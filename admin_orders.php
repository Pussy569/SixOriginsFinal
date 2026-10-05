<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'] ?? null;
if (!isset($admin_id)) {
    header('location:login.php');
    exit;
}

include 'admin_log_activity.php';
include 'send_receipt_email.php';
include 'send_sms_notification.php';
include 'inventory_functions.php';
include 'order_ingredients_helper.php'; // computes ingredients/packaging used per order

// Small helper: turn the newline-separated "Item: note" blob saved by
// cart.php into a clean array of lines, filtering out empties.
function parseSpecialInstructions(?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }
    $lines = [];
    foreach (explode("\n", $raw) as $line) {
        $line = trim($line);
        if ($line !== '') {
            $lines[] = $line;
        }
    }
    return $lines;
}

// ✅ NEW: products are stored separated with '||' (matching cart.php / orders.php),
// because each product entry may itself contain a "[Preference: ...]" / "[Extras: ...]"
// note that contains commas of its own. This mirrors the parsing already used on
// the customer-facing orders.php so admin sees the exact same data.
function parseOrderProducts(?string $raw): array {
    if ($raw === null || trim($raw) === '') {
        return [];
    }

    $items = array_filter(array_map('trim', explode('||', $raw)), function ($p) {
        return $p !== '';
    });

    $parsed = [];
    foreach ($items as $item) {
        $base_line = $item;
        $custom_tags = [];

        if (preg_match_all('/\[(Preference|Extras):\s*(.*?)\]/i', $item, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $custom_tags[] = ['label' => $m[1], 'value' => $m[2]];
            }
            $base_line = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $item));
        }

        $parsed[] = [
            'raw'   => $item,
            'base'  => $base_line,
            'tags'  => $custom_tags,
        ];
    }

    return $parsed;
}

// Allowed statuses for the "Update Status" dropdown — anything outside this
// whitelist is rejected instead of being written straight to the database.
$allowed_order_statuses = ['pending', 'accepted', 'preparing', 'done preparing', 'out for delivery', 'completed'];

function notifyOrderStatusByEmail(array $orderInfo, string $status): bool {
    $parsedProducts = parseOrderProducts((string)($orderInfo['total_products'] ?? ''));
    $orderData = [
        'order_id' => $orderInfo['id'],
        'name' => $orderInfo['name'],
        'address' => $orderInfo['address'],
        'email' => $orderInfo['email'],
        'method' => $orderInfo['method'],
        'total' => $orderInfo['total_price'],
        'status' => ucwords($status),
        'products' => array_map(function ($product) {
            return $product['raw'];
        }, $parsedProducts),
        'special_instructions' => parseSpecialInstructions($orderInfo['special_instructions'] ?? null),
    ];

    return sendReceiptEmail($orderData['email'], $orderData['name'], $orderData);
}

