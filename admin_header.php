<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include 'config.php';
$profile_image = 'default.png';
if (isset($_SESSION['admin_email'])) {
    $admin_email = $_SESSION['admin_email'];
    $stmt = $conn->prepare("SELECT profile_image FROM users WHERE email = ?");
    if ($stmt) {
        $stmt->bind_param("s", $admin_email);
        $stmt->execute();
        $select = $stmt->get_result();
        if ($select && $select->num_rows > 0) {
            $fetch = $select->fetch_assoc();
            $candidate_image = (string)($fetch['profile_image'] ?? '');
            if ($candidate_image !== '' && !str_contains($candidate_image, '..') && !str_starts_with($candidate_image, '/') && file_exists('images/' . $candidate_image)) {
                $profile_image = $candidate_image;
            }
        }
        $stmt->close();
    }
}

// Get count of pending top-up requests
$pending_topups = 0;
$stmt = $conn->prepare("SELECT COUNT(*) AS cnt FROM topup_requests WHERE status = ?");
if ($stmt) {
    $status = 'pending';
    $stmt->bind_param("s", $status);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result && $result->num_rows > 0) {
        $data = $result->fetch_assoc();
        $pending_topups = intval($data['cnt']);
    }
    $stmt->close();
}

// which page is open, so the matching nav item can be highlighted
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width,initial-scale=1" />
<title>Six Origins Cafe — Admin</title>
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
}
body { margin: 0; }
* { box-sizing: border-box; font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial; }
html { -webkit-text-size-adjust: 100%; }

