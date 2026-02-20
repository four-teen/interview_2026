<?php
require_once '../../config/db.php';
session_start();

ini_set('display_errors', '0');
ini_set('html_errors', '0');
error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

header('Content-Type: application/json');

function json_exit($statusCode, $payload)
{
    if (!headers_sent()) {
        http_response_code((int) $statusCode);
        header('Content-Type: application/json');
    }

    $bufferLevel = ob_get_level();
    while ($bufferLevel-- > 0) {
        @ob_end_clean();
    }

    echo json_encode($payload);
    exit;
}

set_exception_handler(function ($e) {
    json_exit(500, [
        'success' => false,
        'message' => 'Unhandled upload exception: ' . $e->getMessage()
    ]);
});

set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(function () {
    $fatal = error_get_last();
    if ($fatal === null) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) $fatal['type'], $fatalTypes, true)) {
        return;
    }

    json_exit(500, [
        'success' => false,
        'message' => 'Fatal upload error: ' . $fatal['message']
    ]);
});

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function normalize_header($value)
{
    $header = strtolower(trim((string) $value));
    $header = preg_replace('/^\xEF\xBB\xBF/', '', $header);
    $header = preg_replace('/[^a-z0-9]+/', '_', $header);
    return trim($header, '_');
}

function normalize_text($value)
{
    $text = trim((string) $value);
    if ($text === '' || strtoupper($text) === '#N/A') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text);
    return strtoupper($text);
}

function normalize_name($value)
{
    return normalize_text($value);
}

function normalize_number($value)
{
    $raw = trim((string) $value);
    if ($raw === '' || strtoupper($raw) === '#N/A') {
        return '';
    }

    $raw = str_replace(',', '', $raw);
    if (!is_numeric($raw)) {
        return '';
    }

    return (string) (int) round((float) $raw);
}

function normalize_examinee_number($value)
{
    $examNo = trim((string) $value);
    if ($examNo === '' || strtolower($examNo) === 'examinee number') {
        return '';
    }

    $examNo = preg_replace('/\s+/', '', $examNo);
    if (preg_match('/^\d+\.0+$/', $examNo)) {
        $examNo = preg_replace('/\.0+$/', '', $examNo);
    }

    return $examNo;
}

function qualitative_to_code($qualitativeText)
{
    $map = [
        'OUTSTANDING' => 1,
        'ABOVE AVERAGE' => 2,
        'HIGH AVERAGE' => 3,
        'MIDDLE AVERAGE' => 4,
        'LOW AVERAGE' => 5,
        'BELOW AVERAGE' => 6
    ];

    $key = normalize_text($qualitativeText);
    return isset($map[$key]) ? (string) $map[$key] : '';
}

function is_empty_row($row)
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return false;
        }
    }
    return true;
}

function is_wide_header_row($row)
{
    return normalize_header($row[0] ?? '') === 'name_of_examinee'
        && normalize_header($row[1] ?? '') === 'examinee_number';
}

function detect_legacy_header_map($row)
{
    $normalized = [];
    foreach ($row as $index => $value) {
        $header = normalize_header($value);
        if ($header !== '') {
            $normalized[$header] = $index;
        }
    }

    if (!isset($normalized['examinee_number'])) {
        return null;
    }

    if (!isset($normalized['name_of_examinee']) && !isset($normalized['full_name']) && !isset($normalized['name'])) {
        return null;
    }

    return $normalized;
}

function first_value_from_map($row, $map, $keys)
{
    foreach ($keys as $key) {
        if (isset($map[$key]) && array_key_exists($map[$key], $row)) {
            return $row[$map[$key]];
        }
    }
    return '';
}

