<?php
include 'config.php';

$admin_id = $_SESSION['admin_id'];
if(!isset($admin_id)){
   header('location:login.php');
   exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="utf-8">
   <meta name="viewport" content="width=device-width,initial-scale=1">
   <title>Six Origins Cafe — Admin Dashboard</title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link rel="icon" type="image/png" href="images/logos.png">
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
        --max-width: 1400px;
      }
      * {
         box-sizing: border-box;
         margin: 0;
         padding: 0;
         font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      }
      body {
         min-height: 100vh;
         display: flex;
         flex-direction: column;
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         color: var(--dark-brown);
         margin: 0;
         padding: 0;
      }
      .page {
         flex: 1;
         max-width: var(--max-width);
         margin: 28px auto 0;
         padding: 0 24px 40px;
         width: 100%;
      }

      /* ===== Welcome banner ===== */
      .dashboard-header {
         position: relative;
         overflow: hidden;
         display: flex;
         align-items: center;
         justify-content: space-between;
         gap: 20px;
         margin-bottom: 28px;
         padding: 28px 32px;
         border-radius: 22px;
         background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 60%, var(--dark-brown) 140%);
         color: #FFFBF7;
         box-shadow: 0 14px 34px rgba(198, 69, 62, 0.28);
         animation: slideDown 0.4s ease;
         flex-wrap: wrap;
      }
      .dashboard-header::before,
      .dashboard-header::after {
         content: '';
         position: absolute;
         border-radius: 50%;
         background: rgba(255, 255, 255, 0.08);
         pointer-events: none;
      }
      .dashboard-header::before { width: 260px; height: 260px; top: -120px; right: 18%; }
      .dashboard-header::after  { width: 160px; height: 160px; bottom: -80px; right: -30px; }
      @keyframes slideDown {
         from { opacity: 0; transform: translateY(-20px);}
         to { opacity: 1; transform: translateY(0);}
      }
      .dashboard-header .title-section { flex: 1; position: relative; z-index: 1; }
      .dashboard-header .title {
         font-size: 2.2rem;
         font-weight: 900;
         color: #FFFBF7;
         letter-spacing: -0.5px;
         display: flex;
         align-items: center;
         gap: 14px;
      }
      .dashboard-header .title i {
         font-size: 2rem;
         color: #FFFBF7;
         width: 58px; height: 58px;
         display: inline-grid; place-items: center;
         background: rgba(255, 255, 255, 0.18);
         border: 1.5px solid rgba(255, 255, 255, 0.3);
         border-radius: 16px;
         animation: float 3s ease-in-out infinite;
      }
      @keyframes float {
         0%, 100% { transform: translateY(0px);}
         50% { transform: translateY(-6px);}
      }
      .dashboard-header .sub {
         color: rgba(255, 251, 247, 0.92);
         font-size: 0.98rem;
         margin-top: 12px;
         display: flex;
         align-items: flex-start;
         gap: 8px;
         font-weight: 500;
         line-height: 1.5;
      }
      .dashboard-header .sub > i {
         color: #FFF2E0;
         font-size: 1.05rem;
         margin-top: 3px;
      }
      .tooltip {
         position: relative;
         display: inline-block;
         padding: 2px 10px;
         margin-left: 4px;
         border-radius: 99px;
         background: rgba(255, 255, 255, 0.18);
         font-size: 0.82rem;
         font-weight: 700;
         cursor: help;
      }
      .tooltip::after {
         content: attr(data-tooltip);
         position: absolute;
         left: 50%; bottom: calc(100% + 8px);
         transform: translateX(-50%) translateY(4px);
         white-space: nowrap;
         padding: 6px 10px;
         border-radius: 8px;
         background: var(--dark-brown);
         color: #fff;
         font-size: 0.78rem;
         opacity: 0;
         pointer-events: none;
         transition: var(--transition);
      }
      .tooltip:hover::after { opacity: 1; transform: translateX(-50%) translateY(0); }

      .actions { display: flex; gap: 12px; flex-wrap: wrap; position: relative; z-index: 1; }
      .action-btn {
         text-decoration: none;
         padding: 13px 24px;
         background: #FFFBF7;
         color: var(--primary-red);
         border-radius: var(--radius);
         font-weight: 800;
         font-size: 0.98rem;
         box-shadow: 0 6px 16px rgba(61, 22, 8, 0.2);
         transition: var(--transition);
         display: inline-flex;
         align-items: center;
         gap: 8px;
         border: 1.5px solid transparent;
         cursor: pointer;
         position: relative;
         overflow: hidden;
      }
      .action-btn::before {
         content: '';
         position: absolute;
         top: 0; left: -100%; width: 100%; height: 100%;
         background: linear-gradient(90deg, transparent, rgba(198,69,62,0.15), transparent);
         transition: left 0.5s ease;
      }
      .action-btn:hover::before { left: 100%;}
      .action-btn:hover {
         background: var(--dark-brown);
         color: #fff;
         transform: translateY(-2px);
         box-shadow: 0 10px 22px rgba(61, 22, 8, 0.3);
      }
      .action-btn.secondary {
         background: rgba(255, 255, 255, 0.15);
         border-color: rgba(255, 255, 255, 0.45);
         color: #FFFBF7;
         box-shadow: none;
      }
      .action-btn.secondary:hover {
         background: #FFFBF7;
         color: var(--primary-red);
         border-color: #FFFBF7;
      }

      /* ===== Cards ===== */
      .grid {
         display: grid;
         grid-template-columns: repeat(4, 1fr);
         gap: 20px;
         margin-bottom: 32px;
      }
      .card {
         background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
         border-radius: 20px;
         padding: 24px;
         box-shadow: var(--shadow);
         border: 1.5px solid #F0E6D8;
         display: flex;
         flex-direction: column;
         justify-content: space-between;
         gap: 18px;
         transition: var(--transition);
         animation: slideUp 0.45s ease backwards;
         position: relative;
         overflow: hidden;
         text-decoration: none;
         color: inherit;
      }
      .card:nth-child(2) { animation-delay: 0.05s; }
      .card:nth-child(3) { animation-delay: 0.10s; }
      .card:nth-child(4) { animation-delay: 0.15s; }
      .card:nth-child(5) { animation-delay: 0.20s; }
      .card:nth-child(6) { animation-delay: 0.25s; }
      .card:nth-child(7) { animation-delay: 0.30s; }
      .card:nth-child(8) { animation-delay: 0.35s; }
      @keyframes slideUp {
         from { opacity: 0; transform: translateY(20px);}
         to { opacity: 1; transform: translateY(0);}
      }
      .card::before {
         content: '';
         position: absolute;
         top: 0; left: 0; width: 100%; height: 4px;
         background: linear-gradient(90deg, var(--primary-red), #B83A34);
         opacity: 0;
         transition: opacity 0.3s ease;
      }
      .card::after {
         content: '';
         position: absolute;
         width: 120px; height: 120px;
         top: -50px; right: -40px;
         border-radius: 50%;
         background: rgba(198, 69, 62, 0.06);
         transition: var(--transition);
      }
      .card:hover::before { opacity: 1; }
      .card:hover::after { transform: scale(1.4); background: rgba(198, 69, 62, 0.1); }
      .card:hover {
         box-shadow: var(--shadow-hover);
         border-color: var(--primary-red);
         transform: translateY(-4px);
      }
      .card:focus-visible { outline: 3px solid rgba(198, 69, 62, 0.45); outline-offset: 3px; }

      .card-icon {
         font-size: 1.5rem;
         margin-bottom: 16px;
         color: var(--primary-red);
         width: 56px; height: 56px;
         display: inline-grid; place-items: center;
         border-radius: 16px;
         background: linear-gradient(135deg, rgba(198,69,62,0.14) 0%, rgba(198,69,62,0.06) 100%);
         position: relative; z-index: 1;
         transition: var(--transition);
      }
      .card:hover .card-icon { background: var(--primary-red); color: #fff; transform: rotate(-6deg) scale(1.05); }
      .card .num {
         font-size: 2.8rem; font-weight: 900; color: var(--dark-brown);
         line-height: 1;
         letter-spacing: -1px;
         position: relative; z-index: 1;
      }
      .card .label {
         margin-top: 14px; font-weight: 800; color: var(--dark-brown);
         display: inline-flex; align-items: center; gap: 8px;
         padding: 8px 14px; border-radius: 10px;
         background: linear-gradient(135deg, rgba(198,69,62,0.1) 0%, rgba(198,69,62,0.05) 100%);
         width: fit-content; font-size: 0.95rem; letter-spacing: 0.3px;
         position: relative; z-index: 1;
      }
      .card .label i { color: var(--primary-red); font-size: 0.85em; }
      .card .meta {
         padding-top: 14px;
         border-top: 1.5px dashed #EBDCCB;
         display: flex; justify-content: space-between; align-items: center; gap: 10px;
         color: var(--gray-brown); font-size: 0.9rem; font-weight: 600;
         position: relative; z-index: 1;
      }
      .card .meta .muted { display: inline-flex; align-items: center; gap: 8px; }
      .card .meta .muted i { color: var(--primary-red); }
      .card .meta .trend {
         padding: 5px 11px; border-radius: 8px; font-weight: 700; font-size: 0.82rem;
         display: flex; align-items: center; gap: 5px; white-space: nowrap;
      }
      .card .meta .trend.up {
         background: rgba(198,69,62,0.15); color: var(--primary-red);
      }
      .card .meta .trend.down {
         background: rgba(102,76,71,0.15); color: var(--gray-brown);
      }

      @media (max-width:1100px){ .grid{grid-template-columns:repeat(2,1fr);} }
      @media (max-width:900px){
         .page{padding:0 18px 36px;}
         .dashboard-header{flex-direction:column; align-items:flex-start; padding:24px 22px;}
         .dashboard-header .title-section{width:100%;}
         .actions{width:100%;}
         .action-btn{flex:1; justify-content:center;}
      }
      @media (max-width:640px){
         .page{margin:18px auto 0; padding:0 14px 32px;}
         .dashboard-header .title{font-size:1.5rem; gap:10px;}
         .dashboard-header .title i{width:46px; height:46px; font-size:1.5rem;}
         .action-btn{flex:1; font-size:0.85rem; padding:11px 16px;}
         .card{padding:20px;}
         .card .num{font-size:2.3rem;}
         .grid{grid-template-columns:1fr; gap:14px;}
      }
      @media (prefers-reduced-motion: reduce) {
         *, *::before, *::after { animation: none !important; transition: none !important; }
      }
   </style>
</head>
<body>
<?php include 'admin_header.php'; ?>
<div class="page">
   <!-- Dashboard Header -->
   <div class="dashboard-header">
      <div class="title-section">
         <div class="title">
            <i class="fa-solid fa-chart-line"></i> Admin Dashboard
         </div>
         <div class="sub">
            <i class="fa-solid fa-check-circle"></i>
            <span>
               <strong>Welcome back!</strong> Here's your store performance overview. 
               <span class="tooltip" data-tooltip="Last updated just now">Last synced: just now</span>
            </span>
         </div>
      </div>
      <div class="actions">
         <a href="admin_products.php" class="action-btn">
            <i class="fa-solid fa-plus-circle"></i> Add Product
         </a>
         <a href="admin_orders.php" class="action-btn secondary">
            <i class="fa-solid fa-receipt"></i> View Orders
         </a>
      </div>
   </div>

   <!-- Key Metrics Grid -->
   <div class="grid">
      <a class="card" href="admin_orders.php" title="Go to Orders">
         <?php
            $total_pendings = 0;
            $select_pending = mysqli_query($conn, "SELECT total_price FROM `orders` WHERE payment_status IN ('pending', 'accepted', 'preparing', 'out for delivery')") or die('query failed');
            if(mysqli_num_rows($select_pending) > 0){
               while($fetch_pendings = mysqli_fetch_assoc($select_pending)){
                  $total_pendings += $fetch_pendings['total_price'];
               }
            }
         ?>
         <div>
            <i class="fa-solid fa-hourglass-half card-icon"></i>
            <div class="num">₱<?php echo number_format($total_pendings, 0); ?></div>
            <div class="label">
              <i class="fa-solid fa-arrow-up"></i> In Progress
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-box"></i> Pending Orders</span>
            <span class="trend up"><i class="fa-solid fa-fire"></i> Active</span>
         </div>
      </a>
      <a class="card" href="admin_orders.php" title="Go to Orders">
         <?php
            $total_completed = 0;
            $select_completed = mysqli_query($conn, "SELECT total_price FROM `orders` WHERE payment_status = 'completed'") or die('query failed');
            if(mysqli_num_rows($select_completed) > 0){
               while($fetch_completed = mysqli_fetch_assoc($select_completed)){
                  $total_completed += $fetch_completed['total_price'];
               }
            }
         ?>
         <div>
            <i class="fa-solid fa-check-circle card-icon"></i>
            <div class="num">₱<?php echo number_format($total_completed, 0); ?></div>
            <div class="label">
              <i class="fa-solid fa-piggy-bank"></i> Collected
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-chart-bar"></i> Total Revenue</span>
            <span class="trend up"><i class="fa-solid fa-arrow-up"></i> All time</span>
         </div>
      </a>
      <a class="card" href="admin_orders.php" title="Go to Orders">
         <?php
            $select_orders = mysqli_query($conn, "SELECT * FROM `orders` WHERE payment_status != 'cancelled'") or die('query failed');
            $number_of_orders = mysqli_num_rows($select_orders);
         ?>
         <div>
            <i class="fa-solid fa-boxes-stacked card-icon"></i>
            <div class="num"><?php echo $number_of_orders; ?></div>
            <div class="label">
              <i class="fa-solid fa-receipt"></i> Orders
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-list"></i> Transactions</span>
            <span><?php echo date('M Y'); ?></span>
         </div>
      </a>
      <a class="card" href="admin_users.php" title="Go to Users">
         <?php
            $select_users = mysqli_query($conn, "SELECT * FROM `users` WHERE user_type = 'user'") or die('query failed');
            $number_of_users = mysqli_num_rows($select_users);
         ?>
         <div>
            <i class="fa-solid fa-people-group card-icon"></i>
            <div class="num"><?php echo $number_of_users; ?></div>
            <div class="label">
              <i class="fa-solid fa-user-check"></i> Customers
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-users"></i> Registered Users</span>
            <span class="trend up"><i class="fa-solid fa-rocket"></i> Growing</span>
         </div>
      </a>
      <a class="card" href="admin_products.php" title="Go to Products">
         <?php
            $select_products = mysqli_query($conn, "SELECT * FROM `products`") or die('query failed');
            $number_of_products = mysqli_num_rows($select_products);
         ?>
         <div>
            <i class="fa-solid fa-package card-icon"></i>
            <div class="num"><?php echo $number_of_products; ?></div>
            <div class="label">
              <i class="fa-solid fa-tag"></i> Catalog
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-store"></i> Active Items</span>
            <span><?php echo date('M d'); ?></span>
         </div>
      </a>
      <a class="card" href="admin_contacts.php" title="Go to Feedback">
         <?php
            $select_messages = mysqli_query($conn, "SELECT * FROM `message`") or die('query failed');
            $number_of_messages = mysqli_num_rows($select_messages);
         ?>
         <div>
            <i class="fa-solid fa-envelope card-icon"></i>
            <div class="num"><?php echo $number_of_messages; ?></div>
            <div class="label">
              <i class="fa-solid fa-message"></i> Inquiries
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-comments"></i> Messages</span>
            <span class="trend down"><i class="fa-solid fa-exclamation"></i> Review</span>
         </div>
      </a>
      <a class="card" href="admin_users.php" title="Go to Users">
         <?php
            $select_admins = mysqli_query($conn, "SELECT * FROM `users` WHERE user_type = 'admin'") or die('query failed');
            $number_of_admins = mysqli_num_rows($select_admins);
         ?>
         <div>
            <i class="fa-solid fa-crown card-icon"></i>
            <div class="num"><?php echo $number_of_admins; ?></div>
            <div class="label">
              <i class="fa-solid fa-shield"></i> Staff
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-user-tie"></i> Admins</span>
            <span><?php echo date('M Y'); ?></span>
         </div>
      </a>
      <a class="card" href="admin_users.php" title="Go to Users">
         <?php
            $select_account = mysqli_query($conn, "SELECT * FROM `users`") or die('query failed');
            $number_of_account = mysqli_num_rows($select_account);
         ?>
         <div>
            <i class="fa-solid fa-database card-icon"></i>
            <div class="num"><?php echo $number_of_account; ?></div>
            <div class="label">
              <i class="fa-solid fa-layer-group"></i> Total
            </div>
         </div>
         <div class="meta">
            <span class="muted"><i class="fa-solid fa-users-gear"></i> All Accounts</span>
            <span class="trend up"><i class="fa-solid fa-arrow-up"></i> <?php echo $number_of_users + $number_of_admins; ?> active</span>
         </div>
      </a>
   </div>
</div>
<?php include 'admin_chatbot.php'; ?>
<?php include 'admin_footer.php'; ?>
</body>
</html>