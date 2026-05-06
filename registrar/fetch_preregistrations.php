<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
require_once __DIR__ . '/preregistration_list_helpers.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'registrar')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access',
    ]);
    exit;
}

if (!ensure_student_preregistration_storage($conn)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to prepare pre-registration storage.',
    ]);
    exit;
}

$filters = registrar_prereg_build_filters($_GET);
$rows = registrar_prereg_fetch_rows($conn, $filters);
$total = registrar_prereg_count_rows($conn, $filters);
$nextOffset = max(0, (int) ($filters['offset'] ?? 0)) + count($rows);

echo json_encode([
    'success' => true,
    'html' => registrar_prereg_render_rows($rows),
    'count' => count($rows),
    'total' => $total,
    'next_offset' => $nextOffset,
    'has_more' => $nextOffset < $total,
]);
