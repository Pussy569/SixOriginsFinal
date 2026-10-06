<?php
// Enhanced footer — purely visual, no server-side logic.
// NOTE: this file is an include fragment. The page that includes it (via header.php)
// already provides <!DOCTYPE>, <html>, <head> and <body>, so they are not repeated here.
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />
<style>
:root {
  --primary-red: #C6453E;
  --dark-brown: #5E1F13;
  --gray-brown: #664C47;
  --light-cream: #FFF2E0;
  --white: #FFFFFF;
  --radius: 16px;
  --shadow: 0 10px 30px rgba(94, 31, 19, 0.08);
  --shadow-hover: 0 15px 40px rgba(94, 31, 19, 0.2);
  --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
}

/* Scoped to the footer so it can't override the page's own reset/background */
.site-footer,
.site-footer * {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
  font-family: 'Montserrat', system-ui, -apple-system, 'Segoe UI', sans-serif;
}

.site-footer {
  background: linear-gradient(135deg, var(--primary-red) 0%, var(--dark-brown) 100%);
  color: #D7D0C4;
  padding: 52px 24px 0 24px;
  margin-top: 64px;
  box-shadow: 0 -10px 30px rgba(94, 31, 19, 0.1);
  position: relative;
  overflow: hidden;
  border-top: 1px solid rgba(255, 255, 255, 0.05);
  width: 100%;
}

.site-footer::before {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: 
    radial-gradient(circle at 20% 50%, rgba(255, 242, 224, 0.08) 0%, transparent 50%),
    radial-gradient(circle at 80% 80%, rgba(102, 76, 71, 0.05) 0%, transparent 50%);
  pointer-events: none;
}

.footer-inner {
  max-width: 1200px;
  margin: 0 auto;
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(min(100%, 240px), 1fr));
  gap: 44px;
  align-items: start;
  position: relative;
  z-index: 1;
}

.footer-inner > * { min-width: 0; }

/* Footer Brand Section */
.footer-brand {
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 18px;
}

.footer-brand-icon {
  font-size: 2.6rem;
  animation: float 3s ease-in-out infinite;
}

@keyframes float {
  0%, 100% { transform: translateY(0px); }
  50% { transform: translateY(-6px); }
}

.footer-brand-text h1 {
  font-family: 'Romelio Sans', serif;
  font-size: 1.85rem;
  font-weight: 900;
  color: #FFFBF7;
  letter-spacing: -0.5px;
  margin: 0;
  line-height: 1.1;
}

.footer-brand-text .accent {
  font-family: 'Montserrat', sans-serif;
  color: #FFF2E0;
  font-weight: 700;
  font-size: 0.85em;
  display: block;
  text-transform: uppercase;
  letter-spacing: 1px;
  margin-top: 4px;
}

.footer-brand p {
  margin-top: 14px;
  color: #D7D0C4;
  font-size: 0.95rem;
  font-weight: 500;
  line-height: 1.6;
  max-width: 280px;
}

