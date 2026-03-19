<?php
require_once '../../config/db.php';
require_once '../../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'monitoring') {
    header('Location: ../../index.php');
    exit;
}

$monitoringHeaderTitle = 'Monitoring Center - Programs';

function table_column_exists(mysqli $conn, string $table, string $column): bool
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

$hasEndorsementCapacityColumn = table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_capacity');
$hasEndorsementPercentageColumn = table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_percentage');

$endorsementCapacitySelect = $hasEndorsementCapacityColumn
    ? 'pc.endorsement_capacity'
    : ($hasEndorsementPercentageColumn
        ? 'pc.endorsement_percentage AS endorsement_capacity'
        : 'NULL AS endorsement_capacity');

$programs = [];
$sql = "
    SELECT
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        p.status,
        col.college_name,
        c.campus_name,
        pc.cutoff_score,
        pc.absorptive_capacity,
        pc.regular_percentage,
        pc.etg_percentage,
        {$endorsementCapacitySelect}
    FROM tbl_program p
    INNER JOIN tbl_college col
        ON col.college_id = p.college_id
    INNER JOIN tbl_campus c
        ON c.campus_id = col.campus_id
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    WHERE p.status = 'active'
    ORDER BY c.campus_name ASC, col.college_name ASC, p.program_name ASC, p.major ASC
";

