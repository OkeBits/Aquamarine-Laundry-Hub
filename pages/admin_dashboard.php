<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

// Check if admin is logged in
if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

if (!isset($_SESSION['admin_username'])) {
    $_SESSION['admin_username'] = $_SESSION['username'] ?? 'admin';
}

$orderService = new OrderService();
$db = Database::getInstance();

$msg = '';
$error = '';

// Handle CSV Sales Report Export
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    $orders = $orderService->getAllOrdersWithCashier();
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=aquamarine_sales_report_' . date('Y-m-d') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tracking Number', 'Customer Name', 'Contact', 'Items Count', 'Total Amount (PHP)', 'Paid Amount (PHP)', 'Status', 'Cashier', 'Date']);
    
    foreach ($orders as $o) {
        fputcsv($output, [
            $o['order_number'],
            $o['name'],
            $o['phone'],
            $o['items_count'],
            number_format($o['total_amount'], 2, '.', ''),
            number_format($o['paid_amount'], 2, '.', ''),
            $o['order_status'],
            $o['cashier_name'] ?: 'Staff',
            $o['created_at']
        ]);
    }
    fclose($output);
    exit;
}

$dateRange = $_GET['range'] ?? 'all';
$stats = $orderService->getOrderStats($dateRange);
$topServices = $orderService->getTopSellingServices();
$topCustomers = $orderService->getTopCustomers(5);
$overdueOrders = $orderService->getOverdueOrders(7);
$salesTrend = $orderService->getDailySalesTrend(7);
$analytics = $orderService->getAnalytics();
$recentOrders = $orderService->getAllOrdersWithCashier();
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;
$totalRecentOrders = count($recentOrders);
$totalPages = max(1, (int)ceil($totalRecentOrders / $limit));
$offset = ($page - 1) * $limit;
$paginatedRecentOrders = array_slice($recentOrders, $offset, $limit);

// Get per-cashier stats based on filter
$from = date('Y-m-d', strtotime('-30 days'));
if ($dateRange === 'today') $from = date('Y-m-d');
elseif ($dateRange === '7days') $from = date('Y-m-d', strtotime('-7 days'));
$to = date('Y-m-d');

$cashierSales = $orderService->getSalesByCashier($from, $to);

