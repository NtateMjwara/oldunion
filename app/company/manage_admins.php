<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/company_functions.php';
require_once '../includes/mailer.php';
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

$uuid = $_GET['uuid'] ?? '';
$company = getCompanyByUuid($uuid);
if (!$company) {
    die('Company not found.');
}

// Only admin or owner can manage admins
requireCompanyRole($company['id'], 'admin');

$csrf_token = generateCSRFToken();
$error   = '';
$success = '';

// Handle invite submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invite'])) {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $role  = $_POST['role'] ?? 'admin';
    if (!in_array($role, ['admin', 'editor', 'viewer'])) {
        $role = 'admin';
    }
    if (!$email) {
        $error = 'Valid email is required.';
    } else {
        // Check if user already an admin
        $stmt = $pdo->prepare("
            SELECT ca.id FROM company_admins ca
            JOIN users u ON ca.user_id = u.id
            WHERE ca.company_id = :company_id AND u.email = :email
        ");
        $stmt->execute(['company_id' => $company['id'], 'email' => $email]);
        if ($stmt->fetch()) {
            $error = 'This user is already an administrator.';
        } else {
            // Create invite token
            $token   = bin2hex(random_bytes(32));
            $expires = date('Y-m-d H:i:s', time() + 7 * 24 * 3600); // 7 days
            $stmt = $pdo->prepare("
                INSERT INTO company_invites (company_id, email, role, token, expires_at, invited_by, created_at)
                VALUES (:company_id, :email, :role, :token, :expires, :invited_by, NOW())
            ");
            $stmt->execute([
                'company_id'  => $company['id'],
                'email'       => $email,
                'role'        => $role,
                'token'       => $token,
                'expires'     => $expires,
                'invited_by'  => $_SESSION['user_id'],
            ]);

            // Send email
            $inviteLink = SITE_URL . "/appcompany/accept_invite.php?token=$token";
            $body  = "You have been invited to become an administrator for " . htmlspecialchars($company['name']) . ".\n\n";
            $body .= "Role: " . ucfirst($role) . "\n";
            $body .= "Click the link to accept: <a href=\"$inviteLink\">$inviteLink</a>";
            sendEmail($email, 'Company Admin Invitation', $body);

            logCompanyActivity($company['id'], $_SESSION['user_id'], "Invited $email as $role");
            $success = 'Invitation sent.';
        }
    }
}

// Handle revoke admin
if (isset($_GET['revoke']) && is_numeric($_GET['revoke'])) {
    $adminId = (int) $_GET['revoke'];
    // Cannot revoke owner
    $stmt = $pdo->prepare("
        SELECT ca.*, u.email FROM company_admins ca
        JOIN users u ON ca.user_id = u.id
        WHERE ca.id = :id AND ca.company_id = :company_id
    ");
    $stmt->execute(['id' => $adminId, 'company_id' => $company['id']]);
    $admin = $stmt->fetch();
    if ($admin && $admin['role'] !== 'owner') {
        if (hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin')) {
            $stmt = $pdo->prepare("DELETE FROM company_admins WHERE id = :id");
            $stmt->execute(['id' => $adminId]);
            logCompanyActivity($company['id'], $_SESSION['user_id'], "Revoked admin {$admin['email']}");
            $success = 'Administrator revoked.';
        }
    }
    // Redirect to avoid re-execution on refresh
    redirect("/app/company/manage_admins.php?uuid=$uuid");
}

$admins = getCompanyAdmins($company['id']);

// Get pending invites
$stmt = $pdo->prepare("SELECT * FROM company_invites WHERE company_id = :company_id AND status = 'pending' ORDER BY created_at DESC");
$stmt->execute(['company_id' => $company['id']]);
$invites = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Administrators - <?php echo htmlspecialchars($company['name']); ?></title>
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
            --card-shadow: 0 10px 25px -5px rgba(0,0,0,0.05), 0 8px 10px -6px rgba(0,0,0,0.02);
            --hover-shadow: 0 20px 30px -10px rgba(11,37,69,0.15);
        }

        * { margin: 0; padding: 0; box-sizing: border-box; font-family: "Inter","Segoe UI",Tahoma,Geneva,Verdana,sans-serif; }

        body { background: var(--light-bg); color: var(--text-dark); min-height: 100vh; padding-top: var(--header-height); display: flex; }

        /* === FIXED HEADER === */
        .main-header {
            position: fixed; top: 0; left: 0; right: 0; padding: 10px 20px;
            background-color: #fff; color: #1D1D1F; z-index: 1000;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1); height: var(--header-height);
            display: flex; align-items: center;
        }
        .header-container { display: flex; justify-content: space-between; align-items: center; width: 100%; max-width: 1400px; margin: 0 auto; }
        .logo-container { display: flex; align-items: center; }
        .logo { font-family: 'Playfair Display', serif; font-weight: 700; font-size: 22px; color: #333; text-decoration: none; text-transform: uppercase; display: inline-block; }
        .logo span { display: inline-block; }
        .logo .second { color: #c8102e; font-family: 'Playfair Display', serif; }
        .logo .first, .logo .big { font-size: 1.8rem; line-height: 0.8; vertical-align: baseline; font-family: 'Playfair Display', serif; }
        .header-actions { display: flex; align-items: center; gap: 20px; position: relative; }
        .notification-badge { position: relative; cursor: pointer; }
        .notification-badge i { color: #666; font-size: 1.2rem; transition: color 0.3s; }
        .notification-badge:hover i { color: #c53030; }
        .notification-count { position: absolute; top: -5px; right: -5px; background-color: #c53030; color: white; border-radius: 50%; width: 20px; height: 20px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: bold; }
        .user-avatar { width: 42px; height: 42px; background: linear-gradient(135deg, #6a11cb 0%, #2575fc 100%); border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: bold; color: white; font-size: 18px; cursor: pointer; transition: transform 0.3s, box-shadow 0.3s; }
        .user-avatar:hover { transform: scale(1.05); box-shadow: 0 0 10px rgba(0,0,0,0.2); }
        .dropdown-content { display: none; position: absolute; top: 55px; right: 0; background-color: #FFFFFF; min-width: 220px; box-shadow: 0px 8px 16px rgba(0,0,0,0.15); z-index: 1001; border-radius: 8px; overflow: hidden; animation: fadeIn 0.3s ease; }
        .dropdown-content.show { display: block; }
        .dropdown-content a { color: #333; padding: 12px 16px; text-decoration: none; display: flex; align-items: center; transition: background-color 0.2s; font-size: 15px; border-bottom: 1px solid #f0f0f0; }
        .dropdown-content a:last-child { border-bottom: none; }
        .dropdown-content a i { width: 25px; margin-right: 10px; color: #6c757d; }
        .dropdown-content a:hover { background-color: #f8f9fa; color: #c53030; }
        .dropdown-content a:hover i { color: #c53030; }

        /* === SIDEBAR === */
        .sidebar { width: 250px; background: white; border-right: 1px solid var(--border); padding: 20px 25px; height: calc(100vh - var(--header-height)); position: sticky; top: var(--header-height); overflow-y: auto; }
        .sidebar a { display: block; text-decoration: none; color: var(--text-muted); margin-bottom: 18px; font-size: 14px; transition: color 0.2s; }
        .sidebar a:hover { color: var(--primary); }
        .sidebar a i { margin-right: 8px; width: 20px; }

        /* === MAIN CONTENT AREA === */
        .main { flex: 1; padding: 30px 50px; transition: padding 0.2s; overflow-x: auto; }

        /* ========== PAGE SPECIFIC STYLES ========== */
        .page-header { margin-bottom: 2rem; }
        .page-header h1 { font-size: 2rem; font-weight: 600; color: var(--primary); margin-bottom: 0.25rem; }
        .page-header .subtitle { color: var(--text-muted); font-size: 1rem; }

        .back-link { display: inline-flex; align-items: center; gap: 0.5rem; color: var(--primary); text-decoration: none; font-weight: 500; margin-bottom: 1.5rem; transition: color 0.2s; }
        .back-link:hover { color: var(--primary-light); }

        .admin-card { background: white; border-radius: 20px; padding: 1.75rem; margin-bottom: 2rem; border: 1px solid var(--border); box-shadow: var(--card-shadow); }
        .admin-card h2 { font-size: 1.3rem; font-weight: 600; margin-bottom: 1.25rem; display: flex; align-items: center; gap: 0.5rem; color: var(--primary); border-bottom: 1px solid var(--border); padding-bottom: 0.75rem; }
        .admin-card h2 i { color: var(--primary); }

        .admin-list { list-style: none; padding: 0; }
        .admin-list li { display: flex; align-items: center; flex-wrap: wrap; gap: 0.75rem; padding: 0.9rem 0; border-bottom: 1px solid var(--border); }
        .admin-list li:last-child { border-bottom: none; }
        .admin-email { font-weight: 500; flex: 1 1 200px; word-break: break-word; }
        .role-badge { background: var(--primary); color: white; padding: 0.25rem 0.75rem; border-radius: 30px; font-size: 0.75rem; font-weight: 500; }
        .role-badge.owner { background: #c8102e; }
        .revoke-link { color: #b91c1c; text-decoration: none; font-size: 0.9rem; margin-left: auto; display: flex; align-items: center; gap: 0.25rem; transition: color 0.2s; }
        .revoke-link:hover { color: #7f1d1d; text-decoration: underline; }

        .invite-form { display: flex; flex-direction: column; gap: 1.25rem; margin-top: 1rem; }
        .form-group { display: flex; flex-direction: column; gap: 0.5rem; }
        .form-group label { font-weight: 500; color: var(--text-dark); font-size: 0.95rem; }
        .form-group input, .form-group select { padding: 0.75rem 1rem; border: 1px solid var(--border); border-radius: 12px; font-size: 1rem; transition: border-color 0.2s; background: white; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--primary); box-shadow: 0 0 0 3px var(--primary-soft); }

        .btn-primary { display: inline-flex; align-items: center; justify-content: center; gap: 0.5rem; background: var(--primary); color: white; padding: 0.75rem 1.5rem; border-radius: 50px; text-decoration: none; font-weight: 500; font-size: 0.95rem; border: none; cursor: pointer; transition: background 0.2s, transform 0.1s, box-shadow 0.2s; box-shadow: 0 4px 8px rgba(11,37,69,0.2); align-self: flex-start; }
        .btn-primary:hover { background: var(--primary-light); transform: translateY(-2px); box-shadow: 0 8px 16px rgba(11,37,69,0.25); }

        .alert { padding: 1rem 1.5rem; border-radius: 16px; margin-bottom: 1.5rem; font-weight: 500; display: flex; align-items: center; gap: 0.75rem; border: 1px solid transparent; }
        .alert.success { background: #e6f7ec; color: #0b6b4d; border-color: #a3e0c0; }
        .alert.error { background: #fef2f2; color: #b91c1c; border-color: #fecaca; }

        .invite-item { display: flex; justify-content: space-between; align-items: center; padding: 0.75rem 0; border-bottom: 1px dashed var(--border); }
        .invite-item:last-child { border-bottom: none; }
        .invite-info { display: flex; flex-direction: column; gap: 0.25rem; }
        .invite-email { font-weight: 500; }
        .invite-meta { font-size: 0.85rem; color: var(--text-muted); }

        /* Responsive */
        @media (min-width: 769px) { .dropdown-content, .dropdown-content.show { display: none !important; } }
        @media (max-width: 768px) { .sidebar { display: none; } .main { padding: 20px 25px; } }
        @media (max-width: 640px) { .main { padding: 15px 20px; } .page-header h1 { font-size: 1.6rem; } .admin-list li { flex-direction: column; align-items: flex-start; } .revoke-link { margin-left: 0; } }
        @media (max-height: 500px) and (orientation: landscape) { .main { padding: 15px 20px; } }

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
        <a href="dashboard.php?uuid=<?php echo urlencode($uuid); ?>" class="back-link">
            <i class="fa-solid fa-arrow-left"></i> Back to Company Dashboard
        </a>

        <div class="page-header">
            <h1>Manage Administrators</h1>
            <div class="subtitle"><?php echo htmlspecialchars($company['name']); ?></div>
        </div>

        <?php if ($error): ?>
            <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="alert success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <!-- Current Administrators Card -->
        <div class="admin-card">
            <h2><i class="fa-solid fa-users"></i> Current Administrators</h2>
            <?php if (empty($admins)): ?>
                <p>No administrators found.</p>
            <?php else: ?>
                <ul class="admin-list">
                    <?php foreach ($admins as $admin): ?>
                        <li>
                            <span class="admin-email"><?php echo htmlspecialchars($admin['email']); ?></span>
                            <span class="role-badge <?php echo $admin['role'] === 'owner' ? 'owner' : ''; ?>">
                                <?php echo ucfirst($admin['role']); ?>
                            </span>
                            <?php if ($admin['role'] === 'owner'): ?>
                                <span style="color: var(--text-muted); font-size: 0.85rem;">(Creator)</span>
                            <?php endif; ?>
                            <?php if ($admin['role'] !== 'owner' && hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin')): ?>
                                <a href="?uuid=<?php echo urlencode($uuid); ?>&revoke=<?php echo $admin['id']; ?>"
                                   class="revoke-link"
                                   onclick="return confirm('Revoke this administrator?')">
                                    <i class="fa-solid fa-trash-can"></i> Revoke
                                </a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>

        <!-- Invite New Administrator Card -->
        <div class="admin-card">
            <h2><i class="fa-solid fa-user-plus"></i> Invite New Administrator</h2>
            <form method="POST" class="invite-form">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="admin@example.com">
                </div>
                <div class="form-group">
                    <label for="role">Role</label>
                    <select id="role" name="role">
                        <option value="admin">Admin (can manage admins and edit)</option>
                        <option value="editor">Editor (can edit content)</option>
                        <option value="viewer">Viewer (view only)</option>
                    </select>
                </div>
                <button type="submit" name="invite" class="btn-primary">
                    <i class="fa-solid fa-paper-plane"></i> Send Invitation
                </button>
            </form>
        </div>

        <!-- Pending Invitations Card -->
        <?php if ($invites): ?>
            <div class="admin-card">
                <h2><i class="fa-regular fa-clock"></i> Pending Invitations</h2>
                <div class="invite-list">
                    <?php foreach ($invites as $inv): ?>
                        <div class="invite-item">
                            <div class="invite-info">
                                <span class="invite-email"><?php echo htmlspecialchars($inv['email']); ?></span>
                                <span class="invite-meta">
                                    <i class="fa-regular fa-envelope"></i> <?php echo ucfirst($inv['role']); ?> ·
                                    Expires <?php echo date('M j, Y', strtotime($inv['expires_at'])); ?>
                                </span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- DROPDOWN TOGGLE SCRIPT -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            const avatar   = document.getElementById('avatarDropdown');
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
