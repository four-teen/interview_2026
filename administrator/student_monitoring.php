<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../config/admin_student_impersonation.php';
require_once '../config/program_ranking_lock.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$adminStudentPreviewCsrf = admin_student_impersonation_get_csrf_token();
$adminStudentPreviewFlash = admin_student_impersonation_pop_flash();
$adminStudentPreviewReturnTo = admin_student_impersonation_normalize_return_to((string) ($_SERVER['REQUEST_URI'] ?? ''));

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

function administrator_student_monitoring_build_rank_lookup(mysqli $conn, array $programIds): array
{
    $lookup = [];
    $errors = [];
    $uniqueProgramIds = array_values(array_unique(array_filter(array_map('intval', $programIds), static function (int $programId): bool {
        return $programId > 0;
    })));

    foreach ($uniqueProgramIds as $programId) {
        $payload = program_ranking_fetch_payload($conn, $programId, null);
        if (!($payload['success'] ?? false)) {
            $errors[$programId] = (string) ($payload['message'] ?? 'Failed to validate program ranking.');
            continue;
        }

        foreach ((array) ($payload['rows'] ?? []) as $rankingRow) {
            $interviewId = (int) ($rankingRow['interview_id'] ?? 0);
            if ($interviewId <= 0) {
                continue;
            }

            $lookup[$programId][$interviewId] = [
                'rank' => (int) ($rankingRow['rank'] ?? 0),
                'locked_rank' => (int) ($rankingRow['locked_rank'] ?? 0),
                'is_locked' => !empty($rankingRow['is_locked']),
                'is_outside_capacity' => !empty($rankingRow['is_outside_capacity']),
                'is_endorsement' => !empty($rankingRow['is_endorsement']),
                'row_section' => (string) ($rankingRow['row_section'] ?? 'regular'),
            ];
        }
    }

    return [
        'lookup' => $lookup,
        'errors' => $errors,
    ];
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
        COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) AS ranking_program_id,
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
      ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
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

$rankLookupResult = administrator_student_monitoring_build_rank_lookup(
    $conn,
    array_column($rows, 'ranking_program_id')
);
$sharedRankLookup = (array) ($rankLookupResult['lookup'] ?? []);
$sharedRankErrors = (array) ($rankLookupResult['errors'] ?? []);

foreach ($rows as &$row) {
    $rankingProgramId = (int) ($row['ranking_program_id'] ?? 0);
    $interviewId = (int) ($row['interview_id'] ?? 0);
    $row['shared_rank_display'] = 'N/A';
    $row['shared_rank_badge_class'] = 'bg-label-secondary';
    $row['shared_rank_note'] = 'No ranking validation';

    if ($rankingProgramId <= 0) {
        $row['shared_rank_note'] = 'No ranking program';
        continue;
    }

    if ($row['final_score'] === null) {
        $row['shared_rank_note'] = 'Unscored';
        continue;
    }

    if (isset($sharedRankErrors[$rankingProgramId])) {
        $row['shared_rank_note'] = 'Validation unavailable';
        continue;
    }

    $rankingEntry = $sharedRankLookup[$rankingProgramId][$interviewId] ?? null;
    if (!$rankingEntry) {
        $row['shared_rank_note'] = 'Not in shared ranking list';
        continue;
    }

    $sharedRank = max(
        (int) ($rankingEntry['locked_rank'] ?? 0),
        (int) ($rankingEntry['rank'] ?? 0)
    );
    if ($sharedRank <= 0) {
        $row['shared_rank_note'] = 'Not ranked';
        continue;
    }

    $row['shared_rank_display'] = '#' . number_format($sharedRank);

    if (!empty($rankingEntry['is_locked'])) {
        $row['shared_rank_badge_class'] = 'bg-label-warning';
        $row['shared_rank_note'] = 'Locked shared rank';
    } elseif (!empty($rankingEntry['is_outside_capacity'])) {
        $row['shared_rank_badge_class'] = 'bg-label-danger';
        $row['shared_rank_note'] = 'Shared rank outside capacity';
    } elseif (!empty($rankingEntry['is_endorsement']) || (string) ($rankingEntry['row_section'] ?? '') === 'scc') {
        $row['shared_rank_badge_class'] = 'bg-label-success';
        $row['shared_rank_note'] = 'SCC shared rank';
    } elseif ((string) ($rankingEntry['row_section'] ?? '') === 'etg') {
        $row['shared_rank_badge_class'] = 'bg-label-info';
        $row['shared_rank_note'] = 'ETG shared rank';
    } else {
        $row['shared_rank_badge_class'] = 'bg-label-primary';
        $row['shared_rank_note'] = 'Shared academic rank';
    }
}
unset($row);

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
              <div class="alert alert-light border py-2 mb-3">
                <span class="fw-semibold">Program Rank</span> uses the same shared academic ranking payload used by Program Chair, Monitoring, and Student views.
              </div>
              <?php if (is_array($adminStudentPreviewFlash) && !empty($adminStudentPreviewFlash['message'])): ?>
                <?php $studentPreviewAlertType = ((string) ($adminStudentPreviewFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($studentPreviewAlertType); ?> py-2 mb-3">
                  <?= htmlspecialchars((string) $adminStudentPreviewFlash['message']); ?>
                </div>
              <?php endif; ?>
              <?php if (!empty($sharedRankErrors)): ?>
                <div class="alert alert-warning py-2 mb-3">
                  Shared rank validation is unavailable for <?= number_format(count($sharedRankErrors)); ?> program(s). Affected students show <span class="fw-semibold">Validation unavailable</span>.
                </div>
              <?php endif; ?>
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
                          <th class="text-center">Program Rank</th>
                          <th class="text-center">Profile</th>
                          <th class="text-center">Credential</th>
                          <th class="text-center">Transfer</th>
                          <th class="text-center">Preview</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($rows)): ?>
                          <tr>
                            <td colspan="10" class="text-center text-muted py-4">
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
                                <span class="badge <?= htmlspecialchars((string) ($row['shared_rank_badge_class'] ?? 'bg-label-secondary')); ?>">
                                  <?= htmlspecialchars((string) ($row['shared_rank_display'] ?? 'N/A')); ?>
                                </span>
                                <small class="text-muted d-block mt-1"><?= htmlspecialchars((string) ($row['shared_rank_note'] ?? '')); ?></small>
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
                                <?php if (!empty($row['credential_id']) && ($row['credential_status'] ?? '') === 'active'): ?>
                                  <form method="post" action="impersonate_student.php" class="d-inline">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminStudentPreviewCsrf); ?>" />
                                    <input type="hidden" name="credential_id" value="<?= (int) ($row['credential_id'] ?? 0); ?>" />
                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($adminStudentPreviewReturnTo); ?>" />
                                    <button type="submit" class="btn btn-sm btn-outline-warning">
                                      View as Student
                                    </button>
                                  </form>
                                <?php else: ?>
                                  <button type="button" class="btn btn-sm btn-outline-secondary" disabled title="Active student credential required.">
                                    View as Student
                                  </button>
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
