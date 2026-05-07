<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
require_once '../config/program_ranking_lock.php';
require_once '../config/student_preregistration.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'monitoring')) {
    header('Location: ../index.php');
    exit;
}

$monitoringHeaderTitle = 'Monitoring Center - Program Rankings';
$search = trim((string) ($_GET['q'] ?? ''));
$campusFilter = (int) ($_GET['campus_id'] ?? 0);
$isProgramCardsRequest = strtolower(trim((string) ($_GET['fetch'] ?? ''))) === 'program_cards';
$isProgramLocksPrintRequest = strtolower(trim((string) ($_GET['print'] ?? ''))) === 'program_locks';
$isQualifiedStudentsPrintRequest = strtolower(trim((string) ($_GET['print'] ?? ''))) === 'qualified_students';
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 12;

if (!$isProgramCardsRequest) {
    $page = 1;
}

$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffRanges = is_array($globalSatCutoffState['ranges'] ?? null) ? $globalSatCutoffState['ranges'] : [];
$globalSatCutoffRangeText = trim((string) ($globalSatCutoffState['range_text'] ?? ''));
$globalSatCutoffActive = $globalSatCutoffEnabled && (!empty($globalSatCutoffRanges) || isset($globalSatCutoffState['value']));

if ($globalSatCutoffActive && $globalSatCutoffRangeText === '') {
    $globalSatCutoffRangeText = format_sat_cutoff_ranges_for_display($globalSatCutoffRanges, ', ');
}

ensure_program_ranking_locks_table($conn);
ensure_student_preregistration_storage($conn);

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

$campusFilterLabel = 'All Campuses';
if ($campusFilter > 0) {
    $campusFilterLabel = 'Campus ID ' . $campusFilter;
    foreach ($campusOptions as $campusOption) {
        $optionCampusId = (int) ($campusOption['campus_id'] ?? 0);
        if ($optionCampusId !== $campusFilter) {
            continue;
        }
        $campusFilterLabel = trim((string) ($campusOption['campus_name'] ?? ''));
        break;
    }
}

$programLockPrintUrl = 'program_rankings.php?' . http_build_query([
    'print' => 'program_locks',
    'q' => $search,
    'campus_id' => $campusFilter
]);
$qualifiedStudentsPrintUrl = 'program_rankings.php?' . http_build_query([
    'print' => 'qualified_students',
    'q' => $search,
    'campus_id' => $campusFilter
]);

$where = ['p.status = \'active\''];
$types = '';
$params = [];

if ($search !== '') {
    $where[] = "(p.program_name LIKE ? OR p.major LIKE ? OR col.college_name LIKE ? OR cam.campus_name LIKE ?)";
    $like = '%' . $search . '%';
    $types .= 'ssss';
    array_push($params, $like, $like, $like, $like);
}

if ($campusFilter > 0) {
    $where[] = 'cam.campus_id = ?';
    $types .= 'i';
    $params[] = $campusFilter;
}

$programFromSql = "
    FROM tbl_program p
    INNER JOIN tbl_college col
        ON p.college_id = col.college_id
    INNER JOIN tbl_campus cam
        ON col.campus_id = cam.campus_id
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    LEFT JOIN (
        SELECT
            COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) AS ranking_program_id,
            COUNT(*) AS total_count,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count,
            SUM(CASE WHEN si.final_score IS NULL THEN 1 ELSE 0 END) AS unscored_count
        FROM tbl_student_interview si
        WHERE si.status = 'active'
        GROUP BY COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
    ) st
        ON st.ranking_program_id = p.program_id
    LEFT JOIN (
        SELECT
            l.program_id,
            COUNT(*) AS locked_count
        FROM tbl_program_ranking_locks l
        GROUP BY l.program_id
    ) lockstat
        ON lockstat.program_id = p.program_id
";
$whereSql = implode(' AND ', $where);

function monitoring_qualified_program_label(array $row): string
{
    $code = trim((string) ($row['program_code'] ?? ''));
    $name = trim((string) ($row['program_name'] ?? ''));
    $major = trim((string) ($row['major'] ?? ''));

    $label = $name;
    if ($major !== '') {
        $label .= ' - ' . $major;
    }
    if ($code !== '') {
        $label = $code . ' - ' . $label;
    }

    return trim($label) !== '' ? $label : ('Program #' . max(0, (int) ($row['program_id'] ?? 0)));
}

function monitoring_fetch_qualified_student_rows(mysqli $conn, int $campusFilter, string $search): array
{
    $where = [
        "c.status = 'active'",
        "col.status = 'active'",
        "p.status = 'active'",
    ];
    $types = '';
    $params = [];

    if ($campusFilter > 0) {
        $where[] = 'c.campus_id = ?';
        $types .= 'i';
        $params[] = $campusFilter;
    }

    if ($search !== '') {
        $where[] = "(
            l.snapshot_full_name LIKE ?
            OR l.snapshot_examinee_number LIKE ?
            OR p.program_code LIKE ?
            OR p.program_name LIKE ?
            OR p.major LIKE ?
            OR col.college_name LIKE ?
            OR c.campus_name LIKE ?
        )";
        $like = '%' . $search . '%';
        $types .= 'sssssss';
        array_push($params, $like, $like, $like, $like, $like, $like, $like);
    }

    $whereSql = implode(' AND ', $where);
    $sql = "
        SELECT
            l.lock_id,
            l.interview_id,
            l.snapshot_examinee_number,
            l.snapshot_full_name,
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            col.college_name,
            c.campus_id,
            c.campus_code,
            c.campus_name
        FROM tbl_program_ranking_locks l
        INNER JOIN tbl_program p
            ON p.program_id = l.program_id
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        INNER JOIN tbl_campus c
            ON c.campus_id = col.campus_id
        LEFT JOIN tbl_student_preregistration spr
            ON spr.interview_id = l.interview_id
           AND spr.status = 'submitted'
        WHERE {$whereSql}
        ORDER BY
            c.campus_name ASC,
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC,
            l.snapshot_full_name ASC,
            l.snapshot_examinee_number ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }
    $stmt->execute();

    $rows = [];
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $row['program_label'] = monitoring_qualified_program_label($row);
        $rows[] = $row;
    }
    $stmt->close();

    return $rows;
}

