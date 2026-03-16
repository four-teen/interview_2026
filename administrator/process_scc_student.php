<?php
require_once '../config/db.php';
require_once '../config/session_security.php';
require_once '../config/admin_student_management.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: student_workspace.php');
    exit;
}

$placementResultId = max(0, (int) ($_POST['placement_result_id'] ?? 0));
$returnTo = admin_student_management_normalize_return_url(
    (string) ($_POST['return_to'] ?? ''),
    rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
        'placement_result_id' => $placementResultId,
    ])
);

if (!admin_student_management_verify_transfer_csrf((string) ($_POST['csrf_token'] ?? ''))) {
    admin_student_management_set_transfer_flash('danger', 'Invalid SCC security token.');
    header('Location: ' . $returnTo);
    exit;
}

$result = admin_student_management_execute_scc_action($conn, [
    'admin_account_id' => (int) ($_SESSION['accountid'] ?? 0),
    'placement_result_id' => $placementResultId,
    'interview_id' => (int) ($_POST['interview_id'] ?? 0),
    'action' => (string) ($_POST['scc_action'] ?? 'ADD'),
]);

if (!($result['success'] ?? false)) {
    admin_student_management_set_transfer_flash('danger', (string) ($result['message'] ?? 'SCC action failed.'));
    header('Location: ' . $returnTo);
    exit;
}

admin_student_management_set_transfer_flash('success', (string) ($result['message'] ?? 'SCC updated successfully.'));
header('Location: ' . $returnTo);
exit;
