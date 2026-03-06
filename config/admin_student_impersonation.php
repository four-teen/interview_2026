<?php
require_once __DIR__ . '/env.php';
require_once __DIR__ . '/session_security.php';
require_once __DIR__ . '/student_credentials.php';

if (!defined('BASE_URL')) {
    define('BASE_URL', getenv('BASE_URL') ?: '/interview');
}

if (!function_exists('admin_student_impersonation_default_redirect')) {
    function admin_student_impersonation_default_redirect(): string
    {
        return rtrim(BASE_URL, '/') . '/administrator/index.php';
    }
}

if (!function_exists('admin_student_impersonation_normalize_return_to')) {
    function admin_student_impersonation_normalize_return_to(string $returnTo): string
    {
        $defaultRedirect = admin_student_impersonation_default_redirect();
        $returnTo = trim($returnTo);
        if ($returnTo === '') {
            return $defaultRedirect;
        }

        $parsed = parse_url($returnTo);
        if ($parsed === false || isset($parsed['scheme']) || isset($parsed['host'])) {
            return $defaultRedirect;
        }

        $path = trim((string) ($parsed['path'] ?? ''));
        if ($path === '') {
            return $defaultRedirect;
        }

        $allowedPrefix = rtrim(BASE_URL, '/') . '/administrator/';
        if (strpos($path, $allowedPrefix) !== 0) {
            return $defaultRedirect;
        }

        $query = isset($parsed['query']) && $parsed['query'] !== ''
            ? ('?' . $parsed['query'])
            : '';

        return $path . $query;
    }
}

if (!function_exists('admin_student_impersonation_get_csrf_token')) {
    function admin_student_impersonation_get_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        if (empty($_SESSION['admin_student_impersonation_csrf'])) {
            try {
                $_SESSION['admin_student_impersonation_csrf'] = bin2hex(random_bytes(32));
            } catch (Exception $e) {
                $_SESSION['admin_student_impersonation_csrf'] = sha1(uniqid('admin_student_impersonation_csrf_', true));
            }
        }

        return (string) $_SESSION['admin_student_impersonation_csrf'];
    }
}

if (!function_exists('admin_student_impersonation_verify_csrf')) {
    function admin_student_impersonation_verify_csrf(string $postedToken): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        $sessionToken = (string) ($_SESSION['admin_student_impersonation_csrf'] ?? '');
        return $postedToken !== '' && $sessionToken !== '' && hash_equals($sessionToken, $postedToken);
    }
}

if (!function_exists('admin_student_impersonation_set_flash')) {
    function admin_student_impersonation_set_flash(string $type, string $message): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        $_SESSION['admin_student_impersonation_flash'] = [
            'type' => $type,
            'message' => $message,
        ];
    }
}

if (!function_exists('admin_student_impersonation_pop_flash')) {
    function admin_student_impersonation_pop_flash(): ?array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        $flash = $_SESSION['admin_student_impersonation_flash'] ?? null;
        unset($_SESSION['admin_student_impersonation_flash']);

        return is_array($flash) ? $flash : null;
    }
}

if (!function_exists('admin_student_impersonation_get_context')) {
    function admin_student_impersonation_get_context(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        $context = $_SESSION['admin_student_impersonation'] ?? null;
        return is_array($context) ? $context : [];
    }
}

if (!function_exists('admin_student_impersonation_is_active')) {
    function admin_student_impersonation_is_active(): bool
    {
        $context = admin_student_impersonation_get_context();
        return !empty($context)
            && ((string) ($context['role'] ?? '')) === 'administrator'
            && (int) ($context['accountid'] ?? 0) > 0;
    }
}

if (!function_exists('admin_student_impersonation_clear_student_session')) {
    function admin_student_impersonation_clear_student_session(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        foreach (array_keys($_SESSION) as $sessionKey) {
            if (strpos((string) $sessionKey, 'student_') === 0) {
                unset($_SESSION[$sessionKey]);
            }
        }
    }
}

