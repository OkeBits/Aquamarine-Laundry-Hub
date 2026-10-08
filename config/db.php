<?php
// Aquamarine Laundry Management Service System - Database Configuration (SQLite)

$db_dir = __DIR__ . '/../db';
if (!file_exists($db_dir)) {
    mkdir($db_dir, 0777, true);
}

$db_path = $db_dir . '/aquamarine.sqlite';

try {
    $pdo = new PDO("sqlite:" . $db_path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    
    // Enable Foreign Keys
    $pdo->exec("PRAGMA foreign_keys = ON;");
    
    // Initialize Schema if tables do not exist
    initializeDatabase($pdo);
} catch (PDOException $e) {
    die("Database Connection Error: " . $e->getMessage());
}

function initializeDatabase($pdo) {
    // Users table
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL CHECK(role IN ('admin', 'staff')),
        status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active', 'inactive')),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Customers table
    $pdo->exec("CREATE TABLE IF NOT EXISTS customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_code TEXT UNIQUE NOT NULL,
        name TEXT NOT NULL,
        contact TEXT NOT NULL,
        email TEXT,
        address TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Services table
    $pdo->exec("CREATE TABLE IF NOT EXISTS services (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        service_name TEXT NOT NULL,
        category TEXT NOT NULL CHECK(category IN ('Wash', 'Dry', 'Iron', 'Full Package', 'Special')),
        price_per_kg REAL NOT NULL DEFAULT 0.0,
        min_kg REAL NOT NULL DEFAULT 1.0,
        description TEXT
    )");

    // Orders table
    $pdo->exec("CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        tracking_number TEXT UNIQUE NOT NULL,
        customer_id INTEGER NOT NULL,
        total_weight REAL DEFAULT 0.0,
        subtotal REAL DEFAULT 0.0,
        discount REAL DEFAULT 0.0,
        total_amount REAL DEFAULT 0.0,
        paid_amount REAL DEFAULT 0.0,
        payment_status TEXT DEFAULT 'Pending' CHECK(payment_status IN ('Pending', 'Partially Paid', 'Fully Paid')),
        order_status TEXT DEFAULT 'Pending' CHECK(order_status IN ('Pending', 'Washing', 'Drying', 'Ironing', 'Ready for Pickup', 'Claimed', 'Completed')),
        estimated_pickup DATETIME,
        notes TEXT,
        created_by INTEGER,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (created_by) REFERENCES users(id)
    )");

    // Order Items table
    $pdo->exec("CREATE TABLE IF NOT EXISTS order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        service_id INTEGER NOT NULL,
        item_details TEXT,
        weight_kg REAL DEFAULT 0.0,
        qty INTEGER DEFAULT 1,
        unit_price REAL NOT NULL,
        subtotal REAL NOT NULL,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
        FOREIGN KEY (service_id) REFERENCES services(id)
    )");

    // Payments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL,
        amount REAL NOT NULL,
        payment_method TEXT DEFAULT 'Cash' CHECK(payment_method IN ('Cash', 'GCash', 'Card', 'Bank Transfer')),
        reference_no TEXT,
        received_by INTEGER,
        payment_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id),
        FOREIGN KEY (received_by) REFERENCES users(id)
    )");

    // Notifications table (SMS / Email simulation)
    $pdo->exec("CREATE TABLE IF NOT EXISTS notifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        customer_id INTEGER,
        order_id INTEGER,
        type TEXT NOT NULL CHECK(type IN ('SMS', 'Email')),
        recipient TEXT NOT NULL,
        message TEXT NOT NULL,
        status TEXT DEFAULT 'Sent' CHECK(status IN ('Sent', 'Pending', 'Failed')),
        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (customer_id) REFERENCES customers(id),
        FOREIGN KEY (order_id) REFERENCES orders(id)
    )");

    // Audit Trail table
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_trail (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER,
        username TEXT,
        action TEXT NOT NULL,
        module TEXT NOT NULL,
        details TEXT,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP
    )");

    // Seed default admin and staff if users table is empty
    $user_stmt = $pdo->query("SELECT COUNT(*) FROM users");
    if ($user_stmt->fetchColumn() == 0) {
        $admin_pass = password_hash("admin123", PASSWORD_DEFAULT);
        $staff_pass = password_hash("staff123", PASSWORD_DEFAULT);
        
        $pdo->exec("INSERT INTO users (username, password_hash, full_name, role, status) VALUES 
            ('admin', '$admin_pass', 'System Administrator', 'admin', 'active'),
            ('staff', '$staff_pass', 'Maria Santos (Cashier)', 'staff', 'active')
        ");

        // Seed default Services
        $pdo->exec("INSERT INTO services (service_name, category, price_per_kg, min_kg, description) VALUES
            ('Wash & Fold (Standard)', 'Full Package', 35.00, 3.0, 'Regular clothes washing, drying, and folding'),
            ('Wash, Dry & Iron (Full Care)', 'Full Package', 60.00, 3.0, 'Complete laundry care with crisp steam ironing'),
            ('Express Wash & Dry', 'Wash', 50.00, 2.0, 'Same-day rapid laundry processing'),
            ('Bedding & Blankets', 'Special', 80.00, 1.0, 'Heavy comforters, blankets, and bedsheets'),
            ('Steam Ironing Only', 'Iron', 25.00, 1.0, 'Professional garment ironing per piece/kg')
        ");

        // Seed sample customer
        $pdo->exec("INSERT INTO customers (customer_code, name, contact, email, address) VALUES
            ('CUST-1001', 'Juan Dela Cruz', '09171234567', 'juan@example.com', '123 Rizal St, Manila'),
            ('CUST-1002', 'Ana Reyes', '09289876543', 'ana.reyes@example.com', '456 Mabini Ave, Quezon City')
        ");

        // Seed sample order
        $pdo->exec("INSERT INTO orders (tracking_number, customer_id, total_weight, subtotal, discount, total_amount, paid_amount, payment_status, order_status, estimated_pickup, notes, created_by) VALUES
            ('AQ-2026-0001', 1, 5.0, 175.00, 0.00, 175.00, 175.00, 'Fully Paid', 'Ready for Pickup', datetime('now', '+1 day'), 'Handle with care - whites', 2),
            ('AQ-2026-0002', 2, 4.0, 240.00, 0.00, 240.00, 100.00, 'Partially Paid', 'Washing', datetime('now', '+2 days'), 'Steam iron dresses', 2)
        ");

        // Seed order items
        $pdo->exec("INSERT INTO order_items (order_id, service_id, item_details, weight_kg, qty, unit_price, subtotal) VALUES
            (1, 1, '5kg Shirts and Pants', 5.0, 1, 35.00, 175.00),
            (2, 2, '4kg Formal Wear', 4.0, 1, 60.00, 240.00)
        ");

        // Seed payment
        $pdo->exec("INSERT INTO payments (order_id, amount, payment_method, reference_no, received_by) VALUES
            (1, 175.00, 'Cash', 'REC-0001', 2),
            (2, 100.00, 'GCash', 'GC-8839120', 2)
        ");

        // Seed audit log
        $pdo->exec("INSERT INTO audit_trail (user_id, username, action, module, details) VALUES
            (1, 'admin', 'System Initialized', 'System', 'Initial database schema and seed data created')
        ");
    }
}
