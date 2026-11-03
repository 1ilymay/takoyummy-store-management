<?php
/** @var mysqli $conn */

$base = "/takoyaki-shop-infoman";

if (!isset($page)) {
    $page = "menu";
}

$settings = [];
$result = mysqli_query($conn, "SELECT setting_key, setting_value FROM settings");

if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
}

$opening = $settings['opening_time'] ?? '10:00:00';
$closing = $settings['closing_time'] ?? '20:00:00';
$now = date("H:i:s");
$isOpen = ($now >= $opening && $now < $closing);

$cartCount = 0;

if (isset($_SESSION['cart'])) {
    foreach ($_SESSION['cart'] as $item) {
        $cartCount += (int) $item['qty'];
    }
}

$isCustomer = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'customer';
$isAdmin = isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Takoyummy</title>
<link rel="stylesheet" href="<?= $base ?>/includes/style.css">
</head>
<body>

<div class="topline"></div>

<header>
    <div class="header-inner">

        <div class="brand">
            <div class="brand-mark">
          <img src="<?= $base ?>/images/takoyaki_logo.jpg" alt="Takoyaki Shop Logo">
             </div>
            <div>
                <strong>Takoyummy</strong>
                <small>Fresh from our griddle</small>
            </div>
        </div>

        <nav class="nav <?= $isAdmin ? 'admin-nav' : 'customer-nav' ?>">

            <?php if ($isAdmin) { ?>

                <a href="<?= $base ?>/admin/flavors/index.php"
                class="<?= ($adminPage ?? '') === 'menu' ? 'active' : '' ?>">
                    Menu Management
                </a>

                <a href="<?= $base ?>/admin/inventory.php"
                class="<?= ($adminPage ?? '') === 'inventory' ? 'active' : '' ?>">
                    Inventory
                </a>

                <a href="<?= $base ?>/admin/orders.php"
                class="<?= ($adminPage ?? '') === 'orders' ? 'active' : '' ?>">
                    Orders & Receipts
                </a>

                <a href="<?= $base ?>/admin/payments.php"
                class="<?= ($adminPage ?? '') === 'payments' ? 'active' : '' ?>">
                    Payments
                </a>

                <a href="<?= $base ?>/admin/reports.php"
                class="<?= ($adminPage ?? '') === 'reports' ? 'active' : '' ?>">
                    Reports
                </a>

                <a href="<?= $base ?>/admin/settings.php"
                class="<?= ($adminPage ?? '') === 'settings' ? 'active' : '' ?>">
                    Settings
                </a>

                <a href="<?= $base ?>/admin/users.php"
                class="<?= ($adminPage ?? '') === 'users' ? 'active' : '' ?>">
                    User Management
                </a>

            <?php } else { ?>

                <a href="<?= $base ?>/menu.php"
                class="<?= $page === 'menu' ? 'active' : '' ?>">Menu</a>

                <?php if ($isCustomer) { ?>
                    <a href="<?= $base ?>/orders.php"
                    class="<?= $page === 'orders' ? 'active' : '' ?>">My Orders</a>

                    <a href="<?= $base ?>/user/profile.php"
                    class="<?= $page === 'account' ? 'active' : '' ?>">Account</a>
                <?php } ?>

            <?php } ?>

        </nav>

        <div class="header-actions">
            <?php if (isset($_SESSION['user_name'])) { ?>

                <?php if ($isCustomer) { ?>
                    <a class="greeting" href="<?= $base ?>/user/profile.php">
                        Hello, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>!
                    </a>

                    <a class="btn light" href="<?= $base ?>/menu.php#cartPanel">
                        Cart <span><?= $cartCount ?></span>
                    </a>
                <?php } else { ?>
                    <span class="greeting">
                        Hello, <strong><?= htmlspecialchars($_SESSION['user_name']) ?></strong>!
                    </span>
                <?php } ?>

                <a class="btn ghost" href="<?= $base ?>/logout.php">Log out</a>

            <?php } else { ?>

                <a class="btn light" href="<?= $base ?>/login.php">Sign in</a>

            <?php } ?>
        </div>

    </div>

    <div class="statusline">
        <span>
            <i class="status-dot"></i>
            <?= $isOpen ? "Open for orders" : "Currently closed" ?>
            · <?= date("g:i A", strtotime($opening)) ?>–<?= date("g:i A", strtotime($closing)) ?>
        </span>

        <span>Pick-up · Dine-in · Delivery</span>
    </div>
</header>

<main>
<div class="wrap">