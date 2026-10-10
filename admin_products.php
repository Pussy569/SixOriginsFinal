<?php
include 'config.php';
include 'admin_log_activity.php';
require_once __DIR__ . '/mail_helper.php';

// Verify admin session
$admin_id = $_SESSION['admin_id'] ?? null;
if (!isset($admin_id)) {
    header('location:login.php');
    exit;
}

// Security: Generate/validate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function validate_csrf_token() {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die('CSRF token validation failed');
    }
}

// Security: destructive GET links (delete/remove) now also carry & check a token.
// Previously delete/remove_ingredient had NO csrf check at all - fixed here.
function validate_csrf_get() {
    if (!isset($_GET['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_GET['csrf_token'])) {
        die('CSRF token validation failed');
    }
}

// Allowed image extensions
$ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$MAX_FILE_SIZE = 2000000; // 2MB
$UPLOAD_DIR = __DIR__ . '/images/user_uploads/';
$UPLOAD_DB_PREFIX = 'user_uploads/';

// Allowed "size type" categories for a product
$ALLOWED_SIZE_TYPES = [
    'cup'   => 'Cup Size',
    'slice' => 'Slice / Pieces',
];

// Security: Ensure upload directory exists and is protected
if (!is_dir($UPLOAD_DIR)) {
    mkdir($UPLOAD_DIR, 0755, true);
}

// Add .htaccess to prevent execution
$htaccess_content = "php_flag engine off\n";
if (!file_exists($UPLOAD_DIR . '.htaccess')) {
    file_put_contents($UPLOAD_DIR . '.htaccess', $htaccess_content);
}

function resolveProductImagePath($image) {
    $images_root = realpath(__DIR__ . '/images');
    if (!is_string($image) || $image === '' || str_contains(str_replace('\\', '/', $image), '../') || str_starts_with($image, '/')) {
        return null;
    }

    $image_path = realpath(__DIR__ . '/images/' . $image);
    if ($images_root === false || $image_path === false || !str_starts_with($image_path, $images_root . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $image_path;
}

// ==================== ADD PRODUCT ====================
if (isset($_POST['add_product'])) {
    validate_csrf_token();

    try {
        // Input validation and sanitization
        $name = trim($_POST['name'] ?? '');
        $details = trim($_POST['details'] ?? '');
        $size_type = $_POST['size_type'] ?? 'cup';
        if (!array_key_exists($size_type, $ALLOWED_SIZE_TYPES)) {
            $size_type = 'cup';
        }
        $allow_special_instructions = isset($_POST['allow_special_instructions']) ? 1 : 0;
        $submitted_sizes = $_POST['size'] ?? [];
        $submitted_prices = $_POST['size_price'] ?? [];
        $submitted_stock = $_POST['stock'] ?? [];
        if (!is_array($submitted_sizes) || !is_array($submitted_prices) || !is_array($submitted_stock)
            || count($submitted_sizes) < 1 || count($submitted_sizes) > 10
            || count($submitted_sizes) !== count($submitted_prices) || count($submitted_sizes) !== count($submitted_stock)) {
            throw new Exception('Add between 1 and 10 complete size, price, and stock options.');
        }

        $size_variants = [];
        $seen_sizes = [];
        foreach ($submitted_sizes as $index => $submitted_size) {
            if (!is_string($submitted_size) || !is_string($submitted_prices[$index] ?? null) || !is_string($submitted_stock[$index] ?? null)) {
                throw new Exception('Each size, price, and stock value must be a single value.');
            }
            $variant_size = trim((string)$submitted_size);
            $variant_price_raw = trim((string)($submitted_prices[$index] ?? ''));
            $variant_stock_raw = trim((string)($submitted_stock[$index] ?? ''));
            if ($variant_size === '' || mb_strlen($variant_size) > 20) {
                throw new Exception('Each size must have a name no longer than 20 characters.');
            }
            if (isset($seen_sizes[mb_strtolower($variant_size)])) {
                throw new Exception('Size names must be unique for each product.');
            }
            $seen_sizes[mb_strtolower($variant_size)] = true;
            if ($variant_price_raw === '' || !ctype_digit($variant_price_raw) || (float)$variant_price_raw < 1 || (float)$variant_price_raw > 2147483647) {
                throw new Exception('Enter a whole-peso price greater than zero for every size.');
            }
            if ($variant_stock_raw === '' || !ctype_digit($variant_stock_raw) || (float)$variant_stock_raw < 1 || (float)$variant_stock_raw > 2147483647) {
                throw new Exception('Enter a stock quantity greater than zero for every size.');
            }
            $size_variants[] = [
                'size' => $variant_size,
                'price' => (int)$variant_price_raw,
                'stock' => (int)$variant_stock_raw,
            ];
        }
        $price = min(array_column($size_variants, 'price'));

        // Validate required fields
        if (empty($name)) {
            throw new Exception('Product name is required');
        }
        if (mb_strlen($name) > 100) {
            throw new Exception('Product name is too long (max 100 characters)');
        }
        if (mb_strlen($details) > 500) {
            throw new Exception('Description is too long (max 500 characters)');
        }

        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Image upload failed');
        }

        // Validate image file
        $file_name = $_FILES['image']['name'];
        $file_size = $_FILES['image']['size'];
        $file_tmp = $_FILES['image']['tmp_name'];

        // Check file size before processing
        if ($file_size > $MAX_FILE_SIZE) {
            throw new Exception('Image size exceeds 2MB limit');
        }

        // Validate file extension
        $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
        if (!in_array($file_ext, $ALLOWED_EXTENSIONS)) {
            throw new Exception('Invalid image format. Allowed: ' . implode(', ', $ALLOWED_EXTENSIONS));
        }

        // Validate MIME type
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime_type = finfo_file($finfo, $file_tmp);
        finfo_close($finfo);

        $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        if (!in_array($mime_type, $allowed_mimes)) {
            throw new Exception('Invalid MIME type detected');
        }

        // Generate secure filename
        $unique_name = bin2hex(random_bytes(16)) . '.' . $file_ext;
        $image_path = $UPLOAD_DIR . $unique_name;
        $image_db_name = $UPLOAD_DB_PREFIX . $unique_name;

        // Use prepared statement to check for duplicate
        $stmt = $conn->prepare("SELECT id FROM `products` WHERE name = ?");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("s", $name);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            throw new Exception('Product name already exists');
        }
        $stmt->close();

        // Insert product with prepared statement
        $stmt = $conn->prepare("INSERT INTO `products`(name, price, image, details, admin_id, size_type, allow_special_instructions) VALUES(?, ?, ?, ?, ?, ?, ?)");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("sdssisi", $name, $price, $image_db_name, $details, $admin_id, $size_type, $allow_special_instructions);

        if (!$stmt->execute()) {
            throw new Exception("Failed to insert product: " . $stmt->error);
        }

        $product_id = $stmt->insert_id;
        $stmt->close();

        // Move uploaded file
        if (!move_uploaded_file($file_tmp, $image_path)) {
            // Rollback: Delete the inserted product
            $delete_stmt = $conn->prepare("DELETE FROM `products` WHERE id = ?");
            $delete_stmt->bind_param("i", $product_id);
            $delete_stmt->execute();
            $delete_stmt->close();
            throw new Exception('Failed to save image file');
        }

        // Save each size's price and stock as one variant.
        $size_stmt = $conn->prepare("INSERT INTO `product_sizes` (product_id, size, price, stock) VALUES(?, ?, ?, ?)");
        if (!$size_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        foreach ($size_variants as $variant) {
            $size_stmt->bind_param("isii", $product_id, $variant['size'], $variant['price'], $variant['stock']);
            if (!$size_stmt->execute()) {
                throw new Exception("Failed to insert size: " . $size_stmt->error);
            }
        }
        $size_stmt->close();

        log_admin_activity($admin_id, 'Add Product', 'Added new product: ' . $name, 'success', 'product', $product_id, null, ['name' => $name, 'price' => $price]);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Product "' . htmlspecialchars($name) . '" added successfully! You can now add Preferences and Extras from its card. 🎉'];

    } catch (Exception $e) {
        if ($update_transaction_started) {
            $conn->rollback();
        }
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php');
    exit;
}

// ==================== UPDATE STOCK ====================
if (isset($_POST['update_stock'])) {
    validate_csrf_token();

    try {
        $size_id = intval($_POST['size_id'] ?? 0);
        $new_stock = intval($_POST['new_stock']);

        // Validate inputs
        if ($size_id <= 0) {
            throw new Exception('Invalid size selected');
        }
        if ($new_stock < 0) {
            throw new Exception('Invalid stock value');
        }

        // Get product and size info for message
        $info_stmt = $conn->prepare("SELECT ps.size, ps.stock, p.name FROM `product_sizes` ps JOIN products p ON ps.product_id = p.id WHERE ps.id = ?");
        $info_stmt->bind_param("i", $size_id);
        $info_stmt->execute();
        $info_result = $info_stmt->get_result();
        $info_row = $info_result->fetch_assoc();
        $info_stmt->close();
        if (!$info_row) {
            throw new Exception('Product size not found');
        }

        // Update stock by ID
        $stmt = $conn->prepare("UPDATE `product_sizes` SET stock = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("ii", $new_stock, $size_id);

        if (!$stmt->execute()) {
            throw new Exception("Failed to update stock: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Update Product Stock', 'Updated stock for ' . ($info_row['name'] ?? 'Product') . ' (' . ($info_row['size'] ?? '') . ')', 'success', 'product size', $size_id, ['stock' => (int)($info_row['stock'] ?? 0)], ['stock' => $new_stock]);
        $_SESSION['message'] = ['type' => 'success', 'text' => htmlspecialchars($info_row['name'] ?? 'Product') . ' (' . htmlspecialchars($info_row['size'] ?? '') . ') stock updated to ' . $new_stock . ' units! 📦'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php');
    exit;
}

// ==================== DELETE PRODUCT ====================
if (isset($_GET['delete'])) {
    validate_csrf_get();

    try {
        $delete_id = intval($_GET['delete']);

        // Get product name for message
        $name_stmt = $conn->prepare("SELECT name FROM `products` WHERE id = ?");
        $name_stmt->bind_param("i", $delete_id);
        $name_stmt->execute();
        $name_result = $name_stmt->get_result();
        $name_row = $name_result->fetch_assoc();
        $product_name = $name_row['name'] ?? 'Product';
        $name_stmt->close();

        // 1. Remove from all carts
        $cart_del = $conn->prepare("DELETE FROM `cart` WHERE product_id=?");
        $cart_del->bind_param("i", $delete_id);
        $cart_del->execute();
        $cart_del->close();

        // 2. Get all sizes for this product
        $sizes_stmt = $conn->prepare("SELECT size FROM `product_sizes` WHERE product_id=?");
        $sizes_stmt->bind_param("i", $delete_id);
        $sizes_stmt->execute();
        $sizes_result = $sizes_stmt->get_result();
        $sizes = [];
        while ($row = $sizes_result->fetch_assoc()) $sizes[] = $row['size'];
        $sizes_stmt->close();

        // 3. Cancel all orders not completed/cancelled
        $orders = $conn->query("SELECT * FROM `orders` WHERE payment_status NOT IN ('completed', 'cancelled')");
        while ($order = $orders->fetch_assoc()) {
            $order_id = (int)$order['id'];
            $order_total_products = $order['total_products'];
            $user_id_order = $order['user_id'];
            $method = $order['method'];
            $order_total = floatval($order['total_price']);

            $found = false;
            foreach ($sizes as $sz) {
                if (strpos($order_total_products, $sz) !== false) {
                    $found = true;
                    break;
                }
            }
            if ($found || strpos($order_total_products, $delete_id) !== false) {
                $cancel_reason = 'Order cancelled: Product deleted by admin.';
                if(strtolower($order['payment_status']) != 'cancelled') {
                    $stmt = $conn->prepare("UPDATE `orders` SET payment_status='cancelled', cancel_reason=? WHERE id=?");
                    $stmt->bind_param("si", $cancel_reason, $order_id);
                    $stmt->execute();
                    $stmt->close();
                    // Refund wallet if applicable
                    if (strtolower($method) === 'wallet balance') {
                        $rf = $conn->prepare("UPDATE users SET wallet_balance=wallet_balance+? WHERE id=?");
                        $rf->bind_param("di", $order_total, $user_id_order);
                        $rf->execute();
                        $rf->close();
                    }
                }
            }
        }

        // 4. Delete product image
        $stmt = $conn->prepare("SELECT image FROM `products` WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $row = $result->fetch_assoc();
            $image_file = resolveProductImagePath($row['image']);
            if ($image_file !== null) {
                unlink($image_file);
            }
        }
        $stmt->close();

        // 5. Delete product sizes
        $stmt = $conn->prepare("DELETE FROM `product_sizes` WHERE product_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        // 6. Delete linked ingredients
        $stmt = $conn->prepare("DELETE FROM `product_ingredients` WHERE product_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        // 7. Delete linked preference groups (options cascade via FK)
        $stmt = $conn->prepare("DELETE FROM `product_preference_groups` WHERE product_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        // 8. Delete linked extra groups (options cascade via FK)
        $stmt = $conn->prepare("DELETE FROM `product_extra_groups` WHERE product_id = ?");
        $stmt->bind_param("i", $delete_id);
        $stmt->execute();
        $stmt->close();

        // 9. Delete the product
        $stmt = $conn->prepare("DELETE FROM `products` WHERE id = ?");
        $stmt->bind_param("i", $delete_id);
        if ($stmt->execute()) {
            // LOG THE ACTIVITY
            log_admin_activity($admin_id, 'Delete Product', 'Deleted product: ' . $product_name, 'success', 'product', $delete_id, ['name' => $product_name], null);

            $_SESSION['message'] = ['type' => 'success', 'text' => 'Product "' . htmlspecialchars($product_name) . '" deleted successfully with all related carts/orders cancelled and refunds processed! 🗑️'];
        } else {
            throw new Exception("Failed to delete product");
        }
        $stmt->close();

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }
    header('location:admin_products.php');
    exit;
}

// ==================== UPDATE PRODUCT ====================
if (isset($_POST['update_product'])) {
    validate_csrf_token();

    $update_transaction_started = false;
    try {
        $update_p_id = intval($_POST['update_p_id']);
        $update_name = trim($_POST['update_name'] ?? '');
        $posted_variant_prices = $_POST['update_size_prices'] ?? [];
        $posted_variant_active = $_POST['update_size_active'] ?? [];
        $new_sizes = $_POST['new_size'] ?? [];
        $new_size_prices = $_POST['new_size_price'] ?? [];
        $new_size_stocks = $_POST['new_size_stock'] ?? [];
        $update_details = trim($_POST['update_details'] ?? '');
        $update_old_image = $_POST['update_old_image'] ?? '';
        $update_size_type = $_POST['update_size_type'] ?? 'cup';
        if (!array_key_exists($update_size_type, $ALLOWED_SIZE_TYPES)) {
            $update_size_type = 'cup';
        }
        $update_allow_special_instructions = isset($_POST['update_allow_special_instructions']) ? 1 : 0;

        // Validate inputs
        if (empty($update_name)) {
            throw new Exception('Product name is required');
        }
        if (mb_strlen($update_name) > 100) {
            throw new Exception('Product name is too long (max 100 characters)');
        }

        $previous_stmt = $conn->prepare("SELECT name, price, details FROM `products` WHERE id = ?");
        if (!$previous_stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $previous_stmt->bind_param("i", $update_p_id);
        $previous_stmt->execute();
        $previous_product = $previous_stmt->get_result()->fetch_assoc();
        $previous_stmt->close();
        if (!$previous_product) {
            throw new Exception('Product not found');
        }

        if (!is_array($posted_variant_prices) || !is_array($posted_variant_active)
            || !is_array($new_sizes) || !is_array($new_size_prices) || !is_array($new_size_stocks)) {
            throw new Exception('Invalid size price data.');
        }
        $size_lookup = $conn->prepare("SELECT id, size FROM `product_sizes` WHERE product_id = ? ORDER BY id ASC");
        if (!$size_lookup) {
            throw new Exception("Database error loading product sizes: " . $conn->error);
        }
        $size_lookup->bind_param("i", $update_p_id);
        $size_lookup->execute();
        $existing_sizes = $size_lookup->get_result()->fetch_all(MYSQLI_ASSOC);
        $size_lookup->close();
        $existing_size_ids = array_map('intval', array_column($existing_sizes, 'id'));
        if (count($posted_variant_prices) !== count($existing_size_ids) || count($posted_variant_active) !== count($existing_size_ids)) {
            throw new Exception('Provide a price and availability setting for every existing product size.');
        }

        $variant_prices = [];
        $variant_active = [];
        $all_size_names = [];
        foreach ($existing_sizes as $existing_size) {
            $all_size_names[mb_strtolower((string)$existing_size['size'])] = true;
        }
        foreach ($existing_size_ids as $size_id) {
            $raw_variant_price = $posted_variant_prices[$size_id] ?? '';
            if (!is_string($raw_variant_price) || !ctype_digit($raw_variant_price) || (float)$raw_variant_price < 1 || (float)$raw_variant_price > 2147483647) {
                throw new Exception('Enter a whole-peso price greater than zero for every size.');
            }
            $active_value = $posted_variant_active[$size_id] ?? null;
            if (!in_array((string)$active_value, ['0', '1'], true)) {
                throw new Exception('Invalid product size availability value.');
            }
            $variant_prices[$size_id] = (int)$raw_variant_price;
            $variant_active[$size_id] = (int)$active_value;
        }

        if (count($new_sizes) > 10 || count($new_sizes) !== count($new_size_prices) || count($new_sizes) !== count($new_size_stocks)) {
            throw new Exception('Add up to 10 complete new size, price, and stock options.');
        }
        $new_size_variants = [];
        foreach ($new_sizes as $index => $raw_new_size) {
            if (!is_string($raw_new_size) || !is_string($new_size_prices[$index] ?? null) || !is_string($new_size_stocks[$index] ?? null)) {
                throw new Exception('Each new size, price, and stock value must be a single value.');
            }
            $new_size = trim($raw_new_size);
            $new_price = trim($new_size_prices[$index]);
            $new_stock = trim($new_size_stocks[$index]);
            if ($new_size === '' && $new_price === '' && $new_stock === '') {
                continue;
            }
            if ($new_size === '' || mb_strlen($new_size) > 20 || isset($all_size_names[mb_strtolower($new_size)])) {
                throw new Exception('New size names must be unique and no longer than 20 characters.');
            }
            if ($new_price === '' || !ctype_digit($new_price) || (float)$new_price < 1 || (float)$new_price > 2147483647) {
                throw new Exception('Enter a whole-peso price greater than zero for each new size.');
            }
            if ($new_stock === '' || !ctype_digit($new_stock) || (float)$new_stock > 2147483647) {
                throw new Exception('Enter a valid non-negative stock quantity for each new size.');
            }
            $all_size_names[mb_strtolower($new_size)] = true;
            $new_size_variants[] = ['size' => $new_size, 'price' => (int)$new_price, 'stock' => (int)$new_stock];
        }

        $active_price_values = [];
        foreach ($variant_prices as $size_id => $variant_price) {
            if ($variant_active[$size_id] === 1) {
                $active_price_values[] = $variant_price;
            }
        }
        foreach ($new_size_variants as $new_variant) {
            $active_price_values[] = $new_variant['price'];
        }
        if (empty($active_price_values)) {
            throw new Exception('Keep at least one product size available.');
        }
        if (count($active_price_values) > 10) {
            throw new Exception('A product can have at most 10 active sizes.');
        }
        $update_price = !empty($active_price_values) ? min($active_price_values) : (int)$previous_product['price'];

        // Update basic product info
        $conn->begin_transaction();
        $update_transaction_started = true;
        $stmt = $conn->prepare("UPDATE `products` SET name = ?, price = ?, details = ?, size_type = ?, allow_special_instructions = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("sissii", $update_name, $update_price, $update_details, $update_size_type, $update_allow_special_instructions, $update_p_id);
        if (!$stmt->execute()) {
            throw new Exception("Failed to update product: " . $stmt->error);
        }
        $stmt->close();

        if (!empty($variant_prices)) {
            $variant_price_stmt = $conn->prepare("UPDATE `product_sizes` SET price = ?, is_active = ? WHERE id = ? AND product_id = ?");
            if (!$variant_price_stmt) {
                throw new Exception("Database error updating product sizes: " . $conn->error);
            }
            foreach ($variant_prices as $size_id => $variant_price) {
                $variant_price_stmt->bind_param("iiii", $variant_price, $variant_active[$size_id], $size_id, $update_p_id);
                if (!$variant_price_stmt->execute()) {
                    throw new Exception("Failed to update size price: " . $variant_price_stmt->error);
                }
            }
            $variant_price_stmt->close();
        }
        if (!empty($new_size_variants)) {
            $new_size_stmt = $conn->prepare("INSERT INTO product_sizes (product_id, size, price, stock, is_active) VALUES (?, ?, ?, ?, 1)");
            if (!$new_size_stmt) {
                throw new Exception("Database error adding product sizes: " . $conn->error);
            }
            foreach ($new_size_variants as $new_variant) {
                $new_size_stmt->bind_param("isii", $update_p_id, $new_variant['size'], $new_variant['price'], $new_variant['stock']);
                if (!$new_size_stmt->execute()) {
                    throw new Exception("Failed to add product size: " . $new_size_stmt->error);
                }
            }
            $new_size_stmt->close();
        }
        $conn->commit();
        $update_transaction_started = false;

        // LOG THE ACTIVITY
        log_admin_activity($admin_id, 'Update Product', 'Updated product: ' . $update_name, 'success', 'product', $update_p_id, [
            'name' => $previous_product['name'],
            'price' => $previous_product['price'],
            'details' => $previous_product['details'],
        ], [
            'name' => $update_name,
            'price' => $update_price,
            'details' => $update_details,
        ]);

        // Handle image upload if provided
        if (isset($_FILES['update_image']) && $_FILES['update_image']['error'] === UPLOAD_ERR_OK) {
            $file_name = $_FILES['update_image']['name'];
            $file_size = $_FILES['update_image']['size'];
            $file_tmp = $_FILES['update_image']['tmp_name'];

            // Validate file size
            if ($file_size > $MAX_FILE_SIZE) {
                throw new Exception('Image size exceeds 2MB limit');
            }

            // Validate extension
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            if (!in_array($file_ext, $ALLOWED_EXTENSIONS)) {
                throw new Exception('Invalid image format');
            }

            // Validate MIME type
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime_type = finfo_file($finfo, $file_tmp);
            finfo_close($finfo);

            $allowed_mimes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            if (!in_array($mime_type, $allowed_mimes)) {
                throw new Exception('Invalid MIME type detected');
            }

            // Generate new filename
            $unique_name = bin2hex(random_bytes(16)) . '.' . $file_ext;
            $new_image_path = $UPLOAD_DIR . $unique_name;
            $image_db_name = $UPLOAD_DB_PREFIX . $unique_name;

            // Move new file
            if (!move_uploaded_file($file_tmp, $new_image_path)) {
                throw new Exception('Failed to save new image');
            }

            // Update database with new image
            $stmt = $conn->prepare("UPDATE `products` SET image = ? WHERE id = ?");
            $stmt->bind_param("si", $image_db_name, $update_p_id);
            $stmt->execute();
            $stmt->close();

            // Delete old image
            if (!empty($update_old_image)) {
                $old_image_path = resolveProductImagePath($update_old_image);
                if ($old_image_path !== null) {
                    unlink($old_image_path);
                }
            }

            $_SESSION['message'] = ['type' => 'success', 'text' => 'Product "' . htmlspecialchars($update_name) . '" updated with new image! ✨'];
        } else {
            $_SESSION['message'] = ['type' => 'success', 'text' => 'Product "' . htmlspecialchars($update_name) . '" updated successfully! ✨'];
        }

        $recipients_result = $conn->query("SELECT user_id, name, email, total_products FROM `orders`");
        if (!$recipients_result) {
            error_log('Product updated, but previous customers could not be loaded for email notification: ' . $conn->error);
            $_SESSION['message']['text'] .= ' ⚠️ Product updated, but previous customers could not be loaded for email notification.';
        } else {
            $recipients = [];
            $productPattern = '/(?:^|\s*\|\|\s*|,\s*)' . preg_quote($previous_product['name'], '/') . '\s*\(/u';
            while ($order = $recipients_result->fetch_assoc()) {
                if (str_starts_with((string)$order['user_id'], 'guest_') || !filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
                    continue;
                }
                if (preg_match($productPattern, (string)$order['total_products'])) {
                    $recipients[strtolower($order['email'])] = [
                        'name' => (string)$order['name'],
                        'email' => (string)$order['email'],
                    ];
                }
            }
            $recipients_result->free();

            $notified_count = 0;
            foreach ($recipients as $recipient) {
                if (send_product_update_email(
                    $recipient['name'],
                    $recipient['email'],
                    $update_name,
                    $update_price,
                    $update_details
                )) {
                    $notified_count++;
                }
            }

            if ($notified_count === count($recipients) && $notified_count > 0) {
                $_SESSION['message']['text'] .= ' ' . $notified_count . ' previous customer(s) notified by email.';
            } elseif ($notified_count < count($recipients)) {
                $_SESSION['message']['text'] .= ' ⚠️ Email notification sent to ' . $notified_count . ' of ' . count($recipients) . ' previous customer(s).';
            }
        }

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php');
    exit;
}

// ==================== LINK / UPDATE PRODUCT INGREDIENT ====================
// Lets staff manually attach an inventory item (ingredient OR packaging) to a
// product and define how much of it is consumed per unit sold.
if (isset($_POST['add_product_ingredient'])) {
    validate_csrf_token();

    $pi_product_id = intval($_POST['pi_product_id'] ?? 0);

    try {
        $ingredient_id = intval($_POST['ingredient_id'] ?? 0);
        $quantity_used = floatval($_POST['quantity_used'] ?? 0);

        if ($pi_product_id <= 0 || $ingredient_id <= 0) {
            throw new Exception('Invalid product or inventory item selected');
        }
        if ($quantity_used <= 0) {
            throw new Exception('Quantity used must be greater than 0');
        }

        // Confirm product exists
        $check_stmt = $conn->prepare("SELECT name FROM `products` WHERE id = ?");
        $check_stmt->bind_param("i", $pi_product_id);
        $check_stmt->execute();
        $product_row = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();

        // Confirm inventory item exists
        $ing_stmt = $conn->prepare("SELECT ingredient_name, unit, category FROM `inventory` WHERE id = ?");
        $ing_stmt->bind_param("i", $ingredient_id);
        $ing_stmt->execute();
        $ing_row = $ing_stmt->get_result()->fetch_assoc();
        $ing_stmt->close();

        if (!$product_row || !$ing_row) {
            throw new Exception('Product or inventory item not found');
        }
        if (strtolower((string)$ing_row['category']) === 'packaging') {
            throw new Exception('Use the product-option packaging form below to assign packaging to a specific size.');
        }

        $option_link_stmt = $conn->prepare(
            "SELECT psi.id
             FROM product_size_ingredients psi
             JOIN product_sizes ps ON psi.product_size_id = ps.id
             WHERE ps.product_id = ? AND psi.ingredient_id = ?
             LIMIT 1"
        );
        if (!$option_link_stmt) {
            throw new Exception("Database error checking existing option inventory: " . $conn->error);
        }
        $option_link_stmt->bind_param("ii", $pi_product_id, $ingredient_id);
        $option_link_stmt->execute();
        $already_linked_to_option = $option_link_stmt->get_result()->num_rows > 0;
        $option_link_stmt->close();
        if ($already_linked_to_option) {
            throw new Exception('This inventory item is already assigned to a product option. Remove its option assignment first to avoid deducting it twice.');
        }

        // Recipe ingredients are applied to every product option.
        $stmt = $conn->prepare("INSERT INTO `product_ingredients` (product_id, ingredient_id, quantity_used) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE quantity_used = VALUES(quantity_used)");
        if (!$stmt) {
            throw new Exception("Database error: " . $conn->error);
        }
        $stmt->bind_param("iid", $pi_product_id, $ingredient_id, $quantity_used);

        if (!$stmt->execute()) {
            throw new Exception("Failed to link ingredient: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Link Ingredient', $ing_row['ingredient_name'] . ' (' . $quantity_used . ' ' . $ing_row['unit'] . ' per unit) linked to product: ' . $product_row['name']);

        $_SESSION['message'] = ['type' => 'success', 'text' => htmlspecialchars($ing_row['ingredient_name']) . ' linked to "' . htmlspecialchars($product_row['name']) . '" successfully! 🧪'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($pi_product_id > 0 ? '?manage_ingredients=' . $pi_product_id : ''));
    exit;
}

// ==================== LINK PACKAGING TO A PRODUCT SIZE ====================
if (isset($_POST['add_size_packaging'])) {
    validate_csrf_token();
    $sp_product_id = (int)($_POST['sp_product_id'] ?? 0);

    try {
        $product_size_id = (int)($_POST['product_size_id'] ?? 0);
        $ingredient_id = (int)($_POST['size_ingredient_id'] ?? 0);
        $quantity_used = (float)($_POST['size_quantity_used'] ?? 0);

        if ($sp_product_id <= 0 || $product_size_id <= 0 || $ingredient_id <= 0 || $quantity_used <= 0) {
            throw new Exception('Choose a product size, packaging item, and positive quantity.');
        }

        $size_stmt = $conn->prepare(
            "SELECT p.name AS product_name, ps.size
             FROM product_sizes ps JOIN products p ON ps.product_id = p.id
             WHERE ps.id = ? AND ps.product_id = ? AND ps.is_active = 1"
        );
        $size_stmt->bind_param("ii", $product_size_id, $sp_product_id);
        $size_stmt->execute();
        $size_row = $size_stmt->get_result()->fetch_assoc();
        $size_stmt->close();
        if (!$size_row) {
            throw new Exception('The selected product size is unavailable.');
        }

        $item_stmt = $conn->prepare("SELECT ingredient_name, unit FROM inventory WHERE id = ?");
        $item_stmt->bind_param("i", $ingredient_id);
        $item_stmt->execute();
        $item_row = $item_stmt->get_result()->fetch_assoc();
        $item_stmt->close();
        if (!$item_row) {
            throw new Exception('Choose an existing inventory item.');
        }

        $global_link_stmt = $conn->prepare(
            "SELECT id FROM product_ingredients WHERE product_id = ? AND ingredient_id = ? LIMIT 1"
        );
        if (!$global_link_stmt) {
            throw new Exception("Database error checking existing inventory mapping: " . $conn->error);
        }
        $global_link_stmt->bind_param("ii", $sp_product_id, $ingredient_id);
        $global_link_stmt->execute();
        $already_linked_globally = $global_link_stmt->get_result()->num_rows > 0;
        $global_link_stmt->close();
        if ($already_linked_globally) {
            throw new Exception('This inventory item is already linked to every option. Remove its product-wide link first to avoid deducting it twice.');
        }

        $link_stmt = $conn->prepare(
            "INSERT INTO product_size_ingredients (product_size_id, ingredient_id, quantity_used)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE quantity_used = VALUES(quantity_used)"
        );
        $link_stmt->bind_param("iid", $product_size_id, $ingredient_id, $quantity_used);
        $link_stmt->execute();
        $link_stmt->close();

        log_admin_activity(
            $admin_id,
            'Link Option Inventory',
            $item_row['ingredient_name'] . ' (' . $quantity_used . ' ' . $item_row['unit'] . ') linked to ' . $size_row['product_name'] . ' - ' . $size_row['size']
        );
        $_SESSION['message'] = ['type' => 'success', 'text' => htmlspecialchars($item_row['ingredient_name']) . ' will be deducted only when ' . htmlspecialchars($size_row['size']) . ' is purchased.'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($sp_product_id > 0 ? '?manage_packaging=' . $sp_product_id : ''));
    exit;
}

// ==================== REMOVE SIZE-SPECIFIC PACKAGING ====================
if (isset($_GET['remove_size_packaging'])) {
    validate_csrf_get();
    $back_product_id = (int)($_GET['manage_packaging'] ?? $_GET['manage_ingredients'] ?? 0);
    $return_to_packaging = isset($_GET['manage_packaging']);

    try {
        $link_id = (int)$_GET['remove_size_packaging'];
        $info_stmt = $conn->prepare(
            "SELECT psi.id, p.id AS product_id, p.name AS product_name, ps.size, i.ingredient_name
             FROM product_size_ingredients psi
             JOIN product_sizes ps ON psi.product_size_id = ps.id
             JOIN products p ON ps.product_id = p.id
             JOIN inventory i ON psi.ingredient_id = i.id
             WHERE psi.id = ?"
        );
        $info_stmt->bind_param("i", $link_id);
        $info_stmt->execute();
        $info = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();
        if (!$info) {
            throw new Exception('Product-option inventory mapping not found.');
        }

        $delete_stmt = $conn->prepare("DELETE FROM product_size_ingredients WHERE id = ?");
        $delete_stmt->bind_param("i", $link_id);
        $delete_stmt->execute();
        $delete_stmt->close();
        if ($back_product_id <= 0) {
            $back_product_id = (int)$info['product_id'];
        }
        log_admin_activity($admin_id, 'Unlink Option Inventory', 'Removed ' . $info['ingredient_name'] . ' from ' . $info['product_name'] . ' - ' . $info['size']);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Product-option inventory mapping removed.'];
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    $return_view = $return_to_packaging ? 'manage_packaging' : 'manage_ingredients';
    header('location:admin_products.php' . ($back_product_id > 0 ? '?' . $return_view . '=' . $back_product_id : ''));
    exit;
}

// ==================== REMOVE PRODUCT INGREDIENT ====================
if (isset($_GET['remove_ingredient'])) {
    validate_csrf_get();
    $back_product_id = intval($_GET['manage_ingredients'] ?? 0);

    try {
        $pi_id = intval($_GET['remove_ingredient']);

        // Get info for logging/message before deleting
        $info_stmt = $conn->prepare("SELECT pi.product_id, p.name AS product_name, i.ingredient_name FROM `product_ingredients` pi JOIN `products` p ON pi.product_id = p.id JOIN `inventory` i ON pi.ingredient_id = i.id WHERE pi.id = ?");
        $info_stmt->bind_param("i", $pi_id);
        $info_stmt->execute();
        $info_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare("DELETE FROM `product_ingredients` WHERE id = ?");
        $stmt->bind_param("i", $pi_id);

        if (!$stmt->execute()) {
            throw new Exception('Failed to remove ingredient link');
        }
        $stmt->close();

        if ($info_row) {
            if ($back_product_id <= 0) {
                $back_product_id = (int)$info_row['product_id'];
            }
            // LOG THE ACTIVITY
            log_admin_activity($admin_id, 'Unlink Ingredient', 'Removed ' . $info_row['ingredient_name'] . ' from product: ' . $info_row['product_name']);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Ingredient removed from product successfully!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($back_product_id > 0 ? '?manage_ingredients=' . $back_product_id : ''));
    exit;
}

// ==================== ADD PREFERENCE TITLE ====================
// A "Preference" is a title (e.g. "Sugar Level", "Milk Type") with a set of
// selectable options underneath it. No price attached - purely a choice.
if (isset($_POST['add_preference_group'])) {
    validate_csrf_token();
    $pg_product_id = intval($_POST['pg_product_id'] ?? 0);

    try {
        $title = trim($_POST['preference_title'] ?? '');

        if ($pg_product_id <= 0) {
            throw new Exception('Invalid product selected');
        }
        if (empty($title)) {
            throw new Exception('Preference title is required');
        }
        if (mb_strlen($title) > 100) {
            throw new Exception('Preference title is too long (max 100 characters)');
        }

        $check_stmt = $conn->prepare("SELECT name FROM `products` WHERE id = ?");
        $check_stmt->bind_param("i", $pg_product_id);
        $check_stmt->execute();
        $product_row = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        if (!$product_row) {
            throw new Exception('Product not found');
        }

        $stmt = $conn->prepare("INSERT INTO `product_preference_groups` (product_id, title) VALUES (?, ?)");
        $stmt->bind_param("is", $pg_product_id, $title);
        if (!$stmt->execute()) {
            throw new Exception("Failed to create preference title: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Add Preference Title', 'Added preference "' . $title . '" to product: ' . $product_row['name']);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Preference title "' . htmlspecialchars($title) . '" created! 🎛️'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($pg_product_id > 0 ? '?manage_preferences=' . $pg_product_id : ''));
    exit;
}

// ==================== ADD PREFERENCE OPTION ====================
if (isset($_POST['add_preference_option'])) {
    validate_csrf_token();
    $po_product_id = intval($_POST['po_product_id'] ?? 0);

    try {
        $group_id = intval($_POST['preference_group_id'] ?? 0);
        $option_name = trim($_POST['preference_option_name'] ?? '');

        if ($group_id <= 0) {
            throw new Exception('Please choose a preference title first');
        }
        if (empty($option_name)) {
            throw new Exception('Option name is required');
        }
        if (mb_strlen($option_name) > 100) {
            throw new Exception('Option name is too long (max 100 characters)');
        }

        $group_stmt = $conn->prepare("SELECT product_id, title FROM `product_preference_groups` WHERE id = ?");
        $group_stmt->bind_param("i", $group_id);
        $group_stmt->execute();
        $group_row = $group_stmt->get_result()->fetch_assoc();
        $group_stmt->close();
        if (!$group_row) {
            throw new Exception('Preference title not found');
        }
        if ($po_product_id <= 0) {
            $po_product_id = (int)$group_row['product_id'];
        }

        $stmt = $conn->prepare("INSERT INTO `product_preference_options` (group_id, option_name) VALUES (?, ?)");
        $stmt->bind_param("is", $group_id, $option_name);
        if (!$stmt->execute()) {
            throw new Exception("Failed to add option: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Add Preference Option', 'Added option "' . $option_name . '" under "' . $group_row['title'] . '"');
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Option "' . htmlspecialchars($option_name) . '" added! 🎛️'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($po_product_id > 0 ? '?manage_preferences=' . $po_product_id : ''));
    exit;
}

// ==================== REMOVE PREFERENCE OPTION ====================
if (isset($_GET['remove_preference_option'])) {
    validate_csrf_get();
    $back_pref_product_id = intval($_GET['manage_preferences'] ?? 0);

    try {
        $opt_id = intval($_GET['remove_preference_option']);

        $info_stmt = $conn->prepare("SELECT po.option_name, pg.product_id FROM `product_preference_options` po JOIN `product_preference_groups` pg ON po.group_id = pg.id WHERE po.id = ?");
        $info_stmt->bind_param("i", $opt_id);
        $info_stmt->execute();
        $info_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare("DELETE FROM `product_preference_options` WHERE id = ?");
        $stmt->bind_param("i", $opt_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to remove option');
        }
        $stmt->close();

        if ($info_row) {
            if ($back_pref_product_id <= 0) {
                $back_pref_product_id = (int)$info_row['product_id'];
            }
            log_admin_activity($admin_id, 'Remove Preference Option', 'Removed option: ' . $info_row['option_name']);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Preference option removed!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($back_pref_product_id > 0 ? '?manage_preferences=' . $back_pref_product_id : ''));
    exit;
}

// ==================== REMOVE PREFERENCE TITLE ====================
if (isset($_GET['remove_preference_group'])) {
    validate_csrf_get();
    $back_pref_product_id = intval($_GET['manage_preferences'] ?? 0);

    try {
        $grp_id = intval($_GET['remove_preference_group']);

        $info_stmt = $conn->prepare("SELECT title, product_id FROM `product_preference_groups` WHERE id = ?");
        $info_stmt->bind_param("i", $grp_id);
        $info_stmt->execute();
        $info_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare("DELETE FROM `product_preference_groups` WHERE id = ?");
        $stmt->bind_param("i", $grp_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to remove preference title');
        }
        $stmt->close();

        if ($info_row) {
            if ($back_pref_product_id <= 0) {
                $back_pref_product_id = (int)$info_row['product_id'];
            }
            log_admin_activity($admin_id, 'Remove Preference Title', 'Removed preference title: ' . $info_row['title']);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Preference title and its options removed!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($back_pref_product_id > 0 ? '?manage_preferences=' . $back_pref_product_id : ''));
    exit;
}

// ==================== ADD EXTRA TITLE ====================
// An "Extra" is a title (e.g. "Add-ons", "Toppings") whose options each carry
// their own admin-set price, added on top of the base product price.
if (isset($_POST['add_extra_group'])) {
    validate_csrf_token();
    $eg_product_id = intval($_POST['eg_product_id'] ?? 0);

    try {
        $title = trim($_POST['extra_title'] ?? '');

        if ($eg_product_id <= 0) {
            throw new Exception('Invalid product selected');
        }
        if (empty($title)) {
            throw new Exception('Extra title is required');
        }
        if (mb_strlen($title) > 100) {
            throw new Exception('Extra title is too long (max 100 characters)');
        }

        $check_stmt = $conn->prepare("SELECT name FROM `products` WHERE id = ?");
        $check_stmt->bind_param("i", $eg_product_id);
        $check_stmt->execute();
        $product_row = $check_stmt->get_result()->fetch_assoc();
        $check_stmt->close();
        if (!$product_row) {
            throw new Exception('Product not found');
        }

        $stmt = $conn->prepare("INSERT INTO `product_extra_groups` (product_id, title) VALUES (?, ?)");
        $stmt->bind_param("is", $eg_product_id, $title);
        if (!$stmt->execute()) {
            throw new Exception("Failed to create extra title: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Add Extra Title', 'Added extra "' . $title . '" to product: ' . $product_row['name']);
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Extra title "' . htmlspecialchars($title) . '" created! ➕'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($eg_product_id > 0 ? '?manage_extras=' . $eg_product_id : ''));
    exit;
}

// ==================== ADD EXTRA OPTION ====================
if (isset($_POST['add_extra_option'])) {
    validate_csrf_token();
    $eo_product_id = intval($_POST['eo_product_id'] ?? 0);

    try {
        $group_id = intval($_POST['extra_group_id'] ?? 0);
        $option_name = trim($_POST['extra_option_name'] ?? '');
        $option_price = isset($_POST['extra_option_price']) && $_POST['extra_option_price'] !== ''
            ? floatval($_POST['extra_option_price']) : -1;

        if ($group_id <= 0) {
            throw new Exception('Please choose an extra title first');
        }
        if (empty($option_name)) {
            throw new Exception('Option name is required');
        }
        if (mb_strlen($option_name) > 100) {
            throw new Exception('Option name is too long (max 100 characters)');
        }
        if ($option_price < 0) {
            throw new Exception('Please enter a valid price (0 or more)');
        }

        $group_stmt = $conn->prepare("SELECT product_id, title FROM `product_extra_groups` WHERE id = ?");
        $group_stmt->bind_param("i", $group_id);
        $group_stmt->execute();
        $group_row = $group_stmt->get_result()->fetch_assoc();
        $group_stmt->close();
        if (!$group_row) {
            throw new Exception('Extra title not found');
        }
        if ($eo_product_id <= 0) {
            $eo_product_id = (int)$group_row['product_id'];
        }

        $stmt = $conn->prepare("INSERT INTO `product_extra_options` (group_id, option_name, price) VALUES (?, ?, ?)");
        $stmt->bind_param("isd", $group_id, $option_name, $option_price);
        if (!$stmt->execute()) {
            throw new Exception("Failed to add option: " . $stmt->error);
        }
        $stmt->close();

        log_admin_activity($admin_id, 'Add Extra Option', 'Added option "' . $option_name . '" (₱' . number_format($option_price, 2) . ') under "' . $group_row['title'] . '"');
        $_SESSION['message'] = ['type' => 'success', 'text' => 'Extra option "' . htmlspecialchars($option_name) . '" added! ➕'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($eo_product_id > 0 ? '?manage_extras=' . $eo_product_id : ''));
    exit;
}

// ==================== REMOVE EXTRA OPTION ====================
if (isset($_GET['remove_extra_option'])) {
    validate_csrf_get();
    $back_extra_product_id = intval($_GET['manage_extras'] ?? 0);

    try {
        $opt_id = intval($_GET['remove_extra_option']);

        $info_stmt = $conn->prepare("SELECT eo.option_name, eg.product_id FROM `product_extra_options` eo JOIN `product_extra_groups` eg ON eo.group_id = eg.id WHERE eo.id = ?");
        $info_stmt->bind_param("i", $opt_id);
        $info_stmt->execute();
        $info_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare("DELETE FROM `product_extra_options` WHERE id = ?");
        $stmt->bind_param("i", $opt_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to remove option');
        }
        $stmt->close();

        if ($info_row) {
            if ($back_extra_product_id <= 0) {
                $back_extra_product_id = (int)$info_row['product_id'];
            }
            log_admin_activity($admin_id, 'Remove Extra Option', 'Removed option: ' . $info_row['option_name']);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Extra option removed!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($back_extra_product_id > 0 ? '?manage_extras=' . $back_extra_product_id : ''));
    exit;
}

// ==================== REMOVE EXTRA TITLE ====================
if (isset($_GET['remove_extra_group'])) {
    validate_csrf_get();
    $back_extra_product_id = intval($_GET['manage_extras'] ?? 0);

    try {
        $grp_id = intval($_GET['remove_extra_group']);

        $info_stmt = $conn->prepare("SELECT title, product_id FROM `product_extra_groups` WHERE id = ?");
        $info_stmt->bind_param("i", $grp_id);
        $info_stmt->execute();
        $info_row = $info_stmt->get_result()->fetch_assoc();
        $info_stmt->close();

        $stmt = $conn->prepare("DELETE FROM `product_extra_groups` WHERE id = ?");
        $stmt->bind_param("i", $grp_id);
        if (!$stmt->execute()) {
            throw new Exception('Failed to remove extra title');
        }
        $stmt->close();

        if ($info_row) {
            if ($back_extra_product_id <= 0) {
                $back_extra_product_id = (int)$info_row['product_id'];
            }
            log_admin_activity($admin_id, 'Remove Extra Title', 'Removed extra title: ' . $info_row['title']);
        }

        $_SESSION['message'] = ['type' => 'success', 'text' => 'Extra title and its options removed!'];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_products.php' . ($back_extra_product_id > 0 ? '?manage_extras=' . $back_extra_product_id : ''));
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0">
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <title>Manage Products — Six Origins Admin</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <link rel="icon" type="image/png" href="images/logos.png">
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

      html {
         scroll-behavior: smooth;
      }

      body {
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         color: var(--dark-brown);
         min-height: 100vh;
         width: 100%;
         overflow-x: hidden;
      }

      .container {
         max-width: 1800px;
         margin: 0 auto;
         padding: 28px 24px 60px;
         width: 100%;
      }

      /* Page Header */
      .page-header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         gap: 20px;
         margin-bottom: 28px;
         flex-wrap: wrap;
         animation: slideDown 0.4s ease;
      }

      @keyframes slideDown {
         from { opacity: 0; transform: translateY(-20px); }
         to { opacity: 1; transform: translateY(0); }
      }

      @keyframes slideUp {
         from { opacity: 0; transform: translateY(20px); }
         to { opacity: 1; transform: translateY(0); }
      }

      .page-header h1 {
         font-size: 2.2rem;
         color: var(--dark-brown);
         font-weight: 900;
         letter-spacing: -0.5px;
      }

      .page-header .sub {
         color: var(--gray-brown);
         font-size: 1rem;
         margin-top: 6px;
         font-weight: 500;
      }

      /* Alerts */
      .alert {
         padding: 16px 20px;
         border-radius: var(--radius);
         margin-bottom: 24px;
         display: flex;
         gap: 12px;
         align-items: flex-start;
         animation: slideDown 0.4s ease;
         border-left: 4px solid;
         font-weight: 600;
      }

      .alert i {
         font-size: 1.2rem;
         flex-shrink: 0;
         margin-top: 2px;
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

      .alert.warning {
         background: linear-gradient(135deg, rgba(180, 83, 9, 0.1) 0%, rgba(180, 83, 9, 0.05) 100%);
         color: #b45309;
         border-color: #b45309;
      }

      /* Main Layout */
      .main-layout {
         display: grid;
         grid-template-columns: minmax(320px, 1fr) minmax(500px, 2fr);
         gap: 32px;
         align-items: start;
      }

      /* Left Panel - Form */
      .form-panel {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         padding: 32px;
         box-shadow: var(--shadow);
         border: 1.5px solid #F0E6D8;
         position: sticky;
         top: 120px;
         animation: slideUp 0.4s ease;
         height: fit-content;
         max-height: calc(100vh - 200px);
         overflow-y: auto;
      }

      .form-panel:hover {
         box-shadow: var(--shadow-hover);
         border-color: var(--primary-red);
      }

      .form-panel::-webkit-scrollbar {
         width: 6px;
      }

      .form-panel::-webkit-scrollbar-track {
         background: rgba(198, 69, 62, 0.1);
         border-radius: 10px;
      }

      .form-panel::-webkit-scrollbar-thumb {
         background: rgba(198, 69, 62, 0.3);
         border-radius: 10px;
      }

      .form-panel::-webkit-scrollbar-thumb:hover {
         background: rgba(198, 69, 62, 0.5);
      }

      .form-panel h2 {
         font-size: 1.5rem;
         color: var(--dark-brown);
         font-weight: 900;
         margin-bottom: 24px;
         display: flex;
         align-items: center;
         gap: 10px;
      }

      .form-panel h2 i {
         font-size: 1.8rem;
         color: var(--primary-red);
      }

      .field {
         margin-bottom: 20px;
      }

      .field-row {
         display: grid;
         grid-template-columns: 1fr 1fr;
         gap: 12px;
      }

      label {
         display: block;
         font-size: 0.95rem;
         color: var(--dark-brown);
         margin-bottom: 8px;
         font-weight: 700;
         letter-spacing: 0.3px;
      }

      label i {
         color: var(--primary-red);
         font-size: 1rem;
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
      input[type="file"],
      select,
      textarea {
         border: 0;
         outline: 0;
         background: transparent;
         width: 100%;
         font-size: 1rem;
         color: var(--dark-brown);
         font-weight: 500;
         font-family: inherit;
      }

      input[type="file"]::file-selector-button {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         border: none;
         padding: 8px 14px;
         border-radius: 6px;
         cursor: pointer;
         font-weight: 700;
         transition: var(--transition);
      }

      input[type="file"]::file-selector-button:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      }

      textarea {
         min-height: 100px;
         resize: vertical;
         font-family: inherit;
         line-height: 1.5;
      }

      .help-text {
         color: var(--gray-brown);
         font-size: 0.85rem;
         margin-top: 6px;
         display: flex;
         align-items: center;
         gap: 4px;
         font-weight: 500;
      }

      /* Image Preview Container */
      .image-preview-container {
         margin-top: 12px;
         display: none;
         text-align: center;
         animation: slideUp 0.3s ease;
      }

      .image-preview-container.show {
         display: block;
      }

      .image-preview-container img {
         width: 100%;
         height: auto;
         max-height: 200px;
         object-fit: cover;
         border-radius: 10px;
         border: 2px solid var(--primary-red);
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.3);
         transition: var(--transition);
      }

      .image-preview-container p {
         font-size: 0.85rem;
         color: var(--gray-brown);
         margin-top: 8px;
         font-weight: 600;
      }

      .image-preview-container .remove-preview {
         margin-top: 8px;
         padding: 6px 12px;
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #D97E6A;
         border: 1px solid rgba(217, 126, 106, 0.2);
         border-radius: 6px;
         cursor: pointer;
         font-size: 0.85rem;
         font-weight: 700;
         transition: var(--transition);
      }

      .image-preview-container .remove-preview:hover {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
         color: #fff;
         border-color: #D97E6A;
      }

      .size-stock-container {
         display: flex;
         flex-direction: column;
         gap: 10px;
         margin-bottom: 12px;
      }

      .size-stock-row {
         display: grid;
         grid-template-columns: minmax(0, 1.2fr) minmax(0, 1fr) minmax(0, 1fr);
         gap: 10px;
      }

      .size-stock-row .input-wrapper {
         padding: 10px 12px;
      }

      .size-price-edit-list {
         display: flex;
         flex-direction: column;
         gap: 8px;
      }

      .size-price-edit-row {
         display: grid;
         grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
         align-items: center;
         gap: 10px;
         color: var(--dark-brown);
         font-weight: 700;
      }

      .size-price-edit-row .input-wrapper {
         padding: 8px 10px;
      }

      .button-group {
         display: flex;
         gap: 8px;
         margin-bottom: 16px;
      }

      .btn {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
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
         text-decoration: none;
         position: relative;
         overflow: hidden;
         white-space: nowrap;
      }

      .btn::before {
         content: '';
         position: absolute;
         top: 0;
         left: -100%;
         width: 100%;
         height: 100%;
         background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
         transition: left 0.5s ease;
      }

      .btn:hover::before {
         left: 100%;
      }

      .btn:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         transform: translateY(-2px);
         box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
      }

      .btn.secondary {
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         border: 1.5px solid #F0E6D8;
         color: var(--dark-brown);
         box-shadow: 0 2px 6px rgba(94, 31, 19, 0.05);
      }

      .btn.secondary:hover {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         border-color: var(--primary-red);
      }

      .btn.add-product {
         width: 100%;
         padding: 14px;
         font-size: 0.95rem;
         border-radius: var(--radius);
      }

      /* Right Panel - Products */
      .products-panel {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         padding: 32px;
         box-shadow: var(--shadow);
         border: 1.5px solid #F0E6D8;
         animation: slideUp 0.4s ease 0.1s backwards;
      }

      .products-panel h2 {
         font-size: 1.5rem;
         color: var(--dark-brown);
         font-weight: 900;
         margin-bottom: 8px;
         display: flex;
         align-items: center;
         gap: 10px;
      }

      .products-panel h2 i {
         font-size: 1.8rem;
         color: var(--primary-red);
      }

      .catalog-info {
         color: var(--gray-brown);
         font-size: 0.95rem;
         margin-bottom: 24px;
         font-weight: 500;
      }

      .catalog-info strong {
         color: var(--dark-brown);
         font-weight: 800;
      }

      /* Product Grid */
      .products-grid {
         display: grid;
         grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
         gap: 24px;
      }

      .product-card {
         background: white;
         border-radius: var(--radius);
         overflow: hidden;
         box-shadow: 0 4px 12px rgba(94, 31, 19, 0.08);
         transition: var(--transition);
         border: 1.5px solid #F0E6D8;
         animation: slideUp 0.4s ease;
         display: flex;
         flex-direction: column;
      }

      .product-card:hover {
         box-shadow: var(--shadow-hover);
         border-color: var(--primary-red);
         transform: translateY(-8px);
      }

      .product-img-container {
         position: relative;
         width: 100%;
         height: 240px;
         overflow: hidden;
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
      }

      .product-card img {
         width: 100%;
         height: 100%;
         object-fit: cover;
         transition: transform 0.4s ease;
      }

      .product-card:hover img {
         transform: scale(1.1);
      }

      .available-badge {
         position: absolute;
         top: 12px;
         right: 12px;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         padding: 6px 14px;
         border-radius: 20px;
         font-size: 0.8rem;
         font-weight: 700;
         display: flex;
         align-items: center;
         gap: 4px;
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
      }

      .low-stock-badge {
         position: absolute;
         top: 12px;
         right: 12px;
         background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
         color: #fff;
         padding: 6px 14px;
         border-radius: 20px;
         font-size: 0.8rem;
         font-weight: 700;
         display: flex;
         align-items: center;
         gap: 4px;
         box-shadow: 0 4px 12px rgba(239, 68, 68, 0.3);
      }

      .product-details {
         padding: 20px;
         flex: 1;
         display: flex;
         flex-direction: column;
      }

      .product-title {
         font-size: 1.15rem;
         font-weight: 900;
         color: var(--dark-brown);
         margin-bottom: 8px;
         line-height: 1.3;
      }

      .product-price {
         font-size: 1.3rem;
         font-weight: 900;
         color: var(--primary-red);
         margin-bottom: 10px;
         display: flex;
         align-items: center;
         gap: 4px;
      }

      .product-description {
         font-size: 0.9rem;
         color: var(--gray-brown);
         margin-bottom: 12px;
         line-height: 1.5;
         max-height: 2.7em;
         overflow: hidden;
         text-overflow: ellipsis;
         display: -webkit-box;
         -webkit-line-clamp: 2;
         -webkit-box-orient: vertical;
      }

      .sizes-list {
         display: flex;
         flex-wrap: wrap;
         gap: 6px;
         margin: 12px 0;
         padding: 12px 0;
         border-top: 1px solid #F0E6D8;
         border-bottom: 1px solid #F0E6D8;
      }

      .size-badge {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: var(--dark-brown);
         padding: 5px 12px;
         border-radius: 8px;
         font-size: 0.8rem;
         font-weight: 700;
         border: 1px solid rgba(198, 69, 62, 0.2);
      }

      .size-badge.low-stock {
         background: linear-gradient(135deg, rgba(239, 68, 68, 0.12) 0%, rgba(239, 68, 68, 0.06) 100%);
         color: #ef4444;
         border-color: rgba(239, 68, 68, 0.2);
      }

      .size-badge.type-badge {
         background: linear-gradient(135deg, rgba(94, 31, 19, 0.1) 0%, rgba(94, 31, 19, 0.05) 100%);
         border-color: rgba(94, 31, 19, 0.2);
      }

      /* Ingredients / preferences / extras summary strips on card */
      .ingredients-summary {
         display: flex;
         align-items: center;
         flex-wrap: wrap;
         gap: 6px;
         font-size: 0.78rem;
         color: var(--gray-brown);
         font-weight: 600;
         margin-bottom: 10px;
      }

      .ingredients-summary i {
         color: var(--primary-red);
      }

      .ingredients-summary .none {
         color: #C93353;
         font-weight: 700;
      }

      .product-actions {
         display: flex;
         flex-wrap: wrap;
         gap: 8px;
         margin-top: auto;
      }

      .product-actions a,
      .product-actions button {
         flex: 1 1 auto;
         padding: 12px;
         border-radius: 8px;
         text-decoration: none;
         font-weight: 800;
         font-size: 0.9rem;
         transition: var(--transition);
         display: flex;
         align-items: center;
         justify-content: center;
         gap: 6px;
         border: none;
         cursor: pointer;
      }

      .product-actions a.edit {
         background: linear-gradient(135deg, #0369a1 0%, #0284c7 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(3, 105, 161, 0.2);
      }

      .product-actions a.edit:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         transform: translateY(-2px);
         box-shadow: 0 4px 12px rgba(94, 31, 19, 0.2);
      }

      .product-actions button.stock {
         background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(139, 92, 246, 0.2);
      }

      .product-actions button.stock:hover {
         background: linear-gradient(135deg, #6d28d9 0%, #5b21b6 100%);
         transform: translateY(-2px);
         box-shadow: 0 4px 12px rgba(139, 92, 246, 0.3);
      }

      .product-actions a.ingredients {
         background: linear-gradient(135deg, #059669 0%, #047857 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(5, 150, 105, 0.2);
      }

      .product-actions a.ingredients:hover {
         background: linear-gradient(135deg, #065f46 0%, #064e3b 100%);
         transform: translateY(-2px);
         box-shadow: 0 4px 12px rgba(5, 150, 105, 0.3);
      }

      .product-actions a.packaging {
         background: linear-gradient(135deg, #0e7490 0%, #155e75 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(14, 116, 144, 0.2);
      }

      .product-actions a.packaging:hover {
         background: linear-gradient(135deg, #164e63 0%, #083344 100%);
         transform: translateY(-2px);
         box-shadow: 0 4px 12px rgba(14, 116, 144, 0.3);
      }

      /* Reused existing palette colors (--gray-brown / --dark-brown / warning amber
         already used for .alert.warning) so no new hues are introduced */
      .product-actions a.preferences {
         background: linear-gradient(135deg, var(--gray-brown) 0%, #4a3733 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(102, 76, 71, 0.25);
      }

      .product-actions a.preferences:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         transform: translateY(-2px);
      }

      .product-actions a.extras {
         background: linear-gradient(135deg, #b45309 0%, #92400e 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(180, 83, 9, 0.25);
      }

      .product-actions a.extras:hover {
         background: linear-gradient(135deg, #78350f 0%, #5c2a0a 100%);
         transform: translateY(-2px);
      }

      .product-actions a.delete {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #D97E6A;
         border: 1.5px solid rgba(217, 126, 106, 0.2);
      }

      .product-actions a.delete:hover {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
         color: #fff;
         border-color: #D97E6A;
         transform: translateY(-2px);
      }

      /* Empty State */
      .empty-state {
         text-align: center;
         padding: 80px 40px;
         color: var(--gray-brown);
      }

      .empty-state i {
         font-size: 4rem;
         margin-bottom: 20px;
         opacity: 0.4;
      }

      .empty-state h4 {
         color: var(--dark-brown);
         font-size: 1.3rem;
         margin-bottom: 10px;
         font-weight: 700;
      }

      .empty-state p {
         font-size: 1rem;
         line-height: 1.6;
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
         animation: fadeIn 0.3s ease;
      }

      .modal.show {
         display: flex;
      }

      @keyframes fadeIn {
         from { opacity: 0; }
         to { opacity: 1; }
      }

      .modal-content {
         width: 100%;
         max-width: 700px;
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         padding: 32px;
         box-shadow: var(--shadow-hover);
         border: 1.5px solid #F0E6D8;
         max-height: 90vh;
         overflow-y: auto;
         animation: slideUp 0.3s ease;
      }

      .modal-content::-webkit-scrollbar {
         width: 8px;
      }

      .modal-content::-webkit-scrollbar-track {
         background: rgba(198, 69, 62, 0.1);
      }

      .modal-content::-webkit-scrollbar-thumb {
         background: rgba(198, 69, 62, 0.3);
         border-radius: 10px;
      }

      .modal-content::-webkit-scrollbar-thumb:hover {
         background: rgba(198, 69, 62, 0.5);
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
         font-size: 1.6rem;
         color: var(--dark-brown);
         font-weight: 900;
         display: flex;
         align-items: center;
         gap: 10px;
      }

      .modal-header h2 i {
         color: var(--primary-red);
         font-size: 1.8rem;
      }

      .modal-header.stock h2 i {
         color: #8b5cf6;
      }

      .modal-header.ingredients h2 i {
         color: #059669;
      }

      .modal-header.preferences h2 i {
         color: var(--gray-brown);
      }

      .modal-header.extras h2 i {
         color: #b45309;
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

      .modal img {
         width: 100%;
         height: 320px;
         object-fit: cover;
         border-radius: 12px;
         margin-bottom: 24px;
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
      }

      .modal-actions {
         display: flex;
         gap: 12px;
         margin-top: 28px;
         justify-content: flex-end;
         flex-wrap: wrap;
      }

      .stock-info {
         background: linear-gradient(135deg, rgba(139, 92, 246, 0.1) 0%, rgba(139, 92, 246, 0.05) 100%);
         padding: 14px;
         border-radius: 10px;
         border-left: 4px solid #8b5cf6;
         margin-bottom: 20px;
      }

      .stock-info p {
         font-size: 0.95rem;
         color: var(--dark-brown);
         margin: 4px 0;
         font-weight: 600;
      }

      .stock-info .current-stock {
         font-size: 1.8rem;
         color: #8b5cf6;
         font-weight: 900;
      }

      /* Ingredients / Preferences / Extras modal shared bits */
      .ingredients-product-tag {
         display: flex;
         align-items: center;
         gap: 8px;
         font-weight: 700;
         color: var(--dark-brown);
         background: linear-gradient(135deg, rgba(5, 150, 105, 0.1) 0%, rgba(5, 150, 105, 0.05) 100%);
         border-left: 4px solid #059669;
         padding: 12px 14px;
         border-radius: 10px;
         margin-bottom: 20px;
      }

      .ingredients-product-tag i {
         color: #059669;
      }

      .linked-ingredients-list {
         display: flex;
         flex-direction: column;
         gap: 10px;
         margin-bottom: 24px;
      }

      .ingredient-item-row {
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 12px;
         padding: 12px 14px;
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
         border: 1.5px solid #F0E6D8;
         border-radius: 10px;
      }

      .ingredient-item-info {
         display: flex;
         flex-direction: column;
         gap: 2px;
      }

      .ingredient-item-name {
         font-weight: 800;
         color: var(--dark-brown);
         font-size: 0.95rem;
      }

      .ingredient-item-meta {
         font-size: 0.8rem;
         color: var(--gray-brown);
         font-weight: 600;
      }

      .ingredient-item-meta .cat-tag {
         display: inline-block;
         background: rgba(5, 150, 105, 0.12);
         color: #047857;
         padding: 1px 8px;
         border-radius: 20px;
         font-weight: 700;
         font-size: 0.72rem;
         margin-right: 6px;
      }

      .remove-ingredient-btn {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #D97E6A;
         border: 1px solid rgba(217, 126, 106, 0.2);
         border-radius: 8px;
         padding: 8px 12px;
         cursor: pointer;
         font-weight: 700;
         font-size: 0.85rem;
         text-decoration: none;
         display: flex;
         align-items: center;
         gap: 6px;
         transition: var(--transition);
         flex-shrink: 0;
         white-space: nowrap;
      }

      .remove-ingredient-btn:hover {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
         color: #fff;
         border-color: #D97E6A;
      }

      .no-ingredients-msg {
         text-align: center;
         padding: 24px;
         color: var(--gray-brown);
         font-weight: 600;
         font-size: 0.9rem;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         border-radius: 10px;
         border: 1.5px dashed #F0E6D8;
      }

      .ingredients-section-label {
         font-size: 0.85rem;
         font-weight: 800;
         text-transform: uppercase;
         letter-spacing: 0.4px;
         color: var(--primary-red);
         margin-bottom: 12px;
         display: flex;
         align-items: center;
         gap: 8px;
      }

      .all-linked-msg {
         text-align: center;
         color: var(--gray-brown);
         font-weight: 600;
         padding: 14px 0;
         font-size: 0.9rem;
      }

      .all-linked-msg i {
         color: #059669;
      }

      /* Preference / Extra title blocks (mirrors ingredient-item-row styling) */
      .group-block {
         border: 1.5px solid #F0E6D8;
         border-radius: 10px;
         padding: 16px;
         margin-bottom: 14px;
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
      }

      .group-block-header {
         display: flex;
         justify-content: space-between;
         align-items: center;
         flex-wrap: wrap;
         gap: 10px;
         margin-bottom: 12px;
      }

      .group-block-header strong {
         font-size: 1rem;
         color: var(--dark-brown);
         font-weight: 800;
         display: flex;
         align-items: center;
         gap: 6px;
      }

      .option-chip {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
         border: 1px solid rgba(198, 69, 62, 0.2);
         color: var(--dark-brown);
         padding: 6px 10px 6px 14px;
         border-radius: 20px;
         font-size: 0.85rem;
         font-weight: 700;
         margin: 4px 6px 4px 0;
      }

      .option-chip .chip-remove {
         background: none;
         border: none;
         color: #D97E6A;
         cursor: pointer;
         font-weight: 900;
         font-size: 0.95rem;
         line-height: 1;
         padding: 2px;
         text-decoration: none;
      }

      .option-chip .chip-remove:hover {
         color: #C93353;
      }

      .checkbox-field {
         display: flex;
         align-items: center;
         gap: 8px;
         cursor: pointer;
      }

      .checkbox-field input[type="checkbox"] {
         width: auto;
         accent-color: var(--primary-red);
      }

      /* Responsive */
      @media (max-width: 1400px) {
         .container {
            max-width: 1400px;
         }

         .main-layout {
            grid-template-columns: 280px 1fr;
            gap: 28px;
         }

         .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
         }
      }

      @media (max-width: 1100px) {
         .main-layout {
            grid-template-columns: 1fr;
         }

         .form-panel {
            position: relative;
            top: 0;
            max-height: none;
         }

         .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
         }
      }

      @media (max-width: 768px) {
         .container {
            padding: 20px 14px 48px;
         }

         .page-header {
            flex-direction: column;
            align-items: flex-start;
         }

         .page-header h1 {
            font-size: 1.8rem;
         }

         .products-grid {
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
         }

         .form-panel,
         .products-panel {
            padding: 24px;
         }

         .form-panel h2,
         .products-panel h2 {
            font-size: 1.3rem;
         }

         .product-img-container {
            height: 200px;
         }

         .field-row {
            grid-template-columns: 1fr;
         }

         .button-group {
            grid-template-columns: 1fr 1fr;
         }

         .modal-content {
            padding: 24px;
         }

         .modal img {
            height: 260px;
         }
      }

      @media (max-width: 640px) {
         .container {
            padding: 18px 12px 36px;
         }

         .page-header h1 {
            font-size: 1.5rem;
         }

         .products-grid {
            grid-template-columns: 1fr;
            gap: 14px;
         }

         .form-panel,
         .products-panel {
            padding: 18px;
         }

         .form-panel h2,
         .products-panel h2 {
            font-size: 1.2rem;
         }

         .form-panel h2 i,
         .products-panel h2 i {
            font-size: 1.4rem;
         }

         label {
            font-size: 0.9rem;
         }

         .input-wrapper {
            padding: 10px 12px;
         }

         input[type="text"],
         input[type="number"],
         textarea,
         select {
            font-size: 0.95rem;
         }

         textarea {
            min-height: 80px;
         }

         .size-stock-row { grid-template-columns: 1fr; }

         .button-group {
            flex-direction: column;
         }

         .button-group .btn {
            width: 100%;
         }

         .btn.add-product {
            padding: 12px;
         }

         .product-title {
            font-size: 1rem;
         }

         .product-price {
            font-size: 1.15rem;
         }

         .product-actions {
            gap: 6px;
         }

         .product-actions a,
         .product-actions button {
            padding: 10px;
            font-size: 0.8rem;
            gap: 4px;
            flex: 1 1 45%;
         }

         .modal-content {
            padding: 18px;
            max-height: 85vh;
         }

         .modal-header h2 {
            font-size: 1.3rem;
         }

         .modal-actions {
            flex-direction: column-reverse;
            gap: 10px;
         }

         .modal-actions .btn {
            width: 100%;
         }

         .modal img {
            height: 200px;
         }
      }

      @media (max-width: 480px) {
         .container {
            padding: 16px 10px 32px;
         }

         .page-header h1 {
            font-size: 1.3rem;
         }

         .page-header .sub {
            font-size: 0.9rem;
         }

         .products-grid {
            gap: 12px;
         }

         .form-panel,
         .products-panel {
            padding: 16px;
         }

         .form-panel h2,
         .products-panel h2 {
            font-size: 1.1rem;
         }

         .product-card {
            animation: none;
         }

         .product-img-container {
            height: 180px;
         }

         .product-details {
            padding: 14px;
         }

         .product-title {
            font-size: 0.95rem;
            margin-bottom: 6px;
         }

         .product-price {
            font-size: 1rem;
         }

         .product-description {
            font-size: 0.85rem;
            -webkit-line-clamp: 1;
         }

         .sizes-list {
            gap: 4px;
            margin: 8px 0;
            padding: 8px 0;
         }

         .size-badge {
            padding: 4px 10px;
            font-size: 0.75rem;
         }

         .product-actions {
            gap: 4px;
         }

         .product-actions a,
         .product-actions button {
            padding: 8px;
            font-size: 0.75rem;
            flex: 1 1 45%;
         }

         .product-actions a i,
         .product-actions button i {
            display: none;
         }

         .alert {
            padding: 12px 14px;
            font-size: 0.9em;
         }

         .catalog-info {
            font-size: 0.9rem;
         }

         .field {
            margin-bottom: 16px;
         }

         .input-wrapper {
            padding: 9px 11px;
         }

         label i {
            font-size: 0.9rem;
         }

         .help-text {
            font-size: 0.8rem;
         }

         .image-preview-container img {
            max-height: 150px;
         }
      }

      @media (max-width: 360px) {
         .page-header h1 {
            font-size: 1.2rem;
         }

         .form-panel,
         .products-panel {
            padding: 14px;
         }

         .modal-content {
            padding: 16px;
         }

         .modal img {
            height: 160px;
         }
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="container">
   <!-- Page Header -->
   <div class="page-header">
      <div>
         <h1>Manage Products</h1>
         <div class="sub">Add, edit, and organize your coffee catalog</div>
      </div>
   </div>

   <!-- Alerts -->
   <?php
      if(isset($_SESSION['message'])){
         $msg = $_SESSION['message'];
         $icon = match($msg['type']) {
            'success' => 'fa-check-circle',
            'error' => 'fa-circle-exclamation',
            'warning' => 'fa-triangle-exclamation',
            default => 'fa-info-circle'
         };
         echo '<div class="alert '.$msg['type'].'"><i class="fa-solid '.$icon.'"></i><div>'.htmlspecialchars($msg['text']).'</div></div>';
         unset($_SESSION['message']);
      }
   ?>

   <div class="main-layout">
      <!-- Left Panel - Add Product Form -->
      <aside class="form-panel">
         <h2><i class="fa-solid fa-plus-circle"></i> Add New Product</h2>

         <form action="" method="post" enctype="multipart/form-data" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">

            <div class="field">
               <label><i class="fa-solid fa-heading"></i> Product Name</label>
               <div class="input-wrapper">
                  <input type="text" name="name" placeholder="e.g., Premium Espresso" required minlength="3" maxlength="100">
               </div>
               <div class="help-text"><i class="fa-solid fa-info-circle"></i> Must be unique</div>
            </div>

            <div class="field">
               <label><i class="fa-solid fa-image"></i> Image</label>
               <div class="input-wrapper">
                  <input type="file" id="imageInput" name="image" accept="image/*" required>
               </div>
            </div>

            <!-- Image Preview -->
            <div class="image-preview-container" id="imagePreviewContainer">
               <img id="imagePreview" src="" alt="Image preview">
               <p>✓ Image ready to upload</p>
               <button type="button" class="remove-preview" onclick="removeImagePreview()">Remove Image</button>
            </div>

            <div class="field">
               <label><i class="fa-solid fa-align-left"></i> Description</label>
               <div class="input-wrapper">
                  <textarea name="details" placeholder="Describe your product..." maxlength="500"></textarea>
               </div>
               <div class="help-text"><i class="fa-solid fa-lightbulb"></i> Ingredients, notes, features</div>
            </div>

            <div class="field">
               <label><i class="fa-solid fa-layer-group"></i> Size Type</label>
               <div class="input-wrapper">
                  <select name="size_type" id="sizeTypeSelect" onchange="updateSizeTypeUI()">
                     <option value="cup">☕ Cup Size (Coffee &amp; Drinks)</option>
                     <option value="slice">🍰 Slice / Pieces (Cakes &amp; Cookies)</option>
                  </select>
               </div>
               <div class="help-text"><i class="fa-solid fa-info-circle"></i> Controls what the size list below means for this product</div>
            </div>

            <div class="field">
               <label id="sizeStockLabel"><i class="fa-solid fa-ruler"></i> Product Options, Prices &amp; Stock</label>
               <div class="size-stock-container" id="sizeStockContainer">
                  <div class="size-stock-row">
                     <div class="input-wrapper">
                        <input type="text" name="size[]" class="size-name-input" placeholder="e.g., Small, 1 Slice, Whole Cake" maxlength="20" required>
                     </div>
                     <div class="input-wrapper">
                        <input type="number" name="size_price[]" min="1" step="1" placeholder="Price (₱)" required>
                     </div>
                     <div class="input-wrapper">
                        <input type="number" name="stock[]" min="1" placeholder="Stock" required>
                     </div>
                  </div>
               </div>
               <div class="button-group">
                  <button type="button" class="btn secondary" onclick="addSizeStock()">
                     <i class="fa-solid fa-plus"></i> Add Size
                  </button>
                  <button type="button" class="btn secondary" onclick="removeLastSize()">
                     <i class="fa-solid fa-minus"></i> Remove
                  </button>
               </div>
               <div class="help-text"><i class="fa-solid fa-info-circle"></i> Use any names that fit this product; sizes do not have to be Small/Medium/Large or cup sizes. Packaging is assigned manually per option in Manage Ingredients.</div>
            </div>

            <div class="field">
               <label class="checkbox-field">
                  <input type="checkbox" name="allow_special_instructions" value="1" checked>
                  <span><i class="fa-solid fa-comment-dots"></i> Allow customers to add special instructions</span>
               </label>
               <div class="help-text"><i class="fa-solid fa-info-circle"></i> Lets a customer leave a note for the barista, e.g. "less ice", "no whipped cream"</div>
            </div>

            <button type="submit" name="add_product" class="btn add-product">
               <i class="fa-solid fa-check-circle"></i> Add Product
            </button>

            <div class="help-text" style="margin-top:12px; justify-content:center; text-align:center;">
               <i class="fa-solid fa-circle-info"></i>&nbsp;Preferences &amp; Extras are added from the product card after creating it
            </div>
         </form>
      </aside>

      <!-- Right Panel - Products List -->
      <main class="products-panel">
         <h2><i class="fa-solid fa-boxes-stacked"></i> Product Catalog</h2>
         <div class="catalog-info">
            <?php
               $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM products");
               $stmt->execute();
               $result = $stmt->get_result();
               $count = $result->fetch_assoc()['cnt'];
               $stmt->close();
               echo '<strong>'.$count.'</strong> product'.($count != 1 ? 's' : '').' in catalog';
            ?>
         </div>

         <?php
            $stmt = $conn->prepare("SELECT * FROM `products` ORDER BY id DESC");
            $stmt->execute();
            $select_products = $stmt->get_result();

            if($select_products->num_rows > 0){
         ?>
         <div class="products-grid">
            <?php
               while($product = $select_products->fetch_assoc()){
                  $sizes_stmt = $conn->prepare("SELECT id, size, price, stock, is_active FROM `product_sizes` WHERE product_id = ? ORDER BY id ASC");
                  $sizes_stmt->bind_param("i", $product['id']);
                  $sizes_stmt->execute();
                  $sizes_result = $sizes_stmt->get_result();
                  $sizes = [];
                  $total_stock = 0;
                  $has_low_stock = false;
                  while($size = $sizes_result->fetch_assoc()){
                     $sizes[] = $size;
                     if ((int)$size['is_active'] === 1) {
                        $total_stock += $size['stock'];
                     }
                     if ((int)$size['is_active'] === 1 && $size['stock'] <= 10) {
                        $has_low_stock = true;
                     }
                  }
                  $sizes_stmt->close();

                  // Count linked ingredients for the summary strip
                  $ic_stmt = $conn->prepare(
                     "SELECT
                        (SELECT COUNT(*) FROM product_ingredients WHERE product_id = ?) +
                        (SELECT COUNT(*) FROM product_size_ingredients psi
                         JOIN product_sizes ps ON psi.product_size_id = ps.id
                         WHERE ps.product_id = ?) AS cnt"
                  );
                  $ic_stmt->bind_param("ii", $product['id'], $product['id']);
                  $ic_stmt->execute();
                  $ingredient_count = $ic_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                  $ic_stmt->close();

                  // Count preference titles
                  $pc_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM `product_preference_groups` WHERE product_id = ?");
                  $pc_stmt->bind_param("i", $product['id']);
                  $pc_stmt->execute();
                  $preference_count = $pc_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                  $pc_stmt->close();

                  // Count extra titles
                  $ec_stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM `product_extra_groups` WHERE product_id = ?");
                  $ec_stmt->bind_param("i", $product['id']);
                  $ec_stmt->execute();
                  $extra_count = $ec_stmt->get_result()->fetch_assoc()['cnt'] ?? 0;
                  $ec_stmt->close();

                  $current_size_type = $product['size_type'] ?? 'cup';
                  $size_type_label = $ALLOWED_SIZE_TYPES[$current_size_type] ?? 'Cup Size';
                  $size_type_icon = $current_size_type === 'slice' ? 'fa-cake-candles' : 'fa-mug-hot';
            ?>
            <div class="product-card">
               <div class="product-img-container">
                  <img src="images/<?php echo htmlspecialchars($product['image']); ?>" alt="<?php echo htmlspecialchars($product['name']); ?>" loading="lazy">
                  <?php if ($has_low_stock): ?>
                     <div class="low-stock-badge"><i class="fa-solid fa-exclamation-triangle"></i> Low Stock</div>
                  <?php else: ?>
                     <div class="available-badge"><i class="fa-solid fa-check"></i> Available</div>
                  <?php endif; ?>
               </div>

               <div class="product-details">
                  <h3 class="product-title"><?php echo htmlspecialchars($product['name']); ?></h3>
                  <?php $starting_price = !empty($sizes) ? min(array_column($sizes, 'price')) : (int)$product['price']; ?>
                  <div class="product-price">
                     <i class="fa-solid fa-peso-sign"></i> From <?php echo number_format($starting_price, 2); ?>
                  </div>

                  <?php if(!empty($product['details'])): ?>
                     <div class="product-description"><?php echo htmlspecialchars($product['details']); ?></div>
                  <?php endif; ?>

                  <div class="sizes-list">
                     <span class="size-badge type-badge"><i class="fa-solid <?php echo $size_type_icon; ?>"></i> <?php echo htmlspecialchars($size_type_label); ?></span>
                     <?php foreach($sizes as $size): ?>
                        <span class="size-badge <?php echo $size['stock'] <= 10 ? 'low-stock' : ''; ?>">
                           <?php echo htmlspecialchars($size['size']); ?> — ₱<?php echo number_format((float)$size['price'], 2); ?> (<?php echo intval($size['stock']); ?>)<?php if (!(int)$size['is_active']): ?> — hidden<?php endif; ?>
                        </span>
                     <?php endforeach; ?>
                  </div>

                  <div class="ingredients-summary">
                     <?php if ($ingredient_count > 0): ?>
                        <i class="fa-solid fa-flask"></i> <?php echo intval($ingredient_count); ?> inventory mapping<?php echo $ingredient_count != 1 ? 's' : ''; ?> linked
                     <?php else: ?>
                        <i class="fa-solid fa-triangle-exclamation" style="color:#C93353;"></i> <span class="none">No inventory items linked yet</span>
                     <?php endif; ?>
                  </div>

                  <div class="ingredients-summary">
                     <i class="fa-solid fa-sliders"></i> <?php echo intval($preference_count); ?> preference title<?php echo $preference_count != 1 ? 's' : ''; ?>
                     <span>•</span>
                     <i class="fa-solid fa-plus"></i> <?php echo intval($extra_count); ?> extra title<?php echo $extra_count != 1 ? 's' : ''; ?>
                     <span>•</span>
                     <i class="fa-solid fa-comment-dots"></i> <?php echo !empty($product['allow_special_instructions']) ? 'Instructions on' : 'Instructions off'; ?>
                  </div>

                  <div class="product-actions">
                     <button type="button" class="stock" onclick="openStockModal(<?php echo intval($product['id']); ?>, '<?php echo htmlspecialchars(json_encode($sizes)); ?>')" title="Update Stock">
                        <i class="fa-solid fa-boxes"></i> Stock
                     </button>
                     <a class="preferences" href="admin_products.php?manage_preferences=<?php echo intval($product['id']); ?>" title="Manage Preferences">
                        <i class="fa-solid fa-sliders"></i> Preferences
                     </a>
                     <a class="extras" href="admin_products.php?manage_extras=<?php echo intval($product['id']); ?>" title="Manage Extras">
                        <i class="fa-solid fa-plus"></i> Extras
                     </a>
                     <a class="ingredients" href="admin_products.php?manage_ingredients=<?php echo intval($product['id']); ?>" title="Manage Ingredients">
                        <i class="fa-solid fa-flask"></i> Ingredients
                     </a>
                     <a class="packaging" href="admin_products.php?manage_packaging=<?php echo intval($product['id']); ?>" title="Manage Size-Specific Packaging">
                        <i class="fa-solid fa-box-open"></i> Packaging
                     </a>
                     <a class="edit" href="admin_products.php?update=<?php echo intval($product['id']); ?>" title="Edit">
                        <i class="fa-solid fa-edit"></i> Edit
                     </a>
                     <a class="delete" href="admin_products.php?delete=<?php echo intval($product['id']); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>" onclick="return confirm('Delete this product?');" title="Delete">
                        <i class="fa-solid fa-trash"></i> Delete
                     </a>
                  </div>
               </div>
            </div>
            <?php
               }
            ?>
         </div>
         <?php
            $stmt->close();
            }else{
         ?>
         <div class="empty-state">
            <i class="fa-solid fa-inbox"></i>
            <h4>No Products Yet</h4>
            <p>Create your first product using the form on the left to get started!</p>
         </div>
         <?php
            }
         ?>
      </main>
   </div>
</div>

<!-- Edit Product Modal -->
<?php
   if(isset($_GET['update'])){
      $update_id = intval($_GET['update']);
      $stmt = $conn->prepare("SELECT * FROM `products` WHERE id = ?");
      $stmt->bind_param("i", $update_id);
      $stmt->execute();
      $update_query = $stmt->get_result();

      if($update_query->num_rows > 0){
         $product = $update_query->fetch_assoc();
         $stmt->close();
         $edit_size_type = $product['size_type'] ?? 'cup';
         $edit_sizes_stmt = $conn->prepare("SELECT id, size, price, is_active FROM `product_sizes` WHERE product_id = ? ORDER BY id ASC");
         $edit_sizes_stmt->bind_param("i", $update_id);
         $edit_sizes_stmt->execute();
         $edit_sizes = $edit_sizes_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
         $edit_sizes_stmt->close();
?>
<div class="modal show" id="editModal">
   <div class="modal-content">
      <div class="modal-header">
         <h2><i class="fa-solid fa-edit"></i> Edit Product</h2>
         <a href="admin_products.php" class="modal-close" title="Close">×</a>
      </div>

      <img src="images/<?php echo htmlspecialchars($product['image']); ?>" alt="Product image" loading="lazy">

      <form action="" method="post" enctype="multipart/form-data" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="update_p_id" value="<?php echo intval($product['id']); ?>">
         <input type="hidden" name="update_old_image" value="<?php echo htmlspecialchars($product['image']); ?>">

         <div class="field">
            <label><i class="fa-solid fa-heading"></i> Product Name</label>
            <div class="input-wrapper">
               <input type="text" name="update_name" value="<?php echo htmlspecialchars($product['name']); ?>" required minlength="3" maxlength="100">
            </div>
         </div>

         <div class="field">
            <label><i class="fa-solid fa-image"></i> Replace Image</label>
            <div class="input-wrapper">
               <input type="file" id="updateImageInput" name="update_image" accept="image/*">
            </div>
         </div>

         <div class="field">
            <label><i class="fa-solid fa-peso-sign"></i> Prices by Size</label>
            <div class="size-price-edit-list">
               <?php foreach ($edit_sizes as $edit_size): ?>
                  <div class="size-price-edit-row">
                     <span><?php echo htmlspecialchars($edit_size['size']); ?><?php if (!(int)$edit_size['is_active']): ?> <small>(hidden)</small><?php endif; ?></span>
                     <div class="input-wrapper">
                        <input type="number" name="update_size_prices[<?php echo (int)$edit_size['id']; ?>]" min="1" step="1" value="<?php echo (int)$edit_size['price']; ?>" required aria-label="Price for <?php echo htmlspecialchars($edit_size['size']); ?>">
                     </div>
                     <select name="update_size_active[<?php echo (int)$edit_size['id']; ?>]" aria-label="Availability for <?php echo htmlspecialchars($edit_size['size']); ?>">
                        <option value="1" <?php echo (int)$edit_size['is_active'] === 1 ? 'selected' : ''; ?>>Available</option>
                        <option value="0" <?php echo (int)$edit_size['is_active'] === 0 ? 'selected' : ''; ?>>Hidden</option>
                     </select>
                  </div>
               <?php endforeach; ?>
            </div>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Set prices and hide/unhide existing sizes. Hidden sizes stay in order history and can be restored later.</div>
            <label style="margin-top: 18px;">Add New Sizes</label>
            <div id="newSizeFields">
               <div class="field-row new-size-row" style="grid-template-columns: repeat(3, minmax(0, 1fr));">
                  <div class="input-wrapper"><input type="text" name="new_size[0]" maxlength="20" placeholder="e.g., 1 Slice or Whole Cake"></div>
                  <div class="input-wrapper"><input type="number" name="new_size_price[0]" min="1" step="1" placeholder="Price"></div>
                  <div class="input-wrapper"><input type="number" name="new_size_stock[0]" min="0" step="1" placeholder="Starting stock"></div>
               </div>
            </div>
            <button type="button" class="btn btn-secondary" onclick="addNewSizeFields()">Add another size</button>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Enter any product-specific option name (not only cup sizes). New options are available immediately; choose their packaging manually in Manage Ingredients.</div>
         </div>

         <!-- Update Image Preview -->
         <div class="image-preview-container" id="updateImagePreviewContainer">
            <img id="updateImagePreview" src="" alt="Image preview">
            <p>✓ Image ready to upload</p>
            <button type="button" class="remove-preview" onclick="removeUpdateImagePreview()">Remove Image</button>
         </div>

         <div class="field">
            <label><i class="fa-solid fa-align-left"></i> Description</label>
            <div class="input-wrapper">
               <textarea name="update_details" maxlength="500"><?php echo htmlspecialchars($product['details']); ?></textarea>
            </div>
         </div>

         <div class="field">
            <label><i class="fa-solid fa-layer-group"></i> Size Type</label>
            <div class="input-wrapper">
               <select name="update_size_type">
                  <option value="cup" <?php echo $edit_size_type === 'cup' ? 'selected' : ''; ?>>☕ Cup Size (Coffee &amp; Drinks)</option>
                  <option value="slice" <?php echo $edit_size_type === 'slice' ? 'selected' : ''; ?>>🍰 Slice / Pieces (Cakes &amp; Cookies)</option>
               </select>
            </div>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Use the "Stock" button on the card to update quantities for existing sizes</div>
         </div>

         <div class="field">
            <label class="checkbox-field">
               <input type="checkbox" name="update_allow_special_instructions" value="1" <?php echo !empty($product['allow_special_instructions']) ? 'checked' : ''; ?>>
               <span><i class="fa-solid fa-comment-dots"></i> Allow customers to add special instructions</span>
            </label>
         </div>

         <div class="modal-actions">
            <a href="admin_products.php" class="btn secondary">
               <i class="fa-solid fa-times"></i> Cancel
            </a>
            <button type="submit" name="update_product" class="btn">
               <i class="fa-solid fa-check-circle"></i> Save Changes
            </button>
         </div>
      </form>
   </div>
</div>
<?php
      } else {
         $stmt->close();
      }
   }
?>

<!-- Manage Ingredients Modal -->
<?php
   $mi_product = null;
   $manage_packaging_only = isset($_GET['manage_packaging']);
   if (isset($_GET['manage_ingredients']) || $manage_packaging_only) {
      $mi_product_id = (int)($manage_packaging_only ? $_GET['manage_packaging'] : $_GET['manage_ingredients']);
      $mi_stmt = $conn->prepare("SELECT * FROM `products` WHERE id = ?");
      $mi_stmt->bind_param("i", $mi_product_id);
      $mi_stmt->execute();
      $mi_result = $mi_stmt->get_result();

      if ($mi_result->num_rows > 0) {
         $mi_product = $mi_result->fetch_assoc();
         $mi_stmt->close();

         // Ingredients / packaging already linked to this product
         $linked_stmt = $conn->prepare("SELECT pi.id, pi.quantity_used, i.id AS ingredient_id, i.ingredient_name, i.unit, i.category FROM `product_ingredients` pi JOIN `inventory` i ON pi.ingredient_id = i.id WHERE pi.product_id = ? ORDER BY i.category ASC, i.ingredient_name ASC");
         $linked_stmt->bind_param("i", $mi_product_id);
         $linked_stmt->execute();
         $linked_result = $linked_stmt->get_result();
         $linked_ingredients = [];
         $linked_ids = [];
         while ($row = $linked_result->fetch_assoc()) {
            $linked_ingredients[] = $row;
            $linked_ids[] = (int)$row['ingredient_id'];
         }
         $linked_stmt->close();

         // Inventory items not yet linked to this product (available to add)
         $inv_stmt = $conn->prepare("SELECT id, ingredient_name, category, unit, quantity FROM `inventory` ORDER BY category ASC, ingredient_name ASC");
         $inv_stmt->execute();
         $inv_result = $inv_stmt->get_result();
         $available_inventory = [];
         while ($row = $inv_result->fetch_assoc()) {
            if (strtolower((string)$row['category']) !== 'packaging' && !in_array((int)$row['id'], $linked_ids, true)) {
               $available_inventory[] = $row;
            }
         }
         $inv_stmt->close();

         $option_link_ids_stmt = $conn->prepare(
            "SELECT DISTINCT psi.ingredient_id
             FROM product_size_ingredients psi
             JOIN product_sizes ps ON psi.product_size_id = ps.id
             WHERE ps.product_id = ?"
         );
         $option_link_ids_stmt->bind_param("i", $mi_product_id);
         $option_link_ids_stmt->execute();
         $option_linked_ids = array_map('intval', array_column($option_link_ids_stmt->get_result()->fetch_all(MYSQLI_ASSOC), 'ingredient_id'));
         $option_link_ids_stmt->close();
         $available_inventory = array_values(array_filter(
            $available_inventory,
            static fn($item) => !in_array((int)$item['id'], $option_linked_ids, true)
         ));

         $option_inventory_stmt = $conn->prepare("SELECT id, ingredient_name, category, unit, quantity FROM inventory ORDER BY category ASC, ingredient_name ASC");
         $option_inventory_stmt->execute();
         $option_inventory = $option_inventory_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
         $option_inventory_stmt->close();

         $size_stmt = $conn->prepare("SELECT id, size FROM product_sizes WHERE product_id = ? AND is_active = 1 ORDER BY id ASC");
         $size_stmt->bind_param("i", $mi_product_id);
         $size_stmt->execute();
         $product_size_options = $size_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
         $size_stmt->close();

         $size_packaging_stmt = $conn->prepare(
            "SELECT psi.id, psi.quantity_used, ps.size, i.ingredient_name, i.unit, i.category
             FROM product_size_ingredients psi
             JOIN product_sizes ps ON psi.product_size_id = ps.id
             JOIN inventory i ON psi.ingredient_id = i.id
             WHERE ps.product_id = ?
             ORDER BY ps.id, i.ingredient_name"
         );
         $size_packaging_stmt->bind_param("i", $mi_product_id);
         $size_packaging_stmt->execute();
         $size_packaging = $size_packaging_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
         $size_packaging_stmt->close();
      } else {
         $mi_stmt->close();
      }
   }

   if ($mi_product !== null && !$manage_packaging_only):
?>
<div class="modal show" id="ingredientsModal">
   <div class="modal-content">
      <div class="modal-header ingredients">
         <h2><i class="fa-solid fa-flask"></i> Manage Ingredients</h2>
         <a href="admin_products.php" class="modal-close" title="Close">×</a>
      </div>

      <div class="ingredients-product-tag">
         <i class="fa-solid fa-box"></i> <?php echo htmlspecialchars($mi_product['name']); ?>
      </div>

      <div class="ingredients-section-label">
         <i class="fa-solid fa-link"></i> Inventory Currently Deducted for Every Option
      </div>
      <div class="linked-ingredients-list">
         <?php if (!empty($linked_ingredients)): ?>
            <?php foreach ($linked_ingredients as $li): ?>
            <div class="ingredient-item-row">
               <div class="ingredient-item-info">
                  <span class="ingredient-item-name"><?php echo htmlspecialchars($li['ingredient_name']); ?></span>
                  <span class="ingredient-item-meta">
                     <span class="cat-tag"><?php echo htmlspecialchars(strtolower($li['category']) === 'drinks' ? 'Consumable (legacy)' : ucfirst($li['category'])); ?></span>
                     Uses <?php echo number_format($li['quantity_used'], 2); ?> <?php echo htmlspecialchars($li['unit']); ?> per unit sold
                     <?php if (strtolower((string)$li['category']) === 'packaging'): ?><strong>Legacy global link: applies to every size. Remove it after setting size-specific packaging below.</strong><?php endif; ?>
                  </span>
               </div>
               <a href="admin_products.php?remove_ingredient=<?php echo intval($li['id']); ?>&manage_ingredients=<?php echo intval($mi_product_id); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                  class="remove-ingredient-btn"
                  onclick="return confirm('Remove this ingredient from the product?');" title="Remove">
                  <i class="fa-solid fa-trash"></i> Remove
               </a>
            </div>
            <?php endforeach; ?>
         <?php else: ?>
            <div class="no-ingredients-msg">
               <i class="fa-solid fa-flask" style="font-size:1.5rem; opacity:0.4; display:block; margin-bottom:8px;"></i>
               No ingredients linked to this product yet. Stock won't be able to auto-deduct until you add some below.
            </div>
         <?php endif; ?>
      </div>

      <div class="ingredients-section-label">
         <i class="fa-solid fa-flask"></i> Add Recipe Ingredient to Every Option
      </div>

      <?php if (!empty($available_inventory)): ?>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="pi_product_id" value="<?php echo intval($mi_product_id); ?>">

         <div class="field">
            <label><i class="fa-solid fa-cubes"></i> Inventory Item</label>
            <div class="input-wrapper">
               <select name="ingredient_id" id="ingredientSelect" required onchange="updateIngredientUnit()">
                  <option value="">Choose from inventory...</option>
                  <?php
                     $current_cat = null;
                     foreach ($available_inventory as $inv) {
                        if ($inv['category'] !== $current_cat) {
                           if ($current_cat !== null) echo '</optgroup>';
                           echo '<optgroup label="' . htmlspecialchars(ucfirst((string)$inv['category'])) . '">';
                           $current_cat = $inv['category'];
                        }
                        echo '<option value="' . intval($inv['id']) . '" data-unit="' . htmlspecialchars($inv['unit']) . '">'
                           . htmlspecialchars($inv['ingredient_name']) . ' (' . htmlspecialchars($inv['unit']) . ', '
                           . number_format($inv['quantity'], 2) . ' in stock)</option>';
                     }
                     if ($current_cat !== null) echo '</optgroup>';
                  ?>
               </select>
            </div>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Recipe ingredients apply to each sale of every option. Add cups, containers, or other option-specific stock in the section below.</div>
         </div>

         <div class="field">
            <label><i class="fa-solid fa-weight-scale"></i> Quantity Used Per Item Sold</label>
            <div class="input-wrapper">
               <input type="number" name="quantity_used" min="0.01" step="0.01" placeholder="e.g. 150" required>
            </div>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Amount deducted from inventory (<span id="selectedUnitLabel">unit</span>) for each item sold</div>
         </div>

         <div class="modal-actions">
            <button type="submit" name="add_product_ingredient" class="btn">
               <i class="fa-solid fa-link"></i> Link Recipe Ingredient
            </button>
         </div>
      </form>
      <?php else: ?>
         <div class="all-linked-msg">
            <i class="fa-solid fa-circle-check"></i> All available recipe ingredients are already linked.
         </div>
      <?php endif; ?>
   </div>
</div>
<script>
   function updateIngredientUnit() {
      const select = document.getElementById('ingredientSelect');
      const label = document.getElementById('selectedUnitLabel');
      if (!select || !label) return;
      const selected = select.options[select.selectedIndex];
      label.textContent = selected && selected.dataset.unit ? selected.dataset.unit : 'unit';
   }

</script>
<?php endif; ?>

<?php if ($mi_product !== null && $manage_packaging_only): ?>
<div class="modal show" id="packagingModal">
   <div class="modal-content">
      <div class="modal-header ingredients">
         <h2><i class="fa-solid fa-box-open"></i> Manage Product Packaging</h2>
         <a href="admin_products.php" class="modal-close" title="Close">×</a>
      </div>
      <div class="ingredients-product-tag">
         <i class="fa-solid fa-box"></i> <?php echo htmlspecialchars($mi_product['name']); ?>
      </div>
      <div class="help-text" style="margin-bottom:16px;">
         Manually connect an inventory item to the matching customer option and set the amount to deduct per item sold. Buying one option will not deduct packaging mapped to other options.
      </div>

      <div class="ingredients-section-label">
         <i class="fa-solid fa-link"></i> Packaging Mappings
      </div>
      <?php if (!empty($size_packaging)): ?>
         <div class="linked-ingredients-list">
            <?php foreach ($size_packaging as $link): ?>
               <div class="ingredient-item-row">
                  <div class="ingredient-item-info">
                     <span class="ingredient-item-name"><?php echo htmlspecialchars($link['size']); ?> — <?php echo htmlspecialchars($link['ingredient_name']); ?></span>
                     <span class="ingredient-item-meta">
                        <span class="cat-tag"><?php echo htmlspecialchars(strtolower((string)$link['category']) === 'drinks' ? 'Consumable' : ucfirst((string)$link['category'])); ?></span>
                        Deducts <?php echo number_format((float)$link['quantity_used'], 2); ?> <?php echo htmlspecialchars($link['unit']); ?> per matching item sold
                     </span>
                  </div>
                  <a href="admin_products.php?remove_size_packaging=<?php echo (int)$link['id']; ?>&manage_packaging=<?php echo (int)$mi_product_id; ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                     class="remove-ingredient-btn" onclick="return confirm('Remove this packaging mapping?');" title="Remove">
                     <i class="fa-solid fa-trash"></i> Remove
                  </a>
               </div>
            <?php endforeach; ?>
         </div>
      <?php else: ?>
         <div class="no-ingredients-msg">No packaging has been assigned to this product's options yet.</div>
      <?php endif; ?>

      <?php if (!empty($product_size_options) && !empty($option_inventory)): ?>
      <div class="ingredients-section-label" style="margin-top:20px;">
         <i class="fa-solid fa-plus"></i> Add Packaging to an Option
      </div>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="sp_product_id" value="<?php echo (int)$mi_product_id; ?>">
         <div class="field">
            <label><i class="fa-solid fa-cubes"></i> Inventory Item</label>
            <div class="input-wrapper">
               <select name="size_ingredient_id" required>
                  <option value="">Choose from inventory...</option>
                  <?php
                     $current_cat = null;
                     foreach ($option_inventory as $inventory_item) {
                        $inventory_category = strtolower((string)$inventory_item['category']) === 'drinks'
                           ? 'Consumable'
                           : ucfirst((string)$inventory_item['category']);
                        if ($inventory_category !== $current_cat) {
                           if ($current_cat !== null) echo '</optgroup>';
                           echo '<optgroup label="' . htmlspecialchars($inventory_category) . '">';
                           $current_cat = $inventory_category;
                        }
                        $is_product_wide = in_array((int)$inventory_item['id'], $linked_ids, true);
                        echo '<option value="' . (int)$inventory_item['id'] . '"' . ($is_product_wide ? ' disabled' : '') . '>'
                           . htmlspecialchars($inventory_item['ingredient_name']) . ' ('
                           . htmlspecialchars($inventory_item['unit']) . ', '
                           . number_format((float)$inventory_item['quantity'], 2) . ' in stock)'
                           . ($is_product_wide ? ' — already used by every option' : '') . '</option>';
                     }
                     if ($current_cat !== null) echo '</optgroup>';
                  ?>
               </select>
            </div>
         </div>
         <div class="field">
            <label><i class="fa-solid fa-ruler"></i> Customer Option</label>
            <div class="input-wrapper">
               <select name="product_size_id" required>
                  <option value="">Choose the matching option...</option>
                  <?php foreach ($product_size_options as $size_option): ?>
                     <option value="<?php echo (int)$size_option['id']; ?>"><?php echo htmlspecialchars($size_option['size']); ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
         </div>
         <div class="field">
            <label><i class="fa-solid fa-weight-scale"></i> Quantity to Deduct Per Item Sold</label>
            <div class="input-wrapper">
               <input type="number" name="size_quantity_used" min="0.01" step="0.01" placeholder="e.g. 1 cup or container" required>
            </div>
         </div>
         <div class="modal-actions">
            <button type="submit" name="add_size_packaging" class="btn"><i class="fa-solid fa-link"></i> Save Packaging Mapping</button>
         </div>
      </form>
      <?php elseif (empty($product_size_options)): ?>
         <div class="no-ingredients-msg">Add an active customer option to this product before assigning packaging.</div>
      <?php else: ?>
         <div class="no-ingredients-msg">Add inventory items before assigning packaging.</div>
      <?php endif; ?>
   </div>
</div>
<?php endif; ?>

<!-- Manage Preferences Modal -->
<?php
   $mp_product = null;
   $preference_groups = [];
   if (isset($_GET['manage_preferences'])) {
      $mp_product_id = intval($_GET['manage_preferences']);
      $mp_stmt = $conn->prepare("SELECT * FROM `products` WHERE id = ?");
      $mp_stmt->bind_param("i", $mp_product_id);
      $mp_stmt->execute();
      $mp_result = $mp_stmt->get_result();

      if ($mp_result->num_rows > 0) {
         $mp_product = $mp_result->fetch_assoc();
         $mp_stmt->close();

         $pg_stmt = $conn->prepare("SELECT * FROM `product_preference_groups` WHERE product_id = ? ORDER BY id ASC");
         $pg_stmt->bind_param("i", $mp_product_id);
         $pg_stmt->execute();
         $pg_result = $pg_stmt->get_result();
         while ($grp = $pg_result->fetch_assoc()) {
            $po_stmt = $conn->prepare("SELECT * FROM `product_preference_options` WHERE group_id = ? ORDER BY id ASC");
            $po_stmt->bind_param("i", $grp['id']);
            $po_stmt->execute();
            $po_result = $po_stmt->get_result();
            $options = [];
            while ($opt = $po_result->fetch_assoc()) { $options[] = $opt; }
            $po_stmt->close();
            $grp['options'] = $options;
            $preference_groups[] = $grp;
         }
         $pg_stmt->close();
      } else {
         $mp_stmt->close();
      }
   }

   if ($mp_product !== null):
?>
<div class="modal show" id="preferencesModal">
   <div class="modal-content">
      <div class="modal-header preferences">
         <h2><i class="fa-solid fa-sliders"></i> Manage Preferences</h2>
         <a href="admin_products.php" class="modal-close" title="Close">×</a>
      </div>

      <div class="ingredients-product-tag">
         <i class="fa-solid fa-box"></i> <?php echo htmlspecialchars($mp_product['name']); ?>
      </div>

      <div class="ingredients-section-label"><i class="fa-solid fa-list"></i> Preference Titles</div>

      <?php if (!empty($preference_groups)): ?>
         <?php foreach ($preference_groups as $grp): ?>
         <div class="group-block">
            <div class="group-block-header">
               <strong><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($grp['title']); ?></strong>
               <a href="admin_products.php?remove_preference_group=<?php echo intval($grp['id']); ?>&manage_preferences=<?php echo intval($mp_product_id); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                  class="remove-ingredient-btn" onclick="return confirm('Remove this preference title and all its options?');" title="Remove Title">
                  <i class="fa-solid fa-trash"></i> Remove Title
               </a>
            </div>
            <div>
               <?php if (!empty($grp['options'])): ?>
                  <?php foreach ($grp['options'] as $opt): ?>
                  <span class="option-chip">
                     <?php echo htmlspecialchars($opt['option_name']); ?>
                     <a href="admin_products.php?remove_preference_option=<?php echo intval($opt['id']); ?>&manage_preferences=<?php echo intval($mp_product_id); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                        class="chip-remove" onclick="return confirm('Remove this option?');" title="Remove">×</a>
                  </span>
                  <?php endforeach; ?>
               <?php else: ?>
                  <span style="color:var(--gray-brown); font-size:0.85rem; font-weight:600;">No options yet — add one below.</span>
               <?php endif; ?>
            </div>
         </div>
         <?php endforeach; ?>
      <?php else: ?>
         <div class="no-ingredients-msg">
            <i class="fa-solid fa-sliders" style="font-size:1.5rem; opacity:0.4; display:block; margin-bottom:8px;"></i>
            No preference titles yet. Create one below (e.g. "Sugar Level", "Milk Type").
         </div>
      <?php endif; ?>

      <div class="ingredients-section-label" style="margin-top:20px;"><i class="fa-solid fa-plus"></i> Create Preference Title</div>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="pg_product_id" value="<?php echo intval($mp_product_id); ?>">
         <div class="field">
            <label><i class="fa-solid fa-heading"></i> Title</label>
            <div class="input-wrapper">
               <input type="text" name="preference_title" placeholder="e.g., Sugar Level, Milk Type" maxlength="100" required>
            </div>
         </div>
         <div class="modal-actions">
            <button type="submit" name="add_preference_group" class="btn">
               <i class="fa-solid fa-check-circle"></i> Create Title
            </button>
         </div>
      </form>

      <?php if (!empty($preference_groups)): ?>
      <div class="ingredients-section-label" style="margin-top:20px;"><i class="fa-solid fa-plus"></i> Add Option to a Title</div>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="po_product_id" value="<?php echo intval($mp_product_id); ?>">
         <div class="field">
            <label><i class="fa-solid fa-list"></i> Preference Title</label>
            <div class="input-wrapper">
               <select name="preference_group_id" required>
                  <option value="">Choose a title...</option>
                  <?php foreach ($preference_groups as $grp): ?>
                     <option value="<?php echo intval($grp['id']); ?>"><?php echo htmlspecialchars($grp['title']); ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
         </div>
         <div class="field">
            <label><i class="fa-solid fa-tag"></i> Option Name</label>
            <div class="input-wrapper">
               <input type="text" name="preference_option_name" placeholder="e.g., No Sugar, Oat Milk" maxlength="100" required>
            </div>
         </div>
         <div class="modal-actions">
            <button type="submit" name="add_preference_option" class="btn">
               <i class="fa-solid fa-plus"></i> Add Option
            </button>
         </div>
      </form>
      <?php endif; ?>

   </div>
</div>
<?php endif; ?>

<!-- Manage Extras Modal -->
<?php
   $me_product = null;
   $extra_groups = [];
   if (isset($_GET['manage_extras'])) {
      $me_product_id = intval($_GET['manage_extras']);
      $me_stmt = $conn->prepare("SELECT * FROM `products` WHERE id = ?");
      $me_stmt->bind_param("i", $me_product_id);
      $me_stmt->execute();
      $me_result = $me_stmt->get_result();

      if ($me_result->num_rows > 0) {
         $me_product = $me_result->fetch_assoc();
         $me_stmt->close();

         $eg_stmt = $conn->prepare("SELECT * FROM `product_extra_groups` WHERE product_id = ? ORDER BY id ASC");
         $eg_stmt->bind_param("i", $me_product_id);
         $eg_stmt->execute();
         $eg_result = $eg_stmt->get_result();
         while ($grp = $eg_result->fetch_assoc()) {
            $eo_stmt = $conn->prepare("SELECT * FROM `product_extra_options` WHERE group_id = ? ORDER BY id ASC");
            $eo_stmt->bind_param("i", $grp['id']);
            $eo_stmt->execute();
            $eo_result = $eo_stmt->get_result();
            $options = [];
            while ($opt = $eo_result->fetch_assoc()) { $options[] = $opt; }
            $eo_stmt->close();
            $grp['options'] = $options;
            $extra_groups[] = $grp;
         }
         $eg_stmt->close();
      } else {
         $me_stmt->close();
      }
   }

   if ($me_product !== null):
?>
<div class="modal show" id="extrasModal">
   <div class="modal-content">
      <div class="modal-header extras">
         <h2><i class="fa-solid fa-plus"></i> Manage Extras</h2>
         <a href="admin_products.php" class="modal-close" title="Close">×</a>
      </div>

      <div class="ingredients-product-tag">
         <i class="fa-solid fa-box"></i> <?php echo htmlspecialchars($me_product['name']); ?>
      </div>

      <div class="ingredients-section-label"><i class="fa-solid fa-list"></i> Extra Titles</div>

      <?php if (!empty($extra_groups)): ?>
         <?php foreach ($extra_groups as $grp): ?>
         <div class="group-block">
            <div class="group-block-header">
               <strong><i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($grp['title']); ?></strong>
               <a href="admin_products.php?remove_extra_group=<?php echo intval($grp['id']); ?>&manage_extras=<?php echo intval($me_product_id); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                  class="remove-ingredient-btn" onclick="return confirm('Remove this extra title and all its options?');" title="Remove Title">
                  <i class="fa-solid fa-trash"></i> Remove Title
               </a>
            </div>
            <div>
               <?php if (!empty($grp['options'])): ?>
                  <?php foreach ($grp['options'] as $opt): ?>
                  <span class="option-chip">
                     <?php echo htmlspecialchars($opt['option_name']); ?> — ₱<?php echo number_format($opt['price'], 2); ?>
                     <a href="admin_products.php?remove_extra_option=<?php echo intval($opt['id']); ?>&manage_extras=<?php echo intval($me_product_id); ?>&csrf_token=<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>"
                        class="chip-remove" onclick="return confirm('Remove this option?');" title="Remove">×</a>
                  </span>
                  <?php endforeach; ?>
               <?php else: ?>
                  <span style="color:var(--gray-brown); font-size:0.85rem; font-weight:600;">No options yet — add one below.</span>
               <?php endif; ?>
            </div>
         </div>
         <?php endforeach; ?>
      <?php else: ?>
         <div class="no-ingredients-msg">
            <i class="fa-solid fa-plus" style="font-size:1.5rem; opacity:0.4; display:block; margin-bottom:8px;"></i>
            No extra titles yet. Create one below (e.g. "Add-ons", "Toppings").
         </div>
      <?php endif; ?>

      <div class="ingredients-section-label" style="margin-top:20px;"><i class="fa-solid fa-plus"></i> Create Extra Title</div>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="eg_product_id" value="<?php echo intval($me_product_id); ?>">
         <div class="field">
            <label><i class="fa-solid fa-heading"></i> Title</label>
            <div class="input-wrapper">
               <input type="text" name="extra_title" placeholder="e.g., Add-ons, Toppings" maxlength="100" required>
            </div>
         </div>
         <div class="modal-actions">
            <button type="submit" name="add_extra_group" class="btn">
               <i class="fa-solid fa-check-circle"></i> Create Title
            </button>
         </div>
      </form>

      <?php if (!empty($extra_groups)): ?>
      <div class="ingredients-section-label" style="margin-top:20px;"><i class="fa-solid fa-plus"></i> Add Option to a Title</div>
      <form action="" method="post" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="eo_product_id" value="<?php echo intval($me_product_id); ?>">
         <div class="field">
            <label><i class="fa-solid fa-list"></i> Extra Title</label>
            <div class="input-wrapper">
               <select name="extra_group_id" required>
                  <option value="">Choose a title...</option>
                  <?php foreach ($extra_groups as $grp): ?>
                     <option value="<?php echo intval($grp['id']); ?>"><?php echo htmlspecialchars($grp['title']); ?></option>
                  <?php endforeach; ?>
               </select>
            </div>
         </div>
         <div class="field-row">
            <div class="field">
               <label><i class="fa-solid fa-tag"></i> Option Name</label>
               <div class="input-wrapper">
                  <input type="text" name="extra_option_name" placeholder="e.g., Extra Shot, Whipped Cream" maxlength="100" required>
               </div>
            </div>
            <div class="field">
               <label><i class="fa-solid fa-peso-sign"></i> Price</label>
               <div class="input-wrapper">
                  <input type="number" name="extra_option_price" min="0" step="0.01" placeholder="20.00" required>
               </div>
            </div>
         </div>
         <div class="modal-actions">
            <button type="submit" name="add_extra_option" class="btn">
               <i class="fa-solid fa-plus"></i> Add Option
            </button>
         </div>
      </form>
      <?php endif; ?>
   </div>
</div>
<?php endif; ?>

<!-- Stock Update Modal -->
<div class="modal" id="stockModal">
   <div class="modal-content">
      <div class="modal-header stock">
         <h2><i class="fa-solid fa-boxes"></i> Update Stock</h2>
         <button type="button" class="modal-close" onclick="closeStockModal()" title="Close">×</button>
      </div>

      <div class="stock-info">
         <p><strong>Product:</strong> <span id="stockProductName"></span></p>
         <p><strong>Current Stock:</strong> <span class="current-stock" id="stockCurrent">0</span> units</p>
      </div>

      <form action="" method="post" id="stockForm" novalidate>
         <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
         <input type="hidden" name="update_stock" value="1">
         <input type="hidden" name="size_id" id="sizeIdInput" value="">

         <div class="field">
            <label for="sizeSelect"><i class="fa-solid fa-ruler"></i> Select Size</label>
            <div class="input-wrapper">
               <select id="sizeSelect" required onchange="updateStockDisplay()">
                  <option value="">Choose a size...</option>
               </select>
            </div>
         </div>

         <div class="field">
            <label for="newStockInput"><i class="fa-solid fa-plus-circle"></i> New Stock Quantity</label>
            <div class="input-wrapper">
               <input type="number" id="newStockInput" name="new_stock" min="0" max="9999" placeholder="0" required>
            </div>
            <div class="help-text"><i class="fa-solid fa-info-circle"></i> Enter the new stock amount</div>
         </div>

         <div class="modal-actions">
            <button type="button" class="btn secondary" onclick="closeStockModal()">
               <i class="fa-solid fa-times"></i> Cancel
            </button>
            <button type="submit" class="btn">
               <i class="fa-solid fa-check-circle"></i> Update Stock
            </button>
         </div>
      </form>
   </div>
</div>

<script>
   // ==================== IMAGE PREVIEW FOR ADD PRODUCT ====================
   document.getElementById('imageInput').addEventListener('change', function(e) {
      const file = e.target.files[0];
      const previewContainer = document.getElementById('imagePreviewContainer');
      const preview = document.getElementById('imagePreview');

      if (file) {
         const reader = new FileReader();
         reader.onload = function(event) {
            preview.src = event.target.result;
            previewContainer.classList.add('show');
         };
         reader.readAsDataURL(file);
      }
   });

   function removeImagePreview() {
      document.getElementById('imageInput').value = '';
      document.getElementById('imagePreviewContainer').classList.remove('show');
   }

   // ==================== IMAGE PREVIEW FOR UPDATE PRODUCT ====================
   const updateImageInput = document.getElementById('updateImageInput');
   if (updateImageInput) {
      updateImageInput.addEventListener('change', function(e) {
         const file = e.target.files[0];
         const previewContainer = document.getElementById('updateImagePreviewContainer');
         const preview = document.getElementById('updateImagePreview');

         if (file) {
            const reader = new FileReader();
            reader.onload = function(event) {
               preview.src = event.target.result;
               previewContainer.classList.add('show');
            };
            reader.readAsDataURL(file);
         }
      });
   }

   function removeUpdateImagePreview() {
      document.getElementById('updateImageInput').value = '';
      document.getElementById('updateImagePreviewContainer').classList.remove('show');
   }

   // ==================== SIZE TYPE (Cup vs Slice/Pieces) ====================
   function updateSizeTypeUI() {
      const select = document.getElementById('sizeTypeSelect');
      const label = document.getElementById('sizeStockLabel');
      if (!select || !label) return;
      const isSlice = select.value === 'slice';
      label.innerHTML = isSlice
         ? '<i class="fa-solid fa-ruler"></i> Slice / Piece Options, Prices &amp; Stock'
         : '<i class="fa-solid fa-ruler"></i> Product Options, Prices &amp; Stock';
      const placeholder = isSlice ? 'e.g., 1 Slice / Whole Cake' : 'e.g., Small / 12oz / 1 Container';
      document.querySelectorAll('.size-name-input').forEach(function(inp) {
         inp.placeholder = placeholder;
      });
   }

   // ==================== SIZE AND STOCK MANAGEMENT ====================
   function addSizeStock(){
      const container = document.getElementById('sizeStockContainer');
      if(container.querySelectorAll('.size-stock-row').length >= 10){
         alert('Maximum 10 sizes allowed');
         return;
      }
      const sizeTypeSelect = document.getElementById('sizeTypeSelect');
      const isSlice = sizeTypeSelect && sizeTypeSelect.value === 'slice';
      const placeholder = isSlice ? 'e.g., 1 Slice / 6 Pieces' : 'e.g., 12oz / Small';
      const row = document.createElement('div');
      row.className = 'size-stock-row';
      row.innerHTML = `
         <div class="input-wrapper">
            <input type="text" name="size[]" class="size-name-input" placeholder="${placeholder}" maxlength="20" required>
         </div>
         <div class="input-wrapper">
            <input type="number" name="size_price[]" min="1" step="1" placeholder="Price (₱)" required>
         </div>
         <div class="input-wrapper">
            <input type="number" name="stock[]" min="1" placeholder="Stock" required>
         </div>
      `;
      container.appendChild(row);
      row.querySelector('input').focus();
   }

   function removeLastSize(){
      const container = document.getElementById('sizeStockContainer');
      const rows = container.querySelectorAll('.size-stock-row');
      if(rows.length > 1){
         rows[rows.length - 1].remove();
      }else{
         alert('Keep at least one size option.');
      }
   }

   function addNewSizeFields() {
      const container = document.getElementById('newSizeFields');
      const index = container.querySelectorAll('.new-size-row').length;
      if (index >= 10) {
         alert('Maximum 10 new sizes can be added at once.');
         return;
      }
      const row = document.createElement('div');
      row.className = 'field-row new-size-row';
      row.style.gridTemplateColumns = 'repeat(3, minmax(0, 1fr))';
      row.style.marginTop = '10px';
      row.innerHTML = `
         <div class="input-wrapper"><input type="text" name="new_size[${index}]" maxlength="20" placeholder="e.g., 1 Slice or Whole Cake"></div>
         <div class="input-wrapper"><input type="number" name="new_size_price[${index}]" min="1" step="1" placeholder="Price"></div>
         <div class="input-wrapper"><input type="number" name="new_size_stock[${index}]" min="0" step="1" placeholder="Starting stock"></div>
      `;
      container.appendChild(row);
      row.querySelector('input').focus();
   }

   // ==================== STOCK UPDATE MODAL ====================
   function openStockModal(productId, sizesJson) {
      try {
         const sizes = JSON.parse(sizesJson);
         const modal = document.getElementById('stockModal');
         const sizeSelect = document.getElementById('sizeSelect');

         // Get product name from the DOM
         const productName = event.target.closest('.product-card').querySelector('.product-title').textContent;

         // Clear previous options
         sizeSelect.innerHTML = '<option value="">Choose a size...</option>';

         // Add sizes to select with data attributes - using ID instead of size name
         sizes.forEach(size => {
            const option = document.createElement('option');
            option.value = size.id;  // Use size ID as value
            option.setAttribute('data-stock', size.stock);
            option.textContent = `${size.size} (Current: ${size.stock} units)`;
            sizeSelect.appendChild(option);
         });

         // Set product info
         document.getElementById('stockProductName').textContent = productName;

         // Reset form
         document.getElementById('newStockInput').value = '';
         document.getElementById('sizeIdInput').value = '';

         // Show modal
         modal.classList.add('show');
         sizeSelect.focus();
      } catch (error) {
         console.error('Error opening stock modal:', error);
         alert('Error loading stock information');
      }
   }

   function updateStockDisplay() {
      const sizeSelect = document.getElementById('sizeSelect');
      const sizeIdInput = document.getElementById('sizeIdInput');
      const currentStock = document.getElementById('stockCurrent');

      const selectedOption = sizeSelect.options[sizeSelect.selectedIndex];
      if (selectedOption && selectedOption.value) {
         // Set the size ID for form submission
         sizeIdInput.value = selectedOption.value;

         // Get current stock from data attribute
         const stock = selectedOption.getAttribute('data-stock');
         currentStock.textContent = stock || '0';
      } else {
         sizeIdInput.value = '';
         currentStock.textContent = '0';
      }
   }

   function closeStockModal() {
      document.getElementById('stockModal').classList.remove('show');
      // Reset form when closing
      document.getElementById('sizeSelect').value = '';
      document.getElementById('sizeIdInput').value = '';
      document.getElementById('newStockInput').value = '';
   }

   // ==================== MODAL ESCAPE KEY ====================
   document.addEventListener('keydown', e => {
      if(e.key === 'Escape') {
         const editModal = document.getElementById('editModal');
         const stockModal = document.getElementById('stockModal');
         const ingredientsModal = document.getElementById('ingredientsModal');
         const preferencesModal = document.getElementById('preferencesModal');
         const extrasModal = document.getElementById('extrasModal');

         if (editModal && editModal.classList.contains('show')) {
            window.location.href = 'admin_products.php';
         }
         if (stockModal && stockModal.classList.contains('show')) {
            closeStockModal();
         }
         if (ingredientsModal && ingredientsModal.classList.contains('show')) {
            window.location.href = 'admin_products.php';
         }
         if (preferencesModal && preferencesModal.classList.contains('show')) {
            window.location.href = 'admin_products.php';
         }
         if (extrasModal && extrasModal.classList.contains('show')) {
            window.location.href = 'admin_products.php';
         }
      }
   });

   // ==================== STOCK FORM SUBMISSION ====================
   document.getElementById('stockForm').addEventListener('submit', function(e) {
      const sizeSelect = document.getElementById('sizeSelect');
      const sizeIdInput = document.getElementById('sizeIdInput');
      const newStock = document.getElementById('newStockInput');

      if (!sizeSelect.value || !sizeIdInput.value) {
         e.preventDefault();
         alert('Please select a size first');
         sizeSelect.focus();
         return false;
      }

      if (newStock.value === '' || newStock.value < 0) {
         e.preventDefault();
         alert('Please enter a valid stock amount');
         newStock.focus();
         return false;
      }
   });
</script>
<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>