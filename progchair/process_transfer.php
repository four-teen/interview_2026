<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/process_transfer.php
 * PURPOSE: Insert Transfer Record (Phase 2 - Clean Logging)
 * ============================================================================
 */

require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

/* ======================================================
   GUARD
====================================================== */
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$assignedProgramId = (int) ($_SESSION['program_id'] ?? 0);

$interviewId   = isset($_POST['interview_id'])   ? (int) $_POST['interview_id']   : 0;
$fromProgramId = isset($_POST['from_program_id']) ? (int) $_POST['from_program_id'] : 0;
$toProgramId   = isset($_POST['to_program_id'])   ? (int) $_POST['to_program_id']   : 0;
$remarks       = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

if ($interviewId <= 0 || $toProgramId <= 0) {
    header('Location: index.php?msg=invalid_request');
    exit;
}

/* ======================================================
   VERIFY OWNERSHIP (ONLY ENCODER/OWNER CAN TRANSFER)
====================================================== */
$ownerSql = "
SELECT program_chair_id, program_id
FROM tbl_student_interview
WHERE interview_id = ?
LIMIT 1
";

$stmtOwner = $conn->prepare($ownerSql);
if (!$stmtOwner) {
    error_log("SQL Error (ownerSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtOwner->bind_param("i", $interviewId);
$stmtOwner->execute();
$ownerRow = $stmtOwner->get_result()->fetch_assoc();

if (!$ownerRow || (int)$ownerRow['program_chair_id'] !== $accountId) {
    header('Location: index.php?msg=not_owner');
    exit;
}

$fromProgramId = (int)$ownerRow['program_id'];

if ($assignedProgramId > 0 && $toProgramId === $assignedProgramId) {
    header('Location: index.php?msg=same_program');
    exit;
}

/* ======================================================
   PREVENT SAME PROGRAM TRANSFER
====================================================== */
if ($fromProgramId === $toProgramId) {
    header('Location: index.php?msg=same_program');
    exit;
}

/* ======================================================
   PREVENT MULTIPLE PENDING TRANSFERS
====================================================== */
$pendingSql = "
SELECT transfer_id
FROM tbl_student_transfer_history
WHERE interview_id = ?
AND status = 'pending'
LIMIT 1
";

$stmtPending = $conn->prepare($pendingSql);
if (!$stmtPending) {
    error_log("SQL Error (pendingSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtPending->bind_param("i", $interviewId);
$stmtPending->execute();
$pendingExists = $stmtPending->get_result()->fetch_assoc();

if ($pendingExists) {
    header('Location: index.php?msg=pending_transfer_exists');
    exit;
}

/* ======================================================
   VALIDATE SAT ELIGIBILITY AGAINST EFFECTIVE CUTOFF
====================================================== */
$eligibilitySql = "
SELECT
    pr.sat_score,
    pc.cutoff_score
FROM tbl_student_interview si
INNER JOIN tbl_placement_results pr
    ON pr.id = si.placement_result_id
LEFT JOIN tbl_program_cutoff pc
    ON pc.program_id = ?
WHERE si.interview_id = ?
LIMIT 1
";

$stmtEligibility = $conn->prepare($eligibilitySql);
if (!$stmtEligibility) {
    error_log("SQL Error (eligibilitySql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtEligibility->bind_param("ii", $toProgramId, $interviewId);
$stmtEligibility->execute();
$eligibilityRow = $stmtEligibility->get_result()->fetch_assoc();
$stmtEligibility->close();

if (!$eligibilityRow) {
    header('Location: index.php?msg=invalid_request');
    exit;
}

$satScore = (int) ($eligibilityRow['sat_score'] ?? 0);
$programCutoff = $eligibilityRow['cutoff_score'] !== null ? (int) $eligibilityRow['cutoff_score'] : null;
if ($programCutoff === null) {
    header('Location: index.php?msg=invalid_request');
    exit;
}
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
$effectiveCutoff = get_effective_sat_cutoff($programCutoff, $globalSatCutoffEnabled, $globalSatCutoffValue);

if ($effectiveCutoff !== null && $satScore < $effectiveCutoff) {
    header('Location: index.php?msg=below_cutoff');
    exit;
}



/* ======================================================
   INSERT TRANSFER RECORD
====================================================== */
$insertSql = "
INSERT INTO tbl_student_transfer_history
(interview_id, from_program_id, to_program_id, transferred_by, remarks)
VALUES (?, ?, ?, ?, ?)
";

$stmtInsert = $conn->prepare($insertSql);
if (!$stmtInsert) {
    error_log("SQL Error (insertSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtInsert->bind_param(
    "iiiis",
    $interviewId,
    $fromProgramId,
    $toProgramId,
    $accountId,
    $remarks
);

$stmtInsert->execute();

/* ======================================================
   SUCCESS REDIRECT
====================================================== */
header('Location: index.php?msg=transfer_logged');
exit;
