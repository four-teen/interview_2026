<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'president')) {
    header('Location: ../index.php');
    exit;
}

$presidentHeaderTitle = 'President Dashboard - Institutional Overview';

function president_table_column_exists(mysqli $conn, string $table, string $column): bool
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

function president_normalize_token(string $value): string
{
    return strtoupper((string) preg_replace('/[^A-Z0-9]/', '', trim($value)));
}

function president_extract_preferred_program_token(string $preferredProgram): string
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

    return president_normalize_token($firstWord);
}

function president_score_matches_cutoff($score, bool $cutoffActive, array $ranges, ?int $fallbackCutoff): bool
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

function president_format_percent(?float $value): string
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
$hasEndorsementCapacityColumn = president_table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_capacity');
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
if ($campusResult) {
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
    ORDER BY c.campus_name ASC, p.program_name ASC, p.major ASC
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
            'total_count' => $scoredCount + $unscoredCount
        ];
    }
}

$activeCampusCount = count($campuses);
$activePrograms = 0;
$programCountResult = $conn->query("SELECT COUNT(*) AS total FROM tbl_program WHERE status = 'active'");
if ($programCountResult) {
    $activePrograms = (int) ($programCountResult->fetch_assoc()['total'] ?? 0);
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
}

$campusTokenMap = [];
$campusExpectedCounts = [];
$campusExpectedAllCounts = [];
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
    $campusCodeToken = president_normalize_token($campusCode);
    $campusNameToken = president_normalize_token($campusFirstWord);

    if ($campusCodeToken !== '') {
        $campusTokenMap[$campusCodeToken] = $campusId;
    }
    if ($campusNameToken !== '') {
        $campusTokenMap[$campusNameToken] = $campusId;
    }

    $campusExpectedCounts[$campusId] = 0;
    $campusExpectedAllCounts[$campusId] = 0;
    $campusInterviewedCounts[$campusId] = 0;
    $campusScoredCounts[$campusId] = 0;
    $campusChartLabels[$campusId] = $campusCode !== '' ? strtoupper($campusCode) : ($campusFirstWord !== '' ? $campusFirstWord : ('Campus ' . $campusId));
    $campusChartFullLabels[$campusId] = $campusName !== '' ? $campusName : ('Campus ' . $campusId);
}

$expectedInterviewsTotal = 0;
$interviewedTotal = 0;
$scoredTotal = 0;
$campusesWithDemand = 0;
$campusesWithInterviewActivity = 0;

