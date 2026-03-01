<?php
/**
 * Save User Account
 * Table: tblaccount
 * Role: Administrator only
 */

session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
    http_response_code(403);
    exit('Unauthorized');
}

require_once '../../config/db.php';
require_once '../../config/program_assignments.php';

/**
 * Normalize posted program IDs to a unique positive-int list.
 *
 * @param mixed $rawValue
 * @return int[]
 */
function normalize_posted_program_ids($rawValue): array
{
    $values = [];
    if (is_array($rawValue)) {
        $values = $rawValue;
    } elseif ($rawValue !== null && $rawValue !== '') {
        $values = [$rawValue];
    }

    $normalized = [];
    foreach ($values as $value) {
        $id = (int) $value;
        if ($id > 0) {
            $normalized[$id] = $id;
        }
    }

    return array_values($normalized);
}

/* =======================
   Validate POST
======================= */
$fullname   = trim($_POST['acc_fullname'] ?? '');
$email      = trim($_POST['email'] ?? '');
$role       = $_POST['role'] ?? 'progchair';
$campus_id  = $_POST['campus_id'] !== '' ? (int)$_POST['campus_id'] : null;
$status     = $_POST['status'] ?? 'inactive';
$programIds = normalize_posted_program_ids($_POST['program_ids'] ?? []);

if (empty($programIds)) {
    $legacyProgramId = (int) ($_POST['program_id'] ?? 0);
    if ($legacyProgramId > 0) {
        $programIds = [$legacyProgramId];
    }
}

$program_id = !empty($programIds) ? (int) $programIds[0] : null;

if ($fullname === '' || $email === '') {
    $_SESSION['error'] = 'Full name and email are required.';
    header('Location: index.php');
    exit;
}

if ($role === 'progchair' && empty($programIds)) {
    $_SESSION['error'] = 'Program Chair accounts must have at least one assigned program.';
    header('Location: index.php');
    exit;
}

if (!in_array($status, ['active', 'inactive'], true)) {
    $status = 'inactive';
}

if ($role !== 'administrator') {
    // Non-admin accounts should start locked by default.
    $status = 'inactive';
}

/* =======================
   Check duplicate email
======================= */
$chk = $conn->prepare("SELECT accountid FROM tblaccount WHERE email = ?");
$chk->bind_param("s", $email);
$chk->execute();
$chk->store_result();

if ($chk->num_rows > 0) {
    $chk->close();
    $_SESSION['error'] = 'Email already exists.';
    header('Location: index.php');
    exit;
}
$chk->close();

try {
    $conn->begin_transaction();

    if (!ensure_account_program_assignments_table($conn)) {
        throw new RuntimeException('Unable to prepare program assignment storage.');
    }

    /* =======================
       Insert account
    ======================= */
    $sql = "
      INSERT INTO tblaccount
      (
        acc_fullname,
        email,
        acc_type,
        approved,
        role,
        campus_id,
        program_id,
        status
      )
      VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare account insert.');
    }

    $acc_type = 'user';   // system default
    $approved = 1;        // admin-created = approved

    $stmt->bind_param(
        "sssissis",
        $fullname,
        $email,
        $acc_type,
        $approved,
        $role,
        $campus_id,
        $program_id,
        $status
    );

    if (!$stmt->execute()) {
        throw new RuntimeException('Failed to insert account.');
    }

    $newAccountId = (int) $stmt->insert_id;
    $stmt->close();

    if ($newAccountId <= 0) {
        throw new RuntimeException('Invalid created account ID.');
    }

    if ($role === 'progchair' && !empty($programIds)) {
        $assignmentStmt = $conn->prepare("
            INSERT INTO tbl_account_program_assignments (accountid, program_id, status)
            VALUES (?, ?, 'active')
            ON DUPLICATE KEY UPDATE status = 'active'
        ");
        if (!$assignmentStmt) {
            throw new RuntimeException('Unable to prepare assignment insert.');
        }

        foreach ($programIds as $assignedProgramId) {
            $assignedProgramId = (int) $assignedProgramId;
            $assignmentStmt->bind_param('ii', $newAccountId, $assignedProgramId);
            if (!$assignmentStmt->execute()) {
                $assignmentStmt->close();
                throw new RuntimeException('Failed to save program assignments.');
            }
        }
        $assignmentStmt->close();
    }

    $conn->commit();
    $_SESSION['success'] = 'Account successfully created.';
} catch (Throwable $e) {
    $conn->rollback();
    $_SESSION['error'] = 'Failed to save account.';
}

$conn->close();

/* =======================
   Redirect back
======================= */
header('Location: index.php');
exit;
