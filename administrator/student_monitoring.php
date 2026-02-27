<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$campusFilter = (int) ($_GET['campus_id'] ?? 0);
$scoreFilter = trim((string) ($_GET['score_status'] ?? ''));
$scoreFilter = in_array($scoreFilter, ['scored', 'unscored'], true) ? $scoreFilter : '';
$profileFilter = trim((string) ($_GET['profile_status'] ?? ''));
$profileFilter = in_array($profileFilter, ['complete', 'incomplete', 'none'], true) ? $profileFilter : '';
$credentialFilter = trim((string) ($_GET['credential_status'] ?? ''));
$credentialFilter = in_array($credentialFilter, ['active', 'inactive', 'needs_change', 'none'], true) ? $credentialFilter : '';
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
$globalSatCutoffActive = ($globalSatCutoffEnabled && $globalSatCutoffValue !== null);

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

$where = ['1=1'];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = '(pr.examinee_number LIKE ? OR pr.full_name LIKE ? OR p.program_name LIKE ? OR c.campus_name LIKE ?)';
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($campusFilter > 0) {
    $where[] = 'si.campus_id = ?';
    $types .= 'i';
    $params[] = $campusFilter;
}

if ($scoreFilter === 'scored') {
    $where[] = 'si.final_score IS NOT NULL';
} elseif ($scoreFilter === 'unscored') {
    $where[] = 'si.final_score IS NULL';
}

if ($profileFilter === 'complete') {
    $where[] = 'sp.profile_id IS NOT NULL AND sp.profile_completion_percent >= 100';
} elseif ($profileFilter === 'incomplete') {
    $where[] = 'sp.profile_id IS NOT NULL AND sp.profile_completion_percent < 100';
} elseif ($profileFilter === 'none') {
    $where[] = 'sp.profile_id IS NULL';
}

if ($credentialFilter === 'active') {
    $where[] = "sc.status = 'active'";
} elseif ($credentialFilter === 'inactive') {
    $where[] = "sc.status = 'inactive'";
} elseif ($credentialFilter === 'needs_change') {
    $where[] = 'sc.must_change_password = 1';
} elseif ($credentialFilter === 'none') {
    $where[] = 'sc.credential_id IS NULL';
}

if ($globalSatCutoffActive) {
    $where[] = 'pr.sat_score >= ?';
    $types .= 'i';
    $params[] = $globalSatCutoffValue;
}

$sql = "
    SELECT
        si.interview_id,
        si.examinee_number,
        si.interview_datetime,
        si.classification,
        si.final_score,
        si.status AS interview_status,
        pr.full_name,
        pr.sat_score,
        c.campus_name,
        p.program_name,
        p.major,
        sc.credential_id,
        sc.status AS credential_status,
        sc.must_change_password,
        sc.password_changed_at,
        sp.profile_id,
        sp.profile_completion_percent,
        (
            SELECT COUNT(*)
            FROM tbl_student_transfer_history th
            WHERE th.interview_id = si.interview_id
              AND th.status = 'pending'
        ) AS pending_transfer_count
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
      ON pr.id = si.placement_result_id
    LEFT JOIN tbl_campus c
      ON c.campus_id = si.campus_id
    LEFT JOIN tbl_program p
      ON p.program_id = si.first_choice
    LEFT JOIN tbl_student_credentials sc
      ON sc.examinee_number = si.examinee_number
    LEFT JOIN tbl_student_profile sp
      ON sp.examinee_number = si.examinee_number
    WHERE " . implode(' AND ', $where) . "
    ORDER BY si.interview_datetime DESC, si.interview_id DESC
    LIMIT 1000
";

