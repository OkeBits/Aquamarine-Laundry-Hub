<?php
// Aquamarine Laundry Management Service System - Auth & Helper Module

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin() {
    if (!isLoggedIn()) {
        header("Location: index.php?page=login");
        exit;
    }
}

function isAdmin() {
    return isLoggedIn() && $_SESSION['role'] === 'admin';
}

function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header("Location: index.php?page=dashboard&error=Access+denied.+Admin+privileges+required.");
        exit;
    }
}

function logAudit($pdo, $action, $module, $details = '') {
    try {
        $userId = $_SESSION['user_id'] ?? null;
        $username = $_SESSION['username'] ?? 'System';
        $stmt = $pdo->prepare("INSERT INTO audit_trail (user_id, username, action, module, details) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$userId, $username, $action, $module, $details]);
    } catch (Exception $e) {
        // Log silently
    }
}

if (!function_exists('logNotification')) {
    function logNotification(?PDO $pdo, ?int $customerId, ?int $orderId, string $type, string $recipient, string $message, string $status = 'Sent'): void {
        try {
            if ($pdo) {
                $stmt = $pdo->prepare("INSERT INTO notifications (customer_id, order_id, type, recipient, message, status) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$customerId, $orderId, $type, $recipient, $message, $status]);
            }
        } catch (Exception $e) {
            // Log silently
        }
    }
}

if (!function_exists('formatCurrency')) {
    function formatCurrency($amount) {
        return '₱' . number_format((float)$amount, 2);
    }
}
