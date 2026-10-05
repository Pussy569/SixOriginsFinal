<?php
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$user_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$user_id || $user_id < 1) {
    http_response_code(404);
    exit('Not found');
}

$stmt = $conn->prepare('SELECT verification_image FROM users WHERE id = ? LIMIT 1');
if (!$stmt) {
    error_log('Could not prepare verification document lookup: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load document.');
}
$stmt->bind_param('i', $user_id);
if (!$stmt->execute()) {
    error_log('Could not execute verification document lookup: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('Unable to load document.');
}
$stmt->bind_result($document_name);
$found = $stmt->fetch();
$stmt->close();

if (!$found || !is_string($document_name) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,199}\.(?:jpe?g|png|webp)\z/i', $document_name)) {
    http_response_code(404);
    exit('Not found');
}

$uploads_root = realpath(__DIR__ . '/uploads');
$document_path = realpath(__DIR__ . '/uploads/' . $document_name);
if ($uploads_root === false || $document_path === false || !str_starts_with($document_path, $uploads_root . DIRECTORY_SEPARATOR) || !is_readable($document_path)) {
    http_response_code(404);
    exit('Not found');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $document_path) : false;
if ($finfo) {
    finfo_close($finfo);
}
$allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$extension = strtolower(pathinfo($document_name, PATHINFO_EXTENSION));
if (!is_string($mime) || ($allowed_mimes[$extension] ?? '') !== $mime) {
    error_log('Verification document failed content-type validation.');
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($document_path));
header('Content-Disposition: inline; filename="verification-document.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($document_path);
