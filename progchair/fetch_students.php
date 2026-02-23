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
    http_response_code(403);
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
    error_log('SQL prepare failed (STEP 1): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load students'
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
// STEP 2B – BATCH-LEVEL TOTALS (for dashboard badge clarity)
// ======================================================

$uploadedTotal = 0;
$qualifiedByCutoffTotal = 0;

$batchTotalsSql = "
    SELECT
        COUNT(*) AS uploaded_total,
        SUM(CASE WHEN sat_score >= ? THEN 1 ELSE 0 END) AS qualified_total
    FROM tbl_placement_results
    WHERE upload_batch_id = ?
";

$stmtBatchTotals = $conn->prepare($batchTotalsSql);
if ($stmtBatchTotals) {
    $stmtBatchTotals->bind_param("is", $programCutoff, $activeBatchId);
    $stmtBatchTotals->execute();
    $batchTotalsRow = $stmtBatchTotals->get_result()->fetch_assoc();
    if ($batchTotalsRow) {
        $uploadedTotal = (int) ($batchTotalsRow['uploaded_total'] ?? 0);
        $qualifiedByCutoffTotal = (int) ($batchTotalsRow['qualified_total'] ?? 0);
    }
}


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

$search = isset($_GET['search']) ? trim((string) $_GET['search']) : '';
$searchFilterSql = '';
$searchParam = '';

if ($search !== '') {
    if (preg_match('/^[0-9]+$/', $search)) {
        // Fast path for examinee lookup (uses prefix index scan).
        $searchFilterSql = " AND pr.examinee_number LIKE ? ";
        $searchParam = preg_replace('/\s+/', '', $search) . '%';
    } else {
        // Names are stored uppercase during intake.
        $searchFilterSql = " AND pr.full_name LIKE ? ";
        $searchParam = strtoupper($search) . '%';
    }
}

$ownerAction = strtolower(trim((string) ($_GET['owner_action'] ?? '')));

$allowedOwnerActions = ['pending', 'unscored', 'needs_review'];
if (!in_array($ownerAction, $allowedOwnerActions, true)) {
    $ownerAction = '';
}

$ownerActionWhere = '';
if ($ownerAction !== '') {
    switch ($ownerAction) {
        case 'pending':
            if ($assignedProgramId > 0) {
                $ownerActionWhere = "
                  AND si.status = 'active'
                  AND EXISTS (
                        SELECT 1
                        FROM tbl_student_transfer_history th_owner
                        WHERE th_owner.interview_id = si.interview_id
                          AND th_owner.status = 'pending'
                          AND th_owner.to_program_id = {$assignedProgramId}
                        LIMIT 1
                  )
                ";
            }
            break;

        case 'unscored':
            if ($assignedProgramId > 0) {
                $ownerActionWhere = "
                  AND si.first_choice = {$assignedProgramId}
                  AND si.status = 'active'
                  AND si.final_score IS NULL
                ";
            }
            break;

        case 'needs_review':
            if ($assignedProgramId > 0) {
                $ownerActionWhere = "
                  AND si.first_choice = {$assignedProgramId}
                  AND si.status = 'active'
                  AND si.final_score IS NOT NULL
                  AND NOT EXISTS (
                        SELECT 1
                        FROM tbl_student_transfer_history th_owner
                        WHERE th_owner.interview_id = si.interview_id
                          AND th_owner.status = 'pending'
                        LIMIT 1
                  )
                ";
            }
            break;
    }
}


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
          {$searchFilterSql}
          {$ownerActionWhere}
    ";


$stmtCount = $conn->prepare($countSql);
if (!$stmtCount) {
    error_log('SQL prepare failed (STEP 5): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load students'
    ]);
    exit;
}

if ($searchFilterSql !== '') {
    $stmtCount->bind_param(
        "sis",
        $activeBatchId,
        $programCutoff,
        $searchParam
    );
} else {
    $stmtCount->bind_param(
        "si",
        $activeBatchId,
        $programCutoff
    );
}

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
        pr.qualitative_text,
        si.interview_id,
        si.first_choice,
        si.program_chair_id,
        si.final_score,

        -- TRANSFER FIELDS
        th.transfer_id,
        th.to_program_id,
        th.status AS transfer_status,
        EXISTS (
            SELECT 1
            FROM tbl_student_transfer_history th_any
            WHERE th_any.interview_id = si.interview_id
              AND th_any.status = 'pending'
            LIMIT 1
        ) AS has_pending_transfer

    FROM tbl_placement_results pr

    LEFT JOIN tbl_student_interview si
        ON pr.examinee_number = si.examinee_number

    LEFT JOIN tbl_student_transfer_history th
        ON si.interview_id = th.interview_id
        AND th.status = 'pending'
        AND th.to_program_id = ?

    WHERE pr.upload_batch_id = ?
      AND pr.sat_score >= ?
      {$searchFilterSql}
      {$ownerActionWhere}

    ORDER BY pr.sat_score DESC
    LIMIT ? OFFSET ?
";



$stmt = $conn->prepare($sql);
if (!$stmt) {
    error_log('SQL prepare failed (STEP 6): ' . $conn->error);
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load students'
    ]);
    exit;
}
if ($searchFilterSql !== '') {
    $stmt->bind_param(
        "isisii",
        $assignedProgramId,
        $activeBatchId,
        $programCutoff,
        $searchParam,
        $limit,
        $offset
    );
} else {
    $stmt->bind_param(
        "isiii",
        $assignedProgramId,
        $activeBatchId,
        $programCutoff,
        $limit,
        $offset
    );
}

$stmt->execute();
$result = $stmt->get_result();

/**
 * ============================================================
 * STEP 6B – BUILD RESPONSE ROWS
 * File: root_folder/interview/progchair/fetch_students.php
 * ============================================================
 */
$students = [];

while ($row = $result->fetch_assoc()) {

    // Flag: does interview already exist?
    $row['has_interview'] = !empty($row['interview_id']);

    // Flag: can current program chair edit?
    $row['can_edit'] = (
        !empty($row['interview_id']) &&
        !empty($row['first_choice']) &&
        (int)$row['first_choice'] === $assignedProgramId
    );

    // NEW: pending transfer exists
    $row['transfer_pending'] = (
        !empty($row['transfer_id']) &&
        $row['transfer_status'] === 'pending'
    );

    // Flag: any pending transfer exists for this interview (for owner UI state)
    $row['has_pending_transfer'] = ((int) ($row['has_pending_transfer'] ?? 0) === 1);

    $students[] = $row;
}





// ======================================================
// STEP 7 – RETURN JSON RESPONSE
// ======================================================

echo json_encode([
    'success' => true,
    'data' => $students,
    'total' => $totalQualified,
    'uploaded_total' => $uploadedTotal,
    'qualified_total' => $qualifiedByCutoffTotal
]);

exit;
