<?php
/**
 * Monitoring ranking endpoint.
 * Uses shared academic rank data with monitoring-only display ordering.
 */

require_once '../config/db.php';
require_once 'program_ranking_monitoring_helper.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['logged_in']) ||
    (($_SESSION['role'] ?? '') !== 'monitoring') ||
    empty($_SESSION['accountid'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$programId = isset($_GET['program_id']) ? (int) $_GET['program_id'] : 0;
if ($programId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid program.'
    ]);
    exit;
}

$payload = monitoring_program_ranking_fetch_payload($conn, $programId);
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

