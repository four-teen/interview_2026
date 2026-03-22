<?php
require_once '../config/db.php';
require_once '../config/program_assignments.php';
require_once '../config/system_controls.php';
require_once '../config/program_ranking_lock.php';
require_once '../config/student_preregistration.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    (($_SESSION['role'] ?? '') !== 'progchair') ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

function progchair_program_summary_format_datetime(?string $value, string $fallback = 'Not yet'): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return $fallback;
    }

    $timestamp = strtotime($raw);
    return ($timestamp !== false) ? date('M j, Y g:i A', $timestamp) : $raw;
}

function progchair_program_summary_format_program_label(array $row): string
{
    $programCode = trim((string) ($row['program_code'] ?? ''));
    $programName = trim((string) ($row['program_name'] ?? ''));
    $major = trim((string) ($row['major'] ?? ''));

    $label = $programName;
    if ($major !== '') {
        $label .= ' - ' . $major;
    }

    if ($programCode !== '') {
        return $programCode . ($label !== '' ? ' - ' . $label : '');
    }

    return $label !== '' ? $label : ('Program #' . (int) ($row['program_id'] ?? 0));
}

function progchair_program_summary_fetch_single_row(mysqli $conn, string $sql, string $types = '', array $params = []): ?array
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return null;
    }

    if ($types !== '') {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $row ?: null;
}

function progchair_program_summary_build_view_url(int $programId, string $view): string
{
    return 'program_summary.php?' . http_build_query([
        'program_id' => max(0, $programId),
        'view' => $view,
    ]) . '#studentListSection';
}

function progchair_program_summary_section_label(string $section): string
{
    $normalized = program_ranking_normalize_section($section);
    if ($normalized === 'scc') {
        return 'SCC';
    }
    if ($normalized === 'etg') {
        return 'ETG';
    }
    return 'REGULAR';
}

$accountId = (int) ($_SESSION['accountid'] ?? 0);
$assignedCampusId = (int) ($_SESSION['campus_id'] ?? 0);
$fallbackProgramId = (int) ($_SESSION['program_id'] ?? 0);
$selectedProgramId = (int) ($_GET['program_id'] ?? 0);
$selectedView = strtolower(trim((string) ($_GET['view'] ?? 'prereg')));
$allowedViews = ['prereg', 'locked', 'scc', 'etg', 'remaining', 'total'];
if (!in_array($selectedView, $allowedViews, true)) {
    $selectedView = 'prereg';
}

$assignedProgramIds = get_account_assigned_program_ids($conn, $accountId, $fallbackProgramId);
$allowedProgramIds = [];
foreach ($assignedProgramIds as $programIdValue) {
    $programIdValue = (int) $programIdValue;
    if ($programIdValue > 0 && is_program_login_unlocked($conn, $programIdValue)) {
        $allowedProgramIds[] = $programIdValue;
    }
}
$allowedProgramIds = normalize_program_id_list($allowedProgramIds);

$currentProgramId = (int) ($_SESSION['program_id'] ?? 0);
if (!empty($allowedProgramIds) && !in_array($currentProgramId, $allowedProgramIds, true)) {
    $currentProgramId = (int) $allowedProgramIds[0];
    $_SESSION['program_id'] = $currentProgramId;
}
$_SESSION['assigned_program_ids'] = $allowedProgramIds;

$pageError = null;
$rankingError = null;
$preRegistrationWarning = null;
$storageReady = false;

$pc_fullname = trim((string) ($_SESSION['fullname'] ?? 'Program Chair'));
$pc_role = 'Program Chair';
$pc_email = trim((string) ($_SESSION['email'] ?? ''));
$pc_campus_name = '';
$pc_college = '';
$assignedCollegeId = 0;

$profileSql = "
    SELECT
        a.acc_fullname,
        a.email,
        c.campus_name,
        p.college_id,
        co.college_name
    FROM tblaccount a
    LEFT JOIN tbl_campus c
        ON a.campus_id = c.campus_id
    LEFT JOIN tbl_program p
        ON p.program_id = ?
    LEFT JOIN tbl_college co
        ON p.college_id = co.college_id
    WHERE a.accountid = ?
    LIMIT 1
";
$profileRow = progchair_program_summary_fetch_single_row(
    $conn,
    $profileSql,
    'ii',
    [$currentProgramId, $accountId]
);

if ($profileRow) {
    $pc_fullname = trim((string) ($profileRow['acc_fullname'] ?? $pc_fullname));
    $pc_email = trim((string) ($profileRow['email'] ?? $pc_email));
    $pc_campus_name = trim((string) ($profileRow['campus_name'] ?? ''));
    $pc_college = trim((string) ($profileRow['college_name'] ?? ''));
    $assignedCollegeId = (int) ($profileRow['college_id'] ?? 0);
}

if (empty($allowedProgramIds)) {
    $pageError = 'No unlocked active program assignment is available for your account.';
} elseif ($assignedCollegeId <= 0) {
    $pageError = 'Unable to determine your assigned college for the active program.';
}

$programOptions = [];
$selectedProgram = null;
$selectedProgramLabel = '';

if ($pageError === null) {
    $programOptionSql = "
        SELECT
            p.program_id,
            p.program_code,
            p.program_name,
            p.major,
            COALESCE(pc.cutoff_score, NULL) AS cutoff_score,
            COALESCE(pc.absorptive_capacity, NULL) AS absorptive_capacity,
            COALESCE(pc.regular_percentage, NULL) AS regular_percentage,
            COALESCE(pc.etg_percentage, NULL) AS etg_percentage,
            COALESCE(pc.endorsement_capacity, 0) AS endorsement_capacity
        FROM tbl_program p
        INNER JOIN tbl_college col
            ON col.college_id = p.college_id
        LEFT JOIN tbl_program_cutoff pc
            ON pc.program_id = p.program_id
        WHERE p.status = 'active'
          AND p.college_id = ?
          AND col.campus_id = ?
        ORDER BY
            (p.program_id = ?) DESC,
            p.program_name ASC,
            p.major ASC,
            p.program_code ASC
    ";
    $stmtProgramOptions = $conn->prepare($programOptionSql);
    if (!$stmtProgramOptions) {
        $pageError = 'Failed to load college programs.';
    } else {
        $stmtProgramOptions->bind_param('iii', $assignedCollegeId, $assignedCampusId, $currentProgramId);
        $stmtProgramOptions->execute();
        $programOptionResult = $stmtProgramOptions->get_result();
        while ($programOptionRow = $programOptionResult->fetch_assoc()) {
            $programOptionRow['label'] = progchair_program_summary_format_program_label($programOptionRow);
            $programOptions[] = $programOptionRow;
        }
        $stmtProgramOptions->close();
    }

    if ($pageError === null && empty($programOptions)) {
        $pageError = 'No active programs are available under your assigned college.';
    }
}

