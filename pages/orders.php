<?php
// Aquamarine Laundry System - Order Processing & Status Tracking Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

// Fetch customers and services for dropdown
$customers = $pdo->query("SELECT * FROM customers ORDER BY name ASC")->fetchAll();
$services = $pdo->query("SELECT * FROM services ORDER BY category ASC, service_name ASC")->fetchAll();

// Handle Order Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_order'])) {
    $customer_id = (int)$_POST['customer_id'];
    $service_id = (int)$_POST['service_id'];
    $weight_kg = max(1.0, (float)$_POST['weight_kg']);
    $notes = trim($_POST['notes'] ?? '');
    $discount = max(0.0, (float)($_POST['discount'] ?? 0));
    $initial_payment = max(0.0, (float)($_POST['initial_payment'] ?? 0));
    $payment_method = $_POST['payment_method'] ?? 'Cash';

    // Service pricing calculation
    $stmtSvc = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmtSvc->execute([$service_id]);
    $svc = $stmtSvc->fetch();

    if ($customer_id && $svc) {
        $price = $svc['price_per_kg'];
        $billable_weight = max($weight_kg, $svc['min_kg']);
        $subtotal = round($billable_weight * $price, 2);
        $total_amount = max(0.0, $subtotal - $discount);
        
        $payment_status = 'Pending';
        if ($initial_payment >= $total_amount && $total_amount > 0) {
            $payment_status = 'Fully Paid';
        } elseif ($initial_payment > 0) {
            $payment_status = 'Partially Paid';
        }

        $tracking_number = 'AQ-' . date('Y') . '-' . str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $estimated_pickup = date('Y-m-d H:i:s', strtotime('+2 days'));

        $stmtOrd = $pdo->prepare("
            INSERT INTO orders (tracking_number, customer_id, total_weight, subtotal, discount, total_amount, paid_amount, payment_status, order_status, estimated_pickup, notes, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?)
        ");
        $stmtOrd->execute([$tracking_number, $customer_id, $weight_kg, $subtotal, $discount, $total_amount, $initial_payment, $payment_status, $estimated_pickup, $notes, $_SESSION['user_id']]);
        $order_id = $pdo->lastInsertId();

        // Line Item
        $stmtItem = $pdo->prepare("INSERT INTO order_items (order_id, service_id, item_details, weight_kg, qty, unit_price, subtotal) VALUES (?, ?, ?, ?, 1, ?, ?)");
        $stmtItem->execute([$order_id, $service_id, $svc['service_name'] . " ({$weight_kg}kg)", $weight_kg, $price, $subtotal]);

        // Record Initial Payment if any
        if ($initial_payment > 0) {
            $stmtPay = $pdo->prepare("INSERT INTO payments (order_id, amount, payment_method, reference_no, received_by) VALUES (?, ?, ?, ?, ?)");
            $stmtPay->execute([$order_id, $initial_payment, $payment_method, 'INIT-' . rand(1000,9999), $_SESSION['user_id']]);
        }

        // Notification Log
        $stmtCust = $pdo->prepare("SELECT * FROM customers WHERE id = ?");
        $stmtCust->execute([$customer_id]);
        $cust = $stmtCust->fetch();
        if ($cust && $cust['contact']) {
            $msg = "Aquamarine Laundry: Your order {$tracking_number} has been received! Total: ₱{$total_amount}. Est. Pickup: " . date('M d, h:i A', strtotime($estimated_pickup));
            logNotification($pdo, $customer_id, $order_id, 'SMS', $cust['contact'], $msg);
        }

        logAudit($pdo, 'Order Created', 'Order Processing', "Created order $tracking_number for {$cust['name']}");
        header("Location: index.php?page=receipt&id=$order_id&success=Order+$tracking_number+created+successfully.");
        exit;
    }
}

