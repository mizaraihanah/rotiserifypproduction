<?php
session_start();
require_once 'config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$page_title = "Inventory Integration Dashboard";
$current_page = "inventory_dashboard";

// Get summary statistics
try {
    // Production feasibility summary
    $stmt = $conn->query("SELECT * FROM production_feasibility");
    $feasibility_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Low stock alerts
    $stmt = $conn->query("
        SELECT 
            p.product_id,
            p.product_name,
            p.stock_quantity,
            p.reorder_threshold,
            CASE 
                WHEN p.stock_quantity <= 0 THEN 'OUT_OF_STOCK'
                WHEN p.stock_quantity <= p.reorder_threshold THEN 'LOW_STOCK'
                ELSE 'OK'
            END as status
        FROM roti_seri_bakery_inventory.products p
        WHERE p.stock_quantity <= p.reorder_threshold
        ORDER BY p.stock_quantity ASC
    ");
    $low_stock_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Unlinked ingredients
    $stmt = $conn->query("
        SELECT 
            i.ingredient_id,
            i.ingredient_name,
            r.recipe_name,
            i.ingredient_quantity,
            i.ingredient_unitOfMeasure
        FROM tbl_ingredients i
        JOIN tbl_recipe r ON i.recipe_id = r.recipe_id
        WHERE i.product_id IS NULL
        ORDER BY r.recipe_name, i.ingredient_name
    ");
    $unlinked_ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Available products for linking
    $stmt = $conn->query("
        SELECT product_id, product_name, stock_quantity, unit_price 
        FROM roti_seri_bakery_inventory.products 
        ORDER BY product_name
    ");
    $available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Recent production schedules
    $stmt = $conn->query("
        SELECT 
            s.schedule_id,
            r.recipe_name,
            s.schedule_date,
            s.schedule_status,
            s.schedule_batchNum,
            s.schedule_quantityToProduce
        FROM tbl_schedule s
        JOIN tbl_recipe r ON s.recipe_id = r.recipe_id
        ORDER BY s.schedule_date DESC, s.schedule_id DESC
        LIMIT 10
    ");
    $recent_schedules = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch(PDOException $e) {
    $error_message = "Error loading dashboard data: " . $e->getMessage();
}

$can_produce_count = count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'CAN_PRODUCE'));
$insufficient_stock_count = count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'INSUFFICIENT_STOCK'));
$not_linked_count = count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'INGREDIENTS_NOT_LINKED'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $page_title; ?> - Roti Seri Production</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .dashboard-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .dashboard-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        
        .dashboard-card.success { border-left-color: #28a745; }
        .dashboard-card.warning { border-left-color: #ffc107; }
        .dashboard-card.danger { border-left-color: #dc3545; }
        .dashboard-card.info { border-left-color: #17a2b8; }
        
        .card-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 15px;
            border-bottom: 1px solid #eee;
            padding-bottom: 10px;
        }
        
        .card-title {
            font-size: 16px;
            font-weight: 600;
            margin: 0;
        }
        
        .card-icon {
            font-size: 24px;
            opacity: 0.7;
        }
        
        .stat-number {
            font-size: 32px;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .stat-label {
            color: #666;
            font-size: 14px;
        }
        
        .status-list {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .status-item {
            padding: 8px 12px;
            margin: 5px 0;
            border-radius: 4px;
            font-size: 14px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-ok { background-color: #d4edda; color: #155724; }
        .status-warning { background-color: #fff3cd; color: #856404; }
        .status-danger { background-color: #f8d7da; color: #721c24; }
        .status-info { background-color: #d1ecf1; color: #0c5460; }
        
        .action-buttons {
            display: flex;
            gap: 10px;
            margin-top: 15px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .btn-primary { background-color: #007bff; color: white; }
        .btn-success { background-color: #28a745; color: white; }
        .btn-warning { background-color: #ffc107; color: #212529; }
        .btn-danger { background-color: #dc3545; color: white; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        
        .link-ingredient-form {
            display: none;
            margin-top: 10px;
            padding: 10px;
            background-color: #f8f9fa;
            border-radius: 4px;
        }
        
        .progress-bar {
            width: 100%;
            height: 8px;
            background-color: #e9ecef;
            border-radius: 4px;
            overflow: hidden;
            margin: 10px 0;
        }
        
        .progress-fill {
            height: 100%;
            transition: width 0.3s ease;
        }
        
        .progress-success { background-color: #28a745; }
        .progress-warning { background-color: #ffc107; }
        .progress-danger { background-color: #dc3545; }
    </style>
</head>
<body>
    <?php include 'includes/dashboard_navigation.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1><i class="fas fa-chart-line"></i> Inventory Integration Dashboard</h1>
            <div class="divider"></div>
        </div>

        <!-- Summary Cards -->
        <div class="dashboard-grid">
            <!-- Production Feasibility Summary -->
            <div class="dashboard-card success">
                <div class="card-header">
                    <h3 class="card-title">Production Ready</h3>
                    <i class="fas fa-check-circle card-icon"></i>
                </div>
                <div class="stat-number" style="color: #28a745;"><?php echo $can_produce_count; ?></div>
                <div class="stat-label">Recipes ready for production</div>
                <div class="progress-bar">
                    <div class="progress-fill progress-success" style="width: <?php echo count($feasibility_data) > 0 ? ($can_produce_count / count($feasibility_data)) * 100 : 0; ?>%;"></div>
                </div>
            </div>

            <!-- Insufficient Stock -->
            <div class="dashboard-card warning">
                <div class="card-header">
                    <h3 class="card-title">Insufficient Stock</h3>
                    <i class="fas fa-exclamation-triangle card-icon"></i>
                </div>
                <div class="stat-number" style="color: #ffc107;"><?php echo $insufficient_stock_count; ?></div>
                <div class="stat-label">Recipes with low inventory</div>
                <div class="action-buttons">
                    <button class="btn btn-warning btn-sm" onclick="viewInsufficientStock()">
                        <i class="fas fa-eye"></i> View Details
                    </button>
                </div>
            </div>

            <!-- Unlinked Ingredients -->
            <div class="dashboard-card danger">
                <div class="card-header">
                    <h3 class="card-title">Setup Required</h3>
                    <i class="fas fa-unlink card-icon"></i>
                </div>
                <div class="stat-number" style="color: #dc3545;"><?php echo count($unlinked_ingredients); ?></div>
                <div class="stat-label">Ingredients not linked to inventory</div>
                <div class="action-buttons">
                    <button class="btn btn-danger btn-sm" onclick="showLinkingSection()">
                        <i class="fas fa-link"></i> Link Ingredients
                    </button>
                </div>
            </div>

            <!-- Low Stock Alerts -->
            <div class="dashboard-card info">
                <div class="card-header">
                    <h3 class="card-title">Stock Alerts</h3>
                    <i class="fas fa-bell card-icon"></i>
                </div>
                <div class="stat-number" style="color: #17a2b8;"><?php echo count($low_stock_items); ?></div>
                <div class="stat-label">Items requiring reorder</div>
                <div class="action-buttons">
                    <a href="../get_inventory_status.php" class="btn btn-info btn-sm" target="_blank">
                        <i class="fas fa-external-link-alt"></i> View Inventory
                    </a>
                </div>
            </div>
        </div>

        <!-- Production Feasibility Section -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Recipe Production Status</h3>
                <button class="btn btn-primary btn-sm" onclick="refreshFeasibility()">
                    <i class="fas fa-sync-alt"></i> Refresh
                </button>
            </div>
            <div class="status-list">
                <?php foreach ($feasibility_data as $recipe): ?>
                    <?php
                    $statusClass = '';
                    $statusIcon = '';
                    $statusText = '';
                    
                    switch($recipe['production_status']) {
                        case 'CAN_PRODUCE':
                            $statusClass = 'status-ok';
                            $statusIcon = 'fas fa-check-circle';
                            $statusText = 'Ready to produce';
                            break;
                        case 'INSUFFICIENT_STOCK':
                            $statusClass = 'status-warning';
                            $statusIcon = 'fas fa-exclamation-triangle';
                            $statusText = 'Low stock for production';
                            break;
                        case 'INGREDIENTS_NOT_LINKED':
                            $statusClass = 'status-danger';
                            $statusIcon = 'fas fa-unlink';
                            $statusText = 'Ingredients not linked to inventory';
                            break;
                        default:
                            $statusClass = 'status-info';
                            $statusIcon = 'fas fa-question-circle';
                            $statusText = 'Status unknown';
                    }
                    ?>
                    <div class="status-item <?php echo $statusClass; ?>">
                        <div>
                            <i class="<?php echo $statusIcon; ?>"></i>
                            <strong><?php echo htmlspecialchars($recipe['recipe_name']); ?></strong>
                            <small>(Max batches: <?php echo $recipe['max_possible_batches'] ?: 0; ?>)</small>
                        </div>
                        <div>
                            <small><?php echo $statusText; ?></small>
                            <button class="btn btn-sm btn-primary" onclick="viewRecipeDetails(<?php echo $recipe['recipe_id']; ?>)">
                                <i class="fas fa-info-circle"></i>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Low Stock Items -->
        <?php if (!empty($low_stock_items)): ?>
        <div class="dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Low Stock Alert</h3>
                <span class="badge badge-warning"><?php echo count($low_stock_items); ?> items</span>
            </div>
            <div class="status-list">
                <?php foreach ($low_stock_items as $item): ?>
                    <div class="status-item <?php echo $item['status'] === 'OUT_OF_STOCK' ? 'status-danger' : 'status-warning'; ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($item['product_name']); ?></strong>
                            <small>(ID: <?php echo $item['product_id']; ?>)</small>
                        </div>
                        <div>
                            <span>Stock: <?php echo $item['stock_quantity']; ?></span>
                            <small>(Threshold: <?php echo $item['reorder_threshold']; ?>)</small>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- Ingredient Linking Section -->
        <div id="linking-section" class="dashboard-card" style="display: none;">
            <div class="card-header">
                <h3 class="card-title">Link Ingredients to Inventory Products</h3>
                <button class="btn btn-sm btn-secondary" onclick="hideLinkingSection()">
                    <i class="fas fa-times"></i> Close
                </button>
            </div>
            <div class="status-list">
                <?php foreach ($unlinked_ingredients as $ingredient): ?>
                    <div class="status-item status-warning">
                        <div>
                            <strong><?php echo htmlspecialchars($ingredient['ingredient_name']); ?></strong>
                            <small>in <?php echo htmlspecialchars($ingredient['recipe_name']); ?></small>
                            <br><small><?php echo $ingredient['ingredient_quantity']; ?> <?php echo $ingredient['ingredient_unitOfMeasure']; ?></small>
                        </div>
                        <div>
                            <button class="btn btn-sm btn-primary" onclick="showLinkForm(<?php echo $ingredient['ingredient_id']; ?>)">
                                <i class="fas fa-link"></i> Link
                            </button>
                        </div>
                    </div>
                    <div id="link-form-<?php echo $ingredient['ingredient_id']; ?>" class="link-ingredient-form">
                        <select id="product-select-<?php echo $ingredient['ingredient_id']; ?>" class="form-control">
                            <option value="">Select inventory product</option>
                            <?php foreach ($available_products as $product): ?>
                                <option value="<?php echo $product['product_id']; ?>">
                                    <?php echo htmlspecialchars($product['product_name']); ?> 
                                    (Stock: <?php echo $product['stock_quantity']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div style="margin-top: 10px;">
                            <button class="btn btn-sm btn-success" onclick="linkIngredient(<?php echo $ingredient['ingredient_id']; ?>)">
                                <i class="fas fa-save"></i> Save Link
                            </button>
                            <button class="btn btn-sm btn-secondary" onclick="hideLinkForm(<?php echo $ingredient['ingredient_id']; ?>)">
                                Cancel
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Recent Schedules -->
        <div class="dashboard-card">
            <div class="card-header">
                <h3 class="card-title">Recent Production Schedules</h3>
                <a href="view_schedules.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-list"></i> View All
                </a>
            </div>
            <div class="status-list">
                <?php foreach ($recent_schedules as $schedule): ?>
                    <?php
                    $statusClass = '';
                    switch($schedule['schedule_status']) {
                        case 'Completed':
                            $statusClass = 'status-ok';
                            break;
                        case 'In Progress':
                            $statusClass = 'status-info';
                            break;
                        case 'Pending':
                            $statusClass = 'status-warning';
                            break;
                        default:
                            $statusClass = 'status-danger';
                    }
                    ?>
                    <div class="status-item <?php echo $statusClass; ?>">
                        <div>
                            <strong><?php echo htmlspecialchars($schedule['recipe_name']); ?></strong>
                            <small>Date: <?php echo date('M j, Y', strtotime($schedule['schedule_date'])); ?></small>
                        </div>
                        <div>
                            <span><?php echo $schedule['schedule_status']; ?></span>
                            <small><?php echo $schedule['schedule_batchNum']; ?> batches</small>
                            <?php if ($schedule['schedule_status'] === 'Pending'): ?>
                                <button class="btn btn-sm btn-success" onclick="startProduction(<?php echo $schedule['schedule_id']; ?>)">
                                    <i class="fas fa-play"></i> Start
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </main>

    <script>
        function refreshFeasibility() {
            location.reload();
        }
        
        function showLinkingSection() {
            document.getElementById('linking-section').style.display = 'block';
        }
        
        function hideLinkingSection() {
            document.getElementById('linking-section').style.display = 'none';
        }
        
        function showLinkForm(ingredientId) {
            document.getElementById('link-form-' + ingredientId).style.display = 'block';
        }
        
        function hideLinkForm(ingredientId) {
            document.getElementById('link-form-' + ingredientId).style.display = 'none';
        }
        
        function linkIngredient(ingredientId) {
            const productId = document.getElementById('product-select-' + ingredientId).value;
            
            if (!productId) {
                alert('Please select a product to link');
                return;
            }
            
            fetch('inventory_integration.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=link_ingredient_to_product&ingredient_id=${ingredientId}&product_id=${productId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Ingredient linked successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error linking ingredient: ' + error);
            });
        }
        
        function startProduction(scheduleId) {
            if (!confirm('Start production? This will deduct ingredients from inventory.')) {
                return;
            }
            
            fetch('inventory_integration.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: `action=start_production&schedule_id=${scheduleId}`
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Production started successfully! Inventory has been deducted.');
                    location.reload();
                } else {
                    alert('Error: ' + data.error);
                }
            })
            .catch(error => {
                alert('Error starting production: ' + error);
            });
        }
        
        function viewRecipeDetails(recipeId) {
            // You can implement a modal or redirect to recipe details
            window.open(`recipe_details.php?id=${recipeId}`, '_blank');
        }
        
        function viewInsufficientStock() {
            // Filter and show only insufficient stock recipes
            const statusItems = document.querySelectorAll('.status-item');
            statusItems.forEach(item => {
                if (item.classList.contains('status-warning')) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        }
        
        // Auto refresh every 5 minutes
        setInterval(() => {
            location.reload();
        }, 300000);
    </script>

    <script src="js/dashboard.js"></script>
</body>
</html>