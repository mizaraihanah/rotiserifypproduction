-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jun 15, 2025 at 06:56 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `production_db`
--

DELIMITER $$
--
-- Procedures
--
CREATE DEFINER=`root`@`localhost` PROCEDURE `deduct_inventory_for_production` (IN `schedule_id_param` INT, OUT `success` BOOLEAN, OUT `error_message` TEXT)   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE ingredient_product_id VARCHAR(10);
    DECLARE recipe_quantity DECIMAL(10,4);
    DECLARE recipe_unit VARCHAR(20);
    DECLARE conversion_factor DECIMAL(10,4);
    DECLARE available_qty INT;
    DECLARE batch_count INT;
    DECLARE inventory_units_needed DECIMAL(10,4);
    DECLARE affected_rows INT DEFAULT 0;
    
    DECLARE ingredient_cursor CURSOR FOR
        SELECT 
            i.product_id,
            i.ingredient_quantity,
            i.ingredient_unitOfMeasure,
            COALESCE(pc.conversion_factor, 1.0) as conversion_factor,
            COALESCE(p.stock_quantity, 0) as stock_quantity,
            s.schedule_batchNum
        FROM tbl_schedule s
        JOIN tbl_ingredients i ON s.recipe_id = i.recipe_id
        LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
        LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
        WHERE s.schedule_id = schedule_id_param
        AND i.product_id IS NOT NULL;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        SET success = FALSE;
        SET error_message = 'Database error occurred during inventory deduction';
    END;
    
    SET success = TRUE;
    SET error_message = '';
    
    -- Check if all ingredients are available first
    OPEN ingredient_cursor;
    check_loop: LOOP
        FETCH ingredient_cursor INTO ingredient_product_id, recipe_quantity, recipe_unit, conversion_factor, available_qty, batch_count;
        IF done THEN
            LEAVE check_loop;
        END IF;
        
        -- Calculate inventory units needed: (recipe_amount * batches) / conversion_factor
        SET inventory_units_needed = (recipe_quantity * batch_count) / conversion_factor;
        
        IF available_qty < inventory_units_needed THEN
            SET success = FALSE;
            SET error_message = CONCAT(
                'Insufficient inventory for product: ', ingredient_product_id, 
                '. Recipe needs: ', recipe_quantity, ' ', recipe_unit, ' × ', batch_count, ' batches = ', (recipe_quantity * batch_count), ' ', recipe_unit,
                '. Conversion: ', conversion_factor, ' ', recipe_unit, ' per unit',
                '. Inventory units needed: ', inventory_units_needed,
                '. Available: ', available_qty, ' units'
            );
            CLOSE ingredient_cursor;
            LEAVE check_loop;
        END IF;
    END LOOP;
    CLOSE ingredient_cursor;
    
    -- If all checks passed, deduct the inventory
    IF success THEN
        SET done = FALSE;
        OPEN ingredient_cursor;
        deduct_loop: LOOP
            FETCH ingredient_cursor INTO ingredient_product_id, recipe_quantity, recipe_unit, conversion_factor, available_qty, batch_count;
            IF done THEN
                LEAVE deduct_loop;
            END IF;
            
            SET inventory_units_needed = (recipe_quantity * batch_count) / conversion_factor;
            
            -- Update inventory in the inventory database
            UPDATE roti_seri_bakery_inventory.products 
            SET stock_quantity = stock_quantity - inventory_units_needed,
                last_updated = NOW()
            WHERE product_id = ingredient_product_id;
            
            SET affected_rows = ROW_COUNT();
            
            -- Check if update was successful
            IF affected_rows = 0 THEN
                SET success = FALSE;
                SET error_message = CONCAT('Failed to update inventory for product: ', ingredient_product_id);
                LEAVE deduct_loop;
            END IF;
            
            -- Log the inventory deduction with detailed conversion info
            INSERT INTO roti_seri_bakery_inventory.inventory_logs 
            (user_id, action, item_id, action_details, ip_address)
            VALUES 
            ('production_system', 'stock_decrease', ingredient_product_id, 
             CONCAT('Production deduction: Schedule ID ', schedule_id_param,
                   ' - Recipe: ', recipe_quantity, ' ', recipe_unit, ' × ', batch_count, ' batches',
                   ' - Conversion: ', conversion_factor, ' ', recipe_unit, '/unit',
                   ' - Inventory units deducted: ', inventory_units_needed), 
             '127.0.0.1');
             
        END LOOP;
        CLOSE ingredient_cursor;
    END IF;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `restore_inventory_for_production` (IN `schedule_id_param` INT, OUT `success` BOOLEAN, OUT `error_message` TEXT)   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE ingredient_product_id VARCHAR(10);
    DECLARE recipe_quantity DECIMAL(10,4);
    DECLARE recipe_unit VARCHAR(20);
    DECLARE conversion_factor DECIMAL(10,4);
    DECLARE batch_count INT;
    DECLARE inventory_units_needed DECIMAL(10,4);
    DECLARE affected_rows INT DEFAULT 0;
    
    DECLARE ingredient_cursor CURSOR FOR
        SELECT 
            i.product_id,
            i.ingredient_quantity,
            i.ingredient_unitOfMeasure,
            COALESCE(pc.conversion_factor, 1.0) as conversion_factor,
            s.schedule_batchNum
        FROM tbl_schedule s
        JOIN tbl_ingredients i ON s.recipe_id = i.recipe_id
        LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
        WHERE s.schedule_id = schedule_id_param
        AND i.product_id IS NOT NULL;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    DECLARE CONTINUE HANDLER FOR SQLEXCEPTION
    BEGIN
        SET success = FALSE;
        SET error_message = 'Database error occurred during inventory restoration';
    END;
    
    SET success = TRUE;
    SET error_message = '';
    
    OPEN ingredient_cursor;
    restore_loop: LOOP
        FETCH ingredient_cursor INTO ingredient_product_id, recipe_quantity, recipe_unit, conversion_factor, batch_count;
        IF done THEN
            LEAVE restore_loop;
        END IF;
        
        SET inventory_units_needed = (recipe_quantity * batch_count) / conversion_factor;
        
        -- Restore inventory
        UPDATE roti_seri_bakery_inventory.products 
        SET stock_quantity = stock_quantity + inventory_units_needed,
            last_updated = NOW()
        WHERE product_id = ingredient_product_id;
        
        SET affected_rows = ROW_COUNT();
        
        -- Check if update was successful
        IF affected_rows = 0 THEN
            SET success = FALSE;
            SET error_message = CONCAT('Failed to restore inventory for product: ', ingredient_product_id);
            LEAVE restore_loop;
        END IF;
        
        -- Log the inventory restoration
        INSERT INTO roti_seri_bakery_inventory.inventory_logs 
        (user_id, action, item_id, action_details, ip_address)
        VALUES 
        ('production_system', 'stock_increase', ingredient_product_id, 
         CONCAT('Production cancellation restore: Schedule ID ', schedule_id_param, ' - Quantity: ', inventory_units_needed), 
         '127.0.0.1');
         
    END LOOP;
    CLOSE ingredient_cursor;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `setup_default_conversions` ()   BEGIN
    -- This procedure sets up common conversion factors
    -- You should customize these based on your actual products
    
    DECLARE done INT DEFAULT FALSE;
    DECLARE prod_id VARCHAR(10);
    DECLARE prod_name VARCHAR(100);
    
    DECLARE product_cursor CURSOR FOR
        SELECT product_id, product_name 
        FROM roti_seri_bakery_inventory.products;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN product_cursor;
    setup_loop: LOOP
        FETCH product_cursor INTO prod_id, prod_name;
        IF done THEN
            LEAVE setup_loop;
        END IF;
        
        -- Set up default conversions based on product name patterns
        IF LOWER(prod_name) LIKE '%flour%' THEN
            -- Flour conversions (assuming 1 unit = 1kg)
            INSERT IGNORE INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES
            (prod_id, 'kg', 1.0, 'Default: 1 unit = 1 kg'),
            (prod_id, 'g', 1000.0, 'Default: 1 unit = 1000 g'),
            (prod_id, 'cups', 8.0, 'Default: 1 unit = 8 cups (approx)');
            
        ELSEIF LOWER(prod_name) LIKE '%sugar%' THEN
            -- Sugar conversions (assuming 1 unit = 1kg)
            INSERT IGNORE INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES
            (prod_id, 'kg', 1.0, 'Default: 1 unit = 1 kg'),
            (prod_id, 'g', 1000.0, 'Default: 1 unit = 1000 g'),
            (prod_id, 'cups', 5.0, 'Default: 1 unit = 5 cups (approx)');
            
        ELSEIF LOWER(prod_name) LIKE '%butter%' OR LOWER(prod_name) LIKE '%oil%' THEN
            -- Fat conversions (assuming 1 unit = 500g)
            INSERT IGNORE INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES
            (prod_id, 'kg', 0.5, 'Default: 1 unit = 0.5 kg'),
            (prod_id, 'g', 500.0, 'Default: 1 unit = 500 g'),
            (prod_id, 'ml', 500.0, 'Default: 1 unit = 500 ml'),
            (prod_id, 'cups', 2.0, 'Default: 1 unit = 2 cups (approx)');
            
        ELSE
            -- Generic conversions (assuming 1 unit = 1kg)
            INSERT IGNORE INTO tbl_product_conversions (product_id, recipe_unit, conversion_factor, notes) VALUES
            (prod_id, 'kg', 1.0, 'Default: 1 unit = 1 kg'),
            (prod_id, 'g', 1000.0, 'Default: 1 unit = 1000 g'),
            (prod_id, 'pcs', 1.0, 'Default: 1 unit = 1 piece');
        END IF;
        
    END LOOP;
    CLOSE product_cursor;
