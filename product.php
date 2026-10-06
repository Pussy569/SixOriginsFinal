<?php
include 'config.php';

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

// keep the same behavior as Item.php: require login to view/add to cart
if(!isset($user_id)){
   header('location:login.php');
   exit;
}

$message = [];

// Handle add to cart using the selected size's authoritative price and stock.
if(isset($_POST['add_to_cart'])){
   $posted_product_id = (int)($_POST['product_id'] ?? 0);
   $product_size = trim((string)($_POST['product_size'] ?? ''));
   $product_quantity = max(1, (int)($_POST['product_quantity'] ?? 1));
   $product_quantity = min(20, $product_quantity);

   $transaction_started = false;
   try {
      if ($posted_product_id <= 0 || $product_size === '') {
         throw new Exception('Please select a valid size before adding to cart.');
      }

      $conn->begin_transaction();
      $transaction_started = true;
      $size_stmt = $conn->prepare("SELECT ps.id, ps.price, ps.stock, p.name, p.image FROM `product_sizes` ps JOIN `products` p ON p.id = ps.product_id WHERE ps.product_id = ? AND ps.size = ? AND ps.is_active = 1 FOR UPDATE");
      $size_stmt->bind_param("is", $posted_product_id, $product_size);
      $size_stmt->execute();
      $variant = $size_stmt->get_result()->fetch_assoc();
      $size_stmt->close();

      if (!$variant || (int)$variant['stock'] < $product_quantity) {
         throw new Exception('Sorry, there is not enough stock for that size.');
      }

      $check_cart = $conn->prepare("SELECT id FROM `cart` WHERE user_id = ? AND product_id = ? AND size = ?");
      $check_cart->bind_param("sis", $user_id, $posted_product_id, $product_size);
      $check_cart->execute();
      $already_in_cart = $check_cart->get_result()->num_rows > 0;
      $check_cart->close();

      if ($already_in_cart) {
         $conn->rollback();
         $transaction_started = false;
         $message[] = ['type' => 'info', 'text' => 'Already in your cart.'];
      } else {
         $stock_stmt = $conn->prepare("UPDATE `product_sizes` SET stock = stock - ? WHERE id = ? AND stock >= ?");
         $stock_stmt->bind_param("iii", $product_quantity, $variant['id'], $product_quantity);
         $stock_stmt->execute();
         if ($stock_stmt->affected_rows !== 1) {
            $stock_stmt->close();
            throw new Exception('Sorry, this size just went out of stock.');
         }
         $stock_stmt->close();

         $unit_price = (int)$variant['price'];
         $insert_stmt = $conn->prepare("INSERT INTO `cart` (user_id, product_id, name, price, quantity, image, size) VALUES (?, ?, ?, ?, ?, ?, ?)");
         $insert_stmt->bind_param("sisiiss", $user_id, $posted_product_id, $variant['name'], $unit_price, $product_quantity, $variant['image'], $product_size);
         $insert_stmt->execute();
         $insert_stmt->close();

         $conn->commit();
         $transaction_started = false;
         $message[] = ['type' => 'success', 'text' => 'Added to your cart.'];
      }
   } catch (Throwable $e) {
      if ($transaction_started) {
         try {
            $conn->rollback();
            $transaction_started = false;
         } catch (Throwable $rollback_error) {
            error_log('Product add-to-cart rollback failed: ' . $rollback_error->getMessage());
         }
      }
      error_log('Product add-to-cart failed: ' . $e->getMessage());
      $message[] = ['type' => 'error', 'text' => 'Unable to add this size to your cart right now. Please try again.'];
   }
}

// Get product id from querystring
if(!isset($_GET['id']) || !is_numeric($_GET['id'])){
   header('location:Item.php');
   exit;
}

$product_id = (int)$_GET['id'];
$select_product = mysqli_query($conn, "SELECT * FROM `products` WHERE id = '$product_id'") or die('query failed');
if(mysqli_num_rows($select_product) == 0){
   header('location:Item.php');
   exit;
}
$prod = mysqli_fetch_assoc($select_product);

