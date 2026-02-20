<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
require_once '../config/session_security.php';
secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'student')) {
    header('Location: ../index.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
    header('Location: change_password.php');
    exit;
}

if (!ensure_student_credentials_table($conn)) {
    http_response_code(500);
    exit('Student portal initialization failed.');
}

$credentialId = (int) ($_SESSION['student_credential_id'] ?? 0);
if ($credentialId <= 0) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$sql = "
    SELECT
        sc.credential_id,
        sc.examinee_number,
        sc.must_change_password,
        pr.id AS placement_result_id,
        pr.full_name,
        pr.sat_score,
        pr.qualitative_text,
        pr.preferred_program,
        pr.upload_batch_id,
        pr.overall_standard_score,
        pr.overall_stanine,
        pr.overall_qualitative_text,
        si.interview_id,
        si.classification,
        si.mobile_number,
        si.interview_datetime,
        si.final_score,
        si.first_choice,
        si.second_choice,
        si.third_choice,
        si.shs_track_id,
        si.etg_class_id,
        cam.campus_name,
        t.track AS shs_track_name,
        ec.class_desc AS etg_class_name,
        p1.program_name AS first_program_name,
        p1.major AS first_program_major,
        p2.program_name AS second_program_name,
        p2.major AS second_program_major,
        p3.program_name AS third_program_name,
        p3.major AS third_program_major
    FROM tbl_student_credentials sc
    LEFT JOIN tbl_placement_results pr
      ON pr.id = sc.placement_result_id
    LEFT JOIN tbl_student_interview si
      ON si.examinee_number = sc.examinee_number
     AND si.status = 'active'
    LEFT JOIN tbl_campus cam
      ON cam.campus_id = si.campus_id
    LEFT JOIN tb_ltrack t
      ON t.trackid = si.shs_track_id
    LEFT JOIN tbl_etg_class ec
      ON ec.etgclassid = si.etg_class_id
    LEFT JOIN tbl_program p1
      ON p1.program_id = si.first_choice
    LEFT JOIN tbl_program p2
      ON p2.program_id = si.second_choice
    LEFT JOIN tbl_program p3
      ON p3.program_id = si.third_choice
    WHERE sc.credential_id = ?
      AND sc.status = 'active'
    ORDER BY si.interview_datetime DESC, si.interview_id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Failed to load student dashboard.');
}
$stmt->bind_param('i', $credentialId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

if ((int) ($student['must_change_password'] ?? 0) === 1) {
    $_SESSION['student_must_change_password'] = true;
    header('Location: change_password.php');
    exit;
}

function format_program_label($name, $major)
{
    $name = trim((string) $name);
    $major = trim((string) $major);
    if ($name === '') {
        return 'N/A';
    }
    if ($major === '') {
        return strtoupper($name);
    }
    return strtoupper($name . ' - ' . $major);
}

$finalScore = $student['final_score'];
$hasScoredInterview = ($finalScore !== null && $finalScore !== '');
$finalScoreDisplay = $hasScoredInterview ? number_format((float) $finalScore, 2) . '%' : 'Pending';
$scoreBadgeClass = $hasScoredInterview ? 'bg-label-success' : 'bg-label-warning';

$studentName = trim((string) ($student['full_name'] ?? ''));
if ($studentName === '') {
    $studentName = 'Student';
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-navbar-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
    <title>Student Dashboard - Interview</title>
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

    <style>
      .student-topbar {
        background: #fff;
        border: 1px solid #e4e8f0;
        border-radius: 0.9rem;
      }

      .student-metric-card {
        border: 1px solid #e8edf6;
        border-radius: 0.85rem;
      }

      .student-label {
        font-size: 0.72rem;
        text-transform: uppercase;
        color: #8190a8;
        letter-spacing: 0.05em;
        margin-bottom: 0.22rem;
      }
    </style>
  </head>

  <body>
    <div class="container-xxl flex-grow-1 container-p-y">
      <div class="student-topbar p-3 p-md-4 mb-4 d-flex flex-wrap align-items-center justify-content-between gap-3">
        <div>
          <h5 class="mb-1">Student Dashboard</h5>
          <div class="text-muted small">
            Welcome, <strong><?= htmlspecialchars($studentName); ?></strong>
          </div>
          <div class="text-muted small">Examinee Number: <?= htmlspecialchars((string) $student['examinee_number']); ?></div>
        </div>
        <div class="d-flex gap-2">
          <a href="change_password.php" class="btn btn-outline-primary btn-sm">Change Password</a>
          <a href="../logout.php" class="btn btn-outline-secondary btn-sm">Log Out</a>
        </div>
      </div>

      <?php if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1'): ?>
        <?php $emailNotice = (string) ($_GET['email_notice'] ?? ''); ?>
        <?php if ($emailNotice === 'sent'): ?>
          <div class="alert alert-success">
            Password changed successfully. Your username and new password were sent to your active email.
          </div>
        <?php elseif ($emailNotice === 'failed'): ?>
          <div class="alert alert-warning">
            Password changed successfully, but email delivery failed. Please verify mail server settings.
          </div>
        <?php else: ?>
          <div class="alert alert-success">
            Password changed successfully.
          </div>
        <?php endif; ?>
      <?php endif; ?>

      <div class="row g-3 mb-4">
        <div class="col-md-4">
          <div class="card student-metric-card h-100">
            <div class="card-body">
              <div class="student-label">Interview Score</div>
              <h4 class="mb-0"><?= htmlspecialchars($finalScoreDisplay); ?></h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card student-metric-card h-100">
            <div class="card-body">
              <div class="student-label">SAT Score</div>
              <h4 class="mb-0"><?= htmlspecialchars((string) ($student['sat_score'] ?? 'N/A')); ?></h4>
            </div>
          </div>
        </div>
        <div class="col-md-4">
          <div class="card student-metric-card h-100">
            <div class="card-body">
              <div class="student-label">Placement Result</div>
              <h4 class="mb-0"><?= htmlspecialchars((string) ($student['qualitative_text'] ?? 'N/A')); ?></h4>
            </div>
          </div>
        </div>
      </div>

      <div class="row g-3">
        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-body">
              <h6 class="mb-3">Placement Information</h6>
              <div class="table-responsive">
                <table class="table table-sm">
                  <tbody>
                    <tr>
                      <th class="w-50">Full Name</th>
                      <td><?= htmlspecialchars($studentName); ?></td>
                    </tr>
                    <tr>
                      <th>Examinee Number</th>
                      <td><?= htmlspecialchars((string) $student['examinee_number']); ?></td>
                    </tr>
                    <tr>
                      <th>Preferred Program</th>
                      <td><?= htmlspecialchars((string) ($student['preferred_program'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Overall Standard Score</th>
                      <td><?= htmlspecialchars((string) ($student['overall_standard_score'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Overall Stanine</th>
                      <td><?= htmlspecialchars((string) ($student['overall_stanine'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Overall Qualitative</th>
                      <td><?= htmlspecialchars((string) ($student['overall_qualitative_text'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Upload Batch</th>
                      <td><?= htmlspecialchars((string) ($student['upload_batch_id'] ?? 'N/A')); ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <div class="col-lg-6">
          <div class="card h-100">
            <div class="card-body">
              <h6 class="mb-3">Interview Information</h6>
              <div class="d-flex align-items-center justify-content-between mb-2">
                <span class="text-muted small">Interview Score Status</span>
                <span class="badge <?= $scoreBadgeClass; ?>">
                  <?= $hasScoredInterview ? 'Scored' : 'Pending'; ?>
                </span>
              </div>
              <div class="table-responsive">
                <table class="table table-sm">
                  <tbody>
                    <tr>
                      <th class="w-50">Campus</th>
                      <td><?= htmlspecialchars((string) ($student['campus_name'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Classification</th>
                      <td><?= htmlspecialchars((string) ($student['classification'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>ETG Class</th>
                      <td><?= htmlspecialchars((string) ($student['etg_class_name'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Mobile Number</th>
                      <td><?= htmlspecialchars((string) ($student['mobile_number'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>SHS Track</th>
                      <td><?= htmlspecialchars((string) ($student['shs_track_name'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Interview Date/Time</th>
                      <td><?= htmlspecialchars((string) ($student['interview_datetime'] ?? 'N/A')); ?></td>
                    </tr>
                    <tr>
                      <th>Final Interview Score</th>
                      <td class="fw-semibold"><?= htmlspecialchars($finalScoreDisplay); ?></td>
                    </tr>
                    <tr>
                      <th>1st Choice Program</th>
                      <td><?= htmlspecialchars(format_program_label($student['first_program_name'] ?? '', $student['first_program_major'] ?? '')); ?></td>
                    </tr>
                    <tr>
                      <th>2nd Choice Program</th>
                      <td><?= htmlspecialchars(format_program_label($student['second_program_name'] ?? '', $student['second_program_major'] ?? '')); ?></td>
                    </tr>
                    <tr>
                      <th>3rd Choice Program</th>
                      <td><?= htmlspecialchars(format_program_label($student['third_program_name'] ?? '', $student['third_program_major'] ?? '')); ?></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
  </body>
</html>
