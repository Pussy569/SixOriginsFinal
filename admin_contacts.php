<?php
require_once __DIR__ . '/mail_helper.php';

include 'config.php';

/**
 * SIX ORIGINS CAFE - ADMINISTRATIVE MESSAGE MANAGEMENT SYSTEM
 * Logic Version: 2.4.0
 * Security: Admin Session Required
 */

$admin_id = isset($_SESSION['admin_id']) ? $_SESSION['admin_id'] : null;

// Standard Security Redirect
if(!isset($admin_id)){
   header('location:login.php');
   exit;
}

/* * FLASH MESSAGING SYSTEM
 * Used to provide feedback for Delete and Reply actions
 */
if (isset($_SESSION['admin_msg'])) {
    $flash_msg = $_SESSION['admin_msg'];
    $flash_type = isset($_SESSION['admin_msg_type']) ? $_SESSION['admin_msg_type'] : 'info';
    unset($_SESSION['admin_msg'], $_SESSION['admin_msg_type']);
}

/* * MESSAGE DELETION LOGIC
 */
if(isset($_GET['delete'])){
   $delete_id = mysqli_real_escape_string($conn, $_GET['delete']);
   
   // Check if record exists before deletion
   $check_exists = mysqli_query($conn, "SELECT id FROM `message` WHERE id = '$delete_id'");
   
   if(mysqli_num_rows($check_exists) > 0) {
       mysqli_query($conn, "DELETE FROM `message` WHERE id = '$delete_id'") or die('query failed');
       // Also clean up replies associated with this message
       mysqli_query($conn, "DELETE FROM `message_replies` WHERE message_id = '$delete_id'");
       
       $_SESSION['admin_msg'] = 'Message and conversation history deleted successfully.';
       $_SESSION['admin_msg_type'] = 'success';
   }
   
   header('location:admin_contacts.php');
   exit;
}

/* * REPLY PROCESSING & EMAIL NOTIFICATION LOGIC
 */
