<?php
require_once '../config/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function guidance_require_access(): void
{
    $role = strtolower(trim((string) ($_SESSION['role'] ?? '')));

    if (!isset($_SESSION['logged_in']) || !in_array($role, ['guidance', 'monitoring'], true)) {
        header('Location: ../index.php');
        exit;
    }
}

function guidance_build_esm_preferred_program_condition_sql(string $columnExpression): string
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

function guidance_is_esm_preferred_program(string $preferredProgram): bool
{
    $normalized = strtoupper(trim($preferredProgram));
    if ($normalized === '') {
        return false;
    }

    $simpleMatches = [
        'NURSING',
        'MIDWIFERY',
        'MEDICAL TECHNOLOGY',
        'ELECTRONICS ENGINEERING',
        'CIVIL ENGINEERING',
        'COMPUTER ENGINEERING',
        'COMPUTER SCIENCE',
        'FISHERIES',
        'BIOLOGY',
        'ACCOUNTANCY',
        'MANAGEMENT ACCOUNTING',
        'ACCOUNTING INFORMATION SYSTEMS',
        'MATHEMATICS EDUCATION',
        'SCIENCE EDUCATION'
    ];

    foreach ($simpleMatches as $match) {
        if (strpos($normalized, $match) !== false) {
            return true;
        }
    }

    return (
        strpos($normalized, 'SECONDARY EDUCATION') !== false
        && (
            strpos($normalized, 'MATHEMATICS') !== false
            || strpos($normalized, 'SCIENCE') !== false
        )
    );
}

function guidance_get_active_batch_id(mysqli $conn): ?string
{
    $batchResult = $conn->query("
        SELECT upload_batch_id
        FROM tbl_placement_results
        ORDER BY created_at DESC, id DESC
        LIMIT 1
    ");

    if (!$batchResult) {
        return null;
    }

    $batchRow = $batchResult->fetch_assoc();
    $batchId = trim((string) ($batchRow['upload_batch_id'] ?? ''));

    return $batchId !== '' ? $batchId : null;
}

function guidance_bind_params(mysqli_stmt $stmt, string $types, array $params): void
{
    if ($types === '' || empty($params)) {
        return;
    }

    $bind = [$types];
    foreach ($params as $index => $value) {
        $bind[] = &$params[$index];
    }

    call_user_func_array([$stmt, 'bind_param'], $bind);
}

function guidance_format_score($value, int $decimals = 0): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    if (!is_numeric($value)) {
        return trim((string) $value);
    }

    return number_format((float) $value, $decimals);
}

function guidance_percentage(int $numerator, int $denominator): string
{
    if ($denominator <= 0) {
        return '0.0%';
    }

    return number_format(($numerator / $denominator) * 100, 1) . '%';
}

function guidance_set_flash(string $type, string $message): void
{
    $_SESSION['guidance_flash'] = [
        'type' => $type,
        'message' => $message
    ];
}

function guidance_pull_flash(): ?array
{
    $flash = $_SESSION['guidance_flash'] ?? null;
    unset($_SESSION['guidance_flash']);

    return is_array($flash) ? $flash : null;
}

function guidance_get_student_return_query(array $source): array
{
    $query = [];

    $search = trim((string) ($source['q'] ?? ''));
    if ($search !== '') {
        $query['q'] = $search;
    }

    $basis = strtolower(trim((string) ($source['basis'] ?? 'all')));
    if (in_array($basis, ['all', 'esm', 'overall'], true) && $basis !== 'all') {
        $query['basis'] = $basis;
    }

    $scoreStatus = strtolower(trim((string) ($source['score_status'] ?? 'all')));
    if (in_array($scoreStatus, ['all', 'scored', 'not_scored'], true) && $scoreStatus !== 'all') {
        $query['score_status'] = $scoreStatus;
    }

    $preferredProgramFilter = trim((string) ($source['preferred_program_filter'] ?? ''));
    if ($preferredProgramFilter !== '') {
        $query['preferred_program_filter'] = $preferredProgramFilter;
    }

    $page = max(1, (int) ($source['page'] ?? 1));
    if ($page > 1) {
        $query['page'] = $page;
    }

    return $query;
}

function guidance_students_url(array $query = []): string
{
    return 'students.php' . (!empty($query) ? ('?' . http_build_query($query)) : '');
}

function guidance_redirect_students(array $query = []): void
{
    header('Location: ' . guidance_students_url($query));
    exit;
}