// Handle Order Status Update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $order_id = (int)$_POST['order_id'];
    $new_status = $_POST['new_status'];
    
    $allowed_statuses = ['Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Claimed', 'Completed'];
    $completed_statuses = ['Claimed', 'Completed'];

    if (in_array($new_status, $allowed_statuses)) {
        $chkStmt = $pdo->prepare("SELECT total_amount, paid_amount FROM orders WHERE id = ?");
        $chkStmt->execute([$order_id]);
        $chkOrd = $chkStmt->fetch();
        $chkBal = $chkOrd ? ((float)$chkOrd['total_amount'] - (float)$chkOrd['paid_amount']) : 0;

        if (in_array($new_status, $completed_statuses) && $chkBal > 0.001) {
            header("Location: index.php?page=orders&error=Cannot+mark+order+as+Completed+because+unpaid+balance+remains.+Please+settle+payment+first.");
            exit;
        }

        $stmt = $pdo->prepare("UPDATE orders SET order_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $order_id]);

        logAudit($pdo, 'Status Updated', 'Order Tracking', "Order ID #$order_id status updated to $new_status");
        header("Location: index.php?page=orders&success=Order+status+updated+to+$new_status.");
        exit;
    }
}

// Search and Filter Orders
$search = trim($_GET['search'] ?? '');
$status_filter = trim($_GET['status'] ?? '');

$query = "SELECT o.*, c.name as customer_name, c.contact as customer_contact 
          FROM orders o 
          JOIN customers c ON o.customer_id = c.id WHERE 1=1";
$params = [];

if (!empty($search)) {
    $query .= " AND (o.tracking_number LIKE ? OR c.name LIKE ? OR c.contact LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status_filter)) {
    $query .= " AND o.order_status = ?";
    $params[] = $status_filter;
}

$query .= " ORDER BY o.id DESC";
$stmtOrders = $pdo->prepare($query);
$stmtOrders->execute($params);
$orders_list = $stmtOrders->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-jug-detergent text-teal me-2"></i> Order Processing & Status Tracking</h4>
        <small class="text-muted">Create laundry orders, compute charges, and manage status workflow</small>
    </div>
    <button class="btn btn-primary fw-bold shadow-sm" style="background: #00a896; border: none;" data-bs-toggle="modal" data-bs-target="#createOrderModal">
        <i class="fa-solid fa-circle-plus me-1"></i> Create Laundry Order
    </button>
</div>

<!-- Search & Filter Card -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-body">
        <form method="GET" action="index.php" class="row g-2">
            <input type="hidden" name="page" value="orders">
            <div class="col-12 col-md-6">
                <input type="text" name="search" class="form-control" placeholder="Search tracking #, customer name, contact..." value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-12 col-md-4">
                <select name="status" class="form-select">
                    <option value="">-- Filter Status (All) --</option>
                    <?php foreach (['Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Claimed', 'Completed'] as $st): ?>
                        <option value="<?= $st ?>" <?= $status_filter === $st ? 'selected' : '' ?>><?= $st ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <button type="submit" class="btn btn-dark w-100 fw-bold">Filter</button>
            </div>
        </form>
    </div>
</div>

