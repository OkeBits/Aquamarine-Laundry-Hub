<?php
// Aquamarine Laundry System - Notification Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

// Fetch notification logs
$notifications = $pdo->query("
    SELECT n.*, c.name as customer_name, o.tracking_number
    FROM notifications n
    LEFT JOIN customers c ON n.customer_id = c.id
    LEFT JOIN orders o ON n.order_id = o.id
    ORDER BY n.id DESC
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-bell text-teal me-2"></i> SMS & Email Notification Module</h4>
        <small class="text-muted">Monitor automated SMS and email notifications dispatched to customers</small>
    </div>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Type</th>
                        <th>Recipient</th>
                        <th>Customer</th>
                        <th>Order Ref</th>
                        <th>Message Content</th>
                        <th>Status</th>
                        <th>Timestamp</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($notifications)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No notification logs recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($notifications as $n): ?>
                            <tr>
                                <td>
                                    <?php if ($n['type'] === 'SMS'): ?>
                                        <span class="badge bg-primary"><i class="fa-solid fa-comment-sms me-1"></i> SMS</span>
                                    <?php else: ?>
                                        <span class="badge bg-info"><i class="fa-solid fa-envelope me-1"></i> Email</span>
                                    <?php endif; ?>
                                </td>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($n['recipient']) ?></td>
                                <td><?= htmlspecialchars($n['customer_name'] ?: 'N/A') ?></td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($n['tracking_number'] ?: 'N/A') ?></td>
                                <td style="max-width: 300px;"><small class="text-muted"><?= htmlspecialchars($n['message']) ?></small></td>
                                <td><span class="badge bg-success-subtle text-success border border-success"><?= $n['status'] ?></span></td>
                                <td><small class="text-muted"><?= date('M d, Y h:i A', strtotime($n['sent_at'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
