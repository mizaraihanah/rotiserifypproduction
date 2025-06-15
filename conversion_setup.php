<?php
session_start();
require_once 'config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        if (isset($_POST['action'])) {
            switch ($_POST['action']) {
                case 'add_conversion':
                    $product_id = trim($_POST['product_id']);
                    $recipe_unit = trim($_POST['recipe_unit']);
                    $conversion_factor = floatval($_POST['conversion_factor']);
                    $notes = trim($_POST['notes']);
                    
                    if (empty($product_id) || empty($recipe_unit) || $conversion_factor <= 0) {
                        throw new Exception("Please fill all required fields with valid values");
                    }
                    
                    $stmt = $conn->prepare("INSERT INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$product_id, $recipe_unit, $conversion_factor, $notes]);
                    
                    $success_message = "Conversion factor added successfully!";
                    break;
                    
                case 'update_conversion':
                    $conversion_id = intval($_POST['conversion_id']);
                    $conversion_factor = floatval($_POST['conversion_factor']);
                    $notes = trim($_POST['notes']);
                    
                    if ($conversion_id <= 0 || $conversion_factor <= 0) {
                        throw new Exception("Invalid conversion data");
                    }
                    
                    $stmt = $conn->prepare("UPDATE tbl_product_conversions SET conversion_factor = ?, notes = ? WHERE conversion_id = ?");
                    $stmt->execute([$conversion_factor, $notes, $conversion_id]);
                    
                    $success_message = "Conversion factor updated successfully!";
                    break;
                    
                case 'delete_conversion':
                    $conversion_id = intval($_POST['conversion_id']);
                    
                    if ($conversion_id <= 0) {
                        throw new Exception("Invalid conversion ID");
                    }
                    
                    $stmt = $conn->prepare("DELETE FROM tbl_product_conversions WHERE conversion_id = ?");
                    $stmt->execute([$conversion_id]);
                    
                    $success_message = "Conversion factor deleted successfully!";
                    break;
                    
                case 'setup_defaults':
                    $stmt = $conn->prepare("CALL setup_default_conversions()");
                    $stmt->execute();
                    
                    $success_message = "Default conversion factors set up successfully!";
                    break;
                    
                case 'update_ingredients':
                    $stmt = $conn->prepare("CALL update_ingredient_conversions()");
                    $stmt->execute();
                    
                    $success_message = "Ingredient conversions updated successfully!";
                    break;
                    
                case 'auto_setup_product':
                    $product_id = trim($_POST['product_id']);
                    $product_name = trim($_POST['product_name']);
                    
                    if (empty($product_id)) {
                        throw new Exception("Please select a product");
                    }
                    
                    // Auto-setup conversions based on product name
                    $conversions = [];
                    $product_lower = strtolower($product_name);
                    
                    if (strpos($product_lower, 'flour') !== false || strpos($product_lower, 'tepung') !== false) {
                        $conversions = [
                            ['unit' => 'kg', 'factor' => 1.0, 'note' => '1 unit = 1 kg'],
                            ['unit' => 'g', 'factor' => 1000.0, 'note' => '1 unit = 1000 grams'],
                            ['unit' => 'cups', 'factor' => 8.0, 'note' => '1 unit = 8 cups (approx)']
                        ];
                    } elseif (strpos($product_lower, 'sugar') !== false || strpos($product_lower, 'gula') !== false) {
                        $conversions = [
                            ['unit' => 'kg', 'factor' => 1.0, 'note' => '1 unit = 1 kg'],
                            ['unit' => 'g', 'factor' => 1000.0, 'note' => '1 unit = 1000 grams'],
                            ['unit' => 'cups', 'factor' => 5.0, 'note' => '1 unit = 5 cups (approx)']
                        ];
                    } elseif (strpos($product_lower, 'butter') !== false || strpos($product_lower, 'margarine') !== false) {
                        $conversions = [
                            ['unit' => 'kg', 'factor' => 0.5, 'note' => '1 unit = 0.5 kg'],
                            ['unit' => 'g', 'factor' => 500.0, 'note' => '1 unit = 500 grams'],
                            ['unit' => 'ml', 'factor' => 500.0, 'note' => '1 unit = 500 ml'],
                            ['unit' => 'cups', 'factor' => 2.0, 'note' => '1 unit = 2 cups (approx)']
                        ];
                    } elseif (strpos($product_lower, 'oil') !== false || strpos($product_lower, 'minyak') !== false) {
                        $conversions = [
                            ['unit' => 'l', 'factor' => 1.0, 'note' => '1 unit = 1 liter'],
                            ['unit' => 'ml', 'factor' => 1000.0, 'note' => '1 unit = 1000 ml'],
                            ['unit' => 'cups', 'factor' => 4.0, 'note' => '1 unit = 4 cups (approx)']
                        ];
                    } elseif (strpos($product_lower, 'egg') !== false || strpos($product_lower, 'telur') !== false) {
                        $conversions = [
                            ['unit' => 'pcs', 'factor' => 12.0, 'note' => '1 unit = 12 pieces'],
                            ['unit' => 'g', 'factor' => 600.0, 'note' => '1 unit = 600g (12 eggs × 50g)']
                        ];
                    } else {
                        // Generic setup
                        $conversions = [
                            ['unit' => 'kg', 'factor' => 1.0, 'note' => '1 unit = 1 kg (generic)'],
                            ['unit' => 'g', 'factor' => 1000.0, 'note' => '1 unit = 1000 g (generic)'],
                            ['unit' => 'pcs', 'factor' => 1.0, 'note' => '1 unit = 1 piece (generic)']
                        ];
                    }
                    
                    $stmt = $conn->prepare("INSERT IGNORE INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES (?, ?, ?, ?)");
                    $count = 0;
                    foreach ($conversions as $conversion) {
                        $stmt->execute([$product_id, $conversion['unit'], $conversion['factor'], $conversion['note']]);
                        if ($stmt->rowCount() > 0) $count++;
                    }
                    
                    $success_message = "Auto-setup completed! Added $count conversion factors for $product_name.";
                    break;
            }
        }
    } catch(Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get conversion setup status
try {
    $stmt = $conn->query("SELECT * FROM conversion_setup_status ORDER BY conversion_status, product_name");
    $conversion_status = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get all existing conversions grouped by product
    $stmt = $conn->query("
        SELECT 
            pc.*,
            p.product_name
        FROM tbl_product_conversions pc
        JOIN roti_seri_bakery_inventory.products p ON pc.product_id = p.product_id
        ORDER BY p.product_name, pc.recipe_unit
    ");
    $existing_conversions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Group conversions by product
    $conversions_by_product = [];
    foreach ($existing_conversions as $conversion) {
        $conversions_by_product[$conversion['product_id']][] = $conversion;
    }
    
    // Get all products for dropdown
    $stmt = $conn->query("
        SELECT product_id, product_name, stock_quantity 
        FROM roti_seri_bakery_inventory.products 
        ORDER BY product_name
    ");
    $all_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get products without conversions
    $products_without_conversions = array_filter($conversion_status, function($status) {
        return $status['conversion_status'] === 'NO_CONVERSIONS';
    });
    
} catch(PDOException $e) {
    $error_message = "Error loading data: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Conversion Setup - Roti Seri Production</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .page-container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 20px;
        }
        
        .tabs {
            display: flex;
            background: #f8f9fa;
            border-radius: 8px 8px 0 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 0;
            border-bottom: 1px solid #dee2e6;
        }
        
        .tab {
            flex: 1;
            padding: 15px 20px;
            background: #e9ecef;
            border: none;
            cursor: pointer;
            font-size: 16px;
            font-weight: 600;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
            color: #495057 !important; /* Dark gray for better visibility */
            text-decoration: none;
            border-right: 1px solid #dee2e6;
        }
        
        .tab:last-child {
            border-right: none;
        }
        
        .tab.active {
            background: white;
            border-bottom-color: #007bff;
            color: #007bff !important; /* Blue for active state */
        }
        
        .tab:hover {
            background: #f8f9fa; /* Light gray background on hover */
            color: #007bff !important; /* Blue for hover state */
        }
        
        /* Ensure nested elements inherit proper color */
        .tab * {
            color: inherit !important;
        }
        
        .tab-content {
            display: none;
            background: white;
            border-radius: 0 0 8px 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            padding: 30px;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .quick-setup-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .product-card {
            background: #f8f9fa;
            border: 2px solid #dee2e6;
            border-radius: 8px;
            padding: 20px;
            transition: all 0.3s ease;
        }
        
        .product-card:hover {
            border-color: #007bff;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .product-name {
            font-size: 18px;
            font-weight: 600;
            color: #333;
        }
        
        .product-id {
            font-size: 12px;
            color: #666;
            background: #e9ecef;
            padding: 2px 8px;
            border-radius: 4px;
        }
        
        /* Ensure all text elements in product cards are visible */
        .product-card,
        .product-card * {
            color: #495057 !important;
        }
        
        .product-card .product-name {
            color: #333 !important;
        }
        
        .product-card .product-id {
            color: #666 !important;
        }
        
        /* Global text color fixes */
        body, 
        .main-content,
        .page-container,
        .tab-content,
        h1, h2, h3, h4, h5, h6,
        p, div, span, td, th, label {
            color: #495057 !important;
        }
        
        /* Specific overrides for elements that should be different colors */
        .status-complete { background: #d4edda; color: #155724 !important; }
        .status-incomplete { background: #fff3cd; color: #856404 !important; }
        .status-none { background: #f8d7da; color: #721c24 !important; }
        
        .help-box,
        .help-box *,
        .stat-card,
        .stat-card * {
            color: white !important;
        }
        
        .alert.success {
            color: #155724 !important;
        }
        
        .alert.error {
            color: #721c24 !important;
        }
        
        .status-complete { background: #d4edda; color: #155724; }
        .status-incomplete { background: #fff3cd; color: #856404; }
        .status-none { background: #f8d7da; color: #721c24; }
        
        .conversion-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 15px;
            font-size: 14px;
        }
        
        .conversion-table th,
        .conversion-table td {
            padding: 8px 12px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
        }
        
        .conversion-table th {
            background-color: #f8f9fa;
            font-weight: 600;
            color: #495057;
        }
        
        .conversion-table td {
            color: #495057;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
            transition: all 0.3s ease;
            color: white !important; /* Ensure button text is always white */
        }
        
        .btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        }
        
        .btn-primary { background-color: #007bff; color: white !important; }
        .btn-success { background-color: #28a745; color: white !important; }
        .btn-warning { background-color: #ffc107; color: #212529 !important; }
        .btn-danger { background-color: #dc3545; color: white !important; }
        .btn-info { background-color: #17a2b8; color: white !important; }
        .btn-secondary { background-color: #6c757d; color: white !important; }
        
        /* Override any global text color for buttons */
        .btn, .btn * {
            color: inherit !important;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #495057;
        }
        
        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px;
            border: 2px solid #dee2e6;
            border-radius: 6px;
            transition: border-color 0.3s ease;
        }
        
        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: #007bff;
        }
        
        .add-conversion-form {
            background: #f8f9fa;
            padding: 25px;
            border-radius: 8px;
            border: 2px solid #dee2e6;
        }
        
        .help-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 25px;
            border-radius: 8px;
            margin-top: 30px;
        }
        
        .help-box h3 {
            margin-top: 0;
            color: white;
        }
        
        .examples-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        
        .example-box {
            background: rgba(255,255,255,0.1);
            padding: 15px;
            border-radius: 6px;
            backdrop-filter: blur(10px);
        }
        
        .auto-setup-btn {
            width: 100%;
            margin-top: 10px;
            background: linear-gradient(135deg, #28a745, #20c997);
            border: none;
            color: white;
            padding: 10px;
            border-radius: 6px;
            font-weight: 600;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: linear-gradient(135deg, #667eea, #764ba2);
            color: white;
            padding: 20px;
            border-radius: 8px;
            text-align: center;
        }
        
        .stat-number {
            font-size: 2.5em;
            font-weight: bold;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 14px;
            opacity: 0.9;
        }
        
        /* Global text color fixes - Add at the end of CSS */
        body, 
        .main-content,
        .page-container,
        .tab-content,
        h1, h2, h3, h4, h5, h6,
        p, div, span, td, th, label {
            color: #495057 !important;
        }
        
        /* Specific overrides for elements that should be different colors */
        .status-complete { background: #d4edda; color: #155724 !important; }
        .status-incomplete { background: #fff3cd; color: #856404 !important; }
        .status-none { background: #f8d7da; color: #721c24 !important; }
        
        /* Force white text in gradient backgrounds */
        .help-box,
        .help-box * {
            color: white !important;
        }
        
        /* Stat cards should have gray text */
        .stat-card,
        .stat-card *,
        .stat-number,
        .stat-label {
            color: #495057 !important;
        }
        
        .stat-number {
            color: #333 !important;
        }
        
        .stat-label {
            color: #666 !important;
        }
        
        .alert.success {
            color: #155724 !important;
        }
        
        .alert.error {
            color: #721c24 !important;
        }
    </style>
</head>
<body>
    <?php include 'includes/dashboard_navigation.php'; ?>

    <main class="main-content">
        <div class="page-container">
            <div class="page-header">
                <h1><i class="fas fa-exchange-alt"></i> Unit Conversion Setup</h1>
                <div class="divider"></div>
                <p>Configure how recipe measurements convert to inventory units for automatic deduction.</p>
            </div>

            <?php if ($success_message): ?>
                <div class="alert success"><?php echo $success_message; ?></div>
            <?php endif; ?>
            <?php if ($error_message): ?>
                <div class="alert error"><?php echo $error_message; ?></div>
            <?php endif; ?>

            <!-- Statistics -->
            <div class="stats-grid">
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($all_products); ?></div>
                    <div class="stat-label">Total Products</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($products_without_conversions); ?></div>
                    <div class="stat-label">Need Setup</div>
                </div>
                <div class="stat-card">
                    <div class="stat-number"><?php echo count($existing_conversions); ?></div>
                    <div class="stat-label">Conversions Created</div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" onclick="switchTab('quick-setup')">
                    <i class="fas fa-magic"></i> Quick Setup
                </button>
                <button class="tab" onclick="switchTab('manual-add')">
                    <i class="fas fa-plus"></i> Manual Add
                </button>
                <button class="tab" onclick="switchTab('manage')">
                    <i class="fas fa-cog"></i> Manage Existing
                </button>
            </div>

            <!-- Quick Setup Tab -->
            <div id="quick-setup" class="tab-content active">
                <h3>Quick Auto-Setup for Products</h3>
                <p>Click the button below each product to automatically set up common conversion factors based on the product type.</p>
                
                <div class="quick-setup-grid">
                    <?php foreach ($products_without_conversions as $product): ?>
                        <div class="product-card">
                            <div class="product-header">
                                <div>
                                    <div class="product-name"><?php echo htmlspecialchars($product['product_name']); ?></div>
                                    <div class="product-id"><?php echo $product['product_id']; ?></div>
                                </div>
                                <div class="status-badge status-none">
                                    <i class="fas fa-exclamation-triangle"></i> No Setup
                                </div>
                            </div>
                            
                            <div style="margin: 15px 0; padding: 10px; background: white; border-radius: 4px;">
                                <small><strong>Stock:</strong> <?php echo $product['stock_quantity']; ?> units</small>
                            </div>
                            
                            <form method="POST" style="margin: 0;">
                                <input type="hidden" name="action" value="auto_setup_product">
                                <input type="hidden" name="product_id" value="<?php echo $product['product_id']; ?>">
                                <input type="hidden" name="product_name" value="<?php echo htmlspecialchars($product['product_name']); ?>">
                                <button type="submit" class="auto-setup-btn">
                                    <i class="fas fa-magic"></i> Auto-Setup Conversions
                                </button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($products_without_conversions)): ?>
                        <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-check-circle" style="font-size: 48px; margin-bottom: 15px; color: #28a745;"></i>
                            <h3>All Products Configured!</h3>
                            <p>All your products have conversion factors set up. You can manage them in the "Manage Existing" tab.</p>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Global Actions -->
                <div style="text-align: center; margin-top: 30px;">
                    <form method="POST" style="display: inline-block; margin-right: 15px;">
                        <input type="hidden" name="action" value="setup_defaults">
                        <button type="submit" class="btn btn-success" onclick="return confirm('Setup default conversions for ALL products?')">
                            <i class="fas fa-magic"></i> Setup All Products
                        </button>
                    </form>
                    
                    <form method="POST" style="display: inline-block;">
                        <input type="hidden" name="action" value="update_ingredients">
                        <button type="submit" class="btn btn-info" onclick="return confirm('Update all ingredient conversion calculations?')">
                            <i class="fas fa-sync-alt"></i> Update Calculations
                        </button>
                    </form>
                </div>
            </div>

            <!-- Manual Add Tab -->
            <div id="manual-add" class="tab-content">
                <h3>Manually Add Conversion Factor</h3>
                
                <div class="add-conversion-form">
                    <form method="POST">
                        <input type="hidden" name="action" value="add_conversion">
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                            <div class="form-group">
                                <label for="product_id">Select Product</label>
                                <select id="product_id" name="product_id" required>
                                    <option value="">Choose a product...</option>
                                    <?php foreach ($all_products as $product): ?>
                                        <option value="<?php echo $product['product_id']; ?>">
                                            <?php echo htmlspecialchars($product['product_name']); ?> (<?php echo $product['product_id']; ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="recipe_unit">Recipe Unit</label>
                                <select id="recipe_unit" name="recipe_unit" required>
                                    <option value="">Select unit...</option>
                                    <option value="kg">Kilograms (kg)</option>
                                    <option value="g">Grams (g)</option>
                                    <option value="l">Liters (l)</option>
                                    <option value="ml">Milliliters (ml)</option>
                                    <option value="cups">Cups</option>
                                    <option value="tbsp">Tablespoons (tbsp)</option>
                                    <option value="tsp">Teaspoons (tsp)</option>
                                    <option value="pcs">Pieces (pcs)</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="conversion_factor">Conversion Factor</label>
                            <input type="number" id="conversion_factor" name="conversion_factor" step="0.0001" min="0.0001" required placeholder="e.g., 1000 means 1000g per inventory unit">
                            <small style="color: #666;">How many recipe units equal 1 inventory unit?</small>
                        </div>
                        
                        <div class="form-group">
                            <label for="notes">Notes (Optional)</label>
                            <textarea id="notes" name="notes" rows="2" placeholder="e.g., 1 inventory unit = 1kg = 1000g"></textarea>
                        </div>
                        
                        <button type="submit" class="btn btn-success" style="width: 100%; padding: 15px; font-size: 16px;">
                            <i class="fas fa-plus"></i> Add Conversion Factor
                        </button>
                    </form>
                </div>
            </div>

            <!-- Manage Existing Tab -->
            <div id="manage" class="tab-content">
                <h3>Manage Existing Conversions</h3>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(400px, 1fr)); gap: 20px;">
                    <?php foreach ($conversions_by_product as $product_id => $conversions): 
                        $product_name = $conversions[0]['product_name'];
                    ?>
                        <div class="product-card">
                            <div class="product-header">
                                <div class="product-name"><?php echo htmlspecialchars($product_name); ?></div>
                                <div class="status-badge status-complete">
                                    <i class="fas fa-check"></i> Configured
                                </div>
                            </div>
                            
                            <table class="conversion-table">
                                <thead>
                                    <tr>
                                        <th>Unit</th>
                                        <th>Factor</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($conversions as $conversion): ?>
                                        <tr>
                                            <td><?php echo $conversion['recipe_unit']; ?></td>
                                            <td><?php echo $conversion['conversion_factor']; ?></td>
                                            <td>
                                                <button class="btn btn-warning btn-sm" onclick="editConversion(<?php echo $conversion['conversion_id']; ?>, <?php echo $conversion['conversion_factor']; ?>, '<?php echo htmlspecialchars($conversion['notes']); ?>')">
                                                    <i class="fas fa-edit"></i>
                                                </button>
                                                <form method="POST" style="display: inline;">
                                                    <input type="hidden" name="action" value="delete_conversion">
                                                    <input type="hidden" name="conversion_id" value="<?php echo $conversion['conversion_id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this conversion?')">
                                                        <i class="fas fa-trash"></i>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Help Section -->
            <div class="help-box">
                <h3><i class="fas fa-lightbulb"></i> How It Works</h3>
                <div class="examples-grid">
                    <div class="example-box">
                        <h4>Flour Example</h4>
                        <strong>1 Inventory Unit = 1 kg</strong><br>
                        Recipe needs 500g<br>
                        500g ÷ 1000 = 0.5 units deducted
                    </div>
                    <div class="example-box">
                        <h4>Sugar Example</h4>
                        <strong>1 Inventory Unit = 500g</strong><br>
                        Recipe needs 250g<br>
                        250g ÷ 500 = 0.5 units deducted
                    </div>
                    <div class="example-box">
                        <h4>Auto-Detection</h4>
                        System automatically suggests conversions based on product name patterns (flour, sugar, butter, oil, etc.)
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Edit Modal -->
    <div id="editModal" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 1000;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; padding: 30px; border-radius: 8px; width: 400px;">
            <h3>Edit Conversion Factor</h3>
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update_conversion">
                <input type="hidden" name="conversion_id" id="edit_conversion_id">
                
                <div class="form-group">
                    <label>Conversion Factor</label>
                    <input type="number" name="conversion_factor" id="edit_conversion_factor" step="0.0001" min="0.0001" required>
                </div>
                
                <div class="form-group">
                    <label>Notes</label>
                    <textarea name="notes" id="edit_notes" rows="3"></textarea>
                </div>
                
                <div style="text-align: right;">
                    <button type="button" class="btn btn-secondary" onclick="closeEditModal()">Cancel</button>
                    <button type="submit" class="btn btn-success">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function switchTab(tabName) {
            // Hide all tab contents
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected tab content
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked tab
            event.target.classList.add('active');
        }
        
        function editConversion(conversionId, factor, notes) {
            document.getElementById('edit_conversion_id').value = conversionId;
            document.getElementById('edit_conversion_factor').value = factor;
            document.getElementById('edit_notes').value = notes;
            document.getElementById('editModal').style.display = 'block';
        }
        
        function closeEditModal() {
            document.getElementById('editModal').style.display = 'none';
        }
        
        // Auto-suggest conversion factors
        document.getElementById('product_id').addEventListener('change', function() {
            const productName = this.options[this.selectedIndex].text.toLowerCase();
            const factorInput = document.getElementById('conversion_factor');
            const notesInput = document.getElementById('notes');
            const unitSelect = document.getElementById('recipe_unit');
            
            if (!unitSelect.value) return;
            
            let factor = '';
            let note = '';
            
            if (productName.includes('flour') || productName.includes('tepung')) {
                if (unitSelect.value === 'kg') { factor = '1'; note = '1 unit = 1kg'; }
                else if (unitSelect.value === 'g') { factor = '1000'; note = '1 unit = 1000g'; }
                else if (unitSelect.value === 'cups') { factor = '8'; note = '1 unit = 8 cups (approx)'; }
            } else if (productName.includes('sugar') || productName.includes('gula')) {
                if (unitSelect.value === 'kg') { factor = '1'; note = '1 unit = 1kg'; }
                else if (unitSelect.value === 'g') { factor = '1000'; note = '1 unit = 1000g'; }
                else if (unitSelect.value === 'cups') { factor = '5'; note = '1 unit = 5 cups (approx)'; }
            } else if (productName.includes('butter') || productName.includes('margarine')) {
                if (unitSelect.value === 'kg') { factor = '0.5'; note = '1 unit = 0.5kg'; }
                else if (unitSelect.value === 'g') { factor = '500'; note = '1 unit = 500g'; }
                else if (unitSelect.value === 'ml') { factor = '500'; note = '1 unit = 500ml'; }
                else if (unitSelect.value === 'cups') { factor = '2'; note = '1 unit = 2 cups (approx)'; }
            } else if (productName.includes('oil') || productName.includes('minyak')) {
                if (unitSelect.value === 'l') { factor = '1'; note = '1 unit = 1 liter'; }
                else if (unitSelect.value === 'ml') { factor = '1000'; note = '1 unit = 1000ml'; }
                else if (unitSelect.value === 'cups') { factor = '4'; note = '1 unit = 4 cups (approx)'; }
            } else if (productName.includes('egg') || productName.includes('telur')) {
                if (unitSelect.value === 'pcs') { factor = '12'; note = '1 unit = 12 pieces'; }
                else if (unitSelect.value === 'g') { factor = '600'; note = '1 unit = 600g (12 eggs × 50g)'; }
            }
            
            if (factor) {
                factorInput.value = factor;
                notesInput.value = note;
            }
        });
        
        document.getElementById('recipe_unit').addEventListener('change', function() {
            const productSelect = document.getElementById('product_id');
            if (productSelect.value) {
                productSelect.dispatchEvent(new Event('change'));
            }
        });
        
        // Close modal when clicking outside
        document.getElementById('editModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeEditModal();
            }
        });
        
        // Auto-refresh every 30 seconds to show new products
        setInterval(function() {
            // Only refresh if we're on the quick-setup tab and there are no products without conversions
            const quickSetupTab = document.getElementById('quick-setup');
            const noProductsMessage = quickSetupTab.querySelector('h3:contains("All Products Configured!")');
            
            if (!noProductsMessage && quickSetupTab.classList.contains('active')) {
                // Check for new products via AJAX (optional enhancement)
                // location.reload();
            }
        }, 30000);
    </script>

    <script src="js/dashboard.js"></script>
</body>
</html>