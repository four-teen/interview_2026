<?php
require_once '../config/admin_student_impersonation.php';

secure_session_start();

if (!admin_student_impersonation_is_active()) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$postedCsrf = (string) ($_POST['csrf_token'] ?? '');
if (!admin_student_impersonation_verify_csrf($postedCsrf)) {
    $_SESSION['student_admin_preview_flash'] = [
        'type' => 'danger',
        'message' => 'Invalid administrator return request.',
    ];
    header('Location: index.php');
    exit;
}

$result = admin_student_impersonation_end();
header('Location: ' . (string) ($result['redirect'] ?? '../index.php'));
exit;
