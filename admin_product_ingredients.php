<?php
include 'config.php';
include 'admin_log_activity.php';

// Verify admin session
$admin_id = $_SESSION['admin_id'] ?? null;
if (!isset($admin_id)) {
    header('location:login.php');
    exit;
}

// ==================== FETCH PRODUCT ====================
if (!isset($_GET['product_id'])) {
    header('location:admin_products.php');
    exit;
}

$product_id = intval($_GET['product_id']);
$product_stmt = $conn->prepare("SELECT * FROM `products` WHERE id = ?");
$product_stmt->bind_param("i", $product_id);
$product_stmt->execute();
$product_result = $product_stmt->get_result();

if ($product_result->num_rows === 0) {
    header('location:admin_products.php');
    exit;
}

$product = $product_result->fetch_assoc();
$product_stmt->close();

// ==================== ADD PRODUCT INGREDIENT ====================
if (isset($_POST['add_product_ingredient'])) {
    try {
        $ingredient_id = intval($_POST['ingredient_id'] ?? 0);
        $quantity_used = floatval($_POST['quantity_used'] ?? 0);

        if ($ingredient_id <= 0 || $quantity_used <= 0) {
            throw new Exception('Please select a valid ingredient and quantity');
        }

        // Check if ingredient already added
        $check_stmt = $conn->prepare("SELECT id FROM `product_ingredients` WHERE product_id = ? AND ingredient_id = ?");
        $check_stmt->bind_param("ii", $product_id, $ingredient_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        
        if ($check_result->num_rows > 0) {
            throw new Exception('This ingredient is already added to this product');
        }
        $check_stmt->close();

        // Insert product ingredient
        $insert_stmt = $conn->prepare(
            "INSERT INTO `product_ingredients` (product_id, ingredient_id, quantity_used) 
             VALUES (?, ?, ?)"
        );
        $insert_stmt->bind_param("iid", $product_id, $ingredient_id, $quantity_used);

        if (!$insert_stmt->execute()) {
            throw new Exception("Failed to add ingredient to product");
        }
        $insert_stmt->close();

        log_admin_activity($admin_id, 'Add Product Ingredient', "Added ingredient to product ID: $product_id");
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Ingredient added to product successfully!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header("location:admin_product_ingredients.php?product_id=$product_id");
    exit;
}

// ==================== UPDATE PRODUCT INGREDIENT ====================
if (isset($_POST['update_product_ingredient'])) {
    try {
        $prod_ingredient_id = intval($_POST['prod_ingredient_id'] ?? 0);
        $quantity_used = floatval($_POST['quantity_used'] ?? 0);

        if ($prod_ingredient_id <= 0 || $quantity_used <= 0) {
            throw new Exception('Invalid ingredient or quantity');
        }

        $update_stmt = $conn->prepare(
            "UPDATE `product_ingredients` SET quantity_used = ? WHERE id = ? AND product_id = ?"
        );
        $update_stmt->bind_param("dii", $quantity_used, $prod_ingredient_id, $product_id);

        if (!$update_stmt->execute()) {
            throw new Exception("Failed to update ingredient");
        }
        $update_stmt->close();

        log_admin_activity($admin_id, 'Update Product Ingredient', "Updated ingredient in product ID: $product_id");
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Ingredient updated successfully!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header("location:admin_product_ingredients.php?product_id=$product_id");
    exit;
}

// ==================== DELETE PRODUCT INGREDIENT ====================
if (isset($_GET['delete_ingredient'])) {
    try {
        $prod_ingredient_id = intval($_GET['delete_ingredient']);

        $delete_stmt = $conn->prepare(
            "DELETE FROM `product_ingredients` WHERE id = ? AND product_id = ?"
        );
        $delete_stmt->bind_param("ii", $prod_ingredient_id, $product_id);

        if (!$delete_stmt->execute()) {
            throw new Exception("Failed to delete ingredient");
        }
        $delete_stmt->close();

        log_admin_activity($admin_id, 'Delete Product Ingredient', "Removed ingredient from product ID: $product_id");
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Ingredient removed from product!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header("location:admin_product_ingredients.php?product_id=$product_id");
    exit;
}

// ==================== FETCH PRODUCT INGREDIENTS ====================
$prod_ing_stmt = $conn->prepare(
    "SELECT pi.id, pi.ingredient_id, pi.quantity_used, i.ingredient_name, i.unit, i.quantity as stock_available
     FROM `product_ingredients` pi
     JOIN `inventory` i ON pi.ingredient_id = i.id
     WHERE pi.product_id = ?
     ORDER BY i.ingredient_name ASC"
);
$prod_ing_stmt->bind_param("i", $product_id);
$prod_ing_stmt->execute();
$prod_ing_result = $prod_ing_stmt->get_result();
$product_ingredients = [];
while ($row = $prod_ing_result->fetch_assoc()) {
    $product_ingredients[] = $row;
}
$prod_ing_stmt->close();

// ==================== FETCH AVAILABLE INGREDIENTS ====================
$inv_stmt = $conn->prepare(
    "SELECT id, ingredient_name, unit, quantity FROM `inventory` ORDER BY ingredient_name ASC"
);
$inv_stmt->execute();
$inv_result = $inv_stmt->get_result();
$all_ingredients = [];
while ($row = $inv_result->fetch_assoc()) {
    $all_ingredients[] = $row;
}
$inv_stmt->close();

// Filter out already added ingredients
$available_ingredients = array_filter($all_ingredients, function($ing) use ($product_ingredients) {
    foreach ($product_ingredients as $pi) {
        if ($pi['ingredient_id'] == $ing['id']) {
            return false;
        }
    }
    return true;
});
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Product Ingredients — Six Origins Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
    <style>
        :root {
            --primary-red: #C6453E;
            --dark-brown: #5E1F13;
            --gray-brown: #664C47;
            --light-cream: #FFF2E0;
            --white: #FFFFFF;
            --radius: 16px;
            --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
            --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
        }

        body {
            background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
            color: var(--dark-brown);
            min-height: 100vh;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 28px 24px 60px;
        }

        /* Page Header */
        .page-header {
            display: flex;
            align-items: center;
            gap: 20px;
            margin-bottom: 28px;
            flex-wrap: wrap;
        }

        .page-header h1 {
            font-size: 2rem;
            color: var(--dark-brown);
            font-weight: 900;
        }

        .page-header .sub {
            color: var(--gray-brown);
            font-size: 0.95rem;
            margin-top: 6px;
            font-weight: 500;
        }

        /* Product Info Card */
        .product-info {
            background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            border: 1.5px solid #F0E6D8;
            margin-bottom: 28px;
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 20px;
            align-items: center;
        }

        .product-img {
            width: 120px;
            height: 120px;
            border-radius: 12px;
            object-fit: cover;
            box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
            border: 2px solid var(--primary-red);
        }

        .product-meta h2 {
            font-size: 1.6rem;
            color: var(--dark-brown);
            margin-bottom: 8px;
        }

        .product-price {
            font-size: 1.3rem;
            color: var(--primary-red);
            font-weight: 900;
            margin-bottom: 12px;
        }

        .product-desc {
            color: var(--gray-brown);
            font-size: 0.9rem;
            line-height: 1.5;
        }

        /* Alert */
        .alert {
            padding: 16px 20px;
            border-radius: var(--radius);
            margin-bottom: 24px;
            display: flex;
            gap: 12px;
            align-items: flex-start;
            border-left: 4px solid;
            font-weight: 600;
        }

        .alert.success {
            background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
            color: var(--primary-red);
            border-color: var(--primary-red);
        }

        .alert.error {
            background: linear-gradient(135deg, rgba(217, 126, 106, 0.1) 0%, rgba(217, 126, 106, 0.05) 100%);
            color: #C93353;
            border-color: #D97E6A;
        }

        /* Main Layout */
        .main-layout {
            display: grid;
            grid-template-columns: 1fr 1.5fr;
            gap: 28px;
            align-items: start;
        }

        /* Form Panel */
        .form-panel {
            background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
            border: 1.5px solid #F0E6D8;
            height: fit-content;
        }

        .form-panel h3 {
            font-size: 1.2rem;
            color: var(--dark-brown);
            font-weight: 900;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .form-panel h3 i {
            color: var(--primary-red);
            font-size: 1.4rem;
        }

        .field {
            margin-bottom: 18px;
        }

        label {
            display: block;
            font-size: 0.9rem;
            color: var(--dark-brown);
            margin-bottom: 8px;
            font-weight: 700;
        }

        label i {
            color: var(--primary-red);
            margin-right: 4px;
        }

        .input-wrapper {
            position: relative;
            display: flex;
            align-items: center;
            padding: 12px 14px;
            border-radius: 10px;
            background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
            border: 1.5px solid #F0E6D8;
            transition: var(--transition);
        }

        .input-wrapper:focus-within {
            border-color: var(--primary-red);
            box-shadow: 0 0 0 4px rgba(198, 69, 62, 0.1);
        }

        input[type="text"],
        input[type="number"],
        select {
            border: 0;
            outline: 0;
            background: transparent;
            width: 100%;
            font-size: 1rem;
            color: var(--dark-brown);
            font-weight: 500;
            font-family: inherit;
        }

        select option {
            color: var(--dark-brown);
            background: var(--white);
        }

        .help-text {
            color: var(--gray-brown);
            font-size: 0.8rem;
            margin-top: 6px;
            font-weight: 500;
        }

        .btn {
            width: 100%;
            padding: 12px 16px;
            background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
            color: #fff;
            border: none;
            border-radius: 10px;
            font-weight: 800;
            font-size: 0.9rem;
            cursor: pointer;
            box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            margin-top: 8px;
        }

        .btn:hover {
            background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
        }

        /* Ingredients List Panel */
        .ingredients-panel {
            background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
            border-radius: var(--radius);
            padding: 28px;
            box-shadow: var(--shadow);
            border: 1.5px solid #F0E6D8;
        }

        .ingredients-panel h3 {
            font-size: 1.3rem;
            color: var(--dark-brown);
            font-weight: 900;
            margin-bottom: 6px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .ingredients-panel h3 i {
            color: var(--primary-red);
            font-size: 1.5rem;
        }

        .ingredients-count {
            color: var(--gray-brown);
            font-size: 0.9rem;
            margin-bottom: 20px;
            font-weight: 500;
        }

        /* Ingredient Cards */
        .ingredients-list {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .ingredient-card {
            background: white;
            border: 1.5px solid #F0E6D8;
            border-radius: 12px;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: var(--transition);
        }

        .ingredient-card:hover {
            border-color: var(--primary-red);
            box-shadow: 0 4px 12px rgba(198, 69, 62, 0.15);
        }

        .ingredient-info {
            flex: 1;
        }

        .ingredient-name {
            font-weight: 700;
            color: var(--dark-brown);
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .ingredient-details {
            display: flex;
            gap: 16px;
            font-size: 0.85rem;
            color: var(--gray-brown);
            font-weight: 600;
        }

        .ingredient-detail {
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .ingredient-detail i {
            color: var(--primary-red);
        }

        .ingredient-actions {
            display: flex;
            gap: 8px;
        }

        .btn-icon {
            width: 36px;
            height: 36px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .btn-edit {
            background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%);
            color: #fff;
        }

        .btn-edit:hover {
            background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
            transform: translateY(-2px);
        }

        .btn-delete {
            background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
            color: #D97E6A;
            border: 1.5px solid rgba(217, 126, 106, 0.2);
        }

        .btn-delete:hover {
            background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
            color: #fff;
            border-color: #D97E6A;
            transform: translateY(-2px);
        }

        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: var(--gray-brown);
        }

        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 16px;
            opacity: 0.3;
        }

        .empty-state p {
            font-size: 1rem;
            margin-bottom: 8px;
        }

        /* Modal */
        .modal {
            position: fixed;
            inset: 0;
            display: none;
            align-items: center;
            justify-content: center;
            background: rgba(94, 31, 19, 0.6);
            z-index: 1200;
            padding: 20px;
        }

        .modal.show {
            display: flex;
        }

        .modal-content {
            width: 100%;
            max-width: 500px;
            background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
            border-radius: var(--radius);
            padding: 32px;
            box-shadow: var(--shadow-hover);
            border: 1.5px solid #F0E6D8;
            animation: slideUp 0.3s ease;
        }

        @keyframes slideUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            padding-bottom: 16px;
            border-bottom: 1.5px solid #F0E6D8;
        }

        .modal-header h2 {
            font-size: 1.4rem;
            color: var(--dark-brown);
            font-weight: 900;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .modal-header h2 i {
            color: var(--primary-red);
        }

        .modal-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: var(--gray-brown);
            transition: var(--transition);
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .modal-close:hover {
            color: var(--dark-brown);
            transform: rotate(90deg);
        }

        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 28px;
            justify-content: flex-end;
            flex-wrap: wrap;
        }

        .btn-cancel {
            background: transparent;
            border: 1.5px solid #F0E6D8;
            color: var(--dark-brown);
            padding: 10px 20px;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.9rem;
            transition: var(--transition);
        }

        .btn-cancel:hover {
            border-color: var(--primary-red);
            color: var(--primary-red);
        }

        .btn-save {
            background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
            color: #fff;
            padding: 10px 24px;
            border-radius: 8px;
            border: none;
            cursor: pointer;
            font-weight: 700;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition);
        }

        .btn-save:hover {
            background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
            transform: translateY(-2px);
        }

        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--primary-red);
            text-decoration: none;
            font-weight: 700;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .back-link:hover {
            gap: 12px;
        }

        @media (max-width: 1100px) {
            .main-layout {
                grid-template-columns: 1fr;
            }

            .product-info {
                grid-template-columns: 1fr;
                text-align: center;
            }
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px 14px 48px;
            }

            .page-header h1 {
                font-size: 1.5rem;
            }

            .product-info {
                padding: 18px;
            }

            .product-img {
                width: 100px;
                height: 100px;
            }
        }
    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="container">
    <!-- Back Link -->
    <a href="admin_products.php" class="back-link">
        <i class="fa-solid fa-arrow-left"></i> Back to Products
    </a>

    <!-- Alerts -->
    <?php
        if(isset($_SESSION['message'])){
            $msg = $_SESSION['message'];
            $icon = $msg['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation';
            echo '<div class="alert '.$msg['type'].'"><i class="fa-solid '.$icon.'"></i><div>'.htmlspecialchars($msg['text']).'</div></div>';
            unset($_SESSION['message']);
        }
    ?>

    <!-- Product Info Card -->
    <div class="product-info">
        <img src="images/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" class="product-img">
        <div class="product-meta">
            <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            <div class="product-price">
                <i class="fa-solid fa-peso-sign"></i> <?php echo number_format($product['price'], 2); ?>
            </div>
            <?php if (!empty($product['details'])): ?>
                <div class="product-desc"><?php echo htmlspecialchars($product['details']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Main Layout -->
    <div class="main-layout">
        <!-- Left Panel - Add Ingredient Form -->
        <aside class="form-panel">
            <h3><i class="fa-solid fa-flask"></i> Add Ingredient</h3>

            <form method="POST" novalidate>
                <div class="field">
                    <label><i class="fa-solid fa-list"></i> Select Ingredient</label>
                    <div class="input-wrapper">
                        <select name="ingredient_id" required>
                            <option value="">-- Choose Ingredient --</option>
                            <?php foreach ($available_ingredients as $ing): ?>
                                <option value="<?php echo intval($ing['id']); ?>">
                                    <?php echo htmlspecialchars($ing['ingredient_name']); ?> (<?php echo htmlspecialchars($ing['unit']); ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="help-text"><i class="fa-solid fa-info-circle"></i> Already added ingredients are hidden</div>
                </div>

                <div class="field">
                    <label><i class="fa-solid fa-weight"></i> Quantity Used Per Unit</label>
                    <div class="input-wrapper">
                        <input type="number" name="quantity_used" min="0.01" step="0.01" placeholder="e.g., 18" required>
                    </div>
                    <div class="help-text"><i class="fa-solid fa-info-circle"></i> How much of this ingredient is used per product</div>
                </div>

                <button type="submit" name="add_product_ingredient" class="btn">
                    <i class="fa-solid fa-plus-circle"></i> Add Ingredient
                </button>
            </form>
        </aside>

        <!-- Right Panel - Ingredients List -->
        <main class="ingredients-panel">
            <h3><i class="fa-solid fa-flask-vial"></i> Product Recipe</h3>
            <div class="ingredients-count">
                <?php echo count($product_ingredients); ?> ingredient<?php echo count($product_ingredients) !== 1 ? 's' : ''; ?> in recipe
            </div>

            <?php if (count($product_ingredients) > 0): ?>
                <div class="ingredients-list">
                    <?php foreach ($product_ingredients as $pi): ?>
                        <div class="ingredient-card">
                            <div class="ingredient-info">
                                <div class="ingredient-name"><?php echo htmlspecialchars($pi['ingredient_name']); ?></div>
                                <div class="ingredient-details">
                                    <span class="ingredient-detail">
                                        <i class="fa-solid fa-weight"></i> 
                                        <?php echo number_format($pi['quantity_used'], 2); ?> <?php echo htmlspecialchars($pi['unit']); ?>
                                    </span>
                                    <span class="ingredient-detail">
                                        <i class="fa-solid fa-cubes"></i>
                                        Stock: <?php echo number_format($pi['stock_available'], 2); ?> <?php echo htmlspecialchars($pi['unit']); ?>
                                    </span>
                                </div>
                            </div>
                            <div class="ingredient-actions">
                                <button type="button" class="btn-icon btn-edit" 
                                    onclick="openEditModal(<?php echo intval($pi['id']); ?>, '<?php echo htmlspecialchars($pi['ingredient_name']); ?>', <?php echo $pi['quantity_used']; ?>)"
                                    title="Edit">
                                    <i class="fa-solid fa-pen-to-square"></i>
                                </button>
                                <a href="admin_product_ingredients.php?product_id=<?php echo intval($product_id); ?>&delete_ingredient=<?php echo intval($pi['id']); ?>" 
                                   class="btn-icon btn-delete" 
                                   onclick="return confirm('Remove this ingredient from the recipe?');"
                                   title="Delete">
                                    <i class="fa-solid fa-trash"></i>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fa-solid fa-flask-vial"></i>
                    <p>No ingredients added yet</p>
                    <p style="font-size: 0.85rem;">Add ingredients using the form on the left to create your product recipe</p>
                </div>
            <?php endif; ?>
        </main>
    </div>
</div>

<!-- Edit Modal -->
<div class="modal" id="editModal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fa-solid fa-pen-to-square"></i> Edit Ingredient Quantity</h2>
            <button type="button" class="modal-close" onclick="closeEditModal()">&times;</button>
        </div>

        <form method="POST" id="editForm">
            <input type="hidden" name="prod_ingredient_id" id="editProdIngredientId">

            <div class="field">
                <label>Ingredient</label>
                <div class="input-wrapper" style="background: #F0E6D8;">
                    <input type="text" id="editIngredientName" disabled style="color: var(--gray-brown);">
                </div>
            </div>

            <div class="field">
                <label><i class="fa-solid fa-weight"></i> Quantity Used</label>
                <div class="input-wrapper">
                    <input type="number" name="quantity_used" id="editQuantity" min="0.01" step="0.01" required>
                </div>
            </div>

            <div class="modal-actions">
                <button type="button" class="btn-cancel" onclick="closeEditModal()">
                    <i class="fa-solid fa-times"></i> Cancel
                </button>
                <button type="submit" name="update_product_ingredient" class="btn-save">
                    <i class="fa-solid fa-check-circle"></i> Save Changes
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function openEditModal(prodIngId, ingredientName, quantity) {
        document.getElementById('editProdIngredientId').value = prodIngId;
        document.getElementById('editIngredientName').value = ingredientName;
        document.getElementById('editQuantity').value = quantity;
        document.getElementById('editModal').classList.add('show');
        document.getElementById('editQuantity').focus();
    }

    function closeEditModal() {
        document.getElementById('editModal').classList.remove('show');
    }

    document.getElementById('editModal').addEventListener('click', function(e) {
        if (e.target === this) closeEditModal();
    });

    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeEditModal();
    });
</script>

<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>
