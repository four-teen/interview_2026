<?php
/**
 * Program-chair account assignment helpers.
 */

if (!function_exists('ensure_account_program_assignments_table')) {
    function ensure_account_program_assignments_table(mysqli $conn): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_account_program_assignments (
                assignment_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                accountid INT(11) NOT NULL,
                program_id INT(10) UNSIGNED NOT NULL,
                status ENUM('active','inactive') NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (assignment_id),
                UNIQUE KEY uniq_account_program (accountid, program_id),
                KEY idx_account_status (accountid, status),
                KEY idx_program_status (program_id, status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        return (bool) $conn->query($sql);
    }
}

if (!function_exists('normalize_program_id_list')) {
    function normalize_program_id_list(array $programIds): array
    {
        $map = [];
        foreach ($programIds as $programId) {
            $value = (int) $programId;
            if ($value > 0) {
                $map[$value] = $value;
            }
        }

        return array_values($map);
    }
}

if (!function_exists('get_fallback_program_group_ids')) {
    function get_fallback_program_group_ids(mysqli $conn, int $accountId, int $fallbackProgramId): array
    {
        $accountId = (int) $accountId;
        $fallbackProgramId = (int) $fallbackProgramId;

        if ($accountId <= 0 || $fallbackProgramId <= 0) {
            return [];
        }

        $metaSql = "
            SELECT
                a.campus_id,
                p.program_code
            FROM tblaccount a
            LEFT JOIN tbl_program p
                ON p.program_id = ?
            WHERE a.accountid = ?
            LIMIT 1
        ";
        $metaStmt = $conn->prepare($metaSql);
        if (!$metaStmt) {
            return [$fallbackProgramId];
        }

        $metaStmt->bind_param('ii', $fallbackProgramId, $accountId);
        $metaStmt->execute();
        $metaRow = $metaStmt->get_result()->fetch_assoc();
        $metaStmt->close();

        $programCode = trim((string) ($metaRow['program_code'] ?? ''));
        $campusId = (int) ($metaRow['campus_id'] ?? 0);
        if ($programCode === '') {
            return [$fallbackProgramId];
        }

        $groupSql = "
            SELECT p.program_id
            FROM tbl_program p
            INNER JOIN tbl_college c
                ON c.college_id = p.college_id
            WHERE p.status = 'active'
              AND p.program_code = ?
              AND (? <= 0 OR c.campus_id = ?)
            ORDER BY p.program_name ASC, p.major ASC, p.program_id ASC
        ";
        $groupStmt = $conn->prepare($groupSql);
        if (!$groupStmt) {
            return [$fallbackProgramId];
        }

        $groupStmt->bind_param('sii', $programCode, $campusId, $campusId);
        $groupStmt->execute();
        $groupResult = $groupStmt->get_result();

        $groupProgramIds = [];
        while ($row = $groupResult->fetch_assoc()) {
            $programId = (int) ($row['program_id'] ?? 0);
            if ($programId > 0) {
                $groupProgramIds[] = $programId;
            }
        }
        $groupStmt->close();

        $groupProgramIds = normalize_program_id_list($groupProgramIds);
        if (!empty($groupProgramIds)) {
            return $groupProgramIds;
        }

        return [$fallbackProgramId];
    }
}

if (!function_exists('get_account_assigned_program_ids')) {
    function get_account_assigned_program_ids(mysqli $conn, int $accountId, int $fallbackProgramId = 0): array
    {
        $accountId = (int) $accountId;
        $fallbackProgramId = (int) $fallbackProgramId;

        if ($accountId <= 0) {
            return $fallbackProgramId > 0 ? [$fallbackProgramId] : [];
        }

        $programIds = [];

        if (ensure_account_program_assignments_table($conn)) {
            $sql = "
                SELECT apa.program_id
                FROM tbl_account_program_assignments apa
                INNER JOIN tbl_program p
                    ON p.program_id = apa.program_id
                WHERE apa.accountid = ?
                  AND apa.status = 'active'
                  AND p.status = 'active'
                ORDER BY apa.assignment_id ASC
            ";

            $stmt = $conn->prepare($sql);
            if ($stmt) {
                $stmt->bind_param('i', $accountId);
                $stmt->execute();
                $result = $stmt->get_result();

                while ($row = $result->fetch_assoc()) {
                    $id = (int) ($row['program_id'] ?? 0);
                    if ($id > 0) {
                        $programIds[] = $id;
                    }
                }
                $stmt->close();
            }
        }

        $programIds = normalize_program_id_list($programIds);
        if (!empty($programIds)) {
            return $programIds;
        }

        $fallbackGroupProgramIds = get_fallback_program_group_ids($conn, $accountId, $fallbackProgramId);
        if (!empty($fallbackGroupProgramIds)) {
            return normalize_program_id_list($fallbackGroupProgramIds);
        }

        return $fallbackProgramId > 0 ? [$fallbackProgramId] : [];
    }
}
