<?php
/**
 * PROJECT: Six Origins Cafe - Automated Web Application
 * MODULE: Delivery Rider Dashboard
 *
 * A rider must be logged in ($_SESSION['rider_id']) and must be an
 * approved 'delivery_rider' account. From here a rider can:
 *   1. See orders that are ready for delivery ("Done Preparing" & unclaimed)
 *   2. Take an order (claims it, moves it to "Out for Delivery")
 *   3. Mark their own active deliveries as "Completed"
 */

include 'config.php';
include 'inventory_functions.php';
include 'send_receipt_email.php';
include 'send_sms_notification.php';

$rider_id = $_SESSION['rider_id'] ?? null;

if (!$rider_id) {
   header('location:login.php');
   exit;
}

// Re-verify the rider account is a real, approved delivery_rider (defense in depth)
$rider_check = mysqli_query($conn, "SELECT * FROM users WHERE id = " . intval($rider_id) . " LIMIT 1");
$rider_info = $rider_check ? mysqli_fetch_assoc($rider_check) : null;

if (!$rider_info || $rider_info['user_type'] !== 'delivery_rider' || $rider_info['status'] !== 'approved') {
   session_unset();
   session_destroy();
   header('location:login.php');
   exit;
}

$message = [];

// ==================== RIDER TAKES AN ORDER ====================
if (isset($_POST['take_order'])) {
   try {
      $order_id = intval($_POST['order_id'] ?? 0);
      if ($order_id <= 0) {
         throw new Exception('Invalid order ID');
      }

      // Atomic claim: only succeeds if the order is still unclaimed & ready
      $stmt = $conn->prepare("UPDATE orders SET rider_id = ?, payment_status = 'out for delivery', out_for_delivery_at = NOW() WHERE id = ? AND rider_id IS NULL AND payment_status = 'done preparing'");
      $stmt->bind_param("ii", $rider_id, $order_id);
      $stmt->execute();

      if ($stmt->affected_rows > 0) {
         $message[] = ['type' => 'success', 'text' => '✅ Order #' . $order_id . ' is now yours for delivery!'];
      } else {
         $message[] = ['type' => 'error', 'text' => '⚠️ This order was already taken by another rider or is no longer available.'];
      }
      $stmt->close();

   } catch (Exception $e) {
      $message[] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
   }
}

// ==================== RIDER MARKS ORDER AS COMPLETED ====================
if (isset($_POST['complete_order'])) {
   try {
      $order_id = intval($_POST['order_id'] ?? 0);
      if ($order_id <= 0) {
         throw new Exception('Invalid order ID');
      }

      // Confirm this order belongs to this rider and is currently out for delivery
      $check_stmt = $conn->prepare("SELECT * FROM orders WHERE id = ? AND rider_id = ? AND payment_status = 'out for delivery'");
      $check_stmt->bind_param("ii", $order_id, $rider_id);
      $check_stmt->execute();
      $check_result = $check_stmt->get_result();

      if ($check_result->num_rows === 0) {
         throw new Exception('This order is not assigned to you or is no longer active.');
      }
      $order_info = $check_result->fetch_assoc();
      $check_stmt->close();

      // Deduct inventory now that the order is truly completed
      $deduct_result = deductInventoryForOrder($conn, $order_id, $rider_id);

      $update_stmt = $conn->prepare("UPDATE orders SET payment_status = 'completed', completed_at = NOW(), prepared_at = NOW() WHERE id = ?");
      $update_stmt->bind_param("i", $order_id);

      if (!$update_stmt->execute()) {
         throw new Exception('Failed to update order');
      }
      $update_stmt->close();

      // Notify the customer
      $product_list = explode(',', $order_info['total_products']);
      $order_data = [
         'order_id' => $order_info['id'],
         'name' => $order_info['name'],
         'address' => $order_info['address'],
         'email' => $order_info['email'],
         'method' => $order_info['method'],
         'total' => $order_info['total_price'],
         'status' => 'Completed',
         'products' => $product_list
      ];
      sendReceiptEmail($order_data['email'], $order_data['name'], $order_data);

      $customer_phone = isset($order_info['number']) ? trim($order_info['number']) : '';
      $is_guest_order = strpos($order_info['user_id'], 'guest_') === 0;
      if (!$is_guest_order && !empty($customer_phone)) {
         sendOrderStatusSMS($customer_phone, $order_info['id'], 'completed', $order_info['total_price'], $order_info['name']);
      }

      $message[] = ['type' => 'success', 'text' => '🎉 Order #' . $order_id . ' marked as Completed! Customer has been notified.'];

   } catch (Exception $e) {
      $message[] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
   }
}

