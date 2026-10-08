<?php
// Aquamarine Laundry System - Audit Trail Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

$audit_logs = $pdo->query("SELECT * FROM audit_trail ORDER BY id DESC LIMIT 100")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-shield-halved text-teal me-2"></i> System Audit Trail Logs</h4>
        <small class="text-muted">Security activity trail monitoring user operations, transaction modifications, and logins</small>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>User</th>
                        <th>Module</th>
                        <th>Action Performed</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($audit_logs)): ?>
                        <tr><td colspan="5" class="text-center py-4 text-muted">No audit logs recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($audit_logs as $log): ?>
                            <tr>
                                <td><small class="text-muted"><?= date('M d, Y h:i:s A', strtotime($log['timestamp'])) ?></small></td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($log['username']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($log['module']) ?></span></td>
                                <td class="fw-semibold text-primary"><?= htmlspecialchars($log['action']) ?></td>
                                <td><small class="text-muted"><?= htmlspecialchars($log['details']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
