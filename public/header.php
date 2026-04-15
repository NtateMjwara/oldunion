<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>Old Union | South African Cooperative Management Company</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../app/assets/images/logo/icons.png">
    <link rel="apple-touch-icon" href="../app/assets/images/logo/icons.png">
    <meta name="msapplication-TileImage" content="../assets/images/logo/icons.png">  
    <style>
        /* ----- RESET & GLOBAL ----- */
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --primary-color: #2c3e50;
            --secondary-color: #3498db;
            --accent-color: #e74c3c;
            --text-color: #1e2a36;
            --light-text: #f8fafc;
            --tertiary-color: #b40000;
            --dark-bg: #1e293b;
            --light-bg: #6F7378;
            --shadow-sm: 0 8px 20px rgba(0, 0, 0, 0.08), 0 2px 4px rgba(0, 0, 0, 0.02);
            --shadow-md: 0 18px 36px -12px rgba(0, 0, 0, 0.15);
            --transition-smooth: all 0.25s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            --radius-md: 14px;
            --radius-sm: 10px;
        }

        body {
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', Roboto, Helvetica, sans-serif;
            background: #ffffff;
            color: var(--text-color);
            scroll-behavior: smooth;
        }

        /* Import fonts */
        @import url('https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600;700&family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600&display=swap');

        /* Header base */
        .header {
            background: rgba(255, 255, 255, 0.96);
            backdrop-filter: blur(2px);
            padding: 0.9rem 2rem;
            box-shadow: 0 2px 12px rgba(0, 0, 0, 0.03), 0 1px 0 rgba(0, 0, 0, 0.05);
            position: sticky;
            top: 0;
            z-index: 1000;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.2s;
        }

        /* Logo area */
        .logo {
            display: flex;
            flex-direction: column;
            align-items: flex-start;
            justify-content: center;
            gap: 0.2rem;
            cursor: pointer;
            transition: transform 0.2s ease;
        }
        .logo:hover { transform: scale(1.01); }

        .alumnus {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem;
            font-weight: 700;
            letter-spacing: -0.3px;
            text-transform: uppercase;
            color: var(--light-bg);
        }
        .alumnus .first { font-size: 1.9rem; display: inline-block; }
        .alumnus .second { color: var(--tertiary-color); }
        .alumnus .big { font-size: 1.9rem; }

        /* Navigation */
        .nav-menu {
            display: flex;
            list-style: none;
            gap: 2.2rem;
            align-items: center;
        }

        .nav-item {
            position: relative;
            list-style: none;
        }

        .nav-link {
            color: var(--dark-bg);
            text-decoration: none;
            font-weight: 540;
            font-size: 1rem;
            padding: 0.6rem 0;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: var(--transition-smooth);
            letter-spacing: -0.2px;
            position: relative;
        }

        .nav-link i {
            font-size: 0.75rem;
            transition: transform 0.2s ease;
            color: #5f6c7a;
        }

        .nav-link:hover { color: var(--secondary-color); }
        .nav-link::after {
            content: '';
            position: absolute;
            bottom: 0;
            left: 0;
            width: 0;
            height: 2px;
            background: var(--secondary-color);
            transition: width 0.25s ease;
            border-radius: 4px;
        }
        .nav-link:hover::after { width: 100%; }

        /* DROPDOWN (elegant, intelligent) */
        .dropdown-container {
            position: absolute;
            top: calc(100% + 8px);
            left: 0;
            min-width: 240px;
            background: #ffffff;
            border-radius: var(--radius-md);
            box-shadow: var(--shadow-md);
            opacity: 0;
            visibility: hidden;
            transform: translateY(-12px);
            transition: opacity 0.22s ease, transform 0.22s ease, visibility 0.22s;
            z-index: 1050;
            backdrop-filter: blur(0px);
            border: 1px solid rgba(0, 0, 0, 0.04);
            overflow: hidden;
        }

        .dropdown-container.show {
            opacity: 1;
            visibility: visible;
            transform: translateY(0);
        }

        /* pointer / arrow */
        .dropdown-container::before {
            content: '';
            position: absolute;
            top: -6px;
            left: 20px;
            width: 12px;
            height: 12px;
            background: white;
            transform: rotate(45deg);
            border-left: 1px solid rgba(0, 0, 0, 0.04);
            border-top: 1px solid rgba(0, 0, 0, 0.04);
            z-index: -0;
        }

        .dropdown-menu {
            list-style: none;
            padding: 0.5rem 0;
            margin: 0;
        }

        .dropdown-menu li {
            padding: 0;
            transition: background 0.18s;
        }

        .dropdown-menu li a {
            display: block;
            padding: 0.75rem 1.4rem;
            color: #1e2a3a;
            text-decoration: none;
            font-weight: 470;
            font-size: 0.9rem;
            transition: all 0.18s;
            letter-spacing: -0.2px;
        }

        .dropdown-menu li:hover {
            background: #f1f5f9;
        }
        .dropdown-menu li a:hover {
            color: var(--secondary-color);
            padding-left: 1.7rem;
        }

        /* Buttons */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        .btn {
            padding: 0.55rem 1.3rem;
            border-radius: 40px;
            font-weight: 550;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s ease;
            border: none;
            font-family: inherit;
        }
        .btn-primary {
            background: var(--secondary-color);
            color: white;
            box-shadow: 0 2px 6px rgba(52,152,219,0.2);
        }
        .btn-primary:hover {
            background: #2c7ab1;
            transform: translateY(-2px);
            box-shadow: 0 8px 18px rgba(52,152,219,0.25);
        }
        .btn-outline {
            background: transparent;
            border: 1px solid #cbd5e1;
            color: #2c3e50;
        }
        .btn-outline:hover {
            background: #f8fafc;
            border-color: var(--secondary-color);
            color: var(--secondary-color);
        }
        .menu-toggle {
            display: none;
            background: none;
            border: none;
            font-size: 1.6rem;
            color: var(--dark-bg);
            cursor: pointer;
        }

        /* Sidebar styles - refined */
        .sidebar {
            position: fixed;
            top: 0;
            left: -100%;
            width: 320px;
            height: 100vh;
            background: #ffffff;
            z-index: 1200;
            transition: left 0.3s cubic-bezier(0.2, 0.9, 0.4, 1.1);
            box-shadow: 4px 0 30px rgba(0, 0, 0, 0.12);
            padding: 1.8rem 1.5rem;
            overflow-y: auto;
        }
        .sidebar.active { left: 0; }
        .sidebar-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 2rem;
            padding-bottom: 1rem;
            border-bottom: 1px solid #eef2f6;
        }
        .sidebar-alumnus {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem;
            font-weight: 700;
            color: var(--light-bg);
        }
        .sidebar-alumnus .second { color: var(--tertiary-color); }
        .sidebar-close {
            background: none;
            border: none;
            font-size: 1.5rem;
            cursor: pointer;
            color: #5b6e8c;
        }
        .sidebar-nav {
            list-style: none;
            margin-bottom: 2rem;
        }
        .sidebar-item {
            margin-bottom: 0.25rem;
            border-radius: var(--radius-sm);
        }
        .sidebar-link {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 0.85rem 1rem;
            color: var(--dark-bg);
            text-decoration: none;
            font-weight: 500;
            border-radius: 12px;
            transition: background 0.2s;
            cursor: pointer;
        }
        .sidebar-link i.chevron-icon {
            font-size: 0.7rem;
            transition: transform 0.2s;
            color: #7e8b9c;
        }
        .sidebar-link:hover {
            background: #f1f5f9;
            color: var(--secondary-color);
        }
        .sidebar-dropdown-container {
            display: none;
            background: #fefefe;
            border-radius: 14px;
            margin: 0.25rem 0 0.5rem 0.8rem;
            padding: 0.3rem 0;
            animation: fadeSlide 0.2s ease;
        }
        .sidebar-dropdown-container.show {
            display: block;
        }
        .sidebar-dropdown-menu {
            list-style: none;
        }
        .sidebar-dropdown-menu li a {
            display: block;
            padding: 0.7rem 1rem 0.7rem 2rem;
            color: #334155;
            text-decoration: none;
            font-size: 0.85rem;
            border-radius: 10px;
            transition: all 0.15s;
        }
        .sidebar-dropdown-menu li a:hover {
            background: #eef2ff;
            color: var(--secondary-color);
            padding-left: 2.2rem;
        }
        .sidebar-actions {
            margin-top: 1.5rem;
            display: flex;
            flex-direction: column;
            gap: 0.8rem;
        }
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.4);
            backdrop-filter: blur(3px);
            z-index: 1100;
            opacity: 0;
            visibility: hidden;
            transition: 0.2s;
        }
        .overlay.active {
            opacity: 1;
            visibility: visible;
        }

        /* mobile / tablet */
        @media (max-width: 768px) {
            .header { padding: 0.8rem 1.2rem; }
            .nav-menu, .header-actions .btn:not(.menu-toggle) { display: none; }
            .menu-toggle { display: block; }
            .dropdown-container { left: 0; min-width: 200px; }
        }
        @media (min-width: 769px) {
            .sidebar, .overlay { display: none; }
        }

        /* animations */
        @keyframes fadeSlide {
            from { opacity: 0; transform: translateY(-6px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* hide scrollbar for cleaner look (optional) */
        ::-webkit-scrollbar { width: 4px; }
        ::-webkit-scrollbar-track { background: #f1f1f1; }
        ::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 6px; }

        /* intelligent hover delay fix */
        .nav-item.dropdown-open .nav-link i {
            transform: rotate(180deg);
        }
        .sidebar-link .chevron-icon.rotated {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>

<header class="header">
    <div class="logo" id="logo">
        <div class="alumnus">
            <span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span>
        </div>
    </div>

    <nav>
        <ul class="nav-menu" id="navMenu">
            <!-- How it Works (no dropdown) -->
            <li class="nav-item">
                <a href="/how-it-works.php" class="nav-link">How it Works</a>
            </li>
            <!-- Our Schools dropdown --
            <li class="nav-item has-dropdown" data-target="how">
                <a href="#" class="nav-link">Our Schools <i class="fas fa-chevron-down"></i></a>
                <div class="dropdown-container"></div>
            </li>
            <!-- Our Portfolios dropdown -->
            <li class="nav-item has-dropdown" data-target="internships">
                <a href="#" class="nav-link">Our Portfolio <i class="fas fa-chevron-down"></i></a>
                <div class="dropdown-container"></div>
            </li>
            <!-- About Us dropdown -->
            <li class="nav-item has-dropdown" data-target="story">
                <a href="#" class="nav-link">About Us <i class="fas fa-chevron-down"></i></a>
                <div class="dropdown-container"></div>
            </li>
        </ul>
    </nav>

    <div class="header-actions">
        <button class="btn btn-outline" id="loginBtn">Login</button>
        <button class="btn btn-primary" id="registerBtn">Register</button>
        <button class="menu-toggle" id="menuToggle"><i class="fas fa-bars"></i></button>
    </div>
</header>

<!-- Sidebar (mobile) -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-alumnus">
            <span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span>
        </div>
        <button class="sidebar-close" id="sidebarClose"><i class="fas fa-times"></i></button>
    </div>
    <ul class="sidebar-nav" id="sidebarNav">
        <li class="sidebar-item">
            <a href="/how-it-works.php" class="sidebar-link">How it Works</a>
        </li>
        <!--<li class="sidebar-item has-dropdown" data-target="how">
            <a href="#" class="sidebar-link">Our Schools <i class="fas fa-chevron-down chevron-icon"></i></a>
            <div class="sidebar-dropdown-container"></div>
        </li>-->
        <li class="sidebar-item has-dropdown" data-target="internships">
            <a href="#" class="sidebar-link">Our Portfolio <i class="fas fa-chevron-down chevron-icon"></i></a>
            <div class="sidebar-dropdown-container"></div>
        </li>
        <li class="sidebar-item has-dropdown" data-target="story">
            <a href="#" class="sidebar-link">About Us <i class="fas fa-chevron-down chevron-icon"></i></a>
            <div class="sidebar-dropdown-container"></div>
        </li>
    </ul>
    <div class="sidebar-actions">
        <button class="btn btn-outline sidebar-btn" id="sidebarLoginBtn">Login</button>
        <button class="btn btn-primary sidebar-btn" id="sidebarRegisterBtn">Register</button>
    </div>
</aside>
<div class="overlay" id="overlay"></div>

<script>
    // ======================= INTELLIGENT DROPDOWN DATA =======================
    const menuData = {
        how: {
            items: [
                { text: 'View Schools', link: '../browse' },
                { text: 'Submit a Business', link: '../listingcenter.php' }
            ]
        },
        internships: {
            items: [
                { text: 'View Our Portfolio', link: '../browse' },
                { text: 'Submit an Asset', link: '../listingcenter.php' }
            ]
        },
        story: {
            items: [
                { text: 'Help Center', link: '../resources.php' },
                { text: 'Contact Us', link: '../contact.php' }
            ]
        }
    };

    // ----- helper: build dropdown HTML inside containers -----
    function buildDropdowns() {
        // desktop nav dropdowns
        const navDropdownItems = document.querySelectorAll('.nav-item.has-dropdown');
        navDropdownItems.forEach(item => {
            const target = item.dataset.target;
            const container = item.querySelector('.dropdown-container');
            if (container && menuData[target]) {
                const ul = document.createElement('ul');
                ul.className = 'dropdown-menu';
                menuData[target].items.forEach(linkItem => {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = linkItem.link;
                    a.textContent = linkItem.text;
                    li.appendChild(a);
                    ul.appendChild(li);
                });
                container.innerHTML = '';
                container.appendChild(ul);
            }
        });

        // sidebar dropdowns
        const sidebarDropdowns = document.querySelectorAll('.sidebar-item.has-dropdown');
        sidebarDropdowns.forEach(item => {
            const target = item.dataset.target;
            const container = item.querySelector('.sidebar-dropdown-container');
            if (container && menuData[target]) {
                const ul = document.createElement('ul');
                ul.className = 'sidebar-dropdown-menu';
                menuData[target].items.forEach(linkItem => {
                    const li = document.createElement('li');
                    const a = document.createElement('a');
                    a.href = linkItem.link;
                    a.textContent = linkItem.text;
                    li.appendChild(a);
                    ul.appendChild(li);
                });
                container.innerHTML = '';
                container.appendChild(ul);
            }
        });
    }

    // ---------- DESKTOP HOVER INTELLIGENCE (with delay + boundary) ----------
    let hoverTimeout = null;
    let activeHoverItem = null;

    function showDropdown(dropdownContainer, parentItem) {
        if (!dropdownContainer) return;
        // hide any other visible dropdowns first (clean)
        document.querySelectorAll('.dropdown-container.show').forEach(dd => {
            if (dd !== dropdownContainer) dd.classList.remove('show');
        });
        dropdownContainer.classList.add('show');
        if (parentItem) parentItem.classList.add('dropdown-open');
    }

    function hideDropdown(dropdownContainer, parentItem) {
        if (dropdownContainer) dropdownContainer.classList.remove('show');
        if (parentItem) parentItem.classList.remove('dropdown-open');
    }

    function attachHoverIntelligence() {
        const isTouch = ('ontouchstart' in window) || (navigator.maxTouchPoints > 0);
        if (window.innerWidth <= 768 || isTouch) return; // mobile uses click toggling

        const navItems = document.querySelectorAll('.nav-item.has-dropdown');
        navItems.forEach(item => {
            const dropdown = item.querySelector('.dropdown-container');
            if (!dropdown) return;

            const enterHandler = () => {
                if (hoverTimeout) clearTimeout(hoverTimeout);
                hoverTimeout = setTimeout(() => {
                    if (activeHoverItem && activeHoverItem !== item) {
                        const oldDropdown = activeHoverItem.querySelector('.dropdown-container');
                        if (oldDropdown) hideDropdown(oldDropdown, activeHoverItem);
                    }
                    showDropdown(dropdown, item);
                    activeHoverItem = item;
                }, 60);
            };
            const leaveHandler = () => {
                if (hoverTimeout) clearTimeout(hoverTimeout);
                hoverTimeout = setTimeout(() => {
                    if (activeHoverItem === item) {
                        hideDropdown(dropdown, item);
                        activeHoverItem = null;
                    }
                }, 100);
            };
            item.addEventListener('mouseenter', enterHandler);
            item.addEventListener('mouseleave', leaveHandler);
            // store to avoid duplicate
            item._cleanupHover = () => {
                item.removeEventListener('mouseenter', enterHandler);
                item.removeEventListener('mouseleave', leaveHandler);
            };
        });
    }

    // ----- CLICK HANDLING for mobile / tablet (fallback + sidebar logic)-----
    function initClickDropdowns() {
        const isMobile = window.innerWidth <= 768;
        const navItems = document.querySelectorAll('.nav-item.has-dropdown');
        
        navItems.forEach(item => {
            const link = item.querySelector('.nav-link');
            const dropdown = item.querySelector('.dropdown-container');
            if (!dropdown) return;
            
            // click toggling for mobile or if touch device
            const clickHandler = (e) => {
                if (window.innerWidth > 768 && !('ontouchstart' in window)) {
                    // on desktop with hover we might still allow click but not interfere
                    // but we prevent default link navigation only if it's the dropdown trigger
                    e.preventDefault();
                    return;
                }
                e.preventDefault();
                e.stopPropagation();
                const isOpen = dropdown.classList.contains('show');
                // close all other dropdowns first
                document.querySelectorAll('.dropdown-container.show').forEach(dd => {
                    if (dd !== dropdown) dd.classList.remove('show');
                });
                document.querySelectorAll('.nav-item.dropdown-open').forEach(nav => {
                    if (nav !== item) nav.classList.remove('dropdown-open');
                });
                if (!isOpen) {
                    dropdown.classList.add('show');
                    item.classList.add('dropdown-open');
                } else {
                    dropdown.classList.remove('show');
                    item.classList.remove('dropdown-open');
                }
            };
            link.addEventListener('click', clickHandler);
            item._clickCleanup = clickHandler;
        });
    }

    // Sidebar dropdown toggling (always click based, smooth)
    function initSidebarDropdowns() {
        const sidebarItems = document.querySelectorAll('.sidebar-item.has-dropdown');
        sidebarItems.forEach(item => {
            const link = item.querySelector('.sidebar-link');
            const dropdownContainer = item.querySelector('.sidebar-dropdown-container');
            const chevron = link.querySelector('.chevron-icon');
            if (!dropdownContainer) return;
            
            const toggleDropdown = (e) => {
                e.preventDefault();
                e.stopPropagation();
                const isVisible = dropdownContainer.classList.contains('show');
                // close others in sidebar
                document.querySelectorAll('.sidebar-item .sidebar-dropdown-container.show').forEach(cont => {
                    if (cont !== dropdownContainer) cont.classList.remove('show');
                });
                document.querySelectorAll('.sidebar-item .chevron-icon.rotated').forEach(icon => {
                    if (icon !== chevron) icon.classList.remove('rotated');
                });
                if (!isVisible) {
                    dropdownContainer.classList.add('show');
                    if (chevron) chevron.classList.add('rotated');
                } else {
                    dropdownContainer.classList.remove('show');
                    if (chevron) chevron.classList.remove('rotated');
                }
            };
            link.addEventListener('click', toggleDropdown);
        });
    }

    // Global click outside to close all dropdowns (desktop + mobile)
    document.addEventListener('click', function(e) {
        // close all nav dropdowns if click outside
        const isInsideNav = e.target.closest('.nav-item.has-dropdown');
        if (!isInsideNav) {
            document.querySelectorAll('.dropdown-container.show').forEach(dd => {
                dd.classList.remove('show');
            });
            document.querySelectorAll('.nav-item.dropdown-open').forEach(item => {
                item.classList.remove('dropdown-open');
            });
            if (activeHoverItem) {
                const drop = activeHoverItem.querySelector('.dropdown-container');
                if (drop) hideDropdown(drop, activeHoverItem);
                activeHoverItem = null;
            }
        }
        // close sidebar dropdowns when clicking outside sidebar
        const isInsideSidebar = e.target.closest('.sidebar');
        if (!isInsideSidebar && sidebar.classList.contains('active')) {
            // do not close sidebar itself, but close dropdowns inside sidebar
            document.querySelectorAll('.sidebar-dropdown-container.show').forEach(cont => {
                cont.classList.remove('show');
            });
            document.querySelectorAll('.chevron-icon.rotated').forEach(icon => {
                icon.classList.remove('rotated');
            });
        } else if (!isInsideSidebar && !sidebar.classList.contains('active')) {
            document.querySelectorAll('.sidebar-dropdown-container.show').forEach(cont => {
                cont.classList.remove('show');
            });
            document.querySelectorAll('.chevron-icon.rotated').forEach(icon => {
                icon.classList.remove('rotated');
            });
        }
    });

    // sidebar open/close logic
    const menuToggle = document.getElementById('menuToggle');
    const sidebar = document.getElementById('sidebar');
    const sidebarClose = document.getElementById('sidebarClose');
    const overlay = document.getElementById('overlay');

    function openSidebar() {
        sidebar.classList.add('active');
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
    }
    function closeSidebar() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        // close all sidebar dropdowns
        document.querySelectorAll('.sidebar-dropdown-container.show').forEach(cont => {
            cont.classList.remove('show');
        });
        document.querySelectorAll('.chevron-icon.rotated').forEach(icon => {
            icon.classList.remove('rotated');
        });
    }
    menuToggle.addEventListener('click', openSidebar);
    sidebarClose.addEventListener('click', closeSidebar);
    overlay.addEventListener('click', closeSidebar);

    // Login / Register actions
    const loginBtn = document.getElementById('loginBtn');
    const registerBtn = document.getElementById('registerBtn');
    const sidebarLogin = document.getElementById('sidebarLoginBtn');
    const sidebarRegister = document.getElementById('sidebarRegisterBtn');
    function openAuth(link) {
        window.open(link, '_blank');
    }
    loginBtn?.addEventListener('click', () => openAuth('../app/auth/login.php'));
    registerBtn?.addEventListener('click', () => openAuth('../app/auth/register.php'));
    sidebarLogin?.addEventListener('click', () => openAuth('../app/auth/login.php'));
    sidebarRegister?.addEventListener('click', () => openAuth('../app/auth/register.php'));
    
    // Logo home redirect
    document.getElementById('logo')?.addEventListener('click', () => { window.location.href = '/'; });

    // Window resize: re-attach behavior to avoid conflicts
    let resizeTimer;
    window.addEventListener('resize', () => {
        if (resizeTimer) clearTimeout(resizeTimer);
        resizeTimer = setTimeout(() => {
            // close all dropdowns on resize for safety
            document.querySelectorAll('.dropdown-container.show').forEach(dd => dd.classList.remove('show'));
            document.querySelectorAll('.nav-item.dropdown-open').forEach(nav => nav.classList.remove('dropdown-open'));
            // reinitialize hover / click dynamically
            if (window.innerWidth > 768) {
                attachHoverIntelligence();
            }
            // ensure mobile click handlers exist
            initClickDropdowns();
        }, 100);
    });

    // Build all dropdown menus and attach behaviors
    buildDropdowns();
    attachHoverIntelligence();
    initClickDropdowns();
    initSidebarDropdowns();

    // additional elegance: close sidebar dropdowns when clicking sidebar links except toggles
    document.querySelectorAll('.sidebar-dropdown-menu a').forEach(link => {
        link.addEventListener('click', () => {
            // optional: keep sidebar open but close dropdown menus for cleaner UX
            setTimeout(() => {
                document.querySelectorAll('.sidebar-dropdown-container.show').forEach(cont => {
                    cont.classList.remove('show');
                });
                document.querySelectorAll('.chevron-icon.rotated').forEach(icon => {
                    icon.classList.remove('rotated');
                });
            }, 80);
        });
    });

    // prevent default on # links inside nav-item (only for those that have dropdown)
    document.querySelectorAll('.nav-item.has-dropdown .nav-link').forEach(link => {
        link.addEventListener('click', (e) => {
            if (window.innerWidth <= 768 || ('ontouchstart' in window)) {
                // handled in initClickDropdowns, but avoid double
                return;
            }
            // on desktop with hover, prevent default so it doesn't navigate to #
            e.preventDefault();
        });
    });
</script>
</body>
</html>