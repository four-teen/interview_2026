<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/get_program_ranking.php
 * PURPOSE: Return ranked students for a selected program (JSON)
 * ============================================================================
 */

require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once 'endorsement_helpers.php';
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
        pc.cutoff_score,
        pc.absorptive_capacity,
        pc.regular_percentage,
        pc.etg_percentage,
        COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity
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

$programCutoff = $program['cutoff_score'] !== null ? (int) $program['cutoff_score'] : null;
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffMin = isset($globalSatCutoffState['min']) ? (int) $globalSatCutoffState['min'] : null;
$globalSatCutoffMax = isset($globalSatCutoffState['max']) ? (int) $globalSatCutoffState['max'] : null;
$globalSatCutoffActive = (bool) ($globalSatCutoffState['active'] ?? false);
$effectiveCutoff = get_effective_sat_cutoff($programCutoff, $globalSatCutoffActive, $globalSatCutoffMin);
$cutoffWhereSql = '';
if ($globalSatCutoffActive) {
    $cutoffWhereSql = ' AND pr.sat_score BETWEEN ? AND ?';
} elseif ($effectiveCutoff !== null) {
    $cutoffWhereSql = ' AND pr.sat_score >= ?';
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
      {$cutoffWhereSql}
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

if ($globalSatCutoffActive) {
    $stmtRanking->bind_param("iii", $programId, $globalSatCutoffMin, $globalSatCutoffMax);
} elseif ($effectiveCutoff !== null) {
    $stmtRanking->bind_param("ii", $programId, $effectiveCutoff);
} else {
    $stmtRanking->bind_param("i", $programId);
}
$stmtRanking->execute();
$resultRanking = $stmtRanking->get_result();

$allRegularRows = [];
$allEtgRows = [];
$allRowsByInterviewId = [];
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

    $allRowsByInterviewId[$mappedRow['interview_id']] = $mappedRow;

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
$endorsementCapacity = max(0, (int) ($program['endorsement_capacity'] ?? 0));
$regularSlots = null;
$etgSlots = null;
$baseCapacity = null;

if (
    $program['absorptive_capacity'] !== null &&
    $program['regular_percentage'] !== null &&
    $program['etg_percentage'] !== null
) {
    $absorptiveCapacity = max(0, (int) $program['absorptive_capacity']);
    $regularPercentage = round((float) $program['regular_percentage'], 2);
    $etgPercentage = round((float) $program['etg_percentage'], 2);
    $baseCapacity = max(0, $absorptiveCapacity - $endorsementCapacity);

    if (
        $regularPercentage >= 0 &&
        $regularPercentage <= 100 &&
        $etgPercentage >= 0 &&
        $etgPercentage <= 100 &&
        abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
    ) {
        $quotaEnabled = true;
        $regularSlots = (int) round($baseCapacity * ($regularPercentage / 100));
        $etgSlots = max(0, $baseCapacity - $regularSlots);
    }
}

// Endorsement rows are persisted and should remain listed below regular rows.
$endorsementRowsRaw = load_program_endorsements($conn, $programId);
$endorsementRows = [];
$endorsementIds = [];
foreach ($endorsementRowsRaw as $endorsementRow) {
    $eid = (int) ($endorsementRow['interview_id'] ?? 0);
    if ($eid <= 0 || !isset($allRowsByInterviewId[$eid])) {
        continue;
    }

    $mapped = $allRowsByInterviewId[$eid];
    $mapped['is_endorsement'] = true;
    $mapped['endorsement_label'] = 'SCC';
    $mapped['endorsement_order'] = (string) ($endorsementRow['endorsed_at'] ?? '');
    $endorsementRows[] = $mapped;
    $endorsementIds[$eid] = true;
}

// Exclude EC rows from ranked pools. They will be appended after regular rows.
$filteredRegularRows = array_values(array_filter($allRegularRows, function (array $row) use ($endorsementIds): bool {
    return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
}));
$filteredEtgRows = array_values(array_filter($allEtgRows, function (array $row) use ($endorsementIds): bool {
    return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
}));

$regularRows = $filteredRegularRows;
$etgRows = $filteredEtgRows;

$regularEffectiveSlots = null;
$endorsementInRegularSlots = 0;
$endorsementSelectedCount = count($endorsementRows);

if ($quotaEnabled) {
    $regularSlotsCount = max(0, (int) $regularSlots);
    $endorsementSlotsCount = max(0, (int) $endorsementCapacity);
    $endorsementInRegularSlots = min($endorsementSelectedCount, $regularSlotsCount, $endorsementSlotsCount);
    $regularEffectiveSlots = max(0, $regularSlotsCount - $endorsementInRegularSlots);

    $regularShownCount = min(count($regularRows), $regularEffectiveSlots);
    $etgShownCount = min(count($etgRows), max(0, (int) $etgSlots));
    $endorsementShownCount = min($endorsementSelectedCount, min($regularSlotsCount, $endorsementSlotsCount));
} else {
    $regularShownCount = count($regularRows);
    $etgShownCount = count($etgRows);
    $endorsementShownCount = $endorsementSelectedCount;
}

foreach ($regularRows as &$regularRow) {
    $regularRow['is_endorsement'] = false;
}
unset($regularRow);

foreach ($etgRows as &$etgRow) {
    $etgRow['is_endorsement'] = false;
}
unset($etgRow);

$rows = array_merge($regularRows, $endorsementRows, $etgRows);

echo json_encode([
    'success' => true,
    'program' => [
        'program_id'   => (int) $program['program_id'],
        'program_name' => strtoupper($program['program_name'] . (!empty($program['major']) ? ' - ' . $program['major'] : ''))
    ],
    'quota' => [
        'enabled' => $quotaEnabled,
        'cutoff_score' => $effectiveCutoff,
        'program_cutoff_score' => $programCutoff,
        'global_cutoff_enabled' => $globalSatCutoffEnabled,
        'global_cutoff_value' => $globalSatCutoffMin,
        'global_cutoff_min' => $globalSatCutoffMin,
        'global_cutoff_max' => $globalSatCutoffMax,
        'global_cutoff_active' => $globalSatCutoffActive,
        'absorptive_capacity' => $absorptiveCapacity,
        'base_capacity' => $baseCapacity,
        'endorsement_capacity' => $endorsementCapacity,
        'endorsement_selected' => $endorsementSelectedCount,
        'regular_slots' => $regularSlots,
        'regular_effective_slots' => $regularEffectiveSlots,
        'endorsement_in_regular_slots' => $endorsementInRegularSlots,
        'etg_slots' => $etgSlots,
        'regular_candidates' => count($filteredRegularRows),
        'etg_candidates' => count($filteredEtgRows),
        'regular_shown' => $regularShownCount,
        'endorsement_shown' => $endorsementShownCount,
        'etg_shown' => $etgShownCount
    ],
    'rows' => $rows
]);

exit;
