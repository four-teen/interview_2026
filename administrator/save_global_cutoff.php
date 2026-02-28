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
$cutoffFromRaw = trim((string) ($_POST['global_cutoff_from'] ?? ''));
$cutoffToRaw = trim((string) ($_POST['global_cutoff_to'] ?? ''));
$cutoffRanges = [];

if ($enabled) {
    if (!preg_match('/^\d+$/', $cutoffFromRaw) || !preg_match('/^\d+$/', $cutoffToRaw)) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT range requires whole numbers for both From and To values.'
        ];
        header('Location: index.php');
        exit;
    }

    $cutoffFrom = (int) $cutoffFromRaw;
    $cutoffTo = (int) $cutoffToRaw;
    if ($cutoffFrom < 0 || $cutoffFrom > 9999 || $cutoffTo < 0 || $cutoffTo > 9999) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT range values must be between 0 and 9999.'
        ];
        header('Location: index.php');
        exit;
    }

    if ($cutoffFrom > $cutoffTo) {
        $_SESSION['admin_global_cutoff_flash'] = [
            'type' => 'danger',
            'message' => 'Global SAT range is invalid: Range From must be less than or equal to Range To.'
        ];
        header('Location: index.php');
        exit;
    }

    $cutoffRanges = [
        [
            'min' => $cutoffFrom,
            'max' => $cutoffTo
        ]
    ];
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$ok = set_global_sat_cutoff_state(
    $conn,
    $enabled,
    null,
    ($accountId > 0 ? $accountId : null),
    $cutoffRanges
);

if (!$ok) {
    $_SESSION['admin_global_cutoff_flash'] = [
        'type' => 'danger',
        'message' => 'Failed to update global SAT cutoff setting.'
    ];
    header('Location: index.php');
    exit;
}

$rangeLabel = format_sat_cutoff_ranges_for_display($cutoffRanges, ', ');
$_SESSION['admin_global_cutoff_flash'] = [
    'type' => 'success',
    'message' => $enabled
        ? ('Global SAT range cutoff is now active (SAT range: ' . $rangeLabel . ').')
        : 'Global SAT cutoff is now disabled. Program cutoffs are being used.'
];

header('Location: index.php');
exit;
