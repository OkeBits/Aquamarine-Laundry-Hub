<?php
// Aquamarine Laundry System - Payment Management Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

// Record Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['record_payment'])) {
    $order_id = (int)$_POST['order_id'];
    $amount = (float)$_POST['amount'];
    $payment_method = $_POST['payment_method'];
    $reference_no = trim($_POST['reference_no'] ?? '');

    if ($order_id && $amount > 0) {
        $stmtOrd = $pdo->prepare("SELECT * FROM orders WHERE id = ?");
        $stmtOrd->execute([$order_id]);
        $ord = $stmtOrd->fetch();

        if ($ord) {
            $new_paid = $ord['paid_amount'] + $amount;
            $new_status = 'Partially Paid';
            if ($new_paid >= $ord['total_amount']) {
                $new_status = 'Fully Paid';
            }

            // Update order payment status
            $stmtUpd = $pdo->prepare("UPDATE orders SET paid_amount = ?, payment_status = ? WHERE id = ?");
            $stmtUpd->execute([$new_paid, $new_status, $order_id]);

            // Insert payment log
            $stmtPay = $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_no, received_by) VALUES (?, ?, ?, ?, ?)");
            $stmtPay->execute([$order_id, $amount, $payment_method, $reference_no ?: 'REC-' . rand(1000,9999), $_SESSION['user_id']]);

            logAudit($pdo, 'Payment Recorded', 'Payments', "Recorded ₱$amount for Order {$ord['tracking_number']}");
            header("Location: index.php?page=payments&success=Payment+of+₱$amount+recorded+successfully.");
            exit;
        }
    }
}

// Fetch pending/partially paid orders for payment modal
$unpaid_orders = $pdo->query("
    SELECT o.*, c.name as customer_name 
    FROM orders o 
    JOIN customers c ON o.customer_id = c.id 
    WHERE o.payment_status != 'Fully Paid' 
    ORDER BY o.id DESC
")->fetchAll();

// Fetch payment history
$payments = $pdo->query("
    SELECT p.*, o.tracking_number, c.name as customer_name, u.username as cashier_name
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN customers c ON o.customer_id = c.id
    LEFT JOIN users u ON p.received_by = u.id
    ORDER BY p.id DESC
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-file-invoice-dollar text-teal me-2"></i> Payment Management</h4>
        <small class="text-muted">Record customer payments, calculate balances, and review transaction history</small>
    </div>
    <button class="btn btn-primary fw-bold shadow-sm" style="background: #00a896; border: none;" data-bs-toggle="modal" data-bs-target="#recordPaymentModal">
        <i class="fa-solid fa-hand-holding-dollar me-1"></i> Record New Payment
    </button>
</div>

<!-- Payment History Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-receipt text-success me-2"></i> Payment History Logs</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Receipt Ref #</th>
                        <th>Order Tracking #</th>
                        <th>Customer</th>
                        <th>Amount Paid</th>
                        <th>Payment Method</th>
                        <th>Received By</th>
                        <th>Date & Time</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($payments)): ?>
                        <tr><td colspan="7" class="text-center py-4 text-muted">No payment logs recorded yet.</td></tr>
                    <?php else: ?>
                        <?php foreach ($payments as $p): ?>
                            <tr>
                                <td class="fw-bold text-dark"><?= htmlspecialchars($p['reference_no']) ?></td>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($p['tracking_number']) ?></td>
                                <td class="fw-semibold"><?= htmlspecialchars($p['customer_name']) ?></td>
                                <td class="fw-bold text-success fs-6"><?= formatCurrency($p['amount']) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($p['payment_method']) ?></span></td>
                                <td><?= htmlspecialchars($p['cashier_name'] ?? 'System') ?></td>
                                <td><small class="text-muted"><?= date('M d, Y h:i A', strtotime($p['payment_date'])) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal fade" id="recordPaymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: #00a896;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-hand-holding-dollar me-2"></i> Record Customer Payment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="record_payment" value="1">

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Select Order (Unpaid / Partially Paid) *</label>
                        <select name="order_id" class="form-select" required>
                            <option value="">-- Choose Order --</option>
                            <?php foreach ($unpaid_orders as $uo): 
                                $bal = $uo['total_amount'] - $uo['paid_amount'];
                            ?>
                                <option value="<?= $uo['id'] ?>">
                                    <?= $uo['tracking_number'] ?> - <?= htmlspecialchars($uo['customer_name']) ?> (Balance: ₱<?= number_format($bal, 2) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Amount (₱) *</label>
                        <input type="number" step="0.01" min="1" name="amount" class="form-control" placeholder="0.00" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Payment Method</label>
                        <select name="payment_method" class="form-select">
                            <option value="Cash">Cash</option>
                            <option value="GCash">GCash</option>
                            <option value="Card">Card</option>
                            <option value="Bank Transfer">Bank Transfer</option>
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Reference / OR Number (Optional)</label>
                        <input type="text" name="reference_no" class="form-control" placeholder="e.g. GCash Ref # or Cash OR #">
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold" style="background: #00a896; border:none;">Record Payment</button>
                </div>
            </form>
        </div>
    </div>
</div>
