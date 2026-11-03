<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/uploads.php';

require_customer();

$base = '/takoyaki-shop-infoman';
$userId = (int) $_SESSION['user_id'];
$error = null;
$stmt = mysqli_prepare($conn, 'SELECT u.email, u.password, cp.full_name, cp.contact_number, cp.default_address, cp.photo_path
    FROM users u LEFT JOIN customer_profile cp ON cp.user_id = u.user_id
    WHERE u.user_id = ? LIMIT 1');
$userData = null;
if ($stmt) {
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (mysqli_stmt_execute($stmt)) {
        $userData = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    } else {
        error_log('Profile lookup failed: ' . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
}
if (!$userData) {
    http_response_code(500);
    $error = 'Unable to load your profile right now.';
    $userData = ['email' => '', 'password' => '', 'full_name' => '', 'contact_number' => '', 'default_address' => '', 'photo_path' => null];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameInput = $_POST['full_name'] ?? '';
    $phoneInput = $_POST['contact_number'] ?? '';
    $addressInput = $_POST['default_address'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $currentPasswordInput = $_POST['current_password'] ?? '';
    $newPasswordInput = $_POST['new_password'] ?? '';
    $confirmPasswordInput = $_POST['confirm_password'] ?? '';
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
    $address = is_string($addressInput) ? trim($addressInput) : '';
    $email = is_string($emailInput) ? strtolower(trim($emailInput)) : '';
    $currentPassword = is_string($currentPasswordInput) ? $currentPasswordInput : '';
    $newPassword = is_string($newPasswordInput) ? $newPasswordInput : '';
    $confirmPassword = is_string($confirmPasswordInput) ? $confirmPasswordInput : '';
    $photoPath = null;

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try that action again.';
    } elseif ($name === '' || strlen($name) > 100 || strlen($phone) > 20 || strlen($address) > 255) {
        $error = 'Name is required. Name, phone, or address exceeds the allowed length.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        $error = 'Enter a valid email address (maximum 100 characters).';
    } elseif ($newPassword !== '' && (strlen($newPassword) < 6 || $newPassword !== $confirmPassword)) {
        $error = 'New password must be at least 6 characters and match the confirmation.';
    } elseif ($newPassword !== '' && !password_verify($currentPassword, $userData['password'])) {
        $error = 'Your current password is incorrect.';
    } elseif (!$conn) {
        $error = 'Database connection failed. Please try again later.';
    } else {
        try {
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
                $photoPath = save_uploaded_image($_FILES['photo'], 'uploads/profiles');
            }
        } catch (RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        if ($error === null) {
            $profileImage = $photoPath ?? $userData['photo_path'];
            $emailCheck = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? AND user_id <> ? LIMIT 1');
            if ($emailCheck) {
                mysqli_stmt_bind_param($emailCheck, 'si', $email, $userId);
                mysqli_stmt_execute($emailCheck);
                $emailTaken = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($emailCheck));
                mysqli_stmt_close($emailCheck);
                if ($emailTaken) {
                    $error = 'That email address is already in use.';
                }
            } else {
                error_log('Profile email check preparation failed: ' . mysqli_error($conn));
                $error = 'Unable to verify your email address.';
            }
        }

        if ($error === null) {
            mysqli_begin_transaction($conn);
            $passwordHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_DEFAULT) : $userData['password'];
            $accountUpdate = mysqli_prepare($conn, 'UPDATE users SET email = ?, password = ? WHERE user_id = ?');
            $accountSaved = false;
            if ($accountUpdate) {
                mysqli_stmt_bind_param($accountUpdate, 'ssi', $email, $passwordHash, $userId);
                $accountSaved = mysqli_stmt_execute($accountUpdate);
                if (!$accountSaved) {
                    error_log('Profile account update failed: ' . mysqli_stmt_error($accountUpdate));
                }
                mysqli_stmt_close($accountUpdate);
            } else {
                error_log('Profile account update preparation failed: ' . mysqli_error($conn));
            }

            $update = mysqli_prepare($conn, 'INSERT INTO customer_profile
                (user_id, full_name, contact_number, default_address, photo_path)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE full_name = VALUES(full_name),
                    contact_number = VALUES(contact_number), default_address = VALUES(default_address),
                    photo_path = VALUES(photo_path)');
            if ($update) {
                mysqli_stmt_bind_param($update, 'issss', $userId, $name, $phone, $address, $profileImage);
                $profileSaved = $accountSaved && mysqli_stmt_execute($update);
                if ($profileSaved) {
                    mysqli_commit($conn);
                    delete_uploaded_image($photoPath ? $userData['photo_path'] : null);
                    $_SESSION['user_name'] = $name;
                    $_SESSION['user_email'] = $email;
                    $_SESSION['message'] = 'Your profile has been updated.';
                    $_SESSION['msg_type'] = 'success';
                    mysqli_stmt_close($update);
                    header('Location: ' . $base . '/user/profile.php');
                    exit();
                }
                error_log('Profile update failed: ' . mysqli_stmt_error($update));
                mysqli_rollback($conn);
                $error = 'Unable to save your profile right now.';
                mysqli_stmt_close($update);
            } else {
                error_log('Profile update preparation failed: ' . mysqli_error($conn));
                $error = 'Unable to save your profile right now.';
            }
        }
        if ($error !== null) {
            delete_uploaded_image($photoPath);
            $userData['full_name'] = $name;
            $userData['contact_number'] = $phone;
            $userData['default_address'] = $address;
        }
    }
}

$page = 'account';
include __DIR__ . '/../includes/header.php';
?>

<div class="page-title">
    <div><div class="eyebrow">My account</div><h1>Profile</h1></div>
</div>
<?php include __DIR__ . '/../includes/alert.php'; ?>
<?php if ($error) { ?><div class="alert danger"><?= htmlspecialchars($error) ?></div><?php } ?>

<section class="panel narrow">
    <?php if (!empty($userData['photo_path'])) { ?>
        <div class="field">
            <label>Current photo</label>
            <img class="profile-photo"
            src="<?= htmlspecialchars($base . '/' . $userData['photo_path']) ?>"
            alt="Your profile photo"
            style="width:120px; height:120px; border-radius:50%; object-fit:cover; display:block;">
        </div>
    <?php } ?>
    <form method="POST" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">
        <div class="field">
            <label for="full_name">Name</label>
            <input id="full_name" name="full_name" type="text" maxlength="100" value="<?= htmlspecialchars($userData['full_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" maxlength="100" value="<?= htmlspecialchars($userData['email'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="contact_number">Contact number</label>
            <input id="contact_number" name="contact_number" type="text" maxlength="20" value="<?= htmlspecialchars($userData['contact_number'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="default_address">Default address</label>
            <textarea id="default_address" name="default_address" maxlength="255"><?= htmlspecialchars($userData['default_address'] ?? '') ?></textarea>
        </div>
        <div class="field">
            <label for="photo">Replace profile photo (JPG, PNG, WEBP; max 5 MB)</label>
            <input id="photo" name="photo" type="file" accept=".jpg,.jpeg,.png,.webp">
        </div>
        <div class="field">
            <label for="current_password">Current password (required to change password)</label>
            <input id="current_password" name="current_password" type="password">
        </div>
        <div class="field">
            <label for="new_password">New password (optional)</label>
            <input id="new_password" name="new_password" type="password" minlength="6">
        </div>
        <div class="field">
            <label for="confirm_password">Confirm new password</label>
            <input id="confirm_password" name="confirm_password" type="password">
        </div>
        <button type="submit">Save profile</button>
    </form>
</section>

<?php include __DIR__ . '/../includes/footer.php'; ?>
