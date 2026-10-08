<?php
// Aquamarine Laundry System - Logout Handler

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

if (isLoggedIn()) {
    logAudit($pdo, 'User Logout', 'Authentication', "User {$_SESSION['username']} logged out");
}

session_unset();
session_destroy();

header("Location: index.php?page=login&success=You+have+been+logged+out.");
exit;
