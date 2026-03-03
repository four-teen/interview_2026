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
  #adminGlobalSearchForm .form-control {
    min-width: 280px;
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

  #adminExamineeSearchModal .table thead th {
    font-size: 0.73rem;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #6e7788;
    white-space: nowrap;
    border-bottom-width: 2px;
    padding-top: 0.95rem;
    padding-bottom: 0.95rem;
  }

  #adminExamineeSearchModal .table tbody td {
    vertical-align: middle;
    padding-top: 0.9rem;
    padding-bottom: 0.9rem;
  }

  #adminExamineeSearchModal .admin-search-table {
    min-width: 1080px;
  }

  #adminExamineeSearchModal .admin-search-student {
    min-width: 240px;
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

  #adminExamineeSearchModal .admin-search-program {
    min-width: 340px;
    color: #5d6b7f;
    line-height: 1.35;
  }

  #adminExamineeSearchModal .admin-search-score {
    font-weight: 600;
    color: #4a5568;
    white-space: nowrap;
  }

  #adminExamineeSearchModal .admin-search-final-score {
    font-weight: 700;
    color: #0f766e;
    white-space: nowrap;
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
          class="form-control border-0 shadow-none w-100"
          style="max-width: 42rem;"
          placeholder="Search examinee number, full name, or preferred program and press Enter"
          aria-label="Search examinees"
        />
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

        <div id="adminSearchResultTableWrap" class="table-responsive d-none">
          <table class="table table-sm table-hover mb-0 admin-search-table">
            <thead>
              <tr>
                <th>Student</th>
                <th>Preferred Program</th>
                <th>Basis</th>
                <th>SAT</th>
                <th>ESM</th>
                <th>Overall</th>
                <th>Status</th>
                <th>Final Score</th>
              </tr>
            </thead>
            <tbody id="adminExamineeSearchResults"></tbody>
          </table>
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
    const modalEl = document.getElementById('adminExamineeSearchModal');
    const queryChipEl = document.getElementById('adminSearchQueryChip');
    const loadingEl = document.getElementById('adminSearchLoadingState');
    const emptyEl = document.getElementById('adminSearchEmptyState');
    const tableWrapEl = document.getElementById('adminSearchResultTableWrap');
    const resultBodyEl = document.getElementById('adminExamineeSearchResults');
    const endpointUrl = <?= json_encode(rtrim(BASE_URL, '/') . '/administrator/search_examinees.php'); ?>;

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
      const showTable = state === 'table';

      loadingEl.classList.toggle('d-none', !showLoading);
      emptyEl.classList.toggle('d-none', !showEmpty);
      tableWrapEl.classList.toggle('d-none', !showTable);
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
          return escapeHtml(value);
        }

        return numericValue.toLocaleString(undefined, {
          minimumFractionDigits: decimals,
          maximumFractionDigits: decimals
        });
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

      resultBodyEl.innerHTML = rows.map((row) => {
        const examinee = escapeHtml(row.examinee_number || '-');
        const fullName = escapeHtml(row.full_name || '-');
        const preferredProgram = escapeHtml(row.preferred_program || '-');
        const basisLabel = escapeHtml(row.basis_label || 'Overall');
        const statusLabel = escapeHtml(row.status_label || 'No Interview');
        const satScore = formatScore(row.sat_score);
        const esmScore = formatScore(row.esm_competency_standard_score);
        const overallScore = formatScore(row.overall_standard_score);
        const finalScore = row.final_score !== null && row.final_score !== undefined && row.final_score !== ''
          ? `${formatScore(row.final_score, 2)}%`
          : 'N/A';

        return `
          <tr>
            <td class="admin-search-student">
              <div class="admin-search-student__name">${fullName}</div>
              <span class="admin-search-student__meta">Examinee #: ${examinee}</span>
            </td>
            <td class="admin-search-program">${preferredProgram}</td>
            <td>
              <span class="admin-search-badge ${getBasisBadgeClass(row.basis_label)}">${basisLabel}</span>
            </td>
            <td class="admin-search-score">${satScore}</td>
            <td class="admin-search-score">${esmScore}</td>
            <td class="admin-search-score">${overallScore}</td>
            <td>
              <span class="admin-search-badge ${getStatusBadgeClass(row.status_label)}">${statusLabel}</span>
            </td>
            <td class="admin-search-final-score">${finalScore}</td>
          </tr>
        `;
      }).join('');

      setModalState('table');
    }

    searchForm.addEventListener('submit', function (event) {
      event.preventDefault();

      const query = (searchInput.value || '').trim();
      emptyEl.textContent = 'No matching examinees found.';

      if (query.length < 2) {
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

          renderRows(payload.rows || []);
        })
        .catch((error) => {
          console.error(error);
          resultBodyEl.innerHTML = '';
          emptyEl.textContent = error.message || 'Failed to search placement results.';
          setModalState('empty');
        });
    });
  })();
</script>
