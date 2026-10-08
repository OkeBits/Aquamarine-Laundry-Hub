<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    header('Location: login.php');
    exit;
}

$orderService = new OrderService();
$db = Database::getInstance();

$role = $_SESSION['role'] ?? 'cashier';
$currentUserId = (int)($_SESSION['user_id'] ?? 0);

// Filter Reset Handler
if (isset($_GET['reset_filters'])) {
    unset($_SESSION['sales_analytics_filters']);
    header('Location: sales_reports.php');
    exit;
}

// Filter Parameters & Session Persistence
if (!empty($_GET)) {
    $hasExplicitPreset = isset($_GET['preset']) && $_GET['preset'] !== '' && $_GET['preset'] !== 'custom';
    $fromDateInput = trim($_GET['from_date'] ?? '');
    $toDateInput = trim($_GET['to_date'] ?? '');

    if (!empty($fromDateInput) || !empty($toDateInput)) {
        $preset = 'custom';
        $fromDate = $fromDateInput;
        $toDate = $toDateInput;
    } elseif ($hasExplicitPreset) {
        $preset = trim($_GET['preset']);
        $fromDate = '';
        $toDate = '';
    } elseif (isset($_GET['from_date']) && empty($fromDateInput) && empty($toDateInput)) {
        $preset = 'all';
        $fromDate = '';
        $toDate = '';
    } else {
        $preset = $_SESSION['sales_analytics_filters']['preset'] ?? '30days';
        $fromDate = $_SESSION['sales_analytics_filters']['from_date'] ?? '';
        $toDate = $_SESSION['sales_analytics_filters']['to_date'] ?? '';
    }

    $paymentMethod = trim($_GET['payment_method'] ?? ($_SESSION['sales_analytics_filters']['payment_method'] ?? 'all'));
    $cashierId = (int)($_GET['cashier_id'] ?? ($_SESSION['sales_analytics_filters']['cashier_id'] ?? 0));
    $search = trim($_GET['search'] ?? ($_SESSION['sales_analytics_filters']['search'] ?? ''));

    $_SESSION['sales_analytics_filters'] = [
        'preset' => $preset,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'payment_method' => $paymentMethod,
        'cashier_id' => $cashierId,
        'search' => $search
    ];
} elseif (isset($_SESSION['sales_analytics_filters']) && is_array($_SESSION['sales_analytics_filters'])) {
    $preset = $_SESSION['sales_analytics_filters']['preset'] ?? '30days';
    $fromDate = $_SESSION['sales_analytics_filters']['from_date'] ?? '';
    $toDate = $_SESSION['sales_analytics_filters']['to_date'] ?? '';
    $paymentMethod = $_SESSION['sales_analytics_filters']['payment_method'] ?? 'all';
    $cashierId = (int)($_SESSION['sales_analytics_filters']['cashier_id'] ?? 0);
    $search = $_SESSION['sales_analytics_filters']['search'] ?? '';
} else {
    $preset = '30days';
    $fromDate = '';
    $toDate = '';
    $paymentMethod = 'all';
    $cashierId = 0;
    $search = '';

    $_SESSION['sales_analytics_filters'] = [
        'preset' => $preset,
        'from_date' => $fromDate,
        'to_date' => $toDate,
        'payment_method' => $paymentMethod,
        'cashier_id' => $cashierId,
        'search' => $search
    ];
}



// Handle Preset Quick Date Ranges
if ($preset === 'today') {
    $fromDate = date('Y-m-d');
    $toDate = date('Y-m-d');
} elseif ($preset === 'yesterday') {
    $fromDate = date('Y-m-d', strtotime('-1 day'));
    $toDate = date('Y-m-d', strtotime('-1 day'));
} elseif ($preset === '7days') {
    $fromDate = date('Y-m-d', strtotime('-7 days'));
    $toDate = date('Y-m-d');
} elseif ($preset === '30days') {
    $fromDate = date('Y-m-d', strtotime('-30 days'));
    $toDate = date('Y-m-d');
} elseif ($preset === 'all') {
    $fromDate = '';
    $toDate = '';
}

// Handle CSV Export
if (isset($_GET['action']) && $_GET['action'] === 'export_report_csv') {
    $reportData = $orderService->getFilteredSalesReport($fromDate, $toDate, $paymentMethod, $cashierId, $search);
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=aquamarine_sales_report_' . date('Y-m-d_H-i-s') . '.csv');
    
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Tracking Number', 'Customer Name', 'Contact', 'Total Weight (kg)', 'Subtotal (PHP)', 'Discount (PHP)', 'Total Amount (PHP)', 'Paid Amount (PHP)', 'Payment Status', 'Order Status', 'Payment Method', 'Cashier', 'Date']);
    
    foreach ($reportData['orders'] as $o) {
        fputcsv($output, [
            $o['tracking_number'],
            $o['customer_name'],
            $o['customer_contact'],
            $o['total_weight'],
            number_format($o['subtotal'], 2, '.', ''),
            number_format($o['discount'], 2, '.', ''),
            number_format($o['total_amount'], 2, '.', ''),
            number_format($o['paid_amount'], 2, '.', ''),
            $o['payment_status'],
            $o['order_status'],
            $o['payment_method'] ?: 'Cash',
            $o['cashier_name'] ?: 'Staff',
            $o['created_at']
        ]);
    }
    fclose($output);
    exit;
}

