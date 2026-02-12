<?php

require_once '../../config/db.php';

$LIMIT = 20;

$sql = "
  SELECT 
    a.accountid,
    a.acc_fullname,
    a.email,
    a.role,
    a.status,

    a.campus_id,
    c.campus_name,

    a.program_id,
    CONCAT(
      p.program_name,
      IF(p.major IS NOT NULL AND p.major <> '', CONCAT(' – ', p.major), '')
    ) AS program_display

  FROM tblaccount a
  LEFT JOIN tbl_campus c 
    ON a.campus_id = c.campus_id

  LEFT JOIN tbl_program p 
    ON a.program_id = p.program_id

  ORDER BY a.acc_fullname
  LIMIT $LIMIT
";



$result = $conn->query($sql);

$accounts = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $accounts[] = $row;
    }
}



session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
  header('Location: ../../index.php');
  exit;
}

$fullname = $_SESSION['fullname'] ?? 'Administrator';
$email    = $_SESSION['email'] ?? '';
$role     = $_SESSION['role'] ?? 'administrator';
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

  <title>User Accounts | Administrator</title>
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

<style>
  .swal2-container { z-index: 20000 !important; }
</style>

</head>

<body>

<div class="layout-wrapper layout-content-navbar">
  <div class="layout-container">

    <!-- SIDEBAR -->
      <?php 
        include '../sidebar.php';
      ?>
    <!-- /SIDEBAR -->

    <!-- MAIN PAGE -->
    <div class="layout-page">

      <!-- NAVBAR -->
        <?php 
          include '../header.php';
        ?>
      <!-- /NAVBAR -->

      <!-- CONTENT -->
      <div class="content-wrapper">
        <div class="container-xxl flex-grow-1 container-p-y">

          <div class="row">

            <!-- LEFT: Accounts table -->
            <div class="col-lg-8 mb-4">
              <div class="card" style="padding: 15px;">

<div class="card-header">
  <div class="row align-items-center g-2">

    <!-- LEFT: Title -->
    <div class="col-12 col-md">
      <h5 class="mb-0">Account Registry</h5>
      <small class="text-muted">
        Fields aligned to <code>tblaccount</code> (UI only).
      </small>
    </div>

    <!-- MIDDLE: Search -->
    <div class="col-12 col-md-4 col-lg-3">
      <input
        type="text"
        id="accountSearch"
        class="form-control form-control-sm"
        placeholder="Search name or email..."
      />
    </div>

    <!-- RIGHT: Button -->
    <div class="col-12 col-md-auto text-md-end">
      <button
        type="button"
        class="btn btn-primary btn-sm"
        data-bs-toggle="modal"
        data-bs-target="#addAccountModal"
      >
        <i class="bx bx-plus me-1"></i> Add Account
      </button>
    </div>

  </div>
</div>


            <div class="row g-3" id="accountsList">

                <?php foreach ($accounts as $acc): ?>
                  <div class="col-12 account-row">
                    <div class="card border">
                      <div class="card-body d-flex align-items-center justify-content-between gap-3">

                      <div class="d-flex align-items-center gap-3 flex-grow-1">

                        <div class="flex-grow-1">
                          <div class="fw-semibold">
                            <?= htmlspecialchars($acc['acc_fullname']) ?>
                          </div>

                          <div class="text-muted small">
                            <?= htmlspecialchars($acc['email']) ?>
                          </div>
                        </div>

                        <div class="d-flex gap-2">
                          <span class="badge bg-label-<?= $acc['role']=='administrator'?'primary':($acc['role']=='progchair'?'info':'warning') ?>">
                            <?= $acc['role'] ?>
                          </span>

                          <span class="badge bg-label-<?= $acc['status']=='active'?'success':'secondary' ?>">
                            <?= $acc['status'] ?>
                          </span>
                        </div>

                          <div class="small text-muted d-none d-md-block">
                            Campus: <?= htmlspecialchars($acc['campus_name'] ?? '—') ?>
                            <br>Program: <?= htmlspecialchars($acc['program_display'] ?? '—') ?>
                          </div>

                      </div>


                        <div class="dropdown">
                          <button class="btn btn-sm btn-icon" data-bs-toggle="dropdown">
                            <i class="bx bx-dots-vertical-rounded"></i>
                          </button>
                          <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                              <a 
                                class="dropdown-item btn-edit-account"
                                href="javascript:void(0);"
                                data-id="<?= $acc['accountid'] ?>"
                                data-name="<?= htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) ?>"
                                data-email="<?= htmlspecialchars($acc['email'], ENT_QUOTES) ?>"
                                data-role="<?= $acc['role'] ?>"
                                data-campus="<?= $acc['campus_id'] ?>"
                                data-program="<?= $acc['program_id'] ?>"
                                data-status="<?= $acc['status'] ?>"
                              >
                                <i class="bx bx-edit-alt me-2"></i> Edit
                              </a>

                            </li>
                            <li>
                              <a 
                                class="dropdown-item text-danger btn-delete-account"
                                href="javascript:void(0);"
                                data-id="<?= $acc['accountid'] ?>"
                                data-name="<?= htmlspecialchars($acc['acc_fullname'], ENT_QUOTES) ?>"
                              >
                                <i class="bx bx-trash me-2"></i> Delete
                              </a>
                            </li>

                          </ul>
                        </div>

                      </div>
                    </div>
                  </div>




                <?php endforeach; ?>

            </div>

