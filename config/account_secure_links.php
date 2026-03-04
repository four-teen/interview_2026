<?php
/**
 * President secure login link helpers.
 */

require_once __DIR__ . '/session_security.php';

if (!defined('PRESIDENT_SECURE_LINK_NEVER_EXPIRES_AT')) {
    define('PRESIDENT_SECURE_LINK_NEVER_EXPIRES_AT', '9999-12-31 23:59:59');
}

if (!function_exists('interview_secure_login_purpose_president')) {
    function interview_secure_login_purpose_president(): string
    {
        return 'president_login';
    }
}

if (!function_exists('ensure_account_secure_login_links_table')) {
    function ensure_account_secure_login_links_table(mysqli $conn): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_account_secure_login_links (
                secure_link_id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
                accountid INT NOT NULL,
                purpose VARCHAR(50) NOT NULL,
                selector VARCHAR(40) NOT NULL,
                token_hash CHAR(64) NOT NULL,
                created_by_accountid INT NULL,
                created_ip VARCHAR(45) NULL,
                created_user_agent VARCHAR(255) NULL,
                expires_at DATETIME NOT NULL,
                used_at DATETIME NULL,
                used_ip VARCHAR(45) NULL,
                used_user_agent VARCHAR(255) NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_secure_link_selector (selector),
                KEY idx_secure_link_account_purpose (accountid, purpose),
                KEY idx_secure_link_expiry (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        return (bool) $conn->query($sql);
    }
}

if (!function_exists('interview_secure_link_client_ip')) {
    function interview_secure_link_client_ip(): string
    {
        $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
        return substr($ip, 0, 45);
    }
}

if (!function_exists('interview_secure_link_user_agent')) {
    function interview_secure_link_user_agent(): string
    {
        $userAgent = trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''));
        return substr($userAgent, 0, 255);
    }
}

if (!function_exists('interview_secure_link_random_hex')) {
    function interview_secure_link_random_hex(int $bytes): string
    {
        if ($bytes < 1) {
            $bytes = 16;
        }

        try {
            return bin2hex(random_bytes($bytes));
        } catch (Exception $e) {
            return hash('sha256', uniqid('secure_link_', true) . mt_rand());
        }
    }
}

if (!function_exists('cleanup_account_secure_login_links')) {
    function cleanup_account_secure_login_links(mysqli $conn): void
    {
        $conn->query("
            DELETE FROM tbl_account_secure_login_links
            WHERE expires_at < NOW()
               OR used_at IS NOT NULL
        ");
    }
}

if (!function_exists('interview_application_base_url')) {
    function interview_application_base_url(): string
    {
        $basePath = defined('BASE_URL') ? (string) BASE_URL : (string) (getenv('BASE_URL') ?: '/interview');
        $basePath = '/' . ltrim($basePath, '/');
        $basePath = rtrim($basePath, '/');

        $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? '')));
        if ($host === '') {
            return $basePath;
        }

        $scheme = is_https_request() ? 'https' : 'http';
        return $scheme . '://' . $host . $basePath;
    }
}

