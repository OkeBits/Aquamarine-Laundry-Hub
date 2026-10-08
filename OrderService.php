<?php
// Aquamarine Laundry Management Service System - OrderService Layer

require_once __DIR__ . '/Database.php';

class OrderService {
    private Database $db;

    public function __construct() {
        $this->db = Database::getInstance();
    }

    public function authenticateUser(string $username, string $password): ?array {
        $user = $this->db->fetch("SELECT * FROM users WHERE username = ? AND status = 'active'", [$username]);
        if ($user && password_verify($password, $user['password_hash'])) {
            return [
                'id' => $user['id'],
                'username' => $user['username'],
                'role' => $user['role'],
                'full_name' => $user['full_name'],
                'cashier_id_number' => $user['cashier_id_number'] ?? 'CSH-000'
            ];
        }
        return null;
    }

    public function getSetting(string $key, string $default = ''): string {
        $row = $this->db->fetch("SELECT setting_value FROM settings WHERE setting_key = ?", [$key]);
        return $row ? $row['setting_value'] : $default;
    }

    public function getAllSettings(): array {
        $rows = $this->db->fetchAll("SELECT setting_key, setting_value FROM settings");
        $result = [];
        foreach ($rows as $r) {
            $result[$r['setting_key']] = $r['setting_value'];
        }
        return $result;
    }

