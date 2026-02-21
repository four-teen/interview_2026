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
if ($firstChoiceId > 0) {
    $capacitySql = "
        SELECT absorptive_capacity
        FROM tbl_program_cutoff
        WHERE program_id = ?
        ORDER BY date_updated DESC, cutoff_id DESC
        LIMIT 1
    ";

    if ($capacityStmt = $conn->prepare($capacitySql)) {
        $capacityStmt->bind_param('i', $firstChoiceId);
        $capacityStmt->execute();
        $capacityRow = $capacityStmt->get_result()->fetch_assoc();
        if ($capacityRow && $capacityRow['absorptive_capacity'] !== null) {
            $firstChoiceAbsorptiveCapacity = max(0, (int) $capacityRow['absorptive_capacity']);
        }
        $capacityStmt->close();
    }
}

$firstChoiceRank = null;
if ($firstChoiceId > 0 && $hasScoredInterview) {
    $rankSql = "
        SELECT si2.examinee_number
        FROM tbl_student_interview si2
        INNER JOIN tbl_placement_results pr2
            ON pr2.id = si2.placement_result_id
        WHERE si2.status = 'active'
          AND si2.final_score IS NOT NULL
          AND si2.first_choice = ?
        ORDER BY si2.final_score DESC, pr2.sat_score DESC, pr2.full_name ASC, si2.examinee_number ASC
    ";

    if ($rankStmt = $conn->prepare($rankSql)) {
        $rankStmt->bind_param('i', $firstChoiceId);
        $rankStmt->execute();
        $rankResult = $rankStmt->get_result();

        $position = 1;
        while ($rankRow = $rankResult->fetch_assoc()) {
            if ((string) ($rankRow['examinee_number'] ?? '') === $currentExaminee) {
                $firstChoiceRank = $position;
                break;
            }
            $position++;
        }

        $rankStmt->close();
    }
}

