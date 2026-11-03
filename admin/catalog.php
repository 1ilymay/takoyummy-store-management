<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'catalog';
$message = null;
$messageType = 'danger';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    $valid = verify_csrf_token($_POST['csrf_token'] ?? null);
    if (!$valid || !$conn) {
        $message = 'Your session expired or the database is unavailable.';
    } else {
        $stmt = false;
        if ($action === 'save_size') {
            $sizeId = filter_var($_POST['size_id'] ?? null, FILTER_VALIDATE_INT);
            $flavorId = filter_var($_POST['flavor_id'] ?? null, FILTER_VALIDATE_INT);
            $labelInput = $_POST['size_label'] ?? '';
            $label = is_string($labelInput) ? trim($labelInput) : '';
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $available = ($_POST['is_available'] ?? '') === '1' ? 1 : 0;
            if (!$flavorId || $label === '' || strlen($label) > 30 || $price === false || $price < 0 || $price > 99999999) {
                $message = 'Enter a flavor, size label, and valid non-negative price.';
            } elseif ($sizeId) {
                $stmt = mysqli_prepare($conn, 'UPDATE flavor_size SET flavor_id = ?, size_label = ?, price = ?, is_available = ? WHERE size_id = ?');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'isdii', $flavorId, $label, $price, $available, $sizeId);
                }
            } else {
                $stmt = mysqli_prepare($conn, 'INSERT INTO flavor_size (flavor_id, size_label, price, is_available) VALUES (?, ?, ?, ?)');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'isdi', $flavorId, $label, $price, $available);
                }
            }
        } elseif ($action === 'save_addon') {
            $addonId = filter_var($_POST['addon_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
            $nameInput = $_POST['name'] ?? '';
            $name = is_string($nameInput) ? trim($nameInput) : '';
            $price = filter_var($_POST['price'] ?? null, FILTER_VALIDATE_FLOAT);
            $available = ($_POST['is_available'] ?? '') === '1' ? 1 : 0;
            if ($name === '' || strlen($name) > 100 || $price === false || $price < 0 || $price > 99999999) {
                $message = 'Enter an add-on name and a valid non-negative price.';
            } elseif ($addonId) {
                $stmt = mysqli_prepare($conn, 'UPDATE addon SET name = ?, price = ?, is_available = ? WHERE addon_id = ?');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sdii', $name, $price, $available, $addonId);
                }
            } else {
                $stmt = mysqli_prepare($conn, 'INSERT INTO addon (name, price, is_available) VALUES (?, ?, ?)');
                if ($stmt) {
                    mysqli_stmt_bind_param($stmt, 'sdi', $name, $price, $available);
                }
            }
        } elseif ($action === 'remove_size') {
            $sizeId = filter_var($_POST['size_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $sizeId ? mysqli_prepare($conn, 'UPDATE flavor_size SET is_available = 0 WHERE size_id = ?') : false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $sizeId);
            } else {
                $message = 'Invalid size.';
            }
        } elseif ($action === 'remove_addon') {
            $addonId = filter_var($_POST['addon_id'] ?? null, FILTER_VALIDATE_INT);
            $stmt = $addonId ? mysqli_prepare($conn, 'UPDATE addon SET is_available = 0 WHERE addon_id = ?') : false;
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'i', $addonId);
            } else {
                $message = 'Invalid add-on.';
            }
        } else {
            $message = 'Invalid catalog action.';
        }

        if ($stmt) {
            if (mysqli_stmt_execute($stmt)) {
                $message = 'Catalog item saved.';
                $messageType = 'success';
            } else {
                $message = 'Unable to save this catalog item.';
                error_log('Catalog save failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } elseif ($message === null) {
            $message = 'Unable to prepare the catalog update.';
            error_log('Catalog statement preparation failed: ' . mysqli_error($conn));
        }
    }

    $_SESSION['message'] = $message;
    $_SESSION['msg_type'] = $messageType;
    header('Location: ' . $base . '/admin/catalog.php');
    exit();
}

