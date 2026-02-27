<?php
/**
 * Toggle Program Endorsement (EC) for ranked students.
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

$accountId = (int) $_SESSION['accountid'];
$campusId = (int) $_SESSION['campus_id'];

$programId = isset($_POST['program_id']) ? (int) $_POST['program_id'] : 0;
$interviewId = isset($_POST['interview_id']) ? (int) $_POST['interview_id'] : 0;
$action = strtoupper(trim((string) ($_POST['action'] ?? 'ADD')));

if ($programId <= 0 || $interviewId <= 0 || !in_array($action, ['ADD', 'REMOVE'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request payload.'
    ]);
    exit;
}

ensure_program_endorsement_table($conn);

// Validate program ownership/campus and pull quota rules.
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

$stmtProgram->bind_param("ii", $programId, $campusId);
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

$endorsementCapacity = max(0, (int) ($program['endorsement_capacity'] ?? 0));
$programCutoff = $program['cutoff_score'] !== null ? (int) $program['cutoff_score'] : null;
$globalCutoffState = get_global_sat_cutoff_state($conn);
$globalCutoffMin = isset($globalCutoffState['min']) ? (int) $globalCutoffState['min'] : null;
$globalCutoffMax = isset($globalCutoffState['max']) ? (int) $globalCutoffState['max'] : null;
$globalCutoffActive = (bool) ($globalCutoffState['active'] ?? false);
$effectiveCutoff = get_effective_sat_cutoff($programCutoff, $globalCutoffActive, $globalCutoffMin);

$quotaEnabled = false;
$regularSlots = null;
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

// Validate interview row belongs to selected program ranking pool and is scored.
$studentSql = "
    SELECT
        si.interview_id,
        si.classification,
        pr.sat_score
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    WHERE si.interview_id = ?
      AND si.status = 'active'
      AND si.final_score IS NOT NULL
      AND COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
    LIMIT 1
";

$stmtStudent = $conn->prepare($studentSql);
if (!$stmtStudent) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (student validation).'
    ]);
    exit;
}

$stmtStudent->bind_param("ii", $interviewId, $programId);
$stmtStudent->execute();
$student = $stmtStudent->get_result()->fetch_assoc();
$stmtStudent->close();

if (!$student) {
    echo json_encode([
        'success' => false,
        'message' => 'Student is not in this program ranking list.'
    ]);
    exit;
}

if ($action === 'ADD') {
    if ($endorsementCapacity <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'SCC capacity is 0. Configure SCC capacity first.'
        ]);
        exit;
    }

    $classification = strtoupper(trim((string) ($student['classification'] ?? 'REGULAR')));
    if ($classification !== 'REGULAR') {
        echo json_encode([
            'success' => false,
            'message' => 'Only Regular students can be added to SCC from this action.'
        ]);
        exit;
    }
    $satScore = (int) ($student['sat_score'] ?? 0);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM tbl_program_endorsements
        WHERE program_id = ?
    ";
    $stmtCount = $conn->prepare($countSql);
    if (!$stmtCount) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error (endorsement count).'
        ]);
        exit;
    }

    $stmtCount->bind_param("i", $programId);
    $stmtCount->execute();
    $currentEndorsed = (int) (($stmtCount->get_result()->fetch_assoc()['total'] ?? 0));
    $stmtCount->close();

    // If already endorsed, keep idempotent success.
    $existsSql = "
        SELECT endorsement_id
        FROM tbl_program_endorsements
        WHERE program_id = ?
          AND interview_id = ?
        LIMIT 1
    ";
    $stmtExists = $conn->prepare($existsSql);
    if ($stmtExists) {
        $stmtExists->bind_param("ii", $programId, $interviewId);
        $stmtExists->execute();
        $alreadyExists = $stmtExists->get_result()->num_rows > 0;
        $stmtExists->close();
        if ($alreadyExists) {
            $syncChoiceSql = "
                UPDATE tbl_student_interview
                SET first_choice = ?
                WHERE interview_id = ?
                LIMIT 1
            ";
            $stmtSyncChoice = $conn->prepare($syncChoiceSql);
            if ($stmtSyncChoice) {
                $stmtSyncChoice->bind_param("ii", $programId, $interviewId);
                $stmtSyncChoice->execute();
                $stmtSyncChoice->close();
            }

            echo json_encode([
                'success' => true,
                'message' => 'Student is already endorsed and synced to first choice.'
            ]);
            exit;
        }
    }

    $isInRegularRankingList = false;
    if ($quotaEnabled) {
        $regularSlotsCount = max(0, (int) $regularSlots);
        $sccInRegularSlots = min($currentEndorsed, $regularSlotsCount, $endorsementCapacity);
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
            if ($globalCutoffActive) {
                $rankedSql .= " AND pr_rank.sat_score BETWEEN ? AND ? ";
            } elseif ($effectiveCutoff !== null) {
                $rankedSql .= " AND pr_rank.sat_score >= ? ";
            }
            $rankedSql .= "
                ORDER BY
                    si_rank.final_score DESC,
                    pr_rank.sat_score DESC,
                    pr_rank.full_name ASC
                LIMIT ?
            ";

            $stmtRanked = $conn->prepare($rankedSql);
            if (!$stmtRanked) {
                http_response_code(500);
                echo json_encode([
                    'success' => false,
                    'message' => 'Server error (ranked regular validation).'
                ]);
                exit;
            }

            if ($globalCutoffActive) {
                $stmtRanked->bind_param("iiiii", $programId, $programId, $globalCutoffMin, $globalCutoffMax, $limit);
            } elseif ($effectiveCutoff !== null) {
                $stmtRanked->bind_param("iiii", $programId, $programId, $effectiveCutoff, $limit);
            } else {
                $stmtRanked->bind_param("iii", $programId, $programId, $limit);
            }
            $stmtRanked->execute();
            $rankedResult = $stmtRanked->get_result();
            while ($rankedRow = $rankedResult->fetch_assoc()) {
                if ((int) ($rankedRow['interview_id'] ?? 0) === $interviewId) {
                    $isInRegularRankingList = true;
                    break;
                }
            }
            $stmtRanked->close();
        }
    } else {
        if ($globalCutoffActive) {
            $isInRegularRankingList = ($satScore >= $globalCutoffMin && $satScore <= $globalCutoffMax);
        } elseif ($effectiveCutoff === null) {
            $isInRegularRankingList = true;
        } else {
            $isInRegularRankingList = $satScore >= $effectiveCutoff;
        }
    }

    if ($isInRegularRankingList) {
        echo json_encode([
            'success' => false,
            'message' => 'Student is already covered by the regular ranking list. SCC is for outside-ranked regular cases.'
        ]);
        exit;
    }

    if ($currentEndorsed >= $endorsementCapacity) {
        echo json_encode([
            'success' => false,
            'message' => 'SCC capacity is full.'
        ]);
        exit;
    }

    $insertSql = "
        INSERT INTO tbl_program_endorsements (
            program_id,
            interview_id,
            endorsed_by
        )
        VALUES (?, ?, ?)
    ";
    $stmtInsert = $conn->prepare($insertSql);
    if (!$stmtInsert) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error (add endorsement).'
        ]);
        exit;
    }

    $stmtInsert->bind_param("iii", $programId, $interviewId, $accountId);
    $ok = $stmtInsert->execute();
    $stmtInsert->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to add endorsement.'
        ]);
        exit;
    }

    $syncChoiceSql = "
        UPDATE tbl_student_interview
        SET first_choice = ?
        WHERE interview_id = ?
        LIMIT 1
    ";
    $stmtSyncChoice = $conn->prepare($syncChoiceSql);
    if ($stmtSyncChoice) {
        $stmtSyncChoice->bind_param("ii", $programId, $interviewId);
        $stmtSyncChoice->execute();
        $stmtSyncChoice->close();
    }

    echo json_encode([
        'success' => true,
        'message' => 'Student added to SCC list and set as first choice.'
    ]);
    exit;
}

// REMOVE
$deleteSql = "
    DELETE FROM tbl_program_endorsements
    WHERE program_id = ?
      AND interview_id = ?
";
$stmtDelete = $conn->prepare($deleteSql);
if (!$stmtDelete) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (remove endorsement).'
    ]);
    exit;
}

$stmtDelete->bind_param("ii", $programId, $interviewId);
$stmtDelete->execute();
$stmtDelete->close();

echo json_encode([
    'success' => true,
    'message' => 'Student removed from SCC list.'
]);
exit;
