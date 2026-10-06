<?php
include dirname(__DIR__, 2) . '/config.php';

$user_id = null;
$is_guest = false;

if (!empty($_SESSION['user_id'])) {
    $user_id = (int) $_SESSION['user_id'];
} elseif (!empty($_SESSION['guest_cart_id'])) {
    $user_id = "guest_" . $_SESSION['guest_cart_id'];
    $is_guest = true;
}

$cart_count = 0;
$profile_image = 'default.png';

// Get cart count for BOTH registered and guest users
if ($user_id && isset($conn)) {
    $user_id_escaped = mysqli_real_escape_string($conn, (string)$user_id);
    $result = mysqli_query($conn, "SELECT COUNT(*) AS cnt FROM `cart` WHERE user_id = '$user_id_escaped'") or die('query failed');
    $row = mysqli_fetch_assoc($result);
    $cart_count = intval($row['cnt']);
}

// Get profile info only for registered users
if (!$is_guest && $user_id && isset($conn)) {
    $user_id_escaped = mysqli_real_escape_string($conn, (string)$user_id);
    $res = mysqli_query($conn, "SELECT profile_image, name, email FROM users WHERE id = '$user_id_escaped' LIMIT 1") or die('query failed');
    if ($res && mysqli_num_rows($res) > 0) {
        $profile_row = mysqli_fetch_assoc($res);
        if (!empty($profile_row['profile_image'])) {
            $profile_image = $profile_row['profile_image'];
        }
        if (empty($_SESSION['user_name']) && !empty($profile_row['name'])) {
            $_SESSION['user_name'] = $profile_row['name'];
        }
        if (empty($_SESSION['user_email']) && !empty($profile_row['email'])) {
            $_SESSION['user_email'] = $profile_row['email'];
        }
    }
}
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Six Origins Cafe</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<!-- FONT AWESOME - FIXED FOR PROPER ICON RENDERING -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" integrity="sha512-DTOQO9RWCH3H5NxKLo6GLOBALYUFF3F8vlG7OYwLDa3p40YXzBTqMjhQS6TmOYw+RbcTwNhDTGPpqEKaFz7LWzg==" crossorigin="anonymous" referrerpolicy="no-referrer" />
<link rel="icon" type="image/png" href="images/logos.png">

<style>
:root {
  --primary-red: #C6453E;
  --dark-brown: #5E1F13;
  --gray-brown: #664C47;
  --light-cream: #FFF2E0;
  --white: #FFFFFF;
  --radius: 16px;
  --shadow: 0 10px 30px rgba(94, 31, 19, 0.08);
  --shadow-hover: 0 15px 40px rgba(94, 31, 19, 0.2);
  --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* FIX FOR FONT AWESOME ICONS NOT RENDERING */
.fa, [class^="fa-"], [class*=" fa-"],
.fas, .fa-solid,
.far, .fa-regular,
.fab, .fa-brands {
  font-family: "Font Awesome 6 Free" !important;
  font-style: normal;
  font-weight: 900 !important;
  -webkit-font-smoothing: antialiased;
  -moz-osx-font-smoothing: grayscale;
}

.fa-regular, .far {
  font-weight: 400 !important;
}

.fa-light, .fal {
  font-weight: 300 !important;
}

body { 
  background: #FFFAF5;
  margin: 0;
  padding: 0;
}

* { 
  box-sizing: border-box; 
  margin: 0; 
  padding: 0; 
  font-family: 'Montserrat', 'Segoe UI', Arial, sans-serif;
}

.header-wrap {
  background: linear-gradient(135deg, rgba(0, 0, 0, 0.4) 0%, rgba(0, 0, 0, 0.5) 100%),
              linear-gradient(135deg, var(--primary-red) 0%, var(--dark-brown) 100%);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.15);
  position: sticky; 
  top: 0; 
  z-index: 99;
  border-bottom: 1px solid rgba(255, 255, 255, 0.1);
  transition: padding var(--transition), background var(--transition);
}

.header-wrap.scrolled {
  padding: 6px 0;
  background: linear-gradient(135deg, rgba(0, 0, 0, 0.35) 0%, rgba(0, 0, 0, 0.45) 100%),
              linear-gradient(135deg, var(--primary-red) 0%, var(--dark-brown) 100%);
}

