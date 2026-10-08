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

$token = trim($_GET['token'] ?? '');
$id = (int)($_GET['id'] ?? 0);
$orderQuery = trim($_GET['order'] ?? ($_GET['tracking'] ?? ''));

$ord = null;
if (!empty($token)) {
    $ord = $db->fetch("
        SELECT o.*, c.name as customer_name, c.contact as customer_contact, c.address as customer_address, u.full_name as cashier_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.receipt_token = ? OR o.tracking_number = ? OR o.id = ?
    ", [$token, $token, is_numeric($token) ? (int)$token : 0]);
} elseif ($id > 0) {
    $ord = $db->fetch("
        SELECT o.*, c.name as customer_name, c.contact as customer_contact, c.address as customer_address, u.full_name as cashier_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.id = ?
    ", [$id]);
} elseif (!empty($orderQuery)) {
    $ord = $db->fetch("
        SELECT o.*, c.name as customer_name, c.contact as customer_contact, c.address as customer_address, u.full_name as cashier_name
        FROM orders o
        JOIN customers c ON o.customer_id = c.id
        LEFT JOIN users u ON o.created_by = u.id
        WHERE o.tracking_number = ? OR o.receipt_token = ?
    ", [$orderQuery, $orderQuery]);
}

if (!$ord) {
    echo "<div style='font-family:sans-serif; text-align:center; padding:50px;'><h2>Receipt Not Found</h2><a href='cashier_dashboard.php'>Back to POS</a></div>";
    exit;
}

$orderId = (int)$ord['id'];
$items = $db->fetchAll("
    SELECT oi.*, s.service_name 
    FROM order_items oi 
    LEFT JOIN services s ON oi.service_id = s.id 
    WHERE oi.order_id = ?
", [$orderId]);

$payments = $db->fetchAll("SELECT * FROM payments WHERE order_id = ? ORDER BY id ASC", [$orderId]);
$latestPayment = !empty($payments) ? end($payments) : null;

$amountPaid = (float)$ord['paid_amount'];
$totalAmount = (float)$ord['total_amount'];
$balanceDue = max(0, $totalAmount - $amountPaid);

$tenderedAmount = (float)($latestPayment['tendered_amount'] ?? 0);
if ($tenderedAmount <= 0) {
    $tenderedAmount = $amountPaid;
}
$change = max(0, $tenderedAmount - $amountPaid);

$custOrdersCount = (int)($db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE customer_id = ? AND order_status IN ('Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed')", [$ord['customer_id']])['cnt'] ?? 0);
$targetStamps = max(1, (int)($orderService->getSetting('loyalty_stamps_target', '11')));
$loyaltyRemainder = $custOrdersCount % $targetStamps;
$loyaltyStamps = ($custOrdersCount > 0 && $loyaltyRemainder === 0) ? $targetStamps : $loyaltyRemainder;

$paymentMethod = $latestPayment['payment_method'] ?? ($ord['payment_method'] ?? 'Cash');
if (empty($paymentMethod)) {
    $paymentMethod = 'Cash';
}
$refNo = $latestPayment['reference_no'] ?? ($ord['reference_no'] ?? '');

$shopName = $orderService->getSetting('shop_name', 'AQUAMARINE LAUNDRY');
$shopAddress = $orderService->getSetting('shop_address', '123 Rizal Ave, Metro Manila');
$shopPhone = $orderService->getSetting('shop_phone', '0917-123-4567');
$receiptFooter = $orderService->getSetting('receipt_footer', 'Thank you for trusting Aquamarine Laundry! Please present this receipt upon pickup.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Receipt #<?= htmlspecialchars($ord['tracking_number']) ?></title>
    <link href="assets/css/offline-tailwind.css" rel="stylesheet"/>
    <link href="assets/css/material-symbols.css" rel="stylesheet"/>
    <script src="assets/js/tailwindcss.js"></script>
    <style>
        * {
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        body { background: #e5e7eb; font-family: 'Inter', sans-serif; }
        h1, h2, h3, h4, .font-headline { font-family: 'Outfit', sans-serif; }
        .font-mono-num { font-family: 'JetBrains Mono', monospace; }
        @media print {
            .no-print { display: none !important; }
            body { background: white !important; padding: 0 !important; margin: 0 !important; }
            .receipt-paper { box-shadow: none !important; border: none !important; max-width: 100% !important; padding: 0 !important; }
            .receipt-paper img { width: 32px !important; height: 32px !important; max-width: 32px !important; max-height: 32px !important; }
        }
    </style>
</head>
<body class="min-h-screen p-4 flex flex-col items-center justify-center text-gray-800">

    <!-- Action Buttons -->
    <div class="no-print mb-4 flex gap-3">
        <button onclick="window.print()" class="bg-blue-600 hover:bg-blue-700 text-white font-bold px-6 py-2.5 rounded-xl shadow flex items-center gap-2 text-sm">
            <span class="material-symbols-outlined text-lg">print</span> Print Receipt
        </button>
        <a href="cashier_dashboard.php" class="bg-white text-gray-700 border font-bold px-5 py-2.5 rounded-xl text-sm hover:bg-gray-50 flex items-center gap-1 shadow-sm">
            <span class="material-symbols-outlined text-lg">arrow_back</span> Back to POS
        </a>
    </div>

    <!-- Real Thermal Paper Receipt Card -->
    <div class="receipt-paper bg-white max-w-sm w-full p-6 rounded-2xl border border-gray-300 shadow-lg text-xs space-y-4">
        
        <!-- Shop Header -->
        <div class="text-center space-y-1">
            <img src="assets/img/logo.png" alt="Aquamarine Logo" class="object-contain mx-auto mb-1 rounded-lg shadow-2xs" style="width: 32px; height: 32px; max-width: 32px; max-height: 32px;">
            <h2 class="text-xl font-black tracking-tight text-blue-700 uppercase"><?= htmlspecialchars($shopName) ?></h2>
            <p class="text-2xs text-gray-500"><?= htmlspecialchars($shopAddress) ?></p>
            <p class="text-2xs text-gray-500 font-semibold">Contact: <?= htmlspecialchars($shopPhone) ?></p>
            <div class="pt-2">
                <span class="border-y border-dashed border-gray-400 py-1 px-4 inline-block font-bold text-2xs uppercase text-gray-700 tracking-widest">
                    LAUNDRY RECEIPT
                </span>
            </div>
        </div>

        <!-- Receipt Metadata -->
        <div class="space-y-1 text-2xs border-b border-gray-200 pb-3">
            <div class="flex justify-between">
                <span class="text-gray-500">Order / Receipt #:</span>
                <strong class="font-mono-num font-bold text-blue-700 text-xs"><?= htmlspecialchars($ord['tracking_number']) ?></strong>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Date & Time:</span>
                <span class="font-medium text-gray-700"><?= date('M d, Y • h:i A', strtotime($ord['created_at'])) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Customer:</span>
                <strong class="font-bold text-gray-900 text-xs"><?= htmlspecialchars($ord['customer_name']) ?></strong>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Phone #:</span>
                <span class="font-mono-num"><?= htmlspecialchars($ord['customer_contact'] ?: 'N/A') ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">Attended By:</span>
                <span><?= htmlspecialchars($ord['cashier_name'] ?: 'Staff') ?></span>
            </div>
        </div>

        <!-- Ordered Items List -->
        <div class="space-y-2 border-b border-gray-200 pb-3">
            <div class="flex justify-between font-bold text-gray-500 text-2xs uppercase tracking-wider">
                <span>Item / Service</span>
                <span>Amount</span>
            </div>
            
            <?php foreach ($items as $it): 
                $displayName = !empty($it['item_details']) ? $it['item_details'] : ($it['service_name'] ?: 'Laundry Service');
                $displayName = preg_replace('/^Add-on:\s*/i', '', $displayName);
                $weightKg = (float)($it['weight_kg'] ?? 0);
                $qty = max(1, (int)($it['qty'] ?? 1));
                $weightQtyStr = $weightKg > 0 ? number_format($weightKg, 1) . ' kg' : ($qty > 1 ? $qty . ' pcs' : '1 pc');
            ?>
                <div class="flex justify-between items-start text-xs">
                    <div>
                        <div class="font-bold text-gray-900"><?= htmlspecialchars($displayName) ?></div>
                        <div class="text-3xs text-gray-500 font-medium"><?= $weightQtyStr ?> <?= ($it['unit_price'] ?? 0) > 0 && $weightKg <= 0 ? '(@ ₱' . number_format($it['unit_price'], 2) . ')' : '' ?></div>
                    </div>
                    <div class="font-mono-num font-bold text-gray-900">₱<?= number_format($it['subtotal'], 2) ?></div>
                </div>
            <?php endforeach; ?>

            <?php if (($ord['service_type'] ?? '') === 'delivery' || (float)($ord['delivery_fee'] ?? 0) > 0): ?>
                <div class="flex justify-between items-start text-xs text-amber-900 bg-amber-50 p-2 rounded-lg border border-amber-200">
                    <div>
                        <div class="font-bold">🚚 Doorstep Pick-up & Delivery</div>
                        <?php if (!empty($ord['delivery_address'])): ?>
                            <div class="text-3xs text-amber-800 font-medium mt-0.5">Address: <?= htmlspecialchars($ord['delivery_address']) ?></div>
                        <?php endif; ?>
                        <?php if (!empty($ord['driver_name'])): ?>
                            <div class="text-3xs text-amber-900 font-bold mt-0.5">Rider/Courier: <?= htmlspecialchars($ord['driver_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="font-mono-num font-bold text-amber-900">+₱<?= number_format((float)($ord['delivery_fee'] ?? 0), 2) ?></div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Totals & Payment Summary -->
        <div class="space-y-1.5 text-xs">
            <?php if ((float)$ord['discount'] > 0): ?>
                <div class="flex justify-between text-rose-700">
                    <span>Discount:</span>
                    <span class="font-mono-num font-bold">-₱<?= number_format($ord['discount'], 2) ?></span>
                </div>
            <?php endif; ?>

            <div class="flex justify-between items-center text-sm font-black text-gray-900 border-t border-gray-300 pt-2">
                <span>TOTAL AMOUNT:</span>
                <span class="font-mono-num text-blue-700 text-base">₱<?= number_format($totalAmount, 2) ?></span>
            </div>

            <div class="flex justify-between text-gray-600 text-2xs pt-1">
                <span>Payment Method:</span>
                <strong class="uppercase font-bold text-gray-800"><?= htmlspecialchars($paymentMethod) ?></strong>
            </div>

            <?php if (stripos($paymentMethod, 'gcash') !== false && !empty($refNo)): ?>
                <div class="flex justify-between text-blue-900 text-2xs bg-blue-50 p-1.5 rounded border border-blue-200 font-semibold">
                    <span>Reference / Trans #:</span>
                    <strong class="font-mono-num font-bold text-blue-800 text-xs"><?= htmlspecialchars($refNo) ?></strong>
                </div>
            <?php endif; ?>

            <div class="flex justify-between text-emerald-800 font-semibold">
                <span>Amount Paid:</span>
                <span class="font-mono-num font-bold">₱<?= number_format($amountPaid, 2) ?></span>
            </div>

            <?php if ($tenderedAmount > 0 && $tenderedAmount >= $amountPaid): ?>
                <div class="flex justify-between text-gray-600 text-2xs">
                    <span>Cash Tendered:</span>
                    <span class="font-mono-num">₱<?= number_format($tenderedAmount, 2) ?></span>
                </div>
                <div class="flex justify-between text-emerald-800 font-bold bg-emerald-50 p-1.5 rounded border border-emerald-200">
                    <span>CHANGE GIVEN:</span>
                    <span class="font-mono-num">₱<?= number_format($change, 2) ?></span>
                </div>
            <?php endif; ?>

            <!-- Payment Status -->
            <div class="pt-1">
                <?php if ($balanceDue <= 0): ?>
                    <div class="text-center font-black text-emerald-800 bg-emerald-100 py-1 rounded uppercase text-2xs border border-emerald-300">
                        ✓ FULLY PAID
                    </div>
                <?php else: ?>
                    <div class="flex justify-between font-bold text-amber-800 bg-amber-50 p-1.5 rounded border border-amber-300 text-xs">
                        <span>BALANCE DUE ON PICKUP:</span>
                        <span class="font-mono-num">₱<?= number_format($balanceDue, 2) ?></span>
                    </div>
                <?php endif; ?>
            </div>
        </div>


        <!-- Customer Loyalty Program Box -->
        <div class="bg-emerald-50/80 border border-emerald-200 p-2 rounded-xl text-center text-2xs space-y-0.5">
            <div class="font-bold text-emerald-950 flex items-center justify-center gap-1">
                ⭐ <?= $targetStamps ?>-ORDER LOYALTY STAMP CARD ⭐
            </div>
            <?php if ((float)$ord['discount'] > 0 && (float)$ord['total_amount'] == 0): ?>
                <div class="text-emerald-800 font-bold">🎁 LOYALTY REWARD DISCOUNT APPLIED!</div>
            <?php else: ?>
                <div class="text-gray-800 font-semibold">Loyalty Progress: <strong><?= $loyaltyStamps ?> / <?= $targetStamps ?> Stamps</strong> (<?= $custOrdersCount ?> Total Orders)</div>
                <div class="text-3xs text-emerald-800 font-bold"><?= max(0, $targetStamps - $loyaltyStamps) ?> more order<?= max(0, $targetStamps - $loyaltyStamps) === 1 ? '' : 's' ?> for Loyalty Reward (Up to <?= htmlspecialchars($orderService->getSetting('loyalty_max_kg', '8.0')) ?> kg / <?= htmlspecialchars($orderService->getSetting('loyalty_max_piece', '5')) ?> pcs Free)!</div>
            <?php endif; ?>
        </div>

        <!-- Footer -->
        <div class="text-center text-2xs text-gray-500 italic border-t border-dashed border-gray-300 pt-3">
            <?= htmlspecialchars($receiptFooter) ?>
        </div>

    </div>

</body>
</html>
