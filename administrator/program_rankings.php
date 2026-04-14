<?php
require_once '../config/db.php';
require_once '../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$selectedProgramId = (int) ($_GET['program_id'] ?? 0);

$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffRanges = is_array($globalSatCutoffState['ranges'] ?? null) ? $globalSatCutoffState['ranges'] : [];
$globalSatCutoffRangeText = trim((string) ($globalSatCutoffState['range_text'] ?? ''));
$globalSatCutoffActive = $globalSatCutoffEnabled && (!empty($globalSatCutoffRanges) || isset($globalSatCutoffState['value']));

if ($globalSatCutoffActive && $globalSatCutoffRangeText === '') {
    $globalSatCutoffRangeText = format_sat_cutoff_ranges_for_display($globalSatCutoffRanges, ', ');
}

$programOptionsByCampus = [];
$programOptionIndex = [];
$programSql = "
    SELECT
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        col.college_name,
        cam.campus_name
    FROM tbl_program p
    INNER JOIN tbl_college col
        ON col.college_id = p.college_id
    INNER JOIN tbl_campus cam
        ON cam.campus_id = col.campus_id
    WHERE p.status = 'active'
      AND col.status = 'active'
      AND cam.status = 'active'
    ORDER BY
        cam.campus_name ASC,
        col.college_name ASC,
        p.program_name ASC,
        p.major ASC
";

$programResult = $conn->query($programSql);
if ($programResult) {
    while ($row = $programResult->fetch_assoc()) {
        $programId = (int) ($row['program_id'] ?? 0);
        if ($programId <= 0) {
            continue;
        }

        $programCode = trim((string) ($row['program_code'] ?? ''));
        $programName = trim((string) ($row['program_name'] ?? ''));
        $major = trim((string) ($row['major'] ?? ''));
        $collegeName = trim((string) ($row['college_name'] ?? ''));
        $campusName = trim((string) ($row['campus_name'] ?? ''));

        $displayName = $programName;
        if ($major !== '') {
            $displayName .= ' - ' . $major;
        }
        if ($programCode !== '') {
            $displayName = $programCode . ' - ' . $displayName;
        }

        $titleName = strtoupper($programName . ($major !== '' ? ' - ' . $major : ''));
        $optionLabel = $collegeName !== ''
            ? ($collegeName . ' / ' . $displayName)
            : $displayName;

        $programOption = [
            'program_id' => $programId,
            'program_code' => $programCode,
            'program_name' => $programName,
            'major' => $major,
            'college_name' => $collegeName,
            'campus_name' => $campusName,
            'display_name' => $displayName,
            'title_name' => $titleName,
            'option_label' => $optionLabel,
        ];

        if (!isset($programOptionsByCampus[$campusName])) {
            $programOptionsByCampus[$campusName] = [];
        }

        $programOptionsByCampus[$campusName][] = $programOption;
        $programOptionIndex[(string) $programId] = $programOption;
    }
}

if (!isset($programOptionIndex[(string) $selectedProgramId])) {
    $selectedProgramId = 0;
}

