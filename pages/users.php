<?php
// Aquamarine Laundry System - User Management Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

// Add User
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_user'])) {
    $username = trim($_POST['username'] ?? '');
    $full_name = trim($_POST['full_name'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'staff';

    if (!empty($username) && !empty($password) && !empty($full_name)) {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, full_name, role) VALUES (?, ?, ?, ?)");
            $stmt->execute([$username, $hash, $full_name, $role]);

            logAudit($pdo, 'User Created', 'User Management', "Created user account $username ($role)");
            header("Location: index.php?page=users&success=User+$username+created+successfully.");
            exit;
        } catch (PDOException $e) {
            header("Location: index.php?page=users&error=Username+already+exists.");
            exit;
        }
    }
}

// Toggle Status
if (isset($_GET['toggle_status']) && isset($_GET['user_id'])) {
    $uid = (int)$_GET['user_id'];
    $stmt = $pdo->prepare("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?");
    $stmt->execute([$uid]);
    logAudit($pdo, 'User Status Changed', 'User Management', "Toggled status for user ID #$uid");
    header("Location: index.php?page=users&success=User+status+updated.");
    exit;
}

$users = $pdo->query("SELECT * FROM users ORDER BY id ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-user-gear text-teal me-2"></i> User Account Management</h4>
        <small class="text-muted">Manage staff accounts, administrator privileges, and login credentials</small>
    </div>
    <button class="btn btn-primary fw-bold shadow-sm" style="background: #00a896; border: none;" data-bs-toggle="modal" data-bs-target="#addUserModal">
        <i class="fa-solid fa-user-plus me-1"></i> Add New User
    </button>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Full Name</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Created Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($u['username']) ?></td>
                            <td><?= htmlspecialchars($u['full_name']) ?></td>
                            <td>
                                <span class="badge <?= $u['role'] === 'admin' ? 'bg-danger' : 'bg-primary' ?>">
                                    <?= strtoupper($u['role']) ?>
                                </span>
                            </td>
                            <td>
                                <span class="badge <?= $u['status'] === 'active' ? 'bg-success' : 'bg-secondary' ?>">
                                    <?= ucfirst($u['status']) ?>
                                </span>
                            </td>
                            <td><small class="text-muted"><?= date('M d, Y', strtotime($u['created_at'])) ?></small></td>
                            <td>
                                <?php if ($u['id'] !== $_SESSION['user_id']): ?>
                                    <a href="index.php?page=users&toggle_status=1&user_id=<?= $u['id'] ?>" class="btn btn-sm btn-outline-dark fw-semibold">
                                        Toggle Status
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add User Modal -->
<div class="modal fade" id="addUserModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: #00a896;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-user-plus me-2"></i> Create Staff Account</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="add_user" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Username *</label>
                        <input type="text" name="username" class="form-control" placeholder="e.g. maria_cashier" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name *</label>
                        <input type="text" name="full_name" class="form-control" placeholder="e.g. Maria Santos" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Password *</label>
                        <input type="password" name="password" class="form-control" placeholder="Enter password" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role</label>
                        <select name="role" class="form-select">
                            <option value="staff" selected>Cashier / Staff</option>
                            <option value="admin">Administrator</option>
                        </select>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold" style="background: #00a896; border:none;">Create User</button>
                </div>
            </form>
        </div>
    </div>
</div>
