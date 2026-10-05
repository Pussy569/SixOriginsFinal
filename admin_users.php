<?php
include 'config.php';
require_once 'mail_helper.php';

$admin_id = $_SESSION['admin_id'] ?? null;

if (!$admin_id) {
   header('location:login.php');
   exit;
}

/**
 * Approve a user and email them (only if they weren't already approved).
 * $allowed_types = null  -> any user type
 * $allowed_types = [...] -> only those user types
 */
function approve_and_notify($conn, $user_id, $allowed_types = null) {
   $res = mysqli_query($conn, "SELECT name, email, user_type, status FROM `users` WHERE id = $user_id LIMIT 1");
   if (!$res || mysqli_num_rows($res) != 1) {
      return;
   }
   $u = mysqli_fetch_assoc($res);

   if ($allowed_types !== null && !in_array($u['user_type'], $allowed_types)) {
      return;
   }

   $was_approved = ($u['status'] === 'approved');

   mysqli_query($conn, "UPDATE `users` SET `status` = 'approved' WHERE id = $user_id") or die('query failed');

   // Send the email only on a real status change (prevents duplicate emails)
   if (!$was_approved) {
      $sent = send_approval_email($u['name'], $u['email'], $u['user_type']);
      $_SESSION['approve_message'] = $sent
         ? '✅ User approved and notification email sent!'
         : '⚠️ User approved, but the notification email could not be sent.';
   } else {
      $_SESSION['approve_message'] = '⚠️ This user was already approved.';
   }
}

// --- Unlock user account (resets failed_attempts AND lockout_until) ---
if (isset($_GET['unlock_account'])) {
   $user_id = intval($_GET['unlock_account']);
   $result = mysqli_query($conn, "UPDATE `users` SET `failed_attempts` = 0, `lockout_until` = NULL WHERE id = $user_id");
   if ($result) {
      $_SESSION['unlock_message'] = '✅ Account unlocked successfully!';
   } else {
      $_SESSION['unlock_message'] = '❌ Failed to unlock account!';
   }
   header('location:admin_users.php');
   exit;
}

// --- Approve any user (for Head Admin) + email notification ---
if (isset($_GET['approve_user'])) {
   $user_id = intval($_GET['approve_user']);
   approve_and_notify($conn, $user_id);
   header('location:admin_users.php');
   exit;
}

// --- Reject any user (for Head Admin) ---
if (isset($_GET['reject_user'])) {
   $user_id = intval($_GET['reject_user']);
   $res = mysqli_query($conn, "SELECT verification_image FROM `users` WHERE id = '$user_id' LIMIT 1");
   if ($res && mysqli_num_rows($res) == 1) {
       $user = mysqli_fetch_assoc($res);
       if (!empty($user['verification_image'])) {
           $file = 'uploads/' . $user['verification_image'];
           if (file_exists($file)) {
               unlink($file);
           }
       }
   }
   mysqli_query($conn, "UPDATE `users` SET `status` = 'rejected', verification_image = NULL WHERE id = '$user_id'") or die('query failed');
   header('location:admin_users.php');
   exit;
}

// Handle deleting user
if (isset($_GET['delete'])) {
   $delete_id = intval($_GET['delete']);
   mysqli_query($conn, "DELETE FROM `users` WHERE id = '$delete_id'") or die('query failed');
   header('location:admin_users.php');
   exit;
}

// --- Approve verification (Senior/PWD) + email notification ---
if (isset($_GET['approve_verification'])) {
   $user_id = intval($_GET['approve_verification']);
   approve_and_notify($conn, $user_id, ['Senior', 'PWD']);
   header('location:admin_users.php');
   exit;
}

if (isset($_GET['reject_verification'])) {
   $user_id = intval($_GET['reject_verification']);
   $res = mysqli_query($conn, "SELECT verification_image, user_type FROM `users` WHERE id = '$user_id' LIMIT 1");
   if ($res && mysqli_num_rows($res) == 1) {
       $user = mysqli_fetch_assoc($res);
       if (in_array($user['user_type'], ['Senior', 'PWD'])) {
           if (!empty($user['verification_image'])) {
               $file = 'uploads/' . $user['verification_image'];
               if (file_exists($file)) {
                   unlink($file);
               }
           }
           mysqli_query($conn, "UPDATE `users` SET `status` = 'rejected', verification_image = NULL WHERE id = '$user_id'") or die('query failed');
       }
   }
   header('location:admin_users.php');
   exit;
}

