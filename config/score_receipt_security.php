<?php
/**
 * Score receipt signing helpers.
 * Used to sign printable interview score snapshots and verify QR payloads.
 */

if (!function_exists('score_receipt_signing_key')) {
    function score_receipt_signing_key(): string
    {
        $envKey = trim((string) getenv('INTERVIEW_SCORE_SIGNING_KEY'));
        if ($envKey !== '') {
            return $envKey;
        }

        $dbHost = isset($GLOBALS['DB_HOST']) ? (string) $GLOBALS['DB_HOST'] : '';
        $dbUser = isset($GLOBALS['DB_USER']) ? (string) $GLOBALS['DB_USER'] : '';
        $dbPass = isset($GLOBALS['DB_PASS']) ? (string) $GLOBALS['DB_PASS'] : '';
        $dbName = isset($GLOBALS['DB_NAME']) ? (string) $GLOBALS['DB_NAME'] : '';

        return hash('sha256', "interview-score-receipt|{$dbHost}|{$dbUser}|{$dbPass}|{$dbName}|v1");
    }
}

if (!function_exists('score_receipt_normalize_payload')) {
    function score_receipt_normalize_payload(array $payload): array
    {
        return [
            'v' => trim((string) ($payload['v'] ?? '1')),
            'id' => trim((string) ($payload['id'] ?? '')),
            'ex' => trim((string) ($payload['ex'] ?? '')),
            'fs' => trim((string) ($payload['fs'] ?? '')),
            'cl' => strtoupper(trim((string) ($payload['cl'] ?? ''))),
            'iat' => trim((string) ($payload['iat'] ?? '')),
        ];
    }
}

if (!function_exists('score_receipt_payload_string')) {
    function score_receipt_payload_string(array $payload): string
    {
        $normalized = score_receipt_normalize_payload($payload);
        return implode('|', [
            'v=' . $normalized['v'],
            'id=' . $normalized['id'],
            'ex=' . $normalized['ex'],
            'fs=' . $normalized['fs'],
            'cl=' . $normalized['cl'],
            'iat=' . $normalized['iat'],
        ]);
    }
}

if (!function_exists('score_receipt_sign')) {
    function score_receipt_sign(array $payload): string
    {
        return hash_hmac(
            'sha256',
            score_receipt_payload_string($payload),
            score_receipt_signing_key()
        );
    }
}

if (!function_exists('score_receipt_verify')) {
    function score_receipt_verify(array $payload, string $signature): bool
    {
        $signature = strtolower(trim($signature));
        if ($signature === '') {
            return false;
        }

        $expected = score_receipt_sign($payload);
        return hash_equals($expected, $signature);
    }
}

