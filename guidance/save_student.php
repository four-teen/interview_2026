<?php
require_once 'bootstrap.php';
guidance_require_access();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    guidance_redirect_students();
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

function guidance_parse_required_int(string $value, string $label, array &$errors): ?int
{
    $value = trim($value);
    if ($value === '') {
        $errors[] = $label . ' is required.';
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || $validated < 0) {
        $errors[] = $label . ' must be a non-negative whole number.';
        return null;
    }

    return (int) $validated;
}

function guidance_parse_optional_int(string $value, string $label, array &$errors): ?int
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $validated = filter_var($value, FILTER_VALIDATE_INT);
    if ($validated === false || $validated < 0) {
        $errors[] = $label . ' must be a non-negative whole number.';
        return null;
    }

    return (int) $validated;
}

function guidance_normalize_component_key(string $componentName): string
{
    $normalized = strtoupper(trim($componentName));
    $sanitized = preg_replace('/[^A-Z0-9]+/', '', $normalized);
    return $sanitized !== null ? $sanitized : '';
}

function guidance_is_sat_component(string $componentName): bool
{
    return guidance_normalize_component_key($componentName) === 'SAT';
}

function guidance_is_etg_classification(string $classification): bool
{
    $normalized = strtoupper(trim($classification));
    return strpos($normalized, 'ETG') === 0;
}

function guidance_get_effective_component_weight(string $componentName, float $defaultWeight, bool $isEtgStudent): float
{
    if (!$isEtgStudent) {
        return $defaultWeight;
    }

    $key = guidance_normalize_component_key($componentName);
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

function guidance_load_active_scoring_components(mysqli $conn): array
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

    return $rows;
}

