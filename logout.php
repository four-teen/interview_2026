<?php
require_once __DIR__ . '/config/session_security.php';

secure_session_start();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $cookieParams = session_get_cookie_params();

    if (PHP_VERSION_ID >= 70300) {
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $cookieParams['path'] ?? '/',
            'domain' => $cookieParams['domain'] ?? '',
            'secure' => !empty($cookieParams['secure']),
            'httponly' => !empty($cookieParams['httponly']),
            'samesite' => $cookieParams['samesite'] ?? 'Lax'
        ]);
    } else {
        setcookie(
            session_name(),
            '',
            time() - 42000,
            ($cookieParams['path'] ?? '/') . '; samesite=Lax',
            $cookieParams['domain'] ?? '',
            !empty($cookieParams['secure']),
            !empty($cookieParams['httponly'])
        );
    }
}

session_destroy();
header('Location: index.php');
exit;
