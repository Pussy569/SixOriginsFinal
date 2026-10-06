<?php
/**
 * SIX ORIGINS CAFE - ADMIN PANEL FOOTER
 * Design: Elegant Coffee Aesthetic
 */
?>

<style>
    .admin-footer {
        background: var(--dark-brown);
        color: var(--light-cream);
        padding: 40px 0;
        margin-top: 60px;
        border-top: 5px solid var(--primary-red);
    }

    .footer-content {
        max-width: 1200px;
        margin: 0 auto;
        padding: 0 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }

    .footer-left {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .footer-logo {
        font-size: 1.5rem;
        font-weight: 900;
        letter-spacing: -0.5px;
        color: var(--white);
    }

    .footer-logo span {
        color: var(--primary-red);
    }

    .copyright-text {
        font-size: 0.85rem;
        opacity: 0.7;
        font-weight: 500;
    }

    .footer-right {
        display: flex;
        gap: 25px;
        align-items: center;
    }

    .system-status {
        display: flex;
        align-items: center;
        gap: 10px;
        font-size: 0.8rem;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 1px;
        background: rgba(255, 255, 255, 0.05);
        padding: 8px 15px;
        border-radius: 50px;
    }

    .status-dot {
        height: 8px;
        width: 8px;
        background: #4CAF50;
        border-radius: 50%;
        box-shadow: 0 0 10px #4CAF50;
        animation: pulseStatus 2s infinite;
    }

    @keyframes pulseStatus {
        0% { transform: scale(1); opacity: 1; }
        50% { transform: scale(1.5); opacity: 0.5; }
        100% { transform: scale(1); opacity: 1; }
    }

    .footer-links {
        display: flex;
        gap: 15px;
    }

    .footer-links a {
        color: var(--light-cream);
        text-decoration: none;
        font-size: 1.2rem;
        transition: var(--transition);
        opacity: 0.6;
    }

    .footer-links a:hover {
        opacity: 1;
        color: var(--primary-red);
        transform: translateY(-3px);
    }

    @media (max-width: 768px) {
        .footer-content {
            flex-direction: column;
            text-align: center;
        }
        .footer-right {
            flex-direction: column;
            gap: 15px;
        }
    }
</style>

<footer class="admin-footer">
    <div class="footer-content">
        <div class="footer-left">
            <div class="footer-logo">SIX<span>ORIGINS</span> CAFE</div>
            <p class="copyright-text">
                &copy; <?php echo date('Y'); ?> Management System. All Rights Reserved.
            </p>
        </div>

        <div class="footer-right">
            <div class="system-status">
                <div class="status-dot"></div>
                System Operational
            </div>
            
            <div class="footer-links">
                <a href="#" title="Settings"><i class="fa-solid fa-gear"></i></a>
                <a href="#" title="Database"><i class="fa-solid fa-database"></i></a>
                <a href="#" title="Support"><i class="fa-solid fa-headset"></i></a>
            </div>
        </div>
    </div>
</footer>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const alerts = document.querySelectorAll('.flash-message');
        alerts.forEach(alert => {
            setTimeout(() => {
                alert.style.transition = "opacity 0.5s ease, transform 0.5s ease";
                alert.style.opacity = "0";
                alert.style.transform = "translateY(-10px)";
                setTimeout(() => alert.remove(), 500);
            }, 4000);
        });
    });
</script>