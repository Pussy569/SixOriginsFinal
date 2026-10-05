<?php
session_start();
include 'config.php';
require_once __DIR__ . '/mail_helper.php';

function dbg($m){
    // uncomment to debug: file_put_contents(__DIR__.'/debug.log', date('[Y-m-d H:i:s] ').$m.PHP_EOL, FILE_APPEND);
}

/**
 * Send verification email using the shared Resend transport.
 */
function send_verification_mail(string $toEmail, string $code): bool|string {
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = "<p style='font-family:Montserrat,Arial;color:#5E1F13'>Hello,</p>
             <p style='font-family:Montserrat,Arial;color:#5E1F13'>Your login verification code is:<br><strong style='font-size:1.4rem;color:#C6453E'>{$safeCode}</strong></p>
             <p style='font-family:Montserrat,Arial;color:#5E1F13'>This code expires in 10 minutes. If you did not request this, please ignore this email.</p>
             <p style='font-family:Montserrat,Arial;color:#5E1F13'>— Six Origins Cafe Team</p>";
    return send_six_origins_mail($toEmail, '', 'Your Login Verification Code', $html);
}

if (empty($_SESSION['pending_2fa_user_id'])) {
    header('Location: login.php');
    exit();
}

$user_id = (int)$_SESSION['pending_2fa_user_id'];
$message = '';
$info = '';
$next_resend_seconds = 0;

if (!isset($_SESSION['2fa_attempts'])) $_SESSION['2fa_attempts'] = 0;

if (!isset($_SESSION['last_2fa_sent'])) {
    $stmt = mysqli_prepare($conn, "SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $last_created_at);
        if (mysqli_stmt_fetch($stmt)) {
            $last_ts = strtotime($last_created_at);
            if ($last_ts === false) {
                $last_ts = time();
            }
            if (abs(time() - $last_ts) > 3600) {
                $last_ts = time();
            }
            $_SESSION['last_2fa_sent'] = (int)$last_ts;
        } else {
            $_SESSION['last_2fa_sent'] = time();
        }
        mysqli_stmt_close($stmt);
    } else {
        $_SESSION['last_2fa_sent'] = time();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['2fa_attempts'], $_SESSION['last_2fa_sent']);
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    if ($isAjax) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => 'login.php']);
        exit();
    } else {
        header('Location: login.php');
        exit();
    }
}

if (isset($_POST['verify_code'])) {
    if ($_SESSION['2fa_attempts'] >= 5) {
        $message = "Too many attempts. Please log in again.";
    } else {
        $code = trim($_POST['code'] ?? '');
        if ($code === '') {
            $message = "Please enter the verification code.";
        } else {
            $now = date('Y-m-d H:i:s');
            $stmt = mysqli_prepare($conn, "SELECT id, code_hash FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at >= ? ORDER BY created_at DESC LIMIT 1");
            mysqli_stmt_bind_param($stmt, "is", $user_id, $now);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_store_result($stmt);
            if (mysqli_stmt_num_rows($stmt) === 1) {
                mysqli_stmt_bind_result($stmt, $ver_id, $ver_hash);
                mysqli_stmt_fetch($stmt);
                mysqli_stmt_close($stmt);
                if (password_verify($code, $ver_hash)) {
                    $up = mysqli_prepare($conn, "UPDATE email_verifications SET used = 1 WHERE id = ?");
                    mysqli_stmt_bind_param($up, "i", $ver_id);
                    mysqli_stmt_execute($up);
                    mysqli_stmt_close($up);

                    $u = mysqli_query($conn, "SELECT id, name, email, user_type FROM users WHERE id = '$user_id' LIMIT 1") or die('query failed');
                    $user = mysqli_fetch_assoc($u);

                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];

                    unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_expires'], $_SESSION['2fa_attempts'], $_SESSION['last_2fa_sent']);
                    header('Location: index.php');
                    exit();
                } else {
                    $_SESSION['2fa_attempts']++;
                    $message = "Invalid verification code. Attempts: {$_SESSION['2fa_attempts']}/5";
                }
            } else {
                $message = "No active verification code found or it expired. Please log in again.";
            }
        }
    }
}

