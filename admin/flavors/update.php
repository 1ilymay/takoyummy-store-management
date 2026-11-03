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

$nameInput = $_POST['name'] ?? '';
$descriptionInput = $_POST['description'] ?? '';
$name = is_string($nameInput) ? trim($nameInput) : '';
$description = is_string($descriptionInput) ? trim($descriptionInput) : '';
$available = $_POST['is_available'] ?? '';
$error = null;
$newImage = null;
$newPhotos = [];
$oldImage = null;
$deletedPhotos = [];

if (!$conn || !$id || $name === '' || strlen($name) > 100) {
    $error = !$conn ? 'Database connection failed. Please try again later.' : 'Item name is required and must be 100 characters or fewer.';
} elseif (strlen($description) > 255) {
    $error = 'Description must be 255 characters or fewer.';
} elseif (!in_array($available, ['0', '1'], true)) {
    $error = 'Choose a valid availability status.';
}

$lookup = null;
if ($error === null) {
    $lookup = mysqli_prepare($conn, 'SELECT image FROM flavor WHERE flavor_id = ? LIMIT 1');
    if ($lookup) {
        mysqli_stmt_bind_param($lookup, 'i', $id);
        mysqli_stmt_execute($lookup);
        $existing = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
        mysqli_stmt_close($lookup);
        if (!$existing) {
            $error = 'Menu item not found.';
        } else {
            $oldImage = $existing['image'];
        }
    } else {
        error_log('Menu lookup preparation failed: ' . mysqli_error($conn));
        $error = 'Unable to load the menu item.';
    }
}

if ($error === null) {
    try {
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $newImage = save_uploaded_image($_FILES['image']);
        }
        if (isset($_FILES['photos'])) {
            $newPhotos = save_uploaded_images($_FILES['photos']);
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

if ($error === null) {
    mysqli_begin_transaction($conn);
    $image = $newImage ?? $oldImage;
    $stmt = mysqli_prepare($conn, 'UPDATE flavor SET name = ?, description = ?, image = ?, is_available = ? WHERE flavor_id = ?');
    $saved = false;
    if ($stmt) {
        $availability = (int) $available;
        mysqli_stmt_bind_param($stmt, 'sssii', $name, $description, $image, $availability, $id);
        $saved = mysqli_stmt_execute($stmt);
        if (!$saved) {
            error_log('Menu update failed: ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);
    } else {
        error_log('Menu update preparation failed: ' . mysqli_error($conn));
    }

    if ($saved && $newPhotos) {
        $photoStmt = mysqli_prepare($conn, 'INSERT INTO flavor_photo (flavor_id, photo_path) VALUES (?, ?)');
        if ($photoStmt) {
            foreach ($newPhotos as $photo) {
                mysqli_stmt_bind_param($photoStmt, 'is', $id, $photo);
                if (!mysqli_stmt_execute($photoStmt)) {
                    $saved = false;
                    error_log('Gallery photo insert failed: ' . mysqli_stmt_error($photoStmt));
                    break;
                }
            }
            mysqli_stmt_close($photoStmt);
        } else {
            $saved = false;
            error_log('Gallery photo insert preparation failed: ' . mysqli_error($conn));
        }
    }

    $removeIds = $_POST['delete_photo'] ?? [];
    if ($saved && is_array($removeIds)) {
        $photoLookup = mysqli_prepare($conn, 'SELECT photo_path FROM flavor_photo WHERE photo_id = ? AND flavor_id = ?');
        $photoDelete = mysqli_prepare($conn, 'DELETE FROM flavor_photo WHERE photo_id = ? AND flavor_id = ?');
        if (!$photoLookup || !$photoDelete) {
            $saved = false;
            error_log('Gallery photo delete preparation failed: ' . mysqli_error($conn));
        } else {
            foreach ($removeIds as $photoIdValue) {
                $photoId = filter_var($photoIdValue, FILTER_VALIDATE_INT);
                if (!$photoId) {
                    continue;
                }
                mysqli_stmt_bind_param($photoLookup, 'ii', $photoId, $id);
                if (!mysqli_stmt_execute($photoLookup)) {
                    $saved = false;
                    break;
                }
                $photoRow = mysqli_fetch_assoc(mysqli_stmt_get_result($photoLookup));
                if ($photoRow) {
                    mysqli_stmt_bind_param($photoDelete, 'ii', $photoId, $id);
                    if (!mysqli_stmt_execute($photoDelete)) {
                        $saved = false;
                        break;
                    }
                    $deletedPhotos[] = $photoRow['photo_path'];
                }
            }
            mysqli_stmt_close($photoLookup);
            mysqli_stmt_close($photoDelete);
        }
    }

    if ($saved) {
        mysqli_commit($conn);
        if ($newImage) {
            delete_uploaded_image($oldImage);
        }
        foreach ($deletedPhotos as $photo) {
            delete_uploaded_image($photo);
        }
        $_SESSION['message'] = 'Menu item updated.';
        $_SESSION['msg_type'] = 'success';
        header('Location: index.php');
        exit();
    }
    mysqli_rollback($conn);
    $error = 'Unable to save the menu item. Please try again.';
}

delete_uploaded_image($newImage);
foreach ($newPhotos as $photo) {
    delete_uploaded_image($photo);
}
$_SESSION['message'] = $error;
$_SESSION['msg_type'] = 'danger';
header('Location: ' . ($id ? 'edit.php?id=' . (int) $id : 'index.php'));
exit();
