<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/get_activity_log_drilldown.php
 * PURPOSE: Return owner-scoped audit log details for chart drill-down
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$date = trim((string) ($_GET['date'] ?? ''));
$action = strtoupper(trim((string) ($_GET['action'] ?? '')));

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || strtotime($date) === false || date('Y-m-d', strtotime($date)) !== $date) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid date'
    ]);
    exit;
}

$allowedActions = ['SCORE_SAVE', 'SCORE_UPDATE', 'FINAL_SCORE_UPDATE', 'OTHER', 'ALL'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
    exit;
}

function format_delta($oldValue, $newValue, $suffix = '')
{
    if ($newValue === null || $newValue === '') {
        return '--';
    }

    $newFormatted = number_format((float) $newValue, 2) . $suffix;
    if ($oldValue === null || $oldValue === '') {
        return $newFormatted;
    }

    $oldFormatted = number_format((float) $oldValue, 2) . $suffix;
    return $oldFormatted . ' -> ' . $newFormatted;
}

$actionLabelMap = [
    'SCORE_SAVE' => 'Score Save',
    'SCORE_UPDATE' => 'Score Update',
    'FINAL_SCORE_UPDATE' => 'Final Score Update'
];

$sql = "
    SELECT
        l.log_id,
        l.action,
        l.created_at,
        l.old_raw,
        l.new_raw,
        l.old_weighted,
        l.new_weighted,
        l.final_before,
        l.final_after,
        pr.examinee_number,
        pr.full_name,
        sc.component_name,
        p.program_name,
        p.major
    FROM tbl_score_audit_logs l
    LEFT JOIN tbl_student_interview si
        ON l.interview_id = si.interview_id
    LEFT JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    LEFT JOIN tbl_scoring_components sc
        ON l.component_id = sc.component_id
    LEFT JOIN tbl_program p
        ON si.program_id = p.program_id
    WHERE l.actor_accountid = ?
      AND DATE(l.created_at) = ?
";

if ($action === 'OTHER') {
    $sql .= " AND (l.action IS NULL OR l.action NOT IN ('SCORE_SAVE', 'SCORE_UPDATE', 'FINAL_SCORE_UPDATE'))";
} elseif ($action !== 'ALL') {
    $sql .= " AND l.action = ?";
}

$sql .= "
    ORDER BY l.created_at DESC, l.log_id DESC
    LIMIT 300
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to load activity details'
    ]);
    exit;
}

if ($action === 'OTHER' || $action === 'ALL') {
    $stmt->bind_param("is", $accountId, $date);
} else {
    $stmt->bind_param("iss", $accountId, $date, $action);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $programLabel = trim((string) ($row['program_name'] ?? ''));
    $major = trim((string) ($row['major'] ?? ''));
    if ($major !== '') {
        $programLabel = ($programLabel !== '' ? $programLabel . ' - ' : '') . $major;
    }

    $actionKey = strtoupper(trim((string) ($row['action'] ?? '')));
    $rows[] = [
        'log_id' => (int) ($row['log_id'] ?? 0),
        'timestamp' => (string) ($row['created_at'] ?? ''),
        'action' => $actionKey,
        'action_label' => $actionLabelMap[$actionKey] ?? ($actionKey !== '' ? $actionKey : 'OTHER'),
        'student_name' => !empty($row['full_name']) ? strtoupper((string) $row['full_name']) : 'N/A',
        'examinee_number' => !empty($row['examinee_number']) ? (string) $row['examinee_number'] : '--',
        'program' => $programLabel,
        'component' => !empty($row['component_name']) ? (string) $row['component_name'] : 'FINAL SCORE',
        'raw_delta' => format_delta($row['old_raw'], $row['new_raw']),
        'weighted_delta' => format_delta($row['old_weighted'], $row['new_weighted']),
        'final_delta' => format_delta($row['final_before'], $row['final_after'], '%')
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'date' => $date,
    'action' => $action,
    'rows' => $rows
]);

exit;
