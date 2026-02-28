<?php
require_once __DIR__ . '/config/env.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/system_controls.php';
require_once __DIR__ . '/config/session_security.php';

secure_session_start();

$googleClientId = getenv('GOOGLE_CLIENT_ID') ?: '115027937761-p80e2nudpe4ldsg9kbi73qc5o9nhg07p.apps.googleusercontent.com';

$googleLoginNonce = (string) ($_SESSION['google_login_nonce'] ?? '');
$googleLoginCsrf = (string) ($_SESSION['google_login_csrf'] ?? '');
$studentLoginCsrf = (string) ($_SESSION['student_login_csrf'] ?? '');

if ($googleLoginNonce === '' || $googleLoginCsrf === '' || $studentLoginCsrf === '') {
    try {
        $googleLoginNonce = bin2hex(random_bytes(16));
        $googleLoginCsrf = bin2hex(random_bytes(32));
        $studentLoginCsrf = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $googleLoginNonce = sha1(uniqid('nonce_', true));
        $googleLoginCsrf = sha1(uniqid('gcsrf_', true));
        $studentLoginCsrf = sha1(uniqid('scsrf_', true));
    }

    $_SESSION['google_login_nonce'] = $googleLoginNonce;
    $_SESSION['google_login_csrf'] = $googleLoginCsrf;
    $_SESSION['student_login_csrf'] = $studentLoginCsrf;
}
$nonAdminLoginLocked = is_non_admin_login_locked($conn);
$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffActive = (bool) ($globalSatCutoffState['active'] ?? false);
$globalSatCutoffRangeText = trim((string) ($globalSatCutoffState['range_text'] ?? ''));
$globalSatCutoffLabel = 'Global SAT cutoff is active.';
if ($globalSatCutoffRangeText !== '') {
    $globalSatCutoffLabel = 'Global SAT cutoff is active. Accepted SAT range: ' . $globalSatCutoffRangeText . '.';
}
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style customizer-hide"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no"
    />

    <title>SKSU Centralized Interview System</title>

    <meta
      name="description"
      content="Centralized interview management system for SKSU tertiary placement test passers."
    />

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <!-- Icons -->
    <link rel="stylesheet" href="assets/vendor/fonts/boxicons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="assets/vendor/css/core.css" />
    <link rel="stylesheet" href="assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link
      rel="stylesheet"
      href="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css"
    />

    <!-- Page CSS -->
    <link
      rel="stylesheet"
      href="assets/vendor/css/pages/page-auth.css"
    />

    <style>
      :root {
        --login-bg: linear-gradient(135deg, #f3f6fb 0%, #f8fafc 45%, #edf7f1 100%);
        --login-card-border: #d9e2ef;
        --login-card-shadow: 0 18px 40px rgba(15, 23, 42, 0.08);
        --login-title: #1f3552;
        --login-subtitle: #526a86;
        --login-note-bg: #edf5ff;
        --login-note-border: #cbdff8;
        --login-note-text: #1f4e7a;
      }

      body {
        background: var(--login-bg);
      }

      .authentication-wrapper {
        min-height: 100vh;
        padding-top: 2rem;
        padding-bottom: 2rem;
        display: flex;
        align-items: center;
      }

      .authentication-inner {
        width: 100%;
        max-width: 560px;
        position: relative;
        padding-top: 2.1rem;
      }

      .login-page-logo-wrap {
        position: absolute;
        top: 0;
        left: 50%;
        transform: translate(-50%, -12%);
        display: flex;
        justify-content: center;
        margin-bottom: 0;
        z-index: 5;
        pointer-events: none;
      }

      .login-page-logo {
        width: 34%;
        max-width: 170px;
        min-width: 100px;
        height: auto;
        object-fit: contain;
        background: #f3f6fb;
        border: 8px solid #f3f6fb;
        border-radius: 50%;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.18);
      }

      .login-card {
        margin-top: 1rem;
        border: 1px solid var(--login-card-border);
        border-radius: 16px;
        box-shadow: var(--login-card-shadow);
      }

      .login-brand-icon {
        width: 44px;
        height: 44px;
        border-radius: 12px;
        background: #edf4ff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
      }

      .login-brand-text {
        color: #1e324d;
        letter-spacing: 0.2px;
      }

      .login-title {
        color: var(--login-title);
        font-weight: 700;
      }

      .login-subtitle {
        color: var(--login-subtitle);
      }

      .login-alert {
        border-radius: 12px;
        border: 1px solid var(--login-note-border);
        font-size: 0.95rem;
      }

      .login-alert.alert-info {
        background: var(--login-note-bg);
        color: var(--login-note-text);
      }

      .login-alert.alert-warning {
        background: #fff4e8;
        border-color: #ffd6aa;
        color: #8a4a07;
      }

      .login-alert-cutoff {
        background: #e8f5e9;
        border-color: #1b5e20;
        color: #1b5e20;
        text-align: center;
        font-weight: 600;
      }

      .login-divider {
        text-align: center;
        margin: 1rem 0 0.9rem;
      }

      .login-divider span {
        display: inline-block;
        font-size: 0.8rem;
        color: #6b7f98;
        text-transform: uppercase;
        letter-spacing: 0.7px;
      }

      .login-footnote {
        color: #5f7088;
      }

      .student-login-panel {
        margin-top: 1.1rem;
        border-top: 1px solid #e6edf7;
        padding-top: 1rem;
      }

      .student-login-toggle {
        border-radius: 10px;
        border-color: #cdd8e9;
        color: #325172;
      }

      .student-login-toggle:hover {
        border-color: #9fb3cf;
        color: #27415d;
      }

      .student-login-form {
        margin-top: 0.85rem;
      }

      .student-login-form .form-control {
        border-radius: 10px;
      }

      .student-login-form .btn {
        border-radius: 10px;
      }

      .google-login-slot {
        display: flex;
        justify-content: center;
        align-items: center;
        width: 100%;
      }

      .google-login-slot .g_id_signin {
        display: inline-flex;
        justify-content: center;
      }

      .google-login-slot .g_id_signin > div,
      .google-login-slot iframe {
        display: block;
        margin: 0 auto !important;
        max-width: 100%;
      }

      @media (max-width: 575.98px) {
        .authentication-inner {
          max-width: 100%;
          padding-top: 1.8rem;
        }

        .login-page-logo {
          width: 40%;
          min-width: 86px;
          max-width: 132px;
        }
      }
    </style>
    <!-- Helpers -->
    <script src="assets/vendor/js/helpers.js"></script>
    <script src="assets/js/config.js"></script>

    <!-- Google Identity Services -->
    <script
      src="https://accounts.google.com/gsi/client"
      async
      defer
    ></script>
  </head>

  <body>
    <!-- Content -->

    <?php
      $statusMsg = '';

      if (isset($_GET['status']) && $_GET['status'] === 'pending_validation') {
          $statusMsg = 'Your email is verified, but your account is pending validation by the system administrator.';
      }
    ?>


    <div class="container-xxl">
      <div class="authentication-wrapper authentication-basic container-p-y">
        <div class="authentication-inner">
          <div class="login-page-logo-wrap">
            <img src="assets/img/logo.png" alt="SKSU Logo" class="login-page-logo" />
          </div>

          <!-- Login Card -->
          <div class="card login-card">
            <div class="card-body p-4 p-md-5">

              <!-- Branding -->
              <div class="app-brand justify-content-center mb-3">
                <a href="#" class="app-brand-link gap-2">
                  <span class="app-brand-text demo fw-bolder login-brand-text">
                    SKSU Interview
                  </span>
                </a>
              </div>

              <h5 class="mb-2 text-center login-title">
                Centralized Interview System
              </h5>
              <p class="mb-3 text-center login-subtitle">
                Official portal for tertiary placement interview processing.<br />
                <small class="text-muted">
                  Sultan Kudarat State University - 7 Campuses
                </small>
              </p>

              <!-- Notice -->
