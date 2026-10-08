<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) && !isset($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized access. Please login.']);
    exit;
}

$customerId = (int)($_GET['customer_id'] ?? 0);
if ($customerId <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid customer ID.']);
    exit;
}

$orderService = new OrderService();
$data = $orderService->getCustomerDetailedHistory($customerId);

if (empty($data)) {
    echo json_encode(['success' => false, 'message' => 'Customer profile not found.']);
    exit;
}

echo json_encode([
    'success' => true,
    'customer' => $data['customer'],
    'orders' => $data['orders'],
    'message_logs' => $data['message_logs'],
    'target_stamps' => $data['target_stamps'] ?? 11,
    'stamps' => $data['stamps'] ?? 0,
    'stamps_needed' => $data['stamps_needed'] ?? 11,
    'is_eligible' => $data['is_eligible'] ?? false
]);