.container {
  max-width: 1200px; 
  margin: 0 auto;
  display: flex; 
  align-items: center; 
  justify-content: space-between;
  padding: 18px 24px;
  gap: 24px;
  transition: padding var(--transition);
  width: 100%;
}

.header-wrap.scrolled .container {
  padding: 12px 24px;
}

.brand {
  display: flex; 
  align-items: center; 
  gap: 12px;
  text-decoration: none;
  transition: var(--transition);
  flex-shrink: 0;
}

.brand:hover {
  transform: scale(1.05);
}

.brand .logo-img {
  width: 56px;
  height: 56px;
  object-fit: contain;
  background: rgba(255, 255, 255, 0.08);
  padding: 8px;
  border-radius: 12px;
  transition: var(--transition);
  border: 1px solid rgba(255, 255, 255, 0.1);
}

.header-wrap.scrolled .brand .logo-img {
  width: 48px;
  height: 48px;
  padding: 6px;
}

.brand:hover .logo-img {
  background: rgba(255, 255, 255, 0.15);
  box-shadow: 0 8px 20px rgba(0, 0, 0, 0.25);
  border-color: rgba(255, 255, 255, 0.2);
}

.brand .logo-text {
  display: flex;
  flex-direction: column;
  gap: 3px;
  transition: var(--transition);
}

.brand .logo-main {
  font-family: 'Romelio Sans', serif;
  color: #FFFBF7;
  font-size: 1.8rem;
  font-weight: 900;
  letter-spacing: -0.5px;
  line-height: 1.1;
  transition: var(--transition);
}

.header-wrap.scrolled .brand .logo-main {
  font-size: 1.5rem;
}

.brand .logo-sub {
  font-family: 'Montserrat', sans-serif;
  color: #FFF2E0;
  font-size: 0.8rem;
  font-weight: 700;
  letter-spacing: 1.3px;
  text-transform: uppercase;
  margin-left: 2px;
  transition: var(--transition);
}

.header-wrap.scrolled .brand .logo-sub {
  font-size: 0.7rem;
}

.nav {
  display: flex; 
  gap: 6px; 
  align-items: center;
  flex: 1; 
  justify-content: center;
  flex-wrap: wrap;
}

.nav a {
  text-decoration: none;
  color: rgba(255, 251, 247, 0.9);
  background: rgba(255, 255, 255, 0.08);
  padding: 12px 20px;
  font-weight: 700;
  font-size: 0.98rem;
  border-radius: var(--radius);
  transition: var(--transition);
  position: relative;
  overflow: hidden;
  border: 1px solid rgba(255, 255, 255, 0.1);
  backdrop-filter: blur(5px);
  white-space: nowrap;
}

.nav a::before {
  content: '';
  position: absolute;
  inset: 0;
  background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.15), transparent);
  transform: translateX(-100%);
  transition: transform 0.5s ease;
}

.nav a:hover::before {
  transform: translateX(100%);
}

.nav a:hover, 
.nav a.active {
  background: #FFF2E0;
  color: var(--dark-brown);
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255, 242, 224, 0.35);
  border-color: #FFF2E0;
}

.actions {
  display: flex; 
  gap: 10px; 
  align-items: center;
  flex-shrink: 0;
}

.icon-btn {
  display: inline-flex; 
  align-items: center; 
  gap: 8px;
  padding: 12px 16px;
  border-radius: var(--radius);
  background: rgba(255, 255, 255, 0.08);
  color: rgba(255, 251, 247, 0.95);
  border: 1.5px solid rgba(255, 255, 255, 0.1);
  cursor: pointer;
  font-weight: 600;
  font-size: 1.05rem;
  transition: var(--transition);
  position: relative;
  backdrop-filter: blur(5px);
  white-space: nowrap;
  text-decoration: none;
}

.header-wrap.scrolled .icon-btn {
  padding: 9px 12px;
  font-size: 0.95rem;
}

.icon-btn:hover {
  background: #FFF2E0;
  color: var(--dark-brown);
  border-color: #FFF2E0;
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255, 242, 224, 0.35);
}