function parse_wide_template_row($row)
{
    $examNo = normalize_examinee_number($row[1] ?? '');
    if ($examNo === '') {
        return null;
    }

    $fullName = normalize_name($row[0] ?? '');
    if ($fullName === '') {
        return null;
    }

    $overallStandardScore = normalize_number($row[21] ?? '');
    $overallStanine = normalize_number($row[22] ?? '');
    $overallQualitative = normalize_text($row[23] ?? '');

    $satScore = $overallStandardScore;
    $qualitativeText = $overallQualitative;
    $qualitativeCode = qualitative_to_code($qualitativeText);

    $preferredProgram = trim((string) ($row[2] ?? ''));
    if (strtoupper($preferredProgram) === '#N/A') {
        $preferredProgram = '';
    }

    return [
        'examinee_number' => $examNo,
        'full_name' => $fullName,
        'preferred_program' => $preferredProgram,
        'sat_score' => $satScore,
        'qualitative_text' => $qualitativeText,
        'qualitative_code' => $qualitativeCode,
        'english_standard_score' => normalize_number($row[3] ?? ''),
        'english_stanine' => normalize_number($row[4] ?? ''),
        'english_qualitative_text' => normalize_text($row[5] ?? ''),
        'science_standard_score' => normalize_number($row[6] ?? ''),
        'science_stanine' => normalize_number($row[7] ?? ''),
        'science_qualitative_text' => normalize_text($row[8] ?? ''),
        'mathematics_standard_score' => normalize_number($row[9] ?? ''),
        'mathematics_stanine' => normalize_number($row[10] ?? ''),
        'mathematics_qualitative_text' => normalize_text($row[11] ?? ''),
        'filipino_standard_score' => normalize_number($row[12] ?? ''),
        'filipino_stanine' => normalize_number($row[13] ?? ''),
        'filipino_qualitative_text' => normalize_text($row[14] ?? ''),
        'social_studies_standard_score' => normalize_number($row[15] ?? ''),
        'social_studies_stanine' => normalize_number($row[16] ?? ''),
        'social_studies_qualitative_text' => normalize_text($row[17] ?? ''),
        'esm_competency_standard_score' => normalize_number($row[18] ?? ''),
        'esm_competency_stanine' => normalize_number($row[19] ?? ''),
        'esm_competency_qualitative_text' => normalize_text($row[20] ?? ''),
        'overall_standard_score' => $overallStandardScore,
        'overall_stanine' => $overallStanine,
        'overall_qualitative_text' => $overallQualitative
    ];
}

function parse_legacy_template_row($row, $map)
{
    $examNo = normalize_examinee_number(first_value_from_map($row, $map, ['examinee_number']));
    if ($examNo === '') {
        return null;
    }

    $fullName = normalize_name(first_value_from_map($row, $map, ['name_of_examinee', 'full_name', 'name']));
    if ($fullName === '') {
        return null;
    }

    $satScore = normalize_number(first_value_from_map($row, $map, ['overall_sat', 'sat_score', 'overall_score', 'overall_standard_score']));
    $qualitativeText = normalize_text(first_value_from_map($row, $map, ['qualitative_interpretation', 'qualitative_text', 'overall_qualitative_interpretation', 'overall_qualitative_text']));
    $qualitativeCode = qualitative_to_code($qualitativeText);

    $preferredProgram = trim((string) first_value_from_map($row, $map, ['preferred_program', 'program_preference']));
    if (strtoupper($preferredProgram) === '#N/A') {
        $preferredProgram = '';
    }

    return [
        'examinee_number' => $examNo,
        'full_name' => $fullName,
        'preferred_program' => $preferredProgram,
        'sat_score' => $satScore,
        'qualitative_text' => $qualitativeText,
        'qualitative_code' => $qualitativeCode,
        'english_standard_score' => '',
        'english_stanine' => '',
        'english_qualitative_text' => '',
        'science_standard_score' => '',
        'science_stanine' => '',
        'science_qualitative_text' => '',
        'mathematics_standard_score' => '',
        'mathematics_stanine' => '',
        'mathematics_qualitative_text' => '',
        'filipino_standard_score' => '',
        'filipino_stanine' => '',
        'filipino_qualitative_text' => '',
        'social_studies_standard_score' => '',
        'social_studies_stanine' => '',
        'social_studies_qualitative_text' => '',
        'esm_competency_standard_score' => '',
        'esm_competency_stanine' => '',
        'esm_competency_qualitative_text' => '',
        'overall_standard_score' => $satScore,
        'overall_stanine' => '',
        'overall_qualitative_text' => $qualitativeText
    ];
}

