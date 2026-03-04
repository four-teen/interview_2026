<?php

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/session_security.php';
require_once __DIR__ . '/../config/account_secure_links.php';

secure_session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow', true);

function president_secure_login_render_error(string $message, int $statusCode = 403): void
{
    http_response_code($statusCode);
    ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Secure Login Unavailable</title>
    <style>
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, #f3f6fb 0%, #eef7ff 45%, #edf7f1 100%);
        font-family: "Public Sans", Arial, sans-serif;
        color: #23344d;
      }

      .secure-login-card {
        width: min(92vw, 520px);
        padding: 2rem;
        border-radius: 20px;
        background: #ffffff;
        border: 1px solid #d9e2ef;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.1);
      }

      .secure-login-card h1 {
        margin: 0 0 0.8rem;
        font-size: 1.5rem;
      }

      .secure-login-card p {
        margin: 0 0 1.25rem;
        line-height: 1.6;
        color: #5d6f86;
      }

      .secure-login-actions {
        display: flex;
        gap: 0.75rem;
        flex-wrap: wrap;
      }

      .secure-login-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 160px;
        padding: 0.85rem 1.2rem;
        border-radius: 12px;
        text-decoration: none;
        font-weight: 600;
      }

      .secure-login-btn.primary {
        background: #696cff;
        color: #fff;
      }

      .secure-login-btn.secondary {
        background: #eef2f7;
        color: #23344d;
      }
    </style>
  </head>
  <body>
    <div class="secure-login-card">
      <h1>Secure login unavailable</h1>
      <p><?= htmlspecialchars($message); ?></p>
      <div class="secure-login-actions">
        <a class="secure-login-btn primary" href="<?= htmlspecialchars(BASE_URL . '/index.php'); ?>">Back to Login</a>
        <a class="secure-login-btn secondary" href="<?= htmlspecialchars(BASE_URL . '/logout.php'); ?>">Clear Session</a>
      </div>
    </div>
  </body>
</html>
    <?php
    exit;
}

function president_secure_login_render_success(string $redirectUrl): void
{
    http_response_code(200);
    ?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Signing In</title>
    <meta http-equiv="refresh" content="0;url=<?= htmlspecialchars($redirectUrl); ?>" />
    <style>
      body {
        margin: 0;
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: #f5f7fb;
        font-family: "Public Sans", Arial, sans-serif;
        color: #23344d;
      }

      .secure-login-status {
        text-align: center;
        padding: 2rem;
      }
    </style>
  </head>
  <body>
    <div class="secure-login-status">
      <p>Signing in to the president dashboard...</p>
      <p><a href="<?= htmlspecialchars($redirectUrl); ?>">Continue</a></p>
    </div>
    <script>
      window.location.replace(<?= json_encode($redirectUrl); ?>);
    </script>
  </body>
</html>
    <?php
    exit;
}

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    president_secure_login_render_error('Invalid secure login request.', 405);
}

$selector = trim((string) ($_GET['selector'] ?? ''));
$token = trim((string) ($_GET['token'] ?? ''));
$result = consume_president_secure_login_link($conn, $selector, $token);

if (empty($result['success']) || empty($result['account']) || !is_array($result['account'])) {
    president_secure_login_render_error((string) ($result['message'] ?? 'This secure login link is invalid or unavailable.'));
}

$account = $result['account'];
$_SESSION = [];
session_regenerate_id(true);
$_SESSION['logged_in'] = true;
$_SESSION['accountid'] = (int) ($account['accountid'] ?? 0);
$_SESSION['fullname'] = (string) ($account['acc_fullname'] ?? '');
$_SESSION['email'] = (string) ($account['email'] ?? '');
$_SESSION['role'] = 'president';
$_SESSION['campus_id'] = isset($account['campus_id']) ? (int) $account['campus_id'] : null;
$_SESSION['program_id'] = isset($account['program_id']) ? (int) $account['program_id'] : null;
$_SESSION['assigned_program_ids'] = [];

president_secure_login_render_success(BASE_URL . '/presidents/index.php');
