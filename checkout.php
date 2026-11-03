<?php
session_start();
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/inventory.php';

if (!is_logged_in()) {
    $_SESSION['redirect_after_login'] = 'checkout';
    redirect_to_login('Please sign in or register to complete checkout.');
}
require_customer();

function load_checkout_lines($conn, array $cart, ?string &$error): array
{
    $lines = [];
    $sizeStmt = mysqli_prepare($conn, 'SELECT fs.price, fs.is_available AS size_available,
        f.name, f.is_available AS flavor_available
        FROM flavor_size fs
        INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
        WHERE fs.size_id = ? LIMIT 1');
    $addonStmt = mysqli_prepare($conn, 'SELECT name, price, is_available FROM addon WHERE addon_id = ? LIMIT 1');

    if (!$sizeStmt || !$addonStmt) {
        $error = 'Unable to load current menu prices.';
        return [];
    }

    foreach ($cart as $cartItem) {
        $sizeId = filter_var($cartItem['size_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($cartItem['qty'] ?? null, FILTER_VALIDATE_INT);
        if (!$sizeId || !$qty || $qty < 1 || $qty > 99) {
            $error = 'Your cart contains an invalid item. Please review it and try again.';
            return [];
        }

        mysqli_stmt_bind_param($sizeStmt, 'i', $sizeId);
        mysqli_stmt_execute($sizeStmt);
        $size = mysqli_fetch_assoc(mysqli_stmt_get_result($sizeStmt));
        if (!$size || (int) $size['size_available'] !== 1 || (int) $size['flavor_available'] !== 1) {
            $error = 'A selected item is no longer available. Please update your cart.';
            return [];
        }

        $addons = [];
        $addonTotal = 0.0;
        $addonIds = $cartItem['addons'] ?? [];
        if (!is_array($addonIds)) {
            $error = 'Your cart contains invalid add-ons. Please update your cart.';
            return [];
        }

        foreach (array_unique($addonIds) as $addonId) {
            $addonId = filter_var($addonId, FILTER_VALIDATE_INT);
            if (!$addonId) {
                $error = 'Your cart contains an invalid add-on. Please update your cart.';
                return [];
            }
            mysqli_stmt_bind_param($addonStmt, 'i', $addonId);
            mysqli_stmt_execute($addonStmt);
            $addon = mysqli_fetch_assoc(mysqli_stmt_get_result($addonStmt));
            if (!$addon || (int) $addon['is_available'] !== 1) {
                $error = 'A selected add-on is no longer available. Please update your cart.';
                return [];
            }
            $addons[] = ['id' => $addonId, 'name' => $addon['name'], 'price' => (float) $addon['price']];
            $addonTotal += (float) $addon['price'];
        }

        $lines[] = [
            'size_id' => $sizeId,
            'name' => $size['name'],
            'qty' => $qty,
            'price' => (float) $size['price'],
            'addons' => $addons,
            'total' => ((float) $size['price'] + $addonTotal) * $qty,
        ];
    }

    mysqli_stmt_close($sizeStmt);
    mysqli_stmt_close($addonStmt);
    return $lines;
}

$base = '/takoyaki-shop-infoman';
$page = 'menu';
$error = null;
$profile = ['full_name' => '', 'contact_number' => '', 'default_address' => ''];
$settings = [];
$cart = $_SESSION['cart'] ?? [];
$orderType = $_SESSION['order_type'] ?? 'delivery';
if (!in_array($orderType, ['delivery', 'pickup', 'dine-in'], true)) {
    $orderType = 'delivery';
    $_SESSION['order_type'] = 'delivery';
}

if (!isset($conn) || !$conn) {
    $error = 'Database connection failed. Please try again later.';
} else {
    $profileStmt = mysqli_prepare($conn, 'SELECT customer_id, full_name, contact_number, default_address FROM customer_profile WHERE user_id = ? LIMIT 1');
    if (!$profileStmt) {
        $error = 'Unable to load your customer profile.';
    } else {
    mysqli_stmt_bind_param($profileStmt, 'i', $_SESSION['user_id']);
    mysqli_stmt_execute($profileStmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($profileStmt)) ?: $profile;
    mysqli_stmt_close($profileStmt);
    }

    $settingsResult = mysqli_query($conn, 'SELECT setting_key, setting_value FROM settings');
    if ($settingsResult) {
        while ($row = mysqli_fetch_assoc($settingsResult)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
    }

    if (!$profile || empty($profile['customer_id'])) {
        $error = 'Your customer profile is missing. Please contact the shop.';
    } elseif (!$cart) {
        $error = 'Your cart is empty. Add an item before checking out.';
    } else {
        $lines = load_checkout_lines($conn, $cart, $error);
        if ($error === null) {
            try {
                $requirements = cart_ingredient_requirements($conn, $cart);
                $error = check_ingredient_stock($conn, $requirements);
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }
    }
}

$subtotal = 0.0;
foreach ($lines ?? [] as $line) {
    $subtotal += $line['total'];
}
$configuredDeliveryFee = (float) ($settings['delivery_fee'] ?? 0);
$deliveryFee = $orderType === 'delivery' ? $configuredDeliveryFee : 0.0;
$isShopOpen = shop_is_open($settings);
if (!$isShopOpen && $error === null) {
    $error = 'The shop is currently closed and cannot accept orders.';
}

if (empty($_SESSION['checkout_csrf'])) {
    $_SESSION['checkout_csrf'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error) {
    $token = $_POST['csrf_token'] ?? '';
    $postedType = $_POST['order_type'] ?? '';
    $recipientInput = $_POST['recipient_name'] ?? '';
    $contactInput = $_POST['delivery_contact'] ?? '';
    $addressInput = $_POST['delivery_address'] ?? '';
    $landmarkInput = $_POST['delivery_landmark'] ?? '';
    $notesInput = $_POST['notes'] ?? '';
    $pickupInput = $_POST['pickup_time'] ?? '';
    $recipientName = is_string($recipientInput) ? trim($recipientInput) : '';
    $deliveryContact = is_string($contactInput) ? trim($contactInput) : '';
    $address = is_string($addressInput) ? trim($addressInput) : '';
    $landmark = is_string($landmarkInput) ? trim($landmarkInput) : '';
    $notes = is_string($notesInput) ? trim($notesInput) : '';
    $pickupInput = is_string($pickupInput) ? trim($pickupInput) : '';
    $pickupDate = $pickupInput !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $pickupInput) : false;
    $pickupTime = $pickupDate ? $pickupDate->format('Y-m-d H:i:s') : null;

    if (!hash_equals($_SESSION['checkout_csrf'], $token)) {
        $error = 'Your session expired. Please refresh the page and try again.';
    } elseif (!is_string($postedType) || !in_array($postedType, ['delivery', 'pickup', 'dine-in'], true)) {
        $error = 'Choose a valid order type.';
    } elseif (!shop_is_open($settings)) {
        $error = 'The shop is currently closed and cannot accept orders.';
    } elseif ($postedType === 'delivery' && $address === '') {
        $error = 'Enter a delivery address to continue.';
    } elseif ($postedType === 'delivery' && ($recipientName === '' || $deliveryContact === '')) {
        $error = 'Enter the recipient name and contact number for delivery.';
    } elseif ($postedType === 'pickup' && (!$pickupDate || $pickupDate->getTimestamp() < time())) {
        $error = 'Choose a valid pickup time in the future.';
    } elseif (strlen($recipientName) > 100 || strlen($deliveryContact) > 20 || strlen($address) > 255 || strlen($landmark) > 255 || strlen($notes) > 255) {
        $error = 'Recipient, contact, address, landmark, or notes exceed the allowed length.';
    } else {
        $orderType = $postedType;
        $deliveryFee = $orderType === 'delivery' ? (float) ($settings['delivery_fee'] ?? 0) : 0.0;
        $addressValue = $orderType === 'delivery' ? $address : null;
        $recipientValue = $orderType === 'delivery' ? $recipientName : null;
        $contactValue = $orderType === 'delivery' ? $deliveryContact : null;
        $landmarkValue = $orderType === 'delivery' ? $landmark : null;
        $total = $subtotal + $deliveryFee;
        $customerId = (int) $profile['customer_id'];
        if ($orderType !== 'pickup') {
            $pickupTime = null;
        }

        mysqli_begin_transaction($conn);
        try {
            $requirements = cart_ingredient_requirements($conn, $cart);
            $stockError = check_ingredient_stock($conn, $requirements, true);
        } catch (RuntimeException $exception) {
            $stockError = $exception->getMessage();
        }
        if ($stockError !== null) {
            mysqli_rollback($conn);
            $error = $stockError;
        }
        $orderStmt = $error === null ? mysqli_prepare($conn, 'INSERT INTO orders
            (customer_id, order_type, recipient_name, delivery_contact, delivery_address, delivery_landmark, delivery_fee, pickup_time, notes, subtotal, total_amount)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)') : false;
        $saved = false;
        if ($orderStmt) {
            mysqli_stmt_bind_param($orderStmt, 'isssssdssdd', $customerId, $orderType, $recipientValue, $contactValue, $addressValue, $landmarkValue, $deliveryFee, $pickupTime, $notes, $subtotal, $total);
            $saved = mysqli_stmt_execute($orderStmt);
            mysqli_stmt_close($orderStmt);
        }

        if ($saved) {
            $orderId = mysqli_insert_id($conn);
            $itemStmt = mysqli_prepare($conn, 'INSERT INTO order_item (order_id, size_id, quantity, item_price) VALUES (?, ?, ?, ?)');
            $addonStmt = mysqli_prepare($conn, 'INSERT INTO order_item_addon (order_item_id, addon_id, addon_price) VALUES (?, ?, ?)');
            if (!$itemStmt || !$addonStmt) {
                $saved = false;
            } else {
                foreach ($lines as $line) {
                    $sizeId = (int) $line['size_id'];
                    $quantity = (int) $line['qty'];
                    $basePrice = (float) $line['price'];
                    mysqli_stmt_bind_param($itemStmt, 'iiid', $orderId, $sizeId, $quantity, $basePrice);
                    if (!mysqli_stmt_execute($itemStmt)) {
                        $saved = false;
                        break;
                    }
                    $orderItemId = mysqli_insert_id($conn);
                    foreach ($line['addons'] as $addon) {
                        $addonId = (int) $addon['id'];
                        $addonPrice = (float) $addon['price'];
                        mysqli_stmt_bind_param($addonStmt, 'iid', $orderItemId, $addonId, $addonPrice);
                        if (!mysqli_stmt_execute($addonStmt)) {
                            $saved = false;
                            break 2;
                        }
                    }
                }
            }
            if ($itemStmt) {
                mysqli_stmt_close($itemStmt);
            }
            if ($addonStmt) {
                mysqli_stmt_close($addonStmt);
            }
        }

        if ($saved) {
            mysqli_commit($conn);
            unset($_SESSION['cart'], $_SESSION['checkout_csrf']);
            $_SESSION['order_type'] = $orderType;
            $_SESSION['message'] = 'Order #' . $orderId . ' placed successfully. The shop will confirm it shortly.';
            $_SESSION['msg_type'] = 'success';
            header('Location: ' . $base . '/orders.php');
            exit();
        }

        mysqli_rollback($conn);
        if ($error === null) {
            $error = 'We could not save your order. Your cart is still available; please try again.';
        }
    }
}

$pageTitle = 'Checkout';
include __DIR__ . '/includes/header.php';
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Review your order</div>
        <h1>Checkout</h1>
    </div>
</div>

<?php include __DIR__ . '/includes/alert.php'; ?>
<?php if ($error) { ?><div class="alert danger"><?= htmlspecialchars($error) ?></div><?php } ?>

<?php if (!empty($lines)) { ?>
    <div class="checkout-layout">
        <section class="panel">
            <h2>Order details</h2>
            <form method="POST" action="<?= $base ?>/checkout.php" id="checkoutForm">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['checkout_csrf']) ?>">
                <div class="field">
                    <label for="order_type">Order type</label>
                    <select id="order_type" name="order_type">
                        <option value="delivery" <?= $orderType === 'delivery' ? 'selected' : '' ?>>Delivery</option>
                        <option value="pickup" <?= $orderType === 'pickup' ? 'selected' : '' ?>>Pickup</option>
                        <option value="dine-in" <?= $orderType === 'dine-in' ? 'selected' : '' ?>>Dine-in</option>
                    </select>
                </div>
                <div class="field" id="deliveryAddressField">
                    <label for="recipient_name">Recipient name</label>
                    <input id="recipient_name" name="recipient_name" maxlength="100" value="<?= htmlspecialchars($_POST['recipient_name'] ?? $profile['full_name'] ?? '') ?>">
                    <label for="delivery_contact">Contact number</label>
                    <input id="delivery_contact" name="delivery_contact" maxlength="20" value="<?= htmlspecialchars($_POST['delivery_contact'] ?? $profile['contact_number'] ?? '') ?>">
                    <label for="delivery_address">Complete delivery address</label>
                    <textarea id="delivery_address" name="delivery_address" maxlength="255" placeholder="Enter your delivery address"><?= htmlspecialchars($_POST['delivery_address'] ?? $profile['default_address'] ?? '') ?></textarea>
                    <label for="delivery_landmark">Landmark (optional)</label>
                    <input id="delivery_landmark" name="delivery_landmark" maxlength="255" value="<?= htmlspecialchars($_POST['delivery_landmark'] ?? '') ?>">
                </div>
                <div class="field" id="pickupTimeField">
                    <label for="pickup_time">Pickup time</label>
                    <input id="pickup_time" name="pickup_time" type="datetime-local" value="<?= htmlspecialchars(is_string($_POST['pickup_time'] ?? null) ? $_POST['pickup_time'] : '') ?>">
                </div>
                <div class="field">
                    <label for="notes">Order notes (optional)</label>
                    <textarea id="notes" name="notes" maxlength="255" placeholder="Allergies or order instructions"><?= htmlspecialchars(is_string($_POST['notes'] ?? null) ? $_POST['notes'] : '') ?></textarea>
                </div>
                <?php if (!$isShopOpen) { ?><p class="notice">The shop is closed. New orders are not being accepted right now.</p><?php } ?>
                <button type="submit" class="btn full" id="placeOrderButton" data-base-total="<?= number_format($subtotal, 2, '.', '') ?>" <?= !$isShopOpen ? 'disabled' : '' ?>>Place order · ₱<?= number_format($subtotal + $deliveryFee, 2) ?></button>
                <p class="tiny muted">Payment is handled when your order is fulfilled.</p>
            </form>
        </section>

        <aside class="panel checkout-summary">
            <h2>Your items</h2>
            <?php foreach ($lines as $line) { ?>
                <div class="cart-item">
                    <div class="cart-item-head"><span><?= (int) $line['qty'] ?> × <?= htmlspecialchars($line['name']) ?></span><strong>₱<?= number_format($line['total'], 2) ?></strong></div>
                    <?php if ($line['addons']) { ?><small><?= htmlspecialchars(implode(', ', array_column($line['addons'], 'name'))) ?></small><?php } ?>
                </div>
            <?php } ?>
            <div class="summary-line"><span>Subtotal</span><strong>₱<?= number_format($subtotal, 2) ?></strong></div>
            <div class="summary-line"><span>Delivery fee</span><strong id="deliveryFeeAmount">₱<?= number_format($deliveryFee, 2) ?></strong></div>
            <div class="summary-line total"><span>Total</span><strong id="checkoutTotal">₱<?= number_format($subtotal + $deliveryFee, 2) ?></strong></div>
            <a class="btn secondary full" href="<?= $base ?>/menu.php#cartPanel">Back to cart</a>
        </aside>
    </div>
<?php } else { ?>
    <section class="panel empty">
        <p>Add an available item to your cart before checking out.</p>
        <a class="btn" href="<?= $base ?>/menu.php">Browse menu</a>
    </section>
<?php } ?>

<script>
    const orderTypeField = document.getElementById('order_type');
    const addressField = document.getElementById('deliveryAddressField');
    const addressInput = document.getElementById('delivery_address');
    const recipientInput = document.getElementById('recipient_name');
    const contactInput = document.getElementById('delivery_contact');
    const landmarkInput = document.getElementById('delivery_landmark');
    const pickupTimeField = document.getElementById('pickupTimeField');
    const pickupTimeInput = document.getElementById('pickup_time');
    const deliveryFeeAmount = document.getElementById('deliveryFeeAmount');
    const checkoutTotal = document.getElementById('checkoutTotal');
    const placeOrderButton = document.getElementById('placeOrderButton');
    if (orderTypeField && addressField && addressInput && pickupTimeField && pickupTimeInput && deliveryFeeAmount && checkoutTotal && placeOrderButton) {
        const baseTotal = Number(placeOrderButton.dataset.baseTotal);
        const deliveryFee = <?= json_encode($configuredDeliveryFee) ?>;
        function syncDeliveryAddress() {
            const needsAddress = orderTypeField.value === 'delivery';
            const needsPickupTime = orderTypeField.value === 'pickup';
            const fee = needsAddress ? deliveryFee : 0;
            addressField.hidden = !needsAddress;
            addressInput.required = needsAddress;
            recipientInput.required = needsAddress;
            contactInput.required = needsAddress;
            landmarkInput.required = false;
            pickupTimeField.hidden = !needsPickupTime;
            pickupTimeInput.required = needsPickupTime;
            deliveryFeeAmount.textContent = '₱' + fee.toFixed(2);
            checkoutTotal.textContent = '₱' + (baseTotal + fee).toFixed(2);
            placeOrderButton.textContent = 'Place order · ₱' + (baseTotal + fee).toFixed(2);
            placeOrderButton.disabled = <?= $isShopOpen ? 'false' : 'true' ?>;
        }
        orderTypeField.addEventListener('change', syncDeliveryAddress);
        syncDeliveryAddress();
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>