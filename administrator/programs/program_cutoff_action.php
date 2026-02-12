<?php
require_once '../../config/db.php';
session_start();

if (isset($_POST['save_cutoff'])) {

    $programId   = (int) $_POST['program_id'];
    $cutoffScore = (int) $_POST['cutoff_score'];

    if ($programId <= 0) {
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
        INSERT INTO tbl_program_cutoff (program_id, cutoff_score)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE cutoff_score = VALUES(cutoff_score)
    ";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param("ii", $programId, $cutoffScore);
    $stmt->execute();

    header("Location: index.php");
    exit;
}
