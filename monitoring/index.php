<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'monitoring')) {
    header('Location: ../index.php');
    exit;
}

$monitoringHeaderTitle = 'Monitoring Center - Dashboard';

function normalize_monitoring_dashboard_token(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/', '', trim($value)));
}

function extract_monitoring_preferred_program_token(string $preferredProgram): string
{
    $preferredProgram = trim($preferredProgram);
    if ($preferredProgram === '') {
        return '';
    }

    $prefix = $preferredProgram;
    $parts = preg_split('/\s*(?:\||-|:)\s*/', $preferredProgram, 2);
    if (is_array($parts) && isset($parts[0])) {
        $candidate = trim((string) $parts[0]);
        if ($candidate !== '') {
            $prefix = $candidate;
        }
    }

    $words = preg_split('/\s+/', $prefix);
    $firstWord = trim((string) ($words[0] ?? $prefix));

    return normalize_monitoring_dashboard_token($firstWord);
}

function monitoring_dashboard_score_matches_cutoff($score, bool $cutoffActive, array $ranges, ?int $fallbackCutoff): bool
{
    if (!$cutoffActive) {
        return true;
    }

    if (!is_numeric($score)) {
        return false;
    }

    $numericScore = (float) $score;
    if (!empty($ranges)) {
        foreach ($ranges as $range) {
            $min = isset($range['min']) ? (float) $range['min'] : 0;
            $max = isset($range['max']) ? (float) $range['max'] : 0;
            if ($numericScore >= $min && $numericScore <= $max) {
                return true;
            }
        }

        return false;
    }

    if ($fallbackCutoff !== null) {
        return $numericScore >= $fallbackCutoff;
    }

    return true;
}

function format_monitoring_percent(?float $value): string
{
    if ($value === null) {
        return 'N/A';
    }

    return number_format($value, 1) . '%';
}

$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffRanges = is_array($globalSatCutoffState['ranges'] ?? null) ? $globalSatCutoffState['ranges'] : [];
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
$globalSatCutoffRangeText = trim((string) ($globalSatCutoffState['range_text'] ?? ''));
$globalSatCutoffActive = $globalSatCutoffEnabled && (!empty($globalSatCutoffRanges) || $globalSatCutoffValue !== null);

if ($globalSatCutoffActive && $globalSatCutoffRangeText === '') {
    if (!empty($globalSatCutoffRanges)) {
        $globalSatCutoffRangeText = format_sat_cutoff_ranges_for_display($globalSatCutoffRanges, ', ');
    } elseif ($globalSatCutoffValue !== null) {
        $globalSatCutoffRangeText = 'SAT >= ' . number_format($globalSatCutoffValue);
    }
}

$campuses = [];
$campusSql = "
    SELECT campus_id, campus_code, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC
";
$campusResult = $conn->query($campusSql);
if ($campusResult) {
    while ($campusRow = $campusResult->fetch_assoc()) {
        $campuses[] = $campusRow;
    }
}

$campusTokenMap = [];
$campusExpectedCounts = [];
$campusInterviewedCounts = [];
$campusScoredCounts = [];
$campusChartLabels = [];
$campusChartFullLabels = [];
$campusSnapshotRows = [];

foreach ($campuses as $campusRow) {
    $campusId = (int) ($campusRow['campus_id'] ?? 0);
    if ($campusId <= 0) {
        continue;
    }

    $campusCode = trim((string) ($campusRow['campus_code'] ?? ''));
    $campusName = trim((string) ($campusRow['campus_name'] ?? ''));
    $campusNameParts = $campusName !== '' ? preg_split('/\s+/', $campusName) : [];
    $campusFirstWord = trim((string) ($campusNameParts[0] ?? ''));

    $campusCodeToken = normalize_monitoring_dashboard_token($campusCode);
    $campusNameToken = normalize_monitoring_dashboard_token($campusFirstWord);

    if ($campusCodeToken !== '') {
        $campusTokenMap[$campusCodeToken] = $campusId;
    }
    if ($campusNameToken !== '') {
        $campusTokenMap[$campusNameToken] = $campusId;
    }

    $campusExpectedCounts[$campusId] = 0;
    $campusInterviewedCounts[$campusId] = 0;
    $campusScoredCounts[$campusId] = 0;
    $campusChartLabels[$campusId] = $campusCode !== '' ? strtoupper($campusCode) : ($campusFirstWord !== '' ? $campusFirstWord : ('Campus ' . $campusId));
    $campusChartFullLabels[$campusId] = $campusName !== '' ? $campusName : ('Campus ' . $campusId);
}

