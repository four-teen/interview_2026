<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/student_preregistration.php';
require_once __DIR__ . '/../config/program_ranking_lock.php';

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=120');

ensure_program_ranking_locks_table($conn);
ensure_student_preregistration_storage($conn);

function q_h($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function q_slugify(string $value): string
{
    $value = strtolower(trim($value));
    $value = preg_replace('/[^a-z0-9]+/', '-', $value);
    $value = trim((string) $value, '-');
    return $value !== '' ? $value : 'campus';
}

function q_public_campus_key(array $campus): string
{
    $code = trim((string) ($campus['campus_code'] ?? ''));
    if ($code !== '') {
        return q_slugify($code);
    }

    $name = trim((string) ($campus['campus_name'] ?? ''));
    if ($name !== '') {
        return q_slugify($name);
    }

    return 'campus-' . max(0, (int) ($campus['campus_id'] ?? 0));
}

function q_program_label(array $row): string
{
    $code = trim((string) ($row['program_code'] ?? ''));
    $name = trim((string) ($row['program_name'] ?? ''));
    $major = trim((string) ($row['major'] ?? ''));

    $label = $name;
    if ($major !== '') {
        $label .= ' - ' . $major;
    }
    if ($code !== '') {
        $label = $code . ' - ' . $label;
    }

    return trim($label) !== '' ? $label : ('Program #' . max(0, (int) ($row['program_id'] ?? 0)));
}

function q_fetch_campuses(mysqli $conn): array
{
    $sql = "
        SELECT
            c.campus_id,
            c.campus_code,
            c.campus_name,
            COUNT(DISTINCT l.lock_id) AS locked_count,
            COUNT(DISTINCT CASE WHEN spr.preregistration_id IS NOT NULL THEN l.lock_id ELSE NULL END) AS preregistered_count,
            COUNT(DISTINCT CASE WHEN l.snapshot_outside_capacity = 1 THEN l.lock_id ELSE NULL END) AS outside_capacity_count,
            MAX(l.locked_at) AS latest_locked_at
        FROM tbl_campus c
        LEFT JOIN tbl_college col
            ON col.campus_id = c.campus_id
           AND col.status = 'active'
        LEFT JOIN tbl_program p
            ON p.college_id = col.college_id
           AND p.status = 'active'
        LEFT JOIN tbl_program_ranking_locks l
            ON l.program_id = p.program_id
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        WHERE c.status = 'active'
        GROUP BY c.campus_id, c.campus_code, c.campus_name
        ORDER BY c.campus_name ASC
    ";

    $campuses = [];
    $result = $conn->query($sql);
    if (!$result) {
        return $campuses;
    }

    while ($row = $result->fetch_assoc()) {
        $row['public_key'] = q_public_campus_key($row);
        $campuses[] = $row;
    }
    $result->free();

    return $campuses;
}

function q_find_selected_campus(array $campuses, string $key): ?array
{
    $normalizedKey = strtolower(trim($key));
    if ($normalizedKey === '') {
        return null;
    }

    foreach ($campuses as $campus) {
        $campusId = (string) max(0, (int) ($campus['campus_id'] ?? 0));
        $publicKey = strtolower((string) ($campus['public_key'] ?? ''));
        $codeKey = q_slugify((string) ($campus['campus_code'] ?? ''));
        $nameKey = q_slugify((string) ($campus['campus_name'] ?? ''));

        if (
            $normalizedKey === $campusId ||
            $normalizedKey === $publicKey ||
            $normalizedKey === $codeKey ||
            $normalizedKey === $nameKey
        ) {
            return $campus;
        }
    }

    return null;
}

function q_fetch_locked_students(mysqli $conn, int $campusId): array
{
    $sql = "
        SELECT
            l.lock_id,
            l.interview_id,
            l.locked_rank,
            l.locked_at,
            l.snapshot_examinee_number,
            l.snapshot_full_name,
            l.snapshot_section,
            l.snapshot_outside_capacity,
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            col.college_name,
            spr.preregistration_id,
            spr.submitted_at
        FROM tbl_program_ranking_locks l
        INNER JOIN tbl_program p
            ON p.program_id = l.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_campus c
            ON c.campus_id = col.campus_id
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        WHERE c.campus_id = ?
          AND c.status = 'active'
          AND col.status = 'active'
          AND p.status = 'active'
        ORDER BY
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC,
            l.snapshot_full_name ASC,
            l.snapshot_examinee_number ASC
    ";

    $rows = [];
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return $rows;
    }

    $stmt->bind_param('i', $campusId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['program_label'] = q_program_label($row);
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

$campuses = q_fetch_campuses($conn);
$pathKey = '';
if (!empty($_SERVER['PATH_INFO'])) {
    $pathKey = trim((string) $_SERVER['PATH_INFO'], '/');
}
$selectedCampusKey = trim((string) ($_GET['c'] ?? ($_GET['campus'] ?? $pathKey)));
$selectedCampus = q_find_selected_campus($campuses, $selectedCampusKey);
$campusPrintAllowed = $selectedCampus && ((string) ($_GET['print'] ?? '') === '1');
$lockedRows = $selectedCampus ? q_fetch_locked_students($conn, (int) $selectedCampus['campus_id']) : [];

$programGroups = [];
$totalLocked = count($lockedRows);
$preRegisteredTotal = 0;
$outsideCapacityTotal = 0;

foreach ($lockedRows as $row) {
    if (!empty($row['preregistration_id'])) {
        $preRegisteredTotal++;
    }
    if ((int) ($row['snapshot_outside_capacity'] ?? 0) === 1) {
        $outsideCapacityTotal++;
    }

    $programId = (int) ($row['program_id'] ?? 0);
    if (!isset($programGroups[$programId])) {
        $programGroups[$programId] = [
            'program_label' => (string) ($row['program_label'] ?? 'Program'),
            'college_name' => (string) ($row['college_name'] ?? ''),
            'rows' => [],
        ];
    }
    $programGroups[$programId]['rows'][] = $row;
}

uasort($programGroups, function (array $a, array $b): int {
    return strcasecmp((string) ($a['program_label'] ?? ''), (string) ($b['program_label'] ?? ''));
});

$publishedAt = date('M j, Y g:i A');
$baseUrl = rtrim((string) BASE_URL, '/');
$assetBase = $baseUrl . '/assets';
$pageTitle = $selectedCampus ? ((string) $selectedCampus['campus_name'] . ' Qualified Students') : 'Qualified Students';
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="<?= q_h($assetBase); ?>/"
  data-template="vertical-menu-template-free"
>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
  <title><?= q_h($pageTitle); ?> - SKSU Interview</title>
  <meta name="description" content="Public campus list of locked students qualified for document submission." />
  <link rel="icon" type="image/x-icon" href="<?= q_h($assetBase); ?>/img/favicon/favicon.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="<?= q_h($assetBase); ?>/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="<?= q_h($assetBase); ?>/vendor/css/core.css" />
  <link rel="stylesheet" href="<?= q_h($assetBase); ?>/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="<?= q_h($assetBase); ?>/css/demo.css" />
  <style>
    :root {
      --q-ink: #182233;
      --q-muted: #66758b;
      --q-line: #dde5ef;
      --q-surface: #ffffff;
      --q-band: #f3f7fb;
      --q-green: #16784b;
      --q-amber: #9a5a00;
      --q-blue: #2f5d9a;
    }

    body {
      margin: 0;
      color: var(--q-ink);
      background: #f7f9fc;
      font-family: "Public Sans", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }

    .q-page {
      min-height: 100vh;
    }

    .q-topbar {
      background: var(--q-surface);
      border-bottom: 1px solid var(--q-line);
    }

    .q-topbar-inner,
    .q-main {
      width: min(1180px, calc(100% - 32px));
      margin: 0 auto;
    }

    .q-topbar-inner {
      min-height: 72px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
    }

    .q-brand {
      display: flex;
      align-items: center;
      gap: 0.8rem;
      min-width: 0;
    }

    .q-brand img {
      width: 48px;
      height: 48px;
      object-fit: contain;
    }

    .q-brand-title {
      margin: 0;
      font-size: 1.05rem;
      font-weight: 700;
      color: var(--q-ink);
      line-height: 1.2;
    }

    .q-brand-subtitle {
      display: block;
      color: var(--q-muted);
      font-size: 0.82rem;
      margin-top: 0.1rem;
    }

    .q-actions {
      display: flex;
      align-items: center;
      gap: 0.5rem;
      flex-wrap: wrap;
      justify-content: flex-end;
    }

    .q-print-brand {
      display: none;
    }

    .q-main {
      padding: 28px 0 48px;
    }

    .q-hero {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 1.5rem;
      align-items: end;
      padding: 26px;
      background: var(--q-band);
      border: 1px solid var(--q-line);
      border-radius: 8px;
    }

    .q-eyebrow {
      margin: 0 0 0.45rem;
      color: var(--q-blue);
      font-size: 0.78rem;
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: 0.08em;
    }

    .q-title {
      margin: 0;
      font-size: clamp(1.55rem, 3vw, 2.35rem);
      line-height: 1.12;
      font-weight: 700;
      letter-spacing: 0;
    }

    .q-copy {
      margin: 0.65rem 0 0;
      color: var(--q-muted);
      max-width: 760px;
      font-size: 0.98rem;
    }

    .q-stats {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(112px, 1fr));
      gap: 0.6rem;
      min-width: 160px;
    }

    .q-stat {
      background: var(--q-surface);
      border: 1px solid var(--q-line);
      border-radius: 8px;
      padding: 0.85rem;
    }

    .q-stat-value {
      font-size: 1.45rem;
      line-height: 1;
      font-weight: 700;
    }

    .q-stat-label {
      display: block;
      margin-top: 0.28rem;
      color: var(--q-muted);
      font-size: 0.77rem;
      line-height: 1.2;
    }

    .q-section {
      margin-top: 22px;
      background: var(--q-surface);
      border: 1px solid var(--q-line);
      border-radius: 8px;
      overflow: hidden;
    }

    .q-section-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 18px 20px;
      border-bottom: 1px solid var(--q-line);
    }

    .q-section-title {
      margin: 0;
      font-size: 1rem;
      font-weight: 700;
    }

    .q-campus-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(245px, 1fr));
      gap: 0;
    }

    .q-campus-link {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      min-height: 104px;
      padding: 18px 20px;
      border-right: 1px solid var(--q-line);
      border-bottom: 1px solid var(--q-line);
      color: inherit;
      text-decoration: none;
      background: #ffffff;
    }

    .q-campus-link:hover {
      color: inherit;
      background: #f8fbff;
    }

    .q-campus-name {
      font-weight: 700;
      line-height: 1.2;
    }

    .q-campus-code,
    .q-campus-meta {
      display: block;
      color: var(--q-muted);
      font-size: 0.8rem;
      margin-top: 0.22rem;
    }

    .q-campus-count {
      min-width: 58px;
      text-align: center;
      border: 1px solid #cdd9e8;
      border-radius: 8px;
      padding: 0.45rem 0.5rem;
      background: #f7fbff;
    }

    .q-campus-count strong {
      display: block;
      font-size: 1.15rem;
      line-height: 1;
      color: var(--q-blue);
    }

    .q-campus-count span {
      display: block;
      margin-top: 0.16rem;
      color: var(--q-muted);
      font-size: 0.7rem;
    }

    .q-toolbar {
      display: grid;
      grid-template-columns: minmax(0, 1fr) auto;
      gap: 0.75rem;
      align-items: center;
      padding: 16px 20px;
      border-bottom: 1px solid var(--q-line);
      background: #ffffff;
    }

    .q-toolbar-actions {
      display: flex;
      align-items: center;
      justify-content: flex-end;
      gap: 0.5rem;
      flex-wrap: wrap;
    }

    .q-search {
      position: relative;
    }

    .q-search i {
      position: absolute;
      left: 0.9rem;
      top: 50%;
      transform: translateY(-50%);
      color: #7b8aa0;
      font-size: 1.05rem;
    }

    .q-search input {
      width: 100%;
      height: 42px;
      border: 1px solid #cfd9e6;
      border-radius: 8px;
      padding: 0 0.9rem 0 2.55rem;
      color: var(--q-ink);
      background: #ffffff;
    }

    .q-program {
      border-bottom: 1px solid var(--q-line);
    }

    .q-program:last-child {
      border-bottom: 0;
    }

    .q-program-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 1rem;
      padding: 16px 20px;
      background: #fbfcfe;
      border-bottom: 1px solid var(--q-line);
    }

    .q-program-title {
      margin: 0;
      font-size: 0.98rem;
      font-weight: 700;
      line-height: 1.3;
    }

    .q-program-college {
      display: block;
      margin-top: 0.18rem;
      color: var(--q-muted);
      font-size: 0.78rem;
      font-weight: 400;
    }

    .q-program-count {
      white-space: nowrap;
      color: var(--q-muted);
      font-size: 0.82rem;
      font-weight: 700;
    }

    .q-table {
      width: 100%;
      border-collapse: collapse;
      table-layout: fixed;
    }

    .q-table th,
    .q-table td {
      padding: 0.8rem 1.25rem;
      border-bottom: 1px solid #eef2f6;
      vertical-align: middle;
      font-size: 0.9rem;
    }

    .q-table th {
      color: #5d6b80;
      font-size: 0.76rem;
      text-transform: uppercase;
      letter-spacing: 0.05em;
      background: #ffffff;
    }

    .q-table tr:last-child td {
      border-bottom: 0;
    }

    .q-name {
      font-weight: 700;
      color: var(--q-ink);
      overflow-wrap: anywhere;
    }

    .q-examinee {
      color: #536276;
      font-variant-numeric: tabular-nums;
    }

    .q-badges {
      display: flex;
      align-items: center;
      gap: 0.35rem;
      flex-wrap: wrap;
    }

    .q-badge {
      display: inline-flex;
      align-items: center;
      min-height: 26px;
      padding: 0.25rem 0.55rem;
      border-radius: 999px;
      font-size: 0.72rem;
      font-weight: 700;
      line-height: 1.1;
      white-space: nowrap;
    }

    .q-badge-green {
      color: var(--q-green);
      background: #e9f7ef;
      border: 1px solid #bfe7cf;
    }

    .q-badge-blue {
      color: var(--q-blue);
      background: #edf4ff;
      border: 1px solid #cbdcf4;
    }

    .q-badge-amber {
      color: var(--q-amber);
      background: #fff6e7;
      border: 1px solid #f4d6a5;
    }

    .q-empty {
      padding: 28px 20px;
      color: var(--q-muted);
      text-align: center;
    }

    .q-muted {
      color: var(--q-muted);
    }

    .q-footer-note {
      margin: 18px 0 0;
      color: var(--q-muted);
      font-size: 0.82rem;
      text-align: center;
    }

    @media (max-width: 860px) {
      .q-hero {
        grid-template-columns: 1fr;
      }

      .q-stats {
        min-width: 0;
      }

      .q-toolbar {
        grid-template-columns: 1fr;
      }
    }

    @media (max-width: 640px) {
      .q-topbar-inner,
      .q-main {
        width: min(100% - 20px, 1180px);
      }

      .q-topbar-inner {
        align-items: flex-start;
        flex-direction: column;
        padding: 12px 0;
      }

      .q-actions {
        justify-content: flex-start;
      }

      .q-hero {
        padding: 20px;
      }

      .q-stats {
        grid-template-columns: 1fr;
      }

      .q-table thead {
        display: none;
      }

      .q-table,
      .q-table tbody,
      .q-table tr,
      .q-table td {
        display: block;
        width: 100%;
      }

      .q-table tr {
        padding: 0.85rem 1rem;
        border-bottom: 1px solid #eef2f6;
      }

      .q-table tr:last-child {
        border-bottom: 0;
      }

      .q-table td {
        padding: 0.18rem 0;
        border: 0;
      }
    }

    @media print {
      @page {
        size: A4 portrait;
        margin: 10mm;
      }

      body {
        background: #ffffff;
        color: #111827;
      }

      .q-topbar,
      .q-toolbar,
      .q-actions,
      .btn {
        display: none !important;
      }

      .q-print-brand {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
      }

      .q-print-brand img {
        width: 34px;
        height: 34px;
        object-fit: contain;
      }

      .q-print-brand-title {
        display: block;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.1;
        color: #111827;
      }

      .q-print-brand-subtitle {
        display: block;
        margin-top: 2px;
        font-size: 9.5px;
        color: #374151;
        line-height: 1.1;
      }

      .q-main {
        width: 100%;
        padding: 0;
      }

      .q-hero {
        display: block;
        padding: 0 0 6px;
        background: #ffffff;
        border-bottom: 2px solid #111827;
        margin-bottom: 6px;
      }

      .q-eyebrow {
        color: #111827;
        font-size: 10px;
        margin-bottom: 3px;
      }

      .q-title {
        font-size: 20px;
        line-height: 1.15;
      }

      .q-copy {
        max-width: none;
        margin-top: 3px;
        color: #374151;
        font-size: 11px;
      }

      .q-stats {
        display: block;
        min-width: 0;
        margin-top: 5px;
      }

      .q-stat {
        display: inline-block;
        border: 0;
        padding: 0;
        background: transparent;
      }

      .q-stat-value {
        display: inline;
        font-size: 12px;
      }

      .q-stat-label {
        display: inline;
        font-size: 11px;
        color: #374151;
      }

      .q-hero,
      .q-section {
        border: 0;
        border-radius: 0;
      }

      .q-section {
        margin-top: 0;
        overflow: visible;
      }

      .q-program {
        page-break-inside: auto;
        break-inside: auto;
        border-bottom: 0;
        margin-top: 6px;
      }

      .q-program + .q-program {
        page-break-before: always;
        break-before: page;
        margin-top: 0;
      }

      .q-program-header {
        padding: 7px 8px;
        border: 1px solid #9ca3af;
        border-bottom: 0;
        background: #f3f4f6;
        page-break-after: avoid;
        break-after: avoid;
      }

      .q-program-title {
        font-size: 11px;
      }

      .q-program-college,
      .q-program-count {
        font-size: 9px;
      }

      .q-table {
        display: table;
        width: 100%;
        border-collapse: collapse;
        table-layout: fixed;
        page-break-inside: auto;
        break-inside: auto;
      }

      .q-table thead {
        display: table-header-group;
      }

      .q-table tbody {
        display: table-row-group;
      }

      .q-table tr {
        display: table-row;
        page-break-inside: avoid;
        break-inside: avoid;
      }

      .q-table th,
      .q-table td {
        display: table-cell;
        width: auto;
        padding: 5px 7px;
        border: 1px solid #d1d5db;
        font-size: 10.5px;
      }

      .q-table th:first-child,
      .q-table td:first-child {
        width: 36px;
        text-align: right;
      }

      .q-table th {
        color: #111827;
        background: #ffffff;
        letter-spacing: 0.02em;
      }

      .q-name {
        color: #111827;
      }

      .q-examinee {
        color: #111827;
      }

      .q-footer-note {
        margin-top: 10px;
        font-size: 9.5px;
      }
    }
  </style>
