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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'remove_by_programs') {
    $deleteProgramIds = (array) ($_POST['program_ids'] ?? []);
    $deleteSearch = trim((string) ($_POST['search'] ?? ''));
    $preserveProgramFilter = (int) ($_POST['preserve_program_filter'] ?? 0);
    $allowedDeleteProgramIds = [];
    $allowedDeleteProgramNames = [];

    foreach ($deleteableProgramOptions as $option) {
        $optionProgramId = (int) ($option['program_id'] ?? 0);
        if ($optionProgramId > 0) {
            $allowedDeleteProgramIds[] = $optionProgramId;
            $allowedDeleteProgramNames[$optionProgramId] = (string) ($option['label'] ?? ('Program #' . $optionProgramId));
        }
    }

    $normalizedDeleteProgramIds = [];
    foreach ($deleteProgramIds as $deleteProgramId) {
        $programId = (int) $deleteProgramId;
        if ($programId > 0) {
            $normalizedDeleteProgramIds[] = $programId;
        }
    }
    $normalizedDeleteProgramIds = array_values(array_unique($normalizedDeleteProgramIds));
    $deletableProgramIds = array_values(array_intersect($normalizedDeleteProgramIds, $allowedDeleteProgramIds));

    if (!$storageReady) {
        $_SESSION['monitoring_prereg_flash'] = [
            'type' => 'danger',
            'message' => 'Pre-registration storage is not ready. Please refresh and try again.',
        ];
    } elseif (empty($deletableProgramIds)) {
        $_SESSION['monitoring_prereg_flash'] = [
            'type' => 'danger',
            'message' => 'Select at least one program with pre-registrations before removing.',
        ];
    } else {
        $deleteResult = student_preregistration_delete_by_programs($conn, $deletableProgramIds);
        $selectedProgramNames = array_values(array_filter(array_map(function (int $programId): string {
            return (string) ($allowedDeleteProgramNames[$programId] ?? ('Program #' . $programId));
        }, $deletableProgramIds)));

        if ((bool) ($deleteResult['success'] ?? false)) {
            $deletedCount = (int) ($deleteResult['deleted'] ?? 0);
            $programNamesText = implode(', ', $selectedProgramNames);
            $deleteMessage = $deletedCount > 0
                ? "{$deletedCount} preregistration(s) removed from: {$programNamesText}."
                : "No pre-registrations found for selected program(s).";
            $_SESSION['monitoring_prereg_flash'] = [
                'type' => 'success',
                'message' => $deleteMessage,
            ];
        } else {
            $_SESSION['monitoring_prereg_flash'] = [
                'type' => 'danger',
                'message' => (string) ($deleteResult['message'] ?? 'Unable to remove pre-registrations.'),
            ];
        }
    }

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
$printSections = student_preregistration_build_print_sections($rows);
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

      .mpr-print-header {
        text-align: center;
        margin-bottom: 0.45rem;
      }

      .mpr-print-school {
        font-size: 1.1rem;
        font-weight: 700;
        color: #1f2937;
        margin-bottom: 0;
      }

      .mpr-print-address {
        margin-top: 0.05rem;
        margin-bottom: 0.05rem;
        font-size: 0.78rem;
        color: #4b5563;
      }

      .mpr-print-title {
        margin-top: 0.15rem;
        margin-bottom: 0.1rem;
        font-size: 0.95rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #1f2937;
      }

      .mpr-print-generated {
        margin-top: 0;
        margin-bottom: 0;
        font-size: 0.78rem;
        color: #6b7280;
      }

      .mpr-print-section + .mpr-print-section {
        margin-top: 1.4rem;
        page-break-before: auto;
      }

      .mpr-print-program {
        border-bottom: 1px solid #111827;
        padding-bottom: 0.28rem;
        margin-bottom: 0.6rem;
        font-size: 0.98rem;
        font-weight: 700;
        color: #111827;
      }

      .mpr-print-count {
        margin-left: 0.45rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: #6b7280;
      }

      .mpr-print-list {
        margin: 0;
        padding-left: 1.45rem;
      }

      .mpr-print-list li + li {
        margin-top: 0.38rem;
      }

      .mpr-print-list-name {
        font-weight: 700;
        color: #111827;
      }

      .mpr-print-list-meta {
        display: block;
        font-size: 0.78rem;
        color: #4b5563;
      }

      @media print {
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

        .card,
        .mpr-stat-card {
          border: 1px solid #d8dee8 !important;
          box-shadow: none !important;
        }

        .mpr-print-sheet {
          display: block;
        }

        .content-wrapper,
        .container-xxl {
          background: #fff !important;
        }

        .mpr-print-section {
          break-inside: avoid;
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
                <form
                  id="mprDeleteProgramForm"
                  class="mpr-delete-form"
                  method="post"
                  data-program-count="<?= (int) count($deleteableProgramOptions); ?>"
                >
                  <input type="hidden" name="action" value="remove_by_programs" />
                  <input type="hidden" name="preserve_program_filter" value="<?= (int) $programFilter; ?>" />
                  <input type="hidden" name="search" value="<?= htmlspecialchars($search); ?>" />
                  <select
                    id="mprProgramDeleteSelect"
                    name="program_ids[]"
                    class="mpr-delete-select form-select"
                    multiple
                    data-placeholder="Select programs to remove"
                  >
                    <?php foreach ($deleteableProgramOptions as $deleteableProgramOption): ?>
                      <?php $deleteableProgramId = (int) ($deleteableProgramOption['program_id'] ?? 0); ?>
                      <?php if ($deleteableProgramId <= 0) continue; ?>
                      <option value="<?= $deleteableProgramId; ?>">
                        <?= htmlspecialchars((string) ($deleteableProgramOption['label'] ?? ('Program #' . $deleteableProgramId))); ?>
                        (<?= number_format((int) ($deleteableProgramOption['prereg_count'] ?? 0)); ?>)
                      </option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-outline-danger" id="mprDeleteProgramBtn" disabled>
                    <i class="bx bx-trash me-1"></i>Remove Selected
                  </button>
                </form>
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
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($rows)): ?>
                          <tr>
                            <td colspan="7" class="text-center text-muted py-4">
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
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="mpr-print-sheet">
                <div class="mpr-print-header">
                  <div class="mpr-print-school"><?= htmlspecialchars((string) ($printHeader['institution'] ?? 'Sultan Kudarat State University')); ?></div>
                  <div class="mpr-print-address"><?= htmlspecialchars((string) ($printHeader['address'] ?? '')); ?></div>
                  <div class="mpr-print-title"><?= htmlspecialchars((string) ($printHeader['report_title'] ?? 'Pre-Registered Students')); ?></div>
                  <div class="mpr-print-generated">Generated on <?= htmlspecialchars($printGeneratedAt); ?></div>
                </div>

                <?php if (empty($printSections)): ?>
                  <div class="text-muted">No pre-registered students found for the selected filter.</div>
                <?php else: ?>
                  <?php foreach ($printSections as $section): ?>
                    <section class="mpr-print-section">
                      <div class="mpr-print-program">
                        <?= htmlspecialchars((string) ($section['program_label'] ?? 'No Program')); ?>
                        <span class="mpr-print-count">(<?= number_format(count((array) ($section['rows'] ?? []))); ?> students)</span>
                      </div>
                      <ol class="mpr-print-list">
                        <?php foreach (($section['rows'] ?? []) as $printRow): ?>
                          <li>
                            <span class="mpr-print-list-name"><?= htmlspecialchars((string) ($printRow['full_name'] ?? 'Unknown Student')); ?></span>
                            <span class="mpr-print-list-meta">
                              Examinee #: <?= htmlspecialchars((string) ($printRow['examinee_number'] ?? '')); ?>
                              <?php if (trim((string) ($printRow['campus_name'] ?? '')) !== ''): ?>
                                | Campus: <?= htmlspecialchars((string) $printRow['campus_name']); ?>
                              <?php endif; ?>
                              <?php if ((int) ($printRow['locked_rank'] ?? 0) > 0): ?>
                                | Locked Rank: #<?= number_format((int) $printRow['locked_rank']); ?>
                              <?php endif; ?>
                            </span>
                          </li>
                        <?php endforeach; ?>
                      </ol>
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
        if (!deleteForm) return;
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

        if (typeof $ !== 'undefined' && $.fn && $.fn.select2) {
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
