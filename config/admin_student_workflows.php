<?php
require_once __DIR__ . '/admin_student_management.php';

if (!function_exists('admin_student_workflows_get_details_csrf')) {
    function admin_student_workflows_get_details_csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['admin_student_details_csrf'])) {
            try {
                $_SESSION['admin_student_details_csrf'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['admin_student_details_csrf'] = sha1(uniqid('admin_student_details_csrf_', true));
            }
        }

        return (string) $_SESSION['admin_student_details_csrf'];
    }
}

if (!function_exists('admin_student_workflows_verify_details_csrf')) {
    function admin_student_workflows_verify_details_csrf(string $postedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = (string) ($_SESSION['admin_student_details_csrf'] ?? '');
        return $postedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $postedToken);
    }
}

if (!function_exists('admin_student_workflows_set_details_flash')) {
    function admin_student_workflows_set_details_flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $_SESSION['admin_student_details_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('admin_student_workflows_pop_details_flash')) {
    function admin_student_workflows_pop_details_flash(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $flash = $_SESSION['admin_student_details_flash'] ?? null;
        unset($_SESSION['admin_student_details_flash']);

        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('admin_student_workflows_get_scores_csrf')) {
    function admin_student_workflows_get_scores_csrf(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        if (empty($_SESSION['admin_student_scores_csrf'])) {
            try {
                $_SESSION['admin_student_scores_csrf'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['admin_student_scores_csrf'] = sha1(uniqid('admin_student_scores_csrf_', true));
            }
        }

        return (string) $_SESSION['admin_student_scores_csrf'];
    }
}

if (!function_exists('admin_student_workflows_verify_scores_csrf')) {
    function admin_student_workflows_verify_scores_csrf(string $postedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $sessionToken = (string) ($_SESSION['admin_student_scores_csrf'] ?? '');
        return $postedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $postedToken);
    }
}

if (!function_exists('admin_student_workflows_fetch_track_options')) {
    function admin_student_workflows_fetch_track_options(mysqli $conn): array
    {
        $options = [];
        $result = $conn->query("
            SELECT trackid, track
            FROM tb_ltrack
            ORDER BY track ASC
        ");

        if (!$result) {
            return $options;
        }

        while ($row = $result->fetch_assoc()) {
            $options[] = [
                'track_id' => (int) ($row['trackid'] ?? 0),
                'track_name' => trim((string) ($row['track'] ?? '')),
            ];
        }
        $result->free();

        return $options;
    }
}

if (!function_exists('admin_student_workflows_fetch_etg_class_options')) {
    function admin_student_workflows_fetch_etg_class_options(mysqli $conn): array
    {
        $options = [];
        $result = $conn->query("
            SELECT etgclassid, class_desc
            FROM tbl_etg_class
            ORDER BY class_desc ASC
        ");

        if (!$result) {
            return $options;
        }

        while ($row = $result->fetch_assoc()) {
            $options[] = [
                'etg_class_id' => (int) ($row['etgclassid'] ?? 0),
                'class_name' => trim((string) ($row['class_desc'] ?? '')),
            ];
        }
        $result->free();

        return $options;
    }
}

if (!function_exists('admin_student_workflows_program_exists')) {
    function admin_student_workflows_program_exists(array $programMap, int $programId): bool
    {
        return $programId > 0 && isset($programMap[$programId]);
    }
}

if (!function_exists('admin_student_workflows_track_exists')) {
    function admin_student_workflows_track_exists(mysqli $conn, int $trackId): bool
    {
        if ($trackId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT trackid
            FROM tb_ltrack
            WHERE trackid = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $trackId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $row;
    }
}

if (!function_exists('admin_student_workflows_etg_class_exists')) {
    function admin_student_workflows_etg_class_exists(mysqli $conn, int $etgClassId): bool
    {
        if ($etgClassId <= 0) {
            return false;
        }

        $stmt = $conn->prepare("
            SELECT etgclassid
            FROM tbl_etg_class
            WHERE etgclassid = ?
            LIMIT 1
        ");
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('i', $etgClassId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (bool) $row;
    }
}

if (!function_exists('admin_student_workflows_normalize_datetime_input')) {
    function admin_student_workflows_normalize_datetime_input(string $rawValue): ?string
    {
        $rawValue = trim($rawValue);
        if ($rawValue === '') {
            return null;
        }

        $formats = [
            'Y-m-d\TH:i',
            'Y-m-d\TH:i:s',
            'Y-m-d H:i',
            'Y-m-d H:i:s',
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $rawValue);
            if ($date instanceof DateTime) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $timestamp = strtotime($rawValue);
        if ($timestamp === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $timestamp);
    }
}

if (!function_exists('admin_student_workflows_is_esm_preferred_program')) {
    function admin_student_workflows_is_esm_preferred_program(string $preferredProgram): bool
    {
        $normalized = strtoupper(trim($preferredProgram));
        if ($normalized === '') {
            return false;
        }

        $simpleMatches = [
            'NURSING',
            'MIDWIFERY',
            'MEDICAL TECHNOLOGY',
            'ELECTRONICS ENGINEERING',
            'CIVIL ENGINEERING',
            'COMPUTER ENGINEERING',
            'COMPUTER SCIENCE',
            'FISHERIES',
            'BIOLOGY',
            'ACCOUNTANCY',
            'MANAGEMENT ACCOUNTING',
            'ACCOUNTING INFORMATION SYSTEMS',
            'MATHEMATICS EDUCATION',
            'SCIENCE EDUCATION',
        ];

        foreach ($simpleMatches as $match) {
            if (strpos($normalized, $match) !== false) {
                return true;
            }
        }

        return (
            strpos($normalized, 'SECONDARY EDUCATION') !== false
            && (
                strpos($normalized, 'MATHEMATICS') !== false
                || strpos($normalized, 'SCIENCE') !== false
            )
        );
    }
}

if (!function_exists('admin_student_workflows_normalize_component_key')) {
    function admin_student_workflows_normalize_component_key(string $componentName): string
    {
        $normalized = strtoupper(trim($componentName));
        $sanitized = preg_replace('/[^A-Z0-9]+/', '', $normalized);

        return $sanitized !== null ? $sanitized : '';
    }
}

if (!function_exists('admin_student_workflows_is_sat_component')) {
    function admin_student_workflows_is_sat_component(string $componentName): bool
    {
        return admin_student_workflows_normalize_component_key($componentName) === 'SAT';
    }
}

if (!function_exists('admin_student_workflows_get_effective_component_weight')) {
    function admin_student_workflows_get_effective_component_weight(
        string $componentName,
        float $defaultWeight,
        bool $isEtgStudent
    ): float {
        if (!$isEtgStudent) {
            return $defaultWeight;
        }

        $key = admin_student_workflows_normalize_component_key($componentName);
        if ($key === 'SAT') {
            return 50.0;
        }
        if ($key === 'GENERALAVERAGE') {
            return 30.0;
        }
        if ($key === 'INTERVIEW') {
            return 5.0;
        }

        return 0.0;
    }
}

if (!function_exists('admin_student_workflows_prepare_components_for_student')) {
    function admin_student_workflows_prepare_components_for_student(array $components, bool $isEtgStudent): array
    {
        $prepared = [];
        foreach ($components as $component) {
            $effectiveWeight = admin_student_workflows_get_effective_component_weight(
                (string) ($component['component_name'] ?? ''),
                (float) ($component['weight_percent'] ?? 0),
                $isEtgStudent
            );

            if ($isEtgStudent && $effectiveWeight <= 0) {
                continue;
            }

            $component['effective_weight_percent'] = $effectiveWeight;
            $prepared[] = $component;
        }

        return $prepared;
    }
}

if (!function_exists('admin_student_workflows_pick_auto_placement_score')) {
    function admin_student_workflows_pick_auto_placement_score(
        int $satScore,
        ?int $esmScore,
        ?int $overallScore,
        ?string $preferredProgram
    ): float {
        if (admin_student_workflows_is_esm_preferred_program((string) $preferredProgram)) {
            return (float) ($esmScore !== null ? $esmScore : $satScore);
        }

        return (float) ($overallScore !== null ? $overallScore : $satScore);
    }
}

if (!function_exists('admin_student_workflows_load_active_scoring_components')) {
    function admin_student_workflows_load_active_scoring_components(mysqli $conn): array
    {
        $rows = [];
        $result = $conn->query("
            SELECT component_id, component_name, max_score, weight_percent, is_auto_computed
            FROM tbl_scoring_components
            WHERE UPPER(status) = 'ACTIVE'
            ORDER BY component_id ASC
        ");

        if (!$result) {
            return $rows;
        }

        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
        $result->free();

        return $rows;
    }
}

if (!function_exists('admin_student_workflows_build_lock_message')) {
    function admin_student_workflows_build_lock_message(?array $lockContext): string
    {
        if ($lockContext === null) {
            return '';
        }

        $lockedRank = (int) ($lockContext['locked_rank'] ?? 0);
        $lockProgram = trim((string) ($lockContext['program_name'] ?? ''));
        $lockMajor = trim((string) ($lockContext['major'] ?? ''));
        if ($lockProgram !== '' && $lockMajor !== '') {
            $lockProgram .= ' - ' . $lockMajor;
        }

        $lockMessage = 'This ranking record is locked';
        if ($lockedRank > 0) {
            $lockMessage .= ' at rank #' . $lockedRank;
        }
        if ($lockProgram !== '') {
            $lockMessage .= ' for ' . strtoupper($lockProgram);
        }

        return $lockMessage . '.';
    }
}

if (!function_exists('admin_student_workflows_fetch_scoring_context')) {
    function admin_student_workflows_fetch_scoring_context(mysqli $conn, int $interviewId): ?array
    {
        if ($interviewId <= 0) {
            return null;
        }

        $sql = "
            SELECT
                si.interview_id,
                si.placement_result_id,
                si.program_chair_id,
                si.first_choice,
                si.program_id,
                si.classification,
                si.final_score,
                si.status AS interview_status,
                pr.sat_score,
                pr.preferred_program,
                pr.overall_standard_score,
                pr.esm_competency_standard_score,
                pr.full_name,
                pr.examinee_number,
                p.program_code,
                p.program_name,
                p.major
            FROM tbl_student_interview si
            INNER JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            LEFT JOIN tbl_program p
                ON p.program_id = COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0))
            WHERE si.interview_id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }

        $stmt->bind_param('i', $interviewId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            return null;
        }

        $classification = strtoupper(trim((string) ($row['classification'] ?? 'REGULAR')));
        $classification = ($classification === 'ETG') ? 'ETG' : 'REGULAR';
        $isEtgStudent = ($classification === 'ETG');
        $satScoreValue = (int) ($row['sat_score'] ?? 0);
        $overallStandardScore = ($row['overall_standard_score'] !== null && $row['overall_standard_score'] !== '')
            ? (int) $row['overall_standard_score']
            : null;
        $esmStandardScore = ($row['esm_competency_standard_score'] !== null && $row['esm_competency_standard_score'] !== '')
            ? (int) $row['esm_competency_standard_score']
            : null;
        $preferredProgram = trim((string) ($row['preferred_program'] ?? ''));
        $isEsmPreferredProgram = admin_student_workflows_is_esm_preferred_program($preferredProgram);
        $placementScoreSourceLabel = $isEsmPreferredProgram ? 'ESM' : 'Overall Standard Score';
        $placementScoreFromPlacement = admin_student_workflows_pick_auto_placement_score(
            $satScoreValue,
            $esmStandardScore,
            $overallStandardScore,
            $preferredProgram
        );
        $programLabel = admin_student_management_format_program_label($row);
        $lockContext = program_ranking_get_interview_lock_context($conn, $interviewId);

        return [
            'interview_id' => (int) ($row['interview_id'] ?? 0),
            'placement_result_id' => (int) ($row['placement_result_id'] ?? 0),
            'program_chair_id' => (int) ($row['program_chair_id'] ?? 0),
            'first_choice' => (int) ($row['first_choice'] ?? 0),
            'program_id' => (int) ($row['program_id'] ?? 0),
            'classification' => $classification,
            'is_etg_student' => $isEtgStudent,
            'final_score' => $row['final_score'] !== null ? (float) $row['final_score'] : null,
            'interview_status' => (string) ($row['interview_status'] ?? ''),
            'student_name' => (string) ($row['full_name'] ?? ''),
            'examinee_number' => (string) ($row['examinee_number'] ?? ''),
            'preferred_program' => $preferredProgram,
            'program_label' => $programLabel,
            'sat_score' => $satScoreValue,
            'overall_standard_score' => $overallStandardScore,
            'esm_competency_standard_score' => $esmStandardScore,
            'is_esm_preferred_program' => $isEsmPreferredProgram,
            'placement_score_source_label' => $placementScoreSourceLabel,
            'placement_score_from_placement' => $placementScoreFromPlacement,
            'lock_context' => $lockContext,
            'lock_message' => admin_student_workflows_build_lock_message($lockContext),
        ];
    }
}

if (!function_exists('admin_student_workflows_load_saved_scores')) {
    function admin_student_workflows_load_saved_scores(mysqli $conn, int $interviewId): array
    {
        $savedScores = [];
        if ($interviewId <= 0) {
            return $savedScores;
        }

        $stmt = $conn->prepare("
            SELECT component_id, raw_score, weighted_score
            FROM tbl_interview_scores
            WHERE interview_id = ?
        ");
        if (!$stmt) {
            return $savedScores;
        }

        $stmt->bind_param('i', $interviewId);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($result && $row = $result->fetch_assoc()) {
            $componentId = (int) ($row['component_id'] ?? 0);
            if ($componentId <= 0) {
                continue;
            }

            $savedScores[$componentId] = [
                'raw_score' => (float) ($row['raw_score'] ?? 0),
                'weighted_score' => (float) ($row['weighted_score'] ?? 0),
            ];
        }
        $stmt->close();

        return $savedScores;
    }
}

if (!function_exists('admin_student_workflows_build_scoring_payload')) {
    function admin_student_workflows_build_scoring_payload(mysqli $conn, int $interviewId): ?array
    {
        $context = admin_student_workflows_fetch_scoring_context($conn, $interviewId);
        if (!$context) {
            return null;
        }

        $components = admin_student_workflows_prepare_components_for_student(
            admin_student_workflows_load_active_scoring_components($conn),
            (bool) ($context['is_etg_student'] ?? false)
        );
        $savedScores = admin_student_workflows_load_saved_scores($conn, $interviewId);
        $totalWeight = 0.0;
        foreach ($components as $component) {
            $totalWeight += (float) ($component['effective_weight_percent'] ?? 0);
        }

        return [
            'context' => $context,
            'components' => $components,
            'saved_scores' => $savedScores,
            'total_weight' => $totalWeight,
        ];
    }
}

if (!function_exists('admin_student_workflows_sync_interview_scores')) {
    function admin_student_workflows_sync_interview_scores(mysqli $conn, int $interviewId): array
    {
        $payload = admin_student_workflows_build_scoring_payload($conn, $interviewId);
        if (!$payload) {
            return [
                'success' => false,
                'message' => 'Interview scoring context was not found.',
            ];
        }

        $context = (array) ($payload['context'] ?? []);
        $components = (array) ($payload['components'] ?? []);
        if (empty($components)) {
            return [
                'success' => true,
                'updated' => false,
                'final_score' => $context['final_score'] ?? null,
            ];
        }

        $existingScores = [];
        $lookupStmt = $conn->prepare("
            SELECT score_id, component_id, raw_score
            FROM tbl_interview_scores
            WHERE interview_id = ?
        ");
        if (!$lookupStmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare score lookup.',
            ];
        }

        $lookupStmt->bind_param('i', $interviewId);
        $lookupStmt->execute();
        $lookupResult = $lookupStmt->get_result();
        while ($lookupResult && $row = $lookupResult->fetch_assoc()) {
            $componentId = (int) ($row['component_id'] ?? 0);
            if ($componentId <= 0) {
                continue;
            }

            $existingScores[$componentId] = [
                'score_id' => (int) ($row['score_id'] ?? 0),
                'raw_score' => (float) ($row['raw_score'] ?? 0),
            ];
        }
        $lookupStmt->close();

        if (empty($existingScores)) {
            return [
                'success' => true,
                'updated' => false,
                'final_score' => $context['final_score'] ?? null,
            ];
        }

        $updateAutoStmt = $conn->prepare("
            UPDATE tbl_interview_scores
            SET raw_score = ?, weighted_score = ?
            WHERE score_id = ?
        ");
        $updateWeightedStmt = $conn->prepare("
            UPDATE tbl_interview_scores
            SET weighted_score = ?
            WHERE score_id = ?
        ");
        $insertAutoStmt = $conn->prepare("
            INSERT INTO tbl_interview_scores (interview_id, component_id, raw_score, weighted_score)
            VALUES (?, ?, ?, ?)
        ");
        $updateFinalStmt = $conn->prepare("
            UPDATE tbl_student_interview
            SET final_score = ?
            WHERE interview_id = ?
        ");

        if (!$updateAutoStmt || !$updateWeightedStmt || !$insertAutoStmt || !$updateFinalStmt) {
            foreach ([$updateAutoStmt, $updateWeightedStmt, $insertAutoStmt, $updateFinalStmt] as $stmt) {
                if ($stmt instanceof mysqli_stmt) {
                    $stmt->close();
                }
            }

            return [
                'success' => false,
                'message' => 'Failed to prepare score synchronization.',
            ];
        }

        $placementScore = (float) ($context['placement_score_from_placement'] ?? 0.0);
        $isEtgStudent = (bool) ($context['is_etg_student'] ?? false);
        $totalFinalScore = 0.0;

        foreach ($components as $component) {
            $componentId = (int) ($component['component_id'] ?? 0);
            if ($componentId <= 0) {
                continue;
            }

            $maxScore = (float) ($component['max_score'] ?? 0);
            if ($maxScore <= 0) {
                continue;
            }

            $weight = (float) ($component['effective_weight_percent'] ?? 0);
            $isAuto = ((int) ($component['is_auto_computed'] ?? 0) === 1)
                || admin_student_workflows_is_sat_component((string) ($component['component_name'] ?? ''));
            $existing = $existingScores[$componentId] ?? null;
            $rawScore = $isAuto
                ? $placementScore
                : (float) ($existing['raw_score'] ?? 0.0);
            $weightedScore = ($rawScore / $maxScore) * $weight;
            $totalFinalScore += $weightedScore;

            if ($existing) {
                $scoreId = (int) ($existing['score_id'] ?? 0);
                if ($scoreId > 0 && $isAuto) {
                    $updateAutoStmt->bind_param('ddi', $rawScore, $weightedScore, $scoreId);
                    $updateAutoStmt->execute();
                } elseif ($scoreId > 0) {
                    $updateWeightedStmt->bind_param('di', $weightedScore, $scoreId);
                    $updateWeightedStmt->execute();
                }
                continue;
            }

            if ($isAuto) {
                $insertAutoStmt->bind_param('iidd', $interviewId, $componentId, $rawScore, $weightedScore);
                $insertAutoStmt->execute();
            }
        }

        if ($isEtgStudent) {
            $totalFinalScore += 15.0;
        }

        $updateFinalStmt->bind_param('di', $totalFinalScore, $interviewId);
        $updateFinalStmt->execute();

        $updateAutoStmt->close();
        $updateWeightedStmt->close();
        $insertAutoStmt->close();
        $updateFinalStmt->close();

        return [
            'success' => true,
            'updated' => true,
            'final_score' => $totalFinalScore,
        ];
    }
}

if (!function_exists('admin_student_workflows_save_student_details')) {
    function admin_student_workflows_save_student_details(mysqli $conn, array $input): array
    {
        $placementResultId = max(0, (int) ($input['placement_result_id'] ?? 0));
        $interviewId = max(0, (int) ($input['interview_id'] ?? 0));
        $classification = strtoupper(trim((string) ($input['classification'] ?? 'REGULAR')));
        $etgClassId = (int) ($input['etg_class_id'] ?? 0);
        $mobileNumber = trim((string) ($input['mobile_number'] ?? ''));
        $firstChoice = max(0, (int) ($input['first_choice'] ?? 0));
        $secondChoice = max(0, (int) ($input['second_choice'] ?? 0));
        $thirdChoice = max(0, (int) ($input['third_choice'] ?? 0));
        $shsTrackId = max(0, (int) ($input['shs_track_id'] ?? 0));
        $interviewDatetimeRaw = (string) ($input['interview_datetime'] ?? '');

        if ($placementResultId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid student record.',
            ];
        }

        $student = admin_student_management_fetch_student_record($conn, [
            'placement_result_id' => $placementResultId,
        ]);
        if (!$student) {
            return [
                'success' => false,
                'message' => 'Student record was not found.',
            ];
        }

        $existingInterviewId = (int) ($student['interview_id'] ?? 0);
        if ($interviewId > 0 && $existingInterviewId > 0 && $interviewId !== $existingInterviewId) {
            return [
                'success' => false,
                'message' => 'Interview reference does not match the latest active record.',
            ];
        }

        $normalizedInterviewId = $existingInterviewId > 0 ? $existingInterviewId : $interviewId;
        if ($normalizedInterviewId > 0 && program_ranking_is_interview_locked($conn, $normalizedInterviewId)) {
            return [
                'success' => false,
                'message' => admin_student_workflows_build_lock_message(
                    program_ranking_get_interview_lock_context($conn, $normalizedInterviewId)
                ) ?: 'This interview is locked in program ranking and cannot be edited.',
            ];
        }

        if (!in_array($classification, ['REGULAR', 'ETG'], true)) {
            return [
                'success' => false,
                'message' => 'Invalid classification.',
            ];
        }

        if (!preg_match('/^0\d{10}$/', $mobileNumber)) {
            return [
                'success' => false,
                'message' => 'Mobile number must be 11 digits and start with 0.',
            ];
        }

        $programOptions = admin_student_management_fetch_program_options($conn);
        $programMap = [];
        foreach ($programOptions as $programOption) {
            $programMap[(int) ($programOption['program_id'] ?? 0)] = $programOption;
        }

        if (!admin_student_workflows_program_exists($programMap, $firstChoice)) {
            return [
                'success' => false,
                'message' => 'First choice program is invalid.',
            ];
        }
        if (!admin_student_workflows_program_exists($programMap, $secondChoice)) {
            return [
                'success' => false,
                'message' => 'Second choice program is invalid.',
            ];
        }
        if (!admin_student_workflows_program_exists($programMap, $thirdChoice)) {
            return [
                'success' => false,
                'message' => 'Third choice program is invalid.',
            ];
        }
        if ($shsTrackId <= 0 || !admin_student_workflows_track_exists($conn, $shsTrackId)) {
            return [
                'success' => false,
                'message' => 'SHS track is invalid.',
            ];
        }

        if ($classification === 'ETG') {
            if ($etgClassId <= 0 || !admin_student_workflows_etg_class_exists($conn, $etgClassId)) {
                return [
                    'success' => false,
                    'message' => 'ETG classification is required for ETG students.',
                ];
            }
        } elseif ($etgClassId > 0 && !admin_student_workflows_etg_class_exists($conn, $etgClassId)) {
            return [
                'success' => false,
                'message' => 'Selected ETG classification does not exist.',
            ];
        } else {
            $etgClassId = 0;
        }

        $normalizedInterviewDatetime = admin_student_workflows_normalize_datetime_input($interviewDatetimeRaw);
        if (trim($interviewDatetimeRaw) !== '' && $normalizedInterviewDatetime === null) {
            return [
                'success' => false,
                'message' => 'Interview date/time is invalid.',
            ];
        }

        $firstChoiceProgram = $programMap[$firstChoice];
        $programChairId = max(0, (int) ($firstChoiceProgram['owner_accountid'] ?? 0));
        $campusId = max(0, (int) ($firstChoiceProgram['campus_id'] ?? 0));
        if ($campusId <= 0) {
            return [
                'success' => false,
                'message' => 'Selected first choice does not have a valid campus.',
            ];
        }

        $placementResultId = (int) ($student['placement_result_id'] ?? 0);
        $examineeNumber = trim((string) ($student['examinee_number'] ?? ''));
        if ($placementResultId <= 0 || $examineeNumber === '') {
            return [
                'success' => false,
                'message' => 'Student placement information is incomplete.',
            ];
        }

        $isInsert = ($normalizedInterviewId <= 0);
        $scoreSync = [
            'success' => true,
            'updated' => false,
        ];

        $conn->begin_transaction();
        try {
            if ($isInsert) {
                $insertSql = "
                    INSERT INTO tbl_student_interview (
                        placement_result_id,
                        examinee_number,
                        program_chair_id,
                        campus_id,
                        program_id,
                        classification,
                        etg_class_id,
                        mobile_number,
                        first_choice,
                        second_choice,
                        third_choice,
                        shs_track_id,
                        interview_datetime
                    )
                    VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, 0), ?, ?, ?, ?, ?, NULLIF(?, ''))
                ";
                $insertStmt = $conn->prepare($insertSql);
                if (!$insertStmt) {
                    throw new RuntimeException('Failed to prepare interview creation.');
                }

                $insertStmt->bind_param(
                    'isiiisisiiiis',
                    $placementResultId,
                    $examineeNumber,
                    $programChairId,
                    $campusId,
                    $firstChoice,
                    $classification,
                    $etgClassId,
                    $mobileNumber,
                    $firstChoice,
                    $secondChoice,
                    $thirdChoice,
                    $shsTrackId,
                    $normalizedInterviewDatetime
                );
                if (!$insertStmt->execute()) {
                    $insertStmt->close();
                    throw new RuntimeException('Failed to create interview record.');
                }
                $normalizedInterviewId = (int) $conn->insert_id;
                $insertStmt->close();
            } else {
                $updateSql = "
                    UPDATE tbl_student_interview
                    SET
                        program_chair_id = ?,
                        campus_id = ?,
                        program_id = ?,
                        classification = ?,
                        etg_class_id = NULLIF(?, 0),
                        mobile_number = ?,
                        first_choice = ?,
                        second_choice = ?,
                        third_choice = ?,
                        shs_track_id = ?,
                        interview_datetime = NULLIF(?, '')
                    WHERE interview_id = ?
                    LIMIT 1
                ";
                $updateStmt = $conn->prepare($updateSql);
                if (!$updateStmt) {
                    throw new RuntimeException('Failed to prepare interview update.');
                }

                $updateStmt->bind_param(
                    'iiisisiiiisi',
                    $programChairId,
                    $campusId,
                    $firstChoice,
                    $classification,
                    $etgClassId,
                    $mobileNumber,
                    $firstChoice,
                    $secondChoice,
                    $thirdChoice,
                    $shsTrackId,
                    $normalizedInterviewDatetime,
                    $normalizedInterviewId
                );
                if (!$updateStmt->execute()) {
                    $updateStmt->close();
                    throw new RuntimeException('Failed to update interview record.');
                }
                $updateStmt->close();
            }

            $credentialResult = provision_student_credentials(
                $conn,
                $placementResultId,
                $normalizedInterviewId,
                $examineeNumber,
                $isInsert
            );
            if (!($credentialResult['success'] ?? false)) {
                throw new RuntimeException((string) ($credentialResult['message'] ?? 'Failed to provision student credentials.'));
            }

            $scoreSync = admin_student_workflows_sync_interview_scores($conn, $normalizedInterviewId);
            if (!($scoreSync['success'] ?? false)) {
                throw new RuntimeException((string) ($scoreSync['message'] ?? 'Failed to synchronize interview scores.'));
            }

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        $message = $isInsert
            ? 'Interview details created successfully.'
            : 'Interview details updated successfully.';
        if (!empty($scoreSync['updated'])) {
            $message .= ' Existing score weights were recomputed using the updated student details.';
        }

        return [
            'success' => true,
            'message' => $message,
            'interview_id' => $normalizedInterviewId,
            'placement_result_id' => $placementResultId,
        ];
    }
}

if (!function_exists('admin_student_workflows_save_interview_scores')) {
    function admin_student_workflows_save_interview_scores(mysqli $conn, array $input): array
    {
        $interviewId = max(0, (int) ($input['interview_id'] ?? 0));
        $actorAccountId = max(0, (int) ($input['actor_account_id'] ?? 0));
        $postedRawScores = isset($input['raw_score']) && is_array($input['raw_score'])
            ? $input['raw_score']
            : null;

        if ($interviewId <= 0 || $actorAccountId <= 0 || $postedRawScores === null) {
            return [
                'success' => false,
                'message' => 'Invalid score submission.',
            ];
        }

        $payload = admin_student_workflows_build_scoring_payload($conn, $interviewId);
        if (!$payload) {
            return [
                'success' => false,
                'message' => 'Interview scoring context was not found.',
            ];
        }

        $context = (array) ($payload['context'] ?? []);
        if (($context['lock_context'] ?? null) !== null) {
            return [
                'success' => false,
                'message' => (string) ($context['lock_message'] ?? 'This ranking record is locked and cannot be updated.'),
                'error_code' => 'locked',
            ];
        }

        $components = (array) ($payload['components'] ?? []);
        $componentsById = [];
        foreach ($components as $component) {
            $componentId = (int) ($component['component_id'] ?? 0);
            if ($componentId > 0) {
                $componentsById[$componentId] = $component;
            }
        }

        $totalFinalScore = 0.0;
        $conn->begin_transaction();
        try {
            foreach ($componentsById as $componentId => $component) {
                $rawScoreInput = array_key_exists($componentId, $postedRawScores)
                    ? $postedRawScores[$componentId]
                    : '';
                $rawScore = trim((string) $rawScoreInput);
                $rawScore = ($rawScore === '') ? 0.0 : (float) $rawScore;
                $maxScore = (float) ($component['max_score'] ?? 0);
                if ($maxScore <= 0) {
                    continue;
                }

                $weight = (float) ($component['effective_weight_percent'] ?? $component['weight_percent'] ?? 0);
                $isAuto = ((int) ($component['is_auto_computed'] ?? 0) === 1)
                    || admin_student_workflows_is_sat_component((string) ($component['component_name'] ?? ''));

                if ($isAuto) {
                    $rawScore = (float) ($context['placement_score_from_placement'] ?? 0.0);
                }

                if (!$isAuto && $rawScore > $maxScore) {
                    $conn->rollback();
                    return [
                        'success' => false,
                        'message' => 'One or more scores exceeded the allowed maximum.',
                        'error_code' => 'invalid_score',
                    ];
                }
                if (!$isAuto && $rawScore < 0) {
                    $rawScore = 0.0;
                }

                $weightedScore = ($rawScore / $maxScore) * $weight;
                $totalFinalScore += $weightedScore;

                $checkStmt = $conn->prepare("
                    SELECT score_id, raw_score, weighted_score
                    FROM tbl_interview_scores
                    WHERE interview_id = ?
                      AND component_id = ?
                    LIMIT 1
                ");
                if (!$checkStmt) {
                    throw new RuntimeException('Failed to check existing component score.');
                }

                $checkStmt->bind_param('ii', $interviewId, $componentId);
                $checkStmt->execute();
                $existingRow = $checkStmt->get_result()->fetch_assoc();
                $checkStmt->close();

                $oldRaw = $existingRow ? (float) ($existingRow['raw_score'] ?? 0) : null;
                $oldWeighted = $existingRow ? (float) ($existingRow['weighted_score'] ?? 0) : null;

                if ($existingRow) {
                    $updateStmt = $conn->prepare("
                        UPDATE tbl_interview_scores
                        SET raw_score = ?, weighted_score = ?
                        WHERE interview_id = ?
                          AND component_id = ?
                    ");
                    if (!$updateStmt) {
                        throw new RuntimeException('Failed to update component score.');
                    }

                    $updateStmt->bind_param('ddii', $rawScore, $weightedScore, $interviewId, $componentId);
                    $updateStmt->execute();
                    $updateStmt->close();
                } else {
                    $insertStmt = $conn->prepare("
                        INSERT INTO tbl_interview_scores (interview_id, component_id, raw_score, weighted_score)
                        VALUES (?, ?, ?, ?)
                    ");
                    if (!$insertStmt) {
                        throw new RuntimeException('Failed to insert component score.');
                    }

                    $insertStmt->bind_param('iidd', $interviewId, $componentId, $rawScore, $weightedScore);
                    $insertStmt->execute();
                    $insertStmt->close();
                }

                $action = ($oldRaw === null) ? 'SCORE_SAVE' : 'SCORE_UPDATE';
                $auditStmt = $conn->prepare("
                    INSERT INTO tbl_score_audit_logs
                    (
                        interview_id,
                        component_id,
                        actor_accountid,
                        action,
                        old_raw,
                        new_raw,
                        old_weighted,
                        new_weighted,
                        ip_address,
                        user_agent
                    )
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$auditStmt) {
                    throw new RuntimeException('Failed to write score audit log.');
                }

                $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
                $auditStmt->bind_param(
                    'iiissdddss',
                    $interviewId,
                    $componentId,
                    $actorAccountId,
                    $action,
                    $oldRaw,
                    $rawScore,
                    $oldWeighted,
                    $weightedScore,
                    $ipAddress,
                    $userAgent
                );
                $auditStmt->execute();
                $auditStmt->close();
            }

            if (!empty($context['is_etg_student'])) {
                $totalFinalScore += 15.0;
            }

            $finalBefore = null;
            $finalLookupStmt = $conn->prepare("
                SELECT final_score
                FROM tbl_student_interview
                WHERE interview_id = ?
                LIMIT 1
            ");
            if (!$finalLookupStmt) {
                throw new RuntimeException('Failed to load previous final score.');
            }

            $finalLookupStmt->bind_param('i', $interviewId);
            $finalLookupStmt->execute();
            $finalRow = $finalLookupStmt->get_result()->fetch_assoc();
            $finalLookupStmt->close();
            if ($finalRow && $finalRow['final_score'] !== null) {
                $finalBefore = (float) $finalRow['final_score'];
            }

            $finalUpdateStmt = $conn->prepare("
                UPDATE tbl_student_interview
                SET final_score = ?
                WHERE interview_id = ?
            ");
            if (!$finalUpdateStmt) {
                throw new RuntimeException('Failed to update final score.');
            }

            $finalUpdateStmt->bind_param('di', $totalFinalScore, $interviewId);
            $finalUpdateStmt->execute();
            $finalUpdateStmt->close();

            $finalAuditStmt = $conn->prepare("
                INSERT INTO tbl_score_audit_logs
                (
                    interview_id,
                    component_id,
                    actor_accountid,
                    action,
                    final_before,
                    final_after,
                    ip_address,
                    user_agent
                )
                VALUES (?, NULL, ?, 'FINAL_SCORE_UPDATE', ?, ?, ?, ?)
            ");
            if (!$finalAuditStmt) {
                throw new RuntimeException('Failed to write final-score audit log.');
            }

            $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $finalAuditStmt->bind_param(
                'iiddss',
                $interviewId,
                $actorAccountId,
                $finalBefore,
                $totalFinalScore,
                $ipAddress,
                $userAgent
            );
            $finalAuditStmt->execute();
            $finalAuditStmt->close();

            $conn->commit();
        } catch (Throwable $e) {
            $conn->rollback();

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }

        return [
            'success' => true,
            'message' => 'Scores saved successfully.',
            'final_score' => $totalFinalScore,
        ];
    }
}
