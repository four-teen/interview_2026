<?php
/**
 * Monitoring-only ranking presentation helpers.
 *
 * Monitoring shows rows in lock-priority order:
 *   SCC -> ETG -> REGULAR
 * while preserving the shared academic rank numbering from the
 * common ranking helper for storage and cross-module consistency.
 */

require_once __DIR__ . '/../config/program_ranking_lock.php';

if (!function_exists('monitoring_program_ranking_resolve_section')) {
    function monitoring_program_ranking_resolve_section(array $row): string
    {
        $rawSection = program_ranking_normalize_section((string) ($row['row_section'] ?? 'regular'));
        if ($rawSection !== 'regular') {
            return $rawSection;
        }

        if ((bool) ($row['is_endorsement'] ?? false)) {
            return 'scc';
        }

        $classification = strtoupper(trim((string) ($row['classification'] ?? 'REGULAR')));
        return $classification === 'REGULAR' ? 'regular' : 'etg';
    }
}

if (!function_exists('monitoring_program_ranking_build_ranges')) {
    function monitoring_program_ranking_build_ranges(array $values): array
    {
        $numbers = [];
        foreach ($values as $value) {
            $number = (int) $value;
            if ($number > 0) {
                $numbers[] = $number;
            }
        }

        if (empty($numbers)) {
            return [];
        }

        sort($numbers);
        $ranges = [];
        $start = $numbers[0];
        $end = $numbers[0];

        for ($i = 1, $count = count($numbers); $i < $count; $i++) {
            $number = $numbers[$i];
            if ($number === ($end + 1)) {
                $end = $number;
                continue;
            }
            $ranges[] = $start === $end ? (string) $start : ($start . '-' . $end);
            $start = $number;
            $end = $number;
        }

        $ranges[] = $start === $end ? (string) $start : ($start . '-' . $end);
        return $ranges;
    }
}

if (!function_exists('monitoring_program_ranking_transform_payload')) {
    function monitoring_program_ranking_transform_payload(array $payload): array
    {
        if (!($payload['success'] ?? false)) {
            return $payload;
        }

        $rows = is_array($payload['rows'] ?? null) ? $payload['rows'] : [];
        $buckets = [
            'inside_scc' => [],
            'inside_etg' => [],
            'inside_regular' => [],
            'outside_regular' => [],
            'outside_scc' => [],
            'outside_etg' => [],
        ];

        foreach ($rows as $row) {
            $section = monitoring_program_ranking_resolve_section($row);
            $isOutsideCapacity = (bool) ($row['is_outside_capacity'] ?? false);

            if ($isOutsideCapacity) {
                if ($section === 'scc') {
                    $buckets['outside_scc'][] = $row;
                } elseif ($section === 'etg') {
                    $buckets['outside_etg'][] = $row;
                } else {
                    $buckets['outside_regular'][] = $row;
                }
                continue;
            }

            if ($section === 'scc') {
                $buckets['inside_scc'][] = $row;
            } elseif ($section === 'etg') {
                $buckets['inside_etg'][] = $row;
            } else {
                $buckets['inside_regular'][] = $row;
            }
        }

        $orderedRows = array_merge(
            $buckets['inside_scc'],
            $buckets['inside_etg'],
            $buckets['inside_regular'],
            $buckets['outside_regular'],
            $buckets['outside_scc'],
            $buckets['outside_etg']
        );

        $lockedSequenceNumbers = [];
        foreach ($orderedRows as $index => &$row) {
            $sequenceNo = $index + 1;
            $row['sequence_no'] = $sequenceNo;
            if ((bool) ($row['is_locked'] ?? false)) {
                $lockedSequenceNumbers[] = $sequenceNo;
            }
        }
        unset($row);

        $payload['rows'] = $orderedRows;
        $payload['locks'] = [
            'active_count' => count($lockedSequenceNumbers),
            'max_locked_rank' => empty($lockedSequenceNumbers) ? 0 : max($lockedSequenceNumbers),
            'ranges' => monitoring_program_ranking_build_ranges($lockedSequenceNumbers),
        ];

        return $payload;
    }
}

if (!function_exists('monitoring_program_ranking_fetch_payload')) {
    function monitoring_program_ranking_fetch_payload(mysqli $conn, int $programId): array
    {
        $payload = program_ranking_fetch_payload($conn, $programId, null);
        return monitoring_program_ranking_transform_payload($payload);
    }
}
