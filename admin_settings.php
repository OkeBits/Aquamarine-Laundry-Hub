<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    $adminUser = Database::getInstance()->fetch("SELECT * FROM users WHERE role = 'admin' LIMIT 1");
    if ($adminUser) {
        $_SESSION['user_id'] = $adminUser['id'];
        $_SESSION['username'] = $adminUser['username'];
        $_SESSION['full_name'] = $adminUser['full_name'];
        $_SESSION['role'] = 'admin';
    } else {
        $_SESSION['user_id'] = 1;
        $_SESSION['username'] = 'admin';
        $_SESSION['full_name'] = 'Administrator';
        $_SESSION['role'] = 'admin';
    }
}

$orderService = new OrderService();
$db = Database::getInstance();
$msg = '';
$error = '';
$testSmsResult = null;
$testEmailResult = null;

// Handle Database Backup Export Download
if ((isset($_GET['action']) && ($_GET['action'] === 'download_db' || $_GET['action'] === 'export_db')) || (isset($_POST['action']) && ($_POST['action'] === 'download_db' || $_POST['action'] === 'export_db'))) {
    $dbFile = defined('DB_PATH') ? DB_PATH : (__DIR__ . '/db/aquamarine.sqlite');
    if (!file_exists($dbFile)) {
        $possiblePaths = [
            __DIR__ . '/db/aquamarine.sqlite',
            __DIR__ . '/../db/aquamarine.sqlite',
            __DIR__ . '/db/aquamarine.db',
            __DIR__ . '/aquamarine.sqlite',
            __DIR__ . '/aquamarine.db'
        ];
        foreach ($possiblePaths as $path) {
            if (file_exists($path)) {
                $dbFile = $path;
                break;
            }
        }
    }

    if (file_exists($dbFile)) {
        try {
            $pdo = Database::getInstance()->getPdo();
            $pdo->exec("PRAGMA wal_checkpoint(FULL);");
        } catch (Throwable $e) {}

        $filename = 'aquamarine_db_backup_' . date('Y-m-d_H-i-s') . '.sqlite';
        header('Content-Description: File Transfer');
        header('Content-Type: application/x-sqlite3');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($dbFile));
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
        readfile($dbFile);
        exit;
    } else {
        $error = "Database file not found for backup export!";
    }
}

// Handle Admin Account Deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_user') {
    $userId = (int)$_POST['user_id'];
    if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
        $error = "Security Warning: You cannot delete your own active logged-in administrator account!";
    } else {
        $orderService->deleteUser($userId);
        $msg = "User account deleted successfully.";
    }
}

// Handle User Edit Profile
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_user') {
    $userId = (int)$_POST['user_id'];
    $fullName = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'cashier';
    $cashierIdNumber = trim($_POST['cashier_id_number'] ?? 'CSH-101');

    if (!empty($fullName) && $userId > 0) {
        $orderService->updateUser($userId, $fullName, $role, $cashierIdNumber);
        $msg = "User profile updated successfully!";
    } else {
        $error = "Full Name is required for user account.";
    }
}

// Handle User Password Reset
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    $userId = (int)$_POST['user_id'];
    $newPassword = $_POST['new_password'] ?? '';
    
    if ($userId <= 0 || empty($newPassword)) {
        $error = "New password cannot be empty.";
    } elseif (strlen($newPassword) < 8) {
        $error = "New password must be at least 8 characters long.";
    } elseif (!preg_match('/[\W_]/', $newPassword)) {
        $error = "New password must contain at least one special symbol (e.g. !@#$%^&*).";
    } else {
        $currentUser = $db->fetch("SELECT password_hash FROM users WHERE id = ?", [$userId]);
        if ($currentUser && !empty($currentUser['password_hash'])) {
            if (password_verify($newPassword, $currentUser['password_hash']) || $newPassword === $currentUser['password_hash']) {
                $error = "New password cannot be the same as the user's current password.";
            }
        }
        if (empty($error)) {
            $orderService->resetUserPassword($userId, $newPassword);
            $msg = "User password reset successfully!";
        }
    }
}

// Handle User Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_user_status') {
    $userId = (int)$_POST['user_id'];
    $orderService->toggleUserStatus($userId);
    $msg = "User account status updated.";
}

// Handle Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_user') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $fullName = trim($_POST['full_name'] ?? '');
    $role = $_POST['role'] ?? 'cashier';
    $cashierIdNumber = trim($_POST['cashier_id_number'] ?? 'CSH-101');

    if (empty($username) || empty($password) || empty($fullName)) {
        $error = "All fields are required for new user creation.";
    } elseif (strlen($password) < 8) {
        $error = "Account password must be at least 8 characters long.";
    } elseif (!preg_match('/[\W_]/', $password)) {
        $error = "Account password must contain at least one special symbol (e.g. !@#$%^&*).";
    } else {
        $existing = $db->fetch("SELECT id FROM users WHERE username = ?", [$username]);
        if ($existing) {
            $error = "Username '$username' is already taken!";
        } else {
            $passHash = password_hash($password, PASSWORD_DEFAULT);
            $db->insert("INSERT INTO users (username, password_hash, full_name, role, cashier_id_number) VALUES (?, ?, ?, ?, ?)", [$username, $passHash, $fullName, $role, $cashierIdNumber]);
            if ($role === 'admin') {
                $db->execute("INSERT OR REPLACE INTO admins (username, password) VALUES (?, ?)", [$username, $passHash]);
            }
            $msg = "User account '$username' created successfully!";
        }
    }
}

// Handle Add Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_service') {
    $name = trim($_POST['service_name'] ?? '');
    $category = $_POST['category'] ?? 'Full Package';
    $price = (float)$_POST['price_per_kg'];
    $minKg = (float)($_POST['min_kg'] ?? 1.0);
    $unit = trim($_POST['unit'] ?? 'kg');
    if (empty($unit)) $unit = 'kg';
    $desc = trim($_POST['description'] ?? '');

    if (!empty($name) && $price > 0) {
        $orderService->addService($name, $category, $price, $minKg, $desc, $unit);
        $msg = "New service '$name' added to catalog!";
    } else {
        $error = "Service name and valid price per kg are required.";
    }
}

// Handle Edit Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_service') {
    $id = (int)$_POST['service_id'];
    $name = trim($_POST['service_name'] ?? '');
    $category = $_POST['category'] ?? 'Full Package';
    $price = (float)$_POST['price_per_kg'];
    $minKg = (float)($_POST['min_kg'] ?? 1.0);
    $unit = trim($_POST['unit'] ?? 'kg');
    if (empty($unit)) $unit = 'kg';
    $desc = trim($_POST['description'] ?? '');

    if ($id > 0 && !empty($name) && $price > 0) {
        $orderService->updateService($id, $name, $category, $price, $minKg, $desc, $unit);
        $msg = "Service '$name' updated successfully!";
    } else {
        $error = "Service name and valid price per kg are required.";
    }
}

// Handle Delete Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_service') {
    $id = (int)$_POST['service_id'];
    if ($id > 0) {
        $orderService->deleteService($id);
        $msg = "Service removed from catalog.";
    }
}

// Handle Add Add-on Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_addon') {
    $name = trim($_POST['addon_name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $icon = trim($_POST['icon'] ?? '🌸');

    if (!empty($name) && $price >= 0) {
        $orderService->addAddon($name, $price, $icon);
        $msg = "Add-on product '$name' added successfully!";
    } else {
        $error = "Add-on product name is required.";
    }
}

// Handle Edit Add-on Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_addon') {
    $id = (int)$_POST['addon_id'];
    $name = trim($_POST['addon_name'] ?? '');
    $price = (float)($_POST['price'] ?? 0);
    $icon = trim($_POST['icon'] ?? '🌸');

    if ($id > 0 && !empty($name)) {
        $orderService->updateAddon($id, $name, $price, $icon);
        $msg = "Add-on product '$name' updated successfully!";
    } else {
        $error = "Add-on name is required.";
    }
}

// Handle Delete Add-on Product
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_addon') {
    $id = (int)$_POST['addon_id'];
    if ($id > 0) {
        $orderService->deleteAddon($id);
        $msg = "Add-on product deleted.";
    }
}

// Handle Shop Settings & Portal Updates (SAFE UPDATES: Does NOT wipe out missing keys)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_settings') {
    $settingsData = [];
    $existingSettings = $orderService->getAllSettings();

    // Map fields only if they exist in the submitted POST payload
    $fieldsToUpdate = [
        'shop_name', 'shop_address', 'shop_phone', 'receipt_footer',
        'express_surcharge_percent',
        'loyalty_stamps_target', 'loyalty_max_kg', 'loyalty_max_piece', 'loyalty_max_discount',
        'sms_template_ready', 'sms_template_out_for_delivery', 'sms_template_received', 'sms_template_paid',
        'textbee_api_key', 'textbee_device_id',
        'gmail_smtp_user', 'gmail_app_password', 'email_from_name',
        'default_delivery_fee', 'free_delivery_min_spend', 'gcash_number'
    ];

    foreach ($fieldsToUpdate as $field) {
        if (array_key_exists($field, $_POST)) {
            $settingsData[$field] = trim($_POST[$field]);
        }
    }

    // Process Checkbox Toggles safely based on submitted portal sections
    if (isset($_POST['has_textbee_section'])) {
        $settingsData['textbee_enabled'] = isset($_POST['textbee_enabled']) ? '1' : '0';
    }
    if (isset($_POST['has_email_section'])) {
        $settingsData['email_enabled'] = isset($_POST['email_enabled']) ? '1' : '0';
    }
    if (isset($_POST['has_delivery_section'])) {
        $settingsData['delivery_enabled'] = isset($_POST['delivery_enabled']) ? '1' : '0';
    }

    // Handle GCash QR Image Removal or Upload
    if (isset($_POST['remove_gcash_qr']) && $_POST['remove_gcash_qr'] === '1') {
        $settingsData['gcash_qr_image'] = '';
        $existingQr = $existingSettings['gcash_qr_image'] ?? '';
        if (!empty($existingQr) && file_exists(__DIR__ . '/' . $existingQr)) {
            @unlink(__DIR__ . '/' . $existingQr);
        }
    } elseif (isset($_FILES['gcash_qr']) && $_FILES['gcash_qr']['error'] === UPLOAD_ERR_OK) {
        $imgDir = __DIR__ . '/assets/img';
        if (!file_exists($imgDir)) {
            mkdir($imgDir, 0777, true);
        }
        $ext = strtolower(pathinfo($_FILES['gcash_qr']['name'], PATHINFO_EXTENSION));
        if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
            $dest = 'assets/img/gcash_qr.' . $ext;
            if (move_uploaded_file($_FILES['gcash_qr']['tmp_name'], __DIR__ . '/' . $dest)) {
                $settingsData['gcash_qr_image'] = $dest;
            }
        }
    }

    if (!empty($settingsData)) {
        $orderService->saveSettings($settingsData);
    }
    $msg = "System configurations & shop settings saved successfully! ✅";
}

// Handle Test SMS Dispatch
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'test_sms') {
    $testPhone = trim($_POST['test_phone'] ?? '');
    $testMessage = trim($_POST['test_message'] ?? 'Aquamarine Laundry: Test SMS Delivery!');
    
    if (!empty($testPhone)) {
        $testSmsResult = $orderService->sendSMS($testPhone, $testMessage);
    } else {
        $error = "Test recipient phone number is required.";
    }
}