// fetch ALL sizes (out-of-stock ones are shown disabled so customers see what exists)
$sizes_result = mysqli_query($conn, "SELECT * FROM `product_sizes` WHERE product_id = '$product_id' AND is_active = 1") or die('query failed');
$sizes = [];
$has_stock = false;
while($s = mysqli_fetch_assoc($sizes_result)){
   $sizes[] = $s;
   if((int)$s['stock'] > 0) $has_stock = true;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8" />
   <meta name="viewport" content="width=device-width,initial-scale=1" />
   <title><?php echo htmlspecialchars($prod['name']); ?> — Six Origins Cafe</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <style>
    /* All page classes are prefixed with "pd-" so they never clash with header.php */
    :root {
      --primary-red: #C6453E;
      --dark-brown: #5E1F13;
      --gray-brown: #664C47;
      --light-cream: #FFF2E0;
      --white: #FFFFFF;
      --line: #F0E6D8;
      --radius: 16px;
      --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
      --transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
      --max-width: 1200px;
    }

    .pd-page, .pd-page *, .pd-page *::before, .pd-page *::after { box-sizing: border-box; }

    /* "clip" avoids sideways scroll without breaking the sticky header */
    body.pd-body {
      min-height: 100vh;
      background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
      color: var(--dark-brown);
      font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      -webkit-font-smoothing: antialiased;
      line-height: 1.6;
      overflow-x: clip;
    }

    .pd-page { max-width: var(--max-width); margin: 0 auto; padding: 24px 24px 56px; width: 100%; }

    .pd-breadcrumb {
      display: flex; flex-wrap: wrap; align-items: center; gap: 8px;
      font-size: 0.92rem; color: var(--gray-brown); font-weight: 500; margin-bottom: 20px;
    }
    .pd-breadcrumb a { color: var(--primary-red); text-decoration: none; font-weight: 700; }
    .pd-breadcrumb a:hover { color: var(--dark-brown); text-decoration: underline; }
    .pd-breadcrumb strong { color: var(--dark-brown); }

    /* Toast-style message */
    .pd-msg {
      display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
      padding: 14px 16px; margin-bottom: 20px; border-radius: 12px; font-weight: 700;
      border: 1px solid; border-left-width: 5px;
    }
    .pd-msg a { margin-left: auto; color: inherit; font-weight: 800; }
    .pd-msg-success { background: rgba(76,175,80,.10); color: #2D5A3D; border-color: rgba(76,175,80,.35); border-left-color: #4CAF50; }
    .pd-msg-info { background: rgba(255,193,7,.12); color: #8a5a00; border-color: rgba(255,193,7,.4); border-left-color: #F5B400; }

    /* Layout */
    .pd-wrap { display: grid; grid-template-columns: minmax(0, 5fr) minmax(0, 6fr); gap: 40px; align-items: start; }

    .pd-card {
      background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
      border-radius: var(--radius); border: 1.5px solid var(--line); box-shadow: var(--shadow);
    }

    .pd-media { position: sticky; top: 110px; padding: 12px; }
    .pd-media-frame { border-radius: 12px; overflow: hidden; background: var(--light-cream); aspect-ratio: 1 / 1; }
    .pd-media-frame img { width: 100%; height: 100%; object-fit: cover; display: block; }

    .pd-info { padding: 32px; }
    .pd-info h1 { font-size: 2.1rem; line-height: 1.15; font-weight: 900; letter-spacing: -0.5px; margin-bottom: 10px; }
    .pd-price { color: var(--primary-red); font-weight: 900; font-size: 1.8rem; margin-bottom: 14px; }
    .pd-desc { color: var(--gray-brown); font-weight: 500; font-size: 0.98rem; max-width: 62ch; }

    .pd-divider { height: 1px; background: var(--line); margin: 24px 0; }

    .pd-group { margin-bottom: 24px; }
    .pd-label {
      display: flex; justify-content: space-between; align-items: baseline; gap: 10px;
      font-weight: 800; font-size: 0.98rem; margin-bottom: 10px;
    }
    .pd-label small { font-weight: 600; color: var(--gray-brown); font-size: 0.85rem; }

    /* Size chips */
    .pd-chips { display: flex; flex-wrap: wrap; gap: 10px; }
    .pd-chip { position: relative; }
    .pd-chip input { position: absolute; opacity: 0; inset: 0; width: 100%; height: 100%; margin: 0; cursor: pointer; }
    .pd-chip span {
      display: flex; flex-direction: column; align-items: center; justify-content: center;
      min-width: 84px; padding: 10px 18px; border-radius: 12px;
      background: var(--white); border: 1.5px solid var(--line);
      font-weight: 800; text-transform: uppercase; transition: var(--transition);
    }
    .pd-chip span em { font-style: normal; font-weight: 600; font-size: 0.75rem; color: var(--gray-brown); text-transform: none; }
    .pd-chip:hover span { border-color: var(--primary-red); }
    .pd-chip input:checked + span {
      background: var(--primary-red); border-color: var(--primary-red); color: #fff;
      box-shadow: 0 6px 16px rgba(198,69,62,.25);
    }
    .pd-chip input:checked + span em { color: rgba(255,255,255,.85); }
    .pd-chip input:focus-visible + span { outline: 3px solid rgba(198,69,62,.45); outline-offset: 2px; }
    .pd-chip input:disabled { cursor: not-allowed; }
    .pd-chip input:disabled + span { opacity: .45; background: #f6f1ea; text-decoration: line-through; }

    /* Stock badge */
    .pd-stock { display: inline-flex; align-items: center; gap: 8px; padding: 8px 14px; border-radius: 10px; font-weight: 700; font-size: 0.9rem; border: 1px solid; }
    .pd-stock.ok { background: rgba(76,175,80,.10); color: #2D5A3D; border-color: rgba(76,175,80,.3); }
    .pd-stock.low { background: rgba(255,193,7,.12); color: #8a5a00; border-color: rgba(255,193,7,.4); }
    .pd-stock.none { background: rgba(198,69,62,.10); color: var(--primary-red); border-color: rgba(198,69,62,.3); }
    .pd-stock.idle { background: var(--white); color: var(--gray-brown); border-color: var(--line); }

    /* Quantity + total */
    .pd-buyrow { display: flex; align-items: center; justify-content: space-between; gap: 16px; flex-wrap: wrap; }
    .pd-qty { display: inline-flex; align-items: center; border: 1.5px solid var(--line); border-radius: 12px; background: var(--white); overflow: hidden; }
    .pd-qty button {
      width: 44px; height: 46px; border: 0; background: transparent; color: var(--dark-brown);
      font-size: 1rem; cursor: pointer; transition: var(--transition);
    }
    .pd-qty button:hover:not(:disabled) { background: var(--light-cream); color: var(--primary-red); }
    .pd-qty button:disabled { opacity: .4; cursor: not-allowed; }
    .pd-qty input {
      width: 56px; height: 46px; border: 0; text-align: center; font-weight: 800; font-size: 1rem;
      font-family: inherit; color: var(--dark-brown); background: transparent; -moz-appearance: textfield;
    }
    .pd-qty input::-webkit-outer-spin-button, .pd-qty input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
    .pd-qty:focus-within { border-color: var(--primary-red); box-shadow: 0 0 0 3px rgba(198,69,62,.12); }
    .pd-qty input:focus { outline: none; }

    .pd-total { text-align: right; }
    .pd-total small { display: block; color: var(--gray-brown); font-weight: 600; font-size: 0.82rem; }
    .pd-total strong { font-size: 1.5rem; font-weight: 900; color: var(--dark-brown); }

    .pd-actions { display: flex; gap: 12px; flex-wrap: wrap; margin-top: 8px; }
    .pd-btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 8px;
      padding: 14px 24px; border-radius: 12px; border: 1.5px solid transparent;
      font-weight: 800; font-size: 1rem; font-family: inherit; text-decoration: none; cursor: pointer;
      transition: var(--transition);
    }
    .pd-btn-primary { flex: 1 1 220px; background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; box-shadow: 0 6px 16px rgba(198,69,62,.25); }
    .pd-btn-primary:hover:not(:disabled) { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); }
    .pd-btn-primary:disabled { opacity: .55; cursor: not-allowed; }
    .pd-btn-outline { background: var(--white); border-color: var(--line); color: var(--dark-brown); }
    .pd-btn-outline:hover { border-color: var(--primary-red); background: var(--light-cream); }
    .pd-btn:focus-visible { outline: 3px solid rgba(198,69,62,.45); outline-offset: 2px; }

    .pd-hint { color: var(--primary-red); font-weight: 700; font-size: 0.88rem; margin-top: 10px; min-height: 1.3em; }

    /* Responsive */
    @media (max-width: 900px) {
      .pd-page { padding: 18px 16px 44px; }
      .pd-wrap { grid-template-columns: 1fr; gap: 20px; }
      .pd-media { position: static; max-width: 520px; margin: 0 auto; width: 100%; }
      .pd-info { padding: 24px; }
      .pd-info h1 { font-size: 1.8rem; }
    }
    @media (max-width: 560px) {
      .pd-page { padding: 14px 14px 36px; }
      .pd-breadcrumb { font-size: 0.85rem; margin-bottom: 14px; }
      .pd-media-frame { aspect-ratio: 4 / 3; }
      .pd-info { padding: 18px; }
      .pd-info h1 { font-size: 1.45rem; }
      .pd-price { font-size: 1.5rem; }
      .pd-chip span { min-width: 74px; padding: 9px 14px; }
      .pd-buyrow { flex-direction: column; align-items: stretch; }
      .pd-qty { align-self: flex-start; }
      .pd-total { text-align: left; }
      .pd-btn { width: 100%; }
    }
    @media (hover: none) { .pd-btn-primary:hover:not(:disabled) { transform: none; } }
    @media (prefers-reduced-motion: reduce) { * { transition: none !important; animation: none !important; } }
   </style>
</head>
<body class="pd-body">

<?php include 'header.php'; ?>

<div class="pd-page">
   <nav class="pd-breadcrumb" aria-label="Breadcrumb">
     <a href="index.php"><i class="fa-solid fa-house"></i> Home</a>
     <span>/</span>
     <a href="Item.php">Shop</a>
     <span>/</span>
     <strong><?php echo htmlspecialchars($prod['name']); ?></strong>
   </nav>

   <?php foreach ($message as $msg): ?>
      <div class="pd-msg pd-msg-<?php echo $msg['type']; ?>" role="status">
        <i class="fa-solid <?php echo $msg['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-info'; ?>"></i>
        <span><?php echo htmlspecialchars($msg['text']); ?></span>
        <a href="cart.php">View cart</a>
      </div>
   <?php endforeach; ?>

   <div class="pd-wrap" role="main">
      <div class="pd-card pd-media">
         <div class="pd-media-frame">
            <img src="images/<?php echo htmlspecialchars($prod['image']); ?>" alt="<?php echo htmlspecialchars($prod['name']); ?>">
         </div>
      </div>

      <div class="pd-card pd-info" aria-labelledby="prodTitle">
         <h1 id="prodTitle"><?php echo htmlspecialchars($prod['name']); ?></h1>
         <div class="pd-price">₱<?php echo htmlspecialchars(number_format($prod['price'], 2)); ?></div>

         <p class="pd-desc">
            <?php if (!empty($prod['details'])): ?>
               <?php echo nl2br(htmlspecialchars($prod['details'])); ?>
            <?php else: ?>
               Premium quality coffee from Six Origins Cafe.
            <?php endif; ?>
         </p>

         <div class="pd-divider"></div>

         <form action="" method="post" id="cartForm" aria-label="Add product to cart form">
            <div class="pd-group">
               <div class="pd-label">
                  <span>Choose a size</span>
                  <small>Required</small>
               </div>

               <?php if ($has_stock): ?>
                  <div class="pd-chips" role="radiogroup" aria-label="Size">
                     <?php foreach ($sizes as $s): $stock = (int)$s['stock']; ?>
                        <label class="pd-chip">
                           <input type="radio" name="product_size" required
                                  value="<?php echo htmlspecialchars($s['size']); ?>"
                                  data-price="<?php echo (float)$s['price']; ?>"
                                  data-stock="<?php echo $stock; ?>"
                                  <?php echo $stock <= 0 ? 'disabled' : ''; ?>>
                           <span>
                              <?php echo htmlspecialchars($s['size']); ?>
                              <em>₱<?php echo number_format((float)$s['price'], 2); ?> · <?php echo $stock > 0 ? $stock . ' left' : 'Sold out'; ?></em>
                           </span>
                        </label>
                     <?php endforeach; ?>
                  </div>
               <?php else: ?>
                  <span class="pd-stock none"><i class="fa-solid fa-ban"></i> Out of stock</span>
               <?php endif; ?>
            </div>

            <div class="pd-group">
               <div class="pd-label"><span>Availability</span></div>
               <span class="pd-stock <?php echo $has_stock ? 'idle' : 'none'; ?>" id="stockBadge" aria-live="polite">
                  <?php if ($has_stock): ?>
                     <i class="fa-solid fa-hand-pointer"></i> Select a size to see stock
                  <?php else: ?>
                     <i class="fa-solid fa-circle-xmark"></i> No sizes available
                  <?php endif; ?>
               </span>
            </div>

            <div class="pd-group">
               <div class="pd-buyrow">
                  <div>
                     <div class="pd-label"><span>Quantity</span></div>
                     <div class="pd-qty">
                        <button type="button" id="qtyMinus" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
                        <input id="qty" type="number" min="1" max="99" name="product_quantity" value="1" inputmode="numeric" aria-label="Quantity">
                        <button type="button" id="qtyPlus" aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>
                     </div>
                  </div>
                  <div class="pd-total">
                     <small>Total</small>
                     <strong id="totalPrice">₱<?php echo number_format($prod['price'], 2); ?></strong>
                  </div>
               </div>
               <div class="pd-hint" id="hint" role="alert"></div>
            </div>

            <div class="pd-actions">
               <?php if ($has_stock): ?>
                  <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                  <input type="hidden" name="product_name" value="<?php echo htmlspecialchars($prod['name']); ?>">
                  <input type="hidden" name="product_image" value="<?php echo htmlspecialchars($prod['image']); ?>">
                  <button type="submit" name="add_to_cart" class="pd-btn pd-btn-primary" aria-label="Add to cart">
                     <i class="fa-solid fa-cart-plus"></i> Add to cart
                  </button>
               <?php else: ?>
                  <button type="button" class="pd-btn pd-btn-primary" disabled aria-label="Product unavailable">
                    <i class="fa-solid fa-ban"></i> Unavailable
                  </button>
               <?php endif; ?>

               <a href="Item.php" class="pd-btn pd-btn-outline">
                 <i class="fa-solid fa-arrow-left"></i> Back to shop
               </a>
            </div>
         </form>
      </div>
   </div>
</div>

<?php include 'footer.php'; ?>

<script>
(function () {
   var fallbackPrice = <?php echo json_encode((float)$prod['price']); ?>;
   var radios = document.querySelectorAll('input[name="product_size"]');
   var qty = document.getElementById('qty');
   var minus = document.getElementById('qtyMinus');
   var plus = document.getElementById('qtyPlus');
   var badge = document.getElementById('stockBadge');
   var total = document.getElementById('totalPrice');
   var hint = document.getElementById('hint');
   var maxQty = 99;

   function selectedStock() {
      for (var i = 0; i < radios.length; i++) {
         if (radios[i].checked) return parseInt(radios[i].dataset.stock, 10) || 0;
      }

      function selectedPrice() {
         for (var i = 0; i < radios.length; i++) {
            if (radios[i].checked) return parseFloat(radios[i].dataset.price) || 0;
         }
         return fallbackPrice;
      }
      return null;
   }

   function setBadge(cls, icon, text) {
      badge.className = 'pd-stock ' + cls;
      badge.innerHTML = '<i class="fa-solid ' + icon + '"></i> ' + text;
   }

   function refresh() {
      var s = selectedStock();
      maxQty = s === null ? 99 : Math.min(99, s);

      var q = parseInt(qty.value, 10);
      if (isNaN(q) || q < 1) q = 1;
      hint.textContent = '';
      if (q > maxQty) { q = maxQty; hint.textContent = 'Only ' + maxQty + ' available for this size.'; }
      qty.value = q;
      qty.max = maxQty;

      minus.disabled = q <= 1;
      plus.disabled = q >= maxQty;
      total.textContent = '₱' + (selectedPrice() * q).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

      if (s === null) return;
      if (s <= 0) setBadge('none', 'fa-circle-xmark', 'Out of stock');
      else if (s <= 2) setBadge('low', 'fa-triangle-exclamation', 'Only ' + s + ' left!');
      else setBadge('ok', 'fa-circle-check', 'In stock');
   }

   radios.forEach(function (r) { r.addEventListener('change', refresh); });
   qty.addEventListener('input', refresh);
   minus.addEventListener('click', function () { qty.value = (parseInt(qty.value, 10) || 1) - 1; refresh(); });
   plus.addEventListener('click', function () { qty.value = (parseInt(qty.value, 10) || 1) + 1; refresh(); });
   refresh();
})();
</script>
<script src="Js/script1.js"></script>
</body>
</html>