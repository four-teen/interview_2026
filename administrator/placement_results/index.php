<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}
?>
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
                Upload yearly tertiary placement test results from <strong>CSV / Excel</strong> files
                (<code>.csv</code>, <code>.xls</code>, <code>.xlsx</code>). All examinee names are normalized to
                <strong>UPPERCASE</strong>, and previous records are replaced for the new cycle.
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
                        <label class="form-label">Data File (CSV or Excel)</label>
                        <input type="file" class="form-control" id="uploadFile" accept=".csv,.xls,.xlsx">
                        <div class="form-text">
                          Supported templates:
                          legacy 4-column CSV and the detailed yearly Excel format
                          (Name of Examinee, Examinee Number, per-subject scores, ESM, Overall Score).
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
      const uploadFile = document.getElementById('uploadFile');
      const btnStart = document.getElementById('btnStartUpload');
      const btnClear = document.getElementById('btnClear');
      const progressWrap = document.getElementById('uploadProgressWrap');
      const progressBar = document.getElementById('progressBar');
      const progressPercent = document.getElementById('progressPercent');
      const progressMeta = document.getElementById('progressMeta');
      const progressStatus = document.getElementById('progressStatus');

      let batchId = null;
      let offset = 0;
      let isUploading = false;
      let stagnantCycles = 0;
      let lastProcessed = 0;
      const chunkSize = 250;

      function setButtonsState() {
        const hasFile = uploadFile.files.length > 0;
        btnStart.disabled = isUploading || !hasFile;
        btnClear.disabled = isUploading || !hasFile;
      }

      function renderProgress(progressData) {
        const percentage = Math.max(0, Math.min(100, Number(progressData.percentage || 0)));
        const processed = Number(progressData.processed || 0);
        const total = Number(progressData.total || 0);

        progressBar.style.width = `${percentage}%`;
        progressPercent.textContent = `${percentage}%`;
        progressMeta.textContent = `Processed: ${processed} / ${total}`;
      }

      function failUpload(message) {
        isUploading = false;
        progressStatus.textContent = 'Upload failed';
        setButtonsState();
        alert(message || 'Upload failed.');
      }

      function extractErrorMessage(xhr, fallbackMessage) {
        if (xhr && xhr.responseJSON && xhr.responseJSON.message) {
          return xhr.responseJSON.message;
        }

        const raw = (xhr && xhr.responseText) ? String(xhr.responseText).trim() : '';
        if (raw) {
          try {
            const parsed = JSON.parse(raw);
            if (parsed && parsed.message) return parsed.message;
          } catch (jsonErr) {}

          const htmlToText = document.createElement('div');
          htmlToText.innerHTML = raw;
          const plain = (htmlToText.textContent || htmlToText.innerText || raw).replace(/\s+/g, ' ').trim();
          return plain.length > 240 ? plain.substring(0, 240) + '...' : plain;
        }

        return fallbackMessage;
      }

      function finishUpload(progressData) {
        isUploading = false;
        const total = Number((progressData && progressData.total) || 0);
        const processed = Number((progressData && progressData.processed) || 0);
        const finalTotal = total > 0 ? total : processed;
        renderProgress({
          percentage: 100,
          processed: finalTotal > 0 ? finalTotal : processed,
          total: finalTotal
        });
        progressStatus.textContent = 'Upload completed';
        setButtonsState();
      }

      function pollProgressAndContinue() {
        $.getJSON('fetch_progress.php', { batch_id: batchId })
          .done(function (p) {
            if (!p || !p.success) {
              failUpload('Failed to fetch upload progress.');
              return;
            }

            renderProgress(p);

            if (p.status === 'failed') {
              failUpload('Upload batch is marked as failed. Please retry.');
              return;
            }

            if (p.status === 'completed' || Number(p.percentage) >= 100) {
              finishUpload(p);
              return;
            }

            const processedNow = Number(p.processed || 0);
            if (processedNow <= lastProcessed) {
              stagnantCycles += 1;
            } else {
              stagnantCycles = 0;
              lastProcessed = processedNow;
            }

            if (stagnantCycles >= 10) {
              failUpload('Upload progress stalled. Please retry the upload.');
              return;
            }

            setTimeout(processNextChunk, 80);
          })
          .fail(function () {
            failUpload('Unable to poll upload progress.');
          });
      }

      function processNextChunk() {
        if (!isUploading || !batchId) return;

        $.ajax({
          url: 'process_chunk.php',
          type: 'POST',
          dataType: 'json',
          data: {
            batch_id: batchId,
            offset: offset
          }
        })
          .done(function (res) {
            if (!res || !res.success) {
              failUpload((res && res.message) || 'Chunk processing failed.');
              return;
            }

            if (typeof res.next_offset !== 'undefined') {
              offset = Number(res.next_offset) || (offset + chunkSize);
            } else {
              offset += chunkSize;
            }

            pollProgressAndContinue();
          })
          .fail(function (xhr) {
            const message = extractErrorMessage(xhr, 'Failed to process upload chunk.');
            failUpload(message);
          });
      }

      uploadFile.addEventListener('change', setButtonsState);

      btnClear.addEventListener('click', () => {
        if (isUploading) return;
        uploadFile.value = '';
        progressWrap.classList.add('d-none');
        setButtonsState();
      });

      $('#btnStartUpload').on('click', function () {
        if (isUploading) return;
        if (!uploadFile.files.length) return;

        const selectedFile = uploadFile.files[0];
        const allowedExt = ['csv', 'xls', 'xlsx'];
        const ext = (selectedFile.name.split('.').pop() || '').toLowerCase();
        if (!allowedExt.includes(ext)) {
          alert('Unsupported file type. Please upload CSV, XLS, or XLSX.');
          return;
        }

        batchId = null;
        offset = 0;
        isUploading = true;
        stagnantCycles = 0;
        lastProcessed = 0;

        progressWrap.classList.remove('d-none');
        renderProgress({ percentage: 0, processed: 0, total: 0 });
        progressStatus.textContent = 'Creating upload batch...';
        setButtonsState();

        $.ajax({
          url: 'start_upload.php',
          type: 'POST',
          dataType: 'json'
        })
          .done(function (res) {
            if (!res || !res.success) {
              failUpload('Upload failed: ' + ((res && res.message) || 'Unable to create batch.'));
              return;
            }

            batchId = res.batch_id;

            const formData = new FormData();
            formData.append('data_file', selectedFile);
            formData.append('batch_id', batchId);

            $.ajax({
              url: 'upload_csv.php',
              type: 'POST',
              dataType: 'json',
              data: formData,
              processData: false,
              contentType: false
            })
              .done(function (parseRes) {
                if (!parseRes || !parseRes.success) {
                  failUpload((parseRes && parseRes.message) || 'Upload file parsing failed.');
                  return;
                }

                progressStatus.textContent = 'Processing records...';
                processNextChunk();
              })
              .fail(function (xhr) {
                const message = extractErrorMessage(xhr, 'Upload file parsing failed.');
                failUpload(message);
              });
          })
          .fail(function (xhr) {
            const message = extractErrorMessage(xhr, 'Failed to start upload batch.');
            failUpload(message);
          });
      });

      setButtonsState();
    </script>

  </body>
</html>
