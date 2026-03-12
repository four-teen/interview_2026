<?php

if (!function_exists('ensure_student_preregistration_storage')) {
    function ensure_student_preregistration_storage(mysqli $conn): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_student_preregistration (
                preregistration_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                credential_id INT(10) UNSIGNED NOT NULL,
                interview_id INT(10) UNSIGNED NOT NULL,
                examinee_number VARCHAR(50) NOT NULL,
                program_id INT(10) UNSIGNED NOT NULL,
                locked_rank INT(10) UNSIGNED DEFAULT NULL,
                profile_completion_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
                agreement_accepted TINYINT(1) NOT NULL DEFAULT 0,
                agreement_accepted_at DATETIME DEFAULT NULL,
                status ENUM('submitted') NOT NULL DEFAULT 'submitted',
                submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (preregistration_id),
                UNIQUE KEY uq_student_prereg_credential (credential_id),
                UNIQUE KEY uq_student_prereg_interview (interview_id),
                KEY idx_student_prereg_program (program_id),
                KEY idx_student_prereg_examinee (examinee_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        if (!$conn->query($sql)) {
            return false;
        }

        $columnsToEnsure = [
            'agreement_accepted' => "ALTER TABLE tbl_student_preregistration ADD COLUMN agreement_accepted TINYINT(1) NOT NULL DEFAULT 0 AFTER profile_completion_percent",
            'agreement_accepted_at' => "ALTER TABLE tbl_student_preregistration ADD COLUMN agreement_accepted_at DATETIME DEFAULT NULL AFTER agreement_accepted",
        ];

        foreach ($columnsToEnsure as $columnName => $alterSql) {
            $columnResult = $conn->query("SHOW COLUMNS FROM tbl_student_preregistration LIKE '" . $conn->real_escape_string($columnName) . "'");
            if (!$columnResult) {
                return false;
            }

            $hasColumn = ($columnResult->num_rows > 0);
            $columnResult->free();
            if ($hasColumn) {
                continue;
            }

            if (!$conn->query($alterSql)) {
                return false;
            }
        }

        return true;
    }
}

if (!function_exists('student_preregistration_format_program_label')) {
    function student_preregistration_format_program_label(array $row): string
    {
        $programCode = trim((string) ($row['program_code'] ?? ''));
        $programName = trim((string) ($row['program_name'] ?? ''));
        $major = trim((string) ($row['major'] ?? ''));

        $label = $programName;
        if ($major !== '') {
            $label .= ' - ' . $major;
        }

        if ($programCode !== '') {
            return $programCode . ' - ' . $label;
        }

        return $label !== '' ? $label : ('Program #' . (int) ($row['program_id'] ?? 0));
    }
}

if (!function_exists('student_preregistration_fetch_submitted_interview_ids')) {
    function student_preregistration_fetch_submitted_interview_ids(mysqli $conn, array $interviewIds): ?array
    {
        if (!ensure_student_preregistration_storage($conn)) {
            return null;
        }

        $normalizedIds = [];
        foreach ($interviewIds as $interviewId) {
            $normalizedId = (int) $interviewId;
            if ($normalizedId > 0) {
                $normalizedIds[$normalizedId] = $normalizedId;
            }
        }

        if (empty($normalizedIds)) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $sql = "
            SELECT interview_id
            FROM tbl_student_preregistration
            WHERE status = 'submitted'
              AND interview_id IN ({$placeholders})
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $params = array_values($normalizedIds);
        $types = str_repeat('i', count($params));
        $stmt->bind_param($types, ...$params);
        $stmt->execute();

        $submittedInterviewIds = [];
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $submittedInterviewId = (int) ($row['interview_id'] ?? 0);
            if ($submittedInterviewId > 0) {
                $submittedInterviewIds[$submittedInterviewId] = true;
            }
        }
        $stmt->close();

        return $submittedInterviewIds;
    }
}

if (!function_exists('student_preregistration_has_submitted_interview')) {
    function student_preregistration_has_submitted_interview(mysqli $conn, int $interviewId): ?bool
    {
        $normalizedInterviewId = (int) $interviewId;
        if ($normalizedInterviewId <= 0) {
            return false;
        }

        $submittedInterviewIds = student_preregistration_fetch_submitted_interview_ids($conn, [$normalizedInterviewId]);
        if ($submittedInterviewIds === null) {
            return null;
        }

        return !empty($submittedInterviewIds[$normalizedInterviewId]);
    }
}

