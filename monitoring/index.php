<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'monitoring')) {
    header('Location: ../index.php');
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$campusFilter = (int) ($_GET['campus_id'] ?? 0);

$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffRanges = is_array($globalSatCutoffState['ranges'] ?? null) ? $globalSatCutoffState['ranges'] : [];
$globalSatCutoffRangeText = trim((string) ($globalSatCutoffState['range_text'] ?? ''));
$globalSatCutoffActive = $globalSatCutoffEnabled && (!empty($globalSatCutoffRanges) || isset($globalSatCutoffState['value']));

if ($globalSatCutoffActive && $globalSatCutoffRangeText === '') {
    $globalSatCutoffRangeText = format_sat_cutoff_ranges_for_display($globalSatCutoffRanges, ', ');
}

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

$programSql = "
    SELECT
        p.program_id,
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
        COALESCE(st.unscored_count, 0) AS unscored_count
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
    WHERE " . implode(' AND ', $where) . "
    ORDER BY cam.campus_name ASC, col.college_name ASC, p.program_name ASC, p.major ASC
";

$programs = [];
$stmtProgram = $conn->prepare($programSql);
if ($stmtProgram) {
    if ($types !== '') {
        $stmtProgram->bind_param($types, ...$params);
    }
    $stmtProgram->execute();
    $programResult = $stmtProgram->get_result();
    while ($programRow = $programResult->fetch_assoc()) {
        $programs[] = $programRow;
    }
    $stmtProgram->close();
}

$summary = [
    'total_programs' => count($programs),
    'total_scored' => 0,
    'total_unscored' => 0,
    'total_interviewed' => 0
];

foreach ($programs as $programRow) {
    $summary['total_scored'] += (int) ($programRow['scored_count'] ?? 0);
    $summary['total_unscored'] += (int) ($programRow['unscored_count'] ?? 0);
    $summary['total_interviewed'] += (int) ($programRow['total_interviewed'] ?? 0);
}

$pendingTransfers = 0;
$pendingTransferResult = $conn->query("SELECT COUNT(*) AS total FROM tbl_student_transfer_history WHERE status = 'pending'");
if ($pendingTransferResult) {
    $pendingTransfers = (int) (($pendingTransferResult->fetch_assoc()['total'] ?? 0));
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

      .mn-table td, .mn-table th {
        vertical-align: middle;
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
        grid-template-columns: 70px 110px minmax(260px, 1fr) 80px 80px 100px;
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
                Unified monitoring view for all active programs. Ranking order is aligned with Program Chair, Student, and Administrator logic.
              </p>

              <?php if ($globalSatCutoffActive): ?>
                <div class="alert alert-info py-2 mb-3">
                  Global SAT cutoff is active<?= $globalSatCutoffRangeText !== '' ? ': SAT range ' . htmlspecialchars($globalSatCutoffRangeText) : '.'; ?>
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
                      <label class="form-label mb-1">Search Program / College / Campus</label>
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
                  <div class="table-responsive">
                    <table class="table table-sm mn-table">
                      <thead>
                        <tr>
                          <th>Program</th>
                          <th>Campus / College</th>
                          <th class="text-center">Cutoff</th>
                          <th class="text-center">Scored</th>
                          <th class="text-center">Unscored</th>
                          <th class="text-center">Action</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php if (empty($programs)): ?>
                          <tr>
                            <td colspan="6" class="text-center text-muted py-4">No programs found.</td>
                          </tr>
                        <?php else: ?>
                          <?php foreach ($programs as $program): ?>
                            <?php
                              $programName = trim((string) ($program['program_name'] ?? ''));
                              $major = trim((string) ($program['major'] ?? ''));
                              $programLabel = $programName . ($major !== '' ? ' - ' . $major : '');
                              $cutoffScore = $program['cutoff_score'];
                            ?>
                            <tr>
                              <td>
                                <div class="fw-semibold"><?= htmlspecialchars($programLabel); ?></div>
                                <small class="text-muted">Program ID: <?= (int) ($program['program_id'] ?? 0); ?></small>
                              </td>
                              <td>
                                <div><?= htmlspecialchars((string) ($program['campus_name'] ?? '')); ?></div>
                                <small class="text-muted"><?= htmlspecialchars((string) ($program['college_name'] ?? '')); ?></small>
                              </td>
                              <td class="text-center">
                                <?php if ($cutoffScore !== null && $cutoffScore !== ''): ?>
                                  <span class="badge bg-label-success"><?= number_format((int) $cutoffScore); ?></span>
                                <?php else: ?>
                                  <span class="badge bg-label-danger">Not Set</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-center"><span class="badge bg-label-success"><?= number_format((int) ($program['scored_count'] ?? 0)); ?></span></td>
                              <td class="text-center"><span class="badge bg-label-warning"><?= number_format((int) ($program['unscored_count'] ?? 0)); ?></span></td>
                              <td class="text-center">
                                <button
                                  type="button"
                                  class="btn btn-sm btn-outline-primary js-open-ranking"
                                  data-program-id="<?= (int) ($program['program_id'] ?? 0); ?>"
                                  data-program-name="<?= htmlspecialchars(strtoupper($programLabel), ENT_QUOTES); ?>"
                                >
                                  View Ranking
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
                    <div class="d-flex justify-content-end gap-2 mb-3">
                      <button type="button" class="btn btn-outline-secondary" id="monitoringPrintBtn">
                        <i class="bx bx-printer me-1"></i> Print Ranking
                      </button>
                    </div>

                    <div id="monitoringRankingLoading" class="text-center py-4 d-none">
                      <div class="spinner-border text-primary" role="status"></div>
                      <div class="small text-muted mt-2">Loading ranking...</div>
                    </div>

                    <div id="monitoringRankingEmpty" class="alert alert-warning d-none mb-0">
                      No ranked students found for this program.
                    </div>

                    <div class="d-none" id="monitoringRankingTableWrap">
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
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
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

        let currentProgramName = '';
        let currentQuota = null;
        let currentRows = [];

        function setState({ loading = false, empty = false, showTable = false }) {
          if (loadingEl) loadingEl.classList.toggle('d-none', !loading);
          if (emptyEl) emptyEl.classList.toggle('d-none', !empty);
          if (tableWrapEl) tableWrapEl.classList.toggle('d-none', !showTable);
        }

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function toSortableScore(value) {
          const parsed = Number.parseFloat(value);
          return Number.isFinite(parsed) ? parsed : 0;
        }

        function sortRankingRows(rows) {
          return [...rows].sort((a, b) => {
            const scoreDiff = toSortableScore(b.final_score) - toSortableScore(a.final_score);
            if (scoreDiff !== 0) return scoreDiff;

            const satDiff = Number(b.sat_score ?? 0) - Number(a.sat_score ?? 0);
            if (satDiff !== 0) return satDiff;

            return String(a.full_name || '').localeCompare(String(b.full_name || ''));
          });
        }

        function isRegularClassification(row) {
          return String(row?.classification || '').toUpperCase() === 'REGULAR';
        }

        function splitRowsByCapacity(rows, limit, quotaEnabled) {
          if (!quotaEnabled) {
            return {
              insideRows: rows,
              outsideRows: []
            };
          }

          const safeLimit = Math.max(0, Number(limit ?? 0));
          return {
            insideRows: rows.slice(0, safeLimit),
            outsideRows: rows.slice(safeLimit)
          };
        }

        function buildRankingRowHtml(row, rankDisplay, { section = 'regular', isOutsideCapacity = false } = {}) {
          const sectionClass = section === 'scc'
            ? 'ranking-scc-row'
            : (section === 'etg' ? 'ranking-etg-row' : '');
          const rowClass = [sectionClass, isOutsideCapacity ? 'ranking-outside-capacity' : '']
            .filter(Boolean)
            .join(' ');
          const classificationText = section === 'scc'
            ? 'SCC'
            : (section === 'etg' ? 'ETG' : 'R');

          return `
            <div class="ranking-list-row ${rowClass}">
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
              <div>Rank</div>
              <div>Examinee #</div>
              <div>Student Name</div>
              <div>Class</div>
              <div>SAT</div>
              <div>Score</div>
            </div>
          `;
        }

        function renderCapacityOrderedTable(regularRows, endorsementRows, etgRows) {
          if (!listEl) return;

          if (!regularRows.length && !endorsementRows.length && !etgRows.length) {
            listEl.innerHTML = `${buildRankingListHeaderHtml()}<div class="ranking-list-empty">No ranked students.</div>`;
            return;
          }

          const quotaEnabled = Boolean(currentQuota && currentQuota.enabled === true);
          const regularLimit = Math.max(0, Number(currentQuota?.regular_slots ?? 0));
          const endorsementLimit = Math.max(0, Number(currentQuota?.endorsement_capacity ?? 0));
          const etgLimit = Math.max(0, Number(currentQuota?.etg_slots ?? 0));

          const regularSplit = splitRowsByCapacity(regularRows, regularLimit, quotaEnabled);
          const endorsementSplit = splitRowsByCapacity(endorsementRows, endorsementLimit, quotaEnabled);
          const etgSplit = splitRowsByCapacity(etgRows, etgLimit, quotaEnabled);

          const orderedRows = [
            ...regularSplit.insideRows.map((row) => ({ row, section: 'regular', isOutsideCapacity: false })),
            ...endorsementSplit.insideRows.map((row) => ({ row, section: 'scc', isOutsideCapacity: false })),
            ...etgSplit.insideRows.map((row) => ({ row, section: 'etg', isOutsideCapacity: false })),
            ...regularSplit.outsideRows.map((row) => ({ row, section: 'regular', isOutsideCapacity: true })),
            ...endorsementSplit.outsideRows.map((row) => ({ row, section: 'scc', isOutsideCapacity: true })),
            ...etgSplit.outsideRows.map((row) => ({ row, section: 'etg', isOutsideCapacity: true }))
          ];

          let html = buildRankingListHeaderHtml();
          orderedRows.forEach((entry, index) => {
            html += buildRankingRowHtml(entry.row, index + 1, {
              section: entry.section,
              isOutsideCapacity: entry.isOutsideCapacity
            });
          });

          listEl.innerHTML = html;
        }

        function buildRankingMeta(grouped, quota) {
          const regularCount = Number(grouped?.regularCount ?? 0);
          const endorsementCount = Number(grouped?.endorsementCount ?? 0);
          const etgCount = Number(grouped?.etgCount ?? 0);
          const total = regularCount + endorsementCount + etgCount;

          if (!quota || quota.enabled !== true) {
            return `${total} ranked student${total === 1 ? '' : 's'} | REGULAR: ${regularCount} | SCC: ${endorsementCount} | ETG: ${etgCount}`;
          }

          const regularSlots = Math.max(0, Number(quota.regular_slots ?? 0));
          const etgSlots = Math.max(0, Number(quota.etg_slots ?? 0));
          const sccSlots = Math.max(0, Number(quota.endorsement_capacity ?? 0));
          const regularUsed = Math.min(regularCount, regularSlots);
          const endorsementUsed = Math.min(endorsementCount, sccSlots);
          const etgUsed = Math.min(etgCount, etgSlots);

          return `Capacity: REGULAR: ${regularUsed}/${regularSlots} | SCC: ${endorsementUsed}/${sccSlots} | ETG: ${etgUsed}/${etgSlots}`;
        }

        function renderRankingRows(rows) {
          const regularRows = sortRankingRows(
            rows.filter((row) => isRegularClassification(row) && !row.is_endorsement)
          );

          const endorsementRows = [...rows.filter((row) => Boolean(row.is_endorsement))]
            .sort((a, b) => {
              const timeA = new Date(a.endorsement_order || 0).getTime();
              const timeB = new Date(b.endorsement_order || 0).getTime();
              if (timeA !== timeB) return timeA - timeB;
              return String(a.full_name || '').localeCompare(String(b.full_name || ''));
            });

          const etgRows = sortRankingRows(
            rows.filter((row) => !isRegularClassification(row) && !row.is_endorsement)
          );

          renderCapacityOrderedTable(regularRows, endorsementRows, etgRows);
          return {
            regularCount: regularRows.length,
            endorsementCount: endorsementRows.length,
            etgCount: etgRows.length
          };
        }

        async function loadProgramRanking(programId, programName) {
          currentProgramName = String(programName || 'PROGRAM');
          currentRows = [];
          currentQuota = null;

          if (titleEl) titleEl.textContent = `Program Ranking - ${currentProgramName}`;
          if (metaEl) metaEl.textContent = 'Loading...';
          setState({ loading: true, empty: false, showTable: false });
          rankingModal.show();

          try {
            const response = await fetch(`get_program_ranking.php?program_id=${encodeURIComponent(String(programId || 0))}`, {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();
            if (!data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Failed to load ranking.');
            }

            currentRows = Array.isArray(data.rows) ? data.rows : [];
            currentQuota = data && typeof data.quota === 'object' ? data.quota : null;

            const groupedCounts = {
              regularCount: currentRows.filter((row) => String(row.classification || '').toUpperCase() === 'REGULAR' && !row.is_endorsement).length,
              endorsementCount: currentRows.filter((row) => Boolean(row.is_endorsement)).length,
              etgCount: currentRows.filter((row) => String(row.classification || '').toUpperCase() !== 'REGULAR' && !row.is_endorsement).length
            };

            if (metaEl) {
              metaEl.textContent = buildRankingMeta(groupedCounts, currentQuota);
            }

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
            if (emptyEl) {
              emptyEl.textContent = (error && error.message) ? error.message : 'Failed to load ranking.';
            }
            setState({ loading: false, empty: true, showTable: false });
          }
        }

        function printCurrentRanking() {
          if (!currentRows.length || !listEl) {
            return;
          }

          const printWindow = window.open('', '_blank', 'width=1200,height=900');
          if (!printWindow) {
            return;
          }

          const metaText = metaEl ? metaEl.textContent : '';
          const rankingHtml = listEl.innerHTML;

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
      grid-template-columns: 70px 110px minmax(280px, 1fr) 80px 80px 100px;
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
    .ranking-list-empty { padding: 12px; color: #64748b; font-size: 13px; }
    @media print { @page { size: landscape; margin: 10mm; } }
  </style>
</head>
<body>
  <h1>Program Ranking - ${escapeHtml(currentProgramName)}</h1>
  <div class="meta">${escapeHtml(metaText || '')}</div>
  <div class="ranking-list">${rankingHtml}</div>
</body>
</html>`);
          printWindow.document.close();
          printWindow.focus();
          printWindow.print();
          printWindow.close();
        }

        document.querySelectorAll('.js-open-ranking').forEach((button) => {
          button.addEventListener('click', () => {
            const programId = Number(button.getAttribute('data-program-id') || 0);
            const programName = String(button.getAttribute('data-program-name') || '').trim();
            if (programId <= 0) return;
            loadProgramRanking(programId, programName);
          });
        });

        if (printBtn) {
          printBtn.addEventListener('click', printCurrentRanking);
        }
      })();
    </script>
  </body>
</html>
