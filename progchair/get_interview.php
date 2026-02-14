<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/get_interview.php
 * PURPOSE: Load interview record (for edit/view mode)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

// =====================================================
// 1. GUARD
// =====================================================
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

$accountId = (int) $_SESSION['accountid'];

// =====================================================
// 2. VALIDATE INPUT
// =====================================================
$placementId = isset($_GET['placement_result_id'])
    ? (int) $_GET['placement_result_id']
    : 0;

if ($placementId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit;
}

// =====================================================
// 3. FETCH INTERVIEW
// =====================================================
$sql = "
    SELECT *
    FROM tbl_student_interview
    WHERE placement_result_id = ?
    LIMIT 1
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $placementId);
$stmt->execute();
$result = $stmt->get_result();

if ($result->num_rows === 0) {
    echo json_encode([
        'success' => true,
        'exists'  => false
    ]);
    exit;
}

$interview = $result->fetch_assoc();

// =====================================================
// 4. OWNER CHECK
// =====================================================
$isOwner = ($interview['program_chair_id'] == $accountId);

// =====================================================
// 5. RETURN RESPONSE
// =====================================================
echo json_encode([
    'success'   => true,
    'exists'    => true,
    'is_owner'  => $isOwner,
    'data'      => [
        'interview_id'   => (int)$interview['interview_id'],
        'classification' => $interview['classification'],
        'etg_class_id'   => $interview['etg_class_id'],
        'mobile_number'  => $interview['mobile_number'],
        'first_choice'   => (int)$interview['first_choice'],
        'second_choice'  => (int)$interview['second_choice'],
        'third_choice'   => (int)$interview['third_choice'],
        'shs_track_id'   => (int)$interview['shs_track_id']
    ]
]);
