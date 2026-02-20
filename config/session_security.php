<?php
/**
 * Session bootstrap helpers for consistent cookie and session hardening.
 */

if (!function_exists('is_https_request')) {
    function is_https_request()
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $forwardedProto === 'https';
    }
}

if (!function_exists('secure_session_start')) {
    function secure_session_start()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $secureDefault = is_https_request() ? '1' : '0';
        $secureCookie = getenv('SESSION_COOKIE_SECURE');
        if ($secureCookie === false || $secureCookie === '') {
            $secureCookie = $secureDefault;
        }

        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');
        ini_set('session.cookie_secure', $secureCookie === '1' ? '1' : '0');

        $cookiePath = ini_get('session.cookie_path');
        if (!is_string($cookiePath) || $cookiePath === '') {
            $cookiePath = '/';
        }

        $cookieDomain = ini_get('session.cookie_domain');
        if (!is_string($cookieDomain)) {
            $cookieDomain = '';
        }

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path' => $cookiePath,
                'domain' => $cookieDomain,
                'secure' => $secureCookie === '1',
                'httponly' => true,
                'samesite' => 'Lax'
            ]);
        } else {
            session_set_cookie_params(
                0,
                $cookiePath . '; samesite=Lax',
                $cookieDomain,
                $secureCookie === '1',
                true
            );
        }

        session_start();
    }
}
