<?php
/**
 * ============================================================================
 * SKSU CENTRALIZED INTERVIEW SYSTEM
 * ----------------------------------------------------------------------------
 * File : auth/google_callback.php
 * ============================================================================
 */

require_once __DIR__ . '/../config/env.php';

$APP_DEBUG = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

session_start();
header('Content-Type: application/json; charset=utf-8');

/* ============================================================================
 * CONFIG
 * ========================================================================== */
$GOOGLE_CLIENT_ID = getenv('GOOGLE_CLIENT_ID') ?: '';

require_once '../config/db.php';

try {
    $AUTH_TRACE_ID = bin2hex(random_bytes(8));
} catch (Exception $e) {
    $AUTH_TRACE_ID = uniqid('trace_', true);
}

/* ============================================================================
 * SIMPLE LOGGER
 * ========================================================================== */
function log_auth($label, $context = [])
{
    global $AUTH_TRACE_ID;
    $safeContext = is_array($context) ? $context : [];
    $safeContext = ['trace_id' => $AUTH_TRACE_ID] + $safeContext;
    error_log('[google_callback] ' . $label . ' ' . json_encode($safeContext, JSON_UNESCAPED_SLASHES));
}

function json_response($statusCode, $payload)
{
    http_response_code((int) $statusCode);
    echo json_encode($payload);
    exit;
}

if ($GOOGLE_CLIENT_ID === '') {
    log_auth('Missing GOOGLE_CLIENT_ID configuration');
    json_response(500, ['success' => false, 'message' => 'Server configuration error']);
}

/* ============================================================================
 * REQUEST VALIDATION
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    log_auth('Invalid request method', ['method' => $_SERVER['REQUEST_METHOD'] ?? 'unknown']);
    json_response(405, ['success' => false, 'message' => 'Invalid request']);
}

if (empty($_POST['credential'])) {
    log_auth('Missing credential');
    json_response(400, ['success' => false, 'message' => 'Missing credential']);
}

$credential = $_POST['credential'];
log_auth('Credential received');

/* ============================================================================
 * GOOGLE TOKEN VERIFICATION
 * ========================================================================== */
$url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($credential);

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
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

/* ============================================================================
 * TOKEN CHECK
 * ========================================================================== */
$issuer = $token['iss'] ?? '';
$validIssuer = in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true);
$emailVerifiedRaw = $token['email_verified'] ?? '';
$isEmailVerified = (
    $emailVerifiedRaw === true ||
    $emailVerifiedRaw === 'true' ||
    $emailVerifiedRaw === 1 ||
    $emailVerifiedRaw === '1'
);
$expiresAt = isset($token['exp']) ? (int) $token['exp'] : 0;

if (
    empty($token['aud']) ||
    $token['aud'] !== $GOOGLE_CLIENT_ID ||
    empty($token['email']) ||
    !$isEmailVerified ||
    !$validIssuer ||
    $expiresAt < time()
) {
    log_auth('Invalid Google token', ['iss' => $issuer, 'exp' => $expiresAt]);
    json_response(401, ['success' => false, 'message' => 'Invalid Google token']);
}

$email = strtolower(trim($token['email']));
log_auth('Email verified', ['email_sha1' => sha1($email)]);

/* ============================================================================
 * DATABASE
 * ========================================================================== */
try {
    $pdo = new PDO(
        "mysql:host=$DB_HOST;dbname=$DB_NAME;charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (Exception $e) {
    log_auth('DB connection error', ['error' => $e->getMessage()]);
    json_response(500, ['success' => false, 'message' => 'Database error']);
}

/* ============================================================================
 * ACCOUNT CHECK
 * ========================================================================== */
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

/* ============================================================================
 * SESSION
 * ========================================================================== */
session_regenerate_id(true);
$_SESSION['logged_in']  = true;
$_SESSION['accountid']  = $account['accountid'];
$_SESSION['fullname']   = $account['acc_fullname'];
$_SESSION['email']      = $account['email'];
$_SESSION['role']       = $account['role'];
$_SESSION['campus_id']  = $account['campus_id'];
$_SESSION['program_id'] = $account['program_id'];

log_auth('Session created', ['accountid' => (int) $account['accountid'], 'role' => $account['role']]);

/* ============================================================================
 * ROLE ROUTING (RETURN JSON)
 * ========================================================================== */
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
