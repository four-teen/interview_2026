<?php
require_once 'bootstrap.php';
guidance_require_access();

$guidanceHeaderTitle = 'Guidance Office - Student Information';
$flash = guidance_pull_flash();

$search = trim((string) ($_GET['q'] ?? ''));
$basisFilter = strtolower(trim((string) ($_GET['basis'] ?? 'all')));
$scoreStatusFilter = strtolower(trim((string) ($_GET['score_status'] ?? 'all')));
$preferredProgramFilter = trim((string) ($_GET['preferred_program_filter'] ?? ''));
$highlightEditedId = max(0, (int) ($_GET['edited_id'] ?? 0));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 15;

if (!in_array($basisFilter, ['all', 'esm', 'overall'], true)) {
    $basisFilter = 'all';
}

if (!in_array($scoreStatusFilter, ['all', 'scored', 'not_scored'], true)) {
    $scoreStatusFilter = 'all';
}

if (strlen($preferredProgramFilter) > 255) {
    $preferredProgramFilter = substr($preferredProgramFilter, 0, 255);
}

$activeBatchId = guidance_get_active_batch_id($conn);
$summary = [
    'total_students' => 0,
    'esm_students' => 0,
    'overall_students' => 0,
    'scored_students' => 0
];
$students = [];
$totalStudents = 0;
$totalPages = 1;
$preferredProgramOptions = [];
$preferredProgramFilterFound = false;
$editMarkerEnabled = guidance_ensure_student_edit_marks_table($conn);
$editMarkSelectSql = "0 AS guidance_edit_count, NULL AS guidance_last_edited_at, '' AS guidance_last_edited_by";
$editMarkJoinSql = '';
if ($editMarkerEnabled) {
    $editMarkSelectSql = "
            COALESCE(gem.edit_count, 0) AS guidance_edit_count,
            gem.last_edited_at AS guidance_last_edited_at,
            COALESCE(geditor.acc_fullname, '') AS guidance_last_edited_by
    ";
    $editMarkJoinSql = "
        LEFT JOIN tbl_guidance_student_edit_marks gem
            ON gem.placement_result_id = pr.id
           AND gem.upload_batch_id = pr.upload_batch_id
        LEFT JOIN tblaccount geditor
            ON geditor.accountid = gem.last_edited_by
    ";
}

function guidance_format_datetime_display(?string $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        $date = new DateTime($raw);
        return $date->format('M d, Y h:i A');
    } catch (Throwable $e) {
        return $raw;
    }
}

