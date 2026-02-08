<?php
require_once '../../config/db.php';
session_start();

if (!isset($_SESSION['logged_in']) || $_SESSION['role'] !== 'administrator') {
  http_response_code(403);
  exit;
}

$sql = "
  SELECT campus_id, campus_name
  FROM tbl_campus
  WHERE status = 'active'
  ORDER BY campus_name
";

$result = $conn->query($sql);

$data = [];
while ($row = $result->fetch_assoc()) {
  $data[] = $row;
}

header('Content-Type: application/json');
echo json_encode($data);
