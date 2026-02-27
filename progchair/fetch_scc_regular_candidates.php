<?php
/**
 * Fetch SCC (Regular) candidates for Select2 picker.
 * Candidates are taken from tbl_student_interview and exclude only students
 * that are already tagged as SCC for the selected program.
 * Rule: SCC tagging is only for students not covered by the current regular
 * ranking list.
 */

require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once 'endorsement_helpers.php';
session_start();

header('Content-Type: application/json');

if (
    !isset($_SESSION['logged_in']) ||
    ($_SESSION['role'] ?? '') !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['campus_id'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$campusId = (int) $_SESSION['campus_id'];
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
$search = trim((string) ($_GET['q'] ?? ''));
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max(1, $page);
$pageSize = 30;
$offset = ($page - 1) * $pageSize;

if ($programId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid program.'
    ]);
    exit;
}

ensure_program_endorsement_table($conn);

$programSql = "
    SELECT
        p.program_id,
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
      AND p.status = 'active'
      AND c.campus_id = ?
    LIMIT 1
";

$stmtProgram = $conn->prepare($programSql);
if (!$stmtProgram) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (program validation).'
    ]);
    exit;
}

$stmtProgram->bind_param('ii', $programId, $campusId);
$stmtProgram->execute();
$program = $stmtProgram->get_result()->fetch_assoc();
$stmtProgram->close();

if (!$program) {
    echo json_encode([
        'success' => false,
        'message' => 'Program is not accessible.'
    ]);
    exit;
}

$programCutoff = $program['cutoff_score'] !== null ? (int) $program['cutoff_score'] : null;
$globalCutoffState = get_global_sat_cutoff_state($conn);
$globalCutoffMin = isset($globalCutoffState['min']) ? (int) $globalCutoffState['min'] : null;
$globalCutoffMax = isset($globalCutoffState['max']) ? (int) $globalCutoffState['max'] : null;
$globalCutoffActive = (bool) ($globalCutoffState['active'] ?? false);
$effectiveCutoff = get_effective_sat_cutoff($programCutoff, $globalCutoffActive, $globalCutoffMin);

$endorsementCapacity = max(0, (int) ($program['endorsement_capacity'] ?? 0));
$quotaEnabled = false;
$regularSlots = null;
$currentEndorsedCount = 0;

$countSql = "
    SELECT COUNT(*) AS total
    FROM tbl_program_endorsements
    WHERE program_id = ?
";
$stmtCount = $conn->prepare($countSql);
if ($stmtCount) {
    $stmtCount->bind_param('i', $programId);
    $stmtCount->execute();
    $currentEndorsedCount = (int) (($stmtCount->get_result()->fetch_assoc()['total'] ?? 0));
    $stmtCount->close();
}

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
        $regularSlots = max(0, (int) round($baseCapacity * ($regularPercentage / 100)));
    }
}

$excludeInterviewIds = [];

if ($quotaEnabled) {
    $regularSlotsCount = max(0, (int) $regularSlots);
    $sccInRegularSlots = min($currentEndorsedCount, $regularSlotsCount, $endorsementCapacity);
    $limit = max(0, $regularSlotsCount - $sccInRegularSlots);
    if ($limit > 0) {
        $rankedSql = "
            SELECT si_rank.interview_id
            FROM tbl_student_interview si_rank
            INNER JOIN tbl_placement_results pr_rank
                ON si_rank.placement_result_id = pr_rank.id
            LEFT JOIN tbl_program_endorsements pe_rank
                ON pe_rank.program_id = ?
               AND pe_rank.interview_id = si_rank.interview_id
            WHERE COALESCE(NULLIF(si_rank.program_id, 0), NULLIF(si_rank.first_choice, 0)) = ?
              AND si_rank.status = 'active'
              AND si_rank.final_score IS NOT NULL
              AND UPPER(COALESCE(si_rank.classification, 'REGULAR')) = 'REGULAR'
              AND pe_rank.endorsement_id IS NULL
        ";

        $rankedTypes = 'ii';
        $rankedParams = [$programId, $programId];

        if ($globalCutoffActive) {
            $rankedSql .= " AND pr_rank.sat_score BETWEEN ? AND ? ";
            $rankedTypes .= 'ii';
            $rankedParams[] = $globalCutoffMin;
            $rankedParams[] = $globalCutoffMax;
        } elseif ($effectiveCutoff !== null) {
            $rankedSql .= " AND pr_rank.sat_score >= ? ";
            $rankedTypes .= 'i';
            $rankedParams[] = $effectiveCutoff;
        }

        $rankedSql .= "
            ORDER BY
                si_rank.final_score DESC,
                pr_rank.sat_score DESC,
                pr_rank.full_name ASC
            LIMIT ?
        ";
        $rankedTypes .= 'i';
        $rankedParams[] = $limit;

        $stmtRanked = $conn->prepare($rankedSql);
        if (!$stmtRanked) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Server error (ranked regular query).'
            ]);
            exit;
        }

        $rankedBindArgs = [$rankedTypes];
        foreach ($rankedParams as $idx => $value) {
            $rankedBindArgs[] = &$rankedParams[$idx];
        }

        if (!call_user_func_array([$stmtRanked, 'bind_param'], $rankedBindArgs)) {
            $stmtRanked->close();
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Server error (ranked regular bind).'
            ]);
            exit;
        }

        $stmtRanked->execute();
        $rankedResult = $stmtRanked->get_result();
        while ($rankedRow = $rankedResult->fetch_assoc()) {
            $rankedInterviewId = (int) ($rankedRow['interview_id'] ?? 0);
            if ($rankedInterviewId > 0) {
                $excludeInterviewIds[] = $rankedInterviewId;
            }
        }
        $stmtRanked->close();
    }
}

