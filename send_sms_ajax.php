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

$phone = trim($_POST['phone'] ?? '');
$message = trim($_POST['message'] ?? '');
$trackingNo = trim($_POST['tracking_number'] ?? '');

if (empty($phone) || empty($message)) {
    echo json_encode(['success' => false, 'message' => 'Phone number and message text are required.']);
    exit;
}

$cleanPhone = preg_replace('/[^0-9+]/', '', $phone);
$isValidPhone = preg_match('/^(09\d{9}|\+639\d{9}|639\d{9}|9\d{9})$/', $cleanPhone);
if (!$isValidPhone) {
    echo json_encode(['success' => false, 'message' => 'Invalid phone number format! Please enter a valid 11-digit PH mobile number starting with 09 (e.g. 09171234567).']);
    exit;
}

$orderService = new OrderService();

// Block duplicate requests sent within 10 seconds
if ($orderService->isDuplicateMessageRequest($phone, $message, 10)) {
    echo json_encode(['success' => false, 'message' => 'Duplicate SMS request blocked! This message was already sent a few seconds ago.']);
    exit;
}

// Fetch customer ID & order ID if available
$db = Database::getInstance();
$ord = !empty($trackingNo) ? $db->fetch("SELECT id, customer_id FROM orders WHERE tracking_number = ?", [$trackingNo]) : null;

$custId = $ord ? (int)$ord['customer_id'] : 0;
$ordId = $ord ? (int)$ord['id'] : 0;

$res = $orderService->sendSMS($phone, $message, $custId, $ordId);

// Log to notifications table as well
$pdo = $db->getPdo();
logNotification($pdo, $custId ?: null, $ordId ?: null, 'SMS', $phone, $message, $res['success'] ? 'Sent' : 'Failed');

echo json_encode($res);
