<?php
/** @var mysqli $conn */
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/uploads.php';

require_admin();

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
$image = null;
$photos = [];
$flavorId = 0;


$sizeLabels = ['4 pcs', '8 pcs', '12 pcs'];
$prices = $_POST['prices'] ?? [];

if (!is_array($prices)) {
    $prices = [];
}


if (!$conn) {
    $_SESSION['message'] = 'Database connection failed. Please try again later.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: create.php');
    exit();
}

if ($name === '' || strlen($name) > 100) {
    $error = 'Item name is required and must be 100 characters or fewer.';
} elseif (strlen($description) > 255) {
    $error = 'Description must be 255 characters or fewer.';
} elseif (!in_array($available, ['0', '1'], true)) {
    $error = 'Choose a valid availability status.';
}


if ($error === null) {
    foreach ($sizeLabels as $size) {
        $price = $prices[$size] ?? '';

        if (!is_string($price) ||
            !is_numeric($price) ||
            (float)$price < 0 ||
            (float)$price > 99999999) {
            $error = "Enter a valid price for $size.";
            break;
        }
    }
}


if ($error === null) {
    try {
        if (isset($_FILES['image']) && $_FILES['image']['error'] !== UPLOAD_ERR_NO_FILE) {
            $image = save_uploaded_image($_FILES['image']);
        }
        if (isset($_FILES['photos'])) {
            $photos = save_uploaded_images($_FILES['photos']);
        }
    } catch (RuntimeException $exception) {
        $error = $exception->getMessage();
    }
}

if ($error === null) {
    mysqli_begin_transaction($conn);
    $stmt = mysqli_prepare($conn, 'INSERT INTO flavor (name, description, image, is_available) VALUES (?, ?, ?, ?)');
    if ($stmt) {
        $availability = (int) $available;
        mysqli_stmt_bind_param($stmt, 'sssi', $name, $description, $image, $availability);
        $saved = mysqli_stmt_execute($stmt);
        $flavorId = $saved ? mysqli_insert_id($conn) : 0;
        if (!$saved) {
            error_log('Menu item insert failed: ' . mysqli_stmt_error($stmt));
        }
        mysqli_stmt_close($stmt);

        if ($saved) {
            $photoStmt = mysqli_prepare($conn, 'INSERT INTO flavor_photo (flavor_id, photo_path) VALUES (?, ?)');
            if ($photoStmt) {
                foreach ($photos as $photo) {
                    mysqli_stmt_bind_param($photoStmt, 'is', $flavorId, $photo);
                    if (!mysqli_stmt_execute($photoStmt)) {
                        $error = 'The menu item was saved, but one or more gallery photos could not be saved.';
                        error_log('Menu gallery insert failed: ' . mysqli_stmt_error($photoStmt));
                        break;
                    }
                }
                mysqli_stmt_close($photoStmt);
            } elseif ($photos) {
                $error = 'The menu item was saved, but the gallery could not be saved.';
                error_log('Menu gallery statement preparation failed: ' . mysqli_error($conn));
            }
        } else {
            $error = 'Unable to save the menu item.';
        }
    } else {
        $error = 'Unable to save the menu item.';
        error_log('Menu item statement preparation failed: ' . mysqli_error($conn));
    }

    
if ($error === null) {
    $sizeStmt = mysqli_prepare($conn,
        "INSERT INTO flavor_size
        (flavor_id, size_label, price, is_available)
        VALUES (?, ?, ?, 1)"
    );

    if ($sizeStmt) {
        foreach ($sizeLabels as $size) {
            $price = (float)$prices[$size];

            mysqli_stmt_bind_param(
                $sizeStmt, "isd",
                $flavorId, $size, $price
            );

            if (!mysqli_stmt_execute($sizeStmt)) {
                $error = "Unable to save sizes and prices.";
                break;
            }
        }

        mysqli_stmt_close($sizeStmt);
    } else {
        $error = "Unable to prepare sizes and prices.";
    }
}

if ($error === null) {
    mysqli_commit($conn);
} else {
    mysqli_rollback($conn);
    $flavorId = 0;
}

}

if ($error !== null) {
    if ($flavorId === 0) {
        delete_uploaded_image($image);
        foreach ($photos as $photo) {
            delete_uploaded_image($photo);
        }
    }
    $_SESSION['message'] = $error;
    $_SESSION['msg_type'] = 'danger';
    $_SESSION['old'] = $_POST;
    header('Location: create.php');
    exit();
}

$_SESSION['message'] = 'Menu item added.';
$_SESSION['msg_type'] = 'success';
header('Location: index.php');
exit();
