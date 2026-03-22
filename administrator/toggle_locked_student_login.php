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
$sessionCsrf = (string) ($_SESSION['admin_locked_student_login_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    $_SESSION['admin_locked_student_login_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid request token for locked-student login action.'
    ];
    header('Location: index.php');
    exit;
}

$action = trim((string) ($_POST['action'] ?? ''));
if (!in_array($action, ['enable', 'disable'], true)) {
    $_SESSION['admin_locked_student_login_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid locked-student login action.'
    ];
    header('Location: index.php');
    exit;
}

$targetEnabled = ($action === 'enable');
$accountId = (int) ($_SESSION['accountid'] ?? 0);
$ok = set_locked_student_login_enabled($conn, $targetEnabled, ($accountId > 0 ? $accountId : null));

if (!$ok) {
    $_SESSION['admin_locked_student_login_flash'] = [
        'type' => 'danger',
        'message' => 'Failed to update locked-student login state.'
    ];
    header('Location: index.php');
    exit;
}

$_SESSION['admin_locked_student_login_flash'] = [
    'type' => 'success',
    'message' => $targetEnabled
        ? 'Login for inside-capacity locked students is now enabled.'
        : 'Login for inside-capacity locked students is now disabled.'
];

header('Location: index.php');
exit;
