<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../config/student_credentials.php';
require_once '../config/admin_student_management.php';
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

ensure_student_credentials_table($conn);
admin_student_management_ensure_transfer_history_table($conn);

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
if (strlen($query) > 100) {
    $query = substr($query, 0, 100);
}

$totalRecords = 0;
$totalStmt = $conn->prepare("SELECT COUNT(*) AS total_records FROM tbl_placement_results");
if ($totalStmt) {
    $totalStmt->execute();
    $totalResult = $totalStmt->get_result();
    if ($totalResult) {
        $totalRow = $totalResult->fetch_assoc();
        $totalRecords = (int) ($totalRow['total_records'] ?? 0);
    }
    $totalStmt->close();
}

if ($query === '') {
    echo json_encode([
        'success' => true,
        'query' => $query,
        'total_records' => $totalRecords,
        'filtered_records' => $totalRecords,
        'returned_records' => 0,
        'rows' => []
    ]);
    exit;
}

$like = '%' . $query . '%';
$esmConditionSql = build_admin_search_esm_preferred_program_condition_sql('pr.preferred_program');

$filteredRecords = 0;
$countSql = "
    SELECT COUNT(*) AS filtered_records
    FROM tbl_placement_results pr
    WHERE (
            pr.examinee_number LIKE ?
         OR pr.full_name LIKE ?
         OR pr.preferred_program LIKE ?
    )
";
$countStmt = $conn->prepare($countSql);
if ($countStmt) {
    $countStmt->bind_param('sss', $like, $like, $like);
    $countStmt->execute();
    $countResult = $countStmt->get_result();
    if ($countResult) {
        $countRow = $countResult->fetch_assoc();
        $filteredRecords = (int) ($countRow['filtered_records'] ?? 0);
    }
    $countStmt->close();
}

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
        ix.final_score,
        ixd.interview_id,
        ixd.interview_datetime,
        ixd.classification AS interview_classification,
        ixd.mobile_number,
        ixd.first_choice AS first_choice_id,
        ixd.second_choice AS second_choice_id,
        ixd.third_choice AS third_choice_id,
        COALESCE(interviewer.acc_fullname, '') AS interviewer_name,
        COALESCE(icam.campus_name, '') AS interview_campus_name,
        COALESCE(track.track, '') AS shs_track_name,
        COALESCE(etg.class_desc, '') AS etg_class_name,
        COALESCE(current_program.program_code, '') AS current_program_code,
        COALESCE(current_program.program_name, '') AS current_program_name,
        COALESCE(current_program.major, '') AS current_program_major,
        COALESCE(sc.credential_id, 0) AS credential_id,
        COALESCE(sc.status, '') AS credential_status,
        COALESCE(sc.must_change_password, 0) AS must_change_password,
        COALESCE(transfer_stats.pending_transfer_count, 0) AS pending_transfer_count,
        TRIM(CONCAT(
            COALESCE(p1.program_code, ''),
            CASE
                WHEN p1.program_code IS NOT NULL AND p1.program_code <> ''
                     AND p1.program_name IS NOT NULL AND p1.program_name <> ''
                    THEN ' - '
                ELSE ''
            END,
            COALESCE(p1.program_name, ''),
            CASE
                WHEN p1.major IS NOT NULL AND p1.major <> ''
                    THEN CONCAT(' (', p1.major, ')')
                ELSE ''
            END
        )) AS first_choice_label,
        TRIM(CONCAT(
            COALESCE(p2.program_code, ''),
            CASE
                WHEN p2.program_code IS NOT NULL AND p2.program_code <> ''
                     AND p2.program_name IS NOT NULL AND p2.program_name <> ''
                    THEN ' - '
                ELSE ''
            END,
            COALESCE(p2.program_name, ''),
            CASE
                WHEN p2.major IS NOT NULL AND p2.major <> ''
                    THEN CONCAT(' (', p2.major, ')')
                ELSE ''
            END
        )) AS second_choice_label,
        TRIM(CONCAT(
            COALESCE(p3.program_code, ''),
            CASE
                WHEN p3.program_code IS NOT NULL AND p3.program_code <> ''
                     AND p3.program_name IS NOT NULL AND p3.program_name <> ''
                    THEN ' - '
                ELSE ''
            END,
            COALESCE(p3.program_name, ''),
            CASE
                WHEN p3.major IS NOT NULL AND p3.major <> ''
                    THEN CONCAT(' (', p3.major, ')')
                ELSE ''
            END
        )) AS third_choice_label
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
    LEFT JOIN (
        SELECT si_active.*
        FROM tbl_student_interview si_active
        INNER JOIN (
            SELECT
                placement_result_id,
                MAX(interview_id) AS latest_interview_id
            FROM tbl_student_interview
            WHERE status = 'active'
            GROUP BY placement_result_id
        ) latest
            ON latest.latest_interview_id = si_active.interview_id
    ) ixd
        ON ixd.placement_result_id = pr.id
    LEFT JOIN tblaccount interviewer
        ON interviewer.accountid = ixd.program_chair_id
    LEFT JOIN tbl_program current_program
        ON current_program.program_id = COALESCE(NULLIF(ixd.program_id, 0), NULLIF(ixd.first_choice, 0))
    LEFT JOIN tbl_campus icam
        ON icam.campus_id = ixd.campus_id
    LEFT JOIN tb_ltrack track
        ON track.trackid = ixd.shs_track_id
    LEFT JOIN tbl_etg_class etg
        ON etg.etgclassid = ixd.etg_class_id
    LEFT JOIN tbl_student_credentials sc
        ON sc.placement_result_id = pr.id
    LEFT JOIN (
        SELECT interview_id, COUNT(*) AS pending_transfer_count
        FROM tbl_student_transfer_history
        WHERE status = 'pending'
        GROUP BY interview_id
    ) transfer_stats
        ON transfer_stats.interview_id = ixd.interview_id
    LEFT JOIN tbl_program p1
        ON p1.program_id = ixd.first_choice
    LEFT JOIN tbl_program p2
        ON p2.program_id = ixd.second_choice
    LEFT JOIN tbl_program p3
        ON p3.program_id = ixd.third_choice
    WHERE (
            pr.examinee_number LIKE ?
         OR pr.full_name LIKE ?
         OR pr.preferred_program LIKE ?
    )
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

