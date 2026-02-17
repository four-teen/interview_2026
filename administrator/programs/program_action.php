<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

$rawReferer = str_replace(["\r", "\n"], '', $_SERVER['HTTP_REFERER'] ?? '');
$refererPath = parse_url($rawReferer, PHP_URL_PATH) ?: '';
$safeRedirect = (strpos($refererPath, '/administrator/programs/') !== false) ? $rawReferer : 'index.php';

/* ADD PROGRAM */
if (isset($_POST['add_program'])) {

    $college_id   = (int) $_POST['college_id'];
    $program_code = trim($_POST['program_code']);
    $program_name = trim($_POST['program_name']);

    $stmt = $conn->prepare("
        INSERT INTO tbl_program (college_id, program_code, program_name)
        VALUES (?, ?, ?)
    ");

    $stmt->bind_param("iss", $college_id, $program_code, $program_name);
    $stmt->execute();

    header("Location: index.php");
    exit;
}

/* TOGGLE STATUS */
if (isset($_POST['toggle_status'])) {

    $program_id = (int) $_POST['program_id'];
    $current_status = $_POST['current_status'];

    $new_status = ($current_status === 'active')
        ? 'inactive'
        : 'active';

    $stmt = $conn->prepare("
        UPDATE tbl_program
        SET status = ?
        WHERE program_id = ?
    ");

    $stmt->bind_param("si", $new_status, $program_id);
    $stmt->execute();

    header("Location: " . $safeRedirect);
    exit;
}
