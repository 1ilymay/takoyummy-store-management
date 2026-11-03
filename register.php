<?php
session_start();
include 'includes/config.php';
include 'includes/auth.php';
require_once __DIR__ . '/includes/uploads.php';

ensure_default_admin();

$customerDestination = [
    'checkout' => '/takoyaki-shop-infoman/checkout.php',
    'orders' => '/takoyaki-shop-infoman/orders.php',
];
if (is_logged_in()) {
    $redirect = ($_SESSION['user_role'] ?? 'customer') === 'admin'
        ? '/takoyaki-shop-infoman/admin/flavors/index.php'
        : ($customerDestination[$_SESSION['redirect_after_login'] ?? ''] ?? '/takoyaki-shop-infoman/menu.php');
    unset($_SESSION['redirect_after_login']);
    header('Location: ' . $redirect);
    exit();
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nameInput = $_POST['full_name'] ?? '';
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $confirmInput = $_POST['confirm_password'] ?? '';
    $name = is_string($nameInput) ? trim($nameInput) : '';
    $email = is_string($emailInput) ? trim(strtolower($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';
    $confirm = is_string($confirmInput) ? $confirmInput : '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif ($name === '' || $email === '' || $password === '' || $confirm === '') {
        $error = 'Please complete all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (!$conn) {
        $error = 'Registration is unavailable while the database is offline.';
    } elseif (strlen($name) > 100 || strlen($email) > 100) {
        $error = 'Your name or email is too long.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    } else {
        $photoPath = null;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            try {
                $photoPath = save_uploaded_image($_FILES['photo'], 'uploads/profiles');
            } catch (RuntimeException $exception) {
                $error = $exception->getMessage();
            }
        }

        $stmt = $error === null ? mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1') : false;
        if ($error === null && !$stmt) {
            error_log('Registration lookup preparation failed: ' . mysqli_error($conn));
            $error = 'Unable to create your account right now.';
        }
        if ($error === null && $stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            $lookupSucceeded = mysqli_stmt_execute($stmt);
            $result = $lookupSucceeded ? mysqli_stmt_get_result($stmt) : false;
            if (!$lookupSucceeded) {
                error_log('Registration email lookup failed: ' . mysqli_stmt_error($stmt));
            }
            mysqli_stmt_close($stmt);

            if (!$lookupSucceeded) {
                delete_uploaded_image($photoPath);
                $error = 'Unable to create your account right now.';
            } elseif ($result && mysqli_num_rows($result) > 0) {
                delete_uploaded_image($photoPath);
                $error = 'An account already exists with that email.';
            } else {
                mysqli_begin_transaction($conn);
                $hashed = password_hash($password, PASSWORD_DEFAULT);
                $insertUser = mysqli_prepare($conn, 'INSERT INTO users (email, password, role, status) VALUES (?, ?, ?, ?)');
                $role = 'customer';
                $status = 'active';
                $userSaved = false;
                if ($insertUser) {
                    mysqli_stmt_bind_param($insertUser, 'ssss', $email, $hashed, $role, $status);
                    $userSaved = mysqli_stmt_execute($insertUser);
                    if (!$userSaved) {
                        error_log('Registration user insert failed: ' . mysqli_stmt_error($insertUser));
                    }
                    mysqli_stmt_close($insertUser);
                } else {
                    error_log('Registration user insert preparation failed: ' . mysqli_error($conn));
                }

                if (!$userSaved) {
                    mysqli_rollback($conn);
                    delete_uploaded_image($photoPath);
                    $error = 'Unable to create your account right now.';
                } else {
                    $userId = mysqli_insert_id($conn);
                    $insertProfile = mysqli_prepare($conn, 'INSERT INTO customer_profile (user_id, full_name, contact_number, default_address, photo_path) VALUES (?, ?, ?, ?, ?)');
                    $phoneInput = $_POST['contact_number'] ?? '';
                    $addressInput = $_POST['default_address'] ?? '';
                    $phone = is_string($phoneInput) ? trim($phoneInput) : '';
                    $address = is_string($addressInput) ? trim($addressInput) : '';
                    $profileSaved = false;
                    if ($insertProfile) {
                        mysqli_stmt_bind_param($insertProfile, 'issss', $userId, $name, $phone, $address, $photoPath);
                        $profileSaved = mysqli_stmt_execute($insertProfile);
                        if (!$profileSaved) {
                            error_log('Registration profile insert failed: ' . mysqli_stmt_error($insertProfile));
                        }
                        mysqli_stmt_close($insertProfile);
                    } else {
                        error_log('Registration profile preparation failed: ' . mysqli_error($conn));
                    }

                    if (!$profileSaved) {
                        mysqli_rollback($conn);
                        delete_uploaded_image($photoPath);
                        $error = 'Unable to save your profile details.';
                    } else {
                        mysqli_commit($conn);
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $userId;
                        $_SESSION['user_role'] = 'customer';
                        $_SESSION['user_name'] = $name;
                        $_SESSION['user_email'] = $email;
                        $_SESSION['message'] = 'Your account is ready. Welcome to Takoyaki Shop!';
                        $_SESSION['msg_type'] = 'success';
                        $destination = $customerDestination[$_SESSION['redirect_after_login'] ?? ''] ?? '/takoyaki-shop-infoman/menu.php';
                        unset($_SESSION['redirect_after_login']);
                        header('Location: ' . $destination);
                        exit();
                    }
                }
            }
        } elseif ($error === null) {
            error_log('Registration lookup preparation failed: ' . mysqli_error($conn));
            delete_uploaded_image($photoPath);
            $error = 'Unable to create your account right now.';
        } elseif ($photoPath) {
            delete_uploaded_image($photoPath);
        }
    }
}
$oldForm = [];
foreach (['full_name', 'email', 'contact_number', 'default_address'] as $fieldName) {
    $value = $_POST[$fieldName] ?? '';
    $oldForm[$fieldName] = is_string($value) ? $value : '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create Account | Takoyaki Shop</title>
    <link rel="stylesheet" href="<?= $base ?? '/takoyaki-shop-infoman' ?>/includes/style.css">
</head>
<body>
<div class="topline"></div>
<div class="landing-shell auth-shell">
    <header class="landing-header compact-auth">
        <a class="brand" href="/takoyaki-shop-infoman/" style="text-decoration:none;">
            <div class="brand-mark">
                <img src="/takoyaki-shop-infoman/images/takoyaki_logo.jpg" alt="Takoyummy Logo">
            </div>
            <div>
                <strong>Takoyummy</strong>
                <small>Fresh from our griddle</small>
            </div>
        </a>

        <div class="landing-actions">
            <a class="btn ghost" href="/takoyaki-shop-infoman/">Back to Welcome</a>
        </div>
    </header>

    <main class="auth-panel-wrap">
        <section class="auth-panel">
            <div class="auth-copy">
                <div class="eyebrow">Join us</div>
                <h1>Create your account</h1>
                <p>Sign up for fresh takoyaki favorites, quick ordering, and saved favorites.</p>
            </div>

            <form method="POST" class="auth-form" enctype="multipart/form-data" novalidate>
                <?php if ($error) { echo "<div class='alert danger'>" . htmlspecialchars($error) . '</div>'; } ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <div class="field">
                    <label for="full_name">Full name</label>
                    <input id="full_name" name="full_name" type="text" value="<?= htmlspecialchars($oldForm['full_name']) ?>" placeholder="Your name" required>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= htmlspecialchars($oldForm['email']) ?>" placeholder="you@example.com" required>
                </div>

                <div class="field">
                    <label for="contact_number">Contact number</label>
                    <input id="contact_number" name="contact_number" type="tel" value="<?= htmlspecialchars($oldForm['contact_number']) ?>" placeholder="09xx xxx xxxx">
                </div>

                <div class="field">
                    <label for="default_address">Default address</label>
                    <textarea id="default_address" name="default_address" placeholder="Your delivery address"><?= htmlspecialchars($oldForm['default_address']) ?></textarea>
                </div>

                <div class="field">
                    <label for="photo">Profile photo (JPG, PNG, WEBP; max 5 MB)</label>
                    <input id="photo" name="photo" type="file" accept=".jpg,.jpeg,.png,.webp">
                </div>

                <div class="field password-wrap">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" placeholder="Create a password" required>
                    <button type="button" class="toggle-password" data-target="password">Show</button>
                </div>

                <div class="field password-wrap">
                    <label for="confirm_password">Confirm password</label>
                    <input id="confirm_password" name="confirm_password" type="password" placeholder="Repeat your password" required>
                    <button type="button" class="toggle-password" data-target="confirm_password">Show</button>
                </div>

                <button type="submit" class="btn full">Create Account</button>
                <p class="auth-switch">Already have an account? <a href="/takoyaki-shop-infoman/login.php">Sign in</a></p>
            </form>
        </section>
    </main>
</div>

<script>
    document.querySelectorAll('.toggle-password').forEach(function(button) {
        button.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var field = document.getElementById(targetId);
            var showing = field.type === 'text';
            field.type = showing ? 'password' : 'text';
            this.textContent = showing ? 'Show' : 'Hide';
        });
    });
</script>
</body>
</html>
