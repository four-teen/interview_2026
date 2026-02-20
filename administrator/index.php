<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../index.php');
    exit;
}

/**
 * ============================================================================
 * root_folder/administrator/index.php
 * PHASE 1 - DASHBOARD GRAPH DATA
 * Qualitative Distribution of Placement Results
 * ============================================================================
 */

// STEP 1: Get latest upload batch (most recent exam)
$batchSql = "
    SELECT upload_batch_id
    FROM tbl_placement_results
    ORDER BY created_at DESC
    LIMIT 1
";
$batchResult = $conn->query($batchSql);
$activeBatchId = null;

/**
 * ============================================================================
 * PHASE 1.5 - CAMPUS LIST (RIGHT SIDE CARDS)
 * ============================================================================
 */

$campuses = [];

$campusSql = "
    SELECT
        c.campus_id,
        c.campus_code,
        c.campus_name,
        COALESCE(i.scored_interviewed_count, 0) AS interviewed_count
    FROM tbl_campus c
    LEFT JOIN (
        SELECT
            campus_id,
            COUNT(*) AS scored_interviewed_count
        FROM tbl_student_interview
        WHERE status = 'active'
          AND final_score IS NOT NULL
        GROUP BY campus_id
    ) i ON i.campus_id = c.campus_id
    WHERE c.status = 'active'
    ORDER BY c.campus_name ASC
";

$campusResult = $conn->query($campusSql);

if ($campusResult && $campusResult->num_rows > 0) {
    while ($row = $campusResult->fetch_assoc()) {
        $campuses[] = $row;
    }
}



if ($batchResult && $batchResult->num_rows > 0) {
    $activeBatchId = $batchResult->fetch_assoc()['upload_batch_id'];
}

// STEP 2: Initialize qualitative buckets (ENSURE ORDER 1-6)
$qualitativeLabels = [
    1 => 'Outstanding',
    2 => 'Above Average',
    3 => 'High Average',
    4 => 'Middle Average',
    5 => 'Low Average',
    6 => 'Below Average'
];

$qualitativeCounts = array_fill(1, 6, 0);

// STEP 3: Aggregate data
if ($activeBatchId) {
    $sql = "
        SELECT qualitative_code, COUNT(*) AS total
        FROM tbl_placement_results
        WHERE upload_batch_id = ?
        GROUP BY qualitative_code
        ORDER BY qualitative_code ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("s", $activeBatchId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $code = (int) $row['qualitative_code'];
        if (isset($qualitativeCounts[$code])) {
            $qualitativeCounts[$code] = (int) $row['total'];
        }
    }
}

$programTrendLabels = [];
$programTrendSeries = [];

$trendDateKeys = [];
$trendDateDisplay = [];
$trendCursor = new DateTime('today -14 days');
for ($i = 0; $i < 15; $i++) {
    $trendKey = $trendCursor->format('Y-m-d');
    $trendDateKeys[] = $trendKey;
    $trendDateDisplay[] = $trendCursor->format('M d');
    $trendCursor->modify('+1 day');
}

$programTrendSql = "
    SELECT
        DATE(si.interview_datetime) AS trend_day,
        si.first_choice AS program_id,
        CONCAT(
            p.program_name,
            IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' - ', p.major), '')
        ) AS program_label,
        COUNT(*) AS total_interviews
    FROM tbl_student_interview si
    INNER JOIN tbl_program p
        ON p.program_id = si.first_choice
    WHERE si.status = 'active'
      AND si.interview_datetime IS NOT NULL
      AND si.interview_datetime >= ?
    GROUP BY trend_day, si.first_choice, program_label
    ORDER BY trend_day ASC, total_interviews DESC
";

$programTrendStmt = $conn->prepare($programTrendSql);
if ($programTrendStmt) {
    $trendStartDate = $trendDateKeys[0] . ' 00:00:00';
    $programTrendStmt->bind_param('s', $trendStartDate);
    $programTrendStmt->execute();
    $programTrendResult = $programTrendStmt->get_result();

    $programTrendBuckets = [];
    $programTrendTotals = [];

    while ($trendRow = $programTrendResult->fetch_assoc()) {
        $dayKey = (string) ($trendRow['trend_day'] ?? '');
        $programId = (int) ($trendRow['program_id'] ?? 0);
        if ($programId <= 0 || !in_array($dayKey, $trendDateKeys, true)) {
            continue;
        }

        if (!isset($programTrendBuckets[$programId])) {
            $programTrendBuckets[$programId] = [
                'label' => (string) ($trendRow['program_label'] ?? 'Program'),
                'days' => []
            ];
            $programTrendTotals[$programId] = 0;
        }

        $dayTotal = (int) ($trendRow['total_interviews'] ?? 0);
        $programTrendBuckets[$programId]['days'][$dayKey] = $dayTotal;
        $programTrendTotals[$programId] += $dayTotal;
    }

    arsort($programTrendTotals);
    $topProgramIds = array_slice(array_keys($programTrendTotals), 0, 5);

    foreach ($topProgramIds as $programId) {
        $seriesData = [];
        foreach ($trendDateKeys as $dayKey) {
            $seriesData[] = (int) ($programTrendBuckets[$programId]['days'][$dayKey] ?? 0);
        }

        $programTrendSeries[] = [
            'name' => $programTrendBuckets[$programId]['label'],
            'data' => $seriesData
        ];
    }

    $programTrendLabels = $trendDateDisplay;
    $programTrendStmt->close();
}

 ?>

