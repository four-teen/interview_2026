<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

if (empty($_SESSION['db_management_csrf'])) {
    $_SESSION['db_management_csrf'] = bin2hex(random_bytes(32));
}

$priorityTables = [
    'tbl_interview_scores',
    'tbl_student_interview',
    'tbl_score_audit_logs',
    'tbl_student_transfer_history'
];
$priorityTablePositions = array_flip($priorityTables);

$dbManagementMessage = '';
$dbManagementMessageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = (string) ($_POST['csrf_token'] ?? '');
    $requestedTable = trim((string) ($_POST['table_name'] ?? ''));
    $knownTables = [];

    $tableListResult = $conn->query('SHOW TABLES');
    if ($tableListResult) {
        while ($tableRow = $tableListResult->fetch_row()) {
            if (isset($tableRow[0])) {
                $knownTables[] = (string) $tableRow[0];
            }
        }
    }

    if (!hash_equals((string) $_SESSION['db_management_csrf'], $postedToken)) {
        $dbManagementMessage = 'Invalid security token. Please refresh and try again.';
        $dbManagementMessageType = 'danger';
    } elseif ($requestedTable === '' || !in_array($requestedTable, $knownTables, true)) {
        $dbManagementMessage = 'Invalid table selected.';
        $dbManagementMessageType = 'danger';
    } elseif (!preg_match('/^[A-Za-z0-9_]+$/', $requestedTable)) {
        $dbManagementMessage = 'Table name validation failed.';
        $dbManagementMessageType = 'danger';
    } else {
        $escapedTable = '`' . str_replace('`', '``', $requestedTable) . '`';
        $truncateSql = "TRUNCATE TABLE {$escapedTable}";

        if ($conn->query($truncateSql) === true) {
            $dbManagementMessage = "Cleared all records from {$requestedTable}.";
            $dbManagementMessageType = 'success';
        } else {
            $deleteSql = "DELETE FROM {$escapedTable}";
            if ($conn->query($deleteSql) === true) {
                $conn->query("ALTER TABLE {$escapedTable} AUTO_INCREMENT = 1");
                $dbManagementMessage = "Cleared all records from {$requestedTable} (using DELETE fallback).";
                $dbManagementMessageType = 'success';
            } else {
                error_log(
                    "DB management clear failed for table {$requestedTable}: " . $conn->error
                );
                $dbManagementMessage = 'Failed to clear table records. Check relationships and DB permissions.';
                $dbManagementMessageType = 'danger';
            }
        }
    }
}

$tableStats = [];
$tableStatsResult = $conn->query('SHOW TABLE STATUS');

if ($tableStatsResult) {
    while ($statusRow = $tableStatsResult->fetch_assoc()) {
        $tableStats[] = [
            'name' => (string) ($statusRow['Name'] ?? ''),
            'rows' => (int) ($statusRow['Rows'] ?? 0),
            'engine' => (string) ($statusRow['Engine'] ?? ''),
            'collation' => (string) ($statusRow['Collation'] ?? ''),
            'updated' => (string) ($statusRow['Update_time'] ?? '')
        ];
    }
}

$tableNameSet = [];
foreach ($tableStats as $table) {
    $tableNameSet[$table['name']] = true;
}

$existingPriorityTables = [];
foreach ($priorityTables as $tableName) {
    if (isset($tableNameSet[$tableName])) {
        $existingPriorityTables[] = $tableName;
    }
}

$recommendedDeleteOrderMap = [];
$deleteOrderFootnote = '';

if (!empty($existingPriorityTables)) {
    $quotedPriority = array_map(static function (string $tableName) use ($conn): string {
        return "'" . $conn->real_escape_string($tableName) . "'";
    }, $existingPriorityTables);
    $priorityInSql = implode(',', $quotedPriority);

    $fkSql = "
        SELECT DISTINCT
            TABLE_NAME AS child_table,
            REFERENCED_TABLE_NAME AS parent_table
        FROM information_schema.KEY_COLUMN_USAGE
        WHERE TABLE_SCHEMA = DATABASE()
          AND REFERENCED_TABLE_NAME IS NOT NULL
          AND TABLE_NAME IN ({$priorityInSql})
          AND REFERENCED_TABLE_NAME IN ({$priorityInSql})
    ";

    $fkResult = $conn->query($fkSql);

    $adjacency = [];
    $indegree = [];
    $seenEdges = [];
    foreach ($existingPriorityTables as $tableName) {
        $adjacency[$tableName] = [];
        $indegree[$tableName] = 0;
    }

    if ($fkResult) {
        while ($fkRow = $fkResult->fetch_assoc()) {
            $child = (string) ($fkRow['child_table'] ?? '');
            $parent = (string) ($fkRow['parent_table'] ?? '');

            if (!isset($adjacency[$child], $adjacency[$parent])) {
                continue;
            }

            $edgeKey = $child . '->' . $parent;
            if (isset($seenEdges[$edgeKey])) {
                continue;
            }

            $seenEdges[$edgeKey] = true;
            $adjacency[$child][] = $parent;
            $indegree[$parent]++;
        }
    }

    $queue = [];
    foreach ($existingPriorityTables as $tableName) {
        if ($indegree[$tableName] === 0) {
            $queue[] = $tableName;
        }
    }

    $sortedDeleteOrder = [];
    while (!empty($queue)) {
        $current = array_shift($queue);
        $sortedDeleteOrder[] = $current;

        foreach ($adjacency[$current] as $next) {
            $indegree[$next]--;
            if ($indegree[$next] === 0) {
                $queue[] = $next;
            }
        }
    }

    if (count($sortedDeleteOrder) < count($existingPriorityTables)) {
        foreach ($existingPriorityTables as $tableName) {
            if (!in_array($tableName, $sortedDeleteOrder, true)) {
                $sortedDeleteOrder[] = $tableName;
            }
        }
    }

    foreach ($sortedDeleteOrder as $idx => $tableName) {
        $recommendedDeleteOrderMap[$tableName] = $idx + 1;
    }

    if (empty($seenEdges)) {
        $deleteOrderFootnote = 'No FK constraints detected among pinned tables. Order uses pinned sequence.';
    }
}

