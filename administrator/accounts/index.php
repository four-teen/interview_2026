<?php

require_once '../../config/db.php';
require_once '../../config/program_assignments.php';

ensure_account_program_assignments_table($conn);

$LIMIT = 20;

$sql = "
  SELECT 
    a.accountid,
    a.acc_fullname,
    a.email,
    a.role,
    a.status,

    a.campus_id,
    c.campus_name,

    a.program_id,
    CONCAT(
      p.program_name,
      IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' – ', p.major), '')
    ) AS legacy_program_display,
    COALESCE(pa.assigned_program_ids_csv, CAST(a.program_id AS CHAR)) AS assigned_program_ids_csv,
    COALESCE(
      NULLIF(pa.assigned_program_display, ''),
      CONCAT(
        p.program_name,
        IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' - ', p.major), '')
      )
    ) AS program_display

  FROM tblaccount a
  LEFT JOIN tbl_campus c 
    ON a.campus_id = c.campus_id

  LEFT JOIN (
    SELECT
      apa.accountid,
      GROUP_CONCAT(
        CONCAT(
          tp.program_code,
          IF(tp.major IS NOT NULL AND tp.major <> '', CONCAT(' - ', tp.major), '')
        )
        ORDER BY tp.program_code ASC, tp.major ASC
        SEPARATOR ', '
      ) AS assigned_program_display,
      GROUP_CONCAT(apa.program_id ORDER BY apa.program_id ASC SEPARATOR ',') AS assigned_program_ids_csv
    FROM tbl_account_program_assignments apa
    INNER JOIN tbl_program tp
      ON tp.program_id = apa.program_id
    WHERE apa.status = 'active'
      AND tp.status = 'active'
    GROUP BY apa.accountid
  ) pa
    ON pa.accountid = a.accountid

  LEFT JOIN tbl_program p 
    ON a.program_id = p.program_id

  ORDER BY a.acc_fullname
  LIMIT $LIMIT
";



$result = $conn->query($sql);

$accounts = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
}

$campusStats = [];
$campusStatsSql = "
  SELECT
    c.campus_id,
    c.campus_name,
    COALESCE(SUM(CASE WHEN a.status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
    COALESCE(SUM(CASE WHEN a.status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
    COUNT(a.accountid) AS total_count
  FROM tbl_campus c
  LEFT JOIN tblaccount a ON a.campus_id = c.campus_id
  WHERE c.status = 'active'
  GROUP BY c.campus_id, c.campus_name
  ORDER BY c.campus_name ASC
";
$campusStatsResult = $conn->query($campusStatsSql);
if ($campusStatsResult) {
    while ($row = $campusStatsResult->fetch_assoc()) {
        $campusStats[] = $row;
    }
}

$overallCounts = ['active_count' => 0, 'inactive_count' => 0, 'total_count' => 0];
$overallSql = "
  SELECT
    COALESCE(SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END), 0) AS active_count,
    COALESCE(SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END), 0) AS inactive_count,
    COUNT(*) AS total_count
  FROM tblaccount
";
$overallResult = $conn->query($overallSql);
if ($overallResult) {
    $overallRow = $overallResult->fetch_assoc();
    if ($overallRow) {
        $overallCounts = $overallRow;
    }
}

$activeUsers = [];
$activeUsersSql = "
  SELECT
    a.accountid,
    a.acc_fullname,
    a.email,
    a.role,
    a.status,
    c.campus_name,
    COALESCE(
      NULLIF(pa.assigned_program_display, ''),
      CASE
        WHEN p.program_id IS NULL OR p.program_id = 0 THEN '-'
        ELSE CONCAT(
          COALESCE(NULLIF(UPPER(TRIM(cp.campus_code)), ''), 'N/A'),
          ' | ',
          COALESCE(NULLIF(TRIM(p.program_code), ''), CONCAT('PROGRAM ', p.program_id)),
          IF(p.program_name IS NOT NULL AND p.program_name <> '', CONCAT(' : ', p.program_name), ''),
          IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' - ', p.major), '')
        )
      END
    ) AS assigned_program_display
  FROM tblaccount a
  LEFT JOIN tbl_campus c
    ON c.campus_id = a.campus_id
  LEFT JOIN (
    SELECT
      apa.accountid,
      GROUP_CONCAT(
        CONCAT(
          COALESCE(NULLIF(UPPER(TRIM(cpa.campus_code)), ''), 'N/A'),
          ' | ',
          COALESCE(NULLIF(TRIM(tp.program_code), ''), CONCAT('PROGRAM ', tp.program_id)),
          IF(tp.program_name IS NOT NULL AND tp.program_name <> '', CONCAT(' : ', tp.program_name), ''),
          IF(tp.major IS NOT NULL AND tp.major <> '', CONCAT(' - ', tp.major), '')
        )
        ORDER BY cpa.campus_code ASC, tp.program_code ASC, tp.major ASC
        SEPARATOR ', '
      ) AS assigned_program_display
    FROM tbl_account_program_assignments apa
    INNER JOIN tbl_program tp
      ON tp.program_id = apa.program_id
    LEFT JOIN tbl_college tpc
      ON tpc.college_id = tp.college_id
    LEFT JOIN tbl_campus cpa
      ON cpa.campus_id = tpc.campus_id
    WHERE apa.status = 'active'
      AND tp.status = 'active'
    GROUP BY apa.accountid
  ) pa
    ON pa.accountid = a.accountid
  LEFT JOIN tbl_program p
    ON p.program_id = a.program_id
  LEFT JOIN tbl_college pc
    ON pc.college_id = p.college_id
  LEFT JOIN tbl_campus cp
    ON cp.campus_id = pc.campus_id
  WHERE a.status = 'active'
  ORDER BY COALESCE(c.campus_name, 'Unassigned Campus') ASC, a.acc_fullname ASC
