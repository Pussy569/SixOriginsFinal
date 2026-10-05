<?php
// index.php — main storefront page (fits header.php and footer.php)
include 'config.php';

// Allow both logged-in users and guests
$user_id = $_SESSION['user_id'] ?? null;
$is_guest = $user_id === null;

// If guest, create a temporary session identifier for cart management
if ($is_guest) {
   if (!isset($_SESSION['guest_cart_id'])) {
      $_SESSION['guest_cart_id'] = bin2hex(random_bytes(16));
   }
   $user_id = 'guest_' . $_SESSION['guest_cart_id'];
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
 * configured for a product (admin_products.php -> Manage Preferences /
 * Manage Extras). Returns [ $preference_groups, $extra_groups ].
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
 * actually configured for this product (never trust the client), and
 * build the human-readable summaries + a dedup hash + the extra cost.
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

$message = [];

// ✅ WELCOME MESSAGE FOR NEWLY LOGGED-IN USERS
if (isset($_SESSION['just_logged_in']) && $_SESSION['just_logged_in'] === true) {
   $user_name = $_SESSION['user_name'] ?? 'Guest';
   $message[] = ' Welcome back, ' . htmlspecialchars($user_name) . '! Enjoy exploring our menu.';
   unset($_SESSION['just_logged_in']);
}

// ==================== ADD TO CART ====================
if (isset($_POST['add_to_cart'])) {

   if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
      $message[] = 'Your session has expired. Please refresh the page and try again.';
   } else {

      $product_id       = isset($_POST['product_id']) ? (int)$_POST['product_id'] : 0;
      $product_size     = trim($_POST['product_size'] ?? '');
      $product_quantity = isset($_POST['product_quantity']) ? (int)$_POST['product_quantity'] : 1;

      // Never trust the browser for quantity
      if ($product_quantity < 1) $product_quantity = 1;
      if ($product_quantity > 20) $product_quantity = 20;

      if ($product_id <= 0 || $product_size === '') {
         $message[] = 'Please select a valid size before adding to cart.';
      } else {

         // Look up the real product info from the DB (never trust hidden POST fields for price)
         $prod_stmt = $conn->prepare("SELECT name, price, image FROM `products` WHERE id = ?");
         $prod_stmt->bind_param("i", $product_id);
         $prod_stmt->execute();
         $product_row = $prod_stmt->get_result()->fetch_assoc();
         $prod_stmt->close();

         if (!$product_row) {
            $message[] = 'That product no longer exists.';
         } else {

            // Validate the chosen preferences/extras against what the admin actually configured
            $sel = validate_and_summarize_selections($conn, $product_id, $_POST['preferences'] ?? [], $_POST['extras'] ?? []);
            $unit_price = intval(round((float)$product_row['price'] + $sel['extras_total']));

            $conn->begin_transaction();
            try {
               // Lock the size row so two shoppers can't both take the last unit
               $lock_stmt = $conn->prepare("SELECT id, stock FROM `product_sizes` WHERE product_id = ? AND size = ? FOR UPDATE");
               $lock_stmt->bind_param("is", $product_id, $product_size);
               $lock_stmt->execute();
               $size_row = $lock_stmt->get_result()->fetch_assoc();
               $lock_stmt->close();

               // ✅ We never reveal the exact stock number to the customer — just
               // reject once the requested quantity can't be fulfilled.
               if (!$size_row || (int)$size_row['stock'] < $product_quantity) {
                  throw new Exception('Sorry, we don\'t have enough stock left for that quantity. Please lower the quantity and try again.');
               }

               $cart_check = $conn->prepare("SELECT id FROM `cart` WHERE user_id = ? AND product_id = ? AND size = ? AND selections_hash = ?");
               $cart_check->bind_param("siss", $user_id, $product_id, $product_size, $sel['hash']);
               $cart_check->execute();
               $already_in_cart = $cart_check->get_result()->num_rows > 0;
               $cart_check->close();

               if ($already_in_cart) {
                  $conn->rollback();
                  $message[] = 'This item (with the same size/preferences/extras) is already in your cart!';
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
                  $message[] = 'Product added to cart!';
               }
            } catch (Exception $e) {
               $conn->rollback();
               $message[] = $e->getMessage();
            }
         }
      }
   }
}
?>
<?php include 'header.php'; ?>
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

html, body { 
  margin: 0;
  padding: 0;
  width: 100%;
  overflow-x: hidden;
  -webkit-text-size-adjust: 100%;
}

body { 
  background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
  margin: 0;
  padding: 0;
}

* {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
}

img, video { max-width: 100%; }

.site-main {
  max-width: 1800px;
  margin: 0 auto;
  padding: 0 24px 64px;
  width: 100%;
}

/* Hero Section */
.hero {
  width: 100vw;
  position: relative;
  left: 50%;
  right: 50%;
  margin-left: -50vw;
  margin-right: -50vw;
  height: 600px;
  height: clamp(400px, 80vh, 600px);
  background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
  overflow: hidden;
  animation: fadeInDown 0.6s ease-out;
  margin-bottom: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
}

