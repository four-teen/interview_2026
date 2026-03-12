<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/program_assignments.php';
require_once __DIR__ . '/program_ranking_lock.php';
require_once __DIR__ . '/student_preregistration.php';
require_once __DIR__ . '/student_credentials.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: '/interview');
}

if (!function_exists('admin_student_management_default_return_url')) {
    function admin_student_management_default_return_url(): string
    {
        return rtrim(BASE_URL, '/') . '/administrator/index.php';
    }
}

if (!function_exists('admin_student_management_normalize_return_url')) {
    function admin_student_management_normalize_return_url(string $returnTo, string $default = ''): string
    {
        $fallback = $default !== '' ? $default : admin_student_management_default_return_url();
        $returnTo = trim($returnTo);
        if ($returnTo === '') {
            return $fallback;
        }

        $parsed = parse_url($returnTo);
        if ($parsed === false || isset($parsed['scheme']) || isset($parsed['host'])) {
            return $fallback;
        }

        $path = trim((string) ($parsed['path'] ?? ''));
        if ($path === '') {
            return $fallback;
        }

        $allowedPrefix = rtrim(BASE_URL, '/') . '/administrator/';
        if (strpos($path, $allowedPrefix) !== 0) {
            return $fallback;
        }

        $query = isset($parsed['query']) && $parsed['query'] !== ''
            ? ('?' . $parsed['query'])
            : '';

        return $path . $query;
    }
}

if (!function_exists('admin_student_management_get_transfer_csrf')) {
    function admin_student_management_get_transfer_csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['admin_student_transfer_csrf'])) {
            try {
                $_SESSION['admin_student_transfer_csrf'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['admin_student_transfer_csrf'] = sha1(uniqid('admin_student_transfer_csrf_', true));
            }
        }

        return (string) $_SESSION['admin_student_transfer_csrf'];
    }
}

if (!function_exists('admin_student_management_verify_transfer_csrf')) {
    function admin_student_management_verify_transfer_csrf(string $postedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = (string) ($_SESSION['admin_student_transfer_csrf'] ?? '');
        return $postedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $postedToken);
    }
}

