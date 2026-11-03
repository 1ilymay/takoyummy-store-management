<?php
/** @var mysqli $conn */
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');

require_admin();

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = "Database connection failed. Please try again later.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$sizeId = isset($_POST['size_id']) ? (int)$_POST['size_id'] : 0;
$flavorId = isset($_POST['flavor_id']) ? (int)$_POST['flavor_id'] : 0;

$result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM order_item WHERE size_id = $sizeId");
$row = mysqli_fetch_assoc($result);

if ($row['total'] > 0) {
    $_SESSION['message'] = "This size is already in existing orders and cannot be deleted. Mark it as Sold Out instead.";
    $_SESSION['msg_type'] = "warning";
    header("Location: sizes.php?id=$flavorId");
    exit();
}

mysqli_query($conn, "DELETE FROM size_ingredient_usage WHERE size_id = $sizeId");
mysqli_query($conn, "DELETE FROM flavor_size WHERE size_id = $sizeId AND flavor_id = $flavorId");

$_SESSION['message'] = "Size deleted.";
$_SESSION['msg_type'] = "success";
header("Location: sizes.php?id=$flavorId");
exit();