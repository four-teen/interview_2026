<?php
/**
 * placement_results/fetch_progress.php
 * Phase 3 – Progress polling
 */

require_once '../../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

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

$totalRows = (int) ($data['total_rows'] ?? 0);
$insertedRows = (int) ($data['inserted_rows'] ?? 0);
$duplicateRows = (int) ($data['duplicate_rows'] ?? 0);
$errorRows = (int) ($data['error_rows'] ?? 0);
$status = (string) ($data['status'] ?? 'processing');

$processed = $insertedRows + $duplicateRows + $errorRows;
if ($totalRows > 0 && $processed > $totalRows) {
    $processed = $totalRows;
}

$percentage = ($totalRows > 0)
    ? (int) round(($processed / $totalRows) * 100)
    : 0;

if ($status === 'completed') {
    $percentage = 100;
    if ($totalRows > 0) {
        $processed = $totalRows;
    }
}

if ($percentage > 100) {
    $percentage = 100;
}

echo json_encode([
    'success' => true,
    'status' => $status,
    'percentage' => $percentage,
    'processed' => $processed,
    'total' => $totalRows,
    'inserted' => $insertedRows,
    'duplicates' => $duplicateRows,
    'errors' => $errorRows
]);
