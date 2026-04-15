<?php
/**
 * /app/profile/preferences.php
 *
 * US-102 — Investor Preferences & Directory Opt-In
 *
 * Investors set their investment preferences and choose whether
 * to appear in the founder-facing investor directory.
 *
 * No financial data is stored or exposed here.
 * All writes go to user_investor_preferences via INSERT … ON DUPLICATE KEY UPDATE.
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/profile/preferences.php'));
}

$userId     = (int)$_SESSION['user_id'];
$pdo        = Database::getInstance();
$csrf_token = generateCSRFToken();
$errors     = [];
$success    = '';

/* ── Load existing preferences ──────────────────────────── */
$stmt = $pdo->prepare("
    SELECT * FROM user_investor_preferences WHERE user_id = ?
");
$stmt->execute([$userId]);
$prefs = $stmt->fetch();

// Decode stored JSON
$stored = [];
if ($prefs && !empty($prefs['preferences_json'])) {
    $stored = json_decode($prefs['preferences_json'], true) ?: [];
}

/* ── Load user email for avatar + default handle ────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userEmail   = $authUser['email'] ?? '';
$userInitial = $userEmail ? strtoupper(substr($userEmail, 0, 1)) : 'U';

// Mask email for default display handle: j***@example.com
function maskEmail(string $email): string {
    [$local, $domain] = explode('@', $email, 2) + ['', ''];
    if (mb_strlen($local) <= 1) return $email;
    return $local[0] . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@' . $domain;
}
$defaultHandle = maskEmail($userEmail);

/* ── POST handler ───────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $optIn   = isset($_POST['opt_in_directory']) ? 1 : 0;
        $handle  = trim($_POST['display_handle'] ?? '');
        $bio     = trim($_POST['bio'] ?? '');
        $minCheque = trim($_POST['min_cheque'] ?? '');
        $maxCheque = trim($_POST['max_cheque'] ?? '');

        // Validate handle
        if ($handle !== '' && mb_strlen($handle) > 80) {
            $errors[] = 'Display handle must be 80 characters or fewer.';
        }
        // Validate cheque sizes
        if ($minCheque !== '' && (!is_numeric($minCheque) || (float)$minCheque < 0)) {
            $errors[] = 'Minimum investment must be a positive number.';
        }
        if ($maxCheque !== '' && (!is_numeric($maxCheque) || (float)$maxCheque < 0)) {
            $errors[] = 'Maximum investment must be a positive number.';
        }
        if ($minCheque !== '' && $maxCheque !== '' && (float)$maxCheque < (float)$minCheque) {
            $errors[] = 'Maximum investment must be greater than or equal to minimum.';
        }

        $validSectors = [
            'Technology & Software', 'Fintech & Financial Services',
            'Healthcare & Biotech', 'Education & EdTech',
            'E-Commerce & Retail', 'Agriculture & AgriTech',
            'Energy & CleanTech', 'Real Estate & PropTech',
            'Media & Entertainment', 'Logistics & Supply Chain',
            'Food & Beverage', 'Manufacturing',
            'Consulting & Professional Services',
            'Non-Profit & Social Impact', 'Other',
        ];
        $validTypes = ['revenue_share', 'cooperative_membership'];
        $validAreas = ['urban', 'township', 'rural'];

        $sectors = array_filter(
            $_POST['sectors'] ?? [],
            fn($s) => in_array($s, $validSectors, true)
        );
        $campaignTypes = array_filter(
            $_POST['campaign_types'] ?? [],
            fn($t) => in_array($t, $validTypes, true)
        );
        $areaPrefs = array_filter(
            $_POST['area_preferences'] ?? [],
            fn($a) => in_array($a, $validAreas, true)
        );

        if (empty($errors)) {
            $prefsJson = json_encode([
                'sectors'          => array_values($sectors),
                'campaign_types'   => array_values($campaignTypes),
                'area_preferences' => array_values($areaPrefs),
                'min_cheque'       => $minCheque !== '' ? (float)$minCheque : null,
                'max_cheque'       => $maxCheque !== '' ? (float)$maxCheque : null,
                'bio'              => $bio ?: null,
            ]);

            $pdo->prepare("
                INSERT INTO user_investor_preferences
                    (user_id, opt_in_directory, display_handle, preferences_json)
                VALUES
                    (:uid, :opt, :handle, :json)
                ON DUPLICATE KEY UPDATE
                    opt_in_directory = VALUES(opt_in_directory),
                    display_handle   = VALUES(display_handle),
                    preferences_json = VALUES(preferences_json)
            ")->execute([
                'uid'    => $userId,
                'opt'    => $optIn,
                'handle' => $handle ?: $defaultHandle,
                'json'   => $prefsJson,
            ]);

            // Reload
            $stmt = $pdo->prepare("SELECT * FROM user_investor_preferences WHERE user_id = ?");
            $stmt->execute([$userId]);
            $prefs  = $stmt->fetch();
            $stored = json_decode($prefs['preferences_json'] ?? '{}', true) ?: [];

            $success = $optIn
                ? 'Preferences saved. You are now visible to verified company founders.'
                : 'Preferences saved. You are currently hidden from the investor directory.';
        }
    }
}

/* ── View helpers ───────────────────────────────────────── */
$isOptedIn    = (bool)($prefs['opt_in_directory'] ?? false);
$savedHandle  = $prefs['display_handle'] ?? $defaultHandle;
$savedBio     = $stored['bio'] ?? '';
$savedMin     = $stored['min_cheque'] ?? '';
$savedMax     = $stored['max_cheque'] ?? '';
$savedSectors = $stored['sectors'] ?? [];
$savedTypes   = $stored['campaign_types'] ?? [];
$savedAreas   = $stored['area_preferences'] ?? [];

$allSectors = [
    'Technology & Software', 'Fintech & Financial Services',
    'Healthcare & Biotech', 'Education & EdTech',
    'E-Commerce & Retail', 'Agriculture & AgriTech',
    'Energy & CleanTech', 'Real Estate & PropTech',
    'Media & Entertainment', 'Logistics & Supply Chain',
    'Food & Beverage', 'Manufacturing',
    'Consulting & Professional Services',
    'Non-Profit & Social Impact', 'Other',
];
$typeLabels = [
    'revenue_share'          => ['Revenue Share',     'fa-chart-line'],
    'cooperative_membership' => ['Co-op Membership',  'fa-people-roof'],
];
$areaLabels = [
    'urban'    => ['Urban',    'fa-city'],
    'township' => ['Township', 'fa-house-flag'],
    'rural'    => ['Rural',    'fa-tree'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investor Preferences | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
        --amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;
        --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
        --surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;
        --text:#101828;--text-muted:#667085;--text-light:#98a2b3;
        --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
        --radius:14px;--radius-sm:8px;
        --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
        --shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);
        --header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    html{font-size:15px;-webkit-font-smoothing:antialiased;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}

    /* ── Header ── */
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
    .logo{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;white-space:nowrap;}
    .logo span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}

    /* ── Layout ── */
    .page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
    .sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
    .sidebar-section-label:first-child{margin-top:0;}
    .sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
    .sidebar a:hover{background:var(--surface-2);color:var(--text);}
    .sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
    .sidebar a i{width:16px;text-align:center;font-size:.85rem;}
    .main-content{flex:1;padding:2rem 2.5rem;min-width:0;max-width:780px;}

    /* ── Page head ── */
    .page-head{margin-bottom:1.75rem;}
    .breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:.4rem;}
    .breadcrumb a{color:var(--navy-light);text-decoration:none;}
    .breadcrumb a:hover{color:var(--navy);}
    .page-head h1{font-family:'DM Serif Display',serif;font-size:1.65rem;color:var(--navy);margin-bottom:.25rem;}
    .page-head p{font-size:.9rem;color:var(--text-muted);line-height:1.55;}

    /* ── Alerts ── */
    .alert{display:flex;align-items:flex-start;gap:.65rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.87rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}

    /* ── Cards ── */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.25rem;}
    .card-header{display:flex;align-items:center;gap:.5rem;padding:1rem 1.25rem;border-bottom:1px solid var(--border);}
    .card-title{font-size:.83rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--navy);display:flex;align-items:center;gap:.4rem;}
    .card-title i{color:var(--navy-light);}
    .card-body{padding:1.25rem;}

    /* ── Directory opt-in toggle ── */
    .opt-in-toggle{display:flex;align-items:flex-start;gap:1rem;padding:1.1rem 1.25rem;border-radius:var(--radius-sm);border:2px solid var(--border);background:var(--surface-2);cursor:pointer;transition:all var(--transition);}
    .opt-in-toggle.is-on{border-color:var(--green);background:var(--green-bg);}
    .opt-in-toggle input[type="checkbox"]{position:absolute;opacity:0;pointer-events:none;}
    .toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0;margin-top:.1rem;}
    .toggle-track{position:absolute;inset:0;border-radius:99px;background:var(--border);transition:background var(--transition);}
    .is-on .toggle-track{background:var(--green);}
    .toggle-thumb{position:absolute;top:2px;left:2px;width:20px;height:20px;border-radius:50%;background:#fff;box-shadow:0 1px 4px rgba(0,0,0,.2);transition:transform var(--transition);}
    .is-on .toggle-thumb{transform:translateX(20px);}
    .toggle-label-group{flex:1;}
    .toggle-label{font-size:.92rem;font-weight:600;color:var(--text);margin-bottom:.2rem;}
    .toggle-desc{font-size:.82rem;color:var(--text-muted);line-height:1.5;}
    .is-on .toggle-label{color:var(--green);}

    /* ── Form elements ── */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;}
    .form-grid .span-2{grid-column:span 2;}
    .field{display:flex;flex-direction:column;gap:.4rem;}
    .field label{font-size:.82rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.35rem;}
    .field label i{color:var(--navy-light);font-size:.78rem;}
    .field input,.field textarea{width:100%;padding:.65rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
    .field input:focus,.field textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.08);}
    .field textarea{resize:vertical;min-height:72px;line-height:1.6;}
    .field .hint{font-size:.75rem;color:var(--text-light);}
    .prefix-wrap{position:relative;}
    .prefix-wrap .prefix{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
    .prefix-wrap input{padding-left:2rem;}

    /* ── Chip selectors ── */
    .chip-grid{display:flex;flex-wrap:wrap;gap:.5rem;}
    .chip-option{position:relative;}
    .chip-option input[type="checkbox"]{position:absolute;opacity:0;pointer-events:none;}
    .chip-label{display:flex;align-items:center;gap:.35rem;padding:.35rem .85rem;border-radius:99px;border:1.5px solid var(--border);background:var(--surface-2);font-size:.82rem;font-weight:500;color:var(--text-muted);cursor:pointer;transition:all var(--transition);user-select:none;}
    .chip-label:hover{border-color:var(--navy-light);color:var(--navy-mid);}
    .chip-option input:checked + .chip-label{background:#eff4ff;border-color:var(--navy-mid);color:var(--navy-mid);font-weight:600;}
    .chip-option input:checked + .chip-label.chip-green{background:var(--green-bg);border-color:var(--green);color:var(--green);}
    .chip-option input:checked + .chip-label.chip-amber{background:var(--amber-light);border-color:var(--amber-dark);color:var(--amber-dark);}

    /* ── Privacy note ── */
    .privacy-note{display:flex;align-items:flex-start;gap:.6rem;padding:.85rem 1rem;background:var(--amber-light);border:1px solid var(--amber-dark);border-radius:var(--radius-sm);font-size:.82rem;color:#78350f;line-height:1.55;margin-bottom:1.25rem;}
    .privacy-note i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}

    /* ── Directory preview card ── */
    .preview-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1.1rem 1.25rem;display:flex;align-items:flex-start;gap:.85rem;}
    .preview-avatar{width:44px;height:44px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:1.1rem;flex-shrink:0;}
    .preview-handle{font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:.2rem;}
    .preview-chips{display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.4rem;}
    .preview-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
    .pc-sector{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
    .pc-type  {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .pc-area  {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .preview-cheque{font-size:.8rem;color:var(--text-muted);margin-top:.35rem;}

    /* ── Buttons ── */
    .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.65rem 1.35rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
    .btn-primary{background:var(--navy-mid);color:#fff;box-shadow:0 2px 8px rgba(15,59,122,.2);}
    .btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
    .btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-ghost:hover{border-color:#94a3b8;color:var(--text);}

    /* ── Section divider ── */
    .section-div{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);margin:1.5rem 0 .9rem;display:flex;align-items:center;gap:.6rem;}
    .section-div::after{content:'';flex:1;height:1px;background:var(--border);}

    /* ── Responsive ── */
    @media(max-width:1024px){.header-nav{display:none;}.main-content{padding:1.5rem;}}
    @media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}}
    @media(max-width:640px){.form-grid{grid-template-columns:1fr;}.form-grid .span-2{grid-column:span 1;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>

<header class="top-header">
    <a href="/app/" class="logo">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/app/"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Discover</a>
        <a href="/app/profile/preferences.php" class="active"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Dashboard</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <a href="/app/?tab=watchlist"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
        <div class="sidebar-section-label">Profile</div>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> My Profile</a>
        <a href="/app/profile/preferences.php" class="active"><i class="fa-solid fa-sliders"></i> Investor Preferences</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">
        <div class="page-head">
            <div class="breadcrumb">
                <a href="/app/profile.php">My Profile</a> &rsaquo; Investor Preferences
            </div>
            <h1>Investor Preferences</h1>
            <p>Control whether verified founders can find and invite you to private investment opportunities. Your financial information is <strong>never</strong> shared.</p>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($errors[0]); ?>
            </div>
        <?php endif; ?>

        <form method="POST" id="prefsForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <!-- ── Directory opt-in ── -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-eye"></i> Directory Visibility</div>
                </div>
                <div class="card-body">
                    <label class="opt-in-toggle <?php echo $isOptedIn ? 'is-on' : ''; ?>" id="optInToggle" for="opt_in_directory">
                        <div class="toggle-switch">
                            <div class="toggle-track"></div>
                            <div class="toggle-thumb"></div>
                        </div>
                        <div class="toggle-label-group">
                            <div class="toggle-label">
                                <?php echo $isOptedIn ? 'Visible to verified founders' : 'Hidden from investor directory'; ?>
                            </div>
                            <div class="toggle-desc">
                                When enabled, verified company founders can see your handle, sector interests, and investment range. They cannot see your wallet balance, transaction history, or any financial data.
                            </div>
                        </div>
                        <input type="checkbox" id="opt_in_directory" name="opt_in_directory" value="1"
                            <?php echo $isOptedIn ? 'checked' : ''; ?>>
                    </label>
                </div>
            </div>

            <!-- ── Display handle + bio ── -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-id-badge"></i> Public Display</div>
                </div>
                <div class="card-body">
                    <div class="privacy-note">
                        <i class="fa-solid fa-shield-halved"></i>
                        <div>Only your handle and preferences below are visible to founders. Your real name, email, and all financial data remain private regardless of your visibility setting.</div>
                    </div>
                    <div class="form-grid">
                        <div class="field span-2">
                            <label for="display_handle"><i class="fa-solid fa-at"></i> Display Handle</label>
                            <input type="text" id="display_handle" name="display_handle"
                                maxlength="80"
                                value="<?php echo htmlspecialchars($savedHandle); ?>"
                                placeholder="<?php echo htmlspecialchars($defaultHandle); ?>">
                            <span class="hint">Leave blank to use your masked email: <code><?php echo htmlspecialchars($defaultHandle); ?></code></span>
                        </div>
                        <div class="field span-2">
                            <label for="bio"><i class="fa-solid fa-quote-left"></i> Short Bio <span style="font-weight:400;color:var(--text-light);font-size:.78rem;">(optional)</span></label>
                            <textarea id="bio" name="bio" rows="2"
                                maxlength="300"
                                placeholder="e.g. Community-focused investor based in Johannesburg. Interested in township enterprises and cooperative businesses."><?php echo htmlspecialchars($savedBio); ?></textarea>
                            <span class="hint">Max 300 characters. Shown to founders in the directory.</span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ── Investment preferences ── -->
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-sliders"></i> Investment Preferences</div>
                </div>
                <div class="card-body">

                    <div class="section-div">Sectors of Interest</div>
                    <div class="chip-grid">
                        <?php foreach ($allSectors as $sector): ?>
                        <div class="chip-option">
                            <input type="checkbox" id="sec_<?php echo md5($sector); ?>"
                                name="sectors[]" value="<?php echo htmlspecialchars($sector); ?>"
                                <?php echo in_array($sector, $savedSectors, true) ? 'checked' : ''; ?>>
                            <label class="chip-label" for="sec_<?php echo md5($sector); ?>">
                                <?php echo htmlspecialchars($sector); ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-div">Campaign Types</div>
                    <div class="chip-grid">
                        <?php foreach ($typeLabels as $val => [$label, $icon]): ?>
                        <div class="chip-option">
                            <input type="checkbox" id="type_<?php echo $val; ?>"
                                name="campaign_types[]" value="<?php echo $val; ?>"
                                <?php echo in_array($val, $savedTypes, true) ? 'checked' : ''; ?>>
                            <label class="chip-label chip-green" for="type_<?php echo $val; ?>">
                                <i class="fa-solid <?php echo $icon; ?>"></i>
                                <?php echo $label; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-div">Area Preferences</div>
                    <div class="chip-grid">
                        <?php foreach ($areaLabels as $val => [$label, $icon]): ?>
                        <div class="chip-option">
                            <input type="checkbox" id="area_<?php echo $val; ?>"
                                name="area_preferences[]" value="<?php echo $val; ?>"
                                <?php echo in_array($val, $savedAreas, true) ? 'checked' : ''; ?>>
                            <label class="chip-label chip-amber" for="area_<?php echo $val; ?>">
                                <i class="fa-solid <?php echo $icon; ?>"></i>
                                <?php echo $label; ?>
                            </label>
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="section-div">Typical Investment Range</div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="min_cheque"><i class="fa-solid fa-arrow-down-1-9"></i> Minimum</label>
                            <div class="prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" id="min_cheque" name="min_cheque"
                                    min="0" step="500"
                                    value="<?php echo htmlspecialchars((string)($savedMin ?? '')); ?>"
                                    placeholder="1 000">
                            </div>
                        </div>
                        <div class="field">
                            <label for="max_cheque"><i class="fa-solid fa-arrow-up-9-1"></i> Maximum</label>
                            <div class="prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" id="max_cheque" name="max_cheque"
                                    min="0" step="500"
                                    value="<?php echo htmlspecialchars((string)($savedMax ?? '')); ?>"
                                    placeholder="50 000">
                            </div>
                        </div>
                    </div>
                    <p style="font-size:.76rem;color:var(--text-light);margin-top:.6rem;">
                        <i class="fa-solid fa-circle-info"></i>&nbsp;
                        Only a range band is shown to founders — exact amounts are not disclosed.
                    </p>

                </div>
            </div>

            <!-- ── Directory preview ── -->
            <?php if ($isOptedIn): ?>
            <div class="card">
                <div class="card-header">
                    <div class="card-title"><i class="fa-solid fa-eye"></i> Preview — How Founders See You</div>
                </div>
                <div class="card-body">
                    <div class="preview-card">
                        <div class="preview-avatar"><?php echo htmlspecialchars($userInitial); ?></div>
                        <div>
                            <div class="preview-handle"><?php echo htmlspecialchars($savedHandle ?: $defaultHandle); ?></div>
                            <?php if ($savedBio): ?>
                                <div style="font-size:.82rem;color:var(--text-muted);margin-top:.15rem;"><?php echo htmlspecialchars($savedBio); ?></div>
                            <?php endif; ?>
                            <div class="preview-chips">
                                <?php foreach (array_slice($savedSectors, 0, 3) as $s): ?>
                                    <span class="preview-chip pc-sector"><?php echo htmlspecialchars($s); ?></span>
                                <?php endforeach; ?>
                                <?php foreach ($savedTypes as $t): ?>
                                    <span class="preview-chip pc-type"><?php echo $typeLabels[$t][0] ?? $t; ?></span>
                                <?php endforeach; ?>
                                <?php foreach ($savedAreas as $a): ?>
                                    <span class="preview-chip pc-area"><?php echo $areaLabels[$a][0] ?? $a; ?></span>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($savedMin || $savedMax): ?>
                                <div class="preview-cheque">
                                    <i class="fa-solid fa-coins" style="font-size:.75rem;margin-right:.25rem;"></i>
                                    Typical range:
                                    <?php
                                    if ($savedMin && $savedMax) {
                                        echo 'R ' . number_format((float)$savedMin, 0, '.', ' ')
                                           . ' – R ' . number_format((float)$savedMax, 0, '.', ' ');
                                    } elseif ($savedMin) {
                                        echo 'From R ' . number_format((float)$savedMin, 0, '.', ' ');
                                    } else {
                                        echo 'Up to R ' . number_format((float)$savedMax, 0, '.', ' ');
                                    }
                                    ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                <button type="submit" class="btn btn-primary">
                    <i class="fa-solid fa-floppy-disk"></i> Save Preferences
                </button>
                <a href="/app/profile.php" class="btn btn-ghost">Cancel</a>
            </div>
        </form>
    </main>
</div>

<script>
// Toggle visual state when checkbox changes
const toggle   = document.getElementById('optInToggle');
const checkbox = document.getElementById('opt_in_directory');

checkbox.addEventListener('change', function () {
    if (this.checked) {
        toggle.classList.add('is-on');
        toggle.querySelector('.toggle-label').textContent = 'Visible to verified founders';
    } else {
        toggle.classList.remove('is-on');
        toggle.querySelector('.toggle-label').textContent = 'Hidden from investor directory';
    }
});

// Clicking the label area fires the checkbox
toggle.addEventListener('click', function(e) {
    if (e.target === checkbox) return; // already handled
    checkbox.checked = !checkbox.checked;
    checkbox.dispatchEvent(new Event('change'));
});
</script>
</body>
</html>
