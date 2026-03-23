<?php
require_once __DIR__ . '/config/db.php';

function class_report_format_integer($value): string
{
    return number_format((int) $value);
}

function class_report_format_decimal($value): string
{
    if ($value === null || $value === '') {
        return 'N/A';
    }

    return number_format((float) $value, 2);
}

function class_report_format_percent(?float $value): string
{
    if ($value === null) {
        return 'N/A';
    }

    return number_format($value, 1) . '%';
}

function class_report_format_day(?string $value): string
{
    $value = trim((string) $value);
    if ($value === '') {
        return 'N/A';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $e) {
        return $value;
    }
}

function class_report_build_date_keys(?string $startDate, ?string $endDate): array
{
    $startDate = trim((string) $startDate);
    $endDate = trim((string) $endDate);
    if ($startDate === '' || $endDate === '') {
        return [];
    }

    try {
        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);
    } catch (Throwable $e) {
        return [];
    }

    if ($start > $end) {
        [$start, $end] = [$end, $start];
    }

    $keys = [];
    $cursor = $start;
    while ($cursor <= $end) {
        $keys[] = $cursor->format('Y-m-d');
        $cursor = $cursor->modify('+1 day');
    }

    return $keys;
}

function class_report_build_date_labels(array $dateKeys): array
{
    $labels = [];
    foreach ($dateKeys as $dateKey) {
        try {
            $labels[] = (new DateTimeImmutable((string) $dateKey))->format('M j');
        } catch (Throwable $e) {
            $labels[] = (string) $dateKey;
        }
    }

    return $labels;
}

$classificationSql = "CASE
    WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 'ETG'
    ELSE 'REGULAR'
END";
$etgClassSql = "COALESCE(NULLIF(TRIM(etg.class_desc), ''), 'No ETG Class')";

$summary = [
    'total_interviews' => 0,
    'scored_interviews' => 0,
    'regular_total' => 0,
    'etg_total' => 0,
    'with_etg_class_total' => 0,
    'distinct_groups' => 0,
    'first_day' => null,
    'last_day' => null,
];

$summarySql = "
    SELECT
        COUNT(*) AS total_interviews,
        SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_interviews,
        SUM(CASE WHEN {$classificationSql} = 'REGULAR' THEN 1 ELSE 0 END) AS regular_total,
        SUM(CASE WHEN {$classificationSql} = 'ETG' THEN 1 ELSE 0 END) AS etg_total,
        SUM(CASE WHEN {$etgClassSql} <> 'No ETG Class' THEN 1 ELSE 0 END) AS with_etg_class_total,
        COUNT(DISTINCT CONCAT({$classificationSql}, '|', {$etgClassSql})) AS distinct_groups,
        MIN(DATE(si.interview_datetime)) AS first_day,
        MAX(DATE(si.interview_datetime)) AS last_day
    FROM tbl_student_interview si
    LEFT JOIN tbl_etg_class etg
        ON etg.etgclassid = si.etg_class_id
    WHERE si.status = 'active'
";
$summaryResult = $conn->query($summarySql);
if ($summaryResult && $summaryRow = $summaryResult->fetch_assoc()) {
    $summary = array_merge($summary, $summaryRow);
    $summaryResult->free();
}

$groupRows = [];
$groupDateSeries = [];
$groupScoredSeries = [];

$groupSql = "
    SELECT
        {$classificationSql} AS classification_label,
        {$etgClassSql} AS etg_class_label,
        COUNT(*) AS total_interviews,
        SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_interviews,
        AVG(si.final_score) AS avg_final_score,
        MIN(si.final_score) AS min_final_score,
        MAX(si.final_score) AS max_final_score,
        MIN(DATE(si.interview_datetime)) AS first_day,
        MAX(DATE(si.interview_datetime)) AS last_day
    FROM tbl_student_interview si
    LEFT JOIN tbl_etg_class etg
        ON etg.etgclassid = si.etg_class_id
    WHERE si.status = 'active'
    GROUP BY 1, 2
    ORDER BY total_interviews DESC, classification_label ASC, etg_class_label ASC
