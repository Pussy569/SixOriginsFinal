<?php

function current_account_session_user_id(): int
{
    foreach (['admin_id', 'rider_id', 'user_id'] as $key) {
        if (!empty($_SESSION[$key])) {
            return (int)$_SESSION[$key];
        }
    }

    return 0;
}

function clear_active_account_session(int $userId, string $sessionToken): void
{
    global $conn;

    if ($userId <= 0 || $sessionToken === '') {
        return;
    }

    $stmt = $conn->prepare('UPDATE users SET active_session_token = NULL WHERE id = ? AND active_session_token = ?');
    $stmt->bind_param('is', $userId, $sessionToken);
    $stmt->execute();
    $stmt->close();
}

function establish_account_login(array $user, string $sessionToken): void
{
    session_regenerate_id(true);

    unset(
        $_SESSION['admin_id'],
        $_SESSION['admin_name'],
        $_SESSION['admin_email'],
        $_SESSION['rider_id'],
        $_SESSION['rider_name'],
        $_SESSION['rider_email'],
        $_SESSION['user_id'],
        $_SESSION['user_name'],
        $_SESSION['user_email'],
        $_SESSION['pending_login_request_id']
    );

    $_SESSION['account_session_token'] = $sessionToken;
    $_SESSION['just_logged_in'] = true;

    $type = strtolower((string)$user['user_type']);
    if ($type === 'admin') {
        $_SESSION['admin_id'] = (int)$user['id'];
        $_SESSION['admin_name'] = (string)$user['name'];
        $_SESSION['admin_email'] = (string)$user['email'];
    } elseif ($type === 'delivery_rider') {
        $_SESSION['rider_id'] = (int)$user['id'];
        $_SESSION['rider_name'] = (string)$user['name'];
        $_SESSION['rider_email'] = (string)$user['email'];
    } else {
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['user_name'] = (string)$user['name'];
        $_SESSION['user_email'] = (string)$user['email'];
    }
}

function begin_account_login(int $userId, string $name, string $email, string $type): string
{
    global $conn;

    $currentUserId = current_account_session_user_id();
    if ($currentUserId > 0 && $currentUserId !== $userId) {
        return 'blocked';
    }

    $stmt = $conn->prepare('SELECT active_session_token FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $stmt->bind_result($activeToken);
    $found = $stmt->fetch();
    $stmt->close();

    if (!$found) {
        error_log('Login session security: account disappeared before session activation.');
        return 'blocked';
    }

    $activeToken = (string)($activeToken ?? '');
    $currentToken = (string)($_SESSION['account_session_token'] ?? '');
    if ($activeToken !== '' && $currentUserId === $userId && $currentToken !== '' && hash_equals($activeToken, $currentToken)) {
        establish_account_login(['id' => $userId, 'name' => $name, 'email' => $email, 'user_type' => $type], $activeToken);
        return 'active';
    }

    if ($activeToken === '') {
        $sessionToken = bin2hex(random_bytes(32));
        $activate = $conn->prepare('UPDATE users SET active_session_token = ? WHERE id = ? AND active_session_token IS NULL');
        $activate->bind_param('si', $sessionToken, $userId);
        $activate->execute();
        $activated = $activate->affected_rows === 1;
        $activate->close();

        if ($activated) {
            establish_account_login(['id' => $userId, 'name' => $name, 'email' => $email, 'user_type' => $type], $sessionToken);
            return 'active';
        }

        $stmt = $conn->prepare('SELECT active_session_token FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $stmt->bind_result($activeToken);
        $found = $stmt->fetch();
        $stmt->close();

        if (!$found || empty($activeToken)) {
            error_log('Login session security: failed to activate or read the account session token.');
            return 'blocked';
        }
    }

    $cleanup = $conn->prepare('DELETE FROM login_session_requests WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
    $cleanup->execute();
    $cleanup->close();

    $expireOld = $conn->prepare("UPDATE login_session_requests SET status = 'expired' WHERE user_id = ? AND status = 'pending'");
    $expireOld->bind_param('i', $userId);
    $expireOld->execute();
    $expireOld->close();

    $requestId = bin2hex(random_bytes(32));
    $newSessionToken = bin2hex(random_bytes(32));
    $create = $conn->prepare("INSERT INTO login_session_requests (request_id, user_id, new_session_token, previous_session_token, status, expires_at) VALUES (?, ?, ?, ?, 'pending', DATE_ADD(NOW(), INTERVAL 60 SECOND))");
    $create->bind_param('siss', $requestId, $userId, $newSessionToken, $activeToken);
    $create->execute();
    $create->close();

    $_SESSION['pending_login_request_id'] = $requestId;
    return 'pending';
}

function require_account_login_approval(int $userId, string $name, string $email, string $type): void
{
    $result = begin_account_login($userId, $name, $email, $type);
    if ($result === 'active') {
        return;
    }

    if ($result === 'pending') {
        $_SESSION['message'] = 'A sign-in request was sent to your currently signed-in device. Approve it there within 60 seconds.';
        session_write_close();
        header('Location: login.php');
        exit();
    }

    $_SESSION['message'] = 'Please sign out of this account before signing in to a different account in this browser.';
    session_write_close();
    header('Location: login.php');
    exit();
}
