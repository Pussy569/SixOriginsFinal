<?php
/**
 * PROJECT: Six Origins Cafe - Automated Web Application
 * MODULE: Delivery Rider Logout
 *
 * Your rider_dashboard.php links to rider_logout.php but that file
 * wasn't among the files you shared, so it would 404. This clears
 * only the rider session and sends them back to login.php.
 */

session_start();

// Only clear rider-related session keys so this never accidentally
// logs out an admin/user session sharing the same PHP session.
unset($_SESSION['rider_id']);
unset($_SESSION['rider_name']);
unset($_SESSION['rider_email']);
unset($_SESSION['just_logged_in']);

session_regenerate_id(true);
session_write_close();

header('location:login.php');
exit;