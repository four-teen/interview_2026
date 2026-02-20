<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
require_once '../config/mailer.php';
require_once '../config/session_security.php';
secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'student')) {
    header('Location: ../index.php');
    exit;
}

if (!ensure_student_credentials_table($conn)) {
    http_response_code(500);
    exit('Student portal initialization failed.');
}

$credentialId = (int) ($_SESSION['student_credential_id'] ?? 0);
$examineeNumber = (string) ($_SESSION['student_examinee_number'] ?? '');
if ($credentialId <= 0 || $examineeNumber === '') {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$errorMsg = '';
$successMsg = '';
$activeEmail = '';

$emailSql = "
    SELECT active_email
    FROM tbl_student_credentials
    WHERE credential_id = ?
      AND examinee_number = ?
    LIMIT 1
";
$emailStmt = $conn->prepare($emailSql);
if ($emailStmt) {
    $emailStmt->bind_param('is', $credentialId, $examineeNumber);
    $emailStmt->execute();
    $emailRow = $emailStmt->get_result()->fetch_assoc();
    if ($emailRow) {
        $activeEmail = trim((string) ($emailRow['active_email'] ?? ''));
    }
    $emailStmt->close();
}

if (empty($_SESSION['student_change_password_csrf'])) {
    try {
        $_SESSION['student_change_password_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['student_change_password_csrf'] = sha1(uniqid('student_pwd_csrf_', true));
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_change_password_csrf'] ?? '');
    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $errorMsg = 'Invalid security token. Refresh and try again.';
    }

    $newPassword = (string) ($_POST['new_password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $activeEmail = trim((string) ($_POST['active_email'] ?? $activeEmail));

    if ($errorMsg !== '') {
        // CSRF validation failed
    } elseif ($activeEmail === '' || !filter_var($activeEmail, FILTER_VALIDATE_EMAIL)) {
        $errorMsg = 'Please enter a valid active email address.';
    } elseif (strlen($newPassword) < 8) {
        $errorMsg = 'New password must be at least 8 characters.';
    } elseif (!preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
        $errorMsg = 'Password must include at least one uppercase letter and one number.';
    } elseif (stripos($newPassword, $examineeNumber) !== false) {
        $errorMsg = 'Password must not contain your examinee number.';
    } elseif ($newPassword !== $confirmPassword) {
        $errorMsg = 'Password confirmation does not match.';
    } else {
        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        if ($passwordHash === false) {
            $errorMsg = 'Failed to secure your new password.';
        } else {
            $updateSql = "
                UPDATE tbl_student_credentials
                SET password_hash = ?,
                    active_email = ?,
                    temp_code = NULL,
                    must_change_password = 0,
                    password_changed_at = NOW(),
                    status = 'active'
                WHERE credential_id = ?
                  AND examinee_number = ?
                LIMIT 1
            ";

            $updateStmt = $conn->prepare($updateSql);
            if (!$updateStmt) {
                $errorMsg = 'Failed to prepare password update.';
            } else {
                $updateStmt->bind_param('ssis', $passwordHash, $activeEmail, $credentialId, $examineeNumber);
                if ($updateStmt->execute()) {
                    $emailSubject = 'Student Portal Credentials Update';
                    $emailBody = implode("\r\n", [
                        'Your student portal credentials were updated.',
                        '',
                        'Username: ' . $examineeNumber,
                        'Password: ' . $newPassword,
                        '',
                        'Please keep this information secure.'
                    ]);

                    $mailError = null;
                    $emailSent = send_system_email($activeEmail, '', $emailSubject, $emailBody, $mailError);
                    if (!$emailSent && $mailError) {
                        error_log('Student password email failed: ' . $mailError);
                    }

                    $_SESSION['student_must_change_password'] = false;
                    unset($_SESSION['student_change_password_csrf']);
                    $emailNotice = $emailSent ? 'sent' : 'failed';
                    header('Location: index.php?password_changed=1&email_notice=' . urlencode($emailNotice));
                    exit;
                }
                $errorMsg = 'Failed to update password. Please try again.';
                $updateStmt->close();
            }
        }
    }
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style customizer-hide"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
    <title>Change Password - Student Portal</title>
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
    <link rel="stylesheet" href="../assets/vendor/css/pages/page-auth.css" />
  </head>

  <body>
    <div class="container-xxl">
      <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner">
          <div class="card">
            <div class="card-body p-4 p-md-5">
              <h4 class="mb-2 text-center">Change Student Password</h4>
              <p class="mb-3 text-center text-muted">
                Please set a new password before accessing your dashboard.
              </p>

              <?php if ($errorMsg !== ''): ?>
                <div class="alert alert-danger small"><?= htmlspecialchars($errorMsg); ?></div>
              <?php endif; ?>

              <?php if ($successMsg !== ''): ?>
                <div class="alert alert-success small"><?= htmlspecialchars($successMsg); ?></div>
              <?php endif; ?>

              <form method="post">
                <input
                  type="hidden"
                  name="csrf_token"
                  value="<?= htmlspecialchars((string) ($_SESSION['student_change_password_csrf'] ?? ''), ENT_QUOTES); ?>"
                />
                <div class="mb-3">
                  <label for="examinee_number" class="form-label">Examinee Number</label>
                  <input
                    type="text"
                    id="examinee_number"
                    name="examinee_number"
                    class="form-control"
                    value="<?= htmlspecialchars($examineeNumber); ?>"
                    readonly
                    autocomplete="username"
                  />
                </div>
                <div class="mb-3">
                  <label for="active_email" class="form-label">Active Email</label>
                  <input
                    type="email"
                    name="active_email"
                    id="active_email"
                    class="form-control"
                    value="<?= htmlspecialchars($activeEmail); ?>"
                    autocomplete="email"
                    required
                  />
                </div>
                <div class="mb-3">
                  <label for="new_password" class="form-label">New Password</label>
                  <input
                    type="password"
                    name="new_password"
                    id="new_password"
                    class="form-control"
                    minlength="8"
                    autocomplete="new-password"
                    required
                  />
                </div>
                <div class="mb-3">
                  <label for="confirm_password" class="form-label">Confirm Password</label>
                  <input
                    type="password"
                    name="confirm_password"
                    id="confirm_password"
                    class="form-control"
                    minlength="8"
                    autocomplete="new-password"
                    required
                  />
                </div>

                <div class="alert alert-info small mb-3" role="alert">
                  Use your browser password manager so this 8-character password is saved securely.
                </div>

                <div class="d-flex flex-wrap gap-2 mb-2">
                  <button type="button" id="togglePasswordBtn" class="btn btn-outline-secondary btn-sm">
                    Show Passwords
                  </button>
                  <button type="button" id="copyPasswordBtn" class="btn btn-outline-primary btn-sm">
                    Copy New Password
                  </button>
                </div>
                <div id="passwordHelperMessage" class="small text-muted mb-3"></div>

                <button type="submit" class="btn btn-primary d-grid w-100">Update Password</button>
              </form>

              <div class="text-center mt-3">
                <a href="../logout.php" class="small">Log out</a>
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
    <script>
      (function () {
        const newPasswordEl = document.getElementById('new_password');
        const confirmPasswordEl = document.getElementById('confirm_password');
        const toggleBtn = document.getElementById('togglePasswordBtn');
        const copyBtn = document.getElementById('copyPasswordBtn');
        const helperEl = document.getElementById('passwordHelperMessage');

        if (!newPasswordEl || !confirmPasswordEl || !helperEl) {
          return;
        }

        function setHelper(message, isError) {
          helperEl.textContent = message || '';
          helperEl.classList.toggle('text-danger', Boolean(isError));
          helperEl.classList.toggle('text-success', !isError && message !== '');
          if (!message) {
            helperEl.classList.remove('text-danger');
            helperEl.classList.remove('text-success');
            helperEl.classList.add('text-muted');
          } else {
            helperEl.classList.remove('text-muted');
          }
        }

        function validateConfirmPassword() {
          if (confirmPasswordEl.value && newPasswordEl.value !== confirmPasswordEl.value) {
            confirmPasswordEl.setCustomValidity('Password confirmation does not match.');
          } else {
            confirmPasswordEl.setCustomValidity('');
          }
        }

        function getNewPasswordValue() {
          const value = (newPasswordEl.value || '').trim();
          if (!value) {
            setHelper('Type your new password first.', true);
            return '';
          }
          return value;
        }

        newPasswordEl.addEventListener('input', validateConfirmPassword);
        confirmPasswordEl.addEventListener('input', validateConfirmPassword);

        if (toggleBtn) {
          toggleBtn.addEventListener('click', function () {
            const show = newPasswordEl.type === 'password';
            newPasswordEl.type = show ? 'text' : 'password';
            confirmPasswordEl.type = show ? 'text' : 'password';
            toggleBtn.textContent = show ? 'Hide Passwords' : 'Show Passwords';
          });
        }

        if (copyBtn) {
          copyBtn.addEventListener('click', async function () {
            const newPassword = getNewPasswordValue();
            if (!newPassword) return;

            if (!navigator.clipboard || !window.isSecureContext) {
              setHelper('Copy not available here. Long-press and copy manually.', true);
              return;
            }

            try {
              await navigator.clipboard.writeText(newPassword);
              setHelper('Password copied. Save it in a secure password manager.');
            } catch (err) {
              setHelper('Copy failed. Please copy manually.', true);
            }
          });
        }
      })();
    </script>
  </body>
</html>