function write_normalized_row($handle, $record, $orderedColumns)
{
    $out = [];
    foreach ($orderedColumns as $column) {
        $out[] = isset($record[$column]) ? $record[$column] : '';
    }
    if (fputcsv($handle, $out) === false) {
        throw new Exception('Failed to write normalized CSV row.');
    }
}

function excel_col_letters_to_index($letters)
{
    $letters = strtoupper(trim((string) $letters));
    if ($letters === '') {
        return 0;
    }

    $index = 0;
    $len = strlen($letters);
    for ($i = 0; $i < $len; $i++) {
        $ord = ord($letters[$i]);
        if ($ord < 65 || $ord > 90) {
            continue;
        }
        $index = ($index * 26) + ($ord - 64);
    }

    return max(0, $index - 1);
}

function resolve_first_sheet_path($zip)
{
    $defaultPath = 'xl/worksheets/sheet1.xml';
    if ($zip->locateName($defaultPath) !== false) {
        return $defaultPath;
    }

    $workbookXmlRaw = $zip->getFromName('xl/workbook.xml');
    $relsXmlRaw = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXmlRaw === false || $relsXmlRaw === false) {
        return null;
    }

    $workbookXml = @simplexml_load_string($workbookXmlRaw);
    $relsXml = @simplexml_load_string($relsXmlRaw);
    if (!$workbookXml || !$relsXml) {
        return null;
    }

    $workbookNs = $workbookXml->getNamespaces(true);
    $relsNs = $relsXml->getNamespaces(true);
    if (!isset($workbookNs[''])) {
        return null;
    }

    $workbookXml->registerXPathNamespace('x', $workbookNs['']);
    if (isset($workbookNs['r'])) {
        $workbookXml->registerXPathNamespace('r', $workbookNs['r']);
    }

    $relsDefaultNs = isset($relsNs['']) ? $relsNs[''] : '';
    if ($relsDefaultNs === '') {
        return null;
    }
    $relsXml->registerXPathNamespace('r', $relsDefaultNs);

    $sheetNodes = $workbookXml->xpath('//x:sheets/x:sheet');
    if (!$sheetNodes || !isset($sheetNodes[0])) {
        return null;
    }

    $firstSheet = $sheetNodes[0];
    $ridAttrs = $firstSheet->attributes($workbookNs['r'] ?? null, true);
    $relationshipId = isset($ridAttrs['id']) ? (string) $ridAttrs['id'] : '';
    if ($relationshipId === '') {
        return null;
    }

    $relationshipNodes = $relsXml->xpath("//r:Relationship[@Id='{$relationshipId}']");
    if (!$relationshipNodes || !isset($relationshipNodes[0])) {
        return null;
    }

    $target = (string) $relationshipNodes[0]['Target'];
    if ($target === '') {
        return null;
    }

    $target = str_replace('\\', '/', $target);
    if (strpos($target, '/xl/') === 0) {
        return ltrim($target, '/');
    }
    if (strpos($target, 'xl/') === 0) {
        return $target;
    }
    if (strpos($target, 'worksheets/') === 0) {
        return 'xl/' . $target;
    }
    if (strpos($target, '../') === 0) {
        $target = preg_replace('#^\.\./#', '', $target);
        return 'xl/' . $target;
    }

    return 'xl/' . ltrim($target, '/');
}

