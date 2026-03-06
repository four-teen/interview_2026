<?php
require_once '../config/db.php';
require_once '../config/admin_student_impersonation.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$returnTo = admin_student_impersonation_normalize_return_to((string) ($_POST['return_to'] ?? ''));
$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
if (!admin_student_impersonation_verify_csrf($postedCsrf)) {
    admin_student_impersonation_set_flash('danger', 'Invalid student preview request.');
    header('Location: ' . $returnTo);
    exit;
}

$result = admin_student_impersonation_begin($conn, (int) ($_POST['credential_id'] ?? 0), $returnTo);
if (!$result['success']) {
    admin_student_impersonation_set_flash('danger', (string) ($result['message'] ?? 'Unable to start student preview.'));
}

header('Location: ' . (string) ($result['redirect'] ?? $returnTo));
exit;