";
$activeUsersResult = $conn->query($activeUsersSql);
if ($activeUsersResult) {
    while ($row = $activeUsersResult->fetch_assoc()) {
        $activeUsers[] = $row;
    }
}



session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
  header('Location: ../../index.php');
  exit;
}

$fullname = $_SESSION['fullname'] ?? 'Administrator';
$email    = $_SESSION['email'] ?? '';
$role     = $_SESSION['role'] ?? 'administrator';
$currentAdminId = (int) ($_SESSION['accountid'] ?? 0);
?>
<!DOCTYPE html>

<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />

  <title>User Accounts | Administrator</title>
  <link rel="icon" type="image/x-icon" href="../../assets/img/favicon/favicon.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../../assets/css/demo.css" />
  <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
  <script src="../../assets/vendor/js/helpers.js"></script>
  <script src="../../assets/js/config.js"></script>

<style>
  .swal2-container { z-index: 20000 !important; }

  #editAccountModal .select2-container,
  #addAccountModal .select2-container {
    width: 100% !important;
  }

  #editAccountModal .select2-container--default .select2-selection--multiple,
  #addAccountModal .select2-container--default .select2-selection--multiple {
    min-height: 2.38rem;
    border: 1px solid #d9dee3;
    border-radius: 0.375rem;
    padding: 0.2rem 0.4rem;
  }

  #editAccountModal .select2-container--default.select2-container--focus .select2-selection--multiple,
  #addAccountModal .select2-container--default.select2-container--focus .select2-selection--multiple {
    border-color: #696cff;
    box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.16);
  }

  #activeUsersModal .active-user-program-cell {
    min-width: 320px;
  }

  #activeUsersModal .active-user-details-cell {
    min-width: 260px;
  }

  #activeUsersModal .active-user-name {
    font-weight: 600;
  }

  #activeUsersModal .active-user-meta {
    margin-top: 0.2rem;
    display: flex;
    flex-direction: column;
    gap: 0.12rem;
    font-size: 0.82rem;
    color: #697a8d;
  }

  #activeUsersModal .campus-group-row td {
    background: #f5f7fa;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.03em;
    font-size: 0.76rem;
  }

  #activeUsersModal .assigned-program-list {
    display: flex;
    flex-direction: column;
    gap: 0.25rem;
  }

  #activeUsersModal .assigned-program-item {
    white-space: normal;
    overflow-wrap: anywhere;
    word-break: break-word;
    line-height: 1.3;
  }
</style>

</head>

<body>

<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <!-- SIDEBAR -->
      <?php 
        include '../sidebar.php';
      ?>
    <!-- /SIDEBAR -->

    <!-- MAIN PAGE -->
    <div class="layout-page">

      <!-- NAVBAR -->
        <?php 
          include '../header.php';
        ?>
      <!-- /NAVBAR -->

      <!-- CONTENT -->
      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row">

            <!-- LEFT: Accounts table -->
            <div class="col-lg-8 mb-4">
              <div class="card" style="padding: 15px;">

