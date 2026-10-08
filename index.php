<?php
require_once __DIR__ . '/app/config.php';

if (isset($_SESSION['user_id'])) {
    if (($_SESSION['role'] ?? '') === 'admin') {
        header('Location: admin_dashboard.php');
    } else {
        header('Location: cashier_dashboard.php');
    }
    exit;
}

header('Location: login.php');
exit;