.icon-btn i {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 1.3em;
  font-weight: 900 !important;
  font-style: normal;
  line-height: 1;
}

.icon-btn .badge {
  background: #FFF2E0;
  color: var(--dark-brown);
  padding: 4px 10px;
  border-radius: 99px;
  font-size: 0.75em;
  font-weight: 800;
  min-width: 24px;
  text-align: center;
  transition: var(--transition);
  animation: badgePulse 0.4s ease;
  font-style: normal;
  font-family: 'Montserrat', sans-serif;
}

@keyframes badgePulse {
  0% {
    transform: scale(1.2);
    opacity: 0.7;
  }
  100% {
    transform: scale(1);
    opacity: 1;
  }
}

.profile-btn {
  display: flex; 
  align-items: center; 
  gap: 10px;
  border-radius: 99px;
  padding: 10px 18px;
  background: rgba(255, 255, 255, 0.08);
  border: 1.5px solid rgba(255, 255, 255, 0.1);
  cursor: pointer;
  transition: var(--transition);
  backdrop-filter: blur(5px);
}

.header-wrap.scrolled .profile-btn {
  padding: 8px 14px;
}

.profile-btn:hover {
  background: rgba(255, 255, 255, 0.15);
  border-color: #FFF2E0;
  transform: translateY(-2px);
}

.profile-btn .avatar {
  width: 42px; 
  height: 42px; 
  border-radius: 99px; 
  object-fit: cover; 
  border: 2.5px solid rgba(255, 251, 247, 0.95);
  box-shadow: 0 6px 16px rgba(0, 0, 0, 0.25);
  transition: var(--transition);
}

.header-wrap.scrolled .profile-btn .avatar {
  width: 38px; 
  height: 38px;
  border-width: 2px;
}

.profile-name {
  font-weight: 700; 
  color: rgba(255, 251, 247, 0.95); 
  font-size: 0.98rem;
  text-transform: capitalize;
  max-width: 120px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  transition: var(--transition);
}

.header-wrap.scrolled .profile-name {
  display: none;
}

.profile-menu {
  position: absolute;
  right: 0;
  top: 78px;
  min-width: 280px;
  background: linear-gradient(135deg, rgba(255, 251, 247, 0.98) 0%, rgba(254, 253, 251, 0.98) 100%);
  border-radius: var(--radius);
  box-shadow: 0 12px 40px rgba(0, 0, 0, 0.25);
  border: 2px solid #F0E6D8;
  padding: 18px;
  display: none; 
  z-index: 90;
  animation: slideDown 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  backdrop-filter: blur(10px);
}

