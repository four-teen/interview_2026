<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
    header('Location: ../index.php');
    exit;
}

if (empty($_SESSION['admin_student_password_csrf'])) {
    try {
        $_SESSION['admin_student_password_csrf'] = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
        $_SESSION['admin_student_password_csrf'] = sha1(uniqid('admin_student_password_csrf_', true));
    }
}

$csrfToken = (string) $_SESSION['admin_student_password_csrf'];
$searchQuery = trim((string) ($_GET['q'] ?? ''));
if (strlen($searchQuery) > 100) {
    $searchQuery = substr($searchQuery, 0, 100);
}

function fetch_student_credential_row(mysqli $conn, string $examineeNumber): ?array
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
        LEFT JOIN tbl_placement_results pr
          ON pr.id = sc.placement_result_id
        WHERE sc.examinee_number = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $examineeNumber);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $returnQ = trim((string) ($_POST['return_q'] ?? ''));
    if (strlen($returnQ) > 100) {
        $returnQ = substr($returnQ, 0, 100);
    }

    $redirectUrl = 'student_passwords.php';
    if ($returnQ !== '') {
        $redirectUrl .= '?q=' . urlencode($returnQ);
    }

    if ($postedToken === '' || !hash_equals($csrfToken, $postedToken)) {
        $_SESSION['student_password_flash'] = [
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
        $_SESSION['student_password_flash'] = [
            'type' => 'danger',
            'message' => 'Please provide an examinee number.'
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $resetResult = reset_student_temporary_password($conn, $examineeNumber);
    if (!($resetResult['success'] ?? false)) {
        $_SESSION['student_password_flash'] = [
            'type' => 'danger',
            'message' => (string) ($resetResult['message'] ?? 'Failed to issue a temporary password.')
        ];
        header('Location: ' . $redirectUrl);
        exit;
    }

    $studentRow = fetch_student_credential_row($conn, $examineeNumber);
    $_SESSION['student_password_flash'] = [
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
if (isset($_SESSION['student_password_flash']) && is_array($_SESSION['student_password_flash'])) {
    $flash = $_SESSION['student_password_flash'];
    unset($_SESSION['student_password_flash']);
}

$pageError = null;
if (!ensure_student_credentials_table($conn)) {
    $pageError = 'Failed to initialize student credential storage.';
}

$rows = [];
if ($pageError === null) {
    if ($searchQuery !== '') {
        $like = '%' . $searchQuery . '%';
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
            LEFT JOIN tbl_placement_results pr
              ON pr.id = sc.placement_result_id
            WHERE sc.status = 'active'
              AND (
                  sc.examinee_number LIKE ?
                  OR pr.full_name LIKE ?
                  OR pr.preferred_program LIKE ?
              )
            ORDER BY sc.updated_at DESC
            LIMIT 50
        ";
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('sss', $like, $like, $like);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
            $stmt->close();
        } else {
            $pageError = 'Failed to prepare student credential search query.';
        }
    } else {
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
            LEFT JOIN tbl_placement_results pr
              ON pr.id = sc.placement_result_id
            WHERE sc.status = 'active'
            ORDER BY sc.updated_at DESC
            LIMIT 30
        ";
        $result = $conn->query($sql);
        if ($result) {
            while ($row = $result->fetch_assoc()) {
                $rows[] = $row;
            }
        } else {
            $pageError = 'Failed to load student credentials.';
        }
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
                <span class="text-muted fw-light">Management /</span> Student Password Recovery
              </h4>
              <p class="text-muted mb-4">
                Student passwords are securely hashed, so old passwords cannot be viewed. Use this page to issue a new
                temporary password when a student forgets their login.
              </p>

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
                <div class="card mb-4 border border-warning">
                  <div class="card-header pb-2">
                    <h6 class="mb-0 text-warning">Issued Student Portal Credentials</h6>
                  </div>
                  <div class="card-body">
                    <p class="mb-1">
                      <strong>Student:</strong>
                      <?= htmlspecialchars((string) ($flash['credential']['full_name'] ?? '')); ?>
                    </p>
                    <p class="mb-1">
                      <strong>Examinee Number:</strong>
                      <?= htmlspecialchars((string) ($flash['credential']['examinee_number'] ?? '')); ?>
                    </p>
                    <p class="mb-1">
                      <strong>Preferred Program:</strong>
                      <?= htmlspecialchars((string) ($flash['credential']['preferred_program'] ?? '-')); ?>
                    </p>
                    <p class="mb-0">
                      <strong>Temporary Password:</strong>
                      <code><?= htmlspecialchars((string) ($flash['credential']['temporary_password'] ?? '')); ?></code>
                    </p>
                    <small class="text-muted d-block mt-2">
                      Share this password securely with the student. They will be required to change it at next login.
                    </small>
                  </div>
                </div>
              <?php endif; ?>

              <div class="row g-4">
                <div class="col-lg-4">
                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-0">Issue by Examinee Number</h5>
                    </div>
                    <div class="card-body">
                      <form method="POST" action="student_passwords.php">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>" />
                        <input type="hidden" name="return_q" value="<?= htmlspecialchars($searchQuery); ?>" />
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
                          onclick="return confirm('Issue a new temporary password for this student?');"
                        >
                          <i class="bx bx-key me-1"></i> Issue Temporary Password
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <div class="col-lg-8">
                  <div class="card">
                    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                      <h5 class="mb-0">Student Credential Records</h5>
                      <form method="GET" action="student_passwords.php" class="d-flex gap-2">
                        <input
                          type="search"
                          class="form-control form-control-sm"
                          name="q"
                          value="<?= htmlspecialchars($searchQuery); ?>"
                          placeholder="Search examinee, name, or program"
                          maxlength="100"
                        />
                        <button type="submit" class="btn btn-sm btn-primary">Search</button>
                      </form>
                    </div>
                    <div class="card-body">
                      <div class="table-responsive">
                        <table class="table table-sm align-middle">
                          <thead>
                            <tr>
                              <th>Examinee #</th>
                              <th>Student</th>
                              <th>Program</th>
                              <th>Status</th>
                              <th class="text-end">Action</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php if (empty($rows)): ?>
                              <tr>
                                <td colspan="5" class="text-center text-muted py-4">
                                  No student credentials found.
                                </td>
                              </tr>
                            <?php else: ?>
                              <?php foreach ($rows as $row): ?>
                                <tr>
                                  <td class="fw-semibold">
                                    <?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?>
                                  </td>
                                  <td>
                                    <?= htmlspecialchars((string) ($row['full_name'] ?: 'No placement profile')); ?>
                                  </td>
                                  <td>
                                    <?= htmlspecialchars((string) ($row['preferred_program'] ?: '-')); ?>
                                  </td>
                                  <td>
                                    <?php if ((int) ($row['must_change_password'] ?? 0) === 1): ?>
                                      <span class="badge bg-label-warning">Needs Change</span>
                                    <?php else: ?>
                                      <span class="badge bg-label-success">Updated</span>
                                    <?php endif; ?>
                                  </td>
                                  <td class="text-end">
                                    <form method="POST" action="student_passwords.php" class="d-inline">
                                      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken); ?>" />
                                      <input type="hidden" name="return_q" value="<?= htmlspecialchars($searchQuery); ?>" />
                                      <input
                                        type="hidden"
                                        name="examinee_number"
                                        value="<?= htmlspecialchars((string) ($row['examinee_number'] ?? '')); ?>"
                                      />
                                      <button
                                        type="submit"
                                        class="btn btn-sm btn-outline-warning"
                                        onclick="return confirm('Issue a new temporary password for this student?');"
                                      >
                                        Reset Password
                                      </button>
                                    </form>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            <?php endif; ?>
                          </tbody>
                        </table>
                      </div>
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
    <script src="../assets/js/main.js"></script>
  </body>
</html>
