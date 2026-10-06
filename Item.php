<?php
include __DIR__ . '/config.php';

// Get user ID - works for both registered and guest users
$user_id = null;
$is_guest = false;

if (isset($_SESSION['user_id'])) {
   $user_id = $_SESSION['user_id'];
} else {
   if (!isset($_SESSION['guest_cart_id'])) {
      $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16));
   }
   $user_id = "guest_" . $_SESSION['guest_cart_id'];
   $is_guest = true;
}

// Security: CSRF token for the add-to-cart forms
if (empty($_SESSION['csrf_token'])) {
   $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Friendly label/icon per size_type — kept in sync with admin_products.php
$SIZE_TYPE_META = [
   'cup'   => ['label' => 'Cup Size',       'icon' => 'fa-mug-hot'],
   'slice' => ['label' => 'Slice / Pieces', 'icon' => 'fa-cake-candles'],
];

/**
 * Pull the Preference titles/options and Extra titles/options an admin
 * configured for a product. Returns [ $preference_groups, $extra_groups ].
 */
function fetch_customizations(mysqli $conn, int $product_id): array {
   $preferences = [];
   $stmt = $conn->prepare("SELECT id, title FROM `product_preference_groups` WHERE product_id = ? ORDER BY id ASC");
   $stmt->bind_param("i", $product_id);
   $stmt->execute();
   $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
   $stmt->close();
   foreach ($groups as $g) {
      $opt_stmt = $conn->prepare("SELECT id, option_name FROM `product_preference_options` WHERE group_id = ? ORDER BY id ASC");
      $opt_stmt->bind_param("i", $g['id']);
      $opt_stmt->execute();
      $g['options'] = $opt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $opt_stmt->close();
      if (!empty($g['options'])) $preferences[] = $g;
   }

   $extras = [];
   $stmt = $conn->prepare("SELECT id, title FROM `product_extra_groups` WHERE product_id = ? ORDER BY id ASC");
   $stmt->bind_param("i", $product_id);
   $stmt->execute();
   $groups = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
   $stmt->close();
   foreach ($groups as $g) {
      $opt_stmt = $conn->prepare("SELECT id, option_name, price FROM `product_extra_options` WHERE group_id = ? ORDER BY id ASC");
      $opt_stmt->bind_param("i", $g['id']);
      $opt_stmt->execute();
      $g['options'] = $opt_stmt->get_result()->fetch_all(MYSQLI_ASSOC);
      $opt_stmt->close();
      if (!empty($g['options'])) $extras[] = $g;
   }

   return [$preferences, $extras];
}

/**
 * Validate the customer's posted preference/extra choices against what's
 * actually configured for this product, and build summaries + dedup hash.
 */
function validate_and_summarize_selections(mysqli $conn, int $product_id, $posted_preferences, $posted_extra_ids): array {
   $pref_summary   = [];
   $pref_selected  = [];
   if (is_array($posted_preferences)) {
      foreach ($posted_preferences as $group_id => $option_id) {
         $group_id  = (int)$group_id;
         $option_id = (int)$option_id;
         if ($option_id <= 0) continue;
         $stmt = $conn->prepare("SELECT po.option_name, pg.title FROM `product_preference_options` po JOIN `product_preference_groups` pg ON po.group_id = pg.id WHERE po.id = ? AND pg.id = ? AND pg.product_id = ?");
         $stmt->bind_param("iii", $option_id, $group_id, $product_id);
         $stmt->execute();
         $row = $stmt->get_result()->fetch_assoc();
         $stmt->close();
         if ($row) {
            $pref_summary[] = $row['title'] . ': ' . $row['option_name'];
            $pref_selected[$group_id] = $option_id;
         }
      }
   }

   $extra_summary  = [];
   $extra_selected = [];
   $extras_total   = 0.0;
   if (is_array($posted_extra_ids)) {
      $seen = [];
      foreach ($posted_extra_ids as $eid) {
         $eid = (int)$eid;
         if ($eid <= 0 || isset($seen[$eid])) continue;
         $seen[$eid] = true;
         $stmt = $conn->prepare("SELECT eo.option_name, eo.price FROM `product_extra_options` eo JOIN `product_extra_groups` eg ON eo.group_id = eg.id WHERE eo.id = ? AND eg.product_id = ?");
         $stmt->bind_param("ii", $eid, $product_id);
         $stmt->execute();
         $row = $stmt->get_result()->fetch_assoc();
         $stmt->close();
         if ($row) {
            $extra_summary[]  = $row['option_name'] . ' (+₱' . number_format((float)$row['price'], 2) . ')';
            $extras_total    += (float)$row['price'];
            $extra_selected[] = $eid;
         }
      }
   }
   ksort($pref_selected);
   sort($extra_selected);

   return [
      'pref_summary'  => !empty($pref_summary) ? implode(', ', $pref_summary) : null,
      'extra_summary' => !empty($extra_summary) ? implode(', ', $extra_summary) : null,
      'extras_total'  => $extras_total,
      'hash'          => md5(json_encode(['p' => $pref_selected, 'e' => $extra_selected])),
   ];
}

if (!isset($_SESSION['message'])) {
   $_SESSION['message'] = array();
}

if (isset($_POST['add_to_cart'])) {

   if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      $_SESSION['message'][] = 'Your session has expired. Please refresh the page and try again.';
   } else {

      $product_id       = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
      $product_size     = trim($_POST['product_size'] ?? '');
      $product_quantity = isset($_POST['product_quantity']) ? (int)$_POST['product_quantity'] : 1;

      if ($product_quantity < 1) $product_quantity = 1;
      if ($product_quantity > 20) $product_quantity = 20;

      if ($product_id <= 0 || $product_size === '') {
         $_SESSION['message'][] = 'Please select a valid size before adding to cart.';
      } else {

         $prod_stmt = $conn->prepare("SELECT name, image FROM `products` WHERE id = ?");
         $prod_stmt->bind_param("i", $product_id);
         $prod_stmt->execute();
         $product_row = $prod_stmt->get_result()->fetch_assoc();
         $prod_stmt->close();

         if (!$product_row) {
            $_SESSION['message'][] = 'That product no longer exists.';
         } else {

            $sel = validate_and_summarize_selections($conn, $product_id, $_POST['preferences'] ?? [], $_POST['extras'] ?? []);
            $conn->begin_transaction();
            try {
               $lock_stmt = $conn->prepare("SELECT id, stock, price FROM `product_sizes` WHERE product_id = ? AND size = ? FOR UPDATE");
               $lock_stmt->bind_param("is", $product_id, $product_size);
               $lock_stmt->execute();
               $size_row = $lock_stmt->get_result()->fetch_assoc();
               $lock_stmt->close();

               // ✅ Never expose the exact number in stock — just reject over-ordering
               if (!$size_row || (int)$size_row['stock'] < $product_quantity) {
                  throw new Exception('Sorry, we don\'t have enough stock left for that quantity. Please lower the quantity and try again.');
               }
               $unit_price = intval(round((float)$size_row['price'] + $sel['extras_total']));

               $cart_check = $conn->prepare("SELECT id FROM `cart` WHERE user_id = ? AND product_id = ? AND size = ? AND selections_hash = ?");
               $cart_check->bind_param("siss", $user_id, $product_id, $product_size, $sel['hash']);
               $cart_check->execute();
               $already_in_cart = $cart_check->get_result()->num_rows > 0;
               $cart_check->close();

               if ($already_in_cart) {
                  $conn->rollback();
                  $_SESSION['message'][] = 'This item (with the same size/preferences/extras) is already in your cart!';
               } else {
                  $update_stmt = $conn->prepare("UPDATE `product_sizes` SET stock = stock - ? WHERE id = ? AND stock >= ?");
                  $update_stmt->bind_param("iii", $product_quantity, $size_row['id'], $product_quantity);
                  $update_stmt->execute();

                  if ($update_stmt->affected_rows < 1) {
                     $update_stmt->close();
                     throw new Exception('Sorry, this size just went out of stock!');
                  }
                  $update_stmt->close();

                  $insert_stmt = $conn->prepare("INSERT INTO `cart` (user_id, product_id, name, price, quantity, image, size, preferences, extras, selections_hash) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                  $insert_stmt->bind_param(
                     "sisiisssss",
                     $user_id,
                     $product_id,
                     $product_row['name'],
                     $unit_price,
                     $product_quantity,
                     $product_row['image'],
                     $product_size,
                     $sel['pref_summary'],
                     $sel['extra_summary'],
                     $sel['hash']
                  );
                  $insert_stmt->execute();
                  $insert_stmt->close();

                  $conn->commit();
                  $_SESSION['message'][] = 'Product added to cart!';
               }
            } catch (Exception $e) {
               $conn->rollback();
               $_SESSION['message'][] = $e->getMessage();
            }
         }
      }
   }
}

