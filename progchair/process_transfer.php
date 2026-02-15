<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/process_transfer.php
 * PURPOSE: Insert Transfer Record (Phase 2 - Clean Logging)
 * ============================================================================
 */

require_once '../config/db.php';
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

$interviewId   = isset($_POST['interview_id'])   ? (int) $_POST['interview_id']   : 0;
$fromProgramId = isset($_POST['from_program_id']) ? (int) $_POST['from_program_id'] : 0;
$toProgramId   = isset($_POST['to_program_id'])   ? (int) $_POST['to_program_id']   : 0;
$remarks       = isset($_POST['remarks']) ? trim($_POST['remarks']) : '';

if ($interviewId <= 0 || $toProgramId <= 0) {
    header('Location: index.php?msg=invalid_request');
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
    die("SQL Error (pendingSql): " . $conn->error);
}

$stmtPending->bind_param("i", $interviewId);
$stmtPending->execute();
$pendingExists = $stmtPending->get_result()->fetch_assoc();

if ($pendingExists) {
    header('Location: index.php?msg=pending_transfer_exists');
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
    die("SQL Error (insertSql): " . $conn->error);
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
