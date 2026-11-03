<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';

require_customer();

$base = '/takoyaki-shop-infoman';
$page = 'menu';
$flavorId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$flavor = null;
$photos = [];
$reviews = [];
$currentReview = null;
$canReview = false;

if ($conn && $flavorId) {
    $stmt = mysqli_prepare($conn, 'SELECT flavor_id, name, description, image, is_available FROM flavor WHERE flavor_id = ? LIMIT 1');
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, 'i', $flavorId);
        mysqli_stmt_execute($stmt);
        $flavor = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        mysqli_stmt_close($stmt);
    }

    if ($flavor) {
        $photoStmt = mysqli_prepare($conn, 'SELECT photo_path FROM flavor_photo WHERE flavor_id = ? ORDER BY photo_id');
        if ($photoStmt) {
            mysqli_stmt_bind_param($photoStmt, 'i', $flavorId);
            mysqli_stmt_execute($photoStmt);
            $photoResult = mysqli_stmt_get_result($photoStmt);
            while ($photo = mysqli_fetch_assoc($photoResult)) {
                $photos[] = $photo['photo_path'];
            }
            mysqli_stmt_close($photoStmt);
        }

        $reviewStmt = mysqli_prepare($conn, 'SELECT r.rating, r.review_text, r.created_at, r.updated_at, cp.full_name
            FROM product_review r
            LEFT JOIN customer_profile cp ON cp.user_id = r.user_id
            WHERE r.flavor_id = ? ORDER BY r.updated_at DESC');
        if ($reviewStmt) {
            mysqli_stmt_bind_param($reviewStmt, 'i', $flavorId);
            mysqli_stmt_execute($reviewStmt);
            $reviewResult = mysqli_stmt_get_result($reviewStmt);
            while ($review = mysqli_fetch_assoc($reviewResult)) {
                $reviews[] = $review;
            }
            mysqli_stmt_close($reviewStmt);
        }

        $userId = (int) $_SESSION['user_id'];
        $eligibility = mysqli_prepare($conn, '
            SELECT 1
            FROM customer_profile cp
            INNER JOIN orders o ON o.customer_id = cp.customer_id
            INNER JOIN order_item oi ON oi.order_id = o.order_id
            INNER JOIN flavor_size fs ON fs.size_id = oi.size_id
            WHERE cp.user_id = ?
            AND fs.flavor_id = ?
            AND o.status = ?
            AND EXISTS (
                SELECT 1
                FROM payment p
                WHERE p.order_id = o.order_id
            )
            LIMIT 1
        ');

        if ($eligibility) {
    $completedStatus = 'Completed';

    mysqli_stmt_bind_param(
        $eligibility,
        'iis',
        $userId,
        $flavorId,
        $completedStatus
    );

    mysqli_stmt_execute($eligibility);

    $eligibilityResult = mysqli_stmt_get_result($eligibility);
    $canReview = mysqli_num_rows($eligibilityResult) > 0;

    mysqli_stmt_close($eligibility);
}

        $currentStmt = mysqli_prepare($conn, 'SELECT rating, review_text FROM product_review WHERE user_id = ? AND flavor_id = ? LIMIT 1');
        if ($currentStmt) {
            mysqli_stmt_bind_param($currentStmt, 'ii', $userId, $flavorId);
            mysqli_stmt_execute($currentStmt);
            $currentReview = mysqli_fetch_assoc(mysqli_stmt_get_result($currentStmt));
            mysqli_stmt_close($currentStmt);
        }
    }
}

if (!$flavor) {
    http_response_code(404);
}

include __DIR__ . '/../includes/header.php';
?>

<style>
.gallery {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
}

.gallery .gallery-image {
    width: 180px;
    height: 180px;
    max-width: 100%;
    object-fit: cover;
    border-radius: 12px;
}
</style>

<div class="page-title">
    <div><div class="eyebrow">Menu item</div><h1><?= htmlspecialchars($flavor['name'] ?? 'Product not found') ?></h1></div>
    <a class="btn secondary" href="<?= $base ?>/menu.php">Back to menu</a>
</div>
<?php include __DIR__ . '/../includes/alert.php'; ?>

<?php if (!$flavor) { ?>
    <section class="panel empty">This product could not be found.</section>
<?php } else { ?>
    <section class="panel">
        <p><?= htmlspecialchars($flavor['description'] ?? '') ?></p>
        <div class="gallery">
            <?php if (!empty($flavor['image'])) { ?>
                <img class="gallery-image" src="<?= htmlspecialchars($base . '/' . $flavor['image']) ?>" alt="<?= htmlspecialchars($flavor['name']) ?>">
            <?php } ?>
            <?php foreach ($photos as $photo) { ?>
                <img class="gallery-image" src="<?= htmlspecialchars($base . '/' . $photo) ?>" alt="<?= htmlspecialchars($flavor['name']) ?>">
            <?php } ?>
            <?php if (empty($flavor['image']) && !$photos) { ?>
                <img class="gallery-image" src="<?= $base ?>/images/takoyaki_logo.jpg" alt="<?= htmlspecialchars($flavor['name']) ?>">
            <?php } ?>
        </div>
    </section>

    <section class="panel review-panel">
        <h2>Customer reviews</h2>
        <?php if ($reviews) { foreach ($reviews as $review) { ?>
            <article class="review">
                <strong><?= htmlspecialchars($review['full_name'] ?: 'Customer') ?></strong>
                <span class="review-rating" aria-label="<?= (int) $review['rating'] ?> out of 5 stars"><?= str_repeat('★', (int) $review['rating']) . str_repeat('☆', 5 - (int) $review['rating']) ?></span>
                <p><?= nl2br(htmlspecialchars($review['review_text'])) ?></p>
                <small class="muted">Updated <?= htmlspecialchars(date('M d, Y', strtotime($review['updated_at']))) ?></small>
            </article>
        <?php } } else { ?>
            <p class="muted">No reviews yet.</p>
        <?php } ?>
    </section>

    <?php
$reviewMode = $_GET['review'] ?? '';

if ($canReview && $reviewMode === '') {
    $reviewMode = $currentReview ? 'view' : 'write';
}

$showReviewForm = $canReview && (
    ($reviewMode === 'write' && !$currentReview)
    || ($reviewMode === 'edit' && $currentReview)
);
?>

<?php if ($canReview && $currentReview && $reviewMode === 'view') { ?>
        <section class="panel narrow">
            <h2>Your Review</h2>

            <p>
                <?= str_repeat('★', (int)$currentReview['rating']) ?>
            </p>

            <p><?= nl2br(htmlspecialchars($currentReview['review_text'])) ?></p>

            <a class="btn secondary"
            href="<?= $base ?>/flavors/view.php?id=<?= (int)$flavorId ?>&review=edit">
                Edit Review
            </a>
        </section>
    <?php } ?>

    <?php if ($showReviewForm) { ?>
        <section class="panel narrow">
            <h2><?= $currentReview ? 'Update your review' : 'Review this product' ?></h2>
            <p class="muted tiny">Reviews are available after a completed order containing this product. Foul language is automatically masked.</p>
            <form method="POST" action="<?= $base ?>/reviews/save.php" novalidate>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
                <input type="hidden" name="flavor_id" value="<?= (int) $flavorId ?>">
                <div class="field">
                    <label for="rating">Rating (1 to 5)</label>
                    <select id="rating" name="rating">
                        <?php for ($rating = 5; $rating >= 1; $rating--) { ?>
                            <option value="<?= $rating ?>" <?= (int) ($currentReview['rating'] ?? 5) === $rating ? 'selected' : '' ?>><?= $rating ?> star<?= $rating === 1 ? '' : 's' ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="field">
                    <label for="review_text">Your review</label>
                    <textarea id="review_text" name="review_text" maxlength="1000"><?= htmlspecialchars($currentReview['review_text'] ?? '') ?></textarea>
                </div>
                <button type="submit"><?= $currentReview ? 'Update review' : 'Submit review' ?></button>
            </form>
        </section>
    <?php } ?>
<?php } ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