@keyframes fadeInDown {
  from { opacity: 0; transform: translateY(-30px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInUp {
  from { opacity: 0; transform: translateY(30px); }
  to { opacity: 1; transform: translateY(0); }
}

@keyframes fadeInLeft {
  from { opacity: 0; transform: translateX(-30px); }
  to { opacity: 1; transform: translateX(0); }
}

@keyframes fadeInRight {
  from { opacity: 0; transform: translateX(30px); }
  to { opacity: 1; transform: translateX(0); }
}

@keyframes fadeIn {
  from { opacity: 0; }
  to { opacity: 1; }
}

@keyframes scaleIn {
  from { opacity: 0; transform: scale(0.95); }
  to { opacity: 1; transform: scale(1); }
}

.hero video {
  position: absolute;
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  top: 0;
  left: 0;
}

.hero::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0; bottom: 0;
  background: radial-gradient(circle at center, rgba(0, 0, 0, 0.3) 0%, rgba(0, 0, 0, 0.5) 100%);
  z-index: 1;
  pointer-events: none;
}

.hero-content {
  position: relative;
  z-index: 2;
  text-align: center;
  max-width: 700px;
  padding: clamp(20px, 5vw, 40px);
  animation: scaleIn 0.8s ease-out 0.2s both;
}

.hero-content h1 {
  font-family: 'Romelio Sans', serif;
  font-size: clamp(2rem, 7.5vw, 4.2rem);
  font-weight: 900;
  color: #ffffff;
  margin: 0;
  line-height: 1.15;
  letter-spacing: -1px;
  text-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
  overflow-wrap: break-word;
}

.hero-content p {
  font-size: clamp(1rem, 2.8vw, 1.4rem);
  color: rgba(255, 255, 255, 0.95);
  margin: 20px 0 0 0;
  line-height: 1.6;
  text-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
  font-weight: 500;
  animation: fadeInUp 0.8s ease-out 0.4s both;
}

.hero-content .cta-btn {
  display: inline-block;
  margin-top: 28px;
  padding: 14px 36px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  text-decoration: none;
  border-radius: 10px;
  font-weight: 800;
  font-size: 1.08rem;
  transition: var(--transition);
  box-shadow: 0 8px 24px rgba(198, 69, 62, 0.35);
  animation: fadeInUp 0.8s ease-out 0.5s both;
  cursor: pointer;
  border: none;
  position: relative;
  overflow: hidden;
}

.hero-content .cta-btn::before {
  content: '';
  position: absolute;
  top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
  transition: left 0.5s ease;
}

.hero-content .cta-btn:hover::before { left: 100%; }

.hero-content .cta-btn:hover {
  transform: translateY(-3px);
  background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
  box-shadow: 0 12px 32px rgba(94, 31, 19, 0.35);
}

/* Guest Badge */
.guest-badge {
  padding: 16px 20px;
  border-radius: var(--radius);
  background: linear-gradient(135deg, rgba(102, 76, 71, 0.1) 0%, rgba(102, 76, 71, 0.05) 100%);
  border-left: 5px solid var(--gray-brown);
  margin-bottom: 24px;
  text-align: center;
  font-size: 0.95rem;
  box-shadow: 0 4px 12px rgba(102, 76, 71, 0.08);
  border: 1px solid rgba(102, 76, 71, 0.15);
  animation: fadeInUp 0.4s ease;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 12px;
  color: var(--gray-brown);
  font-weight: 600;
}

.guest-badge i { font-size: 1.2rem; color: var(--gray-brown); }
.guest-badge strong { color: var(--dark-brown); font-weight: 800; }
.guest-badge a { color: var(--primary-red); text-decoration: none; font-weight: 700; margin-left: 8px; transition: var(--transition); }
.guest-badge a:hover { text-decoration: underline; color: var(--dark-brown); }

/* Messages */
.message-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-bottom: 32px;
  margin-top: 40px;
  animation: fadeInUp 0.4s ease;
}

.message {
  background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
  border-left: 5px solid var(--primary-red);
  padding: 15px 18px;
  border-radius: var(--radius);
  color: var(--primary-red);
  font-weight: 600;
  box-shadow: 0 6px 16px rgba(198, 69, 62, 0.1);
  font-size: 1.05em;
  display: flex;
  gap: 12px;
  align-items: center;
  border: 1px solid rgba(198, 69, 62, 0.2);
  animation: fadeInUp 0.4s ease-out;
  overflow-wrap: anywhere;
}

.message::before { content: '✓'; font-weight: 900; font-size: 1.3em; flex-shrink: 0; }

.message.welcome {
  background: linear-gradient(135deg, rgba(45, 90, 61, 0.1) 0%, rgba(45, 90, 61, 0.05) 100%);
  border-left-color: #2D5A3D;
  color: #2D5A3D;
  border-color: rgba(45, 90, 61, 0.2);
}
.message.welcome::before { content: '🎉'; font-size: 1.4em; }

/* Section Header */
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 32px;
  flex-wrap: wrap;
  gap: 24px;
  animation: fadeInUp 0.6s ease-out 0.2s both;
}

.section-header > div:first-child { flex: 1; min-width: min(250px, 100%); }

.section-header h2 {
  font-size: clamp(1.5rem, 4.5vw, 2.2rem);
  margin: 0;
  color: var(--dark-brown);
  font-family: 'Romelio Sans', serif;
  font-weight: 900;
  letter-spacing: -0.5px;
}

.section-header > div > div { color: var(--gray-brown); font-size: 1rem; margin-top: 8px; font-weight: 500; }

.section-filters { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }

.section-header a {
  padding: 11px 20px;
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.6);
  border: 1.5px solid #F0E6D8;
  color: var(--dark-brown);
  text-decoration: none;
  font-weight: 700;
  font-size: 0.95em;
  transition: var(--transition);
  cursor: pointer;
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}

.section-header a::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transform: translateX(-100%);
  transition: transform 0.5s ease;
}

.section-header a:hover::before { transform: translateX(100%); }

.section-header a:first-child {
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  border: none;
  box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
}

.section-header a:hover, .section-header a:focus {
  transform: translateY(-2px);
  background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
  color: #fff;
  box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
}

/* Product Grid */
.grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(min(100%, 260px), 1fr));
  gap: clamp(16px, 2.5vw, 28px);
  animation: fadeIn 0.6s ease-out 0.4s both;
}

.product {
  background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
  border-radius: var(--radius);
  padding: 18px;
  box-shadow: var(--shadow);
  border: 1.5px solid #F0E6D8;
  display: flex;
  flex-direction: column;
  gap: 14px;
  position: relative;
  min-width: 0;
  transition: var(--transition);
  animation: fadeInUp 0.5s ease-out;
}

.product:nth-child(1) { animation-delay: 0.4s; }
.product:nth-child(2) { animation-delay: 0.45s; }
.product:nth-child(3) { animation-delay: 0.5s; }
.product:nth-child(n+4) { animation-delay: 0.55s; }

.product:hover {
  box-shadow: var(--shadow-hover);
  border-color: var(--primary-red);
  transform: translateY(-4px);
}

.product.out-of-stock { opacity: 0.72; }
.product.out-of-stock:hover { transform: none; border-color: #F0E6D8; }

.badge-price {
  position: absolute;
  top: 14px;
  left: 14px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  padding: 10px 14px;
  border-radius: 10px;
  font-weight: 800;
  font-size: 1.05em;
  box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
  z-index: 2;
}

.out-of-stock-ribbon {
  position: absolute;
  top: 14px;
  right: 14px;
  background: linear-gradient(135deg, var(--gray-brown) 0%, #4a3733 100%);
  color: #fff;
  padding: 8px 14px;
  border-radius: 10px;
  font-weight: 800;
  font-size: 0.82em;
  z-index: 3;
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.2);
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.product .img-wrap {
  height: 240px;
  border-radius: 12px;
  overflow: hidden;
  background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
}

.product .img-wrap .image { width: 100%; height: 100%; object-fit: cover; display: block; transition: transform 0.3s ease; }
.product:hover .img-wrap .image { transform: scale(1.08); }

.product .title {
  font-weight: 800;
  color: var(--dark-brown);
  font-size: 1.12rem;
  font-family: 'Romelio Sans', serif;
  line-height: 1.3;
  overflow-wrap: anywhere;
}

.product .desc {
  color: var(--gray-brown);
  font-size: 0.9rem;
  line-height: 1.55;
  font-weight: 500;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: -4px;
}

.product .size-type-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--primary-red);
  background: rgba(198, 69, 62, 0.08);
  border: 1px solid rgba(198, 69, 62, 0.18);
  padding: 4px 10px;
  border-radius: 20px;
  width: fit-content;
}

