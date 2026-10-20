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
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/app/services/login_session_security.php';

clear_active_account_session((int)($_SESSION['rider_id'] ?? 0), (string)($_SESSION['account_session_token'] ?? ''));

// Only clear rider-related session keys so this never accidentally
// logs out an admin/user session sharing the same PHP session.
unset($_SESSION['rider_id']);
unset($_SESSION['rider_name']);
unset($_SESSION['rider_email']);
unset($_SESSION['account_session_token']);
unset($_SESSION['just_logged_in']);

session_regenerate_id(true);
session_write_close();

header('location:login.php');
exit;