<!-- Infinite scroll loader (UI only) -->
<div id="accountLoader" class="text-center py-3 text-muted small" style="display:none;">
  Loading more accounts...
</div>



              </div>
            </div>

            <!-- RIGHT: Campus “wire” cards -->
            <div class="col-lg-4 mb-4">
              <div class="card h-100">
                <div class="card-header">
                  <h5 class="mb-0">Campus Filters</h5>
                  <small class="text-muted">
                    Use these cards to scope accounts per campus (UI only).
                  </small>
                </div>

                <div class="card-body">
                  <div class="d-grid gap-2">

                    <!-- UI placeholders; we will load real campus list later -->
                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 1
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 2
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 3
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 4
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 5
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 6
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                    <button type="button" class="btn btn-outline-primary text-start">
                      <i class="bx bx-buildings me-2"></i> Campus 7
                      <span class="badge bg-label-primary float-end">—</span>
                    </button>

                  </div>

                  <hr class="my-4" />

                  <div class="small text-muted">
                    <div class="d-flex justify-content-between mb-2">
                      <span>Role options</span>
                      <span class="fw-semibold">administrator · progchair · monitoring</span>
                    </div>
                    <div class="d-flex justify-content-between">
                      <span>Status options</span>
                      <span class="fw-semibold">active · inactive</span>
                    </div>
                  </div>

                </div>
              </div>
            </div>

          </div>

        </div>

<div class="modal fade" id="addAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form method="post" action="save_account.php">

        <div class="modal-header">
          <h5 class="modal-title">Add User Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body row g-3">

          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="acc_fullname" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role" class="form-select" required>
              <option value="administrator">Administrator</option>
              <option value="progchair">Program Chair</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Campus</label>
            <select name="campus_id" id="add_campus" class="form-select">
              <!-- to be populated from tbl_campus -->
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Program</label>
            <select name="program_id" id="add_program" class="form-select">
              <!-- to be populated from tbl_program -->
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Account</button>
        </div>

      </form>

    </div>
  </div>
</div>

<!-- EDIT ACCOUNT MODAL -->
<div class="modal fade" id="editAccountModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-centered">
    <div class="modal-content">

      <form id="editAccountForm">

        <input type="hidden" name="accountid" id="edit_accountid">

        <div class="modal-header">
          <h5 class="modal-title">Edit User Account</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>

        <div class="modal-body row g-3">

          <div class="col-md-6">
            <label class="form-label">Full Name</label>
            <input type="text" name="acc_fullname" id="edit_fullname" class="form-control" required>
          </div>

          <div class="col-md-6">
            <label class="form-label">Email</label>
            <input type="email" name="email" id="edit_email" class="form-control" required>
          </div>

          <div class="col-md-4">
            <label class="form-label">Role</label>
            <select name="role" id="edit_role" class="form-select" required>
              <option value="administrator">Administrator</option>
              <option value="progchair">Program Chair</option>
              <option value="monitoring">Monitoring</option>
            </select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Campus</label>
            <select name="campus_id" id="edit_campus" class="form-select"></select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Program</label>
            <select name="program_id" id="edit_program" class="form-select"></select>
          </div>

          <div class="col-md-4">
            <label class="form-label">Status</label>
            <select name="status" id="edit_status" class="form-select">
              <option value="active">Active</option>
              <option value="inactive">Inactive</option>
            </select>
          </div>

        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Update Account</button>
        </div>

      </form>

    </div>
  </div>
