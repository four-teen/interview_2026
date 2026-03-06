<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
require_once '../config/program_assignments.php';
require_once '../config/system_controls.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    (($_SESSION['role'] ?? '') !== 'progchair') ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$fallbackProgramId = (int) ($_SESSION['program_id'] ?? 0);

$assignedProgramIds = get_account_assigned_program_ids($conn, $accountId, $fallbackProgramId);
$allowedProgramIds = [];
foreach ($assignedProgramIds as $programId) {
    $programId = (int) $programId;
    if ($programId > 0 && is_program_login_unlocked($conn, $programId)) {
        $allowedProgramIds[] = $programId;
    }
}
$allowedProgramIds = normalize_program_id_list($allowedProgramIds);

$currentProgramId = (int) ($_SESSION['program_id'] ?? 0);
if (!empty($allowedProgramIds) && !in_array($currentProgramId, $allowedProgramIds, true)) {
    $currentProgramId = (int) $allowedProgramIds[0];
    $_SESSION['program_id'] = $currentProgramId;
}
$_SESSION['assigned_program_ids'] = $allowedProgramIds;

$currentProgramLabel = '';
if ($currentProgramId > 0) {
    $programSql = "
        SELECT program_code, program_name, major
        FROM tbl_program
        WHERE program_id = ?
        LIMIT 1
    ";
    $programStmt = $conn->prepare($programSql);
    if ($programStmt) {
        $programStmt->bind_param('i', $currentProgramId);
        $programStmt->execute();
        $programRow = $programStmt->get_result()->fetch_assoc();
        $programStmt->close();

        if ($programRow) {
            $programCode = trim((string) ($programRow['program_code'] ?? ''));
            $programName = trim((string) ($programRow['program_name'] ?? ''));
            $major = trim((string) ($programRow['major'] ?? ''));

            if ($programCode !== '') {
                $currentProgramLabel = $programCode;
                if ($major !== '') {
                    $currentProgramLabel .= ' - ' . $major;
                }
            } elseif ($programName !== '') {
                $currentProgramLabel = $programName;
            }
        }
    }

    if ($currentProgramLabel === '') {
        $currentProgramLabel = 'PROGRAM ' . $currentProgramId;
    }
}

