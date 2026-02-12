<?php 
  require_once '../config/db.php';
  session_start();

/**
 * ============================================================================
 * PROGRAMS UNDER ASSIGNED CAMPUS
 * ============================================================================
 */

$assignedCampusId  = $_SESSION['campus_id'];
$assignedProgramId = $_SESSION['program_id'];

$programs = [];

$programSql = "
    SELECT 
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        pc.cutoff_score
    FROM tbl_program p
    INNER JOIN tbl_college c 
        ON p.college_id = c.college_id
    LEFT JOIN tbl_program_cutoff pc
        ON p.program_id = pc.program_id
    WHERE c.campus_id = ?
      AND p.status = 'active'
    ORDER BY 
        (p.program_id = ?) DESC,
        p.program_name ASC
";


$stmtProgram = $conn->prepare($programSql);
$stmtProgram->bind_param("ii", $assignedCampusId, $assignedProgramId);
$stmtProgram->execute();
$resultProgram = $stmtProgram->get_result();

while ($row = $resultProgram->fetch_assoc()) {
    $programs[] = $row;
}



/**
 * ============================================================================
 * STEP 0 – PROGRAM CHAIR IDENTITY & ASSIGNMENT
 * ============================================================================
 */

// Guard (Program Chair only)
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php?status=pending_validation');
    exit;
}

$accountId = (int) $_SESSION['accountid'];

// Fetch Program Chair profile + assignment
$profileSql = "
    SELECT
        a.acc_fullname,
        a.email,
        a.role,

        c.campus_name,
        c.campus_code,

        p.program_name,
        p.program_code,
        p.major,

        co.college_name
    FROM tblaccount a
    LEFT JOIN tbl_campus  c  ON a.campus_id  = c.campus_id
    LEFT JOIN tbl_program p  ON a.program_id = p.program_id
    LEFT JOIN tbl_college co ON p.college_id = co.college_id
    WHERE a.accountid = ?
    LIMIT 1
";

$stmt = $conn->prepare($profileSql);
$stmt->bind_param("i", $accountId);
$stmt->execute();
$profileResult = $stmt->get_result();

$profile = $profileResult->fetch_assoc();

// Safety fallback (should not happen, but clean)
if (!$profile) {
    header('Location: ../index.php?status=pending_validation');
    exit;
}

// Assign variables for reuse
$pc_fullname    = $profile['acc_fullname'];
$pc_email       = $profile['email'];
$pc_role        = 'Program Chair';

$pc_campus_name = $profile['campus_name'];
$pc_program     = $profile['program_name'];
$pc_major       = $profile['major'];
$pc_college     = $profile['college_name'];



