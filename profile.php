<?php
session_start();
include 'config.php';

// ===== UTILITY FUNCTIONS =====

/**
 * Validate full name: letters (including ñ, é, etc.), spaces, hyphens, apostrophes, periods. Max 100 chars.
 */
function validateName($name) {
    return preg_match("/^[\p{L}\p{M} .'\-’]{1,100}$/u", $name);
}

/**
 * Validate email address using PHP's filter
 */
function validateEmail($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Sanitize user input: strip tags & trim whitespace
 */
function sanitizeInput($value) {
    return trim(strip_tags((string)$value));
}

/**
 * Turn ANY value (string, number, null, or an array of messages) into plain text.
 * This is what stops "htmlspecialchars(): Argument #1 must be of type string, array given".
 */
function flattenToString($value) {
    if (is_array($value)) {
        $parts = [];
        array_walk_recursive($value, function ($item) use (&$parts) {
            if (is_scalar($item)) {
                $parts[] = (string)$item;
            }
        });
        return implode(' ', $parts);
    }
    return is_scalar($value) ? (string)$value : '';
}

/**
 * Safely escape output for HTML to prevent XSS (accepts strings OR arrays)
 */
function escapeOutput($str) {
    return htmlspecialchars(flattenToString($str), ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Store a message (string or list of strings) to show after the redirect.
 * $type: 'success' or 'error'
 */
function flash($message, $type = 'success') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

/**
 * CSRF: Validate request origin
 * Replace with real validation logic for production
 */
function validateOrigin() {
    // For demonstration: always true. Implement stricter validation in production.
    return true;
}

/**
 * CSRF: Validate the submitted CSRF token
 */
function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Log security events to file or database
 * Customize this for your needs
 */
function logSecurityEvent($event, $data = []) {
    // Example: log to a file
    // $log_entry = date('[Y-m-d H:i:s] ') . $event . ' ' . json_encode($data) . "\n";
    // file_put_contents('security.log', $log_entry, FILE_APPEND);
    
    // For now, do nothing. Implement logging as needed.
}

/**
 * Auto-generate CSRF token if not set
 */
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ============= VALIDATE REQUEST METHOD =============
if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    die('Method Not Allowed');
}

// ============= VALIDATE REQUEST ORIGIN (CSRF PREVENTION) =============
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateOrigin()) {
        logSecurityEvent('CSRF_ATTEMPT', [
            'page' => 'profile',
            'action' => $_POST['update_profile'] ?? $_POST['change_password'] ?? 'unknown'
        ]);
        http_response_code(403);
        die('🔒 Invalid request origin. Request rejected.');
    }
    
    // ✅ CSRF TOKEN VALIDATION
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        logSecurityEvent('CSRF_VIOLATION', [
            'action' => $_POST['update_profile'] ?? $_POST['change_password'] ?? 'unknown',
            'page' => 'profile'
        ]);
        http_response_code(403);
        die('🔒 Security validation failed. Please try again.');
    }
}

