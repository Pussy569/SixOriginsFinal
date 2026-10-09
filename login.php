<?php
declare(strict_types=1);
session_start();

$DEBUG = getenv('APP_DEBUG') === 'true' && getenv('APP_ENV') !== 'production';

// ✅ NEW: number of wrong passwords allowed before the account is locked
define('MAX_LOGIN_ATTEMPTS', 5); // account locks on the 5th wrong password

ini_set('display_errors', '0');
error_reporting(E_ALL);

include 'config.php';
require_once __DIR__ . '/mail_helper.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';

function dbg($m){
    global $DEBUG;
    if ($DEBUG) {
        file_put_contents(__DIR__ . '/debug.log', date('[Y-m-d H:i:s] ') . $m . PHP_EOL, FILE_APPEND);
    }
}

function send_verification_mail(string $toEmail, string $code): bool|string {
    $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = "<p>Hello,</p>
             <p>Your login verification code is:<br><strong style='font-size:1.4rem;'>{$safeCode}</strong></p>
             <p>This code expires in 10 minutes. If you did not request this, please ignore this email.</p>
             <p>— Six Origins Team</p>";
    return send_six_origins_mail($toEmail, '', 'Your Login Verification Code', $html);
}

function send_lockout_mail(string $toEmail, string $name, int $lockout_minutes): bool|string {
    $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $hours = $lockout_minutes / 60;
    $time_text = $hours >= 1 ? number_format($hours, 1) . " hour(s)" : "{$lockout_minutes} minute(s)";
    $html = "<p>Hello {$safeName},</p>
             <p>Your account has been temporarily locked due to multiple failed login attempts.</p>
             <p><strong>🔐 Lockout Duration: {$time_text}</strong></p>
             <p>For security reasons, you cannot attempt to login during this period. After the lockout duration expires, you can try again.</p>
             <p><strong>Lockout Details:</strong></p>
             <ul>
                <li>Time: " . date('Y-m-d H:i:s') . "</li>
                <li>Your account will be unlocked at: " . date('Y-m-d H:i:s', time() + ($lockout_minutes * 60)) . "</li>
             </ul>
             <p><strong>If this wasn't you:</strong><br>
             If you didn't attempt to login, your password may be compromised. Please reset your password immediately.</p>
             <p>— Six Origins Security Team</p>";
    return send_six_origins_mail($toEmail, $name, '🔒 Account Temporarily Locked - Security Alert', $html);
}

