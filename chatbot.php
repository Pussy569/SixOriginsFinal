<?php
// =============== SESSION & CONFIG ===============
include 'config.php';

// ============== GET USER_ID PROPERLY ==============
$user_id = 0;
if (!empty($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
}

// =============== HELPERS ===============
// sanitizeInput() comes from config.php.
// NOTE: the front-end renders everything with textContent (never innerHTML),
// so replies are sent as plain text. Escaping here as well made characters
// like "&" show up as "&amp;" in the chat.

function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
    exit;
}

// Build a safe images/ URL (handles spaces like "typica bg.png")
function cb_img_path($file, $fallback = 'default.png') {
    $file = trim((string)$file);
    if ($file === '') $file = $fallback;
    return 'images/' . implode('/', array_map('rawurlencode', explode('/', $file)));
}

// Order products are stored as "item || item"; each item may carry
// [Preference: ...] / [Extras: ...] notes that we don't need in the chat.
function cb_order_items($raw) {
    $items = array_filter(array_map('trim', explode('||', (string)$raw)), 'strlen');
    $clean = [];
    foreach ($items as $item) {
        $clean[] = trim(preg_replace('/\s*\[(Preference|Extras):\s*.*?\]/i', '', $item));
    }
    return $clean;
}

function cb_default_topics() {
    return ['Show menu', 'Recommend something', 'Track my order'];
}

// Lowercase, drop apostrophes, turn every other symbol (quotes, ? ! . ,) into a
// space. Used on BOTH the customer's message and the admin's keywords so
// punctuation can never stop a match ("Hi!" == "hi", "located?" == "located").
function cb_normalize($s) {
    $s = mb_strtolower((string)$s);
    $s = preg_replace("/['’`]/u", '', $s);
    $s = preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $s);
    return trim(preg_replace('/\s+/u', ' ', $s));
}

// Remove wrapping quote marks from admin-written replies: "Hello!" -> Hello!
function cb_strip_quotes($s) {
    return trim(preg_replace('/^[\s"“”«»]+|[\s"“”«»]+$/u', '', (string)$s));
}

// Turn what the admin typed in the Keywords box into a clean list.
// Understands:  hi, hello, hey   |   "Hi", "Hello" or "Hey there!"   |   one per line
function cb_extract_keywords($raw) {
    $raw = (string)$raw;
    $pattern = '/["“”«»]([^"“”«»]+)["“”«»]/u';
    $parts = [];

    if (preg_match_all($pattern, $raw, $m) && !empty($m[1])) {
        $parts = $m[1];                                   // everything inside quotes
        $rest = preg_replace($pattern, ' , ', $raw);      // plus any unquoted leftovers
        foreach (preg_split('/[,;\n]+/u', $rest) as $r) $parts[] = $r;
    } else {
        $parts = preg_split('/[,;\n]+/u', $raw);
    }

    $out = [];
    foreach ($parts as $p) {
        $p = preg_replace('/^\s*(or|and)\s+/iu', '', $p);  // "or Hey there" -> "Hey there"
        $p = cb_normalize($p);
        if ($p !== '') $out[$p] = true;
    }
    return array_keys($out);
}

// Does $phrase appear in $message (both already normalized)?
// Short phrases (<=3 chars, e.g. "hi") must be a whole word so "hi" doesn't fire
// inside "this"; longer ones match from the start of a word so "hour" matches "hours".
function cb_phrase_in($message, $phrase) {
    if ($phrase === '') return false;
    $end = (mb_strlen($phrase) <= 3) ? '(?![\p{L}\p{N}])' : '';
    return (bool) preg_match('/(?<![\p{L}\p{N}])' . preg_quote($phrase, '/') . $end . '/u', $message);
}

// How strongly does one keyword match the message? 0 = no match.
// 1) the keyword appears as a phrase                       -> strong match
// 2) the keyword is a full sentence (3+ words, e.g. "How can I contact customer
//    support?") and the customer used most of its important words -> softer match
function cb_kb_match_strength($message, $kw) {
    if (cb_phrase_in($message, $kw)) {
        return 100 + min(mb_strlen($kw), 50);
    }

    $words = explode(' ', $kw);
    if (count($words) < 3) return 0;

    $stop = ['a','an','the','is','are','was','were','am','be','you','your','yours','i','me','my','we','our','us',
             'can','could','do','does','did','to','of','or','and','in','on','at','for','this','that','these',
             'those','it','its','with','please','about','tell','what','how','there','any','have','has','get'];
    $content = [];
    foreach ($words as $w) {
        if (!in_array($w, $stop, true)) $content[] = $w;
    }
    $n = count($content);
    if ($n < 2) return 0;

    $matched = 0;
    foreach ($content as $w) {
        if (cb_phrase_in($message, $w)) $matched++;
    }
    if ($matched >= max(2, (int)ceil($n * 0.5))) {
        return min(mb_strlen($kw), 50);
    }
    return 0;
}

// "Suggestion" entries: first line = message, following lines = tappable options.
function cb_parse_suggestion($text) {
    $lines = array_values(array_filter(array_map('cb_strip_quotes', preg_split('/\R/u', (string)$text)), 'strlen'));
    if (count($lines) <= 1) {
        return [$lines[0] ?? '', cb_default_topics()];
    }
    $bullet = '/^([-•*▪►→]|\d+[.)])\s*/u';
    $message = 'Here are some options:';
    if (!preg_match($bullet, $lines[0])) {
        $message = array_shift($lines);
    }
    $options = [];
    foreach ($lines as $l) {
        $l = trim(preg_replace($bullet, '', $l));
        if ($l !== '') $options[] = mb_substr($l, 0, 60);
    }
    if (empty($options)) $options = cb_default_topics();
    return [$message, array_slice($options, 0, 8)];
}