@keyframes slideDown {
  from {
    opacity: 0;
    transform: translateY(-12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

.profile-menu.active { 
  display: block; 
}

.profile-menu .profile-info {
  display: flex; 
  gap: 12px; 
  align-items: center;
  padding-bottom: 14px;
  border-bottom: 2px solid #F0E6D8;
  margin-bottom: 14px;
}

.profile-menu .profile-info img {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  object-fit: cover;
  border: 2px solid #F0E6D8;
  background: #FFFAF5;
  box-shadow: 0 4px 12px rgba(94, 31, 19, 0.15);
}

.profile-menu .name {
  font-weight: 800; 
  color: var(--dark-brown);
  font-size: 1.1em;
  font-family: 'Romelio Sans', serif;
}

.profile-menu .muted {
  color: var(--gray-brown); 
  font-size: 0.85em; 
  margin-top: 4px;
  word-break: break-all;
}

.profile-menu .profile-actions {
  margin-top: 12px; 
  display: flex; 
  flex-direction: column; 
  gap: 8px;
}

.profile-menu .icon-btn {
  color: var(--dark-brown);
  background: linear-gradient(135deg, #FFFAF5 0%, #FFF9F3 100%);
  border: 1.5px solid #F0E6D8;
  font-size: 0.95em;
  padding: 12px 16px;
  border-radius: 10px;
  text-align: left;
  justify-content: flex-start;
  gap: 12px;
  transition: var(--transition);
  white-space: normal;
}

.profile-menu .icon-btn:hover {
  background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
  color: white;
  border-color: var(--primary-red);
  transform: translateX(4px);
  box-shadow: 0 8px 20px rgba(198, 69, 62, 0.25);
}

.profile-menu .icon-btn i {
  width: 20px;
  height: 20px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  color: var(--primary-red);
  flex-shrink: 0;
  font-weight: 900 !important;
  font-size: 1.1em;
  line-height: 1;
}

.profile-menu .icon-btn:hover i {
  color: white;
}

#menu-toggle { 
  display: none;
}

/* Mobile Menu */
#mobileMenu {
  background: linear-gradient(135deg, rgba(0, 0, 0, 0.35) 0%, rgba(0, 0, 0, 0.45) 100%), linear-gradient(135deg, var(--primary-red) 0%, var(--dark-brown) 100%);
  border-top: 1px solid rgba(255,255,255,0.1);
  padding: 12px 16px 16px;
  animation: slideDown 0.3s cubic-bezier(0.4, 0, 0.2, 1);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
}

#mobileMenu a {
  color: rgba(255, 251, 247, 0.95);
  font-weight: 700;
  padding: 12px 14px;
  border-radius: var(--radius);
  transition: var(--transition);
  display: flex;
  align-items: center;
  gap: 12px;
  background: rgba(255,255,255,0.08);
  border: 1px solid rgba(255,255,255,0.1);
  backdrop-filter: blur(5px);
  text-decoration: none;
  font-size: 0.95rem;
}

#mobileMenu a i {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  font-size: 1.2em;
  font-weight: 900 !important;
  font-style: normal;
  line-height: 1;
}

#mobileMenu a:hover {
  background: #FFF2E0;
  color: var(--dark-brown);
  border-color: #FFF2E0;
  transform: translateX(4px);
}

/* Responsive */
@media (max-width: 900px) {
  .nav { 
    display: none;
  }

  #menu-toggle { 
    display: inline-flex;
  }

  .container { 
    padding: 14px 18px;
  }

  .header-wrap.scrolled .container {
    padding: 10px 18px;
  }

  .profile-menu { 
    right: -8px; 
    top: 68px; 
    min-width: calc(100vw - 32px);
    max-width: 300px;
  }

  .brand .logo-main {
    font-size: 1.5rem;
  }

  .header-wrap.scrolled .brand .logo-main {
    font-size: 1.3rem;
  }

  .brand .logo-img {
    width: 50px;
    height: 50px;
  }

  .header-wrap.scrolled .brand .logo-img {
    width: 44px;
    height: 44px;
  }

  .profile-name {
    display: none;
  }

  .icon-btn {
    padding: 10px 12px;
    font-size: 0.95rem;
  }

  .header-wrap.scrolled .icon-btn {
    padding: 8px 10px;
    font-size: 0.85rem;
  }
}

@media (max-width: 640px) {
  .container {
    padding: 12px 14px;
    gap: 10px;
  }

  .header-wrap.scrolled .container {
    padding: 8px 14px;
  }

  .brand .logo-main {
    font-size: 1.25rem;
  }

  .header-wrap.scrolled .brand .logo-main {
    font-size: 1.1rem;
  }

  .brand .logo-img {
    width: 44px;
    height: 44px;
  }

  .header-wrap.scrolled .brand .logo-img {
    width: 40px;
    height: 40px;
    padding: 5px;
  }

  .brand .logo-sub {
    font-size: 0.65rem;
  }

  .header-wrap.scrolled .brand .logo-sub {
    font-size: 0.6rem;
  }

  .profile-menu {
    min-width: calc(100vw - 28px);
    top: 62px;
    padding: 14px;
    right: -8px;
  }

  .icon-btn {
    padding: 9px 10px;
    font-size: 0.9rem;
    gap: 6px;
  }

  .header-wrap.scrolled .icon-btn {
    padding: 7px 8px;
    font-size: 0.8rem;
  }

  .icon-btn .badge {
    padding: 3px 8px;
    font-size: 0.7em;
  }

  .profile-btn {
    padding: 8px 12px;
    gap: 6px;
  }

  .header-wrap.scrolled .profile-btn {
    padding: 6px 10px;
  }

  .profile-btn .avatar {
    width: 38px;
    height: 38px;
    border-width: 2px;
  }

  .header-wrap.scrolled .profile-btn .avatar {
    width: 34px;
    height: 34px;
    border-width: 1.5px;
  }

  .profile-menu .profile-info img {
    width: 40px;
    height: 40px;
  }

  .profile-menu .name {
    font-size: 1em;
  }

  .profile-menu .muted {
    font-size: 0.8em;
  }

  .profile-menu .icon-btn {
    padding: 10px 12px;
    font-size: 0.9em;
    gap: 10px;
  }

  #mobileMenu {
    padding: 10px 12px 12px;
  }

  #mobileMenu a {
    padding: 10px 12px;
    font-size: 0.9rem;
    gap: 10px;
  }
}