if (!function_exists('admin_student_impersonation_begin')) {
    function admin_student_impersonation_begin(mysqli $conn, int $credentialId, string $returnTo = ''): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        if (!isset($_SESSION['logged_in']) || (($_SESSION['role'] ?? '') !== 'administrator')) {
            return [
                'success' => false,
                'message' => 'Administrator access is required.',
                'redirect' => admin_student_impersonation_default_redirect(),
            ];
        }

        if ($credentialId <= 0) {
            return [
                'success' => false,
                'message' => 'Student credential was not provided.',
                'redirect' => admin_student_impersonation_normalize_return_to($returnTo),
            ];
        }

        if (!ensure_student_credentials_table($conn)) {
            return [
                'success' => false,
                'message' => 'Student credential storage is not ready.',
                'redirect' => admin_student_impersonation_normalize_return_to($returnTo),
            ];
        }

        $studentSql = "
            SELECT
                sc.credential_id,
                sc.examinee_number,
                sc.must_change_password,
                sc.placement_result_id,
                sc.status,
                pr.full_name
            FROM tbl_student_credentials sc
            LEFT JOIN tbl_placement_results pr
                ON pr.id = sc.placement_result_id
            WHERE sc.credential_id = ?
            LIMIT 1
        ";

        $studentStmt = $conn->prepare($studentSql);
        if (!$studentStmt) {
            return [
                'success' => false,
                'message' => 'Failed to prepare student preview lookup.',
                'redirect' => admin_student_impersonation_normalize_return_to($returnTo),
            ];
        }

        $studentStmt->bind_param('i', $credentialId);
        $studentStmt->execute();
        $student = $studentStmt->get_result()->fetch_assoc();
        $studentStmt->close();

        if (!$student) {
            return [
                'success' => false,
                'message' => 'Student credential was not found.',
                'redirect' => admin_student_impersonation_normalize_return_to($returnTo),
            ];
        }

        if ((string) ($student['status'] ?? '') !== 'active') {
            return [
                'success' => false,
                'message' => 'Only active student credentials can be previewed.',
                'redirect' => admin_student_impersonation_normalize_return_to($returnTo),
            ];
        }

        $studentName = trim((string) ($student['full_name'] ?? ''));
        if ($studentName === '') {
            $studentName = trim((string) ($student['examinee_number'] ?? 'Student'));
        }

        $impersonationContext = [
            'logged_in' => true,
            'accountid' => (int) ($_SESSION['accountid'] ?? 0),
            'fullname' => (string) ($_SESSION['fullname'] ?? 'Administrator'),
            'email' => (string) ($_SESSION['email'] ?? ''),
            'role' => 'administrator',
            'campus_id' => isset($_SESSION['campus_id']) ? (int) $_SESSION['campus_id'] : null,
            'program_id' => isset($_SESSION['program_id']) ? (int) $_SESSION['program_id'] : null,
            'assigned_program_ids' => array_values(array_map('intval', (array) ($_SESSION['assigned_program_ids'] ?? []))),
            'return_to' => admin_student_impersonation_normalize_return_to($returnTo),
            'student_credential_id' => (int) ($student['credential_id'] ?? 0),
            'student_examinee_number' => (string) ($student['examinee_number'] ?? ''),
            'student_name' => $studentName,
            'started_at' => date('Y-m-d H:i:s'),
        ];

        session_regenerate_id(true);
        admin_student_impersonation_clear_student_session();

        $_SESSION['admin_student_impersonation'] = $impersonationContext;
        unset($_SESSION['accountid'], $_SESSION['email'], $_SESSION['campus_id'], $_SESSION['program_id'], $_SESSION['assigned_program_ids']);

        $_SESSION['logged_in'] = true;
        $_SESSION['role'] = 'student';
        $_SESSION['student_credential_id'] = (int) ($student['credential_id'] ?? 0);
        $_SESSION['student_examinee_number'] = (string) ($student['examinee_number'] ?? '');
        $_SESSION['student_placement_result_id'] = (int) ($student['placement_result_id'] ?? 0);
        $_SESSION['fullname'] = $studentName;
        $_SESSION['student_must_change_password'] = ((int) ($student['must_change_password'] ?? 1) === 1);

        return [
            'success' => true,
            'message' => 'Student preview started.',
            'redirect' => rtrim(BASE_URL, '/') . '/student/index.php',
        ];
    }
}

if (!function_exists('admin_student_impersonation_end')) {
    function admin_student_impersonation_end(): array
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            secure_session_start();
        }

        $context = admin_student_impersonation_get_context();
        if (empty($context)) {
            return [
                'success' => false,
                'message' => 'No student preview session was found.',
                'redirect' => admin_student_impersonation_default_redirect(),
            ];
        }

        $studentName = trim((string) ($context['student_name'] ?? 'Student'));
        $redirect = admin_student_impersonation_normalize_return_to((string) ($context['return_to'] ?? ''));

        session_regenerate_id(true);
        admin_student_impersonation_clear_student_session();
        unset($_SESSION['admin_student_impersonation']);

        $_SESSION['logged_in'] = !empty($context['logged_in']);
        $_SESSION['accountid'] = (int) ($context['accountid'] ?? 0);
        $_SESSION['fullname'] = (string) ($context['fullname'] ?? 'Administrator');
        $_SESSION['email'] = (string) ($context['email'] ?? '');
        $_SESSION['role'] = 'administrator';
        $_SESSION['campus_id'] = $context['campus_id'] ?? null;
        $_SESSION['program_id'] = $context['program_id'] ?? null;
        $_SESSION['assigned_program_ids'] = array_values(array_map('intval', (array) ($context['assigned_program_ids'] ?? [])));

        admin_student_impersonation_set_flash('success', 'Returned from student preview for ' . $studentName . '.');

        return [
            'success' => true,
            'message' => 'Student preview ended.',
            'redirect' => $redirect,
        ];
    }
}
