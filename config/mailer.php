<?php
/**
 * Lightweight mail helper with SMTP support (no external dependencies).
 * Reads configuration from environment variables.
 */

require_once __DIR__ . '/env.php';

if (!function_exists('mailer_env')) {
    function mailer_env($key, $default = '')
    {
        $value = getenv((string) $key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('mailer_encode_header')) {
    function mailer_encode_header($value)
    {
        $value = (string) $value;
        if ($value === '') {
            return '';
        }
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}

if (!function_exists('mailer_format_address')) {
    function mailer_format_address($email, $name = '')
    {
        $email = trim((string) $email);
        $name = trim((string) $name);

        if ($name === '') {
            return $email;
        }

        $safeName = str_replace(['"', "\r", "\n"], ['', '', ''], $name);
        return '"' . addslashes($safeName) . '" <' . $email . '>';
    }
}

if (!function_exists('mailer_normalize_body')) {
    function mailer_normalize_body($body)
    {
        $body = str_replace(["\r\n", "\r"], "\n", (string) $body);
        $body = str_replace("\0", '', $body);
        return str_replace("\n", "\r\n", $body);
    }
}

if (!function_exists('mailer_native_send')) {
    function mailer_native_send($toEmail, $toName, $subject, $textBody, &$errorMessage = null)
    {
        $fromEmail = trim((string) mailer_env('MAIL_FROM_ADDRESS', 'no-reply@localhost'));
        $fromName = trim((string) mailer_env('MAIL_FROM_NAME', 'Interview System'));

        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $fromEmail = 'no-reply@localhost';
        }

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'From: ' . mailer_format_address($fromEmail, $fromName),
            'Reply-To: ' . $fromEmail
        ];

        $ok = @mail(
            mailer_format_address($toEmail, $toName),
            mailer_encode_header($subject),
            mailer_normalize_body($textBody),
            implode("\r\n", $headers)
        );

        if (!$ok) {
            $errorMessage = 'Native mail() failed.';
        }

        return $ok;
    }
}

if (!function_exists('smtp_read_response')) {
    function smtp_read_response($socket, &$rawResponse = '')
    {
        $rawResponse = '';
        while (($line = fgets($socket, 1024)) !== false) {
            $rawResponse .= $line;
            if (preg_match('/^\d{3}\s/', $line)) {
                break;
            }
        }

        if ($rawResponse === '') {
            return 0;
        }

        return (int) substr($rawResponse, 0, 3);
    }
}

if (!function_exists('smtp_send_command')) {
    function smtp_send_command($socket, $command, array $expectedCodes, &$errorMessage = null)
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            $errorMessage = 'SMTP write failed for command: ' . $command;
            return false;
        }

        $response = '';
        $code = smtp_read_response($socket, $response);
        if ($code === 0 || !in_array($code, $expectedCodes, true)) {
            $errorMessage = 'SMTP command failed: ' . $command . ' | Response: ' . trim($response);
            return false;
        }

        return true;
    }
}

