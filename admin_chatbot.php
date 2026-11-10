<?php

// ==================== OLLAMA CONFIG ====================
define('OLLAMA_URL', getenv('OLLAMA_URL') ?: 'http://127.0.0.1:11434/api/chat');
define('OLLAMA_MODEL', 'qwen3:4b');
define('OLLAMA_BASIC_AUTH_USERNAME', getenv('OLLAMA_BASIC_AUTH_USERNAME') ?: '');
define('OLLAMA_BASIC_AUTH_PASSWORD', getenv('OLLAMA_BASIC_AUTH_PASSWORD') ?: '');

function strip_ollama_thinking($content) {
    return trim(preg_replace('/<think\b[^>]*>.*?<\/think\s*>/is', '', $content));
}

function ollama_basic_auth_options(): ?array {
    $hasUsername = OLLAMA_BASIC_AUTH_USERNAME !== '';
    $hasPassword = OLLAMA_BASIC_AUTH_PASSWORD !== '';
    if (!$hasUsername && !$hasPassword) {
        return [];
    }
    if (!$hasUsername || !$hasPassword) {
        error_log('[Chatbot Ollama] Basic Auth requires both OLLAMA_BASIC_AUTH_USERNAME and OLLAMA_BASIC_AUTH_PASSWORD.');
        return null;
    }

    return [
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => OLLAMA_BASIC_AUTH_USERNAME . ':' . OLLAMA_BASIC_AUTH_PASSWORD,
    ];
}

// How many past turns (user+assistant pairs) to keep and feed back to Qwen, so it can
// answer follow-ups like "explain that forecast in more detail" or "why is that low".
define('CHAT_HISTORY_TURNS', 6);

// Separate, more generous timeouts for CPU-only inference. Keep every Ollama
// request at or above 90 seconds because Qwen3 reasoning can be slow.
define('OLLAMA_TIMEOUT_CLASSIFY', 90);
define('OLLAMA_TIMEOUT_FORECAST', 150);
define('OLLAMA_TIMEOUT_GENERAL', 120);
define('OLLAMA_CONNECT_TIMEOUT', 10);

// Where product images live, relative to this file - matches admin_products.php's $UPLOAD_DIR.
define('CHATBOT_UPLOAD_DIR', 'images/');

// Exact strings the quick-action buttons in the widget send. When a message
// matches one of these exactly, skip AI classification entirely and route
// straight to the intent - faster, and immune to the LLM confusing "check
// ingredient stock" with a finished-product inventory question.
define('CHATBOT_QUICK_ACTIONS', [
    'show low stock' => 'inventory',
    'check ingredient stock' => 'ingredients',
    'how much revenue today?' => 'sales',
    'top products' => 'analytics',
    'show pending orders' => 'orders',
    'forecast sales for next week' => 'forecast',
]);

// ==================== SESSION / CSRF SETUP ====================
// Started here (guarded) so both API actions below and the widget markup at
// the bottom of this file can rely on $_SESSION being available and carrying
// a CSRF token - the SAME $_SESSION['csrf_token'] key admin_products.php
// already uses, so whichever page set it first "wins" and both stay in sync.
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ==================== DEBUG / CONNECTIVITY PING ====================
// A minimal, isolated test so you can confirm PHP can talk to Ollama and how
// long a real generation takes on your CPU, WITHOUT going through intent
// classification, DB queries, or chat history. Hit this first when debugging.
//   admin_chatbot.php?action=ollama_ping
if (isset($_GET['action']) && $_GET['action'] === 'ollama_ping') {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    header('Content-Type: application/json');

    $authOptions = ollama_basic_auth_options();
    if ($authOptions === null) {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'stage' => 'configuration',
            'hint' => 'Ollama Basic Auth is incomplete. Configure both username and password, or leave both unset.',
        ]);
        exit;
    }

    $start = microtime(true);
    $ch = curl_init(OLLAMA_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode([
            'model' => OLLAMA_MODEL,
            'messages' => [['role' => 'user', 'content' => 'Reply with exactly one word: pong']],
            'stream' => false,
            'think' => true,
            'options' => ['temperature' => 0],
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'ngrok-skip-browser-warning: true',
        ],
        CURLOPT_TIMEOUT => 90,
        CURLOPT_CONNECTTIMEOUT => OLLAMA_CONNECT_TIMEOUT,
    ] + $authOptions);
    $raw = curl_exec($ch);
    $curl_err = curl_error($ch);
    $curl_errno = curl_errno($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $elapsed = round(microtime(true) - $start, 2);

    if ($curl_err) {
        echo json_encode([
            'ok' => false,
            'stage' => 'curl',
            'curl_errno' => $curl_errno,
            'curl_error' => $curl_err,
            'elapsed_seconds' => $elapsed,
            'hint' => 'PHP could not connect to the configured Ollama endpoint. Check that Ollama and any tunnel are running and that OLLAMA_URL is correct.',
        ]);
        exit;
    }

    $data = json_decode($raw, true);
    if ($http_code !== 200) {
        echo json_encode([
            'ok' => false,
            'stage' => 'http_status',
            'http_code' => $http_code,
            'raw_response' => $raw,
            'elapsed_seconds' => $elapsed,
            'hint' => $http_code === 404
                ? 'Ollama responded but the model "' . OLLAMA_MODEL . '" was not found. Run: ollama pull qwen3:4b'
                : 'Ollama responded with a non-200 status. See raw_response for details.',
        ]);
        exit;
    }

    $content = $data['message']['content'] ?? null;
    if (is_string($content)) {
        $content = strip_ollama_thinking($content);
    }
    echo json_encode([
        'ok' => $content !== null,
        'stage' => 'success',
        'model' => OLLAMA_MODEL,
        'elapsed_seconds' => $elapsed,
        'reply' => $content,
        'note' => 'This is roughly the minimum latency a real query will take on your CPU. If this succeeded but the chatbot still times out, the forecast/general timeouts may still be too short for your machine - increase OLLAMA_TIMEOUT_FORECAST / OLLAMA_TIMEOUT_GENERAL further.',
    ]);
    exit;
}

