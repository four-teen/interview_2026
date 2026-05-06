<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
require_once '../config/program_ranking_lock.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'registrar')) {
    header('Location: ../index.php');
    exit;
}

$registrarHeaderTitle = 'Registrar - Locked vs Pre-Registered';
$storageReady = ensure_student_preregistration_storage($conn);
ensure_program_ranking_locks_table($conn);

function registrar_lvp_fetch_single(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $row = $result->fetch_assoc();
    $result->free();

    return is_array($row) ? $row : [];
}

function registrar_lvp_fetch_rows(mysqli $conn, string $sql): array
{
    $rows = [];
    $result = $conn->query($sql);
    if (!$result) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $result->free();

    return $rows;
}

function registrar_lvp_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return number_format(($part / $total) * 100, 1) . '%';
}

function registrar_lvp_format_datetime($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return 'N/A';
    }

    $timestamp = strtotime($raw);
    return ($timestamp !== false) ? date('M j, Y g:i A', $timestamp) : $raw;
}

$summary = [
    'locked_inside_count' => 0,
    'pre_registered_from_locks' => 0,
    'pending_contact_count' => 0,
    'outside_capacity_count' => 0,
    'programs_with_pending' => 0,
    'oldest_pending_locked_at' => null,
];
$programRows = [];
$campusRows = [];
$pendingRows = [];
$lockTrendRows = [];
$preregTrendRows = [];

