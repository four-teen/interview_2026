<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/interview_scores.php
 * PURPOSE: Interview Scoring Page
 *
 * PHASE 2:
 *   ??? Load SAT automatically from placement
 *   ??? Lock SAT input
 *   ??? Load saved scores from tbl_interview_scores
 *
 * PHASE 3:
 *   ??? Save/Update scores to tbl_interview_scores (server-side compute)
 *   ??? Only OWNER (encoder) can save/edit
 * ============================================================================
 */

require_once '../config/db.php';
require_once '../config/score_receipt_security.php';
session_start();

// ======================================================
// GUARD
// ======================================================
if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId   = (int) $_SESSION['accountid'];
$interviewId = isset($_GET['interview_id']) ? (int) $_GET['interview_id'] : 0;

if ($interviewId <= 0) {
    header('Location: index.php');
    exit;
}

// ======================================================
// 1) GET INTERVIEW + PLACEMENT SAT (AUTO LOAD)
//   tbl_student_interview.placement_result_id -> tbl_placement_results.id
//   also fetch program_chair_id (OWNER CHECK)
// ======================================================
$satScoreFromPlacement = 0;
$placementResultId     = 0;
$studentName           = '';
$examineeNumber        = '';
$ownerProgramChairId   = 0;
$firstChoiceCourse     = '';
$displayLastName       = '';
$displayOtherNames     = '';
$studentClassification = 'REGULAR';
$isEtgStudent          = false;
$savedFinalScore       = null;

$baseSql = "
    SELECT 
        si.interview_id,
        si.placement_result_id,
        si.program_chair_id,
        si.first_choice,
        si.classification,
        si.final_score,
        pr.sat_score,
        pr.full_name,
        pr.examinee_number,
        p.program_name AS first_choice_program_name,
        p.major AS first_choice_major
    FROM tbl_student_interview si
    INNER JOIN tbl_placement_results pr
        ON pr.id = si.placement_result_id
    LEFT JOIN tbl_program p
        ON p.program_id = si.first_choice
    WHERE si.interview_id = ?
    LIMIT 1
";

$stmtBase = $conn->prepare($baseSql);
if (!$stmtBase) {
    error_log("Prepare failed (baseSql): " . $conn->error);
    header('Location: index.php?error=1');
    exit;
}
$stmtBase->bind_param("i", $interviewId);
$stmtBase->execute();
$resBase = $stmtBase->get_result();

if ($resBase->num_rows === 0) {
    header('Location: index.php');
    exit;
}

$baseRow = $resBase->fetch_assoc();
$placementResultId     = (int) $baseRow['placement_result_id'];
$ownerProgramChairId   = (int) $baseRow['program_chair_id'];
$satScoreFromPlacement = (float) $baseRow['sat_score'];
$studentName           = $baseRow['full_name'];
$examineeNumber        = $baseRow['examinee_number'];
$studentClassification = strtoupper(trim((string) ($baseRow['classification'] ?? 'REGULAR')));
$studentClassification = (strpos($studentClassification, 'ETG') === 0) ? 'ETG' : 'REGULAR';
$isEtgStudent = ($studentClassification === 'ETG');
$savedFinalScore = ($baseRow['final_score'] !== null) ? (float) $baseRow['final_score'] : null;

$firstChoiceCourse = trim((string)($baseRow['first_choice_program_name'] ?? ''));
$firstChoiceMajor  = trim((string)($baseRow['first_choice_major'] ?? ''));
if ($firstChoiceCourse !== '' && $firstChoiceMajor !== '') {
    $firstChoiceCourse .= ' - ' . $firstChoiceMajor;
}

$normalizedStudentName = trim((string)$studentName);
if ($normalizedStudentName !== '' && strpos($normalizedStudentName, ',') !== false) {
    [$ln, $rest] = array_map('trim', explode(',', $normalizedStudentName, 2));
    $displayLastName = strtoupper($ln);
    $displayOtherNames = strtolower($rest);
} else {
    $displayOtherNames = strtolower($normalizedStudentName);
}

$isOwner = ($ownerProgramChairId === $accountId);

function normalize_component_key(string $componentName): string
{
    $normalized = strtoupper(trim($componentName));
    return preg_replace('/[^A-Z0-9]+/', '', $normalized) ?? '';
}

function is_sat_component(string $componentName): bool
{
    return normalize_component_key($componentName) === 'SAT';
}

