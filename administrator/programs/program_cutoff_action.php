<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

function table_column_exists(mysqli $conn, string $table, string $column): bool
{
    $sql = "
        SELECT COUNT(*) AS column_count
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
        LIMIT 1
    ";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;
    $exists = (int) ($row['column_count'] ?? 0) > 0;
    $stmt->close();

    return $exists;
}

function ensure_program_cutoff_endorsement_columns(mysqli $conn): void
{
    if (!table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_capacity')) {
        $conn->query("
            ALTER TABLE tbl_program_cutoff
            ADD COLUMN endorsement_capacity INT UNSIGNED NOT NULL DEFAULT 0
            AFTER etg_percentage
        ");
    }

    if (table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_percentage')) {
        $conn->query("
            UPDATE tbl_program_cutoff
            SET endorsement_capacity = CAST(endorsement_percentage AS UNSIGNED)
            WHERE endorsement_capacity IS NULL OR endorsement_capacity = 0
        ");
    }
}

if (isset($_POST['save_cutoff'])) {

    ensure_program_cutoff_endorsement_columns($conn);

    $programId           = isset($_POST['program_id']) ? (int) $_POST['program_id'] : 0;
    $cutoffScore         = isset($_POST['cutoff_score']) ? (int) $_POST['cutoff_score'] : 0;
    $absorptiveCapacity  = isset($_POST['absorptive_capacity']) ? (int) $_POST['absorptive_capacity'] : 0;
    $regularPercentage   = isset($_POST['regular_percentage']) ? (float) $_POST['regular_percentage'] : 0;
    $etgPercentage       = isset($_POST['etg_percentage']) ? (float) $_POST['etg_percentage'] : 0;
    $endorsementCapacity = isset($_POST['endorsement_capacity']) ? (int) $_POST['endorsement_capacity'] : 0;

    if (
        $programId <= 0 ||
        $cutoffScore < 0 ||
        $absorptiveCapacity < 0 ||
        $regularPercentage < 0 ||
        $regularPercentage > 100 ||
        $etgPercentage < 0 ||
        $etgPercentage > 100 ||
        $endorsementCapacity < 0 ||
        $endorsementCapacity > $absorptiveCapacity
    ) {
        header("Location: index.php");
        exit;
    }

    $regularPercentage = round($regularPercentage, 2);
    $etgPercentage = round($etgPercentage, 2);
    $endorsementCapacity = (int) $endorsementCapacity;

    if (abs(($regularPercentage + $etgPercentage) - 100) > 0.01) {
        header("Location: index.php");
        exit;
    }

    /*
     --------------------------------------------------------
     USE INSERT ... ON DUPLICATE KEY UPDATE
     CLEANER + FASTER
     --------------------------------------------------------
    */

    $hasLegacyEndorsementPercentageColumn = table_column_exists($conn, 'tbl_program_cutoff', 'endorsement_percentage');

    if ($hasLegacyEndorsementPercentageColumn) {
        $sql = "
            INSERT INTO tbl_program_cutoff (
                program_id,
                cutoff_score,
                absorptive_capacity,
                regular_percentage,
                etg_percentage,
                endorsement_capacity,
                endorsement_percentage
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cutoff_score = VALUES(cutoff_score),
                absorptive_capacity = VALUES(absorptive_capacity),
                regular_percentage = VALUES(regular_percentage),
                etg_percentage = VALUES(etg_percentage),
                endorsement_capacity = VALUES(endorsement_capacity),
                endorsement_percentage = VALUES(endorsement_percentage)
        ";

        $legacyEndorsementPercentage = (float) $endorsementCapacity;
        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iiiddid",
            $programId,
            $cutoffScore,
            $absorptiveCapacity,
            $regularPercentage,
            $etgPercentage,
            $endorsementCapacity,
            $legacyEndorsementPercentage
        );
    } else {
        $sql = "
            INSERT INTO tbl_program_cutoff (
                program_id,
                cutoff_score,
                absorptive_capacity,
                regular_percentage,
                etg_percentage,
                endorsement_capacity
            )
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                cutoff_score = VALUES(cutoff_score),
                absorptive_capacity = VALUES(absorptive_capacity),
                regular_percentage = VALUES(regular_percentage),
                etg_percentage = VALUES(etg_percentage),
                endorsement_capacity = VALUES(endorsement_capacity)
        ";

        $stmt = $conn->prepare($sql);
        $stmt->bind_param(
            "iiiddi",
            $programId,
            $cutoffScore,
            $absorptiveCapacity,
            $regularPercentage,
            $etgPercentage,
            $endorsementCapacity
        );
    }
    $stmt->execute();

    header("Location: index.php");
    exit;
}