.product label {
  color: var(--gray-brown);
  font-size: 0.95em;
  display: block;
  margin-bottom: 8px;
  font-weight: 600;
  letter-spacing: 0.3px;
}

.product label i { color: var(--primary-red); margin-right: 4px; }

.product select, .product input.qty {
  width: 100%;
  padding: 11px 14px;
  border-radius: 10px;
  border: 1.5px solid #F0E6D8;
  background: linear-gradient(135deg, #FFFAF5 0%, #FFFBF7 100%);
  color: var(--dark-brown);
  font-size: 0.95em;
  transition: var(--transition);
  font-weight: 500;
  font-family: inherit;
}

.product select:focus, .product input.qty:focus {
  outline: none;
  border-color: var(--primary-red);
  box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
}

.product select:disabled, .product input.qty:disabled { opacity: 0.55; cursor: not-allowed; }

/* Dropdown list styling (matches the site fonts/colors) */
.product select option {
  font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
  font-size: 16px;
  color: var(--dark-brown);
  background: #fff;
}
.product select option:disabled { color: #a89a92; }

/* A transformed parent can make the native dropdown pop up misaligned.
   Keep the card still while its select/checkbox is focused, and on touch screens. */
.product:focus-within { transform: none; }
@media (hover: none) {
  .product:hover { transform: none; }
  .product:hover .img-wrap .image { transform: none; }
}

/* 16px prevents iOS from zooming the page when a field is focused */
@media (max-width: 640px) {
  .product select, .product input.qty { font-size: 16px; }
}

.product input.qty { width: 90px; padding: 10px; }

/* Preferences / Extras */
.customization-block { display: flex; flex-direction: column; gap: 12px; }
.pref-row label, .extra-row-title {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--dark-brown);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}
.pref-row label i, .extra-row-title i { color: var(--primary-red); }
.extra-row { display: flex; flex-direction: column; gap: 4px; }
.extra-opt {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.85rem;
  color: var(--gray-brown);
  font-weight: 600;
  padding: 4px 2px;
  cursor: pointer;
  overflow-wrap: anywhere;
}
.extra-opt input { accent-color: var(--primary-red); width: 16px; height: 16px; flex-shrink: 0; }
.extra-opt input:disabled { cursor: not-allowed; }

.line-total {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--primary-red);
  margin-top: -4px;
}

.meta {
  display: flex;
  gap: 10px;
  align-items: center;
  justify-content: space-between;
  margin-top: 8px;
  flex-wrap: wrap;
}

.btn.btn-primary, .product button[type="submit"] {
  padding: 11px 16px;
  border-radius: 10px;
  border: 0;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  font-weight: 800;
  font-size: 0.95em;
  transition: var(--transition);
  cursor: pointer;
  box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
  display: inline-flex;
  align-items: center;
  gap: 8px;
  position: relative;
  overflow: hidden;
  white-space: nowrap;
  font-family: inherit;
}

.product button[type="submit"]:disabled {
  background: linear-gradient(135deg, #b7a79f 0%, #a89a92 100%);
  cursor: not-allowed;
  box-shadow: none;
}

.btn.btn-primary::before, .product button[type="submit"]::before {
  content: '';
  position: absolute;
  top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transition: left 0.5s ease;
}

.btn.btn-primary:hover::before, .product button[type="submit"]:hover::before { left: 100%; }

.btn.btn-primary:hover, .product button[type="submit"]:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
}

.empty-message {
  grid-column: 1 / -1;
  padding: 48px 24px;
  text-align: center;
  color: var(--gray-brown);
  font-size: 1.1em;
  animation: fadeIn 0.5s ease-out;
}

/* CTA Row */
.cta-row {
  text-align: center;
  margin-top: 48px;
  display: flex;
  gap: 14px;
  flex-wrap: wrap;
  justify-content: center;
  animation: fadeInUp 0.6s ease-out 0.6s both;
}

.cta-row a, .cta-row button {
  display: inline-block;
  padding: 13px 28px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  border-radius: 10px;
  text-decoration: none;
  font-weight: 800;
  border: none;
  cursor: pointer;
  font-size: 1.05em;
  transition: var(--transition);
  box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
  position: relative;
  overflow: hidden;
  white-space: nowrap;
}

.cta-row a::before, .cta-row button::before {
  content: '';
  position: absolute;
  top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transition: left 0.5s ease;
}

.cta-row a:hover::before, .cta-row button:hover::before { left: 100%; }

.cta-row a:hover, .cta-row button:hover {
  background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
}

.cta-row button { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); }
.cta-row button:hover { background: linear-gradient(135deg, var(--gray-brown) 0%, var(--dark-brown) 100%); }

/* ============================================
   COFFEE MOMENT
   ============================================ */
@keyframes pourReveal {
  0% { clip-path: inset(100% 0 0 0); opacity: 0; }
  35% { opacity: 1; }
  100% { clip-path: inset(0 0 0 0); opacity: 1; }
}
@keyframes kenBurns { 0%, 100% { transform: scale(1) translate(0, 0); } 50% { transform: scale(1.07) translate(-1.5%, -1%); } }
@keyframes cardGlowPulse {
  0%, 100% { box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5), 0 0 0 rgba(255, 180, 90, 0); }
  50% { box-shadow: 0 24px 60px rgba(0, 0, 0, 0.5), 0 0 55px rgba(255, 180, 90, 0.35); }
}
@keyframes shineSweep {
  0% { transform: translateX(-140%) skewX(-20deg); opacity: 0; }
  8% { opacity: 0.55; }
  30% { opacity: 0; }
  100% { transform: translateX(220%) skewX(-20deg); opacity: 0; }
}
@keyframes steamRise {
  0% { transform: translateY(0) translateX(0) scale(0.6); opacity: 0; }
  15% { opacity: 0.55; }
  100% { transform: translateY(-140px) translateX(var(--drift, 10px)) scale(1.3); opacity: 0; }
}
@keyframes sparkleTwinkle { 0%, 100% { opacity: 0.25; transform: scale(0.9); } 50% { opacity: 1; transform: scale(1.15); } }