function get_effective_component_weight(string $componentName, float $defaultWeight, bool $isEtgStudent): float
{
    if (!$isEtgStudent) {
        return $defaultWeight;
    }

    $key = normalize_component_key($componentName);
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

function prepare_components_for_student(array $components, bool $isEtgStudent): array
{
    $prepared = [];
    foreach ($components as $component) {
        $effectiveWeight = get_effective_component_weight(
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

// ======================================================
// 2) LOAD COMPONENTS (DYNAMIC) + APPLY CLASS PROFILE
// ======================================================
$components = [];

$componentSql = "
    SELECT component_id, component_name, max_score, weight_percent, is_auto_computed, status
    FROM tbl_scoring_components
    WHERE status = 'ACTIVE'
    ORDER BY component_id ASC
";

$componentResult = $conn->query($componentSql);
if (!$componentResult) {
    error_log("Component Query Error: " . $conn->error);
    header('Location: index.php?error=1');
    exit;
}

while ($row = $componentResult->fetch_assoc()) {
    $components[] = $row;
}

$components = prepare_components_for_student($components, $isEtgStudent);

// ======================================================
// PHASE 3) SAVE SCORES
// NOTE: must be AFTER base query so we know owner + SAT
// ======================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_scores'])) {
  $totalFinalScore = 0;

    // OWNER ONLY
    if (!$isOwner) {
        header("Location: interview_scores.php?interview_id={$interviewId}&forbidden=1");
        exit;
    }

    if (!isset($_POST['raw_score']) || !is_array($_POST['raw_score'])) {
        header("Location: interview_scores.php?interview_id={$interviewId}&error=1");
        exit;
    }

    $componentsById = [];
    foreach ($components as $component) {
        $componentId = (int) ($component['component_id'] ?? 0);
        if ($componentId > 0) {
            $componentsById[$componentId] = $component;
        }
    }

    // optional: transaction for safety
    $conn->begin_transaction();

    try {

        foreach ($_POST['raw_score'] as $componentId => $rawScore) {

            $componentId = (int) $componentId;

            // sanitize numeric
            $rawScore = trim((string)$rawScore);
            $rawScore = ($rawScore === '') ? 0 : (float)$rawScore;
            if (!isset($componentsById[$componentId])) {
                continue;
            }

            $component = $componentsById[$componentId];
            $maxScore = (float) $component['max_score'];
            $weight   = (float) ($component['effective_weight_percent'] ?? $component['weight_percent']);

            $isAuto = ((int)$component['is_auto_computed'] === 1) || is_sat_component((string) ($component['component_name'] ?? ''));

            // enforce SAT from placement (cannot be overridden)
            if ($isAuto) {
                $rawScore = $satScoreFromPlacement;
            }

            if ($maxScore <= 0) {
                continue;
            }

            // trap invalid scores (server-side)
            if (!$isAuto && $rawScore > $maxScore) {
                throw new Exception('RAW_SCORE_EXCEEDS_MAX');
            }
            if (!$isAuto && $rawScore < 0) {
                $rawScore = 0;
            }

            // SERVER-SIDE COMPUTATION
            $weightedScore = ($rawScore / $maxScore) * $weight;
            $totalFinalScore += $weightedScore;

// ==========================================
// CHECK IF SCORE EXISTS + GET OLD VALUES
// ==========================================
$oldRaw = null;
$oldWeighted = null;

$checkSql = "
    SELECT score_id, raw_score, weighted_score
    FROM tbl_interview_scores
    WHERE interview_id = ?
    AND component_id = ?
    LIMIT 1
";

$stmtCheck = $conn->prepare($checkSql);
if (!$stmtCheck) {
    throw new Exception("Prepare failed (checkSql): " . $conn->error);
}

$stmtCheck->bind_param("ii", $interviewId, $componentId);
$stmtCheck->execute();
$checkResult = $stmtCheck->get_result();

$rowExisting = $checkResult->fetch_assoc();

if ($rowExisting) {
    $oldRaw = (float)$rowExisting['raw_score'];
    $oldWeighted = (float)$rowExisting['weighted_score'];
}




            if ($checkResult->num_rows > 0) {

                // UPDATE
                $updateSql = "
                    UPDATE tbl_interview_scores
                    SET raw_score = ?, weighted_score = ?
                    WHERE interview_id = ?
                    AND component_id = ?
                ";

                $stmtUpdate = $conn->prepare($updateSql);
                if (!$stmtUpdate) {
                    throw new Exception("Prepare failed (updateSql): " . $conn->error);
                }

                $stmtUpdate->bind_param("ddii", $rawScore, $weightedScore, $interviewId, $componentId);
                $stmtUpdate->execute();


//loging audit
$action = ($oldRaw === null) ? 'SCORE_SAVE' : 'SCORE_UPDATE';

$auditSql = "
  INSERT INTO tbl_score_audit_logs
  (interview_id, component_id, actor_accountid, action, old_raw, new_raw, old_weighted, new_weighted, ip_address, user_agent)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$stmtAudit = $conn->prepare($auditSql);

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$stmtAudit->bind_param(
  "iiissdddss",
  $interviewId,
  $componentId,
  $accountId,
  $action,
  $oldRaw,
  $rawScore,
  $oldWeighted,
  $weightedScore,
  $ip,
  $ua
);
$stmtAudit->execute();


            } else {

                // INSERT
                $insertSql = "
                    INSERT INTO tbl_interview_scores
                    (interview_id, component_id, raw_score, weighted_score)
                    VALUES (?, ?, ?, ?)
                ";

                $stmtInsert = $conn->prepare($insertSql);
                if (!$stmtInsert) {
                    throw new Exception("Prepare failed (insertSql): " . $conn->error);
                }

                $stmtInsert->bind_param("iidd", $interviewId, $componentId, $rawScore, $weightedScore);
                $stmtInsert->execute();

//audit loging
$action = ($oldRaw === null) ? 'SCORE_SAVE' : 'SCORE_UPDATE';

$auditSql = "
  INSERT INTO tbl_score_audit_logs
  (interview_id, component_id, actor_accountid, action, old_raw, new_raw, old_weighted, new_weighted, ip_address, user_agent)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
";
$stmtAudit = $conn->prepare($auditSql);

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$stmtAudit->bind_param(
  "iiissdddss",
  $interviewId,
  $componentId,
  $accountId,
  $action,
  $oldRaw,
  $rawScore,
  $oldWeighted,
  $weightedScore,
  $ip,
  $ua
);
$stmtAudit->execute();



            }
        }

        // ETG has fixed affirmative action rating: 100/100 * 15% = 15.
        if ($isEtgStudent) {
            $totalFinalScore += 15.0;
        }

// ==========================================
// UPDATE FINAL SCORE IN tbl_student_interview
// ==========================================
$updateFinalSql = "
    UPDATE tbl_student_interview
    SET final_score = ?
    WHERE interview_id = ?
";

$stmtFinal = $conn->prepare($updateFinalSql);
$stmtFinal->bind_param("di", $totalFinalScore, $interviewId);

//start loging audit
$finalBefore = null;
$getFinalSql = "SELECT final_score FROM tbl_student_interview WHERE interview_id = ? LIMIT 1";
$stmtGetFinal = $conn->prepare($getFinalSql);
$stmtGetFinal->bind_param("i", $interviewId);
$stmtGetFinal->execute();
$resGetFinal = $stmtGetFinal->get_result();
if ($r = $resGetFinal->fetch_assoc()) {
    $finalBefore = $r['final_score'] !== null ? (float)$r['final_score'] : null;
}
//end of loging audit

$stmtFinal->execute();
$auditSql2 = "
  INSERT INTO tbl_score_audit_logs
  (interview_id, component_id, actor_accountid, action, final_before, final_after, ip_address, user_agent)
  VALUES (?, NULL, ?, 'FINAL_SCORE_UPDATE', ?, ?, ?, ?)
";
$stmtAudit2 = $conn->prepare($auditSql2);

$ip = $_SERVER['REMOTE_ADDR'] ?? null;
$ua = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255);

$stmtAudit2->bind_param(
  "iiddss",
  $interviewId,
  $accountId,
  $finalBefore,
  $totalFinalScore,
  $ip,
  $ua
);
$stmtAudit2->execute();

//loging audit

        $conn->commit();

        // Redirect (Swal will run after page loads)
        header("Location: interview_scores.php?interview_id={$interviewId}&saved=1");
        exit;

    } catch (Exception $e) {

        $conn->rollback();
        error_log("Save failed: " . $e->getMessage());
        if ($e->getMessage() === 'RAW_SCORE_EXCEEDS_MAX') {
            header("Location: interview_scores.php?interview_id={$interviewId}&invalid_score=1");
        } else {
            header("Location: interview_scores.php?interview_id={$interviewId}&error=1");
        }
        exit;
    }
}

// ======================================================
// 3) LOAD SAVED SCORES (IF ANY) -> keyed by component_id
// ======================================================
$savedScores = [];

$savedSql = "
    SELECT component_id, raw_score, weighted_score
    FROM tbl_interview_scores
    WHERE interview_id = ?
";
$stmtSaved = $conn->prepare($savedSql);
if (!$stmtSaved) {
    error_log("Prepare failed (savedSql): " . $conn->error);
    header('Location: index.php?error=1');
    exit;
}
$stmtSaved->bind_param("i", $interviewId);
$stmtSaved->execute();
$resSaved = $stmtSaved->get_result();

while ($row = $resSaved->fetch_assoc()) {
    $cid = (int) $row['component_id'];
    $savedScores[$cid] = [
        'raw_score'      => (float) $row['raw_score'],
        'weighted_score' => (float) $row['weighted_score'],
    ];
}

// ======================================================
// 4) BUILD INITIAL TOTALS (SERVER SIDE DISPLAY)
// ======================================================
$totalWeight = 0.0;
$finalScore  = 0.0;

foreach ($components as $c) {
    $weight = (float) ($c['effective_weight_percent'] ?? $c['weight_percent']);
    $max    = (float) $c['max_score'];
    $cid    = (int) $c['component_id'];

    $totalWeight += $weight;

    $raw = 0.0;

    $isAuto = ((int)$c['is_auto_computed'] === 1) || is_sat_component((string) ($c['component_name'] ?? ''));

    if ($isAuto) {
        $raw = $satScoreFromPlacement;
    } elseif (isset($savedScores[$cid])) {
        $raw = (float) $savedScores[$cid]['raw_score'];
    }

    if ($max > 0) {
        $finalScore += (($raw / $max) * $weight);
    }
}

if ($isEtgStudent) {
    $totalWeight += 15.0;
    $finalScore += 15.0;
}

// ======================================================
// 5) BUILD SIGNED RECEIPT VERIFICATION LINK
// ======================================================
$printedByName = trim((string) (
    $_SESSION['acc_fullname']
    ?? $_SESSION['account_name']
    ?? $_SESSION['username']
    ?? 'Program Chair'
));
$snapshotFinalScoreForReceipt = ($savedFinalScore !== null) ? $savedFinalScore : $finalScore;
$canPrintSignedResult = ($savedFinalScore !== null);

$scoreReceiptPayload = [
    'v' => '1',
    'id' => (string) $interviewId,
    'ex' => (string) $examineeNumber,
    'fs' => number_format((float) $snapshotFinalScoreForReceipt, 2, '.', ''),
    'cl' => (string) $studentClassification,
    'iat' => gmdate('Y-m-d\TH:i:s\Z'),
];
$scoreReceiptPayload['sig'] = score_receipt_sign($scoreReceiptPayload);

$requestScheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$requestHost = $_SERVER['HTTP_HOST'] ?? 'localhost';
$verifyScoreUrlBase = rtrim($requestScheme . '://' . $requestHost, '/') . BASE_URL . '/progchair/verify_score_receipt.php';
$verifyScoreUrl = $verifyScoreUrlBase . '?' . http_build_query($scoreReceiptPayload);

// ======================================================
// 6) LOAD QUESTION POOL (tblquestions)
// ======================================================
$questionPool = [];
$questionSql = "SELECT questions FROM tblquestions ORDER BY questionsid ASC";
$questionRes = $conn->query($questionSql);
if ($questionRes instanceof mysqli_result) {
    while ($q = $questionRes->fetch_assoc()) {
        $text = trim((string)($q['questions'] ?? ''));
        if ($text !== '') {
            $questionPool[] = $text;
        }
    }
} else {
    error_log("Question Query Error: " . $conn->error);
}

// ======================================================
// 7) LOAD READING IMAGE POOL (../readings)
// ======================================================
$readingPool = [];
$readingFiles = glob(__DIR__ . '/../readings/*.{png,jpg,jpeg,gif,webp,PNG,JPG,JPEG,GIF,WEBP}', GLOB_BRACE);
if (is_array($readingFiles)) {
    foreach ($readingFiles as $file) {
        $readingPool[] = '../readings/' . rawurlencode(basename($file));
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="light-style layout-menu-fixed" dir="ltr">
<head>
  <meta charset="utf-8" />
  <title>Interview Scoring</title>
  <meta name="description" content="" />

  <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap" rel="stylesheet" />

  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" class="template-customizer-core-css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
  <style>
    .student-name-title {
      font-size: 2rem;
      line-height: 1.15;
      font-weight: 700;
      margin-bottom: .35rem;
    }
    .student-lastname {
      color: #b65e00;
      letter-spacing: 0.4px;
    }
    .student-othername {
      color: #2f55d4;
      margin-left: .35rem;
      text-transform: lowercase;
    }
    .student-course-line {
      font-weight: 600;
      color: #566a7f;
    }
    .guide-group-title {
      display: flex;
      justify-content: space-between;
      align-items: center;
      font-weight: 700;
      border-top: 1px solid #e7e7e7;
      margin-top: .75rem;
      padding-top: .75rem;
    }
    .guide-item {
      display: flex;
      justify-content: space-between;
      color: #566a7f;
      padding: .2rem 0;
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

          <!-- HEADER CARD -->
          <div class="card mb-4">
            <div class="card-body">
              <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                  <h3 class="student-name-title">
                    <?php if ($displayLastName !== ''): ?>
                      <span class="student-lastname"><?= htmlspecialchars($displayLastName); ?></span>
                    <?php endif; ?>
                    <span class="student-othername"><?= htmlspecialchars($displayOtherNames); ?></span>
                  </h3>
                  <small class="text-muted d-block">
                    Examinee #: <?= htmlspecialchars($examineeNumber); ?>
                  </small>
                  <small class="student-course-line d-block">
                    First Choice: <?= htmlspecialchars($firstChoiceCourse !== '' ? strtoupper($firstChoiceCourse) : 'N/A'); ?>
                  </small>
                </div>

                <a href="index.php" class="btn btn-label-secondary btn-sm btn-primary">
                  <i class="bx bx-arrow-back me-1"></i> Back to List
                </a>
              </div>
            </div>
          </div>

          <div class="row g-4">
            <div class="col-xxl-8">
              <!-- SCORE COMPONENTS -->
              <div class="card">
                <div class="card-header">
                  <h6 class="mb-0">Score Components<?= $isEtgStudent ? ' (ETG Profile)' : ' (Regular Profile)'; ?></h6>
                </div>

                <div class="card-body">

                  <!-- IMPORTANT: method="POST" for Phase 3 -->
                  <form id="scoreForm" method="POST" autocomplete="off">

                    <div class="table-responsive">
                      <table class="table table-bordered align-middle text-center">
                        <thead class="table-light">
                          <tr>
                            <th class="text-start">Component</th>
                            <th>Raw Score</th>
                            <th>Max Score</th>
                            <th>Weight (%)</th>
                            <th>Weighted Result</th>
                          </tr>
                        </thead>

                        <tbody>
                        <?php foreach ($components as $component): ?>
                          <?php
                            $cid    = (int) $component['component_id'];
                            $name   = $component['component_name'];
                            $max    = (float) $component['max_score'];
                            $weight = (float) ($component['effective_weight_percent'] ?? $component['weight_percent']);

                            $isAuto = ((int)$component['is_auto_computed'] === 1) || is_sat_component((string) $name);

                            // raw score value
                            if ($isAuto) {
                                $rawValue = $satScoreFromPlacement;
                            } else {
                                $rawValue = isset($savedScores[$cid]) ? (float)$savedScores[$cid]['raw_score'] : '';
                            }

                            // initial weighted
                            $weightedValue = 0.0;
                            if ($max > 0 && $rawValue !== '') {
                                $weightedValue = ((float)$rawValue / $max) * $weight;
                            }
                          ?>
                          <tr
                            data-max="<?= $max; ?>"
                            data-weight="<?= $weight; ?>"
                            data-component="<?= htmlspecialchars($name, ENT_QUOTES); ?>"
                          >
                            <td class="text-start"><?= htmlspecialchars($name); ?></td>

                            <td>
                              <input
                                type="number"
                                step="0.01"
                                class="form-control raw-input"
                                name="raw_score[<?= $cid; ?>]"
                                value="<?= ($rawValue === '' ? '' : htmlspecialchars((string)$rawValue)); ?>"
                                min="0"
                                max="<?= htmlspecialchars((string)$max); ?>"
                                <?= $isAuto ? 'readonly' : ''; ?>
                                <?= (!$isOwner && !$isAuto) ? 'readonly' : ''; ?>
                              >
                            </td>

                            <td>
                              <input type="number" class="form-control" value="<?= htmlspecialchars((string)$max); ?>" readonly>
                            </td>

                            <td>
                              <input type="number" class="form-control" value="<?= htmlspecialchars((string)$weight); ?>" readonly>
                            </td>

                            <td class="weighted-result"><?= number_format($weightedValue, 2); ?></td>
                          </tr>
                        <?php endforeach; ?>
                        <?php if ($isEtgStudent): ?>
                          <tr
                            data-max="100"
                            data-weight="15"
                            data-component="Affirmative Action Rating"
                          >
                            <td class="text-start">
                              Affirmative Action Rating
                              <span class="badge bg-label-info ms-1">Fixed</span>
                            </td>
                            <td>
                              <input
                                type="number"
                                step="0.01"
                                class="form-control raw-input"
                                value="100"
                                min="100"
                                max="100"
                                readonly
                              >
                            </td>
                            <td>
                              <input type="number" class="form-control" value="100" readonly>
                            </td>
                            <td>
                              <input type="number" class="form-control" value="15" readonly>
                            </td>
                            <td class="weighted-result">15.00</td>
                          </tr>
                        <?php endif; ?>
                        </tbody>
                      </table>
                    </div>

                    <div class="mt-3 d-flex justify-content-between flex-wrap gap-2">
                      <div>
                        <strong>Total Weight:</strong>
                        <span id="totalWeight" class="text-primary"><?= number_format($totalWeight, 2); ?>%</span>
                      </div>

                      <div>
                        <strong>Final Score:</strong>
                        <span id="finalScore" class="text-success"><?= number_format($finalScore, 2); ?>%</span>
                      </div>
                    </div>

                    <div class="mt-4 d-flex justify-content-end gap-2 flex-wrap">
                      <button
                        type="button"
                        id="printResultBtn"
                        class="btn btn-outline-secondary"
                        <?= $canPrintSignedResult ? '' : 'disabled'; ?>
                      >
                        <i class="bx bx-printer me-1"></i> Print Result
                      </button>
                      <button
                        type="submit"
                        name="save_scores"
                        class="btn btn-success"
                        <?= $isOwner ? '' : 'disabled'; ?>
                      >
                        Save Scores
                      </button>
                    </div>

                    <?php if (!$canPrintSignedResult): ?>
                      <div class="mt-2 text-end">
                        <small class="text-muted">
                          Save scores first to generate a signed printable result with QR verification.
                        </small>
                      </div>
                    <?php endif; ?>

                    <?php if (!$isOwner): ?>
                      <div class="mt-2 text-end">
                        <small class="text-muted">
                          You can view only. Only the encoder can update scores.
                        </small>
                      </div>
                    <?php endif; ?>

                  </form>

                </div>
              </div>

              <!-- INTERVIEW PROCESS -->
              <div class="card mt-4">
                <div class="card-header">
                  <h6 class="mb-0">Interview Process</h6>
                </div>
                <div class="card-body">
                  <ol class="ps-3 mb-3">
                    <li class="mb-2">Ask the student to introduce herself/himself.</li>
                    <li class="mb-2">Choose at least two general questions.</li>
                    <li class="mb-0">Ask at least two program-centered questions based on intended enrollment.</li>
                  </ol>

                  <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm" id="showRandomQuestionsBtn">
                      <i class="bx bx-shuffle me-1"></i> Select Random Questions
                    </button>
                    <button type="button" class="btn btn-outline-info btn-sm" id="showRandomReadingBtn">
                      <i class="bx bx-book-open me-1"></i> Get Suggested Text Reading
                    </button>
                  </div>
                </div>
              </div>
            </div>

            <!-- INTERVIEW SCORING GUIDE -->
            <div class="col-xxl-4">
              <div class="card h-100">
                <div class="card-header">
                  <h6 class="mb-0">Interview Scoring Guidelines</h6>
                </div>
                <div class="card-body">
                  <p class="mb-3">
                    Interview rating shall be based on the percentage score below using a set of guided questions in the criteria:
                  </p>

                  <div class="guide-group-title"><span>Delivery</span><span>30%</span></div>
                  <div class="guide-item"><span>Manner of answering the question</span><span>20%</span></div>
                  <div class="guide-item"><span>Pronunciation</span><span>5%</span></div>
                  <div class="guide-item"><span>Diction and articulation</span><span>5%</span></div>

                  <div class="guide-group-title"><span>Personality</span><span>30%</span></div>
                  <div class="guide-item"><span>Gesture</span><span>10%</span></div>
                  <div class="guide-item"><span>Bearing / Poise / Confidence</span><span>10%</span></div>
                  <div class="guide-item"><span>Values and attitudes shown</span><span>10%</span></div>

                  <div class="guide-group-title"><span>Knowledge</span><span>40%</span></div>
                  <div class="guide-item"><span>Content / Idea</span><span>20%</span></div>
                  <div class="guide-item"><span>Organization</span><span>10%</span></div>
                  <div class="guide-item"><span>Aptitude</span><span>10%</span></div>
                </div>
              </div>
            </div>
          </div>

        </div>
      </div>

    </div>
  </div>
</div>

<!-- Core JS -->
<script src="../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../assets/vendor/libs/popper/popper.js"></script>
<script src="../assets/vendor/js/bootstrap.js"></script>
<script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../assets/vendor/js/menu.js"></script>
<script src="../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script src="https://cdn.jsdelivr.net/npm/qrcodejs@1.0.0/qrcode.min.js"></script>

<script>
const questionPool = <?= json_encode(array_values($questionPool), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const readingPool  = <?= json_encode(array_values($readingPool), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const scoreReceiptMeta = <?= json_encode([
  'verify_url' => $verifyScoreUrl,
  'issued_at' => (string) ($scoreReceiptPayload['iat'] ?? ''),
  'issued_by' => $printedByName,
  'interview_id' => $interviewId,
  'examinee_number' => $examineeNumber,
  'student_name' => $studentName,
  'classification' => $studentClassification,
  'program_name' => $firstChoiceCourse,
  'can_print_signed' => $canPrintSignedResult,
  'snapshot_total_weight' => number_format((float) $totalWeight, 2, '.', ''),
  'snapshot_final_score' => number_format((float) $snapshotFinalScoreForReceipt, 2, '.', ''),
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function pickRandomItems(arr, count) {
  const copy = [...arr];
  for (let i = copy.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [copy[i], copy[j]] = [copy[j], copy[i]];
  }
  return copy.slice(0, count);
}

async function buildQrDataUrl(text) {
  if (!text || typeof QRCode === 'undefined') {
    return '';
  }

  return new Promise((resolve) => {
    const qrHost = document.createElement('div');
    qrHost.style.position = 'fixed';
    qrHost.style.left = '-99999px';
    qrHost.style.top = '-99999px';
    document.body.appendChild(qrHost);

    new QRCode(qrHost, {
      text: String(text),
      width: 160,
      height: 160,
      correctLevel: QRCode.CorrectLevel.M
    });

    setTimeout(() => {
      let dataUrl = '';
      const canvas = qrHost.querySelector('canvas');
      const img = qrHost.querySelector('img');
      if (canvas && typeof canvas.toDataURL === 'function') {
        dataUrl = canvas.toDataURL('image/png');
      } else if (img && typeof img.src === 'string') {
        dataUrl = img.src;
      }
      qrHost.remove();
      resolve(dataUrl);
    }, 100);
  });
}

function collectPrintableScoreRows(useDefaultValues = false) {
  return Array.from(document.querySelectorAll('#scoreForm tbody tr[data-max]')).map((row) => {
    const componentCell = row.querySelector('td.text-start');
    const componentName = componentCell ? componentCell.textContent.trim().replace(/\s+/g, ' ') : '';
    const rawInput = row.querySelector('.raw-input');
    const rawSource = useDefaultValues ? rawInput?.defaultValue : rawInput?.value;
    const rawValue = rawInput ? String(rawSource || '').trim() : '';
    const maxValue = String(row.getAttribute('data-max') || '').trim();
    const weightValue = String(row.getAttribute('data-weight') || '').trim();

    const rawNumber = Number.parseFloat(rawValue || '0');
    const maxNumber = Number.parseFloat(maxValue || '0');
    const weightNumber = Number.parseFloat(weightValue || '0');
    const weightedNumber = (maxNumber > 0 && Number.isFinite(rawNumber))
      ? ((rawNumber / maxNumber) * weightNumber)
      : 0;
    const weightedValue = weightedNumber.toFixed(2);

    return {
      component: componentName,
      raw: rawValue === '' ? '0' : rawValue,
      max: maxValue,
      weight: weightValue,
      weighted: weightedValue
    };
  });
}

function buildPrintableRowsHtml(rows) {
  if (!rows.length) {
    return '<tr><td colspan="5">No score components found.</td></tr>';
  }

  return rows.map((row) => `
    <tr>
      <td>${escapeHtml(row.component)}</td>
      <td>${escapeHtml(row.raw)}</td>
      <td>${escapeHtml(row.max)}</td>
      <td>${escapeHtml(row.weight)}</td>
      <td>${escapeHtml(row.weighted)}</td>
    </tr>
  `).join('');
}

function hasUnsavedScoreChanges() {
  return Array.from(document.querySelectorAll('#scoreForm .raw-input')).some((input) => {
    if (!input) return false;
    return String(input.value ?? '').trim() !== String(input.defaultValue ?? '').trim();
  });
}

async function printInterviewScoreResult() {
  const finalScoreText = `${String(scoreReceiptMeta.snapshot_final_score || '0.00')}%`;
  const totalWeightText = `${String(scoreReceiptMeta.snapshot_total_weight || '0.00')}%`;
  const profileLabel = scoreReceiptMeta.classification === 'ETG' ? 'ETG Profile' : 'Regular Profile';
  const printedAt = new Date().toLocaleString();
  const rowsHtml = buildPrintableRowsHtml(collectPrintableScoreRows(true));
  const qrDataUrl = await buildQrDataUrl(scoreReceiptMeta.verify_url);

  const printWindow = window.open('', '_blank');
  if (!printWindow) {
    Swal.fire('Blocked', 'Please allow pop-ups to print this score result.', 'warning');
    return;
  }

  const qrHtml = qrDataUrl
    ? `<img src="${qrDataUrl}" alt="Verification QR" style="width:150px;height:150px;">`
    : '<div style="font-size:12px;color:#6b7280;">QR could not be generated. Use the verification URL below.</div>';

  printWindow.document.write(`
    <!DOCTYPE html>
    <html>
    <head>
      <meta charset="utf-8">
      <title>Interview Score Result - ${escapeHtml(scoreReceiptMeta.examinee_number || '')}</title>
      <style>
        @page { size: A4 portrait; margin: 14mm; }
        body { font-family: Arial, sans-serif; color: #111827; font-size: 12px; }
        .header { margin-bottom: 12px; }
        .title { margin: 0; font-size: 20px; font-weight: 700; }
        .meta { margin-top: 4px; color: #4b5563; }
        .grid { display: grid; grid-template-columns: 1fr 180px; gap: 16px; align-items: start; margin-bottom: 12px; }
        .kv { margin: 2px 0; }
        .qr-box { border: 1px solid #d1d5db; padding: 8px; text-align: center; border-radius: 6px; }
        .qr-label { margin-top: 6px; font-size: 11px; color: #374151; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 6px 8px; text-align: left; }
        th { background: #f3f4f6; font-weight: 700; }
        td:nth-child(n+2), th:nth-child(n+2) { text-align: right; }
        .summary { margin-top: 10px; display: flex; justify-content: space-between; gap: 12px; font-weight: 700; }
        .verify-url { margin-top: 8px; font-size: 11px; color: #374151; word-break: break-all; }
        .disclaimer { margin-top: 14px; border-top: 1px solid #d1d5db; padding-top: 8px; font-size: 11px; color: #6b7280; text-align: center; letter-spacing: .04em; }
      </style>
    </head>
    <body>
      <div class="header">
        <h1 class="title">Interview Score Result</h1>
        <div class="meta">Printed: ${escapeHtml(printedAt)} | Printed by: ${escapeHtml(scoreReceiptMeta.issued_by || 'Program Chair')}</div>
      </div>

      <div class="grid">
        <div>
          <div class="kv"><strong>Interview ID:</strong> ${escapeHtml(String(scoreReceiptMeta.interview_id || ''))}</div>
          <div class="kv"><strong>Examinee #:</strong> ${escapeHtml(scoreReceiptMeta.examinee_number || '')}</div>
          <div class="kv"><strong>Student Name:</strong> ${escapeHtml(scoreReceiptMeta.student_name || '')}</div>
          <div class="kv"><strong>Classification:</strong> ${escapeHtml(scoreReceiptMeta.classification || '')}</div>
          <div class="kv"><strong>Program:</strong> ${escapeHtml(String(scoreReceiptMeta.program_name || '').toUpperCase())}</div>
          <div class="kv"><strong>Scoring Profile:</strong> ${escapeHtml(profileLabel)}</div>
        </div>
        <div class="qr-box">
          ${qrHtml}
          <div class="qr-label">Scan to verify authenticity</div>
        </div>
      </div>

      <table>
        <thead>
          <tr>
            <th>Component</th>
            <th>Raw</th>
            <th>Max</th>
            <th>Weight (%)</th>
            <th>Weighted</th>
          </tr>
        </thead>
        <tbody>${rowsHtml}</tbody>
      </table>

      <div class="summary">
        <div>Total Weight: ${escapeHtml(totalWeightText)}</div>
        <div>Final Score: ${escapeHtml(finalScoreText)}</div>
      </div>

      <div class="verify-url"><strong>Verification URL:</strong> ${escapeHtml(scoreReceiptMeta.verify_url || '')}</div>
      <div class="disclaimer">SIGNED INTERVIEW SCORE RECEIPT</div>
    </body>
    </html>
  `);

  printWindow.document.close();
  printWindow.focus();
  printWindow.print();
}

function computeScores() {
  let totalWeight = 0;
  let finalScore  = 0;

  document.querySelectorAll('#scoreForm tbody tr[data-max]').forEach(row => {
    const max    = parseFloat(row.getAttribute('data-max') || '0');
    const weight = parseFloat(row.getAttribute('data-weight') || '0');
    const rawEl  = row.querySelector('.raw-input');
    let raw      = rawEl ? parseFloat(rawEl.value || '0') : 0;
    const outEl  = row.querySelector('.weighted-result');

    totalWeight += weight;

    if (Number.isNaN(raw)) raw = 0;
    if (raw < 0) raw = 0;
    if (max > 0 && raw > max) raw = max;

    let weighted = 0;
    if (max > 0) weighted = (raw / max) * weight;

    finalScore += weighted;
    if (outEl) outEl.innerText = weighted.toFixed(2);
  });

  document.getElementById('totalWeight').innerText = totalWeight.toFixed(2) + '%';
  document.getElementById('finalScore').innerText  = finalScore.toFixed(2) + '%';
}

// live compute
document.addEventListener('input', function(e) {
  if (e.target.classList.contains('raw-input')) {
    const max = parseFloat(e.target.getAttribute('max') || '0');
    let value = parseFloat(e.target.value || '0');

    if (!Number.isNaN(value) && value < 0) {
      e.target.value = 0;
    }
    if (!Number.isNaN(value) && max > 0 && value > max) {
      e.target.value = max;
    }
    computeScores();
  }
});

// pre-submit guard
const scoreFormEl = document.getElementById('scoreForm');
if (scoreFormEl) {
  scoreFormEl.addEventListener('submit', function(e) {
    let invalidComponent = '';
    let invalidMax = '';

    document.querySelectorAll('#scoreForm tbody tr[data-max]').forEach((row) => {
      if (invalidComponent) return;
      const max = parseFloat(row.getAttribute('data-max') || '0');
      const name = row.getAttribute('data-component') || 'Component';
      const rawEl = row.querySelector('.raw-input');
      if (!rawEl || rawEl.hasAttribute('readonly')) return;

      const raw = parseFloat(rawEl.value || '0');
      if (!Number.isNaN(raw) && max > 0 && raw > max) {
        invalidComponent = name;
        invalidMax = max;
      }
    });

    if (invalidComponent) {
      e.preventDefault();
      Swal.fire({
        icon: 'error',
        title: 'Invalid Score',
        text: `${invalidComponent} cannot exceed ${invalidMax}.`,
        confirmButtonText: 'Close',
        allowOutsideClick: false,
        allowEscapeKey: false
      });
      return;
    }
  });
}

const printResultBtn = document.getElementById('printResultBtn');
if (printResultBtn) {
  printResultBtn.addEventListener('click', async function () {
    if (!scoreReceiptMeta.can_print_signed) {
      Swal.fire('Save Required', 'Save scores first to generate a signed printable result.', 'info');
      return;
    }

    if (hasUnsavedScoreChanges()) {
      const decision = await Swal.fire({
        icon: 'warning',
        title: 'Unsaved Changes Detected',
        text: 'Print uses the last saved signed snapshot. Save scores first if you want updated values.',
        showCancelButton: true,
        confirmButtonText: 'Print Saved Snapshot',
        cancelButtonText: 'Cancel'
      });
      if (!decision.isConfirmed) {
        return;
      }
    }

    printInterviewScoreResult().catch((err) => {
      console.error(err);
      Swal.fire('Error', 'Failed to prepare printable result.', 'error');
    });
  });
}

// random question picker
const showRandomQuestionsBtn = document.getElementById('showRandomQuestionsBtn');
if (showRandomQuestionsBtn) {
  showRandomQuestionsBtn.addEventListener('click', function() {
    if (!Array.isArray(questionPool) || questionPool.length === 0) {
      Swal.fire('No Questions', 'No questions found in tblquestions.', 'info');
      return;
    }

    const selected = pickRandomItems(questionPool, Math.min(2, questionPool.length));
    const html = `<ol class="text-start ps-3 mb-0">${selected.map(q => `<li>${escapeHtml(q)}</li>`).join('')}</ol>`;

    Swal.fire({
      icon: 'info',
      title: 'Suggested General Questions',
      html,
      confirmButtonText: 'Close',
      showCloseButton: true,
      allowOutsideClick: false,
      allowEscapeKey: false
    });
  });
}

// random reading picker
const showRandomReadingBtn = document.getElementById('showRandomReadingBtn');
if (showRandomReadingBtn) {
  showRandomReadingBtn.addEventListener('click', function() {
    if (!Array.isArray(readingPool) || readingPool.length === 0) {
      Swal.fire('No Reading Image', 'No reading images found in /readings.', 'info');
      return;
    }

    const selected = pickRandomItems(readingPool, 1)[0];
    Swal.fire({
      title: 'Suggested Text Reading',
      html: `<img src="${selected}" alt="Suggested Reading" class="img-fluid rounded border">`,
      width: 900,
      confirmButtonText: 'Close',
      showCloseButton: true,
      allowOutsideClick: false,
      allowEscapeKey: false
    });
  });
}

// initial compute
computeScores();

// Swal messages (after scripts loaded)
<?php if (isset($_GET['saved']) && $_GET['saved'] == '1'): ?>
Swal.fire({
  icon: 'success',
  title: 'Scores Saved Successfully!',
  confirmButtonColor: '#71dd37'
});
<?php endif; ?>

<?php if (isset($_GET['forbidden']) && $_GET['forbidden'] == '1'): ?>
Swal.fire({
  icon: 'error',
  title: 'Not Allowed',
  text: 'Only the encoder can update scores.'
});
<?php endif; ?>

<?php if (isset($_GET['invalid_score']) && $_GET['invalid_score'] == '1'): ?>
Swal.fire({
  icon: 'error',
  title: 'Invalid Score',
  text: 'One or more scores exceeded the allowed maximum.',
  confirmButtonText: 'Close',
  allowOutsideClick: false,
  allowEscapeKey: false
});
<?php endif; ?>
</script>

</body>
</html>
