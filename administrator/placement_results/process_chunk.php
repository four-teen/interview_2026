<?php
/**
 * placement_results/process_chunk.php
 * Phase 4 – Chunk processing
 */

require_once '../../config/db.php';
require_once '../../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

header('Content-Type: application/json');

$batch_id = $_POST['batch_id'] ?? '';
$offset   = intval($_POST['offset'] ?? 0);
$limit    = 250;

if ($batch_id === '') {
    echo json_encode(['success' => false]);
    exit;
}

$filePath = __DIR__ . '/tmp/upload_' . $batch_id . '.xlsx';
if (!file_exists($filePath)) {
    echo json_encode(['success' => false, 'message' => 'File not found']);
    exit;
}

// qualitative mapping
$map = [
    'OUTSTANDING'     => 1,
    'ABOVE AVERAGE'   => 2,
    'HIGH AVERAGE'    => 3,
    'MIDDLE AVERAGE'  => 4,
    'LOW AVERAGE'     => 5,
    'BELOW AVERAGE'   => 6,
];

// load excel
$sheet = IOFactory::load($filePath)->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

// remove header
array_shift($rows);
$chunk = array_slice($rows, $offset, $limit);

$inserted = $duplicate = $error = 0;

foreach ($chunk as $row) {

    try {
        $examinee = trim($row['A']);
        $name     = strtoupper(trim($row['B']));
        $sat      = intval($row['C']);
        $qualText = strtoupper(trim($row['F']));

        if (!isset($map[$qualText])) {
            $error++;
            continue;
        }

        $qualCode = $map[$qualText];

        $stmt = $conn->prepare("
            INSERT INTO tbl_placement_results
            (examinee_number, full_name, sat_score, qualitative_text, qualitative_code, upload_batch_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("ssisis", $examinee, $name, $sat, $qualText, $qualCode, $batch_id);
        $stmt->execute();

        $inserted++;

    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1062) {
            $duplicate++;
        } else {
            $error++;
        }
    }
}

// update batch counters
$conn->query("
    UPDATE tbl_placement_upload_batches
    SET
        inserted_rows = inserted_rows + $inserted,
        duplicate_rows = duplicate_rows + $duplicate,
        error_rows = error_rows + $error
    WHERE batch_id = '$batch_id'
");

echo json_encode([
    'success' => true,
    'processed' => count($chunk)
]);
