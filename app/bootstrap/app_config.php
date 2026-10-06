<?php
// Shared environment-backed application bootstrap.
// Require production DB settings: no insecure fallbacks.
$required_env = ['DB_HOST', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];
$missing = [];
foreach ($required_env as $key) {
    $value = getenv($key);
    if ($value === false || $value === '') {
        $missing[] = $key;
    }
}

if (!empty($missing)) {
    // Variable names go to the server log only, never to the visitor
    error_log('Config error: missing required environment variables: ' . implode(', ', $missing));
    http_response_code(500);
    exit('Service temporarily unavailable. Please try again later.');
}

$app_env = getenv('APP_ENV') ?: 'development';
if ($app_env === 'production') {
    $app_base_url = getenv('APP_BASE_URL');
    $proof_dir = getenv('TOPUP_PROOF_DIR');
    if ($app_base_url === false || !filter_var($app_base_url, FILTER_VALIDATE_URL) || parse_url($app_base_url, PHP_URL_SCHEME) !== 'https'
        || $proof_dir === false || $proof_dir === '' || !is_dir($proof_dir) || !is_writable($proof_dir)) {
        error_log('Config error: production base URL or persistent top-up proof storage is missing or invalid.');
        http_response_code(500);
        exit('Service temporarily unavailable. Please try again later.');
    }
}

$db_host = getenv('DB_HOST');
$db_user = getenv('DB_USER');
$db_pass = getenv('DB_PASSWORD');
$db_name = getenv('DB_NAME');

// Make mysqli throw exceptions so we control what gets shown
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_connect($db_host, $db_user, $db_pass, $db_name);
    mysqli_set_charset($conn, 'utf8mb4');
} catch (mysqli_sql_exception $e) {
    // Details go to the server log only, never to the visitor
    error_log('Database connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Service temporarily unavailable. Please try again later.');
}

// ============= START SESSION =============
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if ($app_env === 'production') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

// ============= SECURITY HEADERS =============
if (!headers_sent()) {
    header('X-Frame-Options: SAMEORIGIN');
    header('X-Content-Type-Options: nosniff');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
}

// ============= CSRF TOKEN =============
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============= HELPER FUNCTIONS =============

if (!function_exists('escapeOutput')) {
    function escapeOutput($data) {
        return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('sanitizeInput')) {
    function sanitizeInput($data) {
        return trim(strip_tags($data));
    }
}

if (!function_exists('validateEmail')) {
    function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
}

if (!function_exists('validatePhone')) {
    function validatePhone($phone) {
        return preg_match('/^[0-9]{10,15}$/', $phone);
    }
}

if (!function_exists('validateCSRFToken')) {
    function validateCSRFToken($token) {
        return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }
}

if (!function_exists('getUserIP')) {
    function getUserIP() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            return $_SERVER['HTTP_CF_CONNECTING_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ips = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($ips[0]);
        } else {
            return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        }
    }
}

if (!function_exists('logSecurityEvent')) {
    function logSecurityEvent($event_type, $details = []) {
        $log_file = __DIR__ . '/logs/security.log';
        if (!is_dir(__DIR__ . '/logs')) {
            mkdir(__DIR__ . '/logs', 0755, true);
        }
        $log_entry = [
            'timestamp' => date('Y-m-d H:i:s'),
            'event_type' => $event_type,
            'ip_address' => getUserIP(),
            'details' => $details
        ];
        file_put_contents($log_file, json_encode($log_entry) . PHP_EOL, FILE_APPEND);
    }
}

// ============= ADDITIONAL HELPER FUNCTIONS =============

if (!function_exists('validateOrigin')) {
    function validateOrigin() {
        // Check if referrer matches current domain
        $referer = isset($_SERVER['HTTP_REFERER']) ? parse_url($_SERVER['HTTP_REFERER'], PHP_URL_HOST) : '';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';

        // Allow requests from same domain or no referrer (mobile apps, etc)
        if (empty($referer) || $referer === $host) {
            return true;
        }

        return false;
    }
}

if (!function_exists('isUserLoggedIn')) {
    function isUserLoggedIn() {
        return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
    }
}

if (!function_exists('redirectToLogin')) {
    function redirectToLogin() {
        header('Location: login.php');
        exit();
    }
}

if (!function_exists('redirectToHome')) {
    function redirectToHome() {
        header('Location: index.php');
        exit();
    }
}

if (!function_exists('getTimeAgo')) {
    function getTimeAgo($timestamp) {
        $time_ago = strtotime($timestamp);
        $current_time = time();
        $time_difference = $current_time - $time_ago;
        $seconds = $time_difference;
        $minutes = round($seconds / 60);
        $hours = round($seconds / 3600);
        $days = round($seconds / 86400);
        $weeks = round($seconds / 604800);
        $months = round($seconds / 2419200);
        $years = round($seconds / 29030400);

        if ($seconds <= 60) {
            return "just now";
        } elseif ($minutes <= 60) {
            return ($minutes == 1) ? "1 minute ago" : "$minutes minutes ago";
        } elseif ($hours <= 24) {
            return ($hours == 1) ? "1 hour ago" : "$hours hours ago";
        } elseif ($days <= 7) {
            return ($days == 1) ? "1 day ago" : "$days days ago";
        } elseif ($weeks <= 4.3) {
            return ($weeks == 1) ? "1 week ago" : "$weeks weeks ago";
        } elseif ($months <= 12) {
            return ($months == 1) ? "1 month ago" : "$months months ago";
        } else {
            return ($years == 1) ? "1 year ago" : "$years years ago";
        }
    }
}

