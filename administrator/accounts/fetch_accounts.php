<?php
/**
 * Fetch Accounts for Infinite Scroll (HTML Snippet)
 * Path: /interview/administrator/accounts/fetch_accounts.php
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
  http_response_code(403);
  exit('Unauthorized');
}

require_once '../../config/db.php';

$limit  = isset($_GET['limit'])  ? (int) $_GET['limit']  : 20;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$q      = isset($_GET['q']) ? trim($_GET['q']) : '';

if ($limit < 1) $limit = 20;
if ($limit > 50) $limit = 50;
if ($offset < 0) $offset = 0;

$sql = "
  SELECT 
    a.accountid,
    a.acc_fullname,
    a.email,
    a.role,
    a.status,

    a.campus_id,
    c.campus_name,

    a.program_id,
    CONCAT(
      p.program_name,
      IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' – ', p.major), '')
    ) AS program_display

  FROM tblaccount a
  LEFT JOIN tbl_campus c ON a.campus_id = c.campus_id
  LEFT JOIN tbl_program p ON a.program_id = p.program_id
";

$params = [];
$types  = "";

if ($q !== "") {
  $sql .= " WHERE a.acc_fullname LIKE ? OR a.email LIKE ? ";
  $like = "%{$q}%";
  $params[] = $like;
  $params[] = $like;
  $types .= "ss";
}

$sql .= " ORDER BY a.acc_fullname LIMIT ? OFFSET ? ";
$params[] = $limit;
$params[] = $offset;
$types .= "ii";

$stmt = $conn->prepare($sql);
$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($acc = $result->fetch_assoc()) {

  $badgeRole = ($acc['role'] === 'administrator')
    ? 'primary'
    : (($acc['role'] === 'progchair') ? 'info' : 'warning');

  $badgeStat = ($acc['status'] === 'active') ? 'success' : 'secondary';

  echo '
  <div class="col-12 account-row">
    <div class="card border">
      <div class="card-body d-flex align-items-center justify-content-between gap-3">

        <div class="d-flex align-items-center gap-3 flex-grow-1">
          <div class="flex-grow-1">
            <div class="fw-semibold">'.htmlspecialchars($acc['acc_fullname']).'</div>
            <div class="text-muted small">'.htmlspecialchars($acc['email']).'</div>
          </div>

          <div class="d-flex gap-2">
            <span class="badge bg-label-'.$badgeRole.'">'.$acc['role'].'</span>
            <span class="badge bg-label-'.$badgeStat.'">'.$acc['status'].'</span>
          </div>

          <div class="small text-muted d-none d-md-block">
            Campus: '.htmlspecialchars($acc['campus_name'] ?? '—').'<br>
            Program: '.htmlspecialchars($acc['program_display'] ?? '—').'
          </div>
        </div>

        <div class="dropdown">
          <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown">
            <i class="bx bx-dots-vertical-rounded"></i>
          </button>
          <ul class="dropdown-menu dropdown-menu-end">

            <li>
              <a
                class="dropdown-item btn-edit-account"
                href="javascript:void(0);"
                data-id="'.$acc['accountid'].'"
                data-name="'.htmlspecialchars($acc['acc_fullname'], ENT_QUOTES).'"
                data-email="'.htmlspecialchars($acc['email'], ENT_QUOTES).'"
                data-role="'.$acc['role'].'"
                data-campus="'.$acc['campus_id'].'"
                data-program="'.$acc['program_id'].'"
                data-status="'.$acc['status'].'"
              >
                <i class="bx bx-edit-alt me-2"></i> Edit
              </a>
            </li>

            <li>
              <a
                class="dropdown-item text-danger btn-delete-account"
                href="javascript:void(0);"
                data-id="'.$acc['accountid'].'"
                data-name="'.htmlspecialchars($acc['acc_fullname'], ENT_QUOTES).'"
              >
                <i class="bx bx-trash me-2"></i> Delete
              </a>
            </li>

          </ul>
        </div>

      </div>
    </div>
  </div>';
}

$stmt->close();
