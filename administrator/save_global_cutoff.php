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
$sessionCsrf = (string) ($_SESSION['admin_global_cutoff_csrf'] ?? '');
if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
    $_SESSION['admin_global_cutoff_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid request token for global cutoff update.'
    ];
    header('Location: index.php');
    exit;
}

$enabled = ((string) ($_POST['global_cutoff_enabled'] ?? '0')) === '1';
$cutoffRaw = trim((string) ($_POST['global_cutoff_score'] ?? ''));
$cutoffValue = null;

if ($enabled) {
    if ($cutoffRaw === '' || !preg_match('/^\d+$/', $cutoffRaw)) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT cutoff must be a whole number when enabled.'
        ];
        header('Location: index.php');
        exit;
    }

    $cutoffValue = (int) $cutoffRaw;
    if ($cutoffValue < 0 || $cutoffValue > 9999) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT cutoff must be between 0 and 9999.'
        ];
        header('Location: index.php');
        exit;
    }
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$ok = set_global_sat_cutoff_state(
    $conn,
    $enabled,
    $cutoffValue,
    ($accountId > 0 ? $accountId : null)
);

if (!$ok) {
    $_SESSION['admin_global_cutoff_flash'] = [
        'type' => 'danger',
        'message' => 'Failed to update global SAT cutoff setting.'
    ];
    header('Location: index.php');
    exit;
}

$_SESSION['admin_global_cutoff_flash'] = [
    'type' => 'success',
    'message' => $enabled
        ? ('Global SAT cutoff is now active (SAT >= ' . number_format((int) $cutoffValue) . ').')
        : 'Global SAT cutoff is now disabled. Program cutoffs are being used.'
];

header('Location: index.php');
exit;
