<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/transfer_student.php
 * PURPOSE: Initiate Student Transfer (Phase 1 - UI Only)
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

$accountId   = (int) $_SESSION['accountid'];
$campusId    = (int) $_SESSION['campus_id'];
$assignedProgramId = (int) ($_SESSION['program_id'] ?? 0);
$placementId = isset($_GET['placement_result_id'])
    ? (int) $_GET['placement_result_id']
    : 0;

if ($placementId <= 0) {
    header('Location: index.php');
    exit;
}

/* ======================================================
   LOAD STUDENT + INTERVIEW
====================================================== */
$studentSql = "
SELECT 
    pr.id AS placement_result_id,
    pr.full_name,
    pr.examinee_number,
    pr.sat_score,

    si.interview_id,
    si.program_id,
    si.first_choice,
    si.classification,
    si.mobile_number,
    si.shs_track_id,
    si.interview_datetime,
    si.final_score,

    p.program_name,
    p.major

FROM tbl_placement_results pr
LEFT JOIN tbl_student_interview si 
    ON pr.id = si.placement_result_id
LEFT JOIN tbl_program p 
    ON si.program_id = p.program_id

WHERE pr.id = ?
AND si.program_chair_id = ?
LIMIT 1
";


$stmt = $conn->prepare($studentSql);
if (!$stmt) {
    error_log("SQL Error (studentSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmt->bind_param("ii", $placementId, $accountId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();

if (!$student || empty($student['interview_id'])) {
    header('Location: index.php');
    exit;
}


/**
 * ============================================================
 * CHECK IF THERE IS ALREADY A PENDING TRANSFER
 * ============================================================
 */

$pendingSql = "
    SELECT transfer_id
    FROM tbl_student_transfer_history
    WHERE interview_id = ?
    AND status = 'pending'
    LIMIT 1
";

$stmtPending = $conn->prepare($pendingSql);

if (!$stmtPending) {
    error_log("SQL Error (pendingSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtPending->bind_param("i", $student['interview_id']);
$stmtPending->execute();
$pendingResult = $stmtPending->get_result();

$hasPendingTransfer = $pendingResult->num_rows > 0;



/**
 * ============================================================
 * LOAD PROGRAMS (SAME CAMPUS ONLY)
 * - Exclude programs WITHOUT cut-off
 * - Include total enrolled students (based on first_choice)
 * ============================================================
 */

$programSql = "
SELECT 
    p.program_id,
    p.program_name,
    p.major,
    c.college_name,
    cam.campus_name,
    pc.cutoff_score,

    -- COUNT STUDENTS BASED ON FIRST CHOICE
    (
        SELECT COUNT(*)
        FROM tbl_student_interview si
        WHERE si.first_choice = p.program_id
        AND si.status = 'active'
    ) AS total_students

FROM tbl_program p
INNER JOIN tbl_college c 
    ON p.college_id = c.college_id
INNER JOIN tbl_campus cam 
    ON c.campus_id = cam.campus_id
INNER JOIN tbl_program_cutoff pc 
    ON p.program_id = pc.program_id  -- INNER JOIN = must have cut-off

WHERE cam.campus_id = ?
AND p.status = 'active'
AND p.program_id <> ?

ORDER BY p.program_name ASC
";


$stmtProg = $conn->prepare($programSql);
if (!$stmtProg) {
    error_log("SQL Error (programSql): " . $conn->error);
    header('Location: index.php?msg=server_error');
    exit;
}

$stmtProg->bind_param("ii", $campusId, $assignedProgramId);
$stmtProg->execute();
$programs = $stmtProg->get_result();
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
<title>Transfer Student</title>
    <meta name="description" content="" />
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

<style>
.program-card {
    cursor: pointer;
    transition: all 0.2s ease;
}
.program-card:hover {
    transform: scale(1.02);
    box-shadow: 0 6px 16px rgba(0,0,0,0.08);
}
.program-selected {
    border: 3px solid #28c76f !important;
    background: #e9f9f1;
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

<div class="d-flex justify-content-between align-items-center mb-4">

  <h4 class="mb-0">
    <i class="bx bx-transfer me-1"></i>
    Initiate Transfer
  </h4>

  <a href="index.php" class="btn btn-label-secondary btn-sm btn-primary">
    <i class="bx bx-arrow-back me-1"></i> Back
  </a>

</div>


<!-- ================= STUDENT SUMMARY ================= -->
<div class="card mb-4 shadow-sm border-0" 
     style="border-left:6px solid #28c76f; background:#f4fff8;">

  <div class="card-body py-3">

    <div class="d-flex justify-content-between align-items-center flex-wrap">

      <!-- LEFT -->
      <div>
        <div class="fw-semibold text-dark">
          <?= htmlspecialchars($student['full_name']); ?>
        </div>

        <small class="text-muted">
          Examinee #: <?= $student['examinee_number']; ?> |
          SAT: <span class="fw-bold text-success"><?= $student['sat_score']; ?></span> |
          Interview Score:
          <?= $student['final_score'] !== null 
                ? '<span class="fw-bold text-warning">'.number_format($student['final_score'],2).'%</span>'
                : 'N/A'; ?>
        </small>
      </div>

      <!-- RIGHT -->
      <div class="text-end small">

        <div>
          <span class="badge bg-label-primary">
            Current: <?= strtoupper($student['program_name']); ?>
          </span>
        </div>

        <div class="mt-1">
          <span class="badge bg-label-info">
            <?= $student['classification']; ?>
          </span>
        </div>

      </div>

    </div>

  </div>
</div>


<?php if ($hasPendingTransfer): ?>

<div class="alert alert-warning mb-4">
    <strong>Pending Transfer Exists.</strong><br>
    This student already has a pending transfer request.
    You must approve or reject it before creating a new one.
</div>

<?php endif; ?>


<!-- ================= PROGRAM LIST ================= -->
<form method="POST" action="process_transfer.php" <?= $hasPendingTransfer ? 'style="pointer-events:none; opacity:0.6;"' : ''; ?>>

<input type="hidden" name="interview_id" value="<?= $student['interview_id']; ?>">
<input type="hidden" name="from_program_id" value="<?= $student['program_id']; ?>">
<input type="hidden" name="to_program_id" id="to_program_id">

<!-- ============================================================
     SEARCH PROGRAM
============================================================ -->
<div class="card mb-4 shadow-sm">
  <div class="card-body">

    <div class="input-group">
      <span class="input-group-text">
        <i class="bx bx-search"></i>
      </span>
      <input type="text"
             id="programSearch"
             class="form-control"
             placeholder="Search program name or college...">
    </div>

  </div>
</div>



<div class="row">

<?php while ($row = $programs->fetch_assoc()): 

    $sat = (int)$student['sat_score'];
    $cutoff = (int)$row['cutoff_score'];
    $qualified = $sat >= $cutoff;

?>

<div class="col-md-4 mb-3">
  <div class="card program-card p-3 <?= !$qualified ? 'border border-danger opacity-50' : ''; ?>"
       data-qualified="<?= $qualified ? '1' : '0'; ?>"
       data-cutoff="<?= $cutoff; ?>"
       onclick="selectProgram(<?= $row['program_id']; ?>, this)">

        <small class="text-muted">
            <?= strtoupper($row['college_name']); ?>
        </small>

        <div class="fw-semibold mt-1">
            <?= strtoupper($row['program_name']); ?>
        </div>

        <?php if (!empty($row['major'])): ?>
            <div class="text-muted small">
                <?= strtoupper($row['major']); ?>
            </div>
        <?php endif; ?>

        <div class="mt-2">

            <span class="badge <?= $qualified ? 'bg-label-success' : 'bg-label-danger'; ?>">
                CUT-OFF: <?= $cutoff; ?>
            </span>

            <span class="badge bg-label-primary ms-2">
                STUDENTS: <?= $row['total_students']; ?>
            </span>

            <?php if (!$qualified): ?>
                <div class="text-danger small mt-1">
                    SAT BELOW CUT-OFF
                </div>
            <?php endif; ?>

        </div>

  </div>
</div>

<?php endwhile; ?>


</div>

<div class="mt-4">
  <label class="form-label">Remarks (Optional)</label>
  <textarea name="remarks" class="form-control"></textarea>
</div>

<div class="mt-4">
  <button type="submit" class="btn btn-primary">
    Submit Transfer
  </button>
  <a href="index.php" class="btn btn-secondary">Cancel</a>
</div>

</form>

</div>
<?php include '../footer.php'; ?>
<div class="content-backdrop fade"></div>
</div>
</div>
</div>
</div>
    <!-- Core JS -->
    <!-- build:js assets/vendor/js/core.js -->
    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>


<script>
function selectProgram(programId, element) {

  const qualified = element.getAttribute('data-qualified');

  if (qualified !== "1") {

    Swal.fire({
      icon: 'warning',
      title: 'Not Qualified',
      text: 'Student SAT score is below the required cut-off.',
    });

    return;
  }

  document.getElementById('to_program_id').value = programId;

  document.querySelectorAll('.program-card')
    .forEach(card => card.classList.remove('program-selected'));

  element.classList.add('program-selected');
}



/**
 * ============================================================
 * PROGRAM SEARCH FILTER
 * ============================================================
 */

document.getElementById('programSearch').addEventListener('keyup', function() {

  const value = this.value.toLowerCase();

  document.querySelectorAll('.program-card').forEach(card => {

    const text = card.innerText.toLowerCase();

    if (text.includes(value)) {
      card.closest('.col-md-4').style.display = '';
    } else {
      card.closest('.col-md-4').style.display = 'none';
    }

  });

});


</script>

</body>
</html>