<div class="card-header">
  <div class="row align-items-center g-2">

    <!-- LEFT: Title -->
    <div class="col-12 col-md">
      <h5 class="mb-0">Account Registry</h5>
      <small class="text-muted">
        Fields aligned to <code>tblaccount</code> (UI only).
      </small>
    </div>

    <!-- MIDDLE: Search -->
    <div class="col-12 col-md-4 col-lg-3">
      <input
        type="text"
        id="accountSearch"
        class="form-control form-control-sm"
        placeholder="Search name or email..."
      />
    </div>

    <!-- RIGHT: Button -->
    <div class="col-12 col-md-auto text-md-end">
      <button
        type="button"
        class="btn btn-label-primary btn-sm me-1"
        data-bs-toggle="modal"
        data-bs-target="#activeUsersModal"
      >
        <i class="bx bx-list-ul me-1"></i> Active Users
      </button>
      <button
        type="button"
        class="btn btn-primary btn-sm"
        data-bs-toggle="modal"
        data-bs-target="#addAccountModal"
      >
        <i class="bx bx-plus me-1"></i> Add Account
      </button>
    </div>

  </div>
</div>


            <div class="row g-3" id="accountsList">

                <?php foreach ($accounts as $acc): ?>
                  <div class="col-12 account-row">
                    <div class="card border">
                      <div class="card-body d-flex align-items-center justify-content-between gap-3">

                      <div class="d-flex align-items-center gap-3 flex-grow-1">

                        <div class="flex-grow-1">
                          <div class="fw-semibold">
                            <?= htmlspecialchars($acc['acc_fullname']) ?>
                          </div>

                          <div class="text-muted small">
                            <?= htmlspecialchars($acc['email']) ?>
                          </div>
                        </div>

                        <div class="d-flex gap-2">
                          <span class="badge bg-label-<?= $acc['role']=='administrator'?'primary':($acc['role']=='progchair'?'info':'warning') ?>">
                            <?= $acc['role'] ?>
                          </span>

                          <span class="badge bg-label-<?= $acc['status']=='active'?'success':'secondary' ?>">
                            <?= $acc['status'] ?>
                          </span>
                        </div>

                          <div class="small text-muted d-none d-md-block">
                            Campus: <?= htmlspecialchars($acc['campus_name'] ?? '—') ?>
                            <br>Program: <?= htmlspecialchars($acc['program_display'] ?? '—') ?>
                          </div>

                      </div>


                        <div class="dropdown">
                          <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown">
                            <i class="bx bx-dots-vertical-rounded"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a 
                                class="dropdown-item btn-edit-account"
                                href="javascript:void(0);"
                                data-id="<?= $acc['accountid'] ?>"
                                data-name="<?= htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) ?>"
                                data-email="<?= htmlspecialchars($acc['email'], ENT_QUOTES) ?>"
                                data-role="<?= $acc['role'] ?>"
                                data-campus="<?= $acc['campus_id'] ?>"
                                data-program="<?= $acc['program_id'] ?>"
                                data-program-ids="<?= htmlspecialchars((string) ($acc['assigned_program_ids_csv'] ?? ''), ENT_QUOTES) ?>"
                                data-status="<?= $acc['status'] ?>"
                              >
                                <i class="bx bx-edit-alt me-2"></i> Edit
                              </a>

                            </li>
                            <li>
                              <?php if ((int) $acc['accountid'] === $currentAdminId): ?>
                                <span class="dropdown-item text-muted disabled">
                                  <i class="bx bx-user me-2"></i> Current Account
                                </span>
                              <?php else: ?>
                                <a
                                  class="dropdown-item btn-toggle-account-lock"
                                  href="javascript:void(0);"
                                  data-id="<?= (int) $acc['accountid'] ?>"
                                  data-name="<?= htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) ?>"
                                  data-current-status="<?= htmlspecialchars($acc['status'], ENT_QUOTES) ?>"
                                  data-action="<?= $acc['status'] === 'active' ? 'lock' : 'unlock' ?>"
                                >
                                  <i class="bx <?= $acc['status'] === 'active' ? 'bx-lock-alt' : 'bx-lock-open-alt' ?> me-2"></i>
                                  <?= $acc['status'] === 'active' ? 'Lock Account' : 'Unlock Account' ?>
                                </a>
                              <?php endif; ?>
                            </li>
                            <li>
                              <a 
                                class="dropdown-item text-danger btn-delete-account"
                                href="javascript:void(0);"
                                data-id="<?= $acc['accountid'] ?>"
                                data-name="<?= htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) ?>"
                              >
                                <i class="bx bx-trash me-2"></i> Delete
                              </a>
                            </li>

                          </ul>
                        </div>

                      </div>
                    </div>
                  </div>




                <?php endforeach; ?>

            </div>

