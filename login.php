<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

$error = '';
$msg = '';

if (isset($_GET['msg']) && $_GET['msg'] === 'password_reset_success') {
    $msg = 'Password reset successfully! You can now log in with your new password.';
}

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($requestMethod === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($username) || empty($password)) {
        $error = 'Username and password are required!';
    } else {
        $orderService = new OrderService();
        $user = $orderService->authenticateUser($username, $password);

        if ($user) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['cashier_id_number'] = $user['cashier_id_number'];

            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_username'] = $user['username'];

            if ($user['role'] === 'admin') {
                header('Location: admin_dashboard.php');
            } else {
                header('Location: cashier_dashboard.php');
            }
            exit;
        }

        $admin = Database::getInstance()->fetch("SELECT * FROM admins WHERE username = ?", [$username]);
        if ($admin && password_verify($password, $admin['password'])) {
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['role'] = 'admin';
            $_SESSION['user_id'] = $admin['id'];
            $_SESSION['username'] = $admin['username'];
            $_SESSION['full_name'] = $admin['username'];
            
            header('Location: admin_dashboard.php');
            exit;
        }
        
        $error = 'Invalid username or password!';
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Admin & Cashier Login</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link href="assets/css/offline-tailwind.css" rel="stylesheet"/>
    <link href="assets/css/material-symbols.css" rel="stylesheet"/>
    <script src="assets/js/tailwindcss.js"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "primary": "#0058be",
                        "primary-container": "#2170e4",
                        "secondary": "#006c49",
                        "background": "#f7f9fb",
                        "surface": "#f7f9fb",
                        "on-surface": "#191c1e",
                        "on-surface-variant": "#424754",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f2f4f6"
                    },
                    "borderRadius": {
                        "DEFAULT": "0.25rem",
                        "lg": "0.5rem",
                        "xl": "0.75rem",
                        "full": "9999px"
                    },
                    "fontFamily": {
                        "headline": ["Plus Jakarta Sans"],
                        "body": ["Inter"],
                        "label": ["Inter"]
                    }
                },
            },
        }
    </script>
    <style>
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .signature-gradient {
            background: linear-gradient(135deg, #0058be 0%, #2170e4 100%);
        }
    </style>
</head>
<body class="bg-background font-body text-on-surface min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <!-- Logo -->
        <div class="text-center mb-8">
            <img src="assets/img/logo.png" alt="Aquamarine Logo" class="w-20 h-20 object-contain rounded-2xl mx-auto mb-3 shadow-md border-2 border-white">
            <h1 class="text-3xl font-black tracking-tighter text-blue-700 font-headline">Aquamarine</h1>
            <p class="text-on-surface-variant text-xs mt-1 font-medium">Laundry Management Service System</p>
        </div>

        <!-- Login Form -->
        <div class="bg-surface-container-lowest p-8 rounded-2xl shadow-sm border border-outline">
    <!-- Message & Error Alert (Top-Left Fixed Popup, 5-Second Auto Fade Out) -->
    <?php if (!empty($msg) || !empty($error)): ?>
        <div id="toast_notification" class="fixed top-6 left-6 z-[9999] max-w-md w-full space-y-3 transition-all duration-500 transform translate-x-0">
            <?php if (!empty($msg)): ?>
                <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                    <p class="text-emerald-800 font-bold flex items-center gap-2 text-xs">
                        <span class="material-symbols-outlined text-base text-emerald-600">check_circle</span> <?= htmlspecialchars($msg) ?>
                    </p>
                    <button onclick="closeToastNotification()" class="text-emerald-500 hover:text-emerald-800 font-bold text-lg leading-none cursor-pointer">&times;</button>
                </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
                <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                    <p class="text-rose-800 font-bold flex items-center gap-2 text-xs">
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

            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-xs font-bold text-on-surface mb-1">Username *</label>
                    <input type="text" name="username" required class="w-full" placeholder="admin or cashier1" autofocus>
                </div>

                <div>
                    <div class="flex justify-between items-center mb-1">
                        <label class="block text-xs font-bold text-on-surface">Password *</label>
                        <a href="forgot_password.php" class="text-2xs font-bold text-primary hover:underline">Forgot Password?</a>
                    </div>
                    <div class="relative flex items-center">
                        <input type="password" name="password" id="login_password" required class="w-full pr-10" placeholder="••••••••">
                        <button type="button" onclick="togglePasswordVisibility('login_password', 'toggle_eye_icon')" class="absolute right-3 text-gray-500 hover:text-gray-700 flex items-center justify-center">
                            <span id="toggle_eye_icon" class="material-symbols-outlined text-xl">visibility</span>
                        </button>
                    </div>
                </div>

                <button type="submit" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md hover:shadow-lg transition-all mt-2">
                    Sign In to Terminal
                </button>
            </form>

            <script>
                function togglePasswordVisibility(inputId, iconId) {
                    const input = document.getElementById(inputId);
                    const icon = document.getElementById(iconId);
                    if (!input || !icon) return;
                    if (input.type === 'password') {
                        input.type = 'text';
                        icon.innerText = 'visibility_off';
                    } else {
                        input.type = 'password';
                        icon.innerText = 'visibility';
                    }
                }
            </script>

            <div class="mt-6 p-4 bg-surface-container-low rounded-xl text-xs text-on-surface-variant text-center border">
                <p class="font-bold text-on-surface mb-1">🔑 Default Login Credentials:</p>
                <p>Admin: <span class="font-mono text-primary font-bold">admin</span> / <span class="font-mono font-bold">admin123</span></p>
                <p>Cashier: <span class="font-mono text-emerald-700 font-bold">cashier1</span> / <span class="font-mono font-bold">cashier123</span></p>
            </div>
        </div>
    </div>
</body>
</html>
