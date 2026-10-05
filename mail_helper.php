<?php
/**
 * mail_helper.php — Six Origins email notifications
 * Include with:  require_once 'mail_helper.php';
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// PHPMailer loading: Composer first, then manual folder fallback
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
   require_once __DIR__ . '/vendor/autoload.php';
} else {
   require_once __DIR__ . '/PHPMailer/src/Exception.php';
   require_once __DIR__ . '/PHPMailer/src/PHPMailer.php';
   require_once __DIR__ . '/PHPMailer/src/SMTP.php';
}

if (!defined('MAIL_USERNAME')) {
   define('MAIL_USERNAME', getenv('MAIL_USERNAME') ?: '');
}
if (!defined('MAIL_APP_PASSWORD')) {
   define('MAIL_APP_PASSWORD', getenv('MAIL_APP_PASSWORD') ?: '');
}
if (!defined('MAIL_FROM_NAME')) {
   define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Six Origins');
}

/** Branded HTML wrapper */
function six_origins_email_layout($title, $bodyHtml) {
   return '
   <div style="background:#FFF2E0;padding:30px 12px;font-family:Arial,Helvetica,sans-serif;">
     <div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #F0E6D8;">
       <div style="background:#C6453E;color:#ffffff;padding:22px 28px;">
         <h1 style="margin:0;font-size:22px;">☕ Six Origins</h1>
       </div>
       <div style="padding:28px;color:#5E1F13;font-size:15px;line-height:1.6;">
         <h2 style="margin-top:0;color:#C6453E;">' . $title . '</h2>
         ' . $bodyHtml . '
       </div>
       <div style="background:#FFF2E0;color:#664C47;padding:16px 28px;font-size:12px;text-align:center;">
         This is an automated message from Six Origins. Please do not reply to this email.
       </div>
     </div>
   </div>';
}

/** Core sender. Returns true on success, false on failure (never throws). */
function send_six_origins_mail($toEmail, $toName, $subject, $html) {
   if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
      return false;
   }
   if (MAIL_USERNAME === '' || MAIL_APP_PASSWORD === '') {
      error_log('Six Origins mail error: SMTP credentials are not configured.');
      return false;
   }

   $mail = new PHPMailer(true);
   try {
      $mail->isSMTP();
      $mail->Host       = 'smtp.gmail.com';
      $mail->SMTPAuth   = true;
      $mail->Username   = MAIL_USERNAME;
      $mail->Password   = MAIL_APP_PASSWORD;
      $mail->SMTPSecure = 'tls';
      $mail->Port       = 587;
      $mail->CharSet    = 'UTF-8';

      $mail->setFrom(MAIL_USERNAME, MAIL_FROM_NAME);
      $mail->addAddress($toEmail, $toName);

      $mail->isHTML(true);
      $mail->Subject = $subject;
      $mail->Body    = $html;
      $mail->AltBody = trim(strip_tags(str_replace(['</p>', '<br>', '<br/>'], "\n", $html)));

      $mail->send();
      return true;
   } catch (Exception $e) {
      error_log('Six Origins mail error: ' . $mail->ErrorInfo);
      return false;
   }
}

/** Sent when the Head Admin approves an account (admin, rider, senior, PWD, etc.) */
function send_approval_email($name, $email, $user_type) {
   $safeName = htmlspecialchars($name);
   $type     = strtolower($user_type);

   if ($type === 'admin') {
      $subject = 'Your Six Origins admin account has been approved';
      $title   = 'Admin Account Approved ✅';
      $body    = '<p>Hi <strong>' . $safeName . '</strong>,</p>
                  <p>Good news! The <strong>Head Admin</strong> has reviewed and <strong>approved</strong> the admin account you created.</p>
                  <p>You can now log in and start managing the Six Origins system.</p>';
   } elseif ($type === 'delivery_rider') {
      $subject = 'Your Six Origins rider account has been approved';
      $title   = 'Rider Account Approved ✅';
      $body    = '<p>Hi <strong>' . $safeName . '</strong>,</p>
                  <p>Your delivery rider account has been <strong>approved</strong>. You can now log in and start accepting deliveries.</p>';
   } else {
      $subject = 'Your Six Origins account has been approved';
      $title   = 'Account Approved ✅';
      $body    = '<p>Hi <strong>' . $safeName . '</strong>,</p>
                  <p>Your account (and verification, if submitted) has been <strong>approved</strong>. You can now log in and enjoy Six Origins.</p>';
   }

   $body .= '<p style="margin-top:24px;">Thank you,<br><strong>The Six Origins Team</strong></p>';

   return send_six_origins_mail($email, $name, $subject, six_origins_email_layout($title, $body));
}

/** Sent right after a customer registers (use this in your register/signup page) */
function send_welcome_email($name, $email) {
   $safeName = htmlspecialchars($name);
   $subject  = 'Welcome to Six Origins ☕';
   $title    = 'Welcome, ' . $safeName . '!';
   $body     = '<p>Thank you for creating an account and visiting our coffee shop website!</p>
                <p>Your account is ready — you can now log in, browse our menu, place orders, and enjoy your favorite brews.</p>
                <p style="margin-top:24px;">We\'re glad to have you with us.<br><strong>The Six Origins Team</strong></p>';

   return send_six_origins_mail($email, $name, $subject, six_origins_email_layout($title, $body));
}