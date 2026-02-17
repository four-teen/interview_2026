<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

/**
 * ============================================================
 * COLLEGES MANAGEMENT
 * ============================================================
 */

/* ------------------------------------------------------------
   FETCH ACTIVE CAMPUSES (For Filter + Modal Dropdown)
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
   GET SELECTED CAMPUS FILTER
------------------------------------------------------------ */
$selectedCampusId = isset($_GET['campus_id'])
    ? (int) $_GET['campus_id']
    : 0;

/* ------------------------------------------------------------
   FETCH COLLEGES WITH PROGRAM COUNT
------------------------------------------------------------ */
$colleges = [];

if ($selectedCampusId > 0) {

    $sql = "
        SELECT 
            col.*,
            COUNT(p.program_id) AS total_programs
        FROM tbl_college col
        LEFT JOIN tbl_program p 
            ON p.college_id = col.college_id
            AND p.status = 'active'
        WHERE col.campus_id = ?
        GROUP BY col.college_id
        ORDER BY col.college_name ASC
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("i", $selectedCampusId);
    $stmt->execute();
    $result = $stmt->get_result();

} else {

    $sql = "
        SELECT 
            col.*,
            COUNT(p.program_id) AS total_programs
        FROM tbl_college col
        LEFT JOIN tbl_program p 
            ON p.college_id = col.college_id
            AND p.status = 'active'
        GROUP BY col.college_id
        ORDER BY col.college_name ASC
    ";

    $result = $conn->query($sql);
}

while ($row = $result->fetch_assoc()) {
    $colleges[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">

<head>
    <meta charset="utf-8" />
    <title>Colleges Management</title>
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
        <h5 class="mb-0">Colleges</h5>

        <button class="btn btn-primary btn-sm"
                data-bs-toggle="modal"
                data-bs-target="#addCollegeModal">
            Add College
        </button>
    </div>

    <div class="card-body">

        <!-- CAMPUS FILTER -->
        <form method="GET" class="mb-4">
            <label class="form-label">Filter by Campus</label>
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
        </form>

        <!-- COLLEGE CARDS -->
        <div class="row">

        <?php if (count($colleges) > 0): ?>

            <?php foreach ($colleges as $college): ?>

                <div class="col-12 mb-3">
                    <div class="card border shadow-sm">
                        <div class="card-body">

                            <div class="d-flex justify-content-between align-items-start">

                                <!-- LEFT SIDE -->
                                <div>

                                    <span class="text-muted small">
                                        <?= htmlspecialchars($college['college_code']); ?>
                                    </span>

                                    <div class="d-flex align-items-center gap-2 flex-wrap">
                                        <h5 class="mb-1 fw-semibold">
                                            <?= htmlspecialchars($college['college_name']); ?>
                                        </h5>

                                        <span class="badge bg-label-info">
                                            <?= (int) $college['total_programs']; ?> Programs
                                        </span>
                                    </div>

                                    <?php if ($college['status'] == 'active'): ?>
                                        <span class="badge bg-success">ACTIVE</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">INACTIVE</span>
                                    <?php endif; ?>

                                </div>

                                <!-- RIGHT SIDE -->
                                <div class="text-end">

                                    <button class="btn btn-sm btn-warning mb-1">
                                        Edit
                                    </button>

                                    <form method="POST"
                                          action="college_action.php"
                                          style="display:inline;">

                                        <input type="hidden"
                                               name="college_id"
                                               value="<?= $college['college_id']; ?>">

                                        <input type="hidden"
                                               name="current_status"
                                               value="<?= $college['status']; ?>">

                                        <button type="submit"
                                                name="toggle_status"
                                                class="btn btn-sm <?= $college['status']=='active' ? 'btn-danger' : 'btn-success'; ?>">
                                            <?= $college['status']=='active' ? 'Deactivate' : 'Activate'; ?>
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
                    No colleges found.
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

<!-- ADD COLLEGE MODAL -->
<div class="modal fade" id="addCollegeModal">
<div class="modal-dialog">
<div class="modal-content">

<form method="POST" action="college_action.php">

    <div class="modal-header">
        <h5 class="modal-title">Add College</h5>
        <button type="button"
                class="btn-close"
                data-bs-dismiss="modal"></button>
    </div>

    <div class="modal-body">

        <div class="mb-3">
            <label>Campus</label>
            <select name="campus_id"
                    class="form-select"
                    required>
                <option value="">Select Campus</option>

                <?php foreach ($campuses as $campus): ?>
                    <option value="<?= $campus['campus_id']; ?>">
                        <?= htmlspecialchars($campus['campus_name']); ?>
                    </option>
                <?php endforeach; ?>

            </select>
        </div>

        <div class="mb-3">
            <label>College Code</label>
            <input type="text"
                   name="college_code"
                   class="form-control"
                   required>
        </div>

        <div class="mb-3">
            <label>College Name</label>
            <input type="text"
                   name="college_name"
                   class="form-control"
                   required>
        </div>

    </div>

    <div class="modal-footer">
        <button type="submit"
                name="add_college"
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

</body>
</html>