.coffee-moment {
  width: 100vw;
  position: relative;
  left: 50%; right: 50%;
  margin-left: -50vw; margin-right: -50vw;
  margin-top: 56px; margin-bottom: 8px;
  padding: 80px 24px;
  background: radial-gradient(circle at 50% 20%, #3D1E13 0%, var(--dark-brown) 45%, #2A0F08 100%);
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  transform: translateY(30px);
  transition: opacity 0.9s ease, transform 0.9s ease;
}
.coffee-moment.in-view { opacity: 1; transform: translateY(0); }

.coffee-moment-inner {
  position: relative;
  z-index: 2;
  max-width: 1100px;
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 56px;
  flex-wrap: wrap;
}

.coffee-moment-visual { position: relative; width: 380px; max-width: 90vw; flex-shrink: 0; display: flex; align-items: center; justify-content: center; }

.coffee-moment-frame {
  position: relative;
  z-index: 2;
  width: 100%;
  border-radius: 20px;
  overflow: hidden;
  cursor: pointer;
  transition: transform 0.4s ease;
  animation: pourReveal 1.15s cubic-bezier(0.65, 0, 0.35, 1) both, cardGlowPulse 4.5s ease-in-out 1.15s infinite;
}

.coffee-moment-img { width: 100%; display: block; border-radius: 20px; transition: transform 0.4s ease; animation: kenBurns 9s ease-in-out 1.15s infinite; }

.coffee-moment-shine {
  position: absolute; top: -25%; left: 0; width: 35%; height: 150%;
  background: linear-gradient(120deg, transparent, rgba(255, 255, 255, 0.35), transparent);
  transform: translateX(-140%) skewX(-20deg);
  animation: shineSweep 5.5s ease-in-out 1.6s infinite;
  pointer-events: none; z-index: 3;
}

.coffee-moment-visual:hover .coffee-moment-frame { transform: scale(1.02); }
.coffee-moment-visual:hover .coffee-moment-img { transform: scale(1.1); }

.coffee-steam {
  position: absolute; top: 32%; width: 16px; height: 16px; border-radius: 50%;
  background: rgba(255, 255, 255, 0.5); filter: blur(6px); z-index: 4; pointer-events: none;
  animation: steamRise 4s ease-in infinite;
}
.coffee-steam:nth-child(1) { left: 57%; --drift: -18px; animation-delay: 0s; }
.coffee-steam:nth-child(2) { left: 64%; top: 26%; --drift: 14px; animation-delay: 1.3s; width: 12px; height: 12px; }
.coffee-steam:nth-child(3) { left: 60%; top: 38%; --drift: 6px; animation-delay: 2.5s; width: 10px; height: 10px; }

.coffee-moment-sparkle {
  position: absolute; border-radius: 50%; background: #FFE7B8;
  box-shadow: 0 0 10px 2px rgba(255, 231, 184, 0.7);
  animation: sparkleTwinkle 2.4s ease-in-out infinite;
  z-index: 3; pointer-events: none;
}
.coffee-moment-sparkle:nth-child(4) { top: 20%; left: 55%; width: 6px; height: 6px; animation-delay: 0.2s; }
.coffee-moment-sparkle:nth-child(5) { top: 16%; left: 66%; width: 4px; height: 4px; animation-delay: 1s; }
.coffee-moment-sparkle:nth-child(6) { top: 28%; left: 70%; width: 5px; height: 5px; animation-delay: 1.7s; }

.coffee-moment-text { position: relative; z-index: 2; max-width: 440px; text-align: left; min-width: 0; }

.coffee-moment-tag {
  display: inline-flex; align-items: center; gap: 8px;
  background: rgba(255, 255, 255, 0.1); border: 1px solid rgba(255, 255, 255, 0.25);
  color: #FFE7B8; font-size: 0.78em; font-weight: 800; text-transform: uppercase; letter-spacing: 1px;
  padding: 7px 14px; border-radius: 20px; margin-bottom: 20px; backdrop-filter: blur(6px);
}

.coffee-moment-text h2 {
  font-family: 'Romelio Sans', serif; color: #fff; font-size: clamp(1.7rem, 5vw, 2.4rem); font-weight: 900;
  letter-spacing: -0.5px; line-height: 1.15; margin-bottom: 16px; text-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
}

.coffee-moment-text p { color: rgba(255, 255, 255, 0.9); font-size: 1.05rem; font-weight: 500; line-height: 1.75; margin-bottom: 28px; }

.coffee-moment-text .CTA-wrap {
  display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
  margin-top: 8px;
}

.coffee-moment-text .cta-btn {
  display: inline-flex; align-items: center; justify-content: center; gap: 10px; padding: 14px 32px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff; text-decoration: none; border-radius: 10px; font-weight: 800; font-size: 1rem;
  transition: var(--transition); box-shadow: 0 8px 24px rgba(198, 69, 62, 0.4);
  position: relative; overflow: hidden; border: 1px solid transparent;
}

.coffee-moment-text .cta-btn::before {
  content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent); transition: left 0.5s ease;
}
.coffee-moment-text .cta-btn:hover::before { left: 100%; }
.coffee-moment-text .cta-btn:hover {
  transform: translateY(-3px); background: linear-gradient(135deg, #fff 0%, #fff 100%);
  color: var(--primary-red); box-shadow: 0 12px 32px rgba(0, 0, 0, 0.35);
}
.coffee-moment-text .secondary-btn {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.22);
  color: #fff;
  box-shadow: none;
}
.coffee-moment-text .secondary-btn:hover {
  background: #fff; color: var(--dark-brown); box-shadow: 0 12px 26px rgba(0, 0, 0, 0.18);
}

/* About Section */
.about-section { margin-top: 80px; animation: fadeInUp 0.6s ease-out 0.7s both; }
.about-header { text-align: center; margin-bottom: 60px; }
.about-header h2 { font-family: 'Romelio Sans', serif; font-size: clamp(1.6rem, 5vw, 2.8rem); color: var(--dark-brown); margin: 0 0 16px 0; font-weight: 900; letter-spacing: -0.5px; }
.about-header p { font-size: 1.1rem; color: var(--gray-brown); max-width: 600px; margin: 0 auto; line-height: 1.8; font-weight: 500; }

.about-container { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 50px; align-items: center; margin-bottom: 60px; }