$rows = [];
$stmt = $conn->prepare($sql);
if ($stmt) {
    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

$summary = [
    'total' => count($rows),
    'scored' => 0,
    'unscored' => 0,
    'with_pending_transfer' => 0,
    'needs_password_change' => 0
];

foreach ($rows as $row) {
    $isScored = ($row['final_score'] !== null);
    if ($isScored) {
        $summary['scored']++;
    } else {
        $summary['unscored']++;
    }

    if ((int) ($row['pending_transfer_count'] ?? 0) > 0) {
        $summary['with_pending_transfer']++;
    }

    if ((int) ($row['must_change_password'] ?? 0) === 1) {
        $summary['needs_password_change']++;
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
    <title>Student Monitoring - Interview</title>

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
      .st-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .st-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .st-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .st-table td, .st-table th {
        vertical-align: middle;
      }

      .rank-detail-row {
        margin-bottom: 0.45rem;
      }

      .rank-detail-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .rank-detail-value {
        font-size: 1rem;
        font-weight: 600;
        color: #2f3f59;
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
                <span class="text-muted fw-light">Monitoring /</span> Student Monitoring
              </h4>
              <p class="text-muted mb-4">
                Read-only visibility of student interview status, credentials, profile progress, and transfer queue.
              </p>
              <?php if ($globalSatCutoffActive): ?>
                <div class="alert alert-info py-2 mb-3">
                  Global SAT cutoff is active: showing students with SAT >= <?= number_format((int) $globalSatCutoffValue); ?>.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                  <div class="st-stat-card">
                    <div class="st-stat-label">Students (Filtered)</div>
                    <div class="st-stat-value"><?= number_format((int) $summary['total']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="st-stat-card">
                    <div class="st-stat-label">Scored</div>
                    <div class="st-stat-value"><?= number_format((int) $summary['scored']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="st-stat-card">
                    <div class="st-stat-label">Unscored</div>
                    <div class="st-stat-value"><?= number_format((int) $summary['unscored']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="st-stat-card">
                    <div class="st-stat-label">Pending Transfer</div>
                    <div class="st-stat-value"><?= number_format((int) $summary['with_pending_transfer']); ?></div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-3">
                      <label class="form-label mb-1">Search</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Examinee #, name, program"
                      />
                    </div>
                    <div class="col-lg-2">
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
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Scoring</label>
                      <select name="score_status" class="form-select">
                        <option value="">All</option>
                        <option value="scored"<?= $scoreFilter === 'scored' ? ' selected' : ''; ?>>Scored</option>
                        <option value="unscored"<?= $scoreFilter === 'unscored' ? ' selected' : ''; ?>>Unscored</option>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Profile</label>
                      <select name="profile_status" class="form-select">
                        <option value="">All</option>
                        <option value="complete"<?= $profileFilter === 'complete' ? ' selected' : ''; ?>>Complete</option>
                        <option value="incomplete"<?= $profileFilter === 'incomplete' ? ' selected' : ''; ?>>Incomplete</option>
                        <option value="none"<?= $profileFilter === 'none' ? ' selected' : ''; ?>>No Profile</option>
                      </select>
                    </div>
                    <div class="col-lg-2">
                      <label class="form-label mb-1">Credential</label>
                      <select name="credential_status" class="form-select">
                        <option value="">All</option>
                        <option value="active"<?= $credentialFilter === 'active' ? ' selected' : ''; ?>>Active</option>
                        <option value="inactive"<?= $credentialFilter === 'inactive' ? ' selected' : ''; ?>>Inactive</option>
                        <option value="needs_change"<?= $credentialFilter === 'needs_change' ? ' selected' : ''; ?>>Needs Change</option>
                        <option value="none"<?= $credentialFilter === 'none' ? ' selected' : ''; ?>>No Credential</option>
                      </select>
                    </div>
                    <div class="col-lg-1 d-grid">
                      <button type="submit" class="btn btn-primary">Go</button>
                    </div>
                  </form>
                </div>

                <div class="card-body">
                  <div class="table-responsive">
                    <table class="table table-sm st-table">
                      <thead>
                        <tr>
                          <th>Student</th>
                          <th>Campus / Program</th>
                          <th class="text-center">SAT</th>
                          <th class="text-center">Interview</th>
                          <th class="text-center">Final Score</th>
                          <th class="text-center">Profile</th>
                          <th class="text-center">Credential</th>
                          <th class="text-center">Transfer</th>
                          <th class="text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($rows)): ?>
                          <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                              No student records found.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($rows as $row): ?>
                            <?php
                              $profilePercent = $row['profile_completion_percent'] !== null
                                  ? (float) $row['profile_completion_percent']
                                  : null;
                              $programName = trim((string) ($row['program_name'] ?? ''));
                              $major = trim((string) ($row['major'] ?? ''));
                              $programDisplay = $programName !== ''
                                  ? ($programName . ($major !== '' ? ' - ' . $major : ''))
                                  : 'No Program';
                            ?>
                            <tr>
                              <td>
                                <div class="fw-semibold"><?= htmlspecialchars((string) ($row['full_name'] ?? '')); ?></div>
                                <small class="text-muted d-block">Examinee #: <?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($row['campus_name'] ?? 'No Campus')); ?></div>
                                <small class="text-muted"><?= htmlspecialchars($programDisplay); ?></small>
                              </td>
                              <td class="text-center"><?= number_format((int) ($row['sat_score'] ?? 0)); ?></td>
                              <td class="text-center">
                                <?php if (!empty($row['interview_datetime'])): ?>
                                  <span class="badge bg-label-info">Done</span>
                                <?php else: ?>
                                  <span class="badge bg-label-secondary">No Date</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <?php if ($row['final_score'] !== null): ?>
                                  <span class="badge bg-label-success"><?= number_format((float) $row['final_score'], 2); ?></span>
                                <?php else: ?>
                                  <span class="badge bg-label-warning">Unscored</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <?php if ($profilePercent === null): ?>
                                  <span class="badge bg-label-secondary">No Profile</span>
                                <?php elseif ($profilePercent >= 100): ?>
                                  <span class="badge bg-label-success"><?= number_format($profilePercent, 2); ?>%</span>
                                <?php else: ?>
                                  <span class="badge bg-label-warning"><?= number_format($profilePercent, 2); ?>%</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <?php if (empty($row['credential_id'])): ?>
                                  <span class="badge bg-label-secondary">None</span>
                                <?php elseif ((int) ($row['must_change_password'] ?? 0) === 1): ?>
                                  <span class="badge bg-label-warning">Needs Change</span>
                                <?php elseif (($row['credential_status'] ?? '') === 'active'): ?>
                                  <span class="badge bg-label-success">Active</span>
                                <?php else: ?>
                                  <span class="badge bg-label-danger">Inactive</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <?php if ((int) ($row['pending_transfer_count'] ?? 0) > 0): ?>
                                  <span class="badge bg-label-danger">Pending</span>
                                <?php else: ?>
                                  <span class="badge bg-label-success">Clear</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center">
                                <button
                                  type="button"
                                  class="btn btn-sm btn-outline-primary js-view-rank"
                                  data-interview-id="<?= (int) ($row['interview_id'] ?? 0); ?>"
                                >
                                  View Rank
                                </button>
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

            <div class="modal fade" id="studentRankModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title">Student Current Rank</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div id="studentRankLoading" class="text-center py-4 d-none">
                      <div class="spinner-border text-primary" role="status"></div>
                      <div class="small text-muted mt-2">Loading rank details...</div>
                    </div>
                    <div id="studentRankError" class="alert alert-danger d-none mb-0"></div>
                    <div id="studentRankBody" class="d-none">
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">Student</div>
                        <div class="rank-detail-value" id="rankStudentName">--</div>
                      </div>
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">Examinee #</div>
                        <div class="rank-detail-value" id="rankExaminee">--</div>
                      </div>
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">Program</div>
                        <div class="rank-detail-value" id="rankProgram">--</div>
                      </div>
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">List</div>
                        <div class="rank-detail-value" id="rankPool">--</div>
                      </div>
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">Current Rank</div>
                        <div class="rank-detail-value" id="rankValue">--</div>
                      </div>
                      <div class="rank-detail-row">
                        <div class="rank-detail-label">Capacity Status</div>
                        <div class="rank-detail-value" id="rankCapacityLabel">--</div>
                      </div>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
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
    <script>
      (function () {
        const modalEl = document.getElementById('studentRankModal');
        if (!modalEl) return;

        const rankModal = new bootstrap.Modal(modalEl);
        const loadingEl = document.getElementById('studentRankLoading');
        const errorEl = document.getElementById('studentRankError');
        const bodyEl = document.getElementById('studentRankBody');

        const fieldStudent = document.getElementById('rankStudentName');
        const fieldExaminee = document.getElementById('rankExaminee');
        const fieldProgram = document.getElementById('rankProgram');
        const fieldPool = document.getElementById('rankPool');
        const fieldRank = document.getElementById('rankValue');
        const fieldCapacity = document.getElementById('rankCapacityLabel');

        function setState(state) {
          if (loadingEl) loadingEl.classList.toggle('d-none', state !== 'loading');
          if (errorEl) errorEl.classList.toggle('d-none', state !== 'error');
          if (bodyEl) bodyEl.classList.toggle('d-none', state !== 'ready');
        }

        function setError(message) {
          if (errorEl) {
            errorEl.textContent = message || 'Failed to load rank details.';
          }
          setState('error');
        }

        function fillRankData(payload) {
          const student = payload && payload.student ? payload.student : {};
          const ranking = payload && payload.ranking ? payload.ranking : {};

          if (fieldStudent) fieldStudent.textContent = student.full_name || '--';
          if (fieldExaminee) fieldExaminee.textContent = student.examinee_number || '--';
          if (fieldProgram) fieldProgram.textContent = student.program_display || '--';
          if (fieldPool) fieldPool.textContent = ranking.pool_label || '--';
          if (fieldRank) fieldRank.textContent = ranking.rank_display || (ranking.message || '--');
          if (fieldCapacity) {
            if (ranking.outside_capacity === true) {
              fieldCapacity.innerHTML = '<span class="badge bg-label-danger">Outside Capacity</span>';
            } else if (ranking.outside_capacity === false) {
              fieldCapacity.innerHTML = '<span class="badge bg-label-success">Within Capacity</span>';
            } else {
              fieldCapacity.textContent = 'Not Available';
            }
          }
        }

        async function loadRank(interviewId) {
          setState('loading');
          rankModal.show();

          try {
            const response = await fetch('get_student_program_rank.php?interview_id=' + encodeURIComponent(String(interviewId || 0)), {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (!data || !data.success) {
              throw new Error((data && data.message) || 'Unable to load rank.');
            }

            fillRankData(data);
            setState('ready');
          } catch (error) {
            setError((error && error.message) ? error.message : 'Unable to load rank.');
          }
        }

        document.querySelectorAll('.js-view-rank').forEach((btn) => {
          btn.addEventListener('click', () => {
            const interviewId = Number(btn.getAttribute('data-interview-id') || 0);
            if (interviewId <= 0) {
              return;
            }
            loadRank(interviewId);
          });
        });
      })();
    </script>
  </body>
</html>
