<?php
/**
 * ============================================================================
 * root_folder/interview/progchair/fetch_students.php
 * Fetch students (Eligibility Filtering + Infinite Scroll + Search + Count)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json');


// ======================================================
// STEP 0 – BASIC GUARD (Program Chair Only)
// ======================================================

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['program_id'])
) {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$assignedProgramId = (int) $_SESSION['program_id'];


// ======================================================
// STEP 1 – GET PROGRAM CUTOFF
// ======================================================

$cutoffSql = "
    SELECT cutoff_score
    FROM tbl_program_cutoff
    WHERE program_id = ?
    LIMIT 1
";

$stmtCutoff = $conn->prepare($cutoffSql);
if (!$stmtCutoff) {
    echo json_encode([
        'success' => false,
        'message' => 'SQL prepare failed (STEP 1)',
        'error'   => $conn->error
    ]);
    exit;
}

$stmtCutoff->bind_param("i", $assignedProgramId);
$stmtCutoff->execute();
$cutoffResult = $stmtCutoff->get_result();

// If no cutoff set → program not active → no students visible
if ($cutoffResult->num_rows === 0) {
    echo json_encode([
        'success' => true,
        'data' => [],
        'total' => 0
    ]);
    exit;
}

$cutoffRow = $cutoffResult->fetch_assoc();
$programCutoff = (int) $cutoffRow['cutoff_score'];


// ======================================================
// STEP 2 – GET LATEST UPLOAD BATCH
// ======================================================

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
        'data' => [],
        'total' => 0
    ]);
    exit;
}

$activeBatchId = $batchResult->fetch_assoc()['upload_batch_id'];


// ======================================================
// STEP 3 – PAGINATION SETTINGS
// ======================================================

$limit  = 20;
$page   = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page   = max($page, 1);
$offset = ($page - 1) * $limit;


// ======================================================
// STEP 4 – SEARCH FILTER
// ======================================================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$searchLike = '%' . $search . '%';


// ======================================================
// STEP 5 – COUNT TOTAL ELIGIBLE STUDENTS
// ======================================================

    $countSql = "
        SELECT COUNT(*) AS total
        FROM tbl_placement_results pr
        LEFT JOIN tbl_student_interview si
            ON pr.examinee_number = si.examinee_number
        WHERE pr.upload_batch_id = ?
          AND pr.sat_score >= ?
          AND si.examinee_number IS NULL
          AND (
                pr.full_name LIKE ?
                OR pr.examinee_number LIKE ?
              )
    ";


$stmtCount = $conn->prepare($countSql);
if (!$stmtCount) {
    echo json_encode([
        'success' => false,
        'message' => 'SQL prepare failed (STEP 5)',
        'error'   => $conn->error
    ]);
    exit;
}

$stmtCount->bind_param(
    "siss",
    $activeBatchId,
    $programCutoff,
    $searchLike,
    $searchLike
);

$stmtCount->execute();
$countResult = $stmtCount->get_result();
$totalQualified = (int) $countResult->fetch_assoc()['total'];


// ======================================================
// STEP 6 – FETCH ELIGIBLE STUDENTS (PAGINATED)
// ======================================================

$sql = "
    SELECT
        pr.id AS placement_result_id,
        pr.examinee_number,
        pr.full_name,
        pr.sat_score,
        pr.qualitative_text
    FROM tbl_placement_results pr
    LEFT JOIN tbl_student_interview si
        ON pr.examinee_number = si.examinee_number
    WHERE pr.upload_batch_id = ?
      AND pr.sat_score >= ?
      AND si.examinee_number IS NULL
      AND (
            pr.full_name LIKE ?
            OR pr.examinee_number LIKE ?
          )
    ORDER BY pr.sat_score DESC
    LIMIT ? OFFSET ?
";


$stmt = $conn->prepare($sql);
if (!$stmt) {
    echo json_encode([
        'success' => false,
        'message' => 'SQL prepare failed (STEP 6)',
        'error'   => $conn->error
    ]);
    exit;
}
$stmt->bind_param(
    "sissii",
    $activeBatchId,
    $programCutoff,
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


// ======================================================
// STEP 7 – RETURN JSON RESPONSE
// ======================================================

echo json_encode([
    'success' => true,
    'data' => $students,
    'total' => $totalQualified
]);

exit;