</head>
<body>
<div class="q-page">
  <header class="q-topbar">
    <div class="q-topbar-inner">
      <?php if ($selectedCampus): ?>
        <div class="q-brand" aria-label="SKSU Interview Qualified Students">
          <img src="<?= q_h($assetBase); ?>/img/logo.png" alt="SKSU Logo" />
          <span>
            <span class="q-brand-title">SKSU Interview</span>
            <span class="q-brand-subtitle">Qualified Students</span>
          </span>
        </div>
      <?php else: ?>
        <a class="q-brand" href="<?= q_h($baseUrl); ?>/q/">
          <img src="<?= q_h($assetBase); ?>/img/logo.png" alt="SKSU Logo" />
          <span>
            <span class="q-brand-title">SKSU Interview</span>
            <span class="q-brand-subtitle">Qualified Students</span>
          </span>
        </a>
      <?php endif; ?>
      <div class="q-actions">
        <?php if ($campusPrintAllowed): ?>
          <button class="btn btn-sm btn-primary" type="button" id="qPrintPageButton">
            <i class="bx bx-printer me-1"></i>Print / Save PDF
          </button>
        <?php endif; ?>
      </div>
    </div>
  </header>

  <main class="q-main">
    <?php if (!$selectedCampus): ?>
      <section class="q-hero">
        <div>
          <p class="q-eyebrow">Public campus links</p>
          <h1 class="q-title">Qualified Students for Document Submission</h1>
          <p class="q-copy">
            Select a campus to view locked students grouped by program and arranged alphabetically.
            The list includes submitted preregistrations and locked students outside absorptive capacity.
          </p>
        </div>
        <div class="q-stats">
          <div class="q-stat">
            <div class="q-stat-value"><?= number_format(count($campuses)); ?></div>
            <span class="q-stat-label">Campus links</span>
          </div>
          <div class="q-stat">
            <div class="q-stat-value"><?= number_format(array_sum(array_map(static function (array $campus): int {
                return max(0, (int) ($campus['locked_count'] ?? 0));
            }, $campuses))); ?></div>
            <span class="q-stat-label">Published names</span>
          </div>
          <div class="q-stat">
            <div class="q-stat-value"><?= q_h($publishedAt); ?></div>
            <span class="q-stat-label">Page generated</span>
          </div>
        </div>
      </section>

      <section class="q-section">
        <div class="q-section-header">
          <h2 class="q-section-title">Campus Short Links</h2>
          <span class="q-muted small"><?= number_format(count($campuses)); ?> active campuses</span>
        </div>
        <?php if (empty($campuses)): ?>
          <div class="q-empty">No active campuses are available.</div>
        <?php else: ?>
          <div class="q-campus-grid">
            <?php foreach ($campuses as $campus): ?>
              <?php
                $campusLink = $baseUrl . '/q/' . rawurlencode((string) ($campus['public_key'] ?? '')) . '/';
                $campusPrintLink = $campusLink . '?print=1';
                $campusCode = trim((string) ($campus['campus_code'] ?? ''));
              ?>
              <a class="q-campus-link" href="<?= q_h($campusPrintLink); ?>">
                <span>
                  <span class="q-campus-name"><?= q_h($campus['campus_name'] ?? 'Campus'); ?></span>
                  <?php if ($campusCode !== ''): ?>
                    <span class="q-campus-code"><?= q_h($campusCode); ?></span>
                  <?php endif; ?>
                  <span class="q-campus-meta"><?= q_h($campusLink); ?></span>
                </span>
                <span class="q-campus-count">
                  <strong><?= number_format(max(0, (int) ($campus['locked_count'] ?? 0))); ?></strong>
                  <span>names</span>
                </span>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php else: ?>
      <section class="q-hero">
        <div class="q-print-brand">
          <img src="<?= q_h($assetBase); ?>/img/logo.png" alt="SKSU Logo" />
          <span>
            <span class="q-print-brand-title">SKSU Interview</span>
            <span class="q-print-brand-subtitle">Qualified Students for Document Submission</span>
          </span>
        </div>
        <div>
          <p class="q-eyebrow">Campus list</p>
          <h1 class="q-title"><?= q_h($selectedCampus['campus_name'] ?? 'Campus'); ?></h1>
          <p class="q-copy">
            Official alphabetical list of locked students qualified for document submission,
            grouped by program. Students listed here should follow campus instructions for
            submitting admission documents.
          </p>
        </div>
        <div class="q-stats">
          <div class="q-stat">
            <div class="q-stat-value"><?= number_format($totalLocked); ?></div>
            <span class="q-stat-label">Published names</span>
          </div>
        </div>
      </section>

      <section class="q-section" id="studentList">
        <div class="q-toolbar">
          <div class="q-search">
            <i class="bx bx-search"></i>
            <input id="qSearchInput" type="search" placeholder="Search name, examinee number, or program" autocomplete="off" />
          </div>
        </div>

        <?php if (empty($programGroups)): ?>
          <div class="q-empty">No locked students are published for this campus yet.</div>
        <?php else: ?>
          <?php foreach ($programGroups as $group): ?>
            <?php $groupRows = (array) ($group['rows'] ?? []); ?>
            <article class="q-program" data-program-group>
              <div class="q-program-header">
                <h2 class="q-program-title">
                  <?= q_h($group['program_label'] ?? 'Program'); ?>
                  <?php if (trim((string) ($group['college_name'] ?? '')) !== ''): ?>
                    <span class="q-program-college"><?= q_h($group['college_name']); ?></span>
                  <?php endif; ?>
                </h2>
                <span class="q-program-count" data-program-count><?= number_format(count($groupRows)); ?> students</span>
              </div>
              <table class="q-table">
                <thead>
                  <tr>
                    <th style="width: 70px;">No.</th>
                    <th>Student Name</th>
                    <th style="width: 32%;">Examinee Number</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($groupRows as $index => $row): ?>
                    <?php
                      $name = trim((string) ($row['snapshot_full_name'] ?? ''));
                      $examineeNumber = trim((string) ($row['snapshot_examinee_number'] ?? ''));
                      $searchText = strtolower(trim(implode(' ', [
                          $name,
                          $examineeNumber,
                          (string) ($group['program_label'] ?? ''),
                          (string) ($group['college_name'] ?? ''),
                      ])));
                    ?>
                    <tr data-student-row data-search="<?= q_h($searchText); ?>">
                      <td>
                        <span class="q-examinee"><?= number_format($index + 1); ?></span>
                      </td>
                      <td>
                        <div class="q-name"><?= q_h($name !== '' ? $name : 'Unnamed Student'); ?></div>
                      </td>
                      <td>
                        <span class="q-examinee"><?= q_h($examineeNumber !== '' ? $examineeNumber : 'N/A'); ?></span>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </article>
          <?php endforeach; ?>
          <div class="q-empty d-none" id="qNoSearchResults">No matching student was found.</div>
        <?php endif; ?>
      </section>

      <p class="q-footer-note">
        For official document requirements and submission schedule, follow the registrar or campus admission office announcement.
      </p>
    <?php endif; ?>
  </main>
