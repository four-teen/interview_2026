<?php
/**
 * Shared account-role definitions and schema helpers.
 */

if (!function_exists('interview_account_role_options')) {
    function interview_account_role_options(): array
    {
        return [
            'administrator' => 'Administrator',
            'president' => 'President',
            'progchair' => 'Program Chair',
            'monitoring' => 'Monitoring',
            'guidance' => 'Guidance',
            'registrar' => 'Registrar'
        ];
    }
}

if (!function_exists('interview_account_role_exists')) {
    function interview_account_role_exists(string $role): bool
    {
        $normalizedRole = strtolower(trim($role));
        return array_key_exists($normalizedRole, interview_account_role_options());
    }
}

if (!function_exists('interview_account_role_badge_class')) {
    function interview_account_role_badge_class(string $role): string
    {
        $normalizedRole = strtolower(trim($role));
        $badgeClasses = [
            'administrator' => 'primary',
            'president' => 'secondary',
            'progchair' => 'info',
            'monitoring' => 'warning',
            'guidance' => 'success',
            'registrar' => 'dark'
        ];

        return $badgeClasses[$normalizedRole] ?? 'secondary';
    }
}

if (!function_exists('ensure_tblaccount_role_enum')) {
    function ensure_tblaccount_role_enum(mysqli $conn): bool
    {
        $columnResult = $conn->query("SHOW COLUMNS FROM tblaccount LIKE 'role'");
        if (!$columnResult) {
            return false;
        }

        $column = $columnResult->fetch_assoc();
        $columnResult->free();

        if (!$column) {
            return false;
        }

        $existingType = strtolower((string) ($column['Type'] ?? ''));
        $missingRole = false;
        foreach (array_keys(interview_account_role_options()) as $roleValue) {
            if (strpos($existingType, "'" . strtolower($roleValue) . "'") === false) {
                $missingRole = true;
                break;
            }
        }

        if (!$missingRole) {
            return true;
        }

        $enumValues = array_map(
            static function (string $role): string {
                return "'" . $role . "'";
            },
            array_keys(interview_account_role_options())
        );

        $nullClause = strtoupper((string) ($column['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
        $defaultValue = $column['Default'] ?? null;
        $defaultClause = $defaultValue !== null
            ? " DEFAULT '" . $conn->real_escape_string((string) $defaultValue) . "'"
            : '';

        $alterSql = sprintf(
            'ALTER TABLE tblaccount MODIFY COLUMN role ENUM(%s) %s%s',
            implode(', ', $enumValues),
            $nullClause,
            $defaultClause
        );

        return (bool) $conn->query($alterSql);
    }
}