<!-- Infinite scroll loader (UI only) -->
<div id="accountLoader" class="text-center py-3 text-muted small" style="display:none;">
  Loading more accounts...
</div>



              </div>
            </div>

            <!-- RIGHT: Campus filter cards -->
            <div class="col-lg-4 mb-4">
              <div class="card h-100">
                <div class="card-header">
                  <h5 class="mb-0">Campus Filters</h5>
                  <small class="text-muted">Click a campus to filter accounts.</small>
                </div>

                <div class="card-body">
                  <div class="d-grid gap-2" id="campusFilters">
                    <button
                      type="button"
                      class="btn btn-outline-primary text-start campus-filter-btn active"
                      data-campus-id=""
                    >
                      <div class="d-flex justify-content-between align-items-center">
                        <span><i class="bx bx-buildings me-2"></i> All Campuses</span>
                        <span class="badge bg-label-primary"><?= (int) ($overallCounts['total_count'] ?? 0) ?></span>
                      </div>
                      <small class="text-muted d-block mt-1">
                        Active: <?= (int) ($overallCounts['active_count'] ?? 0) ?> / Inactive: <?= (int) ($overallCounts['inactive_count'] ?? 0) ?>
                      </small>
                    </button>

                    <?php foreach ($campusStats as $campus): ?>
                      <button
                        type="button"
                        class="btn btn-outline-primary text-start campus-filter-btn"
                        data-campus-id="<?= (int) ($campus['campus_id'] ?? 0) ?>"
                      >
                        <div class="d-flex justify-content-between align-items-center">
                          <span><i class="bx bx-buildings me-2"></i> <?= htmlspecialchars($campus['campus_name']) ?></span>
                          <span class="badge bg-label-primary"><?= (int) ($campus['total_count'] ?? 0) ?></span>
                        </div>
                        <small class="text-muted d-block mt-1">
                          Active: <?= (int) ($campus['active_count'] ?? 0) ?> / Inactive: <?= (int) ($campus['inactive_count'] ?? 0) ?>
                        </small>
                      </button>
                    <?php endforeach; ?>
                  </div>

                  <hr class="my-4" />

                  <div class="small text-muted">
                    <div class="d-flex justify-content-between mb-2">
                      <span>Role options</span>
                      <span class="fw-semibold">administrator · progchair · monitoring</span>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span>Status options</span>
                      <span class="fw-semibold">active · inactive</span>
                    </div>
                  </div>

                </div>
              </div>
            </div>

          </div>

        </div>

<div class="modal fade" id="addAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form method="post" action="save_account.php">

        <div class="modal-header">
          <h5 class="modal-title">Add User Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body row g-3">

          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="acc_fullname" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role" class="form-select" required>
              <option value="administrator">Administrator</option>
              <option value="progchair">Program Chair</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Campus</label>
            <select name="campus_id" id="add_campus" class="form-select">
              <!-- to be populated from tbl_campus -->
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="inactive" selected>Inactive</option>
              <option value="active">Active</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Assigned Program(s)</label>
            <select
              name="program_ids[]"
              id="add_program"
              class="form-select"
              multiple
              data-placeholder="Search and select program/major assignments"
            >
              <!-- to be populated from tbl_program -->
            </select>
            <div class="form-text d-flex justify-content-between align-items-center">
              <span>Program Chair can be assigned to one or more programs/majors.</span>
              <span id="addProgramSelectedCount" class="fw-semibold text-muted">0 selected</span>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Account</button>
        </div>

      </form>

    </div>
  </div>
</div>

<!-- EDIT ACCOUNT MODAL -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form id="editAccountForm">

        <input type="hidden" name="accountid" id="edit_accountid">

        <div class="modal-header">
          <h5 class="modal-title">Edit User Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body row g-3">

          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="acc_fullname" id="edit_fullname" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="edit_email" class="form-control" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role" id="edit_role" class="form-select" required>
              <option value="administrator">Administrator</option>
              <option value="progchair">Program Chair</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Campus</label>
            <select name="campus_id" id="edit_campus" class="form-select"></select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" id="edit_status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

          <div class="col-12">
            <label class="form-label">Assigned Program(s)</label>
            <select
              name="program_ids[]"
              id="edit_program"
              class="form-select"
              multiple
              data-placeholder="Search and select program/major assignments"
            ></select>
            <div class="form-text d-flex justify-content-between align-items-center">
              <span>Only assigned programs will be available for switching.</span>
              <span id="editProgramSelectedCount" class="fw-semibold text-muted">0 selected</span>
            </div>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Account</button>
        </div>

      </form>

    </div>
  </div>