$report = $orderService->getFilteredSalesReport($fromDate, $toDate, $paymentMethod, $cashierId, $search);
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = max(5, (int)($_GET['limit'] ?? 10));
$allFilteredReportOrders = $report['orders'];
$totalReportOrdersCount = count($allFilteredReportOrders);
$totalPages = max(1, (int)ceil($totalReportOrdersCount / $limit));
$offset = ($page - 1) * $limit;
$reportOrders = array_slice($allFilteredReportOrders, $offset, $limit);

$cashCollected = 0.0;
$gcashCollected = 0.0;
$otherCollected = 0.0;
foreach ($allFilteredReportOrders as $ord) {
    $pm = strtolower(trim($ord['payment_method'] ?? 'cash'));
    $amt = (float)($ord['paid_amount'] ?? 0);
    if ($pm === 'gcash') {
        $gcashCollected += $amt;
    } elseif ($pm === 'cash' || empty($pm)) {
        $cashCollected += $amt;
    } else {
        $otherCollected += $amt;
    }
}

$allCashiers = $db->fetchAll("SELECT id, full_name, cashier_id_number FROM users ORDER BY full_name ASC");
$shopName = $orderService->getSetting('shop_name', 'Aquamarine Laundry');

// Prepare Daily Revenue Trend for SVG Line Graph
$dailyTrendData = [];
$dailyMap = [];
foreach ($report['orders'] as $ord) {
    $d = date('Y-m-d', strtotime($ord['created_at']));
    if (!isset($dailyMap[$d])) {
        $dailyMap[$d] = 0.0;
    }
    $dailyMap[$d] += (float)$ord['paid_amount'];
}
ksort($dailyMap);

if (empty($dailyMap)) {
    for ($i = 6; $i >= 0; $i--) {
        $d = date('Y-m-d', strtotime("-$i days"));
        $dailyTrendData[$d] = 0.0;
    }
} else {
    $dailyTrendData = $dailyMap;
}

$maxTrendAmount = max(array_values($dailyTrendData) ?: [1]);
if ($maxTrendAmount <= 0) $maxTrendAmount = 100;
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Sales & Financial Reports</title>
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
        @media print {
            body * {
                visibility: hidden !important;
            }
            #zReadingPrintArea, #zReadingPrintArea * {
                visibility: visible !important;
            }
            #zReadingPrintArea {
                position: absolute !important;
                left: 0 !important;
                top: 0 !important;
                width: 100% !important;
                max-width: 190mm !important;
                margin: 0 auto !important;
                padding: 12mm !important;
                font-family: 'Courier New', Courier, monospace !important;
                color: #000000 !important;
                background: #ffffff !important;
                box-sizing: border-box !important;
                display: block !important;
            }
            @page {
                size: letter portrait;
                margin: 10mm;
            }
        }
    </style>
