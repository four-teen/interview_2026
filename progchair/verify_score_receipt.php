<?php
/**
 * Public verifier for signed interview score receipt QR payloads.
 */

require_once '../config/db.php';
require_once '../config/score_receipt_security.php';

function query_value(string $key): string
{
    return trim((string) ($_GET[$key] ?? ''));
}

function normalize_classification_value(string $value): string
{
    $value = strtoupper(trim($value));
    return (strpos($value, 'ETG') === 0) ? 'ETG' : 'REGULAR';
}

function is_iso_utc_datetime(string $value): bool
{
    return (bool) preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $value);
}

$payload = [
    'v' => query_value('v'),
    'id' => query_value('id'),
    'ex' => query_value('ex'),
    'fs' => query_value('fs'),
    'cl' => normalize_classification_value(query_value('cl')),
    'iat' => query_value('iat'),
];
$signature = strtolower(query_value('sig'));

$inputFormatValid = (
    $payload['v'] === '1' &&
    ctype_digit($payload['id']) &&
    $payload['ex'] !== '' &&
    preg_match('/^\d+(?:\.\d{1,2})?$/', $payload['fs']) &&
    in_array($payload['cl'], ['REGULAR', 'ETG'], true) &&
    is_iso_utc_datetime($payload['iat']) &&
    (bool) preg_match('/^[a-f0-9]{64}$/', $signature)
);

$signatureValid = false;
$recordFound = false;
$snapshotMatchesCurrent = false;
$dbRecord = null;
$status = 'invalid';
$statusTitle = 'Invalid Receipt';
$statusMessage = 'The QR payload is invalid or the signature does not match.';

if ($inputFormatValid) {
    $signatureValid = score_receipt_verify($payload, $signature);

    if ($signatureValid) {
        $interviewId = (int) $payload['id'];
        $sql = "
            SELECT
                si.interview_id,
                si.classification,
                si.final_score,
                pr.examinee_number,
                pr.full_name
            FROM tbl_student_interview si
            INNER JOIN tbl_placement_results pr
                ON pr.id = si.placement_result_id
            WHERE si.interview_id = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if ($stmt) {
            $stmt->bind_param('i', $interviewId);
            $stmt->execute();
            $result = $stmt->get_result();
            if ($result && $result->num_rows > 0) {
                $recordFound = true;
                $dbRecord = $result->fetch_assoc();

                $dbExaminee = trim((string) ($dbRecord['examinee_number'] ?? ''));
                $dbClassification = normalize_classification_value((string) ($dbRecord['classification'] ?? 'REGULAR'));
                $dbFinalScore = number_format((float) ($dbRecord['final_score'] ?? 0), 2, '.', '');
                $payloadFinalScore = number_format((float) $payload['fs'], 2, '.', '');

                $snapshotMatchesCurrent =
                    ($payload['ex'] === $dbExaminee) &&
                    ($payload['cl'] === $dbClassification) &&
                    ($payloadFinalScore === $dbFinalScore);
            }
        }
    }
}

if (!$inputFormatValid || !$signatureValid) {
    $status = 'invalid';
    $statusTitle = 'Invalid Receipt';
    $statusMessage = 'The signed token is not valid. This printout may have been tampered with.';
} elseif (!$recordFound) {
    $status = 'missing';
    $statusTitle = 'Record Not Found';
    $statusMessage = 'The signature is valid, but the interview record no longer exists.';
} elseif ($snapshotMatchesCurrent) {
    $status = 'valid';
    $statusTitle = 'Verified';
    $statusMessage = 'The signature is valid and the snapshot matches the current database record.';
} else {
    $status = 'changed';
    $statusTitle = 'Verified With Changes';
    $statusMessage = 'The signature is valid, but the current database values differ from this printed snapshot.';
}

$issuedAtDisplay = $payload['iat'];
$issuedAtDate = DateTime::createFromFormat('Y-m-d\TH:i:s\Z', $payload['iat'], new DateTimeZone('UTC'));
if ($issuedAtDate instanceof DateTime) {
    $issuedAtDate->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $issuedAtDisplay = $issuedAtDate->format('M j, Y g:i:s A');
}

