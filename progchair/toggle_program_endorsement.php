<?php
/**
 * Toggle Program Endorsement (EC) for ranked students.
 */

require_once '../config/db.php';
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

$cutoffScore = $program['cutoff_score'] !== null ? (int) $program['cutoff_score'] : null;
$endorsementCapacity = max(0, (int) ($program['endorsement_capacity'] ?? 0));

// Validate interview row belongs to selected program ranking pool and has scored SAT.
$studentSql = "
    SELECT
        si.interview_id,
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

$satScore = (int) ($student['sat_score'] ?? 0);

if ($action === 'ADD') {
    if ($endorsementCapacity <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'EC capacity is 0. Configure EC capacity first.'
        ]);
        exit;
    }

    if ($cutoffScore !== null && $satScore < $cutoffScore) {
        echo json_encode([
            'success' => false,
            'message' => 'Student SAT score is below the program cutoff.'
        ]);
        exit;
    }

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

    if ($currentEndorsed >= $endorsementCapacity) {
        echo json_encode([
            'success' => false,
            'message' => 'EC capacity is full.'
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
        'message' => 'Student added to EC list and set as first choice.'
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
    'message' => 'Student removed from EC list.'
]);
exit;
