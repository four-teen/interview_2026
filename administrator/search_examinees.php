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
    LEFT JOIN tbl_campus icam
        ON icam.campus_id = ixd.campus_id
    LEFT JOIN tb_ltrack track
        ON track.trackid = ixd.shs_track_id
    LEFT JOIN tbl_etg_class etg
        ON etg.etgclassid = ixd.etg_class_id
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
    'rows' => $rows
]);
