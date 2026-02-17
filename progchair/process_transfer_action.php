<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/process_transfer_action.php
 * PURPOSE: Handle Transfer Approval / Rejection (AJAX JSON)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');
ob_start();

ini_set('display_errors', 0);
error_reporting(0);

/* ============================================================
   GUARD
============================================================ */
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['program_id'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$programId = (int) $_SESSION['program_id'];

$transferId = isset($_POST['transfer_id']) ? (int) $_POST['transfer_id'] : 0;
$action     = isset($_POST['action']) ? $_POST['action'] : '';

if ($transferId <= 0 || !in_array($action, ['accept', 'reject'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit;
}

/* ============================================================
   LOAD TRANSFER RECORD
============================================================ */
$sql = "
    SELECT interview_id, from_program_id, to_program_id, status
    FROM tbl_student_transfer_history
    WHERE transfer_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    error_log('Prepare failed in process_transfer_action (load transfer): ' . $conn->error);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
    exit;
}


$stmt->bind_param("i", $transferId);

if (!$stmt->execute()) {
    error_log('Execute failed in process_transfer_action (load transfer): ' . $stmt->error);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
    exit;
}

/* 🔥 VERY IMPORTANT – FETCH RESULT BEFORE ANY OTHER QUERY */
$result = $stmt->get_result();
$transfer = $result->fetch_assoc();

$stmt->close(); // 🔥 CLOSE SELECT STATEMENT

if (!$transfer || $transfer['status'] !== 'pending') {
    echo json_encode([
        'success' => false,
        'message' => 'Transfer not found or already processed'
    ]);
    exit;
}

$interviewId = (int) $transfer['interview_id'];
$toProgramId = (int) $transfer['to_program_id'];

if ($toProgramId !== $programId) {
    echo json_encode([
        'success' => false,
        'message' => 'You are not allowed to process this transfer'
    ]);
    exit;
}


/* ============================================================
   START TRANSACTION
============================================================ */
$conn->begin_transaction();

try {

    // =========================================================
    // ACCEPT TRANSFER
    // =========================================================
    if ($action === 'accept') {

        // 1️⃣ Update interview record
        $updateInterviewSql = "
            UPDATE tbl_student_interview
            SET first_choice = ?,
                program_id   = ?,   -- VERY IMPORTANT (ownership change)
                program_chair_id = ?
            WHERE interview_id = ?
        ";

        $stmtUpdate = $conn->prepare($updateInterviewSql);

        if (!$stmtUpdate) {
            throw new Exception($conn->error);
        }

        $stmtUpdate->bind_param(
            "iiii",
            $toProgramId,
            $toProgramId,
            $accountId,
            $interviewId
        );

        if (!$stmtUpdate->execute()) {
            throw new Exception($stmtUpdate->error);
        }


        // 2️⃣ Update transfer history
        $updateHistorySql = "
            UPDATE tbl_student_transfer_history
            SET status = 'approved',
                approved_by = ?,
                approved_datetime = NOW()
            WHERE transfer_id = ?
        ";

        $stmtHistory = $conn->prepare($updateHistorySql);

        if (!$stmtHistory) {
            throw new Exception($conn->error);
        }

        $stmtHistory->bind_param("ii", $accountId, $transferId);

        if (!$stmtHistory->execute()) {
            throw new Exception($stmtHistory->error);
        }
    }


    // =========================================================
    // REJECT TRANSFER
    // =========================================================
    if ($action === 'reject') {

        $updateHistorySql = "
            UPDATE tbl_student_transfer_history
            SET status = 'rejected',
                approved_by = ?,
                approved_datetime = NOW()
            WHERE transfer_id = ?
        ";

        $stmtHistory = $conn->prepare($updateHistorySql);

        if (!$stmtHistory) {
            throw new Exception($conn->error);
        }

        $stmtHistory->bind_param("ii", $accountId, $transferId);

        if (!$stmtHistory->execute()) {
            throw new Exception($stmtHistory->error);
        }
    }

    $conn->commit();

ob_clean();
echo json_encode([
    'success' => true
]);
exit;

} catch (Exception $e) {

    $conn->rollback();
    error_log('Transfer action failed: ' . $e->getMessage());

ob_clean();
echo json_encode([
    'success' => false,
    'message' => 'Failed to process transfer action'
]);
exit;

}