END$$

CREATE DEFINER=`root`@`localhost` PROCEDURE `update_ingredient_conversions` ()   BEGIN
    DECLARE done INT DEFAULT FALSE;
    DECLARE ing_id INT;
    DECLARE prod_id VARCHAR(10);
    DECLARE recipe_qty DECIMAL(10,2);
    DECLARE recipe_unit VARCHAR(20);
    DECLARE inventory_units DECIMAL(10,4);
    DECLARE conversion_factor DECIMAL(10,4);
    
    DECLARE ingredient_cursor CURSOR FOR
        SELECT ingredient_id, product_id, ingredient_quantity, ingredient_unitOfMeasure
        FROM tbl_ingredients 
        WHERE product_id IS NOT NULL;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    OPEN ingredient_cursor;
    update_loop: LOOP
        FETCH ingredient_cursor INTO ing_id, prod_id, recipe_qty, recipe_unit;
        IF done THEN
            LEAVE update_loop;
        END IF;
        
        -- Calculate inventory units needed
        SET inventory_units = calculate_inventory_units(prod_id, recipe_qty, recipe_unit);
        
        -- Get the conversion factor used
        SELECT pc.conversion_factor INTO conversion_factor
        FROM tbl_product_conversions pc
        WHERE pc.product_id = prod_id AND pc.recipe_unit = recipe_unit
        LIMIT 1;
        
        -- Update the ingredient with calculated values
        UPDATE tbl_ingredients 
        SET inventory_units_required = inventory_units,
            conversion_factor = conversion_factor,
            conversion_notes = CONCAT('Recipe: ', recipe_qty, ' ', recipe_unit, ' = Inventory: ', inventory_units, ' units')
        WHERE ingredient_id = ing_id;
        
    END LOOP;
    CLOSE ingredient_cursor;
END$$

--
-- Functions
--
CREATE DEFINER=`root`@`localhost` FUNCTION `calculate_inventory_units` (`product_id_param` VARCHAR(10), `recipe_quantity` DECIMAL(10,2), `recipe_unit` VARCHAR(20)) RETURNS DECIMAL(10,4) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE conversion_factor DECIMAL(10,4) DEFAULT NULL;
    DECLARE inventory_units DECIMAL(10,4) DEFAULT 0;
    
    -- Get conversion factor for this product and unit combination
    SELECT pc.conversion_factor INTO conversion_factor
    FROM tbl_product_conversions pc
    WHERE pc.product_id = product_id_param 
    AND pc.recipe_unit = recipe_unit
    LIMIT 1;
    
    -- If conversion factor found, calculate inventory units needed
    IF conversion_factor IS NOT NULL THEN
        SET inventory_units = recipe_quantity / conversion_factor;
    ELSE
        -- If no conversion found, assume 1:1 ratio (could also return NULL or 0)
        SET inventory_units = recipe_quantity;
    END IF;
    
    RETURN inventory_units;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `can_produce_recipe` (`recipe_id_param` INT, `batches_needed` INT) RETURNS TINYINT(1) DETERMINISTIC READS SQL DATA BEGIN
    DECLARE insufficient_count INT DEFAULT 0;
    DECLARE total_ingredients INT DEFAULT 0;
    
    -- Count total ingredients for the recipe that are linked
    SELECT COUNT(*) INTO total_ingredients
    FROM tbl_ingredients 
    WHERE recipe_id = recipe_id_param 
    AND product_id IS NOT NULL;
    
    -- If no linked ingredients found, cannot produce
    IF total_ingredients = 0 THEN
        RETURN FALSE;
    END IF;
    
    -- Count ingredients that cannot meet the production requirement
    SELECT COUNT(*) INTO insufficient_count
    FROM tbl_ingredients i
    LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
    LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
    WHERE i.recipe_id = recipe_id_param 
    AND i.product_id IS NOT NULL
    AND (
        p.product_id IS NULL OR  -- Product not found
        pc.conversion_factor IS NULL OR  -- No conversion factor
        p.stock_quantity <= 0 OR  -- Out of stock
        p.stock_quantity < ((i.ingredient_quantity * batches_needed) / COALESCE(pc.conversion_factor, 1))  -- Insufficient stock
    );
    
    RETURN insufficient_count = 0;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `test_inventory_integration` () RETURNS TEXT CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
    DECLARE result TEXT DEFAULT '';
    DECLARE recipe_count INT DEFAULT 0;
    DECLARE linked_ingredients INT DEFAULT 0;
    DECLARE total_ingredients INT DEFAULT 0;
    DECLARE products_accessible INT DEFAULT 0;
    
    -- Test 1: Check if we can access inventory database
    SELECT COUNT(*) INTO products_accessible 
    FROM roti_seri_bakery_inventory.products;
    
    -- Test 2: Count recipes
    SELECT COUNT(*) INTO recipe_count FROM tbl_recipe;
    
    -- Test 3: Count ingredients
    SELECT COUNT(*) INTO total_ingredients FROM tbl_ingredients;
    
    -- Test 4: Count linked ingredients
    SELECT COUNT(*) INTO linked_ingredients 
    FROM tbl_ingredients 
    WHERE product_id IS NOT NULL;
    
    SET result = CONCAT(
        'Integration Test Results:\n',
        '- Inventory database accessible: ', IF(products_accessible > 0, 'YES', 'NO'), ' (', products_accessible, ' products found)\n',
        '- Total recipes: ', recipe_count, '\n',
        '- Total ingredients: ', total_ingredients, '\n',
        '- Linked ingredients: ', linked_ingredients, '\n',
        '- Integration readiness: ', 
        CASE 
            WHEN products_accessible = 0 THEN 'FAILED - Cannot access inventory database'
            WHEN total_ingredients = 0 THEN 'INCOMPLETE - No ingredients found'
            WHEN linked_ingredients = 0 THEN 'READY FOR SETUP - Need to link ingredients'
            WHEN linked_ingredients < total_ingredients THEN 'PARTIAL - Some ingredients still need linking'
            ELSE 'COMPLETE - All ingredients linked'
        END
    );
    
    RETURN result;
END$$

