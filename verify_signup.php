<?php

session_start();
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/helpers/site_customization.php';
require_once __DIR__ . '/app/helpers/loyalty.php';

$settings = get_site_customization($conn);
$verificationHeading = site_customization_text($settings, 'verification_signup_heading');
$verificationDescription = str_replace(
    '{email}',
    escapeOutput($email),
    escapeOutput(site_customization_text($settings, 'verification_signup_description'))
);
$verificationCodeLabel = site_customization_text($settings, 'verification_signup_code_label');
$verificationSubmitLabel = site_customization_text($settings, 'verification_signup_button');
$verificationResendLabel = site_customization_text($settings, 'verification_signup_resend_button');
$email = (string)($_SESSION['pending_signup_email'] ?? '');
$message = $_SESSION['message'] ?? '';
unset($_SESSION['message']);
$messageType = 'info';
$transactionOpen = false;

if ($email === '') {
    header('Location: register.php');
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCSRFToken((string)($_POST['csrf_token'] ?? ''))) {
        $message = 'Your session expired. Refresh the page and try again.';
        $messageType = 'error';
    } elseif (isset($_POST['resend_code'])) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE email = ? AND user_type = \'user\' AND email_verified = 0 LIMIT 1');
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $user = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if (!$user) {
            $message = 'This account is already verified. You can sign in.';
        } elseif (loyalty_send_signup_verification($conn, (int)$user['id'])) {
            $message = 'A new verification code has been sent to your email.';
        } else {
            $message = 'A new code could not be sent yet. Please wait one minute and try again.';
            $messageType = 'error';
        }
    } else {
        $code = trim((string)($_POST['code'] ?? ''));
        if (!preg_match('/^\d{6}$/', $code)) {
            $message = 'Enter the six-digit code from your email.';
            $messageType = 'error';
        } else {
            $conn->begin_transaction();
            $transactionOpen = true;
            try {
                $stmt = $conn->prepare(
                    'SELECT sev.id, sev.code_hash, sev.expires_at, sev.attempts, u.id AS user_id
                     FROM signup_email_verifications sev
                     JOIN users u ON u.id = sev.user_id
                     WHERE u.email = ? AND u.user_type = \'user\' AND u.email_verified = 0 AND sev.used = 0
                     ORDER BY sev.id DESC LIMIT 1 FOR UPDATE'
                );
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $verification = $stmt->get_result()->fetch_assoc();
                $stmt->close();

                if (!$verification || strtotime((string)$verification['expires_at']) < time() || (int)$verification['attempts'] >= 5) {
                    if ($verification) {
                        $expire = $conn->prepare('UPDATE signup_email_verifications SET used = 1 WHERE id = ?');
                        $expire->bind_param('i', $verification['id']);
                        $expire->execute();
                        $expire->close();
                    }
                    $conn->commit();
                    $transactionOpen = false;
                    $message = 'That code has expired or is no longer valid. Request a new code and try again.';
                    $messageType = 'error';
                } elseif (!password_verify($code, (string)$verification['code_hash'])) {
                    $attempt = $conn->prepare('UPDATE signup_email_verifications SET attempts = attempts + 1 WHERE id = ?');
                    $attempt->bind_param('i', $verification['id']);
                    $attempt->execute();
                    $attempt->close();
                    $conn->commit();
                    $transactionOpen = false;
                    $message = 'The code is incorrect. Check your email and try again.';
                    $messageType = 'error';
                } else {
                    $mark = $conn->prepare('UPDATE signup_email_verifications SET used = 1 WHERE id = ?');
                    $mark->bind_param('i', $verification['id']);
                    $mark->execute();
                    $mark->close();
                    $verify = $conn->prepare('UPDATE users SET email_verified = 1 WHERE id = ? AND email_verified = 0');
                    $verify->bind_param('i', $verification['user_id']);
                    $verify->execute();
                    $verify->close();
                    $conn->commit();
                    $transactionOpen = false;

                    try {
                        loyalty_award_campaigns($conn, (int)$verification['user_id'], 'signup');
                    } catch (Throwable $e) {
                        error_log('Signup voucher could not be issued: ' . $e->getMessage());
                        $message = 'Your email is verified, but your welcome voucher could not be issued right now. Please contact the store.';
                        $messageType = 'error';
                        throw new RuntimeException('Signup voucher could not be issued.');
                    }

                    unset($_SESSION['pending_signup_email']);
                    $_SESSION['message'] = 'Email verified! Sign in to view your welcome voucher and daily rewards.';
                    header('Location: login.php');
                    exit;
                }
            } catch (Throwable $e) {
                if ($transactionOpen) {
                    $conn->rollback();
                    $transactionOpen = false;
                }
                if ($message === '') {
                    error_log('Signup email verification failed: ' . $e->getMessage());
                    $message = 'Verification could not be completed. Please try again.';
                    $messageType = 'error';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo escapeOutput($verificationHeading); ?> | <?php echo escapeOutput($settings['brand_name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        *{box-sizing:border-box}body{min-height:100vh;margin:0;display:grid;place-items:center;padding:24px;background:var(--site-page-bg,#fffaf5);color:var(--site-text,#3d2b27);font-family:Montserrat,sans-serif}
        .verify-card{width:min(100%,480px);padding:clamp(24px,6vw,42px);border:1px solid color-mix(in srgb,var(--gray-brown,#664c47) 20%,transparent);border-radius:22px;background:var(--site-surface,#fff);box-shadow:0 18px 55px rgba(61,43,39,.12)}
        .verify-icon{width:58px;height:58px;display:grid;place-items:center;border-radius:17px;background:var(--primary-red,#c6453e);color:#fff;font-size:1.45rem}
        h1{margin:20px 0 8px;color:var(--dark-brown,#5e1f13);font-size:clamp(1.5rem,5vw,2rem)}p{line-height:1.65;color:var(--gray-brown,#664c47)}
        label{display:block;margin:22px 0 8px;font-weight:700}input{width:100%;min-height:52px;padding:12px;border:1px solid #d6c9c1;border-radius:10px;font:700 1.25rem Montserrat,sans-serif;letter-spacing:.3em;text-align:center;color:var(--site-text,#3d2b27);background:var(--site-surface,#fff)}
        button,.resend{width:100%;min-height:48px;margin-top:14px;border:0;border-radius:10px;background:linear-gradient(135deg,var(--primary-red,#c6453e),var(--dark-brown,#5e1f13));color:#fff;font:800 1rem Montserrat,sans-serif;cursor:pointer;text-decoration:none}
        .resend{display:grid;place-items:center;background:transparent;color:var(--dark-brown,#5e1f13);border:1px solid color-mix(in srgb,var(--gray-brown,#664c47) 28%,transparent)}
        .notice{padding:12px 14px;border-radius:10px;background:color-mix(in srgb,var(--primary-red,#c6453e) 9%,var(--site-surface,#fff));line-height:1.5}
        .notice.error{background:#fff0ee;color:#8a1f19}
    </style>
    <?php echo site_customization_css($settings); ?>
    <?php echo site_customization_verification_background_css($settings); ?>
</head>
<body class="email-verification-page">
    <main class="verify-card">
        <div class="verify-icon"><i class="fa-solid fa-envelope-circle-check"></i></div>
        <h1><?php echo escapeOutput($verificationHeading); ?></h1>
        <p><?php echo $verificationDescription; ?></p>
        <?php if ($message !== ''): ?>
            <div class="notice <?php echo $messageType === 'error' ? 'error' : ''; ?>" role="status"><?php echo escapeOutput($message); ?></div>
        <?php endif; ?>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <label for="code"><?php echo escapeOutput($verificationCodeLabel); ?></label>
            <input id="code" name="code" inputmode="numeric" autocomplete="one-time-code" pattern="\d{6}" maxlength="6" required>
            <button type="submit"><i class="fa-solid fa-check"></i> <?php echo escapeOutput($verificationSubmitLabel); ?></button>
        </form>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            <button class="resend" type="submit" name="resend_code" value="1"><?php echo escapeOutput($verificationResendLabel); ?></button>
        </form>
    </main>
</body>
</html>
