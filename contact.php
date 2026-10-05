<?php
include 'config.php';

$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;


/* ---------- Load trusted user full name & email (session first, then DB) ---------- */
$user_name = '';
$user_email = '';

// check common session keys
if (!empty($_SESSION['name'])) {
    $user_name = $_SESSION['name'];
} elseif (!empty($_SESSION['user_name'])) {
    $user_name = $_SESSION['user_name'];
}

if (!empty($_SESSION['email'])) {
    $user_email = $_SESSION['email'];
} elseif (!empty($_SESSION['user_email'])) {
    $user_email = $_SESSION['user_email'];
}

// if one or both are still empty, try fetching from DB
if (empty($user_name) || empty($user_email)) {
    // prefer table `users`, fallback to `user`
    $tables_to_try = ['users', 'user'];
    foreach ($tables_to_try as $tbl) {
        $check_tbl = mysqli_query($conn, "SHOW TABLES LIKE '".mysqli_real_escape_string($conn, $tbl)."'") or die('query failed');
        if ($check_tbl && mysqli_num_rows($check_tbl) > 0) {
            $u = mysqli_query($conn, "SELECT name, email FROM `{$tbl}` WHERE id = '".(int)$user_id."' LIMIT 1") or die('query failed');
            if ($u && mysqli_num_rows($u) > 0) {
                $rowu = mysqli_fetch_assoc($u);
                if (empty($user_name) && !empty($rowu['name'])) $user_name = $rowu['name'];
                if (empty($user_email) && !empty($rowu['email'])) $user_email = $rowu['email'];
                break;
            }
        }
    }
}

/* ---------- POST: user sends a brand-new message (existing logic) ---------- */
if(isset($_POST['send'])){

   // use trusted user name and email, do not trust posted values
   $name = mysqli_real_escape_string($conn, $user_name);
   $email = mysqli_real_escape_string($conn, $user_email);

   // number and message still come from the form
   $number = mysqli_real_escape_string($conn, $_POST['number']);
   $msg = mysqli_real_escape_string($conn, $_POST['message']);

   $select_message = mysqli_query($conn, "SELECT * FROM `message` WHERE user_id = '$user_id' AND name = '$name' AND email = '$email' AND number = '$number' AND message = '$msg'") or die('query failed');

   if(mysqli_num_rows($select_message) > 0){
      $message[] = 'Message already sent!';
   }else{
      mysqli_query($conn, "INSERT INTO `message`(user_id, name, email, number, message) VALUES('$user_id', '$name', '$email', '$number', '$msg')") or die('query failed');
      $message[] = 'Message sent successfully!';
   }

}

/*
  POST: user replying to an existing thread (new)
  form field: reply_text, thread_message_id (message id)
*/
if (isset($_POST['reply_to_thread'])) {
    $thread_id = (int)$_POST['thread_message_id'];
    $reply_text = trim($_POST['reply_text'] ?? '');

    if ($thread_id <= 0 || $reply_text === '') {
        $message[] = 'Reply cannot be empty.';
    } else {
        $reply_safe = mysqli_real_escape_string($conn, $reply_text);
        // insert user reply into message_replies; mark seen_by_user = 1 (user authored)
        mysqli_query($conn, "INSERT INTO `message_replies` (message_id, user_id, reply, seen_by_user) VALUES ('$thread_id', '$user_id', '$reply_safe', 1)") or die('query failed');
        $message[] = 'Reply sent. Admin will be notified.';
    }

    // stay on same page — optionally navigate to thread view
}

/*
  When user opens a thread (via ?view_thread=ID), mark admin replies to that thread as seen_by_user = 1
  and fetch the thread for display.
*/
$thread_view = null;
$thread_replies = null;
if (isset($_GET['view_thread'])) {
    $view_id = (int)$_GET['view_thread'];
    // ensure the message belongs to the logged-in user
    $mq = mysqli_query($conn, "SELECT * FROM `message` WHERE id = '$view_id' AND user_id = '$user_id' LIMIT 1") or die('query failed');
    if ($mq && mysqli_num_rows($mq) > 0) {
        $thread_view = mysqli_fetch_assoc($mq);
        // Mark admin replies as seen
        mysqli_query($conn, "UPDATE `message_replies` SET seen_by_user = 1 WHERE message_id = '$view_id' AND admin_id IS NOT NULL AND seen_by_user = 0") or die('query failed');

        // Fetch all replies for the thread (admin and user replies)
        $thread_replies = mysqli_query($conn, "SELECT * FROM `message_replies` WHERE message_id = '$view_id' ORDER BY created_at ASC") or die('query failed');
    } else {
        $message[] = 'Invalid thread or access denied.';
    }
}

