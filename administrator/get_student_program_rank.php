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
$effectiveCutoff = get_effective_sat_cutoff(
    $programCutoff,
    (bool) ($globalSatCutoffState['enabled'] ?? false),
    isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null
);

$cutoffWhereSql = $effectiveCutoff !== null ? ' AND pr.sat_score >= ?' : '';
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

if ($effectiveCutoff !== null) {
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

$sortRankingRows = static function (array $rows): array {
    usort($rows, static function (array $left, array $right): int {
        $leftScore = (float) ($left['final_score'] ?? 0);
        $rightScore = (float) ($right['final_score'] ?? 0);
        if ($rightScore < $leftScore) {
            return -1;
        }
        if ($rightScore > $leftScore) {
            return 1;
        }

        $leftSat = (int) ($left['sat_score'] ?? 0);
        $rightSat = (int) ($right['sat_score'] ?? 0);
        if ($rightSat !== $leftSat) {
            return $rightSat <=> $leftSat;
        }

        return strcmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
    });

    return $rows;
};

$regularRows = $sortRankingRows($filteredRegularRows);
$etgRows = $sortRankingRows($filteredEtgRows);

usort($endorsementRows, static function (array $left, array $right): int {
    $timeLeft = strtotime((string) ($left['endorsed_at'] ?? '')) ?: 0;
    $timeRight = strtotime((string) ($right['endorsed_at'] ?? '')) ?: 0;
    if ($timeLeft !== $timeRight) {
        return $timeLeft <=> $timeRight;
    }

    return strcmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
});

$splitRowsByCapacity = static function (array $rows, int $limit, bool $enabled): array {
    if (!$enabled) {
        return [
            'inside' => $rows,
            'outside' => []
        ];
    }

    $safeLimit = max(0, $limit);
    return [
        'inside' => array_slice($rows, 0, $safeLimit),
        'outside' => array_slice($rows, $safeLimit)
    ];
};

$regularLimit = max(0, (int) ($regularSlots ?? 0));
$endorsementLimit = max(0, (int) $endorsementCapacity);
$etgLimit = max(0, (int) ($etgSlots ?? 0));

$regularSplit = $splitRowsByCapacity($regularRows, $regularLimit, $quotaEnabled);
$endorsementSplit = $splitRowsByCapacity($endorsementRows, $endorsementLimit, $quotaEnabled);
$etgSplit = $splitRowsByCapacity($etgRows, $etgLimit, $quotaEnabled);

$orderedEntries = [];

foreach ($regularSplit['inside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'regular',
        'outside' => false
    ];
}

foreach ($endorsementSplit['inside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'scc',
        'outside' => false
    ];
}

foreach ($etgSplit['inside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'etg',
        'outside' => false
    ];
}

foreach ($regularSplit['outside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'regular',
        'outside' => true
    ];
}

foreach ($endorsementSplit['outside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'scc',
        'outside' => true
    ];
}

foreach ($etgSplit['outside'] as $row) {
    $orderedEntries[] = [
        'interview_id' => (int) ($row['interview_id'] ?? 0),
        'section' => 'etg',
        'outside' => true
    ];
}

$rankValue = null;
$rankTotal = 0;
$poolLabel = 'Unified Ranking';
$outsideCapacity = null;

$sectionLabels = [
    'regular' => 'Regular',
    'scc' => 'SCC',
    'etg' => 'ETG'
];

foreach ($orderedEntries as $idx => $entry) {
    if ((int) ($entry['interview_id'] ?? 0) !== $interviewId) {
        continue;
    }

    $rankValue = $idx + 1;
    $rankTotal = count($orderedEntries);
    $section = (string) ($entry['section'] ?? '');
    $poolLabel = ($sectionLabels[$section] ?? 'Unified') . ' (Unified Ranking)';
    $outsideCapacity = (bool) ($entry['outside'] ?? false);
    break;
}

$rankingMessage = null;
if ($student['final_score'] === null) {
    $rankingMessage = 'Interview is unscored.';
} elseif (!isset($allRowsByInterviewId[$interviewId])) {
    $rankingMessage = ($effectiveCutoff !== null)
        ? 'Student is currently outside the active SAT cutoff filter.'
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