// Assign discount to user (IMPROVED)
if (isset($_POST['assign_discount'])) {
   $user_id = intval($_POST['user_id']);
   $discount_id = intval($_POST['discount_id']);

   // Check if the user already has this discount
   $check = mysqli_query($conn, "SELECT id FROM user_discounts WHERE user_id = $user_id AND discount_id = $discount_id LIMIT 1");
   if (mysqli_num_rows($check) == 0) {
      // Only assign active & valid discounts
      $discount_ok = mysqli_query($conn, "SELECT id FROM discounts WHERE id = $discount_id AND status = 'active' AND valid_until >= CURDATE() LIMIT 1");
      if (mysqli_num_rows($discount_ok) > 0) {
         $assign_result = mysqli_query($conn, "INSERT INTO user_discounts (user_id, discount_id, used) VALUES ($user_id, $discount_id, 0)");
         if ($assign_result) {
            $_SESSION['assign_message'] = '✅ Discount assigned successfully!';
         } else {
            $_SESSION['assign_message'] = '❌ Error assigning discount! Please try again.';
         }
      } else {
         $_SESSION['assign_message'] = '❌ Selected discount is invalid, inactive, or expired!';
      }
   } else {
      $_SESSION['assign_message'] = '⚠️ This discount is already assigned to this user!';
   }
   header('location:admin_users.php');
   exit;
}

// Remove discount from user
if (isset($_GET['remove_discount'])) {
   $remove_id = intval($_GET['remove_discount']);
   $result = mysqli_query($conn, "DELETE FROM user_discounts WHERE id = $remove_id");
   if ($result) {
      $_SESSION['remove_message'] = '✅ Discount removed successfully!';
   } else {
      $_SESSION['remove_message'] = '❌ Failed to remove discount!';
   }
   header('location:admin_users.php');
   exit;
}

