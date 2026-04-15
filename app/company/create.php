<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

// Get user for avatar
$userId = $_SESSION['user_id'];
$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user = $stmt->fetch();
$email = $user['email'] ?? '';
$userInitial = $email ? strtoupper(substr($email, 0, 1)) : 'U';

$csrf_token = generateCSRFToken();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF already validated by csrf.php
    $name = trim($_POST['name'] ?? '');
    if (empty($name)) {
        $error = "Company name is required.";
    } else {
        $pdo = Database::getInstance();
        // Insert company with draft status
        $uuid = generateCompanyUuid();
        $stmt = $pdo->prepare("
            INSERT INTO companies (uuid, name, created_by, status, created_at)
            VALUES (:uuid, :name, :user_id, 'draft', NOW())
        ");
        $success = $stmt->execute([
            'uuid'    => $uuid,
            'name'    => $name,
            'user_id' => $_SESSION['user_id'],
        ]);

        if ($success) {
            $companyId = $pdo->lastInsertId();
            // Add creator as owner
            $stmt = $pdo->prepare("
                INSERT INTO company_admins (company_id, user_id, role, added_by, created_at)
                VALUES (:company_id, :user_id, 'owner', :added_by, NOW())
            ");
            $stmt->execute([
                'company_id' => $companyId,
                'user_id'    => $_SESSION['user_id'],
                'added_by'   => $_SESSION['user_id'],
            ]);

            // Log activity
            logCompanyActivity($companyId, $_SESSION['user_id'], 'Company created');

            // Redirect to onboarding wizard
            redirect("/app/company/wizard.php?uuid=$uuid");
        } else {
            $error = "Failed to create company. Please try again.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Create New Company</title>
    <!-- Font Awesome 6 (free) -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* === GLOBAL VARIABLES === */
        :root {
            --primary: #0b2545;
            --primary-light: #1e3a5f;
            --primary-soft: #e8f0fe;
            --light-bg: #f8f9fb;
            --border: #e4e7ec;
            --text-dark: #101828;
            --text-muted: #667085;
            --light: #f8f9fa;
            --secondary: #6c757d;
            --dark: #1D1D1F;
            --header-height: 70px;
            --card-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.05), 0 8px 10px -6px rgba(0, 0, 0, 0.02);
            --hover-shadow: 0 20px 30px -10px rgba(11, 37, 69, 0.15);
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

        .logo-container { display: flex; align-items: center; }

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
        .header-actions { display: flex; align-items: center; gap: 20px; position: relative; }

        .notification-badge { position: relative; cursor: pointer; }
        .notification-badge i { color: #666; font-size: 1.2rem; transition: color 0.3s; }
        .notification-badge:hover i { color: #c53030; }
        .notification-count {
            position: absolute; top: -5px; right: -5px;
            background-color: #c53030; color: white; border-radius: 50%;
            width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;
            font-size: 12px; font-weight: bold;
        }

        .user-avatar {
            width: 42px; height: 42px;
            background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%);
            border-radius: 50%; display: flex; align-items: center; justify-content: center;
            font-weight: bold; color: white; font-size: 18px; cursor: pointer;
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .user-avatar:hover { transform: scale(1.05); box-shadow: 0 0 10px rgba(0,0,0,0.2); }

        .dropdown-content {
            display: none; position: absolute; top: 55px; right: 0;
            background-color: #FFFFFF; min-width: 220px;
            box-shadow: 0px 8px 16px rgba(0,0,0,0.15); z-index: 1001;
            border-radius: 8px; overflow: hidden; animation: fadeIn 0.3s ease;
        }
        .dropdown-content.show { display: block; }
        .dropdown-content a {
            color: #333; padding: 12px 16px; text-decoration: none; display: flex;
            align-items: center; transition: background-color 0.2s; font-size: 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        .dropdown-content a:last-child { border-bottom: none; }
        .dropdown-content a i { width: 25px; margin-right: 10px; color: #6c757d; }
        .dropdown-content a:hover { background-color: #f8f9fa; color: #c53030; }
        .dropdown-content a:hover i { color: #c53030; }

        /* === SIDEBAR === */
        .sidebar {
            width: 250px; background: white; border-right: 1px solid var(--border);
            padding: 20px 25px; height: calc(100vh - var(--header-height));
            position: sticky; top: var(--header-height); overflow-y: auto;
        }
        .sidebar a { display: block; text-decoration: none; color: var(--text-muted); margin-bottom: 18px; font-size: 14px; transition: color 0.2s; }
        .sidebar a:hover { color: var(--primary); }
        .sidebar a i { margin-right: 8px; width: 20px; }

        /* === MAIN CONTENT AREA === */
        .main {
            flex: 1; padding: 30px 50px; transition: padding 0.2s;
            overflow-x: auto; display: flex; justify-content: center; align-items: flex-start;
        }

        /* ========== CREATE COMPANY FORM STYLES ========== */
        .create-card {
            background: white; border-radius: 24px; padding: 2.5rem;
            max-width: 600px; width: 100%; border: 1px solid var(--border);
            box-shadow: var(--card-shadow);
        }

        .create-card h1 { font-size: 2rem; font-weight: 600; color: var(--primary); margin-bottom: 0.5rem; }
        .create-card .subtitle { color: var(--text-muted); margin-bottom: 2rem; font-size: 1rem; }

        .form-group { margin-bottom: 1.5rem; }
        .form-group label { display: block; font-weight: 500; color: var(--text-dark); margin-bottom: 0.5rem; }
        .form-group input {
            width: 100%; padding: 0.875rem 1.25rem; border: 1px solid var(--border);
            border-radius: 16px; font-size: 1rem; transition: border-color 0.2s, box-shadow 0.2s;
        }
        .form-group input:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }
        .form-group input::placeholder { color: #a0aec0; }

        .alert {
            padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; font-weight: 500;
            display: flex; align-items: center; gap: 0.75rem; border: 1px solid transparent;
        }
        .alert.error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }
        .alert i { font-size: 1.25rem; }

        .btn-primary {
            display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem;
            background: var(--primary); color: white; padding: 0.875rem 2rem; border-radius: 50px;
            text-decoration: none; font-weight: 500; font-size: 1rem; border: none; cursor: pointer;
            transition: background 0.2s, transform 0.1s, box-shadow 0.2s;
            box-shadow: 0 4px 8px rgba(11, 37, 69, 0.2); width: 100%;
        }
        .btn-primary:hover { background: var(--primary-light); transform: translateY(-2px); box-shadow: 0 8px 16px rgba(11, 37, 69, 0.25); }

        .cancel-link {
            display: inline-flex; align-items: center; gap: 0.5rem; color: var(--text-muted);
            text-decoration: none; margin-top: 1.5rem; transition: color 0.2s;
        }
        .cancel-link:hover { color: var(--primary); }

        /* Responsive */
        @media (min-width: 769px) { .dropdown-content, .dropdown-content.show { display: none !important; } }
        @media (max-width: 768px) { .sidebar { display: none; } .main { padding: 20px 25px; } }
        @media (max-width: 640px) { .main { padding: 15px 20px; } .create-card { padding: 1.5rem; } .create-card h1 { font-size: 1.6rem; } }
        @media (max-height: 500px) and (orientation: landscape) { .main { padding: 15px 20px; align-items: center; } }

        /* Hide scrollbar */
        ::-webkit-scrollbar { display: none; }
        * { scrollbar-width: none; -ms-overflow-style: none; }

        @keyframes fadeIn { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>
    <!-- FIXED HEADER -->
    <header class="main-header">
        <div class="header-container">
            <div class="logo-container">
                <a href="#" class="logo">
                    <span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span>
                </a>
            </div>
            <div class="header-actions">
                <div class="notification-badge">
                    <a href="#" style="color: inherit;">
                        <i class="fas fa-bell"></i>
                        <span class="notification-count">3</span>
                    </a>
                </div>
                <div class="user-avatar" id="avatarDropdown"><?= $userInitial ?></div>
                <div class="dropdown-content" id="dropdownMenu">
                    <a href="../user"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
                    <a href="../company/"><i class="fa-solid fa-building"></i> My Companies</a>
                    <a href="../user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
                    <a href="#"><i class="fa-solid fa-briefcase"></i> Portfolio</a>
                    <a href="../wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
                    <a href="../logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
                </div>
            </div>
        </div>
    </header>

    <!-- SIDEBAR -->
    <aside class="sidebar">
        <a href="../user"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="../company/"><i class="fa-solid fa-building"></i> Companies</a>
        <a href="../user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="#"><i class="fa-solid fa-briefcase"></i> Portfolio</a>
        <a href="../wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="../auth/logout.php"><i class="fa-solid fa-sign-out-alt"></i> Logout</a>
    </aside>

    <!-- MAIN CONTENT -->
    <main class="main">
        <div class="create-card">
            <h1>Create a Company Profile</h1>
            <div class="subtitle">Start your journey by registering your business or startup</div>

            <?php if (isset($error)): ?>
                <div class="alert error">
                    <i class="fa-solid fa-circle-exclamation"></i>
                    <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-group">
                    <label for="name">Company Name</label>
                    <input type="text" id="name" name="name" required maxlength="255" placeholder="e.g., Acme Technologies Ltd">
                </div>
                <button type="submit" class="btn-primary">
                    <i class="fa-solid fa-plus"></i> Create Company
                </button>
            </form>

            <div style="text-align: center;">
                <a href="/app/" class="cancel-link">
                    <i class="fa-solid fa-arrow-left"></i> Cancel and return to dashboard
                </a>
            </div>
        </div>
    </main>

    <!-- DROPDOWN TOGGLE SCRIPT -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const avatar = document.getElementById('avatarDropdown');
            const dropdown = document.getElementById('dropdownMenu');
            if (avatar && dropdown) {
                avatar.addEventListener('click', function(e) { e.stopPropagation(); dropdown.classList.toggle('show'); });
                document.addEventListener('click', function(e) {
                    if (!avatar.contains(e.target) && !dropdown.contains(e.target)) dropdown.classList.remove('show');
                });
                dropdown.addEventListener('click', function(e) { e.stopPropagation(); });
            }
        });
    </script>
</body>
</html>