if (!function_exists('student_preregistration_fetch_program_options')) {
    function student_preregistration_fetch_program_options(mysqli $conn): array
    {
        $options = [];
        $sql = "
            SELECT
                p.program_id,
                p.program_code,
                p.program_name,
                p.major,
                COUNT(spr.preregistration_id) AS prereg_count
            FROM tbl_program p
            LEFT JOIN tbl_student_preregistration spr
                ON spr.program_id = p.program_id
            WHERE p.status = 'active'
            GROUP BY p.program_id, p.program_code, p.program_name, p.major
            ORDER BY p.program_name ASC, p.major ASC, p.program_code ASC
        ";
        $result = $conn->query($sql);
        if (!$result) {
            return $options;
        }

        while ($row = $result->fetch_assoc()) {
            $row['label'] = student_preregistration_format_program_label($row);
            $options[] = $row;
        }
        $result->free();

        return $options;
    }
}

if (!function_exists('student_preregistration_fetch_report')) {
    function student_preregistration_fetch_report(mysqli $conn, array $filters = []): array
    {
        $search = trim((string) ($filters['search'] ?? ''));
        $programId = max(0, (int) ($filters['program_id'] ?? 0));
        $limit = max(1, min(2000, (int) ($filters['limit'] ?? 1000)));

        $where = ['1=1'];
        $types = '';
        $params = [];

        if ($search !== '') {
            $like = '%' . $search . '%';
            $where[] = '(
                spr.examinee_number LIKE ?
                OR COALESCE(pr.full_name, \'\') LIKE ?
                OR COALESCE(p.program_code, \'\') LIKE ?
                OR COALESCE(p.program_name, \'\') LIKE ?
                OR COALESCE(c.campus_name, \'\') LIKE ?
            )';
            $types .= 'sssss';
            array_push($params, $like, $like, $like, $like, $like);
        }

        if ($programId > 0) {
            $where[] = 'spr.program_id = ?';
            $types .= 'i';
            $params[] = $programId;
        }

        $sql = "
            SELECT
                spr.preregistration_id,
                spr.credential_id,
                spr.interview_id,
                spr.examinee_number,
                spr.program_id,
                spr.locked_rank,
                spr.profile_completion_percent AS submitted_profile_completion_percent,
                spr.agreement_accepted,
                spr.agreement_accepted_at,
                spr.status,
                spr.submitted_at,
                spr.updated_at,
                COALESCE(sp.profile_completion_percent, spr.profile_completion_percent) AS current_profile_completion_percent,
                pr.full_name,
                c.campus_name,
                si.classification,
                si.final_score,
                si.interview_datetime,
                p.program_code,
                p.program_name,
                p.major,
                sc.status AS credential_status,
                sc.must_change_password
            FROM tbl_student_preregistration spr
            LEFT JOIN tbl_student_interview si
                ON si.interview_id = spr.interview_id
            LEFT JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p
                ON p.program_id = spr.program_id
            LEFT JOIN tbl_campus c
                ON c.campus_id = si.campus_id
            LEFT JOIN tbl_student_profile sp
                ON sp.credential_id = spr.credential_id
            LEFT JOIN tbl_student_credentials sc
                ON sc.credential_id = spr.credential_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY
                CASE
                    WHEN spr.locked_rank IS NULL OR spr.locked_rank = 0 THEN 1
                    ELSE 0
                END ASC,
                spr.locked_rank ASC,
                spr.submitted_at DESC,
                spr.preregistration_id DESC
            LIMIT ?
        ";

        $rows = [];
        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmtTypes = $types . 'i';
            $stmtParams = $params;
            $stmtParams[] = $limit;
            $stmt->bind_param($stmtTypes, ...$stmtParams);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $row['program_label'] = student_preregistration_format_program_label($row);
                $rows[] = $row;
            }
            $stmt->close();
        }

        $programMap = [];
        $summary = [
            'total' => count($rows),
            'programs' => 0,
            'profile_complete' => 0,
            'profile_incomplete' => 0,
            'agreement_accepted' => 0,
        ];

        foreach ($rows as $row) {
            $rowProgramId = (int) ($row['program_id'] ?? 0);
            if ($rowProgramId > 0) {
                $programMap[$rowProgramId] = true;
            }

            $currentPercent = (float) ($row['current_profile_completion_percent'] ?? 0);
            if ($currentPercent >= 100) {
                $summary['profile_complete']++;
            } else {
                $summary['profile_incomplete']++;
            }

            if ((int) ($row['agreement_accepted'] ?? 0) === 1) {
                $summary['agreement_accepted']++;
            }
        }

        $summary['programs'] = count($programMap);

        return [
            'rows' => $rows,
            'summary' => $summary,
        ];
    }
}

if (!function_exists('student_preregistration_delete_by_program')) {
    function student_preregistration_delete_by_program(mysqli $conn, int $programId): array
    {
        $programId = max(0, $programId);
        if ($programId <= 0) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'Invalid program selected.',
            ];
        }

        $stmt = $conn->prepare("DELETE FROM tbl_student_preregistration WHERE program_id = ?");
        if (!$stmt) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'Server error while preparing cleanup query.',
            ];
        }

        $stmt->bind_param('i', $programId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'Failed to remove pre-registrations.',
            ];
        }

        $deleted = (int) $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'deleted' => $deleted,
            'message' => $deleted > 0 ? 'Pre-registrations removed.' : 'No pre-registrations found for selected program.',
        ];
    }
}

