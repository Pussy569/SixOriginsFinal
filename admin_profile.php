<?php
include 'config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';


if (!isset($_SESSION['admin_id'])) {
   header('location:login.php');
   exit;
}

$admin_id = $_SESSION['admin_id'];
$message = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
   $csrf_token = $_POST['csrf_token'] ?? '';
   if (!is_string($csrf_token) || !validateCSRFToken($csrf_token)) {
      http_response_code(403);
      exit('Security validation failed. Refresh the page and try again.');
   }

   if (isset($_POST['change_password'])) {
      $old_password = (string)($_POST['old_password'] ?? '');
      $new_password = (string)($_POST['new_password'] ?? '');
      $confirm_password = (string)($_POST['confirm_password'] ?? '');

      $stmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
      if (!$stmt) {
         error_log('Could not prepare admin password lookup: ' . $conn->error);
         $message[] = 'Unable to change your password right now.';
      } else {
         $stmt->bind_param('i', $admin_id);
         if (!$stmt->execute()) {
            error_log('Could not execute admin password lookup: ' . $stmt->error);
            $message[] = 'Unable to change your password right now.';
         } else {
            $stmt->bind_result($stored_password);
            $found = $stmt->fetch();
            if (!$found) {
               $message[] = 'Admin account was not found.';
            } else {
               $password_matches = password_verify($old_password, $stored_password)
                  || hash_equals(md5($old_password), $stored_password);

               if (!$password_matches) {
                  log_admin_activity((int)$admin_id, 'Change Admin Password', 'Admin password update failed because the current password was incorrect.', 'failure', 'admin', (int)$admin_id);
                  $message[] = 'Old password is incorrect!';
               } elseif ($new_password !== $confirm_password) {
                  $message[] = 'New passwords do not match!';
               } elseif (strlen($new_password) < 12) {
                  $message[] = 'Use a new password with at least 12 characters.';
               } elseif (strlen($new_password) > 72) {
                  $message[] = 'Use a new password no longer than 72 bytes.';
               } else {
                  $new_hash = password_hash($new_password, PASSWORD_DEFAULT);
                  $update = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                  if (!$update) {
                     error_log('Could not prepare admin password update: ' . $conn->error);
                     $message[] = 'Unable to change your password right now.';
                  } else {
                     $update->bind_param('si', $new_hash, $admin_id);
                     if ($update->execute()) {
                        session_regenerate_id(true);
                        log_admin_activity((int)$admin_id, 'Change Admin Password', 'Admin changed their account password.', 'success', 'admin', (int)$admin_id);
                        $message[] = 'Password changed successfully!';
                     } else {
                        error_log('Could not update admin password: ' . $update->error);
                        log_admin_activity((int)$admin_id, 'Change Admin Password', 'Admin password update failed.', 'failure', 'admin', (int)$admin_id);
                        $message[] = 'Unable to change your password right now.';
                     }
                     $update->close();
                  }
               }
            }
         }
         $stmt->close();
      }
   } elseif (isset($_POST['name'])) {
      $new_name = trim((string)($_POST['name'] ?? ''));
      $new_email = trim((string)($_POST['email'] ?? ''));
      $image_name = null;
      $uploaded_path = null;
      $upload = $_FILES['profile_image'] ?? null;

      if ($new_name === '' || mb_strlen($new_name) > 100 || !filter_var($new_email, FILTER_VALIDATE_EMAIL)) {
         $message[] = 'Enter a valid name and email address.';
      } elseif (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
         if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || ($upload['size'] ?? 0) <= 0
            || $upload['size'] > 2 * 1024 * 1024
            || !is_uploaded_file($upload['tmp_name'] ?? '')) {
            $message[] = 'Profile image must be a valid image no larger than 2 MB.';
         } else {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = $finfo ? finfo_file($finfo, $upload['tmp_name']) : false;
            if ($finfo) {
               finfo_close($finfo);
            }
            $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            if (!is_string($mime) || !isset($extensions[$mime]) || @getimagesize($upload['tmp_name']) === false) {
               $message[] = 'Upload a valid JPG, PNG, or WebP profile image.';
            } else {
               $stored_image_name = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
               $upload_dir = __DIR__ . '/images/admin_uploads';
               if (!is_dir($upload_dir) && !mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
                  error_log('Could not create admin profile image directory.');
                  $message[] = 'Profile image could not be saved.';
               } else {
                  $uploaded_path = $upload_dir . '/' . $stored_image_name;
                  if (!move_uploaded_file($upload['tmp_name'], $uploaded_path)) {
                     error_log('Could not save validated admin profile image.');
                     $message[] = 'Profile image could not be saved.';
                     $uploaded_path = null;
                  } else {
                     $image_name = 'admin_uploads/' . $stored_image_name;
                  }
               }
            }
         }
      }

      if (!$message) {
         if ($image_name !== null) {
            $stmt = $conn->prepare('UPDATE users SET name = ?, email = ?, profile_image = ? WHERE id = ?');
            if ($stmt) {
               $stmt->bind_param('sssi', $new_name, $new_email, $image_name, $admin_id);
            }
         } else {
            $stmt = $conn->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
            if ($stmt) {
               $stmt->bind_param('ssi', $new_name, $new_email, $admin_id);
            }
         }

         if (!$stmt) {
            if ($uploaded_path !== null) {
               @unlink($uploaded_path);
            }
            error_log('Could not prepare admin profile update: ' . $conn->error);
            $message[] = 'Unable to update your profile right now.';
         } elseif ($stmt->execute()) {
            $previous_admin_name = $_SESSION['admin_name'] ?? '';
            $previous_admin_email = $_SESSION['admin_email'] ?? '';
            $_SESSION['admin_email'] = $new_email;
            $_SESSION['admin_name'] = $new_name;
            log_admin_activity((int)$admin_id, 'Update Admin Profile', 'Updated admin profile details.', 'success', 'admin', (int)$admin_id, ['name' => $previous_admin_name, 'email' => $previous_admin_email], ['name' => $new_name, 'email' => $new_email]);
            $message[] = 'Profile updated successfully!';
            $stmt->close();
         } else {
            if ($uploaded_path !== null) {
               @unlink($uploaded_path);
            }
            error_log('Could not update admin profile: ' . $stmt->error);
            $message[] = 'Unable to update your profile right now.';
            $stmt->close();
         }
      } elseif ($uploaded_path !== null) {
         @unlink($uploaded_path);
      }
   }
}