</div>

<!-- DELETE CONFIRM MODAL -->
<div class="modal fade" id="deleteAccountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">

      <input type="hidden" id="delete_accountid">

      <div class="modal-header">
        <h5 class="modal-title text-danger">Delete Account</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>

      <div class="modal-body">
        Are you sure you want to delete
        <strong id="delete_account_name"></strong>?
        <div class="text-muted small mt-1">
          This action cannot be undone.
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">
          Cancel
        </button>
        <button type="button" class="btn btn-danger" id="confirmDeleteAccount">
          Delete
        </button>
      </div>

    </div>
  </div>
</div>



        <!-- FOOTER -->
        <footer class="content-footer footer bg-footer-theme">
          <div class="container-xxl d-flex flex-wrap justify-content-between py-2 flex-md-row flex-column">
            <div class="mb-2 mb-md-0">
              © <?php echo date('Y'); ?> Sultan Kudarat State University
            </div>
            <div class="small text-muted">
              Centralized Interview System · Administrator
            </div>
          </div>
        </footer>

        <div class="content-backdrop fade"></div>
      </div>
      <!-- /CONTENT -->

    </div>
    <!-- /MAIN PAGE -->

  </div>

  <div class="layout-overlay layout-menu-toggle"></div>
</div>

<!-- CORE JS -->
<script src="../../assets/vendor/libs/jquery/jquery.js"></script>
<script src="../../assets/vendor/libs/popper/popper.js"></script>
<script src="../../assets/vendor/js/bootstrap.js"></script>
<script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>
<script src="../../assets/vendor/js/menu.js"></script>
<script src="../../assets/js/main.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<?php if (isset($_SESSION['success'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'success',
    title: 'Success',
    text: <?= json_encode($_SESSION['success']) ?>,
    confirmButtonColor: '#696cff'
  });
});
</script>
<?php unset($_SESSION['success']); endif; ?>

<?php if (isset($_SESSION['error'])): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
  Swal.fire({
    icon: 'error',
    title: 'Error',
    text: <?= json_encode($_SESSION['error']) ?>,
    confirmButtonColor: '#ff3e1d'
  });
});
</script>
<?php unset($_SESSION['error']); endif; ?>


<script>
  let loadingAccounts = false;
  let offset = <?= (int)$LIMIT ?>; // start after initial batch
  const limit = <?= (int)$LIMIT ?>;
  let endReached = false;

let activeRequest = null;

function loadMoreAccounts() {
  if (loadingAccounts || endReached) return;

  loadingAccounts = true;
  $('#accountLoader').show().text('Loading more accounts...');

  const q = ($('#accountSearch').val() || '').trim();

  // abort previous request if still running (prevents race conditions)
  if (activeRequest && activeRequest.readyState !== 4) {
    activeRequest.abort();
  }

  activeRequest = $.get('fetch_accounts.php', { limit: limit, offset: offset, q: q })
    .done(function (html) {
      html = (html || '').trim();

      if (html.length === 0) {
        endReached = true;

        // IMPORTANT: differentiate "no search results" vs "end of all"
        if (q !== '' && offset === 0) {
          $('#accountLoader').show().text('No records found.');
        } else {
          $('#accountLoader').show().text('No more accounts.');
        }

        loadingAccounts = false;
        return;
      }

      $('#accountsList').append(html);
      offset += limit;
      $('#accountLoader').hide();
      loadingAccounts = false;
    })
    .fail(function (xhr, status) {
      if (status === 'abort') return; // ignore abort
      $('#accountLoader').show().text('Failed to load more accounts.');
      loadingAccounts = false;
    });
}


  $(window).on('scroll', function () {
    if ($(window).scrollTop() + $(window).height() >= $(document).height() - 120) {
      loadMoreAccounts();
    }
  });