if (isset($_POST['resend'])) {
    $minInterval = 30;
    $windowSeconds = 10 * 60;
    $maxPerWindow = 3;

    $last_ts = isset($_SESSION['last_2fa_sent']) ? (int)$_SESSION['last_2fa_sent'] : null;
    if ($last_ts === null) {
        $stmt = mysqli_prepare($conn, "SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $user_id);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $last_created_at);
        if (mysqli_stmt_fetch($stmt)) {
            $last_ts = strtotime($last_created_at);
            if ($last_ts === false) $last_ts = time();
            if (abs(time() - $last_ts) > 3600) $last_ts = time();
            $_SESSION['last_2fa_sent'] = (int)$last_ts;
        } else {
            $last_ts = time();
            $_SESSION['last_2fa_sent'] = $last_ts;
        }
        mysqli_stmt_close($stmt);
    }

    if ($last_ts !== null && (time() - $last_ts) < $minInterval) {
        $next_resend_seconds = max(0, $minInterval - (time() - $last_ts));
        $message = "A code was recently sent. Please wait {$next_resend_seconds} second" . ($next_resend_seconds != 1 ? 's' : '') . " before resending.";
    } else {
        $window_start = date('Y-m-d H:i:s', time() - $windowSeconds);
        $cnt_stmt = mysqli_prepare($conn, "SELECT COUNT(*) FROM email_verifications WHERE user_id = ? AND created_at >= ?");
        mysqli_stmt_bind_param($cnt_stmt, "is", $user_id, $window_start);
        mysqli_stmt_execute($cnt_stmt);
        mysqli_stmt_bind_result($cnt_stmt, $count_recent);
        mysqli_stmt_fetch($cnt_stmt);
        mysqli_stmt_close($cnt_stmt);

        if ($count_recent >= $maxPerWindow) {
            $message = "You have reached the maximum resend limit. Please try again later.";
        } else {
            mysqli_query($conn, "DELETE FROM email_verifications WHERE user_id = '".(int)$user_id."' AND used = 0");

            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            $expires_at = date('Y-m-d H:i:s', time() + 10 * 60);
            $code_hash = password_hash($code, PASSWORD_DEFAULT);

            if ($ins = mysqli_prepare($conn, "INSERT INTO email_verifications (user_id, code_hash, expires_at) VALUES (?, ?, ?)")) {
                mysqli_stmt_bind_param($ins, "iss", $user_id, $code_hash, $expires_at);
                mysqli_stmt_execute($ins);
                $inserted = mysqli_stmt_affected_rows($ins) > 0;
                mysqli_stmt_close($ins);
            } else {
                $inserted = false;
            }

            if ($inserted) {
                $u = mysqli_query($conn, "SELECT email FROM users WHERE id = '$user_id' LIMIT 1") or die('query failed');
                $row = mysqli_fetch_assoc($u);
                $toEmail = $row['email'];
                $sent = send_verification_mail($toEmail, $code);
                if ($sent === true) {
                    $_SESSION['pending_2fa_expires'] = $expires_at;
                    $_SESSION['last_2fa_sent'] = time();
                    $info = "A new code was sent to your email.";
                    $next_resend_seconds = $minInterval;
                } else {
                    $message = "Failed to send verification email. Please try again later.";
                    mysqli_query($conn, "DELETE FROM email_verifications WHERE user_id = '".(int)$user_id."' AND expires_at = '$expires_at' AND used = 0");
                }
            } else {
                $message = "Failed to generate verification code. Please try again.";
            }
        }
    }
}

if ($next_resend_seconds === 0) {
    $last_ts = isset($_SESSION['last_2fa_sent']) ? (int)$_SESSION['last_2fa_sent'] : null;
    if ($last_ts === null) {
        $stmt = mysqli_prepare($conn, "SELECT created_at FROM email_verifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 1");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $user_id);
            mysqli_stmt_execute($stmt);
            mysqli_stmt_bind_result($stmt, $last_created_at);
            if (mysqli_stmt_fetch($stmt)) {
                $last_ts = strtotime($last_created_at);
                if ($last_ts === false) $last_ts = time();
                if (abs(time() - $last_ts) > 3600) $last_ts = time();
                $_SESSION['last_2fa_sent'] = (int)$last_ts;
            } else {
                $last_ts = time();
                $_SESSION['last_2fa_sent'] = $last_ts;
            }
            mysqli_stmt_close($stmt);
        } else {
            $last_ts = time();
            $_SESSION['last_2fa_sent'] = $last_ts;
        }
    }
    $seconds_since = time() - $last_ts;
    $next_resend_seconds = max(0, 30 - $seconds_since);
    if ($next_resend_seconds > 30) $next_resend_seconds = 30;
}

