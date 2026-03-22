<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
require_once '../config/program_ranking_lock.php';
require_once '../config/admin_student_impersonation.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$adminStudentPreviewCsrf = admin_student_impersonation_get_csrf_token();
$adminStudentPreviewFlash = admin_student_impersonation_pop_flash();
$adminStudentPreviewReturnTo = admin_student_impersonation_normalize_return_to((string) ($_SERVER['REQUEST_URI'] ?? ''));
$adminPreregFlash = $_SESSION['administrator_prereg_flash'] ?? null;
unset($_SESSION['administrator_prereg_flash']);
$search = trim((string) ($_GET['q'] ?? ''));
$programFilter = (int) ($_GET['program_id'] ?? 0);
$storageReady = ensure_student_preregistration_storage($conn);
$adminPreregCsrf = (string) ($_SESSION['administrator_prereg_csrf'] ?? '');
if ($adminPreregCsrf === '') {
    try {
        $adminPreregCsrf = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $adminPreregCsrf = sha1(uniqid('administrator_prereg_', true));
    }
    $_SESSION['administrator_prereg_csrf'] = $adminPreregCsrf;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'forfeit_preregistration') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $preregistrationId = (int) ($_POST['preregistration_id'] ?? 0);
    $preserveSearch = trim((string) ($_POST['search'] ?? ''));
    $preserveProgramFilter = (int) ($_POST['preserve_program_filter'] ?? 0);

    if ($postedCsrf === '' || !hash_equals($adminPreregCsrf, $postedCsrf)) {
        $_SESSION['administrator_prereg_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid request token. Refresh the page and try again.',
        ];
    } else {
        $forfeitResult = student_preregistration_forfeit(
            $conn,
            $preregistrationId,
            (int) ($_SESSION['accountid'] ?? 0)
        );
        $_SESSION['administrator_prereg_flash'] = [
            'type' => ($forfeitResult['success'] ?? false) ? 'success' : 'danger',
            'message' => (string) ($forfeitResult['message'] ?? 'Failed to forfeit pre-registration.'),
        ];
    }

    $redirectParams = [];
    if ($preserveProgramFilter > 0) {
        $redirectParams['program_id'] = $preserveProgramFilter;
    }
    if ($preserveSearch !== '') {
        $redirectParams['q'] = $preserveSearch;
    }
    $nextUrl = 'preregistrations.php' . (empty($redirectParams) ? '' : '?' . http_build_query($redirectParams));
    header('Location: ' . $nextUrl);
    exit;
}

$programOptions = $storageReady ? student_preregistration_fetch_program_options($conn) : [];
$report = $storageReady
    ? student_preregistration_fetch_report($conn, [
        'search' => $search,
        'program_id' => $programFilter,
        'limit' => 1000,
    ])
    : ['rows' => [], 'summary' => ['total' => 0, 'programs' => 0, 'profile_complete' => 0, 'profile_incomplete' => 0, 'agreement_accepted' => 0]];

$rows = $report['rows'];
$summary = $report['summary'];
$printHeader = student_preregistration_get_print_header();
$printSections = student_preregistration_build_print_sections($rows);
foreach ($printSections as &$printSection) {
    $sectionRows = is_array($printSection['rows'] ?? null) ? $printSection['rows'] : [];
    usort($sectionRows, static function (array $left, array $right): int {
        $leftName = trim((string) ($left['full_name'] ?? ''));
        $rightName = trim((string) ($right['full_name'] ?? ''));
        $leftLastName = $leftName;
        $rightLastName = $rightName;

        if (strpos($leftName, ',') !== false) {
            $leftLastName = trim((string) strstr($leftName, ',', true));
        } elseif ($leftName !== '') {
            $leftParts = preg_split('/\s+/', $leftName);
            $leftLastName = trim((string) end($leftParts));
        }

        if (strpos($rightName, ',') !== false) {
            $rightLastName = trim((string) strstr($rightName, ',', true));
        } elseif ($rightName !== '') {
            $rightParts = preg_split('/\s+/', $rightName);
            $rightLastName = trim((string) end($rightParts));
        }

        if (function_exists('mb_strtoupper')) {
            $leftLastName = mb_strtoupper($leftLastName, 'UTF-8');
            $rightLastName = mb_strtoupper($rightLastName, 'UTF-8');
            $leftName = mb_strtoupper($leftName, 'UTF-8');
            $rightName = mb_strtoupper($rightName, 'UTF-8');
        } else {
            $leftLastName = strtoupper($leftLastName);
            $rightLastName = strtoupper($rightLastName);
            $leftName = strtoupper($leftName);
            $rightName = strtoupper($rightName);
        }

        $lastNameCompare = strcmp($leftLastName, $rightLastName);
        if ($lastNameCompare !== 0) {
            return $lastNameCompare;
        }

        $nameCompare = strcmp($leftName, $rightName);
        if ($nameCompare !== 0) {
            return $nameCompare;
        }

        return strcmp(
            trim((string) ($left['examinee_number'] ?? '')),
            trim((string) ($right['examinee_number'] ?? ''))
        );
    });
    $printSection['rows'] = $sectionRows;
}
unset($printSection);
$selectedProgramLabel = 'All Programs';
$selectedProgramCode = '';
foreach ($programOptions as $programOption) {
    if ((int) ($programOption['program_id'] ?? 0) === $programFilter) {
        $selectedProgramLabel = (string) ($programOption['label'] ?? 'All Programs');
        $selectedProgramCode = trim((string) ($programOption['program_code'] ?? ''));
        break;
    }
}
$programProgressRows = [];
$programProgressScopeLabel = $programFilter > 0
    ? ($selectedProgramCode !== '' ? $selectedProgramCode : $selectedProgramLabel)
    : 'All Programs with Scoring or Lock Activity';
