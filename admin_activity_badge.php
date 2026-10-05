<?php
include 'config.php';

$total = 0;

// Count pending orders
$r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM orders WHERE payment_status IN('pending','accepted','preparing','out for delivery')");
$row = mysqli_fetch_assoc($r);
$total += intval($row['cnt']);

// Count low stock products
$r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM product_sizes WHERE stock <= 10");
$row = mysqli_fetch_assoc($r);
$total += intval($row['cnt']);

// Count recent activities (last 24 hours)
$r = mysqli_query($conn, "SELECT COUNT(*) as cnt FROM admin_activity WHERE timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
$row = mysqli_fetch_assoc($r);
$total += intval($row['cnt']);

echo $total;
?>
