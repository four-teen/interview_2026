<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'monitoring')) {
    header('Location: ../index.php');
    exit;
}

$monitoringHeaderTitle = 'Monitoring Center - Student List';
$search = trim((string) ($_GET['q'] ?? ''));
$basisFilter = strtolower(trim((string) ($_GET['basis'] ?? 'all')));
$isStudentCardsRequest = strtolower(trim((string) ($_GET['fetch'] ?? ''))) === 'student_cards';
$allowedBasisFilters = ['all', 'esm', 'overall'];
if (!in_array($basisFilter, $allowedBasisFilters, true)) {
    $basisFilter = 'all';
}

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

if (!$isStudentCardsRequest) {
    $page = 1;
}

function build_monitoring_esm_preferred_program_condition_sql(string $columnExpression): string
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

function build_monitoring_basis_score_sql(
    string $preferredProgramColumnExpression,
    string $esmScoreColumnExpression,
    string $overallScoreColumnExpression
): string {
    $esmConditionSql = build_monitoring_esm_preferred_program_condition_sql($preferredProgramColumnExpression);

    return "CASE
        WHEN {$esmConditionSql} THEN COALESCE({$esmScoreColumnExpression}, 0)
        ELSE COALESCE({$overallScoreColumnExpression}, 0)
    END";
}

function format_monitoring_score($value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    if (is_numeric($value)) {
        return number_format((int) round((float) $value));
    }

    return trim((string) $value);
}

function render_monitoring_student_card(array $student): string
{
    $basisLabel = (string) ($student['monitoring_basis_label'] ?? 'Overall');
    $preferredProgram = trim((string) ($student['preferred_program'] ?? ''));
    $isEsmBasis = (strtoupper($basisLabel) === 'ESM');

    ob_start();
    ?>
    <article class="monitor-student-card">
      <div class="monitor-student-card__identity">
        <div class="monitor-student-card__top">
          <div>
            <div class="monitor-student-card__name"><?= htmlspecialchars((string) ($student['full_name'] ?? '')); ?></div>
            <div class="monitor-student-card__exam">Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? '')); ?></div>
          </div>
          <span class="monitor-student-badge <?= $isEsmBasis ? 'monitor-student-badge--esm' : 'monitor-student-badge--overall'; ?>">
            Source: <?= htmlspecialchars($basisLabel); ?>
          </span>
        </div>
      </div>

      <div class="monitor-student-program">
        <span class="monitor-student-program__label">Preferred Program</span>
        <span class="monitor-student-program__value">
          <?= htmlspecialchars($preferredProgram !== '' ? $preferredProgram : 'No preferred program recorded'); ?>
        </span>
      </div>

      <div class="monitor-student-scores">
        <div class="monitor-student-score monitor-student-score--primary <?= $isEsmBasis ? 'monitor-student-score--esm' : 'monitor-student-score--overall'; ?>">
          <span class="monitor-student-score__label">Score Used</span>
          <span class="monitor-student-score__value"><?= htmlspecialchars(format_monitoring_score($student['monitoring_basis_score'] ?? null)); ?></span>
          <span class="monitor-student-score__hint">Preferred-program source</span>
        </div>

        <div class="monitor-student-score">
          <span class="monitor-student-score__label">ESM</span>
          <span class="monitor-student-score__value"><?= htmlspecialchars(format_monitoring_score($student['esm_competency_standard_score'] ?? null)); ?></span>
        </div>

        <div class="monitor-student-score">
          <span class="monitor-student-score__label">Overall</span>
          <span class="monitor-student-score__value"><?= htmlspecialchars(format_monitoring_score($student['overall_standard_score'] ?? null)); ?></span>
        </div>
      </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function render_monitoring_student_cards(array $students): string
{
    if (empty($students)) {
        return '';
    }

    $html = '';
    foreach ($students as $student) {
        $html .= render_monitoring_student_card($student);
    }

    return $html;
}

