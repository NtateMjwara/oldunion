<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InviteService.php'; // US-101

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/discover/'));
}

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance();

/* ── Filters ────────────────────────────────── */
$search      = trim($_GET['search']   ?? '');
$province    = trim($_GET['province'] ?? '');
$area        = trim($_GET['area']     ?? '');
$industry    = trim($_GET['industry'] ?? '');
$hasCampaign = isset($_GET['has_campaign']);

/* ── WHERE ──────────────────────────────────── */
$where  = ['c.status = :status', 'c.verified = :verified'];
$params = ['status' => 'active', 'verified' => 1];

if ($search !== '') {
    $where[]           = '(c.name LIKE :search OR c.description LIKE :search2)';
    $params['search']  = '%' . $search . '%';
    $params['search2'] = '%' . $search . '%';
}
if ($province !== '') { $where[] = 'cf.province = :province'; $params['province'] = $province; }
if ($area     !== '') { $where[] = 'cf.area = :area';         $params['area']     = $area; }
if ($industry !== '') { $where[] = 'c.industry = :industry';  $params['industry'] = $industry; }

$whereSQL = 'WHERE ' . implode(' AND ', $where);
if ($hasCampaign) {
    $whereSQL .= " AND EXISTS (SELECT 1 FROM funding_campaigns fc2 WHERE fc2.company_id = c.id AND fc2.status IN ('open','funded'))";
}