$activeCampusCount = count($campusExpectedCounts);
$activePrograms = 0;
$programCountResult = $conn->query("SELECT COUNT(*) AS total FROM tbl_program WHERE status = 'active'");
if ($programCountResult) {
    $activePrograms = (int) (($programCountResult->fetch_assoc()['total'] ?? 0));
}

$activeBatchId = null;
$batchSql = "
    SELECT upload_batch_id
    FROM tbl_placement_results
    ORDER BY created_at DESC
    LIMIT 1
";
$batchResult = $conn->query($batchSql);
if ($batchResult && $batchRow = $batchResult->fetch_assoc()) {
    $activeBatchId = trim((string) ($batchRow['upload_batch_id'] ?? ''));
    if ($activeBatchId === '') {
        $activeBatchId = null;
    }
}

$batchRecordCount = 0;
$expectedInterviewsTotal = 0;
$interviewedTotal = 0;
$scoredTotal = 0;
$campusesWithDemand = 0;
$campusesWithInterviewActivity = 0;

if ($activeBatchId !== null) {
    $batchCountStmt = $conn->prepare("
        SELECT COUNT(*) AS total
        FROM tbl_placement_results
        WHERE upload_batch_id = ?
    ");
    if ($batchCountStmt) {
        $batchCountStmt->bind_param('s', $activeBatchId);
        $batchCountStmt->execute();
        $batchCountResult = $batchCountStmt->get_result();
        if ($batchCountResult && $batchCountRow = $batchCountResult->fetch_assoc()) {
            $batchRecordCount = (int) ($batchCountRow['total'] ?? 0);
        }
        $batchCountStmt->close();
    }

    if (!empty($campusTokenMap)) {
        $expectedStmt = $conn->prepare("
            SELECT preferred_program, sat_score
            FROM tbl_placement_results
            WHERE upload_batch_id = ?
              AND preferred_program IS NOT NULL
              AND TRIM(preferred_program) <> ''
        ");
        if ($expectedStmt) {
            $expectedStmt->bind_param('s', $activeBatchId);
            $expectedStmt->execute();
            $expectedResult = $expectedStmt->get_result();

            while ($expectedRow = $expectedResult->fetch_assoc()) {
                $token = extract_monitoring_preferred_program_token((string) ($expectedRow['preferred_program'] ?? ''));
                if ($token === '' || !isset($campusTokenMap[$token])) {
                    continue;
                }

                if (!monitoring_dashboard_score_matches_cutoff(
                    $expectedRow['sat_score'] ?? null,
                    $globalSatCutoffActive,
                    $globalSatCutoffRanges,
                    $globalSatCutoffValue
                )) {
                    continue;
                }

                $campusId = (int) $campusTokenMap[$token];
                if (!isset($campusExpectedCounts[$campusId])) {
                    continue;
                }

                $campusExpectedCounts[$campusId]++;
            }

            $expectedStmt->close();
        }
    }

    $interviewStmt = $conn->prepare("
        SELECT
            col.campus_id,
            COUNT(*) AS interviewed_count,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count
        FROM tbl_student_interview si
        INNER JOIN tbl_placement_results pr
            ON pr.id = si.placement_result_id
        INNER JOIN tbl_program p
            ON p.program_id = si.first_choice
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        WHERE si.status = 'active'
          AND si.first_choice IS NOT NULL
          AND si.first_choice > 0
          AND pr.upload_batch_id = ?
        GROUP BY col.campus_id
    ");
    if ($interviewStmt) {
        $interviewStmt->bind_param('s', $activeBatchId);
        $interviewStmt->execute();
        $interviewResult = $interviewStmt->get_result();

        while ($interviewRow = $interviewResult->fetch_assoc()) {
            $campusId = (int) ($interviewRow['campus_id'] ?? 0);
            if ($campusId <= 0 || !isset($campusInterviewedCounts[$campusId])) {
                continue;
            }

            $campusInterviewedCounts[$campusId] = (int) ($interviewRow['interviewed_count'] ?? 0);
            $campusScoredCounts[$campusId] = (int) ($interviewRow['scored_count'] ?? 0);
        }

        $interviewStmt->close();
    }
}

$expectedSeriesData = [];
$interviewedSeriesData = [];
$scoredSeriesData = [];

foreach ($campuses as $campusRow) {
    $campusId = (int) ($campusRow['campus_id'] ?? 0);
    if ($campusId <= 0) {
        continue;
    }

    $expected = (int) ($campusExpectedCounts[$campusId] ?? 0);
    $interviewed = (int) ($campusInterviewedCounts[$campusId] ?? 0);
    $scored = (int) ($campusScoredCounts[$campusId] ?? 0);
    $pending = max(0, $interviewed - $scored);
    $coverageRate = $expected > 0 ? round(($interviewed / $expected) * 100, 1) : null;
    $scoringRate = $interviewed > 0 ? round(($scored / $interviewed) * 100, 1) : null;

    $expectedInterviewsTotal += $expected;
    $interviewedTotal += $interviewed;
    $scoredTotal += $scored;

    if ($expected > 0) {
        $campusesWithDemand++;
    }
    if ($interviewed > 0) {
        $campusesWithInterviewActivity++;
    }

    $expectedSeriesData[] = $expected;
    $interviewedSeriesData[] = $interviewed;
    $scoredSeriesData[] = $scored;

    $campusSnapshotRows[] = [
        'campus_code' => (string) ($campusChartLabels[$campusId] ?? ('Campus ' . $campusId)),
        'campus_name' => (string) ($campusChartFullLabels[$campusId] ?? ('Campus ' . $campusId)),
        'expected' => $expected,
        'interviewed' => $interviewed,
        'scored' => $scored,
        'pending' => $pending,
        'coverage_rate' => $coverageRate,
        'scoring_rate' => $scoringRate
    ];
}

usort($campusSnapshotRows, static function (array $left, array $right): int {
    $leftLoad = max((int) ($left['expected'] ?? 0), (int) ($left['interviewed'] ?? 0), (int) ($left['scored'] ?? 0));
    $rightLoad = max((int) ($right['expected'] ?? 0), (int) ($right['interviewed'] ?? 0), (int) ($right['scored'] ?? 0));

    if ($rightLoad !== $leftLoad) {
        return $rightLoad <=> $leftLoad;
    }

    $leftExpected = (int) ($left['expected'] ?? 0);
    $rightExpected = (int) ($right['expected'] ?? 0);
    if ($rightExpected !== $leftExpected) {
        return $rightExpected <=> $leftExpected;
    }

    return strcasecmp((string) ($left['campus_name'] ?? ''), (string) ($right['campus_name'] ?? ''));
});

$pendingScoresTotal = max(0, $interviewedTotal - $scoredTotal);
$coveragePercentTotal = $expectedInterviewsTotal > 0 ? round(($interviewedTotal / $expectedInterviewsTotal) * 100, 1) : null;
$scoringPercentTotal = $interviewedTotal > 0 ? round(($scoredTotal / $interviewedTotal) * 100, 1) : null;

$expectedCampusTrendSeries = [
    [
        'name' => 'Expected for Interview',
        'data' => $expectedSeriesData
    ]
];
$campusInterviewTrendSeries = [
    [
        'name' => 'Interviewed',
        'data' => $interviewedSeriesData
    ],
    [
        'name' => 'Scored',
        'data' => $scoredSeriesData
    ]
];

$expectedCampusRangeLabel = $globalSatCutoffActive
    ? 'Expected interviews respect the active global cutoff: ' . ($globalSatCutoffRangeText !== '' ? $globalSatCutoffRangeText : 'Configured cutoff')
    : 'Expected interviews use the active placement batch without a global SAT cutoff filter.';
$interviewCampusRangeLabel = $activeBatchId !== null
    ? 'Interview activity is grouped by the campus of the assigned first-choice program for students in the active placement batch.'
    : 'Interview activity will appear here once a placement-results batch is available.';
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
    <title>Monitoring Dashboard - Interview</title>

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
      .mn-hero-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background:
          radial-gradient(circle at top right, rgba(105, 108, 255, 0.14), transparent 28%),
          linear-gradient(135deg, #ffffff 0%, #f8fbff 100%);
      }

      .mn-hero-title {
        font-size: 1.35rem;
        font-weight: 700;
        color: #24364d;
      }

      .mn-hero-copy {
        color: #66758c;
        max-width: 48rem;
      }

      .mn-hero-pill-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.55rem;
      }

      .mn-hero-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        padding: 0.38rem 0.72rem;
        border-radius: 999px;
        border: 1px solid #dbe5f2;
        background: #fff;
        font-size: 0.76rem;
        font-weight: 600;
        color: #4a5b73;
      }

      .mn-action-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 1rem;
      }

      .mn-action-card {
        position: relative;
        display: flex;
        align-items: flex-start;
        gap: 0.9rem;
        padding: 1rem 1.05rem;
        border: 1px solid #e4eaf3;
        border-radius: 1rem;
        background: #fff;
        color: inherit;
        text-decoration: none;
        transition: all 0.2s ease;
      }

      .mn-action-card:hover {
        color: inherit;
        border-color: #cdd7e8;
        box-shadow: 0 10px 24px rgba(40, 57, 89, 0.08);
        transform: translateY(-1px);
      }

      .mn-action-card__icon {
        width: 44px;
        height: 44px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.25rem;
        flex: 0 0 44px;
      }

      .mn-action-card__title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .mn-action-card__copy {
        margin-top: 0.18rem;
        font-size: 0.84rem;
        line-height: 1.35;
        color: #6b7a90;
      }

      .mn-action-card__meta {
        margin-top: 0.5rem;
        font-size: 0.78rem;
        font-weight: 600;
        color: #56657b;
      }

      .mn-snapshot-card {
        height: 100%;
        border: 1px solid #e6ebf3;
        border-radius: 0.95rem;
        background: #fff;
        padding: 0.95rem 1rem;
      }

      .mn-snapshot-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .mn-snapshot-value {
        margin-top: 0.35rem;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.08;
        color: #2f3f59;
      }

      .mn-snapshot-hint {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.3;
        color: #7d8aa3;
      }

      .mn-chart-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
      }

      .mn-chart-title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .mn-chart-subtitle {
        color: #7d8aa3;
        font-size: 0.83rem;
      }

      .mn-campus-list {
        display: flex;
        flex-direction: column;
        gap: 0.9rem;
      }

      .mn-campus-item {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
        padding: 0.95rem 1rem;
      }

      .mn-campus-item__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
      }

      .mn-campus-item__code {
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #6a7b94;
      }

      .mn-campus-item__name {
        font-size: 0.98rem;
        font-weight: 700;
        color: #314155;
      }

      .mn-campus-item__status {
        font-size: 0.78rem;
        color: #7d8aa3;
      }

      .mn-campus-item__metrics {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 0.9rem;
      }

      .mn-campus-metric {
        border: 1px solid #e9eef5;
        border-radius: 0.8rem;
        background: #f9fbff;
        padding: 0.72rem 0.78rem;
      }

      .mn-campus-metric__label {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .mn-campus-metric__value {
        display: block;
        margin-top: 0.3rem;
        font-size: 1.08rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .mn-campus-progress {
        margin-top: 0.9rem;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
      }

      .mn-campus-progress__label {
        display: flex;
        justify-content: space-between;
        gap: 0.55rem;
        font-size: 0.76rem;
        color: #66758c;
        margin-bottom: 0.34rem;
      }

      .mn-campus-progress .progress {
        height: 0.48rem;
        background: #eef2f7;
      }

      .mn-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 1rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
      }

      @media (max-width: 991.98px) {
        .mn-campus-item__metrics,
        .mn-campus-progress {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 575.98px) {
        .mn-campus-item__header {
          flex-direction: column;
        }

        .mn-campus-item__metrics,
        .mn-campus-progress {
          grid-template-columns: 1fr;
        }
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
                <span class="text-muted fw-light">Monitoring /</span> Dashboard
              </h4>
              <p class="text-muted mb-4">
                Campus-level demand and interview throughput using the latest placement-results batch and current interview assignments.
              </p>

              <?php if ($activeBatchId !== null): ?>
                <div class="alert alert-info py-2 mb-3">
                  Active placement batch: <?= htmlspecialchars($activeBatchId); ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning py-2 mb-3">
                  No placement results batch found yet. Dashboard charts will stay at zero until a batch is uploaded.
                </div>
              <?php endif; ?>

              <?php if ($globalSatCutoffActive): ?>
                <div class="alert alert-primary py-2 mb-4">
                  Global cutoff is active<?= $globalSatCutoffRangeText !== '' ? ': ' . htmlspecialchars($globalSatCutoffRangeText) : '.'; ?>
                </div>
              <?php endif; ?>

              <div class="card mn-hero-card mb-4">
                <div class="card-body">
                  <div class="row g-4 align-items-center">
                    <div class="col-lg-8">
                      <div class="mn-hero-title">Monitoring command view</div>
                      <p class="mn-hero-copy mb-3">
                        Use this dashboard to compare campus demand from preferred programs against actual interview progress from assigned first-choice programs.
                      </p>
                      <div class="mn-hero-pill-list">
                        <span class="mn-hero-pill">
                          <i class="bx bx-buildings"></i>
                          <?= number_format($activeCampusCount); ?> active campuses
                        </span>
                        <span class="mn-hero-pill">
                          <i class="bx bx-briefcase-alt-2"></i>
                          <?= number_format($activePrograms); ?> active programs
                        </span>
                        <span class="mn-hero-pill">
                          <i class="bx bx-collection"></i>
                          <?= number_format($batchRecordCount); ?> batch records
                        </span>
                      </div>
                    </div>
                    <div class="col-lg-4">
                      <div class="mn-campus-item">
                        <div class="mn-campus-item__code">Dashboard Focus</div>
                        <div class="mn-campus-item__name">Campus demand vs. interview completion</div>
                        <div class="mn-campus-item__status mt-2">
                          Expected demand uses preferred-program campus mapping. Completion uses active interview records grouped by the assigned first-choice campus.
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="mn-action-grid mb-4">
                <a href="program_rankings.php" class="mn-action-card">
                  <span class="mn-action-card__icon bg-label-primary">
                    <i class="bx bx-list-ol"></i>
                  </span>
                  <div>
                    <div class="mn-action-card__title">Program Rankings</div>
                    <div class="mn-action-card__copy">
                      Review per-program cutoff status, interviewed totals, score completion, and ranking output.
                    </div>
                    <div class="mn-action-card__meta">
                      <?= number_format($activePrograms); ?> active programs under monitoring
                    </div>
                  </div>
                </a>

                <a href="students.php" class="mn-action-card">
                  <span class="mn-action-card__icon bg-label-info">
                    <i class="bx bx-group"></i>
                  </span>
                  <div>
                    <div class="mn-action-card__title">Students</div>
                    <div class="mn-action-card__copy">
                      Inspect placement results, preferred-program basis, and the score source used per student.
                    </div>
                    <div class="mn-action-card__meta">
                      <?= number_format($batchRecordCount); ?> records in the active placement batch
                    </div>
                  </div>
                </a>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Active Campuses</div>
                    <div class="mn-snapshot-value"><?= number_format($activeCampusCount); ?></div>
                    <span class="mn-snapshot-hint">Campuses in monitoring scope</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Active Programs</div>
                    <div class="mn-snapshot-value"><?= number_format($activePrograms); ?></div>
                    <span class="mn-snapshot-hint">Programs available for ranking and review</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Batch Records</div>
                    <div class="mn-snapshot-value"><?= number_format($batchRecordCount); ?></div>
                    <span class="mn-snapshot-hint">Latest placement-results upload</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Expected Interviews</div>
                    <div class="mn-snapshot-value"><?= number_format($expectedInterviewsTotal); ?></div>
                    <span class="mn-snapshot-hint"><?= number_format($campusesWithDemand); ?> campuses with mapped demand</span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Interviewed</div>
                    <div class="mn-snapshot-value"><?= number_format($interviewedTotal); ?></div>
                    <span class="mn-snapshot-hint">Coverage <?= htmlspecialchars(format_monitoring_percent($coveragePercentTotal)); ?></span>
                  </div>
                </div>
                <div class="col-xl-2 col-md-4 col-6">
                  <div class="mn-snapshot-card">
                    <div class="mn-snapshot-label">Scored</div>
                    <div class="mn-snapshot-value"><?= number_format($scoredTotal); ?></div>
                    <span class="mn-snapshot-hint">
                      Pending <?= number_format($pendingScoresTotal); ?> | Rate <?= htmlspecialchars(format_monitoring_percent($scoringPercentTotal)); ?>
                    </span>
                  </div>
                </div>
              </div>

              <div class="card mn-chart-card mb-4">
                <div class="card-body">
                  <div class="mn-chart-title">Expected Interviews by Campus</div>
                  <div class="mn-chart-subtitle mb-3"><?= htmlspecialchars($expectedCampusRangeLabel); ?></div>
                  <div id="monitoringExpectedCampusChart"></div>
                </div>
              </div>

              <div class="card mn-chart-card mb-4">
                <div class="card-body">
                  <div class="mn-chart-title">Interviewed and Scored by Assigned Campus</div>
                  <div class="mn-chart-subtitle mb-3"><?= htmlspecialchars($interviewCampusRangeLabel); ?></div>
                  <div id="monitoringCampusPipelineChart"></div>
                </div>
              </div>

              <div class="card mn-chart-card">
                <div class="card-body">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                      <div class="mn-chart-title">Campus Snapshot</div>
                      <div class="mn-chart-subtitle">
                        Compare expected demand, actual interviews, scoring completion, and pending load per campus.
                      </div>
                    </div>
                    <div class="small text-muted">
                      Campuses with interview activity: <?= number_format($campusesWithInterviewActivity); ?>
                    </div>
                  </div>

                  <?php if (empty($campusSnapshotRows)): ?>
                    <div class="mn-empty-card">No campus records found.</div>
                  <?php else: ?>
                    <div class="mn-campus-list">
                      <?php foreach ($campusSnapshotRows as $campus): ?>
                        <?php
                          $coverageRate = $campus['coverage_rate'];
                          $scoringRate = $campus['scoring_rate'];
                          $coverageWidth = $coverageRate !== null ? max(0, min(100, $coverageRate)) : 0;
                          $scoringWidth = $scoringRate !== null ? max(0, min(100, $scoringRate)) : 0;
                        ?>
                        <article class="mn-campus-item">
                          <div class="mn-campus-item__header">
                            <div>
                              <div class="mn-campus-item__code"><?= htmlspecialchars((string) $campus['campus_code']); ?></div>
                              <div class="mn-campus-item__name"><?= htmlspecialchars((string) $campus['campus_name']); ?></div>
                            </div>
                            <div class="mn-campus-item__status">
                              <?= (int) ($campus['pending'] ?? 0) > 0 ? number_format((int) $campus['pending']) . ' records still pending scoring' : 'No pending scoring backlog'; ?>
                            </div>
                          </div>

                          <div class="mn-campus-item__metrics">
                            <div class="mn-campus-metric">
                              <span class="mn-campus-metric__label">Expected</span>
                              <span class="mn-campus-metric__value"><?= number_format((int) ($campus['expected'] ?? 0)); ?></span>
                            </div>
                            <div class="mn-campus-metric">
                              <span class="mn-campus-metric__label">Interviewed</span>
                              <span class="mn-campus-metric__value"><?= number_format((int) ($campus['interviewed'] ?? 0)); ?></span>
                            </div>
                            <div class="mn-campus-metric">
                              <span class="mn-campus-metric__label">Scored</span>
                              <span class="mn-campus-metric__value"><?= number_format((int) ($campus['scored'] ?? 0)); ?></span>
                            </div>
                            <div class="mn-campus-metric">
                              <span class="mn-campus-metric__label">Pending</span>
                              <span class="mn-campus-metric__value"><?= number_format((int) ($campus['pending'] ?? 0)); ?></span>
                            </div>
                          </div>

                          <div class="mn-campus-progress">
                            <div>
                              <div class="mn-campus-progress__label">
                                <span>Interview coverage</span>
                                <span><?= htmlspecialchars(format_monitoring_percent($coverageRate)); ?></span>
                              </div>
                              <div class="progress">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?= htmlspecialchars((string) $coverageWidth); ?>%"></div>
                              </div>
                            </div>
                            <div>
                              <div class="mn-campus-progress__label">
                                <span>Scoring completion</span>
                                <span><?= htmlspecialchars(format_monitoring_percent($scoringRate)); ?></span>
                              </div>
                              <div class="progress">
                                <div class="progress-bar bg-success" role="progressbar" style="width: <?= htmlspecialchars((string) $scoringWidth); ?>%"></div>
                              </div>
                            </div>
                          </div>
                        </article>
                      <?php endforeach; ?>
                    </div>
                  <?php endif; ?>
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

        const campusCategories = <?= json_encode(array_values($campusChartLabels)); ?>;
        const campusTooltipLabels = <?= json_encode(array_values($campusChartFullLabels)); ?>;
        const expectedSeries = <?= json_encode($expectedCampusTrendSeries); ?>;
        const pipelineSeries = <?= json_encode($campusInterviewTrendSeries); ?>;

        function toNumber(value) {
          const numericValue = Number(value);
          return Number.isFinite(numericValue) ? numericValue : 0;
        }

        function buildCampusChartOptions(series, colors) {
          return {
            chart: {
              type: 'line',
              height: 320,
              toolbar: { show: false },
              zoom: { enabled: false }
            },
            series,
            colors,
            stroke: {
              curve: 'smooth',
              width: 3
            },
            markers: {
              size: 4,
              strokeWidth: 0
            },
            dataLabels: {
              enabled: false
            },
            grid: {
              borderColor: '#eef2f7',
              strokeDashArray: 4,
              padding: {
                left: 8,
                right: 8
              }
            },
            legend: {
              position: 'top',
              horizontalAlign: 'left'
            },
            xaxis: {
              categories: campusCategories,
              axisBorder: { show: false },
              axisTicks: { show: false },
              labels: {
                rotate: -20,
                style: {
                  colors: '#7d8aa3',
                  fontSize: '12px'
                }
              }
            },
            yaxis: {
              min: 0,
              forceNiceScale: true,
              labels: {
                formatter: function (value) {
                  return Math.round(toNumber(value)).toLocaleString();
                },
                style: {
                  colors: '#7d8aa3'
                }
              }
            },
            tooltip: {
              shared: true,
              intersect: false,
              x: {
                formatter: function (_, context) {
                  const index = Number(context && context.dataPointIndex ? context.dataPointIndex : 0);
                  return campusTooltipLabels[index] || '';
                }
              },
              y: {
                formatter: function (value) {
                  const count = Math.round(toNumber(value));
                  return `${count.toLocaleString()} record${count === 1 ? '' : 's'}`;
                }
              }
            },
            noData: {
              text: 'No campus data available.'
            }
          };
        }

        const expectedChartEl = document.querySelector('#monitoringExpectedCampusChart');
        if (expectedChartEl) {
          new ApexCharts(
            expectedChartEl,
            buildCampusChartOptions(expectedSeries, ['#696cff'])
          ).render();
        }

        const pipelineChartEl = document.querySelector('#monitoringCampusPipelineChart');
        if (pipelineChartEl) {
          new ApexCharts(
            pipelineChartEl,
            buildCampusChartOptions(pipelineSeries, ['#03c3ec', '#71dd37'])
          ).render();
        }
      });
    </script>
  </body>
</html>
