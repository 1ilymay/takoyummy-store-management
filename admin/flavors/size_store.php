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

$flavorId = isset($_POST['flavor_id']) ? (int)$_POST['flavor_id'] : 0;
$label = isset($_POST['size_label']) ? trim($_POST['size_label']) : "";
$price = isset($_POST['price']) ? trim($_POST['price']) : "";

$result = mysqli_query($conn, "SELECT flavor_id FROM flavor WHERE flavor_id = $flavorId");
if (mysqli_num_rows($result) == 0) {
    $_SESSION['message'] = "Menu item not found.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$errors = validate_size($label, $price);

if (count($errors) == 0) {
    $stmt = mysqli_prepare($conn, "SELECT size_id FROM flavor_size WHERE flavor_id = ? AND size_label = ?");
    mysqli_stmt_bind_param($stmt, "is", $flavorId, $label);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    if (mysqli_num_rows($result) > 0) {
        $errors[] = "This flavor already has a size of " . $label . ".";
    }
}

if (count($errors) > 0) {
    $_SESSION['message'] = implode(" ", $errors);
    $_SESSION['msg_type'] = "danger";
    $_SESSION['old_size'] = $_POST;
    header("Location: sizes.php?id=$flavorId");
    exit();
}

$sql = "INSERT INTO flavor_size (flavor_id, size_label, price, is_available) VALUES (?, ?, ?, 1)";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "iss", $flavorId, $label, $price);
mysqli_stmt_execute($stmt);

$_SESSION['message'] = "Size added.";
$_SESSION['msg_type'] = "success";
header("Location: sizes.php?id=$flavorId");
exit();