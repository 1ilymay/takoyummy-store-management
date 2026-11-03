<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'orders';
$orderId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$order = null;
$items = [];

if (isset($conn) && $conn && $orderId) {
    $stmt = mysqli_prepare($conn, 'SELECT o.order_id, o.order_type, o.delivery_address, o.delivery_fee,
        o.pickup_time, o.notes, o.subtotal, o.total_amount, o.status, o.order_date,
        cp.full_name, cp.contact_number, u.email,
        p.amount_tendered, p.change_amount, p.payment_date, p.remarks
        FROM orders o
        INNER JOIN customer_profile cp ON cp.customer_id = o.customer_id
        INNER JOIN users u ON u.user_id = cp.user_id
        LEFT JOIN payment p ON p.order_id = o.order_id
        WHERE o.order_id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $orderId);
        mysqli_stmt_execute($stmt);
        $order = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }

    if ($order) {
        $itemStmt = mysqli_prepare($conn, 'SELECT oi.order_item_id, oi.quantity, oi.item_price,
            fs.size_label, f.name AS flavor_name,
            COALESCE((SELECT SUM(oia.addon_price) FROM order_item_addon oia WHERE oia.order_item_id = oi.order_item_id), 0) AS addon_total,
            (SELECT GROUP_CONCAT(a.name ORDER BY a.name SEPARATOR ", ")
                FROM order_item_addon oia
                INNER JOIN addon a ON a.addon_id = oia.addon_id
                WHERE oia.order_item_id = oi.order_item_id) AS addon_names
            FROM order_item oi
            INNER JOIN flavor_size fs ON fs.size_id = oi.size_id
            INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
            WHERE oi.order_id = ? ORDER BY oi.order_item_id');
        if ($itemStmt) {
            mysqli_stmt_bind_param($itemStmt, 'i', $orderId);
            mysqli_stmt_execute($itemStmt);
            $itemResult = mysqli_stmt_get_result($itemStmt);
            while ($item = mysqli_fetch_assoc($itemResult)) {
                $items[] = $item;
            }
            mysqli_stmt_close($itemStmt);
        }
    }
}

if (!$order) {
    http_response_code(404);
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title no-print">
    <div>
        <div class="eyebrow">Admin management</div>
        <h1>Order receipt</h1>
    </div>
    <div class="actions">
        <a class="btn secondary" href="<?= $base ?>/admin/orders.php">Back to orders</a>
        <?php if ($order) { ?><button type="button" onclick="window.print()">Print receipt</button><?php } ?>
    </div>
</div>

<?php if (!$order) { ?>
    <section class="panel empty">Order not found.</section>
<?php } else { ?>
    <article class="receipt" aria-label="Receipt for order <?= (int) $order['order_id'] ?>">
        <h2>TAKOYAKI SHOP</h2>
        <p class="center">Order receipt</p>
        <hr>
        <div>Order: #<?= (int) $order['order_id'] ?></div>
        <div>Date: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['order_date']))) ?></div>
        <div>Customer: <?= htmlspecialchars($order['full_name'] ?: 'Customer') ?></div>
        <div>Email: <?= htmlspecialchars($order['email']) ?></div>
        <?php if (!empty($order['contact_number'])) { ?><div>Phone: <?= htmlspecialchars($order['contact_number']) ?></div><?php } ?>
        <div>Order type: <?= htmlspecialchars(ucfirst($order['order_type'])) ?></div>
        <?php if (!empty($order['delivery_address'])) { ?><div>Address: <?= htmlspecialchars($order['delivery_address']) ?></div><?php } ?>
        <div>Status: <?= htmlspecialchars($order['status']) ?></div>
        <hr>
        <?php foreach ($items as $item) {
            $unitPrice = (float) $item['item_price'] + (float) $item['addon_total'];
            $lineTotal = $unitPrice * (int) $item['quantity'];
        ?>
            <div class="receipt-item">
                <div><?= (int) $item['quantity'] ?> × <?= htmlspecialchars($item['flavor_name']) ?> (<?= htmlspecialchars($item['size_label']) ?>)</div>
                <?php if (!empty($item['addon_names'])) { ?><div class="muted">Add-ons: <?= htmlspecialchars($item['addon_names']) ?></div><?php } ?>
                <div class="receipt-line"><span>₱<?= number_format($unitPrice, 2) ?> each</span><strong>₱<?= number_format($lineTotal, 2) ?></strong></div>
            </div>
        <?php } ?>
        <hr>
        <div class="receipt-line"><span>Subtotal</span><span>₱<?= number_format((float) $order['subtotal'], 2) ?></span></div>
        <div class="receipt-line"><span>Delivery fee</span><span>₱<?= number_format((float) $order['delivery_fee'], 2) ?></span></div>
        <div class="receipt-line receipt-total"><strong>Total</strong><strong>₱<?= number_format((float) $order['total_amount'], 2) ?></strong></div>
        <?php if ($order['amount_tendered'] !== null) { ?>
            <hr>
            <div>Paid: ₱<?= number_format((float) $order['amount_tendered'], 2) ?></div>
            <div>Change: ₱<?= number_format((float) $order['change_amount'], 2) ?></div>
            <?php if (!empty($order['payment_date'])) { ?><div>Payment date: <?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['payment_date']))) ?></div><?php } ?>
            <?php if (!empty($order['remarks'])) { ?><div>Payment: <?= htmlspecialchars($order['remarks']) ?></div><?php } ?>
        <?php } ?>
        <?php if (!empty($order['notes'])) { ?><hr><div>Notes: <?= htmlspecialchars($order['notes']) ?></div><?php } ?>
        <hr>
        <p class="center">Thank you for your order!</p>
    </article>
<?php } ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>