CREATE DEFINER=`root`@`localhost` FUNCTION `test_recipe_calculation` (`recipe_id_param` INT, `batches_needed` INT) RETURNS TEXT CHARSET utf8mb4 COLLATE utf8mb4_general_ci DETERMINISTIC READS SQL DATA BEGIN
    DECLARE result_text TEXT DEFAULT '';
    DECLARE ingredient_name VARCHAR(200);
    DECLARE recipe_qty DECIMAL(10,4);
    DECLARE recipe_unit VARCHAR(20);
    DECLARE conversion_factor DECIMAL(10,4);
    DECLARE available_stock INT;
    DECLARE inventory_needed DECIMAL(10,4);
    DECLARE can_produce_flag BOOLEAN DEFAULT TRUE;
    DECLARE done INT DEFAULT FALSE;
    
    DECLARE calc_cursor CURSOR FOR
        SELECT 
            i.ingredient_name,
            i.ingredient_quantity,
            i.ingredient_unitOfMeasure,
            COALESCE(pc.conversion_factor, 1.0) as conversion_factor,
            COALESCE(p.stock_quantity, 0) as available_stock
        FROM tbl_ingredients i
        LEFT JOIN roti_seri_bakery_inventory.products p ON i.product_id = p.product_id
        LEFT JOIN tbl_product_conversions pc ON i.product_id = pc.product_id AND i.ingredient_unitOfMeasure = pc.recipe_unit
        WHERE i.recipe_id = recipe_id_param
        AND i.product_id IS NOT NULL;
        
    DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;
    
    SET result_text = CONCAT('Calculation Test for Recipe ID ', recipe_id_param, ' (', batches_needed, ' batches):\n\n');
    
    OPEN calc_cursor;
    calc_loop: LOOP
        FETCH calc_cursor INTO ingredient_name, recipe_qty, recipe_unit, conversion_factor, available_stock;
        IF done THEN
            LEAVE calc_loop;
        END IF;
        
        SET inventory_needed = (recipe_qty * batches_needed) / conversion_factor;
        
        SET result_text = CONCAT(result_text,
            ingredient_name, ':\n',
            '  Recipe needs: ', recipe_qty, ' ', recipe_unit, ' per batch\n',
            '  Total needed: ', (recipe_qty * batches_needed), ' ', recipe_unit, ' for ', batches_needed, ' batches\n',
            '  Conversion: 1 unit = ', conversion_factor, ' ', recipe_unit, '\n',
            '  Inventory needed: ', inventory_needed, ' units\n',
            '  Available: ', available_stock, ' units\n',
            '  Status: ', IF(available_stock >= inventory_needed, 'SUFFICIENT', 'INSUFFICIENT'), '\n\n'
        );
        
        IF available_stock < inventory_needed THEN
            SET can_produce_flag = FALSE;
        END IF;
        
    END LOOP;
    CLOSE calc_cursor;
    
    SET result_text = CONCAT(result_text, 'OVERALL RESULT: ', IF(can_produce_flag, 'CAN PRODUCE', 'CANNOT PRODUCE'));
    
    RETURN result_text;
END$$

DELIMITER ;

-- --------------------------------------------------------

