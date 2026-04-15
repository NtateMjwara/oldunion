<?php
/**
 * Footer Navigation Component (Bottom Nav)
 * 
 * This file contains the bottom navigation bar for mobile portrait views.
 * All class names are prefixed with 'ou-footer-' to ensure uniqueness and
 * prevent conflicts with existing CSS/JS in any project.
 * 
 * Dependencies:
 * - Requires a drawer element with ID 'drawer' to exist on the page for the "More" button.
 * - Requires Font Awesome 6 and DM Sans fonts (included in main layout).
 */

// Prevent direct access if needed
if (!defined('ABSPATH') && !defined('ALLOW_ACCESS')) {
    // If you want to restrict direct access, uncomment the line below:
    // exit('Direct access not allowed.');
}

/**
 * Helper: Determine if the given menu URL matches the current page.
 * Compares normalized paths (ignores trailing slashes, query strings).
 */
function is_active_nav_item($menuUrl) {
    // Get current request URI without query string
    $current = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($current === null) {
        $current = $_SERVER['REQUEST_URI'];
    }
    // Normalize: remove trailing slash (except for root)
    $current = rtrim($current, '/');
    
    // Normalize menu URL (ensure it starts from root, not relative)
    // Convert possible relative URLs like "../app/discover/" to absolute path
    if (strpos($menuUrl, '../') === 0) {
        // Resolve relative path: assuming the script is in a subfolder (e.g., /app/)
        // We'll simply remove leading '../' and prepend '/'
        $menuUrl = '/' . ltrim($menuUrl, '../');
    }
    if (strpos($menuUrl, '/') !== 0) {
        $menuUrl = '/' . $menuUrl;
    }
    $menuUrl = rtrim($menuUrl, '/');
    
    // Special case: Profile link has .php extension
    // Compare both with and without .php for flexibility
    if ($menuUrl === '/app/profile') {
        return ($current === '/app/profile.php' || $current === '/app/profile');
    }
    
    // Direct match
    return ($current === $menuUrl);
}
?>
<!-- ============================================================
     OLD UNION - FOOTER BOTTOM NAVIGATION (Mobile Portrait)
     Unique class names: ou-footer-*
     ============================================================ -->
<nav class="ou-footer-nav" role="navigation" aria-label="Bottom navigation">
    <div class="ou-footer-nav-inner">
        <?php
        // Define navigation items: href, icon, label, optional dot
        $nav_items = [
            [
                'href' => '/app',
                'icon' => 'fa-house',
                'label' => 'Home',
                'dot' => false
            ],
            [
                'href' => '/app/discover/',
                'icon' => 'fa-compass',
                'label' => 'Discover',
                'dot' => false
            ],
            [
                'href' => '/app/profile.php/',
                'icon' => 'fa-user',
                'label' => 'Profile',
                'dot' => false
            ],
            [
                'href' => '/app/wallet/',
                'icon' => 'fa-wallet',
                'label' => 'Wallet',
                'dot' => true   // shows red notification dot
            ]
        ];
        
        foreach ($nav_items as $item) :
            $active_class = is_active_nav_item($item['href']) ? ' ou-footer-nav-item--active' : '';
        ?>
        <a href="<?php echo htmlspecialchars($item['href']); ?>" class="ou-footer-nav-item<?php echo $active_class; ?>">
            <i class="fa-solid <?php echo htmlspecialchars($item['icon']); ?>"></i>
            <span><?php echo htmlspecialchars($item['label']); ?></span>
            <?php if ($item['dot']) : ?>
            <span class="ou-footer-nav-dot"></span>
            <?php endif; ?>
        </a>
        <?php endforeach; ?>
    </div>
</nav>

<style>
/* ============================================================
   FOOTER BOTTOM NAVIGATION STYLES
   Unique prefix: ou-footer-
   ============================================================ */
:root {
    --ou-footer-nav-h: 62px;
    --ou-footer-nav-bg: #ffffff;
    --ou-footer-nav-border: #e4e7ec;
    --ou-footer-nav-text-light: #98a2b3;
    --ou-footer-nav-active: #0f3b7a;
    --ou-footer-nav-dot: #c8102e;
}

/* Base styles - hidden by default, only visible on mobile portrait */
.ou-footer-nav {
    display: none;
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    height: var(--ou-footer-nav-h);
    background: var(--ou-footer-nav-bg);
    border-top: 1px solid var(--ou-footer-nav-border);
    z-index: 500;
    box-shadow: 0 -4px 16px rgba(11, 37, 69, 0.09);
    /* Safe area for home bar on iOS */
    padding-bottom: env(safe-area-inset-bottom);
    margin-top: 5em;
}

.ou-footer-nav-inner {
    display: flex;
    height: 100%;
}

