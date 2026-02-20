<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/export_program_ranking.php
 * PURPOSE: Export ranked students for a selected program (Excel-ready CSV)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['campus_id'])
) {
    header('Location: ../index.php');
    exit;
}

$campusId  = (int) $_SESSION['campus_id'];
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;

if ($programId <= 0) {
    header('Location: index.php?msg=invalid_program');
    exit;
}

// Validate program belongs to current campus
$programSql = "
    SELECT
        p.program_id,
        p.program_name,
        p.major,
        pc.absorptive_capacity,
        pc.regular_percentage,
        pc.etg_percentage
    FROM tbl_program p
    INNER JOIN tbl_college c
        ON p.college_id = c.college_id
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    WHERE p.program_id = ?
      AND c.campus_id = ?
      AND p.status = 'active'
    LIMIT 1
";

$stmtProgram = $conn->prepare($programSql);
if (!$stmtProgram) {
    error_log('SQL prepare failed (export programSql): ' . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtProgram->bind_param("ii", $programId, $campusId);
$stmtProgram->execute();
$program = $stmtProgram->get_result()->fetch_assoc();

if (!$program) {
    header('Location: index.php?msg=invalid_program');
    exit;
}

$rankingSql = "
    SELECT
        si.examinee_number,
        pr.full_name,
        CASE
            WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG'
                THEN CONCAT('ETG-', COALESCE(NULLIF(TRIM(ec.class_desc), ''), 'UNSPECIFIED'))
            ELSE 'REGULAR'
        END AS classification_label,
        pr.sat_score,
        si.final_score,
        si.interview_datetime,
        a.acc_fullname AS encoded_by,
        CASE
            WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 1
            ELSE 0
        END AS classification_group
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    LEFT JOIN tblaccount a
        ON si.program_chair_id = a.accountid
    LEFT JOIN tbl_etg_class ec
        ON si.etg_class_id = ec.etgclassid
    WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
      AND si.status = 'active'
      AND si.final_score IS NOT NULL
    ORDER BY
        classification_group ASC,
        si.final_score DESC,
        pr.sat_score DESC,
        pr.full_name ASC
";

$stmtRanking = $conn->prepare($rankingSql);
if (!$stmtRanking) {
    error_log('SQL prepare failed (export rankingSql): ' . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtRanking->bind_param("i", $programId);
$stmtRanking->execute();
$resultRanking = $stmtRanking->get_result();

$allRegularRows = [];
$allEtgRows = [];
while ($row = $resultRanking->fetch_assoc()) {
    if ((int) ($row['classification_group'] ?? 0) === 1) {
        $allEtgRows[] = $row;
    } else {
        $allRegularRows[] = $row;
    }
}

$quotaEnabled = false;
$absorptiveCapacity = null;
$regularPercentage = null;
$etgPercentage = null;
$regularSlots = null;
$etgSlots = null;

if (
    $program['absorptive_capacity'] !== null &&
    $program['regular_percentage'] !== null &&
    $program['etg_percentage'] !== null
) {
    $absorptiveCapacity = max(0, (int) $program['absorptive_capacity']);
    $regularPercentage = round((float) $program['regular_percentage'], 2);
    $etgPercentage = round((float) $program['etg_percentage'], 2);

    if (
        $regularPercentage >= 0 &&
        $regularPercentage <= 100 &&
        $etgPercentage >= 0 &&
        $etgPercentage <= 100 &&
        abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
    ) {
        $quotaEnabled = true;
        $regularSlots = (int) round($absorptiveCapacity * ($regularPercentage / 100));
        $etgSlots = max(0, $absorptiveCapacity - $regularSlots);
    }
}

if ($quotaEnabled) {
    $regularRows = array_slice($allRegularRows, 0, $regularSlots);
    $etgRows = array_slice($allEtgRows, 0, $etgSlots);
} else {
    $regularRows = $allRegularRows;
    $etgRows = $allEtgRows;
}

$exportRows = array_merge($regularRows, $etgRows);

$programLabel = strtoupper($program['program_name'] . (!empty($program['major']) ? ' - ' . $program['major'] : ''));
$safeName = preg_replace('/[^A-Za-z0-9]+/', '_', $programLabel);
$safeName = trim($safeName, '_');
$filename = 'program_ranking_' . strtolower($safeName) . '_' . date('Ymd_His') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM for Excel compatibility
echo "\xEF\xBB\xBF";

$output = fopen('php://output', 'w');

fputcsv($output, ['Program Ranking']);
fputcsv($output, ['Program', $programLabel]);
fputcsv($output, ['Generated', date('Y-m-d H:i:s')]);
if ($quotaEnabled) {
    $quotaSummary = sprintf(
        'Capacity: %d | Regular: %.2f%% (%d) | ETG: %.2f%% (%d)',
        (int) $absorptiveCapacity,
        (float) $regularPercentage,
        (int) $regularSlots,
        (float) $etgPercentage,
        (int) $etgSlots
    );
    fputcsv($output, ['Quota', $quotaSummary]);
}
fputcsv($output, []);
fputcsv($output, ['Rank', 'Examinee #', 'Student Name', 'Classification', 'SAT Score', 'Final Score', 'Encoded By', 'Interview Datetime']);

$rank = 1;
foreach ($exportRows as $row) {
    fputcsv($output, [
        $rank,
        $row['examinee_number'],
        $row['full_name'],
        $row['classification_label'],
        $row['sat_score'],
        number_format((float) $row['final_score'], 2),
        $row['encoded_by'],
        $row['interview_datetime']
    ]);
    $rank++;
}

fclose($output);
exit;
