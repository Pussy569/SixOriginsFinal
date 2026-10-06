<?php
require_once __DIR__ . '/mail_helper.php';

function sendResetLink($email, $reset_link): bool {
    $safeResetLink = htmlspecialchars($reset_link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<p>Click the link below to reset your password:</p>
             <p><a href="' . $safeResetLink . '">Reset your password</a></p>
             <p>If you did not request a password reset, you can ignore this email.</p>';
    $text = "Click the link below to reset your password:\n" . $reset_link . "\n\nIf you did not request a password reset, you can ignore this email.";

    return send_six_origins_mail($email, '', 'Password Reset Request', $html, $text);
}
