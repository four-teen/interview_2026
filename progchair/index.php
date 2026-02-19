<?php 
  require_once '../config/db.php';
  session_start();


$APP_DEBUG = getenv('APP_DEBUG') === '1';
ini_set('display_errors', $APP_DEBUG ? '1' : '0');
error_reporting(E_ALL);

/**
 * ============================================================================
 * root/progchair/index.php
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
        pc.cutoff_score,
        COALESCE(sc.scored_students_count, 0) AS scored_students_count
    FROM tbl_program p
    INNER JOIN tbl_college c 
        ON p.college_id = c.college_id
    LEFT JOIN tbl_program_cutoff pc
        ON p.program_id = pc.program_id
    LEFT JOIN (
        SELECT
            first_choice AS program_id,
            COUNT(*) AS scored_students_count
        FROM tbl_student_interview
        WHERE final_score IS NOT NULL
          AND status = 'active'
        GROUP BY first_choice
    ) sc
        ON p.program_id = sc.program_id
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

// ======================================================
// LOAD ALL PROGRAMS (for modal dropdowns)
// ======================================================
$allPrograms = [];

$allProgramSql = "
    SELECT program_id, program_name, major
    FROM tbl_program
    WHERE status = 'active'
    ORDER BY program_name ASC
";

$allProgramResult = $conn->query($allProgramSql);

if ($allProgramResult) {
    while ($row = $allProgramResult->fetch_assoc()) {
        $allPrograms[] = $row;
    }
}


// ======================================================
// LOAD ALL SHS TRACKS (tb_ltrack)
// ======================================================
$allTracks = [];

$trackSql = "
    SELECT trackid AS track_id, track AS track_name
    FROM tb_ltrack
    ORDER BY track ASC
";

$trackResult = $conn->query($trackSql);

if ($trackResult) {
    while ($row = $trackResult->fetch_assoc()) {
        $allTracks[] = $row;
    }
}


// ======================================================
// LOAD ETG CLASSES
// ======================================================
$etgClasses = [];

$etgSql = "
    SELECT etgclassid AS etg_class_id,
           class_desc AS class_name
    FROM tbl_etg_class
    ORDER BY class_desc ASC
";

$etgResult = $conn->query($etgSql);

if ($etgResult) {
    while ($row = $etgResult->fetch_assoc()) {
        $etgClasses[] = $row;
    }
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
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
<style>
/* =========================================================
   SCORE BADGE ENHANCEMENT
   File: root_folder/interview/progchair/index.php
========================================================= */

.score-badge {
  font-size: 0.85rem;
  letter-spacing: 0.5px;
}
/* =========================================
   STUDENT CARD HOVER EFFECT
========================================= */

.student-card {
  transition: all 0.25s ease-in-out;
  cursor: pointer;
}

.student-card:hover {
  transform: scale(1.015);
  box-shadow: 0 8px 18px rgba(0, 0, 0, 0.08);
}

.program-choice-selection .select2-selection__rendered {
  text-transform: uppercase;
}

.program-choice-dropdown .select2-results__option {
  text-transform: uppercase;
}

.select2-container--default .select2-selection--single {
  height: calc(2.25rem + 2px);
  border: 1px solid #d9dee3;
}

.select2-container--default .select2-selection--single .select2-selection__rendered {
  line-height: calc(2.25rem + 2px);
  padding-left: 0.9rem;
}

.select2-container--default .select2-selection--single .select2-selection__arrow {
  height: calc(2.25rem + 2px);
}

.programs-card {
  display: flex;
  flex-direction: column;
  height: clamp(320px, calc(100vh - 170px), 880px);
}

.programs-card-body {
  flex: 1 1 auto;
  min-height: 0;
  overflow-y: auto;
}

.program-rank-trigger {
  cursor: pointer;
  transition: background-color 0.2s ease;
}

.program-rank-trigger:hover {
  background-color: #f5f7ff;
}

.program-rank-trigger:focus {
  outline: 2px solid #696cff;
  outline-offset: 2px;
}

.program-score-badge {
  font-size: 0.72rem;
  font-weight: 700;
  letter-spacing: 0.2px;
  padding: 0.4rem 0.55rem;
  min-width: 90px;
  text-align: center;
}

.program-meta-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

@media (max-width: 991.98px) {
  .programs-card {
    height: auto;
  }

  .programs-card-body {
    max-height: 50vh;
  }
}

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

    <!-- QUALIFIED COUNT -->
<div class="mb-4 d-flex justify-content-between align-items-center">
  <small class="text-muted">Use the header search bar to find students.</small>
  <div class="ms-3">
    <span class="badge bg-label-success fs-6" id="qualifiedCountBadge">
      0 Qualified
    </span>
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

