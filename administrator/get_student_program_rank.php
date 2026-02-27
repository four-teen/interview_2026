<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../progchair/endorsement_helpers.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$interviewId = isset($_GET['interview_id']) ? (int) $_GET['interview_id'] : 0;
if ($interviewId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid interview id.'
    ]);
    exit;
}

ensure_program_endorsement_table($conn);

$studentSql = "
    SELECT
        si.interview_id,
        si.examinee_number,
        si.final_score,
        si.classification,
        COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) AS ranking_program_id,
        pr.full_name,
        pr.sat_score,
        p.program_name,
        p.major
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON pr.id = si.placement_result_id
    LEFT JOIN tbl_program p
        ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
    WHERE si.interview_id = ?
      AND si.status = 'active'
    LIMIT 1
";

$stmtStudent = $conn->prepare($studentSql);
if (!$stmtStudent) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (student query).'
    ]);
    exit;
}

$stmtStudent->bind_param('i', $interviewId);
$stmtStudent->execute();
$student = $stmtStudent->get_result()->fetch_assoc();
$stmtStudent->close();

if (!$student) {
    echo json_encode([
        'success' => false,
        'message' => 'Student record not found.'
    ]);
    exit;
}

$programId = (int) ($student['ranking_program_id'] ?? 0);
$programDisplay = trim((string) ($student['program_name'] ?? ''));
$major = trim((string) ($student['major'] ?? ''));
if ($major !== '') {
    $programDisplay .= ' - ' . $major;
}
if ($programDisplay === '') {
    $programDisplay = 'No Program';
}

if ($programId <= 0) {
    echo json_encode([
        'success' => true,
        'student' => [
            'full_name' => (string) ($student['full_name'] ?? ''),
            'examinee_number' => (string) ($student['examinee_number'] ?? ''),
            'program_display' => $programDisplay
        ],
        'ranking' => [
            'pool_label' => 'N/A',
            'rank_display' => 'Not Available',
            'outside_capacity' => null,
            'message' => 'No assigned ranking program.'
        ]
    ]);
    exit;
}

$cutoffSql = "
    SELECT
        cutoff_score,
        absorptive_capacity,
        regular_percentage,
        etg_percentage,
        COALESCE(endorsement_capacity, 0) AS endorsement_capacity
    FROM tbl_program_cutoff
    WHERE program_id = ?
    ORDER BY cutoff_id DESC
    LIMIT 1
";
$stmtCutoff = $conn->prepare($cutoffSql);
$cutoff = null;
if ($stmtCutoff) {
    $stmtCutoff->bind_param('i', $programId);
    $stmtCutoff->execute();
    $cutoff = $stmtCutoff->get_result()->fetch_assoc();
    $stmtCutoff->close();
}

$programCutoff = ($cutoff && $cutoff['cutoff_score'] !== null) ? (int) $cutoff['cutoff_score'] : null;
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffMin = isset($globalSatCutoffState['min']) ? (int) $globalSatCutoffState['min'] : null;
$globalSatCutoffMax = isset($globalSatCutoffState['max']) ? (int) $globalSatCutoffState['max'] : null;
$globalSatCutoffActive = (bool) ($globalSatCutoffState['active'] ?? false);
$effectiveCutoff = get_effective_sat_cutoff(
    $programCutoff,
    $globalSatCutoffActive,
    $globalSatCutoffMin
);

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
        CASE
            WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 1
            ELSE 0
        END AS classification_group
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
      AND si.status = 'active'
      AND si.final_score IS NOT NULL
      {$cutoffWhereSql}
    ORDER BY
      classification_group ASC,
      si.final_score DESC,
      pr.sat_score DESC,
      pr.full_name ASC,
      si.examinee_number ASC
";

$stmtRanking = $conn->prepare($rankingSql);
if (!$stmtRanking) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (ranking query).'
    ]);
    exit;
}

if ($globalSatCutoffActive) {
    $stmtRanking->bind_param('iii', $programId, $globalSatCutoffMin, $globalSatCutoffMax);
} elseif ($effectiveCutoff !== null) {
    $stmtRanking->bind_param('ii', $programId, $effectiveCutoff);
} else {
    $stmtRanking->bind_param('i', $programId);
}
$stmtRanking->execute();
$rankingResult = $stmtRanking->get_result();

$allRegularRows = [];
$allEtgRows = [];
$allRowsByInterviewId = [];
while ($rankingRow = $rankingResult->fetch_assoc()) {
    $mapped = [
        'interview_id' => (int) ($rankingRow['interview_id'] ?? 0),
        'examinee_number' => (string) ($rankingRow['examinee_number'] ?? ''),
        'full_name' => (string) ($rankingRow['full_name'] ?? ''),
        'sat_score' => (int) ($rankingRow['sat_score'] ?? 0),
        'final_score' => (float) ($rankingRow['final_score'] ?? 0),
        'classification_group' => (int) ($rankingRow['classification_group'] ?? 0)
    ];

    if ($mapped['interview_id'] <= 0) {
        continue;
    }

    $allRowsByInterviewId[$mapped['interview_id']] = $mapped;
    if ($mapped['classification_group'] === 1) {
        $allEtgRows[] = $mapped;
    } else {
        $allRegularRows[] = $mapped;
    }
}
$stmtRanking->close();