$discounts_result = mysqli_query($conn, "SELECT * FROM discounts WHERE status = 'active' AND valid_until >= CURDATE() ORDER BY amount DESC");
$discounts = [];
while ($row = mysqli_fetch_assoc($discounts_result)) {
   $discounts[] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>Manage Users — Six Origins Admin</title>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
   <link rel="icon" type="image/png" href="images/logos.png">
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
      }

      * {
         box-sizing: border-box;
         margin: 0;
         padding: 0;
         font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      }

      html {
         -webkit-text-size-adjust: 100%;
      }

      body {
         min-height: 100vh;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         color: var(--dark-brown);
         overflow-x: hidden;
      }

      .page {
         max-width: 1400px;
         margin: clamp(16px, 3vw, 32px) auto;
         padding: 0 clamp(12px, 2.5vw, 18px) 60px;
         width: 100%;
      }

      /* ---------- Header ---------- */
      .header {
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 16px;
         flex-wrap: wrap;
         margin-bottom: clamp(18px, 3vw, 32px);
         animation: slideDown 0.4s ease;
      }

      @keyframes slideDown {
         from { opacity: 0; transform: translateY(-20px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      @keyframes slideUp {
         from { opacity: 0; transform: translateY(20px); }
         to   { opacity: 1; transform: translateY(0); }
      }

      .header h1 {
         font-size: clamp(1.4rem, 3.4vw, 2rem);
         font-weight: 900;
         color: var(--dark-brown);
         letter-spacing: -0.5px;
         line-height: 1.15;
      }

      .header .sub {
         color: var(--gray-brown);
         font-size: clamp(0.88rem, 1.8vw, 1rem);
         margin-top: 6px;
         font-weight: 500;
      }

      .header .actions {
         display: flex;
         gap: 10px;
         align-items: center;
      }

      /* ---------- Buttons ---------- */
      .btn {
         display: inline-flex;
         align-items: center;
         justify-content: center;
         gap: 8px;
         padding: 12px 20px;
         border-radius: var(--radius);
         font-weight: 800;
         text-decoration: none;
         border: none;
         cursor: pointer;
         font-size: 0.98rem;
         font-family: inherit;
         white-space: nowrap;
         transition: var(--transition);
      }

      .btn.refresh {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
      }

      .btn.refresh:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         transform: translateY(-2px);
         box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
      }

      .btn.small {
         padding: 8px 14px;
         font-size: 0.9rem;
      }

      .btn.delete {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.1) 0%, rgba(217, 126, 106, 0.05) 100%);
         color: #D97E6A;
         border: 1.5px solid rgba(217, 126, 106, 0.2);
      }

      .btn.delete:hover {
         background: linear-gradient(135deg, #D97E6A 0%, #C25A52 100%);
         color: #fff;
         border-color: #D97E6A;
      }

      .btn.unlock {
         background: linear-gradient(135deg, rgba(76, 175, 80, 0.1) 0%, rgba(76, 175, 80, 0.05) 100%);
         color: #4CAF50;
         border: 1.5px solid rgba(76, 175, 80, 0.2);
      }

      .btn.unlock:hover {
         background: linear-gradient(135deg, #4CAF50 0%, #388E3C 100%);
         color: #fff;
         border-color: #4CAF50;
         transform: translateY(-2px);
      }

      .btn.approve {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(198, 69, 62, 0.2);
      }

      .btn.approve:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         transform: translateY(-2px);
      }

      .btn.reject {
         background: linear-gradient(135deg, rgba(160, 130, 109, 0.1) 0%, rgba(160, 130, 109, 0.05) 100%);
         color: var(--gray-brown);
         border: 1.5px solid rgba(160, 130, 109, 0.2);
      }

      .btn.reject:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         color: #fff;
         border-color: var(--dark-brown);
      }

      .btn.view {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         box-shadow: 0 2px 6px rgba(198, 69, 62, 0.15);
      }

      .btn.view:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      }

      /* ---------- Panel ---------- */
      .panel {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: var(--radius);
         padding: clamp(14px, 2.4vw, 24px);
         box-shadow: var(--shadow);
         border: 1.5px solid #F0E6D8;
         transition: var(--transition);
         animation: slideUp 0.4s ease;
      }

      .panel:hover {
         box-shadow: var(--shadow-hover);
         border-color: var(--primary-red);
      }

      .muted {
         color: var(--gray-brown);
         font-size: 0.95rem;
         font-weight: 500;
      }

      /* ---------- Alerts ---------- */
      .alert {
         padding: 16px 18px;
         border-radius: var(--radius);
         margin-bottom: 24px;
         display: flex;
         gap: 12px;
         align-items: flex-start;
         box-shadow: 0 6px 16px rgba(94, 31, 19, 0.1);
         animation: slideDown 0.4s ease-out;
         font-weight: 600;
         font-size: 0.95rem;
         border: 1px solid transparent;
      }

      .alert i {
         font-size: 1.1rem;
         flex-shrink: 0;
         margin-top: 2px;
      }

      .alert.success {
         background: linear-gradient(135deg, rgba(76, 175, 80, 0.12) 0%, rgba(76, 175, 80, 0.06) 100%);
         color: #2E7D32;
         border-color: rgba(76, 175, 80, 0.2);
      }

      .alert.warning {
         background: linear-gradient(135deg, rgba(255, 193, 7, 0.12) 0%, rgba(255, 193, 7, 0.06) 100%);
         color: #F57F17;
         border-color: rgba(255, 193, 7, 0.2);
      }

      /* ---------- Table (desktop) ---------- */
      .table-wrapper {
         overflow: auto;
         max-height: 78vh;
         border-radius: 12px;
         -webkit-overflow-scrolling: touch;
      }

      table {
         width: 100%;
         min-width: 1240px;
         border-collapse: collapse;
         font-size: 0.95rem;
      }

      thead th {
         text-align: left;
         padding: 14px 16px;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         font-weight: 800;
         position: sticky;
         top: 0;
         z-index: 10;
         letter-spacing: 0.3px;
         text-transform: uppercase;
         font-size: 0.82rem;
         white-space: nowrap;
         box-shadow: 0 2px 8px rgba(198, 69, 62, 0.2);
      }

      thead th:first-child { border-top-left-radius: 12px; }
      thead th:last-child  { border-top-right-radius: 12px; }

      tbody td {
         padding: 14px 16px;
         border-bottom: 1px solid #F0E6D8;
         vertical-align: top;
         color: var(--dark-brown);
      }

      tbody tr {
         transition: var(--transition);
      }

      tbody tr:hover {
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         box-shadow: inset 0 2px 8px rgba(198, 69, 62, 0.04);
      }

      tbody tr:last-child td {
         border-bottom: none;
      }

      /* Column sizing so nothing gets squeezed on desktop */
      td.cell-profile  { min-width: 130px; }
      td.cell-email    { min-width: 170px; overflow-wrap: anywhere; }
      td.cell-type,
      td.cell-wallet,
      td.cell-status   { white-space: nowrap; }
      td.cell-verify   { min-width: 170px; }
      td.cell-discounts{ min-width: 210px; }
      td.cell-assign   { min-width: 160px; }
      td.cell-actions  { min-width: 150px; }

      /* Profile Image */
      .profile-img {
         width: 56px;
         height: 56px;
         border-radius: 10px;
         object-fit: cover;
         border: 2px solid #F0E6D8;
         box-shadow: 0 2px 6px rgba(94, 31, 19, 0.1);
         display: block;
         margin-bottom: 8px;
         flex-shrink: 0;
      }

      .profile-name {
         font-weight: 700;
         color: var(--dark-brown);
         font-size: 0.95rem;
         overflow-wrap: anywhere;
      }

      /* Verification Image */
      .verification-img {
         max-width: 160px;
         width: 100%;
         height: auto;
         border-radius: 10px;
         display: block;
         margin-bottom: 8px;
         border: 2px solid #F0E6D8;
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
         box-shadow: 0 2px 8px rgba(94, 31, 19, 0.08);
      }

      /* Lockout Status */
      .lockout-status {
         background: linear-gradient(135deg, rgba(217, 126, 106, 0.1) 0%, rgba(217, 126, 106, 0.05) 100%);
         border: 1.5px solid rgba(217, 126, 106, 0.2);
         padding: 10px 12px;
         border-radius: 8px;
         color: #D97E6A;
         font-weight: 700;
         font-size: 0.9rem;
         text-align: center;
         display: flex;
         align-items: center;
         gap: 6px;
         justify-content: center;
         white-space: nowrap;
      }

      /* Badges */
      .badge {
         display: inline-block;
         padding: 8px 14px;
         border-radius: 10px;
         font-weight: 800;
         font-size: 0.85rem;
         text-transform: uppercase;
         letter-spacing: 0.5px;
         box-shadow: 0 2px 6px rgba(94, 31, 19, 0.1);
      }

      .badge.pending {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
         color: var(--primary-red);
         border: 1.5px solid rgba(198, 69, 62, 0.2);
      }

      .badge.approved {
         background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
         color: var(--primary-red);
         border: 1.5px solid rgba(198, 69, 62, 0.2);
      }

      .badge.rejected {
         background: linear-gradient(135deg, rgba(160, 130, 109, 0.1) 0%, rgba(160, 130, 109, 0.05) 100%);
         color: var(--gray-brown);
         border: 1.5px solid rgba(160, 130, 109, 0.2);
      }

      .user-type {
         font-weight: 800;
         text-transform: uppercase;
         letter-spacing: 0.5px;
         font-size: 0.88rem;
      }

      .user-type.admin { color: var(--primary-red); }
      .user-type.user  { color: var(--dark-brown); }

      /* Assign form */
      .assign-form {
         display: flex;
         flex-direction: column;
         gap: 8px;
      }

      .assign-form select,
      .assign-form button {
         padding: 10px 12px;
         font-size: 0.95rem;
         border-radius: 8px;
         border: 1.5px solid #F0E6D8;
         background: linear-gradient(135deg, #FFFAF5 0%, #FEFDFB 100%);
         color: var(--dark-brown);
         font-weight: 600;
         transition: var(--transition);
         cursor: pointer;
         font-family: inherit;
         width: 100%;
      }

      .assign-form select:focus,
      .assign-form button:focus {
         outline: none;
         border-color: var(--primary-red);
         box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
      }

      .assign-form button {
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
         color: #fff;
         border: none;
         font-weight: 800;
         box-shadow: 0 2px 6px rgba(198, 69, 62, 0.2);
      }

      .assign-form button:hover {
         background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
         box-shadow: 0 4px 12px rgba(94, 31, 19, 0.2);
      }

      /* Discount list */
      .discount-item {
         background: linear-gradient(135deg, #F5EFE7 0%, var(--light-cream) 100%);
         padding: 10px 12px;
         border-radius: 8px;
         margin-bottom: 8px;
         border: 1.5px solid #F0E6D8;
         font-weight: 600;
         color: var(--dark-brown);
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 8px;
      }

      .discount-item:last-child { margin-bottom: 0; }

      .discount-item .remove-link {
         margin-left: auto;
         flex-shrink: 0;
      }

      /* Actions */
      .actions-group {
         display: flex;
         flex-direction: column;
         gap: 8px;
      }

      .actions-row {
         display: flex;
         gap: 6px;
         flex-wrap: wrap;
      }

      .no-data {
         padding: 40px 24px;
         text-align: center;
         color: var(--gray-brown);
         font-weight: 700;
         font-size: 1.1em;
      }

      .no-data i {
         font-size: 2.5rem;
         margin-bottom: 12px;
         color: var(--primary-red);
      }

      @media (prefers-reduced-motion: reduce) {
         * { animation: none !important; transition: none !important; }
      }

      /* =====================================================
         TABLET + MOBILE  (<= 1100px): table becomes cards
         ===================================================== */
      @media (max-width: 1100px) {
         .panel:hover { border-color: #F0E6D8; box-shadow: var(--shadow); }

         .table-wrapper {
            overflow: visible;
            max-height: none;
         }

         table,
         thead,
         tbody,
         tr,
         td {
            display: block;
            width: 100%;
            min-width: 0;
         }

         /* Hide header visually, keep it for screen readers */
         thead {
            position: absolute;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0 0 0 0);
            white-space: nowrap;
         }

         tbody {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(min(100%, 340px), 1fr));
            gap: 16px;
         }

         tbody tr {
            background: linear-gradient(135deg, #FFFFFF 0%, #FFFBF7 100%);
            border: 1.5px solid #F0E6D8;
            border-radius: var(--radius);
            padding: 16px;
            box-shadow: 0 4px 14px rgba(94, 31, 19, 0.06);
            min-width: 0;
         }

         tbody tr:hover {
            background: linear-gradient(135deg, #FFFFFF 0%, #FFFBF7 100%);
            box-shadow: 0 4px 14px rgba(94, 31, 19, 0.06);
         }

         /* Each cell = one labeled row inside the card */
         tbody td {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 10px 0;
            border-bottom: 1px dashed #F0E6D8;
            text-align: right;
            min-width: 0;
         }

         tbody td:last-child { border-bottom: none; padding-bottom: 0; }

         tbody td::before {
            content: attr(data-label);
            flex-shrink: 0;
            text-align: left;
            font-size: 0.72rem;
            font-weight: 800;
            letter-spacing: 0.5px;
            text-transform: uppercase;
            color: var(--gray-brown);
         }

         /* Profile = card header (photo + name), no label */
         td.cell-profile {
            justify-content: flex-start;
            gap: 14px;
            text-align: left;
            padding-top: 0;
            padding-bottom: 14px;
            border-bottom: 2px solid #F0E6D8;
         }

         td.cell-profile::before { display: none; }

         td.cell-profile .profile-img {
            width: 60px;
            height: 60px;
            margin-bottom: 0;
         }

         td.cell-profile .profile-name { font-size: 1.05rem; }

         /* Name is already in the card header */
         td.cell-name { display: none; }

         td.cell-email { overflow-wrap: anywhere; }

         /* Content-heavy cells stack label above content */
         td.cell-verify,
         td.cell-discounts,
         td.cell-assign,
         td.cell-actions {
            flex-direction: column;
            align-items: stretch;
            text-align: left;
            gap: 10px;
         }

         td.cell-verify .verification-img { max-width: 220px; }
         td.cell-verify .btn { align-self: flex-start; }

         td.cell-assign .assign-form {
            flex-direction: row;
            flex-wrap: wrap;
         }

         td.cell-assign .assign-form select { flex: 1 1 160px; min-width: 0; }
         td.cell-assign .assign-form button { flex: 0 0 auto; width: auto; }

         .actions-group {
            flex-direction: row;
            flex-wrap: wrap;
            gap: 8px;
         }

         .actions-group > .btn { flex: 1 1 auto; }
         .actions-row { flex: 1 1 100%; }
         .actions-row .btn { flex: 1 1 0; }

         .lockout-status { white-space: normal; }

         /* Empty state spans the whole grid */
         tbody tr:has(td.no-data) {
            grid-column: 1 / -1;
            background: transparent;
            border: none;
            box-shadow: none;
            padding: 0;
         }

         td.no-data { display: block; text-align: center; }
         td.no-data::before { display: none; }
      }

      /* ---------- Small phones ---------- */
      @media (max-width: 640px) {
         .header .actions,
         .header .actions .btn { width: 100%; }

         .panel { padding: 12px; }

         tbody { grid-template-columns: 1fr; }

         tbody tr { padding: 14px; }

         .btn.small { padding: 9px 12px; font-size: 0.88rem; }

         td.cell-assign .assign-form { flex-direction: column; }
         td.cell-assign .assign-form button { width: 100%; }

         td.cell-verify .verification-img { max-width: 100%; }

         .actions-group > .btn,
         .actions-row .btn { flex: 1 1 100%; }

         .alert { padding: 14px; font-size: 0.9rem; }
      }
   </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<div class="page">
   <!-- Success/Warning Messages -->
   <?php if (isset($_SESSION['approve_message'])): ?>
      <?php $is_warn = strpos($_SESSION['approve_message'], '⚠️') !== false; ?>
      <div class="alert <?php echo $is_warn ? 'warning' : 'success'; ?>">
         <i class="fa-solid <?php echo $is_warn ? 'fa-triangle-exclamation' : 'fa-envelope-circle-check'; ?>"></i>
         <div><?php echo htmlspecialchars($_SESSION['approve_message']); ?></div>
      </div>
      <?php unset($_SESSION['approve_message']); ?>
   <?php endif; ?>

   <?php if (isset($_SESSION['unlock_message'])): ?>
      <div class="alert success">
         <i class="fa-solid fa-check-circle"></i>
         <div><?php echo htmlspecialchars($_SESSION['unlock_message']); ?></div>
      </div>
      <?php unset($_SESSION['unlock_message']); ?>
   <?php endif; ?>

   <?php if (isset($_SESSION['assign_message'])): ?>
      <div class="alert success">
         <i class="fa-solid fa-check-circle"></i>
         <div><?php echo htmlspecialchars($_SESSION['assign_message']); ?></div>
      </div>
      <?php unset($_SESSION['assign_message']); ?>
   <?php endif; ?>

   <?php if (isset($_SESSION['remove_message'])): ?>
      <div class="alert success">
         <i class="fa-solid fa-check-circle"></i>
         <div><?php echo htmlspecialchars($_SESSION['remove_message']); ?></div>
      </div>
      <?php unset($_SESSION['remove_message']); ?>
   <?php endif; ?>

   <!-- Header -->
   <div class="header">
      <div>
         <h1>☕ Manage Users</h1>
         <div class="sub muted">Approve users, manage accounts, and assign discounts</div>
      </div>
      <div class="actions">
         <a href="admin_users.php" class="btn refresh small">
            <i class="fa-solid fa-sync-alt"></i> Refresh
         </a>
      </div>
   </div>

   <!-- Table Panel -->
   <div class="panel">
      <div class="table-wrapper">
         <table aria-describedby="users-table">
            <thead>
               <tr>
                  <th><i class="fa-solid fa-user-circle"></i> Profile</th>
                  <th><i class="fa-solid fa-hashtag"></i> ID</th>
                  <th><i class="fa-solid fa-user"></i> Name</th>
                  <th><i class="fa-solid fa-envelope"></i> Email</th>
                  <th><i class="fa-solid fa-tag"></i> Type</th>
                  <th><i class="fa-solid fa-wallet"></i> Wallet</th>
                  <th><i class="fa-solid fa-badge-check"></i> Status</th>
                  <th><i class="fa-solid fa-lock"></i> Lock Status</th>
                  <th><i class="fa-solid fa-image"></i> Verification</th>
                  <th><i class="fa-solid fa-gift"></i> Assigned Discounts</th>
                  <th><i class="fa-solid fa-plus-circle"></i> Assign</th>
                  <th><i class="fa-solid fa-sliders"></i> Actions</th>
               </tr>
            </thead>

            <tbody>
               <?php
               $select_users = mysqli_query($conn, "SELECT * FROM `users` ORDER BY id DESC") or die('query failed');
               if(mysqli_num_rows($select_users) > 0){
                  while ($user = mysqli_fetch_assoc($select_users)) {
                     $image = !empty($user['profile_image']) ? 'images/' . htmlspecialchars($user['profile_image']) : 'images/default.png';
                     $user_id = intval($user['id']);
                     $verification_img_path = !empty($user['verification_image']) ? 'verification_document.php?id=' . $user_id : null;

                     $assigned_discounts_query = "
                       SELECT ud.id AS user_discount_id, d.code, d.amount, d.valid_until, ud.used 
                       FROM user_discounts ud
                       JOIN discounts d ON ud.discount_id = d.id
                       WHERE ud.user_id = $user_id
                     ";
                     $assigned_discounts_result = mysqli_query($conn, $assigned_discounts_query);

                     $needs_any_approval = (empty($user['status']) || $user['status'] == 'pending')
                        && ($user['id'] != $admin_id);

                     $needs_verification = in_array($user['user_type'], ['Senior','PWD']) && (empty($user['status']) || $user['status'] == 'pending');

                     // Check lockout status
                     $is_locked = false;
                     $lockout_until = $user['lockout_until'] ?? null;
                     $failed_attempts = $user['failed_attempts'] ?? 0;
                     
                     if (!empty($lockout_until)) {
                        $lockout_time = strtotime($lockout_until);
                        $now = time();
                        if ($lockout_time && $lockout_time > $now) {
                           $is_locked = true;
                           $minutes_left = ceil(($lockout_time - $now) / 60);
                        }
                     }
               ?>
               <tr>
                  <!-- Profile -->
                  <td class="cell-profile" data-label="Profile">
                     <img src="<?php echo $image; ?>" alt="profile" class="profile-img">
                     <div class="profile-name"><?php echo htmlspecialchars($user['name']); ?></div>
                  </td>

                  <!-- ID -->
                  <td class="cell-id" data-label="ID"><strong>#<?php echo $user['id']; ?></strong></td>

                  <!-- Name -->
                  <td class="cell-name" data-label="Name"><?php echo htmlspecialchars($user['name']); ?></td>

                  <!-- Email -->
                  <td class="cell-email muted" data-label="Email"><?php echo htmlspecialchars($user['email']); ?></td>

                  <!-- Type -->
                  <td class="cell-type" data-label="Type">
                     <span class="user-type <?php echo strtolower($user['user_type']); ?>">
                        <i class="fa-solid fa-<?php echo ($user['user_type'] === 'admin') ? 'crown' : (($user['user_type'] === 'delivery_rider') ? 'motorcycle' : 'user'); ?>"></i>
                        <?php echo htmlspecialchars($user['user_type']); ?>
                     </span>
                  </td>

                  <!-- Wallet -->
                  <td class="cell-wallet" data-label="Wallet">
                     <?php 
                        if ($user['user_type'] != 'admin' && $user['user_type'] != 'delivery_rider') {
                           echo '<strong style="color: var(--primary-red);">₱' . number_format($user['wallet_balance'], 2) . '</strong>';
                        } else {
                           echo '<span class="muted">—</span>';
                        }
                     ?>
                  </td>

                  <!-- Status -->
                  <td class="cell-status" data-label="Status">
                     <?php
                        $st = $user['status'] ?: 'pending';
                        $cls = $st === 'approved' ? 'approved' : ($st === 'rejected' ? 'rejected' : 'pending');
                     ?>
                     <span class="badge <?php echo $cls; ?>">
                        <i class="fa-solid fa-<?php echo ($cls === 'approved') ? 'check-circle' : (($cls === 'rejected') ? 'times-circle' : 'clock'); ?>"></i>
                        <?php echo htmlspecialchars(ucfirst($st)); ?>
                     </span>
                  </td>

                  <!-- Lock Status -->
                  <td class="cell-lock" data-label="Lock Status">
                     <?php if ($is_locked): ?>
                        <div class="lockout-status">
                           <i class="fa-solid fa-lock"></i>
                           <span><?php echo $minutes_left; ?> min(s)</span>
                        </div>
                     <?php elseif ($failed_attempts > 0): ?>
                        <span class="muted"><i class="fa-solid fa-exclamation-circle"></i> <?php echo $failed_attempts; ?> attempt(s)</span>
                     <?php else: ?>
                        <span class="muted"><i class="fa-solid fa-check-circle"></i> Unlocked</span>
                     <?php endif; ?>
                  </td>

                  <!-- Verification -->
                  <td class="cell-verify" data-label="Verification">
                     <?php if ($verification_img_path): ?>
                        <img src="<?php echo htmlspecialchars($verification_img_path, ENT_QUOTES, 'UTF-8'); ?>" alt="verification" class="verification-img">
                        <a class="btn view small" href="<?php echo htmlspecialchars($verification_img_path, ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">
                           <i class="fa-solid fa-expand"></i> View
                        </a>
                     <?php else: ?>
                        <span class="muted"><i class="fa-solid fa-minus-circle"></i> None</span>
                     <?php endif; ?>
                  </td>

                  <!-- Assigned Discounts -->
                  <td class="cell-discounts" data-label="Assigned Discounts">
                     <?php
                     if ($assigned_discounts_result && mysqli_num_rows($assigned_discounts_result) > 0) {
                        while ($discount_row = mysqli_fetch_assoc($assigned_discounts_result)) {
                           echo '<div class="discount-item">';
                           echo '<span><strong>' . htmlspecialchars($discount_row['code']) . '</strong> (' . htmlspecialchars($discount_row['amount']) . '%)';
                           echo $discount_row['used'] ? ' <small style="color: var(--gray-brown);">(Used)</small>' : '';
                           echo '</span>';
                           echo '<a href="admin_users.php?remove_discount=' . intval($discount_row['user_discount_id']) . '" class="btn small delete remove-link" onclick="return confirm(\'Remove this discount?\');" title="Remove discount"><i class="fa-solid fa-trash-alt"></i></a>';
                           echo '</div>';
                        }
                     } else {
                        echo '<span class="muted"><i class="fa-solid fa-minus-circle"></i> None</span>';
                     }
                     ?>
                  </td>

                  <!-- Assign Discount -->
                  <td class="cell-assign" data-label="Assign">
                     <?php if ($user['user_type'] != 'admin' && $user['user_type'] != 'delivery_rider'): ?>
                        <form method="post" class="assign-form">
                           <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                           <select name="discount_id" required aria-label="Select discount to assign">
                              <option value="" disabled selected>Select...</option>
                              <?php foreach ($discounts as $discount): ?>
                                 <option value="<?php echo intval($discount['id']); ?>">
                                    <?php echo htmlspecialchars($discount['code']) . ' — ' . intval($discount['amount']) . '%'; ?>
                                 </option>
                              <?php endforeach; ?>
                           </select>
                           <button type="submit" name="assign_discount" class="btn small">
                              <i class="fa-solid fa-plus"></i> Assign
                           </button>
                        </form>
                     <?php else: ?>
                        <span class="muted">N/A</span>
                     <?php endif; ?>
                  </td>

                  <!-- Actions -->
                  <td class="cell-actions" data-label="Actions">
                     <div class="actions-group">
                        <!-- Delete Button -->
                        <a href="admin_users.php?delete=<?php echo $user['id']; ?>" class="btn delete small" onclick="return confirm('Delete this user permanently?');" title="Delete user">
                           <i class="fa-solid fa-trash-alt"></i> Delete
                        </a>

                        <!-- Unlock Button -->
                        <?php if ($is_locked): ?>
                           <a href="admin_users.php?unlock_account=<?php echo $user['id']; ?>" class="btn unlock small" onclick="return confirm('Unlock this account immediately?');" title="Unlock account">
                              <i class="fa-solid fa-lock-open"></i> Unlock
                           </a>
                        <?php endif; ?>

                        <!-- Approval/Verification Buttons -->
                        <?php if ($needs_any_approval): ?>
                           <div class="actions-row">
                              <a href="admin_users.php?approve_user=<?php echo $user['id']; ?>" class="btn approve small" onclick="return confirm('Approve this user? An email notification will be sent.');" title="Approve user">
                                 <i class="fa-solid fa-check-circle"></i> Approve
                              </a>
                              <a href="admin_users.php?reject_user=<?php echo $user['id']; ?>" class="btn reject small" onclick="return confirm('Reject this user?');" title="Reject user">
                                 <i class="fa-solid fa-times-circle"></i> Reject
                              </a>
                           </div>
                        <?php elseif ($needs_verification): ?>
                           <div class="actions-row">
                              <a href="admin_users.php?approve_verification=<?php echo $user['id']; ?>" class="btn approve small" onclick="return confirm('Approve this verification? An email notification will be sent.');" title="Approve verification">
                                 <i class="fa-solid fa-check-circle"></i> Verify
                              </a>
                              <a href="admin_users.php?reject_verification=<?php echo $user['id']; ?>" class="btn reject small" onclick="return confirm('Reject this verification?');" title="Reject verification">
                                 <i class="fa-solid fa-times-circle"></i> Reject
                              </a>
                           </div>
                        <?php endif; ?>
                     </div>
                  </td>
               </tr>
               <?php
                  }
               } else {
                  echo '<tr><td colspan="12" class="no-data"><i class="fa-solid fa-inbox"></i><div style="margin-top: 10px;">No users found.</div></td></tr>';
               }
               ?>
            </tbody>
         </table>
      </div>
   </div>
</div>
<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>