<?php
require_once '../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$statusFilter = trim((string) ($_GET['status'] ?? ''));
$statusFilter = in_array($statusFilter, ['active', 'inactive'], true) ? $statusFilter : '';
$campusFilter = (int) ($_GET['campus_id'] ?? 0);

$campusOptions = [];
$campusOptionSql = "
    SELECT campus_id, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC
";
$campusOptionResult = $conn->query($campusOptionSql);
if ($campusOptionResult) {
    while ($campusRow = $campusOptionResult->fetch_assoc()) {
        $campusOptions[] = $campusRow;
    }
}

$where = ["a.role = 'progchair'"];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = '(a.acc_fullname LIKE ? OR a.email LIKE ? OR p.program_name LIKE ? OR c.campus_name LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($statusFilter !== '') {
    $where[] = 'a.status = ?';
    $types .= 's';
    $params[] = $statusFilter;
}

if ($campusFilter > 0) {
    $where[] = 'a.campus_id = ?';
    $types .= 'i';
    $params[] = $campusFilter;
}

$sql = "
    SELECT
        a.accountid,
        a.acc_fullname,
        a.email,
        a.status,
        a.campus_id,
        a.program_id,
        c.campus_name,
        p.program_name,
        p.major,
        COALESCE(iv.total_interviews, 0) AS total_interviews,
        COALESCE(iv.scored_interviews, 0) AS scored_interviews,
        COALESCE(iv.unscored_interviews, 0) AS unscored_interviews,
        COALESCE(tr.outgoing_pending, 0) AS outgoing_pending_transfers,
        COALESCE(ti.incoming_pending, 0) AS incoming_pending_transfers,
        COALESCE(logs.total_logs, 0) AS score_activity_count,
        logs.last_scoring_at
    FROM tblaccount a
    LEFT JOIN tbl_campus c
        ON c.campus_id = a.campus_id
    LEFT JOIN tbl_program p
        ON p.program_id = a.program_id
    LEFT JOIN (
        SELECT
            si.program_chair_id,
            COUNT(*) AS total_interviews,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_interviews,
            SUM(CASE WHEN si.final_score IS NULL THEN 1 ELSE 0 END) AS unscored_interviews
        FROM tbl_student_interview si
        WHERE si.status = 'active'
        GROUP BY si.program_chair_id
    ) iv ON iv.program_chair_id = a.accountid
    LEFT JOIN (
        SELECT
            t.transferred_by AS accountid,
            COUNT(*) AS outgoing_pending
        FROM tbl_student_transfer_history t
        WHERE t.status = 'pending'
        GROUP BY t.transferred_by
    ) tr ON tr.accountid = a.accountid
    LEFT JOIN (
        SELECT
            a2.accountid,
            COUNT(*) AS incoming_pending
        FROM tblaccount a2
        INNER JOIN tbl_student_transfer_history t
            ON t.to_program_id = a2.program_id
           AND t.status = 'pending'
        WHERE a2.role = 'progchair'
        GROUP BY a2.accountid
    ) ti ON ti.accountid = a.accountid
    LEFT JOIN (
        SELECT
            l.actor_accountid AS accountid,
            COUNT(*) AS total_logs,
            MAX(l.created_at) AS last_scoring_at
        FROM tbl_score_audit_logs l
        GROUP BY l.actor_accountid
    ) logs ON logs.accountid = a.accountid
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.acc_fullname ASC
";

$chairs = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $chairs[] = $row;
    }
    $stmt->close();
}

$summary = [
    'total' => count($chairs),
    'assigned' => 0,
    'with_unscored' => 0,
    'with_pending' => 0
];