$result = $conn->query($sql);
if ($result) {
    while ($row = $result->fetch_assoc()) {
        $programs[] = $row;
    }
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Monitoring Programs - Interview</title>

    <link rel="icon" type="image/x-icon" href="../../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>
    <style>
      .program-search-wrap .form-control:focus {
        border-color: #6f74ff;
        box-shadow: 0 0 0 0.18rem rgba(105, 108, 255, 0.16);
      }

      .program-card-meta {
        font-size: 0.78rem;
        color: #91a0b6;
        text-transform: uppercase;
      }

      .program-list-shell {
        gap: 1rem;
      }

      .program-card-title {
        margin-top: 0.25rem;
        margin-bottom: 0.3rem;
        line-height: 1.32;
      }

      .program-name-main {
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.01em;
        color: #425b76;
      }

      .program-name-major {
        font-style: italic;
        font-weight: 600;
        color: #e67e22;
      }

      .program-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.32rem;
        padding: 0.25rem 0.55rem;
        border-radius: 999px;
        font-size: 0.78rem;
        font-weight: 600;
      }

      .program-chip.program-chip-cutoff {
        background: #eef0ff;
        color: #5964f2;
      }

      .program-chip.program-chip-capacity {
        background: #e7f9ff;
        color: #00a8d1;
      }

      .program-chip.program-chip-ec {
        background: #fff5df;
        color: #bf7a00;
      }

      .program-capacity-line {
        margin-top: 0.55rem;
        font-size: 0.82rem;
        color: #7d8da7;
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem 0.45rem;
      }

      .program-capacity-line .badge {
        font-size: 0.72rem;
      }

      .program-card-actions .btn {
        white-space: nowrap;
      }

      .cutoff-preview-line {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 0.35rem 0.45rem;
      }

      .cutoff-preview-line .badge {
        font-size: 0.72rem;
      }

      .cutoff-preview-line span {
        margin: 0 !important;
      }

      .program-config-modal .modal-footer {
        gap: 0.75rem;
      }

      @media (max-width: 767.98px) {
        .program-list-shell {
          flex-direction: column;
        }

        .program-card-summary,
        .program-card-actions {
          width: 100%;
        }

        .program-card-actions .btn {
          width: 100%;
          justify-content: center;
        }
      }

      @media (max-width: 575.98px) {
        .programs-header {
          flex-direction: column;
          align-items: stretch !important;
          gap: 0.75rem;
        }

        .program-card-title {
          font-size: 1rem;
        }

        .program-config-modal .modal-header,
        .program-config-modal .modal-body,
        .program-config-modal .modal-footer {
          padding-left: 1rem;
          padding-right: 1rem;
        }

        .program-config-modal .modal-footer .btn {
          width: 100%;
        }
      }
    </style>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include '../sidebar.php'; ?>

        <div class="layout-page">
          <?php include '../header.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center programs-header">
                  <div>
                    <h5 class="mb-1">Programs Management</h5>
                    <small class="text-muted">Configure cut-off, absorptive capacity, SCC, regular, and ETG allocation.</small>
                  </div>
                  <span class="badge bg-label-primary"><?= number_format(count($programs)); ?> Active Programs</span>
                </div>

                <div class="card-body">
                  <div class="row g-3 mb-4">
                    <div class="col-12">
                      <label class="form-label">Search Programs</label>
                      <div class="input-group program-search-wrap">
                        <span class="input-group-text"><i class="bx bx-search"></i></span>
                        <input
                          type="search"
                          id="programSearchInput"
                          class="form-control"
                          placeholder="Search by program, code, campus, or college"
                          autocomplete="off"
                        >
                      </div>
                    </div>
                  </div>

                  <div class="row" id="programCardsContainer">
                    <?php if (!empty($programs)): ?>
                      <?php foreach ($programs as $program): ?>
                        <?php
                        $hasCutoff = $program['cutoff_score'] !== null;
                        $hasCapacityConfig = $program['absorptive_capacity'] !== null
                            && $program['regular_percentage'] !== null
                            && $program['etg_percentage'] !== null;
                        $programName = trim((string) ($program['program_name'] ?? ''));
                        $programMajor = trim((string) ($program['major'] ?? ''));
                        $displayProgramName = strtoupper($programName);
                        if ($programMajor !== '') {
                            $displayProgramName .= preg_match('/major\s+in\s*$/i', $programName)
                                ? ' ' . $programMajor
                                : ' - ' . $programMajor;
                        }

                        $absorptiveCapacity = $hasCapacityConfig ? (int) $program['absorptive_capacity'] : 0;
                        $regularPercentage = $hasCapacityConfig ? (float) $program['regular_percentage'] : 0.0;
                        $etgPercentage = $hasCapacityConfig ? (float) $program['etg_percentage'] : 0.0;
                        $endorsementCapacity = ($hasCapacityConfig && $program['endorsement_capacity'] !== null)
                            ? max(0, (int) $program['endorsement_capacity'])
                            : 0;
                        $distributableCapacity = max(0, $absorptiveCapacity - $endorsementCapacity);
                        $regularSlots = $hasCapacityConfig
                            ? (int) round($distributableCapacity * ($regularPercentage / 100))
                            : 0;
                        $etgSlots = $hasCapacityConfig
                            ? max(0, $distributableCapacity - $regularSlots)
                            : 0;
                        $programId = (int) ($program['program_id'] ?? 0);
                        $programSearchText = strtolower(trim(
                            (string) ($program['program_name'] ?? '') . ' ' .
                            (string) ($program['program_code'] ?? '') . ' ' .
                            (string) ($program['major'] ?? '') . ' ' .
                            (string) ($program['campus_name'] ?? '') . ' ' .
                            (string) ($program['college_name'] ?? '')
                        ));
                        ?>
                        <div
                          class="col-12 mb-3 program-list-card"
                          id="program-card-<?= $programId; ?>"
                          data-program-id="<?= $programId; ?>"
                          data-search="<?= htmlspecialchars($programSearchText); ?>"
                        >
                          <div class="card border shadow-sm">
                            <div class="card-body">
                              <div class="d-flex justify-content-between align-items-start program-list-shell">
                                <div class="program-card-summary">
                                  <div class="program-card-meta">
                                    <?= htmlspecialchars((string) ($program['campus_name'] ?? '')); ?>
                                    /
                                    <?= htmlspecialchars((string) ($program['college_name'] ?? '')); ?>
                                  </div>

                                  <h5 class="program-card-title fw-semibold">
                                    <span class="program-name-main"><?= htmlspecialchars(strtoupper($programName)); ?></span>
                                    <?php if ($programMajor !== ''): ?>
                                      <span class="program-name-major">
                                        <?= preg_match('/major\s+in\s*$/i', $programName) ? '' : ' - '; ?>
                                        <?= htmlspecialchars($programMajor); ?>
                                      </span>
                                    <?php endif; ?>
                                  </h5>

                                  <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
                                    <span class="badge bg-label-secondary"><?= htmlspecialchars((string) ($program['program_code'] ?? '')); ?></span>
                                    <?php if ($hasCutoff): ?>
                                      <span class="program-chip program-chip-cutoff">Cut-Off <span class="badge bg-primary"><?= (int) $program['cutoff_score']; ?></span></span>
                                    <?php else: ?>
                                      <span class="program-chip program-chip-cutoff">Cut-Off <span class="badge bg-secondary">Not Set</span></span>
                                    <?php endif; ?>
                                    <?php if ($hasCapacityConfig): ?>
                                      <span class="program-chip program-chip-capacity">Capacity <span class="badge bg-info"><?= $absorptiveCapacity; ?></span></span>
                                      <span class="program-chip program-chip-ec">SCC <span class="badge bg-warning text-dark"><?= number_format($endorsementCapacity); ?></span></span>
                                    <?php endif; ?>
                                  </div>

                                  <?php if ($hasCapacityConfig): ?>
                                    <div class="program-capacity-line">
                                      Base Capacity:
                                      <span class="badge bg-label-info"><?= number_format($distributableCapacity); ?></span>
                                      Regular:
                                      <span class="badge bg-label-primary"><?= number_format($regularPercentage, 2); ?>% / <?= $regularSlots; ?></span>
                                      ETG:
                                      <span class="badge bg-label-success"><?= number_format($etgPercentage, 2); ?>% / <?= $etgSlots; ?></span>
                                    </div>
                                  <?php else: ?>
                                    <div class="program-capacity-line">
                                      <span class="badge bg-label-warning">Program rules not configured yet.</span>
                                    </div>
                                  <?php endif; ?>
                                </div>

                                <div class="d-flex gap-2 program-card-actions">
                                  <button
                                    type="button"
                                    class="btn btn-sm btn-info cutoff-btn"
                                    data-id="<?= $programId; ?>"
                                    data-name="<?= htmlspecialchars($displayProgramName); ?>"
                                    data-cutoff="<?= htmlspecialchars((string) ($program['cutoff_score'] ?? '')); ?>"
                                    data-capacity="<?= htmlspecialchars((string) ($program['absorptive_capacity'] ?? '')); ?>"
                                    data-regular-pct="<?= htmlspecialchars((string) ($program['regular_percentage'] ?? '')); ?>"
                                    data-etg-pct="<?= htmlspecialchars((string) ($program['etg_percentage'] ?? '')); ?>"
                                    data-endorsement-cap="<?= htmlspecialchars((string) ($program['endorsement_capacity'] ?? '')); ?>"
                                  >
                                    Configure Rules
                                  </button>
                                </div>
                              </div>
                            </div>
                          </div>
                        </div>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <div class="col-12">
                        <div class="alert alert-info mb-0">No active programs found.</div>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div id="programSearchEmptyState" class="alert alert-warning d-none mt-2 mb-0">
                    No programs match your search.
                  </div>
                </div>
              </div>
            </div>

            <?php include '../../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <div class="modal fade" id="cutoffModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-scrollable modal-fullscreen-sm-down program-config-modal">
        <div class="modal-content">
          <form method="POST" action="program_cutoff_action.php" id="cutoffForm">
            <div class="modal-header">
              <h5 class="modal-title">Configure Program Admission Rules</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
              <input type="hidden" name="program_id" id="cutoffProgramId">

              <div class="mb-3">
                <label class="form-label">Program</label>
                <input type="text" id="cutoffProgramName" class="form-control" readonly>
              </div>

              <div class="mb-3">
                <label class="form-label">Cut-Off Score</label>
                <input type="number" name="cutoff_score" id="cutoffScore" class="form-control" min="0" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Absorptive Capacity</label>
                <input type="number" name="absorptive_capacity" id="absorptiveCapacity" class="form-control" min="0" required>
              </div>

              <div class="mb-3">
                <label class="form-label">Special Case Capacity (SCC)</label>
                <input type="number" name="endorsement_capacity" id="endorsementCapacity" class="form-control" min="0" step="1" required>
              </div>

              <div class="row">
                <div class="col-md-6 mb-3">
                  <label class="form-label">% Regular</label>
                  <input type="number" name="regular_percentage" id="regularPercentage" class="form-control" min="0" max="100" step="0.01" required>
                </div>

                <div class="col-md-6 mb-3">
                  <label class="form-label">% ETG</label>
                  <input type="number" name="etg_percentage" id="etgPercentage" class="form-control" min="0" max="100" step="0.01" required>
                </div>
              </div>

              <div class="form-text" id="capacityPreview">
                Regular + ETG must total 100.00%.
              </div>

              <div class="small text-muted cutoff-preview-line mb-1" id="capacityBasePreview">
                <span class="me-1">Absorptive Capacity</span><span class="badge bg-label-info">0</span>
                <span class="ms-2 me-1">SCC</span><span class="badge bg-label-warning">0</span>
                <span class="ms-2 me-1">Base</span><span class="badge bg-label-primary">0</span>
              </div>

              <div class="small text-muted cutoff-preview-line" id="capacitySlotPreview">
                <span class="me-1">Slots : Regular</span><span class="badge bg-label-primary">0</span>
                <span class="ms-2 me-1">ETG</span><span class="badge bg-label-success">0</span>
              </div>
            </div>

            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" name="save_cutoff" class="btn btn-primary">Save</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../../assets/vendor/js/menu.js"></script>
    <script src="../../assets/js/main.js"></script>
    <script>
      const cutoffModalElement = document.getElementById('cutoffModal');
      const cutoffForm = document.getElementById('cutoffForm');
      const cutoffInput = document.getElementById('cutoffScore');
      const capacityInput = document.getElementById('absorptiveCapacity');
      const regularPercentageInput = document.getElementById('regularPercentage');
      const etgPercentageInput = document.getElementById('etgPercentage');
      const endorsementCapacityInput = document.getElementById('endorsementCapacity');
      const capacityPreview = document.getElementById('capacityPreview');
      const capacityBasePreview = document.getElementById('capacityBasePreview');
      const capacitySlotPreview = document.getElementById('capacitySlotPreview');
      const programSearchInput = document.getElementById('programSearchInput');
      const programCards = Array.from(document.querySelectorAll('.program-list-card'));
      const programSearchEmptyState = document.getElementById('programSearchEmptyState');
      const DEFAULT_ABSORPTIVE_CAPACITY = 0;
      const DEFAULT_ENDORSEMENT_CAPACITY = 0;
      const DEFAULT_REGULAR_PERCENTAGE = 95;
      const DEFAULT_ETG_PERCENTAGE = 5;

      function parseNumericValue(value, fallback) {
        const parsed = parseFloat(value);
        return Number.isFinite(parsed) ? parsed : fallback;
      }

      function updateCapacityPreview() {
        const capacity = Math.max(0, Math.floor(parseNumericValue(capacityInput.value, 0)));
        const regularPct = parseNumericValue(regularPercentageInput.value, 0);
        const etgPct = parseNumericValue(etgPercentageInput.value, 0);
        const endorsementCap = Math.max(0, Math.floor(parseNumericValue(endorsementCapacityInput.value, 0)));
        const totalPct = regularPct + etgPct;
        const isValidTotal = Math.abs(totalPct - 100) < 0.01;
        const isValidEndorsementCap = endorsementCap <= capacity;
        const baseCapacity = Math.max(0, capacity - endorsementCap);
        const regularSlots = Math.round(baseCapacity * (regularPct / 100));
        const etgSlots = Math.max(0, baseCapacity - regularSlots);

        const isValid = isValidTotal && isValidEndorsementCap;
        capacityPreview.textContent = `Regular + ETG = ${totalPct.toFixed(2)}%`;
        capacityPreview.classList.toggle('text-success', isValid);
        capacityPreview.classList.toggle('text-danger', !isValid);

        capacityBasePreview.innerHTML = `
          <span class="me-1">Absorptive Capacity</span><span class="badge bg-label-info">${capacity}</span>
          <span class="ms-2 me-1">SCC</span><span class="badge bg-label-warning">${endorsementCap}</span>
          <span class="ms-2 me-1">Base</span><span class="badge bg-label-primary">${baseCapacity}</span>
        `;

        capacitySlotPreview.innerHTML = `
          <span class="me-1">Slots : Regular</span><span class="badge bg-label-primary">${regularSlots}</span>
          <span class="ms-2 me-1">ETG</span><span class="badge bg-label-success">${etgSlots}</span>
        `;

        const percentageValidationMessage = isValidTotal
          ? ''
          : 'Regular and ETG percentages must total 100%.';
        const endorsementCapValidationMessage = isValidEndorsementCap
          ? ''
          : 'Endorsement Capacity cannot be greater than Absorptive Capacity.';

        regularPercentageInput.setCustomValidity(percentageValidationMessage);
        etgPercentageInput.setCustomValidity(percentageValidationMessage);
        endorsementCapacityInput.setCustomValidity(endorsementCapValidationMessage);
      }

      document.querySelectorAll('.cutoff-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
          document.getElementById('cutoffProgramId').value = this.dataset.id;
          document.getElementById('cutoffProgramName').value = this.dataset.name;
          cutoffInput.value = this.dataset.cutoff !== '' ? this.dataset.cutoff : 0;
          capacityInput.value = this.dataset.capacity !== '' ? this.dataset.capacity : DEFAULT_ABSORPTIVE_CAPACITY;
          regularPercentageInput.value = this.dataset.regularPct !== '' ? this.dataset.regularPct : DEFAULT_REGULAR_PERCENTAGE;
          etgPercentageInput.value = this.dataset.etgPct !== '' ? this.dataset.etgPct : DEFAULT_ETG_PERCENTAGE;
          endorsementCapacityInput.value = this.dataset.endorsementCap !== '' ? this.dataset.endorsementCap : DEFAULT_ENDORSEMENT_CAPACITY;

          updateCapacityPreview();
          bootstrap.Modal.getOrCreateInstance(cutoffModalElement).show();
        });
      });

      [capacityInput, regularPercentageInput, etgPercentageInput, endorsementCapacityInput].forEach(function (input) {
        input.addEventListener('input', updateCapacityPreview);
      });

      function applyProgramSearch() {
        const query = (programSearchInput?.value || '').trim().toLowerCase();
        let visibleCount = 0;

        programCards.forEach(function (card) {
          const haystack = String(card.getAttribute('data-search') || '').toLowerCase();
          const isVisible = query === '' || haystack.includes(query);
          card.classList.toggle('d-none', !isVisible);
          if (isVisible) {
            visibleCount++;
          }
        });

        if (programSearchEmptyState) {
          programSearchEmptyState.classList.toggle('d-none', visibleCount > 0);
        }
      }

      if (cutoffForm) {
        cutoffForm.addEventListener('submit', function () {
          updateCapacityPreview();
        });
      }

      if (programSearchInput) {
        programSearchInput.addEventListener('input', applyProgramSearch);
      }

      updateCapacityPreview();
      applyProgramSearch();
    </script>
  </body>
</html>