.admin-header {
    background: var(--dark-brown);
    box-shadow: 0 8px 32px rgba(94, 31, 19, 0.25);
    position: sticky;
    top: 0;
    z-index: 99;
    border-bottom: 5px solid var(--primary-red);
    padding: 16px 0;
}
.admin-header-inner {
    max-width: 1400px;
    margin: 0 auto;
    display: flex; gap: 24px; align-items: center;
    justify-content: space-between;
    padding: 0 24px; width: 100%;
    min-width: 0;
}
.brand {
    display: flex; align-items: center; gap: 14px;
    text-decoration: none; flex-shrink: 0;
}
.brand .logo-img {
    width: 56px; height: 56px; object-fit: contain;
    background: rgba(255,255,255,0.15); padding: 8px;
    border-radius: 12px; border: 2px solid rgba(255,255,255,0.2);
}
.brand .logo-main { color: #FFFBF7; font-size: 1.8rem; font-weight: 900; line-height: 1.1; }
.brand .logo-sub { color: rgba(255,251,247,0.9); font-size: 0.8rem; font-weight: 700; letter-spacing: 1.3px; text-transform: uppercase; }

.admin-nav {
    display: flex; gap: 8px; align-items: center; flex: 1; justify-content: center; flex-wrap: nowrap;
    min-width: 0;
}
.nav-link, .nav-dropdown-toggle {
    text-decoration: none;
    color: rgba(255,251,247,0.95);
    background: rgba(255,255,255,0.15);
    padding: 11px 18px;
    font-weight: 700; font-size: 0.95rem;
    border-radius: var(--radius);
    transition: var(--transition);
    position: relative;
    overflow: hidden;
    border: 1.5px solid rgba(255,255,255,0.2);
    display: inline-flex;
    align-items: center;
    gap: 8px;
    cursor: pointer;
    white-space: nowrap;
}
.nav-link:hover, .nav-dropdown-toggle:hover, .nav-link.active, .nav-dropdown-toggle.active {
    background: rgba(255,255,255,0.25);
    color: #fff; transform: translateY(-2px);
    box-shadow: 0 6px 16px rgba(0,0,0,0.2);
    border-color: rgba(255,255,255,0.4);
}
.nav-dropdown { position: relative; }
.nav-dropdown-menu {
    display: none; position: absolute; left: 0; top: 110%;
    min-width: 190px; background: #FFF2E0;
    box-shadow: var(--shadow); border-radius: 14px; z-index: 20;
    flex-direction: column; overflow: hidden; border: 1.5px solid #F0E6D8;
    animation: ahFade 0.2s;
}
.nav-dropdown-menu a {
    padding: 12px 20px; display: flex; align-items: center;
    color: var(--dark-brown); font-weight: 700; font-size: 1em; gap: 7px; text-decoration: none; background: none; border: none;
    transition: background 0.2s, color 0.2s; white-space: nowrap;
}
.nav-dropdown-menu a:hover { background: var(--primary-red); color: #fff; }
.nav-dropdown.open .nav-dropdown-menu { display: flex; }

.admin-header-actions {
    display: flex; gap: 10px; align-items: center; flex-shrink: 0;
}
.icon-btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    padding: 11px 16px; border-radius: var(--radius);
    background: rgba(255,255,255,0.15);
    color: rgba(255,251,247,0.95);
    border: 1.5px solid rgba(255,255,255,0.2);
    cursor: pointer;
    font-weight: 700; font-size: 1rem; font-family: inherit;
    transition: var(--transition); position: relative; text-decoration: none;
}
.icon-btn:hover { background: rgba(255,255,255,0.25); color: #fff; border-color: rgba(255,255,255,0.4); transform: translateY(-2px); box-shadow: 0 6px 16px rgba(0,0,0,0.2); }
.icon-btn .badge { background: #FFF2E0; color: var(--dark-brown); padding: 4px 10px; border-radius: 99px; font-size: 0.75em; font-weight: 800; min-width: 24px; text-align: center; }

#menu-toggle { display: none; }

.admin-profile { position: relative; }
.profile-btn {
    display: flex; align-items: center; gap: 10px; border-radius: 99px;
    padding: 8px 14px; background: rgba(255,255,255,0.15); border: 1.5px solid rgba(255,255,255,0.2);
    cursor: pointer; color: rgba(255,251,247,0.95); font-weight: 700; transition: var(--transition);
}
.profile-btn:hover { background: rgba(255,255,255,0.25); border-color: rgba(255,255,255,0.4); transform: translateY(-2px); }
.profile-btn .avatar {
    width: 40px; height: 40px; border-radius: 99px; object-fit: cover;
    border: 2.5px solid rgba(255,251,247,0.95); box-shadow: 0 4px 12px rgba(0,0,0,0.2); transition: var(--transition);
}
.profile-btn .profile-name { font-weight: 700; color: rgba(255,251,247,0.95); font-size: 0.95rem; text-transform: capitalize; max-width: 120px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.profile-btn .caret { font-size: 0.75rem; color: rgba(255,251,247,0.8); }

.profile-menu {
    position: absolute; right: 0; top: 70px; width: 300px; max-width: calc(100vw - 24px);
    background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
    border-radius: var(--radius); box-shadow: 0 12px 40px rgba(94,31,19,0.2);
    border: 1.5px solid #F0E6D8; padding: 18px; display: none; z-index: 90;
    animation: ahSlideDown 0.3s cubic-bezier(0.4,0,0.2,1);
    max-height: calc(100vh - 110px); max-height: calc(100dvh - 110px); overflow-y: auto;
}
.profile-menu.active { display: block; }
.profile-menu .profile-info {
    display: flex; gap: 12px; align-items: center; padding-bottom: 14px; border-bottom: 1.5px solid #F0E6D8; margin-bottom: 14px;
}
.profile-menu .profile-info img {
    width: 48px; height: 48px; border-radius: 10px; object-fit: cover; border: 2px solid #F0E6D8; background: var(--light-cream); box-shadow: 0 4px 12px rgba(94,31,19,0.15); flex-shrink: 0;
}
.profile-menu .name { font-weight: 800; color: var(--dark-brown); font-size: 1.1em; }
.profile-menu .email { color: var(--gray-brown); font-size: 0.85em; margin-top: 4px; word-break: break-all; font-weight: 500; }
.profile-menu .profile-actions { margin-top: 12px; display: flex; flex-direction: column; gap: 8px; }
.profile-menu .icon-btn {
    color: var(--dark-brown); background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
    border: 1.5px solid #F0E6D8; font-size: 0.95em; padding: 12px 16px; border-radius: 10px; text-align: left; justify-content: flex-start; gap: 12px;
}
.profile-menu .icon-btn:hover {
    background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
    color: #fff; border-color: var(--primary-red); transform: translateX(4px); box-shadow: 0 6px 16px rgba(198,69,62,0.25);
}
.profile-menu .icon-btn i { width: 20px; text-align: center; color: var(--primary-red); }
.profile-menu .icon-btn:hover i { color: #fff; }

/* =====================================================
   NOTIFICATION DROPDOWN
   ===================================================== */
.notification-wrap { position: relative; }

.notif-dropdown {
    position: absolute;
    right: 0;
    top: 46px;
    width: 420px;
    max-width: calc(100vw - 24px);
    background: #FFF2E0;
    border-radius: 18px;
    box-shadow: 0 20px 60px rgba(94, 31, 19, 0.3);
    border: 1.5px solid #F0E6D8;
    padding: 0;
    z-index: 1000;
    animation: ahFade 0.25s;
    display: flex;
    flex-direction: column;
    max-height: 75vh;
    max-height: min(75vh, calc(100dvh - 110px));
    overflow: hidden;
}
.notif-section { display: flex; flex-direction: column; flex: 1; min-height: 0; border-bottom: 1.5px solid #F0E6D8; }
.notif-section:last-child { border-bottom: none; }
.notif-title {
    font-weight: 900; color: var(--primary-red); padding: 14px 18px;
    display: flex; align-items: center; gap: 9px; flex-shrink: 0; font-size: 0.96rem;
    background: linear-gradient(135deg, rgba(198, 69, 62, 0.08) 0%, transparent);
}
.notif-title i { font-size: 1.1em; }
.notif-list { flex: 1; overflow-y: auto; overflow-x: hidden; scrollbar-width: thin; scrollbar-color: #E8D5C4 transparent; }
.notif-list::-webkit-scrollbar { width: 8px; }
.notif-list::-webkit-scrollbar-track { background: transparent; }
.notif-list::-webkit-scrollbar-thumb { background: #E8D5C4; border-radius: 4px; }
.notif-list::-webkit-scrollbar-thumb:hover { background: #D4B5A0; }

.notif-item {
    display: flex; justify-content: space-between; align-items: flex-start;
    padding: 12px 16px; border-bottom: 1px solid #F1E1D4; background: #fff;
    font-size: 0.92em; color: var(--dark-brown); gap: 12px; transition: background 0.2s; min-height: 60px;
}
.notif-item:hover { background: #FFFAF5; }
.notif-item:last-child { border-bottom: none; }
.notif-item strong { color: var(--primary-red); font-weight: 900; display: block; margin-bottom: 3px; }
.notif-item > div:first-child { flex: 1; min-width: 0; word-break: break-word; }
.notif-status {
    font-size: 0.78em; font-weight: 700; padding: 5px 10px; border-radius: 8px; background: #FFFAF5;
    white-space: nowrap; flex-shrink: 0; text-align: center; height: fit-content; margin-top: 2px;
}
.notif-status.completed { background: #C6F1D6; color: #15803d; }
.notif-status.pending { background: #ffecd6; color: #d97706; }
.notif-status.accepted, .notif-status.preparing, .notif-status.delivery { background: #ffe9f0; color: #c026d3; }
.notif-status.cancelled { background: #fee2e2; color: #dc2626; }
.notif-status.out-of-stock { background: linear-gradient(135deg, rgba(217,126,106,0.15) 0%, rgba(217,126,106,0.08) 100%); color: #D97E6A; border: 1px solid rgba(217,126,106,0.25); }
.notif-status.critical { background: linear-gradient(135deg, rgba(198,69,62,0.15) 0%, rgba(198,69,62,0.08) 100%); color: #C6453E; border: 1px solid rgba(198,69,62,0.25); }
.notif-status.low { background: linear-gradient(135deg, rgba(255,152,0,0.15) 0%, rgba(255,152,0,0.08) 100%); color: #FF9800; border: 1px solid rgba(255,152,0,0.25); }

.activity-item {
    display: flex; gap: 12px; padding: 13px 16px; border-bottom: 1px solid #F1E1D4; background: #fff;
    font-size: 0.91em; transition: background 0.2s; align-items: flex-start; min-height: 65px;
}
.activity-item:hover { background: #FFFAF5; }
.activity-item:last-child { border-bottom: none; }
.activity-icon { font-size: 1.1rem; margin-top: 1px; flex-shrink: 0; width: 24px; text-align: center; }
.activity-content { flex: 1; min-width: 0; word-break: break-word; }
.activity-action { font-weight: 800; color: var(--dark-brown); margin-bottom: 4px; font-size: 0.93em; }
.activity-time { font-size: 0.82rem; color: var(--gray-brown); margin-bottom: 3px; font-weight: 600; }
.activity-details { font-size: 0.79rem; color: var(--gray-brown); line-height: 1.4; word-wrap: break-word; white-space: normal; }
.notif-empty { padding: 24px 18px; text-align: center; color: var(--gray-brown); font-weight: 600; font-size: 0.89em; }

@keyframes ahFade { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
@keyframes ahSlideDown { from { opacity: 0; transform: translateY(-12px); } to { opacity: 1; transform: translateY(0); } }
@keyframes bellRing { 0%,100% { transform: rotate(0); } 15% { transform: rotate(14deg); } 30% { transform: rotate(-12deg); } 45% { transform: rotate(8deg); } 60% { transform: rotate(-6deg); } }

/* ---------- User-friendliness ---------- */
.nav-link.active, .nav-dropdown-toggle.active { background: rgba(255,255,255,0.28); border-color: rgba(255,255,255,0.55); color: #fff; }
.nav-link.active::after, .nav-dropdown-toggle.active::after {
    content: ''; position: absolute; left: 22%; right: 22%; bottom: 4px; height: 3px; border-radius: 3px; background: #FFF2E0;
}
.icon-btn.active { background: rgba(255,255,255,0.28); border-color: rgba(255,255,255,0.55); color: #fff; }
.profile-menu .icon-btn.active { background: rgba(198,69,62,0.12); border-color: var(--primary-red); color: var(--dark-brown); transform: none; }
.nav-dropdown-menu a.current { background: rgba(198,69,62,0.12); }

.nav-link:focus-visible, .nav-dropdown-toggle:focus-visible, .icon-btn:focus-visible, .profile-btn:focus-visible, .brand:focus-visible {
    outline: 3px solid #FFF2E0; outline-offset: 2px;
}
.nav-dropdown-menu a:focus-visible { background: var(--primary-red); color: #fff; outline: none; }
.icon-btn.has-new .fa-bell { animation: bellRing 1.4s ease 0.3s 2; transform-origin: 50% 0; }

/* =====================================================
   RESPONSIVE (one clean set of breakpoints)
   ===================================================== */

/* Desktop only: open the Dashboard menu on hover (and bridge the gap so it doesn't flicker) */
@media (hover: hover) and (min-width: 1281px) {
    .nav-dropdown:hover .nav-dropdown-menu { display: flex; }
    .nav-dropdown::after { content: ''; position: absolute; left: 0; right: 0; top: 100%; height: 16px; }
}

/* Tighter header before the nav has to collapse */
@media (max-width: 1400px) {
    .profile-btn .profile-name { display: none; }
    .admin-header-inner { gap: 16px; }
}

/* Collapse the nav into the hamburger menu */
@media (max-width: 1280px) {
    #menu-toggle { display: inline-flex; }
    .admin-header-inner { padding: 0 18px; }
    .admin-nav {
        display: none; position: absolute; top: 100%; left: 0; right: 0;
        flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 8px;
        padding: 14px 18px 18px;
        background: var(--dark-brown);
        box-shadow: 0 16px 30px rgba(94,31,19,0.35);
        border-top: 1px solid rgba(255,255,255,0.15);
        max-height: calc(100vh - 100px); max-height: calc(100dvh - 100px); overflow-y: auto;
    }
    .admin-nav.open { display: flex; animation: ahSlideDown 0.2s ease; }
    .admin-nav .nav-link, .admin-nav .nav-dropdown-toggle { width: 100%; justify-content: flex-start; padding: 14px 18px; }
    .admin-nav .nav-dropdown-toggle .fa-caret-down { margin-left: auto; }
    .admin-nav .nav-dropdown-menu { position: static; margin-top: 8px; width: 100%; animation: none; }
}

/* Phones & small tablets: panels hang from the header, full width, never off-screen */
@media (max-width: 700px) {
    .admin-header { padding: 12px 0; }
    .notification-wrap, .admin-profile { position: static; }
    .notif-dropdown, .profile-menu {
        position: absolute; left: 10px; right: 10px; top: 100%; margin-top: 8px;
        width: auto; max-width: none; min-width: 0;
        max-height: calc(100vh - 110px); max-height: calc(100dvh - 110px);
    }
    .brand .logo-sub { display: none; }
    .brand .logo-main { font-size: 1.3rem; }
    .brand .logo-img { width: 44px; height: 44px; padding: 6px; }
    .admin-header-inner { gap: 10px; padding: 0 14px; }
    .admin-header-actions { gap: 6px; }
    .icon-btn { min-width: 40px; min-height: 40px; padding: 9px 11px; }
    .profile-btn { padding: 6px 8px; gap: 6px; }
    .profile-btn .avatar { width: 34px; height: 34px; }
    .profile-btn .caret { display: none; }
}

/* Very small phones: keep the logo, hide the text so every button fits */
@media (max-width: 480px) {
    .brand .logo-text { display: none; }
    .admin-header-actions { gap: 4px; }
    .icon-btn { min-width: 38px; min-height: 38px; padding: 8px 9px; font-size: 0.9rem; }
    .icon-btn .badge { padding: 2px 7px; }
}

/* No "stuck" hover lift after tapping on touch screens */
@media (hover: none) {
    .nav-link:hover, .nav-dropdown-toggle:hover, .icon-btn:hover, .profile-btn:hover { transform: none; }
}
@media (prefers-reduced-motion: reduce) {
    .icon-btn.has-new .fa-bell { animation: none; }
}
</style>
</head>
<body>
<header class="admin-header" id="adminHeaderWrap" role="banner">
    <div class="admin-header-inner">
        <!-- Brand Logo -->
        <a href="admin_page.php" class="brand" aria-label="Six Origins Admin home">
            <img src="images/logos.png" alt="Six Origins Logo" class="logo-img">
            <div class="logo-text">
                <div class="logo-main">Six Origins</div>
                <div class="logo-sub">Admin Panel</div>
            </div>
        </a>
        <!-- Main Navigation -->
        <nav class="admin-nav" id="adminNav" role="navigation" aria-label="Admin navigation">
            <div class="nav-dropdown">
                <button class="nav-dropdown-toggle <?php echo in_array($current_page, ['admin_page.php','admin_analytics.php'], true) ? 'active' : ''; ?>" id="dashboardDropdown" aria-haspopup="true" aria-expanded="false" type="button">
                    <i class="fa-solid fa-gauge"></i> Dashboard <i class="fa-solid fa-caret-down"></i>
                </button>
                <div class="nav-dropdown-menu" aria-labelledby="dashboardDropdown">
                    <a href="admin_page.php" class="<?php echo $current_page === 'admin_page.php' ? 'current' : ''; ?>"><i class="fa-solid fa-gauge"></i> Main Dashboard</a>
                    <a href="admin_analytics.php" class="<?php echo $current_page === 'admin_analytics.php' ? 'current' : ''; ?>"><i class="fa-solid fa-chart-pie"></i> Analytics</a>
                </div>
            </div>
            <a href="admin_products.php" class="nav-link <?php echo $current_page === 'admin_products.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-box"></i> Products
            </a>
            <a href="admin_orders.php" class="nav-link <?php echo $current_page === 'admin_orders.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-receipt"></i> Orders
            </a>
            <a href="admin_chatbot_kb.php" class="nav-link <?php echo $current_page === 'admin_chatbot_kb.php' ? 'active' : ''; ?>">
                <i class="fa-solid fa-robot"></i> User Chatbot
            </a>
        </nav>
        <!-- Header Actions -->
        <div class="admin-header-actions" role="group" aria-label="Header actions">
            <button id="menu-toggle" class="icon-btn" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="adminNav">
                <i class="fa-solid fa-bars"></i>
            </button>

            <!-- NOTIFICATION BELL -->
            <div class="notification-wrap">
                <button id="notif-bell" class="icon-btn" type="button" aria-label="Notifications" aria-expanded="false" title="Notifications">
                    <i class="fa-solid fa-bell"></i>
                    <span id="notifBadge" class="badge" style="display:none;"></span>
                </button>
                <div id="notifDropdown" class="notif-dropdown" style="display:none;">

                    <!-- Recent Orders Section -->
                    <div class="notif-section">
                        <div class="notif-title"><i class="fa-solid fa-clock"></i> Recent Orders</div>
                        <div id="notifList" class="notif-list">
                            <div class="notif-empty"><i class="fa-solid fa-spinner fa-spin"></i></div>
                        </div>
                    </div>

                    <!-- Low Stock Section -->
                    <div class="notif-section">
                        <div class="notif-title"><i class="fa-solid fa-triangle-exclamation"></i> Low Stock Alert</div>
                        <div id="lowStockList" class="notif-list">
                            <div class="notif-empty"><i class="fa-solid fa-spinner fa-spin"></i></div>
                        </div>
                    </div>

                    <!-- Activity Section -->
                    <div class="notif-section">
                        <div class="notif-title"><i class="fa-solid fa-history"></i> Recent Activity</div>
                        <div id="activityList" class="notif-list">
                            <div class="notif-empty"><i class="fa-solid fa-spinner fa-spin"></i></div>
                        </div>
                    </div>
                </div>
            </div>

            <a class="icon-btn <?php echo $current_page === 'admin_topups.php' ? 'active' : ''; ?>" href="admin_topups.php" title="Top-up requests" aria-label="Top-up requests">
                <i class="fa-solid fa-wallet"></i>
                <?php if ($pending_topups > 0): ?>
                    <span class="badge"><?php echo intval($pending_topups); ?></span>
                <?php endif; ?>
            </a>
            <a class="icon-btn <?php echo $current_page === 'admin_discount.php' ? 'active' : ''; ?>" href="admin_discount.php" title="Discount codes" aria-label="Discount codes">
                <i class="fa-solid fa-ticket"></i>
            </a>
            <div class="admin-profile">
                <button id="profileBtn" class="profile-btn" type="button" aria-haspopup="true" aria-expanded="false" aria-controls="profileMenu" title="Profile menu">
                    <img src="images/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" class="avatar">
                    <span class="profile-name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></span>
                    <i class="fa-solid fa-caret-down caret"></i>
                </button>
                <div id="profileMenu" class="profile-menu" role="menu" aria-hidden="true">
                    <div class="profile-info">
                        <img src="images/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile">
                        <div>
                            <div class="name"><?php echo htmlspecialchars($_SESSION['admin_name'] ?? 'Admin'); ?></div>
                            <div class="email"><?php echo htmlspecialchars($_SESSION['admin_email'] ?? ''); ?></div>
                        </div>
                    </div>
                    <div class="profile-actions">
                        <a href="admin_profile.php" class="icon-btn <?php echo $current_page === 'admin_profile.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-user-pen"></i> Edit Profile
                        </a>
                        <a href="admin_users.php" class="icon-btn <?php echo $current_page === 'admin_users.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-users"></i> Manage User
                        </a>
                        <a href="admin_contacts.php" class="icon-btn <?php echo $current_page === 'admin_contacts.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-envelope"></i> Manage Feedback
                        </a>
                        <a href="admin_inventory.php" class="icon-btn <?php echo $current_page === 'admin_inventory.php' ? 'active' : ''; ?>">
                            <i class="fa-solid fa-boxes"></i> Inventory Management
                        </a>
                        <a href="logout.php" class="icon-btn">
                            <i class="fa-solid fa-right-from-bracket"></i> Logout
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</header>
<script>
// Dashboard dropdown inside the nav
document.querySelectorAll('.nav-dropdown-toggle').forEach(btn => {
    btn.addEventListener('click', function(e){
        e.preventDefault();
        e.stopPropagation();
        const dd = this.closest('.nav-dropdown');
        dd.classList.toggle('open');
        this.setAttribute('aria-expanded', dd.classList.contains('open') ? 'true' : 'false');
        document.querySelectorAll('.nav-dropdown').forEach(x => { if (x !== dd) x.classList.remove('open'); });
    });
});
document.addEventListener('click', function(e){
    document.querySelectorAll('.nav-dropdown').forEach(dd => {
        if (!dd.contains(e.target)) dd.classList.remove('open');
    });
});

// Profile menu
const profileBtn = document.getElementById('profileBtn');
const profileMenu = document.getElementById('profileMenu');
if (profileBtn && profileMenu) {
    profileBtn.addEventListener('click', function(e){
        e.stopPropagation();
        const open = !profileMenu.classList.contains('active');
        profileMenu.classList.toggle('active', open);
        profileMenu.setAttribute('aria-hidden', open ? 'false' : 'true');
        profileBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) document.dispatchEvent(new CustomEvent('admin:close-notif'));
    });
    document.addEventListener('click', function(e){
        if (!profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
            profileMenu.classList.remove('active');
            profileMenu.setAttribute('aria-hidden', 'true');
            profileBtn.setAttribute('aria-expanded', 'false');
        }
    });
    document.addEventListener('keydown', function(e){
        if (e.key === 'Escape') {
            profileMenu.classList.remove('active');
            profileBtn.setAttribute('aria-expanded', 'false');
        }
    });
}

// Mobile menu button
(function(){
    const toggle = document.getElementById('menu-toggle');
    const nav = document.getElementById('adminNav');
    if (!toggle || !nav) return;
    function setOpen(open){
        nav.classList.toggle('open', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.querySelector('i').className = open ? 'fa-solid fa-xmark' : 'fa-solid fa-bars';
    }
    toggle.addEventListener('click', function(e){ e.stopPropagation(); setOpen(!nav.classList.contains('open')); });
    document.addEventListener('click', function(e){ if (!nav.contains(e.target)) setOpen(false); });
    document.addEventListener('keydown', function(e){ if (e.key === 'Escape') setOpen(false); });
    // reset when the window is widened back to desktop
    window.matchMedia('(min-width: 1281px)').addEventListener('change', function(ev){ if (ev.matches) setOpen(false); });
})();

// Notification bell
(function(){
    const bell         = document.getElementById("notif-bell");
    const dropdown     = document.getElementById("notifDropdown");
    const notifList    = document.getElementById("notifList");
    const lowStockList = document.getElementById("lowStockList");
    const activityList = document.getElementById("activityList");
    const notifBadge   = document.getElementById("notifBadge");
    let isOpen = false;

    function closeDropdown() {
        dropdown.style.display = "none";
        bell.setAttribute('aria-expanded', 'false');
        isOpen = false;
    }

    function loadNotifications() {
        fetch("admin_recent_orders_ajax.php")
            .then(res => res.text())
            .then(html => { notifList.innerHTML = html || '<div class="notif-empty">No recent orders</div>'; })
            .catch(() => { notifList.innerHTML = '<div class="notif-empty" style="color: #dc2626;">Error loading</div>'; });

        fetch("admin_low_stock_ajax.php")
            .then(res => res.text())
            .then(html => { lowStockList.innerHTML = html || '<div class="notif-empty">All items well stocked</div>'; })
            .catch(() => { lowStockList.innerHTML = '<div class="notif-empty" style="color: #dc2626;">Error loading</div>'; });

        fetch("admin_activity_ajax.php")
            .then(res => res.text())
            .then(html => { activityList.innerHTML = html || '<div class="notif-empty">No recent activity</div>'; })
            .catch(() => { activityList.innerHTML = '<div class="notif-empty" style="color: #dc2626;">Error loading</div>'; });
    }

    bell.addEventListener("click", function(e){
        e.stopPropagation();
        if (isOpen) {
            closeDropdown();
        } else {
            dropdown.style.display = "flex";
            bell.setAttribute('aria-expanded', 'true');
            isOpen = true;
            loadNotifications();
            if (profileMenu) profileMenu.classList.remove('active');
        }
    });

    // profile menu asks us to close when it opens
    document.addEventListener('admin:close-notif', function(){ if (isOpen) closeDropdown(); });

    document.addEventListener("click", function(e){
        if (isOpen && !dropdown.contains(e.target) && !bell.contains(e.target)) closeDropdown();
    });

    document.addEventListener("keydown", function(e){
        if (e.key === 'Escape' && isOpen) closeDropdown();
    });

    function updateBadge() {
        fetch("admin_notification_badge.php")
            .then(res => res.text())
            .then(count => {
                const n = parseInt(count, 10) || 0;
                notifBadge.style.display = n > 0 ? "inline-block" : "none";
                notifBadge.textContent = n;
                bell.classList.toggle('has-new', n > 0);
            })
            .catch(err => console.log('Badge update error:', err));
    }

    updateBadge();
    setInterval(updateBadge, 30000);

    setInterval(() => { if (isOpen) loadNotifications(); }, 20000);
})();
</script>
</body>
</html>