.carousel-wrapper {
  position: relative; border-radius: 20px; overflow: hidden;
  box-shadow: 0 12px 40px rgba(94, 31, 19, 0.15); height: 450px;
  animation: fadeInRight 0.8s ease-out 0.2s both; order: 2;
}
.carousel-container { position: relative; width: 100%; height: 100%; overflow: hidden; }
.carousel-slide { position: absolute; width: 100%; height: 100%; opacity: 0; transition: opacity 0.8s ease-in-out; display: flex; align-items: center; justify-content: center; }
.carousel-slide.active { opacity: 1; z-index: 10; }
.carousel-slide img { width: 100%; height: 100%; object-fit: cover; }
.carousel-text {
  position: absolute; bottom: 0; left: 0; right: 0;
  background: linear-gradient(to top, rgba(0, 0, 0, 0.8), transparent);
  color: white; padding: 40px 30px 30px; text-align: center; z-index: 20;
  opacity: 0; transition: opacity 0.8s ease-in-out;
}
.carousel-slide.active .carousel-text { opacity: 1; }
.carousel-text h3 { font-family: 'Romelio Sans', serif; font-size: 1.8rem; margin: 0 0 12px 0; font-weight: 900; letter-spacing: -0.3px; }
.carousel-text p { font-size: 1rem; margin: 0; line-height: 1.6; font-weight: 500; }

.carousel-controls { position: absolute; bottom: 20px; left: 50%; transform: translateX(-50%); display: flex; gap: 12px; z-index: 30; }
.carousel-dot { width: 12px; height: 12px; border-radius: 50%; background: rgba(255, 255, 255, 0.4); cursor: pointer; border: 2px solid rgba(255, 255, 255, 0.6); transition: all 0.3s ease; padding: 0; }
.carousel-dot.active { background: white; border-color: white; width: 32px; border-radius: 6px; }

.carousel-arrow {
  position: absolute; top: 50%; transform: translateY(-50%);
  background: rgba(255, 255, 255, 0.25); border: none; color: white; font-size: 24px;
  padding: 12px 16px; cursor: pointer; border-radius: 8px; transition: all 0.3s ease; z-index: 25; backdrop-filter: blur(5px);
}
.carousel-arrow:hover { background: rgba(255, 255, 255, 0.4); transform: translateY(-50%) scale(1.1); }
.carousel-prev { left: 20px; }
.carousel-next { right: 20px; }

.about-content-card {
  background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%); border-radius: 20px; padding: 48px 40px;
  box-shadow: 0 8px 32px rgba(94, 31, 19, 0.1); border: 1.5px solid #F0E6D8; transition: var(--transition);
  animation: fadeInLeft 0.8s ease-out 0.2s both; order: 1; min-width: 0;
}
.about-content-card:hover { box-shadow: 0 16px 48px rgba(94, 31, 19, 0.15); border-color: var(--dark-brown); transform: translateY(-4px); }
.about-content-card h3 { font-family: 'Romelio Sans', serif; font-size: 2rem; color: var(--dark-brown); margin: 0 0 24px 0; font-weight: 900; display: flex; align-items: center; gap: 12px; }
.about-content-card h3 i { color: var(--primary-red); font-size: 1.8rem; }
.about-content-card p { font-size: 1rem; color: var(--gray-brown); line-height: 1.9; margin: 0 0 18px 0; font-weight: 500; }
.about-content-card p:last-child { margin-bottom: 0; }
.about-content-card a {
  display: inline-block; margin-top: 28px; padding: 13px 32px; border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; text-decoration: none;
  font-weight: 700; transition: var(--transition); box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
  position: relative; overflow: hidden;
}
.about-content-card a::before {
  content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent); transition: left 0.5s ease;
}
.about-content-card a:hover::before { left: 100%; }
.about-content-card a:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(94, 31, 19, 0.3); }

.contact-section { margin-top: 80px; margin-bottom: 60px; animation: fadeInUp 0.6s ease-out 0.8s both; }
.contact-card {
  background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%); border-radius: 20px; padding: 60px 48px;
  box-shadow: 0 12px 40px rgba(94, 31, 19, 0.1); border: 1.5px solid #F0E6D8; text-align: center; position: relative;
  overflow: hidden; transition: var(--transition);
}
.contact-card::before { content: ''; position: absolute; inset: 0; background: radial-gradient(circle at 20% 50%, rgba(198, 69, 62, 0.08) 0%, transparent 50%); pointer-events: none; }
.contact-card:hover { box-shadow: 0 16px 48px rgba(94, 31, 19, 0.15); transform: translateY(-4px); border-color: var(--dark-brown); }
.contact-card h3 { font-family: 'Romelio Sans', serif; font-size: 2.2rem; color: var(--dark-brown); margin: 0 0 20px 0; font-weight: 900; letter-spacing: -0.5px; position: relative; z-index: 2; display: flex; align-items: center; justify-content: center; gap: 12px; }
.contact-card h3 i { color: var(--primary-red); font-size: 2rem; }
.contact-card p { font-size: 1.1rem; color: var(--gray-brown); line-height: 1.8; margin: 0 0 32px 0; max-width: 700px; margin-left: auto; margin-right: auto; font-weight: 500; position: relative; z-index: 2; }
.contact-card a {
  display: inline-block; padding: 14px 36px; border-radius: 10px;
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%); color: #fff; text-decoration: none;
  font-weight: 800; transition: var(--transition); box-shadow: 0 8px 24px rgba(198, 69, 62, 0.25);
  position: relative; overflow: hidden; font-size: 1rem; z-index: 2; gap: 10px; display: inline-flex; align-items: center;
}
.contact-card a::before {
  content: ''; position: absolute; top: 0; left: -100%; width: 100%; height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.3), transparent); transition: left 0.5s ease;
}
.contact-card a:hover::before { left: 100%; }
.contact-card a:hover { background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%); color: #fff; transform: translateY(-2px); box-shadow: 0 12px 32px rgba(94, 31, 19, 0.3); }

/* ============================================
   RESPONSIVE BREAKPOINTS
   ============================================ */

/* Tablet landscape / small laptop */
@media (max-width: 1100px) {
  .carousel-wrapper { height: 380px; }
  .about-content-card { padding: 40px 32px; }
  .about-container { gap: 40px; }
  .contact-card { padding: 50px 40px; }
  .contact-card h3 { font-size: 2rem; }
}

/* Tablet portrait: coffee section + about stack */
@media (max-width: 968px) {
  .about-container { grid-template-columns: minmax(0, 1fr); gap: 40px; }
  .carousel-wrapper { order: 1; height: 400px; }
  .about-content-card { order: 2; }
}

@media (max-width: 900px) {
  .coffee-moment-inner { flex-direction: column; text-align: center; gap: 36px; }
  .coffee-moment-text { text-align: center; max-width: 520px; width: 100%; }
  .coffee-moment-text .CTA-wrap { justify-content: center; }
}

