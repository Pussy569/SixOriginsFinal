<?php
include __DIR__ . '/config.php';
$user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>About — Six Origins Cafe</title>
  <!-- Lets CSS know JavaScript is available (used by the scroll-reveal animations) -->
  <script>document.documentElement.className += ' js';</script>
  <!-- Google Fonts -->
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
      --shadow: 0 8px 24px rgba(94, 31, 19, 0.08);
      --shadow-hover: 0 12px 36px rgba(94, 31, 19, 0.12);
      --max-width: 1200px;
      --base-font-size: 16px;
      --lead-font-size: 1.08rem;
    }

    * {
      box-sizing: border-box;
      margin: 0;
      padding: 0;
      font-family: 'Montserrat', system-ui, -apple-system, "Segoe UI", Roboto, Arial;
    }

    html, body {
      width: 100%;
      overflow-x: hidden;
      -webkit-text-size-adjust: 100%;
    }

    body {
      background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
      color: var(--dark-brown);
      -webkit-font-smoothing: antialiased;
      line-height: 1.65;
      font-size: var(--base-font-size);
      min-height: 100vh;
    }

    img { max-width: 100%; }

    .page {
      max-width: var(--max-width);
      margin: 0 auto;
      padding: 0 18px 60px;
    }

    @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
    @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

    /* ============ HERO ============ */
    .hero {
      position: relative;
      width: 100vw;
      left: 50%;
      margin-left: -50vw;
      margin-right: -50vw;
      margin-top: 0;
      margin-bottom: 0;
      height: 550px;
      height: clamp(380px, 72vh, 550px);
      background: linear-gradient(135deg, rgba(0, 0, 0, 0.52) 0%, rgba(0, 0, 0, 0.65) 100%),
                  url('images/aboback.png') center/cover no-repeat;
      background-attachment: scroll;
      overflow: hidden;
      display: flex;
      align-items: center;
      justify-content: center;
      animation: slideDown 0.4s ease;
    }

    /* Fixed (parallax) backgrounds are janky/unsupported on phones, so desktop only */
    @media (min-width: 1051px) and (hover: hover) {
      .hero { background-attachment: fixed; }
    }

    .hero::before {
      content: '';
      position: absolute;
      inset: 0;
      background: rgba(0, 0, 0, 0.45);
      z-index: 1;
    }

    .hero-content {
      position: relative;
      z-index: 2;
      text-align: center;
      width: 100%;
      max-width: 900px;
      padding: clamp(28px, 6vw, 60px) clamp(18px, 5vw, 50px);
      animation: fadeIn 0.8s ease 0.3s backwards;
    }

    .hero h1 {
      font-size: clamp(1.8rem, 6.5vw, 4rem);
      margin-bottom: clamp(12px, 2.5vw, 24px);
      color: #ffffff;
      font-family: 'Romelio Sans', serif;
      font-weight: 900;
      letter-spacing: -1.5px;
      line-height: 1.1;
      text-shadow: 0 12px 40px rgba(0, 0, 0, 0.7);
      overflow-wrap: break-word;
    }

    .hero p {
      color: rgba(255, 255, 255, 0.98);
      font-size: clamp(1rem, 2.4vw, 1.3rem);
      margin-bottom: clamp(18px, 3.5vw, 36px);
      font-weight: 500;
      line-height: 1.7;
      text-shadow: 0 8px 20px rgba(0, 0, 0, 0.6);
    }

    .hero-buttons {
      display: flex;
      gap: 14px;
      flex-wrap: wrap;
      justify-content: center;
    }

    .hero .btn, .hero .btn-ghost {
      padding: 16px 40px;
      border-radius: 12px;
      font-weight: 800;
      border: none;
      font-size: 1.05em;
      cursor: pointer;
      transition: all 0.3s ease;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      position: relative;
      overflow: hidden;
    }

    .hero .btn::before, .hero .btn-ghost::before {
      content: '';
      position: absolute;
      top: 0;
      left: -100%;
      width: 100%;
      height: 100%;
      background: linear-gradient(90deg, transparent, rgba(255, 255, 255, 0.2), transparent);
      transition: left 0.5s ease;
    }

    .hero .btn:hover::before, .hero .btn-ghost:hover::before { left: 100%; }

    .hero .btn {
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      color: #fff;
      box-shadow: 0 8px 24px rgba(198, 69, 62, 0.45);
    }

    .hero .btn:hover {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      transform: translateY(-3px);
      box-shadow: 0 12px 32px rgba(94, 31, 19, 0.55);
    }

    .hero .btn-ghost {
      background: rgba(255, 255, 255, 0.12);
      color: #ffffff;
      border: 2px solid rgba(255, 255, 255, 0.35);
      backdrop-filter: blur(10px);
    }

    .hero .btn-ghost:hover {
      background: rgba(255, 255, 255, 0.2);
      border-color: rgba(255, 255, 255, 0.6);
      transform: translateY(-3px);
      box-shadow: 0 12px 32px rgba(0, 0, 0, 0.25);
    }

    /* ============================================
       PROMO BANNERS (Featured Blend / New Merch Drop)
       Redesigned as image + content panels:
       - text always sits on a solid panel (never on top of the photo)
       - side-by-side on desktop, stacked on mobile (image first)
       - clear tag, headline, short copy, quick-point chips, 2 buttons
       ============================================ */
    .promo {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
      border-radius: var(--radius);
      overflow: hidden;
      box-shadow: var(--shadow);
      border: 1.5px solid #F0E6D8;
      margin: 48px 0;
      background: #fff;
    }

    .promo-media {
      position: relative;
      min-height: 380px;
      overflow: hidden;
      background: linear-gradient(135deg, #FFFAF5 0%, var(--light-cream) 100%);
    }

    .promo-media img {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      display: block;
      transition: transform 1.2s cubic-bezier(0.22, 1, 0.36, 1);
    }

    .promo-body {
      display: flex;
      flex-direction: column;
      justify-content: center;
      align-items: flex-start;
      padding: clamp(24px, 4.5vw, 56px);
      min-width: 0;
    }

    .promo--dark .promo-body {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      color: #fff;
    }

    .promo--light .promo-body {
      background: linear-gradient(135deg, #FFFBF7 0%, var(--light-cream) 100%);
      color: var(--dark-brown);
    }

    .promo--flip .promo-media { order: 2; }

    .promo-tag {
      display: inline-flex;
      align-items: center;
      gap: 8px;
      font-size: 0.78rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 1px;
      padding: 7px 14px;
      border-radius: 20px;
      margin-bottom: 18px;
    }

    .promo--dark .promo-tag {
      background: rgba(255, 255, 255, 0.12);
      border: 1px solid rgba(255, 255, 255, 0.3);
      color: #FFE7B8;
    }

    .promo--light .promo-tag {
      background: rgba(198, 69, 62, 0.08);
      border: 1px solid rgba(198, 69, 62, 0.2);
      color: var(--primary-red);
    }

    .promo-body h2 {
      font-family: 'Romelio Sans', serif;
      font-size: clamp(1.5rem, 3.6vw, 2.4rem);
      font-weight: 900;
      letter-spacing: -0.8px;
      line-height: 1.15;
      margin-bottom: 14px;
    }

    .promo--dark .promo-body h2 { color: #fff; }
    .promo--light .promo-body h2 { color: var(--dark-brown); }

    .promo-body p {
      font-size: clamp(0.95rem, 1.6vw, 1.05rem);
      font-weight: 500;
      line-height: 1.7;
      margin-bottom: 20px;
      max-width: 520px;
    }

    .promo--dark .promo-body p { color: rgba(255, 255, 255, 0.9); }
    .promo--light .promo-body p { color: var(--gray-brown); }

    .promo-points {
      list-style: none;
      display: flex;
      flex-wrap: wrap;
      gap: 8px;
      margin: 0 0 26px 0;
      padding: 0;
    }

    .promo-points li {
      display: inline-flex;
      align-items: center;
      gap: 7px;
      font-size: 0.85rem;
      font-weight: 700;
      padding: 7px 12px;
      border-radius: 999px;
    }

    .promo--dark .promo-points li {
      background: rgba(255, 255, 255, 0.1);
      color: #fff;
      border: 1px solid rgba(255, 255, 255, 0.2);
    }

    .promo--light .promo-points li {
      background: #fff;
      color: var(--dark-brown);
      border: 1.5px solid #F0E6D8;
    }

    .promo-points li i { color: var(--primary-red); }
    .promo--dark .promo-points li i { color: #FFE7B8; }

    .promo-actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
    }

    .promo-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      min-height: 48px;
      padding: 12px 26px;
      border-radius: 10px;
      font-weight: 800;
      font-size: 0.95rem;
      text-decoration: none;
      color: #fff;
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      box-shadow: 0 6px 18px rgba(198, 69, 62, 0.35);
      border: 2px solid transparent;
      transition: all 0.3s ease;
    }

    .promo-btn:hover {
      background: linear-gradient(135deg, #fff 0%, #fff 100%);
      color: var(--primary-red);
      transform: translateY(-2px);
    }

    .promo--light .promo-btn:hover {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      color: #fff;
    }

    .promo-btn-ghost {
      background: transparent;
      box-shadow: none;
    }

    .promo--dark .promo-btn-ghost { color: #fff; border-color: rgba(255, 255, 255, 0.45); }
    .promo--light .promo-btn-ghost { color: var(--primary-red); border-color: rgba(198, 69, 62, 0.4); }

    .promo--dark .promo-btn-ghost:hover { background: rgba(255, 255, 255, 0.15); color: #fff; }
    .promo--light .promo-btn-ghost:hover { background: rgba(198, 69, 62, 0.08); color: var(--primary-red); }

    .promo-btn:focus-visible { outline: 3px solid #FFE7B8; outline-offset: 2px; }

    /* Scroll reveal (only when JS is available, so content is never hidden without it) */
    .js .promo .promo-media img { transform: scale(1.12); }
    .js .promo.in-view .promo-media img { transform: scale(1); }

    .js .promo .promo-body > * {
      opacity: 0;
      transform: translateY(18px);
      transition: opacity 0.7s ease, transform 0.7s ease;
    }
    .js .promo.in-view .promo-body > * { opacity: 1; transform: translateY(0); }
    .js .promo.in-view .promo-body > *:nth-child(2) { transition-delay: 0.08s; }
    .js .promo.in-view .promo-body > *:nth-child(3) { transition-delay: 0.16s; }
    .js .promo.in-view .promo-body > *:nth-child(4) { transition-delay: 0.24s; }
    .js .promo.in-view .promo-body > *:nth-child(5) { transition-delay: 0.32s; }

    @media (hover: hover) {
      .js .promo.in-view:hover .promo-media img { transform: scale(1.05); }
    }

    /* ============ CONTENT ============ */
    .content-wrapper {
      max-width: var(--max-width);
      margin: 0 auto;
      padding: 44px 18px 60px;
    }

    .section-intro {
      max-width: 640px;
      margin: 0 0 32px 0;
    }

    .section-intro h2 {
      font-family: 'Romelio Sans', serif;
      color: var(--dark-brown);
      font-size: clamp(1.5rem, 4vw, 1.9rem);
      font-weight: 900;
      letter-spacing: -0.5px;
      margin-bottom: 10px;
    }

    .section-intro p {
      color: var(--gray-brown);
      font-size: 1.03em;
      font-weight: 500;
      line-height: 1.7;
    }

    .about-grid {
      display: grid;
      grid-template-columns: minmax(0, 1fr) minmax(0, 420px);
      gap: 32px;
      align-items: start;
      margin-bottom: 44px;
    }

    .card {
      background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
      border-radius: var(--radius);
      padding: 32px;
      box-shadow: var(--shadow);
      border: 1.5px solid #F0E6D8;
      transition: all 0.3s ease;
      animation: slideUp 0.4s ease;
      min-width: 0;
    }

    .card:hover {
      box-shadow: var(--shadow-hover);
      border-color: var(--primary-red);
      transform: translateY(-4px);
    }

    .card h2 {
      margin-bottom: 18px;
      color: var(--dark-brown);
      font-size: 1.5rem;
      font-family: 'Romelio Sans', serif;
      font-weight: 900;
      letter-spacing: -0.5px;
    }

    .card p,
    .card li,
    .team p {
      color: var(--gray-brown);
      opacity: 1 !important;
      font-size: 1.03em;
      font-weight: 500;
      line-height: 1.7;
      margin-bottom: 14px;
    }

    .card p:last-child { margin-bottom: 0; }

    .facts-img {
      width: 100%;
      height: clamp(200px, 40vw, 300px);
      object-fit: cover;
      border-radius: 10px;
      margin-bottom: 20px;
      display: block;
      box-shadow: 0 4px 12px rgba(94, 31, 19, 0.1);
    }

    .facts-title { font-size: 1.3rem; margin-bottom: 14px; }

    .facts-cta {
      display: inline-flex;
      width: 100%;
      justify-content: center;
      align-items: center;
      gap: 8px;
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      color: #fff;
      padding: 12px 20px;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 800;
      transition: all 0.3s ease;
    }

    .facts-cta:hover {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      transform: translateY(-2px);
    }

    .contact-cta {
      margin-top: 24px;
      padding: 18px;
      background: linear-gradient(135deg, #FFFAF5 0%, #FFF9F3 100%);
      border-radius: 12px;
      display: flex;
      gap: 14px;
      align-items: center;
      justify-content: space-between;
      border: 1.5px solid #F0E6D8;
      transition: all 0.3s ease;
      flex-wrap: wrap;
    }

    .contact-cta:hover {
      background: linear-gradient(135deg, #FFF9F3 0%, #FFFAF5 100%);
      border-color: var(--primary-red);
    }

    .contact-cta .text { color: var(--dark-brown); font-weight: 700; }
    .contact-cta .sub { color: var(--gray-brown); font-weight: 500; font-size: 0.95em; }

    .contact-cta a {
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      color: #fff;
      padding: 10px 16px;
      border-radius: 10px;
      text-decoration: none;
      font-weight: 800;
      font-size: 0.95em;
      margin-left: 6px;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
      white-space: nowrap;
      display: inline-block;
    }

    .contact-cta a:hover {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
    }

    ul.clear-list {
      padding-left: 1.5rem;
      margin: 0 0 14px 0;
      color: var(--dark-brown);
      font-weight: 600;
      list-style: none;
    }

    ul.clear-list li {
      margin-bottom: 10px;
      position: relative;
      padding-left: 12px;
      font-size: 0.95em;
    }

    ul.clear-list li::before {
      content: '☕';
      position: absolute;
      left: -12px;
      color: var(--primary-red);
      font-size: 0.9em;
    }

    /* ============ IMPACT ============ */
    .impact-section {
      margin: 0 0 56px 0;
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(min(100%, 300px), 1fr));
      gap: 28px;
      animation: fadeIn 0.6s ease 0.2s backwards;
    }

    .impact-card {
      background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
      border-radius: var(--radius);
      padding: 0;
      border: 1.5px solid #F0E6D8;
      box-shadow: var(--shadow);
      transition: all 0.3s ease;
      animation: slideUp 0.5s ease;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      min-width: 0;
    }

    .impact-card:nth-child(1) { animation-delay: 0.1s; }
    .impact-card:nth-child(2) { animation-delay: 0.15s; }
    .impact-card:nth-child(3) { animation-delay: 0.2s; }

    .impact-card:hover {
      box-shadow: var(--shadow-hover);
      border-color: var(--primary-red);
      transform: translateY(-6px);
    }

    .impact-card .image-wrap { position: relative; overflow: hidden; }

    .impact-card .image {
      width: 100%;
      height: 200px;
      object-fit: cover;
      display: block;
      transition: transform 0.3s ease;
    }

    .impact-card:hover .image { transform: scale(1.05); }

    .impact-card .subtitle-tag {
      position: absolute;
      bottom: 14px;
      left: 14px;
      background: rgba(255, 255, 255, 0.94);
      color: var(--primary-red);
      font-size: 0.78em;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 6px 12px;
      border-radius: 8px;
      box-shadow: 0 4px 10px rgba(94, 31, 19, 0.12);
    }

    .impact-card .content {
      padding: 28px;
      flex: 1;
      display: flex;
      flex-direction: column;
    }

    .impact-card h3 {
      color: var(--dark-brown);
      font-size: 1.3rem;
      font-weight: 900;
      margin-bottom: 10px;
      font-family: 'Romelio Sans', serif;
      letter-spacing: -0.5px;
    }

    .impact-card p {
      color: var(--gray-brown);
      font-size: 0.98em;
      font-weight: 500;
      line-height: 1.65;
      margin-bottom: 16px;
    }

    .impact-card ul { list-style: none; padding: 0; margin: 0 0 24px 0; }

    .impact-card li {
      color: var(--dark-brown);
      font-size: 0.94em;
      font-weight: 600;
      line-height: 1.6;
      margin-bottom: 9px;
      padding-left: 24px;
      position: relative;
    }

    .impact-card li:last-child { margin-bottom: 0; }

    .impact-card li::before {
      content: '✓';
      position: absolute;
      left: 0;
      color: var(--primary-red);
      font-weight: 900;
      font-size: 1.1em;
    }

    .impact-card .cta {
      margin-top: auto;
      display: inline-flex;
      align-items: center;
      gap: 8px;
      align-self: flex-start;
      padding: 10px 20px;
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      color: #fff;
      text-decoration: none;
      border-radius: 10px;
      font-weight: 800;
      font-size: 0.92em;
      transition: all 0.3s ease;
      box-shadow: 0 4px 12px rgba(198, 69, 62, 0.2);
    }

    .impact-card .cta:hover {
      background: linear-gradient(135deg, var(--dark-brown) 0%, #3D1608 100%);
      transform: translateY(-2px);
      box-shadow: 0 6px 18px rgba(94, 31, 19, 0.2);
    }

    /* ============ TEAM ============ */
    .team {
      margin-top: 12px;
      background: linear-gradient(135deg, #FFFBF7 0%, #FEFDFB 100%);
      border-radius: var(--radius);
      padding: 36px;
      box-shadow: var(--shadow);
      border: 1.5px solid #F0E6D8;
      transition: all 0.3s ease;
      animation: slideUp 0.4s ease 0.1s backwards;
    }

    .team:hover {
      box-shadow: var(--shadow-hover);
      border-color: var(--primary-red);
    }

    .team h2 {
      font-family: 'Romelio Sans', serif;
      color: var(--dark-brown);
      font-size: clamp(1.25rem, 3.5vw, 1.6rem);
      font-weight: 900;
      margin: 0;
      letter-spacing: -0.5px;
    }

    .team > p {
      margin-top: 8px;
      font-weight: 600;
      color: var(--gray-brown);
      font-size: 1.03em;
    }

    .team-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(min(100%, 220px), 1fr));
      gap: 20px;
      margin-top: 28px;
    }

    .member {
      background: linear-gradient(135deg, #FFFAF5 0%, #FFF9F3 100%);
      border-radius: 12px;
      padding: 24px 18px;
      text-align: center;
      box-shadow: 0 4px 12px rgba(94, 31, 19, 0.05);
      border: 1.5px solid #F0E6D8;
      transition: all 0.3s ease;
      display: flex;
      flex-direction: column;
      align-items: center;
      min-width: 0;
    }

    .member:hover {
      transform: translateY(-8px);
      box-shadow: var(--shadow-hover);
      border-color: var(--primary-red);
    }

    .member img {
      width: 128px;
      height: 128px;
      object-fit: cover;
      border-radius: 50%;
      margin-bottom: 16px;
      box-shadow: 0 4px 14px rgba(94, 31, 19, 0.15);
      border: 3px solid var(--white);
      transition: transform 0.3s ease;
    }

    .member:hover img { transform: scale(1.05); }

    .member h4 {
      margin: 0 0 8px 0;
      color: var(--dark-brown);
      font-size: 1.1rem;
      font-weight: 800;
      font-family: 'Romelio Sans', serif;
      overflow-wrap: anywhere;
    }

    .member .role {
      display: inline-block;
      color: var(--primary-red);
      background: var(--light-cream);
      font-size: 0.78rem;
      font-weight: 800;
      text-transform: uppercase;
      letter-spacing: 0.5px;
      padding: 5px 12px;
      border-radius: 20px;
      margin-bottom: 12px;
    }

    .member .bio {
      color: var(--gray-brown);
      font-size: 0.88em;
      font-weight: 500;
      line-height: 1.55;
      margin-bottom: 14px;
    }

    .socials {
      display: flex;
      gap: 8px;
      justify-content: center;
      margin-top: auto;
    }

    .social-btn {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      width: 36px;
      height: 36px;
      border-radius: 50%;
      background: var(--white);
      border: 1.5px solid #F0E6D8;
      color: var(--primary-red);
      text-decoration: none;
      font-size: 0.95em;
      transition: all 0.3s ease;
    }

    .social-btn:hover {
      background: linear-gradient(135deg, var(--primary-red) 0%, #B83A34 100%);
      border-color: var(--primary-red);
      color: #fff;
      transform: translateY(-2px);
      box-shadow: 0 4px 12px rgba(94, 31, 19, 0.2);
    }

    /* ============================================
       RESPONSIVE
       ============================================ */
    @media (max-width: 1050px) {
      .about-grid { grid-template-columns: minmax(0, 1fr); }
    }

    @media (max-width: 900px) {
      .impact-section { grid-template-columns: minmax(0, 1fr); }
    }

    /* Promo banners stack: photo on top, content below */
    @media (max-width: 800px) {
      .promo { grid-template-columns: minmax(0, 1fr); margin: 36px 0; }
      .promo--flip .promo-media { order: 0; }
      .promo-media { min-height: 0; aspect-ratio: 16 / 10; }
    }

    @media (max-width: 750px) {
      .content-wrapper { padding: 32px 14px 48px; }
      .page { padding: 0 14px 40px; }
      .hero .btn, .hero .btn-ghost { padding: 12px 24px; font-size: 0.95em; }
      .card { padding: 24px; }
      .card h2 { font-size: 1.25rem; }
      .contact-cta { flex-direction: column; text-align: center; }
      .contact-cta a { margin-left: 0; width: 100%; text-align: center; }
      .team { padding: 24px; }
      .impact-card .content { padding: 24px; }
      .impact-card h3 { font-size: 1.2rem; }
      .impact-card .image { height: 190px; }
    }

    @media (max-width: 640px) {
      .team-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 14px; }
      .member { padding: 18px 12px; }
      .member img { width: 92px; height: 92px; margin-bottom: 10px; }
      .member h4 { font-size: 0.95rem; margin-bottom: 6px; }
      .member .role { font-size: 0.68rem; padding: 4px 10px; margin-bottom: 8px; }
      .member .bio { font-size: 0.8em; margin-bottom: 10px; }
      .socials { gap: 6px; }
    }

    @media (max-width: 480px) {
      .content-wrapper { padding: 24px 12px 32px; }
      .page { padding: 0 12px 32px; }
      .hero-buttons { flex-direction: column; align-items: stretch; }
      .hero .btn, .hero .btn-ghost { padding: 12px 18px; font-size: 0.9em; gap: 6px; }
      .card { padding: 18px; }
      .card h2 { font-size: 1.1rem; margin-bottom: 14px; }
      .card p, .card li { font-size: 0.95em; margin-bottom: 12px; }
      .team { padding: 20px; }
      .team > p { font-size: 0.95em; margin-top: 6px; }
      .impact-section { gap: 20px; margin: 0 0 40px 0; }
      .impact-card .content { padding: 20px; }
      .impact-card h3 { font-size: 1.1rem; margin-bottom: 6px; }
      .impact-card .subtitle-tag { font-size: 0.7em; padding: 5px 10px; bottom: 10px; left: 10px; }
      .impact-card p { font-size: 0.92em; margin-bottom: 12px; }
      .impact-card li { font-size: 0.88em; margin-bottom: 8px; }
      .impact-card .image { height: 160px; }
      .impact-card .cta { padding: 10px 16px; font-size: 0.88em; }
      .contact-cta { padding: 14px; margin-top: 18px; gap: 10px; }
      .contact-cta .text { font-size: 0.9em; }
      .contact-cta .sub { font-size: 0.85em; margin-top: 4px; }
      .contact-cta a { padding: 10px 14px; font-size: 0.85em; }
      ul.clear-list { padding-left: 1.2rem; margin: 0 0 12px 0; }
      ul.clear-list li { margin-bottom: 8px; font-size: 0.9em; }
      .promo-body { padding: 22px 18px 24px; }
      .promo-tag { font-size: 0.7rem; padding: 6px 12px; margin-bottom: 14px; }
      .promo-points { margin-bottom: 20px; }
      .promo-points li { font-size: 0.8rem; padding: 6px 10px; }
      .promo-actions { width: 100%; flex-direction: column; }
      .promo-btn { width: 100%; }
    }

    @media (max-width: 340px) {
      .team-grid { grid-template-columns: minmax(0, 1fr); }
      .card { padding: 14px; }
      .impact-card .content { padding: 16px; }
    }

    /* Hover "lift" effects feel sticky on touch screens */
    @media (hover: none) {
      .card:hover, .impact-card:hover, .member:hover, .team:hover { transform: none; }
      .impact-card:hover .image, .member:hover img { transform: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      .js .promo .promo-media img,
      .js .promo.in-view .promo-media img { transform: none; transition: none; }
      .js .promo .promo-body > * { opacity: 1; transform: none; transition: none; }
    }
  </style>
</head>
<body>
<?php include 'header.php'; ?>

<!-- HERO SECTION WITH BACKGROUND IMAGE - FULL WIDTH AT TOP -->
<section class="hero" aria-labelledby="aboutTitle">
  <div class="hero-content">
    <h1 id="aboutTitle">Six Origins Making Every Day Better</h1>
    <p>Experience quality coffee and apparel crafted with passion.</p>
    <div class="hero-buttons">
      <a href="Item.php" class="btn"><i class="fa-solid fa-bag-shopping"></i> Shop Now</a>
      <a href="contact.php" class="btn-ghost"><i class="fa-solid fa-envelope"></i> Contact Us</a>
    </div>
  </div>
</section>

<div class="content-wrapper">
  <main class="page" role="main">

    <!-- Impact Section -->
    <section aria-label="Six Origins Impact">
      <div class="section-intro">
        <h2>What Makes Us</h2>
        <p>Three things guide everything we do — where our coffee comes from, who we serve, and the promise we keep with every cup.</p>
      </div>
      <div class="impact-section">
        <div class="impact-card">
          <div class="image-wrap">
            <img src="images/ge.jpg" alt="Global Coffee Origins" class="image" loading="lazy">
            <span class="subtitle-tag">Six Finest Regions</span>
          </div>
          <div class="content">
            <h3>Global Coffee Origins</h3>
            <p>We source premium beans from six distinct coffee-growing regions worldwide, each bringing unique flavor profiles and sustainable farming practices.</p>
            <ul>
              <li>Direct relationships with ethical farmers</li>
              <li>Fair trade and sustainable sourcing</li>
              <li>Small-batch specialty roasting</li>
            </ul>
            <a href="Item.php" class="cta"><i class="fa-solid fa-bag-shopping"></i> Explore Collection</a>
          </div>
        </div>

        <div class="impact-card">
          <div class="image-wrap">
            <img src="images/gee.jpg" alt="Community Impact" class="image" loading="lazy">
            <span class="subtitle-tag">Building Connections</span>
          </div>
          <div class="content">
            <h3>Community Impact</h3>
            <p>More than just a cafe, Six Origins is a gathering place where friendship are brewed and local creators are supported across Rizal.</p>
            <ul>
              <li>Support for local artisans and creators</li>
              <li>Community events and coffee sessions</li>
              <li>Fair wages and employee development</li>
            </ul>
            <a href="contact.php" class="cta"><i class="fa-solid fa-heart"></i> Get Involved</a>
          </div>
        </div>

        <div class="impact-card">
          <div class="image-wrap">
            <img src="images/geee.jpg" alt="Six Origins Promise" class="image" loading="lazy">
            <span class="subtitle-tag">Quality &amp; Simplicity</span>
          </div>
          <div class="content">
            <h3>Six Origins Promise</h3>
            <p>Every cup tells a story of passion and precision. From bean to cup, we ensure consistency and the warm hospitality that makes every visit memorable.</p>
            <ul>
              <li>Expert baristas trained to perfection</li>
              <li>Premium merchandise and apparel</li>
              <li>Customer experience as our priority</li>
            </ul>
            <a href="#" class="cta"><i class="fa-solid fa-arrow-right"></i> Learn More</a>
          </div>
        </div>
      </div>
    </section>

    <!-- PROMO 1 — Featured Blend (dark panel, photo on the left) -->
    <section class="promo promo--dark" aria-labelledby="promo1Title">
      <div class="promo-media">
        <img src="images/promo-cafe.png" alt="Six Origins featured coffee blend" loading="lazy">
      </div>
      <div class="promo-body">
        <span class="promo-tag"><i class="fa-solid fa-mug-hot"></i> Featured Blend</span>
        <h2 id="promo1Title">A Darker, Deeper Coffee Experience.</h2>
        <p>Step into the Six Origins cafe and settle in with a cup roasted for depth and warmth — every visit is a moment worth slowing down for.</p>
        <ul class="promo-points" aria-label="Highlights">
          <li><i class="fa-solid fa-earth-asia"></i> Six finest origins</li>
          <li><i class="fa-solid fa-fire"></i> Small-batch roasting</li>
          <li><i class="fa-solid fa-mug-saucer"></i> Cozy cafe vibes</li>
        </ul>
        <div class="promo-actions">
          <a href="Item.php" class="promo-btn"><i class="fa-solid fa-bag-shopping"></i> Explore Our Blends</a>
          <a href="contact.php" class="promo-btn promo-btn-ghost"><i class="fa-solid fa-location-dot"></i> Visit Us</a>
        </div>
      </div>
    </section>

    <!-- About Content Grid -->
    <div class="about-grid" role="region" aria-label="About content">
      <article class="card" aria-labelledby="whyTitle">
        <h2 id="whyTitle">Why Choose Six Origins?</h2>
        <p>We believe that great coffee is more than just a beverage—it's an experience. Every cup served at Six Origins is crafted with precision, passion, and purpose. We don't just brew coffee; we create moments of joy and connection.</p>
        <p>Our commitment to quality extends beyond the cup. From our carefully curated product selection to our commitment to fair wages for our team, we believe in doing business with integrity. We support local artisans, use sustainable practices, and invest in our community because we believe that success should benefit everyone.</p>
        <p>Whether you're a longtime coffee enthusiast or just discovering your favorite brew, Six Origins welcomes you into our family. We're here to share our passion, celebrate your moments, and make every visit memorable.</p>
        <div class="contact-cta" role="group" aria-label="Contact call to action">
          <div>
            <div class="text"><i class="fa-solid fa-question-circle"></i> Questions about our coffee?</div>
            <div class="sub" style="margin-top: 6px;">Our friendly team is here to help and share our coffee passion.</div>
          </div>
          <div>
            <a href="contact.php"><i class="fa-solid fa-arrow-right"></i> Get in Touch</a>
          </div>
        </div>
      </article>

      <aside class="card" aria-labelledby="whyImage">
        <img src="images/ey.jpg" alt="Why Choose Six Origins" id="whyImage" class="facts-img">
        <h2 class="facts-title">🎯 Quick Facts</h2>
        <ul class="clear-list">
          <li><strong>Founded:</strong> Oct 30, 2020</li>
          <li><strong>Location:</strong> Cainta, Rizal, PH</li>
          <li><strong>Origins:</strong> Six finest coffee regions</li>
          <li><strong>Team:</strong> 6+ dedicated members</li>
          <li><strong>Mission:</strong> Quality, community, simplicity</li>
        </ul>
        <div style="margin-top: 16px;">
          <a href="Item.php" class="facts-cta"><i class="fa-solid fa-bag-shopping"></i> Shop Now</a>
        </div>
      </aside>
    </div>

    <!-- PROMO 2 — New Merch Drop (light panel, photo on the right) -->
    <section class="promo promo--light promo--flip" aria-labelledby="promo2Title">
      <div class="promo-media">
        <img src="images/promo-product.png" alt="Six Origins merchandise collection" loading="lazy">
      </div>
      <div class="promo-body">
        <span class="promo-tag"><i class="fa-solid fa-bag-shopping"></i> New Merch Drop</span>
        <h2 id="promo2Title">Gear Built Around the Ritual.</h2>
        <p>From single-origin beans to our signature carryall, everything we make is built around the ritual of a really good cup of coffee.</p>
        <ul class="promo-points" aria-label="Highlights">
          <li><i class="fa-solid fa-star"></i> Just dropped</li>
          <li><i class="fa-solid fa-shirt"></i> Premium apparel</li>
          <li><i class="fa-solid fa-bag-shopping"></i> Signature carryall</li>
        </ul>
        <div class="promo-actions">
          <a href="Item.php" class="promo-btn"><i class="fa-solid fa-bag-shopping"></i> Shop The Collection</a>
          <a href="contact.php" class="promo-btn promo-btn-ghost"><i class="fa-solid fa-headset"></i> Ask Us</a>
        </div>
      </div>
    </section>

    <!-- Team Section -->
    <section class="team">
      <h2>🌟 Meet Our Team</h2>
      <p>A passionate crew that brings Six Origins to life every single day, with dedication and a smile.</p>
      <div class="team-grid" role="list" aria-label="Team members">
        <?php
          $members = [
            ['name' => 'Dag-ay, Victor', 'role' => 'Founder & Visionary', 'bio' => 'Started Six Origins with one goal: coffee worth gathering around.'],
            ['name' => 'Camiller, Antonette', 'role' => 'Operations Lead', 'bio' => 'Keeps every shift, order, and detail running smoothly.'],
            ['name' => 'Velasco, Mark Jazper', 'role' => 'Head Barista', 'bio' => 'Pulls every shot with the same care as the first.'],
            ['name' => 'Zafran, Michaella', 'role' => 'Creative Director', 'bio' => 'Shapes how Six Origins looks, feels, and sounds.'],
            ['name' => 'Biscocho, Dan Samuel', 'role' => 'Supply Manager', 'bio' => 'Sources every bean, keeping quality and freshness consistent.'],
            ['name' => 'Jacinto, Emerson', 'role' => 'Customer Care', 'bio' => 'The friendly face making sure every visit feels right.'],
          ];
          foreach ($members as $m):
        ?>
        <div class="member" role="listitem" aria-label="<?php echo htmlspecialchars($m['name']); ?>">
          <img src="images/no_profile.jpg" alt="<?php echo htmlspecialchars($m['name']); ?>" loading="lazy">
          <h4><?php echo htmlspecialchars($m['name']); ?></h4>
          <span class="role"><?php echo htmlspecialchars($m['role']); ?></span>
          <p class="bio"><?php echo htmlspecialchars($m['bio']); ?></p>
          <div class="socials" aria-hidden="false">
            <a href="#" class="social-btn" title="Facebook" aria-label="<?php echo htmlspecialchars($m['name']); ?> on Facebook">
              <i class="fab fa-facebook-f"></i>
            </a>
            <a href="#" class="social-btn" title="Twitter" aria-label="<?php echo htmlspecialchars($m['name']); ?> on Twitter">
              <i class="fab fa-twitter"></i>
            </a>
            <a href="#" class="social-btn" title="Instagram" aria-label="<?php echo htmlspecialchars($m['name']); ?> on Instagram">
              <i class="fab fa-instagram"></i>
            </a>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </section>

  </main>
</div>

<?php include 'chatbot.php'; ?>
<?php include 'footer.php'; ?>
<script src="Js/script1.js"></script>
<script>
  // ===== Promo panels — reveal each one the first time it scrolls into view =====
  (function () {
    var promos = document.querySelectorAll('.promo');
    if (!promos.length) return;

    if (!('IntersectionObserver' in window)) {
      promos.forEach(function (p) { p.classList.add('in-view'); });
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (entry.isIntersecting) {
          entry.target.classList.add('in-view');
          observer.unobserve(entry.target);
        }
      });
    }, { threshold: 0.2 });

    promos.forEach(function (p) { observer.observe(p); });
  })();
</script>
</body>
</html>