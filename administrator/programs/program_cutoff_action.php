<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

if (isset($_POST['save_cutoff'])) {

    $programId           = isset($_POST['program_id']) ? (int) $_POST['program_id'] : 0;
    $cutoffScore         = isset($_POST['cutoff_score']) ? (int) $_POST['cutoff_score'] : 0;
    $absorptiveCapacity  = isset($_POST['absorptive_capacity']) ? (int) $_POST['absorptive_capacity'] : 0;
    $regularPercentage   = isset($_POST['regular_percentage']) ? (float) $_POST['regular_percentage'] : 0;
    $etgPercentage       = isset($_POST['etg_percentage']) ? (float) $_POST['etg_percentage'] : 0;

    if (
        $programId <= 0 ||
        $cutoffScore < 0 ||
        $absorptiveCapacity < 0 ||
        $regularPercentage < 0 ||
        $regularPercentage > 100 ||
        $etgPercentage < 0 ||
        $etgPercentage > 100
    ) {
        header("Location: index.php");
        exit;
    }

    $regularPercentage = round($regularPercentage, 2);
    $etgPercentage = round($etgPercentage, 2);

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

    $sql = "
        INSERT INTO tbl_program_cutoff (
            program_id,
            cutoff_score,
            absorptive_capacity,
            regular_percentage,
            etg_percentage
        )
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            cutoff_score = VALUES(cutoff_score),
            absorptive_capacity = VALUES(absorptive_capacity),
            regular_percentage = VALUES(regular_percentage),
            etg_percentage = VALUES(etg_percentage)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param(
        "iiidd",
        $programId,
        $cutoffScore,
        $absorptiveCapacity,
        $regularPercentage,
        $etgPercentage
    );
    $stmt->execute();

    header("Location: index.php");
    exit;
}
