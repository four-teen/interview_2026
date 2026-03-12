<?php
require_once '../config/db.php';
require_once '../config/session_security.php';
require_once '../config/admin_student_management.php';
require_once '../config/admin_student_workflows.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

function admin_manage_student_datetime_value($value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    $timestamp = strtotime($raw);
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d\TH:i', $timestamp);
}

$requestedPlacementResultId = max(0, (int) ($_REQUEST['placement_result_id'] ?? 0));
$returnTo = admin_student_management_normalize_return_url(
    (string) ($_REQUEST['return_to'] ?? ''),
    rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
        'placement_result_id' => $requestedPlacementResultId,
    ])
);

$pageUrl = 'manage_student.php?' . http_build_query([
    'placement_result_id' => $requestedPlacementResultId,
    'return_to' => $returnTo,
]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_student_workflows_verify_details_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        admin_student_workflows_set_details_flash('danger', 'Invalid student-details security token.');
        header('Location: ' . $pageUrl);
        exit;
    }

    $result = admin_student_workflows_save_student_details($conn, $_POST);
    admin_student_workflows_set_details_flash(
        ($result['success'] ?? false) ? 'success' : 'danger',
        (string) ($result['message'] ?? 'Unable to save student details.')
    );

    $redirectPlacementResultId = max(0, (int) ($result['placement_result_id'] ?? $requestedPlacementResultId));
    $redirectUrl = 'manage_student.php?' . http_build_query([
        'placement_result_id' => $redirectPlacementResultId,
        'return_to' => $returnTo,
    ]);
    header('Location: ' . $redirectUrl);
    exit;
}

$student = $requestedPlacementResultId > 0
    ? admin_student_management_fetch_student_record($conn, [
        'placement_result_id' => $requestedPlacementResultId,
    ])
    : null;
$flash = admin_student_workflows_pop_details_flash();
$csrfToken = admin_student_workflows_get_details_csrf();
$programOptions = admin_student_management_fetch_program_options($conn);
$trackOptions = admin_student_workflows_fetch_track_options($conn);
$etgClassOptions = admin_student_workflows_fetch_etg_class_options($conn);

$workspaceUrl = $student
    ? admin_student_management_normalize_return_url(
        rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
            'placement_result_id' => (int) ($student['placement_result_id'] ?? 0),
        ]),
        rtrim(BASE_URL, '/') . '/administrator/student_workspace.php'
    )
    : $returnTo;
$scoreUrl = ($student && (int) ($student['interview_id'] ?? 0) > 0)
    ? ('interview_scores.php?' . http_build_query([
        'interview_id' => (int) ($student['interview_id'] ?? 0),
        'return_to' => $workspaceUrl,
    ]))
    : '';
