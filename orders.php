<?php
session_start();

require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';

require_customer();

$page = 'orders';
$orders = [];
$ordersError = false;
$orderProducts = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);

    if (!verify_csrf_token($_POST['csrf_token'] ?? null) || !$orderId) {
        $_SESSION['message'] = 'Invalid order cancellation request.';
        $_SESSION['msg_type'] = 'danger';
    } else {
        $sql = "UPDATE orders o
                INNER JOIN customer_profile cp ON cp.customer_id = o.customer_id
                SET o.status = 'Cancelled'
                WHERE o.order_id = ?
                AND cp.user_id = ?
                AND o.status = 'Pending'";

        $stmt = mysqli_prepare($conn, $sql);

        if ($stmt) {
            $userId = (int) $_SESSION['user_id'];

            mysqli_stmt_bind_param($stmt, 'ii', $orderId, $userId);

            if (mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1) {
                $_SESSION['message'] = 'Your order has been cancelled.';
                $_SESSION['msg_type'] = 'success';
            } else {
                $_SESSION['message'] = 'Only your pending orders can be cancelled.';
                $_SESSION['msg_type'] = 'danger';
            }

            mysqli_stmt_close($stmt);
        } else {
            $ordersError = true;
            error_log(mysqli_error($conn));
        }
    }

    header('Location: /takoyaki-shop-infoman/orders.php');
    exit();
}

$sql = "SELECT
            o.order_id,
            o.order_type,
            o.total_amount,
            o.status,
            o.order_date,
            o.delivery_address,
            o.pickup_time,
            p.payment_id
        FROM orders o
        INNER JOIN customer_profile cp
            ON cp.customer_id = o.customer_id
        LEFT JOIN payment p
            ON p.order_id = o.order_id
        WHERE cp.user_id = ?
        ORDER BY o.order_date DESC";

if (isset($conn) && $conn) {
    $stmt = mysqli_prepare($conn, $sql);

    if ($stmt) {
        $userId = (int) $_SESSION['user_id'];

        mysqli_stmt_bind_param($stmt, 'i', $userId);

        if (mysqli_stmt_execute($stmt)) {
            $result = mysqli_stmt_get_result($stmt);

            if ($result) {
                while ($row = mysqli_fetch_assoc($result)) {
                    $orders[] = $row;
                }
            }
        } else {
            $ordersError = true;
        }

        mysqli_stmt_close($stmt);
    } else {
        $ordersError = true;
        error_log(mysqli_error($conn));
    }
} else {
    $ordersError = true;
}

if (!$ordersError && $conn) {
    $userId = (int) $_SESSION['user_id'];

    $itemStmt = mysqli_prepare($conn, '
        SELECT DISTINCT
            oi.order_id,
            f.flavor_id,
            f.name,
            r.rating
        FROM order_item oi
        INNER JOIN flavor_size fs ON fs.size_id = oi.size_id
        INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
        LEFT JOIN product_review r
            ON r.flavor_id = f.flavor_id
            AND r.user_id = ?
        INNER JOIN orders o ON o.order_id = oi.order_id
        INNER JOIN customer_profile cp ON cp.customer_id = o.customer_id
        WHERE cp.user_id = ?
        ORDER BY oi.order_id DESC, f.name
    ');

    if ($itemStmt) {
        mysqli_stmt_bind_param($itemStmt, 'ii', $userId, $userId);

        if (mysqli_stmt_execute($itemStmt)) {
            $itemResult = mysqli_stmt_get_result($itemStmt);

            while ($item = mysqli_fetch_assoc($itemResult)) {
                $orderProducts[$item['order_id']][] = $item;
            }
        }

        mysqli_stmt_close($itemStmt);
    }
}

include __DIR__ . '/includes/header.php';
?>

<div class="page-title">
    <div>
        <div class="eyebrow">My account</div>
        <h1>My Orders</h1>
        <p>View your current and previous orders.</p>
    </div>
</div>

<?php include __DIR__ . '/includes/alert.php'; ?>

<section class="panel">
    <?php if ($ordersError) { ?>

        <div class="empty">
            We couldn't load your orders right now. Please try again later.
        </div>

    <?php } elseif (count($orders) > 0) { ?>

        <?php foreach ($orders as $order) { ?>

            <div class="order-card">

                <div class="order-card-head">
                    <div>
                        <strong>Order #<?= (int) $order['order_id'] ?></strong>

                        <p>
                            <?= htmlspecialchars(date('M d, Y', strtotime($order['order_date']))) ?>
                            · <?= htmlspecialchars(ucwords(str_replace('-', ' ', $order['order_type']))) ?>
                        </p>
                    </div>

                    <span class="status <?= htmlspecialchars(strtolower($order['status'])) ?>">
                        <?= htmlspecialchars($order['status']) ?>
                    </span>
                </div>

                <p>
                    Total:
                    <strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong>
                </p>

                <?php if ($order['order_type'] === 'delivery' && !empty($order['delivery_address'])) { ?>
                    <p class="tiny muted">
                        Delivery to: <?= htmlspecialchars($order['delivery_address']) ?>
                    </p>
                <?php } ?>

                <?php if ($order['order_type'] === 'pickup' && !empty($order['pickup_time'])) { ?>
                    <p class="tiny muted">
                        Pickup: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['pickup_time']))) ?>
                    </p>
                <?php } ?>

                <p class="tiny muted">
                    <?= $order['payment_id'] ? 'Paid' : 'Payment due on fulfillment' ?>
                </p>

                                <?php if ($order['status'] === 'Pending') { ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="csrf_token"
                               value="<?= htmlspecialchars(csrf_token()) ?>">

                        <input type="hidden" name="order_id"
                               value="<?= (int) $order['order_id'] ?>">

                        <button type="submit" class="small danger">
                            Cancel pending order
                        </button>
                    </form>
                <?php } ?>

             
                <?php if (
                    $order['status'] === 'Completed'
                    && !empty($order['payment_id'])
                ) { ?>

                    <div class="order-reviews" style="margin-top: 16px;">
                        <h4>Review your purchased items</h4>

                        <?php foreach ($orderProducts[$order['order_id']] ?? [] as $item) { ?>

                            <div style="display:flex; align-items:center;
                                        justify-content:space-between;
                                        gap:12px; padding:10px 0;
                                        border-bottom:1px solid #eee;">

                                <strong><?= htmlspecialchars($item['name']) ?></strong>

                                <a class="btn secondary small"
                                   href="<?= $base ?>/flavors/view.php?id=<?= (int)$item['flavor_id'] ?>&review=<?= $item['rating'] === null ? 'write' : 'view' ?>">

                                    <?= $item['rating'] === null
                                        ? 'Write a Review'
                                        : 'View Review' ?>

                                </a>
                            </div>

                        <?php } ?>
                    </div>

                <?php } ?>

            </div>

        <?php } ?>

    <?php } else { ?>

        <div class="empty">
            <h3>No orders yet</h3>
            <p>You haven't placed any orders yet. Start by choosing your favorite takoyaki!</p>
            <a class="btn" href="<?= $base ?>/menu.php">Browse menu</a>
        </div>

    <?php } ?>
</section>

<?php include __DIR__ . '/includes/footer.php'; ?>