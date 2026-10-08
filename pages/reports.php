<?php
// Aquamarine Laundry System - Reporting & Analytics Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireAdmin();

// Financial Summaries
$total_revenue = $pdo->query("SELECT COALESCE(SUM(paid_amount), 0) FROM orders")->fetchColumn();
$total_completed = $pdo->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('Completed', 'Claimed')")->fetchColumn();

// Daily Sales Report
$daily_sales = $pdo->query("
    SELECT DATE(created_at) as sale_date, COUNT(*) as order_count, SUM(total_amount) as gross_sales, SUM(paid_amount) as collected
    FROM orders
    GROUP BY DATE(created_at)
    ORDER BY sale_date DESC LIMIT 10
")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-chart-pie text-teal me-2"></i> Reports & Business Analytics</h4>
        <small class="text-muted">Analyze laundry revenue and order sales performance</small>
    </div>
</div>

<!-- Financial Summary KPI -->
<div class="row g-3 mb-4">
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm rounded-3 p-3 border-start border-4 border-success">
            <div class="text-muted fw-bold text-uppercase fs-7">Gross Revenue Collected</div>
            <div class="fs-3 fw-bold text-success mt-1"><?= formatCurrency($total_revenue) ?></div>
        </div>
    </div>
    <div class="col-12 col-md-6">
        <div class="card border-0 shadow-sm rounded-3 p-3 border-start border-4 border-primary">
            <div class="text-muted fw-bold text-uppercase fs-7">Completed Orders</div>
            <div class="fs-3 fw-bold text-primary mt-1"><?= number_format($total_completed) ?></div>
        </div>
    </div>
</div>

<!-- Daily Sales Breakdown -->
<div class="card border-0 shadow-sm rounded-3 mb-4">
    <div class="card-header bg-white py-3 border-0">
        <h5 class="m-0 fw-bold text-dark"><i class="fa-solid fa-calendar-days text-primary me-2"></i> Daily Sales Summary</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Orders Processed</th>
                        <th>Gross Sales</th>
                        <th>Cash Collected</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($daily_sales)): ?>
                        <tr><td colspan="4" class="text-center py-4 text-muted">No sales data available.</td></tr>
                    <?php else: ?>
                        <?php foreach ($daily_sales as $ds): ?>
                            <tr>
                                <td class="fw-bold text-dark"><?= date('M d, Y', strtotime($ds['sale_date'])) ?></td>
                                <td><span class="badge bg-light text-dark border"><?= $ds['order_count'] ?> Orders</span></td>
                                <td class="fw-bold"><?= formatCurrency($ds['gross_sales']) ?></td>
                                <td class="fw-bold text-success"><?= formatCurrency($ds['collected']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
