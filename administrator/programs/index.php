<?php
require_once '../../config/db.php';
session_start();


/**
 * ============================================================
 * PROGRAMS MANAGEMENT
 * ============================================================
 */

/* ------------------------------------------------------------
   FETCH ACTIVE CAMPUSES
------------------------------------------------------------ */
$campuses = [];

$campusSql = "
    SELECT campus_id, campus_name
    FROM tbl_campus
    WHERE status = 'active'
    ORDER BY campus_name ASC
";

$campusResult = $conn->query($campusSql);

while ($row = $campusResult->fetch_assoc()) {
    $campuses[] = $row;
}

/* ------------------------------------------------------------
   SELECTED FILTERS
------------------------------------------------------------ */
$selectedCampusId  = isset($_GET['campus_id']) ? (int)$_GET['campus_id'] : 0;
$selectedCollegeId = isset($_GET['college_id']) ? (int)$_GET['college_id'] : 0;

/* ------------------------------------------------------------
   FETCH COLLEGES BASED ON SELECTED CAMPUS
------------------------------------------------------------ */
$colleges = [];

if ($selectedCampusId > 0) {

    $collegeSql = "
        SELECT college_id, college_name
        FROM tbl_college
        WHERE campus_id = ?
        ORDER BY college_name ASC
    ";

    $stmt = $conn->prepare($collegeSql);
    $stmt->bind_param("i", $selectedCampusId);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $colleges[] = $row;
    }
}

/* ------------------------------------------------------------
   FETCH PROGRAMS
------------------------------------------------------------ */
$programs = [];

$sql = "
    SELECT 
        p.*,
        col.college_name,
        c.campus_name,
        pc.cutoff_score
    FROM tbl_program p
    JOIN tbl_college col 
        ON col.college_id = p.college_id
    JOIN tbl_campus c 
        ON c.campus_id = col.campus_id
    LEFT JOIN tbl_program_cutoff pc
        ON pc.program_id = p.program_id
    WHERE p.status = 'active'
";


$params = [];
$types  = "";

if ($selectedCampusId > 0) {
    $sql .= " AND c.campus_id = ?";
    $types .= "i";
    $params[] = $selectedCampusId;
}

if ($selectedCollegeId > 0) {
    $sql .= " AND col.college_id = ?";
    $types .= "i";
    $params[] = $selectedCollegeId;
}

$sql .= " ORDER BY p.program_name ASC";

$stmt = $conn->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

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

<!-- FILTER SECTION -->
<form method="GET" class="row g-3 mb-4">

    <div class="col-md-4">
        <label class="form-label">Campus</label>
        <select name="campus_id"
                class="form-select"
                onchange="this.form.submit()">
            <option value="0">All Campuses</option>
            <?php foreach ($campuses as $campus): ?>
                <option value="<?= $campus['campus_id']; ?>"
                    <?= $selectedCampusId == $campus['campus_id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($campus['campus_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

    <div class="col-md-4">
        <label class="form-label">College</label>
        <select name="college_id"
                class="form-select"
                onchange="this.form.submit()">
            <option value="0">All Colleges</option>

            <?php foreach ($colleges as $college): ?>
                <option value="<?= $college['college_id']; ?>"
                    <?= $selectedCollegeId == $college['college_id'] ? 'selected' : ''; ?>>
                    <?= htmlspecialchars($college['college_name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>

</form>

<!-- PROGRAM CARDS -->
<div class="row">

<?php if (count($programs) > 0): ?>

<?php foreach ($programs as $program): ?>

<div class="col-12 mb-3">
<div class="card border shadow-sm">
<div class="card-body">

<div class="d-flex justify-content-between align-items-start">

<div>

    <span class="text-muted small">
        <?= htmlspecialchars($program['campus_name']); ?>
        /
        <?= htmlspecialchars($program['college_name']); ?>
    </span>

    <h5 class="mb-1 fw-semibold">
        <?= htmlspecialchars($program['program_name']); ?>

        <?php if ($program['cutoff_score'] !== null): ?>
            <span class="badge bg-label-primary ms-2">
                Cut-Off: <?= (int)$program['cutoff_score']; ?>
            </span>
        <?php else: ?>
            <span class="badge bg-secondary ms-2">
                No Cut-Off
            </span>
        <?php endif; ?>

    </h5>

    <span class="text-muted small">
        <?= htmlspecialchars($program['program_code']); ?>
    </span>

    <?php if ($program['status'] == 'active'): ?>
        <span class="badge bg-success">ACTIVE</span>
    <?php else: ?>
        <span class="badge bg-secondary">INACTIVE</span>
    <?php endif; ?>

</div>

<div class="d-flex gap-2">

    <button type="button"
            class="btn btn-sm btn-info cutoff-btn"
            data-id="<?= $program['program_id']; ?>"
            data-name="<?= htmlspecialchars($program['program_name']); ?>"
            data-cutoff="<?= $program['cutoff_score']; ?>">
        Cut-Off
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
                    <h5 class="modal-title">Set Program Cut-Off</h5>
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
                               required>
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
document.querySelectorAll('.cutoff-btn').forEach(function(btn) {

    btn.addEventListener('click', function() {

        document.getElementById('cutoffProgramId').value = this.dataset.id;
        document.getElementById('cutoffProgramName').value = this.dataset.name;
document.getElementById('cutoffScore').value =
    this.dataset.cutoff !== undefined && this.dataset.cutoff !== null
        ? this.dataset.cutoff
        : 0;

        var modal = new bootstrap.Modal(
            document.getElementById('cutoffModal')
        );
        modal.show();
    });

});
</script>

</body>
</html>
