<?php
// Aquamarine Laundry Management Service System - Database Singleton Class

require_once __DIR__ . '/config.php';

class Database {
    private static ?Database $instance = null;
    private PDO $pdo;

    private function __construct() {
        $dbDir = dirname(DB_PATH);
        if (!file_exists($dbDir)) {
            mkdir($dbDir, 0777, true);
        }

        try {
            $this->pdo = new PDO("sqlite:" . DB_PATH);
            $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
            $this->pdo->exec("PRAGMA foreign_keys = ON;");

            $this->initializeSchema();
            $this->runMigrations();
        } catch (PDOException $e) {
            die("Database Error: " . $e->getMessage());
        }
    }

    public static function getInstance(): Database {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getPdo(): PDO {
        return $this->pdo;
    }

    public function fetch(string $query, array $params = []): ?array {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function fetchAll(string $query, array $params = []): array {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function execute(string $query, array $params = []): bool {
        $stmt = $this->pdo->prepare($query);
        return $stmt->execute($params);
    }

    public function insert(string $query, array $params = []): int {
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        return (int)$this->pdo->lastInsertId();
    }

    private function initializeSchema(): void {
        // Settings Table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            setting_key TEXT PRIMARY KEY,
            setting_value TEXT
        )");

        // Users table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL,
            full_name TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'cashier',
            cashier_id_number TEXT,
            status TEXT NOT NULL DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Legacy Admins table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password TEXT NOT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Customers table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS customers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            customer_code TEXT UNIQUE NOT NULL,
            name TEXT NOT NULL,
            contact TEXT DEFAULT 'N/A',
            email TEXT,
            address TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Services table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS services (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            service_name TEXT NOT NULL,
            category TEXT NOT NULL,
            price_per_kg REAL NOT NULL DEFAULT 0.0,
            min_kg REAL NOT NULL DEFAULT 1.0,
            unit TEXT DEFAULT 'kg',
            description TEXT
        )");

        // Orders table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            tracking_number TEXT UNIQUE NOT NULL,
            customer_id INTEGER NOT NULL,
            total_weight REAL DEFAULT 0.0,
            subtotal REAL DEFAULT 0.0,
            discount REAL DEFAULT 0.0,
            total_amount REAL DEFAULT 0.0,
            paid_amount REAL DEFAULT 0.0,
            payment_status TEXT DEFAULT 'Pending',
            order_status TEXT DEFAULT 'Pending',
            estimated_pickup DATETIME,
            rack_location TEXT,
            notes TEXT,
            driver_name TEXT,
            created_by INTEGER,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (customer_id) REFERENCES customers(id)
        )");

        // Order Items table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            service_id INTEGER NOT NULL,
            item_details TEXT,
            weight_kg REAL DEFAULT 0.0,
            qty INTEGER DEFAULT 1,
            unit_price REAL NOT NULL,
            subtotal REAL NOT NULL,
            FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
        )");

        // Payments table (includes tendered_amount)
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL,
            amount REAL NOT NULL,
            tendered_amount REAL DEFAULT 0.0,
            payment_method TEXT DEFAULT 'Cash',
            reference_no TEXT,
            received_by INTEGER,
            payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (order_id) REFERENCES orders(id)
        )");

