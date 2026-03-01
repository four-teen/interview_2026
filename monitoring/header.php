<?php
if (!defined('BASE_URL')) {
    require_once __DIR__ . '/../config/env.php';
    define('BASE_URL', getenv('BASE_URL') ?: '/interview');
}

$monitoringEmail = trim((string) ($_SESSION['email'] ?? ''));
if ($monitoringEmail === '') {
    $monitoringEmail = 'No email on file';
}

$monitoringRoleRaw = trim((string) ($_SESSION['role'] ?? 'monitoring'));
$monitoringRole = ucwords(str_replace(['_', '-'], ' ', strtolower($monitoringRoleRaw)));
?>

<nav
  class="layout-navbar container-xxl navbar navbar-expand-xl navbar-detached align-items-center bg-navbar-theme"
  id="layout-navbar">
  <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
    <a class="nav-item nav-link px-0 me-xl-4" href="javascript:void(0)">
      <i class="bx bx-menu bx-sm"></i>
    </a>
  </div>

  <div class="navbar-nav-right d-flex align-items-center w-100" id="navbar-collapse">
    <div class="navbar-nav align-items-center flex-grow-1 me-3">
      <div class="nav-item d-flex align-items-center w-100">
        <i class="bx bx-bar-chart-square fs-4 lh-0"></i>
        <input
          type="text"
          class="form-control border-0 shadow-none w-100"
          style="max-width: 42rem;"
          value="Monitoring Center - All Programs"
          readonly
        />
      </div>
    </div>

    <ul class="navbar-nav flex-row align-items-center ms-auto">
      <li class="nav-item lh-1 me-3 d-none d-lg-flex flex-column align-items-end">
        <span class="fw-semibold small"><?= htmlspecialchars($monitoringEmail); ?></span>
        <small class="text-muted"><?= htmlspecialchars($monitoringRole); ?></small>
      </li>

      <li class="nav-item navbar-dropdown dropdown-user dropdown">
        <a class="nav-link dropdown-toggle hide-arrow" href="javascript:void(0);" data-bs-toggle="dropdown">
          <div class="avatar avatar-online">
            <img src="<?= BASE_URL ?>/assets/img/avatars/1.png" alt class="w-px-40 h-auto rounded-circle" />
          </div>
        </a>
        <ul class="dropdown-menu dropdown-menu-end">
          <li>
            <a class="dropdown-item" href="#">
              <div class="d-flex">
                <div class="flex-shrink-0 me-3">
                  <div class="avatar avatar-online">
                    <img src="<?= BASE_URL ?>/assets/img/avatars/1.png" alt class="w-px-40 h-auto rounded-circle" />
                  </div>
                </div>
                <div class="flex-grow-1">
                  <span class="fw-semibold d-block"><?= htmlspecialchars($monitoringEmail); ?></span>
                  <small class="text-muted"><?= htmlspecialchars($monitoringRole); ?></small>
                </div>
              </div>
            </a>
          </li>
          <li><div class="dropdown-divider"></div></li>
          <li>
            <a class="dropdown-item" href="<?= BASE_URL ?>/logout.php">
              <i class="bx bx-power-off me-2"></i>
              <span class="align-middle">Log Out</span>
            </a>
          </li>
        </ul>
      </li>
    </ul>
  </div>
</nav>
