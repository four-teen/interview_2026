<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'progchair') {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$programId = (int) ($_SESSION['program_id'] ?? 0);
$transferId = isset($_GET['transfer_id']) ? (int)$_GET['transfer_id'] : 0;

if ($transferId <= 0 || $programId <= 0) {
    header('Location: pending_transfers.php');
    exit;
}

$sql = "
UPDATE tbl_student_transfer_history
SET status = 'rejected',
    approved_by = ?,
    approved_datetime = NOW()
WHERE transfer_id = ?
AND status = 'pending'
AND to_program_id = ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("iii", $accountId, $transferId, $programId);
$stmt->execute();

header('Location: pending_transfers.php?msg=rejected');
exit;
