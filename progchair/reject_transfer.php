<?php
require_once '../config/db.php';
require_once '../config/program_ranking_lock.php';
require_once '../config/student_preregistration.php';
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

$loadSql = "
SELECT interview_id
FROM tbl_student_transfer_history
WHERE transfer_id = ?
AND status = 'pending'
AND to_program_id = ?
LIMIT 1
";
$stmtLoad = $conn->prepare($loadSql);
if (!$stmtLoad) {
    header('Location: pending_transfers.php');
    exit;
}
$stmtLoad->bind_param("ii", $transferId, $programId);
$stmtLoad->execute();
$transfer = $stmtLoad->get_result()->fetch_assoc();
$stmtLoad->close();

if (!$transfer) {
    header('Location: pending_transfers.php');
    exit;
}

if (program_ranking_is_interview_locked($conn, (int) ($transfer['interview_id'] ?? 0))) {
    header('Location: pending_transfers.php?msg=rank_locked');
    exit;
}

if (student_preregistration_has_submitted_interview($conn, (int) ($transfer['interview_id'] ?? 0)) === true) {
    header('Location: pending_transfers.php?msg=prereg_locked');
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
