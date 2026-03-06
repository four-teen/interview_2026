<?php
require_once '../config/db.php';
require_once '../config/program_ranking_lock.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$interviewId = isset($_GET['interview_id']) ? (int) $_GET['interview_id'] : 0;
if ($interviewId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid interview id.'
    ]);
    exit;
}

$studentSql = "
    SELECT
        si.interview_id,
        si.examinee_number,
        si.final_score,
        COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) AS ranking_program_id,
        pr.full_name,
        p.program_name,
        p.major
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON pr.id = si.placement_result_id
    LEFT JOIN tbl_program p
        ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
    WHERE si.interview_id = ?
      AND si.status = 'active'
    LIMIT 1
";

$studentStmt = $conn->prepare($studentSql);
if (!$studentStmt) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Failed to prepare student query.'
    ]);
    exit;
}

$studentStmt->bind_param('i', $interviewId);
$studentStmt->execute();
$student = $studentStmt->get_result()->fetch_assoc();
$studentStmt->close();

if (!$student) {
    echo json_encode([
        'success' => false,
        'message' => 'Student record not found.'
    ]);
    exit;
}

$programId = (int) ($student['ranking_program_id'] ?? 0);
$programDisplay = trim((string) ($student['program_name'] ?? ''));
$major = trim((string) ($student['major'] ?? ''));
if ($major !== '') {
    $programDisplay .= ' - ' . $major;
}
if ($programDisplay === '') {
    $programDisplay = 'No Program';
}

$response = [
    'success' => true,
    'student' => [
        'full_name' => (string) ($student['full_name'] ?? ''),
        'examinee_number' => (string) ($student['examinee_number'] ?? ''),
        'program_display' => $programDisplay,
    ],
    'ranking' => [
        'pool_label' => 'N/A',
        'rank_display' => 'Not Available',
        'outside_capacity' => null,
        'message' => '',
    ],
];

if ($programId <= 0) {
    $response['ranking']['message'] = 'No assigned ranking program.';
    echo json_encode($response);
    exit;
}

if ($student['final_score'] === null) {
    $response['ranking']['message'] = 'Interview is not yet scored.';
    echo json_encode($response);
    exit;
}

$payload = program_ranking_fetch_payload($conn, $programId, null);
if (!($payload['success'] ?? false)) {
    http_response_code((int) ($payload['http_status'] ?? 500));
    echo json_encode([
        'success' => false,
        'message' => (string) ($payload['message'] ?? 'Failed to load ranking.')
    ]);
    exit;
}

$matchingRow = null;
foreach ((array) ($payload['rows'] ?? []) as $rankingRow) {
    if ((int) ($rankingRow['interview_id'] ?? 0) === $interviewId) {
        $matchingRow = $rankingRow;
        break;
    }
}

if (!$matchingRow) {
    $response['ranking']['message'] = 'Student is not in the shared ranking list.';
    echo json_encode($response);
    exit;
}

$section = program_ranking_normalize_section((string) ($matchingRow['row_section'] ?? 'regular'));
$poolLabel = 'REGULAR';
if (!empty($matchingRow['is_endorsement']) || $section === 'scc') {
    $poolLabel = 'SCC';
} elseif ($section === 'etg') {
    $poolLabel = 'ETG';
}

$resolvedRank = max(
    (int) ($matchingRow['locked_rank'] ?? 0),
    (int) ($matchingRow['rank'] ?? 0)
);
$response['ranking'] = [
    'pool_label' => $poolLabel,
    'rank_display' => $resolvedRank > 0 ? ('#' . number_format($resolvedRank)) : 'Not Ranked',
    'outside_capacity' => !empty($matchingRow['is_outside_capacity']) ? true : false,
    'message' => !empty($matchingRow['is_locked']) ? 'Locked shared rank.' : 'Shared academic rank.',
];

echo json_encode($response);
exit;
