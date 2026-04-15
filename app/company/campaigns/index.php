<?php
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$uuid = $_GET['uuid'] ?? '';
if (empty($uuid)) { redirect('/app/company/'); }

$company = getCompanyByUuid($uuid);
if (!$company) { redirect('/app/company/'); }

requireCompanyRole($company['id'], 'viewer');

$canAdmin  = hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin');
$canEdit   = hasCompanyPermission($company['id'], $_SESSION['user_id'], 'editor');
$pdo       = Database::getInstance();
$userId    = $_SESSION['user_id'];
$companyId = $company['id'];

$isVerified  = (bool)$company['verified'];
$canCampaign = $isVerified && $company['status'] === 'active';

/* ── Load campaigns with terms ───────────────── */
$stmt = $pdo->prepare("
    SELECT
        fc.id, fc.uuid, fc.title, fc.tagline,
        fc.campaign_type, fc.status,
        fc.raise_target, fc.raise_minimum, fc.raise_maximum,
        fc.min_contribution, fc.max_contribution, fc.max_contributors,
        fc.total_raised, fc.contributor_count,
        fc.opens_at, fc.closes_at, fc.created_at,
        ct.revenue_share_percentage,
        ct.revenue_share_duration_months,
        ct.unit_name,
        ct.unit_price,
        ct.total_units_available
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    WHERE fc.company_id = :cid
    ORDER BY fc.created_at DESC
");
$stmt->execute(['cid' => $companyId]);
$campaigns = $stmt->fetchAll();

// Avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';

$submitted = isset($_GET['submitted']);

$statusConfig = [
    'draft'               => ['Draft',              'cs-draft',     'fa-pencil'],
    'under_review'        => ['Under Review',       'cs-review',    'fa-clock'],
    'approved'            => ['Approved',           'cs-approved',  'fa-circle-check'],
    'open'                => ['Open',               'cs-open',      'fa-door-open'],
    'funded'              => ['Funded',             'cs-funded',    'fa-star'],
    'closed_successful'   => ['Closed – Success',  'cs-success',   'fa-trophy'],
    'closed_unsuccessful' => ['Closed – Failed',   'cs-failed',    'fa-xmark-circle'],
    'cancelled'           => ['Cancelled',          'cs-cancelled', 'fa-ban'],
    'suspended'           => ['Suspended',          'cs-suspended', 'fa-lock'],
];

$typeConfig = [
    'revenue_share'          => ['Revenue Share',          'fa-chart-line',          'tc-rs'],
    'cooperative_membership' => ['Cooperative Membership', 'fa-people-roof',         'tc-co'],
    'fixed_return_loan'      => ['Fixed Return Loan',      'fa-hand-holding-dollar', 'tc-loan'],
    'donation'               => ['Donation',               'fa-heart',               'tc-don'],
    'convertible_note'       => ['Convertible Note',       'fa-rotate',              'tc-cn'],
];

function fmtMoney($v) {
    if ($v === null || $v === '') return '—';
    return 'R ' . number_format((float)$v, 0, '.', ' ');
}
function fmtDate($v) {
    if (!$v) return '—';
    return date('d M Y', strtotime($v));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns | <?php echo htmlspecialchars($company['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{
        --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
        --amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;
        --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
        --surface:#ffffff;--surface-2:#f8f9fb;--border:#e4e7ec;
        --text:#101828;--text-muted:#667085;--text-light:#98a2b3;
        --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
        --radius:14px;--radius-sm:8px;
        --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
        --header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.5rem;justify-content:space-between;z-index:100;gap:1rem;}
    .header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .header-brand span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.25rem;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .8rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;}
    .page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
    .sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
    .sidebar-section-label:first-child{margin-top:0;}
    .sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
    .sidebar a:hover{background:var(--surface-2);color:var(--text);}
    .sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
    .sidebar a i{width:16px;text-align:center;font-size:.85rem;}
    .main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
    .page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;}
    .page-head-left .breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:.35rem;}
    .page-head-left .breadcrumb a{color:var(--navy-light);text-decoration:none;}
    .page-head-left h1{font-family:'DM Serif Display',serif;font-size:1.7rem;color:var(--navy);}
    .alert{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.88rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .alert-warning{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.25rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;white-space:nowrap;}
    .btn-amber{background:var(--amber);color:var(--navy);}
    .btn-amber:hover{background:var(--amber-dark);color:#fff;}
    .btn-outline{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-outline:hover{border-color:#94a3b8;color:var(--text);background:#f8fafc;}
    .btn-primary{background:var(--navy-mid);color:#fff;}
    .btn-primary:hover{background:var(--navy);}
    .btn-sm{padding:.38rem .85rem;font-size:.78rem;}
    .btn-navy{background:var(--navy-mid);color:#fff;}
    .btn-navy:hover{background:var(--navy);}
    /* Campaign card */
    .campaign-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden;transition:box-shadow var(--transition),border-color var(--transition);}
    .campaign-card:hover{box-shadow:0 8px 24px rgba(11,37,69,.1);border-color:#c7d9f8;}
    .campaign-card-head{display:flex;align-items:flex-start;gap:1rem;padding:1.25rem 1.5rem;border-bottom:1px solid var(--border);flex-wrap:wrap;}
    .campaign-type-icon{width:44px;height:44px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
    .tc-rs{background:#eff4ff;color:var(--navy-light);}
    .tc-co{background:var(--green-bg);color:var(--green);}
    .tc-loan{background:var(--amber-light);color:#78350f;}
    .tc-don{background:#fce7f3;color:#9d174d;}
    .tc-cn{background:#f3e8ff;color:#6d28d9;}
    .campaign-card-meta{flex:1;min-width:180px;}
    .campaign-card-title{font-size:1.05rem;font-weight:600;color:var(--navy);margin-bottom:.25rem;}
    .campaign-card-tagline{font-size:.83rem;color:var(--text-muted);margin-bottom:.5rem;}
    .campaign-card-badges{display:flex;flex-wrap:wrap;gap:.4rem;}
    .campaign-card-actions{display:flex;align-items:center;gap:.5rem;flex-shrink:0;}
    .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.75rem;font-weight:600;border:1px solid transparent;}
    .cs-draft{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
    .cs-review{background:#eef2ff;color:#1e4bd2;border-color:#a5c9ff;}
    .cs-approved{background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
    .cs-open{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .cs-funded{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .cs-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .cs-failed{background:var(--error-bg);color:var(--error);border-color:#fecaca;}
    .cs-cancelled{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    .cs-suspended{background:var(--error-bg);color:var(--error);border-color:#fecaca;}
    .badge-type{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .campaign-card-body{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:0;padding:0;}
    .campaign-stat{padding:1rem 1.5rem;border-right:1px solid var(--border);}
    .campaign-stat:last-child{border-right:none;}
    .campaign-stat-label{font-size:.73rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.25rem;}
    .campaign-stat-value{font-size:1rem;font-weight:700;color:var(--navy);}
    .campaign-stat-sub{font-size:.75rem;color:var(--text-muted);margin-top:.1rem;}
    .progress-wrap{padding:1rem 1.5rem;border-top:1px solid var(--border);}
    .progress-bar-outer{height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;margin-bottom:.4rem;}
    .progress-bar-inner{height:100%;background:var(--amber);border-radius:99px;transition:width .5s ease;}
    .progress-bar-inner.funded{background:var(--green);}
    .progress-labels{display:flex;justify-content:space-between;font-size:.75rem;color:var(--text-muted);}
    .progress-labels strong{color:var(--navy);}
    /* Empty */
    .empty-state{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);text-align:center;padding:4rem 2rem;}
    .empty-state i{font-size:2.5rem;color:var(--border);margin-bottom:1rem;display:block;}
    .empty-state h2{font-family:'DM Serif Display',serif;font-size:1.5rem;color:var(--navy);margin-bottom:.5rem;}
    .empty-state p{font-size:.9rem;color:var(--text-muted);margin-bottom:1.5rem;}
    /* Lock banner */
    .lock-banner{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:1rem 1.25rem;display:flex;align-items:flex-start;gap:.75rem;font-size:.88rem;color:#78350f;margin-bottom:1.5rem;}
    .lock-banner i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}
    @media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}.campaign-card-body{grid-template-columns:1fr 1fr;}.campaign-stat{border-bottom:1px solid var(--border);}.campaign-stat:nth-child(even){border-right:none;}}
    @media(max-width:500px){.campaign-card-body{grid-template-columns:1fr;}.campaign-stat{border-right:none;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>
<header class="top-header">
    <a href="/company/" class="header-brand">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/user/"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="/company/" class="active"><i class="fa-solid fa-building"></i> Companies</a>
        <a href="/user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="../dashboard.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-gauge"></i> Overview</a>
        <div class="sidebar-section-label">Content</div>
        <a href="../financials.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-chart-bar"></i> Financials</a>
        <a href="../milestones.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-trophy"></i> Milestones</a>
        <div class="sidebar-section-label">Fundraising</div>
        <a href="index.php?uuid=<?php echo urlencode($uuid); ?>" class="active"><i class="fa-solid fa-rocket"></i> Campaigns</a>
        <div class="sidebar-section-label">Team</div>
        <a href="../manage_admins.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-users"></i> Manage Team</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">
        <div class="page-head">
            <div class="page-head-left">
                <div class="breadcrumb">
                    <a href="../dashboard.php?uuid=<?php echo urlencode($uuid); ?>"><?php echo htmlspecialchars($company['name']); ?></a>
                    &rsaquo; Campaigns
                </div>
                <h1>Campaigns</h1>
            </div>
            <?php if ($canCampaign && $canAdmin): ?>
                <a href="create.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-amber">
                    <i class="fa-solid fa-rocket"></i> New Campaign
                </a>
            <?php endif; ?>
        </div>

        <?php if ($submitted): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                Your campaign has been submitted for review. The Old Union team will assess it within 2–4 business days.
            </div>
        <?php endif; ?>

        <?php if (!$canCampaign): ?>
            <div class="lock-banner">
                <i class="fa-solid fa-lock"></i>
                <div>
                    <strong>Verification required.</strong>
                    Campaigns can only be created once your company is verified and active.
                    <?php if ($company['status'] === 'draft' || $company['status'] === 'pending_verification'): ?>
                        <a href="../wizard.php?uuid=<?php echo urlencode($uuid); ?>"
                           style="font-weight:700;color:inherit;margin-left:.25rem;">
                            Complete your profile →
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($campaigns)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-rocket"></i>
                <h2>No campaigns yet</h2>
                <p>
                    <?php if ($canCampaign): ?>
                        Create your first fundraising campaign to start raising capital from your community.
                    <?php else: ?>
                        Campaigns will be available once your company is verified.
                    <?php endif; ?>
                </p>
                <?php if ($canCampaign && $canAdmin): ?>
                    <a href="create.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-amber">
                        <i class="fa-solid fa-plus"></i> Create First Campaign
                    </a>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <?php foreach ($campaigns as $c):
                $sInfo = $statusConfig[$c['status']] ?? ['Unknown', 'cs-draft', 'fa-question'];
                $tInfo = $typeConfig[$c['campaign_type']] ?? ['Campaign', 'fa-rocket', 'tc-rs'];

                $target  = (float)$c['raise_target'];
                $raised  = (float)$c['total_raised'];
                $pct     = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;
                $isFunded = in_array($c['status'], ['funded', 'closed_successful'], true);
                $canEditCampaign   = $canAdmin && $c['status'] === 'draft';
                $canManageCampaign = $canEdit  && !in_array($c['status'], ['cancelled', 'suspended'], true);
            ?>
                <div class="campaign-card">

                    <div class="campaign-card-head">
                        <div class="campaign-type-icon <?php echo $tInfo[2]; ?>">
                            <i class="fa-solid <?php echo $tInfo[1]; ?>"></i>
                        </div>
                        <div class="campaign-card-meta">
                            <div class="campaign-card-title"><?php echo htmlspecialchars($c['title']); ?></div>
                            <?php if ($c['tagline']): ?>
                                <div class="campaign-card-tagline"><?php echo htmlspecialchars($c['tagline']); ?></div>
                            <?php endif; ?>
                            <div class="campaign-card-badges">
                                <span class="badge <?php echo $sInfo[1]; ?>">
                                    <i class="fa-solid <?php echo $sInfo[2]; ?>"></i>
                                    <?php echo $sInfo[0]; ?>
                                </span>
                                <span class="badge badge-type">
                                    <i class="fa-solid <?php echo $tInfo[1]; ?>"></i>
                                    <?php echo $tInfo[0]; ?>
                                </span>
                            </div>
                        </div>
                        <div class="campaign-card-actions">
                            <?php if ($canEditCampaign): ?>
                                <a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>&cid=<?php echo urlencode($c['uuid']); ?>"
                                   class="btn btn-outline btn-sm">
                                    <i class="fa-solid fa-pen"></i> Edit
                                </a>
                            <?php endif; ?>
                            <?php if ($canManageCampaign): ?>
                                <a href="manage.php?uuid=<?php echo urlencode($uuid); ?>&cid=<?php echo urlencode($c['uuid']); ?>"
                                   class="btn btn-navy btn-sm">
                                    <i class="fa-solid fa-sliders"></i> Manage
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="campaign-card-body">
                        <div class="campaign-stat">
                            <div class="campaign-stat-label">Raise Target</div>
                            <div class="campaign-stat-value"><?php echo fmtMoney($c['raise_target']); ?></div>
                            <div class="campaign-stat-sub">Min: <?php echo fmtMoney($c['raise_minimum']); ?></div>
                        </div>
                        <div class="campaign-stat">
                            <div class="campaign-stat-label">Contributors</div>
                            <div class="campaign-stat-value"><?php echo (int)$c['contributor_count']; ?></div>
                            <div class="campaign-stat-sub">of <?php echo (int)$c['max_contributors']; ?> max</div>
                        </div>
                        <div class="campaign-stat">
                            <div class="campaign-stat-label">Opens</div>
                            <div class="campaign-stat-value" style="font-size:.9rem;"><?php echo fmtDate($c['opens_at']); ?></div>
                            <div class="campaign-stat-sub">Closes <?php echo fmtDate($c['closes_at']); ?></div>
                        </div>
                        <?php if ($c['campaign_type'] === 'revenue_share' && $c['revenue_share_percentage']): ?>
                        <div class="campaign-stat">
                            <div class="campaign-stat-label">Revenue Share</div>
                            <div class="campaign-stat-value"><?php echo htmlspecialchars($c['revenue_share_percentage']); ?>%</div>
                            <div class="campaign-stat-sub">for <?php echo htmlspecialchars((string)$c['revenue_share_duration_months']); ?> months</div>
                        </div>
                        <?php elseif ($c['campaign_type'] === 'cooperative_membership' && $c['unit_name']): ?>
                        <div class="campaign-stat">
                            <div class="campaign-stat-label">Unit</div>
                            <div class="campaign-stat-value" style="font-size:.88rem;"><?php echo htmlspecialchars($c['unit_name']); ?></div>
                            <div class="campaign-stat-sub"><?php echo fmtMoney($c['unit_price']); ?> each &middot; <?php echo (int)$c['total_units_available']; ?> available</div>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if (in_array($c['status'], ['open','funded','closed_successful','closed_unsuccessful'], true)): ?>
                    <div class="progress-wrap">
                        <div class="progress-bar-outer">
                            <div class="progress-bar-inner <?php echo $isFunded ? 'funded' : ''; ?>"
                                 style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="progress-labels">
                            <span><strong><?php echo fmtMoney($raised); ?></strong> raised</span>
                            <span><?php echo $pct; ?>% of target</span>
                        </div>
                    </div>
                    <?php endif; ?>

                </div>
            <?php endforeach; ?>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