// ================= AJAX HANDLERS =================
if (isset($_POST['get_history'])) {
    if ($user_id == 0) {
        jsonResponse([]);
    }
    $history = [];
    $stmt = $conn->prepare("SELECT message, sender, type, payload FROM `chat_history` WHERE user_id = ? ORDER BY created_at ASC LIMIT 50");
    if (!$stmt) {
        jsonResponse(["error" => "Database error"]);
    }
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $res = $stmt->get_result();

    while ($row = $res->fetch_assoc()) {
        $history[] = [
            "message" => $row['message'] ?? '',
            "sender"  => $row['sender'] ?? '',
            "type"    => $row['type'] ?? 'text',
            "payload" => !empty($row['payload']) ? json_decode($row['payload'], true) : []
        ];
    }
    $stmt->close();
    jsonResponse($history);
}

if (isset($_POST['clear_history'])) {
    if ($user_id != 0) {
        $stmt = $conn->prepare("DELETE FROM `chat_history` WHERE user_id = ?");
        if ($stmt) {
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $stmt->close();
        }
    }
    $_SESSION['confusion_count'] = 0;
    jsonResponse(["status" => "success"]);
}

if (isset($_POST['get_response']) && isset($_POST['message'])) {
    $raw_msg = sanitizeInput($_POST['message']);
    $message = strtolower($raw_msg);

    if (trim($raw_msg) === '') {
        jsonResponse(["type" => "text", "message" => "Please type a message so I can help! ☕"]);
    }

    if (!isset($_SESSION['confusion_count'])) {
        $_SESSION['confusion_count'] = 0;
    }

    if ($user_id != 0) {
        $stmt = $conn->prepare("INSERT INTO `chat_history` (user_id, message, sender, type) VALUES (?, ?, ?, ?)");
        if ($stmt) {
            $sender = "user";
            $type = "text";
            $stmt->bind_param("isss", $user_id, $raw_msg, $sender, $type);
            $stmt->execute();
            $stmt->close();
        }
    }

    $response = generateResponse($message, $conn, $user_id);

    if ($user_id != 0) {
        $bot_msg = $response['message'] ?? '';
        $type = $response['type'] ?? 'text';
        $payload = json_encode($response, JSON_UNESCAPED_UNICODE);

        $stmt = $conn->prepare("INSERT INTO `chat_history` (user_id, message, sender, type, payload) VALUES (?, ?, ?, ?, ?)");
        if ($stmt) {
            $sender = "bot";
            $stmt->bind_param("issss", $user_id, $bot_msg, $sender, $type, $payload);
            $stmt->execute();
            $stmt->close();
        }
    }

    jsonResponse($response);
}

if (isset($_POST['get_recommendation'])) {
    $query = $conn->query("SELECT * FROM products ORDER BY RAND() LIMIT 1");
    if ($query && ($product = $query->fetch_assoc())) {
        jsonResponse(formatProduct($product, $conn));
    }
    jsonResponse(["type" => "text", "message" => "No recommendations available at the moment."]);
}

if (isset($_POST['get_menu'])) {
    $products = [];
    $query = $conn->query("SELECT * FROM `products` LIMIT 20");
    if ($query) {
        while ($product = $query->fetch_assoc()) {
            $products[] = formatProduct($product, $conn);
        }
    }
    if (empty($products)) {
        jsonResponse(["type" => "text", "message" => "Our menu is empty right now. Please check back soon! ☕"]);
    }
    jsonResponse([
        "type" => "menu",
        "products" => $products,
        "message" => "Here's our current menu! Swipe to browse."
    ]);
}

// ================= GET ORDERS HANDLER =================
if (isset($_POST['get_orders'])) {
    if ($user_id == 0) {
        jsonResponse(["type" => "text", "message" => "Please log in to track your orders! ☕"]);
    }

    $stmt = $conn->prepare("SELECT id, placed_on, payment_status, total_price, total_products FROM `orders` WHERE user_id = ? ORDER BY id DESC LIMIT 5");
    if (!$stmt) {
        jsonResponse(["error" => "Database error"]);
    }
    $uid_str = (string)$user_id;
    $stmt->bind_param("s", $uid_str);
    $stmt->execute();
    $order_query = $stmt->get_result();

    if ($order_query->num_rows === 0) {
        $stmt->close();
        jsonResponse(["type" => "text", "message" => "📭 You haven't placed any orders yet. Browse our menu! ☕"]);
    }

    $img_stmt = $conn->prepare("SELECT image FROM `products` WHERE name LIKE ? LIMIT 1");
    $orders = [];
    while ($order = $order_query->fetch_assoc()) {
        $items = cb_order_items($order['total_products']);
        $first_name = trim(preg_replace('/\s*\([^)]*\)/', '', $items[0] ?? ''));

        $image = 'images/default.png';
        if ($first_name !== '' && $img_stmt) {
            $like = '%' . $first_name . '%';
            $img_stmt->bind_param("s", $like);
            $img_stmt->execute();
            $img_row = $img_stmt->get_result()->fetch_assoc();
            if ($img_row && !empty($img_row['image'])) {
                $image = cb_img_path($img_row['image']);
            }
        }

        $orders[] = [
            "id"          => (int)$order['id'],
            "placed_on"   => (string)($order['placed_on'] ?? ''),
            "status"      => strtolower((string)($order['payment_status'] ?? 'pending')),
            "total_price" => (float)($order['total_price'] ?? 0),
            "items"       => array_slice($items, 0, 3),
            "more"        => max(0, count($items) - 3),
            "image"       => $image
        ];
    }
    if ($img_stmt) $img_stmt->close();
    $stmt->close();

    jsonResponse(["type" => "orders", "orders" => $orders, "message" => "Your recent orders"]);
}

// Any other chatbot request that slipped through gets a clean JSON answer
// (instead of the whole widget HTML, which broke res.json() on the page).
foreach (['get_history', 'clear_history', 'get_response', 'get_menu', 'get_orders', 'get_recommendation'] as $cb_key) {
    if (isset($_POST[$cb_key])) {
        jsonResponse(["error" => "Unhandled request"]);
    }
}

