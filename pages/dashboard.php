<?php
// Aquamarine Laundry System - Dashboard Page

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

// Fetch KPI metrics
$total_sales = $pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM orders")->fetchColumn();
$active_orders = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status NOT IN ('Completed', 'Claimed')")->fetchColumn();
$ready_pickup = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status = 'Ready for Pickup'")->fetchColumn();
$total_customers = $pdo->query("SELECT COUNT(*) FROM customers")->fetchColumn();

// Fetch Recent Orders
$recent_orders = $pdo->query("
    SELECT o.*, c.name as customer_name 
    FROM orders o 
    JOIN customers c ON o.customer_id = c.id 
    ORDER BY o.id DESC LIMIT 5
")->fetchAll();

// Fetch Status Summary
$status_counts = $pdo->query("
    SELECT order_status, COUNT(*) as count 
    FROM orders 
    GROUP BY order_status
")->fetchAll(PDO::FETCH_KEY_PAIR);

// Audit log feed
$recent_audit = $pdo->query("SELECT * FROM audit_trail ORDER BY id DESC LIMIT 5")->fetchAll();
?>

<!-- KPI Overview Cards -->
<div class="row g-3 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-icon bg-aqua-primary">
                <i class="fa-solid fa-coins"></i>
            </div>
            <div>
                <div class="kpi-title">Total Sales Revenue</div>
                <div class="kpi-value"><?= formatCurrency($total_sales) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-icon bg-aqua-info">
                <i class="fa-solid fa-soap"></i>
            </div>
            <div>
                <div class="kpi-title">Active Laundry Orders</div>
                <div class="kpi-value"><?= number_format($active_orders) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-icon bg-aqua-success">
                <i class="fa-solid fa-box-open"></i>
            </div>
            <div>
                <div class="kpi-title">Ready for Pickup</div>
                <div class="kpi-value"><?= number_format($ready_pickup) ?></div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="kpi-card">
            <div class="kpi-icon bg-aqua-purple">
                <i class="fa-solid fa-users"></i>
            </div>
            <div>
                <div class="kpi-title">Registered Customers</div>
                <div class="kpi-value"><?= number_format($total_customers) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Charts and Recent Activity -->
<div class="row g-4 mb-4">
    <!-- Order Status Summary -->
    <div class="col-12 col-lg-8">
        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0 d-flex justify-content-between align-items-center">
                <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-chart-pie text-teal me-2"></i> Operational Status Breakdown</h5>
                <a href="index.php?page=orders" class="btn btn-sm btn-outline-primary fw-semibold">View All Orders</a>
            </div>
            <div class="card-body">
                <div class="row text-center mb-3">
                    <?php 
                    $statuses = ['Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Claimed', 'Completed'];
                    foreach ($statuses as $st): 
                        $count = $status_counts[$st] ?? 0;
                    ?>
                        <div class="col-6 col-md-3 mb-3">
                            <div class="p-2 rounded bg-light border">
                                <span class="badge-status status-<?= str_replace(' ', '', $st) ?> mb-1"><?= $st ?></span>
                                <div class="fs-4 fw-bold text-dark"><?= $count ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

                <h6 class="fw-bold text-dark mt-4 mb-3"><i class="fa-solid fa-clock-rotate-left me-1"></i> Recent Laundry Transactions</h6>
                <div class="table-responsive">
                    <table class="table table-hover align-middle table-custom m-0">
                        <thead>
                            <tr>
                                <th>Tracking #</th>
                                <th>Customer</th>
                                <th>Total</th>
                                <th>Payment</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_orders as $ord): ?>
                                <tr>
                                    <td class="fw-bold text-primary"><?= htmlspecialchars($ord['tracking_number']) ?></td>
                                    <td class="fw-semibold"><?= htmlspecialchars($ord['customer_name']) ?></td>
                                    <td class="fw-bold"><?= formatCurrency($ord['total_amount']) ?></td>
                                    <td>
                                        <span class="badge-status status-<?= str_replace(' ', '', $ord['payment_status']) ?>">
                                            <?= $ord['payment_status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="badge-status status-<?= str_replace(' ', '', $ord['order_status']) ?>">
                                            <?= $ord['order_status'] ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="index.php?page=receipt&id=<?= $ord['id'] ?>" class="btn btn-sm btn-light border text-dark" title="View Receipt">
                                            <i class="fa-solid fa-receipt"></i>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <!-- Audit Feed & Quick Actions -->
    <div class="col-12 col-lg-4">
        <div class="card border-0 shadow-sm rounded-3 mb-4">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-bolt text-warning me-2"></i> Quick Actions</h5>
            </div>
            <div class="card-body d-grid gap-2">
                <a href="index.php?page=orders" class="btn btn-primary py-2 fw-bold text-start" style="background: #00a896; border:none;">
                    <i class="fa-solid fa-circle-plus me-2"></i> Create New Laundry Order
                </a>
                <a href="index.php?page=customers" class="btn btn-outline-dark py-2 fw-semibold text-start">
                    <i class="fa-solid fa-user-plus me-2"></i> Register New Customer
                </a>
                <a href="index.php?page=payments" class="btn btn-outline-dark py-2 fw-semibold text-start">
                    <i class="fa-solid fa-hand-holding-dollar me-2"></i> Record Customer Payment
                </a>
            </div>
        </div>

        <div class="card border-0 shadow-sm rounded-3">
            <div class="card-header bg-white py-3 border-0">
                <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-list-ul text-info me-2"></i> System Activity Feed</h5>
            </div>
            <div class="card-body p-0">
                <ul class="list-group list-group-flush fs-7">
                    <?php foreach ($recent_audit as $log): ?>
                        <li class="list-group-item px-3 py-2.5">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong class="text-dark"><?= htmlspecialchars($log['username']) ?></strong>
                                <small class="text-muted"><?= date('H:i, M d', strtotime($log['timestamp'])) ?></small>
                            </div>
                            <div class="text-secondary mt-1"><?= htmlspecialchars($log['action']) ?>: <?= htmlspecialchars($log['details']) ?></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>
</div>