/* ── Company query ──────────────────────────── */
$stmt = $pdo->prepare("
    SELECT c.id, c.uuid, c.name, c.logo, c.banner,
           c.type, c.stage, c.industry, c.description,
           c.employee_count, c.founded_year,
           cf.province, cf.city, cf.area
    FROM companies c
    LEFT JOIN company_filter cf ON cf.company_id = c.id
    $whereSQL
    ORDER BY c.created_at DESC
    LIMIT 48
");
$stmt->execute($params);
$companies = $stmt->fetchAll();

/* ── Batch: highlights + one active campaign per company ── */
$highlightsMap = [];
$campaignsMap  = [];
if (!empty($companies)) {
    $ids   = implode(',', array_map('intval', array_column($companies, 'id')));
    $rows  = $pdo->query("SELECT company_id, label, value FROM pitch_highlights WHERE company_id IN ($ids) ORDER BY company_id, sort_order")->fetchAll();
    foreach ($rows as $r) {
        if (!isset($highlightsMap[$r['company_id']])) $highlightsMap[$r['company_id']] = [];
        if (count($highlightsMap[$r['company_id']]) < 3) $highlightsMap[$r['company_id']][] = $r;
    }
    $rows = $pdo->query("
        SELECT fc.company_id, fc.uuid AS campaign_uuid, fc.title AS campaign_title,
               fc.campaign_type, fc.status AS campaign_status,
               fc.raise_target, fc.total_raised, fc.contributor_count, fc.max_contributors, fc.closes_at
        FROM funding_campaigns fc
        INNER JOIN (
            SELECT company_id, MAX(id) AS max_id
            FROM funding_campaigns
            WHERE company_id IN ($ids) AND status IN ('open','funded')
            GROUP BY company_id
        ) latest ON latest.company_id = fc.company_id AND latest.max_id = fc.id
    ")->fetchAll();
    foreach ($rows as $r) $campaignsMap[$r['company_id']] = $r;
}

// US-101 — Batch invite check: collect all campaign IDs visible on this page,
// then resolve which ones this user has an accepted invite for.
// Used in the card template to suppress the campaign strip for uninvited users.
$_allCampaignIds = array_map(
    fn($r) => (int)$r['id'],
    $pdo->query("
        SELECT fc.id
        FROM funding_campaigns fc
        WHERE fc.company_id IN (" .
            (empty($companies) ? '0' : implode(',', array_map('intval', array_column($companies, 'id')))) .
        ") AND fc.status IN ('open','funded')
    ")->fetchAll()
);
$_invitedIds  = InviteService::acceptedCampaignIds($userId, $_allCampaignIds);
$invitedSet   = array_flip($_invitedIds); // O(1) per-card lookup: isset($invitedSet[$campaign['id']])

/* ── Filter options ─────────────────────────── */
$provinces  = ['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'];
$industries = $pdo->query("SELECT DISTINCT industry FROM companies WHERE status='active' AND verified=1 AND industry IS NOT NULL ORDER BY industry")->fetchAll(PDO::FETCH_COLUMN);

/* ── User info ──────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

/* ── Helpers ────────────────────────────────── */
function dSnippet($t, $l = 110) { $t = strip_tags($t ?? ''); return mb_strlen($t) > $l ? mb_substr($t, 0, $l) . '…' : $t; }
function dMoney($v)              { return 'R ' . number_format((float)$v, 0, '.', ' '); }

$ctLabels   = ['revenue_share'=>['fa-chart-line','ct-rs'],'cooperative_membership'=>['fa-people-roof','ct-co'],'fixed_return_loan'=>['fa-hand-holding-dollar','ct-loan'],'donation'=>['fa-heart','ct-don'],'convertible_note'=>['fa-rotate','ct-cn']];
$areaLabels = ['urban'=>'Urban','township'=>'Township','rural'=>'Rural'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Discover Businesses | Old Union</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
/* Header */
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.logo-container{display:flex;align-items:center;}
.logo{font-family:'Playfair Display',serif;font-weight:600;font-size:18px;color:#333;text-decoration:none;text-transform:uppercase;display:inline-block;}
.logo span{display:inline-block;}.logo .second{color:#c8102e;font-family:'Playfair Display',serif;}
.logo .first,.logo .big{font-size:1.4rem;line-height:0.8;vertical-align:baseline;font-family:'Playfair Display',serif;}
.header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
.header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
.header-nav a:hover{background:var(--surface-2);color:var(--text);}.header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;cursor:pointer;}
/* Dropdown */
.header-dropdown{position:fixed;top:calc(var(--header-h) + 1px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:0 0 12px 12px;box-shadow:0 12px 32px rgba(36,33,33,.12);min-width:260px;z-index:1001;opacity:0;transform:translateY(-8px) scale(0.98);pointer-events:none;transition:opacity .2s ease,transform .2s ease;}
.header-dropdown--open{opacity:1;transform:translateY(0) scale(1);pointer-events:all;}
.header-dropdown-inner{padding:.5rem 0;}
.header-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);padding:.7rem 1rem .3rem;}
.header-dropdown-link{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;color:#1e293b;text-decoration:none;font-size:.85rem;font-weight:500;transition:background .15s ease;}
.header-dropdown-link:hover{background:var(--surface-2);color:#0f3b7a;}.header-dropdown-link i{width:20px;text-align:center;font-size:.9rem;color:#5b6e8c;}
.header-dropdown-link.active{background:#e8f0fe;color:#0f3b7a;font-weight:600;}.header-dropdown-link.active i{color:#0f3b7a;}
.header-dropdown-link.danger{color:#b91c1c;}.header-dropdown-link.danger:hover{background:#fef2f2;color:#b91c1c;}
.header-divider{height:1px;background:var(--border);margin:.3rem 0;}
/* Layout */
.page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
.sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
.sidebar-section-label:first-child{margin-top:0;}
.sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
.sidebar a:hover{background:var(--surface-2);color:var(--text);}.sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
.sidebar a i{width:16px;text-align:center;font-size:.85rem;}
.main-content{flex:1;min-width:0;display:flex;flex-direction:column;}
/* Filter bar */
.filter-bar{background:var(--surface);border-bottom:1px solid var(--border);padding:.85rem 2rem;position:sticky;top:var(--header-h);z-index:90;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;}
.filter-bar form{display:contents;}
.search-wrap{position:relative;flex:1;min-width:200px;max-width:340px;}
.search-wrap i{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.82rem;pointer-events:none;}
.search-input{width:100%;padding:.48rem .9rem .48rem 2.2rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.84rem;color:var(--text);background:var(--surface-2);outline:none;transition:all var(--transition);}
.search-input:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.08);}
.filter-select{padding:.45rem .85rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;color:var(--text-muted);background:var(--surface-2);outline:none;cursor:pointer;appearance:none;transition:all var(--transition);background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .7rem center;padding-right:2rem;}
.filter-select:focus,.filter-select.active{border-color:var(--navy-light);color:var(--navy-mid);background-color:#eff4ff;}
.filter-checkbox-label{display:flex;align-items:center;gap:.4rem;font-size:.82rem;font-weight:500;color:var(--text-muted);cursor:pointer;padding:.45rem .85rem;border-radius:99px;border:1.5px solid var(--border);background:var(--surface-2);transition:all var(--transition);white-space:nowrap;user-select:none;}
.filter-checkbox-label:has(input:checked){border-color:var(--navy-light);color:var(--navy-mid);background:#eff4ff;}
.filter-checkbox-label input{display:none;}
.filter-divider{width:1px;height:20px;background:var(--border);flex-shrink:0;}
.filter-count{margin-left:auto;font-size:.8rem;color:var(--text-light);white-space:nowrap;}.filter-count strong{color:var(--text);}
.filter-clear{font-size:.8rem;color:var(--navy-light);text-decoration:none;font-weight:600;white-space:nowrap;padding:.4rem .7rem;border-radius:99px;transition:background var(--transition);}
.filter-clear:hover{background:#eff4ff;}
/* Grid */
.browse-main{padding:1.75rem 2rem 2.5rem;}
.company-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1.5rem;}
/* Company card */
.company-card{background:var(--surface);border-radius:var(--radius);border:1px solid var(--border);box-shadow:var(--shadow);display:flex;flex-direction:column;overflow:hidden;text-decoration:none;color:inherit;transition:transform var(--transition),box-shadow var(--transition),border-color var(--transition);}
.company-card:hover{transform:translateY(-4px);box-shadow:var(--shadow-card);border-color:#c7d9f8;}
.card-banner{height:100px;background:linear-gradient(135deg,var(--navy) 0%,var(--navy-mid) 100%);background-size:cover;background-position:center;position:relative;flex-shrink:0;}
.card-logo-wrap{position:absolute;bottom:-24px;left:1.1rem;width:52px;height:52px;border-radius:10px;background:var(--surface);border:2.5px solid var(--surface);box-shadow:0 2px 10px rgba(0,0,0,.12);overflow:hidden;display:flex;align-items:center;justify-content:center;}
.card-logo-wrap img{width:100%;height:100%;object-fit:cover;}
.card-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.3rem;color:#fff;}
.card-ribbon{position:absolute;top:.6rem;right:.6rem;display:flex;align-items:center;gap:.3rem;padding:.25rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700;backdrop-filter:blur(6px);}
.ribbon-open  {background:rgba(11,107,77,.85);color:#fff;}
.ribbon-funded{background:rgba(245,158,11,.9);color:var(--navy);}
.card-body{padding:0 1.1rem;flex:1;display:flex;flex-direction:column;padding-top:1.5rem;}
.card-name{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--navy);line-height:1.25;margin-bottom:.45rem;}
.card-badges{display:flex;flex-wrap:wrap;gap:.3rem;margin-bottom:.6rem;}
.badge{display:inline-flex;align-items:center;gap:.22rem;padding:.17rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
.badge-industry{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.badge-township{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.badge-urban   {background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
.badge-rural   {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.badge-location{background:#f8fafc;color:var(--text-muted);border-color:var(--border);}
.card-description{font-size:.84rem;color:var(--text-muted);line-height:1.55;margin-bottom:.8rem;flex:1;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;}
.card-highlights{display:flex;gap:.45rem;flex-wrap:wrap;margin-bottom:.8rem;}
.card-hl{display:flex;flex-direction:column;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.32rem .6rem;flex:1;min-width:75px;}
.card-hl-val{font-size:.81rem;font-weight:700;color:var(--navy);line-height:1.1;}
.card-hl-label{font-size:.67rem;color:var(--text-light);margin-top:.1rem;}
/* Campaign strip */
.card-campaign{border-top:1px solid var(--border);padding:.8rem 1.1rem;background:var(--surface-2);}
.cc-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:.42rem;gap:.4rem;}
.cc-title{font-size:.78rem;font-weight:600;color:var(--navy);display:flex;align-items:center;gap:.3rem;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.ct-rs  {color:var(--navy-light);}
.ct-co  {color:var(--green);}
.ct-loan{color:#78350f;}
.ct-don {color:#9d174d;}
.ct-cn  {color:#6d28d9;}
.prog-outer{height:6px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:.38rem;}
.prog-inner{height:100%;border-radius:99px;}
.prog-open  {background:var(--amber);}
.prog-funded{background:var(--green);}
.prog-foot{display:flex;justify-content:space-between;font-size:.71rem;color:var(--text-light);}
/* Footer */
.card-footer{padding:.8rem 1.1rem;display:flex;align-items:center;justify-content:space-between;border-top:1px solid var(--border);gap:.5rem;}
.card-location{display:flex;align-items:center;gap:.3rem;font-size:.75rem;color:var(--text-light);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.card-cta{display:inline-flex;align-items:center;gap:.3rem;font-size:.8rem;font-weight:700;color:var(--navy-mid);padding:.32rem .75rem;border-radius:99px;background:#eff4ff;border:1px solid #c7d9f8;transition:all var(--transition);flex-shrink:0;}
.card-cta:hover{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
/* Empty */
.empty-browse{text-align:center;padding:5rem 2rem;grid-column:1/-1;}
.empty-browse i{font-size:2.5rem;color:var(--border);margin-bottom:1rem;display:block;}
.empty-browse h3{font-family:'DM Serif Display',serif;font-size:1.4rem;color:var(--navy);margin-bottom:.5rem;}
.empty-browse p{font-size:.9rem;color:var(--text-muted);}
/* Responsive */
@media(max-width:1024px){.header-nav{display:none;}.filter-bar{padding:.75rem 1.5rem;}.browse-main{padding:1.25rem 1.5rem 2rem;}}
@media(max-width:900px){.sidebar{display:none;}.filter-bar{gap:.4rem;overflow-x:auto;flex-wrap:nowrap;}.filter-count{display:none;}}
@media(max-width:600px){.company-grid{grid-template-columns:1fr;}.browse-main{padding:1rem;}.filter-bar{padding:.65rem 1rem;}}
@media screen and (min-width:1024px){.header-dropdown{display:none!important;}}
::-webkit-scrollbar{height:4px;width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<header class="top-header">
    <div class="logo-container">
        <a href="/app/" class="logo"><span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span></a>
    </div>
    <nav class="header-nav">
        <a href="/app/"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/discover/" class="active"><i class="fa-solid fa-compass"></i> Discover</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar" id="AvatarBtn"><?php echo htmlspecialchars($ini); ?></div>
    <div class="header-dropdown" id="UserDropdown" role="menu" aria-label="User menu">
        <div class="header-dropdown-inner">
            <div class="header-section-label">Dashboard</div>
            <a href="/app/" class="header-dropdown-link"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
            <a href="/app/?tab=payouts" class="header-dropdown-link"><i class="fa-solid fa-coins"></i> Payouts</a>
            <a href="/app/?tab=watchlist" class="header-dropdown-link"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
            <div class="header-section-label">Discover</div>
            <a href="/app/discover/" class="header-dropdown-link active"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
            <div class="header-section-label">Account</div>
            <a href="/app/wallet/" class="header-dropdown-link"><i class="fa-solid fa-wallet"></i> Wallet</a>
            <a href="/app/profile.php" class="header-dropdown-link"><i class="fa-solid fa-user"></i> Profile</a>
            <div class="header-divider"></div>
            <a href="/app/auth/logout.php" class="header-dropdown-link danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Dashboard</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <a href="/app/?tab=watchlist"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/" class="active"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <div class="main-content">

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="GET" action="/app/discover/" id="ff" style="display:contents;">

                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="search" class="search-input"
                           placeholder="Search businesses…"
                           value="<?php echo htmlspecialchars($search); ?>"
                           onchange="document.getElementById('ff').submit()">
                    <?php foreach(['province','area','industry'] as $k): ?>
                        <?php if (!empty($$k)): ?><input type="hidden" name="<?php echo $k; ?>" value="<?php echo htmlspecialchars($$k); ?>"><?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($hasCampaign): ?><input type="hidden" name="has_campaign" value="1"><?php endif; ?>
                </div>

                <select name="province" class="filter-select <?php echo $province ? 'active' : ''; ?>"
                        onchange="document.getElementById('ff').submit()">
                    <option value="">All Provinces</option>
                    <?php foreach ($provinces as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $province === $p ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>

                <select name="area" class="filter-select <?php echo $area ? 'active' : ''; ?>"
                        onchange="document.getElementById('ff').submit()">
                    <option value="">All Areas</option>
                    <option value="township" <?php echo $area === 'township' ? 'selected' : ''; ?>>Township</option>
                    <option value="urban"    <?php echo $area === 'urban'    ? 'selected' : ''; ?>>Urban</option>
                    <option value="rural"    <?php echo $area === 'rural'    ? 'selected' : ''; ?>>Rural</option>
                </select>

                <select name="industry" class="filter-select <?php echo $industry ? 'active' : ''; ?>"
                        onchange="document.getElementById('ff').submit()">
                    <option value="">All Industries</option>
                    <?php foreach ($industries as $ind): ?>
                        <option value="<?php echo htmlspecialchars($ind); ?>" <?php echo $industry === $ind ? 'selected' : ''; ?>><?php echo htmlspecialchars($ind); ?></option>
                    <?php endforeach; ?>
                </select>

                <div class="filter-divider"></div>

                <label class="filter-checkbox-label">
                    <input type="checkbox" name="has_campaign" value="1"
                           <?php echo $hasCampaign ? 'checked' : ''; ?>
                           onchange="document.getElementById('ff').submit()">
                    <i class="fa-solid fa-rocket" style="font-size:.73rem;"></i> Raising Now
                </label>

            </form>

            <span class="filter-count"><strong><?php echo count($companies); ?></strong> compan<?php echo count($companies) !== 1 ? 'ies' : 'y'; ?></span>

            <?php if ($search || $province || $area || $industry || $hasCampaign): ?>
                <a href="/app/discover/" class="filter-clear"><i class="fa-solid fa-xmark"></i> Clear</a>
            <?php endif; ?>
        </div>

        <!-- Grid -->
        <div class="browse-main">
            <div class="company-grid">

                <?php if (empty($companies)): ?>
                <div class="empty-browse">
                    <i class="fa-solid fa-building-circle-exclamation"></i>
                    <h3>No businesses found</h3>
                    <p>Try adjusting your filters or <a href="/app/discover/" style="color:var(--navy-light);">clear all filters</a>.</p>
                </div>
                <?php else: ?>

                <?php foreach ($companies as $co):
                    $cid      = $co['id'];
                    $hlList   = $highlightsMap[$cid] ?? [];
                    $campaign = $campaignsMap[$cid]  ?? null;
                    $banner   = !empty($co['banner']) ? htmlspecialchars($co['banner']) : '';
                    $logo     = !empty($co['logo'])   ? htmlspecialchars($co['logo'])   : '';
                    $areaLabel = $areaLabels[$co['area'] ?? ''] ?? '';
                    $pct = $isFunded = $ctIcon = $ctClass = null;
                    // US-101: check invite status for this card's campaign
                    $isInvited = $campaign && isset($invitedSet[(int)($campaign['id'] ?? 0)]);
                    if ($campaign) {
                        $t       = (float)$campaign['raise_target'];
                        $r       = (float)$campaign['total_raised'];
                        $pct     = $t > 0 ? min(100, round(($r / $t) * 100)) : 0;
                        $isFunded = $campaign['campaign_status'] === 'funded';
                        $ct = $ctLabels[$campaign['campaign_type']] ?? ['fa-rocket','ct-rs'];
                        [$ctIcon, $ctClass] = $ct;
                    }
                    $loc = array_filter([$co['city'] ?? '', $co['province'] ?? '']);
                ?>
                <a href="/app/discover/company.php?uuid=<?php echo urlencode($co['uuid']); ?>" class="company-card">

                    <div class="card-banner" <?php if ($banner): ?>style="background-image:url('<?php echo $banner; ?>')"<?php endif; ?>>
                        <div class="card-logo-wrap">
                            <?php if ($logo): ?>
                                <img src="<?php echo $logo; ?>" alt="<?php echo htmlspecialchars($co['name']); ?>">
                            <?php else: ?>
                                <div class="card-logo-ph"><?php echo strtoupper(substr($co['name'], 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                        <?php if ($campaign && $isInvited): ?>
                        <div class="card-ribbon <?php echo $isFunded ? 'ribbon-funded' : 'ribbon-open'; ?>">
                            <i class="fa-solid fa-rocket" style="font-size:.65rem;"></i>
                            <?php echo $isFunded ? 'Funded' : 'Raising'; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <div class="card-body">
                        <div class="card-name"><?php echo htmlspecialchars($co['name']); ?></div>
                        <div class="card-badges">
                            <?php if ($co['industry']): ?><span class="badge badge-industry"><?php echo htmlspecialchars($co['industry']); ?></span><?php endif; ?>
                            <?php if ($areaLabel): ?><span class="badge badge-<?php echo htmlspecialchars($co['area']); ?>"><?php echo $areaLabel; ?></span><?php endif; ?>
                            <?php if ($co['stage']): ?><span class="badge badge-location"><?php echo htmlspecialchars(ucfirst(str_replace('_',' ',$co['stage']))); ?></span><?php endif; ?>
                        </div>
                        <?php if ($co['description']): ?>
                            <div class="card-description"><?php echo htmlspecialchars(dSnippet($co['description'])); ?></div>
                        <?php endif; ?>
                        <?php if (!empty($hlList)): ?>
                        <div class="card-highlights">
                            <?php foreach ($hlList as $hl): ?>
                            <div class="card-hl">
                                <span class="card-hl-val"><?php echo htmlspecialchars($hl['value']); ?></span>
                                <span class="card-hl-label"><?php echo htmlspecialchars($hl['label']); ?></span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>
                    </div>

                    <?php if ($campaign && $isInvited): ?>
                    <div class="card-campaign">
                        <div class="cc-header">
                            <div class="cc-title">
                                <i class="fa-solid <?php echo $ctIcon; ?> <?php echo $ctClass; ?>" style="font-size:.73rem;"></i>
                                <?php echo htmlspecialchars($campaign['campaign_title']); ?>
                            </div>
                            <span style="font-size:.72rem;color:var(--text-light);white-space:nowrap;"><?php echo (int)$campaign['contributor_count']; ?>/<?php echo (int)$campaign['max_contributors']; ?></span>
                        </div>
                        <div class="prog-outer">
                            <div class="prog-inner <?php echo $isFunded ? 'prog-funded' : 'prog-open'; ?>" style="width:<?php echo $pct; ?>%"></div>
                        </div>
                        <div class="prog-foot">
                            <span><?php echo dMoney($campaign['total_raised']); ?> raised</span>
                            <span><?php echo $pct; ?>% of <?php echo dMoney($campaign['raise_target']); ?></span>
                        </div>
                    </div>
                    <?php endif; ?>

                    <div class="card-footer">
                        <div class="card-location">
                            <i class="fa-solid fa-location-dot" style="font-size:.73rem;"></i>
                            <?php echo htmlspecialchars(implode(', ', $loc) ?: 'South Africa'); ?>
                        </div>
                        <span class="card-cta">View <i class="fa-solid fa-arrow-right" style="font-size:.68rem;"></i></span>
                    </div>

                </a>
                <?php endforeach; ?>
                <?php endif; ?>

            </div>
        </div>

    </div>
</div>

<script>
(function(){
    'use strict';
    const btn=document.getElementById('AvatarBtn'),dd=document.getElementById('UserDropdown');
    function close(){if(dd)dd.classList.remove('header-dropdown--open');if(btn)btn.setAttribute('aria-expanded','false');}
    function toggle(){if(window.innerWidth>=1024){close();return;}dd.classList.contains('header-dropdown--open')?close():(dd.classList.add('header-dropdown--open'),btn.setAttribute('aria-expanded','true'));}
    if(btn)btn.addEventListener('click',e=>{e.stopPropagation();toggle();});
    document.addEventListener('click',e=>{if(dd&&!dd.contains(e.target)&&btn&&!btn.contains(e.target))close();});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')close();});
    window.addEventListener('resize',()=>{if(window.innerWidth>=1024)close();});
})();
</script>
</body>
</html>
