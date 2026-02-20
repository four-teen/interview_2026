<?php
/**
 * ============================================================================
 * FILE: root_folder/interview/progchair/activity_logs.php
 * PURPOSE: Owner-scoped activity logs (score audit trail)
 * ============================================================================
 */

require_once '../config/db.php';
session_start();

if (
    !isset($_SESSION['logged_in']) ||
    $_SESSION['role'] !== 'progchair' ||
    empty($_SESSION['accountid'])
) {
    header('Location: ../index.php');
    exit;
}

$accountId = (int) $_SESSION['accountid'];
$perPage = 25;
$page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
$page = max($page, 1);
$offset = ($page - 1) * $perPage;
$chartLookbackDays = 30;

$total = 0;
$countSql = "SELECT COUNT(*) AS total FROM tbl_score_audit_logs WHERE actor_accountid = ?";
$stmtCount = $conn->prepare($countSql);
if ($stmtCount) {
    $stmtCount->bind_param("i", $accountId);
    $stmtCount->execute();
    $countRow = $stmtCount->get_result()->fetch_assoc();
    $total = (int) ($countRow['total'] ?? 0);
    $stmtCount->close();
}

$actionLabelMap = [
    'SCORE_SAVE' => 'Score Save',
    'SCORE_UPDATE' => 'Score Update',
    'FINAL_SCORE_UPDATE' => 'Final Score Update',
    'OTHER' => 'Other Actions'
];

$chartEndDate = new DateTimeImmutable('today');
$chartStartDate = $chartEndDate->sub(new DateInterval('P' . max(1, $chartLookbackDays - 1) . 'D'));
$chartDateRows = [];
$dateCursor = $chartStartDate;
while ($dateCursor <= $chartEndDate) {
    $label = $dateCursor->format('Y-m-d');
    $chartDateRows[$label] = [
        'SCORE_SAVE' => 0,
        'SCORE_UPDATE' => 0,
        'FINAL_SCORE_UPDATE' => 0,
        'OTHER' => 0
    ];
    $dateCursor = $dateCursor->modify('+1 day');
}

$chartSql = "
    SELECT
        DATE(created_at) AS log_date,
        action,
        COUNT(*) AS action_count
    FROM tbl_score_audit_logs
    WHERE actor_accountid = ?
      AND created_at >= ?
      AND created_at < DATE_ADD(?, INTERVAL 1 DAY)
    GROUP BY DATE(created_at), action
    ORDER BY DATE(created_at) ASC
";

$stmtChart = $conn->prepare($chartSql);
if ($stmtChart) {
    $chartStartBoundary = $chartStartDate->format('Y-m-d 00:00:00');
    $chartEndBoundary = $chartEndDate->format('Y-m-d');
    $stmtChart->bind_param("iss", $accountId, $chartStartBoundary, $chartEndBoundary);
    $stmtChart->execute();
    $chartResult = $stmtChart->get_result();
    while ($chartRow = $chartResult->fetch_assoc()) {
        $logDate = (string) ($chartRow['log_date'] ?? '');
        if (!isset($chartDateRows[$logDate])) {
            continue;
        }

        $rawAction = strtoupper(trim((string) ($chartRow['action'] ?? '')));
        $actionKey = isset($chartDateRows[$logDate][$rawAction]) ? $rawAction : 'OTHER';
        $chartDateRows[$logDate][$actionKey] += (int) ($chartRow['action_count'] ?? 0);
    }
    $stmtChart->close();
}

$chartCategories = array_keys($chartDateRows);
$chartSeriesData = [
    'SCORE_SAVE' => [],
    'SCORE_UPDATE' => [],
    'FINAL_SCORE_UPDATE' => [],
    'OTHER' => []
];

foreach ($chartDateRows as $dateRow) {
    foreach ($chartSeriesData as $actionKey => $values) {
        $chartSeriesData[$actionKey][] = (int) ($dateRow[$actionKey] ?? 0);
    }
}

