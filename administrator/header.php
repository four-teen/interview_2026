<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/env.php';
    define('BASE_URL', getenv('BASE_URL') ?: '/interview');
}

$adminEmail = trim((string) ($_SESSION['email'] ?? ''));
if ($adminEmail === '') {
    $adminEmail = 'No email on file';
}

$adminRoleRaw = trim((string) ($_SESSION['role'] ?? 'administrator'));
$adminRole = ucwords(str_replace(['_', '-'], ' ', strtolower($adminRoleRaw)));
?>

<style>
  #layout-navbar {
    gap: 0.75rem;
  }

  #layout-navbar .layout-menu-toggle,
  #layout-navbar .navbar-nav.flex-row,
  #layout-navbar .dropdown-user {
    flex: 0 0 auto;
  }

  #layout-navbar .navbar-nav-right,
  #layout-navbar .navbar-nav.align-items-center.flex-grow-1,
  #adminGlobalSearchForm {
    min-width: 0;
  }

  #adminGlobalSearchForm {
    gap: 0.75rem;
  }

  #adminGlobalSearchForm .bx-search {
    flex: 0 0 auto;
    color: #7b8798;
  }

  #adminGlobalSearchForm .form-control {
    min-width: 280px;
  }

  #adminGlobalSearchCount {
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 8.4rem;
    padding: 0.34rem 0.72rem;
    border: 1px solid #dce4ef;
    border-radius: 999px;
    background: #f7f9fc;
    color: #607188;
    font-size: 0.73rem;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
  }

  #adminGlobalSearchCount.is-filtered {
    border-color: #b9d9ff;
    background: #edf5ff;
    color: #1f4f9c;
  }

  #adminGlobalSearchForm .form-control:focus {
    border: 1px solid #d9a441;
    box-shadow: 0 0 0 0.2rem rgba(217, 164, 65, 0.18);
  }

  #adminExamineeSearchModal .modal-content {
    border: 2px solid #d9a441;
    border-radius: 0.9rem;
    box-shadow: 0 16px 36px rgba(43, 30, 8, 0.22);
  }

  #adminExamineeSearchModal .modal-header {
    border-bottom: 1px solid #e5c88e;
    background: linear-gradient(120deg, #fff7e8 0%, #fff1d8 100%);
  }

  #adminExamineeSearchModal .search-query-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.2rem 0.6rem;
    border-radius: 999px;
    background: #fff4dd;
    color: #8a5a10;
    border: 1px solid #e6c487;
    font-size: 0.78rem;
    font-weight: 600;
  }

  #adminExamineeSearchModal .admin-search-list {
    display: flex;
    flex-direction: column;
    gap: 0.8rem;
  }

  #adminExamineeSearchModal .admin-search-card {
    border: 1px solid #e9d2a0;
    border-radius: 0.8rem;
    background: #fffefb;
    padding: 0.85rem 0.9rem;
    box-shadow: 0 4px 14px rgba(138, 90, 16, 0.06);
  }

  #adminExamineeSearchModal .admin-search-card__top {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
  }

  #adminExamineeSearchModal .admin-search-card__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    flex-wrap: wrap;
    gap: 0.35rem;
  }

  #adminExamineeSearchModal .admin-search-student {
    min-width: 0;
    flex: 1 1 380px;
  }

  #adminExamineeSearchModal .admin-search-student__name {
    font-size: 0.98rem;
    font-weight: 700;
    line-height: 1.3;
    color: #364152;
    text-transform: uppercase;
  }

  #adminExamineeSearchModal .admin-search-student__meta {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.79rem;
    color: #7b8798;
  }

  #adminExamineeSearchModal .admin-search-card__metrics {
    margin-top: 0.7rem;
    display: grid;
    grid-template-columns: repeat(4, minmax(145px, 1fr));
    gap: 0.5rem 0.65rem;
  }

  #adminExamineeSearchModal .admin-search-kv {
    border: 1px solid #f1e3c8;
    border-radius: 0.6rem;
    padding: 0.45rem 0.5rem;
    background: #ffffff;
  }

  #adminExamineeSearchModal .admin-search-kv__label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #8a785c;
  }

  #adminExamineeSearchModal .admin-search-kv__value {
    display: block;
    margin-top: 0.18rem;
    font-size: 0.83rem;
    font-weight: 600;
    color: #4a5568;
    line-height: 1.35;
    word-break: break-word;
  }

  #adminExamineeSearchModal .admin-search-kv__value--program {
    color: #5d6b7f;
  }

  #adminExamineeSearchModal .admin-search-kv__value--score {
    color: #364152;
    white-space: nowrap;
  }

  #adminExamineeSearchModal .admin-search-kv__value--final-score {
    color: #0f766e;
    font-weight: 700;
    white-space: nowrap;
  }

  #adminExamineeSearchModal .admin-search-toggle {
    border: 1px solid #d9a441;
    color: #8a5a10;
    background: #fff8eb;
    border-radius: 999px;
    font-size: 0.74rem;
    font-weight: 700;
    padding: 0.28rem 0.7rem;
    line-height: 1;
  }

  #adminExamineeSearchModal .admin-search-toggle:hover {
    background: #ffefd0;
  }

  #adminExamineeSearchModal .admin-search-details-panel {
    margin-top: 0.72rem;
    padding-top: 0.72rem;
    border-top: 1px dashed #e7c989;
  }

  #adminExamineeSearchModal .admin-search-details-grid {
    display: grid;
    grid-template-columns: repeat(3, minmax(180px, 1fr));
    gap: 0.55rem 0.75rem;
  }

  #adminExamineeSearchModal .admin-search-detail-item {
    border: 1px solid #ecd8ad;
    border-radius: 0.6rem;
    padding: 0.45rem 0.55rem;
    background: #fffefb;
  }

  #adminExamineeSearchModal .admin-search-detail-label {
    display: block;
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #7c6650;
  }

  #adminExamineeSearchModal .admin-search-detail-value {
    display: block;
    margin-top: 0.2rem;
    font-size: 0.83rem;
    font-weight: 600;
    color: #3f3a32;
    line-height: 1.35;
    word-break: break-word;
  }

  #adminExamineeSearchModal .admin-search-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    padding: 0.28rem 0.7rem;
    font-size: 0.74rem;
    font-weight: 700;
    line-height: 1;
    white-space: nowrap;
  }

  #adminExamineeSearchModal .admin-search-badge--esm {
    background: #e8f0ff;
    color: #2563eb;
  }

  #adminExamineeSearchModal .admin-search-badge--overall {
    background: #eef2f7;
    color: #475569;
  }

  #adminExamineeSearchModal .admin-search-badge--scored {
    background: #e8fff3;
    color: #13795b;
  }

  #adminExamineeSearchModal .admin-search-badge--pending {
    background: #fff4df;
    color: #b26a00;
  }

  #adminExamineeSearchModal .admin-search-badge--empty {
    background: #edf1f5;
    color: #7b8798;
  }

  @media (max-width: 767.98px) {
    #layout-navbar {
      padding-left: 1rem;
      padding-right: 1rem;
    }

    #layout-navbar .navbar-nav.align-items-center.flex-grow-1 {
      margin-right: 0.5rem !important;
    }

    #adminGlobalSearchForm {
      flex-wrap: wrap;
      gap: 0.4rem 0.65rem;
    }

    #adminGlobalSearchForm .form-control {
      flex: 1 1 calc(100% - 2rem);
      min-width: 0;
      font-size: 0.9rem;
    }

    #adminGlobalSearchCount {
      margin-left: 1.95rem;
      min-width: 0;
      font-size: 0.7rem;
      padding: 0.26rem 0.62rem;
    }

    #adminGlobalSearchInput::placeholder {
      font-size: 0.85rem;
    }

    #layout-navbar .dropdown-user .nav-link {
      padding-left: 0.25rem;
      padding-right: 0.25rem;
    }

    #adminExamineeSearchModal .admin-search-card {
      padding: 0.75rem;
    }

    #adminExamineeSearchModal .admin-search-card__top {
      flex-direction: column;
      align-items: stretch;
    }

    #adminExamineeSearchModal .admin-search-card__actions {
      justify-content: flex-start;
    }

    #adminExamineeSearchModal .admin-search-card__metrics {
      grid-template-columns: 1fr;
    }

    #adminExamineeSearchModal .admin-search-details-grid {
      grid-template-columns: 1fr;
    }
  }

  @media (min-width: 768px) and (max-width: 991.98px) {
    #adminExamineeSearchModal .admin-search-card__metrics {
      grid-template-columns: repeat(2, minmax(0, 1fr));
    }
  }
