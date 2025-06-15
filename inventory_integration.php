<?php
/**
 * Inventory Integration API
 * Handles automatic inventory deduction and restoration for production schedules
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    exit(0);
}

require_once 'config/db_connection.php';

// Authentication check (modify as needed)
session_start();
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Authentication required']);
    exit();
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => null];

try {
    switch ($action) {
        case 'check_recipe_feasibility':
            $recipe_id = intval($_GET['recipe_id'] ?? 0);
            $batches = intval($_GET['batches'] ?? 1);
            
            if ($recipe_id <= 0) {
                throw new Exception('Invalid recipe ID');
            }
            
            // Check if recipe can be produced
            $stmt = $conn->prepare("SELECT can_produce_recipe(?, ?) as can_produce");
            $stmt->execute([$recipe_id, $batches]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Get detailed ingredient status
            $stmt = $conn->prepare("
                SELECT * FROM recipe_inventory_status 
                WHERE recipe_id = ?
            ");
            $stmt->execute([$recipe_id]);
            $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'can_produce' => (bool)$result['can_produce'],
                'batches_requested' => $batches,
                'ingredients' => $ingredients,
                'summary' => [
                    'total_ingredients' => count($ingredients),
                    'sufficient_ingredients' => count(array_filter($ingredients, fn($i) => $i['ingredient_status'] === 'SUFFICIENT')),
                    'insufficient_ingredients' => count(array_filter($ingredients, fn($i) => $i['ingredient_status'] !== 'SUFFICIENT'))
                ]
            ];
            break;

        case 'start_production':
            $schedule_id = intval($_POST['schedule_id'] ?? 0);
            
            if ($schedule_id <= 0) {
                throw new Exception('Invalid schedule ID');
            }
            
            // Call stored procedure to deduct inventory
            $stmt = $conn->prepare("CALL deduct_inventory_for_production(?, @success, @error_message)");
            $stmt->execute([$schedule_id]);
            
            // Get the results
            $result = $conn->query("SELECT @success as success, @error_message as error_message")->fetch(PDO::FETCH_ASSOC);
            
            if ($result['success']) {
                // Update schedule status
                $stmt = $conn->prepare("UPDATE tbl_schedule SET schedule_status = 'In Progress' WHERE schedule_id = ?");
                $stmt->execute([$schedule_id]);
                
                $response = [
                    'success' => true,
                    'message' => 'Production started successfully. Inventory has been deducted.',
                    'schedule_id' => $schedule_id
                ];
            } else {
                throw new Exception($result['error_message'] ?: 'Failed to deduct inventory');
            }
            break;

        case 'cancel_production':
            $schedule_id = intval($_POST['schedule_id'] ?? 0);
            
            if ($schedule_id <= 0) {
                throw new Exception('Invalid schedule ID');
            }
            
            // Call stored procedure to restore inventory
            $stmt = $conn->prepare("CALL restore_inventory_for_production(?, @success, @error_message)");
            $stmt->execute([$schedule_id]);
            
            // Get the results
            $result = $conn->query("SELECT @success as success, @error_message as error_message")->fetch(PDO::FETCH_ASSOC);
            
            if ($result['success']) {
                // Update schedule status
                $stmt = $conn->prepare("UPDATE tbl_schedule SET schedule_status = 'Cancelled' WHERE schedule_id = ?");
                $stmt->execute([$schedule_id]);
                
                $response = [
                    'success' => true,
                    'message' => 'Production cancelled successfully. Inventory has been restored.',
                    'schedule_id' => $schedule_id
                ];
            } else {
                throw new Exception($result['error_message'] ?: 'Failed to restore inventory');
            }
            break;

        case 'get_production_feasibility':
            // Get all recipes with their production feasibility
            $stmt = $conn->query("SELECT * FROM production_feasibility ORDER BY recipe_name");
            $feasibility_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'data' => $feasibility_data,
                'summary' => [
                    'total_recipes' => count($feasibility_data),
                    'can_produce' => count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'CAN_PRODUCE')),
                    'insufficient_stock' => count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'INSUFFICIENT_STOCK')),
                    'not_linked' => count(array_filter($feasibility_data, fn($r) => $r['production_status'] === 'INGREDIENTS_NOT_LINKED'))
                ]
            ];
            break;

        case 'link_ingredient_to_product':
            $ingredient_id = intval($_POST['ingredient_id'] ?? 0);
            $product_id = $_POST['product_id'] ?? '';
            
            if ($ingredient_id <= 0 || empty($product_id)) {
                throw new Exception('Invalid ingredient ID or product ID');
            }
            
            // Update ingredient to link with product
            $stmt = $conn->prepare("UPDATE tbl_ingredients SET product_id = ? WHERE ingredient_id = ?");
            $stmt->execute([$product_id, $ingredient_id]);
            
            $response = [
                'success' => true,
                'message' => 'Ingredient linked to inventory product successfully',
                'ingredient_id' => $ingredient_id,
                'product_id' => $product_id
            ];
            break;

        case 'get_unlinked_ingredients':
            // Get ingredients that are not linked to inventory products
            $stmt = $conn->query("
                SELECT 
                    i.ingredient_id,
                    i.ingredient_name,
                    i.ingredient_quantity,
                    i.ingredient_unitOfMeasure,
                    r.recipe_name,
                    i.product_id
                FROM tbl_ingredients i
                JOIN tbl_recipe r ON i.recipe_id = r.recipe_id
                WHERE i.product_id IS NULL
                ORDER BY r.recipe_name, i.ingredient_name
            ");
            $unlinked = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Get available inventory products
            $stmt = $conn->query("
                SELECT product_id, product_name, stock_quantity, unit_price 
                FROM roti_seri_bakery_inventory.products 
                ORDER BY product_name
            ");
            $available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'unlinked_ingredients' => $unlinked,
                'available_products' => $available_products,
                'count' => count($unlinked)
            ];
            break;

        case 'get_inventory_impact':
            $schedule_id = intval($_GET['schedule_id'] ?? 0);
            
            if ($schedule_id <= 0) {
                throw new Exception('Invalid schedule ID');
            }
            
            // Get the impact of a schedule on inventory
            $stmt = $conn->prepare("
                SELECT 
                    i.ingredient_name,
                    i.ingredient_quantity,
                    i.ingredient_unitOfMeasure,
                    p.product_name,
                    p.stock_quantity as current_stock,
                    (i.ingredient_quantity * s.schedule_batchNum) as total_required,
                    (p.stock_quantity - (i.ingredient_quantity * s.schedule_batchNum)) as stock_after_production,
                    p.reorder_threshold,
                    CASE 
                        WHEN (p.stock_quantity - (i.ingredient_quantity * s.schedule_batchNum)) <= 0 THEN 'CRITICAL'
                        WHEN (p.stock_quantity - (i.ingredient_quantity * s.schedule_batchNum)) <= p.reorder_threshold THEN 'LOW'
                        ELSE 'OK'
                    END as post_production_status
                FROM tbl_schedule s
                JOIN tbl_ingredients i ON s.recipe_id = i.recipe_id
                LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
                WHERE s.schedule_id = ?
            ");
            $stmt->execute([$schedule_id]);
            $impact_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'schedule_id' => $schedule_id,
                'inventory_impact' => $impact_data,
                'warnings' => array_filter($impact_data, fn($item) => $item['post_production_status'] !== 'OK')
            ];
            break;

        case 'get_low_stock_alerts':
            // Get products that will be low stock or out of stock
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
            $alerts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'alerts' => $alerts,
                'count' => count($alerts),
                'out_of_stock' => count(array_filter($alerts, fn($a) => $a['status'] === 'OUT_OF_STOCK')),
                'low_stock' => count(array_filter($alerts, fn($a) => $a['status'] === 'LOW_STOCK'))
            ];
            break;

        case 'simulate_production_impact':
            $recipe_id = intval($_GET['recipe_id'] ?? 0);
            $batches = intval($_GET['batches'] ?? 1);
            
            if ($recipe_id <= 0) {
                throw new Exception('Invalid recipe ID');
            }
            
            // Simulate the impact without actually deducting
            $stmt = $conn->prepare("
                SELECT 
                    i.ingredient_name,
                    i.ingredient_quantity,
                    i.ingredient_unitOfMeasure,
                    p.product_name,
                    p.stock_quantity as current_stock,
                    (i.ingredient_quantity * ?) as total_required,
                    (p.stock_quantity - (i.ingredient_quantity * ?)) as projected_stock,
                    p.reorder_threshold,
                    p.unit_price,
                    ((i.ingredient_quantity * ?) * p.unit_price) as ingredient_cost,
                    CASE 
                        WHEN p.product_id IS NULL THEN 'NOT_LINKED'
                        WHEN p.stock_quantity < (i.ingredient_quantity * ?) THEN 'INSUFFICIENT'
                        WHEN (p.stock_quantity - (i.ingredient_quantity * ?)) <= 0 THEN 'WILL_BE_OUT'
                        WHEN (p.stock_quantity - (i.ingredient_quantity * ?)) <= p.reorder_threshold THEN 'WILL_BE_LOW'
                        ELSE 'OK'
                    END as impact_status
                FROM tbl_ingredients i
                LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
                WHERE i.recipe_id = ?
            ");
            $stmt->execute([$batches, $batches, $batches, $batches, $batches, $batches, $recipe_id]);
            $simulation = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $total_cost = array_sum(array_column($simulation, 'ingredient_cost'));
            $issues = array_filter($simulation, fn($item) => $item['impact_status'] !== 'OK');
            
            $response = [
                'success' => true,
                'recipe_id' => $recipe_id,
                'batches' => $batches,
                'simulation' => $simulation,
                'summary' => [
                    'total_cost' => $total_cost,
                    'total_ingredients' => count($simulation),
                    'issues_count' => count($issues),
                    'can_proceed' => count($issues) === 0
                ],
                'issues' => $issues
            ];
            break;

        case 'get_recipe_ingredients':
            $recipe_id = intval($_GET['recipe_id'] ?? 0);
            
            if ($recipe_id <= 0) {
                throw new Exception('Invalid recipe ID');
            }
            
            // Get recipe ingredients with inventory info
            $stmt = $conn->prepare("
                SELECT 
                    i.ingredient_id,
                    i.ingredient_name,
                    i.ingredient_quantity,
                    i.ingredient_unitOfMeasure,
                    i.product_id,
                    p.product_name,
                    p.stock_quantity,
                    p.unit_price,
                    CASE 
                        WHEN i.product_id IS NULL THEN 'NOT_LINKED'
                        WHEN p.product_id IS NULL THEN 'PRODUCT_NOT_FOUND'
                        WHEN p.stock_quantity <= 0 THEN 'OUT_OF_STOCK'
                        WHEN p.stock_quantity <= p.reorder_threshold THEN 'LOW_STOCK'
                        ELSE 'AVAILABLE'
                    END as stock_status
                FROM tbl_ingredients i
                LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
                WHERE i.recipe_id = ?
                ORDER BY i.ingredient_name
            ");
            $stmt->execute([$recipe_id]);
            $ingredients = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'recipe_id' => $recipe_id,
                'ingredients' => $ingredients,
                'summary' => [
                    'total_ingredients' => count($ingredients),
                    'linked_ingredients' => count(array_filter($ingredients, fn($i) => $i['product_id'] !== null)),
                    'available_ingredients' => count(array_filter($ingredients, fn($i) => $i['stock_status'] === 'AVAILABLE'))
                ]
            ];
            break;

        case 'test_integration':
            // Test the integration setup
            $stmt = $conn->query("SELECT test_inventory_integration() as test_result");
            $test_result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $response = [
                'success' => true,
                'test_result' => $test_result['test_result'],
                'timestamp' => date('Y-m-d H:i:s')
            ];
            break;

        default:
            throw new Exception('Invalid action specified');
    }

} catch (Exception $e) {
    $response = [
        'success' => false,
        'error' => $e->getMessage(),
        'timestamp' => date('Y-m-d H:i:s'),
        'action' => $action
    ];
    
    // Log the error
    error_log("Inventory Integration API Error: " . $e->getMessage() . " (Action: $action)");
}

echo json_encode($response, JSON_PRETTY_PRINT);
?>