<?php
/**
 * Monitoring-only rank lock actions.
 */

require_once '../config/db.php';
require_once '../config/student_preregistration.php';
require_once 'program_ranking_monitoring_helper.php';
session_start();

header('Content-Type: application/json; charset=utf-8');

if (
    !isset($_SESSION['logged_in']) ||
    (($_SESSION['role'] ?? '') !== 'monitoring') ||
    empty($_SESSION['accountid'])
) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Unauthorized.'
    ]);
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$action = strtolower(trim((string) ($_POST['action'] ?? '')));
$programId = isset($_POST['program_id']) ? (int) $_POST['program_id'] : 0;
$startRank = isset($_POST['start_rank']) ? (int) $_POST['start_rank'] : 0;
$endRank = isset($_POST['end_rank']) ? (int) $_POST['end_rank'] : 0;

if ($programId <= 0) {
    echo json_encode([
        'success' => false,
        'message' => 'Invalid program.'
    ]);
    exit;
}

ensure_program_ranking_locks_table($conn);

if (!function_exists('monitoring_ranking_normalize_interview_ids')) {
    function monitoring_ranking_normalize_interview_ids(array $interviewIds): array
    {
        $normalizedIds = [];
        foreach ($interviewIds as $interviewId) {
            $normalizedId = (int) $interviewId;
            if ($normalizedId > 0) {
                $normalizedIds[$normalizedId] = $normalizedId;
            }
        }

        return array_values($normalizedIds);
    }
}

if (!function_exists('monitoring_ranking_split_unlockable_interview_ids')) {
    function monitoring_ranking_split_unlockable_interview_ids(mysqli $conn, array $interviewIds): ?array
    {
        $candidateIds = monitoring_ranking_normalize_interview_ids($interviewIds);
        if (empty($candidateIds)) {
            return [
                'unlockable_ids' => [],
                'blocked_ids' => [],
            ];
        }

        $submittedInterviewIds = student_preregistration_fetch_submitted_interview_ids($conn, $candidateIds);
        if ($submittedInterviewIds === null) {
            return null;
        }

        $unlockableIds = [];
        $blockedIds = [];
        foreach ($candidateIds as $candidateId) {
            if (isset($submittedInterviewIds[$candidateId])) {
                $blockedIds[] = $candidateId;
                continue;
            }

            $unlockableIds[] = $candidateId;
        }

        return [
            'unlockable_ids' => $unlockableIds,
            'blocked_ids' => $blockedIds,
        ];
    }
}

if (!function_exists('monitoring_ranking_fetch_locked_interview_ids')) {
    function monitoring_ranking_fetch_locked_interview_ids(mysqli $conn, int $programId): ?array
    {
        if ($programId <= 0) {
            return [];
        }

        $stmt = $conn->prepare("
            SELECT interview_id
            FROM tbl_program_ranking_locks
            WHERE program_id = ?
        ");
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param("i", $programId);
        $stmt->execute();

        $lockedInterviewIds = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $interviewId = (int) ($row['interview_id'] ?? 0);
            if ($interviewId > 0) {
                $lockedInterviewIds[$interviewId] = $interviewId;
            }
        }
        $stmt->close();

        return array_values($lockedInterviewIds);
    }
}

