<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reviews.php';

require_customer();

$flavorId = filter_input(INPUT_POST, 'flavor_id', FILTER_VALIDATE_INT);
$rating = filter_input(INPUT_POST, 'rating', FILTER_VALIDATE_INT);
$textInput = $_POST['review_text'] ?? '';
$text = is_string($textInput) ? trim($textInput) : '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !verify_csrf_token($_POST['csrf_token'] ?? null)) {
    $error = 'Your session expired. Please try that action again.';
} elseif (!$flavorId || !$rating || $rating < 1 || $rating > 5) {
    $error = 'Choose a product and a rating from 1 to 5.';
} elseif ($text === '' || strlen($text) > 1000) {
    $error = 'Review text is required and must be 1,000 characters or fewer.';
} elseif (!$conn) {
    $error = 'Database connection failed. Please try again later.';
} else {
    $eligibility = mysqli_prepare($conn, 'SELECT 1 FROM customer_profile cp
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
    LIMIT 1');
    $completed = 'Completed';
    if (!$eligibility) {
        error_log('Review eligibility preparation failed: ' . mysqli_error($conn));
        $error = 'Unable to verify your purchase right now.';
    } else {
        $userId = (int) $_SESSION['user_id'];
        mysqli_stmt_bind_param($eligibility, 'iis', $userId, $flavorId, $completed);
        $eligible = mysqli_stmt_execute($eligibility)
            && mysqli_fetch_assoc(mysqli_stmt_get_result($eligibility));
        if (!$eligible) {
            $error = 'You can review a product after completing an order that includes it.';
        }
        mysqli_stmt_close($eligibility);
    }

    if ($error === null) {
        try {
            $filteredText = mask_review_profanity($text);
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }
    }
    if ($error === null) {
        $stmt = mysqli_prepare($conn, 'INSERT INTO product_review (user_id, flavor_id, rating, review_text)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE rating = VALUES(rating), review_text = VALUES(review_text), updated_at = CURRENT_TIMESTAMP');
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iiis', $userId, $flavorId, $rating, $filteredText);
            if (!mysqli_stmt_execute($stmt)) {
                error_log('Review save failed: ' . mysqli_stmt_error($stmt));
                $error = 'Unable to save your review right now.';
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log('Review save preparation failed: ' . mysqli_error($conn));
            $error = 'Unable to save your review right now.';
        }
    }
}

$_SESSION['message'] = $error ?? 'Your review has been saved.';
$_SESSION['msg_type'] = $error === null ? 'success' : 'danger';
header(
    'Location: /takoyaki-shop-infoman/flavors/view.php?id='
    . (int)$flavorId
    . '&review=view'
);
exit();