</div>

<!-- ACTIVE USERS MODAL -->
<div class="modal fade" id="activeUsersModal" tabindex="-1" aria-labelledby="activeUsersModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="activeUsersModalLabel">
          Active Users with Assigned Program(s)
          <span class="text-muted small ms-2">(<?= count($activeUsers); ?> total)</span>
        </h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>

      <div class="modal-body">
        <div id="activeUsersPrintArea">
          <div class="d-flex justify-content-between align-items-center mb-2">
            <h6 class="mb-0">Account Management - Active Users Report</h6>
            <small class="text-muted">Generated: <?= date('F j, Y g:i A'); ?></small>
          </div>

          <div class="table-responsive">
            <table class="table table-sm table-bordered align-middle mb-0">
              <thead class="table-light">
                <tr>
                  <th style="width:56px;">#</th>
                  <th>User Details</th>
                  <th>Assigned Program(s)</th>
                </tr>
              </thead>
              <tbody>
                <?php if (!empty($activeUsers)): ?>
                  <?php
                    $activeUserRowNumber = 0;
                    $currentCampusGroup = '';
                  ?>
                  <?php foreach ($activeUsers as $user): ?>
                    <?php
                      $campusName = trim((string) ($user['campus_name'] ?? ''));
                      if ($campusName === '') {
                          $campusName = 'Unassigned Campus';
                      }
                      if ($campusName !== $currentCampusGroup):
                          $currentCampusGroup = $campusName;
                    ?>
                      <tr class="campus-group-row">
                        <td colspan="3">Campus - <?= htmlspecialchars($currentCampusGroup); ?></td>
                      </tr>
                    <?php endif; ?>
                    <?php $activeUserRowNumber++; ?>
                    <tr>
                      <td><?= (int) $activeUserRowNumber; ?></td>
                      <td class="active-user-details-cell">
                        <div class="active-user-name"><?= htmlspecialchars((string) ($user['acc_fullname'] ?? '')); ?></div>
                        <div class="active-user-meta">
                          <div>Email: <?= htmlspecialchars((string) ($user['email'] ?? '-')); ?></div>
                          <div>Role: <?= htmlspecialchars((string) ($user['role'] ?? '-')); ?></div>
                          <div>Campus: <?= htmlspecialchars($campusName); ?></div>
                        </div>
                      </td>
                      <td class="active-user-program-cell">
                        <?php
                          $assignedProgramRaw = (string) ($user['assigned_program_display'] ?? '');
                          $assignedProgramLines = [];
                          foreach (explode(',', $assignedProgramRaw) as $programPart) {
                              $programPart = trim($programPart);
                              if ($programPart !== '' && $programPart !== '-') {
                                  $assignedProgramLines[] = $programPart;
                              }
                          }
                        ?>
                        <?php if (!empty($assignedProgramLines)): ?>
                          <div class="assigned-program-list">
                            <?php foreach ($assignedProgramLines as $programLine): ?>
                              <div class="assigned-program-item"><?= htmlspecialchars($programLine); ?></div>
                            <?php endforeach; ?>
                          </div>
                        <?php else: ?>
                          <span class="text-muted">-</span>
                        <?php endif; ?>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php else: ?>
                  <tr>
                    <td colspan="3" class="text-center text-muted py-3">No active users found.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-primary" id="btnPrintActiveUsers">
          <i class="bx bx-printer me-1"></i> Print List
        </button>
      </div>
    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <input type="hidden" id="delete_accountid">

      <div class="modal-header">
        <h5 class="modal-title text-danger">Delete Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        Are you sure you want to delete
        <strong id="delete_account_name"></strong>?
        <div class="text-muted small mt-1">
          This action cannot be undone.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="button" class="btn btn-danger" id="confirmDeleteAccount">
          Delete
        </button>
      </div>

    </div>
  </div>
</div>



        <!-- FOOTER -->
        <footer class="content-footer footer bg-footer-theme">
          <div class="container-xxl d-flex flex-wrap justify-content-between py-2 flex-md-row flex-column">
            <div class="mb-2 mb-md-0">
              © <?php echo date('Y'); ?> Sultan Kudarat State University
            </div>
            <div class="small text-muted">
              Centralized Interview System · Administrator
            </div>
          </div>
        </footer>

        <div class="content-backdrop fade"></div>
      </div>
      <!-- /CONTENT -->

    </div>
    <!-- /MAIN PAGE -->

  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<!-- CORE JS -->