<div class="card mb-4 programs-card">

  <div class="card-header bg-success text-white">
    <h6 class="mb-0">
      Programs in <?= htmlspecialchars($pc_campus_name); ?> Campus
    </h6>
  </div>

  <div class="card-body p-3 programs-card-body">

    <?php foreach ($programs as $program): ?>
    <?php 
    $assignedProgramId = $_SESSION['program_id'];
    $isAssigned = ($program['program_id'] == $assignedProgramId);
    ?>
      <div class="mb-3 pb-3 border-bottom program-rank-trigger <?= $isAssigned ? 'bg-label-primary rounded px-2 py-2' : '' ?>"
           data-program-id="<?= (int)$program['program_id']; ?>"
           data-program-name="<?= htmlspecialchars(strtoupper($program['program_name'] . (!empty($program['major']) ? ' - ' . $program['major'] : ''))); ?>"
           tabindex="0"
           role="button"
           aria-label="View ranking for <?= htmlspecialchars($program['program_name']); ?>">

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

        <div class="mt-2 program-meta-row">
          <span class="badge <?= $badgeClass ?> small">
            <?= $cutoffText ?>
          </span>
          <span class="badge bg-primary program-score-badge">
            SCORED: <?= (int)($program['scored_students_count'] ?? 0); ?>
          </span>
        </div>

      </div>

    <?php endforeach; ?>

  </div>

</div>

</div>

<!-- ========================================================= -->
<!-- PROGRAM RANKING MODAL -->
<!-- ========================================================= -->
<div class="modal fade" id="programRankingModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">

      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1" id="programRankingTitle">Program Ranking</h5>
          <small class="text-muted" id="programRankingMeta"></small>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div class="d-flex justify-content-end gap-2 mb-3">
          <button type="button" class="btn btn-label-secondary" id="printRankingBtn">
            <i class="bx bx-printer me-1"></i> Print
          </button>
          <a href="#" class="btn btn-success" id="exportRankingBtn">
            <i class="bx bx-export me-1"></i> Export Excel
          </a>
        </div>

        <div id="programRankingLoading" class="text-center py-4 d-none">
          <div class="spinner-border text-primary" role="status"></div>
          <div class="small text-muted mt-2">Loading ranking...</div>
        </div>

        <div id="programRankingEmpty" class="alert alert-warning d-none mb-0">
          No ranked students found for this program.
        </div>

        <div class="table-responsive d-none" id="programRankingTableWrap">
          <table class="table table-bordered table-striped align-middle mb-0" id="programRankingTable">
            <thead class="table-light">
              <tr>
                <th style="width: 80px;">Rank</th>
                <th style="width: 150px;">Examinee #</th>
                <th>Student Name</th>
                <th style="width: 120px;">SAT</th>
                <th style="width: 130px;">Final Score</th>
                <th>Encoded By</th>
              </tr>
            </thead>
            <tbody></tbody>
          </table>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

            <!-- / Content -->

<!-- ========================================================= -->
<!-- STUDENT DETAILS VERIFICATION MODAL -->
<!-- File Path: root_folder/progchair/index.php -->
<!-- ========================================================= -->
<div class="modal fade" id="studentModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form id="studentInterviewForm" autocomplete="off">

        <div class="modal-header">
          <div>
            <h5 class="modal-title mb-1">Student Details Verification</h5>
            <small class="text-muted">Date/Time: <?= date('F d, Y h:i A'); ?></small>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body">

          <!-- Hidden -->
          <input type="hidden" name="placement_result_id" id="placement_result_id">
          <input type="hidden" name="examinee_number" id="examinee_number">
          <!-- Interview ID (for update mode) -->
          <input type="hidden" name="interview_id" id="interview_id">          

<!-- STUDENT SUMMARY CARD -->
<div class="card border-0 shadow-sm mb-4"
     style="border-left: 5px solid #28c76f; background: #f4fff8;">
  <div class="card-body py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap">

      <div>
        <div class="fw-semibold text-dark">
          <i class="bx bx-user me-1"></i>
          <span id="display_name"></span>
        </div>
        <small class="text-muted">
          Examinee #: <strong id="display_examinee"></strong>
        </small>
      </div>

      <div class="text-end">
        <small class="text-muted">SAT Score</small>
        <div class="fw-bold text-success fs-5" id="display_sat"></div>
      </div>

<div id="viewOnlyExtra" class="text-end mt-2 d-none">
  <small class="text-muted">Interview Score</small>
  <div class="fw-bold text-warning fs-5" id="display_final_score"></div>
  <small class="text-muted d-block mt-1">
    Encoded by: <strong id="display_encoded_by"></strong>
  </small>
</div>

    </div>

  </div>
</div>


          <hr>

<!-- CLASSIFICATION + ETG CLASS (ONE LINE) -->
<div class="row mb-3">

  <!-- FIRST SELECT -->
  <div class="col-md-6">
    <label class="form-label">
      Classification <span class="text-danger">*</span>
    </label>
    <select name="classification"
            id="classification"
            class="form-select"
            required>
      <option value="REGULAR">REGULAR</option>
      <option value="ETG">ETG</option>
    </select>
  </div>

  <!-- SECOND SELECT (DEPENDENT) -->
  <div class="col-md-6">
    <label class="form-label">
      ETG Classification <span class="text-danger">*</span>
    </label>

    <select name="etg_class_id"
            id="etg_class_id"
            class="form-select"
            required>

      <!-- DEFAULT FOR REGULAR -->
      <option value="">Select ETG Class</option>

      <!-- ETG OPTIONS -->
      <?php foreach ($etgClasses as $etg): ?>
        <option value="<?= (int)$etg['etg_class_id']; ?>">
          <?= htmlspecialchars($etg['class_name']); ?>
        </option>
      <?php endforeach; ?>

    </select>

  </div>

