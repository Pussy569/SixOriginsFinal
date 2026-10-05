<?php
include 'config.php';

// ============= VALIDATE REQUEST METHOD =============
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ============= VALIDATE REQUEST ORIGIN (CSRF PREVENTION) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateOrigin()) {
        logSecurityEvent('CSRF_ATTEMPT', [
            'page' => 'checkout',
            'ip' => getUserIP(),
            'action' => $_POST['order_btn'] ?? 'unknown'
        ]);
        http_response_code(403);
        die('🔒 Invalid request origin. Request rejected.');
    }
}

// ============= SESSION & USER SETUP =============
$user_id = $_SESSION['user_id'] ?? null;
$is_guest = $user_id === null;

if ($is_guest) {
    if (!isset($_SESSION['guest_cart_id'])) {
        $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16));
        $_SESSION['guest_created_at'] = time();
    }
    $user_identifier = "guest_" . $_SESSION['guest_cart_id'];
} else {
    $user_identifier = (int)$user_id;
}

$order_success_message = null;
$order_success_id = null;
$order_receipt_data = null; // NEW: store receipt data for modal

if (isset($_SESSION['order_success'])) {
    $order_success_message = $_SESSION['order_success'];
    $order_success_id = $_SESSION['order_success_id'] ?? null;
    $order_receipt_data = $_SESSION['order_receipt_data'] ?? null; // NEW
    unset($_SESSION['order_success']);
    unset($_SESSION['order_success_id']);
    unset($_SESSION['order_receipt_data']); // NEW
}

$message = [];
$wallet_balance = 0;
$user_name = '';
$user_email = '';
$user_type = null;
$user_status = null;
$discounts = [];

// ============= FETCH USER DATA (PREPARED STATEMENT) =============
if (!$is_guest) {
    $stmt = $conn->prepare("SELECT user_type, status, wallet_balance, name, email FROM users WHERE id = ?");
    if (!$stmt) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'User fetch prepare failed']);
        die('Database error. Please try again later.');
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'User fetch execute failed']);
        die('Database error. Please try again later.');
    }
    
    $user_query = $stmt->get_result();
    
    if ($user = $user_query->fetch_assoc()) {
        $user_type = $user['user_type'];
        $user_status = $user['status'];
        $wallet_balance = (float)$user['wallet_balance'];
        $user_name = $user['name'] ?? '';
        $user_email = $user['email'] ?? '';
    }
    $stmt->close();

    // ============= FETCH USER DISCOUNTS (PREPARED STATEMENT) =============
    $stmt = $conn->prepare("
        SELECT d.* FROM `user_discounts` ud
        JOIN `discounts` d ON ud.discount_id = d.id
        WHERE ud.user_id = ?
        AND ud.used = 0
        AND d.status = 'active'
        AND d.valid_until >= CURDATE()
    ");
    if (!$stmt) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Discount fetch prepare failed']);
        die('Database error. Please try again later.');
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Discount fetch execute failed']);
        die('Database error. Please try again later.');
    }
    
    $discount_query = $stmt->get_result();
    
    while ($row = $discount_query->fetch_assoc()) {
        $discounts[] = $row;
    }
    $stmt->close();
}

// ============= HANDLE DISCOUNT APPLICATION =============
if (isset($_POST['apply_discount'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'apply_discount']);
        $message[] = '🔒 Security check failed. Please try again.';
    } elseif ($is_guest) {
        $message[] = 'Please log in to use discount codes.';
    } else {
        $selected_code = sanitizeInput($_POST['discount_code'] ?? '');
        $valid_code = false;
        
        foreach ($discounts as $d) {
            if ($d['code'] === $selected_code) {
                $valid_code = true;
                $_SESSION['apply_discount'] = [
                    'code' => $d['code'],
                    'amount' => $d['amount'],
                    'type' => $d['type']
                ];
                logSecurityEvent('DISCOUNT_APPLIED', ['code' => $d['code']]);
                break;
            }
        }
        
        if (!$valid_code) {
            unset($_SESSION['apply_discount']);
            $message[] = 'Invalid discount code selected.';
            logSecurityEvent('INVALID_DISCOUNT', ['attempted_code' => $selected_code]);
        }
    }
    header("Location: checkout.php");
    exit;
}

if (isset($_POST['remove_discount'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'remove_discount']);
        $message[] = '🔒 Security check failed. Please try again.';
    } else {
        unset($_SESSION['apply_discount']);
        logSecurityEvent('DISCOUNT_REMOVED', ['user_id' => $user_id]);
    }
    header("Location: checkout.php");
    exit;
}

// ============= FETCH CART ITEMS (PREPARED STATEMENT) =============
$cart_total = 0;
$cart_products = [];
$cart_items_detail = []; // NEW: detailed cart items for receipt

$stmt = $conn->prepare("SELECT name, price, quantity, size FROM `cart` WHERE user_id = ?");
if (!$stmt) {
    logSecurityEvent('DATABASE_ERROR', ['error' => 'Cart fetch prepare failed']);
    die('Database error. Please try again later.');
}
$stmt->bind_param("s", $user_identifier);
if (!$stmt->execute()) {
    logSecurityEvent('DATABASE_ERROR', ['error' => 'Cart fetch execute failed']);
    die('Database error. Please try again later.');
}

$select_cart = $stmt->get_result();

if ($select_cart->num_rows > 0) {
    while ($cart_item = $select_cart->fetch_assoc()) {
        $cart_products[] = $cart_item['name'] . ' (' . (int)$cart_item['quantity'] . ' x ' . $cart_item['size'] . ')';
        $sub_total = ((float)$cart_item['price'] * (int)$cart_item['quantity']);
        $cart_total += $sub_total;
        // NEW: store detailed item info for receipt
        $cart_items_detail[] = [
            'name' => $cart_item['name'],
            'size' => $cart_item['size'],
            'qty'  => (int)$cart_item['quantity'],
            'price'=> (float)$cart_item['price'],
            'sub'  => $sub_total
        ];
    }
} else {
    $message[] = 'Your cart is empty';
}
$stmt->close();