function load_shared_strings_from_xlsx($zip)
{
    $sharedStringsRaw = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedStringsRaw === false || trim($sharedStringsRaw) === '') {
        return [];
    }

    $sharedXml = @simplexml_load_string($sharedStringsRaw);
    if (!$sharedXml) {
        return [];
    }

    $ns = $sharedXml->getNamespaces(true);
    $mainNs = isset($ns['']) ? $ns[''] : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $sharedXml->registerXPathNamespace('s', $mainNs);

    $items = $sharedXml->xpath('//s:si');
    if (!$items) {
        return [];
    }

    $values = [];
    foreach ($items as $item) {
        $text = '';
        $itemChildren = $item->children($mainNs);

        if (isset($itemChildren->t)) {
            $text = (string) $itemChildren->t;
        } elseif (isset($itemChildren->r)) {
            foreach ($itemChildren->r as $run) {
                $runChildren = $run->children($mainNs);
                if (isset($runChildren->t)) {
                    $text .= (string) $runChildren->t;
                }
            }
        }

        $values[] = $text;
    }

    return $values;
}

function extract_inline_text_from_cell($cell, $mainNs)
{
    $cellChildren = $cell->children($mainNs);
    if (!isset($cellChildren->is)) {
        return '';
    }

    $inline = $cellChildren->is;
    $inlineChildren = $inline->children($mainNs);
    if (isset($inlineChildren->t)) {
        return (string) $inlineChildren->t;
    }

    $text = '';
    if (isset($inlineChildren->r)) {
        foreach ($inlineChildren->r as $run) {
            $runChildren = $run->children($mainNs);
            if (isset($runChildren->t)) {
                $text .= (string) $runChildren->t;
            }
        }
    }

    return $text;
}

function parse_xlsx_rows_native($xlsxPath)
{
    if (!class_exists('ZipArchive')) {
        throw new Exception('ZipArchive extension is required for XLSX uploads.');
    }
    if (!function_exists('simplexml_load_string')) {
        throw new Exception('SimpleXML extension is required for XLSX uploads.');
    }

    $zip = new ZipArchive();
    $openResult = $zip->open($xlsxPath);
    if ($openResult !== true) {
        throw new Exception('Failed to open XLSX file.');
    }

    try {
        $sheetPath = resolve_first_sheet_path($zip);
        if ($sheetPath === null || $zip->locateName($sheetPath) === false) {
            throw new Exception('Unable to locate worksheet in XLSX file.');
        }

        $sheetRaw = $zip->getFromName($sheetPath);
        if ($sheetRaw === false || trim($sheetRaw) === '') {
            throw new Exception('Worksheet is empty or unreadable.');
        }

        $sharedStrings = load_shared_strings_from_xlsx($zip);
        $sheetXml = @simplexml_load_string($sheetRaw);
        if (!$sheetXml) {
            throw new Exception('Failed to parse worksheet XML.');
        }

        $ns = $sheetXml->getNamespaces(true);
        $mainNs = isset($ns['']) ? $ns[''] : 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheetXml->registerXPathNamespace('s', $mainNs);
        $rowNodes = $sheetXml->xpath('//s:sheetData/s:row');
        if (!$rowNodes) {
            return [];
        }

        $rowsSparse = [];
        $maxCol = 0;

        foreach ($rowNodes as $rowNode) {
            $rowData = [];
            $rowChildren = $rowNode->children($mainNs);
            $cells = isset($rowChildren->c) ? $rowChildren->c : [];
            if (!$cells || count($cells) === 0) {
                $rowsSparse[] = [];
                continue;
            }

            $lastColumnIndex = -1;
            foreach ($cells as $cell) {
                $cellAttrs = $cell->attributes();
                $reference = isset($cellAttrs['r']) ? (string) $cellAttrs['r'] : '';
                $columnLetters = '';
                if (preg_match('/[A-Z]+/i', $reference, $match)) {
                    $columnLetters = strtoupper($match[0]);
                }
                if ($columnLetters !== '') {
                    $columnIndex = excel_col_letters_to_index($columnLetters);
                } else {
                    $columnIndex = $lastColumnIndex + 1;
                }
                $lastColumnIndex = $columnIndex;
                if ($columnIndex > $maxCol) {
                    $maxCol = $columnIndex;
                }

                $type = strtolower(isset($cellAttrs['t']) ? (string) $cellAttrs['t'] : '');
                $cellChildren = $cell->children($mainNs);
                $rawValue = isset($cellChildren->v) ? (string) $cellChildren->v : '';
                $value = '';

                if ($type === 's') {
                    $sharedIndex = (int) $rawValue;
                    $value = isset($sharedStrings[$sharedIndex]) ? $sharedStrings[$sharedIndex] : '';
                } elseif ($type === 'inlinestr') {
                    $value = extract_inline_text_from_cell($cell, $mainNs);
                } elseif ($type === 'b') {
                    $value = ($rawValue === '1') ? '1' : '0';
                } else {
                    $value = $rawValue;
                    if ($value === '') {
                        $value = extract_inline_text_from_cell($cell, $mainNs);
                    }
                }

                $rowData[$columnIndex] = $value;
            }

            ksort($rowData);
            $rowsSparse[] = $rowData;
        }

        $rowsDense = [];
        foreach ($rowsSparse as $rowSparse) {
            if (empty($rowSparse)) {
                $rowsDense[] = [];
                continue;
            }

            $dense = array_fill(0, $maxCol + 1, '');
            foreach ($rowSparse as $colIndex => $cellValue) {
                $dense[(int) $colIndex] = $cellValue;
            }
            $rowsDense[] = $dense;
        }

        return $rowsDense;
    } finally {
        $zip->close();
    }
}