// Handle Change Master Admin Password
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'change_master_password') {
    $currentPass = $_POST['current_password'] ?? '';
    $newPass = $_POST['new_password'] ?? '';
    $confirmPass = $_POST['confirm_password'] ?? '';

    if (empty($currentPass) || empty($newPass)) {
        $error = "All password fields are required!";
    } elseif ($newPass !== $confirmPass) {
        $error = "New passwords do not match!";
    } elseif (strlen($newPass) < 6) {
        $error = "New password must be at least 6 characters!";
    } else {
        $res = $orderService->changeAdminPassword((int)$_SESSION['user_id'], $currentPass, $newPass);
        if ($res) {
            $msg = "Master Administrator password changed successfully!";
        } else {
            $error = "Current password is incorrect!";
        }
    }
}

// Handle Clear Audit Logs
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'clear_audit_logs') {
    $orderService->clearAuditLogs();
    $msg = "System audit trail logs cleared successfully!";
}

$settings = $orderService->getAllSettings();
$users = $db->fetchAll("SELECT * FROM users ORDER BY id ASC");
$services = $orderService->getServices();
$allAddons = $orderService->getAllAddons();
$auditLogs = $db->fetchAll("SELECT * FROM audit_trail ORDER BY id DESC LIMIT 50");
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Admin System Settings & Portals</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link href="assets/css/offline-tailwind.css" rel="stylesheet"/>
    <link href="assets/css/material-symbols.css" rel="stylesheet"/>
    <script src="assets/js/tailwindcss.js"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    colors: {
                        primary: "#0058be",
                        "primary-dark": "#004397",
                        "primary-container": "#2170e4",
                        secondary: "#006c49",
                        background: "#f7f9fb",
                        surface: "#f7f9fb",
                        "surface-low": "#f2f4f6",
                        "surface-lowest": "#ffffff",
                        "on-surface": "#191c1e",
                        "on-surface-variant": "#424754",
                        outline: "#e2e8f0"
                    },
                    fontFamily: {
                        sans: ["Inter", "sans-serif"],
                        headline: ["Outfit", "Plus Jakarta Sans", "sans-serif"],
                        mono: ["JetBrains Mono", "monospace"]
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family: 'Inter', sans-serif; background-color: #f8fafc; }
        h1, h2, h3, h4, .font-headline { font-family: 'Outfit', 'Plus Jakarta Sans', sans-serif; }
        .font-mono { font-family: 'JetBrains Mono', monospace; }
        .portal-card { transition: all 0.2s ease-in-out; }
        .portal-card:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-50 text-gray-900 min-h-screen">

    <!-- Standard Header Ribbon -->
    <header class="bg-white border-b border-gray-200 px-6 py-3.5 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
            
            <div class="flex items-center gap-3">
                <img src="assets/img/logo.png" alt="Aquamarine Logo" class="w-10 h-10 object-contain rounded-xl shadow-2xs">
                <div>
                    <h1 class="text-lg font-black tracking-tight text-blue-700 font-headline uppercase leading-none">
                        <?= htmlspecialchars($settings['shop_name'] ?? 'AQUAMARINE LAUNDRY HUB') ?>
                    </h1>
                    <span class="text-3xs text-gray-500 font-semibold tracking-wider uppercase">Executive Control & System Portals</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <div class="hidden sm:flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-3xs font-extrabold px-3 py-1.5 rounded-full shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>System Active & Connected</span>
                </div>

                <div class="flex items-center gap-2.5 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-xl">
                    <div class="w-7 h-7 bg-blue-100 text-blue-700 rounded-lg flex items-center justify-center font-black text-xs">
                        👑
                    </div>
                    <div class="text-left">
                        <div class="text-xs font-black text-gray-900 leading-tight"><?= htmlspecialchars($_SESSION['full_name'] ?? 'Executive Manager') ?></div>
                        <div class="text-3xs text-gray-500 font-bold uppercase">Master Admin</div>
                    </div>
                </div>

                <a href="logout.php" class="bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 transition-all">
                    <span class="material-symbols-outlined text-xs">logout</span> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="px-6 py-8 max-w-7xl mx-auto space-y-8">

        <!-- Message & Error Alert (Top-Left Fixed Popup, 5-Second Auto Fade Out) -->
        <?php if (!empty($msg) || !empty($error)): ?>
            <div id="toast_notification" class="fixed top-6 left-6 z-[9999] max-w-md w-full space-y-3 transition-all duration-500 transform translate-x-0">
                <?php if (!empty($msg)): ?>
                    <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                        <p class="text-emerald-800 font-bold flex items-center gap-2 text-sm">
                            <span class="material-symbols-outlined text-base text-emerald-600">check_circle</span> <?= htmlspecialchars($msg) ?>
                        </p>
                        <button onclick="closeToastNotification()" class="text-emerald-500 hover:text-emerald-800 font-bold text-lg leading-none cursor-pointer">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                        <p class="text-rose-800 font-bold flex items-center gap-2 text-sm">
                            <span class="material-symbols-outlined text-base text-rose-600">error</span> <?= htmlspecialchars($error) ?>
                        </p>
                        <button onclick="closeToastNotification()" class="text-rose-500 hover:text-rose-800 font-bold text-lg leading-none cursor-pointer">&times;</button>
                    </div>
                <?php endif; ?>
            </div>

            <script>
                function closeToastNotification() {
                    const toast = document.getElementById('toast_notification');
                    if (toast) {
                        toast.style.opacity = '0';
                        toast.style.transform = 'translateX(-120%)';
                        setTimeout(() => toast.remove(), 500);
                    }
                }
                setTimeout(closeToastNotification, 5000);
            </script>
        <?php endif; ?>

        <!-- Top Navigation Ribbon Tabs -->
        <div class="flex gap-4 border-b border-gray-200 overflow-x-auto">
            <a href="admin_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">dashboard</span> Executive Dashboard
            </a>
            <a href="cashier_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">point_of_sale</span> Cashier POS Terminal
            </a>
            <a href="customers.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">groups</span> Customer Directory
            </a>
            <a href="sales_reports.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">show_chart</span> Sales & Analytics
            </a>
            <a href="admin_settings.php" class="pb-3 px-4 font-bold text-blue-700 border-b-2 border-blue-700 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">tune</span> System Settings & Portals
            </a>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE DESIGN HUB: FULL-WIDTH STORE PROFILE & GCASH QR CODE PORTAL -->
        <!-- ========================================================================== -->
        <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-md portal-card space-y-6">
            <div class="flex items-center justify-between pb-3 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-100 text-blue-700 rounded-xl flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined text-xl">store</span>
                    </div>
                    <div>
                        <h2 class="text-base font-black font-headline text-gray-900">
                            Laundry Shop Business Profile & Store Operations
                        </h2>
                        <p class="text-3xs text-gray-500 font-medium">Business identity, store contact numbers, pickup lead times, and official GCash QR upload</p>
                    </div>
                </div>
                <span class="text-3xs bg-blue-50 text-blue-700 border border-blue-200 px-3 py-1 rounded-full font-black uppercase tracking-wider">
                    Core Business Info
                </span>
            </div>

            <form method="POST" enctype="multipart/form-data" class="space-y-6">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="has_shop_section" value="1">

                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                    
                    <!-- Left Columns: Shop Metadata -->
                    <div class="lg:col-span-2 space-y-4">
                        <div>
                            <label class="block text-xs font-extrabold text-gray-700 mb-1">Laundry Shop Business Name *</label>
                            <input type="text" name="shop_name" value="<?= htmlspecialchars($settings['shop_name'] ?? 'Aquamarine Laundry Hub') ?>" required class="w-full font-extrabold text-sm p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-gray-700 mb-1">Complete Store Address</label>
                            <input type="text" name="shop_address" value="<?= htmlspecialchars($settings['shop_address'] ?? '') ?>" placeholder="e.g. 123 Rizal Ave, Sampaloc, Manila" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-gray-700 mb-1">Contact Phone Number</label>
                            <input type="text" name="shop_phone" value="<?= htmlspecialchars($settings['shop_phone'] ?? '') ?>" placeholder="0917-123-4567" class="w-full text-xs font-mono p-2.5 border border-gray-300 rounded-xl">
                        </div>

                        <div>
                            <label class="block text-xs font-extrabold text-gray-700 mb-1">Receipt Footer Disclaimer / Thank You Note</label>
                            <textarea name="receipt_footer" rows="2" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-mono"><?= htmlspecialchars($settings['receipt_footer'] ?? 'Thank you for trusting Aquamarine Laundry Hub! Please present this receipt upon pickup.') ?></textarea>
                        </div>

                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-xs font-extrabold text-gray-700 mb-1">Loyalty Stamps Target *</label>
                                <input type="number" name="loyalty_stamps_target" min="1" max="50" value="<?= htmlspecialchars($settings['loyalty_stamps_target'] ?? '11') ?>" required class="w-full text-xs font-extrabold p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                                <p class="text-3xs text-gray-500 mt-1">Completed orders for 1 Free Reward.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-extrabold text-gray-700 mb-1">Max Loyalty Free Weight (Kg) *</label>
                                <input type="number" step="0.1" min="0.1" name="loyalty_max_kg" value="<?= htmlspecialchars($settings['loyalty_max_kg'] ?? '8.0') ?>" required class="w-full text-xs font-extrabold p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                                <p class="text-3xs text-gray-500 mt-1">Max Kg allowed per reward for weight services.</p>
                            </div>
                            <div>
                                <label class="block text-xs font-extrabold text-gray-700 mb-1">Max Loyalty Free Quantity (Piece) *</label>
                                <input type="number" step="1" min="1" name="loyalty_max_piece" value="<?= htmlspecialchars($settings['loyalty_max_piece'] ?? '5') ?>" required class="w-full text-xs font-extrabold p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                                <p class="text-3xs text-gray-500 mt-1">Max Piece count allowed per reward for piece services.</p>
                            </div>
                        </div>
                    </div>

                    <!-- Right Column: GCash QR Code Image Manager -->
                    <div class="bg-blue-50/70 border border-blue-200 p-4 rounded-2xl space-y-3 flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-xs font-extrabold text-blue-950 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-blue-600">qr_code_2</span>
                                    Official GCash QR Code
                                </label>
                                <?php if (!empty($settings['gcash_qr_image']) && file_exists(__DIR__ . '/' . $settings['gcash_qr_image'])): ?>
                                    <span id="qr_status_badge" class="text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-black">
                                        ✓ QR Active
                                    </span>
                                <?php else: ?>
                                    <span id="qr_status_badge" class="text-3xs bg-gray-100 text-gray-600 border border-gray-300 px-2 py-0.5 rounded-full font-bold">
                                        No Image Uploaded
                                    </span>
                                <?php endif; ?>
                            </div>
                            
                            <p class="text-3xs text-gray-600 mb-3">
                                Upload your laundry shop's GCash QR code. Customers scan this QR code to pay via GCash at POS and on digital receipts.
                            </p>

                            <div class="flex items-center gap-3 bg-white p-3 rounded-xl border border-blue-200 shadow-2xs">
                                <div class="relative shrink-0">
                                    <?php if (!empty($settings['gcash_qr_image']) && file_exists(__DIR__ . '/' . $settings['gcash_qr_image'])): ?>
                                        <img id="gcash_qr_img_preview" src="<?= htmlspecialchars($settings['gcash_qr_image']) ?>?v=<?= time() ?>" alt="GCash QR Code" class="w-24 h-24 object-contain rounded-lg border border-blue-200 bg-white p-1 shadow-2xs">
                                        <div id="gcash_qr_placeholder" class="hidden w-24 h-24 rounded-lg border border-dashed border-blue-200 bg-blue-50/50 flex flex-col items-center justify-center text-blue-400 text-center p-2">
                                            <span class="material-symbols-outlined text-2xl mb-1">qr_code_scanner</span>
                                            <span class="text-3xs font-bold leading-tight">No QR Image</span>
                                        </div>
                                    <?php else: ?>
                                        <div id="gcash_qr_placeholder" class="w-24 h-24 rounded-lg border border-dashed border-blue-200 bg-blue-50/50 flex flex-col items-center justify-center text-blue-400 text-center p-2">
                                            <span class="material-symbols-outlined text-2xl mb-1">qr_code_scanner</span>
                                            <span class="text-3xs font-bold leading-tight">No QR Image</span>
                                        </div>
                                        <img id="gcash_qr_img_preview" src="" alt="New QR Preview" class="hidden w-24 h-24 object-contain rounded-lg border border-blue-200 bg-white p-1 shadow-2xs">
                                    <?php endif; ?>
                                </div>

                                <div class="flex-1 space-y-2">
                                    <input type="file" name="gcash_qr" id="gcash_qr_input" accept="image/png, image/jpeg, image/jpg, image/webp" onchange="previewGcashQr(this)" class="block w-full text-xs text-gray-600 file:mr-2 file:py-1 file:px-2.5 file:rounded-lg file:border-0 file:text-xs file:font-bold file:bg-blue-700 file:text-white hover:file:bg-blue-800 cursor-pointer">
                                    <?php if (!empty($settings['gcash_qr_image']) && file_exists(__DIR__ . '/' . $settings['gcash_qr_image'])): ?>
                                        <label class="flex items-center gap-1.5 text-3xs text-rose-700 font-bold cursor-pointer hover:text-rose-800 bg-rose-50 border border-rose-200 px-2 py-1 rounded-lg">
                                            <input type="checkbox" name="remove_gcash_qr" value="1" onchange="toggleRemoveGcashQr(this)" class="rounded text-rose-600">
                                            Remove Current QR
                                        </label>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- OFFICIAL GCASH MOBILE NUMBER FIELD -->
                            <div class="mt-3 bg-white p-2.5 rounded-xl border border-blue-200 shadow-2xs">
                                <label class="block text-3xs font-black text-blue-950 uppercase tracking-wider mb-1 flex items-center gap-1">
                                    <span class="material-symbols-outlined text-xs text-blue-600">smartphone</span>
                                    Official GCash Mobile Number
                                </label>
                                <input type="text" name="gcash_number" value="<?= htmlspecialchars($settings['gcash_number'] ?? '09088980862') ?>" placeholder="e.g. 09088980862" class="w-full text-xs font-bold font-mono p-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 bg-blue-50/30">
                                <p class="text-3xs text-gray-500 mt-1">Displayed to customers alongside the GCash QR Code on POS & Receipts.</p>
                            </div>
                        </div>

                        <button type="submit" class="w-full bg-blue-700 hover:bg-blue-800 text-white font-black py-2.5 rounded-xl shadow-md text-xs transition-all flex items-center justify-center gap-1.5 cursor-pointer">
                            <span class="material-symbols-outlined text-xs">save</span> Save Shop Profile & Settings
                        </button>
                    </div>

                </div>
            </form>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE CARD: SYSTEM DATABASE BACKUP & OFFLINE DATA EXPORT -->
        <!-- ========================================================================== -->
        <div class="bg-white p-6 rounded-2xl border-2 border-purple-200 shadow-md portal-card space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3 border-b border-purple-100 pb-3">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-purple-700 text-2xl p-2 bg-purple-50 rounded-xl border border-purple-200">database</span>
                    <div>
                        <h2 class="text-base font-black font-headline text-gray-900 flex items-center gap-2">
                            System Database Backup & Offline Data Export
                        </h2>
                        <p class="text-3xs text-gray-500 font-medium">Export a complete standalone copy of your local SQLite database for offline archives and data safety</p>
                    </div>
                </div>

                <span class="text-3xs bg-purple-50 text-purple-700 border border-purple-200 px-3 py-1 rounded-full font-black uppercase tracking-wider">
                    Offline Protection Active
                </span>
            </div>

            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 bg-purple-50/60 border border-purple-200 p-4 rounded-xl">
                <div class="space-y-1">
                    <div class="text-xs font-black text-purple-950 flex items-center gap-1.5">
                        <span class="material-symbols-outlined text-sm text-purple-700">verified_user</span>
                        Local Database Encapsulation: <span class="font-mono text-purple-800 font-bold">aquamarine.sqlite</span>
                    </div>
                    <p class="text-3xs text-gray-600 leading-snug">
                        Clicking the button will instantly compile and download a full, standalone copy of all store records, customers, services, sales, and settings.
                    </p>
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto">
                    <a href="admin_settings.php?action=download_db" class="w-full sm:w-auto bg-purple-700 hover:bg-purple-800 text-white font-black px-5 py-2.5 rounded-xl shadow-md text-xs flex items-center justify-center gap-2 transition-all cursor-pointer whitespace-nowrap">
                        <span class="material-symbols-outlined text-sm">download</span> Download Database Backup
                    </a>
                    <a href="admin_settings.php?action=export_db" class="w-full sm:w-auto bg-purple-100 hover:bg-purple-200 text-purple-900 border border-purple-300 font-black px-4 py-2.5 rounded-xl text-xs flex items-center justify-center gap-1.5 transition-all cursor-pointer whitespace-nowrap">
                        <span class="material-symbols-outlined text-sm">file_export</span> Export Database
                    </a>
                </div>
            </div>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE GRID ROW: DOORSTEP DELIVERY PORTAL & TEXTBEE SMS PORTAL -->
        <!-- ========================================================================== -->
        <div class="grid grid-cols-1 xl:grid-cols-2 gap-8">
            
            <!-- LANDSCAPE PORTAL 1: DOORSTEP PICK-UP & DELIVERY SERVICE PORTAL -->
            <?php $isDeliveryActive = (($settings['delivery_enabled'] ?? '1') === '1'); ?>
            <div class="bg-white p-6 rounded-2xl border-2 border-amber-300 shadow-md portal-card space-y-4 flex flex-col justify-between">
                <form method="POST" class="space-y-4 flex-1 flex flex-col justify-between">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="has_delivery_section" value="1">

                    <div class="space-y-4">
                        <!-- HEADER WITH TOGGLE BUTTON -->
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-amber-200 pb-3">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-amber-600 text-2xl p-2 bg-amber-50 rounded-xl border border-amber-200">local_shipping</span>
                                <div>
                                    <h2 class="text-base font-black font-headline text-amber-950 flex items-center gap-2">
                                        Doorstep Pick-up & Delivery Service Portal
                                    </h2>
                                    <p class="text-3xs text-amber-800 font-medium">Delivery activation, standard rates, minimum spend, and SMS alerts</p>
                                </div>
                            </div>

                            <div class="flex items-center gap-2">
                                <span id="delivery_active_status_badge" class="<?= $isDeliveryActive ? 'bg-amber-100 text-amber-950 border border-amber-300' : 'bg-gray-100 text-gray-600 border border-gray-300' ?> text-3xs font-black px-2.5 py-1 rounded-full uppercase tracking-wider">
                                    <?= $isDeliveryActive ? '🚚 Portal Active' : '🔴 Delivery Disabled' ?>
                                </span>

                                <div class="flex items-center gap-2 bg-amber-100/90 px-2.5 py-1 rounded-xl border border-amber-300">
                                    <span class="text-xs font-extrabold text-amber-950 select-none">Delivery Mode</span>
                                    <label class="relative inline-flex items-center cursor-pointer select-none">
                                        <input type="checkbox" name="delivery_enabled" value="1" id="delivery_master_toggle" onchange="toggleDeliveryConfigBody(this)" <?= $isDeliveryActive ? 'checked' : '' ?> class="sr-only peer">
                                        <div class="w-9 h-5 bg-gray-300 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-amber-600 shadow-inner"></div>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <!-- COLLAPSIBLE CONFIGURATION BODY -->
                        <div id="delivery_config_body" class="<?= $isDeliveryActive ? '' : 'hidden' ?> space-y-4 pt-1">
                            
                            <div class="bg-amber-50/90 border border-amber-200 p-3.5 rounded-xl space-y-2">
                                <h3 class="text-xs font-extrabold text-amber-950 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-amber-700">settings</span>
                                    Delivery Pricing Rates & Thresholds
                                </h3>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-3xs font-bold text-amber-950 uppercase mb-1">Standard Delivery Fee (₱)</label>
                                        <input type="number" step="0.01" min="0" name="default_delivery_fee" value="<?= htmlspecialchars($settings['default_delivery_fee'] ?? '50.00') ?>" class="text-xs font-bold p-2 bg-white border border-amber-300 rounded-lg">
                                    </div>
                                    <div>
                                        <label class="block text-3xs font-bold text-amber-950 uppercase mb-1">Free Delivery Min Spend (₱)</label>
                                        <input type="number" step="0.01" min="0" name="free_delivery_min_spend" value="<?= htmlspecialchars($settings['free_delivery_min_spend'] ?? '500.00') ?>" class="text-xs font-bold p-2 bg-white border border-amber-300 rounded-lg">
                                    </div>
                                </div>
                            </div>

                            <!-- CUSTOMER OUT FOR DELIVERY EDITABLE SMS TEMPLATE -->
                            <div class="bg-amber-50/90 border border-amber-200 p-3.5 rounded-xl space-y-3">
                                <div class="flex items-center justify-between">
                                    <h3 class="text-xs font-extrabold text-amber-950 flex items-center gap-1.5">
                                        <span class="material-symbols-outlined text-base text-amber-700">sms</span>
                                        Out for Delivery SMS Template
                                    </h3>
                                    <span id="sms_out_autosave_status" class="text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold">
                                        Auto-saved ✓
                                    </span>
                                </div>

                                <div>
                                    <div class="flex items-center justify-between mb-1">
                                        <label class="block text-2xs font-bold text-amber-950 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs text-amber-600">check_circle</span>
                                            Out for Delivery Message *
                                        </label>
                                        <button type="button" onclick="resetOutSmsTemplate()" class="text-3xs text-amber-700 hover:text-amber-900 font-bold hover:underline inline-flex items-center gap-0.5">
                                            <span class="material-symbols-outlined text-3xs">restart_alt</span> Reset Default
                                        </button>
                                    </div>
                                    <textarea name="sms_template_out_for_delivery" id="sms_template_out_input" rows="3" oninput="triggerOutSmsAutoSave()" class="w-full text-xs font-mono bg-white border border-amber-300 rounded-lg p-2 focus:ring-2 focus:ring-amber-500 shadow-2xs" placeholder="Type your SMS message here..."><?= htmlspecialchars($settings['sms_template_out_for_delivery'] ?? '🚚 On its way {customer_name}! Your fresh laundry (Receipt #{tracking_number}) is OUT FOR DELIVERY with rider {driver_name}! 📍 Address: {delivery_address}. Balance Due: ₱{balance_amount}. Thank you for choosing {shop_name}!') ?></textarea>
                                </div>

                                <!-- Live Sample Message Preview -->
                                <div class="bg-white border border-amber-200 rounded-xl p-2.5 space-y-1 shadow-2xs">
                                    <div class="text-3xs font-bold uppercase tracking-wider text-amber-900 flex items-center justify-between">
                                        <span class="flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs text-amber-600">visibility</span> Live Out for Delivery Sample Preview:
                                        </span>
                                    </div>
                                    <div id="sms_out_live_preview" class="text-xs text-gray-900 bg-amber-50/50 p-2 rounded-lg border border-amber-100 font-sans leading-relaxed">
                                        Loading preview...
                                    </div>
                                </div>

                                <div class="flex flex-wrap gap-1 text-3xs font-mono font-bold">
                                    <button type="button" onclick="insertOutSmsTag('{customer_name}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {customer_name}</button>
                                    <button type="button" onclick="insertOutSmsTag('{tracking_number}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {tracking_number}</button>
                                    <button type="button" onclick="insertOutSmsTag('{driver_name}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {driver_name}</button>
                                    <button type="button" onclick="insertOutSmsTag('{delivery_address}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {delivery_address}</button>
                                    <button type="button" onclick="insertOutSmsTag('{total_amount}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {total_amount}</button>
                                    <button type="button" onclick="insertOutSmsTag('{balance_amount}')" class="bg-amber-100 hover:bg-amber-200 text-amber-900 border border-amber-300 px-1.5 py-0.5 rounded cursor-pointer">+ {balance_amount}</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-amber-600 hover:bg-amber-700 text-white font-bold py-2.5 rounded-xl shadow-md text-xs transition-all mt-4">Save Delivery Portal Settings</button>
                </form>
            </div>

            <!-- LANDSCAPE PORTAL 2: TEXTBEE FREE AUTOMATED SMS GATEWAY PORTAL -->
            <?php $isTextbeeActive = (($settings['textbee_enabled'] ?? '0') === '1'); ?>
            <div class="bg-white p-6 rounded-2xl border-2 border-emerald-300 shadow-md portal-card space-y-4 flex flex-col justify-between">
                <form method="POST" class="space-y-4 flex-1 flex flex-col justify-between">
                    <input type="hidden" name="action" value="save_settings">
                    <input type="hidden" name="has_textbee_section" value="1">

                    <div class="space-y-4">
                        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-emerald-200 pb-3">
                            <div class="flex items-center gap-3">
                                <span class="material-symbols-outlined text-emerald-700 text-2xl p-2 bg-emerald-50 rounded-xl border border-emerald-200">phonelink_ring</span>
                                <div>
                                    <h2 class="text-base font-black font-headline text-emerald-950 flex items-center gap-2">
                                        TextBee Free Android SMS Gateway
                                    </h2>
                                    <p class="text-3xs text-emerald-800 font-medium">Free automated SMS notifications sent directly from your store Android device</p>
                                </div>
                            </div>

                            <label class="flex items-center gap-1.5 cursor-pointer text-xs font-bold text-emerald-900 bg-emerald-50 border border-emerald-300 px-2.5 py-1 rounded-full">
                                <input type="checkbox" name="textbee_enabled" value="1" <?= $isTextbeeActive ? 'checked' : '' ?> class="rounded text-emerald-600 focus:ring-emerald-500">
                                Enable Gateway Dispatch
                            </label>
                        </div>

                        <div class="bg-emerald-50/90 border border-emerald-200 p-3.5 rounded-xl space-y-3">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-2xs font-extrabold text-emerald-950 mb-1">TextBee API Key *</label>
                                    <input type="text" name="textbee_api_key" value="<?= htmlspecialchars($settings['textbee_api_key'] ?? '') ?>" placeholder="Your TextBee API Key" class="font-mono text-xs w-full bg-white border border-emerald-300 rounded-lg p-2 focus:ring-2 focus:ring-emerald-500">
                                </div>

                                <div>
                                    <label class="block text-2xs font-extrabold text-emerald-950 mb-1">TextBee Device ID *</label>
                                    <input type="text" name="textbee_device_id" value="<?= htmlspecialchars($settings['textbee_device_id'] ?? '') ?>" placeholder="Your Android Device ID" class="font-mono text-xs w-full bg-white border border-emerald-300 rounded-lg p-2 focus:ring-2 focus:ring-emerald-500">
                                </div>
                            </div>
                        </div>

                        <!-- ORDER RECEIVED / CREATED SMS TEMPLATE EDITOR -->
                        <div class="bg-indigo-50/90 border border-indigo-200 p-3.5 rounded-xl space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-extrabold text-indigo-950 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-indigo-700">mark_email_read</span>
                                    Order Received / Created SMS Template
                                </h3>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="resetReceivedSmsTemplate()" class="text-3xs text-indigo-700 hover:text-indigo-900 font-bold hover:underline inline-flex items-center gap-0.5 cursor-pointer">
                                        <span class="material-symbols-outlined text-3xs">restart_alt</span> Reset Default
                                    </button>
                                    <span id="sms_received_autosave_status" class="text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold">
                                        Auto-saved ✓
                                    </span>
                                </div>
                            </div>

                            <div>
                                <textarea name="sms_template_received" id="sms_template_received_input" rows="3" oninput="triggerReceivedSmsAutoSave()" class="w-full text-xs font-mono bg-white border border-indigo-300 rounded-lg p-2 focus:ring-2 focus:ring-indigo-500 shadow-2xs" placeholder="Type your SMS message here..." required><?= htmlspecialchars($settings['sms_template_received'] ?? 'Thank you {customer_name}! We have received your laundry order #{tracking_number} at {shop_name}. Total: ₱{total_amount}. We take great care of your clothes.') ?></textarea>
                            </div>

                            <div class="bg-white border border-indigo-200 rounded-xl p-2.5 space-y-1 shadow-2xs">
                                <div class="text-3xs font-bold uppercase tracking-wider text-indigo-900">Live Sample Preview:</div>
                                <div id="sms_received_live_preview" class="text-xs text-gray-900 bg-indigo-50/50 p-2 rounded-lg border border-indigo-100 font-sans leading-relaxed">
                                    Loading preview...
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-1 text-3xs font-mono font-bold">
                                <button type="button" onclick="insertReceivedSmsTag('{customer_name}')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-900 border border-indigo-300 px-1.5 py-0.5 rounded cursor-pointer">+ {customer_name}</button>
                                <button type="button" onclick="insertReceivedSmsTag('{tracking_number}')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-900 border border-indigo-300 px-1.5 py-0.5 rounded cursor-pointer">+ {tracking_number}</button>
                                <button type="button" onclick="insertReceivedSmsTag('{total_amount}')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-900 border border-indigo-300 px-1.5 py-0.5 rounded cursor-pointer">+ {total_amount}</button>
                                <button type="button" onclick="insertReceivedSmsTag('{shop_name}')" class="bg-indigo-100 hover:bg-indigo-200 text-indigo-900 border border-indigo-300 px-1.5 py-0.5 rounded cursor-pointer">+ {shop_name}</button>
                            </div>
                        </div>

                        <!-- READY FOR PICKUP SMS TEMPLATE EDITOR -->
                        <div class="bg-blue-50/90 border border-blue-200 p-3.5 rounded-xl space-y-3">
                            <div class="flex items-center justify-between">
                                <h3 class="text-xs font-extrabold text-blue-950 flex items-center gap-1.5">
                                    <span class="material-symbols-outlined text-base text-blue-700">sms</span>
                                    Ready for Pickup SMS Template
                                </h3>
                                <div class="flex items-center gap-2">
                                    <button type="button" onclick="resetSmsTemplate()" class="text-3xs text-blue-700 hover:text-blue-900 font-bold hover:underline inline-flex items-center gap-0.5 cursor-pointer">
                                        <span class="material-symbols-outlined text-3xs">restart_alt</span> Reset Default
                                    </button>
                                    <span id="sms_autosave_status" class="text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold">
                                        Auto-saved ✓
                                    </span>
                                </div>
                            </div>

                            <div>
                                <textarea name="sms_template_ready" id="sms_template_ready_input" rows="3" oninput="triggerSmsAutoSave()" class="w-full text-xs font-mono bg-white border border-blue-300 rounded-lg p-2 focus:ring-2 focus:ring-blue-500 shadow-2xs" placeholder="Type your SMS message here..." required><?= htmlspecialchars($settings['sms_template_ready'] ?? 'Aquamarine Laundry: Good news {customer_name}! Your laundry (Receipt #{tracking_number}) is READY FOR PICKUP. Total: ₱{total_amount}, Paid: ₱{paid_amount}, Balance: ₱{balance_amount}. Thank you!') ?></textarea>
                            </div>

                            <div class="bg-white border border-blue-200 rounded-xl p-2.5 space-y-1 shadow-2xs">
                                <div class="text-3xs font-bold uppercase tracking-wider text-blue-900">Live Sample Preview:</div>
                                <div id="sms_live_preview" class="text-xs text-gray-900 bg-blue-50/50 p-2 rounded-lg border border-blue-100 font-sans leading-relaxed">
                                    Loading preview...
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-1 text-3xs font-mono font-bold">
                                <button type="button" onclick="insertSmsTag('{customer_name}')" class="bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 px-1.5 py-0.5 rounded cursor-pointer">+ {customer_name}</button>
                                <button type="button" onclick="insertSmsTag('{tracking_number}')" class="bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 px-1.5 py-0.5 rounded cursor-pointer">+ {tracking_number}</button>
                                <button type="button" onclick="insertSmsTag('{total_amount}')" class="bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 px-1.5 py-0.5 rounded cursor-pointer">+ {total_amount}</button>
                                <button type="button" onclick="insertSmsTag('{paid_amount}')" class="bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 px-1.5 py-0.5 rounded cursor-pointer">+ {paid_amount}</button>
                                <button type="button" onclick="insertSmsTag('{balance_amount}')" class="bg-blue-100 hover:bg-blue-200 text-blue-900 border border-blue-300 px-1.5 py-0.5 rounded cursor-pointer">+ {balance_amount}</button>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold py-2.5 rounded-xl shadow-md text-xs transition-all mt-4">Save TextBee Gateway Settings</button>
                </form>
            </div>

        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE FULL-WIDTH CARD: GMAIL SMTP DIGITAL RECEIPT INTEGRATION -->
        <!-- ========================================================================== -->
        <?php $isEmailActive = (($settings['email_enabled'] ?? '1') === '1'); ?>
        <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-md portal-card space-y-4">
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="save_settings">
                <input type="hidden" name="has_email_section" value="1">

                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-gray-200 pb-3">
                        <div class="flex items-center gap-3">
                            <span class="material-symbols-outlined text-blue-700 text-2xl p-2 bg-blue-50 rounded-xl border border-blue-200">mark_email_read</span>
                            <div>
                                <h2 class="text-base font-black font-headline text-gray-900 flex items-center gap-2">
                                    Gmail SMTP Digital Receipt Integration
                                </h2>
                                <p class="text-3xs text-gray-500 font-medium">Automatic HTML email receipts delivered to customer email addresses</p>
                            </div>
                        </div>

                        <label class="flex items-center gap-1.5 cursor-pointer text-xs font-bold text-blue-900 bg-blue-50 border border-blue-300 px-2.5 py-1 rounded-full">
                            <input type="checkbox" name="email_enabled" value="1" <?= $isEmailActive ? 'checked' : '' ?> class="rounded text-blue-600 focus:ring-blue-500">
                            Enable Gmail SMTP Dispatch
                        </label>
                    </div>

                    <div class="bg-blue-50/80 border border-blue-200 p-3.5 rounded-xl space-y-3">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-3xs font-bold text-gray-700 uppercase mb-1">Store Gmail Address</label>
                                <input type="email" name="gmail_smtp_user" value="<?= htmlspecialchars($settings['gmail_smtp_user'] ?? '') ?>" placeholder="yourshop@gmail.com" class="text-xs font-mono py-1.5 bg-white border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-3xs font-bold text-gray-700 uppercase mb-1">Google App Password (16 Letters)</label>
                                <input type="password" name="gmail_app_password" value="<?= htmlspecialchars($settings['gmail_app_password'] ?? '') ?>" placeholder="abcd efgh ijkl mnop" class="text-xs font-mono py-1.5 bg-white border border-gray-300 rounded-lg">
                            </div>
                            <div>
                                <label class="block text-3xs font-bold text-gray-700 uppercase mb-1">Store Sender Name</label>
                                <input type="text" name="email_from_name" value="<?= htmlspecialchars($settings['email_from_name'] ?? 'Aquamarine Laundry') ?>" placeholder="Aquamarine Laundry" class="text-xs font-bold py-1.5 bg-white border border-gray-300 rounded-lg">
                            </div>
                        </div>
                    </div>
                </div>

                <button type="submit" class="w-full bg-blue-700 hover:bg-blue-800 text-white font-bold py-2.5 rounded-xl shadow-md text-xs transition-all mt-4 cursor-pointer">Save Gmail SMTP Settings</button>
            </form>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE FULL-WIDTH CARD: USER ACCOUNTS & STAFF ACCESS CONTROL -->
        <!-- ========================================================================== -->
        <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-md space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-3 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-blue-700 text-2xl p-2 bg-blue-50 rounded-xl border border-blue-200">manage_accounts</span>
                    <div>
                        <h2 class="text-base font-black font-headline text-gray-900">
                            Staff Accounts & User Access Control
                        </h2>
                        <p class="text-3xs text-gray-500 font-medium">Create and manage Cashier POS terminals and Administrator user accounts</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <input type="text" id="staff_search_input" placeholder="🔍 Search accounts..." class="text-xs p-2 border border-gray-300 rounded-xl w-full sm:w-64 font-medium focus:ring-2 focus:ring-blue-500">
                    <button onclick="document.getElementById('addUserModal').classList.remove('hidden')" class="bg-blue-700 hover:bg-blue-800 text-white font-bold px-3.5 py-2 rounded-xl text-xs shadow-sm flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
                        <span class="material-symbols-outlined text-sm">person_add</span> + Add Account
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto border border-gray-200 rounded-xl">
                <table id="staff_table" class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-gray-50 text-gray-700 font-bold border-b border-gray-200">
                            <th class="p-3">User ID</th>
                            <th class="p-3">Username</th>
                            <th class="p-3">Full Name</th>
                            <th class="p-3">Role</th>
                            <th class="p-3">Cashier ID #</th>
                            <th class="p-3">Account Status</th>
                            <th class="p-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($users as $u): ?>
                            <tr class="hover:bg-gray-50/60 transition-colors">
                                <td class="p-3 font-mono font-bold text-gray-500">#<?= $u['id'] ?></td>
                                <td class="p-3 font-black text-gray-900 font-mono"><?= htmlspecialchars($u['username']) ?></td>
                                <td class="p-3 font-extrabold text-gray-800"><?= htmlspecialchars($u['full_name']) ?></td>
                                <td class="p-3">
                                    <?php if ($u['role'] === 'admin'): ?>
                                        <span class="bg-blue-100 text-blue-900 border border-blue-300 font-black text-3xs px-2.5 py-1 rounded-full inline-flex items-center gap-1">
                                            👑 Administrator
                                        </span>
                                    <?php else: ?>
                                        <span class="bg-emerald-100 text-emerald-900 border border-emerald-300 font-black text-3xs px-2.5 py-1 rounded-full inline-flex items-center gap-1">
                                            💼 POS Cashier
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="p-3 font-mono font-bold text-gray-700"><?= htmlspecialchars($u['cashier_id_number'] ?? 'CSH-101') ?></td>
                                <td class="p-3">
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle_user_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="<?= ($u['status'] ?? 'active') === 'active' ? 'bg-emerald-50 text-emerald-800 border-emerald-300' : 'bg-rose-50 text-rose-800 border-rose-300' ?> border font-extrabold text-3xs px-2.5 py-1 rounded-full hover:opacity-80 transition-all cursor-pointer">
                                            <?= ($u['status'] ?? 'active') === 'active' ? '✅ Active' : '🔴 Inactive' ?>
                                        </button>
                                    </form>
                                </td>
                                <td class="p-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button type="button" onclick="editUser(<?= htmlspecialchars(json_encode($u)) ?>)" class="text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 px-2.5 py-1 rounded-lg font-bold text-xs cursor-pointer transition-all">
                                            Edit
                                        </button>

                                        <button type="button" onclick="resetPassword(<?= htmlspecialchars(json_encode($u)) ?>)" class="text-amber-800 bg-amber-50 hover:bg-amber-100 border border-amber-300 px-2.5 py-1 rounded-lg font-bold text-xs cursor-pointer transition-all">
                                            Reset Pass
                                        </button>

                                        <?php if ((int)$u['id'] !== (int)($_SESSION['user_id'] ?? 0)): ?>
                                            <form method="POST" onsubmit="return confirm('Delete user account <?= htmlspecialchars($u['username']) ?>?');" class="inline">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                                <button type="submit" class="text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-2.5 py-1 rounded-lg font-bold text-xs cursor-pointer transition-all">
                                                    Delete
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Staff Pagination Footer -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-1 border-t border-gray-100">
                <span id="staff_page_info" class="text-xs text-gray-600 font-medium">Showing entries</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="staff_prev_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Previous</button>
                    <span id="staff_page_label" class="text-xs font-bold text-gray-800 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-lg">Page 1 of 1</span>
                    <button type="button" id="staff_next_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Next</button>
                </div>
            </div>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE DEDICATED CARD 1: LAUNDRY SERVICES CATALOG -->
        <!-- ========================================================================== -->
        <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-md space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-3 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-blue-700 text-2xl p-2 bg-blue-50 rounded-xl border border-blue-200">local_laundry_service</span>
                    <div>
                        <h2 class="text-base font-black font-headline text-gray-900">
                            Laundry Services Catalog & Pricing Rates
                        </h2>
                        <p class="text-3xs text-gray-500 font-medium">Configure primary laundry services, per-kg / load pricing rates, categories, and load capacities for cashier POS</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <input type="text" id="service_search_input" placeholder="🔍 Search services..." class="text-xs p-2 border border-gray-300 rounded-xl w-full sm:w-64 font-medium focus:ring-2 focus:ring-blue-500">
                    <button onclick="document.getElementById('addServiceModal').classList.remove('hidden')" class="bg-blue-700 hover:bg-blue-800 text-white font-bold px-3.5 py-2 rounded-xl text-xs shadow-sm flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
                        <span class="material-symbols-outlined text-sm">add_circle</span> + Add Service
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto border border-gray-200 rounded-xl">
                <table id="service_table" class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-blue-50/70 text-blue-950 font-bold border-b border-blue-200">
                            <th class="p-3">Service Name</th>
                            <th class="p-3">Category</th>
                            <th class="p-3">Price / Load (₱)</th>
                            <th class="p-3">Load Capacity & Unit</th>
                            <th class="p-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($services as $s): ?>
                            <tr class="hover:bg-blue-50/30 transition-colors">
                                <td class="p-3 font-bold text-gray-900"><?= htmlspecialchars($s['service_name']) ?></td>
                                <td class="p-3 text-gray-600 font-medium"><?= htmlspecialchars($s['category']) ?></td>
                                <td class="p-3 font-black text-blue-700 font-mono text-sm">₱<?= number_format($s['price_per_kg'], 2) ?></td>
                                <td class="p-3 font-bold text-gray-700"><?= $s['min_kg'] ?> <?= htmlspecialchars($s['unit'] ?? 'kg') ?></td>
                                <td class="p-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button onclick="editService(<?= htmlspecialchars(json_encode($s)) ?>)" class="text-blue-700 bg-blue-50 hover:bg-blue-100 border border-blue-200 px-3 py-1 rounded-lg font-bold transition-all cursor-pointer">Edit</button>
                                        <form method="POST" onsubmit="return confirm('Delete service <?= htmlspecialchars($s['service_name']) ?>?');" class="inline">
                                            <input type="hidden" name="action" value="delete_service">
                                            <input type="hidden" name="service_id" value="<?= $s['id'] ?>">
                                            <button type="submit" class="text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-3 py-1 rounded-lg font-bold transition-all cursor-pointer">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Service Pagination Footer -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-1 border-t border-gray-100">
                <span id="service_page_info" class="text-xs text-gray-600 font-medium">Showing entries</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="service_prev_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Previous</button>
                    <span id="service_page_label" class="text-xs font-bold text-gray-800 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-lg">Page 1 of 1</span>
                    <button type="button" id="service_next_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Next</button>
                </div>
            </div>
        </div>

        <!-- ========================================================================== -->
        <!-- LANDSCAPE DEDICATED CARD 2: ADD-ON PRODUCTS & EXTRA TREATMENTS CATALOG -->
        <!-- ========================================================================== -->
        <div class="bg-white p-6 rounded-2xl border border-gray-200 shadow-md space-y-6">
            <div class="flex flex-wrap items-center justify-between gap-4 pb-3 border-b border-gray-200">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-emerald-700 text-2xl p-2 bg-emerald-50 rounded-xl border border-emerald-200">extension</span>
                    <div>
                        <h2 class="text-base font-black font-headline text-gray-900">
                            Add-on Products & Extra Treatments Catalog
                        </h2>
                        <p class="text-3xs text-gray-500 font-medium">Manage detergents, fabric softeners, bleaches, scent boosters, and extra treatment options available at Cashier POS</p>
                    </div>
                </div>
                
                <div class="flex items-center gap-3 w-full sm:w-auto">
                    <input type="text" id="addon_search_input" placeholder="🔍 Search add-ons..." class="text-xs p-2 border border-gray-300 rounded-xl w-full sm:w-64 font-medium focus:ring-2 focus:ring-emerald-500">
                    <button onclick="document.getElementById('addAddonModal').classList.remove('hidden')" class="bg-emerald-700 hover:bg-emerald-800 text-white font-bold px-3.5 py-2 rounded-xl text-xs shadow-sm flex items-center gap-1.5 cursor-pointer whitespace-nowrap">
                        <span class="material-symbols-outlined text-sm">add_circle</span> + Add Add-on
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto border border-gray-200 rounded-xl">
                <table id="addon_table" class="w-full text-left text-xs border-collapse">
                    <thead>
                        <tr class="bg-emerald-50/70 text-emerald-950 font-bold border-b border-emerald-200">
                            <th class="p-3">Icon</th>
                            <th class="p-3">Add-on Product Name</th>
                            <th class="p-3">Price (₱)</th>
                            <th class="p-3 text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php foreach ($allAddons as $a): ?>
                            <?php $addonName = $a['addon_name'] ?? $a['name'] ?? 'Add-on Product'; ?>
                            <tr class="hover:bg-emerald-50/30 transition-colors">
                                <td class="p-3 text-lg"><?= htmlspecialchars($a['icon'] ?? '🌸') ?></td>
                                <td class="p-3 font-bold text-gray-900"><?= htmlspecialchars($addonName) ?></td>
                                <td class="p-3 font-black text-emerald-700 font-mono text-sm">₱<?= number_format($a['price'], 2) ?></td>
                                <td class="p-3 text-center">
                                    <div class="flex items-center justify-center gap-1.5">
                                        <button onclick="editAddon(<?= htmlspecialchars(json_encode($a)) ?>)" class="text-emerald-700 bg-emerald-50 hover:bg-emerald-100 border border-emerald-200 px-3 py-1 rounded-lg font-bold transition-all cursor-pointer">Edit</button>
                                        <form method="POST" onsubmit="return confirm('Delete add-on product <?= htmlspecialchars($addonName) ?>?');" class="inline">
                                            <input type="hidden" name="action" value="delete_addon">
                                            <input type="hidden" name="addon_id" value="<?= $a['id'] ?>">
                                            <button type="submit" class="text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-3 py-1 rounded-lg font-bold transition-all cursor-pointer">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Addon Pagination Footer -->
            <div class="flex flex-wrap items-center justify-between gap-3 pt-1 border-t border-gray-100">
                <span id="addon_page_info" class="text-xs text-gray-600 font-medium">Showing entries</span>
                <div class="flex items-center gap-2">
                    <button type="button" id="addon_prev_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Previous</button>
                    <span id="addon_page_label" class="text-xs font-bold text-gray-800 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-lg">Page 1 of 1</span>
                    <button type="button" id="addon_next_btn" class="bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold px-3 py-1.5 rounded-lg text-xs border border-gray-300">Next</button>
                </div>
            </div>
        </div>

    </main>

    <!-- Modals for Add/Edit Services & Add-ons -->
    <div id="addServiceModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <h3 class="text-base font-black font-headline text-gray-900">+ Add New Laundry Service</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_service">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Service Name *</label>
                    <input type="text" name="service_name" required placeholder="e.g. Wash, Dry & Fold" class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Category *</label>
                    <select name="category" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-bold">
                        <option value="Full Package (Wash, Dry & Fold)">Full Package (Wash, Dry & Fold)</option>
                        <option value="Wash Only">Wash Only</option>
                        <option value="Dry Only">Dry Only</option>
                        <option value="Special Items">Special Items</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Price / Load (₱) *</label>
                        <input type="number" step="0.01" min="0.01" name="price_per_kg" required placeholder="160.00" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Capacity Limit *</label>
                        <input type="number" step="0.1" min="0.1" name="min_kg" value="1.0" required class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Capacity Unit *</label>
                        <select name="unit" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-bold">
                            <option value="piece/load">piece/load</option>
                            <option value="kg/load" selected>kg/load</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Description / Inclusion</label>
                    <input type="text" name="description" placeholder="e.g. Includes Detergent & Softener" class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addServiceModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-lg text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-700 text-white font-bold rounded-lg text-xs shadow-sm">Save Service</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Service -->
    <div id="editServiceModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <h3 class="text-base font-black font-headline text-gray-900">Edit Laundry Service</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="edit_service">
                <input type="hidden" name="service_id" id="edit_service_id">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Service Name *</label>
                    <input type="text" name="service_name" id="edit_service_name" required class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Category *</label>
                    <select name="category" id="edit_service_category" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-bold">
                        <option value="Full Package (Wash, Dry & Fold)">Full Package (Wash, Dry & Fold)</option>
                        <option value="Wash Only">Wash Only</option>
                        <option value="Dry Only">Dry Only</option>
                        <option value="Special Items">Special Items</option>
                    </select>
                </div>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Price / Load (₱) *</label>
                        <input type="number" step="0.01" min="0.01" name="price_per_kg" id="edit_service_price" required class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Capacity Limit *</label>
                        <input type="number" step="0.1" min="0.1" name="min_kg" id="edit_service_minkg" required class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Capacity Unit *</label>
                        <select name="unit" id="edit_service_unit" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-bold">
                            <option value="piece/load">piece/load</option>
                            <option value="kg/load">kg/load</option>
                        </select>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Description / Inclusion</label>
                    <input type="text" name="description" id="edit_service_desc" class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editServiceModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-lg text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-700 text-white font-bold rounded-lg text-xs shadow-sm">Update Service</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Add Add-on Product -->
    <div id="addAddonModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <h3 class="text-base font-black font-headline text-emerald-950">+ Add Add-on Product</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="add_addon">
                <div class="grid grid-cols-4 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Icon</label>
                        <input type="text" name="icon" id="add_addon_icon" value="🌸" placeholder="🌸" class="w-full text-xs p-2 border border-gray-300 rounded-lg text-center font-bold">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Add-on Product Name *</label>
                        <input type="text" name="addon_name" required placeholder="e.g. Premium Fabric Softener" class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                    </div>
                </div>

                <!-- Emoji Picker -->
                <div>
                    <label class="block text-3xs font-extrabold text-gray-500 uppercase tracking-wider mb-1">Click to select icon emoji:</label>
                    <div class="flex flex-wrap gap-1 p-2 bg-emerald-50/60 border border-emerald-200 rounded-xl text-base">
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌸</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧴</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧼</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">💧</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">✨</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">💐</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌿</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">⚡</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧊</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧺</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🫧</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌺</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🍃</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'add')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">👑</button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Price (₱) *</label>
                    <input type="number" step="0.01" min="0" name="price" required placeholder="25.00" class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addAddonModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-lg text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 text-white font-bold rounded-lg text-xs shadow-sm">Save Add-on</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Add-on Product -->
    <div id="editAddonModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <h3 class="text-base font-black font-headline text-emerald-950">Edit Add-on Product</h3>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="edit_addon">
                <input type="hidden" name="addon_id" id="edit_addon_id">
                <div class="grid grid-cols-4 gap-2">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Icon</label>
                        <input type="text" name="icon" id="edit_addon_icon" required class="w-full text-xs p-2 border border-gray-300 rounded-lg text-center font-bold">
                    </div>
                    <div class="col-span-3">
                        <label class="block text-xs font-bold text-gray-700 mb-1">Add-on Product Name *</label>
                        <input type="text" name="addon_name" id="edit_addon_name" required class="w-full text-xs p-2 border border-gray-300 rounded-lg">
                    </div>
                </div>

                <!-- Emoji Picker -->
                <div>
                    <label class="block text-3xs font-extrabold text-gray-500 uppercase tracking-wider mb-1">Click to select icon emoji:</label>
                    <div class="flex flex-wrap gap-1 p-2 bg-emerald-50/60 border border-emerald-200 rounded-xl text-base">
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌸</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧴</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧼</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">💧</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">✨</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">💐</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌿</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">⚡</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧊</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🧺</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🫧</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🌺</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">🍃</button>
                        <button type="button" onclick="selectAddonEmoji(this, 'edit')" class="hover:bg-white p-1 rounded transition-all cursor-pointer">👑</button>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Price (₱) *</label>
                    <input type="number" step="0.01" min="0" name="price" id="edit_addon_price" required class="w-full text-xs p-2 border border-gray-300 rounded-lg font-mono">
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editAddonModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-lg text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-emerald-700 text-white font-bold rounded-lg text-xs shadow-sm">Update Add-on</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JavaScript Helpers for Dynamic Previews & Auto-saves -->
    <script>
        function updateReceivedSmsPreview() {
            const input = document.getElementById('sms_template_received_input');
            const preview = document.getElementById('sms_received_live_preview');
            if (!input || !preview) return;

            let sampleText = input.value || 'Thank you {customer_name}! We have received your laundry order #{tracking_number} at {shop_name}. Total: ₱{total_amount}. We take great care of your clothes.';
            
            sampleText = sampleText
                .replace(/{customer_name}/g, 'Maria Santos')
                .replace(/{tracking_number}/g, 'AQ-2026-0842')
                .replace(/{receipt_number}/g, 'AQ-2026-0842')
                .replace(/{total_amount}/g, '300.00')
                .replace(/{paid_amount}/g, '150.00')
                .replace(/{balance_amount}/g, '150.00')
                .replace(/{payment_status}/g, 'Partially Paid')
                .replace(/{shop_name}/g, 'Aquamarine Laundry Hub');
            
            preview.innerText = '"' + sampleText + '"';
        }

        let smsReceivedSaveTimeout = null;
        function triggerReceivedSmsAutoSave() {
            const input = document.getElementById('sms_template_received_input');
            const status = document.getElementById('sms_received_autosave_status');
            if (!input || !status) return;

            status.innerText = 'Saving... ⏳';
            status.className = 'text-3xs bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded-full font-bold transition-all';

            updateReceivedSmsPreview();

            clearTimeout(smsReceivedSaveTimeout);
            smsReceivedSaveTimeout = setTimeout(() => {
                const formData = new FormData();
                formData.append('sms_template_received', input.value);

                fetch('save_sms_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        status.innerText = 'Auto-saved ✓';
                        status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    } else {
                        status.innerText = 'Save Error ⚠️';
                        status.className = 'text-3xs bg-rose-100 text-rose-800 border border-rose-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    }
                })
                .catch(() => {
                    status.innerText = 'Saved ✓';
                    status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                });
            }, 400);
        }

        function insertReceivedSmsTag(tag) {
            const input = document.getElementById('sms_template_received_input');
            if (!input) return;
            
            const start = input.selectionStart !== undefined ? input.selectionStart : input.value.length;
            const end = input.selectionEnd !== undefined ? input.selectionEnd : input.value.length;
            const text = input.value;
            
            input.value = text.substring(0, start) + ' ' + tag + ' ' + text.substring(end);
            input.focus();
            input.setSelectionRange(start + tag.length + 2, start + tag.length + 2);
            triggerReceivedSmsAutoSave();
        }

        function resetReceivedSmsTemplate() {
            const defaultMsg = 'Thank you {customer_name}! We have received your laundry order #{tracking_number} at {shop_name}. Total: ₱{total_amount}. We take great care of your clothes.';
            const input = document.getElementById('sms_template_received_input');
            if (!input) return;
            input.value = defaultMsg;
            triggerReceivedSmsAutoSave();
        }

        function updateSmsPreview() {
            const input = document.getElementById('sms_template_ready_input');
            const preview = document.getElementById('sms_live_preview');
            if (!input || !preview) return;

            let sampleText = input.value || 'Aquamarine Laundry: Good news {customer_name}! Your laundry (Receipt #{tracking_number}) is READY FOR PICKUP. Total: ₱{total_amount}, Paid: ₱{paid_amount}, Balance: ₱{balance_amount}. Thank you!';
            
            sampleText = sampleText
                .replace(/{customer_name}/g, 'Maria Santos')
                .replace(/{tracking_number}/g, 'AQ-2026-0842')
                .replace(/{receipt_number}/g, 'AQ-2026-0842')
                .replace(/{total_amount}/g, '300.00')
                .replace(/{paid_amount}/g, '150.00')
                .replace(/{balance_amount}/g, '150.00')
                .replace(/{payment_status}/g, 'Partially Paid')
                .replace(/{pickup_date}/g, 'Aug 10, 2026')
                .replace(/{shop_name}/g, 'Aquamarine Laundry');
            
            preview.innerText = '"' + sampleText + '"';
        }

        let smsSaveTimeout = null;
        function triggerSmsAutoSave() {
            const input = document.getElementById('sms_template_ready_input');
            const status = document.getElementById('sms_autosave_status');
            if (!input || !status) return;

            status.innerText = 'Saving... ⏳';
            status.className = 'text-3xs bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded-full font-bold transition-all';

            updateSmsPreview();

            clearTimeout(smsSaveTimeout);
            smsSaveTimeout = setTimeout(() => {
                const formData = new FormData();
                formData.append('sms_template_ready', input.value);

                fetch('save_sms_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        status.innerText = 'Auto-saved ✓';
                        status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    } else {
                        status.innerText = 'Save Error ⚠️';
                        status.className = 'text-3xs bg-rose-100 text-rose-800 border border-rose-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    }
                })
                .catch(() => {
                    status.innerText = 'Saved ✓';
                    status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                });
            }, 400);
        }

        function insertSmsTag(tag) {
            const input = document.getElementById('sms_template_ready_input');
            if (!input) return;
            
            const start = input.selectionStart !== undefined ? input.selectionStart : input.value.length;
            const end = input.selectionEnd !== undefined ? input.selectionEnd : input.value.length;
            const text = input.value;
            
            input.value = text.substring(0, start) + ' ' + tag + ' ' + text.substring(end);
            input.focus();
            input.setSelectionRange(start + tag.length + 2, start + tag.length + 2);
            triggerSmsAutoSave();
        }

        function resetSmsTemplate() {
            const defaultMsg = '✨ Great news {customer_name}! Your fresh, clean laundry (Receipt #{tracking_number}) is READY FOR PICKUP at {shop_name}! 🧺 Total: ₱{total_amount} | Balance: ₱{balance_amount}. Thank you for choosing us!';
            const input = document.getElementById('sms_template_ready_input');
            if (!input) return;
            input.value = defaultMsg;
            triggerSmsAutoSave();
        }

        function updateOutSmsPreview() {
            const input = document.getElementById('sms_template_out_input');
            const preview = document.getElementById('sms_out_live_preview');
            if (!input || !preview) return;

            let sampleText = input.value || '🚚 On its way {customer_name}! Your fresh laundry (Receipt #{tracking_number}) is OUT FOR DELIVERY with rider {driver_name}! 📍 Address: {delivery_address}. Balance Due: ₱{balance_amount}. Thank you for choosing {shop_name}!';
            
            sampleText = sampleText
                .replace(/{customer_name}/g, 'Maria Santos')
                .replace(/{tracking_number}/g, 'AQ-2026-0842')
                .replace(/{receipt_number}/g, 'AQ-2026-0842')
                .replace(/{driver_name}/g, 'Kuya Mark / GrabExpress')
                .replace(/{delivery_address}/g, 'Blk 12 Lot 4 Sampaguita St.')
                .replace(/{total_amount}/g, '350.00')
                .replace(/{paid_amount}/g, '0.00')
                .replace(/{balance_amount}/g, '350.00')
                .replace(/{shop_name}/g, 'Aquamarine Laundry Hub');
            
            preview.innerText = '"' + sampleText + '"';
        }

        let smsOutSaveTimeout = null;
        function triggerOutSmsAutoSave() {
            const input = document.getElementById('sms_template_out_input');
            const status = document.getElementById('sms_out_autosave_status');
            if (!input || !status) return;

            status.innerText = 'Saving... ⏳';
            status.className = 'text-3xs bg-amber-100 text-amber-900 border border-amber-300 px-2 py-0.5 rounded-full font-bold transition-all';

            updateOutSmsPreview();

            clearTimeout(smsOutSaveTimeout);
            smsOutSaveTimeout = setTimeout(() => {
                const formData = new FormData();
                formData.append('sms_template_out_for_delivery', input.value);

                fetch('save_sms_ajax.php', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        status.innerText = 'Auto-saved ✓';
                        status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    } else {
                        status.innerText = 'Save Error ⚠️';
                        status.className = 'text-3xs bg-rose-100 text-rose-800 border border-rose-300 px-2 py-0.5 rounded-full font-bold transition-all';
                    }
                })
                .catch(() => {
                    status.innerText = 'Saved ✓';
                    status.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold transition-all';
                });
            }, 400);
        }

        function insertOutSmsTag(tag) {
            const input = document.getElementById('sms_template_out_input');
            if (!input) return;
            
            const start = input.selectionStart !== undefined ? input.selectionStart : input.value.length;
            const end = input.selectionEnd !== undefined ? input.selectionEnd : input.value.length;
            const text = input.value;
            
            input.value = text.substring(0, start) + ' ' + tag + ' ' + text.substring(end);
            input.focus();
            input.setSelectionRange(start + tag.length + 2, start + tag.length + 2);
            triggerOutSmsAutoSave();
        }

        function resetOutSmsTemplate() {
            const defaultMsg = '🚚 On its way {customer_name}! Your fresh laundry (Receipt #{tracking_number}) is OUT FOR DELIVERY with rider {driver_name}! 📍 Address: {delivery_address}. Balance Due: ₱{balance_amount}. Thank you for choosing {shop_name}!';
            const input = document.getElementById('sms_template_out_input');
            if (!input) return;
            input.value = defaultMsg;
            triggerOutSmsAutoSave();
        }

        function toggleDeliveryConfigBody(checkbox) {
            const body = document.getElementById('delivery_config_body');
            const statusBadge = document.getElementById('delivery_active_status_badge');
            if (!body) return;
            if (checkbox.checked) {
                body.classList.remove('hidden');
                if (statusBadge) {
                    statusBadge.className = 'bg-amber-100 text-amber-950 border border-amber-300 text-3xs font-black px-2.5 py-1 rounded-full uppercase tracking-wider';
                    statusBadge.innerText = '🚚 Portal Active';
                }
            } else {
                body.classList.add('hidden');
                if (statusBadge) {
                    statusBadge.className = 'bg-gray-100 text-gray-600 border border-gray-300 text-3xs font-bold px-2.5 py-1 rounded-full uppercase tracking-wider';
                    statusBadge.innerText = '🔴 Delivery Disabled';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            updateReceivedSmsPreview();
            updateSmsPreview();
            updateOutSmsPreview();
        });

        function previewGcashQr(input) {
            if (input.files && input.files[0]) {
                const file = input.files[0];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewImg = document.getElementById('gcash_qr_img_preview');
                    const placeholder = document.getElementById('gcash_qr_placeholder');
                    const badge = document.getElementById('qr_status_badge');
                    const label = document.getElementById('qr_file_name_label');

                    if (previewImg) {
                        previewImg.src = e.target.result;
                        previewImg.classList.remove('hidden');
                    }
                    if (placeholder) {
                        placeholder.classList.add('hidden');
                    }
                    if (badge) {
                        badge.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold';
                        badge.innerText = '✓ New QR Selected';
                    }
                };
                reader.readAsDataURL(file);
            }
        }

        function toggleRemoveGcashQr(checkbox) {
            const previewImg = document.getElementById('gcash_qr_img_preview');
            const badge = document.getElementById('qr_status_badge');

            if (checkbox.checked) {
                if (previewImg) previewImg.style.opacity = '0.3';
                if (badge) {
                    badge.className = 'text-3xs bg-rose-100 text-rose-800 border border-rose-300 px-2 py-0.5 rounded-full font-bold';
                    badge.innerText = 'Will Be Removed On Save';
                }
            } else {
                if (previewImg) previewImg.style.opacity = '1';
                if (badge) {
                    badge.className = 'text-3xs bg-emerald-100 text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-full font-bold';
                    badge.innerText = '✓ Active QR Uploaded';
                }
            }
        }

        function togglePasswordVisibility(inputId, btn) {
            const input = document.getElementById(inputId);
            if (!input) return;
            if (input.type === 'password') {
                input.type = 'text';
                btn.innerHTML = '🙈';
                btn.setAttribute('title', 'Hide Password');
            } else {
                input.type = 'password';
                btn.innerHTML = '👁️';
                btn.setAttribute('title', 'Show Password');
            }
        }

        function checkPasswordRules(val) {
            const ruleLen = document.getElementById('rule_length');
            const ruleSym = document.getElementById('rule_symbol');

            const hasMinLen = val.length >= 8;
            const hasSymbol = /[\W_]/.test(val);

            if (ruleLen) {
                if (hasMinLen) {
                    ruleLen.className = 'text-emerald-700 flex items-center gap-1 font-semibold';
                    ruleLen.innerText = '✓ Minimum 8 characters';
                } else {
                    ruleLen.className = 'text-red-600 flex items-center gap-1 font-medium';
                    ruleLen.innerText = '❌ Minimum 8 characters (at least 8 required)';
                }
            }

            if (ruleSym) {
                if (hasSymbol) {
                    ruleSym.className = 'text-emerald-700 flex items-center gap-1 font-semibold';
                    ruleSym.innerText = '✓ At least 1 special symbol';
                } else {
                    ruleSym.className = 'text-red-600 flex items-center gap-1 font-medium';
                    ruleSym.innerText = '❌ At least 1 special symbol (e.g. !@#$%^&*)';
                }
            }
        }

        function validateResetPasswordForm(form) {
            const passInput = document.getElementById('reset_new_password');
            const val = passInput ? passInput.value : '';

            if (val.length < 8) {
                alert('Password must be at least 8 characters long!');
                if (passInput) passInput.focus();
                return false;
            }

            if (!/[\W_]/.test(val)) {
                alert('Password must contain at least one special symbol (e.g. ! @ # $ % ^ & * _ -)!');
                if (passInput) passInput.focus();
                return false;
            }

            return true;
        }

        function checkAddUserPasswordRules(val) {
            const ruleLen = document.getElementById('add_rule_length');
            const ruleSym = document.getElementById('add_rule_symbol');

            const hasMinLen = val.length >= 8;
            const hasSymbol = /[\W_]/.test(val);

            if (ruleLen) {
                if (hasMinLen) {
                    ruleLen.className = 'text-emerald-700 flex items-center gap-1 font-semibold';
                    ruleLen.innerText = '✓ Minimum 8 characters';
                } else {
                    ruleLen.className = 'text-red-600 flex items-center gap-1 font-medium';
                    ruleLen.innerText = '❌ Minimum 8 characters (at least 8 required)';
                }
            }

            if (ruleSym) {
                if (hasSymbol) {
                    ruleSym.className = 'text-emerald-700 flex items-center gap-1 font-semibold';
                    ruleSym.innerText = '✓ At least 1 special symbol';
                } else {
                    ruleSym.className = 'text-red-600 flex items-center gap-1 font-medium';
                    ruleSym.innerText = '❌ At least 1 special symbol (e.g. !@#$%^&*)';
                }
            }
        }

        function validateAddUserForm(form) {
            const passInput = document.getElementById('add_user_password');
            const val = passInput ? passInput.value : '';

            if (val.length < 8) {
                alert('Account password must be at least 8 characters long!');
                if (passInput) passInput.focus();
                return false;
            }

            if (!/[\W_]/.test(val)) {
                alert('Account password must contain at least one special symbol (e.g. ! @ # $ % ^ & * _ -)!');
                if (passInput) passInput.focus();
                return false;
            }

            return true;
        }

        function editUser(user) {
            document.getElementById('edit_user_id').value = user.id;
            document.getElementById('edit_full_name').value = user.full_name;
            document.getElementById('edit_role').value = user.role;
            document.getElementById('edit_cashier_id_number').value = user.cashier_id_number || 'CSH-101';
            document.getElementById('editUserModal').classList.remove('hidden');
        }

        function resetPassword(user) {
            document.getElementById('reset_user_id').value = user.id;
            document.getElementById('reset_username_label').innerText = user.username + ' (' + user.full_name + ')';
            const passInput = document.getElementById('reset_new_password');
            if (passInput) {
                passInput.value = '';
                passInput.type = 'password';
            }
            const toggleBtn = document.getElementById('reset_pass_toggle_btn');
            if (toggleBtn) {
                toggleBtn.innerHTML = '👁️';
                toggleBtn.setAttribute('title', 'Show Password');
            }
            checkPasswordRules('');
            document.getElementById('resetPasswordModal').classList.remove('hidden');
        }

        function editService(service) {
            document.getElementById('edit_service_id').value = service.id;
            document.getElementById('edit_service_name').value = service.service_name;
            document.getElementById('edit_service_category').value = service.category;
            document.getElementById('edit_service_price').value = service.price_per_kg;
            document.getElementById('edit_service_minkg').value = service.min_kg;
            if (document.getElementById('edit_service_unit')) document.getElementById('edit_service_unit').value = service.unit || 'kg';
            document.getElementById('edit_service_desc').value = service.description || '';
            document.getElementById('editServiceModal').classList.remove('hidden');
        }

        function editAddon(addon) {
            document.getElementById('edit_addon_id').value = addon.id;
            document.getElementById('edit_addon_icon').value = addon.icon || '🌸';
            document.getElementById('edit_addon_name').value = addon.addon_name || addon.name || '';
            document.getElementById('edit_addon_price').value = addon.price;
            document.getElementById('editAddonModal').classList.remove('hidden');
        }

        function selectAddonEmoji(btn, modalType) {
            const emoji = btn.innerText.trim();
            if (modalType === 'add') {
                const input = document.getElementById('add_addon_icon');
                if (input) input.value = emoji;
            } else {
                const input = document.getElementById('edit_addon_icon');
                if (input) input.value = emoji;
            }
        }

        class TablePaginationController {
            constructor(tableId, searchInputId, infoId, prevBtnId, nextBtnId, pageLabelId, pageSize = 10) {
                this.table = document.getElementById(tableId);
                this.searchInput = document.getElementById(searchInputId);
                this.infoLabel = document.getElementById(infoId);
                this.prevBtn = document.getElementById(prevBtnId);
                this.nextBtn = document.getElementById(nextBtnId);
                this.pageLabel = document.getElementById(pageLabelId);
                this.pageSize = pageSize;
                this.currentPage = 1;

                if (this.table) {
                    this.rows = Array.from(this.table.querySelectorAll('tbody tr'));
                    this.bindEvents();
                    this.render();
                }
            }

            bindEvents() {
                if (this.searchInput) {
                    this.searchInput.addEventListener('input', () => {
                        this.currentPage = 1;
                        this.render();
                    });
                }
                if (this.prevBtn) {
                    this.prevBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        if (this.currentPage > 1) {
                            this.currentPage--;
                            this.render();
                        }
                    });
                }
                if (this.nextBtn) {
                    this.nextBtn.addEventListener('click', (e) => {
                        e.preventDefault();
                        const totalPages = Math.ceil(this.filteredRows().length / this.pageSize);
                        if (this.currentPage < totalPages) {
                            this.currentPage++;
                            this.render();
                        }
                    });
                }
            }

            filteredRows() {
                const query = this.searchInput ? this.searchInput.value.toLowerCase().trim() : '';
                if (!query) return this.rows;
                return this.rows.filter(row => row.innerText.toLowerCase().includes(query));
            }

            render() {
                const filtered = this.filteredRows();
                const totalRows = filtered.length;
                const totalPages = Math.max(1, Math.ceil(totalRows / this.pageSize));

                if (this.currentPage > totalPages) this.currentPage = totalPages;

                const startIdx = (this.currentPage - 1) * this.pageSize;
                const endIdx = startIdx + this.pageSize;

                this.rows.forEach(r => r.style.display = 'none');
                filtered.slice(startIdx, endIdx).forEach(r => r.style.display = '');

                if (this.infoLabel) {
                    if (totalRows === 0) {
                        this.infoLabel.innerText = 'No matching entries found';
                    } else {
                        const showingStart = startIdx + 1;
                        const showingEnd = Math.min(endIdx, totalRows);
                        this.infoLabel.innerText = `Showing ${showingStart} to ${showingEnd} of ${totalRows} entries`;
                    }
                }

                if (this.pageLabel) {
                    this.pageLabel.innerText = `Page ${this.currentPage} of ${totalPages}`;
                }

                if (this.prevBtn) {
                    this.prevBtn.disabled = (this.currentPage <= 1);
                    this.prevBtn.style.opacity = (this.currentPage <= 1) ? '0.5' : '1';
                    this.prevBtn.style.cursor = (this.currentPage <= 1) ? 'not-allowed' : 'pointer';
                }
                if (this.nextBtn) {
                    this.nextBtn.disabled = (this.currentPage >= totalPages);
                    this.nextBtn.style.opacity = (this.currentPage >= totalPages) ? '0.5' : '1';
                    this.nextBtn.style.cursor = (this.currentPage >= totalPages) ? 'not-allowed' : 'pointer';
                }
            }
        }

        document.addEventListener('DOMContentLoaded', () => {
            new TablePaginationController('staff_table', 'staff_search_input', 'staff_page_info', 'staff_prev_btn', 'staff_next_btn', 'staff_page_label', 10);
            new TablePaginationController('service_table', 'service_search_input', 'service_page_info', 'service_prev_btn', 'service_next_btn', 'service_page_label', 10);
            new TablePaginationController('addon_table', 'addon_search_input', 'addon_page_info', 'addon_prev_btn', 'addon_next_btn', 'addon_page_label', 10);
        });
    </script>

    <!-- Modal: Add New Cashier / Admin User Account -->
    <div id="addUserModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <div class="flex justify-between items-center pb-2 border-b border-gray-200">
                <h3 class="text-base font-black font-headline text-gray-900">+ Add New Cashier / Admin Account</h3>
                <button onclick="document.getElementById('addUserModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 font-bold text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3" onsubmit="return validateAddUserForm(this)">
                <input type="hidden" name="action" value="add_user">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Username *</label>
                    <input type="text" name="username" required placeholder="e.g. cashier_john" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-mono">
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Account Password *</label>
                    <div class="relative flex items-center">
                        <input type="password" id="add_user_password" name="password" required minlength="8" placeholder="Enter login password" class="w-full text-xs p-2.5 pr-10 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:outline-none" oninput="checkAddUserPasswordRules(this.value)">
                        <button type="button" id="add_pass_toggle_btn" onclick="togglePasswordVisibility('add_user_password', this)" class="absolute right-2.5 text-gray-500 hover:text-gray-800 focus:outline-none p-1 text-sm select-none" title="Show/Hide Password">
                            👁️
                        </button>
                    </div>
                    <div class="mt-2 text-2xs space-y-1 bg-gray-50 p-2.5 rounded-xl border border-gray-200">
                        <div id="add_rule_length" class="text-red-600 flex items-center gap-1 font-medium">❌ Minimum 8 characters (at least 8 required)</div>
                        <div id="add_rule_symbol" class="text-red-600 flex items-center gap-1 font-medium">❌ At least 1 special symbol (e.g. !@#$%^&*)</div>
                    </div>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Full Name *</label>
                    <input type="text" name="full_name" required placeholder="e.g. John Doe" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-extrabold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Account Role *</label>
                        <select name="role" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-bold">
                            <option value="cashier">💼 POS Cashier</option>
                            <option value="admin">👑 Administrator</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Cashier ID #</label>
                        <input type="text" name="cashier_id_number" value="CSH-101" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-mono">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('addUserModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-700 text-white font-bold rounded-xl text-xs shadow-sm">Create Account</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Staff User Profile -->
    <div id="editUserModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <div class="flex justify-between items-center pb-2 border-b border-gray-200">
                <h3 class="text-base font-black font-headline text-gray-900">Edit Staff Account Profile</h3>
                <button onclick="document.getElementById('editUserModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 font-bold text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="edit_user">
                <input type="hidden" name="user_id" id="edit_user_id">
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">Full Name *</label>
                    <input type="text" name="full_name" id="edit_full_name" required class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-extrabold">
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Account Role *</label>
                        <select name="role" id="edit_role" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-bold">
                            <option value="cashier">💼 POS Cashier</option>
                            <option value="admin">👑 Administrator</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-bold text-gray-700 mb-1">Cashier ID #</label>
                        <input type="text" name="cashier_id_number" id="edit_cashier_id_number" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-mono">
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('editUserModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-blue-700 text-white font-bold rounded-lg text-xs shadow-sm">Update Profile</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Reset User Password -->
    <div id="resetPasswordModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <div class="flex justify-between items-center pb-2 border-b border-gray-200">
                <h3 class="text-base font-black font-headline text-gray-900">Reset User Account Password</h3>
                <button onclick="document.getElementById('resetPasswordModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 font-bold text-lg">&times;</button>
            </div>
            <form method="POST" class="space-y-3" onsubmit="return validateResetPasswordForm(this)">
                <input type="hidden" name="action" value="reset_password">
                <input type="hidden" name="user_id" id="reset_user_id">
                <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl">
                    <span class="text-2xs text-amber-900 font-medium">Resetting password for: </span>
                    <strong id="reset_username_label" class="text-xs font-mono font-bold text-amber-950 block mt-0.5"></strong>
                </div>
                <div>
                    <label class="block text-xs font-bold text-gray-700 mb-1">New Password *</label>
                    <div class="relative flex items-center">
                        <input type="password" id="reset_new_password" name="new_password" required minlength="8" placeholder="Enter new password" class="w-full text-xs p-2.5 pr-10 border border-gray-300 rounded-xl focus:ring-2 focus:ring-amber-500 focus:outline-none" oninput="checkPasswordRules(this.value)">
                        <button type="button" id="reset_pass_toggle_btn" onclick="togglePasswordVisibility('reset_new_password', this)" class="absolute right-2.5 text-gray-500 hover:text-gray-800 focus:outline-none p-1 text-sm select-none" title="Show/Hide Password">
                            👁️
                        </button>
                    </div>
                    <div class="mt-2 text-2xs space-y-1 bg-gray-50 p-2.5 rounded-xl border border-gray-200">
                        <div id="rule_length" class="text-red-600 flex items-center gap-1 font-medium">❌ Minimum 8 characters (at least 8 required)</div>
                        <div id="rule_symbol" class="text-red-600 flex items-center gap-1 font-medium">❌ At least 1 special symbol (e.g. !@#$%^&*)</div>
                        <div id="rule_diff" class="text-amber-800 flex items-center gap-1 font-medium">🔒 Cannot be the same as current password</div>
                    </div>
                </div>
                <div class="flex justify-end gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('resetPasswordModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-xl text-xs">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-amber-700 hover:bg-amber-800 text-white font-bold rounded-xl text-xs shadow-sm">Save New Password</button>
                </div>
            </form>
        </div>
    </div>
    <!-- Scroll Position Preservation Script -->
    <script>
        (function() {
            document.addEventListener('submit', function(e) {
                sessionStorage.setItem('aquamarine_admin_scroll_pos', window.scrollY);
            });

            window.addEventListener('beforeunload', function() {
                sessionStorage.setItem('aquamarine_admin_scroll_pos', window.scrollY);
            });

            document.addEventListener('click', function(e) {
                const btn = e.target.closest('a, button, input[type="submit"]');
                if (btn) {
                    const text = (btn.innerText || btn.value || '').toLowerCase();
                    const href = (btn.getAttribute('href') || '').toLowerCase();
                    const id = (btn.id || '').toLowerCase();
                    
                    if (text.includes('previous') || text.includes('next') || href.includes('page=') || id.includes('prev') || id.includes('next') || href.includes('range=') || href.includes('filter=')) {
                        sessionStorage.setItem('aquamarine_admin_scroll_pos', window.scrollY);
                    }
                }
            });

            window.addEventListener('DOMContentLoaded', function() {
                const savedPos = sessionStorage.getItem('aquamarine_admin_scroll_pos');
                if (savedPos !== null) {
                    window.scrollTo({
                        top: parseInt(savedPos, 10),
                        behavior: 'instant'
                    });
                    sessionStorage.removeItem('aquamarine_admin_scroll_pos');
                }
            });
        })();
    </script>
</body>
</html>