$programProgressCampusOptions = [];
$programProgressChartPayload = [
    'rows' => [],
    'campuses' => [],
    'default_campus_id' => 0,
    'scope_label' => $programProgressScopeLabel,
];
if ($storageReady) {
    ensure_program_ranking_locks_table($conn);
    $programProgressRows = student_preregistration_fetch_program_progress_rows($conn, [
        'program_id' => $programFilter,
    ]);

    foreach ($programProgressRows as $programProgressRow) {
        $campusId = (int) ($programProgressRow['campus_id'] ?? 0);
        $campusCode = trim((string) ($programProgressRow['campus_code'] ?? ''));
        $campusName = trim((string) ($programProgressRow['campus_name'] ?? ''));
        $campusLabel = $campusName !== '' ? $campusName : 'Unknown Campus';
        if ($campusCode !== '') {
            $campusLabel = $campusCode . ' - ' . $campusLabel;
        }

        if ($campusId > 0 && !isset($programProgressCampusOptions[$campusId])) {
            $programProgressCampusOptions[$campusId] = [
                'campus_id' => $campusId,
                'label' => $campusLabel,
            ];
        }

        $programCode = trim((string) ($programProgressRow['program_code'] ?? ''));
        if ($programCode === '') {
            $programCode = 'P' . (int) ($programProgressRow['program_id'] ?? 0);
        }

        $programProgressChartPayload['rows'][] = [
            'program_id' => (int) ($programProgressRow['program_id'] ?? 0),
            'program_code' => $programCode,
            'program_label' => (string) ($programProgressRow['program_label'] ?? $programCode),
            'campus_id' => $campusId,
            'campus_label' => $campusLabel,
            'scored_count' => (int) ($programProgressRow['scored_count'] ?? 0),
            'locked_count' => (int) ($programProgressRow['locked_count'] ?? 0),
            'remaining_count' => (int) ($programProgressRow['remaining_count'] ?? 0),
        ];
    }

    $programProgressChartPayload['campuses'] = array_values($programProgressCampusOptions);
    if (count($programProgressCampusOptions) === 1) {
        $singleCampus = reset($programProgressCampusOptions);
        $programProgressChartPayload['default_campus_id'] = (int) ($singleCampus['campus_id'] ?? 0);
    }
}
$programProgressHasData = !empty($programProgressRows);
$programProgressDescription = 'Remaining means inside-capacity students who are not yet locked.';
if ($search !== '') {
    $programProgressDescription .= ' Search text does not change this graph.';
}
$printGeneratedAt = date('F j, Y g:i A');