$flavors = [];
$sizes = [];
$addons = [];
$flavorResult = mysqli_query($conn, 'SELECT flavor_id, name FROM flavor ORDER BY name');
while ($flavorResult && $row = mysqli_fetch_assoc($flavorResult)) {
    $flavors[] = $row;
}
$sizeResult = mysqli_query($conn, 'SELECT fs.size_id, fs.flavor_id, fs.size_label, fs.price, fs.is_available, f.name AS flavor_name
    FROM flavor_size fs INNER JOIN flavor f ON f.flavor_id = fs.flavor_id ORDER BY f.name, fs.price');
while ($sizeResult && $row = mysqli_fetch_assoc($sizeResult)) {
    $sizes[] = $row;
}
$addonResult = mysqli_query($conn, 'SELECT addon_id, name, price, is_available FROM addon ORDER BY name');
while ($addonResult && $row = mysqli_fetch_assoc($addonResult)) {
    $addons[] = $row;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title"><div><div class="eyebrow">Store management</div><h1>Sizes, prices &amp; add-ons</h1></div></div>
<?php include __DIR__ . '/../includes/alert.php'; ?>

<section class="panel">
    <h2>Add flavor size</h2>
    <form method="POST" class="form-grid" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_size">
        <div class="field"><label for="flavor_id">Flavor</label><select id="flavor_id" name="flavor_id"><?php foreach ($flavors as $flavor) { ?><option value="<?= (int) $flavor['flavor_id'] ?>"><?= htmlspecialchars($flavor['name']) ?></option><?php } ?></select></div>
        <div class="field"><label for="size_label">Size label</label><input id="size_label" name="size_label" maxlength="30" placeholder="4 pcs"></div>
        <div class="field"><label for="size_price">Price</label><input id="size_price" name="price" type="number" min="0" step="0.01"></div>
        <div class="field"><label>Availability</label><select name="is_available"><option value="1">Available</option><option value="0">Sold out</option></select></div>
        <div class="field"><button type="submit">Add size</button></div>
    </form>
</section>

<section class="panel">
    <h2>Flavor sizes and prices</h2>
    <?php foreach ($sizes as $size) { ?><form id="size-form-<?= (int) $size['size_id'] ?>" method="POST" novalidate></form><?php } ?>
    <div class="table-wrap"><table><thead><tr><th>Flavor</th><th>Size</th><th>Price</th><th>Availability</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($sizes as $size) { ?>
        <tr>
            <td><?= htmlspecialchars($size['flavor_name']) ?></td>
            <td><input form="size-form-<?= (int) $size['size_id'] ?>" type="text" name="size_label" maxlength="30" value="<?= htmlspecialchars($size['size_label']) ?>"></td>
            <td><input form="size-form-<?= (int) $size['size_id'] ?>" type="number" name="price" min="0" step="0.01" value="<?= htmlspecialchars($size['price']) ?>"></td>
            <td><select form="size-form-<?= (int) $size['size_id'] ?>" name="is_available"><option value="1" <?= (int) $size['is_available'] === 1 ? 'selected' : '' ?>>Available</option><option value="0" <?= (int) $size['is_available'] === 0 ? 'selected' : '' ?>>Sold out</option></select></td>
            <td class="actions">
                <input form="size-form-<?= (int) $size['size_id'] ?>" type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input form="size-form-<?= (int) $size['size_id'] ?>" type="hidden" name="action" value="save_size">
                <input form="size-form-<?= (int) $size['size_id'] ?>" type="hidden" name="size_id" value="<?= (int) $size['size_id'] ?>"><input form="size-form-<?= (int) $size['size_id'] ?>" type="hidden" name="flavor_id" value="<?= (int) $size['flavor_id'] ?>">
                <button form="size-form-<?= (int) $size['size_id'] ?>" type="submit" class="small">Save</button>
                <?php if ((int) $size['is_available'] === 1) { ?><button form="size-form-<?= (int) $size['size_id'] ?>" type="submit" name="action" value="remove_size" class="small danger">Remove</button><?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody></table></div>
</section>

<section class="panel">
    <h2>Add add-on</h2>
    <form method="POST" class="form-grid" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="save_addon">
        <div class="field"><label for="addon_name">Name</label><input id="addon_name" name="name" maxlength="100"></div>
        <div class="field"><label for="addon_price">Price</label><input id="addon_price" name="price" type="number" min="0" step="0.01"></div>
        <div class="field"><label>Availability</label><select name="is_available"><option value="1">Available</option><option value="0">Sold out</option></select></div>
        <div class="field"><button type="submit">Add add-on</button></div>
    </form>
</section>

<section class="panel">
    <h2>Add-ons</h2>
    <?php foreach ($addons as $addon) { ?><form id="addon-form-<?= (int) $addon['addon_id'] ?>" method="POST" novalidate></form><?php } ?>
    <div class="table-wrap"><table><thead><tr><th>Name</th><th>Price</th><th>Availability</th><th>Actions</th></tr></thead><tbody>
    <?php foreach ($addons as $addon) { ?>
        <tr>
            <td><input form="addon-form-<?= (int) $addon['addon_id'] ?>" type="text" name="name" maxlength="100" value="<?= htmlspecialchars($addon['name']) ?>"></td>
            <td><input form="addon-form-<?= (int) $addon['addon_id'] ?>" type="number" name="price" min="0" step="0.01" value="<?= htmlspecialchars($addon['price']) ?>"></td>
            <td><select form="addon-form-<?= (int) $addon['addon_id'] ?>" name="is_available"><option value="1" <?= (int) $addon['is_available'] === 1 ? 'selected' : '' ?>>Available</option><option value="0" <?= (int) $addon['is_available'] === 0 ? 'selected' : '' ?>>Sold out</option></select></td>
            <td class="actions"><input form="addon-form-<?= (int) $addon['addon_id'] ?>" type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input form="addon-form-<?= (int) $addon['addon_id'] ?>" type="hidden" name="addon_id" value="<?= (int) $addon['addon_id'] ?>">
                <button form="addon-form-<?= (int) $addon['addon_id'] ?>" type="submit" name="action" value="save_addon" class="small">Save</button>
                <?php if ((int) $addon['is_available'] === 1) { ?><button form="addon-form-<?= (int) $addon['addon_id'] ?>" type="submit" name="action" value="remove_addon" class="small danger">Remove</button><?php } ?>
            </td>
        </tr>
    <?php } ?>
    </tbody></table></div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
