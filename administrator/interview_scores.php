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

$requestedInterviewId = max(0, (int) ($_REQUEST['interview_id'] ?? 0));
$fallbackReturnTo = rtrim(BASE_URL, '/') . '/administrator/index.php';
if ($requestedInterviewId > 0) {
    $fallbackPayload = admin_student_workflows_build_scoring_payload($conn, $requestedInterviewId);
    if ($fallbackPayload && !empty($fallbackPayload['context']['placement_result_id'])) {
        $fallbackReturnTo = rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
            'placement_result_id' => (int) ($fallbackPayload['context']['placement_result_id'] ?? 0),
        ]);
    }
}

$returnTo = admin_student_management_normalize_return_url(
    (string) ($_REQUEST['return_to'] ?? ''),
    $fallbackReturnTo
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_student_workflows_verify_scores_csrf((string) ($_POST['csrf_token'] ?? ''))) {
        header('Location: interview_scores.php?' . http_build_query([
            'interview_id' => $requestedInterviewId,
            'return_to' => $returnTo,
            'invalid_token' => 1,
        ]));
        exit;
    }

    $result = admin_student_workflows_save_interview_scores($conn, [
        'interview_id' => $requestedInterviewId,
        'actor_account_id' => (int) ($_SESSION['accountid'] ?? 0),
        'raw_score' => $_POST['raw_score'] ?? null,
    ]);

    $redirectParams = [
        'interview_id' => $requestedInterviewId,
        'return_to' => $returnTo,
    ];
    if ($result['success'] ?? false) {
        $redirectParams['saved'] = 1;
    } elseif (($result['error_code'] ?? '') === 'invalid_score') {
        $redirectParams['invalid_score'] = 1;
    } elseif (($result['error_code'] ?? '') === 'locked') {
        $redirectParams['locked'] = 1;
    } else {
        $redirectParams['error'] = 1;
    }

    header('Location: interview_scores.php?' . http_build_query($redirectParams));
    exit;
}

$payload = $requestedInterviewId > 0
    ? admin_student_workflows_build_scoring_payload($conn, $requestedInterviewId)
    : null;
$context = is_array($payload['context'] ?? null) ? $payload['context'] : null;
$components = is_array($payload['components'] ?? null) ? $payload['components'] : [];
$savedScores = is_array($payload['saved_scores'] ?? null) ? $payload['saved_scores'] : [];
$totalWeight = (float) ($payload['total_weight'] ?? 0);
$csrfToken = admin_student_workflows_get_scores_csrf();
$workspaceUrl = $context
    ? admin_student_management_normalize_return_url(
        rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
            'placement_result_id' => (int) ($context['placement_result_id'] ?? 0),
        ]),
        $returnTo
    )
    : $returnTo;
$manageUrl = $context
    ? ('manage_student.php?' . http_build_query([
        'placement_result_id' => (int) ($context['placement_result_id'] ?? 0),
        'return_to' => $workspaceUrl,
    ]))
    : '';
