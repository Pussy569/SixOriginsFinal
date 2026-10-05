<?php
include 'config.php';

$total = 0;

// ============= COUNT PENDING/ACTIVE ORDERS =============
$r = mysqli_query($conn, "
    SELECT COUNT(*) as cnt 
    FROM orders 
    WHERE payment_status IN('pending','accepted','preparing','out for delivery')
");
$row = mysqli_fetch_assoc($r);
$total += intval($row['cnt'] ?? 0);

// ============= COUNT LOW STOCK PRODUCTS (stock <= 5) =============
$r = mysqli_query($conn, "
    SELECT COUNT(*) as cnt 
    FROM product_sizes 
    WHERE stock <= 5
");
$row = mysqli_fetch_assoc($r);
$total += intval($row['cnt'] ?? 0);

// ============= COUNT RECENT ADMIN ACTIVITIES (LAST 24 HOURS) =============
// Note: Activities auto-delete after 24 hours, so we just count what's left
$r = mysqli_query($conn, "
    SELECT COUNT(*) as cnt 
    FROM admin_activity 
    WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
");
$row = mysqli_fetch_assoc($r);
$activity_count = intval($row['cnt'] ?? 0);

// Add activity count to badge (optional - shows if there are recent activities)
// Uncomment if you want activities to contribute to badge count:
// $total += $activity_count;

echo $total;
?>