--
-- Stand-in structure for view `conversion_setup_status`
-- (See below for the actual view)
--
CREATE TABLE `conversion_setup_status` (
`product_id` varchar(10)
,`product_name` varchar(100)
,`stock_quantity` int(11)
,`units_configured` bigint(21)
,`configured_units` mediumtext
,`units_used_in_recipes` bigint(21)
,`recipe_units` mediumtext
,`conversion_status` varchar(14)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `ingredient_stock_status`
-- (See below for the actual view)
--
CREATE TABLE `ingredient_stock_status` (
`ingredient_id` int(6)
,`recipe_id` int(6)
,`recipe_name` varchar(100)
,`ingredient_name` varchar(200)
,`ingredient_quantity` int(6)
,`ingredient_unitOfMeasure` varchar(50)
,`product_id` varchar(10)
,`product_name` varchar(100)
,`available_stock` int(11)
,`reorder_threshold` int(11)
,`stock_status` varchar(22)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `production_feasibility`
-- (See below for the actual view)
--
CREATE TABLE `production_feasibility` (
`recipe_id` int(6)
,`recipe_name` varchar(100)
,`recipe_batchSize` int(6)
,`max_possible_batches` bigint(12)
,`total_ingredients` bigint(21)
,`ingredients_short` decimal(22,0)
,`ingredients_not_linked` decimal(22,0)
,`production_status` varchar(22)
);

-- --------------------------------------------------------

--
-- Stand-in structure for view `recipe_inventory_status`
-- (See below for the actual view)
--
CREATE TABLE `recipe_inventory_status` (
`recipe_id` int(6)
,`recipe_name` varchar(100)
,`recipe_batchSize` int(6)
,`ingredient_id` int(6)
,`ingredient_name` varchar(200)
,`recipe_quantity` int(6)
,`recipe_unit` varchar(50)
,`inventory_units_required` decimal(10,4)
,`conversion_factor` decimal(10,4)
,`product_id` varchar(10)
,`product_name` varchar(100)
,`available_inventory_units` int(11)
,`unit_price` decimal(10,2)
,`ingredient_status` varchar(17)
,`possible_batches_for_ingredient` bigint(16)
);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_batches`
--

CREATE TABLE `tbl_batches` (
  `batch_id` int(6) NOT NULL,
  `recipe_id` int(6) NOT NULL,
  `schedule_id` int(6) NOT NULL,
  `batch_startTime` datetime NOT NULL DEFAULT current_timestamp(),
  `batch_endTime` datetime NOT NULL,
  `batch_status` enum('Pending','In Progress','Completed','') NOT NULL DEFAULT 'Pending',
  `batch_remarks` text NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_batches`
--

INSERT INTO `tbl_batches` (`batch_id`, `recipe_id`, `schedule_id`, `batch_startTime`, `batch_endTime`, `batch_status`, `batch_remarks`) VALUES
(4, 4, 8, '2025-01-13 16:43:00', '2025-01-13 19:43:00', 'Completed', ''),
(5, 4, 8, '2025-01-14 17:11:00', '2025-01-14 19:11:00', 'Completed', ''),
(6, 4, 8, '2025-01-15 19:13:00', '2025-01-15 20:13:00', 'Completed', ''),
(8, 4, 8, '2025-01-13 09:08:00', '2025-01-13 09:08:00', 'Completed', ''),
(9, 5, 9, '2025-04-21 22:14:00', '2025-04-22 22:14:00', 'Pending', 'blackout'),
(10, 15, 12, '2025-05-17 19:43:00', '2025-05-21 19:43:00', 'Pending', ''),
(11, 2, 7, '2025-05-17 22:40:00', '2025-05-20 22:40:00', 'Pending', ''),
(12, 5, 9, '2025-05-23 22:42:00', '2025-05-24 22:42:00', 'Completed', ''),
(13, 17, 13, '2025-06-16 23:49:00', '2025-06-17 23:50:00', 'Pending', 'aaaaaaaa'),
(14, 17, 14, '2025-06-16 00:26:00', '2025-06-17 00:26:00', 'Pending', ''),
(15, 17, 15, '2025-06-16 00:30:00', '2025-06-17 00:30:00', 'Pending', '');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_batch_assignments`
--

CREATE TABLE `tbl_batch_assignments` (
  `ba_id` int(6) NOT NULL,
  `batch_id` int(6) NOT NULL,
  `user_id` int(6) NOT NULL,
  `ba_task` varchar(255) NOT NULL,
  `ba_status` enum('Pending','In Progress','Completed','') NOT NULL DEFAULT 'Pending'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_batch_assignments`
--

INSERT INTO `tbl_batch_assignments` (`ba_id`, `batch_id`, `user_id`, `ba_task`, `ba_status`) VALUES
(15, 6, 17, 'Mixing', 'Pending'),
(16, 5, 15, 'Mixing', 'Pending'),
(17, 5, 16, 'Baking', 'Pending'),
(18, 5, 20, 'Decorating', 'Pending'),
(21, 4, 15, 'Mixing', 'Pending'),
(22, 4, 18, 'Baking', 'Pending'),
(26, 8, 18, 'Mixing', 'Pending'),
(33, 9, 15, 'Mixing', 'Pending'),
(34, 9, 16, 'Baking', 'Pending'),
(35, 10, 23, 'Mixing', 'Pending'),
(36, 10, 18, 'Baking', 'Pending'),
(37, 11, 20, 'Decorating', 'Pending'),
(40, 12, 21, 'Decorating', 'Pending'),
(41, 13, 15, 'Baking', 'Pending'),
(42, 14, 18, 'Baking', 'Pending'),
(43, 15, 17, 'Baking', 'Pending');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_equipments`
--

CREATE TABLE `tbl_equipments` (
  `equipment_id` int(6) NOT NULL,
  `equipment_name` varchar(100) NOT NULL,
  `equipment_description` text NOT NULL,
  `equipment_status` enum('Available','Maintenance','Out of Order') NOT NULL DEFAULT 'Available',
  `equipment_dateAdded` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_equipments`
--

INSERT INTO `tbl_equipments` (`equipment_id`, `equipment_name`, `equipment_description`, `equipment_status`, `equipment_dateAdded`) VALUES
(1, 'Mixer A', 'Industrial dough mixer - 50L capacity', '', '2025-01-10 13:37:17'),
(2, 'Mixer B', 'Industrial dough mixer - 100L capacity', 'Available', '2025-01-10 13:37:17'),
(3, 'Oven 1', 'Deck oven - 3 decks', '', '2025-01-10 13:38:05'),
(4, 'Oven 2', 'Convection oven', 'Available', '2025-01-10 13:38:05'),
(5, 'Proofer 1', 'Walk-in proofer', '', '2025-01-10 13:39:53'),
(6, 'Sheeter 1', 'Dough sheeter - medium capacity', 'Available', '2025-01-10 13:39:53');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_ingredients`
--

CREATE TABLE `tbl_ingredients` (
  `ingredient_id` int(6) NOT NULL,
  `product_id` varchar(10) DEFAULT NULL,
  `recipe_id` int(6) NOT NULL,
  `ingredient_name` varchar(200) NOT NULL,
  `ingredient_quantity` int(6) NOT NULL,
  `ingredient_unitOfMeasure` varchar(50) NOT NULL,
  `inventory_units_required` decimal(10,4) DEFAULT NULL,
  `conversion_factor` decimal(10,4) DEFAULT NULL,
  `conversion_notes` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_ingredients`
--

INSERT INTO `tbl_ingredients` (`ingredient_id`, `product_id`, `recipe_id`, `ingredient_name`, `ingredient_quantity`, `ingredient_unitOfMeasure`, `inventory_units_required`, `conversion_factor`, `conversion_notes`) VALUES
(17, NULL, 9, 'Sugar', 200, 'g', NULL, NULL, NULL),
(18, NULL, 9, 'Cocoa Powder', 100, 'g', NULL, NULL, NULL),
(19, NULL, 9, 'Eggs', 4, 'pcs', NULL, NULL, NULL),
(20, NULL, 9, 'Butter', 250, 'g', NULL, NULL, NULL),
(62, NULL, 1, 'Bread Flour', 500, 'g', NULL, NULL, NULL),
(63, NULL, 1, 'Active Dry Yeast', 7, 'g', NULL, NULL, NULL),
(64, NULL, 1, 'Salt', 10, 'g', NULL, NULL, NULL),
(65, NULL, 1, 'Warm Water', 350, 'ml', NULL, NULL, NULL),
(66, NULL, 2, 'All-Purpose Flour', 500, 'g', NULL, NULL, NULL),
(67, NULL, 2, 'Butter', 250, 'g', NULL, NULL, NULL),
(68, NULL, 2, 'Sugar', 50, 'g', NULL, NULL, NULL),
(69, NULL, 2, 'Salt', 10, 'g', NULL, NULL, NULL),
(70, NULL, 2, 'Active Dry Yeast', 7, 'g', NULL, NULL, NULL),
(71, NULL, 2, 'Milk', 200, 'ml', NULL, NULL, NULL),
(72, NULL, 2, 'Dark Chocolate', 200, 'g', NULL, NULL, NULL),
(73, NULL, 3, 'Cake Flour', 150, 'g', NULL, NULL, NULL),
(74, NULL, 3, 'Eggs', 6, 'pcs', NULL, NULL, NULL),
(75, NULL, 3, 'Sugar', 150, 'g', NULL, NULL, NULL),
(76, NULL, 3, 'Vegetable Oil', 80, 'ml', NULL, NULL, NULL),
(77, NULL, 3, 'Vanilla Extract', 10, 'ml', NULL, NULL, NULL),
(78, NULL, 4, 'Bread Flour', 500, 'g', NULL, NULL, NULL),
(79, NULL, 4, 'Whole Wheat Flour', 100, 'g', NULL, NULL, NULL),
(80, NULL, 4, 'Sourdough Starter', 150, 'g', NULL, NULL, NULL),
(81, NULL, 4, 'Salt', 12, 'g', NULL, NULL, NULL),
(82, NULL, 4, 'Water', 350, 'ml', NULL, NULL, NULL),
(83, NULL, 5, 'All-Purpose Flour', 280, 'g', NULL, NULL, NULL),
(84, NULL, 5, 'Butter', 230, 'g', NULL, NULL, NULL),
(85, NULL, 5, 'Brown Sugar', 200, 'g', NULL, NULL, NULL),
(86, NULL, 5, 'White Sugar', 100, 'g', NULL, NULL, NULL),
(87, NULL, 5, 'Eggs', 2, 'pcs', NULL, NULL, NULL),
(88, NULL, 5, 'Vanilla Extract', 5, 'ml', NULL, NULL, NULL),
(89, NULL, 5, 'Chocolate Chips', 300, 'g', NULL, NULL, NULL),
(90, NULL, 6, 'All-Purpose Flour', 400, 'g', NULL, NULL, NULL),
(91, NULL, 6, 'Butter', 250, 'g', NULL, NULL, NULL),
(92, NULL, 6, 'Sugar', 50, 'g', NULL, NULL, NULL),
(93, NULL, 6, 'Active Dry Yeast', 7, 'g', NULL, NULL, NULL),
(94, NULL, 6, 'Milk', 180, 'ml', NULL, NULL, NULL),
(95, NULL, 6, 'Eggs', 2, 'pcs', NULL, NULL, NULL),
(96, NULL, 6, 'Mixed Fruits', 300, 'g', NULL, NULL, NULL),
(97, NULL, 7, 'Cake Flour', 300, 'g', NULL, NULL, NULL),
(98, NULL, 7, 'Cocoa Powder', 20, 'g', NULL, NULL, NULL),
(99, NULL, 7, 'Butter', 120, 'g', NULL, NULL, NULL),
(100, NULL, 7, 'Sugar', 300, 'g', NULL, NULL, NULL),
(101, NULL, 7, 'Eggs', 3, 'pcs', NULL, NULL, NULL),
(102, NULL, 7, 'Buttermilk', 240, 'ml', NULL, NULL, NULL),
(103, NULL, 7, 'Red Food Coloring', 30, 'ml', NULL, NULL, NULL),
(104, NULL, 7, 'Cream Cheese', 500, 'g', NULL, NULL, NULL),
(105, NULL, 8, 'Whole Wheat Flour', 300, 'g', NULL, NULL, NULL),
(106, NULL, 8, 'Bread Flour', 200, 'g', NULL, NULL, NULL),
(107, NULL, 8, 'Active Dry Yeast', 7, 'g', NULL, NULL, NULL),
(108, NULL, 8, 'Honey', 30, 'ml', NULL, NULL, NULL),
(109, NULL, 8, 'Salt', 10, 'g', NULL, NULL, NULL),
(110, NULL, 8, 'Warm Water', 300, 'ml', NULL, NULL, NULL),
(111, NULL, 9, 'Almond Flour', 200, 'g', NULL, NULL, NULL),
(112, NULL, 9, 'Powdered Sugar', 200, 'g', NULL, NULL, NULL),
(113, NULL, 9, 'Egg Whites', 70, 'g', NULL, NULL, NULL),
(114, NULL, 9, 'Granulated Sugar', 90, 'g', NULL, NULL, NULL),
(115, NULL, 9, 'Food Coloring', 1, 'g', NULL, NULL, NULL),
(116, NULL, 10, 'Almond Flour', 150, 'g', NULL, NULL, NULL),
(117, NULL, 10, 'Powdered Sugar', 150, 'g', NULL, NULL, NULL),
(118, NULL, 10, 'Eggs', 5, 'pcs', NULL, NULL, NULL),
(119, NULL, 10, 'Dark Chocolate', 200, 'g', NULL, NULL, NULL),
(120, NULL, 10, 'Coffee Extract', 30, 'ml', NULL, NULL, NULL),
(121, NULL, 10, 'Butter', 250, 'g', NULL, NULL, NULL),
(122, NULL, 10, 'Heavy Cream', 200, 'ml', NULL, NULL, NULL),
(127, NULL, 13, 'yis', 43, 'g', NULL, NULL, NULL),
(128, NULL, 14, 'yis', 43, 'g', NULL, NULL, NULL),
(134, NULL, 15, 'yis', 43, 'pcs', NULL, NULL, NULL),
(145, NULL, 16, 'TEPUNG ROTI', 500, 'g', NULL, NULL, NULL),
(146, NULL, 16, 'GULA CASTER', 4, 'g', NULL, NULL, NULL),
(147, NULL, 16, 'SUSU TEPUNG', 2, 'g', NULL, NULL, NULL),
(148, NULL, 16, 'AIR', 1, 'l', NULL, NULL, NULL),
(149, NULL, 16, 'BUTTER', 2, 'g', NULL, NULL, NULL),
(150, NULL, 16, 'KRIM JAGUNG', 2, 'l', NULL, NULL, NULL),
(151, NULL, 16, 'TELUR', 1, 'pcs', NULL, NULL, NULL),
(152, NULL, 16, 'GARAM', 12, 'g', NULL, NULL, NULL),
(153, NULL, 16, 'PEWARNA KUNING', 1, 'ml', NULL, NULL, NULL),
(154, NULL, 16, 'YIS', 11, 'g', NULL, NULL, NULL),
(155, 'PROD0005', 17, 'Chocolate Chip Cookie', 5, 'pcs', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_product_conversions`
--

CREATE TABLE `tbl_product_conversions` (
  `conversion_id` int(6) NOT NULL,
  `product_id` varchar(10) NOT NULL,
  `recipe_unit` varchar(20) NOT NULL,
  `inventory_unit` varchar(20) NOT NULL DEFAULT 'units',
  `conversion_factor` decimal(10,4) NOT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_product_conversions`
--

INSERT INTO `tbl_product_conversions` (`conversion_id`, `product_id`, `recipe_unit`, `inventory_unit`, `conversion_factor`, `notes`, `created_at`, `updated_at`) VALUES
(1, 'PROD0001', 'kg', 'units', 1.0000, '1 unit of White Bread = 1 kg', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(2, 'PROD0001', 'g', 'units', 1000.0000, '1 unit of White Bread = 1000 grams', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(3, 'PROD0005', 'kg', 'units', 0.5000, '1 unit of Chocolate Chip Cookie = 0.5 kg', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(4, 'PROD0005', 'g', 'units', 500.0000, '1 unit of Chocolate Chip Cookie = 500 grams', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(5, 'PROD0005', 'cups', 'units', 4.0000, '1 unit of Chocolate Chip Cookie = 4 cups', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(6, 'PROD0006', 'kg', 'units', 1.0000, '1 unit of Gluten-Free Flour = 1 kg', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(7, 'PROD0006', 'g', 'units', 1000.0000, '1 unit of Gluten-Free Flour = 1000 grams', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(8, 'PROD0006', 'cups', 'units', 8.0000, '1 unit of Gluten-Free Flour = 8 cups (approx)', '2025-06-15 12:35:22', '2025-06-15 12:35:22'),
(9, 'PROD0001', 'pcs', 'units', 1.0000, 'Default: 1 unit = 1 piece', '2025-06-15 12:39:25', '2025-06-15 12:39:25'),
(12, 'PROD0002', 'kg', 'units', 1.0000, 'Default: 1 unit = 1 kg', '2025-06-15 12:39:25', '2025-06-15 12:39:25'),
(13, 'PROD0002', 'g', 'units', 1000.0000, 'Default: 1 unit = 1000 g', '2025-06-15 12:39:25', '2025-06-15 12:39:25'),
(14, 'PROD0002', 'pcs', 'units', 1.0000, 'Default: 1 unit = 1 piece', '2025-06-15 12:39:25', '2025-06-15 12:39:25'),
(15, 'PROD0005', 'pcs', 'units', 1.0000, 'Default: 1 unit = 1 piece', '2025-06-15 12:39:25', '2025-06-15 12:39:25'),
(153, 'PROD0007', 'kg', 'units', 1.0000, 'Default: 1 unit = 1 kg', '2025-06-15 13:27:42', '2025-06-15 13:27:42'),
(154, 'PROD0007', 'g', 'units', 1000.0000, 'Default: 1 unit = 1000 g', '2025-06-15 13:27:42', '2025-06-15 13:27:42'),
(155, 'PROD0007', 'cups', 'units', 5.0000, 'Default: 1 unit = 5 cups (approx)', '2025-06-15 13:27:42', '2025-06-15 13:27:42');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_quality_checks`
--

CREATE TABLE `tbl_quality_checks` (
  `qc_id` int(11) NOT NULL,
  `batch_id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `production_stage` enum('Mixing','Baking','Cooling','Packaging') NOT NULL,
  `appearance` varchar(255) DEFAULT NULL,
  `texture` varchar(255) DEFAULT NULL,
  `taste_flavour` varchar(255) DEFAULT NULL,
  `shape_size` varchar(255) DEFAULT NULL,
  `packaging` varchar(255) DEFAULT NULL,
  `qc_comments` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_quality_checks`
--

INSERT INTO `tbl_quality_checks` (`qc_id`, `batch_id`, `user_id`, `production_stage`, `appearance`, `texture`, `taste_flavour`, `shape_size`, `packaging`, `qc_comments`, `created_at`) VALUES
(8, 9, 24, 'Mixing', 'Good', 'Soft & Fluffy', 'Excellent Flavour', 'Uniform Shape', 'Properly Packaged', NULL, '2025-04-23 05:50:08'),
(9, 9, 24, 'Mixing', 'Good', 'Soft & Fluffy', 'Excellent Flavour', 'Uniform Shape', 'Damaged Packaged', NULL, '2025-04-24 03:16:40'),
(10, 9, 24, 'Mixing', 'Good', 'Soft & Fluffy', 'Excellent Flavour', 'Uniform Shape', 'Properly Packaged', NULL, '2025-05-17 10:41:05'),
(11, 12, 24, 'Mixing', 'Good', 'Soft & Fluffy', 'Excellent Flavour', 'Uniform Shape', 'Properly Packaged', NULL, '2025-05-17 14:44:44'),
(12, 12, 24, 'Mixing', NULL, NULL, NULL, NULL, NULL, NULL, '2025-05-18 02:43:07');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_recipe`
--

CREATE TABLE `tbl_recipe` (
  `recipe_id` int(6) NOT NULL,
  `recipe_name` varchar(100) NOT NULL,
  `recipe_category` varchar(100) NOT NULL,
  `recipe_batchSize` int(6) NOT NULL,
  `recipe_unitOfMeasure` varchar(50) NOT NULL,
  `recipe_instructions` text NOT NULL,
  `recipe_dateCreated` timestamp NOT NULL DEFAULT current_timestamp(),
  `recipe_dateUpdated` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `image_path` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_recipe`
--

INSERT INTO `tbl_recipe` (`recipe_id`, `recipe_name`, `recipe_category`, `recipe_batchSize`, `recipe_unitOfMeasure`, `recipe_instructions`, `recipe_dateCreated`, `recipe_dateUpdated`, `image_path`) VALUES
(1, 'Classic Baguette', 'Bread', 4, 'pcs', '1. Mix flour, yeast, and salt\n2. Add water gradually and knead for 10 minutes\n3. First rise: 1 hour\n4. Shape into baguettes\n5. Second rise: 30 minutes\n6. Score and bake at 230°C for 25 minutes', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(2, 'Chocolate Croissant', 'Pastry', 12, 'pcs', '1. Prepare laminated dough\n2. Roll and cut into triangles\n3. Add chocolate batons\n4. Shape and proof for 2 hours\n5. Egg wash and bake at 190°C for 18 minutes', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(3, 'Vanilla Chiffon Cake', 'Cake', 1, 'pcs', '1. Separate eggs\n2. Whip egg whites with sugar\n3. Mix wet and dry ingredients\n4. Fold in meringue\n5. Bake in tube pan at 170°C for 45 minutes', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(4, 'Sourdough Bread', 'Bread', 2, 'pcs', '1. Feed starter 12 hours before\n2. Mix dough and autolyse\n3. Stretch and fold every 30 minutes\n4. Bulk ferment 4-6 hours\n5. Shape and cold proof overnight\n6. Bake in Dutch oven', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(5, 'Chocolate Chip Cookies', 'Cookie', 24, 'pcs', '1. Cream butter and sugars\n2. Add eggs and vanilla\n3. Mix in dry ingredients\n4. Fold in chocolate chips\n5. Scoop and bake at 180°C for 12 minutes', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(6, 'Fruit Danish', 'Pastry', 8, 'pcs', '1. Prepare Danish dough\n2. Roll and cut squares\n3. Add pastry cream and fruits\n4. Proof for 1 hour\n5. Bake at 200°C for 15 minutes\n6. Glaze while warm', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(7, 'Red Velvet Cake', 'Cake', 1, 'pcs', '1. Mix wet ingredients\n2. Combine dry ingredients\n3. Add red food coloring\n4. Bake layers at 175°C\n5. Prepare cream cheese frosting\n6. Assemble and frost', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(8, 'Whole Wheat Bread', 'Bread', 2, 'pcs', '1. Mix flours and yeast\n2. Knead for 15 minutes\n3. First rise: 90 minutes\n4. Shape loaves\n5. Second rise: 45 minutes\n6. Bake at 200°C', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(9, 'Macarons', 'Cookie', 24, 'pcs', '1. Age egg whites\n2. Make almond flour mixture\n3. Prepare meringue\n4. Macaronage\n5. Pipe and rest\n6. Bake at 150°C\n7. Fill and mature', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(10, 'Opera Cake', 'Cake', 1, 'pcs', '1. Bake Joconde layers\n2. Prepare coffee syrup\n3. Make chocolate ganache\n4. Prepare coffee buttercream\n5. Layer and refrigerate\n6. Glaze with chocolate', '2024-12-25 15:00:26', '2024-12-25 15:06:00', NULL),
(13, 'roti susu', 'Bread', 57, 'pcs', '1.2333', '2025-05-17 06:52:07', '2025-05-17 06:52:07', 'images/recipes/68283217094db_BPME1013 BMC GROUP 2.png'),
(14, 'KEK MARBLE', 'Cake', 40, 'kg', '1. MASUKKAN YIS\r\n2. LETAK DALAM OVEN', '2025-05-17 07:51:19', '2025-05-17 07:51:19', 'images/recipes/68283ff7c8d6c_PROFILE PIC YT BOT.png'),
(15, 'KEK labu', 'Pastry', 40, 'pcs', '1. AIR\r\n2. GARAM', '2025-05-17 07:54:17', '2025-05-17 10:07:28', 'images/recipes/682840a912d17_use case Diagram-USE CASE DIAGRAM.drawio.png'),
(16, 'ROTI JAGUNG', 'Bread', 50, 'pcs', '1. Menggunakan breadmaker:\r\n- Masukkan bahan B terlebih dahulu.\r\n- Masukkan bahan A dan akhirnya yeast (C).\r\n- Pilih fungsi untuk roti manis.\r\n\r\n2. Selepas 2 jam, keluarkan doh dan bulat-bulatkan mengikut saiz yang diingini. Rehatkan selama 1 jam 30 minit sebelum dipanaskan oven. Atau, biarkan di dalam breadmaker sehingga masak.\r\n\r\n3. Bakar pada suhu 150°C selama 20 minit. Angkat dan sapu butter ke atasnya untuk mengekalkan lembutnya roti dan warna yang berkilat.', '2025-05-18 06:11:09', '2025-05-18 06:11:09', 'images/recipes/682979fd618e9_roti jagung.jpg'),
(17, 'test', 'Bread', 1, 'pcs', '1sdjsjfsd', '2025-06-15 12:50:16', '2025-06-15 12:50:16', 'images/recipes/684ec18872d5e_wallpaper.png');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_schedule`
--

CREATE TABLE `tbl_schedule` (
  `schedule_id` int(6) NOT NULL,
  `recipe_id` int(6) NOT NULL,
  `schedule_date` date NOT NULL,
  `schedule_quantityToProduce` int(6) NOT NULL,
  `schedule_orderVolumn` int(6) NOT NULL,
  `schedule_status` enum('Pending','In Progress','Completed','') NOT NULL DEFAULT 'Pending',
  `schedule_batchNum` int(3) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_schedule`
--

INSERT INTO `tbl_schedule` (`schedule_id`, `recipe_id`, `schedule_date`, `schedule_quantityToProduce`, `schedule_orderVolumn`, `schedule_status`, `schedule_batchNum`) VALUES
(6, 1, '2025-01-14', 32, 30, 'Pending', 8),
(7, 2, '2025-01-15', 36, 30, 'In Progress', 3),
(8, 4, '2025-01-16', 10, 10, 'Completed', 5),
(9, 5, '2025-01-13', 48, 40, 'Pending', 2),
(10, 3, '2025-04-25', 32, 32, 'Pending', 32),
(11, 6, '2025-04-25', 32, 30, 'Pending', 4),
(12, 15, '2025-05-17', 80, 78, 'Pending', 2),
(13, 17, '2025-06-16', 1, 1, 'In Progress', 1),
(14, 17, '2025-06-15', 3, 3, 'In Progress', 3),
(15, 17, '2025-06-16', 2, 2, 'In Progress', 2);

-- --------------------------------------------------------

--
-- Table structure for table `tbl_schedule_assignments`
--

CREATE TABLE `tbl_schedule_assignments` (
  `sa_id` int(6) NOT NULL,
  `schedule_id` int(6) NOT NULL,
  `user_id` int(6) NOT NULL,
  `sa_dateAssigned` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_schedule_assignments`
--

INSERT INTO `tbl_schedule_assignments` (`sa_id`, `schedule_id`, `user_id`, `sa_dateAssigned`) VALUES
(22, 7, 14, '2025-01-13 07:31:27'),
(29, 9, 18, '2025-01-13 09:45:56'),
(30, 6, 17, '2025-01-13 12:01:17'),
(31, 6, 14, '2025-01-13 12:01:17'),
(32, 8, 19, '2025-01-13 12:09:58'),
(33, 8, 14, '2025-01-13 12:09:58'),
(34, 10, 15, '2025-04-24 01:40:04'),
(35, 10, 18, '2025-04-24 01:40:04'),
(36, 11, 20, '2025-04-24 03:15:31'),
(37, 11, 14, '2025-04-24 03:15:31'),
(38, 12, 16, '2025-05-17 10:20:49'),
(39, 12, 20, '2025-05-17 10:20:49'),
(40, 12, 21, '2025-05-17 10:20:49'),
(42, 13, 15, '2025-06-15 16:24:09'),
(44, 14, 18, '2025-06-15 16:27:23'),
(46, 15, 17, '2025-06-15 16:30:50');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_schedule_equipment`
--

CREATE TABLE `tbl_schedule_equipment` (
  `se_id` int(6) NOT NULL,
  `schedule_id` int(6) NOT NULL,
  `equipment_id` int(6) NOT NULL,
  `se_dateAssigned` datetime(6) NOT NULL DEFAULT current_timestamp(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_schedule_equipment`
--

INSERT INTO `tbl_schedule_equipment` (`se_id`, `schedule_id`, `equipment_id`, `se_dateAssigned`) VALUES
(18, 7, 3, '2025-01-13 15:31:27.236068'),
(19, 7, 5, '2025-01-13 15:31:27.236899'),
(26, 9, 1, '2025-01-13 17:45:56.211112'),
(27, 6, 1, '2025-01-13 20:01:17.208562'),
(28, 6, 3, '2025-01-13 20:01:17.208747'),
(29, 8, 1, '2025-01-13 20:09:58.507312'),
(30, 8, 3, '2025-01-13 20:09:58.507424'),
(31, 10, 1, '2025-04-24 09:40:04.884444'),
(32, 11, 2, '2025-04-24 11:15:31.268948'),
(33, 11, 4, '2025-04-24 11:15:31.269600'),
(34, 11, 5, '2025-04-24 11:15:31.275615'),
(35, 12, 3, '2025-05-17 18:20:49.689435'),
(36, 12, 4, '2025-05-17 18:20:49.689694'),
(37, 12, 5, '2025-05-17 18:20:49.689898'),
(40, 13, 1, '2025-06-16 00:24:09.999508'),
(41, 13, 4, '2025-06-16 00:24:10.002872'),
(44, 14, 1, '2025-06-16 00:27:23.910407'),
(45, 14, 4, '2025-06-16 00:27:23.913838'),
(48, 15, 2, '2025-06-16 00:30:50.126317'),
(49, 15, 5, '2025-06-16 00:30:50.126486');

-- --------------------------------------------------------

--
-- Table structure for table `tbl_users`
--

CREATE TABLE `tbl_users` (
  `user_id` int(6) NOT NULL,
  `user_fullName` varchar(200) NOT NULL,
  `user_contact` varchar(100) NOT NULL,
  `user_address` text NOT NULL,
  `user_email` varchar(200) NOT NULL,
  `user_password` varchar(200) NOT NULL,
  `user_dateRegister` datetime NOT NULL DEFAULT current_timestamp(),
  `user_role` varchar(10) NOT NULL DEFAULT 'Baker',
  `reset_token` varchar(255) DEFAULT NULL,
  `token_expiry` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `tbl_users`
--

INSERT INTO `tbl_users` (`user_id`, `user_fullName`, `user_contact`, `user_address`, `user_email`, `user_password`, `user_dateRegister`, `user_role`, `reset_token`, `token_expiry`) VALUES
(13, 'Admin', '0123456789', 'Roti Sri Bakery', 'Admin@gmail.com', '$2y$10$ydcLcf5duwhxgjXK.k/mBu0ikQTz14zXVDzmcx25BOhUsNifs5QB.', '2024-12-29 16:36:59', 'Admin', NULL, NULL),
(14, 'Alicia', '0123456789', 'Kedah', 'alicia@gmail.com', '$2y$10$7jFzWeYpO8gz8e0Z2qVpkeE6co5taUmyOzqflvvx4g1wldBhV8T8e', '2024-12-29 16:38:47', 'Supervisor', NULL, NULL),
(15, 'Abby', '0123456789', 'Kedah', 'abby@gmail.com', '$2y$10$wHP8eBIK2iFIPznx4cop5ue5wlEBw4HYxU0is3ogQw5DeSLrvA8Re', '2024-12-29 16:39:21', 'Baker', NULL, NULL),
(16, 'Aurora', '0123456789', 'Kedah', 'aurora@gmail.com', '$2y$10$EfeC4D4e6C2XFTUMbJjxXO.MJ2OvHtSwpYGztV.Hh5WxSxwcXdbA.', '2024-12-29 16:40:12', 'Baker', NULL, NULL),
(17, 'Irdina', '0123456789', 'Kedah', 'irdina@gmail.com', '$2y$10$NpmQZqhTTp4YOGvN4DGNkeoKdZ2oiB6UJGrI4f/cCStUpNt2CWbVK', '2024-12-29 16:41:00', 'Baker', NULL, NULL),
(18, 'Alia', '0123456789', 'Kedah', 'alia@gmail.com', '$2y$10$px1H5SauACBIugoCce/aPehQol8A7tS9w6qza4W/LB7OUQv4apSBO', '2024-12-29 16:42:41', 'Baker', NULL, NULL),
(19, 'Yungjie', '0123456789', '1234567890', 'yungjielee@gmail.com', '$2y$10$7kI7/iEFumEnHNWqVdYoHeBlNZjwML.kDhDQ2aAY6mqeb2E3oGvka', '2025-01-13 04:25:43', 'Baker', NULL, NULL),
(20, 'Jaslyn', '0123456789', 'Kedah', 'mylittlepony000101@gmail.com', '$2y$10$XO8Tdo9NeLIafi3nH257/.O1jhHoXel0Mrecfl7Xn/rzCDvp92gyC', '2025-01-13 09:02:27', 'Baker', '39bda9c939952785bec958e4792385ab8905a93f5914298b3b4448da06e8576de1d5112bd1524676528b4144b17b3d49175d', '2025-01-13 09:33:38'),
(21, 'MAYA BINTI ALI', '0123456789', 'JALAN TERUSAN, PARIT BUNTAR', 'maya@gmail.com', '$2y$10$AY23NqhpxzRw59yW1E5DD.XiXMmWlSy.Bcr/JpdAHNULaV9nOnIZy', '2025-03-18 10:10:24', 'Baker', NULL, NULL),
(23, 'Dummy Baker', '0000000000', 'dummy baker', 'dummy@baker.com', '$2y$10$rwKIvhDADIft3QuZ19gZdOUr7Ov9UyCMwRvnuuCNAn78qecyZHFUy', '2025-04-20 12:23:18', 'Baker', NULL, NULL),
(24, 'Dummy Sv', '00000000000', 'Dummy Sv', 'dummy@sv.com', '$2y$10$S0JBBnadQsTz63yqbvEhtuCNL5og9algN4MqpIvL5lDccT0/RdX52', '2025-04-20 12:24:03', 'Supervisor', NULL, NULL);

-- --------------------------------------------------------

--
-- Structure for view `conversion_setup_status`
--
DROP TABLE IF EXISTS `conversion_setup_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `conversion_setup_status`  AS SELECT `p`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`stock_quantity` AS `stock_quantity`, count(distinct `pc`.`recipe_unit`) AS `units_configured`, group_concat(distinct `pc`.`recipe_unit` order by `pc`.`recipe_unit` ASC separator ',') AS `configured_units`, count(distinct `i`.`ingredient_unitOfMeasure`) AS `units_used_in_recipes`, group_concat(distinct `i`.`ingredient_unitOfMeasure` order by `i`.`ingredient_unitOfMeasure` ASC separator ',') AS `recipe_units`, CASE WHEN count(distinct `pc`.`recipe_unit`) = 0 THEN 'NO_CONVERSIONS' WHEN count(distinct `pc`.`recipe_unit`) < count(distinct `i`.`ingredient_unitOfMeasure`) THEN 'INCOMPLETE' ELSE 'COMPLETE' END AS `conversion_status` FROM ((`roti_seri_bakery_inventory`.`products` `p` left join `tbl_product_conversions` `pc` on(`p`.`product_id` = `pc`.`product_id`)) left join `tbl_ingredients` `i` on(`p`.`product_id` = `i`.`product_id`)) GROUP BY `p`.`product_id`, `p`.`product_name`, `p`.`stock_quantity` ORDER BY `p`.`product_name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `ingredient_stock_status`
--
DROP TABLE IF EXISTS `ingredient_stock_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `ingredient_stock_status`  AS SELECT `i`.`ingredient_id` AS `ingredient_id`, `i`.`recipe_id` AS `recipe_id`, `r`.`recipe_name` AS `recipe_name`, `i`.`ingredient_name` AS `ingredient_name`, `i`.`ingredient_quantity` AS `ingredient_quantity`, `i`.`ingredient_unitOfMeasure` AS `ingredient_unitOfMeasure`, `i`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, coalesce(`p`.`stock_quantity`,0) AS `available_stock`, `p`.`reorder_threshold` AS `reorder_threshold`, CASE WHEN `i`.`product_id` is null THEN 'NOT_LINKED' WHEN `p`.`product_id` is null THEN 'PRODUCT_NOT_FOUND' WHEN `p`.`stock_quantity` <= 0 THEN 'OUT_OF_STOCK' WHEN `p`.`stock_quantity` <= `p`.`reorder_threshold` THEN 'LOW_STOCK' WHEN `p`.`stock_quantity` < `i`.`ingredient_quantity` THEN 'INSUFFICIENT_FOR_BATCH' ELSE 'SUFFICIENT' END AS `stock_status` FROM ((`tbl_ingredients` `i` join `tbl_recipe` `r` on(`i`.`recipe_id` = `r`.`recipe_id`)) left join `roti_seri_bakery_inventory`.`products` `p` on(`i`.`product_id` = `p`.`product_id`)) ORDER BY `r`.`recipe_name` ASC, `i`.`ingredient_name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `production_feasibility`
--
DROP TABLE IF EXISTS `production_feasibility`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `production_feasibility`  AS SELECT `r`.`recipe_id` AS `recipe_id`, `r`.`recipe_name` AS `recipe_name`, `r`.`recipe_batchSize` AS `recipe_batchSize`, coalesce(min(case when `i`.`product_id` is null or `p`.`product_id` is null or `i`.`ingredient_quantity` <= 0 then 0 else floor(`p`.`stock_quantity` / `i`.`ingredient_quantity`) end),0) AS `max_possible_batches`, count(`i`.`ingredient_id`) AS `total_ingredients`, coalesce(sum(case when `i`.`product_id` is not null and `p`.`product_id` is not null and `p`.`stock_quantity` < `i`.`ingredient_quantity` then 1 else 0 end),0) AS `ingredients_short`, coalesce(sum(case when `i`.`product_id` is null then 1 else 0 end),0) AS `ingredients_not_linked`, CASE WHEN count(`i`.`ingredient_id`) = 0 THEN 'NO_INGREDIENTS' WHEN sum(case when `i`.`product_id` is null then 1 else 0 end) > 0 THEN 'INGREDIENTS_NOT_LINKED' WHEN sum(case when `i`.`product_id` is not null AND `p`.`product_id` is not null AND `p`.`stock_quantity` < `i`.`ingredient_quantity` then 1 else 0 end) > 0 THEN 'INSUFFICIENT_STOCK' WHEN min(case when `i`.`product_id` is null OR `p`.`product_id` is null OR `i`.`ingredient_quantity` <= 0 then 0 else floor(`p`.`stock_quantity` / `i`.`ingredient_quantity`) end) <= 0 THEN 'CANNOT_PRODUCE' ELSE 'CAN_PRODUCE' END AS `production_status` FROM ((`tbl_recipe` `r` left join `tbl_ingredients` `i` on(`r`.`recipe_id` = `i`.`recipe_id`)) left join `roti_seri_bakery_inventory`.`products` `p` on(`i`.`product_id` = `p`.`product_id`)) GROUP BY `r`.`recipe_id`, `r`.`recipe_name`, `r`.`recipe_batchSize` ORDER BY `r`.`recipe_name` ASC ;

-- --------------------------------------------------------

--
-- Structure for view `recipe_inventory_status`
--
DROP TABLE IF EXISTS `recipe_inventory_status`;

CREATE ALGORITHM=UNDEFINED DEFINER=`root`@`localhost` SQL SECURITY DEFINER VIEW `recipe_inventory_status`  AS SELECT `r`.`recipe_id` AS `recipe_id`, `r`.`recipe_name` AS `recipe_name`, `r`.`recipe_batchSize` AS `recipe_batchSize`, `i`.`ingredient_id` AS `ingredient_id`, `i`.`ingredient_name` AS `ingredient_name`, `i`.`ingredient_quantity` AS `recipe_quantity`, `i`.`ingredient_unitOfMeasure` AS `recipe_unit`, `i`.`inventory_units_required` AS `inventory_units_required`, `i`.`conversion_factor` AS `conversion_factor`, `i`.`product_id` AS `product_id`, `p`.`product_name` AS `product_name`, `p`.`stock_quantity` AS `available_inventory_units`, `p`.`unit_price` AS `unit_price`, CASE WHEN `i`.`product_id` is null THEN 'NOT_LINKED' WHEN `p`.`product_id` is null THEN 'PRODUCT_NOT_FOUND' WHEN `i`.`inventory_units_required` is null THEN 'NO_CONVERSION' WHEN `p`.`stock_quantity` <= 0 THEN 'OUT_OF_STOCK' WHEN `p`.`stock_quantity` < `i`.`inventory_units_required` THEN 'INSUFFICIENT' ELSE 'SUFFICIENT' END AS `ingredient_status`, CASE WHEN `i`.`product_id` is null OR `p`.`product_id` is null OR `i`.`inventory_units_required` is null OR `i`.`inventory_units_required` <= 0 THEN 0 ELSE floor(`p`.`stock_quantity` / `i`.`inventory_units_required`) END AS `possible_batches_for_ingredient` FROM ((`tbl_recipe` `r` left join `tbl_ingredients` `i` on(`r`.`recipe_id` = `i`.`recipe_id`)) left join `roti_seri_bakery_inventory`.`products` `p` on(`i`.`product_id` = `p`.`product_id`)) ;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `tbl_batches`
--
ALTER TABLE `tbl_batches`
  ADD PRIMARY KEY (`batch_id`),
  ADD KEY `recipe_id` (`recipe_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `tbl_batch_assignments`
--
ALTER TABLE `tbl_batch_assignments`
  ADD PRIMARY KEY (`ba_id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `batch_id` (`batch_id`);

--
-- Indexes for table `tbl_equipments`
--
ALTER TABLE `tbl_equipments`
  ADD PRIMARY KEY (`equipment_id`);

--
-- Indexes for table `tbl_ingredients`
--
ALTER TABLE `tbl_ingredients`
  ADD PRIMARY KEY (`ingredient_id`),
  ADD KEY `receipt_id` (`recipe_id`),
  ADD KEY `idx_ingredient_product_id` (`product_id`);

--
-- Indexes for table `tbl_product_conversions`
--
ALTER TABLE `tbl_product_conversions`
  ADD PRIMARY KEY (`conversion_id`),
  ADD UNIQUE KEY `unique_product_unit` (`product_id`,`recipe_unit`),
  ADD KEY `idx_product_id` (`product_id`);

--
-- Indexes for table `tbl_quality_checks`
--
ALTER TABLE `tbl_quality_checks`
  ADD PRIMARY KEY (`qc_id`),
  ADD KEY `batch_id` (`batch_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tbl_recipe`
--
ALTER TABLE `tbl_recipe`
  ADD PRIMARY KEY (`recipe_id`),
  ADD KEY `idx_recipe_category` (`recipe_category`);

--
-- Indexes for table `tbl_schedule`
--
ALTER TABLE `tbl_schedule`
  ADD PRIMARY KEY (`schedule_id`),
  ADD KEY `recipe_id` (`recipe_id`),
  ADD KEY `idx_schedule_status` (`schedule_status`),
  ADD KEY `idx_schedule_date` (`schedule_date`);

--
-- Indexes for table `tbl_schedule_assignments`
--
ALTER TABLE `tbl_schedule_assignments`
  ADD PRIMARY KEY (`sa_id`),
  ADD KEY `schedule_id` (`schedule_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `tbl_schedule_equipment`
--
ALTER TABLE `tbl_schedule_equipment`
  ADD PRIMARY KEY (`se_id`),
  ADD KEY `equipment_id` (`equipment_id`),
  ADD KEY `schedule_id` (`schedule_id`);

--
-- Indexes for table `tbl_users`
--
ALTER TABLE `tbl_users`
  ADD PRIMARY KEY (`user_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `tbl_batches`
--
ALTER TABLE `tbl_batches`
  MODIFY `batch_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `tbl_batch_assignments`
--
ALTER TABLE `tbl_batch_assignments`
  MODIFY `ba_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=44;

--
-- AUTO_INCREMENT for table `tbl_equipments`
--
ALTER TABLE `tbl_equipments`
  MODIFY `equipment_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `tbl_ingredients`
--
ALTER TABLE `tbl_ingredients`
  MODIFY `ingredient_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=156;

--
-- AUTO_INCREMENT for table `tbl_product_conversions`
--
ALTER TABLE `tbl_product_conversions`
  MODIFY `conversion_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=171;

--
-- AUTO_INCREMENT for table `tbl_quality_checks`
--
ALTER TABLE `tbl_quality_checks`
  MODIFY `qc_id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=28;

--
-- AUTO_INCREMENT for table `tbl_recipe`
--
ALTER TABLE `tbl_recipe`
  MODIFY `recipe_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `tbl_schedule`
--
ALTER TABLE `tbl_schedule`
  MODIFY `schedule_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=16;

--
-- AUTO_INCREMENT for table `tbl_schedule_assignments`
--
ALTER TABLE `tbl_schedule_assignments`
  MODIFY `sa_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=47;

--
-- AUTO_INCREMENT for table `tbl_schedule_equipment`
--
ALTER TABLE `tbl_schedule_equipment`
  MODIFY `se_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=50;

--
-- AUTO_INCREMENT for table `tbl_users`
--
ALTER TABLE `tbl_users`
  MODIFY `user_id` int(6) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=25;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `tbl_batches`
--
ALTER TABLE `tbl_batches`
  ADD CONSTRAINT `tbl_batches_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `tbl_recipe` (`recipe_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tbl_batches_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `tbl_schedule` (`schedule_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tbl_batch_assignments`
--
ALTER TABLE `tbl_batch_assignments`
  ADD CONSTRAINT `tbl_batch_assignments_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tbl_batch_assignments_ibfk_2` FOREIGN KEY (`batch_id`) REFERENCES `tbl_batches` (`batch_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tbl_ingredients`
--
ALTER TABLE `tbl_ingredients`
  ADD CONSTRAINT `tbl_ingredients_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `tbl_recipe` (`recipe_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tbl_quality_checks`
--
ALTER TABLE `tbl_quality_checks`
  ADD CONSTRAINT `tbl_quality_checks_ibfk_1` FOREIGN KEY (`batch_id`) REFERENCES `tbl_batches` (`batch_id`),
  ADD CONSTRAINT `tbl_quality_checks_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`);

--
-- Constraints for table `tbl_schedule`
--
ALTER TABLE `tbl_schedule`
  ADD CONSTRAINT `tbl_schedule_ibfk_1` FOREIGN KEY (`recipe_id`) REFERENCES `tbl_recipe` (`recipe_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tbl_schedule_assignments`
--
ALTER TABLE `tbl_schedule_assignments`
  ADD CONSTRAINT `tbl_schedule_assignments_ibfk_1` FOREIGN KEY (`schedule_id`) REFERENCES `tbl_schedule` (`schedule_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tbl_schedule_assignments_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `tbl_users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `tbl_schedule_equipment`
--
ALTER TABLE `tbl_schedule_equipment`
  ADD CONSTRAINT `tbl_schedule_equipment_ibfk_1` FOREIGN KEY (`equipment_id`) REFERENCES `tbl_equipments` (`equipment_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `tbl_schedule_equipment_ibfk_2` FOREIGN KEY (`schedule_id`) REFERENCES `tbl_schedule` (`schedule_id`) ON DELETE CASCADE ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
