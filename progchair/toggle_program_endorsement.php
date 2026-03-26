<?php
/**
 * Toggle Program Endorsement (EC) for ranked students.
 *
 * Administrator may add a below-cutoff Regular student to SCC by writing an
 * override_cutoff flag. Program Chair and Monitoring remain limited to the
 * existing qualified-pool rules.
 */

require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../config/program_ranking_lock.php';
require_once 'endorsement_helpers.php';
session_start();

header('Content-Type: application/json');

$role = (string) ($_SESSION['role'] ?? '');
$isProgchair = ($role === 'progchair');
$isAdministrator = ($role === 'administrator');
$isMonitoring = ($role === 'monitoring');

if (
    !isset($_SESSION['logged_in']) ||
    (!$isProgchair && !$isAdministrator && !$isMonitoring) ||
    empty($_SESSION['accountid']) ||
    ($isProgchair && empty($_SESSION['campus_id']))
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$campusId = $isProgchair ? (int) $_SESSION['campus_id'] : 0;

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
    LIMIT 1
";

if ($isProgchair) {
    $programSql = str_replace(
        "LIMIT 1",
        "  AND c.campus_id = ?\n    LIMIT 1",
        $programSql
    );
}

$stmtProgram = $conn->prepare($programSql);
if (!$stmtProgram) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Server error (program validation).'
    ]);
    exit;
}

if ($isProgchair) {
    $stmtProgram->bind_param("ii", $programId, $campusId);
} else {
    $stmtProgram->bind_param("i", $programId);
}
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

$rankingPayload = program_ranking_fetch_payload($conn, $programId, $isProgchair ? $campusId : null);
if (!($rankingPayload['success'] ?? false)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => (string) ($rankingPayload['message'] ?? 'Failed to load ranking state.')
    ]);
    exit;
}

$quota = is_array($rankingPayload['quota'] ?? null) ? $rankingPayload['quota'] : [];
$currentEndorsed = max(0, (int) ($quota['endorsement_selected'] ?? 0));

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

$lockContext = program_ranking_get_interview_lock_context($conn, $interviewId);
if ($lockContext !== null) {
    $lockedRank = (int) ($lockContext['locked_rank'] ?? 0);
    echo json_encode([
        'success' => false,
        'message' => $lockedRank > 0
            ? ('Rank #' . $lockedRank . ' is locked and SCC actions are not allowed.')
            : 'This interview is locked in ranking and SCC actions are not allowed.'
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
    $matchingRankingRow = program_ranking_find_row_by_interview_id((array) ($rankingPayload['rows'] ?? []), $interviewId);
    if ($matchingRankingRow !== null) {
        $matchingSection = program_ranking_normalize_section((string) ($matchingRankingRow['row_section'] ?? 'regular'));
        $isInRegularRankingList = ($matchingSection === 'regular' && empty($matchingRankingRow['is_outside_capacity']));
    }

    if ($isInRegularRankingList) {
        echo json_encode([
            'success' => false,
            'message' => 'Student is already covered by the regular ranking list. SCC is for outside-ranked regular cases.'
        ]);
        exit;
    }

    $requiresCutoffOverride = ($matchingRankingRow === null);
    if ($requiresCutoffOverride && !$isAdministrator) {
        echo json_encode([
            'success' => false,
            'message' => 'Only administrators can bypass the cutoff and add this student to SCC.'
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
            endorsed_by,
            override_cutoff
        )
        VALUES (?, ?, ?, ?)
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

    $overrideCutoffValue = ($isAdministrator && $requiresCutoffOverride) ? 1 : 0;
    $stmtInsert->bind_param("iiii", $programId, $interviewId, $accountId, $overrideCutoffValue);
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
        'message' => $overrideCutoffValue === 1
            ? 'Student added to SCC list by administrator cutoff override and set as first choice.'
            : 'Student added to SCC list and set as first choice.'
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
