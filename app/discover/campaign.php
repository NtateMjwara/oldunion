<?php
// ============================================================
// discover/campaign.php — Public SPV campaign page
//
// Rendering priority (SPV-first, parent-company fallback):
//   Logo:        spv_logo        → company logo
//   Banner:      spv_banner      → company banner
//   Description: spv_description → company description
//   Address:     spv address     → company_filter address
//   Highlights:  campaign_highlights → pitch_highlights
// ============================================================

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InviteService.php'; // US-101

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/discover/campaign.php?cid=' . urlencode($_GET['cid'] ?? '')));
}

$campaignUuid = trim($_GET['cid'] ?? '');
if (empty($campaignUuid)) { redirect('/app/discover/'); }

$pdo    = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

/* ── Load campaign + company (all SPV columns included) ─── */
$stmt = $pdo->prepare("
    SELECT
        fc.id, fc.uuid AS campaign_uuid, fc.title, fc.tagline,
        fc.campaign_type, fc.status AS campaign_status,
        fc.raise_target, fc.raise_minimum, fc.raise_maximum,
        fc.min_contribution, fc.max_contribution, fc.max_contributors,
        fc.total_raised, fc.contributor_count,
        fc.opens_at, fc.closes_at,
        fc.use_of_funds,

        -- SPV identity (may be NULL — fall back to parent below)
        fc.spv_registered_name,
        fc.spv_registration_number,
        fc.spv_description,
        fc.spv_email,
        fc.spv_phone,
        fc.spv_website,
        fc.spv_logo,
        fc.spv_banner,
        fc.spv_address_same_as_company,
        fc.spv_province,
        fc.spv_city,
        fc.spv_suburb,
        fc.spv_area,

        -- Deal terms
        ct.revenue_share_percentage,
        ct.revenue_share_duration_months,
        ct.unit_name, ct.unit_price, ct.total_units_available,
        ct.fixed_return_rate, ct.loan_term_months,
        ct.governing_law,

        -- Parent company
        c.id   AS company_id,
        c.uuid AS company_uuid,
        c.name AS company_name,
        c.logo AS company_logo,
        c.banner AS company_banner,
        c.description AS company_description,
        c.industry, c.type AS company_type, c.stage,
        c.founded_year, c.employee_count, c.website AS company_website,

        -- Parent company address
        cf.province AS co_province,
        cf.city     AS co_city,
        cf.suburb   AS co_suburb,
        cf.area     AS co_area
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    JOIN companies c ON c.id = fc.company_id
    LEFT JOIN company_filter cf ON cf.company_id = c.id
    WHERE fc.uuid = :uuid
      AND fc.status IN ('open','funded')
      AND c.status  = 'active'
      AND c.verified = 1
");
$stmt->execute(['uuid' => $campaignUuid]);
$campaign = $stmt->fetch();
if (!$campaign) { redirect('/app/discover/'); }

// ------------------------------------------------------------------
// US-101 — Invite gate (AC1 + AC3)
// Uninvited users see HTTP 403 — not a 404 — so the access attempt
// is auditable (the campaign exists; the user is simply not on the
// list). Campaign details are withheld entirely from the response.
// ------------------------------------------------------------------
if (!InviteService::hasAccepted($userId, (int)$campaign['id'])) {
    http_response_code(403);
    $pdo->prepare("SELECT email FROM users WHERE id = ?")->execute([$userId]);
    // Render a self-contained 403 page using the platform design language
    // and exit — no campaign data is passed to the template.
    $ini403 = strtoupper(substr($_SESSION['user_email'] ?? 'U', 0, 1));
    ?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Not Authorised | Old Union</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--amber:#f59e0b;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--header-h:64px;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;}
.header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
.header-brand span{color:#c8102e;}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;}
.page{padding-top:var(--header-h);min-height:100vh;display:flex;align-items:center;justify-content:center;}
.gate-card{background:var(--surface);border:1px solid var(--error-bdr);border-radius:var(--radius);padding:3rem 2.5rem;max-width:480px;width:100%;margin:2rem 1.5rem;text-align:center;box-shadow:0 8px 28px rgba(11,37,69,.09);}
.gate-icon{width:72px;height:72px;border-radius:50%;background:var(--error-bg);border:2px solid var(--error-bdr);display:flex;align-items:center;justify-content:center;font-size:1.75rem;color:var(--error);margin:0 auto 1.5rem;}
.gate-code{font-size:.72rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:var(--error);margin-bottom:.5rem;}
.gate-title{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);margin-bottom:.75rem;}
.gate-body{font-size:.9rem;color:var(--text-muted);line-height:1.65;margin-bottom:2rem;}
.gate-body strong{color:var(--text);}
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;text-decoration:none;transition:all .2s;}
.btn-primary{background:var(--navy-mid);color:#fff;}
.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
</style>
</head>
<body>
<header class="top-header">
    <a href="/app/discover/" class="header-brand">Old <span>U</span>nion</a>
    <div class="avatar"><?php echo htmlspecialchars($ini403); ?></div>
</header>
<div class="page">
    <div class="gate-card">
        <div class="gate-icon"><i class="fa-solid fa-lock"></i></div>
        <div class="gate-code">403 — Not Authorised</div>
        <h1 class="gate-title">Invitation required</h1>
        <p class="gate-body">
            This is a <strong>private placement campaign</strong>. Access is limited to
            investors who have received and accepted an explicit invitation from the company.
            <br><br>
            If you believe you should have access, contact the company directly and ask them
            to send you an invitation through the platform.
        </p>
        <a href="/app/discover/" class="btn btn-primary">
            <i class="fa-solid fa-compass"></i> Back to Discover
        </a>
    </div>
</div>
</body>
</html><?php
    exit;
}

$campaignId = (int)$campaign['id'];
$companyId  = (int)$campaign['company_id'];

/* ── Resolve SPV-first display values ──────────────────── */
// Branding
$displayLogo   = $campaign['spv_logo']        ?: $campaign['company_logo'];
$displayBanner = $campaign['spv_banner']       ?: $campaign['company_banner'];

// Description: SPV description > parent company description
$displayDescription = $campaign['spv_description'] ?: $campaign['company_description'];

// Address: if spv_address_same_as_company == 1, use parent address
$sameAddr = (bool)$campaign['spv_address_same_as_company'];
$displayProvince = $sameAddr ? $campaign['co_province'] : $campaign['spv_province'];
$displayCity     = $sameAddr ? $campaign['co_city']     : $campaign['spv_city'];
$displaySuburb   = $sameAddr ? $campaign['co_suburb']   : $campaign['spv_suburb'];
$displayArea     = $sameAddr ? $campaign['co_area']      : $campaign['spv_area'];

// Use of funds (stored as JSON on funding_campaigns)
$useOfFunds = [];
if (!empty($campaign['use_of_funds'])) {
    $useOfFunds = json_decode($campaign['use_of_funds'], true) ?: [];
}

/* ── SPV Highlights (falls back to company highlights) ──── */
$stmt = $pdo->prepare("
    SELECT label, value FROM campaign_highlights
    WHERE campaign_id = ?
    ORDER BY sort_order ASC
    LIMIT 6
");
$stmt->execute([$campaignId]);
$highlights = $stmt->fetchAll();

// Fall back to parent company highlights if the SPV has none
if (empty($highlights)) {
    $stmt = $pdo->prepare("
        SELECT label, value FROM pitch_highlights
        WHERE company_id = ?
        ORDER BY sort_order ASC
        LIMIT 6
    ");
    $stmt->execute([$companyId]);
    $highlights = $stmt->fetchAll();
    $highlightSource = 'company';
} else {
    $highlightSource = 'spv';
}

/* ── Campaign pitch (investment thesis, risk, team etc.) ── */
$stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$pitch = $stmt->fetch() ?: [];

/* ── SPV KYC verification status ─────────────────────────── */
$stmt = $pdo->prepare("SELECT verification_status FROM campaign_kyc WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$kycStatus = $stmt->fetchColumn() ?: null;

/* ── Campaign updates ─────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT id, update_type, title, body,
           period_year, period_month,
           revenue_this_period, expenses_this_period,
           payout_amount, payout_per_contributor,
           published_at
    FROM campaign_updates
    WHERE campaign_id = :cid AND published_at IS NOT NULL
    ORDER BY published_at DESC
    LIMIT 15
");
$stmt->execute(['cid' => $campaignId]);
$updates = $stmt->fetchAll();

/* ── Q&A ─────────────────────────────────────────────────── */
$questions  = [];
$csrf_token = generateCSRFToken();
$qError = $qSuccess = '';

$stmt = $pdo->prepare("
    SELECT cq.id, cq.question, cq.asked_at, cq.answer, cq.answered_at,
           u.email AS asker_email
    FROM campaign_questions cq
    JOIN users u ON u.id = cq.asked_by
    WHERE cq.campaign_id = :cid AND cq.is_public = 1
    ORDER BY cq.asked_at DESC
");
$stmt->execute(['cid' => $campaignId]);
$questions = $stmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $qError = 'Invalid security token. Please refresh and try again.';
    } else {
        $q = trim($_POST['question'] ?? '');
        if ($q === '') { $qError = 'Please enter a question.'; }
        elseif (mb_strlen($q) > 1000) { $qError = 'Question is too long (max 1 000 characters).'; }
        else {
            $pdo->prepare("INSERT INTO campaign_questions (campaign_id, asked_by, question, asked_at) VALUES (:cid, :uid, :q, NOW())")
                ->execute(['cid' => $campaignId, 'uid' => $userId, 'q' => $q]);
            $qSuccess = 'Your question has been submitted and will appear once approved.';
            // Reload public questions
            $stmt = $pdo->prepare("SELECT cq.id, cq.question, cq.asked_at, cq.answer, cq.answered_at, u.email AS asker_email FROM campaign_questions cq JOIN users u ON u.id = cq.asked_by WHERE cq.campaign_id = :cid AND cq.is_public = 1 ORDER BY cq.asked_at DESC");
            $stmt->execute(['cid' => $campaignId]);
            $questions = $stmt->fetchAll();
        }
    }
}

/* ── User info ───────────────────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

/* ── Helpers ─────────────────────────────────────────────── */
function cam_money($v) { if ($v === null || $v === '') return '—'; return 'R ' . number_format((float)$v, 0, '.', ' '); }
function cam_date($v)  { return $v ? date('d M Y', strtotime($v)) : '—'; }
function cam_days_left($d) { return max(0, (int)ceil((strtotime($d) - time()) / 86400)); }
function cam_mask_email($e) {
    $p = explode('@', $e);
    if (count($p) !== 2) return 'Member';
    return substr($p[0], 0, 1) . str_repeat('*', max(2, mb_strlen($p[0]) - 1)) . '@' . $p[1];
}

$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

$typeLabels = [
    'revenue_share'          => ['Revenue Share',    'fa-chart-line',  'ct-rs'],
    'cooperative_membership' => ['Co-op Membership', 'fa-people-roof', 'ct-co'],
    'fixed_return_loan'      => ['Fixed Return Loan','fa-hand-holding-dollar','ct-loan'],
    'donation'               => ['Donation',         'fa-heart',       'ct-don'],
    'convertible_note'       => ['Convertible Note', 'fa-rotate',      'ct-cn'],
];
$updateTypeConfig = [
    'general'         => ['Update',           'fa-newspaper',            'ut-general'],
    'financial'       => ['Financial Report', 'fa-chart-bar',            'ut-financial'],
    'milestone'       => ['Milestone',        'fa-trophy',               'ut-milestone'],
    'payout'          => ['Payout',           'fa-coins',                'ut-payout'],
    'issue'           => ['Issue Flagged',    'fa-triangle-exclamation', 'ut-issue'],
    'campaign_closed' => ['Campaign Closed',  'fa-flag-checkered',       'ut-closed'],
];
$areaLabels = ['urban'=>'Urban','township'=>'Township','rural'=>'Rural'];

$isFunded   = $campaign['campaign_status'] === 'funded';
$target     = (float)$campaign['raise_target'];
$raised     = (float)$campaign['total_raised'];
$pct        = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;
$daysLeft   = cam_days_left($campaign['closes_at']);
$spotsLeft  = max(0, (int)$campaign['max_contributors'] - (int)$campaign['contributor_count']);
$ctInfo     = $typeLabels[$campaign['campaign_type']] ?? ['Campaign', 'fa-rocket', 'ct-rs'];

$contributeUrl = '/app/invest/start.php?cid=' . urlencode($campaignUuid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($campaign['title']) ?> | Old Union</title>
<meta name="description" content="<?= htmlspecialchars(substr(strip_tags($displayDescription ?? ''), 0, 160)) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
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
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
/* Header */
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.logo-container{display:flex;align-items:center;}
.logo{font-family:'Playfair Display',serif;font-weight:600;font-size:18px;color:#333;text-decoration:none;text-transform:uppercase;display:inline-block;}
.logo span{display:inline-block;}.logo .second{color:#c8102e;}
.logo .first,.logo .big{font-size:1.4rem;line-height:0.8;vertical-align:baseline;}
.header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
.header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
.header-nav a:hover{background:var(--surface-2);color:var(--text);}
.header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;cursor:pointer;}
/* Dropdown */
.header-dropdown{position:fixed;top:calc(var(--header-h) + 1px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:0 0 12px 12px;box-shadow:0 12px 32px rgba(36,33,33,.12);min-width:260px;z-index:1001;opacity:0;transform:translateY(-8px) scale(0.98);pointer-events:none;transition:opacity .2s ease,transform .2s ease;}
.header-dropdown--open{opacity:1;transform:translateY(0) scale(1);pointer-events:all;}
.header-dropdown-inner{padding:.5rem 0;}
.header-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);padding:.7rem 1rem .3rem;}
.header-dropdown-link{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;color:#1e293b;text-decoration:none;font-size:.85rem;font-weight:500;transition:background .15s ease;}
.header-dropdown-link:hover{background:var(--surface-2);color:#0f3b7a;}
.header-dropdown-link i{width:20px;text-align:center;font-size:.9rem;color:#5b6e8c;}
.header-dropdown-link.active{background:#e8f0fe;color:#0f3b7a;font-weight:600;}
.header-dropdown-link.danger{color:#b91c1c;}
.header-dropdown-link.danger:hover{background:#fef2f2;}
.header-divider{height:1px;background:var(--border);margin:.3rem 0;}
/* Layout */
.page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
.sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
.sidebar-section-label:first-child{margin-top:0;}
.sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
.sidebar a:hover{background:var(--surface-2);color:var(--text);}
.sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
.sidebar a i{width:16px;text-align:center;font-size:.85rem;}
.main-content{flex:1;min-width:0;}
/* Back bar */
.back-bar{padding:.85rem 2rem;max-width:1200px;margin:0 auto;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.83rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
.back-link:hover{color:var(--navy);}
/* Hero */
.campaign-hero{max-width:1200px;margin:0 auto;padding:0 2rem;}
.hero-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;margin-bottom:1.5rem;}
.hero-banner{height:220px;background:linear-gradient(135deg,var(--navy-mid) 0%,var(--navy) 100%);background-size:cover;background-position:center;position:relative;}
.hero-banner-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 30%,rgba(11,37,69,.65) 100%);}
.hero-type-ribbon{position:absolute;top:1rem;left:1rem;display:inline-flex;align-items:center;gap:.35rem;padding:.3rem .85rem;border-radius:99px;font-size:.75rem;font-weight:700;backdrop-filter:blur(6px);}
.ct-rs{background:rgba(11,37,69,.8);color:#93c5fd;border:1px solid rgba(147,197,253,.3);}
.ct-co{background:rgba(11,107,77,.8);color:#6ee7b7;border:1px solid rgba(110,231,183,.3);}
.ct-loan{background:rgba(217,119,6,.8);color:#fde68a;border:1px solid rgba(253,230,138,.3);}
.hero-status-ribbon{position:absolute;top:1rem;right:1rem;display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .85rem;border-radius:99px;font-size:.75rem;font-weight:700;backdrop-filter:blur(6px);}
.ribbon-open{background:rgba(11,107,77,.85);color:#fff;}
.ribbon-funded{background:rgba(245,158,11,.9);color:var(--navy);}
.hero-logo-wrap{position:absolute;bottom:-28px;left:2rem;width:64px;height:64px;border-radius:12px;background:var(--surface);border:3px solid var(--surface);box-shadow:0 4px 14px rgba(0,0,0,.14);overflow:hidden;display:flex;align-items:center;justify-content:center;}
.hero-logo-wrap img{width:100%;height:100%;object-fit:cover;}
.hero-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.6rem;color:#fff;}
.hero-identity{display:flex;align-items:flex-end;gap:1.25rem;padding:0 2rem 1.5rem;margin-top:-28px;position:relative;z-index:2;flex-wrap:wrap;}
.hero-title-area{flex:1;min-width:200px;padding-bottom:.2rem;padding-top:.5rem;}
.hero-company-name{font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem;display:flex;align-items:center;gap:.35rem;}
.hero-campaign-title{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);line-height:1.2;margin-bottom:.5rem;}
.hero-tagline{font-size:.93rem;color:var(--text-muted);line-height:1.5;}
.hero-badges{display:flex;flex-wrap:wrap;gap:.4rem;margin-top:.6rem;}
/* SPV identity strip */
.spv-identity-strip{background:var(--surface-2);border-top:1px solid var(--border);padding:.75rem 2rem;display:flex;flex-wrap:wrap;align-items:center;gap:1.25rem;}
.spv-identity-item{display:flex;align-items:center;gap:.4rem;font-size:.79rem;color:var(--text-muted);}
.spv-identity-item i{color:var(--navy-light);font-size:.75rem;}
.spv-identity-item strong{color:var(--text);font-weight:600;}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600;border:1px solid transparent;}
.badge-township{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.badge-urban{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
.badge-rural{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.badge-industry{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.badge-stage{background:#f8fafc;color:var(--text-muted);border-color:var(--border);}
.badge-spv{background:#f3e8ff;color:#5b21b6;border-color:#c4b5fd;}
/* Tabs */
.main-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none;background:var(--surface);padding:0 2rem;}
.main-tabs::-webkit-scrollbar{display:none;}
.tab-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.9rem 1.1rem;border:none;background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.855rem;font-weight:500;color:var(--text-muted);border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:all var(--transition);white-space:nowrap;}
.tab-btn:hover{color:var(--navy);}
.tab-btn.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
.tab-count{background:var(--surface-2);border:1px solid var(--border);border-radius:99px;font-size:.68rem;font-weight:600;padding:.05rem .45rem;color:var(--text-muted);}
.tab-btn.active .tab-count{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
/* Page body */
.page-body{max-width:1200px;margin:0 auto;padding:1.5rem 2rem 2rem;display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start;}
.main-tab-card{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);box-shadow:var(--shadow);margin-bottom:1.5rem;}
.tab-panels{padding:1.75rem;}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
/* Overview */
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;}
.info-item{display:flex;flex-direction:column;gap:.25rem;}
.info-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);}
.info-value{font-size:.92rem;font-weight:500;color:var(--text);}
.info-value a{color:var(--navy-light);text-decoration:none;}
.info-value a:hover{text-decoration:underline;}
.section-divider{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.6rem;margin:1.5rem 0 1rem;}
.section-divider::after{content:'';flex:1;height:1px;background:var(--border);}
/* Highlights */
.highlights-wrap{display:flex;flex-wrap:wrap;gap:.65rem;}
.highlight-chip{display:inline-flex;flex-direction:column;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:.55rem .9rem;min-width:110px;transition:border-color var(--transition);}
.highlight-chip:hover{border-color:var(--navy-light);}
.highlight-chip .hl-val{font-size:1.05rem;font-weight:700;color:var(--navy);line-height:1;}
.highlight-chip .hl-lbl{font-size:.72rem;color:var(--text-muted);margin-top:.2rem;}
/* Company description */
.company-desc{font-size:.92rem;color:var(--text-muted);line-height:1.7;margin-bottom:1.5rem;}
/* Investment case */
.pitch-section{margin-bottom:1.25rem;}
.pitch-section-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);display:flex;align-items:center;gap:.4rem;margin-bottom:.6rem;}
.pitch-body{font-size:.88rem;color:var(--text-muted);line-height:1.7;white-space:pre-wrap;}
/* Use of funds table */
.uof-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.uof-table th{text-align:left;padding:.5rem .75rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.uof-table td{padding:.6rem .75rem;border-bottom:1px solid var(--border);}
.uof-table tr:last-child td{border-bottom:none;}
.uof-table td:last-child{text-align:right;font-weight:600;color:var(--navy);}
.uof-total-row td{border-top:2px solid var(--border);font-weight:700;color:var(--navy-mid);}
/* Updates feed */
.update-item{padding:.1rem 0 1.25rem;border-bottom:1px solid var(--border);margin-bottom:1.25rem;}
.update-item:last-child{border-bottom:none;margin-bottom:0;padding-bottom:0;}
.update-head{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:.5rem;flex-wrap:wrap;}
.update-title{font-size:.92rem;font-weight:600;color:var(--navy);margin-top:.3rem;}
.update-date{font-size:.73rem;color:var(--text-light);flex-shrink:0;}
.update-body{font-size:.86rem;color:var(--text-muted);line-height:1.65;margin-top:.35rem;white-space:pre-wrap;}
.update-financials{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .9rem;margin-top:.75rem;display:flex;gap:1.25rem;flex-wrap:wrap;}
.uf-item{display:flex;flex-direction:column;}
.uf-label{font-size:.7rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.15rem;}
.uf-value{font-size:.95rem;font-weight:700;color:var(--navy);}
.uf-value.payout-val{color:var(--green);}
/* Update type badges */
.ut-general{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.ut-financial{background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
.ut-milestone{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.ut-payout{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.ut-issue{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.ut-closed{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
/* Q&A */
.qa-item{padding:1rem 0;border-bottom:1px solid var(--border);}
.qa-item:last-child{border-bottom:none;}
.qa-question{font-size:.88rem;font-weight:600;color:var(--text);margin-bottom:.35rem;display:flex;gap:.5rem;}
.qa-question i{color:var(--navy-light);margin-top:.1rem;flex-shrink:0;}
.qa-meta{font-size:.73rem;color:var(--text-light);margin-bottom:.6rem;}
.qa-answer{background:var(--surface-2);border-left:3px solid var(--navy-mid);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:.65rem .85rem;font-size:.85rem;color:var(--text-muted);line-height:1.6;}
.qa-unanswered{font-size:.8rem;color:var(--text-light);font-style:italic;}
.qa-form textarea{width:100%;padding:.7rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;resize:vertical;min-height:80px;transition:border-color var(--transition),box-shadow var(--transition);}
.qa-form textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.1);}
/* Alerts */
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.25rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
.btn-primary{background:var(--navy-mid);color:#fff;}
.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.btn-sm{padding:.4rem .9rem;font-size:.8rem;}
.empty-panel{padding:2.5rem;text-align:center;font-size:.88rem;color:var(--text-light);}
.empty-panel i{font-size:1.75rem;display:block;margin-bottom:.65rem;opacity:.4;}
/* Right sidebar */
.sidebar-col{position:sticky;top:calc(var(--header-h) + 1.5rem);display:flex;flex-direction:column;gap:1.25rem;}
.progress-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
.progress-card-head{background:var(--navy);padding:1.25rem;}
.progress-card-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.5);margin-bottom:.35rem;}
.progress-card-raised{font-family:'DM Serif Display',serif;font-size:1.7rem;color:#fff;line-height:1;margin-bottom:.2rem;}
.progress-card-of{font-size:.78rem;color:rgba(255,255,255,.5);}
.progress-card-body{padding:1.1rem 1.25rem;}
.prog-bar-outer{height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;margin:1rem 0 .45rem;border:1px solid var(--border);}
.prog-bar-inner{height:100%;border-radius:99px;transition:width .5s ease;}
.prog-bar-open{background:var(--amber);}
.prog-bar-funded{background:var(--green);}
.prog-stats{display:flex;justify-content:space-between;font-size:.78rem;color:var(--text-muted);margin-bottom:1rem;}
.camp-stat-row{display:flex;align-items:center;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--border);font-size:.84rem;}
.camp-stat-row:last-of-type{border-bottom:none;}
.camp-stat-label{color:var(--text-muted);}
.camp-stat-value{font-weight:600;color:var(--text);text-align:right;}
.btn-contribute{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.9rem 1rem;margin-top:1.1rem;background:var(--amber);color:var(--navy);border:none;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;text-decoration:none;cursor:pointer;transition:all var(--transition);box-shadow:0 4px 14px rgba(245,158,11,.3);}
.btn-contribute:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
.btn-days-left{font-size:.76rem;color:var(--text-light);text-align:center;margin-top:.5rem;}
.btn-company-link{display:flex;align-items:center;justify-content:center;gap:.4rem;width:100%;padding:.65rem 1rem;margin-top:.65rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all var(--transition);background:var(--surface-2);}
.btn-company-link:hover{border-color:var(--navy-light);color:var(--navy-mid);background:#eff4ff;}
/* Terms card */
.terms-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.terms-card-head{background:var(--surface-2);padding:.75rem 1.1rem;border-bottom:1px solid var(--border);font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.4rem;}
.terms-card-body{padding:.85rem 1.1rem;}
.terms-row{display:flex;justify-content:space-between;align-items:baseline;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.83rem;gap:.5rem;}
.terms-row:last-child{border-bottom:none;}
.terms-label{color:var(--text-muted);flex-shrink:0;}
.terms-value{font-weight:600;color:var(--text);text-align:right;}
.terms-value.highlight{color:var(--navy-mid);font-size:.92rem;}
/* Spots bar */
.spots-bar{margin-top:1rem;}
.spots-label{display:flex;justify-content:space-between;font-size:.76rem;color:var(--text-muted);margin-bottom:.4rem;}
.spots-outer{height:5px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);}
.spots-inner{height:100%;background:var(--navy-light);border-radius:99px;transition:width .5s ease;}
/* Legal note */
.legal-note{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.75rem .9rem;font-size:.76rem;color:#78350f;line-height:1.55;display:flex;gap:.5rem;align-items:flex-start;}
.legal-note i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}
/* Meta card */
.meta-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1rem 1.1rem;}
.meta-row{display:flex;gap:.5rem;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.83rem;}
.meta-row:last-child{border-bottom:none;}
.meta-label{color:var(--text-muted);min-width:90px;flex-shrink:0;display:flex;align-items:center;gap:.4rem;}
.meta-label i{color:var(--navy-light);font-size:.78rem;width:14px;text-align:center;}
.meta-value{color:var(--text);font-weight:500;word-break:break-word;}
/* Responsive */
@media(max-width:1024px){.page-body{grid-template-columns:1fr 300px;padding:0 1.5rem 2rem;gap:1.5rem;}.campaign-hero{padding:0 1.5rem;}.back-bar{padding:.75rem 1.5rem;}.header-nav{display:none;}}
@media(max-width:900px){.sidebar{display:none;}}
@media(max-width:768px){.page-body{grid-template-columns:1fr;padding:0 1rem 1.5rem;}.sidebar-col{position:static;order:-1;}.campaign-hero{padding:0 1rem;}.back-bar{padding:.65rem 1rem;}.hero-campaign-title{font-size:1.4rem;}.main-tabs{padding:0 1rem;}}
@media(max-width:480px){.hero-banner{height:160px;}.hero-logo-wrap{width:52px;height:52px;}}
@media screen and (min-width:1024px){.header-dropdown{display:none !important;}}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<!-- ═══ HEADER ═══ -->
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
    <div class="avatar" id="AvatarBtn"><?= htmlspecialchars($ini) ?></div>
    <div class="header-dropdown" id="UserDropdown" role="menu">
        <div class="header-dropdown-inner">
            <div class="header-section-label">Dashboard</div>
            <a href="/app/" class="header-dropdown-link"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
            <a href="/app/?tab=payouts" class="header-dropdown-link"><i class="fa-solid fa-coins"></i> Payouts</a>
            <div class="header-section-label">Discover</div>
            <a href="/app/discover/" class="header-dropdown-link active"><i class="fa-solid fa-compass"></i> Browse Campaigns</a>
            <div class="header-section-label">Account</div>
            <a href="/app/wallet/" class="header-dropdown-link"><i class="fa-solid fa-wallet"></i> Wallet</a>
            <a href="/app/profile.php" class="header-dropdown-link"><i class="fa-solid fa-user"></i> Profile</a>
            <div class="header-divider"></div>
            <a href="/app/auth/logout.php" class="header-dropdown-link danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<!-- ═══ PAGE WRAPPER ═══ -->
<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Dashboard</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/" class="active"><i class="fa-solid fa-compass"></i> Browse Campaigns</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <div class="main-content">

        <div class="back-bar">
            <a href="/app/discover/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Discover</a>
        </div>

        <!-- ═══ CAMPAIGN HERO ═══ -->
        <div class="campaign-hero">
            <div class="hero-card">
                <!-- Banner: SPV banner → company banner → gradient -->
                <div class="hero-banner" <?php if ($displayBanner): ?>style="background-image:url('<?= htmlspecialchars($displayBanner) ?>')"<?php endif; ?>>
                    <div class="hero-banner-overlay"></div>
                    <div class="hero-type-ribbon <?= $ctInfo[2] ?>">
                        <i class="fa-solid <?= $ctInfo[1] ?>"></i> <?= $ctInfo[0] ?>
                    </div>
                    <div class="hero-status-ribbon <?= $isFunded ? 'ribbon-funded' : 'ribbon-open' ?>">
                        <i class="fa-solid <?= $isFunded ? 'fa-circle-check' : 'fa-rocket' ?>" style="font-size:.7rem;"></i>
                        <?= $isFunded ? 'Funded' : ($daysLeft > 0 ? $daysLeft . ' days left' : 'Closing today') ?>
                    </div>
                    <!-- Logo: SPV logo → company logo -->
                    <div class="hero-logo-wrap">
                        <?php if ($displayLogo): ?>
                            <img src="<?= htmlspecialchars($displayLogo) ?>" alt="<?= htmlspecialchars($campaign['company_name']) ?>">
                        <?php else: ?>
                            <div class="hero-logo-ph"><?= strtoupper(substr($campaign['company_name'], 0, 1)) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Identity -->
                <div class="hero-identity">
                    <div class="hero-title-area">
                        <div class="hero-company-name">
                            <i class="fa-solid fa-building" style="font-size:.72rem;color:var(--text-light);"></i>
                            <a href="/app/discover/company.php?uuid=<?= urlencode($campaign['company_uuid']) ?>" style="color:var(--text-muted);text-decoration:none;"><?= htmlspecialchars($campaign['company_name']) ?></a>
                        </div>
                        <h1 class="hero-campaign-title"><?= htmlspecialchars($campaign['title']) ?></h1>
                        <?php if ($campaign['tagline']): ?>
                            <div class="hero-tagline"><?= htmlspecialchars($campaign['tagline']) ?></div>
                        <?php endif; ?>
                        <div class="hero-badges">
                            <?php if ($campaign['industry']): ?>
                                <span class="badge badge-industry"><?= htmlspecialchars($campaign['industry']) ?></span>
                            <?php endif; ?>
                            <?php if ($displayArea): ?>
                                <span class="badge badge-<?= htmlspecialchars($displayArea) ?>"><?= $areaLabels[$displayArea] ?? '' ?></span>
                            <?php endif; ?>
                            <?php if (!empty($displayCity) || !empty($displayProvince)): ?>
                                <span class="badge badge-stage">
                                    <i class="fa-solid fa-location-dot" style="font-size:.7rem;"></i>
                                    <?= htmlspecialchars(implode(', ', array_filter([$displayCity, $displayProvince]))) ?>
                                </span>
                            <?php endif; ?>
                            <?php if ($campaign['spv_registration_number']): ?>
                                <span class="badge badge-spv"><i class="fa-solid fa-building" style="font-size:.7rem;"></i> SPV Entity</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- SPV legal identity strip -->
                <?php if ($campaign['spv_registered_name'] || $campaign['spv_registration_number']): ?>
                <div class="spv-identity-strip">
                    <?php if ($campaign['spv_registered_name']): ?>
                    <div class="spv-identity-item">
                        <i class="fa-solid fa-file-signature"></i>
                        <strong><?= htmlspecialchars($campaign['spv_registered_name']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($campaign['spv_registration_number']): ?>
                    <div class="spv-identity-item">
                        <i class="fa-solid fa-hashtag"></i>
                        Reg. No. <strong><?= htmlspecialchars($campaign['spv_registration_number']) ?></strong>
                    </div>
                    <?php endif; ?>
                    <?php if ($campaign['spv_email']): ?>
                    <div class="spv-identity-item">
                        <i class="fa-solid fa-envelope"></i>
                        <a href="mailto:<?= htmlspecialchars($campaign['spv_email']) ?>" style="color:inherit;"><?= htmlspecialchars($campaign['spv_email']) ?></a>
                    </div>
                    <?php endif; ?>
                    <?php if ($campaign['spv_website']): ?>
                    <div class="spv-identity-item">
                        <i class="fa-solid fa-globe"></i>
                        <a href="<?= htmlspecialchars($campaign['spv_website']) ?>" target="_blank" rel="noopener" style="color:inherit;"><?= htmlspecialchars(parse_url($campaign['spv_website'], PHP_URL_HOST)) ?></a>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <!-- Tabs -->
                <div class="main-tabs" id="mainTabs">
                    <button class="tab-btn active" onclick="switchTab('overview', this)">
                        <i class="fa-solid fa-circle-info"></i> Overview
                    </button>
                    <?php if (!empty($pitch['investment_thesis']) || !empty($useOfFunds) || !empty($pitch['spv_team_overview'])): ?>
                    <button class="tab-btn" onclick="switchTab('pitch', this)">
                        <i class="fa-solid fa-briefcase"></i> Investment Case
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($updates)): ?>
                    <button class="tab-btn" onclick="switchTab('updates', this)">
                        <i class="fa-solid fa-newspaper"></i> Updates
                        <span class="tab-count"><?= count($updates) ?></span>
                    </button>
                    <?php endif; ?>
                    <button class="tab-btn" onclick="switchTab('qa', this)" id="qaTabBtn">
                        <i class="fa-solid fa-comments"></i> Q&amp;A
                        <span class="tab-count"><?= count($questions) ?></span>
                    </button>
                </div>
            </div>
        </div>

        <!-- ═══ PAGE BODY ═══ -->
        <div class="page-body">
            <div class="main-col">
                <div class="main-tab-card">
                    <div class="tab-panels">

                        <!-- ── OVERVIEW ── -->
                        <div class="tab-panel active" data-panel="overview">
                            <?php if ($displayDescription): ?>
                                <p class="company-desc"><?= nl2br(htmlspecialchars($displayDescription)) ?></p>
                            <?php endif; ?>

                            <div class="info-grid">
                                <?php if ($displayCity || $displayProvince): ?>
                                <div class="info-item"><span class="info-label">SPV Location</span><span class="info-value"><?= htmlspecialchars(implode(', ', array_filter([$displayCity, $displayProvince]))) ?></span></div>
                                <?php endif; ?>
                                <?php if ($campaign['spv_registration_number']): ?>
                                <div class="info-item"><span class="info-label">CIPC Reg. No.</span><span class="info-value"><?= htmlspecialchars($campaign['spv_registration_number']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($campaign['industry']): ?>
                                <div class="info-item"><span class="info-label">Industry</span><span class="info-value"><?= htmlspecialchars($campaign['industry']) ?></span></div>
                                <?php endif; ?>
                                <?php if ($campaign['company_website']): ?>
                                <div class="info-item"><span class="info-label">Parent Website</span><span class="info-value"><a href="<?= htmlspecialchars($campaign['company_website']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars($campaign['company_website']) ?></a></span></div>
                                <?php endif; ?>
                            </div>

                            <?php if (!empty($highlights)): ?>
                                <div class="section-divider">
                                    Key Highlights
                                    <?php if ($highlightSource === 'company'): ?>
                                    <span style="font-size:.7rem;font-weight:400;letter-spacing:0;text-transform:none;color:var(--text-light);">(from parent company)</span>
                                    <?php endif; ?>
                                </div>
                                <div class="highlights-wrap">
                                    <?php foreach ($highlights as $hl): ?>
                                        <div class="highlight-chip">
                                            <span class="hl-val"><?= htmlspecialchars($hl['value']) ?></span>
                                            <span class="hl-lbl"><?= htmlspecialchars($hl['label']) ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <div class="section-divider">About this Campaign</div>
                            <div class="info-grid" style="margin-bottom:0;">
                                <div class="info-item"><span class="info-label">Opens</span><span class="info-value"><?= cam_date($campaign['opens_at']) ?></span></div>
                                <div class="info-item"><span class="info-label">Closes</span><span class="info-value"><?= cam_date($campaign['closes_at']) ?></span></div>
                                <div class="info-item"><span class="info-label">Raise Target</span><span class="info-value"><?= cam_money($campaign['raise_target']) ?></span></div>
                                <div class="info-item"><span class="info-label">Minimum Raise</span><span class="info-value"><?= cam_money($campaign['raise_minimum']) ?></span></div>
                                <div class="info-item"><span class="info-label">Min. Investment</span><span class="info-value"><?= cam_money($campaign['min_contribution']) ?></span></div>
                                <?php if ($campaign['max_contribution']): ?>
                                <div class="info-item"><span class="info-label">Max. Investment</span><span class="info-value"><?= cam_money($campaign['max_contribution']) ?></span></div>
                                <?php endif; ?>
                                <div class="info-item"><span class="info-label">Spots Left</span><span class="info-value"><?= $spotsLeft ?> of <?= (int)$campaign['max_contributors'] ?></span></div>
                            </div>

                            <div style="margin-top:1.5rem;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;font-size:.82rem;color:var(--text-muted);line-height:1.6;">
                                <strong style="color:var(--text);display:flex;align-items:center;gap:.4rem;margin-bottom:.35rem;"><i class="fa-solid fa-shield-halved" style="color:var(--navy-light);"></i> Risk &amp; Refund Policy</strong>
                                If this SPV does not reach its minimum raise of <strong><?= cam_money($campaign['raise_minimum']) ?></strong>, all contributions are refunded in full. Investing in early-stage businesses carries risk — returns are not guaranteed. This campaign operates under a private placement exemption (max <?= (int)$campaign['max_contributors'] ?> contributors).
                            </div>
                        </div>

                        <!-- ── INVESTMENT CASE ── -->
                        <?php if (!empty($pitch['investment_thesis']) || !empty($useOfFunds) || !empty($pitch['spv_team_overview'])): ?>
                        <div class="tab-panel" data-panel="pitch">

                            <?php if ($pitch['investment_thesis']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-lightbulb"></i> Investment Thesis</div>
                                <div class="pitch-body"><?= htmlspecialchars($pitch['investment_thesis']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($useOfFunds)): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-sack-dollar"></i> Use of Funds</div>
                                <?php
                                $uofTotal = array_sum(array_column($useOfFunds, 'amount'));
                                ?>
                                <table class="uof-table">
                                    <thead><tr><th>Category</th><th style="text-align:right;">Amount</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($useOfFunds as $uof): ?>
                                    <tr>
                                        <td><?= htmlspecialchars($uof['label']) ?></td>
                                        <td><?= $uof['amount'] ? cam_money($uof['amount']) : '—' ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                    <?php if ($uofTotal > 0): ?>
                                    <tfoot><tr class="uof-total-row"><td><strong>Total</strong></td><td><strong><?= cam_money($uofTotal) ?></strong></td></tr></tfoot>
                                    <?php endif; ?>
                                </table>
                            </div>
                            <?php endif; ?>

                            <?php if ($pitch['risk_factors']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-shield-halved"></i> Risk Factors</div>
                                <div class="pitch-body"><?= htmlspecialchars($pitch['risk_factors']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($pitch['exit_strategy']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-door-open"></i> Exit / Return Strategy</div>
                                <div class="pitch-body"><?= htmlspecialchars($pitch['exit_strategy']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($pitch['spv_traction']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-rocket"></i> Traction</div>
                                <div class="pitch-body"><?= htmlspecialchars($pitch['spv_traction']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($pitch['spv_team_overview']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-people-group"></i> SPV Team</div>
                                <div class="pitch-body"><?= htmlspecialchars($pitch['spv_team_overview']) ?></div>
                            </div>
                            <?php endif; ?>

                            <?php if ($pitch['pitch_deck_url'] || $pitch['pitch_video_url']): ?>
                            <div class="pitch-section">
                                <div class="pitch-section-label"><i class="fa-solid fa-photo-film"></i> Pitch Assets</div>
                                <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                                    <?php if ($pitch['pitch_deck_url']): ?>
                                    <a href="<?= htmlspecialchars($pitch['pitch_deck_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm"><i class="fa-solid fa-file-pdf"></i> Download Pitch Deck</a>
                                    <?php endif; ?>
                                    <?php if ($pitch['pitch_video_url']): ?>
                                    <a href="<?= htmlspecialchars($pitch['pitch_video_url']) ?>" target="_blank" rel="noopener" class="btn btn-primary btn-sm"><i class="fa-brands fa-youtube"></i> Watch Pitch Video</a>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                        </div>
                        <?php endif; ?>

                        <!-- ── UPDATES ── -->
                        <?php if (!empty($updates)): ?>
                        <div class="tab-panel" data-panel="updates">
                            <?php foreach ($updates as $upd):
                                $utInfo = $updateTypeConfig[$upd['update_type']] ?? ['Update','fa-newspaper','ut-general'];
                                $period = '';
                                if ($upd['period_month'] && $upd['period_year']) {
                                    $period = ($monthNames[(int)$upd['period_month']] ?? '') . ' ' . $upd['period_year'];
                                }
                            ?>
                            <div class="update-item">
                                <div class="update-head">
                                    <div>
                                        <div style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;">
                                            <span class="badge <?= $utInfo[2] ?>"><i class="fa-solid <?= $utInfo[1] ?>"></i><?= $utInfo[0] ?></span>
                                            <?php if ($period): ?><span class="badge badge-stage"><?= htmlspecialchars($period) ?></span><?php endif; ?>
                                        </div>
                                        <div class="update-title"><?= htmlspecialchars($upd['title']) ?></div>
                                    </div>
                                    <div class="update-date"><?= cam_date($upd['published_at']) ?></div>
                                </div>
                                <div class="update-body"><?= htmlspecialchars($upd['body']) ?></div>
                                <?php
                                $hasFinancials = $upd['revenue_this_period'] !== null || $upd['expenses_this_period'] !== null || $upd['payout_amount'] !== null;
                                if ($hasFinancials): ?>
                                <div class="update-financials">
                                    <?php if ($upd['revenue_this_period'] !== null): ?><div class="uf-item"><span class="uf-label">Revenue</span><span class="uf-value"><?= cam_money($upd['revenue_this_period']) ?></span></div><?php endif; ?>
                                    <?php if ($upd['expenses_this_period'] !== null): ?><div class="uf-item"><span class="uf-label">Expenses</span><span class="uf-value"><?= cam_money($upd['expenses_this_period']) ?></span></div><?php endif; ?>
                                    <?php if ($upd['payout_amount'] !== null): ?><div class="uf-item"><span class="uf-label">Total Payout</span><span class="uf-value payout-val"><?= cam_money($upd['payout_amount']) ?></span></div><?php endif; ?>
                                    <?php if ($upd['payout_per_contributor'] !== null): ?><div class="uf-item"><span class="uf-label">Per Investor</span><span class="uf-value payout-val"><?= cam_money($upd['payout_per_contributor']) ?></span></div><?php endif; ?>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php endif; ?>

                        <!-- ── Q&A ── -->
                        <div class="tab-panel" data-panel="qa">
                            <?php if ($qSuccess): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?= htmlspecialchars($qSuccess) ?></div><?php endif; ?>
                            <?php if ($qError): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?= htmlspecialchars($qError) ?></div><?php endif; ?>
                            <?php if (empty($questions)): ?>
                                <div class="empty-panel"><i class="fa-regular fa-comments"></i>No questions yet. Be the first to ask.</div>
                            <?php else: ?>
                                <?php foreach ($questions as $q): ?>
                                <div class="qa-item">
                                    <div class="qa-question"><i class="fa-solid fa-circle-question"></i><?= htmlspecialchars($q['question']) ?></div>
                                    <div class="qa-meta">Asked by <?= htmlspecialchars(cam_mask_email($q['asker_email'])) ?> &middot; <?= cam_date($q['asked_at']) ?></div>
                                    <?php if ($q['answer']): ?>
                                        <div class="qa-answer">
                                            <strong style="font-size:.75rem;text-transform:uppercase;letter-spacing:.06em;color:var(--navy-light);display:block;margin-bottom:.25rem;"><i class="fa-solid fa-building"></i> <?= htmlspecialchars($campaign['company_name']) ?></strong>
                                            <?= htmlspecialchars($q['answer']) ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="qa-unanswered">Awaiting response from the company.</div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div style="margin-top:1.25rem;border-top:1px solid var(--border);padding-top:1.25rem;">
                                <form method="POST" class="qa-form">
                                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
                                    <textarea name="question" placeholder="Ask the company a question about this campaign…" rows="3" maxlength="1000"></textarea>
                                    <div style="margin-top:.6rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                                        <span style="font-size:.75rem;color:var(--text-light);">Questions are reviewed before being made public.</span>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Submit Question</button>
                                    </div>
                                </form>
                            </div>
                        </div>

                    </div>
                </div>
            </div>

            <!-- ── RIGHT SIDEBAR ── -->
            <div class="sidebar-col">

                <!-- Progress & contribute -->
                <div class="progress-card">
                    <div class="progress-card-head">
                        <div class="progress-card-label">Total Raised</div>
                        <div class="progress-card-raised"><?= cam_money($raised) ?></div>
                        <div class="progress-card-of">of <?= cam_money($target) ?> target</div>
                    </div>
                    <div class="progress-card-body">
                        <div class="prog-bar-outer">
                            <div class="prog-bar-inner <?= $isFunded ? 'prog-bar-funded' : 'prog-bar-open' ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                        <div class="prog-stats">
                            <span><?= $pct ?>% funded</span>
                            <span><?= (int)$campaign['contributor_count'] ?> / <?= (int)$campaign['max_contributors'] ?> investors</span>
                        </div>
                        <!-- Spots -->
                        <div class="spots-bar">
                            <div class="spots-label"><span>Investor spots</span><span><strong><?= $spotsLeft ?></strong> remaining</span></div>
                            <div class="spots-outer">
                                <div class="spots-inner" style="width:<?= max(0, round(((int)$campaign['max_contributors'] - $spotsLeft) / (int)$campaign['max_contributors'] * 100)) ?>%"></div>
                            </div>
                        </div>
                        <div style="border-top:1px solid var(--border);padding-top:.85rem;margin-top:.85rem;">
                            <div class="camp-stat-row"><span class="camp-stat-label">Min. investment</span><span class="camp-stat-value"><?= cam_money($campaign['min_contribution']) ?></span></div>
                            <?php if ($campaign['max_contribution']): ?>
                            <div class="camp-stat-row"><span class="camp-stat-label">Max. investment</span><span class="camp-stat-value"><?= cam_money($campaign['max_contribution']) ?></span></div>
                            <?php endif; ?>
                            <div class="camp-stat-row"><span class="camp-stat-label">Closes</span><span class="camp-stat-value"><?= cam_date($campaign['closes_at']) ?></span></div>
                            <div class="camp-stat-row"><span class="camp-stat-label">Minimum raise</span><span class="camp-stat-value"><?= cam_money($campaign['raise_minimum']) ?></span></div>
                        </div>
                        <a href="<?= htmlspecialchars($contributeUrl) ?>" class="btn-contribute">
                            <i class="fa-solid fa-hand-holding-dollar"></i> Invest Now
                        </a>
                        <?php if ($daysLeft > 0): ?>
                        <div class="btn-days-left"><i class="fa-regular fa-clock"></i> <?= $daysLeft ?> day<?= $daysLeft !== 1 ? 's' : '' ?> left to invest</div>
                        <?php endif; ?>
                        <a href="/app/discover/company.php?uuid=<?= urlencode($campaign['company_uuid']) ?>" class="btn-company-link">
                            <i class="fa-solid fa-building"></i> View Parent Company
                        </a>
                    </div>
                </div>

                <!-- Deal terms -->
                <div class="terms-card">
                    <div class="terms-card-head"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
                    <div class="terms-card-body">
                        <?php if ($campaign['campaign_type'] === 'revenue_share'): ?>
                            <div class="terms-row"><span class="terms-label">Type</span><span class="terms-value">Revenue Share</span></div>
                            <div class="terms-row"><span class="terms-label">Monthly share</span><span class="terms-value highlight"><?= htmlspecialchars((string)$campaign['revenue_share_percentage']) ?>% of revenue</span></div>
                            <div class="terms-row"><span class="terms-label">Duration</span><span class="terms-value highlight"><?= htmlspecialchars((string)$campaign['revenue_share_duration_months']) ?> months</span></div>
                            <div class="terms-row"><span class="terms-label">Governing law</span><span class="terms-value"><?= htmlspecialchars($campaign['governing_law'] ?? 'Republic of South Africa') ?></span></div>
                        <?php elseif ($campaign['campaign_type'] === 'cooperative_membership'): ?>
                            <div class="terms-row"><span class="terms-label">Type</span><span class="terms-value">Co-op Membership</span></div>
                            <div class="terms-row"><span class="terms-label">Unit name</span><span class="terms-value highlight"><?= htmlspecialchars($campaign['unit_name'] ?? '—') ?></span></div>
                            <div class="terms-row"><span class="terms-label">Price / unit</span><span class="terms-value highlight"><?= cam_money($campaign['unit_price']) ?></span></div>
                            <div class="terms-row"><span class="terms-label">Units available</span><span class="terms-value"><?= (int)$campaign['total_units_available'] ?></span></div>
                            <div class="terms-row"><span class="terms-label">Governing law</span><span class="terms-value"><?= htmlspecialchars($campaign['governing_law'] ?? 'Republic of South Africa') ?></span></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Legal note -->
                <div class="legal-note">
                    <i class="fa-solid fa-scale-balanced"></i>
                    <div>Contributions are limited to a maximum of <strong><?= (int)$campaign['max_contributors'] ?> investors</strong> per SPV under South African private placement regulations. If the minimum raise of <strong><?= cam_money($campaign['raise_minimum']) ?></strong> is not reached, all contributions are refunded in full.</div>
                </div>

                <!-- SPV meta -->
                <div class="meta-card">
                    <?php if ($campaign['spv_registered_name']): ?>
                    <div class="meta-row"><span class="meta-label"><i class="fa-solid fa-file-signature"></i> SPV Name</span><span class="meta-value" style="font-size:.8rem;"><?= htmlspecialchars($campaign['spv_registered_name']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($campaign['spv_registration_number']): ?>
                    <div class="meta-row"><span class="meta-label"><i class="fa-solid fa-hashtag"></i> CIPC Reg.</span><span class="meta-value"><?= htmlspecialchars($campaign['spv_registration_number']) ?></span></div>
                    <?php endif; ?>
                    <?php if ($campaign['industry']): ?>
                    <div class="meta-row"><span class="meta-label"><i class="fa-solid fa-industry"></i> Industry</span><span class="meta-value"><?= htmlspecialchars($campaign['industry']) ?></span></div>
                    <?php endif; ?>
                    <?php if (!empty($displayCity) || !empty($displayProvince)): ?>
                    <div class="meta-row"><span class="meta-label"><i class="fa-solid fa-location-dot"></i> Address</span><span class="meta-value"><?= htmlspecialchars(implode(', ', array_filter([$displayCity, $displayProvince]))) ?><?= $sameAddr ? ' <span style="font-size:.73rem;color:var(--text-light);">(parent)</span>' : '' ?></span></div>
                    <?php endif; ?>
                </div>

            </div>
        </div>
    </div>
</div>

<script>
function switchTab(panel, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const el = document.querySelector('[data-panel="' + panel + '"]');
    if (el) el.classList.add('active');
}
document.addEventListener('DOMContentLoaded', () => {
    if (window.location.hash === '#qa') {
        const b = document.getElementById('qaTabBtn');
        if (b) switchTab('qa', b);
    }
});
(function() {
    const btn = document.getElementById('AvatarBtn');
    const dd  = document.getElementById('UserDropdown');
    function close() { if(dd) dd.classList.remove('header-dropdown--open'); }
    function toggle() {
        if (window.innerWidth >= 1024) { close(); return; }
        dd.classList.contains('header-dropdown--open') ? close() : dd.classList.add('header-dropdown--open');
    }
    if (btn) btn.addEventListener('click', e => { e.stopPropagation(); toggle(); });
    document.addEventListener('click', e => { if(dd && !dd.contains(e.target) && btn && !btn.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if(e.key === 'Escape') close(); });
    window.addEventListener('resize', () => { if(window.innerWidth >= 1024) close(); });
})();
</script>
</body>
</html>
