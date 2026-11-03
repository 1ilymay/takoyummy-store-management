<?php
session_start();
include 'includes/config.php';
include 'includes/auth.php';

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = 'Database connection failed. Please try again later.';
    $_SESSION['msg_type'] = 'danger';
    header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
    exit();
}

if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = array();
}

$action = $_POST['action'] ?? '';

if ($action == "add") {
    $sizeId = filter_input(INPUT_POST, 'size_id', FILTER_VALIDATE_INT);
    $qty = filter_input(INPUT_POST, 'qty', FILTER_VALIDATE_INT) ?: 1;
    if ($qty < 1) {
        $qty = 1;
    }
    if ($qty > 99) {
        $qty = 99;
    }

 
    $sizeStmt = mysqli_prepare($conn, "SELECT fs.size_id FROM flavor_size fs
            INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
            WHERE fs.size_id = ? AND fs.is_available = 1 AND f.is_available = 1");
    if ($sizeStmt) {
        mysqli_stmt_bind_param($sizeStmt, 'i', $sizeId);
        mysqli_stmt_execute($sizeStmt);
        $result = mysqli_stmt_get_result($sizeStmt);
        $sizeAvailable = $result && mysqli_num_rows($result) > 0;
        mysqli_stmt_close($sizeStmt);
    } else {
        $sizeAvailable = false;
        error_log('Cart size availability check failed: ' . mysqli_error($conn));
    }

    if (!$sizeAvailable) {
        $_SESSION['message'] = "Sorry, that item is currently sold out.";
        $_SESSION['msg_type'] = "danger";
    } else {
       
        $addons = array();
        if (isset($_POST['addons'])) {
            if (!is_array($_POST['addons'])) {
                $_SESSION['message'] = "Invalid add-on selection.";
                $_SESSION['msg_type'] = "danger";
                header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
                exit();
            }
            foreach ($_POST['addons'] as $addonId) {
                $validAddonId = filter_var($addonId, FILTER_VALIDATE_INT);
                if (!$validAddonId) {
                    $_SESSION['message'] = "Invalid add-on selection.";
                    $_SESSION['msg_type'] = "danger";
                    header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
                    exit();
                }
                $addons[] = (int) $validAddonId;
            }
        }
        sort($addons);

        if ($addons) {
            $addonCheck = mysqli_prepare($conn, 'SELECT COUNT(*) AS available_count FROM addon WHERE addon_id = ? AND is_available = 1');
            if (!$addonCheck) {
                error_log('Cart add-on availability check failed: ' . mysqli_error($conn));
                $_SESSION['message'] = "Unable to verify add-on availability.";
                $_SESSION['msg_type'] = "danger";
                header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
                exit();
            }
            foreach ($addons as $addonId) {
                mysqli_stmt_bind_param($addonCheck, 'i', $addonId);
                mysqli_stmt_execute($addonCheck);
                $availableAddon = mysqli_fetch_assoc(mysqli_stmt_get_result($addonCheck));
                if ((int) ($availableAddon['available_count'] ?? 0) !== 1) {
                    $_SESSION['message'] = "A selected add-on is currently unavailable.";
                    $_SESSION['msg_type'] = "danger";
                    mysqli_stmt_close($addonCheck);
                    header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
                    exit();
                }
            }
            mysqli_stmt_close($addonCheck);
        }

      
        $key = $sizeId . "-" . implode("-", $addons);
        $previousLine = $_SESSION['cart'][$key] ?? null;

        if (isset($_SESSION['cart'][$key])) {
            $_SESSION['cart'][$key]['qty'] += $qty;
            if ($_SESSION['cart'][$key]['qty'] > 99) {
                $_SESSION['cart'][$key]['qty'] = 99;
            }
        } else {
            $_SESSION['cart'][$key] = array('size_id' => $sizeId, 'qty' => $qty, 'addons' => $addons);
        }
        require_once __DIR__ . '/includes/inventory.php';
        try {
            $requirements = cart_ingredient_requirements($conn, $_SESSION['cart']);
            $stockError = check_ingredient_stock($conn, $requirements);
        } catch (RuntimeException $exception) {
            $stockError = $exception->getMessage();
        }
        if ($stockError !== null) {
            if ($previousLine === null) {
                unset($_SESSION['cart'][$key]);
            } else {
                $_SESSION['cart'][$key] = $previousLine;
            }
            $_SESSION['message'] = $stockError;
            $_SESSION['msg_type'] = 'danger';
            header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
            exit();
        }
        $_SESSION['message'] = "Added to your cart.";
    }
}

if ($action == "update") {
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    $qty = filter_input(INPUT_POST, 'qty', FILTER_VALIDATE_INT) ?: 0;
    if (isset($_SESSION['cart'][$key])) {
        $previousLine = $_SESSION['cart'][$key];
        if ($qty <= 0) {
            unset($_SESSION['cart'][$key]);
        } else {
            if ($qty > 99) {
                $qty = 99;
            }
            $_SESSION['cart'][$key]['qty'] = $qty;
            require_once __DIR__ . '/includes/inventory.php';
            try {
                $requirements = cart_ingredient_requirements($conn, $_SESSION['cart']);
                $stockError = check_ingredient_stock($conn, $requirements);
            } catch (RuntimeException $exception) {
                $stockError = $exception->getMessage();
            }
            if ($stockError !== null) {
                $_SESSION['cart'][$key] = $previousLine;
                $_SESSION['message'] = $stockError;
                $_SESSION['msg_type'] = 'danger';
            }
        }
    }
}

if ($action == "remove") {
    $key = is_string($_POST['key'] ?? null) ? $_POST['key'] : '';
    unset($_SESSION['cart'][$key]);
}


if ($action == "set_type") {
    $type = $_POST['order_type'] ?? '';
    if ($type == "delivery" || $type == "pickup" || $type == "dine-in") {
        $_SESSION['order_type'] = $type;
    }
}

header('Location: /takoyaki-shop-infoman/menu.php#cartPanel');
exit();