.ou-footer-nav-item {
    flex: 1;
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.2rem;
    font-size: 0.64rem;
    font-weight: 600;
    color: var(--ou-footer-nav-text-light);
    text-decoration: none;
    cursor: pointer;
    border: none;
    background: transparent;
    position: relative;
    transition: color 0.15s cubic-bezier(0.4, 0, 0.2, 1);
    padding-bottom: 2px;
}

.ou-footer-nav-item i {
    font-size: 1.08rem;
}

.ou-footer-nav-item:hover {
    color: var(--ou-footer-nav-active);
}

/* Active state indicator */
.ou-footer-nav-item--active {
    color: var(--ou-footer-nav-active);
}

.ou-footer-nav-item--active::after {
    content: '';
    position: absolute;
    top: 0;
    left: 22%;
    right: 22%;
    height: 2px;
    border-radius: 0 0 3px 3px;
    background: var(--ou-footer-nav-active);
}

/* Notification dot */
.ou-footer-nav-dot {
    position: absolute;
    top: 6px;
    right: calc(50% - 16px);
    width: 6px;
    height: 6px;
    border-radius: 50%;
    background: var(--ou-footer-nav-dot);
    border: 2px solid var(--ou-footer-nav-bg);
}

/* ─── Mobile Portrait ONLY (<600px) ─── */
@media (max-width: 599px) and (orientation: portrait) {
    .ou-footer-nav {
        display: block;
    }

    /* Adjust page content to avoid overlap with footer */
    .page-wrap {
        padding-bottom: var(--ou-footer-nav-h);
    }
}

/* ─── Hide on landscape and larger screens ─── */
@media (min-width: 600px), (orientation: landscape) {
    .ou-footer-nav {
        display: none;
    }
}

/* Support for very small devices */
@media (max-width: 380px) and (orientation: portrait) {
    .ou-footer-nav-item span {
        font-size: 0.58rem;
    }
    .ou-footer-nav-item i {
        font-size: 0.95rem;
    }
    .ou-footer-nav-dot {
        top: 4px;
        right: calc(50% - 12px);
        width: 5px;
        height: 5px;
    }
}
</style>

<script>
/**
 * Footer Navigation - "More" button handler
 * Opens the mobile drawer when the More button is clicked.
 * Assumes a drawer element with ID 'drawer' exists on the page.
 */
(function() {
    'use strict';

    // Wait for DOM to be fully loaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initFooterNav);
    } else {
        initFooterNav();
    }

    function initFooterNav() {
        var moreBtn = document.getElementById('ou-footer-more-btn');
        var drawer = document.getElementById('drawer');
        var overlay = document.getElementById('overlay');
        var hamburger = document.getElementById('hamburger');

        // If drawer doesn't exist, we can't open it - exit gracefully
        if (!moreBtn || !drawer) {
            if (moreBtn && !drawer) {
                console.warn('Footer nav: Drawer element (#drawer) not found. "More" button will not work.');
            }
            return;
        }

        /**
         * Opens the mobile drawer
         */
        function openDrawer() {
            drawer.classList.add('open');
            if (overlay) {
                overlay.classList.add('vis');
                // Trigger reflow for smooth animation
                requestAnimationFrame(function() {
                    overlay.classList.add('open');
                });
            }
            document.body.style.overflow = 'hidden';
            if (hamburger) {
                hamburger.setAttribute('aria-expanded', 'true');
                var icon = hamburger.querySelector('i');
                if (icon) icon.className = 'fa-solid fa-xmark';
            }
        }

        /**
         * Closes the mobile drawer
         */
        function closeDrawer() {
            drawer.classList.remove('open');
            if (overlay) {
                overlay.classList.remove('open');
                var onEnd = function() {
                    overlay.classList.remove('vis');
                    overlay.removeEventListener('transitionend', onEnd);
                };
                overlay.addEventListener('transitionend', onEnd, { once: true });
            }
            document.body.style.overflow = '';
            if (hamburger) {
                hamburger.setAttribute('aria-expanded', 'false');
                var icon = hamburger.querySelector('i');
                if (icon) icon.className = 'fa-solid fa-bars';
            }
        }

        // Attach click event to More button
        moreBtn.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            // Open drawer if closed, otherwise close? Standard behavior: always open.
            if (!drawer.classList.contains('open')) {
                openDrawer();
            }
        });

        // Optional: Close drawer when overlay is clicked (if overlay exists)
        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }

        // Close drawer on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && drawer.classList.contains('open')) {
                closeDrawer();
            }
        });

        // Close drawer when viewport resizes above mobile breakpoint
        window.addEventListener('resize', function() {
            if (window.innerWidth > 767 && drawer.classList.contains('open')) {
                closeDrawer();
            }
        });
    }
})();
</script>