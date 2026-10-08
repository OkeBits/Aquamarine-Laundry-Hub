# Aquamarine Laundry Management Service System

An offline-first, high-performance web-based management system designed for **Aquamarine Laundry Hub**, built with modern PHP, SQLite, HTML5, CSS3 (Tailwind CSS & Bootstrap 5), JavaScript (ES6+), and Chart/SVG visualizations.

---

## 📌 Project Context & Operational Environment

Aquamarine Laundry Hub is a local commercial service establishment located at Roxas Street, San Francisco District, Pagadian City, Zamboanga del Sur. The shop offers professional garment care solutions, including wash-and-fold, wash-dry-and-iron, express same-day processing, heavy bedding and blanket cleaning, steam ironing, specialized fabric conditioning, and doorstep pickup and delivery services. Operating in an active commercial market, the establishment handles substantial daily transaction volumes involving multi-kilogram garment loads, cash, and GCash payments.

In its baseline operational environment, Aquamarine Laundry relies primarily on manual record-keeping processes. Store staff manually weigh customer garments, calculate service fees using handheld calculators, record customer details in physical logbooks, and write paper receipts. At the end of each work shift, store management manually tallies paper receipts to compute daily revenue, verify cash collections, and track outstanding customer balances.

This baseline operational environment presents critical operational bottlenecks:
1. **High Risk of Human Calculation Errors**: Handheld calculator computations of per-kilogram laundry loads, tiered minimum weights, multiple add-on products, discounts, and delivery surcharges often result in arithmetic errors and financial discrepancies.
2. **Lack of Real-Time Order Tracking**: The absence of a centralized digital tracking dashboard makes it difficult for staff to monitor order progression through washing, drying, and ironing stages.
3. **Lack of Automated Customer Notifications**: Manual customer notification through personal mobile phones is time-consuming, lacks centralized dispatch records, and frequently results in unnotified customers and overdue, uncollected laundry.
4. **Tedious Shift Auditing and Financial Reconciliation**: Manual counting of paper receipts during shift changes makes it difficult to separate cash from GCash collections, verify cashier shift productivity, and monitor outstanding customer receivables.
5. **Store Operational Vulnerability to Internet Outages**: Cloud-dependent point-of-sale solutions fail during local internet service interruptions or power fluctuations, paralyzing store transactions.

To solve these baseline problems, the researchers conceptualized and developed the **Aquamarine Laundry Management Service System**. The system operates locally inside the store premises on a local web server environment using an embedded local database, a structured service-oriented business architecture, and locally hosted visual design frameworks.

The software provides two distinct, role-based operational modules:
- **POS Cashier Terminal**: This module enables cashiers to execute a streamlined 2-Step POS Checkout Wizard by selecting customers, inputting laundry weight in kilograms, computing per-load charges based on minimum weight thresholds, adding customized add-on products with quantity multipliers, selecting walk-in or doorstep delivery with courier assignment, computing cash tendered and change with quick cash bill shortcut buttons, and validating required GCash payment reference numbers. The terminal also allows staff to advance order workflow statuses through eight operational stages, settle outstanding balances upon pickup, print thermal receipts, and dispatch customer notifications, all while enforcing strict shift isolation so that cashiers only view transactions handled during their active duty.
- **Business Owner / Administrator Dashboard**: This module enables store management to exercise global operational oversight by configuring laundry service packages and add-on product catalogs, managing staff user accounts with role-based access permissions, adjusting the customer loyalty stamp reward target, monitoring daily sales performance, analyzing revenue trends through an interactive visual Revenue Trend Curve, tracking cashier shift productivity, and generating printable sales reports and spreadsheet data exports.

By deploying the system locally on store premises, Aquamarine Laundry eliminates manual logbooks, prevents cashier mathematical errors, accelerates transaction processing, protects store records, and ensures continuous, 100% operational availability regardless of external internet connectivity.

---

## 🚀 Key System Modules & Feature Updates

### 1. 🛒 Cashier POS & Order Creation Terminal
- **Interactive Order Creator**: Multi-step order builder (Order & Add-ons → Payment & Checkout).
- **Instant Customer Search Bar**: Real-time customer search by **Name** or **Phone Number** with live result counter and quick clear button.
- **Loyalty Stamp & Reward System**: Automatic stamp accumulation per completed order with customizable stamp targets (e.g. 11 stamps = 1 Free Laundry Reward up to max kg limit).
- **Duplicate Customer Protection**: Prevents duplicate registrations by validating name and contact numbers.

### 2. 📊 Sales Analytics & Financial Reporting
- **Multi-Filter Analytics**: Filter financial records by **Staff/Cashier**, **Payment Status**, **Payment Method (Cash / GCash)**, **Order Status**, **Custom Date Ranges**, or **Search Keywords**.
- **Filter Persistence & Navigation**: Remembers active search criteria across page reloads and smoothly scrolls to results (`#sales_analytics_results`).
- **Dynamic Revenue Trend Curve**: Continuous daily sales collection line graph (SVG) with peak indicators, trend nodes, and hover tooltips.
- **CSV Data Export**: Export filtered sales and revenue reports directly to CSV.

### 3. 🧾 Official Digital & Thermal Receipt System
- **Printable Thermal Receipts**: Professional formatted receipts with shop branding, tracking numbers, line item add-ons, balance breakdown, and QR code payments.
- **Digital Receipt Email & SMS**: Integrated automatic email receipt modal (Gmail SMTP / PHPMailer) and SMS notifications (TextBee API integration).
- **Customizable Messaging Templates**: Configurable SMS templates and receipt disclaimers via Admin Settings.

### 4. 👥 Customer Management Directory
- **Customer Directory**: Centralized list of registered customers, contact numbers, address records, order history, and active loyalty stamp status.
- **Quick Order Creation**: One-click order initiation directly from the customer profile.

### 5. 🛡️ User & Security Management
- **Role-Based Access Control (RBAC)**: Distinct permissions and views for **Administrator** and **Cashier / Staff** roles.
- **Shift Performance Tracking**: Scoped shift metrics for cashiers (`My Shift Transactions Only`) tracking daily sales, completed orders, and collections.

### 6. ⚙️ System Settings & Portals (Admin)
- **Shop Configuration**: Customizable shop name, address, contact details, receipt footer disclaimers, express surcharge percentage, and delivery fees.
- **Loyalty Program Settings**: Adjust loyalty stamp thresholds, reward limits, and max free weight allowance.
- **Database Backup & Data Export**: Instant one-click SQLite database backup export (`Download Database Backup`).
- **API & Portal Settings**: Integrated settings for TextBee SMS API, Gmail SMTP server authentication, and official GCash QR Code image manager.

---

## 🔑 Default Credentials

| Role | Username | Password |
| :--- | :--- | :--- |
| **Administrator** | `admin` | `admin123` |
| **Cashier / Staff** | `staff` | `staff123` |

---

## 💻 How to Run System Offline

1. Double-click **`start_system.bat`** in the root directory (`C:\Users\FUJITSU\Desktop\Aquamarine`).
2. The built-in local PHP server will launch automatically at **`http://localhost:8000`**.
3. Log in using Administrator or Cashier credentials.

---

## 🛠️ Project Technology Stack

- **Backend**: PHP 8+ (PDO SQLite Driver)
- **Database**: SQLite 3 (`aquamarine.sqlite`)
- **Frontend**: Tailwind CSS, Bootstrap 5, Material Symbols, Offline Local CSS Assets
- **Integrations**: PHPMailer (SMTP Email), TextBee (SMS Gateway API)
