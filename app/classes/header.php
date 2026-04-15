<?php
// header.php - extracted header and sidebar with its CSS
// This file expects $userInitial to be defined in the parent script.
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Old Union | Dashboard</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/logo/icons.png">
    <link rel="apple-touch-icon" href="../assets/images/logo/icons.png">
    <meta name="msapplication-TileImage" content="../assets/images/logo/icons.png">      
    <style>
        /* === GLOBAL VARIABLES (used by header & sidebar) === */
        :root {
            --primary: #0b2545;
            --light-bg: #f8f9fb;
            --border: #e4e7ec;
            --text-dark: #101828;
            --text-muted: #667085;
            --light: #f8f9fa;
            --secondary: #6c757d;
            --dark: #1D1D1F;
            --header-height: 70px;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: var(--light-bg);
            color: var(--text-dark);
            min-height: 100vh;
            padding-top: var(--header-height);
            display: flex;
        }

        /* === FIXED HEADER === */
        .main-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            padding: 10px 20px;
            background-color: #fff;
            color: #1D1D1F;
            z-index: 1000;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
            height: var(--header-height);
            display: flex;
            align-items: center;
        }

        .header-container {
            display: flex;
            justify-content: space-between;
            align-items: center;
            width: 100%;
            max-width: 1400px;
            margin: 0 auto;
        }

        .logo-container {
            display: flex;
            align-items: center;
        }

        .logo {
            font-family: 'Playfair Display', serif;
            font-weight: 700;
            font-size: 22px;
            color: #333;
            text-decoration: none;
            text-transform: uppercase;
            display: inline-block;
        }

        .logo span { display: inline-block; }
        .logo .second { color: #c8102e; font-family: 'Playfair Display', serif; }
        .logo .first, .logo .big { font-size: 1.8rem; line-height: 0.8; vertical-align: baseline; font-family: 'Playfair Display', serif; }

        /* Header actions */
        .header-actions {
            display: flex;
            align-items: center;
            gap: 20px;
            position: relative;
        }

        .notification-badge {
            position: relative;
            cursor: pointer;
        }

        .notification-badge i { color: #666; font-size: 1.2rem; transition: color 0.3s; }
        .notification-badge:hover i { color: #c53030; }

        .notification-count {
            position: absolute; top: -5px; right: -5px;
            background-color: #c53030; color: white; border-radius: 50%;
            width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: bold;
        }

        .user-avatar {
            width: 42px; height: 42px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: bold; color: white; font-size: 18px; cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }

        .user-avatar:hover { transform: scale(1.05); box-shadow: 0 0 10px rgba(0,0,0,0.2); }

        .dropdown-content {
            display: none; position: absolute; top: 55px; right: 0; background-color: #FFFFFF;
            min-width: 220px; box-shadow: 0px 8px 16px rgba(0,0,0,0.15); z-index: 1001;
            border-radius: 8px; overflow: hidden; animation: fadeIn 0.3s ease;
        }

        .dropdown-content.show { display: block; }
        .dropdown-content a {
            color: #333; padding: 12px 16px; text-decoration: none; display: flex; align-items: center;
            transition: background-color 0.2s; font-size: 15px; border-bottom: 1px solid #f0f0f0;
        }
        .dropdown-content a:last-child { border-bottom: none; }
        .dropdown-content a i { width: 25px; margin-right: 10px; color: #6c757d; }
        .dropdown-content a:hover { background-color: #f8f9fa; color: #c53030; }
        .dropdown-content a:hover i { color: #c53030; }

        /* === SIDEBAR === */
        .sidebar {
            width: 250px; background: white; border-right: 1px solid var(--border);
            padding: 20px 25px; height: calc(100vh - var(--header-height)); position: sticky;
            top: var(--header-height); overflow-y: auto;
        }

        .sidebar a {
            display: block; text-decoration: none; color: var(--text-muted);
            margin-bottom: 18px; font-size: 14px; transition: color 0.2s;
        }
        .sidebar a:hover { color: var(--primary); }
        .sidebar a i { margin-right: 8px; }

        /* Responsive adjustments for header and sidebar */
        @media (min-width: 769px) { .dropdown-content, .dropdown-content.show { display: none !important; } }
        @media (max-width: 768px) {
            .sidebar { display: none; }
        }
        @media (max-width: 480px) {
            .logo { font-size: 18px; }
            .user-avatar { width: 38px; height: 38px; font-size: 16px; }
            .notification-badge i { font-size: 1.1rem; }
        }
        @media (max-width: 768px) and (orientation: landscape) and (max-height: 500px) {
            :root { --header-height: 60px; }
        }
        ::-webkit-scrollbar { display: none; }
        * { scrollbar-width: none; -ms-overflow-style: none; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <header class="main-header">
        <div class="header-container">
            <div class="logo-container">
                <a href="#" class="logo"><span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span></a>
            </div>
            <div class="header-actions">
                <!--<div class="notification-badge"><a href="#" style="color: inherit;"><i class="fas fa-bell"></i><span class="notification-count">3</span></a></div>-->
                <div class="user-avatar" id="avatarDropdown"><?= $userInitial ?></div>
                <div class="dropdown-content" id="dropdownMenu">
                    <a href="../user">Dashboard</a>
                    <a href="../school/">Manage Schools</a>
                    <a href="../user/profile.php">Profile</a>
                    <a href="../portfolio">My Portfolio</a>
                    <a href="../wallet/">Wallet</a>
                    <a href="../logout.php">Logout</a>
                </div>        
            </div>
        </div>    
    </header>

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <a href="../user"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="../school"><i class="fa-solid fa-school"></i> Manage Schools</a>
        <a href="../user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="../portfolio"><i class="fa-solid fa-folder"></i>My Portfolio</a>        
        <a href="../wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="../auth/logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </aside>

    <script>
        // Dropdown toggle script (should be placed after the dropdown elements)
        document.addEventListener('DOMContentLoaded', function() {
            const avatar = document.getElementById('avatarDropdown');
            const dropdown = document.getElementById('dropdownMenu');
            if (avatar && dropdown) {
                avatar.addEventListener('click', function(e) {
                    e.stopPropagation();
                    dropdown.classList.toggle('show');
                });
                document.addEventListener('click', function(e) {
                    if (!avatar.contains(e.target) && !dropdown.contains(e.target)) {
                        dropdown.classList.remove('show');
                    }
                });
                dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    </script>