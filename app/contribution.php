<?php
require_once 'includes/security.php';
require_once 'includes/session.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/InvestmentService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/investor/'));
}

$userId         = (int)$_SESSION['user_id'];
$contributionId = (int)($_GET['id'] ?? 0);

if ($contributionId <= 0) {
    redirect('/app/');
}

$contribution = InvestmentService::getContributionDetail($contributionId, $userId);
if (!$contribution) {
    redirect('/app/');
}

$pdo        = Database::getInstance();
$campaignId = null;

/* ── Get campaign id from contribution ───────── */
$stmt = $pdo->prepare("SELECT campaign_id FROM contributions WHERE id = ?");
$stmt->execute([$contributionId]);
$conRow     = $stmt->fetch();
$campaignId = $conRow ? (int)$conRow['campaign_id'] : 0;

/* ── Payout history for this contribution ────── */
$stmt = $pdo->prepare("
    SELECT
        pl.id, pl.amount, pl.period_year, pl.period_month,
        pl.payment_method, pl.payment_reference,
        pl.status, pl.paid_at, pl.created_at
    FROM payout_ledger pl
    WHERE pl.contribution_id = :cid
    ORDER BY pl.period_year DESC, pl.period_month DESC
");
$stmt->execute(['cid' => $contributionId]);
$payouts = $stmt->fetchAll();

/* ── Total received for this contribution ────── */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payout_ledger
    WHERE contribution_id = :cid AND status = 'paid'
");
$stmt->execute(['cid' => $contributionId]);
$totalReceivedThisContribution = (float)$stmt->fetchColumn();

/* ── Campaign updates (contributor sees all) ─── */
$updates = [];
if ($campaignId > 0) {
    $stmt = $pdo->prepare("
        SELECT
            cu.id, cu.update_type, cu.title, cu.body,
            cu.period_year, cu.period_month,
            cu.revenue_this_period, cu.expenses_this_period,
            cu.payout_amount, cu.payout_per_contributor,
            cu.is_public, cu.published_at, cu.created_at
        FROM campaign_updates cu
        WHERE cu.campaign_id = :cid
          AND cu.published_at IS NOT NULL
        ORDER BY cu.published_at DESC
        LIMIT 20
    ");
    $stmt->execute(['cid' => $campaignId]);
    $updates = $stmt->fetchAll();
}

/* ── User info ───────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

/* ── Helpers ─────────────────────────────────── */
function fmtR($v) {
    if ($v === null || $v === '') return '—';
    return 'R ' . number_format((float)$v, 2, '.', ' ');
}
function fmtDate($v) {
    return $v ? date('d M Y', strtotime($v)) : '—';
}
function fmtDateTime($v) {
    return $v ? date('d M Y, H:i', strtotime($v)) : '—';
}

$monthNames = [
    1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
    7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec',
];

$statusConfig = [
    'pending_payment' => ['Awaiting EFT Payment', 'sc-pending',   'fa-clock'],
    'paid'            => ['Payment Confirmed',     'sc-paid',      'fa-circle-check'],
    'under_review'    => ['Under Review',          'sc-review',    'fa-magnifying-glass'],
    'active'          => ['Active',                'sc-active',    'fa-rocket'],
    'refunded'        => ['Refunded',              'sc-refunded',  'fa-rotate-left'],
    'defaulted'       => ['Defaulted',             'sc-defaulted', 'fa-triangle-exclamation'],
    'completed'       => ['Completed',             'sc-completed', 'fa-trophy'],
];

$campaignStatusConfig = [
    'open'                => ['Open',               'csc-open'],
    'funded'              => ['Funded',             'csc-funded'],
    'closed_successful'   => ['Closed — Success',  'csc-success'],
    'closed_unsuccessful' => ['Closed — Failed',   'csc-failed'],
    'cancelled'           => ['Cancelled',          'csc-cancelled'],
    'approved'            => ['Approved',           'csc-open'],
    'under_review'        => ['Under Review',       'csc-review'],
    'draft'               => ['Draft',              'csc-draft'],
    'suspended'           => ['Suspended',          'csc-cancelled'],
];

$payoutStatusConfig = [
    'pending'    => ['Pending',    'ps-pending'],
    'processing' => ['Processing', 'ps-processing'],
    'paid'       => ['Paid',       'ps-paid'],
    'failed'     => ['Failed',     'ps-failed'],
];

$updateTypeConfig = [
    'general'        => ['Update',           'fa-newspaper',         'ut-general'],
    'financial'      => ['Financial Report', 'fa-chart-bar',         'ut-financial'],
    'milestone'      => ['Milestone',        'fa-trophy',            'ut-milestone'],
    'payout'         => ['Payout',           'fa-coins',             'ut-payout'],
    'issue'          => ['Issue Flagged',    'fa-triangle-exclamation', 'ut-issue'],
    'campaign_closed'=> ['Campaign Closed',  'fa-flag-checkered',    'ut-closed'],
];

$sInfo  = $statusConfig[$contribution['status']] ?? ['Unknown', 'sc-pending', 'fa-question'];
$csInfo = $campaignStatusConfig[$contribution['campaign_status']] ?? ['Unknown', 'csc-draft'];

// Campaign progress
$target = (float)$contribution['raise_target'];
$raised = (float)$contribution['total_raised'];
$pct    = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;

// Pro-rata
$proRata = $target > 0 ? (float)$contribution['amount'] / $target : 0;

// Payment method label
$pmLabels = [
    'platform_wallet' => ['Wallet',       'fa-wallet'],
    'eft'             => ['Bank EFT',     'fa-building-columns'],
    'instant_eft'     => ['Instant EFT', 'fa-bolt'],
    'card'            => ['Card',         'fa-credit-card'],
];
$pmInfo = $pmLabels[$contribution['payment_method']] ?? ['Unknown', 'fa-question'];

// Is EFT pending and needs bank details?
$showEftDetails = $contribution['status'] === 'pending_payment'
                  && in_array($contribution['payment_method'], ['eft', 'instant_eft'], true);

define('EFT_BANK_NAME',    defined('EFT_BANK')    ? EFT_BANK    : 'FNB');
define('EFT_ACCOUNT_NAME', defined('EFT_ACC_NAME') ? EFT_ACC_NAME : 'Old Union (Pty) Ltd');
define('EFT_ACCOUNT_NO',   defined('EFT_ACC_NO')   ? EFT_ACC_NO   : 'XXXXXXXXXX');
define('EFT_BRANCH_CODE',  defined('EFT_BRANCH')   ? EFT_BRANCH   : '250655');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($contribution['company_name']); ?> Investment | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
    /* Header */
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
    .header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;white-space:nowrap;}
    .header-brand span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}
    /* Layout */
    .page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
    .sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
    .sidebar-section-label:first-child{margin-top:0;}
    .sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
    .sidebar a:hover{background:var(--surface-2);color:var(--text);}
    .sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
    .sidebar a i{width:16px;text-align:center;font-size:.85rem;}
    .main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
    /* Breadcrumb */
    .breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:1.25rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
    .breadcrumb a{color:var(--navy-light);text-decoration:none;}
    .breadcrumb a:hover{color:var(--navy);}
    .breadcrumb i{font-size:.65rem;color:var(--text-light);}
    /* Grid */
    .detail-grid{display:grid;grid-template-columns:1fr 320px;gap:1.5rem;align-items:start;}
    /* Cards */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.25rem;}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border);}
    .card-title{display:flex;align-items:center;gap:.5rem;font-size:.83rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;}
    .card-title i{color:var(--navy-light);}
    .card-body{padding:1.1rem 1.25rem;}
    /* Hero strip */
    .hero-strip{background:var(--navy);border-radius:var(--radius);padding:1.5rem;display:flex;align-items:flex-start;gap:1.25rem;margin-bottom:1.5rem;box-shadow:var(--shadow-card);}
    .hero-logo{width:56px;height:56px;border-radius:10px;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:rgba(255,255,255,.1);}
    .hero-logo img{width:100%;height:100%;object-fit:cover;}
    .hero-logo-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.5rem;color:#fff;}
    .hero-info{flex:1;}
    .hero-company{font-size:.75rem;color:rgba(255,255,255,.55);font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:.2rem;}
    .hero-title{font-family:'DM Serif Display',serif;font-size:1.3rem;color:#fff;margin-bottom:.5rem;line-height:1.2;}
    .hero-badges{display:flex;flex-wrap:wrap;gap:.4rem;}
    .hero-amount{text-align:right;flex-shrink:0;}
    .hero-amount-val{font-family:'DM Serif Display',serif;font-size:1.75rem;color:#fff;line-height:1;}
    .hero-amount-label{font-size:.74rem;color:rgba(255,255,255,.5);margin-top:.2rem;}
    /* Badges */
    .badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .65rem;border-radius:99px;font-size:.73rem;font-weight:600;border:1px solid transparent;}
    .sc-pending  {background:rgba(241,245,249,.15);color:#cbd5e1;border-color:rgba(203,213,225,.3);}
    .sc-paid     {background:rgba(239,244,255,.15);color:#93c5fd;border-color:rgba(147,197,253,.3);}
    .sc-review   {background:rgba(254,243,199,.15);color:#fcd34d;border-color:rgba(252,211,77,.3);}
    .sc-active   {background:rgba(230,247,236,.15);color:#6ee7b7;border-color:rgba(110,231,183,.3);}
    .sc-refunded {background:rgba(241,245,249,.15);color:#94a3b8;border-color:rgba(148,163,184,.3);}
    .sc-defaulted{background:rgba(254,242,242,.15);color:#fca5a5;border-color:rgba(252,165,165,.3);}
    .sc-completed{background:rgba(230,247,236,.15);color:#6ee7b7;border-color:rgba(110,231,183,.3);}
    .csc-open    {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .csc-funded  {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .csc-success {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .csc-failed  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    .csc-cancelled{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    .csc-review  {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .csc-draft   {background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    /* for normal background */
    .badge-info  {background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
    .badge-muted {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .ps-pending   {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
    .ps-processing{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .ps-paid      {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .ps-failed    {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    /* Update type badges */
    .ut-general  {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .ut-financial{background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
    .ut-milestone{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .ut-payout   {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .ut-issue    {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    .ut-closed   {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    /* Review rows */
    .review-row{display:flex;align-items:baseline;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--border);gap:.75rem;font-size:.85rem;}
    .review-row:last-child{border-bottom:none;}
    .review-lbl{color:var(--text-muted);flex-shrink:0;}
    .review-val{font-weight:600;color:var(--text);text-align:right;}
    .review-val.highlight{color:var(--navy-mid);font-size:.92rem;}
    .review-val.green{color:var(--green);}
    .review-val.amber{color:var(--amber-dark);}
    /* Campaign progress (sidebar) */
    .prog-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.35rem;}
    .prog-raised{font-size:1.1rem;font-weight:700;color:var(--navy);line-height:1;margin-bottom:.1rem;}
    .prog-of{font-size:.75rem;color:var(--text-light);margin-bottom:.55rem;}
    .prog-outer{height:7px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);margin-bottom:.35rem;}
    .prog-inner{height:100%;background:var(--amber);border-radius:99px;}
    .prog-inner.funded{background:var(--green);}
    .prog-stats{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-light);}
    /* EFT details table */
    .eft-table{width:100%;border-collapse:collapse;font-size:.84rem;}
    .eft-table td{padding:.55rem .65rem;border-bottom:1px solid var(--border);}
    .eft-table tr:last-child td{border-bottom:none;}
    .eft-table td:first-child{color:var(--text-muted);}
    .eft-table td:last-child{font-weight:600;color:var(--text);}
    .ref-display{background:var(--navy);color:#fff;border-radius:var(--radius-sm);padding:.85rem 1rem;text-align:center;margin-bottom:.75rem;}
    .ref-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.5);margin-bottom:.3rem;}
    .ref-code{font-family:monospace;font-size:1.25rem;font-weight:700;color:var(--amber);letter-spacing:.1em;}
    /* Payout history */
    .payout-hist-row{display:flex;align-items:center;justify-content:space-between;padding:.65rem 0;border-bottom:1px solid var(--border);gap:.75rem;flex-wrap:wrap;}
    .payout-hist-row:last-child{border-bottom:none;}
    .ph-period{font-size:.85rem;font-weight:600;color:var(--text);}
    .ph-method{font-size:.73rem;color:var(--text-light);}
    .ph-right{display:flex;align-items:center;gap:.6rem;flex-shrink:0;}
    .ph-amount{font-size:.92rem;font-weight:700;color:var(--green);}
    /* Updates feed */
    .update-item{padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
    .update-item:last-child{border-bottom:none;}
    .update-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap;}
    .update-title-row{display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
    .update-title{font-size:.92rem;font-weight:600;color:var(--navy);}
    .update-date{font-size:.73rem;color:var(--text-light);flex-shrink:0;}
    .update-body{font-size:.85rem;color:var(--text-muted);line-height:1.65;margin-top:.35rem;white-space:pre-wrap;}
    .update-financials{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .9rem;margin-top:.75rem;display:flex;gap:1.25rem;flex-wrap:wrap;}
    .uf-item{display:flex;flex-direction:column;}
    .uf-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.15rem;}
    .uf-value{font-size:.95rem;font-weight:700;color:var(--navy);}
    .uf-value.payout-val{color:var(--green);}
    /* Alert */
    .alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-warning{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .alert-info{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
    /* Stat pills */
    .stat-pills{display:grid;grid-template-columns:1fr 1fr;gap:.75rem;margin-bottom:.75rem;}
    .stat-pill{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .85rem;}
    .sp-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.2rem;}
    .sp-value{font-size:1rem;font-weight:700;color:var(--navy);}
    .sp-value.green{color:var(--green);}
    /* Empty */
    .empty-section{text-align:center;padding:2rem 1.5rem;font-size:.86rem;color:var(--text-light);}
    .empty-section i{font-size:1.75rem;display:block;margin-bottom:.5rem;color:var(--border);}
    @media(max-width:1024px){.detail-grid{grid-template-columns:1fr;}}
    @media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}}
    @media(max-width:540px){.header-nav{display:none;}.hero-strip{flex-direction:column;}.hero-amount{text-align:left;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>

<header class="top-header">
    <a href="/app/" class="header-brand">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/app/" class="active"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Discover</a>
        <!--<a href="/app/company/"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($ini); ?></div>
</header>

<div class="page-wrapper">

    <aside class="sidebar">
        <div class="sidebar-section-label">Investor</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <a href="/app/?tab=watchlist"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">

        <div class="breadcrumb">
            <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
            <i class="fa-solid fa-chevron-right"></i>
            <?php echo htmlspecialchars($contribution['company_name']); ?>
        </div>

        <!-- ── Hero Strip ── -->
        <div class="hero-strip">
            <div class="hero-logo">
                <?php if ($contribution['company_logo']): ?>
                    <img src="<?php echo htmlspecialchars($contribution['company_logo']); ?>"
                         alt="<?php echo htmlspecialchars($contribution['company_name']); ?>">
                <?php else: ?>
                    <div class="hero-logo-ph"><?php echo strtoupper(substr($contribution['company_name'], 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div class="hero-info">
                <div class="hero-company"><?php echo htmlspecialchars($contribution['company_name']); ?></div>
                <div class="hero-title"><?php echo htmlspecialchars($contribution['campaign_title']); ?></div>
                <div class="hero-badges">
                    <span class="badge <?php echo $sInfo[1]; ?>">
                        <i class="fa-solid <?php echo $sInfo[2]; ?>"></i> <?php echo $sInfo[0]; ?>
                    </span>
                    <?php if ($contribution['campaign_status']): ?>
                    <span class="badge <?php echo $csInfo[1]; ?>">
                        Campaign: <?php echo $csInfo[0]; ?>
                    </span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="hero-amount">
                <div class="hero-amount-val"><?php echo fmtR($contribution['amount']); ?></div>
                <div class="hero-amount-label">invested</div>
            </div>
        </div>

        <?php if ($contribution['status'] === 'pending_payment'): ?>
        <div class="alert alert-warning">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>EFT payment pending.</strong>
                Your spot is reserved but your investment won't be active until we receive your EFT.
                Please use reference <strong><?php echo htmlspecialchars($contribution['payment_reference'] ?? ''); ?></strong>.
            </div>
        </div>
        <?php elseif ($contribution['status'] === 'refunded'): ?>
        <div class="alert alert-info">
            <i class="fa-solid fa-rotate-left"></i>
            <div>
                <strong>Investment refunded.</strong>
                <?php if ($contribution['refund_reason'] ?? ''): ?>
                    <?php echo htmlspecialchars($contribution['refund_reason']); ?>
                <?php else: ?>
                    The campaign did not reach its minimum raise target and your investment has been refunded.
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="detail-grid">

            <!-- ══ MAIN COLUMN ══ -->
            <div class="main-col">

                <!-- Investment Details -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-receipt"></i> Investment Details</span>
                    </div>
                    <div class="card-body">
                        <div class="review-row">
                            <span class="review-lbl">Amount Invested</span>
                            <span class="review-val highlight"><?php echo fmtR($contribution['amount']); ?></span>
                        </div>
                        <div class="review-row">
                            <span class="review-lbl">Status</span>
                            <span class="review-val"><?php echo $sInfo[0]; ?></span>
                        </div>
                        <div class="review-row">
                            <span class="review-lbl">Payment Method</span>
                            <span class="review-val">
                                <i class="fa-solid <?php echo $pmInfo[1]; ?>" style="margin-right:.3rem;color:var(--navy-light);"></i>
                                <?php echo $pmInfo[0]; ?>
                            </span>
                        </div>
                        <div class="review-row">
                            <span class="review-lbl">Reference</span>
                            <span class="review-val" style="font-family:monospace;font-size:.85rem;color:var(--navy-mid);">
                                <?php echo htmlspecialchars($contribution['payment_reference'] ?? '—'); ?>
                            </span>
                        </div>
                        <div class="review-row">
                            <span class="review-lbl">Date Invested</span>
                            <span class="review-val"><?php echo fmtDate($contribution['created_at']); ?></span>
                        </div>
                        <?php if ($contribution['paid_at']): ?>
                        <div class="review-row">
                            <span class="review-lbl">Payment Confirmed</span>
                            <span class="review-val green"><?php echo fmtDateTime($contribution['paid_at']); ?></span>
                        </div>
                        <?php endif; ?>
                        <?php if ($contribution['agreement_signed_at']): ?>
                        <div class="review-row">
                            <span class="review-lbl">Agreement Accepted</span>
                            <span class="review-val"><?php echo fmtDateTime($contribution['agreement_signed_at']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Deal Terms -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-file-contract"></i> Deal Terms</span>
                    </div>
                    <div class="card-body">
                        <?php if ($contribution['campaign_type'] === 'revenue_share'): ?>
                            <div class="review-row"><span class="review-lbl">Instrument</span><span class="review-val">Revenue Share Agreement</span></div>
                            <div class="review-row"><span class="review-lbl">Monthly Revenue Share</span><span class="review-val highlight"><?php echo htmlspecialchars((string)$contribution['revenue_share_percentage']); ?>% of reported revenue</span></div>
                            <div class="review-row"><span class="review-lbl">Duration</span><span class="review-val highlight"><?php echo htmlspecialchars((string)$contribution['revenue_share_duration_months']); ?> months</span></div>
                            <div class="review-row">
                                <span class="review-lbl">Your Pro-Rata Share</span>
                                <span class="review-val"><?php echo number_format($proRata * 100, 4); ?>% of the raise</span>
                            </div>
                            <div class="review-row">
                                <span class="review-lbl">Your Monthly Entitlement</span>
                                <span class="review-val highlight"><?php echo number_format($proRata * (float)$contribution['revenue_share_percentage'], 5); ?>% of monthly revenue</span>
                            </div>
                        <?php elseif ($contribution['campaign_type'] === 'cooperative_membership'): ?>
                            <div class="review-row"><span class="review-lbl">Instrument</span><span class="review-val">Cooperative Membership</span></div>
                            <div class="review-row"><span class="review-lbl">Unit Name</span><span class="review-val highlight"><?php echo htmlspecialchars($contribution['unit_name'] ?? '—'); ?></span></div>
                            <div class="review-row"><span class="review-lbl">Price Per Unit</span><span class="review-val"><?php echo fmtR($contribution['unit_price']); ?></span></div>
                            <?php if ($contribution['unit_price'] && (float)$contribution['unit_price'] > 0): ?>
                            <div class="review-row">
                                <span class="review-lbl">Units Held</span>
                                <span class="review-val highlight"><?php echo number_format((float)$contribution['amount'] / (float)$contribution['unit_price'], 2); ?></span>
                            </div>
                            <?php endif; ?>
                        <?php endif; ?>
                        <div class="review-row">
                            <span class="review-lbl">Governing Law</span>
                            <span class="review-val"><?php echo htmlspecialchars($contribution['governing_law'] ?? 'Republic of South Africa'); ?></span>
                        </div>
                        <div class="review-row">
                            <span class="review-lbl">Campaign Closes</span>
                            <span class="review-val"><?php echo fmtDate($contribution['closes_at']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- EFT Payment Details (if pending) -->
                <?php if ($showEftDetails): ?>
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-building-columns"></i> EFT Payment Details</span>
                    </div>
                    <div class="card-body">
                        <div class="ref-display">
                            <div class="ref-label">Your Unique Reference</div>
                            <div class="ref-code"><?php echo htmlspecialchars($contribution['payment_reference'] ?? ''); ?></div>
                        </div>
                        <table class="eft-table">
                            <tr><td>Bank</td><td><?php echo htmlspecialchars(EFT_BANK_NAME); ?></td></tr>
                            <tr><td>Account Name</td><td><?php echo htmlspecialchars(EFT_ACCOUNT_NAME); ?></td></tr>
                            <tr><td>Account Number</td><td><?php echo htmlspecialchars(EFT_ACCOUNT_NO); ?></td></tr>
                            <tr><td>Branch Code</td><td><?php echo htmlspecialchars(EFT_BRANCH_CODE); ?></td></tr>
                            <tr><td>Amount</td><td><strong><?php echo fmtR($contribution['amount']); ?></strong></td></tr>
                            <tr><td>Reference</td><td><strong style="color:var(--navy-mid);"><?php echo htmlspecialchars($contribution['payment_reference'] ?? ''); ?></strong></td></tr>
                        </table>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payout History -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-coins"></i> Payout History</span>
                        <?php if (!empty($payouts)): ?>
                        <span style="font-size:.78rem;color:var(--text-light);">
                            <?php echo count($payouts); ?> payout<?php echo count($payouts) !== 1 ? 's' : ''; ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <?php if (empty($payouts)): ?>
                        <div class="empty-section">
                            <i class="fa-solid fa-coins"></i>
                            No payouts yet. Returns will appear here once the company begins distributing.
                        </div>
                    <?php else: ?>
                        <div style="padding:.25rem 1.25rem;">
                            <?php foreach ($payouts as $pay):
                                $psInfo  = $payoutStatusConfig[$pay['status']] ?? ['Unknown', 'ps-pending'];
                                $period  = ($monthNames[(int)$pay['period_month']] ?? '') . ' ' . $pay['period_year'];
                            ?>
                                <div class="payout-hist-row">
                                    <div>
                                        <div class="ph-period"><?php echo htmlspecialchars($period); ?></div>
                                        <div class="ph-method">
                                            <?php echo $pay['paid_at'] ? fmtDate($pay['paid_at']) : 'Pending'; ?>
                                            <?php if ($pay['payment_reference']): ?>
                                                &nbsp;·&nbsp; <span style="font-family:monospace;font-size:.72rem;"><?php echo htmlspecialchars($pay['payment_reference']); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                    <div class="ph-right">
                                        <span class="badge <?php echo $psInfo[1]; ?>"><?php echo $psInfo[0]; ?></span>
                                        <span class="ph-amount">+<?php echo fmtR($pay['amount']); ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Campaign Updates Feed -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-newspaper"></i> Campaign Updates</span>
                    </div>
                    <?php if (empty($updates)): ?>
                        <div class="empty-section">
                            <i class="fa-solid fa-newspaper"></i>
                            No updates posted yet. The company will post updates here as the campaign progresses.
                        </div>
                    <?php else: ?>
                        <?php foreach ($updates as $upd):
                            $utInfo = $updateTypeConfig[$upd['update_type']] ?? ['Update', 'fa-newspaper', 'ut-general'];
                            $period = '';
                            if ($upd['period_month'] && $upd['period_year']) {
                                $period = ($monthNames[(int)$upd['period_month']] ?? '') . ' ' . $upd['period_year'];
                            }
                        ?>
                            <div class="update-item">
                                <div class="update-head">
                                    <div>
                                        <div class="update-title-row">
                                            <span class="badge <?php echo $utInfo[2]; ?>">
                                                <i class="fa-solid <?php echo $utInfo[1]; ?>"></i>
                                                <?php echo $utInfo[0]; ?>
                                            </span>
                                            <?php if ($period): ?>
                                                <span class="badge badge-muted"><?php echo htmlspecialchars($period); ?></span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="update-title" style="margin-top:.35rem;"><?php echo htmlspecialchars($upd['title']); ?></div>
                                    </div>
                                    <div class="update-date"><?php echo fmtDate($upd['published_at'] ?? $upd['created_at']); ?></div>
                                </div>
                                <div class="update-body"><?php echo htmlspecialchars($upd['body']); ?></div>

                                <?php
                                $hasFinancials = $upd['revenue_this_period'] !== null
                                              || $upd['expenses_this_period'] !== null
                                              || $upd['payout_amount'] !== null;
                                if ($hasFinancials): ?>
                                <div class="update-financials">
                                    <?php if ($upd['revenue_this_period'] !== null): ?>
                                    <div class="uf-item">
                                        <span class="uf-label">Revenue</span>
                                        <span class="uf-value"><?php echo fmtR($upd['revenue_this_period']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($upd['expenses_this_period'] !== null): ?>
                                    <div class="uf-item">
                                        <span class="uf-label">Expenses</span>
                                        <span class="uf-value"><?php echo fmtR($upd['expenses_this_period']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($upd['payout_amount'] !== null): ?>
                                    <div class="uf-item">
                                        <span class="uf-label">Total Payout</span>
                                        <span class="uf-value payout-val"><?php echo fmtR($upd['payout_amount']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($upd['payout_per_contributor'] !== null): ?>
                                    <div class="uf-item">
                                        <span class="uf-label">Your Share</span>
                                        <span class="uf-value payout-val"><?php echo fmtR($upd['payout_per_contributor']); ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

            </div>

            <!-- ══ SIDEBAR ══ -->
            <div class="sidebar-col">

                <!-- Return Summary -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-chart-line"></i> Return Summary</span>
                    </div>
                    <div class="card-body">
                        <div class="stat-pills">
                            <div class="stat-pill">
                                <div class="sp-label">Invested</div>
                                <div class="sp-value"><?php echo fmtR($contribution['amount']); ?></div>
                            </div>
                            <div class="stat-pill">
                                <div class="sp-label">Received</div>
                                <div class="sp-value green"><?php echo fmtR($totalReceivedThisContribution); ?></div>
                            </div>
                        </div>
                        <?php if ((float)$contribution['amount'] > 0): ?>
                        <div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .85rem;font-size:.82rem;color:var(--text-muted);">
                            <?php
                            $roi = (($totalReceivedThisContribution - (float)$contribution['amount']) / (float)$contribution['amount']) * 100;
                            ?>
                            Return so far:
                            <strong style="color:<?php echo $roi >= 0 ? 'var(--green)' : 'var(--error)'; ?>;">
                                <?php echo ($roi >= 0 ? '+' : '') . number_format($roi, 2); ?>%
                            </strong>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Campaign Progress -->
                <div class="card">
                    <div class="card-header">
                        <span class="card-title"><i class="fa-solid fa-rocket"></i> Campaign Progress</span>
                    </div>
                    <div class="card-body">
                        <div class="prog-label">Total Raised</div>
                        <div class="prog-raised"><?php echo fmtR($raised); ?></div>
                        <div class="prog-of">of <?php echo fmtR($target); ?> target</div>
                        <div class="prog-outer">
                            <div class="prog-inner <?php echo in_array($contribution['campaign_status'], ['funded','closed_successful'], true) ? 'funded' : ''; ?>"
                                 style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="prog-stats">
                            <span><?php echo $pct; ?>% funded</span>
                            <span><?php echo fmtDate($contribution['closes_at']); ?></span>
                        </div>
                    </div>
                </div>

                <!-- Links -->
                <div class="card">
                    <div class="card-body" style="display:flex;flex-direction:column;gap:.5rem;">
                        <a href="/browse/company.php?uuid=<?php echo urlencode($contribution['company_uuid']); ?>"
                           style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:600;color:var(--navy-mid);text-decoration:none;padding:.5rem .6rem;border-radius:var(--radius-sm);transition:background var(--transition);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            <i class="fa-solid fa-building"></i> View Company Profile
                        </a>
                        <a href="/investor/"
                           style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;font-weight:600;color:var(--text-muted);text-decoration:none;padding:.5rem .6rem;border-radius:var(--radius-sm);transition:background var(--transition);"
                           onmouseover="this.style.background='var(--surface-2)'" onmouseout="this.style.background='transparent'">
                            <i class="fa-solid fa-arrow-left"></i> Back to Portfolio
                        </a>
                    </div>
                </div>

            </div>

        </div>
    </main>
</div>
</body>
</html>