// ============= CALCULATE TOTALS =============
$vat = $cart_total * 0.12;
$delivery_fee = 39.00;

$auto_discount_amount = 0;
$auto_discount_label = '';
if (!$is_guest && $user_status === 'approved') {
    if ($user_type === 'Senior') {
        $auto_discount_amount = 200;
        $auto_discount_label = 'Senior Discount';
    } elseif ($user_type === 'PWD') {
        $auto_discount_amount = 250;
        $auto_discount_label = 'PWD Discount';
    }
}

$code_discount_amount = 0;
$code_discount_label = '';
$discount_code = '';

if (isset($_SESSION['apply_discount'])) {
    $discount_data = $_SESSION['apply_discount'];
    $discount_code = $discount_data['code'];
    $code_discount_label = 'Promo Code (' . escapeOutput($discount_code) . ')';
    
    if ($discount_data['type'] === 'percentage') {
        $code_discount_amount = ($cart_total + $vat + $delivery_fee) * ((float)$discount_data['amount'] / 100);
    } else {
        $code_discount_amount = (float)$discount_data['amount'];
    }
}

$final_total = $cart_total + $vat + $delivery_fee - $auto_discount_amount - $code_discount_amount;
if ($final_total < 0) $final_total = 0;

// ============= PLACE ORDER =============
if (isset($_POST['order_btn'])) {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_TOKEN_INVALID', ['action' => 'place_order']);
        $message[] = '🔒 Security check failed. Please try again.';
    } else {
        $name     = sanitizeInput($_POST['name'] ?? '');
        $number   = sanitizeInput($_POST['number'] ?? '');
        $email    = sanitizeInput($_POST['email'] ?? '');
        $method   = sanitizeInput($_POST['method'] ?? '');
        $flat     = sanitizeInput($_POST['flat'] ?? '');
        $street   = sanitizeInput($_POST['street'] ?? '');
        $city     = sanitizeInput($_POST['city'] ?? '');
        $pin_code = sanitizeInput($_POST['pin_code'] ?? '');
        $address  = 'flat no. ' . $flat . ', ' . $street . ', ' . $city . ', ' . $pin_code;
        $placed_on = date('d-M-Y');

        $validation_errors = [];
        
        if (empty($name)) {
            $validation_errors[] = 'Name is required.';
        } elseif (strlen($name) > 100) {
            $validation_errors[] = 'Name is too long (max 100 characters).';
        }
        
        if (!validatePhone($number)) {
            $validation_errors[] = 'Invalid phone number (10-15 digits).';
        }

        if (!validateEmail($email)) {
            $validation_errors[] = 'Invalid email address.';
        }

        if ($method !== 'cash on delivery' && !($method === 'wallet balance' && !$is_guest)) {
            $validation_errors[] = 'Invalid payment method.';
            logSecurityEvent('INVALID_PAYMENT_METHOD', ['method' => $method]);
        }

        if (empty($flat) || empty($street) || empty($city) || empty($pin_code)) {
            $validation_errors[] = 'All address fields are required.';
        }

        if ($cart_total == 0) {
            $validation_errors[] = 'Your cart is empty';
        }

        if (!empty($validation_errors)) {
            foreach ($validation_errors as $error) {
                $message[] = escapeOutput($error);
            }
            logSecurityEvent('ORDER_VALIDATION_FAILED', ['errors' => count($validation_errors)]);
        } else {
            $total_products = implode(', ', $cart_products);

            $conn->begin_transaction();

            try {
                if ($method == 'wallet balance') {
                    if ($is_guest) {
                        throw new Exception('Guests cannot use wallet payment. Please choose Cash on Delivery.');
                    }
                    if ($wallet_balance < $final_total) {
                        logSecurityEvent('INSUFFICIENT_WALLET_BALANCE', [
                            'user_id' => $user_id,
                            'balance' => $wallet_balance,
                            'required' => $final_total
                        ]);
                        throw new Exception('Insufficient wallet balance!');
                    }
                    
                    $new_balance = $wallet_balance - $final_total;
                    
                    $stmt = $conn->prepare("UPDATE `users` SET `wallet_balance` = ? WHERE id = ?");
                    if (!$stmt) throw new Exception('Database prepare failed: ' . $conn->error);
                    $stmt->bind_param("di", $new_balance, $user_id);
                    if (!$stmt->execute()) throw new Exception('Wallet update failed: ' . $stmt->error);
                    $stmt->close();
                    
                    logSecurityEvent('WALLET_DEDUCTED', [
                        'user_id'     => $user_id,
                        'amount'      => $final_total,
                        'new_balance' => $new_balance
                    ]);
                }

                $stmt = $conn->prepare("
                    INSERT INTO `orders`(user_id, name, number, email, method, address, total_products, total_price, placed_on) 
                    VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                if (!$stmt) throw new Exception('Database prepare failed: ' . $conn->error);
                $stmt->bind_param("sssssssds", $user_identifier, $name, $number, $email, $method, $address, $total_products, $final_total, $placed_on);
                if (!$stmt->execute()) throw new Exception('Order insertion failed: ' . $stmt->error);
                
                $order_id = $conn->insert_id;
                $stmt->close();

                $stmt = $conn->prepare("DELETE FROM `cart` WHERE user_id = ?");
                if (!$stmt) throw new Exception('Database prepare failed: ' . $conn->error);
                $stmt->bind_param("s", $user_identifier);
                if (!$stmt->execute()) throw new Exception('Cart deletion failed: ' . $stmt->error);
                $stmt->close();

                if (!$is_guest && !empty($discount_code)) {
                    $stmt = $conn->prepare("
                        UPDATE `user_discounts` 
                        SET used = 1 
                        WHERE user_id = ? 
                        AND discount_id = (SELECT id FROM `discounts` WHERE code = ?)
                    ");
                    if (!$stmt) throw new Exception('Database prepare failed: ' . $conn->error);
                    $stmt->bind_param("is", $user_id, $discount_code);
                    if (!$stmt->execute()) throw new Exception('Discount update failed: ' . $stmt->error);
                    $stmt->close();
                    
                    logSecurityEvent('DISCOUNT_MARKED_USED', [
                        'user_id' => $user_id,
                        'code'    => $discount_code
                    ]);
                }

                $conn->commit();

                // NEW: store receipt data in session for modal
                $_SESSION['order_success']      = '✅ Order placed successfully! Your receipt is ready.';
                $_SESSION['order_success_id']   = $order_id;
                $_SESSION['order_receipt_data'] = [
                    'order_id'             => $order_id,
                    'placed_on'            => $placed_on,
                    'name'                 => $name,
                    'number'               => $number,
                    'email'                => $email,
                    'method'               => $method,
                    'address'              => $address,
                    'items'                => $cart_items_detail,
                    'cart_total'           => $cart_total,
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
                    'user_id'  => $user_id,
                    'total'    => $final_total,
                    'method'   => $method
                ]);
                
                header('Location: checkout.php');
                exit();

            } catch (Exception $e) {
                $conn->rollback();
                $error_msg = $e->getMessage();
                $message[] = "❌ Transaction Failed: " . escapeOutput($error_msg);
                
                logSecurityEvent('ORDER_PLACEMENT_FAILED', [
                    'user_id' => $user_id,
                    'error'   => $error_msg
                ]);
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta http-equiv="X-UA-Compatible" content="ie=edge" />
    <title>Checkout — Six Origins Cafe</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
    <!-- jsPDF for PDF download -->
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
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --max-width: 1200px;
      }

      * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
      html, body { height: 100%; width: 100%; overflow-x: hidden; margin: 0; padding: 0; }
      body { min-height: 100vh; background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%); color: var(--dark-brown); line-height: 1.67; font-size: 16px; }

      .content-wrapper { max-width: var(--max-width); margin: 0 auto; padding: 44px 24px 60px; width: 100%; }

      /* HERO */
      .hero { position: relative; width: 100vw; left: 50%; right: 50%; margin-left: -50vw; margin-right: -50vw; margin-top: 0; margin-bottom: 0; height: 550px; overflow: hidden; display: flex; align-items: center; justify-content: center; animation: fadeInDown 0.6s ease-out; box-shadow: 0 8px 32px rgba(94, 31, 19, 0.15); border-bottom: 1px solid rgba(255,255,255,0.1); background-attachment: fixed; }
      .hero::before { content: ''; position: absolute; top: 0; left: 0; right: 0; bottom: 0; background: radial-gradient(circle at center, rgba(0,0,0,0.3) 0%, rgba(0,0,0,0.5) 100%); z-index: 1; pointer-events: none; }
      .hero-content { position: relative; z-index: 2; text-align: center; max-width: 700px; padding: 40px; animation: scaleIn 0.8s ease-out 0.2s both; }
      .hero h1 { font-size: 4.2rem; font-weight: 900; color: #fff; margin: 0; line-height: 1.15; letter-spacing: -1px; text-shadow: 0 10px 30px rgba(0,0,0,0.5); }
      .hero p { color: rgba(255,255,255,0.95); font-size: 1.4rem; margin: 20px 0 0; line-height: 1.6; text-shadow: 0 6px 18px rgba(0,0,0,0.4); font-weight: 500; animation: fadeInUp 0.8s ease-out 0.4s both; }

      @keyframes fadeInDown { from { opacity:0; transform:translateY(-30px); } to { opacity:1; transform:translateY(0); } }
      @keyframes scaleIn    { from { opacity:0; transform:scale(0.95); } to { opacity:1; transform:scale(1); } }
      @keyframes slideUp    { from { opacity:0; transform:translateY(20px); } to { opacity:1; transform:translateY(0); } }
      @keyframes fadeIn     { from { opacity:0; } to { opacity:1; } }
      @keyframes fadeInUp   { from { opacity:0; transform:translateY(30px); } to { opacity:1; transform:translateY(0); } }

      .checkout-wrapper { display: grid; grid-template-columns: 1fr 420px; gap: 32px; align-items: start; animation: fadeIn 0.6s ease-out 0.4s both; }
      .card { background: linear-gradient(135deg,#FFFBF7 0%,#FEFDFB 100%); border-radius: var(--radius); padding: 28px; box-shadow: var(--shadow); border: 1.5px solid #F0E6D8; transition: var(--transition); animation: slideUp 0.4s ease; }
      .card:hover { box-shadow: var(--shadow-hover); border-color: var(--primary-red); transform: translateY(-4px); }
      .card h2 { margin-bottom: 18px; margin-top: 0; color: var(--dark-brown); font-size: 1.8rem; font-weight: 900; letter-spacing: -0.5px; }

      .receipt-table { width: 100%; border-collapse: collapse; background-color: #fff; color: #333; font-size: 0.98rem; margin-bottom: 12px; box-shadow: 0 4px 12px rgba(94,31,19,0.05); border-radius: 10px; overflow: hidden; }
      .receipt-table th, .receipt-table td { padding: 12px 14px; border-bottom: 1px solid #F0E6D8; text-align: left; }
      .receipt-table thead { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: white; text-transform: uppercase; font-weight: 800; font-size: 0.85rem; letter-spacing: 0.5px; }
      .receipt-table tfoot td { font-weight: 900; font-size: 1.05rem; border-top: 2px solid #F0E6D8; background: linear-gradient(135deg,#f8f8f8 0%,#fafafa 100%); }
      .grand-total td { color: var(--primary-red); font-weight: 900; font-size: 1.1em; }

      .discount-section, .wallet-panel { background: linear-gradient(135deg,#FFFBF7 0%,#FEFDFB 100%); padding: 20px; border-radius: var(--radius); border: 2px solid #F0E6D8; margin-bottom: 12px; transition: var(--transition); box-shadow: 0 4px 12px rgba(94,31,19,0.05); }
      .discount-section:hover { border-color: var(--primary-red); box-shadow: 0 6px 16px rgba(198,69,62,0.1); }
      .discount-section h3, .wallet-panel h3 { margin: 0 0 16px; color: var(--dark-brown); font-size: 1.3rem; font-weight: 900; letter-spacing: -0.5px; }
      .discount-form { display: flex; gap: 12px; flex-wrap: wrap; align-items: center; }

      .btn, .btn-ghost { padding: 11px 24px; border-radius: 10px; font-weight: 800; border: 0; display: inline-flex; align-items: center; gap: 8px; font-size: 0.98em; cursor: pointer; transition: var(--transition); text-decoration: none; box-shadow: 0 6px 16px rgba(198,69,62,0.25); white-space: nowrap; position: relative; overflow: hidden; }
      .btn::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg,transparent,rgba(255,255,255,0.2),transparent); transition: left 0.5s ease; }
      .btn:hover::before { left: 100%; }
      .btn-primary { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; }
      .btn-primary:hover { background: linear-gradient(135deg,var(--dark-brown) 0%,#3D1608 100%); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(94,31,19,0.25); }
      .btn-ghost { background: rgba(255,255,255,0.6); border: 1.5px solid #F0E6D8; color: var(--dark-brown); font-weight: 700; box-shadow: 0 2px 6px rgba(94,31,19,0.05); }
      .btn-ghost::before { display: none; }
      .btn-ghost:hover { background: linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%); color: #fff; border-color: var(--primary-red); transform: translateY(-2px); box-shadow: 0 8px 20px rgba(94,31,19,0.25); }

      .checkout .box { width: 100%; padding: 12px 14px; border-radius: 10px; border: 1.5px solid #F0E6D8; background: linear-gradient(135deg,#FFFAF5 0%,#FFFBF7 100%); margin-bottom: 12px; font-size: 0.98rem; color: var(--dark-brown); transition: var(--transition); font-family: inherit; font-weight: 500; }
      .checkout .box:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,0.12); }
      .small { font-size: 0.95rem; color: var(--gray-brown); }

      .notice { background: linear-gradient(135deg,rgba(198,69,62,0.1) 0%,rgba(198,69,62,0.05) 100%); border-left: 5px solid var(--primary-red); padding: 15px 18px; border-radius: var(--radius); font-weight: 600; color: var(--primary-red); margin-bottom: 12px; display: flex; gap: 10px; align-items: center; box-shadow: 0 6px 16px rgba(198,69,62,0.1); border: 1px solid rgba(198,69,62,0.2); animation: slideUp 0.4s ease-out; }
      .notice::before { content: '✓'; font-weight: 900; font-size: 1.3em; flex-shrink: 0; }

      label { font-weight: 700; margin-bottom: 8px; display: block; color: var(--dark-brown); font-size: 0.98rem; }
      .form-group { display: flex; gap: 10px; margin-top: 16px; }
      .form-group .btn { flex: 1; text-align: center; justify-content: center; }

      /* ========================
         RECEIPT MODAL STYLES
         ======================== */
      .receipt-overlay {
        position: fixed;
        inset: 0;
        background: rgba(30, 10, 5, 0.72);
        backdrop-filter: blur(6px);
        z-index: 9999;
        display: flex;
        align-items: center;
        justify-content: center;
        padding: 20px;
        opacity: 0;
        animation: overlayFadeIn 0.4s ease forwards;
      }

      @keyframes overlayFadeIn {
        to { opacity: 1; }
      }

      .receipt-modal {
        background: #fff;
        border-radius: 20px;
        width: 100%;
        max-width: 560px;
        max-height: 90vh;
        overflow-y: auto;
        box-shadow: 0 32px 80px rgba(94, 31, 19, 0.35);
        animation: modalSlideUp 0.45s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        transform: translateY(40px);
        opacity: 0;
        scrollbar-width: thin;
        scrollbar-color: #F0E6D8 transparent;
      }

      .receipt-modal::-webkit-scrollbar { width: 6px; }
      .receipt-modal::-webkit-scrollbar-track { background: transparent; }
      .receipt-modal::-webkit-scrollbar-thumb { background: #F0E6D8; border-radius: 4px; }

      @keyframes modalSlideUp {
        to { transform: translateY(0); opacity: 1; }
      }

      /* ---- The printable receipt area ---- */
      #receipt-printable {
        padding: 0;
        font-size: 12px;
      }

      .receipt-header {
        background: linear-gradient(135deg, var(--primary-red) 0%, #8B2020 100%);
        padding: 18px 16px 14px;
        text-align: center;
        position: relative;
        border-radius: 20px 20px 0 0;
      }

      .receipt-header::after {
        content: '';
        position: absolute;
        bottom: -10px;
        left: 0;
        right: 0;
        height: 20px;
        background: #fff;
        clip-path: ellipse(55% 100% at 50% 100%);
      }

      .receipt-cafe-name {
        font-size: 1.15rem;
        font-weight: 900;
        color: #fff;
        letter-spacing: -0.5px;
        margin-bottom: 2px;
        text-shadow: 0 2px 8px rgba(0,0,0,0.25);
      }

      .receipt-cafe-tagline {
        font-size: 0.65rem;
        color: rgba(255,255,255,0.8);
        font-weight: 500;
        letter-spacing: 1px;
        text-transform: uppercase;
      }

      .receipt-success-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        background: rgba(255,255,255,0.18);
        border: 1px solid rgba(255,255,255,0.35);
        border-radius: 50px;
        padding: 4px 12px;
        margin-top: 10px;
        color: #fff;
        font-size: 0.7rem;
        font-weight: 700;
        letter-spacing: 0.5px;
      }

      .receipt-success-badge .check-icon {
        width: 16px;
        height: 16px;
        background: #4ade80;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
        flex-shrink: 0;
      }

      .receipt-body {
        padding: 20px 16px 16px;
      }

      .receipt-order-id {
        text-align: center;
        margin-bottom: 16px;
        padding-bottom: 14px;
        border-bottom: 2px dashed #E8B4AB;
      }

      .receipt-order-id .order-num {
        font-size: 1.4rem;
        font-weight: 900;
        color: var(--dark-brown);
        letter-spacing: -0.5px;
      }

      .receipt-order-id .order-date {
        font-size: 0.7rem;
        color: var(--gray-brown);
        margin-top: 3px;
        font-weight: 500;
      }

      .receipt-divider {
        border: none;
        border-top: 2px dashed #F0E6D8;
        margin: 12px 0;
      }

      .receipt-info-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-bottom: 14px;
      }

      .receipt-info-item {
        background: #FFFAF5;
        border: 1px solid #F0E6D8;
        border-radius: 8px;
        padding: 8px 10px;
      }

      .receipt-info-item .info-label {
        font-size: 0.6rem;
        color: var(--gray-brown);
        text-transform: uppercase;
        letter-spacing: 0.8px;
        font-weight: 700;
        margin-bottom: 2px;
      }

      .receipt-info-item .info-value {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--dark-brown);
        word-break: break-word;
      }

      .receipt-info-item.full-width {
        grid-column: 1 / -1;
      }

      .receipt-items-title {
        font-size: 0.65rem;
        text-transform: uppercase;
        letter-spacing: 0.8px;
        color: var(--gray-brown);
        font-weight: 800;
        margin-bottom: 8px;
      }

      .receipt-item-row {
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        gap: 8px;
        padding: 6px 0;
        border-bottom: 1px solid #F5EDE4;
      }

      .receipt-item-row:last-child {
        border-bottom: none;
      }

      .receipt-item-row .item-desc {
        flex: 1;
      }

      .receipt-item-row .item-name {
        font-size: 0.75rem;
        font-weight: 700;
        color: var(--dark-brown);
      }

      .receipt-item-row .item-meta {
        font-size: 0.65rem;
        color: var(--gray-brown);
        margin-top: 1px;
        font-weight: 500;
      }

      .receipt-item-row .item-sub {
        font-size: 0.75rem;
        font-weight: 800;
        color: var(--dark-brown);
        white-space: nowrap;
      }

      .receipt-totals {
        background: #FFFAF5;
        border: 1px solid #F0E6D8;
        border-radius: 8px;
        padding: 12px 12px;
        margin-top: 14px;
      }

      .receipt-total-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 4px 0;
        font-size: 0.73rem;
        color: var(--gray-brown);
        font-weight: 600;
      }

      .receipt-total-row.discount-row {
        color: #16a34a;
      }

      .receipt-total-row.grand {
        border-top: 1.5px solid #E8D5C4;
        margin-top: 6px;
        padding-top: 8px;
        font-size: 0.9rem;
        font-weight: 900;
        color: var(--primary-red);
      }

      .receipt-footer-note {
        text-align: center;
        margin-top: 14px;
        font-size: 0.65rem;
        color: var(--gray-brown);
        font-weight: 500;
        line-height: 1.5;
      }

      .receipt-barcode-area {
        display: flex;
        flex-direction: column;
        align-items: center;
        margin-top: 14px;
        padding-top: 14px;
        border-top: 2px dashed #F0E6D8;
      }

      .receipt-barcode-lines {
        display: flex;
        gap: 1.5px;
        height: 24px;
        align-items: flex-end;
        margin-bottom: 4px;
      }

      .receipt-barcode-lines span {
        display: block;
        width: 2px;
        background: var(--dark-brown);
        border-radius: 0.5px;
      }

      .receipt-barcode-num {
        font-size: 0.6rem;
        color: var(--gray-brown);
        letter-spacing: 1.5px;
        font-weight: 600;
      }

      /* Modal action buttons */
      .receipt-modal-actions {
        display: flex;
        gap: 10px;
        padding: 0 16px 16px;
        flex-wrap: wrap;
      }

      .receipt-modal-actions .btn {
        flex: 1;
        min-width: 120px;
        justify-content: center;
        padding: 10px 16px;
        font-size: 0.9em;
      }

      .btn-close-modal {
        background: rgba(94,31,19,0.07);
        color: var(--dark-brown);
        border: 1.5px solid #F0E6D8;
        box-shadow: none;
      }

      .btn-close-modal::before { display: none; }

      .btn-close-modal:hover {
        background: var(--dark-brown);
        color: #fff;
        border-color: var(--dark-brown);
        transform: translateY(-2px);
        box-shadow: 0 6px 16px rgba(94,31,19,0.2);
      }

      /* payment pill */
      .payment-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        background: linear-gradient(135deg, #FFF2E0, #FFE5C4);
        border: 1px solid #E8C99A;
        border-radius: 50px;
        padding: 2px 8px;
        font-size: 0.7rem;
        font-weight: 700;
        color: var(--dark-brown);
      }

      /* Responsive */
      @media (max-width: 1100px) { .checkout-wrapper { grid-template-columns: 1fr; } }
      @media (max-width: 900px) {
        .content-wrapper { padding: 20px 14px 48px; }
        .hero { height: 420px; padding: 40px 20px; background-attachment: scroll; }
        .hero h1 { font-size: 2.8rem; }
        .hero p { font-size: 1.2rem; }
        .card { padding: 24px; }
        .card h2 { font-size: 1.5rem; }
        .receipt-info-grid { grid-template-columns: 1fr; }
      }
      @media (max-width: 768px) {
        .content-wrapper { padding: 18px 12px 36px; }
        .hero { height: 360px; padding: 30px 15px; background-attachment: scroll; }
        .hero h1 { font-size: 2rem; }
        .hero p { font-size: 1rem; }
        .card { padding: 18px; }
        .card h2 { font-size: 1.3rem; }
        .discount-form { gap: 8px; flex-direction: column; }
        .discount-form select { width: 100%; }
        .discount-form .btn { width: 100%; justify-content: center; }
        .form-group { gap: 8px; margin-top: 14px; }
        .receipt-modal-actions { flex-direction: column; }
        .receipt-modal-actions .btn { min-width: 100%; }
        .receipt-header { padding: 16px 14px 12px; }
        .receipt-body { padding: 18px 14px 14px; }
        .receipt-modal-actions { padding: 0 14px 14px; }
      }
      @media (max-width: 480px) {
        .content-wrapper { padding: 16px 10px 32px; }
        .hero { height: 320px; background-attachment: scroll; }
        .hero h1 { font-size: 1.5rem; }
        .hero p { font-size: 0.95rem; }
        .card { padding: 16px; }
        .card h2 { font-size: 1.15rem; }
        .form-group { flex-direction: column; }
        .form-group .btn { width: 100%; }
        .receipt-cafe-name { font-size: 1rem; }
        .receipt-order-id .order-num { font-size: 1.2rem; }
      }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<!-- HERO -->
<section class="hero" aria-labelledby="checkoutTitle" style="background: linear-gradient(135deg,rgba(0,0,0,0.25) 0%,rgba(0,0,0,0.35) 100%), url('images/aboback.png') center/cover no-repeat;">
  <div class="hero-content">
    <h1 id="checkoutTitle">Checkout</h1>
    <p>Review your order and choose a payment method<?php echo !$is_guest ? '. You can apply available promo codes below.' : '.'; ?></p>
  </div>
</section>

<div class="content-wrapper">
  <main class="page" role="main" aria-labelledby="checkoutTitle">

    <?php if (!empty($message)): ?>
      <div>
        <?php foreach ($message as $msg): ?>
          <div class="notice" role="alert"><?php echo escapeOutput($msg); ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="checkout-wrapper" role="region" aria-label="Checkout content">
      <!-- Order summary card -->
      <div class="card">
        <h2>Your Order</h2>
        <?php if (!empty($cart_products)): ?>
          <div style="overflow-x: auto;">
            <table class="receipt-table" aria-describedby="orderSummary">
              <thead>
                <tr>
                  <th>Product</th>
                  <th>Size</th>
                  <th>Qty</th>
                  <th>Unit Price</th>
                  <th>Subtotal</th>
                </tr>
              </thead>
              <tbody>
                <?php 
                $stmt = $conn->prepare("SELECT name, size, quantity, price FROM `cart` WHERE user_id = ?");
                if (!$stmt) { logSecurityEvent('DATABASE_ERROR', ['error' => 'Cart table fetch prepare failed']); die('Database error. Please try again later.'); }
                $stmt->bind_param("s", $user_identifier);
                if (!$stmt->execute()) { logSecurityEvent('DATABASE_ERROR', ['error' => 'Cart table fetch execute failed']); die('Database error. Please try again later.'); }
                $select_cart = $stmt->get_result();
                
                while ($cart_item = $select_cart->fetch_assoc()) {
                    $product_name = escapeOutput($cart_item['name']);
                    $size         = escapeOutput($cart_item['size']);
                    $qty          = (int)$cart_item['quantity'];
                    $unit_price   = (float)$cart_item['price'];
                    $sub_total    = $unit_price * $qty;
                ?>
                <tr>
                  <td><?php echo $product_name; ?></td>
                  <td><?php echo $size; ?></td>
                  <td><?php echo $qty; ?></td>
                  <td>₱<?php echo number_format($unit_price, 2); ?></td>
                  <td>₱<?php echo number_format($sub_total, 2); ?></td>
                </tr>
                <?php } $stmt->close(); ?>
              </tbody>
              <tfoot>
                <tr>
                  <td colspan="4" style="text-align:right;">Subtotal:</td>
                  <td>₱<?php echo number_format($cart_total, 2); ?></td>
                </tr>
                <tr>
                  <td colspan="4" style="text-align:right;">VAT (12%):</td>
                  <td>₱<?php echo number_format($vat, 2); ?></td>
                </tr>
                <tr>
                  <td colspan="4" style="text-align:right;">Delivery Fee:</td>
                  <td>₱<?php echo number_format($delivery_fee, 2); ?></td>
                </tr>
                <?php if ($auto_discount_amount > 0): ?>
                <tr>
                  <td colspan="4" style="text-align:right;"><?php echo escapeOutput($auto_discount_label); ?>:</td>
                  <td>-₱<?php echo number_format($auto_discount_amount, 2); ?></td>
                </tr>
                <?php endif; ?>
                <?php if ($code_discount_amount > 0): ?>
                <tr>
                  <td colspan="4" style="text-align:right;"><?php echo escapeOutput($code_discount_label); ?>:</td>
                  <td>-₱<?php echo number_format($code_discount_amount, 2); ?></td>
                </tr>
                <?php endif; ?>
                <tr class="grand-total">
                  <td colspan="4" style="text-align:right;"><strong>Total:</strong></td>
                  <td><strong>₱<?php echo number_format($final_total, 2); ?></strong></td>
                </tr>
              </tfoot>
            </table>
          </div>

          <?php if (!$is_guest): ?>
          <div class="discount-section" aria-labelledby="discountTitle">
            <h3 id="discountTitle">Apply Discount</h3>
            <form method="post" class="discount-form" novalidate>
              <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
              <select name="discount_code" class="box" style="margin-bottom:0;" required>
                <option value="" disabled selected>Select a discount code</option>
                <?php foreach ($discounts as $d): 
                  $amount_display = ($d['type'] === 'percentage') ? $d['amount'].'%' : '₱'.number_format($d['amount'], 2);
                ?>
                  <option value="<?php echo escapeOutput($d['code']); ?>" <?php echo (isset($discount_code) && $discount_code === $d['code']) ? 'selected' : ''; ?>>
                    <?php echo escapeOutput($d['code']) . ' — ' . $amount_display; ?>
                  </option>
                <?php endforeach; ?>
              </select>
              <button type="submit" name="apply_discount" class="btn btn-primary">Apply</button>
              <?php if (!empty($discount_code)): ?>
                <button type="submit" name="remove_discount" class="btn btn-ghost">Remove</button>
              <?php endif; ?>
            </form>
          </div>
          <?php endif; ?>

          <div id="wallet-summary" class="wallet-panel" style="display:none;">
            <h3>Wallet Summary</h3>
            <table style="width:100%;border-collapse:collapse;">
              <tr>
                <td style="padding:8px 0;">Wallet Balance</td>
                <td style="text-align:right;padding:8px 0;">₱<span id="walletBalance"><?php echo number_format($wallet_balance, 2); ?></span></td>
              </tr>
              <tr>
                <td style="padding:8px 0;">Order Total</td>
                <td style="text-align:right;padding:8px 0;">₱<span id="orderTotal"><?php echo number_format($final_total, 2); ?></span></td>
              </tr>
              <tr style="font-weight:800;border-top:2px solid #F0E6D8;">
                <td style="padding:8px 0;">Remaining Balance</td>
                <td style="text-align:right;padding:8px 0;">₱<span id="remainingBalance"></span></td>
              </tr>
            </table>
          </div>
        <?php else: ?>
          <p class="small">Your cart is empty.</p>
        <?php endif; ?>
      </div>

      <!-- Place order form -->
      <aside class="card">
        <h2>Place Your Order</h2>
        <form action="" method="post" class="checkout" novalidate>
          <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
          <input type="text"  name="name"     placeholder="Your Name"   required class="box" value="<?php echo escapeOutput($_POST['name']   ?? $user_name); ?>" maxlength="100" />
          <input type="tel"   name="number"   placeholder="Your Number" required class="box" value="<?php echo escapeOutput($_POST['number'] ?? ''); ?>"          maxlength="15" />
          <input type="email" name="email"    placeholder="Your Email"  required class="box" value="<?php echo escapeOutput($_POST['email']  ?? $user_email); ?>" maxlength="100" />
          
          <label for="payment-method">Payment Method</label>
          <select name="method" id="payment-method" class="box" required>
            <option value="" disabled selected>-- Select Payment Method --</option>
            <option value="cash on delivery" <?php if (($_POST['method'] ?? '') === 'cash on delivery') echo 'selected'; ?>>Cash on Delivery</option>
            <?php if (!$is_guest): ?>
            <option value="wallet balance"   <?php if (($_POST['method'] ?? '') === 'wallet balance')   echo 'selected'; ?>>Wallet Balance</option>
            <?php endif; ?>
          </select>
          
          <div id="wallet-info" class="wallet-panel" style="display:none;margin-top:12px;">
            <strong>Wallet balance:</strong> ₱<?php echo number_format($wallet_balance, 2); ?>
          </div>
          
          <label style="margin-top:16px;">Delivery Address</label>
          <input type="text" name="flat"     placeholder="Flat No." required class="box" value="<?php echo escapeOutput($_POST['flat']     ?? ''); ?>" maxlength="50"  />
          <input type="text" name="street"   placeholder="Street"   required class="box" value="<?php echo escapeOutput($_POST['street']   ?? ''); ?>" maxlength="100" />
          <input type="text" name="city"     placeholder="City"     required class="box" value="<?php echo escapeOutput($_POST['city']     ?? ''); ?>" maxlength="50"  />
          <input type="text" name="pin_code" placeholder="Pin Code" required class="box" value="<?php echo escapeOutput($_POST['pin_code'] ?? ''); ?>" maxlength="10"  />
          
          <div class="form-group">
            <a href="cart.php" class="btn btn-ghost">Back to Cart</a>
            <button type="submit" name="order_btn" class="btn btn-primary">Place Order</button>
          </div>
        </form>
      </aside>
    </div>
  </main>
</div>

<?php include 'footer.php'; ?>

<!-- =====================================================
     RECEIPT MODAL — shown when order_receipt_data exists
     ===================================================== -->
<?php if ($order_receipt_data): 
  $r = $order_receipt_data;
  // Generate a fake barcode-like number from order id
  $barcode_num = str_pad($r['order_id'], 6, '0', STR_PAD_LEFT) . '-' . strtoupper(substr(md5($r['order_id']), 0, 8));
?>
<div class="receipt-overlay" id="receiptOverlay" role="dialog" aria-modal="true" aria-labelledby="receiptModalTitle">
  <div class="receipt-modal" id="receiptModal">

    <!-- Printable / capture area -->
    <div id="receipt-printable">

      <!-- Header -->
      <div class="receipt-header">
        <div class="receipt-cafe-name">☕ Six Origins Cafe</div>
        <div class="receipt-cafe-tagline">Where Every Cup Tells a Story</div>
        <div class="receipt-success-badge">
          <span class="check-icon">✓</span>
          Order Confirmed
        </div>
      </div>

      <!-- Body -->
      <div class="receipt-body">

        <div class="receipt-order-id">
          <div class="order-num"># <?php echo str_pad($r['order_id'], 6, '0', STR_PAD_LEFT); ?></div>
          <div class="order-date"><?php echo htmlspecialchars($r['placed_on']); ?> &nbsp;·&nbsp; <?php echo date('h:i A'); ?></div>
        </div>

        <hr class="receipt-divider">

        <!-- Customer & delivery info -->
        <div class="receipt-info-grid">
          <div class="receipt-info-item">
            <div class="info-label">Customer</div>
            <div class="info-value"><?php echo htmlspecialchars($r['name']); ?></div>
          </div>
          <div class="receipt-info-item">
            <div class="info-label">Contact</div>
            <div class="info-value"><?php echo htmlspecialchars($r['number']); ?></div>
          </div>
          <div class="receipt-info-item">
            <div class="info-label">Email</div>
            <div class="info-value" style="font-size:0.7rem;"><?php echo htmlspecialchars($r['email']); ?></div>
          </div>
          <div class="receipt-info-item">
            <div class="info-label">Payment</div>
            <div class="info-value">
              <span class="payment-pill">
                <i class="fas <?php echo $r['method'] === 'wallet balance' ? 'fa-wallet' : 'fa-motorcycle'; ?>" style="font-size:0.6rem;"></i>
                <?php echo ucwords(htmlspecialchars($r['method'])); ?>
              </span>
            </div>
          </div>
          <div class="receipt-info-item full-width">
            <div class="info-label">Delivery Address</div>
            <div class="info-value"><?php echo htmlspecialchars($r['address']); ?></div>
          </div>
        </div>

        <hr class="receipt-divider">

        <!-- Items -->
        <div class="receipt-items-title">Order Items</div>
        <?php foreach ($r['items'] as $item): ?>
        <div class="receipt-item-row">
          <div class="item-desc">
            <div class="item-name"><?php echo htmlspecialchars($item['name']); ?></div>
            <div class="item-meta"><?php echo (int)$item['qty']; ?> × <?php echo htmlspecialchars($item['size']); ?> &nbsp;��&nbsp; ₱<?php echo number_format($item['price'], 2); ?></div>
          </div>
          <div class="item-sub">₱<?php echo number_format($item['sub'], 2); ?></div>
        </div>
        <?php endforeach; ?>

        <!-- Totals -->
        <div class="receipt-totals">
          <div class="receipt-total-row">
            <span>Subtotal</span>
            <span>₱<?php echo number_format($r['cart_total'], 2); ?></span>
          </div>
          <div class="receipt-total-row">
            <span>VAT (12%)</span>
            <span>₱<?php echo number_format($r['vat'], 2); ?></span>
          </div>
          <div class="receipt-total-row">
            <span>Delivery Fee</span>
            <span>₱<?php echo number_format($r['delivery_fee'], 2); ?></span>
          </div>
          <?php if ($r['auto_discount_amount'] > 0): ?>
          <div class="receipt-total-row discount-row">
            <span>— <?php echo htmlspecialchars($r['auto_discount_label']); ?></span>
            <span>-₱<?php echo number_format($r['auto_discount_amount'], 2); ?></span>
          </div>
          <?php endif; ?>
          <?php if ($r['code_discount_amount'] > 0): ?>
          <div class="receipt-total-row discount-row">
            <span>— <?php echo htmlspecialchars($r['code_discount_label']); ?></span>
            <span>-₱<?php echo number_format($r['code_discount_amount'], 2); ?></span>
          </div>
          <?php endif; ?>
          <div class="receipt-total-row grand">
            <span>Total Paid</span>
            <span>₱<?php echo number_format($r['final_total'], 2); ?></span>
          </div>
        </div>

        <!-- Barcode area -->
        <div class="receipt-barcode-area">
          <div class="receipt-barcode-lines" aria-hidden="true">
            <?php
              // Generate pseudo-random bar heights based on order id
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

        <div class="receipt-footer-note">
          Thank you for your order!<br>
          Contact: hello@sixoriginscafe.ph
        </div>
      </div>
      <!-- end receipt-body -->
    </div>
    <!-- end receipt-printable -->

    <!-- Action buttons (outside printable area) -->
    <div class="receipt-modal-actions">
      <button class="btn btn-primary" id="downloadReceiptBtn" onclick="downloadReceiptPDF()">
        <i class="fas fa-download"></i> Download PDF
      </button>
      <a href="orders.php" class="btn btn-ghost">
        <i class="fas fa-list"></i> My Orders
      </a>
      <button class="btn btn-close-modal" onclick="closeReceiptModal()">
        <i class="fas fa-times"></i> Close
      </button>
    </div>

  </div><!-- end receipt-modal -->
</div><!-- end receipt-overlay -->
<?php endif; ?>

<script>
  // ============ WALLET PANEL TOGGLE ============
  (function(){
    const methodSelect   = document.getElementById('payment-method');
    const walletInfo     = document.getElementById('wallet-info');
    const walletSummary  = document.getElementById('wallet-summary');
    const walletBalance  = parseFloat("<?= floatval($wallet_balance) ?>");
    const finalTotal     = parseFloat("<?= floatval($final_total) ?>");
    const remainingEl    = document.getElementById('remainingBalance');
    const isGuest        = <?= json_encode($is_guest) ?>;
    
    function updateWalletInfo() {
      if (!methodSelect) return;
      if (methodSelect.value === 'wallet balance' && !isGuest) {
        if (walletInfo)    walletInfo.style.display    = 'block';
        if (walletSummary) walletSummary.style.display = 'block';
        if (remainingEl) {
          const rem = walletBalance - finalTotal;
          remainingEl.textContent = rem >= 0 ? rem.toFixed(2) : 'Insufficient Funds';
        }
      } else {
        if (walletInfo)    walletInfo.style.display    = 'none';
        if (walletSummary) walletSummary.style.display = 'none';
      }
    }
    
    if (methodSelect) {
      methodSelect.addEventListener('change', updateWalletInfo);
      updateWalletInfo();
    }
  })();

  // ============ RECEIPT MODAL ============
  function closeReceiptModal() {
    const overlay = document.getElementById('receiptOverlay');
    if (!overlay) return;
    overlay.style.transition = 'opacity 0.3s ease';
    overlay.style.opacity    = '0';
    setTimeout(() => overlay.remove(), 320);
  }

  // Close on backdrop click
  document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('receiptOverlay');
    if (overlay) {
      overlay.addEventListener('click', function(e) {
        if (e.target === overlay) closeReceiptModal();
      });
      // Close on Escape
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeReceiptModal();
      });
    }
  });

  // ============ PDF DOWNLOAD ============
  async function downloadReceiptPDF() {
    const btn = document.getElementById('downloadReceiptBtn');
    const original = btn.innerHTML;
    btn.innerHTML  = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    btn.disabled   = true;

    try {
      const { jsPDF } = window.jspdf;
      const element   = document.getElementById('receipt-printable');

      const canvas = await html2canvas(element, {
        scale: 2,
        useCORS: true,
        backgroundColor: '#ffffff',
        logging: false,
        allowTaint: true
      });

      const imgData = canvas.toDataURL('image/png');
      const pdf     = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
      
      const pdfWidth  = pdf.internal.pageSize.getWidth();
      const pdfHeight = (canvas.height * pdfWidth) / canvas.width;
      const pageH     = pdf.internal.pageSize.getHeight();

      if (pdfHeight <= pageH) {
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, pdfHeight);
      } else {
        // Multi-page: scale down to fit
        const scaleFactor = pageH / pdfHeight;
        const scaledHeight = pdfHeight * scaleFactor;
        pdf.addImage(imgData, 'PNG', 0, 0, pdfWidth, scaledHeight);
      }

      const orderId = "<?php echo isset($r) ? str_pad($r['order_id'], 6, '0', STR_PAD_LEFT) : '000000'; ?>";
      pdf.save('SixOrigins_Receipt_' + orderId + '.pdf');

    } catch (err) {
      alert('Could not generate PDF. Please try printing the page instead.');
      console.error(err);
    } finally {
      btn.innerHTML = original;
      btn.disabled  = false;
    }
  }
</script>

<?php include 'chatbot.php'; ?>
<script src="Js/script1.js"></script>
</body>
</html>