foreach ($chairs as $chair) {
    $hasAssignment = ((int) ($chair['campus_id'] ?? 0) > 0) && ((int) ($chair['program_id'] ?? 0) > 0);
    if ($hasAssignment) {
        $summary['assigned']++;
    }
    if ((int) ($chair['unscored_interviews'] ?? 0) > 0) {
        $summary['with_unscored']++;
    }
    if ((int) ($chair['incoming_pending_transfers'] ?? 0) > 0 || (int) ($chair['outgoing_pending_transfers'] ?? 0) > 0) {
        $summary['with_pending']++;
    }
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
    <title>Program Chair Monitoring - Interview</title>

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
      .pc-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .pc-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .pc-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .pc-table td, .pc-table th {
        vertical-align: middle;
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
                <span class="text-muted fw-light">Monitoring /</span> Program Chair Monitoring
              </h4>
              <p class="text-muted mb-4">
                Read-only visibility of workload, scoring status, and transfer queue by program chair.
              </p>

              <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                  <div class="pc-stat-card">
                    <div class="pc-stat-label">Program Chairs</div>
                    <div class="pc-stat-value"><?= number_format((int) $summary['total']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="pc-stat-card">
                    <div class="pc-stat-label">With Assignment</div>
                    <div class="pc-stat-value"><?= number_format((int) $summary['assigned']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="pc-stat-card">
                    <div class="pc-stat-label">With Unscored</div>
                    <div class="pc-stat-value"><?= number_format((int) $summary['with_unscored']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="pc-stat-card">
                    <div class="pc-stat-label">With Pending Transfer</div>
                    <div class="pc-stat-value"><?= number_format((int) $summary['with_pending']); ?></div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-5">
                      <label class="form-label mb-1">Search</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Name, email, campus, or program"
                      />
                    </div>
                    <div class="col-lg-3">
                      <label class="form-label mb-1">Status</label>
                      <select name="status" class="form-select">
                        <option value="">All</option>
                        <option value="active"<?= $statusFilter === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?= $statusFilter === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                      </select>
                    </div>
                    <div class="col-lg-3">
                      <label class="form-label mb-1">Campus</label>
                      <select name="campus_id" class="form-select">
                        <option value="0">All Campuses</option>
                        <?php foreach ($campusOptions as $campus): ?>
                          <?php $optCampusId = (int) ($campus['campus_id'] ?? 0); ?>
                          <option value="<?= $optCampusId; ?>"<?= $campusFilter === $optCampusId ? ' selected' : ''; ?>>
                            <?= htmlspecialchars((string) ($campus['campus_name'] ?? '')); ?>
                          </option>
                        <?php endforeach; ?>
                      </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <button type="submit" class="btn btn-primary">
                        Go
                      </button>
                    </div>
                  </form>
                </div>

                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm pc-table">
                      <thead>
                        <tr>
                          <th>Program Chair</th>
                          <th>Assignment</th>
                          <th class="text-center">Interviews</th>
                          <th class="text-center">Scored</th>
                          <th class="text-center">Unscored</th>
                          <th class="text-center">Incoming Pending</th>
                          <th class="text-center">Outgoing Pending</th>
                          <th class="text-center">Score Logs</th>
                          <th>Last Scoring</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($chairs)): ?>
                          <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                              No program chair records found.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($chairs as $chair): ?>
                            <?php
                              $statusClass = (($chair['status'] ?? 'inactive') === 'active') ? 'bg-label-success' : 'bg-label-secondary';
                              $campusName = trim((string) ($chair['campus_name'] ?? ''));
                              $programName = trim((string) ($chair['program_name'] ?? ''));
                              $major = trim((string) ($chair['major'] ?? ''));
                              $assignment = ($campusName !== '' && $programName !== '')
                                  ? ($campusName . ' | ' . $programName . ($major !== '' ? ' - ' . $major : ''))
                                  : 'Unassigned';
                            ?>
                            <tr>
                              <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($chair['acc_fullname'] ?? '')); ?></div>
                                <small class="text-muted d-block"><?= htmlspecialchars((string) ($chair['email'] ?? '')); ?></small>
                                <span class="badge <?= $statusClass; ?> mt-1"><?= htmlspecialchars((string) ($chair['status'] ?? 'inactive')); ?></span>
                              </td>
                              <td><?= htmlspecialchars($assignment); ?></td>
                              <td class="text-center"><?= number_format((int) ($chair['total_interviews'] ?? 0)); ?></td>
                              <td class="text-center">
                                <span class="badge bg-label-success"><?= number_format((int) ($chair['scored_interviews'] ?? 0)); ?></span>
                              </td>
                              <td class="text-center">
                                <span class="badge bg-label-warning"><?= number_format((int) ($chair['unscored_interviews'] ?? 0)); ?></span>
                              </td>
                              <td class="text-center">
                                <span class="badge bg-label-info"><?= number_format((int) ($chair['incoming_pending_transfers'] ?? 0)); ?></span>
                              </td>
                              <td class="text-center">
                                <span class="badge bg-label-danger"><?= number_format((int) ($chair['outgoing_pending_transfers'] ?? 0)); ?></span>
                              </td>
                              <td class="text-center"><?= number_format((int) ($chair['score_activity_count'] ?? 0)); ?></td>
                              <td>
                                <?php if (!empty($chair['last_scoring_at'])): ?>
                                  <?= htmlspecialchars((string) $chair['last_scoring_at']); ?>
                                <?php else: ?>
                                  <span class="text-muted">No scoring activity</span>
                                <?php endif; ?>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
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
