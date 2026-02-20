<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/student_credentials.php';
require_once __DIR__ . '/../config/session_security.php';

secure_session_start();
header('Content-Type: application/json; charset=utf-8');

const STUDENT_LOGIN_MAX_ATTEMPTS = 5;
const STUDENT_LOGIN_WINDOW_SECONDS = 900;
const STUDENT_LOGIN_LOCKOUT_SECONDS = 900;

function student_json_response($statusCode, $payload)
{
    http_response_code((int) $statusCode);
    echo json_encode($payload);
    exit;
}

function student_client_ip()
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    return substr($ip, 0, 45);
}

function ensure_student_login_attempts_table($conn)
{
    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_student_login_attempts (
            attempt_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            examinee_number VARCHAR(50) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
            first_attempt_at DATETIME NOT NULL,
            last_attempt_at DATETIME NOT NULL,
            locked_until DATETIME DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_login_attempt_identity (examinee_number, ip_address),
            KEY idx_student_login_locked_until (locked_until)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    return (bool) $conn->query($sql);
}

function get_student_login_attempt($conn, $examineeNumber, $ipAddress)
{
    $sql = "
        SELECT
            attempt_id,
            attempt_count,
            first_attempt_at,
            last_attempt_at,
            locked_until
        FROM tbl_student_login_attempts
        WHERE examinee_number = ?
          AND ip_address = ?
        LIMIT 1
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('ss', $examineeNumber, $ipAddress);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    return $row ?: null;
}

function get_lockout_remaining_seconds($lockUntilRaw)
{
    if (!is_string($lockUntilRaw) || trim($lockUntilRaw) === '') {
        return 0;
    }

    $lockUntil = strtotime($lockUntilRaw);
    if ($lockUntil === false) {
        return 0;
    }

    $remaining = $lockUntil - time();
    return $remaining > 0 ? $remaining : 0;
}

function register_student_login_failure($conn, $examineeNumber, $ipAddress)
{
    $attempt = get_student_login_attempt($conn, $examineeNumber, $ipAddress);
    $now = time();
    $windowStart = $now - STUDENT_LOGIN_WINDOW_SECONDS;
    $lockUntil = null;

    if (!$attempt) {
        $insertSql = "
            INSERT INTO tbl_student_login_attempts (
                examinee_number,
                ip_address,
                attempt_count,
                first_attempt_at,
                last_attempt_at,
                locked_until
            ) VALUES (?, ?, 1, NOW(), NOW(), NULL)
        ";
        $insertStmt = $conn->prepare($insertSql);
        if ($insertStmt) {
            $insertStmt->bind_param('ss', $examineeNumber, $ipAddress);
            $insertStmt->execute();
            $insertStmt->close();
        }
        return null;
    }

    $currentLockRemaining = get_lockout_remaining_seconds((string) ($attempt['locked_until'] ?? ''));
    if ($currentLockRemaining > 0) {
        return (string) ($attempt['locked_until'] ?? '');
    }

    $firstAttemptTs = strtotime((string) ($attempt['first_attempt_at'] ?? '')) ?: 0;
    $attemptCount = (int) ($attempt['attempt_count'] ?? 0);

    if ($firstAttemptTs < $windowStart) {
        $attemptCount = 1;
        $firstAttemptSqlValue = 'NOW()';
    } else {
        $attemptCount++;
        $firstAttemptSqlValue = null;
    }

    if ($attemptCount >= STUDENT_LOGIN_MAX_ATTEMPTS) {
        $lockUntil = date('Y-m-d H:i:s', $now + STUDENT_LOGIN_LOCKOUT_SECONDS);
    }

    if ($firstAttemptSqlValue === 'NOW()') {
        $updateSql = "
            UPDATE tbl_student_login_attempts
            SET attempt_count = ?,
                first_attempt_at = NOW(),
                last_attempt_at = NOW(),
                locked_until = ?
            WHERE attempt_id = ?
            LIMIT 1
        ";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt) {
            $attemptId = (int) ($attempt['attempt_id'] ?? 0);
            $updateStmt->bind_param('isi', $attemptCount, $lockUntil, $attemptId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    } else {
        $updateSql = "
            UPDATE tbl_student_login_attempts
            SET attempt_count = ?,
                last_attempt_at = NOW(),
                locked_until = ?
            WHERE attempt_id = ?
            LIMIT 1
        ";
        $updateStmt = $conn->prepare($updateSql);
        if ($updateStmt) {
            $attemptId = (int) ($attempt['attempt_id'] ?? 0);
            $updateStmt->bind_param('isi', $attemptCount, $lockUntil, $attemptId);
            $updateStmt->execute();
            $updateStmt->close();
        }
    }

    return $lockUntil;
}

function clear_student_login_attempts($conn, $examineeNumber, $ipAddress)
{
    $sql = "
        DELETE FROM tbl_student_login_attempts
        WHERE examinee_number = ?
          AND ip_address = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if ($stmt) {
        $stmt->bind_param('ss', $examineeNumber, $ipAddress);
        $stmt->execute();
        $stmt->close();
    }
}

function dummy_password_hash()
{
    static $hash = null;
    if ($hash !== null) {
        return $hash;
    }

    $hash = password_hash('unused_dummy_password_for_timing', PASSWORD_DEFAULT);
    if (!is_string($hash) || $hash === '') {
        $hash = '$2y$10$HhDk5J6z0rn0w4Ybo4iLO.U2Slx/K5M6RYjLh13M6Ccvo8iIrx8uW';
    }

    return $hash;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    student_json_response(405, [
        'success' => false,
        'message' => 'Invalid request method.'
    ]);
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['student_login_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    student_json_response(403, [
        'success' => false,
        'message' => 'Invalid authentication request.'
    ]);
}

if (!ensure_student_credentials_table($conn) || !ensure_student_login_attempts_table($conn)) {
    student_json_response(500, [
        'success' => false,
        'message' => 'Failed to initialize student login security.'
    ]);
}

$examineeNumber = trim((string) ($_POST['examinee_number'] ?? ''));
$password = (string) ($_POST['password'] ?? '');
$clientIp = student_client_ip();

if ($examineeNumber === '' || $password === '') {
    student_json_response(400, [
        'success' => false,
        'message' => 'Please provide examinee number and password.'
    ]);
}

$attempt = get_student_login_attempt($conn, $examineeNumber, $clientIp);
if ($attempt) {
    $remaining = get_lockout_remaining_seconds((string) ($attempt['locked_until'] ?? ''));
    if ($remaining > 0) {
        $waitMinutes = max(1, (int) ceil($remaining / 60));
        student_json_response(429, [
            'success' => false,
            'message' => "Too many failed login attempts. Try again in {$waitMinutes} minute(s)."
        ]);
    }
}

$sql = "
    SELECT
        sc.credential_id,
        sc.examinee_number,
        sc.password_hash,
        sc.must_change_password,
        sc.status,
        sc.placement_result_id,
        pr.full_name
    FROM tbl_student_credentials sc
    LEFT JOIN tbl_placement_results pr
      ON pr.id = sc.placement_result_id
    WHERE sc.examinee_number = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    student_json_response(500, [
        'success' => false,
        'message' => 'Failed to prepare student login query.'
    ]);
}

