<?php
/** @var mysqli $conn */
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['message'] = "Your session expired. Please try that action again.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = "Database connection failed. Please try again later.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$stmt = $id ? mysqli_prepare($conn, "UPDATE flavor SET is_available = 1 - is_available WHERE flavor_id = ?") : false;
if (!$stmt) {
    $_SESSION['message'] = "Invalid menu item.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}
mysqli_stmt_bind_param($stmt, 'i', $id);
if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
    $_SESSION['message'] = "Unable to update availability.";
    $_SESSION['msg_type'] = "danger";
    error_log('Menu availability update failed: ' . mysqli_stmt_error($stmt));
    mysqli_stmt_close($stmt);
    header("Location: index.php");
    exit();
}
mysqli_stmt_close($stmt);

$_SESSION['message'] = "Availability updated.";
$_SESSION['msg_type'] = "success";
header("Location: index.php");
exit();