<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InviteService.php'; // US-101

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/discover/company.php?uuid=' . urlencode($_GET['uuid'] ?? '')));
}

$uuid = trim($_GET['uuid'] ?? '');
if (empty($uuid)) { redirect('/app/discover/'); }

$pdo    = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

/* ── Company ─────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT c.*, cf.province, cf.municipality, cf.city, cf.suburb, cf.area
    FROM companies c
    LEFT JOIN company_filter cf ON cf.company_id = c.id
    WHERE c.uuid = :uuid AND c.status = 'active' AND c.verified = 1
");
$stmt->execute(['uuid' => $uuid]);
$company = $stmt->fetch();
if (!$company) { redirect('/app/discover/'); }
$companyId = $company['id'];

/* ── Pitch (company-level; no use_of_funds here) ── */
$stmt = $pdo->prepare("SELECT * FROM company_pitch WHERE company_id = ?");
$stmt->execute([$companyId]);
$pitch = $stmt->fetch();

/* ── Highlights ─────────────────────────────── */
$stmt = $pdo->prepare("SELECT label, value FROM pitch_highlights WHERE company_id = ? ORDER BY sort_order ASC");
$stmt->execute([$companyId]);
$highlights = $stmt->fetchAll();

/* ── Financials (last 4) ─────────────────────── */
$stmt = $pdo->prepare("
    SELECT period_year, period_month, revenue, gross_profit, net_profit, disclosure_type
    FROM company_financials
    WHERE company_id = ?
    ORDER BY period_year DESC, COALESCE(period_month, 0) DESC
    LIMIT 4
");
$stmt->execute([$companyId]);
$financials = $stmt->fetchAll();

/* ── Milestones (public) ─────────────────────── */
$stmt = $pdo->prepare("
    SELECT milestone_date, title, description
    FROM company_milestones
    WHERE company_id = ? AND is_public = 1
    ORDER BY milestone_date DESC, sort_order ASC
    LIMIT 8
");
$stmt->execute([$companyId]);
$milestones = $stmt->fetchAll();

/* ── ALL active campaigns (SPVs) for this company ─ */
/* Each campaign is its own SPV; a company can have many over time.
   We show open+funded ones here. use_of_funds is per-campaign. */
$stmt = $pdo->prepare("
    SELECT
        fc.id, fc.uuid AS campaign_uuid, fc.title, fc.tagline,
        fc.campaign_type, fc.status,
        fc.raise_target, fc.raise_minimum, fc.raise_maximum,
        fc.min_contribution, fc.max_contribution, fc.max_contributors,
        fc.total_raised, fc.contributor_count,
        fc.opens_at, fc.closes_at,
        fc.use_of_funds,
        ct.revenue_share_percentage,
        ct.revenue_share_duration_months,
        ct.unit_name, ct.unit_price, ct.total_units_available,
        ct.fixed_return_rate, ct.loan_term_months,
        ct.governing_law
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    WHERE fc.company_id = :cid
      AND fc.status IN ('open','funded')
    ORDER BY fc.id DESC
");
$stmt->execute(['cid' => $companyId]);
$campaigns = $stmt->fetchAll();

// US-101 — Batch invite check for this user across all campaigns on this page.
// $invitedSet is used in the sidebar template to conditionally render CTAs.
$_invitedIds  = InviteService::acceptedCampaignIds(
    $userId,
    array_map(fn($c) => (int)$c['id'], $campaigns)
);
$invitedSet = array_flip($_invitedIds); // O(1) lookup: isset($invitedSet[$cam['id']])

/* ── Q&A — load for the most recent campaign (if any) ── */
$qCampaign = $campaigns[0] ?? null;
$questions  = [];
$csrf_token = generateCSRFToken();
$qError = $qSuccess = '';

if ($qCampaign) {
    $stmt = $pdo->prepare("
        SELECT cq.id, cq.question, cq.asked_at, cq.answer, cq.answered_at,
               u.email AS asker_email
        FROM campaign_questions cq
        JOIN users u ON u.id = cq.asked_by
        WHERE cq.campaign_id = :cid AND cq.is_public = 1
        ORDER BY cq.asked_at DESC
    ");
    $stmt->execute(['cid' => $qCampaign['id']]);
    $questions = $stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
            $qError = 'Invalid security token. Please refresh and try again.';
        } else {
            $q = trim($_POST['question'] ?? '');
            if ($q === '') {
                $qError = 'Please enter a question.';
            } elseif (mb_strlen($q) > 1000) {
                $qError = 'Question is too long (max 1 000 characters).';
            } else {
                $pdo->prepare("INSERT INTO campaign_questions (campaign_id, asked_by, question, asked_at) VALUES (:cid, :uid, :q, NOW())")
                    ->execute(['cid' => $qCampaign['id'], 'uid' => $userId, 'q' => $q]);
                $qSuccess = 'Your question has been submitted. The company will respond publicly.';
                $stmt = $pdo->prepare("SELECT cq.id, cq.question, cq.asked_at, cq.answer, cq.answered_at, u.email AS asker_email FROM campaign_questions cq JOIN users u ON u.id = cq.asked_by WHERE cq.campaign_id = :cid AND cq.is_public = 1 ORDER BY cq.asked_at DESC");
                $stmt->execute(['cid' => $qCampaign['id']]);
                $questions = $stmt->fetchAll();
            }
        }
    }
}

/* ── User info ───────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

/* ── Helpers ─────────────────────────────────── */
function pub_money($v) { return ($v === null || $v === '') ? '—' : 'R ' . number_format((float)$v, 0, '.', ' '); }
function pub_date($v)  { return $v ? date('d M Y', strtotime($v)) : '—'; }
function days_left($d) { return max(0, (int)ceil((strtotime($d) - time()) / 86400)); }
function mask_email($e) {
    $p = explode('@', $e);
    if (count($p) !== 2) return 'Member';
    return substr($p[0], 0, 1) . str_repeat('*', max(2, mb_strlen($p[0]) - 1)) . '@' . $p[1];
}

$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$disclosureLabels = [
    'self_reported'       => ['Self-Reported',       'dl-self'],
    'accountant_verified' => ['Accountant Verified', 'dl-acct'],
    'audited'             => ['Audited',              'dl-audit'],
];
$typeLabels = ['startup'=>'Startup','sme'=>'SME','corporation'=>'Corporation','ngo'=>'NGO','cooperative'=>'Cooperative','social_enterprise'=>'Social Enterprise','other'=>'Other'];
$campaignTypeInfo = [
    'revenue_share'          => ['Revenue Share',     'fa-chart-line',          'ct-rs'],
    'cooperative_membership' => ['Co-op Membership',  'fa-people-roof',         'ct-co'],
    'fixed_return_loan'      => ['Fixed Return Loan', 'fa-hand-holding-dollar', 'ct-loan'],
    'donation'               => ['Donation',          'fa-heart',               'ct-don'],
    'convertible_note'       => ['Convertible Note',  'fa-rotate',              'ct-cn'],
];
$areaLabels = ['urban'=>'Urban','township'=>'Township','rural'=>'Rural'];

$banner = !empty($company['banner']) ? htmlspecialchars($company['banner']) : '';
$logo   = !empty($company['logo'])   ? htmlspecialchars($company['logo'])   : '';

// Pitch sections (use_of_funds is no longer here — it lives on each campaign)
$pitchSections = [];
if ($pitch) {
    foreach ([
        ['problem_statement',    'The Problem',           'fa-triangle-exclamation'],
        ['solution',             'Our Solution',          'fa-lightbulb'],
        ['business_model',       'Business Model',        'fa-coins'],
        ['traction',             'Traction & Milestones', 'fa-rocket'],
        ['target_market',        'Target Market',         'fa-bullseye'],
        ['competitive_landscape','Competitive Landscape', 'fa-chess-knight'],
        ['team_overview',        'The Team',              'fa-people-group'],
        ['risks_and_challenges', 'Risks & Challenges',    'fa-shield-halved'],
    ] as [$field, $label, $icon]) {
        if (!empty($pitch[$field])) {
            $pitchSections[] = ['text' => $pitch[$field], 'label' => $label, 'icon' => $icon];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($company['name']); ?> | Old Union</title>
<meta name="description" content="<?php echo htmlspecialchars(substr(strip_tags($company['description'] ?? ''), 0, 160)); ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
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
.main-content{flex:1;min-width:0;}
/* Back bar */
.back-bar{padding:.85rem 2rem;}
.back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.83rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
.back-link:hover{color:var(--navy);}
/* Hero */
.company-hero{padding:0 2rem;}
.hero-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;margin-bottom:1.5rem;}
.hero-banner{height:200px;background:linear-gradient(135deg,var(--navy-mid) 0%,var(--navy) 100%);background-size:cover;background-position:center;position:relative;}
.hero-banner-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 35%,rgba(11,37,69,.6) 100%);}
.hero-identity{display:flex;align-items:flex-end;gap:1.25rem;padding:0 1.75rem 1.5rem;margin-top:-44px;position:relative;z-index:2;flex-wrap:wrap;}
.hero-logo-wrap{width:88px;height:88px;border-radius:14px;border:3px solid var(--surface);background:var(--surface-2);box-shadow:0 4px 14px rgba(0,0,0,.14);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
.hero-logo-wrap img{width:100%;height:100%;object-fit:cover;}
.hero-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:2.2rem;color:#fff;}
.hero-title-area{flex:1;min-width:200px;padding-bottom:.2rem;}
.hero-name{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);line-height:1.2;margin-bottom:.5rem;}
.hero-meta{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600;border:1px solid transparent;}
.badge-verified {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.badge-industry {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.badge-township {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.badge-urban    {background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
.badge-rural    {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.badge-type     {background:#f8fafc;color:var(--text-muted);border-color:var(--border);}
/* Tabs */
.main-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none;background:var(--surface);padding:0 1.25rem;}
.main-tabs::-webkit-scrollbar{display:none;}
.tab-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.9rem 1.1rem;border:none;background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.855rem;font-weight:500;color:var(--text-muted);border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:all var(--transition);white-space:nowrap;}
.tab-btn:hover{color:var(--navy);}.tab-btn.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
.tab-count{background:var(--surface-2);border:1px solid var(--border);border-radius:99px;font-size:.68rem;font-weight:600;padding:.05rem .45rem;color:var(--text-muted);}
.tab-btn.active .tab-count{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
/* Page body */
.page-body{padding:0 2rem 2rem;display:grid;grid-template-columns:1fr 340px;gap:2rem;align-items:start;}
.main-tab-card{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);box-shadow:var(--shadow);margin-bottom:1.5rem;}
.tab-panels{padding:1.75rem;}.tab-panel{display:none;}.tab-panel.active{display:block;}
/* Overview */
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:1rem;margin-bottom:1.25rem;}
.info-item{display:flex;flex-direction:column;gap:.25rem;}
.info-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);}
.info-value{font-size:.9rem;font-weight:500;color:var(--text);}
.info-value a{color:var(--navy-light);text-decoration:none;}
.info-value a:hover{text-decoration:underline;}
.section-divider{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.6rem;margin:1.5rem 0 1rem;}
.section-divider::after{content:'';flex:1;height:1px;background:var(--border);}
.highlights-wrap{display:flex;flex-wrap:wrap;gap:.6rem;}
.hl-chip{display:inline-flex;flex-direction:column;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:.55rem .9rem;min-width:105px;transition:border-color var(--transition);}
.hl-chip:hover{border-color:var(--navy-light);}
.hl-chip .hl-val{font-size:1.05rem;font-weight:700;color:var(--navy);line-height:1;}
.hl-chip .hl-lbl{font-size:.72rem;color:var(--text-muted);margin-top:.2rem;}
/* Pitch */
.pitch-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.pitch-field{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;transition:border-color var(--transition);}
.pitch-field:hover{border-color:var(--navy-light);}.pitch-field.span-2{grid-column:span 2;}
.pitch-field-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
.pitch-field-body{font-size:.88rem;color:var(--text-muted);line-height:1.65;white-space:pre-wrap;max-height:160px;overflow-y:auto;}
.pitch-assets{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;}
.pitch-asset-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem 1.1rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;text-decoration:none;border:1.5px solid var(--border);background:var(--surface-2);color:var(--navy);transition:all var(--transition);}
.pitch-asset-btn:hover{border-color:var(--navy-light);background:#eff4ff;color:var(--navy-mid);}
/* Financials */
.fin-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.fin-table th{text-align:left;padding:.55rem .75rem;font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.fin-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.fin-table tr:last-child td{border-bottom:none;}.fin-table tr:hover td{background:#fafbfc;}
.fin-table td.num{text-align:right;font-variant-numeric:tabular-nums;}.fin-table td.neg{color:var(--error);}
.dl-self {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.dl-acct {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
/* Milestones */
.ms-timeline{display:flex;flex-direction:column;}
.ms-item{display:flex;gap:1rem;padding-bottom:1.25rem;position:relative;}
.ms-item:not(:last-child)::before{content:'';position:absolute;left:15px;top:32px;bottom:0;width:2px;background:var(--border);}
.ms-dot{width:32px;height:32px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:var(--amber);font-size:.75rem;flex-shrink:0;position:relative;z-index:1;}
.ms-content{flex:1;padding-top:.2rem;}
.ms-date{font-size:.72rem;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.15rem;}
.ms-title{font-size:.9rem;font-weight:600;color:var(--navy);margin-bottom:.2rem;}
.ms-desc{font-size:.82rem;color:var(--text-muted);line-height:1.5;}
/* Q&A */
.qa-item{padding:1rem 0;border-bottom:1px solid var(--border);}.qa-item:last-child{border-bottom:none;}
.qa-q{font-size:.88rem;font-weight:600;color:var(--text);margin-bottom:.35rem;display:flex;gap:.5rem;}
.qa-q i{color:var(--navy-light);margin-top:.1rem;flex-shrink:0;}
.qa-meta{font-size:.73rem;color:var(--text-light);margin-bottom:.6rem;}
.qa-a{background:var(--surface-2);border-left:3px solid var(--navy-mid);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:.65rem .85rem;font-size:.85rem;color:var(--text-muted);line-height:1.6;}
.qa-pending{font-size:.8rem;color:var(--text-light);font-style:italic;}
.qa-form textarea{width:100%;padding:.7rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;resize:vertical;min-height:80px;transition:border-color var(--transition),box-shadow var(--transition);}
.qa-form textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.1);}
/* Alerts */
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.alert-error  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.25rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
.btn-primary{background:var(--navy-mid);color:#fff;}.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.btn-sm{padding:.4rem .9rem;font-size:.8rem;}
.empty-panel{padding:2rem;text-align:center;font-size:.88rem;color:var(--text-light);}
.empty-panel i{font-size:1.75rem;display:block;margin-bottom:.65rem;opacity:.4;}
/* ── Right sidebar ── */
.sidebar-col{position:sticky;top:calc(var(--header-h) + 1.5rem);display:flex;flex-direction:column;gap:1.25rem;}
/* SPV Campaign card */
.spv-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
.spv-head{background:var(--navy);padding:1rem 1.2rem;}
.spv-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.45);margin-bottom:.2rem;}
.spv-title{font-family:'DM Serif Display',serif;font-size:1.05rem;color:#fff;line-height:1.25;margin-bottom:.35rem;}
.spv-tagline{font-size:.78rem;color:rgba(255,255,255,.6);line-height:1.4;}
.spv-type-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .6rem;border-radius:99px;font-size:.71rem;font-weight:700;margin-top:.5rem;}
.ct-rs  {background:rgba(26,86,176,.3);color:#93c5fd;}
.ct-co  {background:rgba(11,107,77,.3);color:#6ee7b7;}
.ct-loan{background:rgba(217,119,6,.3);color:#fde68a;}
.ct-don {background:rgba(157,23,77,.3);color:#f9a8d4;}
.ct-cn  {background:rgba(109,40,217,.3);color:#c4b5fd;}
.spv-body{padding:1rem 1.2rem;}
.spv-progress{margin-bottom:.9rem;}
.spv-raised{font-size:1.15rem;font-weight:700;color:var(--navy);line-height:1;}
.spv-of{font-size:.72rem;color:var(--text-light);}
.spv-prog-outer{height:7px;background:var(--surface-2);border-radius:99px;overflow:hidden;margin:.55rem 0 .35rem;border:1px solid var(--border);}
.spv-prog-inner{height:100%;border-radius:99px;transition:width .5s ease;}
.spv-prog-open  {background:var(--amber);}
.spv-prog-funded{background:var(--green);}
.spv-prog-stats{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);}
.spv-stat-row{display:flex;align-items:center;justify-content:space-between;padding:.48rem 0;border-bottom:1px solid var(--border);font-size:.83rem;}
.spv-stat-row:last-of-type{border-bottom:none;}
.spv-stat-lbl{color:var(--text-muted);}
.spv-stat-val{font-weight:600;color:var(--text);text-align:right;}
/* Use of funds on campaign */
.spv-uof{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .9rem;margin-top:.75rem;}
.spv-uof-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--navy-light);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem;}
.spv-uof-text{font-size:.82rem;color:var(--text-muted);line-height:1.6;max-height:100px;overflow-y:auto;}
/* Terms */
.spv-terms{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.75rem .9rem;margin-top:.75rem;}
.spv-terms-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.45rem;display:flex;align-items:center;gap:.35rem;}
.terms-row{display:flex;justify-content:space-between;align-items:baseline;padding:.38rem 0;border-bottom:1px solid var(--border);font-size:.82rem;gap:.5rem;}
.terms-row:last-child{border-bottom:none;}
.terms-lbl{color:var(--text-muted);flex-shrink:0;}
.terms-val{font-weight:600;color:var(--navy-mid);text-align:right;}
/* CTA */
.btn-contribute{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.82rem 1rem;margin-top:1rem;background:var(--amber);color:var(--navy);border:none;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.93rem;font-weight:700;text-decoration:none;cursor:pointer;transition:all var(--transition);box-shadow:0 4px 14px rgba(245,158,11,.3);}
.btn-contribute:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);box-shadow:0 6px 18px rgba(245,158,11,.4);}
.btn-days-left{font-size:.75rem;color:var(--text-light);text-align:center;margin-top:.4rem;}
/* Legal */
.legal-note{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.7rem .85rem;font-size:.75rem;color:#78350f;line-height:1.55;display:flex;gap:.5rem;align-items:flex-start;}
.legal-note i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}
/* Meta */
.meta-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1rem 1.1rem;}
.meta-row{display:flex;gap:.5rem;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.83rem;}
.meta-row:last-child{border-bottom:none;}
.meta-label{color:var(--text-muted);min-width:88px;flex-shrink:0;}
.meta-value{color:var(--text);font-weight:500;word-break:break-word;}
/* Multiple campaigns label */
.campaigns-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);margin-bottom:.6rem;display:flex;align-items:center;gap:.4rem;}
/* Responsive */
@media(max-width:1024px){.page-body{grid-template-columns:1fr 300px;padding:0 1.5rem 2rem;gap:1.5rem;}.company-hero{padding:0 1.5rem;}.back-bar{padding:.75rem 1.5rem;}.header-nav{display:none;}}
@media(max-width:900px){.sidebar{display:none;}}
@media(max-width:768px){.page-body{grid-template-columns:1fr;padding:0 1rem 1.5rem;}.sidebar-col{position:static;order:-1;}.company-hero{padding:0 1rem;}.back-bar{padding:.65rem 1rem;}.hero-name{font-size:1.4rem;}.pitch-grid{grid-template-columns:1fr;}.pitch-field.span-2{grid-column:span 1;}}
@media(max-width:480px){.hero-banner{height:160px;}.hero-logo-wrap{width:72px;height:72px;}}
@media screen and (min-width:1024px){.header-dropdown{display:none!important;}}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
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
        <div class="back-bar">
            <a href="/app/discover/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Discover</a>
        </div>

        <!-- Hero -->
        <div class="company-hero">
            <div class="hero-card">
                <div class="hero-banner" <?php if ($banner): ?>style="background-image:url('<?php echo $banner; ?>')"<?php endif; ?>>
                    <div class="hero-banner-overlay"></div>
                </div>
                <div class="hero-identity">
                    <div class="hero-logo-wrap">
                        <?php if ($logo): ?>
                            <img src="<?php echo $logo; ?>" alt="<?php echo htmlspecialchars($company['name']); ?>">
                        <?php else: ?>
                            <div class="hero-logo-ph"><?php echo strtoupper(substr($company['name'], 0, 1)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="hero-title-area">
                        <h1 class="hero-name"><?php echo htmlspecialchars($company['name']); ?></h1>
                        <div class="hero-meta">
                            <span class="badge badge-verified"><i class="fa-solid fa-circle-check"></i> Verified</span>
                            <?php if ($company['industry']): ?><span class="badge badge-industry"><?php echo htmlspecialchars($company['industry']); ?></span><?php endif; ?>
                            <?php if (!empty($company['area'])): ?><span class="badge badge-<?php echo htmlspecialchars($company['area']); ?>"><?php echo $areaLabels[$company['area']] ?? ''; ?></span><?php endif; ?>
                            <?php if ($company['type']): ?><span class="badge badge-type"><?php echo $typeLabels[$company['type']] ?? ucfirst($company['type']); ?></span><?php endif; ?>
                            <?php if (!empty($company['city']) || !empty($company['province'])): ?>
                                <span class="badge badge-type"><i class="fa-solid fa-location-dot" style="font-size:.7rem;"></i> <?php echo htmlspecialchars(implode(', ', array_filter([$company['city'] ?? '', $company['province'] ?? '']))); ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Tabs -->
                <div class="main-tabs" id="mainTabs">
                    <button class="tab-btn active" onclick="switchTab('overview',this)"><i class="fa-solid fa-circle-info"></i> Overview</button>
                    <?php if (!empty($pitchSections) || ($pitch && ($pitch['pitch_deck_url'] || $pitch['pitch_video_url']))): ?>
                    <button class="tab-btn" onclick="switchTab('pitch',this)">
                        <i class="fa-solid fa-bullhorn"></i> The Pitch
                        <span class="tab-count"><?php echo count($pitchSections); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($financials)): ?>
                    <button class="tab-btn" onclick="switchTab('financials',this)">
                        <i class="fa-solid fa-chart-bar"></i> Financials
                        <span class="tab-count"><?php echo count($financials); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if (!empty($milestones)): ?>
                    <button class="tab-btn" onclick="switchTab('milestones',this)">
                        <i class="fa-solid fa-trophy"></i> Milestones
                        <span class="tab-count"><?php echo count($milestones); ?></span>
                    </button>
                    <?php endif; ?>
                    <?php if ($qCampaign): ?>
                    <button class="tab-btn" onclick="switchTab('qa',this)" id="qaTabBtn">
                        <i class="fa-solid fa-comments"></i> Q&amp;A
                        <span class="tab-count"><?php echo count($questions); ?></span>
                    </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="page-body">

            <!-- ═══ MAIN TABS ═══ -->
            <div>
                <div class="main-tab-card">
                    <div class="tab-panels">

                        <!-- Overview -->
                        <div class="tab-panel active" data-panel="overview">
                            <?php if ($company['description']): ?>
                                <p style="font-size:.92rem;color:var(--text-muted);line-height:1.7;margin-bottom:1.5rem;"><?php echo nl2br(htmlspecialchars($company['description'])); ?></p>
                            <?php endif; ?>
                            <div class="info-grid">
                                <?php if ($company['founded_year']): ?><div class="info-item"><span class="info-label">Founded</span><span class="info-value"><?php echo htmlspecialchars($company['founded_year']); ?></span></div><?php endif; ?>
                                <?php if ($company['employee_count']): ?><div class="info-item"><span class="info-label">Team Size</span><span class="info-value"><?php echo htmlspecialchars($company['employee_count']); ?> employees</span></div><?php endif; ?>
                                <?php if ($company['stage']): ?><div class="info-item"><span class="info-label">Stage</span><span class="info-value"><?php echo ucfirst(str_replace('_',' ',$company['stage'])); ?></span></div><?php endif; ?>
                                <?php if (!empty($company['city']) || !empty($company['province'])): ?><div class="info-item"><span class="info-label">Location</span><span class="info-value"><?php echo htmlspecialchars(implode(', ', array_filter([$company['city'] ?? '', $company['province'] ?? '']))); ?></span></div><?php endif; ?>
                                <?php if ($company['website']): ?><div class="info-item"><span class="info-label">Website</span><span class="info-value"><a href="<?php echo htmlspecialchars($company['website']); ?>" target="_blank" rel="noopener"><i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.75rem;margin-right:.2rem;"></i><?php echo htmlspecialchars($company['website']); ?></a></span></div><?php endif; ?>
                                <?php if ($company['industry']): ?><div class="info-item"><span class="info-label">Industry</span><span class="info-value"><?php echo htmlspecialchars($company['industry']); ?></span></div><?php endif; ?>
                            </div>
                            <?php if (!empty($highlights)): ?>
                                <div class="section-divider">Key Highlights</div>
                                <div class="highlights-wrap">
                                    <?php foreach ($highlights as $hl): ?>
                                        <div class="hl-chip"><span class="hl-val"><?php echo htmlspecialchars($hl['value']); ?></span><span class="hl-lbl"><?php echo htmlspecialchars($hl['label']); ?></span></div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Pitch (company-level; no use_of_funds — that lives per-campaign) -->
                        <?php if (!empty($pitchSections) || ($pitch && ($pitch['pitch_deck_url'] || $pitch['pitch_video_url']))): ?>
                        <div class="tab-panel" data-panel="pitch">
                            <?php if (!empty($pitchSections)): ?>
                            <div class="pitch-grid">
                                <?php foreach ($pitchSections as $ps): ?>
                                <div class="pitch-field<?php echo (count($pitchSections) % 2 !== 0 && $ps === end($pitchSections)) ? ' span-2' : ''; ?>">
                                    <div class="pitch-field-label"><i class="fa-solid <?php echo $ps['icon']; ?>"></i><?php echo htmlspecialchars($ps['label']); ?></div>
                                    <div class="pitch-field-body"><?php echo htmlspecialchars($ps['text']); ?></div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                            <?php if ($pitch && ($pitch['pitch_deck_url'] || $pitch['pitch_video_url'])): ?>
                            <div class="section-divider" style="<?php echo !empty($pitchSections) ? '' : 'margin-top:0;'; ?>">Pitch Assets</div>
                            <div class="pitch-assets">
                                <?php if ($pitch['pitch_deck_url']): ?><a href="<?php echo htmlspecialchars($pitch['pitch_deck_url']); ?>" target="_blank" class="pitch-asset-btn"><i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> Download Pitch Deck</a><?php endif; ?>
                                <?php if ($pitch['pitch_video_url']): ?><a href="<?php echo htmlspecialchars($pitch['pitch_video_url']); ?>" target="_blank" class="pitch-asset-btn"><i class="fa-brands fa-youtube" style="color:#ff0000;"></i> Watch Pitch Video</a><?php endif; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

                        <!-- Financials -->
                        <?php if (!empty($financials)): ?>
                        <div class="tab-panel" data-panel="financials">
                            <div style="overflow-x:auto;">
                                <table class="fin-table">
                                    <thead><tr><th>Period</th><th style="text-align:right;">Revenue</th><th style="text-align:right;">Gross Profit</th><th style="text-align:right;">Net Profit</th><th>Disclosure</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($financials as $fin):
                                        $pl = $fin['period_month'] ? ($monthNames[(int)$fin['period_month']] . ' ' . $fin['period_year']) : ($fin['period_year'] . ' Annual');
                                        $dl = $disclosureLabels[$fin['disclosure_type']] ?? ['Self-Reported','dl-self'];
                                    ?>
                                    <tr>
                                        <td><strong><?php echo htmlspecialchars($pl); ?></strong></td>
                                        <td class="num"><?php echo pub_money($fin['revenue']); ?></td>
                                        <td class="num"><?php echo pub_money($fin['gross_profit']); ?></td>
                                        <td class="num <?php echo isset($fin['net_profit']) && $fin['net_profit'] < 0 ? 'neg' : ''; ?>"><?php echo pub_money($fin['net_profit']); ?></td>
                                        <td><span class="badge <?php echo $dl[1]; ?>"><?php echo $dl[0]; ?></span></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Milestones -->
                        <?php if (!empty($milestones)): ?>
                        <div class="tab-panel" data-panel="milestones">
                            <div class="ms-timeline">
                                <?php foreach ($milestones as $ms): ?>
                                <div class="ms-item">
                                    <div class="ms-dot"><i class="fa-solid fa-flag"></i></div>
                                    <div class="ms-content">
                                        <div class="ms-date"><?php echo pub_date($ms['milestone_date']); ?></div>
                                        <div class="ms-title"><?php echo htmlspecialchars($ms['title']); ?></div>
                                        <?php if ($ms['description']): ?><div class="ms-desc"><?php echo htmlspecialchars($ms['description']); ?></div><?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>

                        <!-- Q&A (for the most recent active campaign) -->
                        <?php if ($qCampaign): ?>
                        <div class="tab-panel" data-panel="qa">
                            <p style="font-size:.78rem;color:var(--text-light);margin-bottom:1rem;">
                                Questions below relate to <strong><?php echo htmlspecialchars($qCampaign['title']); ?></strong>.
                            </p>
                            <?php if ($qSuccess): ?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($qSuccess); ?></div><?php endif; ?>
                            <?php if ($qError): ?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($qError); ?></div><?php endif; ?>
                            <?php if (empty($questions)): ?>
                                <div class="empty-panel"><i class="fa-regular fa-comments"></i>No questions yet. Be the first to ask.</div>
                            <?php else: ?>
                                <?php foreach ($questions as $q): ?>
                                <div class="qa-item">
                                    <div class="qa-q"><i class="fa-solid fa-circle-question"></i><?php echo htmlspecialchars($q['question']); ?></div>
                                    <div class="qa-meta">Asked by <?php echo htmlspecialchars(mask_email($q['asker_email'])); ?> &middot; <?php echo pub_date($q['asked_at']); ?></div>
                                    <?php if ($q['answer']): ?>
                                        <div class="qa-a">
                                            <strong style="font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;color:var(--navy-light);display:block;margin-bottom:.25rem;"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($company['name']); ?></strong>
                                            <?php echo htmlspecialchars($q['answer']); ?>
                                        </div>
                                    <?php else: ?>
                                        <div class="qa-pending">Awaiting response from the company.</div>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            <div style="margin-top:1.25rem;border-top:1px solid var(--border);padding-top:1.25rem;">
                                <form method="POST" class="qa-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <textarea name="question" placeholder="Ask the company a question…" rows="3" maxlength="1000"></textarea>
                                    <div style="margin-top:.6rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                                        <span style="font-size:.75rem;color:var(--text-light);">Questions are public and visible to all investors.</span>
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Submit</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                        <?php endif; ?>

                    </div>
                </div>
            </div>

            <!-- ═══ RIGHT SIDEBAR — one card per active campaign (SPV) ═══ -->
            <div class="sidebar-col">

                <?php if (!empty($campaigns)): ?>
                    <?php if (count($campaigns) > 1): ?>
                        <div class="campaigns-label"><i class="fa-solid fa-layer-group"></i> <?php echo count($campaigns); ?> Active Campaigns</div>
                    <?php endif; ?>

                    <?php foreach ($campaigns as $cam):
                        $ctInfo   = $campaignTypeInfo[$cam['campaign_type']] ?? ['Unknown','fa-rocket','ct-rs'];
                        $t        = (float)$cam['raise_target'];
                        $r        = (float)$cam['total_raised'];
                        $pct      = $t > 0 ? min(100, round(($r / $t) * 100)) : 0;
                        $isFunded = $cam['status'] === 'funded';
                        $daysLeft = days_left($cam['closes_at']);
                        $contributeUrl = '/app/invest/start.php?cid=' . urlencode($cam['campaign_uuid']);
                        // US-101: only show contribute CTAs to investors with an accepted invite
                        $isInvited = isset($invitedSet[(int)$cam['id']]);
                    ?>
                    <div class="spv-card">
                        <div class="spv-head">
                            <div class="spv-label">SPV Campaign</div>
                            <div class="spv-title"><?php echo htmlspecialchars($cam['title']); ?></div>
                            <?php if ($cam['tagline']): ?><div class="spv-tagline"><?php echo htmlspecialchars($cam['tagline']); ?></div><?php endif; ?>
                            <div class="spv-type-chip <?php echo $ctInfo[2]; ?>">
                                <i class="fa-solid <?php echo $ctInfo[1]; ?>"></i> <?php echo $ctInfo[0]; ?>
                            </div>
                        </div>
                        <div class="spv-body">
                            <!-- Progress -->
                            <div class="spv-progress">
                                <div class="spv-raised"><?php echo pub_money($r); ?></div>
                                <div class="spv-of">raised of <?php echo pub_money($t); ?> target</div>
                                <div class="spv-prog-outer">
                                    <div class="spv-prog-inner <?php echo $isFunded ? 'spv-prog-funded' : 'spv-prog-open'; ?>" style="width:<?php echo $pct; ?>%"></div>
                                </div>
                                <div class="spv-prog-stats">
                                    <span><?php echo $pct; ?>% funded</span>
                                    <span><?php echo (int)$cam['contributor_count']; ?>/<?php echo (int)$cam['max_contributors']; ?> contributors</span>
                                </div>
                            </div>
                            <!-- Key stats -->
                            <div style="border-top:1px solid var(--border);padding-top:.75rem;margin-bottom:.75rem;">
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Min. contribution</span><span class="spv-stat-val"><?php echo pub_money($cam['min_contribution']); ?></span></div>
                                <?php if ($cam['max_contribution']): ?><div class="spv-stat-row"><span class="spv-stat-lbl">Max. contribution</span><span class="spv-stat-val"><?php echo pub_money($cam['max_contribution']); ?></span></div><?php endif; ?>
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Closes</span><span class="spv-stat-val"><?php echo pub_date($cam['closes_at']); ?></span></div>
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Min. raise</span><span class="spv-stat-val"><?php echo pub_money($cam['raise_minimum']); ?></span></div>
                            </div>
                            <!-- Use of funds (campaign-specific; lives on funding_campaigns after migration) -->
                            <?php if (!empty($cam['use_of_funds'])): ?>
                            <div class="spv-uof">
                                <div class="spv-uof-label"><i class="fa-solid fa-sack-dollar"></i> Use of Funds</div>
                                <div class="spv-uof-text"><?php echo htmlspecialchars($cam['use_of_funds']); ?></div>
                            </div>
                            <?php endif; ?>
                            <!-- Terms -->
                            <div class="spv-terms">
                                <div class="spv-terms-label"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
                                <?php if ($cam['campaign_type'] === 'revenue_share'): ?>
                                    <div class="terms-row"><span class="terms-lbl">Type</span><span class="terms-val">Revenue Share</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Monthly Share</span><span class="terms-val"><?php echo htmlspecialchars((string)$cam['revenue_share_percentage']); ?>%</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Duration</span><span class="terms-val"><?php echo htmlspecialchars((string)$cam['revenue_share_duration_months']); ?> months</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Governing Law</span><span class="terms-val"><?php echo htmlspecialchars($cam['governing_law'] ?? 'Republic of South Africa'); ?></span></div>
                                <?php elseif ($cam['campaign_type'] === 'cooperative_membership'): ?>
                                    <div class="terms-row"><span class="terms-lbl">Type</span><span class="terms-val">Co-op Membership</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Unit</span><span class="terms-val"><?php echo htmlspecialchars($cam['unit_name'] ?? '—'); ?></span></div>
                                    <div class="terms-row"><span class="terms-lbl">Price / unit</span><span class="terms-val"><?php echo pub_money($cam['unit_price']); ?></span></div>
                                    <div class="terms-row"><span class="terms-lbl">Available</span><span class="terms-val"><?php echo (int)$cam['total_units_available']; ?> units</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Governing Law</span><span class="terms-val"><?php echo htmlspecialchars($cam['governing_law'] ?? 'Republic of South Africa'); ?></span></div>
                                <?php elseif ($cam['campaign_type'] === 'fixed_return_loan'): ?>
                                    <div class="terms-row"><span class="terms-lbl">Type</span><span class="terms-val">Fixed Return Loan</span></div>
                                    <div class="terms-row"><span class="terms-lbl">Return Rate</span><span class="terms-val"><?php echo htmlspecialchars((string)$cam['fixed_return_rate']); ?>% p.a.</span></div>
                                    <?php if ($cam['loan_term_months']): ?><div class="terms-row"><span class="terms-lbl">Term</span><span class="terms-val"><?php echo htmlspecialchars((string)$cam['loan_term_months']); ?> months</span></div><?php endif; ?>
                                    <div class="terms-row"><span class="terms-lbl">Governing Law</span><span class="terms-val"><?php echo htmlspecialchars($cam['governing_law'] ?? 'Republic of South Africa'); ?></span></div>
                                <?php endif; ?>
                            </div>
                            <!-- CTA — US-101 invite gate -->
                            <?php if ($isInvited): ?>
                                <!-- Invited: show full campaign link + invest/funded button -->
                                <a href="/app/discover/campaign.php?cid=<?php echo urlencode($cam['campaign_uuid']); ?>"
                                   style="display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.7rem 1rem;margin-top:.85rem;background:var(--surface-2);color:var(--navy-mid);border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:600;text-decoration:none;transition:all var(--transition);"
                                   onmouseover="this.style.background='#eff4ff';this.style.borderColor='var(--navy-light)'" onmouseout="this.style.background='var(--surface-2)';this.style.borderColor='var(--border)'">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View Full Campaign
                                </a>
                                <?php if (!$isFunded): ?>
                                <a href="<?php echo htmlspecialchars($contributeUrl); ?>" class="btn-contribute">
                                    <i class="fa-solid fa-hand-holding-dollar"></i> Invest in this SPV
                                </a>
                                <?php if ($daysLeft > 0): ?>
                                    <div class="btn-days-left"><i class="fa-regular fa-clock"></i> <?php echo $daysLeft; ?> day<?php echo $daysLeft !== 1 ? 's' : ''; ?> left</div>
                                <?php endif; ?>
                                <?php else: ?>
                                <div class="btn-contribute" style="background:var(--green);color:#fff;box-shadow:0 4px 14px rgba(11,107,77,.25);cursor:default;">
                                    <i class="fa-solid fa-circle-check"></i> Fully Funded
                                </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <!-- Not invited: invitation-required indicator (AC2) -->
                                <div style="margin-top:.85rem;padding:.85rem 1rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);text-align:center;">
                                    <div style="font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem;">
                                        <i class="fa-solid fa-lock" style="color:var(--text-light);margin-right:.3rem;"></i>Invitation required
                                    </div>
                                    <div style="font-size:.73rem;color:var(--text-light);line-height:1.5;">
                                        This campaign is private. Contact the company to request an invitation.
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                    <div class="legal-note">
                        <i class="fa-solid fa-scale-balanced"></i>
                        <div>Each campaign is a separate SPV. Contributions are limited to <strong>50 contributors</strong> per campaign under South African private placement regulations.</div>
                    </div>

                <?php else: ?>
                    <div class="meta-card" style="text-align:center;padding:1.5rem 1rem;">
                        <i class="fa-solid fa-rocket" style="font-size:1.75rem;color:var(--border);display:block;margin-bottom:.6rem;"></i>
                        <div style="font-size:.88rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem;">No active campaigns</div>
                        <div style="font-size:.78rem;color:var(--text-light);">This company isn't raising right now.</div>
                    </div>
                <?php endif; ?>

                <!-- Company meta -->
                <div class="meta-card">
                    <?php if ($company['email']): ?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-envelope" style="margin-right:.3rem;color:var(--navy-light);"></i>Email</span><span class="meta-value"><?php echo htmlspecialchars($company['email']); ?></span></div><?php endif; ?>
                    <?php if ($company['phone']): ?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-phone" style="margin-right:.3rem;color:var(--navy-light);"></i>Phone</span><span class="meta-value"><?php echo htmlspecialchars($company['phone']); ?></span></div><?php endif; ?>
                    <?php if ($company['registration_number']): ?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-hashtag" style="margin-right:.3rem;color:var(--navy-light);"></i>Reg. No.</span><span class="meta-value"><?php echo htmlspecialchars($company['registration_number']); ?></span></div><?php endif; ?>
                    <?php if ($company['founded_year']): ?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-calendar" style="margin-right:.3rem;color:var(--navy-light);"></i>Founded</span><span class="meta-value"><?php echo htmlspecialchars($company['founded_year']); ?></span></div><?php endif; ?>
                </div>

            </div><!-- /.sidebar-col -->
        </div><!-- /.page-body -->
    </div><!-- /.main-content -->
</div><!-- /.page-wrapper -->

<script>
function switchTab(panel, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    btn.classList.add('active');
    const el = document.querySelector('[data-panel="' + panel + '"]');
    if (el) el.classList.add('active');
}
document.addEventListener('DOMContentLoaded', function () {
    if (window.location.hash === '#qa') {
        const b = document.getElementById('qaTabBtn');
        if (b) switchTab('qa', b);
    }
});
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