<?php
/**
 * placement_results/process_chunk.php
 * FINAL – Stable CSV chunk processor (MySQLi, PHP 7.1)
 */

require_once '../../config/db.php';
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$batchId = $_POST['batch_id'] ?? '';
$offset  = (int) ($_POST['offset'] ?? 0);
$limit   = 250;

if ($batchId === '') {
    echo json_encode(['success' => false, 'message' => 'Missing batch ID']);
    exit;
}

$csvPath = __DIR__ . "/uploads/batch_{$batchId}.csv";
if (!file_exists($csvPath)) {
    echo json_encode(['success' => false, 'message' => 'CSV not found']);
    exit;
}

/**
 * Qualitative mapping (NORMALIZED)
 */
$qualMap = [
    'OUTSTANDING'     => 1,
    'ABOVE AVERAGE'   => 2,
    'HIGH AVERAGE'    => 3,
    'MIDDLE AVERAGE'  => 4,
    'LOW AVERAGE'     => 5,
    'BELOW AVERAGE'   => 6
];

$inserted = 0;
$duplicates = 0;
$errors = 0;

/**
 * Get total rows
 */
$stmt = $conn->prepare("
    SELECT total_rows
    FROM tbl_placement_upload_batches
    WHERE batch_id = ?
    LIMIT 1
");
$stmt->bind_param("s", $batchId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();
$totalRows = (int) ($row['total_rows'] ?? 0);

$conn->begin_transaction();

if (($h = fopen($csvPath, 'r')) !== false) {

    /**
     * HEADER MAP (critical)
     */
    $header = fgetcsv($h);

// STRIP UTF-8 BOM FROM FIRST HEADER COLUMN (CRITICAL FIX)
if (isset($header[0])) {
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
}

    $header = array_map('trim', $header);
    $header = array_map('strtolower', $header);
    $map = array_flip($header);

    for ($i = 0; $i < $offset; $i++) {
        fgetcsv($h);
    }

    for ($i = 0; $i < $limit; $i++) {

        $row = fgetcsv($h);
        if (!$row) break;

        $examNo = trim($row[$map['examinee_number']] ?? '');
        $name   = strtoupper(trim($row[$map['name_of_examinee']] ?? ''));
        $sat    = (int) ($row[$map['overall_sat']] ?? 0);

        $qualRaw = strtoupper(trim($row[$map['qualitative_interpretation']] ?? ''));
        $qualRaw = preg_replace('/\s+/', ' ', $qualRaw); // normalize spaces

        if ($examNo === '' || !isset($qualMap[$qualRaw])) {
            $errors++;
            continue;
        }

        try {
            $stmt = $conn->prepare("
                INSERT INTO tbl_placement_results
                (examinee_number, full_name, sat_score, qualitative_text, qualitative_code, upload_batch_id)
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->bind_param(
                "ssisis",
                $examNo,
                $name,
                $sat,
                $qualRaw,
                $qualMap[$qualRaw],
                $batchId
            );
            $stmt->execute();
            $inserted++;

        } catch (mysqli_sql_exception $e) {
            if ($e->getCode() === 1062) {
                $duplicates++;
            } else {
                $errors++;
            }
        }
    }

    fclose($h);
}

$conn->commit();

/**
 * Update batch stats
 */
$stmt = $conn->prepare("
    UPDATE tbl_placement_upload_batches
    SET
        inserted_rows  = inserted_rows + ?,
        duplicate_rows = duplicate_rows + ?,
        error_rows     = error_rows + ?
    WHERE batch_id = ?
");
$stmt->bind_param("iiis", $inserted, $duplicates, $errors, $batchId);
$stmt->execute();

/**
 * Mark completed
 */
if ($offset + $limit >= $totalRows) {
    $stmt = $conn->prepare("
        UPDATE tbl_placement_upload_batches
        SET status = 'completed', completed_at = NOW()
        WHERE batch_id = ?
    ");
    $stmt->bind_param("s", $batchId);
    $stmt->execute();
}

echo json_encode(['success' => true]);