if (!function_exists('mailer_smtp_send')) {
    function mailer_smtp_send($toEmail, $toName, $subject, $textBody, &$errorMessage = null)
    {
        $host = trim((string) mailer_env('MAIL_HOST', ''));
        $port = (int) mailer_env('MAIL_PORT', '587');
        $username = (string) mailer_env('MAIL_USERNAME', '');
        $password = (string) mailer_env('MAIL_PASSWORD', '');
        $encryption = strtolower(trim((string) mailer_env('MAIL_ENCRYPTION', 'tls')));
        $fromEmail = trim((string) mailer_env('MAIL_FROM_ADDRESS', $username));
        $fromName = trim((string) mailer_env('MAIL_FROM_NAME', 'Interview System'));
        $heloDomain = trim((string) mailer_env('MAIL_HELO_DOMAIN', 'localhost'));
        $timeout = (int) mailer_env('MAIL_TIMEOUT', '20');
        $verifyPeer = ((string) mailer_env('MAIL_SMTP_VERIFY_PEER', '1') !== '0');

        if ($host === '' || $port <= 0) {
            $errorMessage = 'MAIL_HOST and MAIL_PORT are required for SMTP.';
            return false;
        }
        if (!filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'MAIL_FROM_ADDRESS is invalid.';
            return false;
        }

        $isSsl = ($encryption === 'ssl');
        $isTls = ($encryption === 'tls');
        $protocol = $isSsl ? 'ssl' : 'tcp';

        $contextOptions = [
            'ssl' => [
                'verify_peer' => $verifyPeer,
                'verify_peer_name' => $verifyPeer,
                'allow_self_signed' => !$verifyPeer
            ]
        ];
        $context = stream_context_create($contextOptions);

        $socket = @stream_socket_client(
            $protocol . '://' . $host . ':' . $port,
            $errno,
            $errstr,
            max(5, $timeout),
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $errorMessage = 'SMTP connection failed: ' . $errstr . ' (' . $errno . ')';
            return false;
        }

        stream_set_timeout($socket, max(5, $timeout));

        $response = '';
        $code = smtp_read_response($socket, $response);
        if ($code !== 220) {
            fclose($socket);
            $errorMessage = 'SMTP server greeting failed: ' . trim($response);
            return false;
        }

        if (!smtp_send_command($socket, 'EHLO ' . $heloDomain, [250], $errorMessage)) {
            fclose($socket);
            return false;
        }

        if ($isTls) {
            if (!smtp_send_command($socket, 'STARTTLS', [220], $errorMessage)) {
                fclose($socket);
                return false;
            }

            $cryptoEnabled = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            if ($cryptoEnabled !== true) {
                fclose($socket);
                $errorMessage = 'Unable to enable TLS encryption.';
                return false;
            }

            if (!smtp_send_command($socket, 'EHLO ' . $heloDomain, [250], $errorMessage)) {
                fclose($socket);
                return false;
            }
        }

        if ($username !== '') {
            $loginAuthError = null;
            if (smtp_send_command($socket, 'AUTH LOGIN', [334], $loginAuthError)) {
                if (!smtp_send_command($socket, base64_encode($username), [334], $errorMessage)) {
                    fclose($socket);
                    return false;
                }
                if (!smtp_send_command($socket, base64_encode($password), [235], $errorMessage)) {
                    fclose($socket);
                    return false;
                }
            } else {
                $plainAuthValue = base64_encode("\0" . $username . "\0" . $password);
                if (!smtp_send_command($socket, 'AUTH PLAIN ' . $plainAuthValue, [235], $errorMessage)) {
                    fclose($socket);
                    $errorMessage = 'SMTP auth failed. LOGIN error: ' . (string) $loginAuthError . ' | PLAIN error: ' . (string) $errorMessage;
                    return false;
                }
            }
        }

        if (!smtp_send_command($socket, 'MAIL FROM:<' . $fromEmail . '>', [250], $errorMessage)) {
            fclose($socket);
            return false;
        }

        if (!smtp_send_command($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251], $errorMessage)) {
            fclose($socket);
            return false;
        }

        if (!smtp_send_command($socket, 'DATA', [354], $errorMessage)) {
            fclose($socket);
            return false;
        }

        $headers = [
            'Date: ' . date('r'),
            'From: ' . mailer_format_address($fromEmail, $fromName),
            'To: ' . mailer_format_address($toEmail, $toName),
            'Subject: ' . mailer_encode_header($subject),
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'Message-ID: <' . uniqid('mail_', true) . '@' . preg_replace('/[^a-z0-9\.\-]+/i', '', $heloDomain) . '>'
        ];

        $body = mailer_normalize_body($textBody);
        $lines = explode("\r\n", $body);
        foreach ($lines as &$line) {
            if (isset($line[0]) && $line[0] === '.') {
                $line = '.' . $line;
            }
        }
        unset($line);

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $lines) . "\r\n.\r\n";
        if (@fwrite($socket, $payload) === false) {
            fclose($socket);
            $errorMessage = 'SMTP write failed during DATA payload.';
            return false;
        }

        $response = '';
        $code = smtp_read_response($socket, $response);
        if ($code !== 250) {
            fclose($socket);
            $errorMessage = 'SMTP DATA failed: ' . trim($response);
            return false;
        }

        @smtp_send_command($socket, 'QUIT', [221], $errorMessage);
        fclose($socket);
        return true;
    }
}

if (!function_exists('send_system_email')) {
    /**
     * Sends a plain-text email.
     * MAIL_DRIVER=smtp uses SMTP transport; anything else uses native mail().
     */
    function send_system_email($toEmail, $toName, $subject, $textBody, &$errorMessage = null)
    {
        $toEmail = trim((string) $toEmail);
        $toName = trim((string) $toName);
        $subject = trim((string) $subject);
        $textBody = (string) $textBody;
        $errorMessage = null;

        if (!filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            $errorMessage = 'Recipient email is invalid.';
            return false;
        }
        if ($subject === '' || trim($textBody) === '') {
            $errorMessage = 'Subject and body are required.';
            return false;
        }

        $driver = strtolower(trim((string) mailer_env('MAIL_DRIVER', 'mail')));
        if ($driver === 'smtp') {
            return mailer_smtp_send($toEmail, $toName, $subject, $textBody, $errorMessage);
        }

        return mailer_native_send($toEmail, $toName, $subject, $textBody, $errorMessage);
    }
}