if ($pageError === null) {
    $collegeProgramIds = array_map(static function (array $row): int {
        return (int) ($row['program_id'] ?? 0);
    }, $programOptions);

    if ($selectedProgramId <= 0 || !in_array($selectedProgramId, $collegeProgramIds, true)) {
        $selectedProgramId = $currentProgramId;
    }
    if ($selectedProgramId <= 0 || !in_array($selectedProgramId, $collegeProgramIds, true)) {
        $selectedProgramId = (int) ($programOptions[0]['program_id'] ?? 0);
    }

    foreach ($programOptions as $programOption) {
        if ((int) ($programOption['program_id'] ?? 0) === $selectedProgramId) {
            $selectedProgram = $programOption;
            $selectedProgramLabel = (string) ($programOption['label'] ?? '');
            break;
        }
    }

    if (!$selectedProgram) {
        $pageError = 'Selected program is not accessible from your college.';
    }
}

$interviewSummary = [
    'active_interviews' => 0,
    'scored_count' => 0,
    'unscored_count' => 0,
    'pending_transfers' => 0,
];
$preregistrationSummary = [
    'prereg_count' => 0,
    'prereg_locked_count' => 0,
    'profile_complete_count' => 0,
    'agreement_accepted_count' => 0,
    'latest_submitted_at' => '',
];
$rankingProgram = [];
$rankingQuota = [
    'enabled' => false,
    'cutoff_score' => null,
    'program_cutoff_score' => null,
    'global_cutoff_active' => false,
    'global_cutoff_value' => null,
    'absorptive_capacity' => null,
    'base_capacity' => null,
    'endorsement_capacity' => 0,
    'endorsement_selected' => 0,
    'regular_slots' => null,
    'regular_effective_slots' => null,
    'etg_slots' => null,
    'regular_candidates' => 0,
    'etg_candidates' => 0,
    'regular_shown' => 0,
    'endorsement_shown' => 0,
    'etg_shown' => 0,
];
$rankingLocks = [
    'active_count' => 0,
    'max_locked_rank' => 0,
    'ranges' => [],
];
$rankingRows = [];
$recentLocks = [];

$sectionStats = [
    'regular' => [
        'label' => 'Regular',
        'candidates' => 0,
        'inside_count' => 0,
        'outside_count' => 0,
        'locked_count' => 0,
        'locked_inside_count' => 0,
        'locked_outside_count' => 0,
        'configured_slots' => null,
        'priority_target' => 0,
        'remaining_to_lock' => 0,
        'available_slots' => null,
    ],
    'scc' => [
        'label' => 'SCC',
        'candidates' => 0,
        'inside_count' => 0,
        'outside_count' => 0,
        'locked_count' => 0,
        'locked_inside_count' => 0,
        'locked_outside_count' => 0,
        'configured_slots' => null,
        'priority_target' => 0,
        'remaining_to_lock' => 0,
        'available_slots' => null,
    ],
    'etg' => [
        'label' => 'ETG',
        'candidates' => 0,
        'inside_count' => 0,
        'outside_count' => 0,
        'locked_count' => 0,
        'locked_inside_count' => 0,
        'locked_outside_count' => 0,
        'configured_slots' => null,
        'priority_target' => 0,
        'remaining_to_lock' => 0,
        'available_slots' => null,
    ],
];

$qualifiedRankedCount = 0;
$outsideCapacityCount = 0;
$lockedTotalCount = 0;
$lockedInsideCount = 0;
$lockedOutsideCount = 0;
$priorityLockTargetTotal = 0;
$remainingToLockTotal = 0;
$lockProgressPercent = 0.0;
$sccStudentCount = 0;
$etgStudentCount = 0;
$latestLockAt = '';
$latestLockBy = '';
$lockRangeText = 'No locked ranks yet.';