<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/libs/popper/popper.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>
<script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../../assets/vendor/js/menu.js"></script>
<script src="../../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (isset($_SESSION['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($_SESSION['success']) ?>,
    confirmButtonColor: '#696cff'
  });
});
</script>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($_SESSION['error']) ?>,
    confirmButtonColor: '#ff3e1d'
  });
});
</script>
<?php unset($_SESSION['error']); endif; ?>


<script>
  let loadingAccounts = false;
  let offset = <?= (int)$LIMIT ?>; // start after initial batch
  const limit = <?= (int)$LIMIT ?>;
  let endReached = false;
  let selectedCampusId = '';

let activeRequest = null;

function resetAndReloadAccounts() {
  offset = 0;
  endReached = false;
  loadingAccounts = false;
  $('#accountsList').html('');
  $('#accountLoader').show().text('Loading more accounts...');
  loadMoreAccounts();
}

function loadMoreAccounts() {
  if (loadingAccounts || endReached) return;

  loadingAccounts = true;
  $('#accountLoader').show().text('Loading more accounts...');

  const q = ($('#accountSearch').val() || '').trim();

  // abort previous request if still running (prevents race conditions)
  if (activeRequest && activeRequest.readyState !== 4) {
    activeRequest.abort();
  }

  activeRequest = $.get('fetch_accounts.php', {
      limit: limit,
      offset: offset,
      q: q,
      campus_id: selectedCampusId
    })
    .done(function (html) {
      html = (html || '').trim();

      if (html.length === 0) {
        endReached = true;

        // IMPORTANT: differentiate "no search results" vs "end of all"
        if (q !== '' && offset === 0) {
          $('#accountLoader').show().text('No records found.');
        } else {
          $('#accountLoader').show().text('No more accounts.');
        }

        loadingAccounts = false;
        return;
      }

      $('#accountsList').append(html);
      offset += limit;
      $('#accountLoader').hide();
      loadingAccounts = false;
    })
    .fail(function (xhr, status) {
      if (status === 'abort') return; // ignore abort
      $('#accountLoader').show().text('Failed to load more accounts.');
      loadingAccounts = false;
    });
}


  $(window).on('scroll', function () {
    if ($(window).scrollTop() + $(window).height() >= $(document).height() - 120) {
      loadMoreAccounts();
    }
  });

/* SEARCH HANDLER */
let searchTimer = null;

$('#accountSearch').on('keyup', function () {
  clearTimeout(searchTimer);

  searchTimer = setTimeout(function () {
    resetAndReloadAccounts();
  }, 300);
});

$(document).on('click', '.campus-filter-btn', function () {
  const campusId = String($(this).data('campus-id') ?? '');
  if (campusId === selectedCampusId) {
    return;
  }

  selectedCampusId = campusId;
  $('.campus-filter-btn').removeClass('active');
  $(this).addClass('active');
  resetAndReloadAccounts();
});


  function parseProgramIds(rawProgramIds, fallbackProgramId) {
  const ids = [];
  const seen = {};

  String(rawProgramIds || '')
    .split(',')
    .map(v => v.trim())
    .forEach(v => {
      const parsed = parseInt(v, 10);
      if (Number.isInteger(parsed) && parsed > 0 && !seen[parsed]) {
        seen[parsed] = true;
        ids.push(String(parsed));
      }
    });

  const fallback = parseInt(fallbackProgramId, 10);
  if (ids.length === 0 && Number.isInteger(fallback) && fallback > 0) {
    ids.push(String(fallback));
  }

  return ids;
}

function loadProgramsForSelect($select, selectedProgramIds) {
  $.getJSON('fetch_programs.php', function (data) {
    let html = '';
    data.forEach(row => {
      const campusCodeRaw = String(row.campus_code || '').trim().toUpperCase();
      const campusCode = campusCodeRaw !== '' ? campusCodeRaw.slice(0, 3) : 'N/A';
      const programCode = String(row.program_code || '').trim();
      const programName = String(row.program_name || '').trim();
      const major = String(row.major || '').trim();
      let programLabel = '';

      if (programCode !== '') {
        programLabel = programCode;
      }
      if (programName !== '') {
        programLabel += (programLabel ? ' : ' : '') + programName;
      }
      if (major !== '') {
        programLabel += (programLabel ? ' - ' : '') + major;
      }
      if (programLabel === '') {
        programLabel = `PROGRAM ${row.program_id}`;
      }

      const label = `${campusCode} | ${programLabel}`;
      html += `<option value="${row.program_id}">${label}</option>`;
    });
    $select.html(html);

    const normalizedSelected = (selectedProgramIds || []).map(v => String(v));
    $select.val(normalizedSelected);
    if ($select.is('#edit_program') || $select.is('#add_program')) {
      $select.trigger('change');
    }
    if ($select.is('#edit_program')) {
      updateEditProgramSelectedCount();
    }
    if ($select.is('#add_program')) {
      updateAddProgramSelectedCount();
    }
  });
}

