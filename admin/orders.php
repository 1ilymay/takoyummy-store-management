<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
require_once __DIR__ . '/../includes/mailtrap.php';
require_once __DIR__ . '/../includes/receipt_pdf.php';

require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'orders';
$statuses = ['Pending', 'Preparing', 'Ready', 'Completed', 'Cancelled'];

if (empty($_SESSION['admin_csrf'])) {
    $_SESSION['admin_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postedToken = $_POST['csrf_token'] ?? '';
    $orderId = filter_input(INPUT_POST, 'order_id', FILTER_VALIDATE_INT);
    $newStatus = $_POST['status'] ?? '';

    if (!hash_equals($_SESSION['admin_csrf'], $postedToken)) {
        $_SESSION['message'] = 'Your session expired. Please try that action again.';
        $_SESSION['msg_type'] = 'danger';
    } elseif (!$orderId || !in_array($newStatus, $statuses, true) || !$conn) {
        $_SESSION['message'] = 'Invalid order update.';
        $_SESSION['msg_type'] = 'danger';
    } else {
        mysqli_begin_transaction($conn);
        $lookup = mysqli_prepare($conn, '
                SELECT o.status, o.stock_deducted_at, o.order_type,
                    o.subtotal, o.delivery_fee, o.total_amount, o.order_date,
                    cp.full_name, u.email,
                    EXISTS (
                        SELECT 1
                        FROM payment p
                        WHERE p.order_id = o.order_id
                    ) AS is_paid
                FROM orders o
                INNER JOIN customer_profile cp ON cp.customer_id = o.customer_id
                INNER JOIN users u ON u.user_id = cp.user_id
                WHERE o.order_id = ? FOR UPDATE
            ');
        $order = null;
        if ($lookup) {
            mysqli_stmt_bind_param($lookup, 'i', $orderId);
            if (mysqli_stmt_execute($lookup)) {
                $order = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
            }
            mysqli_stmt_close($lookup);
        }

        if ($order && $newStatus === 'Completed' && (int)$order['is_paid'] === 0) {
            mysqli_rollback($conn);

            $_SESSION['message'] = 'Cannot complete this order. Payment has not been recorded yet.';
            $_SESSION['msg_type'] = 'danger';

            header('Location: orders.php');
            exit();
        }

        $validTransition = $order && (
            $order['status'] === $newStatus
            || ($order['status'] !== 'Completed' && $order['status'] !== 'Cancelled')
        );
        $error = null;
        if (!$order) {
            $error = 'Order not found.';
        } elseif (!$validTransition) {
            $error = 'Completed or cancelled orders cannot be reopened.';
        }

        if ($error === null && $newStatus === 'Completed' && empty($order['stock_deducted_at'])) {
            try {
                $requirements = order_ingredient_requirements($conn, $orderId);
                $error = check_ingredient_stock($conn, $requirements, true);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
            if ($error === null) {
                $stockUpdate = mysqli_prepare($conn, 'UPDATE ingredient SET stock_qty = stock_qty - ? WHERE ingredient_id = ? AND stock_qty >= ?');
                $logInsert = mysqli_prepare($conn, 'INSERT INTO ingredient_usage_log (order_id, ingredient_id, quantity_used) VALUES (?, ?, ?)');
                if (!$stockUpdate || !$logInsert) {
                    $error = 'Unable to record ingredient usage.';
                } else {
                    foreach ($requirements as $ingredientId => $quantityUsed) {
                        mysqli_stmt_bind_param($stockUpdate, 'did', $quantityUsed, $ingredientId, $quantityUsed);
                        if (!mysqli_stmt_execute($stockUpdate) || mysqli_stmt_affected_rows($stockUpdate) !== 1) {
                            $error = 'Insufficient ingredient stock to complete this order.';
                            break;
                        }
                        mysqli_stmt_bind_param($logInsert, 'iid', $orderId, $ingredientId, $quantityUsed);
                        if (!mysqli_stmt_execute($logInsert)) {
                            $error = 'Unable to save the ingredient usage record.';
                            break;
                        }
                    }
                    mysqli_stmt_close($stockUpdate);
                    mysqli_stmt_close($logInsert);
                }
            }
        }

        $items = [];
        $statusChanged = $order && $order['status'] !== $newStatus;
        if ($error === null && $statusChanged) {
            $itemsStmt = mysqli_prepare($conn, 'SELECT oi.quantity, oi.item_price, fs.size_label,
                    f.name AS flavor_name,
                    COALESCE(SUM(oia.addon_price), 0) AS addon_total,
                    GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ", ") AS addon_names
                FROM order_item oi
                INNER JOIN flavor_size fs ON fs.size_id = oi.size_id
                INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
                LEFT JOIN order_item_addon oia ON oia.order_item_id = oi.order_item_id
                LEFT JOIN addon a ON a.addon_id = oia.addon_id
                WHERE oi.order_id = ?
                GROUP BY oi.order_item_id, oi.quantity, oi.item_price, fs.size_label, f.name
                ORDER BY oi.order_item_id');
            if (!$itemsStmt) {
                $error = 'Unable to prepare the customer transaction email.';
                error_log('Order email item query preparation failed: ' . mysqli_error($conn));
            } else {
                mysqli_stmt_bind_param($itemsStmt, 'i', $orderId);
                if (!mysqli_stmt_execute($itemsStmt)) {
                    $error = 'Unable to load transaction items for the customer email.';
                    error_log('Order email item query failed: ' . mysqli_stmt_error($itemsStmt));
                } else {
                    $itemsResult = mysqli_stmt_get_result($itemsStmt);
                    while ($item = mysqli_fetch_assoc($itemsResult)) {
                        $items[] = $item;
                    }
                }
                mysqli_stmt_close($itemsStmt);
            }
        }

        if ($error === null) {
            $update = mysqli_prepare($conn, 'UPDATE orders
                SET status = ?, stock_deducted_at = IF(? = "Completed" AND stock_deducted_at IS NULL, NOW(), stock_deducted_at)
                WHERE order_id = ?');
            if ($update) {
                mysqli_stmt_bind_param($update, 'ssi', $newStatus, $newStatus, $orderId);
                if (!mysqli_stmt_execute($update)) {
                    $error = 'Could not update the order status.';
                    error_log('Order status update failed: ' . mysqli_stmt_error($update));
                }
                mysqli_stmt_close($update);
            } else {
                $error = 'Could not update the order status.';
                error_log('Order status update preparation failed: ' . mysqli_error($conn));
            }
        }

        if ($error === null) {
            if (!mysqli_commit($conn)) {
                error_log('Order status transaction commit failed: ' . mysqli_error($conn));
                mysqli_rollback($conn);
                $_SESSION['message'] = 'The order status could not be saved.';
                $_SESSION['msg_type'] = 'danger';
            } elseif ($statusChanged) {
                $emailLines = [
                    'Hello ' . ($order['full_name'] ?: 'Customer') . ',',
                    '',
                    'The status of your order #' . $orderId . ' has been updated to ' . $newStatus . '.',
                    '',
                    'Transaction items:',
                ];
                foreach ($items as $item) {
                    $unitPrice = (float) $item['item_price'] + (float) $item['addon_total'];
                    $emailLines[] = (int) $item['quantity'] . ' x ' . $item['flavor_name'] . ' (' . $item['size_label'] . ')'
                        . (!empty($item['addon_names']) ? ' - Add-ons: ' . $item['addon_names'] : '')
                        . ' - PHP ' . number_format($unitPrice * (int) $item['quantity'], 2);
                }
                $emailLines[] = '';
                $emailLines[] = 'Subtotal: PHP ' . number_format((float) $order['subtotal'], 2);
                $emailLines[] = 'Delivery fee: PHP ' . number_format((float) $order['delivery_fee'], 2);
                $emailLines[] = 'Grand total: PHP ' . number_format((float) $order['total_amount'], 2);
                $emailLines[] = '';
                $emailLines[] = 'Thank you for ordering from Takoyaki Shop.';

                try {
                    $pdf = build_order_receipt_pdf($order + ['order_id' => $orderId], $items);
                    mailtrap_send_receipt(
                        $order['email'],
                        $order['full_name'] ?: 'Customer',
                        'Order #' . $orderId . ' status updated: ' . $newStatus,
                        implode("\n", $emailLines),
                        $pdf
                    );
                    $_SESSION['message'] = 'Order #' . $orderId . ' status updated and receipt emailed to the customer.';
                    $_SESSION['msg_type'] = 'success';
                } catch (RuntimeException $exception) {
                    error_log('Order status email failed for order #' . $orderId . ': ' . $exception->getMessage());
                    $_SESSION['message'] = 'Order #' . $orderId . ' status was updated, but the customer email could not be sent. Check Mailtrap settings and the PHP error log.';
                    $_SESSION['msg_type'] = 'danger';
                }
            } else {
                $_SESSION['message'] = 'Order #' . $orderId . ' already has that status.';
                $_SESSION['msg_type'] = 'success';
            }
        } else {
            mysqli_rollback($conn);
            $_SESSION['message'] = $error;
            $_SESSION['msg_type'] = 'danger';
        }
    }

    header('Location: ' . $base . '/admin/orders.php');
    exit();
}

$summary = [
    'total_orders' => 0,
    'pending_orders' => 0,
    'preparing_orders' => 0,
    'ready_orders' => 0,
    'completed_sales' => 0,
];
$summaryResult = mysqli_query($conn, "SELECT COUNT(*) AS total_orders,
    COALESCE(SUM(status = 'Pending'), 0) AS pending_orders,
    COALESCE(SUM(status = 'Preparing'), 0) AS preparing_orders,
    COALESCE(SUM(status = 'Ready'), 0) AS ready_orders,
    COALESCE(SUM(CASE WHEN status = 'Completed' THEN total_amount ELSE 0 END), 0) AS completed_sales
    FROM orders");
if ($summaryResult) {
    $summary = array_merge($summary, mysqli_fetch_assoc($summaryResult) ?: []);
}

$orders = [];
$ordersResult = mysqli_query($conn, 'SELECT o.order_id, o.order_type, o.total_amount, o.status, o.payment_status, o.order_date,
    cp.full_name, cp.contact_number
    FROM orders o
    INNER JOIN customer_profile cp ON cp.customer_id = o.customer_id
    ORDER BY o.order_date DESC');
if ($ordersResult) {
    while ($row = mysqli_fetch_assoc($ordersResult)) {
        $orders[] = $row;
    }
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Admin management</div>
        <h1>Orders &amp; Receipts</h1>
        <p>Review incoming orders, update kitchen status, and print customer receipts.</p>
    </div>
</div>

<?php include __DIR__ . '/../includes/alert.php'; ?>

<section class="stats">
    <div class="stat"><small>Total orders</small><strong><?= (int) $summary['total_orders'] ?></strong></div>
    <div class="stat"><small>Pending</small><strong><?= (int) $summary['pending_orders'] ?></strong></div>
    <div class="stat"><small>Preparing / Ready</small><strong><?= (int) $summary['preparing_orders'] + (int) $summary['ready_orders'] ?></strong></div>
    <div class="stat"><small>Completed sales</small><strong>₱<?= number_format((float) $summary['completed_sales'], 2) ?></strong></div>
</section>

<section class="panel">
    <h2>Recent orders</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Order</th>
                    <th>Customer</th>
                    <th>Type / Date</th>
                    <th>Total</th>
                    <th>Status</th>
                    <th>Payment</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($orders) { ?>
                    <?php foreach ($orders as $order) { ?>
                        <tr>
                            <td><strong>#<?= (int) $order['order_id'] ?></strong></td>
                            <td>
                                <?= htmlspecialchars($order['full_name'] ?: 'Customer') ?>
                                <?php if (!empty($order['contact_number'])) { ?><div class="muted"><?= htmlspecialchars($order['contact_number']) ?></div><?php } ?>
                            </td>
                            <td><?= htmlspecialchars(ucfirst($order['order_type'])) ?><div class="muted"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['order_date']))) ?></div></td>
                            <td><strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong></td>
                            <td><span class="status <?= htmlspecialchars($order['status']) ?>"><?= htmlspecialchars($order['status']) ?></span></td>
                                            <td><?= htmlspecialchars($order['payment_status']) ?></td>
                            <td>
                                <div class="actions">
                                    <form action="<?= $base ?>/admin/orders.php" method="POST" class="admin-order-status">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['admin_csrf']) ?>">
                                        <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                                        <select name="status" aria-label="Status for order <?= (int) $order['order_id'] ?>">
                                            <?php foreach ($statuses as $status) { ?>
                                                <option value="<?= htmlspecialchars($status) ?>" <?= $status === $order['status'] ? 'selected' : '' ?>><?= htmlspecialchars($status) ?></option>
                                            <?php } ?>
                                        </select>
                                        <button type="submit" class="small">Save</button>
                                    </form>
                                    <a class="btn small secondary" href="<?= $base ?>/admin/receipt.php?id=<?= (int) $order['order_id'] ?>">Receipt</a>
                                </div>
                            </td>
                        </tr>
                    <?php } ?>
                <?php } else { ?>
                    <tr><td colspan="7" class="empty">No orders have been placed yet.</td></tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>