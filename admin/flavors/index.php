<?php
/** @var mysqli $conn */
session_start();
include('../../includes/config.php');
include('../../includes/auth.php');

require_admin();

if (!isset($conn) || !$conn) {
    $_SESSION['message'] = "Database connection failed. Please try again later.";
    $_SESSION['msg_type'] = "danger";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' &&
    in_array($_POST['action'] ?? '', ['save_addon', 'delete_addon'], true)) {
    if (verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $action = $_POST['action'];
        $id = (int)($_POST['addon_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $price = (float)($_POST['price'] ?? 0);
        $available = (int)($_POST['is_available'] ?? 0);

        if ($action === 'save_addon' && $name !== '' && $price >= 0) {
            if ($id > 0) {
                $stmt = mysqli_prepare($conn, "UPDATE addon SET name=?, price=?, is_available=? WHERE addon_id=?");
                mysqli_stmt_bind_param($stmt, "sdii", $name, $price, $available, $id);
            } else {
                $stmt = mysqli_prepare($conn, "INSERT INTO addon (name, price, is_available) VALUES (?, ?, ?)");
                mysqli_stmt_bind_param($stmt, "sdi", $name, $price, $available);
            }
                    
                        
            } elseif ($action === 'delete_addon' && $id > 0) {
                $stmt = mysqli_prepare($conn, "DELETE FROM addon WHERE addon_id=?");
                mysqli_stmt_bind_param($stmt, "i", $id);
            }


        if (isset($stmt) && $stmt) {
            
if (mysqli_stmt_execute($stmt)) {
    $_SESSION['message'] = "Add-on saved or deleted successfully.";
    $_SESSION['msg_type'] = "success";
} else {
    $_SESSION['message'] = "Cannot delete this add-on. It may be used in existing orders.";
    $_SESSION['msg_type'] = "danger";
}

mysqli_stmt_close($stmt);

        }
    }

    header("Location: index.php#addons");
    exit();
}

$page = "admin";
$adminPage = "menu";
include('../../includes/header.php');
include('../../includes/adminHeader.php');

$result = false;
if (isset($conn) && $conn) {
    $sql = "SELECT * FROM flavor ORDER BY name";
    $result = mysqli_query($conn, $sql);
}
?>

<div class="page-title">
    <div>
        <div class="eyebrow">Store management</div>
        <h1>Menu items</h1>
        <p>Add, edit, and set availability of your takoyaki flavors.</p>
    </div>
    <a class="btn" href="create.php">+ Add menu item</a>
</div>

<?php include('../../includes/alert.php'); ?>

<section class="panel">
    <h2>Menu catalog</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Item</th>
                    <th>Sizes / prices</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if ($result) {
                    while ($row = mysqli_fetch_assoc($result)) {
                        $id = $row['flavor_id'];

                        
                        $sizes = mysqli_query($conn, "SELECT size_label, price FROM flavor_size WHERE flavor_id = $id ORDER BY price");
                    ?>
                        <tr>
                            <td>
                                <div class="item-cell">
                                    <span class="thumb">
                                        <?php if ($row['image']) { ?>
                                            <img src="<?= $base . '/' . $row['image'] ?>" alt="">
                                        <?php } else { ?>
                                        <img src="<?= $base ?>/images/takoyaki_logo.jpg" alt="">
                                        <?php } ?>
                                    </span>
                                    <div>
                                        <strong><?= htmlspecialchars($row['name']) ?></strong>
                                        <div class="muted"><?= htmlspecialchars($row['description']) ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php
                                if ($sizes && mysqli_num_rows($sizes) == 0) {
                                    echo "<span class='muted'>No sizes yet</span>";
                                }
                                while ($s = mysqli_fetch_assoc($sizes)) {
                                    echo $s['size_label'] . ": ₱" . number_format($s['price'], 2) . "<br>";
                                }
                                ?>
                            </td>
                            <td>
                                <?php if ($row['is_available'] == 1) { ?>
                                    <span class="status Available">Available</span>
                                <?php } else { ?>
                                    <span class="status SoldOut">Sold Out</span>
                                <?php } ?>
                            </td>
                            <td>
                                <div class="actions">
                                    <a class="btn small secondary" href="edit.php?id=<?= $id ?>">Edit</a>
                                    <a class="btn small secondary" href="sizes.php?id=<?= $id ?>">Sizes &amp; Prices</a>

                                    <form action="toggle.php" method="POST" class="inline">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button type="submit" class="small ghost">
                                            <?php if ($row['is_available'] == 1) { echo "Mark sold out"; } else { echo "Make available"; } ?>
                                        </button>
                                    </form>

                                    <form action="delete.php" method="POST" class="inline" onsubmit="return confirm('Delete this menu item?');">
                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                                        <input type="hidden" name="id" value="<?= $id ?>">
                                        <button type="submit" class="small danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php }
                } else { ?>
                    <tr>
                        <td colspan="4" class="muted">Database connection failed. Please try again later.</td>
                    </tr>
                <?php } ?>
            </tbody>
        </table>
    </div>
</section>


<?php
$addons = mysqli_query($conn, "SELECT * FROM addon ORDER BY name");
?>

<section class="panel" id="addons">
    <h2>Add-ons Management</h2>
    <p class="muted">Add, edit, and manage your takoyaki add-ons.</p>

    <h3>Add new add-on</h3>

    <form method="POST" class="form-grid">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_addon">

        <div class="field">
            <label>Add-on name</label>
            <input type="text" name="name" maxlength="100" required>
        </div>

        <div class="field">
            <label>Price</label>
            <input type="number" name="price" min="0" step="0.01" required>
        </div>

        <div class="field">
            <label>Availability</label>
            <select name="is_available">
                <option value="1">Available</option>
                <option value="0">Sold out</option>
            </select>
        </div>

        <div class="field">
            <button type="submit">Add add-on</button>
        </div>
    </form>
</section>

<section class="panel">
    <h2>Current Add-ons</h2>

    <?php if ($addons) { ?>
        <?php while ($addon = mysqli_fetch_assoc($addons)) { ?>
            <form id="addon-<?= $addon['addon_id'] ?>" method="POST"></form>
        <?php } ?>
    <?php } ?>

    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Price</th>
                    <th>Availability</th>
                    <th>Actions</th>
                </tr>
            </thead>

            <tbody>
                <?php
                if ($addons) {
                    mysqli_data_seek($addons, 0);

                    while ($addon = mysqli_fetch_assoc($addons)) {
                        $id = (int)$addon['addon_id'];
                ?>
                    <tr>
                        <td>
                            <input form="addon-<?= $id ?>" type="text"
                                   name="name"
                                   value="<?= htmlspecialchars($addon['name']) ?>"
                                   required>
                        </td>

                        <td>
                            <input form="addon-<?= $id ?>" type="number"
                                   name="price" min="0" step="0.01"
                                   value="<?= htmlspecialchars($addon['price']) ?>"
                                   required>
                        </td>

                        <td>
                            <select form="addon-<?= $id ?>" name="is_available">
                                <option value="1" <?= $addon['is_available'] == 1 ? 'selected' : '' ?>>
                                    Available
                                </option>
                                <option value="0" <?= $addon['is_available'] == 0 ? 'selected' : '' ?>>
                                    Sold out
                                </option>
                            </select>
                        </td>

                        <td class="actions">
                            <input form="addon-<?= $id ?>" type="hidden"
                                   name="csrf_token"
                                   value="<?= htmlspecialchars(csrf_token()) ?>">

                            <input form="addon-<?= $id ?>" type="hidden"
                                   name="addon_id" value="<?= $id ?>">

                            <button form="addon-<?= $id ?>" type="submit"
                                    name="action" value="save_addon"
                                    class="small">
                                Save
                            </button>
                          
                            <button form="addon-<?= $id ?>" type="submit"
                                    name="action" value="delete_addon"
                                    class="small danger"
                                    formnovalidate
                                    onclick="return confirm('Permanently delete this add-on?')">
                                Delete
                            </button>                           
                        </td>
                    </tr>
                <?php
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
</section>

<?php include('../../includes/footer.php'); ?>
