<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['admin_login_lock_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    $_SESSION['admin_login_lock_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid request token for login lock action.'
    ];
    header('Location: index.php');
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
$targetLocked = ($action === 'lock');
if (!in_array($action, ['lock', 'unlock'], true)) {
    $_SESSION['admin_login_lock_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid login lock action.'
    ];
    header('Location: index.php');
    exit;
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$ok = set_non_admin_login_lock($conn, $targetLocked, ($accountId > 0 ? $accountId : null));

if (!$ok) {
    $_SESSION['admin_login_lock_flash'] = [
        'type' => 'danger',
        'message' => 'Failed to update login lock state.'
    ];
    header('Location: index.php');
    exit;
}

$_SESSION['admin_login_lock_flash'] = [
    'type' => 'success',
    'message' => $targetLocked
        ? 'Non-admin logins are now locked. Students and Program Chairs cannot log in.'
        : 'Non-admin logins are now unlocked.'
];

header('Location: index.php');
exit;