if ($activeBatchId !== null) {
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
                $token = president_extract_preferred_program_token((string) ($expectedRow['preferred_program'] ?? ''));
                if ($token === '' || !isset($campusTokenMap[$token])) {
                    continue;
                }

                $campusId = (int) $campusTokenMap[$token];
                if (!isset($campusExpectedCounts[$campusId]) || !isset($campusExpectedAllCounts[$campusId])) {
                    continue;
                }

                $campusExpectedAllCounts[$campusId]++;

                if (!president_score_matches_cutoff(
                    $expectedRow['sat_score'] ?? null,
                    $globalSatCutoffActive,
                    $globalSatCutoffRanges,
                    $globalSatCutoffValue
                )) {
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
$expectedAllSeriesData = [];
$interviewedSeriesData = [];
$scoredSeriesData = [];
foreach ($campuses as $campusRow) {
    $campusId = (int) ($campusRow['campus_id'] ?? 0);
    if ($campusId <= 0) {
        continue;
    }

    $expected = (int) ($campusExpectedCounts[$campusId] ?? 0);
    $expectedAll = (int) ($campusExpectedAllCounts[$campusId] ?? 0);
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
    $expectedAllSeriesData[] = $expectedAll;
    $interviewedSeriesData[] = $interviewed;
    $scoredSeriesData[] = $scored;
    $campusSnapshotRows[] = [
        'campus_id' => $campusId,
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
$campusCombinedSeries = [
    ['name' => 'Expected for Interview', 'data' => $expectedSeriesData],
    ['name' => 'Interviewed', 'data' => $interviewedSeriesData],
    ['name' => 'Scored', 'data' => $scoredSeriesData],
    ['name' => 'Expected Without Cutoff', 'data' => $expectedAllSeriesData]
];
$combinedCampusRangeLabel = $globalSatCutoffActive
    ? 'Expected for Interview respects the active global cutoff: ' . ($globalSatCutoffRangeText !== '' ? $globalSatCutoffRangeText : 'Configured cutoff') . '. Expected Without Cutoff shows mapped demand from the active batch before cutoff filtering. Interview activity is grouped by assigned first-choice campus.'
    : 'Expected for Interview and Expected Without Cutoff both use the active placement batch because no global SAT cutoff is active. Interview activity is grouped by assigned first-choice campus.';

$leadingCampus = $campusSnapshotRows[0] ?? null;
$leadingCampusName = $leadingCampus ? (string) ($leadingCampus['campus_name'] ?? 'Campus') : 'No campus data yet';
$leadingCampusLoad = $leadingCampus ? max(
    (int) ($leadingCampus['expected'] ?? 0),
    (int) ($leadingCampus['interviewed'] ?? 0),
    (int) ($leadingCampus['scored'] ?? 0)
) : 0;
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
    <title>President Dashboard - interview</title>
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
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .pd-hero-card,
      .pd-chart-card,
      .pd-panel-card,
      .pd-snapshot-card,
      .pd-campus-card,
      .pd-campus-item,
      .pd-campus-program-item {
        border: 1px solid #e6ebf3;
        background: #fff;
      }

      .pd-hero-card {
        border-radius: 1.1rem;
        background: linear-gradient(135deg, #fffdf8 0%, #f4f7ff 56%, #edf8ff 100%);
        overflow: hidden;
      }

      .pd-hero-copy {
        color: #5e6e86;
        max-width: 42rem;
      }

      .pd-hero-pill-list {
        display: flex;
        flex-wrap: wrap;
        gap: 0.6rem;
      }

      .pd-hero-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.4rem;
        padding: 0.45rem 0.72rem;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.86);
        border: 1px solid #e3eaf3;
        color: #334155;
        font-size: 0.8rem;
        font-weight: 600;
      }

      .pd-focus-card {
        border-radius: 1rem;
        padding: 1rem;
        background: rgba(255, 255, 255, 0.88);
        border: 1px solid #e4e9f3;
      }

      .pd-focus-card__label {
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #7d8aa3;
      }

      .pd-focus-card__value {
        margin-top: 0.35rem;
        font-size: 1.2rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .pd-focus-card__meta {
        margin-top: 0.35rem;
        color: #64748b;
        font-size: 0.82rem;
        line-height: 1.4;
      }

      .pd-snapshot-card {
        height: 100%;
        border-radius: 0.95rem;
        padding: 0.95rem 1rem;
      }

      .pd-snapshot-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .pd-snapshot-value {
        margin-top: 0.35rem;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.08;
        color: #2f3f59;
      }

      .pd-snapshot-hint {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.3;
        color: #7d8aa3;
      }

      .pd-chart-card,
      .pd-panel-card {
        border-radius: 1rem;
      }

      .pd-chart-title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .pd-chart-subtitle {
        color: #7d8aa3;
        font-size: 0.83rem;
      }

      .pd-overview-list {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.75rem;
      }

      .pd-overview-item {
        border: 1px solid #edf2f7;
        border-radius: 0.9rem;
        background: #f9fbff;
        padding: 0.8rem 0.88rem;
      }

      .pd-overview-item__label {
        display: block;
        font-size: 0.7rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #8391a7;
      }

      .pd-overview-item__value {
        display: block;
        margin-top: 0.28rem;
        font-size: 1.1rem;
        font-weight: 700;
        color: #314155;
      }

      .pd-overview-item__hint {
        display: block;
        margin-top: 0.22rem;
        font-size: 0.76rem;
        color: #7d8aa3;
      }

      .pd-campus-card-list,
      .pd-campus-list {
        display: flex;
        flex-direction: column;
        gap: 0.72rem;
      }

      .pd-campus-card {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 0.8rem;
        padding: 0.78rem 0.82rem;
        border-radius: 0.85rem;
        transition: all 0.2s ease;
        cursor: pointer;
      }

      .pd-campus-card:hover {
        border-color: #c8d0e0;
        box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
        transform: translateY(-1px);
      }

      .pd-campus-card:focus-visible {
        outline: 0;
        border-color: #696cff;
        box-shadow: 0 0 0 0.2rem rgba(105, 108, 255, 0.18);
      }

      .pd-campus-card__main {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        min-width: 0;
      }

      .pd-campus-card__icon {
        width: 38px;
        height: 38px;
        border-radius: 12px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 38px;
        font-size: 1.05rem;
      }

      .pd-campus-card__code {
        font-size: 0.73rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #6c7d93;
        font-weight: 700;
      }

      .pd-campus-card__name {
        font-size: 0.95rem;
        color: #364152;
        font-weight: 600;
        line-height: 1.18rem;
      }

      .pd-campus-card__sub {
        display: block;
        margin-top: 0.12rem;
        font-size: 0.73rem;
        color: #8391a7;
      }

      .pd-campus-card__total {
        text-align: right;
      }

      .pd-campus-card__total-number {
        font-size: 1.2rem;
        font-weight: 700;
        color: #2f3f59;
        line-height: 1.05;
      }

      .pd-campus-card__total-label {
        display: block;
        margin-top: 0.12rem;
        font-size: 0.72rem;
        color: #8391a7;
      }

      .pd-campus-item {
        border-radius: 1rem;
        padding: 0.95rem 1rem;
      }

      .pd-campus-item__header {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 0.75rem;
      }

      .pd-campus-item__code {
        font-size: 0.74rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #6a7b94;
      }

      .pd-campus-item__name {
        font-size: 0.98rem;
        font-weight: 700;
        color: #314155;
      }

      .pd-campus-item__status {
        font-size: 0.78rem;
        color: #7d8aa3;
      }

      .pd-campus-item__metrics {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 0.75rem;
        margin-top: 0.9rem;
      }

      .pd-campus-metric {
        border: 1px solid #e9eef5;
        border-radius: 0.8rem;
        background: #f9fbff;
        padding: 0.72rem 0.78rem;
      }

      .pd-campus-metric__label {
        display: block;
        font-size: 0.68rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .pd-campus-metric__value {
        display: block;
        margin-top: 0.3rem;
        font-size: 1.08rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .pd-campus-progress {
        margin-top: 0.9rem;
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 0.8rem;
      }

      .pd-campus-progress__label {
        display: flex;
        justify-content: space-between;
        gap: 0.55rem;
        font-size: 0.76rem;
        color: #66758c;
        margin-bottom: 0.34rem;
      }

      .pd-campus-progress .progress {
        height: 0.48rem;
        background: #eef2f7;
      }

      .pd-campus-status-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .pd-campus-status-chip {
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

      .pd-campus-program-item {
        border-radius: 0.75rem;
        padding: 0.62rem 0.75rem;
        margin-bottom: 0.55rem;
      }

      .pd-campus-program-item:last-child {
        margin-bottom: 0;
      }

      .pd-campus-program-title {
        font-size: 0.88rem;
        font-weight: 600;
        color: #364152;
        margin: 0;
      }

      .pd-campus-program-metrics {
        display: flex;
        flex-wrap: wrap;
        gap: 0.4rem;
      }

      .pd-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 1rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
      }

      @media (max-width: 991.98px) {
        .pd-overview-list,
        .pd-campus-item__metrics,
        .pd-campus-progress {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 575.98px) {
        .pd-overview-list,
        .pd-campus-item__metrics,
        .pd-campus-progress {
          grid-template-columns: 1fr;
        }

        .pd-campus-item__header {
          flex-direction: column;
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
                <span class="text-muted fw-light">President /</span> Dashboard
              </h4>
              <p class="text-muted mb-4">
                Institution-wide placement and interview visibility across campuses, programs, and active interview activity.
              </p>

              <div class="row g-4">
                <div class="col-xl-8">
                  <div class="card pd-hero-card mb-4">
                    <div class="card-body">
                      <div class="row g-4 align-items-center">
                        <div class="col-lg-8">
                          <h4 class="card-title text-primary mb-2">President dashboard</h4>
                          <p class="pd-hero-copy mb-3">
                            Review campus demand, interview throughput, and score completion from one read-only executive view.
                          </p>
                          <div class="pd-hero-pill-list">
                            <span class="pd-hero-pill">
                              <i class="bx bx-buildings"></i>
                              <?= number_format($activeCampusCount); ?> active campuses
                            </span>
                            <span class="pd-hero-pill">
                              <i class="bx bx-briefcase-alt-2"></i>
                              <?= number_format($activePrograms); ?> active programs
                            </span>
                            <span class="pd-hero-pill">
                              <i class="bx bx-collection"></i>
                              <?= number_format($batchRecordCount); ?> batch records
                            </span>
                            <span class="pd-hero-pill">
                              <i class="bx bx-timer"></i>
                              Pending scores: <?= number_format($pendingScoresTotal); ?>
                            </span>
                          </div>
                        </div>
                        <div class="col-lg-4">
                          <div class="pd-focus-card">
                            <div class="pd-focus-card__label">Highest Current Load</div>
                            <div class="pd-focus-card__value"><?= htmlspecialchars($leadingCampusName); ?></div>
                            <div class="pd-focus-card__meta">
                              Peak combined load: <?= number_format($leadingCampusLoad); ?><br />
                              Coverage <?= htmlspecialchars(president_format_percent($coveragePercentTotal)); ?> |
                              Scoring <?= htmlspecialchars(president_format_percent($scoringPercentTotal)); ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="row g-3 mb-4">
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Active Campuses</div>
                        <div class="pd-snapshot-value"><?= number_format($activeCampusCount); ?></div>
                        <span class="pd-snapshot-hint">Campuses contributing to the current dashboard scope</span>
                      </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Active Programs</div>
                        <div class="pd-snapshot-value"><?= number_format($activePrograms); ?></div>
                        <span class="pd-snapshot-hint">Programs available for interview assignment and ranking</span>
                      </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Batch Records</div>
                        <div class="pd-snapshot-value"><?= number_format($batchRecordCount); ?></div>
                        <span class="pd-snapshot-hint">Records from the latest placement-results upload</span>
                      </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Expected Interviews</div>
                        <div class="pd-snapshot-value"><?= number_format($expectedInterviewsTotal); ?></div>
                        <span class="pd-snapshot-hint"><?= number_format($campusesWithDemand); ?> campuses with mapped demand</span>
                      </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Interviewed</div>
                        <div class="pd-snapshot-value"><?= number_format($interviewedTotal); ?></div>
                        <span class="pd-snapshot-hint">Coverage <?= htmlspecialchars(president_format_percent($coveragePercentTotal)); ?></span>
                      </div>
                    </div>
                    <div class="col-xl-4 col-md-6">
                      <div class="pd-snapshot-card">
                        <div class="pd-snapshot-label">Scored</div>
                        <div class="pd-snapshot-value"><?= number_format($scoredTotal); ?></div>
                        <span class="pd-snapshot-hint">
                          Pending <?= number_format($pendingScoresTotal); ?> | Rate <?= htmlspecialchars(president_format_percent($scoringPercentTotal)); ?>
                        </span>
                      </div>
                    </div>
                  </div>

                  <div class="card pd-chart-card mb-4">
                    <div class="card-body">
                      <div class="pd-chart-title">Expected, Interviewed, and Scored by Campus</div>
                      <div class="pd-chart-subtitle mb-3"><?= htmlspecialchars($combinedCampusRangeLabel); ?></div>
                      <div id="presidentCampusCombinedChart"></div>
                    </div>
                  </div>
                </div>

                <div class="col-xl-4">
                  <div class="card pd-panel-card mb-4">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-center mb-3">
                        <div class="pd-chart-title">Institutional View</div>
                        <span class="badge bg-label-primary">Read only</span>
                      </div>
                      <div class="pd-overview-list">
                        <div class="pd-overview-item">
                          <span class="pd-overview-item__label">Cutoff Filter</span>
                          <span class="pd-overview-item__value"><?= $globalSatCutoffActive ? 'Active' : 'Inactive'; ?></span>
                          <span class="pd-overview-item__hint"><?= htmlspecialchars($globalSatCutoffRangeText !== '' ? $globalSatCutoffRangeText : 'No global SAT override'); ?></span>
                        </div>
                        <div class="pd-overview-item">
                          <span class="pd-overview-item__label">Campus Demand</span>
                          <span class="pd-overview-item__value"><?= number_format($campusesWithDemand); ?></span>
                          <span class="pd-overview-item__hint">Campuses with at least one mapped expected interview</span>
                        </div>
                        <div class="pd-overview-item">
                          <span class="pd-overview-item__label">Campus Activity</span>
                          <span class="pd-overview-item__value"><?= number_format($campusesWithInterviewActivity); ?></span>
                          <span class="pd-overview-item__hint">Campuses with active interview records</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="card pd-panel-card">
                    <div class="card-body">
                      <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
                        <div>
                          <div class="pd-chart-title">Campus Detail View</div>
                          <div class="pd-chart-subtitle">Open per-campus program interview status and capacity snapshot.</div>
                        </div>
                        <div class="small text-muted"><?= number_format($activeCampusCount); ?> campuses</div>
                      </div>

                      <?php
                        $campusCardStyles = [
                            ['icon' => 'bx-buildings', 'badge' => 'bg-label-primary'],
                            ['icon' => 'bx-map', 'badge' => 'bg-label-success'],
                            ['icon' => 'bx-landscape', 'badge' => 'bg-label-info'],
                            ['icon' => 'bx-school', 'badge' => 'bg-label-warning'],
                            ['icon' => 'bx-arch', 'badge' => 'bg-label-danger'],
                            ['icon' => 'bx-compass', 'badge' => 'bg-label-secondary']
                        ];
                      ?>

                      <div class="pd-campus-card-list">
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
                            class="pd-campus-card w-100 text-start"
                            data-campus-id="<?= $campusId; ?>"
                            aria-label="Open <?= htmlspecialchars((string) ($campus['campus_name'] ?? 'Campus')); ?> detail view"
                          >
                            <div class="pd-campus-card__main">
                              <span class="pd-campus-card__icon <?= $style['badge']; ?>">
                                <i class="bx <?= $style['icon']; ?>"></i>
                              </span>
                              <div>
                                <div class="pd-campus-card__code"><?= htmlspecialchars((string) ($campus['campus_code'] ?? '')); ?></div>
                                <div class="pd-campus-card__name"><?= htmlspecialchars((string) ($campus['campus_name'] ?? 'Campus')); ?></div>
                                <small class="pd-campus-card__sub">
                                  Scored: <?= number_format($scoredCount); ?> | Not Scored: <?= number_format($unscoredCount); ?>
                                </small>
                              </div>
                            </div>

                            <div class="pd-campus-card__total">
                              <div class="pd-campus-card__total-number"><?= number_format($interviewedCount); ?></div>
                              <small class="pd-campus-card__total-label">Interviewed</small>
                            </div>
                          </button>
                        <?php endforeach; ?>

                        <?php if (empty($campuses)): ?>
                          <div class="text-muted small">No active campus records found.</div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card pd-chart-card">
                <div class="card-body">
                  <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                    <div>
                      <div class="pd-chart-title">Campus Snapshot</div>
                      <div class="pd-chart-subtitle">
                        Compare expected demand, actual interviews, scoring completion, and pending load per campus.
                      </div>
                    </div>
                    <div class="small text-muted">
                      Campuses with interview activity: <?= number_format($campusesWithInterviewActivity); ?>
                    </div>
                  </div>

                  <?php if (empty($campusSnapshotRows)): ?>
                    <div class="pd-empty-card">No campus records found.</div>
                  <?php else: ?>
                    <div class="pd-campus-list">
                      <?php foreach ($campusSnapshotRows as $campus): ?>
                        <?php
                          $coverageRate = $campus['coverage_rate'];
                          $scoringRate = $campus['scoring_rate'];
                          $coverageWidth = $coverageRate !== null ? max(0, min(100, $coverageRate)) : 0;
                          $scoringWidth = $scoringRate !== null ? max(0, min(100, $scoringRate)) : 0;
                        ?>
                        <article class="pd-campus-item">
                          <div class="pd-campus-item__header">
                            <div>
                              <div class="pd-campus-item__code"><?= htmlspecialchars((string) $campus['campus_code']); ?></div>
                              <div class="pd-campus-item__name"><?= htmlspecialchars((string) $campus['campus_name']); ?></div>
                            </div>
                            <div class="pd-campus-item__status">
                              <?= (int) ($campus['pending'] ?? 0) > 0 ? number_format((int) $campus['pending']) . ' records still pending scoring' : 'No pending scoring backlog'; ?>
                            </div>
                          </div>

                          <div class="pd-campus-item__metrics">
                            <div class="pd-campus-metric">
                              <span class="pd-campus-metric__label">Expected</span>
                              <span class="pd-campus-metric__value"><?= number_format((int) ($campus['expected'] ?? 0)); ?></span>
                            </div>
                            <div class="pd-campus-metric">
                              <span class="pd-campus-metric__label">Interviewed</span>
                              <span class="pd-campus-metric__value"><?= number_format((int) ($campus['interviewed'] ?? 0)); ?></span>
                            </div>
                            <div class="pd-campus-metric">
                              <span class="pd-campus-metric__label">Scored</span>
                              <span class="pd-campus-metric__value"><?= number_format((int) ($campus['scored'] ?? 0)); ?></span>
                            </div>
                            <div class="pd-campus-metric">
                              <span class="pd-campus-metric__label">Pending</span>
                              <span class="pd-campus-metric__value"><?= number_format((int) ($campus['pending'] ?? 0)); ?></span>
                            </div>
                          </div>

                          <div class="pd-campus-progress">
                            <div>
                              <div class="pd-campus-progress__label">
                                <span>Interview coverage</span>
                                <span><?= htmlspecialchars(president_format_percent($coverageRate)); ?></span>
                              </div>
                              <div class="progress">
                                <div class="progress-bar bg-primary" role="progressbar" style="width: <?= htmlspecialchars((string) $coverageWidth); ?>%"></div>
                              </div>
                            </div>
                            <div>
                              <div class="pd-campus-progress__label">
                                <span>Scoring completion</span>
                                <span><?= htmlspecialchars(president_format_percent($scoringRate)); ?></span>
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

              <div class="modal fade" id="presidentCampusStatusModal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                  <div class="modal-content">
                    <div class="modal-header">
                      <div>
                        <h5 class="modal-title mb-1" id="presidentCampusStatusModalTitle">Campus Program Interview Status</h5>
                        <div class="pd-campus-status-summary" id="presidentCampusStatusSummary"></div>
                      </div>
                      <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                      <div id="presidentCampusProgramsWrap"></div>
                      <div id="presidentCampusStatusEmptyState" class="text-muted small d-none">
                        No active programs found for this campus.
                      </div>
                    </div>
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
        const campusCategories = <?= json_encode(array_values($campusChartLabels)); ?>;
        const campusTooltipLabels = <?= json_encode(array_values($campusChartFullLabels)); ?>;
        const campusCombinedSeries = <?= json_encode($campusCombinedSeries); ?>;
        const campusCombinedChartEl = document.querySelector('#presidentCampusCombinedChart');
        const campusStatusById = <?= json_encode($campusProgramStatus); ?>;
        const campusCardEls = document.querySelectorAll('.pd-campus-card[data-campus-id]');
        const campusStatusModalEl = document.getElementById('presidentCampusStatusModal');
        const campusStatusModalTitleEl = document.getElementById('presidentCampusStatusModalTitle');
        const campusStatusSummaryEl = document.getElementById('presidentCampusStatusSummary');
        const campusProgramsWrapEl = document.getElementById('presidentCampusProgramsWrap');
        const campusStatusEmptyStateEl = document.getElementById('presidentCampusStatusEmptyState');

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function toNumber(value) {
          const numericValue = Number(value);
          return Number.isFinite(numericValue) ? numericValue : 0;
        }

        function openCampusStatusModal(campusIdRaw) {
          if (!campusStatusModalEl || typeof bootstrap === 'undefined') {
            return;
          }

          const campusId = String(campusIdRaw || '');
          const campus = campusStatusById[campusId] || null;
          if (!campus) {
            return;
          }

          const campusCode = escapeHtml(campus.campus_code || '');
          const campusName = escapeHtml(campus.campus_name || 'Campus');
          const scoredCount = toNumber(campus.scored_count);
          const unscoredCount = toNumber(campus.unscored_count);
          const totalCount = toNumber(campus.interviewed_count);

          campusStatusModalTitleEl.innerHTML = `${campusCode} - ${campusName}`;
          campusStatusSummaryEl.innerHTML = `
            <span class="pd-campus-status-chip">Total Interviewed: ${totalCount.toLocaleString()}</span>
            <span class="pd-campus-status-chip">Scored: ${scoredCount.toLocaleString()}</span>
            <span class="pd-campus-status-chip">Not Scored: ${unscoredCount.toLocaleString()}</span>
          `;

          const programs = Array.isArray(campus.programs) ? campus.programs.slice() : [];
          programs.sort((a, b) => {
            const aTotal = toNumber(a.total_count);
            const bTotal = toNumber(b.total_count);
            if (bTotal !== aTotal) {
              return bTotal - aTotal;
            }

            return String(a.program_label || '').localeCompare(String(b.program_label || ''));
          });

          if (programs.length === 0) {
            campusProgramsWrapEl.innerHTML = '';
            campusStatusEmptyStateEl.classList.remove('d-none');
          } else {
            campusStatusEmptyStateEl.classList.add('d-none');
            campusProgramsWrapEl.innerHTML = programs.map((program) => {
              const programLabel = escapeHtml(program.program_label || 'Program');
              const programScored = toNumber(program.scored_count);
              const programUnscored = toNumber(program.unscored_count);
              const programTotal = toNumber(program.total_count);

              return `
                <div class="pd-campus-program-item">
                  <div class="d-flex justify-content-between align-items-start gap-2">
                    <p class="pd-campus-program-title">${programLabel}</p>
                    <span class="badge bg-label-primary">Total: ${programTotal.toLocaleString()}</span>
                  </div>
                  <div class="pd-campus-program-metrics mt-2">
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

        if (typeof ApexCharts === 'undefined') {
          return;
        }

        if (campusCombinedChartEl) {
          const hasCampusData = Array.isArray(campusCombinedSeries)
            && campusCombinedSeries.some((seriesRow) =>
              Array.isArray(seriesRow.data) && seriesRow.data.some((value) => toNumber(value) > 0)
            );

          if (!Array.isArray(campusCategories) || campusCategories.length === 0 || !Array.isArray(campusCombinedSeries) || campusCombinedSeries.length === 0) {
            campusCombinedChartEl.innerHTML = '<div class="text-muted small py-3">No campus monitoring data available.</div>';
          } else {
            const campusSeriesNames = campusCombinedSeries.map((seriesRow) => String(seriesRow.name || ''));
            new ApexCharts(campusCombinedChartEl, {
              chart: {
                type: 'line',
                height: 330,
                toolbar: { show: false },
                zoom: { enabled: false }
              },
              series: campusCombinedSeries,
              xaxis: {
                categories: campusCategories,
                axisBorder: { show: false },
                axisTicks: { show: false },
                labels: {
                  rotate: 0,
                  hideOverlappingLabels: false,
                  style: {
                    colors: '#7d8aa3',
                    fontSize: '12px'
                  }
                }
              },
              stroke: {
                curve: 'straight',
                width: campusSeriesNames.map((name) => (
                  name === 'Expected for Interview' || name === 'Expected Without Cutoff' ? 2.4 : 3
                )),
                dashArray: campusSeriesNames.map((name) => {
                  if (name === 'Expected for Interview') {
                    return 6;
                  }

                  if (name === 'Expected Without Cutoff') {
                    return 2;
                  }

                  return 0;
                })
              },
              markers: {
                size: 4.5,
                strokeWidth: 0,
                hover: {
                  sizeOffset: 2
                }
              },
              colors: ['#8592a3', '#03c3ec', '#71dd37', '#ff9f43'],
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
              tooltip: {
                shared: true,
                intersect: false,
                x: {
                  formatter: function (_, context) {
                    const index = Number(context && context.dataPointIndex ? context.dataPointIndex : 0);
                    return campusTooltipLabels[index] || campusCategories[index] || '';
                  }
                },
                y: {
                  formatter: function (value) {
                    const count = Math.round(toNumber(value));
                    return `${count.toLocaleString()} student${count === 1 ? '' : 's'}`;
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
                },
                title: {
                  text: 'Students'
                }
              },
              noData: {
                text: hasCampusData ? 'Loading...' : 'No campus monitoring data available.'
              }
            }).render();
          }
        }
      });
    </script>
  </body>
</html>