// ==================== FETCH ORDERS ====================

// Available pool: ready for delivery, not yet claimed by anyone
$available_orders = [];
$available_result = mysqli_query($conn, "SELECT * FROM orders WHERE payment_status = 'done preparing' AND rider_id IS NULL ORDER BY done_preparing_at ASC");
if ($available_result) {
   while ($row = mysqli_fetch_assoc($available_result)) {
      $available_orders[] = $row;
   }
}

// This rider's active deliveries
$my_orders = [];
$my_result = mysqli_query($conn, "SELECT * FROM orders WHERE rider_id = " . intval($rider_id) . " AND payment_status = 'out for delivery' ORDER BY out_for_delivery_at ASC");
if ($my_result) {
   while ($row = mysqli_fetch_assoc($my_result)) {
      $my_orders[] = $row;
   }
}

// This rider's recently completed deliveries
$completed_orders = [];
$completed_result = mysqli_query($conn, "SELECT * FROM orders WHERE rider_id = " . intval($rider_id) . " AND payment_status = 'completed' ORDER BY completed_at DESC LIMIT 15");
if ($completed_result) {
   while ($row = mysqli_fetch_assoc($completed_result)) {
      $completed_orders[] = $row;
   }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>Rider Dashboard — Six Origins Cafe</title>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
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
         min-height: 100vh;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         color: var(--dark-brown);
      }

      .page {
         max-width: 1300px;
         margin: 0 auto;
         padding: 28px 20px 60px;
      }

      @keyframes slideDown {
         from { opacity: 0; transform: translateY(-20px); }
         to { opacity: 1; transform: translateY(0); }
      }

      @keyframes slideUp {
         from { opacity: 0; transform: translateY(20px); }
         to { opacity: 1; transform: translateY(0); }
      }

      .topbar {
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 16px;
         flex-wrap: wrap;
         margin-bottom: 28px;
         animation: slideDown 0.4s ease;
      }

      .topbar h1 {
         font-size: 2rem;
         font-weight: 900;
         letter-spacing: -0.5px;
      }

      .topbar .sub {
         color: var(--gray-brown);
         font-weight: 500;
         margin-top: 6px;
      }

      .topbar .right {
         display: flex;
         align-items: center;
         gap: 12px;
      }

      .rider-badge {
         display: flex;
         align-items: center;
         gap: 10px;
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border: 1.5px solid #F0E6D8;
         border-radius: var(--radius);
         padding: 10px 16px;
         font-weight: 700;
         box-shadow: var(--shadow);
      }

      .rider-badge i {
         color: var(--primary-red);
         font-size: 1.2rem;
      }

      .logout-btn {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         padding: 11px 18px;
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #D97E6A;
         border: 1.5px solid rgba(217, 126, 106, 0.2);
         border-radius: var(--radius);
         font-weight: 800;
         text-decoration: none;
         transition: var(--transition);
      }

      .logout-btn:hover {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
         color: #fff;
         border-color: #D97E6A;
      }

      .messages {
         margin-bottom: 24px;
         animation: slideDown 0.4s ease;
      }

      .alert {
         padding: 14px 18px;
         border-radius: var(--radius);
         margin-bottom: 10px;
         display: flex;
         gap: 12px;
         align-items: flex-start;
         font-weight: 700;
         font-size: 0.95rem;
         border: 1px solid transparent;
         box-shadow: 0 6px 16px rgba(94, 31, 19, 0.08);
      }

      .alert.success {
         background: linear-gradient(135deg, rgba(76, 175, 80, 0.12) 0%, rgba(76, 175, 80, 0.06) 100%);
         color: #2E7D32;
         border-color: rgba(76, 175, 80, 0.2);
      }

      .alert.error {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: var(--primary-red);
         border-color: rgba(198, 69, 62, 0.2);
      }

      .section {
         margin-bottom: 40px;
         animation: slideUp 0.4s ease;
      }

      .section-title {
         display: flex;
         align-items: center;
         gap: 10px;
         font-size: 1.3rem;
         font-weight: 900;
         margin-bottom: 6px;
      }

      .section-title i {
         color: var(--primary-red);
      }

      .section-desc {
         color: var(--gray-brown);
         font-weight: 500;
         margin-bottom: 18px;
         font-size: 0.95rem;
      }

      .orders-grid {
         display: grid;
         grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
         gap: 18px;
      }

      .order-card {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         box-shadow: var(--shadow);
         border: 1.5px solid #F0E6D8;
         overflow: hidden;
         transition: var(--transition);
      }

      .order-card:hover {
         box-shadow: var(--shadow-hover);
         border-color: var(--primary-red);
         transform: translateY(-3px);
      }

      .order-card .card-top {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         padding: 14px 18px;
         display: flex;
         justify-content: space-between;
         align-items: center;
         font-weight: 800;
      }

      .order-card.completed .card-top {
         background: linear-gradient(135deg, #22C55E 0%, #16A34A 100%);
      }

      .order-card .card-body {
         padding: 18px;
         display: flex;
         flex-direction: column;
         gap: 10px;
      }

      .order-line {
         display: flex;
         align-items: flex-start;
         gap: 10px;
         font-size: 0.92rem;
      }

      .order-line i {
         color: var(--primary-red);
         width: 16px;
         margin-top: 3px;
         flex-shrink: 0;
      }

      .order-line .label {
         font-weight: 800;
         color: var(--dark-brown);
      }

      .order-line .value {
         color: var(--gray-brown);
         font-weight: 500;
      }

      .order-total {
         font-size: 1.2rem;
         font-weight: 900;
         color: var(--primary-red);
      }

      .card-actions {
         padding: 14px 18px;
         border-top: 1.5px solid #F0E6D8;
      }

      .take-btn, .complete-btn {
         width: 100%;
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
         padding: 12px 16px;
         border: none;
         border-radius: 10px;
         font-weight: 800;
         cursor: pointer;
         font-size: 0.95rem;
         transition: var(--transition);
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(198, 69, 62, 0.2);
      }

      .complete-btn {
         background: linear-gradient(135deg, #22C55E 0%, #16A34A 100%);
         box-shadow: 0 2px 6px rgba(34, 197, 94, 0.2);
      }

      .take-btn:hover, .complete-btn:hover {
         transform: translateY(-2px);
         box-shadow: 0 4px 12px rgba(94, 31, 19, 0.2);
      }

      .empty-note {
         color: var(--gray-brown);
         font-weight: 600;
         text-align: center;
         padding: 32px 20px;
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         border: 1.5px dashed #F0E6D8;
      }

      .empty-note i {
         display: block;
         font-size: 2rem;
         color: var(--primary-red);
         margin-bottom: 10px;
         opacity: 0.7;
      }

      @media (max-width: 640px) {
         .page {
            padding: 20px 14px 44px;
         }

         .topbar h1 {
            font-size: 1.5rem;
         }

         .orders-grid {
            grid-template-columns: 1fr;
         }
      }
   </style>
</head>
<body>

<div class="page">
   <div class="topbar">
      <div>
         <h1>🛵 Rider Dashboard</h1>
         <div class="sub">Pick up ready orders and manage your deliveries</div>
      </div>
      <div class="right">
         <div class="rider-badge">
            <i class="fa-solid fa-user"></i>
            <span><?php echo htmlspecialchars($rider_info['name']); ?></span>
         </div>
         <a href="rider_logout.php" class="logout-btn" onclick="return confirm('Log out of your rider account?');">
            <i class="fa-solid fa-right-from-bracket"></i> Logout
         </a>
      </div>
   </div>

   <?php if (!empty($message)): ?>
   <div class="messages">
      <?php foreach ($message as $msg): ?>
         <div class="alert <?php echo $msg['type']; ?>">
            <i class="fa-solid <?php echo $msg['type'] === 'success' ? 'fa-check-circle' : 'fa-circle-exclamation'; ?>"></i>
            <div><?php echo $msg['text']; ?></div>
         </div>
      <?php endforeach; ?>
   </div>
   <?php endif; ?>

   <!-- ==================== MY ACTIVE DELIVERIES ==================== -->
   <div class="section">
      <div class="section-title"><i class="fa-solid fa-truck-fast"></i> My Active Deliveries</div>
      <div class="section-desc">Orders you've taken. Mark them completed once delivered.</div>

      <?php if (count($my_orders) > 0): ?>
      <div class="orders-grid">
         <?php foreach ($my_orders as $order): ?>
         <div class="order-card">
            <div class="card-top">
               <span>Order #<?php echo intval($order['id']); ?></span>
               <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
            </div>
            <div class="card-body">
               <div class="order-line"><i class="fa-solid fa-user"></i><span class="value"><?php echo htmlspecialchars($order['name']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-phone"></i><span class="value"><?php echo htmlspecialchars($order['number']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-map-marker-alt"></i><span class="value"><?php echo htmlspecialchars($order['address']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-box"></i><span class="value"><?php echo htmlspecialchars($order['total_products']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-credit-card"></i><span class="value"><?php echo htmlspecialchars($order['method']); ?></span></div>
            </div>
            <div class="card-actions">
               <form method="post" onsubmit="return confirm('Mark Order #<?php echo intval($order['id']); ?> as completed?');">
                  <input type="hidden" name="order_id" value="<?php echo intval($order['id']); ?>">
                  <button type="submit" name="complete_order" class="complete-btn">
                     <i class="fa-solid fa-check-double"></i> Mark Completed
                  </button>
               </form>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php else: ?>
         <div class="empty-note">
            <i class="fa-solid fa-box-open"></i>
            You have no active deliveries right now.
         </div>
      <?php endif; ?>
   </div>

   <!-- ==================== AVAILABLE ORDERS FOR DELIVERY ==================== -->
   <div class="section">
      <div class="section-title"><i class="fa-solid fa-list-check"></i> Available Orders</div>
      <div class="section-desc">Orders that are ready and waiting for a rider to take them.</div>

      <?php if (count($available_orders) > 0): ?>
      <div class="orders-grid">
         <?php foreach ($available_orders as $order): ?>
         <div class="order-card">
            <div class="card-top">
               <span>Order #<?php echo intval($order['id']); ?></span>
               <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
            </div>
            <div class="card-body">
               <div class="order-line"><i class="fa-solid fa-user"></i><span class="value"><?php echo htmlspecialchars($order['name']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-phone"></i><span class="value"><?php echo htmlspecialchars($order['number']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-map-marker-alt"></i><span class="value"><?php echo htmlspecialchars($order['address']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-box"></i><span class="value"><?php echo htmlspecialchars($order['total_products']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-credit-card"></i><span class="value"><?php echo htmlspecialchars($order['method']); ?></span></div>
            </div>
            <div class="card-actions">
               <form method="post" onsubmit="return confirm('Take Order #<?php echo intval($order['id']); ?> for delivery?');">
                  <input type="hidden" name="order_id" value="<?php echo intval($order['id']); ?>">
                  <button type="submit" name="take_order" class="take-btn">
                     <i class="fa-solid fa-hand-holding"></i> Take This Order
                  </button>
               </form>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php else: ?>
         <div class="empty-note">
            <i class="fa-solid fa-mug-hot"></i>
            No orders are ready for delivery right now. Check back soon!
         </div>
      <?php endif; ?>
   </div>

   <!-- ==================== RECENTLY COMPLETED BY ME ==================== -->
   <div class="section">
      <div class="section-title"><i class="fa-solid fa-clock-rotate-left"></i> Recently Completed</div>
      <div class="section-desc">Your last 15 completed deliveries.</div>

      <?php if (count($completed_orders) > 0): ?>
      <div class="orders-grid">
         <?php foreach ($completed_orders as $order): ?>
         <div class="order-card completed">
            <div class="card-top">
               <span>Order #<?php echo intval($order['id']); ?></span>
               <span>₱<?php echo number_format($order['total_price'], 2); ?></span>
            </div>
            <div class="card-body">
               <div class="order-line"><i class="fa-solid fa-user"></i><span class="value"><?php echo htmlspecialchars($order['name']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-map-marker-alt"></i><span class="value"><?php echo htmlspecialchars($order['address']); ?></span></div>
               <div class="order-line"><i class="fa-solid fa-calendar-check"></i><span class="value"><?php echo htmlspecialchars($order['completed_at']); ?></span></div>
            </div>
         </div>
         <?php endforeach; ?>
      </div>
      <?php else: ?>
         <div class="empty-note">
            <i class="fa-solid fa-inbox"></i>
            You haven't completed any deliveries yet.
         </div>
      <?php endif; ?>
   </div>
</div>

</body>
</html>