";
$groupResult = $conn->query($groupSql);
if ($groupResult) {
    while ($row = $groupResult->fetch_assoc()) {
        $classificationLabel = (string) ($row['classification_label'] ?? 'REGULAR');
        $etgClassLabel = (string) ($row['etg_class_label'] ?? 'No ETG Class');
        $groupLabel = $classificationLabel . ' + ' . $etgClassLabel;
        $totalInterviews = (int) ($row['total_interviews'] ?? 0);
        $scoredInterviews = (int) ($row['scored_interviews'] ?? 0);
        $pendingInterviews = max(0, $totalInterviews - $scoredInterviews);
        $sharePct = $summary['total_interviews'] > 0
            ? ($totalInterviews / (float) $summary['total_interviews']) * 100
            : null;
        $scoreCoveragePct = $totalInterviews > 0
            ? ($scoredInterviews / (float) $totalInterviews) * 100
            : null;
        $groupKey = $classificationLabel . '|' . $etgClassLabel;

        $groupRows[] = [
            'group_key' => $groupKey,
            'group_label' => $groupLabel,
            'classification_label' => $classificationLabel,
            'etg_class_label' => $etgClassLabel,
            'total_interviews' => $totalInterviews,
            'scored_interviews' => $scoredInterviews,
            'pending_interviews' => $pendingInterviews,
            'share_pct' => $sharePct,
            'score_coverage_pct' => $scoreCoveragePct,
            'avg_final_score' => $row['avg_final_score'],
            'min_final_score' => $row['min_final_score'],
            'max_final_score' => $row['max_final_score'],
            'first_day' => $row['first_day'],
            'last_day' => $row['last_day'],
        ];
    }
    $groupResult->free();
}

$dateKeys = class_report_build_date_keys(
    isset($summary['first_day']) ? (string) $summary['first_day'] : null,
    isset($summary['last_day']) ? (string) $summary['last_day'] : null
);
$dateLabels = class_report_build_date_labels($dateKeys);
$dateIndexMap = array_flip($dateKeys);

foreach ($groupRows as $row) {
    $groupKey = (string) ($row['group_key'] ?? '');
    $groupDateSeries[$groupKey] = array_fill(0, count($dateKeys), 0);
    $groupScoredSeries[$groupKey] = array_fill(0, count($dateKeys), 0);
}

$trendSql = "
    SELECT
        DATE(si.interview_datetime) AS report_day,
        {$classificationSql} AS classification_label,
        {$etgClassSql} AS etg_class_label,
        COUNT(*) AS total_interviews,
        SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_interviews
    FROM tbl_student_interview si
    LEFT JOIN tbl_etg_class etg
        ON etg.etgclassid = si.etg_class_id
    WHERE si.status = 'active'
      AND si.interview_datetime IS NOT NULL
    GROUP BY report_day, 2, 3
    ORDER BY report_day ASC, total_interviews DESC
";
$trendResult = $conn->query($trendSql);
if ($trendResult) {
    while ($row = $trendResult->fetch_assoc()) {
        $reportDay = (string) ($row['report_day'] ?? '');
        if ($reportDay === '' || !isset($dateIndexMap[$reportDay])) {
            continue;
        }

        $groupKey = (string) ($row['classification_label'] ?? 'REGULAR') . '|' . (string) ($row['etg_class_label'] ?? 'No ETG Class');
        if (!isset($groupDateSeries[$groupKey], $groupScoredSeries[$groupKey])) {
            continue;
        }

        $seriesIndex = (int) $dateIndexMap[$reportDay];
        $groupDateSeries[$groupKey][$seriesIndex] = (int) ($row['total_interviews'] ?? 0);
        $groupScoredSeries[$groupKey][$seriesIndex] = (int) ($row['scored_interviews'] ?? 0);
    }
    $trendResult->free();
}

$chartSeries = [];
$chartScoredSeries = [];
foreach ($groupRows as $row) {
    $groupKey = (string) ($row['group_key'] ?? '');
    $chartSeries[] = [
        'name' => $row['group_label'],
        'data' => array_values($groupDateSeries[$groupKey] ?? []),
    ];
    $chartScoredSeries[] = [
        'name' => $row['group_label'],
        'data' => array_values($groupScoredSeries[$groupKey] ?? []),
    ];
}

$regularSummaryRows = array_values(array_filter($groupRows, static function (array $row): bool {
    return (string) ($row['classification_label'] ?? '') === 'REGULAR';
}));
$etgSummaryRows = array_values(array_filter($groupRows, static function (array $row): bool {
    return (string) ($row['classification_label'] ?? '') === 'ETG';
}));

