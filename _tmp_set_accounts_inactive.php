<?php
require 'config/db.php';
$sql = "UPDATE tblaccount SET status='inactive' WHERE role != 'administrator'";
if (!mysqli_query($conn, $sql)) {
    fwrite(STDERR, 'ERR:' . mysqli_error($conn));
    exit(1);
}
echo 'affected=' . mysqli_affected_rows($conn) . PHP_EOL;