$firstChoiceRankDisplay = 'N/A';
if ($firstChoiceId > 0) {
    if ($hasScoredInterview && $firstChoiceRank !== null) {
        $firstChoiceRankDisplay = number_format($firstChoiceRank) . ' / ' . number_format($firstChoiceScoredTotal);
    } elseif ($hasScoredInterview) {
        $firstChoiceRankDisplay = 'N/A / ' . number_format($firstChoiceScoredTotal);
    } else {
        $firstChoiceRankDisplay = 'Pending / ' . number_format($firstChoiceScoredTotal);
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
$studentClassGroup = (strtoupper(trim((string) ($student['classification'] ?? 'REGULAR'))) === 'ETG') ? 'ETG' : 'REGULAR';

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
        COALESCE(scored.scored_students, 0) AS scored_students
    FROM tbl_program p
    LEFT JOIN (
        SELECT
            pcx.program_id,
            pcx.cutoff_score,
            pcx.absorptive_capacity,
            pcx.regular_percentage,
            pcx.etg_percentage
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
        $availableSlots = ($capacity !== null) ? max(0, $capacity - $scoredStudents) : null;

        $slotStatus = 'No Capacity';
        if ($capacity !== null) {
            $slotStatus = ($availableSlots > 0) ? 'Open' : 'Full';
        }

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
            $regularSlots = (int) round($capacity * ($regularPercentage / 100));
            $etgSlots = max(0, $capacity - $regularSlots);
        }

        $studentSlotLimit = null;
        if ($quotaConfigured) {
            $studentSlotLimit = ($studentClassGroup === 'ETG') ? $etgSlots : $regularSlots;
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

        $satQualified = ($cutoffScore !== null && $studentSatScore !== null)
            ? ($studentSatScore >= $cutoffScore)
            : false;
        $rankQualified = ($studentSlotLimit !== null && $studentProjectedRank !== null)
            ? ($studentProjectedRank <= $studentSlotLimit)
            : false;
        $transferOpen = ($slotStatus === 'Open') && $satQualified && $rankQualified;

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
            'scored_students' => $scoredStudents,
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

    $scored = max(0, (int) $scoredStudents);
    $available = ($capacity !== null) ? max(0, $capacity - $scored) : null;

    $slotStatus = 'No Capacity';
    if ($capacity !== null) {
        $slotStatus = ($available > 0) ? 'Open' : 'Full';
    }

    $slotBadgeClass = 'bg-label-secondary';
    if ($slotStatus === 'Open') {
        $slotBadgeClass = 'bg-label-success';
    } elseif ($slotStatus === 'Full') {
        $slotBadgeClass = 'bg-label-danger';
    }

    $studentSlotLimit = ($programData !== null && array_key_exists('student_slot_limit', $programData) && $programData['student_slot_limit'] !== null)
        ? max(0, (int) $programData['student_slot_limit'])
        : null;

    $satQualified = (bool) ($programData['sat_qualified'] ?? false);
    $rankQualified = (bool) ($programData['rank_qualified'] ?? false);
    $transferOpen = (bool) ($programData['transfer_open'] ?? false);

    $qualificationNote = 'No transfer evaluation';
    if (!$hasScoredInterview) {
        $qualificationNote = 'Final interview score required';
    } elseif ($cutoffScore === null) {
        $qualificationNote = 'Cutoff SAT not configured';
    } elseif (!$satQualified) {
        $qualificationNote = 'SAT below cutoff';
    } elseif ($studentSlotLimit === null) {
        $qualificationNote = 'Allocation not configured';
    } elseif (!$rankQualified) {
        $qualificationNote = 'Outside allocated capacity';
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
        'scored_display' => number_format($scored),
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
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $firstChoiceSlotDetails['capacity_display']],
            ['label' => 'Scored', 'value' => $firstChoiceSlotDetails['scored_display']],
            ['label' => 'Available Slots', 'value' => $firstChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $firstChoiceSlotDetails['cutoff_display']],
        ],
        'slot_status' => $firstChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $firstChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => '',
        'show_transfer' => false,
        'transfer_program_id' => $firstChoiceSlotDetails['program_id'],
        'transfer_capacity' => $firstChoiceSlotDetails['capacity_display'],
        'transfer_scored' => $firstChoiceSlotDetails['scored_display'],
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
        'stat_label' => 'Scored Students',
        'stat_value' => $secondChoiceSlotDetails['scored_display'],
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $secondChoiceSlotDetails['capacity_display']],
            ['label' => 'Scored', 'value' => $secondChoiceSlotDetails['scored_display']],
            ['label' => 'Available Slots', 'value' => $secondChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $secondChoiceSlotDetails['cutoff_display']],
        ],
        'slot_status' => $secondChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $secondChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => $secondChoiceSlotDetails['qualification_note'],
        'show_transfer' => $secondChoiceSlotDetails['transfer_open'],
        'transfer_program_id' => $secondChoiceSlotDetails['program_id'],
        'transfer_capacity' => $secondChoiceSlotDetails['capacity_display'],
        'transfer_scored' => $secondChoiceSlotDetails['scored_display'],
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
        'stat_label' => 'Scored Students',
        'stat_value' => $thirdChoiceSlotDetails['scored_display'],
        'meta_rows' => [
            ['label' => 'Capacity', 'value' => $thirdChoiceSlotDetails['capacity_display']],
            ['label' => 'Scored', 'value' => $thirdChoiceSlotDetails['scored_display']],
            ['label' => 'Available Slots', 'value' => $thirdChoiceSlotDetails['available_display']],
            ['label' => 'Cutoff SAT', 'value' => $thirdChoiceSlotDetails['cutoff_display']],
        ],
        'slot_status' => $thirdChoiceSlotDetails['slot_status'],
        'slot_badge_class' => $thirdChoiceSlotDetails['slot_badge_class'],
        'qualification_note' => $thirdChoiceSlotDetails['qualification_note'],
        'show_transfer' => $thirdChoiceSlotDetails['transfer_open'],
        'transfer_program_id' => $thirdChoiceSlotDetails['program_id'],
        'transfer_capacity' => $thirdChoiceSlotDetails['capacity_display'],
        'transfer_scored' => $thirdChoiceSlotDetails['scored_display'],
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
                          <button type="button" class="btn btn-sm btn-primary me-2" disabled>Register Me!</button>
                          <a href="change_password.php" class="btn btn-sm btn-outline-primary">Change Password</a>
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
                                  <div class="student-program-card-value"><?= htmlspecialchars($choiceCard['stat_value']); ?></div>
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
                                      data-scored="<?= htmlspecialchars((string) ($choiceCard['transfer_scored'] ?? 'N/A')); ?>"
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
                                  <div class="student-program-item-meta">Capacity: <strong><?= htmlspecialchars($capacityDisplay); ?></strong> | Scored: <strong><?= number_format((int) ($program['scored_students'] ?? 0)); ?></strong></div>
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
                                      data-scored="<?= htmlspecialchars((string) number_format((int) ($program['scored_students'] ?? 0))); ?>"
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
                <thead><tr><th>Program</th><th>Scored Students</th><th>Capacity</th><th>Available Slots</th><th>Status</th></tr></thead>
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
          <div class="modal-header">
            <h5 class="modal-title">Transfer Here</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <div class="alert alert-warning py-2 mb-3">
              Transfer request submission is not enabled yet. This is a preview modal only.
            </div>
            <div class="small text-muted mb-2">Selected Program</div>
            <div class="fw-semibold mb-2" id="transferModalProgramLabel">-</div>
            <div class="small mb-3">
              <span class="badge bg-label-primary" id="transferModalProgramCode">-</span>
              <span class="badge bg-label-success ms-1" id="transferModalStatus">-</span>
            </div>
            <div class="small text-muted mb-2">Program Details</div>
            <div class="small">
              <div class="d-flex justify-content-between py-1 border-bottom"><span>Capacity</span><strong id="transferModalCapacity">-</strong></div>
              <div class="d-flex justify-content-between py-1 border-bottom"><span>Scored</span><strong id="transferModalScored">-</strong></div>
              <div class="d-flex justify-content-between py-1 border-bottom"><span>Available Slots</span><strong id="transferModalAvailable">-</strong></div>
              <div class="d-flex justify-content-between py-1 border-bottom"><span>Cutoff SAT</span><strong id="transferModalCutoff">-</strong></div>
            </div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
            <button type="button" class="btn btn-warning" disabled>Transfer Here</button>
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
            const status = String(row.slot_status || 'No Capacity');
            if (status === 'Open') {
              badgeClass = 'bg-label-success';
            } else if (status === 'Full') {
              badgeClass = 'bg-label-danger';
            }

            const programCode = row.program_code ? `${escapeHtml(row.program_code)} - ` : '';
            return `<tr><td class="fw-semibold">${programCode}${escapeHtml(row.program_label || '-')}</td><td>${formatNumber(row.scored_students)}</td><td>${formatNumber(row.absorptive_capacity)}</td><td>${formatNumber(row.available_slots)}</td><td><span class="badge ${badgeClass}">${escapeHtml(status)}</span></td></tr>`;
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
        if (typeof bootstrap === 'undefined') return;

        const transferModalEl = document.getElementById('studentTransferModal');
        if (!transferModalEl) return;

        const transferModal = bootstrap.Modal.getOrCreateInstance(transferModalEl);
        const buttons = document.querySelectorAll('.js-open-transfer-modal');

        const setText = (id, value) => {
          const element = document.getElementById(id);
          if (!element) return;
          element.textContent = String(value ?? '').trim() || '-';
        };

        buttons.forEach((button) => {
          button.addEventListener('click', function () {
            setText('transferModalProgramLabel', this.getAttribute('data-program-label'));
            setText('transferModalProgramCode', this.getAttribute('data-program-code'));
            setText('transferModalStatus', this.getAttribute('data-status'));
            setText('transferModalCapacity', this.getAttribute('data-capacity'));
            setText('transferModalScored', this.getAttribute('data-scored'));
            setText('transferModalAvailable', this.getAttribute('data-available'));
            setText('transferModalCutoff', this.getAttribute('data-cutoff'));
            transferModal.show();
          });
        });
      })();
    </script>
  </body>
</html>