function guidance_prepare_components_for_interview(array $baseComponents, bool $isEtgStudent): array
{
    $prepared = [];
    foreach ($baseComponents as $component) {
        $effectiveWeight = guidance_get_effective_component_weight(
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

function guidance_pick_auto_placement_score(
    int $satScore,
    ?int $esmScore,
    ?int $overallScore,
    ?string $preferredProgram
): float {
    if (guidance_is_esm_preferred_program((string) $preferredProgram)) {
        return (float) ($esmScore !== null ? $esmScore : $satScore);
    }

    return (float) ($overallScore !== null ? $overallScore : $satScore);
}

function guidance_sync_interview_scores_after_placement_edit(
    mysqli $conn,
    int $placementResultId,
    float $placementScoreFromPlacement,
    array $baseComponents
): array {
    $updatedInterviews = 0;
    $skippedInterviews = 0;

    $interviewStmt = $conn->prepare("
        SELECT
            si.interview_id,
            si.classification
        FROM tbl_student_interview si
        WHERE si.placement_result_id = ?
          AND si.status = 'active'
    ");
    if (!$interviewStmt) {
        throw new RuntimeException('Failed to prepare interview lookup for score sync.');
    }
    $interviewStmt->bind_param('i', $placementResultId);
    $interviewStmt->execute();
    $interviewResult = $interviewStmt->get_result();
    $interviews = [];
    while ($interviewResult && $row = $interviewResult->fetch_assoc()) {
        $interviews[] = $row;
    }
    $interviewStmt->close();

    if (empty($interviews) || empty($baseComponents)) {
        return [
            'updated_interviews' => 0,
            'skipped_interviews' => 0
        ];
    }

    $scoreLookupStmt = $conn->prepare("
        SELECT score_id, component_id, raw_score
        FROM tbl_interview_scores
        WHERE interview_id = ?
    ");
    if (!$scoreLookupStmt) {
        throw new RuntimeException('Failed to prepare score lookup for guidance sync.');
    }

    $updateAutoStmt = $conn->prepare("
        UPDATE tbl_interview_scores
        SET raw_score = ?, weighted_score = ?
        WHERE score_id = ?
    ");
    if (!$updateAutoStmt) {
        $scoreLookupStmt->close();
        throw new RuntimeException('Failed to prepare auto-score update for guidance sync.');
    }

    $updateWeightedStmt = $conn->prepare("
        UPDATE tbl_interview_scores
        SET weighted_score = ?
        WHERE score_id = ?
    ");
    if (!$updateWeightedStmt) {
        $scoreLookupStmt->close();
        $updateAutoStmt->close();
        throw new RuntimeException('Failed to prepare weighted-score update for guidance sync.');
    }

    $insertAutoStmt = $conn->prepare("
        INSERT INTO tbl_interview_scores (interview_id, component_id, raw_score, weighted_score)
        VALUES (?, ?, ?, ?)
    ");
    if (!$insertAutoStmt) {
        $scoreLookupStmt->close();
        $updateAutoStmt->close();
        $updateWeightedStmt->close();
        throw new RuntimeException('Failed to prepare auto-score insert for guidance sync.');
    }

    $updateFinalStmt = $conn->prepare("
        UPDATE tbl_student_interview
        SET final_score = ?
        WHERE interview_id = ?
    ");
    if (!$updateFinalStmt) {
        $scoreLookupStmt->close();
        $updateAutoStmt->close();
        $updateWeightedStmt->close();
        $insertAutoStmt->close();
        throw new RuntimeException('Failed to prepare final score update for guidance sync.');
    }

    foreach ($interviews as $interview) {
        $interviewId = (int) ($interview['interview_id'] ?? 0);
        if ($interviewId <= 0) {
            continue;
        }

        $scoreLookupStmt->bind_param('i', $interviewId);
        $scoreLookupStmt->execute();
        $scoreResult = $scoreLookupStmt->get_result();

        $existingScores = [];
        while ($scoreResult && $scoreRow = $scoreResult->fetch_assoc()) {
            $componentId = (int) ($scoreRow['component_id'] ?? 0);
            if ($componentId <= 0) {
                continue;
            }
            $existingScores[$componentId] = [
                'score_id' => (int) ($scoreRow['score_id'] ?? 0),
                'raw_score' => (float) ($scoreRow['raw_score'] ?? 0)
            ];
        }

        // Keep guidance edits non-invasive for students with no recorded component scores yet.
        if (empty($existingScores)) {
            $skippedInterviews++;
            continue;
        }

        $isEtgStudent = guidance_is_etg_classification((string) ($interview['classification'] ?? ''));
        $components = guidance_prepare_components_for_interview($baseComponents, $isEtgStudent);

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
                || guidance_is_sat_component((string) ($component['component_name'] ?? ''));
            $existing = $existingScores[$componentId] ?? null;

            $rawScore = $isAuto
                ? $placementScoreFromPlacement
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
        $updatedInterviews++;
    }

    $scoreLookupStmt->close();
    $updateAutoStmt->close();
    $updateWeightedStmt->close();
    $insertAutoStmt->close();
    $updateFinalStmt->close();

    return [
        'updated_interviews' => $updatedInterviews,
        'skipped_interviews' => $skippedInterviews
    ];
}

$returnQuery = guidance_get_student_return_query($_POST);
$mode = strtolower(trim((string) ($_POST['mode'] ?? 'add')));
$mode = $mode === 'edit' ? 'edit' : 'add';
$studentId = (int) ($_POST['student_id'] ?? 0);
$editorAccountId = (int) ($_SESSION['accountid'] ?? 0);

$examineeNumber = trim((string) ($_POST['examinee_number'] ?? ''));
$fullName = strtoupper(trim((string) ($_POST['full_name'] ?? '')));
$preferredProgram = trim((string) ($_POST['preferred_program'] ?? ''));
$qualitativeText = trim((string) ($_POST['qualitative_text'] ?? ''));
$qualitativeCodeRaw = (string) ($_POST['qualitative_code'] ?? '');
$satScoreRaw = (string) ($_POST['sat_score'] ?? '');
$esmScoreRaw = (string) ($_POST['esm_competency_standard_score'] ?? '');
$overallScoreRaw = (string) ($_POST['overall_standard_score'] ?? '');

$errors = [];

if ($examineeNumber === '') {
    $errors[] = 'Examinee Number is required.';
} elseif (strlen($examineeNumber) > 30) {
    $errors[] = 'Examinee Number must not exceed 30 characters.';
}

if ($fullName === '') {
    $errors[] = 'Full Name is required.';
} elseif (strlen($fullName) > 255) {
    $errors[] = 'Full Name must not exceed 255 characters.';
}

if ($preferredProgram !== '' && strlen($preferredProgram) > 255) {
    $errors[] = 'Preferred Program must not exceed 255 characters.';
}

if ($qualitativeText === '') {
    $errors[] = 'Qualitative Text is required.';
} elseif (strlen($qualitativeText) > 50) {
    $errors[] = 'Qualitative Text must not exceed 50 characters.';
}

$satScore = guidance_parse_required_int($satScoreRaw, 'SAT Score', $errors);
$qualitativeCode = guidance_parse_required_int($qualitativeCodeRaw, 'Qualitative Code', $errors);
$esmScore = guidance_parse_optional_int($esmScoreRaw, 'ESM Standard Score', $errors);
$overallScore = guidance_parse_optional_int($overallScoreRaw, 'Overall Standard Score', $errors);

if ($overallScore === null && $satScore !== null) {
    $overallScore = $satScore;
}

if ($mode === 'edit' && $studentId <= 0) {
    $errors[] = 'Invalid student record selected for editing.';
}

if (!empty($errors)) {
    guidance_set_flash('danger', implode(' ', $errors));
    guidance_redirect_students($returnQuery);
}

$preferredProgramValue = $preferredProgram !== '' ? $preferredProgram : null;

try {
    $conn->begin_transaction();

    if ($mode === 'edit') {
        $lockStmt = $conn->prepare("
            SELECT id, upload_batch_id
            FROM tbl_placement_results
            WHERE id = ?
            LIMIT 1
            FOR UPDATE
        ");
        $lockStmt->bind_param('i', $studentId);
        $lockStmt->execute();
        $existingResult = $lockStmt->get_result();
        $existingStudent = $existingResult ? $existingResult->fetch_assoc() : null;
        $lockStmt->close();

        if (!$existingStudent) {
            throw new RuntimeException('Student record not found.');
        }

        $studentUploadBatchId = trim((string) ($existingStudent['upload_batch_id'] ?? ''));

        $updateStmt = $conn->prepare("
            UPDATE tbl_placement_results
            SET
                examinee_number = ?,
                full_name = ?,
                sat_score = ?,
                qualitative_text = ?,
                qualitative_code = ?,
                preferred_program = ?,
                esm_competency_standard_score = ?,
                overall_standard_score = ?
            WHERE id = ?
        ");
        $updateStmt->bind_param(
            'ssisisiii',
            $examineeNumber,
            $fullName,
            $satScore,
            $qualitativeText,
            $qualitativeCode,
            $preferredProgramValue,
            $esmScore,
            $overallScore,
            $studentId
        );
        $updateStmt->execute();
        $updateStmt->close();

        $interviewStmt = $conn->prepare("
            UPDATE tbl_student_interview
            SET examinee_number = ?
            WHERE placement_result_id = ?
        ");
        $interviewStmt->bind_param('si', $examineeNumber, $studentId);
        $interviewStmt->execute();
        $interviewStmt->close();

        $credentialStmt = $conn->prepare("
            UPDATE tbl_student_credentials
            SET examinee_number = ?
            WHERE placement_result_id = ?
        ");
        $credentialStmt->bind_param('si', $examineeNumber, $studentId);
        $credentialStmt->execute();
        $credentialStmt->close();

        $profileStmt = $conn->prepare("
            UPDATE tbl_student_profile sp
            INNER JOIN tbl_student_credentials sc
                ON sc.credential_id = sp.credential_id
            SET sp.examinee_number = ?
            WHERE sc.placement_result_id = ?
        ");
        $profileStmt->bind_param('si', $examineeNumber, $studentId);
        $profileStmt->execute();
        $profileStmt->close();

        $scoreSync = guidance_sync_interview_scores_after_placement_edit(
            $conn,
            $studentId,
            guidance_pick_auto_placement_score(
                $satScore,
                $esmScore,
                $overallScore,
                $preferredProgramValue
            ),
            guidance_load_active_scoring_components($conn)
        );
        $editMarkerSaved = guidance_mark_student_record_edited(
            $conn,
            $studentId,
            $studentUploadBatchId,
            $editorAccountId
        );

        $conn->commit();
        $successMessage = 'Student information updated successfully.';
        if ((int) ($scoreSync['updated_interviews'] ?? 0) > 0) {
            $successMessage .= ' Recomputed final and raw interview scores for '
                . number_format((int) $scoreSync['updated_interviews'])
                . ' scored interview record(s).';
        }
        if ($editMarkerSaved) {
            $successMessage .= ' Edit marker updated.';
        }
        guidance_set_flash('success', $successMessage);
        $returnQueryWithEditedMarker = $returnQuery;
        $returnQueryWithEditedMarker['edited_id'] = $studentId;
        guidance_redirect_students($returnQueryWithEditedMarker);
    }

    $activeBatchId = guidance_get_active_batch_id($conn);
    if ($activeBatchId === null) {
        throw new RuntimeException('No active placement-results batch is available for new records.');
    }

    $insertStmt = $conn->prepare("
        INSERT INTO tbl_placement_results (
            examinee_number,
            full_name,
            sat_score,
            qualitative_text,
            qualitative_code,
            preferred_program,
            esm_competency_standard_score,
            overall_standard_score,
            upload_batch_id
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $insertStmt->bind_param(
        'ssisisiis',
        $examineeNumber,
        $fullName,
        $satScore,
        $qualitativeText,
        $qualitativeCode,
        $preferredProgramValue,
        $esmScore,
        $overallScore,
        $activeBatchId
    );
    $insertStmt->execute();
    $insertStmt->close();

    $conn->commit();
    guidance_set_flash('success', 'Student information added to the active placement batch.');
    guidance_redirect_students($returnQuery);
} catch (Throwable $e) {
    try {
        $conn->rollback();
    } catch (Throwable $rollbackError) {
    }

    $message = 'Unable to save student information.';
    $errorText = trim($e->getMessage());
    if ($errorText !== '') {
        if (stripos($errorText, 'Duplicate entry') !== false && stripos($errorText, 'examinee_number') !== false) {
            $message = 'Examinee Number already exists in another record.';
        } else {
            $message = $errorText;
        }
    }

    guidance_set_flash('danger', $message);
    guidance_redirect_students($returnQuery);
}