function updateEditProgramSelectedCount() {
  const selectedCount = ($('#edit_program').val() || []).length;
  $('#editProgramSelectedCount').text(`${selectedCount} selected`);
}

function updateAddProgramSelectedCount() {
  const selectedCount = ($('#add_program').val() || []).length;
  $('#addProgramSelectedCount').text(`${selectedCount} selected`);
}

function initEditProgramSelect2() {
  const $editProgram = $('#edit_program');
  if (!$editProgram.length || typeof $.fn.select2 !== 'function') {
    return;
  }

  if ($editProgram.data('select2')) {
    $editProgram.select2('destroy');
  }

  $editProgram.select2({
    width: '100%',
    dropdownParent: $('#editAccountModal'),
    placeholder: $editProgram.data('placeholder') || 'Search and select program/major assignments',
    closeOnSelect: false
  });
}

function initAddProgramSelect2() {
  const $addProgram = $('#add_program');
  if (!$addProgram.length || typeof $.fn.select2 !== 'function') {
    return;
  }

  if ($addProgram.data('select2')) {
    $addProgram.select2('destroy');
  }

  $addProgram.select2({
    width: '100%',
    dropdownParent: $('#addAccountModal'),
    placeholder: $addProgram.data('placeholder') || 'Search and select program/major assignments',
    closeOnSelect: false
  });
}

/* OPEN EDIT MODAL */
$(document).on('click', '.btn-edit-account', function () {
  const campusId = $(this).data('campus');
  const fallbackProgramId = $(this).data('program');
  const programIds = parseProgramIds($(this).attr('data-program-ids'), fallbackProgramId);

  $('#edit_accountid').val($(this).data('id'));
  $('#edit_fullname').val($(this).data('name'));
  $('#edit_email').val($(this).data('email'));
  $('#edit_role').val($(this).data('role'));
  $('#edit_status').val($(this).data('status'));

  $.getJSON('fetch_campuses.php', function (data) {
    let html = '<option value="">- None -</option>';
    data.forEach(row => {
      html += `<option value="${row.campus_id}">${row.campus_name}</option>`;
    });
    $('#edit_campus').html(html).val(campusId);
    loadProgramsForSelect($('#edit_program'), programIds);
  });

  $('#editAccountModal').modal('show');
});

$('#editAccountModal').on('shown.bs.modal', function () {
  initEditProgramSelect2();
  updateEditProgramSelectedCount();
});

$('#editAccountModal').on('hidden.bs.modal', function () {
  const $editProgram = $('#edit_program');
  if ($editProgram.data('select2')) {
    $editProgram.select2('destroy');
  }
});

$(document).on('change', '#edit_program', function () {
  updateEditProgramSelectedCount();
});

/* OPEN DELETE MODAL */
  $(document).on('click', '.btn-delete-account', function () {
    $('#delete_accountid').val($(this).data('id'));
    $('#delete_account_name').text($(this).data('name'));
    $('#deleteAccountModal').modal('show');
  });

$(document).on('click', '.btn-toggle-account-lock', function () {
  const accountid = $(this).data('id');
  const accountName = $(this).data('name');
  const action = $(this).data('action');
  const currentStatus = $(this).data('current-status');
  const nextStatus = action === 'lock' ? 'inactive' : 'active';
  const titleText = action === 'lock' ? 'Lock account?' : 'Unlock account?';

  Swal.fire({
    icon: 'question',
    title: titleText,
    text: `${accountName} is currently ${currentStatus}. Set to ${nextStatus}?`,
    showCancelButton: true,
    confirmButtonText: action === 'lock' ? 'Lock' : 'Unlock',
    confirmButtonColor: action === 'lock' ? '#ff3e1d' : '#71dd37'
  }).then((result) => {
    if (!result.isConfirmed) return;

    $.ajax({
      url: 'toggle_account_lock.php',
      method: 'POST',
      data: { accountid: accountid, action: action },
      dataType: 'json',
      success: function (res) {
        if (res.success) {
          Swal.fire({
            icon: 'success',
            title: 'Updated',
            text: res.message,
            timer: 1400,
            showConfirmButton: false
          }).then(() => {
            location.reload();
          });
        } else {
          Swal.fire({
            icon: 'error',
            title: 'Action failed',
            text: res.message || 'Unable to update account status.'
          });
        }
      },
      error: function () {
        Swal.fire({
          icon: 'error',
          title: 'Server error',
          text: 'Unable to update account status.'
        });
      }
    });
  });
});