<?php if (!empty($statusMsg)): ?>
  <div class="alert alert-warning small login-alert" role="alert">
    <?= htmlspecialchars($statusMsg); ?>
  </div>
<?php else: ?>
  <div class="alert alert-info small login-alert" role="alert">
    Only official SKSU organizational Google accounts are allowed to
    access this system.
  </div>
<?php endif; ?>
<?php if ($nonAdminLoginLocked): ?>
  <div class="alert alert-warning small login-alert" role="alert">
    Student and Program Chair login is temporarily locked by the administrator.
    Administrator login remains available.
  </div>
<?php endif; ?>
<?php if ($globalSatCutoffActive): ?>
  <div class="alert small login-alert login-alert-cutoff" role="alert">
    <?= htmlspecialchars($globalSatCutoffLabel); ?>
  </div>
<?php endif; ?>

              <!-- Google Login -->
              <div class="mb-3">
                <div class="login-divider">
                  <span>Authorized Google Sign-In</span>
                </div>
                <div
                  id="g_id_onload"
                  data-client_id="<?= htmlspecialchars((string) $googleClientId, ENT_QUOTES); ?>"
                  data-callback="handleGoogleCredential"
                  data-nonce="<?= htmlspecialchars((string) $googleLoginNonce, ENT_QUOTES); ?>"
                  data-auto_prompt="false"
                ></div>

                <div class="google-login-slot">
                  <div
                    class="g_id_signin"
                    data-type="standard"
                    data-size="large"
                    data-theme="outline"
                    data-text="continue_with"
                    data-shape="rectangular"
                    data-logo_alignment="left"
                  ></div>
                </div>

                <div
                  id="loginMsg"
                  class="mt-3 small text-center text-muted"
                ></div>
              </div>

              <div class="student-login-panel">
                <div class="login-divider mt-0">
                  <span>Student Portal Access</span>
                </div>

                <button
                  type="button"
                  id="studentLoginToggle"
                  class="btn btn-outline-secondary w-100 student-login-toggle"
                  <?= $nonAdminLoginLocked ? 'disabled' : ''; ?>
                >
                  <?= $nonAdminLoginLocked ? 'Student Login (Locked)' : 'Student Login'; ?>
                </button>

                <form id="studentLoginForm" class="student-login-form d-none">
                  <div class="mb-2">
                    <input
                      type="text"
                      id="studentExamineeNumber"
                      class="form-control"
                      placeholder="Examinee Number"
                      autocomplete="username"
                      required
                    />
                  </div>
                  <div class="mb-2">
                    <input
                      type="password"
                      id="studentPassword"
                      class="form-control"
                      placeholder="Temporary / Current Password"
                      autocomplete="current-password"
                      autocapitalize="none"
                      autocorrect="off"
                      spellcheck="false"
                      required
                    />
                  </div>
                  <button type="submit" class="btn btn-primary w-100">
                    Login as Student
                  </button>
                  <div id="studentLoginMsg" class="small text-center mt-2 text-muted"></div>
                </form>
              </div>

              <p class="text-center small mb-0 login-footnote">
                Login will be validated against the university account registry.
              </p>
            </div>
          </div>

        </div>
      </div>
    </div>

    <!-- Core JS -->
    <script src="assets/vendor/libs/jquery/jquery.js"></script>
    <script src="assets/vendor/libs/popper/popper.js"></script>
    <script src="assets/vendor/js/bootstrap.js"></script>
    <script src="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="assets/vendor/js/menu.js"></script>
    <script src="assets/js/main.js"></script>

    <!-- Login Logic -->