/* SEARCH HANDLER */
let searchTimer = null;

$('#accountSearch').on('keyup', function () {
  clearTimeout(searchTimer);

  searchTimer = setTimeout(function () {

    // reset state
    offset = 0;
    endReached = false;
    loadingAccounts = false;

    // clear list + loader
    $('#accountsList').html('');
    $('#accountLoader').show().text('Loading more accounts...');

    // load first batch based on search
    loadMoreAccounts();

  }, 300);
});


  /* OPEN EDIT MODAL */
/* OPEN EDIT MODAL */
$(document).on('click', '.btn-edit-account', function () {

  const campusId  = $(this).data('campus');
  const programId = $(this).data('program');

  $('#edit_accountid').val($(this).data('id'));
  $('#edit_fullname').val($(this).data('name'));
  $('#edit_email').val($(this).data('email'));
  $('#edit_role').val($(this).data('role'));
  $('#edit_status').val($(this).data('status'));

  /* Load campuses */
  $.getJSON('fetch_campuses.php', function (data) {
    let html = '<option value="">— None —</option>';
    data.forEach(row => {
      html += `<option value="${row.campus_id}">${row.campus_name}</option>`;
    });
    $('#edit_campus').html(html).val(campusId);
  });

  /* Load programs */
  $.getJSON('fetch_programs.php', function (data) {
    let html = '<option value="">— None —</option>';
    data.forEach(row => {
html += `<option value="${row.program_id}">
  ${row.program_code}${row.major && row.major !== '' ? ' – ' + row.major : ''}
</option>`;
    });
    $('#edit_program').html(html).val(programId);
  });

  $('#editAccountModal').modal('show');
});


  /* OPEN DELETE MODAL */
  $(document).on('click', '.btn-delete-account', function () {
    $('#delete_accountid').val($(this).data('id'));
    $('#delete_account_name').text($(this).data('name'));
    $('#deleteAccountModal').modal('show');
  });

/* SUBMIT EDIT ACCOUNT */
$('#editAccountForm').on('submit', function (e) {
  e.preventDefault();

  $.ajax({
    url: 'update_account.php',
    method: 'POST',
    data: $(this).serialize(),
    dataType: 'json',
    success: function (res) {
      if (res.success) {
        Swal.fire({
          icon: 'success',
          title: 'Updated',
          text: res.message,
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          location.reload(); // safe reload (infinite scroll safe)
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Update failed',
          text: res.message
        });
      }
    },
    error: function () {
      Swal.fire({
        icon: 'error',
        title: 'Server error',
        text: 'Unable to update account'
      });
    }
  });
});

/* CONFIRM DELETE */
$('#confirmDeleteAccount').on('click', function () {
  const accountid = $('#delete_accountid').val();

  $.ajax({
    url: 'delete_account.php',
    method: 'POST',
    data: { accountid: accountid },
    dataType: 'json',
    success: function (res) {
      if (res.success) {
        Swal.fire({
          icon: 'success',
          title: 'Deleted',
          text: res.message,
          timer: 1500,
          showConfirmButton: false
        }).then(() => {
          location.reload();
        });
      } else {
        Swal.fire({
          icon: 'error',
          title: 'Delete failed',
          text: res.message
        });
      }
    },
    error: function () {
      Swal.fire({
        icon: 'error',
        title: 'Server error',
        text: 'Unable to delete account'
      });
    }
  });
});

$('#addAccountModal').on('shown.bs.modal', function () {

  /* Load campuses */
  $.getJSON('fetch_campuses.php', function (data) {
    let html = '<option value="">— Select Campus —</option>';
    data.forEach(row => {
      html += `<option value="${row.campus_id}">${row.campus_name}</option>`;
    });
    $('#add_campus').html(html);
  });

  /* Load programs */
  $.getJSON('fetch_programs.php', function (data) {
    let html = '<option value="">— Select Program —</option>';
    data.forEach(row => {
      html += `<option value="${row.program_id}">
        ${row.program_code}${row.major && row.major !== '' ? ' – ' + row.major : ''}
      </option>`;
    });
    $('#add_program').html(html);
  });

});


</script>



</body>
</html>