$lockMessage = trim((string) ($context['lock_message'] ?? ''));
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
    <title>Interview Scores - Administrator</title>
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
      .ais-banner {
        border: 1px solid #fed7aa;
        border-radius: 1rem;
        background: linear-gradient(135deg, #fff7ed 0%, #ffffff 58%, #eff6ff 100%);
      }

      .ais-stat {
        border: 1px solid #e5ebf4;
        border-radius: 0.9rem;
        background: #fff;
        padding: 1rem;
      }

      .ais-stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #7b8798;
      }

      .ais-stat-value {
        margin-top: 0.22rem;
        font-size: 1.12rem;
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
                    <span class="text-muted fw-light">Administrator /</span> Give Scores
                  </h4>
                  <p class="text-muted mb-0">
                    Administrator-only scoring page. Global cutoff is ignored here and no program-chair route is used.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <?php if ($manageUrl !== ''): ?>
                    <a href="<?= htmlspecialchars($manageUrl); ?>" class="btn btn-outline-secondary btn-sm">
                      <i class="bx bx-slider-alt me-1"></i>Manage Details
                    </a>
                  <?php endif; ?>
                  <a href="<?= htmlspecialchars($workspaceUrl); ?>" class="btn btn-outline-secondary btn-sm">
                    <i class="bx bx-arrow-back me-1"></i>Back to Workspace
                  </a>
                </div>
              </div>

              <?php if (isset($_GET['saved'])): ?>
                <div class="alert alert-success py-2 mb-3">Scores saved successfully.</div>
              <?php elseif (isset($_GET['invalid_score'])): ?>
                <div class="alert alert-danger py-2 mb-3">One or more scores exceeded the allowed maximum.</div>
              <?php elseif (isset($_GET['locked'])): ?>
                <div class="alert alert-warning py-2 mb-3">
                  <?= htmlspecialchars($lockMessage !== '' ? $lockMessage : 'This ranking record is locked and cannot be updated.'); ?>
                </div>
              <?php elseif (isset($_GET['invalid_token'])): ?>
                <div class="alert alert-danger py-2 mb-3">Invalid score security token.</div>
              <?php elseif (isset($_GET['error'])): ?>
                <div class="alert alert-danger py-2 mb-3">Unable to save interview scores.</div>
              <?php endif; ?>

              <?php if (!$context): ?>
                <div class="alert alert-danger">Interview scoring record not found.</div>
              <?php else: ?>
                <div class="card ais-banner mb-4">
                  <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                      <div>
                        <div class="text-uppercase text-muted small fw-semibold mb-1">Scoring Context</div>
                        <h4 class="mb-1"><?= htmlspecialchars((string) ($context['student_name'] ?? 'Unknown Student')); ?></h4>
                        <div class="text-muted">
                          Examinee #: <?= htmlspecialchars((string) ($context['examinee_number'] ?? 'N/A')); ?>
                        </div>
                      </div>
                      <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <span class="badge bg-label-primary"><?= htmlspecialchars((string) ($context['classification'] ?? 'REGULAR')); ?></span>
                        <span class="badge bg-label-info"><?= htmlspecialchars((string) ($context['placement_score_source_label'] ?? 'Overall Standard Score')); ?> Auto Basis</span>
                        <?php if ($lockMessage !== ''): ?>
                          <span class="badge bg-label-danger">Rank Locked</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-3 mb-4">
                  <div class="col-md-3 col-6">
                    <div class="ais-stat">
                      <div class="ais-stat-label">Program</div>
                      <div class="ais-stat-value"><?= htmlspecialchars((string) ($context['program_label'] ?? 'N/A')); ?></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ais-stat">
                      <div class="ais-stat-label">SAT</div>
                      <div class="ais-stat-value"><?= htmlspecialchars(number_format((int) ($context['sat_score'] ?? 0))); ?></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ais-stat">
                      <div class="ais-stat-label">Auto Placement Score</div>
                      <div class="ais-stat-value"><?= htmlspecialchars(number_format((float) ($context['placement_score_from_placement'] ?? 0), 2)); ?></div>
                    </div>
                  </div>
                  <div class="col-md-3 col-6">
                    <div class="ais-stat">
                      <div class="ais-stat-label">Final Score</div>
                      <div class="ais-stat-value" id="finalScorePreview">
                        <?= $context['final_score'] !== null ? htmlspecialchars(number_format((float) $context['final_score'], 2) . '%') : '0.00%'; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="card">
                  <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <h5 class="mb-0">Score Components</h5>
                    <span class="badge bg-label-secondary">Total Weight: <span id="totalWeightPreview"><?= htmlspecialchars(number_format($totalWeight, 2)); ?>%</span></span>
                  </div>
                  <div class="card-body">
                    <?php if ($lockMessage !== ''): ?>
                      <div class="alert alert-warning py-2 mb-3">
                        <?= htmlspecialchars($lockMessage); ?>
                      </div>
                    <?php endif; ?>

                    <form method="post" action="interview_scores.php?<?= htmlspecialchars(http_build_query([
                        'interview_id' => $requestedInterviewId,
                        'return_to' => $returnTo,
                    ])); ?>" id="adminInterviewScoreForm">
                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
                      <div class="table-responsive">
                        <table class="table table-bordered align-middle" id="adminInterviewScoreTable">
                          <thead>
                            <tr>
                              <th>Component</th>
                              <th class="text-end">Max</th>
                              <th class="text-end">Weight (%)</th>
                              <th class="text-end">Raw Score</th>
                              <th class="text-end">Weighted</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($components as $component): ?>
                              <?php
                                $componentId = (int) ($component['component_id'] ?? 0);
                                $maxScore = (float) ($component['max_score'] ?? 0);
                                $weight = (float) ($component['effective_weight_percent'] ?? $component['weight_percent'] ?? 0);
                                $isAuto = ((int) ($component['is_auto_computed'] ?? 0) === 1)
                                    || admin_student_workflows_is_sat_component((string) ($component['component_name'] ?? ''));
                                $savedScore = $savedScores[$componentId] ?? null;
                                $rawValue = $isAuto
                                    ? (float) ($context['placement_score_from_placement'] ?? 0)
                                    : (float) ($savedScore['raw_score'] ?? 0);
                                $weightedValue = $maxScore > 0 ? (($rawValue / $maxScore) * $weight) : 0;
                              ?>
                              <tr
                                data-max="<?= htmlspecialchars(number_format($maxScore, 2, '.', '')); ?>"
                                data-weight="<?= htmlspecialchars(number_format($weight, 2, '.', '')); ?>"
                              >
                                <td><?= htmlspecialchars((string) ($component['component_name'] ?? 'Component')); ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($maxScore, 2)); ?></td>
                                <td class="text-end"><?= htmlspecialchars(number_format($weight, 2)); ?></td>
                                <td class="text-end">
                                  <input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    max="<?= htmlspecialchars(number_format($maxScore, 2, '.', '')); ?>"
                                    name="raw_score[<?= $componentId; ?>]"
                                    class="form-control text-end raw-input"
                                    value="<?= htmlspecialchars(number_format($rawValue, 2, '.', '')); ?>"
                                    <?= $isAuto ? 'readonly' : ''; ?>
                                  />
                                </td>
                                <td class="text-end weighted-result"><?= htmlspecialchars(number_format($weightedValue, 2)); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>

                      <?php if (!empty($context['is_etg_student'])): ?>
                        <div class="alert alert-info py-2 mt-3 mb-0">
                          ETG affirmative action adds 15.00 points to the computed final score.
                        </div>
                      <?php endif; ?>

                      <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="<?= htmlspecialchars($workspaceUrl); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"<?= $lockMessage !== '' ? ' disabled' : ''; ?>>
                          Save Scores
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
        const form = document.getElementById('adminInterviewScoreForm');
        if (!form) return;

        const finalScorePreview = document.getElementById('finalScorePreview');
        const totalWeightPreview = document.getElementById('totalWeightPreview');

        function toNumber(value) {
          const parsed = Number(value);
          return Number.isFinite(parsed) ? parsed : 0;
        }

        function computeScores() {
          let totalWeight = 0;
          let finalScore = 0;

          document.querySelectorAll('#adminInterviewScoreTable tbody tr[data-max]').forEach((row) => {
            const maxScore = toNumber(row.getAttribute('data-max'));
            const weight = toNumber(row.getAttribute('data-weight'));
            const input = row.querySelector('.raw-input');
            const output = row.querySelector('.weighted-result');
            let rawScore = input ? toNumber(input.value) : 0;

            totalWeight += weight;

            if (rawScore < 0) rawScore = 0;
            if (maxScore > 0 && rawScore > maxScore) rawScore = maxScore;
            if (input && !input.hasAttribute('readonly')) {
              input.value = rawScore.toFixed(2);
            }

            const weighted = maxScore > 0 ? ((rawScore / maxScore) * weight) : 0;
            finalScore += weighted;
            if (output) {
              output.textContent = weighted.toFixed(2);
            }
          });

          const isEtgStudent = <?= !empty($context['is_etg_student']) ? 'true' : 'false'; ?>;
          if (isEtgStudent) {
            finalScore += 15;
          }

          if (finalScorePreview) {
            finalScorePreview.textContent = finalScore.toFixed(2) + '%';
          }
          if (totalWeightPreview) {
            totalWeightPreview.textContent = totalWeight.toFixed(2) + '%';
          }
        }

        form.addEventListener('input', function (event) {
          if (!event.target.classList.contains('raw-input')) return;
          computeScores();
        });

        form.addEventListener('submit', function (event) {
          let invalid = false;
          document.querySelectorAll('#adminInterviewScoreTable tbody tr[data-max]').forEach((row) => {
            if (invalid) return;
            const input = row.querySelector('.raw-input');
            if (!input || input.hasAttribute('readonly')) return;
            const maxScore = toNumber(row.getAttribute('data-max'));
            const rawScore = toNumber(input.value);
            if (maxScore > 0 && rawScore > maxScore) {
              invalid = true;
            }
          });

          if (invalid) {
            event.preventDefault();
            window.alert('One or more scores exceeded the allowed maximum.');
          }
        });

        computeScores();
      })();
    </script>
  </body>
</html>
