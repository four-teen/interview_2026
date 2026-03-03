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

$returnQuery = guidance_get_student_return_query($_POST);
$mode = strtolower(trim((string) ($_POST['mode'] ?? 'add')));
$mode = $mode === 'edit' ? 'edit' : 'add';
$studentId = (int) ($_POST['student_id'] ?? 0);

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
            SELECT id
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

        $conn->commit();
        guidance_set_flash('success', 'Student information updated successfully.');
        guidance_redirect_students($returnQuery);
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