/**
 * ============================================================================
 * interview/prograchir/index.php
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



if ($batchResult && $batchResult->num_rows > 0) {
    $activeBatchId = $batchResult->fetch_assoc()['upload_batch_id'];
}

// STEP 2: Initialize qualitative buckets (ENSURE ORDER 1–6)
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
          Welcome, <?= htmlspecialchars($pc_fullname); ?>
        </h5>

        <p class="mb-3">
          You are assigned to
          <strong>
            <?= htmlspecialchars($pc_program); ?>
            <?php if (!empty($pc_major)): ?>
              – <?= htmlspecialchars($pc_major); ?>
            <?php endif; ?>
          </strong>
          under
          <strong><?= htmlspecialchars($pc_campus_name); ?></strong>.
        </p>

        <p class="mb-0 text-muted">
          This dashboard provides an overview of placement test performance.
        </p>
      </div>
    </div>

    <div class="col-sm-5 text-center text-sm-left">
      <div class="card-body pb-0 px-0 px-md-4">
        <img
          src="../assets/img/illustrations/man-with-laptop-light.png"
          height="140"
          alt="Program Chair Dashboard"
        />
      </div>
    </div>
  </div>
</div>


<!-- STUDENT WORKSPACE -->
<div class="card mb-4">
  <div class="card-body">

    <!-- SEARCH -->
    <div class="mb-4">
      <div class="input-group">
        <span class="input-group-text">
          <i class="bx bx-search"></i>
        </span>
        <input
          type="text"
          id="studentSearch"
          class="form-control"
          placeholder="Search by name or examinee number..."
        />
      </div>
    </div>

    <!-- STUDENT LIST CONTAINER -->
    <div id="studentContainer"></div>

    <!-- LOADING INDICATOR -->
    <div id="loadingIndicator" class="text-center py-3 d-none">
      <div class="spinner-border text-primary" role="status"></div>
      <div class="small text-muted mt-2">Loading students...</div>
    </div>

  </div>
</div>


    </div>

<!-- ================= RIGHT COLUMN ================= -->
<div class="col-lg-4 col-12">

<div class="card mb-4">

  <div class="card-header bg-success text-white">
    <h6 class="mb-0">
      Programs in <?= htmlspecialchars($pc_campus_name); ?> Campus
    </h6>
  </div>

  <div class="card-body p-3" style="max-height: 510px; overflow-y: auto;">

    <?php foreach ($programs as $program): ?>
    <?php 
    $assignedProgramId = $_SESSION['program_id'];
    $isAssigned = ($program['program_id'] == $assignedProgramId);
    ?>
      <div class="mb-3 pb-3 border-bottom <?= $isAssigned ? 'bg-label-primary rounded px-2 py-2' : '' ?>">


        <!-- Program Name -->
        <div class="fw-semibold small text-dark">
          <?= strtoupper($program['program_name']) ?>
        </div>

        <!-- Major -->
        <?php if (!empty($program['major'])): ?>
          <div class="text-muted small">
            <?= strtoupper($program['major']) ?>
          </div>
        <?php endif; ?>

        <!-- Cutoff Logic -->
        <?php
          $cutoff = $program['cutoff_score'];

          if ($cutoff === null) {
              $badgeClass = 'bg-label-danger';   // light red
              $cutoffText = 'CUT-OFF: NOT SET';
          } else {
              $badgeClass = 'bg-label-success';  // green
              $cutoffText = 'CUT-OFF: ' . $cutoff;
          }
        ?>

        <div class="mt-2">
          <span class="badge <?= $badgeClass ?> small">
            <?= $cutoffText ?>
          </span>
        </div>

      </div>

    <?php endforeach; ?>

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
    <script src="../assets/js/main.js"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const container = document.getElementById("studentContainer");
  const searchInput = document.getElementById("studentSearch");
  const loadingIndicator = document.getElementById("loadingIndicator");

  let page = 1;
  let isLoading = false;
  let hasMore = true;
  let currentSearch = '';

function createStudentCard(student) {
  return `
    <div class="card mb-2 shadow-sm border-0"
         style="
           border-left: 6px solid #28c76f;
           background: linear-gradient(to right, #e9f9f1 0%, #ffffff 8%);
         ">

      <div class="card-body py-2 px-3">

        <div class="d-flex justify-content-between align-items-center">

          <!-- LEFT SIDE -->
          <div>
            <div class="fw-semibold small mb-1">
              ${student.full_name}
            </div>
            <small class="text-muted">
              Examinee #: ${student.examinee_number}
              &nbsp; | &nbsp;
              SAT: ${student.sat_score}
              &nbsp; | &nbsp;
              ${student.qualitative_text}
            </small>
          </div>

          <!-- RIGHT SIDE BUTTON -->
          <div class="ms-3">
            <button class="btn btn-sm btn-primary px-3 py-1">
              Interview
            </button>
          </div>

        </div>

      </div>
    </div>
  `;
}





  function loadStudents(reset = false) {
    if (isLoading || !hasMore) return;

    isLoading = true;
    loadingIndicator.classList.remove("d-none");

    fetch(`fetch_students.php?page=${page}&search=${encodeURIComponent(currentSearch)}`)
      .then(res => res.json())
      .then(data => {
        if (!data.success) return;

        if (reset) {
          container.innerHTML = '';
        }

        if (data.data.length === 0) {
          hasMore = false;
        } else {
          data.data.forEach(student => {
            container.insertAdjacentHTML('beforeend', createStudentCard(student));
          });
        }

        isLoading = false;
        loadingIndicator.classList.add("d-none");
      })
      .catch(err => {
        console.error(err);
        isLoading = false;
        loadingIndicator.classList.add("d-none");
      });
  }

  // Infinite Scroll
  window.addEventListener("scroll", function () {
    if ((window.innerHeight + window.scrollY) >= document.body.offsetHeight - 200) {
      if (!isLoading && hasMore) {
        page++;
        loadStudents();
      }
    }
  });

  // Debounced Search
  let searchTimeout;
  searchInput.addEventListener("input", function () {
    clearTimeout(searchTimeout);

    searchTimeout = setTimeout(() => {
      currentSearch = searchInput.value.trim();
      page = 1;
      hasMore = true;
      loadStudents(true);
    }, 400);
  });

  // Initial Load
  loadStudents();

});
</script>


  </body>
</html>