<!-- Orders Table -->
<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Tracking #</th>
                        <th>Customer</th>
                        <th>Weight</th>
                        <th>Total Amount</th>
                        <th>Paid</th>
                        <th>Payment Status</th>
                        <th>Order Status</th>
                        <th>Pickup Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders_list)): ?>
                        <tr><td colspan="9" class="text-center py-4 text-muted">No laundry orders found.</td></tr>
                    <?php else: ?>
                        <?php foreach ($orders_list as $ord): ?>
                            <tr>
                                <td class="fw-bold text-primary"><?= htmlspecialchars($ord['tracking_number']) ?></td>
                                <td>
                                    <div class="fw-semibold text-dark"><?= htmlspecialchars($ord['customer_name']) ?></div>
                                    <small class="text-muted"><i class="fa-solid fa-phone me-1"></i><?= htmlspecialchars($ord['customer_contact']) ?></small>
                                </td>
                                <td><span class="badge bg-light text-dark border"><?= $ord['total_weight'] ?> kg</span></td>
                                <td class="fw-bold text-dark"><?= formatCurrency($ord['total_amount']) ?></td>
                                <td class="text-success fw-semibold"><?= formatCurrency($ord['paid_amount']) ?></td>
                                <td>
                                    <span class="badge-status status-<?= str_replace(' ', '', $ord['payment_status']) ?>">
                                        <?= $ord['payment_status'] ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                        <?php $ordBal = (float)$ord['total_amount'] - (float)$ord['paid_amount']; ?>
                                        <select name="new_status" class="form-select form-select-sm fw-bold border-0 bg-light" onchange="this.form.submit()">
                                            <?php foreach (['Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Claimed', 'Completed'] as $st): ?>
                                                <?php 
                                                    $isComp = in_array($st, ['Claimed', 'Completed']);
                                                    $isDisabled = ($isComp && $ordBal > 0.001);
                                                ?>
                                                <option value="<?= $st ?>" <?= $ord['order_status'] === $st ? 'selected' : '' ?> <?= $isDisabled ? 'disabled text-muted' : '' ?>>
                                                    <?= $st ?><?= $isDisabled ? ' 🔒 (Settle ₱' . number_format($ordBal, 2) . ' First)' : '' ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                </td>
                                <td><small class="text-muted"><?= date('M d, h:i A', strtotime($ord['estimated_pickup'])) ?></small></td>
                                <td>
                                    <a href="index.php?page=receipt&id=<?= $ord['id'] ?>" class="btn btn-sm btn-light border" title="Print Official Receipt">
                                        <i class="fa-solid fa-receipt text-dark"></i>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Create Order Modal -->
<div class="modal fade" id="createOrderModal" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: #00a896;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-cart-plus me-2"></i> Create New Laundry Order</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="create_order" value="1">
                    
                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Select Customer *</label>
                            <input type="text" id="orders_customer_search" oninput="filterOrdersCustomerSelect()" placeholder="🔍 Search customer..." class="form-control form-control-sm mb-1">
                            <select name="customer_id" id="orders_customer_select" class="form-select" required>
                                <option value="">-- Choose Customer --</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?> (<?= $c['contact'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <script>
                            function filterOrdersCustomerSelect() {
                                const input = document.getElementById('orders_customer_search');
                                const select = document.getElementById('orders_customer_select');
                                if (!input || !select) return;
                                const q = input.value.toLowerCase().trim();
                                Array.from(select.options).forEach(opt => {
                                    if (opt.value === "") return;
                                    opt.hidden = (q !== "" && !opt.textContent.toLowerCase().includes(q));
                                });
                            }
                            </script>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Laundry Service *</label>
                            <select name="service_id" class="form-select" required>
                                <option value="">-- Select Service --</option>
                                <?php foreach ($services as $s): ?>
                                    <option value="<?= $s['id'] ?>">
                                        <?= htmlspecialchars($s['service_name']) ?> - ₱<?= number_format($s['price_per_kg'], 2) ?>/kg (Min: <?= $s['min_kg'] ?>kg)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Total Weight (kg) *</label>
                            <input type="number" step="0.1" min="0.5" name="weight_kg" class="form-control" value="3.0" required>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Discount (₱)</label>
                            <input type="number" step="0.01" min="0" name="discount" class="form-control" value="0.00">
                        </div>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Initial Payment Received (₱)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" class="form-control" placeholder="0.00">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold">Payment Method</label>
                            <select name="payment_method" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="GCash">GCash</option>
                                <option value="Card">Card</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Special Instructions / Item Notes</label>
                        <textarea name="notes" class="form-control" rows="2" placeholder="e.g., Separate colored clothes, delicate fabric steam iron..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold" style="background: #00a896; border:none;">Process Order & Generate Receipt</button>
                </div>
            </form>
        </div>
    </div>
</div>
