<?php
/**
 * Google login callback endpoint.
 */

require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../config/session_security.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/system_controls.php';

$APP_DEBUG = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

secure_session_start();
header('Content-Type: application/json; charset=utf-8');

$GOOGLE_CLIENT_ID = (string) (getenv('GOOGLE_CLIENT_ID') ?: '');
$GOOGLE_ALLOWED_HD = strtolower(trim((string) (getenv('GOOGLE_ALLOWED_HD') ?: 'sksu.edu.ph')));

try {
    $AUTH_TRACE_ID = bin2hex(random_bytes(8));
} catch (Exception $e) {
    $AUTH_TRACE_ID = uniqid('trace_', true);
}

function log_auth($label, $context = [])
{
    global $AUTH_TRACE_ID;

    $safeContext = is_array($context) ? $context : [];
    foreach ($safeContext as $key => $value) {
        if (is_string($value) && strlen($value) > 180) {
            $safeContext[$key] = substr($value, 0, 180) . '...';
        }
    }

    $safeContext = ['trace_id' => $AUTH_TRACE_ID] + $safeContext;
    error_log('[google_callback] ' . $label . ' ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES));
}

function json_response($statusCode, $payload)
{
    http_response_code((int) $statusCode);
    echo json_encode($payload);
    exit;
}

function ensure_google_subject_bindings_table(PDO $pdo)
{
    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_google_subject_bindings (
            accountid INT NOT NULL PRIMARY KEY,
            google_sub VARCHAR(128) NOT NULL UNIQUE,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    try {
        $pdo->exec($sql);
        return true;
    } catch (Exception $e) {
        log_auth('Failed ensuring Google subject binding table', ['error' => $e->getMessage()]);
        return false;
    }
}

function bind_google_subject(PDO $pdo, $accountId, $googleSub, &$errorMessage)
{
    $errorMessage = '';
    $accountId = (int) $accountId;
    $googleSub = trim((string) $googleSub);

    if ($accountId <= 0 || $googleSub === '') {
        $errorMessage = 'Invalid Google subject binding input.';
        return false;
    }

    try {
        $pdo->beginTransaction();

        $ownBindingStmt = $pdo->prepare(
            'SELECT google_sub FROM tbl_google_subject_bindings WHERE accountid = :accountid LIMIT 1 FOR UPDATE'
        );
        $ownBindingStmt->execute(['accountid' => $accountId]);
        $ownBinding = $ownBindingStmt->fetch(PDO::FETCH_ASSOC);

        if ($ownBinding) {
            $storedSub = (string) ($ownBinding['google_sub'] ?? '');
            if (!hash_equals($storedSub, $googleSub)) {
                $pdo->rollBack();
                $errorMessage = 'Google account does not match the bound identity for this user.';
                return false;
            }

            $pdo->commit();
            return true;
        }

        $subBindingStmt = $pdo->prepare(
            'SELECT accountid FROM tbl_google_subject_bindings WHERE google_sub = :google_sub LIMIT 1 FOR UPDATE'
        );
        $subBindingStmt->execute(['google_sub' => $googleSub]);
        $subBinding = $subBindingStmt->fetch(PDO::FETCH_ASSOC);

        if ($subBinding) {
            $boundAccountId = (int) ($subBinding['accountid'] ?? 0);
            if ($boundAccountId !== $accountId) {
                $pdo->rollBack();
                $errorMessage = 'Google account is already bound to a different user.';
                return false;
            }
        } else {
            $insertStmt = $pdo->prepare(
                'INSERT INTO tbl_google_subject_bindings (accountid, google_sub) VALUES (:accountid, :google_sub)'
            );
            $insertStmt->execute([
                'accountid' => $accountId,
                'google_sub' => $googleSub
            ]);
        }

        $pdo->commit();
        return true;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $errorMessage = 'Failed to bind Google identity.';
        log_auth('Google subject binding error', ['error' => $e->getMessage()]);
        return false;
    }
}

if ($GOOGLE_CLIENT_ID === '') {
    log_auth('Missing GOOGLE_CLIENT_ID configuration');
    json_response(500, ['success' => false, 'message' => 'Server configuration error']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_auth('Invalid request method', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    json_response(405, ['success' => false, 'message' => 'Invalid request']);
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['google_login_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    log_auth('CSRF validation failed');
    json_response(403, ['success' => false, 'message' => 'Invalid authentication request']);
}

if (empty($_POST['credential'])) {
    log_auth('Missing credential');
    json_response(400, ['success' => false, 'message' => 'Missing credential']);
}

$credential = (string) $_POST['credential'];
log_auth('Credential received');

$url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);
$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false || $httpCode !== 200) {
    log_auth('Google verification failed', ['http_code' => $httpCode, 'curl_error' => $curlError]);
    json_response(500, ['success' => false, 'message' => 'Google verification failed']);
}

$token = json_decode($response, true);
if (!is_array($token) || isset($token['error'])) {
    log_auth('Invalid token response from Google');
    json_response(401, ['success' => false, 'message' => 'Invalid Google token']);
}

$issuer = (string) ($token['iss'] ?? '');
$validIssuer = in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true);
$emailVerifiedRaw = $token['email_verified'] ?? '';
$isEmailVerified = (
    $emailVerifiedRaw === true ||
    $emailVerifiedRaw === 'true' ||
    $emailVerifiedRaw === 1 ||
    $emailVerifiedRaw === '1'
);
$expiresAt = isset($token['exp']) ? (int) $token['exp'] : 0;
$googleSub = trim((string) ($token['sub'] ?? ''));
$tokenNonce = trim((string) ($token['nonce'] ?? ''));
$sessionNonce = trim((string) ($_SESSION['google_login_nonce'] ?? ''));
$email = strtolower(trim((string) ($token['email'] ?? '')));
$emailDomain = '';
$atPos = strrpos($email, '@');
if ($atPos !== false) {
    $emailDomain = strtolower(substr($email, $atPos + 1));
}

$tokenHostedDomain = strtolower(trim((string) ($token['hd'] ?? '')));
$validHostedDomain = (
    $GOOGLE_ALLOWED_HD === '' ||
    ($tokenHostedDomain === $GOOGLE_ALLOWED_HD && $emailDomain === $GOOGLE_ALLOWED_HD)
);

if (
    empty($token['aud']) ||
    (string) $token['aud'] !== $GOOGLE_CLIENT_ID ||
    $email === '' ||
    !$isEmailVerified ||
    !$validIssuer ||
    $expiresAt < time() ||
    $googleSub === '' ||
    $tokenNonce === '' ||
    $sessionNonce === '' ||
    !hash_equals($sessionNonce, $tokenNonce) ||
    !$validHostedDomain
) {
    log_auth('Invalid Google token', [
        'iss' => $issuer,
        'exp' => $expiresAt,
        'email_domain' => $emailDomain,
        'token_hd' => $tokenHostedDomain
    ]);
    json_response(401, ['success' => false, 'message' => 'Invalid Google token']);
}

try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (Exception $e) {
    log_auth('DB connection error', ['error' => $e->getMessage()]);
    json_response(500, ['success' => false, 'message' => 'Database error']);
}

$stmt = $pdo->prepare("
    SELECT accountid, acc_fullname, email, role, campus_id, program_id
    FROM tblaccount
    WHERE email = :email AND status = 'active'
    LIMIT 1
");
$stmt->execute(['email' => $email]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    log_auth('Account not found', ['email_sha1' => sha1($email)]);
    json_response(403, ['success' => false, 'message' => 'Account not authorized']);
}

$accountRole = (string) ($account['role'] ?? '');
if ($accountRole === 'progchair' && is_non_admin_login_locked($conn)) {
    log_auth('Program Chair login blocked by admin lock', ['accountid' => (int) ($account['accountid'] ?? 0)]);
    json_response(423, [
        'success' => false,
        'message' => 'Program Chair login is temporarily locked by the administrator.'
    ]);
}

if (!ensure_google_subject_bindings_table($pdo)) {
    json_response(500, ['success' => false, 'message' => 'Server configuration error']);
}

$bindingError = '';
if (!bind_google_subject($pdo, (int) $account['accountid'], $googleSub, $bindingError)) {
    log_auth('Google subject binding failed', [
        'accountid' => (int) $account['accountid'],
        'reason' => $bindingError
    ]);
    json_response(403, ['success' => false, 'message' => 'Account not authorized']);
}

unset($_SESSION['google_login_nonce'], $_SESSION['google_login_csrf']);
session_regenerate_id(true);
$_SESSION['logged_in'] = true;
$_SESSION['accountid'] = $account['accountid'];
$_SESSION['fullname'] = $account['acc_fullname'];
$_SESSION['email'] = $account['email'];
$_SESSION['role'] = $account['role'];
$_SESSION['campus_id'] = $account['campus_id'];
$_SESSION['program_id'] = $account['program_id'];

log_auth('Session created', ['accountid' => (int) $account['accountid'], 'role' => $account['role']]);

switch ($account['role']) {
    case 'administrator':
        $redirect = BASE_URL . '/administrator/index.php';
        break;

    case 'progchair':
        $redirect = BASE_URL . '/progchair/index.php';
        break;

    case 'monitoring':
        $redirect = BASE_URL . '/monitoring/index.php';
        break;

    default:
        log_auth('Invalid role', ['role' => $account['role']]);
        json_response(403, [
            'success' => false,
            'message' => 'Invalid role assignment'
        ]);
}

log_auth('Redirecting to role landing page', ['role' => $account['role']]);
json_response(200, [
    'success' => true,
    'redirect' => $redirect
]);
