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

$accountid = $_POST['accountid'] ?? null;

if (!$accountid) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid account ID'
    ]);
    exit;
}

/* Optional safety: prevent deleting self */
if ((int)$accountid === (int)($_SESSION['accountid'] ?? 0)) {
    echo json_encode([
        'success' => false,
        'message' => 'You cannot delete your own account'
    ]);
    exit;
}

$stmt = $conn->prepare("DELETE FROM tblaccount WHERE accountid = ?");
$stmt->bind_param('i', $accountid);

if ($stmt->execute()) {
    echo json_encode([
        'success' => true,
        'message' => 'Account deleted successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'message' => 'Failed to delete account'
    ]);
}
