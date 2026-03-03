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

function build_admin_search_esm_preferred_program_condition_sql(string $columnExpression): string
{
    $normalizedColumn = "UPPER(COALESCE({$columnExpression}, ''))";
    $patterns = [
        '%NURSING%',
        '%MIDWIFERY%',
        '%MEDICAL TECHNOLOGY%',
        '%ELECTRONICS ENGINEERING%',
        '%CIVIL ENGINEERING%',
        '%COMPUTER ENGINEERING%',
        '%COMPUTER SCIENCE%',
        '%FISHERIES%',
        '%BIOLOGY%',
        '%ACCOUNTANCY%',
        '%MANAGEMENT ACCOUNTING%',
        '%ACCOUNTING INFORMATION SYSTEMS%',
        '%SECONDARY EDUCATION%MATHEMATICS%',
        '%MATHEMATICS EDUCATION%',
        '%SECONDARY EDUCATION%SCIENCE%',
        '%SCIENCE EDUCATION%'
    ];

    $conditions = [];
    foreach ($patterns as $pattern) {
        $conditions[] = "{$normalizedColumn} LIKE '{$pattern}'";
    }

    return '(' . implode(' OR ', $conditions) . ')';
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
$esmConditionSql = build_admin_search_esm_preferred_program_condition_sql('pr.preferred_program');

$sql = "
    SELECT
        pr.id AS placement_result_id,
        pr.examinee_number,
        pr.full_name,
        pr.sat_score,
        pr.qualitative_text,
        pr.preferred_program,
        pr.esm_competency_standard_score,
        pr.overall_standard_score,
        CASE
            WHEN {$esmConditionSql} THEN 'ESM'
            ELSE 'Overall'
        END AS basis_label,
        COALESCE(ix.has_interview, 0) AS has_interview,
        COALESCE(ix.has_score, 0) AS has_score,
        ix.final_score
    FROM tbl_placement_results pr
    LEFT JOIN (
        SELECT
            placement_result_id,
            MAX(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS has_interview,
            MAX(CASE WHEN status = 'active' AND final_score IS NOT NULL THEN 1 ELSE 0 END) AS has_score,
            MAX(CASE WHEN status = 'active' THEN final_score ELSE NULL END) AS final_score
        FROM tbl_student_interview
        GROUP BY placement_result_id
    ) ix
        ON ix.placement_result_id = pr.id
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
    $hasInterview = ((int) ($row['has_interview'] ?? 0) === 1);
    $hasScore = ((int) ($row['has_score'] ?? 0) === 1);
    $statusLabel = 'No Interview';
    if ($hasScore) {
        $statusLabel = 'Scored';
    } elseif ($hasInterview) {
        $statusLabel = 'Pending Score';
    }

    $rows[] = [
        'placement_result_id' => (int) ($row['placement_result_id'] ?? 0),
        'examinee_number' => (string) ($row['examinee_number'] ?? ''),
        'full_name' => (string) ($row['full_name'] ?? ''),
        'sat_score' => $row['sat_score'],
        'qualitative_text' => (string) ($row['qualitative_text'] ?? ''),
        'preferred_program' => (string) ($row['preferred_program'] ?? ''),
        'basis_label' => (string) ($row['basis_label'] ?? 'Overall'),
        'esm_competency_standard_score' => $row['esm_competency_standard_score'],
        'overall_standard_score' => $row['overall_standard_score'],
        'has_interview' => $hasInterview,
        'has_score' => $hasScore,
        'status_label' => $statusLabel,
        'final_score' => $row['final_score']
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'query' => $query,
    'rows' => $rows
]);
