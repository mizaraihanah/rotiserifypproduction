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
$available_products = [];

// Fetch available products from inventory system
try {
    $inventory_query = "SELECT product_id, product_name, stock_quantity, unit_price, 
                        CASE 
                            WHEN stock_quantity <= 0 THEN 'Out of Stock'
                            WHEN stock_quantity <= reorder_threshold THEN 'Low Stock'
                            ELSE 'Available'
                        END as stock_status
                        FROM roti_seri_bakery_inventory.products 
                        ORDER BY product_name";
    
    $stmt = $conn->prepare($inventory_query);
    $stmt->execute();
    $available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    $error_message = "Error fetching inventory: " . $e->getMessage();
}

// Handle form submission
if ($_SERVER["REQUEST_METHOD"] == "POST") {
    try {
        // Validate batch size
        $recipe_batchSize = floatval($_POST['batch_size']);
        if ($recipe_batchSize < 0.01) {
            throw new Exception("Batch size cannot be less than 0.01");
        }

        // Validate ingredient quantities
        if (isset($_POST['ingredient_quantity']) && is_array($_POST['ingredient_quantity'])) {
            foreach ($_POST['ingredient_quantity'] as $quantity) {
                if (floatval($quantity) < 0.01) {
                    throw new Exception("Ingredient quantity cannot be less than 0.01");
                }
            }
        }

        // Validate that at least one ingredient is selected
        if (!isset($_POST['selected_products']) || empty($_POST['selected_products'])) {
            throw new Exception("Please select at least one ingredient");
        }

        $conn->beginTransaction();

        // Get recipe details
        $recipe_name = htmlspecialchars(trim($_POST['recipe_name']));
        $recipe_category = htmlspecialchars(trim($_POST['recipe_category']));
        $recipe_unitOfMeasure = htmlspecialchars(trim($_POST['unit_of_measure']));
        $recipe_instructions = htmlspecialchars(trim($_POST['recipe_instructions']));

        // Handle image upload
        $image_path = null;
        if (isset($_FILES['recipe_image']) && $_FILES['recipe_image']['error'] == 0) {
            $target_dir = "images/recipes/";
            if (!is_dir($target_dir)) mkdir($target_dir, 0777, true);
            $target_file = $target_dir . uniqid() . "_" . basename($_FILES['recipe_image']['name']);
            if (move_uploaded_file($_FILES['recipe_image']['tmp_name'], $target_file)) {
                $image_path = $target_file;
            } else {
                throw new Exception("Failed to upload the recipe image.");
            }
        }

        // Insert recipe
        $stmt = $conn->prepare("INSERT INTO tbl_recipe (recipe_name, recipe_category, recipe_batchSize, recipe_unitOfMeasure, recipe_instructions, image_path) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->execute([$recipe_name, $recipe_category, $recipe_batchSize, $recipe_unitOfMeasure, $recipe_instructions, $image_path]);

        $recipe_id = $conn->lastInsertId();

        // Insert ingredients based on selected products
        $stmt = $conn->prepare("INSERT INTO tbl_ingredients (recipe_id, ingredient_name, ingredient_quantity, ingredient_unitOfMeasure, product_id) VALUES (?, ?, ?, ?, ?)");

        foreach ($_POST['selected_products'] as $product_id) {
            if (!empty($product_id) && isset($_POST['quantity_' . $product_id]) && !empty($_POST['quantity_' . $product_id])) {
                // Get product name from available products
                $product_name = '';
                foreach ($available_products as $product) {
                    if ($product['product_id'] === $product_id) {
                        $product_name = $product['product_name'];
                        break;
                    }
                }
                
                $quantity = floatval($_POST['quantity_' . $product_id]);
                $unit = trim($_POST['unit_' . $product_id]);
                
                $stmt->execute([
                    $recipe_id,
                    $product_name,
                    $quantity,
                    $unit,
                    $product_id
                ]);
            }
        }

        $conn->commit();
        $success_message = "Recipe added successfully with inventory links!";
        
        // Clear form data
        $_POST = [];
        
    } catch(Exception $e) {
        $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Add Recipe - Roti Seri Production</title>
    <link rel="stylesheet" href="css/style.css">
    <link rel="stylesheet" href="css/dashboard.css">
    <link rel="stylesheet" href="css/add_recipe.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        .ingredients-section {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .ingredient-selector {
            display: grid;
            grid-template-columns: 1fr auto auto auto;
            gap: 15px;
            align-items: end;
            margin-bottom: 15px;
            padding: 15px;
            background: white;
            border-radius: 5px;
            border: 1px solid #ddd;
        }
        
        .product-dropdown {
            width: 100%;
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 14px;
        }
        
        .quantity-input, .unit-select {
            padding: 10px;
            border: 1px solid #ddd;
            border-radius: 4px;
            width: 120px;
        }
        
        .add-ingredient-btn {
            padding: 10px 15px;
            background: #28a745;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 14px;
        }
        
        .add-ingredient-btn:hover {
            background: #218838;
        }
        
        .remove-ingredient-btn {
            padding: 8px 12px;
            background: #dc3545;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }
        
        .remove-ingredient-btn:hover {
            background: #c82333;
        }
        
        .stock-info {
            font-size: 12px;
            margin-top: 5px;
            padding: 5px 8px;
            border-radius: 3px;
        }
        
        .stock-available { background-color: #d4edda; color: #155724; }
        .stock-low { background-color: #fff3cd; color: #856404; }
        .stock-out { background-color: #f8d7da; color: #721c24; }
        
        .selected-ingredients {
            margin-top: 20px;
        }
        
        .selected-ingredient {
            display: grid;
            grid-template-columns: 2fr 1fr 1fr auto;
            gap: 15px;
            align-items: center;
            padding: 15px;
            background: white;
            border: 1px solid #ddd;
            border-radius: 5px;
            margin-bottom: 10px;
        }
        
        .ingredient-info {
            font-weight: 500;
        }
        
        .ingredient-name {
            font-size: 16px;
            color: #333;
        }
        
        .product-id {
            font-size: 12px;
            color: #666;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 5px;
            display: block;
        }
    </style>
</head>
<body>
    <?php include 'includes/dashboard_navigation.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <h1>Add New Recipe</h1>
            <div class="divider"></div>
        </div>

        <?php if ($success_message): ?>
            <div class="alert success"><?php echo $success_message; ?></div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert error"><?php echo $error_message; ?></div>
        <?php endif; ?>

        <form method="POST" class="recipe-form" enctype="multipart/form-data">
            <div class="form-section">
                <h2>Recipe Details</h2>
                <div class="form-group">
                    <label for="recipe_name">Recipe Name</label>
                    <input type="text" id="recipe_name" name="recipe_name" required>
                </div>

                <div class="form-group">
                    <label for="recipe_image">Recipe Image</label>
                    <input type="file" name="recipe_image" accept="image/*">
                    <div class="image-preview" id="imagePreview">
                        <img src="" alt="Recipe Image Preview" class="image-preview__image" style="display: none; width: 400px; height: 300px; object-fit: cover;">
                    </div>
                </div>

                <div class="form-group">
                    <label for="recipe_category">Category</label>
                    <select id="recipe_category" name="recipe_category" required>
                        <option value="">Select Category</option>
                        <option value="Bread">Bread</option>
                        <option value="Cake">Cake</option>
                        <option value="Pastry">Pastry</option>
                        <option value="Cookie">Cookie</option>
                    </select>
                </div>

                <div class="form-row">
                    <div class="form-group">
                        <label for="batch_size">Batch Size</label>
                        <input type="number" 
                               id="batch_size" 
                               name="batch_size" 
                               step="0.01" 
                               min="0.01" 
                               required>
                    </div>
                    <div class="form-group">
                        <label for="unit_of_measure">Unit of Measure</label>
                        <select id="unit_of_measure" name="unit_of_measure" required>
                            <option value="">Select Unit</option>
                            <option value="pcs">Pieces</option>
                            <option value="kg">Kilograms</option>
                            <option value="g">Grams</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Recipe Instructions Section -->
            <div class="form-section">
                <h2>Recipe Instructions</h2>
                <div class="form-group">
                    <label for="recipe_instructions">Instructions (Enter each step on a new line)</label>
                    <textarea 
                        id="recipe_instructions" 
                        name="recipe_instructions" 
                        rows="10" 
                        placeholder="1. First step
2. Second step
3. Third step
4. Fourth step..."
                        required
                    ></textarea>
                    <small class="form-text">Enter each instruction step on a new line, preferably numbered.</small>
                </div>
            </div>

            <!-- Ingredients Section -->
            <div class="form-section">
                <h2>Ingredients <small>(Select from Inventory)</small></h2>
                
                <div class="ingredients-section">
                    <h3>Add Ingredients</h3>
                    
                    <div class="ingredient-selector">
                        <div>
                            <label class="form-label">Select Product</label>
                            <select id="product-selector" class="product-dropdown">
                                <option value="">Choose an ingredient from inventory</option>
                                <?php foreach ($available_products as $product): ?>
                                    <option value="<?php echo $product['product_id']; ?>" 
                                            data-name="<?php echo htmlspecialchars($product['product_name']); ?>"
                                            data-stock="<?php echo $product['stock_quantity']; ?>"
                                            data-status="<?php echo $product['stock_status']; ?>"
                                            data-price="<?php echo $product['unit_price']; ?>">
                                        <?php echo htmlspecialchars($product['product_name']); ?> 
                                        (Stock: <?php echo $product['stock_quantity']; ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="stock-info" class="stock-info" style="display: none;"></div>
                        </div>
                        
                        <div>
                            <label class="form-label">Quantity</label>
                            <input type="number" id="quantity-input" class="quantity-input" 
                                   step="0.01" min="0.01" placeholder="0.00">
                        </div>
                        
                        <div>
                            <label class="form-label">Unit</label>
                            <select id="unit-selector" class="unit-select">
                                <option value="">Unit</option>
                                <option value="kg">Kilograms</option>
                                <option value="g">Grams</option>
                                <option value="l">Liters</option>
                                <option value="ml">Milliliters</option>
                                <option value="pcs">Pieces</option>
                                <option value="cups">Cups</option>
                                <option value="tbsp">Tablespoons</option>
                                <option value="tsp">Teaspoons</option>
                            </select>
                        </div>
                        
                        <div>
                            <label class="form-label">&nbsp;</label>
                            <button type="button" class="add-ingredient-btn" onclick="addSelectedIngredient()">
                                <i class="fas fa-plus"></i> Add
                            </button>
                        </div>
                    </div>
                    
                    <div class="selected-ingredients" id="selected-ingredients">
                        <h4>Selected Ingredients:</h4>
                        <div id="ingredients-list">
                            <p style="color: #666; font-style: italic;">No ingredients selected yet. Choose products from the dropdown above.</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="submit-btn">Save Recipe</button>
                <a href="view_recipes.php" class="cancel-btn">Cancel</a>
            </div>
        </form>
    </main>

    <script>
        // Available products data for JavaScript
        const availableProducts = <?php echo json_encode($available_products); ?>;
        let selectedIngredients = [];
        
        // Update stock info when product is selected
        document.getElementById('product-selector').addEventListener('change', function() {
            const selectedOption = this.options[this.selectedIndex];
            const stockInfo = document.getElementById('stock-info');
            
            if (selectedOption.value) {
                const stock = selectedOption.dataset.stock;
                const status = selectedOption.dataset.status;
                
                stockInfo.style.display = 'block';
                stockInfo.className = 'stock-info';
                
                if (status === 'Available') {
                    stockInfo.classList.add('stock-available');
                    stockInfo.innerHTML = `✓ Available: ${stock} units in stock`;
                } else if (status === 'Low Stock') {
                    stockInfo.classList.add('stock-low');
                    stockInfo.innerHTML = `⚠ Low stock: ${stock} units remaining`;
                } else {
                    stockInfo.classList.add('stock-out');
                    stockInfo.innerHTML = `✗ Out of stock (${stock} units)`;
                }
            } else {
                stockInfo.style.display = 'none';
            }
        });
        
        function addSelectedIngredient() {
            const productSelector = document.getElementById('product-selector');
            const quantityInput = document.getElementById('quantity-input');
            const unitSelector = document.getElementById('unit-selector');
            
            const selectedOption = productSelector.options[productSelector.selectedIndex];
            
            if (!selectedOption.value) {
                alert('Please select a product');
                return;
            }
            
            if (!quantityInput.value || parseFloat(quantityInput.value) <= 0) {
                alert('Please enter a valid quantity');
                return;
            }
            
            if (!unitSelector.value) {
                alert('Please select a unit');
                return;
            }
            
            const productId = selectedOption.value;
            const productName = selectedOption.dataset.name;
            const quantity = parseFloat(quantityInput.value);
            const unit = unitSelector.value;
            const stock = selectedOption.dataset.stock;
            const status = selectedOption.dataset.status;
            
            // Check if ingredient already exists
            if (selectedIngredients.find(ing => ing.productId === productId)) {
                alert('This ingredient is already added to the recipe');
                return;
            }
            
            // Add to selected ingredients
            selectedIngredients.push({
                productId: productId,
                productName: productName,
                quantity: quantity,
                unit: unit,
                stock: stock,
                status: status
            });
            
            updateIngredientsDisplay();
            
            // Reset form
            productSelector.selectedIndex = 0;
            quantityInput.value = '';
            unitSelector.selectedIndex = 0;
            document.getElementById('stock-info').style.display = 'none';
        }
        
        function removeIngredient(productId) {
            selectedIngredients = selectedIngredients.filter(ing => ing.productId !== productId);
            updateIngredientsDisplay();
        }
        
        function updateIngredientsDisplay() {
            const ingredientsList = document.getElementById('ingredients-list');
            
            if (selectedIngredients.length === 0) {
                ingredientsList.innerHTML = '<p style="color: #666; font-style: italic;">No ingredients selected yet. Choose products from the dropdown above.</p>';
                return;
            }
            
            let html = '';
            selectedIngredients.forEach(ingredient => {
                const statusClass = ingredient.status === 'Available' ? 'stock-available' : 
                                  ingredient.status === 'Low Stock' ? 'stock-low' : 'stock-out';
                
                html += `
                    <div class="selected-ingredient">
                        <div class="ingredient-info">
                            <div class="ingredient-name">${ingredient.productName}</div>
                            <div class="product-id">ID: ${ingredient.productId}</div>
                            <div class="stock-info ${statusClass}">
                                Stock: ${ingredient.stock} units (${ingredient.status})
                            </div>
                        </div>
                        <div><strong>${ingredient.quantity}</strong></div>
                        <div><strong>${ingredient.unit}</strong></div>
                        <div>
                            <button type="button" class="remove-ingredient-btn" onclick="removeIngredient('${ingredient.productId}')">
                                <i class="fas fa-trash"></i> Remove
                            </button>
                        </div>
                        
                        <!-- Hidden inputs for form submission -->
                        <input type="hidden" name="selected_products[]" value="${ingredient.productId}">
                        <input type="hidden" name="quantity_${ingredient.productId}" value="${ingredient.quantity}">
                        <input type="hidden" name="unit_${ingredient.productId}" value="${ingredient.unit}">
                    </div>
                `;
            });
            
            ingredientsList.innerHTML = html;
        }
        
        // Image preview
        document.querySelector('input[type="file"]').addEventListener('change', function(e) {
            const file = e.target.files[0];
            const preview = document.querySelector('.image-preview__image');
            const reader = new FileReader();

            reader.onload = function(e) {
                preview.src = e.target.result;
                preview.style.display = 'block';
            }

            if (file) {
                reader.readAsDataURL(file);
            }
        });
        
        // Form validation
        document.querySelector('.recipe-form').addEventListener('submit', function(e) {
            if (selectedIngredients.length === 0) {
                e.preventDefault();
                alert('Please add at least one ingredient to the recipe');
                return false;
            }
        });
    </script>

    <script src="js/dashboard.js"></script>
</body>
</html>