/* Footer Sections */
.footer-section {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.footer-section h3 {
  color: #FFFBF7;
  margin: 0;
  font-size: 1.15rem;
  font-weight: 800;
  letter-spacing: 0.5px;
  text-transform: uppercase;
  display: flex;
  align-items: center;
  gap: 10px;
  font-family: 'Romelio Sans', serif;
}

.footer-section h3 i {
  color: #FFF2E0;
  font-size: 1.2rem;
}

.footer-section p, 
.footer-section a {
  color: #D7D0C4;
  font-size: 0.95rem;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 10px;
  transition: var(--transition);
  padding: 6px 0;
}

.footer-section a {
  cursor: pointer;
}

.footer-section a:hover {
  color: #FFF2E0;
  transform: translateX(4px);
}

.footer-section p i,
.footer-section a i {
  color: #FFF2E0;
  font-size: 1.08rem;
  width: 20px;
  text-align: center;
  flex-shrink: 0;
}

/* Contact Info */
.contact-item {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  padding: 8px 0;
  color: #D7D0C4;
  font-size: 0.95rem;
  line-height: 1.5;
}

.contact-item span {
  min-width: 0;
  overflow-wrap: anywhere;
}

.contact-item i {
  color: #FFF2E0;
  margin-top: 2px;
  flex-shrink: 0;
  font-size: 1.08rem;
  width: 20px;
}

/* Social Links */
.socials {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  margin-top: 10px;
}

.socials a {
  color: #FFFBF7;
  background: rgba(255, 242, 224, 0.15);
  border: 1.5px solid rgba(255, 242, 224, 0.2);
  padding: 10px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  transition: var(--transition);
  font-size: 1.05rem;
}

.socials a:hover {
  background: #FFF2E0;
  color: var(--dark-brown);
  border-color: #FFF2E0;
  transform: translateY(-3px);
  box-shadow: 0 8px 20px rgba(255, 242, 224, 0.35);
}

/* Quick Links Grid */
.quick-links {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.quick-links a {
  padding: 11px 14px;
  background: rgba(255, 255, 255, 0.06);
  border-radius: 10px;
  border: 1.5px solid rgba(255, 242, 224, 0.1);
  color: #D7D0C4;
  text-decoration: none;
  font-size: 0.95rem;
  transition: var(--transition);
  font-weight: 600;
}

.quick-links a:hover {
  background: rgba(255, 242, 224, 0.15);
  border-color: #FFF2E0;
  color: #FFF2E0;
  transform: translateX(4px);
}

/* Newsletter */
.newsletter-form {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.newsletter-form input {
  width: 100%;
  padding: 12px 14px;
  border: 1.5px solid rgba(255, 242, 224, 0.2);
  border-radius: 10px;
  background: rgba(255, 255, 255, 0.08);
  color: #FFFBF7;
  font-size: 0.95rem;
  transition: var(--transition);
  font-weight: 500;
}

.newsletter-form input::placeholder {
  color: #D7D0C4;
  font-weight: 500;
}

.newsletter-form input:focus {
  outline: none;
  border-color: #FFF2E0;
  background: rgba(255, 255, 255, 0.12);
  box-shadow: 0 0 0 3px rgba(255, 242, 224, 0.12);
}

.newsletter-form button {
  padding: 12px 18px;
  background: linear-gradient(135deg, #FFF2E0 0%, #FFE8C4 100%);
  color: var(--dark-brown);
  border: none;
  border-radius: 10px;
  font-weight: 800;
  cursor: pointer;
  transition: var(--transition);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  font-size: 0.95rem;
}

.newsletter-form button:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 20px rgba(255, 242, 224, 0.35);
}

/* Footer Bottom */
.footer-bottom {
  margin-top: 44px;
  border-top: 1px solid rgba(255, 255, 255, 0.08);
  padding: 28px 24px;
  text-align: center;
  color: #D7D0C4;
  font-size: 0.95rem;
  font-weight: 500;
  letter-spacing: 0.02em;
  position: relative;
  z-index: 1;
}

.footer-bottom strong {
  color: #FFF2E0;
  font-weight: 800;
}

.footer-bottom .divider {
  margin: 0 6px;
  color: #D7D0C4;
}

.footer-bottom p {
  margin: 10px 0;
  color: #D7D0C4;
  font-size: 0.9rem;
  line-height: 1.7;
}

.footer-bottom a {
  color: #FFF2E0;
  text-decoration: none;
  font-weight: 700;
  transition: var(--transition);
  white-space: nowrap;
}

.footer-bottom a:hover {
  color: #FFFBF7;
  text-decoration: underline;
}

/* ============================================
   RESPONSIVE
   ============================================ */

/* Tablet: brand on its own row, then 2 columns */
@media (max-width: 900px) {
  .footer-inner {
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 36px;
  }

  .footer-brand,
  .footer-inner > div:first-child {
    grid-column: 1 / -1;
  }

  .footer-brand p {
    max-width: 520px;
  }
}

/* Phones: single column */
@media (max-width: 640px) {
  .site-footer {
    padding: 40px 18px 0 18px;
    margin-top: 48px;
  }

  .footer-inner {
    grid-template-columns: minmax(0, 1fr);
    gap: 32px;
  }

  .footer-brand-text h1 {
    font-size: 1.6rem;
  }

  .footer-brand-icon {
    font-size: 2.2rem;
  }

  .footer-brand p {
    max-width: none;
  }

  .footer-section h3 {
    font-size: 1.05rem;
  }

  .footer-section p,
  .footer-section a {
    font-size: 0.9rem;
  }

  /* Quick links sit in two columns instead of a long single stack */
  .quick-links {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 10px;
  }

  .quick-links a:hover {
    transform: none;
  }

  .footer-bottom {
    margin-top: 32px;
    padding: 22px 18px;
    /* extra room at the bottom so the floating chat button never covers the legal links */
    padding-bottom: calc(86px + env(safe-area-inset-bottom, 0px));
    font-size: 0.85rem;
  }
}

@media (max-width: 380px) {
  .site-footer {
    padding: 34px 14px 0 14px;
  }

  .quick-links {
    grid-template-columns: minmax(0, 1fr);
  }

  .footer-brand-text h1 {
    font-size: 1.45rem;
  }

  .socials a {
    width: 42px;
    height: 42px;
  }
}
</style>

<footer class="site-footer" role="contentinfo">
  <div class="footer-inner">
     <!-- Brand Section -->
     <div>
        <div class="footer-brand">
           <div class="footer-brand-icon">☕</div>
           <div class="footer-brand-text">
              <h1>Six Origins</h1>
              <span class="accent">Cafe</span>
           </div>
        </div>
        <p>Discover the six finest coffee origins. Quality beans sourced with care, expertly roasted, and brewed to perfection. Your cozy corner for authentic coffee experiences.</p>
     </div>

     <!-- Contact Section -->
     <div class="footer-section">
        <h3><i class="fa-solid fa-phone"></i> Contact</h3>
        <div class="contact-item">
           <i class="fa-solid fa-phone"></i>
           <span>+63 (123) 456-7890</span>
        </div>
        <div class="contact-item">
           <i class="fa-solid fa-envelope"></i>
           <span>hello@sixorigins.cafe</span>
        </div>
        <div class="contact-item">
           <i class="fa-solid fa-location-dot"></i>
           <span>Cainta, Rizal, Philippines</span>
        </div>
     </div>

     <!-- Follow Section -->
     <div class="footer-section">
        <h3><i class="fa-solid fa-share-nodes"></i> Follow Us</h3>
        <p style="color: #D7D0C4; margin: 0; font-size: 0.9rem;">Stay connected for the latest updates.</p>
        <div class="socials">
           <a href="https://www.facebook.com/profile.php?id=61575572083638" title="Visit our Facebook" aria-label="Facebook">
              <i class="fab fa-facebook-f"></i>
           </a>
           <a href="https://instagram.com" title="Follow on Instagram" aria-label="Instagram">
              <i class="fab fa-instagram"></i>
           </a>
           <a href="https://twitter.com" title="Follow on Twitter" aria-label="Twitter">
              <i class="fab fa-twitter"></i>
           </a>
           <a href="https://tiktok.com" title="Follow on TikTok" aria-label="TikTok">
              <i class="fab fa-tiktok"></i>
           </a>
        </div>
     </div>

     <!-- Quick Links Section -->
     <div class="footer-section">
        <h3><i class="fa-solid fa-link"></i> Quick Links</h3>
        <div class="quick-links">
           <a href="index.php">Home</a>
           <a href="Item.php">Shop</a>
           <a href="about.php">About Us</a>
           <a href="contact.php">Contact</a>
           <a href="orders.php">Orders</a>
        </div>
     </div>

     <!-- Newsletter Section -->
     <div class="footer-section">
        <h3><i class="fa-solid fa-envelope-open"></i> Newsletter</h3>
        <p style="color: #D7D0C4; margin: 0; font-size: 0.9rem;">Get exclusive updates and offers.</p>
        <form class="newsletter-form" onsubmit="event.preventDefault(); alert('Thank you for subscribing!'); this.reset();">
           <input type="email" placeholder="your@email.com" required aria-label="Email address">
           <button type="submit"><i class="fa-solid fa-paper-plane"></i> Subscribe</button>
        </form>
     </div>
  </div>

  <!-- Footer Bottom -->
  <div class="footer-bottom">
     <p>
        &copy; <span id="year"></span> <strong>Six Origins Cafe</strong> <span class="divider">•</span> All Rights Reserved
     </p>
     <p>
        <a href="#">Terms of Service</a> <span class="divider">•</span> 
        <a href="#">Privacy Policy</a> <span class="divider">•</span> 
        <a href="#">Cookie Policy</a>
     </p>
     <p style="margin-top: 12px; font-size: 0.85rem;">
        Crafted with <i class="fa-solid fa-heart" style="color: #FFF2E0;"></i> for coffee lovers everywhere ☕
     </p>
  </div>
</footer>

<script>
document.getElementById('year').textContent = new Date().getFullYear();
</script>