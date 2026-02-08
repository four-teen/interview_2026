<?php
/**
 * placement_results/fetch_progress.php
 * Phase 3 – Progress polling
 */

require_once '../../config/db.php';

header('Content-Type: application/json');

$batch_id = $_GET['batch_id'] ?? '';

if ($batch_id === '') {
    echo json_encode(['success' => false]);
    exit;
}

$stmt = $conn->prepare("
    SELECT total_rows, inserted_rows, duplicate_rows, error_rows, status
    FROM tbl_placement_upload_batches
    WHERE batch_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $batch_id);
$stmt->execute();

$result = $stmt->get_result();
$data = $result->fetch_assoc();

if (!$data) {
    echo json_encode(['success' => false]);
    exit;
}

$processed =
    $data['inserted_rows'] +
    $data['duplicate_rows'] +
    $data['error_rows'];

$percentage = ($data['total_rows'] > 0)
    ? round(($processed / $data['total_rows']) * 100)
    : 0;

echo json_encode([
    'success'    => true,
    'status'    => $data['status'],
    'percentage'=> $percentage,
    'processed' => $processed,
    'total'     => $data['total_rows']
]);