// ==================== CHATBOT API BACKEND ====================
if (isset($_GET['action']) && $_GET['action'] === 'query') {
    include 'config.php';
    require_once __DIR__ . '/app/helpers/loyalty.php';
    require_once __DIR__ . '/app/services/admin_log_activity.php';

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Verify admin session
    if (!isset($_SESSION['admin_id'])) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit;
    }

    // CSRF check. Uses the same $_SESSION['csrf_token'] admin_products.php
    // relies on. Only enforced if a token has actually been established
    // (it always will be, since the top of this file guarantees one) - kept
    // as a soft `!empty()` guard rather than a hard requirement so this
    // never becomes a way to lock an admin out if sessions ever get cleared
    // mid-request.
    if (!empty($_SESSION['csrf_token'])) {
        $sent_token = $_POST['csrf_token'] ?? '';
        if (!hash_equals($_SESSION['csrf_token'], $sent_token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Security check failed. Please refresh the page and try again.']);
            exit;
        }
    }

    // Pull in the activity logger if it's reachable from here, same as
    // admin_products.php / admin_inventory.php, so chat-originated
    // add/edit/delete actions show up in the same audit trail. Guarded so a
    // missing file never breaks the chatbot.
    if (!function_exists('log_admin_activity') && file_exists(__DIR__ . '/admin_log_activity.php')) {
        include_once 'admin_log_activity.php';
    }

    // CPU inference can legitimately take 60-150s. Make sure PHP itself
    // doesn't kill the script before the curl timeout is even reached.
    // (Note: if php-fpm/Apache has its own request timeout or a reverse
    // proxy like Nginx has proxy_read_timeout set lower than this, that
    // will also need raising - see the note at the bottom of this file.)
    set_time_limit(180);

    $query = trim($_POST['query'] ?? '');
    if (empty($query)) {
        echo json_encode(['error' => 'Empty query']);
        exit;
    }

    // ==================== CONVERSATION MEMORY ====================
    function get_chat_history() {
        if (!isset($_SESSION['chatbot_history']) || !is_array($_SESSION['chatbot_history'])) {
            $_SESSION['chatbot_history'] = [];
        }
        return $_SESSION['chatbot_history'];
    }

    function push_chat_history($role, $content) {
        if ($content === '' || $content === null) {
            return;
        }
        if (!isset($_SESSION['chatbot_history']) || !is_array($_SESSION['chatbot_history'])) {
            $_SESSION['chatbot_history'] = [];
        }
        $_SESSION['chatbot_history'][] = ['role' => $role, 'content' => $content];

        $max_messages = CHAT_HISTORY_TURNS * 2;
        if (count($_SESSION['chatbot_history']) > $max_messages) {
            $_SESSION['chatbot_history'] = array_slice($_SESSION['chatbot_history'], -$max_messages);
        }
    }

    // Turns a structured bot response into a short plain-text note we can feed back
    // to Qwen later as conversation context, so it "remembers" what it already showed.
    function summarize_response_for_history($response) {
        $parts = [];
        if (!empty($response['title'])) {
            $parts[] = strip_tags($response['title']);
        }
        if (!empty($response['message'])) {
            $parts[] = strip_tags($response['message']);
        }
        if (!empty($response['data']) && is_array($response['data'])) {
            $lines = [];
            foreach (array_slice($response['data'], 0, 12) as $item) {
                if (is_array($item)) {
                    $pairs = [];
                    foreach ($item as $k => $v) {
                        if (is_scalar($v)) {
                            $pairs[] = "$k: $v";
                        }
                    }
                    if ($pairs) {
                        $lines[] = implode(', ', $pairs);
                    }
                } elseif (is_scalar($item)) {
                    $lines[] = (string)$item;
                }
            }
            if ($lines) {
                $parts[] = implode(' | ', $lines);
            }
        }
        $text = trim(implode("\n", $parts));
        if (strlen($text) > 2200) {
            $text = substr($text, 0, 2200) . '...';
        }
        return $text;
    }

    // Builds the messages array (system + history + latest user message) sent to Ollama.
    function build_messages_with_history($system, $history, $latest_user_content) {
        $messages = [['role' => 'system', 'content' => $system]];
        foreach ($history as $h) {
            $role = ($h['role'] === 'assistant') ? 'assistant' : 'user';
            $messages[] = ['role' => $role, 'content' => $h['content']];
        }
        $messages[] = ['role' => 'user', 'content' => $latest_user_content];
        return $messages;
    }

    // ==================== OLLAMA HELPER ====================
    // Logs the SPECIFIC reason for a failure via error_log() instead of
    // silently returning null for every possible failure mode. Check your
    // PHP error log (or run `tail -f` on it) after a failed query to see
    // exactly what happened.
    function call_ollama($messages, $json_mode = false, $timeout = 90) {
        $timeout = max(90, (int)$timeout);
        $payload = [
            'model' => OLLAMA_MODEL,
            'messages' => $messages,
            'stream' => false,
            'think' => true,
            'options' => ['temperature' => 0.2],
        ];
        if ($json_mode) {
            $payload['format'] = 'json';
        }

        $authOptions = ollama_basic_auth_options();
        if ($authOptions === null) {
            return null;
        }

        $ch = curl_init(OLLAMA_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'ngrok-skip-browser-warning: true',
            ],
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => OLLAMA_CONNECT_TIMEOUT,
        ] + $authOptions);
        $raw = curl_exec($ch);
        $curl_err = curl_error($ch);
        $curl_errno = curl_errno($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_err) {
            // errno 28 = CURLE_OPERATION_TIMEDOUT -> the model just took longer
            // than $timeout seconds to respond. On CPU with qwen3:4b this is the
            // most common cause. Bump OLLAMA_TIMEOUT_FORECAST / _GENERAL / _CLASSIFY
            // if you see this repeatedly.
            error_log("[Chatbot Ollama] curl failed (errno $curl_errno, timeout={$timeout}s): $curl_err");
            return null;
        }

        if ($http_code !== 200) {
            error_log("[Chatbot Ollama] HTTP $http_code from Ollama. Raw response: " . substr((string)$raw, 0, 500));
            return null;
        }

        $data = json_decode($raw, true);
        $content = $data['message']['content'] ?? null;
        if ($content === null) {
            error_log("[Chatbot Ollama] No message.content in response. Raw: " . substr((string)$raw, 0, 500));
            return null;
        }

        return strip_ollama_thinking($content);
    }

    // ==================== NUMBER / ENTITY HELPERS ====================
    // Strips currency symbols, commas, "pesos", etc. and returns a float (or null).
    function clean_number($raw) {
        if ($raw === null) return null;
        $s = preg_replace('/[^\d.\-]/', '', (string)$raw);
        if ($s === '' || $s === '-' || $s === '.') return null;
        return floatval($s);
    }

    // Regex fallback extractor for the 3 product commands. Runs on the raw admin
    // text and fills in anything Qwen's JSON extraction missed, so "add product
    // Red Shirt price 599" still works even if the local model's JSON is rough.
    function regex_extract_product_entities($intent, $query) {
        $entities = [];
        $q = $query;

        // --- price: "price 599", "₱599", "for 599 pesos", "to 599", "at 599" ---
        if (preg_match('/(?:\x{20B1}|php)\s*([\d,]+(?:\.\d+)?)/iu', $q, $m)
            || preg_match('/\b(?:price|cost)\b\s*(?:is|of|:|=)?\s*(?:\x{20B1})?\s*([\d,]+(?:\.\d+)?)/iu', $q, $m)
            || preg_match('/\bto\s+(?:\x{20B1})?\s*([\d,]+(?:\.\d+)?)\b/iu', $q, $m)
            || preg_match('/\bat\s+(?:\x{20B1})?\s*([\d,]+(?:\.\d+)?)\b/iu', $q, $m)
            || preg_match('/\bfor\s+(?:\x{20B1})?\s*([\d,]+(?:\.\d+)?)\s*(?:pesos?)?\b/iu', $q, $m)
            || preg_match('/([\d,]+(?:\.\d+)?)\s*pesos?\b/i', $q, $m)) {
            $entities['price'] = clean_number($m[1]);
        }

        // --- stock: "stock 20", "20 in stock", "20 units" ---
        if (preg_match('/\bstock\b\s*(?:is|of|:|=)?\s*(\d+)/i', $q, $m)
            || preg_match('/(\d+)\s*(?:in stock|units|pcs|pieces)\b/i', $q, $m)) {
            $entities['stock'] = (int)$m[1];
        }

        // --- size: "size M", "size: Large", "size 1 Slice" ---
        if (preg_match('/\bsize\b\s*(?:is|:|=)?\s*([^,\r\n]+?)(?=\s*,|\s+\b(?:price|for|at|stock|description|type)\b|[\r\n.!?;]|$)/iu', $q, $m)) {
            $entities['size'] = trim($m[1]);
        }

        // --- product name (depends on the command) ---
        if ($intent === 'add_product') {
            if (preg_match('/product\s+(?:called|named)\s+"?([A-Za-z0-9 \'\-\.]+?)"?(?:[\s,]+(?:price|for|at|size|stock|description|type|\x{20B1}|php)\b|[\s,]*$)/iu', $q, $m)
                || preg_match('/add\s+(?:a\s+|new\s+)?product\s+"?([A-Za-z0-9 \'\-\.]+?)"?(?:[\s,]+(?:price|for|at|size|stock|description|type|\x{20B1}|php)\b|[\s,]*$)/iu', $q, $m)) {
                $entities['product_name'] = preg_replace('/\s+with$/i', '', trim($m[1]));
            }

            // --- size_type: "slice"/"pieces"/"cake" vs "cup"/"drink" ---
            if (preg_match('/\b(slice|pieces|cake)\b/i', $q)) {
                $entities['size_type'] = 'slice';
            } elseif (preg_match('/\b(cup|drink)\b/i', $q)) {
                $entities['size_type'] = 'cup';
            }

            // --- special instructions toggle ---
            if (preg_match('/\b(no|disable|without)\s+special\s+instructions\b/i', $q)) {
                $entities['allow_special_instructions'] = 0;
            } elseif (preg_match('/\ballow\s+special\s+instructions\b/i', $q)) {
                $entities['allow_special_instructions'] = 1;
            }
        } elseif ($intent === 'edit_price') {
            if (preg_match('/(?:price|cost)\s+of\s+"?([A-Za-z0-9 \'\-\.]+?)"?\s+(?:to|is|=|:)/i', $q, $m)
                || preg_match('/change\s+"?([A-Za-z0-9 \'\-\.]+?)"?\s+(?:price|to)/i', $q, $m)) {
                $entities['product_name'] = trim($m[1]);
            }
        } elseif ($intent === 'delete_product') {
            if (preg_match('/(?:delete|remove)\s+(?:the\s+)?product\s+"?([A-Za-z0-9 \'\-\.]+?)"?\s*$/i', $q, $m)) {
                $entities['product_name'] = trim($m[1]);
            }
        }

        return $entities;
    }

    // Regex fallback extractor for the add_ingredient command. Fills in
    // whatever Qwen's JSON extraction missed for a new inventory item, mirroring
    // the fields admin_inventory.php's "Add New Item" form collects.
    function regex_extract_ingredient_entities($query) {
        $entities = [];
        $q = $query;

        // --- category: consumable vs packaging ---
        if (preg_match('/\b(drinks?|beverage)\b/i', $q)) {
            $entities['category'] = 'consumable';
        } elseif (preg_match('/\bconsumables?\b/i', $q)) {
            $entities['category'] = 'consumable';
        } elseif (preg_match('/\bpackaging\b/i', $q)) {
            $entities['category'] = 'packaging';
        }

        // --- unit: grams, ml, pieces, cups ---
        if (preg_match('/\bgrams?\b/i', $q)) {
            $entities['unit'] = 'grams';
        } elseif (preg_match('/\bml\b/i', $q)) {
            $entities['unit'] = 'ml';
        } elseif (preg_match('/\b(pieces|pcs)\b/i', $q)) {
            $entities['unit'] = 'pieces';
        } elseif (preg_match('/\bcups?\b/i', $q)) {
            $entities['unit'] = 'cups';
        }

        // --- opening stock / quantity ---
        if (preg_match('/\b(?:opening\s+stock|quantity|stock)\b\s*(?:is|of|:|=)?\s*([\d,]+(?:\.\d+)?)/i', $q, $m)) {
            $entities['quantity'] = clean_number($m[1]);
        }

        // --- low-stock alert level ---
        if (preg_match('/\b(?:min(?:imum)?\s*stock(?:\s*level)?|alert\s*level|reorder\s*level)\b\s*(?:is|of|:|=)?\s*([\d,]+(?:\.\d+)?)/i', $q, $m)) {
            $entities['min_stock_level'] = clean_number($m[1]);
        }

        // --- ingredient name ---
        if (preg_match('/ingredient\s+(?:called|named)\s+"?([A-Za-z0-9 \'\-\.]+?)"?(?:\s+(?:category|unit|quantity|stock|min|alert)|\s*$)/i', $q, $m)
            || preg_match('/add\s+(?:a\s+|new\s+)?ingredient\s+"?([A-Za-z0-9 \'\-\.]+?)"?(?:\s+(?:category|unit|quantity|stock|min|alert)|\s*$)/i', $q, $m)) {
            $entities['ingredient_name'] = trim($m[1]);
        }

        return $entities;
    }

    // Fills in any missing/empty keys in $primary (Qwen's extraction) using
    // $fallback (regex extraction), without overwriting anything Qwen already
    // got right.
    function merge_entities($primary, $fallback) {
        if (!is_array($primary)) $primary = [];
        foreach ($fallback as $k => $v) {
            if (!isset($primary[$k]) || $primary[$k] === '' || $primary[$k] === null) {
                $primary[$k] = $v;
            }
        }
        return $primary;
    }

    function regex_extract_reward_entities($intent, $query) {
        $entities = [];
        if ($intent === 'create_voucher') {
            if (preg_match('/\b(?:name|called|named)\s*[:=]?\s*["\']([^"\']{1,100})["\']/i', $query, $m)
                || preg_match('/\bvoucher\s+["“‘]([^"”’]{1,100})["”’]/u', $query, $m)) {
                $entities['name'] = trim($m[1]);
            }
            if (preg_match('/\b(?:sign[\s-]?ups?|new\s+accounts?|welcome\s+(?:offer|voucher|reward))\b/i', $query)) {
                $entities['trigger_type'] = 'signup';
                $entities['condition_type'] = 'none';
            } elseif (preg_match('/\b(order|purchase|buy)\b/i', $query)) {
                $entities['trigger_type'] = 'order';
            }
            if (preg_match('/\bfree[\s-]?shipping\b/i', $query)) {
                $entities['reward_type'] = 'free_shipping';
                $entities['reward_amount'] = 0;
            } elseif (preg_match('/\bfree[\s-]?product\b/i', $query)) {
                $entities['reward_type'] = 'free_product';
                $entities['reward_amount'] = 0;
                if (preg_match('/\bproduct(?:\s+id)?\s*#?\s*(\d+)\b/i', $query, $m)) {
                    $entities['free_product_id'] = (int)$m[1];
                }
            } elseif (preg_match('/(?:\x{20B1}|php)\s*([\d,]+(?:\.\d+)?)\s*(?:off|discount)\b/iu', $query, $m)) {
                $entities['reward_type'] = 'fixed';
                $entities['reward_amount'] = clean_number($m[1]);
            } elseif (preg_match('/\b(\d+(?:\.\d+)?)\s*%/', $query, $m)) {
                $entities['reward_type'] = 'percentage';
                $entities['reward_amount'] = (float)$m[1];
            }
            if (preg_match('/\b(?:minimum spend|min spend)\s*(?:of|at least|:|=)?\s*(?:\x{20B1}|php)?\s*([\d,]+(?:\.\d+)?)/iu', $query, $m)) {
                $entities['minimum_spend'] = clean_number($m[1]);
            }
            if (preg_match('/\bspend\s+(?:at least\s+)?(?:\x{20B1}|php)?\s*([\d,]+(?:\.\d+)?)/iu', $query, $m)
                && ($entities['trigger_type'] ?? '') === 'order') {
                $entities['condition_type'] = 'spend';
                $entities['threshold_value'] = clean_number($m[1]);
            }
            if (preg_match('/\b(?:buy|purchase)\s+(\d+)\s+(?:or more\s+)?products?\b/i', $query, $m)) {
                $entities['condition_type'] = 'products';
                $entities['threshold_value'] = (int)$m[1];
            }
            if (preg_match('/\bvalid\s+(?:for\s+)?(\d{1,3})\s*days?\b/i', $query, $m)) {
                $entities['validity_days'] = (int)$m[1];
            }
        } elseif ($intent === 'edit_loyalty_settings') {
            $patterns = [
                'points_per_wallet_peso' => '/\b(?:every\s+)?(\d+(?:\.\d+)?)\s*points?\s*(?:per|=|for)\s*(?:\x{20B1}|php)?\s*(\d+(?:\.\d+)?)\s*(?:peso|balance|wallet)?\b/iu',
                'points_voucher_cost' => '/\b(?:voucher|redeem(?:ing)?)\s*(?:cost|for|at|requires?)\s*(\d+)\s*points?\b|\b(\d+)\s*[- ]points?\s+(?:(?:for\s+(?:a\s+)?\d+%\s+)?(?:off|discount|voucher)|\d{1,3}\s*%\s+voucher)\b|\b(\d+)\s+points?\s+to\s+(?:get|have)\b/i',
                'points_voucher_percent' => '/\b(\d{1,3})\s*%\s*(?:off|voucher|discount)\b/i',
                'points_voucher_minimum_spend' => '/\b(?:minimum|min)\s*(?:spend)?\s*(?:₱|php)?\s*([\d,]+(?:\.\d+)?)/i',
                'points_voucher_validity_days' => '/\bvalid(?:ity)?\s*(?:for)?\s*(\d+)\s*days?\b/i',
            ];
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $query, $m)) {
                    if ($key === 'points_per_wallet_peso') {
                        $value = (float)($m[2] ?? 0) > 0 ? (float)$m[1] / (float)$m[2] : null;
                    } else {
                        $value = $m[1] ?? '';
                        if ($value === '') {
                            $value = $m[2] ?? '';
                        }
                        if ($value === '') {
                            $value = $m[3] ?? '';
                        }
                    }
                    $entities[$key] = clean_number($value);
                }
            }
        }
        return $entities;
    }

    // Safety net for typed (non-button) messages: the LLM sometimes classifies
    // an ingredient-stock question ("check ingredient stock") as "inventory"
    // because both mention "stock". If the message clearly refers to
    // ingredients and isn't an "add ingredient" command, force the intent
    // back to "ingredients" so it can't be swallowed by the finished-product
    // inventory branch.
    function normalize_ingredient_intent($query, $intent) {
        $q = strtolower($query);
        if (preg_match('/\bingredients?\b/i', $q)
            && !preg_match('/\badd\s+(?:a\s+|new\s+)?ingredient\b/i', $q)) {
            return 'ingredients';
        }
        return $intent;
    }

    // ==================== INTENT CLASSIFICATION (Qwen3) ====================
    function qwen_classify($query, $history = []) {
        $system = <<<SYS
You are the intent classifier for an admin e-commerce dashboard chatbot for a coffee shop.
Classify the admin's LATEST message into exactly one intent and extract any relevant entities.
You may be given earlier turns of the conversation as extra context - use them only to
understand references like "that", "it", or "explain more"; classify only the newest message.

Valid intents:
- inventory: questions about FINISHED PRODUCT stock levels (e.g. how many cups of coffee or
  slices of cake are left to sell), low stock, quantities
- ingredients: questions about RAW ingredient/packaging stock (e.g. milk, cups, syrup) tracked
  in a separate ingredient inventory - NOT the same as finished-product stock
- sales: revenue, earnings, income questions
- orders: order status, pending orders, deliveries
- analytics: best sellers, top products, trending items
- users: customer counts, accounts, members, pending approvals, wallet balances
- help: how-to questions about using the admin panel
- forecast: asking to predict/forecast/project future sales, revenue, or demand
- add_product: a command to create a new menu product
- edit_price: a command to change an existing product's price
- delete_product: a command to remove a product
- add_ingredient: a command to add a new RAW ingredient/packaging item to the ingredient
  inventory (not a menu product)
- create_voucher: create an automatic signup or order-reward voucher campaign
- edit_loyalty_settings: change points-to-wallet or points-voucher redemption settings
- general: greetings, small talk, follow-up questions ("explain that", "why", "tell me more",
  "what does that mean", "can you elaborate"), or open-ended reasoning questions that don't fit above

For add_product, extract entities: product_name, description, size_type ("cup" or "slice"),
allow_special_instructions (1 or 0), and variants as an array of objects with size, price,
and stock. Include every size/price/stock variant the admin specified (1 to 10).
Do NOT extract a "category" entity - this store has no category field on products.
For edit_price, extract entities: product_name, price.
For delete_product, extract entities: product_name.
For add_ingredient, extract entities: ingredient_name, category ("consumable" or "packaging"),
unit ("grams", "ml", "pieces", or "cups"), quantity (opening stock, 0 if not given),
min_stock_level (low-stock alert level, 0 if not given).
For create_voucher, extract entities: name, trigger_type ("signup" or "order"),
condition_type ("none", "spend", or "products"), threshold_value, reward_type
("fixed", "percentage", "free_shipping", or "free_product"), reward_amount,
minimum_spend, free_product_id, validity_days.
For edit_loyalty_settings, extract any requested fields: points_per_wallet_peso,
points_voucher_cost, points_voucher_percent, points_voucher_minimum_spend,
points_voucher_validity_days. Do not invent values; omit unspecified fields.
For all other intents, entities can be an empty object.

Respond with ONLY valid JSON in this exact shape, no extra text:
{"intent": "...", "entities": {...}}
SYS;

        $messages = build_messages_with_history($system, $history, $query);

        $content = call_ollama($messages, true, OLLAMA_TIMEOUT_CLASSIFY);
        if (!$content) {
            return null;
        }

        $parsed = json_decode($content, true);
        if (!is_array($parsed) || empty($parsed['intent'])) {
            error_log('[Chatbot] qwen_classify got unparsable JSON: ' . substr($content, 0, 300));
            return null;
        }
        if (!isset($parsed['entities']) || !is_array($parsed['entities'])) {
            $parsed['entities'] = [];
        }
        return $parsed;
    }

    // ==================== FALLBACK CLASSIFIER ====================
    // Used only if Ollama is unreachable, so the chatbot still works in a degraded mode.
    function classify_intent_fallback($query) {
        $query_lower = strtolower($query);

        // Ingredient / product commands checked first so they aren't swallowed
        // by generic "stock"/"products"/"price" keyword matches further down.
        if (preg_match('/\badd\s+(?:a\s+|new\s+)?ingredient\b/i', $query_lower)) {
            return 'add_ingredient';
        } elseif (preg_match('/\bingredients?\b/i', $query_lower)) {
            return 'ingredients';
        } elseif (preg_match('/\badd\s+(?:a\s+|new\s+)?product\b/i', $query_lower)) {
            return 'add_product';
        } elseif (preg_match('/\b(change|update|edit|set)\s+(?:the\s+)?price\b/i', $query_lower)) {
            return 'edit_price';
        } elseif (preg_match('/\b(delete|remove)\s+(?:the\s+)?product\b/i', $query_lower)) {
            return 'delete_product';
        } elseif (preg_match('/\b(create|make|generate|add)\b.*\bvoucher\b/i', $query_lower)) {
            return 'create_voucher';
        } elseif (preg_match('/\b(edit|change|update|set)\b.*\b(points?|redemption|redeem)\b/i', $query_lower)) {
            return 'edit_loyalty_settings';
        } elseif (preg_match('/(forecast|predict|projection|next week sales|future sales)/i', $query_lower)) {
            return 'forecast';
        } elseif (preg_match('/(low stock|low inventory|stock alert|out of stock|inventory|how many|units|quantity)/i', $query_lower)) {
            return 'inventory';
        } elseif (preg_match('/(revenue|earnings|sales|income|total price|how much|profit|money|cash)/i', $query_lower)) {
            return 'sales';
        } elseif (preg_match('/(order|orders|pending|status|delivery|cart|purchase)/i', $query_lower)) {
            return 'orders';
        } elseif (preg_match('/(best seller|top product|best product|most sold|popular|trending|products)/i', $query_lower)) {
            return 'analytics';
        } elseif (preg_match('/(customer|user|account|profile|members|total user|wallet)/i', $query_lower)) {
            return 'users';
        } elseif (preg_match('/(how to|help|guide|tutorial|steps|manage)/i', $query_lower)) {
            return 'help';
        }

        return 'general';
    }

    // ==================== "LAST REPORT" MEMORY ====================
    // Stores the concrete numbers behind the admin's most recent report so a
    // follow-up like "explain that more" / "why is that low" has real data to
    // reason with, instead of only the AI's own condensed sentence from last time.
    function remember_last_report($type, $lines) {
        $lines = array_filter($lines, fn($l) => $l !== '' && $l !== null);
        if (empty($lines)) return;
        $_SESSION['last_report'] = [
            'type' => $type,
            'text' => implode("\n", $lines),
            'at' => date('Y-m-d H:i'),
        ];
    }

    function recall_last_report_text() {
        if (!empty($_SESSION['last_report']['text'])) {
            return "Here are the exact numbers behind your most recent \"" . $_SESSION['last_report']['type']
                 . "\" report (generated " . $_SESSION['last_report']['at'] . "). Use these real figures if the "
                 . "admin asks you to explain, elaborate, compare, or reason further about it:\n"
                 . $_SESSION['last_report']['text'];
        }
        return null;
    }

    // ==================== PRODUCT CRUD (prepared statements) ====================
    function execute_add_product($conn, $entities) {
        $name = trim($entities['product_name'] ?? '');
        $price = floatval($entities['price'] ?? 0);
        $details = trim($entities['description'] ?? '');
        $admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
        $placeholder_image = ''; // image column is NOT NULL; admin uploads a real photo afterward
        $variants = [];
        if (isset($entities['variants']) && is_array($entities['variants'])) {
            $variants = array_values($entities['variants']);
        } elseif (isset($entities['size']) || isset($entities['stock'])) {
            $variants[] = [
                'size' => $entities['size'] ?? '',
                'price' => $entities['price'] ?? $price,
                'stock' => $entities['stock'] ?? 0,
            ];
        }

        // size_type: mirrors admin_products.php's $ALLOWED_SIZE_TYPES ('cup' default)
        $size_type = 'cup';
        if (!empty($entities['size_type']) && in_array(strtolower($entities['size_type']), ['cup', 'slice'], true)) {
            $size_type = strtolower($entities['size_type']);
        }

        // allow_special_instructions: mirrors the form's default-checked checkbox
        $allow_special_instructions = 1;
        if (isset($entities['allow_special_instructions'])) {
            $allow_special_instructions = ((int)$entities['allow_special_instructions'] === 0) ? 0 : 1;
        }

        if (isset($variants[0]) && is_array($variants[0]) && isset($variants[0]['price'])) {
            $price = (float)$variants[0]['price'];
        }
        if ($name === '' || $price <= 0 || $price !== floor($price)) {
            return ['success' => false, 'message' => 'I need a product name and a valid whole-peso price.'];
        }
        if (count($variants) < 1 || count($variants) > 10) {
            return ['success' => false, 'message' => 'Add at least one size/price/stock variant (up to 10) before creating the product.'];
        }
        $normalizedVariants = [];
        $seenSizes = [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                return ['success' => false, 'message' => 'Each product variant must include a size, price, and stock quantity.'];
            }
            $variantSize = trim((string)($variant['size'] ?? ''));
            $variantPriceValue = $variant['price'] ?? null;
            $variantStockValue = $variant['stock'] ?? null;
            $variantPrice = is_numeric($variantPriceValue) && (float)$variantPriceValue === floor((float)$variantPriceValue)
                ? (int)$variantPriceValue : false;
            $variantStock = is_numeric($variantStockValue) && (float)$variantStockValue === floor((float)$variantStockValue)
                ? (int)$variantStockValue : false;
            $sizeKey = strtolower($variantSize);
            if ($variantSize === '' || mb_strlen($variantSize) > 50 || $variantPrice === false || $variantPrice <= 0
                || $variantStock === false || $variantStock <= 0 || isset($seenSizes[$sizeKey])) {
                return ['success' => false, 'message' => 'Each variant needs a unique size (max 50 characters), whole-peso price above ₱0, and stock above 0.'];
            }
            $seenSizes[$sizeKey] = true;
            $normalizedVariants[] = ['size' => $variantSize, 'price' => $variantPrice, 'stock' => $variantStock];
        }
        $price = $normalizedVariants[0]['price'];
        if (mb_strlen($name) > 100) {
            return ['success' => false, 'message' => 'That product name is too long (max 100 characters).'];
        }
        if (mb_strlen($details) > 500) {
            $details = mb_substr($details, 0, 500);
        }

        // Duplicate name check - mirrors admin_products.php's exact-match rule.
        $dup_stmt = mysqli_prepare($conn, "SELECT id FROM products WHERE name = ?");
        if (!$dup_stmt) {
            return ['success' => false, 'message' => 'Database error checking for duplicates: ' . mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($dup_stmt, 's', $name);
        mysqli_stmt_execute($dup_stmt);
        $dup_result = mysqli_stmt_get_result($dup_stmt);
        $is_duplicate = $dup_result && mysqli_num_rows($dup_result) > 0;
        mysqli_stmt_close($dup_stmt);
        if ($is_duplicate) {
            return ['success' => false, 'message' => "A product named \"$name\" already exists. Try editing its price instead, or use a different name."];
        }

        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("INSERT INTO products (name, price, image, details, admin_id, size_type, allow_special_instructions) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->bind_param('sissisi', $name, $price, $placeholder_image, $details, $admin_id, $size_type, $allow_special_instructions);
            $stmt->execute();
            $product_id = (int)$conn->insert_id;
            $stmt->close();

            $sizeStmt = $conn->prepare('INSERT INTO product_sizes (product_id, size, price, stock, is_active) VALUES (?, ?, ?, ?, 1)');
            foreach ($normalizedVariants as $variant) {
                $size = $variant['size'];
                $variantPrice = $variant['price'];
                $stock = $variant['stock'];
                $sizeStmt->bind_param('isii', $product_id, $size, $variantPrice, $stock);
                $sizeStmt->execute();
            }
            $sizeStmt->close();
            $conn->commit();
        } catch (mysqli_sql_exception $e) {
            $conn->rollback();
            error_log('Chatbot product creation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'The product and its variants could not be saved. Check the database and try again.'];
        }

        if (function_exists('log_admin_activity')) {
            log_admin_activity($admin_id, 'Add Product', 'Added new product via chatbot: ' . $name);
        }

        $type_label = $size_type === 'slice' ? 'Slice/Pieces' : 'Cup';
        return [
            'success' => true,
            'message' => "✅ Product \"$name\" added with " . count($normalizedVariants) . " size/price/stock variant(s) (ID: $product_id, $type_label)."
                . " Don't forget to upload a product photo on the Products page - I can't attach images from chat.",
        ];
    }

    function execute_create_voucher_campaign($conn, $entities) {
        if (!function_exists('is_head_admin') || !is_head_admin()) {
            return ['success' => false, 'message' => 'Only the Head Admin can create automatic voucher campaigns.'];
        }

        $name = trim((string)($entities['name'] ?? ''));
        $trigger = (string)($entities['trigger_type'] ?? '');
        $condition = (string)($entities['condition_type'] ?? '');
        $threshold = (float)($entities['threshold_value'] ?? 0);
        $rewardType = (string)($entities['reward_type'] ?? '');
        $amount = (float)($entities['reward_amount'] ?? 0);
        $minimumSpend = (float)($entities['minimum_spend'] ?? 0);
        $freeProductId = isset($entities['free_product_id']) ? (int)$entities['free_product_id'] : null;
        $validityDays = (int)($entities['validity_days'] ?? 14);

        if ($name === '' || mb_strlen($name) > 100) {
            return ['success' => false, 'message' => 'Provide a voucher campaign name up to 100 characters.'];
        }
        if (!in_array($trigger, ['signup', 'order'], true)) {
            return ['success' => false, 'message' => 'Choose a signup or order trigger for the voucher.'];
        }
        if (!in_array($condition, ['none', 'spend', 'products'], true)
            || ($trigger === 'signup' && $condition !== 'none')
            || ($condition !== 'none' && $threshold <= 0)) {
            return ['success' => false, 'message' => 'Choose a valid eligibility condition and positive spend or product threshold.'];
        }
        if (!in_array($rewardType, ['fixed', 'percentage', 'free_shipping', 'free_product'], true)) {
            return ['success' => false, 'message' => 'Choose a supported reward: fixed discount, percentage, free shipping, or free product.'];
        }
        if (($rewardType === 'fixed' && $amount <= 0)
            || ($rewardType === 'percentage' && ($amount <= 0 || $amount > 100))
            || $minimumSpend < 0 || $validityDays < 1 || $validityDays > 365) {
            return ['success' => false, 'message' => 'The reward amount, minimum spend, or voucher validity is outside the allowed range.'];
        }
        if ($condition === 'products' && $threshold !== floor($threshold)) {
            return ['success' => false, 'message' => 'The product eligibility threshold must be a whole number.'];
        }
        if ($rewardType === 'free_product') {
            if ($freeProductId === null || $freeProductId <= 0) {
                return ['success' => false, 'message' => 'A free-product reward must include a valid product ID.'];
            }
            $product = $conn->prepare('SELECT id FROM products WHERE id = ? LIMIT 1');
            $product->bind_param('i', $freeProductId);
            $product->execute();
            $exists = $product->get_result()->num_rows === 1;
            $product->close();
            if (!$exists) {
                return ['success' => false, 'message' => 'That free-product ID does not exist.'];
            }
        } else {
            $freeProductId = null;
        }

        try {
            $stmt = $conn->prepare(
                'INSERT INTO voucher_campaigns
                    (name, trigger_type, condition_type, threshold_value, reward_type, reward_amount, minimum_spend, free_product_id, validity_days, active, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)'
            );
            $adminId = (int)$_SESSION['admin_id'];
            $stmt->bind_param('sssdsddiii', $name, $trigger, $condition, $threshold, $rewardType, $amount, $minimumSpend, $freeProductId, $validityDays, $adminId);
            $stmt->execute();
            $campaignId = (int)$conn->insert_id;
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            error_log('Chatbot voucher campaign creation failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'The voucher campaign could not be saved. Check the database migration and try again.'];
        }

        if (function_exists('log_admin_activity')) {
            log_admin_activity($adminId, 'Created Voucher Campaign', 'Created campaign via chatbot: ' . $name, 'success', 'voucher_campaigns', $campaignId, null, $entities);
        }
        return ['success' => true, 'message' => 'Automatic voucher campaign "' . $name . '" is active (ID: ' . $campaignId . ').'];
    }

    function execute_update_loyalty_settings($conn, $entities) {
        if (!function_exists('is_head_admin') || !is_head_admin()) {
            return ['success' => false, 'message' => 'Only the Head Admin can change points-redemption settings.'];
        }

        $current = loyalty_get_settings($conn);
        $fields = [
            'points_per_wallet_peso' => [0.01, 10000],
            'points_voucher_cost' => [1, 1000000],
            'points_voucher_percent' => [1, 100],
            'points_voucher_minimum_spend' => [0, 1000000],
            'points_voucher_validity_days' => [1, 365],
        ];
        $updated = [];
        foreach ($fields as $field => [$minimum, $maximum]) {
            $value = $entities[$field] ?? null;
            if ($value === null || $value === '') {
                $updated[$field] = $current[$field];
                continue;
            }
            if (!is_numeric($value) || (float)$value < $minimum || (float)$value > $maximum
                || ($field !== 'points_voucher_minimum_spend' && $field !== 'points_per_wallet_peso'
                    && (float)$value !== (float)(int)$value)
                || ($field === 'points_per_wallet_peso' && round((float)$value, 2) !== (float)$value)) {
                return ['success' => false, 'message' => 'One or more requested points settings are invalid or outside the supported range.'];
            }
            $updated[$field] = $value;
        }

        if (count(array_intersect(array_keys($entities), array_keys($fields))) === 0) {
            return ['success' => false, 'message' => 'Tell me which points setting to change, for example: 5 points per ₱1 or a voucher for 100 points.'];
        }

        try {
            $stmt = $conn->prepare(
                'UPDATE loyalty_settings
                 SET points_per_wallet_peso = ?, points_voucher_cost = ?, points_voucher_percent = ?,
                     points_voucher_minimum_spend = ?, points_voucher_validity_days = ?, updated_by = ?
                 WHERE id = 1'
            );
            $pointsPerPeso = (float)$updated['points_per_wallet_peso'];
            $voucherCost = (int)$updated['points_voucher_cost'];
            $voucherPercent = (int)$updated['points_voucher_percent'];
            $minimumSpend = (float)$updated['points_voucher_minimum_spend'];
            $validityDays = (int)$updated['points_voucher_validity_days'];
            $adminId = (int)$_SESSION['admin_id'];
            $stmt->bind_param('diidii', $pointsPerPeso, $voucherCost, $voucherPercent, $minimumSpend, $validityDays, $adminId);
            $stmt->execute();
            $stmt->close();
        } catch (mysqli_sql_exception $e) {
            error_log('Chatbot loyalty settings update failed: ' . $e->getMessage());
            return ['success' => false, 'message' => 'Points settings could not be saved. Check that the loyalty migration has been applied.'];
        }

        if (function_exists('log_admin_activity')) {
            log_admin_activity($adminId, 'Updated Points Redemption Settings', 'Changed points redemption via chatbot.', 'success', 'loyalty_settings', 1, $current, $updated);
        }
        $rateLabel = rtrim(rtrim(number_format($pointsPerPeso, 2, '.', ''), '0'), '.');
        return ['success' => true, 'message' => 'Points-redemption settings updated. ' . $rateLabel . ' points = ₱1 wallet balance; voucher: ' . $voucherCost . ' points for ' . $voucherPercent . '% off, minimum spend ₱' . number_format($minimumSpend, 2) . ', valid ' . $validityDays . ' days.'];
    }

    function execute_edit_price($conn, $entities) {
        $name = trim($entities['product_name'] ?? '');
        $price = floatval($entities['price'] ?? -1);

        if ($name === '' || $price <= 0) {
            return ['success' => false, 'message' => 'I need the product name and a valid new price.'];
        }

        $like = '%' . $name . '%';
        $stmt = mysqli_prepare($conn, "UPDATE products SET price = ? WHERE name LIKE ?");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error preparing the price update: ' . mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($stmt, 'ds', $price, $like);
        $ok = mysqli_stmt_execute($stmt);
        $affected = $ok ? mysqli_stmt_affected_rows($stmt) : 0;
        mysqli_stmt_close($stmt);

        if ($ok && $affected > 0) {
            $stmt2 = mysqli_prepare($conn, "
                UPDATE product_sizes ps
                JOIN products p ON p.id = ps.product_id
                SET ps.price = ?
                WHERE p.name LIKE ?
            ");
            if ($stmt2) {
                mysqli_stmt_bind_param($stmt2, 'ds', $price, $like);
                mysqli_stmt_execute($stmt2);
                mysqli_stmt_close($stmt2);
            }

            if (function_exists('log_admin_activity')) {
                $admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
                log_admin_activity($admin_id, 'Update Product', 'Updated price for "' . $name . '" via chatbot to ₱' . number_format($price, 2));
            }

            return ['success' => true, 'message' => "✅ Price for \"$name\" updated to ₱" . number_format($price, 2) . " (all sizes updated too)."];
        }
        return ['success' => false, 'message' => "No product matching \"$name\" was found."];
    }

    // Mirrors admin_products.php's ==================== DELETE PRODUCT ====================
    // handler exactly, so a deletion made from the chatbot leaves the store in
    // the same state as one made from the Products page: carts cleared,
    // in-progress orders cancelled (wallet-paid ones refunded), the image file
    // removed, and every linked record (sizes, ingredients, preference/extra
    // groups) cleaned up before the product row itself goes.
    function execute_delete_product($conn, $entities) {
        $name = trim($entities['product_name'] ?? '');
        if ($name === '') {
            return ['success' => false, 'message' => 'Which product should I delete?'];
        }

        // Prefer an exact match; fall back to a partial (LIKE) match.
        $row = null;
        $stmt = mysqli_prepare($conn, "SELECT id, name, image FROM products WHERE name = ? LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $name);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
        }

        if (!$row) {
            $like = '%' . $name . '%';
            $stmt = mysqli_prepare($conn, "SELECT id, name, image FROM products WHERE name LIKE ? LIMIT 1");
            if (!$stmt) {
                return ['success' => false, 'message' => 'Database error looking up the product: ' . mysqli_error($conn)];
            }
            mysqli_stmt_bind_param($stmt, 's', $like);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            $row = $result ? mysqli_fetch_assoc($result) : null;
            mysqli_stmt_close($stmt);
        }

        if (!$row) {
            return ['success' => false, 'message' => "No product matching \"$name\" was found."];
        }

        $delete_id = (int)$row['id'];
        $actual_name = $row['name'];
        $image = $row['image'];

        // 1. Remove from all carts
        $cart_del = mysqli_prepare($conn, "DELETE FROM cart WHERE product_id = ?");
        if ($cart_del) {
            mysqli_stmt_bind_param($cart_del, 'i', $delete_id);
            mysqli_stmt_execute($cart_del);
            mysqli_stmt_close($cart_del);
        }

        // 2. Get all sizes for this product (used to spot it inside orders' free-text product list)
        $sizes = [];
        $sizes_stmt = mysqli_prepare($conn, "SELECT size FROM product_sizes WHERE product_id = ?");
        if ($sizes_stmt) {
            mysqli_stmt_bind_param($sizes_stmt, 'i', $delete_id);
            mysqli_stmt_execute($sizes_stmt);
            $sizes_result = mysqli_stmt_get_result($sizes_stmt);
            while ($sizes_result && ($srow = mysqli_fetch_assoc($sizes_result))) {
                $sizes[] = $srow['size'];
            }
            mysqli_stmt_close($sizes_stmt);
        }

        // 3. Cancel any order that isn't already completed/cancelled and looks
        //    like it includes this product, refunding wallet-paid orders.
        $orders_result = mysqli_query($conn, "SELECT * FROM orders WHERE payment_status NOT IN ('completed', 'cancelled')");
        if ($orders_result) {
            while ($order = mysqli_fetch_assoc($orders_result)) {
                $order_id = (int)$order['id'];
                $order_total_products = $order['total_products'];
                $user_id_order = $order['user_id'];
                $method = $order['method'];
                $order_total = floatval($order['total_price']);

                $found = false;
                foreach ($sizes as $sz) {
                    if ($sz !== '' && strpos($order_total_products, $sz) !== false) {
                        $found = true;
                        break;
                    }
                }
                if (!$found) {
                    $found = strpos($order_total_products, $actual_name) !== false;
                }

                if ($found && strtolower($order['payment_status']) !== 'cancelled') {
                    $cancel_reason = 'Order cancelled: Product deleted by admin.';
                    $cstmt = mysqli_prepare($conn, "UPDATE orders SET payment_status = 'cancelled', cancel_reason = ? WHERE id = ?");
                    if ($cstmt) {
                        mysqli_stmt_bind_param($cstmt, 'si', $cancel_reason, $order_id);
                        mysqli_stmt_execute($cstmt);
                        mysqli_stmt_close($cstmt);
                    }
                    if (strtolower($method) === 'wallet balance') {
                        $rstmt = mysqli_prepare($conn, "UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
                        if ($rstmt) {
                            mysqli_stmt_bind_param($rstmt, 'di', $order_total, $user_id_order);
                            mysqli_stmt_execute($rstmt);
                            mysqli_stmt_close($rstmt);
                        }
                    }
                }
            }
        }

        // 4. Delete the product image file, if any
        if (!empty($image)) {
            $image_file = CHATBOT_UPLOAD_DIR . $image;
            if (file_exists($image_file)) {
                @unlink($image_file);
            }
        }

        // 5-8. Delete linked sizes, ingredients, preference groups, extra groups
        foreach (['product_sizes', 'product_ingredients', 'product_preference_groups', 'product_extra_groups'] as $table) {
            $dstmt = mysqli_prepare($conn, "DELETE FROM `$table` WHERE product_id = ?");
            if ($dstmt) {
                mysqli_stmt_bind_param($dstmt, 'i', $delete_id);
                mysqli_stmt_execute($dstmt);
                mysqli_stmt_close($dstmt);
            }
        }

        // 9. Delete the product itself
        $final_stmt = mysqli_prepare($conn, "DELETE FROM products WHERE id = ?");
        if (!$final_stmt) {
            return ['success' => false, 'message' => 'Database error deleting the product: ' . mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($final_stmt, 'i', $delete_id);
        $ok = mysqli_stmt_execute($final_stmt);
        mysqli_stmt_close($final_stmt);

        if ($ok && function_exists('log_admin_activity')) {
            $admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
            log_admin_activity($admin_id, 'Delete Product', 'Deleted product via chatbot: ' . $actual_name);
        }

        return [
            'success' => $ok,
            'message' => $ok
                ? "🗑️ Product \"$actual_name\" deleted - related carts cleared and any pending orders cancelled (wallet payments refunded)."
                : 'Failed to delete product.',
        ];
    }

    // ==================== INGREDIENT INVENTORY (prepared statements) ====================
    // Mirrors admin_inventory.php's ==================== ADD NEW INGREDIENT ====================
    // handler: same required fields, same allowed category/unit values, same table.
    function execute_add_ingredient($conn, $entities) {
        $allowed_categories = ['consumable', 'packaging'];
        $allowed_units = ['grams', 'ml', 'pieces', 'cups'];

        $name = trim($entities['ingredient_name'] ?? '');
        $category = strtolower(trim($entities['category'] ?? ''));
        if (in_array($category, ['drink', 'drinks', 'beverage', 'beverages'], true)) {
            $category = 'consumable';
        }
        $unit = strtolower(trim($entities['unit'] ?? ''));
        $quantity = isset($entities['quantity']) ? floatval($entities['quantity']) : 0.0;
        $min_stock = isset($entities['min_stock_level']) ? floatval($entities['min_stock_level']) : 0.0;

        if ($name === '' || $category === '' || $unit === '') {
            return ['success' => false, 'message' => 'I need at least the ingredient name, category (consumable or packaging), and unit (grams, ml, pieces, or cups).'];
        }
        if (!in_array($category, $allowed_categories, true)) {
            return ['success' => false, 'message' => 'Category must be either "consumable" or "packaging".'];
        }
        if (!in_array($unit, $allowed_units, true)) {
            return ['success' => false, 'message' => 'Unit must be one of: grams, ml, pieces, cups.'];
        }
        if ($quantity < 0 || $min_stock < 0) {
            return ['success' => false, 'message' => "Stock and alert level can't be negative."];
        }
        if (mb_strlen($name) > 255) {
            $name = mb_substr($name, 0, 255);
        }

        $stmt = mysqli_prepare($conn, "INSERT INTO inventory (ingredient_name, category, quantity, unit, min_stock_level) VALUES (?, ?, ?, ?, ?)");
        if (!$stmt) {
            return ['success' => false, 'message' => 'Database error preparing to add the ingredient: ' . mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($stmt, 'ssdsd', $name, $category, $quantity, $unit, $min_stock);
        $ok = mysqli_stmt_execute($stmt);
        if (!$ok) {
            $err = mysqli_stmt_error($stmt);
            mysqli_stmt_close($stmt);
            return ['success' => false, 'message' => 'Failed to add ingredient: ' . $err];
        }
        mysqli_stmt_close($stmt);

        if (function_exists('log_admin_activity')) {
            $admin_id = isset($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : 1;
            log_admin_activity($admin_id, 'Add Ingredient', 'Added new ingredient via chatbot: ' . $name);
        }

        return [
            'success' => true,
            'message' => "✅ Ingredient \"$name\" ($category) added - opening stock " . number_format($quantity, 2) . " $unit, low-stock alert at " . number_format($min_stock, 2) . " $unit.",
        ];
    }

    // Builds a human-readable confirmation question for a pending destructive/mutating action.
    function build_confirmation_text($intent, $entities, $conn = null) {
        switch ($intent) {
            case 'add_product':
                $name = $entities['product_name'] ?? '(unnamed)';
                $variants = $entities['variants'] ?? [[
                    'size' => $entities['size'] ?? '',
                    'price' => $entities['price'] ?? 0,
                    'stock' => $entities['stock'] ?? 0,
                ]];
                $summary = [];
                foreach ($variants as $variant) {
                    if (is_array($variant)) {
                        $summary[] = ($variant['size'] ?? 'size?') . ' — ₱' . number_format((float)($variant['price'] ?? 0), 2)
                            . ', stock ' . (int)($variant['stock'] ?? 0);
                    }
                }
                return "Add product \"$name\" with " . implode('; ', $summary) . "? Reply \"yes\" to confirm or \"no\" to cancel.";
            case 'edit_price':
                $name = $entities['product_name'] ?? '(unknown product)';
                $price = isset($entities['price']) ? '₱' . number_format(floatval($entities['price']), 2) : 'unknown price';
                return "Change the price of \"$name\" to $price? Reply \"yes\" to confirm or \"no\" to cancel.";
            case 'delete_product':
                $name = $entities['product_name'] ?? '(unknown product)';
                return "Are you sure you want to DELETE \"$name\"? This cannot be undone and will cancel any pending orders for it. Reply \"yes\" to confirm or \"no\" to cancel.";
            case 'add_ingredient':
                $name = $entities['ingredient_name'] ?? '(unnamed)';
                $category = $entities['category'] ?? 'unspecified category';
                $unit = $entities['unit'] ?? 'unit';
                $qty = isset($entities['quantity']) ? $entities['quantity'] : 0;
                return "Add ingredient \"$name\" ($category), opening stock $qty $unit? Reply \"yes\" to confirm or \"no\" to cancel.";
            case 'create_voucher':
                $name = $entities['name'] ?? '(unnamed)';
                $trigger = $entities['trigger_type'] ?? 'unspecified trigger';
                $condition = $entities['condition_type'] ?? 'none';
                $threshold = $entities['threshold_value'] ?? 0;
                $rewardType = $entities['reward_type'] ?? 'unspecified reward';
                $amount = $entities['reward_amount'] ?? 0;
                $reward = $rewardType === 'fixed' ? '₱' . number_format((float)$amount, 2) . ' off'
                    : ($rewardType === 'percentage' ? number_format((float)$amount, 2) . '% off' : str_replace('_', ' ', $rewardType));
                $eligibility = $condition === 'spend' ? 'after spending ₱' . number_format((float)$threshold, 2)
                    : ($condition === 'products' ? 'after buying ' . (int)$threshold . ' products' : 'without an order threshold');
                return "Create automatic campaign \"$name\": $trigger trigger, $eligibility, reward $reward, minimum redemption spend ₱"
                    . number_format((float)($entities['minimum_spend'] ?? 0), 2) . ', valid ' . (int)($entities['validity_days'] ?? 14)
                    . " days? Reply \"yes\" to confirm or \"no\" to cancel.";
            case 'edit_loyalty_settings':
                $settings = loyalty_get_settings($conn);
                foreach (['points_per_wallet_peso', 'points_voucher_cost', 'points_voucher_percent', 'points_voucher_minimum_spend', 'points_voucher_validity_days'] as $key) {
                    if (isset($entities[$key])) {
                        $settings[$key] = $entities[$key];
                    }
                }
                return 'Update points redemption to ' . $settings['points_per_wallet_peso'] . ' points per ₱1; '
                    . $settings['points_voucher_cost'] . ' points for ' . $settings['points_voucher_percent']
                    . '% off (minimum spend ₱' . number_format((float)$settings['points_voucher_minimum_spend'], 2)
                    . ', valid ' . $settings['points_voucher_validity_days'] . ' days)? Reply "yes" to confirm or "no" to cancel.';
        }
        return 'Confirm this action? Reply "yes" or "no".';
    }

    // ==================== FORECAST (Qwen3 reasoning) ====================
    // orders.placed_on is a VARCHAR like "28-Apr-2026", so we must parse it with
    // STR_TO_DATE before grouping/filtering. We also fill in $0 for any day with
    // no completed orders so averages and growth rates aren't skewed by gaps.
    function generate_forecast($conn, $history = []) {
        $result = mysqli_query($conn, "
            SELECT STR_TO_DATE(placed_on, '%d-%b-%Y') as d, SUM(total_price) as rev
            FROM orders
            WHERE payment_status = 'completed'
              AND STR_TO_DATE(placed_on, '%d-%b-%Y') >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY d
            ORDER BY d ASC
        ") or die('Query failed: ' . mysqli_error($conn));

        $daily = [];
        while ($r = mysqli_fetch_assoc($result)) {
            if ($r['d'] === null) continue;
            $daily[$r['d']] = (float)$r['rev'];
        }

        if (empty($daily)) {
            return [
                'type' => 'forecast',
                'title' => '📈 Sales Forecast',
                'message' => 'There is not enough sales history yet to generate a reliable forecast.',
                'data' => [],
            ];
        }

        // Build a complete 30-day series (missing days = 0) for accurate math.
        $series = [];
        for ($i = 29; $i >= 0; $i--) {
            $d = date('Y-m-d', strtotime("-$i days"));
            $series[$d] = $daily[$d] ?? 0.0;
        }

        $today_key = date('Y-m-d');
        $yesterday_key = date('Y-m-d', strtotime('-1 day'));

        $last7 = array_slice($series, -7, 7, true);
        $prev7 = array_slice($series, -14, 7, true);
        $last7_total = array_sum($last7);
        $prev7_total = array_sum($prev7);
        $last7_avg = $last7_total / 7;
        $prev7_avg = $prev7_total / 7;
        $growth_pct = $prev7_avg > 0 ? (($last7_avg - $prev7_avg) / $prev7_avg) * 100 : 0;

        $best_day = array_search(max($series), $series);
        $worst_day = array_search(min($series), $series);

        // Naive next-7-day projection: recent average adjusted by the observed
        // growth rate (clamped so one wild day doesn't blow up the estimate).
        // This isn't a substitute for Qwen's reasoning below, but it gives
        // concrete numbers for any later "explain that more" follow-up.
        $growth_factor = 1 + max(-0.5, min(0.5, $growth_pct / 100));
        $projection = [];
        for ($i = 1; $i <= 7; $i++) {
            $d = date('Y-m-d', strtotime("+$i days"));
            $projection[$d] = round($last7_avg * $growth_factor, 2);
        }
        $projection_avg = array_sum($projection) / 7;

        $history_lines = [];
        foreach ($series as $d => $rev) {
            $history_lines[] = "$d: ₱" . number_format($rev, 2);
        }
        $history_text = implode("\n", $history_lines);

        $stats_text = sprintf(
            "Today (%s): ₱%s\nYesterday (%s): ₱%s\nLast 7 days total: ₱%s (avg ₱%s/day)\nPrevious 7 days total: ₱%s (avg ₱%s/day)\nWeek-over-week growth: %s%%\nBest day in last 30: %s (₱%s)\nWorst day in last 30: %s (₱%s)\nNaive next-7-day projection: avg ₱%s/day",
            $today_key, number_format($series[$today_key] ?? 0, 2),
            $yesterday_key, number_format($series[$yesterday_key] ?? 0, 2),
            number_format($last7_total, 2), number_format($last7_avg, 2),
            number_format($prev7_total, 2), number_format($prev7_avg, 2),
            number_format($growth_pct, 1),
            $best_day, number_format($series[$best_day], 2),
            $worst_day, number_format($series[$worst_day], 2),
            number_format($projection_avg, 2)
        );

        $system = "You are a data analyst for an e-commerce admin dashboard (a coffee shop). You are given "
                . "the exact daily revenue for the last 30 days plus pre-computed stats (weekly totals, "
                . "growth rate, best/worst day, and a naive next-7-day projection). Reason briefly about the "
                . "trend using the real numbers, then give a short, realistic forecast for the next 7 days, "
                . "referencing specific figures (today's or yesterday's revenue, the growth rate, the "
                . "projected average, etc.) where it helps. Keep the answer under 160 words, plain "
                . "conversational text, no markdown headers. If the admin's earlier messages ask you to "
                . "build on a previous forecast or go deeper, use the same numbers to give a more detailed, "
                . "specific answer rather than repeating your first summary.";

        $messages = build_messages_with_history(
            $system,
            $history,
            "Daily revenue for the last 30 days:\n$history_text\n\nComputed stats:\n$stats_text\n\nForecast the next 7 days for me."
        );

        $forecast_text = call_ollama($messages, false, OLLAMA_TIMEOUT_FORECAST);
        if (!$forecast_text) {
            $forecast_text = "Forecast reasoning is temporarily unavailable (the AI service could not be reached in time). "
                           . "Check that \"ollama serve\" is running and that the \"" . OLLAMA_MODEL . "\" model is pulled "
                           . "(ollama pull qwen3:4b). You can also test connectivity directly at "
                           . "admin_chatbot.php?action=ollama_ping, and check your PHP error log for the exact reason. "
                           . "In the meantime, here are the raw numbers: $stats_text";
        }

        // Remember the FULL numbers (not just the condensed paragraph above) so a
        // later "explain that forecast more" has real data to reason with.
        remember_last_report('forecast', [$stats_text, "Full 30-day daily revenue:\n$history_text"]);

        return [
            'type' => 'forecast',
            'title' => '📈 Sales Forecast',
            'message' => $forecast_text,
            'data' => [
                ['metric' => 'Today', 'value' => '₱' . number_format($series[$today_key] ?? 0, 2)],
                ['metric' => 'Yesterday', 'value' => '₱' . number_format($series[$yesterday_key] ?? 0, 2)],
                ['metric' => 'Last 7 days avg/day', 'value' => '₱' . number_format($last7_avg, 2)],
                ['metric' => 'Previous 7 days avg/day', 'value' => '₱' . number_format($prev7_avg, 2)],
                ['metric' => 'Week-over-week growth', 'value' => number_format($growth_pct, 1) . '%'],
                ['metric' => 'Best day (30d)', 'value' => $best_day . ' (₱' . number_format($series[$best_day], 2) . ')'],
                ['metric' => 'Worst day (30d)', 'value' => $worst_day . ' (₱' . number_format($series[$worst_day], 2) . ')'],
            ],
        ];
    }

    // ==================== GENERAL / OPEN-ENDED REASONING (Qwen3) ====================
    // Handles greetings, open-ended questions, and - importantly - follow-ups about
    // anything the bot already showed (forecasts, stock levels, sales, etc). Now
    // also injects the exact numbers behind the last report, so a follow-up like
    // "explain that more" or "why is that low" has real data instead of just the
    // AI's previous, already-condensed sentence.
    function generate_general_answer($query, $history = []) {
        $context_block = recall_last_report_text();

        $system = 'You are a helpful, analytical assistant embedded in a coffee shop\'s e-commerce admin '
                . 'dashboard. Answer the admin\'s question directly and briefly, reasoning through it '
                . 'yourself rather than reciting a script. You have the recent conversation as context, '
                . 'including any data (forecasts, stock levels, ingredient levels, sales figures, etc.) you '
                . 'already showed the admin - if the admin asks you to explain, elaborate on, or reason '
                . 'further about something you said earlier (a forecast, sales numbers, today vs yesterday, '
                . 'etc.), use the exact figures provided below (if any) to give a specific, detailed answer '
                . 'instead of a generic one. Never claim you lack detail if numbers are provided below - dig '
                . 'into them. If it is a plain greeting with no prior context, greet back and mention you can '
                . 'help with inventory, ingredients, sales, orders, analytics, customers, forecasts, and '
                . 'product management.';

        if ($context_block) {
            $system .= "\n\n" . $context_block;
        }

        $messages = build_messages_with_history($system, $history, $query);

        $answer = call_ollama($messages, false, OLLAMA_TIMEOUT_GENERAL);
        if (!$answer) {
            $answer = "I can help you with:\n"
                    . "📦 Inventory & Stock Management\n"
                    . "🧂 Ingredient Inventory\n"
                    . "💰 Sales & Revenue Reports\n"
                    . "📋 Order Status & Tracking\n"
                    . "👑 Best Sellers & Analytics\n"
                    . "👥 Customer Information\n"
                    . "📈 Sales Forecasts\n"
                    . "🛠️ Add / Edit / Delete Products\n"
                    . "🎁 Create auto vouchers and update points redemption\n\n"
                    . 'Try asking: "Show low stock", "Create a welcome voucher", or "Change points redemption".'
                    . "\n\n(The AI reasoning service didn't respond in time - check your PHP error log, "
                    . "or test admin_chatbot.php?action=ollama_ping to diagnose.)";
        }

        return [
            'type' => 'general',
            'title' => '🤖 Admin Assistant',
            'message' => $answer,
            'data' => [],
        ];
    }

    $response = [];
    $chat_history = get_chat_history();

    // ==================== STEP 1: RESOLVE A PENDING CONFIRMATION FIRST ====================
    if (isset($_SESSION['pending_action']) && preg_match('/^\s*(yes|confirm|y|ok(ay)?|sure|do it|proceed|go ahead)\b/i', $query)) {
        $pending = $_SESSION['pending_action'];
        unset($_SESSION['pending_action']);

        switch ($pending['intent']) {
            case 'add_product':
                $result = execute_add_product($conn, $pending['entities']);
                break;
            case 'edit_price':
                $result = execute_edit_price($conn, $pending['entities']);
                break;
            case 'delete_product':
                $result = execute_delete_product($conn, $pending['entities']);
                break;
            case 'add_ingredient':
                $result = execute_add_ingredient($conn, $pending['entities']);
                break;
            case 'create_voucher':
                $result = execute_create_voucher_campaign($conn, $pending['entities']);
                break;
            case 'edit_loyalty_settings':
                $result = execute_update_loyalty_settings($conn, $pending['entities']);
                break;
            default:
                $result = ['success' => false, 'message' => 'Nothing pending to confirm.'];
        }

        $response = [
            'type' => 'command',
            'title' => $result['success'] ? '✅ Done' : '⚠️ Action Failed',
            'message' => $result['message'],
            'data' => [],
        ];
    } elseif (isset($_SESSION['pending_action']) && preg_match('/^\s*(no|cancel|nevermind|never mind|stop)\b/i', $query)) {
        unset($_SESSION['pending_action']);
        $response = [
            'type' => 'command',
            'title' => '❎ Cancelled',
            'message' => 'Okay, I cancelled that action.',
            'data' => [],
        ];
    } else {
        // ==================== STEP 2: CLASSIFY INTENT ====================
        // The widget's quick-action buttons always send one of a fixed set of
        // strings (see CHATBOT_QUICK_ACTIONS at the top of this file). For
        // those, skip the AI classifier entirely - it's faster, and it can't
        // misfire the way the LLM sometimes did (e.g. reading "check
        // ingredient stock" as the "inventory" intent because both mention
        // "stock"). Anything else the admin types still goes through Qwen,
        // with a regex safety net afterward for the same ingredient/inventory
        // mix-up.
        $query_key = strtolower(trim($query));
        if (isset(CHATBOT_QUICK_ACTIONS[$query_key])) {
            $intent = CHATBOT_QUICK_ACTIONS[$query_key];
            $entities = [];
        } else {
            $classified = qwen_classify($query, $chat_history);
            if ($classified !== null) {
                $intent = $classified['intent'];
                $entities = $classified['entities'];
            } else {
                $intent = classify_intent_fallback($query);
                $entities = [];
            }
            $intent = normalize_ingredient_intent($query, $intent);
        }
        if (preg_match('/\b(create|make|generate|add)\b.*\bvoucher\b/i', $query)) {
            $intent = 'create_voucher';
        } elseif (preg_match('/\b(edit|change|update|set)\b.*\b(points?|redemption|redeem)\b/i', $query)) {
            $intent = 'edit_loyalty_settings';
        }

        // Backstop product commands with regex extraction so a shaky LLM
        // extraction still lets the command go through.
        if (in_array($intent, ['add_product', 'edit_price', 'delete_product'], true)) {
            // Ollama's format=json sometimes fills a field it couldn't
            // confidently extract with 0 instead of omitting it (a schema-
            // filling artifact). A real price is never 0, so an isset()-but-
            // zero price from Qwen would otherwise block merge_entities()
            // from letting the (correct) regex-extracted price through.
            // Clear it first so the fallback can do its job.
            if (isset($entities['price']) && floatval($entities['price']) <= 0) {
                unset($entities['price']);
            }
            if ($intent === 'add_product' && isset($entities['stock']) && (int)$entities['stock'] <= 0) {
                unset($entities['stock']);
            }

            $entities = merge_entities($entities, regex_extract_product_entities($intent, $query));
            if (isset($entities['price'])) {
                $entities['price'] = clean_number($entities['price']);
            }
        } elseif ($intent === 'add_ingredient') {
            $entities = merge_entities($entities, regex_extract_ingredient_entities($query));
            if (isset($entities['quantity'])) {
                $entities['quantity'] = clean_number($entities['quantity']);
            }
            if (isset($entities['min_stock_level'])) {
                $entities['min_stock_level'] = clean_number($entities['min_stock_level']);
            }
        } elseif (in_array($intent, ['create_voucher', 'edit_loyalty_settings'], true)) {
            $fallbackEntities = regex_extract_reward_entities($intent, $query);
            $entities = array_merge($entities, $fallbackEntities);
            $numericFields = $intent === 'create_voucher'
                ? ['threshold_value', 'reward_amount', 'minimum_spend', 'validity_days']
                : ['points_per_wallet_peso', 'points_voucher_cost', 'points_voucher_percent', 'points_voucher_minimum_spend', 'points_voucher_validity_days'];
            foreach ($numericFields as $field) {
                if (isset($entities[$field]) && is_numeric($entities[$field]) && (float)$entities[$field] <= 0
                    && !array_key_exists($field, $fallbackEntities)) {
                    unset($entities[$field]);
                }
            }
        }

        // ==================== INVENTORY QUERIES (finished products) ====================
        if ($intent === 'inventory') {
            $low_stock = mysqli_query($conn, "
                SELECT p.name, s.size, s.stock
                FROM product_sizes s
                JOIN products p ON s.product_id = p.id
                WHERE s.stock <= 10 AND s.is_active = 1
                ORDER BY s.stock ASC
                LIMIT 10
            ") or die('Query failed: ' . mysqli_error($conn));

            $items = [];
            while ($row = mysqli_fetch_assoc($low_stock)) {
                $items[] = [
                    'product' => htmlspecialchars($row['name']),
                    'size' => htmlspecialchars($row['size']),
                    'stock' => (int)$row['stock'],
                    'status' => $row['stock'] <= 5 ? 'Critical' : 'Low',
                ];
            }

            if (!empty($items)) {
                remember_last_report('inventory', array_map(
                    fn($i) => "{$i['product']} ({$i['size']}): {$i['stock']} units - {$i['status']}",
                    $items
                ));
                $response = [
                    'type' => 'inventory',
                    'title' => '📦 Low Stock Alert',
                    'message' => 'Found ' . count($items) . ' products with low stock:',
                    'data' => $items,
                    'action' => 'Visit Products page to restock',
                ];
            } else {
                $response = [
                    'type' => 'inventory',
                    'title' => '✅ Inventory Status',
                    'message' => 'Great news! All products are well stocked. No items below 10 units.',
                    'data' => [],
                ];
            }
        }

        // ==================== INGREDIENT INVENTORY (raw materials/packaging) ====================
        elseif ($intent === 'ingredients') {
            $ing_result = mysqli_query($conn, "
                SELECT id, ingredient_name, category, quantity, unit, min_stock_level
                FROM inventory
                ORDER BY category ASC, ingredient_name ASC
            ") or die('Query failed: ' . mysqli_error($conn));

            $items = [];
            $low_count = 0;
            while ($row = mysqli_fetch_assoc($ing_result)) {
                $qty = (float)$row['quantity'];
                $min = (float)$row['min_stock_level'];
                $is_low = $qty <= $min;
                if ($is_low) $low_count++;
                $items[] = [
                    'name' => htmlspecialchars($row['ingredient_name']),
                    'category' => htmlspecialchars(ucfirst($row['category'])),
                    'quantity' => number_format($qty, 2),
                    'unit' => htmlspecialchars($row['unit']),
                    'min_stock_level' => number_format($min, 2),
                    'status' => $is_low ? 'Low' : 'OK',
                ];
            }

            if (!empty($items)) {
                remember_last_report('ingredients', array_map(
                    fn($i) => "{$i['name']} ({$i['category']}): {$i['quantity']} {$i['unit']} on hand, alert at {$i['min_stock_level']} {$i['unit']} - {$i['status']}",
                    $items
                ));
            }

            $response = [
                'type' => 'ingredients',
                'title' => '🧂 Ingredient Inventory',
                'message' => !empty($items)
                    ? ('Tracking ' . count($items) . ' ingredient(s), ' . $low_count . ' running low:')
                    : 'No ingredients are being tracked yet. Add one from the Inventory page, or tell me something like "add ingredient Milk category drinks unit ml quantity 5000 min stock 1000".',
                'data' => $items,
                'action' => $low_count > 0 ? 'Visit the Inventory page to restock' : null,
            ];
        }

        // ==================== SALES/REVENUE QUERIES ====================
        elseif ($intent === 'sales') {
            $today = date('Y-m-d');
            $yesterday = date('Y-m-d', strtotime('-1 day'));
            $this_month = date('Y-m');
            $this_week_start = date('Y-m-d', strtotime('monday this week'));

            $result = mysqli_query($conn, "SELECT SUM(total_price) as total, COUNT(*) as cnt FROM orders WHERE payment_status = 'completed' AND STR_TO_DATE(placed_on, '%d-%b-%Y') = '$today'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $today_revenue = $row && $row['total'] ? floatval($row['total']) : 0;
            $today_orders = $row ? (int)$row['cnt'] : 0;

            $result = mysqli_query($conn, "SELECT SUM(total_price) as total, COUNT(*) as cnt FROM orders WHERE payment_status = 'completed' AND STR_TO_DATE(placed_on, '%d-%b-%Y') = '$yesterday'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $yesterday_revenue = $row && $row['total'] ? floatval($row['total']) : 0;
            $yesterday_orders = $row ? (int)$row['cnt'] : 0;

            $result = mysqli_query($conn, "SELECT SUM(total_price) as total FROM orders WHERE payment_status = 'completed' AND STR_TO_DATE(placed_on, '%d-%b-%Y') >= '$this_week_start'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $week_revenue = $row && $row['total'] ? floatval($row['total']) : 0;

            $result = mysqli_query($conn, "SELECT SUM(total_price) as total FROM orders WHERE payment_status = 'completed' AND DATE_FORMAT(STR_TO_DATE(placed_on, '%d-%b-%Y'), '%Y-%m') = '$this_month'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $month_revenue = $row && $row['total'] ? floatval($row['total']) : 0;

            $result = mysqli_query($conn, "SELECT SUM(total_price) as total FROM orders WHERE payment_status = 'completed'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $total_revenue = $row && $row['total'] ? floatval($row['total']) : 0;

            $day_change_pct = $yesterday_revenue > 0
                ? (($today_revenue - $yesterday_revenue) / $yesterday_revenue) * 100
                : null;

            remember_last_report('sales', [
                "Today ($today): ₱" . number_format($today_revenue, 2) . " from $today_orders completed orders",
                "Yesterday ($yesterday): ₱" . number_format($yesterday_revenue, 2) . " from $yesterday_orders completed orders",
                $day_change_pct !== null
                    ? "Today vs yesterday change: " . number_format($day_change_pct, 1) . "%"
                    : "Today vs yesterday change: not comparable (yesterday had no completed sales)",
                "This week (since $this_week_start): ₱" . number_format($week_revenue, 2),
                "This month ($this_month): ₱" . number_format($month_revenue, 2),
                "All-time: ₱" . number_format($total_revenue, 2),
            ]);

            $response = [
                'type' => 'sales',
                'title' => '💰 Revenue Report',
                'message' => 'Here\'s your sales breakdown:',
                'data' => [
                    ['period' => 'Today', 'revenue' => $today_revenue, 'orders' => $today_orders],
                    ['period' => 'Yesterday', 'revenue' => $yesterday_revenue, 'orders' => $yesterday_orders],
                    ['period' => 'This Week', 'revenue' => $week_revenue],
                    ['period' => 'This Month', 'revenue' => $month_revenue],
                    ['period' => 'All Time', 'revenue' => $total_revenue],
                ],
                'currency' => '₱',
            ];
        }

        // ==================== ORDERS QUERIES ====================
        elseif ($intent === 'orders') {
            $statuses = ['pending', 'accepted', 'preparing', 'done preparing', 'out for delivery', 'completed', 'cancelled'];
            $status_data = [];

            foreach ($statuses as $status) {
                $status_escaped = mysqli_real_escape_string($conn, $status);
                $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM orders WHERE payment_status = '$status_escaped'") or die('Query failed: ' . mysqli_error($conn));
                $row = mysqli_fetch_assoc($result);
                $count = $row ? (int)$row['cnt'] : 0;
                if ($count > 0) {
                    $status_data[] = ['status' => ucfirst($status), 'count' => $count];
                }
            }

            $recent = mysqli_query($conn, "
                SELECT id, user_id, total_price, placed_on
                FROM orders
                WHERE payment_status IN ('pending', 'accepted', 'preparing')
                ORDER BY STR_TO_DATE(placed_on, '%d-%b-%Y') DESC
                LIMIT 5
            ") or die('Query failed: ' . mysqli_error($conn));

            $pending_orders = [];
            while ($row = mysqli_fetch_assoc($recent)) {
                $pending_orders[] = [
                    'order_id' => 'ORD-' . str_pad($row['id'], 5, '0', STR_PAD_LEFT),
                    'total' => (float)$row['total_price'],
                    'time' => date('M d, Y', strtotime($row['placed_on'])),
                ];
            }

            remember_last_report('orders', array_merge(
                array_map(fn($s) => "{$s['status']}: {$s['count']}", $status_data),
                array_map(fn($o) => "Pending: {$o['order_id']} - ₱" . number_format($o['total'], 2) . " ({$o['time']})", $pending_orders)
            ));

            $response = [
                'type' => 'orders',
                'title' => '📋 Order Status',
                'message' => 'Order breakdown by status:',
                'data' => $status_data,
                'recent' => $pending_orders,
                'total_orders' => array_sum(array_column($status_data, 'count')),
            ];
        }

        // ==================== ANALYTICS/PRODUCTS QUERIES ====================
        // Best-seller counting now matches admin_analytics.php exactly: pull
        // each completed order's free-text product list, strip everything
        // from the first "(" or "[" onward (removes "(1 x Regular)",
        // "[Preference: ...]", "[Extras: ...]"), then count occurrences in
        // PHP. The previous LEFT JOIN ... LIKE CONCAT('%', p.name, '%')
        // approach could over/under count when names overlapped.
        elseif ($intent === 'analytics') {
            $orders_res = mysqli_query($conn, "SELECT total_products FROM orders WHERE payment_status = 'completed'") or die('Query failed: ' . mysqli_error($conn));

            $product_counts = [];
            while ($row = mysqli_fetch_assoc($orders_res)) {
                $parts = explode(',', $row['total_products']);
                foreach ($parts as $part) {
                    $clean = trim($part);
                    $clean = preg_replace('/\s*[\(\[].*$/u', '', $clean);
                    $clean = trim($clean);
                    if ($clean === '') continue;
                    $product_counts[$clean] = ($product_counts[$clean] ?? 0) + 1;
                }
            }
            arsort($product_counts);
            $top5 = array_slice($product_counts, 0, 5, true);

            $products = [];
            foreach ($top5 as $name => $count) {
                $products[] = ['name' => htmlspecialchars($name), 'sold' => $count];
            }

            $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM products") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $total_products = $row ? (int)$row['cnt'] : 0;

            if (!empty($products)) {
                remember_last_report('analytics', array_map(fn($p) => "{$p['name']}: {$p['sold']} sold", $products));
            }

            $response = [
                'type' => 'analytics',
                'title' => '👑 Top 5 Best Sellers',
                'message' => 'Your most popular products:',
                'data' => !empty($products) ? $products : [['name' => 'No sales yet', 'sold' => 0]],
                'total_products' => $total_products,
            ];
        }

        // ==================== USER QUERIES ====================
        elseif ($intent === 'users') {
            $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE user_type = 'user'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $total_users = $row ? (int)$row['cnt'] : 0;

            $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE status = 'pending'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $pending_approval = $row ? (int)$row['cnt'] : 0;

            $result = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM users WHERE user_type = 'delivery_rider'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $total_riders = $row ? (int)$row['cnt'] : 0;

            $result = mysqli_query($conn, "SELECT SUM(wallet_balance) as total FROM users WHERE user_type = 'user'") or die('Query failed: ' . mysqli_error($conn));
            $row = mysqli_fetch_assoc($result);
            $total_wallets = $row && $row['total'] ? floatval($row['total']) : 0;

            remember_last_report('users', [
                "Total Customers: $total_users",
                "Pending Approval: $pending_approval",
                "Delivery Riders: $total_riders",
                "Total Wallet Balance: ₱" . number_format($total_wallets, 2),
            ]);

            $response = [
                'type' => 'users',
                'title' => '👥 Customer Statistics',
                'message' => 'Your customer base overview:',
                'data' => [
                    ['metric' => 'Total Customers', 'value' => $total_users],
                    ['metric' => 'Pending Approval', 'value' => $pending_approval],
                    ['metric' => 'Delivery Riders', 'value' => $total_riders],
                    ['metric' => 'Total Wallet Balance', 'value' => '₱' . number_format($total_wallets, 2)],
                ],
            ];
        }

        // ==================== HELP/FAQ QUERIES ====================
        elseif ($intent === 'help') {
            $faq = [
                'how to add a product' => ['steps' => [
                    '1. Go to "Manage Products" page',
                    '2. Fill in product name, price, and description',
                    '3. Upload a product image',
                    '4. Add sizes and stock quantities',
                    '5. Click "Add Product" button',
                    '✅ Your product is now live!',
                    '💡 Tip: tell me "add product <name> with size <size> price <amount> stock <quantity>". I can add up to 10 variants; you will still need to upload the photo afterward.',
                ]],
                'how to edit products' => ['steps' => [
                    '1. Go to "Manage Products" page',
                    '2. Click "Edit" button on the product card',
                    '3. Update product details',
                    '4. You can change image, price, description',
                    '5. Click "Save Changes"',
                    '✅ Product updated successfully!',
                    '💡 Tip: ask me "change the price of <name> to <amount>" to do it instantly.',
                ]],
                'how to delete product' => ['steps' => [
                    '1. Go to "Manage Products" page',
                    '2. Click "Delete" button on the product card',
                    '3. Confirm deletion',
                    '⚠️ All related orders will be cancelled and refunded',
                    '✅ Product removed from catalog',
                    '💡 Tip: ask me "delete product <name>" and I\'ll confirm with you first.',
                ]],
                'how to update stock' => ['steps' => [
                    '1. Go to "Manage Products" page',
                    '2. Click "Stock" button on the product card',
                    '3. Select the size from dropdown',
                    '4. Enter new stock quantity',
                    '5. Click "Update Stock"',
                    '✅ Inventory updated!',
                ]],
                'how to view orders' => ['steps' => [
                    '1. Go to "Orders" page from navigation',
                    '2. View all customer orders',
                    '3. Filter by status (pending, completed, etc)',
                    '4. Click order to see details',
                    '✅ Track order status and payment',
                ]],
                'how to manage users' => ['steps' => [
                    '1. Go to profile menu (top-right)',
                    '2. Click "Manage Users"',
                    '3. View all registered customers',
                    '4. See user details and purchase history',
                    '✅ Monitor your customer base',
                ]],
                'how to manage ingredients' => ['steps' => [
                    '1. Go to "Inventory" page from navigation',
                    '2. View all ingredients/packaging and their stock',
                    '3. Use the form on the left to add a new item',
                    '4. Click the pencil icon on a row to update its stock or alert level',
                    '✅ Ingredient inventory updated!',
                    '💡 Tip: ask me "check ingredients" or "add ingredient <name> category <consumable/packaging> unit <grams/ml/pieces/cups>" to do it from chat.',
                ]],
            ];

            $found = false;
            $query_lower = strtolower($query);
            foreach ($faq as $topic => $content) {
                if (strpos($query_lower, $topic) !== false) {
                    $response = [
                        'type' => 'help',
                        'title' => '📚 ' . ucfirst($topic),
                        'message' => 'Here are the steps:',
                        'data' => $content['steps'],
                    ];
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $response = [
                    'type' => 'help',
                    'title' => '📚 Common Tasks',
                    'message' => 'Try asking about:',
                    'data' => [
                        '• How to add a product?',
                        '• How to edit products?',
                        '• How to delete product?',
                        '• How to update stock?',
                        '• How to view orders?',
                        '• How to manage users?',
                        '• How to manage ingredients?',
                        '• Create an automatic voucher (Head Admin)',
                        '• Change points redemption (Head Admin)',
                    ],
                ];
            }
        }

        // ==================== FORECAST ====================
        elseif ($intent === 'forecast') {
            $response = generate_forecast($conn, $chat_history);
        }

        // ==================== PRODUCT & INGREDIENT COMMANDS ====================
        elseif (in_array($intent, ['add_product', 'edit_price', 'delete_product', 'add_ingredient', 'create_voucher', 'edit_loyalty_settings'], true)) {
            $missing = [];
            if ($intent === 'add_product') {
                if (empty($entities['product_name'])) $missing[] = 'product name';
                $variants = $entities['variants'] ?? [];
                $hasCompleteVariants = is_array($variants) && count($variants) > 0 && count($variants) <= 10;
                if ($hasCompleteVariants) {
                    foreach ($variants as $variant) {
                        if (!is_array($variant) || trim((string)($variant['size'] ?? '')) === ''
                            || !isset($variant['price']) || (float)$variant['price'] <= 0
                            || !isset($variant['stock']) || (int)$variant['stock'] <= 0) {
                            $hasCompleteVariants = false;
                            break;
                        }
                    }
                }
                if (!$hasCompleteVariants && !empty($entities['size'])
                    && isset($entities['price']) && (float)$entities['price'] > 0
                    && isset($entities['stock']) && (int)$entities['stock'] > 0) {
                    $entities['variants'] = [[
                        'size' => $entities['size'],
                        'price' => $entities['price'],
                        'stock' => $entities['stock'],
                    ]];
                    $variants = $entities['variants'];
                    $hasCompleteVariants = true;
                }
                if (!$hasCompleteVariants) {
                    if (empty($entities['size'])) $missing[] = 'size or size name';
                    if (!isset($entities['price']) || (float)$entities['price'] <= 0) $missing[] = 'variant price';
                    if (!isset($entities['stock']) || (int)$entities['stock'] <= 0) $missing[] = 'variant stock';
                }
                if (is_array($variants) && count($variants) > 10) {
                    $missing[] = 'no more than 10 variants';
                }
            } elseif ($intent === 'edit_price') {
                if (empty($entities['product_name'])) $missing[] = 'product name';
                if (empty($entities['price'])) $missing[] = 'new price';
            } elseif ($intent === 'delete_product') {
                if (empty($entities['product_name'])) $missing[] = 'product name';
            } elseif ($intent === 'add_ingredient') {
                if (empty($entities['ingredient_name'])) $missing[] = 'ingredient name';
                if (empty($entities['category'])) $missing[] = 'category (consumable or packaging)';
                if (empty($entities['unit'])) $missing[] = 'unit (grams, ml, pieces, or cups)';
            } elseif ($intent === 'create_voucher') {
                if (empty($entities['name'])) $missing[] = 'voucher campaign name';
                if (empty($entities['trigger_type'])) $missing[] = 'trigger (signup or order)';
                if (empty($entities['reward_type'])) $missing[] = 'reward type (fixed, percentage, free shipping, or free product)';
                if (in_array($entities['reward_type'] ?? '', ['fixed', 'percentage'], true) && empty($entities['reward_amount'])) $missing[] = 'reward amount';
                if (($entities['trigger_type'] ?? '') === 'order' && empty($entities['condition_type'])) $missing[] = 'eligibility condition (none, spend, or number of products)';
                if (($entities['condition_type'] ?? '') === 'spend' && (float)($entities['threshold_value'] ?? 0) <= 0) $missing[] = 'spend threshold';
                if (($entities['condition_type'] ?? '') === 'products' && (int)($entities['threshold_value'] ?? 0) <= 0) $missing[] = 'product count threshold';
                if (($entities['reward_type'] ?? '') === 'free_product' && (int)($entities['free_product_id'] ?? 0) <= 0) $missing[] = 'free product ID';
            } elseif ($intent === 'edit_loyalty_settings') {
                $settingKeys = ['points_per_wallet_peso', 'points_voucher_cost', 'points_voucher_percent', 'points_voucher_minimum_spend', 'points_voucher_validity_days'];
                if (count(array_intersect(array_keys($entities), $settingKeys)) === 0) $missing[] = 'the setting and its new value';
            }

            if (!empty($missing)) {
                $example = $intent === 'add_ingredient'
                    ? 'add ingredient Milk category consumable unit ml quantity 5000 min stock 1000'
                    : ($intent === 'add_product'
                        ? 'add product Strawberry Cake with size Slice price 120 stock 20'
                        : ($intent === 'create_voucher'
                            ? 'create voucher "Welcome Treat" for new signups: ₱100 off, minimum spend ₱500, valid 14 days'
                            : 'change points redemption to 5 points per ₱1 and a 100-point 20% voucher'));
                $response = [
                    'type' => 'command',
                    'title' => '✋ A Bit More Info Needed',
                    'message' => 'I still need: ' . implode(', ', $missing) . ". Please resend the command with that included (e.g. \"$example\").",
                    'data' => [],
                ];
            } else {
                if (in_array($intent, ['create_voucher', 'edit_loyalty_settings'], true)
                    && (!function_exists('is_head_admin') || !is_head_admin())) {
                    $response = [
                        'type' => 'command',
                        'title' => '🔒 Head Admin Only',
                        'message' => 'Only the Head Admin can create voucher campaigns or change points-redemption settings.',
                        'data' => [],
                    ];
                } else {
                    $_SESSION['pending_action'] = ['intent' => $intent, 'entities' => $entities];
                    $response = [
                        'type' => 'command',
                        'title' => '⚠️ Confirm Action',
                        'message' => build_confirmation_text($intent, $entities, $conn),
                        'data' => [],
                    ];
                }
            }
        }

        // ==================== GENERAL / REASONING (powered by Qwen3) ====================
        else {
            $response = generate_general_answer($query, $chat_history);
        }
    }

    // ==================== STEP 3: SAVE THIS EXCHANGE TO CONVERSATION MEMORY ====================
    push_chat_history('user', $query);
    push_chat_history('assistant', summarize_response_for_history($response));

    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// ==================== CHATBOT UI WIDGET ====================
// Only show chatbot if admin is logged in
if (!isset($_SESSION['admin_id'])) {
    exit;
}
require_once __DIR__ . '/app/helpers/site_customization.php';
$site_customization = get_site_customization($conn);
?>

<!-- Chatbot Widget CSS & HTML -->
<style>
    /* =====================================================================
       RESPONSIVE NOTES
       - Window height now adapts to the visible viewport (dvh + visualViewport),
         so it never runs off-screen on short screens or with a phone keyboard.
       - Quick actions become a single horizontally-scrollable row on phones /
         short screens so the message area keeps its space.
       - Widget classes are "cb-" prefixed and keyframes renamed so they can no
         longer collide with page CSS (e.g. .message, slideUp).
       - Inputs are 16px on phones to stop iOS zooming in on focus.
       Colors, gradients and overall look are unchanged.
       ===================================================================== */

    /* Chatbot Floating Button */
    .chatbot-fab {
        position: fixed;
        bottom: 24px;
        right: 24px;
        width: auto;
        max-width: calc(100vw - 32px);
        min-height: 52px;
        padding: 0 18px;
        border-radius: 999px;
        background: linear-gradient(135deg, #C6453E 0%, #B83A34 100%);
        color: #fff;
        border: none;
        cursor: pointer;
        font-size: 0.92rem;
        font-weight: 800;
        white-space: nowrap;
        display: flex;
        align-items: center;
        justify-content: center;
        box-shadow: 0 8px 24px rgba(198, 69, 62, 0.3);
        z-index: 999;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        animation: cbSlideUp 0.4s ease-out;
        -webkit-tap-highlight-color: transparent;
    }

    .chatbot-fab:focus-visible {
        outline: 3px solid #FFF2E0;
        outline-offset: 2px;
    }

    .chatbot-fab.active {
        background: linear-gradient(135deg, #5E1F13 0%, #3D1608 100%);
    }

    /* Chatbot Window */
    .chatbot-window {
        position: fixed;
        bottom: 100px;
        right: 24px;
        width: 380px;
        max-width: calc(100vw - 32px);
        height: 600px;
        height: min(600px, calc(100vh - 124px));
        height: min(600px, calc(var(--cb-vh, 100dvh) - 124px));
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: 20px;
        box-shadow: 0 12px 48px rgba(94, 31, 19, 0.2);
        border: 1.5px solid #F0E6D8;
        display: none;
        flex-direction: column;
        z-index: 999;
        animation: cbSlideUp 0.3s ease-out;
        overflow: hidden;
    }

    .chatbot-window.active {
        display: flex;
    }

    /* Chatbot Header */
    .chatbot-header {
        background: linear-gradient(135deg, #C6453E 0%, #B83A34 100%);
        color: #fff;
        padding: 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        flex-shrink: 0;
    }

    .chatbot-header-title {
        display: flex;
        align-items: center;
        gap: 10px;
        flex: 1;
        min-width: 0;
    }

    .chatbot-header-title h3 {
        margin: 0;
        font-size: 1.1rem;
        font-weight: 900;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .chatbot-header-title i {
        font-size: 1.4rem;
        flex-shrink: 0;
    }
    .chatbot-header-title .admin-chatbot-brand {
        width: 36px;
        height: 36px;
        object-fit: cover;
        border-radius: 50%;
        background: #fff;
        padding: 2px;
        flex: 0 0 36px;
    }

    .chatbot-close {
        background: none;
        border: none;
        color: #fff;
        cursor: pointer;
        font-size: 1.3rem;
        transition: all 0.2s;
        width: 36px;
        height: 36px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .chatbot-close:focus-visible {
        outline: 2px solid #FFF2E0;
        outline-offset: 2px;
        border-radius: 8px;
    }

    /* Chatbot Messages */
    .chatbot-messages {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        overscroll-behavior: contain;
        -webkit-overflow-scrolling: touch;
        padding: 16px;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .chatbot-messages::-webkit-scrollbar {
        width: 6px;
    }

    .chatbot-messages::-webkit-scrollbar-track {
        background: transparent;
    }

    .chatbot-messages::-webkit-scrollbar-thumb {
        background: rgba(198, 69, 62, 0.3);
        border-radius: 3px;
    }

    .chatbot-messages::-webkit-scrollbar-thumb:hover {
        background: rgba(198, 69, 62, 0.5);
    }

    .cb-message {
        display: flex;
        flex-shrink: 0;
        animation: cbFadeInUp 0.3s ease-out;
    }

    .cb-message.user {
        justify-content: flex-end;
    }

    /* column that holds the bubble + its timestamp */
    .cb-message-col {
        display: flex;
        flex-direction: column;
        align-items: flex-start;
        max-width: 85%;
        min-width: 0;
    }

    .cb-message.user .cb-message-col {
        align-items: flex-end;
    }

    .cb-message-content {
        max-width: 100%;
        padding: 12px 16px;
        border-radius: 14px;
        font-size: 0.95rem;
        line-height: 1.5;
        word-wrap: break-word;
        overflow-wrap: anywhere;
    }

    .cb-message.bot .cb-message-content {
        background: linear-gradient(135deg, #F0E6D8 0%, #FFF2E0 100%);
        color: #5E1F13;
        border-bottom-left-radius: 4px;
    }

    .cb-message.user .cb-message-content {
        background: linear-gradient(135deg, #C6453E 0%, #B83A34 100%);
        color: #fff;
        border-bottom-right-radius: 4px;
    }

    .cb-message-time {
        font-size: 0.75rem;
        color: #999;
        margin-top: 4px;
        padding: 0 8px;
    }

    /* Bot Response Styles */
    .bot-response-title {
        font-size: 1rem;
        font-weight: 900;
        color: #C6453E;
        margin-bottom: 8px;
    }

    .bot-response-text {
        font-size: 0.9rem;
        color: #664C47;
        margin-bottom: 10px;
        white-space: pre-line;
    }

    .bot-response-data {
        background: rgba(198, 69, 62, 0.08);
        border-left: 3px solid #C6453E;
        padding: 10px 12px;
        border-radius: 6px;
        font-size: 0.85rem;
        color: #5E1F13;
    }

    .bot-response-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 4px 12px;
        padding: 6px 0;
        border-bottom: 1px solid rgba(198, 69, 62, 0.1);
        flex-wrap: wrap;
    }

    .bot-response-item:last-child {
        border-bottom: none;
    }

    .bot-response-item-label {
        font-weight: 700;
        min-width: 0;
    }

    .bot-response-item-value {
        font-weight: 800;
        color: #C6453E;
    }

    /* Loading indicator */
    .cb-typing-indicator {
        display: flex;
        gap: 4px;
        align-items: center;
        padding: 12px 16px;
    }

    .cb-typing-dot {
        width: 8px;
        height: 8px;
        border-radius: 50%;
        background: #C6453E;
        animation: cbTypingAnimation 1.4s infinite;
    }

    .cb-typing-dot:nth-child(2) {
        animation-delay: 0.2s;
    }

    .cb-typing-dot:nth-child(3) {
        animation-delay: 0.4s;
    }

    @keyframes cbTypingAnimation {
        0%, 60%, 100% { opacity: 0.3; transform: translateY(0); }
        30% { opacity: 1; transform: translateY(-8px); }
    }

    /* Quick Actions */
    .cb-quick-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        padding: 12px 16px;
        border-bottom: 1px solid #F0E6D8;
        flex-shrink: 0;
    }

    .cb-quick-btn {
        padding: 10px 12px;
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(255, 242, 224, 0.1) 100%);
        border: 1.5px solid rgba(198, 69, 62, 0.2);
        border-radius: 8px;
        color: #5E1F13;
        font-family: inherit;
        font-weight: 700;
        font-size: 0.8rem;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 4px;
        text-align: center;
        min-height: 40px;
    }

    .cb-quick-btn:focus-visible {
        outline: 2px solid #C6453E;
        outline-offset: 2px;
    }

    /* Input Area */
    .chatbot-input-area {
        padding: 12px;
        padding-bottom: calc(12px + env(safe-area-inset-bottom, 0px));
        border-top: 1.5px solid #F0E6D8;
        display: flex;
        gap: 8px;
        background: #FEFDFB;
        flex-shrink: 0;
    }

    .chatbot-input {
        flex: 1;
        min-width: 0;
        border: 1.5px solid #F0E6D8;
        border-radius: 8px;
        padding: 10px 12px;
        font-family: inherit;
        font-size: 0.9rem;
        color: #5E1F13;
        transition: all 0.2s;
    }

    .chatbot-input:focus {
        outline: none;
        border-color: #C6453E;
        box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
    }

    .chatbot-send {
        background: linear-gradient(135deg, #C6453E 0%, #B83A34 100%);
        color: #fff;
        border: none;
        border-radius: 8px;
        width: 40px;
        height: 40px;
        flex-shrink: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.1rem;
        transition: all 0.2s;
    }

    .chatbot-send:focus-visible {
        outline: 2px solid #5E1F13;
        outline-offset: 2px;
    }

    /* Hover effects only where a real hover exists (no "stuck" hover after tapping) */
    @media (hover: hover) {
        .chatbot-fab:hover {
            transform: scale(1.1) translateY(-4px);
            box-shadow: 0 12px 32px rgba(198, 69, 62, 0.4);
        }

        .chatbot-close:hover {
            transform: rotate(90deg);
        }

        .cb-quick-btn:hover {
            background: linear-gradient(135deg, rgba(198, 69, 62, 0.15) 0%, rgba(255, 242, 224, 0.15) 100%);
            border-color: rgba(198, 69, 62, 0.4);
            transform: translateY(-2px);
        }

        .chatbot-send:hover {
            background: linear-gradient(135deg, #5E1F13 0%, #3D1608 100%);
            transform: scale(1.05);
        }
    }

    /* ---------- Tablets / small laptops ---------- */
    @media (max-width: 768px) {
        .chatbot-fab {
            bottom: 16px;
            right: 16px;
            min-height: 48px;
            padding: 0 15px;
            font-size: 0.85rem;
        }

        .chatbot-window {
            bottom: 84px;
            right: 16px;
            width: calc(100vw - 32px);
            max-width: 400px;
            height: 540px;
            height: min(540px, calc(100vh - 108px));
            height: min(540px, calc(var(--cb-vh, 100dvh) - 108px));
        }

        .chatbot-header {
            padding: 16px;
        }

        /* one scrollable row instead of a tall grid */
        .cb-quick-actions {
            display: flex;
            gap: 8px;
            padding: 10px 12px;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }

        .cb-quick-actions::-webkit-scrollbar {
            display: none;
        }

        .cb-quick-btn {
            flex: 0 0 auto;
            white-space: nowrap;
            padding: 10px 14px;
        }
    }

    /* ---------- Phones ---------- */
    @media (max-width: 480px) {
        .chatbot-window {
            left: 8px;
            right: 8px;
            bottom: 76px;
            width: auto;
            max-width: none;
            height: calc(100vh - 92px);
            height: calc(var(--cb-vh, 100dvh) - 92px);
            border-radius: 16px;
        }

        .chatbot-messages {
            padding: 12px;
        }

        .cb-message-col {
            max-width: 92%;
        }

        .cb-message-content {
            padding: 11px 14px;
        }

        .chatbot-close {
            width: 40px;
            height: 40px;
        }

        .chatbot-send {
            width: 44px;
            height: 44px;
        }

        /* 16px prevents iOS Safari from auto-zooming when the field is focused */
        .chatbot-input {
            font-size: 16px;
            padding: 11px 12px;
        }
    }

    /* ---------- Short screens (landscape phones, small laptops) ---------- */
    @media (max-height: 560px) {
        .chatbot-window {
            bottom: 76px;
            height: calc(100vh - 92px);
            height: calc(var(--cb-vh, 100dvh) - 92px);
        }

        .cb-quick-actions {
            display: flex;
            padding: 8px 12px;
            overflow-x: auto;
            scrollbar-width: none;
        }

        .cb-quick-actions::-webkit-scrollbar {
            display: none;
        }

        .cb-quick-btn {
            flex: 0 0 auto;
            white-space: nowrap;
            min-height: 36px;
            padding: 8px 12px;
        }

        .chatbot-header {
            padding: 10px 16px;
        }
    }

    @media (max-width: 360px) {
        .chatbot-fab {
            right: 12px;
            bottom: 12px;
        }

        .chatbot-window {
            left: 6px;
            right: 6px;
        }
    }

    /* Animations (cb-prefixed so they can't clash with a page's own keyframes) */
    @keyframes cbSlideUp {
        from {
            opacity: 0;
            transform: translateY(20px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @keyframes cbFadeInUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }
        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    @media (prefers-reduced-motion: reduce) {
        .chatbot-fab,
        .chatbot-window,
        .cb-message,
        .cb-typing-dot {
            animation: none !important;
        }
        .chatbot-fab,
        .chatbot-close,
        .cb-quick-btn,
        .chatbot-send {
            transition: none !important;
        }
    }
</style>
<?php echo site_customization_chatbot_css($site_customization); ?>

<!-- Chatbot HTML -->
<button type="button" class="chatbot-fab" id="chatbotFab" title="Need some help?" aria-label="Need some help? Open admin chat" aria-expanded="false" aria-controls="chatbotWindow">
    Need some help?
</button>

<div class="chatbot-window" id="chatbotWindow" role="dialog" aria-label="Admin Assistant">
    <!-- Header -->
    <div class="chatbot-header">
        <div class="chatbot-header-title">
            <img class="admin-chatbot-brand" src="<?php echo escapeOutput(site_customization_auth_image_url($site_customization['chatbot_image_path'], 'images/logos.png')); ?>" alt="">
            <h3>Admin Assistant</h3>
        </div>
        <button type="button" class="chatbot-close" id="chatbotClose" title="Close" aria-label="Close chat">×</button>
    </div>

    <!-- Quick Actions -->
    <div class="cb-quick-actions">
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('Show low stock')">
            <i class="fa-solid fa-exclamation-triangle"></i> Low Stock
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('How much revenue today?')">
            <i class="fa-solid fa-chart-line"></i> Sales Today
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('Top products')">
            <i class="fa-solid fa-crown"></i> Top Products
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('Show pending orders')">
            <i class="fa-solid fa-clock"></i> Pending Orders
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('Forecast sales for next week')">
            <i class="fa-solid fa-wand-magic-sparkles"></i> Forecast
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotQuery('Check ingredient stock')">
            <i class="fa-solid fa-flask"></i> Ingredients
        </button>
        <button type="button" class="cb-quick-btn" onclick="chatbotPrefill('Add product ')">
            <i class="fa-solid fa-plus"></i> Add Product
        </button>
    </div>

    <!-- Messages -->
    <div class="chatbot-messages" id="chatbotMessages">
        <div class="cb-message bot">
            <div class="cb-message-col">
                <div class="cb-message-content">
                    <div class="bot-response-title">👋 Hello, Admin!</div>
                    <div class="bot-response-text">I'm your AI assistant, now powered by a local Qwen3 model. I can help you with inventory, ingredients, sales, orders, analytics, forecasting, and managing products! Ask me a follow-up question anytime - I remember what we just talked about.</div>
                    <div class="bot-response-text" style="font-size: 0.8rem; margin-top: 8px;">Try asking:</div>
                    <div class="bot-response-data">
                        <div style="padding: 6px 0;">• Show low stock</div>
                        <div style="padding: 6px 0;">• Check ingredient stock</div>
                        <div style="padding: 6px 0;">• How much revenue today?</div>
                        <div style="padding: 6px 0;">• Forecast sales for next week</div>
                        <div style="padding: 6px 0;">• (after a forecast) Can you explain that more?</div>
                        <div style="padding: 6px 0;">• Add product Red Shirt price 599</div>
                        <div style="padding: 6px 0;">• Change the price of Red Shirt to 649</div>
                        <div style="padding: 6px 0;">• Delete product Red Shirt</div>
                        <div style="padding: 6px 0;">• Add ingredient Milk category consumable unit ml quantity 5000 min stock 1000</div>
                    </div>
                </div>
                <div class="cb-message-time"><?php echo date('H:i'); ?></div>
            </div>
        </div>
    </div>

    <!-- Input -->
    <div class="chatbot-input-area">
        <input type="hidden" id="chatbotCsrfToken" value="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
        <input type="text" class="chatbot-input" id="chatbotInput" placeholder="Ask me anything..." autocomplete="off" enterkeyhint="send" aria-label="Message">
        <button type="button" class="chatbot-send" id="chatbotSend" title="Send" aria-label="Send message">
            <i class="fa-solid fa-paper-plane"></i>
        </button>
    </div>
</div>

<script>
    const fab = document.getElementById('chatbotFab');
    const windowEl = document.getElementById('chatbotWindow');
    const closeBtn = document.getElementById('chatbotClose');
    const input = document.getElementById('chatbotInput');
    const sendBtn = document.getElementById('chatbotSend');
    const messagesContainer = document.getElementById('chatbotMessages');

    // Keep the window sized to the *visible* viewport (shrinks when a phone keyboard opens).
    // CSS falls back to 100dvh / 100vh when this isn't available.
    (function () {
        const vv = window.visualViewport;
        if (!vv) return;
        function syncViewportHeight() {
            document.documentElement.style.setProperty('--cb-vh', vv.height + 'px');
            if (windowEl.classList.contains('active')) {
                messagesContainer.scrollTop = messagesContainer.scrollHeight;
            }
        }
        vv.addEventListener('resize', syncViewportHeight);
        syncViewportHeight();
    })();

    function setChatOpen(open) {
        windowEl.classList.toggle('active', open);
        fab.classList.toggle('active', open);
        fab.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
            // on touch screens, don't auto-focus: it would pop the keyboard open and cover the chat
            if (window.matchMedia('(hover: hover)').matches) {
                input.focus();
            }
        }
    }

    // Toggle window
    fab.addEventListener('click', function() {
        setChatOpen(!windowEl.classList.contains('active'));
    });

    // Close window
    closeBtn.addEventListener('click', function() {
        setChatOpen(false);
    });

    // Send message on Enter
    input.addEventListener('keypress', function(e) {
        if (e.key === 'Enter' && this.value.trim()) {
            chatbotQuery(this.value);
            this.value = '';
        }
    });

    // Send button click
    sendBtn.addEventListener('click', function() {
        if (input.value.trim()) {
            chatbotQuery(input.value);
            input.value = '';
        }
    });

    // Prefill the input (used by quick actions that need more user-supplied detail,
    // e.g. "Add Product") instead of sending immediately.
    function chatbotPrefill(text) {
        input.value = text;
        input.focus();
    }

    // Send query to chatbot API
    function chatbotQuery(query) {
        // Add user message
        addMessage(query, 'user');
        input.value = '';

        // Show typing indicator
        showTypingIndicator();

        const csrfTokenEl = document.getElementById('chatbotCsrfToken');
        const csrfToken = csrfTokenEl ? csrfTokenEl.value : '';

        // Send to backend
        fetch('admin_chatbot.php?action=query', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'query=' + encodeURIComponent(query) + '&csrf_token=' + encodeURIComponent(csrfToken)
        })
        .then(res => res.json())
        .then(data => {
            removeTypingIndicator();
            if (data && data.error) {
                addMessage(data.error, 'bot');
                return;
            }
            displayBotResponse(data);
        })
        .catch(err => {
            removeTypingIndicator();
            addMessage('Sorry, I encountered an error. Please try again.', 'bot');
            console.error('Error:', err);
        });
    }

    // Wraps a bubble + timestamp in the same column structure the welcome message uses.
    function buildMessage(sender) {
        const messageDiv = document.createElement('div');
        messageDiv.className = 'cb-message ' + sender;

        const colDiv = document.createElement('div');
        colDiv.className = 'cb-message-col';

        const timeDiv = document.createElement('div');
        timeDiv.className = 'cb-message-time';
        timeDiv.textContent = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

        messageDiv.appendChild(colDiv);
        return { messageDiv, colDiv, timeDiv };
    }

    // Add message to chat
    function addMessage(text, sender) {
        const m = buildMessage(sender);

        const contentDiv = document.createElement('div');
        contentDiv.className = 'cb-message-content';
        contentDiv.textContent = text;

        m.colDiv.appendChild(contentDiv);
        m.colDiv.appendChild(m.timeDiv);
        messagesContainer.appendChild(m.messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Show typing indicator
    function showTypingIndicator() {
        const indicatorDiv = document.createElement('div');
        indicatorDiv.id = 'typingIndicator';
        indicatorDiv.className = 'cb-message bot';
        indicatorDiv.innerHTML = `
            <div class="cb-typing-indicator">
                <div class="cb-typing-dot"></div>
                <div class="cb-typing-dot"></div>
                <div class="cb-typing-dot"></div>
            </div>
        `;
        messagesContainer.appendChild(indicatorDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Remove typing indicator
    function removeTypingIndicator() {
        const indicator = document.getElementById('typingIndicator');
        if (indicator) indicator.remove();
    }

    // Display bot response
    function displayBotResponse(data) {
        const m = buildMessage('bot');

        let html = `<div class="cb-message-content">`;
        html += `<div class="bot-response-title">${data.title}</div>`;
        html += `<div class="bot-response-text">${data.message}</div>`;

        if (data.data && data.data.length > 0) {
            html += `<div class="bot-response-data">`;

            if (data.type === 'sales') {
                data.data.forEach(item => {
                    html += `<div class="bot-response-item">
                        <span class="bot-response-item-label">${item.period}</span>
                        <span class="bot-response-item-value">${data.currency || ''}${Number(item.revenue).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}${item.orders ? ` (${item.orders} orders)` : ''}</span>
                    </div>`;
                });
            } else if (data.type === 'inventory') {
                data.data.forEach(item => {
                    const statusColor = item.status === 'Critical' ? '#dc2626' : '#B83A34';
                    html += `<div class="bot-response-item" style="flex-wrap: wrap; gap: 8px;">
                        <span class="bot-response-item-label">${item.product} (${item.size})</span>
                        <span style="color: ${statusColor}; font-weight: 800;">${item.stock} units</span>
                    </div>`;
                });
            } else if (data.type === 'ingredients') {
                data.data.forEach(item => {
                    const statusColor = item.status === 'Low' ? '#dc2626' : '#059669';
                    html += `<div class="bot-response-item" style="flex-wrap: wrap; gap: 8px;">
                        <span class="bot-response-item-label">${item.name} <span style="opacity:.7; font-weight:600;">(${item.category})</span></span>
                        <span style="color: ${statusColor}; font-weight: 800;">${item.quantity} ${item.unit}${item.status === 'Low' ? ' ⚠️' : ''}</span>
                    </div>`;
                });
            } else if (data.type === 'analytics') {
                data.data.forEach((item, idx) => {
                    html += `<div class="bot-response-item">
                        <span class="bot-response-item-label">${idx + 1}. ${item.name}</span>
                        <span class="bot-response-item-value">${item.sold} sold</span>
                    </div>`;
                });
            } else if (data.type === 'orders') {
                data.data.forEach(item => {
                    html += `<div class="bot-response-item">
                        <span class="bot-response-item-label">${item.status}</span>
                        <span class="bot-response-item-value">${item.count}</span>
                    </div>`;
                });
                if (data.recent && data.recent.length > 0) {
                    html += `<div style="margin-top: 10px; padding-top: 10px; border-top: 1px solid rgba(198, 69, 62, 0.1);">`;
                    html += `<div style="font-weight: 700; margin-bottom: 6px;">Recent pending:</div>`;
                    data.recent.forEach(order => {
                        html += `<div style="font-size: 0.8rem; padding: 4px 0;">${order.order_id} - ₱${Number(order.total).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} (${order.time})</div>`;
                    });
                    html += `</div>`;
                }
            } else if (data.type === 'users') {
                data.data.forEach(item => {
                    html += `<div class="bot-response-item">
                        <span class="bot-response-item-label">${item.metric}</span>
                        <span class="bot-response-item-value">${item.value}</span>
                    </div>`;
                });
            } else if (data.type === 'forecast') {
                data.data.forEach(item => {
                    html += `<div class="bot-response-item">
                        <span class="bot-response-item-label">${item.metric}</span>
                        <span class="bot-response-item-value">${item.value}</span>
                    </div>`;
                });
            } else if (data.type === 'help') {
                data.data.forEach(step => {
                    html += `<div style="padding: 6px 0; line-height: 1.6;">${step}</div>`;
                });
            } else {
                data.data.forEach(item => {
                    html += `<div style="padding: 6px 0;">• ${item}</div>`;
                });
            }

            html += `</div>`;
        }

        if (data.tip) {
            html += `<div class="bot-response-text" style="font-size: 0.8rem; margin-top: 8px; color: #C6453E;">💡 ${data.tip}</div>`;
        }

        html += `</div>`;
        m.colDiv.innerHTML = html;
        m.colDiv.appendChild(m.timeDiv);

        messagesContainer.appendChild(m.messageDiv);
        messagesContainer.scrollTop = messagesContainer.scrollHeight;
    }

    // Escape to close
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && windowEl.classList.contains('active')) {
            setChatOpen(false);
        }
    });
</script>