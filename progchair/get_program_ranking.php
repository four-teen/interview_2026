<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/get_program_ranking.php
 * PURPOSE: Return ranked students for a selected program (JSON)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['campus_id'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$campusId  = (int) $_SESSION['campus_id'];
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;

if ($programId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid program'
    ]);
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
    error_log('SQL prepare failed (programSql): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
    exit;
}

$stmtProgram->bind_param("ii", $programId, $campusId);
$stmtProgram->execute();
$program = $stmtProgram->get_result()->fetch_assoc();

if (!$program) {
    echo json_encode([
        'success' => false,
        'message' => 'Program not found'
    ]);
    exit;
}

$rankingSql = "
    SELECT
        si.interview_id,
        si.examinee_number,
        pr.full_name,
        pr.sat_score,
        si.final_score,
        si.interview_datetime,
        a.acc_fullname AS encoded_by,
        CASE
            WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG'
                THEN CONCAT('ETG-', COALESCE(NULLIF(TRIM(ec.class_desc), ''), 'UNSPECIFIED'))
            ELSE 'REGULAR'
        END AS classification_label,
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
    error_log('SQL prepare failed (rankingSql): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error'
    ]);
    exit;
}

$stmtRanking->bind_param("i", $programId);
$stmtRanking->execute();
$resultRanking = $stmtRanking->get_result();

$allRegularRows = [];
$allEtgRows = [];
while ($row = $resultRanking->fetch_assoc()) {
    $mappedRow = [
        'interview_id'      => (int) $row['interview_id'],
        'examinee_number'   => $row['examinee_number'],
        'full_name'         => $row['full_name'],
        'classification'    => $row['classification_label'],
        'sat_score'         => (int) $row['sat_score'],
        'final_score'       => number_format((float) $row['final_score'], 2),
        'interview_datetime'=> $row['interview_datetime'],
        'encoded_by'        => $row['encoded_by']
    ];

    if ((int) ($row['classification_group'] ?? 0) === 1) {
        $allEtgRows[] = $mappedRow;
    } else {
        $allRegularRows[] = $mappedRow;
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

$rows = array_merge($regularRows, $etgRows);

echo json_encode([
    'success' => true,
    'program' => [
        'program_id'   => (int) $program['program_id'],
        'program_name' => strtoupper($program['program_name'] . (!empty($program['major']) ? ' - ' . $program['major'] : ''))
    ],
    'quota' => [
        'enabled' => $quotaEnabled,
        'absorptive_capacity' => $absorptiveCapacity,
        'regular_percentage' => $regularPercentage,
        'etg_percentage' => $etgPercentage,
        'regular_slots' => $regularSlots,
        'etg_slots' => $etgSlots,
        'regular_candidates' => count($allRegularRows),
        'etg_candidates' => count($allEtgRows),
        'regular_shown' => count($regularRows),
        'etg_shown' => count($etgRows)
    ],
    'rows' => $rows
]);

exit;