// ============= SESSION & USER SETUP =============
if (!isset($_SESSION['user_id'])) {
    header('location:login.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];

// ============= HANDLE PROFILE UPDATE =============
if (isset($_POST['update_profile'])) {
    $new_name = sanitizeInput($_POST['name'] ?? '');
    $new_email = sanitizeInput($_POST['email'] ?? '');
    $twofa_enabled = (isset($_POST['twofa_email_enabled']) && $_POST['twofa_email_enabled'] == '1') ? 1 : 0;

    // ============= VALIDATION =============
    $validation_errors = [];

    // Validate name
    if (empty($new_name)) {
        $validation_errors[] = 'Name is required.';
    } elseif (!validateName($new_name)) {
        $validation_errors[] = 'Name contains invalid characters (use letters, spaces, hyphens, apostrophes only). Max 100 characters.';
    }

    // Validate email
    if (empty($new_email)) {
        $validation_errors[] = 'Email is required.';
    } elseif (!validateEmail($new_email)) {
        $validation_errors[] = 'Invalid email address.';
    }

    // Check if email is already used by another user
    if (!empty($new_email) && validateEmail($new_email)) {
        $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        if (!$stmt) {
            logSecurityEvent('DATABASE_ERROR', ['error' => 'Email check prepare failed']);
            die('Database error. Please try again later.');
        }
        $stmt->bind_param("si", $new_email, $user_id);
        if (!$stmt->execute()) {
            logSecurityEvent('DATABASE_ERROR', ['error' => 'Email check execute failed']);
            die('Database error. Please try again later.');
        }
        $email_result = $stmt->get_result();
        if ($email_result->num_rows > 0) {
            $validation_errors[] = 'This email is already registered to another account.';
        }
        $stmt->close();
    }

    if (!empty($validation_errors)) {
        // Show ALL errors (before, only the last one survived), escaped once at display time
        flash($validation_errors, 'error');
        logSecurityEvent('PROFILE_VALIDATION_FAILED', ['errors' => count($validation_errors), 'user_id' => $user_id]);
        header('location:profile.php');
        exit();
    }

    // ============= HANDLE PROFILE IMAGE UPLOAD =============
    $uploaded_image_name = null;
    $dest_path = null;
    if (!empty($_FILES['profile_image']['name']) && is_uploaded_file($_FILES['profile_image']['tmp_name'])) {
        $file = $_FILES['profile_image'];
        $max_size = 2 * 1024 * 1024; // 2MB
        
        // MIME type validation
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        $allowed = ['image/jpeg' => '.jpg', 'image/png' => '.png', 'image/webp' => '.webp'];
        if (!array_key_exists($mime, $allowed)) {
            flash('Profile image must be JPG, PNG or WEBP.', 'error');
            logSecurityEvent('INVALID_FILE_TYPE', ['mime' => $mime, 'user_id' => $user_id]);
            header('location:profile.php');
            exit();
        }

        if ($file['size'] > $max_size) {
            flash('Profile image is too large (max 2 MB).', 'error');
            logSecurityEvent('FILE_TOO_LARGE', ['size' => $file['size'], 'user_id' => $user_id]);
            header('location:profile.php');
            exit();
        }

        // Verify it's actually an image
        if (!getimagesize($file['tmp_name'])) {
            flash('File is not a valid image.', 'error');
            logSecurityEvent('INVALID_IMAGE_FILE', ['user_id' => $user_id]);
            header('location:profile.php');
            exit();
        }

        // Generate safe filename
        $ext = $allowed[$mime];
        $new_filename = 'user_' . $user_id . '_' . time() . '_' . bin2hex(random_bytes(6)) . $ext;
        $dest_dir = __DIR__ . '/images/user_uploads/';
        if (!is_dir($dest_dir)) {
            mkdir($dest_dir, 0755, true);
        }
        $dest_path = $dest_dir . $new_filename;

        if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
            flash('Failed to upload profile image.', 'error');
            logSecurityEvent('FILE_UPLOAD_FAILED', ['filename' => $new_filename, 'user_id' => $user_id]);
            header('location:profile.php');
            exit();
        }

        $uploaded_image_name = 'user_uploads/' . $new_filename;
        logSecurityEvent('FILE_UPLOADED', ['filename' => $new_filename, 'size' => $file['size'], 'user_id' => $user_id]);
    }

    // ============= BEGIN TRANSACTION =============
    $conn->begin_transaction();

    try {
        // ============= LOOK UP OLD PROFILE IMAGE (deleted only AFTER the update succeeds) =============
        $old_image = null;
        if ($uploaded_image_name !== null) {
            $stmt = $conn->prepare("SELECT profile_image FROM users WHERE id = ? LIMIT 1");
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("i", $user_id);
            if (!$stmt->execute()) {
                throw new Exception('Database execute failed: ' . $stmt->error);
            }
            $old_result = $stmt->get_result();
            
            if ($old_row = $old_result->fetch_assoc()) {
                $old_image = $old_row['profile_image'];
            }
            $stmt->close();
        }

        // ============= UPDATE PROFILE (PREPARED STATEMENT) =============
        if ($uploaded_image_name !== null) {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, profile_image = ?, twofa_email_enabled = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("sssii", $new_name, $new_email, $uploaded_image_name, $twofa_enabled, $user_id);
        } else {
            $stmt = $conn->prepare("UPDATE users SET name = ?, email = ?, twofa_email_enabled = ? WHERE id = ?");
            if (!$stmt) {
                throw new Exception('Database prepare failed: ' . $conn->error);
            }
            $stmt->bind_param("ssii", $new_name, $new_email, $twofa_enabled, $user_id);
        }

        if (!$stmt->execute()) {
            throw new Exception('Update failed: ' . $stmt->error);
        }
        $stmt->close();

        // ============= COMMIT TRANSACTION =============
        $conn->commit();

        // ============= NOW it is safe to delete the old image =============
        if ($uploaded_image_name !== null && !empty($old_image) && $old_image !== 'no_profile.jpg') {
            $images_root = realpath(__DIR__ . '/images');
            $old_path = realpath(__DIR__ . '/images/' . $old_image);
            if ($images_root !== false && $old_path !== false && str_starts_with($old_path, $images_root . DIRECTORY_SEPARATOR)) {
                @unlink($old_path);
                logSecurityEvent('OLD_IMAGE_DELETED', ['old_image' => basename($old_path), 'user_id' => $user_id]);
            }
        }

        // Update session values
        $_SESSION['user_name'] = $new_name;
        $_SESSION['user_email'] = $new_email;
        
        flash('✅ Profile updated successfully!', 'success');
        logSecurityEvent('PROFILE_UPDATED', ['user_id' => $user_id, 'name_updated' => true, 'email_updated' => true]);
        header('location:profile.php');
        exit();

    } catch (Exception $e) {
        // ============= ROLLBACK TRANSACTION =============
        $conn->rollback();

        // The update failed, so remove the image we just uploaded (keeps the old one intact)
        if ($dest_path !== null && file_exists($dest_path)) {
            @unlink($dest_path);
        }

        error_log('Profile update failed (user ' . $user_id . '): ' . $e->getMessage());
        // Don't show raw database errors to the user
        flash('❌ Profile update failed. Please try again.', 'error');
        logSecurityEvent('PROFILE_UPDATE_FAILED', ['error' => $e->getMessage(), 'user_id' => $user_id]);
        header('location:profile.php');
        exit();
    }
}

// ============= HANDLE PASSWORD CHANGE =============
if (isset($_POST['change_password'])) {
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $validation_errors = [];

    // Validate current password
    if (empty($current_password)) {
        $validation_errors[] = 'Current password is required.';
    }

    // Validate new password
    if (empty($new_password)) {
        $validation_errors[] = 'New password is required.';
    } elseif (strlen($new_password) < 8) {
        $validation_errors[] = 'New password must be at least 8 characters long.';
    }

    // Validate confirm password
    if (empty($confirm_password)) {
        $validation_errors[] = 'Password confirmation is required.';
    }

    // Check if passwords match
    if (!empty($new_password) && !empty($confirm_password) && $new_password !== $confirm_password) {
        $validation_errors[] = 'New passwords do not match.';
    }

    if (!empty($validation_errors)) {
        flash($validation_errors, 'error');
        $_SESSION['open_password_panel'] = true; // keep the password box open so the user sees what to fix
        logSecurityEvent('PASSWORD_VALIDATION_FAILED', ['errors' => count($validation_errors), 'user_id' => $user_id]);
        header('location:profile.php');
        exit();
    }

    // ============= VERIFY CURRENT PASSWORD (PREPARED STATEMENT) =============
    $stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
    if (!$stmt) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Password fetch prepare failed']);
        die('Database error. Please try again later.');
    }
    $stmt->bind_param("i", $user_id);
    if (!$stmt->execute()) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Password fetch execute failed']);
        die('Database error. Please try again later.');
    }
    
    $user_result = $stmt->get_result();
    if ($user_result->num_rows === 0) {
        logSecurityEvent('USER_NOT_FOUND', ['user_id' => $user_id]);
        die('User not found.');
    }
    
    $user_data = $user_result->fetch_assoc();
    $stored_password = $user_data['password'];
    $stmt->close();

    // ============= VERIFY PASSWORD WITH password_verify() =============
    if (!password_verify($current_password, $stored_password)) {
        flash('❌ Current password is incorrect.', 'error');
        $_SESSION['open_password_panel'] = true;
        logSecurityEvent('PASSWORD_VERIFICATION_FAILED', ['user_id' => $user_id]);
        header('location:profile.php');
        exit();
    }

    // ============= CHECK NEW PASSWORD IS DIFFERENT =============
    if (password_verify($new_password, $stored_password)) {
        flash('❌ New password must be different from current password.', 'error');
        $_SESSION['open_password_panel'] = true;
        logSecurityEvent('SAME_PASSWORD_ATTEMPT', ['user_id' => $user_id]);
        header('location:profile.php');
        exit();
    }

    // ============= UPDATE PASSWORD (PREPARED STATEMENT) =============
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
    if (!$stmt) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Password update prepare failed']);
        die('Database error. Please try again later.');
    }
    $stmt->bind_param("si", $hashed_password, $user_id);
    if (!$stmt->execute()) {
        logSecurityEvent('DATABASE_ERROR', ['error' => 'Password update execute failed']);
        die('Database error. Please try again later.');
    }
    $stmt->close();

    flash('✅ Password successfully changed!', 'success');
    logSecurityEvent('PASSWORD_CHANGED', ['user_id' => $user_id]);
    header('location:profile.php');
    exit();
}