function format_administrator_prereg_datetime($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'N/A';
    }

    $timestamp = strtotime($raw);
    return ($timestamp !== false) ? date('M j, Y g:i A', $timestamp) : $raw;
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
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Pre-Registrations - Administrator</title>

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
      .prg-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .prg-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .prg-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .prg-filter-hint {
        color: #7b8798;
        font-size: 0.8rem;
      }

      .prg-table td,
      .prg-table th {
        vertical-align: middle;
      }

      .prg-student-name {
        font-weight: 700;
        color: #2f3f59;
      }

      .prg-subline {
        display: block;
        margin-top: 0.14rem;
        color: #7b8798;
        font-size: 0.78rem;
      }

      .prg-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .prg-toolbar-actions {
        display: flex;
        align-items: center;
        gap: 0.75rem;
      }

      .prg-action-stack {
        display: inline-flex;
        flex-direction: column;
        gap: 0.4rem;
        align-items: center;
      }

      .prg-progress-modal-dialog {
        width: min(96vw, 1680px);
        max-width: min(96vw, 1680px);
      }

      .prg-chart-filter-row {
        margin-bottom: 1rem;
      }

      .prg-chart-summary-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.9rem;
        padding: 0.95rem 1rem;
        background: linear-gradient(180deg, #ffffff 0%, #f9fbff 100%);
      }

      .prg-chart-summary-label {
        font-size: 0.76rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #7d8aa3;
      }

      .prg-chart-summary-value {
        margin-top: 0.2rem;
        font-size: 1.65rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .prg-chart-summary-help {
        margin-top: 0.18rem;
        font-size: 0.78rem;
        color: #7b8798;
      }

      .prg-chart-note {
        margin-bottom: 1rem;
        font-size: 0.85rem;
        color: #6b7280;
      }

      .prg-chart-canvas {
        min-height: 430px;
      }

      .prg-chart-empty {
        display: none;
      }

      .prg-print-sheet {
        display: none;
      }

      .prg-print-program-block + .prg-print-program-block {
        break-before: page;
        page-break-before: always;
      }

      .prg-print-cover {
        display: block;
        text-align: center;
        padding: 10mm 10mm 0;
        break-after: page;
        page-break-after: always;
      }

      .prg-print-school {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.15;
        color: #1f2937;
      }

      .prg-print-address {
        margin-top: 0.08rem;
        font-size: 0.82rem;
        line-height: 1.15;
        color: #4b5563;
      }

      .prg-print-title {
        margin-top: 0.5rem;
        font-size: 0.88rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        line-height: 1.15;
        color: #1f2937;
      }

      .prg-print-program {
        margin-top: 0.9rem;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        color: #111827;
      }

      .prg-print-generated {
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.15;
        color: #6b7280;
      }

      .prg-print-count {
        margin-top: 0.3rem;
        font-size: 0.96rem;
        font-weight: 600;
        line-height: 1.15;
        color: #374151;
      }

      .prg-print-list-page {
        padding-top: 0;
      }

      .prg-print-list-head {
        margin-bottom: 0.7rem;
      }

      .prg-print-list-title {
        font-size: 1rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #111827;
      }

      .prg-print-list-subtitle {
        margin-top: 0.2rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: #6b7280;
      }

      .prg-print-table {
        width: calc(100% - 0.8mm);
        margin-right: 0.8mm;
        border-collapse: collapse;
        table-layout: fixed;
        border: 1px solid #9ca3af;
        border-right: 1px solid #9ca3af;
      }

      .prg-print-table th,
      .prg-print-table td {
        border: 1px solid #9ca3af !important;
        padding: 0.42rem 0.5rem;
        vertical-align: top;
      }

      .prg-print-table thead th {
        background: #f3f4f6;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #374151;
      }

      .prg-print-table td {
        font-size: 0.9rem;
        color: #111827;
      }

      .prg-print-table th:last-child,
      .prg-print-table td:last-child {
        border-right: 1px solid #9ca3af !important;
      }

      .prg-print-table tbody tr:last-child td {
        border-bottom: 1px solid #9ca3af !important;
      }

      .prg-print-col-no {
        width: 10mm;
      }

      .prg-print-col-examinee {
        width: 24mm;
      }

      .prg-print-no,
      .prg-print-examinee {
        white-space: nowrap;
      }

      .prg-print-fullname {
        font-weight: 700;
        text-transform: uppercase;
        color: #111827;
      }

      .prg-print-empty {
        border: 1px dashed #cbd5e1;
        border-radius: 0.75rem;
        padding: 1rem 1.1rem;
        color: #64748b;
        background: #f8fafc;
      }

      @media print {
        @page {
          size: A4 portrait;
          margin: 12mm;
        }

        .layout-menu,
        .layout-navbar,
        .content-backdrop,
        .layout-overlay,
        .modal,
        .modal-backdrop,
        .prg-screen-heading,
        .prg-screen-intro,
        .prg-toolbar,
        .prg-stat-row,
        .prg-filter-card,
        .prg-screen-table,
        .alert,
        footer {
          display: none !important;
        }

        .layout-page,
        .content-wrapper,
        .container-xxl {
          margin: 0 !important;
          padding: 0 !important;
          width: 100% !important;
          max-width: 100% !important;
        }

        .prg-print-sheet {
          display: block;
        }

        .content-wrapper,
        .container-xxl {
          background: #fff !important;
        }

        .prg-print-table {
          page-break-inside: auto;
        }

        .prg-print-table tr {
          page-break-inside: avoid;
          page-break-after: auto;
        }

        .prg-print-table thead {
          display: table-header-group;
        }
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
              <h4 class="fw-bold mb-1 prg-screen-heading">
                <span class="text-muted fw-light">Monitoring /</span> Pre-Registrations
              </h4>
              <p class="text-muted mb-4 prg-screen-intro">
                Review students who have already submitted pre-registration and narrow the list by program.
              </p>
              <?php if (is_array($adminStudentPreviewFlash) && !empty($adminStudentPreviewFlash['message'])): ?>
                <?php $studentPreviewAlertType = ((string) ($adminStudentPreviewFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($studentPreviewAlertType); ?> py-2 mb-3">
                  <?= htmlspecialchars((string) $adminStudentPreviewFlash['message']); ?>
                </div>
              <?php endif; ?>
              <?php if (is_array($adminPreregFlash) && !empty($adminPreregFlash['message'])): ?>
                <?php $adminPreregAlertType = ((string) ($adminPreregFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($adminPreregAlertType); ?> py-2 mb-3">
                  <?= htmlspecialchars((string) $adminPreregFlash['message']); ?>
                </div>
              <?php endif; ?>

              <div class="prg-toolbar">
                <div class="prg-toolbar-actions">
                  <button
                    type="button"
                    class="btn btn-outline-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#prgProgramProgressModal"
                    <?= $programProgressHasData ? '' : 'disabled'; ?>
                  >
                    <i class="bx bx-line-chart me-1"></i>Program Graph
                  </button>
                  <button type="button" class="btn btn-outline-secondary prg-print-button" onclick="window.print();">
                    <i class="bx bx-printer me-1"></i>Print
                  </button>
                </div>
                <div class="alert alert-warning mb-0 py-2 px-3">
                  Submitted pre-registrations can be forfeited individually when needed.
                </div>
              </div>

              <?php if (!$storageReady): ?>
                <div class="alert alert-danger">
                  Unable to prepare pre-registration storage. Refresh the page or check database permissions.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4 prg-stat-row">
                <div class="col-md-3 col-6">
                  <div class="prg-stat-card">
                    <div class="prg-stat-label">Students (Filtered)</div>
                    <div class="prg-stat-value"><?= number_format((int) ($summary['total'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="prg-stat-card">
                    <div class="prg-stat-label">Programs</div>
                    <div class="prg-stat-value"><?= number_format((int) ($summary['programs'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="prg-stat-card">
                    <div class="prg-stat-label">Profile 100%</div>
                    <div class="prg-stat-value"><?= number_format((int) ($summary['profile_complete'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="prg-stat-card">
                    <div class="prg-stat-label">Agreement Accepted</div>
                    <div class="prg-stat-value"><?= number_format((int) ($summary['agreement_accepted'] ?? 0)); ?></div>
                  </div>
                </div>
              </div>

              <div class="card prg-filter-card">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-4">
                      <label class="form-label mb-1">Search</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Examinee #, name, campus, program"
                      />
                    </div>
                    <div class="col-lg-4">
                      <label class="form-label mb-1">Program</label>
                      <select name="program_id" class="form-select">
                        <option value="0">All Programs</option>
                        <?php foreach ($programOptions as $programOption): ?>
                          <?php $optionProgramId = (int) ($programOption['program_id'] ?? 0); ?>
                          <option value="<?= $optionProgramId; ?>"<?= $programFilter === $optionProgramId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($programOption['label'] ?? '')); ?>
                            <?php $optionCount = (int) ($programOption['prereg_count'] ?? 0); ?>
                            <?= $optionCount > 0 ? ' (' . number_format($optionCount) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <div class="prg-filter-hint">
                        Showing up to the latest 1,000 pre-registrations.
                      </div>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <button type="submit" class="btn btn-primary">Go</button>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <a href="preregistrations.php" class="btn btn-outline-secondary">Reset</a>
                    </div>
                  </form>
                </div>
              </div>

              <div class="card prg-screen-table">
                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm prg-table">
                      <thead>
                        <tr>
                          <th>Student</th>
                          <th>Program</th>
                          <th>Campus / Interview</th>
                          <th class="text-center">Locked Rank</th>
                          <th class="text-center">Profile</th>
                          <th class="text-center">Agreement</th>
                          <th>Submitted</th>
                          <th class="text-center">Credential</th>
                          <th class="text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($rows)): ?>
                          <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                              No pre-registered students found for the selected filter.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($rows as $row): ?>
                            <?php
                              $currentPercent = (float) ($row['current_profile_completion_percent'] ?? 0);
                              $submittedPercent = (float) ($row['submitted_profile_completion_percent'] ?? 0);
                              $credentialStatus = trim((string) ($row['credential_status'] ?? ''));
                              $needsChange = ((int) ($row['must_change_password'] ?? 0) === 1);
                            ?>
                            <tr>
                              <td>
                                <div class="prg-student-name"><?= htmlspecialchars((string) ($row['full_name'] ?? 'Unknown Student')); ?></div>
                                <small class="prg-subline">Examinee #: <?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?></small>
                                <small class="prg-subline">Classification: <?= htmlspecialchars((string) ($row['classification'] ?? 'N/A')); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($row['program_label'] ?? 'No Program')); ?></div>
                                <small class="prg-subline">Status: <?= htmlspecialchars((string) ($row['status'] ?? 'submitted')); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($row['campus_name'] ?? 'No Campus')); ?></div>
                                <small class="prg-subline">
                                  Interview:
                                  <?= !empty($row['interview_datetime']) ? htmlspecialchars(format_administrator_prereg_datetime((string) $row['interview_datetime'])) : 'No schedule'; ?>
                                </small>
                              </td>
                              <td class="text-center">
                                <?php if ((int) ($row['locked_rank'] ?? 0) > 0): ?>
                                  <span class="badge bg-label-warning">#<?= number_format((int) $row['locked_rank']); ?></span>
                                <?php else: ?>
                                  <span class="badge bg-label-secondary">N/A</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <?php if ($currentPercent >= 100): ?>
                                  <span class="badge bg-label-success"><?= number_format($currentPercent, 0); ?>%</span>
                                <?php else: ?>
                                  <span class="badge bg-label-warning"><?= number_format($currentPercent, 0); ?>%</span>
                                <?php endif; ?>
                                <small class="prg-subline">Submitted: <?= number_format($submittedPercent, 0); ?>%</small>
                              </td>
                              <td class="text-center">
                                <?php if ((int) ($row['agreement_accepted'] ?? 0) === 1): ?>
                                  <span class="badge bg-label-success">Accepted</span>
                                <?php else: ?>
                                  <span class="badge bg-label-danger">Missing</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <div><?= htmlspecialchars(format_administrator_prereg_datetime((string) ($row['submitted_at'] ?? ''))); ?></div>
                                <small class="prg-subline">Updated: <?= htmlspecialchars(format_administrator_prereg_datetime((string) ($row['updated_at'] ?? ''))); ?></small>
                              </td>
                              <td class="text-center">
                                <?php if ($credentialStatus === ''): ?>
                                  <span class="badge bg-label-secondary">None</span>
                                <?php elseif ($needsChange): ?>
                                  <span class="badge bg-label-warning">Needs Change</span>
                                <?php elseif ($credentialStatus === 'active'): ?>
                                  <span class="badge bg-label-success">Active</span>
                                <?php else: ?>
                                  <span class="badge bg-label-danger"><?= htmlspecialchars(ucfirst($credentialStatus)); ?></span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <div class="prg-action-stack">
                                  <?php if ((int) ($row['credential_id'] ?? 0) > 0 && $credentialStatus === 'active'): ?>
                                    <form method="post" action="impersonate_student.php" class="d-inline">
                                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminStudentPreviewCsrf); ?>" />
                                      <input type="hidden" name="credential_id" value="<?= (int) ($row['credential_id'] ?? 0); ?>" />
                                      <input type="hidden" name="return_to" value="<?= htmlspecialchars($adminStudentPreviewReturnTo); ?>" />
                                      <button type="submit" class="btn btn-sm btn-outline-warning">
                                        View as Student
                                      </button>
                                    </form>
                                  <?php else: ?>
                                    <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Active student credential required.">
                                      View as Student
                                    </button>
                                  <?php endif; ?>

                                  <form method="post" class="d-inline js-prg-forfeit-form">
                                    <input type="hidden" name="action" value="forfeit_preregistration" />
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminPreregCsrf); ?>" />
                                    <input type="hidden" name="preregistration_id" value="<?= (int) ($row['preregistration_id'] ?? 0); ?>" />
                                    <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>" />
                                    <input type="hidden" name="preserve_program_filter" value="<?= (int) $programFilter; ?>" />
                                    <button
                                      type="submit"
                                      class="btn btn-sm btn-outline-danger"
                                      data-student-name="<?= htmlspecialchars((string) ($row['full_name'] ?? 'this student')); ?>"
                                    >
                                      Forfeit
                                    </button>
                                  </form>
                                </div>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="modal fade" id="prgProgramProgressModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog prg-progress-modal-dialog modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <div>
                        <h5 class="modal-title mb-1">Program Progress Graph</h5>
                        <div class="text-muted small" id="prgProgramProgressScope"><?= htmlspecialchars($programProgressScopeLabel); ?></div>
                      </div>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <?php if (!$programProgressHasData): ?>
                        <div class="alert alert-warning mb-0">
                          No scored or locked student data is available for the current program filter.
                        </div>
                      <?php else: ?>
                        <div class="row g-3 align-items-end prg-chart-filter-row">
                          <div class="col-xl-4 col-lg-5 col-md-6">
                            <label class="form-label mb-1" for="prgProgramProgressCampusFilter">Campus</label>
                            <select
                              id="prgProgramProgressCampusFilter"
                              class="form-select"
                              <?= count($programProgressCampusOptions) <= 1 ? 'disabled' : ''; ?>
                            >
                              <option value="0">All Campuses</option>
                              <?php foreach ($programProgressCampusOptions as $campusOption): ?>
                                <?php $campusOptionId = (int) ($campusOption['campus_id'] ?? 0); ?>
                                <option value="<?= $campusOptionId; ?>"<?= ((int) ($programProgressChartPayload['default_campus_id'] ?? 0) === $campusOptionId) ? ' selected' : ''; ?>>
                                  <?= htmlspecialchars((string) ($campusOption['label'] ?? 'Unknown Campus')); ?>
                                </option>
                              <?php endforeach; ?>
                            </select>
                          </div>
                          <div class="col-xl-8 col-lg-7 col-md-6">
                            <div class="prg-chart-note mb-0" id="prgProgramProgressDescription">
                              <?= htmlspecialchars($programProgressDescription); ?>
                            </div>
                          </div>
                        </div>

                        <div class="row g-3 mb-3">
                          <div class="col-md-4">
                            <div class="prg-chart-summary-card">
                              <div class="prg-chart-summary-label">Qualified</div>
                              <div class="prg-chart-summary-value" id="prgProgramProgressScoredTotal">0</div>
                              <div class="prg-chart-summary-help">Inside-capacity ranked students currently eligible for pre-registration.</div>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="prg-chart-summary-card">
                              <div class="prg-chart-summary-label">Locked</div>
                              <div class="prg-chart-summary-value" id="prgProgramProgressLockedTotal">0</div>
                              <div class="prg-chart-summary-help">Qualified students already locked in the ranking list.</div>
                            </div>
                          </div>
                          <div class="col-md-4">
                            <div class="prg-chart-summary-card">
                              <div class="prg-chart-summary-label">Remaining</div>
                              <div class="prg-chart-summary-value" id="prgProgramProgressRemainingTotal">0</div>
                              <div class="prg-chart-summary-help">Qualified inside-capacity students still not locked.</div>
                            </div>
                          </div>
                        </div>

                        <div id="prgProgramProgressChartEmpty" class="alert alert-warning prg-chart-empty mb-0">
                          No programs match the selected campus.
                        </div>
                        <div id="prgProgramProgressChart" class="prg-chart-canvas"></div>
                      <?php endif; ?>
                    </div>
                    <div class="modal-footer">
                      <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                    </div>
                  </div>
                </div>
              </div>

              <div class="prg-print-sheet">
                <?php if (empty($printSections)): ?>
                  <div class="prg-print-empty">No pre-registered students found for the selected filter.</div>
                <?php else: ?>
                  <?php foreach ($printSections as $section): ?>
                    <?php $sectionRows = (array) ($section['rows'] ?? []); ?>
                    <section class="prg-print-program-block">
                      <div class="prg-print-cover">
                        <div class="prg-print-school"><?= htmlspecialchars((string) ($printHeader['institution'] ?? 'Sultan Kudarat State University')); ?></div>
                        <div class="prg-print-address"><?= htmlspecialchars((string) ($printHeader['address'] ?? '')); ?></div>
                        <div class="prg-print-program"><?= htmlspecialchars((string) ($section['program_label'] ?? 'No Program')); ?></div>
                        <div class="prg-print-count">Total Pre-Registered: <?= number_format(count($sectionRows)); ?></div>
                        <div class="prg-print-generated">Generated on <?= htmlspecialchars($printGeneratedAt); ?></div>
                      </div>

                      <div class="prg-print-list-page">
                        <div class="prg-print-list-head">
                          <div class="prg-print-list-title"><?= htmlspecialchars((string) ($section['program_label'] ?? 'No Program')); ?></div>
                          <div class="prg-print-list-subtitle">Alphabetical pre-registration list | <?= number_format(count($sectionRows)); ?> student<?= count($sectionRows) === 1 ? '' : 's'; ?></div>
                        </div>

                        <table class="prg-print-table">
                          <colgroup>
                            <col class="prg-print-col-no" />
                            <col class="prg-print-col-examinee" />
                            <col />
                          </colgroup>
                          <thead>
                            <tr>
                              <th>No.</th>
                              <th>Examinee Number</th>
                              <th>Full Name</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($sectionRows as $index => $printRow): ?>
                              <tr>
                                <td class="prg-print-no"><?= number_format($index + 1); ?></td>
                                <td class="prg-print-examinee"><?= htmlspecialchars((string) ($printRow['examinee_number'] ?? '')); ?></td>
                                <td class="prg-print-fullname"><?= htmlspecialchars((string) ($printRow['full_name'] ?? 'Unknown Student')); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    </section>
                  <?php endforeach; ?>
                <?php endif; ?>
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
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const modalEl = document.getElementById('prgProgramProgressModal');
        const chartEl = document.getElementById('prgProgramProgressChart');
        const chartEmptyEl = document.getElementById('prgProgramProgressChartEmpty');
        const campusFilterEl = document.getElementById('prgProgramProgressCampusFilter');
        const scopeEl = document.getElementById('prgProgramProgressScope');
        const descriptionEl = document.getElementById('prgProgramProgressDescription');
        const scoredTotalEl = document.getElementById('prgProgramProgressScoredTotal');
        const lockedTotalEl = document.getElementById('prgProgramProgressLockedTotal');
        const remainingTotalEl = document.getElementById('prgProgramProgressRemainingTotal');
        const progressPayload = <?= json_encode($programProgressChartPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        const baseDescription = <?= json_encode($programProgressDescription, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>;
        let progressChart = null;
        let activeChartRows = [];

        function toNumber(value) {
          const numericValue = Number(value);
          return Number.isFinite(numericValue) ? numericValue : 0;
        }

        function formatWholeNumber(value) {
          return Math.round(toNumber(value)).toLocaleString();
        }

        function formatStudentCount(value) {
          const roundedValue = Math.round(toNumber(value));
          return `${formatWholeNumber(roundedValue)} student${roundedValue === 1 ? '' : 's'}`;
        }

        function setMetricValue(element, value) {
          if (!element) {
            return;
          }

          element.textContent = formatWholeNumber(value);
        }

        function getCampusLabel(campusId) {
          const normalizedCampusId = Math.max(0, Number(campusId || 0));
          if (normalizedCampusId <= 0) {
            return 'All Campuses';
          }

          const campuses = Array.isArray(progressPayload.campuses) ? progressPayload.campuses : [];
          const match = campuses.find(function (campus) {
            return Number(campus && campus.campus_id) === normalizedCampusId;
          });

          return match && match.label ? String(match.label) : 'Selected Campus';
        }

        function getSelectedCampusId() {
          if (!campusFilterEl) {
            return Math.max(0, Number(progressPayload.default_campus_id || 0));
          }

          return Math.max(0, Number(campusFilterEl.value || 0));
        }

        function getFilteredRows() {
          const rows = Array.isArray(progressPayload.rows) ? progressPayload.rows : [];
          const selectedCampusId = getSelectedCampusId();

          return rows.filter(function (row) {
            if (selectedCampusId <= 0) {
              return true;
            }

            return Number(row && row.campus_id) === selectedCampusId;
          });
        }

        function computeTotals(rows) {
          return rows.reduce(function (totals, row) {
            totals.scored += Math.max(0, Number(row && row.scored_count || 0));
            totals.locked += Math.max(0, Number(row && row.locked_count || 0));
            totals.remaining += Math.max(0, Number(row && row.remaining_count || 0));
            return totals;
          }, { scored: 0, locked: 0, remaining: 0 });
        }

        function buildScopeText(campusId) {
          const baseScope = String(progressPayload.scope_label || 'Program Progress Graph');
          if (campusId <= 0) {
            return `${baseScope} | All Campuses`;
          }

          return `${baseScope} | ${getCampusLabel(campusId)}`;
        }

        function buildDescriptionText(rows, campusId) {
          const campusText = campusId > 0
            ? `Showing ${getCampusLabel(campusId)} only.`
            : 'Showing all campuses.';
          const programCount = rows.length;
          return `${campusText} ${formatWholeNumber(programCount)} program${programCount === 1 ? '' : 's'} in the graph. ${baseDescription}`;
        }

        function setChartEmptyState(isEmpty) {
          if (chartEmptyEl) {
            chartEmptyEl.style.display = isEmpty ? 'block' : 'none';
          }

          if (chartEl) {
            chartEl.style.display = isEmpty ? 'none' : 'block';
          }
        }

        function buildSeries(rows) {
          return [
            {
              name: 'Qualified',
              data: rows.map(function (row) { return Math.max(0, Number(row && row.scored_count || 0)); })
            },
            {
              name: 'Locked',
              data: rows.map(function (row) { return Math.max(0, Number(row && row.locked_count || 0)); })
            },
            {
              name: 'Remaining',
              data: rows.map(function (row) { return Math.max(0, Number(row && row.remaining_count || 0)); })
            }
          ];
        }

        function buildProgramProgressChart(rows) {
          if (!chartEl || typeof ApexCharts === 'undefined') {
            return null;
          }

          activeChartRows = rows.slice();

          return new ApexCharts(chartEl, {
            chart: {
              type: 'line',
              height: 440,
              fontFamily: 'Public Sans, sans-serif',
              toolbar: { show: false },
              zoom: { enabled: false },
              animations: {
                enabled: true,
                easing: 'easeinout',
                speed: 480
              }
            },
            series: buildSeries(rows),
            colors: ['#0d6efd', '#ffab00', '#71dd37'],
            stroke: {
              curve: 'smooth',
              width: 3
            },
            markers: {
              size: 5,
              hover: {
                sizeOffset: 2
              }
            },
            dataLabels: {
              enabled: rows.length === 1,
              formatter: function (value) {
                return formatWholeNumber(value);
              }
            },
            legend: {
              position: 'top',
              horizontalAlign: 'left'
            },
            grid: {
              borderColor: '#e6ebf3',
              strokeDashArray: 4
            },
            xaxis: {
              categories: rows.map(function (row) {
                return String(row && row.program_code || row && row.program_label || 'Program');
              }),
              axisBorder: { show: false },
              axisTicks: { show: false },
              labels: {
                rotate: 0,
                trim: false,
                hideOverlappingLabels: false,
                style: {
                  colors: '#7d8aa3',
                  fontSize: '11px'
                }
              }
            },
            yaxis: {
              min: 0,
              forceNiceScale: true,
              labels: {
                formatter: function (value) {
                  return formatWholeNumber(value);
                }
              },
              title: {
                text: 'Students'
              }
            },
            tooltip: {
              shared: true,
              intersect: false,
              x: {
                formatter: function (value, context) {
                  const row = activeChartRows[Number(context && context.dataPointIndex)];
                  if (!row) {
                    return String(value || '');
                  }

                  return `${String(row.program_code || value || '')} | ${String(row.program_label || '')} | ${String(row.campus_label || '')}`;
                }
              },
              y: {
                formatter: function (value) {
                  return formatStudentCount(value);
                }
              }
            },
            noData: {
              text: 'No program progress data available.'
            }
          });
        }

        function renderProgramProgressChart() {
          const rows = getFilteredRows();
          const selectedCampusId = getSelectedCampusId();
          const totals = computeTotals(rows);

          activeChartRows = rows.slice();
          setMetricValue(scoredTotalEl, totals.scored);
          setMetricValue(lockedTotalEl, totals.locked);
          setMetricValue(remainingTotalEl, totals.remaining);

          if (scopeEl) {
            scopeEl.textContent = buildScopeText(selectedCampusId);
          }

          if (descriptionEl) {
            descriptionEl.textContent = buildDescriptionText(rows, selectedCampusId);
          }

          if (rows.length === 0) {
            setChartEmptyState(true);
            if (progressChart) {
              progressChart.destroy();
              progressChart = null;
            }
            return;
          }

          setChartEmptyState(false);

          if (!progressChart) {
            progressChart = buildProgramProgressChart(rows);
            if (progressChart) {
              progressChart.render();
            }
            return;
          }

          progressChart.updateOptions({
            dataLabels: {
              enabled: rows.length === 1,
              formatter: function (value) {
                return formatWholeNumber(value);
              }
            },
            xaxis: {
              categories: rows.map(function (row) {
                return String(row && row.program_code || row && row.program_label || 'Program');
              }),
              axisBorder: { show: false },
              axisTicks: { show: false },
              labels: {
                rotate: 0,
                trim: false,
                hideOverlappingLabels: false,
                style: {
                  colors: '#7d8aa3',
                  fontSize: '11px'
                }
              }
            },
            tooltip: {
              shared: true,
              intersect: false,
              x: {
                formatter: function (value, context) {
                  const row = activeChartRows[Number(context && context.dataPointIndex)];
                  if (!row) {
                    return String(value || '');
                  }

                  return `${String(row.program_code || value || '')} | ${String(row.program_label || '')} | ${String(row.campus_label || '')}`;
                }
              },
              y: {
                formatter: function (value) {
                  return formatStudentCount(value);
                }
              }
            }
          });
          progressChart.updateSeries(buildSeries(rows));
        }

        if (
          !modalEl
          || !chartEl
          || !Array.isArray(progressPayload.rows)
          || progressPayload.rows.length === 0
        ) {
          return;
        }

        if (campusFilterEl && Number(progressPayload.default_campus_id || 0) > 0) {
          campusFilterEl.value = String(Number(progressPayload.default_campus_id || 0));
        }

        modalEl.addEventListener('shown.bs.modal', function () {
          renderProgramProgressChart();
        });

        modalEl.addEventListener('hidden.bs.modal', function () {
          if (!progressChart) {
            return;
          }

          progressChart.destroy();
          progressChart = null;
        });

        if (campusFilterEl) {
          campusFilterEl.addEventListener('change', function () {
            if (!modalEl.classList.contains('show')) {
              return;
            }

            renderProgramProgressChart();
          });
        }
      });
    </script>
    <script>
      (function () {
        Array.from(document.querySelectorAll('.js-prg-forfeit-form')).forEach(function (formEl) {
          formEl.addEventListener('submit', function (event) {
            const submitButton = formEl.querySelector('button[type="submit"]');
            const studentName = submitButton && submitButton.dataset
              ? String(submitButton.dataset.studentName || 'this student')
              : 'this student';

            if (!window.confirm(`Forfeit pre-registration for ${studentName}? This will remove the submission from active pre-registration lists.`)) {
              event.preventDefault();
            }
          });
        });
      })();
    </script>
  </body>
</html>
