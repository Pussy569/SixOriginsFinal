<?php
include 'config.php';

$error = null;
$success = null;

if (isset($_GET['token'])) {
    $token = mysqli_real_escape_string($conn, $_GET['token']);
    $check_token = mysqli_query($conn, "SELECT * FROM `users` WHERE reset_token = '$token'");

    if (mysqli_num_rows($check_token) > 0) {
        $row = mysqli_fetch_assoc($check_token);
        $expiry = $row['reset_token_expiry'];
        $current_time = date('Y-m-d H:i:s');

        if ($expiry && $current_time > $expiry) {
            $error = "This reset link has expired!";
        } else {
            if (isset($_POST['submit'])) {
                $new_pass = $_POST['new_password'];
                $confirm_pass = $_POST['confirm_password'];

                if (empty($new_pass)) {
                    $error = "Please enter a new password.";
                } elseif ($new_pass === $confirm_pass) {
                    $hashed_password = password_hash($new_pass, PASSWORD_DEFAULT);
                    $update = mysqli_query($conn, "UPDATE `users` SET password = '$hashed_password', reset_token = NULL, reset_token_expiry = NULL WHERE reset_token = '$token'");

                    if ($update) {
                        $success = "Password changed successfully! <a href='login.php' style='color:var(--primary-red); text-decoration:underline;'>Login now</a>.";
                    } else {
                        $error = "Something went wrong. Please try again.";
                    }
                } else {
                    $error = "Passwords do not match!";
                }
            }
        }
    } else {
        $error = "Invalid or expired reset link!";
    }
} else {
    $error = "No token provided!";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Reset Password — Six Origins Cafe</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />

    <style>
      :root {
        --primary-red: #C6453E;
        --dark-brown: #5E1F13;
        --gray-brown: #664C47;
        --light-cream: #FFF2E0;
        --white: #FFFFFF;
        --accent-tan: #F0E6D8;
        --radius-lg: 24px;
        --radius-md: 12px;
        --shadow-soft: 0 20px 40px rgba(94, 31, 19, 0.08);
        --transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
      }

      * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', sans-serif; }

      body {
        min-height: 100vh;
        background: radial-gradient(circle at top right, #FFFFFF, var(--light-cream));
        color: var(--dark-brown);
        display: flex;
        flex-direction: column;
      }

      .page-container {
        max-width: 900px;
        margin: 40px auto;
        padding: 0 20px;
        flex: 1;
      }

      .breadcrumb {
        margin-bottom: 24px;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--gray-brown);
      }

      .breadcrumb a { color: var(--primary-red); text-decoration: none; }

      /* Main Layout Card */
      .auth-card {
        background: var(--white);
        border-radius: var(--radius-lg);
        box-shadow: var(--shadow-soft);
        display: flex;
        overflow: hidden;
        border: 1px solid var(--accent-tan);
        animation: cardFadeIn 0.8s ease-out;
      }

      @keyframes cardFadeIn {
        from { opacity: 0; transform: translateY(30px); }
        to { opacity: 1; transform: translateY(0); }
      }

      /* Dark Sidebar */
      .info-side {
        flex: 1;
        background: var(--dark-brown);
        color: var(--light-cream);
        padding: 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
      }

      .info-side h2 { font-size: 2rem; font-weight: 900; line-height: 1.2; margin-bottom: 20px; }
      .info-side p { font-size: 0.95rem; opacity: 0.9; line-height: 1.8; margin-bottom: 30px; }

      .cafe-icon {
        width: 60px;
        height: 60px;
        background: var(--primary-red);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
      }

      /* Form Side */
      .form-side { flex: 1.2; padding: 50px; background: var(--white); }
      .form-header h3 { font-size: 1.6rem; font-weight: 800; margin-bottom: 8px; }
      .form-header p { color: var(--gray-brown); font-size: 0.9rem; margin-bottom: 30px; }

      .input-group { margin-bottom: 20px; }
      .input-group label {
        display: block;
        font-weight: 700;
        font-size: 0.8rem;
        margin-bottom: 8px;
        text-transform: uppercase;
      }

      .input-field { position: relative; }
      .input-field i {
        position: absolute;
        left: 16px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--primary-red);
      }

      .box {
        width: 100%;
        padding: 15px 15px 15px 45px;
        border-radius: var(--radius-md);
        border: 2px solid var(--accent-tan);
        font-size: 1rem;
        font-weight: 500;
        transition: var(--transition);
        background: #FAFAFA;
      }

      .box:focus {
        outline: none;
        border-color: var(--primary-red);
        background: var(--white);
        box-shadow: 0 5px 15px rgba(198, 69, 62, 0.05);
      }

      /* Buttons */
      .btn-stack { display: flex; flex-direction: column; gap: 12px; margin-top: 20px; }
      .btn {
        width: 100%;
        padding: 16px;
        border-radius: var(--radius-md);
        border: none;
        font-weight: 800;
        font-size: 0.9rem;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
        transition: var(--transition);
        text-decoration: none;
      }

      .btn-primary { background: var(--primary-red); color: var(--white); box-shadow: 0 8px 20px rgba(198, 69, 62, 0.2); }
      .btn-primary:hover { transform: translateY(-3px); box-shadow: 0 12px 25px rgba(198, 69, 62, 0.3); }

      .btn-outline { background: transparent; color: var(--gray-brown); border: 2px solid var(--accent-tan); }
      .btn-outline:hover { background: var(--light-cream); color: var(--dark-brown); border-color: var(--dark-brown); }

      /* Messages */
      .message { padding: 15px; border-radius: var(--radius-md); margin-bottom: 25px; font-size: 0.9rem; font-weight: 600; display: flex; align-items: center; gap: 12px; }
      .message.success { background: #E8F5E9; color: #2E7D32; border: 1px solid #A5D6A7; }
      .message.error { background: #FFEBEE; color: #C62828; border: 1px solid #EF9A9A; }

      .security-note {
        margin-top: 25px;
        padding-top: 20px;
        border-top: 1px dashed var(--accent-tan);
        display: flex;
        gap: 10px;
        font-size: 0.8rem;
        color: var(--gray-brown);
      }

      @media (max-width: 850px) {
        .auth-card { flex-direction: column; }
        .info-side { padding: 30px; text-align: center; align-items: center; }
        .form-side { padding: 30px; }
      }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<main class="page-container">
    <nav class="breadcrumb">
        <a href="index.php"><i class="fa-solid fa-house"></i> Home</a> / Reset Password
    </nav>

    <div class="auth-card">
        <section class="info-side">
            <div class="cafe-icon">
                <i class="fa-solid fa-key fa-xl"></i>
            </div>
            <h2>Secure your account.</h2>
            <p>Create a strong, unique password to ensure your account at Six Origins Cafe stays safe.</p>
            <div style="margin-top: auto; font-size: 0.75rem; opacity: 0.7;">
                <i class="fa-solid fa-lock"></i> End-to-end security
            </div>
        </section>

        <section class="form-side">
            <?php if ($error): ?>
                <div class="message error"><i class="fa-solid fa-circle-xmark"></i> <span><?php echo $error; ?></span></div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="message success"><i class="fa-solid fa-circle-check"></i> <span><?php echo $success; ?></span></div>
            <?php endif; ?>

            <?php if (!$success && !$error || (!$success && $error && $error != "Invalid or expired reset link!" && $error != "No token provided!" && $error != "This reset link has expired!")): ?>
                <div class="form-header">
                    <h3>New Password</h3>
                    <p>Enter your new credentials below.</p>
                </div>

                <form action="" method="post" novalidate>
                    <div class="input-group">
                        <label for="new_password">New Password</label>
                        <div class="input-field">
                            <i class="fa-solid fa-shield-halved"></i>
                            <input id="new_password" type="password" name="new_password" class="box" placeholder="Min. 8 characters" required>
                        </div>
                    </div>

                    <div class="input-group">
                        <label for="confirm_password">Confirm Password</label>
                        <div class="input-field">
                            <i class="fa-solid fa-check-double"></i>
                            <input id="confirm_password" type="password" name="confirm_password" class="box" placeholder="Repeat password" required>
                        </div>
                    </div>

                    <div class="btn-stack">
                        <button type="submit" name="submit" class="btn btn-primary">
                            <span>Update Password</span>
                            <i class="fa-solid fa-circle-check"></i>
                        </button>
                        <a href="login.php" class="btn btn-outline">Back to Login</a>
                    </div>
                </form>
            <?php elseif ($error && ($error == "Invalid or expired reset link!" || $error == "No token provided!" || $error == "This reset link has expired!")): ?>
                <div class="btn-stack" style="margin-top: 20px;">
                    <a href="forgot_password.php" class="btn btn-primary">Request New Link</a>
                    <a href="login.php" class="btn btn-outline">Back to Login</a>
                </div>
            <?php endif; ?>

            <div class="security-note">
                <i class="fa-solid fa-circle-info"></i>
                <p>Use at least 8 characters with a mix of letters, numbers, and symbols for maximum security.</p>
            </div>
        </section>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
    // Auto-dismiss logic for messages
    document.addEventListener('DOMContentLoaded', () => {
        const msg = document.querySelector('.message');
        if(msg && !msg.innerHTML.includes('Login now')) { // Don't hide if it contains the login link
            setTimeout(() => {
                msg.style.transition = 'all 0.5s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateY(-10px)';
                setTimeout(() => msg.remove(), 500);
            }, 6000);
        }
    });
</script>

<script src="Js/script1.js"></script>
</body>
</html>