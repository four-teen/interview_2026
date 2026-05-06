<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
require_once __DIR__ . '/preregistration_list_helpers.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'registrar')) {
    header('Location: ../index.php');
    exit;
}

$registrarHeaderTitle = 'Registrar - Pre-Registered Students';
$storageReady = ensure_student_preregistration_storage($conn);
$filters = registrar_prereg_build_filters($_GET);
$filters['limit'] = 30;
$filters['offset'] = 0;
$programOptions = $storageReady ? student_preregistration_fetch_program_options($conn) : [];
$rows = $storageReady ? registrar_prereg_fetch_rows($conn, $filters) : [];
$totalRows = $storageReady ? registrar_prereg_count_rows($conn, $filters) : 0;
$hasMoreRows = count($rows) < $totalRows;
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
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimum-scale=1.0"
    />
    <title>Registrar Pre-Registered Students - Interview</title>

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
      .rpr-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .rpr-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .rpr-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .rpr-filter-hint,
      .rpr-scroll-state {
        color: #7b8798;
        font-size: 0.8rem;
      }

      .rpr-table td,
      .rpr-table th {
        vertical-align: middle;
      }

      .rpr-student-name {
        font-weight: 700;
        color: #2f3f59;
      }

      .rpr-subline {
        display: block;
        margin-top: 0.14rem;
        color: #7b8798;
        font-size: 0.78rem;
      }

      .rpr-scroll-sentinel {
        height: 1px;
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
                <span class="text-muted fw-light">Registrar /</span> Pre-Registered Students
              </h4>
              <p class="text-muted mb-4">
                Submitted pre-registration records with profile, program, school, and address details.
              </p>

              <?php if (!$storageReady): ?>
                <div class="alert alert-danger">
                  Unable to prepare pre-registration storage. Refresh the page or check database permissions.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-md-4 col-6">
                  <div class="rpr-stat-card">
                    <div class="rpr-stat-label">Filtered Records</div>
                    <div class="rpr-stat-value" id="registrarPreregTotal"><?= number_format($totalRows); ?></div>
                  </div>
                </div>
                <div class="col-md-4 col-6">
                  <div class="rpr-stat-card">
                    <div class="rpr-stat-label">Loaded</div>
                    <div class="rpr-stat-value" id="registrarPreregLoaded"><?= number_format(count($rows)); ?></div>
                  </div>
                </div>
                <div class="col-md-4 col-12">
                  <div class="rpr-stat-card">
                    <div class="rpr-stat-label">Page Size</div>
                    <div class="rpr-stat-value">30</div>
                  </div>
                </div>
              </div>

              <div class="card mb-4">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-4">
                      <label class="form-label mb-1">Search</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars((string) ($filters['search'] ?? '')); ?>"
                        class="form-control"
                        placeholder="Examinee #, name, high school, city, barangay"
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
                          <option value="<?= $optionProgramId; ?>"<?= (int) ($filters['program_id'] ?? 0) === $optionProgramId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($programOption['label'] ?? '')); ?>
                            <?= $optionCount > 0 ? ' (' . number_format($optionCount) . ')' : ''; ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <div class="rpr-filter-hint">
                        Infinite scroll loads the next 30 records automatically.
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

              <div class="card">
                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm rpr-table">
                      <thead>
                        <tr>
                          <th>Student</th>
                          <th>Program</th>
                          <th>High School</th>
                          <th>Address</th>
                          <th class="text-center">Rank</th>
                          <th class="text-center">Profile</th>
                          <th>Submitted</th>
                        </tr>
                      </thead>
                      <tbody id="registrarPreregRows">
                        <?php if (empty($rows)): ?>
                          <tr id="registrarPreregEmptyRow">
                            <td colspan="7" class="text-center text-muted py-4">
                              No pre-registered students found for the selected filter.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?= registrar_prereg_render_rows($rows); ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>

                  <div id="registrarPreregLoadState" class="rpr-scroll-state d-flex justify-content-center align-items-center gap-2 py-3">
                    <div class="spinner-border spinner-border-sm text-primary d-none" id="registrarPreregSpinner" role="status" aria-hidden="true"></div>
                    <span id="registrarPreregLoadText"><?= $hasMoreRows ? 'Scroll to load more records.' : 'All matching records loaded.'; ?></span>
                  </div>
                  <div id="registrarPreregSentinel" class="rpr-scroll-sentinel<?= $hasMoreRows ? '' : ' d-none'; ?>" aria-hidden="true"></div>
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
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const rowsEl = document.getElementById('registrarPreregRows');
        const sentinelEl = document.getElementById('registrarPreregSentinel');
        const spinnerEl = document.getElementById('registrarPreregSpinner');
        const loadTextEl = document.getElementById('registrarPreregLoadText');
        const loadedEl = document.getElementById('registrarPreregLoaded');
        const totalEl = document.getElementById('registrarPreregTotal');
        const emptyRow = document.getElementById('registrarPreregEmptyRow');
        const params = new URLSearchParams(window.location.search);
        const limit = 30;
        let offset = <?= (int) count($rows); ?>;
        let total = <?= (int) $totalRows; ?>;
        let loading = false;
        let done = offset >= total;

        function setLoading(isLoading) {
          loading = isLoading;
          if (spinnerEl) {
            spinnerEl.classList.toggle('d-none', !isLoading);
          }
        }

        function updateCounts(loadedCount, totalCount) {
          if (loadedEl) {
            loadedEl.textContent = Number(loadedCount).toLocaleString();
          }
          if (totalEl) {
            totalEl.textContent = Number(totalCount).toLocaleString();
          }
        }

        function updateState(message) {
          if (loadTextEl) {
            loadTextEl.textContent = message;
          }
          if (sentinelEl) {
            sentinelEl.classList.toggle('d-none', done);
          }
        }

        function loadMore() {
          if (loading || done) {
            return;
          }

          setLoading(true);
          updateState('Loading more records...');

          const requestParams = new URLSearchParams(params);
          requestParams.set('limit', String(limit));
          requestParams.set('offset', String(offset));

          fetch(`fetch_preregistrations.php?${requestParams.toString()}`, {
            headers: { 'Accept': 'application/json' }
          })
            .then((response) => response.json())
            .then((payload) => {
              if (!payload || !payload.success) {
                throw new Error((payload && payload.message) ? payload.message : 'Unable to load records.');
              }

              if (emptyRow) {
                emptyRow.remove();
              }

              if (payload.html) {
                rowsEl.insertAdjacentHTML('beforeend', payload.html);
              }

              offset = Number(payload.next_offset || offset);
              total = Number(payload.total || total);
              done = !payload.has_more;
              updateCounts(offset, total);
              updateState(done ? 'All matching records loaded.' : 'Scroll to load more records.');
            })
            .catch(() => {
              updateState('Failed to load more records.');
            })
            .finally(() => {
              setLoading(false);
            });
        }

        if (!sentinelEl || done) {
          updateState('All matching records loaded.');
          return;
        }

        const observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              loadMore();
            }
          });
        }, { rootMargin: '240px 0px' });

        observer.observe(sentinelEl);
      });
    </script>
  </body>
</html>
