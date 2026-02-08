<?php
require_once '../../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

$accountid   = $_POST['accountid']   ?? null;
$fullname    = trim($_POST['acc_fullname'] ?? '');
$email       = trim($_POST['email'] ?? '');
$role        = $_POST['role'] ?? '';
$campus_id   = $_POST['campus_id'] ?: null;
$program_id  = $_POST['program_id'] ?: null;
$status      = $_POST['status'] ?? '';

if (!$accountid || !$fullname || !$email || !$role || !$status) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields'
    ]);
    exit;
}

/* Prevent duplicate email */
$check = $conn->prepare("
    SELECT accountid 
    FROM tblaccount 
    WHERE email = ? AND accountid != ?
    LIMIT 1
");
$check->bind_param('si', $email, $accountid);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Email is already in use'
    ]);
    exit;
}

$stmt = $conn->prepare("
    UPDATE tblaccount
    SET
      acc_fullname = ?,
      email        = ?,
      role         = ?,
      campus_id    = ?,
      program_id   = ?,
      status       = ?
    WHERE accountid = ?
");

$stmt->bind_param(
    'sssissi',
    $fullname,
    $email,
    $role,
    $campus_id,
    $program_id,
    $status,
    $accountid
);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Account updated successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update account'
    ]);
}
