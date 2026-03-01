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
require_once '../../config/program_assignments.php';

ensure_account_program_assignments_table($conn);

$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
$offset = isset($_GET['offset']) ? (int) $_GET['offset'] : 0;
$q = isset($_GET['q']) ? trim($_GET['q']) : '';
$campusId = isset($_GET['campus_id']) ? (int) $_GET['campus_id'] : 0;
$currentAdminId = (int) ($_SESSION['accountid'] ?? 0);

if ($limit < 1) {
  $limit = 20;
}
if ($limit > 50) {
  $limit = 50;
}
if ($offset < 0) {
  $offset = 0;
}

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
    COALESCE(pa.assigned_program_ids_csv, CAST(a.program_id AS CHAR)) AS assigned_program_ids_csv,
    COALESCE(
      NULLIF(pa.assigned_program_display, ''),
      CONCAT(
        p.program_name,
        IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' - ', p.major), '')
      )
    ) AS program_display
  FROM tblaccount a
  LEFT JOIN tbl_campus c
    ON a.campus_id = c.campus_id
  LEFT JOIN (
    SELECT
      apa.accountid,
      GROUP_CONCAT(
        CONCAT(
          tp.program_code,
          IF(tp.major IS NOT NULL AND tp.major <> '', CONCAT(' - ', tp.major), '')
        )
        ORDER BY tp.program_code ASC, tp.major ASC
        SEPARATOR ', '
      ) AS assigned_program_display,
      GROUP_CONCAT(apa.program_id ORDER BY apa.program_id ASC SEPARATOR ',') AS assigned_program_ids_csv
    FROM tbl_account_program_assignments apa
    INNER JOIN tbl_program tp
      ON tp.program_id = apa.program_id
    WHERE apa.status = 'active'
      AND tp.status = 'active'
    GROUP BY apa.accountid
  ) pa
    ON pa.accountid = a.accountid
  LEFT JOIN tbl_program p
    ON a.program_id = p.program_id
";

$params = [];
$types = '';
$whereClauses = [];

if ($q !== '') {
  $whereClauses[] = '(a.acc_fullname LIKE ? OR a.email LIKE ?)';
  $like = '%' . $q . '%';
  $params[] = $like;
  $params[] = $like;
  $types .= 'ss';
}

if ($campusId > 0) {
  $whereClauses[] = 'a.campus_id = ?';
  $params[] = $campusId;
  $types .= 'i';
}

if (count($whereClauses) > 0) {
  $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
}

$sql .= ' ORDER BY a.acc_fullname LIMIT ? OFFSET ?';
$params[] = $limit;
$params[] = $offset;
$types .= 'ii';

$stmt = $conn->prepare($sql);
if (!$stmt) {
  http_response_code(500);
  exit('');
}

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

while ($acc = $result->fetch_assoc()) {
  $badgeRole = ($acc['role'] === 'administrator')
    ? 'primary'
    : (($acc['role'] === 'progchair') ? 'info' : 'warning');
  $badgeStat = ($acc['status'] === 'active') ? 'success' : 'secondary';
  $assignedProgramIdsCsv = htmlspecialchars((string) ($acc['assigned_program_ids_csv'] ?? ''), ENT_QUOTES);

  echo '
  <div class="col-12 account-row">
    <div class="card border">
      <div class="card-body d-flex align-items-center justify-content-between gap-3">
        <div class="d-flex align-items-center gap-3 flex-grow-1">
          <div class="flex-grow-1">
            <div class="fw-semibold">' . htmlspecialchars($acc['acc_fullname']) . '</div>
            <div class="text-muted small">' . htmlspecialchars($acc['email']) . '</div>
          </div>

          <div class="d-flex gap-2">
            <span class="badge bg-label-' . $badgeRole . '">' . $acc['role'] . '</span>
            <span class="badge bg-label-' . $badgeStat . '">' . $acc['status'] . '</span>
          </div>

          <div class="small text-muted d-none d-md-block">
            Campus: ' . htmlspecialchars($acc['campus_name'] ?? '-') . '<br>
            Program: ' . htmlspecialchars($acc['program_display'] ?? '-') . '
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
                data-id="' . $acc['accountid'] . '"
                data-name="' . htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) . '"
                data-email="' . htmlspecialchars($acc['email'], ENT_QUOTES) . '"
                data-role="' . $acc['role'] . '"
                data-campus="' . $acc['campus_id'] . '"
                data-program="' . $acc['program_id'] . '"
                data-program-ids="' . $assignedProgramIdsCsv . '"
                data-status="' . $acc['status'] . '"
              >
                <i class="bx bx-edit-alt me-2"></i> Edit
              </a>
            </li>
            <li>';

  if ((int) $acc['accountid'] === $currentAdminId) {
    echo '
              <span class="dropdown-item text-muted disabled">
                <i class="bx bx-user me-2"></i> Current Account
              </span>';
  } else {
    $toggleAction = ($acc['status'] === 'active') ? 'lock' : 'unlock';
    $toggleLabel = ($acc['status'] === 'active') ? 'Lock Account' : 'Unlock Account';
    $toggleIcon = ($acc['status'] === 'active') ? 'bx-lock-alt' : 'bx-lock-open-alt';
    echo '
              <a
                class="dropdown-item btn-toggle-account-lock"
                href="javascript:void(0);"
                data-id="' . (int) $acc['accountid'] . '"
                data-name="' . htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) . '"
                data-current-status="' . htmlspecialchars($acc['status'], ENT_QUOTES) . '"
                data-action="' . $toggleAction . '"
              >
                <i class="bx ' . $toggleIcon . ' me-2"></i> ' . $toggleLabel . '
              </a>';
  }

  echo '
            </li>
            <li>
              <a
                class="dropdown-item text-danger btn-delete-account"
                href="javascript:void(0);"
                data-id="' . $acc['accountid'] . '"
                data-name="' . htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) . '"
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
