<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';
require_once __DIR__ . '/smtp_settings.php';

function sendResetLink($email, $reset_link){
    global $SMTP_SETTINGS;
    if ($SMTP_SETTINGS['username'] === '' || $SMTP_SETTINGS['password'] === '') {
        error_log('Six Origins mail error: SMTP credentials are not configured.');
        return false;
    }

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host       = $SMTP_SETTINGS['host'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $SMTP_SETTINGS['username'];
        $mail->Password   = $SMTP_SETTINGS['password'];
        $mail->SMTPSecure = $SMTP_SETTINGS['encryption'];
        $mail->Port       = $SMTP_SETTINGS['port'];


        $mail->setFrom($SMTP_SETTINGS['from_email'], $SMTP_SETTINGS['from_name']);
        $mail->addAddress($email); 

     
        $mail->isHTML(true);
        $mail->Subject = 'Password Reset Request';
        $mail->Body    = "Click the link below to reset your password:<br><br>
                         <a href='$reset_link'>$reset_link</a>";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('Six Origins password-reset email failed: ' . $mail->ErrorInfo);
        return false;
    }
}
?>
