<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$uuid = $_GET['uuid'] ?? '';
if (empty($uuid)) {
    redirect('/app/company/');
}

$company = getCompanyByUuid($uuid);
if (!$company) {
    redirect('/app/company/');
}

requireCompanyRole($company['id'], 'viewer');

$pdo       = Database::getInstance();
$userId    = $_SESSION['user_id'];
$companyId = $company['id'];

$userRole     = getUserCompanyRole($companyId, $userId);
$userRoleName = $userRole ? $userRole['role'] : 'viewer';
$canEdit      = hasCompanyPermission($companyId, $userId, 'editor');
$canAdmin     = hasCompanyPermission($companyId, $userId, 'admin');

/* ── Load all related data ───────────────────── */

// KYC
$stmt = $pdo->prepare("SELECT * FROM company_kyc WHERE company_id = ?");
$stmt->execute([$companyId]);
$kyc = $stmt->fetch();

// Location
$stmt = $pdo->prepare("SELECT * FROM company_filter WHERE company_id = ?");
$stmt->execute([$companyId]);
$location = $stmt->fetch();

// Pitch + highlights
$stmt = $pdo->prepare("SELECT * FROM company_pitch WHERE company_id = ?");
$stmt->execute([$companyId]);
$pitch = $stmt->fetch();

