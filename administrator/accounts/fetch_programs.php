<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
  http_response_code(403);
  exit;
}

$campusId = isset($_GET['campus_id']) ? (int) $_GET['campus_id'] : 0;

if ($campusId > 0) {
  $sql = "
    SELECT
      p.program_id,
      p.program_name,
      p.major,
      p.program_code,
      UPPER(TRIM(cp.campus_code)) AS campus_code
    FROM tbl_program p
    LEFT JOIN tbl_college c
      ON c.college_id = p.college_id
    LEFT JOIN tbl_campus cp
      ON cp.campus_id = c.campus_id
    WHERE p.status = 'active'
      AND c.campus_id = ?
    ORDER BY cp.campus_code ASC, p.program_code ASC, p.program_name ASC, p.major ASC
  ";
  $stmt = $conn->prepare($sql);
  $stmt->bind_param('i', $campusId);
  $stmt->execute();
  $result = $stmt->get_result();
} else {
  $sql = "
    SELECT
      p.program_id,
      p.program_name,
      p.major,
      p.program_code,
      UPPER(TRIM(cp.campus_code)) AS campus_code
    FROM tbl_program p
    LEFT JOIN tbl_college c
      ON c.college_id = p.college_id
    LEFT JOIN tbl_campus cp
      ON cp.campus_id = c.campus_id
    WHERE p.status = 'active'
    ORDER BY cp.campus_code ASC, p.program_code ASC, p.program_name ASC, p.major ASC
  ";
  $result = $conn->query($sql);
}

$data = [];
if ($result) {
  while ($row = $result->fetch_assoc()) {
    $data[] = $row;
  }
}

if (isset($stmt) && $stmt instanceof mysqli_stmt) {
  $stmt->close();
}

header('Content-Type: application/json');
echo json_encode($data);
