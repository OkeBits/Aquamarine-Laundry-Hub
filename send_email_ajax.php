<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

if (file_exists(__DIR__ . '/config/auth.php')) {
    require_once __DIR__ . '/config/auth.php';
}

if (!function_exists('logNotification')) {
    function logNotification(?PDO $pdo, ?int $customerId, ?int $orderId, string $type, string $recipient, string $message, string $status = 'Sent'): void {
        try {
            if ($pdo) {
                $stmt = $pdo->prepare("INSERT INTO notifications (customer_id, order_id, type, recipient, message, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customerId, $orderId, $type, $recipient, $message, $status]);
            }
        } catch (Exception $e) {
            // Log error silently
        }
    }
}

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$email = trim($_POST['email'] ?? '');
$trackingNo = trim($_POST['tracking_number'] ?? '');
$custName = trim($_POST['customer_name'] ?? 'Customer');
$orderStatus = trim($_POST['order_status'] ?? 'Completed');
$totalAmount = (float)($_POST['total_amount'] ?? 0);
$paidAmount = (float)($_POST['paid_amount'] ?? 0);

if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'message' => 'Valid recipient email address is required.']);
    exit;
}

$orderService = new OrderService();
$emailMsgKey = "Digital Receipt for Order $trackingNo ($custName)";

if ($orderService->isDuplicateMessageRequest($email, $emailMsgKey, 10)) {
    echo json_encode(['success' => false, 'message' => 'Duplicate Email request blocked! Email receipt was already sent a few seconds ago.']);
    exit;
}

$db = Database::getInstance();
$ord = !empty($trackingNo) ? $db->fetch("SELECT o.*, c.name as cust_name FROM orders o JOIN customers c ON o.customer_id = c.id WHERE o.tracking_number = ?", [$trackingNo]) : null;

$orderData = [
    'tracking_number' => $trackingNo,
    'customer_name' => $ord ? $ord['cust_name'] : $custName,
    'order_status' => !empty($orderStatus) ? $orderStatus : ($ord ? $ord['order_status'] : 'Completed'),
    'subtotal' => $ord ? (float)$ord['subtotal'] : $totalAmount,
    'discount' => $ord ? (float)$ord['discount'] : 0,
    'total_amount' => $ord ? (float)$ord['total_amount'] : $totalAmount,
    'paid_amount' => $ord ? (float)$ord['paid_amount'] : $paidAmount,
    'id' => $ord ? (int)$ord['id'] : 0
];

$res = $orderService->sendDigitalReceiptEmail($email, $orderData);

// Also log into notifications table
$pdo = $db->getPdo();
$emailBodyText = "Digital Receipt for Order #" . ($ord ? $ord['tracking_number'] : $trackingNo) . " (Total: ₱" . number_format($orderData['total_amount'], 2) . ")";
logNotification($pdo, $ord ? (int)$ord['customer_id'] : null, $ord ? (int)$ord['id'] : null, 'Email', $email, $emailBodyText, $res['success'] ? 'Sent' : 'Failed');

echo json_encode($res);