/*
  Inbox / notifications summary:
  find admin replies (admin_id IS NOT NULL) for this user's messages that are unseen
*/
$unseen_q = mysqli_query($conn, "
   SELECT mr.message_id, m.name AS sender_name, m.message AS original_message, COUNT(mr.id) AS new_count, MAX(mr.created_at) AS last_at
   FROM `message_replies` mr
   JOIN `message` m ON mr.message_id = m.id
   WHERE m.user_id = '$user_id' AND mr.admin_id IS NOT NULL AND mr.seen_by_user = 0
   GROUP BY mr.message_id
   ORDER BY last_at DESC
") or die('query failed');

$unseen_total_q = mysqli_query($conn, "
   SELECT COUNT(*) AS c FROM `message_replies` mr
   JOIN `message` m ON mr.message_id = m.id
   WHERE m.user_id = '$user_id' AND mr.admin_id IS NOT NULL AND mr.seen_by_user = 0
") or die('query failed');
$unseen_total = 0;
if ($unseen_total_q && mysqli_num_rows($unseen_total_q) > 0) {
    $urow = mysqli_fetch_assoc($unseen_total_q);
    $unseen_total = (int)$urow['c'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>Contact — Six Origins Cafe</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
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
       --max-width: 1200px;
       --base-font-size: 16px;
       --lead-font-size: 1.08rem;
     }

     * { 
       box-sizing: border-box; 
       margin: 0; 
       padding: 0; 
       font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
     }

     /* RESPONSIVE FIX: only <html> clips sideways overflow. Putting overflow-x:hidden on BOTH html and body
        turns <body> into its own scroll container, which breaks sticky/fixed headers on mobile. */
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
       -webkit-font-smoothing: antialiased;
       line-height: 1.65;
       font-size: var(--base-font-size);
     }

     img { max-width: 100%; }

     .content-wrapper {
       max-width: var(--max-width);
       margin: 0 auto;
       padding: 44px 18px 60px;
     }

     /* ============ HERO ============ */
     /* RESPONSIVE FIX: the hero is a direct child of <body>, so it simply fills the width (no 100vw hack that
        overshoots on phones). It also grows with its content (min-height) so the buttons never get clipped. */
     .hero {
       position: relative;
       width: 100%;
       margin: 0;
       min-height: clamp(380px, 72vh, 550px);
       overflow: hidden;
       display: flex;
       align-items: center;
       justify-content: center;
       animation: slideDown 0.4s ease;
       box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
       border-bottom: 1px solid rgba(255, 255, 255, 0.1);
     }

     .hero::before {
       content: '';
       position: absolute;
       inset: 0;
       background: rgba(0, 0, 0, 0.45);
       z-index: 1;
     }

     .hero-content {
       position: relative;
       z-index: 2;
       text-align: center;
       width: 100%;
       max-width: 900px;
       padding: clamp(28px, 6vw, 60px) clamp(18px, 5vw, 50px);
       animation: fadeIn 0.8s ease 0.3s backwards;
     }

     @keyframes slideDown {
       from { opacity: 0; transform: translateY(-10px); }
       to { opacity: 1; transform: translateY(0); }
     }

     @keyframes fadeIn {
       from { opacity: 0; }
       to { opacity: 1; }
     }

     @keyframes slideUp {
       from { opacity: 0; transform: translateY(20px); }
       to { opacity: 1; transform: translateY(0); }
     }

     .hero h1 {
       font-size: clamp(1.8rem, 6.5vw, 4rem);
       margin-bottom: clamp(12px, 2.5vw, 24px);
       color: #ffffff;
       font-family: 'Montserrat', sans-serif;
       font-weight: 900;
       letter-spacing: -1.5px;
       line-height: 1.1;
       text-shadow: 0 12px 40px rgba(0, 0, 0, 0.7);
       overflow-wrap: break-word;
     }

     .hero p {
       color: rgba(255, 255, 255, 0.98);
       font-size: clamp(1rem, 2.4vw, 1.3rem);
       margin-bottom: clamp(18px, 3.5vw, 36px);
       font-weight: 500;
       line-height: 1.7;
       text-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
     }

     .hero-buttons {
       display: flex;
       gap: 14px;
       flex-wrap: wrap;
       justify-content: center;
       align-items: center;
     }

     .hero .btn, .hero .btn-ghost {
       padding: 16px 40px;
       border-radius: 12px;
       font-weight: 800;
       border: none;
       font-size: 1.05em;
       cursor: pointer;
       transition: all 0.3s ease;
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

     .hero .btn:hover::before, .hero .btn-ghost:hover::before { left: 100%; }

     .hero .btn {
       background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
       color: #fff;
       box-shadow: 0 8px 24px rgba(198, 69, 62, 0.45);
     }

     .hero .btn:hover {
       background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
       transform: translateY(-3px);
       box-shadow: 0 12px 32px rgba(94, 31, 19, 0.55);
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
       transform: translateY(-3px);
       box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
     }

     /* HEADER FIX: scoped to the hero so it can never touch the ".badge" in header.php */
     .hero .badge {
       background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
       color: #fff;
       padding: 6px 12px;
       border-radius: 99px;
       font-weight: 800;
       margin-left: 8px;
       font-size: 0.85rem;
       box-shadow: 0 2px 8px rgba(198, 69, 62, 0.2);
     }

     /* ============ CONTACT GRID ============ */
     .contact-grid {
       display: grid;
       grid-template-columns: minmax(0, 1fr) minmax(0, 400px);
       gap: 32px;
       align-items: start;
       margin-top: 20px;
     }

     .card {
       background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
       border-radius: var(--radius);
       padding: 32px;
       box-shadow: var(--shadow);
       border: 1.5px solid #F0E6D8;
       transition: all 0.3s ease;
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
       margin-bottom: 20px;
       color: var(--dark-brown);
       font-size: 1.5rem;
       font-family: 'Montserrat', sans-serif;
       font-weight: 900;
       letter-spacing: -0.5px;
       margin-top: 0;
     }

     form.contact-form {
       display: flex;
       flex-direction: column;
       gap: 16px;
     }

     label {
       display: block;
       font-weight: 700;
       margin-bottom: 8px;
       color: var(--primary-red);
       font-size: 0.95rem;
       letter-spacing: 0.3px;
     }

     input[type="text"],
     input[type="email"],
     input[type="number"],
     textarea {
       width: 100%;
       max-width: 100%;
       padding: 12px 14px;
       border-radius: 10px;
       border: 1.5px solid #F0E6D8;
       background: linear-gradient(135deg, #FFF2E0 0%, #FFFBF7 100%);
       font-size: 0.95rem;
       color: var(--dark-brown);
       font-weight: 500;
       transition: all 0.3s ease;
       font-family: inherit;
     }

     input[type="text"]:focus,
     input[type="email"]:focus,
     input[type="number"]:focus,
     textarea:focus {
       outline: none;
       border-color: var(--primary-red);
       box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
     }

     textarea {
       min-height: 160px;
       resize: vertical;
     }

     .fields {
       display: grid;
       grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
       gap: 16px;
     }

     /* HEADER FIX: header.php also has an ".actions" group. This rule used to be unscoped, and its mobile
        "flex-direction: column-reverse" stacked the header buttons vertically. Now it only affects the page content. */
     .content-wrapper .actions {
       display: flex;
       gap: 12px;
       align-items: center;
       justify-content: flex-end;
       margin-top: 8px;
     }

     .btn {
       padding: 12px 20px;
       border-radius: 10px;
       border: 0;
       cursor: pointer;
       font-weight: 800;
       display: inline-flex;
       align-items: center;
       justify-content: center;
       gap: 8px;
       font-size: 0.98em;
       background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
       color: #fff;
       transition: all 0.3s ease;
       text-decoration: none;
       box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
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

     .btn:hover::before { left: 100%; }

     .btn:hover {
       background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
       transform: translateY(-2px);
       box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
     }

     /* Also carries the full button sizing so it looks right on its own (e.g. the "Back" link) */
     .btn-ghost-small {
       padding: 12px 20px;
       border-radius: 10px;
       display: inline-flex;
       align-items: center;
       justify-content: center;
       gap: 8px;
       font-size: 0.98em;
       text-decoration: none;
       cursor: pointer;
       transition: all 0.3s ease;
       background: linear-gradient(135deg, #FFF2E0 0%, var(--light-cream) 100%);
       border: 1.5px solid #F0E6D8;
       color: var(--primary-red);
       font-weight: 700;
       box-shadow: none;
     }

     .btn-ghost-small:hover {
       background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
       color: #fff;
       border-color: var(--primary-red);
     }

     .info-list {
       display: flex;
       flex-direction: column;
       gap: 16px;
     }

     .info-card {
       background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
       border-radius: var(--radius);
       padding: 18px;
       border: 1.5px solid #F0E6D8;
       box-shadow: var(--shadow);
       transition: all 0.3s ease;
       min-width: 0;
     }

     .info-card:hover {
       box-shadow: var(--shadow-hover);
       border-color: var(--primary-red);
       transform: translateY(-2px);
     }

     .info-card h4 {
       margin: 0 0 10px 0;
       color: var(--dark-brown);
       font-family: 'Montserrat', sans-serif;
       font-size: 1.15rem;
       font-weight: 900;
     }

     .info-card p {
       margin: 0;
       color: var(--gray-brown);
       font-weight: 600;
       font-size: 1.03em;
       overflow-wrap: anywhere;
     }

     .small {
       font-size: 0.93rem;
       color: var(--gray-brown);
     }

     .info-card .small {
       color: var(--gray-brown);
       font-weight: 500;
     }

     .info-card a {
       color: var(--primary-red);
       font-size: 1.3em;
       margin-right: 12px;
       display: inline-block;
       text-decoration: none;
       transition: all 0.3s ease;
     }

     .info-card a:hover {
       color: var(--dark-brown);
       transform: scale(1.1);
     }

     /* Messages */
     .messages {
       display: flex;
       flex-direction: column;
       gap: 12px;
       margin-bottom: 20px;
       animation: slideDown 0.4s ease;
     }

     .message {
       background: linear-gradient(135deg, rgba(198, 69, 62, 0.1) 0%, rgba(198, 69, 62, 0.05) 100%);
       border-left: 5px solid var(--primary-red);
       padding: 14px 18px;
       border-radius: var(--radius);
       color: #8B3D37;
       font-size: 1.05em;
       box-shadow: 0 4px 12px rgba(198, 69, 62, 0.08);
       font-weight: 700;
       display: flex;
       gap: 10px;
       align-items: center;
       overflow-wrap: anywhere;
     }

     .message::before {
       content: '✓';
       font-weight: 900;
       font-size: 1.2em;
       flex-shrink: 0;
     }

     /* Inbox panel */
     .inbox {
       margin-top: 24px;
       border-radius: var(--radius);
       border: 1.5px solid #F0E6D8;
       background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
       padding: 18px;
       transition: all 0.3s ease;
     }

     .inbox:hover {
       border-color: var(--primary-red);
       box-shadow: var(--shadow);
     }

     .inbox-header {
       display: flex;
       justify-content: space-between;
       align-items: center;
       gap: 10px;
       flex-wrap: wrap;
       margin-bottom: 16px;
       padding-bottom: 12px;
       border-bottom: 1.5px solid #F0E6D8;
     }

     .inbox-header-title {
       font-weight: 900;
       color: var(--dark-brown);
       font-size: 1.1rem;
       font-family: 'Montserrat', sans-serif;
     }

     .inbox-header-status {
       font-weight: 800;
       color: var(--primary-red);
     }

     .thread-row {
       padding: 12px;
       border-radius: 10px;
       background: linear-gradient(135deg, #FFF9F3 0%, #FFFBF7 100%);
       margin-bottom: 10px;
       border: 1.5px solid #F0E6D8;
       transition: all 0.3s ease;
     }

     .thread-row:hover {
       background: linear-gradient(135deg, #FFFBF7 0%, #FFF9F3 100%);
       border-color: var(--primary-red);
       transform: translateX(4px);
     }

     .thread-row-header {
       display: flex;
       justify-content: space-between;
       align-items: center;
       gap: 12px;
     }

     .thread-row-header > div:first-child {
       min-width: 0;
       flex: 1;
     }

     .thread-name {
       font-weight: 800;
       color: var(--dark-brown);
       font-size: 1.05rem;
       overflow-wrap: anywhere;
     }

     .thread-preview {
       font-size: 0.9rem;
       color: var(--gray-brown);
       margin-top: 6px;
       white-space: nowrap;
       overflow: hidden;
       text-overflow: ellipsis;
       max-width: 100%;
     }

     .thread-actions { flex-shrink: 0; }

     .thread-actions a {
       text-decoration: none;
       display: inline-flex;
       align-items: center;
       gap: 6px;
       padding: 8px 14px;
       border-radius: 8px;
       background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
       color: #fff;
       font-weight: 700;
       font-size: 0.9rem;
       transition: all 0.3s ease;
       box-shadow: 0 2px 8px rgba(198, 69, 62, 0.2);
     }

     .thread-actions a:hover {
       background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
       transform: translateY(-2px);
     }

     /* ============ MODAL ============ */
     .modal {
       position: fixed;
       inset: 0;
       display: flex;
       align-items: center;
       justify-content: center;
       background: rgba(94, 31, 19, 0.5);
       z-index: 6000; /* above the floating chat button */
       padding: 16px;
       animation: fadeIn 0.3s ease;
     }

     .modal .modal-content {
       width: 100%;
       max-width: 800px;
       background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
       border-radius: var(--radius);
       padding: 28px;
       box-shadow: var(--shadow-hover);
       border: 1.5px solid #F0E6D8;
       max-height: 90vh;
       max-height: 90dvh;
       overflow-y: auto;
       overscroll-behavior: contain;
       animation: slideUp 0.3s ease;
     }

     .modal-head {
       display: flex;
       justify-content: space-between;
       align-items: center;
       gap: 12px;
       margin-bottom: 20px;
     }

     .modal-close {
       font-size: 1.5rem;
       color: var(--gray-brown);
       text-decoration: none;
       cursor: pointer;
       padding: 6px 10px;
       flex-shrink: 0;
     }

     .modal .modal-content h3 {
       margin: 0;
       color: var(--dark-brown);
       font-family: 'Montserrat', sans-serif;
       font-size: 1.4rem;
       font-weight: 900;
       overflow-wrap: anywhere;
     }

     .original-message {
       padding: 14px;
       border-radius: 10px;
       background: linear-gradient(135deg, #FFF9F3 0%, var(--light-cream) 100%);
       border: 1.5px solid #F0E6D8;
       margin-bottom: 16px;
     }

     .original-message-title {
       font-weight: 800;
       color: var(--dark-brown);
       margin-bottom: 10px;
       font-size: 0.95rem;
     }

     .original-message-text {
       white-space: pre-wrap;
       color: var(--gray-brown);
       font-weight: 500;
       line-height: 1.6;
       overflow-wrap: anywhere;
     }

     .original-message-meta {
       margin-top: 12px;
       color: var(--gray-brown);
       font-size: 0.9rem;
       display: flex;
       flex-wrap: wrap;
       gap: 6px 16px;
       overflow-wrap: anywhere;
     }

     .replies-container {
       max-height: 400px;
       overflow-y: auto;
       margin-bottom: 18px;
       display: flex;
       flex-direction: column;
       gap: 12px;
     }

     .reply-admin {
       padding: 12px;
       margin-bottom: 0;
       border-radius: 10px;
       background: linear-gradient(135deg, #fff 0%, #FFFBF7 100%);
       border: 1.5px solid #F0E6D8;
     }

     .reply-user {
       padding: 12px;
       margin-bottom: 0;
       border-radius: 10px;
       background: linear-gradient(135deg, rgba(198, 69, 62, 0.08) 0%, rgba(198, 69, 62, 0.03) 100%);
       border: 1.5px solid rgba(198, 69, 62, 0.2);
     }

     .reply-header {
       font-weight: 800;
       color: var(--dark-brown);
       margin-bottom: 6px;
       display: flex;
       flex-wrap: wrap;
       gap: 4px 8px;
       align-items: center;
     }

     .reply-header .small {
       font-weight: 600;
       color: var(--gray-brown);
       font-size: 0.85rem;
       margin-left: auto;
     }

     .reply-text {
       color: var(--gray-brown);
       font-weight: 500;
       line-height: 1.6;
       overflow-wrap: anywhere;
     }

     .reply-form {
       display: flex;
       flex-direction: column;
       gap: 12px;
       padding-top: 12px;
       border-top: 1.5px solid #F0E6D8;
     }

     .reply-form textarea { min-height: 120px; }

     .reply-actions {
       display: flex;
       gap: 12px;
       justify-content: flex-end;
     }

     /* ============================================
        RESPONSIVE
        ============================================ */
     @media (max-width: 1100px) {
       .contact-grid { grid-template-columns: minmax(0, 1fr); gap: 24px; }
       /* Contact cards sit side by side on tablets instead of one long stack */
       .info-list { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr)); gap: 16px; }
     }

     @media (max-width: 800px) {
       .content-wrapper { padding: 28px 14px 48px; }
       .hero .btn, .hero .btn-ghost { padding: 12px 24px; font-size: 0.95em; }
       .card { padding: 24px; }
       .fields { grid-template-columns: minmax(0, 1fr); }

       /* Buttons become full width and stack, with the main action (Send) on top */
       .content-wrapper .actions { flex-direction: column-reverse; align-items: stretch; }
       .content-wrapper .actions .btn, .content-wrapper .actions .btn-ghost-small { width: 100%; }

       .modal .modal-content { padding: 20px; }
       .reply-actions { flex-direction: column-reverse; }
       .reply-actions .btn, .reply-actions .btn-ghost-small { width: 100%; }

       /* 16px stops iOS from zooming the page when a field is focused */
       input[type="text"], input[type="email"], input[type="number"], textarea { font-size: 16px; }
       /* Comfortable tap targets */
       .btn, .btn-ghost-small { min-height: 44px; }
     }

     @media (max-width: 600px) {
       .hero-buttons { flex-direction: column; align-items: stretch; }
       .hero .btn, .hero .btn-ghost { width: 100%; }
       .modal { padding: 10px; align-items: flex-end; }
       .modal .modal-content { max-height: 92vh; max-height: 92dvh; padding: 18px; }
       .modal .modal-content h3 { font-size: 1.15rem; }
       .reply-header .small { margin-left: 0; }
       .info-list { grid-template-columns: minmax(0, 1fr); }
     }

     @media (max-width: 480px) {
       .content-wrapper { padding: 22px 12px 36px; }
       .hero .btn, .hero .btn-ghost { padding: 12px 18px; font-size: 0.9em; gap: 6px; }
       .card { padding: 18px; }
       .card h2 { font-size: 1.3rem; }
       .info-list { gap: 12px; }
       .inbox { padding: 14px; }
       .thread-row-header { gap: 8px; flex-wrap: wrap; }
       .thread-actions { width: 100%; }
       .thread-actions a { width: 100%; justify-content: center; padding: 10px 12px; }
       .message { padding: 12px 14px; font-size: 0.95em; }
       textarea { min-height: 140px; }
     }

     /* Hover "lift" effects feel sticky on touch screens */
     @media (hover: none) {
       .card:hover, .info-card:hover, .thread-row:hover { transform: none; }
     }
   </style>
</head>
<body>

<?php include 'header.php'; ?>

<!-- HERO SECTION WITH BACKGROUND IMAGE - FULL WIDTH AT TOP -->
<section class="hero" aria-labelledby="contactTitle" style="background: linear-gradient(135deg, rgba(0, 0, 0, 0.52) 0%, rgba(0, 0, 0, 0.65) 100%), url('images/aboback.png') center/cover no-repeat;">
  <div class="hero-content">
    <h1 id="contactTitle">Get In Touch</h1>
    <p>Have questions about your order, our products, or want to discuss something with us? We'd love to hear from you! Our friendly team is here to help and respond as quickly as possible.</p>
    <div class="hero-buttons">
      <a href="Item.php" class="btn-ghost">
        <i class="fa-solid fa-bag-shopping"></i> Visit Shop
      </a>
      <a href="index.php" class="btn-ghost">
        <i class="fa-solid fa-arrow-left"></i> Back Home
      </a>
      <?php if ($unseen_total > 0): ?>
        <a href="#inbox" class="btn">
          <i class="fa-solid fa-bell"></i> 
          <span>Replies</span>
          <span class="badge"><?php echo $unseen_total; ?></span>
        </a>
      <?php endif; ?>
    </div>
  </div>
</section>

<div class="content-wrapper">

  <!-- Messages -->
  <?php if (!empty($message)): ?>
    <div class="messages" aria-live="polite" aria-atomic="true">
      <?php foreach ($message as $msg): ?>
         <div class="message"><?php echo htmlspecialchars($msg); ?></div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <!-- Contact Grid -->
  <div class="contact-grid" aria-label="Contact content">
    <section class="card">
      <h2>Send us a message</h2>
      <form action="" method="post" class="contact-form" novalidate>
         <div>
           <label for="name">Full Name</label>
           <input id="name" type="text" name="name" required placeholder="Your full name" value="<?php echo htmlspecialchars($user_name); ?>" readonly aria-readonly="true">
         </div>

         <div class="fields">
           <div>
             <label for="email">Email Address</label>
             <input id="email" type="email" name="email" required placeholder="you@example.com" autocomplete="email" value="<?php echo htmlspecialchars($user_email); ?>" readonly aria-readonly="true">
           </div>
           <div>
             <label for="number">Phone Number</label>
             <input id="number" type="number" name="number" required placeholder="09XXXXXXXXX" inputmode="numeric">
           </div>
         </div>

         <div>
           <label for="message">Message</label>
           <textarea id="message" name="message" required placeholder="Tell us how we can help you..."></textarea>
           <div class="small" style="margin-top: 8px;">
             <i class="fa-solid fa-lightbulb"></i> Tip: Include your order number for faster resolution.
           </div>
         </div>

         <div class="actions">
           <a href="index.php" class="btn-ghost-small">
             <i class="fa-solid fa-arrow-left"></i> Back
           </a>
           <button type="submit" name="send" class="btn">
             <i class="fa-solid fa-paper-plane"></i> Send Message
           </button>
         </div>
      </form>

      <!-- Inbox summary -->
      <div id="inbox" class="inbox">
         <div class="inbox-header">
            <div class="inbox-header-title">
              <i class="fa-solid fa-inbox"></i> Your Conversation Threads
            </div>
            <?php if ($unseen_total > 0): ?>
               <div class="inbox-header-status">
                 <i class="fa-solid fa-star"></i> <?php echo $unseen_total; ?> New
               </div>
            <?php else: ?>
               <div class="small">All caught up!</div>
            <?php endif; ?>
         </div>

         <?php
            // List threads that have unseen replies
            if ($unseen_q && mysqli_num_rows($unseen_q) > 0):
               while ($row = mysqli_fetch_assoc($unseen_q)):
         ?>
               <div class="thread-row">
                 <div class="thread-row-header">
                   <div>
                      <div class="thread-name">
                        <i class="fa-solid fa-envelope-open"></i> <?php echo htmlspecialchars($row['sender_name']); ?>
                      </div>
                      <div class="thread-preview"><?php echo htmlspecialchars(mb_strimwidth($row['original_message'], 0, 80, '...')); ?></div>
                   </div>
                   <div class="thread-actions">
                      <a href="contact.php?view_thread=<?php echo (int)$row['message_id']; ?>">
                        <i class="fa-solid fa-arrow-right"></i> View
                      </a>
                   </div>
                 </div>
               </div>
         <?php
               endwhile;
            else:
               // fallback: show recent messages
               $recent_q = mysqli_query($conn, "SELECT * FROM `message` WHERE user_id = '$user_id' ORDER BY id DESC LIMIT 6") or die('query failed');
               if ($recent_q && mysqli_num_rows($recent_q) > 0):
                 while ($r = mysqli_fetch_assoc($recent_q)):
         ?>
                   <div class="thread-row">
                      <div class="thread-row-header">
                        <div>
                          <div class="thread-name">
                            <i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($r['name']); ?>
                          </div>
                          <div class="thread-preview"><?php echo htmlspecialchars(mb_strimwidth($r['message'], 0, 80, '...')); ?></div>
                        </div>
                        <div class="thread-actions">
                           <a href="contact.php?view_thread=<?php echo (int)$r['id']; ?>">
                             <i class="fa-solid fa-arrow-right"></i> Open
                           </a>
                        </div>
                      </div>
                   </div>
         <?php
                 endwhile;
               else:
                  echo '<div class="small" style="text-align: center; padding: 20px;"><i class="fa-solid fa-inbox"></i> <div style="margin-top: 10px;">No messages yet. Use the form above to start a conversation.</div></div>';
               endif;
            endif;
         ?>
      </div>
    </section>

    <!-- Contact Info Sidebar -->
    <aside>
      <div class="info-list">
        <div class="info-card">
          <h4><i class="fa-solid fa-phone"></i> Call Us</h4>
          <p style="color: var(--primary-red); font-size: 1.1em;">+63 912 345 6789</p>
          <p class="small">Available 9AM - 6PM daily</p>
        </div>

        <div class="info-card">
          <h4><i class="fa-solid fa-location-dot"></i> Visit Us</h4>
          <p>Cainta, Rizal, Philippines</p>
          <p class="small">Drop by our cafe for direct assistance and product demos!</p>
        </div>

        <div class="info-card">
          <h4><i class="fa-solid fa-share-nodes"></i> Follow Us</h4>
          <div style="display: flex; gap: 10px; margin-top: 10px;">
            <a href="#" title="Facebook" aria-label="Facebook">
              <i class="fab fa-facebook-f"></i>
            </a>
            <a href="#" title="Instagram" aria-label="Instagram">
              <i class="fab fa-instagram"></i>
            </a>
            <a href="#" title="Twitter" aria-label="Twitter">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="#" title="TikTok" aria-label="TikTok">
              <i class="fab fa-tiktok"></i>
            </a>
          </div>
        </div>

        <div class="info-card">
          <h4><i class="fa-solid fa-clock"></i> Response Time</h4>
          <p>We typically reply within 24 hours</p>
          <p class="small">During business hours: 1-2 hours</p>
        </div>
      </div>
    </aside>
  </div>
</div>

<?php include 'chatbot.php'; ?>
<?php include 'footer.php'; ?>

<!-- Thread Modal -->
<?php if ($thread_view): ?>
  <div class="modal" role="dialog" aria-modal="true" aria-labelledby="thread-title">
    <div class="modal-content">
      <div class="modal-head">
        <h3 id="thread-title">Conversation with <?php echo htmlspecialchars($thread_view['name']); ?></h3>
        <a href="contact.php" class="modal-close" aria-label="Close conversation">
          <i class="fa-solid fa-xmark"></i>
        </a>
      </div>

      <!-- Original Message -->
      <div class="original-message">
         <div class="original-message-title">
           <i class="fa-solid fa-quote-left"></i> Your Original Message
         </div>
         <div class="original-message-text"><?php echo htmlspecialchars($thread_view['message']); ?></div>
         <div class="original-message-meta">
           <span><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($thread_view['email']); ?></span>
           <span><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($thread_view['number']); ?></span>
         </div>
      </div>

      <!-- Replies -->
      <?php if ($thread_replies && mysqli_num_rows($thread_replies) > 0): ?>
         <div class="replies-container">
           <?php while ($tr = mysqli_fetch_assoc($thread_replies)): ?>
              <?php if (!empty($tr['admin_id'])): ?>
                 <div class="reply-admin">
                    <div class="reply-header">
                       <i class="fa-solid fa-user-tie"></i>
                       <span><?php echo htmlspecialchars($tr['admin_name'] ?: 'Admin'); ?></span>
                       <span class="small"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($tr['created_at']); ?></span>
                    </div>
                    <div class="reply-text"><?php echo nl2br(htmlspecialchars($tr['reply'])); ?></div>
                 </div>
              <?php else: ?>
                 <div class="reply-user">
                    <div class="reply-header">
                       <i class="fa-solid fa-user"></i>
                       <span>You</span>
                       <span class="small"><i class="fa-solid fa-clock"></i> <?php echo htmlspecialchars($tr['created_at']); ?></span>
                    </div>
                    <div class="reply-text"><?php echo nl2br(htmlspecialchars($tr['reply'])); ?></div>
                 </div>
              <?php endif; ?>
           <?php endwhile; ?>
         </div>
      <?php else: ?>
         <div class="small" style="text-align: center; padding: 20px; color: var(--gray-brown);">
           <i class="fa-solid fa-inbox"></i> No replies yet from admin
         </div>
      <?php endif; ?>

      <!-- Reply Form -->
      <form action="contact.php" method="post" class="reply-form">
         <input type="hidden" name="thread_message_id" value="<?php echo (int)$thread_view['id']; ?>">
         <div>
            <label for="reply_text" style="font-weight: 700; color: var(--dark-brown);">
              <i class="fa-solid fa-reply"></i> Your Reply
            </label>
            <textarea id="reply_text" name="reply_text" required placeholder="Type your reply here..."></textarea>
         </div>

         <div class="reply-actions">
            <a href="contact.php" class="btn btn-ghost-small" style="text-decoration: none;">
              <i class="fa-solid fa-times"></i> Close
            </a>
            <button type="submit" name="reply_to_thread" class="btn">
              <i class="fa-solid fa-paper-plane"></i> Send Reply
            </button>
         </div>
      </form>
    </div>
  </div>
<?php endif; ?>

<script>
  // Auto-dismiss feedback messages after 4s
  (function(){
    const container = document.querySelector('.messages');
    if(!container) return;
    setTimeout(()=> {
      container.querySelectorAll('.message').forEach(m => {
        m.style.transition = 'opacity 300ms, transform 300ms';
        m.style.opacity = '0';
        m.style.transform = 'translateY(-6px)';
        setTimeout(()=> { if(m.parentNode) m.remove(); }, 350);
      });
    }, 4000);
  })();
</script>
<script src="Js/script1.js"></script>
</body>
</html>