if ($isQualifiedStudentsPrintRequest) {
    $printRows = monitoring_fetch_qualified_student_rows($conn, $campusFilter, $search);
    $campusGroups = [];
    foreach ($printRows as $row) {
        $campusId = (int) ($row['campus_id'] ?? 0);
        $programId = (int) ($row['program_id'] ?? 0);

        if (!isset($campusGroups[$campusId])) {
            $campusGroups[$campusId] = [
                'campus_name' => (string) ($row['campus_name'] ?? 'Campus'),
                'campus_code' => (string) ($row['campus_code'] ?? ''),
                'programs' => [],
                'count' => 0,
            ];
        }

        if (!isset($campusGroups[$campusId]['programs'][$programId])) {
            $campusGroups[$campusId]['programs'][$programId] = [
                'program_label' => (string) ($row['program_label'] ?? 'Program'),
                'college_name' => (string) ($row['college_name'] ?? ''),
                'rows' => [],
            ];
        }

        $campusGroups[$campusId]['programs'][$programId]['rows'][] = $row;
        $campusGroups[$campusId]['count']++;
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Qualified Students Print List</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      font-family: Arial, sans-serif;
      color: #111827;
      background: #ffffff;
    }
    h1 {
      margin: 0;
      font-size: 22px;
      line-height: 1.2;
    }
    .meta {
      margin-top: 8px;
      color: #475569;
      font-size: 13px;
      line-height: 1.45;
    }
    .summary {
      margin-top: 14px;
      padding: 10px 12px;
      border: 1px solid #dbe2eb;
      border-radius: 8px;
      background: #f8fafc;
      font-size: 13px;
    }
    .campus {
      margin-top: 18px;
      page-break-inside: avoid;
    }
    .campus-title {
      padding: 10px 12px;
      border: 1px solid #dbe2eb;
      background: #eff6ff;
      font-size: 16px;
      font-weight: 700;
      color: #1e3a5f;
    }
    .program {
      margin-top: 10px;
      page-break-inside: avoid;
    }
    .program-title {
      padding: 8px 10px;
      border: 1px solid #dbe2eb;
      border-bottom: 0;
      background: #f8fafc;
      font-size: 13px;
      font-weight: 700;
      color: #334155;
    }
    .program-title small {
      display: block;
      margin-top: 2px;
      font-size: 11px;
      color: #64748b;
      font-weight: 400;
      text-transform: uppercase;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      font-size: 12.5px;
    }
    th, td {
      border: 1px solid #dbe2eb;
      padding: 7px 9px;
      text-align: left;
      vertical-align: top;
    }
    th {
      background: #ffffff;
      color: #334155;
      font-size: 11px;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
    .num {
      width: 46px;
      text-align: right;
      white-space: nowrap;
    }
    .name {
      font-weight: 700;
      text-transform: uppercase;
    }
    .empty {
      margin-top: 16px;
      border: 1px dashed #cbd5e1;
      border-radius: 8px;
      padding: 16px;
      color: #64748b;
      background: #f8fafc;
    }
    @media print {
      @page { size: portrait; margin: 10mm; }
      body { padding: 0; }
    }
  </style>
</head>
<body>
  <h1>Qualified Students for Document Submission</h1>
  <div class="meta">
    Campus: <?= htmlspecialchars($campusFilterLabel); ?><br>
    Search: <?= htmlspecialchars($search !== '' ? $search : 'None'); ?>
  </div>
  <div class="summary">
    <strong><?= number_format(count($printRows)); ?></strong> published names
  </div>

  <?php if (!empty($campusGroups)): ?>
    <?php foreach ($campusGroups as $campusGroup): ?>
      <section class="campus">
        <div class="campus-title">
          <?= htmlspecialchars($campusGroup['campus_name']); ?>
          <?php if (trim((string) ($campusGroup['campus_code'] ?? '')) !== ''): ?>
            (<?= htmlspecialchars((string) $campusGroup['campus_code']); ?>)
          <?php endif; ?>
          - <?= number_format((int) ($campusGroup['count'] ?? 0)); ?> names
        </div>
        <?php foreach ($campusGroup['programs'] as $programGroup): ?>
          <div class="program">
            <div class="program-title">
              <?= htmlspecialchars((string) ($programGroup['program_label'] ?? 'Program')); ?>
              <?php if (trim((string) ($programGroup['college_name'] ?? '')) !== ''): ?>
                <small><?= htmlspecialchars((string) $programGroup['college_name']); ?></small>
              <?php endif; ?>
            </div>
            <table>
              <thead>
                <tr>
                  <th class="num">#</th>
                  <th>Student Name</th>
                  <th style="width: 180px;">Examinee Number</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ((array) ($programGroup['rows'] ?? []) as $index => $row): ?>
                  <tr>
                    <td class="num"><?= number_format($index + 1); ?></td>
                    <td class="name"><?= htmlspecialchars((string) ($row['snapshot_full_name'] ?? 'Unnamed Student')); ?></td>
                    <td><?= htmlspecialchars((string) ($row['snapshot_examinee_number'] ?? 'N/A')); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endforeach; ?>
  <?php else: ?>
    <div class="empty">No locked students found for the selected filters.</div>
  <?php endif; ?>

  <script>
    window.addEventListener('load', function () {
      window.print();
    });
  </script>
</body>
</html>
    <?php
    exit;
}

if ($isProgramLocksPrintRequest) {
    $printRows = [];
    $printSql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            col.college_name,
            cam.campus_name,
            COALESCE(lockstat.locked_count, 0) AS locked_count
        {$programFromSql}
        WHERE {$whereSql}
        ORDER BY
            COALESCE(lockstat.locked_count, 0) DESC,
            cam.campus_name ASC,
            col.college_name ASC,
            p.program_name ASC,
            p.major ASC
    ";
    $stmtPrint = $conn->prepare($printSql);
    if ($stmtPrint) {
        if ($types !== '') {
            $stmtPrint->bind_param($types, ...$params);
        }
        $stmtPrint->execute();
        $printResult = $stmtPrint->get_result();
        while ($printResult && $printRow = $printResult->fetch_assoc()) {
            $printRows[] = $printRow;
        }
        $stmtPrint->close();
    }

    $totalPrograms = count($printRows);
    $programsWithLocks = 0;
    $totalLockedRanks = 0;
    foreach ($printRows as $printRow) {
        $lockedCount = max(0, (int) ($printRow['locked_count'] ?? 0));
        $totalLockedRanks += $lockedCount;
        if ($lockedCount > 0) {
            $programsWithLocks++;
        }
    }

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Monitoring Program Lock Summary</title>
  <style>
    :root { color-scheme: light; }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      padding: 24px;
      font-family: Arial, sans-serif;
      color: #111827;
      background: #ffffff;
    }
    h1 {
      margin: 0;
      font-size: 22px;
      line-height: 1.2;
    }
    .meta {
      margin-top: 8px;
      color: #475569;
      font-size: 13px;
      line-height: 1.5;
    }
    .summary {
      margin-top: 14px;
      padding: 12px 14px;
      border: 1px solid #dbe2eb;
      border-radius: 8px;
      background: #f8fafc;
      display: flex;
      flex-wrap: wrap;
      gap: 14px;
      font-size: 13px;
    }
    .summary strong {
      font-size: 15px;
      color: #0f172a;
      margin-right: 4px;
    }
    table {
      width: 100%;
      border-collapse: collapse;
      margin-top: 16px;
      font-size: 13px;
    }
    th, td {
      border: 1px solid #dbe2eb;
      padding: 8px 10px;
      vertical-align: top;
      text-align: left;
    }
    th {
      background: #f1f5f9;
      font-size: 12px;
      text-transform: uppercase;
      color: #334155;
      letter-spacing: 0.03em;
    }
    td.num {
      text-align: right;
      white-space: nowrap;
      font-variant-numeric: tabular-nums;
    }
    .program-name {
      font-weight: 700;
      color: #0f172a;
      text-transform: uppercase;
    }
    .empty {
      margin-top: 16px;
      border: 1px dashed #cbd5e1;
      border-radius: 8px;
      padding: 16px;
      color: #64748b;
      background: #f8fafc;
    }
    @media print {
      @page { size: portrait; margin: 10mm; }
      body { padding: 0; }
    }
  </style>
</head>
<body>
  <h1>Program Locked Ranking Summary</h1>
  <div class="meta">
    Printed: <?= htmlspecialchars(date('F j, Y g:i A')); ?><br>
    Search: <?= htmlspecialchars($search !== '' ? $search : 'None'); ?> |
    Campus: <?= htmlspecialchars($campusFilterLabel); ?>
  </div>
  <div class="summary">
    <span><strong><?= number_format($totalPrograms); ?></strong> Programs</span>
    <span><strong><?= number_format($programsWithLocks); ?></strong> Programs with Locks</span>
    <span><strong><?= number_format($totalLockedRanks); ?></strong> Total Locked Rankings</span>
  </div>

  <?php if (!empty($printRows)): ?>
    <table>
      <thead>
        <tr>
          <th style="width: 52px;">#</th>
          <th>Program</th>
          <th style="width: 220px;">Campus / College</th>
          <th style="width: 150px;">Locked Rankings</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($printRows as $index => $printRow): ?>
          <?php
            $programName = trim((string) ($printRow['program_name'] ?? ''));
            $programCode = trim((string) ($printRow['program_code'] ?? ''));
            $major = trim((string) ($printRow['major'] ?? ''));
            $campusName = trim((string) ($printRow['campus_name'] ?? ''));
            $collegeName = trim((string) ($printRow['college_name'] ?? ''));
            $lockedCount = max(0, (int) ($printRow['locked_count'] ?? 0));
            $programLabel = $programName;
            if ($programCode !== '') {
                $programLabel = $programCode . ' | ' . $programLabel;
            }
            if ($major !== '') {
                $programLabel .= ' - [' . $major . ']';
            }
            $locationLabel = trim($campusName . ($collegeName !== '' ? ' - ' . $collegeName : ''));
          ?>
          <tr>
            <td class="num"><?= number_format($index + 1); ?></td>
            <td>
              <div class="program-name"><?= htmlspecialchars($programLabel); ?></div>
            </td>
            <td><?= htmlspecialchars($locationLabel); ?></td>
            <td class="num"><?= number_format($lockedCount); ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  <?php else: ?>
    <div class="empty">No programs found for the selected filters.</div>
  <?php endif; ?>

  <script>
    window.addEventListener('load', function () {
      window.print();
    });
  </script>
</body>
</html>
    <?php
    exit;
}

