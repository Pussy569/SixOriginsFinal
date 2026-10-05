<?php
include 'config.php';

function generateDiscountCode($prefix = 'DISC', $length = 6) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $random = '';
    for ($i = 0; $i < $length; $i++) {
        $random .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $prefix . $random;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = generateDiscountCode();
    $percentage = intval($_POST['percentage']);
    $expires_at = $_POST['expires'];
    $limit = intval($_POST['limit']);

    $stmt = $conn->prepare("INSERT INTO discount_codes (code, percentage, expires_at, usage_limit) VALUES (?, ?, ?, ?)");
    $stmt->bind_param("sisi", $code, $percentage, $expires_at, $limit);
    if ($stmt->execute()) {
        header("Location: admin_discounts.php?msg=Code+$code+created+successfully");
    } else {
        echo "Error: " . $stmt->error;
    }
    $stmt->close();
}
?>
