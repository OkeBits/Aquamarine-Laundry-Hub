<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$customerId = (int)($_POST['customer_id'] ?? 0);
$orderService = new OrderService();

if ($customerId > 0) {
    $orderService->clearCustomerMessageLogs($customerId);
    echo json_encode(['success' => true, 'message' => 'Customer message logs cleared successfully!']);
} else {
    $orderService->clearAllMessageLogs();
    echo json_encode(['success' => true, 'message' => 'All message dispatch logs cleared!']);
}