// ==================== MARK ORDER AS "DONE PREPARING" (ADMIN QUICK ACTION) ====================
if (isset($_POST['mark_done_now'])) {
    try {
        $order_id = intval($_POST['order_id'] ?? 0);

        if ($order_id <= 0) {
            throw new Exception('Invalid order ID');
        }

        // Get order
        $order_stmt = $conn->prepare("SELECT * FROM orders WHERE id = ?");
        $order_stmt->bind_param("i", $order_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();

        if ($order_result->num_rows === 0) {
            throw new Exception('Order not found');
        }

        $order = $order_result->fetch_assoc();
        $order_stmt->close();

        $current_status = strtolower($order['payment_status']);

        if ($current_status !== 'preparing') {
            throw new Exception('Order must be in "Preparing" status to mark as done');
        }

        // Update to done preparing
        $update_stmt = $conn->prepare("UPDATE orders SET payment_status = 'done preparing', done_preparing_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("i", $order_id);

        if (!$update_stmt->execute()) {
            throw new Exception('Failed to update order');
        }
        $update_stmt->close();

        $email_sent = notifyOrderStatusByEmail($order, 'done preparing');
        $_SESSION['message'] = [
            'type' => $email_sent ? 'success' : 'error',
            'text' => $email_sent
                ? '✅ Order marked as "Done Preparing" and the customer was notified by email.'
                : '⚠️ Order marked as "Done Preparing", but the customer email notification failed.',
        ];

    } catch (Exception $e) {
        $_SESSION['message'] = ['type' => 'error', 'text' => htmlspecialchars($e->getMessage())];
    }

    header('location:admin_orders.php');
    exit;
}

// ==================== UPDATE ORDER STATUS ====================
if (isset($_POST['update_order'])) {
    $order_update_id = intval($_POST['order_id'] ?? 0);
    $submitted_status = isset($_POST['update_payment']) ? trim($_POST['update_payment']) : '';
    $submitted_status_lower = strtolower($submitted_status);

    if ($order_update_id <= 0) {
        $message[] = 'Invalid order.';
    } elseif ($submitted_status === '' ) {
        $message[] = 'Please select a payment status before updating.';
    } elseif (!in_array($submitted_status_lower, $allowed_order_statuses, true)) {
        // ✅ Reject anything outside the known status list instead of writing it straight to the DB
        $message[] = 'Invalid status selected.';
    } else {
        $new_status = $submitted_status_lower;

        // ==================== INVENTORY DEDUCTION LOGIC ====================
        // Inventory is now deducted only when the order is marked COMPLETED,
        // not when it enters "preparing" anymore.
        if ($new_status === 'completed') {
            $deduct_result = deductInventoryForOrder($conn, $order_update_id, $admin_id);
            if (!$deduct_result['success']) {
                $message[] = '⚠️ Warning: ' . htmlspecialchars($deduct_result['message']);
            } else {
                $message[] = '📦 Inventory deducted: ' . implode(', ', $deduct_result['items']);
            }
        }
        // ==================== END INVENTORY DEDUCTION ====================

        $update_stmt = $conn->prepare("UPDATE `orders` SET payment_status = ?, prepared_at = NOW() WHERE id = ?");
        $update_stmt->bind_param("si", $new_status, $order_update_id);
        $update_ok = $update_stmt->execute();
        $update_stmt->close();

        if (!$update_ok) {
            $message[] = 'Failed to update order.';
        } else {
            $info_stmt = $conn->prepare("SELECT * FROM `orders` WHERE id = ?");
            $info_stmt->bind_param("i", $order_update_id);
            $info_stmt->execute();
            $order_info = $info_stmt->get_result()->fetch_assoc();
            $info_stmt->close();

            if ($order_info) {
                $email_sent = notifyOrderStatusByEmail($order_info, $new_status);

                // ==================== SEND SMS NOTIFICATION ====================
                $customer_phone = isset($order_info['number']) ? trim($order_info['number']) : '';
                $customer_name = $order_info['name'];
                $user_id_order = $order_info['user_id'];

                $is_guest_order = strpos($user_id_order, 'guest_') === 0;

                if (!$is_guest_order && !empty($customer_phone)) {
                    $sms_sent = sendOrderStatusSMS(
                        $customer_phone,
                        $order_info['id'],
                        $new_status,
                        $order_info['total_price'],
                        $customer_name
                    );

                    if ($sms_sent) {
                        error_log("✅ SMS sent successfully for order {$order_info['id']}");
                        $message[] = $email_sent
                            ? '✅ Order status updated! Email and SMS notifications sent to customer.'
                            : '⚠️ Order status updated and SMS sent, but the email notification failed.';
                    } else {
                        error_log("❌ SMS failed for order {$order_info['id']}");
                        $message[] = $email_sent
                            ? '⚠️ Order status updated! Email sent, but SMS notification failed.'
                            : '⚠️ Order status updated, but email and SMS notifications failed.';
                    }
                } else if ($is_guest_order) {
                    $message[] = $email_sent
                        ? 'Order status updated and email notification sent! (Guest - SMS not sent)'
                        : '⚠️ Order status updated, but email notification failed. (Guest - SMS not sent)';
                } else {
                    $message[] = $email_sent
                        ? 'Order status updated and email notification sent! (No phone number)'
                        : '⚠️ Order status updated, but email notification failed. (No phone number)';
                }
                // ==================== END SMS NOTIFICATION ====================
            } else {
                $message[] = 'Order not found.';
            }
        }
    }
}

// ==================== DELETE ORDER ====================
if (isset($_GET['delete'])) {
    $delete_id = intval($_GET['delete']);

    if ($delete_id > 0) {
        $delete_stmt = $conn->prepare("DELETE FROM `orders` WHERE id = ?");
        $delete_stmt->bind_param("i", $delete_id);
        if (!$delete_stmt->execute()) {
            $_SESSION['message'] = ['type' => 'error', 'text' => 'Failed to delete order.'];
        }
        $delete_stmt->close();
    }

    header('location:admin_orders.php');
    exit;
}

// ==================== CANCEL ORDER (WITH REFUND & WASTE TRACKING) ====================
if (isset($_GET['cancel_order'])) {
    try {
        $order_id = intval($_GET['cancel_order']);

        if ($order_id <= 0) {
            throw new Exception('Invalid order ID');
        }

        $order_stmt = $conn->prepare("SELECT * FROM `orders` WHERE id = ?");
        $order_stmt->bind_param("i", $order_id);
        $order_stmt->execute();
        $order_result = $order_stmt->get_result();

        if ($order_result->num_rows === 0) {
            $message[] = 'Order not found';
        } else {
            $order = $order_result->fetch_assoc();
            $order_stmt->close();

            if (strtolower($order['payment_status']) === 'cancelled') {
                $message[] = 'Order is already cancelled';
            } else {
                $user_id_order = $order['user_id'];
                $order_total = floatval($order['total_price']);
                $payment_method = $order['method'];
                $current_status = strtolower($order['payment_status']);

                // Inventory is deducted only when an order reaches "completed".
                if ($current_status === 'completed') {
                    $restore_result = restoreInventoryForOrder($conn, $order_id);
                    if ($restore_result['success']) {
                        $message[] = '↩️ Inventory restored: ' . implode(', ', $restore_result['items']);
                    }
                }

                $cancel_stmt = $conn->prepare("UPDATE `orders` SET payment_status = 'cancelled', cancelled_at = NOW() WHERE id = ?");
                $cancel_stmt->bind_param("i", $order_id);
                $cancel_update = $cancel_stmt->execute();
                $cancel_stmt->close();

                if ($cancel_update) {
                    $email_sent = notifyOrderStatusByEmail($order, 'cancelled');
                    $message[] = $email_sent
                        ? '✅ Customer notified of the cancellation by email.'
                        : '⚠️ Order cancelled, but the customer email notification failed.';

                    // ==================== SEND CANCELLATION SMS ====================
                    $customer_phone = isset($order['number']) ? trim($order['number']) : '';
                    $customer_name = $order['name'];
                    $is_guest_order = strpos($user_id_order, 'guest_') === 0;

                    if (!$is_guest_order && !empty($customer_phone)) {
                        $sms_result = sendOrderStatusSMS(
                            $customer_phone,
                            $order['id'],
                            'cancelled',
                            $order_total,
                            $customer_name
                        );
                        error_log("Cancellation SMS sent for order {$order['id']}: " . ($sms_result ? 'SUCCESS' : 'FAILED'));
                    }
                    // ==================== END CANCELLATION SMS ====================

                    if (strtolower($payment_method) === 'wallet balance') {
                        if ($is_guest_order) {
                            $message[] = 'Order cancelled successfully! (Guest - No wallet to refund)';
                        } else {
                            $refund_stmt = $conn->prepare("UPDATE `users` SET wallet_balance = wallet_balance + ? WHERE id = ?");
                            $refund_stmt->bind_param("ds", $order_total, $user_id_order);
                            $refund_stmt->execute();
                            $refund_stmt->close();
                            $message[] = '✅ Order cancelled successfully! Wallet refunded ₱' . number_format($order_total, 2);
                        }
                    } else {
                        $message[] = '✅ Order cancelled successfully! (No refund - Cash on Delivery)';
                    }
                } else {
                    $message[] = 'Failed to cancel order';
                }
            }
        }

    } catch (Exception $e) {
        $message[] = 'Error: ' . htmlspecialchars($e->getMessage());
    }
}

// ---------- View helper: decide if a message is an error/warning for styling only ----------
function messageIsError(string $text): bool {
    return (bool) preg_match('/^⚠️|failed|invalid|not found|error|please select|already cancelled/i', $text);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <title>Manage Orders — Six Origins Admin</title>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
   <link rel="icon" type="image/png" href="images/logos.png">
   <style>
      :root {
         --primary-red: #C6453E;
         --red-deep: #B83A34;
         --dark-brown: #5E1F13;
         --gray-brown: #664C47;
         --light-cream: #FFF2E0;
         --white: #FFFFFF;
         --line: #F0E6D8;
         --radius: 16px;
         --radius-sm: 10px;
         --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
         --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
         --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      }

      * {
         box-sizing: border-box;
         margin: 0;
         padding: 0;
         font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      }

      html {
         scroll-behavior: smooth;
         -webkit-text-size-adjust: 100%;
      }

      html, body { width: 100%; overflow-x: hidden; }

      body {
         min-height: 100vh;
         min-height: 100dvh;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         background-attachment: fixed;
         color: var(--dark-brown);
      }

      :focus-visible {
         outline: 3px solid rgba(198, 69, 62, 0.45);
         outline-offset: 2px;
      }

      .page {
         max-width: 1400px;
         margin: 0 auto;
         width: 100%;
         padding: clamp(14px, 3vw, 28px) clamp(12px, 3vw, 24px) calc(56px + env(safe-area-inset-bottom, 0px));
      }

      /* ---------- Header ---------- */
      .header-row {
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 14px 20px;
         margin-bottom: clamp(16px, 3vw, 24px);
         flex-wrap: wrap;
      }

      .header-row h1 {
         font-size: clamp(1.4rem, 5vw, 2.2rem);
         font-weight: 900;
         letter-spacing: -0.5px;
         line-height: 1.15;
      }

      .header-row .subtitle {
         color: var(--gray-brown);
         font-size: clamp(0.85rem, 2.4vw, 1rem);
         margin-top: 6px;
         font-weight: 500;
      }

      .refresh-btn {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
         min-height: 46px;
         padding: 0 20px;
         background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
         color: #fff;
         border: none;
         border-radius: var(--radius-sm);
         font-weight: 800;
         cursor: pointer;
         text-decoration: none;
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
         transition: var(--transition);
         font-size: 0.95rem;
      }

      /* ---------- Messages ---------- */
      .messages { margin: 0 0 20px; }

      .messages .msg {
         display: flex;
         gap: 12px;
         align-items: flex-start;
         padding: 14px 16px;
         border-radius: var(--radius-sm);
         margin-bottom: 10px;
         font-weight: 700;
         font-size: 0.92rem;
         line-height: 1.4;
         background: rgba(94, 31, 19, 0.06);
         border-left: 5px solid var(--dark-brown);
         color: var(--dark-brown);
      }

      .messages .msg.error {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
         border-left-color: var(--primary-red);
         color: var(--primary-red);
      }

      .messages .msg i { margin-top: 2px; flex: none; }

      /* ---------- Toolbar: search + filters ---------- */
      .toolbar {
         display: grid;
         gap: 12px;
         margin-bottom: 20px;
      }

      .search {
         display: flex;
         align-items: center;
         gap: 10px;
         min-height: 48px;
         padding: 0 14px;
         background: #FFFBF7;
         border: 1.5px solid var(--line);
         border-radius: var(--radius-sm);
         transition: var(--transition);
         max-width: 460px;
      }

      .search:focus-within {
         border-color: var(--primary-red);
         box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
      }

      .search i { color: var(--primary-red); }

      .search input {
         flex: 1;
         min-width: 0;
         height: 46px;
         border: 0;
         outline: 0;
         background: transparent;
         font-size: 16px; /* prevents iOS zoom on focus */
         font-weight: 600;
         color: var(--dark-brown);
      }

      .search input::placeholder { color: var(--gray-brown); font-weight: 500; }

      .chips {
         display: flex;
         gap: 8px;
         overflow-x: auto;
         padding: 2px 2px 6px;
         margin: 0 -2px;
         scrollbar-width: none;
         -webkit-overflow-scrolling: touch;
      }

      .chips::-webkit-scrollbar { display: none; }

      .chip {
         flex: none;
         display: inline-flex;
         align-items: center;
         gap: 8px;
         min-height: 40px;
         padding: 0 14px;
         border-radius: 999px;
         border: 1.5px solid var(--line);
         background: #FFFBF7;
         color: var(--dark-brown);
         font-weight: 700;
         font-size: 0.85rem;
         cursor: pointer;
         transition: var(--transition);
         -webkit-tap-highlight-color: transparent;
      }

      .chip .count {
         min-width: 22px;
         padding: 1px 7px;
         border-radius: 999px;
         background: var(--light-cream);
         font-size: 0.75rem;
         font-weight: 800;
         text-align: center;
      }

      .chip.active {
         background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
         border-color: var(--primary-red);
         color: #fff;
      }

      .chip.active .count { background: rgba(255, 255, 255, 0.25); color: #fff; }

      /* ---------- Order cards ---------- */
      .orders-grid {
         display: grid;
         grid-template-columns: minmax(0, 1fr);
         gap: 18px;
      }

      .order-card {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         box-shadow: var(--shadow);
         border: 1.5px solid var(--line);
         overflow: hidden;
         transition: var(--transition);
      }

      .order-card[hidden] { display: none; }

      @media (hover: hover) {
         .order-card:hover {
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-red);
         }
      }

      .order-card.cancelled { border-color: #D97E6A; opacity: 0.88; }

      .card-header {
         background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
         padding: 16px 22px;
         color: #fff;
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 14px;
      }

      .order-card.cancelled .card-header {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
      }

      .order-title {
         font-size: 1.25rem;
         font-weight: 900;
         overflow-wrap: anywhere;
         line-height: 1.25;
      }

      .order-title-sub {
         font-size: 0.88rem;
         opacity: 0.95;
         font-weight: 700;
         margin-top: 3px;
      }

      .order-id-badge {
         flex: none;
         background: rgba(255, 255, 255, 0.2);
         padding: 8px 14px;
         border-radius: 8px;
         font-weight: 900;
         font-size: 1.05rem;
         white-space: nowrap;
      }

      /* status / date / payment strip */
      .meta-strip {
         display: flex;
         align-items: center;
         flex-wrap: wrap;
         gap: 10px 18px;
         padding: 12px 22px;
         background: linear-gradient(135deg, #FFF8EE 0%, var(--light-cream) 100%);
         border-bottom: 1.5px solid var(--line);
      }

      .meta-item {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         font-size: 0.88rem;
         font-weight: 700;
         color: var(--gray-brown);
      }

      .meta-item i { color: var(--primary-red); }

      .status-badge {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         padding: 7px 12px;
         border-radius: 999px;
         font-weight: 800;
         font-size: 0.8rem;
         text-transform: uppercase;
         letter-spacing: 0.5px;
         border: 1.5px solid;
         white-space: nowrap;
      }

      .status-pending { background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%); color: var(--primary-red); border-color: rgba(198, 69, 62, 0.3); }
      .status-accepted { background: linear-gradient(135deg, rgba(212, 165, 116, 0.12) 0%, rgba(212, 165, 116, 0.06) 100%); color: #704214; border-color: rgba(212, 165, 116, 0.3); }
      .status-preparing { background: linear-gradient(135deg, rgba(3, 105, 161, 0.12) 0%, rgba(3, 105, 161, 0.06) 100%); color: #0369a1; border-color: rgba(3, 105, 161, 0.2); }
      .status-out { background: linear-gradient(135deg, rgba(180, 83, 9, 0.12) 0%, rgba(180, 83, 9, 0.06) 100%); color: #b45309; border-color: rgba(180, 83, 9, 0.2); }
      .status-completed { background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%); color: var(--primary-red); border-color: rgba(198, 69, 62, 0.3); }
      .status-cancelled { background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%); color: #D97E6A; border-color: rgba(217, 126, 106, 0.3); }
      .status-done-preparing { background: linear-gradient(135deg, rgba(34, 197, 94, 0.12) 0%, rgba(34, 197, 94, 0.06) 100%); color: #16A34A; border-color: rgba(34, 197, 94, 0.3); }

      /* body */
      .card-body {
         padding: 22px;
         display: grid;
         grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr);
         gap: 24px 32px;
      }

      .col { display: grid; gap: 20px; align-content: start; min-width: 0; }

      .info-block { display: flex; flex-direction: column; gap: 8px; min-width: 0; }

      .info-label {
         font-size: 0.8rem;
         font-weight: 800;
         text-transform: uppercase;
         letter-spacing: 0.5px;
         display: flex;
         align-items: center;
         gap: 6px;
      }

      .info-label i { color: var(--primary-red); }

      .info-value {
         font-size: 0.98rem;
         font-weight: 700;
         line-height: 1.5;
         overflow-wrap: anywhere;
      }

      .info-value.secondary { font-size: 0.93rem; color: var(--gray-brown); font-weight: 500; }

      .info-value a { color: inherit; text-decoration: none; border-bottom: 1.5px dotted var(--primary-red); }

      /* products */
      .products-list { list-style: none; }

      .products-list li {
         padding: 9px 0 9px 24px;
         position: relative;
         color: var(--gray-brown);
         font-size: 0.95rem;
         font-weight: 600;
         border-bottom: 1px solid var(--line);
         overflow-wrap: anywhere;
      }

      .products-list li::before {
         content: '☕';
         position: absolute;
         left: 0;
         font-size: 0.9em;
      }

      .products-list li:last-child { border-bottom: none; }

      .product-tags { margin-top: 4px; }

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

      /* ingredients */
      .ingredients-used { display: flex; flex-wrap: wrap; gap: 6px; }

      .ingredient-chip {
         display: inline-flex;
         align-items: center;
         gap: 5px;
         background: linear-gradient(135deg, rgba(5, 150, 105, 0.12) 0%, rgba(5, 150, 105, 0.06) 100%);
         color: #047857;
         padding: 5px 10px;
         border-radius: 8px;
         font-size: 0.78rem;
         font-weight: 700;
         border: 1px solid rgba(5, 150, 105, 0.2);
      }

      .ingredient-chip i { font-size: 0.65rem; }

      .no-ingredients-note { font-size: 0.85rem; color: var(--gray-brown); font-style: italic; font-weight: 500; }

      /* footer */
      .card-footer {
         padding: 16px 22px;
         border-top: 1.5px solid var(--line);
         display: flex;
         align-items: center;
         justify-content: space-between;
         flex-wrap: wrap;
         gap: 12px 16px;
      }

      .status-form { display: flex; gap: 8px; align-items: center; }

      .footer-actions { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
      .footer-actions form { display: contents; }

      .select {
         min-height: 44px;
         padding: 0 12px;
         border-radius: var(--radius-sm);
         border: 1.5px solid var(--line);
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
         font-weight: 700;
         color: var(--dark-brown);
         font-size: 0.9rem;
         cursor: pointer;
         min-width: 190px;
      }

      .select:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1); }
      .select:disabled { opacity: 0.55; cursor: not-allowed; }

      .update-btn, .done-now-btn, .cancel-btn, .delete-btn {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 6px;
         min-height: 44px;
         padding: 0 16px;
         border-radius: var(--radius-sm);
         border: 1.5px solid transparent;
         cursor: pointer;
         font-weight: 800;
         font-size: 0.9rem;
         text-decoration: none;
         transition: var(--transition);
         white-space: nowrap;
         -webkit-tap-highlight-color: transparent;
      }

      .update-btn:active, .done-now-btn:active, .cancel-btn:active, .delete-btn:active { transform: scale(0.98); }

      .update-btn {
         background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(198, 69, 62, 0.2);
      }

      .done-now-btn {
         background: linear-gradient(135deg, #22C55E 0%, #16A34A 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(34, 197, 94, 0.2);
      }

      .update-btn:disabled { opacity: 0.5; cursor: not-allowed; }

      .cancel-btn, .delete-btn {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.06) 100%);
         color: #C25A52;
         border-color: rgba(217, 126, 106, 0.3);
      }

      @media (hover: hover) {
         .refresh-btn:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2); }
         .chip:not(.active):hover { border-color: var(--primary-red); }
         .update-btn:not(:disabled):hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(198, 69, 62, 0.3); }
         .done-now-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(34, 197, 94, 0.3); }
         .cancel-btn:hover, .delete-btn:hover { background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%); color: #fff; border-color: #D97E6A; transform: translateY(-2px); }
      }

      /* empty states */
      .empty {
         text-align: center;
         color: var(--gray-brown);
         padding: 56px 24px;
         font-size: 1.05em;
      }

      .empty i { font-size: 3.2rem; margin-bottom: 16px; color: var(--primary-red); opacity: 0.8; }
      .empty[hidden] { display: none; }

      /* ---------- Responsive ---------- */
      @media (max-width: 900px) {
         .card-body { grid-template-columns: minmax(0, 1fr); gap: 20px; }
         .card-footer { flex-direction: column; align-items: stretch; }
         .status-form .select { flex: 1; min-width: 0; }
         .footer-actions > * { flex: 1 1 calc(50% - 4px); }
         .footer-actions .done-now-btn { flex: 1 1 100%; order: -1; }
      }

      @media (max-width: 560px) {
         .header-row { flex-direction: column; align-items: stretch; }
         .refresh-btn { width: 100%; }

         .card-header { padding: 14px 16px; align-items: flex-start; }
         .order-title { font-size: 1.1rem; }
         .order-id-badge { padding: 6px 12px; font-size: 0.95rem; }
         .meta-strip { padding: 12px 16px; gap: 8px 14px; }
         .card-body { padding: 16px; }
         .card-footer { padding: 14px 16px; }

         /* 16px stops iOS zooming into the select */
         .select { font-size: 16px; }
         .search { max-width: none; }
      }

      @media (prefers-reduced-motion: reduce) {
         * { animation: none !important; transition: none !important; scroll-behavior: auto !important; }
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="page">
   <div class="header-row">
      <div>
         <h1>☕ Manage Orders</h1>
         <div class="subtitle">Track, update, and manage customer orders</div>
      </div>
      <a href="admin_orders.php" class="refresh-btn">
         <i class="fa-solid fa-sync-alt"></i> Refresh
      </a>
   </div>

   <?php
      if(isset($message)){
         echo '<div class="messages" role="status" aria-live="polite">';
         foreach($message as $msg){
            $is_err = messageIsError($msg);
            echo '<div class="msg'.($is_err ? ' error' : '').'"><i class="fa-solid '.($is_err ? 'fa-circle-exclamation' : 'fa-circle-check').'"></i><span>'.htmlspecialchars($msg).'</span></div>';
         }
         echo '</div>';
      }
      // Flash messages set via $_SESSION (mark_done_now / delete / redirects)
      if(isset($_SESSION['message'])){
         $flash = $_SESSION['message'];
         $flash_err = (($flash['type'] ?? '') === 'error');
         echo '<div class="messages" role="status" aria-live="polite"><div class="msg'.($flash_err ? ' error' : '').'"><i class="fa-solid '.($flash_err ? 'fa-circle-exclamation' : 'fa-circle-check').'"></i><span>'.htmlspecialchars($flash['text']).'</span></div></div>';
         unset($_SESSION['message']);
      }

      $select_orders = mysqli_query($conn, "SELECT * FROM `orders` ORDER BY placed_on DESC") or die('query failed');
      $has_orders = mysqli_num_rows($select_orders) > 0;
   ?>

   <?php if ($has_orders): ?>
   <div class="toolbar">
      <label class="search" for="orderSearch">
         <i class="fa-solid fa-magnifying-glass"></i>
         <input id="orderSearch" type="search" placeholder="Search name, order #, phone or email" autocomplete="off">
      </label>
      <div class="chips" id="statusChips" role="group" aria-label="Filter orders by status">
         <button type="button" class="chip active" data-filter="all">All <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="pending">Pending <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="accepted">Accepted <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="preparing">Preparing <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="done preparing">Done Preparing <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="out for delivery">Out for Delivery <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="completed">Completed <span class="count">0</span></button>
         <button type="button" class="chip" data-filter="cancelled">Cancelled <span class="count">0</span></button>
      </div>
   </div>
   <?php endif; ?>

   <div class="orders-grid">
      <?php
         if($has_orders){
            while($fetch_orders = mysqli_fetch_assoc($select_orders)){
               $status = strtolower($fetch_orders['payment_status']);
               $status_class = 'status-pending';
               $status_icon = 'fa-info-circle';
               $is_cancelled = $status === 'cancelled';

               if ($status === 'done preparing') {
                  $status_class = 'status-done-preparing';
                  $status_icon = 'fa-check-double';
               } elseif ($status === 'completed') {
                  $status_class = 'status-completed';
                  $status_icon = 'fa-check-circle';
               } elseif ($status === 'accepted') {
                  $status_class = 'status-accepted';
                  $status_icon = 'fa-thumbs-up';
               } elseif ($status === 'preparing') {
                  $status_class = 'status-preparing';
                  $status_icon = 'fa-hourglass-end';
               } elseif ($status === 'out for delivery') {
                  $status_class = 'status-out';
                  $status_icon = 'fa-truck';
               } elseif ($status === 'cancelled') {
                  $status_class = 'status-cancelled';
                  $status_icon = 'fa-ban';
               }

               // ==================== COMPUTE INGREDIENTS/PACKAGING USED FOR THIS ORDER ====================
               $order_ingredients_used = getOrderIngredientsUsage($conn, $fetch_orders['total_products']);
               // ==================== END ====================

               // ✅ parse the per-item special instructions for display
               $order_special_instructions = parseSpecialInstructions($fetch_orders['special_instructions'] ?? null);

               // ✅ NEW: parse products with the same '||' delimiter + Preference/Extras tags used on orders.php
               $order_products_parsed = parseOrderProducts($fetch_orders['total_products']);

               // text used by the search box (view only)
               $search_blob = strtolower($fetch_orders['name'] . ' ' . $fetch_orders['id'] . ' ' . $fetch_orders['number'] . ' ' . $fetch_orders['email']);
      ?>
      <article class="order-card <?php echo $is_cancelled ? 'cancelled' : ''; ?>"
               data-status="<?php echo htmlspecialchars($status); ?>"
               data-search="<?php echo htmlspecialchars($search_blob); ?>">
         <div class="card-header">
            <div>
               <div class="order-title"><?php echo htmlspecialchars($fetch_orders['name']); ?></div>
               <div class="order-title-sub">Order #<?php echo htmlspecialchars($fetch_orders['id']); ?></div>
            </div>
            <div class="order-id-badge">
               ₱<?php echo number_format($fetch_orders['total_price'], 2); ?>
            </div>
         </div>

         <div class="meta-strip">
            <span class="status-badge <?php echo $status_class; ?>">
               <i class="fa-solid <?php echo $status_icon; ?>"></i>
               <?php echo htmlspecialchars(ucfirst($fetch_orders['payment_status'])); ?>
            </span>
            <span class="meta-item"><i class="fa-solid fa-calendar"></i> <?php echo htmlspecialchars($fetch_orders['placed_on']); ?></span>
            <span class="meta-item"><i class="fa-solid fa-credit-card"></i> <?php echo htmlspecialchars($fetch_orders['method']); ?></span>
         </div>

         <div class="card-body">
            <!-- LEFT: what was ordered -->
            <div class="col">
               <!-- Products parsed on the '||' delimiter with Preference/Extras tags shown,
                    same as the customer-facing orders.php. -->
               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-box"></i> Products</div>
                  <?php if (!empty($order_products_parsed)): ?>
                  <ul class="products-list">
                     <?php foreach ($order_products_parsed as $p): ?>
                        <li>
                           <?php echo htmlspecialchars($p['base']); ?>
                           <?php if (!empty($p['tags'])): ?>
                              <div class="product-tags">
                                 <?php foreach ($p['tags'] as $tag): ?>
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
                  <?php else: ?>
                     <div class="info-value secondary"><?php echo htmlspecialchars($fetch_orders['total_products']); ?></div>
                  <?php endif; ?>
               </div>

               <!-- Special Instructions (from cart.php checkout notes) -->
               <?php if (!empty($order_special_instructions)): ?>
               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-comment-dots"></i> Special Instructions</div>
                  <div class="info-value secondary">
                     <?php echo nl2br(htmlspecialchars(implode("\n", $order_special_instructions))); ?>
                  </div>
               </div>
               <?php endif; ?>

               <!-- Ingredients used -->
               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-flask"></i> Ingredients Used</div>
                  <?php if (!empty($order_ingredients_used)): ?>
                  <div class="ingredients-used">
                     <?php foreach ($order_ingredients_used as $iu): ?>
                        <span class="ingredient-chip">
                           <i class="fa-solid fa-circle-dot"></i>
                           <?php echo htmlspecialchars($iu['name']); ?>: <?php echo number_format($iu['total'], 2); ?> <?php echo htmlspecialchars($iu['unit']); ?>
                        </span>
                     <?php endforeach; ?>
                  </div>
                  <?php else: ?>
                     <div class="no-ingredients-note">No ingredients/packaging linked to product(s) in this order</div>
                  <?php endif; ?>
               </div>
            </div>

            <!-- RIGHT: who ordered -->
            <div class="col">
               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-phone"></i> Phone</div>
                  <div class="info-value">
                     <?php $phone_raw = trim((string)$fetch_orders['number']); ?>
                     <?php if ($phone_raw !== ''): ?>
                        <a href="tel:<?php echo htmlspecialchars(preg_replace('/[^0-9+]/', '', $phone_raw)); ?>"><?php echo htmlspecialchars($phone_raw); ?></a>
                     <?php else: ?>
                        <span class="secondary">—</span>
                     <?php endif; ?>
                  </div>
               </div>

               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-envelope"></i> Email</div>
                  <div class="info-value secondary"><?php echo htmlspecialchars($fetch_orders['email']); ?></div>
               </div>

               <div class="info-block">
                  <div class="info-label"><i class="fa-solid fa-map-marker-alt"></i> Address</div>
                  <div class="info-value"><?php echo htmlspecialchars($fetch_orders['address']); ?></div>
               </div>
            </div>
         </div>

         <div class="card-footer">
            <form action="" method="post" class="status-form">
               <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($fetch_orders['id']); ?>">
               <select name="update_payment" class="select" aria-label="Update payment status" <?php echo $is_cancelled ? 'disabled' : ''; ?>>
                  <option value="" disabled selected>Update Status...</option>
                  <option value="pending">Pending</option>
                  <option value="accepted">Accepted</option>
                  <option value="preparing">Preparing</option>
                  <option value="done preparing">Done Preparing</option>
                  <option value="out for delivery">Out for Delivery</option>
                  <option value="completed">Completed</option>
               </select>
               <button type="submit" name="update_order" class="update-btn" <?php echo $is_cancelled ? 'disabled' : ''; ?>>
                  <i class="fa-solid fa-save"></i> Update
               </button>
            </form>

            <div class="footer-actions">
               <!-- DONE NOW BUTTON (Quick action for preparing orders) -->
               <?php if ($status === 'preparing' && !$is_cancelled): ?>
               <form action="" method="post">
                  <input type="hidden" name="order_id" value="<?php echo htmlspecialchars($fetch_orders['id']); ?>">
                  <button type="submit" name="mark_done_now" class="done-now-btn">
                     <i class="fa-solid fa-bolt"></i> Done Now
                  </button>
               </form>
               <?php endif; ?>

               <?php if(!$is_cancelled): ?>
               <a href="admin_orders.php?cancel_order=<?php echo intval($fetch_orders['id']); ?>" onclick="return confirm('Cancel this order? Inventory will be restored if it was deducted.')" class="cancel-btn">
                  <i class="fa-solid fa-times-circle"></i> Cancel
               </a>
               <?php endif; ?>

               <a href="admin_orders.php?delete=<?php echo intval($fetch_orders['id']); ?>" onclick="return confirm('Delete this order? This action cannot be undone.');" class="delete-btn">
                  <i class="fa-solid fa-trash-alt"></i> Delete
               </a>
            </div>
         </div>
      </article>
      <?php
         }
         } else {
            echo '<div class="empty"><i class="fa-solid fa-inbox"></i><div><strong>No Orders Yet</strong><div style="margin-top: 8px; font-size: 0.95em;">Orders will appear here once customers place them</div></div></div>';
         }
      ?>

      <?php if ($has_orders): ?>
      <div class="empty" id="noMatch" hidden>
         <i class="fa-solid fa-filter-circle-xmark"></i>
         <div><strong>No matching orders</strong><div style="margin-top: 8px; font-size: 0.95em;">Try a different status or search</div></div>
      </div>
      <?php endif; ?>
   </div>
</div>

<script>
   (function () {
      const cards = Array.from(document.querySelectorAll('.order-card'));
      const chips = Array.from(document.querySelectorAll('#statusChips .chip'));
      const search = document.getElementById('orderSearch');
      const noMatch = document.getElementById('noMatch');
      if (!cards.length || !chips.length) return;

      let activeFilter = 'all';

      // Fill the count badges
      chips.forEach(chip => {
         const f = chip.dataset.filter;
         const n = f === 'all' ? cards.length : cards.filter(c => c.dataset.status === f).length;
         chip.querySelector('.count').textContent = n;
      });

      function apply() {
         const q = (search.value || '').trim().toLowerCase();
         let visible = 0;
         cards.forEach(card => {
            const okStatus = activeFilter === 'all' || card.dataset.status === activeFilter;
            const okSearch = q === '' || card.dataset.search.indexOf(q) !== -1;
            card.hidden = !(okStatus && okSearch);
            if (!card.hidden) visible++;
         });
         noMatch.hidden = visible !== 0;
      }

      chips.forEach(chip => {
         chip.addEventListener('click', () => {
            chips.forEach(c => c.classList.remove('active'));
            chip.classList.add('active');
            activeFilter = chip.dataset.filter;
            apply();
         });
      });

      search.addEventListener('input', apply);
   })();
</script>
<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>