$summary = [
    'total_programs' => 0,
    'total_scored' => 0,
    'total_unscored' => 0,
    'total_interviewed' => 0
];
$summarySql = "
    SELECT
        COUNT(*) AS total_programs,
        COALESCE(SUM(COALESCE(st.scored_count, 0)), 0) AS total_scored,
        COALESCE(SUM(COALESCE(st.unscored_count, 0)), 0) AS total_unscored,
        COALESCE(SUM(COALESCE(st.total_count, 0)), 0) AS total_interviewed
    {$programFromSql}
    WHERE {$whereSql}
";
$stmtSummary = $conn->prepare($summarySql);
if ($stmtSummary) {
    if ($types !== '') {
        $stmtSummary->bind_param($types, ...$params);
    }
    $stmtSummary->execute();
    $summaryResult = $stmtSummary->get_result();
    if ($summaryResult && $summaryRow = $summaryResult->fetch_assoc()) {
        $summary['total_programs'] = (int) ($summaryRow['total_programs'] ?? 0);
        $summary['total_scored'] = (int) ($summaryRow['total_scored'] ?? 0);
        $summary['total_unscored'] = (int) ($summaryRow['total_unscored'] ?? 0);
        $summary['total_interviewed'] = (int) ($summaryRow['total_interviewed'] ?? 0);
    }
    $stmtSummary->close();
}

