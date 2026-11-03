<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_admin();

$base = '/takoyaki-shop-infoman';
$page = 'admin';
$adminPage = 'inventory';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
    if (!verify_csrf_token($_POST['csrf_token'] ?? null) || !$conn) {
        $error = 'Your session expired or the database is unavailable.';
    } elseif ($action === 'save_ingredient') {
        $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT) ?: 0;
        $nameInput = $_POST['name'] ?? '';
        $unitInput = $_POST['unit'] ?? '';
        $name = is_string($nameInput) ? trim($nameInput) : '';
        $unit = is_string($unitInput) ? trim($unitInput) : '';
        $stock = filter_var($_POST['stock_qty'] ?? null, FILTER_VALIDATE_FLOAT);
        $threshold = filter_var($_POST['low_stock_threshold'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($name === '' || strlen($name) > 100 || $unit === '' || strlen($unit) > 20 || $stock === false || $stock < 0 || $threshold === false || $threshold < 0) {
            $error = 'Enter a name, unit, and valid non-negative stock and low-stock values.';
        } else {
            $stmt = $ingredientId
                ? mysqli_prepare($conn, 'UPDATE ingredient SET name = ?, unit = ?, stock_qty = ?, low_stock_threshold = ? WHERE ingredient_id = ?')
                : mysqli_prepare($conn, 'INSERT INTO ingredient (name, unit, stock_qty, low_stock_threshold) VALUES (?, ?, ?, ?)');
            if ($stmt) {
                if ($ingredientId) {
                    mysqli_stmt_bind_param($stmt, 'ssddi', $name, $unit, $stock, $threshold, $ingredientId);
                } else {
                    mysqli_stmt_bind_param($stmt, 'ssdd', $name, $unit, $stock, $threshold);
                }
                if (!mysqli_stmt_execute($stmt)) {
                    error_log('Ingredient save failed: ' . mysqli_stmt_error($stmt));
                    $error = 'Unable to save the ingredient.';
                }
                mysqli_stmt_close($stmt);
            } else {
                error_log('Ingredient save preparation failed: ' . mysqli_error($conn));
                $error = 'Unable to save the ingredient.';
            }
        }
    } elseif (in_array($action, ['save_size_usage', 'save_addon_usage'], true)) {
        $isSize = $action === 'save_size_usage';
        $idName = $isSize ? 'size_id' : 'addon_id';
        $itemId = filter_var($_POST[$idName] ?? null, FILTER_VALIDATE_INT);
        $ingredientId = filter_var($_POST['ingredient_id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($_POST['qty_used'] ?? null, FILTER_VALIDATE_FLOAT);
        if (!$itemId || !$ingredientId || $quantity === false || $quantity <= 0) {
            $error = 'Select an item and ingredient and enter a positive usage quantity.';
        } else {
            $table = $isSize ? 'size_ingredient_usage' : 'addon_ingredient_usage';
            $column = $isSize ? 'size_id' : 'addon_id';
            $lookup = mysqli_prepare($conn, "SELECT usage_id FROM {$table} WHERE {$column} = ? AND ingredient_id = ? LIMIT 1");
            if ($lookup) {
                mysqli_stmt_bind_param($lookup, 'ii', $itemId, $ingredientId);
                mysqli_stmt_execute($lookup);
                $mapping = mysqli_fetch_assoc(mysqli_stmt_get_result($lookup));
                mysqli_stmt_close($lookup);
                $stmt = $mapping
                    ? mysqli_prepare($conn, "UPDATE {$table} SET qty_used = ? WHERE usage_id = ?")
                    : mysqli_prepare($conn, "INSERT INTO {$table} ({$column}, ingredient_id, qty_used) VALUES (?, ?, ?)");
                if ($stmt) {
                    if ($mapping) {
                        $mappingId = (int) $mapping['usage_id'];
                        mysqli_stmt_bind_param($stmt, 'di', $quantity, $mappingId);
                    } else {
                        mysqli_stmt_bind_param($stmt, 'iid', $itemId, $ingredientId, $quantity);
                    }
                    if (!mysqli_stmt_execute($stmt)) {
                        error_log('Ingredient usage save failed: ' . mysqli_stmt_error($stmt));
                        $error = 'Unable to save ingredient usage.';
                    }
                    mysqli_stmt_close($stmt);
                } else {
                    $error = 'Unable to prepare ingredient usage update.';
                    error_log('Ingredient usage preparation failed: ' . mysqli_error($conn));
                }
            } else {
                $error = 'Unable to check ingredient usage.';
                error_log('Ingredient usage lookup preparation failed: ' . mysqli_error($conn));
            }
        }
    } elseif (in_array($action, ['delete_size_usage', 'delete_addon_usage'], true)) {
        $usageId = filter_var($_POST['usage_id'] ?? null, FILTER_VALIDATE_INT);
        $table = $action === 'delete_size_usage' ? 'size_ingredient_usage' : 'addon_ingredient_usage';
        $stmt = $usageId ? mysqli_prepare($conn, "DELETE FROM {$table} WHERE usage_id = ?") : false;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $usageId);
            if (!mysqli_stmt_execute($stmt)) {
                $error = 'Unable to remove ingredient usage.';
                error_log('Ingredient usage delete failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);
        } else {
            $error = 'Invalid ingredient usage mapping.';
        }
    } else {
        $error = 'Invalid inventory action.';
    }

    $_SESSION['message'] = $error ?? 'Inventory data saved.';
    $_SESSION['msg_type'] = $error === null ? 'success' : 'danger';
    header('Location: ' . $base . '/admin/inventory.php');
    exit();
}

$ingredients = [];
$sizes = [];
$addons = [];
$sizeUsage = [];
$addonUsage = [];
$result = mysqli_query($conn, 'SELECT * FROM ingredient GROUP BY name, unit ORDER BY name');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $ingredients[] = $row;
}
$result = mysqli_query($conn, 'SELECT fs.size_id, fs.size_label, f.name AS flavor_name FROM flavor_size fs JOIN flavor f ON f.flavor_id = fs.flavor_id ORDER BY f.name, fs.size_label');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $sizes[] = $row;
}
$result = mysqli_query($conn, 'SELECT addon_id, name FROM addon ORDER BY name');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $addons[] = $row;
}
$result = mysqli_query($conn, 'SELECT u.usage_id, f.name AS flavor_name, fs.size_label, i.name AS ingredient_name, i.unit, u.qty_used
    FROM size_ingredient_usage u JOIN flavor_size fs ON fs.size_id = u.size_id
    JOIN flavor f ON f.flavor_id = fs.flavor_id JOIN ingredient i ON i.ingredient_id = u.ingredient_id
    ORDER BY f.name, fs.size_label, i.name');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $sizeUsage[] = $row;
}
$result = mysqli_query($conn, 'SELECT u.usage_id, a.name AS addon_name, i.name AS ingredient_name, i.unit, u.qty_used
    FROM addon_ingredient_usage u JOIN addon a ON a.addon_id = u.addon_id
    JOIN ingredient i ON i.ingredient_id = u.ingredient_id ORDER BY a.name, i.name');
while ($result && $row = mysqli_fetch_assoc($result)) {
    $addonUsage[] = $row;
}

include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/adminHeader.php';
?>

<div class="page-title"><div><div class="eyebrow">Store management</div><h1>Ingredient inventory</h1><p>Update stock levels and define ingredient consumption per size or add-on.</p></div></div>
<?php include __DIR__ . '/../includes/alert.php'; ?>

<section class="panel">
    <h2>Ingredient stock and low-stock alerts</h2>
    <?php foreach ($ingredients as $ingredient) { ?><form id="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" method="POST" novalidate></form><?php } ?>
    <form id="ingredient-form-new" method="POST" novalidate></form>
    <div class="table-wrap"><table><thead><tr><th>Ingredient</th><th>Unit</th><th>Stock</th><th>Low-stock alert at</th><th>Save</th></tr></thead><tbody>
    <?php foreach ($ingredients as $ingredient) { ?>
        <tr>
            <td><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" name="name" maxlength="100" value="<?= htmlspecialchars($ingredient['name']) ?>"></td>
            <td><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" name="unit" maxlength="20" value="<?= htmlspecialchars($ingredient['unit']) ?>"></td>
            <td><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" type="number" name="stock_qty" min="0" step="0.01" value="<?= htmlspecialchars($ingredient['stock_qty']) ?>"></td>
            <td><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" type="number" name="low_stock_threshold" min="0" step="0.01" value="<?= htmlspecialchars($ingredient['low_stock_threshold']) ?>"></td>
            <td><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" type="hidden" name="action" value="save_ingredient"><input form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" type="hidden" name="ingredient_id" value="<?= (int) $ingredient['ingredient_id'] ?>"><button form="ingredient-form-<?= (int) $ingredient['ingredient_id'] ?>" class="small" type="submit">Save</button>
                <?php if ((float) $ingredient['stock_qty'] <= (float) $ingredient['low_stock_threshold']) { ?><strong class="status SoldOut">LOW STOCK</strong><?php } ?>
            </td>
        </tr>
    <?php } ?>
    <tr>
        <td><input form="ingredient-form-new" name="name" maxlength="100" placeholder="New ingredient"></td><td><input form="ingredient-form-new" name="unit" maxlength="20" placeholder="g, ml, pcs"></td>
        <td><input form="ingredient-form-new" type="number" name="stock_qty" min="0" step="0.01" value="0"></td><td><input form="ingredient-form-new" type="number" name="low_stock_threshold" min="0" step="0.01" value="0"></td>
        <td><input form="ingredient-form-new" type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input form="ingredient-form-new" type="hidden" name="action" value="save_ingredient"><button form="ingredient-form-new" class="small" type="submit">Add ingredient</button></td>
    </tr>
    </tbody></table></div>
</section>

<section class="panel">
    <h2>Ingredient usage per size</h2>
    <form method="POST" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="save_size_usage">
        <div class="field"><label>Flavor size</label><select name="size_id"><?php foreach ($sizes as $size) { ?><option value="<?= (int) $size['size_id'] ?>"><?= htmlspecialchars($size['flavor_name'] . ' · ' . $size['size_label']) ?></option><?php } ?></select></div>
        <div class="field"><label>Ingredient</label><select name="ingredient_id"><?php foreach ($ingredients as $ingredient) { ?><option value="<?= (int) $ingredient['ingredient_id'] ?>"><?= htmlspecialchars($ingredient['name'] . ' (' . $ingredient['unit'] . ')') ?></option><?php } ?></select></div>
        <div class="field"><label>Quantity used per size</label><input type="number" name="qty_used" min="0.01" step="0.01"></div>
        <div class="field"><button type="submit">Save size usage</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Flavor size</th><th>Ingredient</th><th>Quantity</th><th></th></tr></thead><tbody>
    <?php foreach ($sizeUsage as $usage) { ?><tr><td><?= htmlspecialchars($usage['flavor_name'] . ' · ' . $usage['size_label']) ?></td><td><?= htmlspecialchars($usage['ingredient_name']) ?></td><td><?= number_format((float) $usage['qty_used'], 2) . ' ' . htmlspecialchars($usage['unit']) ?></td><td><form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_size_usage"><input type="hidden" name="usage_id" value="<?= (int) $usage['usage_id'] ?>"><button class="small danger" type="submit">Remove</button></form></td></tr><?php } ?>
    </tbody></table></div>
</section>

<section class="panel">
    <h2>Ingredient usage per add-on</h2>
    <form method="POST" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="save_addon_usage">
        <div class="field"><label>Add-on</label><select name="addon_id"><?php foreach ($addons as $addon) { ?><option value="<?= (int) $addon['addon_id'] ?>"><?= htmlspecialchars($addon['name']) ?></option><?php } ?></select></div>
        <div class="field"><label>Ingredient</label><select name="ingredient_id"><?php foreach ($ingredients as $ingredient) { ?><option value="<?= (int) $ingredient['ingredient_id'] ?>"><?= htmlspecialchars($ingredient['name'] . ' (' . $ingredient['unit'] . ')') ?></option><?php } ?></select></div>
        <div class="field"><label>Quantity per add-on</label><input type="number" name="qty_used" min="0.01" step="0.01"></div>
        <div class="field"><button type="submit">Save add-on usage</button></div>
    </form>
    <div class="table-wrap"><table><thead><tr><th>Add-on</th><th>Ingredient</th><th>Quantity</th><th></th></tr></thead><tbody>
    <?php foreach ($addonUsage as $usage) { ?><tr><td><?= htmlspecialchars($usage['addon_name']) ?></td><td><?= htmlspecialchars($usage['ingredient_name']) ?></td><td><?= number_format((float) $usage['qty_used'], 2) . ' ' . htmlspecialchars($usage['unit']) ?></td><td><form method="POST"><input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>"><input type="hidden" name="action" value="delete_addon_usage"><input type="hidden" name="usage_id" value="<?= (int) $usage['usage_id'] ?>"><button class="small danger" type="submit">Remove</button></form></td></tr><?php } ?>
    </tbody></table></div>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
