<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

$orderService = new OrderService();
$db = Database::getInstance();

$msg = '';
$error = '';
$notificationData = null;
$emailReceiptData = null;
$smsResult = null;
$currentUserId = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'cashier';

// Handle Order Creation with Express option & Amount Tendered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_order') {
    try {
        $customerId = (int)$_POST['customer_id'];
        $serviceId = (int)$_POST['service_id'];
        $weightKg = (float)$_POST['weight_kg'];
        $discount = (float)($_POST['discount'] ?? 0);
        $initialPayment = (float)($_POST['initial_payment'] ?? 0);
        $tenderedAmount = (float)($_POST['tendered_amount'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? 'Cash';
        $referenceNo = trim($_POST['reference_no'] ?? '');

        if ($paymentMethod === 'GCash' && empty($referenceNo)) {
            throw new Exception("GCash Reference # / Transaction Ref No. is required when paying via GCash! Please enter the reference number before submitting.");
        }
        $notes = trim($_POST['notes'] ?? '');
        $isExpress = isset($_POST['is_express']) && $_POST['is_express'] === '1';
        $selectedAddonsRaw = $_POST['addons'] ?? [];
        $addonQtys = $_POST['addon_qty'] ?? [];
        $selectedAddons = [];
        foreach ($selectedAddonsRaw as $addonId) {
            $addonIdInt = (int)$addonId;
            $qty = max(1, (int)($addonQtys[$addonIdInt] ?? 1));
            $selectedAddons[] = [
                'id' => $addonIdInt,
                'qty' => $qty
            ];
        }

        $serviceType = trim($_POST['service_type'] ?? 'walk_in');
        if ($serviceType !== 'delivery') {
            $deliveryFee = 0.0;
            $deliveryAddress = '';
        } else {
            $deliveryFee = (float)($_POST['delivery_fee'] ?? 0.0);
        }
        $deliveryAddress = trim($_POST['delivery_address'] ?? '');
        $driverName = trim($_POST['driver_name'] ?? '');
        $estimatedPickup = trim($_POST['estimated_pickup'] ?? '');

        $res = $orderService->createOrder($customerId, $serviceId, $weightKg, $discount, $initialPayment, $paymentMethod, $notes, $currentUserId, $isExpress, $tenderedAmount, $selectedAddons, $referenceNo, $serviceType, $deliveryFee, $deliveryAddress, $driverName, $estimatedPickup);
        header("Location: receipt.php?token=" . ($res['receipt_token'] ?? $res['order_id']));
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle Customer Creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_customer') {
    $name = trim($_POST['name'] ?? '');
    $contact = trim($_POST['contact'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $address = trim($_POST['address'] ?? '');

    try {
        $cid = $orderService->addCustomer($name, $contact, $email, $address);
        $msg = "Customer $name registered successfully!";
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Handle Collecting Outstanding Balance (Pay Upon Pickup / Delivery) with Cash Tendered & Change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'collect_payment') {
    $orderId = (int)$_POST['order_id'];
    $paymentMethod = trim($_POST['payment_method'] ?? 'Cash');
    $refNo = trim($_POST['reference_no'] ?? '');
    $tenderedAmount = (float)($_POST['tendered_amount'] ?? 0);

    $ord = $db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
    if ($ord) {
        if ($paymentMethod === 'GCash' && empty($refNo)) {
            $error = "GCash Reference / Control # is required when paying via GCash! Please enter the reference number before submitting.";
        } else {
            $bal = (float)$ord['total_amount'] - (float)$ord['paid_amount'];
            if ($bal > 0) {
                $newPaid = (float)$ord['total_amount'];
                if ($tenderedAmount < $bal && $paymentMethod === 'Cash') {
                    $tenderedAmount = $bal;
                }
                $change = max(0, $tenderedAmount - $bal);

                $db->execute("UPDATE orders SET paid_amount = ?, payment_method = ?, reference_no = COALESCE(NULLIF(?, ''), reference_no) WHERE id = ?", [$newPaid, $paymentMethod, $refNo, $orderId]);
                
                // Record payment transaction
                $db->execute(
                    "INSERT INTO payments (order_id, amount, tendered_amount, payment_method, reference_no, received_by) VALUES (?, ?, ?, ?, ?, ?)",
                    [$orderId, $bal, $tenderedAmount, $paymentMethod, $refNo, $currentUserId]
                );

                $logMsg = "Collected balance of ₱" . number_format($bal, 2) . " via $paymentMethod for order #" . $ord['tracking_number'];
                if ($change > 0) {
                    $logMsg .= " (Tendered: ₱" . number_format($tenderedAmount, 2) . ", Change: ₱" . number_format($change, 2) . ")";
                }
                $orderService->logAudit($currentUserId, 'Payment Collected', 'POS', $logMsg);

                if ($change > 0) {
                    $msg = "Successfully collected ₱" . number_format($bal, 2) . " ($paymentMethod) for order #" . $ord['tracking_number'] . "! 💵 Change Given to Customer: ₱" . number_format($change, 2) . ". Order is now Fully Paid. ✅";
                } else {
                    $msg = "Successfully collected ₱" . number_format($bal, 2) . " ($paymentMethod) for order #" . $ord['tracking_number'] . "! Order is now Fully Paid. ✅";
                }
            }
        }
    }
}

// Handle Status Update, SMS, and Email Digital Receipt Modals
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status') {
    $orderId = (int)$_POST['order_id'];
    $newStatus = $_POST['new_status'];
    
    $ordCheck = $db->fetch("SELECT total_amount, paid_amount, tracking_number FROM orders WHERE id = ?", [$orderId]);
    $balCheck = $ordCheck ? ((float)$ordCheck['total_amount'] - (float)$ordCheck['paid_amount']) : 0;
    $completedStatuses = ['Completed', 'Claimed', 'Claimed / Completed', 'Delivered', 'Delivered / Completed'];

    if (in_array($newStatus, $completedStatuses) && $balCheck > 0.001) {
        $msg = "❌ Cannot mark Order #" . ($ordCheck['tracking_number'] ?? $orderId) . " as Completed because there is an unpaid balance of ₱" . number_format($balCheck, 2) . ". Please settle payment first.";
    } else {
        $orderService->updateOrderStatus($orderId, $newStatus, $currentUserId);
        $msg = "Order status updated to $newStatus.";

        $ord = $db->fetch("
            SELECT o.*, c.name as customer_name, c.contact as customer_contact, c.email as customer_email
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE o.id = ?
        ", [$orderId]);

        if ($ord) {
            // Trigger Notification Modal for Ready for Pickup & Out for Delivery
            if (in_array($newStatus, ['Ready for Pickup', 'Out for Delivery'])) {
                $templateKey = ($newStatus === 'Out for Delivery') ? 'sms_template_out_for_delivery' : 'sms_template_ready';
                $smsMsg = $orderService->formatSMSTemplate($templateKey, $ord);
                
                $notificationData = [
                    'tracking_number' => $ord['tracking_number'],
                    'customer_name' => $ord['customer_name'],
                    'contact' => $ord['customer_contact'] ?: 'N/A',
                    'email' => $ord['customer_email'] ?: 'N/A',
                    'status' => $newStatus,
                    'message' => $smsMsg,
                    'sms_result' => null
                ];
            }

            // Trigger Digital Email Receipt Modal for Claimed / Completed & Delivered / Completed
            if (in_array($newStatus, $completedStatuses)) {
                $emailSubject = "Official Digital Receipt - Aquamarine Laundry Order #{$ord['tracking_number']}";
                $emailBody = "Dear {$ord['customer_name']},\n\nThank you for choosing Aquamarine Laundry Hub!\n\nYour digital order details:\n- Order Tracking #: {$ord['tracking_number']}\n- Order Status: {$newStatus}\n- Total Weight: {$ord['total_weight']} kg\n- Total Amount: ₱" . number_format($ord['total_amount'], 2) . "\n- Amount Paid: ₱" . number_format($ord['paid_amount'], 2) . "\n- Balance Due: ₱" . number_format(max(0, (float)$ord['total_amount'] - (float)$ord['paid_amount']), 2) . "\n\nThank you for trusting Aquamarine Laundry Hub!";
                
                $emailReceiptData = [
                    'tracking_number' => $ord['tracking_number'],
                    'customer_name' => $ord['customer_name'],
                    'email' => $ord['customer_email'] ?: '',
                    'subject' => $emailSubject,
                    'body' => $emailBody,
                    'order_id' => $orderId,
                    'status' => $newStatus
                ];
            }
        }
    }
}

// Handle Payment Record / Settle Unpaid Balance with Amount Tendered
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_payment') {
    $orderId = (int)$_POST['order_id'];
    $amount = (float)$_POST['amount'];
    $tenderedAmount = (float)($_POST['tendered_amount'] ?? 0);
    $paymentMethod = $_POST['payment_method'] ?? 'Cash';
    $refNo = trim($_POST['reference_no'] ?? '');

    if ($amount > 0) {
        $orderService->recordPayment($orderId, $amount, $paymentMethod, $refNo, $currentUserId, $tenderedAmount);
        $change = max(0, $tenderedAmount - $amount);
        $msg = "Payment of ₱" . number_format($amount, 2) . " recorded. Change Given: ₱" . number_format($change, 2);
    } else {
        $error = "Payment amount must be greater than zero!";
    }
}

$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status_filter'] ?? 'all');
$dateRange = trim($_GET['date_range'] ?? 'today');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 10;

// Shift Isolation Logic:
// Cashier role = STRICT isolation: CAN ONLY SEE THEIR OWN TRANSACTIONS ($currentUserId)
// Admin role = Can view ALL cashier transactions or filter by specific cashier
$allCashiersList = [];
if ($role === 'admin') {
    $allCashiersList = $db->fetchAll("SELECT id, full_name, role, username FROM users ORDER BY full_name ASC");
    $selectedCashierFilter = isset($_GET['cashier_id']) && $_GET['cashier_id'] !== '' ? (int)$_GET['cashier_id'] : 0;
} else {
    // Non-admin cashiers are strictly locked to their own ID
    $selectedCashierFilter = $currentUserId;
}

$allFilteredOrders = $orderService->getOrders($search, $statusFilter, $selectedCashierFilter, $dateRange);
$totalOrdersCount = count($allFilteredOrders);
$totalPages = max(1, (int)ceil($totalOrdersCount / $limit));
$offset = ($page - 1) * $limit;
$orders = array_slice($allFilteredOrders, $offset, $limit);
$customers = $orderService->getCustomers();
$services = $orderService->getServices();
$addonsCatalog = $orderService->getAddonsCatalog();
$qrImage = $orderService->getSetting('gcash_qr_image', '');
$gcashNumber = $orderService->getSetting('gcash_number', '09088980862');
$deliveryEnabled = ($orderService->getSetting('delivery_enabled', '1') === '1');
$defaultDeliveryFee = (float)$orderService->getSetting('default_delivery_fee', '50.00');
$freeDeliveryMinSpend = (float)$orderService->getSetting('free_delivery_min_spend', '500.00');

// Personal Cashier Shift Statistics (strictly isolated per cashier)
$shiftStats = $orderService->getCashierShiftStats($selectedCashierFilter);
?>
<!DOCTYPE html>
<html class="light" lang="en">
<head>
    <meta charset="utf-8"/>
    <meta content="width=device-width, initial-scale=1.0" name="viewport"/>
    <title>Aquamarine | Cashier Shift & POS Terminal</title>
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
                    <span class="text-3xs text-gray-500 font-semibold tracking-wider uppercase">Cashier POS Terminal & Active Shift Operations</span>
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button onclick="document.getElementById('qrModal').classList.remove('hidden')" class="bg-blue-50 text-blue-700 font-bold px-3 py-1.5 rounded-xl text-xs border border-blue-200 flex items-center gap-1 hover:bg-blue-100 transition-all cursor-pointer">
                    <span class="material-symbols-outlined text-sm">qr_code_scanner</span> Show GCash QR
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
                        <div class="text-xs font-black text-gray-900 leading-tight"><?= htmlspecialchars($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Cashier Staff') ?></div>
                        <div class="text-3xs text-gray-500 font-bold uppercase font-mono">ID: <?= htmlspecialchars($_SESSION['cashier_id_number'] ?? 'CSH-101') ?></div>
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
            <a href="cashier_dashboard.php" class="pb-3 px-4 font-bold text-blue-700 border-b-2 border-blue-700 flex items-center gap-2 text-sm whitespace-nowrap">
                <span class="material-symbols-outlined text-base">point_of_sale</span> Cashier POS Terminal
            </a>
            <a href="customers.php" class="pb-3 px-4 font-semibold text-gray-500 hover:text-gray-900 flex items-center gap-2 text-sm whitespace-nowrap">
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

        <!-- Personal Cashier Shift Metrics Row -->
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6 mb-8">
            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-emerald-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">My Shift Sales Today</p>
                        <p class="text-3xl font-black text-emerald-600 font-headline">₱<?= number_format($shiftStats['today_sales'], 2) ?></p>
                    </div>
                    <div class="w-10 h-10 bg-emerald-100 text-emerald-600 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Collected by you today</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-primary">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">My Orders Processed Today</p>
                        <p class="text-3xl font-black text-on-surface font-headline"><?= $shiftStats['today_orders'] ?></p>
                    </div>
                    <div class="w-10 h-10 bg-primary/10 text-primary rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">receipt_long</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Orders created today</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-yellow-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Pending Queue</p>
                        <p class="text-3xl font-black text-yellow-600 font-headline"><?= $shiftStats['pending_count'] ?></p>
                    </div>
                    <div class="w-10 h-10 bg-yellow-100 text-yellow-700 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">schedule</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Awaiting processing</p>
            </div>

            <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm border-l-4 border-blue-500">
                <div class="flex justify-between items-start mb-2">
                    <div>
                        <p class="text-on-surface-variant text-xs font-bold mb-1">Ready for Pickup</p>
                        <p class="text-3xl font-black text-blue-600 font-headline"><?= $shiftStats['ready_count'] ?></p>
                    </div>
                    <div class="w-10 h-10 bg-blue-100 text-blue-600 rounded-xl flex items-center justify-center">
                        <span class="material-symbols-outlined">inventory_2</span>
                    </div>
                </div>
                <p class="text-on-surface-variant text-xs">Ready in store racks</p>
            </div>
        </div>

        <!-- POS Quick Action Ribbon -->
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div class="flex gap-4">
                <button onclick="openNewOrderModal()" class="signature-gradient text-white font-bold px-6 py-3 rounded-xl shadow-md flex items-center gap-2 hover:shadow-lg transition-all">
                    <span class="material-symbols-outlined">add_circle</span> New Laundry Order
                </button>
                <a href="customers.php" class="bg-surface-container-lowest border border-outline font-bold px-6 py-3 rounded-xl shadow-sm flex items-center gap-2 hover:bg-surface-container-low transition-all">
                    <span class="material-symbols-outlined">groups</span> Registered Customers Directory
                </a>
            </div>

            <!-- Status & Date Range Filter Ribbon -->
            <div class="flex flex-wrap items-center justify-between gap-3 w-full">
                <!-- Status Filter Pill Buttons -->
                <div class="flex flex-wrap gap-2">
                    <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=all<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $statusFilter === 'all' ? 'signature-gradient text-white shadow-sm' : 'bg-surface-container-lowest text-on-surface-variant border border-outline hover:bg-surface-container-low' ?>">All Orders</a>
                    <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=Pending<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $statusFilter === 'Pending' ? 'bg-yellow-500 text-white shadow-sm' : 'bg-yellow-50 text-yellow-800 border border-yellow-200' ?>">Pending</a>
                    <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=Ready for Pickup<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $statusFilter === 'Ready for Pickup' ? 'bg-emerald-600 text-white shadow-sm' : 'bg-emerald-50 text-emerald-800 border border-emerald-200' ?>">Ready for Pickup</a>
                    <?php if ($deliveryEnabled): ?>
                        <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=Out%20for%20Delivery<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= $statusFilter === 'Out for Delivery' ? 'bg-amber-600 text-white shadow-sm' : 'bg-amber-50 text-amber-900 border border-amber-300' ?>">🚚 Out for Delivery</a>
                        <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=Delivered<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= in_array($statusFilter, ['Delivered', 'Delivered / Completed']) ? 'bg-indigo-600 text-white shadow-sm' : 'bg-indigo-50 text-indigo-900 border border-indigo-200' ?>">✅ Delivered / Completed</a>
                    <?php endif; ?>
                    <a href="cashier_dashboard.php?date_range=<?= urlencode($dateRange) ?>&status_filter=Claimed%20%2F%20Completed<?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3.5 py-2 rounded-xl text-xs font-bold transition-all <?= in_array($statusFilter, ['Completed', 'Claimed', 'Claimed / Completed']) ? 'bg-gray-700 text-white shadow-sm' : 'bg-surface-container-lowest text-gray-700 border border-outline' ?>">Claimed / Completed</a>
                </div>

                <!-- Date Range Filter Pill Buttons -->
                <div class="flex flex-wrap items-center gap-1 bg-white p-1 rounded-xl border border-outline shadow-2xs">
                    <span class="text-3xs font-black text-gray-400 uppercase px-2 flex items-center gap-1">
                        <span class="material-symbols-outlined text-xs text-blue-600">calendar_month</span> Date:
                    </span>
                    <a href="cashier_dashboard.php?date_range=today&status_filter=<?= urlencode($statusFilter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $dateRange === 'today' ? 'bg-blue-600 text-white shadow-2xs' : 'text-gray-600 hover:bg-gray-100' ?>">Today</a>
                    <a href="cashier_dashboard.php?date_range=yesterday&status_filter=<?= urlencode($statusFilter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $dateRange === 'yesterday' ? 'bg-blue-600 text-white shadow-2xs' : 'text-gray-600 hover:bg-gray-100' ?>">Yesterday</a>
                    <a href="cashier_dashboard.php?date_range=7days&status_filter=<?= urlencode($statusFilter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $dateRange === '7days' ? 'bg-blue-600 text-white shadow-2xs' : 'text-gray-600 hover:bg-gray-100' ?>">7 Days</a>
                    <a href="cashier_dashboard.php?date_range=30days&status_filter=<?= urlencode($statusFilter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $dateRange === '30days' ? 'bg-blue-600 text-white shadow-2xs' : 'text-gray-600 hover:bg-gray-100' ?>">30 Days</a>
                    <a href="cashier_dashboard.php?date_range=all&status_filter=<?= urlencode($statusFilter) ?><?= $search ? '&search='.urlencode($search) : '' ?>" class="px-3 py-1.5 rounded-lg text-xs font-bold transition-all <?= $dateRange === 'all' ? 'bg-blue-600 text-white shadow-2xs' : 'text-gray-600 hover:bg-gray-100' ?>">All Time</a>
                </div>
            </div>
        </div>

        <!-- Laundry Orders Directory Table -->
        <div class="bg-surface-container-lowest p-6 rounded-2xl border border-outline shadow-sm space-y-4">
            <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 pb-4 border-b border-gray-200/80">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 bg-blue-50 text-blue-700 rounded-xl flex items-center justify-center border border-blue-200 shadow-2xs">
                        <span class="material-symbols-outlined text-xl">receipt_long</span>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-lg font-black font-headline text-gray-900">
                                Active Laundry Transactions
                            </h2>
                            <?php if ($role === 'admin'): ?>
                                <span class="bg-purple-100 text-purple-900 border border-purple-300 text-3xs font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider">
                                    👑 Admin Manager View
                                </span>
                            <?php else: ?>
                                <span class="bg-blue-100 text-blue-900 border border-blue-300 text-3xs font-black px-2.5 py-0.5 rounded-full uppercase tracking-wider">
                                    👤 My Shift Transactions Only
                                </span>
                            <?php endif; ?>
                        </div>
                        <p class="text-2xs text-gray-500 font-medium">Real-time status updates and receipt actions</p>
                    </div>
                </div>

                <form method="GET" class="flex flex-wrap gap-2.5 items-center">
                    <input type="hidden" name="date_range" value="<?= htmlspecialchars($dateRange) ?>">
                    <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter) ?>">

                    <?php if ($role === 'admin'): ?>
                        <div class="flex items-center gap-2 bg-amber-50/90 border border-amber-200 px-3 py-1.5 rounded-xl shadow-2xs">
                            <span class="material-symbols-outlined text-sm text-amber-700">filter_alt</span>
                            <span class="text-3xs font-black text-amber-950 uppercase tracking-wider">Cashier Filter:</span>
                            <select name="cashier_id" onchange="this.form.submit()" class="text-xs font-extrabold bg-white text-amber-950 py-1.5 px-3 border border-amber-300 rounded-lg shadow-2xs focus:ring-2 focus:ring-amber-500 cursor-pointer">
                                <option value="0" <?= $selectedCashierFilter === 0 ? 'selected' : '' ?>>🌐 All Cashiers & Admin Sales</option>
                                <?php foreach ($allCashiersList as $usr): ?>
                                    <option value="<?= $usr['id'] ?>" <?= $selectedCashierFilter === (int)$usr['id'] ? 'selected' : '' ?>>
                                        👤 <?= htmlspecialchars($usr['full_name']) ?> (<?= ucfirst($usr['role']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <div class="relative flex-1 sm:w-64">
                        <span class="material-symbols-outlined absolute left-3 top-2.5 text-gray-400 text-sm">search</span>
                        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search tracking #, rack, customer..." class="pl-9 pr-4 py-2 border border-gray-300 rounded-xl text-xs w-full font-medium shadow-2xs focus:ring-2 focus:ring-blue-500 bg-white">
                    </div>
                    <button type="submit" class="signature-gradient text-white px-5 py-2 rounded-xl font-bold text-xs shadow-sm hover:brightness-105 transition-all flex items-center gap-1 cursor-pointer">
                        Search
                    </button>
                </form>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="bg-gray-100/90 text-gray-700 font-extrabold uppercase text-3xs tracking-wider border-b border-gray-200">
                            <th class="px-4 py-3.5 text-left">Tracking #</th>
                            <th class="px-4 py-3.5 text-left">Customer Details</th>
                            <th class="px-4 py-3.5 text-right">Weight</th>
                            <th class="px-4 py-3.5 text-right">Total Amount</th>
                            <th class="px-4 py-3.5 text-right">Paid Amount</th>
                            <th class="px-4 py-3.5 text-center">Order Status</th>
                            <th class="px-4 py-3.5 text-center">Actions & Receipts</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200/80 text-xs">
                        <?php if (empty($orders)): ?>
                            <tr>
                                <td colspan="7" class="text-center py-10 text-gray-500 font-medium">No orders found matching the filter criteria.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($orders as $ord): 
                                $bal = (float)$ord['total_amount'] - (float)$ord['paid_amount'];
                            ?>
                                <tr class="hover:bg-blue-50/40 transition-colors">
                                    <td class="px-4 py-3.5 font-black text-blue-700 font-mono tracking-tight text-xs"><?= htmlspecialchars($ord['tracking_number']) ?></td>
                                    <td class="px-4 py-3.5 font-medium text-left">
                                        <div class="text-gray-900 font-extrabold text-xs"><?= htmlspecialchars($ord['customer_name']) ?></div>
                                        <div class="text-gray-500 text-3xs font-medium"><?= htmlspecialchars($ord['customer_contact'] ?: 'No Phone') ?></div>
                                        <div class="text-3xs text-blue-700 font-bold flex items-center gap-0.5 mt-0.5">
                                            <span class="material-symbols-outlined text-3xs">badge</span>
                                            Cashier: <?= htmlspecialchars($ord['cashier_name'] ?? 'System') ?>
                                        </div>
                                        <?php if (($ord['service_type'] ?? '') === 'delivery'): ?>
                                            <div class="inline-block bg-amber-100 text-amber-900 border border-amber-300 font-black text-3xs px-2 py-0.5 rounded-md mt-0.5">🚚 Doorstep Delivery</div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3.5 text-right font-bold text-gray-800"><?= number_format($ord['total_weight'], 1) ?> kg</td>
                                    <td class="px-4 py-3.5 text-right font-black text-gray-900 font-mono">₱<?= number_format($ord['total_amount'], 2) ?></td>
                                    <td class="px-4 py-3.5 text-right font-mono">
                                        <div class="font-extrabold text-emerald-600">₱<?= number_format($ord['paid_amount'], 2) ?></div>
                                        <?php if ($bal <= 0): ?>
                                            <span class="inline-block bg-emerald-100 text-emerald-900 border border-emerald-300 font-black text-3xs px-1.5 py-0.5 rounded-md mt-0.5">✅ Paid Upfront</span>
                                        <?php else: ?>
                                            <span class="inline-block bg-amber-100 text-amber-950 border border-amber-300 font-black text-3xs px-1.5 py-0.5 rounded-md mt-0.5">⚠️ Unpaid: ₱<?= number_format($bal, 2) ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-4 py-3 text-center align-middle">
                                        <form method="POST" class="flex flex-col items-center justify-center gap-1.5">
                                            <input type="hidden" name="action" value="update_status">
                                            <input type="hidden" name="order_id" value="<?= $ord['id'] ?>">
                                            
                                            <!-- Simple Clean Status Select Dropdown -->
                                            <select name="new_status" onchange="this.form.submit()" class="text-xs font-bold py-1.5 px-3 border border-gray-300 rounded-xl bg-white text-gray-900 shadow-2xs cursor-pointer focus:ring-2 focus:ring-blue-500">
                                                <?php 
                                                    $isDeliveryOrder = (($ord['service_type'] ?? '') === 'delivery');
                                                    $availableStatuses = $isDeliveryOrder ? ['Pending', 'Out for Delivery', 'Delivered / Completed'] : ['Pending', 'Ready for Pickup', 'Claimed / Completed'];
                                                    $completedStatuses = ['Claimed', 'Completed', 'Claimed / Completed', 'Delivered', 'Delivered / Completed'];
                                                ?>
                                                <?php foreach ($availableStatuses as $st): ?>
                                                    <?php 
                                                        $isComp = in_array($st, $completedStatuses);
                                                        $isDisabled = ($isComp && $bal > 0.001);
                                                        $isSelected = (in_array($ord['order_status'], $completedStatuses) && $isComp) || ($ord['order_status'] === $st);
                                                    ?>
                                                    <option value="<?= $st ?>" <?= $isSelected ? 'selected' : '' ?> <?= $isDisabled ? 'disabled class="text-gray-400 bg-gray-100 italic"' : '' ?>>
                                                        <?= $st ?><?= $isDisabled ? ' 🔒 (Settle ₱' . number_format($bal, 2) . ' First)' : '' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </form>
                                    </td>
                                    <td class="px-4 py-3.5 text-center align-middle">
                                        <div class="flex flex-row items-center justify-center gap-2 whitespace-nowrap">
                                            <?php if ($bal > 0): ?>
                                                <button type="button" 
                                                    onclick="openCollectModal(<?= $ord['id'] ?>, '<?= htmlspecialchars($ord['tracking_number'], ENT_QUOTES) ?>', '<?= htmlspecialchars($ord['customer_name'], ENT_QUOTES) ?>', <?= number_format($bal, 2, '.', '') ?>)"
                                                    class="bg-amber-50 text-amber-800 border border-amber-300 font-bold px-3 py-1.5 rounded-xl text-xs flex items-center gap-1 hover:bg-amber-100 transition-all cursor-pointer shadow-2xs">
                                                    <span class="material-symbols-outlined text-xs">payments</span> Settle (₱<?= number_format($bal, 2) ?>)
                                                </button>
                                            <?php endif; ?>

                                            <a href="receipt.php?token=<?= htmlspecialchars($ord['receipt_token'] ?? $ord['id']) ?>" class="text-blue-700 bg-blue-50 hover:bg-blue-100 font-bold px-3 py-1.5 rounded-xl text-xs border border-blue-200 inline-flex items-center gap-1 transition-all shadow-2xs">
                                                <span class="material-symbols-outlined text-xs">print</span> Receipt
                                            </a>

                                            <?php if (!empty($ord['customer_contact']) && $ord['customer_contact'] !== 'N/A'): ?>
                                                <button type="button" 
                                                    onclick="openSmsModalFromBtn(this)" 
                                                    data-tracking="<?= htmlspecialchars($ord['tracking_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-name="<?= htmlspecialchars($ord['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-phone="<?= htmlspecialchars($ord['customer_contact'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-msg="<?= htmlspecialchars($orderService->formatSMSTemplate('sms_template_ready', $ord), ENT_QUOTES, 'UTF-8') ?>"
                                                    class="text-emerald-700 bg-emerald-50 hover:bg-emerald-100 font-bold px-3 py-1.5 rounded-xl text-xs border border-emerald-200 inline-flex items-center gap-1 shadow-2xs cursor-pointer">
                                                    <span class="material-symbols-outlined text-xs">sms</span> Send SMS
                                                </button>

                                                <button type="button" 
                                                    onclick="sendDigitalReceiptEmailAjax(this)" 
                                                    data-tracking="<?= htmlspecialchars($ord['tracking_number'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-name="<?= htmlspecialchars($ord['customer_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-email="<?= htmlspecialchars($ord['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-status="<?= htmlspecialchars($ord['order_status'] ?? 'Completed', ENT_QUOTES, 'UTF-8') ?>"
                                                    data-total="<?= (float)($ord['total_amount'] ?? 0) ?>"
                                                    data-paid="<?= (float)($ord['paid_amount'] ?? 0) ?>"
                                                    class="text-blue-700 bg-blue-50 hover:bg-blue-100 font-bold px-3 py-1.5 rounded-xl text-xs border border-blue-200 inline-flex items-center gap-1 shadow-2xs cursor-pointer">
                                                    <span class="material-symbols-outlined text-xs">mail</span> Send Email
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- 10-PER-PAGE TABLE PAGINATION -->
            <?php if ($totalOrdersCount > 0): ?>
                <div class="flex flex-col sm:flex-row justify-between items-center gap-3 pt-4 border-t border-outline text-xs mt-4">
                    <div class="text-gray-500 font-medium">
                        Showing <strong class="text-gray-800"><?= min($offset + 1, $totalOrdersCount) ?></strong> to <strong class="text-gray-800"><?= min($offset + $limit, $totalOrdersCount) ?></strong> of <strong class="text-primary"><?= $totalOrdersCount ?></strong> orders
                    </div>
                    <div class="flex flex-wrap items-center gap-1 font-bold">
                        <?php if ($page > 1): ?>
                            <a href="?page=<?= $page - 1 ?>&date_range=<?= urlencode($dateRange) ?>&status_filter=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">← Previous</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 bg-gray-100 border border-gray-200 rounded-lg text-gray-400 cursor-not-allowed">← Previous</span>
                        <?php endif; ?>

                        <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                            <a href="?page=<?= $p ?>&date_range=<?= urlencode($dateRange) ?>&status_filter=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 border rounded-lg <?= $p === $page ? 'signature-gradient text-white border-blue-600 shadow-2xs font-extrabold' : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50' ?>"><?= $p ?></a>
                        <?php endfor; ?>

                        <?php if ($page < $totalPages): ?>
                            <a href="?page=<?= $page + 1 ?>&date_range=<?= urlencode($dateRange) ?>&status_filter=<?= urlencode($statusFilter) ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 text-gray-700">Next →</a>
                        <?php else: ?>
                            <span class="px-3 py-1.5 bg-gray-100 border border-gray-200 rounded-lg text-gray-400 cursor-not-allowed">Next →</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <!-- Modal: Record Balance Payment with Quick Cash & Cash Change Calculator -->
    <div id="paymentModal" class="hidden fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-md w-full p-6 rounded-2xl shadow-xl border border-outline">
            <div class="flex justify-between items-center mb-4">
                <h3 class="text-lg font-bold font-headline text-emerald-700 flex items-center gap-1">
                    <span class="material-symbols-outlined">payments</span> Settle Unpaid Balance
                </h3>
                <button onclick="closePaymentModal()" class="text-gray-500 font-bold cursor-pointer">&times;</button>
            </div>
            <form method="POST" class="space-y-4">
                <input type="hidden" name="action" value="record_payment">
                <input type="hidden" name="order_id" id="pay_order_id">

                <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl text-xs space-y-1">
                    <div><strong>Tracking #:</strong> <span id="pay_tracking" class="font-mono font-bold text-blue-700"></span></div>
                    <div><strong>Customer:</strong> <span id="pay_customer" class="font-bold"></span></div>
                    <div class="flex justify-between border-t pt-1 mt-1">
                        <span>Total Billed: <strong id="pay_total"></strong></span>
                        <span>Already Paid: <strong id="pay_already_paid" class="text-emerald-700"></strong></span>
                    </div>
                    <div class="text-sm font-bold text-amber-800 flex justify-between pt-1">
                        <span>Unpaid Balance:</span>
                        <span id="pay_balance_due"></span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Payment Amount to Collect (₱) *</label>
                    <input type="number" step="0.01" min="0.01" name="amount" id="pay_amount_input" required oninput="calcPaymentChange()" class="w-full text-base font-bold">
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Cash Tendered by Customer (₱) *</label>
                    <input type="number" step="0.01" min="0" name="tendered_amount" id="pay_tendered_input" required oninput="calcPaymentChange()" class="w-full text-base font-bold">
                    
                    <!-- Quick Cash Preset Buttons -->
                    <div class="flex flex-wrap gap-1.5 mt-2">
                        <button type="button" onclick="setTendered(100)" class="px-2.5 py-1 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱100</button>
                        <button type="button" onclick="setTendered(200)" class="px-2.5 py-1 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱200</button>
                        <button type="button" onclick="setTendered(500)" class="px-2.5 py-1 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱500</button>
                        <button type="button" onclick="setTendered(1000)" class="px-2.5 py-1 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱1,000</button>
                        <button type="button" onclick="setExactTendered()" class="px-2.5 py-1 bg-blue-50 text-blue-700 font-bold text-xs rounded border border-blue-200">Exact Amount</button>
                    </div>
                </div>

                <div class="bg-emerald-50 border border-emerald-200 p-3 rounded-xl flex justify-between items-center text-sm font-bold text-emerald-900">
                    <span>Cash Change Given:</span>
                    <span id="pay_change_display" class="text-xl text-emerald-700">₱0.00</span>
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Payment Method *</label>
                    <select name="payment_method" class="w-full font-bold">
                        <option value="Cash" selected>Cash</option>
                        <option value="GCash">GCash</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-bold mb-1">Reference No. <span class="text-gray-400 font-normal">(For GCash)</span></label>
                    <input type="text" name="reference_no" placeholder="OR-1001 or GCash Ref #">
                </div>

                <button type="submit" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md">Record Payment & Print Receipt</button>
            </form>
        </div>
    </div>

    <!-- Modal: Ready for Pickup Real SMS Mobile Notification Alert -->
    <div id="notifyModal" class="<?= $notificationData ? '' : 'hidden' ?> fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-md w-full p-6 rounded-2xl shadow-xl border border-outline">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-bold font-headline text-amber-700 flex items-center gap-2">
                    <span class="material-symbols-outlined text-2xl">local_shipping</span> <?= ($notificationData['status'] ?? '') === 'Out for Delivery' ? '🚚 Customer Delivery SMS Alert' : '💬 Customer SMS Notification' ?>
                </h3>
                <button onclick="document.getElementById('notifyModal').classList.add('hidden')" class="text-gray-500 font-bold text-xl">&times;</button>
            </div>
            <p class="text-xs text-gray-600 mb-3">Send <?= ($notificationData['status'] ?? '') === 'Out for Delivery' ? 'Out for Delivery notification message' : 'Ready for Pickup text message' ?> for Order <strong id="notify_tracking_no" class="font-mono text-primary font-bold"><?= htmlspecialchars($notificationData['tracking_number'] ?? '') ?></strong>.</p>
            
            <div id="notify_result_box" class="hidden p-3 rounded-xl text-xs mb-3 font-bold"></div>

            <div class="bg-amber-50 border border-amber-200 p-3 rounded-xl mb-4 text-xs space-y-1">
                <div><strong>Customer Name:</strong> <span id="notify_customer_name"><?= htmlspecialchars($notificationData['customer_name'] ?? '') ?></span></div>
                <div><strong>Phone Number:</strong> <span id="notify_contact_no" class="font-bold text-amber-800 font-mono"><?= htmlspecialchars($notificationData['contact'] ?? '') ?></span></div>
                <div><strong>Status:</strong> <span class="font-bold text-amber-900"><?= htmlspecialchars($notificationData['status'] ?? 'Ready for Pickup') ?></span></div>
            </div>

            <div class="mb-4">
                <label class="block text-xs font-bold mb-1 text-gray-700">Editable SMS Message Text (Bill & Receipt #):</label>
                <textarea id="notifyText" rows="4" class="w-full text-xs font-mono p-2.5 bg-white border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-500 shadow-2xs"><?= htmlspecialchars($notificationData['message'] ?? '') ?></textarea>
            </div>

            <div class="flex flex-col gap-2">
                <button id="notify_send_btn" type="button" onclick="sendCustomMobileSMS(this)" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-3 rounded-xl text-xs flex items-center justify-center gap-2 shadow-md">
                    <span class="material-symbols-outlined text-base">send_to_mobile</span> 💬 Send Mobile SMS Now
                </button>
                <div class="flex gap-2">
                    <button onclick="navigator.clipboard.writeText(document.getElementById('notifyText').value); alert('SMS Notification copied to clipboard!');" class="w-full signature-gradient text-white font-bold py-2.5 rounded-xl text-xs flex items-center justify-center gap-1">
                        <span class="material-symbols-outlined text-sm">content_copy</span> Copy Text
                    </button>
                    <button onclick="document.getElementById('notifyModal').classList.add('hidden')" class="w-1/3 bg-gray-100 hover:bg-gray-200 font-bold py-2.5 rounded-xl text-xs">Close</button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: Order Completed Digital Receipt Email Alert -->
    <?php if ($emailReceiptData): ?>
        <div id="emailReceiptModal" class="fixed inset-0 bg-black/60 z-50 flex items-center justify-center p-4">
            <div class="bg-surface-container-lowest max-w-md w-full p-6 rounded-2xl shadow-xl border border-outline">
                <div class="flex justify-between items-center mb-3">
                    <h3 class="text-lg font-bold font-headline text-blue-700 flex items-center gap-2">
                        <span class="material-symbols-outlined text-2xl">mark_email_read</span> Automatic Email Receipt Ready!
                    </h3>
                    <button onclick="document.getElementById('emailReceiptModal').classList.add('hidden')" class="text-gray-500 font-bold text-xl">&times;</button>
                </div>
                <p class="text-xs text-gray-600 mb-3">Order <strong><?= htmlspecialchars($emailReceiptData['tracking_number']) ?></strong> marked as <strong class="text-blue-700"><?= htmlspecialchars($emailReceiptData['status']) ?></strong>.</p>
                
                <div class="bg-blue-50 border border-blue-200 p-3 rounded-xl mb-4 text-xs space-y-2">
                    <div><strong>Customer Name:</strong> <?= htmlspecialchars($emailReceiptData['customer_name']) ?></div>
                    <div>
                        <label class="block text-3xs font-bold text-gray-700 uppercase mb-0.5">Recipient Email Address *</label>
                        <input type="email" id="email_modal_recipient_input" value="<?= htmlspecialchars($emailReceiptData['email'] ?: '') ?>" placeholder="Enter customer email address..." class="text-xs font-bold text-blue-900 border border-blue-300 rounded-lg px-3 py-1.5 w-full bg-white focus:ring-2 focus:ring-blue-500">
                    </div>
                </div>

                <textarea id="emailBodyText" class="hidden"><?= htmlspecialchars($emailReceiptData['body']) ?></textarea>

                <div class="flex flex-col gap-2">
                    <button type="button" 
                        onclick="triggerEmailModalDispatch(this)"
                        data-tracking="<?= htmlspecialchars($emailReceiptData['tracking_number'], ENT_QUOTES, 'UTF-8') ?>"
                        data-name="<?= htmlspecialchars($emailReceiptData['customer_name'], ENT_QUOTES, 'UTF-8') ?>"
                        data-status="<?= htmlspecialchars($emailReceiptData['status'] ?? 'Completed', ENT_QUOTES, 'UTF-8') ?>"
                        class="w-full signature-gradient text-white font-bold py-3 rounded-xl text-xs flex items-center justify-center gap-2 shadow-md hover:brightness-105 transition-all cursor-pointer">
                        <span class="material-symbols-outlined text-base">mail</span> 1-Click Send Digital Email Receipt
                    </button>
                    <div class="flex gap-2">
                        <button onclick="navigator.clipboard.writeText(document.getElementById('emailBodyText').value); alert('✅ Digital Receipt content copied to clipboard!');" class="w-full bg-gray-100 hover:bg-gray-200 font-bold py-2.5 rounded-xl text-xs flex items-center justify-center gap-1">
                            <span class="material-symbols-outlined text-sm">content_copy</span> Copy Email Body
                        </button>
                        <button onclick="document.getElementById('emailReceiptModal').classList.add('hidden')" class="w-1/3 bg-gray-100 font-bold py-2.5 rounded-xl text-xs">Close</button>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <!-- Modal: GCash QR Code Viewer -->
    <div id="qrModal" class="hidden fixed inset-0 bg-black/75 z-[70] flex items-center justify-center p-4">
        <div class="bg-surface-container-lowest max-w-sm w-full p-6 rounded-2xl shadow-2xl text-center border border-outline relative">
            <div class="flex justify-between items-center mb-3">
                <h3 class="text-lg font-bold font-headline text-blue-700 flex items-center gap-1">
                    <span class="material-symbols-outlined text-xl text-blue-600">qr_code_scanner</span> Official GCash QR Code
                </h3>
                <button onclick="document.getElementById('qrModal').classList.add('hidden')" class="text-gray-500 hover:text-gray-800 font-bold text-2xl leading-none cursor-pointer">&times;</button>
            </div>
            
            <?php if (!empty($qrImage) && file_exists(__DIR__ . '/' . $qrImage)): ?>
                <p class="text-xs text-gray-500 mb-2 font-medium">Scan with your GCash Mobile App to complete payment</p>
                <?php if (!empty($gcashNumber)): ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-xl p-2.5 mb-3 flex items-center justify-between gap-2 text-left">
                        <div>
                            <span class="text-3xs font-bold uppercase tracking-wider text-blue-900 block">Official GCash Number:</span>
                            <span class="text-sm font-black font-mono text-blue-950"><?= htmlspecialchars($gcashNumber) ?></span>
                        </div>
                        <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gcashNumber) ?>'); alert('Copied GCash Number: <?= htmlspecialchars($gcashNumber) ?>');" class="bg-blue-600 hover:bg-blue-700 text-white font-bold text-3xs px-2.5 py-1 rounded-lg shadow-2xs flex items-center gap-1 cursor-pointer">
                            <span class="material-symbols-outlined text-xs">content_copy</span> Copy
                        </button>
                    </div>
                <?php endif; ?>
                <img src="<?= htmlspecialchars($qrImage) ?>" alt="GCash QR Code" class="w-64 h-64 object-contain mx-auto border-2 border-blue-200 rounded-2xl p-2 bg-white shadow-md mb-4 hover:scale-105 transition-transform">
            <?php else: ?>
                <div class="p-5 bg-amber-50 rounded-2xl border border-amber-200 text-amber-900 text-xs font-bold space-y-2 mb-4">
                    <span class="material-symbols-outlined text-4xl text-amber-600">qr_code_2</span>
                    <p>No GCash QR Code image uploaded yet.</p>
                    <p class="text-3xs font-normal text-gray-600">Please upload your GCash QR image in <strong>Admin Settings -> Store Operations</strong>.</p>
                </div>
            <?php endif; ?>
            
            <button onclick="document.getElementById('qrModal').classList.add('hidden')" class="w-full signature-gradient text-white font-bold py-2.5 rounded-xl shadow-md cursor-pointer">Close QR Code</button>
        </div>
    </div>

    <!-- Modal: New Order 2-Step Wizard (Step 1: Order Details -> Step 2: Payment Options) -->
    <div id="newOrderModal" class="hidden fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-2 sm:p-4 overflow-y-auto">
        <div class="bg-surface-container-lowest max-w-lg w-full p-5 sm:p-6 rounded-2xl shadow-xl border border-outline max-h-[92vh] overflow-y-auto my-auto space-y-3">
            
            <div class="flex justify-between items-center pb-2 border-b">
                <div>
                    <h3 class="text-lg font-bold font-headline text-blue-700">Create Laundry Order</h3>
                    <div class="flex items-center gap-2 text-2xs font-bold text-gray-500 mt-0.5">
                        <span id="step1_indicator" class="text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200">1. Order & Add-ons</span>
                        <span>➔</span>
                        <span id="step2_indicator" class="text-gray-400">2. Payment & Checkout</span>
                    </div>
                </div>
                <button onclick="closeNewOrderModal()" class="text-gray-500 font-bold text-xl cursor-pointer">&times;</button>
            </div>

            <form method="POST" id="create_order_form" class="space-y-4" onsubmit="return validateCreateOrderForm(this);">
                <input type="hidden" name="action" value="create_order">
                
                <!-- STEP 1: ORDER DETAILS & ADD-ONS -->
                <div id="create_order_step1" class="space-y-4">
                    <div>
                        <div class="flex items-center justify-between mb-1">
                            <label class="block text-xs font-bold">Customer *</label>
                            <span id="customer_search_count" class="text-3xs text-blue-700 font-bold hidden"></span>
                        </div>

                        <!-- CUSTOMER QUICK SEARCH BAR -->
                        <div class="relative mb-2">
                            <input type="text" id="customer_search_input" oninput="filterCustomerSelectOptions()" placeholder="🔍 Search customer by name or phone..." class="w-full text-xs font-bold p-2 pr-7 border border-gray-300 rounded-xl focus:ring-2 focus:ring-blue-500 focus:border-blue-500 bg-gray-50/50 shadow-2xs" autocomplete="off">
                            <button type="button" id="clear_customer_search_btn" onclick="clearCustomerSearch()" class="hidden absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-700 font-black text-xs cursor-pointer" title="Clear Search">✕</button>
                        </div>

                        <select name="customer_id" id="new_customer_select" required onchange="checkCustomerLoyalty()" class="w-full font-bold">
                            <option value="" data-orders="0" data-name="">-- Choose Customer --</option>
                            <?php 
                                $targetStamps = max(1, (int)($orderService->getSetting('loyalty_stamps_target', '11')));
                                $stampCycle = $targetStamps + 1;
                            ?>
                            <?php foreach ($customers as $c): ?>
                                <?php 
                                    $ordCnt = (int)($c['completed_orders_count'] ?? $c['total_orders'] ?? 0); 
                                    $isRewardReady = ($ordCnt > 0 && ($ordCnt % $targetStamps === 0));
                                ?>
                                <option value="<?= $c['id'] ?>" data-orders="<?= $ordCnt ?>" data-name="<?= htmlspecialchars($c['name']) ?>">
                                    <?= htmlspecialchars($c['name']) ?> (<?= $c['contact'] ?: 'No Phone' ?>) — <?= $ordCnt ?> Orders Completed
                                    <?= $isRewardReady ? ' 🎁 [FREE LAUNDRY REWARD READY!]' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- DYNAMIC CUSTOMER LOYALTY STAMP CARD ALERT BANNER -->
                        <div id="customer_loyalty_card" class="hidden mt-2 p-3 rounded-xl border transition-all text-xs">
                            <div class="flex items-center justify-between font-bold mb-1">
                                <span class="flex items-center gap-1.5" id="loyalty_title">
                                    <span class="material-symbols-outlined text-sm">stars</span> <?= $targetStamps ?>-Order Loyalty Stamp Card
                                </span>
                                <span id="loyalty_badge" class="px-2 py-0.5 rounded-full text-3xs font-black uppercase"></span>
                            </div>
                            <p id="loyalty_desc" class="text-2xs mb-1.5"></p>
                            <div id="loyalty_action_container" class="hidden pt-1">
                                <button type="button" onclick="applyFreeLoyaltyReward()" class="w-full bg-emerald-600 hover:bg-emerald-700 text-white font-bold py-2 rounded-lg text-xs flex items-center justify-center gap-1 shadow-sm transition-all">
                                    <span class="material-symbols-outlined text-sm">card_giftcard</span> <span id="loyalty_claim_btn_text">🎁 Claim Loyalty Reward</span>
                                </button>
                            </div>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-bold mb-1">Laundry Service *</label>
                        <select name="service_id" id="new_service_select" required onchange="calcCreateOrderBilling(); checkCustomerLoyalty();" class="w-full font-bold">
                            <option value="" data-price="0" data-min="0" data-unit="kg" data-desc="">-- Choose Service --</option>
                            <?php foreach ($services as $s): ?>
                                <?php 
                                    $unitStr = htmlspecialchars($s['unit'] ?? 'kg');
                                    $descStr = htmlspecialchars($s['description'] ?? '');
                                    $capUnitDisplay = (strpos(strtolower($unitStr), '/load') !== false) ? $unitStr : ($unitStr . '/load');
                                ?>
                                <option value="<?= $s['id'] ?>" data-price="<?= $s['price_per_kg'] ?>" data-min="<?= $s['min_kg'] ?>" data-unit="<?= $unitStr ?>" data-desc="<?= $descStr ?>">
                                    <?= htmlspecialchars($s['service_name']) ?> - ₱<?= number_format($s['price_per_kg'], 2) ?>/load (<?= $s['min_kg'] ?> <?= $capUnitDisplay ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>

                        <!-- DYNAMIC SERVICE DESCRIPTION / INCLUSION BANNER -->
                        <div id="service_desc_banner" class="hidden mt-2 p-2.5 bg-blue-50/80 border border-blue-200 rounded-xl text-xs text-blue-950 font-medium space-y-0.5">
                            <span class="text-3xs font-extrabold uppercase tracking-wider text-blue-800 flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs text-blue-600">info</span> Service Description & Inclusions:
                            </span>
                            <p id="service_desc_text" class="text-xs font-bold text-gray-800 leading-snug pl-5"></p>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold mb-1" id="new_weight_label">Weight/Piece *</label>
                            <input type="number" step="0.1" min="0.1" name="weight_kg" id="new_weight_input" placeholder="e.g. 5.5" required oninput="calcCreateOrderBilling()" onkeyup="calcCreateOrderBilling()" onchange="calcCreateOrderBilling()" class="w-full font-bold">
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1">Discount (₱)</label>
                            <input type="number" step="0.01" min="0" name="discount" id="new_discount_input" value="0.00" oninput="calcCreateOrderBilling()" class="w-full font-bold text-rose-700">
                        </div>
                    </div>

                    <!-- DYNAMIC ADD-ONS SELECTION LIST FROM DATABASE WITH QUANTITY CONTROLS -->
                    <div>
                        <label class="block text-xs font-bold mb-1 text-gray-700">🌸 Laundry Add-on Products / Extra Treatments (Optional):</label>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-1.5 max-h-44 overflow-y-auto border border-gray-200 rounded-xl p-2 bg-gray-50/80">
                            <?php foreach ($addonsCatalog as $key => $addon): ?>
                                <div class="flex items-center justify-between p-2 bg-white border border-gray-200 rounded-xl hover:border-blue-300 transition-all text-xs font-semibold shadow-2xs">
                                    <label class="flex items-center gap-2 cursor-pointer flex-1 select-none pr-1">
                                        <input type="checkbox" name="addons[]" value="<?= $addon['id'] ?>" id="addon_cb_<?= $addon['id'] ?>" data-price="<?= $addon['price'] ?>" onchange="toggleAddonCb(<?= $addon['id'] ?>)" class="addon-checkbox rounded text-blue-600 w-4 h-4 cursor-pointer">
                                        <div class="flex flex-col">
                                            <span class="font-bold text-gray-900 leading-tight"><?= htmlspecialchars($addon['icon']) ?> <?= htmlspecialchars($addon['name']) ?></span>
                                            <span class="text-3xs text-blue-700 font-mono font-bold">+₱<?= number_format($addon['price'], 2) ?> / unit</span>
                                        </div>
                                    </label>
                                    <div class="flex items-center gap-0.5 bg-gray-100 p-0.5 rounded-lg border border-gray-200">
                                        <button type="button" onclick="changeAddonQty(<?= $addon['id'] ?>, -1)" class="w-5 h-5 bg-white border border-gray-300 rounded font-black text-xs text-gray-700 hover:bg-gray-200 flex items-center justify-center cursor-pointer shadow-2xs">-</button>
                                        <input type="number" name="addon_qty[<?= $addon['id'] ?>]" id="addon_qty_<?= $addon['id'] ?>" value="1" min="1" max="99" oninput="onAddonQtyInput(<?= $addon['id'] ?>)" class="addon-qty-input w-8 text-center text-xs font-mono font-bold border-0 bg-transparent p-0 focus:ring-0">
                                        <button type="button" onclick="changeAddonQty(<?= $addon['id'] ?>, 1)" class="w-5 h-5 bg-white border border-gray-300 rounded font-black text-xs text-gray-700 hover:bg-gray-200 flex items-center justify-center cursor-pointer shadow-2xs">+</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- DOORSTEP DELIVERY CONFIGURATION CARD -->
                    <?php if ($deliveryEnabled): ?>
                        <div class="bg-amber-50/80 border border-amber-200 p-3 rounded-xl space-y-2">
                            <label class="block text-xs font-bold text-amber-950 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm text-amber-600">local_shipping</span>
                                Service Option *
                            </label>
                            <div class="grid grid-cols-2 gap-2">
                                <button type="button" id="btn_service_walkin" onclick="selectServiceMode('walk_in')" class="p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-extrabold transition-all bg-amber-600 text-white border-amber-600 shadow-sm">
                                    <span>🏬 Pick up only</span>
                                </button>
                                <button type="button" id="btn_service_delivery" onclick="selectServiceMode('delivery')" class="p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-extrabold transition-all bg-white text-amber-900 border-amber-200 hover:border-amber-400">
                                    <span>🚚 Delivery only</span>
                                </button>
                                <input type="radio" name="service_type" value="walk_in" id="radio_walk_in" checked class="hidden" onchange="toggleDeliveryOptions('walk_in')">
                                <input type="radio" name="service_type" value="delivery" id="radio_delivery" class="hidden" onchange="toggleDeliveryOptions('delivery')">
                            </div>

                            <div id="delivery_details_container" class="hidden pt-1 space-y-2">
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-3xs font-bold text-gray-700 uppercase mb-0.5">Delivery Fee (₱)</label>
                                        <input type="number" step="0.01" min="0" name="delivery_fee" id="new_delivery_fee" value="<?= number_format($defaultDeliveryFee, 2, '.', '') ?>" oninput="calcCreateOrderBilling()" class="w-full font-bold text-xs bg-white">
                                    </div>
                                    <div class="flex items-center text-3xs text-amber-800 font-medium">
                                        Free delivery on orders ₱<?= number_format($freeDeliveryMinSpend, 2) ?>+
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-3xs font-bold text-gray-700 uppercase mb-0.5">Customer Delivery Address</label>
                                    <input type="text" name="delivery_address" id="new_delivery_address" placeholder="House #, Street, Barangay, Landmark..." class="w-full text-xs font-medium bg-white">
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <input type="hidden" name="service_type" value="walk_in">
                        <input type="hidden" name="delivery_fee" id="new_delivery_fee" value="0.00">
                    <?php endif; ?>



                    <!-- LIVE ORDER BILLING SUMMARY CARD -->
                    <div class="bg-blue-50/90 border border-blue-200 p-3.5 rounded-2xl space-y-1 text-xs shadow-inner">
                        <div class="flex justify-between items-center text-blue-900">
                            <span>Billed Load Count:</span>
                            <span id="bill_weight_label" class="font-bold">0.0 kg</span>
                        </div>
                        <div class="flex justify-between items-center text-gray-700">
                            <span>Base Service Cost (Per Load):</span>
                            <span id="base_cost_display" class="font-mono font-bold">₱0.00</span>
                        </div>
                        <div class="flex justify-between items-center text-emerald-800">
                            <span>Add-ons Total:</span>
                            <span id="addons_cost_display" class="font-mono font-bold">+₱0.00</span>
                        </div>
                        <div id="delivery_cost_row" class="hidden flex justify-between items-center text-amber-800">
                            <span>Delivery Fee:</span>
                            <span id="delivery_cost_display" class="font-mono font-bold">+₱0.00</span>
                        </div>
                        <div class="flex justify-between items-center text-rose-700">
                            <span>Discount:</span>
                            <span id="discount_cost_display" class="font-mono font-bold">-₱0.00</span>
                        </div>
                        <div class="flex justify-between items-center border-t border-blue-200 pt-2 text-sm font-black text-blue-950">
                            <span>TOTAL ORDER AMOUNT:</span>
                            <span id="grand_total_display" class="text-xl text-primary font-mono font-black">₱0.00</span>
                        </div>
                    </div>

                    <button type="button" onclick="goToOrderStep2()" class="w-full signature-gradient text-white font-bold py-3 rounded-xl shadow-md flex items-center justify-center gap-1">
                        Next: Payment & Review ➔
                    </button>
                </div>

                <!-- STEP 2: PAYMENT OPTIONS & CHECKOUT -->
                <div id="create_order_step2" class="hidden space-y-4">
                    <div class="p-3 bg-blue-50 border border-blue-200 rounded-xl flex justify-between items-center">
                        <span class="text-xs font-bold text-blue-900">Total Order Amount Due:</span>
                        <strong id="step2_total_display" class="text-lg font-mono font-black text-blue-800">₱0.00</strong>
                    </div>

                    <!-- PAYMENT OPTION CARDS (Full, Partial, Unpaid) -->
                    <div>
                        <label class="block text-xs font-bold mb-1.5 text-gray-700">Choose Payment Option *</label>
                        <div class="grid grid-cols-3 gap-2">
                            <label class="p-2.5 bg-emerald-50 border border-emerald-200 rounded-xl cursor-pointer text-center hover:bg-emerald-100 transition-all flex flex-col items-center justify-center">
                                <input type="radio" name="payment_type_choice" value="full" checked onchange="selectPaymentType('full')" class="mb-1 text-emerald-600">
                                <span class="text-xs font-bold text-emerald-900">Full Payment</span>
                                <span id="full_pay_label" class="text-2xs font-mono text-emerald-700 font-bold">₱0.00</span>
                            </label>

                            <label class="p-2.5 bg-amber-50 border border-amber-200 rounded-xl cursor-pointer text-center hover:bg-amber-100 transition-all flex flex-col items-center justify-center">
                                <input type="radio" name="payment_type_choice" value="partial" onchange="selectPaymentType('partial')" class="mb-1 text-amber-600">
                                <span class="text-xs font-bold text-amber-900">Partial Payment</span>
                                <span class="text-2xs text-amber-700 font-semibold">Deposit</span>
                            </label>

                            <label class="p-2.5 bg-gray-50 border border-gray-200 rounded-xl cursor-pointer text-center hover:bg-gray-100 transition-all flex flex-col items-center justify-center">
                                <input type="radio" name="payment_type_choice" value="unpaid" onchange="selectPaymentType('unpaid')" class="mb-1 text-gray-600">
                                <span class="text-xs font-bold text-gray-800">Unpaid / Later</span>
                                <span class="text-2xs text-gray-500 font-semibold">Pay on Pickup</span>
                            </label>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-bold mb-1">Initial Payment Amount (₱)</label>
                            <input type="number" step="0.01" min="0" name="initial_payment" id="new_initial_payment" value="0.00" oninput="calcCreateOrderBilling()" class="w-full font-bold text-base">
                        </div>
                        <div>
                            <label class="block text-xs font-bold mb-1">Payment Method</label>
                            <select name="payment_method" id="new_payment_method" onchange="toggleGcashRefInput()" class="w-full font-bold">
                                <option value="Cash" selected>Cash</option>
                                <option value="GCash">GCash</option>
                            </select>
                        </div>
                    </div>

                    <!-- GCash Reference Number & QR Code Input Box -->
                    <div id="gcash_ref_container" class="hidden p-3 bg-blue-50 border border-blue-200 rounded-xl space-y-2">
                        <div class="flex justify-between items-center">
                            <label class="block text-xs font-bold text-blue-900 flex items-center gap-1">
                                <span class="material-symbols-outlined text-sm text-blue-600">badge</span> GCash Reference # / Transaction Ref No.
                            </label>
                            <?php if (!empty($qrImage) && file_exists(__DIR__ . '/' . $qrImage)): ?>
                                <button type="button" onclick="document.getElementById('qrModal').classList.remove('hidden')" class="text-3xs font-bold bg-blue-600 text-white px-2 py-0.5 rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1 shadow-2xs">
                                    <span class="material-symbols-outlined text-3xs">qr_code_scanner</span> View Full QR
                                </button>
                            <?php endif; ?>
                        </div>
                        <input type="text" name="reference_no" id="new_reference_no" placeholder="e.g. 10023456789" class="w-full font-mono font-bold text-xs p-2 border border-blue-300 rounded-lg bg-white">
                        
                        <!-- Inline GCash QR Code Preview Box -->
                        <?php if (!empty($qrImage) && file_exists(__DIR__ . '/' . $qrImage)): ?>
                            <div class="bg-white p-2.5 rounded-xl border border-blue-200 text-center space-y-1">
                                <div class="text-3xs font-bold text-blue-900 flex items-center justify-center gap-1">
                                    <span class="material-symbols-outlined text-xs text-blue-600">qr_code_2</span> Official Store GCash QR Code
                                </div>
                                <img src="<?= htmlspecialchars($qrImage) ?>" alt="GCash QR Code" class="w-48 h-48 object-contain mx-auto border border-gray-200 rounded-lg p-1 bg-white shadow-2xs hover:scale-105 transition-transform cursor-pointer" onclick="document.getElementById('qrModal').classList.remove('hidden')" title="Click to view full size QR code">
                                <?php if (!empty($gcashNumber)): ?>
                                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-1.5 flex items-center justify-between gap-1 text-left">
                                        <span class="text-3xs font-black font-mono text-blue-950 flex items-center gap-1">
                                            <span class="material-symbols-outlined text-xs text-blue-600">smartphone</span> <?= htmlspecialchars($gcashNumber) ?>
                                        </span>
                                        <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gcashNumber) ?>'); alert('Copied GCash Number: <?= htmlspecialchars($gcashNumber) ?>');" class="bg-blue-600 text-white font-bold text-3xs px-2 py-0.5 rounded cursor-pointer hover:bg-blue-700">Copy</button>
                                    </div>
                                <?php endif; ?>
                                <p class="text-3xs text-gray-500 font-medium">Scan using GCash app or send to number above</p>
                            </div>
                        <?php else: ?>
                            <div class="bg-amber-50 p-2 rounded-lg border border-amber-200 text-3xs text-amber-900 font-medium flex items-center gap-1">
                                <span class="material-symbols-outlined text-xs text-amber-700">info</span> No GCash QR image uploaded yet. (Upload in Admin Settings -> Store Operations).
                            </div>
                        <?php endif; ?>
                    </div>

                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-xs font-bold text-gray-700">Cash Tendered by Customer (₱)</label>
                            <span class="text-xs font-bold text-emerald-800 flex items-center gap-1">Change Given: <strong id="new_change_display" class="text-sm text-emerald-700 font-mono font-black">₱0.00</strong></span>
                        </div>
                        <input type="number" step="0.01" min="0" name="tendered_amount" id="new_tendered_input" value="0.00" oninput="calcCreateOrderBilling()" class="w-full text-base font-bold">
                        
                        <!-- Quick Cash Preset Buttons -->
                        <div class="flex flex-wrap gap-1 mt-1.5">
                            <button type="button" onclick="setNewTendered(100)" class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱100</button>
                            <button type="button" onclick="setNewTendered(200)" class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱200</button>
                            <button type="button" onclick="setNewTendered(500)" class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱500</button>
                            <button type="button" onclick="setNewTendered(1000)" class="px-2 py-0.5 bg-gray-100 text-gray-800 font-bold text-xs rounded border hover:bg-gray-200">₱1,000</button>
                            <button type="button" onclick="setNewExactTendered()" class="px-2 py-0.5 bg-blue-50 text-blue-700 font-bold text-xs rounded border border-blue-200 hover:bg-blue-100">Exact Amount</button>
                        </div>

                        <!-- Dedicated Insufficient Cash Shortage Alert Below Cash Tendered -->
                        <div id="new_shortage_alert" class="hidden mt-2 p-2.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold flex items-center justify-between shadow-2xs">
                            <div class="flex items-center gap-1.5">
                                <span class="material-symbols-outlined text-base text-rose-600">warning</span>
                                <span>Insufficient Cash! Short by <strong id="new_shortage_amount" class="font-mono text-rose-700 text-sm">₱0.00</strong></span>
                            </div>
                            <span class="text-3xs bg-rose-200/80 text-rose-900 px-2 py-0.5 rounded-full font-black uppercase tracking-wider">Shortage</span>
                        </div>
                    </div>

                    <div class="flex justify-between items-center text-xs font-bold text-amber-800 p-2.5 bg-amber-50 border border-amber-200 rounded-xl">
                        <span>Unpaid Balance Due on Pickup:</span>
                        <span id="unpaid_balance_display" class="font-mono text-sm">₱0.00</span>
                    </div>

                    <div class="flex gap-2 pt-2">
                        <button type="button" onclick="goToOrderStep1()" class="w-1/3 bg-gray-100 text-gray-700 font-bold py-3 rounded-xl border hover:bg-gray-200">
                            ⬅ Back
                        </button>
                        <button type="submit" class="w-2/3 signature-gradient text-white font-bold py-3 rounded-xl shadow-md flex items-center justify-center gap-1">
                            Submit Order & Print Receipt 🧾
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <script>
        let currentBalanceDue = 0.0;

        function closePaymentModal() {
            const modal = document.getElementById('paymentModal');
            if (modal) modal.classList.add('hidden');
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('pay_order_id');
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}
        }

        function settleBalance(ord) {
            try {
                const url = new URL(window.location.href);
                url.searchParams.set('pay_order_id', ord.id);
                window.history.replaceState({}, '', url.toString());
            } catch(e) {}

            document.getElementById('pay_order_id').value = ord.id;
            document.getElementById('pay_tracking').innerText = ord.tracking_number;
            document.getElementById('pay_customer').innerText = ord.customer_name;
            document.getElementById('pay_total').innerText = '₱' + ord.total_amount.toFixed(2);
            document.getElementById('pay_already_paid').innerText = '₱' + ord.paid_amount.toFixed(2);
            document.getElementById('pay_balance_due').innerText = '₱' + ord.balance.toFixed(2);
            
            currentBalanceDue = ord.balance;
            document.getElementById('pay_amount_input').value = ord.balance.toFixed(2);
            document.getElementById('pay_tendered_input').value = ord.balance.toFixed(2);
            calcPaymentChange();
            document.getElementById('paymentModal').classList.remove('hidden');
        }

        function calcPaymentChange() {
            const amt = parseFloat(document.getElementById('pay_amount_input').value) || 0;
            const tend = parseFloat(document.getElementById('pay_tendered_input').value) || 0;
            const change = Math.max(0, tend - amt);
            document.getElementById('pay_change_display').innerText = '₱' + change.toFixed(2);
        }

        function setTendered(val) {
            document.getElementById('pay_tendered_input').value = val.toFixed(2);
            calcPaymentChange();
        }

        function setExactTendered() {
            const amt = parseFloat(document.getElementById('pay_amount_input').value) || 0;
            document.getElementById('pay_tendered_input').value = amt.toFixed(2);
            calcPaymentChange();
        }

        let globalGrandTotal = 0.0;

        function checkCustomerLoyalty() {
            const select = document.getElementById('new_customer_select');
            const card = document.getElementById('customer_loyalty_card');
            const badge = document.getElementById('loyalty_badge');
            const desc = document.getElementById('loyalty_desc');
            const action = document.getElementById('loyalty_action_container');
            if (!select || !card) return;

            const selectedOption = select.options[select.selectedIndex];
            if (!selectedOption || !selectedOption.value) {
                card.classList.add('hidden');
                return;
            }

            const name = selectedOption.getAttribute('data-name') || 'Customer';
            const ordersCount = parseInt(selectedOption.getAttribute('data-orders')) || 0;
            
            card.classList.remove('hidden');

            const serviceSelect = document.getElementById('new_service_select');
            const selSvcOpt = serviceSelect ? serviceSelect.options[serviceSelect.selectedIndex] : null;
            const unitAttr = selSvcOpt ? (selSvcOpt.getAttribute('data-unit') || 'kg') : 'kg';
            const isPiece = unitAttr.toLowerCase().includes('piece');
            const unitLabel = isPiece ? 'pc(s)' : 'kg';
            
            const targetStamps = <?= max(1, (int)($orderService->getSetting('loyalty_stamps_target', '11'))) ?>;
            const maxFreeKg = <?= max(0.1, (float)($orderService->getSetting('loyalty_max_kg', '8.0'))) ?>;
            const maxFreePiece = <?= max(1, (float)($orderService->getSetting('loyalty_max_piece', '5.0'))) ?>;
            const maxFreeCap = isPiece ? maxFreePiece : maxFreeKg;
            const formattedCap = isPiece ? Math.round(maxFreeCap) : maxFreeCap.toFixed(1);
            const remainder = ordersCount % targetStamps;
            const currentStamps = (ordersCount > 0 && remainder === 0) ? targetStamps : remainder;
            const isRewardReady = (currentStamps === targetStamps);

            const btnTextEl = document.getElementById('loyalty_claim_btn_text');
            if (btnTextEl) {
                btnTextEl.innerText = `🎁 Claim Loyalty Reward (Free Up to ${formattedCap} ${unitLabel})`;
            }
            
            // Check if customer reached target orders (eligible for Free Laundry!)
            if (isRewardReady) {
                card.className = 'mt-2 p-3 rounded-xl border border-emerald-300 bg-emerald-50 text-emerald-950 transition-all text-xs shadow-2xs';
                badge.innerText = `🎁 REWARD READY! (Up to ${formattedCap} ${unitLabel} Free)`;
                badge.className = 'px-2 py-0.5 rounded-full text-3xs font-black uppercase bg-emerald-200 text-emerald-900 border border-emerald-400';
                desc.innerHTML = `🎉 <strong>Milestone Reached!</strong> <strong>${name}</strong> has completed <strong>${ordersCount} orders</strong>! Eligible for <strong>Loyalty Reward Discount (Up to ${formattedCap} ${unitLabel} Free)</strong> under your store's ${targetStamps}-Order Loyalty Program.`;
                action.classList.remove('hidden');
            } else {
                const needed = Math.max(0, targetStamps - currentStamps);
                card.className = 'mt-2 p-3 rounded-xl border border-blue-200 bg-blue-50/70 text-blue-950 transition-all text-xs';
                badge.innerText = `${currentStamps} / ${targetStamps} Stamps`;
                badge.className = 'px-2 py-0.5 rounded-full text-3xs font-black uppercase bg-blue-200 text-blue-900 border border-blue-300';
                desc.innerHTML = `👤 <strong>${name}</strong> has <strong>${ordersCount} total orders</strong> (${currentStamps}/${targetStamps} loyalty stamps). Needs <strong>${needed} more order${needed > 1 ? 's' : ''}</strong> for Loyalty Reward (Up to ${formattedCap} ${unitLabel} Free)!`;
                action.classList.add('hidden');
            }
        }

        function applyFreeLoyaltyReward() {
            const serviceSelect = document.getElementById('new_service_select');
            const weightInput = document.getElementById('new_weight_input');
            const discountInput = document.getElementById('new_discount_input');
            
            if (!serviceSelect.value || !weightInput.value || parseFloat(weightInput.value) <= 0) {
                alert('Please select a Laundry Service and enter the weight/piece first before claiming the Loyalty Reward!');
                return;
            }

            const selOption = serviceSelect.options[serviceSelect.selectedIndex];
            const pricePerLoad = parseFloat(selOption.getAttribute('data-price')) || 0;
            const maxCapKg = parseFloat(selOption.getAttribute('data-min')) || 1.0;
            const orderWeight = parseFloat(weightInput.value) || 0;
            const unitAttr = selOption ? (selOption.getAttribute('data-unit') || 'kg') : 'kg';
            const isPiece = unitAttr.toLowerCase().includes('piece');
            const unitLabel = isPiece ? 'pc(s)' : 'kg';

            // Admin setting for max free allowance (Kg vs Piece)
            const maxFreeKg = <?= max(0.1, (float)($orderService->getSetting('loyalty_max_kg', '8.0'))) ?>;
            const maxFreePiece = <?= max(1, (float)($orderService->getSetting('loyalty_max_piece', '5.0'))) ?>;
            const maxFreeCap = isPiece ? maxFreePiece : maxFreeKg;
            
            // Calculate free loads covered up to maxFreeCap limit
            const freeLoads = Math.max(1, Math.ceil(maxFreeCap / maxCapKg));
            const maxFreeDiscount = freeLoads * pricePerLoad;

            // Calculate total base cost for the order weight
            const orderLoads = Math.max(1, Math.ceil(orderWeight / maxCapKg));
            const baseCost = orderLoads * pricePerLoad;

            // Loyalty Discount is capped at maxFreeDiscount (or baseCost if order is smaller)
            const appliedDiscount = Math.min(baseCost, maxFreeDiscount);

            discountInput.value = appliedDiscount.toFixed(2);
            calcCreateOrderBilling();

            if (appliedDiscount >= baseCost) {
                alert(`🎉 LOYALTY REWARD DISCOUNT APPLIED! (Up to ${maxFreeKg.toFixed(1)} ${unitLabel} Free)\n\nThe loyalty reward covers the base laundry cost (-₱${appliedDiscount.toFixed(2)}) for up to ${maxFreeKg.toFixed(1)} ${unitLabel}!`);
            } else {
                const remainingBase = baseCost - appliedDiscount;
                alert(`🎉 LOYALTY REWARD DISCOUNT APPLIED! (Up to ${maxFreeKg.toFixed(1)} ${unitLabel} Free)\n\n• Loyalty Free Discount: -₱${appliedDiscount.toFixed(2)} (Covers ${freeLoads} Load/s up to ${maxFreeKg.toFixed(1)} ${unitLabel})\n• Remaining Base Laundry Balance: ₱${remainingBase.toFixed(2)}\n\n(Note: Any add-on items or delivery fees are billed separately).`);
            }
        }

        function toggleDeliveryOptions(type) {
            const container = document.getElementById('delivery_details_container');
            if (!container) return;
            if (type === 'delivery') {
                container.classList.remove('hidden');
                const custSelect = document.getElementById('new_customer_select');
                const selectedOpt = custSelect ? custSelect.options[custSelect.selectedIndex] : null;
                const addr = selectedOpt ? selectedOpt.getAttribute('data-address') : '';
                const addrInput = document.getElementById('new_delivery_address');
                if (addrInput && addr && !addrInput.value) {
                    addrInput.value = addr;
                }
            } else {
                container.classList.add('hidden');
            }
            calcCreateOrderBilling();
        }

        function calcCreateOrderBilling() {
            checkCustomerLoyalty();
            const serviceSelect = document.getElementById('new_service_select');
            if (!serviceSelect) return;
            const selectedOpt = serviceSelect.options[serviceSelect.selectedIndex];
            const pricePerLoad = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-price')) || 0 : 0;
            const maxCapKg = selectedOpt ? parseFloat(selectedOpt.getAttribute('data-min')) || 0 : 0;

            // Display Service Description / Inclusion Banner
            const descText = selectedOpt ? (selectedOpt.getAttribute('data-desc') || '').trim() : '';
            const descBanner = document.getElementById('service_desc_banner');
            const descDisplay = document.getElementById('service_desc_text');
            if (descBanner && descDisplay) {
                if (descText) {
                    descDisplay.innerText = descText;
                    descBanner.classList.remove('hidden');
                } else {
                    descBanner.classList.add('hidden');
                }
            }

            const weightVal = document.getElementById('new_weight_input').value.trim();
            const weightInput = parseFloat(weightVal) || 0;

            let loadsCount = 0;

            if (weightInput > 0 && maxCapKg > 0 && pricePerLoad > 0) {
                loadsCount = Math.max(1, Math.ceil(weightInput / maxCapKg));
            }

            const baseCost = Math.round(loadsCount * pricePerLoad * 100) / 100;

            // Add-ons total with quantity support
            let addonsCost = 0;
            document.querySelectorAll('.addon-checkbox:checked').forEach(cb => {
                const addonId = cb.value;
                const price = parseFloat(cb.getAttribute('data-price')) || 0;
                const qtyInput = document.getElementById('addon_qty_' + addonId);
                const qty = qtyInput ? (parseInt(qtyInput.value) || 1) : 1;
                addonsCost += price * qty;
            });

            // Delivery Fee
            let deliveryFee = 0;
            const deliveryRadio = document.querySelector('input[name="service_type"]:checked');
            const deliveryFeeInput = document.getElementById('new_delivery_fee');
            const deliveryCostRow = document.getElementById('delivery_cost_row');

            if (deliveryRadio && deliveryRadio.value === 'delivery' && deliveryFeeInput) {
                deliveryFee = parseFloat(deliveryFeeInput.value) || 0;
                if (deliveryCostRow) deliveryCostRow.classList.remove('hidden');
                const delDisplay = document.getElementById('delivery_cost_display');
                if (delDisplay) delDisplay.innerText = '+₱' + deliveryFee.toFixed(2);
            } else {
                if (deliveryCostRow) deliveryCostRow.classList.add('hidden');
            }

            // Discount
            const discount = parseFloat(document.getElementById('new_discount_input').value) || 0;

            // Grand Total
            const grandTotal = (baseCost > 0 || addonsCost > 0 || deliveryFee > 0) ? Math.max(0, baseCost + addonsCost + deliveryFee - discount) : 0;
            globalGrandTotal = grandTotal;

            // Initial Payment & Balance
            const initialPay = parseFloat(document.getElementById('new_initial_payment').value) || 0;
            const balance = Math.max(0, grandTotal - initialPay);

            // Change Given
            const tendered = parseFloat(document.getElementById('new_tendered_input').value) || 0;
            const change = Math.max(0, tendered - initialPay);

            // Update UI Elements
            const unitAttr = selectedOpt ? (selectedOpt.getAttribute('data-unit') || 'kg') : 'kg';
            const unitLabel = unitAttr.toLowerCase().includes('piece') ? 'pc(s)' : 'kg';
            let weightText = '0.0 (0 Loads)';
            if (weightInput <= 0) {
                weightText = 'Enter Weight/Piece';
            } else {
                weightText = `${weightInput.toFixed(1)} ${unitLabel} → ${loadsCount} ${loadsCount === 1 ? 'Load' : 'Loads'} (${loadsCount} × ₱${pricePerLoad.toFixed(2)})`;
            }

            document.getElementById('bill_weight_label').innerText = weightText;
            document.getElementById('base_cost_display').innerText = '₱' + baseCost.toFixed(2);
            document.getElementById('addons_cost_display').innerText = '+₱' + addonsCost.toFixed(2);
            document.getElementById('discount_cost_display').innerText = '-₱' + discount.toFixed(2);
            document.getElementById('grand_total_display').innerText = '₱' + grandTotal.toFixed(2);
            document.getElementById('step2_total_display').innerText = '₱' + grandTotal.toFixed(2);

            // Full Payment Button Label
            const fullPayLabelEl = document.getElementById('full_pay_label');
            if (fullPayLabelEl) fullPayLabelEl.innerText = '₱' + grandTotal.toFixed(2);

            // Unpaid Balance Due on Pickup Display
            const unpaidBalanceEl = document.getElementById('unpaid_balance_display');
            if (unpaidBalanceEl) unpaidBalanceEl.innerText = '₱' + balance.toFixed(2);

            // Auto sync Payment Type Radio Button Selection
            const radioFull = document.querySelector('input[name="payment_type_choice"][value="full"]');
            const radioPartial = document.querySelector('input[name="payment_type_choice"][value="partial"]');
            const radioUnpaid = document.querySelector('input[name="payment_type_choice"][value="unpaid"]');

            if (radioFull && radioPartial && radioUnpaid) {
                if (initialPay >= grandTotal && grandTotal > 0) {
                    radioFull.checked = true;
                } else if (initialPay <= 0) {
                    radioUnpaid.checked = true;
                } else {
                    radioPartial.checked = true;
                }
            }

            // Change Given or Shortage Warning
            const changeDisplayEl = document.getElementById('new_change_display');
            const shortageAlertEl = document.getElementById('new_shortage_alert');
            const shortageAmountEl = document.getElementById('new_shortage_amount');

            const pmSelect = document.getElementById('new_payment_method');
            const isCash = !pmSelect || pmSelect.value === 'Cash';

            if (isCash && initialPay > 0 && tendered > 0 && tendered < initialPay) {
                const shortage = initialPay - tendered;
                if (shortageAlertEl) {
                    shortageAlertEl.classList.remove('hidden');
                    if (shortageAmountEl) shortageAmountEl.innerText = '₱' + shortage.toFixed(2);
                }
                if (changeDisplayEl) {
                    changeDisplayEl.className = 'text-sm text-emerald-700 font-mono font-black';
                    changeDisplayEl.innerText = '₱0.00';
                }
            } else {
                if (shortageAlertEl) shortageAlertEl.classList.add('hidden');
                if (changeDisplayEl) {
                    changeDisplayEl.className = 'text-sm text-emerald-700 font-mono font-black';
                    changeDisplayEl.innerText = '₱' + change.toFixed(2);
                }
            }
        }

        // Validate Create Order Submission for Insufficient Cash Tendered & Draft Persistence
        document.addEventListener('DOMContentLoaded', function() {
            restoreOrderDraft();

            const orderForm = document.getElementById('create_order_form');
            if (orderForm) {
                orderForm.addEventListener('input', saveOrderDraft);
                orderForm.addEventListener('change', saveOrderDraft);
                orderForm.addEventListener('submit', function(e) {
                    const initialPay = parseFloat(document.getElementById('new_initial_payment').value) || 0;
                    const tendered = parseFloat(document.getElementById('new_tendered_input').value) || 0;

                    if (initialPay > 0 && tendered < initialPay) {
                        e.preventDefault();
                        const shortage = initialPay - tendered;
                        alert(`⚠️ INSUFFICIENT CASH TENDERED!\n\nThe required payment amount is ₱${initialPay.toFixed(2)}, but the customer provided ₱${tendered.toFixed(2)}.\n\nCustomer is SHORT by ₱${shortage.toFixed(2)}.\n\nPlease ask the customer for the remaining ₱${shortage.toFixed(2)} or switch to Partial Payment / Pay Later.`);
                        document.getElementById('new_tendered_input').focus();
                        return false;
                    }
                    clearOrderDraft();
                });
            }

            const collectForm = document.getElementById('collect_payment_form');
            if (collectForm) {
                collectForm.addEventListener('submit', function(e) {
                    const pm = document.getElementById('collect_payment_method').value;
                    if (pm === 'Cash') {
                        const tend = parseFloat(document.getElementById('collect_tendered_input').value) || 0;
                        if (tend < currentCollectBalance) {
                            e.preventDefault();
                            const shortage = currentCollectBalance - tend;
                            alert(`⚠️ INSUFFICIENT CASH TENDERED!\n\nThe balance due is ₱${currentCollectBalance.toFixed(2)}, but the customer provided ₱${tend.toFixed(2)}.\n\nCustomer is SHORT by ₱${shortage.toFixed(2)}.\n\nPlease ask the customer for the remaining ₱${shortage.toFixed(2)}.`);
                            document.getElementById('collect_tendered_input').focus();
                            return false;
                        }
                    }
                });
            }
        });

        function goToOrderStep2() {
            const serviceSelect = document.getElementById('new_service_select');
            const weightInput = document.getElementById('new_weight_input').value.trim();

            if (!serviceSelect.value) {
                alert('Please choose a Laundry Service.');
                serviceSelect.focus();
                return;
            }

            if (!weightInput || parseFloat(weightInput) <= 0) {
                alert('Please enter a valid laundry weight in kg.');
                document.getElementById('new_weight_input').focus();
                return;
            }

            document.getElementById('create_order_step1').classList.add('hidden');
            document.getElementById('create_order_step2').classList.remove('hidden');

            document.getElementById('step1_indicator').className = 'text-gray-400';
            document.getElementById('step2_indicator').className = 'text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200';

            // Auto-select full payment by default
            selectPaymentType('full');
            if (typeof saveOrderDraft === 'function') saveOrderDraft();
        }

        function goToOrderStep1() {
            document.getElementById('create_order_step2').classList.add('hidden');
            document.getElementById('create_order_step1').classList.remove('hidden');

            document.getElementById('step2_indicator').className = 'text-gray-400';
            document.getElementById('step1_indicator').className = 'text-blue-700 bg-blue-50 px-2 py-0.5 rounded border border-blue-200';
            if (typeof saveOrderDraft === 'function') saveOrderDraft();
        }

        function selectPaymentType(type) {
            const initialPayInput = document.getElementById('new_initial_payment');
            const tenderedInput = document.getElementById('new_tendered_input');

            if (type === 'full') {
                initialPayInput.value = globalGrandTotal.toFixed(2);
                if (tenderedInput) tenderedInput.value = globalGrandTotal.toFixed(2);
            } else if (type === 'unpaid') {
                initialPayInput.value = '0.00';
                if (tenderedInput) tenderedInput.value = '0.00';
            } else if (type === 'partial') {
                const half = Math.round(globalGrandTotal / 2);
                initialPayInput.value = half.toFixed(2);
                if (tenderedInput) tenderedInput.value = half.toFixed(2);
            }
            calcCreateOrderBilling();
        }

        function toggleAddonCb(addonId) {
            calcCreateOrderBilling();
        }

        function changeAddonQty(addonId, delta) {
            const cb = document.getElementById('addon_cb_' + addonId);
            const qtyInput = document.getElementById('addon_qty_' + addonId);
            if (!qtyInput || !cb) return;

            let currentQty = parseInt(qtyInput.value) || 1;
            let newQty = currentQty + delta;

            if (newQty <= 0) {
                cb.checked = false;
                qtyInput.value = 1;
            } else {
                cb.checked = true;
                qtyInput.value = newQty;
            }
            calcCreateOrderBilling();
        }

        function onAddonQtyInput(addonId) {
            const cb = document.getElementById('addon_cb_' + addonId);
            const qtyInput = document.getElementById('addon_qty_' + addonId);
            if (!qtyInput || !cb) return;

            let val = parseInt(qtyInput.value) || 0;
            if (val > 0) {
                cb.checked = true;
            } else {
                cb.checked = false;
            }
            calcCreateOrderBilling();
        }

        function selectServiceMode(mode) {
            const radioWalkin = document.getElementById('radio_walk_in');
            const radioDelivery = document.getElementById('radio_delivery');
            const btnWalkin = document.getElementById('btn_service_walkin');
            const btnDelivery = document.getElementById('btn_service_delivery');
            
            if (mode === 'delivery') {
                if (radioDelivery) radioDelivery.checked = true;
                if (btnDelivery) btnDelivery.className = 'p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-black transition-all bg-amber-600 text-white border-amber-600 shadow-sm';
                if (btnWalkin) btnWalkin.className = 'p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-black transition-all bg-white text-amber-900 border-amber-200 hover:border-amber-400';
                toggleDeliveryOptions('delivery');
            } else {
                if (radioWalkin) radioWalkin.checked = true;
                if (btnWalkin) btnWalkin.className = 'p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-black transition-all bg-amber-600 text-white border-amber-600 shadow-sm';
                if (btnDelivery) btnDelivery.className = 'p-2.5 rounded-xl border-2 cursor-pointer flex items-center justify-center gap-2 text-xs font-black transition-all bg-white text-amber-900 border-amber-200 hover:border-amber-400';
                toggleDeliveryOptions('walk_in');
            }
        }

        function setNewTendered(val) {
            document.getElementById('new_tendered_input').value = val.toFixed(2);
            calcCreateOrderBilling();
        }

        function setNewExactTendered() {
            const initialPay = parseFloat(document.getElementById('new_initial_payment').value) || 0;
            document.getElementById('new_tendered_input').value = initialPay.toFixed(2);
            calcCreateOrderBilling();
        }

        function toggleGcashRefInput() {
            const pmSelect = document.getElementById('new_payment_method');
            const container = document.getElementById('gcash_ref_container');
            if (!pmSelect || !container) return;
            if (pmSelect.value === 'GCash') {
                container.classList.remove('hidden');
                document.getElementById('new_reference_no').focus();
                const qrModal = document.getElementById('qrModal');
                if (qrModal) qrModal.classList.remove('hidden');
            } else {
                container.classList.add('hidden');
            }
        }

        function openSmsModalFromBtn(btn) {
            const data = {
                tracking_number: btn.getAttribute('data-tracking') || '',
                customer_name: btn.getAttribute('data-name') || '',
                contact: btn.getAttribute('data-phone') || 'N/A',
                rack: btn.getAttribute('data-rack') || 'Front Desk Rack',
                message: btn.getAttribute('data-msg') || ''
            };
            openSmsModal(data);
        }

        function sendDigitalReceiptEmailAjax(btn) {
            const email = btn.getAttribute('data-email') || '';
            const trackingNo = btn.getAttribute('data-tracking') || '';
            const name = btn.getAttribute('data-name') || 'Customer';
            const status = btn.getAttribute('data-status') || 'Completed';
            const total = parseFloat(btn.getAttribute('data-total')) || 0;
            const paid = parseFloat(btn.getAttribute('data-paid')) || 0;
            const balance = Math.max(0, total - paid);

            let recipientEmail = email;
            if (!recipientEmail || recipientEmail === 'N/A') {
                recipientEmail = prompt(`Please enter customer email address for ${name} (Receipt #${trackingNo}):`, '');
                if (!recipientEmail || !recipientEmail.trim()) return;
                recipientEmail = recipientEmail.trim();
            }

            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '✉️ Sending...';

            const formData = new FormData();
            formData.append('email', recipientEmail);
            formData.append('tracking_number', trackingNo);
            formData.append('customer_name', name);
            formData.append('order_status', status);
            formData.append('total_amount', total);
            formData.append('paid_amount', paid);

            const emailSubject = `Aquamarine Laundry Digital Receipt #${trackingNo}`;
            const emailBody = `Aquamarine Laundry Management System\nDigital Order Receipt #${trackingNo}\n-----------------------------------\nCustomer: ${name}\nStatus: ${status}\nTotal Bill: ₱${total.toFixed(2)}\nAmount Paid: ₱${paid.toFixed(2)}\nBalance Due: ₱${balance.toFixed(2)}\n-----------------------------------\nThank you for choosing Aquamarine Laundry!`;

            fetch('send_email_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;

                if (data.success) {
                    alert(`✅ DIGITAL RECEIPT EMAIL SENT SUCCESSFULLY!\n\nCustomer: ${name}\nRecipient Email: ${recipientEmail}\nReceipt #: ${trackingNo}\nStatus: ${status}`);
                } else {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(emailBody);
                    }
                    const mailtoUrl = `mailto:${recipientEmail}?subject=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
                    window.location.href = mailtoUrl;
                    alert(`✅ DIGITAL RECEIPT EMAIL DISPATCHED FOR ${name}!\n\nRecipient: ${recipientEmail}\n1. Digital Receipt summary copied to clipboard.\n2. Opening default Email client.`);
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(emailBody);
                }
                const mailtoUrl = `mailto:${recipientEmail}?subject=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
                window.location.href = mailtoUrl;
                alert(`✅ DIGITAL RECEIPT EMAIL DISPATCHED FOR ${name}!\n\nRecipient: ${recipientEmail}\n1. Digital Receipt summary copied to clipboard.\n2. Opening default Email client.`);
            });
        }

        function triggerEmailModalDispatch(btn) {
            const emailInput = document.getElementById('email_modal_recipient_input');
            const recipientEmail = emailInput ? emailInput.value.trim() : '';
            const trackingNo = btn.getAttribute('data-tracking') || '';
            const name = btn.getAttribute('data-name') || 'Customer';
            const emailBody = document.getElementById('emailBodyText') ? document.getElementById('emailBodyText').value : '';
            const emailSubject = `Official Digital Receipt - Aquamarine Laundry Order #${trackingNo}`;

            if (!recipientEmail) {
                alert('Please enter a valid customer email address to send the digital receipt.');
                if (emailInput) emailInput.focus();
                return;
            }

            const originalText = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '✉️ Dispatching Email...';

            const status = btn.getAttribute('data-status') || 'Completed';

            const formData = new FormData();
            formData.append('email', recipientEmail);
            formData.append('tracking_number', trackingNo);
            formData.append('customer_name', name);
            formData.append('order_status', status);

            fetch('send_email_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(r => r.json())
            .then(data => {
                btn.disabled = false;
                btn.innerHTML = originalText;

                if (data.success) {
                    alert(`✅ DIGITAL EMAIL RECEIPT SENT SUCCESSFULLY!\n\nCustomer: ${name}\nRecipient: ${recipientEmail}\nTracking #: ${trackingNo}`);
                    const modal = document.getElementById('emailReceiptModal');
                    if (modal) modal.classList.add('hidden');
                } else {
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(emailBody);
                    }
                    const mailtoUrl = `mailto:${recipientEmail}?subject=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
                    window.location.href = mailtoUrl;
                    alert(`✅ DIGITAL EMAIL RECEIPT DISPATCHED FOR ${name}!\n\nRecipient: ${recipientEmail}\n1. Receipt content copied to clipboard.\n2. Opening default Email app.`);
                    const modal = document.getElementById('emailReceiptModal');
                    if (modal) modal.classList.add('hidden');
                }
            })
            .catch(err => {
                btn.disabled = false;
                btn.innerHTML = originalText;
                if (navigator.clipboard && navigator.clipboard.writeText) {
                    navigator.clipboard.writeText(emailBody);
                }
                const mailtoUrl = `mailto:${recipientEmail}?subject=${encodeURIComponent(emailSubject)}&body=${encodeURIComponent(emailBody)}`;
                window.location.href = mailtoUrl;
                alert(`✅ DIGITAL EMAIL RECEIPT DISPATCHED FOR ${name}!\n\nRecipient: ${recipientEmail}\n1. Receipt content copied to clipboard.\n2. Opening default Email app.`);
                const modal = document.getElementById('emailReceiptModal');
                if (modal) modal.classList.add('hidden');
            });
        }

        function settleBalanceFromBtn(btn) {
            const data = {
                id: btn.getAttribute('data-id'),
                tracking_number: btn.getAttribute('data-tracking') || '',
                customer_name: btn.getAttribute('data-name') || '',
                total_amount: parseFloat(btn.getAttribute('data-total')) || 0,
                paid_amount: parseFloat(btn.getAttribute('data-paid')) || 0,
                balance: parseFloat(btn.getAttribute('data-balance')) || 0
            };
            settleBalance(data);
        }

        function openSmsModal(data) {
            const trackEl = document.getElementById('notify_tracking_no');
            const nameEl = document.getElementById('notify_customer_name');
            const phoneEl = document.getElementById('notify_contact_no');
            const rackEl = document.getElementById('notify_rack_loc');
            const textEl = document.getElementById('notifyText');
            
            if (trackEl) trackEl.innerText = data.tracking_number || '';
            if (nameEl) nameEl.innerText = data.customer_name || '';
            if (phoneEl) phoneEl.innerText = data.contact || 'N/A';
            if (rackEl) rackEl.innerText = data.rack || 'Front Desk Rack';
            if (textEl) textEl.value = data.message || '';
            
            const resBox = document.getElementById('notify_result_box');
            if (resBox) resBox.classList.add('hidden');
            
            const modal = document.getElementById('notifyModal');
            if (modal) modal.classList.remove('hidden');
        }

        function showTopLeftToast(type, text) {
            let container = document.getElementById('toast_notification');
            if (!container) {
                container = document.createElement('div');
                container.id = 'toast_notification';
                container.className = 'fixed top-6 left-6 z-[9999] max-w-md w-full space-y-3 transition-all duration-500 transform translate-x-0';
                document.body.appendChild(container);
            }
            
            const isSuccess = type === 'success';
            const bgClass = isSuccess ? 'bg-emerald-50 border-emerald-500 text-emerald-800' : 'bg-rose-50 border-rose-500 text-rose-800';
            const iconName = isSuccess ? 'check_circle' : 'error';
            const iconColor = isSuccess ? 'text-emerald-600' : 'text-rose-600';

            const toastItem = document.createElement('div');
            toastItem.className = `${bgClass} border-l-4 p-4 rounded-xl shadow-2xl flex items-center justify-between gap-3`;
            toastItem.innerHTML = `
                <p class="font-bold flex items-center gap-2 text-sm">
                    <span class="material-symbols-outlined text-base ${iconColor}">${iconName}</span> ${text}
                </p>
                <button onclick="this.parentElement.remove()" class="font-bold text-lg leading-none cursor-pointer">&times;</button>
            `;
            container.appendChild(toastItem);

            setTimeout(() => {
                toastItem.style.opacity = '0';
                toastItem.style.transform = 'translateX(-120%)';
                setTimeout(() => toastItem.remove(), 500);
            }, 5000);
        }

        function sendCustomMobileSMS(btn) {
            const phoneEl = document.getElementById('notify_contact_no');
            const phone = phoneEl ? phoneEl.innerText.trim() : '';
            const messageEl = document.getElementById('notifyText');
            const message = messageEl ? messageEl.value.trim() : '';
            
            if (!phone || phone === 'N/A') {
                alert('⚠️ No contact phone number provided for this customer.');
                return;
            }
            if (!message) {
                alert('⚠️ Message text cannot be empty.');
                return;
            }
            sendMobileSMS(phone, message, btn);
        }

        function sendMobileSMS(phone, message, btn) {
            if (!phone || phone === 'N/A') {
                alert('⚠️ No contact phone number provided for this customer.');
                return;
            }

            const trackingNoEl = document.getElementById('notify_tracking_no');
            const trackingNo = trackingNoEl ? trackingNoEl.innerText.trim() : '';
            const resBox = document.getElementById('notify_result_box');

            let originalText = btn ? btn.innerHTML : '';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<span class="material-symbols-outlined text-base animate-spin">sync</span> Sending SMS to Customer...';
            }

            const formData = new FormData();
            formData.append('phone', phone);
            formData.append('message', message);
            if (trackingNo) {
                formData.append('tracking_number', trackingNo);
            }

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

                if (data.success) {
                    const successMsg = data.message || `SMS Sent Successfully! Message delivered to ${phone}.`;
                    if (resBox) {
                        resBox.className = 'p-3 rounded-xl text-xs mb-3 font-bold bg-emerald-100 text-emerald-900 border border-emerald-300 block';
                        resBox.innerHTML = `✅ ${successMsg}`;
                    }
                    showTopLeftToast('success', successMsg);
                    alert(`✅ SMS Dispatch Successful!\n\n${successMsg}`);
                } else {
                    const errorMsg = data.message || 'Could not send SMS to customer.';
                    if (resBox) {
                        resBox.className = 'p-3 rounded-xl text-xs mb-3 font-bold bg-rose-100 text-rose-900 border border-rose-300 block';
                        resBox.innerHTML = `❌ SMS Dispatch Failed: ${errorMsg}`;
                    }
                    showTopLeftToast('error', `SMS Dispatch Failed: ${errorMsg}`);
                    
                    if (confirm(`❌ SMS Gateway Error:\n\n${errorMsg}\n\nWould you like to open your device's native SMS app to send this message directly?`)) {
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
                const failMsg = 'Network or server connection issue while dispatching SMS.';
                if (resBox) {
                    resBox.className = 'p-3 rounded-xl text-xs mb-3 font-bold bg-rose-100 text-rose-900 border border-rose-300 block';
                    resBox.innerHTML = `❌ ${failMsg}`;
                }
                showTopLeftToast('error', failMsg);
                if (confirm(`❌ ${failMsg}\n\nWould you like to open your device's native SMS app to send this message directly?`)) {
                    let cleanPhone = phone.replace(/[^0-9+]/g, '');
                    let isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
                    let smsUrl = isIOS ? `sms:${cleanPhone};body=${encodeURIComponent(message)}` : `sms:${cleanPhone}?body=${encodeURIComponent(message)}`;
                    window.location.href = smsUrl;
                }
            });
        }

        let currentCollectBalance = 0;

        function openCollectModal(orderId, trackingNo, customerName, balance) {
            currentCollectBalance = parseFloat(balance) || 0;
            document.getElementById('collect_order_id').value = orderId;
            document.getElementById('collect_tracking_no').innerText = trackingNo;
            document.getElementById('collect_customer_name').innerText = customerName;
            document.getElementById('collect_balance_text').innerText = '₱' + currentCollectBalance.toFixed(2);
            
            const tendInput = document.getElementById('collect_tendered_input');
            if (tendInput) {
                tendInput.value = currentCollectBalance.toFixed(2);
            }
            
            calcCollectChange();
            toggleCollectPaymentFields();
            document.getElementById('collectModal').classList.remove('hidden');
        }

        function calcCollectChange() {
            const tendVal = document.getElementById('collect_tendered_input');
            const pmSelect = document.getElementById('collect_payment_method');
            const btn = document.getElementById('collect_submit_btn');
            const changeDisplay = document.getElementById('collect_change_display');
            const shortageAlertEl = document.getElementById('collect_shortage_alert');
            const shortageAmountEl = document.getElementById('collect_shortage_amount');

            if (!tendVal || !changeDisplay) return;

            const tend = parseFloat(tendVal.value) || 0;
            const pm = pmSelect ? pmSelect.value : 'Cash';

            if (pm === 'Cash' && tend > 0 && tend < currentCollectBalance && currentCollectBalance > 0) {
                const short = currentCollectBalance - tend;
                if (shortageAlertEl) {
                    shortageAlertEl.classList.remove('hidden');
                    if (shortageAmountEl) shortageAmountEl.innerText = '₱' + short.toFixed(2);
                }
                changeDisplay.className = 'font-black font-mono text-emerald-700 text-base';
                changeDisplay.innerText = '₱0.00';

                if (btn) {
                    btn.disabled = true;
                    btn.className = 'flex-1 bg-gray-300 text-gray-500 font-bold py-2.5 rounded-xl text-xs flex items-center justify-center gap-1 cursor-not-allowed opacity-75';
                    btn.innerHTML = `<span class="material-symbols-outlined text-xs">block</span> Short by ₱${short.toFixed(2)}`;
                }
            } else {
                if (shortageAlertEl) shortageAlertEl.classList.add('hidden');
                const change = Math.max(0, tend - currentCollectBalance);
                changeDisplay.className = 'font-black font-mono text-emerald-700 text-base';
                changeDisplay.innerText = '₱' + change.toFixed(2);

                if (btn) {
                    btn.disabled = false;
                    btn.className = 'flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-black py-2.5 rounded-xl text-xs shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer';
                    btn.innerHTML = `<span class="material-symbols-outlined text-xs">check_circle</span> Confirm Payment`;
                }
            }
        }

        function setCollectExactTendered() {
            document.getElementById('collect_tendered_input').value = currentCollectBalance.toFixed(2);
            calcCollectChange();
        }

        function setCollectTenderedVal(val) {
            document.getElementById('collect_tendered_input').value = parseFloat(val).toFixed(2);
            calcCollectChange();
        }

        function toggleCollectPaymentFields() {
            const pmSelect = document.getElementById('collect_payment_method');
            if (!pmSelect) return;
            const pm = pmSelect.value;
            const gcashContainer = document.getElementById('collect_gcash_ref_container');
            const cashContainer = document.getElementById('collect_cash_container');
            
            if (pm === 'GCash') {
                if (gcashContainer) gcashContainer.classList.remove('hidden');
                if (cashContainer) cashContainer.classList.add('hidden');
                const qrModal = document.getElementById('qrModal');
                if (qrModal) qrModal.classList.remove('hidden');
            } else {
                if (gcashContainer) gcashContainer.classList.add('hidden');
                if (cashContainer) cashContainer.classList.remove('hidden');
            }
            calcCollectChange();
        }

        function validateCreateOrderForm(form) {
            const pmSelect = document.getElementById('new_payment_method');
            const refInput = document.getElementById('new_reference_no');
            
            if (pmSelect && pmSelect.value === 'GCash') {
                const refVal = refInput ? refInput.value.trim() : '';
                if (!refVal) {
                    alert('⚠️ GCash Reference # / Transaction Ref No. is required when paying via GCash!\n\nPlease enter the transaction reference number before submitting.');
                    if (refInput) {
                        refInput.focus();
                        refInput.classList.add('border-rose-500', 'ring-2', 'ring-rose-400');
                    }
                    return false;
                }
            } else if (!pmSelect || pmSelect.value === 'Cash') {
                const initialPay = parseFloat(document.getElementById('new_initial_payment').value) || 0;
                const tendered = parseFloat(document.getElementById('new_tendered_input').value) || 0;

                if (initialPay > 0 && tendered < initialPay) {
                    const shortage = initialPay - tendered;
                    alert(`⚠️ INSUFFICIENT CASH TENDERED!\n\nThe required initial payment amount is ₱${initialPay.toFixed(2)}, but the customer provided ₱${tendered.toFixed(2)}.\n\nCustomer is SHORT by ₱${shortage.toFixed(2)}.\n\nPlease ask the customer for the remaining ₱${shortage.toFixed(2)} or select Partial / Pay Later.`);
                    const tendInput = document.getElementById('new_tendered_input');
                    if (tendInput) {
                        tendInput.focus();
                        tendInput.classList.add('border-rose-500', 'ring-2', 'ring-rose-400');
                    }
                    return false;
                }
            }
            return true;
        }

        function validateCollectPaymentForm(form) {
            const pmSelect = document.getElementById('collect_payment_method');
            const refInput = form.querySelector('[name="reference_no"]');
            if (pmSelect && pmSelect.value === 'GCash') {
                const refVal = refInput ? refInput.value.trim() : '';
                if (!refVal) {
                    alert('⚠️ GCash Reference / Control # is required when paying via GCash!\n\nPlease enter the transaction reference number before submitting.');
                    if (refInput) {
                        refInput.focus();
                        refInput.classList.add('border-rose-500', 'ring-2', 'ring-rose-400');
                    }
                    return false;
                }
            } else if (!pmSelect || pmSelect.value === 'Cash') {
                const tend = parseFloat(document.getElementById('collect_tendered_input').value) || 0;
                if (currentCollectBalance > 0 && tend < currentCollectBalance) {
                    const shortage = currentCollectBalance - tend;
                    alert(`⚠️ INSUFFICIENT CASH TENDERED!\n\nThe balance due is ₱${currentCollectBalance.toFixed(2)}, but the customer provided ₱${tend.toFixed(2)}.\n\nCustomer is SHORT by ₱${shortage.toFixed(2)}.\n\nPlease ask the customer for the remaining ₱${shortage.toFixed(2)}.`);
                    const tendInput = document.getElementById('collect_tendered_input');
                    if (tendInput) {
                        tendInput.focus();
                        tendInput.classList.add('border-rose-500', 'ring-2', 'ring-rose-400');
                    }
                    return false;
                }
            }
            return true;
        }

        /* CREATE LAUNDRY ORDER DRAFT PERSISTENCE MANAGER */
        const DRAFT_KEY = 'aquamarine_draft_order';

        function saveOrderDraft() {
            const modal = document.getElementById('newOrderModal');
            if (!modal || modal.classList.contains('hidden')) {
                return;
            }

            const step1 = document.getElementById('create_order_step1');
            const step2 = document.getElementById('create_order_step2');
            const currentStep = (step2 && !step2.classList.contains('hidden')) ? 2 : 1;

            const addons = Array.from(document.querySelectorAll('#create_order_form input[name="addons[]"]:checked'))
                                .map(cb => cb.value);

            const serviceTypeRadio = document.querySelector('#create_order_form input[name="service_type"]:checked');
            const serviceType = serviceTypeRadio ? serviceTypeRadio.value : 'walk_in';

            const payTypeRadio = document.querySelector('#create_order_form input[name="payment_type_choice"]:checked');
            const payTypeChoice = payTypeRadio ? payTypeRadio.value : 'full';

            const draftData = {
                isOpen: true,
                step: currentStep,
                customer_id: document.getElementById('new_customer_select')?.value || '',
                service_id: document.getElementById('new_service_select')?.value || '',
                weight_kg: document.getElementById('new_weight_input')?.value || '',
                discount: document.getElementById('new_discount_input')?.value || '0.00',
                addons: addons,
                service_type: serviceType,
                delivery_fee: document.getElementById('new_delivery_fee')?.value || '50.00',
                delivery_address: document.getElementById('new_delivery_address')?.value || '',
                notes: document.querySelector('#create_order_form textarea[name="notes"]')?.value || '',
                initial_payment: document.getElementById('new_initial_payment')?.value || '0.00',
                payment_method: document.getElementById('new_payment_method')?.value || 'Cash',
                tendered_amount: document.getElementById('new_tendered_input')?.value || '0.00',
                reference_no: document.getElementById('new_reference_no')?.value || '',
                payment_type_choice: payTypeChoice
            };

            sessionStorage.setItem(DRAFT_KEY, JSON.stringify(draftData));
        }

        function clearOrderDraft() {
            sessionStorage.removeItem(DRAFT_KEY);
        }

        function openNewOrderModal() {
            const modal = document.getElementById('newOrderModal');
            if (modal) {
                clearCustomerSearch();
                modal.classList.remove('hidden');
                saveOrderDraft();
            }
        }

        function closeNewOrderModal() {
            const modal = document.getElementById('newOrderModal');
            if (modal) {
                clearCustomerSearch();
                modal.classList.add('hidden');
                clearOrderDraft();
            }
        }

        function filterCustomerSelectOptions() {
            const input = document.getElementById('customer_search_input');
            const select = document.getElementById('new_customer_select');
            const clearBtn = document.getElementById('clear_customer_search_btn');
            const countBadge = document.getElementById('customer_search_count');
            if (!input || !select) return;

            const query = input.value.toLowerCase().trim();
            if (clearBtn) {
                if (query.length > 0) {
                    clearBtn.classList.remove('hidden');
                } else {
                    clearBtn.classList.add('hidden');
                }
            }

            const options = Array.from(select.options);
            let matchCount = 0;
            let totalOptions = 0;

            options.forEach(opt => {
                if (opt.value === "") {
                    opt.hidden = false;
                    opt.style.display = "";
                    return;
                }
                totalOptions++;
                const text = opt.textContent.toLowerCase();
                if (query === "" || text.includes(query)) {
                    opt.hidden = false;
                    opt.style.display = "";
                    matchCount++;
                } else {
                    opt.hidden = true;
                    opt.style.display = "none";
                }
            });

            if (countBadge) {
                if (query.length > 0) {
                    countBadge.innerText = `${matchCount} of ${totalOptions} matching`;
                    countBadge.classList.remove('hidden');
                } else {
                    countBadge.classList.add('hidden');
                }
            }
        }

        function clearCustomerSearch() {
            const input = document.getElementById('customer_search_input');
            if (input) {
                input.value = '';
                filterCustomerSelectOptions();
            }
        }

        function restoreOrderDraft() {
            const saved = sessionStorage.getItem(DRAFT_KEY);
            if (!saved) {
                calcCreateOrderBilling();
                return;
            }

            try {
                const draft = JSON.parse(saved);
                if (!draft || !draft.isOpen) {
                    calcCreateOrderBilling();
                    return;
                }

                const modal = document.getElementById('newOrderModal');
                if (modal) modal.classList.remove('hidden');

                if (draft.customer_id) {
                    const custSelect = document.getElementById('new_customer_select');
                    if (custSelect) {
                        custSelect.value = draft.customer_id;
                        if (typeof checkCustomerLoyalty === 'function') checkCustomerLoyalty();
                    }
                }
                if (draft.service_id) {
                    const servSelect = document.getElementById('new_service_select');
                    if (servSelect) servSelect.value = draft.service_id;
                }
                if (draft.weight_kg !== undefined) {
                    const weightIn = document.getElementById('new_weight_input');
                    if (weightIn) weightIn.value = draft.weight_kg;
                }
                if (draft.discount !== undefined) {
                    const discIn = document.getElementById('new_discount_input');
                    if (discIn) discIn.value = draft.discount;
                }
                if (draft.addons && Array.isArray(draft.addons)) {
                    document.querySelectorAll('#create_order_form input[name="addons[]"]').forEach(cb => {
                        cb.checked = draft.addons.includes(cb.value);
                    });
                }
                if (draft.service_type) {
                    if (typeof selectServiceMode === 'function') {
                        selectServiceMode(draft.service_type);
                    }
                }
                if (draft.delivery_fee !== undefined) {
                    const delFee = document.getElementById('new_delivery_fee');
                    if (delFee) delFee.value = draft.delivery_fee;
                }
                if (draft.delivery_address !== undefined) {
                    const delAddr = document.getElementById('new_delivery_address');
                    if (delAddr) delAddr.value = draft.delivery_address;
                }
                if (draft.notes !== undefined) {
                    const notesEl = document.querySelector('#create_order_form textarea[name="notes"]');
                    if (notesEl) notesEl.value = draft.notes;
                }
                if (draft.initial_payment !== undefined) {
                    const initPay = document.getElementById('new_initial_payment');
                    if (initPay) initPay.value = draft.initial_payment;
                }
                if (draft.payment_method) {
                    const pmSelect = document.getElementById('new_payment_method');
                    if (pmSelect) {
                        pmSelect.value = draft.payment_method;
                        if (typeof toggleGcashRefInput === 'function') toggleGcashRefInput();
                    }
                }
                if (draft.tendered_amount !== undefined) {
                    const tendIn = document.getElementById('new_tendered_input');
                    if (tendIn) tendIn.value = draft.tendered_amount;
                }
                if (draft.reference_no !== undefined) {
                    const refIn = document.getElementById('new_reference_no');
                    if (refIn) refIn.value = draft.reference_no;
                }
                if (draft.payment_type_choice) {
                    const radio = document.querySelector(`#create_order_form input[name="payment_type_choice"][value="${draft.payment_type_choice}"]`);
                    if (radio) radio.checked = true;
                }

                calcCreateOrderBilling();

                if (draft.step === 2) {
                    if (typeof goToOrderStep2 === 'function') goToOrderStep2();
                } else {
                    if (typeof goToOrderStep1 === 'function') goToOrderStep1();
                }
            } catch (e) {
                console.error('Error restoring order draft:', e);
                calcCreateOrderBilling();
            }
        }
    </script>

    <!-- Modal: Collect Outstanding Balance Upon Pickup / Delivery -->
    <div id="collectModal" class="hidden fixed inset-0 bg-black/60 backdrop-blur-xs z-50 flex items-center justify-center p-4">
        <div class="bg-white max-w-md w-full p-6 rounded-2xl shadow-2xl border border-gray-200 space-y-4">
            <div class="flex justify-between items-center pb-3 border-b border-gray-200">
                <div class="flex items-center gap-2">
                    <div class="w-9 h-9 bg-amber-100 text-amber-900 rounded-xl flex items-center justify-center font-bold">
                        <span class="material-symbols-outlined">payments</span>
                    </div>
                    <div>
                        <h3 class="text-base font-black font-headline text-gray-900">Collect Outstanding Balance</h3>
                        <p class="text-3xs text-gray-500 font-medium">Settle payment upon pickup or delivery release</p>
                    </div>
                </div>
                <button onclick="document.getElementById('collectModal').classList.add('hidden')" class="text-gray-400 hover:text-gray-700 font-black text-lg cursor-pointer">&times;</button>
            </div>

            <form method="POST" id="collect_payment_form" class="space-y-4" onsubmit="return validateCollectPaymentForm(this);">
                <input type="hidden" name="action" value="collect_payment">
                <input type="hidden" name="order_id" id="collect_order_id">

                <div class="bg-amber-50 border border-amber-200 p-3.5 rounded-xl space-y-1">
                    <div class="flex justify-between text-xs">
                        <span class="text-gray-600 font-medium">Tracking Number:</span>
                        <span class="font-black font-mono text-blue-700" id="collect_tracking_no"></span>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span class="text-gray-600 font-medium">Customer:</span>
                        <span class="font-extrabold text-gray-900" id="collect_customer_name"></span>
                    </div>
                    <div class="flex justify-between text-sm pt-2 border-t border-amber-200/80">
                        <span class="font-black text-amber-950">Outstanding Balance Due:</span>
                        <span class="font-black font-mono text-amber-900 text-base" id="collect_balance_text">₱0.00</span>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-extrabold text-gray-700 mb-1">Select Payment Method *</label>
                    <select name="payment_method" id="collect_payment_method" onchange="toggleCollectPaymentFields()" class="w-full text-xs font-bold p-2.5 border border-gray-300 rounded-xl bg-white focus:ring-2 focus:ring-amber-500 cursor-pointer">
                        <option value="Cash">💵 Cash</option>
                        <option value="GCash">📱 GCash E-Wallet</option>
                    </select>
                </div>

                <!-- CASH TENDERED & CHANGE CALCULATION CONTAINER -->
                <div id="collect_cash_container" class="space-y-2 bg-emerald-50/80 border border-emerald-200 p-3.5 rounded-xl">
                    <div>
                        <div class="flex justify-between items-center mb-1">
                            <label class="block text-xs font-extrabold text-emerald-950">Cash Provided by Customer (₱) *</label>
                            <button type="button" onclick="setCollectExactTendered()" class="text-3xs font-bold bg-white text-emerald-800 border border-emerald-300 px-2 py-0.5 rounded-lg hover:bg-emerald-100 transition-colors shadow-2xs cursor-pointer">
                                Exact Amount
                            </button>
                        </div>
                        <input type="number" step="0.01" min="0" name="tendered_amount" id="collect_tendered_input" oninput="calcCollectChange()" required class="w-full text-sm font-black font-mono p-2.5 border border-gray-300 rounded-xl bg-white focus:ring-2 focus:ring-emerald-500">
                    </div>

                    <!-- QUICK BILL BUTTON SHORTCUTS -->
                    <div class="flex gap-1.5 pt-0.5">
                        <button type="button" onclick="setCollectTenderedVal(500)" class="flex-1 bg-white hover:bg-emerald-100 text-emerald-900 border border-emerald-300 font-extrabold py-1 text-2xs rounded-lg shadow-2xs cursor-pointer">₱500</button>
                        <button type="button" onclick="setCollectTenderedVal(1000)" class="flex-1 bg-white hover:bg-emerald-100 text-emerald-900 border border-emerald-300 font-extrabold py-1 text-2xs rounded-lg shadow-2xs cursor-pointer">₱1,000</button>
                    </div>

                    <div class="flex justify-between items-center pt-2 border-t border-emerald-200/80 text-xs">
                        <span class="font-extrabold text-gray-700">Change to Return to Customer:</span>
                        <span id="collect_change_display" class="font-black font-mono text-emerald-700 text-base">₱0.00</span>
                    </div>

                    <!-- Dedicated Insufficient Cash Shortage Alert Below Cash Tendered -->
                    <div id="collect_shortage_alert" class="hidden mt-2 p-2.5 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-bold flex items-center justify-between shadow-2xs">
                        <div class="flex items-center gap-1.5">
                            <span class="material-symbols-outlined text-base text-rose-600">warning</span>
                            <span>Insufficient Cash! Short by <strong id="collect_shortage_amount" class="font-mono text-rose-700 text-sm">₱0.00</strong></span>
                        </div>
                        <span class="text-3xs bg-rose-200/80 text-rose-900 px-2 py-0.5 rounded-full font-black uppercase tracking-wider">Shortage</span>
                    </div>
                </div>

                <!-- GCash Reference Number & QR Code Input Box -->
                <div id="collect_gcash_ref_container" class="hidden p-3 bg-blue-50 border border-blue-200 rounded-xl space-y-2">
                    <div class="flex justify-between items-center">
                        <label class="block text-xs font-extrabold text-blue-950 flex items-center gap-1">
                            <span class="material-symbols-outlined text-base text-blue-600">badge</span> GCash Reference / Control #
                        </label>
                        <?php if (!empty($qrImage) && file_exists(__DIR__ . '/' . $qrImage)): ?>
                            <button type="button" onclick="document.getElementById('qrModal').classList.remove('hidden')" class="text-3xs font-bold bg-blue-600 text-white px-2 py-0.5 rounded-lg hover:bg-blue-700 transition-colors flex items-center gap-1 shadow-2xs">
                                <span class="material-symbols-outlined text-3xs">qr_code_scanner</span> View Full QR
                            </button>
                        <?php endif; ?>
                    </div>
                    <input type="text" name="reference_no" placeholder="e.g. 1000 234 567" class="w-full text-xs font-mono font-bold p-2 border border-blue-300 rounded-lg bg-white">

                    <!-- Inline GCash QR Code Preview Box -->
                    <?php if (!empty($qrImage) && file_exists(__DIR__ . '/' . $qrImage)): ?>
                        <div class="bg-white p-2.5 rounded-xl border border-blue-200 text-center space-y-1">
                            <div class="text-3xs font-bold text-blue-900 flex items-center justify-center gap-1">
                                <span class="material-symbols-outlined text-xs text-blue-600">qr_code_2</span> Official Store GCash QR Code
                            </div>
                            <img src="<?= htmlspecialchars($qrImage) ?>" alt="GCash QR Code" class="w-48 h-48 object-contain mx-auto border border-gray-200 rounded-lg p-1 bg-white shadow-2xs hover:scale-105 transition-transform cursor-pointer" onclick="document.getElementById('qrModal').classList.remove('hidden')" title="Click to view full size QR code">
                            <?php if (!empty($gcashNumber)): ?>
                                <div class="bg-blue-50 border border-blue-200 rounded-lg p-1.5 flex items-center justify-between gap-1 text-left">
                                    <span class="text-3xs font-black font-mono text-blue-950 flex items-center gap-1">
                                        <span class="material-symbols-outlined text-xs text-blue-600">smartphone</span> <?= htmlspecialchars($gcashNumber) ?>
                                    </span>
                                    <button type="button" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($gcashNumber) ?>'); alert('Copied GCash Number: <?= htmlspecialchars($gcashNumber) ?>');" class="bg-blue-600 text-white font-bold text-3xs px-2 py-0.5 rounded cursor-pointer hover:bg-blue-700">Copy</button>
                                </div>
                            <?php endif; ?>
                            <p class="text-3xs text-gray-500 font-medium">Scan using GCash app or send to number above</p>
                        </div>
                    <?php else: ?>
                        <div class="bg-amber-50 p-2 rounded-lg border border-amber-200 text-3xs text-amber-900 font-medium flex items-center gap-1">
                            <span class="material-symbols-outlined text-xs text-amber-700">info</span> No GCash QR image uploaded yet. (Upload in Admin Settings -> Store Operations).
                        </div>
                    <?php endif; ?>
                </div>

                <div class="flex gap-2 pt-2">
                    <button type="button" onclick="document.getElementById('collectModal').classList.add('hidden')" class="flex-1 bg-gray-100 hover:bg-gray-200 text-gray-700 font-bold py-2.5 rounded-xl text-xs transition-all cursor-pointer">Cancel</button>
                    <button type="submit" id="collect_submit_btn" class="flex-1 bg-emerald-600 hover:bg-emerald-700 text-white font-black py-2.5 rounded-xl text-xs shadow-md transition-all flex items-center justify-center gap-1 cursor-pointer">
                        <span class="material-symbols-outlined text-xs">check_circle</span> Confirm Payment
                    </button>
                </div>
            </form>
        </div>
    </div>

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
                const payOrderId = parseInt(urlParams.get('pay_order_id'));
                if (payOrderId > 0) {
                    const btn = document.querySelector(`button[onclick*="settleBalance"][onclick*="${payOrderId}"]`);
                    if (btn) {
                        btn.click();
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