if ($storageReady) {
    $summary = array_merge($summary, registrar_lvp_fetch_single($conn, "
        SELECT
            COUNT(*) AS locked_inside_count,
            SUM(CASE WHEN spr.preregistration_id IS NOT NULL THEN 1 ELSE 0 END) AS pre_registered_from_locks,
            SUM(CASE WHEN spr.preregistration_id IS NULL THEN 1 ELSE 0 END) AS pending_contact_count,
            (
                SELECT COUNT(*)
                FROM tbl_program_ranking_locks outside_locks
                WHERE outside_locks.snapshot_outside_capacity = 1
            ) AS outside_capacity_count,
            COUNT(DISTINCT CASE WHEN spr.preregistration_id IS NULL THEN l.program_id ELSE NULL END) AS programs_with_pending,
            MIN(CASE WHEN spr.preregistration_id IS NULL THEN l.locked_at ELSE NULL END) AS oldest_pending_locked_at
        FROM tbl_program_ranking_locks l
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        WHERE l.snapshot_outside_capacity = 0
    "));

    $programRows = registrar_lvp_fetch_rows($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(cam.campus_name), ''), 'No campus') AS campus_name,
            COALESCE(NULLIF(TRIM(cam.campus_code), ''), '') AS campus_code,
            p.program_id,
            COALESCE(
                NULLIF(
                    TRIM(CONCAT(
                        COALESCE(NULLIF(p.program_code, ''), ''),
                        CASE WHEN p.program_code IS NOT NULL AND p.program_code <> '' THEN ' - ' ELSE '' END,
                        COALESCE(NULLIF(p.program_name, ''), ''),
                        CASE WHEN p.major IS NOT NULL AND p.major <> '' THEN CONCAT(' - ', p.major) ELSE '' END
                    )),
                    ''
                ),
                CONCAT('Program #', l.program_id)
            ) AS program_label,
            COUNT(*) AS locked_count,
            SUM(CASE WHEN spr.preregistration_id IS NOT NULL THEN 1 ELSE 0 END) AS preregistered_count,
            SUM(CASE WHEN spr.preregistration_id IS NULL THEN 1 ELSE 0 END) AS pending_count,
            MAX(l.locked_at) AS latest_locked_at
        FROM tbl_program_ranking_locks l
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        LEFT JOIN tbl_program p
            ON p.program_id = l.program_id
        LEFT JOIN tbl_college col
            ON col.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = col.campus_id
        WHERE l.snapshot_outside_capacity = 0
        GROUP BY cam.campus_name, cam.campus_code, p.program_id, program_label
        ORDER BY pending_count DESC, locked_count DESC, campus_name ASC, program_label ASC
    ");

    $campusRows = registrar_lvp_fetch_rows($conn, "
        SELECT
            COALESCE(NULLIF(TRIM(cam.campus_name), ''), 'No campus') AS campus_name,
            COUNT(*) AS locked_count,
            SUM(CASE WHEN spr.preregistration_id IS NOT NULL THEN 1 ELSE 0 END) AS preregistered_count,
            SUM(CASE WHEN spr.preregistration_id IS NULL THEN 1 ELSE 0 END) AS pending_count
        FROM tbl_program_ranking_locks l
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        LEFT JOIN tbl_program p
            ON p.program_id = l.program_id
        LEFT JOIN tbl_college col
            ON col.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = col.campus_id
        WHERE l.snapshot_outside_capacity = 0
        GROUP BY campus_name
        ORDER BY pending_count DESC, locked_count DESC, campus_name ASC
    ");

    $pendingRows = registrar_lvp_fetch_rows($conn, "
        SELECT
            l.lock_id,
            l.program_id,
            l.interview_id,
            l.locked_rank,
            l.locked_at,
            l.snapshot_examinee_number,
            l.snapshot_full_name,
            l.snapshot_classification,
            l.snapshot_final_score,
            l.snapshot_section,
            COALESCE(NULLIF(TRIM(cam.campus_name), ''), 'No campus') AS campus_name,
            COALESCE(NULLIF(TRIM(cam.campus_code), ''), '') AS campus_code,
            COALESCE(
                NULLIF(
                    TRIM(CONCAT(
                        COALESCE(NULLIF(p.program_code, ''), ''),
                        CASE WHEN p.program_code IS NOT NULL AND p.program_code <> '' THEN ' - ' ELSE '' END,
                        COALESCE(NULLIF(p.program_name, ''), ''),
                        CASE WHEN p.major IS NOT NULL AND p.major <> '' THEN CONCAT(' - ', p.major) ELSE '' END
                    )),
                    ''
                ),
                CONCAT('Program #', l.program_id)
            ) AS program_label,
            si.mobile_number,
            COALESCE(sc_i.active_email, sc_e.active_email) AS active_email,
            sp.secondary_school_name,
            rc.citymunDesc AS citymun_name,
            rb.brgyDesc AS barangay_name
        FROM tbl_program_ranking_locks l
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        LEFT JOIN tbl_student_interview si
            ON si.interview_id = l.interview_id
        LEFT JOIN tbl_student_credentials sc_i
            ON sc_i.interview_id = l.interview_id
        LEFT JOIN tbl_student_credentials sc_e
            ON sc_e.examinee_number = l.snapshot_examinee_number
        LEFT JOIN tbl_student_profile sp
            ON sp.credential_id = COALESCE(sc_i.credential_id, sc_e.credential_id)
        LEFT JOIN refcitymun rc
            ON rc.citymunCode = sp.citymun_code
        LEFT JOIN refbrgy rb
            ON rb.brgyCode = sp.barangay_code
        LEFT JOIN tbl_program p
            ON p.program_id = l.program_id
        LEFT JOIN tbl_college col
            ON col.college_id = p.college_id
        LEFT JOIN tbl_campus cam
            ON cam.campus_id = col.campus_id
        WHERE l.snapshot_outside_capacity = 0
          AND spr.preregistration_id IS NULL
        ORDER BY campus_name ASC, program_label ASC, l.locked_rank ASC, l.locked_at ASC
        LIMIT 500
    ");

    $lockTrendRows = registrar_lvp_fetch_rows($conn, "
        SELECT DATE(locked_at) AS trend_date, COUNT(*) AS locked_count
        FROM tbl_program_ranking_locks
        WHERE snapshot_outside_capacity = 0
        GROUP BY DATE(locked_at)
        ORDER BY DATE(locked_at) DESC
        LIMIT 30
    ");

    $preregTrendRows = registrar_lvp_fetch_rows($conn, "
        SELECT DATE(submitted_at) AS trend_date, COUNT(*) AS preregistered_count
        FROM tbl_student_preregistration
        WHERE status = 'submitted'
        GROUP BY DATE(submitted_at)
        ORDER BY DATE(submitted_at) DESC
        LIMIT 30
    ");
}

$lockedInsideCount = max(0, (int) ($summary['locked_inside_count'] ?? 0));
$preRegisteredFromLocks = max(0, (int) ($summary['pre_registered_from_locks'] ?? 0));
$pendingContactCount = max(0, (int) ($summary['pending_contact_count'] ?? 0));
$outsideCapacityCount = max(0, (int) ($summary['outside_capacity_count'] ?? 0));
$conversionRate = registrar_lvp_percent($preRegisteredFromLocks, $lockedInsideCount);

$trendMap = [];
foreach ($lockTrendRows as $row) {
    $dateKey = (string) ($row['trend_date'] ?? '');
    if ($dateKey !== '') {
        $trendMap[$dateKey]['locked'] = max(0, (int) ($row['locked_count'] ?? 0));
    }
}
foreach ($preregTrendRows as $row) {
    $dateKey = (string) ($row['trend_date'] ?? '');
    if ($dateKey !== '') {
        $trendMap[$dateKey]['preregistered'] = max(0, (int) ($row['preregistered_count'] ?? 0));
    }
}
ksort($trendMap);
if (count($trendMap) > 30) {
    $trendMap = array_slice($trendMap, -30, null, true);
}

$trendCategories = [];
$trendLockedData = [];
$trendPreregisteredData = [];
$trendGapData = [];
foreach ($trendMap as $dateKey => $row) {
    $locked = max(0, (int) ($row['locked'] ?? 0));
    $preregistered = max(0, (int) ($row['preregistered'] ?? 0));
    $trendCategories[] = date('M j', strtotime($dateKey));
    $trendLockedData[] = $locked;
    $trendPreregisteredData[] = $preregistered;
    $trendGapData[] = max(0, $locked - $preregistered);
}
$trendSeries = [
    ['name' => 'Locked inside capacity', 'data' => $trendLockedData],
    ['name' => 'Pre-Registered', 'data' => $trendPreregisteredData],
    ['name' => 'Same-day gap', 'data' => $trendGapData],
];

$programChartLabels = [];
$programLockedData = [];
$programPreregisteredData = [];
$programPendingData = [];
foreach (array_slice($programRows, 0, 12) as $programRow) {
    $programChartLabels[] = (string) ($programRow['program_label'] ?? 'Program');
    $programLockedData[] = max(0, (int) ($programRow['locked_count'] ?? 0));
    $programPreregisteredData[] = max(0, (int) ($programRow['preregistered_count'] ?? 0));
    $programPendingData[] = max(0, (int) ($programRow['pending_count'] ?? 0));
}

$campusChartLabels = [];
$campusLockedData = [];
$campusPreregisteredData = [];
$campusPendingData = [];
foreach ($campusRows as $campusRow) {
    $campusChartLabels[] = (string) ($campusRow['campus_name'] ?? 'Campus');
    $campusLockedData[] = max(0, (int) ($campusRow['locked_count'] ?? 0));
    $campusPreregisteredData[] = max(0, (int) ($campusRow['preregistered_count'] ?? 0));
    $campusPendingData[] = max(0, (int) ($campusRow['pending_count'] ?? 0));
}

$priorityProgram = $programRows[0] ?? null;
$oldestPendingText = !empty($summary['oldest_pending_locked_at'])
    ? registrar_lvp_format_datetime((string) $summary['oldest_pending_locked_at'])
    : 'N/A';
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
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimum-scale=1.0"
    />
    <title>Locked vs Pre-Registered - Registrar</title>

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
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .lvp-stat-card {
        height: 100%;
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.95rem 1rem;
        background: #fff;
      }

      .lvp-stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .lvp-stat-value {
        margin-top: 0.35rem;
        font-size: 1.5rem;
        font-weight: 700;
        line-height: 1.08;
        color: #2f3f59;
      }

      .lvp-stat-hint,
      .lvp-subline {
        display: block;
        margin-top: 0.24rem;
        font-size: 0.78rem;
        line-height: 1.3;
        color: #7d8aa3;
      }

      .lvp-chart-card,
      .lvp-table-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.9rem;
        background: #fff;
      }

      .lvp-chart-title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .lvp-chart-subtitle {
        color: #7d8aa3;
        font-size: 0.82rem;
      }

      .lvp-program-name,
      .lvp-student-name {
        font-weight: 700;
        color: #2f3f59;
      }

      .lvp-table td,
      .lvp-table th {
        vertical-align: middle;
      }

      .lvp-priority-card {
        border: 1px solid #ffe0a3;
        border-radius: 0.85rem;
        background: #fffaf0;
        padding: 0.95rem 1rem;
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
                <span class="text-muted fw-light">Registrar /</span> Locked vs Pre-Registered
              </h4>
              <p class="text-muted mb-4">
                Compare inside-capacity ranking locks against submitted pre-registrations to identify students who need follow-up.
              </p>

              <?php if (!$storageReady): ?>
                <div class="alert alert-danger">
                  Unable to prepare pre-registration storage. Refresh the page or check database permissions.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                  <div class="lvp-stat-card">
                    <div class="lvp-stat-label">Total To Be Admitted</div>
                    <div class="lvp-stat-value"><?= number_format($lockedInsideCount); ?></div>
                    <span class="lvp-stat-hint">Inside-capacity locked students</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="lvp-stat-card">
                    <div class="lvp-stat-label">Pre-Registered</div>
                    <div class="lvp-stat-value"><?= number_format($preRegisteredFromLocks); ?></div>
                    <span class="lvp-stat-hint"><?= htmlspecialchars($conversionRate); ?> conversion from locked pool</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="lvp-stat-card">
                    <div class="lvp-stat-label">Need Contact</div>
                    <div class="lvp-stat-value"><?= number_format($pendingContactCount); ?></div>
                    <span class="lvp-stat-hint"><?= number_format((int) ($summary['programs_with_pending'] ?? 0)); ?> programs with pending students</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="lvp-stat-card">
                    <div class="lvp-stat-label">Outside Capacity</div>
                    <div class="lvp-stat-value"><?= number_format($outsideCapacityCount); ?></div>
                    <span class="lvp-stat-hint">Excluded from contact gap</span>
                  </div>
                </div>
              </div>

              <div class="alert alert-primary d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-2 mb-4">
                <div>
                  <strong>Admission calculation:</strong>
                  Total to be admitted <?= number_format($lockedInsideCount); ?>
                  - pre-registered <?= number_format($preRegisteredFromLocks); ?>
                  = remaining <?= number_format($pendingContactCount); ?>.
                </div>
                <div class="small">
                  Outside-capacity locked students are tracked separately and are not counted as remaining admissions.
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-8">
                  <div class="card lvp-chart-card">
                    <div class="card-body">
                      <div class="lvp-chart-title">Lock vs Pre-Registration Trend</div>
                      <div class="lvp-chart-subtitle mb-3">Daily inside-capacity locks, submitted pre-registrations, and same-day gap.</div>
                      <div id="registrarLockedTrendChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-4">
                  <div class="lvp-priority-card mb-3">
                    <div class="lvp-stat-label">Suggested Priority</div>
                    <?php if ($priorityProgram): ?>
                      <div class="lvp-program-name mt-2"><?= htmlspecialchars((string) ($priorityProgram['program_label'] ?? 'Program')); ?></div>
                      <span class="lvp-stat-hint">
                        <?= htmlspecialchars((string) ($priorityProgram['campus_name'] ?? 'No campus')); ?> has
                        <?= number_format((int) ($priorityProgram['pending_count'] ?? 0)); ?> locked student(s) still not pre-registered.
                      </span>
                    <?php else: ?>
                      <div class="lvp-program-name mt-2">No pending contact gap</div>
                      <span class="lvp-stat-hint">All inside-capacity locked students are already pre-registered.</span>
                    <?php endif; ?>
                  </div>
                  <div class="lvp-stat-card">
                    <div class="lvp-stat-label">Oldest Pending Lock</div>
                    <div class="lvp-stat-value" style="font-size: 1.05rem;"><?= htmlspecialchars($oldestPendingText); ?></div>
                    <span class="lvp-stat-hint">Use this to prioritize students who have waited longest after rank lock.</span>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-6">
                  <div class="card lvp-chart-card">
                    <div class="card-body">
                      <div class="lvp-chart-title">Campus Gap</div>
                      <div class="lvp-chart-subtitle mb-3">Locked, pre-registered, and pending students by campus.</div>
                      <div id="registrarCampusGapChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-6">
                  <div class="card lvp-chart-card">
                    <div class="card-body">
                      <div class="lvp-chart-title">Top Program Gaps</div>
                      <div class="lvp-chart-subtitle mb-3">Programs with the largest locked-but-not-pre-registered counts.</div>
                      <div id="registrarProgramGapChart"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card lvp-table-card mb-4">
                <div class="card-body">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                      <div class="lvp-chart-title">Program Admission Summary</div>
                      <div class="lvp-chart-subtitle">Total to admit is the inside-capacity locked count. Remaining means locked students not yet pre-registered.</div>
                    </div>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm lvp-table">
                      <thead>
                        <tr>
                          <th>Campus</th>
                          <th>Program</th>
                          <th class="text-center">Total To Admit</th>
                          <th class="text-center">Pre-Registered</th>
                          <th class="text-center">Remaining</th>
                          <th class="text-center">Rate</th>
                          <th>Latest Lock</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($programRows)): ?>
                          <tr>
                            <td colspan="7" class="text-center text-muted py-4">No inside-capacity locked students found.</td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($programRows as $programRow): ?>
                            <?php
                              $locked = max(0, (int) ($programRow['locked_count'] ?? 0));
                              $registered = max(0, (int) ($programRow['preregistered_count'] ?? 0));
                              $pending = max(0, (int) ($programRow['pending_count'] ?? 0));
                            ?>
                            <tr>
                              <td><?= htmlspecialchars((string) ($programRow['campus_name'] ?? 'No campus')); ?></td>
                              <td>
                                <div class="lvp-program-name"><?= htmlspecialchars((string) ($programRow['program_label'] ?? 'Program')); ?></div>
                              </td>
                              <td class="text-center"><?= number_format($locked); ?></td>
                              <td class="text-center"><?= number_format($registered); ?></td>
                              <td class="text-center">
                                <span class="badge bg-label-<?= $pending > 0 ? 'warning' : 'success'; ?>"><?= number_format($pending); ?></span>
                              </td>
                              <td class="text-center"><?= htmlspecialchars(registrar_lvp_percent($registered, $locked)); ?></td>
                              <td><?= htmlspecialchars(registrar_lvp_format_datetime((string) ($programRow['latest_locked_at'] ?? ''))); ?></td>
                            </tr>
                          <?php endforeach; ?>
                        <?php endif; ?>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <div class="card lvp-table-card">
                <div class="card-body">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                      <div class="lvp-chart-title">Locked Students Needing Contact</div>
                      <div class="lvp-chart-subtitle">Inside-capacity locked students with no submitted pre-registration yet. Showing up to 500 records.</div>
                    </div>
                    <span class="badge bg-label-warning"><?= number_format(count($pendingRows)); ?> shown</span>
                  </div>
                  <div class="table-responsive">
                    <table class="table table-sm lvp-table">
                      <thead>
                        <tr>
                          <th>Student</th>
                          <th>Campus / Program</th>
                          <th class="text-center">Rank</th>
                          <th>Contact</th>
                          <th>Address / School</th>
                          <th>Locked</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($pendingRows)): ?>
                          <tr>
                            <td colspan="6" class="text-center text-muted py-4">
                              No locked students need contact right now.
                            </td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($pendingRows as $pendingRow): ?>
                            <tr>
                              <td>
                                <div class="lvp-student-name"><?= htmlspecialchars((string) ($pendingRow['snapshot_full_name'] ?? 'Unknown Student')); ?></div>
                                <span class="lvp-subline">Examinee #: <?= htmlspecialchars((string) ($pendingRow['snapshot_examinee_number'] ?? '')); ?></span>
                                <span class="lvp-subline">Class: <?= htmlspecialchars((string) ($pendingRow['snapshot_classification'] ?? 'N/A')); ?> | Score <?= htmlspecialchars(number_format((float) ($pendingRow['snapshot_final_score'] ?? 0), 2)); ?></span>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($pendingRow['campus_name'] ?? 'No campus')); ?></div>
                                <span class="lvp-subline"><?= htmlspecialchars((string) ($pendingRow['program_label'] ?? 'Program')); ?></span>
                              </td>
                              <td class="text-center">
                                <span class="badge bg-label-warning">#<?= number_format((int) ($pendingRow['locked_rank'] ?? 0)); ?></span>
                              </td>
                              <td>
                                <div><?= htmlspecialchars(trim((string) ($pendingRow['mobile_number'] ?? '')) !== '' ? (string) $pendingRow['mobile_number'] : 'No mobile'); ?></div>
                                <span class="lvp-subline"><?= htmlspecialchars(trim((string) ($pendingRow['active_email'] ?? '')) !== '' ? (string) $pendingRow['active_email'] : 'No email'); ?></span>
                              </td>
                              <td>
                                <div><?= htmlspecialchars(trim((string) ($pendingRow['citymun_name'] ?? '')) !== '' ? (string) $pendingRow['citymun_name'] : 'No city/municipality'); ?></div>
                                <span class="lvp-subline">Barangay: <?= htmlspecialchars(trim((string) ($pendingRow['barangay_name'] ?? '')) !== '' ? (string) $pendingRow['barangay_name'] : 'N/A'); ?></span>
                                <span class="lvp-subline">School: <?= htmlspecialchars(trim((string) ($pendingRow['secondary_school_name'] ?? '')) !== '' ? (string) $pendingRow['secondary_school_name'] : 'N/A'); ?></span>
                              </td>
                              <td><?= htmlspecialchars(registrar_lvp_format_datetime((string) ($pendingRow['locked_at'] ?? ''))); ?></td>
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
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (typeof ApexCharts === 'undefined') {
          return;
        }

        const colors = ['#696cff', '#71dd37', '#ffab00', '#03c3ec', '#ff3e1d'];
        const noData = { text: 'No lock/pre-registration data available.' };

        function formatNumber(value) {
          const numeric = Number(value);
          return Number.isFinite(numeric) ? Math.round(numeric).toLocaleString() : '0';
        }

        function renderGroupedBar(selector, categories, series, horizontal) {
          const el = document.querySelector(selector);
          if (!el) return;

          new ApexCharts(el, {
            chart: { type: 'bar', height: horizontal ? 380 : 340, toolbar: { show: false } },
            series,
            colors,
            plotOptions: {
              bar: {
                horizontal: !!horizontal,
                borderRadius: 4
              }
            },
            dataLabels: { enabled: false },
            legend: { position: 'top', horizontalAlign: 'left' },
            xaxis: { categories, labels: { formatter: formatNumber } },
            yaxis: { labels: { maxWidth: horizontal ? 240 : 120 } },
            tooltip: { y: { formatter: (value) => `${formatNumber(value)} student${Number(value) === 1 ? '' : 's'}` } },
            noData
          }).render();
        }

        new ApexCharts(document.querySelector('#registrarLockedTrendChart'), {
          chart: { type: 'line', height: 350, toolbar: { show: false }, zoom: { enabled: false } },
          series: <?= json_encode($trendSeries); ?>,
          colors: ['#696cff', '#71dd37', '#ffab00'],
          stroke: { curve: 'smooth', width: 3 },
          markers: { size: 4, strokeWidth: 0 },
          dataLabels: { enabled: false },
          grid: { borderColor: '#eef2f7', strokeDashArray: 4 },
          legend: { position: 'top', horizontalAlign: 'left' },
          xaxis: { categories: <?= json_encode($trendCategories); ?> },
          yaxis: { min: 0, forceNiceScale: true, labels: { formatter: formatNumber } },
          tooltip: { y: { formatter: (value) => `${formatNumber(value)} record${Number(value) === 1 ? '' : 's'}` } },
          noData
        }).render();

        renderGroupedBar(
          '#registrarCampusGapChart',
          <?= json_encode($campusChartLabels); ?>,
          [
            { name: 'Total To Admit', data: <?= json_encode($campusLockedData); ?> },
            { name: 'Pre-Registered', data: <?= json_encode($campusPreregisteredData); ?> },
            { name: 'Remaining', data: <?= json_encode($campusPendingData); ?> }
          ],
          true
        );

        renderGroupedBar(
          '#registrarProgramGapChart',
          <?= json_encode($programChartLabels); ?>,
          [
            { name: 'Total To Admit', data: <?= json_encode($programLockedData); ?> },
            { name: 'Pre-Registered', data: <?= json_encode($programPreregisteredData); ?> },
            { name: 'Remaining', data: <?= json_encode($programPendingData); ?> }
          ],
          true
        );
      });
    </script>
  </body>
</html>
