<?php
require_once '../config/db.php';
require_once '../config/account_roles.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

function administrator_user_transactions_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $escapedTableName = $conn->real_escape_string($tableName);
    $result = $conn->query("SHOW TABLES LIKE '{$escapedTableName}'");
    $cache[$tableName] = $result ? ($result->num_rows > 0) : false;

    if ($result instanceof mysqli_result) {
        $result->free();
    }

    return $cache[$tableName];
}

function administrator_user_transactions_format_program_label(string $programName, string $major = ''): string
{
    $programName = trim($programName);
    $major = trim($major);

    if ($programName === '' && $major === '') {
        return '';
    }

    if ($programName !== '' && $major !== '') {
        return $programName . ' - ' . $major;
    }

    return $programName !== '' ? $programName : $major;
}

function administrator_user_transactions_format_assignment(array $user): string
{
    $campusName = trim((string) ($user['campus_name'] ?? ''));
    $programLabel = administrator_user_transactions_format_program_label(
        (string) ($user['program_name'] ?? ''),
        (string) ($user['major'] ?? '')
    );

    if ($campusName !== '' && $programLabel !== '') {
        return $campusName . ' | ' . $programLabel;
    }

    if ($campusName !== '') {
        return $campusName;
    }

    if ($programLabel !== '') {
        return $programLabel;
    }

    return 'Unassigned';
}

