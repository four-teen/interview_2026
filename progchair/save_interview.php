<?php
/**
 * ============================================================================
 * root_folder/interview/progchair/save_interview.php
 * Handles Insert + Update of Student Interview (Program Chair Only)
 * ============================================================================
 */

require_once '../config/db.php';
require_once '../config/student_credentials.php';
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
$classification     = strtoupper(trim($_POST['classification'] ?? ''));
$etgClassInput      = $_POST['etg_class_id'] ?? '';
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
// CLASSIFICATION + ETG CLASS RULES
// - REGULAR: ETG class may be NONE or selected
// - ETG: ETG class is required
// ======================================================
if (!in_array($classification, ['REGULAR', 'ETG'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid classification.'
    ]);
    exit;
}

if (!preg_match('/^0\d{10}$/', $mobileNumber)) {
    echo json_encode([
        'success' => false,
        'message' => 'Mobile number must be 11 digits and start with 0.'
    ]);
    exit;
}

$etgClassId = 0;
if ($etgClassInput !== '' && $etgClassInput !== null) {
    if (!ctype_digit((string) $etgClassInput)) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid ETG classification.'
        ]);
        exit;
    }

    $etgClassId = (int) $etgClassInput;
    if ($etgClassId <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid ETG classification.'
        ]);
        exit;
    }

    $etgCheckStmt = $conn->prepare("
        SELECT etgclassid
        FROM tbl_etg_class
        WHERE etgclassid = ?
        LIMIT 1
    ");

    if (!$etgCheckStmt) {
        error_log('SQL Prepare Failed (validate etg_class_id): ' . $conn->error);
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to validate ETG classification.'
        ]);
        exit;
    }

    $etgCheckStmt->bind_param("i", $etgClassId);
    $etgCheckStmt->execute();
    $etgCheckResult = $etgCheckStmt->get_result();

    if ($etgCheckResult->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Selected ETG classification does not exist.'
        ]);
        exit;
    }
}

if ($classification === 'ETG' && $etgClassId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'ETG classification is required for ETG students.'
    ]);
    exit;
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
        VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?)
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
        "isiiisisiiii",
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

    try {
        $conn->begin_transaction();

        if (!$stmt->execute()) {
            throw new RuntimeException('Insert interview failed: ' . $stmt->error);
        }

        $newInterviewId = (int) $conn->insert_id;

        $credentialResult = provision_student_credentials(
            $conn,
            $placementResultId,
            $newInterviewId,
            $examineeNumber,
            true
        );

        if (!$credentialResult['success']) {
            throw new RuntimeException((string) ($credentialResult['message'] ?? 'Failed to provision student credentials.'));
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'mode' => 'insert',
            'interview_id' => $newInterviewId,
            'student_credentials' => [
                'username' => $examineeNumber,
                'temporary_password' => (string) ($credentialResult['temporary_code'] ?? '')
            ]
        ]);
    } catch (Throwable $e) {
        $conn->rollback();
        error_log($e->getMessage());
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
        etg_class_id   = NULLIF(?, 0),
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
    "sisiiiii",
    $classification,
    $etgClassId,
    $mobileNumber,
    $firstChoice,
    $secondChoice,
    $thirdChoice,
    $shsTrackId,
    $interviewId
);

try {
    $conn->begin_transaction();

    if (!$updateStmt->execute()) {
        throw new RuntimeException('Update interview failed: ' . $updateStmt->error);
    }

    $credentialResult = provision_student_credentials(
        $conn,
        $placementResultId,
        $interviewId,
        $examineeNumber,
        false
    );

    if (!$credentialResult['success']) {
        throw new RuntimeException((string) ($credentialResult['message'] ?? 'Failed to sync student credentials.'));
    }

    $conn->commit();

    $response = [
        'success' => true,
        'mode' => 'update',
        'interview_id' => $interviewId
    ];

    if (!empty($credentialResult['temporary_code'])) {
        $response['student_credentials'] = [
            'username' => $examineeNumber,
            'temporary_password' => (string) $credentialResult['temporary_code']
        ];
    }

    echo json_encode($response);
} catch (Throwable $e) {
    $conn->rollback();
    error_log($e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update interview'
    ]);
}

exit;
