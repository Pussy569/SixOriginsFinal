<?php

function verificationUploadDirectory(): string
{
    $configured_dir = getenv('VERIFICATION_UPLOAD_DIR');
    if (is_string($configured_dir) && $configured_dir !== '') {
        return rtrim($configured_dir, DIRECTORY_SEPARATOR);
    }

    return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'uploads';
}

function ensureVerificationUploadDirectory(): ?string
{
    $upload_dir = verificationUploadDirectory();
    if (!is_dir($upload_dir) && !@mkdir($upload_dir, 0755, true) && !is_dir($upload_dir)) {
        $error = error_get_last();
        error_log('Could not create admin verification upload directory "' . $upload_dir . '": ' . ($error['message'] ?? 'unknown filesystem error'));
        return null;
    }

    if (!is_writable($upload_dir)) {
        error_log('Admin verification upload directory is not writable: ' . $upload_dir);
        return null;
    }

    return $upload_dir;
}