$message = $_SESSION['message'];
unset($_SESSION['message']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width, initial-scale=1.0" />
   <meta http-equiv="X-UA-Compatible" content="ie=edge">
   <title>Shop — Six Origins Cafe</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
      --lead-font-size: 1.08rem;
    }

    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; }

    /* RESPONSIVE FIX: only <html> clips sideways overflow. Putting overflow-x:hidden on BOTH html and body
       turns <body> into its own scroll container, which breaks sticky/fixed headers on mobile. */
    html { width: 100%; overflow-x: hidden; -webkit-text-size-adjust: 100%; text-size-adjust: 100%; }
    body {
      width: 100%; overflow-x: clip;
      background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
      color: var(--dark-brown); -webkit-font-smoothing: antialiased; line-height: 1.65;
      font-size: var(--base-font-size); min-height: 100vh;
    }
    img { max-width: 100%; }

    .content-wrapper { max-width: var(--max-width); margin: 0 auto; padding: 44px 18px 60px; }

    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeInLeft { from { opacity: 0; transform: translateX(-30px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* ============ HERO ============ */
    /* RESPONSIVE FIX: the hero is a direct child of <body>, so it simply fills the width (no 100vw hack that
       overshoots on phones). It also grows with its content (min-height) so the buttons never get clipped. */
    .hero {
      position: relative; width: 100%; margin: 0;
      min-height: clamp(380px, 72vh, 550px);
      background: linear-gradient(135deg, rgba(0, 0, 0, 0.52) 0%, rgba(0, 0, 0, 0.65) 100%), url('images/aboback.png') center/cover no-repeat;
      background-attachment: scroll; overflow: hidden; display: flex; align-items: center; justify-content: center; animation: slideDown 0.4s ease;
    }
    /* Fixed (parallax) backgrounds are janky/unsupported on phones, so desktop only */
    @media (min-width: 1051px) and (hover: hover) { .hero { background-attachment: fixed; } }
    .hero::before { content: ''; position: absolute; inset: 0; background: rgba(0, 0, 0, 0.45); z-index: 1; }
    .hero-content { position: relative; z-index: 2; text-align: center; width: 100%; max-width: 900px; padding: clamp(28px, 6vw, 60px) clamp(18px, 5vw, 50px); animation: fadeIn 0.8s ease 0.3s backwards; }
    .hero h1 { font-size: clamp(1.8rem, 6.5vw, 4rem); margin-bottom: clamp(12px, 2.5vw, 24px); color: #ffffff; font-family: 'Montserrat', sans-serif; font-weight: 900; letter-spacing: -1.5px; line-height: 1.1; text-shadow: 0 12px 40px rgba(0, 0, 0, 0.7); overflow-wrap: break-word; }
    .hero p { color: rgba(255, 255, 255, 0.98); font-size: clamp(1rem, 2.4vw, 1.3rem); margin-bottom: clamp(18px, 3.5vw, 36px); font-weight: 500; line-height: 1.7; text-shadow: 0 8px 20px rgba(0, 0, 0, 0.6); }
    .hero-buttons { display: flex; gap: 14px; flex-wrap: wrap; justify-content: center; }
    .hero .btn, .hero .btn-ghost {
      padding: 16px 40px; border-radius: 12px; font-weight: 800; border: none; font-size: 1.05em;
      cursor: pointer; transition: all 0.3s ease; text-decoration: none; display: inline-flex; align-items: center; justify-content: center; gap: 10px;
      position: relative; overflow: hidden;
    }
    .hero .btn::before, .hero .btn-ghost::before {
      content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent); transition: left 0.5s ease;
    }
    .hero .btn:hover::before, .hero .btn-ghost:hover::before { left: 100%; }
    .hero .btn { background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; box-shadow: 0 8px 24px rgba(198, 69, 62, 0.45); }
    .hero .btn:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-3px); box-shadow: 0 12px 32px rgba(94, 31, 19, 0.55); }
    .hero .btn-ghost { background: rgba(255, 255, 255, 0.12); color: #ffffff; border: 2px solid rgba(255, 255, 255, 0.35); backdrop-filter: blur(10px); }
    .hero .btn-ghost:hover { background: rgba(255, 255, 255, 0.2); border-color: rgba(255, 255, 255, 0.6); transform: translateY(-3px); box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25); }

    /* ============ LAYOUT ============ */
    .main-layout { display: grid; grid-template-columns: minmax(260px, 320px) minmax(0, 1fr); gap: 32px; align-items: start; }

    .card {
      background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%); border-radius: var(--radius); padding: 32px;
      box-shadow: var(--shadow); border: 1.5px solid #F0E6D8; transition: all 0.3s ease; animation: slideUp 0.4s ease; min-width: 0;
    }
    .card:hover { box-shadow: var(--shadow-hover); border-color: var(--primary-red); transform: translateY(-4px); }
    /* The big products card holds the dropdowns/inputs: a moving (transformed) parent makes native
       dropdown lists pop up misaligned, so this card never lifts */
    .main-layout > article.card:hover { transform: none; }
    .card h2 { margin-bottom: 24px; color: var(--dark-brown); font-size: 1.5rem; font-family: 'Montserrat', sans-serif; font-weight: 900; letter-spacing: -0.5px; display: flex; align-items: center; gap: 10px; }
    .card h2 i { font-size: 2rem; color: var(--primary-red); }

    .sticky-sidebar { position: sticky; top: 120px; height: fit-content; max-height: calc(100vh - 200px); overflow-y: auto; }
    .sticky-sidebar::-webkit-scrollbar { width: 6px; }
    .sticky-sidebar::-webkit-scrollbar-track { background: rgba(198, 69, 62, 0.1); border-radius: 10px; }
    .sticky-sidebar::-webkit-scrollbar-thumb { background: rgba(198, 69, 62, 0.3); border-radius: 10px; }
    .sticky-sidebar::-webkit-scrollbar-thumb:hover { background: rgba(198, 69, 62, 0.5); }

    .products-section { margin-top: 20px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(min(100%, 240px), 1fr)); gap: 24px; }

    .product-card {
      background: white; border-radius: var(--radius); overflow: hidden; box-shadow: var(--shadow);
      transition: all 0.3s ease; border: 1.5px solid #F0E6D8; animation: slideUp 0.4s ease; display: flex; flex-direction: column;
      position: relative; min-width: 0;
    }
    .product-card:hover { box-shadow: var(--shadow-hover); border-color: var(--primary-red); transform: translateY(-8px); }
    .product-card:focus-within { transform: none; }
    .product-card.out-of-stock { opacity: 0.72; }
    .product-card.out-of-stock:hover { transform: none; }

    .price-badge {
      position: absolute; top: 14px; left: 14px; background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      color: #fff; padding: 10px 14px; border-radius: 10px; font-weight: 800; font-size: 1.05em; box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2); z-index: 2;
    }

    .out-of-stock-ribbon {
      position: absolute; top: 14px; right: 14px; background: linear-gradient(135deg, var(--gray-brown) 0%, #4a3733 100%);
      color: #fff; padding: 8px 14px; border-radius: 10px; font-weight: 800; font-size: 0.82em; z-index: 3;
      box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2); display: inline-flex; align-items: center; gap: 6px;
    }

    .img-wrap {
      position: relative; height: 240px; border-radius: 12px; overflow: hidden;
      background: linear-gradient(135deg, #FFF9F3 0%, var(--light-cream) 100%); display: flex; align-items: center; justify-content: center;
    }
    .img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.4s ease; }
    .product-card:hover .img-wrap img { transform: scale(1.08); }

    .product-details { padding: 20px; flex: 1; display: flex; flex-direction: column; min-width: 0; }
    .product-details .name { font-weight: 900; font-size: 1.15rem; color: var(--dark-brown); margin-bottom: 8px; font-family: 'Montserrat', sans-serif; line-height: 1.3; overflow-wrap: anywhere; }

    .product-details .desc {
      color: var(--gray-brown); font-size: 0.9rem; line-height: 1.55; font-weight: 500;
      display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; text-overflow: ellipsis; margin-bottom: 12px;
    }

    .product-details .size-type-tag {
      display: inline-flex; align-items: center; gap: 6px; font-size: 0.78rem; font-weight: 700; color: var(--primary-red);
      background: rgba(198, 69, 62, 0.08); border: 1px solid rgba(198, 69, 62, 0.18); padding: 4px 10px; border-radius: 20px;
      width: fit-content; max-width: 100%; margin-bottom: 10px;
    }

    .product-card label { display: block; margin-bottom: 8px; font-weight: 700; color: var(--primary-red); font-size: 0.95rem; letter-spacing: 0.3px; }

    select, .input {
      width: 100%; max-width: 100%; padding: 12px 14px; border-radius: 10px; border: 1.5px solid #F0E6D8;
      background: linear-gradient(135deg, #FFF2E0 0%, #FFFBF7 100%); font-size: 0.95rem; color: var(--dark-brown);
      font-weight: 500; transition: all 0.3s ease; margin-bottom: 12px; font-family: inherit;
    }
    select option { font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; color: var(--dark-brown); background: #fff; }
    select option:disabled { color: #a89a92; }
    select:focus, .input:focus { outline: none; border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1); }
    select:disabled, .input:disabled { opacity: 0.55; cursor: not-allowed; }

    .qty { width: 100px; }

    .stock { font-weight: 700; color: var(--primary-red); font-size: 0.95rem; margin-bottom: 12px; display: flex; align-items: center; gap: 6px; flex-wrap: wrap; }

    .badge-out {
      background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%); color: #8B3D37;
      border-radius: 8px; padding: 6px 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px;
      font-size: 0.95em; border: 1.5px solid rgba(198, 69, 62, 0.2);
    }
    .badge-low {
      background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%); color: #704214;
      border-radius: 8px; padding: 6px 12px; font-weight: 800; display: inline-flex; align-items: center; gap: 6px;
      font-size: 0.95em; border: 1.5px solid rgba(198, 69, 62, 0.2);
    }

    /* Preferences / Extras */
    .customization-block { margin-bottom: 12px; display: flex; flex-direction: column; gap: 12px; }
    .pref-row label, .extra-row-title { color: var(--dark-brown); font-size: 0.9rem; font-weight: 700; display: flex; align-items: center; gap: 6px; margin-bottom: 6px; }
    .pref-row label i, .extra-row-title i { color: var(--primary-red); }
    .extra-row { display: flex; flex-direction: column; gap: 4px; }
    .extra-opt { display: flex; align-items: center; gap: 8px; font-size: 0.88rem; color: var(--gray-brown); font-weight: 600; padding: 4px 2px; cursor: pointer; overflow-wrap: anywhere; }
    .extra-opt input { accent-color: var(--primary-red); width: 18px; height: 18px; flex-shrink: 0; }

    .line-total { font-size: 0.88rem; font-weight: 700; color: var(--primary-red); margin-bottom: 10px; }

    /* HEADER FIX: header.php also has an ".actions" group. An unscoped rule here (especially the mobile
       "flex-direction: column" below) stacked the header buttons vertically, so it is scoped to the page content. */
    .content-wrapper .actions { display: flex; gap: 8px; align-items: center; justify-content: space-between; margin-top: auto; flex-wrap: wrap; }

    .btn {
      padding: 11px 14px; border-radius: 10px; border: 0; cursor: pointer; font-weight: 800; display: inline-flex;
      align-items: center; justify-content: center; gap: 8px; transition: all 0.3s ease; text-decoration: none; position: relative; overflow: hidden; font-size: 0.95em;
    }
    .btn-primary { background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2); flex: 1; white-space: nowrap; }
    .btn-primary::before { content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%; background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent); transition: left 0.5s ease; }
    .btn-primary:hover::before { left: 100%; }
    .btn-primary:hover:not(:disabled) { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2); }
    .btn-primary:disabled { background: linear-gradient(135deg, #b7a79f 0%, #a89a92 100%); cursor: not-allowed; box-shadow: none; }

    .btn-outline { background: linear-gradient(135deg, #FFF2E0 0%, var(--light-cream) 100%); border: 1.5px solid #F0E6D8; color: var(--primary-red); flex: 1; white-space: nowrap; }
    .btn-outline:hover { background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; border-color: var(--primary-red); transform: translateY(-2px); }

    .message-list { display: flex; flex-direction: column; gap: 12px; margin-bottom: 20px; animation: slideDown 0.4s ease; }
    .message {
      background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%); border-left: 5px solid var(--primary-red);
      padding: 14px 18px; border-radius: var(--radius); color: #8B3D37; font-weight: 700; font-size: 1.05em;
      box-shadow: 0 4px 12px rgba(198, 69, 62, 0.08); display: flex; gap: 10px; align-items: center; overflow-wrap: anywhere;
    }
    .message::before { content: '✓'; font-weight: 900; font-size: 1.2em; flex-shrink: 0; }

    .empty { grid-column: 1 / -1; padding: 32px 24px; text-align: center; color: var(--gray-brown); font-weight: 700; font-size: 1.05em; }

    .contact-cta {
      margin-top: 24px; padding: 18px; background: linear-gradient(135deg, #FFFAF5 0%, #FFF9F3 100%); border-radius: 12px;
      border: 1.5px solid #F0E6D8; display: flex; align-items: center; justify-content: space-between; gap: 14px; transition: all 0.3s ease; flex-wrap: wrap;
    }
    .contact-cta:hover { background: linear-gradient(135deg, #FFF9F3 0%, #FFFAF5 100%); border-color: var(--primary-red); }
    .contact-cta .text { font-weight: 700; color: var(--dark-brown); font-size: 0.95em; }
    .contact-cta .btn {
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; border: none; font-weight: 800;
      font-size: 0.95em; padding: 10px 16px; box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2); white-space: nowrap;
    }
    .contact-cta .btn:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2); }

    /* Scoped to the Quick Facts list (a bare "ul" rule would also restyle the header menu) */
    .facts-list { padding-left: 1.5rem; margin: 0 0 14px 0; color: var(--dark-brown); font-weight: 600; list-style: none; }
    .facts-list li { margin-bottom: 10px; position: relative; padding-left: 12px; font-size: 0.95em; }
    .facts-list li::before { content: '☕'; position: absolute; left: -12px; color: var(--primary-red); font-size: 0.9em; }

    .btn-full-width { display: inline-flex; width: 100%; justify-content: center; }

    /* ============================================
       RESPONSIVE
       ============================================ */
    @media (max-width: 1100px) {
      .main-layout { grid-template-columns: minmax(0, 1fr); gap: 24px; }
      .sticky-sidebar { position: relative; top: 0; max-height: none; overflow: visible; }
      /* On tablets/phones show the products first and the Quick Facts card after them */
      .main-layout > article.card { order: 1; }
      .main-layout > aside.card { order: 2; }
    }

    @media (max-width: 768px) {
      .content-wrapper { padding: 20px 14px 48px; }
      .hero .btn, .hero .btn-ghost { padding: 12px 24px; font-size: 0.95em; }
      .card { padding: 24px; }
      .card h2 { font-size: 1.35rem; margin-bottom: 18px; }
      .grid { gap: 16px; }
      .img-wrap { height: 210px; }
      .product-details { padding: 16px; }
      .price-badge { padding: 8px 12px; font-size: 0.95em; }
      .product-details .name { font-size: 1.05rem; }
      .contact-cta { flex-direction: column; text-align: center; }
      .contact-cta .btn { width: 100%; }
      /* 16px stops iOS from zooming the page when a field is focused */
      select, .input { font-size: 16px; }
      /* Comfortable tap targets */
      .extra-opt { padding: 8px 2px; }
      .btn { min-height: 44px; }
    }

    @media (max-width: 560px) {
      .content-wrapper .actions { flex-direction: column; gap: 10px; }
      .btn-primary, .btn-outline { width: 100%; min-height: 44px; flex: none; }
    }

    @media (max-width: 480px) {
      .content-wrapper { padding: 18px 12px 36px; }
      .hero-buttons { flex-direction: column; align-items: stretch; }
      .hero .btn, .hero .btn-ghost { width: 100%; padding: 12px 18px; font-size: 0.9em; gap: 6px; }
      .card { padding: 16px; }
      .card h2 { font-size: 1.2rem; gap: 8px; }
      .card h2 i { font-size: 1.4rem; }
      .product-details { padding: 14px; }
      .product-card label { font-size: 0.85rem; margin-bottom: 6px; }
      select, .input { padding: 10px 12px; margin-bottom: 10px; }
      .qty { width: 90px; }
      .stock { font-size: 0.85rem; margin-bottom: 10px; }
      .message { padding: 12px 14px; font-size: 0.9em; }
      .message::before { font-size: 1.1em; }
      .contact-cta { padding: 14px; gap: 10px; margin-top: 18px; }
      .contact-cta .text { font-size: 0.85em; }
      .contact-cta .btn { padding: 10px 14px; font-size: 0.85em; }
      .facts-list { padding-left: 1.2rem; }
      .facts-list li { font-size: 0.9em; margin-bottom: 8px; }
    }

    @media (max-width: 360px) {
      .content-wrapper { padding: 14px 10px 32px; }
      .card { padding: 14px; }
      .product-details { padding: 12px; }
      .img-wrap { height: 190px; }
      .btn-primary, .btn-outline { white-space: normal; }
    }

    /* Hover "lift" effects feel sticky on touch screens and shift the cards while you tap */
    @media (hover: none) {
      .card:hover, .product-card:hover { transform: none; }
      .product-card:hover .img-wrap img { transform: none; }
    }
   </style>
</head>
<body>

<?php include 'header.php'; ?>

<section class="hero">
  <div class="hero-content">
    <h1 id="shopTitle">Our Premium Shop</h1>
    <p>Explore our carefully curated collection of premium coffee products and merchandise. Each item is selected with care to reflect our commitment to quality and your satisfaction.</p>
    <div class="hero-buttons">
      <a href="contact.php" class="btn">
        <i class="fa-solid fa-headset"></i> Customer Support
      </a>
      <a href="Item.php" class="btn-ghost">
        <i class="fa-solid fa-shopping-bag"></i> Continue Shopping
      </a>
    </div>
  </div>
</section>

<div class="content-wrapper">

   <?php if (!empty($message)): ?>
      <div class="message-list" aria-live="polite" aria-atomic="true">
         <?php foreach ($message as $msg): ?>
            <div class="message"><?php echo htmlspecialchars($msg); ?></div>
         <?php endforeach; ?>
      </div>
   <?php endif; ?>

   <div class="main-layout">
      <aside class="card sticky-sidebar" aria-labelledby="factsTitle">
         <h2 id="factsTitle"><i class="fa-solid fa-circle-info"></i> Quick Facts</h2>
         <ul class="facts-list">
            <li><strong>Founded:</strong> Oct 30, 2020</li>
            <li><strong>Location:</strong> Cainta, Rizal</li>
            <li><strong>Focus:</strong> Quality coffee & products</li>
            <li><strong>Commitment:</strong> Excellence & community</li>
         </ul>
         <a href="register.php" class="btn btn-primary btn-full-width" style="margin-bottom: 12px;">
           <i class="fa-solid fa-user-plus"></i> Join Community
         </a>
         <div class="contact-cta">
           <div class="text">
             <i class="fa-solid fa-question-circle"></i> Need help?
           </div>
           <a href="contact.php" class="btn" style="flex: 0;">→</a>
         </div>
      </aside>

      <article class="card" aria-labelledby="productsHeading">
         <h2 id="productsHeading"><i class="fa-solid fa-shopping-bag"></i> Featured Products</h2>
         <section class="products-section">
            <div class="grid" role="list">
               <?php
                  $prod_stmt = $conn->prepare("SELECT id, name, price, image, details, size_type FROM `products` ORDER BY id DESC");
                  $prod_stmt->execute();
                  $select_products = $prod_stmt->get_result();

                  if ($select_products->num_rows > 0) {
                     while ($fetch_products = $select_products->fetch_assoc()) {
                        $product_id = (int)$fetch_products['id'];
                        $type = $fetch_products['size_type'] ?? 'cup';
                        $type_meta = $SIZE_TYPE_META[$type] ?? $SIZE_TYPE_META['cup'];

                        // ✅ Fetch ALL sizes (in or out of stock) — customer never sees numbers
                        $sizes_stmt = $conn->prepare("SELECT size, price, stock FROM `product_sizes` WHERE product_id = ? ORDER BY id ASC");
                        $sizes_stmt->bind_param("i", $product_id);
                        $sizes_stmt->execute();
                        $sizes_result = $sizes_stmt->get_result();
                        $sizes = [];
                        $min_stock = null;
                        $has_available_size = false;
                        while ($sz = $sizes_result->fetch_assoc()) {
                           $sizes[] = $sz;
                           $s = (int)$sz['stock'];
                           if ($s > 0) $has_available_size = true;
                           $min_stock = ($min_stock === null) ? $s : min($min_stock, $s);
                        }
                        $sizes_stmt->close();
                        $product_out_of_stock = !$has_available_size;
                        $dis = $product_out_of_stock ? 'disabled' : '';

                        [$preference_groups, $extra_groups] = fetch_customizations($conn, $product_id);
               ?>
               <form action="" method="post" class="product-card<?php echo $product_out_of_stock ? ' out-of-stock' : ''; ?>" role="listitem" aria-labelledby="prod-<?php echo $product_id; ?>">
                  <div class="img-wrap">
                     <?php $starting_price = !empty($sizes) ? min(array_column($sizes, 'price')) : (float)$fetch_products['price']; ?>
                     <div class="price-badge">From ₱<?php echo number_format($starting_price, 2); ?></div>
                     <?php if ($product_out_of_stock): ?>
                        <div class="out-of-stock-ribbon"><i class="fa-solid fa-ban"></i> Out of Stock</div>
                     <?php endif; ?>
                     <img src="images/<?php echo htmlspecialchars($fetch_products['image']); ?>" alt="<?php echo htmlspecialchars($fetch_products['name']); ?>" loading="lazy">
                  </div>

                  <div class="product-details">
                     <div id="prod-<?php echo $product_id; ?>" class="name"><?php echo htmlspecialchars($fetch_products['name']); ?></div>

                     <span class="size-type-tag"><i class="fa-solid <?php echo $type_meta['icon']; ?>"></i> <?php echo htmlspecialchars($type_meta['label']); ?></span>

                     <?php if (!empty($fetch_products['details'])): ?>
                        <p class="desc"><?php echo htmlspecialchars($fetch_products['details']); ?></p>
                     <?php endif; ?>

                     <div>
                        <label for="size-<?php echo $product_id; ?>">Select <?php echo htmlspecialchars($type_meta['label']); ?></label>
                        <select id="size-<?php echo $product_id; ?>" name="product_size" class="input size-select" required <?php echo $dis; ?>>
                           <option value="" disabled selected>Select <?php echo htmlspecialchars($type_meta['label']); ?></option>
                           <?php foreach ($sizes as $size): $sz_out = ((int)$size['stock'] <= 0); ?>
                           <option value="<?php echo htmlspecialchars($size['size']); ?>" data-price="<?php echo (float)$size['price']; ?>" data-stock="<?php echo (int)$size['stock']; ?>" <?php echo $sz_out ? 'disabled' : ''; ?>>
                             <?php echo htmlspecialchars($size['size']); ?> — ₱<?php echo number_format((float)$size['price'], 2); ?><?php echo $sz_out ? ' (Out of Stock)' : ''; ?>
                           </option>
                           <?php endforeach; ?>
                        </select>
                     </div>

                     <?php if (!empty($preference_groups)): ?>
                     <div class="customization-block">
                        <?php foreach ($preference_groups as $grp): ?>
                        <div class="pref-row">
                           <label><i class="fa-solid fa-sliders"></i> <?php echo htmlspecialchars($grp['title']); ?></label>
                           <select name="preferences[<?php echo (int)$grp['id']; ?>]" class="input" style="margin-bottom:0;" <?php echo $dis; ?>>
                              <option value="">No preference</option>
                              <?php foreach ($grp['options'] as $opt): ?>
                                 <option value="<?php echo (int)$opt['id']; ?>"><?php echo htmlspecialchars($opt['option_name']); ?></option>
                              <?php endforeach; ?>
                           </select>
                        </div>
                        <?php endforeach; ?>
                     </div>
                     <?php endif; ?>

                     <?php if (!empty($extra_groups)): ?>
                     <div class="customization-block">
                        <?php foreach ($extra_groups as $grp): ?>
                        <div class="extra-row">
                           <div class="extra-row-title"><i class="fa-solid fa-plus"></i> <?php echo htmlspecialchars($grp['title']); ?></div>
                           <?php foreach ($grp['options'] as $opt): ?>
                           <label class="extra-opt">
                              <input type="checkbox" name="extras[]" class="extra-checkbox" value="<?php echo (int)$opt['id']; ?>" data-price="<?php echo (float)$opt['price']; ?>" <?php echo $dis; ?>>
                              <?php echo htmlspecialchars($opt['option_name']); ?> (+₱<?php echo number_format((float)$opt['price'], 2); ?>)
                           </label>
                           <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                     </div>
                     <?php endif; ?>

                     <div class="line-total" data-base="<?php echo (float)$fetch_products['price']; ?>" style="display:none;"></div>

                     <div>
                        <label for="qty-<?php echo $product_id; ?>">Quantity</label>
                        <input id="qty-<?php echo $product_id; ?>" type="number" min="1" max="20" name="product_quantity" value="1" class="input qty product-qty" aria-label="Quantity" required <?php echo $dis; ?>>
                     </div>

                     <div class="stock">
                        <?php
                          if ($product_out_of_stock) {
                            echo '<i class="fa-solid fa-circle-xmark"></i> <span class="badge-out">Out of stock</span>';
                          } elseif ($min_stock !== null && $min_stock <= 5) {
                            echo '<i class="fa-solid fa-exclamation-triangle"></i> <span class="badge-low">Limited stock</span>';
                          } else {
                            echo '<i class="fa-solid fa-check-circle"></i> <span style="color: var(--primary-red);">In stock</span>';
                          }
                        ?>
                     </div>

                     <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                     <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">

                     <div class="actions">
                        <button type="submit" name="add_to_cart" class="btn btn-primary" aria-label="Add <?php echo htmlspecialchars($fetch_products['name']); ?> to cart" <?php echo $dis; ?>>
                           <i class="fa-solid fa-cart-plus"></i> <?php echo $product_out_of_stock ? 'Out of Stock' : 'Add to Cart'; ?>
                        </button>
                        <a href="product.php?id=<?php echo $product_id; ?>" class="btn btn-outline" style="text-decoration: none;">
                           <i class="fa-solid fa-circle-info"></i> Details
                        </a>
                     </div>
                  </div>
               </form>
               <?php
                     }
                     $prod_stmt->close();
                  } else {
                     echo '<div class="empty"><i class="fa-solid fa-inbox" style="font-size: 2.5rem; color: var(--gray-brown); margin-bottom: 12px;"></i><div style="margin-top: 12px;">No products available yet. Check back soon!</div></div>';
                  }
               ?>
            </div>
         </section>
      </article>
   </div>
</div>

<?php include 'chatbot.php'; ?>

<?php include 'footer.php'; ?>
<script src="Js/script1.js"></script>
<script>
// ✅ Stock-aware quantity capping + live price preview (no raw stock numbers shown)
(function () {
   document.querySelectorAll('form.product-card').forEach(function (form) {
      var sizeSelect = form.querySelector('.size-select');
      var qtyInput   = form.querySelector('.product-qty');
      var checkboxes = form.querySelectorAll('.extra-checkbox');
      var lineTotal  = form.querySelector('.line-total');
      var fallbackBase = lineTotal ? parseFloat(lineTotal.dataset.base || '0') : 0;

      function recalcTotal() {
         if (!lineTotal) return;
         var selectedOption = sizeSelect ? sizeSelect.options[sizeSelect.selectedIndex] : null;
         var base = selectedOption && selectedOption.value
            ? parseFloat(selectedOption.getAttribute('data-price') || '0')
            : fallbackBase;
         var extras = 0;
         checkboxes.forEach(function (cb) { if (cb.checked) extras += parseFloat(cb.dataset.price || '0'); });
         var qty = qtyInput ? (parseInt(qtyInput.value, 10) || 1) : 1;
         if (extras > 0) {
            lineTotal.style.display = 'block';
            lineTotal.textContent = 'Est. total: ₱' + ((base + extras) * qty).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
         } else {
            lineTotal.style.display = 'none';
         }
      }

      function capQty() {
         if (!sizeSelect || !qtyInput) return;
         var opt = sizeSelect.options[sizeSelect.selectedIndex];
         var stock = opt ? parseInt(opt.getAttribute('data-stock') || '0', 10) : 0;
         var cap = Math.max(1, Math.min(20, stock || 1));
         qtyInput.max = cap;
         if (parseInt(qtyInput.value || '1', 10) > cap) qtyInput.value = cap;
      }

      if (sizeSelect) sizeSelect.addEventListener('change', function () { capQty(); recalcTotal(); });
      if (qtyInput) qtyInput.addEventListener('input', recalcTotal);
      checkboxes.forEach(function (cb) { cb.addEventListener('change', recalcTotal); });

      form.addEventListener('submit', function (e) {
         if (!sizeSelect || !qtyInput) return;
         var opt = sizeSelect.options[sizeSelect.selectedIndex];
         var stock = opt ? parseInt(opt.getAttribute('data-stock') || '0', 10) : 0;
         if (parseInt(qtyInput.value || '0', 10) > stock) {
            e.preventDefault();
            alert('There isn\'t enough stock for that quantity on this size. Please lower the quantity.');
         }
      });
   });
})();
</script>
</body>
</html>