<?php
include 'config.php';

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return trim(strip_tags((string)($data ?? '')));
    }
}
if (!function_exists('escapeOutput')) {
    function escapeOutput($data) {
        return htmlspecialchars((string)($data ?? ''), ENT_QUOTES, 'UTF-8');
    }
}
if (!function_exists('validatePhone')) {
    function validatePhone($phone) {
        $digits = preg_replace('/[\s\-\+\(\)]/', '', (string)($phone ?? ''));
        return (bool) preg_match('/^[0-9]{10,15}$/', $digits);
    }
}
if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }
}
if (!function_exists('getUserIP')) {
    function getUserIP() {
        return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    }
}
if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent($event, $context = []) {
        error_log('[SECURITY] ' . $event . ' ' . json_encode($context));
    }
}
if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && is_string($token) && hash_equals($_SESSION['csrf_token'], $token);
    }
}
if (!function_exists('validateOrigin')) {
    function validateOrigin() {
        if (empty($_SERVER['HTTP_HOST'])) return false;
        $referer = $_SERVER['HTTP_REFERER'] ?? '';
        if ($referer === '') return true; // some browsers omit referer; CSRF token is the primary defense
        $refererHost = parse_url($referer, PHP_URL_HOST);
        return $refererHost === $_SERVER['HTTP_HOST'];
    }
}

/* =====================================================================
   SESSION / USER SETUP
   ===================================================================== */
$auth_user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
$is_guest     = $auth_user_id === null;

if ($is_guest) {
    if (!isset($_SESSION['guest_cart_id'])) {
        $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16));
    }
    // Same "guest_" + random hex format used in index.php / Item.php,
    // so a guest's cart is found no matter which page they added from.
    $cart_user_key = 'guest_' . $_SESSION['guest_cart_id'];
} else {
    $cart_user_key = (string)$auth_user_id;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['message'])) {
    $_SESSION['message'] = [];
}

// Friendly label/icon per size_type — kept in sync with admin_products.php
$SIZE_TYPE_META = [
    'cup'   => ['label' => 'Cup Size',       'icon' => 'fa-mug-hot'],
    'slice' => ['label' => 'Slice / Pieces', 'icon' => 'fa-cake-candles'],
];

/* =====================================================================
   ORIGIN CHECK FOR STATE-CHANGING POST REQUESTS
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateOrigin()) {
        logSecurityEvent('CSRF_ATTEMPT', ['page' => 'cart', 'ip' => getUserIP()]);
        http_response_code(403);
        die('🔒 Invalid request origin. Request rejected.');
    }
}

/* =====================================================================
   HELPER: fetch + validate this user's current cart, cleaning up any
   "orphaned" rows whose product was deleted by an admin (restoring the
   stock that was reserved for them).
   ===================================================================== */
function fetchCartItems(mysqli $conn, string $cart_user_key, array &$removed_names = []): array {
    $items   = [];
    $total   = 0.0;
    $orphans = [];

    $stmt = $conn->prepare("SELECT c.*, p.size_type, p.allow_special_instructions, p.id AS product_exists FROM `cart` c LEFT JOIN `products` p ON c.product_id = p.id WHERE c.user_id = ? ORDER BY c.id DESC");
    $stmt->bind_param("s", $cart_user_key);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        if ($row['product_exists'] === null) {
            $orphans[] = $row;
            continue;
        }
        $row['line_subtotal'] = (float)$row['quantity'] * (float)$row['price'];
        $total += $row['line_subtotal'];
        $items[] = $row;
    }
    $stmt->close();

    foreach ($orphans as $orphan) {
        $conn->begin_transaction();
        try {
            $restore = $conn->prepare("UPDATE `product_sizes` SET stock = stock + ? WHERE product_id = ? AND size = ?");
            $restore->bind_param("iis", $orphan['quantity'], $orphan['product_id'], $orphan['size']);
            $restore->execute();
            $restore->close();

            $del = $conn->prepare("DELETE FROM `cart` WHERE id = ?");
            $del->bind_param("i", $orphan['id']);
            $del->execute();
            $del->close();

            $conn->commit();
            $removed_names[] = $orphan['name'];
        } catch (Exception $e) {
            $conn->rollback();
        }
    }

    return [$items, $total];
}

/* =====================================================================
   UPDATE CART QUANTITY
   ===================================================================== */
