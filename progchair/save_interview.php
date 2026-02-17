<?php
/**
 * ============================================================================
 * root_folder/interview/progchair/save_interview.php
 * Handles Insert + Update of Student Interview (Program Chair Only)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

// ======================================================
// GUARD – PROGRAM CHAIR ONLY
// ======================================================
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$programChairId = (int) $_SESSION['accountid'];
$campusId       = (int) $_SESSION['campus_id'];
$programId      = (int) $_SESSION['program_id'];

if ($programId <= 0) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Account program assignment is missing.'
    ]);
    exit;
}


// ======================================================
// VALIDATE REQUIRED FIELDS
// ======================================================

$interviewId        = isset($_POST['interview_id']) ? (int) $_POST['interview_id'] : 0;
$placementResultId  = (int) ($_POST['placement_result_id'] ?? 0);
$examineeNumber     = trim($_POST['examinee_number'] ?? '');
$classification     = trim($_POST['classification'] ?? '');
$etgClassId         = $_POST['etg_class_id'] ?? null;
$mobileNumber       = trim($_POST['mobile_number'] ?? '');
$firstChoice        = $programId; // Force first choice to account's assigned program
$secondChoice       = (int) ($_POST['second_choice'] ?? 0);
$thirdChoice        = (int) ($_POST['third_choice'] ?? 0);
$shsTrackId         = (int) ($_POST['shs_track_id'] ?? 0);


// Basic validation
if (
    empty($placementResultId) ||
    empty($examineeNumber) ||
    empty($classification) ||
    empty($mobileNumber) ||
    empty($secondChoice) ||
    empty($thirdChoice) ||
    empty($shsTrackId)
) {
    echo json_encode([
        'success' => false,
        'message' => 'All required fields must be filled.'
    ]);
    exit;
}


// ======================================================
// HANDLE REGULAR → NULL ETG
// ======================================================
if ($classification === 'REGULAR') {
    $etgClassId = null;
} else {
    $etgClassId = !empty($etgClassId) ? (int) $etgClassId : null;
}


// ======================================================
// INSERT MODE
// ======================================================
if ($interviewId === 0) {

    $sql = "
        INSERT INTO tbl_student_interview (
            placement_result_id,
            examinee_number,
            program_chair_id,
            campus_id,
            program_id,
            classification,
            etg_class_id,
            mobile_number,
            first_choice,
            second_choice,
            third_choice,
            shs_track_id
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        error_log('SQL Prepare Failed (insert interview): ' . $conn->error);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save interview'
        ]);
        exit;
    }

    $stmt->bind_param(
        "isiiissiiiii",
        $placementResultId,
        $examineeNumber,
        $programChairId,
        $campusId,
        $programId,
        $classification,
        $etgClassId,
        $mobileNumber,
        $firstChoice,
        $secondChoice,
        $thirdChoice,
        $shsTrackId
    );

    if ($stmt->execute()) {
        $newInterviewId = (int) $conn->insert_id;
        echo json_encode([
            'success' => true,
            'mode'    => 'insert',
            'interview_id' => $newInterviewId
        ]);
    } else {
        error_log('Insert interview failed: ' . $stmt->error);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to save interview'
        ]);
    }

    exit;
}


// ======================================================
// UPDATE MODE (OWNER ONLY)
// ======================================================

// Ensure record belongs to this program chair
$checkSql = "
    SELECT interview_id
    FROM tbl_student_interview
    WHERE interview_id = ?
      AND program_chair_id = ?
    LIMIT 1
";

$checkStmt = $conn->prepare($checkSql);
$checkStmt->bind_param("ii", $interviewId, $programChairId);
$checkStmt->execute();
$checkResult = $checkStmt->get_result();

if ($checkResult->num_rows === 0) {
    echo json_encode([
        'success' => false,
        'message' => 'You are not allowed to edit this record.'
    ]);
    exit;
}


// Proceed update
$updateSql = "
    UPDATE tbl_student_interview
    SET
        classification = ?,
        etg_class_id   = ?,
        mobile_number  = ?,
        first_choice   = ?,
        second_choice  = ?,
        third_choice   = ?,
        shs_track_id   = ?
    WHERE interview_id = ?
    LIMIT 1
";

$updateStmt = $conn->prepare($updateSql);

if (!$updateStmt) {
    error_log('SQL Prepare Failed (update interview): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update interview'
    ]);
    exit;
}

$updateStmt->bind_param(
    "sissiiii",
    $classification,
    $etgClassId,
    $mobileNumber,
    $firstChoice,
    $secondChoice,
    $thirdChoice,
    $shsTrackId,
    $interviewId
);

if ($updateStmt->execute()) {
    echo json_encode([
        'success' => true,
        'mode'    => 'update',
        'interview_id' => $interviewId
    ]);
} else {
    error_log('Update interview failed: ' . $updateStmt->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update interview'
    ]);
}

exit;
