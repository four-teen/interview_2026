<?php
require_once '../../config/db.php';
require_once '../../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
$sessionCsrf = (string) ($_SESSION['admin_program_login_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    header('Location: index.php');
    exit;
}

$programId = (int) ($_POST['program_id'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));
if ($programId <= 0 || !in_array($action, ['lock', 'unlock'], true)) {
    header('Location: index.php');
    exit;
}

$unlocked = ($action === 'unlock');
$accountId = (int) ($_SESSION['accountid'] ?? 0);
set_program_login_unlocked($conn, $programId, $unlocked, ($accountId > 0 ? $accountId : null));

$rawReferer = str_replace(["\r", "\n"], '', $_SERVER['HTTP_REFERER'] ?? '');
$refererPath = parse_url($rawReferer, PHP_URL_PATH) ?: '';
$safeRedirect = (strpos($refererPath, '/administrator/programs/') !== false) ? $rawReferer : 'index.php';

header('Location: ' . $safeRedirect);
exit;
