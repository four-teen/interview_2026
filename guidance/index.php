<?php
require_once 'bootstrap.php';
guidance_require_access();

$guidanceHeaderTitle = 'Guidance Office - Dashboard';
$activeBatchId = guidance_get_active_batch_id($conn);

$summary = [
    'total_students' => 0,
    'esm_students' => 0,
    'overall_students' => 0,
    'interviewed_students' => 0,
    'scored_students' => 0
];

if ($activeBatchId !== null) {
    $esmConditionSql = guidance_build_esm_preferred_program_condition_sql('pr.preferred_program');
    $summarySql = "
        SELECT
            COUNT(*) AS total_students,
            SUM(CASE WHEN {$esmConditionSql} THEN 1 ELSE 0 END) AS esm_students,
            SUM(CASE WHEN {$esmConditionSql} THEN 0 ELSE 1 END) AS overall_students,
            SUM(CASE WHEN COALESCE(ix.has_interview, 0) = 1 THEN 1 ELSE 0 END) AS interviewed_students,
            SUM(CASE WHEN COALESCE(ix.has_score, 0) = 1 THEN 1 ELSE 0 END) AS scored_students
        FROM tbl_placement_results pr
        LEFT JOIN (
            SELECT
                placement_result_id,
                MAX(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS has_interview,
                MAX(CASE WHEN status = 'active' AND final_score IS NOT NULL THEN 1 ELSE 0 END) AS has_score
            FROM tbl_student_interview
            GROUP BY placement_result_id
        ) ix
            ON ix.placement_result_id = pr.id
        WHERE pr.upload_batch_id = ?
    ";

    $stmtSummary = $conn->prepare($summarySql);
    if ($stmtSummary) {
        $stmtSummary->bind_param('s', $activeBatchId);
        $stmtSummary->execute();
        $summaryResult = $stmtSummary->get_result();
        if ($summaryResult && $summaryRow = $summaryResult->fetch_assoc()) {
            $summary['total_students'] = (int) ($summaryRow['total_students'] ?? 0);
            $summary['esm_students'] = (int) ($summaryRow['esm_students'] ?? 0);
            $summary['overall_students'] = (int) ($summaryRow['overall_students'] ?? 0);
            $summary['interviewed_students'] = (int) ($summaryRow['interviewed_students'] ?? 0);
            $summary['scored_students'] = (int) ($summaryRow['scored_students'] ?? 0);
        }
        $stmtSummary->close();
    }
}

$notScoredStudents = max(0, $summary['total_students'] - $summary['scored_students']);
$withoutInterviewStudents = max(0, $summary['total_students'] - $summary['interviewed_students']);
$pendingScoringStudents = max(0, $summary['interviewed_students'] - $summary['scored_students']);
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
    <title>Guidance Dashboard - Interview</title>

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
      .gd-hero-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background:
          radial-gradient(circle at top right, rgba(105, 108, 255, 0.14), transparent 28%),
          linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
      }

      .gd-hero-title {
        font-size: 1.35rem;
        font-weight: 700;
        color: #24364d;
      }

      .gd-hero-copy {
        color: #66758c;
        max-width: 48rem;
      }

      .gd-hero-pill-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
      }

      .gd-hero-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        padding: 0.38rem 0.72rem;
        border-radius: 999px;
        border: 1px solid #dbe5f2;
        background: #fff;
        font-size: 0.76rem;
        font-weight: 600;
        color: #4a5b73;
      }

      .gd-action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1rem;
      }

      .gd-action-card {
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
        padding: 1rem 1.05rem;
        border: 1px solid #e4eaf3;
        border-radius: 1rem;
        background: #fff;
        color: inherit;
        text-decoration: none;
        transition: all 0.2s ease;
      }

      .gd-action-card:hover {
        color: inherit;
        border-color: #cdd7e8;
        box-shadow: 0 10px 24px rgba(40, 57, 89, 0.08);
        transform: translateY(-1px);
      }

      .gd-action-card__icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex: 0 0 44px;
      }

      .gd-action-card__title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .gd-action-card__copy {
        margin-top: 0.18rem;
        font-size: 0.84rem;
        line-height: 1.35;
        color: #6b7a90;
      }

      .gd-action-card__meta {
        margin-top: 0.5rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: #56657b;
      }

      .gd-snapshot-card {
        height: 100%;
        border: 1px solid #e6ebf3;
        border-radius: 0.95rem;
        background: #fff;
        padding: 0.95rem 1rem;
      }

      .gd-snapshot-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .gd-snapshot-value {
        margin-top: 0.35rem;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.08;
        color: #2f3f59;
      }

      .gd-snapshot-hint {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.3;
        color: #7d8aa3;
      }

      .gd-status-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
      }

      .gd-status-item + .gd-status-item {
        margin-top: 1rem;
      }

      .gd-status-label {
        display: flex;
        justify-content: space-between;
        gap: 0.75rem;
        margin-bottom: 0.35rem;
        font-size: 0.83rem;
        color: #64748b;
      }

      .gd-status-card .progress {
        height: 0.5rem;
        background: #eef2f7;
      }

      .gd-summary-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
        padding: 1rem 1.05rem;
      }

      .gd-summary-kicker {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .gd-summary-title {
        margin-top: 0.3rem;
        font-size: 1.05rem;
        font-weight: 700;
        color: #334155;
      }

      .gd-summary-copy {
        margin-top: 0.4rem;
        font-size: 0.84rem;
        line-height: 1.45;
        color: #64748b;
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
                <span class="text-muted fw-light">Guidance /</span> Dashboard
              </h4>
              <p class="text-muted mb-4">
                Batch-level scoring progress for guidance staff using the latest placement-results upload.
              </p>

              <?php if ($activeBatchId !== null): ?>
                <div class="alert alert-info py-2 mb-4">
                  Active placement batch: <?= htmlspecialchars($activeBatchId); ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning py-2 mb-4">
                  No placement-results batch is available yet. Snapshot counts will stay at zero until a batch exists.
                </div>
              <?php endif; ?>

              <div class="card gd-hero-card mb-4">
                <div class="card-body">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <div class="gd-hero-title">Guidance scoring snapshot</div>
                      <p class="gd-hero-copy mb-3">
                        Review which students are already scored, which records still need scoring, and which preferred programs fall under ESM classification.
                      </p>
                      <div class="gd-hero-pill-list">
                        <span class="gd-hero-pill">
                          <i class="bx bx-collection"></i>
                          <?= number_format($summary['total_students']); ?> batch records
                        </span>
                        <span class="gd-hero-pill">
                          <i class="bx bx-check-shield"></i>
                          <?= number_format($summary['scored_students']); ?> scored
                        </span>
                        <span class="gd-hero-pill">
                          <i class="bx bx-time-five"></i>
                          <?= number_format($notScoredStudents); ?> not scored
                        </span>
                      </div>
                    </div>
                    <div class="col-lg-4">
                      <div class="gd-summary-card">
                        <div class="gd-summary-kicker">Sidebar Workflow</div>
                        <div class="gd-summary-title">Dashboard and Student Information</div>
                        <div class="gd-summary-copy">
                          Use the sidebar to move from the scoring snapshot to the student-information page where placement records can be added or updated.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="gd-action-grid mb-4">
                <a href="students.php" class="gd-action-card">
                  <span class="gd-action-card__icon bg-label-info">
                    <i class="bx bx-user-plus"></i>
                  </span>
                  <div>
                    <div class="gd-action-card__title">Student Information</div>
                    <div class="gd-action-card__copy">
                      Add new placement records, correct existing student information, and review whether a preferred program is ESM-based.
                    </div>
                    <div class="gd-action-card__meta">
                      <?= number_format($summary['total_students']); ?> students in the active batch
                    </div>
                  </div>
                </a>

                <a href="students.php?score_status=not_scored" class="gd-action-card">
                  <span class="gd-action-card__icon bg-label-warning">
                    <i class="bx bx-list-ul"></i>
                  </span>
                  <div>
                    <div class="gd-action-card__title">Needs Scoring</div>
                    <div class="gd-action-card__copy">
                      Jump directly to students who do not yet have a recorded final interview score.
                    </div>
                    <div class="gd-action-card__meta">
                      <?= number_format($notScoredStudents); ?> records currently pending
                    </div>
                  </div>
                </a>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">Students</div>
                    <div class="gd-snapshot-value"><?= number_format($summary['total_students']); ?></div>
                    <span class="gd-snapshot-hint">Records in the active batch</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">Scored</div>
                    <div class="gd-snapshot-value"><?= number_format($summary['scored_students']); ?></div>
                    <span class="gd-snapshot-hint"><?= guidance_percentage($summary['scored_students'], $summary['total_students']); ?> of all students</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">Not Scored</div>
                    <div class="gd-snapshot-value"><?= number_format($notScoredStudents); ?></div>
                    <span class="gd-snapshot-hint">No final score recorded yet</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">With Interview</div>
                    <div class="gd-snapshot-value"><?= number_format($summary['interviewed_students']); ?></div>
                    <span class="gd-snapshot-hint">Students already assigned an interview record</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">ESM Basis</div>
                    <div class="gd-snapshot-value"><?= number_format($summary['esm_students']); ?></div>
                    <span class="gd-snapshot-hint">Preferred program matches the ESM list</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="gd-snapshot-card">
                    <div class="gd-snapshot-label">Overall Basis</div>
                    <div class="gd-snapshot-value"><?= number_format($summary['overall_students']); ?></div>
                    <span class="gd-snapshot-hint">All other preferred programs</span>
                  </div>
                </div>
              </div>

              <div class="row g-4">
                <div class="col-lg-7">
                  <div class="card gd-status-card h-100">
                    <div class="card-body">
                      <h5 class="card-title mb-1">Progress Snapshot</h5>
                      <p class="text-muted small mb-4">
                        The counts below summarize interview coverage and scoring completion for the active placement batch.
                      </p>

                      <div class="gd-status-item">
                        <div class="gd-status-label">
                          <span>Scored students</span>
                          <span><?= number_format($summary['scored_students']); ?> / <?= number_format($summary['total_students']); ?> (<?= guidance_percentage($summary['scored_students'], $summary['total_students']); ?>)</span>
                        </div>
                        <div class="progress">
                          <div class="progress-bar bg-success" role="progressbar" style="width: <?= htmlspecialchars(guidance_percentage($summary['scored_students'], $summary['total_students'])); ?>"></div>
                        </div>
                      </div>

                      <div class="gd-status-item">
                        <div class="gd-status-label">
                          <span>Students with interview records</span>
                          <span><?= number_format($summary['interviewed_students']); ?> / <?= number_format($summary['total_students']); ?> (<?= guidance_percentage($summary['interviewed_students'], $summary['total_students']); ?>)</span>
                        </div>
                        <div class="progress">
                          <div class="progress-bar bg-primary" role="progressbar" style="width: <?= htmlspecialchars(guidance_percentage($summary['interviewed_students'], $summary['total_students'])); ?>"></div>
                        </div>
                      </div>

                      <div class="gd-status-item">
                        <div class="gd-status-label">
                          <span>ESM-classified preferred programs</span>
                          <span><?= number_format($summary['esm_students']); ?> / <?= number_format($summary['total_students']); ?> (<?= guidance_percentage($summary['esm_students'], $summary['total_students']); ?>)</span>
                        </div>
                        <div class="progress">
                          <div class="progress-bar bg-info" role="progressbar" style="width: <?= htmlspecialchars(guidance_percentage($summary['esm_students'], $summary['total_students'])); ?>"></div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-lg-5">
                  <div class="gd-summary-card h-100">
                    <div class="gd-summary-kicker">Queue Details</div>
                    <div class="gd-summary-title">What still needs attention</div>
                    <div class="gd-summary-copy">
                      <strong><?= number_format($pendingScoringStudents); ?></strong> students already have interview records but still need a final score.
                    </div>
                    <div class="gd-summary-copy">
                      <strong><?= number_format($withoutInterviewStudents); ?></strong> students do not have an interview record yet, so they are also counted as not scored.
                    </div>
                    <div class="gd-summary-copy">
                      Use <a href="students.php" class="fw-semibold">Student Information</a> to review the record details or correct placement data in the active batch.
                    </div>
                  </div>
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