$stmt->bind_param('s', $examineeNumber);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

$passwordHash = dummy_password_hash();
if ($student && is_string($student['password_hash'] ?? null) && trim((string) $student['password_hash']) !== '') {
    $passwordHash = (string) $student['password_hash'];
}

$passwordValid = password_verify($password, $passwordHash);
$studentIsActive = ($student && ((string) ($student['status'] ?? '') === 'active'));

if (!$student || !$studentIsActive || !$passwordValid) {
    $lockUntil = register_student_login_failure($conn, $examineeNumber, $clientIp);
    $remaining = get_lockout_remaining_seconds((string) ($lockUntil ?? ''));

    if ($remaining > 0) {
        $waitMinutes = max(1, (int) ceil($remaining / 60));
        student_json_response(429, [
            'success' => false,
            'message' => "Too many failed login attempts. Try again in {$waitMinutes} minute(s)."
        ]);
    }

    student_json_response(401, [
        'success' => false,
        'message' => 'Invalid student credentials.'
    ]);
}

clear_student_login_attempts($conn, $examineeNumber, $clientIp);

session_regenerate_id(true);
unset($_SESSION['student_login_csrf']);
$_SESSION['logged_in'] = true;
$_SESSION['role'] = 'student';
$_SESSION['student_credential_id'] = (int) ($student['credential_id'] ?? 0);
$_SESSION['student_examinee_number'] = (string) ($student['examinee_number'] ?? $examineeNumber);
$_SESSION['student_placement_result_id'] = (int) ($student['placement_result_id'] ?? 0);
$_SESSION['fullname'] = (string) ($student['full_name'] ?? $examineeNumber);
$_SESSION['student_must_change_password'] = ((int) ($student['must_change_password'] ?? 1) === 1);

$redirect = BASE_URL . '/student/index.php';
if (!empty($_SESSION['student_must_change_password'])) {
    $redirect = BASE_URL . '/student/change_password.php';
}

student_json_response(200, [
    'success' => true,
    'redirect' => $redirect
]);
