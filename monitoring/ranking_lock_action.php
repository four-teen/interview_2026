<?php
/**
 * Monitoring-only rank lock actions.
 */

require_once '../config/db.php';
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

    $unlockedCount = 0;
    if (!empty($interviewIds)) {
        $placeholders = implode(',', array_fill(0, count($interviewIds), '?'));
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

        $params = array_merge([$programId], array_values($interviewIds));
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $unlockedCount = (int) $stmt->affected_rows;
        $stmt->close();
    }

    $updated = monitoring_program_ranking_fetch_payload($conn, $programId);
    $updatedLocks = is_array($updated['locks'] ?? null) ? $updated['locks'] : ['active_count' => 0, 'ranges' => []];

    echo json_encode([
        'success' => true,
        'message' => 'Lock range removed.',
        'unlocked_count' => $unlockedCount,
        'locks' => $updatedLocks
    ]);
    exit;
}

if ($action === 'unlock_all') {
    $stmt = $conn->prepare("DELETE FROM tbl_program_ranking_locks WHERE program_id = ?");
    if (!$stmt) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Server error (unlock all).'
        ]);
        exit;
    }
    $stmt->bind_param("i", $programId);
    $stmt->execute();
    $unlockedCount = (int) $stmt->affected_rows;
    $stmt->close();

    echo json_encode([
        'success' => true,
        'message' => 'All locks removed.',
        'unlocked_count' => $unlockedCount,
        'locks' => ['active_count' => 0, 'max_locked_rank' => 0, 'ranges' => []]
    ]);
    exit;
}

echo json_encode([
    'success' => false,
    'message' => 'Invalid action.'
]);
exit;

