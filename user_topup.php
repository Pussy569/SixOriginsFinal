<?php
include 'config.php';

if (!isset($_SESSION['user_id'])) {
    header('location:login.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$message = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    if (!is_string($csrf_token) || !validateCSRFToken($csrf_token)) {
        http_response_code(403);
        $message[] = '❌ Your session expired. Refresh the page and try again.';
    } else {
        $amount_input = trim((string)($_POST['amount'] ?? ''));
        $amount = filter_var($amount_input, FILTER_VALIDATE_FLOAT);
        $upload = $_FILES['screenshot'] ?? null;

        if ($amount === false || !is_finite((float)$amount) || $amount <= 0 || $amount > 99999999.99 || round((float)$amount, 2) !== (float)$amount) {
            $message[] = '❌ Enter a valid amount with no more than two decimal places.';
        } elseif (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $message[] = '❌ Choose a valid transaction screenshot to upload.';
        } elseif (($upload['size'] ?? 0) <= 0 || $upload['size'] > 5 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            $message[] = '❌ Screenshot must be an image no larger than 5 MB.';
        } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : false;
            if ($finfo) {
                finfo_close($finfo);
            }
            $image_info = @getimagesize($upload['tmp_name']);
            $extensions = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
            ];

            if (!is_string($mime) || !isset($extensions[$mime]) || $image_info === false || $image_info['mime'] !== $mime) {
                $message[] = '❌ Upload a valid JPG, PNG, or WebP image.';
            } else {
                $proof_dir = getenv('TOPUP_PROOF_DIR') ?: (sys_get_temp_dir() . '/six-origins-topup-proofs');
                if (!is_dir($proof_dir) && !mkdir($proof_dir, 0700, true) && !is_dir($proof_dir)) {
                    error_log('Could not create the private top-up proof directory.');
                    $message[] = '❌ Upload could not be saved. Please try again later.';
                } else {
                    $proof_name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $proof_path = rtrim($proof_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $proof_name;

                    if (!move_uploaded_file($upload['tmp_name'], $proof_path)) {
                        error_log('Could not save a validated top-up proof upload.');
                        $message[] = '❌ Upload could not be saved. Please try again later.';
                    } else {
                        try {
                            $stmt = $conn->prepare("INSERT INTO topup_requests (user_id, amount, screenshot, status) VALUES (?, ?, ?, 'pending')");
                            $stmt->bind_param('ids', $user_id, $amount, $proof_name);
                            $stmt->execute();
                            $stmt->close();
                            $message[] = '✅ Top-up request submitted! Please wait for admin approval.';
                        } catch (mysqli_sql_exception $e) {
                            @unlink($proof_path);
                            error_log('Could not save top-up request: ' . $e->getMessage());
                            $message[] = '❌ Your request could not be submitted. Please try again later.';
                        }
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Top-Up Request — Six Origins Cafe</title>
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
        --radius: 16px;
        --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
        --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
        --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        --max-width: 1200px;
      }

      * {
        box-sizing: border-box;
        margin: 0;
        padding: 0;
        font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      }

      html, body {
        height: 100%;
        width: 100%;
        overflow-x: hidden;
      }

      body {
        min-height: 100vh;
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        color: var(--dark-brown);
        line-height: 1.7;
        font-size: 16px;
        margin: 0;
        padding: 0;
      }

      /* HERO SECTION WITH BACKGROUND IMAGE */
      .hero {
        position: relative;
        width: 100vw;
        left: 50%;
        right: 50%;
        margin-left: -50vw;
        margin-right: -50vw;
        margin-top: 0;
        margin-bottom: 0;
        height: 550px;
        overflow: hidden;
        display: flex;
        align-items: center;
        justify-content: center;
        animation: fadeInDown 0.6s ease-out;
        box-shadow: 0 8px 32px rgba(94, 31, 19, 0.15);
        border-bottom: 1px solid rgba(255, 255, 255, 0.1);
        background-attachment: fixed;
      }

      .hero::before {
        content: '';
        position: absolute;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: radial-gradient(circle at center, rgba(0, 0, 0, 0.3) 0%, rgba(0, 0, 0, 0.5) 100%);
        z-index: 1;
        pointer-events: none;
      }

      .hero-content {
        position: relative;
        z-index: 2;
        text-align: center;
        max-width: 700px;
        padding: 40px;
        animation: scaleIn 0.8s ease-out 0.2s both;
      }

      @keyframes fadeInDown {
        from {
          opacity: 0;
          transform: translateY(-30px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @keyframes scaleIn {
        from {
          opacity: 0;
          transform: scale(0.95);
        }
        to {
          opacity: 1;
          transform: scale(1);
        }
      }

      @keyframes slideUp {
        from {
          opacity: 0;
          transform: translateY(20px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @keyframes slideDown {
        from {
          opacity: 0;
          transform: translateY(-20px);
        }
        to {
          opacity: 1;
          transform: translateY(0);
        }
      }

      @keyframes fadeIn {
        from {
          opacity: 0;
        }
        to {
          opacity: 1;
        }
      }

      .hero h1 {
        font-size: 4.2rem;
        margin-bottom: 24px;
        color: #ffffff;
        font-weight: 900;
        letter-spacing: -1px;
        line-height: 1.15;
        text-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
      }

      .hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: 1.4rem;
        margin-bottom: 28px;
        opacity: 1;
        font-weight: 500;
        line-height: 1.6;
        text-shadow: 0 6px 18px rgba(0, 0, 0, 0.4);
      }

      .hero-buttons {
        display: flex;
        gap: 16px;
        flex-wrap: wrap;
        justify-content: center;
      }

      .hero .btn, .hero .btn-ghost {
        padding: 14px 32px;
        border-radius: 10px;
        font-weight: 800;
        border: none;
        font-size: 1rem;
        cursor: pointer;
        transition: var(--transition);
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        position: relative;
        overflow: hidden;
      }

      .hero .btn::before, .hero .btn-ghost::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s ease;
      }

      .hero .btn:hover::before, .hero .btn-ghost:hover::before {
        left: 100%;
      }

      .hero .btn {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
      }

      .hero .btn:hover {
        background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
      }

      .hero .btn-ghost {
        background: rgba(255, 255, 255, 0.12);
        color: #ffffff;
        border: 2px solid rgba(255, 255, 255, 0.35);
        backdrop-filter: blur(10px);
      }

      .hero .btn-ghost:hover {
        background: rgba(255, 255, 255, 0.2);
        border-color: rgba(255, 255, 255, 0.6);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
      }

      .content-wrapper {
        max-width: var(--max-width);
        margin: 0 auto;
        padding: 44px 24px 60px;
        width: 100%;
      }

      .breadcrumb {
        color: var(--gray-brown);
        margin-bottom: 15px;
        font-size: 0.97rem;
        font-weight: 500;
      }

      .breadcrumb a {
        color: var(--primary-red);
        text-decoration: none;
        font-weight: 700;
        transition: var(--transition);
      }

      .breadcrumb a:hover {
        color: var(--dark-brown);
      }

      .topup-wrap {
        display: grid;
        grid-template-columns: 1fr 380px;
        gap: 28px;
        align-items: start;
        animation: fadeIn 0.6s ease-out 0.4s both;
      }

      .card {
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: var(--radius);
        padding: 28px;
        box-shadow: var(--shadow);
        border: 1.5px solid #F0E6D8;
        transition: var(--transition);
        animation: slideUp 0.4s ease;
      }

      .card:hover {
        box-shadow: var(--shadow-hover);
        border-color: var(--primary-red);
        transform: translateY(-4px);
      }

      .card h2 {
        margin-top: 0;
        margin-bottom: 18px;
        font-weight: 900;
        font-size: 1.8rem;
        color: var(--dark-brown);
        letter-spacing: -0.5px;
      }

      .card h3 {
        margin: 0 0 16px 0;
        color: var(--dark-brown);
        font-size: 1.3rem;
        font-weight: 900;
      }

      .messages {
        display: flex;
        flex-direction: column;
        gap: 12px;
        margin-bottom: 20px;
        animation: slideDown 0.4s ease;
      }

      .msg-success {
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
        border-left: 5px solid var(--primary-red);
        padding: 15px 18px;
        border-radius: var(--radius);
        color: var(--primary-red);
        font-weight: 600;
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.1);
        display: flex;
        gap: 10px;
        align-items: center;
        border: 1px solid rgba(198, 69, 62, 0.2);
      }

      .msg-success::before {
        content: '✓';
        font-weight: 900;
        font-size: 1.3em;
        flex-shrink: 0;
      }

      .msg-error {
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
        border-left: 5px solid var(--primary-red);
        padding: 15px 18px;
        border-radius: var(--radius);
        color: var(--primary-red);
        font-weight: 600;
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.1);
        display: flex;
        gap: 10px;
        align-items: center;
        border: 1px solid rgba(198, 69, 62, 0.2);
      }

      .msg-error::before {
        content: '⚠';
        font-weight: 900;
        font-size: 1.3em;
        flex-shrink: 0;
      }

      form {
        display: flex;
        flex-direction: column;
        gap: 16px;
      }

      label {
        display: block;
        font-weight: 700;
        margin-bottom: 8px;
        color: var(--dark-brown);
        font-size: 1rem;
      }

      input[type="number"],
      input[type="file"] {
        width: 100%;
        padding: 12px 14px;
        border-radius: 10px;
        border: 1.5px solid #F0E6D8;
        background: linear-gradient(135deg, #FFFAF5 0%, #FFFBF7 100%);
        font-size: 0.95rem;
        color: var(--dark-brown);
        font-weight: 500;
        transition: var(--transition);
        font-family: inherit;
      }

      input[type="number"]:focus,
      input[type="file"]:focus {
        outline: none;
        border-color: var(--primary-red);
        box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
      }

      input[type="number"]::placeholder {
        color: var(--gray-brown);
      }

      .helper {
        font-size: 0.92rem;
        color: var(--gray-brown);
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
        font-weight: 500;
      }

      .helper i {
        font-size: 0.85rem;
      }

      .preview {
        width: 100%;
        height: 220px;
        border-radius: 12px;
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        border: 1.5px dashed #F0E6D8;
        display: flex;
        align-items: center;
        justify-content: center;
        overflow: hidden;
        margin-top: 8px;
        transition: var(--transition);
      }

      .preview:hover {
        border-color: var(--primary-red);
        background: linear-gradient(135deg, var(--light-cream) 0%, #FFFAF5 100%);
      }

      .preview img {
        width: 100%;
        height: 100%;
        object-fit: contain;
        display: block;
      }

      .preview-empty {
        color: var(--gray-brown);
        font-weight: 700;
        text-align: center;
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 8px;
      }

      .preview-empty i {
        font-size: 2rem;
        opacity: 0.5;
      }

      .form-actions {
        display: flex;
        gap: 12px;
        justify-content: flex-end;
        margin-top: 18px;
      }

      .btn {
        padding: 11px 24px;
        border-radius: 10px;
        border: 0;
        cursor: pointer;
        font-weight: 800;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        font-size: 0.98em;
        transition: var(--transition);
        text-decoration: none;
        position: relative;
        overflow: hidden;
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

      .btn:hover::before {
        left: 100%;
      }

      .btn-primary {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
      }

      .btn-primary:hover {
        background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
      }

      .btn-primary:active {
        transform: translateY(0px);
      }

      .btn-ghost {
        background: rgba(255, 255, 255, 0.6);
        border: 1.5px solid #F0E6D8;
        color: var(--dark-brown);
        font-weight: 700;
        box-shadow: 0 2px 6px rgba(94, 31, 19, 0.05);
      }

      .btn-ghost::before {
        display: none;
      }

      .btn-ghost:hover {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        border-color: var(--primary-red);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
      }

      .gcash-card {
        background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
        border-radius: 12px;
        padding: 18px;
        border: 1.5px solid #F0E6D8;
        transition: var(--transition);
      }

      .gcash-card:hover {
        border-color: var(--primary-red);
        box-shadow: var(--shadow);
      }

      .gcash-card p {
        margin: 0 0 12px 0;
        font-size: 0.97rem;
        color: var(--dark-brown);
        font-weight: 500;
      }

      .gcash-card strong {
        color: var(--dark-brown);
        font-weight: 800;
      }

      .gcash-number {
        color: var(--primary-red);
        font-weight: 900;
        font-size: 1.1em;
        letter-spacing: 1px;
        font-family: 'Courier New', monospace;
      }

      .gcash-name {
        color: var(--dark-brown);
        font-weight: 800;
      }

      .payment-steps {
        margin-top: 16px;
        padding-top: 16px;
        border-top: 1px dashed rgba(94, 31, 19, 0.1);
      }

      .payment-steps h4 {
        margin: 0 0 12px 0;
        color: var(--dark-brown);
        font-size: 1rem;
        font-weight: 900;
      }

      .payment-steps ul {
        margin: 0;
        padding: 0;
        list-style: none;
      }

      .payment-steps li {
        margin-bottom: 10px;
        padding-left: 24px;
        position: relative;
        color: var(--gray-brown);
        font-weight: 600;
        font-size: 0.93rem;
      }

      .payment-steps li::before {
        content: '→';
        position: absolute;
        left: 0;
        color: var(--primary-red);
        font-weight: 900;
        font-size: 1.2em;
      }

      .info-box {
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.05) 0%, rgba(198, 69, 62, 0.02) 100%);
        border-radius: 10px;
        padding: 14px;
        border: 1.5px solid #F0E6D8;
        margin-top: 14px;
        font-size: 0.92rem;
        color: var(--gray-brown);
        font-weight: 600;
        display: flex;
        gap: 8px;
        align-items: flex-start;
      }

      .info-box i {
        color: var(--primary-red);
        font-size: 0.95rem;
        margin-top: 2px;
        flex-shrink: 0;
      }

      /* Responsive */
      @media (max-width: 1100px) {
        .topup-wrap {
          grid-template-columns: 1fr;
        }
      }

      @media (max-width: 900px) {
        .content-wrapper {
          padding: 20px 14px 48px;
        }

        .hero {
          height: 420px;
          background-attachment: scroll;
          padding: 40px 20px;
        }

        .hero h1 {
          font-size: 2.8rem;
          margin-bottom: 16px;
        }

        .hero p {
          font-size: 1.2rem;
          margin-bottom: 20px;
        }

        .hero-buttons {
          gap: 12px;
        }

        .hero .btn, .hero .btn-ghost {
          padding: 12px 24px;
          font-size: 0.95em;
        }

        .topup-wrap {
          grid-template-columns: 1fr;
          gap: 16px;
        }

        .card {
          padding: 22px;
        }

        .card h2 {
          font-size: 1.5rem;
        }

        .preview {
          height: 180px;
        }

        .form-actions {
          flex-direction: column-reverse;
        }

        .form-actions .btn {
          width: 100%;
          justify-content: center;
        }
      }

      @media (max-width: 768px) {
        .content-wrapper {
          padding: 18px 12px 36px;
        }

        .hero {
          height: 360px;
          padding: 30px 15px;
          background-attachment: scroll;
        }

        .hero h1 {
          font-size: 2rem;
          margin-bottom: 12px;
          line-height: 1.1;
        }

        .hero p {
          font-size: 1rem;
          margin-bottom: 16px;
        }

        .hero .btn, .hero .btn-ghost {
          padding: 10px 18px;
          font-size: 0.9em;
          gap: 6px;
        }

        .hero-buttons {
          flex-direction: column;
          gap: 10px;
        }

        .card {
          padding: 18px;
        }

        .card h2 {
          font-size: 1.3rem;
          margin-bottom: 14px;
        }

        .card h3 {
          font-size: 1.1rem;
          margin-bottom: 12px;
        }

        .btn {
          padding: 10px 16px;
          font-size: 0.9em;
        }

        .preview {
          height: 160px;
        }

        .gcash-card {
          padding: 14px;
        }

        .gcash-card p {
          font-size: 0.9rem;
          margin-bottom: 10px;
        }

        .payment-steps h4 {
          font-size: 0.95rem;
          margin-bottom: 10px;
        }

        .payment-steps li {
          margin-bottom: 8px;
          font-size: 0.88rem;
          padding-left: 20px;
        }

        .info-box {
          padding: 12px;
          font-size: 0.88rem;
        }

        label {
          font-size: 0.95rem;
        }

        input[type="number"],
        input[type="file"] {
          padding: 10px 12px;
          font-size: 0.9rem;
        }

        .helper {
          font-size: 0.88rem;
        }
      }

      @media (max-width: 480px) {
        .content-wrapper {
          padding: 14px 10px 28px;
        }

        .hero {
          height: 320px;
          padding: 25px 12px;
          background-attachment: scroll;
        }

        .hero h1 {
          font-size: 1.5rem;
          margin-bottom: 10px;
        }

        .hero p {
          font-size: 0.95rem;
          margin-bottom: 14px;
        }

        .hero .btn, .hero .btn-ghost {
          padding: 9px 16px;
          font-size: 0.85em;
          gap: 6px;
        }

        .card {
          padding: 14px;
        }

        .card h2 {
          font-size: 1.1rem;
        }

        .card h3 {
          font-size: 1rem;
        }

        .btn {
          padding: 9px 12px;
          font-size: 0.85em;
        }

        .preview {
          height: 140px;
        }

        .gcash-card {
          padding: 12px;
        }

        .gcash-card p {
          font-size: 0.85rem;
          margin-bottom: 8px;
        }

        .payment-steps h4 {
          font-size: 0.9rem;
          margin-bottom: 8px;
        }

        .payment-steps li {
          margin-bottom: 6px;
          font-size: 0.8rem;
          padding-left: 18px;
        }

        .info-box {
          padding: 10px;
          font-size: 0.8rem;
        }

        .messages {
          margin-bottom: 16px;
        }

        .msg-success,
        .msg-error {
          padding: 12px 14px;
          font-size: 0.85em;
        }
      }

      @media (max-width: 360px) {
        .hero h1 {
          font-size: 1.3rem;
        }

        .hero-buttons {
          flex-direction: column;
        }

        .card {
          padding: 12px;
        }

        .card h2 {
          font-size: 1rem;
        }
      }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<!-- HERO SECTION WITH BACKGROUND IMAGE -->
<section class="hero" aria-labelledby="topupTitle" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.35) 100%), url('images/aboback.png') center/cover no-repeat;">
  <div class="hero-content">
    <h1 id="topupTitle">Request a Wallet Top-Up</h1>
    <p>Send payment via GCash, upload your transaction screenshot, and submit your top-up request. Our admin team will review and approve it promptly.</p>
    <div class="hero-buttons">
      <a href="profile.php" class="btn">
        <i class="fa-solid fa-wallet"></i> View Wallet
      </a>
      <a href="index.php" class="btn-ghost">
        <i class="fa-solid fa-arrow-left"></i> Back Home
      </a>
    </div>
  </div>
</section>

<div class="content-wrapper">

  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a> &nbsp;/&nbsp; <a href="profile.php">Profile</a> &nbsp;/&nbsp; <strong>Top-Up</strong>
  </nav>

  <?php if (!empty($message)): ?>
    <div class="messages" role="status" aria-live="polite" aria-atomic="true">
      <?php foreach ($message as $msg): ?>
        <?php if (str_contains($msg, '✅')): ?>
          <div class="msg-success"><?php echo htmlspecialchars($msg); ?></div>
        <?php else: ?>
          <div class="msg-error"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <div class="topup-wrap" role="region" aria-label="Top-up content">
    <!-- Left: Form -->
    <section class="card" aria-labelledby="formTitle">
      <h2 id="formTitle">Submit Top-Up Request</h2>
      <form action="" method="post" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
        <div>
          <label for="amount">
            <i class="fa-solid fa-peso-sign"></i> Top-up Amount (₱)
          </label>
          <input 
            id="amount" 
            type="number" 
            name="amount" 
            min="1" 
            step="0.01" 
            required 
            placeholder="Enter amount (e.g., 150.00)"
            aria-label="Top-up amount in Philippine Pesos"
          >
          <div class="helper">
            <i class="fa-solid fa-info-circle"></i>
            <span>Enter the exact amount you sent. Use two decimal places for cents (e.g., 150.00).</span>
          </div>
        </div>

        <div>
          <label for="screenshot">
            <i class="fa-solid fa-image"></i> Upload Transaction Screenshot
          </label>
          <input 
            id="screenshot" 
            type="file" 
            name="screenshot" 
            accept="image/jpeg,image/png,image/webp"
            required
            aria-label="Upload screenshot of GCash transaction"
          >
          <div class="helper">
            <i class="fa-solid fa-info-circle"></i>
            <span>JPG/PNG preferred. Make sure the amount and sender name are clearly visible.</span>
          </div>
        </div>

        <div>
          <div class="preview" id="previewBox" aria-hidden="true">
            <div class="preview-empty">
              <i class="fa-solid fa-image"></i>
              <span>No screenshot selected</span>
            </div>
          </div>
        </div>

        <div class="info-box">
          <i class="fa-solid fa-lightbulb"></i>
          <span><strong>Tip:</strong> Take a clear screenshot showing the GCash reference number, amount, and recipient name for faster approval.</span>
        </div>

        <div class="form-actions">
          <a href="profile.php" class="btn btn-ghost" style="text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Back
          </a>
          <button type="submit" class="btn btn-primary">
            <i class="fa-solid fa-check"></i> Submit Request
          </button>
        </div>
      </form>
    </section>

    <!-- Right: Payment Info -->
    <aside class="card" aria-labelledby="paymentTitle">
      <h3 id="paymentTitle">
        <i class="fa-brands fa-mobile"></i> GCash Payment Details
      </h3>
      <div class="gcash-card">
        <p>
          <strong>GCash Number:</strong><br>
          <span class="gcash-number">09086657580</span>
        </p>
        <p>
          <strong>Account Name:</strong><br>
          <span class="gcash-name">Vic******* D</span>
        </p>
        <p style="margin-bottom: 0; font-size: 0.88rem; color: var(--gray-brown);">
          <i class="fa-solid fa-shield-halved"></i> Your payment is secure and protected.
        </p>

        <div class="payment-steps">
          <h4>
            <i class="fa-solid fa-list-ol"></i> How it works
          </h4>
          <ul>
            <li>Open your GCash app and send money</li>
            <li>Enter the GCash number and exact amount</li>
            <li>Take a screenshot of the receipt/confirmation</li>
            <li>Upload the screenshot below and submit</li>
            <li>Admin reviews and approves your request</li>
            <li>Wallet balance updates immediately</li>
          </ul>
        </div>

        <div class="info-box" style="margin-top: 16px;">
          <i class="fa-solid fa-clock"></i>
          <span><strong>Processing Time:</strong> Usually within 1-2 hours during business hours. We'll notify you once approved!</span>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php include 'chatbot.php'; ?>
<?php include 'footer.php'; ?>

<script>
  (function(){
    // Image preview with better feedback
    const input = document.getElementById('screenshot');
    const previewBox = document.getElementById('previewBox');
    
    if (input && previewBox) {
      input.addEventListener('change', function(){
        const file = this.files && this.files[0];
        
        if (!file) {
          previewBox.innerHTML = '<div class="preview-empty"><i class="fa-solid fa-image"></i><span>No screenshot selected</span></div>';
          return;
        }
        
        if (!file.type.startsWith('image/')) {
          previewBox.innerHTML = '<div class="preview-empty" style="color: var(--primary-red);"><i class="fa-solid fa-circle-xmark"></i><span>Selected file is not an image</span></div>';
          return;
        }

        if (file.size > 5 * 1024 * 1024) {
          previewBox.innerHTML = '<div class="preview-empty" style="color: var(--primary-red);"><i class="fa-solid fa-exclamation-triangle"></i><span>File size too large (max 5MB)</span></div>';
          return;
        }
        
        const reader = new FileReader();
        reader.onload = function(e){
          previewBox.innerHTML = '<img src="' + e.target.result + '" alt="Screenshot preview">';
        };
        reader.readAsDataURL(file);
      });
    }

    // Form validation
    const form = document.querySelector('form');
    if (form) {
      form.addEventListener('submit', function(e){
        const amount = parseFloat(document.getElementById('amount').value || '0');
        const file = input.files && input.files[0];
        
        if (!(amount > 0)) {
          e.preventDefault();
          alert('⚠️ Please enter a valid amount greater than 0.');
          document.getElementById('amount').focus();
          return;
        }

        if (!file) {
          e.preventDefault();
          alert('⚠️ Please upload a screenshot of your GCash transaction before submitting.');
          document.getElementById('screenshot').focus();
          return;
        }

        if (!file.type.startsWith('image/')) {
          e.preventDefault();
          alert('⚠️ Selected file must be an image (JPG, PNG, etc.).');
          return;
        }
      });
    }
  })();
</script>
<script src="Js/script1.js"></script>
</body>
</html>