if (!function_exists('admin_student_management_set_transfer_flash')) {
    function admin_student_management_set_transfer_flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['admin_student_transfer_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('admin_student_management_pop_transfer_flash')) {
    function admin_student_management_pop_transfer_flash(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $flash = $_SESSION['admin_student_transfer_flash'] ?? null;
        unset($_SESSION['admin_student_transfer_flash']);

        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('admin_student_management_ensure_transfer_history_table')) {
    function admin_student_management_ensure_transfer_history_table(mysqli $conn): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_student_transfer_history (
                transfer_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                interview_id INT(10) UNSIGNED NOT NULL,
                from_program_id INT(10) UNSIGNED NOT NULL,
                to_program_id INT(10) UNSIGNED NOT NULL,
                transferred_by INT(10) UNSIGNED NOT NULL,
                transfer_datetime DATETIME DEFAULT CURRENT_TIMESTAMP,
                remarks TEXT,
                status ENUM('pending', 'approved', 'rejected') NOT NULL DEFAULT 'pending',
                approved_by INT(10) UNSIGNED DEFAULT NULL,
                approved_datetime DATETIME DEFAULT NULL,
                PRIMARY KEY (transfer_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        return (bool) $conn->query($sql);
    }
}

if (!function_exists('admin_student_management_format_program_label')) {
    function admin_student_management_format_program_label(array $row): string
    {
        $programCode = trim((string) ($row['program_code'] ?? ''));
        $programName = trim((string) ($row['program_name'] ?? ''));
        $major = trim((string) ($row['major'] ?? ''));

        $label = $programName;
        if ($major !== '') {
            $label .= ' - ' . $major;
        }

        if ($programCode !== '') {
            return $programCode . ($label !== '' ? ' - ' . $label : '');
        }

        return $label !== '' ? $label : ('Program #' . (int) ($row['program_id'] ?? 0));
    }
}

if (!function_exists('admin_student_management_fetch_program_owner_map')) {
    function admin_student_management_fetch_program_owner_map(mysqli $conn): array
    {
        $ownerMap = [];
        ensure_account_program_assignments_table($conn);

        $assignmentSql = "
            SELECT
                apa.program_id,
                a.accountid,
                a.acc_fullname
            FROM tbl_account_program_assignments apa
            INNER JOIN tblaccount a
                ON a.accountid = apa.accountid
            WHERE apa.status = 'active'
              AND a.status = 'active'
              AND a.role = 'progchair'
            ORDER BY apa.program_id ASC, apa.assignment_id ASC, a.acc_fullname ASC
        ";
        $assignmentResult = $conn->query($assignmentSql);
        if ($assignmentResult) {
            while ($row = $assignmentResult->fetch_assoc()) {
                $programId = (int) ($row['program_id'] ?? 0);
                if ($programId <= 0 || isset($ownerMap[$programId])) {
                    continue;
                }

                $ownerMap[$programId] = [
                    'accountid' => (int) ($row['accountid'] ?? 0),
                    'fullname' => trim((string) ($row['acc_fullname'] ?? '')),
                    'source' => 'assignment',
                ];
            }
            $assignmentResult->free();
        }

        $fallbackSql = "
            SELECT
                a.program_id,
                a.accountid,
                a.acc_fullname
            FROM tblaccount a
            WHERE a.status = 'active'
              AND a.role = 'progchair'
              AND a.program_id IS NOT NULL
              AND a.program_id > 0
            ORDER BY a.program_id ASC, a.acc_fullname ASC
        ";
        $fallbackResult = $conn->query($fallbackSql);
        if ($fallbackResult) {
            while ($row = $fallbackResult->fetch_assoc()) {
                $programId = (int) ($row['program_id'] ?? 0);
                if ($programId <= 0 || isset($ownerMap[$programId])) {
                    continue;
                }

                $ownerMap[$programId] = [
                    'accountid' => (int) ($row['accountid'] ?? 0),
                    'fullname' => trim((string) ($row['acc_fullname'] ?? '')),
                    'source' => 'fallback',
                ];
            }
            $fallbackResult->free();
        }

        return $ownerMap;
    }
}

if (!function_exists('admin_student_management_fetch_program_options')) {
    function admin_student_management_fetch_program_options(mysqli $conn, int $excludeProgramId = 0): array
    {
        $options = [];
        $ownerMap = admin_student_management_fetch_program_owner_map($conn);

        $sql = "
            SELECT
                p.program_id,
                p.program_code,
                p.program_name,
                p.major,
                c.college_name,
                cam.campus_id,
                cam.campus_name,
                cutoff.cutoff_score,
                cutoff.absorptive_capacity
            FROM tbl_program p
            INNER JOIN tbl_college c
                ON c.college_id = p.college_id
            INNER JOIN tbl_campus cam
                ON cam.campus_id = c.campus_id
            LEFT JOIN (
                SELECT pc1.program_id, pc1.cutoff_score, pc1.absorptive_capacity
                FROM tbl_program_cutoff pc1
                INNER JOIN (
                    SELECT program_id, MAX(cutoff_id) AS latest_cutoff_id
                    FROM tbl_program_cutoff
                    GROUP BY program_id
                ) latest
                    ON latest.latest_cutoff_id = pc1.cutoff_id
            ) cutoff
                ON cutoff.program_id = p.program_id
            WHERE p.status = 'active'
            ORDER BY cam.campus_name ASC, c.college_name ASC, p.program_name ASC, p.major ASC
        ";

        $result = $conn->query($sql);
        if (!$result) {
            return $options;
        }

        while ($row = $result->fetch_assoc()) {
            $programId = (int) ($row['program_id'] ?? 0);
            if ($programId <= 0 || $programId === $excludeProgramId) {
                continue;
            }

            $owner = $ownerMap[$programId] ?? [
                'accountid' => 0,
                'fullname' => '',
                'source' => '',
            ];

            $row['program_label'] = admin_student_management_format_program_label($row);
            $row['owner_accountid'] = (int) ($owner['accountid'] ?? 0);
            $row['owner_fullname'] = (string) ($owner['fullname'] ?? '');
            $row['owner_source'] = (string) ($owner['source'] ?? '');
            $options[] = $row;
        }
        $result->free();

        return $options;
    }
}

if (!function_exists('admin_student_management_build_choice_label')) {
    function admin_student_management_build_choice_label(array $row, string $prefix): string
    {
        $code = trim((string) ($row[$prefix . '_choice_code'] ?? ''));
        $name = trim((string) ($row[$prefix . '_choice_name'] ?? ''));
        $major = trim((string) ($row[$prefix . '_choice_major'] ?? ''));

        $label = $name;
        if ($major !== '') {
            $label .= ' - ' . $major;
        }
        if ($code !== '') {
            $label = $code . ($label !== '' ? ' - ' . $label : '');
        }

        return $label !== '' ? $label : 'N/A';
    }
}

if (!function_exists('admin_student_management_fetch_student_record')) {
    function admin_student_management_fetch_student_record(mysqli $conn, array $criteria): ?array
    {
        $placementResultId = max(0, (int) ($criteria['placement_result_id'] ?? 0));
        $interviewId = max(0, (int) ($criteria['interview_id'] ?? 0));
        $examineeNumber = trim((string) ($criteria['examinee_number'] ?? ''));

        $whereSql = '';
        $types = '';
        $params = [];

        if ($placementResultId > 0) {
            $whereSql = 'pr.id = ?';
            $types = 'i';
            $params[] = $placementResultId;
        } elseif ($interviewId > 0) {
            $whereSql = 'si.interview_id = ?';
            $types = 'i';
            $params[] = $interviewId;
        } elseif ($examineeNumber !== '') {
            $whereSql = 'pr.examinee_number = ?';
            $types = 's';
            $params[] = $examineeNumber;
        } else {
            return null;
        }

        ensure_student_credentials_table($conn);
        admin_student_management_ensure_transfer_history_table($conn);

        $sql = "
            SELECT
                pr.id AS placement_result_id,
                pr.examinee_number,
                pr.full_name,
                pr.sat_score,
                pr.qualitative_text,
                pr.preferred_program,
                pr.overall_standard_score,
                pr.esm_competency_standard_score,
                si.interview_id,
                si.status AS interview_status,
                si.classification,
                si.mobile_number,
                si.interview_datetime,
                si.final_score,
                si.program_chair_id,
                si.campus_id,
                si.program_id,
                si.first_choice,
                si.second_choice,
                si.third_choice,
                si.shs_track_id,
                si.etg_class_id,
                current_program.program_code,
                current_program.program_name,
                current_program.major,
                owner.acc_fullname AS owner_fullname,
                owner.email AS owner_email,
                camp.campus_name,
                track.track AS shs_track_name,
                etg.class_desc AS etg_class_name,
                p1.program_code AS first_choice_code,
                p1.program_name AS first_choice_name,
                p1.major AS first_choice_major,
                p2.program_code AS second_choice_code,
                p2.program_name AS second_choice_name,
                p2.major AS second_choice_major,
                p3.program_code AS third_choice_code,
                p3.program_name AS third_choice_name,
                p3.major AS third_choice_major,
                sc.credential_id,
                sc.status AS credential_status,
                sc.must_change_password,
                sc.active_email,
                sp.profile_id,
                sp.profile_completion_percent,
                COALESCE(transfer_stats.pending_transfer_count, 0) AS pending_transfer_count
            FROM tbl_placement_results pr
            LEFT JOIN (
                SELECT si_active.*
                FROM tbl_student_interview si_active
                INNER JOIN (
                    SELECT placement_result_id, MAX(interview_id) AS latest_interview_id
                    FROM tbl_student_interview
                    WHERE status = 'active'
                    GROUP BY placement_result_id
                ) latest
                    ON latest.latest_interview_id = si_active.interview_id
            ) si
                ON si.placement_result_id = pr.id
            LEFT JOIN tbl_program current_program
                ON current_program.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
            LEFT JOIN tblaccount owner
                ON owner.accountid = si.program_chair_id
            LEFT JOIN tbl_campus camp
                ON camp.campus_id = si.campus_id
            LEFT JOIN tb_ltrack track
                ON track.trackid = si.shs_track_id
            LEFT JOIN tbl_etg_class etg
                ON etg.etgclassid = si.etg_class_id
            LEFT JOIN tbl_program p1
                ON p1.program_id = si.first_choice
            LEFT JOIN tbl_program p2
                ON p2.program_id = si.second_choice
            LEFT JOIN tbl_program p3
                ON p3.program_id = si.third_choice
            LEFT JOIN tbl_student_credentials sc
                ON sc.placement_result_id = pr.id
            LEFT JOIN tbl_student_profile sp
                ON sp.credential_id = sc.credential_id
                OR sp.examinee_number = pr.examinee_number
            LEFT JOIN (
                SELECT interview_id, COUNT(*) AS pending_transfer_count
                FROM tbl_student_transfer_history
                WHERE status = 'pending'
                GROUP BY interview_id
            ) transfer_stats
                ON transfer_stats.interview_id = si.interview_id
            WHERE {$whereSql}
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $row['current_program_id'] = (int) ($row['program_id'] ?? ($row['first_choice'] ?? 0));
        $row['current_program_label'] = admin_student_management_format_program_label($row);
        $row['first_choice_label'] = admin_student_management_build_choice_label($row, 'first');
        $row['second_choice_label'] = admin_student_management_build_choice_label($row, 'second');
        $row['third_choice_label'] = admin_student_management_build_choice_label($row, 'third');
        $row['profile_completion_percent'] = $row['profile_completion_percent'] !== null
            ? (float) $row['profile_completion_percent']
            : null;
        $row['credential_id'] = (int) ($row['credential_id'] ?? 0);
        $row['interview_id'] = (int) ($row['interview_id'] ?? 0);
        $row['placement_result_id'] = (int) ($row['placement_result_id'] ?? 0);
        $row['program_chair_id'] = (int) ($row['program_chair_id'] ?? 0);
        $row['pending_transfer_count'] = (int) ($row['pending_transfer_count'] ?? 0);
        $row['rank_display'] = 'N/A';
        $row['rank_note'] = 'No ranking validation';
        $row['rank_badge_class'] = 'bg-label-secondary';

        $currentProgramId = (int) ($row['current_program_id'] ?? 0);
        $currentInterviewId = (int) ($row['interview_id'] ?? 0);
        if ($currentProgramId > 0 && $currentInterviewId > 0 && $row['final_score'] !== null) {
            $payload = program_ranking_fetch_payload($conn, $currentProgramId, null);
            if ($payload['success'] ?? false) {
                foreach ((array) ($payload['rows'] ?? []) as $rankingRow) {
                    if ((int) ($rankingRow['interview_id'] ?? 0) !== $currentInterviewId) {
                        continue;
                    }

                    $resolvedRank = max(
                        (int) ($rankingRow['locked_rank'] ?? 0),
                        (int) ($rankingRow['rank'] ?? 0)
                    );
                    if ($resolvedRank > 0) {
                        $row['rank_display'] = '#' . number_format($resolvedRank);
                    }

                    if (!empty($rankingRow['is_locked'])) {
                        $row['rank_badge_class'] = 'bg-label-warning';
                        $row['rank_note'] = 'Locked shared rank';
                    } elseif (!empty($rankingRow['is_outside_capacity'])) {
                        $row['rank_badge_class'] = 'bg-label-danger';
                        $row['rank_note'] = 'Shared rank outside capacity';
                    } elseif (!empty($rankingRow['is_endorsement']) || (string) ($rankingRow['row_section'] ?? '') === 'scc') {
                        $row['rank_badge_class'] = 'bg-label-success';
                        $row['rank_note'] = 'SCC shared rank';
                    } elseif ((string) ($rankingRow['row_section'] ?? '') === 'etg') {
                        $row['rank_badge_class'] = 'bg-label-info';
                        $row['rank_note'] = 'ETG shared rank';
                    } else {
                        $row['rank_badge_class'] = 'bg-label-primary';
                        $row['rank_note'] = 'Shared academic rank';
                    }
                    break;
                }
            } else {
                $row['rank_note'] = 'Validation unavailable';
            }
        } elseif ($currentInterviewId > 0 && $row['final_score'] === null) {
            $row['rank_note'] = 'Unscored';
        }

        return $row;
    }
}

if (!function_exists('admin_student_management_execute_direct_transfer')) {
    function admin_student_management_execute_direct_transfer(mysqli $conn, array $input): array
    {
        $adminAccountId = max(0, (int) ($input['admin_account_id'] ?? 0));
        $placementResultId = max(0, (int) ($input['placement_result_id'] ?? 0));
        $interviewId = max(0, (int) ($input['interview_id'] ?? 0));
        $targetProgramId = max(0, (int) ($input['to_program_id'] ?? 0));
        $remarks = trim((string) ($input['remarks'] ?? ''));

        if ($adminAccountId <= 0 || $placementResultId <= 0 || $interviewId <= 0 || $targetProgramId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid transfer request.',
            ];
        }

        if (!admin_student_management_ensure_transfer_history_table($conn)) {
            return [
                'success' => false,
                'message' => 'Transfer history storage is not ready.',
            ];
        }

        $student = admin_student_management_fetch_student_record($conn, [
            'placement_result_id' => $placementResultId,
        ]);

        if (!$student || (int) ($student['interview_id'] ?? 0) !== $interviewId) {
            return [
                'success' => false,
                'message' => 'Student interview record not found.',
            ];
        }

        if ((string) ($student['interview_status'] ?? '') !== 'active') {
            return [
                'success' => false,
                'message' => 'Only active interview records can be transferred.',
            ];
        }

        if (program_ranking_is_interview_locked($conn, $interviewId)) {
            return [
                'success' => false,
                'message' => 'This interview rank is locked and cannot be transferred.',
            ];
        }

        if (student_preregistration_has_submitted_interview($conn, $interviewId) === true) {
            return [
                'success' => false,
                'message' => 'This student already submitted pre-registration and cannot be transferred.',
            ];
        }

        $fromProgramId = (int) ($student['current_program_id'] ?? 0);
        if ($fromProgramId <= 0) {
            return [
                'success' => false,
                'message' => 'Current program assignment is missing.',
            ];
        }

        if ($fromProgramId === $targetProgramId) {
            return [
                'success' => false,
                'message' => 'Target program is the same as the current program.',
            ];
        }

        $programOptions = admin_student_management_fetch_program_options($conn);
        $targetProgram = null;
        foreach ($programOptions as $programOption) {
            if ((int) ($programOption['program_id'] ?? 0) === $targetProgramId) {
                $targetProgram = $programOption;
                break;
            }
        }

        if (!$targetProgram) {
            return [
                'success' => false,
                'message' => 'Selected destination program was not found.',
            ];
        }

        $targetProgramChairId = max(0, (int) ($targetProgram['owner_accountid'] ?? 0));
        $targetCampusId = (int) ($targetProgram['campus_id'] ?? 0);
        if ($targetCampusId <= 0) {
            return [
                'success' => false,
                'message' => 'Destination program campus is invalid.',
            ];
        }

        $transferRemarks = $remarks !== ''
            ? ('ADMIN DIRECT TRANSFER: ' . $remarks)
            : 'ADMIN DIRECT TRANSFER';

        $conn->begin_transaction();
        try {
            $resolvePendingSql = "
                UPDATE tbl_student_transfer_history
                SET status = 'rejected',
                    approved_by = ?,
                    approved_datetime = NOW()
                WHERE interview_id = ?
                  AND status = 'pending'
            ";
            $resolvePendingStmt = $conn->prepare($resolvePendingSql);
            if (!$resolvePendingStmt) {
                throw new RuntimeException('Failed to prepare pending transfer cleanup.');
            }
            $resolvePendingStmt->bind_param(
                'ii',
                $adminAccountId,
                $interviewId
            );
            if (!$resolvePendingStmt->execute()) {
                $resolvePendingStmt->close();
                throw new RuntimeException('Failed to clear existing pending transfers.');
            }
            $resolvePendingStmt->close();

            $updateInterviewSql = "
                UPDATE tbl_student_interview
                SET first_choice = ?,
                    program_id = ?,
                    campus_id = ?,
                    program_chair_id = ?
                WHERE interview_id = ?
                LIMIT 1
            ";
            $updateInterviewStmt = $conn->prepare($updateInterviewSql);
            if (!$updateInterviewStmt) {
                throw new RuntimeException('Failed to prepare interview transfer update.');
            }
            $updateInterviewStmt->bind_param(
                'iiiii',
                $targetProgramId,
                $targetProgramId,
                $targetCampusId,
                $targetProgramChairId,
                $interviewId
            );
            if (!$updateInterviewStmt->execute()) {
                $updateInterviewStmt->close();
                throw new RuntimeException('Failed to update interview program assignment.');
            }
            $updateInterviewStmt->close();

            $insertTransferSql = "
                INSERT INTO tbl_student_transfer_history (
                    interview_id,
                    from_program_id,
                    to_program_id,
                    transferred_by,
                    transfer_datetime,
                    remarks,
                    status,
                    approved_by,
                    approved_datetime
                ) VALUES (?, ?, ?, ?, NOW(), ?, 'approved', ?, NOW())
            ";
            $insertTransferStmt = $conn->prepare($insertTransferSql);
            if (!$insertTransferStmt) {
                throw new RuntimeException('Failed to prepare transfer history insert.');
            }
            $insertTransferStmt->bind_param(
                'iiiisi',
                $interviewId,
                $fromProgramId,
                $targetProgramId,
                $adminAccountId,
                $transferRemarks,
                $adminAccountId
            );
            if (!$insertTransferStmt->execute()) {
                $insertTransferStmt->close();
                throw new RuntimeException('Failed to write transfer history.');
            }
            $insertTransferStmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $message = 'Student transferred successfully to ' . (string) ($targetProgram['program_label'] ?? 'the selected program') . '.';
        if ($targetProgramChairId <= 0) {
            $message .= ' No active program chair is currently assigned, so the record is now temporarily unassigned.';
        }

        return [
            'success' => true,
            'message' => $message,
            'target_program_label' => (string) ($targetProgram['program_label'] ?? ''),
        ];
    }
}
