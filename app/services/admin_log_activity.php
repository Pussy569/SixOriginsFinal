<?php
// admin_log_activity.php
include_once dirname(__DIR__, 2) . '/config.php';

function log_admin_activity($admin_id, $action, $description = '', $result = 'success', $record_type = null, $record_id = null, $before_values = null, $after_values = null) {
    global $conn;

    return write_admin_activity(
        $admin_id,
        $action,
        $description,
        $result,
        $record_type,
        $record_id,
        $before_values,
        $after_values,
        $_SESSION['admin_name'] ?? null,
        $_SESSION['admin_email'] ?? null
    );
}

function write_admin_activity($admin_id, $action, $description = '', $result = 'success', $record_type = null, $record_id = null, $before_values = null, $after_values = null, $admin_name = null, $admin_email = null) {
    global $conn;

    $admin_id = is_numeric($admin_id) ? (int)$admin_id : 0;
    $action = substr((string)$action, 0, 255);
    $description = (string)$description;
    $result = $result === 'failure' ? 'failure' : 'success';
    $record_type = $record_type !== null ? substr((string)$record_type, 0, 80) : null;
    $record_id = is_numeric($record_id) ? (int)$record_id : null;
    $admin_name = $admin_name !== null ? substr((string)$admin_name, 0, 100) : null;
    $admin_email = $admin_email !== null ? substr((string)$admin_email, 0, 100) : null;
    $ip_address = $_SERVER['REMOTE_ADDR'] ?? null;
    $before_json = $before_values === null ? null : json_encode($before_values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    $after_json = $after_values === null ? null : json_encode($after_values, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

    try {
        if ($admin_id > 0 && ($admin_name === null || $admin_email === null)) {
            $actor = $conn->prepare('SELECT name, email FROM users WHERE id = ? LIMIT 1');
            if ($actor) {
                $actor->bind_param('i', $admin_id);
                $actor->execute();
                $actor->bind_result($stored_name, $stored_email);
                if ($actor->fetch()) {
                    $admin_name = $admin_name ?? $stored_name;
                    $admin_email = $admin_email ?? $stored_email;
                }
                $actor->close();
            }
        }

        $stmt = $conn->prepare(
            'INSERT INTO admin_activity
                (admin_id, action, description, ip_address, timestamp, result, record_type, record_id, before_values, after_values, admin_name_snapshot, admin_email_snapshot)
             VALUES (?, ?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->bind_param(
            'isssssissss',
            $admin_id,
            $action,
            $description,
            $ip_address,
            $result,
            $record_type,
            $record_id,
            $before_json,
            $after_json,
            $admin_name,
            $admin_email
        );
        $saved = $stmt->execute();
        $stmt->close();
        if (!$saved) {
            error_log('Could not save admin activity record.');
        }
        return $saved;
    } catch (mysqli_sql_exception $e) {
        error_log('Could not save admin activity record: ' . $e->getMessage());
        return false;
    }
}

function is_head_admin(): bool
{
    $configured_id = getenv('HEAD_ADMIN_ID');
    if ($configured_id === false || $configured_id === '') {
        $head_admin_id = 1;
    } elseif (ctype_digit($configured_id)) {
        $head_admin_id = (int)$configured_id;
    } else {
        return false;
    }
    return $head_admin_id > 0 && isset($_SESSION['admin_id']) && (int)$_SESSION['admin_id'] === $head_admin_id;
}
?>