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
$cutoffMinRaw = trim((string) ($_POST['global_cutoff_min'] ?? ''));
$cutoffMaxRaw = trim((string) ($_POST['global_cutoff_max'] ?? ''));
$cutoffMin = null;
$cutoffMax = null;

if ($enabled) {
    if ($cutoffMinRaw === '' || !preg_match('/^\d+$/', $cutoffMinRaw)) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT minimum must be a whole number when enabled.'
        ];
        header('Location: index.php');
        exit;
    }

    if ($cutoffMaxRaw === '' || !preg_match('/^\d+$/', $cutoffMaxRaw)) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT maximum must be a whole number when enabled.'
        ];
        header('Location: index.php');
        exit;
    }

    $cutoffMin = (int) $cutoffMinRaw;
    $cutoffMax = (int) $cutoffMaxRaw;

    if ($cutoffMin < 0 || $cutoffMin > 9999 || $cutoffMax < 0 || $cutoffMax > 9999) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT range must be between 0 and 9999.'
        ];
        header('Location: index.php');
        exit;
    }

    if ($cutoffMin > $cutoffMax) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT minimum cannot be greater than maximum.'
        ];
        header('Location: index.php');
        exit;
    }
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$ok = set_global_sat_cutoff_state(
    $conn,
    $enabled,
    $cutoffMin,
    $cutoffMax,
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
        ? ('Global SAT cutoff is now active (SAT ' . number_format((int) $cutoffMin) . ' - ' . number_format((int) $cutoffMax) . ').')
        : 'Global SAT cutoff is now disabled. Program cutoffs are being used.'
];

header('Location: index.php');
exit;