@media (max-width: 480px) {
  .coffee-moment-text .CTA-wrap { flex-direction: column; width: 100%; }
  .coffee-moment-text .CTA-wrap .cta-btn { width: 100%; }
}

/* Large phones */
@media (max-width: 768px) {
  .site-main { padding: 0 16px 48px; }
  .hero { margin-bottom: 32px; }
  .hero-content .cta-btn { padding: 13px 30px; font-size: 1rem; margin-top: 22px; }
  .message-list { margin-top: 28px; margin-bottom: 24px; }
  .section-header { gap: 16px; margin-bottom: 24px; }
  .carousel-wrapper { height: 320px; margin-bottom: 0; }
  .carousel-text { padding: 30px 20px 20px; }
  .carousel-text h3 { font-size: 1.5rem; margin-bottom: 8px; }
  .carousel-text p { font-size: 0.9rem; }
  .carousel-arrow { padding: 10px 14px; font-size: 20px; }
  .about-section { margin-top: 60px; }
  .about-header { margin-bottom: 40px; }
  .about-header p { font-size: 1rem; }
  .about-container { gap: 30px; margin-bottom: 40px; }
  .about-content-card { padding: 32px 24px; }
  .about-content-card h3 { font-size: 1.6rem; gap: 10px; }
  .about-content-card h3 i { font-size: 1.4rem; }
  .about-content-card p { font-size: 0.95rem; }
  .contact-section { margin-top: 60px; margin-bottom: 40px; }
  .contact-card { padding: 40px 24px; }
  .contact-card h3 { font-size: 1.8rem; gap: 10px; }
  .contact-card h3 i { font-size: 1.6rem; }
  .contact-card p { font-size: 1rem; margin-bottom: 24px; }
  .contact-card a { padding: 12px 28px; font-size: 0.95rem; }
  .guest-badge { padding: 14px 16px; font-size: 0.9rem; flex-direction: column; text-align: center; }
  .guest-badge a { margin-left: 0; margin-top: 8px; }
  .cta-row { margin-top: 36px; }
}

/* Phones */
@media (max-width: 640px) {
  .section-filters { width: 100%; display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px; }
  .section-header .section-filters a { text-align: center; padding: 11px 10px; white-space: normal; }
  .product { padding: 16px; }
  .product .img-wrap { height: 220px; }
  .coffee-moment { padding: 56px 18px; margin-top: 40px; }
  .coffee-moment-visual { width: 280px; }
  .coffee-moment-text p { font-size: 0.95rem; }
  .cta-row { flex-direction: column; align-items: stretch; }
  .cta-row a, .cta-row button { text-align: center; }
}

@media (max-width: 480px) {
  .site-main { padding: 0 14px 40px; }
  .hero { margin-bottom: 24px; }
  .carousel-wrapper { height: 260px; border-radius: 16px; }
  .carousel-text { padding: 20px 16px 40px; }
  .carousel-text h3 { font-size: 1.2rem; margin-bottom: 6px; }
  .carousel-text p { font-size: 0.85rem; }
  .carousel-arrow { padding: 8px 10px; font-size: 18px; }
  .carousel-prev { left: 10px; }
  .carousel-next { right: 10px; }
  .carousel-controls { bottom: 15px; }
  .carousel-dot { width: 10px; height: 10px; }
  .carousel-dot.active { width: 28px; }
  .about-header { margin-bottom: 30px; }
  .about-header p { font-size: 0.95rem; }
  .about-container { gap: 20px; }
  .about-content-card { padding: 24px 16px; border-radius: 16px; }
  .about-content-card h3 { font-size: 1.3rem; margin-bottom: 16px; }
  .about-content-card h3 i { font-size: 1.2rem; }
  .about-content-card p { font-size: 0.9rem; margin-bottom: 12px; }
  .about-content-card a { margin-top: 20px; padding: 11px 24px; font-size: 0.9rem; }
  .contact-section { margin-top: 50px; margin-bottom: 30px; }
  .contact-card { padding: 32px 18px; border-radius: 16px; }
  .contact-card h3 { font-size: 1.5rem; gap: 8px; margin-bottom: 16px; }
  .contact-card h3 i { font-size: 1.4rem; }
  .contact-card p { font-size: 0.95rem; margin-bottom: 20px; }
  .contact-card a { padding: 11px 22px; font-size: 0.9rem; }
  .guest-badge { padding: 12px 12px; font-size: 0.85rem; }
  .guest-badge i { font-size: 1rem; }
  .message { padding: 12px 14px; font-size: 0.95em; }
}

@media (max-width: 360px) {
  .hero-content .cta-btn { padding: 12px 24px; }
  .carousel-wrapper { height: 220px; }
  .about-content-card h3 { font-size: 1.1rem; }
  .contact-card h3 { font-size: 1.3rem; }
  .contact-card p { font-size: 0.9rem; }
  .guest-badge { flex-direction: column; gap: 8px; }
  .product .img-wrap { height: 190px; }
  .coffee-moment-visual { width: 240px; }
}

/* Short landscape phones: keep the hero from eating the whole screen */
@media (max-height: 480px) and (orientation: landscape) {
  .hero { height: 360px; }
  .hero-content h1 { font-size: 1.8rem; }
  .hero-content p { margin-top: 10px; }
  .hero-content .cta-btn { margin-top: 14px; }
}
</style>

