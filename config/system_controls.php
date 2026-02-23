<?php
/**
 * Shared helpers for global system controls.
 */

if (!function_exists('ensure_system_controls_table')) {
    function ensure_system_controls_table(mysqli $conn): bool
    {
        $sql = "
            CREATE TABLE IF NOT EXISTS tbl_system_controls (
                control_key VARCHAR(100) NOT NULL PRIMARY KEY,
                control_value VARCHAR(255) NOT NULL,
                updated_by INT(11) DEFAULT NULL,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ";

        return (bool) $conn->query($sql);
    }
}

if (!function_exists('get_system_control_value')) {
    function get_system_control_value(mysqli $conn, string $controlKey, string $defaultValue = ''): string
    {
        if (!ensure_system_controls_table($conn)) {
            return $defaultValue;
        }

        $sql = "
            SELECT control_value
            FROM tbl_system_controls
            WHERE control_key = ?
            LIMIT 1
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return $defaultValue;
        }

        $stmt->bind_param('s', $controlKey);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row || !isset($row['control_value'])) {
            return $defaultValue;
        }

        return (string) $row['control_value'];
    }
}

if (!function_exists('set_system_control_value')) {
    function set_system_control_value(mysqli $conn, string $controlKey, string $controlValue, ?int $updatedBy = null): bool
    {
        if (!ensure_system_controls_table($conn)) {
            return false;
        }

        $updatedByValue = $updatedBy !== null ? (int) $updatedBy : null;
        $sql = "
            INSERT INTO tbl_system_controls (control_key, control_value, updated_by)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE
                control_value = VALUES(control_value),
                updated_by = VALUES(updated_by)
        ";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('ssi', $controlKey, $controlValue, $updatedByValue);
        $ok = $stmt->execute();
        $stmt->close();

        return $ok;
    }
}

if (!function_exists('is_non_admin_login_locked')) {
    function is_non_admin_login_locked(mysqli $conn): bool
    {
        $value = get_system_control_value($conn, 'non_admin_login_lock', '0');
        return $value === '1';
    }
}

if (!function_exists('set_non_admin_login_lock')) {
    function set_non_admin_login_lock(mysqli $conn, bool $locked, ?int $updatedBy = null): bool
    {
        return set_system_control_value($conn, 'non_admin_login_lock', $locked ? '1' : '0', $updatedBy);
    }
}