</head>
<body class="bg-background font-body text-on-surface">

    <!-- Standard Header Ribbon -->
    <header class="bg-white border-b border-gray-200 px-6 py-3.5 sticky top-0 z-40 shadow-xs no-print">
        <div class="max-w-7xl mx-auto flex flex-wrap items-center justify-between gap-4">
            
            <div class="flex items-center gap-3">
                <img src="assets/img/logo.png" alt="Aquamarine Logo" class="w-10 h-10 object-contain rounded-xl shadow-2xs">
                <div>
                    <h1 class="text-lg font-black tracking-tight text-blue-700 font-headline uppercase leading-none">
                        AQUAMARINE LAUNDRY HUB
                    </h1>
                    <span class="text-3xs text-gray-500 font-semibold tracking-wider uppercase">Sales Analytics & Financial Audit Log</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="window.print()" class="bg-gray-100 text-gray-800 border border-gray-300 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 hover:bg-gray-200 transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-sm">print</span> Print Z-Reading
                </button>

                <a href="sales_reports.php?action=export_report_csv&from_date=<?= urlencode($fromDate) ?>&to_date=<?= urlencode($toDate) ?>&payment_method=<?= urlencode($paymentMethod) ?>&cashier_id=<?= $cashierId ?>&search=<?= urlencode($search) ?>" class="bg-emerald-50 text-emerald-800 border border-emerald-300 font-black px-3.5 py-1.5 rounded-xl text-xs flex items-center gap-1 hover:bg-emerald-100 transition-all shadow-2xs">
                    <span class="material-symbols-outlined text-sm">download</span> Export CSV
                </a>

                <div class="hidden sm:flex items-center gap-2 bg-emerald-50 border border-emerald-200 text-emerald-800 text-3xs font-extrabold px-3 py-1.5 rounded-full shadow-2xs">
                    <span class="w-2 h-2 rounded-full bg-emerald-500 animate-pulse"></span>
                    <span>System Active & Connected</span>
                </div>

                <div class="flex items-center gap-2.5 bg-gray-50 border border-gray-200 px-3 py-1.5 rounded-xl">
                    <div class="w-7 h-7 bg-blue-100 text-blue-700 rounded-lg flex items-center justify-center font-black text-xs">
                        <?= $role === 'admin' ? '👑' : '💼' ?>
                    </div>
                    <div class="text-left">
                        <div class="text-xs font-black text-gray-900 leading-tight"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Staff User') ?></div>
                        <div class="text-3xs text-gray-500 font-bold uppercase"><?= $role === 'admin' ? 'Master Admin' : 'POS Cashier' ?></div>
                    </div>
                </div>

                <a href="logout.php" class="bg-rose-50 text-rose-700 border border-rose-200 hover:bg-rose-100 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 transition-all">
                    <span class="material-symbols-outlined text-xs">logout</span> Logout
                </a>
            </div>
        </div>
    </header>

    <main class="px-6 py-8 max-w-7xl mx-auto space-y-8">

        <!-- Top Navigation Ribbon Tabs -->
        <div class="flex gap-4 border-b border-gray-200 overflow-x-auto no-print">
            <?php if ($role === 'admin'): ?>
                <a href="admin_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">dashboard</span> Executive Dashboard
                </a>
            <?php endif; ?>
            <a href="cashier_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">point_of_sale</span> Cashier POS Terminal
            </a>
            <a href="customers.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">groups</span> Customer Directory
            </a>
            <a href="sales_reports.php" class="pb-3 px-4 font-bold text-blue-700 border-b-2 border-blue-700 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">show_chart</span> Sales & Analytics
            </a>
            <?php if ($role === 'admin'): ?>
                <a href="admin_settings.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">tune</span> System Settings & Portals
                </a>
            <?php endif; ?>
        </div>

        <!-- Filter & Search Controls Bar -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm mb-8 no-print">
            <h2 class="text-sm font-bold font-headline mb-4 flex items-center gap-2">
                <span class="material-symbols-outlined text-primary">filter_alt</span> Search & Financial Filters
            </h2>

            <form method="GET" action="sales_reports.php" onsubmit="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="space-y-4">
                <!-- Preset Quick Buttons -->
                <div class="flex flex-wrap items-center gap-2 mb-2">
                    <span class="text-xs font-bold text-gray-500 mr-2">Quick Ranges:</span>
                    <?php
                    $baseFilterParams = [
                        'search' => $search,
                        'payment_method' => $paymentMethod,
                        'cashier_id' => $cashierId
                    ];
                    ?>
                    <a href="sales_reports.php?<?= http_build_query(array_merge($baseFilterParams, ['preset' => 'today'])) ?>" onclick="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="px-3 py-1 rounded-lg text-xs font-bold <?= $preset === 'today' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Today</a>
                    <a href="sales_reports.php?<?= http_build_query(array_merge($baseFilterParams, ['preset' => 'yesterday'])) ?>" onclick="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="px-3 py-1 rounded-lg text-xs font-bold <?= $preset === 'yesterday' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Yesterday</a>
                    <a href="sales_reports.php?<?= http_build_query(array_merge($baseFilterParams, ['preset' => '7days'])) ?>" onclick="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="px-3 py-1 rounded-lg text-xs font-bold <?= $preset === '7days' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Last 7 Days</a>
                    <a href="sales_reports.php?<?= http_build_query(array_merge($baseFilterParams, ['preset' => '30days'])) ?>" onclick="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="px-3 py-1 rounded-lg text-xs font-bold <?= $preset === '30days' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">Last 30 Days</a>
                    <a href="sales_reports.php?<?= http_build_query(array_merge($baseFilterParams, ['preset' => 'all'])) ?>" onclick="sessionStorage.setItem('aquamarine_scroll_to_results', '1');" class="px-3 py-1 rounded-lg text-xs font-bold <?= $preset === 'all' ? 'bg-primary text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' ?>">All Time</a>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-5 gap-4 items-end">
                    <div>
                        <label class="block text-xs font-bold mb-1">Search Customer / Tracking #</label>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search..." class="text-xs w-full">
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">From Date</label>
                        <input type="date" name="from_date" value="<?= htmlspecialchars($fromDate) ?>" class="text-xs w-full">
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">To Date</label>
                        <input type="date" name="to_date" value="<?= htmlspecialchars($toDate) ?>" class="text-xs w-full">
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">Payment Method</label>
                        <select name="payment_method" class="w-full font-bold text-xs">
                            <option value="all" <?= $paymentMethod === 'all' ? 'selected' : '' ?>>All Payment Methods</option>
                            <option value="Cash" <?= $paymentMethod === 'Cash' ? 'selected' : '' ?>>Cash</option>
                            <option value="GCash" <?= $paymentMethod === 'GCash' ? 'selected' : '' ?>>GCash</option>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">Account Filter</label>
                        <select name="cashier_id" class="w-full font-bold text-xs">
                            <option value="0" <?= $cashierId === 0 ? 'selected' : '' ?>>All Staff / Cashiers</option>
                            <?php foreach ($allCashiers as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= $cashierId === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['full_name']) ?> (<?= htmlspecialchars($c['cashier_id_number'] ?: 'ID') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="flex justify-between items-center pt-2">
                    <a href="sales_reports.php?reset_filters=1" class="text-xs font-bold text-gray-500 hover:text-gray-800 underline flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm">restart_alt</span> Reset Filters
                    </a>
                    <button type="submit" class="signature-gradient text-white font-bold px-6 py-2 rounded-xl text-xs shadow-md flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-sm">search</span> Filter Sales Analytics
                    </button>
                </div>
            </form>
        </div>

        <!-- Offline SVG Revenue Trend Line Graph -->
        <div id="sales_analytics_results" class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm mb-8 no-print">
            <div class="flex flex-col sm:flex-row justify-between sm:items-center gap-3 mb-6 pb-4 border-b border-gray-200/80">
                <div>
                    <h2 class="text-base font-bold font-headline flex items-center gap-2 text-gray-900">
                        <span class="material-symbols-outlined text-primary">trending_up</span> Revenue Trend Curve (Daily Sales Collection)
                    </h2>
                    <p class="text-xs text-gray-500 font-medium">Visual trend line of cash & GCash payments collected per day</p>
                </div>
                <div class="flex items-center gap-2">
                    <span class="text-xs font-bold text-blue-900 bg-blue-50 px-3 py-1.5 rounded-xl border border-blue-200 shadow-2xs flex items-center gap-1.5">
                        <span class="w-2.5 h-2.5 rounded-full bg-blue-600"></span> Daily Revenue (₱)
                    </span>
                    <span class="text-xs font-extrabold text-emerald-700 bg-emerald-50 px-3 py-1.5 rounded-xl border border-emerald-200 shadow-2xs">
                        Peak: ₱<?= number_format($maxTrendAmount, 2) ?>
                    </span>
                </div>
            </div>

            <!-- SVG Line Graph Container with Gridlines & Y-Axis Scale -->
            <div class="w-full bg-surface-container-low/40 rounded-2xl p-5 border border-gray-200">
                <?php
                $keys = array_keys($dailyTrendData);
                $values = array_values($dailyTrendData);
                $count = count($keys);
                
                $points = [];
                $widthStep = $count > 1 ? (480 / ($count - 1)) : 480;
                
                for ($i = 0; $i < $count; $i++) {
                    $x = 75 + ($i * $widthStep);
                    $normY = 160 - (($values[$i] / $maxTrendAmount) * 120);
                    $points[] = "$x,$normY";
                }
                $polylinePoints = implode(' ', $points);
                ?>

                <svg viewBox="0 0 600 210" class="w-full h-auto overflow-visible">
                    <!-- Y-Axis Grid Lines & Currency Scale Labels -->
                    <!-- Top Line (Peak) -->
                    <line x1="70" y1="40" x2="570" y2="40" stroke="#cbd5e1" stroke-dasharray="4"/>
                    <text x="62" y="44" text-anchor="end" fill="#64748b" font-size="10" font-weight="700" font-family="sans-serif">₱<?= number_format($maxTrendAmount, 0) ?></text>

                    <!-- Mid Line (50%) -->
                    <line x1="70" y1="100" x2="570" y2="100" stroke="#e2e8f0" stroke-dasharray="4"/>
                    <text x="62" y="104" text-anchor="end" fill="#94a3b8" font-size="10" font-weight="600" font-family="sans-serif">₱<?= number_format($maxTrendAmount / 2, 0) ?></text>

                    <!-- Base Line (0) -->
                    <line x1="70" y1="160" x2="570" y2="160" stroke="#94a3b8" stroke-width="1.5"/>
                    <text x="62" y="164" text-anchor="end" fill="#64748b" font-size="10" font-weight="700" font-family="sans-serif">₱0</text>

                    <!-- Gradient Area Under Curve -->
                    <defs>
                        <linearGradient id="revenueGrad" x1="0" y1="0" x2="0" y2="1">
                            <stop offset="0%" stop-color="#0058be" stop-opacity="0.3"/>
                            <stop offset="100%" stop-color="#0058be" stop-opacity="0.0"/>
                        </linearGradient>
                    </defs>

                    <!-- Shaded Area Under Polyline -->
                    <?php if ($count > 0): ?>
                        <polygon points="75,160 <?= $polylinePoints ?> <?= 75 + (($count-1)*$widthStep) ?>,160" fill="url(#revenueGrad)"/>
                    <?php endif; ?>

                    <!-- Polyline Curve -->
                    <polyline fill="none" stroke="#0058be" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" points="<?= $polylinePoints ?>"/>

                    <!-- Data Nodes & Value Badges -->
                    <?php for ($i = 0; $i < $count; $i++): 
                        $coords = explode(',', $points[$i]);
                        $cx = (float)$coords[0];
                        $cy = (float)$coords[1];
                        $amt = $values[$i];
                        $dateLabel = date('M d', strtotime($keys[$i]));
                    ?>
                        <!-- Circle Node -->
                        <circle cx="<?= $cx ?>" cy="<?= $cy ?>" r="5.5" fill="#0058be" stroke="#ffffff" stroke-width="2.5" class="transition-all hover:r-8 cursor-pointer">
                            <title><?= $dateLabel ?>: ₱<?= number_format($amt, 2) ?></title>
                        </circle>

                        <!-- Currency Text Badge above node -->
                        <?php if ($amt > 0 || $count <= 7): ?>
                            <text x="<?= $cx ?>" y="<?= max(25, $cy - 10) ?>" text-anchor="middle" fill="<?= $amt == $maxTrendAmount ? '#0058be' : '#475569' ?>" font-size="9" font-weight="<?= $amt == $maxTrendAmount ? '800' : '600' ?>" font-family="sans-serif">
                                ₱<?= number_format($amt, 0) ?>
                            </text>
                        <?php endif; ?>

                        <!-- X-Axis Date Labels under base line -->
                        <text x="<?= $cx ?>" y="180" text-anchor="middle" fill="#64748b" font-size="10" font-weight="600" font-family="sans-serif">
                            <?= date('M d', strtotime($keys[$i])) ?>
                        </text>
                    <?php endfor; ?>
                </svg>
            </div>
        </div>

        <!-- Financial KPI Summary Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-primary">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Gross Sales Billed</p>
                        <p class="text-3xl font-black text-on-surface font-headline">₱<?= number_format($report['gross_sales'], 2) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-primary/10 text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Total order charges</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-emerald-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Collected Cash / GCash</p>
                        <p class="text-3xl font-black text-emerald-600 font-headline">₱<?= number_format($report['total_collected'], 2) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">account_balance_wallet</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Actual payments received</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-amber-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Unpaid Balance Due</p>
                        <p class="text-3xl font-black text-amber-600 font-headline">₱<?= number_format($report['total_unpaid'], 2) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-amber-100 text-amber-600 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">pending_actions</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Receivables balance</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-indigo-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Average Order Value</p>
                        <p class="text-3xl font-black text-indigo-700 font-headline">₱<?= number_format($report['average_order_value'], 2) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-indigo-100 text-indigo-700 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">analytics</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs"><?= $report['order_count'] ?> Total Transactions</p>
            </div>
        </div>

        <!-- Detailed Sales Transactions Table -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm space-y-4">
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-4 border-b border-gray-200/80">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-50 text-blue-700 rounded-xl flex items-center justify-center border border-blue-200 shadow-2xs">
                        <span class="material-symbols-outlined text-xl">receipt</span>
                    </div>
                    <div>
                        <h2 class="text-lg font-black font-headline text-gray-900">Detailed Sales Transaction History</h2>
                        <p class="text-2xs text-gray-500 font-medium">Complete record of laundry sales, cash collection, and balances (10 items per page)</p>
                    </div>
                </div>
                <span class="text-xs font-bold text-gray-600 bg-gray-100 px-3 py-1.5 rounded-xl border border-gray-200">
                    Total Filtered Records: <?= $totalReportOrdersCount ?>
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-100/90 text-gray-700 font-extrabold uppercase text-3xs tracking-wider border-b border-gray-200">
                            <th class="px-4 py-3.5 text-left">Tracking #</th>
                            <th class="px-4 py-3.5 text-left">Customer</th>
                            <th class="px-4 py-3.5 text-right">Weight & Mode</th>
                            <th class="px-4 py-3.5 text-center">Payment Method</th>
                            <th class="px-4 py-3.5 text-right">Order Billed</th>
                            <th class="px-4 py-3.5 text-right">Amount Paid</th>
                            <th class="px-4 py-3.5 text-right">Balance Due</th>
                            <th class="px-4 py-3.5 text-center">Payment Status</th>
                            <th class="px-4 py-3.5 text-center">Cashier</th>
                            <th class="px-4 py-3.5 text-center">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200/80 text-xs">
                        <?php if (empty($reportOrders)): ?>
                            <tr>
                                <td colspan="10" class="text-center py-10 text-gray-500 font-medium">No sales transactions found for the selected filter parameters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($reportOrders as $ord): 
                                $bal = (float)$ord['total_amount'] - (float)$ord['paid_amount'];
                                $isDelivery = (($ord['service_type'] ?? '') === 'delivery' || (float)($ord['delivery_fee'] ?? 0) > 0);
                            ?>
                                <tr class="hover:bg-blue-50/40 transition-colors">
                                    <td class="px-4 py-3.5 font-black text-blue-700 font-mono tracking-tight text-xs"><?= htmlspecialchars($ord['tracking_number']) ?></td>
                                    <td class="px-4 py-3.5 font-medium text-left">
                                        <div class="text-gray-900 font-extrabold text-xs"><?= htmlspecialchars($ord['customer_name']) ?></div>
                                        <div class="text-gray-500 text-3xs font-medium"><?= htmlspecialchars($ord['customer_contact'] ?: 'N/A') ?></div>
                                    </td>
                                    <td class="px-4 py-3.5 text-right">
                                        <div class="font-bold text-gray-900"><?= number_format($ord['total_weight'], 1) ?> kg</div>
                                        <?php if ($isDelivery): ?>
                                            <div class="text-3xs text-amber-800 font-bold">🚚 Doorstep (+₱<?= number_format($ord['delivery_fee'], 2) ?>)</div>
                                        <?php else: ?>
                                            <div class="text-3xs text-gray-500 font-medium">🏬 Store Pick-up</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-center">
                                        <span class="px-2.5 py-1 rounded-md text-3xs font-black uppercase <?= ($ord['payment_method'] ?: 'Cash') === 'Cash' ? 'bg-emerald-100 text-emerald-900 border border-emerald-300' : 'bg-blue-100 text-blue-900 border border-blue-300' ?>">
                                            <?= htmlspecialchars($ord['payment_method'] ?: 'Cash') ?>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-black text-gray-900 font-mono">₱<?= number_format($ord['total_amount'], 2) ?></td>
                                    <td class="px-4 py-3.5 text-right font-extrabold text-emerald-600 font-mono">₱<?= number_format($ord['paid_amount'], 2) ?></td>
                                    <td class="px-4 py-3.5 text-right font-extrabold font-mono <?= $bal > 0 ? 'text-amber-600' : 'text-gray-400' ?>">
                                        ₱<?= number_format(max(0, $bal), 2) ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-center">
                                        <?php if ($bal <= 0): ?>
                                            <span class="px-2 py-0.5 rounded text-3xs font-black bg-emerald-100 text-emerald-800 border border-emerald-300">✅ Fully Paid</span>
                                        <?php elseif ($ord['paid_amount'] > 0): ?>
                                            <span class="px-2 py-0.5 rounded text-3xs font-black bg-amber-100 text-amber-800 border border-amber-300">⚠️ Partial</span>
                                        <?php else: ?>
                                            <span class="px-2 py-0.5 rounded text-3xs font-black bg-rose-100 text-rose-800 border border-rose-300">🔴 Unpaid</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-center font-bold text-gray-800 text-xs"><?= htmlspecialchars($ord['cashier_name'] ?: 'Staff') ?></td>
                                    <td class="px-4 py-3.5 text-center text-gray-500 text-3xs font-medium"><?= date('M d, Y h:i A', strtotime($ord['created_at'])) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Detailed Sales Transaction History Pagination Bar -->
            <?php $queryParams = $_GET; ?>
            <div class="flex flex-col sm:flex-row items-center justify-between gap-4 pt-4 border-t border-gray-200">
                <div class="flex flex-wrap items-center gap-3">
                    <p class="text-xs text-gray-500 font-medium">
                        Showing <span class="font-bold text-gray-900"><?= $totalReportOrdersCount > 0 ? $offset + 1 : 0 ?></span> to <span class="font-bold text-gray-900"><?= min($offset + $limit, $totalReportOrdersCount) ?></span> of <span class="font-bold text-gray-900"><?= $totalReportOrdersCount ?></span> sales records
                    </p>

                    <!-- Per Page Limit Selector -->
                    <form method="GET" class="inline-flex items-center gap-1.5 text-xs text-gray-500">
                        <?php foreach ($_GET as $k => $v): ?>
                            <?php if (!in_array($k, ['limit', 'page'])): ?>
                                <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <span class="text-3xs font-bold text-gray-400 uppercase tracking-wider">Per Page:</span>
                        <select name="limit" onchange="this.form.submit()" class="text-xs font-bold py-1 px-2 border border-gray-300 rounded-lg bg-white focus:ring-2 focus:ring-blue-500 cursor-pointer">
                            <option value="10" <?= $limit == 10 ? 'selected' : '' ?>>10</option>
                            <option value="25" <?= $limit == 25 ? 'selected' : '' ?>>25</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100</option>
                        </select>
                    </form>
                </div>

                <?php if ($totalPages >= 1): ?>
                    <div class="flex items-center gap-1">
                        <?php if ($page > 1): 
                            $queryParams['page'] = $page - 1;
                        ?>
                            <a href="sales_reports.php?<?= http_build_query($queryParams) ?>" class="px-3 py-1.5 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 shadow-2xs flex items-center gap-1 transition-all">
                                <span class="material-symbols-outlined text-xs">arrow_back</span> Previous
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-400 cursor-not-allowed flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs">arrow_back</span> Previous
                            </span>
                        <?php endif; ?>

                        <div class="flex items-center gap-1 px-2">
                            <?php for ($p = 1; $p <= $totalPages; $p++): 
                                $queryParams['page'] = $p;
                            ?>
                                <?php if ($p == $page): ?>
                                    <span class="w-8 h-8 rounded-xl bg-blue-700 text-white font-black text-xs flex items-center justify-center shadow-2xs"><?= $p ?></span>
                                <?php elseif ($p <= 3 || $p >= $totalPages - 2 || abs($p - $page) <= 1): ?>
                                    <a href="sales_reports.php?<?= http_build_query($queryParams) ?>" class="w-8 h-8 rounded-xl border border-gray-300 bg-white text-gray-700 font-bold text-xs hover:bg-gray-50 flex items-center justify-center transition-all"><?= $p ?></a>
                                <?php elseif ($p == 4 && $page > 4): ?>
                                    <span class="text-gray-400 font-bold text-xs px-1">...</span>
                                <?php endif; ?>
                            <?php endfor; ?>
                        </div>

                        <?php if ($page < $totalPages): 
                            $queryParams['page'] = $page + 1;
                        ?>
                            <a href="sales_reports.php?<?= http_build_query($queryParams) ?>" class="px-3 py-1.5 rounded-xl border border-gray-300 bg-white text-xs font-bold text-gray-700 hover:bg-gray-50 shadow-2xs flex items-center gap-1 transition-all">
                                Next <span class="material-symbols-outlined text-xs">arrow_forward</span>
                            </a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 rounded-xl border border-gray-200 bg-gray-50 text-xs font-bold text-gray-400 cursor-not-allowed flex items-center gap-1">
                                Next <span class="material-symbols-outlined text-xs">arrow_forward</span>
                            </span>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </main>

    <!-- OFFICIAL PRINTABLE Z-READING REPORT (DESIGNED FOR SHORT/LETTER/A4 BOND PAPER) -->
    <div id="zReadingPrintArea" class="hidden print:block font-mono text-xs text-black bg-white p-6 border-2 border-dashed border-gray-400 max-w-2xl mx-auto space-y-4">
        <!-- Header -->
        <div class="text-center border-b-2 border-black pb-3">
            <h2 class="text-xl font-black uppercase tracking-wider"><?= htmlspecialchars($shopName) ?></h2>
            <p class="text-2xs uppercase font-bold"><?= htmlspecialchars($orderService->getSetting('shop_address', '123 Rizal Ave, Metro Manila')) ?></p>
            <p class="text-2xs font-bold">TEL: <?= htmlspecialchars($orderService->getSetting('shop_phone', '0917-123-4567')) ?></p>
            <div class="mt-2 text-sm font-black uppercase tracking-widest bg-black text-white py-1">
                *** OFFICIAL Z-READING SHIFT REPORT ***
            </div>
        </div>

        <!-- Meta Info -->
        <table class="w-full text-2xs font-bold border-b border-black pb-2">
            <tr>
                <td>REPORT PERIOD:</td>
                <td class="text-right"><?= $fromDate ? date('M d, Y', strtotime($fromDate)) : 'Beginning' ?> to <?= $toDate ? date('M d, Y', strtotime($toDate)) : 'Present' ?></td>
            </tr>
            <tr>
                <td>DATE GENERATED:</td>
                <td class="text-right"><?= date('Y-m-d h:i:s A') ?></td>
            </tr>
            <tr>
                <td>AUDIT CASHIER / USER:</td>
                <td class="text-right"><?= htmlspecialchars($_SESSION['full_name'] ?? 'System Admin') ?></td>
            </tr>
            <tr>
                <td>FILTER PAYMENT METHOD:</td>
                <td class="text-right"><?= strtoupper($paymentMethod) ?></td>
            </tr>
        </table>

        <!-- FINANCIAL RECONCILIATION SUMMARY -->
        <div class="border-t-2 border-b-2 border-black py-3 space-y-3">
            <div class="text-xs font-black uppercase tracking-wider text-center bg-gray-100 py-1 border border-black">
                FINANCIAL RECONCILIATION SUMMARY
            </div>

            <!-- SECTION 1: SALES & REVENUE OVERVIEW -->
            <div>
                <div class="text-2xs font-black uppercase text-gray-700 mb-1">1. Sales & Revenue Overview</div>
                <table class="w-full text-xs space-y-1">
                    <tr>
                        <td>Total Billed Sales (Gross Total):</td>
                        <td class="text-right font-mono font-bold">₱<?= number_format($report['gross_sales'], 2) ?></td>
                    </tr>
                    <tr class="text-rose-800">
                        <td>Less: Outstanding Receivables (Unpaid Balance):</td>
                        <td class="text-right font-mono font-bold">- ₱<?= number_format($report['total_unpaid'], 2) ?></td>
                    </tr>
                    <tr class="font-black border-t border-dashed border-gray-400">
                        <td>NET PAYMENTS COLLECTED:</td>
                        <td class="text-right font-mono text-sm">₱<?= number_format($report['total_collected'], 2) ?></td>
                    </tr>
                </table>
            </div>

            <!-- SECTION 2: PAYMENT METHOD BREAKDOWN -->
            <div class="border-t border-gray-300 pt-2">
                <div class="text-2xs font-black uppercase text-gray-700 mb-1">2. Payment Collection Breakdown</div>
                <table class="w-full text-xs space-y-1">
                    <tr>
                        <td>💵 Cash in Drawer (Physical Cash):</td>
                        <td class="text-right font-mono font-bold">₱<?= number_format($cashCollected, 2) ?></td>
                    </tr>
                    <tr>
                        <td>📱 GCash E-Wallet (Digital Transfer):</td>
                        <td class="text-right font-mono font-bold">₱<?= number_format($gcashCollected, 2) ?></td>
                    </tr>
                    <?php if ($otherCollected > 0): ?>
                    <tr>
                        <td>💳 Other Payment Methods:</td>
                        <td class="text-right font-mono font-bold">₱<?= number_format($otherCollected, 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="font-black border-t border-dashed border-gray-400 text-sm">
                        <td>TOTAL SHIFT CASH & GCASH INFLOW:</td>
                        <td class="text-right font-mono">₱<?= number_format($report['total_collected'], 2) ?></td>
                    </tr>
                </table>
            </div>

            <!-- SECTION 3: AUDIT & OPERATIONAL STATS -->
            <div class="border-t border-gray-300 pt-2">
                <div class="text-2xs font-black uppercase text-gray-700 mb-1">3. Shift Performance & Audit Metrics</div>
                <table class="w-full text-xs space-y-1">
                    <tr>
                        <td>Total Orders Processed:</td>
                        <td class="text-right font-mono font-bold"><?= number_format($report['order_count']) ?> Orders</td>
                    </tr>
                    <tr>
                        <td>Average Sales Per Order:</td>
                        <td class="text-right font-mono font-bold">₱<?= number_format($report['average_order_value'], 2) ?></td>
                    </tr>
                    <tr>
                        <td>Collection Rate (% Paid):</td>
                        <td class="text-right font-mono font-bold">
                            <?= $report['gross_sales'] > 0 ? number_format(($report['total_collected'] / $report['gross_sales']) * 100, 1) : '0.0' ?>%
                        </td>
                    </tr>
                </table>
            </div>
        </div>

        <!-- Signature Block -->
        <div class="pt-6 grid grid-cols-2 gap-8 text-center text-3xs font-bold uppercase">
            <div>
                <div class="border-b border-black mb-1 mt-6"></div>
                <span>CASHIER ON DUTY (SIGNATURE)</span>
            </div>
            <div>
                <div class="border-b border-black mb-1 mt-6"></div>
                <span>STORE OWNER (SIGNATURE)</span>
            </div>
        </div>

        <!-- Footer -->
        <div class="text-center text-3xs text-gray-600 pt-4 border-t border-gray-300">
            <p>*** END OF OFFICIAL Z-READING REPORT ***</p>
            <p>Aquamarine Laundry POS Terminal Audit Log • Cleanliness & Care Guaranteed</p>
        </div>
    </div>

    <!-- Scroll Position Preservation Script -->
    <script>
        (function() {
            document.addEventListener('click', function(e) {
                const btn = e.target.closest('a, button');
                if (btn) {
                    const text = (btn.innerText || btn.value || '').toLowerCase();
                    const href = (btn.getAttribute('href') || '').toLowerCase();
                    const id = (btn.id || '').toLowerCase();
                    
                    if (text.includes('previous') || text.includes('next') || href.includes('page=') || id.includes('prev') || id.includes('next')) {
                        sessionStorage.setItem('aquamarine_scroll_pos', window.scrollY);
                    }
                }
            });

            window.addEventListener('DOMContentLoaded', function() {
                if (sessionStorage.getItem('aquamarine_scroll_to_results') === '1') {
                    sessionStorage.removeItem('aquamarine_scroll_to_results');
                    const resultsEl = document.getElementById('sales_analytics_results');
                    if (resultsEl) {
                        setTimeout(function() {
                            resultsEl.scrollIntoView({ behavior: 'smooth', block: 'start' });
                        }, 100);
                        return;
                    }
                }

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
