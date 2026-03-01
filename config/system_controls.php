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

if (!function_exists('student_login_control_key')) {
    function student_login_control_key(): string
    {
        return 'student_login_lock';
    }
}

if (!function_exists('is_student_login_locked')) {
    function is_student_login_locked(mysqli $conn): bool
    {
        $value = get_system_control_value($conn, student_login_control_key(), '0');
        return $value === '1';
    }
}

if (!function_exists('set_student_login_lock')) {
    function set_student_login_lock(mysqli $conn, bool $locked, ?int $updatedBy = null): bool
    {
        return set_system_control_value($conn, student_login_control_key(), $locked ? '1' : '0', $updatedBy);
    }
}

if (!function_exists('program_login_control_key')) {
    function program_login_control_key(int $programId): string
    {
        return 'program_login_lock_' . max(0, (int) $programId);
    }
}

if (!function_exists('is_program_login_unlocked')) {
    function is_program_login_unlocked(mysqli $conn, int $programId): bool
    {
        $programId = (int) $programId;
        if ($programId <= 0) {
            return false;
        }

        $value = get_system_control_value($conn, program_login_control_key($programId), '0');
        return $value === '1';
    }
}

if (!function_exists('set_program_login_unlocked')) {
    function set_program_login_unlocked(mysqli $conn, int $programId, bool $unlocked, ?int $updatedBy = null): bool
    {
        $programId = (int) $programId;
        if ($programId <= 0) {
            return false;
        }

        return set_system_control_value(
            $conn,
            program_login_control_key($programId),
            $unlocked ? '1' : '0',
            $updatedBy
        );
    }
}

if (!function_exists('get_program_login_lock_map')) {
    function get_program_login_lock_map(mysqli $conn): array
    {
        if (!ensure_system_controls_table($conn)) {
            return [];
        }

        $sql = "
            SELECT control_key, control_value
            FROM tbl_system_controls
            WHERE control_key LIKE 'program_login_lock_%'
        ";
        $result = $conn->query($sql);
        if (!$result) {
            return [];
        }

        $map = [];
        while ($row = $result->fetch_assoc()) {
            $key = (string) ($row['control_key'] ?? '');
            if (!preg_match('/^program_login_lock_(\d+)$/', $key, $matches)) {
                continue;
            }
            $programId = (int) ($matches[1] ?? 0);
            if ($programId <= 0) {
                continue;
            }
            $map[$programId] = ((string) ($row['control_value'] ?? '0')) === '1';
        }

        return $map;
    }
}

if (!function_exists('global_sat_cutoff_enabled_control_key')) {
    function global_sat_cutoff_enabled_control_key(): string
    {
        return 'global_sat_cutoff_enabled';
    }
}

if (!function_exists('global_sat_cutoff_value_control_key')) {
    function global_sat_cutoff_value_control_key(): string
    {
        return 'global_sat_cutoff_value';
    }
}

if (!function_exists('global_sat_cutoff_ranges_control_key')) {
    function global_sat_cutoff_ranges_control_key(): string
    {
        return 'global_sat_cutoff_ranges';
    }
}

if (!function_exists('is_global_sat_cutoff_enabled')) {
    function is_global_sat_cutoff_enabled(mysqli $conn): bool
    {
        $value = get_system_control_value($conn, global_sat_cutoff_enabled_control_key(), '0');
        return $value === '1';
    }
}

if (!function_exists('get_global_sat_cutoff_value')) {
    function get_global_sat_cutoff_value(mysqli $conn): ?int
    {
        $value = trim(get_system_control_value($conn, global_sat_cutoff_value_control_key(), ''));
        if ($value === '' || !preg_match('/^\d+$/', $value)) {
            return null;
        }

        $parsed = (int) $value;
        return $parsed >= 0 ? $parsed : null;
    }
}

if (!function_exists('normalize_sat_cutoff_ranges')) {
    function normalize_sat_cutoff_ranges(array $ranges): ?array
    {
        $normalized = [];

        foreach ($ranges as $range) {
            if (!is_array($range) || !array_key_exists('min', $range) || !array_key_exists('max', $range)) {
                return null;
            }

            if (!is_numeric($range['min']) || !is_numeric($range['max'])) {
                return null;
            }

            $min = (int) $range['min'];
            $max = (int) $range['max'];

            if ($min < 0 || $max < 0 || $min > 9999 || $max > 9999 || $min > $max) {
                return null;
            }

            $normalized[] = [
                'min' => $min,
                'max' => $max
            ];
        }

        if (empty($normalized)) {
            return [];
        }

        usort($normalized, static function (array $left, array $right): int {
            if ($left['min'] === $right['min']) {
                return $left['max'] <=> $right['max'];
            }

            return $left['min'] <=> $right['min'];
        });

        $merged = [];
        foreach ($normalized as $range) {
            if (empty($merged)) {
                $merged[] = $range;
                continue;
            }

            $lastIndex = count($merged) - 1;
            $lastRange = $merged[$lastIndex];

            if ($range['min'] <= ($lastRange['max'] + 1)) {
                if ($range['max'] > $lastRange['max']) {
                    $merged[$lastIndex]['max'] = $range['max'];
                }
                continue;
            }

            $merged[] = $range;
        }

        return $merged;
    }
}

