<?php
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_id'])) {
    http_response_code(403);
    exit('Forbidden');
}

$request_id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$request_id || $request_id < 1) {
    http_response_code(404);
    exit('Not found');
}

$stmt = $conn->prepare('SELECT screenshot FROM topup_requests WHERE id = ? LIMIT 1');
if (!$stmt) {
    error_log('Could not prepare top-up proof lookup: ' . $conn->error);
    http_response_code(500);
    exit('Unable to load proof.');
}
$stmt->bind_param('i', $request_id);
if (!$stmt->execute()) {
    error_log('Could not execute top-up proof lookup: ' . $stmt->error);
    $stmt->close();
    http_response_code(500);
    exit('Unable to load proof.');
}
$stmt->bind_result($proof_name);
$found = $stmt->fetch();
$stmt->close();

if (!$found || !is_string($proof_name) || !preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,199}\.(?:jpe?g|png|webp)\z/i', $proof_name)) {
    http_response_code(404);
    exit('Not found');
}

$proof_dir = getenv('TOPUP_PROOF_DIR') ?: (sys_get_temp_dir() . '/six-origins-topup-proofs');
$proof_path = rtrim($proof_dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $proof_name;
if (!is_file($proof_path) || !is_readable($proof_path)) {
    http_response_code(404);
    exit('Not found');
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $proof_path) : false;
if ($finfo) {
    finfo_close($finfo);
}
$allowed_mimes = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
$extension = strtolower(pathinfo($proof_name, PATHINFO_EXTENSION));
if (!is_string($mime) || ($allowed_mimes[$extension] ?? '') !== $mime) {
    error_log('Top-up proof file failed content-type validation.');
    http_response_code(404);
    exit('Not found');
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($proof_path));
header('Content-Disposition: inline; filename="payment-proof.' . $extension . '"');
header('Cache-Control: private, no-store, max-age=0');
header('X-Content-Type-Options: nosniff');
readfile($proof_path);