</div>

<script src="<?= q_h($assetBase); ?>/vendor/libs/jquery/jquery.js"></script>
<script src="<?= q_h($assetBase); ?>/vendor/js/bootstrap.js"></script>
<?php if ($selectedCampus && !empty($programGroups)): ?>
<script>
(function () {
  const input = document.getElementById("qSearchInput");
  const empty = document.getElementById("qNoSearchResults");
  const groups = Array.from(document.querySelectorAll("[data-program-group]"));

  function applyFilter() {
    const needle = String(input.value || "").trim().toLowerCase();
    let visibleTotal = 0;

    groups.forEach((group) => {
      const rows = Array.from(group.querySelectorAll("[data-student-row]"));
      let visibleInGroup = 0;

      rows.forEach((row) => {
        const haystack = String(row.getAttribute("data-search") || "");
        const visible = needle === "" || haystack.includes(needle);
        row.classList.toggle("d-none", !visible);
        if (visible) {
          visibleInGroup += 1;
        }
      });

      const count = group.querySelector("[data-program-count]");
      if (count) {
        count.textContent = visibleInGroup + (visibleInGroup === 1 ? " student" : " students");
      }
      group.classList.toggle("d-none", visibleInGroup === 0);
      visibleTotal += visibleInGroup;
    });

    if (empty) {
      empty.classList.toggle("d-none", visibleTotal !== 0);
    }
  }

  if (input) {
    input.addEventListener("input", applyFilter);
  }

<?php if ($campusPrintAllowed): ?>
  const printButton = document.getElementById("qPrintPageButton");
  if (printButton) {
    printButton.addEventListener("click", function () {
      if (input) {
        input.value = "";
        applyFilter();
      }
      window.print();
    });
  }
<?php endif; ?>
})();
</script>
<?php endif; ?>
</body>
</html>
