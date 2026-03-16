<?php
require_once '../config/db.php';
require_once '../config/session_security.php';
require_once '../config/admin_student_management.php';

secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

$placementResultId = max(0, (int) ($_GET['placement_result_id'] ?? 0));
$examineeNumber = trim((string) ($_GET['examinee_number'] ?? ''));
$lookupQuery = $examineeNumber !== '' ? $examineeNumber : ($placementResultId > 0 ? (string) $placementResultId : '');
$hasLookupQuery = ($lookupQuery !== '');
$student = admin_student_management_fetch_student_record($conn, [
    'placement_result_id' => $placementResultId,
    'examinee_number' => $examineeNumber,
]);
$transferFlash = admin_student_management_pop_transfer_flash();
$workspaceUrl = admin_student_management_normalize_return_url(
    rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
        'placement_result_id' => (int) ($student['placement_result_id'] ?? $placementResultId),
    ]),
    rtrim(BASE_URL, '/') . '/administrator/student_workspace.php'
);
$manageUrl = '';
if ((int) ($student['placement_result_id'] ?? 0) > 0) {
    $manageUrl = 'manage_student.php?' . http_build_query([
        'placement_result_id' => (int) ($student['placement_result_id'] ?? 0),
        'return_to' => $workspaceUrl,
    ]);
}
$ratingUrl = '';
if ((int) ($student['interview_id'] ?? 0) > 0) {
    $ratingUrl = rtrim(BASE_URL, '/') . '/administrator/interview_scores.php?' . http_build_query([
        'interview_id' => (int) ($student['interview_id'] ?? 0),
        'return_to' => $workspaceUrl,
    ]);
}

$transferUrl = '';
if ((int) ($student['placement_result_id'] ?? 0) > 0) {
    $transferUrl = 'transfer_student.php?' . http_build_query([
        'placement_result_id' => (int) ($student['placement_result_id'] ?? 0),
        'return_to' => $workspaceUrl,
    ]);
}

$adminActionCsrf = admin_student_management_get_transfer_csrf();
$currentProgramLabel = trim((string) ($student['current_program_label'] ?? ''));
$currentProgramLabel = $currentProgramLabel !== '' ? $currentProgramLabel : 'No active program assignment';
$interviewStatusLabel = ((int) ($student['interview_id'] ?? 0) > 0)
    ? (((string) ($student['interview_status'] ?? '') === 'active') ? 'Active Interview Record' : 'Interview Record Found')
    : 'No Interview Record';
$ratingButtonDisabled = ((int) ($student['interview_id'] ?? 0) <= 0);
$transferButtonDisabled = ((int) ($student['interview_id'] ?? 0) <= 0);
$currentInterviewId = (int) ($student['interview_id'] ?? 0);
$currentProgramId = (int) ($student['current_program_id'] ?? 0);
$studentRankLocked = $student ? program_ranking_is_interview_locked($conn, $currentInterviewId) : false;
$studentSubmittedPreRegistration = $student
    ? (student_preregistration_has_submitted_interview($conn, $currentInterviewId) === true)
    : false;
$isCurrentProgramScc = !empty($student['is_current_program_endorsement']);
$hasCurrentProgramSccOverride = !empty($student['current_program_endorsement_override_cutoff']);
$canManageScc = $student
    && $currentInterviewId > 0
    && $currentProgramId > 0
    && !$studentRankLocked
    && !$studentSubmittedPreRegistration;