function administrator_user_transactions_format_datetime(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'No activity';
    }

    try {
        $date = new DateTime($raw);
        return $date->format('M d, Y g:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

function administrator_user_transactions_pick_latest_datetime(array $values): ?string
{
    $latestValue = null;
    $latestTimestamp = null;

    foreach ($values as $value) {
        $raw = trim((string) $value);
        if ($raw === '') {
            continue;
        }

        $timestamp = strtotime($raw);
        if ($timestamp === false) {
            continue;
        }

        if ($latestTimestamp === null || $timestamp > $latestTimestamp) {
            $latestTimestamp = $timestamp;
            $latestValue = $raw;
        }
    }

    return $latestValue;
}

function administrator_user_transactions_format_delta($oldValue, $newValue, string $suffix = ''): string
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

function administrator_user_transactions_source_label(array $row): string
{
    $source = (string) ($row['source'] ?? '');
    $action = strtoupper(trim((string) ($row['action'] ?? '')));

    if ($source === 'score') {
        if ($action === 'SCORE_SAVE') {
            return 'Score Save';
        }

        if ($action === 'SCORE_UPDATE') {
            return 'Score Update';
        }

        if ($action === 'FINAL_SCORE_UPDATE') {
            return 'Final Score';
        }

        return 'Score Log';
    }

    if ($source === 'transfer') {
        return 'Transfer';
    }

    if ($source === 'guidance_edit') {
        return 'Guidance Edit';
    }

    return 'Activity';
}

function administrator_user_transactions_badge_class(array $row): string
{
    $source = (string) ($row['source'] ?? '');
    $action = strtoupper(trim((string) ($row['action'] ?? '')));
    $status = strtolower(trim((string) ($row['status'] ?? '')));

    if ($source === 'score') {
        if ($action === 'SCORE_SAVE') {
            return 'bg-label-success';
        }

        if ($action === 'SCORE_UPDATE') {
            return 'bg-label-warning';
        }

        if ($action === 'FINAL_SCORE_UPDATE') {
            return 'bg-label-info';
        }

        return 'bg-label-secondary';
    }

    if ($source === 'transfer') {
        if ($status === 'approved') {
            return 'bg-label-success';
        }

        if ($status === 'rejected') {
            return 'bg-label-danger';
        }

        if ($status === 'pending') {
            return 'bg-label-warning';
        }

        return 'bg-label-secondary';
    }

    if ($source === 'guidance_edit') {
        return 'bg-label-primary';
    }

    return 'bg-label-secondary';
}

function administrator_user_transactions_student_key(array $row): string
{
    $interviewId = (int) ($row['interview_id'] ?? 0);
    if ($interviewId > 0) {
        return 'interview:' . $interviewId;
    }

    $placementResultId = (int) ($row['placement_result_id'] ?? 0);
    if ($placementResultId > 0) {
        return 'placement:' . $placementResultId;
    }

    $examineeNumber = trim((string) ($row['examinee_number'] ?? ''));
    if ($examineeNumber !== '') {
        return 'examinee:' . $examineeNumber;
    }

    return '';
}

function administrator_user_transactions_compare_users(array $left, array $right): int
{
    $transactionCompare = ((int) ($right['transaction_count'] ?? 0)) <=> ((int) ($left['transaction_count'] ?? 0));
    if ($transactionCompare !== 0) {
        return $transactionCompare;
    }

    $leftTimestamp = !empty($left['last_transaction_at']) ? (strtotime((string) $left['last_transaction_at']) ?: 0) : 0;
    $rightTimestamp = !empty($right['last_transaction_at']) ? (strtotime((string) $right['last_transaction_at']) ?: 0) : 0;
    $lastActivityCompare = $rightTimestamp <=> $leftTimestamp;
    if ($lastActivityCompare !== 0) {
        return $lastActivityCompare;
    }

    return strnatcasecmp((string) ($left['acc_fullname'] ?? ''), (string) ($right['acc_fullname'] ?? ''));
}

function administrator_user_transactions_build_page_url(array $filters, int $accountId = 0): string
{
    $query = [];

    if ($filters['q'] !== '') {
        $query['q'] = $filters['q'];
    }

    if ($filters['role'] !== '') {
        $query['role'] = $filters['role'];
    }

    if ($filters['status'] !== '') {
        $query['status'] = $filters['status'];
    }

    if ($filters['campus_id'] > 0) {
        $query['campus_id'] = $filters['campus_id'];
    }

    if ($filters['student_q'] !== '') {
        $query['student_q'] = $filters['student_q'];
    }

    if ($accountId > 0) {
        $query['account_id'] = $accountId;
    }

    if ($filters['student_id'] > 0) {
        $query['student_id'] = $filters['student_id'];
    }

    $queryString = http_build_query($query);
    return 'user_transactions.php' . ($queryString !== '' ? ('?' . $queryString) : '');
}

function administrator_user_transactions_role_label(string $role, array $roleOptions): string
{
    $role = trim($role);
    if ($role === '') {
        return 'Unknown';
    }

    return (string) ($roleOptions[$role] ?? ucwords(str_replace('_', ' ', $role)));
}

function administrator_user_transactions_compare_activity_rows(array $left, array $right): int
{
    $leftTimestamp = !empty($left['transaction_datetime']) ? (strtotime((string) $left['transaction_datetime']) ?: 0) : 0;
    $rightTimestamp = !empty($right['transaction_datetime']) ? (strtotime((string) $right['transaction_datetime']) ?: 0) : 0;

    if ($leftTimestamp !== $rightTimestamp) {
        return $rightTimestamp <=> $leftTimestamp;
    }

    return ((int) ($right['sort_id'] ?? 0)) <=> ((int) ($left['sort_id'] ?? 0));
}

function administrator_user_transactions_load_student_rows(
    mysqli $conn,
    string $searchTerm,
    int $placementResultId,
    bool $scoreTableExists,
    bool $transferTableExists,
    bool $guidanceEditTableExists,
    bool $studentCredentialsTableExists
): array {
    if ($searchTerm === '' && $placementResultId <= 0) {
        return [];
    }

    $scoreSelectSql = "0 AS score_log_count, NULL AS last_score_at";
    $scoreJoinSql = '';
    if ($scoreTableExists) {
        $scoreSelectSql = "COALESCE(score_stats.score_log_count, 0) AS score_log_count, score_stats.last_score_at";
        $scoreJoinSql = "
            LEFT JOIN (
                SELECT
                    si.placement_result_id,
                    COUNT(*) AS score_log_count,
                    MAX(l.created_at) AS last_score_at
                FROM tbl_score_audit_logs l
                INNER JOIN tbl_student_interview si
                    ON si.interview_id = l.interview_id
                GROUP BY si.placement_result_id
            ) score_stats
                ON score_stats.placement_result_id = pr.id
        ";
    }

    $transferSelectSql = "0 AS transfer_count, NULL AS last_transfer_at";
    $transferJoinSql = '';
    if ($transferTableExists) {
        $transferSelectSql = "COALESCE(transfer_stats.transfer_count, 0) AS transfer_count, transfer_stats.last_transfer_at";
        $transferJoinSql = "
            LEFT JOIN (
                SELECT
                    si.placement_result_id,
                    COUNT(*) AS transfer_count,
                    MAX(t.transfer_datetime) AS last_transfer_at
                FROM tbl_student_transfer_history t
                INNER JOIN tbl_student_interview si
                    ON si.interview_id = t.interview_id
                GROUP BY si.placement_result_id
            ) transfer_stats
                ON transfer_stats.placement_result_id = pr.id
        ";
    }

    $guidanceSelectSql = "0 AS guidance_edit_count, NULL AS last_guidance_at";
    $guidanceJoinSql = '';
    if ($guidanceEditTableExists) {
        $guidanceSelectSql = "COALESCE(guidance_stats.guidance_edit_count, 0) AS guidance_edit_count, guidance_stats.last_guidance_at";
        $guidanceJoinSql = "
            LEFT JOIN (
                SELECT
                    placement_result_id,
                    SUM(edit_count) AS guidance_edit_count,
                    MAX(last_edited_at) AS last_guidance_at
                FROM tbl_guidance_student_edit_marks
                GROUP BY placement_result_id
            ) guidance_stats
                ON guidance_stats.placement_result_id = pr.id
        ";
    }

    $credentialSelectSql = '0 AS credential_id';
    $credentialJoinSql = '';
    if ($studentCredentialsTableExists) {
        $credentialSelectSql = 'COALESCE(sc.credential_id, 0) AS credential_id';
        $credentialJoinSql = "
            LEFT JOIN tbl_student_credentials sc
                ON sc.placement_result_id = pr.id
        ";
    }

    $where = ['1=1'];
    $types = '';
    $params = [];

    if ($searchTerm !== '') {
        $where[] = '(pr.examinee_number LIKE ? OR pr.full_name LIKE ? OR pr.preferred_program LIKE ?)';
        $like = '%' . $searchTerm . '%';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    if ($placementResultId > 0) {
        $where[] = 'pr.id = ?';
        $types .= 'i';
        $params[] = $placementResultId;
    }

    $sql = "
        SELECT
            pr.id AS placement_result_id,
            pr.examinee_number,
            pr.full_name,
            pr.preferred_program,
            COALESCE(latest_si.interview_id, 0) AS interview_id,
            {$credentialSelectSql},
            COALESCE(c.campus_name, '') AS campus_name,
            COALESCE(p.program_name, '') AS program_name,
            COALESCE(p.major, '') AS major,
            {$scoreSelectSql},
            {$transferSelectSql},
            {$guidanceSelectSql}
        FROM tbl_placement_results pr
        LEFT JOIN (
            SELECT si1.*
            FROM tbl_student_interview si1
            INNER JOIN (
                SELECT placement_result_id, MAX(interview_id) AS latest_interview_id
                FROM tbl_student_interview
                GROUP BY placement_result_id
            ) latest_si_map
                ON latest_si_map.latest_interview_id = si1.interview_id
        ) latest_si
            ON latest_si.placement_result_id = pr.id
        LEFT JOIN tbl_program p
            ON p.program_id = COALESCE(NULLIF(latest_si.program_id, 0), NULLIF(latest_si.first_choice, 0))
        LEFT JOIN tbl_campus c
            ON c.campus_id = latest_si.campus_id
        {$credentialJoinSql}
        {$scoreJoinSql}
        {$transferJoinSql}
        {$guidanceJoinSql}
        WHERE " . implode(' AND ', $where) . "
        ORDER BY
            (COALESCE(score_log_count, 0) + COALESCE(transfer_count, 0) + COALESCE(guidance_edit_count, 0)) DESC,
            pr.full_name ASC,
            pr.id DESC
        LIMIT 50
    ";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

function administrator_user_transactions_resolve_transfer_actor(
    array $transferRow,
    array $roleOptions,
    array $assignedProgramMap,
    array $credentialIdMap,
    int $studentCredentialId = 0
): array {
    $transferredBy = (int) ($transferRow['transferred_by'] ?? 0);
    $actorAccountId = (int) ($transferRow['actor_accountid'] ?? 0);
    $actorName = trim((string) ($transferRow['actor_name'] ?? ''));
    $actorRole = trim((string) ($transferRow['actor_role'] ?? ''));
    $remarks = strtoupper(trim((string) ($transferRow['remarks'] ?? '')));
    $fromProgramId = (int) ($transferRow['from_program_id'] ?? 0);
    $approvedBy = (int) ($transferRow['approved_by'] ?? 0);

    if ($actorAccountId > 0 && $actorRole === 'administrator') {
        if ($approvedBy === $actorAccountId && strpos($remarks, 'ADMIN DIRECT TRANSFER') === 0) {
            return [
                'name' => ($actorName !== '' ? $actorName : ('Account #' . $actorAccountId)),
                'role_label' => administrator_user_transactions_role_label($actorRole, $roleOptions),
            ];
        }
    }

    if ($actorAccountId > 0 && $actorRole === 'progchair') {
        $assignedPrograms = $assignedProgramMap[$actorAccountId] ?? [];
        if ($fromProgramId > 0 && in_array($fromProgramId, $assignedPrograms, true)) {
            return [
                'name' => ($actorName !== '' ? $actorName : ('Account #' . $actorAccountId)),
                'role_label' => administrator_user_transactions_role_label($actorRole, $roleOptions),
            ];
        }
    }

    if ($studentCredentialId > 0 && $transferredBy === $studentCredentialId) {
        return [
            'name' => 'Student Portal',
            'role_label' => 'Student',
        ];
    }

    if (isset($credentialIdMap[$transferredBy])) {
        return [
            'name' => 'Student Portal',
            'role_label' => 'Student',
        ];
    }

    if ($actorAccountId > 0) {
        return [
            'name' => ($actorName !== '' ? $actorName : ('Account #' . $actorAccountId)),
            'role_label' => administrator_user_transactions_role_label($actorRole, $roleOptions),
        ];
    }

    return [
        'name' => 'Unknown Actor',
        'role_label' => 'Unknown',
    ];
}

function administrator_user_transactions_is_staff_transfer(
    array $transferRow,
    array $user,
    array $assignedProgramMap,
    array $credentialIdMap
): bool {
    $accountId = (int) ($user['accountid'] ?? 0);
    $transferredBy = (int) ($transferRow['transferred_by'] ?? 0);
    if ($accountId <= 0 || $accountId !== $transferredBy) {
        return false;
    }

    $role = (string) ($user['role'] ?? '');
    if ($role === 'administrator') {
        $remarks = strtoupper(trim((string) ($transferRow['remarks'] ?? '')));
        return ((int) ($transferRow['approved_by'] ?? 0) === $accountId)
            && (strpos($remarks, 'ADMIN DIRECT TRANSFER') === 0);
    }

    if ($role === 'progchair') {
        $fromProgramId = (int) ($transferRow['from_program_id'] ?? 0);
        $assignedPrograms = $assignedProgramMap[$accountId] ?? [];
        if ($fromProgramId <= 0 || !in_array($fromProgramId, $assignedPrograms, true)) {
            return false;
        }

        if (!isset($credentialIdMap[$accountId])) {
            return true;
        }

        return strtolower(trim((string) ($transferRow['status'] ?? ''))) === 'pending';
    }

    return false;
}

$roleOptions = function_exists('interview_account_role_options')
    ? interview_account_role_options()
    : [
        'administrator' => 'Administrator',
        'president' => 'President',
        'progchair' => 'Program Chair',
        'monitoring' => 'Monitoring',
        'guidance' => 'Guidance',
    ];

$search = trim((string) ($_GET['q'] ?? ''));
$roleFilter = trim((string) ($_GET['role'] ?? ''));
$roleFilter = array_key_exists($roleFilter, $roleOptions) ? $roleFilter : '';
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$statusFilter = in_array($statusFilter, ['active', 'inactive'], true) ? $statusFilter : '';
$campusFilter = max(0, (int) ($_GET['campus_id'] ?? 0));
$studentSearch = trim((string) ($_GET['student_q'] ?? ''));
$selectedAccountId = max(0, (int) ($_GET['account_id'] ?? 0));
$selectedStudentId = max(0, (int) ($_GET['student_id'] ?? 0));

$filters = [
    'q' => $search,
    'role' => $roleFilter,
    'status' => $statusFilter,
    'campus_id' => $campusFilter,
    'student_q' => $studentSearch,
    'student_id' => $selectedStudentId,
];

$campusOptions = [];
$campusOptionResult = $conn->query("
    SELECT campus_id, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC
");
if ($campusOptionResult) {
    while ($campusRow = $campusOptionResult->fetch_assoc()) {
        $campusOptions[] = $campusRow;
    }
}

$where = ['1=1'];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = '(a.acc_fullname LIKE ? OR a.email LIKE ? OR c.campus_name LIKE ? OR p.program_name LIKE ? OR p.major LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'sssss';
    array_push($params, $like, $like, $like, $like, $like);
}

if ($roleFilter !== '') {
    $where[] = 'a.role = ?';
    $types .= 's';
    $params[] = $roleFilter;
}

if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($campusFilter > 0) {
    $where[] = 'a.campus_id = ?';
    $types .= 'i';
    $params[] = $campusFilter;
}

$users = [];
$userSql = "
    SELECT
        a.accountid,
        a.acc_fullname,
        a.email,
        a.role,
        a.status,
        a.campus_id,
        a.program_id,
        c.campus_name,
        p.program_name,
        p.major
    FROM tblaccount a
    LEFT JOIN tbl_campus c
        ON c.campus_id = a.campus_id
    LEFT JOIN tbl_program p
        ON p.program_id = a.program_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.acc_fullname ASC, a.accountid ASC
";

$userStmt = $conn->prepare($userSql);
if ($userStmt) {
    if ($types !== '') {
        $userStmt->bind_param($types, ...$params);
    }

    $userStmt->execute();
    $userResult = $userStmt->get_result();
    while ($row = $userResult->fetch_assoc()) {
        $users[] = $row;
    }
    $userStmt->close();
}

$userIds = array_values(array_filter(array_map(static function (array $user): int {
    return (int) ($user['accountid'] ?? 0);
}, $users)));
$userIdListSql = !empty($userIds) ? implode(',', array_map('intval', $userIds)) : '';

$userStats = [];
$userMetaById = [];
$assignedProgramMap = [];

foreach ($users as $user) {
    $accountId = (int) ($user['accountid'] ?? 0);
    $fallbackProgramId = max(0, (int) ($user['program_id'] ?? 0));

    $userMetaById[$accountId] = $user;
    $userStats[$accountId] = [
        'score_log_count' => 0,
        'score_student_count' => 0,
        'last_score_at' => null,
        'transfer_count' => 0,
        'transfer_student_count' => 0,
        'pending_transfer_count' => 0,
        'approved_transfer_count' => 0,
        'rejected_transfer_count' => 0,
        'last_transfer_at' => null,
        'guidance_edit_count' => 0,
        'guidance_student_count' => 0,
        'last_guidance_at' => null,
    ];
    $assignedProgramMap[$accountId] = $fallbackProgramId > 0 ? [$fallbackProgramId] : [];
}

$scoreTableExists = administrator_user_transactions_table_exists($conn, 'tbl_score_audit_logs');
$transferTableExists = administrator_user_transactions_table_exists($conn, 'tbl_student_transfer_history');
$guidanceEditTableExists = administrator_user_transactions_table_exists($conn, 'tbl_guidance_student_edit_marks');
$studentCredentialsTableExists = administrator_user_transactions_table_exists($conn, 'tbl_student_credentials');
$accountProgramAssignmentsTableExists = administrator_user_transactions_table_exists($conn, 'tbl_account_program_assignments');

if ($accountProgramAssignmentsTableExists && $userIdListSql !== '') {
    $assignmentSql = "
        SELECT accountid, program_id
        FROM tbl_account_program_assignments
        WHERE status = 'active'
          AND accountid IN ({$userIdListSql})
        ORDER BY assignment_id ASC
    ";
    $assignmentResult = $conn->query($assignmentSql);
    if ($assignmentResult) {
        while ($assignmentRow = $assignmentResult->fetch_assoc()) {
            $accountId = (int) ($assignmentRow['accountid'] ?? 0);
            $programId = (int) ($assignmentRow['program_id'] ?? 0);
            if ($accountId <= 0 || $programId <= 0) {
                continue;
            }

            $assignedProgramMap[$accountId][] = $programId;
        }
    }
}

foreach ($assignedProgramMap as $accountId => $programIds) {
    $normalizedIds = [];
    foreach ($programIds as $programId) {
        $programId = (int) $programId;
        if ($programId > 0) {
            $normalizedIds[$programId] = $programId;
        }
    }
    $assignedProgramMap[$accountId] = array_values($normalizedIds);
}

$credentialIdMap = [];
if ($studentCredentialsTableExists) {
    $credentialResult = $conn->query("SELECT credential_id FROM tbl_student_credentials");
    if ($credentialResult) {
        while ($credentialRow = $credentialResult->fetch_assoc()) {
            $credentialId = (int) ($credentialRow['credential_id'] ?? 0);
            if ($credentialId > 0) {
                $credentialIdMap[$credentialId] = true;
            }
        }
    }
}

if ($scoreTableExists && $userIdListSql !== '') {
    $scoreAggregateSql = "
        SELECT
            actor_accountid AS accountid,
            COUNT(*) AS total_logs,
            COUNT(DISTINCT interview_id) AS distinct_students,
            MAX(created_at) AS last_activity
        FROM tbl_score_audit_logs
        WHERE actor_accountid IN ({$userIdListSql})
        GROUP BY actor_accountid
    ";
    $scoreAggregateResult = $conn->query($scoreAggregateSql);
    if ($scoreAggregateResult) {
        while ($scoreRow = $scoreAggregateResult->fetch_assoc()) {
            $accountId = (int) ($scoreRow['accountid'] ?? 0);
            if (!isset($userStats[$accountId])) {
                continue;
            }

            $userStats[$accountId]['score_log_count'] = (int) ($scoreRow['total_logs'] ?? 0);
            $userStats[$accountId]['score_student_count'] = (int) ($scoreRow['distinct_students'] ?? 0);
            $userStats[$accountId]['last_score_at'] = (string) ($scoreRow['last_activity'] ?? '');
        }
    }
}

if ($guidanceEditTableExists && $userIdListSql !== '') {
    $guidanceAggregateSql = "
        SELECT
            last_edited_by AS accountid,
            SUM(edit_count) AS total_edits,
            COUNT(DISTINCT placement_result_id) AS distinct_students,
            MAX(last_edited_at) AS last_activity
        FROM tbl_guidance_student_edit_marks
        WHERE last_edited_by IN ({$userIdListSql})
        GROUP BY last_edited_by
    ";
    $guidanceAggregateResult = $conn->query($guidanceAggregateSql);
    if ($guidanceAggregateResult) {
        while ($guidanceRow = $guidanceAggregateResult->fetch_assoc()) {
            $accountId = (int) ($guidanceRow['accountid'] ?? 0);
            if (!isset($userStats[$accountId])) {
                continue;
            }

            $userStats[$accountId]['guidance_edit_count'] = (int) ($guidanceRow['total_edits'] ?? 0);
            $userStats[$accountId]['guidance_student_count'] = (int) ($guidanceRow['distinct_students'] ?? 0);
            $userStats[$accountId]['last_guidance_at'] = (string) ($guidanceRow['last_activity'] ?? '');
        }
    }
}

$attributedTransferRowsByAccount = [];
if ($transferTableExists && $userIdListSql !== '') {
    $transferSql = "
        SELECT
            transfer_id,
            interview_id,
            from_program_id,
            to_program_id,
            transferred_by,
            transfer_datetime,
            remarks,
            status,
            approved_by,
            approved_datetime
        FROM tbl_student_transfer_history
        WHERE transferred_by IN ({$userIdListSql})
        ORDER BY transfer_datetime DESC, transfer_id DESC
    ";
    $transferResult = $conn->query($transferSql);
    if ($transferResult) {
        $transferStudentMap = [];

        while ($transferRow = $transferResult->fetch_assoc()) {
            $actorAccountId = (int) ($transferRow['transferred_by'] ?? 0);
            if (!isset($userMetaById[$actorAccountId])) {
                continue;
            }

            $user = $userMetaById[$actorAccountId];
            if (!administrator_user_transactions_is_staff_transfer(
                $transferRow,
                $user,
                $assignedProgramMap,
                $credentialIdMap
            )) {
                continue;
            }

            $status = strtolower(trim((string) ($transferRow['status'] ?? '')));
            $interviewId = (int) ($transferRow['interview_id'] ?? 0);

            $userStats[$actorAccountId]['transfer_count']++;
            if ($status === 'pending') {
                $userStats[$actorAccountId]['pending_transfer_count']++;
            } elseif ($status === 'approved') {
                $userStats[$actorAccountId]['approved_transfer_count']++;
            } elseif ($status === 'rejected') {
                $userStats[$actorAccountId]['rejected_transfer_count']++;
            }

            $userStats[$actorAccountId]['last_transfer_at'] = administrator_user_transactions_pick_latest_datetime([
                $userStats[$actorAccountId]['last_transfer_at'],
                (string) ($transferRow['transfer_datetime'] ?? ''),
            ]);

            if ($interviewId > 0) {
                $transferStudentMap[$actorAccountId][$interviewId] = true;
            }

            $attributedTransferRowsByAccount[$actorAccountId][] = [
                'transfer_id' => (int) ($transferRow['transfer_id'] ?? 0),
                'interview_id' => $interviewId,
                'transfer_datetime' => (string) ($transferRow['transfer_datetime'] ?? ''),
            ];
        }

        foreach ($transferStudentMap as $accountId => $interviews) {
            if (isset($userStats[$accountId])) {
                $userStats[$accountId]['transfer_student_count'] = count($interviews);
            }
        }
    }
}

foreach ($users as &$user) {
    $accountId = (int) ($user['accountid'] ?? 0);
    $stats = $userStats[$accountId] ?? [];

    $user['score_log_count'] = (int) ($stats['score_log_count'] ?? 0);
    $user['score_student_count'] = (int) ($stats['score_student_count'] ?? 0);
    $user['transfer_count'] = (int) ($stats['transfer_count'] ?? 0);
    $user['transfer_student_count'] = (int) ($stats['transfer_student_count'] ?? 0);
    $user['guidance_edit_count'] = (int) ($stats['guidance_edit_count'] ?? 0);
    $user['guidance_student_count'] = (int) ($stats['guidance_student_count'] ?? 0);
    $user['pending_transfer_count'] = (int) ($stats['pending_transfer_count'] ?? 0);
    $user['approved_transfer_count'] = (int) ($stats['approved_transfer_count'] ?? 0);
    $user['rejected_transfer_count'] = (int) ($stats['rejected_transfer_count'] ?? 0);
    $user['transaction_count'] = $user['score_log_count'] + $user['transfer_count'] + $user['guidance_edit_count'];
    $user['last_transaction_at'] = administrator_user_transactions_pick_latest_datetime([
        (string) ($stats['last_score_at'] ?? ''),
        (string) ($stats['last_transfer_at'] ?? ''),
        (string) ($stats['last_guidance_at'] ?? ''),
    ]);
}
unset($user);

usort($users, 'administrator_user_transactions_compare_users');

$selectedUser = null;
if ($selectedAccountId > 0) {
    foreach ($users as $user) {
        if ((int) ($user['accountid'] ?? 0) === $selectedAccountId) {
            $selectedUser = $user;
            break;
        }
    }
}

if ($selectedUser === null && !empty($users)) {
    $selectedUser = $users[0];
    $selectedAccountId = (int) ($selectedUser['accountid'] ?? 0);
}

$summary = [
    'users' => count($users),
    'with_activity' => 0,
    'score_logs' => 0,
    'transfers' => 0,
    'guidance_edits' => 0,
    'total_transactions' => 0,
];

foreach ($users as $user) {
    $transactionCount = (int) ($user['transaction_count'] ?? 0);
    if ($transactionCount > 0) {
        $summary['with_activity']++;
    }

    $summary['score_logs'] += (int) ($user['score_log_count'] ?? 0);
    $summary['transfers'] += (int) ($user['transfer_count'] ?? 0);
    $summary['guidance_edits'] += (int) ($user['guidance_edit_count'] ?? 0);
    $summary['total_transactions'] += $transactionCount;
}

$selectedActivityRows = [];
$selectedStudentKeyMap = [];

if ($selectedUser) {
    $selectedUserId = (int) ($selectedUser['accountid'] ?? 0);

    if ($scoreTableExists) {
        $scoreDetailSql = "
            SELECT
                l.log_id,
                l.interview_id,
                l.action,
                l.old_raw,
                l.new_raw,
                l.old_weighted,
                l.new_weighted,
                l.final_before,
                l.final_after,
                l.created_at,
                pr.id AS placement_result_id,
                pr.examinee_number,
                pr.full_name,
                p.program_name,
                p.major,
                sc.component_name
            FROM tbl_score_audit_logs l
            LEFT JOIN tbl_student_interview si
                ON si.interview_id = l.interview_id
            LEFT JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p
                ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
            LEFT JOIN tbl_scoring_components sc
                ON sc.component_id = l.component_id
            WHERE l.actor_accountid = ?
            ORDER BY l.created_at DESC, l.log_id DESC
            LIMIT 200
        ";
        $scoreDetailStmt = $conn->prepare($scoreDetailSql);
        if ($scoreDetailStmt) {
            $scoreDetailStmt->bind_param('i', $selectedUserId);
            $scoreDetailStmt->execute();
            $scoreDetailResult = $scoreDetailStmt->get_result();

            while ($scoreRow = $scoreDetailResult->fetch_assoc()) {
                $action = strtoupper(trim((string) ($scoreRow['action'] ?? '')));
                $componentName = trim((string) ($scoreRow['component_name'] ?? ''));
                $detail = $action === 'FINAL_SCORE_UPDATE'
                    ? ('Final Score: ' . administrator_user_transactions_format_delta(
                        $scoreRow['final_before'] ?? null,
                        $scoreRow['final_after'] ?? null,
                        '%'
                    ))
                    : (
                        ($componentName !== '' ? ($componentName . ' | ') : '')
                        . 'Raw: ' . administrator_user_transactions_format_delta(
                            $scoreRow['old_raw'] ?? null,
                            $scoreRow['new_raw'] ?? null
                        )
                        . ' | Weighted: ' . administrator_user_transactions_format_delta(
                            $scoreRow['old_weighted'] ?? null,
                            $scoreRow['new_weighted'] ?? null
                        )
                    );

                $selectedActivityRows[] = [
                    'source' => 'score',
                    'action' => $action,
                    'status' => '',
                    'sort_id' => (int) ($scoreRow['log_id'] ?? 0),
                    'transaction_datetime' => (string) ($scoreRow['created_at'] ?? ''),
                    'student_name' => (string) ($scoreRow['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($scoreRow['examinee_number'] ?? ''),
                    'interview_id' => (int) ($scoreRow['interview_id'] ?? 0),
                    'placement_result_id' => (int) ($scoreRow['placement_result_id'] ?? 0),
                    'program_label' => administrator_user_transactions_format_program_label(
                        (string) ($scoreRow['program_name'] ?? ''),
                        (string) ($scoreRow['major'] ?? '')
                    ),
                    'detail' => $detail,
                ];
            }

            $scoreDetailStmt->close();
        }
    }

    if ($guidanceEditTableExists) {
        $guidanceDetailSql = "
            SELECT
                gem.mark_id,
                gem.placement_result_id,
                gem.last_edited_at,
                gem.edit_count,
                pr.examinee_number,
                pr.full_name,
                pr.preferred_program
            FROM tbl_guidance_student_edit_marks gem
            LEFT JOIN tbl_placement_results pr
                ON pr.id = gem.placement_result_id
            WHERE gem.last_edited_by = ?
            ORDER BY gem.last_edited_at DESC, gem.mark_id DESC
            LIMIT 200
        ";
        $guidanceDetailStmt = $conn->prepare($guidanceDetailSql);
        if ($guidanceDetailStmt) {
            $guidanceDetailStmt->bind_param('i', $selectedUserId);
            $guidanceDetailStmt->execute();
            $guidanceDetailResult = $guidanceDetailStmt->get_result();

            while ($guidanceRow = $guidanceDetailResult->fetch_assoc()) {
                $editCount = (int) ($guidanceRow['edit_count'] ?? 0);
                $selectedActivityRows[] = [
                    'source' => 'guidance_edit',
                    'action' => 'GUIDANCE_EDIT',
                    'status' => '',
                    'sort_id' => (int) ($guidanceRow['mark_id'] ?? 0),
                    'transaction_datetime' => (string) ($guidanceRow['last_edited_at'] ?? ''),
                    'student_name' => (string) ($guidanceRow['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($guidanceRow['examinee_number'] ?? ''),
                    'interview_id' => 0,
                    'placement_result_id' => (int) ($guidanceRow['placement_result_id'] ?? 0),
                    'program_label' => trim((string) ($guidanceRow['preferred_program'] ?? '')),
                    'detail' => $editCount > 1
                        ? ('Student record edited ' . number_format($editCount) . ' times')
                        : 'Student record edited once',
                ];
            }

            $guidanceDetailStmt->close();
        }
    }

    $selectedTransferIds = array_values(array_filter(array_map(static function (array $row): int {
        return (int) ($row['transfer_id'] ?? 0);
    }, array_slice($attributedTransferRowsByAccount[$selectedUserId] ?? [], 0, 200))));

    if ($transferTableExists && !empty($selectedTransferIds)) {
        $transferIdListSql = implode(',', array_map('intval', $selectedTransferIds));
        $transferDetailSql = "
            SELECT
                t.transfer_id,
                t.interview_id,
                t.transfer_datetime,
                t.remarks,
                t.status,
                t.approved_by,
                t.approved_datetime,
                pr.id AS placement_result_id,
                pr.examinee_number,
                pr.full_name,
                p_from.program_name AS from_program_name,
                p_from.major AS from_program_major,
                p_to.program_name AS to_program_name,
                p_to.major AS to_program_major,
                approver.acc_fullname AS approved_by_name
            FROM tbl_student_transfer_history t
            LEFT JOIN tbl_student_interview si
                ON si.interview_id = t.interview_id
            LEFT JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p_from
                ON p_from.program_id = t.from_program_id
            LEFT JOIN tbl_program p_to
                ON p_to.program_id = t.to_program_id
            LEFT JOIN tblaccount approver
                ON approver.accountid = t.approved_by
            WHERE t.transfer_id IN ({$transferIdListSql})
        ";
        $transferDetailResult = $conn->query($transferDetailSql);
        if ($transferDetailResult) {
            $transferDetailMap = [];
            while ($transferRow = $transferDetailResult->fetch_assoc()) {
                $transferDetailMap[(int) ($transferRow['transfer_id'] ?? 0)] = $transferRow;
            }

            foreach ($selectedTransferIds as $transferId) {
                if (!isset($transferDetailMap[$transferId])) {
                    continue;
                }

                $transferRow = $transferDetailMap[$transferId];
                $fromProgramLabel = administrator_user_transactions_format_program_label(
                    (string) ($transferRow['from_program_name'] ?? ''),
                    (string) ($transferRow['from_program_major'] ?? '')
                );
                $toProgramLabel = administrator_user_transactions_format_program_label(
                    (string) ($transferRow['to_program_name'] ?? ''),
                    (string) ($transferRow['to_program_major'] ?? '')
                );

                $detailParts = [];
                if ($fromProgramLabel !== '' || $toProgramLabel !== '') {
                    $detailParts[] = ($fromProgramLabel !== '' ? $fromProgramLabel : 'Unspecified')
                        . ' -> '
                        . ($toProgramLabel !== '' ? $toProgramLabel : 'Unspecified');
                }

                $approvedByName = trim((string) ($transferRow['approved_by_name'] ?? ''));
                $approvedDatetime = trim((string) ($transferRow['approved_datetime'] ?? ''));
                $status = strtolower(trim((string) ($transferRow['status'] ?? '')));
                if ($status === 'approved' && $approvedByName !== '') {
                    $detailParts[] = 'Approved by ' . $approvedByName;
                } elseif ($status === 'rejected' && $approvedByName !== '') {
                    $detailParts[] = 'Rejected by ' . $approvedByName;
                } elseif ($status === 'pending') {
                    $detailParts[] = 'Awaiting action';
                }

                if ($approvedDatetime !== '' && $status !== 'pending') {
                    $detailParts[] = 'Handled ' . administrator_user_transactions_format_datetime($approvedDatetime);
                }

                $remarks = trim((string) ($transferRow['remarks'] ?? ''));
                if ($remarks !== '') {
                    $detailParts[] = $remarks;
                }

                $selectedActivityRows[] = [
                    'source' => 'transfer',
                    'action' => 'TRANSFER',
                    'status' => $status,
                    'sort_id' => (int) ($transferRow['transfer_id'] ?? 0),
                    'transaction_datetime' => (string) ($transferRow['transfer_datetime'] ?? ''),
                    'student_name' => (string) ($transferRow['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($transferRow['examinee_number'] ?? ''),
                    'interview_id' => (int) ($transferRow['interview_id'] ?? 0),
                    'placement_result_id' => (int) ($transferRow['placement_result_id'] ?? 0),
                    'program_label' => $toProgramLabel !== '' ? $toProgramLabel : $fromProgramLabel,
                    'detail' => implode(' | ', array_filter($detailParts)),
                ];
            }
        }
    }

    usort($selectedActivityRows, 'administrator_user_transactions_compare_activity_rows');

    foreach ($selectedActivityRows as $activityRow) {
        $studentKey = administrator_user_transactions_student_key($activityRow);
        if ($studentKey !== '') {
            $selectedStudentKeyMap[$studentKey] = true;
        }
    }
}

$selectedSummary = $selectedUser
    ? [
        'transactions' => (int) ($selectedUser['transaction_count'] ?? 0),
        'students' => count($selectedStudentKeyMap),
        'score_logs' => (int) ($selectedUser['score_log_count'] ?? 0),
        'transfers' => (int) ($selectedUser['transfer_count'] ?? 0),
        'guidance_edits' => (int) ($selectedUser['guidance_edit_count'] ?? 0),
        'last_transaction_at' => (string) ($selectedUser['last_transaction_at'] ?? ''),
    ]
    : null;

$studentMatches = administrator_user_transactions_load_student_rows(
    $conn,
    $studentSearch,
    ($studentSearch === '' ? $selectedStudentId : 0),
    $scoreTableExists,
    $transferTableExists,
    $guidanceEditTableExists,
    $studentCredentialsTableExists
);

$selectedStudent = null;
if ($selectedStudentId > 0) {
    foreach ($studentMatches as $studentRow) {
        if ((int) ($studentRow['placement_result_id'] ?? 0) === $selectedStudentId) {
            $selectedStudent = $studentRow;
            break;
        }
    }
}

if ($selectedStudent === null && $selectedStudentId > 0) {
    $selectedStudentRows = administrator_user_transactions_load_student_rows(
        $conn,
        '',
        $selectedStudentId,
        $scoreTableExists,
        $transferTableExists,
        $guidanceEditTableExists,
        $studentCredentialsTableExists
    );
    if (!empty($selectedStudentRows)) {
        $selectedStudent = $selectedStudentRows[0];
        if ($studentSearch === '') {
            $studentMatches = $selectedStudentRows;
        } else {
            array_unshift($studentMatches, $selectedStudent);
        }
    }
}

if ($selectedStudent === null && !empty($studentMatches)) {
    $selectedStudent = $studentMatches[0];
    $selectedStudentId = (int) ($selectedStudent['placement_result_id'] ?? 0);
    $filters['student_id'] = $selectedStudentId;
}

$selectedStudentActivityRows = [];
$selectedStudentActorMap = [];

if ($selectedStudent) {
    $selectedPlacementResultId = (int) ($selectedStudent['placement_result_id'] ?? 0);
    $selectedStudentCredentialId = (int) ($selectedStudent['credential_id'] ?? 0);

    if ($scoreTableExists) {
        $studentScoreSql = "
            SELECT
                l.log_id,
                l.interview_id,
                l.action,
                l.old_raw,
                l.new_raw,
                l.old_weighted,
                l.new_weighted,
                l.final_before,
                l.final_after,
                l.created_at,
                p.program_name,
                p.major,
                sc.component_name,
                actor.accountid AS actor_accountid,
                actor.acc_fullname AS actor_name,
                actor.role AS actor_role
            FROM tbl_score_audit_logs l
            INNER JOIN tbl_student_interview si
                ON si.interview_id = l.interview_id
            LEFT JOIN tbl_program p
                ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
            LEFT JOIN tbl_scoring_components sc
                ON sc.component_id = l.component_id
            LEFT JOIN tblaccount actor
                ON actor.accountid = l.actor_accountid
            WHERE si.placement_result_id = ?
            ORDER BY l.created_at DESC, l.log_id DESC
            LIMIT 200
        ";
        $studentScoreStmt = $conn->prepare($studentScoreSql);
        if ($studentScoreStmt) {
            $studentScoreStmt->bind_param('i', $selectedPlacementResultId);
            $studentScoreStmt->execute();
            $studentScoreResult = $studentScoreStmt->get_result();

            while ($scoreRow = $studentScoreResult->fetch_assoc()) {
                $actorAccountId = (int) ($scoreRow['actor_accountid'] ?? 0);
                $actorName = trim((string) ($scoreRow['actor_name'] ?? ''));
                $actorRole = administrator_user_transactions_role_label((string) ($scoreRow['actor_role'] ?? ''), $roleOptions);
                $action = strtoupper(trim((string) ($scoreRow['action'] ?? '')));
                $componentName = trim((string) ($scoreRow['component_name'] ?? ''));
                $detail = $action === 'FINAL_SCORE_UPDATE'
                    ? ('Final Score: ' . administrator_user_transactions_format_delta(
                        $scoreRow['final_before'] ?? null,
                        $scoreRow['final_after'] ?? null,
                        '%'
                    ))
                    : (
                        ($componentName !== '' ? ($componentName . ' | ') : '')
                        . 'Raw: ' . administrator_user_transactions_format_delta(
                            $scoreRow['old_raw'] ?? null,
                            $scoreRow['new_raw'] ?? null
                        )
                        . ' | Weighted: ' . administrator_user_transactions_format_delta(
                            $scoreRow['old_weighted'] ?? null,
                            $scoreRow['new_weighted'] ?? null
                        )
                    );

                $selectedStudentActivityRows[] = [
                    'source' => 'score',
                    'action' => $action,
                    'status' => '',
                    'sort_id' => (int) ($scoreRow['log_id'] ?? 0),
                    'transaction_datetime' => (string) ($scoreRow['created_at'] ?? ''),
                    'student_name' => (string) ($selectedStudent['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($selectedStudent['examinee_number'] ?? ''),
                    'interview_id' => (int) ($scoreRow['interview_id'] ?? 0),
                    'placement_result_id' => $selectedPlacementResultId,
                    'program_label' => administrator_user_transactions_format_program_label(
                        (string) ($scoreRow['program_name'] ?? ''),
                        (string) ($scoreRow['major'] ?? '')
                    ),
                    'detail' => $detail,
                    'actor_name' => ($actorName !== '' ? $actorName : 'Unknown User'),
                    'actor_role_label' => $actorRole,
                ];

                $actorKey = $actorAccountId > 0 ? ('account:' . $actorAccountId) : ('score:' . md5($actorName . '|' . $actorRole));
                $selectedStudentActorMap[$actorKey] = true;
            }

            $studentScoreStmt->close();
        }
    }

    if ($guidanceEditTableExists) {
        $studentGuidanceSql = "
            SELECT
                gem.mark_id,
                gem.last_edited_at,
                gem.edit_count,
                actor.accountid AS actor_accountid,
                actor.acc_fullname AS actor_name,
                actor.role AS actor_role
            FROM tbl_guidance_student_edit_marks gem
            LEFT JOIN tblaccount actor
                ON actor.accountid = gem.last_edited_by
            WHERE gem.placement_result_id = ?
            ORDER BY gem.last_edited_at DESC, gem.mark_id DESC
            LIMIT 200
        ";
        $studentGuidanceStmt = $conn->prepare($studentGuidanceSql);
        if ($studentGuidanceStmt) {
            $studentGuidanceStmt->bind_param('i', $selectedPlacementResultId);
            $studentGuidanceStmt->execute();
            $studentGuidanceResult = $studentGuidanceStmt->get_result();

            while ($guidanceRow = $studentGuidanceResult->fetch_assoc()) {
                $actorAccountId = (int) ($guidanceRow['actor_accountid'] ?? 0);
                $actorName = trim((string) ($guidanceRow['actor_name'] ?? ''));
                $actorRole = administrator_user_transactions_role_label((string) ($guidanceRow['actor_role'] ?? ''), $roleOptions);
                $editCount = (int) ($guidanceRow['edit_count'] ?? 0);

                $selectedStudentActivityRows[] = [
                    'source' => 'guidance_edit',
                    'action' => 'GUIDANCE_EDIT',
                    'status' => '',
                    'sort_id' => (int) ($guidanceRow['mark_id'] ?? 0),
                    'transaction_datetime' => (string) ($guidanceRow['last_edited_at'] ?? ''),
                    'student_name' => (string) ($selectedStudent['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($selectedStudent['examinee_number'] ?? ''),
                    'interview_id' => (int) ($selectedStudent['interview_id'] ?? 0),
                    'placement_result_id' => $selectedPlacementResultId,
                    'program_label' => trim((string) ($selectedStudent['preferred_program'] ?? '')),
                    'detail' => $editCount > 1
                        ? ('Student record edited ' . number_format($editCount) . ' times')
                        : 'Student record edited once',
                    'actor_name' => ($actorName !== '' ? $actorName : 'Unknown User'),
                    'actor_role_label' => $actorRole,
                ];

                $actorKey = $actorAccountId > 0 ? ('account:' . $actorAccountId) : ('guidance:' . md5($actorName . '|' . $actorRole));
                $selectedStudentActorMap[$actorKey] = true;
            }

            $studentGuidanceStmt->close();
        }
    }

    if ($transferTableExists) {
        $studentTransferSql = "
            SELECT
                t.transfer_id,
                t.interview_id,
                t.from_program_id,
                t.to_program_id,
                t.transferred_by,
                t.transfer_datetime,
                t.remarks,
                t.status,
                t.approved_by,
                t.approved_datetime,
                p_from.program_name AS from_program_name,
                p_from.major AS from_program_major,
                p_to.program_name AS to_program_name,
                p_to.major AS to_program_major,
                approver.acc_fullname AS approved_by_name,
                actor.accountid AS actor_accountid,
                actor.acc_fullname AS actor_name,
                actor.role AS actor_role
            FROM tbl_student_transfer_history t
            INNER JOIN tbl_student_interview si
                ON si.interview_id = t.interview_id
            LEFT JOIN tbl_program p_from
                ON p_from.program_id = t.from_program_id
            LEFT JOIN tbl_program p_to
                ON p_to.program_id = t.to_program_id
            LEFT JOIN tblaccount approver
                ON approver.accountid = t.approved_by
            LEFT JOIN tblaccount actor
                ON actor.accountid = t.transferred_by
            WHERE si.placement_result_id = ?
            ORDER BY t.transfer_datetime DESC, t.transfer_id DESC
            LIMIT 200
        ";
        $studentTransferStmt = $conn->prepare($studentTransferSql);
        if ($studentTransferStmt) {
            $studentTransferStmt->bind_param('i', $selectedPlacementResultId);
            $studentTransferStmt->execute();
            $studentTransferResult = $studentTransferStmt->get_result();

            while ($transferRow = $studentTransferResult->fetch_assoc()) {
                $fromProgramLabel = administrator_user_transactions_format_program_label(
                    (string) ($transferRow['from_program_name'] ?? ''),
                    (string) ($transferRow['from_program_major'] ?? '')
                );
                $toProgramLabel = administrator_user_transactions_format_program_label(
                    (string) ($transferRow['to_program_name'] ?? ''),
                    (string) ($transferRow['to_program_major'] ?? '')
                );

                $detailParts = [];
                if ($fromProgramLabel !== '' || $toProgramLabel !== '') {
                    $detailParts[] = ($fromProgramLabel !== '' ? $fromProgramLabel : 'Unspecified')
                        . ' -> '
                        . ($toProgramLabel !== '' ? $toProgramLabel : 'Unspecified');
                }

                $approvedByName = trim((string) ($transferRow['approved_by_name'] ?? ''));
                $approvedDatetime = trim((string) ($transferRow['approved_datetime'] ?? ''));
                $status = strtolower(trim((string) ($transferRow['status'] ?? '')));
                if ($status === 'approved' && $approvedByName !== '') {
                    $detailParts[] = 'Approved by ' . $approvedByName;
                } elseif ($status === 'rejected' && $approvedByName !== '') {
                    $detailParts[] = 'Rejected by ' . $approvedByName;
                } elseif ($status === 'pending') {
                    $detailParts[] = 'Awaiting action';
                }

                if ($approvedDatetime !== '' && $status !== 'pending') {
                    $detailParts[] = 'Handled ' . administrator_user_transactions_format_datetime($approvedDatetime);
                }

                $remarks = trim((string) ($transferRow['remarks'] ?? ''));
                if ($remarks !== '') {
                    $detailParts[] = $remarks;
                }

                $actorMeta = administrator_user_transactions_resolve_transfer_actor(
                    $transferRow,
                    $roleOptions,
                    $assignedProgramMap,
                    $credentialIdMap,
                    $selectedStudentCredentialId
                );

                $selectedStudentActivityRows[] = [
                    'source' => 'transfer',
                    'action' => 'TRANSFER',
                    'status' => $status,
                    'sort_id' => (int) ($transferRow['transfer_id'] ?? 0),
                    'transaction_datetime' => (string) ($transferRow['transfer_datetime'] ?? ''),
                    'student_name' => (string) ($selectedStudent['full_name'] ?? 'Unknown Student'),
                    'examinee_number' => (string) ($selectedStudent['examinee_number'] ?? ''),
                    'interview_id' => (int) ($transferRow['interview_id'] ?? 0),
                    'placement_result_id' => $selectedPlacementResultId,
                    'program_label' => $toProgramLabel !== '' ? $toProgramLabel : $fromProgramLabel,
                    'detail' => implode(' | ', array_filter($detailParts)),
                    'actor_name' => (string) ($actorMeta['name'] ?? 'Unknown Actor'),
                    'actor_role_label' => (string) ($actorMeta['role_label'] ?? 'Unknown'),
                ];

                $actorKey = strtolower((string) ($actorMeta['role_label'] ?? '')) . ':' . strtolower((string) ($actorMeta['name'] ?? ''));
                $selectedStudentActorMap[$actorKey] = true;
            }

            $studentTransferStmt->close();
        }
    }

    usort($selectedStudentActivityRows, 'administrator_user_transactions_compare_activity_rows');
}

$selectedStudentSummary = $selectedStudent
    ? [
        'transactions' => count($selectedStudentActivityRows),
        'actors' => count($selectedStudentActorMap),
        'score_logs' => (int) ($selectedStudent['score_log_count'] ?? 0),
        'transfers' => (int) ($selectedStudent['transfer_count'] ?? 0),
        'guidance_edits' => (int) ($selectedStudent['guidance_edit_count'] ?? 0),
        'last_transaction_at' => administrator_user_transactions_pick_latest_datetime(array_column($selectedStudentActivityRows, 'transaction_datetime')),
    ]
    : null;

$unavailableSources = [];
if (!$scoreTableExists) {
    $unavailableSources[] = 'score audit logs';
}
if (!$transferTableExists) {
    $unavailableSources[] = 'transfer history';
}
if (!$guidanceEditTableExists) {
    $unavailableSources[] = 'guidance edit marks';
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>User Transactions - Administrator</title>

    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .ut-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.95rem 1rem;
        background: #fff;
        height: 100%;
      }

      .ut-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .ut-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .ut-user-title {
        font-size: 1.1rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .ut-table td,
      .ut-table th {
        vertical-align: middle;
      }

      .ut-activity-table td,
      .ut-activity-table th {
        vertical-align: top;
      }

      .ut-activity-detail {
        min-width: 280px;
        white-space: normal;
      }

      .ut-selected-card {
        border: 1px solid #e7ecf4;
        border-radius: 1rem;
        padding: 1rem;
        background: linear-gradient(135deg, #f8faff 0%, #ffffff 100%);
      }
    </style>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'header.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <h4 class="fw-bold mb-1">
                <span class="text-muted fw-light">Monitoring /</span> User Transactions
              </h4>
              <p class="text-muted mb-4">
                Review staff-side activity by user and trace transactions for a specific student from scoring, transfers, and guidance edits.
              </p>

              <?php if (!empty($unavailableSources)): ?>
                <div class="alert alert-warning" role="alert">
                  Some transaction sources are not available in this database:
                  <?= htmlspecialchars(implode(', ', $unavailableSources)); ?>.
                </div>
              <?php endif; ?>

              <?php if ($transferTableExists): ?>
                <div class="alert alert-info" role="alert">
                  Transfer counts are intentionally conservative. Student-initiated transfers use the same numeric field as staff actors, so only staff-attributable transfer rows are shown here.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">Users In View</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['users']); ?></div>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">With Activity</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['with_activity']); ?></div>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">Score Logs</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['score_logs']); ?></div>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">Transfers</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['transfers']); ?></div>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">Guidance Edits</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['guidance_edits']); ?></div>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="ut-stat-card">
                    <div class="ut-stat-label">Total Transactions</div>
                    <div class="ut-stat-value"><?= number_format((int) $summary['total_transactions']); ?></div>
                  </div>
                </div>
              </div>

              <div class="card mb-4">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <?php if ($studentSearch !== ''): ?>
                      <input type="hidden" name="student_q" value="<?= htmlspecialchars($studentSearch); ?>" />
                    <?php endif; ?>
                    <?php if ($selectedStudentId > 0): ?>
                      <input type="hidden" name="student_id" value="<?= $selectedStudentId; ?>" />
                    <?php endif; ?>
                    <div class="col-lg-3">
                      <label class="form-label mb-1">Search</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Name, email, campus, or program"
                      />
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Role</label>
                      <select name="role" class="form-select">
                        <option value="">All Roles</option>
                        <?php foreach ($roleOptions as $roleKey => $roleLabel): ?>
                          <option value="<?= htmlspecialchars((string) $roleKey); ?>"<?= $roleFilter === $roleKey ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) $roleLabel); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Status</label>
                      <select name="status" class="form-select">
                        <option value="">All Status</option>
                        <option value="active"<?= $statusFilter === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Campus</label>
                      <select name="campus_id" class="form-select">
                        <option value="0">All Campuses</option>
                        <?php foreach ($campusOptions as $campus): ?>
                          <?php $optionCampusId = (int) ($campus['campus_id'] ?? 0); ?>
                          <option value="<?= $optionCampusId; ?>"<?= $campusFilter === $optionCampusId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($campus['campus_name'] ?? '')); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Selected User</label>
                      <select name="account_id" class="form-select">
                        <option value="0">Auto Select</option>
                        <?php foreach ($users as $user): ?>
                          <?php $optionUserId = (int) ($user['accountid'] ?? 0); ?>
                          <option value="<?= $optionUserId; ?>"<?= $selectedAccountId === $optionUserId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($user['acc_fullname'] ?? '')); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <button type="submit" class="btn btn-primary">Go</button>
                    </div>
                  </form>
                </div>
              </div>

              <div class="card mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                  <div>
                    <h5 class="mb-1">User Transaction Summary</h5>
                    <small class="text-muted">Counts are grouped by user and limited to student-related activity sources.</small>
                  </div>
                </div>
                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm ut-table">
                      <thead>
                        <tr>
                          <th>User</th>
                          <th>Role / Assignment</th>
                          <th class="text-center">Score Logs</th>
                          <th class="text-center">Transfers</th>
                          <th class="text-center">Guidance Edits</th>
                          <th class="text-center">Total</th>
                          <th>Last Activity</th>
                          <th class="text-center">View</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($users)): ?>
                          <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                              No users matched the current filters.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($users as $user): ?>
                            <?php
                              $accountId = (int) ($user['accountid'] ?? 0);
                              $roleKey = (string) ($user['role'] ?? '');
                              $roleLabel = (string) ($roleOptions[$roleKey] ?? ucwords(str_replace('_', ' ', $roleKey)));
                              $statusClass = (($user['status'] ?? 'inactive') === 'active') ? 'bg-label-success' : 'bg-label-secondary';
                              $isSelected = ($selectedAccountId === $accountId);
                              $viewUrl = administrator_user_transactions_build_page_url($filters, $accountId);
                            ?>
                            <tr<?= $isSelected ? ' class="table-primary"' : ''; ?>>
                              <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($user['acc_fullname'] ?? '')); ?></div>
                                <small class="text-muted d-block"><?= htmlspecialchars((string) ($user['email'] ?? '')); ?></small>
                                <span class="badge <?= $statusClass; ?> mt-1"><?= htmlspecialchars((string) ($user['status'] ?? 'inactive')); ?></span>
                              </td>
                              <td>
                                <div class="fw-semibold"><?= htmlspecialchars($roleLabel); ?></div>
                                <small class="text-muted"><?= htmlspecialchars(administrator_user_transactions_format_assignment($user)); ?></small>
                              </td>
                              <td class="text-center">
                                <div class="fw-semibold"><?= number_format((int) ($user['score_log_count'] ?? 0)); ?></div>
                                <small class="text-muted"><?= number_format((int) ($user['score_student_count'] ?? 0)); ?> students</small>
                              </td>
                              <td class="text-center">
                                <div class="fw-semibold"><?= number_format((int) ($user['transfer_count'] ?? 0)); ?></div>
                                <small class="text-muted"><?= number_format((int) ($user['transfer_student_count'] ?? 0)); ?> students</small>
                              </td>
                              <td class="text-center">
                                <div class="fw-semibold"><?= number_format((int) ($user['guidance_edit_count'] ?? 0)); ?></div>
                                <small class="text-muted"><?= number_format((int) ($user['guidance_student_count'] ?? 0)); ?> students</small>
                              </td>
                              <td class="text-center">
                                <span class="badge bg-label-primary"><?= number_format((int) ($user['transaction_count'] ?? 0)); ?></span>
                              </td>
                              <td><?= htmlspecialchars(administrator_user_transactions_format_datetime((string) ($user['last_transaction_at'] ?? ''))); ?></td>
                              <td class="text-center">
                                <a href="<?= htmlspecialchars($viewUrl); ?>" class="btn btn-sm <?= $isSelected ? 'btn-primary' : 'btn-outline-primary'; ?>">
                                  <?= $isSelected ? 'Selected' : 'View'; ?>
                                </a>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <h5 class="mb-1">Selected User Details</h5>
                  <small class="text-muted">Detailed timeline of student-related activity for the selected account.</small>
                </div>
                <div class="card-body">
                  <?php if (!$selectedUser): ?>
                    <div class="text-center text-muted py-4">
                      Select a user to view detailed transactions.
                    </div>
                  <?php else: ?>
                    <?php
                      $selectedRoleKey = (string) ($selectedUser['role'] ?? '');
                      $selectedRoleLabel = (string) ($roleOptions[$selectedRoleKey] ?? ucwords(str_replace('_', ' ', $selectedRoleKey)));
                    ?>
                    <div class="ut-selected-card mb-4">
                      <div class="row g-3 align-items-center">
                        <div class="col-lg-6">
                          <div class="ut-user-title"><?= htmlspecialchars((string) ($selectedUser['acc_fullname'] ?? '')); ?></div>
                          <div class="text-muted"><?= htmlspecialchars((string) ($selectedUser['email'] ?? '')); ?></div>
                        </div>
                        <div class="col-lg-6">
                          <div class="text-muted small text-uppercase fw-semibold mb-1">Assignment</div>
                          <div class="fw-semibold"><?= htmlspecialchars(administrator_user_transactions_format_assignment($selectedUser)); ?></div>
                          <small class="text-muted"><?= htmlspecialchars($selectedRoleLabel); ?></small>
                        </div>
                      </div>
                    </div>

                    <?php if ($selectedSummary): ?>
                      <div class="row g-3 mb-4">
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Transactions</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedSummary['transactions']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Students</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedSummary['students']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Score Logs</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedSummary['score_logs']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Transfers</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedSummary['transfers']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Guidance Edits</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedSummary['guidance_edits']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Last Activity</div>
                            <div class="small fw-semibold text-dark mt-2">
                              <?= htmlspecialchars(administrator_user_transactions_format_datetime($selectedSummary['last_transaction_at'])); ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                      <table class="table table-sm ut-table ut-activity-table">
                        <thead>
                          <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Student</th>
                            <th>Program</th>
                            <th>Details</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if (empty($selectedActivityRows)): ?>
                            <tr>
                              <td colspan="5" class="text-center text-muted py-4">
                                No recorded student transactions for this user.
                              </td>
                            </tr>
                          <?php else: ?>
                            <?php foreach ($selectedActivityRows as $activityRow): ?>
                              <?php
                                $badgeClass = administrator_user_transactions_badge_class($activityRow);
                                $label = administrator_user_transactions_source_label($activityRow);
                                $status = trim((string) ($activityRow['status'] ?? ''));
                              ?>
                              <tr>
                                <td class="text-nowrap">
                                  <?= htmlspecialchars(administrator_user_transactions_format_datetime((string) ($activityRow['transaction_datetime'] ?? ''))); ?>
                                </td>
                                <td>
                                  <span class="badge <?= htmlspecialchars($badgeClass); ?>"><?= htmlspecialchars($label); ?></span>
                                  <?php if ($status !== ''): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars(ucfirst($status)); ?></div>
                                  <?php endif; ?>
                                </td>
                                <td>
                                  <div class="fw-semibold"><?= htmlspecialchars((string) ($activityRow['student_name'] ?? 'Unknown Student')); ?></div>
                                  <small class="text-muted">
                                    Examinee #:
                                    <?= htmlspecialchars(trim((string) ($activityRow['examinee_number'] ?? '')) !== '' ? (string) ($activityRow['examinee_number'] ?? '') : '--'); ?>
                                  </small>
                                </td>
                                <td>
                                  <?php if (trim((string) ($activityRow['program_label'] ?? '')) !== ''): ?>
                                    <?= htmlspecialchars((string) ($activityRow['program_label'] ?? '')); ?>
                                  <?php else: ?>
                                    <span class="text-muted">No program label</span>
                                  <?php endif; ?>
                                </td>
                                <td class="ut-activity-detail">
                                  <?= htmlspecialchars((string) ($activityRow['detail'] ?? '')); ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card mt-4">
                <div class="card-header">
                  <h5 class="mb-1">Student Transaction Trace</h5>
                  <small class="text-muted">Search a student and review all recorded transactions under that student.</small>
                </div>
                <div class="card-body">
                  <form method="GET" class="row g-2 align-items-end mb-4">
                    <?php if ($search !== ''): ?>
                      <input type="hidden" name="q" value="<?= htmlspecialchars($search); ?>" />
                    <?php endif; ?>
                    <?php if ($roleFilter !== ''): ?>
                      <input type="hidden" name="role" value="<?= htmlspecialchars($roleFilter); ?>" />
                    <?php endif; ?>
                    <?php if ($statusFilter !== ''): ?>
                      <input type="hidden" name="status" value="<?= htmlspecialchars($statusFilter); ?>" />
                    <?php endif; ?>
                    <?php if ($campusFilter > 0): ?>
                      <input type="hidden" name="campus_id" value="<?= $campusFilter; ?>" />
                    <?php endif; ?>
                    <?php if ($selectedAccountId > 0): ?>
                      <input type="hidden" name="account_id" value="<?= $selectedAccountId; ?>" />
                    <?php endif; ?>
                    <div class="col-lg-6">
                      <label class="form-label mb-1">Student Search</label>
                      <input
                        type="search"
                        name="student_q"
                        value="<?= htmlspecialchars($studentSearch); ?>"
                        class="form-control"
                        placeholder="Examinee number, student name, or preferred program"
                      />
                    </div>
                    <div class="col-lg-5">
                      <label class="form-label mb-1">Matched Student</label>
                      <select name="student_id" class="form-select">
                        <option value="0">Auto Select</option>
                        <?php foreach ($studentMatches as $studentMatch): ?>
                          <?php
                            $studentOptionId = (int) ($studentMatch['placement_result_id'] ?? 0);
                            $studentOptionTransactions = (int) ($studentMatch['score_log_count'] ?? 0)
                                + (int) ($studentMatch['transfer_count'] ?? 0)
                                + (int) ($studentMatch['guidance_edit_count'] ?? 0);
                          ?>
                          <option value="<?= $studentOptionId; ?>"<?= $selectedStudentId === $studentOptionId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($studentMatch['full_name'] ?? 'Unknown Student')); ?>
                            | <?= htmlspecialchars((string) ($studentMatch['examinee_number'] ?? '--')); ?>
                            | <?= number_format($studentOptionTransactions); ?> tx
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <button type="submit" class="btn btn-primary">Trace</button>
                    </div>
                  </form>

                  <?php if ($studentSearch !== '' && empty($studentMatches)): ?>
                    <div class="alert alert-warning" role="alert">
                      No students matched the current search.
                    </div>
                  <?php elseif (!$selectedStudent): ?>
                    <div class="text-center text-muted py-4">
                      Search by student name or examinee number to view the trace for that student.
                    </div>
                  <?php else: ?>
                    <div class="ut-selected-card mb-4">
                      <div class="row g-3 align-items-center">
                        <div class="col-lg-6">
                          <div class="ut-user-title"><?= htmlspecialchars((string) ($selectedStudent['full_name'] ?? 'Unknown Student')); ?></div>
                          <div class="text-muted">Examinee #: <?= htmlspecialchars((string) ($selectedStudent['examinee_number'] ?? '--')); ?></div>
                        </div>
                        <div class="col-lg-6">
                          <div class="text-muted small text-uppercase fw-semibold mb-1">Student Context</div>
                          <div class="fw-semibold">
                            <?php
                              $selectedStudentProgramLabel = administrator_user_transactions_format_program_label(
                                  (string) ($selectedStudent['program_name'] ?? ''),
                                  (string) ($selectedStudent['major'] ?? '')
                              );
                            ?>
                            <?= htmlspecialchars($selectedStudentProgramLabel !== '' ? $selectedStudentProgramLabel : (string) ($selectedStudent['preferred_program'] ?? 'No program label')); ?>
                          </div>
                          <small class="text-muted">
                            <?= htmlspecialchars(trim((string) ($selectedStudent['campus_name'] ?? '')) !== '' ? (string) ($selectedStudent['campus_name'] ?? '') : 'No campus assigned'); ?>
                          </small>
                        </div>
                      </div>
                    </div>

                    <?php if ($selectedStudentSummary): ?>
                      <div class="row g-3 mb-4">
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Transactions</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedStudentSummary['transactions']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Actors</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedStudentSummary['actors']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Score Logs</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedStudentSummary['score_logs']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Transfers</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedStudentSummary['transfers']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Guidance Edits</div>
                            <div class="ut-stat-value"><?= number_format((int) $selectedStudentSummary['guidance_edits']); ?></div>
                          </div>
                        </div>
                        <div class="col-xl-2 col-md-4 col-6">
                          <div class="ut-stat-card">
                            <div class="ut-stat-label">Last Activity</div>
                            <div class="small fw-semibold text-dark mt-2">
                              <?= htmlspecialchars(administrator_user_transactions_format_datetime($selectedStudentSummary['last_transaction_at'])); ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    <?php endif; ?>

                    <div class="table-responsive">
                      <table class="table table-sm ut-table ut-activity-table">
                        <thead>
                          <tr>
                            <th>When</th>
                            <th>Type</th>
                            <th>Actor</th>
                            <th>Program</th>
                            <th>Details</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php if (empty($selectedStudentActivityRows)): ?>
                            <tr>
                              <td colspan="5" class="text-center text-muted py-4">
                                No recorded transactions for this student.
                              </td>
                            </tr>
                          <?php else: ?>
                            <?php foreach ($selectedStudentActivityRows as $activityRow): ?>
                              <?php
                                $badgeClass = administrator_user_transactions_badge_class($activityRow);
                                $label = administrator_user_transactions_source_label($activityRow);
                                $status = trim((string) ($activityRow['status'] ?? ''));
                              ?>
                              <tr>
                                <td class="text-nowrap">
                                  <?= htmlspecialchars(administrator_user_transactions_format_datetime((string) ($activityRow['transaction_datetime'] ?? ''))); ?>
                                </td>
                                <td>
                                  <span class="badge <?= htmlspecialchars($badgeClass); ?>"><?= htmlspecialchars($label); ?></span>
                                  <?php if ($status !== ''): ?>
                                    <div class="small text-muted mt-1"><?= htmlspecialchars(ucfirst($status)); ?></div>
                                  <?php endif; ?>
                                </td>
                                <td>
                                  <div class="fw-semibold"><?= htmlspecialchars((string) ($activityRow['actor_name'] ?? 'Unknown Actor')); ?></div>
                                  <small class="text-muted"><?= htmlspecialchars((string) ($activityRow['actor_role_label'] ?? 'Unknown')); ?></small>
                                </td>
                                <td>
                                  <?php if (trim((string) ($activityRow['program_label'] ?? '')) !== ''): ?>
                                    <?= htmlspecialchars((string) ($activityRow['program_label'] ?? '')); ?>
                                  <?php else: ?>
                                    <span class="text-muted">No program label</span>
                                  <?php endif; ?>
                                </td>
                                <td class="ut-activity-detail">
                                  <?= htmlspecialchars((string) ($activityRow['detail'] ?? '')); ?>
                                </td>
                              </tr>
                            <?php endforeach; ?>
                          <?php endif; ?>
                        </tbody>
                      </table>
                    </div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