$fileField = '';
if (isset($_FILES['data_file'])) {
    $fileField = 'data_file';
} elseif (isset($_FILES['csv_file'])) {
    $fileField = 'csv_file';
}

if ($fileField === '' || !isset($_POST['batch_id'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Missing file or batch ID']);
    exit;
}

$batchId = trim((string) $_POST['batch_id']);
$tmpFile = $_FILES[$fileField]['tmp_name'];
$originalName = (string) ($_FILES[$fileField]['name'] ?? '');
$extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
$uploadError = (int) ($_FILES[$fileField]['error'] ?? UPLOAD_ERR_NO_FILE);

if ($uploadError !== UPLOAD_ERR_OK) {
    $uploadErrorMap = [
        UPLOAD_ERR_INI_SIZE => 'Uploaded file exceeds server upload_max_filesize limit.',
        UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the form MAX_FILE_SIZE limit.',
        UPLOAD_ERR_PARTIAL => 'Uploaded file was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Missing temporary upload directory.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write uploaded file to disk.',
        UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.'
    ];
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $uploadErrorMap[$uploadError] ?? ('Upload error code: ' . $uploadError)
    ]);
    exit;
}

if (!is_uploaded_file($tmpFile)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid upload']);
    exit;
}

$allowedExtensions = ['csv', 'xlsx', 'xls'];
if (!in_array($extension, $allowedExtensions, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Unsupported file type. Use CSV, XLS, or XLSX.']);
    exit;
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0777, true) && !is_dir($uploadDir)) {
        json_exit(500, ['success' => false, 'message' => 'Failed to create upload directory.']);
    }
}

$sourcePath = $uploadDir . "/source_{$batchId}." . $extension;
$normalizedCsvPath = $uploadDir . "/batch_{$batchId}.csv";

