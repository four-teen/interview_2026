<?php
/**
 * Shared helpers for Program Endorsement (EC) features.
 */

function ensure_program_endorsement_table(mysqli $conn): void
{
    $sql = "
        CREATE TABLE IF NOT EXISTS tbl_program_endorsements (
            endorsement_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            program_id INT(10) UNSIGNED NOT NULL,
            interview_id INT(10) UNSIGNED NOT NULL,
            endorsed_by INT(10) UNSIGNED DEFAULT NULL,
            endorsed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (endorsement_id),
            UNIQUE KEY uq_program_interview (program_id, interview_id),
            KEY idx_program (program_id),
            KEY idx_interview (interview_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ";

    $conn->query($sql);
}

function load_program_endorsements(mysqli $conn, int $programId): array
{
    ensure_program_endorsement_table($conn);

    $sql = "
        SELECT
            pe.interview_id,
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
            'endorsed_at' => (string) ($row['endorsed_at'] ?? '')
        ];
    }

    $stmt->close();
    return $rows;
}
