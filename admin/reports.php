<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'reports';
$today = date('Y-m-d');
$monthStart = date('Y-m-01');
$start = is_string($_GET['start'] ?? null) ? $_GET['start'] : $monthStart;
$end = is_string($_GET['end'] ?? null) ? $_GET['end'] : $today;
if (!DateTime::createFromFormat('Y-m-d', $start) || !DateTime::createFromFormat('Y-m-d', $end) || $start > $end) {
    $start = $monthStart;
    $end = $today;
}
$startDateTime = $start . ' 00:00:00';
$endDateTime = $end . ' 23:59:59';

$summary = ['order_count' => 0, 'sales' => 0, 'delivery_fees' => 0, 'cancelled_count' => 0];
$summaryStmt = mysqli_prepare($conn, "SELECT COUNT(*) AS order_count,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS sales,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_status = 'Paid' THEN delivery_fee ELSE 0 END), 0) AS delivery_fees,
    COALESCE(SUM(status = 'Cancelled'), 0) AS cancelled_count
    FROM orders WHERE order_date BETWEEN ? AND ?");
if ($summaryStmt) {
    mysqli_stmt_bind_param($summaryStmt, 'ss', $startDateTime, $endDateTime);
    mysqli_stmt_execute($summaryStmt);
    $summary = mysqli_fetch_assoc(mysqli_stmt_get_result($summaryStmt)) ?: $summary;
    mysqli_stmt_close($summaryStmt);
}

$typeSales = [];
$typeStmt = mysqli_prepare($conn, "SELECT order_type, COUNT(*) AS order_count,
    COALESCE(SUM(CASE WHEN status = 'Completed' AND payment_status = 'Paid' THEN total_amount ELSE 0 END), 0) AS sales
    FROM orders WHERE order_date BETWEEN ? AND ? GROUP BY order_type ORDER BY order_type");
if ($typeStmt) {
    mysqli_stmt_bind_param($typeStmt, 'ss', $startDateTime, $endDateTime);
    mysqli_stmt_execute($typeStmt);
    $result = mysqli_stmt_get_result($typeStmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $typeSales[] = $row;
    }
    mysqli_stmt_close($typeStmt);
}

$completedOrders = [];
$ordersStmt = mysqli_prepare($conn, "SELECT order_id, order_type, subtotal, delivery_fee, total_amount, order_date
    FROM orders WHERE status = 'Completed' AND payment_status = 'Paid' AND order_date BETWEEN ? AND ? ORDER BY order_date");
if ($ordersStmt) {
    mysqli_stmt_bind_param($ordersStmt, 'ss', $startDateTime, $endDateTime);
    mysqli_stmt_execute($ordersStmt);
    $result = mysqli_stmt_get_result($ordersStmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $completedOrders[] = $row;
    }
    mysqli_stmt_close($ordersStmt);
}

$bestSellers = [];
$bestStmt = mysqli_prepare($conn, "SELECT f.name AS flavor_name, fs.size_label, SUM(oi.quantity) AS quantity_sold
    FROM order_item oi JOIN orders o ON o.order_id = oi.order_id
    JOIN flavor_size fs ON fs.size_id = oi.size_id JOIN flavor f ON f.flavor_id = fs.flavor_id
    WHERE o.status = 'Completed' AND payment_status = 'Paid' AND o.order_date BETWEEN ? AND ?
    GROUP BY f.flavor_id, f.name, fs.size_id, fs.size_label ORDER BY quantity_sold DESC, f.name LIMIT 50");
if ($bestStmt) {
    mysqli_stmt_bind_param($bestStmt, 'ss', $startDateTime, $endDateTime);
    mysqli_stmt_execute($bestStmt);
    $result = mysqli_stmt_get_result($bestStmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $bestSellers[] = $row;
    }
    mysqli_stmt_close($bestStmt);
}

$ingredientUsage = [];
$usageStmt = mysqli_prepare($conn, 'SELECT i.name, i.unit, SUM(l.quantity_used) AS quantity_used
    FROM ingredient_usage_log l JOIN ingredient i ON i.ingredient_id = l.ingredient_id
    WHERE l.recorded_at BETWEEN ? AND ? GROUP BY i.ingredient_id, i.name, i.unit ORDER BY i.name');
if ($usageStmt) {
    mysqli_stmt_bind_param($usageStmt, 'ss', $startDateTime, $endDateTime);
    mysqli_stmt_execute($usageStmt);
    $result = mysqli_stmt_get_result($usageStmt);
    while ($row = mysqli_fetch_assoc($result)) {
        $ingredientUsage[] = $row;
    }
    mysqli_stmt_close($usageStmt);
}

$stock = [];
$stockResult = mysqli_query($conn, 'SELECT ingredient_id, name, unit, stock_qty, low_stock_threshold FROM ingredient ORDER BY name');
while ($stockResult && $row = mysqli_fetch_assoc($stockResult)) {
    $stock[] = $row;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title"><div><div class="eyebrow">Admin management</div><h1>Reports</h1><p>Sales, order types, best-selling flavors, and ingredient use for a selected period.</p></div></div>
<?php include __DIR__ . '/../includes/alert.php'; ?>
<section class="panel">
    <form method="GET" class="form-grid">
        <div class="field"><label for="start">From</label><input id="start" name="start" type="date" value="<?= htmlspecialchars($start) ?>"></div>
        <div class="field"><label for="end">To</label><input id="end" name="end" type="date" value="<?= htmlspecialchars($end) ?>"></div>
        <div class="field"><button type="submit">Run report</button></div>
    </form>
</section>

<section class="stats">
    <div class="stat"><small>Orders in period</small><strong><?= (int) $summary['order_count'] ?></strong></div>
    <div class="stat"><small>Completed sales</small><strong>₱<?= number_format((float) $summary['sales'], 2) ?></strong></div>
    <div class="stat"><small>Delivery fees collected</small><strong>₱<?= number_format((float) $summary['delivery_fees'], 2) ?></strong></div>
    <div class="stat"><small>Cancelled orders</small><strong><?= (int) $summary['cancelled_count'] ?></strong></div>
</section>

<section class="panel"><h2>Sales by order type</h2><div class="table-wrap"><table><thead><tr><th>Order type</th><th>Orders</th><th>Completed sales</th></tr></thead><tbody>
<?php foreach ($typeSales as $row) { ?><tr><td><?= htmlspecialchars(ucfirst($row['order_type'])) ?></td><td><?= (int) $row['order_count'] ?></td><td>₱<?= number_format((float) $row['sales'], 2) ?></td></tr><?php } ?>
</tbody></table></div></section>

<section class="panel"><h2>Completed orders</h2><div class="table-wrap"><table><thead><tr><th>Order</th><th>Date</th><th>Type</th><th>Subtotal</th><th>Delivery fee</th><th>Total</th></tr></thead><tbody>
<?php $completedTotal = 0.0; foreach ($completedOrders as $row) { $completedTotal += (float) $row['total_amount']; ?><tr><td>#<?= (int) $row['order_id'] ?></td><td><?= htmlspecialchars(date('M d, Y g:i A', strtotime($row['order_date']))) ?></td><td><?= htmlspecialchars(ucfirst($row['order_type'])) ?></td><td>₱<?= number_format((float) $row['subtotal'], 2) ?></td><td>₱<?= number_format((float) $row['delivery_fee'], 2) ?></td><td>₱<?= number_format((float) $row['total_amount'], 2) ?></td></tr><?php } ?>
<tr><th colspan="5">Total completed sales</th><th>₱<?= number_format($completedTotal, 2) ?></th></tr>
</tbody></table></div></section>

<section class="panel"><h2>Best-selling flavors and sizes</h2><div class="table-wrap"><table><thead><tr><th>Flavor</th><th>Size</th><th>Quantity sold</th></tr></thead><tbody>
<?php foreach ($bestSellers as $row) { ?><tr><td><?= htmlspecialchars($row['flavor_name']) ?></td><td><?= htmlspecialchars($row['size_label']) ?></td><td><?= (int) $row['quantity_sold'] ?></td></tr><?php } ?>
</tbody></table></div></section>

<section class="panel"><h2>Ingredient usage in period</h2><div class="table-wrap"><table><thead><tr><th>Ingredient</th><th>Consumed</th></tr></thead><tbody>
<?php foreach ($ingredientUsage as $row) { ?><tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format((float) $row['quantity_used'], 2) . ' ' . htmlspecialchars($row['unit']) ?></td></tr><?php } ?>
</tbody></table></div></section>

<section class="panel"><h2>Current ingredient stock</h2><div class="table-wrap"><table><thead><tr><th>Ingredient</th><th>Current stock</th><th>Low-stock level</th><th>Status</th></tr></thead><tbody>
<?php foreach ($stock as $row) { $low = (float) $row['stock_qty'] <= (float) $row['low_stock_threshold']; ?><tr><td><?= htmlspecialchars($row['name']) ?></td><td><?= number_format((float) $row['stock_qty'], 2) . ' ' . htmlspecialchars($row['unit']) ?></td><td><?= number_format((float) $row['low_stock_threshold'], 2) . ' ' . htmlspecialchars($row['unit']) ?></td><td><?= $low ? '<span class="status SoldOut">LOW STOCK</span>' : '<span class="status Available">OK</span>' ?></td></tr><?php } ?>
</tbody></table></div></section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