if (!function_exists('student_preregistration_delete_by_programs')) {
    function student_preregistration_delete_by_programs(mysqli $conn, array $programIds): array
    {
        $normalizedIds = [];
        foreach ($programIds as $programId) {
            $id = (int) $programId;
            if ($id > 0) {
                $normalizedIds[] = $id;
            }
        }

        $normalizedIds = array_values(array_unique($normalizedIds));
        if (empty($normalizedIds)) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'No valid program selected.',
            ];
        }

        $placeholders = implode(',', array_fill(0, count($normalizedIds), '?'));
        $deleteSql = "DELETE FROM tbl_student_preregistration WHERE program_id IN ({$placeholders})";
        $stmt = $conn->prepare($deleteSql);
        if (!$stmt) {
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'Server error while preparing cleanup query.',
            ];
        }

        $types = str_repeat('i', count($normalizedIds));
        $stmt->bind_param($types, ...$normalizedIds);
        if (!$stmt->execute()) {
            $stmt->close();
            return [
                'success' => false,
                'deleted' => 0,
                'message' => 'Failed to remove pre-registrations.',
            ];
        }

        $deleted = (int) $stmt->affected_rows;
        $stmt->close();

        return [
            'success' => true,
            'deleted' => $deleted,
            'message' => $deleted > 0 ? 'Pre-registrations removed.' : 'No pre-registrations found for selected programs.',
            'program_ids' => $normalizedIds,
        ];
    }
}

if (!function_exists('student_preregistration_get_print_header')) {
    function student_preregistration_get_print_header(): array
    {
        return [
            'institution' => 'Sultan Kudarat State University',
            'address' => 'EJC Montilla, Tacurong City, 9800, Philippines',
            'report_title' => 'Pre-Registered Students',
        ];
    }
}

if (!function_exists('student_preregistration_build_print_sections')) {
    function student_preregistration_build_print_sections(array $rows): array
    {
        $sections = [];

        foreach ($rows as $row) {
            $programLabel = trim((string) ($row['program_label'] ?? 'No Program'));
            if ($programLabel === '') {
                $programLabel = 'No Program';
            }

            if (!isset($sections[$programLabel])) {
                $sections[$programLabel] = [
                    'program_label' => $programLabel,
                    'rows' => [],
                ];
            }

            $sections[$programLabel]['rows'][] = $row;
        }

        ksort($sections, SORT_NATURAL | SORT_FLAG_CASE);

        foreach ($sections as &$section) {
            usort($section['rows'], function (array $left, array $right): int {
                $leftRank = (int) ($left['locked_rank'] ?? 0);
                $rightRank = (int) ($right['locked_rank'] ?? 0);

                if ($leftRank === 0) {
                    $leftRank = 2147483647;
                }

                if ($rightRank === 0) {
                    $rightRank = 2147483647;
                }

                if ($leftRank < $rightRank) {
                    return -1;
                }

                if ($leftRank > $rightRank) {
                    return 1;
                }

                $leftName = trim((string) ($left['full_name'] ?? ''));
                $rightName = trim((string) ($right['full_name'] ?? ''));
                if (function_exists('mb_strtoupper')) {
                    $leftName = mb_strtoupper($leftName, 'UTF-8');
                    $rightName = mb_strtoupper($rightName, 'UTF-8');
                } else {
                    $leftName = strtoupper($leftName);
                    $rightName = strtoupper($rightName);
                }
                $compare = strcmp($leftName, $rightName);
                if ($compare !== 0) {
                    return $compare;
                }

                return strcmp(
                    trim((string) ($left['examinee_number'] ?? '')),
                    trim((string) ($right['examinee_number'] ?? ''))
                );
            });
        }
        unset($section);

        return array_values($sections);
    }
}
