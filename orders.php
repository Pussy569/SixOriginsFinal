<?php
include 'config.php';

// ✅ UPDATED: Support both registered users and guests
$user_id = $_SESSION['user_id'] ?? null;
$is_guest = $user_id === null;

// If guest, ensure guest_cart_id exists
if ($is_guest) {
    if (!isset($_SESSION['guest_cart_id'])) {
        $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16)); // Secure token
        $_SESSION['guest_created_at'] = time();
    }
    $user_identifier = "guest_" . $_SESSION['guest_cart_id'];
} else {
    $user_identifier = $user_id;
}

// If not logged in AND no guest session, redirect
if ($is_guest && !isset($_SESSION['guest_cart_id'])) {
    $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16));
    $user_identifier = "guest_" . $_SESSION['guest_cart_id'];
}

// ==================== HANDLE CANCEL ORDER (CUSTOMER) ====================
if (isset($_POST['cancel_customer_order'])) {
    include 'inventory_functions.php';
    
    try {
        $order_id = intval($_POST['order_id'] ?? 0);
        
        if ($order_id <= 0) {
            throw new Exception('Invalid order ID');
        }
        
        // Get order details
        $order_stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
        $order_stmt->bind_param("is", $order_id, $user_identifier);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();
        
        if ($order_result->num_rows === 0) {
            throw new Exception('Order not found');
        }
        
        $order = $order_result->fetch_assoc();
        $order_stmt->close();
        
        $current_status = strtolower($order['payment_status']);
        
        // Check if order is already cancelled
        if ($current_status === 'cancelled') {
            throw new Exception('This order is already cancelled');
        }
        
        // Check if order is in "preparing" status
        if ($current_status === 'preparing') {
            // Get timestamp when it changed to preparing
            $time_stmt = $conn->prepare("SELECT cancelled_at FROM orders WHERE id = ?");
            $time_stmt->bind_param("i", $order_id);
            $time_stmt->execute();
            $time_result = $time_stmt->get_result();
            $time_row = $time_result->fetch_assoc();
            $time_stmt->close();
            
            // Check if 10 minutes have passed since "preparing" status
            // For now, we'll allow cancellation. The JavaScript will handle the timer.
        }
        
        // ==================== WASTE MANAGEMENT & INVENTORY RESTORATION ====================
        if ($current_status === 'preparing') {
            // Add to waste management
            addWasteRecord($conn, $order_id, $user_id ?? null);
            
            // Restore inventory (reverse the deduction)
            restoreInventoryForOrder($conn, $order_id);
        }
        // ==================== END WASTE MANAGEMENT ====================
        
        // Cancel the order
        $cancel_stmt = $conn->prepare("UPDATE orders SET payment_status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
        $cancel_stmt->bind_param("i", $order_id);
        
        if (!$cancel_stmt->execute()) {
            throw new Exception('Failed to cancel order');
        }
        $cancel_stmt->close();
        
        // Process refund if payment method was wallet balance
        if (strtolower($order['method']) === 'wallet balance' && !$is_guest) {
            $refund_amount = floatval($order['total_price']);
            $refund_stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $refund_stmt->bind_param("di", $refund_amount, $user_id);
            $refund_stmt->execute();
            $refund_stmt->close();
        }
        
        $_SESSION['message'] = ['type' => 'success', 'text' => '✅ Order cancelled successfully!'];
        
    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }
    
    header('location:orders.php');
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width,initial-scale=1" />
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <title>Orders — Six Origins Cafe</title>
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
        --max-width: 1200px;
        --base-font-size: 16px;
      }

      * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      }

      html, body {
        height: 100%;
      }

      body {
        min-height: 100vh;
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        color: var(--dark-brown);
        -webkit-font-smoothing: antialiased;
        line-height: 1.65;
        font-size: var(--base-font-size);
      }

      .content-wrapper {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 44px 18px 60px;
      }

      .hero {
        position: relative;
        width: 100vw;
        left: 50%;
        right: 50%;
        margin-left: -50vw;
        margin-right: -50vw;
        height: 550px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: slideDown 0.4s ease;
        box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background: linear-gradient(135deg, rgba(0, 0, 0, 0.52) 0%, rgba(0, 0, 0, 0.65) 100%), url('images/aboback.png') center/cover no-repeat fixed;
      }

      @keyframes slideDown {
        from { opacity: 0; transform: translateY(-10px); }
        to { opacity: 1; transform: translateY(0); }
      }

      @keyframes slideUp {
        from { opacity: 0; transform: translateY(20px); }
        to { opacity: 1; transform: translateY(0); }
      }

      .hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.45);
        z-index: 1;
      }

      .hero-content {
        position: relative;
        z-index: 2;
        text-align: center;
        max-width: 900px;
        padding: 60px 50px;
        animation: fadeIn 0.8s ease 0.3s backwards;
      }

      @keyframes fadeIn {
        from { opacity: 0; }
        to { opacity: 1; }
      }

      .hero h1 {
        font-size: 4rem;
        margin-bottom: 24px;
        color: #ffffff;
        font-family: 'Montserrat', sans-serif;
        font-weight: 900;
        letter-spacing: -1.5px;
        line-height: 1.08;
        text-shadow: 0 12px 40px rgba(0, 0, 0, 0.7);
      }

      .hero p {
        color: rgba(255, 255, 255, 0.98);
        font-size: 1.3rem;
        margin-bottom: 0;
        font-weight: 500;
        line-height: 1.7;
        text-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
      }

      .controls {
        display: flex;
        gap: 16px;
        align-items: center;
        justify-content: space-between;
        margin: 28px 0;
        flex-wrap: wrap;
        padding: 20px 24px;
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        border: 1.5px solid #F0E6D8;
        box-shadow: var(--shadow);
        animation: slideUp 0.4s ease;
      }

      .controls .left {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
      }
      
      .controls input[type="search"],
      .controls select {
        padding: 11px 14px;
        border-radius: 10px;
        border: 1.5px solid #F0E6D8;
        background: linear-gradient(135deg, #FFF2E0 0%, #FFFBF7 100%);
        color: var(--dark-brown);
        font-size: 0.95rem;
        font-weight: 500;
        transition: all 0.3s ease;
        min-width: 180px;
        font-family: inherit;
      }

      .controls input[type="search"]:focus,
      .controls select:focus {
        outline: none;
        border-color: var(--primary-red);
        box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
      }

      .controls input[type="search"]::placeholder {
        color: var(--gray-brown);
      }

      .summary-pills {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
      }

      .summary-pill {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        padding: 12px 18px;
        border-radius: 99px;
        font-weight: 800;
        box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
        font-size: 0.98em;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        transition: all 0.3s ease;
        white-space: nowrap;
      }

      .summary-pill:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(198, 69, 62, 0.3);
      }

      .summary-pill i {
        font-size: 1.1rem;
      }

      .summary-pill strong {
        font-weight: 900;
        font-size: 1.15em;
      }

      .orders-grid {
        display: grid;
        grid-template-columns: 1fr;
        gap: 20px;
        animation: slideUp 0.4s ease;
      }

      .order-card {
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        box-shadow: var(--shadow);
        border: 1.5px solid #F0E6D8;
        overflow: hidden;
        transition: all 0.3s ease;
        animation: slideUp 0.4s ease;
      }

      .order-card:hover {
        box-shadow: var(--shadow-hover);
        border-color: var(--primary-red);
        transform: translateY(-4px);
      }

      .order-card.cancelled {
        border-color: #D97E6A;
        opacity: 0.85;
      }

      .card-header {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        padding: 18px 24px;
        color: #fff;
        display: grid;
        grid-template-columns: auto 1fr auto;
        gap: 20px;
        align-items: center;
      }

      .order-card.cancelled .card-header {
        background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
      }

      .date-badge {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 800;
        font-size: 0.95rem;
        background: rgba(255, 255, 255, 0.2);
        padding: 10px 14px;
        border-radius: 8px;
        white-space: nowrap;
      }

      .date-badge i {
        font-size: 1.1rem;
      }

      .order-title {
        font-size: 1.3rem;
        font-weight: 900;
      }

      .order-title-sub {
        font-size: 0.9rem;
        opacity: 0.95;
        font-weight: 600;
        margin-top: 4px;
      }

      .order-total-badge {
        background: rgba(255, 255, 255, 0.2);
        padding: 10px 14px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 0.95rem;
        white-space: nowrap;
      }

      .card-body {
        padding: 24px;
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
        gap: 24px;
      }

      .info-block {
        display: flex;
        flex-direction: column;
        gap: 8px;
      }

      .info-label {
        font-size: 0.85rem;
        font-weight: 800;
        color: var(--primary-red);
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .info-label i {
        font-size: 1rem;
        color: var(--primary-red);
      }

      .info-value {
        font-size: 1rem;
        font-weight: 700;
        color: var(--dark-brown);
        line-height: 1.5;
      }

      .info-value.secondary {
        font-size: 0.95rem;
        color: var(--gray-brown);
        font-weight: 500;
      }

      .info-value.price {
        font-size: 1.5rem;
        color: var(--primary-red);
        font-weight: 900;
      }

      .info-value.wallet {
        font-size: 1.2rem;
        color: var(--primary-red);
        font-weight: 900;
      }

      .products-list {
        list-style: none;
        padding: 0;
        margin: 0;
      }

      .products-list li {
        padding: 8px 0;
        padding-left: 20px;
        position: relative;
        color: var(--gray-brown);
        font-size: 0.95rem;
        font-weight: 500;
        border-bottom: 1px solid #F0E6D8;
      }

      .products-list li::before {
        content: '☕';
        position: absolute;
        left: 0;
        color: var(--primary-red);
        font-size: 0.9em;
      }

      .products-list li:last-child {
        border-bottom: none;
      }

      /* Preference / extras chips shown under each product line */
      .product-custom-tag {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 0.78rem;
        font-weight: 700;
        color: var(--primary-red);
        background: rgba(198, 69, 62, 0.08);
        border: 1px solid rgba(198, 69, 62, 0.18);
        padding: 3px 9px;
        border-radius: 20px;
        margin: 4px 6px 0 0;
      }

      .card-footer {
        padding: 20px 24px;
        border-top: 1.5px solid #F0E6D8;
        display: grid;
        grid-template-columns: 1fr auto;
        gap: 16px;
        align-items: center;
      }

      .status-badge {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 10px 14px;
        border-radius: 10px;
        font-weight: 800;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        border: 1.5px solid;
        white-space: nowrap;
      }

      .status-badge i {
        font-size: 1rem;
      }

      .status-pending {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: #8B3D37;
         border-color: rgba(198, 69, 62, 0.3);
      }

      .status-accepted {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: #8B3D37;
         border-color: rgba(198, 69, 62, 0.3);
      }

      .status-preparing {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: #8B3D37;
         border-color: rgba(198, 69, 62, 0.3);
      }

      .status-out {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: #8B3D37;
         border-color: rgba(198, 69, 62, 0.3);
      }

      .status-completed {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: #8B3D37;
         border-color: rgba(198, 69, 62, 0.3);
      }

      .status-cancelled {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #D97E6A;
         border-color: rgba(217, 126, 106, 0.3);
      }

      .cancel-section {
        display: flex;
        flex-direction: column;
        gap: 8px;
      }

      .cancel-timer {
        font-size: 0.85rem;
        font-weight: 700;
        color: var(--primary-red);
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .cancel-timer i {
        font-size: 1rem;
      }

      .cancel-customer-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
        padding: 12px 16px;
        background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
        color: #D97E6A;
        border: 1.5px solid rgba(217, 126, 106, 0.2);
        border-radius: 10px;
        cursor: pointer;
        font-weight: 800;
        font-size: 0.9rem;
        text-decoration: none;
        transition: all 0.3s ease;
        text-align: center;
      }

      .cancel-customer-btn:hover:not(:disabled) {
        background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
        color: #fff;
        border-color: #D97E6A;
        transform: translateY(-2px);
      }

      .cancel-customer-btn:disabled {
        opacity: 0.5;
        cursor: not-allowed;
      }

      .empty {
        text-align: center;
        color: var(--gray-brown);
        padding: 60px 24px;
        font-size: 1.1em;
      }

      .empty i {
        font-size: 3.5rem;
        margin-bottom: 16px;
        color: var(--primary-red);
        opacity: 0.8;
      }

      .empty a {
        color: var(--primary-red);
        text-decoration: none;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 16px;
        transition: all 0.3s ease;
      }

      .empty a:hover {
        color: var(--dark-brown);
      }

      .guest-badge {
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
        border: 1.5px solid rgba(198, 69, 62, 0.3);
        color: var(--primary-red);
        padding: 12px 16px;
        border-radius: var(--radius);
        font-weight: 700;
        margin-bottom: 20px;
        display: flex;
        gap: 10px;
        align-items: center;
      }

      .guest-badge i {
        font-size: 1.1rem;
      }

      .alert {
        padding: 16px 20px;
        border-radius: var(--radius);
        margin-bottom: 24px;
        display: flex;
        gap: 12px;
        align-items: flex-start;
        animation: slideDown 0.4s ease;
        border-left: 4px solid;
        font-weight: 700;
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

      @media (max-width: 1100px) {
        .controls {
          flex-direction: column;
          align-items: stretch;
        }

        .controls .left {
          flex-direction: column;
        }

        .controls input[type="search"],
        .controls select {
          min-width: 100%;
        }

        .summary-pills {
          width: 100%;
          justify-content: space-between;
        }

        .summary-pill {
          flex: 1;
          justify-content: center;
        }

        .card-header {
          grid-template-columns: 1fr;
          gap: 12px;
        }

        .card-body {
          grid-template-columns: 1fr;
        }

        .card-footer {
          grid-template-columns: 1fr;
          gap: 12px;
        }
      }

      @media (max-width: 768px) {
        .content-wrapper {
          padding: 20px 14px 48px;
        }

        .hero {
          height: 420px;
          background-attachment: scroll;
          padding: 40px 20px;
        }

        .hero h1 {
          font-size: 2.4rem;
          margin-bottom: 16px;
        }

        .hero p {
          font-size: 1.08rem;
        }

        .card-header {
          padding: 16px 18px;
        }

        .card-body {
          padding: 18px;
          gap: 16px;
        }

        .card-footer {
          padding: 16px 18px;
        }

        .date-badge,
        .order-total-badge {
          padding: 8px 12px;
          font-size: 0.85rem;
        }

        .order-title {
          font-size: 1.1rem;
        }

        .summary-pill {
          font-size: 0.9em;
          padding: 10px 14px;
        }

        .guest-badge {
          padding: 10px 14px;
          font-size: 0.95em;
        }
      }

      @media (max-width: 480px) {
        .content-wrapper {
          padding: 18px 12px 36px;
        }

        .hero {
          height: 360px;
          padding: 30px 15px;
        }

        .hero h1 {
          font-size: 1.8rem;
          margin-bottom: 12px;
          line-height: 1.1;
        }

        .hero p {
          font-size: 1rem;
        }

        .controls {
          padding: 16px;
        }

        .card-header {
          padding: 14px 16px;
        }

        .card-body {
          padding: 16px;
        }

        .card-footer {
          padding: 14px 16px;
        }

        .info-value {
          font-size: 0.95rem;
        }

        .guest-badge {
          padding: 10px 12px;
          font-size: 0.9em;
        }

        .cancel-customer-btn {
          width: 100%;
        }
      }
   </style>
</head>
<body>

<?php include 'header.php'; ?>

<!-- HERO SECTION -->
<section class="hero">
  <div class="hero-content">
    <h1>Your Orders</h1>
    <p>Track and review all your orders. Check payment status, product details, and wallet transactions in one place.</p>
  </div>
</section>

<div class="content-wrapper">
  <main role="main">

    <?php
      // ✅ UPDATED: Fetch orders using user_identifier (supports both users and guests)
      $order_query = mysqli_query($conn, "SELECT * FROM `orders` WHERE user_id = '$user_identifier' ORDER BY placed_on ASC, id ASC") or die('query failed');
      $order_rows = [];
      $total_spent = 0.0;
      while($row = mysqli_fetch_assoc($order_query)){
         $order_rows[] = $row;
         if (strtolower($row['payment_status']) !== 'cancelled') {
            $total_spent += floatval($row['total_price']);
         }
      }
      $orders_count = count($order_rows);

      // ✅ UPDATED: Get wallet balance for registered users only
      $current_wallet_balance = 0;
      if (!$is_guest) {
        $user_wallet_q = mysqli_query($conn, "SELECT wallet_balance FROM users WHERE id = '$user_id'") or die('query failed');
        $user_wallet_row = mysqli_fetch_assoc($user_wallet_q);
        $current_wallet_balance = $user_wallet_row ? floatval($user_wallet_row['wallet_balance']) : 0;
      }
    ?>

    <!-- Alert Messages -->
    <?php
        // ✅ FIXED: some pages elsewhere in the project were setting $_SESSION['message']
        // as a plain string instead of the ['type' => ..., 'text' => ...] array shape this
        // page expects. That caused "Undefined array key 'type'/'text'" warnings. This block
        // now normalizes either shape before using it, so it degrades gracefully either way.
        if(isset($_SESSION['message'])){
            $msg = $_SESSION['message'];

            if (is_string($msg)) {
                $msg = ['type' => 'success', 'text' => $msg];
            }

            $msg_type = $msg['type'] ?? 'success';
            $msg_text = $msg['text'] ?? '';

            $icon = match($msg_type) {
                'success' => 'fa-check-circle',
                'error' => 'fa-circle-exclamation',
                default => 'fa-info-circle'
            };

            if ($msg_text !== '') {
                echo '<div class="alert '.htmlspecialchars($msg_type).'"><i class="fa-solid '.$icon.'"></i><div>'.htmlspecialchars($msg_text).'</div></div>';
            }

            unset($_SESSION['message']);
        }
    ?>

    <!-- Guest Badge (show for guests) -->
    <?php if ($is_guest): ?>
    <div class="guest-badge">
      <i class="fa-solid fa-user-secret"></i>
      <span>You are browsing as a <strong>Guest</strong>. Your orders are linked to this browser session.</span>
    </div>
    <?php endif; ?>

    <!-- Controls -->
    <div class="controls" role="region" aria-label="Order controls">
      <div class="left">
        <input id="searchInput" type="search" placeholder="Search by product, address, or name..." aria-label="Search orders" />
        <select id="statusFilter" aria-label="Filter by status">
          <option value="all">All statuses</option>
          <option value="pending">Pending</option>
          <option value="accepted">Accepted</option>
          <option value="preparing">Preparing</option>
          <option value="out for delivery">Out for delivery</option>
          <option value="completed">Completed</option>
          <option value="cancelled">Cancelled</option>
        </select>
      </div>
      <div class="summary-pills" aria-hidden="true">
        <div class="summary-pill">
          <i class="fa-solid fa-receipt"></i>
          <span>Orders: <strong id="ordersCount"><?php echo $orders_count; ?></strong></span>
        </div>
        <div class="summary-pill">
          <i class="fa-solid fa-wallet"></i>
          <span>Total Spent: <strong>₱<?php echo number_format($total_spent, 2); ?></strong></span>
        </div>
        <?php if (!$is_guest): ?>
        <div class="summary-pill">
          <i class="fa-solid fa-coins"></i>
          <span>Wallet: <strong>₱<?php echo number_format($current_wallet_balance, 2); ?></strong></span>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Orders Grid -->
    <div class="orders-grid" id="ordersContainer">
      <?php 
        if ($orders_count > 0):
          $wallet_balance_sim = $current_wallet_balance;
          $display_rows = [];

          for ($i = 0; $i < count($order_rows); $i++) {
             $order = $order_rows[$i];
             $order_total = floatval($order['total_price']);
             $wallet_deduction = '—';
             $remaining_balance = '—';
             if (!$is_guest && $order['method'] === 'wallet balance') {
                $wallet_deduction = "₱" . number_format($order_total, 2);
                $remaining_balance = "₱" . number_format($wallet_balance_sim, 2);
                $wallet_balance_sim += $order_total;
             }
             $display_rows[] = [
               'order' => $order,
               'wallet_deduction' => $wallet_deduction,
               'remaining_balance' => $remaining_balance,
             ];
          }

          // Display in reverse order (newest first)
          for ($i = count($display_rows) - 1; $i >= 0; $i--) {
             $fetch_orders = $display_rows[$i]['order'];
             $wallet_deduction = $display_rows[$i]['wallet_deduction'];
             $remaining_balance = $display_rows[$i]['remaining_balance'];

             // Parse products
             // ✅ UPDATED: products are separated with '||' (instead of a plain comma) because
             // each product entry may itself contain a "[Preference: ...]" / "[Extras: ...]"
             // note added at checkout, and those notes contain commas of their own.
             $order_products_array = array_filter(array_map('trim', explode('||', $fetch_orders['total_products'])), function ($p) {
                return $p !== '';
             });

             // ✅ NEW: Parse per-item special instructions saved by cart.php
             // (stored as newline-separated "Item Name: note" lines)
             $order_special_instructions = [];
             if (!empty($fetch_orders['special_instructions'])) {
                foreach (explode("\n", $fetch_orders['special_instructions']) as $note_line) {
                   $note_line = trim($note_line);
                   if ($note_line !== '') {
                      $order_special_instructions[] = $note_line;
                   }
                }
             }

             // Status styling
             $status = strtolower($fetch_orders['payment_status']);
             $is_cancelled = $status === 'cancelled';
             
             switch ($status) {
                case 'pending': 
                   $payment_status_class = 'status-pending';
                   $status_icon = 'fa-info-circle';
                   break;
                case 'accepted': 
                   $payment_status_class = 'status-accepted';
                   $status_icon = 'fa-thumbs-up';
                   break;
                case 'preparing': 
                   $payment_status_class = 'status-preparing';
                   $status_icon = 'fa-hourglass-end';
                   break;
                case 'out for delivery': 
                   $payment_status_class = 'status-out';
                   $status_icon = 'fa-truck';
                   break;
                case 'completed': 
                   $payment_status_class = 'status-completed';
                   $status_icon = 'fa-check-circle';
                   break;
                case 'cancelled':
                   $payment_status_class = 'status-cancelled';
                   $status_icon = 'fa-ban';
                   break;
                default: 
                   $payment_status_class = '';
                   $status_icon = 'fa-circle-check';
             }

             // Format date
             $placed_date = new DateTime($fetch_orders['placed_on']);
             $short_date = $placed_date->format('M d, Y');
             
             // Get time when it entered "preparing" status
             $preparing_time = null;
             if ($status === 'preparing' && isset($fetch_orders['placed_on'])) {
                // We'll calculate from placed_on + assumed acceptance time
                // For this example, we'll set a timestamp in JavaScript
                $preparing_time = time(); // Current time - will be overridden by JS
             }
      ?>
      <div class="order-card <?php echo $is_cancelled ? 'cancelled' : ''; ?>" data-name="<?php echo strtolower(htmlspecialchars($fetch_orders['name'])); ?>" data-email="<?php echo strtolower(htmlspecialchars($fetch_orders['email'])); ?>" data-address="<?php echo strtolower(htmlspecialchars($fetch_orders['address'])); ?>" data-status="<?php echo strtolower(htmlspecialchars($fetch_orders['payment_status'])); ?>" data-order-id="<?php echo intval($fetch_orders['id']); ?>">
         <!-- Card Header -->
         <div class="card-header">
            <div class="date-badge">
               <i class="fa-solid fa-calendar"></i>
               <?php echo htmlspecialchars($short_date); ?>
            </div>
            <div>
               <div class="order-title"><?php echo htmlspecialchars($fetch_orders['name']); ?></div>
               <div class="order-title-sub">Order #<?php echo htmlspecialchars($fetch_orders['id']); ?></div>
            </div>
            <div class="order-total-badge">
               Total: <strong>₱<?php echo number_format($fetch_orders['total_price'], 2); ?></strong>
            </div>
         </div>

         <!-- Card Body -->
         <div class="card-body">
            <!-- Phone -->
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-phone"></i> Phone
               </div>
               <div class="info-value"><?php echo htmlspecialchars($fetch_orders['number']); ?></div>
            </div>

            <!-- Email -->
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-envelope"></i> Email
               </div>
               <div class="info-value secondary"><?php echo htmlspecialchars($fetch_orders['email']); ?></div>
            </div>

            <!-- Address -->
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-map-marker-alt"></i> Address
               </div>
               <div class="info-value"><?php echo htmlspecialchars($fetch_orders['address']); ?></div>
            </div>

            <!-- Payment Method -->
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-credit-card"></i> Payment Method
               </div>
               <div class="info-value"><?php echo htmlspecialchars($fetch_orders['method']); ?></div>
            </div>

            <!-- Products -->
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-box"></i> Products
               </div>
               <ul class="products-list">
                  <?php foreach ($order_products_array as $product):
                     // Split off any "[Preference: ...]" / "[Extras: ...]" notes appended
                     // at checkout so they can be shown as small tags under the product line.
                     $base_line = $product;
                     $custom_tags = [];
                     if (preg_match_all('/\[(Preference|Extras):\s*(.*?)\]/i', $product, $matches, PREG_SET_ORDER)) {
                        foreach ($matches as $m) {
                           $custom_tags[] = ['label' => $m[1], 'value' => $m[2]];
                        }
                        $base_line = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $product));
                     }
                  ?>
                     <li>
                        <?php echo htmlspecialchars($base_line); ?>
                        <?php if (!empty($custom_tags)): ?>
                           <div style="margin-top:6px;">
                              <?php foreach ($custom_tags as $tag): ?>
                                 <span class="product-custom-tag">
                                    <i class="fa-solid <?php echo strtolower($tag['label']) === 'extras' ? 'fa-plus' : 'fa-sliders'; ?>"></i>
                                    <?php echo htmlspecialchars($tag['label']) . ': ' . htmlspecialchars($tag['value']); ?>
                                 </span>
                              <?php endforeach; ?>
                           </div>
                        <?php endif; ?>
                     </li>
                  <?php endforeach; ?>
               </ul>
            </div>

            <!-- ✅ NEW: Special Instructions (only shown if the customer added any at checkout) -->
            <?php if (!empty($order_special_instructions)): ?>
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-comment-dots"></i> Special Instructions
               </div>
               <ul class="products-list">
                  <?php foreach ($order_special_instructions as $note_line): ?>
                     <li><?php echo htmlspecialchars($note_line); ?></li>
                  <?php endforeach; ?>
               </ul>
            </div>
            <?php endif; ?>

            <!-- Wallet Info (if applicable & registered user) -->
            <?php if (!$is_guest && $fetch_orders['method'] === 'wallet balance'): ?>
            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-coins"></i> Wallet Deduction
               </div>
               <div class="info-value wallet"><?php echo $wallet_deduction; ?></div>
            </div>

            <div class="info-block">
               <div class="info-label">
                  <i class="fa-solid fa-chart-line"></i> Remaining Balance
               </div>
               <div class="info-value wallet"><?php echo $remaining_balance; ?></div>
            </div>
            <?php endif; ?>
         </div>

         <!-- Card Footer -->
         <div class="card-footer">
            <div class="status-badge <?php echo $payment_status_class; ?>">
               <i class="fa-solid <?php echo $status_icon; ?>"></i>
               <?php echo htmlspecialchars(ucfirst($fetch_orders['payment_status'])); ?>
            </div>

            <!-- Cancel Button (only if not cancelled) -->
            <?php if (!$is_cancelled): ?>
            <div class="cancel-section">
               <div class="cancel-timer" style="display: none;" id="timer-<?php echo intval($fetch_orders['id']); ?>">
                  <i class="fa-solid fa-hourglass-end"></i>
                  <span id="timer-text-<?php echo intval($fetch_orders['id']); ?>">10:00</span>
               </div>
               <form method="post" style="width: 100%;">
                  <input type="hidden" name="order_id" value="<?php echo intval($fetch_orders['id']); ?>">
                  <button type="submit" name="cancel_customer_order" class="cancel-customer-btn" id="cancel-btn-<?php echo intval($fetch_orders['id']); ?>" <?php echo $status !== 'preparing' ? 'disabled style="opacity:0.5; cursor: not-allowed;"' : ''; ?>>
                     <i class="fa-solid fa-times-circle"></i> Cancel Order
                  </button>
               </form>
            </div>
            <?php endif; ?>
         </div>
      </div>
      <?php
         }
        else:
      ?>
      <div class="empty">
         <i class="fa-solid fa-inbox"></i>
         <div><strong>No Orders Yet</strong></div>
         <div style="margin-top: 8px; font-size: 0.95em;">Start shopping and come back to track your orders!</div>
         <a href="Item.php">
            <i class="fa-solid fa-bag-shopping"></i> Browse Shop
         </a>
      </div>
      <?php endif; ?>
    </div>
  </main>
