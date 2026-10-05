<?php
include 'config.php';
session_start();

$user_id = $_SESSION['user_id'] ?? 0;

if ($user_id == 0) {
    echo "0.00";
    exit;
}


$user_query = mysqli_query($conn, "SELECT wallet_balance FROM `users` WHERE id = '$user_id'") or die('query failed');
$user_data = mysqli_fetch_assoc($user_query);
$wallet_balance = $user_data['wallet_balance'];


$cart_total = 0;
$cart_query = mysqli_query($conn, "SELECT * FROM `cart` WHERE user_id = '$user_id'") or die('query failed');
if (mysqli_num_rows($cart_query) > 0) {
    while ($cart_item = mysqli_fetch_assoc($cart_query)) {
        $sub_total = $cart_item['price'] * $cart_item['quantity'];
        $cart_total += $sub_total;
    }
}


$vat = $cart_total * 0.12;
$delivery_fee = 39.00;
$final_total = $cart_total + $vat + $delivery_fee;


$remaining_balance = max($wallet_balance - $final_total, 0);


echo number_format($remaining_balance, 2);
?>
