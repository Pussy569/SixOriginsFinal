<?php
/**
 * Shared Resend email transport and Six Origins account notifications.
 */

require_once dirname(__DIR__, 2) . '/vendor/autoload.php';

if (!defined('RESEND_API_KEY')) {
   define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
}
if (!defined('MAIL_FROM_EMAIL')) {
   define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: '');
}
if (!defined('MAIL_FROM_NAME')) {
   define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Six Origins');
}

/** Branded HTML wrapper */
function six_origins_email_layout($title, $bodyHtml, ?string $footerText = null) {
   global $conn, $site_customization;
   $settings = is_array($site_customization ?? null) ? $site_customization : [];
   if ($settings === [] && ($conn ?? null) instanceof mysqli) {
      require_once dirname(__DIR__) . '/helpers/site_customization.php';
      $settings = get_site_customization($conn);
   }
   $primary = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['primary_color'] ?? '')) ? $settings['primary_color'] : '#C6453E';
   $secondary = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['secondary_color'] ?? '')) ? $settings['secondary_color'] : '#5E1F13';
   $pageBackground = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['page_background'] ?? '')) ? $settings['page_background'] : '#FFF2E0';
   $surface = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['surface_color'] ?? '')) ? $settings['surface_color'] : '#FFFFFF';
   $text = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['text_color'] ?? '')) ? $settings['text_color'] : $secondary;
   $muted = preg_match('/^#[0-9A-Fa-f]{6}$/', (string)($settings['accent_color'] ?? '')) ? $settings['accent_color'] : '#664C47';
   $brandName = htmlspecialchars((string)($settings['brand_name'] ?? 'Six Origins'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $footer = htmlspecialchars(
      $footerText ?? 'This is an automated message from ' . (string)($settings['brand_name'] ?? 'Six Origins') . '. Please do not reply to this email.',
      ENT_QUOTES | ENT_SUBSTITUTE,
      'UTF-8'
   );
   return '
   <div style="background:' . $pageBackground . ';padding:30px 12px;font-family:Arial,Helvetica,sans-serif;">
     <div style="max-width:560px;margin:0 auto;background:' . $surface . ';border-radius:16px;overflow:hidden;border:1px solid ' . $muted . ';">
       <div style="background:' . $primary . ';color:#ffffff;padding:22px 28px;">
         <h1 style="margin:0;font-size:22px;">' . $brandName . '</h1>
       </div>
       <div style="padding:28px;color:' . $text . ';font-size:15px;line-height:1.6;">
         <h2 style="margin-top:0;color:' . $primary . ';">' . $title . '</h2>
         ' . $bodyHtml . '
       </div>
       <div style="background:' . $pageBackground . ';color:' . $muted . ';padding:16px 28px;font-size:12px;text-align:center;">
         ' . $footer . '
       </div>
     </div>
   </div>';
}

/** Send mail through Resend. */
function send_six_origins_mail($toEmail, $toName, $subject, $html, $text = null, $replyTo = null): bool {
   if (empty($toEmail) || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
      error_log('Six Origins mail error: recipient email address is invalid.');
      return false;
   }
   if (RESEND_API_KEY === '' || MAIL_FROM_EMAIL === '') {
      error_log('Six Origins mail error: Resend API key or sender email is not configured.');
      return false;
   }

   $plainText = $text ?? trim(strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />'], "\n", $html)));
   $message = [
         'from' => MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
         'to' => [$toEmail],
         'subject' => $subject,
         'html' => $html,
         'text' => $plainText,
   ];
   if (is_string($replyTo) && filter_var($replyTo, FILTER_VALIDATE_EMAIL)) {
      $message['reply_to'] = $replyTo;
   }

   try {
      Resend::client(RESEND_API_KEY)->emails->send($message);
      return true;
   } catch (Throwable $e) {
      error_log('Six Origins Resend email failed: ' . $e->getMessage());
      return false;
   }
}

/** Sent when the Head Admin approves an account (admin, rider, senior, PWD, etc.) */
function send_approval_email($name, $email, $user_type) {
   $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $type = strtolower($user_type);

   if ($type === 'admin') {
      $subject = 'Your Six Origins admin account has been approved';
      $title = 'Admin Account Approved ✅';
      $body = '<p>Hi <strong>' . $safeName . '</strong>,</p>
               <p>Good news! The <strong>Head Admin</strong> has reviewed and <strong>approved</strong> the admin account you created.</p>
               <p>You can now log in and start managing the Six Origins system.</p>';
   } elseif ($type === 'delivery_rider') {
      $subject = 'Your Six Origins rider account has been approved';
      $title = 'Rider Account Approved ✅';
      $body = '<p>Hi <strong>' . $safeName . '</strong>,</p>
               <p>Your delivery rider account has been <strong>approved</strong>. You can now log in and start accepting deliveries.</p>';
   } else {
      $subject = 'Your Six Origins account has been approved';
      $title = 'Account Approved ✅';
      $body = '<p>Hi <strong>' . $safeName . '</strong>,</p>
               <p>Your account (and verification, if submitted) has been <strong>approved</strong>. You can now log in and enjoy Six Origins.</p>';
   }

   $body .= '<p style="margin-top:24px;">Thank you,<br><strong>The Six Origins Team</strong></p>';
   return send_six_origins_mail($email, $name, $subject, six_origins_email_layout($title, $body));
}

/** Sent right after a customer registers. */
function send_welcome_email($name, $email) {
   $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $subject = 'Welcome to Six Origins ☕';
   $title = 'Welcome, ' . $safeName . '!';
   $body = '<p>Thank you for creating an account and visiting our coffee shop website!</p>
            <p>Your account is ready — you can now log in, browse our menu, place orders, and enjoy your favorite brews.</p>
            <p style="margin-top:24px;">We\'re glad to have you with us.<br><strong>The Six Origins Team</strong></p>';

   return send_six_origins_mail($email, $name, $subject, six_origins_email_layout($title, $body));
}

function send_signup_verification_mail(string $name, string $email, string $code, array $settings = []): bool {
   require_once dirname(__DIR__) . '/helpers/site_customization.php';
   $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safeCode = htmlspecialchars($code, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $subject = site_customization_email_subject($settings, 'verification_signup_email_subject');
   $emailTitle = htmlspecialchars(site_customization_text($settings, 'verification_signup_email_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $greeting = str_replace('{name}', $safeName, htmlspecialchars(site_customization_text($settings, 'verification_signup_email_greeting'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
   $instruction = htmlspecialchars(site_customization_text($settings, 'verification_signup_email_instruction'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $note = nl2br(htmlspecialchars(site_customization_text($settings, 'verification_signup_email_note'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
   $footer = site_customization_text($settings, 'verification_email_footer');
   $body = '<p>' . $greeting . '</p>
            <p>' . $instruction . '</p>
            <p style="font-size:28px;font-weight:800;letter-spacing:8px;">' . $safeCode . '</p>
            <p>' . $note . '</p>';
   return send_six_origins_mail(
      $email,
      $name,
      $subject,
      six_origins_email_layout($emailTitle, $body, $footer),
      'Your Six Origins verification code is ' . $code . '. It expires in 15 minutes.'
   );
}

/** Notify a customer who previously ordered a product about its updated details. */
function send_product_update_email(string $name, string $email, string $productName, $price, string $details): bool {
   $safeName = htmlspecialchars($name, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safeProductName = htmlspecialchars($productName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
   $safeDetails = nl2br(htmlspecialchars($details, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
   $body = '<p>Hi <strong>' . $safeName . '</strong>,</p>
            <p>We have updated <strong>' . $safeProductName . '</strong>, a product you have ordered from Six Origins.</p>
            <p><strong>Current price:</strong> ₱' . number_format((float)$price, 2) . '</p>';
   if ($details !== '') {
      $body .= '<p><strong>Product details:</strong><br>' . $safeDetails . '</p>';
   }
   $body .= '<p>Visit Six Origins to see the latest menu.</p>';

   return send_six_origins_mail(
      $email,
      $name,
      'Product update: ' . $productName,
      six_origins_email_layout('A product you ordered was updated', $body)
   );
}
