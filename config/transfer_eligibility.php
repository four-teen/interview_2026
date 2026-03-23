<?php
require_once __DIR__ . '/program_ranking_lock.php';

if (!function_exists('transfer_eligibility_build_cutoff_basis_score_sql')) {
    function transfer_eligibility_build_cutoff_basis_score_sql(string $preferredProgramColumnExpression): string
    {
        $esmPreferredProgramConditionSql = program_ranking_build_esm_preferred_program_condition_sql($preferredProgramColumnExpression);

        return "CASE
            WHEN {$esmPreferredProgramConditionSql} THEN COALESCE(pr.esm_competency_standard_score, pr.sat_score, 0)
            ELSE COALESCE(pr.overall_standard_score, pr.sat_score, 0)
        END";
    }
}

if (!function_exists('transfer_eligibility_reason_to_message')) {
    function transfer_eligibility_reason_to_message(string $reasonCode): string
    {
        switch ($reasonCode) {
            case 'student_not_found':
                return 'Student interview record is unavailable.';
            case 'same_program':
                return 'Selected program is already the current program.';
            case 'target_program_not_found':
                return 'Selected transfer program is not available.';
            case 'final_score_required':
                return 'Final interview score is required before requesting a transfer.';
            case 'campus_not_configured':
                return 'Selected program campus is not configured.';
            case 'capacity_not_configured':
                return 'Selected program capacity is not configured.';
            case 'cutoff_not_configured':
                return 'Selected program cutoff is not configured.';
            case 'below_cutoff':
                return 'Your score does not meet the selected program cutoff.';
            case 'outside_qualified_pool':
                return 'Your projected rank is outside the qualified pool for the selected program.';
            case 'no_slots_available':
                return 'Selected program has no available slots.';
            case 'validation_unavailable':
                return 'Transfer validation is temporarily unavailable. Please try again.';
            default:
                return 'Transfer request failed.';
        }
    }
}

if (!function_exists('transfer_eligibility_compare_rows')) {
    function transfer_eligibility_compare_rows(array $left, array $right): int
    {
        $leftFinal = (float) ($left['final_score'] ?? 0);
        $rightFinal = (float) ($right['final_score'] ?? 0);
        if ($leftFinal !== $rightFinal) {
            return ($leftFinal < $rightFinal) ? 1 : -1;
        }

        $leftBasis = (float) ($left['cutoff_basis_score'] ?? 0);
        $rightBasis = (float) ($right['cutoff_basis_score'] ?? 0);
        if ($leftBasis !== $rightBasis) {
            return ($leftBasis < $rightBasis) ? 1 : -1;
        }

        $nameComparison = strcasecmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
        if ($nameComparison !== 0) {
            return $nameComparison;
        }

        return strcmp((string) ($left['examinee_number'] ?? ''), (string) ($right['examinee_number'] ?? ''));
    }
}

if (!function_exists('transfer_eligibility_get_projected_rank')) {
    function transfer_eligibility_get_projected_rank(array $rankedRows, array $candidateRow): ?int
    {
        $candidateExaminee = trim((string) ($candidateRow['examinee_number'] ?? ''));
        if ($candidateExaminee === '') {
            return null;
        }

        $rows = [];
        foreach ($rankedRows as $row) {
            if ((string) ($row['examinee_number'] ?? '') === $candidateExaminee) {
                continue;
            }
            $rows[] = $row;
        }

        $rows[] = $candidateRow;
        usort($rows, 'transfer_eligibility_compare_rows');

        $rank = 1;
        foreach ($rows as $row) {
            if ((string) ($row['examinee_number'] ?? '') === $candidateExaminee) {
                return $rank;
            }
            $rank++;
        }

        return null;
    }
}

if (!function_exists('transfer_eligibility_load_candidate')) {
    function transfer_eligibility_load_candidate(mysqli $conn, int $interviewId): array
    {
        $cutoffBasisScoreSql = transfer_eligibility_build_cutoff_basis_score_sql('pr.preferred_program');
        $sql = "
            SELECT
                si.interview_id,
                si.examinee_number,
                si.first_choice,
                si.second_choice,
                si.third_choice,
                si.program_id,
                si.campus_id,
                si.program_chair_id,
                si.final_score,
                CASE
                    WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 'ETG'
                    ELSE 'REGULAR'
                END AS class_group,
                pr.full_name,
                ({$cutoffBasisScoreSql}) AS cutoff_basis_score
            FROM tbl_student_interview si
            INNER JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            WHERE si.interview_id = ?
              AND si.status = 'active'
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [
                'success' => false,
                'row' => null,
                'message' => 'Failed to prepare candidate validation query.',
            ];
        }

        $stmt->bind_param('i', $interviewId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [
                'success' => false,
                'row' => null,
                'message' => 'Failed to execute candidate validation query.',
            ];
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'success' => true,
            'row' => $row ?: null,
            'message' => '',
        ];
    }
}