$stmt = $pdo->prepare("
    SELECT label, value FROM pitch_highlights
    WHERE company_id = ?
    ORDER BY sort_order ASC
");
$stmt->execute([$companyId]);
$highlights = $stmt->fetchAll();

// Financials count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM company_financials WHERE company_id = ?");
$stmt->execute([$companyId]);
$financialsCount = (int)$stmt->fetchColumn();

// Latest financial period
$stmt = $pdo->prepare("
    SELECT period_year, period_month, revenue, net_profit
    FROM company_financials
    WHERE company_id = ?
    ORDER BY period_year DESC, COALESCE(period_month, 0) DESC
    LIMIT 1
");
$stmt->execute([$companyId]);
$latestFinancial = $stmt->fetch();

// Milestones count
$stmt = $pdo->prepare("SELECT COUNT(*) FROM company_milestones WHERE company_id = ?");
$stmt->execute([$companyId]);
$milestonesCount = (int)$stmt->fetchColumn();

// Campaigns
$stmt = $pdo->prepare("
    SELECT id, uuid, title, campaign_type, status,
           raise_target, total_raised, contributor_count,
           opens_at, closes_at, created_at
    FROM funding_campaigns
    WHERE company_id = ?
    ORDER BY created_at DESC
");
$stmt->execute([$companyId]);
$campaigns = $stmt->fetchAll();

$activeCampaign = null;
foreach ($campaigns as $c) {
    if (in_array($c['status'], ['open', 'funded'], true)) {
        $activeCampaign = $c;
        break;
    }
}

// Admins
$admins = getCompanyAdmins($companyId);

// Recent activity
$stmt = $pdo->prepare("
    SELECT al.action, al.created_at, al.ip_address, u.email
    FROM company_activity_logs al
    JOIN users u ON al.user_id = u.id
    WHERE al.company_id = ?
    ORDER BY al.created_at DESC
    LIMIT 8
");
$stmt->execute([$companyId]);
$activities = $stmt->fetchAll();

/* ── Helpers ─────────────────────────────────── */
$isVerified = (bool)$company['verified'];
$canCampaign = $isVerified && $company['status'] === 'active';

$statusLabels = [
    'draft'                => ['Draft',                'status-draft'],
    'pending_verification' => ['Pending Verification', 'status-pending'],
    'active'               => ['Active',               'status-active'],
    'suspended'            => ['Suspended',            'status-suspended'],
];
$statusInfo = $statusLabels[$company['status']] ?? ['Unknown', 'status-draft'];

$campaignStatusLabels = [
    'draft'               => ['Draft',             'cs-draft'],
    'under_review'        => ['Under Review',      'cs-review'],
    'approved'            => ['Approved',           'cs-approved'],
    'open'                => ['Open',               'cs-open'],
    'funded'              => ['Funded',             'cs-funded'],
    'closed_successful'   => ['Closed – Success',  'cs-success'],
    'closed_unsuccessful' => ['Closed – Failed',   'cs-failed'],
    'cancelled'           => ['Cancelled',          'cs-cancelled'],
    'suspended'           => ['Suspended',          'cs-suspended'],
];

$campaignTypeLabels = [
    'revenue_share'          => ['Revenue Share',          'fa-chart-line'],
    'cooperative_membership' => ['Cooperative Membership', 'fa-people-roof'],
    'fixed_return_loan'      => ['Fixed Return Loan',      'fa-hand-holding-dollar'],
    'donation'               => ['Donation',               'fa-heart'],
    'convertible_note'       => ['Convertible Note',       'fa-rotate'],
];

function fmtMoney($val) {
    if ($val === null || $val === '') return '—';
    return 'R ' . number_format((float)$val, 0, '.', ' ');
}

// User initial for avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';

$submitted = isset($_GET['submitted']);
$banner    = !empty($company['banner']) ? htmlspecialchars($company['banner']) : '';
$logo      = !empty($company['logo'])   ? htmlspecialchars($company['logo'])   : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($company['name']); ?> | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --navy:        #0b2545;
        --navy-mid:    #0f3b7a;
        --navy-light:  #1a56b0;
        --amber:       #f59e0b;
        --amber-dark:  #d97706;
        --amber-light: #fef3c7;
        --green:       #0b6b4d;
        --green-bg:    #e6f7ec;
        --green-bdr:   #a7f3d0;
        --surface:     #ffffff;
        --surface-2:   #f8f9fb;
        --border:      #e4e7ec;
        --text:        #101828;
        --text-muted:  #667085;
        --text-light:  #98a2b3;
        --error:       #b91c1c;
        --error-bg:    #fef2f2;
        --radius:      14px;
        --radius-sm:   8px;
        --shadow:      0 4px 16px rgba(11,37,69,.07), 0 1px 3px rgba(11,37,69,.05);
        --header-h:    64px;
        --sidebar-w:   240px;
        --transition:  .2s cubic-bezier(.4,0,.2,1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: var(--surface-2); color: var(--text); min-height: 100vh; }

    /* ── HEADER ───────────────────────────────── */
    .top-header {
        position: fixed; top: 0; left: 0; right: 0; height: var(--header-h);
        background: var(--surface); border-bottom: 1px solid var(--border);
        display: flex; align-items: center; padding: 0 1.5rem;
        justify-content: space-between; z-index: 100; gap: 1rem;
    }
    .header-brand {
        font-family: 'DM Serif Display', serif; font-size: 1.35rem;
        color: var(--navy); text-decoration: none; white-space: nowrap;
    }
    .header-brand span { color: #c8102e; }
    .header-right { display: flex; align-items: center; gap: 1rem; }
    .header-nav { display: flex; align-items: center; gap: .25rem; }
    .header-nav a {
        display: flex; align-items: center; gap: .4rem; padding: .4rem .8rem;
        border-radius: 6px; font-size: .85rem; font-weight: 500; color: var(--text-muted);
        text-decoration: none; transition: all var(--transition);
    }
    .header-nav a:hover { background: var(--surface-2); color: var(--text); }
    .header-nav a.active { background: #eff4ff; color: var(--navy-mid); }
    .avatar {
        width: 36px; height: 36px; border-radius: 50%;
        background: linear-gradient(135deg,#6a11cb,#2575fc);
        display: flex; align-items: center; justify-content: center;
        font-weight: 700; color: #fff; font-size: .9rem;
        cursor: pointer; flex-shrink: 0;
    }

    /* ── LAYOUT ──────────────────────────────── */
    .page-wrapper { padding-top: var(--header-h); display: flex; min-height: 100vh; }
    .sidebar {
        width: var(--sidebar-w); background: var(--surface);
        border-right: 1px solid var(--border);
        position: sticky; top: var(--header-h);
        height: calc(100vh - var(--header-h));
        overflow-y: auto; padding: 1.5rem 1rem;
        flex-shrink: 0;
    }
    .sidebar-section-label {
        font-size: .7rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .1em; color: var(--text-light);
        padding: 0 .5rem; margin-bottom: .5rem; margin-top: 1.25rem;
    }
    .sidebar-section-label:first-child { margin-top: 0; }
    .sidebar a {
        display: flex; align-items: center; gap: .6rem;
        padding: .55rem .75rem; border-radius: var(--radius-sm);
        font-size: .86rem; font-weight: 500; color: var(--text-muted);
        text-decoration: none; transition: all var(--transition);
        margin-bottom: .1rem;
    }
    .sidebar a:hover { background: var(--surface-2); color: var(--text); }
    .sidebar a.active { background: #eff4ff; color: var(--navy-mid); font-weight: 600; }
    .sidebar a i { width: 16px; text-align: center; font-size: .85rem; }

    .main-content { flex: 1; padding: 2rem 2.5rem; min-width: 0; }

    /* ── HERO ────────────────────────────────── */
    .company-hero {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); overflow: hidden;
        box-shadow: var(--shadow); margin-bottom: 1.75rem;
    }
    .hero-banner {
        height: 160px;
        background: linear-gradient(135deg, var(--navy) 0%, var(--navy-mid) 100%);
        background-size: cover; background-position: center;
        position: relative;
    }
    .hero-body { padding: 1.5rem 1.75rem; display: flex; align-items: flex-end; gap: 1.25rem; flex-wrap: wrap; }
    .hero-logo-wrap {
        margin-top: -48px; flex-shrink: 0;
        width: 80px; height: 80px; border-radius: 50%;
        background: var(--surface); border: 3px solid var(--surface);
        box-shadow: 0 2px 10px rgba(0,0,0,.12); overflow: hidden;
        display: flex; align-items: center; justify-content: center;
    }
    .hero-logo-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .hero-logo-placeholder {
        width: 100%; height: 100%;
        background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
        display: flex; align-items: center; justify-content: center;
        font-family: 'DM Serif Display', serif; font-size: 2rem; color: #fff;
    }
    .hero-info { flex: 1; min-width: 200px; }
    .hero-name {
        font-family: 'DM Serif Display', serif; font-size: 1.65rem;
        color: var(--navy); line-height: 1.2; margin-bottom: .4rem;
    }
    .hero-meta { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
    .badge {
        display: inline-flex; align-items: center; gap: .35rem;
        padding: .2rem .65rem; border-radius: 99px;
        font-size: .76rem; font-weight: 600; border: 1px solid transparent;
    }
    .badge-draft     { background: #fffbeb; color: #b45309; border-color: #fcd34d; }
    .badge-pending   { background: #eef2ff; color: #1e4bd2; border-color: #a5c9ff; }
    .badge-active    { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .badge-suspended { background: var(--error-bg); color: var(--error); border-color: #fecaca; }
    .badge-verified  { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .badge-role      { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
    .hero-actions { display: flex; gap: .6rem; flex-wrap: wrap; margin-left: auto; align-items: flex-end; }

    /* ── ALERT ───────────────────────────────── */
    .alert {
        display: flex; align-items: flex-start; gap: .75rem;
        padding: .9rem 1.1rem; border-radius: var(--radius-sm);
        margin-bottom: 1.5rem; font-size: .88rem; font-weight: 500;
        border: 1px solid transparent;
    }
    .alert i { flex-shrink: 0; margin-top: .05rem; }
    .alert-success { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .alert-warning { background: var(--amber-light); color: #78350f; border-color: var(--amber); }
    .alert-info    { background: #eff4ff; color: var(--navy-mid); border-color: #c7d9f8; }

    /* ── GRID ────────────────────────────────── */
    .dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; margin-bottom: 1.75rem; }
    .dash-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 1.25rem; margin-bottom: 1.75rem; }

    /* ── CARDS ───────────────────────────────── */
    .card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow); overflow: hidden;
    }
    .card-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 1rem 1.25rem; border-bottom: 1px solid var(--border);
    }
    .card-title {
        display: flex; align-items: center; gap: .5rem;
        font-size: .88rem; font-weight: 700; color: var(--navy);
        text-transform: uppercase; letter-spacing: .06em;
    }
    .card-title i { color: var(--navy-light); }
    .card-body { padding: 1.25rem; }
    .card-link {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .82rem; font-weight: 600; color: var(--navy-light);
        text-decoration: none; transition: color var(--transition);
    }
    .card-link:hover { color: var(--navy); }

    /* Stat card */
    .stat-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow);
        padding: 1.25rem; display: flex; flex-direction: column; gap: .35rem;
    }
    .stat-card-icon {
        width: 38px; height: 38px; border-radius: var(--radius-sm);
        background: #eff4ff; display: flex; align-items: center; justify-content: center;
        color: var(--navy-light); font-size: 1rem; margin-bottom: .25rem;
    }
    .stat-card-value { font-size: 1.6rem; font-weight: 700; color: var(--navy); line-height: 1; }
    .stat-card-label { font-size: .78rem; color: var(--text-muted); font-weight: 500; }

    /* Module link card */
    .module-card {
        background: var(--surface); border: 1.5px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow);
        padding: 1.5rem; text-decoration: none; color: inherit;
        display: flex; flex-direction: column; gap: .75rem;
        transition: all var(--transition); position: relative; overflow: hidden;
    }
    .module-card:hover { border-color: var(--navy-light); transform: translateY(-2px); box-shadow: 0 8px 24px rgba(11,37,69,.1); }
    .module-card-icon {
        width: 44px; height: 44px; border-radius: var(--radius-sm);
        background: var(--navy); display: flex; align-items: center; justify-content: center;
        color: var(--amber); font-size: 1.15rem;
    }
    .module-card-title { font-size: 1rem; font-weight: 600; color: var(--navy); }
    .module-card-desc  { font-size: .82rem; color: var(--text-muted); line-height: 1.5; }
    .module-card-count {
        display: inline-flex; align-items: center; gap: .3rem;
        font-size: .78rem; font-weight: 600; color: var(--navy-light);
        background: #eff4ff; padding: .2rem .6rem; border-radius: 99px; border: 1px solid #c7d9f8;
        margin-top: auto;
    }
    .module-card-arrow {
        position: absolute; right: 1.25rem; top: 50%; transform: translateY(-50%);
        color: var(--text-light); font-size: .85rem; transition: right var(--transition);
    }
    .module-card:hover .module-card-arrow { right: 1rem; color: var(--navy-light); }
    .module-card.locked { opacity: .6; pointer-events: none; }
    .module-card.locked::after {
        content: 'Verification required';
        position: absolute; inset: 0; background: rgba(248,249,251,.85);
        display: flex; align-items: center; justify-content: center;
        font-size: .8rem; font-weight: 600; color: var(--text-muted);
        backdrop-filter: blur(1px);
    }

    /* Campaign row */
    .campaign-row {
        display: flex; align-items: center; gap: 1rem; padding: .85rem 0;
        border-bottom: 1px solid var(--border); flex-wrap: wrap;
    }
    .campaign-row:last-child { border-bottom: none; }
    .campaign-type-icon {
        width: 36px; height: 36px; border-radius: var(--radius-sm);
        background: #eff4ff; display: flex; align-items: center; justify-content: center;
        color: var(--navy-light); font-size: .9rem; flex-shrink: 0;
    }
    .campaign-info { flex: 1; min-width: 150px; }
    .campaign-title { font-size: .9rem; font-weight: 600; color: var(--navy); margin-bottom: .15rem; }
    .campaign-sub   { font-size: .76rem; color: var(--text-muted); }
    .campaign-progress { flex: 1; min-width: 120px; }
    .progress-bar-wrap { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin-bottom: .3rem; }
    .progress-bar-fill { height: 100%; background: var(--amber); border-radius: 99px; }
    .progress-label { font-size: .73rem; color: var(--text-muted); }

    /* Campaign status badges */
    .cs-draft     { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }
    .cs-review    { background: #eef2ff; color: #1e4bd2; border-color: #a5c9ff; }
    .cs-approved  { background: #eff4ff; color: var(--navy-light); border-color: #c7d9f8; }
    .cs-open      { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .cs-funded    { background: var(--amber-light); color: #78350f; border-color: var(--amber); }
    .cs-success   { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .cs-failed    { background: var(--error-bg); color: var(--error); border-color: #fecaca; }
    .cs-cancelled { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
    .cs-suspended { background: var(--error-bg); color: var(--error); border-color: #fecaca; }

    /* KYC status row */
    .kyc-row { display: flex; align-items: center; justify-content: space-between; padding: .6rem 0; border-bottom: 1px solid var(--border); font-size: .85rem; }
    .kyc-row:last-child { border-bottom: none; }
    .kyc-doc  { color: var(--text-muted); }
    .kyc-tick { color: var(--green); } .kyc-cross { color: var(--text-light); }

    /* Activity log */
    .activity-item { display: flex; align-items: flex-start; gap: .75rem; padding: .6rem 0; border-bottom: 1px solid var(--border); }
    .activity-item:last-child { border-bottom: none; }
    .activity-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--navy-light); margin-top: .35rem; flex-shrink: 0; }
    .activity-text { font-size: .83rem; color: var(--text); line-height: 1.4; }
    .activity-meta { font-size: .75rem; color: var(--text-light); margin-top: .1rem; }

    /* Buttons */
    .btn { display: inline-flex; align-items: center; gap: .45rem; padding: .6rem 1.25rem; border-radius: 99px; font-family: 'DM Sans', sans-serif; font-size: .85rem; font-weight: 600; cursor: pointer; transition: all var(--transition); border: none; text-decoration: none; white-space: nowrap; }
    .btn-primary { background: var(--navy-mid); color: #fff; box-shadow: 0 2px 8px rgba(15,59,122,.2); }
    .btn-primary:hover { background: var(--navy); transform: translateY(-1px); }
    .btn-outline { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border); }
    .btn-outline:hover { border-color: #94a3b8; color: var(--text); background: #f8fafc; }
    .btn-amber { background: var(--amber); color: var(--navy); }
    .btn-amber:hover { background: var(--amber-dark); color: #fff; }
    .btn-sm { padding: .4rem .9rem; font-size: .8rem; }

    /* Info rows */
    .info-row { display: flex; gap: .5rem; padding: .55rem 0; border-bottom: 1px solid var(--border); font-size: .85rem; }
    .info-row:last-child { border-bottom: none; }
    .info-label { color: var(--text-muted); min-width: 130px; flex-shrink: 0; }
    .info-value { color: var(--text); font-weight: 500; word-break: break-word; }

    /* Highlights chips */
    .highlights-wrap { display: flex; flex-wrap: wrap; gap: .5rem; }
    .highlight-chip {
        display: inline-flex; flex-direction: column; align-items: flex-start;
        background: #eff4ff; border: 1px solid #c7d9f8; border-radius: var(--radius-sm);
        padding: .5rem .85rem;
    }
    .highlight-chip-val   { font-size: .95rem; font-weight: 700; color: var(--navy-mid); line-height: 1.1; }
    .highlight-chip-label { font-size: .72rem; color: var(--text-muted); margin-top: .15rem; }

    /* Empty */
    .empty-inline { padding: 1.25rem; text-align: center; font-size: .85rem; color: var(--text-light); }

    /* ── RESPONSIVE ──────────────────────────── */
    @media (max-width: 1024px) {
        .dash-grid-3 { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 900px) {
        .sidebar { display: none; }
        .main-content { padding: 1.25rem; }
        .dash-grid { grid-template-columns: 1fr; }
        .dash-grid-3 { grid-template-columns: 1fr 1fr; }
    }
    @media (max-width: 600px) {
        .dash-grid-3 { grid-template-columns: 1fr; }
        .hero-body { flex-direction: column; align-items: flex-start; }
        .hero-actions { margin-left: 0; }
    }
    ::-webkit-scrollbar { width: 5px; }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
    </style>
</head>
<body>

<!-- ═══════ HEADER ═══════ -->
<header class="top-header">
    <a href="/company/" class="header-brand">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/user/"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="/company/" class="active"><i class="fa-solid fa-building"></i> Companies</a>
        <a href="/user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="header-right">
        <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
    </div>
</header>

<div class="page-wrapper">

    <!-- ═══════ SIDEBAR ═══════ -->
    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="dashboard.php?uuid=<?php echo urlencode($uuid); ?>" class="active">
            <i class="fa-solid fa-gauge"></i> Overview
        </a>
        <?php if ($canEdit): ?>
        <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>">
            <i class="fa-solid fa-pen-to-square"></i> Edit Profile
        </a>
        <?php endif; ?>

        <div class="sidebar-section-label">Content</div>
        <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>">
            <i class="fa-solid fa-chart-bar"></i> Financials
        </a>
        <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>">
            <i class="fa-solid fa-trophy"></i> Milestones
        </a>

        <div class="sidebar-section-label">Fundraising</div>
        <a href="campaigns/index.php?uuid=<?php echo urlencode($uuid); ?>">
            <i class="fa-solid fa-rocket"></i> Campaigns
        </a>

        <div class="sidebar-section-label">Team</div>
        <a href="manage_admins.php?uuid=<?php echo urlencode($uuid); ?>">
            <i class="fa-solid fa-users"></i> Manage Team
        </a>

        <div class="sidebar-section-label">Account</div>
        <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <!-- ═══════ MAIN ═══════ -->
    <main class="main-content">

        <?php if ($submitted): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div>Your company has been submitted for verification. The Old Union team will review your documents within 1–3 business days.</div>
            </div>
        <?php endif; ?>

        <?php if ($company['status'] === 'draft' && $canEdit): ?>
            <div class="alert alert-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>
                    Your company profile is incomplete.
                    <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>" style="font-weight:700;color:inherit;margin-left:.25rem;">Complete your profile →</a>
                </div>
            </div>
        <?php elseif ($company['status'] === 'pending_verification'): ?>
            <div class="alert alert-info">
                <i class="fa-solid fa-clock"></i>
                <div>Your documents are under review. We will notify you once verification is complete.</div>
            </div>
        <?php endif; ?>

        <!-- ── HERO ── -->
        <div class="company-hero">
            <div class="hero-banner" <?php if ($banner): ?>style="background-image:url('<?php echo $banner; ?>')"<?php endif; ?>></div>
            <div class="hero-body">
                <div class="hero-logo-wrap">
                    <?php if ($logo): ?>
                        <img src="<?php echo $logo; ?>" alt="<?php echo htmlspecialchars($company['name']); ?>">
                    <?php else: ?>
                        <div class="hero-logo-placeholder"><?php echo strtoupper(substr($company['name'], 0, 1)); ?></div>
                    <?php endif; ?>
                </div>
                <div class="hero-info">
                    <h1 class="hero-name"><?php echo htmlspecialchars($company['name']); ?></h1>
                    <div class="hero-meta">
                        <span class="badge <?php echo $statusInfo[1]; ?>">
                            <?php echo htmlspecialchars($statusInfo[0]); ?>
                        </span>
                        <?php if ($isVerified): ?>
                            <span class="badge badge-verified">
                                <i class="fa-solid fa-circle-check"></i> Verified
                            </span>
                        <?php endif; ?>
                        <span class="badge badge-role">
                            <i class="fa-solid fa-key"></i> <?php echo ucfirst($userRoleName); ?>
                        </span>
                        <?php if ($company['industry']): ?>
                            <span class="badge badge-role">
                                <i class="fa-solid fa-tag"></i> <?php echo htmlspecialchars($company['industry']); ?>
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="hero-actions">
                    <?php if ($canEdit): ?>
                        <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-outline btn-sm">
                            <i class="fa-solid fa-pen"></i> Edit Profile
                        </a>
                    <?php endif; ?>
                    <?php if ($canCampaign): ?>
                        <a href="campaigns/create.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-amber btn-sm">
                            <i class="fa-solid fa-rocket"></i> New Campaign
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ── STAT STRIP ── -->
        <div class="dash-grid-3" style="margin-bottom:1.75rem;">
            <div class="stat-card">
                <div class="stat-card-icon"><i class="fa-solid fa-chart-bar"></i></div>
                <div class="stat-card-value"><?php echo $financialsCount; ?></div>
                <div class="stat-card-label">Financial Reports</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon"><i class="fa-solid fa-trophy"></i></div>
                <div class="stat-card-value"><?php echo $milestonesCount; ?></div>
                <div class="stat-card-label">Milestones</div>
            </div>
            <div class="stat-card">
                <div class="stat-card-icon"><i class="fa-solid fa-rocket"></i></div>
                <div class="stat-card-value"><?php echo count($campaigns); ?></div>
                <div class="stat-card-label">Campaigns</div>
            </div>
        </div>

        <!-- ── MODULE CARDS ── -->
        <div class="dash-grid" style="margin-bottom:1.75rem;">
            <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>" class="module-card">
                <div class="module-card-icon"><i class="fa-solid fa-chart-bar"></i></div>
                <div class="module-card-title">Financials</div>
                <div class="module-card-desc">Self-reported monthly and annual P&L. Build contributor trust by keeping this up to date.</div>
                <span class="module-card-count"><i class="fa-solid fa-file-lines"></i> <?php echo $financialsCount; ?> report<?php echo $financialsCount !== 1 ? 's' : ''; ?></span>
                <i class="fa-solid fa-arrow-right module-card-arrow"></i>
            </a>
            <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>" class="module-card">
                <div class="module-card-icon"><i class="fa-solid fa-trophy"></i></div>
                <div class="module-card-title">Milestones</div>
                <div class="module-card-desc">Showcase your achievements on your public profile. Every milestone adds credibility.</div>
                <span class="module-card-count"><i class="fa-solid fa-flag"></i> <?php echo $milestonesCount; ?> milestone<?php echo $milestonesCount !== 1 ? 's' : ''; ?></span>
                <i class="fa-solid fa-arrow-right module-card-arrow"></i>
            </a>
            <a href="campaigns/index.php?uuid=<?php echo urlencode($uuid); ?>" class="module-card <?php echo !$canCampaign ? 'locked' : ''; ?>">
                <div class="module-card-icon"><i class="fa-solid fa-rocket"></i></div>
                <div class="module-card-title">Campaigns</div>
                <div class="module-card-desc">Create and manage your fundraising campaigns. Revenue share and cooperative membership available.</div>
                <span class="module-card-count"><i class="fa-solid fa-rocket"></i> <?php echo count($campaigns); ?> campaign<?php echo count($campaigns) !== 1 ? 's' : ''; ?></span>
                <i class="fa-solid fa-arrow-right module-card-arrow"></i>
            </a>
            <a href="manage_admins.php?uuid=<?php echo urlencode($uuid); ?>" class="module-card">
                <div class="module-card-icon"><i class="fa-solid fa-users"></i></div>
                <div class="module-card-title">Team</div>
                <div class="module-card-desc">Manage administrators. Invite editors, viewers, and co-owners to help run the company profile.</div>
                <span class="module-card-count"><i class="fa-solid fa-user"></i> <?php echo count($admins); ?> member<?php echo count($admins) !== 1 ? 's' : ''; ?></span>
                <i class="fa-solid fa-arrow-right module-card-arrow"></i>
            </a>
        </div>

        <div class="dash-grid">

            <!-- ── COMPANY INFO ── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fa-solid fa-building"></i> Company Info</span>
                    <?php if ($canEdit): ?>
                        <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>&step=1" class="card-link">
                            <i class="fa-solid fa-pen"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="info-row"><span class="info-label">Type</span><span class="info-value"><?php echo ucfirst(str_replace('_', ' ', $company['type'] ?? '')); ?></span></div>
                    <div class="info-row"><span class="info-label">Stage</span><span class="info-value"><?php echo htmlspecialchars($company['stage'] ?? '—'); ?></span></div>
                    <div class="info-row"><span class="info-label">Industry</span><span class="info-value"><?php echo htmlspecialchars($company['industry'] ?? '—'); ?></span></div>
                    <div class="info-row"><span class="info-label">Founded</span><span class="info-value"><?php echo htmlspecialchars($company['founded_year'] ?? '—'); ?></span></div>
                    <div class="info-row"><span class="info-label">Team Size</span><span class="info-value"><?php echo htmlspecialchars($company['employee_count'] ?? '—'); ?></span></div>
                    <div class="info-row"><span class="info-label">Reg. Number</span><span class="info-value"><?php echo htmlspecialchars($company['registration_number'] ?? '—'); ?></span></div>
                    <?php if ($location): ?>
                    <div class="info-row"><span class="info-label">Location</span><span class="info-value"><?php echo htmlspecialchars(implode(', ', array_filter([$location['suburb'], $location['city'], $location['province']]))); ?></span></div>
                    <div class="info-row"><span class="info-label">Area</span><span class="info-value"><?php echo htmlspecialchars(ucfirst($location['area'] ?? '')); ?></span></div>
                    <?php endif; ?>
                    <div class="info-row"><span class="info-label">Email</span><span class="info-value"><?php echo htmlspecialchars($company['email'] ?? '—'); ?></span></div>
                    <div class="info-row"><span class="info-label">Phone</span><span class="info-value"><?php echo htmlspecialchars($company['phone'] ?? '—'); ?></span></div>
                    <?php if ($company['website']): ?>
                    <div class="info-row"><span class="info-label">Website</span><span class="info-value"><a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank" style="color:var(--navy-light);"><?php echo htmlspecialchars($company['website']); ?></a></span></div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── KYC STATUS ── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fa-solid fa-shield-check"></i> Verification</span>
                    <?php if ($canEdit && in_array($company['status'], ['draft', 'pending_verification'])): ?>
                        <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>&step=7" class="card-link">
                            <i class="fa-solid fa-upload"></i> Upload Docs
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php
                    $docFields = [
                        'registration_document'  => 'Registration Certificate',
                        'proof_of_address'        => 'Proof of Address',
                        'director_id_document'    => 'Director ID',
                        'tax_clearance_document'  => 'Tax Clearance',
                    ];
                    foreach ($docFields as $field => $label):
                        $uploaded = $kyc && !empty($kyc[$field]);
                    ?>
                        <div class="kyc-row">
                            <span class="kyc-doc"><?php echo $label; ?></span>
                            <?php if ($uploaded): ?>
                                <i class="fa-solid fa-circle-check kyc-tick"></i>
                            <?php else: ?>
                                <i class="fa-regular fa-circle kyc-cross"></i>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>

                    <?php if ($kyc): ?>
                        <div style="margin-top:1rem;">
                            <?php
                            $kvcStatus = $kyc['verification_status'] ?? 'pending';
                            $kvcClass  = [
                                'pending'      => 'badge-pending',
                                'under_review' => 'badge-pending',
                                'approved'     => 'badge-active',
                                'rejected'     => 'badge-suspended',
                            ][$kvcStatus] ?? 'badge-role';
                            ?>
                            <span class="badge <?php echo $kvcClass; ?>">
                                KYC: <?php echo ucfirst(str_replace('_', ' ', $kvcStatus)); ?>
                            </span>
                            <?php if ($kvcStatus === 'rejected' && !empty($kyc['rejection_reason'])): ?>
                                <p style="font-size:.82rem;color:var(--error);margin-top:.5rem;">
                                    <?php echo htmlspecialchars($kyc['rejection_reason']); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── HIGHLIGHTS ── -->
            <?php if (!empty($highlights) || !empty($pitch)): ?>
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fa-solid fa-star"></i> Highlights</span>
                    <?php if ($canEdit): ?>
                        <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>&step=5" class="card-link">
                            <i class="fa-solid fa-pen"></i> Edit
                        </a>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($highlights)): ?>
                        <div class="highlights-wrap">
                            <?php foreach ($highlights as $hl): ?>
                                <div class="highlight-chip">
                                    <span class="highlight-chip-val"><?php echo htmlspecialchars($hl['value']); ?></span>
                                    <span class="highlight-chip-label"><?php echo htmlspecialchars($hl['label']); ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <div class="empty-inline">No highlights added yet.</div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- ── CAMPAIGNS ── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fa-solid fa-rocket"></i> Campaigns</span>
                    <a href="campaigns/index.php?uuid=<?php echo urlencode($uuid); ?>" class="card-link">
                        View all <i class="fa-solid fa-arrow-right"></i>
                    </a>
                </div>
                <div class="card-body">
                    <?php if (empty($campaigns)): ?>
                        <div class="empty-inline">No campaigns yet.</div>
                    <?php else: ?>
                        <?php foreach (array_slice($campaigns, 0, 3) as $c):
                            $cStatusInfo = $campaignStatusLabels[$c['status']] ?? ['Unknown', 'cs-draft'];
                            $cTypeInfo   = $campaignTypeLabels[$c['campaign_type']] ?? ['Campaign', 'fa-rocket'];
                            $pct = $c['raise_target'] > 0
                                ? min(100, round(($c['total_raised'] / $c['raise_target']) * 100))
                                : 0;
                        ?>
                            <div class="campaign-row">
                                <div class="campaign-type-icon">
                                    <i class="fa-solid <?php echo $cTypeInfo[1]; ?>"></i>
                                </div>
                                <div class="campaign-info">
                                    <div class="campaign-title"><?php echo htmlspecialchars($c['title']); ?></div>
                                    <div class="campaign-sub">
                                        <span class="badge <?php echo $cStatusInfo[1]; ?>" style="font-size:.7rem;">
                                            <?php echo $cStatusInfo[0]; ?>
                                        </span>
                                    </div>
                                </div>
                                <div class="campaign-progress">
                                    <div class="progress-bar-wrap">
                                        <div class="progress-bar-fill" style="width:<?php echo $pct; ?>%"></div>
                                    </div>
                                    <div class="progress-label">
                                        <?php echo fmtMoney($c['total_raised']); ?> of <?php echo fmtMoney($c['raise_target']); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- ── RECENT ACTIVITY ── -->
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Activity</span>
                </div>
                <div class="card-body">
                    <?php if (empty($activities)): ?>
                        <div class="empty-inline">No activity yet.</div>
                    <?php else: ?>
                        <?php foreach ($activities as $log): ?>
                            <div class="activity-item">
                                <div class="activity-dot"></div>
                                <div>
                                    <div class="activity-text"><?php echo htmlspecialchars($log['action']); ?></div>
                                    <div class="activity-meta">
                                        <?php echo htmlspecialchars($log['email']); ?> &middot;
                                        <?php echo date('d M Y H:i', strtotime($log['created_at'])); ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </main>
</div>
</body>
</html>