@media (max-width: 480px) {
  .container {
    padding: 10px 12px;
    gap: 8px;
  }

  .header-wrap.scrolled .container {
    padding: 8px 12px;
  }

  .brand {
    gap: 8px;
  }

  .brand .logo-main {
    font-size: 1.1rem;
  }

  .header-wrap.scrolled .brand .logo-main {
    font-size: 1rem;
  }

  .brand .logo-img {
    width: 40px;
    height: 40px;
    padding: 6px;
  }

  .header-wrap.scrolled .brand .logo-img {
    width: 36px;
    height: 36px;
    padding: 5px;
  }

  .brand .logo-sub {
    font-size: 0.6rem;
    letter-spacing: 1px;
  }

  .header-wrap.scrolled .brand .logo-sub {
    font-size: 0.55rem;
  }

  .icon-btn {
    padding: 8px 8px;
    font-size: 0.85rem;
    gap: 4px;
  }

  .header-wrap.scrolled .icon-btn {
    padding: 6px 7px;
    font-size: 0.75rem;
  }

  .icon-btn i {
    font-size: 1.1em;
    font-weight: 900 !important;
  }

  .profile-btn {
    padding: 7px 10px;
    gap: 5px;
  }

  .header-wrap.scrolled .profile-btn {
    padding: 6px 9px;
  }

  .profile-btn .avatar {
    width: 36px;
    height: 36px;
    border-width: 2px;
  }

  .header-wrap.scrolled .profile-btn .avatar {
    width: 32px;
    height: 32px;
    border-width: 1.5px;
  }

  .profile-menu {
    min-width: calc(100vw - 24px);
    top: 56px;
    padding: 12px;
    right: -6px;
  }

  .profile-menu .profile-info {
    gap: 10px;
    padding-bottom: 12px;
    margin-bottom: 12px;
  }

  .profile-menu .profile-info img {
    width: 36px;
    height: 36px;
  }

  .profile-menu .name {
    font-size: 0.95em;
  }

  .profile-menu .muted {
    font-size: 0.75em;
  }

  .profile-menu .profile-actions {
    gap: 6px;
  }

  .profile-menu .icon-btn {
    padding: 9px 11px;
    font-size: 0.85em;
    gap: 9px;
  }

  .profile-menu .icon-btn i {
    width: 18px;
    height: 18px;
    font-size: 1em;
    font-weight: 900 !important;
  }

  #mobileMenu {
    padding: 8px 10px 10px;
  }

  #mobileMenu a {
    padding: 9px 10px;
    font-size: 0.85rem;
    gap: 9px;
  }

  #mobileMenu a i {
    width: 18px;
    height: 18px;
    font-size: 1em;
    font-weight: 900 !important;
  }
}

@media (max-width: 360px) {
  .container {
    padding: 9px 10px;
    gap: 6px;
  }

  .brand .logo-main {
    font-size: 1rem;
  }

  .brand .logo-img {
    width: 36px;
    height: 36px;
  }

  .icon-btn {
    padding: 7px 7px;
    font-size: 0.8rem;
  }

  .profile-btn {
    padding: 6px 8px;
  }

  .profile-btn .avatar {
    width: 34px;
    height: 34px;
  }
}

/* =====================================================
   ✅ MOBILE RESPONSIVE FIX (added)
   ===================================================== */