$totalPrograms = count($programOptionIndex);
$totalCampuses = count($programOptionsByCampus);
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
    <title>Administrator Program Rankings - Interview</title>

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
      .admin-ranking-summary {
        display: flex;
        flex-wrap: wrap;
        gap: 0.5rem;
      }

      .admin-ranking-summary--end {
        justify-content: flex-start;
      }

      .admin-ranking-chip {
        display: inline-flex;
        align-items: center;
        min-height: 2rem;
        padding: 0.3rem 0.7rem;
        border-radius: 999px;
        border: 1px solid #dfe5ee;
        background: #f8fafc;
        color: #526277;
        font-size: 0.78rem;
        font-weight: 700;
        line-height: 1.2;
      }

      .admin-ranking-chip--strong {
        background: #eef2ff;
        border-color: #c7d2fe;
        color: #4338ca;
      }

      #adminProgramRankingMeta {
        font-size: 0.92rem;
        font-weight: 600;
      }

      .admin-ranking-placeholder {
        border: 1px dashed #d7deea;
        border-radius: 0.85rem;
        background: #f8fbff;
        padding: 1rem 1.05rem;
        color: #64748b;
        font-size: 0.92rem;
      }

      .admin-ranking-scroll {
        overflow-x: auto;
        overflow-y: visible;
      }

      .ranking-list {
        border: none;
        border-radius: 0;
        background: transparent;
        overflow-x: auto;
        overflow-y: visible;
      }

      .ranking-list-header,
      .ranking-list-row {
        display: grid;
        grid-template-columns: 58px 70px 110px minmax(220px, 1fr) 80px 80px 100px 116px;
        gap: 0.65rem;
        align-items: center;
        padding: 0.18rem 0;
        min-width: 900px;
      }

      .ranking-list-header {
        background: transparent;
        border-bottom: none;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        color: #111827;
        text-transform: uppercase;
        margin-bottom: 0.28rem;
      }

      .ranking-list-row {
        border-top: none;
        font-size: 0.92rem;
        color: #111827;
      }

      .ranking-list-row .ranking-col-name {
        text-transform: uppercase;
      }

      .ranking-list-row .ranking-col-class {
        font-weight: 500;
      }

      .ranking-list-row .ranking-col-score {
        font-weight: 500;
        color: #111827;
      }

      .ranking-col-actions {
        display: flex;
        justify-content: flex-end;
      }

      .ranking-col-actions .btn {
        white-space: nowrap;
      }

      .ranking-list-empty {
        padding: 0.45rem 0;
        font-size: 0.82rem;
        color: #64748b;
      }

      .ranking-scc-row {
        color: #15803d !important;
      }

      .ranking-scc-row .ranking-col-score {
        color: #15803d !important;
      }

      .ranking-etg-row {
        color: #2563eb !important;
      }

      .ranking-etg-row .ranking-col-score {
        color: #2563eb !important;
      }

      .ranking-outside-capacity {
        color: #dc2626 !important;
      }

      .ranking-outside-capacity .ranking-col-score {
        color: #dc2626 !important;
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

      #adminLockStatus {
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

      @media (max-width: 991.98px) {
        .ranking-list-header,
        .ranking-list-row {
          grid-template-columns: 52px 64px 92px minmax(160px, 1.4fr) 72px 60px 72px 108px;
          gap: 0.45rem;
          padding: 0.14rem 0;
          font-size: 0.8rem;
          min-width: 860px;
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
                <span class="text-muted fw-light">Administrator /</span> Program Rankings
              </h4>
              <p class="text-muted mb-4">
                Select any active program to view the same ranked student list shown in the Program Chair program ranking modal.
              </p>

              <?php if ($globalSatCutoffActive): ?>
                <div class="alert alert-info py-2 mb-4">
                  Global cutoff is active<?= $globalSatCutoffRangeText !== '' ? ': preferred-program basis range ' . htmlspecialchars($globalSatCutoffRangeText) : '.'; ?>
                </div>
              <?php endif; ?>

              <div class="card mb-4">
                <div class="card-body">
                  <div class="row g-3 align-items-end">
                    <div class="col-lg-7">
                      <label class="form-label mb-1" for="adminProgramSelect">Program</label>
                      <select id="adminProgramSelect" class="form-select">
                        <option value="">Select a program</option>
                        <?php foreach ($programOptionsByCampus as $campusName => $campusPrograms): ?>
                          <optgroup label="<?= htmlspecialchars($campusName); ?>">
                            <?php foreach ($campusPrograms as $programOption): ?>
                              <?php $optionProgramId = (int) ($programOption['program_id'] ?? 0); ?>
                              <option value="<?= $optionProgramId; ?>"<?= $selectedProgramId === $optionProgramId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($programOption['option_label'] ?? 'Program')); ?>
                              </option>
                            <?php endforeach; ?>
                          </optgroup>
                        <?php endforeach; ?>
                      </select>
                      <small class="text-muted d-block mt-2">
                        Only active programs are listed. Rankings use the same shared ordering and row styling shown in the Program Chair modal.
                      </small>
                    </div>
                    <div class="col-lg-5">
                      <div class="admin-ranking-summary">
                        <span class="admin-ranking-chip admin-ranking-chip--strong">
                          Programs: <?= number_format($totalPrograms); ?>
                        </span>
                        <span class="admin-ranking-chip">
                          Campuses: <?= number_format($totalCampuses); ?>
                        </span>
                        <span class="admin-ranking-chip" id="adminRankingCampusChip">
                          Selected Campus: --
                        </span>
                        <span class="admin-ranking-chip" id="adminRankingCollegeChip">
                          Selected College: --
                        </span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="card">
                <div class="card-body">
                  <div class="d-flex flex-column flex-lg-row justify-content-between gap-3 mb-3">
                    <div>
                      <h5 class="card-title mb-1" id="adminProgramRankingTitle">Program Ranking</h5>
                      <small class="text-muted d-block" id="adminProgramRankingMeta">
                        Select a program to load the ranked student list.
                      </small>
                    </div>
                    <div class="admin-ranking-summary admin-ranking-summary--end">
                      <span class="badge bg-label-primary">Program Chair View</span>
                      <span class="badge bg-label-secondary">Administrator View</span>
                    </div>
                  </div>

                  <div class="row g-2 align-items-end mb-2">
                    <div class="col-6 col-md-2">
                      <label class="form-label form-label-sm mb-1 small text-muted" for="adminLockFrom">No. From</label>
                      <input type="number" min="1" class="form-control form-control-sm" id="adminLockFrom" placeholder="1">
                    </div>
                    <div class="col-6 col-md-2">
                      <label class="form-label form-label-sm mb-1 small text-muted" for="adminLockTo">No. To</label>
                      <input type="number" min="1" class="form-control form-control-sm" id="adminLockTo" placeholder="50">
                    </div>
                    <div class="col-12 col-md-auto d-grid">
                      <button type="button" class="btn btn-sm btn-warning" id="adminLockRangeBtn" disabled>
                        <i class="bx bx-lock-alt me-1"></i> Lock Range
                      </button>
                    </div>
                    <div class="col-12 col-md-auto d-grid">
                      <button type="button" class="btn btn-sm btn-outline-warning" id="adminUnlockRangeBtn" disabled>
                        <i class="bx bx-lock-open-alt me-1"></i> Unlock Range
                      </button>
                    </div>
                    <div class="col-12 col-md-auto d-grid">
                      <button type="button" class="btn btn-sm btn-outline-danger" id="adminUnlockAllBtn" disabled>
                        <i class="bx bx-reset me-1"></i> Unlock All
                      </button>
                    </div>
                    <div class="col-12 col-md-auto d-grid">
                      <button type="button" class="btn btn-sm btn-outline-primary" id="adminAddSccRegularBtn" disabled>
                        <i class="bx bx-plus-circle me-1"></i> Add SCC (Regular)
                      </button>
                    </div>
                  </div>

                  <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-3">
                    <div class="small text-muted" id="adminLockSummary">No locked numbers.</div>
                  </div>

                  <div class="small mb-2 d-none" id="adminLockStatus"></div>

                  <div id="adminProgramRankingPlaceholder" class="admin-ranking-placeholder">
                    Select a program above to view ranked students.
                  </div>

                  <div id="adminProgramRankingLoading" class="text-center py-4 d-none">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="small text-muted mt-2">Loading ranking...</div>
                  </div>

                  <div id="adminProgramRankingEmpty" class="alert alert-warning d-none mb-0">
                    No ranked students found for this program.
                  </div>

                  <div class="d-none" id="adminProgramRankingTableWrap">
                    <div class="small text-muted mb-2">
                      <span class="fw-semibold">No.</span> follows Monitoring lock order. <span class="fw-semibold">Rank</span> keeps the academic rank order.
                    </div>
                    <div class="small text-muted mb-2">
                      <span class="fw-semibold text-danger">Red rows</span> are outside capacity but still shown in the ranking list.
                    </div>
                    <div class="admin-ranking-scroll">
                      <div id="adminProgramRankingList" class="ranking-list"></div>
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
    <script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        const programSelectEl = document.getElementById('adminProgramSelect');
        const titleEl = document.getElementById('adminProgramRankingTitle');
        const metaEl = document.getElementById('adminProgramRankingMeta');
        const campusChipEl = document.getElementById('adminRankingCampusChip');
        const collegeChipEl = document.getElementById('adminRankingCollegeChip');
        const placeholderEl = document.getElementById('adminProgramRankingPlaceholder');
        const loadingEl = document.getElementById('adminProgramRankingLoading');
        const emptyEl = document.getElementById('adminProgramRankingEmpty');
        const tableWrapEl = document.getElementById('adminProgramRankingTableWrap');
        const listEl = document.getElementById('adminProgramRankingList');
        const lockFromEl = document.getElementById('adminLockFrom');
        const lockToEl = document.getElementById('adminLockTo');
        const lockRangeBtn = document.getElementById('adminLockRangeBtn');
        const unlockRangeBtn = document.getElementById('adminUnlockRangeBtn');
        const unlockAllBtn = document.getElementById('adminUnlockAllBtn');
        const addSccRegularBtn = document.getElementById('adminAddSccRegularBtn');
        const lockSummaryEl = document.getElementById('adminLockSummary');
        const lockStatusEl = document.getElementById('adminLockStatus');
        const lockActionEndpoint = '../monitoring/ranking_lock_action.php';
        const sccCandidatesEndpoint = '../progchair/fetch_scc_regular_candidates.php';
        const toggleEndorsementEndpoint = '../progchair/toggle_program_endorsement.php';
        const programOptionIndex = <?=
            json_encode(
                $programOptionIndex,
                JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
            );
        ?>;
        let currentProgramId = 0;
        let currentProgramName = '';
        let currentQuota = null;
        let currentRows = [];
        let currentLocks = { active_count: 0, ranges: [] };

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function getAdminRankingSwalOptions(options = {}) {
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

        function fireAdminRankingSwal(title, text, icon) {
          if (typeof Swal === 'undefined') {
            window.alert(`${title}\n\n${text}`);
            return Promise.resolve();
          }
          return Swal.fire(getAdminRankingSwalOptions({ title, text, icon }));
        }

        function setRankingState({ showPlaceholder = false, loading = false, empty = false, showTable = false }) {
          if (placeholderEl) placeholderEl.classList.toggle('d-none', !showPlaceholder);
          if (loadingEl) loadingEl.classList.toggle('d-none', !loading);
          if (emptyEl) emptyEl.classList.toggle('d-none', !empty);
          if (tableWrapEl) tableWrapEl.classList.toggle('d-none', !showTable);
        }

        function updateProgramSummary(selectedProgram, payloadProgram) {
          const campusText = String(
            (payloadProgram && payloadProgram.campus_name) ||
            (selectedProgram && selectedProgram.campus_name) ||
            '--'
          ).trim() || '--';
          const collegeText = String(
            (payloadProgram && payloadProgram.college_name) ||
            (selectedProgram && selectedProgram.college_name) ||
            '--'
          ).trim() || '--';

          if (campusChipEl) {
            campusChipEl.textContent = `Selected Campus: ${campusText}`;
          }

          if (collegeChipEl) {
            collegeChipEl.textContent = `Selected College: ${collegeText}`;
          }
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

          if (lockRangeBtn) {
            lockRangeBtn.disabled = !hasProgram;
          }
          if (unlockRangeBtn) {
            unlockRangeBtn.disabled = !hasProgram;
          }
          if (unlockAllBtn) {
            unlockAllBtn.disabled = !hasProgram || Number(currentLocks?.active_count ?? 0) <= 0;
          }

          const ecCapacity = Number(currentQuota?.endorsement_capacity ?? 0);
          const ecSelected = Number(currentQuota?.endorsement_selected ?? 0);
          const ecRemaining = Math.max(0, ecCapacity - ecSelected);

          if (addSccRegularBtn) {
            const canAddScc = hasProgram && ecCapacity > 0 && ecRemaining > 0;
            addSccRegularBtn.disabled = !canAddScc;
            addSccRegularBtn.title = addSccRegularBtn.disabled
              ? (!hasProgram
                  ? 'No active program selected.'
                  : (ecCapacity <= 0
                      ? 'SCC capacity is 0.'
                      : (ecRemaining <= 0
                          ? 'SCC is full.'
                          : 'No active program selected.')))
              : `Add SCC from student interview list (${ecRemaining} slot${ecRemaining === 1 ? '' : 's'} remaining).`;
          }
        }

        function syncProgramQuery(programId) {
          if (!window.history || typeof window.history.replaceState !== 'function') {
            return;
          }

          const url = new URL(window.location.href);
          if (programId > 0) {
            url.searchParams.set('program_id', String(programId));
          } else {
            url.searchParams.delete('program_id');
          }

          window.history.replaceState({}, '', url.toString());
        }

        function getRowSection(row) {
          const raw = String(row?.row_section || '').toLowerCase();
          if (raw === 'scc' || raw === 'etg' || raw === 'regular') {
            return raw;
          }
          if (Boolean(row?.is_endorsement)) {
            return 'scc';
          }
          return String(row?.classification || '').toUpperCase() === 'REGULAR' ? 'regular' : 'etg';
        }

        function buildRankingRowHtml(row, sequenceDisplay, rankDisplay, options = {}) {
          const showLockPill = options.showLockPill !== false;
          const section = getRowSection(row);
          const isOutsideCapacity = Boolean(row?.is_outside_capacity);
          const interviewId = Number(row?.interview_id ?? 0);
          const sectionClass = section === 'scc'
            ? 'ranking-scc-row'
            : (section === 'etg' ? 'ranking-etg-row' : '');
          const rowClass = [
            sectionClass,
            isOutsideCapacity ? 'ranking-outside-capacity' : '',
            Boolean(row?.is_locked) ? 'ranking-locked-row' : ''
          ]
            .filter(Boolean)
            .join(' ');
          const classificationText = section === 'scc'
            ? 'SCC'
            : (section === 'etg' ? 'ETG' : 'R');
          const canRemoveScc = section === 'scc' && interviewId > 0;
          const removeSccTitle = Boolean(row?.is_locked)
            ? 'This interview rank is locked and SCC cannot be changed.'
            : 'Remove this student from the SCC list.';
          const actionHtml = canRemoveScc
            ? `
                <div class="ranking-col-actions">
                  <button
                    type="button"
                    class="btn btn-sm btn-outline-success admin-remove-scc-btn"
                    data-interview-id="${interviewId}"
                    title="${escapeHtml(removeSccTitle)}"
                    ${Boolean(row?.is_locked) ? 'disabled' : ''}
                  >
                    <i class="bx bx-minus-circle me-1"></i>Remove SCC
                  </button>
                </div>
              `
            : '<div class="ranking-col-actions"></div>';
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
              ${actionHtml}
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
              <div class="ranking-col-actions">Action</div>
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
            if (section === 'scc') {
              grouped.endorsementCount++;
            } else if (section === 'etg') {
              grouped.etgCount++;
            } else {
              grouped.regularCount++;
            }

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
          if (!currentProgramId || !interviewId) return Promise.resolve();

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
            fireAdminRankingSwal('Unavailable', 'Only Regular students can be added to SCC from this action.', 'info');
            return;
          }

          const ecCapacity = Number(currentQuota?.endorsement_capacity ?? 0);
          const ecSelected = Number(currentQuota?.endorsement_selected ?? 0);
          const ecRemaining = Math.max(0, ecCapacity - ecSelected);

          if (ecCapacity <= 0) {
            fireAdminRankingSwal('SCC Capacity', 'SCC capacity is 0. Configure SCC capacity first.', 'info');
            return;
          }

          if (ecRemaining <= 0) {
            fireAdminRankingSwal('SCC Full', 'SCC capacity is full.', 'info');
            return;
          }

          if (typeof Swal === 'undefined' || typeof $ === 'undefined' || !$.fn || !$.fn.select2) {
            fireAdminRankingSwal('Unavailable', 'SCC picker dependencies are not available on this page.', 'error');
            return;
          }

          let pickerSelect = null;

          Swal.fire(getAdminRankingSwalOptions({
            title: 'Add SCC (Regular)',
            html: `
              <div class="scc-picker-wrap">
                <label for="sccRegularPicker" class="scc-picker-label">Select student</label>
                <select id="sccRegularPicker"></select>
                <div class="scc-picker-help">
                  Outside-ranked Regular students are listed here. Entries marked <strong>Admin Cutoff Override</strong> are below cutoff and can only be added by administrator override, but they will still appear in the shared SCC list.
                </div>
              </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Add SCC',
            cancelButtonText: 'Cancel',
            focusConfirm: false,
            didOpen: () => {
              const selectEl = document.getElementById('sccRegularPicker');
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
                  noResults: () => 'No outside-ranked or admin-override Regular candidate found.',
                  searching: () => 'Searching...'
                }
              });

              pickerSelect.on('select2:open', () => {
                const searchField = document.querySelector('.select2-container--open .select2-search__field');
                if (searchField) searchField.focus();
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
            if (!result.isConfirmed || !result.value) return;

            const interviewId = Number(result.value || 0);
            if (!interviewId) return;

            toggleEndorsement(interviewId, 'ADD')
              .then((data) => {
                fireAdminRankingSwal('Added', data.message || 'Student added to SCC list.', 'success');
                loadProgramRanking(currentProgramId);
              })
              .catch((err) => {
                fireAdminRankingSwal('Error', err.message || 'Failed to add SCC.', 'error');
              });
          });
        }

        function promptRemoveScc(interviewId) {
          const normalizedInterviewId = Number(interviewId || 0);
          if (normalizedInterviewId <= 0) {
            fireAdminRankingSwal('Unavailable', 'Invalid SCC record selected.', 'error');
            return;
          }

          const matchingRow = Array.isArray(currentRows)
            ? currentRows.find((row) => Number(row?.interview_id ?? 0) === normalizedInterviewId)
            : null;

          if (!matchingRow) {
            fireAdminRankingSwal('Unavailable', 'Student record could not be found in the current ranking list.', 'error');
            return;
          }

          const studentLabel = String(
            matchingRow?.full_name ||
            matchingRow?.examinee_number ||
            'this student'
          ).trim() || 'this student';

          Swal.fire(getAdminRankingSwalOptions({
            title: 'Remove SCC?',
            html: `
              <div class="text-start">
                Remove <strong>${escapeHtml(studentLabel)}</strong> from the SCC list?
              </div>
            `,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonText: 'Remove SCC',
            cancelButtonText: 'Cancel',
            focusCancel: true
          })).then((result) => {
            if (!result.isConfirmed) return;

            toggleEndorsement(normalizedInterviewId, 'REMOVE')
              .then((data) => {
                fireAdminRankingSwal('Removed', data.message || 'Student removed from SCC list.', 'success');
                loadProgramRanking(currentProgramId);
              })
              .catch((err) => {
                fireAdminRankingSwal('Error', err.message || 'Failed to remove SCC.', 'error');
              });
          });
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
            const response = await fetch(lockActionEndpoint, {
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
            await loadProgramRanking(currentProgramId, statusMessage, false);
          } catch (error) {
            setLockStatus((error && error.message) ? error.message : 'Lock action failed.', true);
          }
        }

        function setIdleState() {
          currentProgramId = 0;
          currentProgramName = '';
          currentQuota = null;
          currentRows = [];
          currentLocks = { active_count: 0, ranges: [] };
          updateProgramSummary(null, null);
          updateLockSummary();
          setLockStatus('');
          refreshActionButtons();

          if (titleEl) {
            titleEl.textContent = 'Program Ranking';
          }

          if (metaEl) {
            metaEl.textContent = 'Select a program to load the ranked student list.';
          }

          if (emptyEl) {
            emptyEl.textContent = 'No ranked students found for this program.';
          }

          if (listEl) {
            listEl.innerHTML = '';
          }

          if (lockFromEl) lockFromEl.value = '';
          if (lockToEl) lockToEl.value = '';
          setRankingState({ showPlaceholder: true, loading: false, empty: false, showTable: false });
        }

        async function loadProgramRanking(programId, lockStatusMessage = '', lockStatusIsError = false) {
          const normalizedProgramId = Number(programId || 0);
          const selectedProgram = programOptionIndex[String(normalizedProgramId)] || null;

          syncProgramQuery(normalizedProgramId);

          if (normalizedProgramId <= 0 || !selectedProgram) {
            setIdleState();
            return;
          }

          currentProgramId = normalizedProgramId;
          currentProgramName = selectedProgram.title_name || 'PROGRAM';
          currentQuota = null;
          currentRows = [];
          currentLocks = { active_count: 0, ranges: [] };
          updateProgramSummary(selectedProgram, null);
          updateLockSummary();
          setLockStatus('');
          refreshActionButtons();

          if (titleEl) {
            titleEl.textContent = `Program Ranking - ${currentProgramName}`;
          }

          if (metaEl) {
            metaEl.textContent = 'Loading...';
          }

          if (emptyEl) {
            emptyEl.textContent = 'No ranked students found for this program.';
          }

          if (listEl) {
            listEl.innerHTML = '';
          }

          setRankingState({ showPlaceholder: false, loading: true, empty: false, showTable: false });

          try {
            const response = await fetch(`get_program_ranking.php?program_id=${encodeURIComponent(String(normalizedProgramId))}`, {
              headers: { Accept: 'application/json' }
            });
            const data = await response.json();

            if (!response.ok || !data || !data.success) {
              throw new Error((data && data.message) ? data.message : 'Failed to load ranking.');
            }

            currentRows = Array.isArray(data.rows) ? data.rows : [];
            currentQuota = data && typeof data.quota === 'object' ? data.quota : null;
            currentLocks = data && typeof data.locks === 'object'
              ? data.locks
              : { active_count: 0, ranges: [] };
            const payloadProgram = data && typeof data.program === 'object' ? data.program : null;

            updateProgramSummary(selectedProgram, payloadProgram);
            updateLockSummary();

            if (payloadProgram && payloadProgram.program_name && titleEl) {
              currentProgramName = String(payloadProgram.program_name || currentProgramName);
              titleEl.textContent = `Program Ranking - ${currentProgramName}`;
            }

            refreshActionButtons();

            if (currentRows.length === 0) {
              if (emptyEl && currentQuota && currentQuota.enabled === true) {
                const capacity = Number(currentQuota.absorptive_capacity ?? 0);
                emptyEl.textContent = capacity <= 0
                  ? 'No ranking shown because absorptive capacity is set to 0.'
                  : 'No ranked students found for this program.';
              }

              if (metaEl) {
                metaEl.textContent = 'No ranking available.';
              }

              if (lockStatusMessage !== '') {
                setLockStatus(lockStatusMessage, lockStatusIsError);
              }
              setRankingState({ showPlaceholder: false, loading: false, empty: true, showTable: false });
              return;
            }

            const grouped = renderRankingRows(currentRows);
            if (metaEl) {
              metaEl.textContent = buildRankingMeta(grouped, currentQuota);
            }

            if (lockStatusMessage !== '') {
              setLockStatus(lockStatusMessage, lockStatusIsError);
            }
            setRankingState({ showPlaceholder: false, loading: false, empty: false, showTable: true });
          } catch (error) {
            currentRows = [];
            currentQuota = null;
            currentLocks = { active_count: 0, ranges: [] };
            updateLockSummary();
            refreshActionButtons();

            if (emptyEl) {
              emptyEl.textContent = (error && error.message) ? error.message : 'Failed to load ranking.';
            }

            if (metaEl) {
              metaEl.textContent = 'Failed to load ranking.';
            }

            setLockStatus((error && error.message) ? error.message : 'Failed to load ranking.', true);
            setRankingState({ showPlaceholder: false, loading: false, empty: true, showTable: false });
          }
        }

        if (programSelectEl) {
          programSelectEl.addEventListener('change', function () {
            loadProgramRanking(Number(programSelectEl.value || 0));
          });
        }

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

        if (listEl) {
          listEl.addEventListener('click', (event) => {
            const trigger = event.target instanceof Element
              ? event.target.closest('.admin-remove-scc-btn')
              : null;
            if (!trigger) {
              return;
            }

            const interviewId = Number(trigger.getAttribute('data-interview-id') || 0);
            if (interviewId > 0) {
              promptRemoveScc(interviewId);
            }
          });
        }

        const initialProgramId = Number(programSelectEl?.value || 0);
        if (initialProgramId > 0) {
          loadProgramRanking(initialProgramId);
        } else {
          setIdleState();
        }
      });
    </script>
  </body>
</html>