function check_account_lockout(string $email): array {
    global $conn;

    $stmt = mysqli_prepare($conn, "SELECT id, failed_attempts, lockout_until FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "s", $email);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $user_id, $failed_attempts, $lockout_until);
    $found = mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    if (!$found) {
        return ['locked' => false, 'reason' => 'User not found'];
    }

    $now = time();

    // ✅ CHECK IF lockout_until IS NOT NULL AND NOT EMPTY FIRST
    if (!empty($lockout_until)) {
        $lockout_time = strtotime($lockout_until);

        if ($lockout_time && $lockout_time > $now) {
            $minutes_left = ceil(($lockout_time - $now) / 60);
            return [
                'locked' => true,
                'user_id' => $user_id,
                'reason' => "🔒 Account is locked. Try again in {$minutes_left} minute(s).",
                'minutes_left' => $minutes_left
            ];
        }
    }

    return ['locked' => false, 'user_id' => $user_id, 'failed_attempts' => $failed_attempts ?? 0];
}

/**
 * ✅ UPDATED: attempts 1-4 are only counted (no lock, no email).
 * The account is locked starting on the 5th wrong password.
 *
 * Returns:
 *   ['locked' => false, 'remaining' => int]   // still has attempts left
 *   ['locked' => true,  'minutes'   => int]   // account is now locked
 */
function increment_failed_attempts(int $user_id, string $email): array {
    global $conn;

    // Get current failed attempts + name in one query
    $stmt = mysqli_prepare($conn, "SELECT failed_attempts, name FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_bind_result($stmt, $failed_attempts, $name);
    mysqli_stmt_fetch($stmt);
    mysqli_stmt_close($stmt);

    $failed_attempts = ($failed_attempts ?? 0) + 1;

    // Attempts 1-4: just count them, do NOT lock
    if ($failed_attempts < MAX_LOGIN_ATTEMPTS) {
        $upd = mysqli_prepare($conn, "UPDATE users SET failed_attempts = ? WHERE id = ?");
        mysqli_stmt_bind_param($upd, "ii", $failed_attempts, $user_id);
        mysqli_stmt_execute($upd);
        mysqli_stmt_close($upd);

        $remaining = MAX_LOGIN_ATTEMPTS - $failed_attempts;
        dbg("Failed attempt #{$failed_attempts} for user {$user_id}. {$remaining} attempt(s) left before lockout");

        return ['locked' => false, 'remaining' => $remaining];
    }

    // Attempt 5 and beyond: lock the account
    // Lockout durations in minutes: 30, 180 (3 hrs), 480 (8 hrs), 1440 (24 hrs)
    $lockout_durations = [30, 180, 480, 1440];
    $lockout_minutes   = $lockout_durations[min($failed_attempts - MAX_LOGIN_ATTEMPTS, 3)];
    $lockout_until     = date('Y-m-d H:i:s', time() + ($lockout_minutes * 60));

    $upd = mysqli_prepare($conn, "UPDATE users SET failed_attempts = ?, lockout_until = ? WHERE id = ?");
    mysqli_stmt_bind_param($upd, "isi", $failed_attempts, $lockout_until, $user_id);
    mysqli_stmt_execute($upd);
    mysqli_stmt_close($upd);

    dbg("Failed attempt #{$failed_attempts} for user {$user_id}. LOCKED for {$lockout_minutes} minutes");

    // Send lockout email only when the account is actually locked
    send_lockout_mail($email, (string)$name, $lockout_minutes);

    return ['locked' => true, 'minutes' => $lockout_minutes];
}

function reset_failed_attempts(int $user_id): void {
    global $conn;

    $stmt = mysqli_prepare($conn, "UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "i", $user_id);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    dbg("Failed attempts reset for user {$user_id}");
}

/* ------------------ login processing ------------------ */
if (isset($_POST['submit'])) {

    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $pass  = isset($_POST['password']) ? $_POST['password'] : '';

    dbg("========== LOGIN ATTEMPT ==========");
    dbg("Email: {$email}");
    dbg("Password length: " . strlen($pass));

    if ($email === '' || $pass === '') {
        $_SESSION['message'] = 'Please enter email and password.';
        dbg("ERROR: Empty email or password");
        header('Location: login.php');
        exit();
    }

    // Check for account lockout
    $lockout_check = check_account_lockout($email);
    if ($lockout_check['locked']) {
        $_SESSION['message'] = $lockout_check['reason'];
        dbg("LOGIN BLOCKED: Account locked - {$lockout_check['reason']}");
        header('Location: login.php');
        exit();
    }

    // Fetch user from database
    dbg("Searching for user in database...");
    if ($stmt = mysqli_prepare($conn, "SELECT id, name, email, password, user_type, status, twofa_email_enabled FROM users WHERE email = ? LIMIT 1")) {
        mysqli_stmt_bind_param($stmt, "s", $email);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_bind_result($stmt, $id, $name, $db_email, $db_password, $user_type, $status, $twofa_enabled);
        $found = mysqli_stmt_fetch($stmt);
        mysqli_stmt_close($stmt);

        dbg("Database query executed. Found: " . ($found ? 'YES' : 'NO'));
    } else {
        dbg("❌ DB prepare failed: " . mysqli_error($conn));
        $found = false;
    }

    if ($found) {
        dbg("User found! ID: {$id}, Name: {$name}, Type: {$user_type}, Status: {$status}");
        dbg("Stored password hash length: " . strlen($db_password));
        dbg("Stored password (first 20 chars): " . substr($db_password, 0, 20));

        $password_ok = false;

        // Try password_hash verification first
        dbg("Trying password_verify()...");
        if (password_verify($pass, $db_password)) {
            $password_ok = true;
            dbg("✅ PASSWORD VERIFIED WITH password_hash!");
        } else {
            dbg("❌ password_verify failed");

            // Try MD5
            dbg("Trying MD5...");
            $md5_hash = md5($pass);
            dbg("MD5 hash: {$md5_hash}");
            dbg("Stored hash: {$db_password}");

            if ($md5_hash === $db_password) {
                $password_ok = true;
                dbg("✅ PASSWORD VERIFIED WITH MD5! Upgrading...");

                $new_hash = password_hash($pass, PASSWORD_DEFAULT);
                if ($upd = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?")) {
                    mysqli_stmt_bind_param($upd, "si", $new_hash, $id);
                    if (mysqli_stmt_execute($upd)) {
                        dbg("✅ Password upgraded to password_hash");
                    } else {
                        dbg("❌ Failed to upgrade password");
                    }
                    mysqli_stmt_close($upd);
                }
            } else {
                dbg("❌ MD5 also failed");

                // Try plain text
                dbg("Trying plain text comparison...");
                if ($pass === $db_password) {
                    $password_ok = true;
                    dbg("✅ PASSWORD VERIFIED WITH PLAIN TEXT! Upgrading...");

                    $new_hash = password_hash($pass, PASSWORD_DEFAULT);
                    if ($upd = mysqli_prepare($conn, "UPDATE users SET password = ? WHERE id = ?")) {
                        mysqli_stmt_bind_param($upd, "si", $new_hash, $id);
                        if (mysqli_stmt_execute($upd)) {
                            dbg("✅ Password upgraded to password_hash");
                        }
                        mysqli_stmt_close($upd);
                    }
                } else {
                    dbg("❌ PLAIN TEXT ALSO FAILED");
                }
            }
        }

        if ($password_ok) {
            dbg("✅ PASSWORD IS CORRECT!");
            $type = strtolower($user_type);
            $stat = strtolower($status);

            dbg("User type: {$type}, Status: {$stat}");

            // ✅ RESET FAILED ATTEMPTS ON SUCCESSFUL PASSWORD VERIFICATION
            reset_failed_attempts($id);

            // ✅ 'delivery_rider' is included in the approval-gated branch.
            if (in_array($type, ['admin', 'delivery_rider', 'senior', 'pwd'])) {
                if ($stat === 'approved') {
                    session_regenerate_id(true);

                    if ($type === 'admin') {
                        $_SESSION['admin_name'] = $name;
                        $_SESSION['admin_email'] = $db_email;
                        $_SESSION['admin_id'] = $id;
                        $_SESSION['just_logged_in'] = true; // ✅ ADD WELCOME MESSAGE FLAG
                        write_admin_activity($id, 'Admin Login', 'Admin signed in successfully.', 'success', 'admin', $id, null, null, $name, $db_email);
                        session_write_close();
                        dbg("✅ ADMIN LOGIN SUCCESSFUL - REDIRECTING TO admin_page.php");
                        header('Location: admin_page.php');
                        exit();
                    } elseif ($type === 'delivery_rider') {
                        // ✅ dedicated rider session + redirect to the rider dashboard
                        $_SESSION['rider_name']  = $name;
                        $_SESSION['rider_email'] = $db_email;
                        $_SESSION['rider_id']    = $id;
                        $_SESSION['just_logged_in'] = true;
                        session_write_close();
                        dbg("✅ DELIVERY RIDER LOGIN SUCCESSFUL - REDIRECTING TO rider_dashboard.php");
                        header('Location: rider_dashboard.php');
                        exit();
                    } else {
                        // senior / pwd
                        $_SESSION['user_name']  = $name;
                        $_SESSION['user_email'] = $db_email;
                        $_SESSION['user_id']    = $id;
                        $_SESSION['just_logged_in'] = true; // ✅ ADD WELCOME MESSAGE FLAG
                        session_write_close();
                        dbg("✅ SENIOR/PWD LOGIN SUCCESSFUL - REDIRECTING TO index.php");
                        header('Location: index.php');
                        exit();
                    }
                } else {
                    $type_label = $type === 'delivery_rider' ? 'Delivery Rider' : ucfirst($type);
                    if ($type === 'admin') {
                        write_admin_activity($id, 'Admin Login Blocked', 'Admin account is not approved.', 'failure', 'admin', $id, ['status' => $status], null, $name, $db_email);
                    }
                    $_SESSION['message'] = "Your {$type_label} account is pending approval. Please wait for admin confirmation.";
                    dbg("Account pending approval ({$type})");
                }
            } else {
                // Regular user
                if (!empty($twofa_enabled) && intval($twofa_enabled) === 1) {
                    dbg("2FA is enabled for this user");

                    $now = date('Y-m-d H:i:s');

                    if ($q = mysqli_prepare($conn, "SELECT id, created_at, expires_at FROM email_verifications WHERE user_id = ? AND used = 0 AND expires_at >= ? ORDER BY created_at DESC LIMIT 1")) {
                        mysqli_stmt_bind_param($q, "is", $id, $now);
                        mysqli_stmt_execute($q);
                        mysqli_stmt_bind_result($q, $existing_id, $existing_created_at, $existing_expires_at);
                        $has_existing = mysqli_stmt_fetch($q);
                        mysqli_stmt_close($q);
                    } else {
                        $has_existing = false;
                    }

                    if ($has_existing) {
                        $_SESSION['pending_2fa_user_id'] = $id;
                        $_SESSION['pending_2fa_expires'] = $existing_expires_at;
                        session_write_close();
                        dbg("Existing 2FA code found - redirecting to verify");
                        header('Location: verify_email_code.php');
                        exit();
                    }

                    mysqli_query($conn, "DELETE FROM email_verifications WHERE user_id = '".(int)$id."' AND used = 0");

                    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                    $expires_at = date('Y-m-d H:i:s', time() + 10 * 60);
                    $code_hash = password_hash($code, PASSWORD_DEFAULT);

                    if ($ins = mysqli_prepare($conn, "INSERT INTO email_verifications (user_id, code_hash, expires_at) VALUES (?, ?, ?)")) {
                        mysqli_stmt_bind_param($ins, "iss", $id, $code_hash, $expires_at);
                        mysqli_stmt_execute($ins);
                        $insert_ok = mysqli_stmt_affected_rows($ins) > 0;
                        mysqli_stmt_close($ins);
                    } else {
                        $insert_ok = false;
                    }

                    if ($insert_ok) {
                        $sent = send_verification_mail($db_email, $code);
                        if ($sent === true) {
                            $_SESSION['pending_2fa_user_id'] = $id;
                            $_SESSION['pending_2fa_expires'] = $expires_at;
                            session_write_close();
                            dbg("✅ 2FA code sent - redirecting to verify");
                            header('Location: verify_email_code.php');
                            exit();
                        } else {
                            $_SESSION['message'] = 'Failed to send verification email. Please try again later.';
                            dbg("Failed to send 2FA email: {$sent}");
                            mysqli_query($conn, "DELETE FROM email_verifications WHERE user_id = '".(int)$id."' AND expires_at = '$expires_at' AND used = 0");
                        }
                    } else {
                        $_SESSION['message'] = 'Failed to create verification record. Please try again.';
                        dbg("Failed to insert verification code");
                    }

                    session_write_close();
                    header('Location: login.php');
                    exit();
                } else {
                    // Regular user, no 2FA
                    session_regenerate_id(true);
                    $_SESSION['user_name']  = $name;
                    $_SESSION['user_email'] = $db_email;
                    $_SESSION['user_id']    = $id;
                    $_SESSION['just_logged_in'] = true; // ✅ ADD WELCOME MESSAGE FLAG
                    session_write_close();
                    dbg("✅ REGULAR USER LOGIN SUCCESSFUL - REDIRECTING TO index.php");
                    header('Location: index.php');
                    exit();
                }
            }
        } else {
            dbg("❌ PASSWORD VERIFICATION FAILED FOR USER {$id}");
            if (strtolower($user_type) === 'admin') {
                write_admin_activity($id, 'Admin Login Failed', 'Incorrect password supplied for admin account.', 'failure', 'admin', $id, null, null, $name, $db_email);
            }

            // ✅ UPDATED: count the failure; only lock on the 5th wrong attempt
            $result = increment_failed_attempts($id, $email);

            if ($result['locked']) {
                $mins = $result['minutes'];
                $time_text = $mins >= 60 ? round($mins / 60, 1) . ' hour(s)' : "{$mins} minute(s)";
                $_SESSION['message'] = "Too many failed attempts. Your account is locked for {$time_text}.";
            } else {
                $left = $result['remaining'];
                $_SESSION['message'] = "Incorrect email or password! You have {$left} attempt(s) left before your account is locked.";
            }
        }
    } else {
        $_SESSION['message'] = 'Incorrect email or password!';
        dbg("❌ USER NOT FOUND IN DATABASE");
    }

    session_write_close();
    header('Location: login.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8" />
   <meta name="viewport" content="width=device-width, initial-scale=1.0" />
   <meta name="theme-color" content="#5E1F13" />
   <meta name="mobile-web-app-capable" content="yes" />
   <meta name="apple-mobile-web-app-capable" content="yes" />
   <meta name="apple-mobile-web-app-status-bar-style" content="default" />
   <meta name="apple-mobile-web-app-title" content="Six Origins" />
   <title>Login | Six Origins Cafe</title>
   <link rel="manifest" href="./manifest.json">
   <link rel="apple-touch-icon" href="images/app-icon-192.png">
   <script src="Js/app-launch.js" defer></script>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
   <link rel="icon" type="image/png" href="images/app-icon-192.png">
   <style>
      :root{--primary-red:#C6453E;--dark-brown:#5E1F13;--gray-brown:#664C47;--light-cream:#FFF2E0;--white:#FFFFFF;--radius:16px;--shadow:0 8px 24px rgba(94,31,19,.08);--shadow-hover:0 12px 36px rgba(94,31,19,.12);--transition:all .3s cubic-bezier(.4,0,.2,1)}
      *{box-sizing:border-box;margin:0;padding:0;font-family:'Montserrat',system-ui,-apple-system,"Segoe UI",Roboto,Arial}
      html,body{height:100%;width:100%;background:#fff;overflow-x:hidden}
      body{display:flex;align-items:center;justify-content:center;color:var(--dark-brown);margin:0;padding:0}
      .layout{width:100%;height:100%;display:grid;grid-template-columns:1fr 1fr;gap:0}

      @keyframes fadeInUp{from{opacity:0;transform:translateY(40px)}to{opacity:1;transform:translateY(0)}}
      @keyframes slideInDown{from{opacity:0;transform:translateY(-15px)}to{opacity:1;transform:translateY(0)}}
      @keyframes slideInUp{from{opacity:0;transform:translateY(40px)}to{opacity:1;transform:translateY(0)}}
      @keyframes slideInRight{from{opacity:0;transform:translateX(40px)}to{opacity:1;transform:translateX(0)}}
      @keyframes fadeIn{from{opacity:0;transform:translateY(10px)}to{opacity:1;transform:translateY(0)}}
      @keyframes overlayFade{from{opacity:0}to{opacity:1}}
      @keyframes shake{0%,100%{transform:translateX(0)}25%{transform:translateX(-4px)}75%{transform:translateX(4px)}}
      @keyframes slideUp{from{transform:translateY(100%);opacity:0}to{transform:translateY(0);opacity:1}}

      /* IMAGE PANEL */
      .image-panel{position:relative;overflow:hidden;display:flex;align-items:center;justify-content:center;height:100vh}
      .image-panel::before{content:'';position:absolute;inset:0;background:radial-gradient(circle at center,rgba(0,0,0,.3) 0%,rgba(0,0,0,.5) 100%);z-index:2}
      .image-panel>img{width:100%;height:100%;object-fit:cover;object-position:center}
      .image-overlay{position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;z-index:3;color:#fff;gap:20px;padding:40px;animation:fadeInUp .8s ease-out}
      .image-overlay h2{font-size:3.2rem;font-weight:900;letter-spacing:-1.5px;text-shadow:0 8px 24px rgba(0,0,0,.4);display:flex;align-items:center;gap:16px;justify-content:center;flex-wrap:wrap;line-height:1.1}
      .image-overlay h2 img{width:80px;height:80px;object-fit:contain;filter:drop-shadow(0 4px 12px rgba(0,0,0,.3))}
      .image-overlay p{font-size:1.2rem;line-height:1.8;opacity:.95;text-shadow:0 4px 12px rgba(0,0,0,.3);max-width:420px;font-weight:500}

      /* HOME BUTTON */
      .home-btn{position:absolute;top:40px;left:40px;z-index:10;background:linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%);color:#fff;border:3px solid #fff;padding:12px 24px;border-radius:12px;font-weight:700;font-size:1rem;cursor:pointer;display:inline-flex;align-items:center;gap:10px;box-shadow:0 8px 24px rgba(94,31,19,.3);transition:var(--transition);text-decoration:none;animation:slideInDown .6s ease-out}
      .home-btn:hover{transform:translateY(-2px);box-shadow:0 12px 32px rgba(94,31,19,.4)}
      .home-btn:active{transform:translateY(0)}

      /* GUIDE */
      .guide-btn{position:absolute;bottom:40px;left:40px;z-index:10;width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%);border:3px solid #fff;color:#fff;font-size:1.6rem;cursor:pointer;display:flex;align-items:center;justify-content:center;box-shadow:0 8px 24px rgba(94,31,19,.3);transition:var(--transition);animation:slideInUp .6s ease-out .5s both}
      .guide-btn:hover{transform:scale(1.1);box-shadow:0 12px 32px rgba(94,31,19,.4)}
      .guide-btn:active{transform:scale(.95)}
      .guide-overlay{position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:999;display:none;align-items:center;justify-content:center;backdrop-filter:blur(4px);animation:overlayFade .3s ease-out;padding:20px;overflow-y:auto}
      .guide-overlay.active{display:flex}
      .guide-container{background:#fff;border-radius:24px;padding:48px 40px;max-width:540px;width:100%;max-height:85vh;overflow-y:auto;box-shadow:0 25px 60px rgba(0,0,0,.25);animation:slideInUp .4s ease-out;position:relative}
      .guide-close{position:absolute;top:24px;right:24px;width:44px;height:44px;border-radius:50%;background:#F5EFE7;border:none;color:var(--dark-brown);font-size:1.4rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:var(--transition)}
      .guide-close:hover{background:#E8DCD4;transform:rotate(90deg)}
      .guide-container h3{font-size:2rem;color:var(--dark-brown);margin-bottom:32px;font-weight:900}
      .guide-steps{display:flex;flex-direction:column;gap:20px}
      .guide-step{display:flex;gap:16px;padding-bottom:20px;border-bottom:1px solid #F0E6D8}
      .guide-step:last-child{border-bottom:none;padding-bottom:0}
      .step-number{width:48px;height:48px;min-width:48px;background:linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%);color:#fff;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:1.2rem;box-shadow:0 4px 12px rgba(198,69,62,.25)}
      .step-content h4{font-size:1.1rem;color:var(--dark-brown);margin-bottom:8px;font-weight:800}
      .step-content p{font-size:.95rem;color:var(--gray-brown);line-height:1.6}

      /* FORM PANEL */
      .form-panel{padding:40px;display:flex;flex-direction:column;justify-content:center;align-items:center;background:linear-gradient(135deg,#FFFAF5 0%,var(--light-cream) 100%);height:100vh;position:relative;overflow-y:auto}
      .form-panel::before{content:'';position:absolute;top:-100px;right:-100px;width:300px;height:300px;background:radial-gradient(circle,rgba(198,69,62,.1) 0%,transparent 70%);border-radius:50%;pointer-events:none}
      .form-wrapper{width:100%;max-width:480px;position:relative;z-index:1;animation:slideInRight .6s ease-out}
      .logo-section{display:flex;align-items:center;gap:16px;margin-bottom:48px}
      .logo-img{width:80px;height:80px;border-radius:16px;object-fit:contain;background:#fff;padding:8px;box-shadow:0 6px 16px rgba(94,31,19,.12);transition:var(--transition)}
      .logo-img:hover{transform:scale(1.05);box-shadow:var(--shadow-hover)}
      .logo-text h3{font-size:2rem;font-weight:900;color:var(--dark-brown);margin-bottom:4px}
      .logo-text p{font-size:.95rem;color:var(--primary-red);font-weight:700;letter-spacing:.5px}
      .form-header{margin-bottom:32px}
      .form-header .title{font-size:2.4rem;font-weight:900;color:var(--dark-brown);margin-bottom:12px;letter-spacing:-.5px}
      .form-header .subtitle{color:var(--gray-brown);font-size:1rem;line-height:1.6;font-weight:500}
      .field{margin-bottom:24px;animation:fadeIn .5s ease-out}
      .field:nth-child(1){animation-delay:.1s}
      .field:nth-child(2){animation-delay:.2s}
      label{display:block;font-size:.95rem;color:var(--dark-brown);margin-bottom:10px;font-weight:700;letter-spacing:.3px}
      .control{display:flex;align-items:center;gap:14px;background:var(--white);border:1.5px solid #F0E6D8;padding:14px 18px;border-radius:12px;transition:var(--transition);box-shadow:0 2px 8px rgba(94,31,19,.04)}
      .control:focus-within{border-color:var(--primary-red);background:var(--white);box-shadow:0 0 0 3px rgba(198,69,62,.12),0 4px 12px rgba(94,31,19,.08)}
      .control input{border:0;outline:none;font-size:1rem;width:100%;background:transparent;color:var(--dark-brown);font-weight:500;letter-spacing:.3px}
      .control input::placeholder{color:var(--gray-brown);font-weight:500}
      .control input:-webkit-autofill{-webkit-box-shadow:0 0 0 1000px #fff inset!important;-webkit-text-fill-color:var(--dark-brown)!important}
      .control .icon{color:var(--primary-red);font-size:1.2rem;width:24px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
      #togglePwd{background:none;border:none;color:var(--primary-red);cursor:pointer;font-size:1.1rem;width:40px;height:40px;display:flex;align-items:center;justify-content:center;flex-shrink:0;padding:0;margin:0 -8px 0 0;transition:var(--transition);border-radius:8px}
      #togglePwd:hover{background:rgba(198,69,62,.1);color:var(--dark-brown)}
      .action-links{display:flex;justify-content:space-between;align-items:center;margin:24px 0 32px 0;gap:16px;flex-wrap:wrap}
      .forgot-link{color:var(--primary-red);font-weight:700;text-decoration:none;font-size:.95rem;transition:var(--transition);white-space:nowrap}
      .forgot-link:hover{color:var(--dark-brown);text-decoration:underline}
      .signup-link{font-size:.95rem;color:var(--gray-brown)}
      .signup-link a{color:var(--primary-red);font-weight:700;text-decoration:none;transition:var(--transition)}
      .signup-link a:hover{color:var(--dark-brown);text-decoration:underline}
      .admin-apply{text-align:center;font-size:.88rem;color:var(--gray-brown);margin:-8px 0 24px 0;font-weight:500}
      .admin-apply a{color:var(--primary-red);font-weight:700;text-decoration:none;transition:var(--transition)}
      .admin-apply a:hover{color:var(--dark-brown);text-decoration:underline}

      /* MESSAGES */
      .message{padding:16px 18px;border-radius:var(--radius);margin-bottom:24px;display:flex;gap:12px;align-items:flex-start;box-shadow:0 6px 16px rgba(94,31,19,.1);animation:slideInDown .4s ease-out;font-weight:600;font-size:.95rem;border:1px solid transparent;transition:opacity .3s,transform .3s}
      .message i{font-size:1.1rem;flex-shrink:0;margin-top:2px}
      .message.error{background:linear-gradient(135deg,rgba(198,69,62,.12) 0%,rgba(198,69,62,.06) 100%);color:var(--primary-red);border-color:rgba(198,69,62,.2)}
      .message.success{background:linear-gradient(135deg,rgba(45,90,61,.12) 0%,rgba(45,90,61,.06) 100%);color:#2D5A3D;border-color:rgba(45,90,61,.2)}
      .message.warning{background:linear-gradient(135deg,rgba(255,193,7,.12) 0%,rgba(255,193,7,.06) 100%);color:#F57F17;border-color:rgba(255,193,7,.2)}
      #emailError,#pwdError{color:var(--primary-red);font-size:.85rem;margin-top:8px;display:none;gap:6px;align-items:flex-start;animation:shake .3s ease-out}
      #pwdHelp{color:var(--primary-red);font-size:.85rem;margin-top:8px;display:none;gap:6px;align-items:flex-start}

      /* BUTTON */
      .btn{width:100%;padding:14px 24px;background:linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%);color:#fff;border:0;border-radius:12px;font-weight:800;font-size:1.05rem;cursor:pointer;display:inline-flex;align-items:center;justify-content:center;gap:10px;transition:var(--transition);box-shadow:0 6px 16px rgba(198,69,62,.25);position:relative;overflow:hidden;margin-bottom:24px;letter-spacing:.5px}
      .btn::before{content:'';position:absolute;top:0;left:-100%;width:100%;height:100%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.25),transparent);transition:left .5s ease}
      .btn:hover:not(:disabled){transform:translateY(-2px);box-shadow:0 8px 20px rgba(94,31,19,.25)}
      .btn:hover:not(:disabled)::before{left:100%}
      .btn:disabled{opacity:.7;cursor:not-allowed}
      .divider{display:flex;align-items:center;gap:16px;margin:0 0 24px 0}
      .divider::before,.divider::after{content:'';height:1px;background:#F0E6D8;flex:1}
      .divider-text{color:var(--gray-brown);font-weight:700;font-size:.9rem}
      .terms-text{text-align:center;color:var(--gray-brown);font-size:.85rem;line-height:1.7;font-weight:500}
      .terms-text a{color:var(--primary-red);font-weight:700;text-decoration:none;transition:var(--transition)}
      .terms-text a:hover{text-decoration:underline;color:var(--dark-brown)}

      /* COOKIE CONSENT */
      .cookie-consent{position:fixed;bottom:0;left:0;right:0;background:linear-gradient(135deg,#2C2C2C 0%,#1A1A1A 100%);color:#fff;padding:20px;box-shadow:0 -4px 20px rgba(0,0,0,.3);z-index:1000;display:none;justify-content:center;align-items:center;animation:slideUp .4s ease-out;border-top:3px solid var(--primary-red)}
      .cookie-content{max-width:1000px;width:100%;display:flex;align-items:center;justify-content:space-between;gap:20px}
      .cookie-text h4{font-size:1rem;font-weight:800;margin-bottom:8px;color:#fff}
      .cookie-text p{font-size:.9rem;line-height:1.6;color:#DDD;margin:0}
      .cookie-text a{color:var(--primary-red);text-decoration:underline;transition:all .3s ease}
      .cookie-text a:hover{color:#FFA8A0}
      .cookie-actions{display:flex;gap:12px;flex-shrink:0}
      .cookie-btn{padding:10px 20px;border:none;border-radius:8px;font-weight:700;font-size:.9rem;cursor:pointer;transition:all .3s ease;white-space:nowrap}
      .cookie-btn.reject{background:#555;color:#fff}
      .cookie-btn.reject:hover{background:#444;transform:translateY(-2px)}
      .cookie-btn.accept{background:var(--primary-red);color:#fff}
      .cookie-btn.accept:hover{background:#B83A34;transform:translateY(-2px);box-shadow:0 4px 12px rgba(198,69,62,.3)}

      /* ✅ NEW: LOADING SCREEN */
      .loading-screen{position:fixed;inset:0;z-index:2000;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:22px;padding:24px;text-align:center;background:linear-gradient(135deg,#FFFAF5 0%,var(--light-cream) 100%);opacity:1;visibility:visible;transition:opacity .5s ease,visibility .5s ease}
      .loading-screen.hidden{opacity:0;visibility:hidden;pointer-events:none}
      .loading-screen.overlay-mode{background:rgba(255,246,235,.92);backdrop-filter:blur(6px)}
      .loader-logo-wrap{position:relative;width:132px;height:132px;display:flex;align-items:center;justify-content:center}
      .loader-ring{position:absolute;inset:0;border-radius:50%;border:5px solid rgba(198,69,62,.15);border-top-color:var(--primary-red);border-right-color:var(--primary-red);animation:loaderSpin 1s linear infinite}
      .loader-ring.second{inset:12px;border-width:4px;border-top-color:var(--dark-brown);border-right-color:transparent;border-left-color:var(--dark-brown);animation-duration:1.6s;animation-direction:reverse}
      .loader-logo{width:70px;height:70px;object-fit:contain;background:#fff;border-radius:16px;padding:8px;box-shadow:0 6px 16px rgba(94,31,19,.15);animation:loaderPulse 1.6s ease-in-out infinite}
      .loader-title{font-size:1.6rem;font-weight:900;color:var(--dark-brown);letter-spacing:-.3px}
      .loader-text{font-size:.95rem;font-weight:600;color:var(--gray-brown);display:flex;align-items:center;gap:4px}
      .loader-dots span{display:inline-block;width:5px;height:5px;margin-left:3px;border-radius:50%;background:var(--primary-red);animation:loaderDot 1.2s infinite ease-in-out both}
      .loader-dots span:nth-child(2){animation-delay:.18s}
      .loader-dots span:nth-child(3){animation-delay:.36s}
      .loader-bar{width:220px;max-width:70vw;height:6px;background:rgba(198,69,62,.15);border-radius:10px;overflow:hidden}
      .loader-bar span{display:block;height:100%;width:40%;border-radius:10px;background:linear-gradient(90deg,var(--primary-red),var(--dark-brown));animation:loaderBar 1.4s ease-in-out infinite}
      @keyframes loaderSpin{to{transform:rotate(360deg)}}
      @keyframes loaderPulse{0%,100%{transform:scale(1)}50%{transform:scale(1.08)}}
      @keyframes loaderDot{0%,80%,100%{transform:scale(.4);opacity:.4}40%{transform:scale(1);opacity:1}}
      @keyframes loaderBar{0%{transform:translateX(-120%)}100%{transform:translateX(320%)}}
      @media (prefers-reduced-motion:reduce){.loader-ring,.loader-logo,.loader-dots span,.loader-bar span{animation-duration:3s}}

      /* RESPONSIVE */
      @media (max-width:768px){
         .cookie-content{flex-direction:column;align-items:flex-start}
         .cookie-actions{width:100%;flex-direction:column}
         .cookie-btn{width:100%;padding:12px 16px}
         .cookie-consent{padding:16px}
         .cookie-text p{font-size:.85rem}
      }
      @media (max-width:768px){.home-btn{padding:10px 16px;font-size:.9rem;top:24px;left:24px}}
      @media (max-width:480px){.home-btn{padding:9px 14px;font-size:.85rem;gap:6px;top:16px;left:16px}.home-btn i{font-size:.85rem}}
      @media (max-width:1024px){
         .layout{grid-template-columns:1fr}
         .image-panel{display:none}
         .form-panel{justify-content:center}
      }
      @media (max-width:768px){
         .form-panel{padding:30px 24px;min-height:100vh}
         .form-wrapper{max-width:100%}
         .form-header .title{font-size:2rem}
         .control{padding:13px 16px}
         .field{margin-bottom:20px}
         .action-links{flex-direction:column;align-items:flex-start;margin:20px 0 28px 0}
         label{font-size:.9rem}
         .btn{padding:13px 20px;font-size:1rem}
         .logo-section{margin-bottom:36px}
         .logo-text h3{font-size:1.6rem}
         .guide-container{padding:40px 30px}
         .guide-container h3{font-size:1.6rem}
      }
      @media (max-width:480px){
         .form-panel{padding:20px 18px;min-height:100vh}
         .form-wrapper{max-width:100%}
         .form-header .title{font-size:1.6rem}
         .form-header .subtitle{font-size:.9rem}
         .logo-section{margin-bottom:28px;gap:12px}
         .logo-img{width:64px;height:64px}
         .logo-text h3{font-size:1.3rem}
         .logo-text p{font-size:.85rem}
         label{font-size:.9rem;margin-bottom:8px}
         .control{padding:12px 14px;gap:10px}
         .control input{font-size:.95rem}
         .control .icon{font-size:1rem;width:20px}
         #togglePwd{width:36px;height:36px;font-size:1rem}
         .field{margin-bottom:18px}
         .action-links{margin:18px 0 24px 0;gap:12px}
         .forgot-link{font-size:.85rem}
         .signup-link{font-size:.85rem}
         .admin-apply{font-size:.8rem}
         .btn{padding:12px 18px;font-size:.95rem;margin-bottom:20px}
         .divider{margin:0 0 20px 0}
         .divider-text{font-size:.85rem}
         .terms-text{font-size:.75rem;line-height:1.6}
         .message{padding:12px 14px;font-size:.85rem;margin-bottom:20px}
         .message i{font-size:1rem}
         #emailError,#pwdError,#pwdHelp{font-size:.75rem;margin-top:6px}
         .guide-container{padding:30px 20px;border-radius:20px}
         .guide-container h3{font-size:1.4rem;margin-bottom:24px}
         .guide-steps{gap:16px}
         .guide-step{gap:12px;padding-bottom:16px}
         .step-number{width:40px;height:40px;font-size:1rem}
         .step-content h4{font-size:.95rem}
         .step-content p{font-size:.85rem}
         .guide-btn{width:56px;height:56px;font-size:1.3rem;bottom:24px;left:24px}
         .image-overlay h2{font-size:2rem}
         .image-overlay h2 img{width:50px;height:50px}
         .image-overlay p{font-size:.95rem}
         .loader-logo-wrap{width:112px;height:112px}
         .loader-logo{width:58px;height:58px}
         .loader-title{font-size:1.3rem}
         .loader-text{font-size:.85rem}
      }
      @media (max-width:360px){
         .form-panel{padding:16px 14px}
         .form-header .title{font-size:1.4rem}
         .logo-img{width:56px;height:56px}
         .logo-text h3{font-size:1.1rem}
         .control{padding:11px 12px}
         .btn{padding:11px 16px;font-size:.9rem}
         .guide-container{padding:25px 16px}
         .loader-logo-wrap{width:96px;height:96px}
         .loader-logo{width:50px;height:50px}
      }

      /* =====================================================
         ✅ MOBILE RESPONSIVE FIX (added)
         - Back to Website + Help buttons now live INSIDE the
           login section on phones/tablets (top of the form)
         - Form is never clipped by the address bar / keyboard
         - Inputs are 16px (no iPhone zoom), taps are 44px+
         ===================================================== */

      /* hidden on desktop (desktop keeps the buttons on the image panel) */
      .mobile-actions{display:none}

      .m-btn{display:inline-flex;align-items:center;justify-content:center;gap:10px;min-height:46px;padding:11px 18px;border-radius:12px;font-weight:700;font-size:.95rem;text-decoration:none;cursor:pointer;transition:var(--transition);white-space:nowrap;-webkit-tap-highlight-color:transparent}
      .m-home{flex:1;background:linear-gradient(135deg,var(--primary-red) 0%,#B83A34 100%);color:#fff;border:0;box-shadow:0 6px 16px rgba(198,69,62,.25)}
      .m-home:active{transform:scale(.97)}
      .m-help{background:var(--white);color:var(--primary-red);border:1.5px solid #F0E6D8;box-shadow:0 2px 8px rgba(94,31,19,.06)}
      .m-help:active{transform:scale(.97);background:#FFF2E0}

      @media (max-width:1024px){
         html{height:auto;-webkit-text-size-adjust:100%}
         body{height:auto;min-height:100vh;min-height:100dvh;display:block;overflow-x:clip}
         .layout{height:auto;min-height:100vh;min-height:100dvh}

         /* form scrolls naturally, never clipped */
         .form-panel{height:auto;min-height:100vh;min-height:100dvh;justify-content:flex-start;padding:24px 24px 40px;overflow-x:hidden;overflow-y:visible}

         /* Back to Website + Help row, inside the login section */
         .mobile-actions{display:flex;gap:10px;align-items:center;width:100%;margin-bottom:28px}

         /* guide popup */
         .guide-overlay{align-items:flex-start;padding:16px}
         .guide-container{max-height:none;margin:auto;padding:36px 24px}
         .guide-container h3{padding-right:52px}
         .guide-close{top:14px;right:14px}

         /* cookie banner */
         .cookie-consent{max-height:60vh;max-height:60dvh;overflow-y:auto;padding-bottom:calc(16px + env(safe-area-inset-bottom, 0px))}
      }

      @media (max-width:768px){
         .form-panel{padding:20px 20px 36px}
         .control input{font-size:16px}
         #togglePwd{width:44px;height:44px}
         .action-links{gap:14px}
         .forgot-link,.signup-link a{display:inline-block;padding:4px 0}
         .btn{min-height:48px}
         .cookie-btn{min-height:44px}
      }

      @media (max-width:480px){
         .form-panel{padding:16px 16px 32px}
         .control input{font-size:16px}
         #togglePwd{width:44px;height:44px}
         .mobile-actions{margin-bottom:22px}
         .guide-container{padding:32px 18px}
         .loader-bar{width:180px}
      }

      @media (max-width:360px){
         .form-panel{padding:14px 14px 28px}
         .logo-section{gap:10px}
         .m-btn{padding:10px 14px;font-size:.88rem}
      }

      /* short landscape phones: tighter spacing */
      @media (max-height:480px) and (orientation:landscape){
         .mobile-actions{margin-bottom:16px}
         .logo-section{margin-bottom:20px}
         .form-header{margin-bottom:20px}
         .field{margin-bottom:14px}
      }
   </style>
   <noscript><style>.loading-screen{display:none!important}</style></noscript>
</head>
<body>

   <!-- ✅ NEW: LOADING SCREEN (shows while the page loads and again when you press Sign In) -->
   <div id="loadingScreen" class="loading-screen" role="status" aria-live="polite" aria-label="Loading">
      <div class="loader-logo-wrap">
         <div class="loader-ring"></div>
         <div class="loader-ring second"></div>
         <img src="images/app-icon-192.png" alt="Six Origins" class="loader-logo">
      </div>
      <div class="loader-title">Six Origins</div>
      <div class="loader-text"><span id="loaderMsg">Brewing your page</span>
         <span class="loader-dots"><span></span><span></span><span></span></span>
      </div>
      <div class="loader-bar"><span></span></div>
   </div>

   <div class="layout">
      <aside class="image-panel">
         <img src="images/logback.jpeg" alt="Coffee background">
         <div class="image-overlay">
            <h2>
               Welcome Back!
               <img src="images/logos.png" alt="Six Origins Icon">
            </h2>
            <p>Step into your cozy coffee corner. Your account is secure and ready for you.</p>
         </div>

         <!-- ✅ HOME BUTTON ON IMAGE PANEL -->
         <a href="index.php" class="home-btn" title="Go to home page">
            <i class="fa-solid fa-house"></i>
            <span>Home</span>
         </a>

         <button class="guide-btn" id="guideBtn" type="button" aria-label="Help guide">
            <i class="fas fa-question"></i>
         </button>
      </aside>

      <div class="guide-overlay" id="guideOverlay">
         <div class="guide-container">
            <button class="guide-close" id="guideCloseBtn" type="button" aria-label="Close guide">
               <i class="fas fa-times"></i>
            </button>
            <h3>How to Sign In</h3>
            <div class="guide-steps">
               <div class="guide-step">
                  <div class="step-number">1</div>
                  <div class="step-content">
                     <h4>Enter Your Email</h4>
                     <p>Use the email address associated with your Six Origins account.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">2</div>
                  <div class="step-content">
                     <h4>Enter Your Password</h4>
                     <p>Type your secure password. Click the eye icon to show/hide it.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">3</div>
                  <div class="step-content">
                     <h4>Forgot Your Password?</h4>
                     <p>Click the link below to reset it via email verification.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">4</div>
                  <div class="step-content">
                     <h4>New to Six Origins?</h4>
                     <p>Click "Sign up" to create a customer account (approved instantly). Want to be an admin? Use the "Register as Admin" link.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">5</div>
                  <div class="step-content">
                     <h4>Click Sign In</h4>
                     <p>You'll be securely logged in to your account! Admin, Delivery Rider, Senior, and PWD accounts must first be approved by an admin before they can log in.</p>
                  </div>
               </div>
            </div>
         </div>
      </div>

      <main class="form-panel">
         <div class="form-wrapper">

            <!-- ✅ MOBILE/TABLET ONLY: Back to Website + Help, inside the login section -->
            <div class="mobile-actions">
               <a href="index.php" class="m-btn m-home" title="Go to the website">
                  <i class="fa-solid fa-arrow-left"></i>
                  <span>View Homepage</span>
               </a>
               <button type="button" class="m-btn m-help" id="guideBtnMobile" aria-label="Help guide">
                  <i class="fas fa-question"></i>
                  <span>Help</span>
               </button>
            </div>

            <div class="logo-section">
               <img src="images/logos.png" alt="Six Origins Logo" class="logo-img">
               <div class="logo-text">
                  <h3>Six Origins</h3>
                  <p>☕ Coffee Lovers</p>
               </div>
            </div>

            <div class="form-header">
               <div class="title">Sign In</div>
               <div class="subtitle">Welcome back! Enter your credentials to continue.</div>
            </div>

            <?php
            if(isset($_SESSION['message'])){
               $raw = $_SESSION['message'];
               $messageType = '';
               if (is_array($raw)) {
                  $messageType = strtolower(is_string($raw['type'] ?? null) ? $raw['type'] : '');
                  $rawText = $raw['text'] ?? $raw['message'] ?? '';
                  $raw = is_scalar($rawText) ? (string)$rawText : '';
               } elseif (is_scalar($raw)) {
                  $raw = (string)$raw;
               } else {
                  $raw = '';
               }
               $msg = htmlspecialchars($raw, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
               $lowerMessage = strtolower($raw);
               $isError = in_array($messageType, ['error', 'danger'], true)
                  || stripos($lowerMessage, 'incorrect') !== false
                  || stripos($lowerMessage, 'please') !== false
                  || stripos($lowerMessage, 'pending') !== false;
               $isLocked = in_array($messageType, ['warning', 'locked'], true)
                  || stripos($lowerMessage, 'locked') !== false
                  || stripos($lowerMessage, 'try again') !== false;
               $cls = $isLocked ? 'message warning' : ($isError ? 'message error' : 'message success');
               $icon = $isLocked ? 'fa-lock' : ($isError ? 'fa-circle-exclamation' : 'fa-check-circle');
               echo '<div class="'. $cls .'"><i class="fa-solid ' . $icon . '"></i><div>'.$msg.'</div></div>';
               unset($_SESSION['message']);
            }
            ?>

            <form id="loginForm" method="post" novalidate>
               <div class="field">
                  <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                  <div class="control">
                     <span class="icon"><i class="fas fa-envelope"></i></span>
                     <input id="email" name="email" type="email" placeholder="your@email.com" required autocomplete="email">
                  </div>
                  <div id="emailError"><i class="fa-solid fa-circle-exclamation"></i><span>Please enter a valid email address</span></div>
               </div>

               <div class="field">
                  <label for="password"><i class="fas fa-lock"></i> Password</label>
                  <div class="control">
                     <span class="icon"><i class="fas fa-lock"></i></span>
                     <input id="password" name="password" type="password" placeholder="Enter your password" required autocomplete="current-password">
                     <button type="button" id="togglePwd" aria-label="Toggle password visibility">
                        <i class="fa-regular fa-eye"></i>
                     </button>
                  </div>
                  <div id="pwdHelp"><i class="fa-solid fa-lightbulb"></i><span>8+ characters recommended</span></div>
               </div>

               <div class="action-links">
                  <a href="forgot_password.php" class="forgot-link"><i class="fas fa-redo"></i> Reset Password</a>
                  <div class="signup-link">Don't have an account? <a href="register.php">Sign up</a></div>
               </div>

               <button class="btn" type="submit" name="submit" aria-label="Sign in button">
                  <i class="fa-solid fa-arrow-right-to-bracket"></i>
                  <span id="btnLabel">Sign In</span>
               </button>


               <div class="divider"><span class="divider-text">secure login</span></div>

               <div class="terms-text">
                  By signing in, you agree to our <a href="privacy_policy.php" target="_blank">Privacy Policy</a> and
                  <a href="cookies_consent.php" target="_blank">Cookie Policy</a>.
               </div>
            </form>
         </div>
      </main>
   </div>

   <!-- COOKIE CONSENT BANNER -->
   <div id="cookieConsent" class="cookie-consent">
      <div class="cookie-content">
         <div class="cookie-text">
            <h4>🍪 Cookie Consent</h4>
            <p>
               We use cookies to enhance your experience, secure your account, and analyze site usage.
               By continuing, you agree to our use of cookies.
               <a href="privacy_policy.php" target="_blank">Learn more</a>
            </p>
         </div>
         <div class="cookie-actions">
            <button id="rejectCookie" class="cookie-btn reject">Reject</button>
            <button id="acceptCookie" class="cookie-btn accept">Accept All</button>
         </div>
      </div>
   </div>

   <script>
      // ✅ NEW: Loading screen helpers
      const loadingScreen = document.getElementById('loadingScreen');
      const loaderMsg = document.getElementById('loaderMsg');
      const loadStart = Date.now();
      let isSubmittingLogin = false;

      function hideLoading() {
         loadingScreen.classList.add('hidden');
      }
      function showLoading(message) {
         loaderMsg.textContent = message;
         loadingScreen.classList.add('overlay-mode');
         loadingScreen.classList.remove('hidden');
      }

      // Hide the page-load screen once everything is ready (shown at least ~0.9s so it doesn't flash)
      window.addEventListener('load', () => {
         const wait = Math.max(0, 900 - (Date.now() - loadStart));
         setTimeout(() => {
            if (!isSubmittingLogin) hideLoading();
         }, wait);
      });
      // Only guard the initial page-load screen; keep the sign-in overlay visible
      // while the authentication request is being processed.
      setTimeout(() => {
         if (!isSubmittingLogin) hideLoading();
      }, 6000);
      window.addEventListener('pageshow', () => {
         isSubmittingLogin = false;
         hideLoading();
      });

      // Guide overlay
      document.getElementById('guideBtn').addEventListener('click', () => {
         document.getElementById('guideOverlay').classList.add('active');
      });

      // ✅ NEW: mobile Help button (inside the login section) opens the same guide
      document.getElementById('guideBtnMobile').addEventListener('click', () => {
         document.getElementById('guideOverlay').classList.add('active');
      });

      document.getElementById('guideCloseBtn').addEventListener('click', () => {
         document.getElementById('guideOverlay').classList.remove('active');
      });

      document.getElementById('guideOverlay').addEventListener('click', (e) => {
         if (e.target === document.getElementById('guideOverlay')) {
            document.getElementById('guideOverlay').classList.remove('active');
         }
      });

      // Password visibility toggle
      document.getElementById('togglePwd').addEventListener('click', (e) => {
         e.preventDefault();
         const pwd = document.getElementById('password');
         if (pwd.type === 'password') {
            pwd.type = 'text';
            document.getElementById('togglePwd').innerHTML = '<i class="fa-regular fa-eye-slash"></i>';
         } else {
            pwd.type = 'password';
            document.getElementById('togglePwd').innerHTML = '<i class="fa-regular fa-eye"></i>';
         }
      });

      // Form validation (+ loading screen once validation passes)
      document.getElementById('loginForm').addEventListener('submit', function(e) {
         const email = document.getElementById('email').value.trim();
         const password = document.getElementById('password').value;
         if (!email || !/^\S+@\S+\.\S+$/.test(email)) {
            document.getElementById('emailError').style.display = 'flex';
            e.preventDefault();
            return false;
         }
         if (!password) {
            e.preventDefault();
            document.getElementById('password').focus();
            return false;
         }
         document.getElementById('emailError').style.display = 'none';

         // Do NOT disable the submit button here - PHP checks $_POST['submit']
         isSubmittingLogin = true;
         showLoading('Signing you in');
      });

      document.getElementById('email').addEventListener('input', () => {
         document.getElementById('emailError').style.display = 'none';
      });

      // Check if user has already consented to cookies
      function checkCookieConsent() {
         const accepted = document.cookie.split(';').some(c =>
            c.trim().startsWith('cookies_accepted=')
         );

         if (!accepted) {
            document.getElementById('cookieConsent').style.display = 'flex';
         }
      }

      // Accept cookies
      document.getElementById('acceptCookie').addEventListener('click', () => {
         fetch('cookies_consent.php', {
            method: 'POST',
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'consent=accept'
         }).then(() => {
            document.getElementById('cookieConsent').style.display = 'none';
         });
      });

      // Reject cookies
      document.getElementById('rejectCookie').addEventListener('click', () => {
         fetch('cookies_consent.php', {
            method: 'POST',
            headers: {
               'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'consent=deny'
         }).then(() => {
            document.getElementById('cookieConsent').style.display = 'none';
         });
      });

      // Check consent on page load
      window.addEventListener('load', checkCookieConsent);

      // Auto-close messages after 7 seconds
      const messages = document.querySelectorAll('.message');
      messages.forEach(msg => {
         setTimeout(() => {
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-10px)';
            setTimeout(() => msg.remove(), 300);
         }, 7000);
      });
   </script>
</body>
</html>