$topGroup = $groupRows[0] ?? null;
$pendingTotal = max(0, (int) $summary['total_interviews'] - (int) $summary['scored_interviews']);
$refreshLabel = date('F j, Y g:i A');
$coverageLabel = 'No interview dates found';
if (!empty($dateKeys)) {
    $coverageLabel = class_report_format_day((string) $summary['first_day']) . ' to ' . class_report_format_day((string) $summary['last_day']);
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style" dir="ltr" data-theme="theme-default" data-assets-path="assets/">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
    <title>Classification + ETG Class Report</title>
    <meta
      name="description"
      content="Standalone report for Regular plus ETG Class and ETG plus ETG Class combinations."
    />

    <link rel="icon" type="image/x-icon" href="assets/img/favicon/favicon.ico" />
    <link rel="stylesheet" href="assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="assets/vendor/css/core.css" />
    <link rel="stylesheet" href="assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="assets/css/demo.css" />
    <link rel="stylesheet" href="assets/vendor/libs/apex-charts/apex-charts.css" />

    <style>
      :root {
        --cer-report-bg: radial-gradient(circle at top left, rgba(3, 195, 236, 0.18), transparent 34%),
          radial-gradient(circle at top right, rgba(113, 221, 55, 0.18), transparent 30%),
          linear-gradient(180deg, #f4f7fb 0%, #eef4fb 42%, #f8fbf5 100%);
        --cer-shell-width: 1480px;
        --cer-hero: linear-gradient(135deg, #0f3057 0%, #176b87 42%, #27a36c 100%);
        --cer-card-bg: rgba(255, 255, 255, 0.94);
        --cer-card-border: rgba(133, 146, 163, 0.18);
        --cer-shadow: 0 24px 60px rgba(15, 48, 87, 0.12);
        --cer-text-muted: #5d7289;
        --cer-regular: #1f6bff;
        --cer-etg: #1f9d72;
      }

      body {
        min-height: 100vh;
        background: var(--cer-report-bg);
        color: #22364d;
      }

      .cer-shell {
        max-width: var(--cer-shell-width);
      }

      .cer-hero {
        position: relative;
        overflow: hidden;
        border-radius: 28px;
        padding: 2rem;
        background: var(--cer-hero);
        color: #fff;
        box-shadow: 0 24px 64px rgba(15, 48, 87, 0.22);
      }

      .cer-hero::before {
        content: '';
        position: absolute;
        inset: auto -8% -42% auto;
        width: 340px;
        height: 340px;
        border-radius: 50%;
        background: radial-gradient(circle, rgba(255, 255, 255, 0.24), rgba(255, 255, 255, 0));
        pointer-events: none;
      }

      .cer-tag {
        display: inline-flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.45rem 0.9rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.14);
        border: 1px solid rgba(255, 255, 255, 0.18);
        font-size: 0.78rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .cer-title {
        margin: 1rem 0 0.75rem;
        font-size: clamp(2rem, 3.3vw, 3rem);
        line-height: 1.05;
        color: #fff;
      }

      .cer-subtitle {
        max-width: 920px;
        margin: 0;
        color: rgba(255, 255, 255, 0.82);
        font-size: 1rem;
      }

      .cer-hero-meta {
        display: grid;
        gap: 0.85rem;
        padding-top: 0.5rem;
      }

      .cer-hero-metric {
        padding: 0.95rem 1rem;
        border-radius: 18px;
        background: rgba(7, 22, 44, 0.22);
        border: 1px solid rgba(255, 255, 255, 0.12);
      }

      .cer-hero-metric-label {
        display: block;
        margin-bottom: 0.25rem;
        color: rgba(255, 255, 255, 0.68);
        font-size: 0.75rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .cer-hero-metric-value {
        font-size: 1rem;
        font-weight: 700;
      }

      .cer-summary-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 1rem;
        margin-top: 1.6rem;
      }

      .cer-summary-card {
        padding: 1rem 1.1rem;
        border-radius: 20px;
        background: rgba(255, 255, 255, 0.13);
        border: 1px solid rgba(255, 255, 255, 0.15);
        backdrop-filter: blur(10px);
      }

      .cer-summary-label {
        display: block;
        color: rgba(255, 255, 255, 0.72);
        font-size: 0.78rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      .cer-summary-value {
        display: block;
        margin-top: 0.5rem;
        font-size: 1.8rem;
        font-weight: 700;
        line-height: 1;
      }

      .cer-summary-help {
        display: block;
        margin-top: 0.45rem;
        color: rgba(255, 255, 255, 0.68);
        font-size: 0.86rem;
      }

      .cer-card {
        border: 1px solid var(--cer-card-border);
        border-radius: 26px;
        background: var(--cer-card-bg);
        box-shadow: var(--cer-shadow);
        backdrop-filter: blur(16px);
      }

      .cer-card-body {
        padding: 1.5rem;
      }

      .cer-section-heading {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: #19324a;
      }

      .cer-section-subtitle {
        margin: 0.35rem 0 0;
        color: var(--cer-text-muted);
      }

      .cer-chart-wrap {
        min-height: 480px;
      }

      .cer-chart-help {
        margin: 1rem 0 0;
        color: var(--cer-text-muted);
        font-size: 0.92rem;
      }

      .cer-class-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 84px;
        padding: 0.35rem 0.75rem;
        border-radius: 999px;
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
      }

      .cer-class-badge--regular {
        background: rgba(31, 107, 255, 0.12);
        color: var(--cer-regular);
      }

      .cer-class-badge--etg {
        background: rgba(31, 157, 114, 0.14);
        color: var(--cer-etg);
      }

      .cer-simple-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 1rem;
        margin-bottom: 1.25rem;
      }

      .cer-mini-card {
        border: 1px solid rgba(133, 146, 163, 0.18);
        border-radius: 22px;
        background: rgba(248, 250, 252, 0.88);
        padding: 1rem;
      }

      .cer-mini-card--regular {
        background: linear-gradient(180deg, rgba(31, 107, 255, 0.05), rgba(255, 255, 255, 0.92));
      }

      .cer-mini-card--etg {
        background: linear-gradient(180deg, rgba(31, 157, 114, 0.07), rgba(255, 255, 255, 0.92));
      }

      .cer-mini-title {
        margin: 0;
        font-size: 1.05rem;
        font-weight: 700;
        color: #19324a;
      }

      .cer-mini-help {
        margin: 0.35rem 0 0.9rem;
        color: var(--cer-text-muted);
        font-size: 0.9rem;
      }

      .cer-mini-total {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 90px;
        padding: 0.38rem 0.8rem;
        border-radius: 999px;
        background: rgba(25, 50, 74, 0.08);
        color: #19324a;
        font-size: 0.8rem;
        font-weight: 700;
      }

      .cer-table {
        margin-bottom: 0;
      }

      .cer-table thead th {
        border-bottom: 1px solid rgba(133, 146, 163, 0.22);
        color: #50667d;
        font-size: 0.78rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        white-space: nowrap;
      }

      .cer-table tbody td {
        border-color: rgba(133, 146, 163, 0.14);
        vertical-align: middle;
      }

      .cer-combo-name {
        font-weight: 700;
        color: #18324a;
      }

      .cer-combo-meta {
        margin-top: 0.3rem;
        color: var(--cer-text-muted);
        font-size: 0.88rem;
      }

      .cer-empty {
        padding: 1rem 0;
        color: var(--cer-text-muted);
        text-align: center;
      }

      .cer-details {
        margin-top: 0.5rem;
        border-top: 1px solid rgba(133, 146, 163, 0.18);
        padding-top: 1rem;
      }

      .cer-details summary {
        cursor: pointer;
        list-style: none;
        font-weight: 700;
        color: #19324a;
      }

      .cer-details summary::-webkit-details-marker {
        display: none;
      }

      .cer-details-copy {
        margin: 0.45rem 0 1rem;
        color: var(--cer-text-muted);
        font-size: 0.92rem;
      }

      @media (max-width: 991.98px) {
        .cer-hero {
          padding: 1.5rem;
        }

        .cer-card-body {
          padding: 1.25rem;
        }

        .cer-chart-wrap {
          min-height: 400px;
        }

        .cer-simple-grid {
          grid-template-columns: 1fr;
        }
      }
    </style>
  </head>
  <body>
    <main class="container-xxl py-4 py-lg-5 cer-shell">
      <section class="cer-hero">
        <div class="row g-4 align-items-start">
          <div class="col-xl-8">
            <span class="cer-tag"><i class="bx bx-line-chart"></i> Public Report</span>
            <h1 class="cer-title">Regular + ETG Class / ETG + ETG Class Report</h1>
            <p class="cer-subtitle">
              Standalone view for all active interview records grouped by classification and ETG class.
              This page is separate from the secured dashboards and can be opened directly from its link without a login session.
            </p>
          </div>
          <div class="col-xl-4">
            <div class="cer-hero-meta">
              <div class="cer-hero-metric">
                <span class="cer-hero-metric-label">Coverage</span>
                <span class="cer-hero-metric-value"><?= htmlspecialchars($coverageLabel); ?></span>
              </div>
              <div class="cer-hero-metric">
                <span class="cer-hero-metric-label">Last Refreshed</span>
                <span class="cer-hero-metric-value"><?= htmlspecialchars($refreshLabel); ?></span>
              </div>
              <div class="cer-hero-metric">
                <span class="cer-hero-metric-label">Largest Combination</span>
                <span class="cer-hero-metric-value">
                  <?= htmlspecialchars($topGroup['group_label'] ?? 'N/A'); ?>
                  <?php if ($topGroup): ?>
                    (<?= class_report_format_integer($topGroup['total_interviews'] ?? 0); ?>)
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </div>
        </div>

        <div class="cer-summary-grid">
          <div class="cer-summary-card">
            <span class="cer-summary-label">Active Interviews</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['total_interviews'] ?? 0); ?></span>
            <span class="cer-summary-help">All active records included in this public page.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">Scored</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['scored_interviews'] ?? 0); ?></span>
            <span class="cer-summary-help"><?= class_report_format_percent(($summary['total_interviews'] ?? 0) > 0 ? ((int) $summary['scored_interviews'] / (float) $summary['total_interviews']) * 100 : null); ?> coverage across all active interviews.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">Pending Score</span>
            <span class="cer-summary-value"><?= class_report_format_integer($pendingTotal); ?></span>
            <span class="cer-summary-help">Active interviews without a final score yet.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">Regular</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['regular_total'] ?? 0); ?></span>
            <span class="cer-summary-help">Rows normalized to the REGULAR classification.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">ETG</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['etg_total'] ?? 0); ?></span>
            <span class="cer-summary-help">Rows normalized to the ETG classification.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">With ETG Class</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['with_etg_class_total'] ?? 0); ?></span>
            <span class="cer-summary-help">Regular and ETG records that carry an ETG class value.</span>
          </div>
          <div class="cer-summary-card">
            <span class="cer-summary-label">Distinct Combinations</span>
            <span class="cer-summary-value"><?= class_report_format_integer($summary['distinct_groups'] ?? 0); ?></span>
            <span class="cer-summary-help">Unique classification plus ETG class combinations.</span>
          </div>
        </div>
      </section>

      <section class="cer-card mt-4">
        <div class="cer-card-body">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3 mb-3">
            <div>
              <h2 class="cer-section-heading">Multi-Line Class Trend</h2>
              <p class="cer-section-subtitle">
                Daily interview volume for every Regular + ETG Class and ETG + ETG Class combination.
              </p>
            </div>
            <div class="text-muted small">
              Tooltip shows both interviewed and scored counts for each point.
            </div>
          </div>

          <div id="classificationEtgTrendChart" class="cer-chart-wrap"></div>
          <p class="cer-chart-help">
            Each line represents one class combination. Click a legend item to isolate or hide combinations while viewing the public report.
          </p>
        </div>
      </section>

      <section class="cer-card mt-4">
        <div class="cer-card-body">
          <div class="d-flex flex-column flex-lg-row justify-content-between align-items-start gap-3 mb-3">
            <div>
              <h2 class="cer-section-heading">Combination Summary</h2>
              <p class="cer-section-subtitle">
                Easy-to-check counts separated into Regular and ETG tables.
              </p>
            </div>
            <div class="text-muted small">
              Each row shows one ETG class and its count.
            </div>
          </div>

          <div class="cer-simple-grid">
            <div class="cer-mini-card cer-mini-card--regular">
              <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                <div>
                  <h3 class="cer-mini-title">Regular</h3>
                  <p class="cer-mini-help">Regular rows listed by ETG class.</p>
                </div>
                <span class="cer-mini-total"><?= class_report_format_integer($summary['regular_total'] ?? 0); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-sm cer-table align-middle">
                  <thead>
                    <tr>
                      <th>ETG Class</th>
                      <th>Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($regularSummaryRows)): ?>
                      <tr>
                        <td colspan="2" class="cer-empty">No Regular rows found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($regularSummaryRows as $row): ?>
                        <tr>
                          <td><?= htmlspecialchars((string) ($row['etg_class_label'] ?? '')); ?></td>
                          <td><?= class_report_format_integer($row['total_interviews'] ?? 0); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>

            <div class="cer-mini-card cer-mini-card--etg">
              <div class="d-flex justify-content-between align-items-start gap-3 mb-2">
                <div>
                  <h3 class="cer-mini-title">ETG</h3>
                  <p class="cer-mini-help">ETG rows listed by ETG class.</p>
                </div>
                <span class="cer-mini-total"><?= class_report_format_integer($summary['etg_total'] ?? 0); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table table-sm cer-table align-middle">
                  <thead>
                    <tr>
                      <th>ETG Class</th>
                      <th>Count</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($etgSummaryRows)): ?>
                      <tr>
                        <td colspan="2" class="cer-empty">No ETG rows found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($etgSummaryRows as $row): ?>
                        <tr>
                          <td><?= htmlspecialchars((string) ($row['etg_class_label'] ?? '')); ?></td>
                          <td><?= class_report_format_integer($row['total_interviews'] ?? 0); ?></td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <details class="cer-details">
            <summary>Show detailed combination breakdown</summary>
            <p class="cer-details-copy">
              Optional detailed table for totals, scored and pending counts, score coverage, and score range.
            </p>
            <div class="table-responsive">
              <table class="table table-hover cer-table align-middle">
                <thead>
                  <tr>
                    <th>Combination</th>
                    <th>Class</th>
                    <th>ETG Class</th>
                    <th>Total</th>
                    <th>Scored</th>
                    <th>Pending</th>
                    <th>Share</th>
                    <th>Coverage</th>
                    <th>Avg Score</th>
                    <th>Score Range</th>
                    <th>First Day</th>
                    <th>Last Day</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($groupRows)): ?>
                    <tr>
                      <td colspan="12" class="cer-empty">No active interview records found for this report.</td>
                    </tr>
                  <?php else: ?>
                    <?php foreach ($groupRows as $row): ?>
                      <?php $isEtg = ((string) ($row['classification_label'] ?? '') === 'ETG'); ?>
                      <tr>
                        <td>
                          <div class="cer-combo-name"><?= htmlspecialchars((string) ($row['group_label'] ?? '')); ?></div>
                          <div class="cer-combo-meta">
                            <?= class_report_format_integer($row['total_interviews'] ?? 0); ?> row(s)
                            across <?= htmlspecialchars($coverageLabel); ?>
                          </div>
                        </td>
                        <td>
                          <span class="cer-class-badge <?= $isEtg ? 'cer-class-badge--etg' : 'cer-class-badge--regular'; ?>">
                            <?= htmlspecialchars((string) ($row['classification_label'] ?? '')); ?>
                          </span>
                        </td>
                        <td><?= htmlspecialchars((string) ($row['etg_class_label'] ?? '')); ?></td>
                        <td><?= class_report_format_integer($row['total_interviews'] ?? 0); ?></td>
                        <td><?= class_report_format_integer($row['scored_interviews'] ?? 0); ?></td>
                        <td><?= class_report_format_integer($row['pending_interviews'] ?? 0); ?></td>
                        <td><?= class_report_format_percent(isset($row['share_pct']) ? (float) $row['share_pct'] : null); ?></td>
                        <td><?= class_report_format_percent(isset($row['score_coverage_pct']) ? (float) $row['score_coverage_pct'] : null); ?></td>
                        <td><?= class_report_format_decimal($row['avg_final_score'] ?? null); ?></td>
                        <td>
                          <?php
                          $minScore = ($row['min_final_score'] ?? null);
                          $maxScore = ($row['max_final_score'] ?? null);
                          echo htmlspecialchars(
                              ($minScore !== null && $minScore !== '' ? number_format((float) $minScore, 2) : 'N/A')
                              . ' to '
                              . ($maxScore !== null && $maxScore !== '' ? number_format((float) $maxScore, 2) : 'N/A')
                          );
                          ?>
                        </td>
                        <td><?= htmlspecialchars(class_report_format_day(isset($row['first_day']) ? (string) $row['first_day'] : null)); ?></td>
                        <td><?= htmlspecialchars(class_report_format_day(isset($row['last_day']) ? (string) $row['last_day'] : null)); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </details>
        </div>
      </section>
    </main>

    <script src="assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const chartEl = document.getElementById('classificationEtgTrendChart');
        const chartCategories = <?= json_encode($dateLabels, JSON_UNESCAPED_SLASHES); ?>;
        const interviewedSeries = <?= json_encode($chartSeries, JSON_UNESCAPED_SLASHES); ?>;
        const scoredSeries = <?= json_encode($chartScoredSeries, JSON_UNESCAPED_SLASHES); ?>;

        function toNumber(value) {
          const parsed = Number(value);
          return Number.isFinite(parsed) ? parsed : 0;
        }

        function buildPalette(size) {
          const base = [
            '#1f6bff',
            '#1f9d72',
            '#ff8f1f',
            '#7c3aed',
            '#ef4444',
            '#0ea5e9',
            '#14b8a6',
            '#f59e0b',
            '#6366f1',
            '#ec4899'
          ];

          if (size <= base.length) {
            return base.slice(0, size);
          }

          const palette = base.slice();
          for (let i = base.length; i < size; i++) {
            const hue = (i * 43) % 360;
            const lightness = i % 2 === 0 ? 46 : 54;
            palette.push(`hsl(${hue}, 68%, ${lightness}%)`);
          }

          return palette;
        }

        function getScoredValue(seriesIndex, dataPointIndex) {
          if (!Array.isArray(scoredSeries) || !Array.isArray(scoredSeries[seriesIndex]?.data)) {
            return 0;
          }

          return toNumber(scoredSeries[seriesIndex].data[dataPointIndex]);
        }

        if (!chartEl) {
          return;
        }

        if (typeof ApexCharts === 'undefined') {
          chartEl.innerHTML = '<div class="text-muted small py-3">Chart library is not available.</div>';
          return;
        }

        if (!Array.isArray(chartCategories) || chartCategories.length === 0 || !Array.isArray(interviewedSeries) || interviewedSeries.length === 0) {
          chartEl.innerHTML = '<div class="text-muted small py-3">No class combination trend data available.</div>';
          return;
        }

        const chartOptions = {
          chart: {
            type: 'line',
            height: 480,
            toolbar: {
              show: true,
              tools: {
                download: true,
                selection: false,
                zoom: false,
                zoomin: false,
                zoomout: false,
                pan: false,
                reset: false
              }
            },
            zoom: {
              enabled: false
            }
          },
          series: interviewedSeries,
          colors: buildPalette(interviewedSeries.length),
          stroke: {
            curve: 'smooth',
            width: 3
          },
          markers: {
            size: 3.5,
            strokeWidth: 0,
            hover: {
              sizeOffset: 2
            }
          },
          dataLabels: {
            enabled: false
          },
          xaxis: {
            categories: chartCategories,
            labels: {
              rotate: -35,
              hideOverlappingLabels: true,
              style: {
                colors: '#61758a',
                fontSize: '12px'
              }
            },
            axisBorder: {
              show: false
            },
            axisTicks: {
              show: false
            }
          },
          yaxis: {
            min: 0,
            forceNiceScale: true,
            title: {
              text: 'Interviewed Students'
            },
            labels: {
              formatter: function (value) {
                return Math.round(toNumber(value)).toLocaleString();
              },
              style: {
                colors: '#61758a'
              }
            }
          },
          legend: {
            position: 'top',
            horizontalAlign: 'left'
          },
          grid: {
            borderColor: '#dfe7f1',
            strokeDashArray: 5,
            padding: {
              left: 8,
              right: 8
            }
          },
          tooltip: {
            shared: true,
            intersect: false,
            y: {
              formatter: function (value, context) {
                const interviewedValue = Math.round(toNumber(value));
                const scoredValue = Math.round(getScoredValue(context.seriesIndex, context.dataPointIndex));
                return `${interviewedValue.toLocaleString()} interviewed | ${scoredValue.toLocaleString()} scored`;
              }
            }
          },
          noData: {
            text: 'No class combination trend data available.'
          }
        };

        const trendChart = new ApexCharts(chartEl, chartOptions);
        trendChart.render();
      });
    </script>
  </body>
</html>