        // Audit Trail table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_trail (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER,
            username TEXT,
            action TEXT NOT NULL,
            module TEXT NOT NULL,
            details TEXT,
            timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Addons table
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS addons (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            addon_name TEXT NOT NULL,
            price REAL NOT NULL DEFAULT 0.0,
            icon TEXT DEFAULT '🌸',
            status TEXT DEFAULT 'active',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Seed default addons ONLY on initial schema creation (guarded by addons_seeded setting)
        $addonsSeeded = $this->pdo->query("SELECT setting_value FROM settings WHERE setting_key = 'addons_seeded'")->fetchColumn();
        if (!$addonsSeeded) {
            $addonsCount = $this->pdo->query("SELECT COUNT(*) FROM addons")->fetchColumn();
            if ($addonsCount == 0) {
                $this->pdo->exec("INSERT INTO addons (addon_name, price, icon) VALUES 
                    ('Fabric Softener (Fresh Fragrance)', 15.00, '🌸'),
                    ('Detergent Shot Boost', 20.00, '🧼'),
                    ('Bleach / Stain Treatment', 25.00, '✨'),
                    ('Scent Boost Beads', 30.00, '🌺'),
                    ('Heavy Stain Removal', 40.00, '🧤'),
                    ('Garment Protection Bag', 50.00, '🛍️')
                ");
            }
            $this->pdo->exec("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES ('addons_seeded', '1')");
        }

        // Seed default settings if empty
        $settingsCount = $this->pdo->query("SELECT COUNT(*) FROM settings")->fetchColumn();
        if ($settingsCount == 0) {
            $defaultSettings = [
                'shop_name' => 'Aquamarine Laundry Management Service System',
                'shop_address' => '123 Rizal Ave, Metro Manila, Philippines',
                'shop_phone' => '0917-123-4567',
                'receipt_footer' => 'Thank you for trusting Aquamarine! Unclaimed items after 30 days will be disposed of.',
                'default_pickup_hours' => '48',
                'express_surcharge_percent' => '30',
                'sms_template_received' => 'Aquamarine Laundry: Order {tracking_number} received! Total: P{total_amount}. Est. Pickup: {pickup_date}',
                'sms_template_ready' => 'Aquamarine Laundry: Good news {customer_name}! Order {tracking_number} is READY FOR PICKUP!',
                'sms_template_paid' => 'Aquamarine Laundry: Payment of P{paid_amount} received for order {tracking_number}. Thank you!',
                'textbee_api_key' => '',
                'textbee_device_id' => '',
                'textbee_enabled' => '1',
                'active_sms_gateway' => 'textbee',
                'gcash_number' => '09088980862'
            ];

            $stmt = $this->pdo->prepare("INSERT OR REPLACE INTO settings (setting_key, setting_value) VALUES (?, ?)");
            foreach ($defaultSettings as $k => $v) {
                $stmt->execute([$k, $v]);
            }
        }

        // Seed default users if empty
        $count = $this->pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count == 0) {
            $adminPass = password_hash("admin123", PASSWORD_DEFAULT);
            $cashierPass = password_hash("cashier123", PASSWORD_DEFAULT);

            $this->pdo->exec("INSERT INTO users (username, password_hash, full_name, role, cashier_id_number) VALUES 
                ('admin', '$adminPass', 'System Administrator', 'admin', 'ADM-001'),
                ('cashier1', '$cashierPass', 'Maria Santos', 'cashier', 'CSH-101')
            ");

            $this->pdo->exec("INSERT INTO admins (username, password) VALUES ('admin', '$adminPass')");

            $this->pdo->exec("INSERT INTO services (service_name, category, price_per_kg, min_kg, description) VALUES
                ('Wash & Fold (Standard)', 'Full Package', 35.00, 3.0, 'Regular washing, drying, and folding'),
                ('Wash, Dry & Iron', 'Full Package', 60.00, 3.0, 'Complete laundry care with steam ironing'),
                ('Express Wash & Dry', 'Wash', 50.00, 2.0, 'Rapid same-day laundry processing'),
                ('Bedding & Blankets', 'Special', 80.00, 1.0, 'Heavy comforters, blankets, bedsheets'),
                ('Steam Ironing Only', 'Iron', 25.00, 1.0, 'Professional garment steam ironing')
            ");
        }
    }

    private function runMigrations(): void {
        try {
            $payCols = $this->pdo->query("PRAGMA table_info(payments)")->fetchAll();
            $hasTend = false;
            $hasRef = false;
            foreach ($payCols as $col) {
                if ($col['name'] === 'tendered_amount') { $hasTend = true; }
                if ($col['name'] === 'reference_no') { $hasRef = true; }
            }
            if (!$hasTend) {
                $this->pdo->exec("ALTER TABLE payments ADD COLUMN tendered_amount REAL DEFAULT 0.0");
            }
            if (!$hasRef) {
                $this->pdo->exec("ALTER TABLE payments ADD COLUMN reference_no TEXT");
            }

            $orderCols = $this->pdo->query("PRAGMA table_info(orders)")->fetchAll();
            $hasOrdPm = false;
            $hasOrdRef = false;
            $hasServiceType = false;
            $hasDeliveryFee = false;
            $hasDeliveryAddr = false;
            $hasDriverName = false;

            foreach ($orderCols as $col) {
                if ($col['name'] === 'payment_method') { $hasOrdPm = true; }
                if ($col['name'] === 'reference_no') { $hasOrdRef = true; }
                if ($col['name'] === 'service_type') { $hasServiceType = true; }
                if ($col['name'] === 'delivery_fee') { $hasDeliveryFee = true; }
                if ($col['name'] === 'delivery_address') { $hasDeliveryAddr = true; }
                if ($col['name'] === 'driver_name') { $hasDriverName = true; }
            }
            if (!$hasOrdPm) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN payment_method TEXT DEFAULT 'Cash'");
            }
            if (!$hasOrdRef) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN reference_no TEXT");
            }
            if (!$hasServiceType) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN service_type TEXT DEFAULT 'walk_in'");
            }
            if (!$hasDeliveryFee) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN delivery_fee REAL DEFAULT 0.0");
            }
            if (!$hasDeliveryAddr) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN delivery_address TEXT");
            }
            if (!$hasDriverName) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN driver_name TEXT");
            }
            $hasReceiptToken = false;
            foreach ($orderCols as $col) {
                if ($col['name'] === 'receipt_token') { $hasReceiptToken = true; }
            }
            if (!$hasReceiptToken) {
                $this->pdo->exec("ALTER TABLE orders ADD COLUMN receipt_token TEXT");
            }
            $this->pdo->exec("UPDATE orders SET receipt_token = LOWER(HEX(RANDOMBLOB(8))) WHERE receipt_token IS NULL OR receipt_token = ''");

            // Recreate orders table without legacy CHECK constraint so any status (e.g. Out for Delivery) saves directly
            $tableSql = $this->pdo->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='orders'")->fetchColumn();
            if ($tableSql && strpos($tableSql, 'CHECK') !== false) {
                $this->pdo->exec("PRAGMA foreign_keys=OFF;");
                $this->pdo->exec("CREATE TABLE IF NOT EXISTS orders_temp (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    tracking_number TEXT UNIQUE NOT NULL,
                    customer_id INTEGER NOT NULL,
                    total_weight REAL DEFAULT 0.0,
                    subtotal REAL DEFAULT 0.0,
                    discount REAL DEFAULT 0.0,
                    total_amount REAL DEFAULT 0.0,
                    paid_amount REAL DEFAULT 0.0,
                    payment_status TEXT DEFAULT 'Pending',
                    order_status TEXT DEFAULT 'Pending',
                    estimated_pickup DATETIME,
                    rack_location TEXT,
                    notes TEXT,
                    created_by INTEGER,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    payment_method TEXT DEFAULT 'Cash',
                    reference_no TEXT,
                    service_type TEXT DEFAULT 'walk_in',
                    delivery_fee REAL DEFAULT 0.0,
                    delivery_address TEXT,
                    driver_name TEXT,
                    FOREIGN KEY (customer_id) REFERENCES customers(id)
                );");
                $this->pdo->exec("INSERT INTO orders_temp SELECT id, tracking_number, customer_id, total_weight, subtotal, discount, total_amount, paid_amount, payment_status, order_status, estimated_pickup, rack_location, notes, created_by, created_at, payment_method, reference_no, service_type, delivery_fee, delivery_address, driver_name FROM orders;");
                $this->pdo->exec("DROP TABLE orders;");
                $this->pdo->exec("ALTER TABLE orders_temp RENAME TO orders;");
                $this->pdo->exec("PRAGMA foreign_keys=ON;");
            }

            // Create message_logs table for tracking SMS & Email dispatches and preventing duplicates
            $this->pdo->exec("CREATE TABLE IF NOT EXISTS message_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                customer_id INTEGER,
                order_id INTEGER,
                channel TEXT NOT NULL,
                recipient TEXT NOT NULL,
                message_text TEXT NOT NULL,
                status TEXT DEFAULT 'sent',
                details TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );");

            // Update previous Doorstep Delivery orders currently set as 'Ready for Pickup' or 'Pending' to 'Out for Delivery'
            $this->pdo->exec("UPDATE orders SET order_status = 'Out for Delivery' WHERE (service_type = 'delivery' OR (delivery_fee IS NOT NULL AND delivery_fee > 0)) AND order_status IN ('Ready for Pickup', 'Pending');");

            // Migration: Ensure unit column exists in services table
            $svcCols = $this->pdo->query("PRAGMA table_info(services)")->fetchAll(PDO::FETCH_ASSOC);
            $hasUnit = false;
            foreach ($svcCols as $c) {
                if ($c['name'] === 'unit') {
                    $hasUnit = true;
                    break;
                }
            }
            if (!$hasUnit) {
                $this->pdo->exec("ALTER TABLE services ADD COLUMN unit TEXT DEFAULT 'kg';");
            }
        } catch (Exception $e) {
            // Migration complete
        }
    }
}
