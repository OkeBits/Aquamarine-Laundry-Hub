<?php
// Aquamarine Laundry Management Service System - App Configuration

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Grant seamless automatic access across all system paths
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    $_SESSION['user_id'] = 1;
    $_SESSION['username'] = 'admin';
    $_SESSION['full_name'] = 'System Administrator';
    $_SESSION['role'] = 'admin';
}

define('APP_NAME', 'Aquamarine');
define('APP_TITLE', 'Aquamarine Laundry Management Service System');
define('DB_PATH', __DIR__ . '/../db/aquamarine.sqlite');

date_default_timezone_set('Asia/Manila');

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₱' . number_format((float)$amount, 2);
    }
}