if ($pageError === null && $selectedProgramId > 0) {
    $interviewSummarySql = "
        SELECT
            COUNT(*) AS active_interviews,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count,
            SUM(CASE WHEN si.final_score IS NULL THEN 1 ELSE 0 END) AS unscored_count
        FROM tbl_student_interview si
        WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
          AND si.status = 'active'
    ";
    $interviewRow = progchair_program_summary_fetch_single_row(
        $conn,
        $interviewSummarySql,
        'i',
        [$selectedProgramId]
    );
    if ($interviewRow) {
        $interviewSummary['active_interviews'] = max(0, (int) ($interviewRow['active_interviews'] ?? 0));
        $interviewSummary['scored_count'] = max(0, (int) ($interviewRow['scored_count'] ?? 0));
        $interviewSummary['unscored_count'] = max(0, (int) ($interviewRow['unscored_count'] ?? 0));
    }

    $pendingTransferSql = "
        SELECT COUNT(*) AS pending_transfers
        FROM tbl_student_transfer_history t
        INNER JOIN tbl_student_interview si
            ON si.interview_id = t.interview_id
        WHERE t.status = 'pending'
          AND t.to_program_id = ?
          AND si.status = 'active'
    ";
    $pendingTransferRow = progchair_program_summary_fetch_single_row(
        $conn,
        $pendingTransferSql,
        'i',
        [$selectedProgramId]
    );
    if ($pendingTransferRow) {
        $interviewSummary['pending_transfers'] = max(0, (int) ($pendingTransferRow['pending_transfers'] ?? 0));
    }

    $storageReady = ensure_student_preregistration_storage($conn);
    if (!$storageReady) {
        $preRegistrationWarning = 'Pre-registration storage is unavailable right now.';
    } else {
        $preregistrationSql = "
            SELECT
                COUNT(*) AS prereg_count,
                SUM(CASE WHEN spr.locked_rank IS NOT NULL AND spr.locked_rank > 0 THEN 1 ELSE 0 END) AS prereg_locked_count,
                SUM(CASE WHEN spr.profile_completion_percent >= 100 THEN 1 ELSE 0 END) AS profile_complete_count,
                SUM(CASE WHEN spr.agreement_accepted = 1 THEN 1 ELSE 0 END) AS agreement_accepted_count,
                MAX(spr.submitted_at) AS latest_submitted_at
            FROM tbl_student_preregistration spr
            WHERE spr.program_id = ?
              AND spr.status = 'submitted'
        ";
        $preregistrationRow = progchair_program_summary_fetch_single_row(
            $conn,
            $preregistrationSql,
            'i',
            [$selectedProgramId]
        );
        if ($preregistrationRow) {
            $preregistrationSummary['prereg_count'] = max(0, (int) ($preregistrationRow['prereg_count'] ?? 0));
            $preregistrationSummary['prereg_locked_count'] = max(0, (int) ($preregistrationRow['prereg_locked_count'] ?? 0));
            $preregistrationSummary['profile_complete_count'] = max(0, (int) ($preregistrationRow['profile_complete_count'] ?? 0));
            $preregistrationSummary['agreement_accepted_count'] = max(0, (int) ($preregistrationRow['agreement_accepted_count'] ?? 0));
            $preregistrationSummary['latest_submitted_at'] = (string) ($preregistrationRow['latest_submitted_at'] ?? '');
        }
    }

    $rankingPayload = program_ranking_fetch_payload($conn, $selectedProgramId, $assignedCampusId);
    if (!($rankingPayload['success'] ?? false)) {
        $rankingError = (string) ($rankingPayload['message'] ?? 'Failed to load ranking details for this program.');
    } else {
        $rankingProgram = is_array($rankingPayload['program'] ?? null) ? $rankingPayload['program'] : [];
        $rankingQuota = array_merge($rankingQuota, is_array($rankingPayload['quota'] ?? null) ? $rankingPayload['quota'] : []);
        $rankingLocks = array_merge($rankingLocks, is_array($rankingPayload['locks'] ?? null) ? $rankingPayload['locks'] : []);
        $rankingRows = is_array($rankingPayload['rows'] ?? null) ? $rankingPayload['rows'] : [];

        foreach ($rankingRows as $rankingRow) {
            $section = program_ranking_normalize_section((string) ($rankingRow['row_section'] ?? 'regular'));
            if (!isset($sectionStats[$section])) {
                $section = 'regular';
            }

            $sectionStats[$section]['candidates']++;
            $isOutsideCapacity = !empty($rankingRow['is_outside_capacity']);
            $isLocked = !empty($rankingRow['is_locked']);

            if ($isOutsideCapacity) {
                $sectionStats[$section]['outside_count']++;
                $outsideCapacityCount++;
            } else {
                $sectionStats[$section]['inside_count']++;
            }

            if ($isLocked) {
                $sectionStats[$section]['locked_count']++;
                $lockedTotalCount++;

                if ($isOutsideCapacity) {
                    $sectionStats[$section]['locked_outside_count']++;
                    $lockedOutsideCount++;
                } else {
                    $sectionStats[$section]['locked_inside_count']++;
                    $lockedInsideCount++;
                }
            }
        }

        $sectionStats['regular']['configured_slots'] = ($rankingQuota['enabled'] ?? false)
            ? max(0, (int) ($rankingQuota['regular_slots'] ?? 0))
            : null;
        $sectionStats['scc']['configured_slots'] = ($rankingQuota['enabled'] ?? false)
            ? max(0, (int) ($rankingQuota['endorsement_capacity'] ?? 0))
            : null;
        $sectionStats['etg']['configured_slots'] = ($rankingQuota['enabled'] ?? false)
            ? max(0, (int) ($rankingQuota['etg_slots'] ?? 0))
            : null;

        $sectionStats['regular']['priority_target'] = max(0, (int) ($rankingQuota['regular_shown'] ?? $sectionStats['regular']['inside_count']));
        $sectionStats['scc']['priority_target'] = max(0, (int) ($rankingQuota['endorsement_shown'] ?? $sectionStats['scc']['inside_count']));
        $sectionStats['etg']['priority_target'] = max(0, (int) ($rankingQuota['etg_shown'] ?? $sectionStats['etg']['inside_count']));

        foreach ($sectionStats as $sectionKey => $stats) {
            $lockedPriorityCount = min(
                max(0, (int) ($stats['locked_inside_count'] ?? 0)),
                max(0, (int) ($stats['priority_target'] ?? 0))
            );
            $sectionStats[$sectionKey]['remaining_to_lock'] = max(0, (int) ($stats['priority_target'] ?? 0) - $lockedPriorityCount);

            if (($rankingQuota['enabled'] ?? false) && $stats['configured_slots'] !== null) {
                $sectionStats[$sectionKey]['available_slots'] = max(
                    0,
                    max(0, (int) ($stats['priority_target'] ?? 0)) - max(0, (int) ($stats['inside_count'] ?? 0))
                );
            }
        }

        $qualifiedRankedCount = count($rankingRows);
        $priorityLockTargetTotal =
            (int) ($sectionStats['regular']['priority_target'] ?? 0) +
            (int) ($sectionStats['scc']['priority_target'] ?? 0) +
            (int) ($sectionStats['etg']['priority_target'] ?? 0);
        $remainingToLockTotal =
            (int) ($sectionStats['regular']['remaining_to_lock'] ?? 0) +
            (int) ($sectionStats['scc']['remaining_to_lock'] ?? 0) +
            (int) ($sectionStats['etg']['remaining_to_lock'] ?? 0);
        $lockProgressPercent = $priorityLockTargetTotal > 0
            ? round((($priorityLockTargetTotal - $remainingToLockTotal) / $priorityLockTargetTotal) * 100, 1)
            : 0.0;

        $sccStudentCount = max(
            (int) ($sectionStats['scc']['candidates'] ?? 0),
            max(0, (int) ($rankingQuota['endorsement_selected'] ?? 0))
        );
        $etgStudentCount = max(0, (int) ($sectionStats['etg']['candidates'] ?? 0));

        $lockRangeText = !empty($rankingLocks['ranges'])
            ? implode(', ', (array) $rankingLocks['ranges'])
            : 'No locked ranks yet.';

        $recentLocks = program_ranking_load_active_locks($conn, $selectedProgramId);
        usort($recentLocks, static function (array $left, array $right): int {
            $leftTime = strtotime((string) ($left['locked_at'] ?? '')) ?: 0;
            $rightTime = strtotime((string) ($right['locked_at'] ?? '')) ?: 0;
            if ($leftTime === $rightTime) {
                return (int) ($right['locked_rank'] ?? 0) <=> (int) ($left['locked_rank'] ?? 0);
            }
            return $rightTime <=> $leftTime;
        });

        if (!empty($recentLocks)) {
            $latestLockAt = (string) ($recentLocks[0]['locked_at'] ?? '');
            $latestLockBy = trim((string) ($recentLocks[0]['locked_by_name'] ?? ''));
        }

        $recentLocks = array_slice($recentLocks, 0, 8);
    }
}

