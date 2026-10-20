<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/services/login_session_security.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

function account_session_reply(array $data, int $statusCode = 200): void
{
    http_response_code($statusCode);
    echo json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    account_session_reply(['error' => 'Method not allowed.'], 405);
}

$action = (string)($_POST['action'] ?? '');
$userId = current_account_session_user_id();
$sessionToken = (string)($_SESSION['account_session_token'] ?? '');

if ($action !== 'poll_pending' && ($userId <= 0 || $sessionToken === '')) {
    account_session_reply(['status' => 'unauthenticated'], 401);
}

if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
    account_session_reply(['error' => 'Invalid security token.'], 403);
}

if ($action === 'poll_active') {
    $stmt = $conn->prepare("SELECT r.request_id FROM users u LEFT JOIN login_session_requests r ON r.user_id = u.id AND r.status = 'pending' AND r.expires_at > NOW() WHERE u.id = ? AND u.active_session_token = ? ORDER BY r.created_at DESC LIMIT 1");
    $stmt->bind_param('is', $userId, $sessionToken);
    $stmt->execute();
    $stmt->bind_result($requestId);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        session_unset();
        session_destroy();
        account_session_reply(['status' => 'revoked'], 401);
    }

    $hasRequest = is_string($requestId) && $requestId !== '';
    account_session_reply(['status' => $hasRequest ? 'pending' : 'none', 'request_id' => $hasRequest ? $requestId : null]);
}

if ($action === 'approve' || $action === 'reject') {
    $stmt = $conn->prepare('SELECT active_session_token FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($activeToken);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found || !is_string($activeToken) || !hash_equals($activeToken, $sessionToken)) {
        session_unset();
        session_destroy();
        account_session_reply(['status' => 'revoked'], 401);
    }

    $requestId = (string)($_POST['request_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $requestId)) {
        account_session_reply(['error' => 'Invalid sign-in request.'], 400);
    }

    if ($action === 'reject') {
        $stmt = $conn->prepare("UPDATE login_session_requests SET status = 'rejected' WHERE request_id = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW()");
        $stmt->bind_param('si', $requestId, $userId);
        $stmt->execute();
        $changed = $stmt->affected_rows === 1;
        $stmt->close();

        account_session_reply(['status' => $changed ? 'rejected' : 'expired']);
    }

    $conn->begin_transaction();
    $request = $conn->prepare("SELECT new_session_token FROM login_session_requests WHERE request_id = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW() FOR UPDATE");
    $request->bind_param('si', $requestId, $userId);
    $request->execute();
    $request->bind_result($newSessionToken);
    $found = $request->fetch();
    $request->close();

    if (!$found || !is_string($newSessionToken) || !preg_match('/^[a-f0-9]{64}$/', $newSessionToken)) {
        $conn->rollback();
        account_session_reply(['status' => 'expired']);
    }

    $approve = $conn->prepare("UPDATE login_session_requests SET status = 'approved' WHERE request_id = ? AND user_id = ? AND status = 'pending' AND expires_at > NOW()");
    $approve->bind_param('si', $requestId, $userId);
    $approve->execute();
    $approved = $approve->affected_rows === 1;
    $approve->close();

    if (!$approved) {
        $conn->rollback();
        account_session_reply(['status' => 'expired']);
    }

    $activate = $conn->prepare('UPDATE users SET active_session_token = ? WHERE id = ? AND active_session_token = ?');
    $activate->bind_param('sis', $newSessionToken, $userId, $sessionToken);
    $activate->execute();
    $activated = $activate->affected_rows === 1;
    $activate->close();

    if (!$activated) {
        $conn->rollback();
        session_unset();
        session_destroy();
        account_session_reply(['status' => 'revoked'], 401);
    }

    $conn->commit();
    if (isset($_SESSION['admin_id'])) {
        write_admin_activity((int)$_SESSION['admin_id'], 'Login Approval', 'Approved a sign-in from another device.', 'success', 'admin', (int)$_SESSION['admin_id']);
    }
    account_session_reply(['status' => 'approved']);
}

if ($action === 'poll_pending') {
    $requestId = (string)($_SESSION['pending_login_request_id'] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $requestId)) {
        account_session_reply(['status' => 'none']);
    }

    $stmt = $conn->prepare('SELECT r.status, r.new_session_token, u.id, u.name, u.email, u.user_type, u.active_session_token FROM login_session_requests r JOIN users u ON u.id = r.user_id WHERE r.request_id = ? LIMIT 1');
    $stmt->bind_param('s', $requestId);
    $stmt->execute();
    $stmt->bind_result($requestStatus, $newSessionToken, $accountId, $accountName, $accountEmail, $accountType, $activeToken);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        unset($_SESSION['pending_login_request_id']);
        account_session_reply(['status' => 'expired']);
    }

    if ($requestStatus === 'pending') {
        $expire = $conn->prepare("UPDATE login_session_requests SET status = 'expired' WHERE request_id = ? AND status = 'pending' AND expires_at <= NOW()");
        $expire->bind_param('s', $requestId);
        $expire->execute();
        $expired = $expire->affected_rows === 1;
        $expire->close();
        if (!$expired) {
            account_session_reply(['status' => 'pending']);
        }
        $requestStatus = 'expired';
    }

    if ($requestStatus !== 'approved') {
        unset($_SESSION['pending_login_request_id']);
        account_session_reply(['status' => $requestStatus === 'rejected' ? 'rejected' : 'expired']);
    }

    if (!is_string($newSessionToken) || !is_string($activeToken) || !hash_equals($newSessionToken, $activeToken)) {
        unset($_SESSION['pending_login_request_id']);
        account_session_reply(['status' => 'revoked']);
    }

    establish_account_login([
        'id' => (int)$accountId,
        'name' => (string)$accountName,
        'email' => (string)$accountEmail,
        'user_type' => (string)$accountType
    ], $newSessionToken);

    if (strtolower((string)$accountType) === 'admin') {
        write_admin_activity((int)$accountId, 'Admin Login', 'Admin signed in after approving a device sign-in.', 'success', 'admin', (int)$accountId, null, null, (string)$accountName, (string)$accountEmail);
        $redirect = 'admin_page.php';
    } elseif (strtolower((string)$accountType) === 'delivery_rider') {
        $redirect = 'rider_dashboard.php';
    } else {
        $redirect = 'index.php';
    }

    account_session_reply(['status' => 'approved', 'redirect' => $redirect]);
}

account_session_reply(['error' => 'Unknown action.'], 400);
