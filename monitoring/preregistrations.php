<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'monitoring')) {
    header('Location: ../index.php');
    exit;
}

$monitoringHeaderTitle = 'Monitoring Center - Pre-Registrations';
$search = trim((string) ($_GET['q'] ?? ''));
$programFilter = (int) ($_GET['program_id'] ?? 0);
$storageReady = ensure_student_preregistration_storage($conn);
$programOptions = $storageReady ? student_preregistration_fetch_program_options($conn) : [];
$deleteableProgramOptions = [];
foreach ($programOptions as $programOption) {
    if ((int) ($programOption['prereg_count'] ?? 0) > 0) {
        $deleteableProgramOptions[] = $programOption;
    }
}
$flashMessage = null;

if (empty($_SESSION['monitoring_prereg_csrf'])) {
    try {
        $_SESSION['monitoring_prereg_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['monitoring_prereg_csrf'] = sha1(uniqid('monitoring_prereg_', true));
    }
}
$monitoringPreregCsrf = (string) $_SESSION['monitoring_prereg_csrf'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'forfeit_preregistration') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $preregistrationId = (int) ($_POST['preregistration_id'] ?? 0);
    $preserveSearch = trim((string) ($_POST['search'] ?? ''));
    $preserveProgramFilter = (int) ($_POST['preserve_program_filter'] ?? 0);

    if ($postedCsrf === '' || !hash_equals($monitoringPreregCsrf, $postedCsrf)) {
        $_SESSION['monitoring_prereg_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid request token. Refresh the page and try again.',
        ];
    } else {
        $forfeitResult = student_preregistration_forfeit(
            $conn,
            $preregistrationId,
            (int) ($_SESSION['accountid'] ?? 0)
        );
        $_SESSION['monitoring_prereg_flash'] = [
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'remove_by_programs') {
    $deleteSearch = trim((string) ($_POST['search'] ?? ''));
    $preserveProgramFilter = (int) ($_POST['preserve_program_filter'] ?? 0);
    $_SESSION['monitoring_prereg_flash'] = [
        'type' => 'danger',
        'message' => 'Submitted pre-registrations are protected. Removal from Monitoring is disabled.',
    ];

    $redirectParams = [];
    if ($preserveProgramFilter > 0) {
        $redirectParams['program_id'] = $preserveProgramFilter;
    }
    if ($deleteSearch !== '') {
        $redirectParams['q'] = $deleteSearch;
    }
    $nextUrl = 'preregistrations.php' . (empty($redirectParams) ? '' : '?' . http_build_query($redirectParams));
    header('Location: ' . $nextUrl);
    exit;
}

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
$printSections = student_preregistration_build_print_campus_sections($rows);
foreach ($printSections as &$printCampusSection) {
    foreach ($printCampusSection['programs'] as &$printProgramSection) {
        $sectionRows = is_array($printProgramSection['rows'] ?? null) ? $printProgramSection['rows'] : [];
        usort($sectionRows, 'monitoring_prereg_compare_students_by_last_name');
        $printProgramSection['rows'] = $sectionRows;
    }
    unset($printProgramSection);
}
unset($printCampusSection);
$monitoringPreregFlash = $_SESSION['monitoring_prereg_flash'] ?? null;
if (is_array($monitoringPreregFlash) && isset($monitoringPreregFlash['message'])) {
    $flashMessage = [
        'type' => ((string) ($monitoringPreregFlash['type'] ?? 'success') === 'danger') ? 'danger' : 'success',
        'message' => (string) $monitoringPreregFlash['message'],
    ];
}
unset($_SESSION['monitoring_prereg_flash']);
$printGeneratedAt = date('F j, Y g:i A');

function format_monitoring_prereg_datetime($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'N/A';
    }

    $timestamp = strtotime($raw);
    return ($timestamp !== false) ? date('M j, Y g:i A', $timestamp) : $raw;
}

function monitoring_prereg_compare_students_by_last_name(array $left, array $right): int
{
    $leftName = trim((string) ($left['full_name'] ?? ''));
    $rightName = trim((string) ($right['full_name'] ?? ''));
    $leftLastName = monitoring_prereg_extract_last_name($leftName);
    $rightLastName = monitoring_prereg_extract_last_name($rightName);

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
}

function monitoring_prereg_extract_last_name(string $name): string
{
    $name = trim($name);
    if ($name === '') {
        return '';
    }

    if (strpos($name, ',') !== false) {
        return trim((string) strstr($name, ',', true));
    }

    $parts = preg_split('/\s+/', $name);
    return trim((string) end($parts));
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
    <title>Monitoring Pre-Registrations - Interview</title>

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .mpr-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .mpr-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .mpr-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .mpr-filter-hint {
        color: #7b8798;
        font-size: 0.8rem;
      }

      .mpr-table td,
      .mpr-table th {
        vertical-align: middle;
      }

      .mpr-student-name {
        font-weight: 700;
        color: #2f3f59;
      }

      .mpr-subline {
        display: block;
        margin-top: 0.14rem;
        color: #7b8798;
        font-size: 0.78rem;
      }

      .mpr-toolbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .mpr-delete-form {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        margin-bottom: 0;
      }

      .mpr-delete-select {
        width: 360px;
        max-width: 100%;
      }

      .mpr-delete-select .mpr-delete-select-option {
        display: flex;
        align-items: center;
        gap: 0.45rem;
      }

      .mpr-delete-select .mpr-delete-select-option input[type='checkbox'] {
        cursor: default;
        pointer-events: none;
      }

      .mpr-print-sheet {
        display: none;
      }

      .mpr-print-program-block + .mpr-print-program-block {
        margin-top: 7mm;
        break-before: auto;
        page-break-before: auto;
      }

      .mpr-print-campus-block + .mpr-print-campus-block {
        break-before: page;
        page-break-before: always;
      }

      .mpr-print-cover {
        display: block;
        text-align: center;
        padding: 0 0 5mm;
        margin-bottom: 5mm;
        border-bottom: 1px solid #d1d5db;
        break-after: avoid;
        page-break-after: avoid;
      }

      .mpr-print-school {
        font-size: 1.15rem;
        font-weight: 700;
        line-height: 1.15;
        color: #1f2937;
      }

      .mpr-print-address {
        margin-top: 0.08rem;
        font-size: 0.82rem;
        line-height: 1.15;
        color: #4b5563;
      }

      .mpr-print-program {
        margin-top: 0.55rem;
        font-size: 1.28rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        color: #111827;
      }

      .mpr-print-campus {
        margin-top: 0.9rem;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.2;
        text-transform: uppercase;
        color: #111827;
      }

      .mpr-print-count {
        margin-top: 0.3rem;
        font-size: 0.96rem;
        font-weight: 600;
        line-height: 1.15;
        color: #374151;
      }

      .mpr-print-generated {
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.15;
        color: #6b7280;
      }

      .mpr-print-list-page {
        padding-top: 0;
      }

      .mpr-print-list-head {
        margin-bottom: 0.7rem;
        break-after: avoid;
        page-break-after: avoid;
      }

      .mpr-print-list-title {
        font-size: 1rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #111827;
      }

      .mpr-print-list-subtitle {
        margin-top: 0.2rem;
        font-size: 0.82rem;
        font-weight: 600;
        color: #6b7280;
      }

      .mpr-print-table {
        width: calc(100% - 0.8mm);
        margin-right: 0.8mm;
        border-collapse: collapse;
        table-layout: fixed;
        border: 1px solid #9ca3af;
        border-right: 1px solid #9ca3af;
      }

      .mpr-print-table th,
      .mpr-print-table td {
        border: 1px solid #9ca3af !important;
        padding: 0.42rem 0.5rem;
        vertical-align: top;
      }

      .mpr-print-table thead th {
        background: #f3f4f6;
        font-size: 0.75rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #374151;
      }

      .mpr-print-table td {
        font-size: 0.9rem;
        color: #111827;
      }

      .mpr-print-table th:last-child,
      .mpr-print-table td:last-child {
        border-right: 1px solid #9ca3af !important;
      }

      .mpr-print-table tbody tr:last-child td {
        border-bottom: 1px solid #9ca3af !important;
      }

      .mpr-print-col-no {
        width: 10mm;
      }

      .mpr-print-col-examinee {
        width: 24mm;
      }

      .mpr-print-no,
      .mpr-print-examinee {
        white-space: nowrap;
      }

      .mpr-print-fullname {
        font-weight: 700;
        text-transform: uppercase;
        color: #111827;
      }

      .mpr-print-empty {
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
        .mpr-toolbar,
        .mpr-stat-row,
        .mpr-filter-card,
        .mpr-screen-table,
        .mpr-page-title,
        .mpr-page-subtitle,
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

        .mpr-print-sheet {
          display: block;
        }

        .content-wrapper,
        .container-xxl {
          background: #fff !important;
        }

        .mpr-print-table {
          page-break-inside: auto;
        }

        .mpr-print-table tr {
          page-break-inside: avoid;
          page-break-after: auto;
        }

        .mpr-print-table thead {
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
              <h4 class="fw-bold mb-1 mpr-page-title">
                <span class="text-muted fw-light">Monitoring /</span> Pre-Registrations
              </h4>
              <p class="text-muted mb-4 mpr-page-subtitle">
                Read-only list of submitted pre-registrations with program-based filtering.
              </p>

              <div class="mpr-toolbar">
                <button type="button" class="btn btn-outline-secondary mpr-print-button" onclick="window.print();">
                  <i class="bx bx-printer me-1"></i>Print
                </button>
                <div class="alert alert-warning mb-0 py-2 px-3">
                  Submitted pre-registrations can now be forfeited individually when needed.
                </div>
              </div>

              <?php if (!$storageReady): ?>
                <div class="alert alert-danger">
                  Unable to prepare pre-registration storage. Refresh the page or check database permissions.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4 mpr-stat-row">
                <div class="col-md-3 col-6">
                  <div class="mpr-stat-card">
                    <div class="mpr-stat-label">Students (Filtered)</div>
                    <div class="mpr-stat-value"><?= number_format((int) ($summary['total'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mpr-stat-card">
                    <div class="mpr-stat-label">Programs</div>
                    <div class="mpr-stat-value"><?= number_format((int) ($summary['programs'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mpr-stat-card">
                    <div class="mpr-stat-label">Profile 100%</div>
                    <div class="mpr-stat-value"><?= number_format((int) ($summary['profile_complete'] ?? 0)); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mpr-stat-card">
                    <div class="mpr-stat-label">Agreement Accepted</div>
                    <div class="mpr-stat-value"><?= number_format((int) ($summary['agreement_accepted'] ?? 0)); ?></div>
                  </div>
                </div>
              </div>

              <div class="card mpr-filter-card">
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
                          <?php $optionCount = (int) ($programOption['prereg_count'] ?? 0); ?>
                          <?php if ($optionCount <= 0) continue; ?>
                          <option value="<?= $optionProgramId; ?>"<?= $programFilter === $optionProgramId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($programOption['label'] ?? '')); ?>
                            <?= $optionCount > 0 ? ' (' . number_format($optionCount) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <div class="mpr-filter-hint">
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

              <div class="card mpr-screen-table">
                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm mpr-table">
                      <thead>
                        <tr>
                          <th>Student</th>
                          <th>Program</th>
                          <th>Campus / Interview</th>
                          <th class="text-center">Locked Rank</th>
                          <th class="text-center">Profile</th>
                          <th class="text-center">Agreement</th>
                          <th>Submitted</th>
                          <th class="text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($rows)): ?>
                          <tr>
                            <td colspan="8" class="text-center text-muted py-4">
                              No pre-registered students found for the selected filter.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($rows as $row): ?>
                            <?php
                              $currentPercent = (float) ($row['current_profile_completion_percent'] ?? 0);
                              $submittedPercent = (float) ($row['submitted_profile_completion_percent'] ?? 0);
                            ?>
                            <tr>
                              <td>
                                <div class="mpr-student-name"><?= htmlspecialchars((string) ($row['full_name'] ?? 'Unknown Student')); ?></div>
                                <small class="mpr-subline">Examinee #: <?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?></small>
                                <small class="mpr-subline">Classification: <?= htmlspecialchars((string) ($row['classification'] ?? 'N/A')); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($row['program_label'] ?? 'No Program')); ?></div>
                                <small class="mpr-subline">Status: <?= htmlspecialchars((string) ($row['status'] ?? 'submitted')); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($row['campus_name'] ?? 'No Campus')); ?></div>
                                <small class="mpr-subline">
                                  Interview:
                                  <?= !empty($row['interview_datetime']) ? htmlspecialchars(format_monitoring_prereg_datetime((string) $row['interview_datetime'])) : 'No schedule'; ?>
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
                                <small class="mpr-subline">Submitted: <?= number_format($submittedPercent, 0); ?>%</small>
                              </td>
                              <td class="text-center">
                                <?php if ((int) ($row['agreement_accepted'] ?? 0) === 1): ?>
                                  <span class="badge bg-label-success">Accepted</span>
                                <?php else: ?>
                                  <span class="badge bg-label-danger">Missing</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <div><?= htmlspecialchars(format_monitoring_prereg_datetime((string) ($row['submitted_at'] ?? ''))); ?></div>
                                <small class="mpr-subline">Updated: <?= htmlspecialchars(format_monitoring_prereg_datetime((string) ($row['updated_at'] ?? ''))); ?></small>
                              </td>
                              <td class="text-center">
                                <form method="post" class="d-inline js-mpr-forfeit-form">
                                  <input type="hidden" name="action" value="forfeit_preregistration" />
                                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($monitoringPreregCsrf); ?>" />
                                  <input type="hidden" name="preregistration_id" value="<?= (int) ($row['preregistration_id'] ?? 0); ?>" />
                                  <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>" />
                                  <input type="hidden" name="preserve_program_filter" value="<?= (int) $programFilter; ?>" />
                                  <input
                                    type="submit"
                                    class="btn btn-sm btn-outline-danger"
                                    value="Forfeit"
                                    data-student-name="<?= htmlspecialchars((string) ($row['full_name'] ?? 'this student')); ?>"
                                  />
                                </form>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="mpr-print-sheet">
                <?php if (empty($printSections)): ?>
                  <div class="mpr-print-empty">No pre-registered students found for the selected filter.</div>
                <?php else: ?>
                  <?php foreach ($printSections as $campusSection): ?>
                    <?php
                      $campusPrograms = (array) ($campusSection['programs'] ?? []);
                      $campusTotal = (int) ($campusSection['total'] ?? 0);
                    ?>
                    <section class="mpr-print-campus-block">
                      <div class="mpr-print-cover">
                        <div class="mpr-print-school"><?= htmlspecialchars((string) ($printHeader['institution'] ?? 'Sultan Kudarat State University')); ?></div>
                        <div class="mpr-print-address"><?= htmlspecialchars((string) ($printHeader['address'] ?? '')); ?></div>
                        <div class="mpr-print-campus"><?= htmlspecialchars((string) ($campusSection['campus_name'] ?? 'No Campus')); ?></div>
                        <div class="mpr-print-count">Total Pre-Registered: <?= number_format($campusTotal); ?></div>
                        <div class="mpr-print-generated">Generated on <?= htmlspecialchars($printGeneratedAt); ?></div>
                      </div>

                      <?php foreach ($campusPrograms as $programSection): ?>
                        <?php $sectionRows = (array) ($programSection['rows'] ?? []); ?>
                        <section class="mpr-print-program-block">
                          <div class="mpr-print-list-page">
                            <div class="mpr-print-list-head">
                              <div class="mpr-print-list-title"><?= htmlspecialchars((string) ($programSection['program_label'] ?? 'No Program')); ?></div>
                              <div class="mpr-print-list-subtitle">Alphabetical pre-registration list | <?= number_format(count($sectionRows)); ?> student<?= count($sectionRows) === 1 ? '' : 's'; ?></div>
                            </div>

                            <table class="mpr-print-table">
                              <colgroup>
                                <col class="mpr-print-col-no" />
                                <col class="mpr-print-col-examinee" />
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
                                    <td class="mpr-print-no"><?= number_format($index + 1); ?></td>
                                    <td class="mpr-print-examinee"><?= htmlspecialchars((string) ($printRow['examinee_number'] ?? '')); ?></td>
                                    <td class="mpr-print-fullname"><?= htmlspecialchars((string) ($printRow['full_name'] ?? 'Unknown Student')); ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        </section>
                      <?php endforeach; ?>
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
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      (function () {
        const deleteForm = document.getElementById('mprDeleteProgramForm');
        const deleteSelect = document.getElementById('mprProgramDeleteSelect');
        const deleteBtn = document.getElementById('mprDeleteProgramBtn');
        const hasFlashMessage = <?= $flashMessage ? 'true' : 'false'; ?>;
        const flashPayload = <?= json_encode($flashMessage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        const isSwalAvailable = typeof Swal !== 'undefined';

        function showSwalAlert(config) {
          if (!isSwalAvailable) {
            return Promise.resolve({
              isConfirmed: confirm(config.text || 'Confirm action')
            });
          }

          return Swal.fire(config);
        }

        function showSwalWarning(title, text, buttonLabel) {
          return showSwalAlert({
            icon: 'warning',
            title: title,
            text: text,
            confirmButtonText: buttonLabel || 'OK',
            confirmButtonColor: '#696cff'
          });
        }

        function updateDeleteButtonState() {
          if (!deleteSelect || !deleteBtn) {
            return;
          }

          const selectedCount = (deleteSelect && deleteSelect.selectedOptions)
            ? deleteSelect.selectedOptions.length
            : 0;
          deleteBtn.disabled = selectedCount <= 0;
        }

        function formatSelectOption(option) {
          if (option.element === undefined) {
            return option.text;
          }

          const isChecked = (option.selected || option.element && option.element.selected) ? 'checked' : '';
          const container = document.createElement('span');
          const check = document.createElement('input');
          const label = document.createElement('span');

          container.className = 'mpr-delete-select-option';
          check.type = 'checkbox';
          check.disabled = true;
          if (isChecked === 'checked') {
            check.checked = true;
          }
          label.textContent = option.text;

          container.appendChild(check);
          container.appendChild(label);
          return container;
        }

        if (deleteForm && typeof $ !== 'undefined' && $.fn && $.fn.select2) {
          const $deleteSelect = $('#mprProgramDeleteSelect');
          if ($deleteSelect.length) {
            $deleteSelect.select2({
              width: '100%',
              placeholder: $deleteSelect.data('placeholder') || 'Select programs to remove',
              closeOnSelect: false,
              templateResult: formatSelectOption
            });

            $deleteSelect.on('change', function () {
              updateDeleteButtonState();
            });

            $deleteSelect.on('select2:select select2:unselect', function () {
              updateDeleteButtonState();
            });

            updateDeleteButtonState();
          }
        }

        if (deleteForm) {
          deleteForm.addEventListener('submit', function (event) {
            event.preventDefault();
            const selectedProgramIds = Array.from((deleteSelect && deleteSelect.selectedOptions) || [])
              .map((option) => option.value)
              .filter((value) => String(value).trim() !== '');

            if (selectedProgramIds.length <= 0) {
              showSwalWarning('No program selected', 'Select at least one program with pre-registrations first.');
              return;
            }

            const programCount = Number(deleteForm.dataset.programCount || 0);
            if (programCount <= 0) {
              showSwalWarning('Nothing to remove', 'No programs with pre-registrations are available for deletion.');
              return;
            }

            showSwalAlert({
              icon: 'warning',
              title: 'Confirm removal',
              html: `${selectedProgramIds.length} program(s) selected. This action cannot be undone.`,
              showCancelButton: true,
              confirmButtonText: 'Delete',
              cancelButtonText: 'Cancel',
              confirmButtonColor: '#ff3e1d',
              cancelButtonColor: '#6c757d',
              reverseButtons: true
            }).then((result) => {
              if (result.isConfirmed) {
                deleteForm.submit();
              }
            });
          });
        }

        Array.from(document.querySelectorAll('.js-mpr-forfeit-form')).forEach(function (formEl) {
          formEl.addEventListener('submit', function (event) {
            event.preventDefault();

            const submitButton = formEl.querySelector('input[type="submit"], button[type="submit"]');
            const studentName = submitButton && submitButton.dataset
              ? String(submitButton.dataset.studentName || 'this student')
              : 'this student';

            showSwalAlert({
              icon: 'warning',
              title: 'Forfeit pre-registration?',
              text: `This will mark ${studentName} as forfeited and remove the submission from active pre-registration lists.`,
              showCancelButton: true,
              confirmButtonText: 'Forfeit',
              cancelButtonText: 'Cancel',
              confirmButtonColor: '#ff3e1d',
              cancelButtonColor: '#6c757d',
              reverseButtons: true
            }).then((result) => {
              if (result.isConfirmed) {
                formEl.submit();
              }
            });
          });
        });

        if (hasFlashMessage && flashPayload) {
          if (!isSwalAvailable) {
            if (flashPayload.type === 'danger') {
              alert(flashPayload.message || 'Error');
            } else {
              alert(flashPayload.message || 'Done');
            }
            return;
          }

          Swal.fire({
            icon: flashPayload.type === 'danger' ? 'error' : 'success',
            title: flashPayload.type === 'danger' ? 'Error' : 'Done',
            text: String(flashPayload.message || ''),
            confirmButtonColor: flashPayload.type === 'danger' ? '#ff3e1d' : '#696cff'
          });
        }
      })();
    </script>
    </body>
  </html>
