<?php
/**
 * profile.php – Member profile management (Personal, Address, KYC, Tax)
 * Integrated with Old Union dashboard header & sidebar.
 */

// -----------------------------------------------------------------------------
// 1. Initialisation and security
// -----------------------------------------------------------------------------
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';        // auto‑validates POST
require_once '../includes/database.php';
require_once '../includes/functions.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: /app/auth/login.php');
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = Database::getInstance();

// -----------------------------------------------------------------------------
// 2. Helper: fetch a single row from a table (or return empty array)
// -----------------------------------------------------------------------------
function fetchUserData(PDO $pdo, string $table, int $userId): array {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE user_id = ?");
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: [];
}

// -----------------------------------------------------------------------------
// 3. Load current data from all four tables + users table
// -----------------------------------------------------------------------------
$personal = fetchUserData($pdo, 'user_profiles', $userId);
$address  = fetchUserData($pdo, 'user_addresses', $userId);
$kyc      = fetchUserData($pdo, 'user_kyc', $userId);
$tax      = fetchUserData($pdo, 'user_tax_information', $userId);

// Also fetch basic user data (first_name, last_name are stored in `users`)
$stmt = $pdo->prepare("SELECT first_name, last_name FROM user_profiles WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch() ?: [];

// Merge everything into one convenient array for the HTML templates
$data = array_merge($user, $personal, $address, $kyc, $tax);

// -----------------------------------------------------------------------------
// 4. Handle POST requests (form submissions)
// -----------------------------------------------------------------------------
$message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !validateCSRFToken($_POST['csrf_token'])) {
        die('Invalid CSRF token.');
    }

    $tab = $_POST['tab'] ?? '';
    $updateData = $_POST;
    unset($updateData['csrf_token'], $updateData['tab']);

    try {
        $pdo->beginTransaction();

        switch ($tab) {
            case 'personal':
                if (empty($personal)) {
                    $sql = "INSERT INTO user_profiles 
                            (user_id, title, first_name, last_name, initials, preferred_name,
                             date_of_birth, gender, phone, identification_type, identification_number,
                             country_of_birth, city_of_birth, country_of_residence)
                            VALUES 
                            (:user_id, :title, :first_name, :last_name, :initials, :preferred_name,
                             :date_of_birth, :gender, :phone, :identification_type, :identification_number,
                             :country_of_birth, :city_of_birth, :country_of_residence)";
                } else {
                    $sql = "UPDATE user_profiles SET
                            title = :title, first_name = :first_name, last_name = :last_name,
                            initials = :initials, preferred_name = :preferred_name,
                            date_of_birth = :date_of_birth, gender = :gender, phone = :phone,
                            identification_type = :identification_type,
                            identification_number = :identification_number,
                            country_of_birth = :country_of_birth, city_of_birth = :city_of_birth,
                            country_of_residence = :country_of_residence
                            WHERE user_id = :user_id";
                }
                $updateData['user_id'] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateData);
                break;

            case 'address':
                if (empty($address)) {
                    $sql = "INSERT INTO user_addresses
                            (user_id, address_line_1, address_line_2, city, province, postal_code, country)
                            VALUES
                            (:user_id, :address_line_1, :address_line_2, :city, :province, :postal_code, :country)";
                } else {
                    $sql = "UPDATE user_addresses SET
                            address_line_1 = :address_line_1, address_line_2 = :address_line_2,
                            city = :city, province = :province, postal_code = :postal_code, country = :country
                            WHERE user_id = :user_id";
                }
                $updateData['user_id'] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateData);
                break;

            case 'kyc':
                if (empty($kyc)) {
                    $sql = "INSERT INTO user_kyc
                            (user_id, source_of_income, account_funds_origin, occupation,
                             total_annual_income_range, employer, industry)
                            VALUES
                            (:user_id, :source_of_income, :account_funds_origin, :occupation,
                             :total_annual_income_range, :employer, :industry)";
                } else {
                    $sql = "UPDATE user_kyc SET
                            source_of_income = :source_of_income,
                            account_funds_origin = :account_funds_origin,
                            occupation = :occupation,
                            total_annual_income_range = :total_annual_income_range,
                            employer = :employer,
                            industry = :industry
                            WHERE user_id = :user_id";
                }
                $updateData['user_id'] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateData);
                break;

            case 'tax':
                $updateData['vat_registered'] = isset($_POST['vat_registered']) ? 1 : 0;
                if ($updateData['vat_registered'] == 0) {
                    $updateData['vat_number'] = null;
                }

                if (empty($tax)) {
                    $sql = "INSERT INTO user_tax_information
                            (user_id, tax_number, tax_residency_country, vat_registered, vat_number, tax_status)
                            VALUES
                            (:user_id, :tax_number, :tax_residency_country, :vat_registered, :vat_number, :tax_status)";
                } else {
                    $sql = "UPDATE user_tax_information SET
                            tax_number = :tax_number,
                            tax_residency_country = :tax_residency_country,
                            vat_registered = :vat_registered,
                            vat_number = :vat_number,
                            tax_status = :tax_status
                            WHERE user_id = :user_id";
                }
                $updateData['user_id'] = $userId;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($updateData);
                break;

            case 'security':
                // Password change
                $currentPassword = $_POST['current_password'] ?? '';
                $newPassword = $_POST['new_password'] ?? '';
                $confirmPassword = $_POST['confirm_password'] ?? '';

                // Fetch current hash
                $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
                $stmt->execute([$userId]);
                $userRow = $stmt->fetch();
                if (!$userRow) {
                    throw new Exception('User not found.');
                }

                // Verify current password
                if (!password_verify($currentPassword, $userRow['password_hash'])) {
                    throw new Exception('Current password is incorrect.');
                }

                // Validate new password
                if (strlen($newPassword) < 8) {
                    throw new Exception('New password must be at least 8 characters long.');
                }
                if ($newPassword !== $confirmPassword) {
                    throw new Exception('New password and confirmation do not match.');
                }

                // Hash new password and update
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $stmt->execute([$newHash, $userId]);

                // Regenerate session ID for security
                session_regenerate_id(true);
                break;

            default:
                throw new Exception('Invalid tab.');
        }

        $pdo->commit();

        // Refresh data after update
        $personal = fetchUserData($pdo, 'user_profiles', $userId);
        $address  = fetchUserData($pdo, 'user_addresses', $userId);
        $kyc      = fetchUserData($pdo, 'user_kyc', $userId);
        $tax      = fetchUserData($pdo, 'user_tax_information', $userId);
        $data = array_merge($user, $personal, $address, $kyc, $tax);

        $message = ['type' => 'success', 'text' => 'Information saved successfully.'];

    } catch (Exception $e) {
        $pdo->rollBack();
        error_log('Profile update error: ' . $e->getMessage());
        $message = ['type' => 'error', 'text' => 'An error occurred. Please try again.'];
    }
}