if (!move_uploaded_file($tmpFile, $sourcePath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to store uploaded file']);
    exit;
}

$normalizedColumns = [
    'examinee_number',
    'full_name',
    'preferred_program',
    'sat_score',
    'qualitative_text',
    'qualitative_code',
    'english_standard_score',
    'english_stanine',
    'english_qualitative_text',
    'science_standard_score',
    'science_stanine',
    'science_qualitative_text',
    'mathematics_standard_score',
    'mathematics_stanine',
    'mathematics_qualitative_text',
    'filipino_standard_score',
    'filipino_stanine',
    'filipino_qualitative_text',
    'social_studies_standard_score',
    'social_studies_stanine',
    'social_studies_qualitative_text',
    'esm_competency_standard_score',
    'esm_competency_stanine',
    'esm_competency_qualitative_text',
    'overall_standard_score',
    'overall_stanine',
    'overall_qualitative_text'
];

$totalRows = 0;
$mode = '';
$legacyHeaderMap = [];

$outHandle = fopen($normalizedCsvPath, 'w');
if ($outHandle === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to prepare normalized upload file']);
    exit;
}
if (fputcsv($outHandle, $normalizedColumns) === false) {
    fclose($outHandle);
    json_exit(500, ['success' => false, 'message' => 'Failed to write normalized CSV header.']);
}

try {
    if ($extension === 'csv') {
        $inHandle = fopen($sourcePath, 'r');
        if ($inHandle === false) {
            throw new Exception('Failed to read uploaded CSV file');
        }

        while (($row = fgetcsv($inHandle)) !== false) {
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string) $row[0]);
            }

            if (is_empty_row($row)) {
                continue;
            }

            if ($mode === '') {
                if (is_wide_header_row($row)) {
                    $mode = 'wide';
                    continue;
                }

                $detectedLegacyMap = detect_legacy_header_map($row);
                if ($detectedLegacyMap !== null) {
                    $mode = 'legacy';
                    $legacyHeaderMap = $detectedLegacyMap;
                    continue;
                }

                $wideProbe = parse_wide_template_row($row);
                if ($wideProbe !== null) {
                    $mode = 'wide';
                    write_normalized_row($outHandle, $wideProbe, $normalizedColumns);
                    $totalRows++;
                }
                continue;
            }

            if ($mode === 'wide') {
                $parsed = parse_wide_template_row($row);
            } else {
                $parsed = parse_legacy_template_row($row, $legacyHeaderMap);
            }

            if ($parsed === null) {
                continue;
            }

            write_normalized_row($outHandle, $parsed, $normalizedColumns);
            $totalRows++;
        }

        fclose($inHandle);
    } elseif ($extension === 'xlsx') {
        $rows = parse_xlsx_rows_native($sourcePath);
        foreach ($rows as $row) {
            if (is_empty_row($row)) {
                continue;
            }

            if ($mode === '') {
                if (is_wide_header_row($row)) {
                    $mode = 'wide';
                    continue;
                }

                $detectedLegacyMap = detect_legacy_header_map($row);
                if ($detectedLegacyMap !== null) {
                    $mode = 'legacy';
                    $legacyHeaderMap = $detectedLegacyMap;
                    continue;
                }

                $wideProbe = parse_wide_template_row($row);
                if ($wideProbe !== null) {
                    $mode = 'wide';
                    write_normalized_row($outHandle, $wideProbe, $normalizedColumns);
                    $totalRows++;
                }
                continue;
            }

            if ($mode === 'wide') {
                $parsed = parse_wide_template_row($row);
            } else {
                $parsed = parse_legacy_template_row($row, $legacyHeaderMap);
            }

            if ($parsed === null) {
                continue;
            }

            write_normalized_row($outHandle, $parsed, $normalizedColumns);
            $totalRows++;
        }
    } else {
        // Legacy .xls requires PhpSpreadsheet runtime support.
        $autoloadPath = __DIR__ . '/../../vendor/autoload.php';
        if (!file_exists($autoloadPath)) {
            throw new Exception('XLS is not supported on this server. Please save the file as XLSX.');
        }

        require_once $autoloadPath;
        if (!class_exists('\\PhpOffice\\PhpSpreadsheet\\IOFactory')) {
            throw new Exception('XLS reader is unavailable. Please convert file to XLSX.');
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($sourcePath);
        if (method_exists($reader, 'setReadDataOnly')) {
            $reader->setReadDataOnly(true);
        }
        if (method_exists($reader, 'setReadEmptyCells')) {
            $reader->setReadEmptyCells(false);
        }

        $spreadsheet = $reader->load($sourcePath);
        $sheet = $spreadsheet->getSheet(0);
        $highestRow = (int) $sheet->getHighestDataRow();
        $highestColumn = $sheet->getHighestDataColumn();
        $highestColumnIndex = 0;
        if (class_exists('\\PhpOffice\\PhpSpreadsheet\\Cell\\Coordinate')) {
            $highestColumnIndex = (int) \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        }

        for ($rowIndex = 1; $rowIndex <= $highestRow; $rowIndex++) {
            $row = $sheet->rangeToArray(
                'A' . $rowIndex . ':' . $highestColumn . $rowIndex,
                '',
                true,
                false
            )[0];

            if ($highestColumnIndex > 0 && count($row) < $highestColumnIndex) {
                $row = array_pad($row, $highestColumnIndex, '');
            }

            if (is_empty_row($row)) {
                continue;
            }

            if ($mode === '') {
                if (is_wide_header_row($row)) {
                    $mode = 'wide';
                    continue;
                }

                $detectedLegacyMap = detect_legacy_header_map($row);
                if ($detectedLegacyMap !== null) {
                    $mode = 'legacy';
                    $legacyHeaderMap = $detectedLegacyMap;
                    continue;
                }

                $wideProbe = parse_wide_template_row($row);
                if ($wideProbe !== null) {
                    $mode = 'wide';
                    write_normalized_row($outHandle, $wideProbe, $normalizedColumns);
                    $totalRows++;
                }
                continue;
            }

            if ($mode === 'wide') {
                $parsed = parse_wide_template_row($row);
            } else {
                $parsed = parse_legacy_template_row($row, $legacyHeaderMap);
            }

            if ($parsed === null) {
                continue;
            }

            write_normalized_row($outHandle, $parsed, $normalizedColumns);
            $totalRows++;
        }

        if (method_exists($spreadsheet, 'disconnectWorksheets')) {
            $spreadsheet->disconnectWorksheets();
        }
        unset($spreadsheet);
    }

    fclose($outHandle);
} catch (Throwable $e) {
    if (is_resource($outHandle)) {
        fclose($outHandle);
    }
    @unlink($normalizedCsvPath);

    // Never allow status-update failure to hide the real parse error.
    try {
        $stmtFail = $conn->prepare("
            UPDATE tbl_placement_upload_batches
            SET status = 'failed'
            WHERE batch_id = ?
        ");
        if ($stmtFail) {
            $stmtFail->bind_param("s", $batchId);
            $stmtFail->execute();
        }
    } catch (Throwable $ignoredStatusUpdateError) {
    }

    json_exit(500, [
        'success' => false,
        'message' => 'Failed to parse uploaded file: ' . $e->getMessage()
    ]);
}

if ($totalRows <= 0) {
    $stmtEmpty = $conn->prepare("
        UPDATE tbl_placement_upload_batches
        SET total_rows = 0, status = 'failed'
        WHERE batch_id = ?
    ");
    if ($stmtEmpty) {
        $stmtEmpty->bind_param("s", $batchId);
        $stmtEmpty->execute();
    }

    echo json_encode([
        'success' => false,
        'message' => 'No valid data rows found. Please verify the file template.',
        'total_rows' => 0
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE tbl_placement_upload_batches
    SET total_rows = ?, status = 'processing'
    WHERE batch_id = ?
");
if (!$stmt) {
    json_exit(500, ['success' => false, 'message' => 'Failed to update upload batch totals.']);
}

$stmt->bind_param("is", $totalRows, $batchId);
if (!$stmt->execute()) {
    json_exit(500, ['success' => false, 'message' => 'Failed to persist upload batch totals.']);
}

echo json_encode([
    'success' => true,
    'total_rows' => $totalRows
]);