<main class="site-main">

   <section class="hero">
      <video id="heroVideo" autoplay loop playsinline muted style="width:100%;height:100%;object-fit:cover;display:block;">
         <source src="video/hoho.mp4" type="video/mp4">
         Your browser does not support the video tag.
      </video>
      <div class="hero-content">
         <h1>Six Origins Making Every Day Better</h1>
         <p>Experience quality coffee and apparel crafted with passion.</p>
         <a href="Item.php" class="cta-btn"><i class="fa-solid fa-bag-shopping"></i> Shop Now</a>
      </div>
   </section>

   <?php if ($is_guest): ?>
      <div class="guest-badge" role="status">
         <i class="fa-solid fa-user-secret"></i>
         <span><strong>Browsing as a Guest</strong> — You can shop and checkout without an account. <a href="login.php">Sign in or create an account</a> for faster checkout!</span>
      </div>
   <?php endif; ?>

   <?php if (!empty($message)): ?>
      <div class="message-list" aria-live="polite" aria-atomic="true">
         <?php foreach ($message as $msg): ?>
            <div class="message <?php echo strpos($msg, '🎉') !== false ? 'welcome' : ''; ?>">
               <?php echo htmlspecialchars($msg); ?>
            </div>
         <?php endforeach; ?>
      </div>
   <?php endif; ?>

   <section class="products-section" aria-labelledby="productsTitle">
      <div class="section-header">
         <div>
            <h2 id="productsTitle">☕ Latest Products</h2>
            <div>Discover our newest arrivals — choose your size and add to cart</div>
         </div>
         <div class="section-filters">
            <a href="Item.php"><i class="fa-solid fa-star"></i> All Products</a>
            <a href="Menu1P.php"><i class="fa-solid fa-mug-hot"></i> Coffee</a>
            <a href="Menu2P.php"><i class="fa-solid fa-cup"></i> Drinks</a>
            <a href="Menu3P.php"><i class="fa-solid fa-cookie"></i> Sweets</a>
         </div>
      </div>

      <div class="grid">
         <?php
            $prod_stmt = $conn->prepare("SELECT id, name, price, image, details, size_type FROM `products` ORDER BY id DESC LIMIT 8");
            $prod_stmt->execute();
            $select_products = $prod_stmt->get_result();

            if ($select_products->num_rows > 0) {
               while ($fetch_products = $select_products->fetch_assoc()) {
                  $product_id = (int)$fetch_products['id'];
                  $type = $fetch_products['size_type'] ?? 'cup';
                  $type_meta = $SIZE_TYPE_META[$type] ?? $SIZE_TYPE_META['cup'];

                  // ✅ Fetch ALL sizes (not just in-stock ones) so out-of-stock
                  // options can still be shown, disabled, with no numbers.
                  $sizes_stmt = $conn->prepare("SELECT size, stock FROM `product_sizes` WHERE product_id = ? ORDER BY id ASC");
                  $sizes_stmt->bind_param("i", $product_id);
                  $sizes_stmt->execute();
                  $sizes_result = $sizes_stmt->get_result();
                  $sizes = [];
                  $has_available_size = false;
                  while ($sz = $sizes_result->fetch_assoc()) {
                     $sizes[] = $sz;
                     if ((int)$sz['stock'] > 0) $has_available_size = true;
                  }
                  $sizes_stmt->close();
                  $product_out_of_stock = !$has_available_size;
                  $dis = $product_out_of_stock ? 'disabled' : '';

                  [$preference_groups, $extra_groups] = fetch_customizations($conn, $product_id);
         ?>
         <form action="" method="post" class="product<?php echo $product_out_of_stock ? ' out-of-stock' : ''; ?>" aria-labelledby="prod-<?php echo $product_id; ?>">
            <div class="badge-price">₱<?php echo htmlspecialchars($fetch_products['price']); ?></div>
            <?php if ($product_out_of_stock): ?>
               <div class="out-of-stock-ribbon"><i class="fa-solid fa-ban"></i> Out of Stock</div>
            <?php endif; ?>
            <div class="img-wrap">
               <img class="image" src="images/<?php echo htmlspecialchars($fetch_products['image']); ?>" alt="<?php echo htmlspecialchars($fetch_products['name']); ?>" loading="lazy">
            </div>
            <div id="prod-<?php echo $product_id; ?>" class="title"><?php echo htmlspecialchars($fetch_products['name']); ?></div>

            <span class="size-type-tag"><i class="fa-solid <?php echo $type_meta['icon']; ?>"></i> <?php echo htmlspecialchars($type_meta['label']); ?></span>

            <?php if (!empty($fetch_products['details'])): ?>
               <p class="desc"><?php echo htmlspecialchars($fetch_products['details']); ?></p>
            <?php endif; ?>

            <div>
               <label for="size-<?php echo $product_id; ?>"><i class="fa-solid <?php echo $type_meta['icon']; ?>"></i> <?php echo htmlspecialchars($type_meta['label']); ?></label>
               <select name="product_size" id="size-<?php echo $product_id; ?>" class="qty size-select" required <?php echo $dis; ?>>
                  <option value="" disabled selected>Select <?php echo htmlspecialchars($type_meta['label']); ?></option>
                  <?php foreach ($sizes as $size): $sz_out = ((int)$size['stock'] <= 0); ?>
                  <option value="<?php echo htmlspecialchars($size['size']); ?>" data-stock="<?php echo (int)$size['stock']; ?>" <?php echo $sz_out ? 'disabled' : ''; ?>>
                     <?php echo htmlspecialchars($size['size']); ?><?php echo $sz_out ? ' (Out of Stock)' : ''; ?>
                  </option>
                  <?php endforeach; ?>
               </select>
            </div>

            <?php if (!empty($preference_groups)): ?>
            <div class="customization-block">
               <?php foreach ($preference_groups as $grp): ?>
               <div class="pref-row">
                  <label><i class="fa-solid fa-sliders"></i> <?php echo htmlspecialchars($grp['title']); ?></label>
                  <select name="preferences[<?php echo (int)$grp['id']; ?>]" <?php echo $dis; ?>>
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

            <div class="meta">
               <div>
                  <input type="number" min="1" max="20" name="product_quantity" value="1" class="qty product-qty" aria-label="Quantity" <?php echo $dis; ?>>
               </div>
               <div>
                  <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                  <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
                  <button type="submit" name="add_to_cart" class="btn btn-primary" <?php echo $dis; ?>>
                     <i class="fa-solid fa-cart-plus"></i> <?php echo $product_out_of_stock ? 'Out of Stock' : 'Add'; ?>
                  </button>
               </div>
            </div>
         </form>
         <?php
               }
               $prod_stmt->close();
            } else {
               echo '<div class="empty-message"><i class="fa-solid fa-inbox" style="font-size: 2.5rem; color: var(--gray-brown); margin-bottom: 12px;"></i><div style="margin-top:12px; color: var(--gray-brown);">No products available yet. Check back soon!</div></div>';
            }
         ?>
      </div>
   </section>

   <!-- Coffee Moment -->
   <section class="coffee-moment" id="coffeeMoment" aria-label="A moment worth savoring">
      <div class="coffee-moment-inner">
         <div class="coffee-moment-visual">
            <span class="coffee-steam"></span>
            <span class="coffee-steam"></span>
            <span class="coffee-steam"></span>
            <span class="coffee-moment-sparkle"></span>
            <span class="coffee-moment-sparkle"></span>
            <span class="coffee-moment-sparkle"></span>
            <div class="coffee-moment-frame">
               <img class="coffee-moment-img" src="images/coffee-splash.png" alt="A splash of freshly brewed coffee" loading="lazy">
               <span class="coffee-moment-shine"></span>
            </div>
         </div>
         <div class="coffee-moment-text">
            <span class="coffee-moment-tag"><i class="fa-solid fa-mug-hot"></i> For the Coffee Lovers</span>
            <h2>Every Cup Starts with a Smile.</h2>
            <p>From the first pour to the final sip, every cup is made to feel warm, easy, and worth slowing down for.</p>
            <div class="CTA-wrap">
               <a href="Item.php" class="cta-btn"><i class="fa-solid fa-mug-saucer"></i> Grab Your Cup</a>
               <a href="Menu1P.php" class="cta-btn secondary-btn"><i class="fa-solid fa-book-open"></i> Explore Menu</a>
            </div>
         </div>
      </div>
   </section>

   <div class="cta-row">
      <a href="Item.php">Coffee</a>
      <a href="Item.php">Drinks</a>
      <a href="Item.php">Sweets</a>
   </div>

   <!-- About Section -->
   <section class="about-section">
      <div class="about-header">
         <h2>☕ About Six Origins</h2>
         <p>Discover the story behind our passion for quality coffee and premium apparel</p>
      </div>

      <div class="about-container">
         <div class="about-content-card">
            <h3><i class="fa-solid fa-heart"></i> Our Story</h3>
            <p>Six Origins Cafe isn't just a coffee shop—it's a dream built from the ground up. It started with a vision to create a cozy Japanese-inspired café where quality meets simplicity.</p>
            <p>At the heart of Six Origins is our commitment to excellence. We source the finest beans from six distinct origins around the world, expertly roast them, and brew each cup to perfection. From freshly brewed specialty drinks to rich cream-based blends, refreshing teas, and handcrafted pastries, everything is made to delight and connect.</p>
            <a href="About us.php"><i class="fa-solid fa-arrow-right"></i> Read More</a>
         </div>

         <div class="carousel-wrapper">
            <div class="carousel-container">
               <div class="carousel-slide active">
                  <img src="images/typica bg.png" alt="Six Origins Coffee Origins" loading="lazy">
                  <div class="carousel-text">
                     <h3>Our Coffee Origins</h3>
                     <p>Sourced from six distinct origins around the world</p>
                  </div>
               </div>
               <div class="carousel-slide">
                  <img src="images/typica bg.png" alt="Six Origins Roasting" loading="lazy">
                  <div class="carousel-text">
                     <h3>Expert Roasting</h3>
                     <p>Each batch carefully roasted to perfection</p>
                  </div>
               </div>
               <div class="carousel-slide">
                  <img src="images/typica bg.png" alt="Six Origins Brewing" loading="lazy">
                  <div class="carousel-text">
                     <h3>Artisan Brewing</h3>
                     <p>Brewed with precision and passion</p>
                  </div>
               </div>
               <div class="carousel-slide">
                  <img src="images/typica bg.png" alt="Six Origins Premium Apparel" loading="lazy">
                  <div class="carousel-text">
                     <h3>Premium Apparel</h3>
                     <p>Quality clothing crafted for our community</p>
                  </div>
               </div>
            </div>

            <button class="carousel-arrow carousel-prev" aria-label="Previous slide">
               <i class="fa-solid fa-chevron-left"></i>
            </button>
            <button class="carousel-arrow carousel-next" aria-label="Next slide">
               <i class="fa-solid fa-chevron-right"></i>
            </button>

            <div class="carousel-controls" role="tablist">
               <button class="carousel-dot active" role="tab" aria-label="Slide 1" aria-selected="true"></button>
               <button class="carousel-dot" role="tab" aria-label="Slide 2" aria-selected="false"></button>
               <button class="carousel-dot" role="tab" aria-label="Slide 3" aria-selected="false"></button>
               <button class="carousel-dot" role="tab" aria-label="Slide 4" aria-selected="false"></button>
            </div>
         </div>
      </div>
   </section>

