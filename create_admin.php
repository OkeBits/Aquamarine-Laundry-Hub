<?php
// Aquamarine Laundry System - CLI Admin Account Generator
// Usage: C:\php\php.exe create_admin.php <username> <password> [full_name]

require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';

if (php_sapi_name() !== 'cli') {
    die("This tool must be run from the command line.\n");
}

$username = $argv[1] ?? '';
$password = $argv[2] ?? '';
$fullName = $argv[3] ?? 'Administrator';

if (empty($username) || empty($password)) {
    echo "=======================================================\n";
    echo "  Aquamarine Laundry System - Create Admin User\n";
    echo "=======================================================\n";
    echo "Usage: C:\\php\\php.exe create_admin.php <username> <password> [\"Full Name\"]\n";
    echo "Example: C:\\php\\php.exe create_admin.php superadmin mysecret123 \"Super Admin\"\n";
    exit(1);
}

$db = Database::getInstance();
$hash = password_hash($password, PASSWORD_DEFAULT);

try {
    // Add to users table
    $db->execute(
        "INSERT INTO users (username, password_hash, full_name, role, cashier_id_number) VALUES (?, ?, ?, 'admin', 'ADM-" . rand(100, 999) . "')",
        [$username, $hash, $fullName]
    );

    // Add to legacy admins table for backward compatibility
    $db->execute("INSERT OR REPLACE INTO admins (username, password) VALUES (?, ?)", [$username, $hash]);

    echo "SUCCESS: Admin user '$username' created successfully!\n";
    echo "Role: Administrator | Full Name: $fullName\n";
} catch (Exception $e) {
    echo "ERROR: Could not create admin user: " . $e->getMessage() . "\n";
}
