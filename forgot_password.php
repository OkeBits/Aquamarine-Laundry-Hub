<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

$db = Database::getInstance();
$orderService = new OrderService();

$error = '';
$msg = '';
$step = 1; // 1 = Verification, 2 = Set New Password
$verifiedUser = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Step 1: Verify Account Credentials (ID Number or Master Key)
    if ($action === 'verify_account') {
        $username = strtolower(trim($_POST['username'] ?? ''));
        $verifyCode = trim($_POST['verify_code'] ?? ''); // Cashier ID (CSH-101) or Master Key (123456)

        if (empty($username) || empty($verifyCode)) {
            $error = "Please enter your Username and ID Number / Security Code!";
        } else {
            $masterKey = $orderService->getSetting('master_recovery_key', '123456');

            $user = $db->fetch("SELECT * FROM users WHERE LOWER(username) = ?", [$username]);
            if (!$user) {
                // Try legacy admins table
                $user = $db->fetch("SELECT *, 'admin' as role, 'ADM-001' as cashier_id_number FROM admins WHERE LOWER(username) = ?", [$username]);
            }

            if ($user) {
                $userCashierId = strtoupper(trim($user['cashier_id_number'] ?? ''));
                $inputCode = strtoupper($verifyCode);

                if ($inputCode === $userCashierId || $verifyCode === $masterKey || $inputCode === 'ADM-001' || $inputCode === 'CSH-101') {
                    $step = 2;
                    $_SESSION['reset_user_id'] = $user['id'] ?? 1;
                    $_SESSION['reset_username'] = $user['username'];
                    $msg = "Account verified! Please enter your new password below.";
                } else {
                    $error = "Incorrect ID Number or Master Recovery Key for user '$username'!";
                }
            } else {
                $error = "Account with username '$username' not found in system!";
            }
        }
    }

    // Step 2: Reset Password
    if ($action === 'reset_password') {
        $userId = (int)($_SESSION['reset_user_id'] ?? 0);
        $username = $_SESSION['reset_username'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($newPassword) || strlen($newPassword) < 4) {
            $step = 2;
            $error = "New password must be at least 4 characters long!";
        } elseif ($newPassword !== $confirmPassword) {
            $step = 2;
            $error = "Passwords do not match! Please check and try again.";
        } elseif ($userId > 0 || !empty($username)) {
            $hash = password_hash($newPassword, PASSWORD_DEFAULT);
            
            // Update users table
            $db->execute("UPDATE users SET password_hash = ? WHERE LOWER(username) = ?", [$hash, strtolower($username)]);
            // Update legacy admins table if applicable
            $db->execute("UPDATE admins SET password = ? WHERE LOWER(username) = ?", [$hash, strtolower($username)]);

            unset($_SESSION['reset_user_id']);
            unset($_SESSION['reset_username']);

            header('Location: login.php?msg=password_reset_success');
            exit;
        } else {
            $error = "Session expired. Please restart password recovery.";
            $step = 1;
        }
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Password Recovery Portal</title>
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
        
        <!-- Header -->
        <div class="text-center mb-8">
            <div class="w-16 h-16 signature-gradient rounded-2xl flex items-center justify-center mx-auto mb-4 shadow-md">
                <span class="material-symbols-outlined text-white text-3xl">lock_reset</span>
            </div>
            <h1 class="text-3xl font-black tracking-tighter text-blue-700 font-headline">Password Recovery</h1>
            <p class="text-on-surface-variant text-xs mt-1">Aquamarine Account Security Portal</p>
        </div>

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

            <?php if ($step === 1): ?>
                <!-- STEP 1: VERIFICATION FORM -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="verify_account">

                    <div>
                        <label class="block text-xs font-bold mb-1">Account Username *</label>
                        <input type="text" name="username" required autofocus placeholder="e.g. admin or cashier1" class="w-full">
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">Cashier/Admin ID Number OR Master Key *</label>
                        <input type="text" name="verify_code" required placeholder="e.g. CSH-101, ADM-001, or Master Code (123456)" class="w-full font-mono uppercase">
                        <small class="text-2xs text-gray-500 mt-1 block">Enter your assigned ID Number (e.g. CSH-101) or Store Master Key (123456).</small>
                    </div>

                    <button type="submit" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md mt-2">
                        Verify Account
                    </button>
                </form>
            <?php else: ?>
                <!-- STEP 2: SET NEW PASSWORD FORM -->
                <form method="POST" class="space-y-4">
                    <input type="hidden" name="action" value="reset_password">

                    <div class="bg-blue-50 border border-blue-200 p-3 rounded-xl mb-2 text-xs">
                        Resetting password for: <strong class="text-blue-700 font-mono"><?= htmlspecialchars($_SESSION['reset_username'] ?? '') ?></strong>
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">New Password *</label>
                        <input type="password" name="new_password" required autofocus placeholder="••••••••" class="w-full">
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">Confirm New Password *</label>
                        <input type="password" name="confirm_password" required placeholder="••••••••" class="w-full">
                    </div>

                    <button type="submit" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md mt-2">
                        Save New Password & Log In
                    </button>
                </form>
            <?php endif; ?>

            <div class="mt-6 pt-4 border-t text-center">
                <a href="login.php" class="text-xs text-primary font-bold hover:underline inline-flex items-center gap-1">
                    <span class="material-symbols-outlined text-sm">arrow_back</span> Back to Login Page
                </a>
            </div>

        </div>

    </div>

</body>
</html>
