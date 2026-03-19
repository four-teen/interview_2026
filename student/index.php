<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
require_once '../config/program_ranking_lock.php';
require_once '../config/session_security.php';
require_once '../config/admin_student_impersonation.php';
secure_session_start();

$isAdminStudentPreview = admin_student_impersonation_is_active();
$adminStudentPreviewContext = $isAdminStudentPreview ? admin_student_impersonation_get_context() : [];
$adminStudentPreviewCsrf = $isAdminStudentPreview ? admin_student_impersonation_get_csrf_token() : '';

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'student')) {
    header('Location: ../index.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password']) && !$isAdminStudentPreview) {
    header('Location: change_password.php');
    exit;
}

if (!ensure_student_credentials_table($conn)) {
    http_response_code(500);
    exit('Student portal initialization failed.');
}

$credentialId = (int) ($_SESSION['student_credential_id'] ?? 0);
if ($credentialId <= 0) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

$studentEsmPreferredProgramConditionSql = program_ranking_build_esm_preferred_program_condition_sql('pr.preferred_program');
$studentCutoffBasisScoreSql = "CASE
    WHEN {$studentEsmPreferredProgramConditionSql} THEN COALESCE(pr.esm_competency_standard_score, pr.sat_score, 0)
    ELSE COALESCE(pr.overall_standard_score, pr.sat_score, 0)
END";

$sql = "
    SELECT
        sc.credential_id,
        sc.examinee_number,
        sc.active_email,
        sc.must_change_password,
        pr.id AS placement_result_id,
        pr.full_name,
        pr.sat_score,
        pr.qualitative_text,
        pr.preferred_program,
        pr.upload_batch_id,
        ({$studentCutoffBasisScoreSql}) AS cutoff_basis_score,
        pr.overall_standard_score,
        pr.overall_stanine,
        pr.overall_qualitative_text,
        si.interview_id,
        si.classification,
        si.mobile_number,
        si.interview_datetime,
        si.final_score,
        si.first_choice,
        si.second_choice,
        si.third_choice,
        si.shs_track_id,
        si.etg_class_id,
        cam.campus_name,
        t.track AS shs_track_name,
        ec.class_desc AS etg_class_name,
        p1.program_name AS first_program_name,
        p1.major AS first_program_major,
        p1cam.campus_name AS first_program_campus_name,
        p2.program_name AS second_program_name,
        p2.major AS second_program_major,
        p2cam.campus_name AS second_program_campus_name,
        p3.program_name AS third_program_name,
        p3.major AS third_program_major,
        p3cam.campus_name AS third_program_campus_name
    FROM tbl_student_credentials sc
    LEFT JOIN tbl_placement_results pr
      ON pr.id = sc.placement_result_id
    LEFT JOIN tbl_student_interview si
      ON si.examinee_number = sc.examinee_number
     AND si.status = 'active'
    LEFT JOIN tbl_campus cam
      ON cam.campus_id = si.campus_id
    LEFT JOIN tb_ltrack t
      ON t.trackid = si.shs_track_id
    LEFT JOIN tbl_etg_class ec
      ON ec.etgclassid = si.etg_class_id
    LEFT JOIN tbl_program p1
      ON p1.program_id = si.first_choice
    LEFT JOIN tbl_college p1col
      ON p1col.college_id = p1.college_id
    LEFT JOIN tbl_campus p1cam
      ON p1cam.campus_id = p1col.campus_id
    LEFT JOIN tbl_program p2
      ON p2.program_id = si.second_choice
    LEFT JOIN tbl_college p2col
      ON p2col.college_id = p2.college_id
    LEFT JOIN tbl_campus p2cam
      ON p2cam.campus_id = p2col.campus_id
    LEFT JOIN tbl_program p3
      ON p3.program_id = si.third_choice
    LEFT JOIN tbl_college p3col
      ON p3col.college_id = p3.college_id
    LEFT JOIN tbl_campus p3cam
      ON p3cam.campus_id = p3col.campus_id
    WHERE sc.credential_id = ?
      AND sc.status = 'active'
    ORDER BY si.interview_datetime DESC, si.interview_id DESC
    LIMIT 1
";

$stmt = $conn->prepare($sql);
if (!$stmt) {
    http_response_code(500);
    exit('Failed to load student dashboard.');
}
$stmt->bind_param('i', $credentialId);
$stmt->execute();
$student = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$student) {
    session_unset();
    session_destroy();
    header('Location: ../index.php');
    exit;
}

if ((int) ($student['must_change_password'] ?? 0) === 1 && !$isAdminStudentPreview) {
    $_SESSION['student_must_change_password'] = true;
    header('Location: change_password.php');
    exit;
}

$transferFlash = null;
if (isset($_SESSION['student_transfer_flash']) && is_array($_SESSION['student_transfer_flash'])) {
    $transferFlash = $_SESSION['student_transfer_flash'];
    unset($_SESSION['student_transfer_flash']);
}

$profileFlash = null;
if (isset($_SESSION['student_profile_flash']) && is_array($_SESSION['student_profile_flash'])) {
    $profileFlash = $_SESSION['student_profile_flash'];
    unset($_SESSION['student_profile_flash']);
}

$preRegistrationFlash = null;
if (isset($_SESSION['student_prereg_flash']) && is_array($_SESSION['student_prereg_flash'])) {
    $preRegistrationFlash = $_SESSION['student_prereg_flash'];
    unset($_SESSION['student_prereg_flash']);
}

$studentAdminPreviewFlash = null;
if (isset($_SESSION['student_admin_preview_flash']) && is_array($_SESSION['student_admin_preview_flash'])) {
    $studentAdminPreviewFlash = $_SESSION['student_admin_preview_flash'];
    unset($_SESSION['student_admin_preview_flash']);
}