$pageTitleProgram = $selectedProgramLabel !== '' ? $selectedProgramLabel : 'Program Summary';
$appliedCutoff = $rankingQuota['cutoff_score'];
$programCutoffScore = $rankingQuota['program_cutoff_score'];
$globalCutoffActive = !empty($rankingQuota['global_cutoff_active']);
$globalCutoffValue = $rankingQuota['global_cutoff_value'];
$quotaEnabled = !empty($rankingQuota['enabled']);
$absorptiveCapacity = isset($rankingQuota['absorptive_capacity']) ? (int) $rankingQuota['absorptive_capacity'] : null;
$baseCapacity = isset($rankingQuota['base_capacity']) ? (int) $rankingQuota['base_capacity'] : null;
$regularPercentage = isset($selectedProgram['regular_percentage']) && $selectedProgram['regular_percentage'] !== null
    ? (float) $selectedProgram['regular_percentage']
    : null;
$etgPercentage = isset($selectedProgram['etg_percentage']) && $selectedProgram['etg_percentage'] !== null
    ? (float) $selectedProgram['etg_percentage']
    : null;
$endorsementCapacity = max(0, (int) ($rankingQuota['endorsement_capacity'] ?? ($selectedProgram['endorsement_capacity'] ?? 0)));
$latestPreregistrationAt = (string) ($preregistrationSummary['latest_submitted_at'] ?? '');
$preregistrationPendingFromLocked = max(
    0,
    max(0, (int) $lockedInsideCount) - max(0, (int) ($preregistrationSummary['prereg_locked_count'] ?? 0))
);
$priorityLockedCount = max(0, $priorityLockTargetTotal - $remainingToLockTotal);
$etgCapacity = max(0, (int) ($sectionStats['etg']['configured_slots'] ?? 0));
$preRegisteredCardValue = number_format((int) ($preregistrationSummary['prereg_count'] ?? 0)) . ' / ' . number_format($lockedInsideCount);
$lockedCardValue = number_format($priorityLockedCount) . ' / ' . number_format($priorityLockTargetTotal);
$sccCardValue = number_format($sccStudentCount) . ' / ' . number_format($endorsementCapacity);
$etgCardValue = number_format($etgStudentCount) . ' / ' . number_format($etgCapacity);
$remainingCardValue = number_format($remainingToLockTotal) . ' / ' . number_format($priorityLockTargetTotal);
$totalRankedRecords = max($qualifiedRankedCount, (int) ($interviewSummary['scored_count'] ?? 0));
$totalStudentsCardValue = number_format($totalRankedRecords);

$studentListViews = [
    'prereg' => [
        'title' => 'Pre-Registered Students',
        'description' => 'Submitted pre-registration records for the selected program.',
    ],
    'locked' => [
        'title' => 'Locked Students',
        'description' => 'Students already locked in ranking order for the selected program.',
    ],
    'scc' => [
        'title' => 'SCC Students',
        'description' => 'Students currently assigned under SCC for the selected program.',
    ],
    'etg' => [
        'title' => 'ETG Students',
        'description' => 'Students currently ranked under the ETG section.',
    ],
    'remaining' => [
        'title' => 'Remaining To Lock',
        'description' => 'Students still inside capacity and not yet locked.',
    ],
    'total' => [
        'title' => 'Total Students',
        'description' => 'All students currently shown in the ranked program list, including SCC and ETG within the same total.',
    ],
];

$studentListRows = [];
$studentMobileMap = [];

if ($selectedProgramId > 0) {
    $mobileSql = "
        SELECT
            si.interview_id,
            si.examinee_number,
            si.mobile_number
        FROM tbl_student_interview si
        WHERE COALESCE(NULLIF(si.program_id, 0), NULLIF(si.first_choice, 0)) = ?
          AND si.status = 'active'
    ";
    $stmtMobile = $conn->prepare($mobileSql);
    if ($stmtMobile) {
        $stmtMobile->bind_param('i', $selectedProgramId);
        $stmtMobile->execute();
        $mobileResult = $stmtMobile->get_result();
        while ($mobileRow = $mobileResult->fetch_assoc()) {
            $mobile = trim((string) ($mobileRow['mobile_number'] ?? ''));
            $interviewId = (int) ($mobileRow['interview_id'] ?? 0);
            $examineeNumber = trim((string) ($mobileRow['examinee_number'] ?? ''));

            if ($interviewId > 0) {
                $studentMobileMap['interview'][$interviewId] = $mobile;
            }
            if ($examineeNumber !== '') {
                $studentMobileMap['examinee'][$examineeNumber] = $mobile;
            }
        }
        $stmtMobile->close();
    }
}

if ($storageReady && $selectedProgramId > 0) {
    $preregReport = student_preregistration_fetch_report($conn, [
        'program_id' => $selectedProgramId,
        'limit' => 2000,
    ]);
    foreach ((array) ($preregReport['rows'] ?? []) as $row) {
        $classification = strtoupper(trim((string) ($row['classification'] ?? 'REGULAR')));
        $mobileNumber = trim((string) ($studentMobileMap['interview'][(int) ($row['interview_id'] ?? 0)] ?? ''));
        if ($mobileNumber === '') {
            $mobileNumber = trim((string) ($studentMobileMap['examinee'][(string) ($row['examinee_number'] ?? '')] ?? ''));
        }
        $studentListRows['prereg'][] = [
            'full_name' => (string) ($row['full_name'] ?? 'N/A'),
            'examinee_number' => (string) ($row['examinee_number'] ?? '--'),
            'category' => ($classification === 'ETG') ? 'ETG' : 'REGULAR',
            'rank' => ((int) ($row['locked_rank'] ?? 0) > 0) ? ('#' . number_format((int) $row['locked_rank'])) : 'Not locked',
            'score' => ($row['final_score'] !== null && $row['final_score'] !== '') ? number_format((float) $row['final_score'], 2) : 'N/A',
            'status' => 'Submitted',
            'date' => progchair_program_summary_format_datetime((string) ($row['submitted_at'] ?? ''), '--'),
            'active_mobile' => $mobileNumber !== '' ? $mobileNumber : 'N/A',
        ];
    }
}