</div>




          <!-- MOBILE + SHS TRACK (ONE LINE) -->
          <div class="row mb-3">
            <div class="col-md-6 mb-3 mb-md-0">
              <label class="form-label">Mobile Number <span class="text-danger">*</span></label>
              <input type="text" name="mobile_number" class="form-control" required>
            </div>

            <div class="col-md-6">
              <label class="form-label">SHS Track <span class="text-danger">*</span></label>
              <select name="shs_track_id" class="form-select" required>
                <option value="">Select Track</option>
                <?php foreach ($allTracks as $track): ?>
                  <option value="<?= (int)$track['track_id']; ?>">
                    <?= htmlspecialchars($track['track_name']); ?>
                  </option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>

          <!-- PROGRAM CHOICES -->
          <?php
          function renderProgramOptions($programs) {
              foreach ($programs as $p) {
                  $display = strtoupper($p['program_name'] . (!empty($p['major']) ? ' - ' . $p['major'] : ''));
                  echo '<option value="' . (int)$p['program_id'] . '">' . htmlspecialchars($display) . '</option>';
              }
          }
          ?>

          <div class="mb-3">
            <label class="form-label">1st Choice <span class="text-danger">*</span></label>
            <select name="first_choice" id="first_choice" class="form-select js-program-choice" required disabled>
              <option value="">SELECT PROGRAM</option>
              <?php renderProgramOptions($allPrograms); ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">2nd Choice <span class="text-danger">*</span></label>
            <select name="second_choice" class="form-select js-program-choice" required>
              <option value="">SELECT PROGRAM</option>
              <?php renderProgramOptions($allPrograms); ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label">3rd Choice <span class="text-danger">*</span></label>
            <select name="third_choice" class="form-select js-program-choice" required>
              <option value="">SELECT PROGRAM</option>
              <?php renderProgramOptions($allPrograms); ?>
            </select>
          </div>

          

        </div>

        <div class="modal-footer">

          <button type="button"
                  class="btn btn-label-secondary"
                  data-bs-dismiss="modal">
            Close
          </button>

          <!-- NEW: Enter Scores Button -->
          <button type="button"
                  id="enterScoresBtn"
                  class="btn btn-primary d-none">
            Enter Scores
          </button>

          <button type="submit"
                  id="saveInterviewBtn"
                  class="btn btn-success">
            Save
          </button>

        </div>


      </form>

    </div>
  </div>
</div>

<!-- ========================================================= -->
<!-- STUDENT DETAILS VIEW-ONLY MODAL (GREEN BUTTON) -->
<!-- File Path: root_folder/interview/progchair/index.php -->
<!-- ========================================================= -->
<div class="modal fade" id="studentViewModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <div class="modal-header">
        <h5 class="modal-title">Student Details Verification</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">

        <!-- STUDENT SUMMARY CARD (same look/feel as manage modal) -->
        <div class="card border-0 shadow-sm mb-4"
             style="border-left: 5px solid #28c76f; background: #f4fff8;">
          <div class="card-body py-3">

            <div class="d-flex justify-content-between align-items-center flex-wrap">

              <div>
                <div class="fw-semibold text-dark">
                  <i class="bx bx-user me-1"></i>
                  <span id="v_display_name"></span>
                </div>
                <small class="text-muted">
                  Examinee #: <strong id="v_display_examinee"></strong>
                </small>
              </div>

              <div class="text-end">
                <small class="text-muted">SAT Score</small>
                <div class="fw-bold text-success fs-5" id="v_display_sat"></div>
              </div>

              <div class="text-end mt-2">
                <small class="text-muted">Interview Score</small>
                <div class="fw-bold text-warning fs-5" id="v_display_final_score"></div>
                <small class="text-muted d-block mt-1">
                  Encoded by: <strong id="v_display_encoded_by"></strong>
                </small>
              </div>

            </div>

          </div>
        </div>

        <hr>

        <!-- SIMPLE INFO LIST (NO INPUT BOXES) -->
        <div class="row g-3">

          <div class="col-md-6">
            <small class="text-muted d-block">Classification</small>
            <div class="fw-semibold" id="v_classification"></div>
          </div>

          <div class="col-md-6">
            <small class="text-muted d-block">ETG Classification</small>
            <div class="fw-semibold" id="v_etg_class"></div>
          </div>

          <div class="col-md-6">
            <small class="text-muted d-block">Mobile Number</small>
            <div class="fw-semibold" id="v_mobile_number"></div>
          </div>

          <div class="col-md-6">
            <small class="text-muted d-block">SHS Track</small>
            <div class="fw-semibold" id="v_shs_track"></div>
          </div>

          <div class="col-12">
            <small class="text-muted d-block">1st Choice</small>
            <div class="fw-semibold" id="v_first_choice"></div>
          </div>

          <div class="col-12">
            <small class="text-muted d-block">2nd Choice</small>
            <div class="fw-semibold" id="v_second_choice"></div>
          </div>

          <div class="col-12">
            <small class="text-muted d-block">3rd Choice</small>
            <div class="fw-semibold" id="v_third_choice"></div>
          </div>

          <div class="col-12">
            <small class="text-muted d-block">Date/Time</small>
            <div class="fw-semibold" id="v_interview_datetime"></div>
          </div>

        </div>

      </div>

      <!-- FOOTER: CLOSE ONLY -->
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
      </div>

    </div>
  </div>