<script>
const googleLoginCsrfToken = <?= json_encode((string) $googleLoginCsrf); ?>;
const studentLoginCsrfToken = <?= json_encode((string) $studentLoginCsrf); ?>;
let googleLoginInFlight = false;

function handleGoogleCredential(response) {
  if (googleLoginInFlight) {
    return;
  }

  const msg = document.getElementById("loginMsg");
  googleLoginInFlight = true;

  msg.className = "mt-3 small text-center text-success";
  msg.textContent = "Login successful. Checking account authorization...";

  fetch('auth/google_callback.php', {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded'
    },
    body: new URLSearchParams({
      credential: response.credential,
      csrf_token: googleLoginCsrfToken
    })
  })
  .then(res => res.json())
  .then(data => {
    if (!data.success) {
      throw new Error(data.message || 'Authentication failed');
    }

    window.location.href = data.redirect;
  })
  .catch(err => {
    console.error(err);
    msg.className = "mt-3 small text-center text-danger";
    msg.textContent = err.message || "Authentication failed. Please contact the system administrator.";
    googleLoginInFlight = false;
  });
}

const studentLoginToggle = document.getElementById("studentLoginToggle");
const studentLoginForm = document.getElementById("studentLoginForm");
const studentLoginMsg = document.getElementById("studentLoginMsg");

if (studentLoginToggle && studentLoginForm) {
  studentLoginToggle.addEventListener("click", function () {
    studentLoginForm.classList.toggle("d-none");
    if (!studentLoginForm.classList.contains("d-none")) {
      const examineeInput = document.getElementById("studentExamineeNumber");
      if (examineeInput) examineeInput.focus();
    }
  });
}

if (studentLoginForm) {
  studentLoginForm.addEventListener("submit", function (event) {
    event.preventDefault();

    const examineeNumber = String(document.getElementById("studentExamineeNumber")?.value || "").trim();
    const password = String(document.getElementById("studentPassword")?.value || "");

    if (!examineeNumber || !password) {
      if (studentLoginMsg) {
        studentLoginMsg.className = "small text-center mt-2 text-danger";
        studentLoginMsg.textContent = "Please provide examinee number and password.";
      }
      return;
    }

    if (studentLoginMsg) {
      studentLoginMsg.className = "small text-center mt-2 text-success";
      studentLoginMsg.textContent = "Signing in...";
    }

    fetch("auth/student_login.php", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded"
      },
      body: new URLSearchParams({
        examinee_number: examineeNumber,
        password: password,
        csrf_token: studentLoginCsrfToken
      })
    })
      .then((res) => res.json())
      .then((data) => {
        if (!data.success) {
          throw new Error(data.message || "Student login failed.");
        }
        window.location.href = data.redirect;
      })
      .catch((error) => {
        console.error(error);
        if (studentLoginMsg) {
          studentLoginMsg.className = "small text-center mt-2 text-danger";
          studentLoginMsg.textContent = error.message || "Student login failed.";
        }
      });
  });
}
</script>


  </body>
</html>
