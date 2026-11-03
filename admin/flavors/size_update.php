<?php
/** @var mysqli $conn */
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');
include('../../includes/size_check.php');

require_admin();

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = "Database connection failed. Please try again later.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$sizeId = isset($_POST['size_id']) ? (int)$_POST['size_id'] : 0;
$flavorId = isset($_POST['flavor_id']) ? (int)$_POST['flavor_id'] : 0;
$label = isset($_POST['size_label']) ? trim($_POST['size_label']) : "";
$price = isset($_POST['price']) ? trim($_POST['price']) : "";
$available = isset($_POST['is_available']) ? (int)$_POST['is_available'] : 1;

$errors = validate_size($label, $price);

if ($available !== 0 && $available !== 1) {
    $errors[] = "Availability must be Available or Sold Out.";
}

if (count($errors) == 0) {
    $stmt = mysqli_prepare($conn, "SELECT size_id FROM flavor_size WHERE flavor_id = ? AND size_label = ? AND size_id <> ?");
    mysqli_stmt_bind_param($stmt, "isi", $flavorId, $label, $sizeId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($result) > 0) {
        $errors[] = "This flavor already has a size of " . $label . ".";
    }
}

if (count($errors) > 0) {
    $_SESSION['message'] = implode(" ", $errors);
    $_SESSION['msg_type'] = "danger";
    header("Location: sizes.php?id=$flavorId");
    exit();
}

$sql = "UPDATE flavor_size SET size_label = ?, price = ?, is_available = ? WHERE size_id = ? AND flavor_id = ?";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "ssiii", $label, $price, $available, $sizeId, $flavorId);
mysqli_stmt_execute($stmt);

$_SESSION['message'] = "Size updated.";
$_SESSION['msg_type'] = "success";
header("Location: sizes.php?id=$flavorId");
exit();