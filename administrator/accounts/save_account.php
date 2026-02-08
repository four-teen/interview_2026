<?php
/**
 * Save User Account
 * Table: tblaccount
 * Role: Administrator only
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
    http_response_code(403);
    exit('Unauthorized');
}

require_once '../../config/db.php';

/* =======================
   Validate POST
======================= */
$fullname   = trim($_POST['acc_fullname'] ?? '');
$email      = trim($_POST['email'] ?? '');
$role       = $_POST['role'] ?? 'progchair';
$campus_id  = $_POST['campus_id'] !== '' ? (int)$_POST['campus_id'] : null;
$program_id = $_POST['program_id'] !== '' ? (int)$_POST['program_id'] : null;
$status     = $_POST['status'] ?? 'active';

if ($fullname === '' || $email === '') {
    $_SESSION['error'] = 'Full name and email are required.';
    header('Location: index.php');
    exit;
}

/* =======================
   Check duplicate email
======================= */
$chk = $conn->prepare("SELECT accountid FROM tblaccount WHERE email = ?");
$chk->bind_param("s", $email);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    $chk->close();
    $_SESSION['error'] = 'Email already exists.';
    header('Location: index.php');
    exit;
}
$chk->close();

/* =======================
   Insert account
======================= */
$sql = "
  INSERT INTO tblaccount
  (
    acc_fullname,
    email,
    acc_type,
    approved,
    role,
    campus_id,
    program_id,
    status
  )
  VALUES (?, ?, ?, ?, ?, ?, ?, ?)
";

$stmt = $conn->prepare($sql);

$acc_type = 'user';   // system default
$approved = 1;        // admin-created = approved

$stmt->bind_param(
    "sssissis",
    $fullname,
    $email,
    $acc_type,
    $approved,
    $role,
    $campus_id,
    $program_id,
    $status
);

if ($stmt->execute()) {
    $_SESSION['success'] = 'Account successfully created.';
} else {
    $_SESSION['error'] = 'Failed to save account.';
}

$stmt->close();
$conn->close();

/* =======================
   Redirect back
======================= */
header('Location: index.php');
exit;