$interviewJoinSql = "
    LEFT JOIN (
        SELECT
            placement_result_id,
            MAX(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS has_interview,
            MAX(CASE WHEN status = 'active' AND final_score IS NOT NULL THEN 1 ELSE 0 END) AS has_score,
            MAX(CASE WHEN status = 'active' THEN final_score ELSE NULL END) AS final_score
        FROM tbl_student_interview
        GROUP BY placement_result_id
    ) ix
        ON ix.placement_result_id = pr.id
";

if ($activeBatchId !== null) {
    $esmConditionSql = guidance_build_esm_preferred_program_condition_sql('pr.preferred_program');

    $preferredProgramSql = "
        SELECT
            TRIM(pr.preferred_program) AS preferred_program_name,
            COUNT(*) AS total_students
        FROM tbl_placement_results pr
        WHERE pr.upload_batch_id = ?
          AND pr.preferred_program IS NOT NULL
          AND TRIM(pr.preferred_program) <> ''
        GROUP BY TRIM(pr.preferred_program)
        ORDER BY TRIM(pr.preferred_program) ASC
    ";
    $stmtPreferredPrograms = $conn->prepare($preferredProgramSql);
    if ($stmtPreferredPrograms) {
        $stmtPreferredPrograms->bind_param('s', $activeBatchId);
        $stmtPreferredPrograms->execute();
        $preferredProgramResult = $stmtPreferredPrograms->get_result();
        while ($preferredProgramResult && $preferredProgramRow = $preferredProgramResult->fetch_assoc()) {
            $programName = trim((string) ($preferredProgramRow['preferred_program_name'] ?? ''));
            if ($programName === '') {
                continue;
            }

            if (!$preferredProgramFilterFound && strcasecmp($programName, $preferredProgramFilter) === 0) {
                $preferredProgramFilter = $programName;
                $preferredProgramFilterFound = true;
            }

            $preferredProgramOptions[] = [
                'name' => $programName,
                'count' => (int) ($preferredProgramRow['total_students'] ?? 0)
            ];
        }
        $stmtPreferredPrograms->close();
    }

    $summarySql = "
        SELECT
            COUNT(*) AS total_students,
            SUM(CASE WHEN {$esmConditionSql} THEN 1 ELSE 0 END) AS esm_students,
            SUM(CASE WHEN {$esmConditionSql} THEN 0 ELSE 1 END) AS overall_students,
            SUM(CASE WHEN COALESCE(ix.has_score, 0) = 1 THEN 1 ELSE 0 END) AS scored_students
        FROM tbl_placement_results pr
        {$interviewJoinSql}
        WHERE pr.upload_batch_id = ?
    ";
    $stmtSummary = $conn->prepare($summarySql);
    if ($stmtSummary) {
        $stmtSummary->bind_param('s', $activeBatchId);
        $stmtSummary->execute();
        $summaryResult = $stmtSummary->get_result();
        if ($summaryResult && $summaryRow = $summaryResult->fetch_assoc()) {
            $summary['total_students'] = (int) ($summaryRow['total_students'] ?? 0);
            $summary['esm_students'] = (int) ($summaryRow['esm_students'] ?? 0);
            $summary['overall_students'] = (int) ($summaryRow['overall_students'] ?? 0);
            $summary['scored_students'] = (int) ($summaryRow['scored_students'] ?? 0);
        }
        $stmtSummary->close();
    }

    $where = ['pr.upload_batch_id = ?'];
    $types = 's';
    $params = [$activeBatchId];

    if ($search !== '') {
        $like = '%' . $search . '%';
        $where[] = '(pr.examinee_number LIKE ? OR pr.full_name LIKE ? OR pr.preferred_program LIKE ?)';
        $types .= 'sss';
        array_push($params, $like, $like, $like);
    }

    if ($basisFilter === 'esm') {
        $where[] = $esmConditionSql;
    } elseif ($basisFilter === 'overall') {
        $where[] = "NOT {$esmConditionSql}";
    }

    if ($scoreStatusFilter === 'scored') {
        $where[] = 'COALESCE(ix.has_score, 0) = 1';
    } elseif ($scoreStatusFilter === 'not_scored') {
        $where[] = 'COALESCE(ix.has_score, 0) = 0';
    }

    if ($preferredProgramFilter !== '') {
        $where[] = "TRIM(COALESCE(pr.preferred_program, '')) = ?";
        $types .= 's';
        $params[] = $preferredProgramFilter;
    }

    $whereSql = implode(' AND ', $where);

    $countSql = "
        SELECT COUNT(*) AS total
        FROM tbl_placement_results pr
        {$interviewJoinSql}
        WHERE {$whereSql}
    ";
    $stmtCount = $conn->prepare($countSql);
    if ($stmtCount) {
        guidance_bind_params($stmtCount, $types, $params);
        $stmtCount->execute();
        $countResult = $stmtCount->get_result();
        if ($countResult && $countRow = $countResult->fetch_assoc()) {
            $totalStudents = (int) ($countRow['total'] ?? 0);
        }
        $stmtCount->close();
    }

    $totalPages = max(1, (int) ceil($totalStudents / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }

    $offset = max(0, ($page - 1) * $perPage);
    $studentSql = "
        SELECT
            pr.id,
            pr.examinee_number,
            pr.full_name,
            pr.preferred_program,
            pr.sat_score,
            pr.qualitative_text,
            pr.qualitative_code,
            pr.esm_competency_standard_score,
            pr.overall_standard_score,
            COALESCE(ix.has_interview, 0) AS has_interview,
            COALESCE(ix.has_score, 0) AS has_score,
            ix.final_score,
            {$editMarkSelectSql}
        FROM tbl_placement_results pr
        {$interviewJoinSql}
        {$editMarkJoinSql}
        WHERE {$whereSql}
        ORDER BY pr.full_name ASC, pr.examinee_number ASC
        LIMIT ? OFFSET ?
    ";
    $stmtStudents = $conn->prepare($studentSql);
    if ($stmtStudents) {
        $studentTypes = $types . 'ii';
        $studentParams = $params;
        $studentParams[] = $perPage;
        $studentParams[] = $offset;
        guidance_bind_params($stmtStudents, $studentTypes, $studentParams);
        $stmtStudents->execute();
        $studentResult = $stmtStudents->get_result();
        while ($studentRow = $studentResult->fetch_assoc()) {
            $students[] = $studentRow;
        }
        $stmtStudents->close();
    }
}

$notScoredStudents = max(0, $summary['total_students'] - $summary['scored_students']);
$returnQuery = guidance_get_student_return_query($_GET);
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
    <title>Guidance Student Information - Interview</title>

    <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />
    <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
    <link rel="stylesheet" href="../assets/vendor/css/core.css" />
    <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
    <link rel="stylesheet" href="../assets/css/demo.css" />
    <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .gs-stat-card {
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.9rem 0.95rem;
        background: #fff;
      }

      .gs-stat-label {
        font-size: 0.74rem;
        font-weight: 700;
        text-transform: uppercase;
        color: #7d8aa3;
        letter-spacing: 0.04em;
      }

      .gs-stat-value {
        font-size: 1.45rem;
        font-weight: 700;
        line-height: 1.1;
        color: #2f3f59;
      }

      .gs-table-card {
        border: 1px solid #e6ebf3;
      }

      .gs-badge {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 0.32rem 0.65rem;
        font-size: 0.74rem;
        font-weight: 700;
      }

      .gs-badge--esm {
        background: #e8f0ff;
        color: #2563eb;
      }

      .gs-badge--overall {
        background: #f1f5f9;
        color: #334155;
      }

      .gs-badge--edited {
        background: #e0f2fe;
        color: #075985;
      }

      .gs-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 1rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
      }

      .gs-table td,
      .gs-table th {
        vertical-align: middle;
      }

      .gs-name {
        font-weight: 700;
        color: #334155;
        text-transform: uppercase;
      }

      .gs-sub {
        display: block;
        margin-top: 0.2rem;
        font-size: 0.76rem;
        color: #7d8aa3;
      }

      .gs-sub--edited {
        color: #0c4a6e;
        font-weight: 600;
      }

      .gs-row--just-edited td {
        background: #ecfdf3;
      }

      .gs-final-score {
        font-weight: 700;
        color: #0f766e;
      }

      .gs-modal-note {
        border: 1px solid #dbe7ff;
        border-radius: 0.8rem;
        background: #f8fbff;
        padding: 0.85rem 0.95rem;
        font-size: 0.83rem;
        color: #5a6d88;
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
              <h4 class="fw-bold mb-1">
                <span class="text-muted fw-light">Guidance /</span> Student Information
              </h4>
              <p class="text-muted mb-4">
                Review the active placement-results batch, identify ESM students from their preferred program, and add or edit student records when corrections are needed.
              </p>

              <?php if ($flash): ?>
                <div class="alert alert-<?= htmlspecialchars($flash['type'] ?? 'info'); ?> py-2 mb-4">
                  <?= htmlspecialchars((string) ($flash['message'] ?? '')); ?>
                </div>
              <?php endif; ?>

              <?php if ($activeBatchId !== null): ?>
                <div class="alert alert-info py-2 mb-3">
                  Active placement batch: <?= htmlspecialchars($activeBatchId); ?>
                </div>
              <?php else: ?>
                <div class="alert alert-warning py-2 mb-3">
                  No placement-results batch is available yet. Add and edit actions are disabled until a batch exists.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-md-3 col-6">
                  <div class="gs-stat-card">
                    <div class="gs-stat-label">Students in Batch</div>
                    <div class="gs-stat-value"><?= number_format($summary['total_students']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="gs-stat-card">
                    <div class="gs-stat-label">Scored</div>
                    <div class="gs-stat-value"><?= number_format($summary['scored_students']); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="gs-stat-card">
                    <div class="gs-stat-label">Not Scored</div>
                    <div class="gs-stat-value"><?= number_format($notScoredStudents); ?></div>
                  </div>
                </div>
                <div class="col-md-3 col-6">
                  <div class="gs-stat-card">
                    <div class="gs-stat-label">ESM Students</div>
                    <div class="gs-stat-value"><?= number_format($summary['esm_students']); ?></div>
                  </div>
                </div>
              </div>

              <div class="card gs-table-card">
                <div class="card-header">
                  <div class="d-flex flex-column flex-xl-row justify-content-between gap-3">
                    <form method="GET" class="row g-2 align-items-end flex-grow-1">
                      <div class="col-xl-4">
                        <label class="form-label mb-1">Search</label>
                        <input
                          type="search"
                          name="q"
                          value="<?= htmlspecialchars($search); ?>"
                          class="form-control"
                          placeholder="Examinee number, name, or preferred program"
                        />
                      </div>
                      <div class="col-xl-3 col-md-6">
                        <label class="form-label mb-1">Preferred Program</label>
                        <select name="preferred_program_filter" class="form-select">
                          <option value="">All Programs</option>
                          <?php foreach ($preferredProgramOptions as $preferredProgramOption): ?>
                            <?php
                            $optionName = (string) ($preferredProgramOption['name'] ?? '');
                            $optionCount = (int) ($preferredProgramOption['count'] ?? 0);
                            ?>
                            <option value="<?= htmlspecialchars($optionName); ?>"<?= $preferredProgramFilter === $optionName ? ' selected' : ''; ?>>
                              <?= htmlspecialchars($optionName); ?> (<?= number_format($optionCount); ?>)
                            </option>
                          <?php endforeach; ?>
                          <?php if ($preferredProgramFilter !== '' && !$preferredProgramFilterFound): ?>
                            <option value="<?= htmlspecialchars($preferredProgramFilter); ?>" selected>
                              <?= htmlspecialchars($preferredProgramFilter); ?> (0)
                            </option>
                          <?php endif; ?>
                        </select>
                      </div>
                      <div class="col-xl-2 col-md-3">
                        <label class="form-label mb-1">Basis</label>
                        <select name="basis" class="form-select">
                          <option value="all"<?= $basisFilter === 'all' ? ' selected' : ''; ?>>All</option>
                          <option value="esm"<?= $basisFilter === 'esm' ? ' selected' : ''; ?>>ESM</option>
                          <option value="overall"<?= $basisFilter === 'overall' ? ' selected' : ''; ?>>Overall</option>
                        </select>
                      </div>
                      <div class="col-xl-2 col-md-3">
                        <label class="form-label mb-1">Score Status</label>
                        <select name="score_status" class="form-select">
                          <option value="all"<?= $scoreStatusFilter === 'all' ? ' selected' : ''; ?>>All</option>
                          <option value="scored"<?= $scoreStatusFilter === 'scored' ? ' selected' : ''; ?>>Scored</option>
                          <option value="not_scored"<?= $scoreStatusFilter === 'not_scored' ? ' selected' : ''; ?>>Not Scored</option>
                        </select>
                      </div>
                      <div class="col-xl-1 col-md-12 d-grid">
                        <button type="submit" class="btn btn-primary">Filter</button>
                      </div>
                    </form>

                    <div class="d-grid d-xl-block">
                      <button
                        type="button"
                        class="btn btn-primary"
                        data-guidance-open="add"
                        <?= $activeBatchId === null ? 'disabled' : ''; ?>
                      >
                        <i class="bx bx-plus me-1"></i>Add Student
                      </button>
                    </div>
                  </div>
                </div>

                <div class="card-body">
                  <?php if ($activeBatchId === null): ?>
                    <div class="gs-empty-card">No placement-results batch found.</div>
                  <?php elseif (empty($students)): ?>
                    <div class="gs-empty-card">No students matched the selected filters.</div>
                  <?php else: ?>
                    <div class="table-responsive">
                      <table class="table table-hover gs-table">
                        <thead>
                          <tr>
                            <th>Student</th>
                            <th>Preferred Program</th>
                            <th>Basis</th>
                            <th>SAT</th>
                            <th>ESM</th>
                            <th>Overall</th>
                            <th>Status</th>
                            <th>Final Score</th>
                            <th class="text-end">Action</th>
                          </tr>
                        </thead>
                        <tbody>
                          <?php foreach ($students as $student): ?>
                            <?php
                            $studentIdValue = (int) ($student['id'] ?? 0);
                            $preferredProgram = trim((string) ($student['preferred_program'] ?? ''));
                            $isEsm = guidance_is_esm_preferred_program($preferredProgram);
                            $hasInterview = ((int) ($student['has_interview'] ?? 0) === 1);
                            $hasScore = ((int) ($student['has_score'] ?? 0) === 1);
                            $editCount = (int) ($student['guidance_edit_count'] ?? 0);
                            $isEditedRecord = $editCount > 0;
                            $editedBy = trim((string) ($student['guidance_last_edited_by'] ?? ''));
                            $editedAtLabel = guidance_format_datetime_display((string) ($student['guidance_last_edited_at'] ?? ''));
                            $rowClassList = [];
                            if ($isEditedRecord && $studentIdValue === $highlightEditedId) {
                                $rowClassList[] = 'gs-row--just-edited';
                            }
                            $rowClassAttr = !empty($rowClassList)
                                ? (' class="' . implode(' ', $rowClassList) . '"')
                                : '';
                            ?>
                            <tr<?= $rowClassAttr; ?>>
                              <td>
                                <span class="gs-name"><?= htmlspecialchars((string) ($student['full_name'] ?? '')); ?></span>
                                <?php if ($isEditedRecord): ?>
                                  <span class="gs-badge gs-badge--edited ms-2">Edited</span>
                                <?php endif; ?>
                                <small class="gs-sub">Examinee #: <?= htmlspecialchars((string) ($student['examinee_number'] ?? '')); ?></small>
                                <?php if ($isEditedRecord): ?>
                                  <small class="gs-sub gs-sub--edited">
                                    Last edit: <?= htmlspecialchars($editedAtLabel !== '' ? $editedAtLabel : 'Unknown'); ?>
                                    <?php if ($editedBy !== ''): ?>
                                      by <?= htmlspecialchars($editedBy); ?>
                                    <?php endif; ?>
                                    <?php if ($editCount > 1): ?>
                                      (<?= number_format($editCount); ?>x)
                                    <?php endif; ?>
                                  </small>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?= htmlspecialchars($preferredProgram !== '' ? $preferredProgram : 'No preferred program recorded'); ?>
                              </td>
                              <td>
                                <span class="gs-badge <?= $isEsm ? 'gs-badge--esm' : 'gs-badge--overall'; ?>">
                                  <?= $isEsm ? 'ESM' : 'Overall'; ?>
                                </span>
                              </td>
                              <td><?= htmlspecialchars(guidance_format_score($student['sat_score'] ?? null)); ?></td>
                              <td><?= htmlspecialchars(guidance_format_score($student['esm_competency_standard_score'] ?? null)); ?></td>
                              <td><?= htmlspecialchars(guidance_format_score($student['overall_standard_score'] ?? null)); ?></td>
                              <td>
                                <?php if ($hasScore): ?>
                                  <span class="badge bg-label-success">Scored</span>
                                <?php elseif ($hasInterview): ?>
                                  <span class="badge bg-label-warning">Pending Score</span>
                                <?php else: ?>
                                  <span class="badge bg-label-secondary">No Interview</span>
                                <?php endif; ?>
                              </td>
                              <td>
                                <?php if ($student['final_score'] !== null && $student['final_score'] !== ''): ?>
                                  <span class="gs-final-score"><?= htmlspecialchars(guidance_format_score($student['final_score'], 2)); ?></span>
                                <?php else: ?>
                                  <span class="text-muted">N/A</span>
                                <?php endif; ?>
                              </td>
                              <td class="text-end">
                                <button
                                  type="button"
                                  class="btn btn-sm btn-outline-primary"
                                  data-guidance-open="edit"
                                  data-student-id="<?= $studentIdValue; ?>"
                                  data-examinee-number="<?= htmlspecialchars((string) ($student['examinee_number'] ?? ''), ENT_QUOTES); ?>"
                                  data-full-name="<?= htmlspecialchars((string) ($student['full_name'] ?? ''), ENT_QUOTES); ?>"
                                  data-preferred-program="<?= htmlspecialchars($preferredProgram, ENT_QUOTES); ?>"
                                  data-sat-score="<?= htmlspecialchars((string) ($student['sat_score'] ?? ''), ENT_QUOTES); ?>"
                                  data-qualitative-text="<?= htmlspecialchars((string) ($student['qualitative_text'] ?? ''), ENT_QUOTES); ?>"
                                  data-qualitative-code="<?= htmlspecialchars((string) ($student['qualitative_code'] ?? ''), ENT_QUOTES); ?>"
                                  data-esm-score="<?= htmlspecialchars((string) ($student['esm_competency_standard_score'] ?? ''), ENT_QUOTES); ?>"
                                  data-overall-score="<?= htmlspecialchars((string) ($student['overall_standard_score'] ?? ''), ENT_QUOTES); ?>"
                                >
                                  <i class="bx bx-edit-alt me-1"></i>Edit
                                </button>
                              </td>
                            </tr>
                          <?php endforeach; ?>
                        </tbody>
                      </table>
                    </div>

                    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mt-3">
                      <div class="small text-muted">
                        Showing <?= number_format(count($students)); ?> of <?= number_format($totalStudents); ?> matching students.
                      </div>
                      <div class="d-flex gap-2">
                        <?php
                        $previousQuery = $returnQuery;
                        $nextQuery = $returnQuery;
                        if ($page > 1) {
                            $previousQuery['page'] = $page - 1;
                        }
                        if ($page < $totalPages) {
                            $nextQuery['page'] = $page + 1;
                        }
                        ?>
                        <a
                          href="<?= htmlspecialchars($page > 1 ? guidance_students_url($previousQuery) : '#'); ?>"
                          class="btn btn-outline-secondary<?= $page > 1 ? '' : ' disabled'; ?>"
                        >
                          Previous
                        </a>
                        <button type="button" class="btn btn-outline-secondary" disabled>
                          Page <?= number_format($page); ?> of <?= number_format($totalPages); ?>
                        </button>
                        <a
                          href="<?= htmlspecialchars($page < $totalPages ? guidance_students_url($nextQuery) : '#'); ?>"
                          class="btn btn-outline-secondary<?= $page < $totalPages ? '' : ' disabled'; ?>"
                        >
                          Next
                        </a>
                      </div>
                    </div>
                  <?php endif; ?>
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

    <div class="modal fade" id="guidanceStudentModal" tabindex="-1" aria-hidden="true">
      <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
          <form action="save_student.php" method="POST" id="guidanceStudentForm">
            <div class="modal-header">
              <h5 class="modal-title" id="guidanceStudentModalLabel">Add Student</h5>
              <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
              <div class="gs-modal-note mb-3">
                Records created from this screen are saved to the active placement-results batch:
                <strong><?= htmlspecialchars((string) ($activeBatchId ?? 'Unavailable')); ?></strong>.
              </div>

              <input type="hidden" name="mode" id="guidanceMode" value="add" />
              <input type="hidden" name="student_id" id="guidanceStudentId" value="0" />
              <input type="hidden" name="q" value="<?= htmlspecialchars($search); ?>" />
              <input type="hidden" name="basis" value="<?= htmlspecialchars($basisFilter); ?>" />
              <input type="hidden" name="score_status" value="<?= htmlspecialchars($scoreStatusFilter); ?>" />
              <input type="hidden" name="preferred_program_filter" value="<?= htmlspecialchars($preferredProgramFilter); ?>" />
              <input type="hidden" name="page" value="<?= (int) $page; ?>" />

              <div class="row g-3">
                <div class="col-md-6">
                  <label for="guidanceExamineeNumber" class="form-label">Examinee Number</label>
                  <input type="text" class="form-control" id="guidanceExamineeNumber" name="examinee_number" maxlength="30" required />
                </div>
                <div class="col-md-6">
                  <label for="guidanceFullName" class="form-label">Full Name</label>
                  <input type="text" class="form-control" id="guidanceFullName" name="full_name" maxlength="255" required />
                </div>
                <div class="col-12">
                  <label for="guidancePreferredProgram" class="form-label">Preferred Program</label>
                  <input type="text" class="form-control" id="guidancePreferredProgram" name="preferred_program" maxlength="255" />
                </div>
                <div class="col-md-4">
                  <label for="guidanceSatScore" class="form-label">SAT Score</label>
                  <input type="number" class="form-control" id="guidanceSatScore" name="sat_score" min="0" required />
                </div>
                <div class="col-md-4">
                  <label for="guidanceEsmScore" class="form-label">ESM Standard Score</label>
                  <input type="number" class="form-control" id="guidanceEsmScore" name="esm_competency_standard_score" min="0" />
                </div>
                <div class="col-md-4">
                  <label for="guidanceOverallScore" class="form-label">Overall Standard Score</label>
                  <input type="number" class="form-control" id="guidanceOverallScore" name="overall_standard_score" min="0" />
                </div>
                <div class="col-md-8">
                  <label for="guidanceQualitativeText" class="form-label">Qualitative Text</label>
                  <input type="text" class="form-control" id="guidanceQualitativeText" name="qualitative_text" maxlength="50" required />
                </div>
                <div class="col-md-4">
                  <label for="guidanceQualitativeCode" class="form-label">Qualitative Code</label>
                  <input type="number" class="form-control" id="guidanceQualitativeCode" name="qualitative_code" min="0" max="127" required />
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
              <button type="submit" class="btn btn-primary">Save Student</button>
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
        const modalEl = document.getElementById('guidanceStudentModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
          return;
        }

        const modal = new bootstrap.Modal(modalEl);
        const titleEl = document.getElementById('guidanceStudentModalLabel');
        const modeEl = document.getElementById('guidanceMode');
        const idEl = document.getElementById('guidanceStudentId');
        const examineeEl = document.getElementById('guidanceExamineeNumber');
        const fullNameEl = document.getElementById('guidanceFullName');
        const preferredProgramEl = document.getElementById('guidancePreferredProgram');
        const satScoreEl = document.getElementById('guidanceSatScore');
        const esmScoreEl = document.getElementById('guidanceEsmScore');
        const overallScoreEl = document.getElementById('guidanceOverallScore');
        const qualitativeTextEl = document.getElementById('guidanceQualitativeText');
        const qualitativeCodeEl = document.getElementById('guidanceQualitativeCode');

        function resetForm() {
          modeEl.value = 'add';
          idEl.value = '0';
          titleEl.textContent = 'Add Student';
          examineeEl.value = '';
          fullNameEl.value = '';
          preferredProgramEl.value = '';
          satScoreEl.value = '';
          esmScoreEl.value = '';
          overallScoreEl.value = '';
          qualitativeTextEl.value = '';
          qualitativeCodeEl.value = '';
        }

        document.querySelectorAll('[data-guidance-open]').forEach(function (trigger) {
          trigger.addEventListener('click', function () {
            const mode = trigger.getAttribute('data-guidance-open');
            resetForm();

            if (mode === 'edit') {
              modeEl.value = 'edit';
              idEl.value = trigger.getAttribute('data-student-id') || '0';
              titleEl.textContent = 'Edit Student';
              examineeEl.value = trigger.getAttribute('data-examinee-number') || '';
              fullNameEl.value = trigger.getAttribute('data-full-name') || '';
              preferredProgramEl.value = trigger.getAttribute('data-preferred-program') || '';
              satScoreEl.value = trigger.getAttribute('data-sat-score') || '';
              esmScoreEl.value = trigger.getAttribute('data-esm-score') || '';
              overallScoreEl.value = trigger.getAttribute('data-overall-score') || '';
              qualitativeTextEl.value = trigger.getAttribute('data-qualitative-text') || '';
              qualitativeCodeEl.value = trigger.getAttribute('data-qualitative-code') || '';
            }

            modal.show();
          });
        });

        modalEl.addEventListener('hidden.bs.modal', resetForm);
      })();
    </script>
  </body>
</html>
