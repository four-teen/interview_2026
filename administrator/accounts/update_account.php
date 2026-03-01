<?php
require_once '../../config/db.php';
require_once '../../config/program_assignments.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized access'
    ]);
    exit;
}

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

$accountid   = $_POST['accountid']   ?? null;
$fullname    = trim($_POST['acc_fullname'] ?? '');
$email       = trim($_POST['email'] ?? '');
$role        = $_POST['role'] ?? '';
$campus_id   = $_POST['campus_id'] ?: null;
$status      = $_POST['status'] ?? '';
$programIds  = normalize_posted_program_ids($_POST['program_ids'] ?? []);

if (empty($programIds)) {
    $legacyProgramId = (int) ($_POST['program_id'] ?? 0);
    if ($legacyProgramId > 0) {
        $programIds = [$legacyProgramId];
    }
}

$program_id = !empty($programIds) ? (int) $programIds[0] : null;

if (!$accountid || !$fullname || !$email || !$role || !$status) {
    echo json_encode([
        'success' => false,
        'message' => 'Missing required fields'
    ]);
    exit;
}

if ($role === 'progchair' && empty($programIds)) {
    echo json_encode([
        'success' => false,
        'message' => 'Program Chair accounts must have at least one assigned program.'
    ]);
    exit;
}

/* Prevent duplicate email */
$check = $conn->prepare("
    SELECT accountid 
    FROM tblaccount 
    WHERE email = ? AND accountid != ?
    LIMIT 1
");
$check->bind_param('si', $email, $accountid);
$check->execute();
$check->store_result();

if ($check->num_rows > 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Email is already in use'
    ]);
    $check->close();
    exit;
}
$check->close();

try {
    $conn->begin_transaction();

    if (!ensure_account_program_assignments_table($conn)) {
        throw new RuntimeException('Unable to prepare program assignment storage.');
    }

    $stmt = $conn->prepare("
        UPDATE tblaccount
        SET
          acc_fullname = ?,
          email        = ?,
          role         = ?,
          campus_id    = ?,
          program_id   = ?,
          status       = ?
        WHERE accountid = ?
    ");
    if (!$stmt) {
        throw new RuntimeException('Unable to prepare account update.');
    }

    $stmt->bind_param(
        'sssissi',
        $fullname,
        $email,
        $role,
        $campus_id,
        $program_id,
        $status,
        $accountid
    );
    if (!$stmt->execute()) {
        $stmt->close();
        throw new RuntimeException('Failed to update account.');
    }
    $stmt->close();

    $deleteAssignments = $conn->prepare("
        DELETE FROM tbl_account_program_assignments
        WHERE accountid = ?
    ");
    if (!$deleteAssignments) {
        throw new RuntimeException('Unable to reset existing program assignments.');
    }
    $deleteAssignments->bind_param('i', $accountid);
    if (!$deleteAssignments->execute()) {
        $deleteAssignments->close();
        throw new RuntimeException('Failed to reset existing program assignments.');
    }
    $deleteAssignments->close();

    if ($role === 'progchair' && !empty($programIds)) {
        $assignmentStmt = $conn->prepare("
            INSERT INTO tbl_account_program_assignments (accountid, program_id, status)
            VALUES (?, ?, 'active')
            ON DUPLICATE KEY UPDATE status = 'active'
        ");
        if (!$assignmentStmt) {
            throw new RuntimeException('Unable to prepare assignment update.');
        }

        foreach ($programIds as $assignedProgramId) {
            $assignedProgramId = (int) $assignedProgramId;
            $assignmentStmt->bind_param('ii', $accountid, $assignedProgramId);
            if (!$assignmentStmt->execute()) {
                $assignmentStmt->close();
                throw new RuntimeException('Failed to update program assignments.');
            }
        }
        $assignmentStmt->close();
    }

    $conn->commit();
    echo json_encode([
        'success' => true,
        'message' => 'Account updated successfully'
    ]);
} catch (Throwable $e) {
    $conn->rollback();
    echo json_encode([
        'success' => false,
        'message' => 'Failed to update account'
    ]);
}
