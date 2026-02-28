<?php
require_once '../config/db.php';
require_once '../config/student_credentials.php';
require_once '../config/session_security.php';
secure_session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'student')) {
    header('Location: ../index.php');
    exit;
}

if (!empty($_SESSION['student_must_change_password'])) {
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
        p2.program_name AS second_program_name,
        p2.major AS second_program_major,
        p3.program_name AS third_program_name,
        p3.major AS third_program_major
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
    LEFT JOIN tbl_program p2
      ON p2.program_id = si.second_choice
    LEFT JOIN tbl_program p3
      ON p3.program_id = si.third_choice
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

if ((int) ($student['must_change_password'] ?? 0) === 1) {
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

function calculate_student_profile_completion_percent(array $profileData)
{
    $requiredTextFields = [
        'birth_date',
        'sex',
        'civil_status',
        'nationality',
        'religion',
        'secondary_school_name',
        'secondary_school_type',
        'secondary_postal_code',
        'address_line1',
        'postal_code',
        'guardian_name',
        'parent_guardian_address_line1',
        'parent_guardian_postal_code',
    ];
    $requiredCodeFields = [
        'secondary_province_code',
        'secondary_citymun_code',
        'region_code',
        'province_code',
        'citymun_code',
        'barangay_code',
        'parent_guardian_region_code',
        'parent_guardian_province_code',
        'parent_guardian_citymun_code',
        'parent_guardian_barangay_code',
    ];

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

if (!ensure_student_profile_table($conn)) {
    http_response_code(500);
    exit('Student profile storage initialization failed.');
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
$studentProfile['profile_completion_percent'] = $studentProfileCompletionPercent;

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

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_profile_csrf'] ?? '');
    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $flashMessage = 'Invalid profile security token. Refresh the page and try again.';
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
                $bindTypes = 'i'
                    . str_repeat('s', 9)
                    . str_repeat('i', 4)
                    . str_repeat('s', 2)
                    . str_repeat('i', 4)
                    . 's'
                    . str_repeat('i', 4)
                    . 's'
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'student_transfer_submit') {
    $flashType = 'danger';
    $flashMessage = 'Transfer request failed.';

    $postedCsrf = (string) ($_POST['csrf_token'] ?? '');
    $sessionCsrf = (string) ($_SESSION['student_transfer_csrf'] ?? '');
    $targetProgramId = (int) ($_POST['to_program_id'] ?? 0);
    $transferReason = trim((string) ($_POST['transfer_reason'] ?? ''));
    $transferReasonWords = count_reason_words($transferReason);
    $interviewId = (int) ($student['interview_id'] ?? 0);
    $currentFirstChoiceId = (int) ($student['first_choice'] ?? 0);
    $studentSatScoreForTransfer = null;
    $studentClassGroupForTransfer = (strtoupper(trim((string) ($student['classification'] ?? 'REGULAR'))) === 'ETG')
        ? 'ETG'
        : 'REGULAR';
    if (isset($student['sat_score']) && $student['sat_score'] !== '' && $student['sat_score'] !== null) {
        $studentSatScoreForTransfer = (float) $student['sat_score'];
    }

    if ($postedCsrf === '' || $sessionCsrf === '' || !hash_equals($sessionCsrf, $postedCsrf)) {
        $flashMessage = 'Invalid security token. Refresh the page and try again.';
    } elseif ($interviewId <= 0) {
        $flashMessage = 'Interview record is missing. Transfer cannot be processed.';
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
                pc.cutoff_score,
                pc.absorptive_capacity,
                pc.regular_percentage,
                pc.etg_percentage,
                COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity,
                COALESCE(scored.scored_students, 0) AS scored_students
            FROM tbl_program p
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
            $targetCapacity = ($targetProgram['absorptive_capacity'] !== null && $targetProgram['absorptive_capacity'] !== '')
                ? max(0, (int) $targetProgram['absorptive_capacity'])
                : null;
            $targetScored = max(0, (int) ($targetProgram['scored_students'] ?? 0));
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

            $targetClassScored = 0;
            if ($targetProgramId > 0) {
                $targetClassSql = "
                    SELECT COUNT(*) AS class_scored
                    FROM tbl_student_interview
                    WHERE status = 'active'
                      AND final_score IS NOT NULL
                      AND first_choice = ?
                      AND UPPER(COALESCE(classification, 'REGULAR')) = ?
                ";
                $targetClassStmt = $conn->prepare($targetClassSql);
                if ($targetClassStmt) {
                    $targetClassStmt->bind_param('is', $targetProgramId, $studentClassGroupForTransfer);
                    $targetClassStmt->execute();
                    $targetClassRow = $targetClassStmt->get_result()->fetch_assoc();
                    $targetClassScored = max(0, (int) ($targetClassRow['class_scored'] ?? 0));
                    $targetClassStmt->close();
                }
            }

            if ($targetCapacity !== null) {
                if ($targetQuotaConfigured && $targetSlotLimit !== null) {
                    $targetAvailable = max(0, (int) $targetSlotLimit - $targetClassScored);
                } else {
                    $targetAvailable = max(0, $targetCapacity - $targetScored);
                }
            } else {
                $targetAvailable = 0;
            }
            $targetCutoff = ($targetProgram['cutoff_score'] !== null && $targetProgram['cutoff_score'] !== '')
                ? (int) $targetProgram['cutoff_score']
                : null;
            $targetOpen = ($targetCapacity !== null && $targetAvailable > 0);
            $targetSatQualified = ($targetCutoff !== null && $studentSatScoreForTransfer !== null)
                ? ($studentSatScoreForTransfer >= $targetCutoff)
                : false;

            if (!$targetOpen) {
                $flashMessage = 'Selected program has no available slots.';
            } elseif (!$targetSatQualified) {
                $flashMessage = 'Your SAT score does not meet the selected program cutoff.';
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
                            program_chair_id = ?
                        WHERE interview_id = ?
                        LIMIT 1
                    ";
                    $updateInterviewStmt = $conn->prepare($updateInterviewSql);
                    if (!$updateInterviewStmt) {
                        throw new Exception('Failed to prepare interview update.');
                    }
                    $updateInterviewStmt->bind_param(
                        'iiiiii',
                        $newFirstChoice,
                        $newSecondChoice,
                        $newThirdChoice,
                        $targetProgramId,
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

$transferActionEnabled = $firstChoiceOutsideCapacity;
$transferActionHref = $transferActionEnabled ? '#program-slot-availability-tab' : 'javascript:void(0);';
$transferActionClass = $transferActionEnabled ? '' : ' is-disabled';
$transferActionSub = $transferActionEnabled
    ? 'Outside capacity: explore open programs'
    : 'Available when rank is outside capacity';

$studentSatScore = null;
if (isset($student['sat_score']) && $student['sat_score'] !== '' && $student['sat_score'] !== null) {
    $studentSatScore = (float) $student['sat_score'];
}
$studentFinalScoreValue = $hasScoredInterview ? (float) $finalScore : null;
$programRankingPools = [];
$rankingPoolSql = "
    SELECT
        si.first_choice AS program_id,
        CASE
            WHEN UPPER(COALESCE(si.classification, 'REGULAR')) = 'ETG' THEN 'ETG'
            ELSE 'REGULAR'
        END AS class_group,
        si.examinee_number,
        si.final_score,
        pr.sat_score,
        pr.full_name
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON pr.id = si.placement_result_id
    WHERE si.status = 'active'
      AND si.final_score IS NOT NULL
      AND si.first_choice IS NOT NULL
      AND si.first_choice > 0
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

        if (!isset($programRankingPools[$programId])) {
            $programRankingPools[$programId] = [
                'REGULAR' => [],
                'ETG' => [],
            ];
        }

        $programRankingPools[$programId][$classGroup][] = [
            'examinee_number' => (string) ($rankingRow['examinee_number'] ?? ''),
            'final_score' => (float) ($rankingRow['final_score'] ?? 0),
            'sat_score' => (float) ($rankingRow['sat_score'] ?? 0),
            'full_name' => trim((string) ($rankingRow['full_name'] ?? '')),
        ];
    }

    $rankingPoolStmt->close();
}

$rankingComparator = function (array $left, array $right): int {
    $leftFinal = (float) ($left['final_score'] ?? 0);
    $rightFinal = (float) ($right['final_score'] ?? 0);
    if ($leftFinal !== $rightFinal) {
        return ($leftFinal < $rightFinal) ? 1 : -1;
    }

    $leftSat = (float) ($left['sat_score'] ?? 0);
    $rightSat = (float) ($right['sat_score'] ?? 0);
    if ($leftSat !== $rightSat) {
        return ($leftSat < $rightSat) ? 1 : -1;
    }

    $nameComparison = strcasecmp((string) ($left['full_name'] ?? ''), (string) ($right['full_name'] ?? ''));
    if ($nameComparison !== 0) {
        return $nameComparison;
    }

    return strcmp((string) ($left['examinee_number'] ?? ''), (string) ($right['examinee_number'] ?? ''));
};

foreach ($programRankingPools as $programId => $groupedRows) {
    foreach (['REGULAR', 'ETG'] as $groupKey) {
        $rows = $groupedRows[$groupKey] ?? [];
        usort($rows, $rankingComparator);
        $programRankingPools[$programId][$groupKey] = $rows;
    }
}

$getProjectedRank = function (array $rankedRows, array $candidateRow) use ($rankingComparator): ?int {
    $candidateExaminee = (string) ($candidateRow['examinee_number'] ?? '');
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
    usort($rows, $rankingComparator);

    $rank = 1;
    foreach ($rows as $row) {
        if ((string) ($row['examinee_number'] ?? '') === $candidateExaminee) {
            return $rank;
        }
        $rank++;
    }

    return null;
};

$allPrograms = [];
$allProgramsSql = "
    SELECT
        p.program_id,
        p.program_code,
        p.program_name,
        p.major,
        pc.absorptive_capacity,
        pc.cutoff_score,
        pc.regular_percentage,
        pc.etg_percentage,
        COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity,
        COALESCE(scored.scored_students, 0) AS scored_students
    FROM tbl_program p
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

        $scoredStudents = max(0, (int) ($programRow['scored_students'] ?? 0));
        $endorsementCapacity = max(0, (int) ($programRow['endorsement_capacity'] ?? 0));
        $classScoredCount = count($programRankingPools[$programId][$studentClassGroup] ?? []);
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
        if ($hasScoredInterview && $studentFinalScoreValue !== null && $programId > 0) {
            $rankingPool = $programRankingPools[$programId][$studentClassGroup] ?? [];
            $studentProjectedRank = $getProjectedRank($rankingPool, [
                'examinee_number' => $currentExaminee,
                'final_score' => $studentFinalScoreValue,
                'sat_score' => (float) ($studentSatScore ?? 0),
                'full_name' => $studentName,
            ]);
        }

        $slotStatus = 'Capacity not set';
        if ($capacity !== null) {
            $slotStatus = ($availableSlots > 0) ? 'Open' : 'Full';
        }

        $satQualified = ($cutoffScore !== null && $studentSatScore !== null)
            ? ($studentSatScore >= $cutoffScore)
            : false;
        $rankQualified = ($slotStatus === 'Open');
        $transferOpen = ($slotStatus === 'Open') && $satQualified;

        $allPrograms[] = [
            'program_id' => $programId,
            'program_code' => strtoupper(trim((string) ($programRow['program_code'] ?? ''))),
            'program_label' => format_program_label($programRow['program_name'] ?? '', $programRow['major'] ?? ''),
            'absorptive_capacity' => $capacity,
            'cutoff_score' => $cutoffScore,
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

$slotPrograms = $allPrograms;

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
    if ($slotStatus === 'Open') {
        $slotBadgeClass = 'bg-label-success';
    } elseif ($slotStatus === 'Full') {
        $slotBadgeClass = 'bg-label-danger';
    }

    $satQualified = (bool) ($programData['sat_qualified'] ?? false);
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
    } elseif ($slotStatus !== 'Open') {
        $qualificationNote = 'No open slots available';
    } elseif ($transferOpen) {
        $qualificationNote = 'Eligible for transfer';
    }

    return [
        'program_id' => $programId,
        'program_code' => $programCode,
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

$programChoiceCards = [
    [
        'title' => '1st Choice Program',
        'program' => format_program_label($student['first_program_name'] ?? '', $student['first_program_major'] ?? ''),
        'program_code' => $firstChoiceSlotDetails['program_code'],
        'is_primary' => true,
        'stat_label' => 'Rank / Total Scored',
        'stat_value' => $firstChoiceRankDisplay,
        'stat_primary_value' => $firstChoiceRankValueDisplay,
        'stat_secondary_value' => $firstChoiceRankTotalDisplay,
        'stat_primary_outside' => $firstChoiceOutsideCapacity,
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $firstChoiceSlotDetails['capacity_display']],
            ['label' => 'Available Slots', 'value' => $firstChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $firstChoiceSlotDetails['cutoff_display']],
        ],
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
    </style>
  </head>

  <body>
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
                  <small class="sidebar-action-sub">View your student details</small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a href="<?= htmlspecialchars($transferActionHref); ?>" class="menu-link sidebar-action-card<?= $transferActionClass; ?>">
                <span class="sidebar-action-icon bg-label-warning"><i class="bx bx-transfer-alt"></i></span>
                <div>
                  <div class="sidebar-action-title">Transfer</div>
                  <small class="sidebar-action-sub"><?= htmlspecialchars($transferActionSub); ?></small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a href="change_password.php" class="menu-link sidebar-action-card">
                <span class="sidebar-action-icon bg-label-info"><i class="bx bx-lock-open-alt"></i></span>
                <div>
                  <div class="sidebar-action-title">Change Password</div>
                  <small class="sidebar-action-sub">Update account security</small>
                </div>
              </a>
            </li>
            <li class="menu-item px-2">
              <a href="javascript:void(0);" class="menu-link sidebar-action-card is-disabled">
                <span class="sidebar-action-icon bg-label-secondary"><i class="bx bx-time-five"></i></span>
                <div>
                  <div class="sidebar-action-title">Logs</div>
                  <small class="sidebar-action-sub">Feature coming soon</small>
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
                <form id="studentProgramSearchForm" class="nav-item d-flex align-items-center w-100" autocomplete="off">
                  <i class="bx bx-search fs-4 lh-0"></i>
                  <input
                    type="search"
                    id="studentProgramSearchInput"
                    class="form-control border-0 shadow-none w-100"
                    style="max-width: 42rem;"
                    placeholder="Search program name/code and press Enter to view available slots"
                    aria-label="Search programs"
                  />
                </form>
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
                    <li><a class="dropdown-item" href="change_password.php"><i class="bx bx-lock-open-alt me-2"></i>Change Password</a></li>
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

              <div class="row">
                <div class="col-lg-8 col-12">
                  <div id="program-slot-availability" class="card mb-4">
                    <div class="d-flex align-items-end row">
                      <div class="col-sm-7">
                        <div class="card-body">
                          <h5 class="card-title text-primary">Student Dashboard</h5>
                          <p class="mb-4">Track your interview progress, verify your details, and explore available program slots.</p>
                          <div class="d-flex flex-wrap gap-2 mb-4">
                            <span class="badge <?= $hasScoredInterview ? 'bg-label-success' : 'bg-label-warning'; ?>">Interview: <?= $hasScoredInterview ? 'Scored' : 'Pending'; ?></span>
                          </div>
                          <div class="d-flex flex-wrap align-items-center gap-2">
                            <button type="button" class="btn btn-sm btn-primary" disabled>Register Me!</button>
                            <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#studentProfileModal">
                              Update My Profile
                            </button>
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
                </div>

                <div class="col-lg-4 col-12">
                  <div class="student-pinned-wrap">
                    <div class="card mb-4">
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
                                  <div class="student-program-card-title mt-3"><?= htmlspecialchars($choiceCard['stat_label']); ?></div>
                                  <?php if ($index === 0 && $choiceCard['stat_primary_value'] !== null && $choiceCard['stat_secondary_value'] !== null): ?>
                                    <div class="student-program-card-value">
                                      <span class="<?= !empty($choiceCard['stat_primary_outside']) ? 'student-rank-number-outside' : ''; ?>">
                                        <?= htmlspecialchars((string) $choiceCard['stat_primary_value']); ?>
                                      </span>
                                      <span>/<?= htmlspecialchars((string) $choiceCard['stat_secondary_value']); ?></span>
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
                                      data-capacity="<?= htmlspecialchars((string) ($choiceCard['transfer_capacity'] ?? 'N/A')); ?>"
                                      data-available="<?= htmlspecialchars((string) ($choiceCard['transfer_available'] ?? 'N/A')); ?>"
                                      data-cutoff="<?= htmlspecialchars((string) ($choiceCard['transfer_cutoff'] ?? 'N/A')); ?>"
                                      data-status="<?= htmlspecialchars((string) ($choiceCard['slot_status'] ?? 'N/A')); ?>"
                                    >
                                      Transfer Here
                                    </button>
                                  <?php endif; ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          </div>
                        </div>
                        <div
                          class="tab-pane fade"
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
                                  if (($program['slot_status'] ?? '') === 'Open') {
                                      $statusBadgeClass = 'bg-label-success';
                                  } elseif (($program['slot_status'] ?? '') === 'Full') {
                                      $statusBadgeClass = 'bg-label-danger';
                                  }
                                ?>
                                <div class="student-program-item">
                                  <div class="student-program-item-name"><?= htmlspecialchars((string) ($program['program_code'] ?? '')); ?><?= htmlspecialchars((string) ($program['program_code'] ?? '') !== '' ? ' - ' : ''); ?><?= htmlspecialchars((string) ($program['program_label'] ?? 'N/A')); ?></div>
                                  <div class="student-program-item-meta">Capacity: <strong><?= htmlspecialchars($capacityDisplay); ?></strong></div>
                                  <div class="student-program-item-meta">Cutoff SAT: <strong><?= htmlspecialchars($cutoffDisplay); ?></strong></div>
                                  <div class="mt-2 d-flex align-items-center justify-content-between">
                                    <small class="fw-semibold">Available Slots: <?= htmlspecialchars($availableDisplay); ?></small>
                                    <span class="badge <?= $statusBadgeClass; ?>"><?= htmlspecialchars((string) ($program['slot_status'] ?? 'N/A')); ?></span>
                                  </div>
                                  <?php if (!empty($program['transfer_open'])): ?>
                                    <button
                                      type="button"
                                      class="btn btn-sm btn-outline-warning student-transfer-btn js-open-transfer-modal"
                                      data-program-id="<?= htmlspecialchars((string) ($program['program_id'] ?? '')); ?>"
                                      data-program-code="<?= htmlspecialchars((string) ($program['program_code'] ?? '')); ?>"
                                      data-program-label="<?= htmlspecialchars((string) ($program['program_label'] ?? '')); ?>"
                                      data-capacity="<?= htmlspecialchars($capacityDisplay); ?>"
                                      data-available="<?= htmlspecialchars($availableDisplay); ?>"
                                      data-cutoff="<?= htmlspecialchars($cutoffDisplay); ?>"
                                      data-status="<?= htmlspecialchars((string) ($program['slot_status'] ?? 'N/A')); ?>"
                                    >
                                      Transfer Here
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
            <div class="modal-body">
              <div class="small text-muted mb-3">Current completion: <strong><?= htmlspecialchars($profileCompletionBadge); ?></strong></div>

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
                  <input type="date" class="form-control" id="profileBirthDate" name="birth_date" value="<?= htmlspecialchars((string) ($studentProfile['birth_date'] ?? '')); ?>" />
                </div>
                <div class="col-md-4">
                  <label for="profileSex" class="form-label">Sex</label>
                  <select class="form-select" id="profileSex" name="sex">
                    <option value="">Select Sex</option>
                    <option value="Male" <?= ((string) ($studentProfile['sex'] ?? '') === 'Male') ? 'selected' : ''; ?>>Male</option>
                    <option value="Female" <?= ((string) ($studentProfile['sex'] ?? '') === 'Female') ? 'selected' : ''; ?>>Female</option>
                    <option value="Other" <?= ((string) ($studentProfile['sex'] ?? '') === 'Other') ? 'selected' : ''; ?>>Other</option>
                  </select>
                </div>
                <div class="col-md-4">
                  <label for="profileCivilStatus" class="form-label">Civil Status</label>
                  <select class="form-select" id="profileCivilStatus" name="civil_status">
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
                  <input type="text" class="form-control" id="profileNationality" name="nationality" maxlength="100" value="<?= htmlspecialchars((string) ($studentProfile['nationality'] ?? '')); ?>" />
                </div>
                <div class="col-md-6">
                  <label for="profileReligion" class="form-label">Religion</label>
                  <input type="text" class="form-control" id="profileReligion" name="religion" maxlength="120" value="<?= htmlspecialchars((string) ($studentProfile['religion'] ?? '')); ?>" />
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
                    />
                  </div>
                  <div class="col-md-4">
                    <label for="profileSchoolType" class="form-label">School Type</label>
                    <select class="form-select" id="profileSchoolType" name="secondary_school_type">
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
                    />
                  </div>
                </div>
              </div>

              <div class="student-profile-panel mt-4">
                <div class="student-profile-section-title">Address Information</div>
              <div class="row g-3">
                <div class="col-md-12">
                  <label for="profileAddressLine1" class="form-label">House No. / Street / Purok</label>
                  <input type="text" class="form-control" id="profileAddressLine1" name="address_line1" maxlength="255" value="<?= htmlspecialchars((string) ($studentProfile['address_line1'] ?? '')); ?>" />
                </div>
                <div class="col-md-3">
                  <label for="profileRegion" class="form-label">Region</label>
                  <select class="form-select" id="profileRegion" name="region_code">
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
                  >
                    <option value="">Select Barangay</option>
                    <?php if ($profileBarangayCode > 0 && trim((string) ($studentProfile['barangay_name'] ?? '')) !== ''): ?>
                      <option value="<?= htmlspecialchars((string) $profileBarangayCode); ?>" selected><?= htmlspecialchars((string) ($studentProfile['barangay_name'] ?? '')); ?></option>
                    <?php endif; ?>
                  </select>
                </div>
                <div class="col-md-3">
                  <label for="profilePostalCode" class="form-label">Postal Code</label>
                  <input type="text" class="form-control" id="profilePostalCode" name="postal_code" maxlength="20" value="<?= htmlspecialchars((string) ($studentProfile['postal_code'] ?? '')); ?>" />
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
                    />
                  </div>
                  <div class="col-md-3">
                    <label for="profileParentGuardianRegion" class="form-label">Region</label>
                    <select class="form-select" id="profileParentGuardianRegion" name="parent_guardian_region_code">
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
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
              <button type="submit" class="btn btn-primary">Save Profile</button>
            </div>
          </form>
        </div>
      </div>
    </div>

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
            if (status === 'Open') {
              badgeClass = 'bg-label-success';
            } else if (status === 'Full') {
              badgeClass = 'bg-label-danger';
            }

            const programCode = row.program_code ? `${escapeHtml(row.program_code)} - ` : '';
            return `<tr><td class="fw-semibold">${programCode}${escapeHtml(row.program_label || '-')}</td><td>${formatNumber(row.absorptive_capacity)}</td><td>${formatNumber(row.available_slots)}</td><td><span class="badge ${badgeClass}">${escapeHtml(status)}</span></td></tr>`;
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
            return label.includes(query) || code.includes(query);
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
        if (!profileModalEl) return;

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

        profileModalEl.addEventListener('shown.bs.modal', async function () {
          for (const binding of addressBindings) {
            if (!binding || typeof binding.initialize !== 'function') {
              continue;
            }
            await binding.initialize();
          }
        });
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
    </script>
  </body>
</html>
