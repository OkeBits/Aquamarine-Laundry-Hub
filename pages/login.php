<?php
// Aquamarine Laundry System - Login Page

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['full_name'] = $user['full_name'];
            $_SESSION['role'] = $user['role'];

            logAudit($pdo, 'User Login', 'Authentication', "User {$user['username']} logged in successfully");

            header("Location: index.php?page=dashboard");
            exit;
        } else {
            $error = "Invalid username or password.";
        }
    } else {
        $error = "Please fill in all fields.";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Aquamarine Laundry Service System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css" rel="stylesheet">
    <link href="assets/css/style.css" rel="stylesheet">
</head>
<body class="login-body">

<div class="login-card">
    <div class="text-center mb-4">
        <div class="d-inline-flex align-items-center justify-content-center bg-light text-success rounded-circle mb-3" style="width: 70px; height: 70px; background: #e0f2fe !important;">
            <i class="fa-solid fa-soap text-primary fs-1" style="color: #00a896 !important;"></i>
        </div>
        <h3 class="fw-bold text-dark m-0">Aquamarine</h3>
        <p class="text-muted fs-6">Laundry Management Service System</p>
    </div>

    <?php if (!empty($error)): ?>
        <div class="alert alert-danger p-2 fs-7 text-center mb-3">
            <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="index.php?page=login">
        <div class="mb-3">
            <label class="form-label fw-semibold text-dark">Username</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="fa-solid fa-user text-muted"></i></span>
                <input type="text" name="username" class="form-control" placeholder="Enter username" required autofocus>
            </div>
        </div>

        <div class="mb-4">
            <label class="form-label fw-semibold text-dark">Password</label>
            <div class="input-group">
                <span class="input-group-text bg-light"><i class="fa-solid fa-lock text-muted"></i></span>
                <input type="password" name="password" class="form-control" placeholder="Enter password" required>
            </div>
        </div>

        <button type="submit" class="btn btn-primary w-100 py-2.5 fw-bold shadow-sm" style="background: #00a896; border: none;">
            <i class="fa-solid fa-right-to-bracket me-2"></i> Log In
        </button>
    </form>

    <div class="mt-4 p-3 bg-light rounded text-center fs-7 text-muted border">
        <small class="d-block fw-bold text-dark mb-1"><i class="fa-solid fa-key text-warning me-1"></i> Default Credentials:</small>
        <small class="d-block">Admin: <strong>admin</strong> / <strong>admin123</strong></small>
        <small class="d-block">Staff: <strong>staff</strong> / <strong>staff123</strong></small>
    </div>
</div>

</body>
</html>
