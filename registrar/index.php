<?php
require_once '../config/db.php';
require_once '../config/student_preregistration.php';
session_start();

if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'registrar')) {
    header('Location: ../index.php');
    exit;
}

$registrarHeaderTitle = 'Registrar - Dashboard';
$storageReady = ensure_student_preregistration_storage($conn);

function registrar_fetch_single(mysqli $conn, string $sql): array
{
    $result = $conn->query($sql);
    if (!$result) {
        return [];
    }

    $row = $result->fetch_assoc();
    $result->free();

    return is_array($row) ? $row : [];
}

function registrar_fetch_distribution(mysqli $conn, string $sql): array
{
    $rows = [];
    $result = $conn->query($sql);
    if (!$result) {
        return $rows;
    }

    while ($row = $result->fetch_assoc()) {
        $label = trim((string) ($row['label'] ?? ''));
        if ($label === '') {
            $label = 'Not specified';
        }

        $rows[] = [
            'label' => $label,
            'count' => max(0, (int) ($row['count'] ?? 0)),
        ];
    }
    $result->free();

    return $rows;
}

function registrar_chart_labels(array $rows): array
{
    return array_map(static function (array $row): string {
        return (string) ($row['label'] ?? '');
    }, $rows);
}

function registrar_chart_counts(array $rows): array
{
    return array_map(static function (array $row): int {
        return (int) ($row['count'] ?? 0);
    }, $rows);
}

function registrar_percent(int $part, int $total): string
{
    if ($total <= 0) {
        return '0%';
    }

    return number_format(($part / $total) * 100, 1) . '%';
}

$summary = [
    'total_preregistered' => 0,
    'program_count' => 0,
    'campus_count' => 0,
    'profile_complete' => 0,
    'agreement_accepted' => 0,
    'etg_count' => 0,
    'non_etg_count' => 0,
    'scored_count' => 0,
    'average_final_score' => null,
];

$genderRows = [];
$civilRows = [];
$religionRows = [];
$schoolTypeRows = [];
$schoolRows = [];
$cityRows = [];
$barangayRows = [];
$programRows = [];
$campusRows = [];
$profileRows = [];
$scoreBandRows = [];
$etgClassRows = [];
$trendRows = [];
$etgScoreSummary = ['etg_scored' => 0, 'etg_average_score' => null, 'etg_highest_score' => null];