$sql = "
    SELECT
        si.interview_id,
        si.examinee_number,
        pr.full_name,
        pr.sat_score,
        si.final_score
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
      AND si.status = 'active'
      AND si.final_score IS NOT NULL
      AND UPPER(COALESCE(si.classification, 'REGULAR')) = 'REGULAR'
      AND NOT EXISTS (
          SELECT 1
          FROM tbl_program_endorsements pe
          WHERE pe.program_id = ?
            AND pe.interview_id = si.interview_id
      )
";

$types = 'ii';
$params = [$programId, $programId];

if ($quotaEnabled) {
    if (!empty($excludeInterviewIds)) {
        $placeholders = implode(',', array_fill(0, count($excludeInterviewIds), '?'));
        $sql .= " AND si.interview_id NOT IN ({$placeholders}) ";
        $types .= str_repeat('i', count($excludeInterviewIds));
        foreach ($excludeInterviewIds as $excludeId) {
            $params[] = (int) $excludeId;
        }
    }
} else {
    if ($globalCutoffActive) {
        $sql .= " AND (pr.sat_score < ? OR pr.sat_score > ?) ";
        $types .= 'ii';
        $params[] = $globalCutoffMin;
        $params[] = $globalCutoffMax;
    } elseif ($effectiveCutoff !== null) {
        $sql .= " AND pr.sat_score < ? ";
        $types .= 'i';
        $params[] = $effectiveCutoff;
    } else {
        // No quota and no cutoff means regular students are already in the ranking list.
        $sql .= " AND 1 = 0 ";
    }
}

if ($search !== '') {
    $searchLike = '%' . $search . '%';
    $sql .= " AND (si.examinee_number LIKE ? OR pr.full_name LIKE ?) ";
    $types .= 'ss';
    $params[] = $searchLike;
    $params[] = $searchLike;
}

$sql .= "
    ORDER BY
        si.final_score DESC,
        pr.sat_score DESC,
        pr.full_name ASC
    LIMIT ? OFFSET ?
";
$types .= 'ii';
$params[] = $pageSize + 1;
$params[] = $offset;

$stmtCandidates = $conn->prepare($sql);
if (!$stmtCandidates) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (candidate query).'
    ]);
    exit;
}

$bindArgs = [$types];
foreach ($params as $idx => $value) {
    $bindArgs[] = &$params[$idx];
}

if (!call_user_func_array([$stmtCandidates, 'bind_param'], $bindArgs)) {
    $stmtCandidates->close();
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (candidate bind).'
    ]);
    exit;
}

$stmtCandidates->execute();
$result = $stmtCandidates->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = $row;
}
$stmtCandidates->close();

$hasMore = count($rows) > $pageSize;
if ($hasMore) {
    array_pop($rows);
}

$results = [];
foreach ($rows as $row) {
    $interviewId = (int) ($row['interview_id'] ?? 0);
    if ($interviewId <= 0) {
        continue;
    }

    $examineeNumber = trim((string) ($row['examinee_number'] ?? ''));
    $fullName = strtoupper(trim((string) ($row['full_name'] ?? '')));
    $satScore = (int) ($row['sat_score'] ?? 0);
    $finalScore = number_format((float) ($row['final_score'] ?? 0), 2);

    $labelParts = [];
    $labelParts[] = ($examineeNumber !== '' ? $examineeNumber : 'NO EXAMINEE #');
    $labelParts[] = ($fullName !== '' ? $fullName : 'NO NAME');

    $results[] = [
        'id' => $interviewId,
        'text' => implode(' - ', [
            $labelParts[0],
            $labelParts[1] . ' | SAT: ' . $satScore . ' | SCORE: ' . $finalScore
        ])
    ];
}

echo json_encode([
    'success' => true,
    'results' => $results,
    'pagination' => [
        'more' => $hasMore
    ]
]);
exit;
