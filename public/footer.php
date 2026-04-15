<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Footer Component – Old Union</title>
    <!-- Font Awesome for icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts (optional, but matches the main site) -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400;14..32,500;14..32,600&display=swap" rel="stylesheet">
    <style>
        /* ===== FOOTER STYLES (self-contained) ===== */
        :root {
            --dark-bg: #303234;
            --primary-bg: #f5f5f5;
            --text-color: #6F7378;
            /* tertiary color is kept for completeness, not actively used */
            --tertiary-color: #b40000;
        }

        /* basic reset to avoid interference */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Inter', sans-serif;
            background: #f0f0f0; /* just for preview, not needed in production */
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
        }

        .site-footer {
            background: var(--primary-bg);
            color: #fff;
            padding: 2rem 5%;
            font-family: 'Inter', sans-serif;
        }

        .footer-inner {
            max-width: 1360px;
            margin: 0 auto;
            text-align: center;
        }

        .site-footer hr {
            border: 0;
            border-top: 1px solid #3a3a3a;
            margin: 22px auto;
            width: 64%;
        }

        .footer-social,
        .footer-nav {
            display: flex;
            justify-content: center;
            align-items: center;
            flex-wrap: wrap;
            gap: 0;
        }

        #footer-nav-primary a {
          font-size: 1rem;
        }       

        .footer-social a,
        .footer-nav a {
            color: var(--text-color);
            text-decoration: none;
            transition: color 0.2s ease;
        }

        .footer-social a:hover,
        .footer-nav a:hover {
            color: var(--dark-bg);
        }

        .footer-social {
            font-size: 1.2rem;
        }

        .footer-social a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 32px;
            margin: 0 10px;
        }

        .footer-nav {
            font-size: 0.85rem;
            line-height: 1.8;
        }

        .footer-nav a {
            margin: 0 10px;
            white-space: nowrap;
        }

        .footer-nav span,
        .footer-social .dot {
            color: #5a5a5a;
            margin: 0 4px;
            user-select: none;
        }

        .privacy-choice {
            display: inline-flex;
            align-items: center;
            gap: 4px;
        }

        .privacy-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 9px;
            font-weight: 700;
            color: #fff;
            background: #2b6edc;
            border-radius: 10px;
            padding: 1px 4px;
            line-height: 1;
            transform: translateY(-1px);
        }

        .footer-copyright {
            font-size: 0.85rem;
            color: #aaa;
            margin-top: 6px;
        }

        @media (max-width: 768px) {
            .site-footer hr {
                width: 90%;
            }

            .footer-nav a {
                margin: 4px 8px;
            }

            .footer-social a {
                margin: 0 8px;
            }
        }
    </style>
</head>
<body>
    <!-- Footer Component -->
    <footer class="site-footer">
        <div class="footer-inner">
            <div class="footer-social">
                <a href="#" aria-label="LinkedIn"><i class="fa-brands fa-linkedin-in"></i></a>
                <span class="dot">·</span>
                <a href="#" aria-label="Instagram"><i class="fa-brands fa-instagram"></i></a>
                <span class="dot">·</span>
                <a href="#" aria-label="X"><i class="fa-brands fa-twitter"></i></a>
                <span class="dot">·</span>
                <a href="#" aria-label="Facebook"><i class="fa-brands fa-facebook-f"></i></a>
                <span class="dot">·</span>
                <a href="#" aria-label="YouTube"><i class="fa-brands fa-youtube"></i></a>
            </div>
            <hr>
            <nav class="footer-nav footer-nav-primary" id="footer-nav-primary" aria-label="Footer primary links">
                <a href="#">Disclosures</a>
                <span>·</span>
                <a href="#">Investor Relations</a>
                <span>·</span>
                <a href="#">Corporate Governance</a>
                <span>·</span>
                <a href="#">Newsroom</a>
                <span>·</span>
                <a href="#">Careers</a>
            </nav>
            <hr>
            <nav class="footer-nav footer-nav-secondary" aria-label="Footer secondary links">
                <a href="#">Contact Us</a>
                <span>·</span>
                <a href="#">Global Offices</a>
                <span>·</span>
                <a href="#">Equal Employment Opportunity</a>
                <span>·</span>
                <a href="#">Cybersecurity</a>
                <span>·</span>
                <a href="#">Terms of Use</a>
                <span>·</span>
                <a href="#">Privacy &amp; Cookies</a>
                <span>·</span>
                <a href="#" class="privacy-choice">
                    Your Privacy Choices
                    <span class="privacy-icon" aria-hidden="true">✓✕</span>
                </a>
            </nav>
            <hr>
            <div class="footer-copyright">
                © <span id="current-year"></span> Old Union Group. All rights reserved.
            </div>
        </div>
    </footer>

    <!-- JavaScript to set the current year dynamically -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const yearSpan = document.getElementById('current-year');
            if (yearSpan) {
                yearSpan.textContent = new Date().getFullYear();
            }
        });
    </script>
</body>
</html>