// ============= FETCH FRESH USER DATA (PREPARED STATEMENT) =============
$stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
if (!$stmt) {
    logSecurityEvent('DATABASE_ERROR', ['error' => 'User data fetch prepare failed']);
    die('Database error. Please try again later.');
}
$stmt->bind_param("i", $user_id);
if (!$stmt->execute()) {
    logSecurityEvent('DATABASE_ERROR', ['error' => 'User data fetch execute failed']);
    die('Database error. Please try again later.');
}

$user_result = $stmt->get_result();
if ($user_result->num_rows === 0) {
    logSecurityEvent('USER_NOT_FOUND', ['user_id' => $user_id]);
    die('User not found.');
}

$user = $user_result->fetch_assoc();
$stmt->close();

if (!isset($_SESSION['user_name'])) {
    $_SESSION['user_name'] = $user['name'];
}
if (!isset($_SESSION['user_email'])) {
    $_SESSION['user_email'] = $user['email'];
}

// ============= PREPARE FLASH MESSAGES (works whether the message is a string OR an array) =============
$flash_messages = [];
if (isset($_SESSION['message'])) {
    $raw_messages = $_SESSION['message'];
    if (!is_array($raw_messages)) {
        $raw_messages = [$raw_messages];
    }
    array_walk_recursive($raw_messages, function ($item) use (&$flash_messages) {
        if (is_scalar($item) && trim((string)$item) !== '') {
            $flash_messages[] = (string)$item;
        }
    });
}
$flash_type = (($_SESSION['message_type'] ?? 'success') === 'error') ? 'error' : 'success';
unset($_SESSION['message'], $_SESSION['message_type']);

