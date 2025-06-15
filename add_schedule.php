<?php
session_start();
require_once 'config/db_connection.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

// Generate a CSRF token if not already created
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$success_message = '';
$error_message = '';
$recipes = [];
$equipment = [];
$users = [];

try {
    // Get all recipes with their batch sizes and production feasibility
    $stmt = $conn->query("
        SELECT 
            r.recipe_id, 
            r.recipe_name, 
            r.recipe_batchSize,
            pf.max_possible_batches,
            pf.production_status,
            pf.ingredients_short,
            pf.ingredients_not_linked
        FROM tbl_recipe r
        LEFT JOIN production_feasibility pf ON r.recipe_id = pf.recipe_id
        ORDER BY r.recipe_name
    ");
    $recipes = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Handle AJAX request for recipe inventory check with PROPER CONVERSION LOGIC
    if (isset($_GET['check_recipe']) && isset($_GET['batches'])) {
        $recipe_id = intval($_GET['check_recipe']);
        $batches_needed = intval($_GET['batches']);
        
        // Get detailed ingredient availability WITH CONVERSION FACTORS
        $stmt = $conn->prepare("
            SELECT 
                i.ingredient_name,
                i.ingredient_quantity as required_per_batch,
                i.ingredient_unitOfMeasure,
                i.product_id,
                p.product_name,
                p.stock_quantity as available_stock,
                COALESCE(pc.conversion_factor, 1.0) as conversion_factor,
                (i.ingredient_quantity * ?) as total_recipe_amount,
                ((i.ingredient_quantity * ?) / COALESCE(pc.conversion_factor, 1.0)) as inventory_units_needed,
                CASE 
                    WHEN i.product_id IS NULL THEN 'NOT_LINKED'
                    WHEN p.product_id IS NULL THEN 'PRODUCT_NOT_FOUND'
                    WHEN pc.conversion_factor IS NULL THEN 'NO_CONVERSION'
                    WHEN p.stock_quantity <= 0 THEN 'OUT_OF_STOCK'
                    WHEN p.stock_quantity < ((i.ingredient_quantity * ?) / COALESCE(pc.conversion_factor, 1.0)) THEN 'INSUFFICIENT'
                    ELSE 'SUFFICIENT'
                END as status
            FROM tbl_ingredients i
            LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
            LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
            WHERE i.recipe_id = ?
        ");
        $stmt->execute([$batches_needed, $batches_needed, $batches_needed, $recipe_id]);
        $ingredients_check = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Check if production is possible
        $can_produce = true;
        $issues = [];
        
        foreach ($ingredients_check as $ingredient) {
            if ($ingredient['status'] !== 'SUFFICIENT') {
                $can_produce = false;
                $issues[] = $ingredient;
            }
        }
        
        header('Content-Type: application/json');
        echo json_encode([
            'can_produce' => $can_produce,
            'ingredients' => $ingredients_check,
            'issues' => $issues,
            'batches_requested' => $batches_needed
        ]);
        exit();
    }

    // Handle form submission
    if ($_SERVER["REQUEST_METHOD"] == "POST") {

        // Validate CSRF token
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            die("Invalid CSRF token");
        }

        $conn->beginTransaction();
        try {
            // Get schedule details
            $recipe_id = $_POST['recipe_id'];
            $schedule_date = $_POST['schedule_date'];
            $quantity = floatval($_POST['quantity']);
            $schedule_orderVolumn = intval($_POST['schedule_orderVolumn']);
            $assigned_users = $_POST['assigned_users'] ?? [];
            $selected_equipment = $_POST['equipment'] ?? [];
            $schedule_batchNum = $_POST['schedule_batchNum'];

            if (!is_numeric($quantity) || $quantity <= 0) {
                die("Invalid quantity value");
            }

            // IMPROVED INVENTORY CHECKING WITH DETAILED ANALYSIS
            $stmt = $conn->prepare("
                SELECT 
                    i.ingredient_name,
                    i.ingredient_quantity,
                    i.ingredient_unitOfMeasure,
                    i.product_id,
                    p.product_name,
                    p.stock_quantity,
                    COALESCE(pc.conversion_factor, 1.0) as conversion_factor,
                    ((i.ingredient_quantity * ?) / COALESCE(pc.conversion_factor, 1.0)) as inventory_units_needed,
                    CASE 
                        WHEN i.product_id IS NULL THEN 'NOT_LINKED'
                        WHEN p.product_id IS NULL THEN 'PRODUCT_NOT_FOUND'
                        WHEN pc.conversion_factor IS NULL THEN 'NO_CONVERSION'
                        WHEN p.stock_quantity <= 0 THEN 'OUT_OF_STOCK'
                        WHEN p.stock_quantity < ((i.ingredient_quantity * ?) / COALESCE(pc.conversion_factor, 1.0)) THEN 'INSUFFICIENT'
                        ELSE 'SUFFICIENT'
                    END as status
                FROM tbl_ingredients i
                LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
                LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
                WHERE i.recipe_id = ?
            ");
            $stmt->execute([$schedule_batchNum, $schedule_batchNum, $recipe_id]);
            $ingredient_check = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Check if any ingredients have issues
            $blocking_ingredients = array_filter($ingredient_check, function($ingredient) {
                return $ingredient['status'] !== 'SUFFICIENT';
            });
            
            if (!empty($blocking_ingredients)) {
                $error_details = [];
                foreach ($blocking_ingredients as $ingredient) {
                    switch ($ingredient['status']) {
                        case 'NOT_LINKED':
                            $error_details[] = "{$ingredient['ingredient_name']}: Not linked to inventory product";
                            break;
                        case 'NO_CONVERSION':
                            $error_details[] = "{$ingredient['ingredient_name']}: No conversion factor set up";
                            break;
                        case 'OUT_OF_STOCK':
                            $error_details[] = "{$ingredient['ingredient_name']}: Out of stock";
                            break;
                        case 'INSUFFICIENT':
                            $needed = number_format($ingredient['inventory_units_needed'], 2);
                            $available = $ingredient['stock_quantity'];
                            $recipe_amount = $ingredient['ingredient_quantity'] * $schedule_batchNum;
                            $error_details[] = "{$ingredient['ingredient_name']}: Need {$recipe_amount} {$ingredient['ingredient_unitOfMeasure']} ({$needed} inventory units), only {$available} units available";
                            break;
                        case 'PRODUCT_NOT_FOUND':
                            $error_details[] = "{$ingredient['ingredient_name']}: Linked product not found in inventory";
                            break;
                    }
                }
                
                $detailed_error = "Cannot schedule production. Issues found:\n• " . implode("\n• ", $error_details);
                $detailed_error .= "\n\nUse the Debug Tool to analyze: debug_production.php?recipe_id={$recipe_id}&batches={$schedule_batchNum}";
                
                throw new Exception($detailed_error);
            }

            // If we get here, all ingredients are sufficient - proceed with scheduling
            
            // Insert schedule
            $stmt = $conn->prepare("INSERT INTO tbl_schedule 
                (recipe_id, schedule_date, schedule_quantityToProduce, schedule_status, schedule_orderVolumn, schedule_batchNum) 
                VALUES (?, ?, ?, 'Pending', ?, ?)");
            $stmt->execute([$recipe_id, $schedule_date, $quantity, $schedule_orderVolumn, $schedule_batchNum]);
            
            $schedule_id = $conn->lastInsertId();

            // Insert equipment assignments
            if (!empty($selected_equipment)) {
                $stmt = $conn->prepare("INSERT INTO tbl_schedule_equipment (schedule_id, equipment_id) VALUES (?, ?)");
                foreach ($selected_equipment as $equipment_id) {
                    $stmt->execute([$schedule_id, $equipment_id]);
                }
            }

            // Insert user assignments
            if (!empty($assigned_users)) {
                $stmt = $conn->prepare("INSERT INTO tbl_schedule_assignments (schedule_id, user_id) VALUES (?, ?)");
                foreach ($assigned_users as $user_id) {
                    $stmt->execute([$schedule_id, $user_id]);
                }
            }

            $conn->commit();
            $success_message = "Schedule created successfully! Inventory will be automatically deducted when production starts.";
            
        } catch(Exception $e) {
            $conn->rollBack();
            $error_message = "Production scheduling failed: " . $e->getMessage();
        }
    }

    // Handle AJAX requests for user availability
    if (isset($_GET['date'])) {
        $selected_date = $_GET['date'];

        $stmt = $conn->prepare("
            SELECT u.user_id, u.user_fullName, u.user_role,
                   CASE 
                       WHEN EXISTS (
                           SELECT 1 
                           FROM tbl_schedule_assignments sa 
                           JOIN tbl_schedule s ON sa.schedule_id = s.schedule_id 
                           WHERE sa.user_id = u.user_id 
                           AND DATE(s.schedule_date) = ?
                           AND s.schedule_status != 'Completed'
                       ) THEN 'Unavailable'
                       ELSE 'Available'
                   END AS availability_status
            FROM tbl_users u
            WHERE u.user_role IN ('Baker', 'Supervisor')
            ORDER BY u.user_role, u.user_fullName
        ");
        $stmt->execute([$selected_date]);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

        header('Content-Type: application/json');
        echo json_encode($users);
        exit();
    }

    // Handle AJAX requests for equipment availability
    if (isset($_GET['date_equipment'])) {
        $selected_date = $_GET['date_equipment'];
    
        $stmt = $conn->prepare("
            SELECT e.equipment_id, e.equipment_name,
                   CASE 
                       WHEN EXISTS (
                           SELECT 1 
                           FROM tbl_schedule_equipment se 
                           JOIN tbl_schedule s ON se.schedule_id = s.schedule_id 
                           WHERE se.equipment_id = e.equipment_id 
                           AND DATE(s.schedule_date) = ?
                           AND s.schedule_status != 'Completed'
                       ) THEN 'In-Use'
                       ELSE 'Available'
                   END AS availability_status
            FROM tbl_equipments e
            ORDER BY e.equipment_name
        ");
        $stmt->execute([$selected_date]);
        $equipment = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
        header('Content-Type: application/json');
        echo json_encode($equipment);
        exit();
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
    <title>Add Schedule - Roti Seri Production</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/schedule.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .inventory-status {
            margin-top: 10px;
            padding: 10px;
            border-radius: 5px;
            display: none;
        }
        .status-sufficient { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .status-insufficient { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .status-warning { background-color: #fff3cd; color: #856404; border: 1px solid #ffeaa7; }
        
        .ingredient-list {
            max-height: 300px;
            overflow-y: auto;
            margin-top: 10px;
        }
        
        .ingredient-item {
            padding: 8px;
            margin: 5px 0;
            border-radius: 3px;
            font-size: 14px;
        }
        
        .ingredient-sufficient { background-color: #e8f5e8; }
        .ingredient-insufficient { background-color: #ffeaea; }
        .ingredient-not-linked { background-color: #fff0e6; }
        .ingredient-no-conversion { background-color: #ffebee; }
        
        .recipe-status {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: bold;
            margin-left: 10px;
        }
        
        .status-can-produce { background-color: #d4edda; color: #155724; }
        .status-cannot-produce { background-color: #f8d7da; color: #721c24; }
        .status-issues { background-color: #fff3cd; color: #856404; }
        
        .debug-link {
            display: inline-block;
            margin-top: 10px;
            padding: 8px 15px;
            background-color: #17a2b8;
            color: white;
            text-decoration: none;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .debug-link:hover {
            background-color: #138496;
            color: white;
        }
    </style>
</head>
<body>
    <?php include 'includes/dashboard_navigation.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Add New Schedule</h1>
            <div class="divider"></div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert success"><?php echo $success_message; ?></div>
        <?php endif; ?>
        <?php if ($error_message): ?>
            <div class="alert error">
                <?php echo nl2br(htmlspecialchars($error_message)); ?>
                <?php if (strpos($error_message, 'debug_production.php') !== false): ?>
                    <?php 
                    // Extract recipe_id and batches from error message if available
                    preg_match('/recipe_id=(\d+)&batches=(\d+)/', $error_message, $matches);
                    if ($matches): 
                    ?>
                        <br><br>
                        <a href="debug_production.php?recipe_id=<?php echo $matches[1]; ?>&batches=<?php echo $matches[2]; ?>" class="debug-link">
                            <i class="fas fa-bug"></i> Open Debug Tool
                        </a>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="POST" class="schedule-form">
            <div class="form-section">
                <h2>Schedule Details</h2>
                <div class="form-group">
                    <label for="recipe_id">Recipe</label>
                    <select id="recipe_id" name="recipe_id" required onchange="checkRecipeInventory()">
                        <option value="">Select Recipe</option>
                        <?php foreach ($recipes as $recipe): ?>
                            <option value="<?php echo $recipe['recipe_id']; ?>" 
                                    data-batch-size="<?php echo $recipe['recipe_batchSize']; ?>"
                                    data-max-batches="<?php echo $recipe['max_possible_batches'] ?? 0; ?>"
                                    data-production-status="<?php echo $recipe['production_status'] ?? 'UNKNOWN'; ?>">
                                <?php echo htmlspecialchars($recipe['recipe_name']); ?>
                                <?php 
                                $status = $recipe['production_status'] ?? 'UNKNOWN';
                                $statusClass = '';
                                $statusText = '';
                                
                                switch($status) {
                                    case 'CAN_PRODUCE':
                                        $statusClass = 'status-can-produce';
                                        $statusText = 'Ready';
                                        break;
                                    case 'INSUFFICIENT_STOCK':
                                        $statusClass = 'status-cannot-produce';
                                        $statusText = 'Low Stock';
                                        break;
                                    case 'INGREDIENTS_NOT_LINKED':
                                        $statusClass = 'status-issues';
                                        $statusText = 'Setup Needed';
                                        break;
                                    case 'CANNOT_PRODUCE':
                                        $statusClass = 'status-cannot-produce';
                                        $statusText = 'Cannot Produce';
                                        break;
                                    default:
                                        $statusClass = 'status-issues';
                                        $statusText = 'Check Required';
                                }
                                ?>
                                <span class="recipe-status <?php echo $statusClass; ?>"><?php echo $statusText; ?></span>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    
                    <div id="inventory-status" class="inventory-status">
                        <div id="inventory-summary"></div>
                        <div id="ingredient-details" class="ingredient-list"></div>
                    </div>
                </div>

                <div class="form-group">
                    <label for="schedule_date">Production Date</label>
                    <input type="date" id="schedule_date" name="schedule_date" required 
                           min="<?php echo date('Y-m-d'); ?>">
                </div>

                <div class="form-group">
                    <label for="schedule_orderVolumn">Order Volume (units)</label>
                    <div class="input-with-button">
                        <input type="number" id="schedule_orderVolumn" name="schedule_orderVolumn" 
                               required min="1" onchange="calculateProduction()">
                        <button type="button" id="calculateBtn" class="calculate-btn" onclick="calculateProduction()">
                            <i class="fas fa-calculator"></i> Calculate
                        </button>
                    </div>
                    <small id="calculation-info" class="calculation-info"></small>
                </div>

                <div class="form-group">
                    <label for="schedule_batchNum">Number of Batches:</label>
                    <input type="number" 
                           id="schedule_batchNum" 
                           name="schedule_batchNum" 
                           class="form-control" 
                           min="1" 
                           readonly
                           onchange="checkRecipeInventory()">
                    <small id="batch-calculation" class="calculation-info"></small>
                </div>

                <div class="form-group">
                    <label for="quantity">Quantity to Produce</label>
                    <input type="number" id="quantity" name="quantity" 
                           step="0.01" min="0.01" required readonly>
                    <small id="quantity-calculation" class="calculation-info"></small>
                </div>
            </div>

            <div class="form-section">
                <h2>Equipment Selection</h2>
                <div id="equipment-selection" class="equipment-selection">
                    <p>Select a production date to see equipment availability.</p>
                </div>
            </div>

            <div class="form-section">
                <h2>Assign Users</h2>
                <div id="user-availability" class="user-selection">
                    <p>Select a production date to see user availability.</p>
                </div>
            </div>

            <!-- Add the CSRF token -->
            <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">

            <div class="form-actions">
                <button type="submit" class="submit-btn" id="submit-btn">Create Schedule</button>
                <a href="view_schedules.php" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </main>

    <script>
        function calculateProduction() {
            const recipeSelect = document.getElementById('recipe_id');
            const orderVolume = parseInt(document.getElementById('schedule_orderVolumn').value) || 0;
            const batchNumInput = document.getElementById('schedule_batchNum');
            const quantityInput = document.getElementById('quantity');
            
            if (!recipeSelect.value || orderVolume <= 0) {
                return;
            }
            
            const selectedOption = recipeSelect.options[recipeSelect.selectedIndex];
            const batchSize = parseInt(selectedOption.dataset.batchSize) || 1;
            
            // Calculate number of batches needed
            const batchesNeeded = Math.ceil(orderVolume / batchSize);
            const totalQuantity = batchesNeeded * batchSize;
            
            batchNumInput.value = batchesNeeded;
            quantityInput.value = totalQuantity;
            
            // Update calculation info
            document.getElementById('calculation-info').textContent = 
                `Order: ${orderVolume} units, Batch size: ${batchSize} units/batch`;
            document.getElementById('batch-calculation').textContent = 
                `${batchesNeeded} batches needed (${orderVolume} ÷ ${batchSize} = ${batchesNeeded})`;
            document.getElementById('quantity-calculation').textContent = 
                `Total production: ${totalQuantity} units (${batchesNeeded} × ${batchSize})`;
            
            // Check inventory for the calculated batches
            checkRecipeInventory();
        }
        
        function checkRecipeInventory() {
            const recipeSelect = document.getElementById('recipe_id');
            const batchesInput = document.getElementById('schedule_batchNum');
            const statusDiv = document.getElementById('inventory-status');
            const summaryDiv = document.getElementById('inventory-summary');
            const detailsDiv = document.getElementById('ingredient-details');
            const submitBtn = document.getElementById('submit-btn');
            
            if (!recipeSelect.value || !batchesInput.value) {
                statusDiv.style.display = 'none';
                return;
            }
            
            const recipeId = recipeSelect.value;
            const batches = parseInt(batchesInput.value);
            
            // Show loading
            statusDiv.style.display = 'block';
            statusDiv.className = 'inventory-status status-warning';
            summaryDiv.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking inventory...';
            detailsDiv.innerHTML = '';
            
            // Fetch inventory status
            fetch(`?check_recipe=${recipeId}&batches=${batches}`)
                .then(response => response.json())
                .then(data => {
                    if (data.can_produce) {
                        statusDiv.className = 'inventory-status status-sufficient';
                        summaryDiv.innerHTML = '<i class="fas fa-check-circle"></i> All ingredients available for production!';
                        submitBtn.disabled = false;
                        submitBtn.style.opacity = '1';
                    } else {
                        statusDiv.className = 'inventory-status status-insufficient';
                        summaryDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Cannot produce: Insufficient ingredients in inventory!';
                        submitBtn.disabled = true;
                        submitBtn.style.opacity = '0.5';
                        
                        // Add debug link
                        summaryDiv.innerHTML += `<br><a href="debug_production.php?recipe_id=${recipeId}&batches=${batches}" class="debug-link" target="_blank"><i class="fas fa-bug"></i> Debug This Issue</a>`;
                    }
                    
                    // Show ingredient details
                    let detailsHTML = '';
                    data.ingredients.forEach(ingredient => {
                        let itemClass = '';
                        let statusIcon = '';
                        let statusText = '';
                        
                        switch(ingredient.status) {
                            case 'SUFFICIENT':
                                itemClass = 'ingredient-sufficient';
                                statusIcon = '<i class="fas fa-check text-success"></i>';
                                statusText = `Available: ${ingredient.available_stock} units`;
                                break;
                            case 'INSUFFICIENT':
                                itemClass = 'ingredient-insufficient';
                                statusIcon = '<i class="fas fa-times text-danger"></i>';
                                statusText = `Need: ${ingredient.total_recipe_amount} ${ingredient.ingredient_unitOfMeasure} (${Number(ingredient.inventory_units_needed).toFixed(2)} units), Available: ${ingredient.available_stock} units`;
                                break;
                            case 'OUT_OF_STOCK':
                                itemClass = 'ingredient-insufficient';
                                statusIcon = '<i class="fas fa-times text-danger"></i>';
                                statusText = 'Out of stock';
                                break;
                            case 'NOT_LINKED':
                                itemClass = 'ingredient-not-linked';
                                statusIcon = '<i class="fas fa-unlink text-warning"></i>';
                                statusText = 'Not linked to inventory';
                                break;
                            case 'NO_CONVERSION':
                                itemClass = 'ingredient-no-conversion';
                                statusIcon = '<i class="fas fa-exclamation text-warning"></i>';
                                statusText = 'No conversion factor set';
                                break;
                        }
                        
                        detailsHTML += `
                            <div class="ingredient-item ${itemClass}">
                                ${statusIcon} 
                                <strong>${ingredient.ingredient_name}</strong> 
                                (${ingredient.required_per_batch} ${ingredient.ingredient_unitOfMeasure}/batch × ${batches} batches = ${ingredient.total_recipe_amount} ${ingredient.ingredient_unitOfMeasure})
                                <br><small>${statusText}</small>
                            </div>
                        `;
                    });
                    
                    detailsDiv.innerHTML = detailsHTML;
                })
                .catch(error => {
                    statusDiv.className = 'inventory-status status-insufficient';
                    summaryDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Error checking inventory: ' + error.message;
                    submitBtn.disabled = true;
                    submitBtn.style.opacity = '0.5';
                });
        }
        
        // Handle date change for equipment and user availability
        document.getElementById('schedule_date').addEventListener('change', function() {
            const selectedDate = this.value;
            
            if (selectedDate) {
                // Load equipment availability
                fetch(`?date_equipment=${selectedDate}`)
                    .then(response => response.json())
                    .then(data => {
                        const equipmentDiv = document.getElementById('equipment-selection');
                        let html = '<h3>Available Equipment:</h3>';
                        
                        data.forEach(equipment => {
                            const status = equipment.availability_status === 'Available' ? 'available' : 'unavailable';
                            const disabled = equipment.availability_status === 'Available' ? '' : 'disabled';
                            
                            html += `
                                <label class="equipment-item ${status}">
                                    <input type="checkbox" name="equipment[]" value="${equipment.equipment_id}" ${disabled}>
                                    ${equipment.equipment_name} - ${equipment.availability_status}
                                </label>
                            `;
                        });
                        
                        equipmentDiv.innerHTML = html;
                    });
                
                // Load user availability
                fetch(`?date=${selectedDate}`)
                    .then(response => response.json())
                    .then(data => {
                        const userDiv = document.getElementById('user-availability');
                        let html = '<h3>Available Users:</h3>';
                        
                        data.forEach(user => {
                            const status = user.availability_status === 'Available' ? 'available' : 'unavailable';
                            const disabled = user.availability_status === 'Available' ? '' : 'disabled';
                            
                            html += `
                                <label class="user-item ${status}">
                                    <input type="checkbox" name="assigned_users[]" value="${user.user_id}" ${disabled}>
                                    ${user.user_fullName} (${user.user_role}) - ${user.availability_status}
                                </label>
                            `;
                        });
                        
                        userDiv.innerHTML = html;
                    });
            }
        });
    </script>

    <script src="js/dashboard.js"></script>
    <script src="js/schedule.js"></script>
</body>
</html>