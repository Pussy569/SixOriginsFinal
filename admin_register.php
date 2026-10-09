<?php
/**
 * PROJECT: Six Origins Cafe - Automated Web Application
 * MODULE: Admin Registration (separate from customer registration)
 * VERSION: 5.1.2026
 * AUTHOR: BSIS Student Group - One Cainta College
 *
 * DESCRIPTION:
 * Registration for ADMIN accounts only. A verification document (ID / certification)
 * is required and the account is saved with status = 'pending'. The admin can only
 * log in (login.php) after the head admin approves the account.
 * This page is intentionally not linked anywhere - staff are given the URL directly.
 */

session_start();
include 'config.php';

if(isset($_POST['submit'])){
   $name  = trim($_POST['name'] ?? '');
   $email = trim($_POST['email'] ?? '');
   $pass  = $_POST['password'] ?? '';
   $cpass = $_POST['cpassword'] ?? '';
   $captcha_verified = $_POST['captcha_verified'] ?? '';

   // This page can ONLY create admin accounts - role is never taken from the form
   $type   = 'admin';
   $status = 'pending'; // must be approved by the head admin

   // Verify Custom CAPTCHA
   if($captcha_verified !== 'true'){
      $_SESSION['message'] = 'Please complete the CAPTCHA verification!';
      header('location:register_admin.php');
      exit();
   }

   if($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 8){
      $_SESSION['message'] = 'Please fill in all fields correctly (password must be 8+ characters).';
      header('location:register_admin.php');
      exit();
   }

   // Email already registered?
   $chk = mysqli_prepare($conn, "SELECT id FROM users WHERE email = ? LIMIT 1");
   mysqli_stmt_bind_param($chk, "s", $email);
   mysqli_stmt_execute($chk);
   mysqli_stmt_store_result($chk);
   $exists = mysqli_stmt_num_rows($chk) > 0;
   mysqli_stmt_close($chk);

   if($exists){
      $_SESSION['message'] = 'Email already registered!';
      header('location:register_admin.php');
      exit();
   }

   if($pass !== $cpass){
      $_SESSION['message'] = 'Passwords do not match!';
      header('location:register_admin.php');
      exit();
   }

   // Verification document is REQUIRED for admin accounts
   if(!isset($_FILES['verification_image']) || $_FILES['verification_image']['error'] !== UPLOAD_ERR_OK){
      $_SESSION['message'] = 'Verification document required for Admin accounts!';
      header('location:register_admin.php');
      exit();
   }

   $file = $_FILES['verification_image'];
   $ext  = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
   $allowed_ext  = ['jpg', 'jpeg', 'png'];
   $allowed_mime = ['image/jpeg', 'image/png'];
   $mime = function_exists('mime_content_type') ? mime_content_type($file['tmp_name']) : '';

   if(!in_array($ext, $allowed_ext, true) || ($mime !== '' && !in_array($mime, $allowed_mime, true)) || $file['size'] > 5 * 1024 * 1024){
      $_SESSION['message'] = 'Verification upload failed: use a JPG or PNG image up to 5MB.';
      header('location:register_admin.php');
      exit();
   }

   // Ensure the uploads folder exists, including when another request creates it concurrently.
   $upload_dir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
   if(!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)){
      error_log('Could not create admin registration upload directory.');
      $_SESSION['message'] = 'Verification image could not be saved. Please try again.';
      header('location:register_admin.php');
      exit();
   }

   $verify_img = uniqid('verify_') . '.' . $ext;
   $verify_path = $upload_dir . DIRECTORY_SEPARATOR . $verify_img;
   if(!move_uploaded_file($file['tmp_name'], $verify_path)){
      $_SESSION['message'] = 'Failed to upload verification image!';
      header('location:register_admin.php');
      exit();
   }

   $hash = password_hash($pass, PASSWORD_DEFAULT);

   $ins = mysqli_prepare($conn, "INSERT INTO users(name, email, password, user_type, status, verification_image) VALUES(?, ?, ?, ?, ?, ?)");
   mysqli_stmt_bind_param($ins, "ssssss", $name, $email, $hash, $type, $status, $verify_img);

   if(mysqli_stmt_execute($ins)){
      mysqli_stmt_close($ins);
      $_SESSION['message'] = "Admin registration submitted! Wait for the head admin's approval before signing in.";
      header('location:login.php');
      exit();
   } else {
      $err = mysqli_stmt_error($ins);
      mysqli_stmt_close($ins);
      @unlink($verify_path);
      $_SESSION['message'] = 'Registration failed: ' . $err;
      header('location:register_admin.php');
      exit();
   }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8" />
   <meta name="viewport" content="width=device-width, initial-scale=1.0" />
   <title>Admin Register | Six Origins Cafe</title>
   <meta name="robots" content="noindex, nofollow" />

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

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
        --blue: #4285F4;
        --light-gray: #f3f3f3;

        /* Strength Meter Colors */
        --str-weak: #ff4d4d;
        --str-fair: #ffa500;
        --str-good: #2db92d;
        --str-strong: #1a73e8;
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
         background: white;
         overflow-x: hidden;
         margin: 0;
         padding: 0;
      }

      body {
         display: flex;
         align-items: center;
         justify-content: center;
         color: var(--dark-brown);
      }

      .layout {
         width: 100%;
         height: 100%;
         display: grid;
         grid-template-columns: 1fr 1fr;
         gap: 0;
      }

      /* LEFT SIDE - FORM PANEL */
      .form-panel {
         padding: 40px;
         display: flex;
         flex-direction: column;
         justify-content: flex-start;
         align-items: center;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         min-height: 100vh;
         position: relative;
         overflow-y: auto;
      }

      .form-panel::before {
         content: '';
         position: absolute;
         top: -100px;
         left: -100px;
         width: 300px;
         height: 300px;
         background: radial-gradient(circle, rgba(198, 69, 62, 0.1) 0%, transparent 70%);
         border-radius: 50%;
         pointer-events: none;
      }

      .form-wrapper {
         width: 100%;
         max-width: 500px;
         position: relative;
         z-index: 1;
         animation: slideInLeft 0.6s ease-out;
         padding: 20px 0;
      }

      @keyframes slideInLeft {
         from { opacity: 0; transform: translateX(-40px); }
         to   { opacity: 1; transform: translateX(0); }
      }

      /* LOGO SECTION */
      .logo-section {
         display: flex;
         align-items: center;
         gap: 16px;
         margin-bottom: 48px;
      }

      .logo-img {
         width: 80px;
         height: 80px;
         border-radius: 16px;
         object-fit: contain;
         background: white;
         padding: 8px;
         box-shadow: 0 6px 16px rgba(94, 31, 19, 0.12);
         transition: var(--transition);
      }

      .logo-img:hover {
         transform: scale(1.05);
         box-shadow: var(--shadow-hover);
      }

      .logo-text h3 {
         font-size: 2rem;
         font-weight: 900;
         color: var(--dark-brown);
         margin-bottom: 4px;
      }

      .logo-text p {
         font-size: 0.95rem;
         color: var(--primary-red);
         font-weight: 700;
         letter-spacing: 0.5px;
      }

      /* FORM HEADER */
      .form-header { margin-bottom: 32px; }

      .form-header .title {
         font-size: 2.4rem;
         font-weight: 900;
         color: var(--dark-brown);
         margin-bottom: 12px;
         letter-spacing: -0.5px;
      }

      .form-header .subtitle {
         color: var(--gray-brown);
         font-size: 1rem;
         line-height: 1.6;
         font-weight: 500;
      }

      form { margin-top: 0; }

      .field {
         margin-bottom: 20px;
         animation: fadeIn 0.5s ease-out;
      }

      @keyframes fadeIn {
         from { opacity: 0; transform: translateY(10px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      label {
         display: block;
         font-size: 0.95rem;
         color: var(--dark-brown);
         margin-bottom: 10px;
         font-weight: 700;
         letter-spacing: 0.3px;
      }

      .control {
         display: flex;
         align-items: center;
         gap: 14px;
         background: var(--white);
         border: 1.5px solid #F0E6D8;
         padding: 14px 18px;
         border-radius: 12px;
         transition: var(--transition);
         box-shadow: 0 2px 8px rgba(94, 31, 19, 0.04);
      }

      .control:focus-within {
         border-color: var(--primary-red);
         background: var(--white);
         box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12), 0 4px 12px rgba(94, 31, 19, 0.08);
      }

      .control input[type="text"],
      .control input[type="email"],
      .control input[type="password"] {
         border: 0;
         outline: none;
         font-size: 1rem;
         width: 100%;
         background: transparent;
         color: var(--dark-brown);
         font-weight: 500;
         letter-spacing: 0.3px;
      }

      .control input::placeholder {
         color: var(--gray-brown);
         font-weight: 500;
      }

      .control input:-webkit-autofill {
         -webkit-box-shadow: 0 0 0 1000px white inset !important;
         -webkit-text-fill-color: var(--dark-brown) !important;
      }

      .control .icon {
         color: var(--primary-red);
         font-size: 1.2rem;
         width: 24px;
         text-align: center;
         display: flex;
         align-items: center;
         justify-content: center;
         flex-shrink: 0;
      }

      /* PASSWORD STRENGTH TRACKER */
      .pw-strength-container {
         margin-top: 10px;
         display: none; /* Only show when typing */
      }
      .pw-meter {
         height: 6px;
         width: 100%;
         background: #e0e0e0;
         border-radius: 10px;
         overflow: hidden;
         margin-bottom: 8px;
      }
      .pw-meter-bar {
         height: 100%;
         width: 0%;
         transition: width 0.4s ease, background 0.4s ease;
      }
      .pw-text {
         font-size: 0.8rem;
         font-weight: 700;
         display: block;
         margin-bottom: 5px;
      }
      .pw-requirements-grid {
         display: grid;
         grid-template-columns: 1fr 1fr;
         gap: 8px;
         margin-top: 10px;
      }
      .pw-req-item {
         font-size: 0.75rem;
         color: #999;
         display: flex;
         align-items: center;
         gap: 6px;
         transition: color 0.3s ease;
      }
      .pw-req-item.met {
         color: #2D5A3D;
         font-weight: 700;
      }

      /* MESSAGE ALERTS */
      .message {
         padding: 16px 18px;
         border-radius: var(--radius);
         margin-bottom: 24px;
         display: flex;
         gap: 12px;
         align-items: flex-start;
         box-shadow: 0 6px 16px rgba(94, 31, 19, 0.1);
         animation: slideInDown 0.4s ease-out;
         font-weight: 600;
         font-size: 0.95rem;
         border: 1px solid transparent;
      }

      @keyframes slideInDown {
         from { opacity: 0; transform: translateY(-15px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      .message i {
         font-size: 1.1rem;
         flex-shrink: 0;
         margin-top: 2px;
      }

      .message.error {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.12) 0%, rgba(198, 69, 62, 0.06) 100%);
         color: var(--primary-red);
         border-color: rgba(198, 69, 62, 0.2);
      }

      .message.success {
         background: linear-gradient(135deg, rgba(45, 90, 61, 0.12) 0%, rgba(45, 90, 61, 0.06) 100%);
         color: #2D5A3D;
         border-color: rgba(45, 90, 61, 0.2);
      }

      /* PASSWORD REQUIREMENTS BOX */
      .password-requirements {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.08) 0%, rgba(198, 69, 62, 0.03) 100%);
         border: 1.5px solid rgba(198, 69, 62, 0.15);
         border-radius: 12px;
         padding: 14px 16px;
         margin-top: 16px;
         font-size: 0.88rem;
         color: var(--gray-brown);
      }

      .password-requirements h4 {
         color: var(--dark-brown);
         font-size: 0.9rem;
         margin-bottom: 8px;
         font-weight: 700;
         display: flex;
         align-items: center;
         gap: 6px;
      }

      .password-requirements ul {
         list-style: none;
         padding: 0;
         margin: 0;
      }

      .password-requirements li {
         display: flex;
         align-items: center;
         gap: 8px;
         margin-bottom: 4px;
         padding: 0;
      }

      .password-requirements li:last-child { margin-bottom: 0; }

      .password-requirements li i {
         font-size: 0.8rem;
         color: var(--primary-red);
      }

      /* CAPTCHA CHECKBOX - GOOGLE RECAPTCHA STYLE */
      .captcha-checkbox-container {
         background: #f9f9f9;
         border: 1px solid #d3d3d3;
         border-radius: 2px;
         padding: 10px 20px;
         margin-top: 20px;
         margin-bottom: 20px;
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 20px;
         box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12), 0 1px 2px rgba(0, 0, 0, 0.24);
         transition: all 0.3s ease;
         position: relative;
         z-index: 1;
         cursor: pointer;
      }

      .captcha-checkbox-container:hover:not(.disabled) {
         box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15), 0 2px 4px rgba(0, 0, 0, 0.12);
      }

      .captcha-checkbox-container.disabled {
         opacity: 0.6;
         cursor: not-allowed;
         background: #f0f0f0;
      }

      .checkbox-wrapper { flex-shrink: 0; }

      .checkbox-input {
         width: 24px;
         height: 24px;
         cursor: pointer;
         accent-color: #4285F4;
         border: 2px solid #c6c6c6;
         border-radius: 2px;
         outline: none;
      }

      .checkbox-input:hover:not(:disabled) {
         border-color: #4285F4;
         box-shadow: 0 0 0 1px rgba(66, 133, 244, 0.1);
      }

      .captcha-checkbox-container.disabled .checkbox-input {
         cursor: not-allowed;
         opacity: 0.5;
      }

      .checkbox-label-text {
         display: flex;
         flex-direction: column;
         gap: 2px;
         cursor: pointer;
         user-select: none;
         flex-grow: 1;
      }

      .checkbox-label-text .main-text {
         font-weight: 500;
         color: #555555;
         font-size: 0.95rem;
         letter-spacing: 0.3px;
      }

      .checkbox-label-text .sub-text {
         font-size: 0.7rem;
         color: #999999;
         font-weight: 400;
         display: none;
      }

      /* CAPTCHA LOGO SECTION */
      .captcha-logo {
         flex-shrink: 0;
         display: flex;
         flex-direction: column;
         align-items: center;
         justify-content: center;
         gap: 6px;
         padding: 8px 12px;
         background: white;
         border: 1px solid #e8e8e8;
         border-radius: 2px;
         min-width: 90px;
      }

      .captcha-logo img {
         width: 50px;
         height: 50px;
         object-fit: contain;
         margin-bottom: 2px;
      }

      .captcha-logo .logo-text {
         font-weight: 700;
         font-size: 0.9rem;
         color: #4285F4;
         letter-spacing: 0.2px;
         text-align: center;
      }

      .captcha-logo .privacy-text {
         font-size: 0.65rem;
         text-align: center;
         white-space: nowrap;
      }

      .captcha-logo a {
         color: #70B7F7;
         text-decoration: none;
         transition: color 0.2s ease;
         font-weight: 500;
      }

      .captcha-logo a:hover {
         color: #4285F4;
         text-decoration: underline;
      }

      /* CAPTCHA MODAL OVERLAY - LEFT POSITION */
      .captcha-modal-overlay {
         position: fixed;
         inset: 0;
         background: rgba(0, 0, 0, 0.6);
         z-index: 9998;
         display: none;
         align-items: center;
         justify-content: flex-start;
         padding: 20px;
         animation: fadeIn 0.3s ease-out;
         backdrop-filter: blur(4px);
      }

      .captcha-modal-overlay.active { display: flex; }

      .captcha-modal {
         background: white;
         border-radius: 8px;
         padding: 0;
         width: 100%;
         max-width: 520px;
         max-height: 90vh;
         overflow: hidden;
         display: flex;
         flex-direction: column;
         box-shadow: 0 8px 40px rgba(0, 0, 0, 0.3);
         z-index: 9999;
         animation: slideInLeft 0.3s ease-out;
         margin-left: 40px;
      }

      .captcha-modal-header {
         background: linear-gradient(135deg, #3367d6 0%, #4285F4 100%);
         color: white;
         padding: 24px;
         flex-shrink: 0;
         border-radius: 8px 8px 0 0;
      }

      .captcha-modal-header h3 {
         font-size: 1.3rem;
         font-weight: 700;
         margin: 0 0 8px 0;
         letter-spacing: 0.3px;
      }

      .captcha-modal-header p {
         font-size: 0.95rem;
         font-weight: 500;
         margin: 0;
         opacity: 0.95;
      }

      .captcha-modal-body {
         padding: 24px;
         overflow-y: auto;
         flex: 1;
         background: white;
      }

      .captcha-challenge {
         color: #3367d6;
         font-weight: 700;
         font-size: 1rem;
         text-align: center;
         margin-bottom: 20px;
         text-transform: uppercase;
         letter-spacing: 0.8px;
         line-height: 1.4;
      }

      .captcha-grid {
         display: grid;
         grid-template-columns: repeat(3, 1fr);
         gap: 12px;
         margin-bottom: 20px;
      }

      .captcha-tile {
         position: relative;
         aspect-ratio: 1 / 1;
         border: 2px solid #ddd;
         border-radius: 4px;
         overflow: hidden;
         cursor: pointer;
         transition: all 0.2s ease;
         background: #f5f5f5;
         user-select: none;
         box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
         display: flex;
         align-items: center;
         justify-content: center;
         min-height: 120px;
      }

      .captcha-tile:hover:not(.selected) {
         border-color: #999;
         box-shadow: 0 2px 6px rgba(0, 0, 0, 0.12);
      }

      .captcha-tile img {
         width: 100%;
         height: 100%;
         object-fit: cover;
         display: block;
      }

      .captcha-tile-placeholder {
         font-size: 48px;
         color: #bbb;
      }

      .captcha-tile.selected {
         border-color: #4285F4;
         background: rgba(66, 133, 244, 0.08);
         box-shadow: 0 0 0 3px rgba(66, 133, 244, 0.2);
      }

      .captcha-tile.selected::after {
         content: '✓';
         position: absolute;
         top: 50%;
         left: 50%;
         transform: translate(-50%, -50%);
         background: #4285F4;
         color: white;
         width: 36px;
         height: 36px;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         font-weight: 900;
         font-size: 1.2rem;
         box-shadow: 0 2px 8px rgba(66, 133, 244, 0.4);
      }

      .captcha-info {
         font-size: 0.85rem;
         color: #666;
         text-align: center;
         margin-bottom: 16px;
         padding: 12px;
         line-height: 1.4;
      }

      .captcha-actions {
         display: flex;
         gap: 12px;
         margin-bottom: 12px;
      }

      .captcha-btn {
         flex: 1;
         padding: 12px 16px;
         border: 1px solid #ddd;
         background: white;
         color: #555;
         border-radius: 4px;
         cursor: pointer;
         font-weight: 600;
         font-size: 0.9rem;
         transition: all 0.2s ease;
         display: flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
      }

      .captcha-btn:hover:not(:disabled) {
         border-color: #999;
         background: #f9f9f9;
         color: #333;
      }

      .captcha-btn:active:not(:disabled) { transform: scale(0.98); }

      .captcha-btn.verify {
         background: #4285F4;
         color: white;
         border: 0;
         flex: 1.1;
         font-size: 0.95rem;
         font-weight: 700;
      }

      .captcha-btn.verify:hover:not(:disabled) {
         background: #3367d6;
         box-shadow: 0 2px 8px rgba(66, 133, 244, 0.3);
         border-color: #3367d6;
      }

      .captcha-btn:disabled {
         opacity: 0.6;
         cursor: not-allowed;
      }

      .captcha-status {
         text-align: center;
         font-size: 0.85rem;
         font-weight: 600;
         padding: 12px;
         border-radius: 4px;
         margin-bottom: 0;
         display: none;
         line-height: 1.4;
      }

      .captcha-status.success {
         background: #e6f4e6;
         color: #0d652d;
         display: block;
      }

      .captcha-status.error {
         background: #fce8e6;
         color: #d33b27;
         display: block;
      }

      /* SIGN UP BUTTON */
      .btn {
         width: 100%;
         padding: 14px 24px;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: white;
         border: 0;
         border-radius: 12px;
         font-weight: 800;
         font-size: 1.05rem;
         cursor: pointer;
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 10px;
         transition: var(--transition);
         box-shadow: 0 6px 16px rgba(198, 69, 62, 0.25);
         position: relative;
         overflow: hidden;
         margin-top: 24px;
         margin-bottom: 24px;
         letter-spacing: 0.5px;
      }

      .btn::before {
         content: '';
         position: absolute;
         top: 0;
         left: -100%;
         width: 100%;
         height: 100%;
         background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.25), transparent);
         transition: left 0.5s ease;
      }

      .btn:hover:not(:disabled) {
         transform: translateY(-2px);
         box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
      }

      .btn:hover:not(:disabled)::before { left: 100%; }

      .btn:active:not(:disabled) { transform: translateY(-1px); }

      .btn:disabled {
         opacity: 0.7;
         cursor: not-allowed;
         transform: none;
      }

      /* LOGIN LINK */
      .login-link {
         text-align: center;
         color: var(--gray-brown);
         font-size: 0.95rem;
         font-weight: 500;
         margin-bottom: 20px;
      }

      .login-link a {
         color: var(--primary-red);
         font-weight: 700;
         text-decoration: none;
         transition: var(--transition);
      }

      .login-link a:hover {
         color: var(--dark-brown);
         text-decoration: underline;
      }

      /* RIGHT SIDE - IMAGE PANEL */
      .image-panel {
         position: relative;
         overflow: hidden;
         display: flex;
         align-items: center;
         justify-content: center;
         min-height: 100vh;
      }

      .image-panel::before {
         content: '';
         position: absolute;
         inset: 0;
         background: radial-gradient(circle at center, rgba(0, 0, 0, 0.3) 0%, rgba(0, 0, 0, 0.5) 100%);
         z-index: 2;
      }

      .image-panel img {
         width: 100%;
         height: 100%;
         object-fit: cover;
         object-position: center;
      }

      .image-overlay {
         position: absolute;
         inset: 0;
         display: flex;
         flex-direction: column;
         align-items: center;
         justify-content: center;
         text-align: center;
         z-index: 3;
         color: white;
         gap: 24px;
         padding: 40px;
         animation: fadeInUp 0.8s ease-out;
      }

      @keyframes fadeInUp {
         from { opacity: 0; transform: translateY(40px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      .image-overlay h2 {
         font-size: 3.2rem;
         font-weight: 900;
         letter-spacing: -1.5px;
         text-shadow: 0 8px 24px rgba(0, 0, 0, 0.4);
         display: flex;
         align-items: center;
         gap: 16px;
         justify-content: center;
         flex-wrap: wrap;
         line-height: 1.1;
      }

      .image-overlay h2 img {
         width: 80px;
         height: 80px;
         object-fit: contain;
         filter: drop-shadow(0 4px 12px rgba(0, 0, 0, 0.3));
      }

      .image-overlay .benefits {
         display: flex;
         flex-direction: column;
         gap: 16px;
         align-items: center;
      }

      .benefit-item {
         display: flex;
         align-items: center;
         gap: 12px;
         font-size: 1.1rem;
         opacity: 0.95;
         text-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
         font-weight: 500;
      }

      .benefit-item i {
         font-size: 1.5rem;
         flex-shrink: 0;
      }

      /* GUIDE BUTTON */
      .guide-btn {
         position: absolute;
         bottom: 40px;
         right: 40px;
         z-index: 10;
         width: 64px;
         height: 64px;
         border-radius: 50%;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         border: 3px solid white;
         color: white;
         font-size: 1.6rem;
         cursor: pointer;
         display: flex;
         align-items: center;
         justify-content: center;
         box-shadow: 0 8px 24px rgba(94, 31, 19, 0.3);
         transition: var(--transition);
         animation: slideInUp 0.6s ease-out 0.5s both;
      }

      @keyframes slideInUp {
         from { opacity: 0; transform: translateY(40px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      .guide-btn:hover {
         transform: scale(1.1);
         box-shadow: 0 12px 32px rgba(94, 31, 19, 0.4);
      }

      .guide-btn:active { transform: scale(0.95); }

      .guide-overlay {
         position: fixed;
         inset: 0;
         background: rgba(0, 0, 0, 0.6);
         z-index: 999;
         display: none;
         align-items: center;
         justify-content: center;
         backdrop-filter: blur(4px);
         animation: fadeIn 0.3s ease-out;
         padding: 20px;
         overflow-y: auto;
      }

      .guide-overlay.active { display: flex; }

      .guide-container {
         background: white;
         border-radius: 24px;
         padding: 48px 40px;
         max-width: 540px;
         width: 100%;
         max-height: 85vh;
         overflow-y: auto;
         box-shadow: 0 25px 60px rgba(0, 0, 0, 0.25);
         animation: slideInUp 0.4s ease-out;
         position: relative;
      }

      .guide-close {
         position: absolute;
         top: 24px;
         right: 24px;
         width: 44px;
         height: 44px;
         border-radius: 50%;
         background: #F5EFE7;
         border: none;
         color: var(--dark-brown);
         font-size: 1.4rem;
         cursor: pointer;
         display: flex;
         align-items: center;
         justify-content: center;
         transition: var(--transition);
      }

      .guide-close:hover {
         background: #E8DCD4;
         transform: rotate(90deg);
      }

      .guide-container h3 {
         font-size: 2rem;
         color: var(--dark-brown);
         margin-bottom: 32px;
         font-weight: 900;
      }

      .guide-steps {
         display: flex;
         flex-direction: column;
         gap: 20px;
      }

      .guide-step {
         display: flex;
         gap: 16px;
         padding-bottom: 20px;
         border-bottom: 1px solid #F0E6D8;
      }

      .guide-step:last-child {
         border-bottom: none;
         padding-bottom: 0;
      }

      .step-number {
         width: 48px;
         height: 48px;
         min-width: 48px;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: white;
         border-radius: 50%;
         display: flex;
         align-items: center;
         justify-content: center;
         font-weight: 800;
         font-size: 1.2rem;
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.25);
      }

      .step-content h4 {
         font-size: 1.1rem;
         color: var(--dark-brown);
         margin-bottom: 8px;
         font-weight: 800;
      }

      .step-content p {
         font-size: 0.95rem;
         color: var(--gray-brown);
         line-height: 1.6;
      }


      /* ===== ADMIN-ONLY ADDITIONS ===== */
      .role-badge {
         display: inline-flex;
         align-items: center;
         gap: 8px;
         padding: 6px 14px;
         margin-bottom: 14px;
         border-radius: 999px;
         background: linear-gradient(135deg, var(--dark-brown) 0%, #7A2A1B 100%);
         color: #fff;
         font-size: 0.78rem;
         font-weight: 800;
         letter-spacing: 0.8px;
         text-transform: uppercase;
         box-shadow: 0 4px 12px rgba(94, 31, 19, 0.25);
      }

      .approval-notice {
         display: flex;
         gap: 12px;
         align-items: flex-start;
         padding: 14px 16px;
         margin-bottom: 24px;
         border-radius: 12px;
         background: linear-gradient(135deg, rgba(255, 165, 0, 0.14) 0%, rgba(255, 165, 0, 0.06) 100%);
         border: 1.5px solid rgba(255, 165, 0, 0.35);
         color: #7A4B00;
         font-size: 0.88rem;
         font-weight: 600;
         line-height: 1.5;
      }

      .approval-notice i {
         font-size: 1.1rem;
         margin-top: 2px;
         flex-shrink: 0;
      }

      .note {
         font-size: 0.85rem;
         color: var(--gray-brown);
         margin-top: 8px;
         display: flex;
         gap: 6px;
         align-items: flex-start;
         font-weight: 500;
      }

      .note i {
         font-size: 0.9rem;
         margin-top: 2px;
         flex-shrink: 0;
         color: var(--primary-red);
      }

      /* VERIFICATION SECTION */
      .verify-section {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.08) 0%, rgba(198, 69, 62, 0.03) 100%);
         border: 1.5px solid var(--primary-red);
         border-radius: 12px;
         padding: 18px;
         transition: all 0.3s ease;
      }

      .file-input-wrapper { position: relative; }

      .file-input-label {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
         padding: 20px;
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
         border: 2px dashed var(--primary-red);
         border-radius: 12px;
         cursor: pointer;
         transition: all 0.3s ease;
         width: 100%;
         font-weight: 700;
         color: var(--dark-brown);
         font-size: 0.95rem;
         text-align: center;
         margin-bottom: 0;
      }

      .file-input-label:hover {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.15) 0%, rgba(198, 69, 62, 0.08) 100%);
         border-color: var(--dark-brown);
      }

      .file-input-label i {
         font-size: 1.3rem;
         color: var(--primary-red);
      }

      #verification_image { display: none; }

      .file-info {
         margin-top: 8px;
         padding: 8px 12px;
         background: rgba(45, 90, 61, 0.1);
         border-radius: 8px;
         font-size: 0.85rem;
         color: #2D5A3D;
         display: none;
         font-weight: 600;
      }

      .file-info.shown { display: block; }

      @media (max-width: 768px) {
         .verify-section { padding: 14px; }
         .file-input-label { padding: 16px; font-size: 0.9rem; }
      }

      @media (max-width: 480px) {
         .role-badge { font-size: 0.7rem; padding: 5px 12px; }
         .approval-notice { font-size: 0.78rem; padding: 12px; }
         .note { font-size: 0.75rem; margin-top: 6px; }
         .verify-section { padding: 12px; }
         .file-input-label { padding: 14px; font-size: 0.8rem; }
         .file-input-label i { font-size: 1.1rem; }
         .file-info { margin-top: 6px; padding: 6px 10px; font-size: 0.75rem; }
      }

      /* RESPONSIVE DESIGN */
      @media (max-width: 1024px) {
         .layout { grid-template-columns: 1fr; }
         .image-panel { display: none; }
         .form-panel { justify-content: flex-start; padding-top: 40px; }
         .form-wrapper { max-width: 450px; }
         .captcha-modal-overlay { justify-content: center; }
         .captcha-modal { margin-left: 0; }
      }

      @media (max-width: 768px) {
         .form-panel { padding: 30px 24px; min-height: auto; }
         .form-wrapper { max-width: 100%; }
         .form-header .title { font-size: 2rem; }
         .form-header .subtitle { font-size: 0.95rem; }
         .logo-section { margin-bottom: 36px; }
         .logo-img { width: 68px; height: 68px; }
         .logo-text h3 { font-size: 1.5rem; }
         .control { padding: 13px 16px; }
         .field { margin-bottom: 18px; }
         .btn { padding: 13px 20px; font-size: 1rem; margin-top: 20px; margin-bottom: 20px; }
         .captcha-checkbox-container { padding: 12px 14px; margin-top: 18px; margin-bottom: 18px; flex-wrap: wrap; }
         .captcha-grid { gap: 8px; }
         .captcha-modal { max-width: 90vw; margin-left: 0; }
         .captcha-modal-body { padding: 20px; }
         .guide-btn { width: 56px; height: 56px; font-size: 1.3rem; bottom: 30px; right: 30px; }
         .guide-container { padding: 40px 30px; margin: 20px; }
         .password-requirements { margin-top: 14px; padding: 12px 14px; }
      }

      @media (max-width: 480px) {
         .form-panel { padding: 20px 18px; min-height: auto; }
         .logo-section { margin-bottom: 28px; gap: 12px; }
         .logo-img { width: 64px; height: 64px; }
         .logo-text h3 { font-size: 1.3rem; }
         .logo-text p { font-size: 0.8rem; }
         .form-header { margin-bottom: 24px; }
         .form-header .title { font-size: 1.6rem; }
         .form-header .subtitle { font-size: 0.85rem; }
         .field { margin-bottom: 16px; }
         .control { padding: 12px 14px; gap: 10px; }
         .control input { font-size: 16px; }
         label { font-size: 0.9rem; margin-bottom: 8px; }
         .control .icon { font-size: 1rem; width: 20px; }
         .btn { padding: 12px 18px; font-size: 0.95rem; margin-top: 16px; margin-bottom: 16px; }
         .captcha-checkbox-container { padding: 10px 12px; margin-top: 16px; margin-bottom: 16px; flex-direction: column; align-items: flex-start; gap: 10px; }
         .captcha-logo { min-width: auto; width: 100%; padding: 10px; }
         .captcha-logo img { width: 40px; height: 40px; }
         .captcha-grid { gap: 6px; margin-bottom: 16px; }
         .captcha-tile { min-height: 100px; }
         .captcha-tile.selected::after { width: 32px; height: 32px; font-size: 1rem; }
         .captcha-modal { max-width: 95vw; max-height: 95vh; margin-left: 0; }
         .captcha-modal-header { padding: 16px; }
         .captcha-modal-header h3 { font-size: 1.1rem; }
         .captcha-modal-header p { font-size: 0.85rem; }
         .captcha-modal-body { padding: 16px; }
         .captcha-challenge { font-size: 0.85rem; margin-bottom: 14px; }
         .captcha-info { font-size: 0.75rem; padding: 8px; margin-bottom: 12px; }
         .captcha-actions { gap: 8px; margin-bottom: 10px; }
         .captcha-btn { padding: 10px 12px; font-size: 0.8rem; gap: 6px; }
         .captcha-status { font-size: 0.75rem; padding: 10px; }
         .password-requirements { margin-top: 12px; padding: 10px 12px; font-size: 0.8rem; }
         .password-requirements h4 { font-size: 0.85rem; margin-bottom: 6px; }
         .password-requirements li { margin-bottom: 3px; gap: 6px; }
         .login-link { font-size: 0.85rem; margin-top: 12px; }
         .guide-btn { width: 52px; height: 52px; font-size: 1.2rem; bottom: 20px; right: 20px; }
         .guide-container { padding: 30px 20px; border-radius: 20px; }
         .guide-container h3 { font-size: 1.5rem; margin-bottom: 24px; }
         .guide-steps { gap: 16px; }
         .guide-step { gap: 12px; padding-bottom: 16px; }
         .step-number { width: 40px; height: 40px; font-size: 1rem; }
         .step-content h4 { font-size: 0.95rem; }
         .step-content p { font-size: 0.85rem; }
         .image-overlay h2 { font-size: 1.8rem; }
         .image-overlay h2 img { width: 50px; height: 50px; }
         .benefit-item { font-size: 0.95rem; gap: 10px; }
         .benefit-item i { font-size: 1.2rem; }
         .message { padding: 12px 14px; font-size: 0.8rem; margin-bottom: 16px; }
         .message i { font-size: 1rem; }
      }

      @media (max-width: 360px) {
         .form-panel { padding: 16px 14px; }
         .form-header .title { font-size: 1.4rem; }
         .logo-img { width: 56px; height: 56px; }
         .logo-text h3 { font-size: 1.1rem; }
         .control { padding: 11px 12px; }
         .btn { padding: 11px 16px; font-size: 0.9rem; }
         .captcha-checkbox-container { padding: 8px 10px; }
         .guide-btn { width: 48px; height: 48px; }
      }
   </style>
</head>
<body>

   <div class="layout">
      <main class="form-panel" aria-labelledby="registerTitle">
         <div class="form-wrapper">
            <div class="logo-section">
               <img src="images/logos.png" alt="Six Origins Logo" class="logo-img">
               <div class="logo-text">
                  <h3>Six Origins</h3>
                  <p>☕ Coffee Lovers</p>
               </div>
            </div>

            <div class="form-header">
               <div class="role-badge"><i class="fa-solid fa-user-shield"></i> Admin Account</div>
               <div class="title" id="registerTitle">Admin Sign Up</div>
               <div class="subtitle">Submit your details and verification document. The head admin will review and approve your account.</div>
            </div>

            <div class="approval-notice">
               <i class="fa-solid fa-hourglass-half"></i>
               <div>Your account will stay <strong>pending</strong> until the head admin approves it. You can only sign in after approval.</div>
            </div>

            <?php
            if(isset($_SESSION['message'])){
               $raw = $_SESSION['message'];
               $msg = htmlspecialchars($raw);
               $isError = stripos($raw, 'not match') !== false || stripos($raw, 'already') !== false || stripos($raw, 'required') !== false || stripos($raw, 'failed') !== false || stripos($raw, 'please') !== false;
               $cls = $isError ? 'message error' : 'message success';
               $icon = $isError ? 'fa-circle-exclamation' : 'fa-check-circle';
               echo '<div class="'. $cls .'"><i class="fa-solid ' . $icon . '"></i><div>'.$msg.'</div></div>';
               unset($_SESSION['message']);
            }
            ?>

            <form method="post" enctype="multipart/form-data" id="registerForm" novalidate>
               <div class="field">
                  <label for="name"><i class="fas fa-user"></i> Full Name</label>
                  <div class="control">
                     <span class="icon" aria-hidden="true"><i class="fas fa-user"></i></span>
                     <input id="name" name="name" type="text" placeholder="John Doe" required aria-required="true" onchange="checkFormCompletion()" oninput="checkFormCompletion()">
                  </div>
               </div>

               <div class="field">
                  <label for="email"><i class="fas fa-envelope"></i> Email Address</label>
                  <div class="control">
                     <span class="icon" aria-hidden="true"><i class="fas fa-envelope"></i></span>
                     <input id="email" name="email" type="email" placeholder="your@email.com" required aria-required="true" autocomplete="email" onchange="checkFormCompletion()" oninput="checkFormCompletion()">
                  </div>
               </div>

               <div class="field">
                  <label for="password"><i class="fas fa-lock"></i> Password</label>
                  <div class="control">
                     <span class="icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                     <input id="password" name="password" type="password" placeholder="Enter password" required aria-required="true" autocomplete="new-password" onchange="checkFormCompletion()" oninput="handlePasswordInput()">
                  </div>

                  <div id="pwStrengthEngine" class="pw-strength-container">
                     <div class="pw-meter">
                        <div id="pwMeterBar" class="pw-meter-bar"></div>
                     </div>
                     <span id="pwStrengthText" class="pw-text">Strength: Weak</span>
                     <div class="pw-requirements-grid">
                        <div id="reqLen" class="pw-req-item"><i class="fas fa-circle"></i> 8+ Characters</div>
                        <div id="reqUp" class="pw-req-item"><i class="fas fa-circle"></i> Uppercase</div>
                        <div id="reqNum" class="pw-req-item"><i class="fas fa-circle"></i> Number</div>
                        <div id="reqSp" class="pw-req-item"><i class="fas fa-circle"></i> Special</div>
                     </div>
                  </div>
               </div>

               <div class="field">
                  <label for="cpassword"><i class="fas fa-lock"></i> Confirm Password</label>
                  <div class="control">
                     <span class="icon" aria-hidden="true"><i class="fas fa-lock"></i></span>
                     <input id="cpassword" name="cpassword" type="password" placeholder="Re-enter password" required aria-required="true" autocomplete="new-password" onchange="checkFormCompletion()" oninput="checkFormCompletion()">
                  </div>
               </div>

               <div class="field" id="verify">
                  <label><i class="fas fa-file-upload"></i> Verification Document</label>
                  <div class="verify-section">
                     <div class="file-input-wrapper">
                        <label for="verification_image" class="file-input-label">
                           <i class="fa-solid fa-cloud-arrow-up"></i>
                           <span id="uploadText">Click or drag &amp; drop to upload</span>
                        </label>
                        <input id="verification_image" name="verification_image" type="file" accept="image/png,image/jpeg" required aria-required="true">
                     </div>
                     <div id="fileInfo" class="file-info">
                        <i class="fa-solid fa-check-circle"></i>
                        <span id="fileName"></span>
                     </div>
                     <div class="note" style="margin-top: 12px;">
                        <i class="fa-solid fa-circle-info"></i>
                        <span>Upload a clear photo of your ID or certification (JPG, PNG max 5MB).</span>
                     </div>
                  </div>
               </div>

               <div class="password-requirements">
                  <h4><i class="fa-solid fa-shield-halved"></i> Password Requirements</h4>
                  <ul>
                     <li><i class="fa-solid fa-check"></i> At least 8 characters long</li>
                     <li><i class="fa-solid fa-check"></i> Mix of uppercase, lowercase, numbers</li>
                     <li><i class="fa-solid fa-check"></i> Avoid birthdays or sequential numbers</li>
                  </ul>
               </div>

               <div class="captcha-checkbox-container" id="captchaCheckboxContainer" onclick="if (!document.getElementById('captchaCheckbox').disabled) handleCheckboxChange()">
                  <div class="checkbox-wrapper">
                     <input type="checkbox" id="captchaCheckbox" class="checkbox-input" onchange="handleCheckboxChange()" disabled>
                  </div>
                  <div class="checkbox-label-text">
                     <span class="main-text">I'm not a robot</span>
                     <span class="sub-text"></span>
                  </div>
                  <div class="captcha-logo">
                     <img src="images/capcha.png" alt="reCAPTCHA Logo">
                     <div class="logo-text">reCAPTCHA</div>
                     <div class="privacy-text">
                        <a href="#" onclick="return false;">Privacy</a> -
                        <a href="#" onclick="return false;">Terms</a>
                     </div>
                  </div>
               </div>

               <div class="captcha-modal-overlay" id="captchaModalOverlay">
                  <div class="captcha-modal">
                     <div class="captcha-modal-header">
                        <h3>Verify you're human</h3>
                        <p>Select all images with the specified object</p>
                     </div>
                     <div class="captcha-modal-body">
                        <div class="captcha-challenge" id="captchaChallenge">
                           SELECT ALL IMAGES WITH A BUS
                        </div>

                        <div class="captcha-grid" id="captchaGrid"></div>

                        <div class="captcha-info">
                           Click verify once there are none left.
                        </div>

                        <div class="captcha-actions">
                           <button type="button" class="captcha-btn" onclick="refreshCaptcha()">
                              <i class="fa-solid fa-rotate-right"></i> Refresh
                           </button>
                           <button type="button" class="captcha-btn verify" onclick="verifyCaptcha()">
                              Verify
                           </button>
                        </div>

                        <div class="captcha-status" id="captchaStatus"></div>
                     </div>
                  </div>
               </div>

               <input type="hidden" id="captcha_verified" name="captcha_verified" value="">

               <button class="btn" type="submit" name="submit" id="submitBtn">
                  <i class="fa-solid fa-user-shield"></i>
                  <span>Submit for Approval</span>
               </button>

               <div class="login-link">
                  Already approved? <a href="login.php">Sign in here</a>
               </div>
               <div class="login-link">
                  Just want to order coffee? <a href="register.php">Register as Customer</a>
               </div>
            </form>
         </div>
      </main>

      <aside class="image-panel" aria-hidden="false">
         <img src="images/logback.jpeg" alt="Coffee background - admin registration page" loading="lazy">
         <div class="image-overlay">
            <h2>
               Join Our Team
               <img src="images/logos.png" alt="Six Origins Icon">
            </h2>

            <div class="benefits">
               <div class="benefit-item">
                  <i class="fa-solid fa-user-shield"></i>
                  <span>Verified &amp; approved by the head admin</span>
               </div>
               <div class="benefit-item">
                  <i class="fa-solid fa-chart-line"></i>
                  <span>Manage orders, products &amp; sales</span>
               </div>
               <div class="benefit-item">
                  <i class="fa-solid fa-clipboard-check"></i>
                  <span>Review and process customer requests</span>
               </div>
               <div class="benefit-item">
                  <i class="fa-solid fa-users"></i>
                  <span>Help run the Six Origins community</span>
               </div>
            </div>
         </div>

         <button class="guide-btn" id="guideBtn" type="button" aria-label="Show registration guide">
            <i class="fas fa-question"></i>
         </button>
      </aside>

      <div class="guide-overlay" id="guideOverlay">
         <div class="guide-container">
            <button class="guide-close" id="guideCloseBtn" type="button" aria-label="Close guide">
               <i class="fas fa-times"></i>
            </button>
            <h3>Admin Registration Guide</h3>
            <div class="guide-steps">
               <div class="guide-step">
                  <div class="step-number">1</div>
                  <div class="step-content">
                     <h4>Enter Your Full Name</h4>
                     <p>Use your real full name so the head admin can match it with your document.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">2</div>
                  <div class="step-content">
                     <h4>Enter Your Email</h4>
                     <p>Use an email address you have access to. You will use it to sign in once approved.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">3</div>
                  <div class="step-content">
                     <h4>Set &amp; Confirm Password</h4>
                     <p>Create a strong password (8+ characters, letters, numbers, symbols) and re-enter it exactly.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">4</div>
                  <div class="step-content">
                     <h4>Upload Verification Document</h4>
                     <p>Upload a clear photo of your ID or certification (JPG or PNG, max 5MB). This is required for admin accounts.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">5</div>
                  <div class="step-content">
                     <h4>Verify You're Human</h4>
                     <p>Click the &quot;I'm not a robot&quot; checkbox and select all images matching the challenge.</p>
                  </div>
               </div>
               <div class="guide-step">
                  <div class="step-number">6</div>
                  <div class="step-content">
                     <h4>Submit &amp; Wait for Approval</h4>
                     <p>Your account stays pending until the head admin approves it. After approval, sign in using the normal login page.</p>
                  </div>
               </div>
            </div>
         </div>
      </div>
   </div>

   <script>
      /**
       * PASSWORD STRENGTH ENGINE
       */
      function handlePasswordInput() {
          const pass = document.getElementById('password').value;
          const engine = document.getElementById('pwStrengthEngine');
          const bar = document.getElementById('pwMeterBar');
          const text = document.getElementById('pwStrengthText');

          engine.style.display = pass.length > 0 ? 'block' : 'none';

          let strength = 0;

          const reqs = {
              reqLen: pass.length >= 8,
              reqUp:  /[A-Z]/.test(pass),
              reqNum: /[0-9]/.test(pass),
              reqSp:  /[^A-Za-z0-9]/.test(pass)
          };

          Object.keys(reqs).forEach(function(id){
              const el = document.getElementById(id);
              if(reqs[id]) { strength += 25; el.classList.add('met'); }
              else { el.classList.remove('met'); }
          });

          bar.style.width = strength + "%";

          if(strength <= 25) {
              bar.style.backgroundColor = 'var(--str-weak)';
              text.innerText = "Strength: Weak";
              text.style.color = 'var(--str-weak)';
          } else if(strength <= 50) {
              bar.style.backgroundColor = 'var(--str-fair)';
              text.innerText = "Strength: Fair";
              text.style.color = 'var(--str-fair)';
          } else if(strength <= 75) {
              bar.style.backgroundColor = 'var(--str-good)';
              text.innerText = "Strength: Good";
              text.style.color = 'var(--str-good)';
          } else {
              bar.style.backgroundColor = 'var(--str-strong)';
              text.innerText = "Strength: Strong";
              text.style.color = 'var(--str-strong)';
          }

          checkFormCompletion();
      }

      // CAPTCHA Configuration
      const CAPTCHA_CONFIG = {
          challenges: [
            { type: 'car', text: 'SELECT ALL IMAGES WITH A CAR' },
            { type: 'bus', text: 'SELECT ALL IMAGES WITH A BUS' },
            { type: 'road', text: 'SELECT ALL IMAGES WITH A ROAD' }
          ],
          imageTypes: ['car', 'bus', 'road'],
          totalTiles: 9
      };

      let currentChallenge = null;
      let selectedTiles = [];
      let tileData = [];
      let actualCorrectCount = 0;
      let captchaModalShown = false;

      // Initialize on page load
      document.addEventListener('DOMContentLoaded', function(){
         checkFormCompletion();
      });

      // Enable the CAPTCHA checkbox only when the form is filled in correctly
      function checkFormCompletion() {
         const name = document.getElementById('name').value.trim();
         const email = document.getElementById('email').value.trim();
         const password = document.getElementById('password').value;
         const cpassword = document.getElementById('cpassword').value;
         const checkboxContainer = document.getElementById('captchaCheckboxContainer');
         const checkbox = document.getElementById('captchaCheckbox');

         const nameValid = name.length >= 2;
         const emailValid = email && /^\S+@\S+\.\S+$/.test(email);
         const passwordValid = password && password.length >= 8;
         const passwordMatch = password === cpassword;

         const fileInput = document.getElementById('verification_image');
         const fileSelected = fileInput.files && fileInput.files.length > 0;

         const isFormComplete = nameValid && emailValid && passwordValid && passwordMatch && fileSelected;

         if(isFormComplete) {
            checkboxContainer.classList.remove('disabled');
            checkbox.disabled = false;
         } else {
            checkboxContainer.classList.add('disabled');
            checkbox.disabled = true;
            checkbox.checked = false;
            document.getElementById('captcha_verified').value = '';
            document.getElementById('captchaModalOverlay').classList.remove('active');
         }
      }

      function handleCheckboxChange() {
         const checkbox = document.getElementById('captchaCheckbox');
         const modalOverlay = document.getElementById('captchaModalOverlay');

         if(checkbox.disabled) {
            return;
         }

         if(checkbox.checked) {
            modalOverlay.classList.add('active');

            if(!captchaModalShown) {
               generateCaptchaGrid();
               captchaModalShown = true;
            }
         } else {
            modalOverlay.classList.remove('active');
            document.getElementById('captcha_verified').value = '';
         }
      }

      function generateCaptchaGrid() {
          const grid = document.getElementById('captchaGrid');
          grid.innerHTML = '';
          selectedTiles = [];
          tileData = [];

          currentChallenge = CAPTCHA_CONFIG.challenges[Math.floor(Math.random() * CAPTCHA_CONFIG.challenges.length)];
          document.getElementById('captchaChallenge').textContent = currentChallenge.text;

          // Random number of correct images (2 to 5)
          actualCorrectCount = Math.floor(Math.random() * 4) + 2;
          let incorrectCount = CAPTCHA_CONFIG.totalTiles - actualCorrectCount;

          let tiles = [];

          for(let i = 0; i < actualCorrectCount; i++) {
             tiles.push({ type: currentChallenge.type, isCorrect: true });
          }

          for(let i = 0; i < incorrectCount; i++) {
             let randomType;
             do {
                randomType = CAPTCHA_CONFIG.imageTypes[Math.floor(Math.random() * CAPTCHA_CONFIG.imageTypes.length)];
             } while(randomType === currentChallenge.type);
             tiles.push({ type: randomType, isCorrect: false });
          }

          tiles = shuffleArray(tiles);
          tileData = tiles;

          tiles.forEach((tile, index) => {
             const tileElement = document.createElement('div');
             tileElement.className = 'captcha-tile';
             tileElement.id = 'tile-' + index;

             const img = document.createElement('img');
             img.src = 'images/captcha/' + tile.type + '-' + (Math.floor(Math.random() * 3) + 1) + '.jpg';
             img.alt = tile.type + ' image';
             img.style.display = 'none';

             const placeholder = document.createElement('div');
             placeholder.className = 'captcha-tile-placeholder';
             const icons = { 'car': '🚗', 'bus': '🚌', 'road': '🛣️' };
             placeholder.textContent = icons[tile.type];

             img.onload = function() {
                this.style.display = 'block';
                placeholder.style.display = 'none';
             };

             tileElement.appendChild(img);
             tileElement.appendChild(placeholder);
             tileElement.addEventListener('click', () => toggleTileSelection(index));
             grid.appendChild(tileElement);
          });
      }

      function toggleTileSelection(index) {
         const tile = document.getElementById('tile-' + index);
         const tileIndex = selectedTiles.indexOf(index);

         if(tileIndex > -1) {
            selectedTiles.splice(tileIndex, 1);
            tile.classList.remove('selected');
         } else {
            selectedTiles.push(index);
            tile.classList.add('selected');
         }
      }

      function verifyCaptcha() {
          const statusElement = document.getElementById('captchaStatus');
          statusElement.className = '';
          statusElement.textContent = '';

          if(selectedTiles.length !== actualCorrectCount) {
             statusElement.className = 'captcha-status error';
             statusElement.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> Please select ALL matching images (' + actualCorrectCount + ' found)';
             return;
          }

          let allCorrect = true;
          selectedTiles.forEach(index => {
             if(!tileData[index].isCorrect) {
                allCorrect = false;
             }
          });

          if(allCorrect) {
             statusElement.className = 'captcha-status success';
             statusElement.innerHTML = '<i class="fa-solid fa-check-circle"></i> Verification successful!';
             document.getElementById('captcha_verified').value = 'true';

             setTimeout(() => {
                document.getElementById('captchaModalOverlay').classList.remove('active');
                document.getElementById('captchaCheckbox').checked = true;
             }, 1500);
          } else {
             statusElement.className = 'captcha-status error';
             statusElement.innerHTML = '<i class="fa-solid fa-exclamation-circle"></i> Incorrect selection. Try again.';
             setTimeout(refreshCaptcha, 1000);
          }
      }

      function refreshCaptcha() {
         document.getElementById('captcha_verified').value = '';
         document.getElementById('captchaStatus').className = '';
         document.getElementById('captchaStatus').textContent = '';
         generateCaptchaGrid();
      }

      function shuffleArray(array) {
         const shuffled = [...array];
         for(let i = shuffled.length - 1; i > 0; i--) {
            const j = Math.floor(Math.random() * (i + 1));
            [shuffled[i], shuffled[j]] = [shuffled[j], shuffled[i]];
         }
         return shuffled;
      }

      // Close modal when clicking outside
      document.getElementById('captchaModalOverlay').addEventListener('click', function(e) {
         if(e.target === this) {
            this.classList.remove('active');
            document.getElementById('captchaCheckbox').checked = false;
         }
      });

      // Verification document: validation, preview, drag & drop
      (function(){
         const fileInput = document.getElementById('verification_image');
         const label = document.querySelector('.file-input-label');
         const fileInfo = document.getElementById('fileInfo');
         const fileName = document.getElementById('fileName');
         const idleBg = 'linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%)';

         if(!fileInput || !label) return;

         fileInput.addEventListener('change', function(){
            if(this.files.length > 0){
               const file = this.files[0];
               const okType = ['image/jpeg', 'image/png'].indexOf(file.type) !== -1;
               const okSize = file.size <= 5 * 1024 * 1024;

               if(!okType || !okSize){
                  alert('Please upload a JPG or PNG image up to 5MB.');
                  this.value = '';
                  fileInfo.classList.remove('shown');
                  label.style.borderColor = 'var(--primary-red)';
                  checkFormCompletion();
                  return;
               }

               const sizeMB = (file.size / 1024 / 1024).toFixed(2);
               fileName.textContent = '✓ ' + file.name + ' (' + sizeMB + ' MB)';
               fileInfo.classList.add('shown');
               label.style.borderColor = '#2D5A3D';
            } else {
               fileInfo.classList.remove('shown');
               label.style.borderColor = 'var(--primary-red)';
            }
            checkFormCompletion();
         });

         label.addEventListener('dragover', function(e){
            e.preventDefault();
            label.style.background = 'linear-gradient(135deg, rgba(45, 90, 61, 0.15) 0%, rgba(45, 90, 61, 0.08) 100%)';
            label.style.borderColor = '#2D5A3D';
         });

         label.addEventListener('dragleave', function(){
            label.style.background = idleBg;
            label.style.borderColor = 'var(--primary-red)';
         });

         label.addEventListener('drop', function(e){
            e.preventDefault();
            label.style.background = idleBg;
            label.style.borderColor = 'var(--primary-red)';
            const files = e.dataTransfer.files;
            if(files.length > 0) {
               fileInput.files = files;
               fileInput.dispatchEvent(new Event('change', { bubbles: true }));
            }
         });
      })();

      // Guide overlay toggle
      (function(){
         const guideBtn = document.getElementById('guideBtn');
         const guideOverlay = document.getElementById('guideOverlay');
         const guideCloseBtn = document.getElementById('guideCloseBtn');

         if(guideBtn && guideOverlay){
            guideBtn.addEventListener('click', function(){
               guideOverlay.classList.add('active');
            });

            guideCloseBtn.addEventListener('click', function(){
               guideOverlay.classList.remove('active');
            });

            guideOverlay.addEventListener('click', function(e){
               if(e.target === guideOverlay){
                  guideOverlay.classList.remove('active');
               }
            });

            document.addEventListener('keydown', function(e){
               if(e.key === 'Escape' && guideOverlay.classList.contains('active')){
                  guideOverlay.classList.remove('active');
               }
            });
         }
      })();

      // Client-side validation
      document.getElementById('registerForm').addEventListener('submit', function(e){
         const name = document.getElementById('name').value.trim();
         const email = document.getElementById('email').value.trim();
         const password = document.getElementById('password').value;
         const cpassword = document.getElementById('cpassword').value;
         const captchaVerified = document.getElementById('captcha_verified').value;

         if(!name || name.length < 2){
            alert('Please enter your full name');
            e.preventDefault();
            return false;
         }

         if(!email || !/^\S+@\S+\.\S+$/.test(email)){
            alert('Please enter a valid email address');
            e.preventDefault();
            return false;
         }

         if(!password || password.length < 8){
            alert('Password must be at least 8 characters long');
            e.preventDefault();
            return false;
         }

         if(password !== cpassword){
            alert('Passwords do not match');
            e.preventDefault();
            return false;
         }

         const verifyFile = document.getElementById('verification_image');
         if(!verifyFile.files || verifyFile.files.length === 0){
            alert('Please upload your verification document');
            e.preventDefault();
            return false;
         }

         if(captchaVerified !== 'true'){
            alert('Please complete the robot verification!');
            e.preventDefault();
            return false;
         }
      });

      // Auto-dismiss messages
      const messages = document.querySelectorAll('.message');
      messages.forEach(msg => {
         setTimeout(() => {
            msg.style.opacity = '0';
            msg.style.transform = 'translateY(-10px)';
            setTimeout(() => msg.remove(), 300);
         }, 5000);
      });
   </script>

</body>
</html>