html{-webkit-text-size-adjust:100%}
.profile-dropdown{position:relative}

/* menu collapses earlier so the nav never wraps/overflows between 900-1080px */
@media (max-width:1080px){
  .nav{display:none}
  #menu-toggle{display:inline-flex}
  .profile-name{display:none}
}

@media (max-width:900px){
  .container{flex-wrap:nowrap;min-width:0}
  /* profile menu now anchors to the header, so it is never cut off */
  .profile-dropdown{position:static}
  .profile-menu{left:12px;right:12px;top:100%;margin-top:6px;min-width:0;max-width:none;width:auto;
                max-height:calc(100vh - 90px);max-height:calc(100dvh - 90px);overflow-y:auto}
  .icon-btn{min-width:40px;min-height:40px;justify-content:center}
}

@media (max-width:480px){
  .actions{gap:4px}
  .profile-btn .fa-caret-down{display:none}
  .icon-btn{min-width:38px;min-height:38px}
}

/* very small phones: keep the logo, hide the text so everything fits */
@media (max-width:380px){
  .brand .logo-text{display:none}
}

/* no "stuck" hover lift after tapping on touch screens */
@media (hover:none){
  .nav a:hover,.icon-btn:hover,.profile-btn:hover,.brand:hover{transform:none}
}
</style>
</head>
<body>
<header class="header-wrap" id="headerWrap" role="banner">
  <div class="container">
     <a href="index.php" class="brand" aria-label="Six Origins Cafe home">
        <img src="images/logos.png" alt="Six Origins Logo" class="logo-img" loading="lazy">
        <div class="logo-text">
           <div class="logo-main">Six Origins</div>
           <div class="logo-sub">Cafe</div>
        </div>
     </a>

     <nav class="nav" role="navigation" aria-label="Main navigation">
        <a href="index.php" class="active">Home</a>
        <a href="about.php">About</a>
        <a href="Item.php">Shop</a>
        <a href="contact.php">Contact</a>
        <a href="orders.php">Orders</a>
     </nav>

     <div class="actions" role="group" aria-label="Header actions">
        <button id="menu-toggle" class="icon-btn" aria-expanded="false" aria-controls="mobileMenu" title="Menu" aria-label="Toggle mobile menu">
           <i class="fas fa-bars"></i>
        </button>
        <a href="search_page.php" class="icon-btn" title="Search products" aria-label="Search">
           <i class="fas fa-magnifying-glass"></i>
        </a>
        <a href="cart.php" class="icon-btn" title="Shopping cart" aria-label="View shopping cart">
           <i class="fas fa-shopping-bag"></i>
           <span class="badge"><?php echo intval($cart_count); ?></span>
        </a>
        <div class="profile-dropdown">
           <button id="profileBtn" class="profile-btn" aria-haspopup="true" aria-expanded="false" aria-controls="profileMenu" title="Profile menu" aria-label="User profile menu">
              <img src="images/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" class="avatar" loading="lazy">
              <span class="profile-name"><?php echo (!$is_guest && !empty($_SESSION['user_name'])) ? htmlspecialchars(substr($_SESSION['user_name'], 0, 12)) : ($is_guest ? 'Guest' : 'User'); ?></span>
              <i class="fas fa-caret-down" style="font-size: 0.75rem; font-weight: 900 !important; font-style: normal;"></i>
           </button>

           <div id="profileMenu" class="profile-menu" role="menu" aria-hidden="true">
              <div class="profile-info">
                 <img src="images/<?php echo htmlspecialchars($profile_image); ?>" alt="Profile" loading="lazy">
                 <div>
                    <div class="name"><?php echo (!$is_guest && !empty($_SESSION['user_name'])) ? htmlspecialchars($_SESSION['user_name']) : ($is_guest ? 'Guest User' : 'User'); ?></div>
                    <div class="muted"><?php echo (!$is_guest && !empty($_SESSION['user_email'])) ? htmlspecialchars($_SESSION['user_email']) : ($is_guest ? 'Browsing as guest' : 'Not signed in'); ?></div>
                 </div>
              </div>
              <div class="profile-actions">
                 <?php if (!$is_guest && $user_id): ?>
                    <a href="profile.php" class="icon-btn" role="menuitem"><i class="fas fa-user"></i> My Profile</a>
                    <a href="user_topup.php" class="icon-btn" role="menuitem"><i class="fas fa-wallet"></i> Top Up</a>
                    <a href="logout.php" class="icon-btn" role="menuitem"><i class="fas fa-sign-out-alt"></i> Logout</a>
                 <?php else: ?>
                    <a href="login.php" class="icon-btn" role="menuitem"><i class="fas fa-sign-in-alt"></i> Login</a>
                    <a href="register.php" class="icon-btn" role="menuitem"><i class="fas fa-user-plus"></i> Register</a>
                 <?php endif; ?>
              </div>
           </div>
        </div>
     </div>
  </div>

  <!-- Mobile menu -->
  <div id="mobileMenu" style="display: none;" role="navigation" aria-label="Mobile navigation">
     <div style="max-width: 1200px; margin: 0 auto; display: flex; flex-direction: column; gap: 6px;">
        <a href="index.php" aria-label="Home page">
           <i class="fas fa-home"></i> Home
        </a>
        <a href="about.php" aria-label="About page">
           <i class="fas fa-circle-info"></i> About
        </a>
        <a href="Item.php" aria-label="Shop page">
           <i class="fas fa-bag-shopping"></i> Shop
        </a>
        <a href="contact.php" aria-label="Contact page">
           <i class="fas fa-envelope"></i> Contact
        </a>
        <a href="orders.php" aria-label="Orders page">
           <i class="fas fa-box"></i> Orders
        </a>
     </div>
  </div>
