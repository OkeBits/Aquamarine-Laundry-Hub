<?php
// Aquamarine Laundry System - Services & Pricing Module

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/auth.php';
requireLogin();

// Add Service
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_service'])) {
    $service_name = trim($_POST['service_name'] ?? '');
    $category = $_POST['category'] ?? 'Wash';
    $price_per_kg = (float)($_POST['price_per_kg'] ?? 0);
    $min_kg = (float)($_POST['min_kg'] ?? 1);
    $description = trim($_POST['description'] ?? '');

    if (!empty($service_name) && $price_per_kg > 0) {
        $stmt = $pdo->prepare("INSERT INTO services (service_name, category, price_per_kg, min_kg, description) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$service_name, $category, $price_per_kg, $min_kg, $description]);

        logAudit($pdo, 'Service Added', 'Services', "Added service $service_name at ₱$price_per_kg/kg");
        header("Location: index.php?page=services&success=Service+$service_name+added+successfully.");
        exit;
    }
}

$services_list = $pdo->query("SELECT * FROM services ORDER BY category ASC, service_name ASC")->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold m-0"><i class="fa-solid fa-list-check text-teal me-2"></i> Services & Pricing Management</h4>
        <small class="text-muted">Configure laundry service offerings, per-kg pricing, and minimum weight policies</small>
    </div>
    <button class="btn btn-primary fw-bold shadow-sm" style="background: #00a896; border: none;" data-bs-toggle="modal" data-bs-target="#addServiceModal">
        <i class="fa-solid fa-plus me-1"></i> Add New Service
    </button>
</div>

<div class="card border-0 shadow-sm rounded-3">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle table-custom m-0">
                <thead>
                    <tr>
                        <th>Service Name</th>
                        <th>Category</th>
                        <th>Price / kg</th>
                        <th>Min. Weight</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($services_list as $s): ?>
                        <tr>
                            <td class="fw-bold text-dark"><?= htmlspecialchars($s['service_name']) ?></td>
                            <td><span class="badge bg-light text-dark border"><?= htmlspecialchars($s['category']) ?></span></td>
                            <td class="fw-bold text-teal"><?= formatCurrency($s['price_per_kg']) ?></td>
                            <td><?= $s['min_kg'] ?> kg</td>
                            <td><small class="text-muted"><?= htmlspecialchars($s['description'] ?: 'N/A') ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Add Service Modal -->
<div class="modal fade" id="addServiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header text-white" style="background: #00a896;">
                <h5 class="modal-title fw-bold"><i class="fa-solid fa-plus me-2"></i> Add New Laundry Service</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <input type="hidden" name="add_service" value="1">
                    
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Service Name *</label>
                        <input type="text" name="service_name" class="form-control" placeholder="e.g. Wash, Dry & Fold" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Category</label>
                        <select name="category" class="form-select">
                            <option value="Wash">Wash</option>
                            <option value="Dry">Dry</option>
                            <option value="Iron">Iron</option>
                            <option value="Full Package" selected>Full Package</option>
                            <option value="Special">Special Treatment</option>
                        </select>
                    </div>

                    <div class="row g-3 mb-3">
                        <div class="col-6">
                            <label class="form-label fw-semibold">Price per kg (₱) *</label>
                            <input type="number" step="0.01" min="1" name="price_per_kg" class="form-control" placeholder="35.00" required>
                        </div>
                        <div class="col-6">
                            <label class="form-label fw-semibold">Min Weight (kg)</label>
                            <input type="number" step="0.1" min="1" name="min_kg" class="form-control" value="3.0">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Description</label>
                        <textarea name="description" class="form-control" rows="2" placeholder="Service description..."></textarea>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary fw-bold" style="background: #00a896; border:none;">Add Service</button>
                </div>
            </form>
        </div>
    </div>
</div>
