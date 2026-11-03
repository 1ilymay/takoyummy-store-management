<?php
/** @var mysqli $conn */
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');

require_admin();

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = "Database connection failed. Please try again later.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$flavorId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// get the flavor
$result = mysqli_query($conn, "SELECT flavor_id, name FROM flavor WHERE flavor_id = $flavorId");
$flavor = mysqli_fetch_assoc($result);

if (!$flavor) {
    $_SESSION['message'] = "Menu item not found.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$oldLabel = "";
$oldPrice = "";
if (isset($_SESSION['old_size'])) {
    $oldLabel = $_SESSION['old_size']['size_label'];
    $oldPrice = $_SESSION['old_size']['price'];
    unset($_SESSION['old_size']);
}

$sizes = mysqli_query($conn, "SELECT * FROM flavor_size WHERE flavor_id = $flavorId ORDER BY price");

$page = "admin";
$adminPage = "menu";
include('../../includes/header.php');
include('../../includes/adminHeader.php');
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Store management</div>
        <h1>Sizes and prices</h1>
        <p>Flavor: <strong><?= htmlspecialchars($flavor['name']) ?></strong></p>
    </div>
    <a class="btn secondary" href="index.php">← Back to menu items</a>
</div>

<?php include('../../includes/alert.php'); ?>

<section class="panel narrow">
    <h2>Add a size</h2>
    <form action="size_store.php" method="POST" novalidate>
        <input type="hidden" name="flavor_id" value="<?= $flavorId ?>">
        <div class="form-grid">
            <div class="field">
                <label for="size_label">Size name</label>
                <input type="text" id="size_label" name="size_label" placeholder="Example: 4 pcs" value="<?= htmlspecialchars($oldLabel) ?>">
            </div>
            <div class="field">
                <label for="price">Price (₱)</label>
                <input type="text" id="price" name="price" placeholder="Example: 70.00" value="<?= htmlspecialchars($oldPrice) ?>">
            </div>
        </div>
        <button type="submit">Add size</button>
    </form>
</section>

<section class="panel" style="margin-top:14px;">
    <h2>Current sizes</h2>

    <?php if (mysqli_num_rows($sizes) == 0) { ?>
        <div class="empty">No sizes yet. This flavor cannot be ordered until you add one.</div>
    <?php } ?>

    <?php while ($s = mysqli_fetch_assoc($sizes)) { ?>
        <form action="size_update.php" method="POST" novalidate style="display:flex; gap:8px; align-items:center; flex-wrap:wrap; margin-bottom:10px;">
            <input type="hidden" name="size_id" value="<?= $s['size_id'] ?>">
            <input type="hidden" name="flavor_id" value="<?= $flavorId ?>">

            <input type="text" name="size_label" value="<?= htmlspecialchars($s['size_label']) ?>" style="width:180px;">
            <input type="text" name="price" value="<?= $s['price'] ?>" style="width:120px;">
            <select name="is_available" style="width:auto;">
                <option value="1" <?php if ($s['is_available'] == 1) echo "selected"; ?>>Available</option>
                <option value="0" <?php if ($s['is_available'] == 0) echo "selected"; ?>>Sold Out</option>
            </select>

            <button type="submit">Save</button>
            <button type="submit" formaction="size_delete.php" class="danger" onclick="return confirm('Delete this size?');">Delete</button>
        </form>
    <?php } ?>
</section>

<?php include('../../includes/footer.php'); ?>