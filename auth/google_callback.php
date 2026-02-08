<?php
/**
 * ============================================================================
 * SKSU CENTRALIZED INTERVIEW SYSTEM
 * ----------------------------------------------------------------------------
 * File : auth/google_callback.php
 * ============================================================================
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();

/* ============================================================================
 * CONFIG
 * ========================================================================== */
$GOOGLE_CLIENT_ID = '115027937761-p80e2nudpe4ldsg9kbi73qc5o9nhg07p.apps.googleusercontent.com';

require_once '../config/db.php';

$LOG_FILE = __DIR__ . '/google_auth.log';

/* ============================================================================
 * SIMPLE LOGGER
 * ========================================================================== */
function log_auth($label, $data = null)
{
    global $LOG_FILE;
    $line = '[' . date('Y-m-d H:i:s') . "] {$label}";
    if ($data !== null) {
        $line .= ' : ' . json_encode($data);
    }
    file_put_contents($LOG_FILE, $line . PHP_EOL, FILE_APPEND);
}

/* ============================================================================
 * REQUEST VALIDATION
 * ========================================================================== */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    log_auth('Invalid request method');
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

if (empty($_POST['credential'])) {
    http_response_code(400);
    log_auth('Missing credential');
    echo json_encode(['success' => false, 'message' => 'Missing credential']);
    exit;
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
curl_close($ch);

if (!$response) {
    http_response_code(500);
    log_auth('Google verification failed');
    echo json_encode(['success' => false, 'message' => 'Google verification failed']);
    exit;
}

$token = json_decode($response, true);
log_auth('Google token verified', $token);

/* ============================================================================
 * TOKEN CHECK
 * ========================================================================== */
if (
    empty($token['aud']) ||
    $token['aud'] !== $GOOGLE_CLIENT_ID ||
    empty($token['email']) ||
    ($token['email_verified'] ?? '') !== 'true'
) {
    http_response_code(401);
    log_auth('Invalid Google token');
    echo json_encode(['success' => false, 'message' => 'Invalid Google token']);
    exit;
}

$email = strtolower(trim($token['email']));
log_auth('Email verified', $email);

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
    http_response_code(500);
    log_auth('DB connection error', $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error']);
    exit;
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
    http_response_code(403);
    log_auth('Account not found', $email);
    echo json_encode(['success' => false, 'message' => 'Account not authorized']);
    exit;
}

/* ============================================================================
 * SESSION
 * ========================================================================== */
$_SESSION['logged_in']  = true;
$_SESSION['accountid']  = $account['accountid'];
$_SESSION['fullname']   = $account['acc_fullname'];
$_SESSION['email']      = $account['email'];
$_SESSION['role']       = $account['role'];

log_auth('Session created', $_SESSION);

/* ============================================================================
 * ROLE ROUTING (RETURN JSON)
 * ========================================================================== */
switch ($account['role']) {
    case 'administrator':
        $redirect = '/interview/administrator/index.php';
        break;

    case 'progchair':
        $redirect = '/interview/progchair/index.php';
        break;

    case 'monitoring':
        $redirect = '/interview/monitoring/index.php';
        break;

    default:
        http_response_code(403);
        log_auth('Invalid role', $account['role']);
        echo json_encode([
            'success' => false,
            'message' => 'Invalid role assignment'
        ]);
        exit;
}


log_auth('Redirecting to', $redirect);

echo json_encode([
    'success' => true,
    'redirect' => $redirect
]);
exit;