if (!function_exists('transfer_eligibility_load_target_program')) {
    function transfer_eligibility_load_target_program(mysqli $conn, int $targetProgramId): array
    {
        $sql = "
            SELECT
                p.program_id,
                p.program_code,
                p.program_name,
                p.major,
                col.campus_id,
                cam.campus_name,
                pc.cutoff_score,
                pc.absorptive_capacity,
                pc.regular_percentage,
                pc.etg_percentage,
                COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity
            FROM tbl_program p
            LEFT JOIN tbl_college col
                ON col.college_id = p.college_id
            LEFT JOIN tbl_campus cam
                ON cam.campus_id = col.campus_id
            LEFT JOIN (
                SELECT
                    pcx.program_id,
                    pcx.cutoff_score,
                    pcx.absorptive_capacity,
                    pcx.regular_percentage,
                    pcx.etg_percentage,
                    COALESCE(pcx.endorsement_capacity, 0) AS endorsement_capacity
                FROM tbl_program_cutoff pcx
                INNER JOIN (
                    SELECT program_id, MAX(cutoff_id) AS max_cutoff_id
                    FROM tbl_program_cutoff
                    GROUP BY program_id
                ) latest_cutoff
                    ON latest_cutoff.max_cutoff_id = pcx.cutoff_id
            ) pc
                ON pc.program_id = p.program_id
            WHERE p.program_id = ?
              AND p.status = 'active'
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return [
                'success' => false,
                'row' => null,
                'message' => 'Failed to prepare target program validation query.',
            ];
        }

        $stmt->bind_param('i', $targetProgramId);
        if (!$stmt->execute()) {
            $stmt->close();
            return [
                'success' => false,
                'row' => null,
                'message' => 'Failed to execute target program validation query.',
            ];
        }

        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'success' => true,
            'row' => $row ?: null,
            'message' => '',
        ];
    }
}