</div>





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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
document.addEventListener("DOMContentLoaded", function () {

  const container = document.getElementById("studentContainer");
  const navbarSearchInput = document.querySelector('#layout-navbar input[aria-label="Search..."]');
  const legacySearchInput = document.getElementById("studentSearch");
  const searchInput = navbarSearchInput || legacySearchInput;
  const loadingIndicator = document.getElementById("loadingIndicator");

  let page = 1;
  let isLoading = false;
  let hasMore = true;
  let currentSearch = '';
  const assignedProgramId = <?= (int) $assignedProgramId; ?>;

  if (navbarSearchInput) {
    navbarSearchInput.placeholder = 'Search by name or examinee number...';
    navbarSearchInput.setAttribute('aria-label', 'Search by name or examinee number...');
  }

  function initProgramChoiceSelects() {
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;

    $('.js-program-choice').select2({
      width: '100%',
      placeholder: 'SELECT PROGRAM',
      dropdownParent: $('#studentModal'),
      dropdownCssClass: 'program-choice-dropdown',
      selectionCssClass: 'program-choice-selection'
    });
  }

  function setProgramChoiceValue(fieldName, fieldValue) {
    const el = document.querySelector(`#studentInterviewForm select[name="${fieldName}"]`);
    if (!el) return;

    el.value = fieldValue || '';

    if (typeof $ !== 'undefined' && $.fn && $.fn.select2) {
      $(el).trigger('change.select2');
    } else {
      el.dispatchEvent(new Event('change', { bubbles: true }));
    }
  }

  function enforceLockedFirstChoice(lockToAssigned = false) {
    const firstChoiceEl = document.querySelector('#studentInterviewForm select[name="first_choice"]');
    if (!firstChoiceEl) return;

    if (lockToAssigned && assignedProgramId > 0) {
      setProgramChoiceValue('first_choice', assignedProgramId);
    }

    firstChoiceEl.disabled = true;

    if (typeof $ !== 'undefined' && $.fn && $.fn.select2) {
      $(firstChoiceEl)
        .prop('disabled', true)
        .trigger('change.select2');
    }
  }

  function resetProgramChoices() {
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;
    $('.js-program-choice').val('').trigger('change.select2');
  }

  function refreshProgramChoiceSelects() {
    if (typeof $ === 'undefined' || !$.fn || !$.fn.select2) return;
    $('.js-program-choice').each(function () {
      $(this).prop('disabled', this.disabled).trigger('change.select2');
    });
  }

  initProgramChoiceSelects();

/**
 * ============================================================
 * BUILD STUDENT CARD HTML
 * File: root_folder/interview/progchair/index.php
 * ============================================================
 */
function buildStudentCardHtml(student, buttonHtml) {

  let interviewBadge = '';

  if (student.final_score !== null && student.final_score !== undefined) {
    const formattedScore = Number(student.final_score).toFixed(2);
    interviewBadge = `
      &nbsp; | &nbsp;
      <span class="badge score-badge text-warning bg-white fw-bold px-3 py-2">
        SCORE: ${formattedScore}%
      </span>
    `;
  }

  return `
    <div class="card student-card mb-2 shadow-sm border-0"
         style="
           border-left: 6px solid #28c76f;
           background: linear-gradient(to right, #e9f9f1 0%, #ffffff 8%);
         ">

      <div class="card-body py-2 px-3">
        <div class="d-flex justify-content-between align-items-center">

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
              ${interviewBadge}
            </small>
          </div>

          <div class="ms-3">
            ${buttonHtml}
          </div>

        </div>
      </div>
    </div>
  `;
}


function createStudentCard(student) {

  let buttonHtml = ''; // ✅ MOVE THIS TO TOP

  // =========================================
  // 0️⃣ TRANSFER PENDING → SHOW ACCEPT / REJECT
  // =========================================
  if (student.transfer_pending) {

    buttonHtml = `
      <div class="d-flex align-items-center gap-2">
        <button
          type="button"
          class="btn btn-sm btn-success px-3 py-1"
          onclick="handleTransferAction(event, ${student.transfer_id}, 'accept')"
        >
          Accept
        </button>

        <button
          type="button"
          class="btn btn-sm btn-danger px-3 py-1"
          onclick="handleTransferAction(event, ${student.transfer_id}, 'reject')"
        >
          Reject
        </button>
      </div>
    `;

    return buildStudentCardHtml(student, buttonHtml);
  }

  // =========================================
  // 1️⃣ NO INTERVIEW YET
  // =========================================
  if (!student.has_interview) {

    buttonHtml = `
      <button
        type="button"
        class="btn btn-sm btn-primary px-3 py-1"
        data-bs-toggle="modal"
        data-bs-target="#studentModal"
        data-id="${student.placement_result_id}"
        data-examinee="${student.examinee_number}"
        data-name="${student.full_name}"
        data-score="${student.sat_score}"
        data-has-interview="0"
      >
        Manage
      </button>
    `;
  }

  // =========================================
  // 2️⃣ HAS INTERVIEW & OWNER
  // =========================================
  else if (student.can_edit) {

    const transferButtonHtml = student.has_pending_transfer
      ? `
        <button
          type="button"
          class="btn btn-sm btn-danger px-3 py-1"
          disabled
          title="Transfer request is pending approval"
        >
          Pending Transfer
        </button>
      `
      : `
        <a
          href="transfer_student.php?placement_result_id=${student.placement_result_id}"
          class="btn btn-sm btn-outline-danger px-3 py-1"
        >
          Transfer
        </a>
      `;

    buttonHtml = `
      <div class="d-flex align-items-center gap-2">
        <button
          type="button"
          class="btn btn-sm btn-warning px-3 py-1"
          data-bs-toggle="modal"
          data-bs-target="#studentModal"
          data-id="${student.placement_result_id}"
          data-examinee="${student.examinee_number}"
          data-name="${student.full_name}"
          data-score="${student.sat_score}"
          data-has-interview="1"
        >
          Manage Details
        </button>

        ${transferButtonHtml}
      </div>
    `;
  }

  // =========================================
  // 3️⃣ VIEW ONLY
  // =========================================
  else {

    buttonHtml = `
      <button
        type="button"
        class="btn btn-sm btn-success px-3 py-1"
        data-bs-toggle="modal"
        data-bs-target="#studentViewModal"
        data-id="${student.placement_result_id}"
        data-examinee="${student.examinee_number}"
        data-name="${student.full_name}"
        data-score="${student.sat_score}"
        data-has-interview="1"
      >
        View Details
      </button>
    `;
  }

  return buildStudentCardHtml(student, buttonHtml);
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

          // ✅ Update Qualified Count Badge
          if (typeof data.total !== 'undefined') {
            const badge = document.getElementById('qualifiedCountBadge');
            badge.innerText = data.total + ' Qualified';
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

// 🔥 Make loadStudents globally accessible
window.loadStudents = loadStudents;

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
  if (searchInput) {
    searchInput.addEventListener("input", function () {
      clearTimeout(searchTimeout);

      searchTimeout = setTimeout(() => {
        currentSearch = searchInput.value.trim();
        page = 1;
        hasMore = true;
        loadStudents(true);
      }, 400);
    });
  }

  // Initial Load
  loadStudents();

// ==============================================
// Program Ranking Modal (Right Column Programs)
// ==============================================
const programRankingModalEl   = document.getElementById('programRankingModal');
const programRankingTitleEl   = document.getElementById('programRankingTitle');
const programRankingMetaEl    = document.getElementById('programRankingMeta');
const programRankingLoadingEl = document.getElementById('programRankingLoading');
const programRankingEmptyEl   = document.getElementById('programRankingEmpty');
const programRankingTableWrap = document.getElementById('programRankingTableWrap');
const programRankingTableBody = document.querySelector('#programRankingTable tbody');
const exportRankingBtn        = document.getElementById('exportRankingBtn');
const printRankingBtn         = document.getElementById('printRankingBtn');

let currentRankingProgramId = 0;
let currentRankingProgramName = '';
let currentRankingRows = [];

const programRankingModal = programRankingModalEl
  ? new bootstrap.Modal(programRankingModalEl)
  : null;

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function renderRankingRows(rows) {
  if (!programRankingTableBody) return;

  programRankingTableBody.innerHTML = rows.map((row, index) => `
    <tr>
      <td class="fw-semibold">${index + 1}</td>
      <td>${escapeHtml(row.examinee_number || '')}</td>
      <td class="text-uppercase">${escapeHtml(row.full_name || '')}</td>
      <td>${escapeHtml(row.sat_score ?? '')}</td>
      <td class="fw-semibold text-warning">${escapeHtml(row.final_score ?? '')}</td>
      <td>${escapeHtml(row.encoded_by || 'N/A')}</td>
    </tr>
  `).join('');
}

function setRankingState({ loading = false, empty = false, showTable = false }) {
  if (programRankingLoadingEl) programRankingLoadingEl.classList.toggle('d-none', !loading);
  if (programRankingEmptyEl) programRankingEmptyEl.classList.toggle('d-none', !empty);
  if (programRankingTableWrap) programRankingTableWrap.classList.toggle('d-none', !showTable);
}

function loadProgramRanking(programId, programName) {
  if (!programId) return;

  currentRankingProgramId = programId;
  currentRankingProgramName = programName || 'PROGRAM';
  currentRankingRows = [];

  if (programRankingTitleEl) {
    programRankingTitleEl.textContent = `Program Ranking - ${currentRankingProgramName}`;
  }

  if (programRankingMetaEl) {
    programRankingMetaEl.textContent = 'Loading...';
  }

  if (exportRankingBtn) {
    exportRankingBtn.href = `export_program_ranking.php?program_id=${programId}`;
  }

  setRankingState({ loading: true, empty: false, showTable: false });

  fetch(`get_program_ranking.php?program_id=${programId}`)
    .then(res => res.json())
    .then(data => {
      if (!data.success) {
        throw new Error(data.message || 'Failed to load ranking.');
      }

      currentRankingRows = Array.isArray(data.rows) ? data.rows : [];

      if (programRankingMetaEl) {
        const total = currentRankingRows.length;
        programRankingMetaEl.textContent = `${total} ranked student${total === 1 ? '' : 's'} (highest to lowest)`;
      }

      if (currentRankingRows.length === 0) {
        setRankingState({ loading: false, empty: true, showTable: false });
        return;
      }

      renderRankingRows(currentRankingRows);
      setRankingState({ loading: false, empty: false, showTable: true });
    })
    .catch(err => {
      console.error(err);
      setRankingState({ loading: false, empty: true, showTable: false });

      if (programRankingEmptyEl) {
        programRankingEmptyEl.textContent = err.message || 'Failed to load ranking.';
      }
    });
}

document.querySelectorAll('.program-rank-trigger').forEach((el) => {
  const openRanking = () => {
    const programId = Number(el.getAttribute('data-program-id') || 0);
    const programName = (el.getAttribute('data-program-name') || '').trim();

    if (!programId || !programRankingModal) return;

    if (programRankingEmptyEl) {
      programRankingEmptyEl.textContent = 'No ranked students found for this program.';
    }

    programRankingModal.show();
    loadProgramRanking(programId, programName);
  };

  el.addEventListener('click', openRanking);
  el.addEventListener('keydown', (event) => {
    if (event.key === 'Enter' || event.key === ' ') {
      event.preventDefault();
      openRanking();
    }
  });
});

if (printRankingBtn) {
  printRankingBtn.addEventListener('click', function () {
    if (!currentRankingRows.length) {
      Swal.fire('No Data', 'No ranked students to print.', 'info');
      return;
    }

    const printWindow = window.open('', '_blank');
    if (!printWindow) {
      Swal.fire('Blocked', 'Please allow pop-ups to print ranking.', 'warning');
      return;
    }

    const now = new Date().toLocaleString();
    const tableHtml = programRankingTableWrap ? programRankingTableWrap.innerHTML : '';

    printWindow.document.write(`
      <!DOCTYPE html>
      <html>
      <head>
        <meta charset="utf-8">
        <title>Program Ranking - ${currentRankingProgramName}</title>
        <style>
          body { font-family: Arial, sans-serif; padding: 20px; color: #1f2d3d; }
          h2 { margin: 0 0 6px 0; font-size: 20px; }
          .meta { margin-bottom: 14px; color: #6c757d; font-size: 13px; }
          table { width: 100%; border-collapse: collapse; }
          th, td { border: 1px solid #d9dee3; padding: 8px; font-size: 12px; }
          th { background: #f5f7fa; text-align: left; }
        </style>
      </head>
      <body>
        <h2>Program Ranking - ${currentRankingProgramName}</h2>
        <div class="meta">Generated: ${now}</div>
        ${tableHtml}
      </body>
      </html>
    `);
    printWindow.document.close();
    printWindow.focus();
    printWindow.print();
  });
}



// ==============================================
// Populate Modal (Student Details Verification)
// ==============================================
const studentModal = document.getElementById('studentModal');

if (studentModal) {
  studentModal.addEventListener('show.bs.modal', function (event) {

    const button = event.relatedTarget;

    const placementId  = button.getAttribute('data-id');
    const examinee     = button.getAttribute('data-examinee');
    const name         = button.getAttribute('data-name');
    const score        = button.getAttribute('data-score');
    const hasInterview = button.getAttribute('data-has-interview') == "1";

    // reset first (clears old values)
    document.getElementById('studentInterviewForm').reset();
    document.getElementById('viewOnlyExtra').classList.add('d-none');
    resetProgramChoices();

    // hidden
    document.getElementById('placement_result_id').value = placementId || '';
    document.getElementById('examinee_number').value     = examinee || '';
    document.getElementById('interview_id').value        = '';

    // display
    document.getElementById('display_examinee').innerText = examinee || '';
    document.getElementById('display_name').innerText     = name || '';
    document.getElementById('display_sat').innerText      = score || '';

    const saveButton = document.getElementById('saveInterviewBtn');
    const scoreButton = document.getElementById('enterScoresBtn');

    // ================================
    // INSERT MODE
    // ================================
    if (!hasInterview) {

      saveButton.textContent = 'Save';
      saveButton.classList.remove('btn-secondary');
      saveButton.classList.add('btn-success');
      saveButton.disabled = false;

      document.querySelectorAll('#studentInterviewForm input, #studentInterviewForm select')
        .forEach(el => el.disabled = false);
      refreshProgramChoiceSelects();
      enforceLockedFirstChoice(true);

      document.getElementById('classification').value = 'REGULAR';
      syncEtgUI();

      return;
    }

    // ================================
    // EDIT / VIEW MODE
    // ================================
    fetch(`get_interview.php?placement_result_id=${placementId}`)
      .then(res => res.json())
      .then(data => {

        if (!data.success || !data.exists) return;

        const record = data.data;

        // populate
        document.getElementById('interview_id').value = record.interview_id;
        document.getElementById('classification').value = record.classification;

        document.querySelector('[name="mobile_number"]').value = record.mobile_number || '';
        setProgramChoiceValue('first_choice', assignedProgramId > 0 ? assignedProgramId : record.first_choice);
        setProgramChoiceValue('second_choice', record.second_choice);
        setProgramChoiceValue('third_choice', record.third_choice);
        document.querySelector('[name="shs_track_id"]').value  = record.shs_track_id || '';

        if (record.classification === 'ETG') {
          document.getElementById('etg_class_id').value = record.etg_class_id || '';
        }

        syncEtgUI();

        // OWNER CHECK
if (data.is_owner) {

  // OWNER MODE
  saveButton.textContent = 'Update';
  saveButton.classList.remove('btn-secondary');
  saveButton.classList.add('btn-success');
  saveButton.disabled = false;

  document.querySelectorAll('#studentInterviewForm input, #studentInterviewForm select')
    .forEach(el => el.disabled = false);
  refreshProgramChoiceSelects();
  enforceLockedFirstChoice(true);

  scoreButton.classList.remove('d-none');

  scoreButton.onclick = function () {
    window.location.href = 'interview_scores.php?interview_id=' + record.interview_id;
  };

  // Hide view-only section
  document.getElementById('viewOnlyExtra').classList.add('d-none');

} else {

  // VIEW ONLY MODE
  saveButton.textContent = 'View Only';
  saveButton.classList.remove('btn-success');
  saveButton.classList.add('btn-secondary');
  saveButton.disabled = true;

  document.querySelectorAll('#studentInterviewForm input, #studentInterviewForm select')
    .forEach(el => el.disabled = true);
  refreshProgramChoiceSelects();

  scoreButton.classList.add('d-none');

  // SHOW FINAL SCORE + ENCODED BY
  if (record.final_score !== null && record.final_score !== '') {
    document.getElementById('display_final_score').innerText =
      Number(record.final_score).toFixed(2) + '%';
  } else {
    document.getElementById('display_final_score').innerText = 'No Score';
  }

  document.getElementById('display_encoded_by').innerText =
    record.encoded_by || 'N/A';

  document.getElementById('viewOnlyExtra').classList.remove('d-none');

}



      });

  });
}

// ==============================================
// Populate Modal (VIEW ONLY) - studentViewModal
// ==============================================
const studentViewModal = document.getElementById('studentViewModal');

if (studentViewModal) {
  studentViewModal.addEventListener('show.bs.modal', function (event) {

    const button = event.relatedTarget;

    const placementId  = button.getAttribute('data-id');
    const examinee     = button.getAttribute('data-examinee');
    const name         = button.getAttribute('data-name');
    const score        = button.getAttribute('data-score');

    // Header fields
    document.getElementById('v_display_examinee').innerText = examinee || '';
    document.getElementById('v_display_name').innerText     = name || '';
    document.getElementById('v_display_sat').innerText      = score || '';

    // Reset body fields
    document.getElementById('v_classification').innerText     = '';
    document.getElementById('v_etg_class').innerText          = '';
    document.getElementById('v_mobile_number').innerText      = '';
    document.getElementById('v_first_choice').innerText       = '';
    document.getElementById('v_second_choice').innerText      = '';
    document.getElementById('v_third_choice').innerText       = '';
    document.getElementById('v_shs_track').innerText          = '';
    document.getElementById('v_interview_datetime').innerText = '';

    document.getElementById('v_display_final_score').innerText = 'Loading...';
    document.getElementById('v_display_encoded_by').innerText  = 'Loading...';

    // Fetch interview details
    fetch(`get_interview.php?placement_result_id=${placementId}`)
      .then(res => res.json())
      .then(data => {

        if (!data.success || !data.exists) {
          document.getElementById('v_display_final_score').innerText = 'No Record';
          document.getElementById('v_display_encoded_by').innerText  = 'N/A';
          return;
        }

        const record = data.data;

        // Interview score + encoder
        if (record.final_score !== null && record.final_score !== '') {
          document.getElementById('v_display_final_score').innerText =
            Number(record.final_score).toFixed(2) + '%';
        } else {
          document.getElementById('v_display_final_score').innerText = 'No Score';
        }

        document.getElementById('v_display_encoded_by').innerText =
          record.encoded_by || 'N/A';

        // Simple list fields
        document.getElementById('v_classification').innerText =
          record.classification || '—';

        // ETG class (only meaningful if ETG)
        if (record.classification === 'ETG' && record.etg_class_id) {
          // Pull label from existing select options in the manage modal (no extra queries)
          const opt = document.querySelector(`#etg_class_id option[value="${record.etg_class_id}"]`);
          document.getElementById('v_etg_class').innerText = opt ? opt.textContent.trim() : '—';
        } else {
          document.getElementById('v_etg_class').innerText = '—';
        }

        document.getElementById('v_mobile_number').innerText =
          record.mobile_number || '—';

        // Program choices: pull label from existing select options in manage modal
        const p1 = document.querySelector(`[name="first_choice"] option[value="${record.first_choice}"]`);
        const p2 = document.querySelector(`[name="second_choice"] option[value="${record.second_choice}"]`);
        const p3 = document.querySelector(`[name="third_choice"] option[value="${record.third_choice}"]`);

        document.getElementById('v_first_choice').innerText  = p1 ? p1.textContent.trim().toUpperCase() : '—';
        document.getElementById('v_second_choice').innerText = p2 ? p2.textContent.trim().toUpperCase() : '—';
        document.getElementById('v_third_choice').innerText  = p3 ? p3.textContent.trim().toUpperCase() : '—';

        // SHS Track label from existing select options
        const t = document.querySelector(`[name="shs_track_id"] option[value="${record.shs_track_id}"]`);
        document.getElementById('v_shs_track').innerText = t ? t.textContent.trim() : '—';

        // Datetime
        document.getElementById('v_interview_datetime').innerText =
          record.interview_datetime || '—';

      })
      .catch(() => {
        document.getElementById('v_display_final_score').innerText = 'Error';
        document.getElementById('v_display_encoded_by').innerText  = 'Error';
      });

  });
}


// ==============================================
// ETG CLASS RULES
// - If REGULAR: etg_class_id becomes "not required" and hidden
// - If ETG: show dropdown and required
// ==============================================
function syncEtgUI() {

  const classificationEl = document.getElementById('classification');
  const etgSelectEl      = document.getElementById('etg_class_id');

  if (!classificationEl || !etgSelectEl) return;

  if (classificationEl.value === 'ETG') {

    // Enable dropdown
    etgSelectEl.disabled = false;
    etgSelectEl.required = true;

    // If currently REGULAR, switch to first ETG option
    if (!etgSelectEl.value) {
        if (etgSelectEl.options.length > 1) {
            etgSelectEl.selectedIndex = 1;
        }
    }


  } else {

    // Set to REGULAR
    etgSelectEl.value = '';

    // Disable dropdown
    etgSelectEl.disabled = true;
    etgSelectEl.required = false;
  }
}




// Hook change event
const classificationElHook = document.getElementById('classification');
if (classificationElHook) {
  classificationElHook.addEventListener('change', syncEtgUI);
}


// =========================================================================
// AJAX SAVE INTERVIEW (Insert + Update)
// File: root_folder/progchair/index.php
// =========================================================================

const interviewForm = document.getElementById('studentInterviewForm');

if (interviewForm) {

  interviewForm.addEventListener('submit', function (e) {

    e.preventDefault();

    const formData = new FormData(interviewForm);

    fetch('save_interview.php', {
      method: 'POST',
      body: formData
    })
    .then(res => res.json())
    .then(data => {

      if (!data.success) {
        Swal.fire({
          icon: 'error',
          title: 'Error',
          text: data.message || 'Something went wrong.'
        });
        return;
      }

      // Close modal
      const modal = bootstrap.Modal.getInstance(document.getElementById('studentModal'));
      modal.hide();

// 🔥 RELOAD LIST PROPERLY AFTER SAVE
page = 1;
hasMore = true;
loadStudents(true);

      // Ask next action
      Swal.fire({
        icon: 'success',
        title: 'Saved Successfully',
        text: 'Do you want to enter scores now?',
        showCancelButton: true,
        confirmButtonText: 'Yes, Enter Scores',
        cancelButtonText: 'Return to List'
      }).then((result) => {

        if (result.isConfirmed) {
          const interviewId = Number(data.interview_id || 0);

          if (!interviewId) {
            Swal.fire('Error', 'Saved, but interview ID was not returned.', 'error');
            return;
          }

          // Redirect to score entry
          window.location.href = 'interview_scores.php?interview_id=' + interviewId;

        }

      });

    })
    .catch(err => {
      console.error(err);
      Swal.fire({
        icon: 'error',
        title: 'Error',
        text: 'Request failed.'
      });
    });

  });

}

});


/**
 * ============================================================
 * TRANSFER ACTION (ACCEPT / REJECT)
 * File: root_folder/interview/progchair/index.php
 * ============================================================
 */
function handleTransferAction(e, transferId, action) {

  if (e) e.preventDefault();

  Swal.fire({
    icon: 'question',
    title: action === 'accept' ? 'Approve Transfer?' : 'Reject Transfer?',
    text: action === 'accept'
      ? 'This will move the student to the selected program.'
      : 'This will cancel the transfer request.',
    showCancelButton: true,
    confirmButtonText: 'Yes',
    cancelButtonText: 'Cancel'
  }).then((result) => {

    if (!result.isConfirmed) return;

      fetch('process_transfer_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: `transfer_id=${transferId}&action=${action}`
      })
      .then(res => res.json())
      .then(data => {

        if (!data.success) {
          Swal.fire('Error', data.message || 'Action failed.', 'error');
          return;
        }

        Swal.fire('Success', 'Transfer updated.', 'success');

        page = 1;
        hasMore = true;
        loadStudents(true);

      })
      .catch(err => {
        console.error(err);
        Swal.fire('Error', 'Request failed.', 'error');
      });



  });
}
</script>


  </body>
</html>