$currentProgramId = (int) ($student['current_program_id'] ?? 0);
$formClassification = strtoupper(trim((string) ($student['classification'] ?? 'REGULAR')));
$formClassification = ($formClassification === 'ETG') ? 'ETG' : 'REGULAR';
$formInterviewDateTime = admin_manage_student_datetime_value((string) ($student['interview_datetime'] ?? ''));
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0" />
    <title>Manage Student - Administrator</title>
    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet" />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .ams-shell {
        border: 1px solid #e7edf6;
        border-radius: 1rem;
        background: linear-gradient(135deg, #f8fbff 0%, #ffffff 58%, #fff7ed 100%);
      }

      .ams-note {
        border: 1px dashed #fdba74;
        border-radius: 0.9rem;
        background: #fff7ed;
        color: #9a3412;
      }

      .ams-summary {
        border: 1px solid #e7edf6;
        border-radius: 0.9rem;
        background: #fff;
      }

      .ams-summary-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #7b8798;
      }

      .ams-summary-value {
        display: block;
        margin-top: 0.22rem;
        font-size: 1rem;
        font-weight: 700;
        color: #2f3f59;
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
              <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-4">
                <div>
                  <h4 class="fw-bold mb-1">
                    <span class="text-muted fw-light">Administrator /</span> Manage Student
                  </h4>
                  <p class="text-muted mb-0">
                    Edit or create the interview-side student record inside the administrator module only.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="<?= htmlspecialchars($workspaceUrl); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bx bx-arrow-back me-1"></i>Back to Workspace
                  </a>
                  <?php if ($scoreUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($scoreUrl); ?>" class="btn btn-primary btn-sm">
                      <i class="bx bx-edit-alt me-1"></i>Give Scores
                    </a>
                  <?php endif; ?>
                </div>
              </div>

              <div class="card ams-note mb-4">
                <div class="card-body py-3">
                  Administrator detail updates are isolated to this module. Global and program SAT cutoff checks are not applied in this workflow.
                </div>
              </div>

              <?php if (is_array($flash) && !empty($flash['message'])): ?>
                <?php $flashType = ((string) ($flash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($flashType); ?> py-2 mb-3">
                  <?= htmlspecialchars((string) $flash['message']); ?>
                </div>
              <?php endif; ?>

              <?php if (!$student): ?>
                <div class="alert alert-danger">
                  Student record not found.
                </div>
              <?php else: ?>
                <div class="card ams-shell mb-4">
                  <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                      <div>
                        <div class="text-uppercase text-muted small fw-semibold mb-1">Administrator Student Editor</div>
                        <h4 class="mb-1"><?= htmlspecialchars((string) ($student['full_name'] ?? 'Unknown Student')); ?></h4>
                        <div class="text-muted">
                          Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? 'N/A')); ?>
                        </div>
                      </div>
                      <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <span class="badge bg-label-primary">
                          <?= (int) ($student['interview_id'] ?? 0) > 0 ? 'Interview Record Found' : 'No Interview Record Yet'; ?>
                        </span>
                        <span class="badge <?= htmlspecialchars((string) ($student['rank_badge_class'] ?? 'bg-label-secondary')); ?>">
                          <?= htmlspecialchars((string) ($student['rank_display'] ?? 'N/A')); ?>
                        </span>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mb-4">
                  <div class="col-md-3 col-6">
                    <div class="ams-summary p-3">
                      <span class="ams-summary-label">Current Program</span>
                      <span class="ams-summary-value"><?= htmlspecialchars((string) ($student['current_program_label'] ?? 'No active program')); ?></span>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ams-summary p-3">
                      <span class="ams-summary-label">Classification</span>
                      <span class="ams-summary-value"><?= htmlspecialchars($formClassification); ?></span>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ams-summary p-3">
                      <span class="ams-summary-label">SAT</span>
                      <span class="ams-summary-value"><?= htmlspecialchars(number_format((int) ($student['sat_score'] ?? 0))); ?></span>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ams-summary p-3">
                      <span class="ams-summary-label">Final Score</span>
                      <span class="ams-summary-value">
                        <?= $student['final_score'] !== null ? htmlspecialchars(number_format((float) $student['final_score'], 2) . '%') : 'N/A'; ?>
                      </span>
                    </div>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header">
                    <h5 class="mb-0">Interview Details</h5>
                  </div>
                  <div class="card-body">
                    <form method="post" action="manage_student.php" id="adminManageStudentForm">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
                      <input type="hidden" name="placement_result_id" value="<?= (int) ($student['placement_result_id'] ?? 0); ?>" />
                      <input type="hidden" name="interview_id" value="<?= (int) ($student['interview_id'] ?? 0); ?>" />
                      <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES); ?>" />

                      <div class="row g-3">
                        <div class="col-md-4">
                          <label class="form-label">Classification</label>
                          <select name="classification" id="adminStudentClassification" class="form-select" required>
                            <option value="REGULAR"<?= $formClassification === 'REGULAR' ? ' selected' : ''; ?>>REGULAR</option>
                            <option value="ETG"<?= $formClassification === 'ETG' ? ' selected' : ''; ?>>ETG</option>
                          </select>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">ETG Classification</label>
                          <select name="etg_class_id" id="adminStudentEtgClass" class="form-select">
                            <option value="">Select ETG Class</option>
                            <?php foreach ($etgClassOptions as $option): ?>
                              <?php $etgClassId = (int) ($option['etg_class_id'] ?? 0); ?>
                              <option value="<?= $etgClassId; ?>"<?= (int) ($student['etg_class_id'] ?? 0) === $etgClassId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($option['class_name'] ?? '')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">Mobile Number</label>
                          <input
                            type="text"
                            name="mobile_number"
                            id="adminStudentMobile"
                            class="form-control"
                            value="<?= htmlspecialchars((string) ($student['mobile_number'] ?? '')); ?>"
                            maxlength="11"
                            inputmode="numeric"
                            required
                          />
                        </div>

                        <div class="col-md-4">
                          <label class="form-label">1st Choice</label>
                          <select name="first_choice" class="form-select" required>
                            <option value="">Select Program</option>
                            <?php foreach ($programOptions as $programOption): ?>
                              <?php $programId = (int) ($programOption['program_id'] ?? 0); ?>
                              <option value="<?= $programId; ?>"<?= $currentProgramId === $programId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($programOption['program_label'] ?? '')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">2nd Choice</label>
                          <select name="second_choice" class="form-select" required>
                            <option value="">Select Program</option>
                            <?php foreach ($programOptions as $programOption): ?>
                              <?php $programId = (int) ($programOption['program_id'] ?? 0); ?>
                              <option value="<?= $programId; ?>"<?= (int) ($student['second_choice'] ?? 0) === $programId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($programOption['program_label'] ?? '')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-4">
                          <label class="form-label">3rd Choice</label>
                          <select name="third_choice" class="form-select" required>
                            <option value="">Select Program</option>
                            <?php foreach ($programOptions as $programOption): ?>
                              <?php $programId = (int) ($programOption['program_id'] ?? 0); ?>
                              <option value="<?= $programId; ?>"<?= (int) ($student['third_choice'] ?? 0) === $programId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($programOption['program_label'] ?? '')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>

                        <div class="col-md-6">
                          <label class="form-label">SHS Track</label>
                          <select name="shs_track_id" class="form-select" required>
                            <option value="">Select SHS Track</option>
                            <?php foreach ($trackOptions as $trackOption): ?>
                              <?php $trackId = (int) ($trackOption['track_id'] ?? 0); ?>
                              <option value="<?= $trackId; ?>"<?= (int) ($student['shs_track_id'] ?? 0) === $trackId ? ' selected' : ''; ?>>
                                <?= htmlspecialchars((string) ($trackOption['track_name'] ?? '')); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                        </div>
                        <div class="col-md-6">
                          <label class="form-label">Interview Date/Time</label>
                          <input
                            type="datetime-local"
                            name="interview_datetime"
                            class="form-control"
                            value="<?= htmlspecialchars($formInterviewDateTime); ?>"
                          />
                        </div>
                      </div>

                      <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="<?= htmlspecialchars($workspaceUrl); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                          <?= (int) ($student['interview_id'] ?? 0) > 0 ? 'Save Details' : 'Create Interview Record'; ?>
                        </button>
                      </div>
                    </form>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      (function () {
        const classificationEl = document.getElementById('adminStudentClassification');
        const etgClassEl = document.getElementById('adminStudentEtgClass');
        const mobileEl = document.getElementById('adminStudentMobile');

        function syncEtgState() {
          if (!classificationEl || !etgClassEl) return;
          const isEtg = String(classificationEl.value || '').toUpperCase() === 'ETG';
          etgClassEl.required = isEtg;
        }

        if (classificationEl) {
          classificationEl.addEventListener('change', syncEtgState);
          syncEtgState();
        }

        if (mobileEl) {
          mobileEl.addEventListener('input', function () {
            this.value = String(this.value || '').replace(/\D/g, '').slice(0, 11);
          });
        }
      })();
    </script>
  </body>
</html>
