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
$student = admin_student_management_fetch_student_record($conn, [
    'placement_result_id' => $placementResultId,
]);
$returnTo = admin_student_management_normalize_return_url(
    (string) ($_GET['return_to'] ?? ''),
    rtrim(BASE_URL, '/') . '/administrator/student_workspace.php?' . http_build_query([
        'placement_result_id' => $placementResultId,
    ])
);
$flash = admin_student_management_pop_transfer_flash();
$csrfToken = admin_student_management_get_transfer_csrf();
$programOptions = $student ? admin_student_management_fetch_program_options($conn, (int) ($student['current_program_id'] ?? 0)) : [];
$isRankLocked = $student ? program_ranking_is_interview_locked($conn, (int) ($student['interview_id'] ?? 0)) : false;
$hasPendingTransfer = ((int) ($student['pending_transfer_count'] ?? 0) > 0);
$canTransfer = $student
    && (int) ($student['interview_id'] ?? 0) > 0
    && !$isRankLocked;
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffActive = (bool) ($globalSatCutoffState['enabled'] ?? false) && isset($globalSatCutoffState['value']);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) ($globalSatCutoffState['value']) : null;
$flashType = is_array($flash) ? (string) ($flash['type'] ?? '') : '';
$flashMessage = is_array($flash) ? trim((string) ($flash['message'] ?? '')) : '';
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
    <title>Transfer Student - Administrator</title>
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
      .ast-summary {
        border: 1px solid #e4e9f2;
        border-radius: 1rem;
        background: linear-gradient(135deg, #f8fbff 0%, #ffffff 60%, #fff7eb 100%);
      }

      .ast-program-card {
        border: 1px solid #e4e9f2;
        border-radius: 0.95rem;
        height: 100%;
        transition: border-color 0.2s ease, box-shadow 0.2s ease, transform 0.2s ease;
        cursor: pointer;
      }

      .ast-program-card:hover {
        transform: translateY(-2px);
        border-color: #b8c7de;
        box-shadow: 0 12px 24px rgba(47, 63, 89, 0.08);
      }

      .ast-program-card.is-disabled {
        cursor: not-allowed;
        opacity: 0.6;
        box-shadow: none;
        transform: none;
      }

      .ast-program-card.is-selected {
        border-color: #ff9800;
        box-shadow: 0 0 0 0.18rem rgba(255, 152, 0, 0.18);
      }

      .ast-program-card__campus {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.06em;
        color: #7b8798;
        font-weight: 700;
      }

      .ast-program-card__name {
        margin-top: 0.3rem;
        font-weight: 700;
        color: #2f3f59;
      }

      .ast-program-card__meta {
        margin-top: 0.55rem;
        font-size: 0.83rem;
        color: #607188;
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
                    <span class="text-muted fw-light">Administrator /</span> Transfer Student
                  </h4>
                  <p class="text-muted mb-0">
                    Direct administrator transfer is applied immediately. Existing pending requests are bypassed, and if the destination has no assigned chair the record stays temporarily unassigned.
                  </p>
                </div>
                <a href="<?= htmlspecialchars($returnTo); ?>" class="btn btn-outline-secondary btn-sm">
                  <i class="bx bx-arrow-back me-1"></i>Back to Workspace
                </a>
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
                <div class="card ast-summary mb-4">
                  <div class="card-body">
                    <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-start gap-3">
                      <div>
                        <h4 class="mb-1"><?= htmlspecialchars((string) ($student['full_name'] ?? 'Unknown Student')); ?></h4>
                        <div class="text-muted">
                          Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? 'N/A')); ?>
                        </div>
                        <div class="mt-2 small text-muted">
                          Current Program: <strong><?= htmlspecialchars((string) ($student['current_program_label'] ?? 'N/A')); ?></strong>
                        </div>
                      </div>
                      <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <span class="badge <?= htmlspecialchars((string) ($student['rank_badge_class'] ?? 'bg-label-secondary')); ?>">
                          <?= htmlspecialchars((string) ($student['rank_display'] ?? 'N/A')); ?>
                        </span>
                        <?php if ($isRankLocked): ?>
                          <span class="badge bg-label-danger">Rank Locked</span>
                        <?php endif; ?>
                        <?php if ($hasPendingTransfer): ?>
                          <span class="badge bg-label-info">Pending Requests Will Be Bypassed</span>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>
                </div>

                <?php if ($globalSatCutoffActive && $globalSatCutoffValue !== null): ?>
                  <div class="alert alert-info py-2 mb-3">
                    Global SAT cutoff override is active at <?= number_format($globalSatCutoffValue); ?>.
                  </div>
                <?php endif; ?>

                <?php if (!$canTransfer): ?>
                  <div class="alert alert-warning mb-4">
                    <?php if ((int) ($student['interview_id'] ?? 0) <= 0): ?>
                      Transfer is unavailable because this student does not have an active interview record.
                    <?php elseif ($isRankLocked): ?>
                      Transfer is unavailable because the student rank is already locked.
                    <?php else: ?>
                      Transfer is currently unavailable for this record.
                    <?php endif; ?>
                  </div>
                <?php endif; ?>

                <form method="post" action="process_transfer_student.php" id="adminDirectTransferForm">
                  <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES); ?>" />
                  <input type="hidden" name="placement_result_id" value="<?= (int) ($student['placement_result_id'] ?? 0); ?>" />
                  <input type="hidden" name="interview_id" value="<?= (int) ($student['interview_id'] ?? 0); ?>" />
                  <input type="hidden" name="to_program_id" id="adminTransferProgramId" value="" />
                  <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo, ENT_QUOTES); ?>" />

                  <div class="card mb-4">
                    <div class="card-header">
                      <div class="row g-2 align-items-end">
                        <div class="col-lg-8">
                          <label class="form-label mb-1" for="adminTransferProgramSearch">Search Destination Program</label>
                          <input type="search" id="adminTransferProgramSearch" class="form-control" placeholder="Search campus, college, program, or program chair" />
                        </div>
                        <div class="col-lg-4">
                          <div class="small text-muted">
                            All active programs can be selected for direct transfer, even if no program chair is assigned yet.
                          </div>
                        </div>
                      </div>
                    </div>
                    <div class="card-body">
                      <div class="row g-3" id="adminTransferProgramGrid">
                        <?php foreach ($programOptions as $programOption): ?>
                          <?php
                            $targetProgramId = (int) ($programOption['program_id'] ?? 0);
                            $targetProgramLabel = (string) ($programOption['program_label'] ?? 'Unknown Program');
                            $ownerName = trim((string) ($programOption['owner_fullname'] ?? ''));
                            $programSelectable = $canTransfer;
                          ?>
                          <div
                            class="col-md-6 col-xl-4 admin-transfer-program-item"
                            data-search="<?= htmlspecialchars(strtolower(implode(' ', [
                                (string) ($programOption['campus_name'] ?? ''),
                                (string) ($programOption['college_name'] ?? ''),
                                $targetProgramLabel,
                                $ownerName,
                            ])), ENT_QUOTES); ?>"
                          >
                            <div
                              class="ast-program-card card<?= $programSelectable ? '' : ' is-disabled'; ?>"
                              data-program-id="<?= $targetProgramId; ?>"
                              data-program-selectable="<?= $programSelectable ? '1' : '0'; ?>"
                              tabindex="<?= $programSelectable ? '0' : '-1'; ?>"
                            >
                              <div class="card-body">
                                <div class="ast-program-card__campus"><?= htmlspecialchars((string) ($programOption['campus_name'] ?? 'No Campus')); ?></div>
                                <div class="ast-program-card__name"><?= htmlspecialchars($targetProgramLabel); ?></div>
                                <div class="ast-program-card__meta">
                                  <div>College: <?= htmlspecialchars((string) ($programOption['college_name'] ?? 'N/A')); ?></div>
                                  <div>Cutoff: <?= $programOption['cutoff_score'] !== null ? htmlspecialchars(number_format((int) $programOption['cutoff_score'])) : 'Not Set'; ?></div>
                                  <div>Capacity: <?= $programOption['absorptive_capacity'] !== null ? htmlspecialchars(number_format((int) $programOption['absorptive_capacity'])) : 'Not Set'; ?></div>
                                  <div>Program Chair: <?= htmlspecialchars($ownerName !== '' ? $ownerName : 'Unassigned after transfer until an active chair is set'); ?></div>
                                </div>
                              </div>
                            </div>
                          </div>
                        <?php endforeach; ?>
                      </div>
                    </div>
                  </div>

                  <div class="card">
                    <div class="card-body">
                      <label class="form-label" for="adminTransferRemarks">Transfer Remarks</label>
                      <textarea
                        name="remarks"
                        id="adminTransferRemarks"
                        class="form-control"
                        rows="4"
                        placeholder="Explain why the administrator is moving this student."
                        <?= $canTransfer ? '' : 'disabled'; ?>
                      ></textarea>

                      <div class="d-flex justify-content-end gap-2 mt-4">
                        <a href="<?= htmlspecialchars($returnTo); ?>" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-warning" id="adminTransferSubmitBtn" disabled>
                          Transfer Student
                        </button>
                      </div>
                    </div>
                  </div>
                </form>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
      (function () {
        const transferForm = document.getElementById('adminDirectTransferForm');
        const searchInput = document.getElementById('adminTransferProgramSearch');
        const programItems = Array.from(document.querySelectorAll('.admin-transfer-program-item'));
        const submitBtn = document.getElementById('adminTransferSubmitBtn');
        const hiddenProgramInput = document.getElementById('adminTransferProgramId');
        const canTransfer = <?= $canTransfer ? 'true' : 'false'; ?>;
        const flashType = <?= json_encode($flashType, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        const flashMessage = <?= json_encode($flashMessage, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        let selectedCard = null;

        if (flashMessage && typeof Swal !== 'undefined') {
          Swal.fire({
            icon: flashType === 'success' ? 'success' : 'error',
            title: flashType === 'success' ? 'Transfer Complete' : 'Transfer Failed',
            text: flashMessage,
            confirmButtonColor: '#696cff'
          });
        }

        function updateSubmitState() {
          if (!submitBtn) return;
          submitBtn.disabled = !canTransfer || !hiddenProgramInput || !hiddenProgramInput.value;
        }

        function selectProgram(card) {
          if (!card || card.getAttribute('data-program-selectable') !== '1') {
            return;
          }

          if (selectedCard) {
            selectedCard.classList.remove('is-selected');
          }

          selectedCard = card;
          selectedCard.classList.add('is-selected');
          if (hiddenProgramInput) {
            hiddenProgramInput.value = String(card.getAttribute('data-program-id') || '');
          }
          updateSubmitState();
        }

        programItems.forEach((item) => {
          const card = item.querySelector('.ast-program-card');
          if (!card) return;

          card.addEventListener('click', () => selectProgram(card));
          card.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' || event.key === ' ') {
              event.preventDefault();
              selectProgram(card);
            }
          });
        });

        if (searchInput) {
          searchInput.addEventListener('input', () => {
            const needle = String(searchInput.value || '').trim().toLowerCase();
            programItems.forEach((item) => {
              const haystack = String(item.getAttribute('data-search') || '');
              item.classList.toggle('d-none', needle !== '' && !haystack.includes(needle));
            });
          });
        }

        if (transferForm) {
          transferForm.addEventListener('submit', (event) => {
            if (!canTransfer || transferForm.dataset.confirmed === '1') {
              return;
            }

            event.preventDefault();

            const selectedProgramName = selectedCard
              ? String((selectedCard.querySelector('.ast-program-card__name') || {}).textContent || '').trim()
              : 'the selected program';

            if (typeof Swal === 'undefined') {
              const confirmed = window.confirm(`Transfer this student to ${selectedProgramName}? This administrator transfer is applied immediately.`);
              if (confirmed) {
                transferForm.dataset.confirmed = '1';
                transferForm.submit();
              }
              return;
            }

            Swal.fire({
              icon: 'warning',
              title: 'Confirm Transfer',
              text: `Transfer this student to ${selectedProgramName}? This administrator transfer is applied immediately.`,
              showCancelButton: true,
              confirmButtonText: 'Yes, transfer student',
              cancelButtonText: 'Cancel',
              confirmButtonColor: '#f59e0b'
            }).then((result) => {
              if (!result.isConfirmed) {
                return;
              }

              transferForm.dataset.confirmed = '1';
              transferForm.submit();
            });
          });
        }

        updateSubmitState();
      })();
    </script>
  </body>
</html>
