<?php
require_once '../../config/db.php';
require_once '../../config/system_controls.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

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
$programLoginLockMap = get_program_login_lock_map($conn);

if (empty($_SESSION['admin_program_login_csrf'])) {
    $_SESSION['admin_program_login_csrf'] = bin2hex(random_bytes(32));
}
$programLoginCsrfToken = (string) $_SESSION['admin_program_login_csrf'];

/**
 * ============================================================
 * PROGRAMS MANAGEMENT
 * ============================================================
 */

/* ------------------------------------------------------------
   FETCH PROGRAMS
------------------------------------------------------------ */
$programs = [];

$endorsementCapacitySelect = $hasEndorsementCapacityColumn
    ? 'pc.endorsement_capacity'
    : ($hasEndorsementPercentageColumn
        ? 'pc.endorsement_percentage AS endorsement_capacity'
        : 'NULL AS endorsement_capacity');

$sql = "
    SELECT 
        p.*,
        col.college_name,
        c.campus_name,
        pc.cutoff_score,
        pc.absorptive_capacity,
        pc.regular_percentage,
        pc.etg_percentage,
        {$endorsementCapacitySelect}
    FROM tbl_program p
    JOIN tbl_college col 
        ON col.college_id = p.college_id
    JOIN tbl_campus c 
        ON c.campus_id = col.campus_id
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    WHERE p.status = 'active'
";


$sql .= " ORDER BY p.program_name ASC";

$stmt = $conn->prepare($sql);

$stmt->execute();
$result = $stmt->get_result();

