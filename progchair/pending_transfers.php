<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/pending_transfers.php
 * PURPOSE: View & Approve Pending Student Transfers
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

/* ======================================================
   GUARD
====================================================== */
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$programId = (int) $_SESSION['program_id'];

/* ======================================================
   LOAD PENDING TRANSFERS (ONLY FOR MY PROGRAM)
====================================================== */
$sql = "
SELECT 
    t.transfer_id,
    t.interview_id,
    t.from_program_id,
    t.to_program_id,
    t.transfer_datetime,
    t.remarks,

    pr.full_name,
    pr.examinee_number,
    pr.sat_score,

    si.final_score,

    p_from.program_name AS from_program,
    p_to.program_name AS to_program

FROM tbl_student_transfer_history t
INNER JOIN tbl_student_interview si
    ON t.interview_id = si.interview_id
INNER JOIN tbl_placement_results pr
    ON si.placement_result_id = pr.id
INNER JOIN tbl_program p_from
    ON t.from_program_id = p_from.program_id
INNER JOIN tbl_program p_to
    ON t.to_program_id = p_to.program_id

WHERE t.status = 'pending'
AND t.to_program_id = ?

ORDER BY t.transfer_datetime ASC
";

$stmt = $conn->prepare($sql);
$stmt->bind_param("i", $programId);
$stmt->execute();
$result = $stmt->get_result();
?>
<!DOCTYPE html>
<html lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free">

<head>
<meta charset="utf-8">
<title>Pending Transfers</title>

<link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
<link rel="stylesheet" href="../assets/vendor/css/core.css" />
<link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
<link rel="stylesheet" href="../assets/css/demo.css" />
</head>

<body>

<div class="layout-wrapper layout-content-navbar">
<div class="layout-container">

<?php include 'sidebar.php'; ?>

<div class="layout-page">
<?php include 'header.php'; ?>

<div class="content-wrapper">
<div class="container-xxl flex-grow-1 container-p-y">

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <i class="bx bx-time-five me-1"></i>
        Pending Transfers
    </h4>

    <a href="index.php" class="btn btn-label-secondary btn-sm">
        <i class="bx bx-arrow-back me-1"></i> Back
    </a>
</div>

<?php if ($result->num_rows === 0): ?>
    <div class="alert alert-success">
        No pending transfers for your program.
    </div>
<?php endif; ?>

<?php while ($row = $result->fetch_assoc()): ?>

<div class="card mb-4 shadow-sm">
    <div class="card-body">

        <div class="fw-semibold">
            <?= htmlspecialchars($row['full_name']); ?>
        </div>

        <small class="text-muted">
            Examinee #: <?= $row['examinee_number']; ?> |
            SAT: <?= $row['sat_score']; ?> |
            Interview: <?= $row['final_score'] !== null ? number_format($row['final_score'],2).'%' : 'N/A'; ?>
        </small>

        <hr>

        <div class="row">
            <div class="col-md-6">
                <strong>From:</strong> <?= $row['from_program']; ?>
            </div>
            <div class="col-md-6">
                <strong>To:</strong> <?= $row['to_program']; ?>
            </div>
        </div>

        <?php if (!empty($row['remarks'])): ?>
            <div class="mt-2">
                <strong>Remarks:</strong>
                <div class="text-muted"><?= htmlspecialchars($row['remarks']); ?></div>
            </div>
        <?php endif; ?>

        <div class="mt-3">

            <a href="approve_transfer.php?transfer_id=<?= $row['transfer_id']; ?>"
               class="btn btn-success btn-sm">
                Approve
            </a>

            <a href="reject_transfer.php?transfer_id=<?= $row['transfer_id']; ?>"
               class="btn btn-danger btn-sm">
                Reject
            </a>

        </div>

    </div>
</div>

<?php endwhile; ?>

</div>
<?php include '../footer.php'; ?>
<div class="content-backdrop fade"></div>
</div>
</div>
</div>
</div>

</body>
</html>