if (empty($_SESSION['progchair_student_password_csrf'])) {
    try {
        $_SESSION['progchair_student_password_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['progchair_student_password_csrf'] = sha1(uniqid('progchair_student_password_csrf_', true));
    }
}

$csrfToken = (string) $_SESSION['progchair_student_password_csrf'];

function fetch_program_student_credential_row(mysqli $conn, string $examineeNumber, int $programId): ?array
{
    $sql = "
        SELECT
            sc.examinee_number,
            sc.active_email,
            sc.must_change_password,
            sc.password_changed_at,
            sc.updated_at,
            COALESCE(pr.full_name, '') AS full_name,
            COALESCE(pr.preferred_program, '') AS preferred_program
        FROM tbl_student_credentials sc
        INNER JOIN tbl_student_interview si
            ON si.interview_id = sc.interview_id
        LEFT JOIN tbl_placement_results pr
            ON pr.id = sc.placement_result_id
        WHERE sc.status = 'active'
          AND si.status = 'active'
          AND si.program_id = ?
          AND sc.examinee_number = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('is', $programId, $examineeNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $redirectUrl = 'student_passwords.php';

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['progchair_student_password_flash'] = [
            'type' => 'danger',
            'message' => 'Invalid request token. Please try again.'
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $rawExaminee = trim((string) ($_POST['examinee_number'] ?? ''));
    $examineeNumber = strtoupper($rawExaminee);
    if (strlen($examineeNumber) > 50) {
        $examineeNumber = substr($examineeNumber, 0, 50);
    }

    if ($examineeNumber === '') {
        $_SESSION['progchair_student_password_flash'] = [
            'type' => 'danger',
            'message' => 'Please provide an examinee number.'
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    if ($currentProgramId <= 0 || !in_array($currentProgramId, $allowedProgramIds, true)) {
        $_SESSION['progchair_student_password_flash'] = [
            'type' => 'danger',
            'message' => 'No valid active program assignment found for your account.'
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $studentRow = fetch_program_student_credential_row($conn, $examineeNumber, $currentProgramId);
    if ($studentRow === null) {
        $_SESSION['progchair_student_password_flash'] = [
            'type' => 'danger',
            'message' => 'This examinee is not under your active program or has no active credential record.'
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $resetResult = reset_student_temporary_password($conn, $examineeNumber);
    if (!($resetResult['success'] ?? false)) {
        $_SESSION['progchair_student_password_flash'] = [
            'type' => 'danger',
            'message' => (string) ($resetResult['message'] ?? 'Failed to issue a temporary password.')
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $_SESSION['progchair_student_password_flash'] = [
        'type' => 'success',
        'message' => 'New temporary password issued successfully.',
        'credential' => [
            'examinee_number' => $examineeNumber,
            'temporary_password' => (string) ($resetResult['temporary_code'] ?? ''),
            'full_name' => (string) ($studentRow['full_name'] ?? ''),
            'preferred_program' => (string) ($studentRow['preferred_program'] ?? '')
        ]
    ];

    header('Location: ' . $redirectUrl);
    exit;
}

$flash = null;
if (isset($_SESSION['progchair_student_password_flash']) && is_array($_SESSION['progchair_student_password_flash'])) {
    $flash = $_SESSION['progchair_student_password_flash'];
    unset($_SESSION['progchair_student_password_flash']);
}

$pageError = null;
if (!ensure_student_credentials_table($conn)) {
    $pageError = 'Failed to initialize student credential storage.';
}

if ($pageError === null && (empty($allowedProgramIds) || $currentProgramId <= 0 || !in_array($currentProgramId, $allowedProgramIds, true))) {
    $pageError = 'No unlocked active program assignment is available for your account.';
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
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Student Password Recovery - Interview</title>

    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .student-credential-actions {
        display: flex;
        flex-wrap: wrap;
        gap: 0.45rem;
      }

      .student-credential-qr-box {
        border: 1px dashed #f0ad4e;
        border-radius: 0.75rem;
        background: #fffdf8;
        padding: 0.75rem;
        text-align: center;
        max-width: 250px;
        margin-left: auto;
      }

      .student-credential-qr-image {
        width: 100%;
        max-width: 190px;
        height: auto;
        border-radius: 0.5rem;
        border: 1px solid #f6d8a0;
        background: #ffffff;
        padding: 0.35rem;
      }

      @media (max-width: 991.98px) {
        .student-credential-qr-box {
          margin-left: 0;
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
              <h4 class="fw-bold mb-1">
                <span class="text-muted fw-light">Program Chair /</span> Student Password Recovery
              </h4>
              <p class="text-muted mb-2">
                Student passwords are securely hashed, so old passwords cannot be viewed. Use this page to issue a new
                temporary password when a student forgets their login.
              </p>
              <?php if ($currentProgramLabel !== ''): ?>
                <p class="text-muted mb-4">
                  Active Program: <strong><?= htmlspecialchars($currentProgramLabel); ?></strong>
                </p>
              <?php endif; ?>

              <?php if ($pageError !== null): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($pageError); ?>
                </div>
              <?php endif; ?>

              <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars((string) ($flash['type'] ?? 'info')); ?>" role="alert">
                  <?= htmlspecialchars((string) ($flash['message'] ?? '')); ?>
                </div>
              <?php endif; ?>

              <?php if (!empty($flash['credential']) && is_array($flash['credential'])): ?>
                <?php
                  $issuedExamineeNumber = (string) ($flash['credential']['examinee_number'] ?? '');
                  $issuedTemporaryPassword = (string) ($flash['credential']['temporary_password'] ?? '');
                  $issuedFullName = (string) ($flash['credential']['full_name'] ?? '');
                  $issuedPreferredProgram = (string) ($flash['credential']['preferred_program'] ?? '-');
                  $studentPortalLoginUrl = 'https://interview.sksu-orms.net/';

                  $copyMessage = "Student Portal Credentials\n"
                    . "Student: {$issuedFullName}\n"
                    . "Examinee Number: {$issuedExamineeNumber}\n"
                    . "Preferred Program: {$issuedPreferredProgram}\n"
                    . "Login URL: {$studentPortalLoginUrl}\n"
                    . "Username: {$issuedExamineeNumber}\n"
                    . "Temporary Password: {$issuedTemporaryPassword}\n"
                    . "Note: Change password on first login.";

                  $qrPayload = "Student Portal Credentials\n"
                    . "Login URL: {$studentPortalLoginUrl}\n"
                    . "Username: {$issuedExamineeNumber}\n"
                    . "Temporary Password: {$issuedTemporaryPassword}";

                  $qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=220x220&margin=8&data=' . rawurlencode($qrPayload);
                ?>
                <div class="card mb-4 border border-warning">
                  <div class="card-header pb-2 d-flex flex-column flex-md-row align-items-md-start justify-content-between gap-2">
                    <h6 class="mb-0 text-warning">Issued Student Portal Credentials</h6>
                    <div class="student-credential-actions">
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-primary js-copy-text"
                        data-copy-text="<?= htmlspecialchars($copyMessage, ENT_QUOTES); ?>"
                      >
                        <i class="bx bx-copy me-1"></i>Copy Details
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-secondary js-copy-text"
                        data-copy-text="<?= htmlspecialchars($issuedTemporaryPassword, ENT_QUOTES); ?>"
                      >
                        <i class="bx bx-key me-1"></i>Copy Password
                      </button>
                      <button
                        type="button"
                        class="btn btn-sm btn-outline-info js-copy-text"
                        data-copy-text="<?= htmlspecialchars($studentPortalLoginUrl, ENT_QUOTES); ?>"
                      >
                        <i class="bx bx-link me-1"></i>Copy Login Link
                      </button>
                    </div>
                  </div>
                  <div class="card-body">
                    <div class="row g-3 align-items-start">
                      <div class="col-12 col-lg-8">
                        <p class="mb-1">
                          <strong>Student:</strong>
                          <?= htmlspecialchars($issuedFullName); ?>
                        </p>
                        <p class="mb-1">
                          <strong>Examinee Number:</strong>
                          <?= htmlspecialchars($issuedExamineeNumber); ?>
                        </p>
                        <p class="mb-1">
                          <strong>Preferred Program:</strong>
                          <?= htmlspecialchars($issuedPreferredProgram); ?>
                        </p>
                        <p class="mb-1">
                          <strong>Temporary Password:</strong>
                          <code><?= htmlspecialchars($issuedTemporaryPassword); ?></code>
                        </p>
                        <p class="mb-0">
                          <strong>Portal Login:</strong>
                          <a href="<?= htmlspecialchars($studentPortalLoginUrl); ?>" target="_blank" rel="noopener">
                            <?= htmlspecialchars($studentPortalLoginUrl); ?>
                          </a>
                        </p>
                        <small class="text-muted d-block mt-2">
                          Share this password securely with the student. They will be required to change it at next login.
                        </small>
                      </div>
                      <div class="col-12 col-lg-4">
                        <div class="student-credential-qr-box">
                          <div class="small fw-semibold text-warning mb-2">Scan QR to Share</div>
                          <img
                            src="<?= htmlspecialchars($qrUrl); ?>"
                            alt="Student portal credentials QR code"
                            class="student-credential-qr-image"
                          />
                          <small class="text-muted d-block mt-2">
                            QR contains login URL, username, and temporary password.
                          </small>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>

              <div class="row g-4 justify-content-center">
                <div class="col-md-8 col-lg-5">
                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-0">Issue Temporary Password</h5>
                    </div>
                    <div class="card-body">
                      <form method="POST" action="student_passwords.php" id="issuePasswordForm">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>" />
                        <div class="mb-3">
                          <label class="form-label" for="manualExamineeInput">Examinee Number</label>
                          <input
                            type="text"
                            class="form-control"
                            id="manualExamineeInput"
                            name="examinee_number"
                            maxlength="50"
                            required
                            placeholder="e.g. 508634"
                          />
                        </div>
                        <button
                          type="submit"
                          class="btn btn-warning w-100"
                        >
                          <i class="bx bx-key me-1"></i> Issue Temporary Password
                        </button>
                      </form>
                    </div>
                  </div>
                </div>
              </div>
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
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      (function () {
        async function copyText(text) {
          const value = String(text || '');
          if (!value) return false;

          try {
            if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
              await navigator.clipboard.writeText(value);
              return true;
            }
          } catch (error) {
          }

          const helper = document.createElement('textarea');
          helper.value = value;
          helper.setAttribute('readonly', 'readonly');
          helper.style.position = 'fixed';
          helper.style.opacity = '0';
          helper.style.pointerEvents = 'none';
          document.body.appendChild(helper);
          helper.focus();
          helper.select();

          let success = false;
          try {
            success = document.execCommand('copy');
          } catch (error) {
            success = false;
          }

          document.body.removeChild(helper);
          return success;
        }

        function setButtonState(button, text, cssClass) {
          if (!button) return;
          button.dataset.originalText = button.dataset.originalText || button.innerHTML;
          button.innerHTML = text;
          if (cssClass) {
            button.classList.add(cssClass);
          }
          setTimeout(() => {
            button.innerHTML = button.dataset.originalText || button.innerHTML;
            if (cssClass) {
              button.classList.remove(cssClass);
            }
          }, 1500);
        }

        const issuePasswordForm = document.getElementById('issuePasswordForm');
        if (issuePasswordForm) {
          issuePasswordForm.addEventListener('submit', async function (event) {
            event.preventDefault();

            const examineeInput = issuePasswordForm.querySelector('input[name="examinee_number"]');
            const examineeNumber = String((examineeInput && examineeInput.value) || '').trim();

            if (!examineeNumber) {
              if (typeof Swal !== 'undefined' && Swal.fire) {
                Swal.fire('Required', 'Please provide an examinee number.', 'warning');
              }
              return;
            }

            let proceed = false;
            if (typeof Swal !== 'undefined' && Swal.fire) {
              const result = await Swal.fire({
                title: 'Issue Temporary Password?',
                text: 'Issue a new temporary password for examinee #' + examineeNumber + '?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Yes, issue password',
                cancelButtonText: 'Cancel',
                reverseButtons: true
              });
              proceed = !!result.isConfirmed;
            } else {
              proceed = window.confirm('Issue a new temporary password for this student?');
            }

            if (proceed) {
              issuePasswordForm.submit();
            }
          });
        }

        document.addEventListener('click', async function (event) {
          const button = event.target.closest('.js-copy-text');
          if (!button) return;

          event.preventDefault();
          const textToCopy = button.getAttribute('data-copy-text') || '';
          const success = await copyText(textToCopy);
          if (success) {
            setButtonState(button, '<i class="bx bx-check me-1"></i>Copied', 'btn-success');
          } else {
            setButtonState(button, '<i class="bx bx-x me-1"></i>Copy Failed', 'btn-danger');
          }
        });
      })();
    </script>
  </body>
</html>