// ================= RESPONSE FUNCTION =================
function generateResponse($message, $conn, $user_id) {
    $_SESSION['confusion_count'] = $_SESSION['confusion_count'] ?? 0;

    // ===== 1. CHECK KNOWLEDGE BASE FIRST =====
    $knowledge_match = searchKnowledgeBase($message, $conn);
    if ($knowledge_match) {
        $_SESSION['confusion_count'] = 0;
        return $knowledge_match;
    }

    // ===== 2. PATTERN MATCHING INTENTS =====
    if (preg_match('/\b(menu|product|products|coffee|drink|drinks|offer|list)\b/i', $message)) {
        $_SESSION['confusion_count'] = 0;
        return ["type" => "show_menu", "message" => "Here's our current menu!"];
    }

    if (preg_match('/\b(order|orders|status|track|tracking|package)\b/i', $message)) {
        $_SESSION['confusion_count'] = 0;

        if (!$user_id || $user_id == 0) {
            return ["type" => "text", "message" => "Please log in to track your orders! ☕"];
        }

        return ["type" => "get_orders", "message" => "Fetching your orders..."];
    }

    if (preg_match('/\b(recommend|recommendation|suggest|best)\b/i', $message)) {
        $_SESSION['confusion_count'] = 0;
        $query = $conn->query("SELECT * FROM products ORDER BY RAND() LIMIT 1");
        if ($query && ($product = $query->fetch_assoc())) {
            return formatProduct($product, $conn);
        }
    }

    // ===== 3. FALLBACK =====
    $_SESSION['confusion_count']++;
    if ($_SESSION['confusion_count'] >= 2) {
        return [
            "type" => "options",
            "message" => "Sorry for the confusion. Here are the main things I can help with:",
            "options" => cb_default_topics()
        ];
    }
    return ["type" => "text", "message" => "I'm not quite sure about that. Try asking about our menu, hours or orders! ☕"];
}

// ===== SEARCH KNOWLEDGE BASE =====
// Picks the best match across ALL active entries: highest priority wins,
// and for equal priority the longest matching keyword wins.
function searchKnowledgeBase($message, $conn) {
    $kb_query = $conn->query("SELECT keywords, response, response_type, priority FROM chatbot_knowledge WHERE is_active = 1 LIMIT 500");

    if (!$kb_query) {
        return null; // Table doesn't exist yet, that's OK
    }

    $message = cb_normalize($message);
    if ($message === '') return null;

    $best = null;
    $best_score = -1;

    while ($entry = $kb_query->fetch_assoc()) {
        foreach (cb_extract_keywords($entry['keywords'] ?? '') as $keyword) {
            $strength = cb_kb_match_strength($message, $keyword);
            if ($strength <= 0) continue;

            // priority decides first, match strength breaks ties
            $score = ((int)$entry['priority']) * 1000 + $strength;
            if ($score > $best_score) {
                $best_score = $score;
                $best = $entry;
            }
        }
    }

    if (!$best) return null;

    $response = (string)($best['response'] ?? '');
    if (($best['response_type'] ?? 'text') === 'suggestion') {
        [$msg, $options] = cb_parse_suggestion($response);
        return ["type" => "options", "message" => $msg, "options" => $options];
    }
    return ["type" => "text", "message" => cb_strip_quotes($response)];
}

function formatProduct($product, $conn) {
    $p_id = (int)$product['id'];
    $sizes = [];
    $any_available = false;

    $stmt = $conn->prepare("SELECT size, stock FROM product_sizes WHERE product_id = ? ORDER BY id ASC");
    if ($stmt) {
        $stmt->bind_param("i", $p_id);
        $stmt->execute();
        $sizes_query = $stmt->get_result();

        while ($row = $sizes_query->fetch_assoc()) {
            // Exact stock numbers are never exposed to customers (same as index.php)
            $ok = ((int)($row['stock'] ?? 0)) > 0;
            if ($ok) $any_available = true;
            $sizes[] = ["size" => (string)($row['size'] ?? ''), "available" => $ok];
        }
        $stmt->close();
    }

    return [
        "type"      => "product",
        "id"        => $p_id,
        "name"      => (string)($product['name'] ?? ''),
        "image"     => cb_img_path($product['image'] ?? ''),
        "price"     => (float)($product['price'] ?? 0),
        "available" => $any_available,
        "sizes"     => $sizes
    ];
}
?>

<!-- ===== Six Origins Chatbot ===== -->
<div id="chatbot-root">
  <button id="chatbot-launcher" type="button" title="Chat with us" aria-label="Open chat" aria-expanded="false" aria-controls="chatbot-box">
    <img src="images/logos.png" alt="Six Origins Logo" />
    <span class="cb-launch-tip" id="cb-tip">Hi!</span>
  </button>

  <section id="chatbot-box" role="dialog" aria-label="Six Origins chat">
    <header class="cb-header">
      <div class="cb-title">
        <span class="cb-brand"><img src="images/logos.png" alt="" /></span>
        <span class="cb-title-text">
          <span class="cb-name">Six Origins</span>
          <span class="cb-status"><span class="cb-live-dot"></span> Online</span>
        </span>
      </div>
      <div class="cb-head-actions">
        <button type="button" class="cb-icon-btn" id="cb-clear" title="Clear chat" aria-label="Clear chat">
          <i class="fa-solid fa-rotate-right"></i>
        </button>
        <a href="cart.php" class="cb-icon-btn" title="View cart" aria-label="View cart">
          <i class="fa-solid fa-cart-shopping"></i>
        </a>
        <button type="button" class="cb-icon-btn" id="cb-close" title="Close chat" aria-label="Close chat">
          <i class="fa-solid fa-xmark"></i>
        </button>
      </div>
    </header>

    <div id="cb-body">
      <div class="cb-quick-bar">
        <button type="button" class="cb-quick-btn" id="cb-q-menu"><i class="fa-solid fa-list"></i> Menu</button>
        <button type="button" class="cb-quick-btn hot" id="cb-q-rec"><i class="fa-solid fa-mug-hot"></i> Recommend</button>
        <button type="button" class="cb-quick-btn" id="cb-q-orders"><i class="fa-solid fa-box"></i> Orders</button>
      </div>

      <main id="cb-messages" aria-live="polite"></main>

      <form class="cb-input-bar" id="cb-form" autocomplete="off">
        <input type="text" id="cb-input" maxlength="160" placeholder="Type a message..." aria-label="Type a message" enterkeyhint="send" />
        <button type="submit" id="cb-send-btn" aria-label="Send" disabled>
          <i class="fa-solid fa-paper-plane"></i>
        </button>
      </form>
    </div>
  </section>