// Calculate Max Trend for SVG Bar Chart Scaling
$maxTrendAmount = 1.0;
foreach ($salesTrend as $st) {
    if ($st['amount'] > $maxTrendAmount) {
        $maxTrendAmount = $st['amount'];
    }
}
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Admin Dashboard</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link href="assets/css/offline-tailwind.css" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600;700&family=JetBrains+Mono:wght@500;600;700&display=swap" rel="stylesheet"/>
    <link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet"/>
    <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
    <script id="tailwind-config">
        tailwind.config = {
            darkMode: "class",
            theme: {
                extend: {
                    "colors": {
                        "primary": "#0058be",
                        "primary-container": "#2170e4",
                        "secondary": "#006c49",
                        "background": "#f8fafc",
                        "surface": "#f8fafc",
                        "on-surface": "#0f172a",
                        "on-surface-variant": "#475569",
                        "surface-container-lowest": "#ffffff",
                        "surface-container-low": "#f1f5f9"
                    },
                    "fontFamily": {
                        "headline": ["Outfit", "Plus Jakarta Sans", "sans-serif"],
                        "subheading": ["Plus Jakarta Sans", "sans-serif"],
                        "body": ["Inter", "sans-serif"],
                        "mono": ["JetBrains Mono", "monospace"]
                    }
                },
            },
        }
    </script>
    <style>
        * {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        body {
            font-family: 'Inter', sans-serif;
            letter-spacing: -0.01em;
        }
        h1, h2, h3, h4, .font-headline {
            font-family: 'Outfit', sans-serif;
            letter-spacing: -0.025em;
        }
        .material-symbols-outlined {
            font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24;
        }
        .signature-gradient {
            background: linear-gradient(135deg, #0058be 0%, #2170e4 100%);
        }
    </style>
</head>
<body class="bg-background font-body text-on-surface">

    <!-- Standard Header Ribbon -->
    <header class="bg-white border-b border-gray-200 px-6 py-3.5 sticky top-0 z-40 shadow-xs">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
            
            <div class="flex items-center gap-3">
                <img src="assets/img/logo.png" alt="Aquamarine Logo" class="w-10 h-10 object-contain rounded-xl shadow-2xs">
                <div>
                    <h1 class="text-lg font-black tracking-tight text-blue-700 font-headline uppercase leading-none">
                        AQUAMARINE LAUNDRY HUB
                    </h1>
                    <span class="text-3xs text-gray-500 font-semibold tracking-wider uppercase">Executive System Dashboard & Analytics</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <a href="admin_dashboard.php?action=export_csv" class="bg-emerald-50 text-emerald-800 border border-emerald-300 font-black px-3.5 py-1.5 rounded-xl text-xs flex items-center gap-1 hover:bg-emerald-100 transition-all shadow-2xs">
                    <span class="material-symbols-outlined text-sm">download</span> Export Sales CSV
                </a>

                <div class="hidden sm:flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-3xs font-extrabold px-3 py-1.5 rounded-full shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>System Active & Connected</span>
                </div>

                <div class="flex items-center gap-2.5 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-xl">
                    <div class="w-7 h-7 bg-blue-100 text-blue-700 rounded-lg flex items-center justify-center font-black text-xs">
                        👑
                    </div>
                    <div class="text-left">
                        <div class="text-xs font-black text-gray-900 leading-tight"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['admin_username'] ?? 'Executive Manager') ?></div>
                        <div class="text-3xs text-gray-500 font-bold uppercase">Master Admin</div>
                    </div>
                </div>

                <a href="logout.php" class="bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 transition-all">
                    <span class="material-symbols-outlined text-xs">logout</span> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="px-6 py-8 max-w-7xl mx-auto space-y-8">

        <!-- Message & Error Alert (Top-Left Fixed Popup, 5-Second Auto Fade Out) -->
        <?php if (!empty($msg) || !empty($error)): ?>
            <div id="toast_notification" class="fixed top-6 left-6 z-[9999] max-w-md w-full space-y-3 transition-all duration-500 transform translate-x-0">
                <?php if (!empty($msg)): ?>
                    <div class="bg-emerald-50 border-l-4 border-emerald-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                        <p class="text-emerald-800 font-bold flex items-center gap-2 text-sm">
                            <span class="material-symbols-outlined text-base text-emerald-600">check_circle</span> <?= htmlspecialchars($msg) ?>
                        </p>
                        <button onclick="closeToastNotification()" class="text-emerald-500 hover:text-emerald-800 font-bold text-lg leading-none cursor-pointer">&times;</button>
                    </div>
                <?php endif; ?>

                <?php if (!empty($error)): ?>
                    <div class="bg-rose-50 border-l-4 border-rose-500 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3">
                        <p class="text-rose-800 font-bold flex items-center gap-2 text-sm">
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

        <!-- Smart Attention & Alerts Bar -->
        <?php if (!empty($overdueOrders)): ?>
            <div class="bg-amber-50 border-l-4 border-amber-500 p-4 rounded-xl flex justify-between items-center shadow-2xs">
                <div class="flex items-center gap-3">
                    <span class="material-symbols-outlined text-amber-600 text-2xl">warning</span>
                    <div>
                        <h4 class="font-bold text-amber-900 text-sm">Unclaimed Laundry Warning (<?= count($overdueOrders) ?> Orders)</h4>
                        <p class="text-xs text-amber-800">The following customer items have been ready for pickup for over 7 days.</p>
                    </div>
                </div>
                <div class="flex gap-2">
                    <?php foreach (array_slice($overdueOrders, 0, 2) as $oo): ?>
                        <span class="px-2.5 py-1 bg-amber-100 text-amber-900 text-xs rounded font-bold font-mono"><?= $oo['tracking_number'] ?> (<?= htmlspecialchars($oo['customer_name']) ?>)</span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Top Navigation Ribbon Tabs -->
        <div class="flex gap-4 border-b border-gray-200 overflow-x-auto">
            <a href="admin_dashboard.php" class="pb-3 px-4 font-bold text-blue-700 border-b-2 border-blue-700 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">dashboard</span> Executive Dashboard
            </a>
            <a href="cashier_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">point_of_sale</span> Cashier POS Terminal
            </a>
            <a href="customers.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">groups</span> Customer Directory
            </a>
            <a href="sales_reports.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">show_chart</span> Sales & Analytics
            </a>
            <a href="admin_settings.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">tune</span> System Settings & Portals
            </a>
        </div>

        <!-- Dedicated Analytics Control Bar (Separated from Navigation Bar) -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mb-6 bg-white p-4 rounded-2xl border border-gray-200 shadow-2xs">
            <div class="flex items-center gap-2">
                <span class="material-symbols-outlined text-blue-700 text-xl">analytics</span>
                <h3 class="text-sm font-extrabold font-headline text-gray-900">Financial Performance & Analytics</h3>
            </div>
            
            <div class="flex items-center gap-2 flex-wrap">
                <span class="text-xs font-bold text-gray-600 uppercase tracking-wider">Date Filter:</span>
                <a href="admin_dashboard.php?range=today" class="px-3.5 py-1.5 text-xs font-extrabold rounded-xl transition-all <?= $dateRange === 'today' ? 'bg-blue-700 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Today</a>
                <a href="admin_dashboard.php?range=7days" class="px-3.5 py-1.5 text-xs font-extrabold rounded-xl transition-all <?= $dateRange === '7days' ? 'bg-blue-700 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Last 7 Days</a>
                <a href="admin_dashboard.php?range=30days" class="px-3.5 py-1.5 text-xs font-extrabold rounded-xl transition-all <?= $dateRange === '30days' ? 'bg-blue-700 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Last 30 Days</a>
                <a href="admin_dashboard.php?range=all" class="px-3.5 py-1.5 text-xs font-extrabold rounded-xl transition-all <?= $dateRange === 'all' ? 'bg-blue-700 text-white shadow-2xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">All Time</a>
            </div>
        </div>

        <!-- Key Financial Summary Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">
            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm signature-gradient text-white">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-white/80 text-sm mb-2">Gross Revenue Collected</p>
                        <p class="text-4xl font-bold font-headline">₱<?php echo number_format($stats['revenue'], 2); ?></p>
                    </div>
                    <span class="material-symbols-outlined text-5xl opacity-20">trending_up</span>
                </div>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-emerald-500">
                <div class="flex justify-between items-center">
                    <div>
                        <p class="text-on-surface-variant text-sm mb-2">Total Net Sales Revenue</p>
                        <p class="text-4xl font-bold text-emerald-700 font-headline">₱<?php echo number_format($analytics['total_sales'], 2); ?></p>
                    </div>
                    <span class="material-symbols-outlined text-5xl opacity-20 text-emerald-600">paid</span>
                </div>
                <p class="text-on-surface-variant text-xs mt-3">Calculated total order payments</p>
            </div>
        </div>

        <!-- 7-Day Revenue Trend SVG Bar Chart -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm mb-8">
            <div class="flex justify-between items-center mb-6">
                <div>
                    <h2 class="text-lg font-bold font-headline flex items-center gap-2">
                        <span class="material-symbols-outlined text-primary">bar_chart</span> 7-Day Sales & Revenue Trend
                    </h2>
                    <p class="text-xs text-on-surface-variant">Daily sales breakdown over the last week</p>
                </div>
                <span class="text-xs font-bold bg-blue-50 text-primary px-3 py-1 rounded-full border border-blue-200">Peak Trend Chart</span>
            </div>

            <!-- SVG Bar Chart -->
            <div class="h-44 flex items-end justify-between gap-4 pt-6 px-4 border-b border-outline pb-2">
                <?php foreach ($salesTrend as $st): 
                    $heightPct = min(100, max(15, round(($st['amount'] / $maxTrendAmount) * 100)));
                ?>
                    <div class="flex-1 flex flex-col items-center gap-2 h-full justify-end group">
                        <span class="text-2xs font-bold text-gray-500 opacity-0 group-hover:opacity-100 transition-opacity">₱<?= number_format($st['amount'], 0) ?></span>
                        <div class="w-full signature-gradient rounded-t-lg transition-all duration-300 group-hover:brightness-110" style="height: <?= $heightPct ?>%;"></div>
                        <span class="text-xs font-bold text-on-surface-variant mt-1"><?= $st['day'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Leaderboard & Best Selling Row -->
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
            
            <!-- Top 5 VIP Customers Leaderboard -->
            <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm">
                <h2 class="text-lg font-bold font-headline mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-500">workspace_premium</span> Top 5 VIP Customers (Lifetime Spend)
                </h2>

                <div class="space-y-3">
                    <?php if (empty($topCustomers)): ?>
                        <p class="text-xs text-gray-500">No customer records yet.</p>
                    <?php else: ?>
                        <?php foreach ($topCustomers as $idx => $tc): ?>
                            <div class="p-3 bg-surface-container-low rounded-xl border flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <span class="w-7 h-7 rounded-full bg-amber-100 text-amber-800 text-xs font-bold flex items-center justify-center font-headline">#<?= $idx + 1 ?></span>
                                    <div>
                                        <strong class="text-on-surface text-sm block"><?= htmlspecialchars($tc['name']) ?></strong>
                                        <span class="text-xs text-gray-500"><?= htmlspecialchars($tc['contact']) ?> | <?= $tc['total_orders'] ?> Orders</span>
                                    </div>
                                </div>
                                <span class="font-bold text-primary font-mono text-sm">₱<?= number_format($tc['total_spend'], 2) ?></span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Top 3 Best-Selling Services -->
            <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm">
                <h2 class="text-lg font-bold font-headline mb-4 flex items-center gap-2">
                    <span class="material-symbols-outlined text-primary">local_laundry_service</span> Top Performing Laundry Packages
                </h2>

                <div class="space-y-3">
                    <?php if (empty($topServices)): ?>
                        <p class="text-xs text-gray-500">No service sales recorded yet.</p>
                    <?php else: ?>
                        <?php foreach ($topServices as $index => $ts): ?>
                            <div class="p-3.5 bg-surface-container-low rounded-xl border flex justify-between items-center">
                                <div class="flex items-center gap-3">
                                    <span class="w-7 h-7 rounded-full bg-blue-100 text-blue-800 text-xs font-bold flex items-center justify-center font-headline">#<?= $index + 1 ?></span>
                                    <div>
                                        <strong class="text-on-surface text-sm block"><?= htmlspecialchars($ts['service_name']) ?></strong>
                                        <span class="text-xs text-gray-500 bg-gray-200 px-2 py-0.5 rounded-full font-bold"><?= htmlspecialchars($ts['category']) ?></span>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <span class="font-bold text-primary font-mono text-sm block">₱<?= number_format($ts['total_revenue'], 2) ?></span>
                                    <span class="text-2xs text-gray-500 font-bold"><?= $ts['total_orders'] ?> Orders Sold</span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Cashier Performance Breakdown Table -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border border-outline mb-8">
            <div class="flex items-center gap-3 mb-4">
                <div class="w-10 h-10 bg-secondary/10 text-secondary rounded-xl flex items-center justify-center">
                    <span class="material-symbols-outlined">badge</span>
                </div>
                <div>
                    <h2 class="text-xl font-bold text-on-surface font-headline">Cashier Performance (<?= ucfirst($dateRange) ?>)</h2>
                    <p class="text-xs text-on-surface-variant">Revenue and orders processed per cashier</p>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-surface-container-low text-on-surface-variant font-semibold border-b">
                            <th class="p-3">Cashier ID</th>
                            <th class="p-3">Name</th>
                            <th class="p-3">Total Orders</th>
                            <th class="p-3">Completed</th>
                            <th class="p-3">Revenue Collected</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php foreach ($cashierSales as $cs): ?>
                            <tr class="hover:bg-surface-container-low transition-colors">
                                <td class="p-3 font-bold text-primary font-mono"><?= htmlspecialchars($cs['cashier_id_number'] ?? 'N/A') ?></td>
                                <td class="p-3 font-semibold text-on-surface"><?= htmlspecialchars($cs['full_name']) ?></td>
                                <td class="p-3"><?= (int)$cs['total_orders'] ?></td>
                                <td class="p-3"><?= (int)$cs['completed_orders'] ?></td>
                                <td class="p-3 font-bold text-primary">₱<?= number_format((float)$cs['total_revenue'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Orders Directory -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border border-outline space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200/80">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-50 text-blue-700 rounded-xl flex items-center justify-center border border-blue-200 shadow-2xs">
                        <span class="material-symbols-outlined text-xl">receipt_long</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-black font-headline text-gray-900">Recent Orders Directory</h2>
                        <p class="text-2xs text-gray-500 font-medium">Shop-wide order history and tracking status (10 items per page)</p>
                    </div>
                </div>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-100/90 text-gray-700 font-extrabold uppercase text-3xs tracking-wider border-b border-gray-200">
                            <th class="px-4 py-3.5 text-left">Order #</th>
                            <th class="px-4 py-3.5 text-left">Customer</th>
                            <th class="px-4 py-3.5 text-left">Phone</th>
                            <th class="px-4 py-3.5 text-center">Items</th>
                            <th class="px-4 py-3.5 text-right">Amount</th>
                            <th class="px-4 py-3.5 text-center">Status</th>
                            <th class="px-4 py-3.5 text-center">Date</th>
                            <th class="px-4 py-3.5 text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200/80 text-xs">
                        <?php if (empty($paginatedRecentOrders)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-10 text-gray-500 font-medium">No recent orders found.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($paginatedRecentOrders as $order): ?>
                                <tr class="hover:bg-blue-50/40 transition-colors">
                                    <td class="px-4 py-3.5 font-black text-blue-700 font-mono tracking-tight"><?= htmlspecialchars($order['order_number']) ?></td>
                                    <td class="px-4 py-3.5 font-extrabold text-gray-900"><?= htmlspecialchars($order['name']) ?></td>
                                    <td class="px-4 py-3.5 text-gray-500 text-3xs font-medium"><?= htmlspecialchars($order['phone']) ?></td>
                                    <td class="px-4 py-3.5 text-center font-bold text-gray-800"><?= $order['items_count'] ?></td>
                                    <td class="px-4 py-3.5 text-right font-black text-gray-900 font-mono">₱<?= number_format($order['total_amount'], 2) ?></td>
                                    <td class="px-4 py-3.5 text-center align-middle">
                                         <?php 
                                             $curSt = strtolower($order['status'] ?? '');
                                             $badgeStyle = 'bg-blue-100 text-blue-950 border-blue-300';
                                             if (strpos($curSt, 'pending') !== false) {
                                                 $badgeStyle = 'bg-amber-100 text-amber-950 border-amber-300';
                                             } elseif (strpos($curSt, 'ready') !== false) {
                                                 $badgeStyle = 'bg-emerald-100 text-emerald-950 border-emerald-300';
                                             } elseif (strpos($curSt, 'delivery') !== false) {
                                                 $badgeStyle = 'bg-sky-100 text-sky-950 border-sky-300';
                                             }
                                         ?>
                                         <span class="px-3 py-1 rounded-full text-3xs font-black uppercase tracking-wider border shadow-2xs inline-block <?= $badgeStyle ?>">
                                             <?= htmlspecialchars($order['status']) ?>
                                         </span>
                                     </td>
                                    <td class="px-4 py-3.5 text-center text-gray-500 text-3xs font-medium"><?= date('M d, Y', strtotime($order['created_at'])) ?></td>
                                    <td class="px-4 py-3.5 text-center">
                                        <a href="receipt.php?token=<?= htmlspecialchars($order['receipt_token'] ?? $order['id']) ?>" class="text-blue-700 font-bold hover:bg-blue-100 bg-blue-50 px-2.5 py-1.5 rounded-lg text-xs border border-blue-200 inline-flex items-center gap-1 transition-all">
                                            <span class="material-symbols-outlined text-xs">print</span> View Receipt
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination Bar (10 per page) -->
            <?php if ($totalPages > 1): ?>
                <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-gray-200">
                    <p class="text-xs text-gray-500 font-medium">
                        Showing <span class="font-bold text-gray-900"><?= $offset + 1 ?></span> to <span class="font-bold text-gray-900"><?= min($offset + $limit, $totalRecentOrders) ?></span> of <span class="font-bold text-gray-900"><?= $totalRecentOrders ?></span> recent orders
                    </p>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): ?>
                            <a href="admin_dashboard.php?range=<?= urlencode($dateRange) ?>&page=<?= $page - 1 ?>" class="px-3 py-1.5 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 shadow-2xs flex items-center gap-1 transition-all">
                                <span class="material-symbols-outlined text-xs">arrow_back</span> Previous
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-400 cursor-not-allowed flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">arrow_back</span> Previous
                            </span>
                        <?php endif; ?>

                        <div class="flex items-center gap-1 px-2">
                            <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                                <?php if ($p == $page): ?>
                                    <span class="w-8 h-8 rounded-xl bg-blue-700 text-white font-black text-xs flex items-center justify-center shadow-2xs"><?= $p ?></span>
                                <?php elseif ($p <= 3 || $p >= $totalPages - 2 || abs($p - $page) <= 1): ?>
                                    <a href="admin_dashboard.php?range=<?= urlencode($dateRange) ?>&page=<?= $p ?>" class="w-8 h-8 rounded-xl border border-gray-300 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 flex items-center justify-center transition-all"><?= $p ?></a>
                                <?php elseif ($p == 4 && $page > 4): ?>
                                    <span class="text-gray-400 font-bold text-xs px-1">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): ?>
                            <a href="admin_dashboard.php?range=<?= urlencode($dateRange) ?>&page=<?= $page + 1 ?>" class="px-3 py-1.5 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 shadow-2xs flex items-center gap-1 transition-all">
                                Next <span class="material-symbols-outlined text-xs">arrow_forward</span>
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-400 cursor-not-allowed flex items-center gap-1">
                                Next <span class="material-symbols-outlined text-xs">arrow_forward</span>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>

    </main>

    <!-- Scroll Position Preservation Script -->
    <script>
        (function() {
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('a, button, input[type="submit"]');
                if (btn) {
                    const text = (btn.innerText || btn.value || '').toLowerCase();
                    const href = (btn.getAttribute('href') || '').toLowerCase();
                    const id = (btn.id || '').toLowerCase();
                    
                    if (text.includes('previous') || text.includes('next') || href.includes('page=') || id.includes('prev') || id.includes('next') || href.includes('range=') || href.includes('filter=')) {
                        sessionStorage.setItem('aquamarine_scroll_pos', window.scrollY);
                    }
                }
            });

            window.addEventListener('DOMContentLoaded', function() {
                const savedPos = sessionStorage.getItem('aquamarine_scroll_pos');
                if (savedPos !== null) {
                    window.scrollTo({
                        top: parseInt(savedPos, 10),
                        behavior: 'instant'
                    });
                    sessionStorage.removeItem('aquamarine_scroll_pos');
                }
            });
        })();
    </script>
</body>
</html>