if (empty($_SESSION['student_transfer_csrf'])) {
    try {
        $_SESSION['student_transfer_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['student_transfer_csrf'] = sha1(uniqid('student_transfer_csrf_', true));
    }
}

if (empty($_SESSION['student_profile_csrf'])) {
    try {
        $_SESSION['student_profile_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['student_profile_csrf'] = sha1(uniqid('student_profile_csrf_', true));
    }
}

if (empty($_SESSION['student_prereg_csrf'])) {
    try {
        $_SESSION['student_prereg_csrf'] = bin2hex(random_bytes(32));
    } catch (Exception $e) {
        $_SESSION['student_prereg_csrf'] = sha1(uniqid('student_prereg_csrf_', true));
    }
}

$studentPostedAction = (string) ($_POST['action'] ?? '');
if (
    $isAdminStudentPreview &&
    $_SERVER['REQUEST_METHOD'] === 'POST' &&
    in_array($studentPostedAction, ['student_profile_save', 'student_preregistration_submit', 'student_transfer_submit'], true)
) {
    $_SESSION['student_admin_preview_flash'] = [
        'type' => 'danger',
        'message' => 'Administrator preview is read-only. Return to Administrator to make changes.',
    ];
    header('Location: index.php');
    exit;
}

function ensure_student_transfer_history_table($conn)
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

function ensure_student_profile_table($conn)
{
    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_student_profile (
            profile_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            credential_id INT(10) UNSIGNED NOT NULL,
            examinee_number VARCHAR(50) NOT NULL,
            birth_date DATE DEFAULT NULL,
            sex ENUM('Male', 'Female', 'Other') DEFAULT NULL,
            civil_status ENUM('Single', 'Married', 'Separated', 'Widowed', 'Other') DEFAULT NULL,
            nationality VARCHAR(100) DEFAULT NULL,
            religion VARCHAR(120) DEFAULT NULL,
            secondary_school_name VARCHAR(190) DEFAULT NULL,
            secondary_school_type ENUM('Private', 'Public') DEFAULT NULL,
            secondary_address_line1 VARCHAR(255) DEFAULT NULL,
            secondary_region_code INT(10) UNSIGNED DEFAULT NULL,
            secondary_province_code INT(10) UNSIGNED DEFAULT NULL,
            secondary_citymun_code INT(10) UNSIGNED DEFAULT NULL,
            secondary_barangay_code INT(10) UNSIGNED DEFAULT NULL,
            secondary_postal_code VARCHAR(20) DEFAULT NULL,
            address_line1 VARCHAR(255) DEFAULT NULL,
            region_code INT(10) UNSIGNED DEFAULT NULL,
            province_code INT(10) UNSIGNED DEFAULT NULL,
            citymun_code INT(10) UNSIGNED DEFAULT NULL,
            barangay_code INT(10) UNSIGNED DEFAULT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            parent_guardian_address_line1 VARCHAR(255) DEFAULT NULL,
            parent_guardian_region_code INT(10) UNSIGNED DEFAULT NULL,
            parent_guardian_province_code INT(10) UNSIGNED DEFAULT NULL,
            parent_guardian_citymun_code INT(10) UNSIGNED DEFAULT NULL,
            parent_guardian_barangay_code INT(10) UNSIGNED DEFAULT NULL,
            parent_guardian_postal_code VARCHAR(20) DEFAULT NULL,
            father_name VARCHAR(190) DEFAULT NULL,
            father_contact_number VARCHAR(30) DEFAULT NULL,
            father_occupation VARCHAR(120) DEFAULT NULL,
            mother_name VARCHAR(190) DEFAULT NULL,
            mother_contact_number VARCHAR(30) DEFAULT NULL,
            mother_occupation VARCHAR(120) DEFAULT NULL,
            guardian_name VARCHAR(190) DEFAULT NULL,
            guardian_relationship VARCHAR(120) DEFAULT NULL,
            guardian_contact_number VARCHAR(30) DEFAULT NULL,
            guardian_occupation VARCHAR(120) DEFAULT NULL,
            profile_completion_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (profile_id),
            UNIQUE KEY uq_student_profile_credential (credential_id),
            UNIQUE KEY uq_student_profile_examinee (examinee_number),
            KEY idx_student_profile_region (region_code),
            KEY idx_student_profile_province (province_code),
            KEY idx_student_profile_citymun (citymun_code),
            KEY idx_student_profile_barangay (barangay_code),
            KEY idx_student_profile_secondary_region (secondary_region_code),
            KEY idx_student_profile_secondary_province (secondary_province_code),
            KEY idx_student_profile_secondary_citymun (secondary_citymun_code),
            KEY idx_student_profile_secondary_barangay (secondary_barangay_code),
            KEY idx_student_profile_parent_guardian_region (parent_guardian_region_code),
            KEY idx_student_profile_parent_guardian_province (parent_guardian_province_code),
            KEY idx_student_profile_parent_guardian_citymun (parent_guardian_citymun_code),
            KEY idx_student_profile_parent_guardian_barangay (parent_guardian_barangay_code),
            CONSTRAINT fk_student_profile_credential
                FOREIGN KEY (credential_id) REFERENCES tbl_student_credentials (credential_id)
                ON UPDATE CASCADE
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $ok = (bool) $conn->query($sql);
    if (!$ok) {
        return false;
    }

    $columnsToEnsure = [
        'secondary_address_line1' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_address_line1 VARCHAR(255) DEFAULT NULL AFTER secondary_school_type",
        'secondary_region_code' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_region_code INT(10) UNSIGNED DEFAULT NULL AFTER secondary_address_line1",
        'secondary_province_code' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_province_code INT(10) UNSIGNED DEFAULT NULL AFTER secondary_region_code",
        'secondary_citymun_code' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_citymun_code INT(10) UNSIGNED DEFAULT NULL AFTER secondary_province_code",
        'secondary_barangay_code' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_barangay_code INT(10) UNSIGNED DEFAULT NULL AFTER secondary_citymun_code",
        'secondary_postal_code' => "ALTER TABLE tbl_student_profile ADD COLUMN secondary_postal_code VARCHAR(20) DEFAULT NULL AFTER secondary_barangay_code",
        'parent_guardian_address_line1' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_address_line1 VARCHAR(255) DEFAULT NULL AFTER postal_code",
        'parent_guardian_region_code' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_region_code INT(10) UNSIGNED DEFAULT NULL AFTER parent_guardian_address_line1",
        'parent_guardian_province_code' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_province_code INT(10) UNSIGNED DEFAULT NULL AFTER parent_guardian_region_code",
        'parent_guardian_citymun_code' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_citymun_code INT(10) UNSIGNED DEFAULT NULL AFTER parent_guardian_province_code",
        'parent_guardian_barangay_code' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_barangay_code INT(10) UNSIGNED DEFAULT NULL AFTER parent_guardian_citymun_code",
        'parent_guardian_postal_code' => "ALTER TABLE tbl_student_profile ADD COLUMN parent_guardian_postal_code VARCHAR(20) DEFAULT NULL AFTER parent_guardian_barangay_code",
    ];

    foreach ($columnsToEnsure as $columnName => $alterSql) {
        $columnResult = $conn->query("SHOW COLUMNS FROM tbl_student_profile LIKE '" . $conn->real_escape_string($columnName) . "'");
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

function ensure_student_preregistration_table($conn)
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
                status ENUM('submitted', 'forfeited') NOT NULL DEFAULT 'submitted',
                forfeited_at DATETIME DEFAULT NULL,
                forfeited_by INT(10) UNSIGNED DEFAULT NULL,
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
        'forfeited_at' => "ALTER TABLE tbl_student_preregistration ADD COLUMN forfeited_at DATETIME DEFAULT NULL AFTER status",
        'forfeited_by' => "ALTER TABLE tbl_student_preregistration ADD COLUMN forfeited_by INT(10) UNSIGNED DEFAULT NULL AFTER forfeited_at",
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

    $statusColumnResult = $conn->query("SHOW COLUMNS FROM tbl_student_preregistration LIKE 'status'");
    if (!$statusColumnResult) {
        return false;
    }

    $statusColumn = $statusColumnResult->fetch_assoc();
    $statusColumnResult->free();
    $statusType = strtolower((string) ($statusColumn['Type'] ?? ''));
    if (strpos($statusType, "'forfeited'") === false) {
        if (!$conn->query("
            ALTER TABLE tbl_student_preregistration
            MODIFY COLUMN status ENUM('submitted', 'forfeited') NOT NULL DEFAULT 'submitted'
        ")) {
            return false;
        }
    }

    return true;
}

function normalize_profile_text($value, $maxLength)
{
    $value = trim((string) $value);
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return (string) mb_substr($value, 0, (int) $maxLength);
    }

    return (string) substr($value, 0, (int) $maxLength);
}

function student_profile_completion_requirements()
{
    return [
        'text' => [
            'birth_date' => 'Birth Date',
            'sex' => 'Sex',
            'civil_status' => 'Civil Status',
            'nationality' => 'Nationality',
            'religion' => 'Religion',
            'secondary_school_name' => 'Secondary School',
            'secondary_school_type' => 'School Type',
            'secondary_postal_code' => 'Secondary School Postal Code',
            'address_line1' => 'House No. / Street / Purok',
            'postal_code' => 'Postal Code',
            'guardian_name' => 'Parent / Guardian Name',
            'parent_guardian_address_line1' => 'Parent / Guardian House No. / Street / Purok',
            'parent_guardian_postal_code' => 'Parent / Guardian Postal Code',
        ],
        'code' => [
            'secondary_province_code' => 'Secondary School Province',
            'secondary_citymun_code' => 'Secondary School City / Municipality',
            'region_code' => 'Region',
            'province_code' => 'Province',
            'citymun_code' => 'City / Municipality',
            'barangay_code' => 'Barangay',
            'parent_guardian_region_code' => 'Parent / Guardian Region',
            'parent_guardian_province_code' => 'Parent / Guardian Province',
            'parent_guardian_citymun_code' => 'Parent / Guardian City / Municipality',
            'parent_guardian_barangay_code' => 'Parent / Guardian Barangay',
        ],
    ];
}

function calculate_student_profile_completion_percent(array $profileData)
{
    $requirements = student_profile_completion_requirements();
    $requiredTextFields = array_keys($requirements['text']);
    $requiredCodeFields = array_keys($requirements['code']);

    $filledCount = 0;
    $requiredCount = count($requiredTextFields) + count($requiredCodeFields);
    if ($requiredCount <= 0) {
        return 0.0;
    }

    foreach ($requiredTextFields as $field) {
        $value = trim((string) ($profileData[$field] ?? ''));
        if ($value !== '') {
            $filledCount++;
        }
    }

    foreach ($requiredCodeFields as $field) {
        if ((int) ($profileData[$field] ?? 0) > 0) {
            $filledCount++;
        }
    }

    $percent = ((float) $filledCount / (float) $requiredCount) * 100;
    $percent = round($percent, 2);

    if ($percent < 0) {
        $percent = 0.0;
    }
    if ($percent > 100) {
        $percent = 100.0;
    }

    return $percent;
}

function get_student_profile_missing_fields(array $profileData)
{
    $requirements = student_profile_completion_requirements();
    $missing = [];

    foreach (($requirements['text'] ?? []) as $field => $label) {
        $value = trim((string) ($profileData[$field] ?? ''));
        if ($value === '') {
            $missing[$field] = (string) $label;
        }
    }

    foreach (($requirements['code'] ?? []) as $field => $label) {
        if ((int) ($profileData[$field] ?? 0) <= 0) {
            $missing[$field] = (string) $label;
        }
    }

    return $missing;
}

function send_profile_lookup_json($payload, $statusCode = 200)
{
    http_response_code((int) $statusCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function count_reason_words($text)
{
    $text = trim((string) $text);
    if ($text === '') {
        return 0;
    }

    $matches = [];
    preg_match_all('/\S+/u', $text, $matches);
    return isset($matches[0]) ? count($matches[0]) : 0;
}

function build_reordered_program_choices($selectedProgramId, $firstChoiceId, $secondChoiceId, $thirdChoiceId)
{
    $selectedProgramId = (int) $selectedProgramId;
    $ordered = [];
    if ($selectedProgramId > 0) {
        $ordered[] = $selectedProgramId;
    }

    $currentChoices = [
        (int) $firstChoiceId,
        (int) $secondChoiceId,
        (int) $thirdChoiceId,
    ];

    foreach ($currentChoices as $programId) {
        if ($programId <= 0) {
            continue;
        }
        if ($programId === $selectedProgramId) {
            continue;
        }
        if (in_array($programId, $ordered, true)) {
            continue;
        }
        $ordered[] = $programId;
    }

    $ordered = array_slice($ordered, 0, 3);
    while (count($ordered) < 3) {
        $ordered[] = 0;
    }

    return $ordered;
}

function student_program_ranking_compare_rows(array $left, array $right): int
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

function student_program_ranking_get_projected_rank(array $rankedRows, array $candidateRow): ?int
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
    usort($rows, 'student_program_ranking_compare_rows');

    $rank = 1;
    foreach ($rows as $row) {
        if ((string) ($row['examinee_number'] ?? '') === $candidateExaminee) {
            return $rank;
        }
        $rank++;
    }

    return null;
}

function student_program_ranking_build_context(mysqli $conn): array
{
    ensure_program_endorsement_table($conn);

    $context = [
        'rows_by_program' => [],
        'endorsement_ids_by_program' => [],
    ];

    $esmPreferredProgramConditionSql = program_ranking_build_esm_preferred_program_condition_sql('pr.preferred_program');
    $cutoffBasisScoreSql = "CASE
        WHEN {$esmPreferredProgramConditionSql} THEN COALESCE(pr.esm_competency_standard_score, pr.sat_score, 0)
        ELSE COALESCE(pr.overall_standard_score, pr.sat_score, 0)
    END";

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

    if ($rankingPoolStmt = $conn->prepare($rankingPoolSql)) {
        $rankingPoolStmt->execute();
        $rankingPoolResult = $rankingPoolStmt->get_result();

        while ($rankingRow = $rankingPoolResult->fetch_assoc()) {
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

        $rankingPoolStmt->close();
    }

    $endorsementSql = "
        SELECT program_id, interview_id
        FROM tbl_program_endorsements
        ORDER BY program_id ASC, endorsed_at ASC, endorsement_id ASC
    ";
    $endorsementResult = $conn->query($endorsementSql);
    if ($endorsementResult) {
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
    }

    foreach ($context['rows_by_program'] as $programId => $groupedRows) {
        foreach (['REGULAR', 'ETG'] as $groupKey) {
            $rows = $groupedRows[$groupKey] ?? [];
            usort($rows, 'student_program_ranking_compare_rows');
            $context['rows_by_program'][$programId][$groupKey] = $rows;
        }
    }

    return $context;
}

function student_program_ranking_get_pool_state(array $context, int $programId, ?int $effectiveCutoff): array
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

function student_program_ranking_section_label(string $section): string
{
    $normalized = program_ranking_normalize_section($section);
    if ($normalized === 'scc') {
        return 'SCC';
    }
    if ($normalized === 'etg') {
        return 'ETG';
    }

    return 'Regular';
}

if (!ensure_student_profile_table($conn)) {
    http_response_code(500);
    exit('Student profile storage initialization failed.');
}

if (!ensure_student_preregistration_table($conn)) {
    http_response_code(500);
    exit('Student pre-registration storage initialization failed.');
}

$globalSatCutoffState = get_global_sat_cutoff_state($conn);
$globalSatCutoffEnabled = (bool) ($globalSatCutoffState['enabled'] ?? false);
$globalSatCutoffValue = isset($globalSatCutoffState['value']) ? (int) $globalSatCutoffState['value'] : null;

$studentRankingContext = student_program_ranking_build_context($conn);
$studentTransferCandidateRow = null;
if ($student['final_score'] !== null && $student['final_score'] !== '') {
    $studentTransferCandidateRow = [
        'examinee_number' => (string) ($student['examinee_number'] ?? ''),
        'final_score' => (float) ($student['final_score'] ?? 0),
        'cutoff_basis_score' => (float) ($student['cutoff_basis_score'] ?? 0),
        'full_name' => trim((string) ($student['full_name'] ?? '')),
    ];
}

$studentProfile = [
    'birth_date' => '',
    'sex' => '',
    'civil_status' => '',
    'nationality' => '',
    'religion' => '',
    'secondary_school_name' => '',
    'secondary_school_type' => '',
    'secondary_address_line1' => '',
    'secondary_region_code' => 0,
    'secondary_province_code' => 0,
    'secondary_citymun_code' => 0,
    'secondary_barangay_code' => 0,
    'secondary_postal_code' => '',
    'address_line1' => '',
    'region_code' => 0,
    'province_code' => 0,
    'citymun_code' => 0,
    'barangay_code' => 0,
    'postal_code' => '',
    'parent_guardian_address_line1' => '',
    'parent_guardian_region_code' => 0,
    'parent_guardian_province_code' => 0,
    'parent_guardian_citymun_code' => 0,
    'parent_guardian_barangay_code' => 0,
    'parent_guardian_postal_code' => '',
    'father_name' => '',
    'father_contact_number' => '',
    'father_occupation' => '',
    'mother_name' => '',
    'mother_contact_number' => '',
    'mother_occupation' => '',
    'guardian_name' => '',
    'guardian_relationship' => '',
    'guardian_contact_number' => '',
    'guardian_occupation' => '',
    'profile_completion_percent' => 0,
    'region_name' => '',
    'province_name' => '',
    'citymun_name' => '',
    'barangay_name' => '',
    'secondary_region_name' => '',
    'secondary_province_name' => '',
    'secondary_citymun_name' => '',
    'secondary_barangay_name' => '',
    'parent_guardian_region_name' => '',
    'parent_guardian_province_name' => '',
    'parent_guardian_citymun_name' => '',
    'parent_guardian_barangay_name' => '',
];

$profileSql = "
    SELECT
        sp.*,
        rr.regDesc AS region_name,
        rp.provDesc AS province_name,
        rc.citymunDesc AS citymun_name,
        rb.brgyDesc AS barangay_name,
        rr2.regDesc AS secondary_region_name,
        rp2.provDesc AS secondary_province_name,
        rc2.citymunDesc AS secondary_citymun_name,
        rb2.brgyDesc AS secondary_barangay_name,
        rr3.regDesc AS parent_guardian_region_name,
        rp3.provDesc AS parent_guardian_province_name,
        rc3.citymunDesc AS parent_guardian_citymun_name,
        rb3.brgyDesc AS parent_guardian_barangay_name
    FROM tbl_student_profile sp
    LEFT JOIN refregion rr
        ON rr.regCode = sp.region_code
    LEFT JOIN refprovince rp
        ON rp.provCode = sp.province_code
    LEFT JOIN refcitymun rc
        ON rc.citymunCode = sp.citymun_code
    LEFT JOIN refbrgy rb
        ON rb.brgyCode = sp.barangay_code
    LEFT JOIN refregion rr2
        ON rr2.regCode = sp.secondary_region_code
    LEFT JOIN refprovince rp2
        ON rp2.provCode = sp.secondary_province_code
    LEFT JOIN refcitymun rc2
        ON rc2.citymunCode = sp.secondary_citymun_code
    LEFT JOIN refbrgy rb2
        ON rb2.brgyCode = sp.secondary_barangay_code
    LEFT JOIN refregion rr3
        ON rr3.regCode = sp.parent_guardian_region_code
    LEFT JOIN refprovince rp3
        ON rp3.provCode = sp.parent_guardian_province_code
    LEFT JOIN refcitymun rc3
        ON rc3.citymunCode = sp.parent_guardian_citymun_code
    LEFT JOIN refbrgy rb3
        ON rb3.brgyCode = sp.parent_guardian_barangay_code
    WHERE sp.credential_id = ?
    LIMIT 1
";

if ($profileStmt = $conn->prepare($profileSql)) {
    $profileStmt->bind_param('i', $credentialId);
    $profileStmt->execute();
    $profileRow = $profileStmt->get_result()->fetch_assoc();
    $profileStmt->close();

    if ($profileRow) {
        $studentProfile = array_merge($studentProfile, $profileRow);
    }
}

$studentProfile['region_code'] = (int) ($studentProfile['region_code'] ?? 0);
$studentProfile['province_code'] = (int) ($studentProfile['province_code'] ?? 0);
$studentProfile['citymun_code'] = (int) ($studentProfile['citymun_code'] ?? 0);
$studentProfile['barangay_code'] = (int) ($studentProfile['barangay_code'] ?? 0);
$studentProfile['secondary_region_code'] = (int) ($studentProfile['secondary_region_code'] ?? 0);
$studentProfile['secondary_province_code'] = (int) ($studentProfile['secondary_province_code'] ?? 0);
$studentProfile['secondary_citymun_code'] = (int) ($studentProfile['secondary_citymun_code'] ?? 0);
$studentProfile['secondary_barangay_code'] = (int) ($studentProfile['secondary_barangay_code'] ?? 0);
$studentProfile['parent_guardian_region_code'] = (int) ($studentProfile['parent_guardian_region_code'] ?? 0);
$studentProfile['parent_guardian_province_code'] = (int) ($studentProfile['parent_guardian_province_code'] ?? 0);
$studentProfile['parent_guardian_citymun_code'] = (int) ($studentProfile['parent_guardian_citymun_code'] ?? 0);
$studentProfile['parent_guardian_barangay_code'] = (int) ($studentProfile['parent_guardian_barangay_code'] ?? 0);
$studentProfileCompletionPercent = calculate_student_profile_completion_percent($studentProfile);
$studentProfileMissingFields = get_student_profile_missing_fields($studentProfile);
$studentProfileMissingLabels = array_values($studentProfileMissingFields);
$studentProfileMissingSummary = '';
if (count($studentProfileMissingLabels) === 1) {
    $studentProfileMissingSummary = '1 required field remaining: ' . $studentProfileMissingLabels[0] . '.';
} elseif (count($studentProfileMissingLabels) > 1) {
    $previewLabels = array_slice($studentProfileMissingLabels, 0, 3);
    $studentProfileMissingSummary = count($studentProfileMissingLabels) . ' required fields remaining: ' . implode(', ', $previewLabels);
    if (count($studentProfileMissingLabels) > 3) {
        $studentProfileMissingSummary .= ', and more.';
    } else {
        $studentProfileMissingSummary .= '.';
    }
}
$studentProfile['profile_completion_percent'] = $studentProfileCompletionPercent;

$studentPreRegistration = null;
$preRegistrationSql = "
    SELECT
        preregistration_id,
        interview_id,
        program_id,
        locked_rank,
        profile_completion_percent,
        agreement_accepted,
        agreement_accepted_at,
        status,
        submitted_at,
        updated_at
    FROM tbl_student_preregistration
    WHERE credential_id = ?
    LIMIT 1
";
if ($preRegistrationStmt = $conn->prepare($preRegistrationSql)) {
    $preRegistrationStmt->bind_param('i', $credentialId);
    $preRegistrationStmt->execute();
    $preRegistrationRow = $preRegistrationStmt->get_result()->fetch_assoc();
    $preRegistrationStmt->close();

    if ($preRegistrationRow) {
        $studentPreRegistration = $preRegistrationRow;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['profile_lookup'])) {
    $lookupType = strtolower(trim((string) ($_GET['profile_lookup'] ?? '')));
    $items = [];

    if ($lookupType === 'region') {
        $regionSql = "SELECT regCode AS code, regDesc AS label FROM refregion ORDER BY regCode ASC";
        $regionResult = $conn->query($regionSql);
        if (!$regionResult) {
            send_profile_lookup_json(['success' => false, 'message' => 'Failed loading regions.'], 500);
        }

        while ($row = $regionResult->fetch_assoc()) {
            $items[] = [
                'code' => (int) ($row['code'] ?? 0),
                'label' => trim((string) ($row['label'] ?? '')),
            ];
        }
        $regionResult->free();
        send_profile_lookup_json(['success' => true, 'items' => $items]);
    }

    if ($lookupType === 'province') {
        $regionCode = (int) ($_GET['region_code'] ?? 0);
        if ($regionCode > 0) {
            $provinceSql = "
                SELECT provCode AS code, provDesc AS label
                FROM refprovince
                WHERE regCode = ?
                ORDER BY provDesc ASC
            ";
            $provinceStmt = $conn->prepare($provinceSql);
            if (!$provinceStmt) {
                send_profile_lookup_json(['success' => false, 'message' => 'Failed loading provinces.'], 500);
            }
            $provinceStmt->bind_param('i', $regionCode);
            $provinceStmt->execute();
            $provinceResult = $provinceStmt->get_result();
            while ($row = $provinceResult->fetch_assoc()) {
                $items[] = [
                    'code' => (int) ($row['code'] ?? 0),
                    'label' => trim((string) ($row['label'] ?? '')),
                ];
            }
            $provinceStmt->close();
        } else {
            $provinceSql = "SELECT provCode AS code, provDesc AS label FROM refprovince ORDER BY provDesc ASC";
            $provinceResult = $conn->query($provinceSql);
            if (!$provinceResult) {
                send_profile_lookup_json(['success' => false, 'message' => 'Failed loading provinces.'], 500);
            }
            while ($row = $provinceResult->fetch_assoc()) {
                $items[] = [
                    'code' => (int) ($row['code'] ?? 0),
                    'label' => trim((string) ($row['label'] ?? '')),
                ];
            }
            $provinceResult->free();
        }
        send_profile_lookup_json(['success' => true, 'items' => $items]);
    }

    if ($lookupType === 'citymun') {
        $provinceCode = (int) ($_GET['province_code'] ?? 0);
        if ($provinceCode <= 0) {
            send_profile_lookup_json(['success' => true, 'items' => []]);
        }

        $citySql = "
            SELECT citymunCode AS code, citymunDesc AS label
            FROM refcitymun
            WHERE provCode = ?
            ORDER BY citymunDesc ASC
        ";
        $cityStmt = $conn->prepare($citySql);
        if (!$cityStmt) {
            send_profile_lookup_json(['success' => false, 'message' => 'Failed loading cities/municipalities.'], 500);
        }
        $cityStmt->bind_param('i', $provinceCode);
        $cityStmt->execute();
        $cityResult = $cityStmt->get_result();
        while ($row = $cityResult->fetch_assoc()) {
            $items[] = [
                'code' => (int) ($row['code'] ?? 0),
                'label' => trim((string) ($row['label'] ?? '')),
            ];
        }
        $cityStmt->close();
        send_profile_lookup_json(['success' => true, 'items' => $items]);
    }

    if ($lookupType === 'barangay') {
        $citymunCode = (int) ($_GET['citymun_code'] ?? 0);
        if ($citymunCode <= 0) {
            send_profile_lookup_json(['success' => true, 'items' => []]);
        }

        $barangaySql = "
            SELECT brgyCode AS code, brgyDesc AS label
            FROM refbrgy
            WHERE citymunCode = ?
            ORDER BY brgyDesc ASC
        ";
        $barangayStmt = $conn->prepare($barangaySql);
        if (!$barangayStmt) {
            send_profile_lookup_json(['success' => false, 'message' => 'Failed loading barangays.'], 500);
        }
        $barangayStmt->bind_param('i', $citymunCode);
        $barangayStmt->execute();
        $barangayResult = $barangayStmt->get_result();
        while ($row = $barangayResult->fetch_assoc()) {
            $items[] = [
                'code' => (int) ($row['code'] ?? 0),
                'label' => trim((string) ($row['label'] ?? '')),
            ];
        }
        $barangayStmt->close();
        send_profile_lookup_json(['success' => true, 'items' => $items]);
    }

    send_profile_lookup_json(['success' => false, 'message' => 'Invalid lookup type.'], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'student_profile_save') {
    $flashType = 'danger';
    $flashMessage = 'Profile update failed.';
    $hasSubmittedPreRegistration = is_array($studentPreRegistration)
        && !empty($studentPreRegistration['preregistration_id'])
        && strtolower(trim((string) ($studentPreRegistration['status'] ?? 'submitted'))) === 'submitted';

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_profile_csrf'] ?? '');
    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $flashMessage = 'Invalid profile security token. Refresh the page and try again.';
    } elseif ($hasSubmittedPreRegistration) {
        $flashMessage = 'Profile updates are no longer available after pre-registration is submitted.';
    } else {
        $birthDate = trim((string) ($_POST['birth_date'] ?? ''));
        $sex = normalize_profile_text($_POST['sex'] ?? '', 10);
        $civilStatus = normalize_profile_text($_POST['civil_status'] ?? '', 20);
        $nationality = normalize_profile_text($_POST['nationality'] ?? '', 100);
        $religion = normalize_profile_text($_POST['religion'] ?? '', 120);
        $secondarySchoolName = normalize_profile_text($_POST['secondary_school_name'] ?? '', 190);
        $secondarySchoolType = normalize_profile_text($_POST['secondary_school_type'] ?? '', 20);
        $secondaryAddressLine1 = normalize_profile_text($_POST['secondary_address_line1'] ?? '', 255);
        $secondaryRegionCode = (int) ($_POST['secondary_region_code'] ?? 0);
        $secondaryProvinceCode = (int) ($_POST['secondary_province_code'] ?? 0);
        $secondaryCitymunCode = (int) ($_POST['secondary_citymun_code'] ?? 0);
        $secondaryBarangayCode = (int) ($_POST['secondary_barangay_code'] ?? 0);
        $secondaryPostalCode = normalize_profile_text($_POST['secondary_postal_code'] ?? '', 20);
        $addressLine1 = normalize_profile_text($_POST['address_line1'] ?? '', 255);
        $regionCode = (int) ($_POST['region_code'] ?? 0);
        $provinceCode = (int) ($_POST['province_code'] ?? 0);
        $citymunCode = (int) ($_POST['citymun_code'] ?? 0);
        $barangayCode = (int) ($_POST['barangay_code'] ?? 0);
        $postalCode = normalize_profile_text($_POST['postal_code'] ?? '', 20);
        $parentGuardianAddressLine1 = normalize_profile_text($_POST['parent_guardian_address_line1'] ?? '', 255);
        $parentGuardianRegionCode = (int) ($_POST['parent_guardian_region_code'] ?? 0);
        $parentGuardianProvinceCode = (int) ($_POST['parent_guardian_province_code'] ?? 0);
        $parentGuardianCitymunCode = (int) ($_POST['parent_guardian_citymun_code'] ?? 0);
        $parentGuardianBarangayCode = (int) ($_POST['parent_guardian_barangay_code'] ?? 0);
        $parentGuardianPostalCode = normalize_profile_text($_POST['parent_guardian_postal_code'] ?? '', 20);
        $fatherName = normalize_profile_text($_POST['father_name'] ?? '', 190);
        $fatherContactNumber = normalize_profile_text($_POST['father_contact_number'] ?? '', 30);
        $fatherOccupation = normalize_profile_text($_POST['father_occupation'] ?? '', 120);
        $motherName = normalize_profile_text($_POST['mother_name'] ?? '', 190);
        $motherContactNumber = normalize_profile_text($_POST['mother_contact_number'] ?? '', 30);
        $motherOccupation = normalize_profile_text($_POST['mother_occupation'] ?? '', 120);
        $guardianName = normalize_profile_text($_POST['guardian_name'] ?? '', 190);
        $guardianRelationship = normalize_profile_text($_POST['guardian_relationship'] ?? '', 120);
        $guardianContactNumber = normalize_profile_text($_POST['guardian_contact_number'] ?? '', 30);
        $guardianOccupation = normalize_profile_text($_POST['guardian_occupation'] ?? '', 120);

        $allowedSex = ['', 'Male', 'Female', 'Other'];
        $allowedCivilStatus = ['', 'Single', 'Married', 'Separated', 'Widowed', 'Other'];
        $allowedSchoolType = ['', 'Private', 'Public'];
        if (!in_array($sex, $allowedSex, true)) {
            $sex = '';
        }
        if (!in_array($civilStatus, $allowedCivilStatus, true)) {
            $civilStatus = '';
        }
        if (!in_array($secondarySchoolType, $allowedSchoolType, true)) {
            $secondarySchoolType = '';
        }

        if ($birthDate !== '') {
            $dateParts = explode('-', $birthDate);
            $isValidBirthDate = (
                count($dateParts) === 3 &&
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $birthDate) &&
                checkdate((int) $dateParts[1], (int) $dateParts[2], (int) $dateParts[0])
            );
            if (!$isValidBirthDate) {
                $flashMessage = 'Invalid birth date format.';
            }
        }

        if ($flashMessage === 'Profile update failed.') {
            if ($secondaryCitymunCode > 0 && $secondaryProvinceCode <= 0) {
                $flashMessage = 'Select a secondary school province before selecting a city/municipality.';
            } elseif ($secondaryBarangayCode > 0 && $secondaryCitymunCode <= 0) {
                $flashMessage = 'Select a secondary school city/municipality before selecting a barangay.';
            } elseif ($provinceCode > 0 && $regionCode <= 0) {
                $flashMessage = 'Select a region before selecting a province.';
            } elseif ($citymunCode > 0 && $provinceCode <= 0) {
                $flashMessage = 'Select a province before selecting a city/municipality.';
            } elseif ($barangayCode > 0 && $citymunCode <= 0) {
                $flashMessage = 'Select a city/municipality before selecting a barangay.';
            } elseif ($parentGuardianProvinceCode > 0 && $parentGuardianRegionCode <= 0) {
                $flashMessage = 'Select a parent/guardian region before selecting a province.';
            } elseif ($parentGuardianCitymunCode > 0 && $parentGuardianProvinceCode <= 0) {
                $flashMessage = 'Select a parent/guardian province before selecting a city/municipality.';
            } elseif ($parentGuardianBarangayCode > 0 && $parentGuardianCitymunCode <= 0) {
                $flashMessage = 'Select a parent/guardian city/municipality before selecting a barangay.';
            }
        }

        if ($flashMessage === 'Profile update failed.' && $secondaryRegionCode > 0 && $secondaryProvinceCode > 0) {
            $secondaryProvinceMatchSql = "SELECT 1 FROM refprovince WHERE provCode = ? AND regCode = ? LIMIT 1";
            $secondaryProvinceMatchStmt = $conn->prepare($secondaryProvinceMatchSql);
            if (!$secondaryProvinceMatchStmt) {
                $flashMessage = 'Unable to validate secondary school province and region.';
            } else {
                $secondaryProvinceMatchStmt->bind_param('ii', $secondaryProvinceCode, $secondaryRegionCode);
                $secondaryProvinceMatchStmt->execute();
                $secondaryProvinceMatch = $secondaryProvinceMatchStmt->get_result()->fetch_assoc();
                $secondaryProvinceMatchStmt->close();
                if (!$secondaryProvinceMatch) {
                    $flashMessage = 'Selected secondary school province does not match the selected region.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $secondaryProvinceCode > 0 && $secondaryCitymunCode > 0) {
            $secondaryCityMatchSql = "SELECT 1 FROM refcitymun WHERE citymunCode = ? AND provCode = ? LIMIT 1";
            $secondaryCityMatchStmt = $conn->prepare($secondaryCityMatchSql);
            if (!$secondaryCityMatchStmt) {
                $flashMessage = 'Unable to validate secondary school city/municipality.';
            } else {
                $secondaryCityMatchStmt->bind_param('ii', $secondaryCitymunCode, $secondaryProvinceCode);
                $secondaryCityMatchStmt->execute();
                $secondaryCityMatch = $secondaryCityMatchStmt->get_result()->fetch_assoc();
                $secondaryCityMatchStmt->close();
                if (!$secondaryCityMatch) {
                    $flashMessage = 'Selected secondary school city/municipality does not match the selected province.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $secondaryCitymunCode > 0 && $secondaryBarangayCode > 0) {
            $secondaryBarangayMatchSql = "SELECT 1 FROM refbrgy WHERE brgyCode = ? AND citymunCode = ? LIMIT 1";
            $secondaryBarangayMatchStmt = $conn->prepare($secondaryBarangayMatchSql);
            if (!$secondaryBarangayMatchStmt) {
                $flashMessage = 'Unable to validate secondary school barangay.';
            } else {
                $secondaryBarangayMatchStmt->bind_param('ii', $secondaryBarangayCode, $secondaryCitymunCode);
                $secondaryBarangayMatchStmt->execute();
                $secondaryBarangayMatch = $secondaryBarangayMatchStmt->get_result()->fetch_assoc();
                $secondaryBarangayMatchStmt->close();
                if (!$secondaryBarangayMatch) {
                    $flashMessage = 'Selected secondary school barangay does not match the selected city/municipality.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $regionCode > 0 && $provinceCode > 0) {
            $provinceMatchSql = "SELECT 1 FROM refprovince WHERE provCode = ? AND regCode = ? LIMIT 1";
            $provinceMatchStmt = $conn->prepare($provinceMatchSql);
            if (!$provinceMatchStmt) {
                $flashMessage = 'Unable to validate province and region.';
            } else {
                $provinceMatchStmt->bind_param('ii', $provinceCode, $regionCode);
                $provinceMatchStmt->execute();
                $provinceMatch = $provinceMatchStmt->get_result()->fetch_assoc();
                $provinceMatchStmt->close();
                if (!$provinceMatch) {
                    $flashMessage = 'Selected province does not match the selected region.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $provinceCode > 0 && $citymunCode > 0) {
            $cityMatchSql = "SELECT 1 FROM refcitymun WHERE citymunCode = ? AND provCode = ? LIMIT 1";
            $cityMatchStmt = $conn->prepare($cityMatchSql);
            if (!$cityMatchStmt) {
                $flashMessage = 'Unable to validate city/municipality.';
            } else {
                $cityMatchStmt->bind_param('ii', $citymunCode, $provinceCode);
                $cityMatchStmt->execute();
                $cityMatch = $cityMatchStmt->get_result()->fetch_assoc();
                $cityMatchStmt->close();
                if (!$cityMatch) {
                    $flashMessage = 'Selected city/municipality does not match the selected province.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $citymunCode > 0 && $barangayCode > 0) {
            $barangayMatchSql = "SELECT 1 FROM refbrgy WHERE brgyCode = ? AND citymunCode = ? LIMIT 1";
            $barangayMatchStmt = $conn->prepare($barangayMatchSql);
            if (!$barangayMatchStmt) {
                $flashMessage = 'Unable to validate barangay.';
            } else {
                $barangayMatchStmt->bind_param('ii', $barangayCode, $citymunCode);
                $barangayMatchStmt->execute();
                $barangayMatch = $barangayMatchStmt->get_result()->fetch_assoc();
                $barangayMatchStmt->close();
                if (!$barangayMatch) {
                    $flashMessage = 'Selected barangay does not match the selected city/municipality.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $parentGuardianRegionCode > 0 && $parentGuardianProvinceCode > 0) {
            $parentGuardianProvinceMatchSql = "SELECT 1 FROM refprovince WHERE provCode = ? AND regCode = ? LIMIT 1";
            $parentGuardianProvinceMatchStmt = $conn->prepare($parentGuardianProvinceMatchSql);
            if (!$parentGuardianProvinceMatchStmt) {
                $flashMessage = 'Unable to validate parent/guardian province and region.';
            } else {
                $parentGuardianProvinceMatchStmt->bind_param('ii', $parentGuardianProvinceCode, $parentGuardianRegionCode);
                $parentGuardianProvinceMatchStmt->execute();
                $parentGuardianProvinceMatch = $parentGuardianProvinceMatchStmt->get_result()->fetch_assoc();
                $parentGuardianProvinceMatchStmt->close();
                if (!$parentGuardianProvinceMatch) {
                    $flashMessage = 'Selected parent/guardian province does not match the selected region.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $parentGuardianProvinceCode > 0 && $parentGuardianCitymunCode > 0) {
            $parentGuardianCityMatchSql = "SELECT 1 FROM refcitymun WHERE citymunCode = ? AND provCode = ? LIMIT 1";
            $parentGuardianCityMatchStmt = $conn->prepare($parentGuardianCityMatchSql);
            if (!$parentGuardianCityMatchStmt) {
                $flashMessage = 'Unable to validate parent/guardian city/municipality.';
            } else {
                $parentGuardianCityMatchStmt->bind_param('ii', $parentGuardianCitymunCode, $parentGuardianProvinceCode);
                $parentGuardianCityMatchStmt->execute();
                $parentGuardianCityMatch = $parentGuardianCityMatchStmt->get_result()->fetch_assoc();
                $parentGuardianCityMatchStmt->close();
                if (!$parentGuardianCityMatch) {
                    $flashMessage = 'Selected parent/guardian city/municipality does not match the selected province.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.' && $parentGuardianCitymunCode > 0 && $parentGuardianBarangayCode > 0) {
            $parentGuardianBarangayMatchSql = "SELECT 1 FROM refbrgy WHERE brgyCode = ? AND citymunCode = ? LIMIT 1";
            $parentGuardianBarangayMatchStmt = $conn->prepare($parentGuardianBarangayMatchSql);
            if (!$parentGuardianBarangayMatchStmt) {
                $flashMessage = 'Unable to validate parent/guardian barangay.';
            } else {
                $parentGuardianBarangayMatchStmt->bind_param('ii', $parentGuardianBarangayCode, $parentGuardianCitymunCode);
                $parentGuardianBarangayMatchStmt->execute();
                $parentGuardianBarangayMatch = $parentGuardianBarangayMatchStmt->get_result()->fetch_assoc();
                $parentGuardianBarangayMatchStmt->close();
                if (!$parentGuardianBarangayMatch) {
                    $flashMessage = 'Selected parent/guardian barangay does not match the selected city/municipality.';
                }
            }
        }

        if ($flashMessage === 'Profile update failed.') {
            $profilePayload = [
                'birth_date' => $birthDate,
                'sex' => $sex,
                'civil_status' => $civilStatus,
                'nationality' => $nationality,
                'religion' => $religion,
                'secondary_school_name' => $secondarySchoolName,
                'secondary_school_type' => $secondarySchoolType,
                'secondary_address_line1' => $secondaryAddressLine1,
                'secondary_region_code' => $secondaryRegionCode,
                'secondary_province_code' => $secondaryProvinceCode,
                'secondary_citymun_code' => $secondaryCitymunCode,
                'secondary_barangay_code' => $secondaryBarangayCode,
                'secondary_postal_code' => $secondaryPostalCode,
                'address_line1' => $addressLine1,
                'region_code' => $regionCode,
                'province_code' => $provinceCode,
                'citymun_code' => $citymunCode,
                'barangay_code' => $barangayCode,
                'postal_code' => $postalCode,
                'parent_guardian_address_line1' => $parentGuardianAddressLine1,
                'parent_guardian_region_code' => $parentGuardianRegionCode,
                'parent_guardian_province_code' => $parentGuardianProvinceCode,
                'parent_guardian_citymun_code' => $parentGuardianCitymunCode,
                'parent_guardian_barangay_code' => $parentGuardianBarangayCode,
                'parent_guardian_postal_code' => $parentGuardianPostalCode,
                'father_name' => $fatherName,
                'father_contact_number' => $fatherContactNumber,
                'father_occupation' => $fatherOccupation,
                'mother_name' => $motherName,
                'mother_contact_number' => $motherContactNumber,
                'mother_occupation' => $motherOccupation,
                'guardian_name' => $guardianName,
                'guardian_relationship' => $guardianRelationship,
                'guardian_contact_number' => $guardianContactNumber,
                'guardian_occupation' => $guardianOccupation,
            ];
            $completionPercent = calculate_student_profile_completion_percent($profilePayload);
            $studentExamineeNumber = normalize_profile_text($student['examinee_number'] ?? '', 50);

            $saveProfileSql = "
                INSERT INTO tbl_student_profile (
                    credential_id,
                    examinee_number,
                    birth_date,
                    sex,
                    civil_status,
                    nationality,
                    religion,
                    secondary_school_name,
                    secondary_school_type,
                    secondary_address_line1,
                    secondary_region_code,
                    secondary_province_code,
                    secondary_citymun_code,
                    secondary_barangay_code,
                    secondary_postal_code,
                    address_line1,
                    region_code,
                    province_code,
                    citymun_code,
                    barangay_code,
                    postal_code,
                    parent_guardian_address_line1,
                    parent_guardian_region_code,
                    parent_guardian_province_code,
                    parent_guardian_citymun_code,
                    parent_guardian_barangay_code,
                    parent_guardian_postal_code,
                    father_name,
                    father_contact_number,
                    father_occupation,
                    mother_name,
                    mother_contact_number,
                    mother_occupation,
                    guardian_name,
                    guardian_relationship,
                    guardian_contact_number,
                    guardian_occupation,
                    profile_completion_percent
                ) VALUES (
                    ?,
                    ?,
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, 0),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    NULLIF(?, ''),
                    ?
                )
                ON DUPLICATE KEY UPDATE
                    examinee_number = VALUES(examinee_number),
                    birth_date = VALUES(birth_date),
                    sex = VALUES(sex),
                    civil_status = VALUES(civil_status),
                    nationality = VALUES(nationality),
                    religion = VALUES(religion),
                    secondary_school_name = VALUES(secondary_school_name),
                    secondary_school_type = VALUES(secondary_school_type),
                    secondary_address_line1 = VALUES(secondary_address_line1),
                    secondary_region_code = VALUES(secondary_region_code),
                    secondary_province_code = VALUES(secondary_province_code),
                    secondary_citymun_code = VALUES(secondary_citymun_code),
                    secondary_barangay_code = VALUES(secondary_barangay_code),
                    secondary_postal_code = VALUES(secondary_postal_code),
                    address_line1 = VALUES(address_line1),
                    region_code = VALUES(region_code),
                    province_code = VALUES(province_code),
                    citymun_code = VALUES(citymun_code),
                    barangay_code = VALUES(barangay_code),
                    postal_code = VALUES(postal_code),
                    parent_guardian_address_line1 = VALUES(parent_guardian_address_line1),
                    parent_guardian_region_code = VALUES(parent_guardian_region_code),
                    parent_guardian_province_code = VALUES(parent_guardian_province_code),
                    parent_guardian_citymun_code = VALUES(parent_guardian_citymun_code),
                    parent_guardian_barangay_code = VALUES(parent_guardian_barangay_code),
                    parent_guardian_postal_code = VALUES(parent_guardian_postal_code),
                    father_name = VALUES(father_name),
                    father_contact_number = VALUES(father_contact_number),
                    father_occupation = VALUES(father_occupation),
                    mother_name = VALUES(mother_name),
                    mother_contact_number = VALUES(mother_contact_number),
                    mother_occupation = VALUES(mother_occupation),
                    guardian_name = VALUES(guardian_name),
                    guardian_relationship = VALUES(guardian_relationship),
                    guardian_contact_number = VALUES(guardian_contact_number),
                    guardian_occupation = VALUES(guardian_occupation),
                    profile_completion_percent = VALUES(profile_completion_percent),
                    updated_at = CURRENT_TIMESTAMP
            ";

            $saveProfileStmt = $conn->prepare($saveProfileSql);
            if (!$saveProfileStmt) {
                $flashMessage = 'Failed to prepare profile save.';
            } else {
                // Keep types aligned with the INSERT column order.
                $bindTypes = 'i'
                    . str_repeat('s', 9)
                    . str_repeat('i', 4)
                    . str_repeat('s', 2)
                    . str_repeat('i', 4)
                    . str_repeat('s', 2)
                    . str_repeat('i', 4)
                    . str_repeat('s', 11)
                    . 'd';
                $saveProfileStmt->bind_param(
                    $bindTypes,
                    $credentialId,
                    $studentExamineeNumber,
                    $birthDate,
                    $sex,
                    $civilStatus,
                    $nationality,
                    $religion,
                    $secondarySchoolName,
                    $secondarySchoolType,
                    $secondaryAddressLine1,
                    $secondaryRegionCode,
                    $secondaryProvinceCode,
                    $secondaryCitymunCode,
                    $secondaryBarangayCode,
                    $secondaryPostalCode,
                    $addressLine1,
                    $regionCode,
                    $provinceCode,
                    $citymunCode,
                    $barangayCode,
                    $postalCode,
                    $parentGuardianAddressLine1,
                    $parentGuardianRegionCode,
                    $parentGuardianProvinceCode,
                    $parentGuardianCitymunCode,
                    $parentGuardianBarangayCode,
                    $parentGuardianPostalCode,
                    $fatherName,
                    $fatherContactNumber,
                    $fatherOccupation,
                    $motherName,
                    $motherContactNumber,
                    $motherOccupation,
                    $guardianName,
                    $guardianRelationship,
                    $guardianContactNumber,
                    $guardianOccupation,
                    $completionPercent
                );

                if ($saveProfileStmt->execute()) {
                    $flashType = 'success';
                    $flashMessage = 'Profile updated successfully. Completion: ' . number_format($completionPercent, 0) . '%.';
                } else {
                    $flashMessage = 'Failed to save profile details.';
                }
                $saveProfileStmt->close();
            }
        }
    }

    $_SESSION['student_profile_flash'] = [
        'type' => $flashType,
        'message' => $flashMessage,
    ];
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'student_preregistration_submit') {
    $flashType = 'danger';
    $flashMessage = 'Pre-registration could not be submitted.';

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_prereg_csrf'] ?? '');
    $agreementAccepted = ((string) ($_POST['prereg_agreement_accept'] ?? '') === '1');
    $interviewId = (int) ($student['interview_id'] ?? 0);
    $currentProgramId = (int) ($student['first_choice'] ?? 0);
    $lockContext = program_ranking_get_interview_lock_context($conn, $interviewId);
    $profileIsComplete = ((float) $studentProfileCompletionPercent >= 100.0);

    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $flashMessage = 'Invalid pre-registration security token. Refresh the page and try again.';
    } elseif ($interviewId <= 0) {
        $flashMessage = 'Interview record is missing. Pre-registration is unavailable.';
    } elseif ($currentProgramId <= 0) {
        $flashMessage = 'Program assignment is missing. Pre-registration is unavailable.';
    } elseif ($lockContext === null) {
        $flashMessage = 'Pre-registration opens only after your rank is locked.';
    } elseif (!$profileIsComplete) {
        $flashMessage = 'Complete your profile to 100% before submitting pre-registration.';
    } elseif (
        is_array($studentPreRegistration)
        && !empty($studentPreRegistration['preregistration_id'])
        && strtolower(trim((string) ($studentPreRegistration['status'] ?? 'submitted'))) === 'submitted'
    ) {
        $flashType = 'success';
        $flashMessage = 'Pre-registration was already submitted.';
    } elseif (!$agreementAccepted) {
        $flashMessage = 'You must accept the SKSU Pre-Registration Agreement before submitting.';
    } else {
        $lockedRank = max(0, (int) ($lockContext['locked_rank'] ?? 0));
        $insertSql = "
            INSERT INTO tbl_student_preregistration (
                credential_id,
                interview_id,
                examinee_number,
                program_id,
                locked_rank,
                profile_completion_percent,
                agreement_accepted,
                agreement_accepted_at,
                status
            ) VALUES (?, ?, ?, ?, NULLIF(?, 0), ?, 1, NOW(), 'submitted')
            ON DUPLICATE KEY UPDATE
                locked_rank = VALUES(locked_rank),
                profile_completion_percent = VALUES(profile_completion_percent),
                agreement_accepted = VALUES(agreement_accepted),
                agreement_accepted_at = VALUES(agreement_accepted_at),
                status = 'submitted',
                forfeited_at = NULL,
                forfeited_by = NULL,
                submitted_at = NOW()
        ";
        $insertStmt = $conn->prepare($insertSql);
        if ($insertStmt) {
            $examineeNumber = (string) ($student['examinee_number'] ?? '');
            $completionPercent = round((float) $studentProfileCompletionPercent, 2);
            $insertStmt->bind_param(
                'iisiid',
                $credentialId,
                $interviewId,
                $examineeNumber,
                $currentProgramId,
                $lockedRank,
                $completionPercent
            );

            if ($insertStmt->execute()) {
                $flashType = 'success';
                $flashMessage = 'Pre-registration submitted successfully.';
            } else {
                $flashMessage = 'Failed to save pre-registration.';
            }
            $insertStmt->close();
        } else {
            $flashMessage = 'Failed to prepare pre-registration.';
        }
    }

    $_SESSION['student_prereg_flash'] = [
        'type' => $flashType,
        'message' => $flashMessage,
    ];
    header('Location: index.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'student_transfer_submit') {
    $flashType = 'danger';
    $flashMessage = 'Transfer request failed.';
    $hasSubmittedPreRegistration = is_array($studentPreRegistration)
        && !empty($studentPreRegistration['preregistration_id'])
        && strtolower(trim((string) ($studentPreRegistration['status'] ?? 'submitted'))) === 'submitted';

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_transfer_csrf'] ?? '');
    $targetProgramId = (int) ($_POST['to_program_id'] ?? 0);
    $transferReason = trim((string) ($_POST['transfer_reason'] ?? ''));
    $transferReasonWords = count_reason_words($transferReason);
    $interviewId = (int) ($student['interview_id'] ?? 0);
    $currentFirstChoiceId = (int) ($student['first_choice'] ?? 0);
    $studentClassGroupForTransfer = (strtoupper(trim((string) ($student['classification'] ?? 'REGULAR'))) === 'ETG')
        ? 'ETG'
        : 'REGULAR';

    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $flashMessage = 'Invalid security token. Refresh the page and try again.';
    } elseif ($interviewId <= 0) {
        $flashMessage = 'Interview record is missing. Transfer cannot be processed.';
    } elseif ($hasSubmittedPreRegistration) {
        $flashMessage = 'Transfers are no longer available because your pre-registration has already been submitted.';
    } elseif (program_ranking_is_interview_locked($conn, $interviewId)) {
        $flashMessage = 'Transfers are no longer available because your current rank is locked.';
    } elseif ($targetProgramId <= 0) {
        $flashMessage = 'Please select a valid transfer program.';
    } elseif ($targetProgramId === $currentFirstChoiceId) {
        $flashMessage = 'Selected program is already your first choice.';
    } elseif ($transferReasonWords < 50) {
        $flashMessage = 'Please provide at least 50 words for your transfer reason.';
    } elseif (!ensure_student_transfer_history_table($conn)) {
        $flashMessage = 'Transfer history table initialization failed.';
    } else {
        $targetProgramSql = "
            SELECT
                p.program_id,
                col.campus_id,
                cam.campus_name,
                pc.cutoff_score,
                pc.absorptive_capacity,
                pc.regular_percentage,
                pc.etg_percentage,
                COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity,
                COALESCE(scored.scored_students, 0) AS scored_students
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
            LEFT JOIN (
                SELECT
                    first_choice AS program_id,
                    COUNT(*) AS scored_students
                FROM tbl_student_interview
                WHERE status = 'active'
                  AND final_score IS NOT NULL
                GROUP BY first_choice
            ) scored
                ON scored.program_id = p.program_id
            WHERE p.program_id = ?
              AND p.status = 'active'
            LIMIT 1
        ";

        $targetStmt = $conn->prepare($targetProgramSql);
        $targetProgram = null;
        if ($targetStmt) {
            $targetStmt->bind_param('i', $targetProgramId);
            $targetStmt->execute();
            $targetProgram = $targetStmt->get_result()->fetch_assoc();
            $targetStmt->close();
        }

        if (!$targetProgram) {
            $flashMessage = 'Selected transfer program is not available.';
        } else {
            $targetCampusId = (int) ($targetProgram['campus_id'] ?? 0);
            $targetCapacity = ($targetProgram['absorptive_capacity'] !== null && $targetProgram['absorptive_capacity'] !== '')
                ? max(0, (int) $targetProgram['absorptive_capacity'])
                : null;
            $targetRawCutoff = ($targetProgram['cutoff_score'] !== null && $targetProgram['cutoff_score'] !== '')
                ? (int) $targetProgram['cutoff_score']
                : null;
            $targetEffectiveCutoff = get_effective_sat_cutoff($targetRawCutoff, $globalSatCutoffEnabled, $globalSatCutoffValue);
            $targetPoolState = student_program_ranking_get_pool_state($studentRankingContext, $targetProgramId, $targetEffectiveCutoff);
            $targetScored = max(0, (int) ($targetPoolState['scored_total'] ?? 0));
            $targetRegularPercentage = ($targetProgram['regular_percentage'] !== null && $targetProgram['regular_percentage'] !== '')
                ? round((float) $targetProgram['regular_percentage'], 2)
                : null;
            $targetEtgPercentage = ($targetProgram['etg_percentage'] !== null && $targetProgram['etg_percentage'] !== '')
                ? round((float) $targetProgram['etg_percentage'], 2)
                : null;
            $targetEndorsementCapacity = max(0, (int) ($targetProgram['endorsement_capacity'] ?? 0));

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
                $targetSlotLimit = ($studentClassGroupForTransfer === 'ETG') ? $targetEtgSlots : $targetRegularSlots;
                $targetQuotaConfigured = true;
            }

            $targetClassRows = ($studentClassGroupForTransfer === 'ETG')
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
            $targetProjectedRank = ($studentTransferCandidateRow !== null)
                ? student_program_ranking_get_projected_rank($targetClassRows, $studentTransferCandidateRow)
                : null;
            $targetSatQualified = ($targetEffectiveCutoff !== null && $studentTransferCandidateRow !== null)
                ? ((float) ($studentTransferCandidateRow['cutoff_basis_score'] ?? 0) >= $targetEffectiveCutoff)
                : false;
            $targetRankQualified = false;
            if ($targetQuotaConfigured && $targetSlotLimit !== null) {
                $targetRankQualified = ($targetProjectedRank !== null && $targetProjectedRank <= $targetSlotLimit);
            } elseif ($targetCapacity !== null) {
                $targetRankQualified = ($targetAvailable > 0);
            }

            if ($studentTransferCandidateRow === null) {
                $flashMessage = 'Final interview score is required before requesting a transfer.';
            } elseif ($targetCampusId <= 0) {
                $flashMessage = 'Selected program campus is not configured.';
            } elseif ($targetCapacity === null) {
                $flashMessage = 'Selected program capacity is not configured.';
            } elseif ($targetEffectiveCutoff === null) {
                $flashMessage = 'Selected program cutoff is not configured.';
            } elseif (!$targetSatQualified) {
                $flashMessage = 'Your score does not meet the selected program cutoff.';
            } elseif (!$targetRankQualified) {
                $flashMessage = $targetQuotaConfigured
                    ? 'Your projected rank is outside the qualified pool for the selected program.'
                    : 'Selected program has no available slots.';
            } else {
                $conn->begin_transaction();

                try {
                    $reloadSql = "
                        SELECT interview_id, first_choice, second_choice, third_choice, program_chair_id
                        FROM tbl_student_interview
                        WHERE interview_id = ?
                          AND status = 'active'
                        LIMIT 1
                        FOR UPDATE
                    ";
                    $reloadStmt = $conn->prepare($reloadSql);
                    if (!$reloadStmt) {
                        throw new Exception('Failed to lock interview row.');
                    }

                    $reloadStmt->bind_param('i', $interviewId);
                    if (!$reloadStmt->execute()) {
                        throw new Exception('Failed to load interview row.');
                    }
                    $lockedInterview = $reloadStmt->get_result()->fetch_assoc();
                    $reloadStmt->close();

                    if (!$lockedInterview) {
                        throw new Exception('Interview record is unavailable.');
                    }

                    $fromProgramId = (int) ($lockedInterview['first_choice'] ?? 0);
                    if ($fromProgramId <= 0) {
                        $fromProgramId = (int) ($student['first_choice'] ?? 0);
                    }
                    if ($fromProgramId === $targetProgramId) {
                        throw new Exception('Selected program is already your first choice.');
                    }

                    $reorderedChoices = build_reordered_program_choices(
                        $targetProgramId,
                        (int) ($lockedInterview['first_choice'] ?? 0),
                        (int) ($lockedInterview['second_choice'] ?? 0),
                        (int) ($lockedInterview['third_choice'] ?? 0)
                    );

                    $newFirstChoice = (int) ($reorderedChoices[0] ?? 0);
                    $newSecondChoice = (int) ($reorderedChoices[1] ?? 0);
                    $newThirdChoice = (int) ($reorderedChoices[2] ?? 0);
                    if ($newFirstChoice <= 0) {
                        throw new Exception('Unable to compute updated program choices.');
                    }

                    $targetProgramChairId = (int) ($lockedInterview['program_chair_id'] ?? 0);
                    $chairSql = "
                        SELECT accountid
                        FROM tblaccount
                        WHERE role = 'progchair'
                          AND status = 'active'
                          AND approved = 1
                          AND program_id = ?
                        ORDER BY updated_at DESC, accountid DESC
                        LIMIT 1
                    ";
                    $chairStmt = $conn->prepare($chairSql);
                    if ($chairStmt) {
                        $chairStmt->bind_param('i', $targetProgramId);
                        $chairStmt->execute();
                        $chairRow = $chairStmt->get_result()->fetch_assoc();
                        if ($chairRow) {
                            $targetProgramChairId = (int) ($chairRow['accountid'] ?? $targetProgramChairId);
                        }
                        $chairStmt->close();
                    }
                    if ($targetProgramChairId <= 0) {
                        $targetProgramChairId = (int) ($lockedInterview['program_chair_id'] ?? 0);
                    }

                    $updateInterviewSql = "
                        UPDATE tbl_student_interview
                        SET first_choice = ?,
                            second_choice = ?,
                            third_choice = ?,
                            program_id = ?,
                            campus_id = ?,
                            program_chair_id = ?
                        WHERE interview_id = ?
                        LIMIT 1
                    ";
                    $updateInterviewStmt = $conn->prepare($updateInterviewSql);
                    if (!$updateInterviewStmt) {
                        throw new Exception('Failed to prepare interview update.');
                    }
                    $updateInterviewStmt->bind_param(
                        'iiiiiii',
                        $newFirstChoice,
                        $newSecondChoice,
                        $newThirdChoice,
                        $targetProgramId,
                        $targetCampusId,
                        $targetProgramChairId,
                        $interviewId
                    );
                    if (!$updateInterviewStmt->execute()) {
                        throw new Exception('Failed to update interview choices.');
                    }
                    $updateInterviewStmt->close();

                    $transferredBy = max(1, $credentialId);
                    $approvedBy = $targetProgramChairId > 0 ? $targetProgramChairId : 0;
                    $insertHistorySql = "
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
                        ) VALUES (?, ?, ?, ?, NOW(), ?, 'approved', NULLIF(?, 0), NOW())
                    ";
                    $insertHistoryStmt = $conn->prepare($insertHistorySql);
                    if (!$insertHistoryStmt) {
                        throw new Exception('Failed to prepare transfer history insert.');
                    }
                    $insertHistoryStmt->bind_param(
                        'iiiisi',
                        $interviewId,
                        $fromProgramId,
                        $targetProgramId,
                        $transferredBy,
                        $transferReason,
                        $approvedBy
                    );
                    if (!$insertHistoryStmt->execute()) {
                        throw new Exception('Failed to write transfer history.');
                    }
                    $insertHistoryStmt->close();

                    $conn->commit();
                    $flashType = 'success';
                    $flashMessage = 'Transfer completed. Your pinned choices were updated.';
                } catch (Exception $e) {
                    $conn->rollback();
                    error_log('Student transfer failed: ' . $e->getMessage());
                    $flashMessage = 'Transfer could not be completed. Please try again.';
                }
            }
        }
    }

    $_SESSION['student_transfer_flash'] = [
        'type' => $flashType,
        'message' => $flashMessage,
    ];
    header('Location: index.php');
    exit;
}

function format_program_label($name, $major)
{
    $name = trim((string) $name);
    $major = trim((string) $major);
    if ($name === '') {
        return 'N/A';
    }
    if ($major === '') {
        return strtoupper($name);
    }
    return strtoupper($name . ' - ' . $major);
}

function resolve_program_campus_name($preferredCampusName, $fallbackCampusName = '', $programId = 0)
{
    $preferredCampusName = trim((string) $preferredCampusName);
    if ($preferredCampusName !== '') {
        return $preferredCampusName;
    }

    $fallbackCampusName = trim((string) $fallbackCampusName);
    if ($fallbackCampusName !== '') {
        return $fallbackCampusName;
    }

    return ((int) $programId > 0) ? 'No campus assigned' : '';
}

$finalScore = $student['final_score'];
$hasScoredInterview = ($finalScore !== null && $finalScore !== '');
$finalScoreDisplay = $hasScoredInterview ? number_format((float) $finalScore, 2) . '%' : 'Pending';
$scoreBadgeClass = $hasScoredInterview ? 'bg-label-success' : 'bg-label-warning';

$studentName = trim((string) ($student['full_name'] ?? ''));
if ($studentName === '') {
    $studentName = 'Student';
}

$studentInitials = '';
$initialTokens = preg_split('/[\s,]+/', $studentName, -1, PREG_SPLIT_NO_EMPTY);
if (is_array($initialTokens)) {
    foreach ($initialTokens as $token) {
        $token = trim((string) $token);
        if ($token === '') {
            continue;
        }
        $studentInitials .= strtoupper(substr($token, 0, 1));
        if (strlen($studentInitials) >= 2) {
            break;
        }
    }
}
if ($studentInitials === '') {
    $studentInitials = 'ST';
}

$firstChoiceId = (int) ($student['first_choice'] ?? 0);
$secondChoiceId = (int) ($student['second_choice'] ?? 0);
$thirdChoiceId = (int) ($student['third_choice'] ?? 0);
$currentExaminee = (string) ($student['examinee_number'] ?? '');
$studentClassGroup = (strtoupper(trim((string) ($student['classification'] ?? 'REGULAR'))) === 'ETG') ? 'ETG' : 'REGULAR';

$choiceStats = [
    'first_choice_scored' => 0,
    'second_choice_scored' => 0,
    'third_choice_scored' => 0,
];

$choiceStatsSql = "
    SELECT
        SUM(CASE WHEN first_choice = ? THEN 1 ELSE 0 END) AS first_choice_scored,
        SUM(CASE WHEN first_choice = ? AND examinee_number <> ? THEN 1 ELSE 0 END) AS second_choice_scored,
        SUM(CASE WHEN first_choice = ? AND examinee_number <> ? THEN 1 ELSE 0 END) AS third_choice_scored
    FROM tbl_student_interview
    WHERE status = 'active'
      AND final_score IS NOT NULL
";

if ($choiceStatsStmt = $conn->prepare($choiceStatsSql)) {
    $choiceStatsStmt->bind_param('iisis', $firstChoiceId, $secondChoiceId, $currentExaminee, $thirdChoiceId, $currentExaminee);
    $choiceStatsStmt->execute();
    $choiceStatsResult = $choiceStatsStmt->get_result()->fetch_assoc();
    if ($choiceStatsResult) {
        $choiceStats['first_choice_scored'] = (int) ($choiceStatsResult['first_choice_scored'] ?? 0);
        $choiceStats['second_choice_scored'] = (int) ($choiceStatsResult['second_choice_scored'] ?? 0);
        $choiceStats['third_choice_scored'] = (int) ($choiceStatsResult['third_choice_scored'] ?? 0);
    }
    $choiceStatsStmt->close();
}

$firstChoiceScoredTotal = (int) ($choiceStats['first_choice_scored'] ?? 0);
$secondChoiceScoredTotal = (int) ($choiceStats['second_choice_scored'] ?? 0);
$thirdChoiceScoredTotal = (int) ($choiceStats['third_choice_scored'] ?? 0);

$firstChoiceAbsorptiveCapacity = null;
$firstChoiceQuotaEnabled = false;
$firstChoiceRegularSlots = null;
$firstChoiceEtgSlots = null;
$firstChoiceEndorsementCapacity = 0;
if ($firstChoiceId > 0) {
    $capacitySql = "
        SELECT
            absorptive_capacity,
            regular_percentage,
            etg_percentage,
            COALESCE(endorsement_capacity, 0) AS endorsement_capacity
        FROM tbl_program_cutoff
        WHERE program_id = ?
        ORDER BY date_updated DESC, cutoff_id DESC
        LIMIT 1
    ";

    if ($capacityStmt = $conn->prepare($capacitySql)) {
        $capacityStmt->bind_param('i', $firstChoiceId);
        $capacityStmt->execute();
        $capacityRow = $capacityStmt->get_result()->fetch_assoc();
        if ($capacityRow) {
            if ($capacityRow['absorptive_capacity'] !== null) {
                $firstChoiceAbsorptiveCapacity = max(0, (int) $capacityRow['absorptive_capacity']);
            }

            $regularPercentage = ($capacityRow['regular_percentage'] !== null && $capacityRow['regular_percentage'] !== '')
                ? round((float) $capacityRow['regular_percentage'], 2)
                : null;
            $etgPercentage = ($capacityRow['etg_percentage'] !== null && $capacityRow['etg_percentage'] !== '')
                ? round((float) $capacityRow['etg_percentage'], 2)
                : null;
            $endorsementCapacity = max(0, (int) ($capacityRow['endorsement_capacity'] ?? 0));
            $firstChoiceEndorsementCapacity = $endorsementCapacity;

            if (
                $firstChoiceAbsorptiveCapacity !== null &&
                $regularPercentage !== null &&
                $etgPercentage !== null &&
                $regularPercentage >= 0 &&
                $regularPercentage <= 100 &&
                $etgPercentage >= 0 &&
                $etgPercentage <= 100 &&
                abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
            ) {
                $baseCapacity = max(0, $firstChoiceAbsorptiveCapacity - $endorsementCapacity);
                $firstChoiceRegularSlots = (int) round($baseCapacity * ($regularPercentage / 100));
                $firstChoiceEtgSlots = max(0, $baseCapacity - $firstChoiceRegularSlots);
                $firstChoiceQuotaEnabled = true;
            }
        }
        $capacityStmt->close();
    }
}

$firstChoiceRank = null;
if ($firstChoiceId > 0 && $hasScoredInterview) {
    $rankSql = "
        SELECT
            si2.interview_id,
            si2.examinee_number,
            CASE
                WHEN UPPER(COALESCE(si2.classification, 'REGULAR')) = 'ETG' THEN 1
                ELSE 0
            END AS classification_group
        FROM tbl_student_interview si2
        INNER JOIN tbl_placement_results pr2
            ON pr2.id = si2.placement_result_id
        WHERE si2.status = 'active'
          AND si2.final_score IS NOT NULL
          AND si2.first_choice = ?
        ORDER BY
            classification_group ASC,
            si2.final_score DESC,
            pr2.sat_score DESC,
            pr2.full_name ASC,
            si2.examinee_number ASC
    ";

    if ($rankStmt = $conn->prepare($rankSql)) {
        $rankStmt->bind_param('i', $firstChoiceId);
        $rankStmt->execute();
        $rankResult = $rankStmt->get_result();

        $allRegularRows = [];
        $allEtgRows = [];
        $allRowsByInterviewId = [];
        while ($rankRow = $rankResult->fetch_assoc()) {
            $mappedRow = [
                'interview_id' => (int) ($rankRow['interview_id'] ?? 0),
                'examinee_number' => (string) ($rankRow['examinee_number'] ?? ''),
            ];

            $allRowsByInterviewId[$mappedRow['interview_id']] = $mappedRow;

            if ((int) ($rankRow['classification_group'] ?? 0) === 1) {
                $allEtgRows[] = $mappedRow;
            } else {
                $allRegularRows[] = $mappedRow;
            }
        }

        $rankStmt->close();

        $endorsementRows = [];
        $endorsementIds = [];
        $endorsementSql = "
            SELECT interview_id
            FROM tbl_program_endorsements
            WHERE program_id = ?
            ORDER BY endorsed_at ASC, endorsement_id ASC
        ";
        if ($endorsementStmt = $conn->prepare($endorsementSql)) {
            $endorsementStmt->bind_param('i', $firstChoiceId);
            $endorsementStmt->execute();
            $endorsementResult = $endorsementStmt->get_result();

            while ($endorsementRow = $endorsementResult->fetch_assoc()) {
                $endorsementInterviewId = (int) ($endorsementRow['interview_id'] ?? 0);
                if ($endorsementInterviewId <= 0 || !isset($allRowsByInterviewId[$endorsementInterviewId])) {
                    continue;
                }

                $endorsementRows[] = $allRowsByInterviewId[$endorsementInterviewId];
                $endorsementIds[$endorsementInterviewId] = true;
            }

            $endorsementStmt->close();
        }

        $filteredRegularRows = array_values(array_filter($allRegularRows, function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));
        $filteredEtgRows = array_values(array_filter($allEtgRows, function (array $row) use ($endorsementIds): bool {
            return !isset($endorsementIds[(int) ($row['interview_id'] ?? 0)]);
        }));

        if ($firstChoiceQuotaEnabled) {
            $regularLimit = max(0, (int) $firstChoiceRegularSlots);
            $endorsementLimit = max(0, (int) $firstChoiceEndorsementCapacity);
            $etgLimit = max(0, (int) $firstChoiceEtgSlots);

            $regularInsideRows = array_slice($filteredRegularRows, 0, $regularLimit);
            $regularOutsideRows = array_slice($filteredRegularRows, $regularLimit);

            $endorsementInsideRows = array_slice($endorsementRows, 0, $endorsementLimit);
            $endorsementOutsideRows = array_slice($endorsementRows, $endorsementLimit);

            $etgInsideRows = array_slice($filteredEtgRows, 0, $etgLimit);
            $etgOutsideRows = array_slice($filteredEtgRows, $etgLimit);

            $rankingRows = array_merge(
                $regularInsideRows,
                $endorsementInsideRows,
                $etgInsideRows,
                $regularOutsideRows,
                $endorsementOutsideRows,
                $etgOutsideRows
            );
        } else {
            $rankingRows = array_merge($filteredRegularRows, $endorsementRows, $filteredEtgRows);
        }

        $position = 1;
        foreach ($rankingRows as $rankRow) {
            if ((string) ($rankRow['examinee_number'] ?? '') === $currentExaminee) {
                $firstChoiceRank = $position;
                break;
            }
            $position++;
        }
    }
}

$firstChoiceRankTotal = ($firstChoiceAbsorptiveCapacity !== null)
    ? $firstChoiceAbsorptiveCapacity
    : $firstChoiceScoredTotal;

$firstChoiceRankValueDisplay = null;
$firstChoiceRankTotalDisplay = ($firstChoiceId > 0) ? number_format($firstChoiceRankTotal) : null;
$firstChoiceRankDisplay = 'N/A';
$firstChoiceRankLabel = 'Overall Rank / Total Ranked';
$firstChoicePoolRank = $firstChoiceRank;
$firstChoicePoolTotal = $firstChoiceRankTotal;
$firstChoicePoolRankValueDisplay = null;
$firstChoicePoolRankTotalDisplay = $firstChoiceRankTotalDisplay;
$firstChoicePoolRankDisplay = 'N/A';
$firstChoiceRankingSection = 'regular';
$firstChoiceRankingSectionLabel = student_program_ranking_section_label($firstChoiceRankingSection);
$firstChoiceRawRank = $firstChoiceRank;
$firstChoiceRegularEffectiveSlots = $firstChoiceRegularSlots;
$firstChoiceEndorsementShown = 0;
$firstChoiceEtgShown = 0;
$firstChoiceQuotaAdjustedPosition = null;
$firstChoiceQuotaAdjustedPositionDisplay = null;
$firstChoiceQuotaNote = '';
$firstChoiceHasSharedRankingRow = false;
if ($firstChoiceId > 0) {
    if ($hasScoredInterview && $firstChoiceRank !== null) {
        $firstChoiceRankValueDisplay = number_format($firstChoiceRank);
        $firstChoiceRankDisplay = $firstChoiceRankValueDisplay . ' / ' . $firstChoiceRankTotalDisplay;
    } elseif ($hasScoredInterview) {
        $firstChoiceRankDisplay = 'N/A / ' . $firstChoiceRankTotalDisplay;
    } else {
        $firstChoiceRankValueDisplay = 'Pending';
        $firstChoiceRankDisplay = 'Pending / ' . $firstChoiceRankTotalDisplay;
    }

    $firstChoicePoolRankValueDisplay = $firstChoiceRankValueDisplay;
    $firstChoicePoolRankDisplay = $firstChoiceRankDisplay;
}

$firstChoiceWithinCapacity = false;
$firstChoiceOutsideCapacity = false;
$firstChoiceStatusText = 'Interview score pending';
$firstChoiceStatusClass = 'status-pending';

if ($hasScoredInterview && $firstChoiceRank !== null && $firstChoiceAbsorptiveCapacity !== null) {
    if ($firstChoiceRank <= $firstChoiceAbsorptiveCapacity) {
        $firstChoiceWithinCapacity = true;
        $firstChoiceStatusText = 'Within absorptive capacity';
        $firstChoiceStatusClass = 'status-ok';
    } else {
        $firstChoiceOutsideCapacity = true;
        $firstChoiceStatusText = 'Outside absorptive capacity';
        $firstChoiceStatusClass = 'status-out';
    }
} elseif ($hasScoredInterview && $firstChoiceRank !== null) {
    $firstChoiceStatusText = 'Capacity not configured';
}

$studentLockContext = program_ranking_get_interview_lock_context($conn, (int) ($student['interview_id'] ?? 0));
$studentRankLocked = ($studentLockContext !== null);
$studentLockedRank = $studentRankLocked ? max(0, (int) ($studentLockContext['locked_rank'] ?? 0)) : 0;

if ($firstChoiceId > 0 && $hasScoredInterview) {
    $sharedRankingPayload = program_ranking_fetch_payload($conn, $firstChoiceId, null);
    if (!empty($sharedRankingPayload['success']) && isset($sharedRankingPayload['rows']) && is_array($sharedRankingPayload['rows'])) {
        $sharedRankingRows = array_values($sharedRankingPayload['rows']);
        $firstChoiceRankTotal = count($sharedRankingRows);
        $currentInterviewId = (int) ($student['interview_id'] ?? 0);
        $matchingRankingRow = null;
        $matchedByInterview = false;
        $insideQualifiedCount = 0;
        $lastInsideLiveRank = 0;
        $outsideRowCount = 0;
        $sharedQuota = isset($sharedRankingPayload['quota']) && is_array($sharedRankingPayload['quota'])
            ? $sharedRankingPayload['quota']
            : [];

        if (array_key_exists('regular_effective_slots', $sharedQuota) && $sharedQuota['regular_effective_slots'] !== null) {
            $firstChoiceRegularEffectiveSlots = max(0, (int) $sharedQuota['regular_effective_slots']);
        }
        if (array_key_exists('endorsement_shown', $sharedQuota)) {
            $firstChoiceEndorsementShown = max(0, (int) $sharedQuota['endorsement_shown']);
        }
        if (array_key_exists('etg_shown', $sharedQuota)) {
            $firstChoiceEtgShown = max(0, (int) $sharedQuota['etg_shown']);
        }

        foreach ($sharedRankingRows as $rankingRow) {
            $rowInterviewId = (int) ($rankingRow['interview_id'] ?? 0);
            $rowExamineeNumber = (string) ($rankingRow['examinee_number'] ?? '');
            $rowSection = strtolower(trim((string) ($rankingRow['row_section'] ?? 'regular')));
            if ($rowSection === '') {
                $rowSection = 'regular';
            }
            $rowOutside = !empty($rankingRow['is_outside_capacity']);

            if ($rowOutside) {
                $outsideRowCount++;
            } else {
                $insideQualifiedCount++;
                $lastInsideLiveRank = max($lastInsideLiveRank, (int) ($rankingRow['rank'] ?? 0));
            }

            $isInterviewMatch = ($currentInterviewId > 0 && $rowInterviewId === $currentInterviewId);
            $isExamineeMatch = ($rowExamineeNumber !== '' && $rowExamineeNumber === $currentExaminee);
            if ($isInterviewMatch || (!$matchedByInterview && $isExamineeMatch)) {
                $matchingRankingRow = $rankingRow;
            }

            if ($isInterviewMatch) {
                $matchedByInterview = true;
            }
        }

        $firstChoiceRankTotalDisplay = number_format($firstChoiceRankTotal);

        if ($matchingRankingRow !== null) {
            $firstChoiceHasSharedRankingRow = true;
            $firstChoiceRank = max(0, (int) ($matchingRankingRow['rank'] ?? 0));
            $firstChoiceRawRank = $firstChoiceRank;
            $firstChoiceRankValueDisplay = $firstChoiceRank > 0 ? number_format($firstChoiceRank) : 'N/A';
            $firstChoiceRankDisplay = $firstChoiceRankValueDisplay . ' / ' . $firstChoiceRankTotalDisplay;
            $firstChoiceRankingSection = strtolower(trim((string) ($matchingRankingRow['row_section'] ?? 'regular')));
            if ($firstChoiceRankingSection === '') {
                $firstChoiceRankingSection = 'regular';
            }
            $firstChoiceRankingSectionLabel = student_program_ranking_section_label($firstChoiceRankingSection);

            $sectionRank = 0;
            $sectionTotal = 0;
            foreach ($sharedRankingRows as $rankingRow) {
                $rowSection = strtolower(trim((string) ($rankingRow['row_section'] ?? 'regular')));
                if ($rowSection !== $firstChoiceRankingSection) {
                    continue;
                }

                $sectionTotal++;
                $rowInterviewId = (int) ($rankingRow['interview_id'] ?? 0);
                $rowExamineeNumber = (string) ($rankingRow['examinee_number'] ?? '');
                if (
                    $sectionRank === 0 &&
                    (
                        ($currentInterviewId > 0 && $rowInterviewId === $currentInterviewId) ||
                        ($rowExamineeNumber !== '' && $rowExamineeNumber === $currentExaminee)
                    )
                ) {
                    $sectionRank = $sectionTotal;
                }
            }

            if ($sectionTotal > 0) {
                $firstChoicePoolTotal = $sectionTotal;
                $firstChoicePoolRankTotalDisplay = number_format($sectionTotal);
            }

            if ($sectionRank > 0) {
                $firstChoicePoolRank = $sectionRank;
                $firstChoicePoolRankValueDisplay = number_format($sectionRank);
                $firstChoicePoolRankDisplay = $firstChoicePoolRankValueDisplay . ' / ' . $firstChoicePoolRankTotalDisplay;
            } else {
                $firstChoicePoolRank = null;
                $firstChoicePoolRankValueDisplay = 'N/A';
                $firstChoicePoolRankDisplay = 'N/A / ' . ($firstChoicePoolRankTotalDisplay ?? $firstChoiceRankTotalDisplay);
            }

            $isOutsideCapacity = !empty($matchingRankingRow['is_outside_capacity']);
            $firstChoiceWithinCapacity = !$isOutsideCapacity;
            $firstChoiceOutsideCapacity = $isOutsideCapacity;

            $quotaDisplayEnabled = (
                $firstChoiceAbsorptiveCapacity !== null &&
                !empty($sharedQuota['enabled']) &&
                array_key_exists('regular_slots', $sharedQuota) &&
                array_key_exists('endorsement_capacity', $sharedQuota) &&
                array_key_exists('etg_slots', $sharedQuota)
            );

            if ($quotaDisplayEnabled) {
                $displayPosition = $firstChoiceRank;
                $hiddenInsideSlots = max(0, (int) $firstChoiceAbsorptiveCapacity - $insideQualifiedCount);
                if ($firstChoiceRank > 0 && $firstChoiceRank > $lastInsideLiveRank) {
                    $displayPosition += $hiddenInsideSlots;
                }

                $displayTotal = max(0, count($sharedRankingRows) + $hiddenInsideSlots);
                $firstChoiceRankValueDisplay = $displayPosition > 0 ? number_format($displayPosition) : 'N/A';
                $firstChoiceRankTotalDisplay = number_format($displayTotal);
                $firstChoiceRankDisplay = $firstChoiceRankValueDisplay . ' / ' . $firstChoiceRankTotalDisplay;

                if ($firstChoiceOutsideCapacity && $firstChoiceRankingSection === 'regular') {
                    $firstChoiceQuotaAdjustedPosition = $displayPosition;
                    $firstChoiceQuotaAdjustedPositionDisplay = number_format($displayPosition) . ' / ' . number_format((int) $firstChoiceAbsorptiveCapacity);
                }
            }

            if (!empty($matchingRankingRow['is_locked'])) {
                $studentRankLocked = true;
                if ($studentLockedRank <= 0) {
                    $studentLockedRank = max(0, (int) ($matchingRankingRow['locked_rank'] ?? $firstChoiceRank));
                }
            }

            if ($studentRankLocked) {
                $firstChoiceStatusText = $studentLockedRank > 0
                    ? ('Rank locked at #' . number_format($studentLockedRank))
                    : 'Rank locked for pre-registration';
                $firstChoiceStatusClass = 'status-ok';
            } elseif ($firstChoiceOutsideCapacity) {
                if ($firstChoiceRankingSection === 'regular' && $firstChoiceQuotaAdjustedPosition !== null) {
                    $firstChoiceStatusText = 'Outside regular quota';
                } elseif ($firstChoiceRankingSection === 'scc') {
                    $firstChoiceStatusText = 'Outside SCC quota';
                } elseif ($firstChoiceRankingSection === 'etg') {
                    $firstChoiceStatusText = 'Outside ETG quota';
                } else {
                    $firstChoiceStatusText = 'Outside ranked pool';
                }
                $firstChoiceStatusClass = 'status-out';
            } else {
                if ($firstChoiceRankingSection === 'regular' && $firstChoiceRegularEffectiveSlots !== null) {
                    $firstChoiceStatusText = 'Within regular quota';
                } elseif ($firstChoiceRankingSection === 'scc') {
                    $firstChoiceStatusText = 'Within SCC quota';
                } elseif ($firstChoiceRankingSection === 'etg') {
                    $firstChoiceStatusText = 'Within ETG quota';
                } else {
                    $firstChoiceStatusText = 'Within ranked pool';
                }
                $firstChoiceStatusClass = 'status-ok';
            }
        } elseif ($hasScoredInterview) {
            $firstChoiceRankValueDisplay = 'N/A';
            $firstChoiceRankDisplay = 'N/A / ' . $firstChoiceRankTotalDisplay;
            $firstChoicePoolRankValueDisplay = 'N/A';
            $firstChoicePoolRankTotalDisplay = $firstChoiceRankTotalDisplay;
            $firstChoicePoolRankDisplay = 'N/A / ' . $firstChoiceRankTotalDisplay;
            $firstChoiceStatusText = 'Not included in the ranked pool';
            $firstChoiceStatusClass = 'status-out';
            $firstChoiceWithinCapacity = false;
            $firstChoiceOutsideCapacity = true;
        }
    }
}

$firstChoiceDisplayRankLabel = $firstChoiceRankLabel;
$firstChoiceDisplayRankValue = $firstChoiceRankDisplay;
$firstChoiceDisplayRankPrimaryValue = $firstChoiceRankValueDisplay;
$firstChoiceDisplayRankSecondaryValue = $firstChoiceRankTotalDisplay;
if (!$studentRankLocked && $firstChoiceHasSharedRankingRow) {
    $firstChoiceDisplayRankLabel = $firstChoiceRankingSectionLabel . ' Rank / Total ' . $firstChoiceRankingSectionLabel;
    $firstChoiceDisplayRankValue = $firstChoicePoolRankDisplay;
    $firstChoiceDisplayRankPrimaryValue = $firstChoicePoolRankValueDisplay;
    $firstChoiceDisplayRankSecondaryValue = $firstChoicePoolRankTotalDisplay;
}

$transferActionEnabled = (!$studentRankLocked && $firstChoiceOutsideCapacity && !$isAdminStudentPreview);
$transferActionHref = $transferActionEnabled ? '#program-slot-availability-tab' : 'javascript:void(0);';
$transferActionClass = $transferActionEnabled ? '' : ' is-disabled';
$transferActionTitle = $studentRankLocked ? 'Transfer Closed' : 'Transfer';
$transferActionSub = $studentRankLocked
    ? 'Unavailable while your program rank is locked'
    : ($isAdminStudentPreview
        ? 'Disabled during administrator preview'
        : ($transferActionEnabled
            ? 'Outside capacity: explore open programs'
            : 'Available when rank is outside capacity'));
$changePasswordActionHref = ($studentRankLocked || $isAdminStudentPreview) ? 'javascript:void(0);' : 'change_password.php';
$changePasswordActionClass = ($studentRankLocked || $isAdminStudentPreview) ? ' is-disabled' : '';
$changePasswordActionSub = $studentRankLocked
    ? 'Unavailable while pre-registration is active'
    : ($isAdminStudentPreview ? 'Disabled during administrator preview' : 'Update account security');
$logsActionSub = $studentRankLocked
    ? 'Unavailable while pre-registration is active'
    : ($isAdminStudentPreview ? 'Disabled during administrator preview' : 'Feature coming soon');

$studentCutoffBasisScore = ($student['cutoff_basis_score'] !== null && $student['cutoff_basis_score'] !== '')
    ? (float) $student['cutoff_basis_score']
    : null;

$allPrograms = [];
$allProgramsSql = "
    SELECT
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        col.campus_id,
        cam.campus_name,
        pc.absorptive_capacity,
        pc.cutoff_score,
        pc.regular_percentage,
        pc.etg_percentage,
        COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity,
        COALESCE(scored.scored_students, 0) AS scored_students
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
    LEFT JOIN (
        SELECT
            first_choice AS program_id,
            COUNT(*) AS scored_students
        FROM tbl_student_interview
        WHERE status = 'active'
          AND final_score IS NOT NULL
        GROUP BY first_choice
    ) scored
        ON scored.program_id = p.program_id
    WHERE p.status = 'active'
    ORDER BY p.program_name ASC, p.major ASC
";

if ($allProgramsStmt = $conn->prepare($allProgramsSql)) {
    $allProgramsStmt->execute();
    $allProgramsResult = $allProgramsStmt->get_result();

    while ($programRow = $allProgramsResult->fetch_assoc()) {
        $programId = (int) ($programRow['program_id'] ?? 0);
        $capacityValue = $programRow['absorptive_capacity'];
        $capacity = ($capacityValue !== null && $capacityValue !== '') ? max(0, (int) $capacityValue) : null;
        $cutoffScore = ($programRow['cutoff_score'] !== null && $programRow['cutoff_score'] !== '')
            ? (int) $programRow['cutoff_score']
            : null;

        $regularPercentage = ($programRow['regular_percentage'] !== null && $programRow['regular_percentage'] !== '')
            ? round((float) $programRow['regular_percentage'], 2)
            : null;
        $etgPercentage = ($programRow['etg_percentage'] !== null && $programRow['etg_percentage'] !== '')
            ? round((float) $programRow['etg_percentage'], 2)
            : null;

        $effectiveCutoff = get_effective_sat_cutoff($cutoffScore, $globalSatCutoffEnabled, $globalSatCutoffValue);
        $poolState = student_program_ranking_get_pool_state($studentRankingContext, $programId, $effectiveCutoff);
        $scoredStudents = max(0, (int) ($poolState['scored_total'] ?? 0));
        $endorsementCapacity = max(0, (int) ($programRow['endorsement_capacity'] ?? 0));
        $classRows = ($studentClassGroup === 'ETG')
            ? (array) ($poolState['etg_rows'] ?? [])
            : (array) ($poolState['regular_rows'] ?? []);
        $classScoredCount = count($classRows);
        $availableSlots = null;

        $quotaConfigured = false;
        $regularSlots = null;
        $etgSlots = null;
        if (
            $capacity !== null &&
            $regularPercentage !== null &&
            $etgPercentage !== null &&
            $regularPercentage >= 0 &&
            $regularPercentage <= 100 &&
            $etgPercentage >= 0 &&
            $etgPercentage <= 100 &&
            abs(($regularPercentage + $etgPercentage) - 100) <= 0.01
        ) {
            $quotaConfigured = true;
            $baseCapacity = max(0, $capacity - $endorsementCapacity);
            $regularSlots = (int) round($baseCapacity * ($regularPercentage / 100));
            $etgSlots = max(0, $baseCapacity - $regularSlots);
        }

        $studentSlotLimit = null;
        if ($quotaConfigured) {
            $studentSlotLimit = ($studentClassGroup === 'ETG') ? $etgSlots : $regularSlots;
        }

        if ($capacity !== null) {
            if ($quotaConfigured && $studentSlotLimit !== null) {
                $availableSlots = max(0, (int) $studentSlotLimit - $classScoredCount);
            } else {
                $availableSlots = max(0, $capacity - $scoredStudents);
            }
        }

        $studentProjectedRank = null;
        if ($studentTransferCandidateRow !== null && $programId > 0) {
            $studentProjectedRank = student_program_ranking_get_projected_rank($classRows, $studentTransferCandidateRow);
        }

        $canEvaluateSatQualification = ($effectiveCutoff !== null && $studentCutoffBasisScore !== null);
        $satQualified = $canEvaluateSatQualification
            ? ($studentCutoffBasisScore >= $effectiveCutoff)
            : false;
        $slotStatus = 'Capacity not set';
        if ($capacity !== null) {
            if ($quotaConfigured && $studentProjectedRank !== null) {
                $isProgramFull = ($scoredStudents >= $capacity);
                if ($studentSlotLimit !== null && $studentProjectedRank <= $studentSlotLimit) {
                    $slotStatus = $canEvaluateSatQualification
                        ? ($satQualified ? 'Qualified' : 'SAT Below Cutoff')
                        : 'Open';
                } else {
                    $slotStatus = $isProgramFull ? 'Full' : 'Outside Ranked Pool';
                }
            } else {
                if ($availableSlots > 0) {
                    $slotStatus = $canEvaluateSatQualification
                        ? ($satQualified ? 'Qualified' : 'SAT Below Cutoff')
                        : 'Open';
                } else {
                    $slotStatus = 'Full';
                }
            }
        }
        $rankQualified = false;
        if ($quotaConfigured && $studentSlotLimit !== null) {
            $rankQualified = ($studentProjectedRank !== null && $studentProjectedRank <= $studentSlotLimit);
        } elseif ($capacity !== null) {
            $rankQualified = ($availableSlots > 0);
        }
        $transferOpen = ($programId !== $firstChoiceId) && $rankQualified && $satQualified;

        $allPrograms[] = [
            'program_id' => $programId,
            'program_code' => strtoupper(trim((string) ($programRow['program_code'] ?? ''))),
            'program_label' => format_program_label($programRow['program_name'] ?? '', $programRow['major'] ?? ''),
            'campus_id' => (int) ($programRow['campus_id'] ?? 0),
            'campus_name' => resolve_program_campus_name($programRow['campus_name'] ?? '', '', $programId),
            'absorptive_capacity' => $capacity,
            'cutoff_score' => $effectiveCutoff,
            'regular_percentage' => $regularPercentage,
            'etg_percentage' => $etgPercentage,
            'regular_slots' => $regularSlots,
            'etg_slots' => $etgSlots,
            'endorsement_capacity' => $endorsementCapacity,
            'scored_students' => $scoredStudents,
            'class_scored' => $classScoredCount,
            'available_slots' => $availableSlots,
            'slot_status' => $slotStatus,
            'quota_configured' => $quotaConfigured,
            'student_slot_limit' => $studentSlotLimit,
            'student_rank' => $studentProjectedRank,
            'sat_qualified' => $satQualified,
            'rank_qualified' => $rankQualified,
            'transfer_open' => $transferOpen,
        ];
    }

    $allProgramsStmt->close();
}

if ($studentRankLocked) {
    foreach ($allPrograms as &$programItem) {
        $programItem['transfer_open'] = false;
    }
    unset($programItem);
}

$slotPrograms = $studentRankLocked ? [] : $allPrograms;

$programIndexById = [];
foreach ($allPrograms as $programItem) {
    $programId = (int) ($programItem['program_id'] ?? 0);
    if ($programId > 0) {
        $programIndexById[$programId] = $programItem;
    }
}

$buildChoiceSlotDetails = function ($programId, $scoredStudents) use ($programIndexById, $hasScoredInterview) {
    $programId = (int) $programId;
    $programData = ($programId > 0 && isset($programIndexById[$programId])) ? $programIndexById[$programId] : null;

    $programCode = strtoupper(trim((string) ($programData['program_code'] ?? '')));
    $cutoffScore = null;
    if ($programData !== null && array_key_exists('cutoff_score', $programData) && $programData['cutoff_score'] !== null) {
        $cutoffScore = (int) $programData['cutoff_score'];
    }

    $capacity = null;
    if ($programData !== null && array_key_exists('absorptive_capacity', $programData) && $programData['absorptive_capacity'] !== null) {
        $capacity = max(0, (int) $programData['absorptive_capacity']);
    }

    $available = ($programData !== null && array_key_exists('available_slots', $programData))
        ? (($programData['available_slots'] !== null) ? max(0, (int) $programData['available_slots']) : null)
        : (($capacity !== null) ? max(0, $capacity - max(0, (int) $scoredStudents)) : null);

    $slotStatus = (string) ($programData['slot_status'] ?? 'Capacity not set');

    $slotBadgeClass = 'bg-label-secondary';
    if ($slotStatus === 'Open' || $slotStatus === 'Qualified') {
        $slotBadgeClass = 'bg-label-success';
    } elseif ($slotStatus === 'Full' || $slotStatus === 'Outside Ranked Pool' || $slotStatus === 'SAT Below Cutoff') {
        $slotBadgeClass = 'bg-label-danger';
    }

    $satQualified = (bool) ($programData['sat_qualified'] ?? false);
    $rankQualified = (bool) ($programData['rank_qualified'] ?? false);
    $transferOpen = (bool) ($programData['transfer_open'] ?? false);

    $qualificationNote = 'No transfer evaluation';
    if (!$hasScoredInterview) {
        $qualificationNote = 'Final interview score required';
    } elseif ($capacity === null) {
        $qualificationNote = 'Capacity not set';
    } elseif ($cutoffScore === null) {
        $qualificationNote = 'Cutoff SAT not configured';
    } elseif (!$satQualified) {
        $qualificationNote = 'SAT below cutoff';
    } elseif (!$rankQualified && $slotStatus === 'Outside Ranked Pool') {
        $qualificationNote = 'Projected rank is outside the qualified pool';
    } elseif (!$rankQualified && $slotStatus === 'Full') {
        $qualificationNote = 'No open slots available';
    } elseif ($transferOpen) {
        $qualificationNote = 'Eligible for transfer';
    }

    return [
        'program_id' => $programId,
        'program_code' => $programCode,
        'campus_name' => resolve_program_campus_name($programData['campus_name'] ?? '', '', $programId),
        'cutoff_display' => ($cutoffScore !== null) ? number_format($cutoffScore) : 'N/A',
        'capacity_display' => ($capacity !== null) ? number_format($capacity) : 'N/A',
        'available_display' => ($available !== null) ? number_format($available) : 'N/A',
        'slot_status' => $slotStatus,
        'slot_badge_class' => $slotBadgeClass,
        'transfer_open' => $transferOpen,
        'qualification_note' => $qualificationNote,
    ];
};

$firstChoiceSlotDetails = $buildChoiceSlotDetails($firstChoiceId, $firstChoiceScoredTotal);
$secondChoiceSlotDetails = $buildChoiceSlotDetails($secondChoiceId, $secondChoiceScoredTotal);
$thirdChoiceSlotDetails = $buildChoiceSlotDetails($thirdChoiceId, $thirdChoiceScoredTotal);

$firstChoiceCampusName = resolve_program_campus_name(
    $student['first_program_campus_name'] ?? '',
    $firstChoiceSlotDetails['campus_name'] ?? '',
    $firstChoiceId
);
$secondChoiceCampusName = resolve_program_campus_name(
    $student['second_program_campus_name'] ?? '',
    $secondChoiceSlotDetails['campus_name'] ?? '',
    $secondChoiceId
);
$thirdChoiceCampusName = resolve_program_campus_name(
    $student['third_program_campus_name'] ?? '',
    $thirdChoiceSlotDetails['campus_name'] ?? '',
    $thirdChoiceId
);

$firstChoicePrimaryMetaRows = [
    ['label' => 'Total Capacity', 'value' => $firstChoiceSlotDetails['capacity_display']],
];

$firstChoicePrimaryMetaRows[] = ['label' => 'Available Slots', 'value' => $firstChoiceSlotDetails['available_display']];
$firstChoicePrimaryMetaRows[] = ['label' => 'Cutoff SAT', 'value' => $firstChoiceSlotDetails['cutoff_display']];
if ($firstChoiceHasSharedRankingRow) {
    $firstChoicePrimaryMetaRows[] = ['label' => 'Ranking Pool', 'value' => $firstChoiceRankingSectionLabel];
    $firstChoicePrimaryMetaRows[] = ['label' => 'Overall Rank', 'value' => $firstChoiceRankDisplay];
}

$programChoiceCards = [
    [
        'title' => '1st Choice Program',
        'program' => format_program_label($student['first_program_name'] ?? '', $student['first_program_major'] ?? ''),
        'program_code' => $firstChoiceSlotDetails['program_code'],
        'campus_name' => $firstChoiceCampusName,
        'is_primary' => true,
        'stat_label' => $firstChoiceDisplayRankLabel,
        'stat_value' => $firstChoiceDisplayRankValue,
        'stat_primary_value' => $firstChoiceDisplayRankPrimaryValue,
        'stat_secondary_value' => $firstChoiceDisplayRankSecondaryValue,
        'stat_primary_outside' => $firstChoiceOutsideCapacity,
        'meta_rows' => $firstChoicePrimaryMetaRows,
        'slot_status' => $firstChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $firstChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => '',
        'show_transfer' => false,
        'transfer_program_id' => $firstChoiceSlotDetails['program_id'],
        'transfer_capacity' => $firstChoiceSlotDetails['capacity_display'],
        'transfer_available' => $firstChoiceSlotDetails['available_display'],
        'transfer_cutoff' => $firstChoiceSlotDetails['cutoff_display'],
        'status_text' => $firstChoiceStatusText,
        'status_class' => $firstChoiceStatusClass,
    ],
    [
        'title' => '2nd Choice Program',
        'program' => format_program_label($student['second_program_name'] ?? '', $student['second_program_major'] ?? ''),
        'program_code' => $secondChoiceSlotDetails['program_code'],
        'campus_name' => $secondChoiceCampusName,
        'is_primary' => false,
        'stat_label' => 'Available Slots',
        'stat_value' => $secondChoiceSlotDetails['available_display'],
        'stat_primary_value' => null,
        'stat_secondary_value' => null,
        'stat_primary_outside' => false,
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $secondChoiceSlotDetails['capacity_display']],
            ['label' => 'Available Slots', 'value' => $secondChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $secondChoiceSlotDetails['cutoff_display']],
        ],
        'slot_status' => $secondChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $secondChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => $secondChoiceSlotDetails['qualification_note'],
        'show_transfer' => $secondChoiceSlotDetails['transfer_open'],
        'transfer_program_id' => $secondChoiceSlotDetails['program_id'],
        'transfer_capacity' => $secondChoiceSlotDetails['capacity_display'],
        'transfer_available' => $secondChoiceSlotDetails['available_display'],
        'transfer_cutoff' => $secondChoiceSlotDetails['cutoff_display'],
        'status_text' => '',
        'status_class' => '',
    ],
    [
        'title' => '3rd Choice Program',
        'program' => format_program_label($student['third_program_name'] ?? '', $student['third_program_major'] ?? ''),
        'program_code' => $thirdChoiceSlotDetails['program_code'],
        'campus_name' => $thirdChoiceCampusName,
        'is_primary' => false,
        'stat_label' => 'Available Slots',
        'stat_value' => $thirdChoiceSlotDetails['available_display'],
        'stat_primary_value' => null,
        'stat_secondary_value' => null,
        'stat_primary_outside' => false,
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $thirdChoiceSlotDetails['capacity_display']],
            ['label' => 'Available Slots', 'value' => $thirdChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $thirdChoiceSlotDetails['cutoff_display']],
        ],
        'slot_status' => $thirdChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $thirdChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => $thirdChoiceSlotDetails['qualification_note'],
        'show_transfer' => $thirdChoiceSlotDetails['transfer_open'],
        'transfer_program_id' => $thirdChoiceSlotDetails['program_id'],
        'transfer_capacity' => $thirdChoiceSlotDetails['capacity_display'],
        'transfer_available' => $thirdChoiceSlotDetails['available_display'],
        'transfer_cutoff' => $thirdChoiceSlotDetails['cutoff_display'],
        'status_text' => '',
        'status_class' => '',
    ],
];

if ($studentRankLocked) {
    $programChoiceCards = [reset($programChoiceCards)];
    foreach ($programChoiceCards as $choiceIndex => &$choiceCard) {
        $choiceCard['show_transfer'] = false;
        if ($choiceIndex > 0) {
            $choiceCard['qualification_note'] = 'Transfer closed after rank lock.';
        }
    }
    unset($choiceCard);
}

$studentEmail = trim((string) ($student['active_email'] ?? ($_SESSION['email'] ?? 'No email on file')));
if ($studentEmail === '') {
    $studentEmail = 'No email on file';
}

$regionOptions = [];
$regionListSql = "SELECT regCode, regDesc FROM refregion ORDER BY regCode ASC";
$regionListResult = $conn->query($regionListSql);
if ($regionListResult) {
    while ($regionRow = $regionListResult->fetch_assoc()) {
        $regionOptions[] = [
            'code' => (int) ($regionRow['regCode'] ?? 0),
            'label' => trim((string) ($regionRow['regDesc'] ?? '')),
        ];
    }
    $regionListResult->free();
}

$profileRegionCode = (int) ($studentProfile['region_code'] ?? 0);
$profileProvinceCode = (int) ($studentProfile['province_code'] ?? 0);
$profileCitymunCode = (int) ($studentProfile['citymun_code'] ?? 0);
$profileBarangayCode = (int) ($studentProfile['barangay_code'] ?? 0);
$profileSecondaryRegionCode = (int) ($studentProfile['secondary_region_code'] ?? 0);
$profileSecondaryProvinceCode = (int) ($studentProfile['secondary_province_code'] ?? 0);
$profileSecondaryCitymunCode = (int) ($studentProfile['secondary_citymun_code'] ?? 0);
$profileSecondaryBarangayCode = (int) ($studentProfile['secondary_barangay_code'] ?? 0);
$profileParentGuardianRegionCode = (int) ($studentProfile['parent_guardian_region_code'] ?? 0);
$profileParentGuardianProvinceCode = (int) ($studentProfile['parent_guardian_province_code'] ?? 0);
$profileParentGuardianCitymunCode = (int) ($studentProfile['parent_guardian_citymun_code'] ?? 0);
$profileParentGuardianBarangayCode = (int) ($studentProfile['parent_guardian_barangay_code'] ?? 0);
$profileCompletionBadge = number_format((float) $studentProfileCompletionPercent, 0) . '% Complete';
$isProfileComplete = ((float) $studentProfileCompletionPercent >= 100.0);
$preRegistrationSubmitted = is_array($studentPreRegistration)
    && !empty($studentPreRegistration['preregistration_id'])
    && strtolower(trim((string) ($studentPreRegistration['status'] ?? 'submitted'))) === 'submitted';
$preRegistrationSubmittedAtDisplay = '';
if ($preRegistrationSubmitted) {
    $submittedAt = (string) ($studentPreRegistration['submitted_at'] ?? '');
    $submittedAtTimestamp = ($submittedAt !== '') ? strtotime($submittedAt) : false;
    $preRegistrationSubmittedAtDisplay = ($submittedAtTimestamp !== false)
        ? date('F j, Y g:i A', $submittedAtTimestamp)
        : $submittedAt;
}

$canUpdateProfile = ($studentRankLocked && !$preRegistrationSubmitted && !$isAdminStudentPreview);
$canSubmitPreRegistration = ($studentRankLocked && $isProfileComplete && !$preRegistrationSubmitted && !$isAdminStudentPreview);
$preRegistrationButtonLabel = $preRegistrationSubmitted
    ? 'Pre-Registration Submitted'
    : ($canSubmitPreRegistration ? 'Review Agreement & Submit' : 'Submit Pre-Registration');
$preRegistrationButtonClass = $preRegistrationSubmitted ? 'btn-success' : ($isAdminStudentPreview ? 'btn-secondary' : 'btn-primary');
$preRegistrationButtonTitle = 'Pre-registration has already been submitted.';
if (!$preRegistrationSubmitted) {
    if ($isAdminStudentPreview) {
        $preRegistrationButtonLabel = 'Admin Preview Only';
        $preRegistrationButtonTitle = 'Administrator preview is read-only.';
    } elseif (!$studentRankLocked) {
        $preRegistrationButtonTitle = 'Available once your rank is locked.';
    } elseif (!$isProfileComplete) {
        $preRegistrationButtonTitle = 'Complete your profile to 100% first.';
    } else {
        $preRegistrationButtonTitle = 'Review the agreement and submit your locked program for pre-registration.';
    }
}
$updateProfileButtonTitle = $canUpdateProfile
    ? 'Update your profile details.'
    : ($isAdminStudentPreview
        ? 'Administrator preview is read-only.'
        : ($preRegistrationSubmitted
            ? 'Profile updates are locked after pre-registration submission.'
            : 'Profile updates open once your rank is locked.'));
$updateProfileButtonLabel = $isAdminStudentPreview
    ? 'View Profile Details'
    : ($canUpdateProfile
        ? ($isProfileComplete ? 'Review Profile Details' : 'Complete Profile Details')
        : ($preRegistrationSubmitted ? 'Profile Locked' : 'Update My Profile'));
$profileMenuSubtext = $isAdminStudentPreview
    ? 'Viewing this student as administrator'
    : ($studentRankLocked
        ? 'Complete your pre-registration details'
        : 'View your student details');
$studentPreviewAdministratorName = trim((string) ($adminStudentPreviewContext['fullname'] ?? 'Administrator'));
$studentReadOnlyButtonAttr = $isAdminStudentPreview ? 'disabled' : '';
$studentReadOnlyButtonTitle = $isAdminStudentPreview ? 'Administrator preview is read-only.' : '';
$lockedWelcomeNotice = ($studentRankLocked && !$preRegistrationSubmitted)
    ? 'Congratulations! You have secured a slot for your priority program. You can proceed now to pre-registration.'
    : '';
$lockedWelcomeNoticeSubtext = ($lockedWelcomeNotice !== '' && !$isProfileComplete)
    ? 'Complete your profile details to finish your submission.'
    : '';
$studentPageBodyClass = $isAdminStudentPreview ? 'student-admin-preview-active' : '';

$preRegistrationCardTitle = 'Pre-Registration';
$preRegistrationCardMessage = 'Pre-registration opens after your rank is locked.';
$preRegistrationStatusBadgeClass = 'bg-label-secondary';
$preRegistrationStatusBadgeText = 'Awaiting Lock';

if ($preRegistrationSubmitted) {
    $preRegistrationCardTitle = 'Pre-Registration Submitted';
    $preRegistrationCardMessage = $preRegistrationSubmittedAtDisplay !== ''
        ? ('Your pre-registration was recorded on ' . $preRegistrationSubmittedAtDisplay . '.')
        : 'Your pre-registration was recorded successfully.';
    $preRegistrationStatusBadgeClass = 'bg-label-success';
    $preRegistrationStatusBadgeText = 'Submitted';
} elseif ($studentRankLocked) {
    $preRegistrationCardTitle = 'Pre-Registration Ready';
    if ($isProfileComplete) {
        $preRegistrationCardMessage = $studentLockedRank > 0
            ? ('Your rank is locked at #' . number_format($studentLockedRank) . '. Review the agreement and submit your pre-registration.')
            : 'Your rank is locked. Review the agreement and submit your pre-registration.';
        $preRegistrationStatusBadgeClass = 'bg-label-success';
        $preRegistrationStatusBadgeText = 'Ready to Submit';
    } else {
        $preRegistrationCardMessage = $studentLockedRank > 0
            ? ('Your rank is locked at #' . number_format($studentLockedRank) . '. Complete your profile to 100% to continue.')
            : 'Your rank is locked. Complete your profile to 100% to continue.';
        $preRegistrationStatusBadgeClass = 'bg-label-warning';
        $preRegistrationStatusBadgeText = 'Profile Incomplete';
    }
} elseif ($hasScoredInterview) {
    $preRegistrationCardMessage = 'Your interview has been scored. Wait for your program rank to be locked before continuing.';
    $preRegistrationStatusBadgeClass = 'bg-label-info';
    $preRegistrationStatusBadgeText = 'Waiting for Rank Lock';
} else {
    $preRegistrationCardMessage = 'Pre-registration will open after your interview score and rank are finalized.';
    $preRegistrationStatusBadgeClass = 'bg-label-warning';
    $preRegistrationStatusBadgeText = 'Interview Pending';
}

if ($studentRankLocked && isset($programChoiceCards[0])) {
    $lockedRankDisplay = $studentLockedRank > 0
        ? number_format($studentLockedRank)
        : ((string) $firstChoiceRankValueDisplay !== '' ? (string) $firstChoiceRankValueDisplay : 'N/A');

    $programChoiceCards[0]['stat_label'] = 'Locked Rank';
    $programChoiceCards[0]['stat_value'] = $lockedRankDisplay;
    $programChoiceCards[0]['stat_primary_value'] = null;
    $programChoiceCards[0]['stat_secondary_value'] = null;
    $programChoiceCards[0]['meta_rows'] = [
        ['label' => 'Cutoff SAT', 'value' => $firstChoiceSlotDetails['cutoff_display']],
        ['label' => 'Profile', 'value' => $profileCompletionBadge],
    ];
}

$nationalitySuggestions = [
    'Philippines',
    'Afghanistan',
    'Albania',
    'Algeria',
    'Andorra',
    'Angola',
    'Antigua and Barbuda',
    'Argentina',
    'Armenia',
    'Australia',
    'Austria',
    'Azerbaijan',
    'Bahamas',
    'Bahrain',
    'Bangladesh',
    'Barbados',
    'Belarus',
    'Belgium',
    'Belize',
    'Benin',
    'Bhutan',
    'Bolivia',
    'Bosnia and Herzegovina',
    'Botswana',
    'Brazil',
    'Brunei',
    'Bulgaria',
    'Burkina Faso',
    'Burundi',
    'Cambodia',
    'Cameroon',
    'Canada',
    'Cabo Verde',
    'Central African Republic',
    'Chad',
    'Chile',
    'China',
    'Colombia',
    'Comoros',
    'Congo',
    'Costa Rica',
    'Cote d\'Ivoire',
    'Croatia',
    'Cuba',
    'Cyprus',
    'Czech Republic',
    'Denmark',
    'Djibouti',
    'Dominica',
    'Dominican Republic',
    'Ecuador',
    'Egypt',
    'El Salvador',
    'Equatorial Guinea',
    'Eritrea',
    'Estonia',
    'Eswatini',
    'Ethiopia',
    'Fiji',
    'Finland',
    'France',
    'Gabon',
    'Gambia',
    'Georgia',
    'Germany',
    'Ghana',
    'Greece',
    'Grenada',
    'Guatemala',
    'Guinea',
    'Guinea-Bissau',
    'Guyana',
    'Haiti',
    'Honduras',
    'Hong Kong',
    'Hungary',
    'Iceland',
    'India',
    'Indonesia',
    'Iran',
    'Iraq',
    'Ireland',
    'Israel',
    'Italy',
    'Jamaica',
    'Japan',
    'Jordan',
    'Kazakhstan',
    'Kenya',
    'Kiribati',
    'Kuwait',
    'Kyrgyzstan',
    'Laos',
    'Latvia',
    'Lebanon',
    'Lesotho',
    'Liberia',
    'Libya',
    'Liechtenstein',
    'Lithuania',
    'Luxembourg',
    'Macau',
    'Madagascar',
    'Malawi',
    'Malaysia',
    'Maldives',
    'Mali',
    'Malta',
    'Marshall Islands',
    'Mauritania',
    'Mauritius',
    'Mexico',
    'Micronesia',
    'Moldova',
    'Monaco',
    'Mongolia',
    'Montenegro',
    'Morocco',
    'Mozambique',
    'Myanmar',
    'Namibia',
    'Nauru',
    'Nepal',
    'Netherlands',
    'New Zealand',
    'Nicaragua',
    'Niger',
    'Nigeria',
    'North Korea',
    'North Macedonia',
    'Norway',
    'Oman',
    'Pakistan',
    'Palau',
    'Palestine',
    'Panama',
    'Papua New Guinea',
    'Paraguay',
    'Peru',
    'Poland',
    'Portugal',
    'Qatar',
    'Romania',
    'Russia',
    'Rwanda',
    'Saint Kitts and Nevis',
    'Saint Lucia',
    'Saint Vincent and the Grenadines',
    'Samoa',
    'San Marino',
    'Sao Tome and Principe',
    'Saudi Arabia',
    'Senegal',
    'Serbia',
    'Seychelles',
    'Sierra Leone',
    'Singapore',
    'Slovakia',
    'Slovenia',
    'Solomon Islands',
    'Somalia',
    'South Africa',
    'South Korea',
    'South Sudan',
    'Spain',
    'Sri Lanka',
    'Sudan',
    'Suriname',
    'Sweden',
    'Switzerland',
    'Syria',
    'Taiwan',
    'Tajikistan',
    'Tanzania',
    'Thailand',
    'Timor-Leste',
    'Togo',
    'Tonga',
    'Trinidad and Tobago',
    'Tunisia',
    'Turkey',
    'Turkmenistan',
    'Tuvalu',
    'Uganda',
    'Ukraine',
    'United Arab Emirates',
    'United Kingdom',
    'United States',
    'Uruguay',
    'Uzbekistan',
    'Vanuatu',
    'Vatican City',
    'Venezuela',
    'Vietnam',
    'Yemen',
    'Zambia',
    'Zimbabwe',
];

$religionSuggestions = [
    'Roman Catholic',
    'Islam',
    'Iglesia ni Cristo',
    'Philippine Independent Church (Aglipayan)',
    'Born Again Christian',
    'Christian',
    'Protestant',
    'Bible Baptist',
    'Baptist',
    'Evangelical Christian',
    'Pentecostal',
    'Seventh-day Adventist',
    'Jehovah\'s Witnesses',
    'United Church of Christ in the Philippines',
    'Methodist',
    'Presbyterian',
    'Church of Christ',
    'The Church of Jesus Christ of Latter-day Saints',
    'Members Church of God International',
    'Jesus Is Lord Church',
    'Buddhist',
    'Hindu',
    'Sikh',
    'Traditional/Indigenous Beliefs',
    'None',
    'Other',
];

ob_start();
?>
              <div class="student-profile-panel">
                <div class="student-profile-section-title">Basic Information</div>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label class="form-label">Full Name</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($studentName); ?>" readonly />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Examinee Number</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars((string) ($student['examinee_number'] ?? '')); ?>" readonly />
                  </div>
                  <div class="col-md-4">
                    <label class="form-label">Current Mobile Number</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars((string) ($student['mobile_number'] ?? '')); ?>" readonly />
                  </div>

                  <div class="col-md-4">
                    <label for="profileBirthDate" class="form-label">Birth Date</label>
                    <input type="date" class="form-control" id="profileBirthDate" name="birth_date" value="<?= htmlspecialchars((string) ($studentProfile['birth_date'] ?? '')); ?>" required />
                  </div>
                  <div class="col-md-4">
                    <label for="profileSex" class="form-label">Sex</label>
                    <select class="form-select" id="profileSex" name="sex" required>
                      <option value="">Select Sex</option>
                      <option value="Male" <?= ((string) ($studentProfile['sex'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                      <option value="Female" <?= ((string) ($studentProfile['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                      <option value="Other" <?= ((string) ($studentProfile['sex'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="profileCivilStatus" class="form-label">Civil Status</label>
                    <select class="form-select" id="profileCivilStatus" name="civil_status" required>
                      <option value="">Select Civil Status</option>
                      <option value="Single" <?= ((string) ($studentProfile['civil_status'] ?? '') === 'Single') ? 'selected' : ''; ?>>Single</option>
                      <option value="Married" <?= ((string) ($studentProfile['civil_status'] ?? '') === 'Married') ? 'selected' : ''; ?>>Married</option>
                      <option value="Separated" <?= ((string) ($studentProfile['civil_status'] ?? '') === 'Separated') ? 'selected' : ''; ?>>Separated</option>
                      <option value="Widowed" <?= ((string) ($studentProfile['civil_status'] ?? '') === 'Widowed') ? 'selected' : ''; ?>>Widowed</option>
                      <option value="Other" <?= ((string) ($studentProfile['civil_status'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                    </select>
                  </div>
                  <div class="col-md-6">
                    <label for="profileNationality" class="form-label">Nationality</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileNationality"
                      name="nationality"
                      maxlength="100"
                      list="profileNationalityOptions"
                      placeholder="Select or type nationality"
                      value="<?= htmlspecialchars((string) ($studentProfile['nationality'] ?? '')); ?>"
                      required
                    />
                    <datalist id="profileNationalityOptions">
                      <?php foreach ($nationalitySuggestions as $nationalityOption): ?>
                        <option value="<?= htmlspecialchars($nationalityOption); ?>"></option>
                      <?php endforeach; ?>
                    </datalist>
                    <small class="text-muted">Common country options are provided. You can still type a custom value.</small>
                  </div>
                  <div class="col-md-6">
                    <label for="profileReligion" class="form-label">Religion</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileReligion"
                      name="religion"
                      maxlength="120"
                      list="profileReligionOptions"
                      placeholder="Select or type religion"
                      value="<?= htmlspecialchars((string) ($studentProfile['religion'] ?? '')); ?>"
                      required
                    />
                    <datalist id="profileReligionOptions">
                      <?php foreach ($religionSuggestions as $religionOption): ?>
                        <option value="<?= htmlspecialchars($religionOption); ?>"></option>
                      <?php endforeach; ?>
                    </datalist>
                    <small class="text-muted">Includes common religions in the Philippines. You can still type a custom value.</small>
                  </div>
                </div>
              </div>

              <div class="student-profile-panel mt-4">
                <div class="student-profile-section-title">Secondary School Information</div>
                <div class="row g-3 mb-3">
                  <div class="col-md-8">
                    <label for="profileSecondarySchool" class="form-label">Secondary School</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileSecondarySchool"
                      name="secondary_school_name"
                      maxlength="190"
                      value="<?= htmlspecialchars((string) ($studentProfile['secondary_school_name'] ?? '')); ?>"
                      required
                    />
                  </div>
                  <div class="col-md-4">
                    <label for="profileSchoolType" class="form-label">School Type</label>
                    <select class="form-select" id="profileSchoolType" name="secondary_school_type" required>
                      <option value="">Select School Type</option>
                      <option value="Private" <?= ((string) ($studentProfile['secondary_school_type'] ?? '') === 'Private') ? 'selected' : ''; ?>>Private</option>
                      <option value="Public" <?= ((string) ($studentProfile['secondary_school_type'] ?? '') === 'Public') ? 'selected' : ''; ?>>Public</option>
                    </select>
                  </div>
                </div>
                <div class="student-profile-section-title">Secondary School Address</div>
                <input
                  type="hidden"
                  id="profileSecondaryAddressLine1"
                  name="secondary_address_line1"
                  value="<?= htmlspecialchars((string) ($studentProfile['secondary_address_line1'] ?? '')); ?>"
                />
                <select class="d-none" id="profileSecondaryRegion" name="secondary_region_code">
                  <option value="">Select Region</option>
                  <?php foreach ($regionOptions as $regionOption): ?>
                    <option value="<?= htmlspecialchars((string) ($regionOption['code'] ?? 0)); ?>" <?= ((int) ($regionOption['code'] ?? 0) === $profileSecondaryRegionCode) ? 'selected' : ''; ?>>
                      <?= htmlspecialchars((string) ($regionOption['label'] ?? '')); ?>
                    </option>
                  <?php endforeach; ?>
                </select>
                <select
                  class="d-none"
                  id="profileSecondaryBarangay"
                  name="secondary_barangay_code"
                  data-selected="<?= htmlspecialchars((string) $profileSecondaryBarangayCode); ?>"
                >
                  <option value="">Select Barangay</option>
                  <?php if ($profileSecondaryBarangayCode > 0 && trim((string) ($studentProfile['secondary_barangay_name'] ?? '')) !== ''): ?>
                    <option value="<?= htmlspecialchars((string) $profileSecondaryBarangayCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['secondary_barangay_name'] ?? '')); ?></option>
                  <?php endif; ?>
                </select>
                <div class="row g-3">
                  <div class="col-md-4">
                    <label for="profileSecondaryProvince" class="form-label">Province</label>
                    <select
                      class="form-select"
                      id="profileSecondaryProvince"
                      name="secondary_province_code"
                      data-selected="<?= htmlspecialchars((string) $profileSecondaryProvinceCode); ?>"
                      required
                    >
                      <option value="">Select Province</option>
                      <?php if ($profileSecondaryProvinceCode > 0 && trim((string) ($studentProfile['secondary_province_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileSecondaryProvinceCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['secondary_province_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="profileSecondaryCityMun" class="form-label">City / Municipality</label>
                    <select
                      class="form-select"
                      id="profileSecondaryCityMun"
                      name="secondary_citymun_code"
                      data-selected="<?= htmlspecialchars((string) $profileSecondaryCitymunCode); ?>"
                      required
                    >
                      <option value="">Select City / Municipality</option>
                      <?php if ($profileSecondaryCitymunCode > 0 && trim((string) ($studentProfile['secondary_citymun_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileSecondaryCitymunCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['secondary_citymun_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-4">
                    <label for="profileSecondaryPostalCode" class="form-label">Postal Code</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileSecondaryPostalCode"
                      name="secondary_postal_code"
                      maxlength="20"
                      value="<?= htmlspecialchars((string) ($studentProfile['secondary_postal_code'] ?? '')); ?>"
                      required
                    />
                  </div>
                </div>
              </div>

              <div class="student-profile-panel mt-4">
                <div class="student-profile-section-title">Address Information</div>
                <div class="row g-3">
                  <div class="col-md-12">
                    <label for="profileAddressLine1" class="form-label">House No. / Street / Purok</label>
                    <input type="text" class="form-control" id="profileAddressLine1" name="address_line1" maxlength="255" value="<?= htmlspecialchars((string) ($studentProfile['address_line1'] ?? '')); ?>" required />
                  </div>
                  <div class="col-md-3">
                    <label for="profileRegion" class="form-label">Region</label>
                    <select class="form-select" id="profileRegion" name="region_code" required>
                      <option value="">Select Region</option>
                      <?php foreach ($regionOptions as $regionOption): ?>
                        <option value="<?= htmlspecialchars((string) ($regionOption['code'] ?? 0)); ?>" <?= ((int) ($regionOption['code'] ?? 0) === $profileRegionCode) ? 'selected' : ''; ?>>
                          <?= htmlspecialchars((string) ($regionOption['label'] ?? '')); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileProvince" class="form-label">Province</label>
                    <select
                      class="form-select"
                      id="profileProvince"
                      name="province_code"
                      data-selected="<?= htmlspecialchars((string) $profileProvinceCode); ?>"
                      required
                    >
                      <option value="">Select Province</option>
                      <?php if ($profileProvinceCode > 0 && trim((string) ($studentProfile['province_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileProvinceCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['province_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileCityMun" class="form-label">City / Municipality</label>
                    <select
                      class="form-select"
                      id="profileCityMun"
                      name="citymun_code"
                      data-selected="<?= htmlspecialchars((string) $profileCitymunCode); ?>"
                      required
                    >
                      <option value="">Select City / Municipality</option>
                      <?php if ($profileCitymunCode > 0 && trim((string) ($studentProfile['citymun_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileCitymunCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['citymun_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileBarangay" class="form-label">Barangay</label>
                    <select
                      class="form-select"
                      id="profileBarangay"
                      name="barangay_code"
                      data-selected="<?= htmlspecialchars((string) $profileBarangayCode); ?>"
                      required
                    >
                      <option value="">Select Barangay</option>
                      <?php if ($profileBarangayCode > 0 && trim((string) ($studentProfile['barangay_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileBarangayCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['barangay_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profilePostalCode" class="form-label">Postal Code</label>
                    <input type="text" class="form-control" id="profilePostalCode" name="postal_code" maxlength="20" value="<?= htmlspecialchars((string) ($studentProfile['postal_code'] ?? '')); ?>" required />
                  </div>
                </div>
              </div>

              <div class="student-profile-panel mt-4">
                <div class="student-profile-section-title">Parents / Guardian Information</div>
                <div class="row g-3">
                  <div class="col-md-12">
                    <label for="profileParentGuardianName" class="form-label">Parent / Guardian Name</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileParentGuardianName"
                      name="guardian_name"
                      maxlength="190"
                      value="<?= htmlspecialchars((string) ($studentProfile['guardian_name'] ?? '')); ?>"
                      required
                    />
                  </div>
                  <div class="col-md-12">
                    <label for="profileParentGuardianAddressLine1" class="form-label">House No. / Street / Purok</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileParentGuardianAddressLine1"
                      name="parent_guardian_address_line1"
                      maxlength="255"
                      value="<?= htmlspecialchars((string) ($studentProfile['parent_guardian_address_line1'] ?? '')); ?>"
                      required
                    />
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianRegion" class="form-label">Region</label>
                    <select class="form-select" id="profileParentGuardianRegion" name="parent_guardian_region_code" required>
                      <option value="">Select Region</option>
                      <?php foreach ($regionOptions as $regionOption): ?>
                        <option value="<?= htmlspecialchars((string) ($regionOption['code'] ?? 0)); ?>" <?= ((int) ($regionOption['code'] ?? 0) === $profileParentGuardianRegionCode) ? 'selected' : ''; ?>>
                          <?= htmlspecialchars((string) ($regionOption['label'] ?? '')); ?>
                        </option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianProvince" class="form-label">Province</label>
                    <select
                      class="form-select"
                      id="profileParentGuardianProvince"
                      name="parent_guardian_province_code"
                      data-selected="<?= htmlspecialchars((string) $profileParentGuardianProvinceCode); ?>"
                      required
                    >
                      <option value="">Select Province</option>
                      <?php if ($profileParentGuardianProvinceCode > 0 && trim((string) ($studentProfile['parent_guardian_province_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileParentGuardianProvinceCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['parent_guardian_province_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianCityMun" class="form-label">City / Municipality</label>
                    <select
                      class="form-select"
                      id="profileParentGuardianCityMun"
                      name="parent_guardian_citymun_code"
                      data-selected="<?= htmlspecialchars((string) $profileParentGuardianCitymunCode); ?>"
                      required
                    >
                      <option value="">Select City / Municipality</option>
                      <?php if ($profileParentGuardianCitymunCode > 0 && trim((string) ($studentProfile['parent_guardian_citymun_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileParentGuardianCitymunCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['parent_guardian_citymun_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianBarangay" class="form-label">Barangay</label>
                    <select
                      class="form-select"
                      id="profileParentGuardianBarangay"
                      name="parent_guardian_barangay_code"
                      data-selected="<?= htmlspecialchars((string) $profileParentGuardianBarangayCode); ?>"
                      required
                    >
                      <option value="">Select Barangay</option>
                      <?php if ($profileParentGuardianBarangayCode > 0 && trim((string) ($studentProfile['parent_guardian_barangay_name'] ?? '')) !== ''): ?>
                        <option value="<?= htmlspecialchars((string) $profileParentGuardianBarangayCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['parent_guardian_barangay_name'] ?? '')); ?></option>
                      <?php endif; ?>
                    </select>
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianPostalCode" class="form-label">Postal Code</label>
                    <input
                      type="text"
                      class="form-control"
                      id="profileParentGuardianPostalCode"
                      name="parent_guardian_postal_code"
                      maxlength="20"
                      value="<?= htmlspecialchars((string) ($studentProfile['parent_guardian_postal_code'] ?? '')); ?>"
                      required
                    />
                  </div>
                </div>
              </div>

              <input type="hidden" name="father_name" value="<?= htmlspecialchars((string) ($studentProfile['father_name'] ?? '')); ?>" />
              <input type="hidden" name="father_contact_number" value="<?= htmlspecialchars((string) ($studentProfile['father_contact_number'] ?? '')); ?>" />
              <input type="hidden" name="father_occupation" value="<?= htmlspecialchars((string) ($studentProfile['father_occupation'] ?? '')); ?>" />
              <input type="hidden" name="mother_name" value="<?= htmlspecialchars((string) ($studentProfile['mother_name'] ?? '')); ?>" />
              <input type="hidden" name="mother_contact_number" value="<?= htmlspecialchars((string) ($studentProfile['mother_contact_number'] ?? '')); ?>" />
              <input type="hidden" name="mother_occupation" value="<?= htmlspecialchars((string) ($studentProfile['mother_occupation'] ?? '')); ?>" />
              <input type="hidden" name="guardian_relationship" value="<?= htmlspecialchars((string) ($studentProfile['guardian_relationship'] ?? '')); ?>" />
              <input type="hidden" name="guardian_contact_number" value="<?= htmlspecialchars((string) ($studentProfile['guardian_contact_number'] ?? '')); ?>" />
              <input type="hidden" name="guardian_occupation" value="<?= htmlspecialchars((string) ($studentProfile['guardian_occupation'] ?? '')); ?>" />
<?php
$studentProfileFormFieldsHtml = ob_get_clean();
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../assets/"
  data-template="vertical-menu-template-free"
>
  <head>
    <meta charset="utf-8" />
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Student Dashboard - Interview</title>

    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap"
      rel="stylesheet"
    />

    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>

    <style>
      #layout-menu .sidebar-action-card {
        display: flex;
        align-items: center;
        gap: 0.65rem;
        padding: 0.72rem 0.78rem;
        margin: 0.42rem 0.2rem;
        border: 1px solid #e4e8f0;
        border-radius: 0.75rem;
        background: #ffffff;
        transition: all 0.2s ease;
      }

      #layout-menu .sidebar-action-card:hover {
        border-color: #c8d0e0;
        box-shadow: 0 6px 14px rgba(51, 71, 103, 0.09);
        transform: translateY(-1px);
      }

      #layout-menu .sidebar-action-card.active {
        border-color: #696cff;
        background: #f6f7ff;
      }

      #layout-menu .sidebar-action-card.is-disabled {
        opacity: 0.66;
        cursor: default;
        pointer-events: none;
      }

      #layout-menu .sidebar-action-card.is-disabled:hover {
        border-color: #e4e8f0;
        box-shadow: none;
        transform: none;
      }

      #layout-menu .sidebar-action-icon {
        width: 32px;
        height: 32px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex: 0 0 32px;
        font-size: 1rem;
      }

      #layout-menu .sidebar-action-title {
        font-size: 0.86rem;
        font-weight: 600;
        line-height: 1.05rem;
        color: #364152;
      }

      #layout-menu .sidebar-action-sub {
        display: block;
        margin-top: 0.14rem;
        font-size: 0.72rem;
        color: #8391a7;
        line-height: 0.92rem;
      }

      .student-important-notice {
        padding: 0.9rem 1rem 0.95rem;
        border: 1px solid #f1d38a;
        border-left: 4px solid #d9a441;
        border-radius: 0.95rem;
        background: linear-gradient(180deg, #fff7e5 0%, #fffdf8 100%);
        box-shadow: 0 10px 20px rgba(169, 123, 24, 0.08);
      }

      .student-important-notice-title {
        display: flex;
        align-items: center;
        gap: 0.45rem;
        color: #9a6700;
        font-size: 0.78rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .student-important-notice-copy {
        margin: 0.55rem 0 0;
        color: #6f5a2a;
        font-size: 0.78rem;
        line-height: 1.45;
      }

      #studentProgramSearchForm .form-control:focus {
        border: 1px solid #d9a441;
        box-shadow: 0 0 0 0.2rem rgba(217, 164, 65, 0.18);
      }

      .student-program-card {
        border: 1px solid #e4e8f0;
        border-radius: 0.8rem;
        padding: 0.82rem 0.86rem;
        background: #fff;
      }

      .student-program-card.is-first-choice {
        border-left: 4px solid #696cff;
        background: #f8f9ff;
      }

      .student-program-card.is-first-choice.is-qualified {
        border-left-color: #34a853;
        background: #edf7ef;
      }

      .student-program-card.is-first-choice.is-outside {
        border-left-color: #ea4335;
        background: #fdecea;
      }

      .student-program-card.is-locked-view {
        border-left-width: 0;
        border: 1px solid #d7e5d3;
        background: linear-gradient(180deg, #f7fbf5 0%, #eff7ec 100%);
        box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.72);
      }

      .student-program-card-title {
        font-size: 0.72rem;
        color: #6c7d93;
        text-transform: uppercase;
        letter-spacing: 0.04em;
      }

      .student-program-card-name {
        color: #344054;
        font-weight: 700;
        line-height: 1.3rem;
        margin-top: 0.22rem;
      }

      .student-program-code {
        margin-top: 0.35rem;
        display: inline-flex;
        align-items: center;
        padding: 0.16rem 0.44rem;
        border-radius: 999px;
        border: 1px solid #d6dce8;
        background: #f8f9fc;
        color: #5f6f86;
        font-size: 0.69rem;
        font-weight: 700;
        letter-spacing: 0.03em;
      }

      .student-program-campus {
        margin-top: 0.28rem;
        font-size: 0.74rem;
        color: #6a7b92;
      }

      .student-program-card-value {
        color: #696cff;
        font-weight: 700;
        font-size: 1.24rem;
        line-height: 1.15;
        margin-top: 0.22rem;
      }

      .student-rank-number-outside {
        color: #d93025;
      }

      .student-rank-lock-pill {
        display: inline-flex;
        align-items: center;
        gap: 0.28rem;
        margin-left: 0.45rem;
        padding: 0.16rem 0.44rem;
        border-radius: 999px;
        background: #fff4d6;
        border: 1px solid #f0cf7b;
        color: #9a6700;
        font-size: 0.72rem;
        font-weight: 700;
        vertical-align: middle;
      }

      .student-program-status {
        margin-top: 0.55rem;
        font-size: 0.76rem;
        font-weight: 700;
      }

      .student-program-status.status-ok {
        color: #1e8e3e;
      }

      .student-program-status.status-out {
        color: #d93025;
      }

      .student-program-status.status-pending {
        color: #b26a00;
      }

      .student-program-details {
        margin-top: 0.55rem;
        padding-top: 0.48rem;
        border-top: 1px dashed #d9deea;
      }

      .student-program-detail-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.7rem;
        margin-top: 0.26rem;
        font-size: 0.74rem;
        color: #6a7b92;
      }

      .student-program-detail-line strong {
        color: #314155;
      }

      .student-transfer-btn {
        margin-top: 0.7rem;
      }

      .student-locked-pane {
        padding: 0.35rem 0 0.9rem;
      }

      .student-locked-pane-kicker {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #7a8b74;
        font-weight: 700;
      }

      .student-locked-pane-title {
        margin: 0.3rem 0 0.18rem;
        color: #314155;
        font-weight: 700;
      }

      .student-locked-pane-text {
        margin: 0;
        color: #6a7b92;
        font-size: 0.82rem;
      }

      .student-locked-program-head {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 0.85rem;
      }

      .student-locked-program-head .student-rank-lock-pill {
        margin-left: 0;
      }

      .student-locked-metric-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 0.7rem;
        margin-top: 0.95rem;
      }

      .student-locked-metric {
        padding: 0.72rem 0.76rem;
        border-radius: 0.8rem;
        border: 1px solid #dce7d8;
        background: rgba(255, 255, 255, 0.78);
      }

      .student-locked-metric-label {
        display: block;
        font-size: 0.7rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        color: #75886f;
      }

      .student-locked-metric-value {
        display: block;
        margin-top: 0.25rem;
        color: #1f5130;
        font-size: 1.16rem;
        font-weight: 700;
        line-height: 1.1;
      }

      .student-agreement-summary {
        border: 1px solid #e4e8f0;
        border-radius: 1rem;
        padding: 0.9rem 1rem;
        background: linear-gradient(180deg, #f7f9ff 0%, #f1f6ff 100%);
      }

      .student-agreement-summary-title {
        margin: 0 0 0.25rem;
        color: #314155;
        font-size: 0.95rem;
        font-weight: 700;
      }

      .student-agreement-summary-copy {
        margin: 0;
        color: #62748a;
        font-size: 0.84rem;
      }

      .student-agreement-doc {
        max-height: 22rem;
        overflow: auto;
        border: 1px solid #e4e8f0;
        border-radius: 1rem;
        padding: 1rem 1.05rem;
        background: #fff;
      }

      .student-agreement-doc-title {
        margin: 0;
        color: #314155;
        font-size: 1rem;
        font-weight: 700;
      }

      .student-agreement-doc-subtitle {
        margin: 0.12rem 0 0;
        color: #6b7a90;
        font-size: 0.82rem;
      }

      .student-agreement-doc-body {
        margin-top: 1rem;
        color: #445166;
        font-size: 0.9rem;
      }

      .student-agreement-doc-body ol {
        margin-bottom: 0;
        padding-left: 1.2rem;
      }

      .student-agreement-doc-body li + li {
        margin-top: 0.9rem;
      }

      .student-agreement-doc-body h6 {
        margin: 0 0 0.3rem;
        color: #314155;
        font-size: 0.92rem;
        font-weight: 700;
      }

      .student-agreement-doc-body p {
        margin-bottom: 0.45rem;
      }

      .student-agreement-doc-body ul {
        margin: 0;
        padding-left: 1.1rem;
      }

      .student-agreement-check {
        margin-top: 0.9rem;
        padding-top: 0.95rem;
        border-top: 1px solid #eef2f7;
      }

      .student-program-list {
        display: flex;
        flex-direction: column;
        gap: 0.72rem;
        max-height: 560px;
        overflow: auto;
        padding-right: 0.2rem;
      }

      .student-program-item {
        border: 1px solid #e4e8f0;
        border-radius: 0.78rem;
        background: #fff;
        padding: 0.74rem 0.8rem;
      }

      .student-program-item-name {
        font-size: 0.88rem;
        font-weight: 700;
        color: #354152;
        line-height: 1.15rem;
      }

      .student-program-item-meta {
        margin-top: 0.24rem;
        font-size: 0.74rem;
        color: #6f7f95;
      }

      .student-final-score-row {
        background: #e8f0fe;
      }

      .student-final-score-row th,
      .student-final-score-row td {
        color: #1a73e8;
        font-weight: 700;
      }

      #studentProgramSearchModal .modal-content {
        border: 2px solid #d9a441;
        border-radius: 0.9rem;
        box-shadow: 0 16px 36px rgba(43, 30, 8, 0.22);
      }

      #studentProgramSearchModal .modal-header {
        border-bottom: 1px solid #e5c88e;
        background: linear-gradient(120deg, #fff7e8 0%, #fff1d8 100%);
      }

      #studentProgramSearchModal .search-query-chip {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.2rem 0.6rem;
        border-radius: 999px;
        background: #fff4dd;
        color: #8a5a10;
        border: 1px solid #e6c487;
        font-size: 0.78rem;
        font-weight: 600;
      }

      .student-right-tabs .nav-link {
        border: 1px solid transparent;
        border-radius: 0.6rem;
        font-weight: 600;
        color: #607189;
      }

      .student-right-tabs .nav-link.active {
        border-color: #d9a441;
        color: #8a5a10;
        background: #fff7e8;
      }

      .student-profile-progress-chip {
        font-size: 0.72rem;
        letter-spacing: 0.01em;
        padding: 0.4rem 0.62rem;
      }

      @keyframes studentSoftBlink {
        0%, 100% {
          opacity: 1;
        }
        50% {
          opacity: 0.58;
        }
      }

      .student-locked-congrats {
        display: inline-flex;
        align-items: center;
        gap: 0.45rem;
        padding: 0.62rem 0.82rem;
        margin-bottom: 0.85rem;
        border: 1px solid #b7e3c0;
        border-radius: 0.88rem;
        background: linear-gradient(135deg, #edfdf1 0%, #f8fff8 100%);
        color: #166534;
        font-size: 0.86rem;
        font-weight: 700;
        line-height: 1.4;
        animation: studentSoftBlink 1.8s ease-in-out infinite;
      }

      .student-locked-congrats i {
        font-size: 1rem;
        color: #16a34a;
      }

      .student-locked-congrats-note {
        margin-bottom: 0.95rem;
        color: #5f6b7a;
        font-size: 0.82rem;
      }

      .student-profile-shell .student-profile-panel {
        border: 1px solid #e4e9f2;
        border-radius: 0.75rem;
        padding: 1rem;
        background: #fff;
      }

      .student-profile-shell .student-profile-section-title {
        margin-top: 0.3rem;
        margin-bottom: 0.8rem;
        padding-bottom: 0.38rem;
        border-bottom: 1px dashed #d7deea;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #5f6f86;
      }

      .student-inline-profile-card {
        border: 1px solid #dde4f0;
      }

      .student-inline-profile-header {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
      }

      .student-inline-profile-kicker {
        font-size: 0.72rem;
        text-transform: uppercase;
        letter-spacing: 0.08em;
        color: #6a7b92;
        font-weight: 700;
      }

      .student-inline-profile-copy {
        color: #6b7280;
        font-size: 0.9rem;
        margin-bottom: 0;
      }

      .student-profile-missing-note {
        margin-top: 0.45rem;
        color: #b45309;
        font-size: 0.82rem;
        font-weight: 600;
      }

      .student-inline-profile-actions {
        display: flex;
        align-items: center;
        gap: 0.6rem;
        flex-wrap: wrap;
      }

      .student-admin-preview-banner {
        border: 1px solid #f1c27d;
        border-left: 5px solid #d97706;
        border-radius: 1rem;
        background: linear-gradient(135deg, #fff7e6 0%, #fffdf8 100%);
        box-shadow: 0 14px 28px rgba(217, 119, 6, 0.08);
      }

      .student-admin-preview-banner .student-admin-preview-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.35rem;
        padding: 0.32rem 0.65rem;
        margin-bottom: 0.45rem;
        border-radius: 999px;
        background: #fff1d6;
        color: #a16207;
        font-size: 0.72rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
      }

      .student-admin-preview-active #program-slot-availability,
      .student-admin-preview-active .student-inline-profile-card {
        border: 2px solid #f3d19d;
        box-shadow: 0 10px 26px rgba(217, 119, 6, 0.08);
      }

      #studentProfileModal .modal-content {
        border-radius: 0.88rem;
      }

      #studentProfileModal .modal-body {
        max-height: 72vh;
        overflow-y: auto;
      }

      #studentProfileModal .student-profile-panel {
        border: 1px solid #e4e9f2;
        border-radius: 0.75rem;
        padding: 1rem;
        background: #fff;
      }

      #studentProfileModal .student-profile-section-title {
        margin-top: 0.3rem;
        margin-bottom: 0.8rem;
        padding-bottom: 0.38rem;
        border-bottom: 1px dashed #d7deea;
        font-size: 0.82rem;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #5f6f86;
      }

      @media (min-width: 992px) {
        .student-pinned-wrap {
          position: sticky;
          top: 5.2rem;
          z-index: 2;
        }
      }

      @media (max-width: 767.98px) {
        .student-inline-profile-header {
          flex-direction: column;
        }

        .student-locked-metric-grid {
          grid-template-columns: repeat(2, minmax(0, 1fr));
        }
      }

      @media (max-width: 479.98px) {
        .student-locked-metric-grid {
          grid-template-columns: 1fr;
        }
      }
    </style>
  </head>

  <body class="<?= htmlspecialchars($studentPageBodyClass); ?>">
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <aside id="layout-menu" class="layout-menu menu-vertical menu bg-menu-theme">
          <div class="app-brand demo">
            <a href="index.php" class="app-brand-link">
              <span class="app-brand-logo demo"><i class="bx bxs-graduation bx-sm text-primary"></i></span>
              <span class="app-brand-text demo menu-text fw-bolder ms-2">Interview</span>
            </a>
            <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto d-block d-xl-none">
              <i class="bx bx-chevron-left bx-sm align-middle"></i>
            </a>
          </div>

          <div class="menu-inner-shadow"></div>
          <ul class="menu-inner py-1">
            <li class="menu-header small text-uppercase mt-1">
              <span class="menu-header-text">Student Menu</span>
            </li>
            <li class="menu-item px-2">
              <a href="#student-profile" class="menu-link sidebar-action-card active">
                <span class="sidebar-action-icon bg-label-primary"><i class="bx bx-user-circle"></i></span>
                <div>
                  <div class="sidebar-action-title">Profile</div>
                  <small class="sidebar-action-sub"><?= htmlspecialchars($profileMenuSubtext); ?></small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a
                href="<?= htmlspecialchars($transferActionHref); ?>"
                class="menu-link sidebar-action-card<?= $transferActionClass; ?>"
                <?= $transferActionEnabled ? '' : 'tabindex="-1" aria-disabled="true"'; ?>
              >
                <span class="sidebar-action-icon bg-label-warning"><i class="bx bx-transfer-alt"></i></span>
                <div>
                  <div class="sidebar-action-title"><?= htmlspecialchars($transferActionTitle); ?></div>
                  <small class="sidebar-action-sub"><?= htmlspecialchars($transferActionSub); ?></small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a
                href="<?= htmlspecialchars($changePasswordActionHref); ?>"
                class="menu-link sidebar-action-card<?= $changePasswordActionClass; ?>"
                <?= ($studentRankLocked || $isAdminStudentPreview) ? 'tabindex="-1" aria-disabled="true"' : ''; ?>
              >
                <span class="sidebar-action-icon bg-label-info"><i class="bx bx-lock-open-alt"></i></span>
                <div>
                  <div class="sidebar-action-title">Change Password</div>
                  <small class="sidebar-action-sub"><?= htmlspecialchars($changePasswordActionSub); ?></small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a href="javascript:void(0);" class="menu-link sidebar-action-card is-disabled" tabindex="-1" aria-disabled="true">
                <span class="sidebar-action-icon bg-label-secondary"><i class="bx bx-time-five"></i></span>
                <div>
                  <div class="sidebar-action-title">Logs</div>
                  <small class="sidebar-action-sub"><?= htmlspecialchars($logsActionSub); ?></small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a href="../logout.php" class="menu-link sidebar-action-card">
                <span class="sidebar-action-icon bg-label-danger"><i class="bx bx-log-out"></i></span>
                <div>
                  <div class="sidebar-action-title">Logout</div>
                  <small class="sidebar-action-sub">Sign out your account</small>
                </div>
              </a>
            </li>
          </ul>
        </aside>

        <div class="layout-page">
          <nav class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme" id="layout-navbar">
            <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
              <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)"><i class="bx bx-menu bx-sm"></i></a>
            </div>

            <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
              <div class="navbar-nav align-items-center flex-grow-1 me-3">
                <?php if (!$studentRankLocked && !$isAdminStudentPreview): ?>
                  <form id="studentProgramSearchForm" class="nav-item d-flex align-items-center w-100" autocomplete="off">
                    <i class="bx bx-search fs-4 lh-0"></i>
                    <input
                      type="search"
                      id="studentProgramSearchInput"
                      class="form-control border-0 shadow-none w-100"
                      style="max-width: 42rem;"
                      placeholder="Search program name, code, or campus and press Enter to view available slots"
                      aria-label="Search programs by name, code, or campus"
                    />
                  </form>
                <?php elseif ($isAdminStudentPreview): ?>
                  <div class="small text-muted">Administrator preview mode is read-only. Program transfer actions are disabled.</div>
                <?php else: ?>
                  <div class="small text-muted">Program transfers are closed because your rank is already locked.</div>
                <?php endif; ?>
              </div>

              <ul class="navbar-nav flex-row align-items-center ms-auto">
                <li class="nav-item lh-1 me-3 d-none d-lg-flex flex-column align-items-end">
                  <span class="fw-semibold small"><?= htmlspecialchars($studentName); ?></span>
                  <small class="text-muted">Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? 'N/A')); ?></small>
                </li>
                <li class="nav-item navbar-dropdown dropdown-user dropdown">
                  <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
                    <div class="avatar avatar-online">
                      <span class="w-px-40 h-px-40 rounded-circle d-inline-flex align-items-center justify-content-center bg-label-primary fw-bold">
                        <?= htmlspecialchars($studentInitials); ?>
                      </span>
                    </div>
                  </a>
                  <ul class="dropdown-menu dropdown-menu-end">
                    <li><a class="dropdown-item" href="#student-profile"><i class="bx bx-user me-2"></i>My Profile</a></li>
                    <?php if ($isAdminStudentPreview): ?>
                      <li><a class="dropdown-item disabled" href="javascript:void(0);" tabindex="-1" aria-disabled="true"><i class="bx bx-lock-open-alt me-2"></i>Change Password</a></li>
                    <?php elseif ($studentRankLocked): ?>
                      <li><a class="dropdown-item disabled" href="javascript:void(0);" tabindex="-1" aria-disabled="true"><i class="bx bx-lock-open-alt me-2"></i>Change Password</a></li>
                    <?php else: ?>
                      <li><a class="dropdown-item" href="change_password.php"><i class="bx bx-lock-open-alt me-2"></i>Change Password</a></li>
                    <?php endif; ?>
                    <?php if ($isAdminStudentPreview): ?>
                      <li>
                        <form method="post" action="stop_impersonation.php" class="m-0">
                          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminStudentPreviewCsrf, ENT_QUOTES); ?>" />
                          <button type="submit" class="dropdown-item"><i class="bx bx-revision me-2"></i>Return to Administrator</button>
                        </form>
                      </li>
                    <?php endif; ?>
                    <li><div class="dropdown-divider"></div></li>
                    <li><a class="dropdown-item" href="../logout.php"><i class="bx bx-power-off me-2"></i>Log Out</a></li>
                  </ul>
                </li>
              </ul>
            </div>
          </nav>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <?php if (is_array($transferFlash) && !empty($transferFlash['message'])): ?>
                <?php $transferAlertType = ((string) ($transferFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($transferAlertType); ?>"><?= htmlspecialchars((string) $transferFlash['message']); ?></div>
              <?php endif; ?>

              <?php if (is_array($profileFlash) && !empty($profileFlash['message'])): ?>
                <?php $profileAlertType = ((string) ($profileFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($profileAlertType); ?>"><?= htmlspecialchars((string) $profileFlash['message']); ?></div>
              <?php endif; ?>

              <?php if (is_array($preRegistrationFlash) && !empty($preRegistrationFlash['message'])): ?>
                <?php $preRegistrationAlertType = ((string) ($preRegistrationFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($preRegistrationAlertType); ?>"><?= htmlspecialchars((string) $preRegistrationFlash['message']); ?></div>
              <?php endif; ?>

              <?php if (is_array($studentAdminPreviewFlash) && !empty($studentAdminPreviewFlash['message'])): ?>
                <?php $studentAdminPreviewAlertType = ((string) ($studentAdminPreviewFlash['type'] ?? '') === 'success') ? 'success' : 'danger'; ?>
                <div class="alert alert-<?= htmlspecialchars($studentAdminPreviewAlertType); ?>"><?= htmlspecialchars((string) $studentAdminPreviewFlash['message']); ?></div>
              <?php endif; ?>

              <?php if (isset($_GET['password_changed']) && $_GET['password_changed'] === '1'): ?>
                <?php $emailNotice = (string) ($_GET['email_notice'] ?? ''); ?>
                <?php if ($emailNotice === 'sent'): ?>
                  <div class="alert alert-success">Password changed successfully. Your username and new password were sent to your active email.</div>
                <?php elseif ($emailNotice === 'failed'): ?>
                  <div class="alert alert-warning">Password changed successfully, but email delivery failed. Please verify mail server settings.</div>
                <?php else: ?>
                  <div class="alert alert-success">Password changed successfully.</div>
                <?php endif; ?>
              <?php endif; ?>

              <?php if ($isAdminStudentPreview): ?>
                <div class="alert student-admin-preview-banner d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3">
                  <div>
                    <div class="student-admin-preview-badge"><i class="bx bx-show-alt"></i>Administrator Mirror View</div>
                    <div class="fw-semibold mb-1">Administrator Preview Mode</div>
                    <div class="small">
                      You are viewing this student portal as <?= htmlspecialchars($studentPreviewAdministratorName !== '' ? $studentPreviewAdministratorName : 'Administrator'); ?>.
                      Ranking, profile, and locked-program details match the student view. Changes are disabled.
                    </div>
                  </div>
                  <form method="post" action="stop_impersonation.php" class="d-inline-flex">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($adminStudentPreviewCsrf, ENT_QUOTES); ?>" />
                    <button type="submit" class="btn btn-warning btn-sm">Return to Administrator</button>
                  </form>
                </div>
              <?php endif; ?>

              <div class="row">
                <div class="col-lg-8 col-12">
                  <div id="program-slot-availability" class="card mb-4">
                    <div class="d-flex align-items-end row">
                      <div class="col-sm-7">
                        <div class="card-body">
                          <?php if ($lockedWelcomeNotice !== ''): ?>
                            <div class="student-locked-congrats">
                              <i class="bx bxs-badge-check"></i>
                              <span><?= htmlspecialchars($lockedWelcomeNotice); ?></span>
                            </div>
                            <?php if ($lockedWelcomeNoticeSubtext !== ''): ?>
                              <div class="student-locked-congrats-note"><?= htmlspecialchars($lockedWelcomeNoticeSubtext); ?></div>
                            <?php endif; ?>
                          <?php endif; ?>
                          <h5 class="card-title text-primary"><?= htmlspecialchars($preRegistrationCardTitle); ?></h5>
                          <p class="mb-4"><?= htmlspecialchars($preRegistrationCardMessage); ?></p>
                          <?php if (!$isProfileComplete && $studentProfileMissingSummary !== ''): ?>
                            <div class="student-profile-missing-note mb-3"><?= htmlspecialchars($studentProfileMissingSummary); ?></div>
                          <?php endif; ?>
                          <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="badge <?= $hasScoredInterview ? 'bg-label-success' : 'bg-label-warning'; ?>">Interview: <?= $hasScoredInterview ? 'Scored' : 'Pending'; ?></span>
                            <span class="badge <?= htmlspecialchars($preRegistrationStatusBadgeClass); ?>"><?= htmlspecialchars($preRegistrationStatusBadgeText); ?></span>
                            <?php if ($studentRankLocked && $studentLockedRank > 0): ?>
                              <span class="badge bg-label-warning"><i class="bx bx-lock-alt me-1"></i>Locked Rank #<?= htmlspecialchars(number_format($studentLockedRank)); ?></span>
                            <?php endif; ?>
                          </div>
                          <div class="d-flex flex-wrap align-items-center gap-2">
                            <form method="post" class="d-inline" id="studentPreRegistrationForm" autocomplete="off">
                              <input type="hidden" name="action" value="student_preregistration_submit" />
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['student_prereg_csrf'] ?? ''), ENT_QUOTES); ?>" />
                              <button
                                type="button"
                                id="openPreRegistrationAgreementBtn"
                                class="btn btn-sm <?= htmlspecialchars($preRegistrationButtonClass); ?>"
                                <?= $canSubmitPreRegistration ? '' : 'disabled'; ?>
                                <?= $canSubmitPreRegistration ? 'data-bs-toggle="modal" data-bs-target="#studentPreRegistrationAgreementModal"' : ''; ?>
                                title="<?= htmlspecialchars($preRegistrationButtonTitle); ?>"
                              >
                                <?= htmlspecialchars($preRegistrationButtonLabel); ?>
                              </button>
                            </form>
                            <?php if ($studentRankLocked): ?>
                              <a
                                href="#student-profile"
                                class="btn btn-sm btn-outline-primary"
                                title="<?= htmlspecialchars($updateProfileButtonTitle); ?>"
                              >
                                <?= htmlspecialchars($updateProfileButtonLabel); ?>
                              </a>
                            <?php else: ?>
                              <button
                                type="button"
                                class="btn btn-sm btn-outline-primary"
                                <?= $canUpdateProfile ? '' : 'disabled'; ?>
                                title="<?= htmlspecialchars($updateProfileButtonTitle); ?>"
                              >
                                <?= htmlspecialchars($updateProfileButtonLabel); ?>
                              </button>
                            <?php endif; ?>
                            <span class="badge bg-label-info student-profile-progress-chip"><?= htmlspecialchars($profileCompletionBadge); ?></span>
                          </div>
                        </div>
                      </div>
                      <div class="col-sm-5 text-center text-sm-left">
                        <div class="card-body pb-0 px-0 px-md-4">
                          <img src="../assets/img/illustrations/man-with-laptop-light.png" height="140" alt="Student dashboard overview" />
                        </div>
                      </div>
                    </div>
                  </div>

                  <?php if (!$studentRankLocked): ?>
                    <div class="student-important-notice mb-4" role="note" aria-label="Important Notice">
                      <div class="student-important-notice-title">
                        <i class="bx bx-error-circle"></i>
                        <span>Important Notice</span>
                      </div>
                      <p class="student-important-notice-copy">Your current rank is provisional and may move up or down depending on the final scores of all applicants. Please wait for the final result. Once your status shows "Congratulations", you may proceed with pre-registration.</p>
                    </div>
                  <?php endif; ?>

                  <?php if ($studentRankLocked): ?>
                    <div id="student-profile" class="card mb-4 student-inline-profile-card">
                      <form method="post" id="studentProfileForm" autocomplete="off">
                        <input type="hidden" name="action" value="student_profile_save" />
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['student_profile_csrf'] ?? ''), ENT_QUOTES); ?>" />
                        <div class="card-body student-profile-shell">
                          <div class="student-inline-profile-header">
                            <div>
                              <div class="student-inline-profile-kicker">Profile Completion</div>
                              <h5 class="card-title mb-1">Complete Your Profile Details</h5>
                              <p class="student-inline-profile-copy">Fill in the missing information here before final pre-registration submission.</p>
                              <?php if (!$isProfileComplete && $studentProfileMissingSummary !== ''): ?>
                                <div class="student-profile-missing-note"><?= htmlspecialchars($studentProfileMissingSummary); ?></div>
                              <?php endif; ?>
                            </div>
                            <div class="student-inline-profile-actions">
                              <?php if ($studentLockedRank > 0): ?>
                                <span class="badge bg-label-warning"><i class="bx bx-lock-alt me-1"></i>Locked Rank #<?= htmlspecialchars(number_format($studentLockedRank)); ?></span>
                              <?php endif; ?>
                              <span class="badge bg-label-info student-profile-progress-chip"><?= htmlspecialchars($profileCompletionBadge); ?></span>
                              <button type="submit" class="btn btn-primary btn-sm" <?= $studentReadOnlyButtonAttr; ?> title="<?= htmlspecialchars($studentReadOnlyButtonTitle); ?>">Save Profile</button>
                            </div>
                          </div>
                          <?php echo $studentProfileFormFieldsHtml; ?>
                          <div class="d-flex justify-content-end mt-4">
                            <button type="submit" class="btn btn-primary" <?= $studentReadOnlyButtonAttr; ?> title="<?= htmlspecialchars($studentReadOnlyButtonTitle); ?>">Save Profile</button>
                          </div>
                        </div>
                      </form>
                    </div>
                  <?php else: ?>
                    <div id="student-profile" class="card mb-4">
                      <div class="card-body">
                        <h5 class="card-title mb-3">Student Details</h5>
                        <div class="table-responsive">
                          <table class="table table-sm mb-0">
                            <tbody>
                              <tr><th class="w-50">Full Name</th><td><?= htmlspecialchars($studentName); ?></td></tr>
                              <tr><th>Examinee Number</th><td><?= htmlspecialchars((string) ($student['examinee_number'] ?? 'N/A')); ?></td></tr>
                              <tr><th>Campus</th><td><?= htmlspecialchars((string) ($student['campus_name'] ?? 'N/A')); ?></td></tr>
                              <tr><th>Classification</th><td><?= htmlspecialchars((string) ($student['classification'] ?? 'N/A')); ?></td></tr>
                              <tr><th>ETG Class</th><td><?= htmlspecialchars((string) ($student['etg_class_name'] ?? 'N/A')); ?></td></tr>
                              <tr><th>Mobile Number</th><td><?= htmlspecialchars((string) ($student['mobile_number'] ?? 'N/A')); ?></td></tr>
                              <tr><th>SHS Track</th><td><?= htmlspecialchars((string) ($student['shs_track_name'] ?? 'N/A')); ?></td></tr>
                              <tr><th>Interview Date/Time</th><td><?= htmlspecialchars((string) ($student['interview_datetime'] ?? 'N/A')); ?></td></tr>
                              <tr class="student-final-score-row"><th>Final Interview Score</th><td class="fw-semibold"><?= htmlspecialchars($finalScoreDisplay); ?></td></tr>
                              <tr><th>SAT Score</th><td><?= htmlspecialchars((string) ($student['sat_score'] ?? 'N/A')); ?></td></tr>
                              <tr><th>Placement Result</th><td><?= htmlspecialchars((string) ($student['qualitative_text'] ?? 'N/A')); ?></td></tr>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  <?php endif; ?>
                </div>

                <div class="col-lg-4 col-12">
                  <div class="student-pinned-wrap">
                    <div class="card mb-4">
                      <?php if ($studentRankLocked): ?>
                        <div class="card-body">
                          <div class="student-locked-pane">
                            <div class="student-locked-pane-kicker">Locked Program</div>
                            <h6 class="student-locked-pane-title">Pre-Registration Placement</h6>
                            <p class="student-locked-pane-text">Only your locked program is shown here while pre-registration is active.</p>
                          </div>
                          <div class="d-flex flex-column gap-3">
                            <?php foreach ($programChoiceCards as $index => $choiceCard): ?>
                              <?php
                                $choiceCardClass = 'is-first-choice is-locked-view';
                                if ($firstChoiceWithinCapacity) {
                                    $choiceCardClass .= ' is-qualified';
                                } elseif ($firstChoiceOutsideCapacity) {
                                    $choiceCardClass .= ' is-outside';
                                }
                              ?>
                              <div class="student-program-card <?= trim($choiceCardClass); ?>">
                                <div class="student-locked-program-head">
                                  <div>
                                    <div class="student-program-card-title"><?= htmlspecialchars($choiceCard['title']); ?></div>
                                    <div class="student-program-card-name"><?= htmlspecialchars($choiceCard['program']); ?></div>
                                    <?php if (!empty($choiceCard['program_code'])): ?>
                                      <div class="student-program-code"><?= htmlspecialchars((string) $choiceCard['program_code']); ?></div>
                                    <?php endif; ?>
                                    <?php if (!empty($choiceCard['campus_name'])): ?>
                                      <div class="student-program-campus">Campus: <?= htmlspecialchars((string) $choiceCard['campus_name']); ?></div>
                                    <?php endif; ?>
                                  </div>
                                  <span class="student-rank-lock-pill" title="Locked rank" aria-label="Locked rank">
                                    <i class="bx bx-lock-alt"></i>
                                    <span>Locked</span>
                                  </span>
                                </div>
                                <div class="student-locked-metric-grid">
                                  <div class="student-locked-metric">
                                    <span class="student-locked-metric-label">Rank</span>
                                    <?php $lockedRankMetricValue = (string) ($choiceCard['stat_value'] ?? 'N/A'); ?>
                                    <strong class="student-locked-metric-value <?= !empty($choiceCard['stat_primary_outside']) ? 'student-rank-number-outside' : ''; ?>"><?= htmlspecialchars($lockedRankMetricValue !== 'N/A' ? ('#' . $lockedRankMetricValue) : 'N/A'); ?></strong>
                                  </div>
                                  <?php foreach (($choiceCard['meta_rows'] ?? []) as $metaRow): ?>
                                    <div class="student-locked-metric">
                                      <span class="student-locked-metric-label"><?= htmlspecialchars((string) ($metaRow['label'] ?? '')); ?></span>
                                      <strong class="student-locked-metric-value"><?= htmlspecialchars((string) ($metaRow['value'] ?? 'N/A')); ?></strong>
                                    </div>
                                  <?php endforeach; ?>
                                </div>
                                <?php if (!empty($choiceCard['status_text'])): ?>
                                  <div class="student-program-status <?= htmlspecialchars((string) ($choiceCard['status_class'] ?? 'status-pending')); ?>">
                                    <?= htmlspecialchars((string) $choiceCard['status_text']); ?>
                                  </div>
                                <?php endif; ?>
                              </div>
                            <?php endforeach; ?>
                          </div>
                        </div>
                      <?php else: ?>
                      <div class="card-body pb-2">
                        <ul class="nav nav-tabs nav-fill student-right-tabs" id="studentRightTabs" role="tablist">
                          <li class="nav-item" role="presentation">
                            <button
                              class="nav-link active"
                              id="student-pinned-choices-tab"
                              data-bs-toggle="tab"
                              data-bs-target="#student-pinned-choices-pane"
                              type="button"
                              role="tab"
                              aria-controls="student-pinned-choices-pane"
                              aria-selected="true"
                            >
                              Pinned Choices
                            </button>
                          </li>
                          <li class="nav-item" role="presentation">
                            <button
                              class="nav-link"
                              id="program-slot-availability-tab"
                              data-bs-toggle="tab"
                              data-bs-target="#program-slot-availability-pane"
                              type="button"
                              role="tab"
                              aria-controls="program-slot-availability-pane"
                              aria-selected="false"
                            >
                              Program Slots
                            </button>
                          </li>
                        </ul>
                      </div>
                      <div class="tab-content pt-0">
                        <div
                          class="tab-pane fade show active"
                          id="student-pinned-choices-pane"
                          role="tabpanel"
                          aria-labelledby="student-pinned-choices-tab"
                        >
                          <div class="card-body pt-1">
                            <h6 class="mb-3 text-uppercase text-muted">Pinned Program Choices</h6>
                            <div class="d-flex flex-column gap-3">
                              <?php foreach ($programChoiceCards as $index => $choiceCard): ?>
                                <?php
                                  $choiceCardClass = '';
                                  if ($index === 0) {
                                      $choiceCardClass = 'is-first-choice';
                                      if ($firstChoiceWithinCapacity) {
                                          $choiceCardClass .= ' is-qualified';
                                      } elseif ($firstChoiceOutsideCapacity) {
                                          $choiceCardClass .= ' is-outside';
                                      }
                                  }
                                ?>
                                <div class="student-program-card <?= trim($choiceCardClass); ?>">
                                  <div class="student-program-card-title"><?= htmlspecialchars($choiceCard['title']); ?></div>
                                  <div class="student-program-card-name"><?= htmlspecialchars($choiceCard['program']); ?></div>
                                  <?php if (!empty($choiceCard['program_code'])): ?>
                                    <div class="student-program-code"><?= htmlspecialchars((string) $choiceCard['program_code']); ?></div>
                                  <?php endif; ?>
                                  <?php if (!empty($choiceCard['campus_name'])): ?>
                                    <div class="student-program-campus">Campus: <?= htmlspecialchars((string) $choiceCard['campus_name']); ?></div>
                                  <?php endif; ?>
                                  <div class="student-program-card-title mt-3"><?= htmlspecialchars($choiceCard['stat_label']); ?></div>
                                <?php if ($index === 0 && $choiceCard['stat_primary_value'] !== null && $choiceCard['stat_secondary_value'] !== null): ?>
                                    <div class="student-program-card-value">
                                      <span class="<?= !empty($choiceCard['stat_primary_outside']) ? 'student-rank-number-outside' : ''; ?>">
                                        <?= htmlspecialchars((string) $choiceCard['stat_primary_value']); ?>
                                      </span>
                                      <span>/<?= htmlspecialchars((string) $choiceCard['stat_secondary_value']); ?></span>
                                      <?php if ($index === 0 && $studentRankLocked): ?>
                                        <span class="student-rank-lock-pill" title="Locked rank" aria-label="Locked rank">
                                          <i class="bx bx-lock-alt"></i>
                                          <span>Locked</span>
                                        </span>
                                      <?php endif; ?>
                                    </div>
                                  <?php else: ?>
                                    <div class="student-program-card-value"><?= htmlspecialchars($choiceCard['stat_value']); ?></div>
                                  <?php endif; ?>
                                  <?php if (!empty($choiceCard['meta_rows']) && is_array($choiceCard['meta_rows'])): ?>
                                    <div class="student-program-details">
                                      <?php foreach ($choiceCard['meta_rows'] as $metaRow): ?>
                                        <div class="student-program-detail-line">
                                          <span><?= htmlspecialchars((string) ($metaRow['label'] ?? '')); ?></span>
                                          <strong><?= htmlspecialchars((string) ($metaRow['value'] ?? 'N/A')); ?></strong>
                                        </div>
                                      <?php endforeach; ?>
                                      <div class="student-program-detail-line">
                                        <span>Status</span>
                                        <span class="badge <?= htmlspecialchars((string) ($choiceCard['slot_badge_class'] ?? 'bg-label-secondary')); ?>"><?= htmlspecialchars((string) ($choiceCard['slot_status'] ?? 'N/A')); ?></span>
                                      </div>
                                    </div>
                                  <?php endif; ?>
                                  <?php if ($index === 0 && !empty($choiceCard['status_text'])): ?>
                                    <div class="student-program-status <?= htmlspecialchars((string) ($choiceCard['status_class'] ?? 'status-pending')); ?>">
                                      <?= htmlspecialchars((string) $choiceCard['status_text']); ?>
                                    </div>
                                  <?php endif; ?>
                                  <?php if (!empty($choiceCard['qualification_note'])): ?>
                                    <div class="small mt-2 text-muted"><?= htmlspecialchars((string) $choiceCard['qualification_note']); ?></div>
                                  <?php endif; ?>
                                  <?php if (!empty($choiceCard['show_transfer'])): ?>
                                    <button
                                      type="button"
                                      class="btn btn-sm btn-outline-warning student-transfer-btn js-open-transfer-modal"
                                      data-program-id="<?= htmlspecialchars((string) ($choiceCard['transfer_program_id'] ?? '')); ?>"
                                      data-program-code="<?= htmlspecialchars((string) ($choiceCard['program_code'] ?? '')); ?>"
                                      data-program-label="<?= htmlspecialchars((string) ($choiceCard['program'] ?? '')); ?>"
                                      data-program-campus="<?= htmlspecialchars((string) ($choiceCard['campus_name'] ?? '')); ?>"
                                      data-capacity="<?= htmlspecialchars((string) ($choiceCard['transfer_capacity'] ?? 'N/A')); ?>"
                                      data-available="<?= htmlspecialchars((string) ($choiceCard['transfer_available'] ?? 'N/A')); ?>"
                                      data-cutoff="<?= htmlspecialchars((string) ($choiceCard['transfer_cutoff'] ?? 'N/A')); ?>"
                                      data-status="<?= htmlspecialchars((string) ($choiceCard['slot_status'] ?? 'N/A')); ?>"
                                    >
                                      Transfer
                                    </button>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                        <div
                          class="tab-pane fade<?= $studentRankLocked ? ' d-none' : ''; ?>"
                          id="program-slot-availability-pane"
                          role="tabpanel"
                          aria-labelledby="program-slot-availability-tab"
                        >
                          <div class="card-body pt-1">
                            <h6 class="mb-3 text-uppercase text-muted">Program Slot Availability</h6>
                            <div class="student-program-list">
                              <?php foreach ($slotPrograms as $program): ?>
                                <?php
                                  $capacityDisplay = $program['absorptive_capacity'] !== null ? number_format((int) $program['absorptive_capacity']) : 'N/A';
                                  $availableDisplay = $program['available_slots'] !== null ? number_format((int) $program['available_slots']) : 'N/A';
                                  $cutoffDisplay = $program['cutoff_score'] !== null ? number_format((int) $program['cutoff_score']) : 'N/A';
                                  $statusBadgeClass = 'bg-label-secondary';
                                  if (in_array((string) ($program['slot_status'] ?? ''), ['Open', 'Qualified'], true)) {
                                      $statusBadgeClass = 'bg-label-success';
                                  } elseif (in_array((string) ($program['slot_status'] ?? ''), ['Full', 'Outside Ranked Pool', 'SAT Below Cutoff'], true)) {
                                      $statusBadgeClass = 'bg-label-danger';
                                  }
                                ?>
                                <div class="student-program-item">
                                  <div class="student-program-item-name"><?= htmlspecialchars((string) ($program['program_code'] ?? '')); ?><?= htmlspecialchars((string) ($program['program_code'] ?? '') !== '' ? ' - ' : ''); ?><?= htmlspecialchars((string) ($program['program_label'] ?? 'N/A')); ?></div>
                                  <div class="student-program-item-meta">Campus: <strong><?= htmlspecialchars((string) ($program['campus_name'] ?? 'No campus assigned')); ?></strong></div>
                                  <div class="student-program-item-meta">Capacity: <strong><?= htmlspecialchars($capacityDisplay); ?></strong></div>
                                  <div class="student-program-item-meta">Cutoff SAT: <strong><?= htmlspecialchars($cutoffDisplay); ?></strong></div>
                                  <div class="mt-2 d-flex align-items-center justify-content-between">
                                    <small class="fw-semibold">Available Slots: <?= htmlspecialchars($availableDisplay); ?></small>
                                    <span class="badge <?= $statusBadgeClass; ?>"><?= htmlspecialchars((string) ($program['slot_status'] ?? 'N/A')); ?></span>
                                  </div>
                                  <?php if (!empty($program['transfer_open']) && !$isAdminStudentPreview): ?>
                                    <button
                                      type="button"
                                      class="btn btn-sm btn-outline-warning student-transfer-btn js-open-transfer-modal"
                                      data-program-id="<?= htmlspecialchars((string) ($program['program_id'] ?? '')); ?>"
                                      data-program-code="<?= htmlspecialchars((string) ($program['program_code'] ?? '')); ?>"
                                      data-program-label="<?= htmlspecialchars((string) ($program['program_label'] ?? '')); ?>"
                                      data-program-campus="<?= htmlspecialchars((string) ($program['campus_name'] ?? '')); ?>"
                                      data-capacity="<?= htmlspecialchars($capacityDisplay); ?>"
                                      data-available="<?= htmlspecialchars($availableDisplay); ?>"
                                      data-cutoff="<?= htmlspecialchars($cutoffDisplay); ?>"
                                      data-status="<?= htmlspecialchars((string) ($program['slot_status'] ?? 'N/A')); ?>"
                                    >
                                      Transfer
                                    </button>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                              <?php if (empty($slotPrograms)): ?>
                                <div class="text-muted small">No programs currently available.</div>
                              <?php endif; ?>
                            </div>
                          </div>
                        </div>
                      </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>
      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <?php if (!$studentRankLocked): ?>
      <div class="modal fade" id="studentProfileModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content">
            <form method="post" id="studentProfileForm" autocomplete="off">
              <input type="hidden" name="action" value="student_profile_save" />
              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['student_profile_csrf'] ?? ''), ENT_QUOTES); ?>" />

              <div class="modal-header">
                <h5 class="modal-title">Update My Profile</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body student-profile-shell">
                <div class="small text-muted mb-3">Current completion: <strong><?= htmlspecialchars($profileCompletionBadge); ?></strong></div>
                <?php echo $studentProfileFormFieldsHtml; ?>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
                <button type="submit" class="btn btn-primary" <?= $studentReadOnlyButtonAttr; ?> title="<?= htmlspecialchars($studentReadOnlyButtonTitle); ?>">Save Profile</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <div class="modal fade" id="studentProgramSearchModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title mb-1">Program Slot Search Results</h5>
              <div><span id="studentSearchQueryChip" class="search-query-chip d-none"></span></div>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div id="studentSearchEmptyState" class="text-center text-muted py-4 d-none">No matching programs found.</div>
            <div id="studentSearchTableWrap" class="table-responsive d-none">
              <table class="table table-sm table-hover mb-0">
                <thead><tr><th>Program</th><th>Capacity</th><th>Available Slots</th><th>Status</th></tr></thead>
                <tbody id="studentProgramSearchResults"></tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>

    <div class="modal fade" id="studentTransferModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <form method="post" id="studentTransferForm" autocomplete="off">
            <input type="hidden" name="action" value="student_transfer_submit" />
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string) ($_SESSION['student_transfer_csrf'] ?? ''), ENT_QUOTES); ?>" />
            <input type="hidden" name="to_program_id" id="transferModalProgramId" value="" />

            <div class="modal-header">
              <h5 class="modal-title">Transfer Here</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="small text-muted mb-2">Selected Program</div>
              <div class="fw-semibold mb-2" id="transferModalProgramLabel">-</div>
              <div class="small mb-3">
                <span class="badge bg-label-primary" id="transferModalProgramCode">-</span>
                <span class="badge bg-label-success ms-1" id="transferModalStatus">-</span>
              </div>
              <div class="small text-muted mb-2">Program Details</div>
              <div class="small">
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Campus</span><strong id="transferModalCampus">-</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Capacity</span><strong id="transferModalCapacity">-</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Available Slots</span><strong id="transferModalAvailable">-</strong></div>
                <div class="d-flex justify-content-between py-1 border-bottom"><span>Cutoff SAT</span><strong id="transferModalCutoff">-</strong></div>
              </div>

              <div class="mt-3">
                <label for="transferReasonInput" class="form-label">Reason for Transfer</label>
                <textarea
                  class="form-control"
                  id="transferReasonInput"
                  name="transfer_reason"
                  rows="4"
                  placeholder="Provide your reason for transfer (minimum 50 words)."
                  required
                ></textarea>
                <div class="d-flex justify-content-between mt-1">
                  <small class="text-muted">Minimum 50 words required.</small>
                  <small id="transferReasonWordCount" class="text-muted">0 / 50 words</small>
                </div>
                <div id="transferReasonError" class="small text-danger mt-1 d-none">Please provide at least 50 words before submitting.</div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-warning" id="transferModalSubmitBtn" disabled>Transfer Here</button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <div class="modal fade" id="studentPreRegistrationAgreementModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <div class="modal-header">
            <div>
              <h5 class="modal-title">SKSU Pre-Registration End-User / Enrollee Agreement</h5>
              <small class="text-muted">Review and accept this agreement before final pre-registration submission.</small>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="student-agreement-summary">
              <p class="student-agreement-summary-title">Student Pre-Registration Agreement</p>
              <p class="student-agreement-summary-copy">This step confirms your intent to enroll, the accuracy of your information, and your consent to SKSU data handling for admission and enrollment processing.</p>
            </div>

            <div class="student-agreement-doc mt-3">
              <h6 class="student-agreement-doc-title">Sultan Kudarat State University</h6>
              <p class="student-agreement-doc-subtitle">Student Pre-Registration Agreement</p>

              <div class="student-agreement-doc-body">
                <p>By completing and submitting this online pre-registration form, I hereby acknowledge and agree to the following terms and conditions set by Sultan Kudarat State University (SKSU):</p>
                <ol>
                  <li>
                    <h6>Intent to Enroll</h6>
                    <p>I confirm that my submission of this pre-registration form signifies my genuine intention to enroll at Sultan Kudarat State University for the upcoming academic term, subject to the admission requirements, evaluation, and approval of the University.</p>
                  </li>
                  <li>
                    <h6>Accuracy of Information</h6>
                    <p>I declare that all information provided in this system, including my personal information, educational background, and contact details, are true, complete, and accurate to the best of my knowledge.</p>
                    <p>I understand that providing false, misleading, or incomplete information may result in the cancellation of my pre-registration or disqualification from admission.</p>
                  </li>
                  <li>
                    <h6>Compliance with University Policies</h6>
                    <p>I understand that my application and eventual enrollment shall be governed by the policies, rules, and regulations of Sultan Kudarat State University, including admission policies, academic regulations, and student conduct guidelines.</p>
                  </li>
                  <li>
                    <h6>Submission of Required Documents</h6>
                    <p>I agree to submit all required admission documents within the prescribed schedule of the University. Failure to comply with the document requirements may result in denial or cancellation of my enrollment.</p>
                  </li>
                  <li>
                    <h6>Data Privacy Consent</h6>
                    <p>In accordance with the Data Privacy Act of 2012 (RA 10173), I authorize Sultan Kudarat State University to collect, process, and store my personal data for the purposes of admission processing, enrollment management, academic records, and other legitimate academic and administrative functions of the University.</p>
                  </li>
                  <li>
                    <h6>System Usage</h6>
                    <p>I understand that this online system is intended solely for legitimate pre-registration and admission purposes. Any misuse of the system, including falsification of records or unauthorized access, may lead to administrative action and cancellation of my registration.</p>
                  </li>
                  <li>
                    <h6>Confirmation of Agreement</h6>
                    <p>By clicking "Save Profile" or "Submit Registration", I confirm that:</p>
                    <ul>
                      <li>I have read and understood the terms of this agreement.</li>
                      <li>I voluntarily agree to comply with the policies and procedures of Sultan Kudarat State University.</li>
                      <li>I confirm my intent to proceed with enrollment if accepted by the University.</li>
                    </ul>
                  </li>
                </ol>
              </div>
            </div>

            <div class="student-agreement-check">
              <div class="form-check mb-0">
                <input
                  class="form-check-input"
                  type="checkbox"
                  id="studentPreregAgreementCheckbox"
                  name="prereg_agreement_accept"
                  value="1"
                  form="studentPreRegistrationForm"
                  required
                />
                <label class="form-check-label" for="studentPreregAgreementCheckbox">
                  I have read and agree to the SKSU Pre-Registration End-User / Enrollee Agreement.
                </label>
              </div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
            <button type="submit" class="btn btn-primary" id="studentPreregAgreementSubmitBtn" form="studentPreRegistrationForm">Agree and Submit</button>
          </div>
        </div>
      </div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      (function () {
        const searchForm = document.getElementById('studentProgramSearchForm');
        if (!searchForm) return;

        const searchInput = document.getElementById('studentProgramSearchInput');
        const modalEl = document.getElementById('studentProgramSearchModal');
        const queryChipEl = document.getElementById('studentSearchQueryChip');
        const emptyEl = document.getElementById('studentSearchEmptyState');
        const tableWrapEl = document.getElementById('studentSearchTableWrap');
        const resultBodyEl = document.getElementById('studentProgramSearchResults');
        const programData = <?= json_encode($slotPrograms, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        function escapeHtml(value) {
          return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
        }

        function formatNumber(value) {
          if (value === null || value === undefined || value === '') {
            return 'N/A';
          }
          const parsed = Number(value);
          if (Number.isNaN(parsed)) {
            return 'N/A';
          }
          return parsed.toLocaleString();
        }

        function renderRows(rows) {
          if (!Array.isArray(rows) || rows.length === 0) {
            resultBodyEl.innerHTML = '';
            tableWrapEl.classList.add('d-none');
            emptyEl.classList.remove('d-none');
            return;
          }

          resultBodyEl.innerHTML = rows.map((row) => {
            let badgeClass = 'bg-label-secondary';
            const status = String(row.slot_status || 'Capacity not set');
            if (status === 'Open' || status === 'Qualified') {
              badgeClass = 'bg-label-success';
            } else if (status === 'Full' || status === 'Outside Ranked Pool' || status === 'SAT Below Cutoff') {
              badgeClass = 'bg-label-danger';
            }

            const programCode = row.program_code ? `${escapeHtml(row.program_code)} - ` : '';
            const campusName = escapeHtml(row.campus_name || 'No campus assigned');
            return `<tr><td><div class="fw-semibold">${programCode}${escapeHtml(row.program_label || '-')}</div><div class="small text-muted mt-1">Campus: ${campusName}</div></td><td>${formatNumber(row.absorptive_capacity)}</td><td>${formatNumber(row.available_slots)}</td><td><span class="badge ${badgeClass}">${escapeHtml(status)}</span></td></tr>`;
          }).join('');

          emptyEl.classList.add('d-none');
          tableWrapEl.classList.remove('d-none');
        }

        function openModal() {
          if (typeof bootstrap === 'undefined' || !modalEl) return;
          bootstrap.Modal.getOrCreateInstance(modalEl).show();
        }

        searchForm.addEventListener('submit', function (event) {
          event.preventDefault();
          const rawQuery = (searchInput.value || '').trim();
          const query = rawQuery.toLowerCase();

          if (query.length < 2) {
            queryChipEl.textContent = 'Please enter at least 2 characters';
            queryChipEl.classList.remove('d-none');
            renderRows([]);
            openModal();
            return;
          }

          queryChipEl.textContent = `Query: ${rawQuery}`;
          queryChipEl.classList.remove('d-none');
          const filteredRows = (programData || []).filter((row) => {
            const label = String(row.program_label || '').toLowerCase();
            const code = String(row.program_code || '').toLowerCase();
            const campus = String(row.campus_name || '').toLowerCase();
            return label.includes(query) || code.includes(query) || campus.includes(query);
          });

          renderRows(filteredRows);
          openModal();
        });
      })();

      (function () {
        if (window.location.hash !== '#program-slot-availability-tab') return;
        if (typeof bootstrap === 'undefined') return;

        const programSlotsTab = document.getElementById('program-slot-availability-tab');
        if (!programSlotsTab) return;

        bootstrap.Tab.getOrCreateInstance(programSlotsTab).show();
        const pinnedWrap = document.querySelector('.student-pinned-wrap');
        if (pinnedWrap) {
          pinnedWrap.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
      })();

      (function () {
        const profileModalEl = document.getElementById('studentProfileModal');
        const profileFormEl = document.getElementById('studentProfileForm');
        if (!profileFormEl) return;

        const toPositiveInt = (value) => {
          const parsed = Number(value);
          if (!Number.isFinite(parsed) || parsed <= 0) return 0;
          return Math.trunc(parsed);
        };

        const getDataSelected = (element) => toPositiveInt(element ? element.getAttribute('data-selected') : 0);

        const setLoadingOption = (element, message) => {
          if (!element) return;
          element.innerHTML = `<option value="">${message}</option>`;
        };

        const escapeHtml = (value) =>
          String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');

        const populateSelect = (element, items, placeholder, selectedValue) => {
          if (!element) return;
          const selected = toPositiveInt(selectedValue);
          let html = `<option value="">${escapeHtml(placeholder)}</option>`;
          if (Array.isArray(items)) {
            items.forEach((item) => {
              const code = toPositiveInt(item.code);
              const label = String(item.label || '').trim();
              if (code <= 0 || label === '') return;
              const isSelected = selected > 0 && code === selected;
              html += `<option value="${code}"${isSelected ? ' selected' : ''}>${escapeHtml(label)}</option>`;
            });
          }
          element.innerHTML = html;
        };

        const fetchLookupItems = async (lookupType, key, value) => {
          const lookupUrl = `index.php?profile_lookup=${encodeURIComponent(lookupType)}&${encodeURIComponent(key)}=${encodeURIComponent(value)}`;
          const response = await fetch(lookupUrl, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            credentials: 'same-origin',
          });
          if (!response.ok) {
            throw new Error(`Lookup request failed (${response.status})`);
          }
          const payload = await response.json();
          if (!payload || payload.success !== true || !Array.isArray(payload.items)) {
            throw new Error('Lookup payload is invalid');
          }
          return payload.items;
        };

        const createAddressBinding = (config) => {
          const regionEl = document.getElementById(config.regionId);
          const provinceEl = document.getElementById(config.provinceId);
          const citymunEl = document.getElementById(config.citymunId);
          const barangayEl = document.getElementById(config.barangayId);
          if (!regionEl || !provinceEl || !citymunEl || !barangayEl) {
            return null;
          }
          const regionRequired = config.regionOptional !== true;

          const resetDependentSelects = () => {
            populateSelect(provinceEl, [], 'Select Province', 0);
            populateSelect(citymunEl, [], 'Select City / Municipality', 0);
            populateSelect(barangayEl, [], 'Select Barangay', 0);
          };

          const loadProvinces = async (regionCode, selectedProvinceCode) => {
            const normalizedRegionCode = toPositiveInt(regionCode);
            if (regionRequired && normalizedRegionCode <= 0) {
              resetDependentSelects();
              return;
            }

            setLoadingOption(provinceEl, 'Loading provinces...');
            populateSelect(citymunEl, [], 'Select City / Municipality', 0);
            populateSelect(barangayEl, [], 'Select Barangay', 0);

            try {
              const items = await fetchLookupItems('province', 'region_code', normalizedRegionCode || 0);
              populateSelect(provinceEl, items, 'Select Province', selectedProvinceCode);
            } catch (error) {
              setLoadingOption(provinceEl, 'Unable to load provinces');
            }
          };

          const loadCities = async (provinceCode, selectedCityCode) => {
            const normalizedProvinceCode = toPositiveInt(provinceCode);
            if (normalizedProvinceCode <= 0) {
              populateSelect(citymunEl, [], 'Select City / Municipality', 0);
              populateSelect(barangayEl, [], 'Select Barangay', 0);
              return;
            }

            setLoadingOption(citymunEl, 'Loading cities/municipalities...');
            populateSelect(barangayEl, [], 'Select Barangay', 0);

            try {
              const items = await fetchLookupItems('citymun', 'province_code', normalizedProvinceCode);
              populateSelect(citymunEl, items, 'Select City / Municipality', selectedCityCode);
            } catch (error) {
              setLoadingOption(citymunEl, 'Unable to load cities/municipalities');
            }
          };

          const loadBarangays = async (citymunCode, selectedBarangayCode) => {
            const normalizedCityCode = toPositiveInt(citymunCode);
            if (normalizedCityCode <= 0) {
              populateSelect(barangayEl, [], 'Select Barangay', 0);
              return;
            }

            setLoadingOption(barangayEl, 'Loading barangays...');

            try {
              const items = await fetchLookupItems('barangay', 'citymun_code', normalizedCityCode);
              populateSelect(barangayEl, items, 'Select Barangay', selectedBarangayCode);
            } catch (error) {
              setLoadingOption(barangayEl, 'Unable to load barangays');
            }
          };

          if (regionRequired) {
            regionEl.addEventListener('change', async function () {
              const regionCode = toPositiveInt(this.value);
              provinceEl.setAttribute('data-selected', '');
              citymunEl.setAttribute('data-selected', '');
              barangayEl.setAttribute('data-selected', '');
              await loadProvinces(regionCode, 0);
            });
          }

          provinceEl.addEventListener('change', async function () {
            const provinceCode = toPositiveInt(this.value);
            citymunEl.setAttribute('data-selected', '');
            barangayEl.setAttribute('data-selected', '');
            await loadCities(provinceCode, 0);
          });

          citymunEl.addEventListener('change', async function () {
            const cityCode = toPositiveInt(this.value);
            barangayEl.setAttribute('data-selected', '');
            await loadBarangays(cityCode, 0);
          });

          return {
            initialize: async () => {
              const selectedRegion = toPositiveInt(regionEl.value) || getDataSelected(regionEl);
              const selectedProvince = toPositiveInt(provinceEl.value) || getDataSelected(provinceEl);
              const selectedCity = toPositiveInt(citymunEl.value) || getDataSelected(citymunEl);
              const selectedBarangay = toPositiveInt(barangayEl.value) || getDataSelected(barangayEl);

              if (regionRequired && selectedRegion <= 0) {
                resetDependentSelects();
                return;
              }

              if (selectedRegion > 0) {
                regionEl.value = String(selectedRegion);
              }
              await loadProvinces(selectedRegion, selectedProvince);
              if (selectedProvince <= 0) return;

              provinceEl.value = String(selectedProvince);
              await loadCities(selectedProvince, selectedCity);
              if (selectedCity <= 0) return;

              citymunEl.value = String(selectedCity);
              await loadBarangays(selectedCity, selectedBarangay);
              if (selectedBarangay > 0) {
                barangayEl.value = String(selectedBarangay);
              }
            },
          };
        };

        const addressBindings = [
          createAddressBinding({
            regionId: 'profileSecondaryRegion',
            provinceId: 'profileSecondaryProvince',
            citymunId: 'profileSecondaryCityMun',
            barangayId: 'profileSecondaryBarangay',
            regionOptional: true,
          }),
          createAddressBinding({
            regionId: 'profileRegion',
            provinceId: 'profileProvince',
            citymunId: 'profileCityMun',
            barangayId: 'profileBarangay',
          }),
          createAddressBinding({
            regionId: 'profileParentGuardianRegion',
            provinceId: 'profileParentGuardianProvince',
            citymunId: 'profileParentGuardianCityMun',
            barangayId: 'profileParentGuardianBarangay',
          }),
        ].filter(Boolean);

        const initializeProfileAddressBindings = async function () {
          for (const binding of addressBindings) {
            if (!binding || typeof binding.initialize !== 'function') {
              continue;
            }
            await binding.initialize();
          }
        };

        if (profileModalEl) {
          profileModalEl.addEventListener('shown.bs.modal', initializeProfileAddressBindings);
          return;
        }

        initializeProfileAddressBindings();
      })();

      (function () {
        if (typeof bootstrap === 'undefined') return;

        const transferModalEl = document.getElementById('studentTransferModal');
        if (!transferModalEl) return;

        const transferModal = bootstrap.Modal.getOrCreateInstance(transferModalEl);
        const buttons = document.querySelectorAll('.js-open-transfer-modal');
        const transferFormEl = document.getElementById('studentTransferForm');
        const programIdInputEl = document.getElementById('transferModalProgramId');
        const reasonInputEl = document.getElementById('transferReasonInput');
        const wordCountEl = document.getElementById('transferReasonWordCount');
        const reasonErrorEl = document.getElementById('transferReasonError');
        const submitBtnEl = document.getElementById('transferModalSubmitBtn');
        const minimumWords = 50;
        let submitAttempted = false;

        const setText = (id, value) => {
          const element = document.getElementById(id);
          if (!element) return;
          element.textContent = String(value ?? '').trim() || '-';
        };

        const countWords = (text) => {
          const words = String(text || '').trim().match(/\S+/g);
          return words ? words.length : 0;
        };

        const updateReasonState = () => {
          const wordCount = countWords(reasonInputEl ? reasonInputEl.value : '');
          if (wordCountEl) {
            wordCountEl.textContent = `${wordCount} / ${minimumWords} words`;
          }

          const isValidReason = wordCount >= minimumWords;
          const hasTypedReason = !!(reasonInputEl && reasonInputEl.value.trim().length > 0);
          const hasProgram = !!(programIdInputEl && Number(programIdInputEl.value) > 0);
          if (submitBtnEl) {
            submitBtnEl.disabled = !(isValidReason && hasProgram);
          }

          if (reasonErrorEl) {
            reasonErrorEl.classList.toggle('d-none', isValidReason || (!submitAttempted && !hasTypedReason));
          }

          if (reasonInputEl) {
            reasonInputEl.classList.toggle('is-invalid', !isValidReason && (submitAttempted || hasTypedReason));
          }
        };

        if (reasonInputEl) {
          reasonInputEl.addEventListener('input', updateReasonState);
        }

        if (transferFormEl) {
          transferFormEl.addEventListener('submit', function (event) {
            const selectedProgramId = Number(programIdInputEl ? programIdInputEl.value : 0);
            const words = countWords(reasonInputEl ? reasonInputEl.value : '');
            const isValid = selectedProgramId > 0 && words >= minimumWords;
            if (!isValid) {
              submitAttempted = true;
              event.preventDefault();
              updateReasonState();
            }
          });
        }

        buttons.forEach((button) => {
          button.addEventListener('click', function () {
            setText('transferModalProgramLabel', this.getAttribute('data-program-label'));
            setText('transferModalProgramCode', this.getAttribute('data-program-code'));
            setText('transferModalStatus', this.getAttribute('data-status'));
            setText('transferModalCampus', this.getAttribute('data-program-campus'));
            setText('transferModalCapacity', this.getAttribute('data-capacity'));
            setText('transferModalAvailable', this.getAttribute('data-available'));
            setText('transferModalCutoff', this.getAttribute('data-cutoff'));
            if (programIdInputEl) {
              programIdInputEl.value = String(this.getAttribute('data-program-id') || '').trim();
            }
            if (reasonInputEl) {
              reasonInputEl.value = '';
            }
            submitAttempted = false;
            updateReasonState();
            transferModal.show();
          });
        });
      })();

      (function () {
        if (typeof bootstrap === 'undefined') return;

        const agreementModalEl = document.getElementById('studentPreRegistrationAgreementModal');
        const agreementCheckboxEl = document.getElementById('studentPreregAgreementCheckbox');
        const agreementSubmitBtnEl = document.getElementById('studentPreregAgreementSubmitBtn');
        if (!agreementModalEl || !agreementCheckboxEl || !agreementSubmitBtnEl) return;

        const syncAgreementState = () => {
          agreementSubmitBtnEl.disabled = !agreementCheckboxEl.checked;
        };

        agreementModalEl.addEventListener('show.bs.modal', function () {
          agreementCheckboxEl.checked = false;
          syncAgreementState();
        });

        agreementCheckboxEl.addEventListener('change', syncAgreementState);
        syncAgreementState();
      })();
    </script>
  </body>
</html>
