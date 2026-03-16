<?php
/**
 * Shared helpers for Program Endorsement (EC) features.
 */

if (!function_exists('program_endorsement_column_exists')) {
    function program_endorsement_column_exists(mysqli $conn, string $column): bool
    {
        $sql = "
            SELECT COUNT(*) AS column_count
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'tbl_program_endorsements'
              AND COLUMN_NAME = ?
            LIMIT 1
        ";

        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return false;
        }

        $stmt->bind_param('s', $column);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return (int) ($row['column_count'] ?? 0) > 0;
    }
}

function ensure_program_endorsement_table(mysqli $conn): void
{
    static $schemaEnsured = false;
    if ($schemaEnsured) {
        return;
    }

    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_program_endorsements (
            endorsement_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            program_id INT(10) UNSIGNED NOT NULL,
            interview_id INT(10) UNSIGNED NOT NULL,
            endorsed_by INT(10) UNSIGNED DEFAULT NULL,
            override_cutoff TINYINT(1) NOT NULL DEFAULT 0,
            endorsed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (endorsement_id),
            UNIQUE KEY uq_program_interview (program_id, interview_id),
            KEY idx_program (program_id),
            KEY idx_interview (interview_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $conn->query($sql);

    if (!program_endorsement_column_exists($conn, 'override_cutoff')) {
        $conn->query("
            ALTER TABLE tbl_program_endorsements
            ADD COLUMN override_cutoff TINYINT(1) NOT NULL DEFAULT 0
            AFTER endorsed_by
        ");
    }

    $schemaEnsured = true;
}

function load_program_endorsements(mysqli $conn, int $programId): array
{
    ensure_program_endorsement_table($conn);

    $sql = "
        SELECT
            pe.interview_id,
            pe.override_cutoff,
            pe.endorsed_at
        FROM tbl_program_endorsements pe
        WHERE pe.program_id = ?
        ORDER BY pe.endorsed_at ASC, pe.endorsement_id ASC
    ";

    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return [];
    }

    $stmt->bind_param("i", $programId);
    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];
    while ($row = $result->fetch_assoc()) {
        $rows[] = [
            'interview_id' => (int) ($row['interview_id'] ?? 0),
            'override_cutoff' => ((int) ($row['override_cutoff'] ?? 0) === 1),
            'endorsed_at' => (string) ($row['endorsed_at'] ?? '')
        ];
    }

    $stmt->close();
    return $rows;
}