$statusClassMap = [
    'valid' => 'status-valid',
    'changed' => 'status-changed',
    'missing' => 'status-missing',
    'invalid' => 'status-invalid',
];
$statusClass = $statusClassMap[$status] ?? 'status-invalid';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Score Receipt Verification</title>
  <style>
    :root {
      --bg: #f3f4f6;
      --card: #ffffff;
      --text: #111827;
      --muted: #6b7280;
      --ok: #166534;
      --ok-bg: #dcfce7;
      --warn: #92400e;
      --warn-bg: #fef3c7;
      --bad: #991b1b;
      --bad-bg: #fee2e2;
      --missing: #1e3a8a;
      --missing-bg: #dbeafe;
      --border: #d1d5db;
    }
    body {
      margin: 0;
      background: var(--bg);
      color: var(--text);
      font-family: Arial, sans-serif;
      line-height: 1.4;
    }
    .wrap {
      max-width: 860px;
      margin: 24px auto;
      padding: 0 14px;
    }
    .card {
      background: var(--card);
      border-radius: 10px;
      border: 1px solid var(--border);
      box-shadow: 0 8px 18px rgba(17, 24, 39, 0.06);
      overflow: hidden;
    }
    .head {
      padding: 18px 18px 12px;
      border-bottom: 1px solid var(--border);
    }
    .title {
      margin: 0;
      font-size: 20px;
    }
    .sub {
      margin: 6px 0 0;
      color: var(--muted);
      font-size: 13px;
    }
    .status {
      margin: 14px 18px 0;
      border-radius: 8px;
      padding: 12px 14px;
      border: 1px solid transparent;
    }
    .status h2 {
      margin: 0 0 6px;
      font-size: 18px;
    }
    .status p {
      margin: 0;
      font-size: 14px;
    }
    .status-valid {
      background: var(--ok-bg);
      border-color: #86efac;
      color: var(--ok);
    }
    .status-changed {
      background: var(--warn-bg);
      border-color: #fcd34d;
      color: var(--warn);
    }
    .status-missing {
      background: var(--missing-bg);
      border-color: #93c5fd;
      color: var(--missing);
    }
    .status-invalid {
      background: var(--bad-bg);
      border-color: #fca5a5;
      color: var(--bad);
    }
    .body {
      padding: 16px 18px 20px;
    }
    .grid {
      display: grid;
      grid-template-columns: repeat(2, minmax(220px, 1fr));
      gap: 12px 16px;
    }
    .kv-label {
      margin: 0;
      font-size: 12px;
      color: var(--muted);
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .kv-value {
      margin: 2px 0 0;
      font-size: 15px;
      font-weight: 600;
      word-break: break-word;
    }
    .foot {
      margin-top: 16px;
      border-top: 1px solid var(--border);
      padding-top: 10px;
      color: var(--muted);
      font-size: 12px;
    }
    @media (max-width: 680px) {
      .grid { grid-template-columns: 1fr; }
      .title { font-size: 18px; }
    }
  </style>
</head>
<body>
  <div class="wrap">
    <div class="card">
      <div class="head">
        <h1 class="title">Interview Score Receipt Verification</h1>
        <p class="sub">Use this page to validate QR code signatures on printed interview score results.</p>
      </div>

      <div class="status <?= htmlspecialchars($statusClass); ?>">
        <h2><?= htmlspecialchars($statusTitle); ?></h2>
        <p><?= htmlspecialchars($statusMessage); ?></p>
      </div>

      <div class="body">
        <div class="grid">
          <div>
            <p class="kv-label">Interview ID</p>
            <p class="kv-value"><?= htmlspecialchars($payload['id']); ?></p>
          </div>
          <div>
            <p class="kv-label">Examinee Number (Token)</p>
            <p class="kv-value"><?= htmlspecialchars($payload['ex']); ?></p>
          </div>
          <div>
            <p class="kv-label">Final Score (Token)</p>
            <p class="kv-value"><?= htmlspecialchars(number_format((float) $payload['fs'], 2, '.', '')); ?>%</p>
          </div>
          <div>
            <p class="kv-label">Classification (Token)</p>
            <p class="kv-value"><?= htmlspecialchars($payload['cl']); ?></p>
          </div>
          <div>
            <p class="kv-label">Issued At (Token)</p>
            <p class="kv-value"><?= htmlspecialchars($issuedAtDisplay); ?></p>
          </div>
          <div>
            <p class="kv-label">Signature</p>
            <p class="kv-value"><?= htmlspecialchars(substr($signature, 0, 16) . '...'); ?></p>
          </div>
        </div>

        <?php if ($recordFound && $dbRecord): ?>
        <div class="foot">
          Current DB Snapshot:
          Examinee # <?= htmlspecialchars((string) ($dbRecord['examinee_number'] ?? '')); ?> |
          Name <?= htmlspecialchars((string) ($dbRecord['full_name'] ?? '')); ?> |
          Final <?= htmlspecialchars(number_format((float) ($dbRecord['final_score'] ?? 0), 2, '.', '')); ?>% |
          Class <?= htmlspecialchars(normalize_classification_value((string) ($dbRecord['classification'] ?? 'REGULAR'))); ?>
        </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</body>
</html>

