<?php
require_once '../../config/db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

$accountId = (int) ($_POST['accountid'] ?? 0);
$action = trim((string) ($_POST['action'] ?? ''));

if ($accountId <= 0 || !in_array($action, ['lock', 'unlock'], true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid request'
    ]);
    exit;
}

$currentAdminId = (int) ($_SESSION['accountid'] ?? 0);
if ($accountId === $currentAdminId) {
    echo json_encode([
        'success' => false,
        'message' => 'You cannot lock or unlock your own account.'
    ]);
    exit;
}

$newStatus = ($action === 'lock') ? 'inactive' : 'active';

$stmt = $conn->prepare("
    UPDATE tblaccount
    SET status = ?
    WHERE accountid = ?
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare update query.'
    ]);
    exit;
}

$stmt->bind_param('si', $newStatus, $accountId);
$ok = $stmt->execute();
$affected = $stmt->affected_rows;
$stmt->close();

if (!$ok) {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update account status.'
    ]);
    exit;
}

if ($affected < 1) {
    echo json_encode([
        'success' => false,
        'message' => 'Account not found or status unchanged.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => ($action === 'lock')
        ? 'Account has been locked (set to inactive).'
        : 'Account has been unlocked (set to active).'
]);