$open_password_panel = !empty($_SESSION['open_password_panel']);
unset($_SESSION['open_password_panel']);

// Profile image: fall back to the default if the file is missing on disk
$profile_img_file = 'no_profile.jpg';
if (!empty($user['profile_image'])) {
    $candidate_image = (string)$user['profile_image'];
    $images_root = realpath(__DIR__ . '/images');
    $candidate_path = realpath(__DIR__ . '/images/' . $candidate_image);
    if ($images_root !== false && $candidate_path !== false && str_starts_with($candidate_path, $images_root . DIRECTORY_SEPARATOR)) {
        $profile_img_file = $candidate_image;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Profile — Six Origins Cafe</title>
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

      /* RESPONSIVE FIX: only <html> clips sideways overflow (and no fixed height:100%). Putting overflow-x:hidden
         on BOTH html and body turns <body> into its own scroll container, which breaks sticky/fixed headers on mobile. */
      html {
        width: 100%;
        overflow-x: hidden;
        -webkit-text-size-adjust: 100%;
        text-size-adjust: 100%;
      }

      body {
        width: 100%;
        overflow-x: clip;
        min-height: 100vh;
        background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
        color: var(--dark-brown);
        line-height: 1.7;
        font-size: 16px;
        margin: 0;
        padding: 0;
      }

      img { max-width: 100%; }

      /* HERO SECTION WITH BACKGROUND IMAGE */
      /* RESPONSIVE FIX: the hero is a direct child of <body>, so it simply fills the width (no 100vw hack that
         overshoots on phones). It grows with its content (min-height instead of a fixed height), so the title,
         text and buttons never get cut off on small screens. */
      .hero {
        position: relative;
        width: 100%;
        margin: 0;
        min-height: 550px;
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
        width: 100%;
        max-width: 700px;
        padding: clamp(24px, 5vw, 40px);
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
        font-size: clamp(1.7rem, 6.5vw, 4.2rem);
        margin-bottom: clamp(12px, 2.5vw, 24px);
        color: #ffffff;
        font-weight: 900;
        letter-spacing: -1px;
        line-height: 1.15;
        text-shadow: 0 10px 30px rgba(0, 0, 0, 0.5);
        overflow-wrap: break-word;
      }

      .hero p {
        color: rgba(255, 255, 255, 0.95);
        font-size: clamp(0.95rem, 2.6vw, 1.4rem);
        margin-bottom: clamp(16px, 3vw, 28px);
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
        justify-content: center;
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

      .profile-wrap {
        display: grid;
        grid-template-columns: 340px minmax(0, 1fr);
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
        min-width: 0;
      }

      .card:hover {
        box-shadow: var(--shadow-hover);
        border-color: var(--primary-red);
        transform: translateY(-4px);
      }

      /* Don't move the card while someone is typing in it */
      .card:focus-within { transform: none; }

      .card h2 {
        margin-top: 0;
        margin-bottom: 18px;
        font-weight: 900;
        font-size: 1.8rem;
        color: var(--dark-brown);
        letter-spacing: -0.5px;
      }

      .card h3 {
        margin: 0 0 12px 0;
        color: var(--dark-brown);
        font-size: 1.3rem;
        font-weight: 900;
      }

      .profile-summary {
        text-align: center;
        padding-bottom: 6px;
      }

      .profile-summary img {
        width: 140px;
        height: 140px;
        border-radius: 14px;
        object-fit: cover;
        border: 1.5px solid #F0E6D8;
        display: block;
        margin: 0 auto 16px;
        box-shadow: var(--shadow);
        transition: var(--transition);
      }

      .profile-summary img:hover {
        box-shadow: var(--shadow-hover);
        transform: translateY(-2px);
      }

      /* HEADER FIX: scoped to the summary card so it can never touch ".profile-name" in header.php */
      .profile-summary .profile-name {
        font-size: 1.4rem;
        font-weight: 900;
        color: var(--dark-brown);
        margin-bottom: 6px;
        overflow-wrap: anywhere;
      }

      .profile-summary .profile-email {
        color: var(--gray-brown);
        font-weight: 600;
        margin-bottom: 10px;
        font-size: 0.95rem;
        overflow-wrap: anywhere;
      }

      .wallet {
        font-weight: 800;
        color: var(--primary-red);
        margin-top: 12px;
        font-size: 1.1em;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 6px;
      }

      .wallet i {
        font-size: 0.95rem;
      }

      .profile-summary .profile-actions {
        margin-top: 18px;
      }

      .profile-summary .profile-actions .btn {
        width: 100%;
        justify-content: center;
      }

      form.profile-form {
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

      input[type="text"],
      input[type="email"],
      input[type="file"],
      input[type="password"] {
        width: 100%;
        max-width: 100%;
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

      input[type="text"]:focus,
      input[type="email"]:focus,
      input[type="file"]:focus,
      input[type="password"]:focus {
        outline: none;
        border-color: var(--primary-red);
        box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
      }

      .checkbox-wrapper {
        display: flex;
        align-items: flex-start;
        gap: 12px;
        padding: 12px;
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.05) 0%, rgba(198, 69, 62, 0.02) 100%);
        border-radius: 10px;
        border: 1.5px solid #F0E6D8;
      }

      .checkbox-wrapper input[type="checkbox"] {
        width: 20px;
        height: 20px;
        margin-top: 2px;
        cursor: pointer;
        accent-color: var(--primary-red);
        flex-shrink: 0;
      }

      .checkbox-label {
        flex: 1;
        min-width: 0;
      }

      .checkbox-label label {
        margin: 0;
        cursor: pointer;
        font-weight: 700;
        color: var(--dark-brown);
        font-size: 1em;
      }

      /* HEADER FIX: header.php also has an ".actions" group. This rule used to be unscoped, and its mobile
         "flex-direction: column-reverse" stacked the header buttons vertically. Now it only affects the page content. */
      .content-wrapper .actions {
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

      .message {
        margin-bottom: 20px;
        padding: 15px 18px;
        border-left: 5px solid var(--primary-red);
        border-radius: var(--radius);
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
        font-weight: 600;
        color: var(--primary-red);
        box-shadow: 0 6px 16px rgba(198, 69, 62, 0.1);
        display: flex;
        gap: 10px;
        align-items: center;
        animation: slideDown 0.4s ease;
        border: 1px solid rgba(198, 69, 62, 0.2);
        overflow-wrap: anywhere;
      }

      .message::before {
        content: '✓';
        font-weight: 900;
        font-size: 1.3em;
        flex-shrink: 0;
      }

      /* Error variant + multiple-message list (added) */
      .message.error {
        background: linear-gradient(135deg, rgba(217, 126, 106, 0.12) 0%, rgba(217, 126, 106, 0.05) 100%);
        border-left-color: #D97E6A;
        border-color: rgba(217, 126, 106, 0.3);
        color: #8B4A42;
      }

      .message.error::before {
        content: none;
      }

      .message ul {
        margin: 0;
        padding-left: 18px;
      }

      .message li + li {
        margin-top: 4px;
      }

      .security-section {
        padding-top: 16px;
        border-top: 1px dashed rgba(94, 31, 19, 0.1);
        margin-top: 20px;
      }

      .security-section p {
        color: var(--gray-brown);
        font-size: 0.95rem;
        margin: 0 0 12px 0;
        font-weight: 500;
      }

      .password-panel {
        display: none;
        margin-top: 14px;
        padding: 16px;
        background: linear-gradient(135deg, rgba(198, 69, 62, 0.05) 0%, rgba(198, 69, 62, 0.02) 100%);
        border-radius: 10px;
        border: 1.5px solid #F0E6D8;
        animation: slideDown 0.3s ease;
      }

      .password-form {
        display: flex;
        flex-direction: column;
        gap: 12px;
      }

      .password-form input {
        padding: 12px 14px;
        border-radius: 10px;
        border: 1.5px solid #F0E6D8;
        background: #fff;
        font-size: 0.95rem;
        color: var(--dark-brown);
        font-weight: 500;
        transition: var(--transition);
        font-family: inherit;
      }

      .password-form input:focus {
        outline: none;
        border-color: var(--primary-red);
        box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
      }

      .password-form input::placeholder {
        color: var(--gray-brown);
      }

      .toggle-btn {
        background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
        color: #fff;
        padding: 11px 24px;
        border-radius: 10px;
        border: 0;
        cursor: pointer;
        font-weight: 800;
        font-size: 0.98em;
        transition: var(--transition);
        display: inline-flex;
        align-items: center;
        gap: 8px;
        position: relative;
        overflow: hidden;
      }

      .toggle-btn::before {
        content: '';
        position: absolute;
        top: 0;
        left: -100%;
        width: 100%;
        height: 100%;
        background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
        transition: left 0.5s ease;
      }

      .toggle-btn:hover::before {
        left: 100%;
      }

      .toggle-btn[aria-expanded="true"] {
        background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      }

      .toggle-btn:hover {
        background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
      }

      .helper {
        font-size: 0.92rem;
        color: var(--gray-brown);
        margin-top: 6px;
        display: flex;
        align-items: center;
        gap: 6px;
      }

      .helper i {
        font-size: 0.85rem;
        flex-shrink: 0;
      }

      hr {
        margin: 20px 0;
        border: none;
        border-top: 1px dashed rgba(94, 31, 19, 0.1);
      }

      /* ============================================
         RESPONSIVE
         ============================================ */
      @media (max-width: 1100px) {
        .profile-wrap {
          grid-template-columns: minmax(0, 1fr);
        }
      }

      @media (max-width: 900px) {
        .content-wrapper {
          padding: 20px 14px 48px;
        }

        .hero {
          min-height: 420px;
          background-attachment: scroll;
        }

        .hero-buttons {
          gap: 12px;
        }

        .hero .btn, .hero .btn-ghost {
          padding: 12px 24px;
          font-size: 0.95em;
        }

        .profile-wrap {
          gap: 16px;
        }

        .card {
          padding: 22px;
        }

        .card h2 {
          font-size: 1.5rem;
        }

        .profile-summary img {
          width: 120px;
          height: 120px;
        }

        .content-wrapper .actions {
          flex-direction: column-reverse;
        }

        .content-wrapper .actions .btn {
          width: 100%;
          justify-content: center;
        }

        /* 16px stops iOS from zooming the page when a field is focused */
        input[type="text"],
        input[type="email"],
        input[type="file"],
        input[type="password"],
        .password-form input {
          font-size: 16px;
        }

        /* Comfortable tap targets */
        .btn, .toggle-btn {
          min-height: 44px;
        }
      }

      @media (max-width: 768px) {
        .content-wrapper {
          padding: 18px 12px 36px;
        }

        .hero {
          min-height: 360px;
        }

        .hero-buttons {
          flex-direction: column;
          align-items: stretch;
          gap: 10px;
        }

        .hero .btn, .hero .btn-ghost {
          width: 100%;
          padding: 11px 18px;
          font-size: 0.9em;
          gap: 6px;
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
        }

        .profile-summary img {
          width: 100px;
          height: 100px;
        }

        .profile-summary .profile-name {
          font-size: 1.15rem;
        }

        .profile-summary .profile-email {
          font-size: 0.9rem;
        }

        .btn {
          padding: 10px 16px;
          font-size: 0.9em;
        }

        label {
          font-size: 0.95rem;
        }

        input[type="text"],
        input[type="email"],
        input[type="file"],
        input[type="password"] {
          padding: 10px 12px;
        }

        .checkbox-wrapper {
          padding: 10px;
        }

        .checkbox-wrapper input[type="checkbox"] {
          width: 18px;
          height: 18px;
        }

        .password-form input {
          padding: 10px 12px;
        }

        .message {
          padding: 12px 14px;
          font-size: 0.95em;
        }

        .toggle-btn {
          width: 100%;
          justify-content: center;
        }
      }

      @media (max-width: 480px) {
        .content-wrapper {
          padding: 14px 10px 28px;
        }

        .hero {
          min-height: 320px;
        }

        .hero-content {
          padding: 24px 14px;
        }

        .card {
          padding: 14px;
        }

        .card h2 {
          font-size: 1.1rem;
        }

        .profile-summary img {
          width: 90px;
          height: 90px;
          margin-bottom: 12px;
        }

        .profile-summary .profile-name {
          font-size: 1rem;
        }

        .profile-summary .profile-email {
          font-size: 0.85rem;
        }

        .wallet {
          font-size: 1rem;
        }

        .btn {
          padding: 9px 12px;
          font-size: 0.85em;
        }

        label {
          font-size: 0.9rem;
          margin-bottom: 6px;
        }

        .helper {
          font-size: 0.85rem;
        }

        .password-panel {
          padding: 12px;
        }

        .message {
          padding: 10px 12px;
          font-size: 0.85em;
          margin-bottom: 12px;
        }
      }

      @media (max-width: 360px) {
        .card {
          padding: 12px;
        }

        .card h2 {
          font-size: 1rem;
        }
      }

      /* Hover "lift" effects feel sticky on touch screens */
      @media (hover: none) {
        .card:hover, .profile-summary img:hover { transform: none; }
      }
    </style>
</head>
<body>

<?php include 'header.php'; ?>

<!-- HERO SECTION WITH BACKGROUND IMAGE -->
<section class="hero" aria-labelledby="profileTitle" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.25) 0%, rgba(0, 0, 0, 0.35) 100%), url('images/aboback.png') center/cover no-repeat;">
  <div class="hero-content">
    <h1 id="profileTitle">Your Profile</h1>
    <p>Update your personal information, manage security settings, and customize your account preferences. Your data is always secure with us.</p>
    <div class="hero-buttons">
      <a href="orders.php" class="btn">
        <i class="fa-solid fa-receipt"></i> View Orders
      </a>
      <a href="index.php" class="btn-ghost">
        <i class="fa-solid fa-arrow-left"></i> Back Home
      </a>
    </div>
  </div>
</section>

<div class="content-wrapper">

  <nav class="breadcrumb" aria-label="Breadcrumb">
    <a href="index.php">Home</a> &nbsp;/&nbsp; <strong>Profile</strong>
  </nav>

  <?php if (!empty($flash_messages)): ?>
    <div class="message <?php echo $flash_type === 'error' ? 'error' : ''; ?>" role="status" aria-live="polite" aria-atomic="true">
      <?php if (count($flash_messages) === 1): ?>
        <span><?php echo escapeOutput($flash_messages[0]); ?></span>
      <?php else: ?>
        <ul>
          <?php foreach ($flash_messages as $flash_line): ?>
            <li><?php echo escapeOutput($flash_line); ?></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="profile-wrap" role="region" aria-label="Profile content">
    <!-- Left: Profile Summary Card -->
    <aside class="card" aria-labelledby="profileSummary">
      <div class="profile-summary">
        <img id="previewImage" src="images/<?php echo escapeOutput($profile_img_file); ?>" alt="Your profile image">

        <div id="profileSummary" class="profile-name"><?php echo escapeOutput($user['name']); ?></div>
        <div class="profile-email"><?php echo escapeOutput($user['email']); ?></div>
        <div class="wallet">
          <i class="fa-solid fa-wallet"></i>
          <span>₱<?php echo number_format((float)($user['wallet_balance'] ?? 0), 2); ?></span>
        </div>

        <div class="profile-actions">
          <a href="orders.php" class="btn btn-primary">
            <i class="fa-solid fa-receipt"></i> View Orders
          </a>
        </div>
      </div>
    </aside>

    <!-- Right: Edit Profile Card -->
    <section class="card" aria-labelledby="editProfile">
      <h2 id="editProfile">Edit Profile</h2>
      <form action="" method="post" enctype="multipart/form-data" class="profile-form" novalidate>
        <!-- ✅ CSRF TOKEN HIDDEN FIELD -->
        <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
        
        <div>
          <label for="name">Full Name</label>
          <input id="name" type="text" name="name" value="<?php echo escapeOutput($user['name']); ?>" required aria-label="Full name" autocomplete="name">
        </div>

        <div>
          <label for="email">Email Address</label>
          <input id="email" type="email" name="email" value="<?php echo escapeOutput($user['email']); ?>" required aria-label="Email address" autocomplete="email">
        </div>

        <div>
          <label for="profile_image">Profile Picture</label>
          <input id="profile_image" type="file" name="profile_image" accept="image/*" aria-label="Upload profile image">
          <div class="helper">
            <i class="fa-solid fa-info-circle"></i>
            <span>Choose a square image for best results. Preview updates instantly.</span>
          </div>
        </div>

        <!-- Email 2FA toggle -->
        <div class="checkbox-wrapper">
          <input type="checkbox" id="twofa_checkbox" name="twofa_email_enabled" value="1" <?php echo !empty($user['twofa_email_enabled']) ? 'checked' : ''; ?> aria-label="Enable two-factor authentication">
          <div class="checkbox-label">
            <label for="twofa_checkbox">Require email verification code at login</label>
            <div class="helper">
              <i class="fa-solid fa-shield"></i>
              <span>When enabled, each login will send a one-time code to your email.</span>
            </div>
          </div>
        </div>

        <div class="actions">
          <a href="index.php" class="btn btn-ghost" style="text-decoration: none;">
            <i class="fa-solid fa-arrow-left"></i> Back
          </a>
          <button type="submit" name="update_profile" class="btn btn-primary">
            <i class="fa-solid fa-save"></i> Save Changes
          </button>
        </div>
      </form>

      <div class="security-section">
        <h3>🔐 Security Settings</h3>
        <p>Change your password. We'll verify your current password first for security.</p>
        <button id="togglePassword" type="button" class="toggle-btn" aria-expanded="<?php echo $open_password_panel ? 'true' : 'false'; ?>">
          <?php if ($open_password_panel): ?>
            <i class="fa-solid fa-chevron-up"></i> Hide Change Password
          <?php else: ?>
            <i class="fa-solid fa-key"></i> Change Password
          <?php endif; ?>
        </button>

        <div id="passwordPanel" class="password-panel" aria-hidden="<?php echo $open_password_panel ? 'false' : 'true'; ?>"<?php echo $open_password_panel ? ' style="display:block;"' : ''; ?>>
          <form action="profile.php" method="post" class="password-form" novalidate>
            <!-- ✅ CSRF TOKEN HIDDEN FIELD -->
            <input type="hidden" name="csrf_token" value="<?php echo escapeOutput($_SESSION['csrf_token']); ?>">
            
            <input type="password" name="current_password" placeholder="Current password" required aria-label="Current password" autocomplete="current-password">
            <input type="password" name="new_password" placeholder="New password" required aria-label="New password" autocomplete="new-password">
            <input type="password" name="confirm_password" placeholder="Confirm new password" required aria-label="Confirm new password" autocomplete="new-password">
            <div class="actions" style="margin-top: 12px;">
              <button type="button" id="cancelPassword" class="btn btn-ghost">
                <i class="fa-solid fa-times"></i> Cancel
              </button>
              <button type="submit" name="change_password" class="btn btn-primary">
                <i class="fa-solid fa-check"></i> Update Password
              </button>
            </div>
          </form>
        </div>
      </div>
    </section>
  </div>
</div>

<?php include 'chatbot.php'; ?>
<?php include 'footer.php'; ?>

<script>
  (function(){
    // Toggle password panel
    const togglePassword = document.getElementById('togglePassword');
    const passwordPanel = document.getElementById('passwordPanel');
    const cancelPassword = document.getElementById('cancelPassword');

    togglePassword.addEventListener('click', function(){
      const expanded = this.getAttribute('aria-expanded') === 'true';
      this.setAttribute('aria-expanded', String(!expanded));
      if (!expanded) {
        passwordPanel.style.display = 'block';
        passwordPanel.setAttribute('aria-hidden', 'false');
        this.innerHTML = '<i class="fa-solid fa-chevron-up"></i> Hide Change Password';
      } else {
        passwordPanel.style.display = 'none';
        passwordPanel.setAttribute('aria-hidden', 'true');
        this.innerHTML = '<i class="fa-solid fa-key"></i> Change Password';
      }
    });

    cancelPassword.addEventListener('click', function(){
      passwordPanel.style.display = 'none';
      passwordPanel.setAttribute('aria-hidden', 'true');
      togglePassword.setAttribute('aria-expanded', 'false');
      togglePassword.innerHTML = '<i class="fa-solid fa-key"></i> Change Password';
    });

    // Image preview
    const inputImage = document.getElementById('profile_image');
    const preview = document.getElementById('previewImage');
    if (inputImage && preview) {
      inputImage.addEventListener('change', function(e){
        const file = this.files && this.files[0];
        if (!file) return;
        if (!file.type.startsWith('image/')) return;
        const reader = new FileReader();
        reader.onload = function(ev){
          preview.src = ev.target.result;
        };
        reader.readAsDataURL(file);
      });
    }
  })();
</script>
<script src="Js/script1.js"></script>
</body>
</html>