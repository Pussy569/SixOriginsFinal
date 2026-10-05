<?php
include 'config.php';

$result = mysqli_query($conn, "SELECT o.id, o.total_price, o.payment_status, u.name FROM orders o LEFT JOIN users u ON u.id = o.user_id WHERE o.payment_status IN('pending','accepted','preparing','out for delivery') ORDER BY o.id DESC LIMIT 5");

if(!$result || mysqli_num_rows($result) === 0) { 
    echo '<div class="notif-empty">No pending orders</div>';
} else {
    while($r = mysqli_fetch_assoc($result)){
        $status_class = match($r['payment_status']) {
            'completed' => 'completed',
            'pending' => 'pending',
            'accepted' => 'accepted',
            'preparing' => 'preparing',
            'out for delivery' => 'delivery',
            'cancelled' => 'cancelled',
            default => 'pending'
        };
        echo '<div class="notif-item">
                <div class="notif-detail">
                    <strong>Order #'.$r['id'].'</strong>
                    ₱'.number_format($r['total_price'],2).' by '.htmlspecialchars($r['name'] ?? 'Unknown').'
                </div>
                <span class="notif-status '. $status_class .'">'.ucwords($r['payment_status']).'</span>
            </div>';
    }
}
?>