if ($storageReady) {
    $summary = array_merge($summary, registrar_fetch_single($conn, "
        SELECT
            COUNT(*) AS total_preregistered,
            COUNT(DISTINCT spr.program_id) AS program_count,
            COUNT(DISTINCT program_campus.campus_id) AS campus_count,
            SUM(CASE WHEN COALESCE(sp.profile_completion_percent, spr.profile_completion_percent, 0) >= 100 THEN 1 ELSE 0 END) AS profile_complete,
            SUM(CASE WHEN spr.agreement_accepted = 1 THEN 1 ELSE 0 END) AS agreement_accepted,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG' THEN 1 ELSE 0 END) AS etg_count,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG' THEN 0 ELSE 1 END) AS non_etg_count,
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS scored_count,
            AVG(CASE WHEN si.final_score IS NOT NULL THEN si.final_score ELSE NULL END) AS average_final_score
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp
            ON sp.credential_id = spr.credential_id
        LEFT JOIN tbl_student_interview si
            ON si.interview_id = spr.interview_id
        LEFT JOIN tbl_program p
            ON p.program_id = spr.program_id
        LEFT JOIN tbl_college program_college
            ON program_college.college_id = p.college_id
        LEFT JOIN tbl_campus program_campus
            ON program_campus.campus_id = program_college.campus_id
        WHERE spr.status = 'submitted'
    "));

    $genderRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(sp.sex), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
    ");

    $civilRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(sp.civil_status), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
    ");

    $religionRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(sp.religion), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
        LIMIT 12
    ");

    $schoolTypeRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(sp.secondary_school_type), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
    ");

    $schoolRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(sp.secondary_school_name), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
        LIMIT 12
    ");

    $cityRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(rc.citymunDesc), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        LEFT JOIN refcitymun rc ON rc.citymunCode = sp.citymun_code
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
        LIMIT 12
    ");

    $barangayRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(rb.brgyDesc), ''), 'Not specified') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        LEFT JOIN refbrgy rb ON rb.brgyCode = sp.barangay_code
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
        LIMIT 12
    ");

    $programRows = registrar_fetch_distribution($conn, "
        SELECT
            COALESCE(
                NULLIF(
                    TRIM(CONCAT(
                        COALESCE(NULLIF(p.program_code, ''), ''),
                        CASE WHEN p.program_code IS NOT NULL AND p.program_code <> '' THEN ' - ' ELSE '' END,
                        COALESCE(NULLIF(p.program_name, ''), ''),
                        CASE WHEN p.major IS NOT NULL AND p.major <> '' THEN CONCAT(' - ', p.major) ELSE '' END
                    )),
                    ''
                ),
                'No program'
            ) AS label,
            COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_program p ON p.program_id = spr.program_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
        LIMIT 10
    ");

    $campusRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(cam.campus_name), ''), 'No campus') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_program p ON p.program_id = spr.program_id
        LEFT JOIN tbl_college col ON col.college_id = p.college_id
        LEFT JOIN tbl_campus cam ON cam.campus_id = col.campus_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY count DESC, label ASC
    ");

    $profileRows = registrar_fetch_distribution($conn, "
        SELECT
            CASE
                WHEN COALESCE(sp.profile_completion_percent, spr.profile_completion_percent, 0) >= 100 THEN 'Complete'
                WHEN COALESCE(sp.profile_completion_percent, spr.profile_completion_percent, 0) >= 75 THEN '75-99%'
                WHEN COALESCE(sp.profile_completion_percent, spr.profile_completion_percent, 0) >= 50 THEN '50-74%'
                WHEN COALESCE(sp.profile_completion_percent, spr.profile_completion_percent, 0) > 0 THEN '1-49%'
                ELSE 'Not started'
            END AS label,
            COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_profile sp ON sp.credential_id = spr.credential_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY
            CASE label
                WHEN 'Complete' THEN 1
                WHEN '75-99%' THEN 2
                WHEN '50-74%' THEN 3
                WHEN '1-49%' THEN 4
                ELSE 5
            END
    ");

    $scoreBandRows = registrar_fetch_distribution($conn, "
        SELECT
            CASE
                WHEN si.final_score IS NULL THEN 'No score'
                WHEN si.final_score >= 90 THEN '90-100'
                WHEN si.final_score >= 80 THEN '80-89'
                WHEN si.final_score >= 70 THEN '70-79'
                WHEN si.final_score >= 60 THEN '60-69'
                ELSE 'Below 60'
            END AS label,
            COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_interview si ON si.interview_id = spr.interview_id
        WHERE spr.status = 'submitted'
        GROUP BY label
        ORDER BY
            CASE label
                WHEN '90-100' THEN 1
                WHEN '80-89' THEN 2
                WHEN '70-79' THEN 3
                WHEN '60-69' THEN 4
                WHEN 'Below 60' THEN 5
                ELSE 6
            END
    ");

    $etgClassRows = registrar_fetch_distribution($conn, "
        SELECT COALESCE(NULLIF(TRIM(ec.class_desc), ''), 'No ETG class') AS label, COUNT(*) AS count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_interview si ON si.interview_id = spr.interview_id
        LEFT JOIN tbl_etg_class ec ON ec.etgclassid = si.etg_class_id
        WHERE spr.status = 'submitted'
          AND UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG'
        GROUP BY label
        ORDER BY count DESC, label ASC
    ");

    $etgScoreSummary = array_merge($etgScoreSummary, registrar_fetch_single($conn, "
        SELECT
            SUM(CASE WHEN si.final_score IS NOT NULL THEN 1 ELSE 0 END) AS etg_scored,
            AVG(CASE WHEN si.final_score IS NOT NULL THEN si.final_score ELSE NULL END) AS etg_average_score,
            MAX(si.final_score) AS etg_highest_score
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_interview si ON si.interview_id = spr.interview_id
        WHERE spr.status = 'submitted'
          AND UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG'
    "));

    $trendResult = $conn->query("
        SELECT
            DATE(spr.submitted_at) AS submitted_date,
            COUNT(*) AS total_count,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG' THEN 1 ELSE 0 END) AS etg_count,
            SUM(CASE WHEN UPPER(TRIM(COALESCE(si.classification, ''))) = 'ETG' THEN 0 ELSE 1 END) AS non_etg_count
        FROM tbl_student_preregistration spr
        LEFT JOIN tbl_student_interview si ON si.interview_id = spr.interview_id
        WHERE spr.status = 'submitted'
        GROUP BY DATE(spr.submitted_at)
        ORDER BY DATE(spr.submitted_at) DESC
        LIMIT 30
    ");
    if ($trendResult) {
        while ($trendRow = $trendResult->fetch_assoc()) {
            $trendRows[] = [
                'date' => (string) ($trendRow['submitted_date'] ?? ''),
                'total' => max(0, (int) ($trendRow['total_count'] ?? 0)),
                'etg' => max(0, (int) ($trendRow['etg_count'] ?? 0)),
                'non_etg' => max(0, (int) ($trendRow['non_etg_count'] ?? 0)),
            ];
        }
        $trendResult->free();
        $trendRows = array_reverse($trendRows);
    }
}

$totalPreregistered = max(0, (int) ($summary['total_preregistered'] ?? 0));
$profileComplete = max(0, (int) ($summary['profile_complete'] ?? 0));
$agreementAccepted = max(0, (int) ($summary['agreement_accepted'] ?? 0));
$etgCount = max(0, (int) ($summary['etg_count'] ?? 0));
$nonEtgCount = max(0, (int) ($summary['non_etg_count'] ?? 0));
$averageFinalScore = $summary['average_final_score'] !== null ? (float) $summary['average_final_score'] : null;
$etgAverageScore = $etgScoreSummary['etg_average_score'] !== null ? (float) $etgScoreSummary['etg_average_score'] : null;
$etgHighestScore = $etgScoreSummary['etg_highest_score'] !== null ? (float) $etgScoreSummary['etg_highest_score'] : null;

$trendCategories = array_map(static function (array $row): string {
    return date('M j', strtotime((string) ($row['date'] ?? 'now')));
}, $trendRows);
$trendSeries = [
    ['name' => 'Submitted', 'data' => array_map(static function (array $row): int {
        return (int) $row['total'];
    }, $trendRows)],
    ['name' => 'ETG', 'data' => array_map(static function (array $row): int {
        return (int) $row['etg'];
    }, $trendRows)],
    ['name' => 'Non-ETG', 'data' => array_map(static function (array $row): int {
        return (int) $row['non_etg'];
    }, $trendRows)],
];
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
      content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, minimum-scale=1.0"
    />
    <title>Registrar Dashboard - Interview</title>

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
    <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
    <script src="../assets/vendor/js/helpers.js"></script>
    <script src="../assets/js/config.js"></script>
    <style>
      .rg-stat-card {
        height: 100%;
        border: 1px solid #e6ebf3;
        border-radius: 0.85rem;
        padding: 0.95rem 1rem;
        background: #fff;
      }

      .rg-stat-label {
        font-size: 0.72rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.04em;
        color: #7d8aa3;
      }

      .rg-stat-value {
        margin-top: 0.35rem;
        font-size: 1.55rem;
        font-weight: 700;
        line-height: 1.08;
        color: #2f3f59;
      }

      .rg-stat-hint {
        display: block;
        margin-top: 0.28rem;
        font-size: 0.78rem;
        line-height: 1.3;
        color: #7d8aa3;
      }

      .rg-chart-card {
        height: 100%;
        border: 1px solid #e6ebf3;
        border-radius: 0.9rem;
        background: #fff;
      }

      .rg-chart-title {
        font-size: 1rem;
        font-weight: 700;
        color: #334155;
      }

      .rg-chart-subtitle {
        color: #7d8aa3;
        font-size: 0.82rem;
      }

      .rg-empty-card {
        border: 1px dashed #d9e2ef;
        border-radius: 0.85rem;
        padding: 2rem 1.25rem;
        background: #f9fbff;
        color: #6b7a90;
        text-align: center;
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
                <span class="text-muted fw-light">Registrar /</span> Dashboard
              </h4>
              <p class="text-muted mb-4">
                Analytics from submitted pre-registration records and their linked student profile and interview data.
              </p>

              <?php if (!$storageReady): ?>
                <div class="alert alert-danger">
                  Unable to prepare pre-registration storage. Refresh the page or check database permissions.
                </div>
              <?php endif; ?>

              <div class="row g-3 mb-4">
                <div class="col-xl-3 col-md-6">
                  <div class="rg-stat-card">
                    <div class="rg-stat-label">Pre-Registered Students</div>
                    <div class="rg-stat-value"><?= number_format($totalPreregistered); ?></div>
                    <span class="rg-stat-hint"><?= number_format((int) ($summary['program_count'] ?? 0)); ?> programs represented</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="rg-stat-card">
                    <div class="rg-stat-label">Profile Complete</div>
                    <div class="rg-stat-value"><?= number_format($profileComplete); ?></div>
                    <span class="rg-stat-hint"><?= htmlspecialchars(registrar_percent($profileComplete, $totalPreregistered)); ?> of submitted records</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="rg-stat-card">
                    <div class="rg-stat-label">ETG Students</div>
                    <div class="rg-stat-value"><?= number_format($etgCount); ?></div>
                    <span class="rg-stat-hint"><?= htmlspecialchars(registrar_percent($etgCount, $totalPreregistered)); ?> ETG share</span>
                  </div>
                </div>
                <div class="col-xl-3 col-md-6">
                  <div class="rg-stat-card">
                    <div class="rg-stat-label">Average Interview Score</div>
                    <div class="rg-stat-value"><?= $averageFinalScore !== null ? number_format($averageFinalScore, 2) : 'N/A'; ?></div>
                    <span class="rg-stat-hint"><?= number_format((int) ($summary['scored_count'] ?? 0)); ?> records with final score</span>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-8">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">Pre-Registration Trend</div>
                      <div class="rg-chart-subtitle mb-3">Submitted, ETG, and non-ETG records by submission date.</div>
                      <div id="registrarTrendChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-4">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">ETG Snapshot</div>
                      <div class="rg-chart-subtitle mb-3">Classification from linked interview records.</div>
                      <div id="registrarEtgShareChart"></div>
                      <div class="row g-2 mt-2">
                        <div class="col-6">
                          <div class="rg-stat-card">
                            <div class="rg-stat-label">ETG Avg Score</div>
                            <div class="rg-stat-value"><?= $etgAverageScore !== null ? number_format($etgAverageScore, 2) : 'N/A'; ?></div>
                          </div>
                        </div>
                        <div class="col-6">
                          <div class="rg-stat-card">
                            <div class="rg-stat-label">ETG Highest</div>
                            <div class="rg-stat-value"><?= $etgHighestScore !== null ? number_format($etgHighestScore, 2) : 'N/A'; ?></div>
                          </div>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-4 col-lg-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By Gender</div>
                      <div class="rg-chart-subtitle mb-3">Student profile sex field.</div>
                      <div id="registrarGenderChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By Civil Status</div>
                      <div class="rg-chart-subtitle mb-3">Submitted profile civil status.</div>
                      <div id="registrarCivilChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-4 col-lg-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By School Type</div>
                      <div class="rg-chart-subtitle mb-3">Secondary school classification.</div>
                      <div id="registrarSchoolTypeChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">Top High Schools</div>
                      <div class="rg-chart-subtitle mb-3">Secondary school name from pre-registration profile.</div>
                      <div id="registrarSchoolChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By Religion</div>
                      <div class="rg-chart-subtitle mb-3">Top profile religion entries.</div>
                      <div id="registrarReligionChart"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-3 mb-4">
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By City / Municipality</div>
                      <div class="rg-chart-subtitle mb-3">Home address city or municipality.</div>
                      <div id="registrarCityChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">By Barangay</div>
                      <div class="rg-chart-subtitle mb-3">Home address barangay.</div>
                      <div id="registrarBarangayChart"></div>
                    </div>
                  </div>
                </div>
              </div>

              <div class="row g-3">
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">Top Programs</div>
                      <div class="rg-chart-subtitle mb-3">Program selected at pre-registration submission.</div>
                      <div id="registrarProgramChart"></div>
                    </div>
                  </div>
                </div>
                <div class="col-xl-6">
                  <div class="card rg-chart-card">
                    <div class="card-body">
                      <div class="rg-chart-title">ETG Classes and Score Bands</div>
                      <div class="rg-chart-subtitle mb-3">Interview classification, ETG class, and final-score grouping.</div>
                      <div class="row g-3">
                        <div class="col-lg-6">
                          <div id="registrarEtgClassChart"></div>
                        </div>
                        <div class="col-lg-6">
                          <div id="registrarScoreBandChart"></div>
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

    <script src="../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../assets/vendor/libs/popper/popper.js"></script>
    <script src="../assets/vendor/js/bootstrap.js"></script>
    <script src="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
    <script src="../assets/vendor/js/menu.js"></script>
    <script src="../assets/vendor/libs/apex-charts/apexcharts.js"></script>
    <script src="../assets/js/main.js"></script>
    <script>
      document.addEventListener('DOMContentLoaded', function () {
        if (typeof ApexCharts === 'undefined') {
          return;
        }

        const palette = ['#696cff', '#03c3ec', '#71dd37', '#ffab00', '#ff3e1d', '#8592a3', '#8e5cf7', '#00a884', '#d63384', '#2b7fff', '#f97316', '#14b8a6'];
        const noData = { text: 'No submitted pre-registration data available.' };

        function numberFormatter(value) {
          const numberValue = Number(value);
          return Number.isFinite(numberValue) ? Math.round(numberValue).toLocaleString() : '0';
        }

        function renderDonut(selector, labels, series) {
          const el = document.querySelector(selector);
          if (!el) return;

          new ApexCharts(el, {
            chart: { type: 'donut', height: 280 },
            labels,
            series,
            colors: palette,
            legend: { position: 'bottom' },
            dataLabels: { enabled: true },
            tooltip: {
              y: { formatter: (value) => `${numberFormatter(value)} student${Number(value) === 1 ? '' : 's'}` }
            },
            noData
          }).render();
        }

        function renderBar(selector, categories, data, horizontal) {
          const el = document.querySelector(selector);
          if (!el) return;

          new ApexCharts(el, {
            chart: { type: 'bar', height: horizontal ? 360 : 310, toolbar: { show: false } },
            series: [{ name: 'Students', data }],
            colors: ['#03c3ec'],
            plotOptions: {
              bar: {
                horizontal: !!horizontal,
                borderRadius: 4,
                distributed: true
              }
            },
            dataLabels: { enabled: false },
            legend: { show: false },
            xaxis: {
              categories,
              labels: { formatter: numberFormatter }
            },
            yaxis: {
              labels: {
                maxWidth: horizontal ? 220 : 120
              }
            },
            tooltip: {
              y: { formatter: (value) => `${numberFormatter(value)} student${Number(value) === 1 ? '' : 's'}` }
            },
            noData
          }).render();
        }

        new ApexCharts(document.querySelector('#registrarTrendChart'), {
          chart: { type: 'line', height: 350, toolbar: { show: false }, zoom: { enabled: false } },
          series: <?= json_encode($trendSeries); ?>,
          colors: ['#696cff', '#ffab00', '#03c3ec'],
          stroke: { curve: 'smooth', width: 3 },
          markers: { size: 4, strokeWidth: 0 },
          dataLabels: { enabled: false },
          grid: { borderColor: '#eef2f7', strokeDashArray: 4 },
          legend: { position: 'top', horizontalAlign: 'left' },
          xaxis: { categories: <?= json_encode($trendCategories); ?> },
          yaxis: { min: 0, forceNiceScale: true, labels: { formatter: numberFormatter } },
          tooltip: { y: { formatter: (value) => `${numberFormatter(value)} record${Number(value) === 1 ? '' : 's'}` } },
          noData
        }).render();

        renderDonut('#registrarEtgShareChart', ['ETG', 'Non-ETG'], [<?= (int) $etgCount; ?>, <?= (int) $nonEtgCount; ?>]);
        renderDonut('#registrarGenderChart', <?= json_encode(registrar_chart_labels($genderRows)); ?>, <?= json_encode(registrar_chart_counts($genderRows)); ?>);
        renderDonut('#registrarCivilChart', <?= json_encode(registrar_chart_labels($civilRows)); ?>, <?= json_encode(registrar_chart_counts($civilRows)); ?>);
        renderDonut('#registrarSchoolTypeChart', <?= json_encode(registrar_chart_labels($schoolTypeRows)); ?>, <?= json_encode(registrar_chart_counts($schoolTypeRows)); ?>);
        renderBar('#registrarSchoolChart', <?= json_encode(registrar_chart_labels($schoolRows)); ?>, <?= json_encode(registrar_chart_counts($schoolRows)); ?>, true);
        renderBar('#registrarReligionChart', <?= json_encode(registrar_chart_labels($religionRows)); ?>, <?= json_encode(registrar_chart_counts($religionRows)); ?>, true);
        renderBar('#registrarCityChart', <?= json_encode(registrar_chart_labels($cityRows)); ?>, <?= json_encode(registrar_chart_counts($cityRows)); ?>, true);
        renderBar('#registrarBarangayChart', <?= json_encode(registrar_chart_labels($barangayRows)); ?>, <?= json_encode(registrar_chart_counts($barangayRows)); ?>, true);
        renderBar('#registrarProgramChart', <?= json_encode(registrar_chart_labels($programRows)); ?>, <?= json_encode(registrar_chart_counts($programRows)); ?>, true);
        renderBar('#registrarEtgClassChart', <?= json_encode(registrar_chart_labels($etgClassRows)); ?>, <?= json_encode(registrar_chart_counts($etgClassRows)); ?>, true);
        renderBar('#registrarScoreBandChart', <?= json_encode(registrar_chart_labels($scoreBandRows)); ?>, <?= json_encode(registrar_chart_counts($scoreBandRows)); ?>, true);
      });
    </script>
  </body>
</html>
