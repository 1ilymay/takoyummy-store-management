<?php
session_start();
require_once __DIR__ . '/../../includes/config.php';
require_once __DIR__ . '/../../includes/auth.php';

require_admin();

header('Location: /takoyaki-shop-infoman/admin/flavors/index.php');
exit();