<?php
/**
 * Program Chair ranking endpoint.
 * Uses the shared ranking payload with Monitoring-style display ordering.
 */

require_once '../config/db.php';
require_once '../config/program_ranking_lock.php';
require_once '../monitoring/program_ranking_monitoring_helper.php';
session_start();

header('Content-Type: application/json');

if (
    !isset($_SESSION['logged_in']) ||
    ($_SESSION['role'] ?? '') !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['campus_id'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$campusId = (int) $_SESSION['campus_id'];
$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
if ($programId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid program'
    ]);
    exit;
}

$payload = monitoring_program_ranking_transform_payload(
    program_ranking_fetch_payload($conn, $programId, $campusId)
);
if (!($payload['success'] ?? false)) {
    $statusCode = (int) ($payload['http_status'] ?? 400);
    if ($statusCode > 0) {
        http_response_code($statusCode);
    }
    echo json_encode([
        'success' => false,
        'message' => (string) ($payload['message'] ?? 'Failed to load ranking.')
    ]);
    exit;
}

echo json_encode($payload);
exit;

