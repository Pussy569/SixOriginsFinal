<?php
session_start();
session_unset();
session_destroy();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width,initial-scale=1" />
    <title>Goodbye — Six Origins Cafe</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css" />

    <style>
        :root {
            --primary-red: #C6453E;
            --dark-brown: #5E1F13;
            --gray-brown: #664C47;
            --light-cream: #FFF2E0;
            --white: #FFFFFF;
            --radius-lg: 24px;
            --radius-md: 12px;
            --shadow-soft: 0 20px 40px rgba(94, 31, 19, 0.08);
            --transition: all 0.4s cubic-bezier(0.165, 0.84, 0.44, 1);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Montserrat', sans-serif; }

        body {
            background: radial-gradient(circle at top right, #FFFFFF, var(--light-cream));
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            overflow: hidden; /* Prevent scroll during loader */
        }

        /* LOADING SYSTEM (unchanged) */
        #loader-overlay {
            position: fixed;
            inset: 0;
            background: var(--white);
            z-index: 9999;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            transition: opacity 0.5s ease;
        }

        .coffee-loader {
            width: 80px;
            height: 80px;
            border: 5px solid var(--light-cream);
            border-top: 5px solid var(--primary-red);
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin-bottom: 20px;
        }

        @keyframes spin { 0% { transform: rotate(0deg); } 100% { transform: rotate(360deg); } }

        .loader-text {
            color: var(--dark-brown);
            font-weight: 800;
            letter-spacing: 2px;
            text-transform: uppercase;
            font-size: 0.8rem;
        }

        /* MAIN CARD — redesigned as one warm, centered card instead of a
           split dark/light layout, so the goodbye screen reads friendly
           rather than like a security notice. */
        .logout-card {
            width: 100%;
            max-width: 520px;
            background: var(--white);
            border-radius: var(--radius-lg);
            box-shadow: var(--shadow-soft);
            border: 1px solid #F0E6D8;
            padding: 56px 48px 44px;
            text-align: center;
            opacity: 0;
            transform: translateY(20px);
            transition: all 0.8s var(--transition);
        }

        .logout-card.visible {
            opacity: 1;
            transform: translateY(0);
        }

        .icon-circle {
            width: 88px;
            height: 88px;
            background: var(--light-cream);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 34px;
            color: var(--primary-red);
            margin: 0 auto 24px;
        }

        .logout-card h2 {
            font-size: 1.9rem;
            font-weight: 900;
            line-height: 1.15;
            margin-bottom: 14px;
            color: var(--dark-brown);
        }

        .logout-card > p {
            font-size: 1rem;
            color: var(--gray-brown);
            line-height: 1.75;
            margin-bottom: 28px;
            font-weight: 500;
        }

        .security-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: rgba(76, 175, 80, 0.1);
            color: #4CAF50;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 0.72rem;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 28px;
            border: 1px solid rgba(76, 175, 80, 0.2);
        }

        /* Buttons Stack — one clear primary action, secondary de-emphasized
           and placed below it instead of competing side by side. */
        .button-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }

        .btn {
            width: 100%;
            padding: 16px 20px;
            border-radius: var(--radius-md);
            text-decoration: none;
            font-weight: 800;
            font-size: 0.95rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            transition: var(--transition);
            border: none;
            cursor: pointer;
        }

        .btn-primary {
            background: var(--primary-red);
            color: var(--white);
            box-shadow: 0 8px 20px rgba(198, 69, 62, 0.25);
        }

        .btn-primary:hover {
            transform: translateY(-3px);
            box-shadow: 0 12px 25px rgba(198, 69, 62, 0.35);
            filter: brightness(1.05);
        }

        .btn-outline {
            background: transparent;
            color: var(--gray-brown);
            border: 2px solid #F0E6D8;
        }

        .btn-outline:hover {
            background: var(--light-cream);
            border-color: var(--dark-brown);
            color: var(--dark-brown);
        }

        .footer-note {
            margin-top: 26px;
            font-size: 0.82rem;
            color: var(--gray-brown);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            opacity: 0.8;
        }

        /* Responsive */
        @media (max-width: 560px) {
            .logout-card { padding: 44px 28px 32px; }
            .logout-card h2 { font-size: 1.6rem; }
        }
    </style>
</head>
<body>

    <div id="loader-overlay">
        <div class="coffee-loader"></div>
        <div class="loader-text">Six Origins Logout...</div>
    </div>

    <main class="logout-card" id="mainCard">
        <div class="icon-circle">
            <i class="fa-solid fa-mug-hot"></i>
        </div>

        <h2>See you soon!</h2>
        <p>Thanks for spending time with Six Origins Cafe. We've safely ended your session to protect your account — come back whenever you're ready for your next cup.</p>

        <div class="security-badge">
            <i class="fa-solid fa-shield-check"></i> Session Cleared
        </div>

        <div class="button-group">
            <a href="login.php" class="btn btn-primary">
                <i class="fa-solid fa-arrow-left-to-bracket"></i>
                Sign In Again
            </a>
            <a href="index.php" class="btn btn-outline">
                <i class="fa-solid fa-house"></i>
                Back to Home
            </a>
        </div>

        <div class="footer-note">
            <i class="fa-solid fa-circle-info"></i>
            <span>Your cart and preferences are saved for your next visit.</span>
        </div>
    </main>

    <script>
        window.addEventListener('load', () => {
            const loader = document.getElementById('loader-overlay');
            const card = document.getElementById('mainCard');
            
            // Simulating a "Security Cleansing" delay
            setTimeout(() => {
                loader.style.opacity = '0';
                setTimeout(() => {
                    loader.style.display = 'none';
                    document.body.style.overflow = 'auto';
                    card.classList.add('visible');
                }, 500);
            }, 1500);
        });
    </script>

</body>
</html>