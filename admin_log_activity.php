<?php
// admin_log_activity.php
include 'config.php';

function log_admin_activity($admin_id, $action, $description = '') {
    global $conn;
    
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
    
    $stmt = $conn->prepare("INSERT INTO admin_activity (admin_id, action, description, ip_address, timestamp) VALUES (?, ?, ?, ?, NOW())");
    
    if ($stmt) {
        $stmt->bind_param("isss", $admin_id, $action, $description, $ip_address);
        $stmt->execute();
        $stmt->close();
        return true;
    }
    return false;
}
?>