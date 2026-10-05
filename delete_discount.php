<?php
include 'config.php';
session_start();

$admin_id = $_SESSION['admin_id'] ?? null;
if (!$admin_id) {
    header('location:login.php');
    exit;
}

if (isset($_GET['id'])) {
    $id = intval($_GET['id']);
    $delete = $conn->prepare("DELETE FROM discounts WHERE id = ?");
    $delete->bind_param("i", $id);
    $delete->execute();
    $delete->close();
}

header("Location: admin_discount.php");
exit;