$endorsementRowsRaw = load_program_endorsements($conn, $programId);
$endorsementRows = [];
$endorsementIds = [];
foreach ($endorsementRowsRaw as $endorsementRow) {
    $eid = (int) ($endorsementRow['interview_id'] ?? 0);
    if ($eid <= 0 || !isset($allRowsByInterviewId[$eid])) {
        continue;
    }

    $endorsementRows[] = $allRowsByInterviewId[$eid];
    $endorsementIds[$eid] = true;
}

$filteredRegularRows = array_values(array_filter($allRegularRows, static function (array $row) use ($endorsementIds): bool {
    return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
}));
$filteredEtgRows = array_values(array_filter($allEtgRows, static function (array $row) use ($endorsementIds): bool {
    return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
}));

$quotaEnabled = false;
$regularSlots = null;
$etgSlots = null;
$endorsementCapacity = max(0, (int) (($cutoff['endorsement_capacity'] ?? 0)));
if (
    $cutoff &&
    $cutoff['absorptive_capacity'] !== null &&
    $cutoff['regular_percentage'] !== null &&
    $cutoff['etg_percentage'] !== null
) {
    $absorptiveCapacity = max(0, (int) $cutoff['absorptive_capacity']);
    $regularPercentage = round((float) $cutoff['regular_percentage'], 2);
    $etgPercentage = round((float) $cutoff['etg_percentage'], 2);
    $baseCapacity = max(0, $absorptiveCapacity - $endorsementCapacity);

    if (
        $regularPercentage >= 0 &&
        $regularPercentage <= 100 &&
        $etgPercentage >= 0 &&
        $etgPercentage <= 100 &&
        abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
    ) {
        $quotaEnabled = true;
        $regularSlots = max(0, (int) round($baseCapacity * ($regularPercentage / 100)));
        $etgSlots = max(0, $baseCapacity - $regularSlots);
    }
}

$orderedRegular = [];
if ($quotaEnabled && $regularSlots !== null) {
    $sccInRegularSlots = min(count($endorsementRows), $regularSlots, $endorsementCapacity);
    $regularInsideSlots = max(0, $regularSlots - $sccInRegularSlots);
    $regularInsideRows = array_slice($filteredRegularRows, 0, $regularInsideSlots);
    $regularOutsideRows = array_slice($filteredRegularRows, $regularInsideSlots);

    foreach ($regularInsideRows as $row) {
        $orderedRegular[] = [
            'interview_id' => (int) $row['interview_id'],
            'outside' => false,
            'is_scc' => false
        ];
    }

    foreach ($endorsementRows as $idx => $row) {
        $rankPosition = count($orderedRegular) + 1;
        $outside = ($rankPosition > $regularSlots) || ($idx >= $endorsementCapacity);
        $orderedRegular[] = [
            'interview_id' => (int) $row['interview_id'],
            'outside' => $outside,
            'is_scc' => true
        ];
    }

    foreach ($regularOutsideRows as $row) {
        $rankPosition = count($orderedRegular) + 1;
        $orderedRegular[] = [
            'interview_id' => (int) $row['interview_id'],
            'outside' => ($rankPosition > $regularSlots),
            'is_scc' => false
        ];
    }
} else {
    foreach ($filteredRegularRows as $row) {
        $orderedRegular[] = [
            'interview_id' => (int) $row['interview_id'],
            'outside' => false,
            'is_scc' => false
        ];
    }
    foreach ($endorsementRows as $row) {
        $orderedRegular[] = [
            'interview_id' => (int) $row['interview_id'],
            'outside' => false,
            'is_scc' => true
        ];
    }
}

$rankValue = null;
$rankTotal = 0;
$poolLabel = 'Regular + SCC';
$outsideCapacity = null;

foreach ($orderedRegular as $idx => $entry) {
    if ((int) $entry['interview_id'] === $interviewId) {
        $rankValue = $idx + 1;
        $rankTotal = count($orderedRegular);
        $poolLabel = 'Regular + SCC';
        $outsideCapacity = (bool) $entry['outside'];
        break;
    }
}

if ($rankValue === null) {
    foreach ($filteredEtgRows as $idx => $row) {
        if ((int) ($row['interview_id'] ?? 0) !== $interviewId) {
            continue;
        }

        $rankValue = $idx + 1;
        $rankTotal = count($filteredEtgRows);
        $poolLabel = 'ETG';
        if ($quotaEnabled && $etgSlots !== null) {
            $outsideCapacity = ($idx >= $etgSlots);
        } else {
            $outsideCapacity = null;
        }
        break;
    }
}

$rankingMessage = null;
if ($student['final_score'] === null) {
    $rankingMessage = 'Interview is unscored.';
} elseif (!isset($allRowsByInterviewId[$interviewId])) {
    $rankingMessage = ($globalSatCutoffActive || $effectiveCutoff !== null)
        ? 'Student is currently outside the active SAT cutoff filter range.'
        : 'Student is not included in current ranking list.';
}

$rankDisplay = ($rankValue !== null && $rankTotal > 0)
    ? (number_format($rankValue) . '/' . number_format($rankTotal))
    : ($rankingMessage ?? 'Not Available');

echo json_encode([
    'success' => true,
    'student' => [
        'interview_id' => (int) $student['interview_id'],
        'examinee_number' => (string) ($student['examinee_number'] ?? ''),
        'full_name' => strtoupper(trim((string) ($student['full_name'] ?? ''))),
        'program_display' => $programDisplay
    ],
    'ranking' => [
        'pool_label' => $poolLabel,
        'rank' => $rankValue,
        'total' => $rankTotal,
        'rank_display' => $rankDisplay,
        'outside_capacity' => $outsideCapacity,
        'message' => $rankingMessage
    ]
]);
exit;
