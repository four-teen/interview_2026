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
    SELECT campus_id, campus_code, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC
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
                Congratulations John!
              </h5>
              <p class="mb-4">
                You have done <span class="fw-bold">72%</span> more sales today.
                Check your new badge in your profile.
              </p>
              <a href="javascript:;" class="btn btn-sm btn-outline-primary">
                View Badges
              </a>
            </div>
          </div>
          <div class="col-sm-5 text-center text-sm-left">
            <div class="card-body pb-0 px-0 px-md-4">
              <img
                src="../assets/img/illustrations/man-with-laptop-light.png"
                height="140"
                alt="View Badge User"
              />
            </div>
          </div>
        </div>
      </div>

      <!-- GRAPH CARD -->
      <div class="card mb-4">
        <div class="card-body">
          <h5 class="card-title mb-3">
            Placement Test Performance Overview
          </h5>
          <div id="placementQualitativeChart"></div>
        </div>
      </div>

    </div>

    <!-- ================= RIGHT COLUMN ================= -->
    <div class="col-lg-4 col-12">

      <div class="row">
        <?php foreach ($campuses as $campus): ?>
          <div class="col-lg-6 col-md-12 col-6 mb-4">
            <div class="card h-100">
              <div class="card-body">

                <div class="d-flex justify-content-between align-items-start mb-2">
                  <div class="avatar flex-shrink-0">
                    <span class="avatar-initial rounded bg-label-primary">
                      <?= htmlspecialchars($campus['campus_code']); ?>
                    </span>
                  </div>
                  <i class="bx bx-dots-vertical-rounded text-muted"></i>
                </div>

                <span class="fw-semibold d-block mb-1">
                  <?= htmlspecialchars($campus['campus_name']); ?>
                </span>

                <h3 class="card-title mb-1">
                  -
                </h3>

                <small class="text-muted">
                  Examinees
                </small>

              </div>
            </div>
          </div>
        <?php endforeach; ?>
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

  const qualitativeCategories = <?php echo json_encode(array_values($qualitativeLabels)); ?>;
  const qualitativeData = <?php echo json_encode(array_values($qualitativeCounts)); ?>;

  const options = {
    chart: {
      type: 'bar',
      height: 350,
      toolbar: { show: false }
    },
    series: [{
      name: 'Number of Examinees',
      data: qualitativeData
    }],
    xaxis: {
      categories: qualitativeCategories,
      labels: {
        rotate: -45
      }
    },
    plotOptions: {
      bar: {
        borderRadius: 6,
        columnWidth: '45%'
      }
    },
    colors: ['#696cff'], // Sneat primary
    dataLabels: {
      enabled: true
    },
    grid: {
      strokeDashArray: 4
    },
    yaxis: {
      title: {
        text: 'Number of Examinees'
      }
    }
  };

  const chart = new ApexCharts(
    document.querySelector("#placementQualitativeChart"),
    options
  );

  chart.render();
});
</script>

  </body>
</html>
