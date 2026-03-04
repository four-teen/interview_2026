<?php

require_once '../../config/db.php';
require_once '../../config/account_secure_links.php';

session_start();
header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized request.'
    ]);
    exit;
}

$postedCsrf = trim((string) ($_POST['csrf_token'] ?? ''));
$sessionCsrf = trim((string) ($_SESSION['admin_secure_link_csrf'] ?? ''));
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid secure link request token.'
    ]);
    exit;
}

$accountId = (int) ($_POST['accountid'] ?? 0);
$createdByAccountId = (int) ($_SESSION['accountid'] ?? 0);

$result = generate_president_secure_login_link($conn, $accountId, $createdByAccountId > 0 ? $createdByAccountId : null);
$statusCode = !empty($result['success']) ? 200 : 422;

http_response_code($statusCode);
echo json_encode($result);
exit;