// -----------------------------------------------------------------------------
// 5. Calculate profile completion percentage
// -----------------------------------------------------------------------------
$completionFields = [
    'first_name', 'last_name', 'date_of_birth', 'identification_number',
    'address_line_1', 'city', 'province', 'postal_code', 'country',
    'source_of_income', 'occupation',
    'tax_number'
];

$filled = 0;
foreach ($completionFields as $field) {
    if (!empty($data[$field])) {
        $filled++;
    }
}
$completion = round(($filled / count($completionFields)) * 100);

// -----------------------------------------------------------------------------
// 6. Determine active tab
// -----------------------------------------------------------------------------
$allowedTabs = ['personal', 'address', 'kyc', 'tax', 'security'];
$active_tab = $_GET['tab'] ?? 'personal';
if (!in_array($active_tab, $allowedTabs)) {
    $active_tab = 'personal';
}

// -----------------------------------------------------------------------------
// 7. Generate CSRF token
// -----------------------------------------------------------------------------
$csrf_token = generateCSRFToken();

// -----------------------------------------------------------------------------
// 8. Fetch user email for avatar initial
// -----------------------------------------------------------------------------
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$email = $user['email'] ?? '';
$userInitial = $email ? strtoupper(substr($email, 0, 1)) : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Old Union | My Profile</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- External dropdown CSS -->
    <link rel="stylesheet" href="/assets/css/dropdown.css">
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
            --success: #067647;
            --success-bg: #ecfdf3;
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

        /* Profile-specific styles */
        .page-header {
            margin-bottom: 30px;
        }
        .page-header h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.75rem;
            color: var(--navy);
            margin-bottom: .25rem;
        }
        .progress-section {
            margin: 30px 0 20px;
        }
        .progress-label {
            display: flex;
            justify-content: space-between;
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 8px;
        }
        .progress-bar-bg {
            height: 8px;
            background: #edf2f7;
            border-radius: 4px;
            overflow: hidden;
        }
        .progress-fill {
            height: 100%;
            background: var(--navy-mid);
            width: 0%;
            transition: width 0.3s ease;
        }
        .tabs {
            display: flex;
            border-bottom: 1px solid var(--border);
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .tab {
            padding: 12px 25px;
            font-size: 14px;
            cursor: pointer;
            color: var(--text-muted);
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            transition: all 0.2s;
            font-family: 'DM Sans', sans-serif;
        }
        .tab.active {
            border-bottom: 2px solid var(--navy-mid);
            color: var(--navy-mid);
            font-weight: 500;
        }
        .tab-content {
            display: none;
        }
        .tab-content.active {
            display: block;
        }
        .form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group.full-width {
            grid-column: span 2;
        }
        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text);
            margin-bottom: 6px;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            font-size: 14px;
            background: white;
            transition: border 0.2s;
            border-radius: var(--radius-sm);
            font-family: 'DM Sans', sans-serif;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: var(--navy-light);
            box-shadow: 0 0 0 2px rgba(26,86,176,0.1);
        }
        button[type="submit"] {
            background: var(--navy-mid);
            color: white;
            border: none;
            padding: 14px 30px;
            font-size: 15px;
            font-weight: 500;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 10px;
            border-radius: 99px;
            font-family: 'DM Sans', sans-serif;
        }
        button[type="submit"]:hover {
            background: var(--navy);
        }
        .alert {
            padding: 12px 16px;
            font-size: 14px;
            margin-bottom: 24px;
            border: 1px solid transparent;
            border-radius: var(--radius-sm);
        }
        .alert.success {
            background: var(--success-bg);
            border-color: #abefc6;
            color: var(--success);
        }
        .alert.error {
            background: var(--error-bg);
            border-color: #fecdca;
            color: var(--error);
        }
        .document-list {
            list-style: none;
            margin: 20px 0;
        }
        .document-list li {
            padding: 10px;
            border: 1px solid var(--border);
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .document-list .verified {
            color: var(--success);
            font-weight: 500;
        }
        .file-upload-group {
            border: 2px dashed var(--border);
            padding: 20px;
            text-align: center;
            margin-bottom: 20px;
        }
        .file-upload-group input[type="file"] {
            display: none;
        }
        .file-upload-group label {
            background: var(--surface-2);
            padding: 10px 20px;
            cursor: pointer;
            display: inline-block;
            border: 1px solid var(--border);
            border-radius: 99px;
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
            .form-grid {
                grid-template-columns: 1fr;
            }
            .form-group.full-width {
                grid-column: span 1;
            }
            .tabs {
                flex-direction: column;
                border-bottom: none;
            }
            .tab {
                text-align: left;
                padding: 10px 0;
                border-bottom: 1px solid var(--border);
            }
            .tab.active {
                border-bottom: 1px solid var(--navy-mid);
            }
        }
        @media (max-width: 480px) {
            .main-content-area {
                padding: 0.75rem;
            }
            .page-header h1 {
                font-size: 1.5rem;
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
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Discover</a>
        <!--<a href="/app/company/"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/profile.php" class="active"><i class="fa-solid fa-user"></i> Profile</a>
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
            <a href="/app/profile.php" class="header-dropdown-link active">
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
        <!--<a href="/app/company/"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php" class="active"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <!-- MAIN CONTENT -->
    <div class="main-content-area">
        <div class="page-header">
            <h1>My Profile</h1>
            <div class="subtitle">Manage your personal information and account settings</div>
        </div>

        <!-- Progress bar -->
        <div class="progress-section">
            <div class="progress-label">
                <span>Profile Completion</span>
                <span><?php echo $completion; ?>%</span>
            </div>
            <div class="progress-bar-bg">
                <div class="progress-fill" style="width: <?php echo $completion; ?>%;"></div>
            </div>
        </div>

        <!-- Display messages -->
        <?php if ($message): ?>
            <div class="alert <?php echo $message['type']; ?>"><?php echo htmlspecialchars($message['text']); ?></div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="tabs">
            <button class="tab <?php echo $active_tab === 'personal' ? 'active' : ''; ?>" data-tab="personal">Personal Details</button>
            <button class="tab <?php echo $active_tab === 'address' ? 'active' : ''; ?>" data-tab="address">Address</button>
            <button class="tab <?php echo $active_tab === 'kyc' ? 'active' : ''; ?>" data-tab="kyc">KYC</button>
            <button class="tab <?php echo $active_tab === 'tax' ? 'active' : ''; ?>" data-tab="tax">Tax Information</button>
            <button class="tab <?php echo $active_tab === 'security' ? 'active' : ''; ?>" data-tab="security">Security</button>
        </div>

        <!-- Personal Details Tab -->
        <div id="personal" class="tab-content <?php echo $active_tab === 'personal' ? 'active' : ''; ?>">
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="tab" value="personal">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Title</label>
                        <select name="title">
                            <option value="">Select</option>
                            <?php
                            $titles = ['Mr','Mrs','Ms','Dr','Prof','Other'];
                            foreach ($titles as $t) {
                                $selected = ($data['title'] ?? '') === $t ? 'selected' : '';
                                echo "<option value=\"$t\" $selected>$t</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" value="<?php echo htmlspecialchars($data['first_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" value="<?php echo htmlspecialchars($data['last_name'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Initials</label>
                        <input type="text" name="initials" value="<?php echo htmlspecialchars($data['initials'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Preferred Name</label>
                        <input type="text" name="preferred_name" value="<?php echo htmlspecialchars($data['preferred_name'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Date of Birth *</label>
                        <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($data['date_of_birth'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Gender</label>
                        <select name="gender">
                            <option value="">Select</option>
                            <option value="male" <?php echo ($data['gender'] ?? '') === 'male' ? 'selected' : ''; ?>>Male</option>
                            <option value="female" <?php echo ($data['gender'] ?? '') === 'female' ? 'selected' : ''; ?>>Female</option>
                            <option value="other" <?php echo ($data['gender'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                            <option value="prefer_not_to_say" <?php echo ($data['gender'] ?? '') === 'prefer_not_to_say' ? 'selected' : ''; ?>>Prefer not to say</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="tel" name="phone" value="<?php echo htmlspecialchars($data['phone'] ?? ''); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Identification Type</label>
                        <select name="identification_type">
                            <option value="">Select</option>
                            <option value="national_id" <?php echo ($data['identification_type'] ?? '') === 'national_id' ? 'selected' : ''; ?>>National ID</option>
                            <option value="passport" <?php echo ($data['identification_type'] ?? '') === 'passport' ? 'selected' : ''; ?>>Passport</option>
                            <option value="driver_license" <?php echo ($data['identification_type'] ?? '') === 'driver_license' ? 'selected' : ''; ?>>Driver's License</option>
                            <option value="other" <?php echo ($data['identification_type'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Identification Number *</label>
                        <input type="text" name="identification_number" value="<?php echo htmlspecialchars($data['identification_number'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Country of Birth</label>
                        <input type="text" name="country_of_birth" value="<?php echo htmlspecialchars($data['country_of_birth'] ?? 'South Africa'); ?>">
                    </div>
                    <div class="form-group">
                        <label>City of Birth</label>
                        <input type="text" name="city_of_birth" value="<?php echo htmlspecialchars($data['city_of_birth'] ?? ''); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Country of Residence</label>
                        <input type="text" name="country_of_residence" value="<?php echo htmlspecialchars($data['country_of_residence'] ?? 'South Africa'); ?>">
                    </div>
                </div>
                <button type="submit">Save Personal Details</button>
            </form>
        </div>

        <!-- Address Tab -->
        <div id="address" class="tab-content <?php echo $active_tab === 'address' ? 'active' : ''; ?>">
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="tab" value="address">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Address Line 1 *</label>
                        <input type="text" name="address_line_1" value="<?php echo htmlspecialchars($data['address_line_1'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Address Line 2</label>
                        <input type="text" name="address_line_2" value="<?php echo htmlspecialchars($data['address_line_2'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>City *</label>
                        <input type="text" name="city" value="<?php echo htmlspecialchars($data['city'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Province *</label>
                        <input type="text" name="province" value="<?php echo htmlspecialchars($data['province'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Postal Code *</label>
                        <input type="text" name="postal_code" value="<?php echo htmlspecialchars($data['postal_code'] ?? ''); ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Country *</label>
                        <input type="text" name="country" value="<?php echo htmlspecialchars($data['country'] ?? ''); ?>" required>
                    </div>
                </div>
                <button type="submit">Save Address</button>
            </form>
        </div>

        <!-- KYC Tab -->
        <div id="kyc" class="tab-content <?php echo $active_tab === 'kyc' ? 'active' : ''; ?>">
            <form method="POST" action="profile.php" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="tab" value="kyc">
                <div class="form-grid">
                    <div class="form-group">
                        <label>Source of Income</label>
                        <select name="source_of_income">
                            <option value="">Select</option>
                            <option value="employment" <?php echo ($data['source_of_income'] ?? '') === 'employment' ? 'selected' : ''; ?>>Employment</option>
                            <option value="business" <?php echo ($data['source_of_income'] ?? '') === 'business' ? 'selected' : ''; ?>>Business</option>
                            <option value="investment" <?php echo ($data['source_of_income'] ?? '') === 'investment' ? 'selected' : ''; ?>>Investment</option>
                            <option value="pension" <?php echo ($data['source_of_income'] ?? '') === 'pension' ? 'selected' : ''; ?>>Pension</option>
                            <option value="other" <?php echo ($data['source_of_income'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Origin of Account Funds</label>
                        <select name="account_funds_origin">
                            <option value="">Select</option>
                            <option value="salary" <?php echo ($data['account_funds_origin'] ?? '') === 'salary' ? 'selected' : ''; ?>>Salary</option>
                            <option value="savings" <?php echo ($data['account_funds_origin'] ?? '') === 'savings' ? 'selected' : ''; ?>>Savings</option>
                            <option value="gift" <?php echo ($data['account_funds_origin'] ?? '') === 'gift' ? 'selected' : ''; ?>>Gift</option>
                            <option value="inheritance" <?php echo ($data['account_funds_origin'] ?? '') === 'inheritance' ? 'selected' : ''; ?>>Inheritance</option>
                            <option value="other" <?php echo ($data['account_funds_origin'] ?? '') === 'other' ? 'selected' : ''; ?>>Other</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Occupation</label>
                        <input type="text" name="occupation" value="<?php echo htmlspecialchars($data['occupation'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Total Annual Income Range</label>
                        <select name="total_annual_income_range">
                            <option value="">Select</option>
                            <option value="0-50000" <?php echo ($data['total_annual_income_range'] ?? '') === '0-50000' ? 'selected' : ''; ?>>0 - 50,000</option>
                            <option value="50001-100000" <?php echo ($data['total_annual_income_range'] ?? '') === '50001-100000' ? 'selected' : ''; ?>>50,001 - 100,000</option>
                            <option value="100001-200000" <?php echo ($data['total_annual_income_range'] ?? '') === '100001-200000' ? 'selected' : ''; ?>>100,001 - 200,000</option>
                            <option value="200001-500000" <?php echo ($data['total_annual_income_range'] ?? '') === '200001-500000' ? 'selected' : ''; ?>>200,001 - 500,000</option>
                            <option value="500001+" <?php echo ($data['total_annual_income_range'] ?? '') === '500001+' ? 'selected' : ''; ?>>500,001+</option>
                        </select>
                    </div>
                    <div class="form-group full-width">
                        <label>Employer</label>
                        <input type="text" name="employer" value="<?php echo htmlspecialchars($data['employer'] ?? ''); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Industry</label>
                        <input type="text" name="industry" value="<?php echo htmlspecialchars($data['industry'] ?? ''); ?>">
                    </div>
                </div>
                <button type="submit">Save KYC Information</button>
            </form>
        </div>

        <!-- Tax Information Tab -->
        <div id="tax" class="tab-content <?php echo $active_tab === 'tax' ? 'active' : ''; ?>">
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="tab" value="tax">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Tax Number</label>
                        <input type="text" name="tax_number" value="<?php echo htmlspecialchars($data['tax_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Tax Residency Country</label>
                        <input type="text" name="tax_residency_country" value="<?php echo htmlspecialchars($data['tax_residency_country'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>VAT Registered?</label>
                        <input type="checkbox" name="vat_registered" value="1" <?php echo ($data['vat_registered'] ?? 0) ? 'checked' : ''; ?>>
                    </div>
                    <div class="form-group full-width" id="vat_number_group" style="<?php echo ($data['vat_registered'] ?? 0) ? '' : 'display:none;'; ?>">
                        <label>VAT Number</label>
                        <input type="text" name="vat_number" value="<?php echo htmlspecialchars($data['vat_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group full-width">
                        <label>Tax Status</label>
                        <select name="tax_status">
                            <option value="individual" <?php echo ($data['tax_status'] ?? 'individual') === 'individual' ? 'selected' : ''; ?>>Individual</option>
                            <option value="company" <?php echo ($data['tax_status'] ?? '') === 'company' ? 'selected' : ''; ?>>Company</option>
                            <option value="trust" <?php echo ($data['tax_status'] ?? '') === 'trust' ? 'selected' : ''; ?>>Trust</option>
                        </select>
                    </div>
                </div>
                <button type="submit">Save Tax Information</button>
            </form>
        </div>

        <!-- Security Tab (Password Change) -->
        <div id="security" class="tab-content <?php echo $active_tab === 'security' ? 'active' : ''; ?>">
            <form method="POST" action="profile.php">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="tab" value="security">
                <div class="form-grid">
                    <div class="form-group full-width">
                        <label>Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    <div class="form-group full-width">
                        <label>New Password (min. 8 characters)</label>
                        <input type="password" name="new_password" required>
                    </div>
                    <div class="form-group full-width">
                        <label>Confirm New Password</label>
                        <input type="password" name="confirm_password" required>
                    </div>
                </div>
                <button type="submit">Change Password</button>
            </form>
        </div>
    </div>
</div>
<?php include('classes/footer.php'); ?>
<!-- External dropdown JS -->
<script src="/assets/js/dropdown.js"></script>
<script>
    document.addEventListener('DOMContentLoaded', function() {
        // Tab switching
        const tabs = document.querySelectorAll('.tab');
        const contents = document.querySelectorAll('.tab-content');
        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const tabId = tab.getAttribute('data-tab');
                tabs.forEach(t => t.classList.remove('active'));
                tab.classList.add('active');
                contents.forEach(c => c.classList.remove('active'));
                document.getElementById(tabId).classList.add('active');
                // Update URL hash for direct linking
                window.location.hash = tabId;
            });
        });

        // VAT checkbox toggle
        const vatCheck = document.querySelector('input[name="vat_registered"]');
        const vatGroup = document.getElementById('vat_number_group');
        if (vatCheck && vatGroup) {
            vatCheck.addEventListener('change', function() {
                vatGroup.style.display = this.checked ? 'block' : 'none';
            });
        }

        // If URL hash matches a tab, activate it
        if (window.location.hash) {
            const hashTab = window.location.hash.substring(1);
            const targetTab = document.querySelector(`.tab[data-tab="${hashTab}"]`);
            if (targetTab) {
                targetTab.click();
            }
        }
    });
</script>
</body>
</html>