<?php
/**
 * placement_results/process_chunk.php
 * Chunk processor for normalized placement uploads.
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

function mark_batch_failed(mysqli $conn, $batchId)
{
    $stmtFail = $conn->prepare("
        UPDATE tbl_placement_upload_batches
        SET status = 'failed'
        WHERE batch_id = ?
    ");
    if ($stmtFail) {
        $stmtFail->bind_param("s", $batchId);
        $stmtFail->execute();
    }
}

$csvPath = __DIR__ . "/uploads/batch_{$batchId}.csv";
if (!file_exists($csvPath)) {
    mark_batch_failed($conn, $batchId);
    echo json_encode(['success' => false, 'message' => 'CSV not found']);
    exit;
}

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

$stmt = $conn->prepare("\n    SELECT total_rows\n    FROM tbl_placement_upload_batches\n    WHERE batch_id = ?\n    LIMIT 1\n");
$stmt->bind_param("s", $batchId);
$stmt->execute();
$row = $stmt->get_result()->fetch_assoc();

if (!$row) {
    echo json_encode(['success' => false, 'message' => 'Invalid batch ID']);
    exit;
}

$totalRows = (int) ($row['total_rows'] ?? 0);

$conn->begin_transaction();

if (($h = fopen($csvPath, 'r')) !== false) {
    $header = fgetcsv($h);

    if (isset($header[0])) {
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $header[0]);
    }

    $normalizedHeader = [];
    foreach ($header as $index => $column) {
        $key = strtolower(trim((string) $column));
        $key = preg_replace('/[^a-z0-9_]+/', '_', $key);
        $key = trim($key, '_');
        if ($key !== '') {
            $normalizedHeader[$key] = $index;
        }
    }

    $requiredColumns = ['examinee_number', 'full_name', 'sat_score', 'qualitative_text', 'qualitative_code'];
    foreach ($requiredColumns as $requiredColumn) {
        if (!isset($normalizedHeader[$requiredColumn])) {
            fclose($h);
            $conn->rollback();
            mark_batch_failed($conn, $batchId);
            echo json_encode([
                'success' => false,
                'message' => 'Invalid normalized file header: missing ' . $requiredColumn
            ]);
            exit;
        }
    }

    $insertSql = "\n        INSERT INTO tbl_placement_results (\n            examinee_number,\n            full_name,\n            sat_score,\n            qualitative_text,\n            qualitative_code,\n            upload_batch_id,\n            preferred_program,\n            english_standard_score,\n            english_stanine,\n            english_qualitative_text,\n            science_standard_score,\n            science_stanine,\n            science_qualitative_text,\n            mathematics_standard_score,\n            mathematics_stanine,\n            mathematics_qualitative_text,\n            filipino_standard_score,\n            filipino_stanine,\n            filipino_qualitative_text,\n            social_studies_standard_score,\n            social_studies_stanine,\n            social_studies_qualitative_text,\n            esm_competency_standard_score,\n            esm_competency_stanine,\n            esm_competency_qualitative_text,\n            overall_standard_score,\n            overall_stanine,\n            overall_qualitative_text\n        ) VALUES (\n            ?, ?, ?, ?, ?, ?, ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?,\n            NULLIF(?, ''), NULLIF(?, ''), ?\n        )\n    ";

    $stmtInsert = $conn->prepare($insertSql);
    if (!$stmtInsert) {
        fclose($h);
        $conn->rollback();
        mark_batch_failed($conn, $batchId);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to prepare insert statement'
        ]);
        exit;
    }

    $getCell = function ($rowData, $headerMap, $columnName) {
        if (!isset($headerMap[$columnName])) {
            return '';
        }
        $idx = $headerMap[$columnName];
        return isset($rowData[$idx]) ? trim((string) $rowData[$idx]) : '';
    };

    for ($i = 0; $i < $offset; $i++) {
        fgetcsv($h);
    }

    for ($i = 0; $i < $limit; $i++) {
        $row = fgetcsv($h);
        if (!$row) {
            break;
        }

        $examNo = $getCell($row, $normalizedHeader, 'examinee_number');
        if (preg_match('/^\d+\.0+$/', $examNo)) {
            $examNo = preg_replace('/\.0+$/', '', $examNo);
        }
        $examNo = preg_replace('/\s+/', '', $examNo);

        $name = strtoupper($getCell($row, $normalizedHeader, 'full_name'));

        $satRaw = str_replace(',', '', $getCell($row, $normalizedHeader, 'sat_score'));
        $sat = ($satRaw !== '' && is_numeric($satRaw)) ? (int) round((float) $satRaw) : 0;

        $qualRaw = strtoupper($getCell($row, $normalizedHeader, 'qualitative_text'));
        $qualRaw = preg_replace('/\s+/', ' ', $qualRaw);

        $qualCodeRaw = $getCell($row, $normalizedHeader, 'qualitative_code');
        $qualCode = ($qualCodeRaw !== '' && is_numeric($qualCodeRaw)) ? (int) $qualCodeRaw : 0;
        if ($qualCode <= 0 && isset($qualMap[$qualRaw])) {
            $qualCode = $qualMap[$qualRaw];
        }

        if ($examNo === '' || $name === '') {
            $errors++;
            continue;
        }

        $preferredProgram = $getCell($row, $normalizedHeader, 'preferred_program');

        $englishStandardScore = $getCell($row, $normalizedHeader, 'english_standard_score');
        $englishStanine = $getCell($row, $normalizedHeader, 'english_stanine');
        $englishQualitative = strtoupper($getCell($row, $normalizedHeader, 'english_qualitative_text'));

        $scienceStandardScore = $getCell($row, $normalizedHeader, 'science_standard_score');
        $scienceStanine = $getCell($row, $normalizedHeader, 'science_stanine');
        $scienceQualitative = strtoupper($getCell($row, $normalizedHeader, 'science_qualitative_text'));

        $mathematicsStandardScore = $getCell($row, $normalizedHeader, 'mathematics_standard_score');
        $mathematicsStanine = $getCell($row, $normalizedHeader, 'mathematics_stanine');
        $mathematicsQualitative = strtoupper($getCell($row, $normalizedHeader, 'mathematics_qualitative_text'));

        $filipinoStandardScore = $getCell($row, $normalizedHeader, 'filipino_standard_score');
        $filipinoStanine = $getCell($row, $normalizedHeader, 'filipino_stanine');
        $filipinoQualitative = strtoupper($getCell($row, $normalizedHeader, 'filipino_qualitative_text'));

        $socialStudiesStandardScore = $getCell($row, $normalizedHeader, 'social_studies_standard_score');
        $socialStudiesStanine = $getCell($row, $normalizedHeader, 'social_studies_stanine');
        $socialStudiesQualitative = strtoupper($getCell($row, $normalizedHeader, 'social_studies_qualitative_text'));

        $esmCompetencyStandardScore = $getCell($row, $normalizedHeader, 'esm_competency_standard_score');
        $esmCompetencyStanine = $getCell($row, $normalizedHeader, 'esm_competency_stanine');
        $esmCompetencyQualitative = strtoupper($getCell($row, $normalizedHeader, 'esm_competency_qualitative_text'));

        $overallStandardScore = $getCell($row, $normalizedHeader, 'overall_standard_score');
        $overallStanine = $getCell($row, $normalizedHeader, 'overall_stanine');
        $overallQualitative = strtoupper($getCell($row, $normalizedHeader, 'overall_qualitative_text'));

        $stmtInsert->bind_param(
            "ssisisssssssssssssssssssssss",
            $examNo,
            $name,
            $sat,
            $qualRaw,
            $qualCode,
            $batchId,
            $preferredProgram,
            $englishStandardScore,
            $englishStanine,
            $englishQualitative,
            $scienceStandardScore,
            $scienceStanine,
            $scienceQualitative,
            $mathematicsStandardScore,
            $mathematicsStanine,
            $mathematicsQualitative,
            $filipinoStandardScore,
            $filipinoStanine,
            $filipinoQualitative,
            $socialStudiesStandardScore,
            $socialStudiesStanine,
            $socialStudiesQualitative,
            $esmCompetencyStandardScore,
            $esmCompetencyStanine,
            $esmCompetencyQualitative,
            $overallStandardScore,
            $overallStanine,
            $overallQualitative
        );

        if ($stmtInsert->execute()) {
            $inserted++;
        } elseif ((int) $stmtInsert->errno === 1062) {
            $duplicates++;
            $stmtInsert->reset();
        } else {
            $errors++;
            $stmtInsert->reset();
        }
    }

    $stmtInsert->close();
    fclose($h);
}

$conn->commit();

$stmt = $conn->prepare("\n    UPDATE tbl_placement_upload_batches\n    SET\n        inserted_rows  = inserted_rows + ?,\n        duplicate_rows = duplicate_rows + ?,\n        error_rows     = error_rows + ?\n    WHERE batch_id = ?\n");
$stmt->bind_param("iiis", $inserted, $duplicates, $errors, $batchId);
$stmt->execute();

$progressSql = "
    SELECT total_rows, inserted_rows, duplicate_rows, error_rows, status
    FROM tbl_placement_upload_batches
    WHERE batch_id = ?
    LIMIT 1
";
$stmtProgress = $conn->prepare($progressSql);
$stmtProgress->bind_param("s", $batchId);
$stmtProgress->execute();
$progressRow = $stmtProgress->get_result()->fetch_assoc();

$totalRowsDb = (int) ($progressRow['total_rows'] ?? 0);
$processedTotal = (int) ($progressRow['inserted_rows'] ?? 0)
    + (int) ($progressRow['duplicate_rows'] ?? 0)
    + (int) ($progressRow['error_rows'] ?? 0);
$isDone = ($totalRowsDb > 0) ? ($processedTotal >= $totalRowsDb) : true;
$status = (string) ($progressRow['status'] ?? 'processing');

if ($isDone && $status !== 'completed') {
    $stmt = $conn->prepare("
        UPDATE tbl_placement_upload_batches
        SET status = 'completed', completed_at = NOW()
        WHERE batch_id = ?
    ");
    $stmt->bind_param("s", $batchId);
    $stmt->execute();
    $status = 'completed';
}

$nextOffset = $offset + $limit;
if ($totalRowsDb > 0 && $nextOffset > $totalRowsDb) {
    $nextOffset = $totalRowsDb;
}

echo json_encode([
    'success' => true,
    'processed_chunk' => $inserted + $duplicates + $errors,
    'processed_total' => $processedTotal,
    'total_rows' => $totalRowsDb,
    'next_offset' => $nextOffset,
    'done' => $isDone,
    'status' => $status
]);
