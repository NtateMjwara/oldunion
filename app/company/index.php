<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$userId = $_SESSION['user_id'];
$pdo = Database::getInstance();

// Fetch user email for avatar initial
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$email = $user['email'] ?? '';
$userInitial = $email ? strtoupper(substr($email, 0, 1)) : 'U';

$uuid = $_GET['uuid'] ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Old Union | My Companies</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- External dropdown CSS -->
    <link rel="stylesheet" href="../assets/css/dropdown.css">
    <style>
        /* ----------------------------------------------
           GLOBAL & RESET
        ------------------------------------------------ */
        :root {
            --navy: #0b2545;
            --navy-mid: #0f3b7a;
            --navy-light: #1a56b0;
            --amber: #f59e0b;
            --amber-dark: #d97706;
            --amber-light: #fef3c7;
            --green: #0b6b4d;
            --green-bg: #e6f7ec;
            --green-bdr: #a7f3d0;
            --surface: #ffffff;
            --surface-2: #f8f9fb;
            --border: #e4e7ec;
            --text: #101828;
            --text-muted: #667085;
            --text-light: #98a2b3;
            --error: #b91c1c;
            --error-bg: #fef2f2;
            --error-bdr: #fecaca;
            --radius: 14px;
            --radius-sm: 8px;
            --shadow: 0 4px 16px rgba(11,37,69,.07), 0 1px 3px rgba(11,37,69,.05);
            --shadow-card: 0 8px 28px rgba(11,37,69,.09), 0 1px 4px rgba(11,37,69,.06);
            --header-h: 64px;
            --sidebar-w: 240px;
            --transition: .2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface-2);
            color: var(--text);
            min-height: 100vh;
        }

        /* ----------------------------------------------
           HEADER & TOP BAR
        ------------------------------------------------ */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-h);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 1.75rem;
            justify-content: space-between;
            z-index: 100;
            gap: 1rem;
        }

        .logo-container {
            display: flex;
            align-items: center;
        }

        .logo {
            font-family: 'Playfair Display', serif;
            font-weight: 600;
            font-size: 18px;
            color: #333;
            text-decoration: none;
            text-transform: uppercase;
            display: inline-block;
        }
        .logo span { display: inline-block; }
        .logo .second { color: #c8102e; font-family: 'Playfair Display', serif; }
        .logo .first, .logo .big { font-size: 1.4rem; line-height: 0.8; vertical-align: baseline; font-family: 'Playfair Display', serif; }

        .header-nav {
            display: flex;
            align-items: center;
            gap: .15rem;
            flex: 1;
            justify-content: center;
        }
        .header-nav a {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .85rem;
            border-radius: 6px;
            font-size: .85rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--transition);
            white-space: nowrap;
        }
        .header-nav a:hover {
            background: var(--surface-2);
            color: var(--text);
        }
        .header-nav a.active {
            background: #eff4ff;
            color: var(--navy-mid);
        }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6a11cb, #2575fc);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: .9rem;
            flex-shrink: 0;
            cursor: pointer;
        }

        /* ----------------------------------------------
           SIDEBAR
        ------------------------------------------------ */
        .page-wrapper {
            padding-top: var(--header-h);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            position: sticky;
            top: var(--header-h);
            height: calc(100vh - var(--header-h));
            overflow-y: auto;
            padding: 1.5rem 1rem;
            flex-shrink: 0;
        }
        .sidebar-section-label {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--text-light);
            padding: 0 .5rem;
            margin-bottom: .5rem;
            margin-top: 1.25rem;
        }
        .sidebar-section-label:first-child { margin-top: 0; }
        .sidebar a {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .55rem .75rem;
            border-radius: var(--radius-sm);
            font-size: .86rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--transition);
            margin-bottom: .1rem;
        }
        .sidebar a:hover {
            background: var(--surface-2);
            color: var(--text);
        }
        .sidebar a.active {
            background: #eff4ff;
            color: var(--navy-mid);
            font-weight: 600;
        }
        .sidebar a i {
            width: 16px;
            text-align: center;
            font-size: .85rem;
        }

        /* ----------------------------------------------
           MAIN CONTENT AREA
        ------------------------------------------------ */
        .main-content-area {
            flex: 1;
            padding: 2rem 2.5rem;
            min-width: 0;
        }

        /* Page header */
        .page-header {
            margin-bottom: 1.75rem;
        }
        .page-header h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.75rem;
            color: var(--navy);
            margin-bottom: .25rem;
        }
        .page-header .subtitle {
            font-size: .9rem;
            color: var(--text-muted);
        }

        /* Navigation Tabs */
        .nav-tabs {
            display: flex;
            background: var(--surface-2);
            border-radius: var(--radius);
            padding: 5px;
            margin-bottom: 1.5rem;
            border: 1px solid var(--border);
        }
        .nav-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            background: transparent;
            border: none;
            border-radius: var(--radius-sm);
            font-weight: 600;
            color: var(--text-muted);
            cursor: pointer;
            transition: var(--transition);
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: .85rem;
        }
        .nav-tab.active {
            background: white;
            color: var(--navy-mid);
            box-shadow: var(--shadow);
        }
        .nav-tab:hover:not(.active) {
            background: rgba(255,255,255,0.5);
            color: var(--navy);
        }

        /* Add Company Button */
        .add-company-wrapper {
            margin-bottom: 1.5rem;
            display: flex;
            justify-content: flex-end;
        }
        .btn-primary {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            background: var(--navy-mid);
            color: white;
            padding: 0.75rem 1.5rem;
            border-radius: 99px;
            text-decoration: none;
            font-weight: 500;
            font-size: 0.9rem;
            border: none;
            cursor: pointer;
            transition: background 0.2s, transform 0.1s;
            box-shadow: 0 4px 6px -1px rgba(15,59,122,0.2);
        }
        .btn-primary:hover {
            background: var(--navy);
            transform: translateY(-1px);
        }

        /* Companies Grid */
        .companies-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.75rem;
            margin-top: 0.5rem;
        }

        .company-listing-card {
            background: #ffffff;
            border-radius: 1.25rem;
            overflow: hidden;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.02);
            transition: all 0.25s ease;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            border: 1px solid var(--border);
            height: 100%;
        }
        .company-listing-card:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 30px -10px rgba(11,37,69,0.15);
            border-color: var(--navy-light);
        }

        .company-listing-banner {
            height: 130px;
            background-color: #d9e2ef;
            background-size: cover;
            background-position: center;
            position: relative;
        }

        .company-listing-logo-wrapper {
            position: absolute;
            bottom: -32px;
            left: 20px;
            width: 70px;
            height: 70px;
            border-radius: 50%;
            background: white;
            border: 3px solid white;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            overflow: hidden;
        }
        .company-listing-logo {
            width: 100%;
            height: 100%;
            object-fit: cover;
            display: block;
        }

        .company-listing-info {
            padding: 3rem 1.5rem 1.5rem 1.5rem;
            flex: 1;
        }
        .company-listing-name {
            font-size: 1.35rem;
            font-weight: 600;
            margin-bottom: 0.75rem;
            color: var(--navy);
            line-height: 1.4;
        }

        .status-with-icon {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 0.35rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
        }
        .status-draft { color: #b45309; background: #fffbeb; border-color: #fcd34d; }
        .status-pending_verification { color: #1e4bd2; background: #eef2ff; border-color: #a5c9ff; }
        .status-active { color: #0b6b4d; background: #e6f7ec; border-color: #a3e0c0; }
        .status-suspended { color: #b91c1c; background: #fef2f2; border-color: #fecaca; }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            background: #ffffff;
            border-radius: 2rem;
            box-shadow: var(--shadow);
            border: 1px solid var(--border);
            max-width: 500px;
            margin: 2rem auto;
        }
        .empty-state i {
            font-size: 4rem;
            color: #94a3b8;
            margin-bottom: 1.5rem;
        }
        .empty-state h2 {
            font-size: 1.8rem;
            font-weight: 600;
            color: var(--navy);
            margin-bottom: 0.75rem;
        }
        .empty-state p {
            color: var(--text-muted);
            margin-bottom: 2rem;
            font-size: 1rem;
        }

        /* Company Detail View (from original) */
        .detail-container {
            max-width: 900px;
            background: #fff;
            border-radius: var(--radius);
            padding: 2rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        .company-detail-header {
            display: flex;
            align-items: center;
            gap: 1.5rem;
            flex-wrap: wrap;
        }
        .company-detail-logo {
            max-width: 100px;
            max-height: 100px;
            border-radius: 50%;
            border: 2px solid white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        .company-detail-banner {
            width: 100%;
            max-height: 250px;
            object-fit: cover;
            border-radius: var(--radius);
            margin: 1rem 0;
        }
        .detail-status-badge {
            display: inline-block;
            padding: 0.4rem 1rem;
            border-radius: 30px;
            font-size: 0.85rem;
            font-weight: 500;
        }
        .detail-status-draft { background: #fffbeb; color: #b45309; border: 1px solid #fcd34d; }
        .detail-status-pending_verification { background: #eef2ff; color: #1e4bd2; border: 1px solid #a5c9ff; }
        .detail-status-active { background: #e6f7ec; color: #0b6b4d; border: 1px solid #a3e0c0; }
        .detail-status-suspended { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .admin-list {
            list-style: none;
            padding: 0;
        }
        .admin-list li {
            padding: 0.75rem;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        .role-badge {
            background: var(--navy-mid);
            color: white;
            padding: 0.2rem 0.75rem;
            border-radius: 30px;
            font-size: 0.75rem;
        }
        .alert {
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
        }
        .alert.success {
            background: var(--green-bg);
            color: var(--green);
            border: 1px solid var(--green-bdr);
        }
        .alert.error {
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid var(--error-bdr);
        }

        /* Responsive */
        @media (max-width: 1024px) {
            .sidebar {
                display: none;
            }
            .main-content-area {
                padding: 1.5rem;
            }
            .header-nav {
                display: none !important;
            }
        }
        @media (max-width: 768px) {
            .main-content-area {
                padding: 1rem;
            }
            .companies-grid {
                grid-template-columns: 1fr;
            }
            .nav-tabs {
                flex-direction: column;
                gap: 0.5rem;
            }
            .nav-tab {
                justify-content: center;
            }
            .add-company-wrapper {
                justify-content: stretch;
            }
            .btn-primary {
                width: 100%;
                justify-content: center;
            }
            .detail-container {
                padding: 1rem;
            }
            .company-detail-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        @media (min-width: 641px) and (max-width: 1024px) {
            .companies-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }

        ::-webkit-scrollbar {
            width: 5px;
        }
        ::-webkit-scrollbar-thumb {
            background: var(--border);
            border-radius: 99px;
        }
    </style>
</head>
<body>

<!-- HEADER (same as other pages) -->
<header class="top-header">
    <div class="logo-container">
        <a href="/app/" class="logo"><span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span></a>
    </div>
    <nav class="header-nav">
        <a href="/app/"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/discover/"><i class="fa-solid fa-compass"></i> Discover</a>
        <!--<a href="/company/" class="active"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar" id="AvatarBtn"><?php echo htmlspecialchars($userInitial); ?></div>
    <div class="header-dropdown" id="UserDropdown" role="menu" aria-label="User menu">
        <div class="header-dropdown-inner">
            <div class="header-section-label">Dashboard</div>
            <a href="/app/" class="header-dropdown-link">
                <i class="fa-solid fa-chart-pie"></i> Portfolio
            </a>
            <a href="/app/?tab=payouts" class="header-dropdown-link">
                <i class="fa-solid fa-coins"></i> Payouts
            </a>
            <a href="/app/?tab=watchlist" class="header-dropdown-link">
                <i class="fa-solid fa-bookmark"></i> Watchlist
            </a>
            <div class="header-section-label">Discover</div>
            <a href="/app/discover/" class="header-dropdown-link">
                <i class="fa-solid fa-compass"></i> Browse Businesses
            </a>
            <div class="header-section-label">Account</div>
            <a href="/app/wallet/" class="header-dropdown-link">
                <i class="fa-solid fa-wallet"></i> Wallet
            </a>
            <a href="/app/profile.php" class="header-dropdown-link">
                <i class="fa-solid fa-user"></i> Profile
            </a>
            <div class="header-divider"></div>
            <a href="/app/auth/logout.php" class="header-dropdown-link danger">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</header>

<div class="page-wrapper">
    <!-- LEFT SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-section-label">Dashboard</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <a href="/app/?tab=watchlist"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
        <div class="sidebar-section-label">Account</div>
        <!--<a href="/company/" class="active"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content-area">
        <?php if (empty($uuid)):
            // ------------------------------------------------------------
            // LIST VIEW – all companies the user is an admin of
            // ------------------------------------------------------------
            $stmt = $pdo->prepare("
                SELECT c.* FROM companies c
                JOIN company_admins ca ON ca.company_id = c.id
                WHERE ca.user_id = :user_id
                ORDER BY c.created_at DESC
            ");
            $stmt->execute(['user_id' => $_SESSION['user_id']]);
            $userCompanies = $stmt->fetchAll();
        ?>
            <div class="page-header">
                <h1>My Companies</h1>
                <div class="subtitle">Manage and monitor your businesses and startups</div>
            </div>

            <!-- Add Company Button (always visible) -->
            <div class="add-company-wrapper">
                <a href="create.php" class="btn-primary">
                    <i class="fa-solid fa-plus"></i> Add a Company
                </a>
            </div>

            <?php if (empty($userCompanies)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-building-circle-exclamation"></i>
                    <h2>No companies yet</h2>
                    <p>Get started by creating your first company profile.</p>
                </div>
            <?php else: ?>
                <div class="companies-grid">
                    <?php foreach ($userCompanies as $company):
                        $status = $company['status'];
                        $iconClass = '';
                        $statusClass = 'status-' . $status;
                        switch ($status) {
                            case 'draft':                $iconClass = 'fa-pencil';             break;
                            case 'pending_verification': $iconClass = 'fa-clock';              break;
                            case 'active':               $iconClass = 'fa-check-circle';       break;
                            case 'suspended':            $iconClass = 'fa-exclamation-triangle'; break;
                            default:                     $iconClass = 'fa-question-circle';
                        }
                        $banner        = !empty($company['banner']) ? htmlspecialchars($company['banner']) : '/assets/images/default-banner.jpg';
                        $logo          = !empty($company['logo'])   ? htmlspecialchars($company['logo'])   : '/assets/images/default-logo.png';
                        $statusDisplay = ucfirst(str_replace('_', ' ', $status));
                    ?>
                        <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($company['uuid']); ?>" class="company-listing-card">
                            <div class="company-listing-banner" style="background-image: url('<?php echo $banner; ?>');">
                                <div class="company-listing-logo-wrapper">
                                    <img src="<?php echo $logo; ?>" alt="<?php echo htmlspecialchars($company['name']); ?> logo" class="company-listing-logo">
                                </div>
                            </div>
                            <div class="company-listing-info">
                                <div class="company-listing-name"><?php echo htmlspecialchars($company['name']); ?></div>
                                <div class="status-with-icon <?php echo $statusClass; ?>">
                                    <i class="fa-solid <?php echo $iconClass; ?>"></i>
                                    <span><?php echo $statusDisplay; ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        <?php else:
            // ------------------------------------------------------------
            // DETAIL VIEW – show specific company
            // ------------------------------------------------------------
            $company = getCompanyByUuid($uuid);
            if (!$company) {
                echo '<div class="empty-state"><i class="fa-solid fa-building-circle-exclamation"></i><h2>Company not found</h2><p>The company you are looking for does not exist.</p><a href="/company" class="btn-primary">← Back to Companies</a></div>';
            } else {
                // User must have at least viewer permission
                requireCompanyRole($company['id'], 'viewer');

                $userRoleData = getUserCompanyRole($company['id'], $_SESSION['user_id']);
                $userRole     = $userRoleData ? $userRoleData['role'] : '';

                $stmt = $pdo->prepare("SELECT * FROM company_kyc WHERE company_id = :company_id");
                $stmt->execute(['company_id' => $company['id']]);
                $kyc = $stmt->fetch();

                $stmt = $pdo->prepare("
                    SELECT al.*, u.email
                    FROM company_activity_logs al
                    JOIN users u ON al.user_id = u.id
                    WHERE al.company_id = :company_id
                    ORDER BY al.created_at DESC
                    LIMIT 10
                ");
                $stmt->execute(['company_id' => $company['id']]);
                $activities = $stmt->fetchAll();

                $submitted = isset($_GET['submitted']);
                ?>
                <div class="detail-container">
                    <?php if ($submitted): ?>
                        <div class="alert success">Your company has been submitted for verification.</div>
                    <?php endif; ?>

                    <div class="company-detail-header">
                        <?php if ($company['logo']): ?>
                            <img src="<?php echo htmlspecialchars($company['logo']); ?>" alt="Logo" class="company-detail-logo">
                        <?php endif; ?>
                        <h1><?php echo htmlspecialchars($company['name']); ?></h1>
                    </div>

                    <div style="margin: 1rem 0;">
                        <span class="detail-status-badge detail-status-<?php echo $company['status']; ?>">
                            <i class="fa-solid
                                <?php
                                    switch($company['status']) {
                                        case 'draft':                echo 'fa-pencil';             break;
                                        case 'pending_verification': echo 'fa-clock';              break;
                                        case 'active':               echo 'fa-check-circle';       break;
                                        case 'suspended':            echo 'fa-exclamation-triangle'; break;
                                        default:                     echo 'fa-question-circle';
                                    }
                                ?>"></i>
                            Status: <?php echo ucfirst(str_replace('_', ' ', $company['status'])); ?>
                        </span>
                        <?php if ($company['verified']): ?>
                            <span class="detail-status-badge detail-status-active" style="margin-left: 0.5rem;">Verified</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($company['banner']): ?>
                        <img src="<?php echo htmlspecialchars($company['banner']); ?>" alt="Banner" class="company-detail-banner">
                    <?php endif; ?>

                    <h2>Company Information</h2>
                    <p><strong>Type:</strong> <?php echo ucfirst($company['type'] ?? 'N/A'); ?></p>
                    <p><strong>Industry:</strong> <?php echo htmlspecialchars($company['industry'] ?? 'N/A'); ?></p>
                    <p><strong>Stage:</strong> <?php echo htmlspecialchars($company['stage'] ?? 'N/A'); ?></p>
                    <p><strong>Registration #:</strong> <?php echo htmlspecialchars($company['registration_number'] ?? 'N/A'); ?></p>
                    <p><strong>About:</strong> <?php echo nl2br(htmlspecialchars($company['description'] ?? '')); ?></p>
                    <p><strong>Email:</strong> <?php echo htmlspecialchars($company['email'] ?? 'N/A'); ?></p>
                    <p><strong>Phone:</strong> <?php echo htmlspecialchars($company['phone'] ?? 'N/A'); ?></p>
                    <p><strong>Website:</strong> <?php echo $company['website'] ? '<a href="'.htmlspecialchars($company['website']).'" target="_blank">'.htmlspecialchars($company['website']).'</a>' : 'N/A'; ?></p>

                    <h2>Business Verification</h2>
                    <?php if ($kyc): ?>
                        <p><strong>Status:</strong> <?php echo ucfirst($kyc['verification_status']); ?></p>
                        <?php if ($kyc['verification_status'] == 'rejected' && $kyc['rejection_reason']): ?>
                            <div class="alert error">Rejection reason: <?php echo htmlspecialchars($kyc['rejection_reason']); ?></div>
                        <?php endif; ?>
                    <?php else: ?>
                        <p>No verification documents uploaded yet.</p>
                    <?php endif; ?>

                    <h2>Administrators</h2>
                    <?php $admins = getCompanyAdmins($company['id']); ?>
                    <ul class="admin-list">
                        <?php foreach ($admins as $admin): ?>
                            <li>
                                <?php echo htmlspecialchars($admin['email']); ?>
                                <span class="role-badge"><?php echo ucfirst($admin['role']); ?></span>
                                <?php if ($admin['role'] === 'owner'): ?><span>(Owner)</span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>

                    <?php if (hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin')): ?>
                        <p><a href="manage_admins.php?uuid=<?php echo urlencode($uuid); ?>" class="btn-primary" style="display: inline-block; margin-right: 0.5rem;">Manage Administrators</a></p>
                    <?php endif; ?>

                    <?php if ($company['status'] === 'draft' && hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin')): ?>
                        <p><a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>" class="btn-primary" style="display: inline-block;">Complete Profile</a></p>
                    <?php endif; ?>

                    <h2>Recent Activity</h2>
                    <?php if ($activities): ?>
                        <ul style="list-style: none; padding: 0;">
                            <?php foreach ($activities as $log): ?>
                                <li style="padding: 0.5rem 0; border-bottom: 1px solid var(--border);"><?php echo htmlspecialchars($log['created_at']); ?> – <?php echo htmlspecialchars($log['email']); ?> <?php echo htmlspecialchars($log['action']); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <p>No recent activity.</p>
                    <?php endif; ?>
                </div>
            <?php } ?>
        <?php endif; ?>
    </div>
</div>

<!-- External dropdown JS -->
<script src="../assets/js/dropdown.js"></script>
</body>
</html>