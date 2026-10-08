<?php
require_once __DIR__ . '/app/config.php';
require_once __DIR__ . '/app/Database.php';
require_once __DIR__ . '/app/OrderService.php';

header('Content-Type: application/json');

$settingsToSave = [];

if (isset($_POST['sms_template_ready'])) {
    $templateReady = trim($_POST['sms_template_ready']);
    if (empty($templateReady)) {
        $templateReady = 'Aquamarine Laundry Hub: Good news {customer_name}! Your laundry (Receipt #{tracking_number}) is READY FOR PICKUP. Total: ₱{total_amount}, Paid: ₱{paid_amount}, Balance: ₱{balance_amount}. Thank you!';
    }
    $settingsToSave['sms_template_ready'] = $templateReady;
}

if (isset($_POST['sms_template_out_for_delivery'])) {
    $templateOut = trim($_POST['sms_template_out_for_delivery']);
    if (empty($templateOut)) {
        $templateOut = '🚚 Aquamarine Laundry Hub: Great news {customer_name}! Your laundry (Receipt #{tracking_number}) is OUT FOR DELIVERY! Assigned Rider: {driver_name}. Address: {delivery_address}. Total: ₱{total_amount}, Balance Due: ₱{balance_amount}. Please prepare payment. Thank you!';
    }
    $settingsToSave['sms_template_out_for_delivery'] = $templateOut;
}

if (isset($_POST['sms_template_received'])) {
    $templateReceived = trim($_POST['sms_template_received']);
    if (empty($templateReceived)) {
        $templateReceived = 'Thank you {customer_name}! We have received your laundry order #{tracking_number} at {shop_name}. Total: ₱{total_amount} | Est. pickup: {estimated_pickup}. We take great care of your clothes.';
    }
    $settingsToSave['sms_template_received'] = $templateReceived;
}

if (!empty($settingsToSave)) {
    $orderService = new OrderService();
    $orderService->saveSettings($settingsToSave);
}

echo json_encode([
    'success' => true, 
    'message' => 'SMS templates auto-saved successfully!'
]);
