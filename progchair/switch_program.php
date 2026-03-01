<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../config/program_assignments.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    (($_SESSION['role'] ?? '') !== 'progchair') ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$fallbackProgramId = (int) ($_SESSION['program_id'] ?? 0);
$targetProgramId = (int) ($_POST['program_id'] ?? $_GET['program_id'] ?? 0);

$redirect = trim((string) ($_POST['redirect'] ?? $_GET['redirect'] ?? 'index.php'));
if ($redirect === '' || preg_match('/^https?:\/\//i', $redirect) || strpos($redirect, '..') !== false) {
    $redirect = 'index.php';
}

$assignedProgramIds = get_account_assigned_program_ids($conn, $accountId, $fallbackProgramId);
$allowedProgramIds = [];
foreach ($assignedProgramIds as $programId) {
    $programId = (int) $programId;
    if ($programId > 0 && is_program_login_unlocked($conn, $programId)) {
        $allowedProgramIds[] = $programId;
    }
}
$allowedProgramIds = normalize_program_id_list($allowedProgramIds);

if ($targetProgramId > 0 && in_array($targetProgramId, $allowedProgramIds, true)) {
    $_SESSION['program_id'] = $targetProgramId;
}

$_SESSION['assigned_program_ids'] = $allowedProgramIds;

header('Location: ' . $redirect);
exit;