</header>

<script>
(function(){
   const headerWrap = document.getElementById('headerWrap');
   const profileBtn = document.getElementById('profileBtn');
   const profileMenu = document.getElementById('profileMenu');
   const menuToggle = document.getElementById('menu-toggle');
   const mobileMenu = document.getElementById('mobileMenu');

   // Scroll effect for header shrink/expand
   let lastScrollY = 0;
   window.addEventListener('scroll', function() {
      const currentScroll = window.scrollY;
      
      if (currentScroll > 50) {
         headerWrap.classList.add('scrolled');
      } else {
         headerWrap.classList.remove('scrolled');
      }
      
      lastScrollY = currentScroll;
   }, { passive: true });

   function closeProfile(){
      profileMenu.classList.remove('active');
      profileMenu.setAttribute('aria-hidden', 'true');
      profileBtn.setAttribute('aria-expanded', 'false');
   }

   function openProfile(){
      profileMenu.classList.add('active');
      profileMenu.setAttribute('aria-hidden', 'false');
      profileBtn.setAttribute('aria-expanded', 'true');
   }

   if (profileBtn && profileMenu) {
      profileBtn.addEventListener('click', function(e){
         e.stopPropagation();
         if (profileMenu.classList.contains('active')) {
            closeProfile();
         } else {
            openProfile();
         }
      });

      document.addEventListener('click', function(e){
         if (!profileMenu.contains(e.target) && !profileBtn.contains(e.target)) {
            closeProfile();
         }
      });

      document.addEventListener('keydown', function(e){
         if (e.key === 'Escape') closeProfile();
      });
   }

   if (menuToggle && mobileMenu) {
      menuToggle.addEventListener('click', function(e){
         e.stopPropagation();
         const open = mobileMenu.style.display === 'block';
         mobileMenu.style.display = open ? 'none' : 'block';
         menuToggle.setAttribute('aria-expanded', !open);
      });

      const mobileLinks = mobileMenu.querySelectorAll('a');
      mobileLinks.forEach(link => {
         link.addEventListener('click', function(){
            mobileMenu.style.display = 'none';
            menuToggle.setAttribute('aria-expanded', 'false');
         });
      });

      document.addEventListener('click', function(e){
         if (!mobileMenu.contains(e.target) && !menuToggle.contains(e.target)) {
            mobileMenu.style.display = 'none';
            menuToggle.setAttribute('aria-expanded', 'false');
         }
      });
   }
})();
</script>

</body>
</html>