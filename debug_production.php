<?php
session_start();
require_once 'config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$recipe_id = $_GET['recipe_id'] ?? 0;
$batches = $_GET['batches'] ?? 1;

try {
    // Get all recipes for dropdown
    $stmt = $conn->query("SELECT recipe_id, recipe_name, recipe_batchSize FROM tbl_recipe ORDER BY recipe_name");
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $debug_data = [];
    $can_produce = false;
    
    if ($recipe_id > 0) {
        // Get recipe details
        $stmt = $conn->prepare("SELECT * FROM tbl_recipe WHERE recipe_id = ?");
        $stmt->execute([$recipe_id]);
        $recipe = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get detailed ingredient analysis
        $stmt = $conn->prepare("
            SELECT 
                i.ingredient_id,
                i.ingredient_name,
                i.ingredient_quantity as recipe_quantity,
                i.ingredient_unitOfMeasure as recipe_unit,
                i.product_id,
                i.inventory_units_required,
                i.conversion_factor,
                i.conversion_notes,
                p.product_name,
                p.stock_quantity as available_stock,
                pc.conversion_factor as current_conversion_factor,
                pc.notes as conversion_notes,
                CASE 
                    WHEN i.product_id IS NULL THEN 'NOT_LINKED'
                    WHEN p.product_id IS NULL THEN 'PRODUCT_NOT_FOUND'
                    WHEN pc.conversion_factor IS NULL THEN 'NO_CONVERSION'
                    WHEN p.stock_quantity <= 0 THEN 'OUT_OF_STOCK'
                    WHEN (i.ingredient_quantity * ? / COALESCE(pc.conversion_factor, 1)) > p.stock_quantity THEN 'INSUFFICIENT'
                    ELSE 'SUFFICIENT'
                END as status,
                (i.ingredient_quantity * ?) as total_recipe_amount,
                CASE 
                    WHEN pc.conversion_factor IS NOT NULL THEN 
                        (i.ingredient_quantity * ? / pc.conversion_factor)
                    ELSE 
                        (i.ingredient_quantity * ?)
                END as inventory_units_needed,
                CASE 
                    WHEN pc.conversion_factor IS NOT NULL AND p.stock_quantity > 0 THEN 
                        FLOOR(p.stock_quantity * pc.conversion_factor / i.ingredient_quantity)
                    ELSE 0
                END as max_possible_batches
            FROM tbl_ingredients i
            LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
            LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
            WHERE i.recipe_id = ?
            ORDER BY i.ingredient_name
        ");
        $stmt->execute([$batches, $batches, $batches, $batches, $recipe_id]);
        $debug_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if can produce using function
        $stmt = $conn->prepare("SELECT can_produce_recipe(?, ?) as can_produce");
        $stmt->execute([$recipe_id, $batches]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $can_produce = (bool)$result['can_produce'];
    }
    
} catch(PDOException $e) {
    $error_message = "Error: " . $e->getMessage();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Production Debug Tool - Roti Seri Production</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
<style>
    body {
        color: #333333;
    }
    
    .debug-container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 20px;
    }
    
    .debug-card {
        background: white;
        border-radius: 8px;
        padding: 25px;
        margin-bottom: 20px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    
    .status-sufficient { background-color: #d4edda; color: #155724; }
    .status-insufficient { background-color: #f8d7da; color: #721c24; }
    .status-not-linked { background-color: #fff3cd; color: #856404; }
    .status-no-conversion { background-color: #f8d7da; color: #721c24; }
    .status-out-of-stock { background-color: #f8d7da; color: #721c24; }
    
    .debug-table {
        width: 100%;
        border-collapse: collapse;
        margin-top: 15px;
    }
    
    .debug-table th,
    .debug-table td {
        padding: 12px;
        text-align: left;
        border: 1px solid #dee2e6;
        font-size: 13px;
        color: #333333;
    }
    
    h1, h2, h3, h4, h5, h6, p, label, select, input, a {
        color: #333333;
    }
    
    .debug-table th {
        background-color: #f8f9fa;
        font-weight: 600;
    }
    
    .issue-highlight {
        background-color: #fff3cd;
        border-left: 4px solid #ffc107;
        padding: 15px;
        margin: 10px 0;
    }
    
    .success-highlight {
        background-color: #d4edda;
        border-left: 4px solid #28a745;
        padding: 15px;
        margin: 10px 0;
    }
    
    .form-row {
        display: grid;
        grid-template-columns: 2fr 1fr auto;
        gap: 15px;
        align-items: end;
        margin-bottom: 20px;
    }
    
    .btn {
        padding: 10px 20px;
        border: none;
        border-radius: 4px;
        cursor: pointer;
        text-decoration: none;
        font-weight: 600;
    }
    
    .btn-primary { background-color: #007bff; color: white; }
    .btn-success { background-color: #28a745; color: white; }
    .btn-warning { background-color: #ffc107; color: #212529; }
    .btn-danger { background-color: #dc3545; color: white; }
    
    .quick-fixes {
        background: #f8f9fa;
        padding: 20px;
        border-radius: 8px;
        margin-top: 20px;
    }
</style>

    </style>
</head>
<body>
    <?php include 'includes/dashboard_navigation.php'; ?>

    <main class="main-content">
        <div class="debug-container">
            <div class="page-header">
                <h1><i class="fas fa-bug"></i> Production Debug Tool</h1>
                <div class="divider"></div>
                <p>Use this tool to debug production scheduling issues and identify why recipes can't be produced.</p>
            </div>

            <div class="debug-card">
                <h3>Select Recipe to Debug</h3>
                <form method="GET">
                    <div class="form-row">
                        <div>
                            <label>Recipe</label>
                            <select name="recipe_id" required>
                                <option value="">Select a recipe to debug</option>
                                <?php foreach ($recipes as $recipe_option): ?>
                                    <option value="<?php echo $recipe_option['recipe_id']; ?>" 
                                            <?php echo ($recipe_id == $recipe_option['recipe_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($recipe_option['recipe_name']); ?>
                                        (Batch: <?php echo $recipe_option['recipe_batchSize']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Batches to Test</label>
                            <input type="number" name="batches" value="<?php echo $batches; ?>" min="1" required>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-search"></i> Analyze
                            </button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if ($recipe_id > 0): ?>
                <!-- Results -->
                <div class="debug-card">
                    <h3>Analysis Results</h3>
                    
                    <?php if ($can_produce): ?>
                        <div class="success-highlight">
                            <h4><i class="fas fa-check-circle"></i> ✅ Production Possible</h4>
                            <p>This recipe CAN be produced with <?php echo $batches; ?> batches.</p>
                        </div>
                    <?php else: ?>
                        <div class="issue-highlight">
                            <h4><i class="fas fa-exclamation-triangle"></i> ❌ Production Blocked</h4>
                            <p>This recipe CANNOT be produced with <?php echo $batches; ?> batches. Issues found below:</p>
                        </div>
                    <?php endif; ?>
                    
                    <h4>Recipe: <?php echo htmlspecialchars($recipe['recipe_name']); ?></h4>
                    <p><strong>Batch Size:</strong> <?php echo $recipe['recipe_batchSize']; ?> <?php echo $recipe['recipe_unitOfMeasure']; ?></p>
                    <p><strong>Testing:</strong> <?php echo $batches; ?> batches</p>
                </div>

                <!-- Detailed Ingredient Analysis -->
                <div class="debug-card">
                    <h3>Detailed Ingredient Analysis</h3>
                    
                    <table class="debug-table">
                        <thead>
                            <tr>
                                <th>Ingredient</th>
                                <th>Recipe Amount</th>
                                <th>Total Needed</th>
                                <th>Linked Product</th>
                                <th>Available Stock</th>
                                <th>Conversion</th>
                                <th>Units Needed</th>
                                <th>Status</th>
                                <th>Max Batches</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($debug_data as $ingredient): ?>
                                <tr class="status-<?php echo strtolower(str_replace('_', '-', $ingredient['status'])); ?>">
                                    <td>
                                        <strong><?php echo htmlspecialchars($ingredient['ingredient_name']); ?></strong>
                                        <br><small>ID: <?php echo $ingredient['ingredient_id']; ?></small>
                                    </td>
                                    <td>
                                        <?php echo $ingredient['recipe_quantity']; ?> <?php echo $ingredient['recipe_unit']; ?>
                                        <br><small>per batch</small>
                                    </td>
                                    <td>
                                        <strong><?php echo $ingredient['total_recipe_amount']; ?> <?php echo $ingredient['recipe_unit']; ?></strong>
                                        <br><small>for <?php echo $batches; ?> batches</small>
                                    </td>
                                    <td>
                                        <?php if ($ingredient['product_id']): ?>
                                            <strong><?php echo htmlspecialchars($ingredient['product_name']); ?></strong>
                                            <br><small><?php echo $ingredient['product_id']; ?></small>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">❌ Not Linked</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ingredient['available_stock'] !== null): ?>
                                            <strong><?php echo $ingredient['available_stock']; ?> units</strong>
                                        <?php else: ?>
                                            <span style="color: #666;">N/A</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($ingredient['current_conversion_factor']): ?>
                                            <strong>1 unit = <?php echo $ingredient['current_conversion_factor']; ?> <?php echo $ingredient['recipe_unit']; ?></strong>
                                            <?php if ($ingredient['conversion_notes']): ?>
                                                <br><small><?php echo htmlspecialchars($ingredient['conversion_notes']); ?></small>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">❌ No Conversion</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <strong><?php echo number_format($ingredient['inventory_units_needed'], 4); ?> units</strong>
                                    </td>
                                    <td>
                                        <?php
                                        $statusIcons = [
                                            'SUFFICIENT' => '✅',
                                            'INSUFFICIENT' => '❌',
                                            'NOT_LINKED' => '🔗',
                                            'NO_CONVERSION' => '⚠️',
                                            'OUT_OF_STOCK' => '📭',
                                            'PRODUCT_NOT_FOUND' => '❓'
                                        ];
                                        echo $statusIcons[$ingredient['status']] ?? '❓';
                                        echo ' ' . str_replace('_', ' ', $ingredient['status']);
                                        ?>
                                    </td>
                                    <td>
                                        <strong><?php echo $ingredient['max_possible_batches']; ?></strong>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Quick Fixes -->
                <div class="quick-fixes">
                    <h3><i class="fas fa-tools"></i> Quick Fixes</h3>
                    
                    <?php
                    $issues = array_filter($debug_data, fn($item) => $item['status'] !== 'SUFFICIENT');
                    if (!empty($issues)):
                    ?>
                        <h4>Issues Found:</h4>
                        <ul>
                            <?php foreach ($issues as $issue): ?>
                                <li>
                                    <strong><?php echo htmlspecialchars($issue['ingredient_name']); ?>:</strong>
                                    <?php
                                    switch ($issue['status']) {
                                        case 'NOT_LINKED':
                                            echo "❌ Not linked to inventory product";
                                            echo " → <a href='conversion_setup.php' class='btn btn-warning'>Link Ingredients</a>";
                                            break;
                                        case 'NO_CONVERSION':
                                            echo "⚠️ No conversion factor set";
                                            echo " → <a href='conversion_setup.php' class='btn btn-warning'>Set Conversions</a>";
                                            break;
                                        case 'OUT_OF_STOCK':
                                            echo "📭 Out of stock in inventory";
                                            echo " → <span class='btn btn-danger'>Restock Needed</span>";
                                            break;
                                        case 'INSUFFICIENT':
                                            echo "❌ Not enough stock (need {$issue['inventory_units_needed']} units, have {$issue['available_stock']} units)";
                                            echo " → <span class='btn btn-danger'>Restock or Reduce Batches</span>";
                                            break;
                                        case 'PRODUCT_NOT_FOUND':
                                            echo "❓ Linked product not found in inventory";
                                            echo " → <a href='conversion_setup.php' class='btn btn-danger'>Fix Link</a>";
                                            break;
                                    }
                                    ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <div class="success-highlight">
                            <h4>🎉 No Issues Found!</h4>
                            <p>All ingredients are properly set up and have sufficient stock.</p>
                            <a href="add_schedule.php" class="btn btn-success">
                                <i class="fas fa-plus"></i> Create Production Schedule
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>

    <script src="js/dashboard.js"></script>
</body>
</html>