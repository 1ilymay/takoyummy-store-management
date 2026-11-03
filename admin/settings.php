<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'settings';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feeInput = $_POST['delivery_fee'] ?? '';
    $openingInput = $_POST['opening_time'] ?? '';
    $closingInput = $_POST['closing_time'] ?? '';
    $fee = filter_var($feeInput, FILTER_VALIDATE_FLOAT);
    $opening = is_string($openingInput) ? $openingInput : '';
    $closing = is_string($closingInput) ? $closingInput : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try that action again.';
    } elseif ($fee === false || $fee < 0
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $opening)
        || !preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $closing)) {
        $error = 'Enter a non-negative delivery fee and valid opening and closing times.';
    } else {
        $values = [
            'delivery_fee' => number_format($fee, 2, '.', ''),
            'opening_time' => $opening . ':00',
            'closing_time' => $closing . ':00',
        ];
        mysqli_begin_transaction($conn);
        $stmt = mysqli_prepare($conn, 'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $saved = (bool) $stmt;
        if ($stmt) {
            foreach ($values as $key => $value) {
                mysqli_stmt_bind_param($stmt, 'ss', $key, $value);
                if (!mysqli_stmt_execute($stmt)) {
                    $saved = false;
                    error_log('Store setting update failed: ' . mysqli_stmt_error($stmt));
                    break;
                }
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log('Store setting update preparation failed: ' . mysqli_error($conn));
        }
        if ($saved) {
            mysqli_commit($conn);
            $_SESSION['message'] = 'Store settings saved.';
            $_SESSION['msg_type'] = 'success';
            header('Location: ' . $base . '/admin/settings.php');
            exit();
        }
        mysqli_rollback($conn);
        $error = 'Unable to save the store settings.';
    }
}

$settings = ['delivery_fee' => '50.00', 'opening_time' => '10:00:00', 'closing_time' => '20:00:00'];
$result = mysqli_query($conn, 'SELECT setting_key, setting_value FROM settings');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title"><div><div class="eyebrow">Store management</div><h1>Store settings</h1><p>Configure delivery pricing and order cut-off times.</p></div></div>
<?php include __DIR__ . '/../includes/alert.php'; ?>
<?php if ($error) { ?><div class="alert danger"><?= htmlspecialchars($error) ?></div><?php } ?>
<section class="panel narrow">
    <form method="POST" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="field"><label for="delivery_fee">Flat delivery fee</label><input id="delivery_fee" name="delivery_fee" type="number" min="0" step="0.01" value="<?= htmlspecialchars($settings['delivery_fee']) ?>"></div>
        <div class="field"><label for="opening_time">Opening time</label><input id="opening_time" name="opening_time" type="time" value="<?= htmlspecialchars(substr($settings['opening_time'], 0, 5)) ?>"></div>
        <div class="field"><label for="closing_time">Closing time</label><input id="closing_time" name="closing_time" type="time" value="<?= htmlspecialchars(substr($settings['closing_time'], 0, 5)) ?>"></div>
        <button type="submit">Save settings</button>
    </form>
</section>
<?php include __DIR__ . '/../includes/footer.php'; ?>
