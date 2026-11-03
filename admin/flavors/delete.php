<?php
/** @var mysqli $conn */
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/uploads.php';

require_admin();

$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $_SESSION['message'] = 'Your session expired. Please try that action again.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}
if (!$conn || !$id) {
    $_SESSION['message'] = 'Invalid menu item.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}

$check = mysqli_prepare($conn, 'SELECT COUNT(*) AS total FROM order_item oi
    INNER JOIN flavor_size fs ON oi.size_id = fs.size_id WHERE fs.flavor_id = ?');
if (!$check) {
    error_log('Menu deletion check preparation failed: ' . mysqli_error($conn));
    $_SESSION['message'] = 'Unable to check existing orders.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}
mysqli_stmt_bind_param($check, 'i', $id);
mysqli_stmt_execute($check);
$orderRow = mysqli_fetch_assoc(mysqli_stmt_get_result($check));
mysqli_stmt_close($check);
if (!$orderRow || (int) $orderRow['total'] > 0) {
    $_SESSION['message'] = 'This item is already in existing orders and cannot be deleted. Mark it as Sold Out instead.';
    $_SESSION['msg_type'] = 'warning';
    header('Location: index.php');
    exit();
}

$lookup = mysqli_prepare($conn, 'SELECT image FROM flavor WHERE flavor_id = ? LIMIT 1');
if (!$lookup) {
    error_log('Menu deletion lookup preparation failed: ' . mysqli_error($conn));
    $_SESSION['message'] = 'Unable to load the menu item.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}
mysqli_stmt_bind_param($lookup, 'i', $id);
mysqli_stmt_execute($lookup);
$flavor = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
mysqli_stmt_close($lookup);
if (!$flavor) {
    $_SESSION['message'] = 'Menu item not found.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}

$gallery = [];
$photoStmt = mysqli_prepare($conn, 'SELECT photo_path FROM flavor_photo WHERE flavor_id = ?');
if ($photoStmt) {
    mysqli_stmt_bind_param($photoStmt, 'i', $id);
    mysqli_stmt_execute($photoStmt);
    $photoResult = mysqli_stmt_get_result($photoStmt);
    while ($photo = mysqli_fetch_assoc($photoResult)) {
        $gallery[] = $photo['photo_path'];
    }
    mysqli_stmt_close($photoStmt);
}

mysqli_begin_transaction($conn);
$usageDelete = mysqli_prepare($conn, 'DELETE FROM size_ingredient_usage
    WHERE size_id IN (SELECT size_id FROM flavor_size WHERE flavor_id = ?)');
$sizeDelete = mysqli_prepare($conn, 'DELETE FROM flavor_size WHERE flavor_id = ?');
$flavorDelete = mysqli_prepare($conn, 'DELETE FROM flavor WHERE flavor_id = ?');
$deleted = false;
if ($usageDelete && $sizeDelete && $flavorDelete) {
    mysqli_stmt_bind_param($usageDelete, 'i', $id);
    $deleted = mysqli_stmt_execute($usageDelete);
}
if ($deleted) {
    mysqli_stmt_bind_param($sizeDelete, 'i', $id);
    $deleted = mysqli_stmt_execute($sizeDelete);
    if ($deleted) {
        mysqli_stmt_bind_param($flavorDelete, 'i', $id);
        $deleted = mysqli_stmt_execute($flavorDelete) && mysqli_stmt_affected_rows($flavorDelete) === 1;
    }
    if ($usageDelete) {
        mysqli_stmt_close($usageDelete);
    }
}
if ($sizeDelete) {
    mysqli_stmt_close($sizeDelete);
}
if ($flavorDelete) {
    mysqli_stmt_close($flavorDelete);
}
if (!$deleted) {
    mysqli_rollback($conn);
    error_log('Menu deletion failed: ' . mysqli_error($conn));
    $_SESSION['message'] = 'Unable to delete the menu item.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: index.php');
    exit();
}
mysqli_commit($conn);

delete_uploaded_image($flavor['image']);
foreach ($gallery as $photo) {
    delete_uploaded_image($photo);
}
$_SESSION['message'] = 'Menu item deleted.';
$_SESSION['msg_type'] = 'success';
header('Location: index.php');
exit();