if (!function_exists('generate_president_secure_login_link')) {
    function generate_president_secure_login_link(mysqli $conn, int $accountId, ?int $createdByAccountId = null): array
    {
        if ($accountId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid president account.'
            ];
        }

        if (!ensure_account_secure_login_links_table($conn)) {
            return [
                'success' => false,
                'message' => 'Unable to prepare secure login storage.'
            ];
        }

        cleanup_account_secure_login_links($conn);

        $accountStmt = $conn->prepare("
            SELECT accountid, acc_fullname, email, role, status
            FROM tblaccount
            WHERE accountid = ?
            LIMIT 1
        ");
        if (!$accountStmt) {
            return [
                'success' => false,
                'message' => 'Unable to validate the selected account.'
            ];
        }

        $accountStmt->bind_param('i', $accountId);
        $accountStmt->execute();
        $accountResult = $accountStmt->get_result();
        $account = $accountResult ? $accountResult->fetch_assoc() : null;
        $accountStmt->close();

        if (!$account || strtolower(trim((string) ($account['role'] ?? ''))) !== 'president') {
            return [
                'success' => false,
                'message' => 'Secure login links are available for president accounts only.'
            ];
        }

        if (strtolower(trim((string) ($account['status'] ?? 'inactive'))) !== 'active') {
            return [
                'success' => false,
                'message' => 'Activate the president account before generating a secure login link.'
            ];
        }

        $purpose = interview_secure_login_purpose_president();
        $selector = substr(interview_secure_link_random_hex(12), 0, 24);
        $token = interview_secure_link_random_hex(32);
        $tokenHash = hash('sha256', $token);
        $expiresAt = PRESIDENT_SECURE_LINK_NEVER_EXPIRES_AT;
        $createdIp = interview_secure_link_client_ip();
        $createdUserAgent = interview_secure_link_user_agent();

        $insertStmt = $conn->prepare("
            INSERT INTO tbl_account_secure_login_links (
                accountid,
                purpose,
                selector,
                token_hash,
                created_by_accountid,
                created_ip,
                created_user_agent,
                expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$insertStmt) {
            return [
                'success' => false,
                'message' => 'Unable to store the secure login link.'
            ];
        }

        $insertStmt->bind_param(
            'isssisss',
            $accountId,
            $purpose,
            $selector,
            $tokenHash,
            $createdByAccountId,
            $createdIp,
            $createdUserAgent,
            $expiresAt
        );

        $saved = $insertStmt->execute();
        $insertStmt->close();

        if (!$saved) {
            return [
                'success' => false,
                'message' => 'Unable to save the secure login link.'
            ];
        }

        $baseUrl = interview_application_base_url();
        $loginUrl = $baseUrl . '/auth/president_secure_login.php?selector=' . rawurlencode($selector) . '&token=' . rawurlencode($token);

        return [
            'success' => true,
            'message' => 'Permanent secure login link generated successfully.',
            'url' => $loginUrl,
            'expires_at' => $expiresAt,
            'expires_display' => 'No expiration',
            'account_name' => (string) ($account['acc_fullname'] ?? 'President')
        ];
    }
}

if (!function_exists('consume_president_secure_login_link')) {
    function consume_president_secure_login_link(mysqli $conn, string $selector, string $token): array
    {
        $selector = trim($selector);
        $token = trim($token);

        if ($selector === '' || $token === '') {
            return [
                'success' => false,
                'message' => 'The secure login link is invalid.'
            ];
        }

        if (!ensure_account_secure_login_links_table($conn)) {
            return [
                'success' => false,
                'message' => 'Unable to prepare secure login storage.'
            ];
        }

        cleanup_account_secure_login_links($conn);

        $purpose = interview_secure_login_purpose_president();
        $linkStmt = $conn->prepare("
            SELECT
                l.secure_link_id,
                l.accountid,
                l.token_hash,
                l.expires_at,
                l.used_at,
                a.acc_fullname,
                a.email,
                a.role,
                a.campus_id,
                a.program_id,
                a.status
            FROM tbl_account_secure_login_links l
            INNER JOIN tblaccount a
                ON a.accountid = l.accountid
            WHERE l.selector = ?
              AND l.purpose = ?
            LIMIT 1
        ");
        if (!$linkStmt) {
            return [
                'success' => false,
                'message' => 'Unable to validate the secure login link.'
            ];
        }

        $linkStmt->bind_param('ss', $selector, $purpose);
        $linkStmt->execute();
        $linkResult = $linkStmt->get_result();
        $linkRow = $linkResult ? $linkResult->fetch_assoc() : null;
        $linkStmt->close();

        if (!$linkRow) {
            return [
                'success' => false,
                'message' => 'This secure login link is invalid or unavailable.'
            ];
        }

        if (strtolower(trim((string) ($linkRow['role'] ?? ''))) !== 'president') {
            return [
                'success' => false,
                'message' => 'This secure login link is not assigned to a president account.'
            ];
        }

        if (strtolower(trim((string) ($linkRow['status'] ?? 'inactive'))) !== 'active') {
            return [
                'success' => false,
                'message' => 'This president account is inactive.'
            ];
        }

        $expiresAtTimestamp = strtotime((string) ($linkRow['expires_at'] ?? ''));
        if ($expiresAtTimestamp === false || $expiresAtTimestamp < time()) {
            return [
                'success' => false,
                'message' => 'This secure login link has expired.'
            ];
        }

        $expectedHash = (string) ($linkRow['token_hash'] ?? '');
        $providedHash = hash('sha256', $token);
        if ($expectedHash === '' || !hash_equals($expectedHash, $providedHash)) {
            return [
                'success' => false,
                'message' => 'This secure login link is invalid.'
            ];
        }

        return [
            'success' => true,
            'account' => [
                'accountid' => (int) ($linkRow['accountid'] ?? 0),
                'acc_fullname' => (string) ($linkRow['acc_fullname'] ?? ''),
                'email' => (string) ($linkRow['email'] ?? ''),
                'role' => 'president',
                'campus_id' => isset($linkRow['campus_id']) ? (int) $linkRow['campus_id'] : null,
                'program_id' => isset($linkRow['program_id']) ? (int) $linkRow['program_id'] : null
            ]
        ];
    }
}
