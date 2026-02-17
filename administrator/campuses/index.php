<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

/**
 * ============================================================
 * CAMPUSES MANAGEMENT
 * ============================================================
 */

// Fetch campuses
$sql = "
    SELECT 
        c.*,
        COUNT(col.college_id) AS total_colleges
    FROM tbl_campus c
    LEFT JOIN tbl_college col 
        ON col.campus_id = c.campus_id
        AND col.status = 'active'
    GROUP BY c.campus_id
    ORDER BY c.campus_name ASC
";
$result = $conn->query($sql);
$campuses = [];

if ($result && $result->num_rows > 0) {
    while ($row = $result->fetch_assoc()) {
        $campuses[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed">
<head>
    <meta charset="utf-8" />
    <title>Campuses Management</title>
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
        <h5 class="mb-0">Campuses</h5>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addCampusModal">
            Add Campus
        </button>
    </div>

    <div class="card-body">
        <div class="card-body">
            <div class="row">

                <?php foreach ($campuses as $campus): ?>
                    <div class="col-12 mb-3">
                        <div class="card border shadow-sm">
                            <div class="card-body">

                                <div class="d-flex justify-content-between align-items-start">

                                    <!-- LEFT SIDE -->
                                    <div>
                                        <span class="text-muted small">
                                            <?= htmlspecialchars($campus['campus_code']); ?>
                                        </span>

                                        <div class="d-flex align-items-center gap-2">
                                            <h5 class="mb-1 fw-semibold">
                                                <?= htmlspecialchars($campus['campus_name']); ?>
                                            </h5>

                                            <span class="badge bg-label-primary">
                                                <?= (int)$campus['total_colleges']; ?> Colleges
                                            </span>
                                        </div>


                                        <?php if ($campus['status'] == 'active'): ?>
                                            <span class="badge bg-success">ACTIVE</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">INACTIVE</span>
                                        <?php endif; ?>
                                    </div>

                                    <!-- RIGHT SIDE ACTIONS -->
                                    <div class="text-end">

                                        <button class="btn btn-sm btn-warning mb-1 edit-btn"
                                            data-id="<?= $campus['campus_id']; ?>"
                                            data-code="<?= htmlspecialchars($campus['campus_code']); ?>"
                                            data-name="<?= htmlspecialchars($campus['campus_name']); ?>">
                                            Edit
                                        </button>

                                        <form method="POST" action="campus_action.php" style="display:inline;">
                                            <input type="hidden" name="campus_id" value="<?= $campus['campus_id']; ?>">
                                            <input type="hidden" name="current_status" value="<?= $campus['status']; ?>">
                                            <button type="submit" name="toggle_status"
                                                class="btn btn-sm <?= $campus['status'] == 'active' ? 'btn-danger' : 'btn-success'; ?>">
                                                <?= $campus['status'] == 'active' ? 'Deactivate' : 'Activate'; ?>
                                            </button>
                                        </form>

                                    </div>

                                </div>

                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>

            </div>
        </div>

    </div>
</div>

</div>
</div>
</div>
</div>
</div>

<!-- ADD MODAL -->
<div class="modal fade" id="addCampusModal">
<div class="modal-dialog">
<div class="modal-content">
<form method="POST" action="campus_action.php">
<div class="modal-header">
<h5 class="modal-title">Add Campus</h5>
<button type="button" class="btn-close" data-bs-dismiss="modal"></button>
</div>
<div class="modal-body">
    <div class="mb-3">
        <label>Campus Code</label>
        <input type="text" name="campus_code" class="form-control" required>
    </div>
    <div class="mb-3">
        <label>Campus Name</label>
        <input type="text" name="campus_name" class="form-control" required>
    </div>
</div>
<div class="modal-footer">
    <button type="submit" name="add_campus" class="btn btn-primary">Save</button>
</div>
</form>
</div>
</div>
</div>

<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>
<script src="../../assets/vendor/libs/datatables/jquery.dataTables.js"></script>
<script>
$(document).ready(function(){
    $('#campusTable').DataTable();
});
</script>

</body>
</html>
