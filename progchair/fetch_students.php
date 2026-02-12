<?php
/**
 * ============================================================================
 * root_folder/interview/prograchir/fetch_students.php
 * Fetch students (infinite scroll + search)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');

// Basic guard
if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'progchair') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

// Pagination settings
$limit = 20;
$page  = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page  = max($page, 1);
$offset = ($page - 1) * $limit;

// Search
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchLike = '%' . $search . '%';

// STEP 1: Get latest batch
$batchSql = "
    SELECT upload_batch_id
    FROM tbl_placement_results
    ORDER BY created_at DESC
    LIMIT 1
";

$batchResult = $conn->query($batchSql);

if (!$batchResult || $batchResult->num_rows === 0) {
    echo json_encode([
        'success' => true,
        'data' => []
    ]);
    exit;
}

$activeBatchId = $batchResult->fetch_assoc()['upload_batch_id'];

// STEP 2: Fetch students
$sql = "
    SELECT id, examinee_number, full_name, sat_score, qualitative_text
    FROM tbl_placement_results
    WHERE upload_batch_id = ?
      AND (
            full_name LIKE ?
            OR examinee_number LIKE ?
          )
    ORDER BY id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$stmt->bind_param(
    "sssii",
    $activeBatchId,
    $searchLike,
    $searchLike,
    $limit,
    $offset
);

$stmt->execute();
$result = $stmt->get_result();

$students = [];

while ($row = $result->fetch_assoc()) {
    $students[] = $row;
}

echo json_encode([
    'success' => true,
    'data' => $students
]);

exit;