$chartSeries = [
    [
        'name' => $actionLabelMap['SCORE_SAVE'],
        'key' => 'SCORE_SAVE',
        'color' => '#71dd37',
        'data' => $chartSeriesData['SCORE_SAVE']
    ],
    [
        'name' => $actionLabelMap['SCORE_UPDATE'],
        'key' => 'SCORE_UPDATE',
        'color' => '#ffab00',
        'data' => $chartSeriesData['SCORE_UPDATE']
    ],
    [
        'name' => $actionLabelMap['FINAL_SCORE_UPDATE'],
        'key' => 'FINAL_SCORE_UPDATE',
        'color' => '#03c3ec',
        'data' => $chartSeriesData['FINAL_SCORE_UPDATE']
    ]
];

$chartTotals = [
    'SCORE_SAVE' => array_sum($chartSeriesData['SCORE_SAVE']),
    'SCORE_UPDATE' => array_sum($chartSeriesData['SCORE_UPDATE']),
    'FINAL_SCORE_UPDATE' => array_sum($chartSeriesData['FINAL_SCORE_UPDATE']),
    'OTHER' => array_sum($chartSeriesData['OTHER'])
];
$chartHasData = ($chartTotals['SCORE_SAVE'] + $chartTotals['SCORE_UPDATE'] + $chartTotals['FINAL_SCORE_UPDATE'] + $chartTotals['OTHER']) > 0;

$sql = "
    SELECT
        l.log_id,
        l.interview_id,
        l.component_id,
        l.action,
        l.old_raw,
        l.new_raw,
        l.old_weighted,
        l.new_weighted,
        l.final_before,
        l.final_after,
        l.ip_address,
        l.created_at,
        pr.examinee_number,
        pr.full_name,
        sc.component_name,
        p.program_name,
        p.major
    FROM tbl_score_audit_logs l
    LEFT JOIN tbl_student_interview si
        ON l.interview_id = si.interview_id
    LEFT JOIN tbl_placement_results pr
        ON si.placement_result_id = pr.id
    LEFT JOIN tbl_scoring_components sc
        ON l.component_id = sc.component_id
    LEFT JOIN tbl_program p
        ON si.program_id = p.program_id
    WHERE l.actor_accountid = ?
    ORDER BY l.created_at DESC, l.log_id DESC
    LIMIT ? OFFSET ?
";

$stmt = $conn->prepare($sql);
$rows = [];
if ($stmt) {
    $stmt->bind_param("iii", $accountId, $perPage, $offset);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();
}

$totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;

function format_delta($oldValue, $newValue, $suffix = '')
{
    if ($newValue === null || $newValue === '') {
        return '--';
    }

    $newFormatted = number_format((float) $newValue, 2) . $suffix;
    if ($oldValue === null || $oldValue === '') {
        return $newFormatted;
    }

    $oldFormatted = number_format((float) $oldValue, 2) . $suffix;
    return $oldFormatted . ' -> ' . $newFormatted;
}

function action_badge_class($action)
{
    if ($action === 'SCORE_SAVE') {
        return 'bg-label-success';
    }

    if ($action === 'SCORE_UPDATE') {
        return 'bg-label-warning';
    }

    if ($action === 'FINAL_SCORE_UPDATE') {
        return 'bg-label-info';
    }

    return 'bg-label-secondary';
}

$chartPayload = [
    'categories' => $chartCategories,
    'series' => $chartSeries
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
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0">
  <title>Activity Logs - Program Chair</title>

  <link rel="icon" type="image/x-icon" href="../assets/img/favicon/favicon.ico" />
  <link rel="stylesheet" href="../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../assets/css/demo.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <link rel="stylesheet" href="../assets/vendor/libs/apex-charts/apex-charts.css" />
  <style>
    #activityTrendChart {
      min-height: 320px;
    }
  </style>
  <script src="../assets/vendor/js/helpers.js"></script>
  <script src="../assets/js/config.js"></script>
