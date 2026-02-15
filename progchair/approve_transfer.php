<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'progchair') {
    header('Location: ../index.php');
    exit;
}

$accountId = $_SESSION['accountid'];
$transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 0;

if ($transferId <= 0) {
    header('Location: pending_transfers.php');
    exit;
}

/* LOAD TRANSFER */
$sql = "
SELECT interview_id, to_program_id
FROM tbl_student_transfer_history
WHERE transfer_id = ?
AND status = 'pending'
LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $transferId);
$stmt->execute();
$transfer = $stmt->get_result()->fetch_assoc();

if (!$transfer) {
    header('Location: pending_transfers.php');
    exit;
}

/* UPDATE INTERVIEW */
$updateInterview = "
UPDATE tbl_student_interview
SET first_choice = ?, program_id = ?
WHERE interview_id = ?
";

$stmt = $conn->prepare($updateInterview);
$stmt->bind_param("iii",
    $transfer['to_program_id'],
    $transfer['to_program_id'],
    $transfer['interview_id']
);
$stmt->execute();

/* UPDATE TRANSFER STATUS */
$updateTransfer = "
UPDATE tbl_student_transfer_history
SET status = 'approved',
    approved_by = ?,
    approved_datetime = NOW()
WHERE transfer_id = ?
";

$stmt = $conn->prepare($updateTransfer);
$stmt->bind_param("ii", $accountId, $transferId);
$stmt->execute();

header('Location: pending_transfers.php?msg=approved');
exit;