if (!function_exists('transfer_eligibility_build_context')) {
    function transfer_eligibility_build_context(mysqli $conn): array
    {
        ensure_program_endorsement_table($conn);

        $context = [
            'rows_by_program' => [],
            'endorsement_ids_by_program' => [],
        ];

        $cutoffBasisScoreSql = transfer_eligibility_build_cutoff_basis_score_sql('pr.preferred_program');
        $rankingPoolSql = "
            SELECT
                COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) AS program_id,
                si.interview_id,
                CASE
                    WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 'ETG'
                    ELSE 'REGULAR'
                END AS class_group,
                si.examinee_number,
                si.final_score,
                ({$cutoffBasisScoreSql}) AS cutoff_basis_score,
                pr.full_name
            FROM tbl_student_interview si
            INNER JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            WHERE si.status = 'active'
              AND si.final_score IS NOT NULL
              AND COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) > 0
        ";

        $stmt = $conn->prepare($rankingPoolSql);
        if (!$stmt) {
            return [
                'success' => false,
                'context' => $context,
                'message' => 'Failed to prepare ranking pool validation query.',
            ];
        }

        if (!$stmt->execute()) {
            $stmt->close();
            return [
                'success' => false,
                'context' => $context,
                'message' => 'Failed to execute ranking pool validation query.',
            ];
        }

        $result = $stmt->get_result();
        while ($rankingRow = $result->fetch_assoc()) {
            $programId = (int) ($rankingRow['program_id'] ?? 0);
            if ($programId <= 0) {
                continue;
            }

            $classGroup = strtoupper(trim((string) ($rankingRow['class_group'] ?? 'REGULAR')));
            if ($classGroup !== 'ETG') {
                $classGroup = 'REGULAR';
            }

            if (!isset($context['rows_by_program'][$programId])) {
                $context['rows_by_program'][$programId] = [
                    'REGULAR' => [],
                    'ETG' => [],
                ];
            }

            $context['rows_by_program'][$programId][$classGroup][] = [
                'interview_id' => (int) ($rankingRow['interview_id'] ?? 0),
                'examinee_number' => (string) ($rankingRow['examinee_number'] ?? ''),
                'final_score' => (float) ($rankingRow['final_score'] ?? 0),
                'cutoff_basis_score' => (float) ($rankingRow['cutoff_basis_score'] ?? 0),
                'full_name' => trim((string) ($rankingRow['full_name'] ?? '')),
            ];
        }
        $stmt->close();

        $endorsementSql = "
            SELECT program_id, interview_id
            FROM tbl_program_endorsements
            ORDER BY program_id ASC, endorsed_at ASC, endorsement_id ASC
        ";
        $endorsementResult = $conn->query($endorsementSql);
        if (!$endorsementResult) {
            return [
                'success' => false,
                'context' => $context,
                'message' => 'Failed to load endorsement validation data.',
            ];
        }

        while ($endorsementRow = $endorsementResult->fetch_assoc()) {
            $programId = (int) ($endorsementRow['program_id'] ?? 0);
            $interviewId = (int) ($endorsementRow['interview_id'] ?? 0);
            if ($programId <= 0 || $interviewId <= 0) {
                continue;
            }

            if (!isset($context['endorsement_ids_by_program'][$programId])) {
                $context['endorsement_ids_by_program'][$programId] = [];
            }

            $context['endorsement_ids_by_program'][$programId][$interviewId] = true;
        }
        $endorsementResult->free();

        foreach ($context['rows_by_program'] as $programId => $groupedRows) {
            foreach (['REGULAR', 'ETG'] as $groupKey) {
                $rows = $groupedRows[$groupKey] ?? [];
                usort($rows, 'transfer_eligibility_compare_rows');
                $context['rows_by_program'][$programId][$groupKey] = $rows;
            }
        }

        return [
            'success' => true,
            'context' => $context,
            'message' => '',
        ];
    }
}

if (!function_exists('transfer_eligibility_get_pool_state')) {
    function transfer_eligibility_get_pool_state(array $context, int $programId, ?int $effectiveCutoff): array
    {
        $programId = (int) $programId;
        $regularRows = $context['rows_by_program'][$programId]['REGULAR'] ?? [];
        $etgRows = $context['rows_by_program'][$programId]['ETG'] ?? [];
        $endorsementIds = $context['endorsement_ids_by_program'][$programId] ?? [];

        $passingRowsByInterviewId = [];
        $passingRegularRows = [];
        $passingEtgRows = [];

        foreach ($regularRows as $row) {
            if ($effectiveCutoff !== null && (float) ($row['cutoff_basis_score'] ?? 0) < $effectiveCutoff) {
                continue;
            }
            $interviewId = (int) ($row['interview_id'] ?? 0);
            if ($interviewId > 0) {
                $passingRowsByInterviewId[$interviewId] = $row;
            }
            $passingRegularRows[] = $row;
        }

        foreach ($etgRows as $row) {
            if ($effectiveCutoff !== null && (float) ($row['cutoff_basis_score'] ?? 0) < $effectiveCutoff) {
                continue;
            }
            $interviewId = (int) ($row['interview_id'] ?? 0);
            if ($interviewId > 0) {
                $passingRowsByInterviewId[$interviewId] = $row;
            }
            $passingEtgRows[] = $row;
        }

        $filteredRegularRows = array_values(array_filter($passingRegularRows, static function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));
        $filteredEtgRows = array_values(array_filter($passingEtgRows, static function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));

        $endorsementCount = 0;
        foreach ($endorsementIds as $interviewId => $_) {
            if (isset($passingRowsByInterviewId[(int) $interviewId])) {
                $endorsementCount++;
            }
        }

        return [
            'regular_rows' => $filteredRegularRows,
            'etg_rows' => $filteredEtgRows,
            'endorsement_count' => $endorsementCount,
            'scored_total' => count($filteredRegularRows) + count($filteredEtgRows) + $endorsementCount,
        ];
    }
}

