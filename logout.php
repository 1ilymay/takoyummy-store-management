<?php
session_start();
foreach (['user_id', 'user_role', 'user_email', 'user_name', 'csrf_token', 'checkout_csrf', 'admin_csrf'] as $key) {
    unset($_SESSION[$key]);
}
session_regenerate_id(true);
$_SESSION['message'] = 'You have been signed out. Your cart is saved.';
$_SESSION['msg_type'] = 'success';
header('Location: /takoyaki-shop-infoman/');
exit();