if ($action === 'lock_range') {
    if ($startRank <= 0 || $endRank <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Start number and end number are required.'
        ]);
        exit;
    }

    $fromRank = min($startRank, $endRank);
    $toRank = max($startRank, $endRank);

    $payload = monitoring_program_ranking_fetch_payload($conn, $programId);
    if (!($payload['success'] ?? false)) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($payload['message'] ?? 'Failed to load ranking.')
        ]);
        exit;
    }

    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $rowsBySequence = [];
    foreach ($rows as $row) {
        $sequenceNo = (int) ($row['sequence_no'] ?? 0);
        if ($sequenceNo > 0) {
            $rowsBySequence[$sequenceNo] = $row;
        }
    }

    $insertSql = "
        INSERT INTO tbl_program_ranking_locks (
            program_id,
            interview_id,
            locked_rank,
            locked_by,
            snapshot_examinee_number,
            snapshot_full_name,
            snapshot_classification,
            snapshot_sat_score,
            snapshot_final_score,
            snapshot_is_endorsement,
            snapshot_endorsement_order,
            snapshot_interview_datetime,
            snapshot_encoded_by,
            snapshot_section,
            snapshot_outside_capacity
        ) VALUES (
            ?, ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?, ?,
            ?, ?
        )
    ";
    $stmtInsert = $conn->prepare($insertSql);
    if (!$stmtInsert) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error (lock insert).'
        ]);
        exit;
    }

    $lockedCount = 0;
    $alreadyLocked = 0;
    $missingRows = 0;

    $conn->begin_transaction();
    try {
        for ($sequenceNo = $fromRank; $sequenceNo <= $toRank; $sequenceNo++) {
            if (!isset($rowsBySequence[$sequenceNo])) {
                $missingRows++;
                continue;
            }

            $row = $rowsBySequence[$sequenceNo];
            if ((bool) ($row['is_locked'] ?? false)) {
                $alreadyLocked++;
                continue;
            }

            $interviewId = (int) ($row['interview_id'] ?? 0);
            $actualRank = (int) ($row['rank'] ?? 0);
            if ($interviewId <= 0 || $actualRank <= 0) {
                $missingRows++;
                continue;
            }

            $examineeNumber = (string) ($row['examinee_number'] ?? '');
            $fullName = (string) ($row['full_name'] ?? '');
            $classification = (string) ($row['classification'] ?? 'REGULAR');
            $satScore = (int) ($row['sat_score'] ?? 0);
            $finalScore = round((float) ($row['final_score'] ?? 0), 2);
            $isEndorsement = ((bool) ($row['is_endorsement'] ?? false)) ? 1 : 0;
            $endorsementOrder = (string) ($row['endorsement_order'] ?? '');
            $interviewDatetime = (string) ($row['interview_datetime'] ?? '');
            $encodedBy = (string) ($row['encoded_by'] ?? '');
            $section = program_ranking_normalize_section((string) ($row['row_section'] ?? 'regular'));
            $outsideCapacity = ((bool) ($row['is_outside_capacity'] ?? false)) ? 1 : 0;

            $stmtInsert->bind_param(
                "iiiisssidissssi",
                $programId,
                $interviewId,
                $actualRank,
                $accountId,
                $examineeNumber,
                $fullName,
                $classification,
                $satScore,
                $finalScore,
                $isEndorsement,
                $endorsementOrder,
                $interviewDatetime,
                $encodedBy,
                $section,
                $outsideCapacity
            );

            if (!$stmtInsert->execute()) {
                if ((int) ($stmtInsert->errno ?? 0) === 1062) {
                    $alreadyLocked++;
                    continue;
                }
                throw new RuntimeException('Lock insert failed.');
            }

            $lockedCount++;
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        $stmtInsert->close();
        echo json_encode([
            'success' => false,
            'message' => 'Failed to lock rank range.'
        ]);
        exit;
    }

    $stmtInsert->close();

    $updated = monitoring_program_ranking_fetch_payload($conn, $programId);
    $updatedLocks = is_array($updated['locks'] ?? null) ? $updated['locks'] : ['active_count' => 0, 'ranges' => []];

    echo json_encode([
        'success' => true,
        'message' => 'Lock applied.',
        'locked_count' => $lockedCount,
        'already_locked' => $alreadyLocked,
        'missing_rows' => $missingRows,
        'locks' => $updatedLocks
    ]);
    exit;
}

