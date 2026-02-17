<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || ($_SESSION['role'] ?? '') !== 'administrator') {
    header('Location: ../../index.php');
    exit;
}

$rawReferer = str_replace(["\r", "\n"], '', $_SERVER['HTTP_REFERER'] ?? '');
$refererPath = parse_url($rawReferer, PHP_URL_PATH) ?: '';
$safeRedirect = (strpos($refererPath, '/administrator/colleges/') !== false) ? $rawReferer : 'index.php';

/* ============================================================
  ADD COLLEGE
============================================================ */

if (isset($_POST['add_college'])) {

    $campus_id   = (int) $_POST['campus_id'];
    $college_code = trim($_POST['college_code']);
    $college_name = trim($_POST['college_name']);

    $stmt = $conn->prepare("
        INSERT INTO tbl_college (campus_id, college_code, college_name)
        VALUES (?, ?, ?)
    ");

    $stmt->bind_param("iss", $campus_id, $college_code, $college_name);
    $stmt->execute();

    header("Location: index.php?campus_id=" . $campus_id);
    exit;
}

/* ============================================================
   TOGGLE STATUS
============================================================ */

if (isset($_POST['toggle_status'])) {

    $college_id = (int) $_POST['college_id'];
    $current_status = $_POST['current_status'];

    $new_status = ($current_status === 'active')
        ? 'inactive'
        : 'active';

    $stmt = $conn->prepare("
        UPDATE tbl_college
        SET status = ?
        WHERE college_id = ?
    ");

    $stmt->bind_param("si", $new_status, $college_id);
    $stmt->execute();

    header("Location: " . $safeRedirect);
    exit;
}