</style>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
  id="layout-navbar">
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
    <div class="navbar-nav align-items-center flex-grow-1 me-3">
      <form id="adminGlobalSearchForm" class="nav-item d-flex align-items-center w-100" autocomplete="off">
        <i class="bx bx-search fs-4 lh-0"></i>
        <input
          type="search"
          id="adminGlobalSearchInput"
          class="form-control border-0 shadow-none"
          style="flex: 1 1 42rem; max-width: 42rem;"
          placeholder="Search examinee number, full name, or preferred program and press Enter"
          aria-label="Search examinees"
        />
        <span id="adminGlobalSearchCount" aria-live="polite">Total: --</span>
      </form>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 d-none d-lg-flex flex-column align-items-end">
        <span class="fw-semibold small"><?= htmlspecialchars($adminEmail); ?></span>
        <small class="text-muted"><?= htmlspecialchars($adminRole); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?= BASE_URL ?>/assets/img/avatars/1.png" alt class="w-px-40 h-auto rounded-circle" />
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?= BASE_URL ?>/assets/img/avatars/1.png" alt class="w-px-40 h-auto rounded-circle" />
                  </div>
                </div>
                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?= htmlspecialchars($adminEmail); ?></span>
                  <small class="text-muted"><?= htmlspecialchars($adminRole); ?></small>
                </div>
              </div>
            </a>
          </li>
          <li>
            <div class="dropdown-divider"></div>
          </li>
          <li>
            <a class="dropdown-item" href="<?= BASE_URL ?>/logout.php">
              <i class="bx bx-power-off me-2"></i>
              <span class="align-middle">Log Out</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>

