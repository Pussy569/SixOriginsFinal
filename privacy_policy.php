<?php
session_start();
?>
<!DOCTYPE html>
<html lang="en">
<head>
   <meta charset="UTF-8" />
   <meta name="viewport" content="width=device-width, initial-scale=1.0" />
   <title>Privacy Policy | Six Origins Cafe</title>

   <link rel="preconnect" href="https://fonts.googleapis.com">
   <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
   <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">

   <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

   <style>
      :root {
        --primary-red: #C6453E;
        --dark-brown: #5E1F13;
        --gray-brown: #664C47;
        --light-cream: #FFF2E0;
        --white: #FFFFFF;
      }

      * {
         box-sizing: border-box;
         margin: 0;
         padding: 0;
         font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
      }

      html, body {
         width: 100%;
         background: white;
      }

      body {
         color: var(--dark-brown);
      }

      header {
         background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
         padding: 20px 40px;
         display: flex;
         align-items: center;
         justify-content: space-between;
         box-shadow: 0 2px 8px rgba(94, 31, 19, 0.08);
      }

      .logo-section {
         display: flex;
         align-items: center;
         gap: 12px;
      }

      .logo-section img {
         width: 50px;
         height: 50px;
         border-radius: 8px;
         object-fit: contain;
      }

      .logo-section h1 {
         font-size: 1.5rem;
         font-weight: 900;
         color: var(--dark-brown);
      }

      .back-btn {
         background: var(--primary-red);
         color: white;
         border: none;
         padding: 10px 20px;
         border-radius: 8px;
         cursor: pointer;
         font-weight: 700;
         text-decoration: none;
         display: inline-flex;
         align-items: center;
         gap: 8px;
         transition: all 0.3s ease;
      }

      .back-btn:hover {
         background: #B83A34;
         transform: translateX(-3px);
      }

      .container {
         max-width: 900px;
         margin: 0 auto;
         padding: 40px;
      }

      h2 {
         font-size: 2rem;
         font-weight: 900;
         color: var(--dark-brown);
         margin-top: 40px;
         margin-bottom: 20px;
         border-bottom: 3px solid var(--primary-red);
         padding-bottom: 12px;
      }

      h3 {
         font-size: 1.3rem;
         font-weight: 800;
         color: var(--dark-brown);
         margin-top: 24px;
         margin-bottom: 12px;
      }

      p {
         font-size: 0.95rem;
         line-height: 1.8;
         color: var(--gray-brown);
         margin-bottom: 16px;
      }

      ul, ol {
         margin-left: 20px;
         margin-bottom: 16px;
      }

      li {
         margin-bottom: 8px;
         color: var(--gray-brown);
         line-height: 1.6;
      }

      .highlight {
         background: rgba(198, 69, 62, 0.1);
         padding: 20px;
         border-left: 4px solid var(--primary-red);
         border-radius: 8px;
         margin: 20px 0;
      }

      footer {
         background: var(--dark-brown);
         color: white;
         text-align: center;
         padding: 20px;
         margin-top: 40px;
      }

      @media (max-width: 768px) {
         header {
            padding: 16px 24px;
            flex-direction: column;
            gap: 16px;
            text-align: center;
         }

         .container {
            padding: 20px;
         }

         h2 {
            font-size: 1.5rem;
         }

         h3 {
            font-size: 1.1rem;
         }

         p, li {
            font-size: 0.9rem;
         }
      }
   </style>
</head>
<body>

   <header>
      <div class="logo-section">
         <img src="images/logos.png" alt="Six Origins Logo">
         <h1>Six Origins Cafe</h1>
      </div>
      <a href="login.php" class="back-btn"><i class="fas fa-arrow-left"></i> Back to Login</a>
   </header>

   <div class="container">
      <h2>Privacy Policy</h2>
      <p><strong>Last Updated: <?php echo date('F d, Y'); ?></strong></p>

      <h3>1. Introduction</h3>
      <p>
         Six Origins Cafe ("we", "us", "our") operates the website. This page informs you of our policies 
         regarding the collection, use, and disclosure of personal data when you use our service and the 
         choices you have associated with that data.
      </p>

      <h3>2. Information Collection and Use</h3>
      <p>We collect several different types of information for various purposes to provide and improve our service:</p>
      <ul>
         <li><strong>Personal Data:</strong> Email address, name, password, phone number</li>
         <li><strong>Usage Data:</strong> Browser type, IP address, pages visited, time spent on pages</li>
         <li><strong>Cookies:</strong> Small files stored on your device for authentication and analytics</li>
         <li><strong>Verification Images:</strong> Document images for admin account verification (encrypted storage)</li>
      </ul>

      <h3>3. Use of Data</h3>
      <p>Six Origins Cafe uses the collected data for various purposes:</p>
      <ul>
         <li>To provide and maintain our service</li>
         <li>To notify you about changes to our service</li>
         <li>To allow you to participate in interactive features</li>
         <li>To provide customer support</li>
         <li>To verify user identity and prevent fraud</li>
         <li>To gather analysis or valuable information to improve our service</li>
         <li>To monitor the usage of our service</li>
      </ul>

      <div class="highlight">
         <strong>🔒 Security Note:</strong> Your password is encrypted using bcrypt hashing. Verification 
         images are stored securely with restricted access. We do not share your personal data with third parties 
         without your consent.
      </div>

      <h3>4. Cookie Policy</h3>
      <p>
         We use cookies to enhance your experience on our platform. Cookies are small data files stored on your 
         device that help us remember your preferences and track your usage patterns.
      </p>
      <ul>
         <li><strong>Session Cookies:</strong> Maintain your login session (required)</li>
         <li><strong>Authentication Cookies:</strong> Verify your identity and security</li>
         <li><strong>Analytics Cookies:</strong> Help us understand how you use our service</li>
         <li><strong>Preference Cookies:</strong> Remember your settings and choices</li>
      </ul>

      <h3>5. Two-Factor Authentication (2FA)</h3>
      <p>
         For added security, we offer optional 2FA via email verification codes. When enabled, you'll receive 
         a verification code via email during login. This code is sent using SMTP mail service and expires after 10 minutes.
      </p>

      <h3>6. Data Retention</h3>
      <p>
         We retain your personal data only for as long as necessary to provide our service and fulfill the 
         purposes outlined in this policy. You can request data deletion at any time by contacting us.
      </p>

      <h3>7. Your Rights</h3>
      <p>You have the right to:</p>
      <ul>
         <li>Access your personal data</li>
         <li>Correct inaccurate data</li>
         <li>Request deletion of your data</li>
         <li>Opt-out of marketing communications</li>
         <li>Withdraw consent for data processing</li>
      </ul>

      <h3>8. Contact Us</h3>
      <p>
         If you have any questions about this Privacy Policy or our data practices, please contact us at:
      </p>
      <p>
         📧 <strong>Email:</strong> privacy@sixorigins.com<br>
         📍 <strong>Address:</strong> Six Origins Cafe, Coffee Street, City<br>
         📞 <strong>Phone:</strong> +1 (555) 123-4567
      </p>

      <h3>9. Changes to This Privacy Policy</h3>
      <p>
         We may update our Privacy Policy from time to time. We will notify you of any changes by posting 
         the new Privacy Policy on this page and updating the "Last Updated" date at the top.
      </p>

   </div>

   <footer>
      <p <a href="privacy_policy.php" style="color: white; text-decoration: none;"></a></a>&copy; 2024 Six Origins Cafe. All rights reserved. | <a href="privacy_policy.php" style="color: white; text-decoration: none;">Privacy Policy</a></p>
   </footer>

</body>
</html>