</div>

<style>
#chatbot-root {
  --cb-red: #C6453E;
  --cb-red-deep: #B83A34;
  --cb-brown: #5E1F13;
  --cb-gray: #664C47;
  --cb-cream: #FFF2E0;
  --cb-line: #F0E6D8;
  --cb-green: #2D5A3D;
  --cb-ease: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);

  position: fixed;
  right: max(22px, env(safe-area-inset-right));
  bottom: max(22px, env(safe-area-inset-bottom));
  z-index: 5000;
  font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
}

#chatbot-root *, #chatbot-root *::before, #chatbot-root *::after {
  box-sizing: border-box;
  font-family: inherit;
}

#chatbot-root :focus-visible { outline: 3px solid rgba(198, 69, 62, 0.45); outline-offset: 2px; }

/* ---------- Launcher ---------- */
#chatbot-launcher {
  width: 62px;
  height: 62px;
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  border-radius: 50%;
  box-shadow: 0 8px 24px rgba(94, 31, 19, 0.28);
  border: none;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  position: relative;
  padding: 0;
  transition: var(--cb-ease);
  -webkit-tap-highlight-color: transparent;
}

#chatbot-launcher img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

@media (hover: hover) {
  #chatbot-launcher:hover { box-shadow: 0 12px 36px rgba(94, 31, 19, 0.35); transform: scale(1.06); }
}

#chatbot-launcher:active { transform: scale(0.95); }

.cb-launch-tip {
  position: absolute;
  right: -4px;
  bottom: -4px;
  background: var(--cb-cream);
  color: var(--cb-brown);
  border-radius: 13px 0 13px 13px;
  font-size: 11.5px;
  font-weight: 800;
  padding: 3px 10px 2px 8px;
  box-shadow: 0 4px 12px rgba(94, 31, 19, 0.18);
}

.cb-launch-tip.gone { display: none; }

/* ---------- Panel ---------- */
#chatbot-box {
  position: absolute;
  right: 0;
  bottom: 78px;
  width: 372px;
  max-width: calc(100vw - 20px);
  display: none;
  flex-direction: column;
  background: #FFFBF7;
  border-radius: 18px;
  box-shadow: 0 16px 48px rgba(94, 31, 19, 0.22);
  border: 1.5px solid var(--cb-line);
  overflow: hidden;
}

#chatbot-box.open { display: flex; animation: cbOpen 0.25s ease-out; }

@keyframes cbOpen {
  from { opacity: 0; transform: translateY(16px) scale(0.96); }
  to   { opacity: 1; transform: none; }
}

.cb-header {
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  color: #fff;
  padding: 12px 12px 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-shrink: 0;
}

.cb-title { display: flex; align-items: center; gap: 12px; min-width: 0; }

.cb-brand {
  width: 40px;
  height: 40px;
  border-radius: 50%;
  background: #fff;
  border: 2px solid rgba(255, 255, 255, 0.6);
  overflow: hidden;
  flex-shrink: 0;
}

.cb-brand img { width: 100%; height: 100%; object-fit: cover; display: block; }

.cb-title-text { display: flex; flex-direction: column; min-width: 0; line-height: 1.2; }
.cb-name { font-size: 16px; font-weight: 900; letter-spacing: -0.2px; }

.cb-status { display: inline-flex; align-items: center; gap: 6px; font-size: 12px; font-weight: 600; opacity: 0.92; margin-top: 2px; }

.cb-live-dot {
  width: 8px;
  height: 8px;
  background: #4CAF50;
  border-radius: 50%;
  box-shadow: 0 0 8px rgba(76, 175, 80, 0.7);
  animation: cbPulse 2s ease-in-out infinite;
}

@keyframes cbPulse { 0%, 100% { opacity: 1; } 50% { opacity: 0.45; } }

.cb-head-actions { display: flex; align-items: center; gap: 2px; flex-shrink: 0; }

.cb-icon-btn {
  background: none;
  border: none;
  color: #fff;
  font-size: 1.05rem;
  width: 40px;
  height: 40px;
  cursor: pointer;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  text-decoration: none;
  transition: var(--cb-ease);
  -webkit-tap-highlight-color: transparent;
}

.cb-icon-btn:hover { background: rgba(255, 255, 255, 0.18); }
.cb-icon-btn:active { transform: scale(0.92); }

#cb-body {
  background: linear-gradient(135deg, #FFFAF5 0%, var(--cb-cream) 100%);
  display: flex;
  flex-direction: column;
  height: 520px;
  height: min(520px, calc(100vh - 150px));
  height: min(520px, calc(100dvh - 150px));
  min-height: 0;
}

/* ---------- Quick bar ---------- */
.cb-quick-bar {
  display: flex;
  gap: 8px;
  padding: 10px 12px;
  background: #fff;
  border-bottom: 1.5px solid var(--cb-line);
  overflow-x: auto;
  flex-shrink: 0;
  scrollbar-width: none;
}

.cb-quick-bar::-webkit-scrollbar { display: none; }

.cb-quick-btn {
  flex: 1 0 auto;
  min-height: 40px;
  padding: 0 14px;
  border-radius: 999px;
  border: 1.5px solid var(--cb-line);
  background: #FFFAF5;
  color: var(--cb-brown);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  white-space: nowrap;
  transition: var(--cb-ease);
  -webkit-tap-highlight-color: transparent;
}

.cb-quick-btn i { color: var(--cb-red); }
.cb-quick-btn:active { transform: scale(0.96); }