$sccActionDisabledReason = '';
if ($student) {
    if ($currentInterviewId <= 0) {
        $sccActionDisabledReason = 'SCC is unavailable because the student does not have an active interview record.';
    } elseif ($currentProgramId <= 0) {
        $sccActionDisabledReason = 'SCC is unavailable because the current program assignment is missing.';
    } elseif ($studentRankLocked) {
        $sccActionDisabledReason = 'SCC is unavailable because the student rank is already locked.';
    } elseif ($studentSubmittedPreRegistration) {
        $sccActionDisabledReason = 'SCC is unavailable because the student already submitted pre-registration.';
    } elseif (!$isCurrentProgramScc && $student['final_score'] === null) {
        $sccActionDisabledReason = 'SCC can only be added after the final interview score is available.';
    } elseif (
        !$isCurrentProgramScc &&
        strtoupper(trim((string) ($student['classification'] ?? 'REGULAR'))) !== 'REGULAR'
    ) {
        $sccActionDisabledReason = 'Only Regular students can be added to SCC.';
    }
}
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
    <title>Student Workspace - Administrator</title>
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
      .aws-hero {
        border: 1px solid #e4e9f2;
        border-radius: 1rem;
        background: linear-gradient(135deg, #fffaf0 0%, #ffffff 55%, #eef4ff 100%);
      }

      .aws-kicker {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #8a6d3b;
      }

      .aws-stat {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.95rem 1rem;
        background: #fff;
      }

      .aws-stat-label {
        font-size: 0.73rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .aws-stat-value {
        margin-top: 0.28rem;
        font-size: 1.18rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .aws-detail-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.95rem;
      }

      .aws-detail-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.85rem 1rem;
      }

      .aws-detail-label {
        display: block;
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #7b8798;
      }

      .aws-detail-value {
        display: block;
        margin-top: 0.2rem;
        color: #2f3f59;
        font-weight: 600;
        line-height: 1.4;
      }

      .aws-action-stack {
        display: flex;
        flex-wrap: wrap;
        gap: 0.75rem;
      }

      .aws-search-card {
        border: 1px solid #e4e9f2;
        border-radius: 0.95rem;
        background: linear-gradient(135deg, #f7fbff 0%, #ffffff 62%, #fff8ef 100%);
      }

      @media (max-width: 991.98px) {
        .aws-detail-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 575.98px) {
        .aws-detail-grid {
          grid-template-columns: 1fr;
        }
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
                    <span class="text-muted fw-light">Administrator /</span> Student Workspace
                  </h4>
                  <p class="text-muted mb-0">
                    Review the full student context, then manage details, transfer, and score entirely inside the administrator module.
                  </p>
                </div>
                <a href="index.php" class="btn btn-outline-secondary btn-sm">
                  <i class="bx bx-arrow-back me-1"></i>Back
                </a>
              </div>

              <div class="card aws-search-card mb-4">
                <div class="card-body">
                  <form method="get" action="student_workspace.php" class="row g-2 align-items-end">
                    <div class="col-lg-8">
                      <label class="form-label mb-1" for="studentWorkspaceLookup">Search Examinee Number</label>
                      <input
                        type="search"
                        class="form-control"
                        id="studentWorkspaceLookup"
                        name="examinee_number"
                        value="<?= htmlspecialchars($lookupQuery); ?>"
                        placeholder="Enter examinee number"
                      />
                      <small class="text-muted">Use this page to pull the student record, then manage details, transfer, or give scores.</small>
                    </div>
                    <div class="col-lg-4 d-flex gap-2">
                      <button type="submit" class="btn btn-primary">
                        <i class="bx bx-search me-1"></i>Search Student
                      </button>
                      <a href="student_workspace.php" class="btn btn-outline-secondary">Clear</a>
                    </div>
                  </form>
                </div>
              </div>

              <?php if (is_array($transferFlash) && !empty($transferFlash['message'])): ?>
                <?php $transferFlashType = ((string) ($transferFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($transferFlashType); ?> py-2 mb-3">
                  <?= htmlspecialchars((string) $transferFlash['message']); ?>
                </div>
              <?php endif; ?>

              <?php if (!$student): ?>
                <?php if ($hasLookupQuery): ?>
                  <div class="alert alert-danger">
                    Student record not found for `<?= htmlspecialchars($lookupQuery); ?>`. Check the examinee number and try again.
                  </div>
                <?php else: ?>
                  <div class="alert alert-info">
                    Search an examinee number above to open the student workspace.
                  </div>
                <?php endif; ?>
              <?php else: ?>
                <div class="card aws-hero mb-4">
                  <div class="card-body">
                    <div class="aws-kicker mb-2">Student Snapshot</div>
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                      <div>
                        <h4 class="mb-1"><?= htmlspecialchars((string) ($student['full_name'] ?? 'Unknown Student')); ?></h4>
                        <div class="text-muted mb-2">
                          Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? 'N/A')); ?>
                        </div>
                        <div class="aws-action-stack">
                          <?php if ($manageUrl !== ''): ?>
                            <a href="<?= htmlspecialchars($manageUrl); ?>" class="btn btn-outline-primary">
                              <i class="bx bx-slider-alt me-1"></i>Manage Details
                            </a>
                          <?php endif; ?>

                          <?php if (!$transferButtonDisabled): ?>
                            <a href="<?= htmlspecialchars($transferUrl); ?>" class="btn btn-warning">
                              <i class="bx bx-transfer-alt me-1"></i>Transfer Student
                            </a>
                          <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary" disabled>
                              <i class="bx bx-transfer-alt me-1"></i>Transfer Student
                            </button>
                          <?php endif; ?>

                          <?php if (!$ratingButtonDisabled): ?>
                            <a href="<?= htmlspecialchars($ratingUrl); ?>" class="btn btn-primary">
                              <i class="bx bx-edit-alt me-1"></i>Give Scores
                            </a>
                          <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary" disabled>
                              <i class="bx bx-edit-alt me-1"></i>Give Scores
                            </button>
                          <?php endif; ?>

                          <?php if ($canManageScc): ?>
                            <form method="post" action="process_scc_student.php" class="d-inline">
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminActionCsrf, ENT_QUOTES); ?>" />
                              <input type="hidden" name="placement_result_id" value="<?= (int) ($student['placement_result_id'] ?? 0); ?>" />
                              <input type="hidden" name="interview_id" value="<?= $currentInterviewId; ?>" />
                              <input type="hidden" name="scc_action" value="<?= $isCurrentProgramScc ? 'REMOVE' : 'ADD'; ?>" />
                              <input type="hidden" name="return_to" value="<?= htmlspecialchars($workspaceUrl, ENT_QUOTES); ?>" />
                              <button type="submit" class="btn <?= $isCurrentProgramScc ? 'btn-outline-success' : 'btn-outline-warning'; ?>">
                                <i class="bx <?= $isCurrentProgramScc ? 'bx-minus-circle' : 'bx-bookmark-plus'; ?> me-1"></i><?= $isCurrentProgramScc ? 'Remove SCC' : 'Add SCC'; ?>
                              </button>
                            </form>
                          <?php else: ?>
                            <button type="button" class="btn btn-outline-secondary" disabled title="<?= htmlspecialchars($sccActionDisabledReason); ?>">
                              <i class="bx bx-bookmark-plus me-1"></i><?= $isCurrentProgramScc ? 'Remove SCC' : 'Add SCC'; ?>
                            </button>
                          <?php endif; ?>
                        </div>
                      </div>

                      <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <span class="badge bg-label-primary"><?= htmlspecialchars($interviewStatusLabel); ?></span>
                        <span class="badge <?= htmlspecialchars((string) ($student['rank_badge_class'] ?? 'bg-label-secondary')); ?>">
                          Rank <?= htmlspecialchars((string) ($student['rank_display'] ?? 'N/A')); ?>
                        </span>
                        <?php if ((int) ($student['pending_transfer_count'] ?? 0) > 0): ?>
                          <span class="badge bg-label-danger">Pending Transfer</span>
                        <?php endif; ?>
                        <?php if ($isCurrentProgramScc): ?>
                          <span class="badge bg-label-success">SCC Active</span>
                        <?php endif; ?>
                        <?php if ($hasCurrentProgramSccOverride): ?>
                          <span class="badge bg-label-warning">SCC Cutoff Override</span>
                        <?php endif; ?>
                        <?php if ((string) ($student['credential_status'] ?? '') === 'active'): ?>
                          <span class="badge bg-label-success">Student Login Active</span>
                        <?php elseif ((int) ($student['credential_id'] ?? 0) > 0): ?>
                          <span class="badge bg-label-warning">Student Login Inactive</span>
                        <?php else: ?>
                          <span class="badge bg-label-secondary">No Student Login</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mb-4">
                  <div class="col-md-3 col-6">
                    <div class="aws-stat">
                      <div class="aws-stat-label">Current Program</div>
                      <div class="aws-stat-value"><?= htmlspecialchars($currentProgramLabel); ?></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="aws-stat">
                      <div class="aws-stat-label">SAT</div>
                      <div class="aws-stat-value"><?= htmlspecialchars(number_format((int) ($student['sat_score'] ?? 0))); ?></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="aws-stat">
                      <div class="aws-stat-label">Final Score</div>
                      <div class="aws-stat-value">
                        <?= $student['final_score'] !== null ? htmlspecialchars(number_format((float) $student['final_score'], 2) . '%') : 'N/A'; ?>
                      </div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="aws-stat">
                      <div class="aws-stat-label">Profile Completion</div>
                      <div class="aws-stat-value">
                        <?= $student['profile_completion_percent'] !== null ? htmlspecialchars(number_format((float) $student['profile_completion_percent'], 0) . '%') : 'No Profile'; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="alert alert-info py-2 mb-4">
                  Administrator SCC follows the live shared-ranking rules for active, unlocked Regular interviews. When an administrator adds SCC, the entry can stay visible even if the student is below the normal cutoff.
                </div>

                <div class="card aws-detail-card mb-4">
                  <div class="card-header">
                    <h5 class="mb-0">Placement and Interview Details</h5>
                  </div>
                  <div class="card-body">
                    <div class="aws-detail-grid">
                      <div><span class="aws-detail-label">Preferred Program</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['preferred_program'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Current Campus</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['campus_name'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Classification</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['classification'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Interview Date/Time</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['interview_datetime'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">SHS Track</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['shs_track_name'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">ETG Class</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['etg_class_name'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">1st Choice</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['first_choice_label'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">2nd Choice</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['second_choice_label'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">3rd Choice</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['third_choice_label'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Overall Standard Score</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['overall_standard_score'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">ESM Standard Score</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['esm_competency_standard_score'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Placement Result</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['qualitative_text'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Current SCC Status</span><span class="aws-detail-value"><?= $isCurrentProgramScc ? htmlspecialchars($hasCurrentProgramSccOverride ? 'SCC (Admin Cutoff Override)' : 'SCC Active') : 'Not Tagged'; ?></span></div>
                    </div>
                  </div>
                </div>

                <div class="card aws-detail-card">
                  <div class="card-header">
                    <h5 class="mb-0">Ownership and Student Portal Status</h5>
                  </div>
                  <div class="card-body">
                    <div class="aws-detail-grid">
                      <div><span class="aws-detail-label">Program Chair Owner</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['owner_fullname'] ?? 'Unassigned')); ?></span></div>
                      <div><span class="aws-detail-label">Owner Email</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['owner_email'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Mobile Number</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['mobile_number'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Credential Status</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['credential_status'] ?? 'none')); ?></span></div>
                      <div><span class="aws-detail-label">Active Email</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['active_email'] ?? 'N/A')); ?></span></div>
                      <div><span class="aws-detail-label">Rank Validation</span><span class="aws-detail-value"><?= htmlspecialchars((string) ($student['rank_note'] ?? 'N/A')); ?></span></div>
                    </div>
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
  </body>
</html>
