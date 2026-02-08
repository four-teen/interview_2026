<?php
/**
 * placement_results/start_upload.php
 * Phase 3 – Create upload batch + reset data
 */

require_once '../../config/db.php'; // adjust ONLY if your db path is different
session_start();

header('Content-Type: application/json');

// safety check
if (!isset($_SESSION['user_id'])) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$admin_id = (int) $_SESSION['user_id'];
$batch_id = uniqid('PLT_', true);

$conn->begin_transaction();

try {

    // 1. Remove existing placement results
    $conn->query("DELETE FROM tbl_placement_results");

    // 2. Create new upload batch
    $stmt = $conn->prepare("
        INSERT INTO tbl_placement_upload_batches
        (batch_id, total_rows, inserted_rows, duplicate_rows, error_rows, status, uploaded_by)
        VALUES (?, 0, 0, 0, 0, 'processing', ?)
    ");
    $stmt->bind_param("si", $batch_id, $admin_id);
    $stmt->execute();

    $conn->commit();

    echo json_encode([
        'success'  => true,
        'batch_id' => $batch_id
    ]);

} catch (Exception $e) {

    $conn->rollback();

    echo json_encode([
        'success' => false,
        'message' => 'Failed to start upload'
    ]);
}
