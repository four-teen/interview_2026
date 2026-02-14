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
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$programChairId = (int) $_SESSION['accountid'];
$campusId       = (int) $_SESSION['campus_id'];
$programId      = (int) $_SESSION['program_id'];


// ======================================================
// VALIDATE REQUIRED FIELDS
// ======================================================

$interviewId        = isset($_POST['interview_id']) ? (int) $_POST['interview_id'] : 0;
$placementResultId  = (int) ($_POST['placement_result_id'] ?? 0);
$examineeNumber     = trim($_POST['examinee_number'] ?? '');
$classification     = trim($_POST['classification'] ?? '');
$etgClassId         = $_POST['etg_class_id'] ?? null;
$mobileNumber       = trim($_POST['mobile_number'] ?? '');
$firstChoice        = (int) ($_POST['first_choice'] ?? 0);
$secondChoice       = (int) ($_POST['second_choice'] ?? 0);
$thirdChoice        = (int) ($_POST['third_choice'] ?? 0);
$shsTrackId         = (int) ($_POST['shs_track_id'] ?? 0);


// Basic validation
if (
    empty($placementResultId) ||
    empty($examineeNumber) ||
    empty($classification) ||
    empty($mobileNumber) ||
    empty($firstChoice) ||
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
        echo json_encode([
            'success' => false,
            'message' => 'SQL Prepare Failed',
            'error' => $conn->error
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
        echo json_encode([
            'success' => true,
            'mode'    => 'insert'
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Insert failed',
            'error'   => $stmt->error
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
    echo json_encode([
        'success' => false,
        'message' => 'Update failed',
        'error'   => $updateStmt->error
    ]);
}

exit;