$stmt->bind_param('sss', $like, $like, $like);
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
        'interview_id' => (int) ($row['interview_id'] ?? 0),
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
        'final_score' => $row['final_score'],
        'interview_datetime' => (string) ($row['interview_datetime'] ?? ''),
        'interviewer_name' => (string) ($row['interviewer_name'] ?? ''),
        'interview_classification' => (string) ($row['interview_classification'] ?? ''),
        'mobile_number' => (string) ($row['mobile_number'] ?? ''),
        'interview_campus_name' => (string) ($row['interview_campus_name'] ?? ''),
        'shs_track_name' => (string) ($row['shs_track_name'] ?? ''),
        'etg_class_name' => (string) ($row['etg_class_name'] ?? ''),
        'current_program_label' => trim(implode(' ', array_filter([
            trim((string) ($row['current_program_code'] ?? '')),
            trim((string) ($row['current_program_name'] ?? '')) !== ''
                ? ('- ' . trim((string) ($row['current_program_name'] ?? '')))
                : '',
            trim((string) ($row['current_program_major'] ?? '')) !== ''
                ? ('(' . trim((string) ($row['current_program_major'] ?? '')) . ')')
                : '',
        ]))),
        'credential_id' => (int) ($row['credential_id'] ?? 0),
        'credential_status' => (string) ($row['credential_status'] ?? ''),
        'must_change_password' => ((int) ($row['must_change_password'] ?? 0) === 1),
        'pending_transfer_count' => (int) ($row['pending_transfer_count'] ?? 0),
        'first_choice_id' => (int) ($row['first_choice_id'] ?? 0),
        'second_choice_id' => (int) ($row['second_choice_id'] ?? 0),
        'third_choice_id' => (int) ($row['third_choice_id'] ?? 0),
        'first_choice_label' => trim((string) ($row['first_choice_label'] ?? '')),
        'second_choice_label' => trim((string) ($row['second_choice_label'] ?? '')),
        'third_choice_label' => trim((string) ($row['third_choice_label'] ?? ''))
    ];
}

$stmt->close();

echo json_encode([
    'success' => true,
    'query' => $query,
    'total_records' => $totalRecords,
    'filtered_records' => $filteredRecords,
    'returned_records' => count($rows),
    'rows' => $rows
]);