if ($selectedProgramId > 0) {
    foreach ($rankingRows as $row) {
        $section = program_ranking_normalize_section((string) ($row['row_section'] ?? 'regular'));
        $sectionLabel = progchair_program_summary_section_label($section);
        $isLocked = !empty($row['is_locked']);
        $isOutsideCapacity = !empty($row['is_outside_capacity']);
        $rankLabel = $isLocked && (int) ($row['locked_rank'] ?? 0) > 0
            ? ('#' . number_format((int) $row['locked_rank']))
            : ('#' . number_format((int) ($row['rank'] ?? 0)));
        $scoreValue = ($row['final_score'] ?? '') !== '' ? number_format((float) $row['final_score'], 2) : 'N/A';
        $mobileNumber = trim((string) ($studentMobileMap['interview'][(int) ($row['interview_id'] ?? 0)] ?? ''));
        if ($mobileNumber === '') {
            $mobileNumber = trim((string) ($studentMobileMap['examinee'][(string) ($row['examinee_number'] ?? '')] ?? ''));
        }
        $baseRow = [
            'full_name' => (string) ($row['full_name'] ?? 'N/A'),
            'examinee_number' => (string) ($row['examinee_number'] ?? '--'),
            'category' => $sectionLabel,
            'rank' => $rankLabel,
            'score' => $scoreValue,
            'status' => $isLocked ? 'Locked' : 'Open',
            'date' => progchair_program_summary_format_datetime(
                $isLocked ? (string) ($row['locked_at'] ?? '') : (string) ($row['interview_datetime'] ?? ''),
                '--'
            ),
            'active_mobile' => $mobileNumber !== '' ? $mobileNumber : 'N/A',
        ];

        if ($isLocked) {
            $studentListRows['locked'][] = $baseRow;
        }

        if ($section === 'scc') {
            $studentListRows['scc'][] = array_merge($baseRow, [
                'status' => $isLocked ? 'SCC Locked' : 'Assigned SCC',
            ]);
        }

        if ($section === 'etg') {
            $studentListRows['etg'][] = array_merge($baseRow, [
                'status' => $isLocked ? 'ETG Locked' : 'Assigned ETG',
                'category' => (string) ($row['classification'] ?? $sectionLabel),
            ]);
        }

        if (!$isLocked && !$isOutsideCapacity) {
            $studentListRows['remaining'][] = array_merge($baseRow, ['status' => 'Waiting for lock']);
        }

        $studentListRows['total'][] = $baseRow;
    }
}

foreach ($allowedViews as $viewKey) {
    if (!isset($studentListRows[$viewKey]) || !is_array($studentListRows[$viewKey])) {
        $studentListRows[$viewKey] = [];
    }
}