$totalPages = max(1, (int) ceil($summary['total_programs'] / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = max(0, ($page - 1) * $perPage);

$programSql = "
    SELECT
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        col.college_name,
        cam.campus_name,
        cam.campus_id,
        pc.cutoff_score,
        pc.absorptive_capacity,
        pc.regular_percentage,
        pc.etg_percentage,
        COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity,
        COALESCE(st.total_count, 0) AS total_interviewed,
        COALESCE(st.scored_count, 0) AS scored_count,
        COALESCE(st.unscored_count, 0) AS unscored_count,
        COALESCE(lockstat.locked_count, 0) AS locked_count
    {$programFromSql}
    WHERE {$whereSql}
    ORDER BY
        COALESCE(st.scored_count, 0) DESC,
        COALESCE(st.total_count, 0) DESC,
        COALESCE(st.unscored_count, 0) DESC,
        cam.campus_name ASC,
        col.college_name ASC,
        p.program_name ASC,
        p.major ASC
    LIMIT ? OFFSET ?
";

$programs = [];
$stmtProgram = $conn->prepare($programSql);
if ($stmtProgram) {
    $programTypes = $types . 'ii';
    $programParams = $params;
    $programParams[] = $perPage;
    $programParams[] = $offset;
    $stmtProgram->bind_param($programTypes, ...$programParams);
    $stmtProgram->execute();
    $programResult = $stmtProgram->get_result();
    while ($programRow = $programResult->fetch_assoc()) {
        $programs[] = $programRow;
    }
    $stmtProgram->close();
}

$loadedPrograms = count($programs);
$hasMorePrograms = $page < $totalPages;

$pendingTransfers = 0;
$pendingTransferResult = $conn->query("SELECT COUNT(*) AS total FROM tbl_student_transfer_history WHERE status = 'pending'");
if ($pendingTransferResult) {
    $pendingTransfers = (int) (($pendingTransferResult->fetch_assoc()['total'] ?? 0));
}

function build_monitoring_program_display(array $program): array
{
    $programName = trim((string) ($program['program_name'] ?? ''));
    $programCode = trim((string) ($program['program_code'] ?? ''));
    $major = trim((string) ($program['major'] ?? ''));
    $campusName = trim((string) ($program['campus_name'] ?? ''));
    $collegeName = trim((string) ($program['college_name'] ?? ''));

    $headline = $programName;
    if ($programCode !== '') {
        $headline = $programCode . ' | ' . $headline;
    }

    if ($major !== '') {
        $headline .= ' - [' . $major . ']';
    }

    $locationParts = [];
    if ($campusName !== '') {
        $locationParts[] = $campusName;
    }
    if ($collegeName !== '') {
        $locationParts[] = $collegeName;
    }

    return [
        'headline' => $headline,
        'location' => implode(' - ', $locationParts)
    ];
}

function render_monitoring_program_card(array $program): string
{
    $programDisplay = build_monitoring_program_display($program);
    $programLabel = $programDisplay['headline'];
    $locationLabel = $programDisplay['location'];

    $cutoffScore = $program['cutoff_score'] ?? null;
    $hasCutoff = ($cutoffScore !== null && $cutoffScore !== '');
    $cutoffDisplay = $hasCutoff ? number_format((int) $cutoffScore) : 'Not Set';

    $hasCapacityConfig = (
        $program['absorptive_capacity'] !== null &&
        $program['regular_percentage'] !== null &&
        $program['etg_percentage'] !== null
    );

    $absorptiveCapacity = $hasCapacityConfig ? max(0, (int) ($program['absorptive_capacity'] ?? 0)) : 0;
    $endorsementCapacity = $hasCapacityConfig ? max(0, (int) ($program['endorsement_capacity'] ?? 0)) : 0;
    $baseCapacity = $hasCapacityConfig ? max(0, $absorptiveCapacity - $endorsementCapacity) : 0;
    $regularPercentage = $hasCapacityConfig ? (float) ($program['regular_percentage'] ?? 0) : 0.0;
    $etgPercentage = $hasCapacityConfig ? (float) ($program['etg_percentage'] ?? 0) : 0.0;
    $regularSlots = $hasCapacityConfig ? (int) round($baseCapacity * ($regularPercentage / 100)) : 0;
    $etgSlots = $hasCapacityConfig ? max(0, $baseCapacity - $regularSlots) : 0;

    $capacityDisplay = $hasCapacityConfig ? number_format($absorptiveCapacity) : 'N/A';
    $sccDisplay = $hasCapacityConfig ? number_format($endorsementCapacity) : 'N/A';
    $baseCapacityDisplay = $hasCapacityConfig ? number_format($baseCapacity) : 'N/A';
    $regularDisplay = $hasCapacityConfig
        ? number_format($regularPercentage, 2) . '% / ' . number_format($regularSlots)
        : 'N/A';
    $etgDisplay = $hasCapacityConfig
        ? number_format($etgPercentage, 2) . '% / ' . number_format($etgSlots)
        : 'N/A';
    $configStateClass = $hasCapacityConfig ? '' : ' monitor-config-chip--muted';

    ob_start();
    ?>
    <article class="monitor-program-card">
      <div class="monitor-program-card__main">
        <div class="monitor-program-card__title"><?= htmlspecialchars($programLabel); ?></div>
        <?php if ($locationLabel !== ''): ?>
          <div class="monitor-program-card__meta"><?= htmlspecialchars($locationLabel); ?></div>
        <?php endif; ?>

        <div class="monitor-program-config">
          <span class="monitor-config-chip monitor-config-chip--cutoff">
            Cut-Off
            <span class="monitor-config-chip__value"><?= htmlspecialchars($cutoffDisplay); ?></span>
          </span>
          <span class="monitor-config-chip monitor-config-chip--capacity<?= $configStateClass; ?>">
            Capacity
            <span class="monitor-config-chip__value"><?= htmlspecialchars($capacityDisplay); ?></span>
          </span>
          <span class="monitor-config-chip monitor-config-chip--scc<?= $configStateClass; ?>">
            SCC
            <span class="monitor-config-chip__value"><?= htmlspecialchars($sccDisplay); ?></span>
          </span>
          <span class="monitor-config-chip monitor-config-chip--base<?= $configStateClass; ?>">
            Base Capacity
            <span class="monitor-config-chip__value"><?= htmlspecialchars($baseCapacityDisplay); ?></span>
          </span>
          <span class="monitor-config-chip monitor-config-chip--regular<?= $configStateClass; ?>">
            Regular
            <span class="monitor-config-chip__value"><?= htmlspecialchars($regularDisplay); ?></span>
          </span>
          <span class="monitor-config-chip monitor-config-chip--etg<?= $configStateClass; ?>">
            ETG
            <span class="monitor-config-chip__value"><?= htmlspecialchars($etgDisplay); ?></span>
          </span>
        </div>
      </div>

      <div class="monitor-program-metrics">
        <div class="monitor-program-metric <?= $hasCutoff ? 'monitor-program-metric--success' : 'monitor-program-metric--danger'; ?>">
          <span class="monitor-program-metric__label">Cutoff Score</span>
          <span class="monitor-program-metric__value"><?= htmlspecialchars($cutoffDisplay); ?></span>
          <span class="monitor-program-metric__hint"><?= $hasCutoff ? 'Program cutoff configured' : 'Program cutoff not configured'; ?></span>
        </div>

        <div class="monitor-program-metric">
          <span class="monitor-program-metric__label">Total Interviewed</span>
          <span class="monitor-program-metric__value"><?= number_format((int) ($program['total_interviewed'] ?? 0)); ?></span>
          <span class="monitor-program-metric__hint">Students with interview records</span>
        </div>

        <div class="monitor-program-metric">
          <span class="monitor-program-metric__label">Scored Interviews</span>
          <span class="monitor-program-metric__value"><?= number_format((int) ($program['scored_count'] ?? 0)); ?></span>
          <span class="monitor-program-metric__hint">Final interview scores saved</span>
        </div>

        <div class="monitor-program-metric">
          <span class="monitor-program-metric__label">Waiting for Scores</span>
          <span class="monitor-program-metric__value"><?= number_format((int) ($program['unscored_count'] ?? 0)); ?></span>
          <span class="monitor-program-metric__hint">Interview records still unscored</span>
        </div>
      </div>

      <div class="monitor-program-card__footer">
        <button
          type="button"
          class="btn btn-outline-primary js-open-ranking"
          data-program-id="<?= (int) ($program['program_id'] ?? 0); ?>"
          data-program-name="<?= htmlspecialchars(strtoupper($programLabel), ENT_QUOTES); ?>"
        >
          View Ranking
        </button>
      </div>
    </article>
    <?php

    return trim((string) ob_get_clean());
}

function render_monitoring_program_cards(array $programs): string
{
    if (empty($programs)) {
        return '';
    }

    $html = '';
    foreach ($programs as $program) {
        $html .= render_monitoring_program_card($program);
    }

    return $html;
}

if ($isProgramCardsRequest) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'html' => render_monitoring_program_cards($programs),
        'page' => $page,
        'total_pages' => $totalPages,
        'total' => (int) $summary['total_programs'],
        'loaded_count' => $loadedPrograms,
        'has_more' => $hasMorePrograms,
        'next_page' => $hasMorePrograms ? ($page + 1) : 0
    ]);
    exit;
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
    <title>Monitoring Program Rankings - Interview</title>

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
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .mn-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .mn-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .mn-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .monitor-program-list {
        display: flex;
        flex-direction: column;
        gap: 1rem;
      }

      .monitor-program-card {
        border: 1px solid #e6ebf3;
        border-radius: 1rem;
        background: #fff;
        padding: 1rem 1.1rem;
        display: flex;
        flex-wrap: wrap;
        align-items: stretch;
        gap: 1rem;
      }

      .monitor-program-card__main {
        flex: 1 1 320px;
        min-width: 0;
      }

      .monitor-program-card__title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
        line-height: 1.45;
      }

      .monitor-program-card__meta {
        margin-top: 0.3rem;
        font-size: 0.84rem;
        color: #6b7a90;
      }

      .monitor-program-config {
        margin-top: 0.65rem;
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
      }

      .monitor-config-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.38rem;
        padding: 0.28rem 0.55rem;
        border-radius: 999px;
        border: 1px solid #dbe4f0;
        background: #f8fbff;
        font-size: 0.74rem;
        font-weight: 700;
        color: #617089;
        letter-spacing: 0.01em;
      }

      .monitor-config-chip__value {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 1.35rem;
        padding: 0.05rem 0.5rem;
        border-radius: 999px;
        border: 1px solid #d5dfec;
        background: #fff;
        color: #2f3f59;
        font-weight: 700;
      }

      .monitor-config-chip--cutoff .monitor-config-chip__value {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #4f46e5;
      }

      .monitor-config-chip--capacity .monitor-config-chip__value {
        background: #ecfeff;
        border-color: #a5f3fc;
        color: #0e7490;
      }

      .monitor-config-chip--scc .monitor-config-chip__value {
        background: #fff7ed;
        border-color: #fed7aa;
        color: #b45309;
      }

      .monitor-config-chip--base .monitor-config-chip__value {
        background: #f0f9ff;
        border-color: #bae6fd;
        color: #0369a1;
      }

      .monitor-config-chip--regular .monitor-config-chip__value {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #4338ca;
      }

      .monitor-config-chip--etg .monitor-config-chip__value {
        background: #f0fdf4;
        border-color: #bbf7d0;
        color: #15803d;
      }

      .monitor-config-chip--muted .monitor-config-chip__value {
        background: #f8fafc;
        border-color: #e2e8f0;
        color: #64748b;
      }

      .monitor-program-metrics {
        flex: 999 1 640px;
        display: grid;
        grid-template-columns: repeat(4, minmax(150px, 1fr));
        gap: 0.8rem;
      }

      .monitor-program-metric {
        border: 1px solid #e9eef5;
        border-radius: 0.85rem;
        padding: 0.8rem 0.85rem;
        background: #f9fbff;
      }

      .monitor-program-metric--success {
        background: #f3fbf2;
        border-color: #dbeed7;
      }

      .monitor-program-metric--danger {
        background: #fff5f2;
        border-color: #f6d7cf;
      }

      .monitor-program-metric__label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .monitor-program-metric__value {
        display: block;
        margin-top: 0.35rem;
        font-size: 1.2rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .monitor-program-metric--success .monitor-program-metric__value {
        color: #15803d;
      }

      .monitor-program-metric--danger .monitor-program-metric__value {
        color: #dc2626;
      }

      .monitor-program-metric__hint {
        display: block;
        margin-top: 0.2rem;
        font-size: 0.76rem;
        color: #7d8aa3;
        line-height: 1.2;
      }

      .monitor-program-card__footer {
        flex: 0 0 160px;
        display: flex;
        align-items: center;
        justify-content: flex-end;
      }

      .monitor-program-card__footer .btn {
        width: 100%;
      }

      .monitor-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 1rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
      }

      .monitor-scroll-state {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 0.65rem;
        min-height: 2.5rem;
        margin-top: 1rem;
        color: #6b7a90;
        font-size: 0.9rem;
      }

      .monitor-scroll-sentinel {
        height: 1px;
      }

      .ranking-list {
        border: 1px solid #e7ecf3;
        border-radius: 0.8rem;
        overflow: hidden;
        background: #fff;
      }

      .ranking-list-header,
      .ranking-list-row {
        display: grid;
        grid-template-columns: 58px 70px 110px minmax(240px, 1fr) 80px 80px 100px;
        gap: 0;
        align-items: center;
      }

      .ranking-list-header {
        background: #f6f8fc;
        border-bottom: 1px solid #e7ecf3;
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #5f6e86;
      }

      .ranking-list-header > div,
      .ranking-list-row > div {
        padding: 0.58rem 0.62rem;
      }

      .ranking-list-row {
        border-top: 1px solid #f0f3f8;
        font-size: 0.92rem;
        color: #334155;
      }

      .ranking-list-row .ranking-col-name {
        font-weight: 600;
        text-transform: uppercase;
      }

      .ranking-locked-row {
        background: #fffbeb;
      }

      .ranking-lock-pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        margin-left: 0.35rem;
        width: 1.4rem;
        height: 1.15rem;
        padding: 0;
        border-radius: 999px;
        border: 1px solid #fcd34d;
        background: #fef3c7;
        color: #92400e;
        font-size: 0.65rem;
        font-weight: 700;
        letter-spacing: 0.02em;
      }

      .ranking-lock-pill svg {
        width: 0.72rem;
        height: 0.72rem;
        display: block;
        fill: currentColor;
      }

      #monitoringLockStatus {
        font-weight: 600;
      }

      .swal2-popup .scc-picker-wrap {
        text-align: left;
      }

      .swal2-popup .scc-picker-label {
        display: block;
        margin-bottom: 0.35rem;
        font-size: 0.86rem;
        font-weight: 600;
        color: #374151;
      }

      .swal2-popup .scc-picker-help {
        margin-top: 0.45rem;
        font-size: 0.78rem;
        color: #6b7280;
        line-height: 1.35;
      }

      .swal2-popup .select2-container {
        width: 100% !important;
      }

      .swal2-container .select2-dropdown {
        z-index: 21001;
      }

      .ranking-scc-row {
        color: #15803d;
      }

      .ranking-etg-row {
        color: #2563eb;
      }

      .ranking-outside-capacity {
        color: #dc2626;
      }

      .ranking-outside-capacity .ranking-col-score {
        color: #dc2626;
      }

      .ranking-list-empty {
        padding: 1rem;
        color: #64748b;
        font-size: 0.9rem;
      }

      @media (max-width: 1199.98px) {
        .monitor-program-metrics {
          flex-basis: 100%;
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }

        .monitor-program-card__footer {
          flex-basis: 100%;
          justify-content: flex-start;
        }

        .monitor-program-card__footer .btn {
          max-width: 220px;
        }
      }

      @media (max-width: 767.98px) {
        .monitor-program-metrics {
          grid-template-columns: 1fr;
        }

        .monitor-program-card__footer .btn {
          max-width: none;
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
                <span class="text-muted fw-light">Monitoring /</span> Program Rankings
              </h4>
              <p class="text-muted mb-4">
                Unified monitoring view for all active programs. Cards are ranked by highest scored interviews first and include full program configuration details.
              </p>

              <?php if ($globalSatCutoffActive): ?>
                <div class="alert alert-info py-2 mb-3">
                  Global cutoff is active<?= $globalSatCutoffRangeText !== '' ? ': preferred-program basis range ' . htmlspecialchars($globalSatCutoffRangeText) : '.'; ?>
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Programs (Filtered)</div>
                    <div class="mn-stat-value"><?= number_format((int) $summary['total_programs']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Total Interviewed</div>
                    <div class="mn-stat-value"><?= number_format((int) $summary['total_interviewed']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Scored</div>
                    <div class="mn-stat-value"><?= number_format((int) $summary['total_scored']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="mn-stat-card">
                    <div class="mn-stat-label">Pending Transfers</div>
                    <div class="mn-stat-value"><?= number_format((int) $pendingTransfers); ?></div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-header">
                  <form method="GET" class="row g-2 align-items-end">
                    <div class="col-lg-6">
                      <label class="form-label mb-1">Search Program / Major / College / Campus</label>
                      <input
                        type="search"
                        name="q"
                        value="<?= htmlspecialchars($search); ?>"
                        class="form-control"
                        placeholder="Type program name, major, college, or campus"
                      />
                    </div>
                    <div class="col-lg-4">
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
                    <div class="col-lg-2 d-grid">
                      <button type="submit" class="btn btn-primary">Filter</button>
                    </div>
                  </form>
                </div>

                <div class="card-body">
                  <div class="d-flex flex-wrap justify-content-end gap-2 mb-3">
                    <a href="<?= htmlspecialchars($qualifiedStudentsPrintUrl); ?>" target="_blank" rel="noopener" class="btn btn-outline-primary btn-sm">
                      <i class="bx bx-printer me-1"></i> Print Qualified Students
                    </a>
                    <a href="<?= htmlspecialchars($programLockPrintUrl); ?>" target="_blank" rel="noopener" class="btn btn-outline-secondary btn-sm">
                      <i class="bx bx-printer me-1"></i> Print Program Lock Summary
                    </a>
                  </div>

                  <?php if (empty($programs)): ?>
                    <div class="monitor-empty-card">No programs found.</div>
                  <?php else: ?>
                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-3">
                      <div class="small text-muted" id="monitoringProgramCountText">
                        Loaded <?= number_format($loadedPrograms); ?> of <?= number_format((int) $summary['total_programs']); ?> matching programs
                      </div>
                      <div class="small text-muted" id="monitoringProgramPageText">
                        Loaded page <?= number_format($page); ?> of <?= number_format($totalPages); ?>
                      </div>
                    </div>

                    <div
                      id="monitoringProgramList"
                      class="monitor-program-list"
                      data-next-page="<?= $hasMorePrograms ? ($page + 1) : 0; ?>"
                      data-has-more="<?= $hasMorePrograms ? '1' : '0'; ?>"
                      data-total="<?= (int) $summary['total_programs']; ?>"
                      data-loaded="<?= $loadedPrograms; ?>"
                      data-total-pages="<?= $totalPages; ?>"
                      data-current-page="<?= $page; ?>"
                    >
                      <?= render_monitoring_program_cards($programs); ?>
                    </div>

                    <div id="monitoringProgramLoadState" class="monitor-scroll-state">
                      <div class="spinner-border spinner-border-sm text-primary d-none" id="monitoringProgramSpinner" role="status" aria-hidden="true"></div>
                      <span id="monitoringProgramLoadText"><?= $hasMorePrograms ? 'Scroll to load more programs.' : 'All programs loaded.'; ?></span>
                    </div>
                    <div id="monitoringProgramSentinel" class="monitor-scroll-sentinel<?= $hasMorePrograms ? '' : ' d-none'; ?>" aria-hidden="true"></div>
                  <?php endif; ?>
                </div>
              </div>
            </div>

            <div class="modal fade" id="monitoringRankingModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <div>
                      <h5 class="modal-title mb-1" id="monitoringRankingTitle">Program Ranking</h5>
                      <small class="text-muted" id="monitoringRankingMeta">--</small>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div class="d-flex flex-wrap gap-2 mb-3">
                      <span class="badge bg-label-primary">Program Chair View</span>
                      <span class="badge bg-label-info">Student View</span>
                      <span class="badge bg-label-secondary">Administrator View</span>
                    </div>
                    <div class="row g-2 align-items-end mb-2">
                      <div class="col-6 col-md-2">
                        <label class="form-label form-label-sm mb-1 small text-muted">No. From</label>
                        <input type="number" min="1" class="form-control form-control-sm" id="monitoringLockFrom" placeholder="1">
                      </div>
                      <div class="col-6 col-md-2">
                        <label class="form-label form-label-sm mb-1 small text-muted">No. To</label>
                        <input type="number" min="1" class="form-control form-control-sm" id="monitoringLockTo" placeholder="50">
                      </div>
                      <div class="col-12 col-md-auto d-grid">
                        <button type="button" class="btn btn-sm btn-warning" id="monitoringLockRangeBtn">
                          <i class="bx bx-lock-alt me-1"></i> Lock Range
                        </button>
                      </div>
                      <div class="col-12 col-md-auto d-grid">
                        <button type="button" class="btn btn-sm btn-outline-warning" id="monitoringUnlockRangeBtn">
                          <i class="bx bx-lock-open-alt me-1"></i> Unlock Range
                        </button>
                      </div>
                      <div class="col-12 col-md-auto d-grid">
                        <button type="button" class="btn btn-sm btn-outline-danger" id="monitoringUnlockAllBtn">
                          <i class="bx bx-reset me-1"></i> Unlock All
                        </button>
                      </div>
                      <div class="col-12 col-md-auto d-grid">
                        <button type="button" class="btn btn-sm btn-outline-primary" id="monitoringAddSccRegularBtn" disabled>
                          <i class="bx bx-plus-circle me-1"></i> Add SCC (Regular)
                        </button>
                      </div>
                    </div>

                    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
                      <div class="small text-muted" id="monitoringLockSummary">No locked ranks.</div>
                      <div class="d-flex gap-2 align-items-center">
                        <select id="monitoringPrintScope" class="form-select form-select-sm" style="min-width: 180px;">
                          <option value="all">Print: All in List</option>
                          <option value="locked">Print: Locked Only</option>
                          <option value="inside">Print: Inside Capacity Only</option>
                        </select>
                        <button type="button" class="btn btn-outline-secondary btn-sm" id="monitoringPrintBtn">
                          <i class="bx bx-printer me-1"></i> Print Ranking
                        </button>
                      </div>
                    </div>

                    <div class="small mb-2 d-none" id="monitoringLockStatus"></div>

                    <div id="monitoringRankingLoading" class="text-center py-4 d-none">
                      <div class="spinner-border text-primary" role="status"></div>
                      <div class="small text-muted mt-2">Loading ranking...</div>
                    </div>

                    <div id="monitoringRankingEmpty" class="alert alert-warning d-none mb-0">
                      No ranked students found for this program.
                    </div>

                    <div class="d-none" id="monitoringRankingTableWrap">
                      <div class="small text-muted mb-2">
                        <span class="fw-semibold">No.</span> follows Monitoring lock order. <span class="fw-semibold">Rank</span> keeps the academic rank order.
                      </div>
                      <div class="small text-muted mb-2">
                        <span class="fw-semibold text-danger">Red rows</span> are outside capacity but still shown in the ranking list.
                      </div>
                      <div id="monitoringRankingList" class="ranking-list"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      (function () {
        const listEl = document.getElementById('monitoringProgramList');
        const sentinelEl = document.getElementById('monitoringProgramSentinel');
        const spinnerEl = document.getElementById('monitoringProgramSpinner');
        const loadTextEl = document.getElementById('monitoringProgramLoadText');
        const countTextEl = document.getElementById('monitoringProgramCountText');
        const pageTextEl = document.getElementById('monitoringProgramPageText');

        if (!listEl || !sentinelEl || !loadTextEl) return;

        let nextPage = Number(listEl.dataset.nextPage || 0);
        let hasMore = listEl.dataset.hasMore === '1';
        let total = Number(listEl.dataset.total || 0);
        let loaded = Number(listEl.dataset.loaded || 0);
        let totalPages = Number(listEl.dataset.totalPages || 1);
        let currentPage = Number(listEl.dataset.currentPage || 1);
        let isLoading = false;

        function updateStateText() {
          if (countTextEl) {
            countTextEl.textContent = `Loaded ${loaded} of ${total} matching programs`;
          }

          if (pageTextEl) {
            pageTextEl.textContent = `Loaded page ${currentPage} of ${totalPages}`;
          }

          loadTextEl.textContent = hasMore ? 'Scroll to load more programs.' : 'All programs loaded.';
          sentinelEl.classList.toggle('d-none', !hasMore);
        }

        async function loadMorePrograms() {
          if (!hasMore || isLoading || nextPage <= 0) {
            return;
          }

          isLoading = true;
          if (spinnerEl) spinnerEl.classList.remove('d-none');
          loadTextEl.textContent = 'Loading more programs...';

          try {
            const params = new URLSearchParams(window.location.search);
            params.set('fetch', 'program_cards');
            params.set('page', String(nextPage));

            const response = await fetch(`program_rankings.php?${params.toString()}`, {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();

            if (!response.ok || !data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Failed to load more programs.');
            }

            if (data.html) {
              listEl.insertAdjacentHTML('beforeend', data.html);
            }

            loaded += Number(data.loaded_count || 0);
            total = Number(data.total || total);
            currentPage = Number(data.page || currentPage);
            totalPages = Number(data.total_pages || totalPages);
            hasMore = Boolean(data.has_more);
            nextPage = Number(data.next_page || 0);
            updateStateText();
          } catch (error) {
            loadTextEl.textContent = (error && error.message) ? error.message : 'Failed to load more programs.';
          } finally {
            isLoading = false;
            if (spinnerEl) spinnerEl.classList.add('d-none');
          }
        }

        updateStateText();

        if (!hasMore || !('IntersectionObserver' in window)) {
          return;
        }

        const observer = new IntersectionObserver((entries) => {
          entries.forEach((entry) => {
            if (entry.isIntersecting) {
              loadMorePrograms();
            }
          });
        }, {
          rootMargin: '320px 0px'
        });

        observer.observe(sentinelEl);
      })();

      (function () {
        const modalEl = document.getElementById('monitoringRankingModal');
        if (!modalEl) return;

        const rankingModal = new bootstrap.Modal(modalEl);
        const titleEl = document.getElementById('monitoringRankingTitle');
        const metaEl = document.getElementById('monitoringRankingMeta');
        const loadingEl = document.getElementById('monitoringRankingLoading');
        const emptyEl = document.getElementById('monitoringRankingEmpty');
        const tableWrapEl = document.getElementById('monitoringRankingTableWrap');
        const listEl = document.getElementById('monitoringRankingList');
        const printBtn = document.getElementById('monitoringPrintBtn');
        const printScopeEl = document.getElementById('monitoringPrintScope');
        const lockFromEl = document.getElementById('monitoringLockFrom');
        const lockToEl = document.getElementById('monitoringLockTo');
        const lockRangeBtn = document.getElementById('monitoringLockRangeBtn');
        const unlockRangeBtn = document.getElementById('monitoringUnlockRangeBtn');
        const unlockAllBtn = document.getElementById('monitoringUnlockAllBtn');
        const addSccRegularBtn = document.getElementById('monitoringAddSccRegularBtn');
        const lockSummaryEl = document.getElementById('monitoringLockSummary');
        const lockStatusEl = document.getElementById('monitoringLockStatus');
        const sccCandidatesEndpoint = '../progchair/fetch_scc_regular_candidates.php';
        const toggleEndorsementEndpoint = '../progchair/toggle_program_endorsement.php';

        let currentProgramId = 0;
        let currentProgramName = '';
        let currentQuota = null;
        let currentRows = [];
        let currentLocks = { active_count: 0, ranges: [] };

        function setState({ loading = false, empty = false, showTable = false }) {
          if (loadingEl) loadingEl.classList.toggle('d-none', !loading);
          if (emptyEl) emptyEl.classList.toggle('d-none', !empty);
          if (tableWrapEl) tableWrapEl.classList.toggle('d-none', !showTable);
        }

        function setLockStatus(message, isError = false) {
          if (!lockStatusEl) return;
          const text = String(message || '').trim();
          if (text === '') {
            lockStatusEl.classList.add('d-none');
            lockStatusEl.textContent = '';
            lockStatusEl.classList.remove('text-danger', 'text-success');
            return;
          }
          lockStatusEl.textContent = text;
          lockStatusEl.classList.remove('d-none');
          lockStatusEl.classList.toggle('text-danger', Boolean(isError));
          lockStatusEl.classList.toggle('text-success', !Boolean(isError));
        }

        function getMonitoringRankingSwalOptions(options = {}) {
          const mergedOptions = { ...(options || {}) };
          const originalDidOpen = mergedOptions.didOpen;

          mergedOptions.didOpen = (...args) => {
            const swalContainer = typeof Swal !== 'undefined' ? Swal.getContainer() : null;
            if (swalContainer) {
              swalContainer.style.zIndex = '20000';
            }
            if (typeof originalDidOpen === 'function') {
              originalDidOpen(...args);
            }
          };

          return mergedOptions;
        }

        function fireMonitoringRankingSwal(title, text, icon) {
          if (typeof Swal === 'undefined') {
            window.alert(`${title}\n\n${text}`);
            return Promise.resolve();
          }

          return Swal.fire(getMonitoringRankingSwalOptions({ title, text, icon }));
        }

        function updateLockSummary() {
          if (!lockSummaryEl) return;
          const count = Number(currentLocks?.active_count ?? 0);
          const ranges = Array.isArray(currentLocks?.ranges) ? currentLocks.ranges : [];
          if (count <= 0) {
            lockSummaryEl.textContent = 'No locked numbers.';
            return;
          }
          const label = ranges.length ? ranges.join(', ') : `${count} number(s)`;
          lockSummaryEl.textContent = `Locked No.: ${label}`;
        }

        function refreshActionButtons() {
          const hasProgram = currentProgramId > 0;
          const ecCapacity = Math.max(0, Number(currentQuota?.endorsement_capacity ?? 0));
          const ecSelected = Math.max(0, Number(currentQuota?.endorsement_selected ?? 0));
          const ecRemaining = Math.max(0, ecCapacity - ecSelected);

          if (addSccRegularBtn) {
            const canAddScc = hasProgram && ecCapacity > 0 && ecRemaining > 0;
            addSccRegularBtn.disabled = !canAddScc;
            addSccRegularBtn.title = addSccRegularBtn.disabled
              ? (!hasProgram
                  ? 'No active program selected.'
                  : (ecCapacity <= 0
                      ? 'SCC capacity is 0.'
                      : (ecRemaining <= 0 ? 'SCC is full.' : 'No active program selected.')))
              : `Add SCC from student interview list (${ecRemaining} slot${ecRemaining === 1 ? '' : 's'} remaining).`;
          }
        }

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function getRowSection(row) {
          const raw = String(row?.row_section || '').toLowerCase();
          if (raw === 'scc' || raw === 'etg' || raw === 'regular') return raw;
          if (Boolean(row?.is_endorsement)) return 'scc';
          return String(row?.classification || '').toUpperCase() === 'REGULAR' ? 'regular' : 'etg';
        }

        function buildRankingRowHtml(row, sequenceDisplay, rankDisplay, options = {}) {
          const showLockPill = options.showLockPill !== false;
          const section = getRowSection(row);
          const isOutsideCapacity = Boolean(row?.is_outside_capacity);
          const sectionClass = section === 'scc'
            ? 'ranking-scc-row'
            : (section === 'etg' ? 'ranking-etg-row' : '');
          const rowClass = [
            sectionClass,
            isOutsideCapacity ? 'ranking-outside-capacity' : '',
            Boolean(row?.is_locked) ? 'ranking-locked-row' : ''
          ].filter(Boolean).join(' ');
          const classificationText = section === 'scc'
            ? 'SCC'
            : (section === 'etg' ? 'ETG' : 'R');

          const lockPill = showLockPill && Boolean(row?.is_locked)
            ? `
                <span class="ranking-lock-pill" title="Locked" aria-label="Locked">
                  <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                    <path d="M17 8h-1V6a4 4 0 0 0-8 0v2H7a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-8a2 2 0 0 0-2-2Zm-7-2a2 2 0 1 1 4 0v2h-4V6Zm7 12H7v-8h10v8Z"></path>
                  </svg>
                </span>
              `
            : '';

          return `
            <div class="ranking-list-row ${rowClass}">
              <div class="ranking-col-no"><span class="fw-semibold">${sequenceDisplay}</span>${lockPill}</div>
              <div class="ranking-col-rank"><span class="fw-semibold">${rankDisplay}</span></div>
              <div class="ranking-col-examinee">${escapeHtml(row.examinee_number || '')}</div>
              <div class="ranking-col-name">${escapeHtml(row.full_name || '')}</div>
              <div class="ranking-col-class">${escapeHtml(classificationText)}</div>
              <div class="ranking-col-sat">${escapeHtml(row.sat_score ?? '')}</div>
              <div class="ranking-col-score">${escapeHtml(row.final_score ?? '')}</div>
            </div>
          `;
        }

        function buildRankingListHeaderHtml() {
          return `
            <div class="ranking-list-header">
              <div>No.</div>
              <div>Rank</div>
              <div>Examinee #</div>
              <div>Student Name</div>
              <div>Class</div>
              <div>SAT</div>
              <div>Score</div>
            </div>
          `;
        }

        function renderRankingRows(rows) {
          if (!listEl) {
            return { regularCount: 0, endorsementCount: 0, etgCount: 0 };
          }

          const orderedRows = Array.isArray(rows) ? rows : [];
          if (!orderedRows.length) {
            listEl.innerHTML = `${buildRankingListHeaderHtml()}<div class="ranking-list-empty">No ranked students.</div>`;
            return { regularCount: 0, endorsementCount: 0, etgCount: 0 };
          }

          const grouped = { regularCount: 0, endorsementCount: 0, etgCount: 0 };
          let html = buildRankingListHeaderHtml();
          orderedRows.forEach((row, index) => {
            const section = getRowSection(row);
            if (section === 'scc') grouped.endorsementCount++;
            else if (section === 'etg') grouped.etgCount++;
            else grouped.regularCount++;

            const sequenceDisplay = Number(row?.sequence_no ?? 0) > 0 ? Number(row.sequence_no) : (index + 1);
            const rankDisplay = Number(row?.rank ?? 0) > 0 ? Number(row.rank) : (index + 1);
            html += buildRankingRowHtml(row, sequenceDisplay, rankDisplay);
          });

          listEl.innerHTML = html;
          return grouped;
        }

        function buildRankingMeta(grouped, quota) {
          const regularCount = Number(grouped?.regularCount ?? 0);
          const endorsementCount = Number(grouped?.endorsementCount ?? 0);
          const etgCount = Number(grouped?.etgCount ?? 0);
          const total = regularCount + endorsementCount + etgCount;

          if (!quota || quota.enabled !== true) {
            return `${total} ranked student${total === 1 ? '' : 's'} | REGULAR: ${regularCount} | SCC: ${endorsementCount} | ETG: ${etgCount}`;
          }

          const regularSlots = Math.max(0, Number(quota.regular_effective_slots ?? quota.regular_slots ?? 0));
          const etgSlots = Math.max(0, Number(quota.etg_slots ?? 0));
          const sccSlots = Math.max(0, Number(quota.endorsement_capacity ?? 0));
          const regularUsed = Math.min(regularCount, regularSlots);
          const endorsementUsed = Math.min(endorsementCount, sccSlots);
          const etgUsed = Math.min(etgCount, etgSlots);

          return `Capacity: REGULAR: ${regularUsed}/${regularSlots} | SCC: ${endorsementUsed}/${sccSlots} | ETG: ${etgUsed}/${etgSlots}`;
        }

        function toggleEndorsement(interviewId, action) {
          if (!currentProgramId || !interviewId) {
            return Promise.resolve();
          }

          const formData = new URLSearchParams();
          formData.set('program_id', String(currentProgramId));
          formData.set('interview_id', String(interviewId));
          formData.set('action', String(action || '').toUpperCase());

          return fetch(toggleEndorsementEndpoint, {
            method: 'POST',
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
              Accept: 'application/json'
            },
            body: formData.toString()
          })
            .then((res) => res.json())
            .then((data) => {
              if (!data || !data.success) {
                throw new Error((data && data.message) || 'Failed to update SCC list.');
              }

              return data;
            });
        }

        function openAddEcPicker(sourceType) {
          const targetType = String(sourceType || '').toUpperCase();
          if (targetType !== 'REGULAR') {
            fireMonitoringRankingSwal('Unavailable', 'Only Regular students can be added to SCC from this action.', 'info');
            return;
          }

          const ecCapacity = Math.max(0, Number(currentQuota?.endorsement_capacity ?? 0));
          const ecSelected = Math.max(0, Number(currentQuota?.endorsement_selected ?? 0));
          const ecRemaining = Math.max(0, ecCapacity - ecSelected);

          if (ecCapacity <= 0) {
            fireMonitoringRankingSwal('SCC Capacity', 'SCC capacity is 0. Configure SCC capacity first.', 'info');
            return;
          }

          if (ecRemaining <= 0) {
            fireMonitoringRankingSwal('SCC Full', 'SCC capacity is full.', 'info');
            return;
          }

          if (typeof Swal === 'undefined' || typeof $ === 'undefined' || !$.fn || !$.fn.select2) {
            fireMonitoringRankingSwal('Unavailable', 'SCC picker dependencies are not available on this page.', 'error');
            return;
          }

          let pickerSelect = null;

          Swal.fire(getMonitoringRankingSwalOptions({
            title: 'Add SCC (Regular)',
            html: `
              <div class="scc-picker-wrap">
                <label for="monitoringSccRegularPicker" class="scc-picker-label">Select student</label>
                <select id="monitoringSccRegularPicker"></select>
                <div class="scc-picker-help">
                  All individuals selected under SCC are required to participate in the program interview.
                </div>
              </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add SCC',
            cancelButtonText: 'Cancel',
            focusConfirm: false,
            didOpen: () => {
              const selectEl = document.getElementById('monitoringSccRegularPicker');
              if (!selectEl) {
                return;
              }

              pickerSelect = $(selectEl);
              pickerSelect.select2({
                width: '100%',
                dropdownParent: $(Swal.getPopup()),
                placeholder: 'Search examinee # or student name',
                allowClear: true,
                minimumInputLength: 0,
                ajax: {
                  url: sccCandidatesEndpoint,
                  dataType: 'json',
                  delay: 250,
                  data: (params) => ({
                    program_id: currentProgramId,
                    q: params.term || '',
                    page: params.page || 1
                  }),
                  processResults: (data) => {
                    const results = (data && data.success && Array.isArray(data.results)) ? data.results : [];
                    return {
                      results,
                      pagination: {
                        more: Boolean(data?.pagination?.more)
                      }
                    };
                  }
                },
                language: {
                  noResults: () => 'No outside-ranked regular candidate found.',
                  searching: () => 'Searching...'
                }
              });

              pickerSelect.on('select2:open', () => {
                const searchField = document.querySelector('.select2-container--open .select2-search__field');
                if (searchField) {
                  searchField.focus();
                }
              });

              pickerSelect.select2('open');
            },
            willClose: () => {
              if (pickerSelect && pickerSelect.data('select2')) {
                pickerSelect.select2('destroy');
              }
              pickerSelect = null;
            },
            preConfirm: () => {
              const selectedValue = pickerSelect && pickerSelect.length ? pickerSelect.val() : '';
              const interviewId = Number(selectedValue || 0);
              if (!interviewId) {
                Swal.showValidationMessage('Please select a student.');
                return false;
              }

              return interviewId;
            }
          })).then((result) => {
            if (!result.isConfirmed || !result.value) {
              return;
            }

            const interviewId = Number(result.value || 0);
            if (!interviewId) {
              return;
            }

            toggleEndorsement(interviewId, 'ADD')
              .then((data) => {
                fireMonitoringRankingSwal('Added', data.message || 'Student added to SCC list.', 'success');
                loadProgramRanking(currentProgramId, currentProgramName);
              })
              .catch((error) => {
                fireMonitoringRankingSwal('Error', error.message || 'Failed to add SCC.', 'error');
              });
          });
        }

        async function loadProgramRanking(programId, programName) {
          currentProgramId = Number(programId || 0);
          currentProgramName = String(programName || 'PROGRAM');
          currentRows = [];
          currentQuota = null;
          currentLocks = { active_count: 0, ranges: [] };
          updateLockSummary();
          refreshActionButtons();

          if (titleEl) titleEl.textContent = `Program Ranking - ${currentProgramName}`;
          if (metaEl) metaEl.textContent = 'Loading...';
          setLockStatus('');
          setState({ loading: true, empty: false, showTable: false });
          rankingModal.show();

          try {
            const response = await fetch(`get_program_ranking.php?program_id=${encodeURIComponent(String(currentProgramId || 0))}`, {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (!data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Failed to load ranking.');
            }

            currentRows = Array.isArray(data.rows) ? data.rows : [];
            currentQuota = data && typeof data.quota === 'object' ? data.quota : null;
            currentLocks = data && typeof data.locks === 'object'
              ? data.locks
              : { active_count: 0, ranges: [] };
            updateLockSummary();
            refreshActionButtons();

            if (currentRows.length === 0) {
              if (emptyEl && currentQuota && currentQuota.enabled === true) {
                const capacity = Number(currentQuota.absorptive_capacity ?? 0);
                emptyEl.textContent = capacity <= 0
                  ? 'No ranking shown because absorptive capacity is set to 0.'
                  : 'No ranked students found for this program.';
              }
              setState({ loading: false, empty: true, showTable: false });
              return;
            }

            const grouped = renderRankingRows(currentRows);
            if (metaEl) {
              metaEl.textContent = buildRankingMeta(grouped, currentQuota);
            }
            setState({ loading: false, empty: false, showTable: true });
          } catch (error) {
            refreshActionButtons();
            if (emptyEl) {
              emptyEl.textContent = (error && error.message) ? error.message : 'Failed to load ranking.';
            }
            setState({ loading: false, empty: true, showTable: false });
          }
        }

        async function applyLockAction(action) {
          if (!currentProgramId) return;

          const params = new URLSearchParams();
          params.set('action', action);
          params.set('program_id', String(currentProgramId));

          if (action === 'lock_range' || action === 'unlock_range') {
            const startRank = Number(lockFromEl?.value || 0);
            const endRank = Number(lockToEl?.value || 0);
            if (startRank <= 0 || endRank <= 0) {
              setLockStatus('Enter both number values first.', true);
              return;
            }
            params.set('start_rank', String(startRank));
            params.set('end_rank', String(endRank));
          }

          setLockStatus('Applying lock action...');
          try {
            const response = await fetch('ranking_lock_action.php', {
              method: 'POST',
              headers: {
                'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                Accept: 'application/json'
              },
              body: params.toString()
            });
            const data = await response.json();
            if (!response.ok || !data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Lock action failed.');
            }

            const statusMessage = String(data.message || 'Lock action completed.');
            await loadProgramRanking(currentProgramId, currentProgramName);
            setLockStatus(statusMessage, false);
          } catch (error) {
            setLockStatus((error && error.message) ? error.message : 'Lock action failed.', true);
          }
        }

        function getRowsForPrint(scope) {
          const normalizedScope = String(scope || 'all').toLowerCase();
          if (normalizedScope === 'locked') {
            return currentRows.filter((row) => Boolean(row?.is_locked));
          }
          if (normalizedScope === 'inside') {
            return currentRows.filter((row) => !Boolean(row?.is_outside_capacity));
          }
          return currentRows;
        }

        function buildRankingHtmlForRows(rows) {
          if (!Array.isArray(rows) || !rows.length) {
            return `${buildRankingListHeaderHtml()}<div class="ranking-list-empty">No rows match the selected print filter.</div>`;
          }
          let html = buildRankingListHeaderHtml();
          rows.forEach((row, index) => {
            const sequenceDisplay = Number(row?.sequence_no ?? 0) > 0 ? Number(row.sequence_no) : (index + 1);
            const rankDisplay = Number(row?.rank ?? 0) > 0 ? Number(row.rank) : (index + 1);
            html += buildRankingRowHtml(row, sequenceDisplay, rankDisplay, { showLockPill: false });
          });
          return html;
        }

        function printCurrentRanking() {
          if (!currentRows.length) return;

          const scope = String(printScopeEl?.value || 'all').toLowerCase();
          const scopeLabel = scope === 'locked'
            ? 'Locked Only'
            : (scope === 'inside' ? 'Inside Capacity Only' : 'All in List');
          const rowsForPrint = getRowsForPrint(scope);
          const rankingHtml = buildRankingHtmlForRows(rowsForPrint);
          const metaText = metaEl ? metaEl.textContent : '';

          const printWindow = window.open('', '_blank', 'width=1200,height=900');
          if (!printWindow) return;

          printWindow.document.write(`<!doctype html>
<html>
<head>
  <meta charset="utf-8">
  <title>Program Ranking - ${escapeHtml(currentProgramName)}</title>
  <style>
    body { font-family: Arial, sans-serif; padding: 20px; color: #111827; }
    h1 { margin: 0 0 6px; font-size: 22px; }
    .meta { margin-bottom: 14px; color: #475569; font-size: 13px; }
    .ranking-list { border: 1px solid #e5e7eb; border-radius: 8px; overflow: hidden; }
    .ranking-list-header, .ranking-list-row {
      display: grid;
      grid-template-columns: 58px 70px 110px minmax(260px, 1fr) 80px 80px 100px;
      align-items: center;
    }
    .ranking-list-header {
      background: #f8fafc;
      border-bottom: 1px solid #e5e7eb;
      font-size: 12px;
      font-weight: 700;
      text-transform: uppercase;
      color: #475569;
    }
    .ranking-list-row { border-top: 1px solid #eef2f7; font-size: 14px; }
    .ranking-list-header > div, .ranking-list-row > div { padding: 8px 10px; }
    .ranking-col-name { text-transform: uppercase; font-weight: 600; }
    .ranking-scc-row { color: #15803d !important; }
    .ranking-etg-row { color: #2563eb !important; }
    .ranking-outside-capacity { color: #dc2626 !important; }
    .ranking-locked-row { background: #fffbeb; }
    .ranking-lock-pill {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 1.4rem;
      height: 1.15rem;
      margin-left: 0.35rem;
      padding: 0;
      border-radius: 999px;
      border: 1px solid #fcd34d;
      background: #fef3c7;
      color: #92400e;
      vertical-align: middle;
    }
    .ranking-lock-pill svg {
      width: 0.72rem;
      height: 0.72rem;
      display: block;
      fill: currentColor;
    }
    .ranking-list-empty { padding: 12px; color: #64748b; font-size: 13px; }
    @media print { @page { size: landscape; margin: 10mm; } }
  </style>
</head>
<body>
  <h1>Program Ranking - ${escapeHtml(currentProgramName)}</h1>
  <div class="meta">${escapeHtml(metaText || '')} | Print Filter: ${escapeHtml(scopeLabel)}</div>
  <div class="ranking-list">${rankingHtml}</div>
</body>
</html>`);
          printWindow.document.close();
          printWindow.focus();
          printWindow.print();
          printWindow.close();
        }

        document.addEventListener('click', (event) => {
          const button = event.target.closest('.js-open-ranking');
          if (!button) return;
          const programId = Number(button.getAttribute('data-program-id') || 0);
          const programName = String(button.getAttribute('data-program-name') || '').trim();
          if (programId <= 0) return;
          loadProgramRanking(programId, programName);
        });

        if (lockRangeBtn) {
          lockRangeBtn.addEventListener('click', () => applyLockAction('lock_range'));
        }
        if (unlockRangeBtn) {
          unlockRangeBtn.addEventListener('click', () => applyLockAction('unlock_range'));
        }
        if (unlockAllBtn) {
          unlockAllBtn.addEventListener('click', () => applyLockAction('unlock_all'));
        }
        if (addSccRegularBtn) {
          addSccRegularBtn.addEventListener('click', () => openAddEcPicker('REGULAR'));
        }
        if (printBtn) {
          printBtn.addEventListener('click', printCurrentRanking);
        }
      })();
    </script>
  </body>
</html>
