<?php
include 'config.php';

$result = mysqli_query($conn, "SELECT ps.id, p.name, ps.size, ps.stock FROM product_sizes ps 
                               JOIN products p ON ps.product_id = p.id 
                               WHERE ps.stock <= 5 
                               ORDER BY ps.stock ASC LIMIT 5");

if(!$result || mysqli_num_rows($result) === 0) { 
    echo '<div class="notif-empty">All products well stocked</div>';
} else {
    while($r = mysqli_fetch_assoc($result)){
        // Determine status badge
        if($r['stock'] == 0) {
            $status_class = 'out-of-stock';
            $status_text = 'Out of Stock';
        } elseif($r['stock'] <= 2) {
            $status_class = 'critical';
            $status_text = 'Critical: ' . intval($r['stock']);
        } else {
            $status_class = 'low';
            $status_text = 'Low: ' . intval($r['stock']);
        }
        
        echo '<div class="notif-item">
                <div class="notif-detail">
                    <strong>'.htmlspecialchars($r['name']).'</strong>
                    Size: '.htmlspecialchars($r['size']).' - Stock: '.intval($r['stock']).' units
                </div>
                <span class="notif-status '. $status_class .'">'. $status_text .'</span>
            </div>';
    }
}
?>
