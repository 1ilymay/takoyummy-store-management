<?php
session_start();
include 'includes/config.php';
include 'includes/auth.php';

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
    $emailInput = $_POST['email'] ?? '';
    $passwordInput = $_POST['password'] ?? '';
    $email = is_string($emailInput) ? trim(strtolower($emailInput)) : '';
    $password = is_string($passwordInput) ? $passwordInput : '';

    if (!verify_csrf_token($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please submit the form again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Email and password are required.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($email) > 100) {
        $error = 'Email address is too long.';
    } elseif (!$conn) {
        $error = 'Unable to sign in while the database is offline.';
    } else {
        $stmt = mysqli_prepare($conn, 'SELECT user_id, email, password, role, status FROM users WHERE email = ? LIMIT 1');
        $user = null;
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $email);
            if (mysqli_stmt_execute($stmt)) {
                $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
            } else {
                error_log('Login query failed: ' . mysqli_stmt_error($stmt));
                $error = 'Unable to sign in right now. Please try again.';
            }
            mysqli_stmt_close($stmt);
        } else {
            error_log('Login query preparation failed: ' . mysqli_error($conn));
            $error = 'Unable to sign in right now. Please try again.';
        }

        if ($error === null && (!$user || !password_verify($password, $user['password']))) {
            $error = 'Invalid email or password.';
        } elseif ($error === null && $user['status'] !== 'active') {
            $error = 'This account is disabled.';
        } elseif ($error === null) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $user['user_id'];
            $_SESSION['user_email'] = $user['email'];
            $_SESSION['user_role'] = $user['role'];

            $fullName = $user['email'];
            if (isset($conn) && $conn && $user['role'] === 'customer') {
                $profileStmt = mysqli_prepare($conn, 'SELECT full_name FROM customer_profile WHERE user_id = ? LIMIT 1');
                if ($profileStmt) {
                    mysqli_stmt_bind_param($profileStmt, 'i', $_SESSION['user_id']);
                    mysqli_stmt_execute($profileStmt);
                    $profileRes = mysqli_stmt_get_result($profileStmt);
                    if ($profileRow = mysqli_fetch_assoc($profileRes)) {
                        $fullName = $profileRow['full_name'] ?: $user['email'];
                    }
                    mysqli_stmt_close($profileStmt);
                }
            }
            $_SESSION['user_name'] = $fullName;

            $redirectUrl = ($user['role'] === 'admin')
                ? '/takoyaki-shop-infoman/admin/flavors/index.php'
                : ($customerDestination[$_SESSION['redirect_after_login'] ?? ''] ?? '/takoyaki-shop-infoman/menu.php');
            unset($_SESSION['redirect_after_login']);

            $_SESSION['message'] = 'Welcome back!';
            $_SESSION['msg_type'] = 'success';
            header('Location: ' . $redirectUrl);
            exit();
        }
    }
}
$oldEmail = is_string($_POST['email'] ?? null) ? $_POST['email'] : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign In | Takoyaki Shop</title>
    <link rel="stylesheet" href="/takoyaki-shop-infoman/includes/style.css">
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
                <div class="eyebrow">Welcome back</div>
                <h1>Sign in to your account</h1>
                <p>Enjoy fresh takoyaki, quick checkout, and personalized order tracking.</p>
            </div>

            <form method="POST" class="auth-form" novalidate>
                <?php if ($error) { echo "<div class='alert danger'>" . htmlspecialchars($error) . '</div>'; } ?>
                <?php include 'includes/alert.php'; ?>
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrf_token()) ?>">

                <div class="field">
                    <label for="email">Email</label>
                    <input id="email" name="email" type="email" value="<?= htmlspecialchars($oldEmail) ?>" placeholder="you@example.com" required>
                </div>

                <div class="field password-wrap">
                    <label for="password">Password</label>
                    <input id="password" name="password" type="password" placeholder="Enter your password" required>
                    <button type="button" class="toggle-password" data-target="password">Show</button>
                </div>

                <button type="submit" class="btn full">Sign In</button>
                <p class="auth-switch">Need an account? <a href="/takoyaki-shop-infoman/register.php">Create one</a></p>
            </form>
        </section>
    </main>
</div>

<script>
    document.querySelectorAll('.toggle-password').forEach(function(button) {
        button.addEventListener('click', function() {
            var target = document.getElementById(this.getAttribute('data-target'));
            var showing = target.type === 'text';
            target.type = showing ? 'password' : 'text';
            this.textContent = showing ? 'Show' : 'Hide';
        });
    });
</script>
</body>
</html>
