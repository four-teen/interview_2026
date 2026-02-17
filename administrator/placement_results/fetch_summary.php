<?php
/**
 * placement_results/fetch_summary.php
 * Phase 3 – Upload summary
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

$data = $stmt->get_result()->fetch_assoc();

echo json_encode([
    'success' => true,
    'data'    => $data
]);
