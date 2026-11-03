<?php
date_default_timezone_set('Asia/Manila');

$db_host = "localhost";
$db_username = "root";
$db_passwd = "";
$db_name = "takoyaki_shop";

$conn = null;

$conn = mysqli_connect($db_host, $db_username, $db_passwd, $db_name);

if ($conn) {
    mysqli_set_charset($conn, 'utf8mb4');
} else {
    error_log("Database connection failed: " . mysqli_connect_error());
    $conn = false;
}