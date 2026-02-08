<?php
require_once '../../config/db.php';

header('Content-Type: application/json');

if (!isset($_FILES['csv_file'], $_POST['batch_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing file or batch ID']);
    exit;
}

$batchId = $_POST['batch_id'];
$tmpFile = $_FILES['csv_file']['tmp_name'];

if (!is_uploaded_file($tmpFile)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid upload']);
    exit;
}

// Ensure upload directory exists
$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$csvPath = $uploadDir . "/batch_{$batchId}.csv";
move_uploaded_file($tmpFile, $csvPath);

/**
 * Count total rows SAFELY
 */
$totalRows = 0;

if (($h = fopen($csvPath, 'r')) !== false) {

    // Remove BOM if present
    $firstLine = fgets($h);
    if ($firstLine !== false) {
        $firstLine = preg_replace('/^\xEF\xBB\xBF/', '', $firstLine);
    }

    // Read header normally
    $header = str_getcsv($firstLine);

    // Count valid data rows
    while (($row = fgetcsv($h)) !== false) {
        if (count(array_filter($row)) > 0) {
            $totalRows++;
        }
    }

    fclose($h);
}

// Update batch metadata ONLY (no truncate here)
$stmt = $conn->prepare("
    UPDATE tbl_placement_upload_batches
    SET total_rows = ?, status = 'processing'
    WHERE batch_id = ?
");
$stmt->bind_param("is", $totalRows, $batchId);
$stmt->execute();

echo json_encode([
    'success'     => true,
    'total_rows' => $totalRows
]);
