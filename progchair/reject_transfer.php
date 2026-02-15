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

$sql = "
UPDATE tbl_student_transfer_history
SET status = 'rejected',
    approved_by = ?,
    approved_datetime = NOW()
WHERE transfer_id = ?
AND status = 'pending'
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("ii", $accountId, $transferId);
$stmt->execute();

header('Location: pending_transfers.php?msg=rejected');
exit;