<div class="modal fade" id="adminExamineeSearchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title mb-1">Examinee Search Results</h5>
          <div>
            <span id="adminSearchQueryChip" class="search-query-chip d-none"></span>
          </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div id="adminSearchLoadingState" class="text-center text-muted py-4 d-none">
          Searching placement results...
        </div>

        <div id="adminSearchEmptyState" class="text-center text-muted py-4 d-none">
          No matching examinees found.
        </div>

        <div id="adminSearchResultCardWrap" class="d-none">
          <div id="adminExamineeSearchResults" class="admin-search-list"></div>
        </div>
      </div>
    </div>
  </div>
</div>

<script>
  (function () {
    const searchForm = document.getElementById('adminGlobalSearchForm');
    if (!searchForm) return;

    const searchInput = document.getElementById('adminGlobalSearchInput');
    const countBadgeEl = document.getElementById('adminGlobalSearchCount');
    const compactSearchMedia = window.matchMedia('(max-width: 767.98px)');
    const modalEl = document.getElementById('adminExamineeSearchModal');
    const queryChipEl = document.getElementById('adminSearchQueryChip');
    const loadingEl = document.getElementById('adminSearchLoadingState');
    const emptyEl = document.getElementById('adminSearchEmptyState');
    const cardWrapEl = document.getElementById('adminSearchResultCardWrap');
    const resultBodyEl = document.getElementById('adminExamineeSearchResults');
    const endpointUrl = <?= json_encode(rtrim(BASE_URL, '/') . '/administrator/search_examinees.php'); ?>;
    const countFormatter = new Intl.NumberFormat();
    let lastKnownTotalRecords = null;

    const updateSearchPlaceholder = () => {
      searchInput.placeholder = compactSearchMedia.matches
        ? 'Search student or examinee no.'
        : 'Search examinee number, full name, or preferred program and press Enter';
    };

    function toValidCount(value) {
      const parsed = Number(value);
      if (!Number.isFinite(parsed) || parsed < 0) return null;
      return Math.trunc(parsed);
    }

    function formatCount(value) {
      const normalized = toValidCount(value);
      if (normalized === null) return null;
      return countFormatter.format(normalized);
    }

    function updateCountBadge(filteredRecords = null, totalRecords = null, isFiltered = false) {
      if (!countBadgeEl) return;

      const normalizedTotal = toValidCount(totalRecords);
      const normalizedFiltered = toValidCount(filteredRecords);

      if (normalizedTotal !== null) {
        lastKnownTotalRecords = normalizedTotal;
      }

      countBadgeEl.classList.toggle('is-filtered', isFiltered);

      if (isFiltered && normalizedFiltered !== null) {
        const matchedText = formatCount(normalizedFiltered);
        if (lastKnownTotalRecords !== null) {
          countBadgeEl.textContent = `Matched: ${matchedText} / ${formatCount(lastKnownTotalRecords)}`;
        } else {
          countBadgeEl.textContent = `Matched: ${matchedText}`;
        }
        return;
      }

      if (lastKnownTotalRecords !== null) {
        countBadgeEl.textContent = `Total: ${formatCount(lastKnownTotalRecords)}`;
        return;
      }

      countBadgeEl.textContent = 'Total: --';
    }

    function loadInitialTotalCount() {
      fetch(endpointUrl, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      })
        .then((res) => res.json())
        .then((payload) => {
          if (!payload || payload.success !== true) return;
          updateCountBadge(null, payload.total_records, false);
        })
        .catch(() => {
          updateCountBadge(null, null, false);
        });
    }

    updateSearchPlaceholder();
    loadInitialTotalCount();
    if (typeof compactSearchMedia.addEventListener === 'function') {
      compactSearchMedia.addEventListener('change', updateSearchPlaceholder);
    } else if (typeof compactSearchMedia.addListener === 'function') {
      compactSearchMedia.addListener(updateSearchPlaceholder);
    }

    function escapeHtml(value) {
      return String(value ?? '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function setModalState(state) {
      const showLoading = state === 'loading';
      const showEmpty = state === 'empty';
      const showCards = state === 'cards';

      loadingEl.classList.toggle('d-none', !showLoading);
      emptyEl.classList.toggle('d-none', !showEmpty);
      cardWrapEl.classList.toggle('d-none', !showCards);
    }

    function openModal() {
      if (!modalEl || typeof bootstrap === 'undefined') return;
      const modal = bootstrap.Modal.getOrCreateInstance(modalEl);
      modal.show();
    }

    function renderRows(rows) {
      if (!Array.isArray(rows) || rows.length === 0) {
        resultBodyEl.innerHTML = '';
        setModalState('empty');
        return;
      }

      function formatScore(value, decimals = 0) {
        if (value === null || value === undefined || value === '') return 'N/A';

        const numericValue = Number(value);
        if (Number.isNaN(numericValue)) {
          return String(value);
        }

        return numericValue.toLocaleString(undefined, {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        });
      }

      function formatDateTime(value) {
        const raw = String(value || '').trim();
        if (raw === '') return 'N/A';
        const parsed = new Date(raw.replace(' ', 'T'));
        if (Number.isNaN(parsed.getTime())) {
          return raw;
        }
        return parsed.toLocaleString(undefined, {
          year: 'numeric',
          month: 'short',
          day: 'numeric',
          hour: 'numeric',
          minute: '2-digit'
        });
      }

      function buildProgramChoiceLabel(label, idValue) {
        const text = String(label || '').trim();
        if (text !== '') return text;
        const id = Number(idValue || 0);
        if (id > 0) return `PROGRAM ${id}`;
        return 'N/A';
      }

      function buildDetailItem(label, value) {
        return `
          <div class="admin-search-detail-item">
            <span class="admin-search-detail-label">${escapeHtml(label)}</span>
            <span class="admin-search-detail-value">${escapeHtml(value)}</span>
          </div>
        `;
      }

      function buildMetricItem(label, value, valueClass = '') {
        const classSuffix = String(valueClass || '').trim();
        const classes = classSuffix === '' ? 'admin-search-kv__value' : `admin-search-kv__value ${classSuffix}`;
        return `
          <div class="admin-search-kv">
            <span class="admin-search-kv__label">${escapeHtml(label)}</span>
            <span class="${classes}">${escapeHtml(value)}</span>
          </div>
        `;
      }

      function getBasisBadgeClass(label) {
        return String(label || '').toUpperCase() === 'ESM'
          ? 'admin-search-badge--esm'
          : 'admin-search-badge--overall';
      }

      function getStatusBadgeClass(label) {
        const normalized = String(label || '').toUpperCase();
        if (normalized === 'SCORED') return 'admin-search-badge--scored';
        if (normalized === 'PENDING SCORE') return 'admin-search-badge--pending';
        return 'admin-search-badge--empty';
      }

      resultBodyEl.innerHTML = rows.map((row, index) => {
        const detailRowId = `adminSearchDetailsRow-${index}`;
        const examinee = escapeHtml(row.examinee_number || '-');
        const fullName = escapeHtml(row.full_name || '-');
        const firstChoiceLabel = buildProgramChoiceLabel(row.first_choice_label, row.first_choice_id);
        const secondChoiceLabel = buildProgramChoiceLabel(row.second_choice_label, row.second_choice_id);
        const thirdChoiceLabel = buildProgramChoiceLabel(row.third_choice_label, row.third_choice_id);
        const basisLabel = String(row.basis_label || 'Overall');
        const statusLabel = String(row.status_label || 'No Interview');
        const satScore = formatScore(row.sat_score);
        const esmScore = formatScore(row.esm_competency_standard_score);
        const overallScore = formatScore(row.overall_standard_score);
        const interviewerName = String(row.interviewer_name || 'N/A');
        const interviewDate = formatDateTime(row.interview_datetime);
        const classification = String(row.interview_classification || '').trim() || 'N/A';
        const etgClassName = String(row.etg_class_name || '').trim() || 'N/A';
        const shsTrack = String(row.shs_track_name || '').trim() || 'N/A';
        const campusName = String(row.interview_campus_name || '').trim() || 'N/A';
        const mobileNumber = String(row.mobile_number || '').trim() || 'N/A';
        const interviewId = Number(row.interview_id || 0);
        const placementResultId = Number(row.placement_result_id || 0);
        const finalScoreText = row.final_score !== null && row.final_score !== undefined && row.final_score !== ''
          ? `${formatScore(row.final_score, 2)}%`
          : 'N/A';

        const detailsHtml = [
          buildDetailItem('Interview ID', interviewId > 0 ? String(interviewId) : 'N/A'),
          buildDetailItem('Placement Result ID', placementResultId > 0 ? String(placementResultId) : 'N/A'),
          buildDetailItem('Interviewed By', interviewerName),
          buildDetailItem('Interview Date/Time', formatDateTime(row.interview_datetime)),
          buildDetailItem('1st Choice', firstChoiceLabel),
          buildDetailItem('2nd Choice', secondChoiceLabel),
          buildDetailItem('3rd Choice', thirdChoiceLabel),
          buildDetailItem('Preferred Program', String(row.preferred_program || 'N/A')),
          buildDetailItem('Classification', classification),
          buildDetailItem('ETG Class', etgClassName),
          buildDetailItem('SHS Track', shsTrack),
          buildDetailItem('Interview Campus', campusName),
          buildDetailItem('Mobile Number', mobileNumber),
          buildDetailItem('Interview Status', String(row.status_label || 'No Interview'))
        ].join('');

        const summaryMetricsHtml = [
          buildMetricItem('Preferred Program', String(row.preferred_program || 'N/A'), 'admin-search-kv__value--program'),
          buildMetricItem('1st Choice', firstChoiceLabel, 'admin-search-kv__value--program'),
          buildMetricItem('SAT', satScore, 'admin-search-kv__value--score'),
          buildMetricItem('ESM', esmScore, 'admin-search-kv__value--score'),
          buildMetricItem('Overall', overallScore, 'admin-search-kv__value--score'),
          buildMetricItem('Final Score', finalScoreText, 'admin-search-kv__value--final-score'),
          buildMetricItem('Interviewed By', interviewerName),
          buildMetricItem('When', interviewDate)
        ].join('');

        return `
          <article class="admin-search-card">
            <div class="admin-search-card__top">
              <div class="admin-search-student">
                <div class="admin-search-student__name">${fullName}</div>
                <span class="admin-search-student__meta">Examinee #: ${examinee}</span>
              </div>
              <div class="admin-search-card__actions">
                <span class="admin-search-badge ${getBasisBadgeClass(row.basis_label)}">${escapeHtml(basisLabel)}</span>
                <span class="admin-search-badge ${getStatusBadgeClass(row.status_label)}">${escapeHtml(statusLabel)}</span>
                <button
                  type="button"
                  class="btn btn-sm admin-search-toggle js-toggle-search-details"
                  data-detail-row-id="${detailRowId}"
                  aria-expanded="false"
                  aria-controls="${detailRowId}"
                >
                  Show Full Details
                </button>
              </div>
            </div>

            <div class="admin-search-card__metrics">${summaryMetricsHtml}</div>

            <div id="${detailRowId}" class="admin-search-details-panel d-none">
              <div class="admin-search-details-grid">${detailsHtml}</div>
            </div>
          </article>
        `;
      }).join('');

      setModalState('cards');
    }

    searchForm.addEventListener('submit', function (event) {
      event.preventDefault();

      const query = (searchInput.value || '').trim();
      emptyEl.textContent = 'No matching examinees found.';

      if (query.length < 2) {
        updateCountBadge(null, lastKnownTotalRecords, false);
        queryChipEl.textContent = 'Please enter at least 2 characters';
        queryChipEl.classList.remove('d-none');
        resultBodyEl.innerHTML = '';
        setModalState('empty');
        openModal();
        return;
      }

      queryChipEl.textContent = `Query: ${query}`;
      queryChipEl.classList.remove('d-none');
      setModalState('loading');
      openModal();

      fetch(`${endpointUrl}?q=${encodeURIComponent(query)}`, {
        method: 'GET',
        headers: { Accept: 'application/json' }
      })
        .then((res) => res.json())
        .then((payload) => {
          if (!payload || payload.success !== true) {
            throw new Error((payload && payload.message) || 'Failed to search records.');
          }

          const rows = payload.rows || [];
          const filteredCount = toValidCount(payload.filtered_records) ?? rows.length;
          const totalCount = toValidCount(payload.total_records) ?? lastKnownTotalRecords;
          updateCountBadge(filteredCount, totalCount, true);
          renderRows(rows);
        })
        .catch((error) => {
          console.error(error);
          resultBodyEl.innerHTML = '';
          emptyEl.textContent = error.message || 'Failed to search placement results.';
          setModalState('empty');
          updateCountBadge(null, lastKnownTotalRecords, false);
        });
    });

    searchInput.addEventListener('input', function () {
      if ((searchInput.value || '').trim() === '') {
        updateCountBadge(null, lastKnownTotalRecords, false);
      }
    });

    resultBodyEl.addEventListener('click', function (event) {
      const button = event.target.closest('.js-toggle-search-details');
      if (!button) return;

      const detailRowId = String(button.getAttribute('data-detail-row-id') || '').trim();
      if (detailRowId === '') return;

      const detailRowEl = document.getElementById(detailRowId);
      if (!detailRowEl) return;

      const expanded = button.getAttribute('aria-expanded') === 'true';
      detailRowEl.classList.toggle('d-none', expanded);
      button.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      button.textContent = expanded ? 'Show Full Details' : 'Hide Full Details';
    });
  })();
</script>
