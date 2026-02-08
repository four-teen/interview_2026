<?php
/**
 * Global Database Connection (MySQLi)
 * Used across Administrator module
 */

$DB_HOST = 'localhost';
$DB_USER = 'interview_user';
$DB_PASS = 'interview_pass';
$DB_NAME = 'the_interview_db';

$conn = new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);

if ($conn->connect_error) {
    die('Database connection failed: ' . $conn->connect_error);
}

// Force UTF-8
$conn->set_charset('utf8mb4');
