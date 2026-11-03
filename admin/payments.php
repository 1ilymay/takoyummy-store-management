<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'payments';
$message = null;
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
    $amountInput = $_POST['amount_tendered'] ?? '';
    $amount = filter_var($amountInput, FILTER_VALIDATE_FLOAT);
    $remarksInput = $_POST['remarks'] ?? '';
    $remarks = is_string($remarksInput) ? trim($remarksInput) : '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null) || !$orderId || $amount === false || $amount < 0 || strlen($remarks) > 255) {
        $message = 'Enter a valid order, tender amount, and remarks.';
    } else {
        mysqli_begin_transaction($conn);
        $lookup = mysqli_prepare($conn, 'SELECT total_amount, status, payment_status FROM orders WHERE order_id = ? FOR UPDATE');
        $validOrder = false;
        if ($lookup) {
            mysqli_stmt_bind_param($lookup, 'i', $orderId);
            if (mysqli_stmt_execute($lookup)) {
                $order = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
                $validOrder = (bool) $order;
            }
            mysqli_stmt_close($lookup);
        }

        if (!$validOrder) {
            $message = 'Order not found.';
        } elseif ($order['status'] === 'Cancelled') {
            $message = 'Payment cannot be recorded for a cancelled order.';
        } elseif ($order['payment_status'] === 'Paid') {
            $message = 'This order has already been paid.';
        } elseif ($amount < (float) $order['total_amount']) {
            $message = 'Amount tendered cannot be less than the total due.';
        } else {
            $change = $amount - (float) $order['total_amount'];
            $insert = mysqli_prepare($conn, 'INSERT INTO payment (order_id, amount_tendered, change_amount, remarks) VALUES (?, ?, ?, ?)');
            $saved = false;
            if ($insert) {
                mysqli_stmt_bind_param($insert, 'idds', $orderId, $amount, $change, $remarks);
                $saved = mysqli_stmt_execute($insert);
                if (!$saved) {
                    error_log('Payment insert failed: ' . mysqli_stmt_error($insert));
                }
                mysqli_stmt_close($insert);
            } else {
                error_log('Payment insert preparation failed: ' . mysqli_error($conn));
            }

            if ($saved) {
                $update = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'Paid' WHERE order_id = ?");
                if ($update) {
                    mysqli_stmt_bind_param($update, 'i', $orderId);
                    $saved = mysqli_stmt_execute($update);
                    if (!$saved) {
                        error_log('Order payment status update failed: ' . mysqli_stmt_error($update));
                    }
                    mysqli_stmt_close($update);
                } else {
                    $saved = false;
                    error_log('Order payment status preparation failed: ' . mysqli_error($conn));
                }
                if (!$saved) {
                    $message = 'Payment could not be completed.';
                }
            } else {
                $message = 'Payment could not be saved.';
            }
        }

        if ($message === null) {
            mysqli_commit($conn);
            $_SESSION['message'] = 'Cash payment recorded for order #' . $orderId . '.';
            $_SESSION['msg_type'] = 'success';
            header('Location: ' . $base . '/admin/payments.php');
            exit();
        }
        mysqli_rollback($conn);
    }

    $_SESSION['message'] = $message;
    $_SESSION['msg_type'] = $messageType;
    header('Location: ' . $base . '/admin/payments.php');
    exit();
}

$orders = [];
$result = mysqli_query($conn, 'SELECT o.order_id, o.order_type, o.total_amount, o.status, o.payment_status,
    o.order_date, cp.full_name
    FROM orders o LEFT JOIN customer_profile cp ON cp.customer_id = o.customer_id
    ORDER BY o.order_date DESC');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $orders[] = $row;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title"><div><div class="eyebrow">Admin management</div><h1>Cash payments</h1><p>Record cash tender, view change, and mark the order paid.</p></div></div>
<?php include __DIR__ . '/../includes/alert.php'; ?>
<section class="panel"><div class="table-wrap"><table><thead><tr><th>Order</th><th>Customer / Date</th><th>Type / Status</th><th>Amount due</th><th>Record cash payment</th></tr></thead><tbody>
<?php foreach ($orders as $order) { ?>
    <tr>
        <td>#<?= (int) $order['order_id'] ?></td>
        <td><?= htmlspecialchars($order['full_name'] ?: 'Customer') ?><div class="muted"><?= htmlspecialchars(date('M d, Y g:i A', strtotime($order['order_date']))) ?></div></td>
        <td><?= htmlspecialchars(ucfirst($order['order_type'])) ?><div class="muted"><?= htmlspecialchars($order['status']) ?> · <?= htmlspecialchars($order['payment_status']) ?></div></td>
        <td>₱<?= number_format((float) $order['total_amount'], 2) ?></td>
        <td>
            <?php if ($order['status'] !== 'Cancelled' && $order['payment_status'] !== 'Paid') { ?>
                <form method="POST" class="payment-form">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                    <input type="hidden" name="order_id" value="<?= (int) $order['order_id'] ?>">
                    <label>Amount tendered</label><input class="payment-amount" name="amount_tendered" type="number" min="<?= htmlspecialchars($order['total_amount']) ?>" step="0.01" data-total="<?= htmlspecialchars($order['total_amount']) ?>" required>
                    <label>Change</label><output class="payment-change">₱0.00</output>
                    <label>Remarks (optional)</label><input name="remarks" maxlength="255">
                    <button class="small" type="submit">Record payment</button>
                </form>
            <?php } else { ?><a class="btn small secondary" href="<?= $base ?>/admin/receipt.php?id=<?= (int) $order['order_id'] ?>">View receipt</a><?php } ?>
        </td>
    </tr>
<?php } ?>
</tbody></table></div></section>
<script>
    document.querySelectorAll('.payment-form').forEach(function(form) {
        var amount = form.querySelector('.payment-amount');
        var change = form.querySelector('.payment-change');
        amount.addEventListener('input', function() {
            change.textContent = '₱' + Math.max(0, Number(amount.value || 0) - Number(amount.dataset.total)).toFixed(2);
        });
    });
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