$activeBatchId = null;
$batchResult = $conn->query("
    SELECT upload_batch_id
    FROM tbl_placement_results
    ORDER BY created_at DESC
    LIMIT 1
");
if ($batchResult && $batchRow = $batchResult->fetch_assoc()) {
    $activeBatchId = (string) ($batchRow['upload_batch_id'] ?? '');
}

$batchSummary = [
    'total_students' => 0,
    'esm_count' => 0,
    'overall_count' => 0
];
$totalStudents = 0;
$totalPages = 1;
$students = [];

if ($activeBatchId !== null && $activeBatchId !== '') {
    $esmConditionSql = build_monitoring_esm_preferred_program_condition_sql('pr.preferred_program');
    $basisScoreSql = build_monitoring_basis_score_sql(
        'pr.preferred_program',
        'pr.esm_competency_standard_score',
        'pr.overall_standard_score'
    );

    $summarySql = "
        SELECT
            COUNT(*) AS total_students,
            SUM(CASE WHEN {$esmConditionSql} THEN 1 ELSE 0 END) AS esm_count,
            SUM(CASE WHEN {$esmConditionSql} THEN 0 ELSE 1 END) AS overall_count
        FROM tbl_placement_results pr
        WHERE pr.upload_batch_id = ?
    ";
    $stmtSummary = $conn->prepare($summarySql);
    if ($stmtSummary) {
        $stmtSummary->bind_param('s', $activeBatchId);
        $stmtSummary->execute();
        $summaryResult = $stmtSummary->get_result();
        if ($summaryResult && $summaryRow = $summaryResult->fetch_assoc()) {
            $batchSummary['total_students'] = (int) ($summaryRow['total_students'] ?? 0);
            $batchSummary['esm_count'] = (int) ($summaryRow['esm_count'] ?? 0);
            $batchSummary['overall_count'] = (int) ($summaryRow['overall_count'] ?? 0);
        }
        $stmtSummary->close();
    }

    $where = ['pr.upload_batch_id = ?'];
    $types = 's';
    $params = [$activeBatchId];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(pr.examinee_number LIKE ? OR pr.full_name LIKE ? OR pr.preferred_program LIKE ?)';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    if ($basisFilter === 'esm') {
        $where[] = $esmConditionSql;
    } elseif ($basisFilter === 'overall') {
        $where[] = "NOT {$esmConditionSql}";
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM tbl_placement_results pr
        WHERE {$whereSql}
    ";
    $stmtCount = $conn->prepare($countSql);
    if ($stmtCount) {
        $stmtCount->bind_param($types, ...$params);
        $stmtCount->execute();
        $countResult = $stmtCount->get_result();
        if ($countResult && $countRow = $countResult->fetch_assoc()) {
            $totalStudents = (int) ($countRow['total'] ?? 0);
        }
        $stmtCount->close();
    }

    $totalPages = max(1, (int) ceil($totalStudents / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = max(0, ($page - 1) * $perPage);

    $studentSql = "
        SELECT
            pr.id,
            pr.examinee_number,
            pr.full_name,
            pr.preferred_program,
            pr.esm_competency_standard_score,
            pr.overall_standard_score,
            {$basisScoreSql} AS monitoring_basis_score,
            CASE
                WHEN {$esmConditionSql} THEN 'ESM'
                ELSE 'Overall'
            END AS monitoring_basis_label
        FROM tbl_placement_results pr
        WHERE {$whereSql}
        ORDER BY pr.full_name ASC, pr.examinee_number ASC
        LIMIT ? OFFSET ?
    ";
    $stmtStudents = $conn->prepare($studentSql);
    if ($stmtStudents) {
        $studentTypes = $types . 'ii';
        $studentParams = $params;
        $studentParams[] = $perPage;
        $studentParams[] = $offset;
        $stmtStudents->bind_param($studentTypes, ...$studentParams);
        $stmtStudents->execute();
        $studentResult = $stmtStudents->get_result();
        while ($studentRow = $studentResult->fetch_assoc()) {
            $students[] = $studentRow;
        }
        $stmtStudents->close();
    }
}

$loadedStudents = count($students);
$hasMoreStudents = $page < $totalPages;

if ($isStudentCardsRequest) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => render_monitoring_student_cards($students),
        'page' => $page,
        'total_pages' => $totalPages,
        'total' => $totalStudents,
        'loaded_count' => $loadedStudents,
        'has_more' => $hasMoreStudents,
        'next_page' => $hasMoreStudents ? ($page + 1) : 0
    ]);
    exit;
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
    <title>Monitoring Student List - Interview</title>

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
      .mn-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .mn-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .mn-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .monitor-student-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
      }

      .monitor-student-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
        padding: 1rem 1.1rem;
        display: flex;
        flex-wrap: wrap;
        align-items: stretch;
        gap: 1rem;
      }

      .monitor-student-card__identity {
        flex: 1 1 280px;
        min-width: 240px;
      }

      .monitor-student-card__top {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.75rem;
      }

      .monitor-student-card__name {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
        line-height: 1.35;
        text-transform: uppercase;
      }

      .monitor-student-card__exam {
        margin-top: 0.18rem;
        font-size: 0.82rem;
        color: #6b7a90;
      }

      .monitor-student-badge {
        border-radius: 999px;
        padding: 0.34rem 0.65rem;
        font-size: 0.75rem;
        font-weight: 700;
        white-space: nowrap;
      }

      .monitor-student-badge--esm {
        background: #e8f0ff;
        color: #2563eb;
      }

      .monitor-student-badge--overall {
        background: #f1f5f9;
        color: #334155;
      }

      .monitor-student-program {
        flex: 1.2 1 320px;
        min-width: 260px;
        border: 1px solid #e9eef5;
        border-radius: 0.85rem;
        background: #f9fbff;
        padding: 0.85rem 0.9rem;
      }

      .monitor-student-program__label,
      .monitor-student-score__label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .monitor-student-program__value {
        display: block;
        margin-top: 0.32rem;
        font-size: 0.92rem;
        font-weight: 600;
        color: #334155;
        line-height: 1.45;
      }

      .monitor-student-scores {
        flex: 999 1 520px;
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.8rem;
      }

      .monitor-student-score {
        border: 1px solid #e9eef5;
        border-radius: 0.85rem;
        padding: 0.8rem 0.85rem;
        background: #fff;
      }

      .monitor-student-score--primary.monitor-student-score--esm {
        background: #eef4ff;
        border-color: #d7e3ff;
      }

      .monitor-student-score--primary.monitor-student-score--overall {
        background: #f4f7fb;
        border-color: #dde5f0;
      }

      .monitor-student-score__value {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.12rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .monitor-student-score--primary.monitor-student-score--esm .monitor-student-score__value {
        color: #2563eb;
      }

      .monitor-student-score__hint {
        display: block;
        margin-top: 0.2rem;
        font-size: 0.76rem;
        color: #7d8aa3;
      }

      .monitor-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 1rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
      }

      .monitor-scroll-state {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.65rem;
        min-height: 2.5rem;
        margin-top: 1rem;
        color: #6b7a90;
        font-size: 0.9rem;
      }

      .monitor-scroll-sentinel {
        height: 1px;
      }

      @media (max-width: 1199.98px) {
        .monitor-student-program,
        .monitor-student-scores {
          flex-basis: 100%;
        }
      }

      @media (max-width: 991.98px) {
        .monitor-student-scores {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 767.98px) {
        .monitor-student-card__identity,
        .monitor-student-program {
          min-width: 0;
          flex-basis: 100%;
        }

        .monitor-student-card__top {
          flex-direction: column;
        }

        .monitor-student-scores {
          grid-template-columns: 1fr;
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
              <h4 class="fw-bold mb-1">
                <span class="text-muted fw-light">Monitoring /</span> Student List
              </h4>
              <p class="text-muted mb-4">
                Latest placement-results batch with preferred-program-based source score.
                ESM programs use <strong>ESM Competency Standard Score</strong>; all other programs use
                <strong>Overall Standard Score</strong>.
              </p>

              <?php if ($activeBatchId !== null && $activeBatchId !== ''): ?>
                <div class="alert alert-info py-2 mb-3">
                  Active placement batch: <?= htmlspecialchars($activeBatchId); ?>
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Students in Batch</div>
                    <div class="mn-stat-value"><?= number_format((int) $batchSummary['total_students']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">ESM Source</div>
                    <div class="mn-stat-value"><?= number_format((int) $batchSummary['esm_count']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Overall Source</div>
                    <div class="mn-stat-value"><?= number_format((int) $batchSummary['overall_count']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Matching Results</div>
                    <div class="mn-stat-value"><?= number_format((int) $totalStudents); ?></div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-7">
                      <label class="form-label mb-1">Search Examinee / Student / Preferred Program</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Type examinee number, student name, or preferred program"
                      />
                    </div>
                    <div class="col-lg-3">
                      <label class="form-label mb-1">Score Source</label>
                      <select name="basis" class="form-select">
                        <option value="all"<?= $basisFilter === 'all' ? ' selected' : ''; ?>>All Sources</option>
                        <option value="esm"<?= $basisFilter === 'esm' ? ' selected' : ''; ?>>ESM</option>
                        <option value="overall"<?= $basisFilter === 'overall' ? ' selected' : ''; ?>>Overall</option>
                      </select>
                    </div>
                    <div class="col-lg-2 d-grid">
                      <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                  </form>
                </div>

                <div class="card-body">
                  <?php if ($activeBatchId === null || $activeBatchId === ''): ?>
                    <div class="monitor-empty-card">No placement results batch found.</div>
                  <?php elseif (empty($students)): ?>
                    <div class="monitor-empty-card">No students found for the selected filters.</div>
                  <?php else: ?>
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                      <div class="small text-muted" id="monitoringStudentCountText">
                        Loaded <?= number_format($loadedStudents); ?> of <?= number_format($totalStudents); ?> matching students
                      </div>
                      <div class="small text-muted" id="monitoringStudentPageText">
                        Loaded page <?= number_format($page); ?> of <?= number_format($totalPages); ?>
                      </div>
                    </div>

                    <div
                      id="monitoringStudentList"
                      class="monitor-student-list"
                      data-next-page="<?= $hasMoreStudents ? ($page + 1) : 0; ?>"
                      data-has-more="<?= $hasMoreStudents ? '1' : '0'; ?>"
                      data-total="<?= $totalStudents; ?>"
                      data-loaded="<?= $loadedStudents; ?>"
                      data-total-pages="<?= $totalPages; ?>"
                      data-current-page="<?= $page; ?>"
                    >
                      <?= render_monitoring_student_cards($students); ?>
                    </div>

                    <div id="monitoringStudentLoadState" class="monitor-scroll-state">
                      <div class="spinner-border spinner-border-sm text-primary d-none" id="monitoringStudentSpinner" role="status" aria-hidden="true"></div>
                      <span id="monitoringStudentLoadText"><?= $hasMoreStudents ? 'Scroll to load more students.' : 'All students loaded.'; ?></span>
                    </div>
                    <div id="monitoringStudentSentinel" class="monitor-scroll-sentinel<?= $hasMoreStudents ? '' : ' d-none'; ?>" aria-hidden="true"></div>
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
    <script>
      (function () {
        const listEl = document.getElementById('monitoringStudentList');
        const sentinelEl = document.getElementById('monitoringStudentSentinel');
        const spinnerEl = document.getElementById('monitoringStudentSpinner');
        const loadTextEl = document.getElementById('monitoringStudentLoadText');
        const countTextEl = document.getElementById('monitoringStudentCountText');
        const pageTextEl = document.getElementById('monitoringStudentPageText');

        if (!listEl || !sentinelEl || !loadTextEl) return;

        let nextPage = Number(listEl.dataset.nextPage || 0);
        let hasMore = listEl.dataset.hasMore === '1';
        let total = Number(listEl.dataset.total || 0);
        let loaded = Number(listEl.dataset.loaded || 0);
        let totalPages = Number(listEl.dataset.totalPages || 1);
        let currentPage = Number(listEl.dataset.currentPage || 1);
        let isLoading = false;

        function updateStateText() {
          if (countTextEl) {
            countTextEl.textContent = `Loaded ${loaded} of ${total} matching students`;
          }

          if (pageTextEl) {
            pageTextEl.textContent = `Loaded page ${currentPage} of ${totalPages}`;
          }

          loadTextEl.textContent = hasMore ? 'Scroll to load more students.' : 'All students loaded.';
          sentinelEl.classList.toggle('d-none', !hasMore);
        }

        async function loadMoreStudents() {
          if (!hasMore || isLoading || nextPage <= 0) {
            return;
          }

          isLoading = true;
          if (spinnerEl) spinnerEl.classList.remove('d-none');
          loadTextEl.textContent = 'Loading more students...';

          try {
            const params = new URLSearchParams(window.location.search);
            params.set('fetch', 'student_cards');
            params.set('page', String(nextPage));

            const response = await fetch(`students.php?${params.toString()}`, {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();

            if (!response.ok || !data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Failed to load more students.');
            }

            if (data.html) {
              listEl.insertAdjacentHTML('beforeend', data.html);
            }

            loaded += Number(data.loaded_count || 0);
            total = Number(data.total || total);
            currentPage = Number(data.page || currentPage);
            totalPages = Number(data.total_pages || totalPages);
            hasMore = Boolean(data.has_more);
            nextPage = Number(data.next_page || 0);
            updateStateText();
          } catch (error) {
            loadTextEl.textContent = (error && error.message) ? error.message : 'Failed to load more students.';
          } finally {
            isLoading = false;
            if (spinnerEl) spinnerEl.classList.add('d-none');
          }
        }

        updateStateText();

        if (!hasMore || !('IntersectionObserver' in window)) {
          return;
        }

        const observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              loadMoreStudents();
            }
          });
        }, {
          rootMargin: '320px 0px'
        });

        observer.observe(sentinelEl);
      })();
    </script>
  </body>
</html>