if (!function_exists('generateOrderReference')) {
    function generateOrderReference() {
        return 'ORD-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    }
}

if (!function_exists('formatPhoneNumber')) {
    function formatPhoneNumber($phone) {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) >= 10) {
            return '+63' . substr($phone, -10);
        }
        return $phone;
    }
}

if (!function_exists('validatePhoneFormatPH')) {
    function validatePhoneFormatPH($phone) {
        // Valid PH phone: 09xxxxxxxxx or +639xxxxxxxxx
        return preg_match('/^(09|\+639)[0-9]{9}$/', preg_replace('/[^0-9+]/', '', $phone));
    }
}

if (!function_exists('sanitizeFileName')) {
    function sanitizeFileName($filename) {
        $filename = preg_replace('/[^a-zA-Z0-9._-]/', '', $filename);
        $filename = preg_replace('/_+/', '_', $filename);
        return trim($filename, '_');
    }
}

if (!function_exists('isValidURL')) {
    function isValidURL($url) {
        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }
}

if (!function_exists('truncateText')) {
    function truncateText($text, $length = 100, $suffix = '...') {
        if (strlen($text) <= $length) {
            return $text;
        }
        return substr($text, 0, $length) . $suffix;
    }
}

if (!function_exists('getUserRoleDisplay')) {
    function getUserRoleDisplay($role) {
        $roles = [
            'user' => 'Regular User',
            'senior' => 'Senior Citizen',
            'pwd' => 'PWD',
            'admin' => 'Administrator',
            'staff' => 'Staff'
        ];
        return isset($roles[$role]) ? $roles[$role] : ucfirst($role);
    }
}

if (!function_exists('getOrderStatusBadge')) {
    function getOrderStatusBadge($status) {
        $badges = [
            'pending' => '<span style="background: #fff3cd; color: #856404; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">⏳ Pending</span>',
            'processing' => '<span style="background: #cfe2ff; color: #084298; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">🔄 Processing</span>',
            'shipped' => '<span style="background: #d1e7dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">📦 Shipped</span>',
            'delivered' => '<span style="background: #d1e7dd; color: #0f5132; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">✅ Delivered</span>',
            'cancelled' => '<span style="background: #f8d7da; color: #842029; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">❌ Cancelled</span>'
        ];
        return isset($badges[$status]) ? $badges[$status] : '<span style="background: #e2e3e5; color: #383d41; padding: 4px 12px; border-radius: 20px; font-weight: 600; font-size: 0.85em;">❓ Unknown</span>';
    }
}

if (!function_exists('hashPassword')) {
    function hashPassword($password) {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
    }
}

if (!function_exists('verifyPassword')) {
    function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

if (!function_exists('rateLimit')) {
    function rateLimit($action, $limit = 5, $window = 300) {
        $key = 'rate_limit_' . $action . '_' . getUserIP();

        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        }

        $elapsed = time() - $_SESSION[$key]['first_attempt'];

        if ($elapsed > $window) {
            $_SESSION[$key] = ['count' => 0, 'first_attempt' => time()];
        }

        $_SESSION[$key]['count']++;

        return $_SESSION[$key]['count'] <= $limit;
    }
}

if (!function_exists('getOrdersByUser')) {
    function getOrdersByUser($user_id, $limit = 10) {
        global $conn;
        $stmt = $conn->prepare("SELECT * FROM `orders` WHERE user_id = ? ORDER BY placed_on DESC LIMIT ?");
        if (!$stmt) {
            logSecurityEvent('DATABASE_ERROR', ['error' => 'Get orders prepare failed']);
            return [];
        }
        $stmt->bind_param("si", $user_id, $limit);
        if (!$stmt->execute()) {
            logSecurityEvent('DATABASE_ERROR', ['error' => 'Get orders execute failed']);
            return [];
        }
        $result = $stmt->get_result();
        $orders = [];
        while ($row = $result->fetch_assoc()) {
            $orders[] = $row;
        }
        $stmt->close();
        return $orders;
    }
}

if (!function_exists('calculateDiscount')) {
    function calculateDiscount($subtotal, $discount_type, $discount_amount) {
        if ($discount_type === 'percentage') {
            return ($subtotal * $discount_amount) / 100;
        } else {
            return $discount_amount;
        }
    }
}

if (!function_exists('isOrderExpired')) {
    function isOrderExpired($placed_on, $days = 30) {
        $placed_date = strtotime($placed_on);
        $expiry_date = strtotime("+$days days", $placed_date);
        return time() > $expiry_date;
    }
}

if (!function_exists('sendEmail')) {
    function sendEmail($to, $subject, $body, $headers = []) {
        require_once dirname(__DIR__, 2) . '/mail_helper.php';
        $replyTo = $headers['Reply-To'] ?? null;
        return send_six_origins_mail($to, '', $subject, $body, null, $replyTo);
    }
}

?>