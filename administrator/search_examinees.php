<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access.'
    ]);
    exit;
}

$query = trim((string) ($_GET['q'] ?? ''));
if ($query === '') {
    echo json_encode([
        'success' => true,
        'rows' => []
    ]);
    exit;
}

if (strlen($query) > 100) {
    $query = substr($query, 0, 100);
}

$like = '%' . $query . '%';
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
$globalSatCutoffActive = ($globalSatCutoffEnabled && $globalSatCutoffValue !== null);
$cutoffWhereSql = $globalSatCutoffActive ? ' AND pr.sat_score >= ?' : '';

$sql = "
    SELECT
        pr.id AS placement_result_id,
        pr.examinee_number,
        pr.full_name,
        pr.sat_score,
        pr.qualitative_text,
        pr.preferred_program
    FROM tbl_placement_results pr
    WHERE (
            pr.examinee_number LIKE ?
         OR pr.full_name LIKE ?
         OR pr.preferred_program LIKE ?
    )
      {$cutoffWhereSql}
    ORDER BY pr.created_at DESC, pr.id DESC
    LIMIT 50
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare search query.'
    ]);
    exit;
}

if ($globalSatCutoffActive) {
    $stmt->bind_param('sssi', $like, $like, $like, $globalSatCutoffValue);
} else {
    $stmt->bind_param('sss', $like, $like, $like);
}
$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $rows[] = [
        'placement_result_id' => (int) ($row['placement_result_id'] ?? 0),
        'examinee_number' => (string) ($row['examinee_number'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'sat_score' => $row['sat_score'],
        'qualitative_text' => (string) ($row['qualitative_text'] ?? ''),
        'preferred_program' => (string) ($row['preferred_program'] ?? '')
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'query' => $query,
    'rows' => $rows
]);
