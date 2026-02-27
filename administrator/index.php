<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['admin_login_lock_csrf'])) {
    try {
        $_SESSION['admin_login_lock_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['admin_login_lock_csrf'] = sha1(uniqid('admin_login_lock_csrf_', true));
    }
}

$adminLoginLockCsrf = (string) $_SESSION['admin_login_lock_csrf'];
$nonAdminLoginLocked = is_non_admin_login_locked($conn);

if (empty($_SESSION['admin_global_cutoff_csrf'])) {
    try {
        $_SESSION['admin_global_cutoff_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['admin_global_cutoff_csrf'] = sha1(uniqid('admin_global_cutoff_csrf_', true));
    }
}

$adminGlobalCutoffCsrf = (string) $_SESSION['admin_global_cutoff_csrf'];
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
$globalSatCutoffActive = (bool) ($globalSatCutoffState['active'] ?? false);

$loginLockFlash = null;
if (isset($_SESSION['admin_login_lock_flash']) && is_array($_SESSION['admin_login_lock_flash'])) {
    $loginLockFlash = $_SESSION['admin_login_lock_flash'];
    unset($_SESSION['admin_login_lock_flash']);
}

$globalCutoffFlash = null;
if (isset($_SESSION['admin_global_cutoff_flash']) && is_array($_SESSION['admin_global_cutoff_flash'])) {
    $globalCutoffFlash = $_SESSION['admin_global_cutoff_flash'];
    unset($_SESSION['admin_global_cutoff_flash']);
}

function table_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $exists = (int) ($row['column_count'] ?? 0) > 0;
    $stmt->close();

    return $exists;
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
$hasEndorsementCapacityColumn = table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_capacity');
$endorsementCapacitySelect = $hasEndorsementCapacityColumn
    ? 'COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity'
    : '0 AS endorsement_capacity';

$campusSql = "
    SELECT
        c.campus_id,
        c.campus_code,
        c.campus_name,
        COALESCE(i.scored_count, 0) AS scored_count,
        COALESCE(i.unscored_count, 0) AS unscored_count,
        COALESCE(i.total_count, 0) AS interviewed_count
    FROM tbl_campus c
    LEFT JOIN (
        SELECT
            col.campus_id,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count,
            SUM(CASE WHEN si.final_score IS NULL THEN 1 ELSE 0 END) AS unscored_count,
            COUNT(*) AS total_count
        FROM tbl_student_interview si
        INNER JOIN tbl_program p
            ON p.program_id = si.first_choice
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE si.status = 'active'
          AND si.first_choice IS NOT NULL
          AND si.first_choice > 0
        GROUP BY col.campus_id
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

$campusProgramStatus = [];
foreach ($campuses as $campusRow) {
    $campusId = (int) ($campusRow['campus_id'] ?? 0);
    if ($campusId <= 0) {
        continue;
    }

    $campusProgramStatus[$campusId] = [
        'campus_id' => $campusId,
        'campus_code' => (string) ($campusRow['campus_code'] ?? ''),
        'campus_name' => (string) ($campusRow['campus_name'] ?? ''),
        'scored_count' => (int) ($campusRow['scored_count'] ?? 0),
        'unscored_count' => (int) ($campusRow['unscored_count'] ?? 0),
        'interviewed_count' => (int) ($campusRow['interviewed_count'] ?? 0),
        'programs' => []
    ];
}

$campusProgramSql = "
    SELECT
        c.campus_id,
        p.program_id,
        CONCAT(
            p.program_name,
            IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' - ', p.major), '')
        ) AS program_label,
        COALESCE(pc.cutoff_score, 0) AS cutoff_score,
        COALESCE(pc.absorptive_capacity, 0) AS absorptive_capacity,
        {$endorsementCapacitySelect},
        COALESCE(i.scored_count, 0) AS scored_count,
        COALESCE(i.unscored_count, 0) AS unscored_count
    FROM tbl_campus c
    INNER JOIN tbl_college col
        ON col.campus_id = c.campus_id
       AND col.status = 'active'
    INNER JOIN tbl_program p
        ON p.college_id = col.college_id
       AND p.status = 'active'
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    LEFT JOIN (
        SELECT
            first_choice AS program_id,
            SUM(CASE WHEN final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count,
            SUM(CASE WHEN final_score IS NULL THEN 1 ELSE 0 END) AS unscored_count
        FROM tbl_student_interview
        WHERE status = 'active'
          AND first_choice IS NOT NULL
          AND first_choice > 0
        GROUP BY first_choice
    ) i ON i.program_id = p.program_id
    WHERE c.status = 'active'
    ORDER BY c.campus_name ASC, p.program_name ASC
";

$campusProgramResult = $conn->query($campusProgramSql);
if ($campusProgramResult) {
    while ($programRow = $campusProgramResult->fetch_assoc()) {
        $campusId = (int) ($programRow['campus_id'] ?? 0);
        if ($campusId <= 0 || !isset($campusProgramStatus[$campusId])) {
            continue;
        }

        $scoredCount = (int) ($programRow['scored_count'] ?? 0);
        $unscoredCount = (int) ($programRow['unscored_count'] ?? 0);

        $campusProgramStatus[$campusId]['programs'][] = [
            'program_id' => (int) ($programRow['program_id'] ?? 0),
            'program_label' => (string) ($programRow['program_label'] ?? 'Program'),
            'cutoff_score' => (float) ($programRow['cutoff_score'] ?? 0),
            'absorptive_capacity' => (int) ($programRow['absorptive_capacity'] ?? 0),
            'endorsement_capacity' => (int) ($programRow['endorsement_capacity'] ?? 0),
            'scored_count' => $scoredCount,
            'unscored_count' => $unscoredCount,
            'total_count' => ($scoredCount + $unscoredCount)
        ];
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
$overallInterviewTrendSeries = [];

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

$dailyInterviewTrend = [];
$dailyScoredTrend = [];
foreach ($trendDateKeys as $dayKey) {
    $dailyInterviewTrend[$dayKey] = 0;
    $dailyScoredTrend[$dayKey] = 0;
}

$overallTrendSql = "
    SELECT
        DATE(si.interview_datetime) AS trend_day,
        COUNT(*) AS interviewed_total,
        SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_total
    FROM tbl_student_interview si
    WHERE si.status = 'active'
      AND si.interview_datetime IS NOT NULL
      AND si.interview_datetime >= ?
    GROUP BY trend_day
    ORDER BY trend_day ASC
";
$overallTrendStmt = $conn->prepare($overallTrendSql);
if ($overallTrendStmt) {
    $trendStartDate = $trendDateKeys[0] . ' 00:00:00';
    $overallTrendStmt->bind_param('s', $trendStartDate);
    $overallTrendStmt->execute();
    $overallTrendResult = $overallTrendStmt->get_result();

    while ($overallRow = $overallTrendResult->fetch_assoc()) {
        $dayKey = (string) ($overallRow['trend_day'] ?? '');
        if (!array_key_exists($dayKey, $dailyInterviewTrend)) {
            continue;
        }

        $dailyInterviewTrend[$dayKey] = (int) ($overallRow['interviewed_total'] ?? 0);
        $dailyScoredTrend[$dayKey] = (int) ($overallRow['scored_total'] ?? 0);
    }

    $overallTrendStmt->close();
}

$overallInterviewTrendSeries = [
    'interviewed' => array_values($dailyInterviewTrend),
    'scored' => array_values($dailyScoredTrend)
];

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
        cursor: pointer;
      }

      .admin-campus-card:hover {
        border-color: #c8d0e0;
        box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
        transform: translateY(-1px);
      }

      .admin-campus-card:focus-visible {
        outline: 0;
        border-color: #696cff;
        box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.2);
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

      .campus-status-modal .modal-content {
        border: 1px solid #e4e8f0;
        border-radius: 0.85rem;
        box-shadow: 0 16px 34px rgba(39, 56, 84, 0.18);
      }

      .campus-status-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .campus-status-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.55rem;
        border-radius: 999px;
        border: 1px solid #e5e9f1;
        font-size: 0.75rem;
        font-weight: 600;
        color: #56627a;
        background: #fff;
      }

      .campus-program-item {
        border: 1px solid #e8edf5;
        border-radius: 0.75rem;
        padding: 0.62rem 0.75rem;
        margin-bottom: 0.55rem;
        background: #fff;
      }

      .campus-program-item:last-child {
        margin-bottom: 0;
      }

      .campus-program-title {
        font-size: 0.88rem;
        font-weight: 600;
        color: #364152;
        margin: 0;
      }

      .campus-program-metrics {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
      }

      .admin-hero-actions {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.55rem;
      }

      .admin-hero-actions .btn {
        margin: 0;
      }

      .admin-login-control {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.28rem 0.36rem;
        border: 1px solid #e4e8f0;
        border-radius: 0.65rem;
        background: #f9fafc;
      }

      .admin-login-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.32rem;
        padding: 0.2rem 0.52rem;
        border-radius: 999px;
        font-size: 0.72rem;
        font-weight: 700;
        line-height: 1;
        letter-spacing: 0.03em;
        text-transform: uppercase;
        border: 1px solid transparent;
      }

      .admin-login-chip.locked {
        background: #fff1f0;
        color: #b42318;
        border-color: #fecdca;
      }

      .admin-login-chip.unlocked {
        background: #ecfdf3;
        color: #067647;
        border-color: #abefc6;
      }

      .admin-login-chip.global-active {
        background: #eff8ff;
        color: #175cd3;
        border-color: #b2ddff;
      }

      .admin-login-chip.global-inactive {
        background: #f4f7fc;
        color: #475467;
        border-color: #d0d5dd;
      }

      @media (max-width: 575.98px) {
        .admin-login-control {
          width: 100%;
          justify-content: space-between;
        }
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
  <?php if ($loginLockFlash): ?>
    <div class="alert alert-<?= htmlspecialchars((string) ($loginLockFlash['type'] ?? 'info')); ?> mb-3" role="alert">
      <?= htmlspecialchars((string) ($loginLockFlash['message'] ?? '')); ?>
    </div>
  <?php endif; ?>
  <?php if ($globalCutoffFlash): ?>
    <div class="alert alert-<?= htmlspecialchars((string) ($globalCutoffFlash['type'] ?? 'info')); ?> mb-3" role="alert">
      <?= htmlspecialchars((string) ($globalCutoffFlash['message'] ?? '')); ?>
    </div>
  <?php endif; ?>

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
                <span class="badge bg-label-success">
                  Records in Batch: <?= number_format(array_sum($qualitativeCounts)); ?>
                </span>
              </div>

              <div class="admin-hero-actions">
                <a href="accounts/index.php" class="btn btn-sm btn-primary">
                  Manage Accounts
                </a>
                <a href="placement_results/index.php" class="btn btn-sm btn-outline-primary">
                  Manage Placement Results
                </a>
                <div class="admin-login-control">
                  <span class="admin-login-chip <?= $nonAdminLoginLocked ? 'locked' : 'unlocked'; ?>">
                    Login: <?= $nonAdminLoginLocked ? 'Locked' : 'Unlocked'; ?>
                  </span>
                  <form
                    method="POST"
                    action="toggle_login_lock.php"
                    class="m-0"
                    onsubmit="return confirm('Are you sure you want to <?= $nonAdminLoginLocked ? 'unlock' : 'lock'; ?> Student and Program Chair logins?');"
                  >
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminLoginLockCsrf); ?>" />
                    <input type="hidden" name="action" value="<?= $nonAdminLoginLocked ? 'unlock' : 'lock'; ?>" />
                    <button type="submit" class="btn btn-sm <?= $nonAdminLoginLocked ? 'btn-success' : 'btn-danger'; ?>">
                      <?= $nonAdminLoginLocked ? 'Unlock Login' : 'Lock Login'; ?>
                    </button>
                  </form>
                </div>
                <div class="admin-login-control">
                  <span class="admin-login-chip <?= $globalSatCutoffActive ? 'global-active' : 'global-inactive'; ?>">
                    Global SAT: <?= $globalSatCutoffActive ? ('>= ' . number_format((int) $globalSatCutoffValue)) : 'Disabled'; ?>
                  </span>
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-secondary"
                    data-bs-toggle="modal"
                    data-bs-target="#globalCutoffModal"
                  >
                    Set Global Cutoff
                  </button>
                </div>
              </div>
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
            Interview Volume Trend (Last 15 Days)
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
                $scoredCount = (int) ($campus['scored_count'] ?? 0);
                $unscoredCount = (int) ($campus['unscored_count'] ?? 0);
                $campusId = (int) ($campus['campus_id'] ?? 0);
              ?>
              <button
                type="button"
                class="admin-campus-card <?= $style['accent']; ?> w-100 text-start"
                data-campus-id="<?= $campusId; ?>"
                aria-label="Open <?= htmlspecialchars($campus['campus_name']); ?> interview status details"
              >
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
                      Scored: <?= number_format($scoredCount); ?> | Not Scored: <?= number_format($unscoredCount); ?>
                    </small>
                  </div>
                </div>

                <div class="admin-campus-total">
                  <div class="admin-campus-total-number">
                    <?= number_format($interviewedCount); ?>
                  </div>
                  <small class="admin-campus-total-label">Interviewed</small>
                </div>
              </button>
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

<div class="modal fade campus-status-modal" id="campusStatusModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1" id="campusStatusModalTitle">Campus Program Interview Status</h5>
          <div class="campus-status-summary" id="campusStatusSummary"></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="campusStatusProgramsWrap"></div>
        <div id="campusStatusEmptyState" class="text-muted small d-none">
          No active programs found for this campus.
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="globalCutoffModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <form method="POST" action="save_global_cutoff.php">
        <div class="modal-header">
          <h5 class="modal-title">Global SAT Cutoff</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminGlobalCutoffCsrf); ?>" />

          <div class="form-check form-switch mb-3">
            <input
              class="form-check-input"
              type="checkbox"
              id="globalCutoffEnabledInput"
              name="global_cutoff_enabled"
              value="1"
              <?= $globalSatCutoffEnabled ? 'checked' : ''; ?>
            />
            <label class="form-check-label" for="globalCutoffEnabledInput">
              Enable global SAT cutoff override
            </label>
          </div>

          <div>
            <label class="form-label" for="globalCutoffScoreInput">Minimum SAT Score (show >= score)</label>
            <input
              type="number"
              class="form-control"
              id="globalCutoffScoreInput"
              name="global_cutoff_score"
              min="0"
              max="9999"
              step="1"
              value="<?= $globalSatCutoffValue !== null ? (int) $globalSatCutoffValue : ''; ?>"
            />
            <small class="text-muted d-block mt-2">
              When enabled, this value overrides per-program cutoff visibility for Administrator and Program Chair lists.
            </small>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Cutoff</button>
        </div>
      </form>
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
  const overallTrendSeries = <?php echo json_encode($overallInterviewTrendSeries); ?>;
  const chartContainer = document.querySelector("#programInterviewTrendChart");
  const campusStatusById = <?php echo json_encode($campusProgramStatus); ?>;
  const campusStatusModalEl = document.getElementById('campusStatusModal');
  const campusStatusModalTitleEl = document.getElementById('campusStatusModalTitle');
  const campusStatusSummaryEl = document.getElementById('campusStatusSummary');
  const campusStatusProgramsWrapEl = document.getElementById('campusStatusProgramsWrap');
  const campusStatusEmptyStateEl = document.getElementById('campusStatusEmptyState');
  const campusCardEls = document.querySelectorAll('.admin-campus-card[data-campus-id]');
  const globalCutoffEnabledInput = document.getElementById('globalCutoffEnabledInput');
  const globalCutoffScoreInput = document.getElementById('globalCutoffScoreInput');

  function syncGlobalCutoffControls() {
    if (!globalCutoffEnabledInput || !globalCutoffScoreInput) return;
    const enabled = globalCutoffEnabledInput.checked;
    globalCutoffScoreInput.disabled = !enabled;
    globalCutoffScoreInput.required = enabled;
  }

  if (globalCutoffEnabledInput && globalCutoffScoreInput) {
    globalCutoffEnabledInput.addEventListener('change', syncGlobalCutoffControls);
    syncGlobalCutoffControls();
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function toNumber(value) {
    const num = Number(value);
    return Number.isFinite(num) ? num : 0;
  }

  function openCampusStatusModal(campusIdRaw) {
    if (!campusStatusModalEl || typeof bootstrap === 'undefined') return;

    const campusId = String(campusIdRaw || '');
    const campus = campusStatusById[campusId] || null;
    if (!campus) return;

    const campusCode = escapeHtml(campus.campus_code || '');
    const campusName = escapeHtml(campus.campus_name || 'Campus');
    const scoredCount = toNumber(campus.scored_count);
    const unscoredCount = toNumber(campus.unscored_count);
    const totalCount = toNumber(campus.interviewed_count);

    campusStatusModalTitleEl.innerHTML = `${campusCode} - ${campusName}`;
    campusStatusSummaryEl.innerHTML = `
      <span class="campus-status-chip">Total Interviewed: ${totalCount.toLocaleString()}</span>
      <span class="campus-status-chip">Scored: ${scoredCount.toLocaleString()}</span>
      <span class="campus-status-chip">Not Scored: ${unscoredCount.toLocaleString()}</span>
    `;

    const programs = Array.isArray(campus.programs) ? campus.programs.slice() : [];
    programs.sort((a, b) => {
      const aTotal = toNumber(a.total_count);
      const bTotal = toNumber(b.total_count);
      if (bTotal !== aTotal) return bTotal - aTotal;
      return String(a.program_label || '').localeCompare(String(b.program_label || ''));
    });

    if (programs.length === 0) {
      campusStatusProgramsWrapEl.innerHTML = '';
      campusStatusEmptyStateEl.classList.remove('d-none');
    } else {
      campusStatusEmptyStateEl.classList.add('d-none');
      campusStatusProgramsWrapEl.innerHTML = programs.map((program) => {
        const programLabel = escapeHtml(program.program_label || 'Program');
        const programScored = toNumber(program.scored_count);
        const programUnscored = toNumber(program.unscored_count);
        const programTotal = toNumber(program.total_count);

        return `
          <div class="campus-program-item">
            <div class="d-flex justify-content-between align-items-start gap-2">
              <p class="campus-program-title">${programLabel}</p>
              <span class="badge bg-label-primary">Total: ${programTotal.toLocaleString()}</span>
            </div>
            <div class="campus-program-metrics mt-2">
              <span class="badge bg-label-success">Scored: ${programScored.toLocaleString()}</span>
              <span class="badge bg-label-warning">Not Scored: ${programUnscored.toLocaleString()}</span>
              <span class="badge bg-label-secondary">Cutoff: ${toNumber(program.cutoff_score).toLocaleString()}</span>
              <span class="badge bg-label-info">AC: ${toNumber(program.absorptive_capacity).toLocaleString()}</span>
              <span class="badge bg-label-danger">SCC: ${toNumber(program.endorsement_capacity).toLocaleString()}</span>
            </div>
          </div>
        `;
      }).join('');
    }

    const modalInstance = bootstrap.Modal.getOrCreateInstance(campusStatusModalEl);
    modalInstance.show();
  }

  campusCardEls.forEach((cardEl) => {
    cardEl.addEventListener('click', () => {
      openCampusStatusModal(cardEl.getAttribute('data-campus-id'));
    });
  });

  if (!chartContainer) return;
  const mergedSeries = [];

  if (overallTrendSeries && Array.isArray(overallTrendSeries.interviewed)) {
    mergedSeries.push({
      name: 'Total Interviewed',
      data: overallTrendSeries.interviewed
    });
  }

  if (overallTrendSeries && Array.isArray(overallTrendSeries.scored)) {
    mergedSeries.push({
      name: 'Total Scored',
      data: overallTrendSeries.scored
    });
  }

  if (mergedSeries.length === 0) {
    chartContainer.innerHTML = '<div class="text-muted small py-3">No interview trend data available for the last 15 days.</div>';
    return;
  }

  const options = {
    chart: {
      type: 'line',
      height: 350,
      toolbar: { show: false }
    },
    series: mergedSeries,
    xaxis: {
      categories: trendCategories,
      labels: {
        rotate: -45,
        hideOverlappingLabels: true
      }
    },
    stroke: {
      curve: 'smooth',
      width: mergedSeries.map((seriesRow) => {
        const name = String(seriesRow.name || '');
        return (name === 'Total Interviewed' || name === 'Total Scored') ? 3.4 : 2.2;
      }),
      dashArray: mergedSeries.map((seriesRow) => {
        const name = String(seriesRow.name || '');
        return name === 'Total Scored' ? 4 : 0;
      })
    },
    markers: {
      size: 4,
      strokeWidth: 0
    },
    colors: ['#ff3e1d', '#1f6bff'],
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