.cb-quick-btn.hot {
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  border-color: transparent;
  color: #fff;
  box-shadow: 0 4px 12px rgba(198, 69, 62, 0.25);
}

.cb-quick-btn.hot i { color: #fff; }

@media (hover: hover) {
  .cb-quick-btn:not(.hot):hover { background: var(--cb-cream); border-color: var(--cb-red); }
}

/* ---------- Messages ---------- */
#cb-messages {
  flex: 1;
  min-height: 0;
  padding: 14px 12px 8px;
  overflow-y: auto;
  overscroll-behavior: contain;
  -webkit-overflow-scrolling: touch;
  display: flex;
  flex-direction: column;
  gap: 12px;
}

#cb-messages::-webkit-scrollbar { width: 6px; }
#cb-messages::-webkit-scrollbar-thumb { background: #D4A574; border-radius: 3px; }

.cb-msg-row {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  max-width: 92%;
  animation: cbMsg 0.25s ease-out;
}

@keyframes cbMsg { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }

.cb-msg-row.user { align-self: flex-end; flex-direction: row-reverse; }
.cb-msg-row.bot  { align-self: flex-start; }

.cb-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 14px;
  flex-shrink: 0;
  margin-bottom: 2px;
  color: #fff;
}

.cb-avatar.bot  { background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%); }
.cb-avatar.user { background: var(--cb-brown); border: 2px solid var(--cb-red); }

.cb-msg-bubble {
  background: #fff;
  font-size: 14px;
  line-height: 1.5;
  color: var(--cb-brown);
  border-radius: 14px 14px 14px 4px;
  padding: 11px 15px;
  min-width: 0;
  overflow-wrap: anywhere;
  white-space: pre-line; /* keeps line breaks from the admin's knowledge-base replies */
  box-shadow: 0 2px 12px rgba(94, 31, 19, 0.08);
  border: 1.5px solid var(--cb-line);
  font-weight: 500;
}

.cb-msg-bubble a { color: var(--cb-red); font-weight: 700; text-decoration: underline; }

.cb-msg-row.user .cb-msg-bubble {
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  color: #fff;
  border-radius: 14px 14px 4px 14px;
  border: none;
  box-shadow: 0 2px 12px rgba(198, 69, 62, 0.22);
}

.cb-msg-bubble .cb-msg-main { font-weight: 600; }
.cb-msg-bubble small { display: block; margin-top: 4px; font-size: 12px; color: var(--cb-gray); opacity: 0.85; }

/* typing dots */
.cb-typing { display: inline-flex; gap: 4px; padding: 3px 0; }
.cb-typing span { width: 7px; height: 7px; border-radius: 50%; background: var(--cb-gray); animation: cbBlink 1.2s infinite; }
.cb-typing span:nth-child(2) { animation-delay: 0.2s; }
.cb-typing span:nth-child(3) { animation-delay: 0.4s; }
@keyframes cbBlink { 0%, 100% { opacity: 0.3; } 50% { opacity: 1; } }

/* tappable suggestion chips */
.cb-chips { display: flex; flex-wrap: wrap; gap: 8px; margin: -4px 0 0 40px; max-width: calc(100% - 40px); animation: cbMsg 0.25s ease-out; }

.cb-chip {
  min-height: 38px;
  padding: 0 14px;
  border-radius: 999px;
  border: 1.5px solid var(--cb-red);
  background: #fff;
  color: var(--cb-red);
  font-size: 13px;
  font-weight: 700;
  cursor: pointer;
  text-align: left;
  transition: var(--cb-ease);
  -webkit-tap-highlight-color: transparent;
}