</div>

<?php include 'footer.php'; ?>

<script>
  // ==================== 10-MINUTE COUNTDOWN TIMER ====================
  (function(){
    const orders = document.querySelectorAll('[data-order-id]');
    const TEN_MINUTES = 10 * 60; // 600 seconds
    
    orders.forEach(orderCard => {
      const orderStatus = orderCard.dataset.status;
      const orderId = orderCard.dataset.orderId;
      const cancelBtn = document.getElementById(`cancel-btn-${orderId}`);
      const timerDisplay = document.getElementById(`timer-${orderId}`);
      const timerText = document.getElementById(`timer-text-${orderId}`);
      
      if (orderStatus === 'preparing' && cancelBtn) {
        // Get the time from the current time when page loads
        // In a real scenario, you'd get this from the server
        let remainingTime = TEN_MINUTES;
        
        // Show timer and enable button
        timerDisplay.style.display = 'flex';
        cancelBtn.disabled = false;
        cancelBtn.style.opacity = '1';
        cancelBtn.style.cursor = 'pointer';
        
        // Update timer every second
        const timerInterval = setInterval(() => {
          remainingTime--;
          
          // Format time as MM:SS
          const minutes = Math.floor(remainingTime / 60);
          const seconds = remainingTime % 60;
          timerText.textContent = `${minutes}:${String(seconds).padStart(2, '0')}`;
          
          // When time runs out
          if (remainingTime <= 0) {
            clearInterval(timerInterval);
            timerDisplay.style.display = 'none';
            cancelBtn.disabled = true;
            cancelBtn.style.opacity = '0.5';
            cancelBtn.style.cursor = 'not-allowed';
            cancelBtn.title = 'Cancellation window has closed';
          }
        }, 1000);
      } else if (orderStatus !== 'preparing' && cancelBtn) {
        // Disable cancel button for non-preparing orders
        cancelBtn.disabled = true;
        cancelBtn.style.opacity = '0.5';
        cancelBtn.style.cursor = 'not-allowed';
        cancelBtn.title = 'Can only cancel orders in preparing status';
      }
    });
  })();

  // ==================== SEARCH AND FILTER FUNCTIONALITY ====================
  (function(){
    const searchInput = document.getElementById('searchInput');
    const statusFilter = document.getElementById('statusFilter');
    const ordersContainer = document.getElementById('ordersContainer');
    const ordersCount = document.getElementById('ordersCount');

    if (!ordersContainer) return;

    function normalize(s){ return (s||'').toString().trim().toLowerCase(); }

    function filterRows(){
      const q = normalize(searchInput.value);
      const status = statusFilter.value;
      let visible = 0;
      const cards = Array.from(ordersContainer.querySelectorAll('.order-card'));
      
      cards.forEach(card => {
        const name = normalize(card.dataset.name);
        const email = normalize(card.dataset.email);
        const address = normalize(card.dataset.address);
        const statusVal = normalize(card.dataset.status);

        const matchesQuery = !q || name.includes(q) || email.includes(q) || address.includes(q) || card.innerText.toLowerCase().includes(q);
        const matchesStatus = (status === 'all') || (statusVal === status);

        if (matchesQuery && matchesStatus) {
          card.style.display = '';
          visible++;
        } else {
          card.style.display = 'none';
        }
      });
      ordersCount.textContent = visible;
    }

    searchInput && searchInput.addEventListener('input', filterRows);
    statusFilter && statusFilter.addEventListener('change', filterRows);

    // Keyboard shortcut
    document.addEventListener('keydown', function(e){
      if (e.key === '/' && document.activeElement !== searchInput) {
        e.preventDefault();
        searchInput.focus();
      }
    });

    filterRows();
  })();
</script>

<?php include 'chatbot.php'; ?>
<script src="Js/script1.js"></script>
</body>
</html>