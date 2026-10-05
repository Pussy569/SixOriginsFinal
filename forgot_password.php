<?php
include 'config.php';
include 'send_email.php';

$message = '';

if (isset($_POST['submit'])) {
    $email = mysqli_real_escape_string($conn, $_POST['email']);
    $token = bin2hex(random_bytes(32)); // secure token
    $expiry = date('Y-m-d H:i:s', strtotime('+1 hour')); // expires in 1 hour

    $check_email = mysqli_query($conn, "SELECT * FROM `users` WHERE email = '$email'");

    if (mysqli_num_rows($check_email) > 0) {
        $update = mysqli_query($conn, "UPDATE `users` SET reset_token = '$token', reset_token_expiry = '$expiry' WHERE email = '$email'");

        if ($update) {
            $base_url = rtrim(getenv('APP_BASE_URL') ?: 'http://localhost', '/');
            $reset_link = $base_url . "/reset_password.php?token=$token";
            $result = sendResetLink($email, $reset_link);

            if ($result === true) {
                $message = "<div class='message success'><i class='fa-solid fa-circle-check'></i> <span>Reset link sent! Please check your inbox.</span></div>";
            } else {
                $message = "<div class='message error'><i class='fa-solid fa-circle-exclamation'></i> <span>Error: " . htmlspecialchars($result) . "</span></div>";
            }
        } else {
            $message = "<div class='message error'><i class='fa-solid fa-database'></i> <span>Database error. Please try again.</span></div>";
        }
    } else {
        $message = "<div class='message error'><i class='fa-solid fa-user-xmark'></i> <span>Email not found in our records!</span></div>";
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Forgot Password — Six Origins Cafe</title>

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

      * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: 'Montserrat', sans-serif;
      }

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

      /* Breadcrumb */
      .breadcrumb {
        margin-bottom: 24px;
        font-size: 0.85rem;
        letter-spacing: 0.5px;
        text-transform: uppercase;
        font-weight: 700;
        color: var(--gray-brown);
      }

      .breadcrumb a {
        color: var(--primary-red);
        text-decoration: none;
        transition: var(--transition);
      }

      .breadcrumb a:hover { color: var(--dark-brown); }

      /* The Main Card */
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

      /* Sidebar Info Area */
      .info-side {
        flex: 1;
        background: var(--dark-brown);
        color: var(--light-cream);
        padding: 40px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        position: relative;
        overflow: hidden;
      }

      .info-side::after {
        content: '';
        position: absolute;
        bottom: -50px;
        right: -50px;
        width: 200px;
        height: 200px;
        background: var(--primary-red);
        border-radius: 50%;
        opacity: 0.1;
      }

      .info-side h2 {
        font-size: 2rem;
        font-weight: 900;
        line-height: 1.2;
        margin-bottom: 20px;
        z-index: 1;
      }

      .info-side p {
        font-size: 0.95rem;
        opacity: 0.9;
        line-height: 1.8;
        margin-bottom: 30px;
        z-index: 1;
      }

      .cafe-logo-mock {
        width: 60px;
        height: 60px;
        background: var(--primary-red);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-bottom: 20px;
      }

      /* Form Area */
      .form-side {
        flex: 1.2;
        padding: 50px;
        background: var(--white);
      }

      .form-header h3 {
        font-size: 1.6rem;
        font-weight: 800;
        margin-bottom: 8px;
        color: var(--dark-brown);
      }

      .form-header p {
        color: var(--gray-brown);
        font-size: 0.9rem;
        margin-bottom: 30px;
      }

      /* Input Styling */
      .input-group {
        margin-bottom: 20px;
      }

      .input-group label {
        display: block;
        font-weight: 700;
        font-size: 0.8rem;
        margin-bottom: 8px;
        text-transform: uppercase;
        color: var(--dark-brown);
      }

      .input-field {
        position: relative;
      }

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

      /* Button Styles */
      .btn-stack {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-top: 20px;
      }

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

      .btn-primary {
        background: var(--primary-red);
        color: var(--white);
        box-shadow: 0 8px 20px rgba(198, 69, 62, 0.2);
      }

      .btn-primary:hover {
        transform: translateY(-3px);
        box-shadow: 0 12px 25px rgba(198, 69, 62, 0.3);
        filter: brightness(1.1);
      }

      .btn-outline {
        background: transparent;
        color: var(--gray-brown);
        border: 2px solid var(--accent-tan);
      }

      .btn-outline:hover {
        background: var(--light-cream);
        border-color: var(--dark-brown);
        color: var(--dark-brown);
      }

      /* Alerts */
      .message {
        padding: 15px;
        border-radius: var(--radius-md);
        margin-bottom: 25px;
        font-size: 0.9rem;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
      }

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
        line-height: 1.5;
      }

      .security-note i { color: var(--primary-red); }

      /* Responsive */
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
        <a href="index.php"><i class="fa-solid fa-house"></i> Home</a> / Forgot Password
    </nav>

    <div class="auth-card">
        <section class="info-side">
            <div class="cafe-logo-mock">
                <i class="fa-solid fa-mug-hot fa-xl"></i>
            </div>
            <h2>Forgot your password?</h2>
            <p>It happens to the best of us! Provide your email address and we'll help you get back into your account in no time.</p>
            
            <div style="margin-top: auto; font-size: 0.75rem; opacity: 0.7;">
                <i class="fa-solid fa-shield-halved"></i> 256-bit Secure Encryption
            </div>
        </section>

        <section class="form-side">
            <?php if ($message !== '') echo $message; ?>

            <div class="form-header">
                <h3>Reset Request</h3>
                <p>Please enter your registered email below.</p>
            </div>

            <form action="" method="post" novalidate>
                <div class="input-group">
                    <label for="email">Email Address</label>
                    <div class="input-field">
                        <i class="fa-solid fa-envelope"></i>
                        <input id="email" type="email" name="email" class="box" placeholder="example@mail.com" required>
                    </div>
                </div>

                <div class="btn-stack">
                    <button type="submit" name="submit" class="btn btn-primary">
                        <span>Send Reset Link</span>
                        <i class="fa-solid fa-arrow-right"></i>
                    </button>
                    
                    <a href="login.php" class="btn btn-outline">
                        <i class="fa-solid fa-chevron-left"></i>
                        <span>Back to Login</span>
                    </a>
                </div>

                <div class="security-note">
                    <i class="fa-solid fa-circle-info"></i>
                    <p>For your security, this reset link will automatically expire in <strong>60 minutes</strong>. If you don't see the email, check your spam.</p>
                </div>
            </form>
        </section>
    </div>
</main>

<?php include 'footer.php'; ?>

<script>
    // Message dismissal
    document.addEventListener('DOMContentLoaded', () => {
        const msg = document.querySelector('.message');
        if(msg) {
            setTimeout(() => {
                msg.style.transition = 'all 0.5s ease';
                msg.style.opacity = '0';
                msg.style.transform = 'translateX(20px)';
                setTimeout(() => msg.remove(), 500);
            }, 5000);
        }
    });
</script>

<script src="Js/script1.js"></script>
</body>
</html>