if (isset($_POST['update_cart'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        $_SESSION['message'][] = 'Your session has expired. Please try again.';
        header('Location: cart.php'); exit;
    }

    $cart_id       = (int)($_POST['cart_id'] ?? 0);
    $cart_quantity = (int)($_POST['cart_quantity'] ?? 0);
    if ($cart_quantity < 1) $cart_quantity = 1;
    if ($cart_quantity > 50) $cart_quantity = 50;

    if ($cart_id <= 0) {
        $_SESSION['message'][] = 'Invalid cart item.';
    } else {
        $conn->begin_transaction();
        try {
            $cart_stmt = $conn->prepare("SELECT product_id, size, quantity FROM `cart` WHERE id = ? AND user_id = ? FOR UPDATE");
            $cart_stmt->bind_param("is", $cart_id, $cart_user_key);
            $cart_stmt->execute();
            $fetch_cart = $cart_stmt->get_result()->fetch_assoc();
            $cart_stmt->close();

            if (!$fetch_cart) {
                throw new Exception('Cart item not found.');
            }

            $product_id   = (int)$fetch_cart['product_id'];
            $product_size = $fetch_cart['size'];
            $old_quantity = (int)$fetch_cart['quantity'];

            $size_stmt = $conn->prepare("SELECT stock FROM `product_sizes` WHERE product_id = ? AND size = ? FOR UPDATE");
            $size_stmt->bind_param("is", $product_id, $product_size);
            $size_stmt->execute();
            $size_row = $size_stmt->get_result()->fetch_assoc();
            $size_stmt->close();

            if (!$size_row) {
                throw new Exception('This size is no longer available.');
            }

            $available_stock  = (int)$size_row['stock'];
            $stock_difference = $cart_quantity - $old_quantity;

            if ($stock_difference > $available_stock) {
                throw new Exception("Only " . ($available_stock + $old_quantity) . " left in stock for size $product_size.");
            }

            if ($stock_difference !== 0) {
                $adjust_stmt = $conn->prepare("UPDATE `product_sizes` SET stock = stock - ? WHERE product_id = ? AND size = ?");
                $adjust_stmt->bind_param("iis", $stock_difference, $product_id, $product_size);
                $adjust_stmt->execute();
                $adjust_stmt->close();
            }

            $update_stmt = $conn->prepare("UPDATE `cart` SET quantity = ? WHERE id = ? AND user_id = ?");
            $update_stmt->bind_param("iis", $cart_quantity, $cart_id, $cart_user_key);
            $update_stmt->execute();
            $update_stmt->close();

            $conn->commit();
            $_SESSION['message'][] = 'Cart quantity updated!';
        } catch (Exception $e) {
            $conn->rollback();
            $_SESSION['message'][] = $e->getMessage();
        }
    }

    header('Location: cart.php'); exit;
}

/* =====================================================================
   DELETE SINGLE ITEM
   ===================================================================== */
if (isset($_GET['delete'])) {
    if (!isset($_GET['csrf_token']) || !validateCSRFToken($_GET['csrf_token'])) {
        $_SESSION['message'][] = 'Your session has expired. Please try again.';
        header('Location: cart.php'); exit;
    }

    $delete_id = (int)$_GET['delete'];

    $conn->begin_transaction();
    try {
        $cart_stmt = $conn->prepare("SELECT product_id, size, quantity FROM `cart` WHERE id = ? AND user_id = ? FOR UPDATE");
        $cart_stmt->bind_param("is", $delete_id, $cart_user_key);
        $cart_stmt->execute();
        $fetch_cart = $cart_stmt->get_result()->fetch_assoc();
        $cart_stmt->close();

        if ($fetch_cart) {
            $product_id   = (int)$fetch_cart['product_id'];
            $product_size = $fetch_cart['size'];
            $quantity     = (int)$fetch_cart['quantity'];

            $restore_stmt = $conn->prepare("UPDATE `product_sizes` SET stock = stock + ? WHERE product_id = ? AND size = ?");
            $restore_stmt->bind_param("iis", $quantity, $product_id, $product_size);
            $restore_stmt->execute();
            $restore_stmt->close();

            $delete_stmt = $conn->prepare("DELETE FROM `cart` WHERE id = ? AND user_id = ?");
            $delete_stmt->bind_param("is", $delete_id, $cart_user_key);
            $delete_stmt->execute();
            $delete_stmt->close();
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    header('Location: cart.php'); exit;
}

/* =====================================================================
   DELETE ALL ITEMS
   ===================================================================== */
if (isset($_GET['delete_all'])) {
    if (!isset($_GET['csrf_token']) || !validateCSRFToken($_GET['csrf_token'])) {
        $_SESSION['message'][] = 'Your session has expired. Please try again.';
        header('Location: cart.php'); exit;
    }

    $conn->begin_transaction();
    try {
        $cart_stmt = $conn->prepare("SELECT product_id, size, quantity FROM `cart` WHERE user_id = ? FOR UPDATE");
        $cart_stmt->bind_param("s", $cart_user_key);
        $cart_stmt->execute();
        $all_items = $cart_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $cart_stmt->close();

        if (!empty($all_items)) {
            $restore_stmt = $conn->prepare("UPDATE `product_sizes` SET stock = stock + ? WHERE product_id = ? AND size = ?");
            foreach ($all_items as $item) {
                $product_id   = (int)$item['product_id'];
                $product_size = $item['size'];
                $quantity     = (int)$item['quantity'];
                $restore_stmt->bind_param("iis", $quantity, $product_id, $product_size);
                $restore_stmt->execute();
            }
            $restore_stmt->close();
        }

        $delete_stmt = $conn->prepare("DELETE FROM `cart` WHERE user_id = ?");
        $delete_stmt->bind_param("s", $cart_user_key);
        $delete_stmt->execute();
        $delete_stmt->close();

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollback();
    }

    header('Location: cart.php'); exit;
}

/* =====================================================================
   FETCH ACCOUNT INFO (wallet, discounts) — needed for the checkout modal
   ===================================================================== */
$wallet_balance = 0.0;
$user_name      = '';
$user_email     = '';
$user_type      = null;
$user_status    = null;
$discounts      = [];

if (!$is_guest) {
    $stmt = $conn->prepare("SELECT user_type, status, wallet_balance, name, email FROM users WHERE id = ?");
    $stmt->bind_param("i", $auth_user_id);
    $stmt->execute();
    $user_result = $stmt->get_result();
    if ($u = $user_result->fetch_assoc()) {
        $user_type      = $u['user_type'];
        $user_status    = $u['status'];
        $wallet_balance = (float)$u['wallet_balance'];
        $user_name      = $u['name'] ?? '';
        $user_email     = $u['email'] ?? '';
    }
    $stmt->close();

    $stmt = $conn->prepare("
        SELECT d.* FROM `user_discounts` ud
        JOIN `discounts` d ON ud.discount_id = d.id
        WHERE ud.user_id = ?
        AND ud.used = 0
        AND d.status = 'active'
        AND d.valid_until >= CURDATE()
    ");
    $stmt->bind_param("i", $auth_user_id);
    $stmt->execute();
    $discount_result = $stmt->get_result();
    while ($row = $discount_result->fetch_assoc()) {
        $discounts[] = $row;
    }
    $stmt->close();
}

/* =====================================================================
   APPLY / REMOVE DISCOUNT CODE
   ===================================================================== */
if (isset($_POST['apply_discount'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'apply_discount']);
        $_SESSION['message'][] = '🔒 Security check failed. Please try again.';
    } elseif ($is_guest) {
        $_SESSION['message'][] = 'Please log in to use discount codes.';
    } else {
        $selected_code = sanitizeInput($_POST['discount_code'] ?? '');
        $valid_code = false;

        foreach ($discounts as $d) {
            if ($d['code'] === $selected_code) {
                $valid_code = true;
                $_SESSION['apply_discount'] = [
                    'code'   => $d['code'],
                    'amount' => $d['amount'],
                    'type'   => $d['type'] ?? 'fixed',
                ];
                logSecurityEvent('DISCOUNT_APPLIED', ['code' => $d['code']]);
                break;
            }
        }

        if (!$valid_code) {
            unset($_SESSION['apply_discount']);
            $_SESSION['message'][] = 'Invalid discount code selected.';
            logSecurityEvent('INVALID_DISCOUNT', ['attempted_code' => $selected_code]);
        }
    }
    header('Location: cart.php?checkout=1'); exit;
}

if (isset($_POST['remove_discount'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'remove_discount']);
        $_SESSION['message'][] = '🔒 Security check failed. Please try again.';
    } else {
        unset($_SESSION['apply_discount']);
        logSecurityEvent('DISCOUNT_REMOVED', ['user_id' => $auth_user_id]);
    }
    header('Location: cart.php?checkout=1'); exit;
}

/* =====================================================================
   PLACE ORDER
   ===================================================================== */
if (isset($_POST['order_btn'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'place_order']);
        $_SESSION['message'][] = '🔒 Security check failed. Please try again.';
        header('Location: cart.php?checkout=1'); exit;
    }

    // Always re-pull cart contents fresh from the DB — never trust
    // prices/quantities that might be sitting in hidden form fields.
    $removed_names = [];
    [$order_items, $order_cart_total] = fetchCartItems($conn, $cart_user_key, $removed_names);

    foreach ($removed_names as $rn) {
        $_SESSION['message'][] = '"' . $rn . '" was removed from your cart because it is no longer available.';
    }

    if (empty($order_items)) {
        $_SESSION['message'][] = 'Your cart is empty.';
        header('Location: cart.php'); exit;
    }

    $name     = sanitizeInput($_POST['name'] ?? '');
    $number   = sanitizeInput($_POST['number'] ?? '');
    $email    = sanitizeInput($_POST['email'] ?? '');
    $method   = sanitizeInput($_POST['method'] ?? '');
    $flat     = sanitizeInput($_POST['flat'] ?? '');
    $street   = sanitizeInput($_POST['street'] ?? '');
    $city     = sanitizeInput($_POST['city'] ?? '');
    $pin_code = sanitizeInput($_POST['pin_code'] ?? '');
    $placed_on = date('d-M-Y');

    $allowed_methods = ['cash on delivery', 'wallet balance', 'pickup'];
    $is_pickup        = ($method === 'pickup');
    $requires_wallet  = in_array($method, ['wallet balance', 'pickup'], true);

    $validation_errors = [];

    if (empty($name)) {
        $validation_errors[] = 'Name is required.';
    } elseif (mb_strlen($name) > 100) {
        $validation_errors[] = 'Name is too long (max 100 characters).';
    }

    if (!validatePhone($number)) {
        $validation_errors[] = 'Invalid phone number (10-15 digits).';
    }

    if (!validateEmail($email)) {
        $validation_errors[] = 'Invalid email address.';
    }

    if (!in_array($method, $allowed_methods, true)) {
        $validation_errors[] = 'Invalid payment method.';
        logSecurityEvent('INVALID_PAYMENT_METHOD', ['method' => $method]);
    } elseif ($requires_wallet && $is_guest) {
        $validation_errors[] = 'Please log in to use Wallet Balance or Store Pickup.';
    }

    if (!$is_pickup) {
        if (empty($flat) || empty($street) || empty($city) || empty($pin_code)) {
            $validation_errors[] = 'All address fields are required for delivery.';
        } elseif (mb_strlen($flat) > 50 || mb_strlen($street) > 100 || mb_strlen($city) > 50 || mb_strlen($pin_code) > 10) {
            $validation_errors[] = 'One or more address fields exceed the maximum allowed length.';
        }
    }

    // ---- Totals are always recomputed here — the client never dictates the price ----
    $vat          = $order_cart_total * 0.12;
    $delivery_fee = $is_pickup ? 0.00 : 39.00;

    $auto_discount_amount = 0;
    $auto_discount_label  = '';
    if (!$is_guest && $user_status === 'approved') {
        if ($user_type === 'Senior') {
            $auto_discount_amount = 200;
            $auto_discount_label  = 'Senior Discount';
        } elseif ($user_type === 'PWD') {
            $auto_discount_amount = 250;
            $auto_discount_label  = 'PWD Discount';
        }
    }

    $code_discount_amount = 0;
    $code_discount_label  = '';
    $discount_code        = '';
    if (isset($_SESSION['apply_discount'])) {
        $discount_data = $_SESSION['apply_discount'];
        $discount_code = $discount_data['code'];
        $code_discount_label = 'Promo Code (' . $discount_code . ')';
        $d_type = $discount_data['type'] ?? 'fixed';
        if ($d_type === 'percentage') {
            $code_discount_amount = ($order_cart_total + $vat + $delivery_fee) * ((float)$discount_data['amount'] / 100);
        } else {
            $code_discount_amount = (float)$discount_data['amount'];
        }
    }

    $final_total = $order_cart_total + $vat + $delivery_fee - $auto_discount_amount - $code_discount_amount;
    if ($final_total < 0) $final_total = 0;

    if (!empty($validation_errors)) {
        foreach ($validation_errors as $error) {
            $_SESSION['message'][] = escapeOutput($error);
        }
        logSecurityEvent('ORDER_VALIDATION_FAILED', ['errors' => count($validation_errors)]);
        header('Location: cart.php?checkout=1'); exit;
    }

    // ---- Build the product summary + optional per-item special instructions ----
    $total_products_parts       = [];
    $special_instructions_parts = [];
    $posted_instructions        = $_POST['special_instructions'] ?? [];

    foreach ($order_items as $item) {
        $product_line = $item['name'] . ' (' . (int)$item['quantity'] . ' x ' . $item['size'] . ')';
        // Carry the chosen preferences/extras along with each product so they
        // remain visible later in orders.php — bracketed so they don't get
        // confused with the product-to-product separator below.
        if (!empty($item['preferences'])) {
            $product_line .= ' [Preference: ' . $item['preferences'] . ']';
        }
        if (!empty($item['extras'])) {
            $product_line .= ' [Extras: ' . $item['extras'] . ']';
        }
        $total_products_parts[] = $product_line;

        if (!empty($item['allow_special_instructions'])) {
            $raw_note = $posted_instructions[$item['id']] ?? '';
            $note = trim(strip_tags((string)$raw_note));
            if ($note !== '') {
                if (mb_strlen($note) > 300) {
                    $note = mb_substr($note, 0, 300);
                }
                $special_instructions_parts[] = $item['name'] . ': ' . $note;
            }
        }
    }
    // NOTE: previously joined with ', ' — switched to ' || ' because preference/extra
    // summaries (e.g. "Hot / Iced: Iced, Extra Espresso Shot (+₱25.00)") already contain
    // commas, which would otherwise be mis-split when orders.php parses this field back out.
    $total_products = implode(' || ', $total_products_parts);
    $special_instructions_combined = !empty($special_instructions_parts) ? implode("\n", $special_instructions_parts) : null;

    $address = $is_pickup
        ? 'Store Pickup — Six Origins Cafe, Cainta, Rizal'
        : ('flat no. ' . $flat . ', ' . $street . ', ' . $city . ', ' . $pin_code);

    $conn->begin_transaction();
    try {
        if ($requires_wallet) {
            // Row-lock and re-check the balance at the moment of truth,
            // rather than trusting the value fetched earlier on page load.
            $wstmt = $conn->prepare("SELECT wallet_balance FROM users WHERE id = ? FOR UPDATE");
            $wstmt->bind_param("i", $auth_user_id);
            $wstmt->execute();
            $wrow = $wstmt->get_result()->fetch_assoc();
            $wstmt->close();

            if (!$wrow) {
                throw new Exception('Account not found.');
            }

            $current_balance = (float)$wrow['wallet_balance'];
            if ($current_balance < $final_total) {
                logSecurityEvent('INSUFFICIENT_WALLET_BALANCE', [
                    'user_id'  => $auth_user_id,
                    'balance'  => $current_balance,
                    'required' => $final_total,
                ]);
                throw new Exception('Insufficient wallet balance!');
            }

            $new_balance = $current_balance - $final_total;
            $stmt = $conn->prepare("UPDATE `users` SET wallet_balance = ? WHERE id = ?");
            $stmt->bind_param("di", $new_balance, $auth_user_id);
            if (!$stmt->execute()) {
                throw new Exception('Wallet update failed.');
            }
            $stmt->close();

            logSecurityEvent('WALLET_DEDUCTED', [
                'user_id'     => $auth_user_id,
                'amount'      => $final_total,
                'new_balance' => $new_balance,
            ]);
        }

        $stmt = $conn->prepare("
            INSERT INTO `orders`
                (user_id, name, number, email, method, address, total_products, special_instructions, total_price, placed_on)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        if (!$stmt) {
            throw new Exception('Database prepare failed.');
        }
        $stmt->bind_param(
            "ssssssssds",
            $cart_user_key, $name, $number, $email, $method, $address, $total_products, $special_instructions_combined, $final_total, $placed_on
        );
        if (!$stmt->execute()) {
            throw new Exception('Order insertion failed.');
        }
        $order_id = $conn->insert_id;
        $stmt->close();

        $stmt = $conn->prepare("DELETE FROM `cart` WHERE user_id = ?");
        $stmt->bind_param("s", $cart_user_key);
        if (!$stmt->execute()) {
            throw new Exception('Cart cleanup failed.');
        }
        $stmt->close();

        if (!$is_guest && !empty($discount_code)) {
            $stmt = $conn->prepare("
                UPDATE `user_discounts`
                SET used = 1
                WHERE user_id = ?
                AND discount_id = (SELECT id FROM `discounts` WHERE code = ?)
            ");
            $stmt->bind_param("is", $auth_user_id, $discount_code);
            $stmt->execute();
            $stmt->close();

            logSecurityEvent('DISCOUNT_MARKED_USED', ['user_id' => $auth_user_id, 'code' => $discount_code]);
        }

        $conn->commit();
        unset($_SESSION['apply_discount']);

        $_SESSION['order_success'] = true;
        $_SESSION['order_receipt_data'] = [
            'order_id'             => $order_id,
            'placed_on'            => $placed_on,
            'name'                 => $name,
            'number'               => $number,
            'email'                => $email,
            'method'               => $method,
            'is_pickup'            => $is_pickup,
            'address'              => $address,
            'items'                => array_map(function ($it) {
                return [
                    'name'        => $it['name'],
                    'size'        => $it['size'],
                    'qty'         => (int)$it['quantity'],
                    'price'       => (float)$it['price'],
                    'sub'         => $it['line_subtotal'],
                    'preferences' => $it['preferences'] ?? null,
                    'extras'      => $it['extras'] ?? null,
                ];
            }, $order_items),
            'special_instructions' => $special_instructions_parts,
            'cart_total'           => $order_cart_total,
            'vat'                  => $vat,
            'delivery_fee'         => $delivery_fee,
            'auto_discount_amount' => $auto_discount_amount,
            'auto_discount_label'  => $auto_discount_label,
            'code_discount_amount' => $code_discount_amount,
            'code_discount_label'  => $code_discount_label,
            'final_total'          => $final_total,
        ];

        logSecurityEvent('ORDER_PLACED_SUCCESS', [
            'order_id' => $order_id,
            'user_id'  => $auth_user_id,
            'total'    => $final_total,
            'method'   => $method,
        ]);

        header('Location: cart.php'); exit();

    } catch (Exception $e) {
        $conn->rollback();
        $_SESSION['message'][] = '❌ Transaction Failed: ' . escapeOutput($e->getMessage());
        logSecurityEvent('ORDER_PLACEMENT_FAILED', ['user_id' => $auth_user_id, 'error' => $e->getMessage()]);
        header('Location: cart.php?checkout=1'); exit;
    }
}

/* =====================================================================
   RENDER SETUP
   ===================================================================== */
$order_receipt_data = null;
if (isset($_SESSION['order_success'])) {
    $order_receipt_data = $_SESSION['order_receipt_data'] ?? null;
    unset($_SESSION['order_success'], $_SESSION['order_receipt_data']);
}

$page_messages = $_SESSION['message'];
$_SESSION['message'] = [];

$removed_names = [];
[$cart_items, $grand_total] = fetchCartItems($conn, $cart_user_key, $removed_names);
foreach ($removed_names as $rn) {
    $page_messages[] = '"' . $rn . '" was removed from your cart because it is no longer available.';
}

// Items eligible for the optional special-instructions box in the checkout modal
$special_instruction_items = array_values(array_filter($cart_items, function ($it) {
    return !empty($it['allow_special_instructions']);
}));

// Preview-only totals for the checkout modal's initial state (assumes delivery).
// JS recalculates this live as the customer changes payment method — the
// server always recomputes authoritatively when the order is placed, so a
// tampered preview can never change what's actually charged.
$preview_vat          = $grand_total * 0.12;
$preview_delivery_fee = 39.00;

$preview_auto_discount_amount = 0;
$preview_auto_discount_label  = '';
if (!$is_guest && $user_status === 'approved') {
    if ($user_type === 'Senior') {
        $preview_auto_discount_amount = 200;
        $preview_auto_discount_label  = 'Senior Discount';
    } elseif ($user_type === 'PWD') {
        $preview_auto_discount_amount = 250;
        $preview_auto_discount_label  = 'PWD Discount';
    }
}

$preview_code_discount_amount = 0;
$preview_code_discount_label  = '';
$active_discount_code         = '';
$active_discount_type         = 'fixed';
$active_discount_raw_amount   = 0;
if (isset($_SESSION['apply_discount'])) {
    $dd = $_SESSION['apply_discount'];
    $active_discount_code       = $dd['code'];
    $active_discount_type       = $dd['type'] ?? 'fixed';
    $active_discount_raw_amount = (float)$dd['amount'];
    $preview_code_discount_label = 'Promo Code (' . $dd['code'] . ')';
    if ($active_discount_type === 'percentage') {
        $preview_code_discount_amount = ($grand_total + $preview_vat + $preview_delivery_fee) * ($active_discount_raw_amount / 100);
    } else {
        $preview_code_discount_amount = $active_discount_raw_amount;
    }
}

$preview_final_total = $grand_total + $preview_vat + $preview_delivery_fee - $preview_auto_discount_amount - $preview_code_discount_amount;
if ($preview_final_total < 0) $preview_final_total = 0;

$open_checkout_on_load = isset($_GET['checkout']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width,initial-scale=1" />
   <title>Shopping Cart — Six Origins Cafe</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
   <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
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
       --max-width: 1200px;
       --gap: 24px;
       --base-font-size: 16px;
     }

     * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
     /* RESPONSIVE FIX: only <html> clips sideways overflow. overflow-x:hidden on BOTH html and body turns
        <body> into its own scroll container, which breaks the sticky header on mobile. */
     html { width: 100%; overflow-x: hidden; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
     img { max-width: 100%; }
     body {
       width: 100%;
       overflow-x: clip;
       min-height: 100vh;
       background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
       color: var(--dark-brown);
       -webkit-font-smoothing: antialiased;
       line-height: 1.65;
       font-size: var(--base-font-size);
     }

     .content-wrapper { max-width: var(--max-width); margin: 0 auto; padding: 44px 18px 60px; }

     .hero {
       position: relative; width: 100%; margin: 0; min-height: 550px; overflow: hidden;
       display: flex; align-items: center; justify-content: center; animation: slideDown 0.4s ease;
       box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15); border-bottom: 1px solid rgba(255, 255, 255, 0.1);
     }
     .hero::before { content: ''; position: absolute; inset: 0; background: rgba(0, 0, 0, 0.45); z-index: 1; }
     .hero-content { position: relative; z-index: 2; text-align: center; width: 100%; max-width: 900px; padding: clamp(28px, 6vw, 60px) clamp(18px, 5vw, 50px); animation: fadeIn 0.8s ease 0.3s backwards; }

     @keyframes slideDown { from { opacity:0; transform:translateY(-10px); } to { opacity:1; transform:translateY(0); } }
     @keyframes fadeIn    { from { opacity:0; } to { opacity:1; } }
     @keyframes slideUp   { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
     @keyframes overlayFadeIn { to { opacity: 1; } }
     @keyframes modalSlideUp { to { transform: translateY(0); opacity: 1; } }

     .hero h1 { font-size: clamp(1.8rem, 6.5vw, 4rem); margin-bottom: clamp(12px, 2.5vw, 24px); overflow-wrap: break-word; color: #fff; font-weight: 900; letter-spacing: -1.5px; line-height: 1.08; text-shadow: 0 12px 40px rgba(0,0,0,0.7); }
     .hero p  { color: rgba(255,255,255,0.98); font-size: clamp(1rem, 2.4vw, 1.3rem); margin-bottom: 0; font-weight: 500; line-height: 1.7; text-shadow: 0 8px 20px rgba(0,0,0,0.6); }

     .heading { margin: 10px 0 24px 0; animation: slideDown 0.4s ease; }
     .heading h3 { font-size: 2rem; color: var(--dark-brown); margin-bottom: 8px; font-weight: 900; letter-spacing: -0.5px; }
     .heading p { color: var(--gray-brown); font-size: 1.05em; }

     .cart-wrap { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 420px); gap: var(--gap); max-width: var(--max-width); margin: 0 auto; padding: 0 18px; }

     .card { background: linear-gradient(135deg,#FFFBF7 0%,#FEFDFB 100%); border-radius: var(--radius); padding: 28px; box-shadow: var(--shadow); border: 1.5px solid #F0E6D8; transition: all .3s ease; animation: slideUp .4s ease; min-width: 0; }
     .card:hover { box-shadow: var(--shadow-hover); border-color: var(--primary-red); transform: translateY(-2px); }
     .card:focus-within { transform: none; }
     .card h2 { margin-bottom: 18px; font-size: 1.4rem; font-weight: 900; color: var(--dark-brown); letter-spacing: -0.5px; }

     .items { display: grid; gap: 18px; }
     .cart-item { display: flex; gap: 14px; align-items: flex-start; background: linear-gradient(135deg,#FFF9F3 0%,#FFFBF7 100%); border-radius: 12px; padding: 14px; border: 1.5px solid #F0E6D8; box-shadow: 0 4px 12px rgba(94,31,19,0.05); transition: all .3s ease; }
     .cart-item:hover { box-shadow: var(--shadow); border-color: var(--primary-red); transform: translateY(-2px); }

     .delete-link { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; background: linear-gradient(135deg,rgba(198,69,62,0.1) 0%,rgba(198,69,62,0.05) 100%); color: #D97E6A; border: 1.5px solid rgba(198,69,62,0.2); border-radius: 8px; text-decoration: none; font-weight: 800; font-size: 1.05em; cursor: pointer; transition: all .3s ease; flex-shrink: 0; margin-top: 2px; }
     .delete-link:hover { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border-color: var(--primary-red); transform: scale(1.05); }

     .cart-item img { width: 130px; height: 110px; object-fit: cover; border-radius: 10px; flex-shrink: 0; box-shadow: 0 4px 12px rgba(94,31,19,0.1); transition: transform .3s ease; }
     .cart-item:hover img { transform: scale(1.05); }

     .item-body { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 10px; }
     .item-title { overflow-wrap: anywhere; font-weight: 900; color: var(--dark-brown); font-size: 1.15rem; min-height: 40px; line-height: 1.3; }
     .item-meta { color: var(--gray-brown); font-weight: 700; font-size: .98em; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }
     .item-meta .size-type-tag { display: inline-flex; align-items: center; gap: 6px; font-size: .78rem; font-weight: 700; color: var(--primary-red); background: rgba(198,69,62,0.08); border: 1px solid rgba(198,69,62,0.18); padding: 4px 10px; border-radius: 20px; }
     .item-price { font-weight: 900; color: var(--primary-red); font-size: 1.15em; }
     .item-controls { display: flex; gap: 10px; align-items: center; margin-top: 8px; flex-wrap: wrap; }
     .item-controls input[type="number"] { width: 90px; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #F0E6D8; font-weight: 700; background: linear-gradient(135deg,#FFF2E0 0%,#FFFBF7 100%); color: var(--dark-brown); transition: all .3s ease; }
     .item-controls form { flex-wrap: wrap; }
     .item-controls input[type="number"]:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,0.1); }

     .option-btn { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border: none; padding: 10px 14px; border-radius: 8px; text-decoration: none; cursor: pointer; font-weight: 800; font-size: .95em; transition: all .3s ease; box-shadow: 0 2px 6px rgba(198,69,62,0.2); }
     .option-btn:hover { background: linear-gradient(135deg,var(--dark-brown) 0%,#3D1608 100%); transform: translateY(-2px); box-shadow: 0 4px 12px rgba(94,31,19,0.3); }

     .sub-total { font-weight: 800; color: var(--primary-red); margin-top: 8px; font-size: 1em; }
     .sub-total span { color: var(--primary-red); font-weight: 900; font-size: 1.1em; }

     .empty { padding: 32px 24px; text-align: center; color: var(--gray-brown); font-weight: 800; border-radius: 12px; background: linear-gradient(135deg,#FFFBF7 0%,#FEFDFB 100%); border: 2px dashed #F0E6D8; font-size: 1.05em; }
     .empty i { font-size: 2.5rem; margin-bottom: 12px; color: var(--primary-red); }

     .summary { display: flex; flex-direction: column; gap: 14px; height: fit-content; position: sticky; top: 20px; }
     .summary h3 { margin: 0; font-size: 1.3rem; font-weight: 900; color: var(--dark-brown); }
     .summary-row { gap: 12px; display: flex; align-items: center; justify-content: space-between; font-weight: 700; color: var(--dark-brown); font-size: 1em; padding: 8px 0; }
     .summary-row.divider { border-top: 1.5px solid #F0E6D8; padding-top: 14px; margin-top: 12px; }
     .summary .grand { font-size: 1.3rem; color: var(--dark-brown); font-weight: 900; }
     .summary .grand span { color: var(--primary-red); font-size: 1.25em; }

     .flex { display: flex; gap: 12px; justify-content: center; margin-top: 18px; }
     .btn { padding: 12px 16px; border-radius: 10px; font-weight: 800; border: none; cursor: pointer; display: inline-flex; align-items: center; justify-content: center; gap: 8px; font-size: .98em; transition: all .3s ease; text-decoration: none; box-shadow: 0 4px 12px rgba(94,31,19,0.1); position: relative; overflow: hidden; }
     .btn::before { content:''; position:absolute; top:0; left:-100%; width:100%; height:100%; background:linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent); transition:left .5s ease; }
     .btn:hover::before { left: 100%; }
     .btn-primary { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; box-shadow: 0 4px 12px rgba(198,69,62,0.2); }
     .btn-primary:hover:not(.disabled) { background: linear-gradient(135deg,var(--dark-brown) 0%,#3D1608 100%); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(94,31,19,0.2); }
     .btn-outline { background: linear-gradient(135deg,#FFF2E0 0%,var(--light-cream) 100%); border: 1.5px solid #F0E6D8; color: var(--primary-red); font-weight: 700; box-shadow: 0 2px 6px rgba(94,31,19,0.05); }
     .btn-outline:hover:not(.disabled) { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border-color: var(--primary-red); transform: translateY(-2px); }
     .btn-ghost { background: rgba(255,255,255,0.6); border: 1.5px solid #F0E6D8; color: var(--dark-brown); font-weight: 700; box-shadow: 0 2px 6px rgba(94,31,19,0.05); }
     .btn-ghost:hover { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border-color: var(--primary-red); transform: translateY(-2px); }

     .delete-btn { display: inline-block; padding: 10px 14px; border-radius: 10px; background: linear-gradient(135deg,rgba(198,69,62,0.1) 0%,rgba(198,69,62,0.05) 100%); border: 1.5px solid rgba(198,69,62,0.2); color: var(--primary-red); text-decoration: none; font-weight: 800; margin-top: 12px; text-align: center; cursor: pointer; transition: all .3s ease; width: 100%; box-shadow: 0 2px 6px rgba(198,69,62,0.1); }
     .delete-btn:hover:not(.disabled) { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border-color: var(--primary-red); transform: translateY(-2px); }
     .delete-btn.disabled, .btn.disabled { opacity: .5; pointer-events: none; cursor: not-allowed; }

     .messages { margin: 18px 0 20px 0; animation: slideDown .4s ease; }
     .messages .message { background: linear-gradient(135deg,rgba(198,69,62,0.1) 0%,rgba(198,69,62,0.05) 100%); border-left: 5px solid var(--primary-red); padding: 14px 18px; border-radius: var(--radius); color: #8B3D37; font-weight: 700; font-size: 1.05em; box-shadow: 0 4px 12px rgba(198,69,62,0.08); display: flex; gap: 10px; align-items: center; }
     .messages .message::before { content: '✓'; font-weight: 900; font-size: 1.2em; flex-shrink: 0; }

     /* Item customization (preferences / extras) display — matches Item.php styling */
     .item-customization { display: flex; flex-direction: column; gap: 6px; margin-top: 2px; }
     .customization-line { display: flex; align-items: flex-start; gap: 6px; font-size: .85rem; font-weight: 600; color: var(--gray-brown); background: rgba(198,69,62,0.05); border: 1px solid rgba(198,69,62,0.15); border-radius: 8px; padding: 6px 10px; }
     .customization-line i { color: var(--primary-red); margin-top: 2px; flex-shrink: 0; }
     .customization-line strong { color: var(--dark-brown); font-weight: 800; }

     /* =====================================================
        CHECKOUT MODAL (popup, opened from the Checkout button)
        ===================================================== */
     .checkout-overlay, .receipt-overlay {
       position: fixed; inset: 0; background: rgba(30,10,5,0.72); backdrop-filter: blur(6px);
       z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px;
       opacity: 0;
     }
     .checkout-overlay.show, .receipt-overlay.show { display: flex; animation: overlayFadeIn .3s ease forwards; }

     .checkout-modal {
       background: #fff; border-radius: 20px; width: 100%; max-width: 720px; max-height: 92vh; overflow-y: auto;
       box-shadow: 0 32px 80px rgba(94,31,19,0.35); transform: translateY(30px); opacity: 0;
       animation: modalSlideUp .4s cubic-bezier(.22,1,.36,1) forwards;
       scrollbar-width: thin; scrollbar-color: #F0E6D8 transparent;
     }
     .checkout-modal::-webkit-scrollbar { width: 6px; }
     .checkout-modal::-webkit-scrollbar-thumb { background: #F0E6D8; border-radius: 4px; }

     .checkout-modal-header {
       background: linear-gradient(135deg,var(--primary-red) 0%,#8B2020 100%); padding: 20px 24px;
       display: flex; align-items: center; justify-content: space-between; border-radius: 20px 20px 0 0;
       position: sticky; top: 0; z-index: 2;
     }
     .checkout-modal-header h2 { color: #fff; font-size: 1.4rem; font-weight: 900; letter-spacing: -0.5px; }
     .checkout-modal-close { background: rgba(255,255,255,0.15); border: none; color: #fff; width: 34px; height: 34px; border-radius: 50%; font-size: 1.2rem; cursor: pointer; transition: all .3s ease; }
     .checkout-modal-close:hover { background: rgba(255,255,255,0.3); transform: rotate(90deg); }

     .checkout-modal-body { padding: 22px 24px 24px; }
     .checkout-section { margin-bottom: 22px; }
     .checkout-section-title { font-size: .95rem; text-transform: uppercase; letter-spacing: .6px; color: var(--primary-red); font-weight: 800; margin-bottom: 12px; display: flex; align-items: center; gap: 8px; }

     .receipt-table { width: 100%; border-collapse: collapse; background: #fff; color: #333; font-size: .92rem; margin-bottom: 4px; box-shadow: 0 4px 12px rgba(94,31,19,0.05); border-radius: 10px; overflow: hidden; }
     .receipt-table th, .receipt-table td { padding: 10px 12px; border-bottom: 1px solid #F0E6D8; text-align: left; }
     .receipt-table thead { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; text-transform: uppercase; font-weight: 800; font-size: .78rem; letter-spacing: .5px; }
     .receipt-table tfoot td { font-weight: 900; font-size: .98rem; border-top: 2px solid #F0E6D8; background: linear-gradient(135deg,#f8f8f8 0%,#fafafa 100%); }
     .grand-total td { color: var(--primary-red); font-weight: 900; font-size: 1.05em; }
     .receipt-table .product-cell-name { font-weight: 800; }
     .receipt-table .product-cell-custom { display: block; margin-top: 4px; font-size: .78rem; font-weight: 600; color: var(--gray-brown); }
     .receipt-table .product-cell-custom i { color: var(--primary-red); margin-right: 3px; }

     .note-box { background: linear-gradient(135deg,#FFFAF5 0%,#FFF9F3 100%); border: 1.5px dashed #F0E6D8; border-radius: 10px; padding: 12px 14px; font-size: .85rem; color: var(--gray-brown); font-weight: 600; margin-bottom: 12px; }

     .instruction-field { margin-bottom: 14px; }
     .instruction-field label { display: block; font-weight: 700; font-size: .88rem; color: var(--dark-brown); margin-bottom: 6px; }
     .instruction-field .opt-tag { color: var(--gray-brown); font-weight: 600; font-size: .78rem; }
     .instruction-field textarea {
       width: 100%; min-height: 60px; padding: 10px 12px; border-radius: 8px; border: 1.5px solid #F0E6D8;
       background: linear-gradient(135deg,#FFFAF5 0%,#FFFBF7 100%); font-size: .92rem; color: var(--dark-brown);
       resize: vertical; font-family: inherit; transition: all .3s ease;
     }
     .instruction-field textarea:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,0.1); }

     .discount-section, .wallet-panel { background: linear-gradient(135deg,#FFFBF7 0%,#FEFDFB 100%); padding: 16px 18px; border-radius: 14px; border: 2px solid #F0E6D8; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(94,31,19,0.05); }
     .discount-section h3, .wallet-panel h3 { margin: 0 0 12px; color: var(--dark-brown); font-size: 1.05rem; font-weight: 900; }
     .discount-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

     .checkout .box {
       width: 100%; padding: 11px 13px; border-radius: 10px; border: 1.5px solid #F0E6D8;
       background: linear-gradient(135deg,#FFFAF5 0%,#FFFBF7 100%); margin-bottom: 12px; font-size: .95rem;
       color: var(--dark-brown); transition: all .3s ease; font-family: inherit; font-weight: 500;
     }
     .checkout .box:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,0.12); }
     .checkout label { font-weight: 700; margin-bottom: 6px; display: block; color: var(--dark-brown); font-size: .92rem; }
     .field-hidden { display: none !important; }

     .payment-option-note { font-size: .8rem; color: var(--gray-brown); font-weight: 600; margin: -6px 0 12px; }

     .checkout-place-actions { display: flex; gap: 10px; margin-top: 8px; flex-wrap: wrap; }
     .checkout-place-actions .btn { flex: 1; min-width: 140px; justify-content: center; }

     /* Receipt modal */
     .receipt-modal { background: #fff; border-radius: 20px; width: 100%; max-width: 560px; max-height: 90vh; overflow-y: auto; box-shadow: 0 32px 80px rgba(94,31,19,0.35); transform: translateY(40px); opacity: 0; animation: modalSlideUp .45s cubic-bezier(.22,1,.36,1) forwards; scrollbar-width: thin; scrollbar-color: #F0E6D8 transparent; }
     .receipt-modal::-webkit-scrollbar { width: 6px; }
     .receipt-modal::-webkit-scrollbar-thumb { background: #F0E6D8; border-radius: 4px; }
     #receipt-printable { padding: 0; font-size: 12px; }
     .receipt-header { background: linear-gradient(135deg,var(--primary-red) 0%,#8B2020 100%); padding: 18px 16px 14px; text-align: center; position: relative; border-radius: 20px 20px 0 0; }
     .receipt-header::after { content:''; position:absolute; bottom:-10px; left:0; right:0; height:20px; background:#fff; clip-path: ellipse(55% 100% at 50% 100%); }
     .receipt-cafe-name { font-size: 1.15rem; font-weight: 900; color: #fff; letter-spacing: -0.5px; margin-bottom: 2px; text-shadow: 0 2px 8px rgba(0,0,0,0.25); }
     .receipt-cafe-tagline { font-size: .65rem; color: rgba(255,255,255,0.8); font-weight: 500; letter-spacing: 1px; text-transform: uppercase; }
     .receipt-success-badge { display: inline-flex; align-items: center; gap: 6px; background: rgba(255,255,255,0.18); border: 1px solid rgba(255,255,255,0.35); border-radius: 50px; padding: 4px 12px; margin-top: 10px; color: #fff; font-size: .7rem; font-weight: 700; letter-spacing: .5px; }
     .receipt-success-badge .check-icon { width: 16px; height: 16px; background: #4ade80; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: .6rem; flex-shrink: 0; }
     .receipt-body { padding: 20px 16px 16px; }
     .receipt-order-id { text-align: center; margin-bottom: 16px; padding-bottom: 14px; border-bottom: 2px dashed #E8B4AB; }
     .receipt-order-id .order-num { font-size: 1.4rem; font-weight: 900; color: var(--dark-brown); letter-spacing: -0.5px; }
     .receipt-order-id .order-date { font-size: .7rem; color: var(--gray-brown); margin-top: 3px; font-weight: 500; }
     .receipt-divider { border: none; border-top: 2px dashed #F0E6D8; margin: 12px 0; }
     .receipt-info-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 8px; margin-bottom: 14px; }
     .receipt-info-item { background: #FFFAF5; border: 1px solid #F0E6D8; border-radius: 8px; padding: 8px 10px; }
     .receipt-info-item .info-label { font-size: .6rem; color: var(--gray-brown); text-transform: uppercase; letter-spacing: .8px; font-weight: 700; margin-bottom: 2px; }
     .receipt-info-item .info-value { font-size: .75rem; font-weight: 700; color: var(--dark-brown); word-break: break-word; }
     .receipt-info-item.full-width { grid-column: 1/-1; }
     .receipt-items-title { font-size: .65rem; text-transform: uppercase; letter-spacing: .8px; color: var(--gray-brown); font-weight: 800; margin-bottom: 8px; }
     .receipt-item-row { display: flex; justify-content: space-between; align-items: flex-start; gap: 8px; padding: 6px 0; border-bottom: 1px solid #F5EDE4; }
     .receipt-item-row:last-child { border-bottom: none; }
     .receipt-item-row .item-desc { flex: 1; }
     .receipt-item-row .item-name { font-size: .75rem; font-weight: 700; color: var(--dark-brown); }
     .receipt-item-row .item-meta2 { font-size: .65rem; color: var(--gray-brown); margin-top: 1px; font-weight: 500; }
     .receipt-item-row .item-custom { font-size: .65rem; color: var(--primary-red); margin-top: 2px; font-weight: 600; }
     .receipt-item-row .item-sub { font-size: .75rem; font-weight: 800; color: var(--dark-brown); white-space: nowrap; }
     .receipt-notes-box { background:#FFF7ED; border:1px dashed #E8C99A; border-radius:8px; padding:8px 10px; margin-top:10px; font-size:.68rem;  }
     .receipt-notes-box p { font-size:.68rem; color:#8a5a1f; font-weight:600; margin:2px 0; }
     .receipt-totals { background: #FFFAF5; border: 1px solid #F0E6D8; border-radius: 8px; padding: 12px 12px; margin-top: 14px; }
     .receipt-total-row { display: flex; justify-content: space-between; align-items: center; padding: 4px 0; font-size: .73rem; color: var(--gray-brown); font-weight: 600; }
     .receipt-total-row.discount-row { color: #16a34a; }
     .receipt-total-row.grand { border-top: 1.5px solid #E8D5C4; margin-top: 6px; padding-top: 8px; font-size: .9rem; font-weight: 900; color: var(--primary-red); }
     .receipt-footer-note { text-align: center; margin-top: 14px; font-size: .65rem; color: var(--gray-brown); font-weight: 500; line-height: 1.5; }
     .receipt-barcode-area { display: flex; flex-direction: column; align-items: center; margin-top: 14px; padding-top: 14px; border-top: 2px dashed #F0E6D8; }
     .receipt-barcode-lines { display: flex; gap: 1.5px; height: 24px; align-items: flex-end; margin-bottom: 4px; }
     .receipt-barcode-lines span { display: block; width: 2px; background: var(--dark-brown); border-radius: .5px; }
     .receipt-barcode-num { font-size: .6rem; color: var(--gray-brown); letter-spacing: 1.5px; font-weight: 600; }
     .receipt-modal-actions { display: flex; gap: 10px; padding: 0 16px 16px; flex-wrap: wrap; }
     .receipt-modal-actions .btn { flex: 1; min-width: 120px; justify-content: center; padding: 10px 16px; font-size: .9em; }
     .btn-close-modal { background: rgba(94,31,19,0.07); color: var(--dark-brown); border: 1.5px solid #F0E6D8; box-shadow: none; }
     .btn-close-modal::before { display: none; }
     .btn-close-modal:hover { background: var(--dark-brown); color: #fff; border-color: var(--dark-brown); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(94,31,19,0.2); }
     .payment-pill { display: inline-flex; align-items: center; gap: 4px; background: linear-gradient(135deg,#FFF2E0,#FFE5C4); border: 1px solid #E8C99A; border-radius: 50px; padding: 2px 8px; font-size: .7rem; font-weight: 700; color: var(--dark-brown); }

     /* ============================================
        RESPONSIVE
        ============================================ */
     .messages .message { overflow-wrap: anywhere; }

     @media (max-width: 1100px) {
       .cart-wrap { grid-template-columns: minmax(0, 1fr); }
       .summary { position: static; }
     }

     @media (max-width: 800px) {
       .content-wrapper { padding: 0 14px 48px; }
       .cart-wrap { padding: 0; } /* the wrapper already supplies the side padding */
       .hero { min-height: 420px; }
       .heading h3 { font-size: 1.6rem; }
       .card { padding: 20px; }
       .cart-item { flex-direction: column; gap: 12px; padding: 12px; }
       .cart-item img { width: 100%; height: 180px; }
       .cart-item .delete-link { align-self: flex-end; margin-top: 0; }
       .flex { flex-direction: column; }
       .btn { width: 100%; }
       .delete-btn { width: 100%; }
       .checkout-place-actions { flex-direction: column; }
       .checkout-place-actions .btn { min-width: 100%; }

       /* Pop-ups use the whole phone screen */
       .checkout-overlay, .receipt-overlay { padding: 10px; }
       .checkout-modal { max-height: 94vh; max-height: 94dvh; border-radius: 16px; }
       .checkout-modal-header { border-radius: 16px 16px 0 0; }
       .receipt-modal { max-height: 94vh; max-height: 94dvh; }
       .receipt-table { font-size: .85rem; min-width: 460px; } /* scrolls sideways inside its own box instead of squishing */
       .receipt-table th, .receipt-table td { padding: 8px; }

       /* 16px stops iOS from zooming the page when a field is focused */
       .checkout .box, .instruction-field textarea, .item-controls input[type="number"] { font-size: 16px; }
       /* Comfortable tap targets */
       .btn, .option-btn, .delete-link { min-height: 44px; }
       .delete-link { min-width: 44px; }
     }

     @media (max-width: 480px) {
       .content-wrapper { padding: 0 12px 36px; }
       .hero { min-height: 340px; }
       .heading h3 { font-size: 1.4rem; }
       .card { padding: 16px; }
       .card h2 { font-size: 1.2rem; }
       .cart-item img { height: 160px; }
       .item-title { font-size: 1rem; min-height: 0; }
       .item-controls { width: 100%; }
       .item-controls input[type="number"] { width: auto; flex: 1 1 70px; min-width: 70px; }
       .option-btn { flex: 1 1 100%; width: 100%; }
       .summary-row { font-size: .95em; }
       .summary .grand { font-size: 1.1rem; }
       .checkout-modal-body { padding: 16px; }
       .checkout-modal-header { padding: 16px; }
       .checkout-modal-header h2 { font-size: 1.2rem; }
       .receipt-body { padding: 18px 12px 14px; }
       .receipt-cafe-name { font-size: 1rem; }
       .receipt-order-id .order-num { font-size: 1.2rem; }
     }

     @media (max-width: 360px) {
       .card { padding: 14px; }
       .receipt-info-grid { grid-template-columns: minmax(0, 1fr); }
     }

     /* Hover "lift" effects feel sticky on touch screens and shift the cards while you tap */
     @media (hover: none) {
       .card:hover, .cart-item:hover { transform: none; }
       .cart-item:hover img { transform: none; }
     }
   </style>
</head>
<body>

<?php include 'header.php'; ?>

<section class="hero" aria-labelledby="cartTitle" style="background: linear-gradient(135deg, rgba(0,0,0,0.52) 0%, rgba(0,0,0,0.65) 100%), url('images/aboback.png') center/cover no-repeat;">
  <div class="hero-content">
    <h1 id="cartTitle">Your Shopping Cart</h1>
    <p>Review your items and check out right here — no page hopping needed.</p>
  </div>
</section>

<div class="content-wrapper">
  <main class="page" role="main" aria-labelledby="cartTitle">

    <?php if (!empty($page_messages)): ?>
       <div class="messages" aria-live="polite" aria-atomic="true">
          <?php foreach ($page_messages as $msg): ?>
             <div class="message"><?php echo escapeOutput($msg); ?></div>
          <?php endforeach; ?>
       </div>
    <?php endif; ?>

    <header class="heading">
      <h3><i class="fa-solid fa-bag-shopping"></i> Shopping Cart</h3>
      <p style="margin-top: 8px;">Review your items and check out when ready.</p>
    </header>

    <div class="cart-wrap" role="region" aria-label="Cart content">
      <section class="card" aria-labelledby="productsHeading">
        <h2 id="productsHeading"><i class="fa-solid fa-bag-shopping"></i> Items in Cart</h2>
        <div class="items" aria-live="polite" aria-atomic="true">
           <?php if (!empty($cart_items)): ?>
              <?php foreach ($cart_items as $fetch_cart):
                 $type_meta = $SIZE_TYPE_META[$fetch_cart['size_type']] ?? $SIZE_TYPE_META['cup'];
              ?>
              <div class="cart-item" role="group" aria-label="<?php echo escapeOutput($fetch_cart['name']); ?>">
                 <a href="cart.php?delete=<?php echo (int)$fetch_cart['id']; ?>&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>" class="delete-link" onclick="return confirm('Remove this item from cart?');" title="Remove item" aria-label="Remove <?php echo escapeOutput($fetch_cart['name']); ?>">
                   <i class="fa-solid fa-trash-alt"></i>
                 </a>
                 <img src="images/<?php echo escapeOutput($fetch_cart['image']); ?>" alt="<?php echo escapeOutput($fetch_cart['name']); ?>" loading="lazy">
                 <div class="item-body">
                    <div class="item-title"><?php echo escapeOutput($fetch_cart['name']); ?></div>
                    <div class="item-meta">
                      <span class="size-type-tag"><i class="fa-solid <?php echo $type_meta['icon']; ?>"></i> <?php echo escapeOutput($type_meta['label']); ?></span>
                      <span><i class="fa-solid fa-ruler"></i> <strong><?php echo escapeOutput($fetch_cart['size']); ?></strong></span>
                    </div>
                    <?php if (!empty($fetch_cart['preferences']) || !empty($fetch_cart['extras'])): ?>
                    <div class="item-customization">
                       <?php if (!empty($fetch_cart['preferences'])): ?>
                       <div class="customization-line"><i class="fa-solid fa-sliders"></i> <span><strong>Preference:</strong> <?php echo escapeOutput($fetch_cart['preferences']); ?></span></div>
                       <?php endif; ?>
                       <?php if (!empty($fetch_cart['extras'])): ?>
                       <div class="customization-line"><i class="fa-solid fa-plus"></i> <span><strong>Extras:</strong> <?php echo escapeOutput($fetch_cart['extras']); ?></span></div>
                       <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <div class="item-price"><i class="fa-solid fa-tag"></i> ₱<?php echo number_format($fetch_cart['price'], 2); ?></div>
                    <div class="item-controls">
                       <form action="" method="post" style="display:flex; gap:10px; align-items:center; margin:0; width:100%;">
                          <label for="qty-<?php echo $fetch_cart['id']; ?>" style="font-weight:700; color:var(--primary-red); white-space:nowrap;"><i class="fa-solid fa-boxes"></i> Qty:</label>
                          <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
                          <input type="hidden" name="cart_id" value="<?php echo (int)$fetch_cart['id']; ?>">
                          <input type="number" id="qty-<?php echo $fetch_cart['id']; ?>" min="1" max="50" name="cart_quantity" value="<?php echo intval($fetch_cart['quantity']); ?>" aria-label="Quantity for <?php echo escapeOutput($fetch_cart['name']); ?>">
                          <button type="submit" name="update_cart" class="option-btn" aria-label="Update quantity"><i class="fa-solid fa-sync"></i> Update</button>
                       </form>
                    </div>
                    <div class="sub-total"><i class="fa-solid fa-receipt"></i> Subtotal: <span>₱<?php echo number_format($fetch_cart['line_subtotal'], 2); ?></span></div>
                 </div>
              </div>
              <?php endforeach; ?>
           <?php else: ?>
              <div class="empty"><i class="fa-solid fa-cart-shopping"></i><div style="margin-top:10px;">Your cart is empty</div><div style="margin-top:12px;"><a href="Item.php" style="color:var(--primary-red); text-decoration:none; font-weight:700;"><i class="fa-solid fa-bag-shopping"></i> Start Shopping</a></div></div>
           <?php endif; ?>
        </div>
      </section>

      <aside class="summary card" aria-labelledby="summaryTitle">
        <h3 id="summaryTitle"><i class="fa-solid fa-receipt"></i> Order Summary</h3>
        <div class="summary-row"><span><i class="fa-solid fa-calculator"></i> Items Total</span><span style="font-weight:900;">₱<?php echo number_format($grand_total, 2); ?></span></div>
        <div class="summary-row"><span><i class="fa-solid fa-shipping-fast"></i> Delivery / Pickup Fee</span><span style="font-weight:900; color:var(--gray-brown);">Calculated at checkout</span></div>
        <div class="summary-row divider"><span class="grand"><i class="fa-solid fa-crown"></i> Estimated Total</span><span class="grand">₱<?php echo number_format($grand_total + $preview_vat, 2); ?>+</span></div>

        <div class="flex" style="margin-top:18px;">
           <a href="Item.php" class="btn btn-outline" style="flex:1; text-align:center;"><i class="fa-solid fa-arrow-left"></i> Continue Shopping</a>
           <button type="button" id="checkoutBtn" class="btn btn-primary <?php echo ($grand_total > 0) ? '' : 'disabled'; ?>" style="flex:1; text-align:center;" onclick="openCheckoutModal()">
             <i class="fa-solid fa-arrow-right"></i> Checkout
           </button>
        </div>

        <button onclick="if(confirm('Clear your cart?')) window.location='cart.php?delete_all=1&csrf_token=<?php echo urlencode($_SESSION['csrf_token']); ?>'" class="delete-btn <?php echo ($grand_total > 0) ? '' : 'disabled'; ?>" aria-label="Delete all items from cart">
           <i class="fa-solid fa-trash-alt"></i> Clear Cart
        </button>
      </aside>
    </div>
  </main>
</div>

<!-- =====================================================
     CHECKOUT MODAL
     ===================================================== -->
<div class="checkout-overlay" id="checkoutOverlay" role="dialog" aria-modal="true" aria-labelledby="checkoutModalTitle">
  <div class="checkout-modal" id="checkoutModal">
    <div class="checkout-modal-header">
      <h2 id="checkoutModalTitle"><i class="fa-solid fa-receipt"></i> Checkout</h2>
      <button type="button" class="checkout-modal-close" onclick="closeCheckoutModal()" title="Close">×</button>
    </div>

    <div class="checkout-modal-body">
      <?php if (empty($cart_items)): ?>
        <p class="small">Your cart is empty.</p>
      <?php else: ?>

      <!-- Order review -->
      <div class="checkout-section">
        <div class="checkout-section-title"><i class="fa-solid fa-bag-shopping"></i> Your Order</div>
        <div style="overflow-x:auto;">
          <table class="receipt-table">
            <thead><tr><th>Product</th><th>Size</th><th>Qty</th><th>Unit Price</th><th>Subtotal</th></tr></thead>
            <tbody>
              <?php foreach ($cart_items as $it): ?>
              <tr>
                <td>
                  <span class="product-cell-name"><?php echo escapeOutput($it['name']); ?></span>
                  <?php if (!empty($it['preferences'])): ?>
                    <span class="product-cell-custom"><i class="fa-solid fa-sliders"></i><?php echo escapeOutput($it['preferences']); ?></span>
                  <?php endif; ?>
                  <?php if (!empty($it['extras'])): ?>
                    <span class="product-cell-custom"><i class="fa-solid fa-plus"></i><?php echo escapeOutput($it['extras']); ?></span>
                  <?php endif; ?>
                </td>
                <td><?php echo escapeOutput($it['size']); ?></td>
                <td><?php echo (int)$it['quantity']; ?></td>
                <td>₱<?php echo number_format($it['price'], 2); ?></td>
                <td>₱<?php echo number_format($it['line_subtotal'], 2); ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
            <tfoot>
              <tr><td colspan="4" style="text-align:right;">Subtotal:</td><td>₱<?php echo number_format($grand_total, 2); ?></td></tr>
              <tr><td colspan="4" style="text-align:right;">VAT (12%):</td><td>₱<?php echo number_format($preview_vat, 2); ?></td></tr>
              <tr id="feeRow"><td colspan="4" style="text-align:right;" id="feeLabel">Delivery Fee:</td><td id="feeValue">₱<?php echo number_format($preview_delivery_fee, 2); ?></td></tr>
              <?php if ($preview_auto_discount_amount > 0): ?>
              <tr><td colspan="4" style="text-align:right;"><?php echo escapeOutput($preview_auto_discount_label); ?>:</td><td>-₱<?php echo number_format($preview_auto_discount_amount, 2); ?></td></tr>
              <?php endif; ?>
              <?php if ($preview_code_discount_amount > 0): ?>
              <tr id="codeDiscountRow"><td colspan="4" style="text-align:right;"><?php echo escapeOutput($preview_code_discount_label); ?>:</td><td id="codeDiscountValue">-₱<?php echo number_format($preview_code_discount_amount, 2); ?></td></tr>
              <?php endif; ?>
              <tr class="grand-total"><td colspan="4" style="text-align:right;"><strong>Total:</strong></td><td id="grandTotalValue"><strong>₱<?php echo number_format($preview_final_total, 2); ?></strong></td></tr>
            </tfoot>
          </table>
        </div>
      </div>

      <!-- Optional special instructions, only for items the admin has flagged -->
      <?php if (!empty($special_instruction_items)): ?>
      <div class="checkout-section">
        <div class="checkout-section-title"><i class="fa-solid fa-comment-dots"></i> Special Instructions <span class="opt-tag">(optional)</span></div>
        <div class="note-box">Need something specific with an item? Let us know below — totally optional, your order goes through either way.</div>
        <?php foreach ($special_instruction_items as $it): ?>
          <div class="instruction-field">
            <label for="note-<?php echo (int)$it['id']; ?>"><?php echo escapeOutput($it['name']); ?> (<?php echo escapeOutput($it['size']); ?>) <span class="opt-tag">— optional</span></label>
            <textarea id="note-<?php echo (int)$it['id']; ?>" name="special_instructions[<?php echo (int)$it['id']; ?>]" form="placeOrderForm" maxlength="300" placeholder="e.g. less ice, no whipped cream, extra icing..."></textarea>
          </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>

      <!-- Discount code -->
      <?php if (!$is_guest): ?>
      <div class="checkout-section">
        <div class="checkout-section-title"><i class="fa-solid fa-tag"></i> Discount Code</div>
        <div class="discount-section">
          <form method="post" class="discount-form" novalidate>
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <select name="discount_code" class="box" style="margin-bottom:0;" required>
              <option value="" disabled <?php echo empty($active_discount_code) ? 'selected' : ''; ?>>Select a discount code</option>
              <?php foreach ($discounts as $d):
                 $d_type_disp = $d['type'] ?? 'fixed';
                 $amount_display = ($d_type_disp === 'percentage') ? $d['amount'] . '%' : '₱' . number_format($d['amount'], 2);
              ?>
                <option value="<?php echo escapeOutput($d['code']); ?>" <?php echo ($active_discount_code === $d['code']) ? 'selected' : ''; ?>>
                  <?php echo escapeOutput($d['code']) . ' — ' . $amount_display; ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button type="submit" name="apply_discount" class="btn btn-primary">Apply</button>
            <?php if (!empty($active_discount_code)): ?>
              <button type="submit" name="remove_discount" class="btn btn-ghost">Remove</button>
            <?php endif; ?>
          </form>
        </div>
      </div>
      <?php endif; ?>

      <!-- Wallet summary (shown/hidden by JS) -->
      <div id="wallet-summary" class="wallet-panel" style="display:none;">
        <h3><i class="fa-solid fa-wallet"></i> Wallet Summary</h3>
        <table style="width:100%; border-collapse:collapse;">
          <tr><td style="padding:6px 0;">Wallet Balance</td><td style="text-align:right; padding:6px 0;">₱<span id="walletBalance"><?php echo number_format($wallet_balance, 2); ?></span></td></tr>
          <tr><td style="padding:6px 0;">Order Total</td><td style="text-align:right; padding:6px 0;">₱<span id="orderTotalForWallet"><?php echo number_format($preview_final_total, 2); ?></span></td></tr>
          <tr style="font-weight:800; border-top:2px solid #F0E6D8;"><td style="padding:6px 0;">Remaining Balance</td><td style="text-align:right; padding:6px 0;">₱<span id="remainingBalance"></span></td></tr>
        </table>
      </div>

      <!-- Contact + payment + address -->
      <form action="" method="post" class="checkout" id="placeOrderForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">

        <div class="checkout-section">
          <div class="checkout-section-title"><i class="fa-solid fa-user"></i> Contact Details</div>
          <input type="text"  name="name"   placeholder="Your Name"   required class="box" value="<?php echo escapeOutput($user_name); ?>" maxlength="100" />
          <input type="tel"   name="number" placeholder="Your Number" required class="box" maxlength="15" />
          <input type="email" name="email"  placeholder="Your Email"  required class="box" value="<?php echo escapeOutput($user_email); ?>" maxlength="100" />
        </div>

        <div class="checkout-section">
          <div class="checkout-section-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div>
          <select name="method" id="payment-method" class="box" required>
            <option value="" disabled selected>-- Select Payment Method --</option>
            <option value="cash on delivery">Cash on Delivery</option>
            <?php if (!$is_guest): ?>
            <option value="wallet balance">Wallet Balance (Delivery)</option>
            <option value="pickup">Store Pickup (Pay via Wallet)</option>
            <?php endif; ?>
          </select>
          <?php if ($is_guest): ?>
            <div class="payment-option-note"><i class="fa-solid fa-circle-info"></i> Log in to also unlock Wallet Balance and Store Pickup.</div>
          <?php else: ?>
            <div class="payment-option-note" id="pickupNote" style="display:none;"><i class="fa-solid fa-circle-info"></i> Store Pickup is paid via Wallet Balance only and has no delivery fee.</div>
          <?php endif; ?>
        </div>

        <div class="checkout-section" id="addressSection">
          <div class="checkout-section-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div>
          <input type="text" name="flat"     id="addr-flat"     placeholder="Flat No." class="box" maxlength="50" />
          <input type="text" name="street"   id="addr-street"   placeholder="Street"   class="box" maxlength="100" />
          <input type="text" name="city"     id="addr-city"     placeholder="City"     class="box" maxlength="50" />
          <input type="text" name="pin_code" id="addr-pin"      placeholder="Pin Code" class="box" maxlength="10" />
        </div>

        <div class="checkout-place-actions">
          <button type="button" class="btn btn-ghost" onclick="closeCheckoutModal()"><i class="fa-solid fa-times"></i> Cancel</button>
          <button type="submit" name="order_btn" class="btn btn-primary"><i class="fa-solid fa-check-circle"></i> Place Order</button>
        </div>
      </form>

      <?php endif; ?>
    </div>
  </div>
</div>

<!-- =====================================================
     RECEIPT MODAL — shown right after a successful order
     ===================================================== -->
<?php if ($order_receipt_data):
  $r = $order_receipt_data;
  $barcode_num = str_pad($r['order_id'], 6, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5($r['order_id']), 0, 8));
?>
<div class="receipt-overlay show" id="receiptOverlay" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
  <div class="receipt-modal" id="receiptModal">
    <div id="receipt-printable">
      <div class="receipt-header">
        <div class="receipt-cafe-name">☕ Six Origins Cafe</div>
        <div class="receipt-cafe-tagline">Where Every Cup Tells a Story</div>
        <div class="receipt-success-badge"><span class="check-icon">✓</span> Order Confirmed</div>
      </div>

      <div class="receipt-body">
        <div class="receipt-order-id">
          <div class="order-num"># <?php echo str_pad($r['order_id'], 6, '0', STR_PAD_LEFT); ?></div>
          <div class="order-date"><?php echo escapeOutput($r['placed_on']); ?> &nbsp;·&nbsp; <?php echo date('h:i A'); ?></div>
        </div>

        <hr class="receipt-divider">

        <div class="receipt-info-grid">
          <div class="receipt-info-item"><div class="info-label">Customer</div><div class="info-value"><?php echo escapeOutput($r['name']); ?></div></div>
          <div class="receipt-info-item"><div class="info-label">Contact</div><div class="info-value"><?php echo escapeOutput($r['number']); ?></div></div>
          <div class="receipt-info-item"><div class="info-label">Email</div><div class="info-value" style="font-size:.7rem;"><?php echo escapeOutput($r['email']); ?></div></div>
          <div class="receipt-info-item">
            <div class="info-label">Payment</div>
            <div class="info-value">
              <span class="payment-pill">
                <i class="fas <?php echo $r['is_pickup'] ? 'fa-store' : ($r['method'] === 'wallet balance' ? 'fa-wallet' : 'fa-motorcycle'); ?>" style="font-size:.6rem;"></i>
                <?php echo $r['is_pickup'] ? 'Store Pickup (Wallet)' : ucwords(escapeOutput($r['method'])); ?>
              </span>
            </div>
          </div>
          <div class="receipt-info-item full-width"><div class="info-label"><?php echo $r['is_pickup'] ? 'Pickup Location' : 'Delivery Address'; ?></div><div class="info-value"><?php echo escapeOutput($r['address']); ?></div></div>
        </div>

        <hr class="receipt-divider">

        <div class="receipt-items-title">Order Items</div>
        <?php foreach ($r['items'] as $item): ?>
        <div class="receipt-item-row">
          <div class="item-desc">
            <div class="item-name"><?php echo escapeOutput($item['name']); ?></div>
            <div class="item-meta2"><?php echo (int)$item['qty']; ?> × <?php echo escapeOutput($item['size']); ?> &nbsp;·&nbsp; ₱<?php echo number_format($item['price'], 2); ?></div>
            <?php if (!empty($item['preferences'])): ?>
              <div class="item-custom"><i class="fa-solid fa-sliders"></i> <?php echo escapeOutput($item['preferences']); ?></div>
            <?php endif; ?>
            <?php if (!empty($item['extras'])): ?>
              <div class="item-custom"><i class="fa-solid fa-plus"></i> <?php echo escapeOutput($item['extras']); ?></div>
            <?php endif; ?>
          </div>
          <div class="item-sub">₱<?php echo number_format($item['sub'], 2); ?></div>
        </div>
        <?php endforeach; ?>

        <?php if (!empty($r['special_instructions'])): ?>
        <div class="receipt-notes-box">
          <strong style="font-size:.68rem;">Special Instructions:</strong>
          <?php foreach ($r['special_instructions'] as $note): ?>
            <p>• <?php echo escapeOutput($note); ?></p>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="receipt-totals">
          <div class="receipt-total-row"><span>Subtotal</span><span>₱<?php echo number_format($r['cart_total'], 2); ?></span></div>
          <div class="receipt-total-row"><span>VAT (12%)</span><span>₱<?php echo number_format($r['vat'], 2); ?></span></div>
          <div class="receipt-total-row"><span><?php echo $r['is_pickup'] ? 'Pickup Fee' : 'Delivery Fee'; ?></span><span>₱<?php echo number_format($r['delivery_fee'], 2); ?></span></div>
          <?php if ($r['auto_discount_amount'] > 0): ?>
          <div class="receipt-total-row discount-row"><span>— <?php echo escapeOutput($r['auto_discount_label']); ?></span><span>-₱<?php echo number_format($r['auto_discount_amount'], 2); ?></span></div>
          <?php endif; ?>
          <?php if ($r['code_discount_amount'] > 0): ?>
          <div class="receipt-total-row discount-row"><span>— <?php echo escapeOutput($r['code_discount_label']); ?></span><span>-₱<?php echo number_format($r['code_discount_amount'], 2); ?></span></div>
          <?php endif; ?>
          <div class="receipt-total-row grand"><span>Total Paid</span><span>₱<?php echo number_format($r['final_total'], 2); ?></span></div>
        </div>

        <div class="receipt-barcode-area">
          <div class="receipt-barcode-lines" aria-hidden="true">
            <?php
              srand($r['order_id']);
              for ($i = 0; $i < 35; $i++) {
                  $h = rand(12, 24);
                  $w = ($i % 4 === 0) ? 4 : (($i % 3 === 0) ? 3 : 2);
                  echo "<span style=\"height:{$h}px; width:{$w}px;\"></span>";
              }
            ?>
          </div>
          <div class="receipt-barcode-num"><?php echo $barcode_num; ?></div>
        </div>

        <div class="receipt-footer-note">Thank you for your order!<br>Contact: hello@sixoriginscafe.ph</div>
      </div>
    </div>

    <div class="receipt-modal-actions">
      <button class="btn btn-primary" id="downloadReceiptBtn" onclick="downloadReceiptPDF()"><i class="fas fa-download"></i> Download PDF</button>
      <a href="orders.php" class="btn btn-ghost"><i class="fas fa-list"></i> My Orders</a>
      <button class="btn btn-close-modal" onclick="closeReceiptModal()"><i class="fas fa-times"></i> Close</button>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  /* ============ CHECKOUT MODAL OPEN/CLOSE ============ */
  function openCheckoutModal() {
     var btn = document.getElementById('checkoutBtn');
     if (btn && btn.classList.contains('disabled')) return;
     document.getElementById('checkoutOverlay').classList.add('show');
  }
  function closeCheckoutModal() {
     document.getElementById('checkoutOverlay').classList.remove('show');
  }
  document.getElementById('checkoutOverlay').addEventListener('click', function (e) {
     if (e.target === this) closeCheckoutModal();
  });
  <?php if ($open_checkout_on_load && !empty($cart_items)): ?>
  document.addEventListener('DOMContentLoaded', openCheckoutModal);
  <?php endif; ?>

  /* ============ PAYMENT METHOD / WALLET / PICKUP TOGGLING ============ */
  (function () {
    var CART_TOTAL          = <?php echo json_encode($grand_total); ?>;
    var VAT                 = <?php echo json_encode($preview_vat); ?>;
    var AUTO_DISCOUNT       = <?php echo json_encode($preview_auto_discount_amount); ?>;
    var CODE_DISCOUNT_TYPE  = <?php echo json_encode($active_discount_type); ?>;
    var CODE_DISCOUNT_RAW   = <?php echo json_encode($active_discount_raw_amount); ?>;
    var WALLET_BALANCE      = <?php echo json_encode($wallet_balance); ?>;
    var IS_GUEST             = <?php echo json_encode($is_guest); ?>;

    var methodSelect  = document.getElementById('payment-method');
    var addressSection = document.getElementById('addressSection');
    var addrFlat = document.getElementById('addr-flat');
    var addrStreet = document.getElementById('addr-street');
    var addrCity = document.getElementById('addr-city');
    var addrPin = document.getElementById('addr-pin');
    var walletSummary = document.getElementById('wallet-summary');
    var pickupNote = document.getElementById('pickupNote');
    var feeLabel = document.getElementById('feeLabel');
    var feeValue = document.getElementById('feeValue');
    var grandTotalValue = document.getElementById('grandTotalValue');
    var orderTotalForWallet = document.getElementById('orderTotalForWallet');
    var remainingBalanceEl = document.getElementById('remainingBalance');
    var codeDiscountRow = document.getElementById('codeDiscountRow');
    var codeDiscountValue = document.getElementById('codeDiscountValue');

    function money(n) {
      return n.toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }

    function recalc() {
      if (!methodSelect) return;
      var method = methodSelect.value;
      var isPickup = (method === 'pickup');
      var requiresWallet = (method === 'wallet balance' || method === 'pickup');

      var deliveryFee = isPickup ? 0 : 39;
      if (feeLabel) feeLabel.textContent = (isPickup ? 'Pickup Fee:' : 'Delivery Fee:');
      if (feeValue) feeValue.textContent = '₱' + money(deliveryFee);

      var codeDiscount = 0;
      if (CODE_DISCOUNT_RAW > 0) {
        codeDiscount = (CODE_DISCOUNT_TYPE === 'percentage')
          ? (CART_TOTAL + VAT + deliveryFee) * (CODE_DISCOUNT_RAW / 100)
          : CODE_DISCOUNT_RAW;
      }
      if (codeDiscountRow) codeDiscountRow.style.display = codeDiscount > 0 ? '' : 'none';
      if (codeDiscountValue) codeDiscountValue.textContent = '-₱' + money(codeDiscount);

      var total = CART_TOTAL + VAT + deliveryFee - AUTO_DISCOUNT - codeDiscount;
      if (total < 0) total = 0;
      if (grandTotalValue) grandTotalValue.innerHTML = '<strong>₱' + money(total) + '</strong>';
      if (orderTotalForWallet) orderTotalForWallet.textContent = money(total);

      // Address required only for delivery methods
      if (addressSection) addressSection.classList.toggle('field-hidden', isPickup);
      [addrFlat, addrStreet, addrCity, addrPin].forEach(function (el) {
        if (!el) return;
        if (isPickup) { el.removeAttribute('required'); } else { el.setAttribute('required', 'required'); }
      });

      // Wallet summary + pickup note
      if (walletSummary) walletSummary.style.display = requiresWallet && !IS_GUEST ? 'block' : 'none';
      if (pickupNote) pickupNote.style.display = isPickup ? 'block' : 'none';
      if (remainingBalanceEl) {
        var rem = WALLET_BALANCE - total;
        remainingBalanceEl.textContent = rem >= 0 ? money(rem) : 'Insufficient Funds';
      }
    }

    if (methodSelect) {
      methodSelect.addEventListener('change', recalc);
      recalc();
    }

    // Guard: block submitting "wallet balance" / "pickup" without enough funds
    var placeOrderForm = document.getElementById('placeOrderForm');
    if (placeOrderForm) {
      placeOrderForm.addEventListener('submit', function (e) {
        var method = methodSelect ? methodSelect.value : '';
        if ((method === 'wallet balance' || method === 'pickup') && remainingBalanceEl && remainingBalanceEl.textContent === 'Insufficient Funds') {
          e.preventDefault();
          alert('Insufficient wallet balance for this payment method.');
        }
      });
    }
  })();

  /* ============ RECEIPT MODAL ============ */
  function closeReceiptModal() {
    var overlay = document.getElementById('receiptOverlay');
    if (!overlay) return;
    overlay.style.transition = 'opacity 0.3s ease';
    overlay.style.opacity = '0';
    setTimeout(function () { overlay.remove(); }, 320);
  }
  document.addEventListener('DOMContentLoaded', function () {
    var overlay = document.getElementById('receiptOverlay');
    if (overlay) {
      overlay.addEventListener('click', function (e) { if (e.target === overlay) closeReceiptModal(); });
      document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeReceiptModal(); });
    }
    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') closeCheckoutModal();
    });
  });

  async function downloadReceiptPDF() {
    var btn = document.getElementById('downloadReceiptBtn');
    var original = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    btn.disabled = true;
    try {
      var jsPDFLib = window.jspdf.jsPDF;
      var element = document.getElementById('receipt-printable');
      var canvas = await html2canvas(element, { scale: 2, useCORS: true, backgroundColor: '#ffffff', logging: false, allowTaint: true });
      var imgData = canvas.toDataURL('image/png');
      var pdf = new jsPDFLib({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      var pdfWidth = pdf.internal.pageSize.getWidth();
      var pdfHeight = (canvas.height * pdfWidth) / canvas.width;
      var pageH = pdf.internal.pageSize.getHeight();
      if (pdfHeight <= pageH) {
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
      } else {
        var scaleFactor = pageH / pdfHeight;
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight * scaleFactor);
      }
      var orderId = "<?php echo isset($r) ? str_pad($r['order_id'], 6, '0', STR_PAD_LEFT) : '000000'; ?>";
      pdf.save('SixOrigins_Receipt_' + orderId + '.pdf');
    } catch (err) {
      alert('Could not generate PDF. Please try printing the page instead.');
      console.error(err);
    } finally {
      btn.innerHTML = original;
      btn.disabled = false;
    }
  }
</script>

<?php include 'footer.php'; ?>
<script src="Js/script1.js"></script>
</body>
</html>