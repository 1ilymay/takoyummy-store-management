<?php
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');

require_admin();

$page = "admin";
$adminPage = "menu";
include('../../includes/header.php');
include('../../includes/adminHeader.php');


$name = "";
$description = "";
$available = 1;
$oldPrices = [];
if (isset($_SESSION['old'])) {
    $name = is_string($_SESSION['old']['name'] ?? null) ? $_SESSION['old']['name'] : '';
    $description = is_string($_SESSION['old']['description'] ?? null) ? $_SESSION['old']['description'] : '';
    $available = is_string($_SESSION['old']['is_available'] ?? null) ? $_SESSION['old']['is_available'] : 1;
    $oldPrices = $_SESSION['old']['prices'] ?? [];
    unset($_SESSION['old']);
}
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Store management</div>
        <h1>Add a menu item</h1>
    </div>
</div>

<?php include('../../includes/alert.php'); ?>

<section class="panel narrow">
    <form action="store.php" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="field">
            <label for="name">Item name</label>
            <input type="text" id="name" name="name" maxlength="100" value="<?= htmlspecialchars($name) ?>">
        </div>
        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="255"><?= htmlspecialchars($description) ?></textarea>
        </div>
        <div class="form-grid">
            <div class="field">
                <label for="image">Main photo (JPG, PNG, WEBP; max 5 MB)</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                <label for="photos">Additional photos (up to 5)</label>
                <input type="file" id="photos" name="photos[]" accept=".jpg,.jpeg,.png,.webp" multiple>
            </div>
            <div class="field">
                <label for="is_available">Availability</label>
                <select id="is_available" name="is_available">
                    <option value="1" <?php if ($available == 1) echo "selected"; ?>>Available</option>
                    <option value="0" <?php if ($available == 0) echo "selected"; ?>>Sold Out</option>
                </select>
            </div>
        </div>
        
        <h3>Sizes &amp; Prices</h3>
        <p class="muted">Enter the price for each size.</p>

        <div class="form-grid">
            <?php
            $sizes = ['4 pcs', '8 pcs', '12 pcs'];

            foreach ($sizes as $size) {
            ?>
                <div class="field">
                    <label><?= $size ?> — Price (₱)</label>
                    <input type="text"
                        name="prices[<?= $size ?>]"
                        placeholder="Enter price"
                        value="<?= htmlspecialchars($oldPrices[$size] ?? '') ?>"
                </div>
            <?php } ?>
        </div>

        <button type="submit">Add menu item</button>
        <a class="btn secondary" href="index.php">Cancel</a>
    </form>
</section>

<?php include('../../includes/footer.php'); ?>