$activeListTitle = (string) ($studentListViews[$selectedView]['title'] ?? 'Students');
$activeListDescription = (string) ($studentListViews[$selectedView]['description'] ?? '');
$activeListRows = $studentListRows[$selectedView] ?? [];
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
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />
    <title>Program Summary - <?= htmlspecialchars($pageTitleProgram); ?></title>

    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
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
      .pss-filter-card,
      .pss-summary-card,
      .pss-detail-card {
        border: 1px solid #e8edf5;
        border-radius: 1rem;
        box-shadow: 0 10px 24px rgba(15, 23, 42, 0.04);
      }

      .pss-summary-link {
        display: block;
        height: 100%;
        color: inherit;
        text-decoration: none;
      }

      .pss-summary-link:hover {
        color: inherit;
      }

      .pss-summary-link .pss-summary-card {
        transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
      }

      .pss-summary-link:hover .pss-summary-card {
        transform: translateY(-2px);
        border-color: #c9d6ea;
        box-shadow: 0 14px 28px rgba(15, 23, 42, 0.08);
      }

      .pss-summary-link.is-active .pss-summary-card {
        border-color: #3b82f6;
        box-shadow: 0 14px 28px rgba(37, 99, 235, 0.12);
      }

      .pss-filter-card .card-body,
      .pss-summary-card .card-body,
      .pss-detail-card .card-body {
        padding: 1.1rem 1.15rem;
      }

      .pss-filter-label {
        display: block;
        margin-bottom: 0.45rem;
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #7a8699;
      }

      .pss-page-subtitle {
        max-width: 880px;
      }

      .pss-summary-card {
        height: 100%;
        background: linear-gradient(180deg, #ffffff 0%, #fbfcff 100%);
      }

      .pss-summary-top {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 0.85rem;
        margin-bottom: 0.8rem;
      }

      .pss-summary-icon {
        width: 42px;
        height: 42px;
        border-radius: 14px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 1.2rem;
      }

      .pss-summary-label {
        font-size: 0.76rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: #7a8699;
      }

      .pss-summary-value {
        margin: 0;
        font-size: 1.9rem;
        line-height: 1.05;
        font-weight: 700;
        color: #23314d;
      }

      .pss-summary-note {
        color: #7a8699;
        font-size: 0.82rem;
        line-height: 1.35;
      }

      .pss-detail-title {
        font-size: 0.95rem;
        font-weight: 700;
        color: #23314d;
      }

      .pss-detail-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        gap: 0.95rem;
      }

      .pss-detail-item {
        border: 1px solid #edf1f7;
        border-radius: 0.9rem;
        padding: 0.85rem 0.9rem;
        background: #fcfdff;
      }

      .pss-detail-item-label {
        display: block;
        margin-bottom: 0.32rem;
        font-size: 0.73rem;
        font-weight: 700;
        letter-spacing: 0.04em;
        text-transform: uppercase;
        color: #8391a7;
      }

      .pss-detail-item-value {
        font-size: 1rem;
        font-weight: 700;
        color: #22314b;
      }

      .pss-detail-item-sub {
        display: block;
        margin-top: 0.2rem;
        color: #7a8699;
        font-size: 0.78rem;
      }

      .pss-progress-shell {
        width: 100%;
        height: 11px;
        overflow: hidden;
        border-radius: 999px;
        background: #edf2fb;
      }

      .pss-progress-bar {
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #3b82f6 0%, #2563eb 100%);
      }

      .pss-breakdown-table th,
      .pss-breakdown-table td,
      .pss-compact-table th,
      .pss-compact-table td {
        vertical-align: middle;
      }

      .pss-highlight-badge {
        display: inline-flex;
        align-items: center;
        gap: 0.3rem;
        padding: 0.42rem 0.68rem;
        border-radius: 999px;
        background: #eef4ff;
        color: #2454c6;
        font-weight: 700;
        font-size: 0.82rem;
      }

      .pss-meta-list {
        display: grid;
        gap: 0.8rem;
      }

      .pss-meta-line {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
        padding-bottom: 0.7rem;
        border-bottom: 1px solid #edf1f7;
      }

      .pss-meta-line:last-child {
        padding-bottom: 0;
        border-bottom: 0;
      }

      .pss-meta-key {
        color: #7a8699;
        font-size: 0.82rem;
      }

      .pss-meta-value {
        text-align: right;
        color: #22314b;
        font-size: 0.9rem;
        font-weight: 600;
      }

      .pss-empty-state {
        border: 1px dashed #c9d4e5;
        border-radius: 0.95rem;
        padding: 1rem;
        color: #66768d;
        background: #fbfcfe;
      }

      @media (max-width: 767.98px) {
        .pss-summary-value {
          font-size: 1.65rem;
        }

        .pss-meta-line {
          flex-direction: column;
          gap: 0.3rem;
        }

        .pss-meta-value {
          text-align: left;
        }
      }
    </style>
  </head>
  <body>
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">
        <?php include 'sidebar.php'; ?>

        <div class="layout-page">
          <?php include 'header.php'; ?>

          <div class="content-wrapper">
            <div class="container-xxl flex-grow-1 container-p-y">
              <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-4">
                <div>
                  <h4 class="fw-bold mb-1">
                    <span class="text-muted fw-light">Program Chair /</span> Program Summary
                  </h4>
                  <p class="text-muted mb-0 pss-page-subtitle">
                    One-stop program view for pre-registration, ranking locks, SCC and ETG counts, scoring progress,
                    and remaining seat actions under your current college.
                  </p>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <a href="index.php" class="btn btn-label-secondary">
                    <i class="bx bx-arrow-back me-1"></i> Dashboard
                  </a>
                </div>
              </div>

              <?php if ($pageError !== null): ?>
                <div class="alert alert-danger" role="alert">
                  <?= htmlspecialchars($pageError); ?>
                </div>
              <?php else: ?>
                <div class="card pss-filter-card mb-4">
                  <div class="card-body">
                    <div class="row g-3 align-items-end">
                      <div class="col-lg-7">
                        <label class="pss-filter-label" for="programSummarySelect">Select Program</label>
                        <form method="get" action="program_summary.php">
                          <select
                            id="programSummarySelect"
                            class="form-select"
                            name="program_id"
                            onchange="this.form.submit()"
                          >
                            <?php foreach ($programOptions as $programOption): ?>
                              <?php $optionProgramId = (int) ($programOption['program_id'] ?? 0); ?>
                              <option
                                value="<?= $optionProgramId; ?>"
                                <?= $optionProgramId === $selectedProgramId ? 'selected' : ''; ?>
                              >
                                <?= htmlspecialchars((string) ($programOption['label'] ?? ('Program #' . $optionProgramId))); ?>
                              </option>
                            <?php endforeach; ?>
                          </select>
                          <noscript>
                            <button type="submit" class="btn btn-primary btn-sm mt-2">Apply</button>
                          </noscript>
                        </form>
                      </div>
                      <div class="col-lg-5">
                        <div class="pss-meta-list">
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">College Scope</div>
                            <div class="pss-meta-value">
                              <?= htmlspecialchars($pc_college !== '' ? $pc_college : 'Assigned College'); ?>
                            </div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Campus</div>
                            <div class="pss-meta-value">
                              <?= htmlspecialchars($pc_campus_name !== '' ? $pc_campus_name : 'Assigned Campus'); ?>
                            </div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <?php if ($rankingError !== null): ?>
                  <div class="alert alert-warning" role="alert">
                    <?= htmlspecialchars($rankingError); ?>
                  </div>
                <?php endif; ?>

                <?php if ($preRegistrationWarning !== null): ?>
                  <div class="alert alert-warning" role="alert">
                    <?= htmlspecialchars($preRegistrationWarning); ?>
                  </div>
                <?php endif; ?>

                <div class="row g-4 mb-4">
                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'prereg')); ?>"
                      class="pss-summary-link<?= $selectedView === 'prereg' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">Pre-Registered</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($preRegisteredCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-primary">
                              <i class="bx bx-user-check"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Submitted pre-registration / inside-capacity locked students
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>

                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'locked')); ?>"
                      class="pss-summary-link<?= $selectedView === 'locked' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">Locked Students</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($lockedCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-warning">
                              <i class="bx bx-lock-alt"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Priority locked / total available to lock
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>

                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'scc')); ?>"
                      class="pss-summary-link<?= $selectedView === 'scc' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">SCC Students</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($sccCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-success">
                              <i class="bx bx-badge-check"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Assigned SCC / SCC capacity
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>

                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'etg')); ?>"
                      class="pss-summary-link<?= $selectedView === 'etg' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">ETG Students</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($etgCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-info">
                              <i class="bx bx-group"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Assigned ETG / ETG capacity
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>

                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'remaining')); ?>"
                      class="pss-summary-link<?= $selectedView === 'remaining' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">Remaining To Lock</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($remainingCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-danger">
                              <i class="bx bx-list-check"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Remaining to lock / total available based on capacity
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>

                  <div class="col-md-6 col-xl-2">
                    <a
                      href="<?= htmlspecialchars(progchair_program_summary_build_view_url($selectedProgramId, 'total')); ?>"
                      class="pss-summary-link<?= $selectedView === 'total' ? ' is-active' : ''; ?>"
                    >
                      <div class="card pss-summary-card">
                        <div class="card-body">
                          <div class="pss-summary-top">
                            <div>
                              <div class="pss-summary-label">Total Students</div>
                              <p class="pss-summary-value"><?= htmlspecialchars($totalStudentsCardValue); ?></p>
                            </div>
                            <span class="pss-summary-icon bg-label-secondary">
                              <i class="bx bx-list-ul"></i>
                            </span>
                          </div>
                          <div class="pss-summary-note">
                            Actual total students from the ranked list
                          </div>
                        </div>
                      </div>
                    </a>
                  </div>
                </div>

                <div class="card pss-detail-card mb-4" id="studentListSection">
                  <div class="card-body">
                    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                      <div>
                        <div class="pss-detail-title"><?= htmlspecialchars($activeListTitle); ?></div>
                        <small class="text-muted"><?= htmlspecialchars($activeListDescription); ?></small>
                      </div>
                      <span class="badge bg-label-primary"><?= number_format(count($activeListRows)); ?> student<?= count($activeListRows) === 1 ? '' : 's'; ?></span>
                    </div>

                    <?php if (empty($activeListRows)): ?>
                      <div class="pss-empty-state">
                        No students found for this selection.
                      </div>
                    <?php else: ?>
                      <div class="table-responsive">
                        <table class="table table-hover pss-compact-table mb-0">
                          <thead class="table-light">
                            <tr>
                              <th>Student</th>
                              <th style="width: 120px;">Examinee #</th>
                              <th style="width: 140px;">Category</th>
                              <th style="width: 120px;">Rank</th>
                              <th style="width: 110px;">Score</th>
                              <th style="width: 140px;">Status</th>
                              <th style="width: 170px;">Date</th>
                              <th>Active Mobile</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($activeListRows as $listRow): ?>
                              <tr>
                                <td class="fw-semibold"><?= htmlspecialchars((string) ($listRow['full_name'] ?? 'N/A')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['examinee_number'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['category'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['rank'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['score'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['status'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['date'] ?? '--')); ?></td>
                                <td><?= htmlspecialchars((string) ($listRow['active_mobile'] ?? 'N/A')); ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="row g-4 mb-4">
                  <div class="col-lg-7">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                          <div class="pss-detail-title">Program Details</div>
                          <span class="pss-highlight-badge">
                            <i class="bx bx-buildings"></i>
                            <?= htmlspecialchars($selectedProgramLabel !== '' ? $selectedProgramLabel : 'Selected Program'); ?>
                          </span>
                        </div>
                        <div class="pss-detail-grid">
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">College</span>
                            <div class="pss-detail-item-value"><?= htmlspecialchars($pc_college !== '' ? $pc_college : 'Not available'); ?></div>
                            <span class="pss-detail-item-sub">Program filter is limited to this college.</span>
                          </div>
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">Campus</span>
                            <div class="pss-detail-item-value"><?= htmlspecialchars($pc_campus_name !== '' ? $pc_campus_name : 'Not available'); ?></div>
                            <span class="pss-detail-item-sub">Same campus scope as your active assignment.</span>
                          </div>
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">Applied Cutoff</span>
                            <div class="pss-detail-item-value">
                              <?= $appliedCutoff !== null ? number_format((int) $appliedCutoff) : 'Not set'; ?>
                            </div>
                            <span class="pss-detail-item-sub">
                              <?= $globalCutoffActive
                                ? 'Global cutoff is active' . ($globalCutoffValue !== null ? ': ' . number_format((int) $globalCutoffValue) : '')
                                : (($programCutoffScore !== null) ? 'Program cutoff in effect' : 'No cutoff configured'); ?>
                            </span>
                          </div>
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">Absorptive Capacity</span>
                            <div class="pss-detail-item-value">
                              <?= $absorptiveCapacity !== null ? number_format($absorptiveCapacity) : 'Not set'; ?>
                            </div>
                            <span class="pss-detail-item-sub">
                              <?= $baseCapacity !== null ? 'Base capacity before live rollover: ' . number_format($baseCapacity) : 'Capacity distribution not configured yet'; ?>
                            </span>
                          </div>
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">Regular / ETG Split</span>
                            <div class="pss-detail-item-value">
                              <?php if ($regularPercentage !== null && $etgPercentage !== null): ?>
                                <?= number_format($regularPercentage, 2); ?>% / <?= number_format($etgPercentage, 2); ?>%
                              <?php else: ?>
                                Not set
                              <?php endif; ?>
                            </div>
                            <span class="pss-detail-item-sub">
                              <?= $quotaEnabled ? 'Quota rules are active for this program.' : 'Quota rules are not fully configured.'; ?>
                            </span>
                          </div>
                          <div class="pss-detail-item">
                            <span class="pss-detail-item-label">Latest Activity</span>
                            <div class="pss-detail-item-value">
                              <?= htmlspecialchars(progchair_program_summary_format_datetime($latestLockAt, 'No lock activity')); ?>
                            </div>
                            <span class="pss-detail-item-sub">
                              <?= htmlspecialchars($latestLockBy !== '' ? 'Locked by ' . $latestLockBy : 'No locked rank recorded yet.'); ?>
                            </span>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-lg-5">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                          <div class="pss-detail-title">Lock Progress</div>
                          <span class="badge bg-label-primary">
                            <?= number_format($priorityLockTargetTotal); ?> target
                          </span>
                        </div>

                        <div class="mb-2 d-flex justify-content-between align-items-center gap-2">
                          <span class="text-muted small">Priority lock completion</span>
                          <span class="fw-semibold text-primary"><?= number_format($lockProgressPercent, 1); ?>%</span>
                        </div>
                        <div class="pss-progress-shell mb-3">
                          <div
                            class="pss-progress-bar"
                            style="width: <?= max(0, min(100, (float) $lockProgressPercent)); ?>%;"
                          ></div>
                        </div>

                        <div class="pss-meta-list">
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Ranked records</div>
                            <div class="pss-meta-value"><?= number_format($totalRankedRecords); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Locked inside target</div>
                            <div class="pss-meta-value"><?= number_format($lockedInsideCount); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Remaining seats to lock</div>
                            <div class="pss-meta-value"><?= number_format($remainingToLockTotal); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Outside-capacity ranked</div>
                            <div class="pss-meta-value"><?= number_format($outsideCapacityCount); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Pending transfer requests</div>
                            <div class="pss-meta-value"><?= number_format((int) ($interviewSummary['pending_transfers'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Locked rank ranges</div>
                            <div class="pss-meta-value"><?= htmlspecialchars($lockRangeText); ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-4 mb-4">
                  <div class="col-lg-8">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                          <div class="pss-detail-title">Capacity And Lock Breakdown</div>
                          <span class="badge bg-label-info">
                            Live ranking target shown for REGULAR / SCC / ETG
                          </span>
                        </div>

                        <div class="table-responsive">
                          <table class="table table-hover pss-breakdown-table mb-0">
                            <thead class="table-light">
                              <tr>
                                <th>Category</th>
                                <th class="text-center">Qualified / Selected</th>
                                <th class="text-center">Base Slots</th>
                                <th class="text-center">Live Target</th>
                                <th class="text-center">Locked</th>
                                <th class="text-center">Remaining To Lock</th>
                                <th class="text-center">Open Live Seats</th>
                              </tr>
                            </thead>
                            <tbody>
                              <?php foreach ($sectionStats as $sectionKey => $stats): ?>
                                <tr>
                                  <td>
                                    <div class="fw-semibold"><?= htmlspecialchars((string) ($stats['label'] ?? strtoupper($sectionKey))); ?></div>
                                    <small class="text-muted">
                                      Outside capacity: <?= number_format((int) ($stats['outside_count'] ?? 0)); ?>
                                    </small>
                                  </td>
                                  <td class="text-center fw-semibold">
                                    <?= number_format((int) ($stats['candidates'] ?? 0)); ?>
                                  </td>
                                  <td class="text-center">
                                    <?= $stats['configured_slots'] !== null ? number_format((int) $stats['configured_slots']) : 'N/A'; ?>
                                  </td>
                                  <td class="text-center">
                                    <?= number_format((int) ($stats['priority_target'] ?? 0)); ?>
                                  </td>
                                  <td class="text-center">
                                    <?= number_format((int) ($stats['locked_inside_count'] ?? 0)); ?>
                                  </td>
                                  <td class="text-center text-danger fw-semibold">
                                    <?= number_format((int) ($stats['remaining_to_lock'] ?? 0)); ?>
                                  </td>
                                  <td class="text-center text-primary fw-semibold">
                                    <?= $stats['available_slots'] !== null ? number_format((int) $stats['available_slots']) : 'N/A'; ?>
                                  </td>
                                </tr>
                              <?php endforeach; ?>
                            </tbody>
                          </table>
                        </div>
                      </div>
                    </div>
                  </div>

                  <div class="col-lg-4">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="pss-detail-title mb-3">Operational Snapshot</div>
                        <div class="pss-meta-list">
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Scored interviews</div>
                            <div class="pss-meta-value"><?= number_format((int) ($interviewSummary['scored_count'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Unscored interviews</div>
                            <div class="pss-meta-value"><?= number_format((int) ($interviewSummary['unscored_count'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Submitted pre-registrations</div>
                            <div class="pss-meta-value"><?= number_format((int) ($preregistrationSummary['prereg_count'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Profiles at 100%</div>
                            <div class="pss-meta-value"><?= number_format((int) ($preregistrationSummary['profile_complete_count'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Agreement accepted</div>
                            <div class="pss-meta-value"><?= number_format((int) ($preregistrationSummary['agreement_accepted_count'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Locked but not pre-registered</div>
                            <div class="pss-meta-value"><?= number_format($preregistrationPendingFromLocked); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Latest pre-registration</div>
                            <div class="pss-meta-value"><?= htmlspecialchars(progchair_program_summary_format_datetime($latestPreregistrationAt, 'No submission yet')); ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="row g-4">
                  <div class="col-lg-8">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                          <div class="pss-detail-title">Recent Locked Students</div>
                          <span class="badge bg-label-warning"><?= number_format((int) ($rankingLocks['active_count'] ?? 0)); ?> total locked</span>
                        </div>

                        <?php if (empty($recentLocks)): ?>
                          <div class="pss-empty-state">
                            No locked students recorded for this program yet.
                          </div>
                        <?php else: ?>
                          <div class="table-responsive">
                            <table class="table table-sm table-hover pss-compact-table mb-0">
                              <thead class="table-light">
                                <tr>
                                  <th style="width: 78px;">Rank</th>
                                  <th>Student</th>
                                  <th style="width: 110px;">Category</th>
                                  <th style="width: 170px;">Locked At</th>
                                  <th style="width: 170px;">Locked By</th>
                                </tr>
                              </thead>
                              <tbody>
                                <?php foreach ($recentLocks as $lockRow): ?>
                                  <?php
                                    $lockSection = program_ranking_normalize_section((string) ($lockRow['snapshot_section'] ?? 'regular'));
                                    $lockSectionLabel = strtoupper($lockSection === 'scc' ? 'SCC' : $lockSection);
                                  ?>
                                  <tr>
                                    <td class="fw-semibold">#<?= number_format((int) ($lockRow['locked_rank'] ?? 0)); ?></td>
                                    <td>
                                      <div class="fw-semibold">
                                        <?= htmlspecialchars((string) ($lockRow['snapshot_full_name'] ?? 'N/A')); ?>
                                      </div>
                                      <small class="text-muted">
                                        Examinee #: <?= htmlspecialchars((string) ($lockRow['snapshot_examinee_number'] ?? '--')); ?>
                                      </small>
                                    </td>
                                    <td><?= htmlspecialchars($lockSectionLabel); ?></td>
                                    <td><?= htmlspecialchars(progchair_program_summary_format_datetime((string) ($lockRow['locked_at'] ?? ''), '--')); ?></td>
                                    <td><?= htmlspecialchars((string) ($lockRow['locked_by_name'] ?? 'N/A')); ?></td>
                                  </tr>
                                <?php endforeach; ?>
                              </tbody>
                            </table>
                          </div>
                        <?php endif; ?>
                      </div>
                    </div>
                  </div>

                  <div class="col-lg-4">
                    <div class="card pss-detail-card h-100">
                      <div class="card-body">
                        <div class="pss-detail-title mb-3">Chair Notes</div>
                        <div class="pss-meta-list">
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Regular live seats open</div>
                            <div class="pss-meta-value">
                              <?= $sectionStats['regular']['available_slots'] !== null ? number_format((int) $sectionStats['regular']['available_slots']) : 'N/A'; ?>
                            </div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">SCC live seats open</div>
                            <div class="pss-meta-value">
                              <?= $sectionStats['scc']['available_slots'] !== null ? number_format((int) $sectionStats['scc']['available_slots']) : 'N/A'; ?>
                            </div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">ETG live seats open</div>
                            <div class="pss-meta-value">
                              <?= $sectionStats['etg']['available_slots'] !== null ? number_format((int) $sectionStats['etg']['available_slots']) : 'N/A'; ?>
                            </div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Regular remaining to lock</div>
                            <div class="pss-meta-value"><?= number_format((int) ($sectionStats['regular']['remaining_to_lock'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">SCC remaining to lock</div>
                            <div class="pss-meta-value"><?= number_format((int) ($sectionStats['scc']['remaining_to_lock'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">ETG remaining to lock</div>
                            <div class="pss-meta-value"><?= number_format((int) ($sectionStats['etg']['remaining_to_lock'] ?? 0)); ?></div>
                          </div>
                          <div class="pss-meta-line">
                            <div class="pss-meta-key">Maximum locked rank</div>
                            <div class="pss-meta-value"><?= number_format((int) ($rankingLocks['max_locked_rank'] ?? 0)); ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <?php include '../footer.php'; ?>
            <div class="content-backdrop fade"></div>
          </div>
        </div>
      </div>

      <div class="layout-overlay layout-menu-toggle"></div>
    </div>

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      (function () {
        const navbarSearchInput = document.querySelector('#layout-navbar input[aria-label="Search..."]');
        if (navbarSearchInput) {
          navbarSearchInput.placeholder = 'Use the program selector to change summary scope...';
          navbarSearchInput.setAttribute('aria-label', 'Use the program selector to change summary scope...');
          navbarSearchInput.readOnly = true;
        }
      })();
    </script>
  </body>
</html>