$stmt = $conn->prepare('SELECT * FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
   error_log('Could not prepare admin profile lookup: ' . $conn->error);
   http_response_code(500);
   exit('Unable to load your profile.');
}
$stmt->bind_param('i', $admin_id);
if (!$stmt->execute()) {
   error_log('Could not execute admin profile lookup: ' . $stmt->error);
   http_response_code(500);
   exit('Unable to load your profile.');
}
$admin = $stmt->get_result()->fetch_assoc();
$stmt->close();
if (!$admin) {
   http_response_code(404);
   exit('Admin account was not found.');
}

// View-only helper: keep the password panel open after a password submit
$show_password_panel = isset($_POST['change_password']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
   <title>Admin Profile | Six Origins Cafe</title>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="icon" type="image/png" href="images/logos.png">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <style>
      :root{
         --primary-red: #C6453E;
         --red-deep: #B83A34;
         --dark-brown: #5E1F13;
         --gray-brown: #664C47;
         --light-cream: #FFF2E0;
         --white: #FFFFFF;
         --line: #F0E6D8;
         --radius: 18px;
         --radius-sm: 12px;
         --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
         --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
         --transition: all 0.25s cubic-bezier(0.4, 0, 0.2, 1);
      }

      *{
         box-sizing: border-box;
         margin: 0;
         padding: 0;
         font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif;
      }

      html{
         -webkit-text-size-adjust: 100%;
         scroll-padding-top: 16px;
      }

      html, body{
         width: 100%;
         overflow-x: hidden;
      }

      body{
         min-height: 100vh;
         min-height: 100dvh;
         background: linear-gradient(135deg, #FFFBF7 0%, var(--light-cream) 100%);
         background-attachment: fixed;
         color: var(--dark-brown);
      }

      :focus-visible{
         outline: 3px solid rgba(198, 69, 62, 0.45);
         outline-offset: 2px;
      }

      .page{
         max-width: 1100px;
         margin: 0 auto;
         width: 100%;
         padding: clamp(14px, 3vw, 32px) clamp(12px, 3vw, 20px) calc(48px + env(safe-area-inset-bottom, 0px));
      }

      /* ---------- Header ---------- */
      .header-row{
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 14px 20px;
         flex-wrap: wrap;
         margin-bottom: clamp(16px, 3vw, 28px);
      }

      .header-row .title{
         font-size: clamp(1.35rem, 4.5vw, 2rem);
         font-weight: 900;
         letter-spacing: -0.5px;
         line-height: 1.15;
      }

      .header-row .subtitle{
         margin-top: 4px;
         color: var(--gray-brown);
         font-size: clamp(0.85rem, 2.4vw, 1rem);
         font-weight: 500;
      }

      /* ---------- Layout ---------- */
      .grid{
         display: grid;
         grid-template-columns: minmax(0, 330px) minmax(0, 1fr);
         gap: clamp(16px, 3vw, 28px);
         align-items: start;
      }

      .card{
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         padding: clamp(18px, 3.5vw, 32px);
         box-shadow: var(--shadow);
         border: 1.5px solid var(--line);
         transition: var(--transition);
         min-width: 0;
      }

      @media (hover: hover) {
         .card:hover{
            box-shadow: var(--shadow-hover);
            border-color: var(--primary-red);
         }
      }

      /* ---------- Profile card ---------- */
      .profile-card{
         text-align: center;
         position: sticky;
         top: 16px;
      }

      .avatar{
         width: 140px;
         height: 140px;
         border-radius: var(--radius);
         object-fit: cover;
         border: 3px solid var(--line);
         box-shadow: 0 6px 18px rgba(94, 31, 19, 0.15);
         margin: 0 auto 16px;
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
         display: block;
      }

      .profile-name{
         font-size: 1.3rem;
         font-weight: 900;
         margin-bottom: 4px;
         overflow-wrap: anywhere;
      }

      .profile-email{
         color: var(--gray-brown);
         font-size: 0.92rem;
         font-weight: 500;
         overflow-wrap: anywhere;
      }

      .profile-actions{
         display: grid;
         gap: 10px;
         margin: 20px 0 16px;
      }

      .profile-stats{
         display: flex;
         gap: 8px;
         justify-content: center;
         flex-wrap: wrap;
      }

      .stat{
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
         padding: 8px 12px;
         border-radius: 10px;
         font-weight: 700;
         border: 1.5px solid var(--line);
         font-size: 0.85rem;
      }

      /* ---------- Forms ---------- */
      .form h3{
         margin-bottom: 20px;
         font-size: clamp(1.05rem, 3.4vw, 1.3rem);
         font-weight: 900;
         letter-spacing: -0.3px;
         display: flex;
         align-items: center;
         gap: 10px;
      }

      .form h3 i{
         color: var(--primary-red);
         font-size: 1.25em;
      }

      .field{
         margin-bottom: 18px;
      }

      label{
         display: flex;
         align-items: center;
         gap: 8px;
         font-size: 0.92rem;
         font-weight: 700;
         margin-bottom: 8px;
      }

      label i{
         color: var(--primary-red);
         width: 1.1em;
         text-align: center;
      }

      .input{
         display: flex;
         align-items: center;
         gap: 10px;
         min-height: 50px;
         padding: 0 14px;
         border-radius: var(--radius-sm);
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
         border: 1.5px solid var(--line);
         transition: var(--transition);
      }

      .input:focus-within{
         border-color: var(--primary-red);
         box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.12);
      }

      /* 16px minimum stops iOS Safari from zooming on focus */
      .input input[type="text"],
      .input input[type="email"],
      .input input[type="password"]{
         border: 0;
         outline: 0;
         background: transparent;
         width: 100%;
         min-width: 0;
         height: 48px;
         font-size: 16px;
         color: var(--dark-brown);
         font-weight: 500;
      }

      .input input::placeholder{ color: var(--gray-brown); }

      .pw-eye{
         flex: none;
         width: 40px;
         height: 40px;
         margin-right: -8px;
         border: 0;
         border-radius: 10px;
         background: transparent;
         color: var(--gray-brown);
         cursor: pointer;
         font-size: 1rem;
      }

      .pw-eye:hover{ color: var(--primary-red); }

      /* File picker */
      .file-drop{
         display: flex;
         align-items: center;
         gap: 14px;
         padding: 12px 14px;
         border: 1.5px dashed #E3D2BC;
         border-radius: var(--radius-sm);
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
         cursor: pointer;
         transition: var(--transition);
      }

      .file-drop:hover,
      .file-drop:focus-within{
         border-color: var(--primary-red);
      }

      .file-drop input[type="file"]{
         position: absolute;
         width: 1px;
         height: 1px;
         opacity: 0;
         overflow: hidden;
      }

      .file-drop .file-icon{
         flex: none;
         width: 44px;
         height: 44px;
         border-radius: 12px;
         display: grid;
         place-items: center;
         background: var(--light-cream);
         color: var(--primary-red);
         font-size: 1.2rem;
      }

      .file-drop .file-text{
         min-width: 0;
         display: grid;
         gap: 2px;
      }

      .file-drop .file-title{ font-weight: 700; font-size: 0.92rem; }

      .file-drop .file-name{
         color: var(--gray-brown);
         font-size: 0.8rem;
         font-weight: 500;
         white-space: nowrap;
         overflow: hidden;
         text-overflow: ellipsis;
      }

      /* ---------- Buttons ---------- */
      .btn{
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
         min-height: 48px;
         padding: 0 18px;
         border-radius: var(--radius-sm);
         border: 1.5px solid transparent;
         cursor: pointer;
         font-weight: 800;
         font-size: 0.95rem;
         text-decoration: none;
         transition: var(--transition);
         -webkit-tap-highlight-color: transparent;
      }

      .btn:active{ transform: scale(0.98); }

      .btn-primary{
         background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
         color: #fff;
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.22);
      }

      .btn-ghost{
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         border-color: var(--line);
         color: var(--dark-brown);
      }

      @media (hover: hover) {
         .btn-primary:hover{
            background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
            transform: translateY(-2px);
            box-shadow: 0 6px 18px rgba(94, 31, 19, 0.22);
         }
         .btn-ghost:hover{
            background: linear-gradient(135deg, var(--primary-red) 0%, var(--red-deep) 100%);
            color: #fff;
            border-color: var(--primary-red);
            transform: translateY(-2px);
         }
      }

      .form-actions{
         display: flex;
         gap: 12px;
         margin-top: 24px;
      }

      .form-actions .btn{ flex: 1; }

      /* ---------- Messages ---------- */
      .messages{ margin-bottom: 18px; }

      .messages .msg{
         display: flex;
         align-items: flex-start;
         gap: 10px;
         padding: 14px 16px;
         border-radius: var(--radius-sm);
         margin-bottom: 10px;
         font-weight: 700;
         font-size: 0.92rem;
         line-height: 1.4;
         background: rgba(94, 31, 19, 0.06);
         border-left: 5px solid var(--dark-brown);
         color: var(--dark-brown);
         animation: pop 0.3s ease;
      }

      .messages .msg.error{
         background: rgba(198, 69, 62, 0.1);
         border-left-color: var(--primary-red);
         color: var(--primary-red);
      }

      .messages .msg i{ margin-top: 2px; flex: none; }

      @keyframes pop{
         from{ opacity: 0; transform: translateY(-6px); }
         to{ opacity: 1; transform: none; }
      }

      /* ---------- Panels ---------- */
      .panel[hidden]{ display: none; }
      .panel{ animation: pop 0.25s ease; }

      .muted{
         color: var(--gray-brown);
         font-weight: 500;
      }

      /* ---------- Responsive ---------- */
      @media (max-width: 900px){
         .grid{ grid-template-columns: minmax(0, 1fr); }

         /* profile card becomes a compact horizontal summary */
         .profile-card{
            position: static;
            display: grid;
            grid-template-columns: auto minmax(0, 1fr);
            grid-template-areas:
               "avatar info"
               "actions actions"
               "stats stats";
            column-gap: 16px;
            align-items: center;
            text-align: left;
         }

         .avatar{
            grid-area: avatar;
            width: 88px;
            height: 88px;
            margin: 0;
         }

         .profile-info{ grid-area: info; min-width: 0; }
         .profile-actions{ grid-area: actions; grid-template-columns: 1fr 1fr; margin: 18px 0 0; }
         .profile-stats{ grid-area: stats; justify-content: flex-start; margin-top: 14px; }
      }

      @media (max-width: 560px){
         .header-row{ flex-direction: column; align-items: stretch; }
         .header-row .btn{ width: 100%; }

         .avatar{ width: 72px; height: 72px; border-radius: 14px; }
         .profile-name{ font-size: 1.1rem; }
         .profile-email{ font-size: 0.85rem; }

         /* tab-style buttons: icon over label so both fit on small phones */
         .profile-actions .btn{
            flex-direction: column;
            gap: 4px;
            min-height: 56px;
            padding: 8px;
            font-size: 0.82rem;
         }

         .form-actions{ flex-direction: column-reverse; }
         .form-actions .btn{ width: 100%; }
      }

      @media (max-width: 360px){
         .card{ padding: 14px; }
         .stat{ font-size: 0.78rem; padding: 6px 9px; }
      }

      @media (prefers-reduced-motion: reduce){
         *{ animation: none !important; transition: none !important; }
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="page">
   <div class="header-row">
      <div>
         <div class="title">Edit Admin Profile</div>
         <div class="subtitle muted">Update your profile details and change password</div>
      </div>
      <div>
         <a href="admin_page.php" class="btn btn-ghost" title="Back to dashboard">
            <i class="fa-solid fa-arrow-left"></i> Back to Dashboard
         </a>
      </div>
   </div>

   <?php if (!empty($message)): ?>
      <div class="messages" role="status" aria-live="polite">
         <?php foreach ($message as $msg):
            $is_error = (stripos($msg, 'incorrect') !== false || stripos($msg, 'not match') !== false);
         ?>
            <div class="msg <?php echo $is_error ? 'error' : ''; ?>">
               <i class="fa-solid <?php echo $is_error ? 'fa-circle-exclamation' : 'fa-circle-check'; ?>"></i>
               <span><?php echo htmlspecialchars($msg); ?></span>
            </div>
         <?php endforeach; ?>
      </div>
   <?php endif; ?>

   <div class="grid">
      <aside class="card profile-card" aria-label="Profile summary">
         <img src="images/<?php echo htmlspecialchars($admin['profile_image'] ?? 'no_profile.jpg', ENT_QUOTES, 'UTF-8'); ?>" alt="Profile Image" class="avatar" id="currentAvatar" loading="lazy">

         <div class="profile-info">
            <div class="profile-name"><?php echo htmlspecialchars($admin['name'] ?? 'Admin'); ?></div>
            <div class="profile-email"><?php echo htmlspecialchars($admin['email'] ?? ''); ?></div>
         </div>

         <div class="profile-actions" role="tablist" aria-label="Profile sections">
            <button type="button" id="editToggle" class="btn <?php echo $show_password_panel ? 'btn-ghost' : 'btn-primary'; ?>" role="tab" aria-controls="editProfileSection" aria-selected="<?php echo $show_password_panel ? 'false' : 'true'; ?>" title="Edit profile">
               <i class="fa-solid fa-user-pen"></i> Edit Profile
            </button>
            <button type="button" id="pwToggle" class="btn <?php echo $show_password_panel ? 'btn-primary' : 'btn-ghost'; ?>" role="tab" aria-controls="passwordSection" aria-selected="<?php echo $show_password_panel ? 'true' : 'false'; ?>" title="Change password">
               <i class="fa-solid fa-key"></i> Change Password
            </button>
         </div>

         <div class="profile-stats" aria-label="Profile information">
            <div class="stat"><strong>ID:</strong> <?php echo intval($admin['id']); ?></div>
            <div class="stat"><strong>Role:</strong> <?php echo htmlspecialchars($admin['user_type'] ?? 'Admin'); ?></div>
         </div>
      </aside>

      <main class="card" id="mainPanel">
         <section id="editProfileSection" class="form panel" role="tabpanel" aria-labelledby="editProfileTitle" <?php echo $show_password_panel ? 'hidden' : ''; ?>>
            <h3 id="editProfileTitle"><i class="fa-solid fa-user-pen"></i> Update Profile Details</h3>
            <form action="" method="post" enctype="multipart/form-data" id="profileForm" novalidate>
               <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
               <div class="field">
                  <label for="profile_image"><i class="fa-solid fa-image"></i> Profile Image (Optional)</label>
                  <label class="file-drop" for="profile_image">
                     <span class="file-icon"><i class="fa-solid fa-camera"></i></span>
                     <span class="file-text">
                        <span class="file-title">Choose a new photo</span>
                        <span class="file-name" id="fileName">No file selected</span>
                     </span>
                     <input id="profile_image" name="profile_image" type="file" accept="image/*" title="Select a profile image">
                  </label>
               </div>

               <div class="field">
                  <label for="name"><i class="fa-solid fa-user"></i> Full Name</label>
                  <div class="input">
                     <input id="name" name="name" type="text" value="<?php echo htmlspecialchars($admin['name']); ?>" required minlength="2" maxlength="100" autocomplete="name">
                  </div>
               </div>

               <div class="field">
                  <label for="email"><i class="fa-solid fa-envelope"></i> Email</label>
                  <div class="input">
                     <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($admin['email']); ?>" required autocomplete="email" inputmode="email">
                  </div>
               </div>

               <div class="form-actions">
                  <button type="submit" class="btn btn-primary">
                     <i class="fa-solid fa-check-circle"></i> Save Changes
                  </button>
                  <button type="button" id="cancelEdit" class="btn btn-ghost">
                     <i class="fa-solid fa-times"></i> Cancel
                  </button>
               </div>
            </form>
         </section>

         <section id="passwordSection" class="form panel" role="tabpanel" aria-labelledby="passwordTitle" <?php echo $show_password_panel ? '' : 'hidden'; ?>>
            <h3 id="passwordTitle"><i class="fa-solid fa-key"></i> Change Password</h3>
            <form action="" method="post" id="passwordForm" novalidate>
               <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8'); ?>">
               <div class="field">
                  <label for="old_password"><i class="fa-solid fa-lock"></i> Current Password</label>
                  <div class="input">
                     <input id="old_password" name="old_password" type="password" required minlength="6" autocomplete="current-password">
                     <button type="button" class="pw-eye" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                  </div>
               </div>

               <div class="field">
                  <label for="new_password"><i class="fa-solid fa-lock-open"></i> New Password</label>
                  <div class="input">
                     <input id="new_password" name="new_password" type="password" required minlength="12" autocomplete="new-password">
                     <button type="button" class="pw-eye" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                  </div>
               </div>

               <div class="field">
                  <label for="confirm_password"><i class="fa-solid fa-lock-open"></i> Confirm New Password</label>
                  <div class="input">
                     <input id="confirm_password" name="confirm_password" type="password" required minlength="12" autocomplete="new-password">
                     <button type="button" class="pw-eye" aria-label="Show password"><i class="fa-solid fa-eye"></i></button>
                  </div>
               </div>

               <div class="form-actions">
                  <button type="submit" name="change_password" class="btn btn-primary">
                     <i class="fa-solid fa-check-circle"></i> Change Password
                  </button>
                  <button type="button" id="cancelPw" class="btn btn-ghost">
                     <i class="fa-solid fa-times"></i> Cancel
                  </button>
               </div>
            </form>
         </section>
      </main>
   </div>
</div>

<script>
   (function(){
      const editToggle = document.getElementById('editToggle');
      const pwToggle = document.getElementById('pwToggle');
      const editSection = document.getElementById('editProfileSection');
      const passwordSection = document.getElementById('passwordSection');
      const mainPanel = document.getElementById('mainPanel');
      const cancelEdit = document.getElementById('cancelEdit');
      const cancelPw = document.getElementById('cancelPw');
      const profileInput = document.getElementById('profile_image');
      const currentAvatar = document.getElementById('currentAvatar');
      const fileName = document.getElementById('fileName');
      const originalAvatar = currentAvatar.getAttribute('src');

      // Switch between the two panels, keeping the toggle buttons in sync
      function showPanel(which, focusFirst) {
         const isPw = which === 'password';
         editSection.hidden = isPw;
         passwordSection.hidden = !isPw;

         editToggle.classList.toggle('btn-primary', !isPw);
         editToggle.classList.toggle('btn-ghost', isPw);
         pwToggle.classList.toggle('btn-primary', isPw);
         pwToggle.classList.toggle('btn-ghost', !isPw);
         editToggle.setAttribute('aria-selected', String(!isPw));
         pwToggle.setAttribute('aria-selected', String(isPw));

         // On stacked (mobile) layouts the form sits below the summary, so bring it into view
         if (window.matchMedia('(max-width: 900px)').matches) {
            mainPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
         }
         if (focusFirst) {
            const target = (isPw ? passwordSection : editSection).querySelector('input:not([type="file"])');
            if (target) setTimeout(() => target.focus({preventScroll: true}), 250);
         }
      }

      editToggle.addEventListener('click', () => showPanel('edit', true));
      pwToggle.addEventListener('click', () => showPanel('password', true));

      cancelEdit.addEventListener('click', () => {
         document.getElementById('profileForm').reset();
         currentAvatar.src = originalAvatar;
         fileName.textContent = 'No file selected';
         window.scrollTo({top: 0, behavior: 'smooth'});
      });

      cancelPw.addEventListener('click', () => {
         document.getElementById('passwordForm').reset();
         document.querySelectorAll('.pw-eye').forEach(b => resetEye(b));
         showPanel('edit', false);
      });

      // Show / hide password
      function resetEye(btn) {
         const input = btn.parentElement.querySelector('input');
         input.type = 'password';
         btn.innerHTML = '<i class="fa-solid fa-eye"></i>';
         btn.setAttribute('aria-label', 'Show password');
      }

      document.querySelectorAll('.pw-eye').forEach(btn => {
         btn.addEventListener('click', () => {
            const input = btn.parentElement.querySelector('input');
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.innerHTML = show ? '<i class="fa-solid fa-eye-slash"></i>' : '<i class="fa-solid fa-eye"></i>';
            btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
         });
      });

      // Preview selected avatar locally (improves UX, does not change server logic)
      if (profileInput) {
         profileInput.addEventListener('change', (e) => {
            const file = e.target.files[0];
            if (!file) {
               fileName.textContent = 'No file selected';
               return;
            }
            if (!file.type.startsWith('image/')) {
               alert('Please select a valid image file');
               e.target.value = '';
               fileName.textContent = 'No file selected';
               return;
            }
            fileName.textContent = file.name;
            const reader = new FileReader();
            reader.onload = function(ev){
               currentAvatar.src = ev.target.result;
            };
            reader.readAsDataURL(file);
         });
      }
   })();
</script>
<?php include 'admin_footer.php'; ?>
</body>
</html>