    public function saveSettings(array $settings): bool {
        $stmt = $this->db->getPdo()->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }
        return true;
    }

    public function formatSMSTemplate(string $templateKey, array $orderData): string {
        $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? "https" : "http";
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost:8000';
        $receiptToken = !empty($orderData['receipt_token']) ? $orderData['receipt_token'] : (!empty($orderData['tracking_number']) ? $orderData['tracking_number'] : ($orderData['id'] ?? ''));

        if (empty($receiptToken) && !empty($orderData['id'])) {
            $rRow = $this->db->fetch("SELECT receipt_token, tracking_number FROM orders WHERE id = ?", [$orderData['id']]);
            if ($rRow) {
                $receiptToken = !empty($rRow['receipt_token']) ? $rRow['receipt_token'] : $rRow['tracking_number'];
            }
        }

        $receiptUrl = "{$protocol}://{$host}/receipt.php?token={$receiptToken}";

        $defaultTemplates = [
            'sms_template_ready' => '✨ Great news {customer_name}! Your fresh, clean laundry (Receipt #{tracking_number}) is READY FOR PICKUP at {shop_name}! 🧺 Total: ₱{total_amount} | Balance: ₱{balance_amount}. Thank you for choosing us!',
            'sms_template_out_for_delivery' => '🚚 On its way {customer_name}! Your fresh laundry (Receipt #{tracking_number}) is OUT FOR DELIVERY with rider {driver_name}! 📍 Address: {delivery_address}. Balance Due: ₱{balance_amount}. Thank you for choosing {shop_name}!',
            'sms_template_received' => 'Thank you {customer_name}! We have received your laundry order #{tracking_number} at {shop_name}. Total: ₱{total_amount} | Est. pickup: {estimated_pickup}. We take great care of your clothes.',
            'sms_template_paid' => '🎉 Payment Received! Thank you {customer_name} for settling ₱{paid_amount} for Order #{tracking_number} at {shop_name}. Have a wonderful day!'
        ];

        $template = $this->getSetting($templateKey, $defaultTemplates[$templateKey] ?? '');
        if (empty($template)) {
            $template = $defaultTemplates[$templateKey] ?? '✨ Great news {customer_name}! Your fresh, clean laundry (Receipt #{tracking_number}) is READY FOR PICKUP at {shop_name}! 🧺 Total: ₱{total_amount} | Balance: ₱{balance_amount}. Thank you for choosing us!';
        }

        $customerName = $orderData['customer_name'] ?? ($orderData['name'] ?? 'Customer');
        $totalVal = (float)($orderData['total_amount'] ?? 0);
        $paidVal = (float)($orderData['paid_amount'] ?? 0);
        $balanceVal = max(0, $totalVal - $paidVal);
        $paymentStatus = ($balanceVal <= 0.001) ? 'Fully Paid' : (($paidVal > 0) ? 'Partially Paid' : 'Unpaid');

        $totalAmount = number_format($totalVal, 2);
        $paidAmount = number_format($paidVal, 2);
        $balanceAmount = number_format($balanceVal, 2);
        $pickupDate = !empty($orderData['estimated_pickup']) ? date('M d, Y', strtotime($orderData['estimated_pickup'])) : date('M d, Y', strtotime('+48 hours'));
        $shopName = $this->getSetting('shop_name', 'Aquamarine Laundry Hub');
        $driver = !empty($orderData['driver_name']) ? $orderData['driver_name'] : 'Assigned Courier';
        $deliveryAddr = !empty($orderData['delivery_address']) ? $orderData['delivery_address'] : 'Customer Address';

        $replacements = [
            '{customer_name}' => $customerName,
            '{tracking_number}' => $trackingNo,
            '{receipt_number}' => $trackingNo,
            '{total_amount}' => $totalAmount,
            '{paid_amount}' => $paidAmount,
            '{balance_amount}' => $balanceAmount,
            '{payment_status}' => $paymentStatus,
            '{pickup_date}' => $pickupDate,
            '{estimated_pickup}' => $pickupDate,
            '{shop_name}' => $shopName,
            '{driver_name}' => $driver,
            '{delivery_address}' => $deliveryAddr
        ];

        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }

    public function sendSMS(string $toPhone, string $messageText, ?int $customerId = null, ?int $orderId = null): array {
        $res = $this->sendTextBeeSMS($toPhone, $messageText);
        
        if (!$customerId || $customerId <= 0) {
            $cleanPhone = preg_replace('/[^0-9]/', '', $toPhone);
            $last10 = !empty($cleanPhone) ? substr($cleanPhone, -10) : '';
            if (!empty($last10)) {
                $c = $this->db->fetch("SELECT id FROM customers WHERE contact LIKE ?", ["%$last10"]);
                if ($c) $customerId = (int)$c['id'];
            }
        }

        if (!$orderId || $orderId <= 0) {
            if (preg_match('/AQ-\d{4}-\d{4}/i', $messageText, $matches)) {
                $o = $this->db->fetch("SELECT id FROM orders WHERE tracking_number = ?", [$matches[0]]);
                if ($o) $orderId = (int)$o['id'];
            }
        }

        $this->logMessage($customerId ?: 0, $orderId ?: null, 'sms', $toPhone, $messageText, $res['success'] ? 'sent' : 'failed', $res['message'] ?? '');
        return $res;
    }

    public function sendTextBeeSMS(string $toPhone, string $messageText): array {
        $apiKey = trim($this->getSetting('textbee_api_key', ''));
        $deviceId = trim($this->getSetting('textbee_device_id', ''));

        if (empty($apiKey) || empty($deviceId)) {
            return ['success' => false, 'message' => 'TextBee Free SMS Gateway credentials (API Key & Device ID) are incomplete in Admin Settings.'];
        }

        // Format Philippine phone number to E.164 (+639xxxxxxxxx)
        $cleanPhone = preg_replace('/[^0-9+]/', '', $toPhone);
        if (strpos($cleanPhone, '09') === 0) {
            $cleanPhone = '+63' . substr($cleanPhone, 1);
        } elseif (strpos($cleanPhone, '9') === 0 && strlen($cleanPhone) === 10) {
            $cleanPhone = '+63' . $cleanPhone;
        } elseif (strpos($cleanPhone, '+') !== 0) {
            $cleanPhone = '+' . $cleanPhone;
        }

        $url = "https://api.textbee.dev/api/v1/gateway/devices/$deviceId/send-sms";
        $payload = json_encode([
            'recipients' => [$cleanPhone],
            'message' => $messageText
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $response = null;
        $httpCode = 0;

        if (function_exists('curl_init')) {
            $ch = @curl_init();
            if ($ch) {
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "x-api-key: $apiKey",
                    "Content-Type: application/json",
                    "Accept: application/json"
                ]);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
                curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
                curl_setopt($ch, CURLOPT_TIMEOUT, 12);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
            }
        }

        // Pure PHP stream fallback if cURL is disabled or failed
        if (empty($response)) {
            $opts = [
                'http' => [
                    'method'  => 'POST',
                    'header'  => "x-api-key: $apiKey\r\n" .
                                 "Content-Type: application/json\r\n" .
                                 "Accept: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 10,
                    'ignore_errors' => true
                ],
                'ssl' => [
                    'verify_peer' => false,
                    'verify_peer_name' => false
                ]
            ];
            $context = stream_context_create($opts);
            $response = @file_get_contents($url, false, $context);
            if (isset($http_response_header) && is_array($http_response_header)) {
                foreach ($http_response_header as $header) {
                    if (preg_match('/HTTP\/\d\.\d\s+(\d+)/i', $header, $m)) {
                        $httpCode = (int)$m[1];
                    }
                }
            }
        }

        if (empty($response)) {
            $this->logAudit(1, 'SMS Delivery Timeout', 'TextBee', "TextBee dispatch timed out to $cleanPhone");
            return ['success' => false, 'message' => "TextBee request timed out. Please check your Android phone Wi-Fi connection."];
        }

        $json = json_decode($response, true);
        if ($httpCode >= 200 && $httpCode < 300) {
            $apiMsg = $json['data']['message'] ?? ($json['message'] ?? 'SMS sent');
            $this->logAudit(1, 'SMS Sent via TextBee', 'TextBee', "Sent free SMS to $cleanPhone: $messageText");
            return ['success' => true, 'message' => "Free SMS successfully dispatched via TextBee Android Gateway to $cleanPhone! ($apiMsg)"];
        } else {
            $errorMsg = $json['message'] ?? ($json['data']['message'] ?? ($json['error'] ?? ("TextBee Error (HTTP Code $httpCode)")));
            $this->logAudit(1, 'SMS Delivery Failed', 'TextBee', "Failed sending SMS to $cleanPhone: $errorMsg");
            return ['success' => false, 'message' => "TextBee API Error ($httpCode): $errorMsg"];
        }
    }

    public function sendDigitalReceiptEmail(string $toEmail, array $orderData): array {
        $toEmail = trim($toEmail);
        if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => "Invalid recipient email address: '$toEmail'"];
        }

        $emailEnabled = $this->getSetting('email_enabled', '1');
        $smtpUser = trim($this->getSetting('gmail_smtp_user', ''));
        $smtpPass = trim($this->getSetting('gmail_app_password', ''));
        $fromName = trim($this->getSetting('shop_name', $this->getSetting('email_from_name', 'AQUAMARINE LAUNDRY HUB')));
        $shopAddress = trim($this->getSetting('shop_address', 'San Francisco District Pagadian City'));
        $shopPhone = trim($this->getSetting('shop_phone', '09309829975'));

        if (empty($smtpUser) || empty($smtpPass)) {
            return [
                'success' => false,
                'message' => "Gmail SMTP is not configured yet! Please enter your Gmail address and 16-letter Google App Password in Admin Settings."
            ];
        }

        $trackingNo = $orderData['tracking_number'] ?? ($orderData['order_number'] ?? 'AQ-0000');
        $ord = null;
        if (!empty($orderData['id'])) {
            $ord = $this->db->fetch("
                SELECT o.*, c.id as cust_id, c.name as cust_name, c.contact as cust_phone, c.email as cust_email, u.full_name as cashier_name 
                FROM orders o 
                JOIN customers c ON o.customer_id = c.id 
                LEFT JOIN users u ON o.created_by = u.id 
                WHERE o.id = ?
            ", [$orderData['id']]);
        } elseif (!empty($trackingNo)) {
            $ord = $this->db->fetch("
                SELECT o.*, c.id as cust_id, c.name as cust_name, c.contact as cust_phone, c.email as cust_email, u.full_name as cashier_name 
                FROM orders o 
                JOIN customers c ON o.customer_id = c.id 
                LEFT JOIN users u ON o.created_by = u.id 
                WHERE o.tracking_number = ?
            ", [$trackingNo]);
        }

        if ($ord) {
            $trackingNo = $ord['tracking_number'];
            $custName = $ord['cust_name'] ?: ($orderData['customer_name'] ?? 'Valued Customer');
            $custPhone = $ord['cust_phone'] ?: ($orderData['phone'] ?? 'N/A');
            $cashierName = $ord['cashier_name'] ?: 'System Administrator';
            $orderStatus = !empty($orderData['order_status']) ? $orderData['order_status'] : ($ord['order_status'] ?? 'Completed');
            $subtotalVal = (float)$ord['subtotal'];
            $discountVal = (float)$ord['discount'];
            $totalVal = (float)$ord['total_amount'];
            $paidVal = (float)$ord['paid_amount'];
            $createdAt = $ord['created_at'];
            $estimatedPickup = $ord['estimated_pickup'];
            $custId = (int)$ord['cust_id'];
            $ordId = (int)$ord['id'];
        } else {
            $custName = $orderData['customer_name'] ?? ($orderData['name'] ?? 'Valued Customer');
            $custPhone = $orderData['phone'] ?? $orderData['contact'] ?? 'N/A';
            $cashierName = 'System Administrator';
            $orderStatus = $orderData['order_status'] ?? ($orderData['status'] ?? 'Completed');
            $subtotalVal = (float)($orderData['subtotal'] ?? $orderData['total_amount'] ?? 0);
            $discountVal = (float)($orderData['discount'] ?? 0);
            $totalVal = (float)($orderData['total_amount'] ?? 0);
            $paidVal = (float)($orderData['paid_amount'] ?? 0);
            $createdAt = date('Y-m-d H:i:s');
            $estimatedPickup = date('Y-m-d H:i:s', strtotime('+48 hours'));
            $custId = 0;
            $ordId = 0;
        }

        // Fetch payment details
        $pay = $ordId > 0 ? $this->db->fetch("SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC LIMIT 1", [$ordId]) : null;
        $tenderedVal = $pay ? (float)($pay['tendered_amount'] ?? $paidVal) : $paidVal;
        if ($tenderedVal < $paidVal) $tenderedVal = $paidVal;
        $changeVal = max(0, $tenderedVal - $paidVal);

        $paymentMethod = strtoupper($pay && !empty($pay['payment_method']) ? $pay['payment_method'] : (!empty($ord['payment_method']) ? $ord['payment_method'] : 'CASH'));
        $referenceNo = $pay && !empty($pay['reference_no']) ? $pay['reference_no'] : ($ord['reference_no'] ?? '');

        $totalAmt = number_format($totalVal, 2);
        $paidAmt = number_format($paidVal, 2);
        $tenderedAmt = number_format($tenderedVal, 2);
        $changeAmt = number_format($changeVal, 2);

        $dateTimeStr = date('M d, Y • h:i A', strtotime($createdAt));
        $isFullyPaid = ($paidVal >= $totalVal || ($totalVal - $paidVal) <= 0.001);
        $paymentStatusText = $isFullyPaid ? 'FULLY PAID' : ($paidVal > 0 ? 'PARTIALLY PAID' : 'UNPAID');
        $paymentStatusBg = $isFullyPaid ? '#dcfce7' : ($paidVal > 0 ? '#fef3c7' : '#fee2e2');
        $paymentStatusBorder = $isFullyPaid ? '#86efac' : ($paidVal > 0 ? '#fde68a' : '#fca5a5');
        $paymentStatusColor = $isFullyPaid ? '#15803d' : ($paidVal > 0 ? '#b45309' : '#b91c1c');
        $paymentStatusIcon = $isFullyPaid ? '✓' : ($paidVal > 0 ? '⚠️' : '⏳');
        $balanceDueVal = max(0, $totalVal - $paidVal);
        $balanceDueAmt = number_format($balanceDueVal, 2);

        // Fetch detailed order items if available
        $itemsHtml = '';
        if ($ordId > 0) {
            $orderItems = $this->db->fetchAll("SELECT oi.*, s.service_name FROM order_items oi LEFT JOIN services s ON oi.service_id = s.id WHERE oi.order_id = ?", [$ordId]);
            foreach ($orderItems as $item) {
                $displayName = !empty($item['item_details']) ? $item['item_details'] : ($item['service_name'] ?: 'Laundry Service');
                $displayName = preg_replace('/^Add-on:\s*/i', '', $displayName);
                $weightKg = (float)($item['weight_kg'] ?? 0);
                $weightStr = $weightKg > 0 ? number_format($weightKg, 1) . " kg" : "";
                $subtotal = number_format((float)$item['subtotal'], 2);

                $itemsHtml .= "
                    <tr style='border-bottom: 1px solid #f1f5f9;'>
                        <td style='padding: 6px 0;'>
                            <div style='font-weight: 700; color: #0f172a; font-size: 13px;'>" . htmlspecialchars($displayName) . "</div>
                            " . ($weightStr ? "<div style='font-size: 11px; color: #64748b; margin-top: 2px;'>{$weightStr}</div>" : "") . "
                        </td>
                        <td align='right' style='padding: 6px 0; font-weight: 700; color: #0f172a; font-size: 13px; vertical-align: top;'>₱{$subtotal}</td>
                    </tr>
                ";
            }
        }

        if (empty($itemsHtml)) {
            $itemsHtml = "
                <tr style='border-bottom: 1px solid #f1f5f9;'>
                    <td style='padding: 6px 0;'>
                        <div style='font-weight: 700; color: #0f172a; font-size: 13px;'>Wash & Fold (Standard)</div>
                    </td>
                    <td align='right' style='padding: 6px 0; font-weight: 700; color: #0f172a; font-size: 13px; vertical-align: top;'>₱{$totalAmt}</td>
                </tr>
            ";
        }

        // Loyalty calculations
        $targetStamps = max(1, (int)$this->getSetting('loyalty_stamps_target', '11'));
        $maxFreeKg = max(0.1, (float)$this->getSetting('loyalty_max_kg', '8.0'));
        $custOrdersCount = $custId > 0 ? (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM orders WHERE customer_id = ?", [$custId])['cnt'] ?? 1) : 1;
        $remainder = $custOrdersCount % $targetStamps;
        $currentStamps = ($custOrdersCount > 0 && $remainder === 0) ? $targetStamps : $remainder;
        $neededStamps = max(0, $targetStamps - $currentStamps);

        if ($currentStamps === $targetStamps) {
            $loyaltyMsg = "🎉 <strong>Milestone Reached!</strong> Loyalty Reward Ready on your next visit (Up to {$maxFreeKg} kg Free)!";
        } else {
            $loyaltyMsg = "<strong>{$neededStamps} more order" . ($neededStamps > 1 ? 's' : '') . "</strong> for Loyalty Reward (Up to {$maxFreeKg} kg Free)!";
        }

        $subject = "Aquamarine Laundry Receipt #$trackingNo";

        $htmlContent = "
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset='utf-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <title>Laundry Receipt - Aquamarine</title>
        </head>
        <body style='margin: 0; padding: 20px 10px; background-color: #e5e7eb; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; color: #1f2937;'>
            <table role='presentation' width='100%' border='0' cellspacing='0' cellpadding='0'>
                <tr>
                    <td align='center'>
                        
                        <!-- THERMAL RECEIPT CARD CONTAINER -->
                        <table role='presentation' width='100%' border='0' cellspacing='0' cellpadding='0' style='max-width: 380px; background-color: #ffffff; border-radius: 16px; border: 1px solid #d1d5db; padding: 24px; box-shadow: 0 10px 25px rgba(0,0,0,0.08);'>
                            
                            <!-- SHOP HEADER -->
                            <tr>
                                <td align='center' style='text-align: center; padding-bottom: 12px;'>
                                    <div style='font-size: 28px; margin-bottom: 2px;'>🧺</div>
                                    <h2 style='margin: 0; font-size: 20px; font-weight: 900; color: #1d4ed8; letter-spacing: -0.5px; text-transform: uppercase;'>" . htmlspecialchars($fromName) . "</h2>
                                    <p style='margin: 4px 0 0 0; font-size: 11px; color: #6b7280;'>" . htmlspecialchars($shopAddress) . "</p>
                                    <p style='margin: 2px 0 0 0; font-size: 11px; color: #6b7280; font-weight: 600;'>Contact: " . htmlspecialchars($shopPhone) . "</p>
                                    
                                    <!-- DASHED TITLE BADGE -->
                                    <div style='margin-top: 10px; padding: 6px 0; border-top: 1px dashed #9ca3af; border-bottom: 1px dashed #9ca3af;'>
                                        <span style='font-size: 11px; font-weight: 800; color: #374151; letter-spacing: 1.5px; text-transform: uppercase;'>LAUNDRY RECEIPT</span>
                                    </div>
                                </td>
                            </tr>

                            <!-- RECEIPT METADATA -->
                            <tr>
                                <td style='padding-bottom: 12px; border-bottom: 1px solid #e5e7eb;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 11px; color: #6b7280;'>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Order / Receipt #:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; color: #1d4ed8; font-size: 12px; font-family: monospace;'>" . htmlspecialchars($trackingNo) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Date & Time:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 500; color: #374151;'>{$dateTimeStr}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Customer:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; color: #111827; font-size: 12px;'>" . htmlspecialchars($custName) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Phone #:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 600; color: #374151; font-family: monospace;'>" . htmlspecialchars($custPhone) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Attended By:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 500; color: #374151;'>" . htmlspecialchars($cashierName) . "</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Order Status:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; color: #1d4ed8; font-size: 12px; text-transform: uppercase;'>" . htmlspecialchars($orderStatus) . "</td>
                                        </tr>
                                    </table>
                                </td>
                            </tr>

                            <!-- ORDERED ITEMS LIST -->
                            <tr>
                                <td style='padding: 12px 0; border-bottom: 1px solid #e5e7eb;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 11px;'>
                                        <thead>
                                            <tr style='font-size: 10px; font-weight: 800; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;'>
                                                <th align='left' style='padding-bottom: 6px;'>ITEM / SERVICE</th>
                                                <th align='right' style='padding-bottom: 6px;'>AMOUNT</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {$itemsHtml}
                                        </tbody>
                                    </table>
                                </td>
                            </tr>

                            <!-- PAYMENT & TOTALS BREAKDOWN -->
                            <tr>
                                <td style='padding-top: 12px;'>
                                    <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 12px; color: #111827;'>
                                        <tr>
                                            <td style='padding: 4px 0; font-weight: 900; font-size: 13px; color: #111827;'>TOTAL AMOUNT:</td>
                                            <td align='right' style='padding: 4px 0; font-weight: 900; font-size: 16px; color: #1d4ed8; font-family: monospace;'>₱{$totalAmt}</td>
                                        </tr>
                                        <tr>
                                            <td style='padding: 2px 0; color: #4b5563; font-size: 11px; font-weight: 600;'>Payment Method:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; font-size: 11px; text-transform: uppercase; color: #111827;'>" . htmlspecialchars($paymentMethod) . "</td>
                                        </tr>
                                    </table>

                                    <!-- REFERENCE / TRANS # PILL -->
                                    " . ((stripos($paymentMethod, 'GCASH') !== false && !empty($referenceNo)) ? "
                                    <div style='background-color: #eff6ff; border: 1px solid #bfdbfe; border-radius: 8px; padding: 8px 12px; margin: 8px 0;'>
                                        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 11px;'>
                                            <tr>
                                                <td style='font-weight: 700; color: #1d4ed8;'>Reference / Trans #:</td>
                                                <td align='right' style='font-weight: 800; color: #1d4ed8; font-family: monospace; font-size: 12px;'>" . htmlspecialchars($referenceNo) . "</td>
                                            </tr>
                                        </table>
                                    </div>" : "") . "

                                    <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 11px; margin-top: 4px;'>
                                        <tr>
                                            <td style='padding: 2px 0; color: #4b5563; font-weight: 700;'>Amount Paid:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; color: #16a34a; font-size: 12px; font-family: monospace;'>₱{$paidAmt}</td>
                                        </tr>
                                        " . (!$isFullyPaid ? "
                                        <tr>
                                            <td style='padding: 2px 0; color: #dc2626; font-weight: 700;'>Balance Due:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 800; color: #dc2626; font-size: 12px; font-family: monospace;'>₱{$balanceDueAmt}</td>
                                        </tr>" : "") . "
                                        <tr>
                                            <td style='padding: 2px 0; color: #6b7280;'>Cash Tendered:</td>
                                            <td align='right' style='padding: 2px 0; font-weight: 500; color: #6b7280; font-family: monospace;'>₱{$tenderedAmt}</td>
                                        </tr>
                                    </table>

                                    <!-- CHANGE GIVEN PILL -->
                                    <div style='background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 8px; padding: 6px 12px; margin: 6px 0;'>
                                        <table width='100%' border='0' cellspacing='0' cellpadding='0' style='font-size: 11px;'>
                                            <tr>
                                                <td style='font-weight: 800; color: #047857;'>CHANGE GIVEN:</td>
                                                <td align='right' style='font-weight: 800; color: #047857; font-size: 12px; font-family: monospace;'>₱{$changeAmt}</td>
                                            </tr>
                                        </table>
                                    </div>

                                    <!-- PAYMENT BADGE -->
                                    <div style='background-color: {$paymentStatusBg}; border: 1px solid {$paymentStatusBorder}; border-radius: 8px; padding: 8px; margin: 8px 0; text-align: center;'>
                                        <span style='font-size: 11px; font-weight: 900; color: {$paymentStatusColor}; text-transform: uppercase; letter-spacing: 1px;'>
                                            {$paymentStatusIcon} {$paymentStatusText}
                                        </span>
                                    </div>
                                </td>
                            </tr>

                            <!-- LOYALTY STAMP CARD PILL -->
                            <tr>
                                <td style='padding-top: 8px;'>
                                    <div style='background-color: #ecfdf5; border: 1px solid #a7f3d0; border-radius: 12px; padding: 10px 14px; text-align: center;'>
                                        <div style='font-size: 11px; font-weight: 800; color: #047857; margin-bottom: 2px;'>
                                            ⭐ {$targetStamps}-ORDER LOYALTY STAMP CARD ⭐
                                        </div>
                                        <div style='font-size: 11px; font-weight: 700; color: #065f46; margin-bottom: 2px;'>
                                            Loyalty Progress: {$currentStamps} / {$targetStamps} Stamps ({$custOrdersCount} Total Orders)
                                        </div>
                                        <div style='font-size: 10px; font-weight: 700; color: #047857;'>
                                            {$loyaltyMsg}
                                        </div>
                                    </div>
                                </td>
                            </tr>

                        </table>
                    </td>
                </tr>
            </table>
        </body>
        </html>
        ";

        $res = $this->sendGmailSmtp($toEmail, $subject, $htmlContent, $smtpUser, $smtpPass, $fromName);

        // Auto log email dispatch
        $cust = $this->db->fetch("SELECT id FROM customers WHERE email = ?", [$toEmail]);
        $ord = !empty($trackingNo) ? $this->db->fetch("SELECT id FROM orders WHERE tracking_number = ?", [$trackingNo]) : null;

        $this->logMessage($cust ? (int)$cust['id'] : 0, $ord ? (int)$ord['id'] : null, 'email', $toEmail, "Digital Receipt for Order #$trackingNo ($custName)", $res['success'] ? 'sent' : 'failed', $res['message'] ?? '');

        return $res;
    }

    private function sendGmailSmtp(string $to, string $subject, string $htmlBody, string $smtpUser, string $smtpPass, string $fromName): array {
        $cleanPass = str_replace(' ', '', $smtpPass);
        $host = 'smtp.gmail.com';
        $port = 587;

        $socket = @fsockopen($host, $port, $errno, $errstr, 10);
        if (!$socket) {
            $socket = @fsockopen("ssl://$host", 465, $errno, $errstr, 10);
            if (!$socket) {
                return ['success' => false, 'message' => "Could not connect to Gmail SMTP server ($errstr). Please check internet connection."];
            }
        } else {
            $read = fgets($socket, 512);
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            while ($line = fgets($socket, 512)) {
                if (substr($line, 3, 1) == " ") break;
            }

            fputs($socket, "STARTTLS\r\n");
            $read = fgets($socket, 512);
            if (substr($read, 0, 3) != '220') {
                fclose($socket);
                return ['success' => false, 'message' => "TLS negotiation failed with Gmail SMTP: $read"];
            }

            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            fputs($socket, "EHLO " . gethostname() . "\r\n");
            while ($line = fgets($socket, 512)) {
                if (substr($line, 3, 1) == " ") break;
            }
        }

        // AUTH LOGIN
        fputs($socket, "AUTH LOGIN\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) != '334') {
            fclose($socket);
            return ['success' => false, 'message' => "Gmail AUTH LOGIN failed: $read"];
        }

        fputs($socket, base64_encode($smtpUser) . "\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) != '334') {
            fclose($socket);
            return ['success' => false, 'message' => "Gmail Username authentication failed for '$smtpUser'."];
        }

        fputs($socket, base64_encode($cleanPass) . "\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) != '235') {
            fclose($socket);
            return ['success' => false, 'message' => "Gmail App Password authentication failed! Please verify your 16-letter App Password in Admin Settings."];
        }

        // MAIL FROM & RCPT TO
        fputs($socket, "MAIL FROM: <$smtpUser>\r\n");
        fgets($socket, 512);

        fputs($socket, "RCPT TO: <$to>\r\n");
        $read = fgets($socket, 512);
        if (substr($read, 0, 3) != '250') {
            fclose($socket);
            return ['success' => false, 'message' => "Recipient address '$to' rejected by Gmail: $read"];
        }

        // DATA
        fputs($socket, "DATA\r\n");
        fgets($socket, 512);

        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $fromName <$smtpUser>\r\n";
        $headers .= "To: <$to>\r\n";
        $headers .= "Subject: $subject\r\n";
        $headers .= "Date: " . date('r') . "\r\n";

        fputs($socket, $headers . "\r\n" . $htmlBody . "\r\n.\r\n");
        $read = fgets($socket, 512);

        fputs($socket, "QUIT\r\n");
        fclose($socket);

        if (substr($read, 0, 3) == '250') {
            $this->logAudit(1, 'Gmail Receipt Sent', 'Gmail SMTP', "Emailed digital receipt to $to via Gmail SMTP");
            return ['success' => true, 'message' => "Digital receipt successfully delivered to $to via Gmail SMTP!"];
        } else {
            return ['success' => false, 'message' => "Gmail delivery error: $read"];
        }
    }

    public function changeAdminPassword(int $userId, string $currentPassword, string $newPassword): bool {
        $user = $this->db->fetch("SELECT * FROM users WHERE id = ?", [$userId]);
        if (!$user || !password_verify($currentPassword, $user['password_hash'])) {
            return false;
        }

        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $this->db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$newHash, $userId]);
        $this->db->execute("UPDATE admins SET password = ? WHERE username = ?", [$newHash, $user['username']]);
        
        $this->logAudit($userId, 'Password Changed', 'Security', "Admin password updated for user: {$user['username']}");
        return true;
    }

    public function clearAuditLogs(): bool {
        return $this->db->execute("DELETE FROM audit_trail");
    }

    public function getCashierShiftStats(int $cashierId = 0): array {
        $today = date('Y-m-d');
        
        $salesSql = "SELECT SUM(paid_amount) as total FROM orders WHERE DATE(created_at) = ?";
        $ordersSql = "SELECT COUNT(*) as count FROM orders WHERE DATE(created_at) = ?";
        $pendingSql = "SELECT COUNT(*) as count FROM orders WHERE order_status = 'Pending'";
        $readySql = "SELECT COUNT(*) as count FROM orders WHERE order_status IN ('Ready for Pickup', 'Out for Delivery')";
        $paramsToday = [$today];
        $paramsPending = [];
        $paramsReady = [];

        if ($cashierId > 0) {
            $salesSql .= " AND created_by = ?";
            $ordersSql .= " AND created_by = ?";
            $pendingSql .= " AND created_by = ?";
            $readySql .= " AND created_by = ?";
            $paramsToday[] = $cashierId;
            $paramsPending[] = $cashierId;
            $paramsReady[] = $cashierId;
        }

        $salesRow = $this->db->fetch($salesSql, $paramsToday);
        $todaySales = (float)($salesRow['total'] ?? 0);

        $ordersRow = $this->db->fetch($ordersSql, $paramsToday);
        $todayOrders = (int)($ordersRow['count'] ?? 0);

        $pendingRow = $this->db->fetch($pendingSql, $paramsPending);
        $pendingCount = (int)($pendingRow['count'] ?? 0);

        $readyRow = $this->db->fetch($readySql, $paramsReady);
        $readyCount = (int)($readyRow['count'] ?? 0);

        return [
            'today_sales' => $todaySales,
            'today_orders' => $todayOrders,
            'pending_count' => $pendingCount,
            'ready_count' => $readyCount
        ];
    }

    public function getFilteredSalesReport(string $fromDate = '', string $toDate = '', string $paymentMethod = 'all', int $cashierId = 0, string $search = ''): array {
        $sql = "
            SELECT 
                o.id,
                o.tracking_number,
                o.total_weight,
                o.subtotal,
                o.discount,
                o.total_amount,
                o.paid_amount,
                o.payment_status,
                o.order_status,
                o.created_at,
                c.name as customer_name,
                c.contact as customer_contact,
                u.full_name as cashier_name,
                u.cashier_id_number,
                (SELECT p.payment_method FROM payments p WHERE p.order_id = o.id ORDER BY p.id DESC LIMIT 1) as payment_method
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            WHERE 1=1
        ";
        $params = [];

        if (!empty($fromDate)) {
            $sql .= " AND DATE(o.created_at) >= ?";
            $params[] = $fromDate;
        }

        if (!empty($toDate)) {
            $sql .= " AND DATE(o.created_at) <= ?";
            $params[] = $toDate;
        }

        if ($cashierId > 0) {
            $sql .= " AND (o.created_by = ? OR o.id IN (SELECT order_id FROM payments WHERE received_by = ?))";
            $params[] = $cashierId;
            $params[] = $cashierId;
        }

        if (!empty($search)) {
            $sql .= " AND (o.tracking_number LIKE ? OR c.name LIKE ? OR c.contact LIKE ? OR u.full_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        $sql .= " ORDER BY o.id DESC";
        $orders = $this->db->fetchAll($sql, $params);

        if (!empty($paymentMethod) && $paymentMethod !== 'all') {
            $orders = array_filter($orders, function($o) use ($paymentMethod) {
                $pm = $o['payment_method'] ?: 'Cash';
                return strtolower($pm) === strtolower($paymentMethod);
            });
            $orders = array_values($orders);
        }

        $grossSales = 0.0;
        $totalCollected = 0.0;
        $totalUnpaid = 0.0;
        $orderCount = count($orders);

        foreach ($orders as $o) {
            $grossSales += (float)$o['total_amount'];
            $totalCollected += (float)$o['paid_amount'];
            $balance = (float)$o['total_amount'] - (float)$o['paid_amount'];
            if ($balance > 0) {
                $totalUnpaid += $balance;
            }
        }

        $aov = $orderCount > 0 ? ($grossSales / $orderCount) : 0.0;

        return [
            'orders' => $orders,
            'gross_sales' => $grossSales,
            'total_collected' => $totalCollected,
            'total_unpaid' => $totalUnpaid,
            'order_count' => $orderCount,
            'average_order_value' => $aov
        ];
    }

    public function getDailySalesTrend(int $days = 7): array {
        $trend = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $date = date('Y-m-d', strtotime("-$i days"));
            $dayLabel = date('D', strtotime("-$i days"));
            $row = $this->db->fetch("SELECT SUM(paid_amount) as total FROM orders WHERE DATE(created_at) = ?", [$date]);
            $amount = (float)($row['total'] ?? 0);
            $trend[] = [
                'date' => $date,
                'day' => $dayLabel,
                'amount' => $amount
            ];
        }
        return $trend;
    }

    public function getTopCustomers(int $limit = 5): array {
        return $this->db->fetchAll("
            SELECT 
                c.id,
                c.customer_code,
                c.name,
                c.contact,
                COUNT(o.id) as total_orders,
                SUM(o.paid_amount) as total_spend
            FROM customers c
            JOIN orders o ON o.customer_id = c.id
            GROUP BY c.id
            ORDER BY total_spend DESC, total_orders DESC
            LIMIT ?
        ", [$limit]);
    }

    public function getOverdueOrders(int $daysThreshold = 7): array {
        return $this->db->fetchAll("
            SELECT o.*, c.name as customer_name, c.contact as customer_contact
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            WHERE o.order_status = 'Ready for Pickup'
            AND DATE(o.created_at) <= DATE('now', '-' || ? || ' days')
            ORDER BY o.created_at ASC
        ", [$daysThreshold]);
    }

    public function getOrderStats(string $dateRange = 'all'): array {
        $where = " WHERE 1=1";
        $params = [];

        if ($dateRange === 'today') {
            $where .= " AND DATE(created_at) = DATE('now')";
        } elseif ($dateRange === '7days') {
            $where .= " AND DATE(created_at) >= DATE('now', '-7 days')";
        } elseif ($dateRange === '30days') {
            $where .= " AND DATE(created_at) >= DATE('now', '-30 days')";
        }

        $total = (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM orders $where", $params)['cnt'] ?? 0);
        $pending = (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM orders $where AND order_status = 'Pending'", $params)['cnt'] ?? 0);
        $processing = (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM orders $where AND order_status IN ('Washing', 'Drying', 'Ironing')", $params)['cnt'] ?? 0);
        $completed = (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM orders $where AND order_status IN ('Ready for Pickup', 'Claimed', 'Completed')", $params)['cnt'] ?? 0);
        $revenue = (float)($this->db->fetch("SELECT SUM(paid_amount) as total FROM orders $where", $params)['total'] ?? 0);

        return [
            'total' => $total,
            'pending' => $pending,
            'processing' => $processing,
            'completed' => $completed,
            'revenue' => $revenue
        ];
    }

    public function getTopSellingServices(): array {
        return $this->db->fetchAll("
            SELECT 
                s.service_name,
                s.category,
                COUNT(oi.id) as total_orders,
                SUM(oi.subtotal) as total_revenue
            FROM order_items oi
            JOIN services s ON oi.service_id = s.id
            GROUP BY s.id
            ORDER BY total_orders DESC, total_revenue DESC
            LIMIT 3
        ");
    }

    public function getSalesCount(): int {
        return (int)($this->db->fetch("SELECT COUNT(*) as cnt FROM payments")['cnt'] ?? 0);
    }

    public function getSalesByCashier(string $fromDate, string $toDate): array {
        return $this->db->fetchAll("
            SELECT 
                u.cashier_id_number,
                u.full_name,
                COUNT(o.id) as total_orders,
                SUM(CASE WHEN o.order_status IN ('Ready for Pickup', 'Claimed', 'Completed') THEN 1 ELSE 0 END) as completed_orders,
                COALESCE(SUM(o.paid_amount), 0) as total_revenue
            FROM users u
            LEFT JOIN orders o ON o.created_by = u.id AND DATE(o.created_at) BETWEEN ? AND ?
            WHERE u.role IN ('cashier', 'staff', 'admin')
            GROUP BY u.id
            HAVING total_orders > 0 OR u.role = 'cashier'
        ", [$fromDate, $toDate]);
    }

    public function getAllOrdersWithCashier(): array {
        return $this->db->fetchAll("
            SELECT 
                o.id,
                o.tracking_number as order_number,
                c.name,
                c.contact as phone,
                (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as items_count,
                o.total_amount,
                o.paid_amount,
                LOWER(o.order_status) as status,
                o.order_status,
                o.created_at,
                u.full_name as cashier_name
            FROM orders o
            JOIN customers c ON o.customer_id = c.id
            LEFT JOIN users u ON o.created_by = u.id
            ORDER BY o.id DESC
        ");
    }

    public function getAddons(): array {
        return $this->db->fetchAll("SELECT * FROM addons WHERE status = 'active' ORDER BY id ASC");
    }

    public function getAllAddons(): array {
        return $this->db->fetchAll("SELECT * FROM addons ORDER BY id ASC");
    }

    public function addAddon(string $name, float $price, string $icon = '🌸'): int {
        $id = $this->db->insert(
            "INSERT INTO addons (addon_name, price, icon, status) VALUES (?, ?, ?, 'active')",
            [$name, $price, $icon]
        );
        $this->logAudit(1, 'Add-on Created', 'OrderService', "Created add-on '$name' (₱$price)");
        return $id;
    }

    public function updateAddon(int $id, string $name, float $price, string $icon = '🌸'): bool {
        $this->db->execute(
            "UPDATE addons SET addon_name = ?, price = ?, icon = ? WHERE id = ?",
            [$name, $price, $icon, $id]
        );
        $this->logAudit(1, 'Add-on Updated', 'OrderService', "Updated add-on #$id: '$name' (₱$price)");
        return true;
    }

    public function deleteAddon(int $id): bool {
        $addon = $this->db->fetch("SELECT addon_name FROM addons WHERE id = ?", [$id]);
        $this->db->execute("DELETE FROM addons WHERE id = ?", [$id]);
        if ($addon) {
            $this->logAudit(1, 'Add-on Deleted', 'OrderService', "Deleted add-on '{$addon['addon_name']}' (#$id)");
        }
        return true;
    }

    public function getAddonsCatalog(): array {
        $addons = $this->getAddons();
        $catalog = [];
        foreach ($addons as $a) {
            $catalog[$a['id']] = [
                'id' => $a['id'],
                'name' => $a['addon_name'],
                'price' => (float)$a['price'],
                'icon' => $a['icon'] ?: '🌸'
            ];
        }
        return $catalog;
    }

    public function createOrder(int $customerId, int $serviceId, float $weightKg, float $discount = 0.0, float $initialPayment = 0.0, string $paymentMethod = 'Cash', string $notes = '', int $createdBy = 1, bool $isExpress = false, float $tenderedAmount = 0.0, array $selectedAddons = [], string $referenceNo = '', string $serviceType = 'walk_in', float $deliveryFee = 0.0, string $deliveryAddress = '', string $driverName = '', string $estimatedPickup = ''): array {
        $service = $this->db->fetch("SELECT * FROM services WHERE id = ?", [$serviceId]);
        if (!$service) {
            throw new Exception("Selected service not found.");
        }

        $minKg = max(0.1, (float)$service['min_kg']);
        $pricePerLoad = (float)$service['price_per_kg'];
        $loadsCount = max(1, (int)ceil($weightKg / $minKg));
        $baseSubtotal = round($loadsCount * $pricePerLoad, 2);

        // Calculate Add-ons cost dynamically from database with quantity support
        $addonsTotal = 0.0;
        $validSelectedAddons = [];
        if (!empty($selectedAddons)) {
            $allAddons = $this->getAddonsCatalog();
            foreach ($selectedAddons as $addonItem) {
                if (is_array($addonItem)) {
                    $addonId = (int)($addonItem['id'] ?? 0);
                    $qty = max(1, (int)($addonItem['qty'] ?? 1));
                } else {
                    $addonId = (int)$addonItem;
                    $qty = 1;
                }

                if (isset($allAddons[$addonId])) {
                    $addon = $allAddons[$addonId];
                    $price = (float)$addon['price'];
                    $itemSubtotal = round($price * $qty, 2);
                    $addonsTotal += $itemSubtotal;
                    $validSelectedAddons[] = [
                        'id' => $addon['id'],
                        'name' => $addon['name'],
                        'price' => $price,
                        'qty' => $qty,
                        'subtotal' => $itemSubtotal
                    ];
                }
            }
        }

        if (!empty($validSelectedAddons)) {
            $addonNames = array_map(function($a) {
                return $a['name'] . ($a['qty'] > 1 ? " (x{$a['qty']})" : "");
            }, $validSelectedAddons);
            $notes = "[ADD-ONS: " . implode(', ', $addonNames) . "] " . $notes;
        }

        if ($serviceType === 'delivery' && $deliveryFee > 0) {
            $notes = "[🚚 DOORSTEP DELIVERY - Fee: ₱" . number_format($deliveryFee, 2) . "] " . $notes;
        }

        $subtotal = $baseSubtotal + $addonsTotal + $deliveryFee;
        $totalAmount = max(0.0, $subtotal - $discount);

        $paymentStatus = 'Pending';
        if ($initialPayment >= $totalAmount && $totalAmount > 0) {
            $paymentStatus = 'Fully Paid';
        } elseif ($initialPayment > 0) {
            $paymentStatus = 'Partially Paid';
        }

        $pickupHours = (int)$this->getSetting('default_pickup_hours', '48');
        $trackingNo = 'AQ-' . date('Y') . '-' . str_pad((string)rand(1, 9999), 4, '0', STR_PAD_LEFT);
        $receiptToken = strtolower(bin2hex(random_bytes(8)));
        if (!empty($estimatedPickup)) {
            $estimatedPickupStr = date('Y-m-d H:i:s', strtotime($estimatedPickup));
        } else {
            $estimatedPickupStr = date('Y-m-d H:i:s', strtotime("+$pickupHours hours"));
        }

        $refNoToSave = trim($referenceNo) ?: ('OR-' . rand(1000, 9999));

        $orderId = $this->db->insert(
            "INSERT INTO orders (tracking_number, receipt_token, customer_id, total_weight, subtotal, discount, total_amount, paid_amount, payment_status, order_status, estimated_pickup, notes, created_by, payment_method, reference_no, service_type, delivery_fee, delivery_address, driver_name)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [$trackingNo, $receiptToken, $customerId, $weightKg, $subtotal, $discount, $totalAmount, $initialPayment, $paymentStatus, $estimatedPickupStr, trim($notes), $createdBy, $paymentMethod, $refNoToSave, $serviceType, $deliveryFee, trim($deliveryAddress), trim($driverName)]
        );

        // Primary Laundry Service Item (Per Load)
        $itemDetailStr = $service['service_name'] . " (" . $loadsCount . ($loadsCount === 1 ? " Load" : " Loads") . " / {$weightKg}kg)";
        $this->db->execute(
            "INSERT INTO order_items (order_id, service_id, item_details, weight_kg, qty, unit_price, subtotal)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$orderId, $serviceId, $itemDetailStr, $weightKg, $loadsCount, $pricePerLoad, $baseSubtotal]
        );

        // Insert selected add-ons into order_items with proper quantity & details
        foreach ($validSelectedAddons as $addon) {
            $this->db->execute(
                "INSERT INTO order_items (order_id, service_id, item_details, weight_kg, qty, unit_price, subtotal)
                 VALUES (?, ?, ?, 0.0, ?, ?, ?)",
                [$orderId, $serviceId, "Add-on: " . $addon['name'], $addon['qty'], $addon['price'], $addon['subtotal']]
            );
        }

        if ($initialPayment > 0) {
            $actualTendered = max($initialPayment, $tenderedAmount);
            $this->db->execute(
                "INSERT INTO payments (order_id, amount, tendered_amount, payment_method, reference_no, received_by) VALUES (?, ?, ?, ?, ?, ?)",
                [$orderId, $initialPayment, $actualTendered, $paymentMethod, $refNoToSave, $createdBy]
            );
        }

        // Auto-send Order Received SMS if customer contact is available
        $cust = $this->db->fetch("SELECT name, contact FROM customers WHERE id = ?", [$customerId]);
        $smsResult = null;
        if ($cust && !empty($cust['contact'])) {
            $orderDataForSms = [
                'id' => $orderId,
                'tracking_number' => $trackingNo,
                'customer_name' => $cust['name'],
                'total_amount' => $totalAmount,
                'paid_amount' => $initialPayment,
                'estimated_pickup' => $estimatedPickupStr
            ];
            $smsMsg = $this->formatSMSTemplate('sms_template_received', $orderDataForSms);
            $smsResult = $this->sendSMS($cust['contact'], $smsMsg, $customerId, $orderId);
        }

        $this->logAudit($createdBy, 'Order Created', 'OrderService', "Created order $trackingNo for customer ID #$customerId with Total ₱$totalAmount");

        return [
            'order_id' => $orderId,
            'tracking_number' => $trackingNo,
            'receipt_token' => $receiptToken,
            'total_amount' => $totalAmount,
            'paid_amount' => $initialPayment,
            'sms_result' => $smsResult
        ];
    }

    public function updateOrderStatus(int $orderId, string $newStatus, int $updatedBy = 1): bool {
        $validStatuses = ['Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed', 'Claimed', 'Completed', 'Claimed / Completed'];
        if (!in_array($newStatus, $validStatuses)) {
            return false;
        }

        $completedStatuses = ['Completed', 'Claimed', 'Claimed / Completed', 'Delivered / Completed'];
        if (in_array($newStatus, $completedStatuses)) {
            $ord = $this->db->fetch("SELECT total_amount, paid_amount FROM orders WHERE id = ?", [$orderId]);
            if ($ord) {
                $bal = (float)($ord['total_amount'] ?? 0) - (float)($ord['paid_amount'] ?? 0);
                if ($bal > 0.001) {
                    return false;
                }
            }
        }

        $dbStatus = $newStatus;
        $res = $this->db->execute("UPDATE orders SET order_status = ? WHERE id = ?", [$dbStatus, $orderId]);

        if ($res) {
            $this->logAudit($updatedBy, 'Status Updated', 'OrderService', "Order #$orderId status changed to $newStatus");
        }
        return $res;
    }

    public function recordPayment(int $orderId, float $amount, string $paymentMethod = 'Cash', string $refNo = '', int $receivedBy = 1, float $tenderedAmount = 0.0): bool {
        $order = $this->db->fetch("SELECT * FROM orders WHERE id = ?", [$orderId]);
        if (!$order) return false;

        $newPaid = (float)$order['paid_amount'] + $amount;
        $newStatus = 'Partially Paid';
        if ($newPaid >= (float)$order['total_amount']) {
            $newStatus = 'Fully Paid';
        }

        $actualTendered = max($amount, $tenderedAmount);
        $finalRefNo = trim($refNo) ?: ($order['reference_no'] ?? ('OR-' . rand(1000, 9999)));

        $this->db->execute("UPDATE orders SET paid_amount = ?, payment_status = ?, payment_method = ?, reference_no = ? WHERE id = ?", [$newPaid, $newStatus, $paymentMethod, $finalRefNo, $orderId]);
        $this->db->execute(
            "INSERT INTO payments (order_id, amount, tendered_amount, payment_method, reference_no, received_by) VALUES (?, ?, ?, ?, ?, ?)",
            [$orderId, $amount, $actualTendered, $paymentMethod, $finalRefNo, $receivedBy]
        );

        $this->logAudit($receivedBy, 'Payment Recorded', 'OrderService', "Recorded ₱$amount (Tendered: ₱$actualTendered) for Order #$orderId");
        return true;
    }

    public static function validateCustomerInput(string $name, string $contact = '', string $email = ''): array {
        $name = trim($name);
        $contact = trim($contact);
        $email = trim($email);

        if (empty($name) || !preg_match('/[a-zA-Z]{2,}/', $name)) {
            return ['valid' => false, 'error' => 'Customer Full Name is required and must contain at least 2 alphabetic letters.'];
        }

        if (!empty($contact) && strcasecmp($contact, 'N/A') !== 0) {
            $cleanPhone = preg_replace('/[^0-9+]/', '', $contact);
            $isValidPhone = preg_match('/^(09\d{9}|\+639\d{9}|639\d{9}|9\d{9})$/', $cleanPhone);
            if (!$isValidPhone) {
                return ['valid' => false, 'error' => 'Invalid phone number format! Please enter a valid 11-digit PH mobile number starting with 09 (e.g. 09171234567).'];
            }

            if (strpos($cleanPhone, '+63') === 0) {
                $contact = '0' . substr($cleanPhone, 3);
            } elseif (strpos($cleanPhone, '639') === 0) {
                $contact = '0' . substr($cleanPhone, 2);
            } elseif (strlen($cleanPhone) === 10 && strpos($cleanPhone, '9') === 0) {
                $contact = '0' . $cleanPhone;
            } else {
                $contact = $cleanPhone;
            }
        } else {
            $contact = 'N/A';
        }

        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['valid' => false, 'error' => 'Invalid Email address format! (e.g. customer@gmail.com)'];
        }

        return [
            'valid' => true,
            'name' => $name,
            'contact' => $contact,
            'email' => $email
        ];
    }

    public function addCustomer(string $name, string $contact = '', string $email = '', string $address = ''): int {
        $val = self::validateCustomerInput($name, $contact, $email);
        if (!$val['valid']) {
            throw new Exception($val['error']);
        }

        // Duplicate checks for Name, Contact Number, and Email
        $dupName = $this->db->fetch("SELECT id FROM customers WHERE LOWER(name) = LOWER(?)", [$val['name']]);
        if ($dupName) {
            throw new Exception("Customer registration failed: A customer named '" . htmlspecialchars($val['name']) . "' already exists!");
        }

        if (!empty($val['contact']) && strcasecmp($val['contact'], 'N/A') !== 0) {
            $dupContact = $this->db->fetch("SELECT id FROM customers WHERE contact = ?", [$val['contact']]);
            if ($dupContact) {
                throw new Exception("Customer registration failed: The phone number '" . htmlspecialchars($val['contact']) . "' is already registered to another customer!");
            }
        }

        if (!empty($val['email'])) {
            $dupEmail = $this->db->fetch("SELECT id FROM customers WHERE LOWER(email) = LOWER(?)", [$val['email']]);
            if ($dupEmail) {
                throw new Exception("Customer registration failed: The email address '" . htmlspecialchars($val['email']) . "' is already registered to another customer!");
            }
        }

        $code = 'CUST-' . rand(1000, 9999);
        return $this->db->insert(
            "INSERT INTO customers (customer_code, name, contact, email, address) VALUES (?, ?, ?, ?, ?)",
            [$code, $val['name'], $val['contact'], $val['email'], trim($address)]
        );
    }

    public function updateCustomer(int $id, string $name, string $contact = '', string $email = '', string $address = ''): array {
        if ($id <= 0) {
            return ['success' => false, 'message' => 'Invalid customer ID.'];
        }

        $val = self::validateCustomerInput($name, $contact, $email);
        if (!$val['valid']) {
            return ['success' => false, 'message' => $val['error']];
        }

        // Duplicate checks excluding current customer ID
        $dupName = $this->db->fetch("SELECT id FROM customers WHERE LOWER(name) = LOWER(?) AND id != ?", [$val['name'], $id]);
        if ($dupName) {
            return ['success' => false, 'message' => "Update failed: Another customer named '" . htmlspecialchars($val['name']) . "' already exists!"];
        }

        if (!empty($val['contact']) && strcasecmp($val['contact'], 'N/A') !== 0) {
            $dupContact = $this->db->fetch("SELECT id FROM customers WHERE contact = ? AND id != ?", [$val['contact'], $id]);
            if ($dupContact) {
                return ['success' => false, 'message' => "Update failed: The phone number '" . htmlspecialchars($val['contact']) . "' is already registered to another customer!"];
            }
        }

        if (!empty($val['email'])) {
            $dupEmail = $this->db->fetch("SELECT id FROM customers WHERE LOWER(email) = LOWER(?) AND id != ?", [$val['email'], $id]);
            if ($dupEmail) {
                return ['success' => false, 'message' => "Update failed: The email address '" . htmlspecialchars($val['email']) . "' is already registered to another customer!"];
            }
        }

        $res = $this->db->execute(
            "UPDATE customers SET name = ?, contact = ?, email = ?, address = ? WHERE id = ?",
            [$val['name'], $val['contact'], $val['email'], trim($address), $id]
        );

        return [
            'success' => (bool)$res,
            'message' => $res ? "Customer profile for '{$val['name']}' updated successfully!" : "Failed to update customer profile."
        ];
    }

    public function getOrders(string $search = '', string $statusFilter = '', int $createdBy = 0, string $dateRange = 'today'): array {
        $sql = "SELECT o.*, c.name as customer_name, c.contact as customer_contact, c.email as customer_email, u.full_name as cashier_name 
                FROM orders o 
                JOIN customers c ON o.customer_id = c.id 
                LEFT JOIN users u ON o.created_by = u.id 
                WHERE 1=1";
        $params = [];

        if ($createdBy > 0) {
            $sql .= " AND o.created_by = ?";
            $params[] = $createdBy;
        }

        if (!empty($search)) {
            $sql .= " AND (o.tracking_number LIKE ? OR c.name LIKE ? OR c.contact LIKE ? OR u.full_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        if (!empty($statusFilter) && $statusFilter !== 'all') {
            if ($statusFilter === 'processing') {
                $sql .= " AND o.order_status IN ('Washing', 'Drying', 'Ironing')";
            } elseif (in_array($statusFilter, ['Completed', 'Claimed', 'Claimed / Completed'])) {
                $sql .= " AND o.order_status IN ('Completed', 'Claimed', 'Claimed / Completed')";
            } elseif (in_array($statusFilter, ['Delivered', 'Delivered / Completed'])) {
                $sql .= " AND o.order_status IN ('Delivered', 'Delivered / Completed', 'Completed')";
            } else {
                $sql .= " AND o.order_status = ?";
                $params[] = $statusFilter;
            }
        }

        if (!empty($dateRange) && $dateRange !== 'all') {
            $today = date('Y-m-d');
            if ($dateRange === 'today') {
                $sql .= " AND DATE(o.created_at) = ?";
                $params[] = $today;
            } elseif ($dateRange === 'yesterday') {
                $yesterday = date('Y-m-d', strtotime('-1 day'));
                $sql .= " AND DATE(o.created_at) = ?";
                $params[] = $yesterday;
            } elseif ($dateRange === '7days') {
                $sevenDaysAgo = date('Y-m-d', strtotime('-7 days'));
                $sql .= " AND DATE(o.created_at) >= ?";
                $params[] = $sevenDaysAgo;
            } elseif ($dateRange === '30days') {
                $thirtyDaysAgo = date('Y-m-d', strtotime('-30 days'));
                $sql .= " AND DATE(o.created_at) >= ?";
                $params[] = $thirtyDaysAgo;
            }
        }

        $sql .= " ORDER BY o.id DESC";
        return $this->db->fetchAll($sql, $params);
    }

    public function getCustomers(string $search = ''): array {
        $sql = "
            SELECT 
                c.*,
                COUNT(o.id) as total_orders,
                SUM(CASE WHEN o.order_status IN ('Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed') THEN 1 ELSE 0 END) as completed_orders_count
            FROM customers c
            LEFT JOIN orders o ON o.customer_id = c.id
        ";
        if (!empty($search)) {
            $sql .= " WHERE c.name LIKE ? OR c.contact LIKE ? OR c.customer_code LIKE ? GROUP BY c.id ORDER BY c.name ASC";
            return $this->db->fetchAll($sql, ["%$search%", "%$search%", "%$search%"]);
        }
        $sql .= " GROUP BY c.id ORDER BY c.name ASC";
        return $this->db->fetchAll($sql);
    }

    public function getCustomerLoyaltyDetails(int $customerId): array {
        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) return [];

        $orders = $this->db->fetchAll("
            SELECT id, tracking_number, total_amount, paid_amount, discount, order_status, created_at
            FROM orders
            WHERE customer_id = ?
            ORDER BY id DESC
        ", [$customerId]);

        $completedStatuses = ['Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed'];
        $completedOrders = array_filter($orders, function($o) use ($completedStatuses) {
            return in_array($o['order_status'], $completedStatuses);
        });
        
        $totalCompleted = count($completedOrders);
        $targetStamps = max(1, (int)($this->getSetting('loyalty_stamps_target', '11')));
        $remainder = $totalCompleted % $targetStamps;
        $stamps = ($totalCompleted > 0 && $remainder === 0) ? $targetStamps : $remainder;
        $rewardsEarned = (int)floor($totalCompleted / $targetStamps);
        
        $rewardsClaimed = 0;
        foreach ($orders as $o) {
            if ((float)$o['discount'] > 0 && (float)$o['total_amount'] == 0) {
                $rewardsClaimed++;
            }
        }

        $isEligible = ($totalCompleted > 0 && $remainder === 0);

        return [
            'customer' => $customer,
            'orders' => array_values($orders),
            'total_completed' => $totalCompleted,
            'stamps' => $stamps,
            'target_stamps' => $targetStamps,
            'stamps_needed' => max(0, $targetStamps - $stamps),
            'rewards_earned' => $rewardsEarned,
            'rewards_claimed' => $rewardsClaimed,
            'is_eligible' => $isEligible
        ];
    }

    public function getServices(): array {
        return $this->db->fetchAll("SELECT * FROM services ORDER BY category ASC, service_name ASC");
    }

    public function addService(string $name, string $category, float $pricePerKg, float $minKg = 1.0, string $description = '', string $unit = 'kg'): bool {
        return (bool)$this->db->insert(
            "INSERT INTO services (service_name, category, price_per_kg, min_kg, unit, description) VALUES (?, ?, ?, ?, ?, ?)",
            [$name, $category, $pricePerKg, $minKg, $unit, $description]
        );
    }

    public function updateService(int $id, string $name, string $category, float $pricePerKg, float $minKg = 1.0, string $description = '', string $unit = 'kg'): bool {
        return $this->db->execute(
            "UPDATE services SET service_name = ?, category = ?, price_per_kg = ?, min_kg = ?, unit = ?, description = ? WHERE id = ?",
            [$name, $category, $pricePerKg, $minKg, $unit, $description, $id]
        );
    }

    public function deleteService(int $id): bool {
        $pdo = $this->db->getPdo();
        $pdo->exec("PRAGMA foreign_keys = OFF;");
        $res = $this->db->execute("DELETE FROM services WHERE id = ?", [$id]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        return $res;
    }

    public function toggleUserStatus(int $userId): bool {
        return $this->db->execute("UPDATE users SET status = CASE WHEN status = 'active' THEN 'inactive' ELSE 'active' END WHERE id = ?", [$userId]);
    }

    public function updateUser(int $userId, string $fullName, string $role, string $cashierIdNumber): bool {
        $res = $this->db->execute(
            "UPDATE users SET full_name = ?, role = ?, cashier_id_number = ? WHERE id = ?",
            [$fullName, $role, $cashierIdNumber, $userId]
        );
        if ($res && $role === 'admin') {
            $user = $this->db->fetch("SELECT username, password_hash FROM users WHERE id = ?", [$userId]);
            if ($user) {
                $this->db->execute("INSERT OR REPLACE INTO admins (username, password) VALUES (?, ?)", [$user['username'], $user['password_hash']]);
            }
        }
        return $res;
    }

    public function deleteUser(int $userId): bool {
        $pdo = $this->db->getPdo();
        $pdo->exec("PRAGMA foreign_keys = OFF;");
        $user = $this->db->fetch("SELECT username FROM users WHERE id = ?", [$userId]);
        if ($user) {
            $this->db->execute("DELETE FROM admins WHERE username = ?", [$user['username']]);
        }
        $res = $this->db->execute("DELETE FROM users WHERE id = ?", [$userId]);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        return $res;
    }

    public function resetUserPassword(int $userId, string $newPassword): bool {
        $hash = password_hash($newPassword, PASSWORD_DEFAULT);
        $user = $this->db->fetch("SELECT username FROM users WHERE id = ?", [$userId]);
        if ($user) {
            $this->db->execute("UPDATE admins SET password = ? WHERE username = ?", [$hash, $user['username']]);
        }
        return $this->db->execute("UPDATE users SET password_hash = ? WHERE id = ?", [$hash, $userId]);
    }

    public function getAnalytics(): array {
        $totalSales = (float)($this->db->fetch("SELECT SUM(paid_amount) as total FROM orders")['total'] ?? 0);
        $activeOrders = (int)($this->db->fetch("SELECT COUNT(*) as count FROM orders WHERE order_status NOT IN ('Completed', 'Claimed')")['count'] ?? 0);
        $readyPickup = (int)($this->db->fetch("SELECT COUNT(*) as count FROM orders WHERE order_status = 'Ready for Pickup'")['count'] ?? 0);
        $totalCustomers = (int)($this->db->fetch("SELECT COUNT(*) as count FROM customers")['count'] ?? 0);

        return [
            'total_sales' => $totalSales,
            'active_orders' => $activeOrders,
            'ready_pickup' => $readyPickup,
            'total_customers' => $totalCustomers
        ];
    }

    public function logAudit(int $userId, string $action, string $module, string $details = ''): void {
        $username = $_SESSION['username'] ?? $_SESSION['admin_username'] ?? 'System';
        $this->db->execute(
            "INSERT INTO audit_trail (user_id, username, action, module, details) VALUES (?, ?, ?, ?, ?)",
            [$userId, $username, $action, $module, $details]
        );
    }

    public function logAuditTrail(int $userId, string $details, string $action = 'Payment Collection', string $module = 'POS'): void {
        $this->logAudit($userId, $action, $module, $details);
    }

    public function logMessage(int $customerId, ?int $orderId, string $channel, string $recipient, string $messageText, string $status = 'sent', string $details = ''): bool {
        return (bool)$this->db->insert(
            "INSERT INTO message_logs (customer_id, order_id, channel, recipient, message_text, status, details) VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$customerId > 0 ? $customerId : null, $orderId > 0 ? $orderId : null, $channel, $recipient, $messageText, $status, $details]
        );
    }

    public function isDuplicateMessageRequest(string $recipient, string $messageText, int $secondsWindow = 10): bool {
        $cleanPhone = preg_replace('/[^0-9]/', '', $recipient);
        $last10 = !empty($cleanPhone) ? substr($cleanPhone, -10) : '';
        if (empty($last10)) return false;

        $row = $this->db->fetch(
            "SELECT COUNT(*) as cnt FROM message_logs 
             WHERE (recipient = ? OR recipient LIKE ?) AND message_text = ? 
             AND (
                created_at >= DATETIME('now', '-' || ? || ' seconds')
                OR created_at >= DATETIME('now', 'localtime', '-' || ? || ' seconds')
             )",
            [trim($recipient), "%$last10", $messageText, $secondsWindow, $secondsWindow]
        );
        return ((int)($row['cnt'] ?? 0)) > 0;
    }

    public function getCustomerMessageLogs(int $customerId): array {
        return $this->db->fetchAll(
            "SELECT m.*, o.tracking_number 
             FROM message_logs m 
             LEFT JOIN orders o ON m.order_id = o.id 
             WHERE m.customer_id = ? OR m.recipient IN (SELECT contact FROM customers WHERE id = ?) OR m.recipient IN (SELECT email FROM customers WHERE id = ?)
             ORDER BY m.id DESC",
            [$customerId, $customerId, $customerId]
        );
    }

    public function getCustomerDetailedHistory(int $customerId): array {
        $customer = $this->db->fetch("SELECT * FROM customers WHERE id = ?", [$customerId]);
        if (!$customer) return [];

        $orders = $this->db->fetchAll("
            SELECT o.*, u.full_name as cashier_name
            FROM orders o
            LEFT JOIN users u ON o.created_by = u.id
            WHERE o.customer_id = ?
            ORDER BY o.id DESC
        ", [$customerId]);

        $custContact = trim($customer['contact'] ?? '');
        $cleanContact = preg_replace('/[^0-9]/', '', $custContact);
        $last10 = !empty($cleanContact) ? substr($cleanContact, -10) : 'NOMATCH';

        // Annotate each order with message delivery status
        foreach ($orders as &$ord) {
            $trk = trim($ord['tracking_number'] ?? '');

            $smsLogs = $this->db->fetchAll(
                "SELECT * FROM message_logs 
                 WHERE channel = 'sms' 
                   AND (order_id = ? OR message_text LIKE ? OR (customer_id = ? AND message_text LIKE ?)) 
                 ORDER BY id DESC",
                [$ord['id'], "%$trk%", $customerId, "%$trk%"]
            );

            $emailLogs = $this->db->fetchAll(
                "SELECT * FROM message_logs 
                 WHERE channel = 'email' 
                   AND (order_id = ? OR message_text LIKE ? OR (customer_id = ? AND message_text LIKE ?)) 
                 ORDER BY id DESC",
                [$ord['id'], "%$trk%", $customerId, "%$trk%"]
            );

            $ord['sms_count'] = count($smsLogs);
            $ord['email_count'] = count($emailLogs);
            $ord['last_sms'] = !empty($smsLogs) ? $smsLogs[0] : null;
            $ord['last_email'] = !empty($emailLogs) ? $emailLogs[0] : null;
        }

        $allLogs = $this->getCustomerMessageLogs($customerId);

        $completedStatuses = ['Completed', 'Claimed', 'Claimed / Completed', 'Ready for Pickup', 'Out for Delivery', 'Delivered', 'Delivered / Completed'];
        $completedOrders = array_filter($orders, function($o) use ($completedStatuses) {
            return in_array($o['order_status'], $completedStatuses);
        });
        
        $totalCompleted = count($completedOrders);
        $targetStamps = max(1, (int)($this->getSetting('loyalty_stamps_target', '11')));
        $remainder = $totalCompleted % $targetStamps;
        $stamps = ($totalCompleted > 0 && $remainder === 0) ? $targetStamps : $remainder;
        $rewardsEarned = (int)floor($totalCompleted / $targetStamps);
        
        $rewardsClaimed = 0;
        foreach ($orders as $o) {
            if ((float)$o['discount'] > 0 && (float)$o['total_amount'] == 0) {
                $rewardsClaimed++;
            }
        }

        $isEligible = ($totalCompleted > 0 && $remainder === 0);

        return [
            'customer' => $customer,
            'orders' => $orders,
            'message_logs' => $allLogs,
            'total_completed' => $totalCompleted,
            'stamps' => $stamps,
            'target_stamps' => $targetStamps,
            'stamps_needed' => max(0, $targetStamps - $stamps),
            'rewards_earned' => $rewardsEarned,
            'rewards_claimed' => $rewardsClaimed,
            'is_eligible' => $isEligible
        ];
    }

    public function clearCustomerMessageLogs(int $customerId): bool {
        $customer = $this->db->fetch("SELECT contact, email FROM customers WHERE id = ?", [$customerId]);
        $contact = $customer ? trim($customer['contact'] ?? '') : '';
        $email = $customer ? trim($customer['email'] ?? '') : '';
        $cleanContact = preg_replace('/[^0-9]/', '', $contact);
        $last10 = !empty($cleanContact) ? substr($cleanContact, -10) : 'NOMATCH';

        return $this->db->execute(
            "DELETE FROM message_logs 
             WHERE customer_id = ? 
                OR recipient = ? 
                OR recipient = ? 
                OR (recipient LIKE ?)",
            [$customerId, $contact, $email, "%$last10"]
        );
    }

    public function clearAllMessageLogs(): bool {
        return $this->db->execute("DELETE FROM message_logs");
    }
}