if (!function_exists('transfer_eligibility_evaluate')) {
    function transfer_eligibility_evaluate(mysqli $conn, int $interviewId, int $targetProgramId): array
    {
        $interviewId = max(0, (int) $interviewId);
        $targetProgramId = max(0, (int) $targetProgramId);

        if ($interviewId <= 0 || $targetProgramId <= 0) {
            return [
                'success' => true,
                'eligible' => false,
                'reason_code' => 'validation_unavailable',
                'message' => transfer_eligibility_reason_to_message('validation_unavailable'),
            ];
        }

        $candidateResult = transfer_eligibility_load_candidate($conn, $interviewId);
        if (!($candidateResult['success'] ?? false)) {
            return [
                'success' => false,
                'eligible' => false,
                'reason_code' => 'validation_unavailable',
                'message' => transfer_eligibility_reason_to_message('validation_unavailable'),
                'details' => $candidateResult,
            ];
        }

        $candidate = $candidateResult['row'] ?? null;
        if (!$candidate) {
            return [
                'success' => true,
                'eligible' => false,
                'reason_code' => 'student_not_found',
                'message' => transfer_eligibility_reason_to_message('student_not_found'),
            ];
        }

        $currentProgramId = (int) ($candidate['program_id'] ?? 0);
        if ($currentProgramId <= 0) {
            $currentProgramId = (int) ($candidate['first_choice'] ?? 0);
        }

        if ($currentProgramId > 0 && $currentProgramId === $targetProgramId) {
            return [
                'success' => true,
                'eligible' => false,
                'reason_code' => 'same_program',
                'message' => transfer_eligibility_reason_to_message('same_program'),
                'candidate' => $candidate,
            ];
        }

        $targetProgramResult = transfer_eligibility_load_target_program($conn, $targetProgramId);
        if (!($targetProgramResult['success'] ?? false)) {
            return [
                'success' => false,
                'eligible' => false,
                'reason_code' => 'validation_unavailable',
                'message' => transfer_eligibility_reason_to_message('validation_unavailable'),
                'details' => $targetProgramResult,
                'candidate' => $candidate,
            ];
        }

        $targetProgram = $targetProgramResult['row'] ?? null;
        if (!$targetProgram) {
            return [
                'success' => true,
                'eligible' => false,
                'reason_code' => 'target_program_not_found',
                'message' => transfer_eligibility_reason_to_message('target_program_not_found'),
                'candidate' => $candidate,
            ];
        }

        $contextResult = transfer_eligibility_build_context($conn);
        if (!($contextResult['success'] ?? false)) {
            return [
                'success' => false,
                'eligible' => false,
                'reason_code' => 'validation_unavailable',
                'message' => transfer_eligibility_reason_to_message('validation_unavailable'),
                'details' => $contextResult,
                'candidate' => $candidate,
                'target_program' => $targetProgram,
            ];
        }

        $context = (array) ($contextResult['context'] ?? []);
        $globalSatCutoffState = get_global_sat_cutoff_state($conn);
        $globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
        $globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;

        $targetCampusId = (int) ($targetProgram['campus_id'] ?? 0);
        $targetCapacity = ($targetProgram['absorptive_capacity'] !== null && $targetProgram['absorptive_capacity'] !== '')
            ? max(0, (int) $targetProgram['absorptive_capacity'])
            : null;
        $targetRawCutoff = ($targetProgram['cutoff_score'] !== null && $targetProgram['cutoff_score'] !== '')
            ? (int) $targetProgram['cutoff_score']
            : null;
        $targetEffectiveCutoff = get_effective_sat_cutoff($targetRawCutoff, $globalSatCutoffEnabled, $globalSatCutoffValue);
        $targetPoolState = transfer_eligibility_get_pool_state($context, $targetProgramId, $targetEffectiveCutoff);
        $targetScored = max(0, (int) ($targetPoolState['scored_total'] ?? 0));

        $targetRegularPercentage = ($targetProgram['regular_percentage'] !== null && $targetProgram['regular_percentage'] !== '')
            ? round((float) $targetProgram['regular_percentage'], 2)
            : null;
        $targetEtgPercentage = ($targetProgram['etg_percentage'] !== null && $targetProgram['etg_percentage'] !== '')
            ? round((float) $targetProgram['etg_percentage'], 2)
            : null;
        $targetEndorsementCapacity = max(0, (int) ($targetProgram['endorsement_capacity'] ?? 0));

        $classGroup = strtoupper(trim((string) ($candidate['class_group'] ?? 'REGULAR')));
        if ($classGroup !== 'ETG') {
            $classGroup = 'REGULAR';
        }

        $candidateRow = null;
        if ($candidate['final_score'] !== null && $candidate['final_score'] !== '') {
            $candidateRow = [
                'examinee_number' => (string) ($candidate['examinee_number'] ?? ''),
                'final_score' => (float) ($candidate['final_score'] ?? 0),
                'cutoff_basis_score' => (float) ($candidate['cutoff_basis_score'] ?? 0),
                'full_name' => trim((string) ($candidate['full_name'] ?? '')),
            ];
        }

        $targetQuotaConfigured = false;
        $targetRegularSlots = null;
        $targetEtgSlots = null;
        $targetSlotLimit = null;
        if (
            $targetCapacity !== null &&
            $targetRegularPercentage !== null &&
            $targetEtgPercentage !== null &&
            $targetRegularPercentage >= 0 &&
            $targetRegularPercentage <= 100 &&
            $targetEtgPercentage >= 0 &&
            $targetEtgPercentage <= 100 &&
            abs(($targetRegularPercentage + $targetEtgPercentage) - 100) <= 0.01
        ) {
            $targetBaseCapacity = max(0, $targetCapacity - $targetEndorsementCapacity);
            $targetRegularSlots = (int) round($targetBaseCapacity * ($targetRegularPercentage / 100));
            $targetEtgSlots = max(0, $targetBaseCapacity - $targetRegularSlots);
            $targetSlotLimit = ($classGroup === 'ETG') ? $targetEtgSlots : $targetRegularSlots;
            $targetQuotaConfigured = true;
        }

        $targetClassRows = ($classGroup === 'ETG')
            ? (array) ($targetPoolState['etg_rows'] ?? [])
            : (array) ($targetPoolState['regular_rows'] ?? []);
        $targetClassScored = count($targetClassRows);

        if ($targetCapacity !== null) {
            if ($targetQuotaConfigured && $targetSlotLimit !== null) {
                $targetAvailable = max(0, (int) $targetSlotLimit - $targetClassScored);
            } else {
                $targetAvailable = max(0, $targetCapacity - $targetScored);
            }
        } else {
            $targetAvailable = 0;
        }

        $targetProjectedRank = ($candidateRow !== null)
            ? transfer_eligibility_get_projected_rank($targetClassRows, $candidateRow)
            : null;
        $targetSatQualified = ($targetEffectiveCutoff !== null && $candidateRow !== null)
            ? ((float) ($candidateRow['cutoff_basis_score'] ?? 0) >= $targetEffectiveCutoff)
            : false;
        $targetRankQualified = false;
        if ($targetQuotaConfigured && $targetSlotLimit !== null) {
            $targetRankQualified = ($targetProjectedRank !== null && $targetProjectedRank <= $targetSlotLimit);
        } elseif ($targetCapacity !== null) {
            $targetRankQualified = ($targetAvailable > 0);
        }

        $reasonCode = '';
        if ($candidateRow === null) {
            $reasonCode = 'final_score_required';
        } elseif ($targetCampusId <= 0) {
            $reasonCode = 'campus_not_configured';
        } elseif ($targetCapacity === null) {
            $reasonCode = 'capacity_not_configured';
        } elseif ($targetEffectiveCutoff === null) {
            $reasonCode = 'cutoff_not_configured';
        } elseif (!$targetSatQualified) {
            $reasonCode = 'below_cutoff';
        } elseif (!$targetRankQualified) {
            $reasonCode = $targetQuotaConfigured ? 'outside_qualified_pool' : 'no_slots_available';
        }

        $eligible = ($reasonCode === '');

        return [
            'success' => true,
            'eligible' => $eligible,
            'reason_code' => $eligible ? 'eligible' : $reasonCode,
            'message' => $eligible ? '' : transfer_eligibility_reason_to_message($reasonCode),
            'candidate' => $candidate,
            'candidate_row' => $candidateRow,
            'target_program' => $targetProgram,
            'target_effective_cutoff' => $targetEffectiveCutoff,
            'target_quota_configured' => $targetQuotaConfigured,
            'target_slot_limit' => $targetSlotLimit,
            'target_available' => $targetAvailable,
            'target_projected_rank' => $targetProjectedRank,
            'target_sat_qualified' => $targetSatQualified,
            'target_rank_qualified' => $targetRankQualified,
            'target_pool_state' => $targetPoolState,
        ];
    }
}
