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

$msg = '';
$error = '';
$role = $_SESSION['role'] ?? 'cashier';

// Handle Add Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    try {
        $cid = $orderService->addCustomer($name, $contact, $email, $address);
        $msg = "Customer '$name' registered successfully!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle Edit Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'edit_customer') {
    $id = (int)($_POST['customer_id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    $res = $orderService->updateCustomer($id, $name, $contact, $email, $address);
    if ($res['success']) {
        $msg = $res['message'];
    } else {
        $error = $res['message'];
    }
}

// Handle Delete Customer
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_customer') {
    $id = (int)$_POST['customer_id'];
    if ($id > 0) {
        $pdo = $db->getPdo();
        $pdo->exec("PRAGMA foreign_keys = OFF;");
        $db->execute("DELETE FROM customers WHERE id = ?", [$id]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $msg = "Customer record deleted successfully!";
    }
}

$search = trim($_GET['search'] ?? '');
$filter = trim($_GET['filter'] ?? 'all');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

$allCustomers = $db->fetchAll("
    SELECT 
        c.*,
        COUNT(o.id) as total_orders,
        SUM(CASE WHEN o.order_status IN ('Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed') THEN 1 ELSE 0 END) as completed_orders_count,
        COALESCE(SUM(o.paid_amount), 0) as lifetime_spend
    FROM customers c
    LEFT JOIN orders o ON o.customer_id = c.id
    WHERE c.name LIKE ? OR c.contact LIKE ? OR c.customer_code LIKE ?
    GROUP BY c.id
    ORDER BY c.id DESC
", ["%$search%", "%$search%", "%$search%"]);

$targetStamps = max(1, (int)($orderService->getSetting('loyalty_stamps_target', '11')));

foreach ($allCustomers as &$c) {
    $c['target_stamps'] = $targetStamps;
}
unset($c);

if ($filter === 'reward_ready') {
    $allCustomers = array_values(array_filter($allCustomers, function($c) use ($targetStamps) {
        $cnt = (int)($c['completed_orders_count'] ?? $c['total_orders'] ?? 0);
        return $cnt > 0 && ($cnt % $targetStamps === 0);
    }));
} elseif ($filter === 'vip') {
    $allCustomers = array_values(array_filter($allCustomers, function($c) {
        return (int)($c['completed_orders_count'] ?? $c['total_orders'] ?? 0) >= 10;
    }));
} elseif ($filter === 'new') {
    $allCustomers = array_values(array_filter($allCustomers, function($c) {
        return (int)($c['completed_orders_count'] ?? $c['total_orders'] ?? 0) <= 2;
    }));
}

$totalCustomersCount = count($allCustomers);
$totalPages = max(1, (int)ceil($totalCustomersCount / $limit));
$offset = ($page - 1) * $limit;
$customers = array_slice($allCustomers, $offset, $limit);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Registered Customers Directory</title>
    <link rel="icon" type="image/png" href="assets/img/logo.png">
    <link href="assets/css/offline-tailwind.css" rel="stylesheet"/>
    <link href="assets/css/material-symbols.css" rel="stylesheet"/>
    <script src="assets/js/tailwindcss.js"></script>
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
                    <span class="text-3xs text-gray-500 font-semibold tracking-wider uppercase">Registered Customer Profiles & Loyalty Cards</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="openAddCustomerModal()" class="bg-blue-700 hover:bg-blue-800 text-white font-bold px-3.5 py-1.5 rounded-xl text-xs flex items-center gap-1 transition-all shadow-2xs cursor-pointer">
                    <span class="material-symbols-outlined text-sm">person_add</span> + Register Customer
                </button>

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

        <!-- Top Navigation Ribbon Tabs -->
        <div class="flex gap-4 border-b border-gray-200 overflow-x-auto">
            <?php if ($role === 'admin'): ?>
                <a href="admin_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">dashboard</span> Executive Dashboard
                </a>
            <?php endif; ?>
            <a href="cashier_dashboard.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">point_of_sale</span> Cashier POS Terminal
            </a>
            <a href="customers.php" class="pb-3 px-4 font-bold text-blue-700 border-b-2 border-blue-700 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">groups</span> Customer Directory
            </a>
            <a href="sales_reports.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">show_chart</span> Sales & Analytics
            </a>
            <?php if ($role === 'admin'): ?>
                <a href="admin_settings.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
                    <span class="material-symbols-outlined text-base">tune</span> System Settings & Portals
                </a>
            <?php endif; ?>
        </div>

        <!-- Directory Header & Search Bar with Loyalty Filter Pills -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm mb-8 space-y-4">
            <div class="flex flex-col md:flex-row justify-between items-center gap-4">
                <form method="GET" class="w-full md:w-auto flex gap-2">
                    <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
                    <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search name, phone, code..." class="px-4 py-2 border rounded-xl text-sm w-full md:w-80 font-medium">
                    <button type="submit" class="signature-gradient text-white px-5 py-2 rounded-xl font-bold text-xs shadow-sm">Search Customers</button>
                </form>
                <div class="text-xs text-gray-500 font-bold">
                    Registered Profiles Shown: <strong class="text-primary text-sm"><?= count($customers) ?></strong>
                </div>
            </div>

            <!-- Loyalty Status Filter Pills -->
            <div class="flex flex-wrap gap-2 pt-2 border-t">
                <a href="customers.php?filter=all<?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all <?= $filter === 'all' ? 'signature-gradient text-white shadow-xs' : 'bg-gray-100 text-gray-700 hover:bg-gray-200 border border-gray-200' ?>">All Customers</a>
                <a href="customers.php?filter=reward_ready<?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all <?= $filter === 'reward_ready' ? 'bg-emerald-600 text-white shadow-xs' : 'bg-emerald-50 text-emerald-800 border border-emerald-300 hover:bg-emerald-100' ?>">🎁 Loyalty Reward Ready (<?= $targetStamps ?>/<?= $targetStamps ?>)</a>
                <a href="customers.php?filter=new<?= $search ? '&search=' . urlencode($search) : '' ?>" class="px-3.5 py-1.5 rounded-xl text-xs font-bold transition-all <?= $filter === 'new' ? 'bg-blue-600 text-white shadow-xs' : 'bg-blue-50 text-blue-800 border border-blue-200 hover:bg-blue-100' ?>">🆕 New Customers</a>
            </div>
        </div>

        <!-- Customers Directory Table -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-surface-container-low text-on-surface-variant font-semibold border-b">
                            <th class="p-3">Customer Code</th>
                            <th class="p-3">Full Name</th>
                            <th class="p-3">Contact Number</th>
                            <th class="p-3">Email Address</th>
                            <th class="p-3">Total Orders</th>
                            <th class="p-3">Loyalty Progress (<?= $targetStamps ?> Stamps Target)</th>
                            <th class="p-3">Lifetime Spend</th>
                            <th class="p-3">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-8 text-on-surface-variant">No customers found. Click <strong>Register Customer</strong> to add one.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c): ?>
                                <?php 
                                    $totalCompleted = (int)($c['completed_orders_count'] ?? $c['total_orders'] ?? 0);
                                    $remainder = $totalCompleted % $targetStamps;
                                    $stamps = ($totalCompleted > 0 && $remainder === 0) ? $targetStamps : $remainder;
                                    $isEligible = ($stamps === $targetStamps);
                                ?>
                                <tr class="hover:bg-surface-container-low transition-all">
                                    <td class="p-3 font-mono font-bold text-primary"><?= htmlspecialchars($c['customer_code']) ?></td>
                                    <td class="p-3 font-bold text-on-surface"><?= htmlspecialchars($c['name']) ?></td>
                                    <td class="p-3 font-semibold text-gray-700">
                                        <?= htmlspecialchars($c['contact']) ?>
                                    </td>
                                    <td class="p-3 text-xs text-gray-600">
                                        <?= htmlspecialchars($c['email'] ?: 'N/A') ?>
                                    </td>
                                    <td class="p-3 font-bold"><?= $c['total_orders'] ?> orders</td>
                                    <td class="p-3">
                                        <?php 
                                            $loyaltyDataJson = htmlspecialchars(json_encode([
                                                'id' => $c['id'],
                                                'name' => $c['name'],
                                                'code' => $c['customer_code'],
                                                'contact' => $c['contact'],
                                                'total_orders' => $totalCompleted,
                                                'stamps' => $stamps,
                                                'target_stamps' => $targetStamps,
                                                'is_eligible' => $isEligible
                                            ]), ENT_QUOTES, 'UTF-8');
                                        ?>
                                        <?php if ($isEligible): ?>
                                            <button type="button" onclick="openLoyaltyModal(<?= $loyaltyDataJson ?>)" class="inline-flex items-center gap-1 bg-emerald-100 text-emerald-900 border border-emerald-300 px-2.5 py-1 rounded-lg text-xs font-bold shadow-2xs hover:bg-emerald-200 cursor-pointer">
                                                🎁 REWARD READY!
                                            </button>
                                        <?php else: ?>
                                            <button type="button" onclick="openLoyaltyModal(<?= $loyaltyDataJson ?>)" class="space-y-1 text-left w-full hover:opacity-80 cursor-pointer">
                                                <div class="flex justify-between items-center text-3xs font-bold text-gray-700">
                                                    <span>⭐ <?= $stamps ?> / <?= $targetStamps ?> Stamps</span>
                                                    <span class="text-blue-700 font-semibold"><?= max(0, $targetStamps - $stamps) ?> more for Reward</span>
                                                </div>
                                                <div class="w-28 bg-gray-200 h-2 rounded-full overflow-hidden">
                                                    <div class="bg-blue-600 h-full rounded-full transition-all" style="width: <?= min(100, round(($stamps / $targetStamps) * 100)) ?>%;"></div>
                                                </div>
                                            </button>
                                        <?php endif; ?>
                                    </td>
                                    <td class="p-3 font-bold text-emerald-600">₱<?= number_format($c['lifetime_spend'], 2) ?></td>
                                    <td class="p-3">
                                        <button type="button" onclick="editCustomer(<?= htmlspecialchars(json_encode($c), ENT_QUOTES, 'UTF-8') ?>)" class="text-blue-700 bg-blue-50 border border-blue-200 px-3 py-1.5 rounded-lg text-xs font-bold hover:bg-blue-100 flex items-center gap-1 shadow-2xs cursor-pointer">
                                            <span class="material-symbols-outlined text-xs">edit</span> Edit
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 10-PER-PAGE TABLE PAGINATION -->
            <?php if ($totalCustomersCount > 0): ?>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-3 pt-4 border-t border-outline text-xs mt-4">
                    <div class="text-gray-500 font-medium">
                        Showing <strong class="text-gray-800"><?= min($offset + 1, $totalCustomersCount) ?></strong> to <strong class="text-gray-800"><?= min($offset + $limit, $totalCustomersCount) ?></strong> of <strong class="text-primary"><?= $totalCustomersCount ?></strong> customers
                    </div>
                    <div class="flex flex-wrap items-center gap-1 font-bold">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">← Previous</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 bg-gray-100 border border-gray-200 rounded-lg text-gray-400 cursor-not-allowed">← Previous</span>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="?page=<?= $p ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 border rounded-lg <?= $p === $page ? 'signature-gradient text-white border-blue-600 shadow-2xs font-extrabold' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&filter=<?= urlencode($filter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">Next →</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 bg-gray-100 border border-gray-200 rounded-lg text-gray-400 cursor-not-allowed">Next →</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal: Register Customer -->
    <div id="addCustomerModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-md w-full p-6 rounded-2xl shadow-xl border border-outline">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold font-headline">Register New Customer</h3>
                <button onclick="closeAddCustomerModal()" class="text-gray-500 font-bold cursor-pointer">&times;</button>
            </div>
            <form method="POST" class="space-y-4" onsubmit="return validateCustomerForm(this);">
                <input type="hidden" name="action" value="add_customer">
                
                <div>
                    <label class="block text-xs font-bold mb-1">Customer Full Name *</label>
                    <input type="text" name="name" required minlength="2" placeholder="e.g. Maria Santos" class="w-full">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Mobile Phone Number <span class="text-gray-400 font-normal">(e.g. 09171234567)</span></label>
                    <input type="text" name="contact" placeholder="09171234567" class="w-full">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Email Address <span class="text-gray-400 font-normal">(For Digital Receipts)</span></label>
                    <input type="email" name="email" placeholder="customer@gmail.com" class="w-full">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Complete Address</label>
                    <textarea name="address" rows="2" placeholder="Block 5, Lot 12, Rizal St..." class="w-full"></textarea>
                </div>

                <button type="submit" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md">Register Customer Profile</button>
            </form>
        </div>
    </div>

    <!-- Modal: Edit Customer & Actions Popup -->
    <div id="editCustomerModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-md w-full p-6 rounded-2xl shadow-xl border border-outline space-y-4">
            <div class="flex justify-between items-center pb-2 border-b border-gray-100">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-blue-700 text-xl">manage_accounts</span>
                    <h3 class="text-base font-bold font-headline text-blue-700">Edit Customer Profile</h3>
                </div>
                <button onclick="closeEditModal()" class="text-gray-400 hover:text-gray-700 font-bold text-lg cursor-pointer">&times;</button>
            </div>

            <form method="POST" class="space-y-3" onsubmit="return validateCustomerForm(this);">
                <input type="hidden" name="action" value="edit_customer">
                <input type="hidden" name="customer_id" id="edit_cust_id">
                
                <div>
                    <label class="block text-xs font-bold mb-1">Customer Full Name *</label>
                    <input type="text" name="name" id="edit_cust_name" required minlength="2" placeholder="e.g. Maria Santos" class="w-full text-xs font-bold p-2.5 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Mobile Phone Number <span class="text-gray-400 font-normal">(e.g. 09171234567)</span></label>
                    <input type="text" name="contact" id="edit_cust_contact" placeholder="09171234567" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl font-mono">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Email Address <span class="text-gray-400 font-normal">(For Digital Receipts)</span></label>
                    <input type="email" name="email" id="edit_cust_email" placeholder="customer@gmail.com" class="w-full text-xs p-2.5 border border-gray-300 rounded-xl">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Complete Address</label>
                    <textarea name="address" id="edit_cust_address" rows="2" placeholder="Block 5, Lot 12, Rizal St..." class="w-full text-xs p-2.5 border border-gray-300 rounded-xl"></textarea>
                </div>

                <div class="flex gap-2 pt-1">
                    <button type="submit" class="flex-1 signature-gradient text-white font-bold py-2.5 rounded-xl shadow-md text-xs cursor-pointer">
                        💾 Update Customer Profile
                    </button>
                    <button type="button" onclick="deleteFromEditModal()" class="bg-rose-50 text-rose-700 hover:bg-rose-100 border border-rose-200 px-3.5 py-2.5 rounded-xl text-xs font-bold flex items-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-xs">delete</span> Delete
                    </button>
                </div>
            </form>

            <!-- Action Buttons Inside Popup Modal -->
            <div class="pt-3 border-t border-gray-200">
                <div class="grid grid-cols-2 gap-2">
                    <button type="button" onclick="openHistoryFromEditModal()" class="text-indigo-700 bg-indigo-50 border border-indigo-200 px-3 py-2.5 rounded-xl text-xs font-bold hover:bg-indigo-100 flex items-center justify-center gap-1 shadow-2xs cursor-pointer">
                        <span class="material-symbols-outlined text-sm">history</span> History & SMS Logs
                    </button>
                    <button type="button" onclick="openLoyaltyFromEditModal()" class="text-amber-900 bg-amber-50 border border-amber-300 px-3 py-2.5 rounded-xl text-xs font-bold hover:bg-amber-100 flex items-center justify-center gap-1 shadow-2xs cursor-pointer">
                        <span class="material-symbols-outlined text-sm">stars</span> Stamp Card
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Interactive 11-Stamp Customer Loyalty Card -->
    <div id="loyaltyCardModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-lg w-full p-6 rounded-2xl shadow-xl border border-outline space-y-4">
            <div class="flex justify-between items-center pb-2 border-b">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-amber-600 text-2xl">stars</span>
                    <div>
                        <h3 class="text-lg font-bold font-headline text-amber-900" id="card_cust_name">Customer Loyalty Stamp Card</h3>
                        <p class="text-2xs text-gray-500 font-mono" id="card_cust_code"></p>
                    </div>
                </div>
                <button onclick="closeLoyaltyModal()" class="text-gray-500 font-bold text-xl cursor-pointer">&times;</button>
            </div>

            <!-- VISUAL 11-STAMP LOYALTY CARD CONTAINER -->
            <div class="bg-gradient-to-br from-amber-500 via-amber-600 to-amber-700 p-5 rounded-2xl text-white shadow-lg space-y-3 relative overflow-hidden">
                <div class="flex justify-between items-center">
                    <span id="card_target_stamps_badge" class="text-xs font-black uppercase tracking-wider bg-white/20 px-2.5 py-0.5 rounded-full border border-white/30"><?= $targetStamps ?>-Order Loyalty Stamp Card</span>
                    <span id="card_stamp_counter" class="text-xs font-mono font-bold bg-black/30 px-2.5 py-0.5 rounded-full"></span>
                </div>

                <!-- CIRCULAR STAMP SLOTS -->
                <div class="flex flex-wrap justify-center items-center gap-2.5 py-2" id="stamps_grid">
                    <!-- Stamps rendered dynamically in JS -->
                </div>

                <div id="card_loyalty_status_msg" class="bg-white/20 p-2.5 rounded-xl text-2xs font-bold border border-white/30 text-center"></div>
            </div>

            <!-- ACTION BUTTONS RIBBON -->
            <div class="flex flex-col gap-2 pt-2">
                <a id="card_pos_shortcut_btn" href="cashier_dashboard.php" class="w-full signature-gradient text-white font-bold py-3 rounded-xl text-xs flex items-center justify-center gap-1 shadow-md">
                    <span class="material-symbols-outlined text-base">add_circle</span> 🧺 Create New Laundry Order for Customer
                </a>
                <div class="flex gap-2">
                    <button id="card_sms_btn" type="button" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2.5 rounded-xl text-xs flex items-center justify-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-sm">sms</span> 💬 Send Loyalty SMS Update
                    </button>
                    <button onclick="closeLoyaltyModal()" class="w-1/3 bg-gray-100 font-bold py-2.5 rounded-xl text-xs cursor-pointer">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Interactive Send Loyalty SMS Update -->
    <div id="loyaltySmsModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl space-y-4">
            <div class="flex justify-between items-center pb-2 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-emerald-700 text-xl">sms</span>
                    <h3 class="text-base font-black font-headline text-gray-900">💬 Send Loyalty SMS Update</h3>
                </div>
                <button onclick="document.getElementById('loyaltySmsModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 font-bold text-lg">&times;</button>
            </div>

            <div class="bg-emerald-50 border border-emerald-200 p-3 rounded-xl space-y-0.5">
                <div class="text-3xs font-extrabold uppercase tracking-wider text-emerald-900">Recipient Customer:</div>
                <div id="sms_modal_cust_name" class="text-xs font-black text-gray-900"></div>
                <div id="sms_modal_cust_phone" class="text-3xs font-mono font-bold text-emerald-800"></div>
            </div>

            <div>
                <label class="block text-xs font-bold text-gray-700 mb-1">Custom SMS Message Text *</label>
                <textarea id="loyalty_sms_textarea" rows="4" class="w-full text-xs font-mono bg-white border border-gray-300 rounded-xl p-3 focus:ring-2 focus:ring-emerald-500 shadow-2xs"></textarea>
            </div>

            <div class="flex flex-col gap-2 pt-2 border-t border-gray-100">
                <button type="button" id="loyalty_sms_submit_btn" onclick="executeLoyaltySmsSend()" class="w-full bg-emerald-700 hover:bg-emerald-800 text-white font-bold py-2.5 rounded-xl text-xs shadow-md flex items-center justify-center gap-1.5 cursor-pointer">
                    <span class="material-symbols-outlined text-sm">send</span> 🚀 Send Free SMS via TextBee Gateway
                </button>
                <div class="flex gap-2">
                    <button type="button" onclick="triggerMobileSmsFallback()" class="flex-1 bg-blue-50 text-blue-800 border border-blue-200 font-bold py-2 rounded-xl text-xs flex items-center justify-center gap-1 hover:bg-blue-100 cursor-pointer">
                        <span class="material-symbols-outlined text-xs">smartphone</span> 📱 Open Mobile SMS App
                    </button>
                    <button type="button" onclick="document.getElementById('loyaltySmsModal').classList.add('hidden')" class="px-4 py-2 bg-gray-100 text-gray-700 font-bold rounded-xl text-xs cursor-pointer">Cancel</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Customer Order History & Message Delivery Logs -->
    <div id="customerHistoryModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-3xl w-full p-6 rounded-2xl shadow-2xl space-y-4 max-h-[90vh] overflow-y-auto">
            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <span class="material-symbols-outlined text-indigo-700 text-2xl">history</span>
                    <div>
                        <h3 class="text-base font-black font-headline text-gray-900" id="hist_modal_title">Customer Order History & Message Logs</h3>
                        <p class="text-3xs text-gray-500 font-mono" id="hist_modal_subtitle"></p>
                    </div>
                </div>
                <button onclick="closeCustomerHistoryModal()" class="text-gray-400 hover:text-gray-700 font-bold text-xl cursor-pointer">&times;</button>
            </div>

            <!-- Loading Spinner -->
            <div id="hist_modal_loading" class="py-10 text-center text-gray-500 font-bold text-xs flex items-center justify-center gap-2">
                <span class="material-symbols-outlined animate-spin">sync</span> Loading transaction history & message delivery logs...
            </div>

            <!-- Modal Content -->
            <div id="hist_modal_body" class="hidden space-y-6">
                <!-- 1. Customer Summary Card -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                    <div class="bg-blue-50 border border-blue-200 p-3 rounded-xl">
                        <div class="text-3xs font-extrabold text-blue-900 uppercase">Total Lifetime Orders</div>
                        <div id="hist_stat_orders" class="text-xl font-black text-blue-950 font-mono">0</div>
                    </div>
                    <div class="bg-emerald-50 border border-emerald-200 p-3 rounded-xl">
                        <div class="text-3xs font-extrabold text-emerald-900 uppercase">Total Lifetime Spend</div>
                        <div id="hist_stat_spend" class="text-xl font-black text-emerald-950 font-mono">₱0.00</div>
                    </div>
                    <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl">
                        <div class="text-3xs font-extrabold text-amber-900 uppercase">Active Loyalty Stamps</div>
                        <div id="hist_stat_stamps" class="text-xl font-black text-amber-950 font-mono">0 / <?= $targetStamps ?></div>
                    </div>
                </div>

                <!-- 2. Order History Table with Message Received Badges -->
                <div>
                    <h4 class="text-xs font-black font-headline uppercase tracking-wider text-gray-800 mb-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-sm text-blue-700">receipt_long</span> Past Order Transactions & Message Status
                    </h4>
                    <div class="overflow-x-auto border border-gray-200 rounded-xl">
                        <table class="w-full text-left text-xs border-collapse">
                            <thead>
                                <tr class="bg-gray-100/90 text-gray-700 font-extrabold uppercase text-3xs tracking-wider border-b border-gray-200">
                                    <th class="p-2.5">Tracking #</th>
                                    <th class="p-2.5">Date</th>
                                    <th class="p-2.5 text-right">Amount</th>
                                    <th class="p-2.5 text-center">Order Status</th>
                                    <th class="p-2.5 text-center">📱 SMS Sent?</th>
                                    <th class="p-2.5 text-center">✉️ Email Sent?</th>
                                </tr>
                            </thead>
                            <tbody id="hist_orders_tbody" class="divide-y divide-gray-200 text-2xs">
                                <!-- Populated dynamically -->
                            </tbody>
                        </table>
                    </div>
                    <!-- Paginated Control Footer for Past Orders -->
                    <div id="hist_orders_pagination_container" class="hidden flex flex-wrap items-center justify-between gap-2 mt-2 px-1 text-3xs font-semibold text-gray-600">
                        <span id="hist_orders_pagination_info">Showing 0 of 0 orders</span>
                        <div class="flex items-center gap-1" id="hist_orders_pagination_controls">
                            <!-- Page buttons populated via JS -->
                        </div>
                    </div>
                </div>

                <!-- 3. Chronological Message Logs Audit Trail -->
                <div>
                    <div class="flex justify-between items-center mb-2">
                        <h4 class="text-xs font-black font-headline uppercase tracking-wider text-gray-800 flex items-center gap-1">
                            <span class="material-symbols-outlined text-sm text-emerald-700">mark_chat_read</span> Message Dispatch Logs & Delivery History
                        </h4>
                        <button type="button" onclick="clearCurrentCustomerMessageLogs()" class="text-rose-700 bg-rose-50 hover:bg-rose-100 border border-rose-200 px-2 py-0.5 rounded-lg text-3xs font-bold flex items-center gap-1 transition-all cursor-pointer shadow-2xs">
                            <span class="material-symbols-outlined text-3xs">delete_sweep</span> Clear Message Logs
                        </button>
                    </div>
                    <div class="border border-gray-200 rounded-xl overflow-hidden bg-gray-50/50 p-3 space-y-2 max-h-60 overflow-y-auto" id="hist_msg_logs_container">
                        <!-- Populated dynamically -->
                    </div>
                </div>
            </div>

            <div class="flex justify-end pt-2 border-t border-gray-100">
                <button onclick="closeCustomerHistoryModal()" class="px-5 py-2 bg-gray-100 hover:bg-gray-200 text-gray-800 font-bold rounded-xl text-xs cursor-pointer">Close History Window</button>
            </div>
        </div>
    </div>

    <script>
        let currentSmsPhone = '';
        let currentSmsMessage = '';
        let currentModalCustomerId = 0;

        function setUrlParam(key, value) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set(key, value);
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}
        }

        function removeUrlParam(keys) {
            try {
                const url = new URL(window.location.href);
                const kArray = Array.isArray(keys) ? keys : [keys];
                kArray.forEach(k => url.searchParams.delete(k));
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}
        }

        function openAddCustomerModal() {
            setUrlParam('modal', 'add_customer');
            removeUrlParam(['history_id', 'loyalty_id', 'edit_id']);
            const el = document.getElementById('addCustomerModal');
            if (el) el.classList.remove('hidden');
        }

        function closeAddCustomerModal() {
            const el = document.getElementById('addCustomerModal');
            if (el) el.classList.add('hidden');
            removeUrlParam('modal');
        }

        function closeCustomerHistoryModal() {
            const el = document.getElementById('customerHistoryModal');
            if (el) el.classList.add('hidden');
            removeUrlParam('history_id');
        }

        function closeLoyaltyModal() {
            const el = document.getElementById('loyaltyCardModal');
            if (el) el.classList.add('hidden');
            removeUrlParam('loyalty_id');
        }

        function closeEditModal() {
            const el = document.getElementById('editCustomerModal');
            if (el) el.classList.add('hidden');
            removeUrlParam('edit_id');
        }

        let currentHistOrdersPage = 1;
        const histOrdersPerPage = 5;

        function renderHistOrdersTable(orders, page = 1) {
            currentHistOrdersPage = page;
            const tbody = document.getElementById('hist_orders_tbody');
            const pagContainer = document.getElementById('hist_orders_pagination_container');
            const pagInfo = document.getElementById('hist_orders_pagination_info');
            const pagControls = document.getElementById('hist_orders_pagination_controls');
            
            if (!tbody) return;
            tbody.innerHTML = '';
            
            if (!orders || orders.length === 0) {
                tbody.innerHTML = `<tr><td colspan="6" class="text-center py-6 text-gray-500 font-medium">No order transactions recorded for this customer yet.</td></tr>`;
                if (pagContainer) pagContainer.classList.add('hidden');
                return;
            }

            const totalOrders = orders.length;
            const totalPages = Math.ceil(totalOrders / histOrdersPerPage);
            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;

            const startIdx = (page - 1) * histOrdersPerPage;
            const endIdx = Math.min(startIdx + histOrdersPerPage, totalOrders);
            const pageOrders = orders.slice(startIdx, endIdx);

            pageOrders.forEach(o => {
                const tr = document.createElement('tr');
                tr.className = 'hover:bg-blue-50/50 transition-colors';

                const smsBadge = o.sms_count > 0 
                    ? `<span class="bg-emerald-100 text-emerald-950 border border-emerald-300 px-2 py-0.5 rounded-md font-bold text-3xs">✅ Sent (${o.sms_count})</span>`
                    : `<span class="bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-md text-3xs">⚠️ Not Sent</span>`;

                const emailBadge = o.email_count > 0 
                    ? `<span class="bg-blue-100 text-blue-950 border border-blue-300 px-2 py-0.5 rounded-md font-bold text-3xs">✅ Sent (${o.email_count})</span>`
                    : `<span class="bg-gray-100 text-gray-500 border border-gray-200 px-2 py-0.5 rounded-md text-3xs">⚠️ Not Sent</span>`;

                tr.innerHTML = `
                    <td class="p-2.5 font-bold font-mono text-blue-700">${o.tracking_number}</td>
                    <td class="p-2.5 text-gray-600">${new Date(o.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}</td>
                    <td class="p-2.5 text-right font-black font-mono">₱${parseFloat(o.total_amount).toFixed(2)}</td>
                    <td class="p-2.5 text-center font-bold">${o.order_status}</td>
                    <td class="p-2.5 text-center">${smsBadge}</td>
                    <td class="p-2.5 text-center">${emailBadge}</td>
                `;
                tbody.appendChild(tr);
            });

            if (pagContainer) {
                if (totalPages <= 1) {
                    pagContainer.classList.add('hidden');
                } else {
                    pagContainer.classList.remove('hidden');
                    pagInfo.innerText = `Showing ${startIdx + 1}–${endIdx} of ${totalOrders} orders (Page ${page} of ${totalPages})`;
                    
                    pagControls.innerHTML = '';
                    
                    // Prev Button
                    const prevBtn = document.createElement('button');
                    prevBtn.type = 'button';
                    prevBtn.className = `px-2 py-1 rounded border text-3xs font-bold transition-all ${page === 1 ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100 cursor-pointer'}`;
                    prevBtn.innerText = '◀ Prev';
                    prevBtn.disabled = (page === 1);
                    prevBtn.onclick = () => renderHistOrdersTable(orders, page - 1);
                    pagControls.appendChild(prevBtn);

                    // Page Numbers
                    for (let p = 1; p <= totalPages; p++) {
                        const pBtn = document.createElement('button');
                        pBtn.type = 'button';
                        pBtn.className = `px-2 py-1 rounded border text-3xs font-bold transition-all cursor-pointer ${p === page ? 'bg-blue-600 text-white border-blue-600 font-extrabold' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100'}`;
                        pBtn.innerText = p;
                        pBtn.onclick = () => renderHistOrdersTable(orders, p);
                        pagControls.appendChild(pBtn);
                    }

                    // Next Button
                    const nextBtn = document.createElement('button');
                    nextBtn.type = 'button';
                    nextBtn.className = `px-2 py-1 rounded border text-3xs font-bold transition-all ${page === totalPages ? 'bg-gray-100 text-gray-400 border-gray-200 cursor-not-allowed' : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-100 cursor-pointer'}`;
                    nextBtn.innerText = 'Next ▶';
                    nextBtn.disabled = (page === totalPages);
                    nextBtn.onclick = () => renderHistOrdersTable(orders, page + 1);
                    pagControls.appendChild(nextBtn);
                }
            }
        }

        function openCustomerHistoryModal(customerId, customerName) {
            currentModalCustomerId = customerId;
            setUrlParam('history_id', customerId);
            removeUrlParam(['loyalty_id', 'edit_id', 'modal']);

            if (customerName) {
                document.getElementById('hist_modal_title').innerText = customerName + ' — Order History & Message Logs';
            } else {
                document.getElementById('hist_modal_title').innerText = 'Order History & Message Logs';
            }
            document.getElementById('hist_modal_subtitle').innerText = 'Customer ID #' + customerId;
            document.getElementById('hist_modal_loading').classList.remove('hidden');
            document.getElementById('hist_modal_body').classList.add('hidden');
            document.getElementById('customerHistoryModal').classList.remove('hidden');

            fetch(`get_customer_history_ajax.php?customer_id=${customerId}`)
                .then(r => r.json())
                .then(data => {
                    document.getElementById('hist_modal_loading').classList.add('hidden');
                    document.getElementById('hist_modal_body').classList.remove('hidden');

                    if (!data.success) {
                        alert(data.message || 'Error fetching customer history.');
                        return;
                    }

                    const cust = data.customer || {};
                    if (cust.name) {
                        document.getElementById('hist_modal_title').innerText = cust.name + ' — Order History & Message Logs';
                    }
                    const orders = data.orders || [];
                    const logs = data.message_logs || [];
                    const targetStamps = parseInt(data.target_stamps) || <?= $targetStamps ?>;

                    // Stats
                    const totalSpend = orders.reduce((acc, o) => acc + (parseFloat(o.paid_amount) || 0), 0);
                    const completedStatuses = ['Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed'];
                    const completedCount = orders.filter(o => completedStatuses.includes(o.order_status)).length;
                    const remainder = completedCount % targetStamps;
                    const stamps = (completedCount > 0 && remainder === 0) ? targetStamps : remainder;

                    document.getElementById('hist_stat_orders').innerText = orders.length + ' Orders';
                    document.getElementById('hist_stat_spend').innerText = '₱' + totalSpend.toFixed(2);
                    document.getElementById('hist_stat_stamps').innerText = stamps + ' / ' + targetStamps + ' Stamps';

                    // Render Paginated Orders Table (5 orders per page)
                    renderHistOrdersTable(orders, 1);

                    // Message Logs Container
                    const logsContainer = document.getElementById('hist_msg_logs_container');
                    logsContainer.innerHTML = '';

                    if (logs.length === 0) {
                        logsContainer.innerHTML = `<div class="text-center py-4 text-gray-500 text-2xs font-medium">No SMS or Email dispatches recorded for this customer yet.</div>`;
                    } else {
                        logs.forEach(l => {
                            const isSms = l.channel === 'sms';
                            const icon = isSms ? '📱' : '✉️';
                            const channelName = isSms ? 'SMS (TextBee Gateway)' : 'Email (Gmail SMTP)';
                            const badgeColor = isSms ? 'bg-emerald-100 text-emerald-950 border-emerald-300' : 'bg-blue-100 text-blue-950 border-blue-300';
                            
                            const isSuccess = (l.status || '').toLowerCase() === 'sent';
                            const statusColor = isSuccess ? 'text-emerald-700' : 'text-rose-700 font-bold';
                            const statusBadge = isSuccess ? '✅ Sent' : '❌ Failed';
                            
                            const div = document.createElement('div');
                            div.className = 'bg-white p-2.5 rounded-xl border border-gray-200 shadow-2xs text-2xs space-y-1';
                            div.innerHTML = `
                                <div class="flex justify-between items-center">
                                    <span class="${badgeColor} border px-2 py-0.5 rounded-full font-bold text-3xs">${icon} ${channelName}</span>
                                    <span class="text-gray-400 font-mono text-3xs">${l.created_at}</span>
                                </div>
                                <div class="font-mono text-gray-800 bg-gray-50 p-1.5 rounded-lg border border-gray-200/80 text-3xs break-words">${l.message_text}</div>
                                <div class="text-3xs text-gray-500">Recipient: <strong>${l.recipient}</strong> • Status: <strong class="${statusColor}">${statusBadge}</strong>${l.details ? ' <span class="text-rose-600 font-medium">(' + l.details + ')</span>' : ''}</div>
                            `;
                            logsContainer.appendChild(div);
                        });
                    }
                })
                .catch(err => {
                    document.getElementById('hist_modal_loading').classList.add('hidden');
                    alert('Error connecting to server. Please try again.');
                });
        }

        function validateCustomerForm(form) {
            const nameInput = form.querySelector('[name="name"]');
            const contactInput = form.querySelector('[name="contact"]');
            const emailInput = form.querySelector('[name="email"]');

            if (nameInput) {
                const nameVal = nameInput.value.trim();
                if (nameVal.length < 2 || !/[a-zA-Z]{2,}/.test(nameVal)) {
                    alert('Customer Full Name is required and must contain at least 2 alphabetic letters.');
                    nameInput.focus();
                    return false;
                }
            }

            if (contactInput) {
                const contactVal = contactInput.value.trim();
                if (contactVal && contactVal.toUpperCase() !== 'N/A') {
                    const cleanPhone = contactVal.replace(/[^0-9+]/g, '');
                    const isValidPhone = /^(09\d{9}|\+639\d{9}|639\d{9}|9\d{9})$/.test(cleanPhone);
                    if (!isValidPhone) {
                        alert('Invalid phone number format! Please enter a valid 11-digit PH mobile number starting with 09 (e.g. 09171234567).');
                        contactInput.focus();
                        return false;
                    }
                }
            }

            if (emailInput) {
                const emailVal = emailInput.value.trim();
                if (emailVal && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(emailVal)) {
                    alert('Invalid Email address format! (e.g. customer@gmail.com)');
                    emailInput.focus();
                    return false;
                }
            }

            return true;
        }

        function clearCurrentCustomerMessageLogs() {
            if (!currentModalCustomerId) return;
            if (!confirm("Are you sure you want to clear all SMS and Email message dispatch logs for this customer?")) return;

            const formData = new FormData();
            formData.append('customer_id', currentModalCustomerId);

            fetch('clear_message_logs_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    const custName = document.getElementById('hist_modal_title').innerText.split(' — ')[0];
                    openCustomerHistoryModal(currentModalCustomerId, custName);
                } else {
                    alert(data.message || 'Error clearing message logs.');
                }
            })
            .catch(err => alert('Error connecting to server to clear logs.'));
        }

        function openLoyaltySmsModal(name, phone, message) {
            if (!phone || phone === 'N/A') {
                alert('No phone number provided for this customer record.');
                return;
            }
            currentSmsPhone = phone;
            currentSmsMessage = message;

            document.getElementById('sms_modal_cust_name').innerText = name;
            document.getElementById('sms_modal_cust_phone').innerText = 'Phone: ' + phone;
            document.getElementById('loyalty_sms_textarea').value = message;
            document.getElementById('loyaltySmsModal').classList.remove('hidden');
        }

        function executeLoyaltySmsSend() {
            const phone = currentSmsPhone;
            const message = document.getElementById('loyalty_sms_textarea').value.trim();
            const btn = document.getElementById('loyalty_sms_submit_btn');

            if (!message) {
                alert('Please enter SMS message content.');
                return;
            }

            sendMobileSMS(phone, message, btn);
        }

        function triggerMobileSmsFallback() {
            const phone = currentSmsPhone;
            const message = document.getElementById('loyalty_sms_textarea').value.trim();
            let cleanPhone = phone.replace(/[^0-9+]/g, '');
            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(message);
            }
            let isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
            let smsUrl = isIOS ? `sms:${cleanPhone};body=${encodeURIComponent(message)}` : `sms:${cleanPhone}?body=${encodeURIComponent(message)}`;
            window.location.href = smsUrl;
            alert("📱 Mobile SMS Triggered for " + phone + "!\n\nMessage auto-copied to clipboard!");
        }

        function openLoyaltyModal(data) {
            if (data && data.id) {
                setUrlParam('loyalty_id', data.id);
                removeUrlParam(['history_id', 'edit_id', 'modal']);
            }
            const targetStamps = parseInt(data.target_stamps) || 11;

            const badgeEl = document.getElementById('card_target_stamps_badge');
            if (badgeEl) {
                badgeEl.innerText = targetStamps + '-Order Loyalty Stamp Card';
            }

            document.getElementById('card_cust_name').innerText = data.name + ' Loyalty Card ⭐';
            document.getElementById('card_cust_code').innerText = 'Customer Code: ' + data.code + ' • Phone: ' + (data.contact || 'N/A');
            
            const total = parseInt(data.total_orders) || 0;
            const remainder = total % targetStamps;
            const stamps = (total > 0 && remainder === 0) ? targetStamps : remainder;
            const needed = Math.max(0, targetStamps - stamps);
            const isRewardReady = (stamps === targetStamps);

            document.getElementById('card_stamp_counter').innerText = stamps + ' / ' + targetStamps + ' Stamps Earned';

            const grid = document.getElementById('stamps_grid');
            grid.innerHTML = '';

            for (let i = 1; i <= targetStamps; i++) {
                const isEarned = i <= stamps;
                const circle = document.createElement('div');
                circle.className = `h-10 w-10 rounded-full flex flex-col items-center justify-center text-3xs font-black transition-all ${
                    isEarned 
                        ? 'bg-white text-emerald-700 shadow-md border-2 border-emerald-300 scale-105' 
                        : 'bg-black/20 text-white/70 border border-white/30'
                }`;
                circle.innerHTML = isEarned ? '✓<span class="text-3xs font-bold">#' + i + '</span>' : i;
                grid.appendChild(circle);
            }

            // Free Laundry Reward Slot
            const freeSlot = document.createElement('div');
            freeSlot.className = `h-10 w-10 rounded-full flex items-center justify-center text-xs font-black transition-all ${
                isRewardReady 
                    ? 'bg-yellow-300 text-yellow-950 shadow-lg border-2 border-white animate-bounce' 
                    : 'bg-white/10 text-white/50 border border-dashed border-white/40'
            }`;
            freeSlot.innerHTML = '🎁';
            freeSlot.title = `${targetStamps}th Order (100% FREE Laundry Reward!)`;
            grid.appendChild(freeSlot);

            const maxFreeKg = <?= max(0.1, (float)($orderService->getSetting('loyalty_max_kg', '8.0'))) ?>;
            const maxFreePiece = <?= max(1, (float)($orderService->getSetting('loyalty_max_piece', '5.0'))) ?>;
            const capText = `${maxFreeKg.toFixed(1)} kg / ${Math.round(maxFreePiece)} pcs`;
            const msgEl = document.getElementById('card_loyalty_status_msg');
            if (isRewardReady) {
                msgEl.className = 'bg-yellow-300 text-yellow-950 p-2.5 rounded-xl text-2xs font-black border border-yellow-400 text-center shadow-md';
                msgEl.innerText = `🎉 REWARD READY! Customer completed ${targetStamps} orders. Next wash gets FREE Laundry (Up to ${capText})!`;
            } else {
                msgEl.className = 'bg-white/20 p-2.5 rounded-xl text-2xs font-bold border border-white/30 text-center';
                msgEl.innerText = `${stamps}/${targetStamps} Stamps Completed — ${needed} more order${needed === 1 ? "" : "s"} needed for FREE Laundry (Up to ${capText})!`;
            }

            const smsBtn = document.getElementById('card_sms_btn');
            if (smsBtn) {
                let smsMsg = isRewardReady
                    ? `Aquamarine Laundry: Good news ${data.name}! You completed ${targetStamps} orders! Your next laundry qualifies for FREE Laundry (Up to ${capText})! Visit us to claim your reward. Thank you!`
                    : `Aquamarine Laundry: Hi ${data.name}! You have ${stamps}/${targetStamps} loyalty stamps! Complete ${needed} more orders for FREE laundry (Up to ${capText})! Thank you!`;
                
                smsBtn.onclick = function() {
                    openLoyaltySmsModal(data.name, data.contact, smsMsg);
                };
            }

            const posBtn = document.getElementById('card_pos_shortcut_btn');
            if (posBtn) {
                posBtn.href = `cashier_dashboard.php?customer_id=${data.id}`;
            }

            document.getElementById('loyaltyCardModal').classList.remove('hidden');
        }

        let currentEditingCustomerData = null;

        function editCustomer(c) {
            currentEditingCustomerData = c;
            if (c && c.id) {
                setUrlParam('edit_id', c.id);
                removeUrlParam(['history_id', 'loyalty_id', 'modal']);
            }
            document.getElementById('edit_cust_id').value = c.id;
            document.getElementById('edit_cust_name').value = c.name;
            document.getElementById('edit_cust_contact').value = c.contact === 'N/A' ? '' : c.contact;
            document.getElementById('edit_cust_email').value = c.email || '';
            document.getElementById('edit_cust_address').value = c.address || '';
            document.getElementById('editCustomerModal').classList.remove('hidden');
        }

        function openHistoryFromEditModal() {
            if (!currentEditingCustomerData) return;
            document.getElementById('editCustomerModal').classList.add('hidden');
            openCustomerHistoryModal(currentEditingCustomerData.id, currentEditingCustomerData.name);
        }

        function openLoyaltyFromEditModal() {
            if (!currentEditingCustomerData) return;
            document.getElementById('editCustomerModal').classList.add('hidden');

            const total = parseInt(currentEditingCustomerData.completed_orders_count || currentEditingCustomerData.total_orders) || 0;
            const targetStamps = parseInt(currentEditingCustomerData.target_stamps) || <?= $targetStamps ?>;
            const remainder = total % targetStamps;
            const stamps = (total > 0 && remainder === 0) ? targetStamps : remainder;
            const isEligible = (stamps === targetStamps);

            openLoyaltyModal({
                id: currentEditingCustomerData.id,
                name: currentEditingCustomerData.name,
                code: currentEditingCustomerData.customer_code,
                contact: currentEditingCustomerData.contact,
                total_orders: total,
                stamps: stamps,
                target_stamps: targetStamps,
                is_eligible: isEligible
            });
        }

        function deleteFromEditModal() {
            if (!currentEditingCustomerData) return;
            if (confirm("Are you sure you want to delete the customer profile for '" + currentEditingCustomerData.name + "'?")) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="delete_customer">
                    <input type="hidden" name="customer_id" value="${currentEditingCustomerData.id}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        }

        function sendMobileSMS(phone, message, btn) {
            if (!phone || phone === 'N/A') {
                alert('⚠️ No contact phone number provided for this customer.');
                return;
            }

            let originalText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = 'Sending...';
            }

            const formData = new FormData();
            formData.append('phone', phone);
            formData.append('message', message);

            const smsEndpoint = window.location.pathname.includes('/pages/') ? '../send_sms_ajax.php' : 'send_sms_ajax.php';

            fetch(smsEndpoint, {
                method: 'POST',
                body: formData
            })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    console.error('Non-JSON response:', text);
                    return { success: false, message: 'Server response error (' + r.status + '): ' + (text.substring(0, 150) || r.statusText) };
                }
            })
            .then(data => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }

                const modal = document.getElementById('loyaltySmsModal');
                if (modal) modal.classList.add('hidden');

                if (data.success) {
                    const successMsg = data.message || `SMS Sent Successfully to ${phone}!`;
                    alert(`✅ ${successMsg}`);
                } else {
                    const errorMsg = data.message || 'Could not send SMS to customer.';
                    if (confirm(`❌ SMS Gateway Warning:\n\n${errorMsg}\n\nWould you like to open your device's native SMS app to send this message directly?`)) {
                        let cleanPhone = phone.replace(/[^0-9+]/g, '');
                        let isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                        let smsUrl = isIOS ? `sms:${cleanPhone};body=${encodeURIComponent(message)}` : `sms:${cleanPhone}?body=${encodeURIComponent(message)}`;
                        window.location.href = smsUrl;
                    }
                }
            })
            .catch(err => {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                }
                const modal = document.getElementById('loyaltySmsModal');
                if (modal) modal.classList.add('hidden');
                if (confirm(`❌ Network or server connection issue while dispatching SMS.\n\nWould you like to open your device's native SMS app to send this message directly?`)) {
                    let cleanPhone = phone.replace(/[^0-9+]/g, '');
                    let isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                    let smsUrl = isIOS ? `sms:${cleanPhone};body=${encodeURIComponent(message)}` : `sms:${cleanPhone}?body=${encodeURIComponent(message)}`;
                    window.location.href = smsUrl;
                }
            });
        }
    </script>
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
                const urlParams = new URLSearchParams(window.location.search);
                const historyId = parseInt(urlParams.get('history_id'));
                const loyaltyId = parseInt(urlParams.get('loyalty_id'));
                const editId = parseInt(urlParams.get('edit_id'));
                const modalParam = urlParams.get('modal');

                if (historyId > 0) {
                    openCustomerHistoryModal(historyId, '');
                } else if (loyaltyId > 0) {
                    fetch(`get_customer_history_ajax.php?customer_id=${loyaltyId}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success && data.customer) {
                                const cust = data.customer;
                                const total = parseInt(cust.completed_orders_count || cust.total_orders || (data.orders ? data.orders.length : 0)) || 0;
                                const targetStamps = parseInt(data.target_stamps) || 11;
                                const remainder = total % targetStamps;
                                const stamps = (total > 0 && remainder === 0) ? targetStamps : remainder;
                                openLoyaltyModal({
                                    id: cust.id,
                                    name: cust.name,
                                    code: cust.customer_code,
                                    contact: cust.contact,
                                    total_orders: total,
                                    stamps: stamps,
                                    target_stamps: targetStamps,
                                    is_eligible: (stamps === targetStamps)
                                });
                            }
                        });
                } else if (editId > 0) {
                    fetch(`get_customer_history_ajax.php?customer_id=${editId}`)
                        .then(r => r.json())
                        .then(data => {
                            if (data && data.success && data.customer) {
                                editCustomer(data.customer);
                            }
                        });
                } else if (modalParam === 'add_customer') {
                    openAddCustomerModal();
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