usort($tableStats, static function (array $a, array $b) use ($priorityTablePositions): int {
    $aPinned = isset($priorityTablePositions[$a['name']]);
    $bPinned = isset($priorityTablePositions[$b['name']]);

    if ($aPinned && $bPinned) {
        return $priorityTablePositions[$a['name']] <=> $priorityTablePositions[$b['name']];
    }

    if ($aPinned !== $bPinned) {
        return $aPinned ? -1 : 1;
    }

    return strcmp($a['name'], $b['name']);
});
?>
<!DOCTYPE html>
<html
  lang="en"
  class="light-style layout-menu-fixed"
  dir="ltr"
  data-theme="theme-default"
  data-assets-path="../../assets/"
  data-template="vertical-menu-template-free"
>
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no" />
  <title>DB Management | Administrator</title>
  <link rel="icon" type="image/x-icon" href="../../assets/img/favicon/favicon.ico" />
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link
    href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
    rel="stylesheet"
  />
  <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />
  <link rel="stylesheet" href="../../assets/vendor/css/core.css" />
  <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" />
  <link rel="stylesheet" href="../../assets/css/demo.css" />
  <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />
  <script src="../../assets/vendor/js/helpers.js"></script>
  <script src="../../assets/js/config.js"></script>
</head>
<body>
<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">
    <?php include '../sidebar.php'; ?>

    <div class="layout-page">
      <?php include '../header.php'; ?>

      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">
          <div class="card">
            <div class="card-header d-flex justify-content-between align-items-start flex-wrap gap-2">
              <div>
                <h5 class="mb-1">Database Management</h5>
                <small class="text-muted">View tables and clear records per table. Lower order number means clear first.</small>
              </div>
              <span class="badge bg-label-warning">
                Dangerous Action: Clear table removes all records.
              </span>
            </div>
            <div class="card-body">
              <?php if ($deleteOrderFootnote !== ''): ?>
                <div class="alert alert-warning mb-3" role="alert">
                  <?= htmlspecialchars($deleteOrderFootnote); ?>
                </div>
              <?php endif; ?>

              <?php if ($dbManagementMessage !== ''): ?>
                <div class="alert alert-<?= htmlspecialchars($dbManagementMessageType); ?> mb-3" role="alert">
                  <?= htmlspecialchars($dbManagementMessage); ?>
                </div>
              <?php endif; ?>

              <div class="table-responsive">
                <table class="table table-sm table-hover align-middle">
                  <thead>
                    <tr>
                      <th>Table</th>
                      <th>Clear Order</th>
                      <th>Rows</th>
                      <th>Engine</th>
                      <th>Collation</th>
                      <th>Updated</th>
                      <th class="text-end">Action</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php if (empty($tableStats)): ?>
                      <tr>
                        <td colspan="7" class="text-center text-muted py-4">No tables found.</td>
                      </tr>
                    <?php else: ?>
                      <?php foreach ($tableStats as $table): ?>
                        <tr>
                          <td>
                            <code><?= htmlspecialchars($table['name']); ?></code>
                            <?php if (isset($priorityTablePositions[$table['name']])): ?>
                              <span class="badge bg-label-info ms-2">Pinned</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <?php if (isset($recommendedDeleteOrderMap[$table['name']])): ?>
                              <span class="badge bg-label-danger">#<?= (int) $recommendedDeleteOrderMap[$table['name']]; ?></span>
                            <?php else: ?>
                              <span class="text-muted">-</span>
                            <?php endif; ?>
                          </td>
                          <td><?= number_format((int) $table['rows']); ?></td>
                          <td><?= htmlspecialchars($table['engine'] !== '' ? $table['engine'] : '-'); ?></td>
                          <td><?= htmlspecialchars($table['collation'] !== '' ? $table['collation'] : '-'); ?></td>
                          <td><?= htmlspecialchars($table['updated'] !== '' ? $table['updated'] : '-'); ?></td>
                          <td class="text-end">
                            <form
                              method="post"
                              class="d-inline-block js-clear-table-form"
                              data-table="<?= htmlspecialchars($table['name']); ?>"
                            >
                              <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['db_management_csrf']); ?>">
                              <input type="hidden" name="table_name" value="<?= htmlspecialchars($table['name']); ?>">
                              <button type="submit" class="btn btn-sm btn-outline-danger">
                                Clear Records
                              </button>
                            </form>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    <?php endif; ?>
                  </tbody>
                </table>
              </div>
            </div>
          </div>
        </div>

        <?php include '../../footer.php'; ?>
        <div class="content-backdrop fade"></div>
      </div>
    </div>
  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/libs/popper/popper.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>
<script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../../assets/vendor/js/menu.js"></script>
<script src="../../assets/js/main.js"></script>
<script>
  document.querySelectorAll('.js-clear-table-form').forEach(function (formEl) {
    formEl.addEventListener('submit', function (event) {
      const tableName = this.dataset.table || 'this table';
      const confirmed = window.confirm(
        'Clear all records from "' + tableName + '"? This action cannot be undone.'
      );

      if (!confirmed) {
        event.preventDefault();
      }
    });
  });
</script>
</body>
</html>