if(isset($_POST['send_reply'])){
    $message_id = (int)$_POST['message_id'];
    $reply_text = trim($_POST['reply_text'] ?? '');

    // Validation
    if ($message_id <= 0 || $reply_text === ''){
        $_SESSION['admin_msg'] = 'Reply cannot be empty. Please enter a message.';
        $_SESSION['admin_msg_type'] = 'error';
        header("Location: admin_contacts.php?reply=" . $message_id);
        exit;
    }

    // Database Insertion
    $reply_safe = mysqli_real_escape_string($conn, $reply_text);
    $admin_id_int = (int)$admin_id;
    $insert = mysqli_query($conn, "INSERT INTO `message_replies` (message_id, admin_id, reply) VALUES ('$message_id', '$admin_id_int', '$reply_safe')") or die('query failed');

    // Email Dispatch Logic
    $email_query = mysqli_query($conn, "SELECT email, name FROM `message` WHERE id = '$message_id' LIMIT 1") or die('query failed');
    $email_sent = false;
    
    if ($email_query && mysqli_num_rows($email_query) > 0) {
        $row = mysqli_fetch_assoc($email_query);
        $to = $row['email'];
        $customer_name = (trim($row['name']) !== '' ? $row['name'] : 'Valued Customer');
        
        $subject = "Reply from Six Origins Cafe Support";
        
        $safeName = htmlspecialchars($customer_name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeReply = nl2br(htmlspecialchars($reply_text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
        $body = '<p>Hello ' . $safeName . ',</p>
                 <p>We have received your inquiry and our team has provided a response:</p>
                 <blockquote>' . $safeReply . '</blockquote>
                 <p>If you have further questions, feel free to reply to this email or visit our cafe.</p>
                 <p>Warm regards,<br>Six Origins Cafe Administration<br>☕ Brewed with Passion</p>';
        $text = "Hello {$customer_name},\n\nWe have received your inquiry and our team has provided a response:\n\n{$reply_text}\n\nIf you have further questions, feel free to reply to this email or visit our cafe.\n\nWarm regards,\nSix Origins Cafe Administration\nBrewed with Passion";
        if (filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $email_sent = send_six_origins_mail($to, $customer_name, $subject, $body, $text, 'support@sixoriginscafe.com');
        }
    }

    // Redirect with Status
    if ($insert) {
        if ($email_sent) {
            $_SESSION['admin_msg'] = 'Reply successfully saved and emailed to ' . $to;
            $_SESSION['admin_msg_type'] = 'success';
        } else {
            $_SESSION['admin_msg'] = 'Reply saved to database, but email notification failed.';
            $_SESSION['admin_msg_type'] = 'warning';
        }
    } else {
        $_SESSION['admin_msg'] = 'A database error occurred. Reply was not saved.';
        $_SESSION['admin_msg_type'] = 'error';
    }

    header('Location: admin_contacts.php?reply=' . $message_id);
    exit;
}

/* * DATA RETRIEVAL FOR MODAL CONTEXT
 */
$reply_modal_data = null;
$replies_q = null;

if (isset($_GET['reply'])) {
    $reply_id = (int)$_GET['reply'];
    if ($reply_id > 0) {
        $mq = mysqli_query($conn, "SELECT * FROM `message` WHERE id = '$reply_id' LIMIT 1") or die('query failed');
        if ($mq && mysqli_num_rows($mq) > 0) {
            $reply_modal_data = mysqli_fetch_assoc($mq);
            // Fetch entire conversation history for the thread
            $replies_q = mysqli_query($conn, "SELECT mr.* FROM `message_replies` mr WHERE mr.message_id = '$reply_id' ORDER BY mr.created_at ASC") or die('query failed');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Manage Inquiries — Six Origins Admin Panel</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:ital,wght@0,100;0,200;0,300;0,400;0,500;0,600;0,700;0,800;0,900;1,100;1,200;1,300;1,400;1,500;1,600;1,700;1,800;1,900&display=swap" rel="stylesheet">
    <link rel="icon" type="image/png" href="images/logos.png">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />

    <style>
        /* * DESIGN SYSTEM & VARIABLES
         * Built specifically for Six Origins Cafe Branding
         */
        :root {
            --primary-red: #C6453E;
            --primary-red-hover: #A53933;
            --dark-brown: #5E1F13;
            --deep-brown: #3D1608;
            --gray-brown: #664C47;
            --light-cream: #FFF2E0;
            --soft-cream: #FFF9F2;
            --white: #FFFFFF;
            --success-green: #2E7D32;
            --error-red: #D32F2F;
            --warning-orange: #EF6C00;
            
            --border-color: #F0E6D8;
            --orange-border: #FFE0B2;
            --quote-bg: #FFF5E6;
            --admin-bubble: #FFFFFF;
            --you-bubble: #FFF5F5;
            
            --shadow-sm: 0 2px 4px rgba(94, 31, 19, 0.05);
            --shadow-md: 0 8px 24px rgba(94, 31, 19, 0.08);
            --shadow-lg: 0 12px 36px rgba(94, 31, 19, 0.12);
            --radius-sm: 8px;
            --radius-md: 12px;
            --radius-lg: 16px;
            --radius-xl: 24px;
            
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }

        /* RESET & BASE STYLES */
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Montserrat', sans-serif;
        }

        body {
            min-height: 100vh;
            background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
            color: var(--dark-brown);
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
        }

        /* LAYOUT COMPONENTS */
        .admin-container {
            max-width: 1200px;
            margin: 40px auto;
            padding: 0 20px 60px;
            width: 100%;
        }

        /* REUSABLE HEADER COMPONENT */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 35px;
            padding-bottom: 20px;
            border-bottom: 2px solid var(--border-color);
            animation: fadeInDown 0.5s ease-out;
        }

        @keyframes fadeInDown {
            from { opacity: 0; transform: translateY(-20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        .page-title {
            display: flex;
            flex-direction: column;
        }

        .page-title h1 {
            font-size: 2.2rem;
            font-weight: 900;
            color: var(--dark-brown);
            letter-spacing: -1px;
            text-transform: capitalize;
        }

        .page-title p {
            font-size: 1rem;
            color: var(--gray-brown);
            font-weight: 500;
        }

        /* ACTION BUTTONS */
        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            padding: 12px 24px;
            border-radius: var(--radius-md);
            font-weight: 800;
            font-size: 0.95rem;
            cursor: pointer;
            transition: var(--transition);
            text-decoration: none;
            border: none;
            outline: none;
        }

        .btn-refresh {
            background: var(--white);
            color: var(--dark-brown);
            border: 2px solid var(--border-color);
            box-shadow: var(--shadow-sm);
        }

        .btn-refresh:hover {
            background: var(--dark-brown);
            color: var(--white);
            border-color: var(--dark-brown);
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
        }

        /* DATA PANEL & TABLE */
        .content-panel {
            background: var(--white);
            border-radius: var(--radius-xl);
            padding: 30px;
            box-shadow: var(--shadow-md);
            border: 1px solid var(--border-color);
            animation: fadeInUp 0.5s ease-out;
        }

        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* SEARCH BAR — lets an admin jump straight to a ticket instead of
           scanning the whole list, same pattern used on the Knowledge
           Base page. */
        .search-bar {
            margin-bottom: 22px;
            position: relative;
            max-width: 420px;
        }

        .search-bar input {
            width: 100%;
            padding: 13px 16px 13px 44px;
            border: 1.5px solid var(--border-color);
            border-radius: var(--radius-md);
            font-size: 0.95rem;
            font-family: inherit;
            color: var(--dark-brown);
            background: var(--soft-cream);
            transition: var(--transition);
        }

        .search-bar input:focus {
            outline: none;
            border-color: var(--primary-red);
            background: var(--white);
            box-shadow: 0 0 0 3px rgba(198, 69, 62, 0.1);
        }

        .search-bar i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--gray-brown);
        }

        .no-results-row td {
            text-align: center;
            padding: 40px 20px !important;
            color: var(--gray-brown);
            font-weight: 700;
        }

        .table-container {
            width: 100%;
            overflow-x: auto;
            border-radius: var(--radius-lg);
            border: 1px solid var(--border-color);
        }

        .admin-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1000px;
            background: var(--white);
        }

        .admin-table thead th {
            background: var(--primary-red);
            color: var(--white);
            padding: 18px 24px;
            text-align: left;
            font-weight: 800;
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 1px;
            position: sticky;
            top: 0;
        }

        .admin-table tbody td {
            padding: 20px 24px;
            border-bottom: 1px solid var(--border-color);
            vertical-align: middle;
            font-size: 0.95rem;
        }

        .admin-table tbody tr:last-child td {
            border-bottom: none;
        }

        .admin-table tbody tr:hover {
            background: var(--soft-cream);
        }

        /* TABLE CELL UTILITIES */
        .user-id-tag {
            background: var(--light-cream);
            color: var(--dark-brown);
            padding: 6px 12px;
            border-radius: 999px;
            font-weight: 900;
            font-size: 0.75rem;
            border: 1px solid var(--border-color);
        }

        .customer-name {
            font-weight: 700;
            color: var(--dark-brown);
            display: block;
        }

        .contact-link {
            color: var(--gray-brown);
            text-decoration: none;
            font-size: 0.85rem;
            transition: var(--transition);
        }

        .contact-link:hover {
            color: var(--primary-red);
        }

        .msg-preview {
            max-width: 350px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--gray-brown);
            font-style: italic;
        }

        /* TABLE ACTION BUTTONS */
        .action-cell {
            display: flex;
            gap: 12px;
            align-items: center;
        }

        .btn-table-reply {
            background: var(--primary-red);
            color: var(--white);
            padding: 10px 20px;
            border-radius: var(--radius-sm);
            font-weight: 800;
            text-decoration: none;
            font-size: 0.85rem;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
        }

        .btn-table-reply .reply-count {
            background: rgba(255, 255, 255, 0.25);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.75rem;
        }

        .btn-table-reply:hover {
            background: var(--dark-brown);
            transform: scale(1.05);
        }

        .btn-table-delete {
            background: #F8D7DA;
            color: #842029;
            padding: 10px;
            border-radius: var(--radius-sm);
            text-decoration: none;
            transition: var(--transition);
            flex-shrink: 0;
        }

        .btn-table-delete:hover {
            background: #842029;
            color: white;
        }

        /* * MODAL SYSTEM STYLING 
         * EXACTLY AS REQUESTED IN THE IMAGE
         */
        .modal-mask {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            padding: 20px;
            backdrop-filter: blur(4px);
        }

        .modal-window {
            background: var(--white);
            width: 100%;
            max-width: 800px;
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-lg);
            max-height: 90vh;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            animation: modalPop 0.3s cubic-bezier(0.175, 0.885, 0.32, 1.275);
        }

        @keyframes modalPop {
            from { opacity: 0; transform: scale(0.9); }
            to { opacity: 1; transform: scale(1); }
        }

        .modal-header {
            padding: 24px 35px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            border-bottom: 1px solid #F1F1F1;
        }

        .modal-header h2 {
            font-size: 1.5rem;
            color: var(--dark-brown);
            font-weight: 800;
        }

        .btn-close-icon {
            color: var(--gray-brown);
            font-size: 1.4rem;
            cursor: pointer;
            transition: var(--transition);
            background: none;
            border: none;
        }

        .btn-close-icon:hover {
            color: var(--primary-red);
            transform: rotate(90deg);
        }

        .modal-scroll-area {
            padding: 30px 35px;
            overflow-y: auto;
            flex: 1;
        }

        /* ORIGINAL MESSAGE (QUOTE BOX) */
        .original-message-wrapper {
            background: var(--quote-bg);
            padding: 24px;
            border-radius: var(--radius-md);
            border: 1px solid var(--orange-border);
            margin-bottom: 30px;
            position: relative;
        }

        .original-message-wrapper h4 {
            color: #8D4B38;
            font-size: 0.95rem;
            font-weight: 800;
            margin-bottom: 12px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .original-message-wrapper p {
            color: var(--dark-brown);
            font-weight: 600;
            font-size: 1.1rem;
            line-height: 1.6;
            margin-bottom: 20px;
        }

        .original-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            font-size: 0.85rem;
            color: #8D6E63;
            font-weight: 600;
            border-top: 1px solid rgba(141, 75, 56, 0.1);
            padding-top: 15px;
        }

        .original-meta span {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* CONVERSATION HISTORY THREAD */
        .thread-container {
            display: flex;
            flex-direction: column;
            gap: 20px;
            margin-bottom: 35px;
        }

        .bubble {
            padding: 20px 25px;
            border-radius: var(--radius-md);
            border: 1px solid #EEEEEE;
            position: relative;
        }

        .bubble-admin {
            background: var(--admin-bubble);
            align-self: flex-start;
            width: 90%;
        }

        .bubble-customer {
            background: var(--you-bubble);
            border-color: #FFE5E5;
            align-self: flex-end;
            width: 90%;
        }

        .bubble-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 10px;
        }

        .bubble-name {
            font-weight: 800;
            color: var(--dark-brown);
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .bubble-name i {
            color: var(--primary-red);
        }

        .bubble-time {
            color: #9E9E9E;
            font-size: 0.75rem;
            font-weight: 600;
        }

        .bubble-text {
            color: var(--dark-brown);
            font-weight: 500;
            font-size: 0.95rem;
            white-space: pre-wrap;
        }

        /* REPLY FORM */
        .reply-form-section {
            border-top: 1px solid #F1F1F1;
            padding: 30px 35px;
            background: #FAFAFA;
        }

        .reply-form-section h4 {
            color: #8D4B38;
            font-size: 0.95rem;
            font-weight: 800;
            margin-bottom: 15px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .reply-textarea {
            width: 100%;
            height: 140px;
            padding: 20px;
            border-radius: var(--radius-md);
            border: 1px solid var(--orange-border);
            background: #FFFDF9;
            font-size: 1rem;
            color: var(--dark-brown);
            resize: none;
            margin-bottom: 20px;
            transition: var(--transition);
        }

        .reply-textarea:focus {
            outline: none;
            border-color: var(--primary-red);
            background: var(--white);
            box-shadow: 0 0 0 4px rgba(198, 69, 62, 0.05);
        }

        .modal-footer-actions {
            display: flex;
            justify-content: flex-end;
            gap: 15px;
        }

        .btn-modal-close {
            background: #FFF5E6;
            color: #8D4B38;
            border: 2px solid transparent;
        }

        .btn-modal-close:hover {
            background: #FFE0B2;
            color: #5E1F13;
        }

        .btn-modal-send {
            background: var(--primary-red);
            color: var(--white);
            padding: 14px 35px;
            box-shadow: 0 6px 12px rgba(198, 69, 62, 0.2);
        }

        .btn-modal-send:hover {
            background: var(--dark-brown);
            transform: translateY(-2px);
            box-shadow: 0 8px 20px rgba(94, 31, 19, 0.25);
        }

        /* EMPTY STATES */
        .empty-inbox {
            text-align: center;
            padding: 80px 20px;
        }

        .empty-inbox i {
            font-size: 4rem;
            color: var(--border-color);
            margin-bottom: 20px;
        }

        .empty-inbox h3 {
            font-weight: 800;
            color: var(--gray-brown);
        }

        /* RESPONSIVE DESIGN UTILITIES */
        @media (max-width: 1024px) {
            .admin-container { padding: 20px; }
            .page-title h1 { font-size: 1.8rem; }
        }

        @media (max-width: 768px) {
            .page-header { flex-direction: column; align-items: flex-start; gap: 20px; }
            .btn-refresh { width: 100%; }
            .search-bar { max-width: 100%; }
            .modal-window { max-width: 95%; }
            .modal-header, .modal-scroll-area, .reply-form-section { padding: 20px; }
            .bubble-admin, .bubble-customer { width: 100%; }
            .original-meta { flex-direction: column; gap: 10px; }
        }

        /* Below 480px the table becomes a stack of cards. Each cell now
           shows its column name above the value (via data-label), so a
           row on mobile reads "Sender Name: John Doe" instead of just a
           bare value with no context. */
        @media (max-width: 480px) {
            .admin-table thead { display: none; }
            .admin-table tbody td { display: block; width: 100%; padding: 14px 16px; text-align: left; border-bottom: 1px solid #F5EEE6; }
            .admin-table tbody td:last-child { border-bottom: none; }
            .admin-table tbody td::before {
                content: attr(data-label);
                display: block;
                font-size: 0.7rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: 0.5px;
                color: var(--primary-red);
                margin-bottom: 4px;
            }
            .admin-table tbody td[data-label=""]::before { display: none; }
            .admin-table tbody tr { display: block; border: 1px solid var(--border-color); margin-bottom: 15px; border-radius: 12px; overflow: hidden; }
            .msg-preview { max-width: 100%; white-space: normal; }
            .action-cell { flex-direction: column; align-items: stretch; }
            .btn-table-reply { justify-content: center; }
            .btn-table-delete { text-align: center; }
            .btn-modal-send { width: 100%; }
        }

    </style>
</head>
<body>

<?php include 'admin_header.php'; ?>

<main class="admin-container">

    <header class="page-header">
        <div class="page-title">
            <h1><i class="fa-solid fa-comments"></i> Customer Support</h1>
            <p>Managing inquiries for Six Origins Cafe branches</p>
        </div>
        <a href="admin_contacts.php" class="btn btn-refresh">
            <i class="fa-solid fa-arrows-rotate"></i> Refresh Database
        </a>
    </header>

    <?php if (!empty($flash_msg)): ?>
        <div style="padding: 18px 24px; border-radius: var(--radius-md); margin-bottom: 25px; font-weight: 700; border-left: 6px solid <?php echo ($flash_type == 'success' ? 'var(--success-green)' : 'var(--error-red)'); ?>; background: var(--white); box-shadow: var(--shadow-sm);">
            <i class="fa-solid <?php echo ($flash_type == 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'); ?>" style="color: <?php echo ($flash_type == 'success' ? 'var(--success-green)' : 'var(--error-red)'); ?>; margin-right: 12px;"></i>
            <?php echo htmlspecialchars($flash_msg); ?>
        </div>
    <?php endif; ?>

    <section class="content-panel">
        <?php
            $select_message = mysqli_query($conn, "SELECT * FROM `message` ORDER BY id DESC") or die('query failed');
            if(mysqli_num_rows($select_message) > 0):
        ?>
        <div class="search-bar">
            <i class="fa-solid fa-search"></i>
            <input type="text" id="contactSearch" placeholder="Search by name, email, or message..." onkeyup="filterContacts()">
        </div>
        <div class="table-container">
            <table class="admin-table" id="contactsTable">
                <thead>
                    <tr>
                        <th>Ref ID</th>
                        <th>Sender Name</th>
                        <th>Contact Email</th>
                        <th>Inquiry Preview</th>
                        <th>Management</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                        while($fetch_message = mysqli_fetch_assoc($select_message)): 
                            $msg_id = (int)$fetch_message['id'];
                            
                            // Check reply count for badge
                            $reply_count_q = mysqli_query($conn, "SELECT COUNT(*) as total FROM `message_replies` WHERE message_id = '$msg_id'");
                            $rc = mysqli_fetch_assoc($reply_count_q);
                    ?>
                    <tr class="contact-row">
                        <td data-label="Ref ID"><span class="user-id-tag">#<?php echo $fetch_message['user_id']; ?></span></td>
                        <td data-label="Sender Name"><span class="customer-name"><?php echo htmlspecialchars($fetch_message['name']); ?></span></td>
                        <td data-label="Contact Email"><a href="mailto:<?php echo $fetch_message['email']; ?>" class="contact-link"><?php echo htmlspecialchars($fetch_message['email']); ?></a></td>
                        <td data-label="Inquiry Preview"><div class="msg-preview"><?php echo mb_strimwidth($fetch_message['message'], 0, 45, "..."); ?></div></td>
                        <td data-label="" class="action-cell">
                            <a href="admin_contacts.php?reply=<?php echo $msg_id; ?>" class="btn-table-reply">
                                <i class="fa-solid fa-reply"></i> View &amp; Reply
                                <?php if ($rc['total'] > 0): ?><span class="reply-count"><?php echo $rc['total']; ?></span><?php endif; ?>
                            </a>
                            <a href="admin_contacts.php?delete=<?php echo $msg_id; ?>" class="btn-table-delete" onclick="return confirm('Delete this entire ticket?');" title="Delete Permanent">
                                <i class="fa-solid fa-trash-can"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php else: ?>
        <div class="empty-inbox">
            <i class="fa-solid fa-mug-saucer"></i>
            <h3>The support queue is empty!</h3>
            <p>New inquiries will appear here when customers contact the cafe.</p>
        </div>
        <?php endif; ?>
    </section>

</main>

<?php if ($reply_modal_data): ?>
<div class="modal-mask">
    <div class="modal-window">
        
        <header class="modal-header">
            <h2>Conversation with <?php echo htmlspecialchars($reply_modal_data['name']); ?></h2>
            <a href="admin_contacts.php" class="btn-close-icon" aria-label="Close modal">✕</a>
        </header>

        <div class="modal-scroll-area">
            
            <div class="original-message-wrapper">
                <h4><i class="fa-solid fa-quote-left"></i> Your Original Message</h4>
                <p><?php echo nl2br(htmlspecialchars($reply_modal_data['message'])); ?></p>
                
                <div class="original-meta">
                    <span><i class="fa-solid fa-envelope"></i> <?php echo htmlspecialchars($reply_modal_data['email']); ?></span>
                    <span><i class="fa-solid fa-phone"></i> <?php echo htmlspecialchars($reply_modal_data['number']); ?></span>
                    <span><i class="fa-solid fa-hashtag"></i> Ticket #<?php echo $reply_modal_data['id']; ?></span>
                </div>
            </div>

            <div class="thread-container">
                <?php 
                    if ($replies_q && mysqli_num_rows($replies_q) > 0): 
                        while ($r = mysqli_fetch_assoc($replies_q)): 
                            $is_admin = (bool)$r['admin_id'];
                ?>
                <div class="bubble <?php echo $is_admin ? 'bubble-admin' : 'bubble-customer'; ?>">
                    <div class="bubble-header">
                        <span class="bubble-name">
                            <i class="fa-solid <?php echo $is_admin ? 'fa-user-tie' : 'fa-user'; ?>"></i>
                            <?php echo $is_admin ? 'Admin' : 'You'; ?>
                        </span>
                        <span class="bubble-time"><i class="fa-solid fa-clock-rotate-left"></i> <?php echo $r['created_at']; ?></span>
                    </div>
                    <div class="bubble-text"><?php echo nl2br(htmlspecialchars($r['reply'])); ?></div>
                </div>
                <?php 
                        endwhile; 
                    endif; 
                ?>
            </div>

            <section class="reply-section-container">
                <form action="admin_contacts.php" method="post">
                    <input type="hidden" name="message_id" value="<?php echo $reply_modal_data['id']; ?>">
                    
                    <label for="reply_text" class="reply-label"><i class="fa-solid fa-reply-all"></i> Compose Your Response</label>
                    <textarea 
                        name="reply_text" 
                        id="reply_text" 
                        class="reply-textarea" 
                        placeholder="Type your reply here... (Customer will receive an email notification automatically)" 
                        required></textarea>
                    
                    <div class="modal-footer-actions">
                        <a href="admin_contacts.php" class="btn btn-modal-close">✕ Close Window</a>
                        <button type="submit" name="send_reply" class="btn btn-modal-send">
                            <i class="fa-solid fa-paper-plane"></i> Send Reply Now
                        </button>
                    </div>
                </form>
            </section>

        </div> </div> </div> <?php endif; ?>

<?php include 'admin_footer.php'; ?>

<script type="text/javascript">
    // Auto-scroll the chat thread to the bottom when replying
    document.addEventListener('DOMContentLoaded', function() {
        const scrollArea = document.querySelector('.modal-scroll-area');
        if(scrollArea) {
            scrollArea.scrollTop = scrollArea.scrollHeight;
        }
    });

    // Close modal on escape keypress
    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            window.location.href = 'admin_contacts.php';
        }
    });

    // Search/filter the inquiries table by name, email, or message text
    function filterContacts() {
        const query = document.getElementById('contactSearch').value.toLowerCase();
        const rows = document.querySelectorAll('#contactsTable tbody tr.contact-row');
        let visibleCount = 0;

        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            if (text.includes(query)) {
                row.style.display = '';
                visibleCount++;
            } else {
                row.style.display = 'none';
            }
        });

        const tbody = document.querySelector('#contactsTable tbody');
        let noResultsRow = tbody.querySelector('.no-results-row');
        if (visibleCount === 0) {
            if (!noResultsRow) {
                noResultsRow = document.createElement('tr');
                noResultsRow.className = 'no-results-row';
                noResultsRow.innerHTML = '<td colspan="5"><i class="fa-solid fa-magnifying-glass" style="margin-right:8px;"></i>No inquiries match your search.</td>';
                tbody.appendChild(noResultsRow);
            }
        } else if (noResultsRow) {
            noResultsRow.remove();
        }
    }
</script>
<?php include 'admin_chatbot.php'; ?>
<script src="Js/script1.js"></script>

</body>
</html>