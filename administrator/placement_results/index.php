<!DOCTYPE html>

<!-- =========================================================
placement_results/index.php
* Sneat - Bootstrap 5 HTML Admin Template
==============================================================
-->
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
    <meta
      name="viewport"
      content="width=device-width, initial-scale=1.0, user-scalable=no, minimum-scale=1.0, maximum-scale=1.0"
    />

    <title>Placement Test Results - Interview</title>

    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="../../assets/img/favicon/favicon.ico" />

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com" />
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
    <link
      href="https://fonts.googleapis.com/css2?family=Public+Sans:wght@300;400;500;600;700&display=swap"
      rel="stylesheet"
    />

    <!-- Icons. Uncomment required icon fonts -->
    <link rel="stylesheet" href="../../assets/vendor/fonts/boxicons.css" />

    <!-- Core CSS -->
    <link rel="stylesheet" href="../../assets/vendor/css/core.css" class="template-customizer-core-css" />
    <link rel="stylesheet" href="../../assets/vendor/css/theme-default.css" class="template-customizer-theme-css" />
    <link rel="stylesheet" href="../../assets/css/demo.css" />

    <!-- Vendors CSS -->
    <link rel="stylesheet" href="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css" />

    <link rel="stylesheet" href="../../assets/vendor/libs/apex-charts/apex-charts.css" />

    <script src="../../assets/vendor/js/helpers.js"></script>
    <script src="../../assets/js/config.js"></script>
  </head>

  <body>
    <!-- Layout wrapper -->
    <div class="layout-wrapper layout-content-navbar">
      <div class="layout-container">

        <!-- Menu -->
        <?php 
          include 'sidebar.php';

        ?>
        <!-- / Menu -->

        <!-- Layout container -->
        <div class="layout-page">

          <!-- Navbar -->
            <?php 
              include 'header.php';
            ?>
          <!-- / Navbar -->

          <!-- Content wrapper -->
          <div class="content-wrapper">

            <!-- MAIN CONTENT -->
            <div class="container-xxl flex-grow-1 container-p-y">

              <h4 class="fw-bold mb-2">
                <span class="text-muted fw-light">Interview Settings /</span> Placement Test Results
              </h4>

              <p class="text-muted">
                Upload tertiary placement test results from CSV (.csv). All examinee names will be converted to
                <strong>UPPERCASE</strong>. Existing records will be removed before saving the new dataset.
              </p>

              <div class="alert alert-warning">
                <strong>Warning:</strong> Uploading a new file will permanently remove all existing placement test results.
                Duplicate examinee numbers will be reported in the summary.
              </div>

              <div class="row">
                <!-- Upload Card -->
                <div class="col-lg-8 mb-4">
                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-0">Upload Placement Test Results</h5>
                    </div>
                    <div class="card-body">

                      <div class="mb-3">
                        <label class="form-label">CSV File (.csv)</label>
                        <input type="file" class="form-control" id="csvFile" accept=".csv">
                        <div class="form-text">
                          Required columns (CSV, UTF-8):
                          Examinee Number, Name of Examinee, Overall SAT, Qualitative Interpretation
                        </div>
                      </div>

                      <div class="d-flex justify-content-end gap-2">
                        <button class="btn btn-outline-secondary" id="btnClear" disabled>Clear</button>
                        <button class="btn btn-primary" id="btnStartUpload" disabled>Start Upload</button>
                      </div>

                      <!-- Progress -->
                      <div class="mt-4 d-none" id="uploadProgressWrap">
                        <div class="d-flex justify-content-between mb-1">
                          <small id="progressStatus">Processing...</small>
                          <small id="progressPercent">0%</small>
                        </div>
                        <div class="progress">
                          <div class="progress-bar" id="progressBar" style="width:0%"></div>
                        </div>
                        <small class="text-muted" id="progressMeta">Processed: 0 / 0</small>
                      </div>

                    </div>
                  </div>
                </div>

                <!-- Mapping Card -->
                <div class="col-lg-4 mb-4">
                  <div class="card">
                    <div class="card-header">
                      <h5 class="mb-0">Qualitative Mapping</h5>
                    </div>
                    <div class="card-body">
                      <ul class="list-group">
                        <li class="list-group-item d-flex justify-content-between"><span>OUTSTANDING</span><strong>1</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>ABOVE AVERAGE</span><strong>2</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>HIGH AVERAGE</span><strong>3</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>MIDDLE AVERAGE</span><strong>4</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>LOW AVERAGE</span><strong>5</strong></li>
                        <li class="list-group-item d-flex justify-content-between"><span>BELOW AVERAGE</span><strong>6</strong></li>
                      </ul>
                    </div>
                  </div>
                </div>
              </div>

            </div>
            <!-- / MAIN CONTENT -->

            <!-- Footer -->
              <?php 
                include '../../footer.php';

              ?>
            <!-- / Footer -->

            <div class="content-backdrop fade"></div>
          </div>
          <!-- Content wrapper -->
        </div>
        <!-- / Layout page -->
      </div>
    </div>

    <!-- build:js assets/vendor/js/core.js -->
    <script src="../../assets/vendor/libs/jquery/jquery.js"></script>
    <script src="../../assets/vendor/libs/popper/popper.js"></script>
    <script src="../../assets/vendor/js/bootstrap.js"></script>
    <script src="../../assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.js"></script>

    <script src="../../assets/vendor/js/menu.js"></script>
    <!-- endbuild -->

    <!-- Vendors JS -->
    <script src="../../assets/vendor/libs/apex-charts/apexcharts.js"></script>

    <!-- Main JS -->
    <script src="../../assets/js/main.js"></script>

    <!-- Page JS -->
    <script src="../../assets/js/dashboards-analytics.js"></script>


    <!-- UI ONLY SCRIPT -->
    <script>
      const csvFile = document.getElementById('csvFile');
      const btnStart = document.getElementById('btnStartUpload');
      const btnClear = document.getElementById('btnClear');
      const progressWrap = document.getElementById('uploadProgressWrap');

      csvFile.addEventListener('change', () => {
        const hasFile = csvFile.files.length > 0;
        btnStart.disabled = !hasFile;
        btnClear.disabled = !hasFile;
      });

      btnClear.addEventListener('click', () => {
        csvFile.value = '';
        btnStart.disabled = true;
        btnClear.disabled = true;
        progressWrap.classList.add('d-none');
      });

      let batchId = null;
      let offset = 0;
      const chunkSize = 250;

      $('#btnStartUpload').on('click', function () {

        const fileInput = document.getElementById('csvFile');
        if (!fileInput.files.length) return;

        $('#uploadProgressWrap').removeClass('d-none');
        $('#progressStatus').text('Creating upload batch...');

        // 1. create batch
        $.post('start_upload.php', function (res) {

          if (!res.success) {
            alert('Upload failed: ' + res.message);
            return;
          }

          batchId = res.batch_id;

          let formData = new FormData();
          formData.append('csv_file', fileInput.files[0]);
          formData.append('batch_id', batchId);

          $.ajax({
            url: 'upload_csv.php',
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function () {
              $('#progressStatus').text('Processing records...');
              processNextChunk();
            }
          });

        }, 'json');
      });

      function processNextChunk() {

        $.post('process_chunk.php', {
          batch_id: batchId,
          offset: offset
        }, function (res) {

          offset += chunkSize;

          // poll progress
          $.getJSON('fetch_progress.php', { batch_id: batchId }, function (p) {

            $('#progressBar').css('width', p.percentage + '%');
            $('#progressPercent').text(p.percentage + '%');
            $('#progressMeta').text(`Processed: ${p.processed} / ${p.total}`);

            if (p.percentage < 100) {
              processNextChunk();
            } else {
              $('#progressStatus').text('Upload completed');
            }
          });

        }, 'json');
      }
    </script>

  </body>
</html>
