<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

if (isset($_POST['add_campus'])) {

    $code = trim($_POST['campus_code']);
    $name = trim($_POST['campus_name']);

    $stmt = $conn->prepare("
        INSERT INTO tbl_campus (campus_code, campus_name)
        VALUES (?, ?)
    ");
    $stmt->bind_param("ss", $code, $name);
    $stmt->execute();

    header("Location: index.php");
    exit;
}

if (isset($_POST['toggle_status'])) {

    $campus_id = (int) ($_POST['campus_id'] ?? 0);
    $current_status = $_POST['current_status'] ?? '';

    if ($campus_id <= 0) {
        header("Location: index.php");
        exit;
    }

    $new_status = ($current_status === 'active') ? 'inactive' : 'active';

    $stmt = $conn->prepare("
        UPDATE tbl_campus
        SET status = ?
        WHERE campus_id = ?
    ");
    $stmt->bind_param("si", $new_status, $campus_id);
    $stmt->execute();

    header("Location: index.php");
    exit;
}
