<?php
/**
 * Export ranked students for a selected program (Excel-ready CSV).
 * Uses shared ranking payload so Program Chair and Monitoring stay identical.
 */

require_once '../config/db.php';
require_once '../config/program_ranking_lock.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    ($_SESSION['role'] ?? '') !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['campus_id'])
) {
    header('Location: ../index.php');
    exit;
}

$campusId = (int) $_SESSION['campus_id'];
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
if ($programId <= 0) {
    header('Location: index.php?msg=invalid_program');
    exit;
}

$payload = program_ranking_fetch_payload($conn, $programId, $campusId);
if (!($payload['success'] ?? false)) {
    header('Location: index.php?msg=invalid_program');
    exit;
}

$program = is_array($payload['program'] ?? null) ? $payload['program'] : [];
$quota = is_array($payload['quota'] ?? null) ? $payload['quota'] : [];
$rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
$locks = is_array($payload['locks'] ?? null) ? $payload['locks'] : ['active_count' => 0, 'ranges' => []];

$programLabel = strtoupper((string) ($program['program_name'] ?? 'PROGRAM'));
$safeName = preg_replace('/[^A-Za-z0-9]+/', '_', $programLabel);
$safeName = trim((string) $safeName, '_');
$filename = 'program_ranking_' . strtolower(($safeName !== '' ? $safeName : 'program')) . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
$output = fopen('php://output', 'w');

fputcsv($output, ['Program Ranking']);
fputcsv($output, ['Program', $programLabel]);
fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);

$cutoffValue = $quota['cutoff_score'] ?? null;
if ($cutoffValue !== null && $cutoffValue !== '') {
    fputcsv($output, ['Applied SAT Cutoff', (string) $cutoffValue]);
}

if (($quota['enabled'] ?? false) === true) {
    $quotaSummary = sprintf(
        'Capacity: %d | Base: %d | Regular: %d/%d | SCC: %d/%d | ETG: %d/%d',
        (int) ($quota['absorptive_capacity'] ?? 0),
        (int) ($quota['base_capacity'] ?? 0),
        (int) ($quota['regular_shown'] ?? 0),
        (int) ($quota['regular_slots'] ?? 0),
        (int) ($quota['endorsement_shown'] ?? 0),
        (int) ($quota['endorsement_capacity'] ?? 0),
        (int) ($quota['etg_shown'] ?? 0),
        (int) ($quota['etg_slots'] ?? 0)
    );
    fputcsv($output, ['Quota', $quotaSummary]);
}

$lockRanges = is_array($locks['ranges'] ?? null) ? $locks['ranges'] : [];
$lockRangeText = !empty($lockRanges) ? implode(', ', $lockRanges) : '';
fputcsv($output, ['Locked Ranks', $lockRangeText !== '' ? $lockRangeText : 'None']);
fputcsv($output, []);

fputcsv($output, [
    'Rank',
    'Examinee #',
    'Student Name',
    'Class',
    'SAT Score',
    'Final Score',
    'Locked',
    'Outside Capacity',
    'Encoded By',
    'Interview Datetime'
]);

foreach ($rows as $index => $row) {
    $section = strtolower(trim((string) ($row['row_section'] ?? '')));
    if ($section === 'scc') {
        $classLabel = 'SCC';
    } elseif ($section === 'etg') {
        $classLabel = 'ETG';
    } else {
        $classLabel = 'R';
    }

    $rank = (int) ($row['rank'] ?? 0);
    if ($rank <= 0) {
        $rank = $index + 1;
    }

    fputcsv($output, [
        $rank,
        (string) ($row['examinee_number'] ?? ''),
        (string) ($row['full_name'] ?? ''),
        $classLabel,
        (string) ($row['sat_score'] ?? ''),
        number_format((float) ($row['final_score'] ?? 0), 2, '.', ''),
        ((bool) ($row['is_locked'] ?? false)) ? 'YES' : 'NO',
        ((bool) ($row['is_outside_capacity'] ?? false)) ? 'YES' : 'NO',
        (string) ($row['encoded_by'] ?? ''),
        (string) ($row['interview_datetime'] ?? '')
    ]);
}

fclose($output);
exit;