if (!function_exists('parse_sat_cutoff_ranges_text')) {
    function parse_sat_cutoff_ranges_text(string $rawRanges, ?bool &$isValid = null): array
    {
        $isValid = true;
        $rawRanges = trim($rawRanges);

        if ($rawRanges === '') {
            return [];
        }

        $segments = preg_split('/[\r\n,]+/', $rawRanges);
        if (!is_array($segments)) {
            $isValid = false;
            return [];
        }

        $parsedRanges = [];
        foreach ($segments as $segment) {
            $segment = trim((string) $segment);
            if ($segment === '') {
                continue;
            }

            if (!preg_match('/^(\d+)\s*-\s*(\d+)$/', $segment, $matches)) {
                $isValid = false;
                return [];
            }

            $min = (int) ($matches[1] ?? 0);
            $max = (int) ($matches[2] ?? 0);

            $parsedRanges[] = [
                'min' => $min,
                'max' => $max
            ];
        }

        $normalized = normalize_sat_cutoff_ranges($parsedRanges);
        if ($normalized === null) {
            $isValid = false;
            return [];
        }

        return $normalized;
    }
}

if (!function_exists('serialize_sat_cutoff_ranges')) {
    function serialize_sat_cutoff_ranges(array $ranges): string
    {
        $normalized = normalize_sat_cutoff_ranges($ranges);
        if ($normalized === null || empty($normalized)) {
            return '';
        }

        $parts = [];
        foreach ($normalized as $range) {
            $parts[] = ((int) $range['min']) . '-' . ((int) $range['max']);
        }

        return implode(',', $parts);
    }
}

if (!function_exists('format_sat_cutoff_ranges_for_display')) {
    function format_sat_cutoff_ranges_for_display(array $ranges, string $separator = ', '): string
    {
        $normalized = normalize_sat_cutoff_ranges($ranges);
        if ($normalized === null || empty($normalized)) {
            return '';
        }

        $parts = [];
        foreach ($normalized as $range) {
            $parts[] = ((int) $range['min']) . '-' . ((int) $range['max']);
        }

        return implode($separator, $parts);
    }
}

if (!function_exists('get_global_sat_cutoff_ranges')) {
    function get_global_sat_cutoff_ranges(mysqli $conn): array
    {
        $value = trim(get_system_control_value($conn, global_sat_cutoff_ranges_control_key(), ''));
        $isValid = true;
        $ranges = parse_sat_cutoff_ranges_text($value, $isValid);

        if (!$isValid) {
            return [];
        }

        return $ranges;
    }
}

if (!function_exists('get_global_sat_cutoff_state')) {
    function get_global_sat_cutoff_state(mysqli $conn): array
    {
        $enabled = is_global_sat_cutoff_enabled($conn);
        $value = get_global_sat_cutoff_value($conn);
        $ranges = get_global_sat_cutoff_ranges($conn);

        // Backward compatibility: legacy single-value cutoff behaves as [value-9999].
        if ($enabled && empty($ranges) && $value !== null) {
            $ranges = [
                [
                    'min' => max(0, (int) $value),
                    'max' => 9999
                ]
            ];
        }

        $active = $enabled && (!empty($ranges) || $value !== null);
        $rangeText = format_sat_cutoff_ranges_for_display($ranges, ', ');

        return [
            'enabled' => $enabled,
            'value' => $value,
            'ranges' => $ranges,
            'range_text' => $rangeText,
            'active' => $active
        ];
    }
}

if (!function_exists('get_effective_sat_cutoff')) {
    function get_effective_sat_cutoff(?int $programCutoff, bool $globalEnabled, ?int $globalCutoffValue): ?int
    {
        if ($globalEnabled && $globalCutoffValue !== null) {
            return max(0, (int) $globalCutoffValue);
        }

        if ($programCutoff === null) {
            return null;
        }

        return max(0, (int) $programCutoff);
    }
}

if (!function_exists('set_global_sat_cutoff_state')) {
    function set_global_sat_cutoff_state(
        mysqli $conn,
        bool $enabled,
        ?int $cutoffValue,
        ?int $updatedBy = null,
        ?array $cutoffRanges = null
    ): bool {
        $normalizedRanges = [];

        if (!$enabled) {
            $cutoffValue = null;
            $normalizedRanges = [];
        } elseif ($cutoffRanges !== null) {
            $normalizedRanges = normalize_sat_cutoff_ranges($cutoffRanges);
            if ($normalizedRanges === null || empty($normalizedRanges)) {
                return false;
            }

            // Keep legacy single-value key aligned for existing consumers.
            $cutoffValue = (int) $normalizedRanges[0]['min'];
        } else {
            if ($cutoffValue === null || $cutoffValue < 0 || $cutoffValue > 9999) {
                return false;
            }

            $cutoffValue = max(0, (int) $cutoffValue);
            $normalizedRanges = [
                [
                    'min' => $cutoffValue,
                    'max' => 9999
                ]
            ];
        }

        $enabledValue = $enabled ? '1' : '0';
        $cutoffControlValue = ($enabled && $cutoffValue !== null) ? (string) ((int) $cutoffValue) : '';
        $rangesControlValue = ($enabled && !empty($normalizedRanges))
            ? serialize_sat_cutoff_ranges($normalizedRanges)
            : '';

        $conn->begin_transaction();
        try {
            $savedEnabled = set_system_control_value(
                $conn,
                global_sat_cutoff_enabled_control_key(),
                $enabledValue,
                $updatedBy
            );
            if (!$savedEnabled) {
                throw new RuntimeException('Failed saving global SAT cutoff enabled state.');
            }

            $savedValue = set_system_control_value(
                $conn,
                global_sat_cutoff_value_control_key(),
                $cutoffControlValue,
                $updatedBy
            );
            if (!$savedValue) {
                throw new RuntimeException('Failed saving global SAT cutoff value.');
            }

            $savedRanges = set_system_control_value(
                $conn,
                global_sat_cutoff_ranges_control_key(),
                $rangesControlValue,
                $updatedBy
            );
            if (!$savedRanges) {
                throw new RuntimeException('Failed saving global SAT cutoff ranges.');
            }

            $conn->commit();
            return true;
        } catch (Throwable $e) {
            $conn->rollback();
            return false;
        }
    }
}
