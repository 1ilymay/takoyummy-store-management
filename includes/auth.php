<?php
function is_logged_in(): bool
{
    return !empty($_SESSION['user_id']) && !empty($_SESSION['user_role']);
}

function redirect_to_login(string $message = 'Please sign in to continue.', string $type = 'warning'): void
{
    $_SESSION['message'] = $message;
    $_SESSION['msg_type'] = $type;
    header('Location: /takoyaki-shop-infoman/login.php');
    exit();
}

function require_login(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!is_logged_in()) {
        redirect_to_login();
    }

    global $conn;
    if (!isset($conn) || !$conn) {
        redirect_to_login('Unable to verify your account right now. Please try again.', 'danger');
    }

    $stmt = mysqli_prepare($conn, 'SELECT role, status FROM users WHERE user_id = ? LIMIT 1');
    if (!$stmt) {
        error_log('Unable to prepare session account check: ' . mysqli_error($conn));
        redirect_to_login('Unable to verify your account right now. Please try again.', 'danger');
    }
    $userId = (int) $_SESSION['user_id'];
    mysqli_stmt_bind_param($stmt, 'i', $userId);
    if (!mysqli_stmt_execute($stmt)) {
        error_log('Unable to check session account: ' . mysqli_stmt_error($stmt));
        mysqli_stmt_close($stmt);
        redirect_to_login('Unable to verify your account right now. Please try again.', 'danger');
    }
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    if (!$user || $user['status'] !== 'active') {
        $_SESSION = [];
        session_regenerate_id(true);
        redirect_to_login('Your account is inactive or no longer available.', 'warning');
    }
    $_SESSION['user_role'] = $user['role'];
}

function require_customer(): void
{
    require_login();

    if (($_SESSION['user_role'] ?? '') !== 'customer') {
        $_SESSION['message'] = 'Customer access only.';
        $_SESSION['msg_type'] = 'danger';
        header('Location: /takoyaki-shop-infoman/admin/flavors/index.php');
        exit();
    }
}

function require_admin(): void
{
    require_login();

    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        $_SESSION['message'] = 'Admin access required.';
        $_SESSION['msg_type'] = 'danger';
        header('Location: /takoyaki-shop-infoman/menu.php');
        exit();
    }
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf_token($token): bool
{
    return is_string($token) && isset($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function ensure_default_admin(): void
{
    global $conn;

    if (!isset($conn) || !$conn) {
        return;
    }

    $email = 'admin@takoyaki.com';
    $stmt = mysqli_prepare($conn, 'SELECT user_id FROM users WHERE email = ? LIMIT 1');
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    if ($result && mysqli_num_rows($result) === 0) {
        $hash = password_hash('admin123', PASSWORD_DEFAULT);
        $insert = mysqli_prepare($conn, 'INSERT INTO users (email, password, role, status) VALUES (?, ?, ?, ?)');
        $role = 'admin';
        $status = 'active';
        mysqli_stmt_bind_param($insert, 'ssss', $email, $hash, $role, $status);
        mysqli_stmt_execute($insert);
    }
}