$expires_display = $_SESSION['pending_2fa_expires'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Enter verification code — Six Origins Cafe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<style>
:root{
  --primary-red: #C6453E;
  --dark-brown: #5E1F13;
  --gray-brown: #664C47;
  --light-cream: #FFF2E0;
  --white: #FFFFFF;
  --border-light: #F0E6D8;
  --radius: 16px;
  --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
  --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
  --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

*{
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: 'Montserrat', system-ui, Arial;
}

html, body {
  height: 100%;
  width: 100%;
}

body {
  background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
  color: var(--dark-brown);
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 24px;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

.container {
  width: 100%;
  max-width: 1000px;
  display: grid;
  grid-template-columns: 1fr 450px;
  gap: 32px;
  align-items: center;
}

.panel {
  background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
  border-radius: var(--radius);
  padding: 32px;
  box-shadow: var(--shadow);
  border: 1.5px solid var(--border-light);
  animation: slideInLeft 0.5s ease;
}

@keyframes slideInLeft {
  from {
    opacity: 0;
    transform: translateX(-30px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

@keyframes slideInRight {
  from {
    opacity: 0;
    transform: translateX(30px);
  }
  to {
    opacity: 1;
    transform: translateX(0);
  }
}

.panel:hover {
  box-shadow: var(--shadow-hover);
  border-color: var(--primary-red);
}

.brand {
  display: flex;
  gap: 16px;
  align-items: center;
  margin-bottom: 28px;
}

.brand img {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  object-fit: cover;
  box-shadow: 0 4px 12px rgba(94, 31, 19, 0.1);
}

.brand h1 {
  font-size: 1.8rem;
  color: var(--dark-brown);
  font-weight: 900;
  letter-spacing: -0.3px;
}

.brand p {
  color: var(--gray-brown);
  font-size: 0.9rem;
  font-weight: 600;
}

.info-section {
  margin-top: 24px;
  padding: 16px;
  background: linear-gradient(135deg, rgba(198, 69, 62, 0.05) 0%, rgba(198, 69, 62, 0.02) 100%);
  border-radius: 12px;
  border-left: 4px solid var(--primary-red);
}

.info-section strong {
  display: block;
  color: var(--dark-brown);
  font-weight: 800;
  margin-bottom: 8px;
  font-size: 0.95rem;
}

.info-section p {
  color: var(--gray-brown);
  font-size: 0.9rem;
  line-height: 1.5;
  font-weight: 500;
}

.form-card {
  padding: 32px;
  background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
  border-radius: var(--radius);
  border: 1.5px solid var(--border-light);
  box-shadow: var(--shadow);
  animation: slideInRight 0.5s ease;
}

.form-card:hover {
  box-shadow: var(--shadow-hover);
  border-color: var(--primary-red);
}

.title {
  font-size: 1.8rem;
  color: var(--dark-brown);
  font-weight: 900;
  margin-bottom: 8px;
  letter-spacing: -0.3px;
}

.subtitle {
  color: var(--gray-brown);
  margin-bottom: 20px;
  font-weight: 500;
  font-size: 0.95rem;
  line-height: 1.6;
}

.input-group {
  margin-bottom: 16px;
}

label {
  display: block;
  font-weight: 800;
  color: var(--dark-brown);
  font-size: 0.95rem;
  margin-bottom: 8px;
  letter-spacing: 0.3px;
}

.input {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 16px;
  border-radius: 10px;
  background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
  border: 1.5px solid var(--border-light);
  box-shadow: 0 2px 8px rgba(94, 31, 19, 0.04);
  transition: var(--transition);
}

.input:focus-within {
  border-color: var(--primary-red);
  box-shadow: 0 0 0 4px rgba(198, 69, 62, 0.1);
}

.input input {
  border: 0;
  background: transparent;
  outline: none;
  font-size: 1.1rem;
  color: var(--dark-brown);
  width: 100%;
  font-weight: 600;
  letter-spacing: 0.1em;
}

.input input::placeholder {
  color: var(--gray-brown);
}

.button-group {
  display: flex;
  gap: 12px;
  margin-top: 20px;
  justify-content: flex-end;
}

.btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 12px 20px;
  border-radius: 10px;
  border: none;
  cursor: pointer;
  font-weight: 800;
  font-size: 0.95rem;
  transition: var(--transition);
  letter-spacing: 0.3px;
  flex: 1;
  position: relative;
  overflow: hidden;
  box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
}

.btn::before {
  content: '';
  position: absolute;
  top: 0;
  left: -100%;
  width: 100%;
  height: 100%;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
  transition: left 0.5s ease;
}

.btn:hover:not(:disabled) {
  transform: translateY(-2px);
  box-shadow: 0 6px 18px rgba(94, 31, 19, 0.25);
}

.btn:hover:not(:disabled)::before {
  left: 100%;
}

.btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
  transform: none;
}

.btn.ghost {
  background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
  border: 1.5px solid var(--border-light);
  color: var(--dark-brown);
  box-shadow: 0 2px 6px rgba(94, 31, 19, 0.05);
  flex: 0.8;
}

.btn.ghost:hover:not(:disabled) {
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: #fff;
  border-color: var(--primary-red);
}

.info {
  margin-top: 20px;
  padding: 14px 16px;
  background: linear-gradient(135deg, rgba(198, 69, 62, 0.05) 0%, rgba(198, 69, 62, 0.02) 100%);
  border-radius: 10px;
  border-left: 4px solid var(--primary-red);
  font-size: 0.9rem;
  color: var(--gray-brown);
  font-weight: 500;
  line-height: 1.6;
}

.info strong {
  color: var(--dark-brown);
  font-weight: 800;
}

.info div {
  margin-top: 8px;
}

.msg {
  padding: 14px 16px;
  border-radius: var(--radius);
  margin-bottom: 16px;
  font-weight: 700;
  border-left: 4px solid;
  display: flex;
  align-items: center;
  gap: 10px;
  animation: slideDown 0.3s ease;
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-10px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.msg i {
  flex-shrink: 0;
  font-size: 1.1rem;
}

.msg.error {
  background: linear-gradient(135deg, rgba(217, 126, 106, 0.1) 0%, rgba(217, 126, 106, 0.05) 100%);
  border-color: #D97E6A;
  color: #D97E6A;
}

.msg.success {
  background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
  border-color: var(--primary-red);
  color: var(--primary-red);
}

@media (max-width: 1024px) {
  .container {
    grid-template-columns: 1fr;
    gap: 24px;
  }

  .button-group {
    justify-content: stretch;
  }

  .btn, .btn.ghost {
    flex: 1;
  }
}

@media (max-width: 640px) {
  body {
    padding: 16px;
  }

  .container {
    gap: 20px;
  }

  .panel, .form-card {
    padding: 24px;
  }

  .title {
    font-size: 1.5rem;
  }

  .subtitle {
    font-size: 0.9rem;
  }

  .brand {
    margin-bottom: 20px;
  }

  .brand img {
    width: 50px;
    height: 50px;
  }

  .brand h1 {
    font-size: 1.4rem;
  }

  .info-section {
    margin-top: 18px;
    padding: 14px;
  }

  .button-group {
    flex-direction: column;
  }

  .btn, .btn.ghost {
    width: 100%;
    flex: initial;
  }
}

@media (max-width: 480px) {
  .panel {
    padding: 18px;
  }

  .form-card {
    padding: 18px;
  }

  .title {
    font-size: 1.3rem;
  }

  .input {
    padding: 12px 14px;
  }

  .input input {
    font-size: 1rem;
  }

  .btn {
    padding: 11px 16px;
    font-size: 0.9rem;
  }

  .brand {
    gap: 12px;
  }

  .brand img {
    width: 45px;
    height: 45px;
  }

  .brand h1 {
    font-size: 1.2rem;
  }

  .brand p {
    font-size: 0.85rem;
  }
}
</style>
</head>
<body>

<div class="container">
  <aside class="panel" aria-hidden="false">
    <div class="brand">
      <img src="images/logo.jpg" alt="Six Origins Cafe" />
      <div>
        <h1>Six Origins</h1>
        <p>Cafe Portal</p>
      </div>
    </div>

    <div class="info-section">
      <strong><i class="fa-solid fa-shield"></i> Why this step?</strong>
      <p>We send a verification code to your email to confirm it's you. This improves account security and prevents unauthorized access.</p>
    </div>

    <div class="info-section">
      <strong><i class="fa-solid fa-envelope"></i> Didn't receive the email?</strong>
      <p>Check your spam/junk folder. You can resend the code below (rate limited to 30 seconds).</p>
    </div>
  </aside>

  <main class="form-card" role="main" aria-labelledby="verifyTitle">
    <h2 class="title" id="verifyTitle"><i class="fa-solid fa-lock"></i> Enter verification code</h2>
    <p class="subtitle">We emailed a 6-digit code to your account. Enter it below to complete sign-in.</p>

    <?php if ($message): ?>
      <div class="msg error">
        <i class="fa-solid fa-circle-xmark"></i>
        <span><?php echo htmlspecialchars($message); ?></span>
      </div>
    <?php endif; ?>
    
    <?php if ($info): ?>
      <div class="msg success">
        <i class="fa-solid fa-circle-check"></i>
        <span><?php echo htmlspecialchars($info); ?></span>
      </div>
    <?php endif; ?>

    <form method="post" id="verifyForm">
      <div class="input-group">
        <label for="code"><i class="fa-solid fa-hashtag"></i> Verification code</label>
        <div class="input">
          <input id="code" name="code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6" placeholder="123456" required autofocus>
        </div>
      </div>

      <div class="button-group">
        <button type="submit" name="verify_code" class="btn">
          <i class="fa-solid fa-check-circle"></i>
          <span>Verify</span>
        </button>
        <button type="button" id="resendBtn" class="btn ghost">
          <i class="fa-solid fa-redo"></i>
          <span>Resend code</span>
        </button>
        <button type="button" id="cancelBtn" class="btn ghost">
          <i class="fa-solid fa-times-circle"></i>
          <span>Cancel</span>
        </button>
      </div>
    </form>

    <div class="info">
      <?php if (!empty($expires_display)): ?>
        <div><strong><i class="fa-solid fa-hourglass-end"></i> Code expires at:</strong> <?php echo htmlspecialchars($expires_display); ?></div>
      <?php endif; ?>
      <div><strong><i class="fa-solid fa-attempt"></i> Attempts left:</strong> <?php echo max(0, 5 - ($_SESSION['2fa_attempts'] ?? 0)); ?>/5</div>
      <div id="resendInfo"></div>
    </div>

  </main>
</div>

<script>
  (function(){
    var nextResendSeconds = <?php echo (int)$next_resend_seconds; ?>;
    if (nextResendSeconds < 0) nextResendSeconds = 0;
    if (nextResendSeconds > 30) nextResendSeconds = 30;

    var resendBtn = document.getElementById('resendBtn');
    var resendInfo = document.getElementById('resendInfo');
    var verifyForm = document.getElementById('verifyForm');
    var cancelBtn = document.getElementById('cancelBtn');

    function updateResendUI() {
      if (nextResendSeconds > 0) {
        resendBtn.disabled = true;
        resendBtn.innerHTML = '<i class="fa-solid fa-hourglass-end"></i><span>Resend (' + nextResendSeconds + 's)</span>';
        resendInfo.innerHTML = '<strong><i class="fa-solid fa-info-circle"></i> Resend available in:</strong> ' + nextResendSeconds + ' second' + (nextResendSeconds !== 1 ? 's' : '') + '.';
        nextResendSeconds--;
      } else {
        resendBtn.disabled = false;
        resendBtn.innerHTML = '<i class="fa-solid fa-redo"></i><span>Resend code</span>';
        resendInfo.innerHTML = '';
        clearInterval(timer);
      }
    }

    updateResendUI();
    var timer = null;
    if (nextResendSeconds > 0) timer = setInterval(updateResendUI, 1000);

    resendBtn.addEventListener('click', function(){
      var input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'resend';
      input.value = '1';
      verifyForm.appendChild(input);
      verifyForm.submit();
    });

    cancelBtn.addEventListener('click', function(){
      fetch(window.location.href, {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'cancel=1'
      }).then(function(resp){
        return resp.json ? resp.json() : resp.text();
      }).then(function(data){
        try {
          if (data && data.redirect) window.location.href = data.redirect;
          else window.location.href = 'login.php';
        } catch (e) {
          window.location.href = 'login.php';
        }
      }).catch(function(){
        window.location.href = 'login.php';
      });
    });
  })();
</script>
</body>
</html>