if ($action === 'unlock_range') {
    if ($startRank <= 0 || $endRank <= 0) {
        echo json_encode([
            'success' => false,
            'message' => 'Start number and end number are required.'
        ]);
        exit;
    }

    $fromRank = min($startRank, $endRank);
    $toRank = max($startRank, $endRank);

    $payload = monitoring_program_ranking_fetch_payload($conn, $programId);
    if (!($payload['success'] ?? false)) {
        echo json_encode([
            'success' => false,
            'message' => (string) ($payload['message'] ?? 'Failed to load ranking.')
        ]);
        exit;
    }

    $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
    $interviewIds = [];
    foreach ($rows as $row) {
        $sequenceNo = (int) ($row['sequence_no'] ?? 0);
        if ($sequenceNo < $fromRank || $sequenceNo > $toRank) {
            continue;
        }
        if (!((bool) ($row['is_locked'] ?? false))) {
            continue;
        }
        $interviewId = (int) ($row['interview_id'] ?? 0);
        if ($interviewId > 0) {
            $interviewIds[$interviewId] = $interviewId;
        }
    }

    $unlockState = monitoring_ranking_split_unlockable_interview_ids($conn, array_values($interviewIds));
    if ($unlockState === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to verify pre-registration status before unlocking.'
        ]);
        exit;
    }

    $unlockableInterviewIds = $unlockState['unlockable_ids'];
    $blockedInterviewIds = $unlockState['blocked_ids'];
    $blockedCount = count($blockedInterviewIds);
    $unlockedCount = 0;

    if (!empty($unlockableInterviewIds)) {
        $placeholders = implode(',', array_fill(0, count($unlockableInterviewIds), '?'));
        $sql = "
            DELETE FROM tbl_program_ranking_locks
            WHERE program_id = ?
              AND interview_id IN ({$placeholders})
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Server error (unlock range).'
            ]);
            exit;
        }

        $params = array_merge([$programId], $unlockableInterviewIds);
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $unlockedCount = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $updated = monitoring_program_ranking_fetch_payload($conn, $programId);
    $updatedLocks = is_array($updated['locks'] ?? null) ? $updated['locks'] : ['active_count' => 0, 'ranges' => []];

    $success = !($unlockedCount === 0 && $blockedCount > 0);
    if ($unlockedCount > 0 && $blockedCount > 0) {
        $message = $unlockedCount . ' lock(s) removed. '
            . $blockedCount . ' lock(s) kept because pre-registration is already submitted.';
    } elseif ($blockedCount > 0) {
        $message = $blockedCount === 1
            ? 'This locked rank cannot be unlocked because the student already submitted pre-registration.'
            : $blockedCount . ' locked rank(s) cannot be unlocked because the students already submitted pre-registration.';
    } elseif ($unlockedCount > 0) {
        $message = 'Lock range removed.';
    } else {
        $message = 'No locked ranks found in the selected range.';
    }

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'unlocked_count' => $unlockedCount,
        'blocked_count' => $blockedCount,
        'locks' => $updatedLocks
    ]);
    exit;
}

if ($action === 'unlock_all') {
    $lockedInterviewIds = monitoring_ranking_fetch_locked_interview_ids($conn, $programId);
    if ($lockedInterviewIds === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error (load locked ranks).'
        ]);
        exit;
    }

    $unlockState = monitoring_ranking_split_unlockable_interview_ids($conn, $lockedInterviewIds);
    if ($unlockState === null) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Failed to verify pre-registration status before unlocking.'
        ]);
        exit;
    }

    $unlockableInterviewIds = $unlockState['unlockable_ids'];
    $blockedInterviewIds = $unlockState['blocked_ids'];
    $blockedCount = count($blockedInterviewIds);
    $unlockedCount = 0;

    if (!empty($unlockableInterviewIds)) {
        $placeholders = implode(',', array_fill(0, count($unlockableInterviewIds), '?'));
        $sql = "
            DELETE FROM tbl_program_ranking_locks
            WHERE program_id = ?
              AND interview_id IN ({$placeholders})
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Server error (unlock all).'
            ]);
            exit;
        }

        $params = array_merge([$programId], $unlockableInterviewIds);
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $unlockedCount = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $updated = monitoring_program_ranking_fetch_payload($conn, $programId);
    $updatedLocks = is_array($updated['locks'] ?? null) ? $updated['locks'] : ['active_count' => 0, 'ranges' => []];

    $success = !($unlockedCount === 0 && $blockedCount > 0);
    if ($unlockedCount > 0 && $blockedCount > 0) {
        $message = $unlockedCount . ' lock(s) removed. '
            . $blockedCount . ' lock(s) kept because pre-registration is already submitted.';
    } elseif ($blockedCount > 0) {
        $message = $blockedCount === 1
            ? 'This locked rank cannot be unlocked because the student already submitted pre-registration.'
            : $blockedCount . ' locked rank(s) cannot be unlocked because the students already submitted pre-registration.';
    } elseif ($unlockedCount > 0) {
        $message = 'All locks removed.';
    } else {
        $message = 'No locked ranks found.';
    }

    echo json_encode([
        'success' => $success,
        'message' => $message,
        'unlocked_count' => $unlockedCount,
        'blocked_count' => $blockedCount,
        'locks' => $updatedLocks
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Invalid action.'
]);
exit;

