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

$id = (int)$_GET['id'];
$result = mysqli_query($conn, "SELECT * FROM flavor WHERE flavor_id = $id");
$flavor = mysqli_fetch_assoc($result);
$gallery = [];
$galleryStmt = mysqli_prepare($conn, 'SELECT photo_id, photo_path FROM flavor_photo WHERE flavor_id = ? ORDER BY photo_id');
if ($galleryStmt) {
    mysqli_stmt_bind_param($galleryStmt, 'i', $id);
    mysqli_stmt_execute($galleryStmt);
    $galleryResult = mysqli_stmt_get_result($galleryStmt);
    while ($photo = mysqli_fetch_assoc($galleryResult)) {
        $gallery[] = $photo;
    }
    mysqli_stmt_close($galleryStmt);
}


if (!$flavor) {
    $_SESSION['message'] = "Menu item not found.";
    $_SESSION['msg_type'] = "danger";
    header("Location: index.php");
    exit();
}

$page = "admin";
$adminPage = "menu";
include('../../includes/header.php');
include('../../includes/adminHeader.php');
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Store management</div>
        <h1>Edit menu item</h1>
    </div>
</div>

<?php include('../../includes/alert.php'); ?>

<section class="panel narrow">
    <form action="update.php" method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="id" value="<?= $id ?>">
        <div class="field">
            <label for="name">Item name</label>
            <input type="text" id="name" name="name" maxlength="100" value="<?= htmlspecialchars($flavor['name']) ?>">
        </div>
        <div class="field">
            <label for="description">Description</label>
            <textarea id="description" name="description" maxlength="255"><?= htmlspecialchars($flavor['description']) ?></textarea>
        </div>
        <div class="field">
            <label>Current photo</label>
            <span class="thumb">
                <?php if ($flavor['image']) { ?>
                    <img src="<?= $base . '/' . $flavor['image'] ?>" alt="">
                <?php } else { ?>
                <img src="<?= $base ?>/images/takoyaki_logo.jpg" alt="">
                <?php } ?>
            </span>
        </div>
        <?php if ($gallery) { ?>
            <div class="field">
                <label>Additional photos (select any to remove)</label>
                <div class="gallery">
                    <?php foreach ($gallery as $photo) { ?>
                        <label class="gallery-photo">
                            <img src="<?= htmlspecialchars($base . '/' . $photo['photo_path']) ?>" alt="Additional product photo">
                            <input type="checkbox" name="delete_photo[]" value="<?= (int) $photo['photo_id'] ?>"> Remove
                        </label>
                    <?php } ?>
                </div>
            </div>
        <?php } ?>
        <div class="form-grid">
            <div class="field">
                <label for="image">Replace main photo (optional; JPG, PNG, WEBP; max 5 MB)</label>
                <input type="file" id="image" name="image" accept=".jpg,.jpeg,.png,.webp">
                <label for="photos">Add photos (up to 5)</label>
                <input type="file" id="photos" name="photos[]" accept=".jpg,.jpeg,.png,.webp" multiple>
            </div>
            <div class="field">
                <label for="is_available">Availability</label>
                <select id="is_available" name="is_available">
                    <option value="1" <?php if ($flavor['is_available'] == 1) echo "selected"; ?>>Available</option>
                    <option value="0" <?php if ($flavor['is_available'] == 0) echo "selected"; ?>>Sold Out</option>
                </select>
            </div>
        </div>
        <button type="submit">Save changes</button>
        <a class="btn secondary" href="index.php">Cancel</a>
    </form>
</section>

<?php include('../../includes/footer.php'); ?>