</head>
<body>
  <div class="layout-wrapper layout-content-navbar">
    <div class="layout-container">
      <?php include 'sidebar.php'; ?>

      <div class="layout-page">
        <?php include 'header.php'; ?>

        <div class="content-wrapper">
          <div class="container-xxl flex-grow-1 container-p-y">
            <div class="d-flex justify-content-between align-items-center mb-4">
              <div>
                <h4 class="mb-1">
                  <i class="bx bx-history me-1"></i>
                  Activity Logs
                </h4>
                <small class="text-muted">Audit trail for scoring actions performed by your account.</small>
              </div>
              <a href="index.php" class="btn btn-label-secondary btn-sm">
                <i class="bx bx-arrow-back me-1"></i> Back to Dashboard
              </a>
            </div>

            <div class="card mb-4">
              <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                  <span class="fw-semibold">Activity Trend (Last <?= (int) $chartLookbackDays; ?> Days)</span>
                  <small class="text-muted d-block">Click any chart point to view the detailed activity list.</small>
                </div>
                <div class="d-flex flex-wrap gap-2">
                  <span class="badge bg-label-success">
                    <?= htmlspecialchars($actionLabelMap['SCORE_SAVE']); ?>: <?= (int) $chartTotals['SCORE_SAVE']; ?>
                  </span>
                  <span class="badge bg-label-warning">
                    <?= htmlspecialchars($actionLabelMap['SCORE_UPDATE']); ?>: <?= (int) $chartTotals['SCORE_UPDATE']; ?>
                  </span>
                  <span class="badge bg-label-info">
                    <?= htmlspecialchars($actionLabelMap['FINAL_SCORE_UPDATE']); ?>: <?= (int) $chartTotals['FINAL_SCORE_UPDATE']; ?>
                  </span>
                  <?php if ($chartTotals['OTHER'] > 0): ?>
                    <span class="badge bg-label-secondary">
                      <?= htmlspecialchars($actionLabelMap['OTHER']); ?>: <?= (int) $chartTotals['OTHER']; ?>
                    </span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="card-body">
                <?php if ($chartHasData): ?>
                  <div id="activityTrendChart"></div>
                <?php else: ?>
                  <div class="alert alert-info mb-0">No activity trend data found in the last <?= (int) $chartLookbackDays; ?> days.</div>
                <?php endif; ?>
              </div>
            </div>

            <div class="card">
              <div class="card-header d-flex justify-content-between align-items-center">
                <span class="fw-semibold">Total Logs</span>
                <span class="badge bg-primary"><?= (int) $total; ?></span>
              </div>

              <div class="card-body p-0">
                <?php if (empty($rows)): ?>
                  <div class="p-4">
                    <div class="alert alert-info mb-0">No activity logs found for your account.</div>
                  </div>
                <?php else: ?>
                  <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                      <thead class="table-light">
                        <tr>
                          <th style="width: 92px;">Log ID</th>
                          <th style="width: 180px;">Timestamp</th>
                          <th style="width: 170px;">Action</th>
                          <th>Student</th>
                          <th style="width: 140px;">Component</th>
                          <th style="width: 160px;">Raw</th>
                          <th style="width: 170px;">Weighted</th>
                          <th style="width: 170px;">Final Score</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach ($rows as $row): ?>
                          <?php
                            $studentName = !empty($row['full_name']) ? strtoupper($row['full_name']) : 'N/A';
                            $examinee = !empty($row['examinee_number']) ? $row['examinee_number'] : '--';
                            $programLabel = !empty($row['program_name']) ? $row['program_name'] : '';
                            if (!empty($row['major'])) {
                                $programLabel .= ' - ' . $row['major'];
                            }
                          ?>
                          <tr>
                            <td><span class="fw-semibold"><?= (int) $row['log_id']; ?></span></td>
                            <td><?= htmlspecialchars((string) $row['created_at']); ?></td>
                            <td>
                              <span class="badge <?= action_badge_class($row['action']); ?>">
                                <?= htmlspecialchars((string) $row['action']); ?>
                              </span>
                            </td>
                            <td>
                              <div class="fw-semibold"><?= htmlspecialchars($studentName); ?></div>
                              <small class="text-muted">Examinee #: <?= htmlspecialchars((string) $examinee); ?></small>
                              <?php if (!empty($programLabel)): ?>
                                <div><small class="text-muted"><?= htmlspecialchars($programLabel); ?></small></div>
                              <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars((string) ($row['component_name'] ?? 'FINAL SCORE')); ?></td>
                            <td><?= htmlspecialchars(format_delta($row['old_raw'], $row['new_raw'])); ?></td>
                            <td><?= htmlspecialchars(format_delta($row['old_weighted'], $row['new_weighted'])); ?></td>
                            <td><?= htmlspecialchars(format_delta($row['final_before'], $row['final_after'], '%')); ?></td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                <?php endif; ?>
              </div>

              <?php if ($totalPages > 1): ?>
                <div class="card-footer d-flex justify-content-between align-items-center">
                  <small class="text-muted">
                    Page <?= (int) $page; ?> of <?= (int) $totalPages; ?>
                  </small>
                  <div class="btn-group">
                    <a
                      class="btn btn-sm btn-outline-secondary <?= $page <= 1 ? 'disabled' : ''; ?>"
                      href="activity_logs.php?page=<?= max(1, $page - 1); ?>"
                    >
                      Previous
                    </a>
                    <a
                      class="btn btn-sm btn-outline-secondary <?= $page >= $totalPages ? 'disabled' : ''; ?>"
                      href="activity_logs.php?page=<?= min($totalPages, $page + 1); ?>"
                    >
                      Next
                    </a>
                  </div>
                </div>
              <?php endif; ?>
            </div>

            <div class="modal fade" id="activityLogDetailModal" tabindex="-1" aria-hidden="true">
              <div class="modal-dialog modal-xl modal-dialog-scrollable">
                <div class="modal-content">
                  <div class="modal-header">
                    <h5 class="modal-title" id="activityLogDetailTitle">Activity Details</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                  </div>
                  <div class="modal-body">
                    <div id="activityLogDetailState" class="text-muted">
                      Select a chart point to load activity details.
                    </div>

                    <div class="table-responsive d-none" id="activityLogDetailTableWrap">
                      <table class="table table-sm table-hover align-middle mb-0">
                        <thead class="table-light">
                          <tr>
                            <th style="width: 176px;">Timestamp</th>
                            <th style="width: 164px;">Action</th>
                            <th>Student</th>
                            <th style="width: 150px;">Component</th>
                            <th style="width: 140px;">Raw</th>
                            <th style="width: 150px;">Weighted</th>
                            <th style="width: 150px;">Final Score</th>
                          </tr>
                        </thead>
                        <tbody id="activityLogDetailTableBody"></tbody>
                      </table>
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
    (function () {
      const chartEl = document.getElementById('activityTrendChart');
      const activityChartPayload = <?= json_encode($chartPayload, JSON_UNESCAPED_SLASHES); ?>;
      const actionLabelMap = <?= json_encode($actionLabelMap, JSON_UNESCAPED_SLASHES); ?>;

      const detailModalEl = document.getElementById('activityLogDetailModal');
      const detailTitleEl = document.getElementById('activityLogDetailTitle');
      const detailStateEl = document.getElementById('activityLogDetailState');
      const detailTableWrapEl = document.getElementById('activityLogDetailTableWrap');
      const detailTableBodyEl = document.getElementById('activityLogDetailTableBody');
      const detailModal = detailModalEl ? new bootstrap.Modal(detailModalEl) : null;

      function escapeHtml(value) {
        const str = value === null || value === undefined ? '' : String(value);
        return str
          .replace(/&/g, '&amp;')
          .replace(/</g, '&lt;')
          .replace(/>/g, '&gt;')
          .replace(/\"/g, '&quot;')
          .replace(/'/g, '&#039;');
      }

      function actionBadgeClass(action) {
        if (action === 'SCORE_SAVE') return 'bg-label-success';
        if (action === 'SCORE_UPDATE') return 'bg-label-warning';
        if (action === 'FINAL_SCORE_UPDATE') return 'bg-label-info';
        return 'bg-label-secondary';
      }

      function showDetailMessage(type, message) {
        if (!detailStateEl) return;
        detailStateEl.classList.remove('d-none');
        detailStateEl.innerHTML = '<div class="alert alert-' + type + ' mb-0">' + escapeHtml(message) + '</div>';
      }

      async function loadDetailRows(dateLabel, actionKey) {
        if (!detailModal || !detailTitleEl || !detailStateEl || !detailTableWrapEl || !detailTableBodyEl) {
          return;
        }

        const actionLabel = actionLabelMap[actionKey] || actionKey;
        detailTitleEl.textContent = actionLabel + ' - ' + dateLabel;
        detailTableBodyEl.innerHTML = '';
        detailTableWrapEl.classList.add('d-none');
        detailStateEl.classList.remove('d-none');
        detailStateEl.innerHTML =
          '<div class="d-flex align-items-center text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading activity details...</div>';

        detailModal.show();

        try {
          const response = await fetch(
            'get_activity_log_drilldown.php?date=' +
              encodeURIComponent(dateLabel) +
              '&action=' +
              encodeURIComponent(actionKey),
            {
              method: 'GET',
              headers: { Accept: 'application/json' }
            }
          );

          let payload = null;
          try {
            payload = await response.json();
          } catch (jsonError) {
            payload = null;
          }

          if (!response.ok || !payload || !payload.success) {
            throw new Error((payload && payload.message) || 'Failed to load activity details.');
          }

          if (!Array.isArray(payload.rows) || payload.rows.length === 0) {
            showDetailMessage('info', 'No activity details found for this data point.');
            return;
          }

          const tableRowsHtml = payload.rows
            .map(function (row) {
              const studentName = row.student_name ? escapeHtml(row.student_name) : 'N/A';
              const examineeNumber = row.examinee_number ? escapeHtml(row.examinee_number) : '--';
              const programText = row.program ? '<div><small class="text-muted">' + escapeHtml(row.program) + '</small></div>' : '';

              return (
                '<tr>' +
                  '<td class="text-nowrap">' + escapeHtml(row.timestamp || '--') + '</td>' +
                  '<td><span class="badge ' + actionBadgeClass(row.action) + '">' + escapeHtml(row.action_label || row.action || '--') + '</span></td>' +
                  '<td>' +
                    '<div class="fw-semibold">' + studentName + '</div>' +
                    '<small class="text-muted">Examinee #: ' + examineeNumber + '</small>' +
                    programText +
                  '</td>' +
                  '<td>' + escapeHtml(row.component || 'FINAL SCORE') + '</td>' +
                  '<td>' + escapeHtml(row.raw_delta || '--') + '</td>' +
                  '<td>' + escapeHtml(row.weighted_delta || '--') + '</td>' +
                  '<td>' + escapeHtml(row.final_delta || '--') + '</td>' +
                '</tr>'
              );
            })
            .join('');

          detailTableBodyEl.innerHTML = tableRowsHtml;
          detailStateEl.classList.add('d-none');
          detailTableWrapEl.classList.remove('d-none');
        } catch (error) {
          showDetailMessage('danger', error && error.message ? error.message : 'Failed to load activity details.');
        }
      }

      if (chartEl && window.ApexCharts && activityChartPayload && Array.isArray(activityChartPayload.categories)) {
        const seriesMeta = Array.isArray(activityChartPayload.series) ? activityChartPayload.series : [];
        if (seriesMeta.length === 0) return;

        const series = seriesMeta.map(function (item) {
          return {
            name: item.name,
            data: item.data,
            color: item.color
          };
        });
        const actionKeysBySeries = seriesMeta.map(function (item) { return item.key; });

        const chart = new ApexCharts(chartEl, {
          chart: {
            type: 'line',
            height: 340,
            toolbar: { show: false },
            zoom: { enabled: false },
            events: {
              dataPointSelection: function (event, chartContext, config) {
                if (!config) return;

                const seriesIndex = Number(config.seriesIndex);
                const dataPointIndex = Number(config.dataPointIndex);
                if (seriesIndex < 0 || dataPointIndex < 0) return;

                const actionKey = actionKeysBySeries[seriesIndex];
                const dateLabel = activityChartPayload.categories[dataPointIndex];
                if (!actionKey || !dateLabel) return;

                loadDetailRows(dateLabel, actionKey);
              }
            }
          },
          stroke: {
            curve: 'smooth',
            width: 3
          },
          dataLabels: {
            enabled: false
          },
          markers: {
            size: 4,
            hover: {
              size: 6
            }
          },
          legend: {
            position: 'top',
            horizontalAlign: 'left'
          },
          grid: {
            borderColor: '#eceff5'
          },
          xaxis: {
            categories: activityChartPayload.categories,
            labels: {
              rotate: -45,
              trim: true
            }
          },
          yaxis: {
            min: 0,
            forceNiceScale: true,
            labels: {
              formatter: function (value) {
                return String(Math.max(0, Math.round(Number(value) || 0)));
              }
            },
            title: {
              text: 'Actions'
            }
          },
          tooltip: {
            shared: false,
            intersect: true,
            y: {
              formatter: function (value) {
                const normalized = Math.max(0, Math.round(Number(value) || 0));
                return normalized + (normalized === 1 ? ' action' : ' actions');
              }
            }
          },
          series: series
        });

        chart.render();
      }
    })();
  </script>
</body>
</html>
