<?php
require_once '../../config/db.php';

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