</main>

<?php include 'chatbot.php'; ?>

<?php include 'footer.php'; ?>

<script src="Js/script1.js"></script>
<script>
// Carousel Functionality
(function() {
   let currentSlide = 0;
   const slides = document.querySelectorAll('.carousel-slide');
   const dots = document.querySelectorAll('.carousel-dot');
   const prevBtn = document.querySelector('.carousel-prev');
   const nextBtn = document.querySelector('.carousel-next');
   const totalSlides = slides.length;

   function showSlide(n) {
      slides[currentSlide].classList.remove('active');
      dots[currentSlide].classList.remove('active');
      dots[currentSlide].setAttribute('aria-selected', 'false');
      currentSlide = (n + totalSlides) % totalSlides;
      slides[currentSlide].classList.add('active');
      dots[currentSlide].classList.add('active');
      dots[currentSlide].setAttribute('aria-selected', 'true');
   }

   function nextSlide() { showSlide(currentSlide + 1); }
   function prevSlide() { showSlide(currentSlide - 1); }

   if (prevBtn) prevBtn.addEventListener('click', prevSlide);
   if (nextBtn) nextBtn.addEventListener('click', nextSlide);
   dots.forEach((dot, index) => { dot.addEventListener('click', () => showSlide(index)); });
   setInterval(nextSlide, 7000);
})();

// Gentle hero unmute on first interaction (non-blocking)
document.addEventListener('click', function () {
   var video = document.getElementById('heroVideo');
   if (video && video.muted) {
      video.muted = false;
      video.play().catch(err => console.log('Autoplay prevented:', err));
   }
}, { once: true });

document.addEventListener('touchstart', function () {
   var video = document.getElementById('heroVideo');
   if (video && video.muted) {
      video.muted = false;
      video.play().catch(err => console.log('Autoplay prevented:', err));
   }
}, { once: true });

// Coffee Moment — plays its float/steam/sparkle animation once it scrolls into view
(function () {
   var coffeeMoment = document.getElementById('coffeeMoment');
   if (!coffeeMoment) return;
   if (!('IntersectionObserver' in window)) { coffeeMoment.classList.add('in-view'); return; }
   var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
         if (entry.isIntersecting) { entry.target.classList.add('in-view'); observer.unobserve(entry.target); }
      });
   }, { threshold: 0.3 });
   observer.observe(coffeeMoment);
})();

// ✅ Stock-aware quantity capping + live price preview (no raw stock numbers shown to the customer)
(function () {
   document.querySelectorAll('form.product').forEach(function (form) {
      var sizeSelect = form.querySelector('.size-select');
      var qtyInput   = form.querySelector('.product-qty');
      var checkboxes = form.querySelectorAll('.extra-checkbox');
      var lineTotal  = form.querySelector('.line-total');
      var base       = lineTotal ? parseFloat(lineTotal.dataset.base || '0') : 0;

      function recalcTotal() {
         if (!lineTotal) return;
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