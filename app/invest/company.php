<?php
/**
 * /app/invest/company.php
 *
 * US-301 — Fleet Operator Profile                               Team B
 * US-302 — Financials panel: actual-vs-projected on company profile
 *
 * PHP data header: Team A stub (verbatim).
 * HTML layout: Team B.
 *
 * discover/company.php 301-redirects here (US-002 routing update).
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InviteService.php';
require_once '../includes/FleetService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/invest/company.php?uuid=' . urlencode($_GET['uuid'] ?? '')));
}

$uuid = trim($_GET['uuid'] ?? '');
if (empty($uuid)) { redirect('/app/discover/'); }

$pdo    = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// ── Company ───────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT c.*, cf.province, cf.municipality, cf.city, cf.suburb, cf.area
    FROM companies c
    LEFT JOIN company_filter cf ON cf.company_id = c.id
    WHERE c.uuid = :uuid AND c.status = 'active' AND c.verified = 1
");
$stmt->execute(['uuid' => $uuid]);
$company = $stmt->fetch();
if (!$company) { redirect('/app/discover/'); }
$companyId = (int)$company['id'];

// ── Pitch & Highlights ────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM company_pitch WHERE company_id = ?");
$stmt->execute([$companyId]);
$pitch = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("SELECT label, value FROM pitch_highlights WHERE company_id = ? ORDER BY sort_order ASC LIMIT 6");
$stmt->execute([$companyId]);
$highlights = $stmt->fetchAll();

// ── Financials (last 4 periods) ───────────────────────────────────────────
$stmt = $pdo->prepare("SELECT period_year, period_month, revenue, gross_profit, net_profit, disclosure_type FROM company_financials WHERE company_id = ? ORDER BY period_year DESC, COALESCE(period_month, 0) DESC LIMIT 4");
$stmt->execute([$companyId]);
$financials = $stmt->fetchAll();

// ── Milestones ────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT milestone_date, title, description FROM company_milestones WHERE company_id = ? AND is_public = 1 ORDER BY milestone_date DESC, sort_order ASC LIMIT 8");
$stmt->execute([$companyId]);
$milestones = $stmt->fetchAll();

// ── Active Campaigns (SPVs) ───────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        fc.id, fc.uuid AS campaign_uuid, fc.title, fc.tagline,
        fc.campaign_type, fc.status,
        fc.raise_target, fc.raise_minimum, fc.raise_maximum,
        fc.min_contribution, fc.max_contribution, fc.max_contributors,
        fc.total_raised, fc.contributor_count, fc.opens_at, fc.closes_at,
        ct.revenue_share_percentage, ct.revenue_share_duration_months,
        ct.unit_name, ct.unit_price, ct.total_units_available,
        ct.governing_law,
        ct.hurdle_rate, ct.investor_waterfall_pct, ct.management_fee_pct,
        ct.distribution_frequency, ct.term_months,
        ct.asset_type, ct.asset_count, ct.total_acquisition_cost
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    WHERE fc.company_id = :cid AND fc.status IN ('open','funded')
    ORDER BY fc.id DESC
");
$stmt->execute(['cid' => $companyId]);
$campaigns = $stmt->fetchAll();

// ── Batch invite check ────────────────────────────────────────────────────
$campaignIds = array_map(fn($c) => (int)$c['id'], $campaigns);
$invitedIds  = InviteService::acceptedCampaignIds($userId, $campaignIds);
$invitedSet  = array_flip($invitedIds);

// ── Fleet asset summaries per campaign ───────────────────────────────────
$fleetSummaries = [];
foreach ($campaigns as $cam) {
    if ($cam['campaign_type'] === 'fleet_asset') {
        $fleetSummaries[$cam['id']] = FleetService::getAssetSummary((int)$cam['id']);
    }
}

// ── US-302: Operational track record (aggregate + per-campaign) ───────────
$stmt = $pdo->prepare("
    SELECT
        SUM(com.total_gross_revenue)            AS total_revenue,
        SUM(com.investor_distribution_actual)   AS total_distributions_paid,
        AVG(com.utilisation_rate_pct)           AS avg_utilisation,
        COUNT(DISTINCT com.campaign_id)         AS campaigns_with_metrics,
        SUM(com.active_asset_count)             AS total_active_assets
    FROM campaign_operational_metrics com
    JOIN funding_campaigns fc ON fc.id = com.campaign_id
    WHERE fc.company_id = :cid
");
$stmt->execute(['cid' => $companyId]);
$trackRecord = $stmt->fetch();

// Per-campaign performance rows (US-302)
$stmt = $pdo->prepare("
    SELECT
        fc.uuid AS campaign_uuid,
        fc.title AS campaign_title,
        fc.status AS campaign_status,
        ct.term_months,
        SUM(com.investor_distribution_actual)   AS actual_total,
        SUM(cp.investor_distribution)           AS projected_total,
        MAX(com.disclosure_type)                AS disclosure_type,
        COUNT(com.id)                           AS periods_reported
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct              ON ct.campaign_id = fc.id
    LEFT JOIN campaign_operational_metrics com ON com.campaign_id = fc.id
    LEFT JOIN campaign_projections cp        ON cp.campaign_id = fc.id
    WHERE fc.company_id = :cid
    GROUP BY fc.id
    ORDER BY fc.id DESC
    LIMIT 10
");
$stmt->execute(['cid' => $companyId]);
$campaignPerformance = $stmt->fetchAll();
$hasPerformance = !empty($campaignPerformance) && array_sum(array_column($campaignPerformance, 'periods_reported')) > 0;

// ── Q&A (most recent active campaign) ────────────────────────────────────
$csrf_token = generateCSRFToken();
$qError = $qSuccess = '';
$questions = [];
$qCampaign = $campaigns[0] ?? null;
if ($qCampaign) {
    $stmt = $pdo->prepare("SELECT cq.id, cq.question, cq.asked_at, cq.answer, cq.answered_at, u.email AS asker_email FROM campaign_questions cq JOIN users u ON u.id = cq.asked_by WHERE cq.campaign_id = :cid AND cq.is_public = 1 ORDER BY cq.asked_at DESC");
    $stmt->execute(['cid' => $qCampaign['id']]);
    $questions = $stmt->fetchAll();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['question'])) {
        if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) { $qError = 'Invalid security token.'; }
        else {
            $q = trim($_POST['question'] ?? '');
            if ($q === '')             { $qError = 'Please enter a question.'; }
            elseif (mb_strlen($q)>1000){ $qError = 'Question is too long (max 1 000 characters).'; }
            else {
                $pdo->prepare("INSERT INTO campaign_questions (campaign_id, asked_by, question, asked_at) VALUES (:cid,:uid,:q,NOW())")->execute(['cid'=>$qCampaign['id'],'uid'=>$userId,'q'=>$q]);
                $qSuccess = 'Your question has been submitted and will appear once approved.';
                $stmt->execute(['cid' => $qCampaign['id']]);
                $questions = $stmt->fetchAll();
            }
        }
    }
}

// ── User info ─────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

function pub_money(mixed $v): string { return ($v===null||$v==='') ? '—' : 'R '.number_format((float)$v, 0, '.', ' '); }
function pub_date(mixed $v): string  { return $v ? date('d M Y', strtotime($v)) : '—'; }
function pub_days(string $d): int    { return max(0,(int)ceil((strtotime($d)-time())/86400)); }
function pub_mask(string $e): string { $p=explode('@',$e); if(count($p)!==2) return 'Member'; return substr($p[0],0,1).str_repeat('*',max(2,mb_strlen($p[0])-1)).'@'.$p[1]; }

$banner = !empty($company['banner']) ? $company['banner'] : '';
$logo   = !empty($company['logo'])   ? $company['logo']   : '';
$hasFleet = !empty(array_filter($campaigns, fn($c) => $c['campaign_type']==='fleet_asset'));

$areaLabels = ['urban'=>'Urban','township'=>'Township','rural'=>'Rural'];
$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];
$disclosureLabels = ['self_reported'=>['Self-Reported','dl-self'],'platform_verified'=>['Platform Verified','dl-plat'],'audited'=>['Audited','dl-audit']];
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
    /* ── Design Contract §4 variables (DO NOT ADD) ──────────── */
    :root{
        --navy:#0b2545; --navy-mid:#0f3b7a; --navy-light:#1a56b0;
        --amber:#f59e0b; --amber-dark:#d97706; --amber-light:#fef3c7;
        --green:#0b6b4d; --green-bg:#e6f7ec; --green-bdr:#a7f3d0;
        --surface:#fff; --surface-2:#f8f9fb;
        --border:#e4e7ec; --border-focus:#1a56b0;
        --text:#101828; --text-muted:#667085; --text-light:#98a2b3;
        --error:#b91c1c; --error-bg:#fef2f2; --error-bdr:#fecaca;
        --radius:14px; --radius-sm:8px;
        --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
        --shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);
        --header-h:64px; --nav-w:240px; --sidebar-w:340px;
        --transition:.2s cubic-bezier(.4,0,.2,1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
    ::-webkit-scrollbar{width:5px;height:4px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}

    /* ── HEADER ─────────────────────────────────────────────── */
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:200;gap:1rem;}
    .header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .header-brand span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;cursor:pointer;}
    .hdd{position:fixed;top:calc(var(--header-h)+1px);right:0;background:var(--surface);border:1px solid var(--border);border-radius:0 0 12px 12px;box-shadow:0 12px 32px rgba(36,33,33,.12);min-width:260px;z-index:201;opacity:0;transform:translateY(-8px) scale(.98);pointer-events:none;transition:opacity .2s ease,transform .2s ease;}
    .hdd--open{opacity:1;transform:translateY(0) scale(1);pointer-events:all;}
    .hdd-inner{padding:.5rem 0;}
    .hdd-sec{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);padding:.7rem 1rem .3rem;}
    .hdd-lnk{display:flex;align-items:center;gap:.75rem;padding:.6rem 1rem;color:#1e293b;text-decoration:none;font-size:.85rem;font-weight:500;transition:background .15s;}
    .hdd-lnk:hover{background:var(--surface-2);color:var(--navy-mid);}
    .hdd-lnk i{width:20px;text-align:center;font-size:.9rem;color:#5b6e8c;}
    .hdd-lnk.danger{color:var(--error);}.hdd-lnk.danger:hover{background:var(--error-bg);}
    .hdd-div{height:1px;background:var(--border);margin:.3rem 0;}

    /* ── PAGE LAYOUT ─────────────────────────────────────────── */
    .page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
    .nav-sidebar{width:var(--nav-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
    .ns-sec{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin:1.25rem 0 .5rem;}
    .ns-sec:first-child{margin-top:0;}
    .nav-sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
    .nav-sidebar a:hover{background:var(--surface-2);color:var(--text);}
    .nav-sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
    .nav-sidebar a i{width:16px;text-align:center;font-size:.85rem;}
    .main-content{flex:1;min-width:0;}

    /* ── BACK BAR ────────────────────────────────────────────── */
    .back-bar{padding:.85rem 2rem;}
    .back-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.83rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
    .back-link:hover{color:var(--navy);}

    /* ── COMPANY HERO ────────────────────────────────────────── */
    .company-hero{padding:0 2rem;}
    .hero-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
    .hero-banner{height:200px;background:linear-gradient(135deg,var(--navy-mid) 0%,var(--navy) 100%);background-size:cover;background-position:center;position:relative;}
    .hero-banner::after{content:'';position:absolute;inset:0;pointer-events:none;background:repeating-linear-gradient(-45deg,transparent,transparent 24px,rgba(200,168,75,.04) 24px,rgba(200,168,75,.04) 25px);}
    .hero-overlay{position:absolute;inset:0;background:linear-gradient(to bottom,transparent 30%,rgba(11,37,69,.6) 100%);}
    .verified-ribbon{position:absolute;top:1rem;right:1rem;z-index:2;display:inline-flex;align-items:center;gap:.3rem;padding:.3rem .85rem;border-radius:99px;font-size:.74rem;font-weight:700;background:rgba(11,107,77,.85);color:#fff;backdrop-filter:blur(6px);}
    .fleet-ribbon{position:absolute;top:1rem;left:1rem;z-index:2;display:inline-flex;align-items:center;gap:.3rem;padding:.25rem .75rem;border-radius:99px;font-size:.7rem;font-weight:600;background:rgba(3,105,161,.8);color:#fff;border:1px solid rgba(255,255,255,.15);backdrop-filter:blur(4px);}
    .hero-logo-wrap{position:absolute;bottom:-32px;left:2rem;z-index:3;width:72px;height:72px;border-radius:14px;background:var(--surface);border:3px solid var(--surface);box-shadow:0 4px 14px rgba(0,0,0,.14);overflow:hidden;display:flex;align-items:center;justify-content:center;}
    .hero-logo-wrap img{width:100%;height:100%;object-fit:cover;}
    .hero-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.8rem;color:#fff;}
    .hero-identity{display:flex;align-items:flex-end;gap:1.25rem;padding:0 2rem 1.5rem;margin-top:-32px;position:relative;z-index:4;flex-wrap:wrap;}
    .hero-title-area{flex:1;min-width:200px;padding-top:.5rem;}
    .hero-company-name{font-family:'DM Serif Display',serif;font-size:1.9rem;color:var(--navy);line-height:1.15;margin-bottom:.45rem;}
    .hero-meta{display:flex;flex-wrap:wrap;gap:.4rem;align-items:center;}
    .badge{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .65rem;border-radius:99px;font-size:.74rem;font-weight:600;border:1px solid transparent;}
    .b-verified{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .b-industry{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .b-area-township{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .b-area-urban{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
    .b-area-rural{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .b-stage{background:#f8fafc;color:var(--text-muted);border-color:var(--border);}
    .b-fleet{background:rgba(3,105,161,.1);color:#0369a1;border-color:#bae6fd;}

    /* ── TABS ────────────────────────────────────────────────── */
    .main-tabs{display:flex;border-bottom:1px solid var(--border);overflow-x:auto;scrollbar-width:none;background:var(--surface);padding:0 2rem;}
    .main-tabs::-webkit-scrollbar{display:none;}
    .tab-btn{display:inline-flex;align-items:center;gap:.45rem;padding:.9rem 1.1rem;border:none;background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.855rem;font-weight:500;color:var(--text-muted);border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:all var(--transition);white-space:nowrap;}
    .tab-btn:hover{color:var(--navy);}
    .tab-btn.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
    .tab-count{background:var(--surface-2);border:1px solid var(--border);border-radius:99px;font-size:.68rem;font-weight:600;padding:.05rem .45rem;color:var(--text-muted);}
    .tab-btn.active .tab-count{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}

    /* ── TWO-COLUMN BODY ─────────────────────────────────────── */
    .page-body{padding:1.5rem 2rem 2rem;display:grid;grid-template-columns:1fr var(--sidebar-w);gap:2rem;align-items:start;}
    .main-tab-card{background:var(--surface);border:1px solid var(--border);border-top:none;border-radius:0 0 var(--radius) var(--radius);box-shadow:var(--shadow);}
    .tab-panels{padding:1.75rem;}
    .tab-panel{display:none;}
    .tab-panel.active{display:block;}
    .sec-div{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.6rem;margin:1.5rem 0 1rem;}
    .sec-div::after{content:'';flex:1;height:1px;background:var(--border);}
    .sec-div:first-child{margin-top:0;}
    .sec-div i{color:var(--navy-light);}

    /* ── OVERVIEW TAB ────────────────────────────────────────── */
    .info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:1rem;margin-bottom:1.25rem;}
    .info-item{display:flex;flex-direction:column;gap:.25rem;}
    .info-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);}
    .info-value{font-size:.9rem;font-weight:500;color:var(--text);}
    .info-value a{color:var(--navy-light);text-decoration:none;}
    .info-value a:hover{text-decoration:underline;}
    .hl-wrap{display:flex;flex-wrap:wrap;gap:.6rem;margin-bottom:.25rem;}
    .hl-chip{display:flex;flex-direction:column;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:.55rem .9rem;min-width:110px;transition:border-color var(--transition);}
    .hl-chip:hover{border-color:var(--navy-light);}
    .hl-chip .hv{font-size:1.05rem;font-weight:700;color:var(--navy);line-height:1;}
    .hl-chip .hl{font-size:.72rem;color:var(--text-muted);margin-top:.2rem;}
    .pitch-body{font-size:.88rem;color:var(--text-muted);line-height:1.7;white-space:pre-wrap;margin-bottom:.5rem;}

    /* Track record banner (US-302) */
    .track-record-bar{display:flex;gap:1.5rem;flex-wrap:wrap;background:var(--navy);border-radius:var(--radius-sm);padding:1.1rem 1.35rem;margin-bottom:1.25rem;}
    .trb-item{display:flex;flex-direction:column;gap:.1rem;}
    .trb-val{font-family:'DM Serif Display',serif;font-size:1.35rem;color:#fff;line-height:1;}
    .trb-lbl{font-size:.71rem;color:rgba(255,255,255,.5);margin-top:.1rem;}
    .trb-divider{width:1px;background:rgba(255,255,255,.12);flex-shrink:0;align-self:stretch;}

    /* US-302: Per-campaign performance table */
    .perf-table{width:100%;border-collapse:collapse;font-size:.83rem;margin-bottom:.25rem;}
    .perf-table th{text-align:left;padding:.5rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);background:var(--surface-2);}
    .perf-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
    .perf-table tr:last-child td{border-bottom:none;}
    .perf-table tr:hover td{background:var(--surface-2);}
    .perf-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
    .variance-pos{color:var(--green);font-weight:700;}
    .variance-neg{color:var(--error);font-weight:700;}
    .dl-self{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
    .dl-plat{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}

    /* Milestones timeline */
    .ms-timeline{display:flex;flex-direction:column;}
    .ms-item{display:flex;gap:1rem;padding-bottom:1.25rem;position:relative;}
    .ms-item:not(:last-child)::before{content:'';position:absolute;left:15px;top:32px;bottom:0;width:2px;background:var(--border);}
    .ms-dot{width:32px;height:32px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:var(--amber);font-size:.75rem;flex-shrink:0;position:relative;z-index:1;}
    .ms-content{flex:1;padding-top:.15rem;}
    .ms-date{font-size:.72rem;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.15rem;}
    .ms-title{font-size:.9rem;font-weight:600;color:var(--navy);margin-bottom:.2rem;}
    .ms-desc{font-size:.82rem;color:var(--text-muted);line-height:1.5;}

    /* Company financials table */
    .fin-table{width:100%;border-collapse:collapse;font-size:.84rem;}
    .fin-table th{text-align:left;padding:.55rem .75rem;font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
    .fin-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
    .fin-table tr:last-child td{border-bottom:none;}
    .fin-table tr:hover td{background:var(--surface-2);}
    .fin-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
    .fin-table td.neg{color:var(--error);}

    /* Pitch sections grid */
    .pitch-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
    .pitch-field{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;transition:border-color var(--transition);}
    .pitch-field:hover{border-color:var(--navy-light);}
    .pitch-field.span2{grid-column:span 2;}
    .pf-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
    .pf-body{font-size:.87rem;color:var(--text-muted);line-height:1.65;white-space:pre-wrap;max-height:160px;overflow-y:auto;}
    .pitch-assets{display:flex;gap:.75rem;flex-wrap:wrap;margin-top:1rem;}
    .pitch-asset-btn{display:inline-flex;align-items:center;gap:.5rem;padding:.55rem 1.1rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;text-decoration:none;border:1.5px solid var(--border);background:var(--surface-2);color:var(--navy);transition:all var(--transition);}
    .pitch-asset-btn:hover{border-color:var(--navy-light);background:#eff4ff;color:var(--navy-mid);}

    /* Q&A */
    .qa-item{padding:1rem 0;border-bottom:1px solid var(--border);}
    .qa-item:last-child{border-bottom:none;}
    .qa-q{font-size:.88rem;font-weight:600;color:var(--text);margin-bottom:.35rem;display:flex;gap:.5rem;}
    .qa-q i{color:var(--navy-light);margin-top:.1rem;flex-shrink:0;}
    .qa-meta{font-size:.73rem;color:var(--text-light);margin-bottom:.6rem;}
    .qa-a{background:var(--surface-2);border-left:3px solid var(--navy-mid);border-radius:0 var(--radius-sm) var(--radius-sm) 0;padding:.65rem .85rem;font-size:.85rem;color:var(--text-muted);line-height:1.6;}
    .qa-pending{font-size:.8rem;color:var(--text-light);font-style:italic;}
    .qa-form textarea{width:100%;padding:.7rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;resize:vertical;min-height:80px;transition:border-color var(--transition),box-shadow var(--transition);}
    .qa-form textarea:focus{border-color:var(--border-focus);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.1);}

    /* Alerts + buttons */
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

    /* ── RIGHT SPV SIDEBAR ───────────────────────────────────── */
    .spv-sidebar{position:sticky;top:calc(var(--header-h) + 1.5rem);display:flex;flex-direction:column;gap:1.25rem;}

    /* SPV campaign cards */
    .spv-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
    .spv-card-head{background:var(--navy);padding:1rem 1.25rem;}
    .spv-head-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.4);margin-bottom:.2rem;}
    .spv-head-title{font-family:'DM Serif Display',serif;font-size:1.05rem;color:#fff;line-height:1.25;margin-bottom:.35rem;}
    .spv-head-tag{font-size:.78rem;color:rgba(255,255,255,.6);line-height:1.4;}
    .spv-type-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .6rem;border-radius:99px;font-size:.71rem;font-weight:700;margin-top:.45rem;}
    .ct-fleet{background:rgba(3,105,161,.25);color:#7dd3fc;}
    .ct-rs{background:rgba(26,86,176,.3);color:#93c5fd;}
    .ct-co{background:rgba(11,107,77,.3);color:#6ee7b7;}
    .spv-body{padding:1rem 1.25rem;}
    .spv-progress{margin-bottom:.85rem;}
    .spv-raised{font-size:1.15rem;font-weight:700;color:var(--navy);line-height:1;}
    .spv-of{font-size:.72rem;color:var(--text-light);}
    .prog-outer{height:7px;background:var(--surface-2);border-radius:99px;overflow:hidden;margin:.5rem 0 .35rem;border:1px solid var(--border);}
    .prog-inner{height:100%;border-radius:99px;transition:width .5s ease;}
    .prog-open{background:var(--amber);}
    .prog-funded{background:var(--green);}
    .prog-stats{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);}
    .spv-stat-row{display:flex;align-items:center;justify-content:space-between;padding:.48rem 0;border-bottom:1px solid var(--border);font-size:.83rem;}
    .spv-stat-row:last-of-type{border-bottom:none;}
    .spv-stat-lbl{color:var(--text-muted);}
    .spv-stat-val{font-weight:600;color:var(--text);text-align:right;}
    .fleet-summary-chips{display:flex;gap:.5rem;flex-wrap:wrap;margin:.6rem 0;}
    .fsc{display:flex;flex-direction:column;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.4rem .7rem;flex:1;min-width:60px;}
    .fsc-val{font-size:.92rem;font-weight:700;color:var(--navy);}
    .fsc-lbl{font-size:.67rem;color:var(--text-light);margin-top:.1rem;}
    /* Invite-aware CTAs */
    .btn-view-spv{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.82rem 1rem;margin-top:.85rem;background:var(--amber);color:var(--navy);border:none;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.93rem;font-weight:700;text-decoration:none;cursor:pointer;transition:all var(--transition);box-shadow:0 4px 14px rgba(245,158,11,.3);}
    .btn-view-spv:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
    .btn-view-outline{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.65rem 1rem;margin-top:.65rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;color:var(--text-muted);text-decoration:none;transition:all var(--transition);background:var(--surface-2);}
    .btn-view-outline:hover{border-color:var(--navy-light);color:var(--navy-mid);background:#eff4ff;}
    .invite-gate-notice{margin-top:.85rem;padding:.75rem 1rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);text-align:center;}
    .igw-title{font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.2rem;display:flex;align-items:center;justify-content:center;gap:.35rem;}
    .igw-sub{font-size:.72rem;color:var(--text-light);line-height:1.5;}
    /* No campaigns */
    .no-campaigns{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1.75rem 1.25rem;text-align:center;}
    .no-campaigns i{font-size:1.75rem;color:var(--border);display:block;margin-bottom:.65rem;}
    .no-campaigns p{font-size:.86rem;color:var(--text-muted);}
    /* Meta card */
    .meta-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);padding:1rem 1.1rem;}
    .meta-row{display:flex;gap:.5rem;padding:.45rem 0;border-bottom:1px solid var(--border);font-size:.83rem;}
    .meta-row:last-child{border-bottom:none;}
    .meta-label{color:var(--text-muted);min-width:88px;flex-shrink:0;display:flex;align-items:center;gap:.35rem;}
    .meta-label i{color:var(--navy-light);font-size:.78rem;width:14px;text-align:center;}
    .meta-value{color:var(--text);font-weight:500;word-break:break-word;}
    .legal-note{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.75rem .9rem;font-size:.75rem;color:#78350f;line-height:1.55;display:flex;gap:.5rem;align-items:flex-start;}
    .legal-note i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}

    /* ── RESPONSIVE ──────────────────────────────────────────── */
    @media(max-width:1100px){.page-body{grid-template-columns:1fr 300px;padding:0 1.5rem 2rem;gap:1.5rem;}.company-hero,.back-bar{padding-left:1.5rem;padding-right:1.5rem;}.main-tabs{padding-left:1.5rem;padding-right:1.5rem;}}
    @media(max-width:900px){.nav-sidebar,.header-nav{display:none;}}
    @media(max-width:768px){.page-body{grid-template-columns:1fr;padding:0 1rem 1.5rem;}.spv-sidebar{position:static;order:-1;}.company-hero,.back-bar{padding-left:1rem;padding-right:1rem;}.main-tabs{padding:0 1rem;}.hero-identity{padding:0 1rem 1.25rem;}.hero-company-name{font-size:1.5rem;}.tab-panels{padding:1.25rem;}.pitch-grid{grid-template-columns:1fr;}.pitch-field.span2{grid-column:span 1;}}
    @media(max-width:480px){.hero-banner{height:160px;}.hero-logo-wrap{width:56px;height:56px;}.info-grid{grid-template-columns:1fr 1fr;}.track-record-bar{flex-direction:column;gap:.75rem;}}
    @media screen and (min-width:900px){.hdd{display:none!important;}}
    </style>
</head>
<body>

<!-- ═══ HEADER ═══════════════════════════════════════════════ -->
<header class="top-header">
    <a href="/app/" class="header-brand">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/app/"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/invest/" class="active"><i class="fa-solid fa-compass"></i> Discover</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar" id="AvatarBtn"><?php echo htmlspecialchars($ini); ?></div>
    <div class="hdd" id="UserDropdown" role="menu">
        <div class="hdd-inner">
            <div class="hdd-sec">Dashboard</div>
            <a href="/app/" class="hdd-lnk"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
            <a href="/app/?tab=payouts" class="hdd-lnk"><i class="fa-solid fa-coins"></i> Payouts</a>
            <div class="hdd-sec">Discover</div>
            <a href="/app/invest/" class="hdd-lnk"><i class="fa-solid fa-compass"></i> Browse Campaigns</a>
            <div class="hdd-sec">Account</div>
            <a href="/app/wallet/" class="hdd-lnk"><i class="fa-solid fa-wallet"></i> Wallet</a>
            <a href="/app/profile.php" class="hdd-lnk"><i class="fa-solid fa-user"></i> Profile</a>
            <div class="hdd-div"></div>
            <a href="/app/auth/logout.php" class="hdd-lnk danger"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </div>
    </div>
</header>

<div class="page-wrapper">
    <aside class="nav-sidebar">
        <div class="ns-sec">Dashboard</div>
        <a href="/app/"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <div class="ns-sec">Discover</div>
        <a href="/app/invest/" class="active"><i class="fa-solid fa-compass"></i> Browse Campaigns</a>
        <div class="ns-sec">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <div class="main-content">
        <div class="back-bar">
            <a href="/app/invest/" class="back-link"><i class="fa-solid fa-arrow-left"></i> Back to Discover</a>
        </div>

        <!-- COMPANY HERO -->
        <div class="company-hero">
            <div class="hero-card">
                <div class="hero-banner" <?php if($banner):?>style="background-image:url('<?php echo htmlspecialchars($banner);?>')"<?php endif;?>>
                    <div class="hero-overlay"></div>
                    <?php if($hasFleet):?><div class="fleet-ribbon"><i class="fa-solid fa-truck" style="font-size:.68rem;"></i> Fleet Operator</div><?php endif;?>
                    <div class="verified-ribbon"><i class="fa-solid fa-circle-check" style="font-size:.68rem;"></i> Verified</div>
                    <div class="hero-logo-wrap">
                        <?php if($logo):?><img src="<?php echo htmlspecialchars($logo);?>" alt="<?php echo htmlspecialchars($company['name']);?>">
                        <?php else:?><div class="hero-logo-ph"><?php echo strtoupper(substr($company['name'],0,1));?></div><?php endif;?>
                    </div>
                </div>
                <div class="hero-identity">
                    <div class="hero-title-area">
                        <h1 class="hero-company-name"><?php echo htmlspecialchars($company['name']);?></h1>
                        <div class="hero-meta">
                            <span class="badge b-verified"><i class="fa-solid fa-circle-check"></i> Verified Operator</span>
                            <?php if($company['industry']):?><span class="badge b-industry"><?php echo htmlspecialchars($company['industry']);?></span><?php endif;?>
                            <?php if(!empty($company['area'])):?><span class="badge b-area-<?php echo htmlspecialchars($company['area']);?>"><?php echo $areaLabels[$company['area']]??'';?></span><?php endif;?>
                            <?php if($hasFleet):?><span class="badge b-fleet"><i class="fa-solid fa-truck" style="font-size:.7rem;"></i> Fleet Operator</span><?php endif;?>
                            <?php if($company['stage']):?><span class="badge b-stage"><?php echo ucfirst(str_replace('_',' ',$company['stage']));?></span><?php endif;?>
                        </div>
                    </div>
                </div>
                <!-- TABS -->
                <div class="main-tabs">
                    <button class="tab-btn active" onclick="switchTab('about',this)" data-tab="about"><i class="fa-solid fa-circle-info"></i> About</button>
                    <?php if($hasPerformance||!empty($financials)):?>
                    <button class="tab-btn" onclick="switchTab('performance',this)" data-tab="performance"><i class="fa-solid fa-chart-bar"></i> Track Record</button>
                    <?php endif;?>
                    <?php if(!empty($pitch)):?>
                    <button class="tab-btn" onclick="switchTab('pitch',this)" data-tab="pitch">
                        <i class="fa-solid fa-bullhorn"></i> The Pitch
                    </button>
                    <?php endif;?>
                    <?php if(!empty($milestones)):?>
                    <button class="tab-btn" onclick="switchTab('milestones',this)" data-tab="milestones">
                        <i class="fa-solid fa-trophy"></i> Milestones
                        <span class="tab-count"><?php echo count($milestones);?></span>
                    </button>
                    <?php endif;?>
                    <?php if($qCampaign):?>
                    <button class="tab-btn" onclick="switchTab('qa',this)" data-tab="qa" id="qaTabBtn">
                        <i class="fa-solid fa-comments"></i> Q&amp;A
                        <?php if(!empty($questions)):?><span class="tab-count"><?php echo count($questions);?></span><?php endif;?>
                    </button>
                    <?php endif;?>
                </div>
            </div>
        </div><!-- /.company-hero -->

        <!-- TWO-COLUMN BODY -->
        <div class="page-body">
            <!-- MAIN COLUMN -->
            <div>
            <div class="main-tab-card">
            <div class="tab-panels">

                <!-- ══ ABOUT ══ -->
                <div class="tab-panel active" data-panel="about">

                    <?php if($company['description']):?>
                    <p style="font-size:.92rem;color:var(--text-muted);line-height:1.7;margin-bottom:1.5rem;"><?php echo nl2br(htmlspecialchars($company['description']));?></p>
                    <?php endif;?>

                    <!-- Track record summary bar (US-302) -->
                    <?php if($trackRecord&&(float)($trackRecord['total_distributions_paid']??0)>0):?>
                    <div class="track-record-bar">
                        <div class="trb-item">
                            <div class="trb-val"><?php echo pub_money($trackRecord['total_distributions_paid']);?></div>
                            <div class="trb-lbl">Total distributions paid</div>
                        </div>
                        <div class="trb-divider"></div>
                        <?php if(!empty($trackRecord['avg_utilisation'])):?>
                        <div class="trb-item">
                            <div class="trb-val"><?php echo number_format((float)$trackRecord['avg_utilisation'],1);?>%</div>
                            <div class="trb-lbl">Avg. fleet utilisation</div>
                        </div>
                        <div class="trb-divider"></div>
                        <?php endif;?>
                        <div class="trb-item">
                            <div class="trb-val"><?php echo (int)($trackRecord['campaigns_with_metrics']??0);?></div>
                            <div class="trb-lbl">Campaigns with reported metrics</div>
                        </div>
                        <?php if(!empty($trackRecord['total_revenue'])):?>
                        <div class="trb-divider"></div>
                        <div class="trb-item">
                            <div class="trb-val"><?php echo pub_money($trackRecord['total_revenue']);?></div>
                            <div class="trb-lbl">Total gross revenue</div>
                        </div>
                        <?php endif;?>
                    </div>
                    <?php endif;?>

                    <!-- Company metrics -->
                    <div class="info-grid">
                        <?php if($company['founded_year']):?><div class="info-item"><span class="info-label">Founded</span><span class="info-value"><?php echo htmlspecialchars($company['founded_year']);?></span></div><?php endif;?>
                        <?php if($company['employee_count']):?><div class="info-item"><span class="info-label">Team Size</span><span class="info-value"><?php echo htmlspecialchars($company['employee_count']);?> employees</span></div><?php endif;?>
                        <?php if($company['stage']):?><div class="info-item"><span class="info-label">Stage</span><span class="info-value"><?php echo ucfirst(str_replace('_',' ',$company['stage']));?></span></div><?php endif;?>
                        <?php if(!empty($company['city'])||!empty($company['province'])):?><div class="info-item"><span class="info-label">Location</span><span class="info-value"><?php echo htmlspecialchars(implode(', ',array_filter([$company['city']??'',$company['province']??''])));?></span></div><?php endif;?>
                        <?php if($company['website']):?><div class="info-item"><span class="info-label">Website</span><span class="info-value"><a href="<?php echo htmlspecialchars($company['website']);?>" target="_blank" rel="noopener"><?php echo htmlspecialchars($company['website']);?></a></span></div><?php endif;?>
                        <?php if($company['industry']):?><div class="info-item"><span class="info-label">Industry</span><span class="info-value"><?php echo htmlspecialchars($company['industry']);?></span></div><?php endif;?>
                        <?php if(!empty($company['registration_number'])):?><div class="info-item"><span class="info-label">CIPC Reg.</span><span class="info-value"><?php echo htmlspecialchars($company['registration_number']);?></span></div><?php endif;?>
                    </div>

                    <?php if(!empty($highlights)):?>
                    <div class="sec-div">Key Highlights</div>
                    <div class="hl-wrap">
                        <?php foreach($highlights as $hl):?><div class="hl-chip"><span class="hv"><?php echo htmlspecialchars($hl['value']);?></span><span class="hl"><?php echo htmlspecialchars($hl['label']);?></span></div><?php endforeach;?>
                    </div>
                    <?php endif;?>

                </div><!-- /about -->

                <!-- ══ TRACK RECORD (US-302) ══ -->
                <?php if($hasPerformance||!empty($financials)):?>
                <div class="tab-panel" data-panel="performance">

                    <?php if($hasPerformance):?>
                    <div class="sec-div"><i class="fa-solid fa-chart-bar"></i> Campaign Performance History</div>
                    <div style="overflow-x:auto;">
                    <table class="perf-table">
                        <thead>
                            <tr>
                                <th>Campaign</th>
                                <th>Status</th>
                                <th style="text-align:right;">Actual Distrib.</th>
                                <th style="text-align:right;">Projected Distrib.</th>
                                <th style="text-align:right;">Variance</th>
                                <th>Periods</th>
                                <th>Disclosure</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach($campaignPerformance as $cp):
                            if(!(int)$cp['periods_reported']) continue;
                            $actual  =(float)($cp['actual_total']??0);
                            $proj    =(float)($cp['projected_total']??0);
                            $variance= $proj>0 ? round((($actual-$proj)/$proj)*100,1) : null;
                            $vClass  = ($variance!==null&&$variance>=0)?'variance-pos':'variance-neg';
                            $dl = $disclosureLabels[$cp['disclosure_type']??'self_reported']??['Self-Reported','dl-self'];
                        ?>
                        <tr>
                            <td>
                                <?php if(isset($invitedSet[array_search($cp['campaign_uuid'],array_column($campaigns,'campaign_uuid'))])):?>
                                <a href="/app/invest/campaign.php?cid=<?php echo urlencode($cp['campaign_uuid']);?>" style="color:var(--navy-mid);font-weight:600;text-decoration:none;"><?php echo htmlspecialchars($cp['campaign_title']);?></a>
                                <?php else:?>
                                <span style="font-weight:600;color:var(--text);"><?php echo htmlspecialchars($cp['campaign_title']);?></span>
                                <?php endif;?>
                            </td>
                            <td><span style="font-size:.75rem;font-weight:600;color:<?php echo $cp['campaign_status']==='funded'?'var(--green)':'var(--navy-mid)';?>"><?php echo ucfirst($cp['campaign_status']??'');?></span></td>
                            <td class="num" style="color:var(--green);font-weight:600;"><?php echo pub_money($actual);?></td>
                            <td class="num"><?php echo pub_money($proj);?></td>
                            <td class="num <?php echo $variance!==null?$vClass:'';?>"><?php echo $variance!==null?(($variance>=0?'+':'').number_format($variance,1).'%'):'—';?></td>
                            <td style="text-align:center;font-size:.82rem;color:var(--text-muted);"><?php echo (int)$cp['periods_reported'];?></td>
                            <td><span class="badge <?php echo $dl[1];?>"><?php echo $dl[0];?></span></td>
                        </tr>
                        <?php endforeach;?>
                        </tbody>
                    </table>
                    </div>
                    <p style="font-size:.74rem;color:var(--text-light);margin-top:.65rem;line-height:1.55;"><i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>Variance = (Actual − Projected) ÷ Projected. Self-reported figures have not been independently verified by Old Union.</p>
                    <?php endif;?>

                    <?php if(!empty($financials)):?>
                    <div class="sec-div"><i class="fa-solid fa-receipt"></i> Company Financials</div>
                    <div style="overflow-x:auto;">
                    <table class="fin-table">
                        <thead><tr><th>Period</th><th style="text-align:right;">Revenue</th><th style="text-align:right;">Gross Profit</th><th style="text-align:right;">Net Profit</th><th>Disclosure</th></tr></thead>
                        <tbody>
                        <?php foreach($financials as $fin):
                            $period=$fin['period_month']?($monthNames[(int)$fin['period_month']].' '.$fin['period_year']):($fin['period_year'].' Annual');
                            $dl=$disclosureLabels[$fin['disclosure_type']??'self_reported']??['Self-Reported','dl-self'];
                        ?>
                        <tr>
                            <td style="font-weight:600;"><?php echo htmlspecialchars($period);?></td>
                            <td class="num"><?php echo pub_money($fin['revenue']??null);?></td>
                            <td class="num"><?php echo pub_money($fin['gross_profit']??null);?></td>
                            <td class="num <?php echo isset($fin['net_profit'])&&(float)$fin['net_profit']<0?'neg':'';?>"><?php echo pub_money($fin['net_profit']??null);?></td>
                            <td><span class="badge <?php echo $dl[1];?>"><?php echo $dl[0];?></span></td>
                        </tr>
                        <?php endforeach;?>
                        </tbody>
                    </table>
                    </div>
                    <?php endif;?>

                </div><!-- /performance -->
                <?php endif;?>

                <!-- ══ PITCH ══ -->
                <?php if(!empty($pitch)):?>
                <div class="tab-panel" data-panel="pitch">
                    <?php
                    $pitchSections=[
                        ['problem_statement','The Problem','fa-triangle-exclamation'],
                        ['solution','Our Solution','fa-lightbulb'],
                        ['business_model','Business Model','fa-coins'],
                        ['traction','Traction & Milestones','fa-rocket'],
                        ['target_market','Target Market','fa-bullseye'],
                        ['competitive_landscape','Competitive Landscape','fa-chess-knight'],
                        ['team_overview','The Team','fa-people-group'],
                        ['risks_and_challenges','Risks & Challenges','fa-shield-halved'],
                        ['exit_strategy','Exit / Return Strategy','fa-door-open'],
                    ];
                    $activeSections=array_filter($pitchSections,fn($s)=>!empty($pitch[$s[0]]));
                    ?>
                    <?php if(!empty($activeSections)):?>
                    <div class="pitch-grid">
                        <?php foreach($activeSections as $idx=>[$field,$label,$icon]):
                            $isLast=count($activeSections)%2!==0&&$idx===count($activeSections)-1;
                        ?>
                        <div class="pitch-field<?php echo $isLast?' span2':'';?>">
                            <div class="pf-label"><i class="fa-solid <?php echo $icon;?>"></i><?php echo $label;?></div>
                            <div class="pf-body"><?php echo htmlspecialchars($pitch[$field]);?></div>
                        </div>
                        <?php endforeach;?>
                    </div>
                    <?php endif;?>
                    <?php if(!empty($pitch['pitch_deck_url'])||!empty($pitch['pitch_video_url'])):?>
                    <div class="pitch-assets">
                        <?php if(!empty($pitch['pitch_deck_url'])):?><a href="<?php echo htmlspecialchars($pitch['pitch_deck_url']);?>" target="_blank" rel="noopener" class="pitch-asset-btn"><i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> Download Pitch Deck</a><?php endif;?>
                        <?php if(!empty($pitch['pitch_video_url'])):?><a href="<?php echo htmlspecialchars($pitch['pitch_video_url']);?>" target="_blank" rel="noopener" class="pitch-asset-btn"><i class="fa-brands fa-youtube" style="color:#ff0000;"></i> Watch Pitch Video</a><?php endif;?>
                    </div>
                    <?php endif;?>
                </div>
                <?php endif;?>

                <!-- ══ MILESTONES ══ -->
                <?php if(!empty($milestones)):?>
                <div class="tab-panel" data-panel="milestones">
                    <div class="ms-timeline">
                        <?php foreach($milestones as $ms):?>
                        <div class="ms-item">
                            <div class="ms-dot"><i class="fa-solid fa-flag"></i></div>
                            <div class="ms-content">
                                <div class="ms-date"><?php echo pub_date($ms['milestone_date']);?></div>
                                <div class="ms-title"><?php echo htmlspecialchars($ms['title']);?></div>
                                <?php if($ms['description']):?><div class="ms-desc"><?php echo htmlspecialchars($ms['description']);?></div><?php endif;?>
                            </div>
                        </div>
                        <?php endforeach;?>
                    </div>
                </div>
                <?php endif;?>

                <!-- ══ Q&A ══ -->
                <?php if($qCampaign):?>
                <div class="tab-panel" data-panel="qa">
                    <p style="font-size:.78rem;color:var(--text-light);margin-bottom:1rem;">Questions relate to <strong><?php echo htmlspecialchars($qCampaign['title']);?></strong>.</p>
                    <?php if($qSuccess):?><div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($qSuccess);?></div><?php endif;?>
                    <?php if($qError):?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($qError);?></div><?php endif;?>
                    <?php if(empty($questions)):?><div class="empty-panel"><i class="fa-regular fa-comments"></i>No questions yet.</div>
                    <?php else: foreach($questions as $q):?>
                    <div class="qa-item">
                        <div class="qa-q"><i class="fa-solid fa-circle-question"></i><?php echo htmlspecialchars($q['question']);?></div>
                        <div class="qa-meta">Asked by <?php echo htmlspecialchars(pub_mask($q['asker_email']));?> &middot; <?php echo pub_date($q['asked_at']);?></div>
                        <?php if($q['answer']):?><div class="qa-a"><strong style="font-size:.74rem;text-transform:uppercase;letter-spacing:.06em;color:var(--navy-light);display:block;margin-bottom:.25rem;"><i class="fa-solid fa-building"></i> <?php echo htmlspecialchars($company['name']);?></strong><?php echo htmlspecialchars($q['answer']);?></div>
                        <?php else:?><div class="qa-pending">Awaiting response.</div><?php endif;?>
                    </div>
                    <?php endforeach; endif;?>
                    <div style="margin-top:1.25rem;border-top:1px solid var(--border);padding-top:1.25rem;">
                        <form method="POST" class="qa-form">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token;?>">
                            <textarea name="question" placeholder="Ask the operator a question…" rows="3" maxlength="1000"></textarea>
                            <div style="margin-top:.6rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem;">
                                <span style="font-size:.75rem;color:var(--text-light);">Questions are reviewed before being made public.</span>
                                <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-paper-plane"></i> Submit</button>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif;?>

            </div></div></div><!-- /.tab-panels/.main-tab-card/.main-col -->

            <!-- RIGHT SPV SIDEBAR -->
            <div class="spv-sidebar">

                <?php if(!empty($campaigns)):
                    if(count($campaigns)>1):?>
                <p style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);margin-bottom:.65rem;display:flex;align-items:center;gap:.4rem;"><i class="fa-solid fa-layer-group" style="color:var(--navy-light);"></i> <?php echo count($campaigns);?> Active SPV<?php echo count($campaigns)!==1?'s':'';?></p>
                    <?php endif;?>

                    <?php foreach($campaigns as $cam):
                        $isInvited=isset($invitedSet[(int)$cam['id']]);
                        $t=(float)$cam['raise_target'];
                        $r=(float)$cam['total_raised'];
                        $pct_c=$t>0?min(100,round(($r/$t)*100)):0;
                        $isFunded_c=$cam['status']==='funded';
                        $daysLeft_c=pub_days($cam['closes_at']);
                        $spotsLeft_c=max(0,(int)$cam['max_contributors']-(int)$cam['contributor_count']);
                        $isFleet_c=$cam['campaign_type']==='fleet_asset';
                        $fs=$fleetSummaries[$cam['id']]??null;
                        $typeChip=match($cam['campaign_type']){'fleet_asset'=>['Fleet Asset','fa-truck','ct-fleet'],'cooperative_membership'=>['Co-op','fa-people-roof','ct-co'],default=>['Revenue Share','fa-chart-line','ct-rs']};
                    ?>
                    <div class="spv-card">
                        <div class="spv-card-head">
                            <div class="spv-head-label">SPV Campaign</div>
                            <div class="spv-head-title"><?php echo htmlspecialchars($cam['title']);?></div>
                            <?php if(!empty($cam['tagline'])):?><div class="spv-head-tag"><?php echo htmlspecialchars($cam['tagline']);?></div><?php endif;?>
                            <span class="spv-type-chip <?php echo $typeChip[2];?>"><i class="fa-solid <?php echo $typeChip[1];?>"></i> <?php echo $typeChip[0];?></span>
                        </div>
                        <div class="spv-body">
                            <!-- Progress -->
                            <div class="spv-progress">
                                <div class="spv-raised"><?php echo pub_money($r);?></div>
                                <div class="spv-of">raised of <?php echo pub_money($t);?> target</div>
                                <div class="prog-outer"><div class="prog-inner <?php echo $isFunded_c?'prog-funded':'prog-open';?>" style="width:<?php echo $pct_c;?>%"></div></div>
                                <div class="prog-stats"><span><?php echo $pct_c;?>% funded</span><span><?php echo (int)$cam['contributor_count'];?>/<?php echo (int)$cam['max_contributors'];?> investors</span></div>
                            </div>
                            <!-- Fleet asset summary chips -->
                            <?php if($isFleet_c&&$fs):?>
                            <div class="fleet-summary-chips">
                                <div class="fsc"><span class="fsc-val"><?php echo (int)($fs['active']??$fs['active_count']??0);?>/<?php echo (int)($fs['total']??$fs['total_count']??0);?></span><span class="fsc-lbl">Active assets</span></div>
                                <?php if(!empty($cam['hurdle_rate'])):?><div class="fsc"><span class="fsc-val"><?php echo number_format((float)$cam['hurdle_rate'],1);?>%</span><span class="fsc-lbl">Hurdle rate</span></div><?php endif;?>
                                <?php if(!empty($cam['term_months'])):?><div class="fsc"><span class="fsc-val"><?php echo (int)$cam['term_months'];?> mo</span><span class="fsc-lbl">Term</span></div><?php endif;?>
                            </div>
                            <?php endif;?>
                            <!-- Key stats -->
                            <div style="border-top:1px solid var(--border);padding-top:.75rem;margin-bottom:.75rem;">
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Min. investment</span><span class="spv-stat-val"><?php echo pub_money($cam['min_contribution']);?></span></div>
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Closes</span><span class="spv-stat-val"><?php echo pub_date($cam['closes_at']);?></span></div>
                                <div class="spv-stat-row"><span class="spv-stat-lbl">Spots remaining</span><span class="spv-stat-val"><?php echo $spotsLeft_c;?></span></div>
                            </div>
                            <!-- Invite-aware CTA -->
                            <?php if($isInvited):?>
                                <a href="/app/invest/campaign.php?cid=<?php echo urlencode($cam['campaign_uuid']);?>" class="btn-view-spv">
                                    <i class="fa-solid fa-rocket"></i> <?php echo $isFunded_c?'View Funded Campaign':'View &amp; Invest';?>
                                </a>
                            <?php elseif(false /* pending invite check placeholder */):?>
                                <div class="invite-gate-notice">
                                    <div class="igw-title"><i class="fa-solid fa-hourglass-half" style="font-size:.72rem;"></i> Invitation Pending</div>
                                    <div class="igw-sub">Your request is awaiting approval from the operator.</div>
                                </div>
                            <?php else:?>
                                <div class="invite-gate-notice">
                                    <div class="igw-title"><i class="fa-solid fa-lock" style="font-size:.72rem;"></i> Invitation Required</div>
                                    <div class="igw-sub">Contact the operator to request access to this private placement.</div>
                                </div>
                                <a href="/app/invest/campaign.php?cid=<?php echo urlencode($cam['campaign_uuid']);?>" class="btn-view-outline">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Request Access
                                </a>
                            <?php endif;?>
                        </div>
                    </div><!-- /.spv-card -->
                    <?php endforeach;?>

                    <div class="legal-note"><i class="fa-solid fa-scale-balanced"></i><div>Each campaign is a separate SPV. Max <strong>50 contributors</strong> per campaign under SA private placement regulations.</div></div>

                <?php else:?>
                <div class="no-campaigns">
                    <i class="fa-solid fa-rocket"></i>
                    <p style="font-weight:600;color:var(--text-muted);margin-bottom:.3rem;">No active campaigns</p>
                    <p>This operator isn't raising at this time. Check back soon.</p>
                </div>
                <?php endif;?>

                <!-- Company meta card -->
                <div class="meta-card">
                    <?php if($company['email']):?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-envelope"></i>Email</span><span class="meta-value"><?php echo htmlspecialchars($company['email']);?></span></div><?php endif;?>
                    <?php if($company['phone']):?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-phone"></i>Phone</span><span class="meta-value"><?php echo htmlspecialchars($company['phone']);?></span></div><?php endif;?>
                    <?php if($company['registration_number']):?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-hashtag"></i>CIPC Reg.</span><span class="meta-value"><?php echo htmlspecialchars($company['registration_number']);?></span></div><?php endif;?>
                    <?php if($company['founded_year']):?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-calendar"></i>Founded</span><span class="meta-value"><?php echo htmlspecialchars($company['founded_year']);?></span></div><?php endif;?>
                    <?php if($company['website']):?><div class="meta-row"><span class="meta-label"><i class="fa-solid fa-globe"></i>Website</span><span class="meta-value"><a href="<?php echo htmlspecialchars($company['website']);?>" target="_blank" rel="noopener" style="color:var(--navy-light);text-decoration:none;"><?php echo htmlspecialchars($company['website']);?></a></span></div><?php endif;?>
                </div>

            </div><!-- /.spv-sidebar -->
        </div><!-- /.page-body -->
    </div><!-- /.main-content -->
</div><!-- /.page-wrapper -->

<script>
function switchTab(panel, btn) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    if (btn) btn.classList.add('active');
    const el = document.querySelector('[data-panel="' + panel + '"]');
    if (el) el.classList.add('active');
    history.replaceState(null, '', '#' + panel);
}
document.addEventListener('DOMContentLoaded', () => {
    const hash = window.location.hash.replace('#', '');
    const valid = ['about', 'performance', 'pitch', 'milestones', 'qa'];
    if (hash && valid.includes(hash)) {
        const btn = document.querySelector('[data-tab="' + hash + '"]');
        if (btn) switchTab(hash, btn);
    }
});
(function () {
    const btn = document.getElementById('AvatarBtn');
    const dd  = document.getElementById('UserDropdown');
    if (!btn || !dd) return;
    const close  = () => dd.classList.remove('hdd--open');
    const toggle = () => { if (window.innerWidth >= 900) { close(); return; } dd.classList.contains('hdd--open') ? close() : dd.classList.add('hdd--open'); };
    btn.addEventListener('click', e => { e.stopPropagation(); toggle(); });
    document.addEventListener('click', e => { if (!dd.contains(e.target) && !btn.contains(e.target)) close(); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
    window.addEventListener('resize', () => { if (window.innerWidth >= 900) close(); });
})();
</script>
</body>
</html>
