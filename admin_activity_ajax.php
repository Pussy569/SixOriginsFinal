<?php
include 'config.php';
require_once __DIR__ . '/app/services/admin_log_activity.php';

if (empty($_SESSION['admin_id']) || !is_head_admin()) {
    http_response_code(empty($_SESSION['admin_id']) ? 401 : 403);
    exit('Forbidden');
}

// Keep the notification preview recent without deleting the full audit history.
$result = mysqli_query($conn, "
    SELECT 
        aa.id,
        aa.action,
        aa.description,
        aa.timestamp,
        COALESCE(NULLIF(aa.admin_name_snapshot, ''), u.name, 'Admin') as admin_name
    FROM admin_activity aa
    LEFT JOIN users u ON aa.admin_id = u.id
    WHERE aa.timestamp >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
    ORDER BY aa.timestamp DESC
    LIMIT 8
");

if(!$result || mysqli_num_rows($result) === 0) {
    echo '<div style="padding: 16px; color: #664C47; font-weight: 600; text-align: center; font-size: 0.9em;">No activity in the last 24 hours.</div>';
} else {
    while($r = mysqli_fetch_assoc($result)){
        $timestamp = new DateTime($r['timestamp']);
        $now = new DateTime();
        $interval = $now->diff($timestamp);
        
        // Format time difference
        if($interval->d > 0) {
            $time_ago = $interval->d . ' day' . ($interval->d > 1 ? 's' : '') . ' ago';
        } elseif($interval->h > 0) {
            $time_ago = $interval->h . ' hour' . ($interval->h > 1 ? 's' : '') . ' ago';
        } elseif($interval->i > 0) {
            $time_ago = $interval->i . ' minute' . ($interval->i > 1 ? 's' : '') . ' ago';
        } else {
            $time_ago = 'Just now';
        }
        
        // Get action icon and color
        $action_icon = 'fa-circle-info';
        $action_color = '#664C47';
        
        if(strpos($r['action'], 'Add') !== false) {
            $action_icon = 'fa-plus-circle';
            $action_color = '#15803d';
        } elseif(strpos($r['action'], 'Delete') !== false) {
            $action_icon = 'fa-trash';
            $action_color = '#dc2626';
        } elseif(strpos($r['action'], 'Edit') !== false || strpos($r['action'], 'Update') !== false) {
            $action_icon = 'fa-edit';
            $action_color = '#0369a1';
        } elseif(strpos($r['action'], 'View') !== false || strpos($r['action'], 'Export') !== false) {
            $action_icon = 'fa-eye';
            $action_color = '#7c3aed';
        }
        
        // Exact timestamp for tooltip
        $exact_time = $timestamp->format('M d, Y • h:i A');
        
        ?>
        <div class="activity-item" title="<?php echo htmlspecialchars($exact_time); ?>">
            <div class="activity-icon" style="color: <?php echo $action_color; ?>;">
                <i class="fa-solid <?php echo $action_icon; ?>"></i>
            </div>
            <div class="activity-content">
                <div class="activity-action" style="color: <?php echo $action_color; ?>;">
                    <?php echo htmlspecialchars($r['action']); ?>
                </div>
                <div class="activity-time">
                    <?php echo htmlspecialchars($r['admin_name'] ?? 'Admin'); ?> • <?php echo htmlspecialchars($time_ago); ?>
                </div>
                <?php if(!empty($r['description'])): ?>
                <div class="activity-details">
                    <?php echo htmlspecialchars($r['description']); ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
?>
