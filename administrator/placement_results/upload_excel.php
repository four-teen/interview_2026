<?php
/**
 * placement_results/upload_excel.php
 * Phase 4 – Excel intake & row counting
 */

require_once '../../config/db.php';
require_once '../../vendor/autoload.php';
session_start();

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$batch_id = $_POST['batch_id'] ?? '';

if ($batch_id === '' || !isset($_FILES['excel_file'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

// save uploaded file
$tmpName = $_FILES['excel_file']['tmp_name'];
$filename = 'upload_' . $batch_id . '.xlsx';
$uploadPath = __DIR__ . '/tmp/' . $filename;

// ensure tmp folder exists
if (!is_dir(__DIR__ . '/tmp')) {
    mkdir(__DIR__ . '/tmp', 0777, true);
}

move_uploaded_file($tmpName, $uploadPath);

// load spreadsheet
$spreadsheet = IOFactory::load($uploadPath);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

// remove header row
$header = array_shift($rows);
$totalRows = count($rows);

// update total_rows in batch table
$stmt = $conn->prepare("
    UPDATE tbl_placement_upload_batches
    SET total_rows = ?
    WHERE batch_id = ?
");
$stmt->bind_param("is", $totalRows, $batch_id);
$stmt->execute();

echo json_encode([
    'success' => true,
    'total_rows' => $totalRows
]);