/* SUBMIT EDIT ACCOUNT */
$('#editAccountForm').on('submit', function (e) {
  e.preventDefault();

  $.ajax({
    url: 'update_account.php',
    method: 'POST',
    data: $(this).serialize(),
    dataType: 'json',
    success: function (res) {
      if (res.success) {
        Swal.fire({
          icon: 'success',
          title: 'Updated',
          text: res.message,
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          location.reload(); // safe reload (infinite scroll safe)
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Update failed',
          text: res.message
        });
      }
    },
    error: function () {
      Swal.fire({
        icon: 'error',
        title: 'Server error',
        text: 'Unable to update account'
      });
    }
  });
});

/* CONFIRM DELETE */
$('#confirmDeleteAccount').on('click', function () {
  const accountid = $('#delete_accountid').val();

  $.ajax({
    url: 'delete_account.php',
    method: 'POST',
    data: { accountid: accountid },
    dataType: 'json',
    success: function (res) {
      if (res.success) {
        Swal.fire({
          icon: 'success',
          title: 'Deleted',
          text: res.message,
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          location.reload();
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Delete failed',
          text: res.message
        });
      }
    },
    error: function () {
      Swal.fire({
        icon: 'error',
        title: 'Server error',
        text: 'Unable to delete account'
      });
    }
  });
});

$('#addAccountModal').on('shown.bs.modal', function () {
  initAddProgramSelect2();
  updateAddProgramSelectedCount();

  $.getJSON('fetch_campuses.php', function (data) {
    let html = '<option value="">- Select Campus -</option>';
    data.forEach(row => {
      html += `<option value="${row.campus_id}">${row.campus_name}</option>`;
    });
    $('#add_campus').html(html);
    loadProgramsForSelect($('#add_program'), []);
  });
});

$('#addAccountModal').on('hidden.bs.modal', function () {
  const $addProgram = $('#add_program');
  if ($addProgram.data('select2')) {
    $addProgram.select2('destroy');
  }
  $('#addProgramSelectedCount').text('0 selected');
});

$('#add_campus').on('change', function () {
  loadProgramsForSelect($('#add_program'), []);
});

$(document).on('change', '#add_program', function () {
  updateAddProgramSelectedCount();
});

$('#edit_campus').on('change', function () {
  const currentSelected = ($('#edit_program').val() || []).map(v => String(v));
  loadProgramsForSelect($('#edit_program'), currentSelected);
});

$('#btnPrintActiveUsers').on('click', function () {
  const reportArea = document.getElementById('activeUsersPrintArea');
  if (!reportArea) return;

  const printWindow = window.open('', '_blank', 'width=1200,height=800');
  if (!printWindow) {
    Swal.fire({
      icon: 'warning',
      title: 'Popup blocked',
      text: 'Please allow popups to print the active users list.'
    });
    return;
  }

  printWindow.document.write(`
    <!doctype html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>Active Users List</title>
      <style>
        body { font-family: Arial, sans-serif; margin: 20px; color: #1f2937; }
        h1 { margin: 0 0 10px; font-size: 20px; }
        table { width: 100%; border-collapse: collapse; font-size: 12px; }
        th, td {
          border: 1px solid #d1d5db;
          padding: 6px 8px;
          text-align: left;
          vertical-align: top;
          white-space: normal;
          overflow-wrap: anywhere;
          word-break: break-word;
        }
        th { background: #f3f4f6; }
        .assigned-program-list { display: flex; flex-direction: column; gap: 4px; }
        .active-user-meta { font-size: 11px; color: #4b5563; display: flex; flex-direction: column; gap: 2px; }
        .campus-group-row td { background: #eef2f7; font-weight: 700; text-transform: uppercase; letter-spacing: 0.03em; }
      </style>
    </head>
    <body>${reportArea.innerHTML}</body>
    </html>
  `);
  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
  printWindow.close();
});

</script>



</body>
</html>