.cb-chip:active { background: var(--cb-red); color: #fff; }
@media (hover: hover) { .cb-chip:hover { background: var(--cb-red); color: #fff; } }

/* ---------- Product cards ---------- */
.cb-carousel {
  align-self: stretch;
  min-width: 0;
  display: flex;
  gap: 10px;
  overflow-x: auto;
  scroll-snap-type: x mandatory;
  padding: 2px 2px 8px;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: thin;
  scrollbar-color: #D4A574 transparent;
}

.cb-product-card {
  background: #fff;
  border: 1.5px solid var(--cb-line);
  border-radius: 14px;
  padding: 10px;
  display: flex;
  flex-direction: column;
  gap: 6px;
  box-shadow: 0 3px 12px rgba(94, 31, 19, 0.08);
  scroll-snap-align: start;
  flex: 0 0 156px;
  min-width: 0;
}

.cb-product-card.solo { flex: none; align-self: stretch; width: auto; max-width: 100%; }

.cb-product-card img {
  width: 100%;
  aspect-ratio: 1 / 1;
  object-fit: cover;
  border-radius: 10px;
  background: var(--cb-cream);
  display: block;
}

.cb-product-card.solo img { aspect-ratio: 16 / 10; }

.cb-product-name { font-size: 13.5px; font-weight: 800; color: var(--cb-brown); line-height: 1.3; overflow-wrap: anywhere; }
.cb-product-price { font-size: 15px; font-weight: 900; color: var(--cb-red); }

.cb-avail { display: inline-flex; align-items: center; gap: 6px; font-size: 11.5px; font-weight: 700; color: var(--cb-green); }
.cb-avail::before { content: ""; width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
.cb-avail.out { color: var(--cb-gray); }

.cb-sizes-list { display: flex; flex-wrap: wrap; gap: 6px; }

.cb-size-pill {
  padding: 4px 10px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  background: rgba(45, 90, 61, 0.1);
  color: var(--cb-green);
  border: 1px solid rgba(45, 90, 61, 0.25);
}

.cb-size-pill.out { background: rgba(102, 76, 71, 0.08); color: var(--cb-gray); border-color: rgba(102, 76, 71, 0.2); text-decoration: line-through; }

.cb-order-now {
  margin-top: auto;
  min-height: 38px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  border-radius: 10px;
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  color: #fff;
  font-size: 12.5px;
  font-weight: 800;
  text-decoration: none;
  box-shadow: 0 2px 8px rgba(198, 69, 62, 0.25);
}

/* ---------- Order cards ---------- */
.cb-orders-container { align-self: stretch; display: flex; flex-direction: column; gap: 10px; min-width: 0; }

.cb-order-card {
  background: #fff;
  border: 1.5px solid var(--cb-line);
  border-left: 5px solid var(--cb-red);
  border-radius: 12px;
  box-shadow: 0 3px 14px rgba(94, 31, 19, 0.08);
  overflow: hidden;
}

.cb-order-top { display: flex; gap: 10px; padding: 10px 12px; align-items: center; border-bottom: 1.5px solid var(--cb-line); }

.cb-order-img { width: 48px; height: 48px; border-radius: 8px; object-fit: cover; flex-shrink: 0; background: var(--cb-cream); }

.cb-order-info { flex: 1; min-width: 0; }
.cb-order-id { font-size: 13px; font-weight: 900; color: var(--cb-red); }
.cb-order-date { font-size: 11.5px; color: var(--cb-gray); font-weight: 600; margin-top: 2px; }

.cb-order-items { padding: 8px 12px 0; list-style: none; font-size: 12.5px; color: var(--cb-gray); font-weight: 600; }
.cb-order-items li { padding: 2px 0; overflow-wrap: anywhere; }

.cb-order-foot { display: flex; align-items: center; justify-content: space-between; gap: 8px; padding: 10px 12px; flex-wrap: wrap; }
.cb-order-total { font-size: 15px; font-weight: 900; color: var(--cb-red); }

.cb-status-badge {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 5px 11px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 800;
  text-transform: uppercase;
  letter-spacing: 0.3px;
  border: 1px solid;
  white-space: nowrap;
}

.cb-st-pending   { background: rgba(198, 69, 62, 0.1);   color: var(--cb-red);  border-color: rgba(198, 69, 62, 0.3); }
.cb-st-accepted  { background: rgba(212, 165, 116, 0.15); color: #704214;       border-color: rgba(212, 165, 116, 0.4); }
.cb-st-preparing { background: rgba(3, 105, 161, 0.1);   color: #0369a1;        border-color: rgba(3, 105, 161, 0.25); }
.cb-st-done      { background: rgba(34, 197, 94, 0.12);  color: #16A34A;        border-color: rgba(34, 197, 94, 0.3); }
.cb-st-out       { background: rgba(180, 83, 9, 0.1);    color: #b45309;        border-color: rgba(180, 83, 9, 0.25); }
.cb-st-completed { background: rgba(45, 90, 61, 0.1);    color: var(--cb-green); border-color: rgba(45, 90, 61, 0.3); }
.cb-st-cancelled { background: rgba(217, 126, 106, 0.12); color: #C25A52;       border-color: rgba(217, 126, 106, 0.35); }

.cb-view-all {
  align-self: flex-start;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 38px;
  padding: 0 14px;
  border-radius: 10px;
  border: 1.5px solid var(--cb-red);
  color: var(--cb-red);
  font-size: 12.5px;
  font-weight: 800;
  text-decoration: none;
  background: #fff;
}

/* ---------- Composer ---------- */
.cb-input-bar {
  display: flex;
  gap: 10px;
  padding: 11px 12px calc(12px + env(safe-area-inset-bottom, 0px));
  background: #fff;
  border-top: 1.5px solid var(--cb-line);
  flex-shrink: 0;
}

#cb-input {
  flex: 1;
  min-width: 0;
  height: 46px;
  background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
  border: 1.5px solid var(--cb-line);
  border-radius: 23px;
  font-size: 16px; /* stops iOS zooming in on focus */
  padding: 0 18px;
  outline: none;
  transition: var(--cb-ease);
  color: var(--cb-brown);
  font-weight: 500;
}

#cb-input::placeholder { color: var(--cb-gray); }
#cb-input:focus { border-color: var(--cb-red); background: #fff; box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12); }

#cb-send-btn {
  width: 46px;
  height: 46px;
  border-radius: 50%;
  border: none;
  background: linear-gradient(135deg, var(--cb-red) 0%, var(--cb-red-deep) 100%);
  color: #fff;
  font-size: 17px;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  box-shadow: 0 2px 12px rgba(198, 69, 62, 0.28);
  transition: var(--cb-ease);
  -webkit-tap-highlight-color: transparent;
}

#cb-send-btn:disabled { opacity: 0.45; cursor: not-allowed; box-shadow: none; }
#cb-send-btn:not(:disabled):active { transform: scale(0.93); }

/* ---------- Phones: chat becomes a full-screen sheet ---------- */
@media (max-width: 510px) {
  #chatbot-root { right: max(14px, env(safe-area-inset-right)); bottom: max(16px, env(safe-area-inset-bottom)); }

  #chatbot-launcher { width: 56px; height: 56px; }

  #chatbot-root.cb-is-open #chatbot-launcher { display: none; }

  #chatbot-box {
    position: fixed;
    top: var(--cb-top, 0px);
    left: 0;
    right: 0;
    bottom: auto;
    width: auto;
    max-width: none;
    height: 100vh;
    height: var(--cb-vh, 100dvh);
    border-radius: 0;
    border: 0;
    box-shadow: none;
  }

  .cb-header { padding-top: calc(12px + env(safe-area-inset-top, 0px)); }

  #cb-body { height: auto; flex: 1; min-height: 0; }

  .cb-msg-row { max-width: 94%; }
}

html.cb-lock, html.cb-lock body { overflow: hidden !important; }

@media (prefers-reduced-motion: reduce) {
  #chatbot-root *, #chatbot-root *::before, #chatbot-root *::after { animation: none !important; transition: none !important; }
}
</style>

<script>
(function () {
  var ENDPOINT = 'chatbot.php';
  var SHOP_URL = 'Item.php';
  var ORDERS_URL = 'orders.php';

  var root = document.getElementById('chatbot-root');
  var box = document.getElementById('chatbot-box');
  var launcher = document.getElementById('chatbot-launcher');
  var tip = document.getElementById('cb-tip');
  var msgs = document.getElementById('cb-messages');
  var form = document.getElementById('cb-form');
  var input = document.getElementById('cb-input');
  var sendBtn = document.getElementById('cb-send-btn');

  var pending = false;
  var typingEl = null;

  var STATUS = {
    'pending':          ['Pending', 'fa-clock', 'pending'],
    'accepted':         ['Accepted', 'fa-thumbs-up', 'accepted'],
    'preparing':        ['Preparing', 'fa-hourglass-half', 'preparing'],
    'done preparing':   ['Ready', 'fa-check-double', 'done'],
    'out for delivery': ['Out for delivery', 'fa-truck', 'out'],
    'completed':        ['Completed', 'fa-circle-check', 'completed'],
    'cancelled':        ['Cancelled', 'fa-ban', 'cancelled']
  };

  function isMobile() { return window.matchMedia('(max-width: 510px)').matches; }

  /* ---------- helpers ---------- */
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) n.className = cls;
    if (text !== undefined) n.textContent = text;
    return n;
  }

  function icon(cls) { var i = document.createElement('i'); i.className = 'fa-solid ' + cls; return i; }

  function toBottom() { requestAnimationFrame(function () { msgs.scrollTop = msgs.scrollHeight; }); }

  function post(data) {
    var p = new URLSearchParams();
    Object.keys(data).forEach(function (k) { p.append(k, data[k]); });
    return fetch(ENDPOINT, { method: 'POST', body: p, credentials: 'same-origin' }).then(function (r) {
      if (!r.ok) throw new Error('HTTP ' + r.status);
      return r.json();
    });
  }

  // Plain text with clickable http(s) links (built with DOM nodes, never innerHTML)
  function fillText(node, text) {
    var re = /(https?:\/\/[^\s<]+[^\s<.,;:!?)\]'"])/g, last = 0, m;
    text = String(text || '');
    while ((m = re.exec(text))) {
      if (m.index > last) node.appendChild(document.createTextNode(text.slice(last, m.index)));
      var a = el('a', null, m[0]);
      a.href = m[0]; a.target = '_blank'; a.rel = 'noopener noreferrer';
      node.appendChild(a);
      last = m.index + m[0].length;
    }
    if (last < text.length) node.appendChild(document.createTextNode(text.slice(last)));
  }

  function money(n) { return '₱' + parseFloat(n || 0).toFixed(2); }

  /* ---------- messages ---------- */
  function addMessage(text, who, sub) {
    var row = el('div', 'cb-msg-row ' + who);
    var av = el('div', 'cb-avatar ' + who);
    av.appendChild(icon(who === 'user' ? 'fa-user' : 'fa-mug-hot'));
    var bubble = el('div', 'cb-msg-bubble');
    var main = el('div', 'cb-msg-main');
    fillText(main, text);
    bubble.appendChild(main);
    if (sub) bubble.appendChild(el('small', null, sub));
    row.appendChild(av);
    row.appendChild(bubble);
    msgs.appendChild(row);
    toBottom();
    return row;
  }

  function addChips(options) {
    if (!options || !options.length) return;
    var wrap = el('div', 'cb-chips');
    options.forEach(function (opt) {
      var b = el('button', 'cb-chip', opt);
      b.type = 'button';
      b.addEventListener('click', function () { wrap.remove(); send(opt); });
      wrap.appendChild(b);
    });
    msgs.appendChild(wrap);
    toBottom();
  }

  function showTyping() {
    hideTyping();
    typingEl = el('div', 'cb-msg-row bot');
    var av = el('div', 'cb-avatar bot'); av.appendChild(icon('fa-mug-hot'));
    var bubble = el('div', 'cb-msg-bubble');
    var dots = el('span', 'cb-typing');
    dots.appendChild(el('span')); dots.appendChild(el('span')); dots.appendChild(el('span'));
    bubble.appendChild(dots);
    typingEl.appendChild(av); typingEl.appendChild(bubble);
    msgs.appendChild(typingEl);
    toBottom();
  }

  function hideTyping() { if (typingEl) { typingEl.remove(); typingEl = null; } }

  function welcome(title, sub) {
    msgs.innerHTML = '';
    addMessage(title || 'Welcome to Six Origins! ☕', 'bot', sub || 'How can I help you today?');
  }

  /* ---------- product cards ---------- */
  function productCard(p, solo) {
    var card = el('div', 'cb-product-card' + (solo ? ' solo' : ''));
    var img = el('img'); img.src = p.image; img.alt = p.name; img.loading = 'lazy';
    img.onerror = function () { this.onerror = null; this.src = 'images/default.png'; };
    card.appendChild(img);
    card.appendChild(el('div', 'cb-product-name', p.name));
    card.appendChild(el('div', 'cb-product-price', money(p.price)));

    if (solo && p.sizes && p.sizes.length) {
      var list = el('div', 'cb-sizes-list');
      p.sizes.forEach(function (s) { list.appendChild(el('span', 'cb-size-pill' + (s.available ? '' : ' out'), s.size)); });
      card.appendChild(list);
    }

    card.appendChild(el('div', 'cb-avail' + (p.available ? '' : ' out'), p.available ? 'Available' : 'Out of stock'));

    var link = el('a', 'cb-order-now');
    link.href = SHOP_URL;
    link.appendChild(icon('fa-bag-shopping'));
    link.appendChild(document.createTextNode(' Order now'));
    card.appendChild(link);
    return card;
  }

  function showProduct(p) { msgs.appendChild(productCard(p, true)); toBottom(); }

  function showMenuCards(products) {
    var car = el('div', 'cb-carousel');
    products.forEach(function (p) { car.appendChild(productCard(p, false)); });
    msgs.appendChild(car);
    toBottom();
  }

  /* ---------- orders ---------- */
  function showOrders(data) {
    if (!data || !data.orders) return;
    var wrap = el('div', 'cb-orders-container');

    data.orders.forEach(function (o) {
      var st = STATUS[o.status] || [o.status ? o.status.charAt(0).toUpperCase() + o.status.slice(1) : 'Pending', 'fa-clock', 'pending'];
      var card = el('div', 'cb-order-card');

      var top = el('div', 'cb-order-top');
      var img = el('img', 'cb-order-img'); img.src = o.image; img.alt = '';
      img.onerror = function () { this.onerror = null; this.src = 'images/default.png'; };
      var info = el('div', 'cb-order-info');
      info.appendChild(el('div', 'cb-order-id', 'Order #' + o.id));
      info.appendChild(el('div', 'cb-order-date', o.placed_on));
      top.appendChild(img); top.appendChild(info);
      card.appendChild(top);

      var ul = el('ul', 'cb-order-items');
      (o.items || []).forEach(function (it) { ul.appendChild(el('li', null, '• ' + it)); });
      if (o.more > 0) ul.appendChild(el('li', null, '+ ' + o.more + ' more item' + (o.more > 1 ? 's' : '')));
      card.appendChild(ul);

      var foot = el('div', 'cb-order-foot');
      foot.appendChild(el('div', 'cb-order-total', money(o.total_price)));
      var badge = el('span', 'cb-status-badge cb-st-' + st[2]);
      badge.appendChild(icon(st[1]));
      badge.appendChild(document.createTextNode(' ' + st[0]));
      foot.appendChild(badge);
      card.appendChild(foot);

      wrap.appendChild(card);
    });

    var all = el('a', 'cb-view-all');
    all.href = ORDERS_URL;
    all.appendChild(document.createTextNode('View all orders '));
    all.appendChild(icon('fa-arrow-right'));
    wrap.appendChild(all);

    msgs.appendChild(wrap);
    toBottom();
  }

  /* ---------- actions ---------- */
  function handleReply(d) {
    if (!d || d.error) { addMessage('Sorry, something went wrong. Please try again.', 'bot'); return; }
    switch (d.type) {
      case 'options': addMessage(d.message, 'bot'); addChips(d.options); break;
      case 'product': showProduct(d); break;
      case 'show_menu': loadMenu(); break;
      case 'get_orders': loadOrders(); break;
      case 'menu': addMessage(d.message, 'bot'); showMenuCards(d.products || []); break;
      case 'orders': showOrders(d); break;
      default: addMessage(d.message || '', 'bot');
    }
  }

  function run(promise) {
    pending = true;
    showTyping();
    return promise
      .then(function (d) { hideTyping(); handleReply(d); })
      .catch(function (err) {
        console.error('Chatbot error:', err);
        hideTyping();
        addMessage('Sorry, I couldn\'t reach the server. Please try again.', 'bot');
      })
      .then(function () { pending = false; });
  }

  function send(text) {
    text = String(text || '').trim();
    if (!text || pending) return;
    addMessage(text, 'user');
    input.value = '';
    updateSend();
    run(post({ get_response: 1, message: text }));
  }

  function loadMenu() { hideTyping(); run(post({ get_menu: 1 })); }
  function loadOrders() { hideTyping(); run(post({ get_orders: 1 })); }
  function loadRecommendation() { if (!pending) run(post({ get_recommendation: 1 })); }

  function clearChat() {
    post({ clear_history: 1 }).catch(function () {}).then(function () {
      welcome('✅ Chat cleared!', 'Start a new conversation.');
    });
  }

  function loadHistory() {
    post({ get_history: 1 }).then(function (data) {
      if (!data || data.error || !data.length) { welcome(); return; }
      msgs.innerHTML = '';
      data.forEach(function (c) {
        if (c.type === 'product' && c.payload && c.payload.name) { showProduct(c.payload); }
        else if (c.type === 'menu' || c.type === 'orders') { /* live cards aren't replayed */ }
        else if (c.message) { addMessage(c.message, c.sender === 'user' ? 'user' : 'bot'); }
      });
      if (!msgs.children.length) welcome();
    }).catch(function () { welcome(); });
  }

  /* ---------- open / close ---------- */
  function setOpen(open) {
    box.classList.toggle('open', open);
    root.classList.toggle('cb-is-open', open);
    launcher.setAttribute('aria-expanded', open ? 'true' : 'false');
    if (isMobile()) document.documentElement.classList.toggle('cb-lock', open);
    if (open) {
      if (tip) tip.classList.add('gone');
      toBottom();
      if (!isMobile()) setTimeout(function () { input.focus(); }, 50);
    } else {
      document.documentElement.classList.remove('cb-lock');
    }
  }

  function updateSend() { sendBtn.disabled = !input.value.trim() || pending; }

  /* ---------- wire up ---------- */
  launcher.addEventListener('click', function () { setOpen(!box.classList.contains('open')); });
  document.getElementById('cb-close').addEventListener('click', function () { setOpen(false); });
  document.getElementById('cb-clear').addEventListener('click', clearChat);
  document.getElementById('cb-q-menu').addEventListener('click', function () { if (!pending) loadMenu(); });
  document.getElementById('cb-q-rec').addEventListener('click', loadRecommendation);
  document.getElementById('cb-q-orders').addEventListener('click', function () { send('Track my order'); });

  form.addEventListener('submit', function (e) { e.preventDefault(); send(input.value); });
  input.addEventListener('input', updateSend);

  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && box.classList.contains('open')) setOpen(false);
  });

  window.addEventListener('resize', function () {
    if (!isMobile()) document.documentElement.classList.remove('cb-lock');
    else if (box.classList.contains('open')) document.documentElement.classList.add('cb-lock');
  });

  // Keep the full-screen chat sized to what's actually visible when the phone keyboard opens
  if (window.visualViewport) {
    var vv = window.visualViewport;
    var fit = function () {
      root.style.setProperty('--cb-vh', vv.height + 'px');
      root.style.setProperty('--cb-top', vv.offsetTop + 'px');
      if (box.classList.contains('open')) toBottom();
    };
    vv.addEventListener('resize', fit);
    vv.addEventListener('scroll', fit);
    fit();
  }

  loadHistory();
})();
</script>