<!DOCTYPE html>

<!-- =========================================================
* Sneat - Bootstrap 5 HTML Admin Template - Pro | v1.0.0
==============================================================

* Product Page: https://themeselection.com/products/sneat-bootstrap-html-admin-template/
* Created by: ThemeSelection
* License: You must have a valid license purchased in order to legally use the theme for your project.
* Copyright ThemeSelection (https://themeselection.com)

=========================================================
 -->
<!-- beautify ignore:start -->
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

    <title>Dashboard - interview</title>

    <meta name="description" content="" />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <!-- Icons. Uncomment required icon fonts -->
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .admin-campus-list {
        display: flex;
        flex-direction: column;
        gap: 0.72rem;
      }

      .admin-campus-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.8rem;
        padding: 0.78rem 0.82rem;
        border: 1px solid #e4e8f0;
        border-radius: 0.75rem;
        background: #fff;
        transition: all 0.2s ease;
      }

      .admin-campus-card:hover {
        border-color: #c8d0e0;
        box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
        transform: translateY(-1px);
      }

      .admin-campus-main {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        min-width: 0;
      }

      .admin-campus-icon {
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1rem;
        flex: 0 0 36px;
      }

      .admin-campus-code {
        font-size: 0.73rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c7d93;
        font-weight: 700;
        line-height: 0.92rem;
      }

      .admin-campus-name {
        font-size: 0.95rem;
        color: #364152;
        font-weight: 600;
        line-height: 1.18rem;
      }

      .admin-campus-sub {
        display: block;
        margin-top: 0.12rem;
        font-size: 0.73rem;
        color: #8391a7;
      }

      .admin-campus-total {
        text-align: right;
      }

      .admin-campus-total-number {
        font-size: 1.22rem;
        font-weight: 700;
        color: #2f3f59;
        line-height: 1.05;
      }

      .admin-campus-total-label {
        display: block;
        margin-top: 0.12rem;
        font-size: 0.72rem;
        color: #8391a7;
      }

      .admin-campus-accent-primary { border-left: 4px solid #696cff; }
      .admin-campus-accent-success { border-left: 4px solid #71dd37; }
      .admin-campus-accent-info { border-left: 4px solid #03c3ec; }
      .admin-campus-accent-warning { border-left: 4px solid #ffab00; }
      .admin-campus-accent-danger { border-left: 4px solid #ff3e1d; }
      .admin-campus-accent-secondary { border-left: 4px solid #8592a3; }
    </style>

  </head>

  <body>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <!-- Menu -->
        <?php 
          include 'sidebar.php';

        ?>

        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">
          <!-- Navbar -->

            <?php 
              include 'header.php';
            ?>

          <!-- / Navbar -->

          <!-- Content wrapper -->
          <div class="content-wrapper">
            <!-- Content -->

<div class="container-xxl flex-grow-1 container-p-y">
  <div class="row">

    <!-- ================= LEFT COLUMN ================= -->
    <div class="col-lg-8 col-12">

      <!-- HERO CARD -->
      <div class="card mb-4">
        <div class="d-flex align-items-end row">
          <div class="col-sm-7">
            <div class="card-body">
              <h5 class="card-title text-primary">
                Administrator Dashboard
              </h5>
              <p class="mb-4">
                Monitor placement result uploads, campus coverage, and account readiness
                from one central panel.
              </p>
              <div class="d-flex flex-wrap gap-2 mb-4">
                <span class="badge bg-label-primary">
                  Active Campuses: <?= count($campuses); ?>
                </span>
                <span class="badge bg-label-info">
                  Latest Batch:
                  <?= $activeBatchId ? htmlspecialchars((string) $activeBatchId) : 'No active batch'; ?>
                </span>
                <span class="badge bg-label-success">
                  Records in Batch: <?= number_format(array_sum($qualitativeCounts)); ?>
                </span>
              </div>

              <a href="accounts/index.php" class="btn btn-sm btn-primary me-2">
                Manage Accounts
              </a>
              <a href="placement_results/index.php" class="btn btn-sm btn-outline-primary">
                Manage Placement Results
              </a>
            </div>
          </div>
          <div class="col-sm-5 text-center text-sm-left">
            <div class="card-body pb-0 px-0 px-md-4">
              <img
                src="../assets/img/illustrations/man-with-laptop-light.png"
                height="140"
                alt="Administrator dashboard overview"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- GRAPH CARD -->
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title mb-3">
            Interview Volume Trend by Program (Last 15 Days)
          </h5>
          <div id="programInterviewTrendChart"></div>
        </div>
      </div>

    </div>

    <!-- ================= RIGHT COLUMN ================= -->
    <div class="col-lg-4 col-12">

      <div class="card mb-4">
        <div class="card-body">
          <h6 class="mb-3 text-uppercase text-muted">
            Campus Interview Status
          </h6>

          <div class="admin-campus-list">
            <?php
              $campusCardStyles = [
                  ['icon' => 'bx-buildings', 'badge' => 'bg-label-primary', 'accent' => 'admin-campus-accent-primary'],
                  ['icon' => 'bx-map', 'badge' => 'bg-label-success', 'accent' => 'admin-campus-accent-success'],
                  ['icon' => 'bx-landscape', 'badge' => 'bg-label-info', 'accent' => 'admin-campus-accent-info'],
                  ['icon' => 'bx-school', 'badge' => 'bg-label-warning', 'accent' => 'admin-campus-accent-warning'],
                  ['icon' => 'bx-arch', 'badge' => 'bg-label-danger', 'accent' => 'admin-campus-accent-danger'],
                  ['icon' => 'bx-compass', 'badge' => 'bg-label-secondary', 'accent' => 'admin-campus-accent-secondary']
              ];
            ?>

            <?php foreach ($campuses as $idx => $campus): ?>
              <?php
                $style = $campusCardStyles[$idx % count($campusCardStyles)];
                $interviewedCount = (int) ($campus['interviewed_count'] ?? 0);
              ?>
              <div class="admin-campus-card <?= $style['accent']; ?>">
                <div class="admin-campus-main">
                  <span class="admin-campus-icon <?= $style['badge']; ?>">
                    <i class="bx <?= $style['icon']; ?>"></i>
                  </span>
                  <div>
                    <div class="admin-campus-code">
                      <?= htmlspecialchars($campus['campus_code']); ?>
                    </div>
                    <div class="admin-campus-name">
                      <?= htmlspecialchars($campus['campus_name']); ?>
                    </div>
                    <small class="admin-campus-sub">
                      Interviewed (Scored)
                    </small>
                  </div>
                </div>

                <div class="admin-campus-total">
                  <div class="admin-campus-total-number">
                    <?= number_format($interviewedCount); ?>
                  </div>
                  <small class="admin-campus-total-label">Interviewed</small>
                </div>
              </div>
            <?php endforeach; ?>

            <?php if (empty($campuses)): ?>
              <div class="text-muted small">
                No active campus records found.
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>

    </div>

  </div>
</div>

            <!-- / Content -->

            <!-- Footer -->
              <?php 
                include '../footer.php';

               ?>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>

      <!-- Overlay -->
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>
    <!-- / Layout wrapper -->

    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>

    <script src="../assets/vendor/js/menu.js"></script>
    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>

    <!-- Main JS -->
    <script src="../assets/js/main.js"></script>

    <!-- Page JS -->
    <script src="../assets/js/dashboards-analytics.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const trendCategories = <?php echo json_encode($programTrendLabels); ?>;
  const trendSeries = <?php echo json_encode($programTrendSeries); ?>;
  const chartContainer = document.querySelector("#programInterviewTrendChart");

  if (!chartContainer) return;
  if (!Array.isArray(trendSeries) || trendSeries.length === 0) {
    chartContainer.innerHTML = '<div class="text-muted small py-3">No interview trend data available for the last 15 days.</div>';
    return;
  }

  const options = {
    chart: {
      type: 'line',
      height: 350,
      toolbar: { show: false }
    },
    series: trendSeries,
    xaxis: {
      categories: trendCategories,
      labels: {
        rotate: -45,
        hideOverlappingLabels: true
      }
    },
    stroke: {
      curve: 'smooth',
      width: 3
    },
    markers: {
      size: 4,
      strokeWidth: 0
    },
    colors: ['#696cff', '#03c3ec', '#71dd37', '#ffab00', '#ff3e1d'],
    dataLabels: {
      enabled: false
    },
    grid: {
      strokeDashArray: 4
    },
    legend: {
      position: 'top'
    },
    tooltip: {
      shared: true,
      intersect: false
    },
    yaxis: {
      title: {
        text: 'Number of Interviews'
      }
    }
  };

  const chart = new ApexCharts(
    chartContainer,
    options
  );

  chart.render();
});
</script>

  </body>
</html>