while ($row = $result->fetch_assoc()) {
    $programs[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
<meta charset="utf-8" />
<title>Programs Management</title>
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
      }

      .program-capacity-line .badge {
        font-size: 0.72rem;
      }

      .cutoff-preview-line .badge {
        font-size: 0.72rem;
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

<div class="card-header d-flex justify-content-between align-items-center">
    <h5 class="mb-0">Programs</h5>

    <button class="btn btn-primary btn-sm"
            data-bs-toggle="modal"
            data-bs-target="#addProgramModal">
        Add Program
    </button>
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

<!-- PROGRAM CARDS -->
<div class="row" id="programCardsContainer">

<?php if (count($programs) > 0): ?>

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

$absorptiveCapacity = $hasCapacityConfig ? (int)$program['absorptive_capacity'] : 0;
$regularPercentage  = $hasCapacityConfig ? (float)$program['regular_percentage'] : 0.0;
$etgPercentage      = $hasCapacityConfig ? (float)$program['etg_percentage'] : 0.0;
$endorsementCapacity = ($hasCapacityConfig && $program['endorsement_capacity'] !== null)
    ? max(0, (int)$program['endorsement_capacity'])
    : 0.0;
$distributableCapacity = max(0, $absorptiveCapacity - $endorsementCapacity);

$regularSlots = $hasCapacityConfig
    ? (int) round($distributableCapacity * ($regularPercentage / 100))
    : 0;
$etgSlots = $hasCapacityConfig
    ? max(0, $distributableCapacity - $regularSlots)
    : 0;
$programId = (int) ($program['program_id'] ?? 0);
$isProgramLoginUnlocked = (bool) ($programLoginLockMap[$programId] ?? false);
$programSearchText = strtolower(trim(
    (string) ($program['program_name'] ?? '') . ' ' .
    (string) ($program['program_code'] ?? '') . ' ' .
    (string) ($program['major'] ?? '') . ' ' .
    (string) ($program['campus_name'] ?? '') . ' ' .
    (string) ($program['college_name'] ?? '')
));
?>

<div class="col-12 mb-3 program-list-card" data-search="<?= htmlspecialchars($programSearchText); ?>">
<div class="card border shadow-sm">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start">

<div>

    <div class="program-card-meta">
        <?= htmlspecialchars($program['campus_name']); ?>
        /
        <?= htmlspecialchars($program['college_name']); ?>
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
        <span class="badge bg-label-secondary"><?= htmlspecialchars($program['program_code']); ?></span>
        <?php if ($program['status'] == 'active'): ?>
            <span class="badge bg-success">ACTIVE</span>
        <?php else: ?>
            <span class="badge bg-secondary">INACTIVE</span>
        <?php endif; ?>
        <?php if ($isProgramLoginUnlocked): ?>
            <span class="badge bg-label-success">LOGIN UNLOCKED</span>
        <?php else: ?>
            <span class="badge bg-label-danger">LOGIN LOCKED</span>
        <?php endif; ?>
        <?php if ($hasCutoff): ?>
            <span class="program-chip program-chip-cutoff">Cut-Off <span class="badge bg-primary"><?= (int)$program['cutoff_score']; ?></span></span>
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
    <?php endif; ?>

</div>

<div class="d-flex gap-2">
    <form method="POST"
          action="toggle_program_login_lock.php"
          class="m-0">
        <input type="hidden"
               name="csrf_token"
               value="<?= htmlspecialchars($programLoginCsrfToken); ?>">
        <input type="hidden"
               name="program_id"
               value="<?= $programId; ?>">
        <input type="hidden"
               name="action"
               value="<?= $isProgramLoginUnlocked ? 'lock' : 'unlock'; ?>">
        <button type="submit"
                class="btn btn-sm <?= $isProgramLoginUnlocked ? 'btn-warning' : 'btn-success'; ?>">
            <?= $isProgramLoginUnlocked ? 'Lock Login' : 'Unlock Login'; ?>
        </button>
    </form>

    <button type="button"
            class="btn btn-sm btn-info cutoff-btn"
            data-id="<?= $program['program_id']; ?>"
            data-name="<?= htmlspecialchars($displayProgramName); ?>"
            data-cutoff="<?= $program['cutoff_score']; ?>"
            data-capacity="<?= $program['absorptive_capacity']; ?>"
            data-regular-pct="<?= $program['regular_percentage']; ?>"
            data-etg-pct="<?= $program['etg_percentage']; ?>"
            data-endorsement-cap="<?= htmlspecialchars((string) ($program['endorsement_capacity'] ?? '')); ?>">
        Configure Rules
    </button>

    <form method="POST"
          action="program_action.php"
          class="m-0">

        <input type="hidden"
               name="program_id"
               value="<?= $program['program_id']; ?>">

        <input type="hidden"
               name="current_status"
               value="<?= $program['status']; ?>">

        <button type="submit"
                name="toggle_status"
                class="btn btn-sm <?= $program['status']=='active'?'btn-danger':'btn-success'; ?>">
            <?= $program['status']=='active'?'Deactivate':'Activate'; ?>
        </button>

    </form>

</div>


</div>
</div>
</div>
</div>

<?php endforeach; ?>

<?php else: ?>

<div class="col-12">
    <div class="alert alert-info">
        No programs found.
    </div>
</div>

<?php endif; ?>

</div>

<div id="programSearchEmptyState" class="alert alert-warning d-none mt-2 mb-0">
    No programs match your search.
</div>

</div>
</div>

</div>
</div>
</div>
</div>
</div>
</div>

<!-- ADD PROGRAM MODAL -->
<div class="modal fade" id="addProgramModal">
<div class="modal-dialog">
<div class="modal-content">

<form method="POST" action="program_action.php">

<div class="modal-header">
    <h5 class="modal-title">Add Program</h5>
    <button type="button"
            class="btn-close"
            data-bs-dismiss="modal"></button>
</div>

<div class="modal-body">

    <div class="mb-3">
        <label>College</label>
        <select name="college_id"
                class="form-select"
                required>
            <option value="">Select College</option>

            <?php
            $allCollegeSql = "
                SELECT college_id, college_name
                FROM tbl_college
                WHERE status='active'
                ORDER BY college_name ASC
            ";
            $allCollegeResult = $conn->query($allCollegeSql);
            while ($col = $allCollegeResult->fetch_assoc()):
            ?>
                <option value="<?= $col['college_id']; ?>">
                    <?= htmlspecialchars($col['college_name']); ?>
                </option>
            <?php endwhile; ?>
        </select>
    </div>

    <div class="mb-3">
        <label>Program Code</label>
        <input type="text"
               name="program_code"
               class="form-control"
               required>
    </div>

    <div class="mb-3">
        <label>Program Name</label>
        <input type="text"
               name="program_name"
               class="form-control"
               required>
    </div>

</div>

<div class="modal-footer">
    <button type="submit"
            name="add_program"
            class="btn btn-primary">
        Save
    </button>
</div>

</form>

</div>
</div>
</div>

<!-- CUT-OFF MODAL -->
<div class="modal fade" id="cutoffModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">

            <form method="POST" action="program_cutoff_action.php">

                <div class="modal-header">
                    <h5 class="modal-title">Configure Program Admission Rules</h5>
                    <button type="button"
                            class="btn-close"
                            data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">

                    <input type="hidden"
                           name="program_id"
                           id="cutoffProgramId">

                    <div class="mb-3">
                        <label class="form-label">Program</label>
                        <input type="text"
                               id="cutoffProgramName"
                               class="form-control"
                               readonly>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Cut-Off Score</label>
                        <input type="number"
                               name="cutoff_score"
                               id="cutoffScore"
                               class="form-control"
                               min="0"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Absorptive Capacity</label>
                        <input type="number"
                               name="absorptive_capacity"
                               id="absorptiveCapacity"
                               class="form-control"
                               min="0"
                               required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Special Case Capacity (SCC)</label>
                        <input type="number"
                               name="endorsement_capacity"
                               id="endorsementCapacity"
                               class="form-control"
                               min="0"
                               step="1"
                               required>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">% Regular</label>
                            <input type="number"
                                   name="regular_percentage"
                                   id="regularPercentage"
                                   class="form-control"
                                   min="0"
                                   max="100"
                                   step="0.01"
                                   required>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">% ETG</label>
                            <input type="number"
                                   name="etg_percentage"
                                   id="etgPercentage"
                                   class="form-control"
                                   min="0"
                                   max="100"
                                   step="0.01"
                                   required>
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
                    <button type="submit"
                            name="save_cutoff"
                            class="btn btn-primary">
                        Save
                    </button>
                </div>

            </form>

        </div>
    </div>
</div>



<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>

<script>
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

document.querySelectorAll('.cutoff-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        document.getElementById('cutoffProgramId').value = this.dataset.id;
        document.getElementById('cutoffProgramName').value = this.dataset.name;
        cutoffInput.value = this.dataset.cutoff !== '' ? this.dataset.cutoff : 0;
        capacityInput.value = this.dataset.capacity !== '' ? this.dataset.capacity : DEFAULT_ABSORPTIVE_CAPACITY;
        regularPercentageInput.value = this.dataset.regularPct !== '' ? this.dataset.regularPct : DEFAULT_REGULAR_PERCENTAGE;
        etgPercentageInput.value = this.dataset.etgPct !== '' ? this.dataset.etgPct : DEFAULT_ETG_PERCENTAGE;
        endorsementCapacityInput.value = this.dataset.endorsementCap !== '' ? this.dataset.endorsementCap : DEFAULT_ENDORSEMENT_CAPACITY;

        updateCapacityPreview();

        var modal = new bootstrap.Modal(
            document.getElementById('cutoffModal')
        );
        modal.show();
    });

});

[capacityInput, regularPercentageInput, etgPercentageInput, endorsementCapacityInput].forEach(function(input) {
    input.addEventListener('input', updateCapacityPreview);
});

updateCapacityPreview();

function applyProgramSearch() {
    const query = (programSearchInput?.value || '').trim().toLowerCase();
    let visibleCount = 0;

    programCards.forEach(function(card) {
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

if (programSearchInput) {
    programSearchInput.addEventListener('input', applyProgramSearch);
}

applyProgramSearch();
</script>

</body>
</html>
