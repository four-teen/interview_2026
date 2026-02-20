<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/get_owner_action_details.php
 * PURPOSE: Return owner/incoming action details for sidebar action cards
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid']) ||
    empty($_SESSION['program_id'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized'
    ]);
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$programId = (int) $_SESSION['program_id'];
$action = strtolower(trim((string) ($_GET['action'] ?? '')));

$allowedActions = ['pending', 'unscored', 'needs_review'];
if (!in_array($action, $allowedActions, true)) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid action'
    ]);
    exit;
}

if ($action === 'pending') {
    $sql = "
        SELECT
            t.transfer_id,
            t.interview_id,
            pr.examinee_number,
            pr.full_name,
            pr.sat_score,
            si.classification,
            ec.class_desc AS etg_class_name,
            si.final_score,
            si.interview_datetime,
            CONCAT(
                COALESCE(p_from.program_name, ''),
                CASE
                    WHEN TRIM(COALESCE(p_from.major, '')) = '' THEN ''
                    ELSE CONCAT(' - ', p_from.major)
                END
            ) AS from_program,
            t.transfer_datetime AS action_datetime,
            a.acc_fullname AS actor_name,
            t.remarks
        FROM tbl_student_transfer_history t
        INNER JOIN tbl_student_interview si
            ON si.interview_id = t.interview_id
        INNER JOIN tbl_placement_results pr
            ON si.placement_result_id = pr.id
        LEFT JOIN tbl_etg_class ec
            ON si.etg_class_id = ec.etgclassid
        LEFT JOIN tbl_program p_from
            ON t.from_program_id = p_from.program_id
        LEFT JOIN tblaccount a
            ON t.transferred_by = a.accountid
        WHERE t.status = 'pending'
          AND t.to_program_id = ?
          AND si.status = 'active'
        ORDER BY t.transfer_datetime DESC, t.transfer_id DESC
        LIMIT 250
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load action details'
        ]);
        exit;
    }

    $stmt->bind_param("i", $programId);
} elseif ($action === 'unscored') {
    $sql = "
        SELECT
            NULL AS transfer_id,
            si.interview_id,
            pr.examinee_number,
            pr.full_name,
            pr.sat_score,
            si.classification,
            ec.class_desc AS etg_class_name,
            si.final_score,
            si.interview_datetime,
            NULL AS from_program,
            si.interview_datetime AS action_datetime,
            NULL AS actor_name,
            NULL AS remarks
        FROM tbl_student_interview si
        INNER JOIN tbl_placement_results pr
            ON si.placement_result_id = pr.id
        LEFT JOIN tbl_etg_class ec
            ON si.etg_class_id = ec.etgclassid
        WHERE si.status = 'active'
          AND si.program_chair_id = ?
          AND si.final_score IS NULL
        ORDER BY si.interview_datetime DESC, si.interview_id DESC
        LIMIT 250
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load action details'
        ]);
        exit;
    }

    $stmt->bind_param("i", $accountId);
} else {
    $sql = "
        SELECT
            NULL AS transfer_id,
            si.interview_id,
            pr.examinee_number,
            pr.full_name,
            pr.sat_score,
            si.classification,
            ec.class_desc AS etg_class_name,
            si.final_score,
            si.interview_datetime,
            NULL AS from_program,
            si.interview_datetime AS action_datetime,
            NULL AS actor_name,
            NULL AS remarks
        FROM tbl_student_interview si
        INNER JOIN tbl_placement_results pr
            ON si.placement_result_id = pr.id
        LEFT JOIN tbl_etg_class ec
            ON si.etg_class_id = ec.etgclassid
        WHERE si.status = 'active'
          AND si.program_chair_id = ?
          AND si.final_score IS NOT NULL
          AND NOT EXISTS (
                SELECT 1
                FROM tbl_student_transfer_history t
                WHERE t.interview_id = si.interview_id
                  AND t.status = 'pending'
          )
        ORDER BY si.final_score DESC, pr.sat_score DESC, pr.full_name ASC
        LIMIT 250
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to load action details'
        ]);
        exit;
    }

    $stmt->bind_param("i", $accountId);
}

$stmt->execute();
$result = $stmt->get_result();

$rows = [];
while ($row = $result->fetch_assoc()) {
    $classification = strtoupper((string) ($row['classification'] ?? 'REGULAR'));
    $etgClassName = trim((string) ($row['etg_class_name'] ?? ''));

    $classificationLabel = 'REGULAR';
    if ($classification === 'ETG') {
        $classificationLabel = 'ETG-' . ($etgClassName !== '' ? strtoupper($etgClassName) : 'UNSPECIFIED');
    }

    $rows[] = [
        'transfer_id' => $row['transfer_id'] !== null ? (int) $row['transfer_id'] : null,
        'interview_id' => (int) $row['interview_id'],
        'examinee_number' => $row['examinee_number'],
        'full_name' => $row['full_name'],
        'sat_score' => $row['sat_score'] !== null ? (int) $row['sat_score'] : null,
        'classification' => $classificationLabel,
        'final_score' => $row['final_score'] !== null ? number_format((float) $row['final_score'], 2) : null,
        'action_datetime' => $row['action_datetime'],
        'from_program' => $row['from_program'],
        'actor_name' => $row['actor_name'],
        'remarks' => $row['remarks']
    ];
}

$titleMap = [
    'pending' => 'Pending Transfers',
    'unscored' => 'Unscored',
    'needs_review' => 'Needs Review'
];

echo json_encode([
    'success' => true,
    'action' => $action,
    'title' => $titleMap[$action] ?? 'Action Details',
    'rows' => $rows
]);

exit;
