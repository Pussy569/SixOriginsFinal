<?php
// Handle cookie consent
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $consent = isset($_POST['consent']) ? $_POST['consent'] : 'deny';
    
    if ($consent === 'accept') {
        setcookie('cookies_accepted', 'yes', time() + (365 * 24 * 60 * 60), '/');
        setcookie('cookie_consent_time', date('Y-m-d H:i:s'), time() + (365 * 24 * 60 * 60), '/');
    } else {
        setcookie('cookies_accepted', 'no', time() + (365 * 24 * 60 * 60), '/');
    }
    
    header('Content-Type: application/json');
    echo json_encode(['status' => 'success']);
    exit;
}
?>