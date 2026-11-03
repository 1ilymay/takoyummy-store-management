
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

session_start();
include 'includes/config.php';
include 'includes/auth.php';

$page = 'menu';
include 'includes/header.php';

$flavors = false;
if (isset($conn) && $conn) {
    $flavors = mysqli_query($conn, 'SELECT * FROM flavor ORDER BY is_available DESC, name');
}

$first = null;
if (isset($conn) && $conn) {
    $result = mysqli_query(
        $conn,
        'SELECT flavor_id FROM flavor WHERE is_available = 1 ORDER BY name LIMIT 1'
    );
    if ($result) {
        $first = mysqli_fetch_assoc($result);
    }
}

$featuredId = $first ? (int) $first['flavor_id'] : 0;

$cartLines = [];
$subtotal = 0;

if (isset($_SESSION['cart']) && isset($conn) && $conn) {
    foreach ($_SESSION['cart'] as $key => $item) {
        $sizeId = (int) $item['size_id'];

        $result = mysqli_query($conn, "
            SELECT fs.size_label, fs.price, f.name
            FROM flavor_size fs
            INNER JOIN flavor f ON f.flavor_id = fs.flavor_id
            WHERE fs.size_id = $sizeId
        ");

        $size = $result ? mysqli_fetch_assoc($result) : null;
        if (!$size) continue;

        $addonNames = [];
        $addonTotal = 0;

        foreach (($item['addons'] ?? []) as $addonId) {
            $addonId = (int) $addonId;

            $result = mysqli_query($conn, "
                SELECT name, price
                FROM addon
                WHERE addon_id = $addonId
                AND is_available = 1
            ");

            $addon = $result ? mysqli_fetch_assoc($result) : null;

            if ($addon) {
                $addonNames[] = $addon['name'];
                $addonTotal += (float) $addon['price'];
            }
        }

        $qty = (int) $item['qty'];
        $lineTotal = ((float) $size['price'] + $addonTotal) * $qty;
        $subtotal += $lineTotal;

        $cartLines[] = [
            'key' => $key,
            'name' => $size['name'],
            'size' => $size['size_label'],
            'addons' => implode(', ', $addonNames),
            'qty' => $qty,
            'total' => $lineTotal
        ];
    }
}

$cartCount = 0;
foreach ($cartLines as $line) {
    $cartCount += $line['qty'];
}

$orderType = $_SESSION['order_type'] ?? 'delivery';

if (!in_array($orderType, ['delivery', 'pickup', 'dine-in'], true)) {
    $orderType = 'delivery';
    $_SESSION['order_type'] = 'delivery';
}

$deliveryFee = $orderType === 'delivery'
    ? (float) ($settings['delivery_fee'] ?? 0)
    : 0;

$total = $subtotal + $deliveryFee;
?>

<style>
.menu-page .hero-banner {
    min-height: 255px;
    background:
        linear-gradient(
            90deg,
            #f1e6d7 0%,
            rgba(241,230,215,.94) 43%,
            rgba(241,230,215,.08) 100%
        ),
        url('<?= htmlspecialchars($base) ?>/images/octobits.jpg') center/cover;
    border-radius: 18px;
    display: flex;
    align-items: center;
    padding: 30px;
    margin-bottom: 18px;
}

.menu-page .hero-copy {
    max-width: 470px;
}

.menu-page .hero-copy h2 {
    font-size: clamp(25px, 3vw, 38px);
    line-height: 1.12;
    margin: 8px 0;
}

.menu-page .hero-copy p {
    margin: 0 0 18px;
}

.menu-page .shop-quickbar {
    margin: 12px 0 18px;
}

.menu-page .menu-search {
    display: flex;
    align-items: center;
    gap: 8px;
    max-width: 440px;
}

.menu-page .menu-search input {
    flex: 1;
    min-width: 0;
}

.menu-page .products {
    grid-template-columns: repeat(2, minmax(0, 1fr));
}

.menu-page .product {
    display: block;
    grid-column: auto;
    min-width: 0;
}

.menu-page .menu-expand {
    display: block;
}

.menu-page .menu-preview {
    display: block;
    cursor: pointer;
    list-style: none;
}

.menu-page .menu-preview::-webkit-details-marker {
    display: none;
}

.menu-page .menu-preview .food-photo {
    height: 180px;
    min-height: 0;
    overflow: hidden;
}

.menu-page .menu-preview .food-photo img {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.menu-page .menu-preview .product-content {
    padding: 14px 16px;
}

.menu-page .menu-preview h3 {
    margin: 0 0 6px;
}

.menu-page .menu-preview .product-desc {
    margin: 0;
}

.menu-page .menu-open-hint {
    display: block;
    margin-top: 12px;
    font-size: 12px;
    color: #916b51;
    font-weight: 700;
}

.menu-page .menu-expand[open] .menu-open-hint {
    display: none;
}

.menu-page .menu-expanded {
    padding: 14px 16px 18px;
    border-top: 1px solid #eee4d8;
}

.menu-page .menu-expanded .sizes {
    flex-wrap: wrap;
    margin: 7px 0 14px;
}

.menu-page .menu-expanded .size-option {
    min-width: 85px;
    cursor: pointer;
}

.menu-page .menu-expanded .toppings {
    margin: 7px 0 14px;
}

.menu-page .menu-expanded .product-actions {
    margin-top: 12px;
}

.menu-page .menu-reviews {
    border-top: 1px solid #eee4d8;
    margin-top: 16px;
    padding-top: 12px;
}

.menu-page .menu-review {
    padding: 10px 0;
    border-bottom: 1px solid #f0e8df;
}

.menu-page .menu-review p {
    margin: 5px 0;
    font-size: 13px;
}

.menu-page .review-stars {
    color: #c7882b;
}

.menu-page .side-col {
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.menu-page .cart-panel {
    top: 130px;
}

@media (max-width: 850px) {
    .menu-page .layout {
        grid-template-columns: 1fr;
    }

    .menu-page .cart-panel {
        position: static;
    }
}

@media (max-width: 550px) {
    .menu-page .products {
        grid-template-columns: 1fr;
    }

    .menu-page .hero-banner {
        padding: 22px;
        min-height: 210px;
    }
}
</style>

<div class="menu-page">

    <div class="hero-banner">
        <div class="hero-copy">
            <div class="eyebrow">Fresh off the griddle</div>
            <h2>Hot takoyaki, made for sharing.</h2>
            <p>Choose your favorite flavor and build the perfect bite.</p>
            <a class="btn" href="#products">Order now</a>
        </div>
    </div>

    <div class="shop-quickbar">
        <label class="menu-search" for="menuSearch">
            <input
                id="menuSearch"
                type="search"
                placeholder="Search flavors..."
                autocomplete="off"
            >
            <button
                type="button"
                onclick="document.getElementById('menuSearch').focus()"
            >
                Search
            </button>
        </label>
    </div>

    <?php include 'includes/alert.php'; ?>

    <div class="layout" id="products">

        <section>
            <div class="products">

                <?php if (!$flavors || mysqli_num_rows($flavors) === 0) { ?>

                    <div class="panel empty" style="grid-column:1/-1">
                        No menu items yet. Please check back soon.
                    </div>

                <?php } else { ?>

                    <?php while ($f = mysqli_fetch_assoc($flavors)) {

                        $flavorId = (int) $f['flavor_id'];

                        $sizes = mysqli_query($conn, "
                            SELECT *
                            FROM flavor_size
                            WHERE flavor_id = $flavorId
                            ORDER BY price
                        ");

                        $addons = mysqli_query($conn, "
                            SELECT *
                            FROM addon
                            WHERE is_available = 1
                            ORDER BY name
                        ");

                        $hasSizes = $sizes && mysqli_num_rows($sizes) > 0;
                        $hasOrderableSize = false;

                        if ($hasSizes) {
                            while ($checkSize = mysqli_fetch_assoc($sizes)) {
                                if ((int) $checkSize['is_available'] === 1) {
                                    $hasOrderableSize = true;
                                }
                            }
                            mysqli_data_seek($sizes, 0);
                        }

                        $canOrder =
                            (int) $f['is_available'] === 1 &&
                            $hasOrderableSize;

                        $reviews = [];

                        $reviewResult = mysqli_query($conn, "
                            SELECT
                                r.rating,
                                r.review_text,
                                cp.full_name
                            FROM product_review r
                            LEFT JOIN customer_profile cp
                                ON cp.user_id = r.user_id
                            WHERE r.flavor_id = $flavorId
                            ORDER BY r.updated_at DESC
                            LIMIT 3
                        ");

                        if ($reviewResult) {
                            while ($review = mysqli_fetch_assoc($reviewResult)) {
                                $reviews[] = $review;
                            }
                        }
                    ?>

                    <article
                        class="product product-search-item <?= (int) $f['is_available'] === 0 ? 'sold' : '' ?>"
                        data-search="<?= htmlspecialchars(
                            strtolower($f['name'] . ' ' . ($f['description'] ?? '')),
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                    >

                        <details class="menu-expand">

                            <summary class="menu-preview">

                                <div class="food-photo">

                                    <?php if (!empty($f['image'])) { ?>

                                        <img
                                            src="<?= htmlspecialchars(
                                                $base . '/' . $f['image'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                            alt="<?= htmlspecialchars($f['name']) ?>"
                                        >

                                    <?php } else { ?>

                                        <img
                                            src="<?= htmlspecialchars($base) ?>/images/takoyaki_logo.jpg"
                                            alt="Takoyaki"
                                        >

                                    <?php } ?>

                                    <?php if ((int) $f['is_available'] === 0) { ?>
                                        <div class="sold-overlay">Sold Out</div>
                                    <?php } ?>

                                </div>

                                <div class="product-content">
                                    <h3><?= htmlspecialchars($f['name']) ?></h3>

                                    <p class="product-desc">
                                        <?= htmlspecialchars($f['description'] ?? '') ?>
                                    </p>

                                    <span class="menu-open-hint">
                                        View sizes, toppings &amp; reviews +
                                    </span>
                                </div>

                            </summary>

                            <div class="menu-expanded">

                                <form action="cart_update.php" method="POST">

                                    <input
                                        type="hidden"
                                        name="action"
                                        value="add"
                                    >

                                    <?php if ($hasSizes) { ?>

                                        <label>Choose a size</label>

                                        <div class="sizes">

                                            <?php
                                            $firstSize = true;

                                            while ($s = mysqli_fetch_assoc($sizes)) {

                                                $ok =
                                                    (int) $f['is_available'] === 1 &&
                                                    (int) $s['is_available'] === 1;
                                            ?>

                                                <label class="size-option">

                                                    <input
                                                        type="radio"
                                                        name="size_id"
                                                        value="<?= (int) $s['size_id'] ?>"
                                                        <?= $ok && $firstSize ? 'checked' : '' ?>
                                                        <?= !$ok ? 'disabled' : '' ?>
                                                    >

                                                    <strong>
                                                        <?= htmlspecialchars($s['size_label']) ?>
                                                    </strong>

                                                    ₱<?= number_format((float) $s['price'], 2) ?>

                                                </label>

                                                <?php
                                                if ($ok && $firstSize) {
                                                    $firstSize = false;
                                                }
                                                ?>

                                            <?php } ?>

                                        </div>

                                    <?php } else { ?>

                                        <p class="tiny muted">
                                            No sizes available yet.
                                        </p>

                                    <?php } ?>

                                    <?php if (
                                        $canOrder &&
                                        $addons &&
                                        mysqli_num_rows($addons) > 0
                                    ) { ?>

                                        <label>Optional toppings and extras</label>

                                        <div class="toppings">

                                            <?php while ($a = mysqli_fetch_assoc($addons)) { ?>

                                                <label class="topping">

                                                    <input
                                                        type="checkbox"
                                                        name="addons[]"
                                                        value="<?= (int) $a['addon_id'] ?>"
                                                    >

                                                    <?= htmlspecialchars($a['name']) ?>

                                                    +₱<?= number_format((float) $a['price'], 2) ?>

                                                </label>

                                            <?php } ?>

                                        </div>

                                    <?php } ?>

                                    <div class="product-actions">

                                        <input
                                            type="number"
                                            name="qty"
                                            min="1"
                                            max="99"
                                            value="1"
                                            aria-label="Quantity"
                                            <?= !$canOrder ? 'disabled' : '' ?>
                                        >

                                        <button
                                            type="submit"
                                            <?= !$canOrder ? 'disabled' : '' ?>
                                        >
                                            ＋ Add to Cart
                                        </button>

                                    </div>

                                </form>

                                <div class="menu-reviews">

                                    <strong>Customer reviews</strong>

                                    <?php if (!$reviews) { ?>
                                        <p class="tiny muted">
                                            No reviews yet.
                                        </p>
                                    <?php } ?>

                                    <?php foreach ($reviews as $review) { ?>

                                        <div class="menu-review">

                                            <strong>
                                                <?= htmlspecialchars(
                                                    $review['full_name'] ?: 'Customer'
                                                ) ?>
                                            </strong>

                                            <span class="review-stars">
                                                <?= str_repeat('★', (int) $review['rating']) ?>
                                                <?= str_repeat(
                                                    '☆',
                                                    max(0, 5 - (int) $review['rating'])
                                                ) ?>
                                            </span>

                                            <p>
                                                <?= nl2br(
                                                    htmlspecialchars($review['review_text'])
                                                ) ?>
                                            </p>

                                        </div>

                                    <?php } ?>

                                    <p>
                                        <a href="<?= htmlspecialchars($base) ?>/flavors/view.php?id=<?= $flavorId ?>">
                                            View all reviews
                                        </a>
                                    </p>

                                </div>

                            </div>

                        </details>

                    </article>

                    <?php } ?>

                <?php } ?>

                <div
                    class="panel empty hidden"
                    id="noSearchResults"
                    style="grid-column:1/-1"
                >
                    No menu items match your search.
                </div>

            </div>
        </section>

        <aside class="side-col">

            <div class="promo-card mini">
                <div>
                    <div class="eyebrow">Today’s deal</div>
                    <h3>30% off combo packs</h3>
                    <p>Perfect for sharing with friends.</p>
                </div>
            </div>

            <div class="promo-card mini alt">
                <div>
                    <div class="eyebrow">Chef’s pick</div>
                    <h3>Signature flavor</h3>
                    <p>Smoky, savory, and crispy every time.</p>
                </div>
            </div>

            <section class="panel cart-panel" id="cartPanel">

                <h2>
                    Your Cart
                    <span class="muted tiny">
                        (<?= $cartCount ?> items)
                    </span>
                </h2>

                <?php if (count($cartLines) > 0) { ?>

                    <form
                        action="cart_update.php"
                        method="POST"
                        class="field"
                    >

                        <input
                            type="hidden"
                            name="action"
                            value="set_type"
                        >

                        <label for="order_type">Order type</label>

                        <select
                            id="order_type"
                            name="order_type"
                            onchange="this.form.submit()"
                        >

                            <option
                                value="delivery"
                                <?= $orderType === 'delivery' ? 'selected' : '' ?>
                            >
                                Delivery
                            </option>

                            <option
                                value="pickup"
                                <?= $orderType === 'pickup' ? 'selected' : '' ?>
                            >
                                Pickup
                            </option>

                            <option
                                value="dine-in"
                                <?= $orderType === 'dine-in' ? 'selected' : '' ?>
                            >
                                Dine-in
                            </option>

                        </select>

                    </form>

                    <div class="cart-items">

                        <?php foreach ($cartLines as $line) { ?>

                            <div class="cart-item">

                                <div class="cart-item-head">

                                    <span>
                                        <?= htmlspecialchars($line['name']) ?>
                                    </span>

                                    <strong>
                                        ₱<?= number_format((float) $line['total'], 2) ?>
                                    </strong>

                                </div>

                                <small>
                                    <?= htmlspecialchars($line['size']) ?>

                                    <?php if ($line['addons'] !== '') { ?>
                                        · <?= htmlspecialchars($line['addons']) ?>
                                    <?php } ?>
                                </small>

                                <div class="qty">

                                    <form action="cart_update.php" method="POST">

                                        <input type="hidden" name="action" value="update">

                                        <input
                                            type="hidden"
                                            name="key"
                                            value="<?= htmlspecialchars(
                                                (string) $line['key'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="qty"
                                            value="<?= (int) $line['qty'] - 1 ?>"
                                        >

                                        <button type="submit">−</button>

                                    </form>

                                    <strong><?= (int) $line['qty'] ?></strong>

                                    <form action="cart_update.php" method="POST">

                                        <input type="hidden" name="action" value="update">

                                        <input
                                            type="hidden"
                                            name="key"
                                            value="<?= htmlspecialchars(
                                                (string) $line['key'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >

                                        <input
                                            type="hidden"
                                            name="qty"
                                            value="<?= (int) $line['qty'] + 1 ?>"
                                        >

                                        <button type="submit">+</button>

                                    </form>

                                    <form action="cart_update.php" method="POST">

                                        <input type="hidden" name="action" value="remove">

                                        <input
                                            type="hidden"
                                            name="key"
                                            value="<?= htmlspecialchars(
                                                (string) $line['key'],
                                                ENT_QUOTES,
                                                'UTF-8'
                                            ) ?>"
                                        >

                                        <button
                                            type="submit"
                                            class="ghost small"
                                        >
                                            Remove
                                        </button>

                                    </form>

                                </div>

                            </div>

                        <?php } ?>

                    </div>

                    <div class="summary-line">
                        <span>Subtotal</span>
                        <strong>
                            ₱<?= number_format((float) $subtotal, 2) ?>
                        </strong>
                    </div>

                    <div class="summary-line">

                        <span>
                            <?= $orderType === 'delivery'
                                ? 'Delivery fee'
                                : 'Service fee' ?>
                        </span>

                        <strong>
                            ₱<?= number_format((float) $deliveryFee, 2) ?>
                        </strong>

                    </div>

                    <div class="summary-line total">

                        <span>Total</span>

                        <strong>
                            ₱<?= number_format((float) $total, 2) ?>
                        </strong>

                    </div>

                    <a class="btn full" href="checkout.php">
                        Proceed to Checkout →
                    </a>

                    <p class="tiny muted">
                        You can review your order before confirming.
                    </p>

                <?php } else { ?>

                    <div class="empty">
                        Your cart is empty.

                        <p class="tiny muted">
                            Choose something delicious to get started.
                        </p>
                    </div>

                <?php } ?>

            </section>

        </aside>

    </div>

</div>

<script>
const menuSearch = document.getElementById('menuSearch');
const searchItems = document.querySelectorAll('.product-search-item');
const noSearchResults = document.getElementById('noSearchResults');

if (menuSearch && noSearchResults) {
    menuSearch.addEventListener('input', function () {
        const query = this.value.trim().toLowerCase();
        let visibleCount = 0;

        searchItems.forEach(function (item) {
            const matches = item.dataset.search.includes(query);

            item.classList.toggle('hidden', !matches);

            if (matches) {
                visibleCount++;
            }
        });

        noSearchResults.classList.toggle(
            'hidden',
            visibleCount > 0
        );
    });
}
</script>

<?php include 'includes/footer.php'; ?>
