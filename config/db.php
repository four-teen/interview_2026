<?php
/**
 * root_folder = inteview
 * path: root_folder/config/db.php
 * Global Database Connection (MySQLi)
 * Used across Administrator module
 */

$DB_HOST = 'localhost';
$DB_USER = 'root';
$DB_PASS = 'vertrigo';
$DB_NAME = 'the_interview_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// Force UTF-8
$conn->set_charset('utf8mb4');
