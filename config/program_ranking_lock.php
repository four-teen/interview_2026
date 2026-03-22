<?php
/**
 * Shared program ranking + lock helpers.
 */

require_once __DIR__ . '/system_controls.php';
require_once __DIR__ . '/../progchair/endorsement_helpers.php';

if (!function_exists('ensure_program_ranking_locks_table')) {
    function ensure_program_ranking_locks_table(mysqli $conn): void
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_program_ranking_locks (
                lock_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                program_id INT(10) UNSIGNED NOT NULL,
                interview_id INT(10) UNSIGNED NOT NULL,
                locked_rank INT(10) UNSIGNED NOT NULL,
                locked_by INT(10) UNSIGNED DEFAULT NULL,
                locked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                snapshot_examinee_number VARCHAR(30) NOT NULL,
                snapshot_full_name VARCHAR(255) NOT NULL,
                snapshot_classification VARCHAR(100) NOT NULL,
                snapshot_sat_score INT(11) NOT NULL DEFAULT 0,
                snapshot_final_score DECIMAL(8,2) NOT NULL DEFAULT 0.00,
                snapshot_is_endorsement TINYINT(1) NOT NULL DEFAULT 0,
                snapshot_endorsement_order VARCHAR(32) DEFAULT NULL,
                snapshot_interview_datetime VARCHAR(32) DEFAULT NULL,
                snapshot_encoded_by VARCHAR(255) DEFAULT NULL,
                snapshot_section VARCHAR(16) NOT NULL DEFAULT 'regular',
                snapshot_outside_capacity TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (lock_id),
                UNIQUE KEY uq_program_rank (program_id, locked_rank),
                UNIQUE KEY uq_program_interview (program_id, interview_id),
                KEY idx_program (program_id),
                KEY idx_interview (interview_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        $conn->query($sql);
    }
}

if (!function_exists('program_ranking_build_esm_preferred_program_condition_sql')) {
    function program_ranking_build_esm_preferred_program_condition_sql(string $columnExpression): string
    {
        $normalizedColumn = "UPPER(COALESCE({$columnExpression}, ''))";
        $patterns = [
            '%NURSING%',
            '%MIDWIFERY%',
            '%MEDICAL TECHNOLOGY%',
            '%ELECTRONICS ENGINEERING%',
            '%CIVIL ENGINEERING%',
            '%COMPUTER ENGINEERING%',
            '%COMPUTER SCIENCE%',
            '%FISHERIES%',
            '%BIOLOGY%',
            '%ACCOUNTANCY%',
            '%MANAGEMENT ACCOUNTING%',
            '%ACCOUNTING INFORMATION SYSTEMS%',
            '%SECONDARY EDUCATION%MATHEMATICS%',
            '%MATHEMATICS EDUCATION%',
            '%SECONDARY EDUCATION%SCIENCE%',
            '%SCIENCE EDUCATION%'
        ];

        $conditions = [];
        foreach ($patterns as $pattern) {
            $conditions[] = "{$normalizedColumn} LIKE '{$pattern}'";
        }

        return '(' . implode(' OR ', $conditions) . ')';
    }
}

if (!function_exists('program_ranking_split_rows_by_capacity')) {
    function program_ranking_split_rows_by_capacity(array $rows, int $limit, bool $quotaEnabled): array
    {
        if (!$quotaEnabled) {
            return ['inside' => array_values($rows), 'outside' => []];
        }

        $safeLimit = max(0, $limit);
        return [
            'inside' => array_slice($rows, 0, $safeLimit),
            'outside' => array_slice($rows, $safeLimit)
        ];
    }
}

if (!function_exists('program_ranking_normalize_section')) {
    function program_ranking_normalize_section(string $section): string
    {
        $normalized = strtolower(trim($section));
        if ($normalized === 'scc') {
            return 'scc';
        }
        if ($normalized === 'etg') {
            return 'etg';
        }
        return 'regular';
    }
}

if (!function_exists('program_ranking_summarize_locked_rows')) {
    function program_ranking_summarize_locked_rows(array $lockRows): array
    {
        $lockedInterviewIds = [];
        $insideCounts = [
            'regular' => 0,
            'scc' => 0,
            'etg' => 0,
        ];
        $outsideCounts = [
            'regular' => 0,
            'scc' => 0,
            'etg' => 0,
        ];

        foreach ($lockRows as $lockRow) {
            $interviewId = (int) ($lockRow['interview_id'] ?? 0);
            if ($interviewId > 0) {
                $lockedInterviewIds[$interviewId] = true;
            }

            $section = program_ranking_normalize_section((string) ($lockRow['snapshot_section'] ?? 'regular'));
            if (!isset($insideCounts[$section])) {
                $section = 'regular';
            }

            $isOutsideCapacity = ((int) ($lockRow['snapshot_outside_capacity'] ?? 0) === 1);
            if ($isOutsideCapacity) {
                $outsideCounts[$section]++;
            } else {
                $insideCounts[$section]++;
            }
        }

        return [
            'locked_interview_ids' => $lockedInterviewIds,
            'inside_counts' => $insideCounts,
            'outside_counts' => $outsideCounts,
        ];
    }
}

if (!function_exists('program_ranking_build_lock_ranges')) {
    function program_ranking_build_lock_ranges(array $lockRows): array
    {
        $ranks = [];
        foreach ($lockRows as $lockRow) {
            $rank = (int) ($lockRow['locked_rank'] ?? 0);
            if ($rank > 0) {
                $ranks[] = $rank;
            }
        }
        if (empty($ranks)) {
            return [];
        }

        sort($ranks);
        $ranges = [];
        $start = $ranks[0];
        $end = $ranks[0];
        for ($i = 1, $count = count($ranks); $i < $count; $i++) {
            $rank = $ranks[$i];
            if ($rank === $end + 1) {
                $end = $rank;
                continue;
            }
            $ranges[] = ($start === $end) ? (string) $start : ($start . '-' . $end);
            $start = $rank;
            $end = $rank;
        }
        $ranges[] = ($start === $end) ? (string) $start : ($start . '-' . $end);
        return $ranges;
    }
}

if (!function_exists('program_ranking_find_row_by_interview_id')) {
    function program_ranking_find_row_by_interview_id(array $rows, int $interviewId): ?array
    {
        if ($interviewId <= 0) {
            return null;
        }

        foreach ($rows as $row) {
            if ((int) ($row['interview_id'] ?? 0) === $interviewId) {
                return $row;
            }
        }

        return null;
    }
}

if (!function_exists('program_ranking_load_active_locks')) {
    function program_ranking_load_active_locks(mysqli $conn, int $programId): array
    {
        ensure_program_ranking_locks_table($conn);
        $sql = "
            SELECT
                l.*,
                a.acc_fullname AS locked_by_name
            FROM tbl_program_ranking_locks l
            LEFT JOIN tblaccount a
                ON l.locked_by = a.accountid
            WHERE l.program_id = ?
            ORDER BY l.locked_rank ASC
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [];
        }
        $stmt->bind_param("i", $programId);
        $stmt->execute();
        $result = $stmt->get_result();
        $rows = [];
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('program_ranking_apply_locks')) {
    function program_ranking_apply_locks(array $orderedEntries, array $lockRows): array
    {
        $lockedByRank = [];
        $lockedInterviewIds = [];
        $maxLockedRank = 0;

        foreach ($lockRows as $lockRow) {
            $rank = (int) ($lockRow['locked_rank'] ?? 0);
            if ($rank <= 0) {
                continue;
            }
            $maxLockedRank = max($maxLockedRank, $rank);
            $interviewId = (int) ($lockRow['interview_id'] ?? 0);
            if ($interviewId > 0) {
                $lockedInterviewIds[$interviewId] = true;
            }

            $lockedByRank[$rank] = [
                'interview_id' => $interviewId,
                'examinee_number' => (string) ($lockRow['snapshot_examinee_number'] ?? ''),
                'full_name' => (string) ($lockRow['snapshot_full_name'] ?? ''),
                'classification' => (string) ($lockRow['snapshot_classification'] ?? 'REGULAR'),
                'sat_score' => (int) ($lockRow['snapshot_sat_score'] ?? 0),
                'final_score' => number_format((float) ($lockRow['snapshot_final_score'] ?? 0), 2, '.', ''),
                'interview_datetime' => (string) ($lockRow['snapshot_interview_datetime'] ?? ''),
                'encoded_by' => (string) ($lockRow['snapshot_encoded_by'] ?? ''),
                'is_endorsement' => ((int) ($lockRow['snapshot_is_endorsement'] ?? 0) === 1),
                'endorsement_label' => ((int) ($lockRow['snapshot_is_endorsement'] ?? 0) === 1) ? 'SCC' : '',
                'endorsement_order' => (string) ($lockRow['snapshot_endorsement_order'] ?? ''),
                'row_section' => program_ranking_normalize_section((string) ($lockRow['snapshot_section'] ?? 'regular')),
                'is_outside_capacity' => ((int) ($lockRow['snapshot_outside_capacity'] ?? 0) === 1),
                'is_locked' => true,
                'locked_rank' => $rank,
                'locked_at' => (string) ($lockRow['locked_at'] ?? ''),
                'locked_by' => (int) ($lockRow['locked_by'] ?? 0),
                'locked_by_name' => (string) ($lockRow['locked_by_name'] ?? '')
            ];
        }

        $unlocked = array_values(array_filter($orderedEntries, function (array $entry) use ($lockedInterviewIds): bool {
            $interviewId = (int) ($entry['interview_id'] ?? 0);
            return $interviewId <= 0 || !isset($lockedInterviewIds[$interviewId]);
        }));

        $finalRows = [];
        $cursor = 0;
        $targetRanks = max(count($orderedEntries), $maxLockedRank);

        for ($rank = 1; $rank <= $targetRanks; $rank++) {
            if (isset($lockedByRank[$rank])) {
                $row = $lockedByRank[$rank];
                $row['rank'] = $rank;
                $finalRows[] = $row;
                continue;
            }
            if (!isset($unlocked[$cursor])) {
                continue;
            }
            $row = $unlocked[$cursor];
            $row['is_locked'] = false;
            $row['locked_rank'] = null;
            $row['locked_at'] = '';
            $row['locked_by'] = 0;
            $row['locked_by_name'] = '';
            $row['rank'] = $rank;
            $finalRows[] = $row;
            $cursor++;
        }

        while (isset($unlocked[$cursor])) {
            $targetRanks++;
            $row = $unlocked[$cursor];
            $row['is_locked'] = false;
            $row['locked_rank'] = null;
            $row['locked_at'] = '';
            $row['locked_by'] = 0;
            $row['locked_by_name'] = '';
            $row['rank'] = $targetRanks;
            $finalRows[] = $row;
            $cursor++;
        }

        return [
            'rows' => $finalRows,
            'locks' => [
                'active_count' => count($lockRows),
                'max_locked_rank' => $maxLockedRank,
                'ranges' => program_ranking_build_lock_ranges($lockRows)
            ]
        ];
    }
}

if (!function_exists('program_ranking_fetch_payload')) {
    function program_ranking_fetch_payload(mysqli $conn, int $programId, ?int $campusId = null): array
    {
        ensure_program_endorsement_table($conn);
        ensure_program_ranking_locks_table($conn);

        $programSql = "
            SELECT
                p.program_id,
                p.program_name,
                p.major,
                col.college_name,
                cam.campus_name,
                cam.campus_id,
                pc.cutoff_score,
                pc.absorptive_capacity,
                pc.regular_percentage,
                pc.etg_percentage,
                COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity
            FROM tbl_program p
            INNER JOIN tbl_college col
                ON p.college_id = col.college_id
            INNER JOIN tbl_campus cam
                ON col.campus_id = cam.campus_id
            LEFT JOIN tbl_program_cutoff pc
                ON pc.program_id = p.program_id
            WHERE p.program_id = ?
              AND p.status = 'active'
        ";
        if ($campusId !== null) {
            $programSql .= " AND cam.campus_id = ? ";
        }
        $programSql .= " LIMIT 1 ";

        $stmtProgram = $conn->prepare($programSql);
        if (!$stmtProgram) {
            return ['success' => false, 'http_status' => 500, 'message' => 'Server error (program query).'];
        }

        if ($campusId !== null) {
            $stmtProgram->bind_param("ii", $programId, $campusId);
        } else {
            $stmtProgram->bind_param("i", $programId);
        }
        $stmtProgram->execute();
        $program = $stmtProgram->get_result()->fetch_assoc();
        $stmtProgram->close();

        if (!$program) {
            return ['success' => false, 'http_status' => 404, 'message' => 'Program not found.'];
        }

        $programCutoff = $program['cutoff_score'] !== null ? (int) $program['cutoff_score'] : null;
        $globalSatCutoffState = get_global_sat_cutoff_state($conn);
        $globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
        $globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;
        $effectiveCutoff = get_effective_sat_cutoff($programCutoff, $globalSatCutoffEnabled, $globalSatCutoffValue);

        $esmPreferredProgramConditionSql = program_ranking_build_esm_preferred_program_condition_sql('pr.preferred_program');
        $cutoffBasisScoreSql = "CASE
            WHEN {$esmPreferredProgramConditionSql} THEN COALESCE(pr.esm_competency_standard_score, pr.sat_score, 0)
            ELSE COALESCE(pr.overall_standard_score, pr.sat_score, 0)
        END";
        $cutoffWhereSql = $effectiveCutoff !== null ? " AND ({$cutoffBasisScoreSql}) >= ? " : '';

        $rankingSql = "
            SELECT
                si.interview_id,
                si.examinee_number,
                pr.full_name,
                ({$cutoffBasisScoreSql}) AS cutoff_basis_score,
                si.final_score,
                si.interview_datetime,
                a.acc_fullname AS encoded_by,
                CASE
                    WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG'
                        THEN CONCAT('ETG-', COALESCE(NULLIF(TRIM(ec.class_desc), ''), 'UNSPECIFIED'))
                    ELSE 'REGULAR'
                END AS classification_label,
                CASE
                    WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 1
                    ELSE 0
                END AS classification_group
            FROM tbl_student_interview si
            INNER JOIN tbl_placement_results pr
                ON si.placement_result_id = pr.id
            LEFT JOIN tblaccount a
                ON si.program_chair_id = a.accountid
            LEFT JOIN tbl_etg_class ec
                ON si.etg_class_id = ec.etgclassid
            WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
              AND si.status = 'active'
              AND si.final_score IS NOT NULL
              {$cutoffWhereSql}
            ORDER BY
                classification_group ASC,
                si.final_score DESC,
                cutoff_basis_score DESC,
                pr.full_name ASC
        ";

        $stmtRanking = $conn->prepare($rankingSql);
        if (!$stmtRanking) {
            return ['success' => false, 'http_status' => 500, 'message' => 'Server error (ranking query).'];
        }

        if ($effectiveCutoff !== null) {
            $stmtRanking->bind_param("ii", $programId, $effectiveCutoff);
        } else {
            $stmtRanking->bind_param("i", $programId);
        }
        $stmtRanking->execute();
        $resultRanking = $stmtRanking->get_result();
        $stmtRanking->close();

        $mapRankingRow = static function (array $row): array {
            return [
                'interview_id' => (int) ($row['interview_id'] ?? 0),
                'examinee_number' => (string) ($row['examinee_number'] ?? ''),
                'full_name' => (string) ($row['full_name'] ?? ''),
                'classification' => (string) ($row['classification_label'] ?? 'REGULAR'),
                'sat_score' => (int) ($row['cutoff_basis_score'] ?? 0),
                'final_score' => number_format((float) ($row['final_score'] ?? 0), 2, '.', ''),
                'interview_datetime' => (string) ($row['interview_datetime'] ?? ''),
                'encoded_by' => (string) ($row['encoded_by'] ?? ''),
                'is_endorsement' => false,
                'cutoff_override' => false,
            ];
        };

        $allRegularRows = [];
        $allEtgRows = [];
        $allRowsByInterviewId = [];

        while ($row = $resultRanking->fetch_assoc()) {
            $mapped = $mapRankingRow($row);
            $allRowsByInterviewId[$mapped['interview_id']] = $mapped;
            if ((int) ($row['classification_group'] ?? 0) === 1) {
                $allEtgRows[] = $mapped;
            } else {
                $allRegularRows[] = $mapped;
            }
        }

        $quotaEnabled = false;
        $absorptiveCapacity = null;
        $regularPercentage = null;
        $etgPercentage = null;
        $endorsementCapacity = max(0, (int) ($program['endorsement_capacity'] ?? 0));
        $regularSlots = null;
        $etgSlots = null;
        $baseCapacity = null;

        if (
            $program['absorptive_capacity'] !== null &&
            $program['regular_percentage'] !== null &&
            $program['etg_percentage'] !== null
        ) {
            $absorptiveCapacity = max(0, (int) $program['absorptive_capacity']);
            $regularPercentage = round((float) $program['regular_percentage'], 2);
            $etgPercentage = round((float) $program['etg_percentage'], 2);
            $baseCapacity = max(0, $absorptiveCapacity - $endorsementCapacity);

            if (
                $regularPercentage >= 0 &&
                $regularPercentage <= 100 &&
                $etgPercentage >= 0 &&
                $etgPercentage <= 100 &&
                abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
            ) {
                $quotaEnabled = true;
                $regularSlots = (int) round($baseCapacity * ($regularPercentage / 100));
                $etgSlots = max(0, $baseCapacity - $regularSlots);
            }
        }

        $endorsementRowsRaw = load_program_endorsements($conn, $programId);
        $overrideOnlyRowsByInterviewId = [];
        $overrideOnlyIds = [];
        foreach ($endorsementRowsRaw as $endorsementRow) {
            $eid = (int) ($endorsementRow['interview_id'] ?? 0);
            if (
                $eid > 0 &&
                !isset($allRowsByInterviewId[$eid]) &&
                !empty($endorsementRow['override_cutoff'])
            ) {
                $overrideOnlyIds[$eid] = $eid;
            }
        }

        if (!empty($overrideOnlyIds)) {
            $placeholders = implode(',', array_fill(0, count($overrideOnlyIds), '?'));
            $overrideSql = "
                SELECT
                    si.interview_id,
                    si.examinee_number,
                    pr.full_name,
                    ({$cutoffBasisScoreSql}) AS cutoff_basis_score,
                    si.final_score,
                    si.interview_datetime,
                    a.acc_fullname AS encoded_by,
                    CASE
                        WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG'
                            THEN CONCAT('ETG-', COALESCE(NULLIF(TRIM(ec.class_desc), ''), 'UNSPECIFIED'))
                        ELSE 'REGULAR'
                    END AS classification_label,
                    CASE
                        WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 1
                        ELSE 0
                    END AS classification_group
                FROM tbl_student_interview si
                INNER JOIN tbl_placement_results pr
                    ON si.placement_result_id = pr.id
                LEFT JOIN tblaccount a
                    ON si.program_chair_id = a.accountid
                LEFT JOIN tbl_etg_class ec
                    ON si.etg_class_id = ec.etgclassid
                WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
                  AND si.status = 'active'
                  AND si.final_score IS NOT NULL
                  AND si.interview_id IN ({$placeholders})
                ORDER BY si.interview_id ASC
            ";

            $overrideStmt = $conn->prepare($overrideSql);
            if (!$overrideStmt) {
                return ['success' => false, 'http_status' => 500, 'message' => 'Server error (override endorsement query).'];
            }

            $overrideParams = array_merge([$programId], array_values($overrideOnlyIds));
            $overrideTypes = 'i' . str_repeat('i', count($overrideOnlyIds));
            $overrideBind = [$overrideTypes];
            foreach ($overrideParams as $index => $value) {
                $overrideBind[] = &$overrideParams[$index];
            }

            if (!call_user_func_array([$overrideStmt, 'bind_param'], $overrideBind)) {
                $overrideStmt->close();
                return ['success' => false, 'http_status' => 500, 'message' => 'Server error (override endorsement bind).'];
            }

            $overrideStmt->execute();
            $overrideResult = $overrideStmt->get_result();
            while ($overrideRow = $overrideResult->fetch_assoc()) {
                $mapped = $mapRankingRow($overrideRow);
                $mapped['cutoff_override'] = true;
                $overrideOnlyRowsByInterviewId[(int) ($mapped['interview_id'] ?? 0)] = $mapped;
            }
            $overrideStmt->close();
        }

        $endorsementRows = [];
        $endorsementIds = [];
        foreach ($endorsementRowsRaw as $endorsementRow) {
            $eid = (int) ($endorsementRow['interview_id'] ?? 0);
            if ($eid <= 0) {
                continue;
            }

            if (isset($allRowsByInterviewId[$eid])) {
                $mapped = $allRowsByInterviewId[$eid];
            } elseif (isset($overrideOnlyRowsByInterviewId[$eid])) {
                $mapped = $overrideOnlyRowsByInterviewId[$eid];
            } else {
                continue;
            }

            $mapped['is_endorsement'] = true;
            $mapped['endorsement_label'] = 'SCC';
            $mapped['endorsement_order'] = (string) ($endorsementRow['endorsed_at'] ?? '');
            $mapped['cutoff_override'] = !empty($endorsementRow['override_cutoff']) || !empty($mapped['cutoff_override']);
            $endorsementRows[] = $mapped;
            $endorsementIds[$eid] = true;
        }

        $filteredRegularRows = array_values(array_filter($allRegularRows, function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));
        $filteredEtgRows = array_values(array_filter($allEtgRows, function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));

        $regularRows = $filteredRegularRows;
        $etgRows = $filteredEtgRows;
        $lockRows = program_ranking_load_active_locks($conn, $programId);
        $lockedSummary = program_ranking_summarize_locked_rows($lockRows);
        $lockedInterviewIds = $lockedSummary['locked_interview_ids'];
        $lockedInsideCounts = is_array($lockedSummary['inside_counts'] ?? null)
            ? $lockedSummary['inside_counts']
            : ['regular' => 0, 'scc' => 0, 'etg' => 0];

        $unlockedRegularRows = array_values(array_filter($regularRows, function (array $row) use ($lockedInterviewIds): bool {
            return !isset($lockedInterviewIds[(int) ($row['interview_id'] ?? 0)]);
        }));
        $unlockedEndorsementRows = array_values(array_filter($endorsementRows, function (array $row) use ($lockedInterviewIds): bool {
            return !isset($lockedInterviewIds[(int) ($row['interview_id'] ?? 0)]);
        }));
        $unlockedEtgRows = array_values(array_filter($etgRows, function (array $row) use ($lockedInterviewIds): bool {
            return !isset($lockedInterviewIds[(int) ($row['interview_id'] ?? 0)]);
        }));

        $regularEffectiveSlots = null;
        $endorsementInRegularSlots = 0;
        $endorsementSelectedCount = count($endorsementRows);
        if ($quotaEnabled) {
            $regularSlotsCount = max(0, (int) $regularSlots);
            $endorsementSlotsCount = max(0, (int) $endorsementCapacity);
            $regularEffectiveSlots = $regularSlotsCount;
            $regularRemainingSlots = max(0, $regularSlotsCount - max(0, (int) ($lockedInsideCounts['regular'] ?? 0)));
            $endorsementRemainingSlots = max(0, $endorsementSlotsCount - max(0, (int) ($lockedInsideCounts['scc'] ?? 0)));
            $etgRemainingSlots = max(0, max(0, (int) $etgSlots) - max(0, (int) ($lockedInsideCounts['etg'] ?? 0)));
        } else {
            $regularRemainingSlots = 0;
            $endorsementRemainingSlots = 0;
            $etgRemainingSlots = 0;
        }

        $regularSplit = program_ranking_split_rows_by_capacity($unlockedRegularRows, $regularRemainingSlots, $quotaEnabled);
        $endorsementSplit = program_ranking_split_rows_by_capacity($unlockedEndorsementRows, $endorsementRemainingSlots, $quotaEnabled);
        $etgSplit = program_ranking_split_rows_by_capacity($unlockedEtgRows, $etgRemainingSlots, $quotaEnabled);

        $regularInsideRows = $regularSplit['inside'];
        $regularOutsideRows = $regularSplit['outside'];
        $endorsementInsideRows = $endorsementSplit['inside'];
        $endorsementOutsideRows = $endorsementSplit['outside'];
        $etgInsideRows = $etgSplit['inside'];
        $etgOutsideRows = $etgSplit['outside'];

        if ($quotaEnabled && $absorptiveCapacity !== null) {
            $insideTotal = max(0, (int) ($lockedInsideCounts['regular'] ?? 0))
                + max(0, (int) ($lockedInsideCounts['scc'] ?? 0))
                + max(0, (int) ($lockedInsideCounts['etg'] ?? 0))
                + count($regularInsideRows)
                + count($endorsementInsideRows)
                + count($etgInsideRows);

            $remainingAbsorptiveSlots = max(0, max(0, (int) $absorptiveCapacity) - $insideTotal);
            $promoteOutsideRows = static function (array &$insideRows, array &$outsideRows) use (&$remainingAbsorptiveSlots): void {
                if ($remainingAbsorptiveSlots <= 0 || empty($outsideRows)) {
                    return;
                }

                $split = program_ranking_split_rows_by_capacity($outsideRows, $remainingAbsorptiveSlots, true);
                if (!empty($split['inside'])) {
                    $insideRows = array_merge($insideRows, $split['inside']);
                    $remainingAbsorptiveSlots = max(0, $remainingAbsorptiveSlots - count($split['inside']));
                }
                $outsideRows = $split['outside'];
            };

            // Fill any unused total absorptive seats from the remaining pools in section priority order.
            $promoteOutsideRows($regularInsideRows, $regularOutsideRows);
            $promoteOutsideRows($endorsementInsideRows, $endorsementOutsideRows);
            $promoteOutsideRows($etgInsideRows, $etgOutsideRows);
        }

        if ($quotaEnabled) {
            $regularShownCount = max(0, (int) ($lockedInsideCounts['regular'] ?? 0)) + count($regularInsideRows);
            $etgShownCount = max(0, (int) ($lockedInsideCounts['etg'] ?? 0)) + count($etgInsideRows);
            $endorsementShownCount = max(0, (int) ($lockedInsideCounts['scc'] ?? 0)) + count($endorsementInsideRows);
            $regularEffectiveSlots = $regularShownCount;
        } else {
            $regularShownCount = count($regularRows);
            $etgShownCount = count($etgRows);
            $endorsementShownCount = $endorsementSelectedCount;
        }

        $orderedEntries = [];
        $pushRows = function (array $rows, string $section, bool $outside) use (&$orderedEntries): void {
            foreach ($rows as $row) {
                $entry = $row;
                $entry['row_section'] = $section;
                $entry['is_outside_capacity'] = $outside;
                $orderedEntries[] = $entry;
            }
        };
        $pushRows($regularInsideRows, 'regular', false);
        $pushRows($endorsementInsideRows, 'scc', false);
        $pushRows($etgInsideRows, 'etg', false);
        $pushRows($regularOutsideRows, 'regular', true);
        $pushRows($endorsementOutsideRows, 'scc', true);
        $pushRows($etgOutsideRows, 'etg', true);

        $lockApply = program_ranking_apply_locks($orderedEntries, $lockRows);

        return [
            'success' => true,
            'program' => [
                'program_id' => (int) $program['program_id'],
                'program_name' => strtoupper((string) $program['program_name'] . (!empty($program['major']) ? ' - ' . (string) $program['major'] : '')),
                'campus_name' => (string) ($program['campus_name'] ?? ''),
                'college_name' => (string) ($program['college_name'] ?? '')
            ],
            'quota' => [
                'enabled' => $quotaEnabled,
                'cutoff_score' => $effectiveCutoff,
                'program_cutoff_score' => $programCutoff,
                'global_cutoff_enabled' => $globalSatCutoffEnabled,
                'global_cutoff_value' => $globalSatCutoffValue,
                'global_cutoff_active' => ($globalSatCutoffEnabled && $globalSatCutoffValue !== null),
                'absorptive_capacity' => $absorptiveCapacity,
                'base_capacity' => $baseCapacity,
                'endorsement_capacity' => $endorsementCapacity,
                'endorsement_selected' => $endorsementSelectedCount,
                'regular_slots' => $regularSlots,
                'regular_effective_slots' => $regularEffectiveSlots,
                'endorsement_in_regular_slots' => $endorsementInRegularSlots,
                'etg_slots' => $etgSlots,
                'regular_candidates' => count($filteredRegularRows),
                'etg_candidates' => count($filteredEtgRows),
                'regular_shown' => $regularShownCount,
                'endorsement_shown' => $endorsementShownCount,
                'etg_shown' => $etgShownCount
            ],
            'locks' => $lockApply['locks'],
            'rows' => $lockApply['rows']
        ];
    }
}

if (!function_exists('program_ranking_is_interview_locked')) {
    function program_ranking_is_interview_locked(mysqli $conn, int $interviewId): bool
    {
        ensure_program_ranking_locks_table($conn);
        if ($interviewId <= 0) {
            return false;
        }
        $stmt = $conn->prepare("SELECT 1 FROM tbl_program_ranking_locks WHERE interview_id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        $stmt->bind_param("i", $interviewId);
        $stmt->execute();
        $exists = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $exists;
    }
}

if (!function_exists('program_ranking_is_lock_context_outside_capacity')) {
    function program_ranking_is_lock_context_outside_capacity(?array $lockContext): bool
    {
        return is_array($lockContext) && !empty($lockContext['is_outside_capacity']);
    }
}

if (!function_exists('program_ranking_can_lock_context_preregister')) {
    function program_ranking_can_lock_context_preregister(?array $lockContext): bool
    {
        return is_array($lockContext) && !program_ranking_is_lock_context_outside_capacity($lockContext);
    }
}

if (!function_exists('program_ranking_get_interview_lock_context')) {
    function program_ranking_get_interview_lock_context(mysqli $conn, int $interviewId): ?array
    {
        ensure_program_ranking_locks_table($conn);
        if ($interviewId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                l.program_id,
                l.locked_rank,
                l.locked_at,
                l.snapshot_outside_capacity,
                p.program_name,
                p.major
            FROM tbl_program_ranking_locks l
            LEFT JOIN tbl_program p
                ON p.program_id = l.program_id
            WHERE l.interview_id = ?
            ORDER BY l.locked_rank ASC
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param("i", $interviewId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        return [
            'program_id' => (int) ($row['program_id'] ?? 0),
            'program_name' => (string) ($row['program_name'] ?? ''),
            'major' => (string) ($row['major'] ?? ''),
            'locked_rank' => (int) ($row['locked_rank'] ?? 0),
            'locked_at' => (string) ($row['locked_at'] ?? ''),
            'is_outside_capacity' => ((int) ($row['snapshot_outside_capacity'] ?? 0) === 1),
        ];
    }
}
