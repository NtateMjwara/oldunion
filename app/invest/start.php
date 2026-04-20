<?php
/**
 * /app/invest/start.php
 * US-601 — Fleet waterfall distribution calculator             Team B
 *
 * Fleet changes vs original:
 *   - FleetService::buildFleetParamsJson() injects FLEET_PARAMS
 *   - FleetService::calculateDistribution() pre-calculates at min amount
 *   - "Illustrative Return" → "Projected Distribution" panel for fleet
 *   - Quick-amount buttons show ~monthly distribution estimate
 *   - Hurdle status pill (above / below)
 *   - −10% utilisation sensitivity note
 * Non-fleet: revenue-share illustration unchanged (no regression).
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InvestmentService.php';
require_once '../includes/FleetService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/invest/start.php?cid=' . urlencode($_GET['cid'] ?? '')));
}

$campaignUuid = trim($_GET['cid'] ?? '');
if (empty($campaignUuid)) { redirect('/app/invest/'); }

$campaign = InvestmentService::getCampaignForInvestment($campaignUuid);
if (!$campaign) { redirect('/app/invest/'); }

$userId     = (int)$_SESSION['user_id'];
$csrf_token = generateCSRFToken();
$errors     = [];
$isFleet    = $campaign['campaign_type'] === 'fleet_asset';

$existing = InvestmentService::getUserContribution($userId, (int)$campaign['id']);
if ($existing) {
    redirect('/app/invest/success.php?already=1&cid=' . urlencode($campaignUuid));
}

/* POST: validate + store intent */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $amountRaw = trim($_POST['amount'] ?? '');
        if ($amountRaw === '' || !is_numeric($amountRaw)) {
            $errors[] = 'Please enter a valid investment amount.';
        } else {
            $amount = (float)$amountRaw;
            $min    = (float)$campaign['min_contribution'];
            $max    = $campaign['max_contribution'] ? (float)$campaign['max_contribution'] : null;
            if ($amount < $min) {
                $errors[] = 'Minimum investment is R ' . number_format($min, 0, '.', ' ') . '.';
            } elseif ($max !== null && $amount > $max) {
                $errors[] = 'Maximum investment is R ' . number_format($max, 0, '.', ' ') . '.';
            } elseif ($campaign['raise_maximum'] !== null) {
                $remaining = (float)$campaign['raise_maximum'] - (float)$campaign['total_raised'];
                if ($amount > $remaining) {
                    $errors[] = 'Only R ' . number_format($remaining, 0, '.', ' ') . ' of the hard cap remains.';
                }
            }
            if (empty($errors)) {
                $_SESSION['invest_intent'] = [
                    'campaign_uuid' => $campaign['uuid'],
                    'campaign_id'   => $campaign['id'],
                    'amount'        => $amount,
                    'expires_at'    => time() + 900,
                ];
                redirect('/app/invest/confirm.php');
            }
        }
    }
}

/* Fleet data */
$fleetParams   = $isFleet ? FleetService::buildFleetParamsJson((int)$campaign['id'], $campaign) : ['isFleet' => false];
$fleetCalcBase = $isFleet ? FleetService::calculateDistribution((float)$campaign['min_contribution'], (int)$campaign['id']) : null;

/* Helpers */
function s601_money(?float $v): string { return ($v===null||$v<=0) ? '—' : 'R '.number_format($v,0,'.',' '); }
function s601_date(?string $v): string { return $v ? date('d M Y', strtotime($v)) : '—'; }

$target   = (float)$campaign['raise_target'];
$raised   = (float)$campaign['total_raised'];
$pct      = $target > 0 ? min(100, round(($raised/$target)*100)) : 0;
$days     = max(0,(int)ceil((strtotime($campaign['closes_at'])-time())/86400));
$min      = (float)$campaign['min_contribution'];
$maxAmt   = $campaign['max_contribution'] ? (float)$campaign['max_contribution'] : null;
$ctLabels = ['revenue_share'=>['Revenue Share','fa-chart-line','ct-rs'],'cooperative_membership'=>['Co-op','fa-people-roof','ct-co'],'fleet_asset'=>['Fleet Asset SPV','fa-truck','ct-fleet']];
$ctInfo   = $ctLabels[$campaign['campaign_type']] ?? ['Campaign','fa-rocket','ct-rs'];

$quickAmts = array_values(array_unique(array_filter(
    [$min, $min*2, $min*5, $min*10],
    fn($v) => !$maxAmt || $v <= $maxAmt
)));
sort($quickAmts);

$pdo = Database::getInstance();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?"); $stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'],0,1)) : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Invest in <?php echo htmlspecialchars($campaign['company_name']); ?> | Old Union</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--border-focus:#1a56b0;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07);--shadow-card:0 8px 28px rgba(11,37,69,.09);--header-h:64px;--transition:.2s cubic-bezier(.4,0,.2,1);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}.header-brand span{color:#c8102e;}
.header-steps{display:flex;align-items:center;gap:.15rem;}
.step-pill{display:flex;align-items:center;gap:.35rem;padding:.35rem .9rem;font-size:.8rem;font-weight:600;color:var(--text-light);border-radius:99px;white-space:nowrap;}
.step-pill.active{background:#eff4ff;color:var(--navy-mid);}
.step-pill.done{color:var(--green);}
.step-sep{color:var(--border);font-size:.7rem;}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}
.page{padding-top:var(--header-h);min-height:100vh;padding-bottom:3rem;}
.page-inner{max-width:980px;margin:0 auto;padding:2.5rem 1.5rem;display:grid;grid-template-columns:1fr 400px;gap:2rem;align-items:start;}

/* Summary card */
.summary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow-card);overflow:hidden;}
.sh{background:var(--navy);padding:1.5rem;display:flex;align-items:flex-start;gap:1rem;position:relative;overflow:hidden;}
.sh::after{content:'';position:absolute;inset:0;background:repeating-linear-gradient(-45deg,transparent,transparent 24px,rgba(200,168,75,.04) 24px,rgba(200,168,75,.04) 25px);pointer-events:none;}
.sh-logo{width:52px;height:52px;border-radius:10px;background:rgba(255,255,255,.1);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;position:relative;z-index:1;}
.sh-logo img{width:100%;height:100%;object-fit:cover;}
.sh-logo-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.4rem;color:#d4af3c;}
.sh-text{position:relative;z-index:1;}
.sh-company{font-size:.73rem;font-weight:600;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem;}
.sh-title{font-family:'DM Serif Display',serif;font-size:1.2rem;color:#fff;line-height:1.2;margin-bottom:.35rem;}
.sh-tagline{font-size:.8rem;color:rgba(255,255,255,.58);}
.type-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700;margin-top:.55rem;}
.ct-rs{background:rgba(26,86,176,.3);color:#93c5fd;}.ct-co{background:rgba(11,107,77,.3);color:#6ee7b7;}.ct-fleet{background:rgba(3,105,161,.3);color:#7dd3fc;}
.sb{padding:1.25rem;}
.prog-raised{font-size:1.3rem;font-weight:700;color:var(--navy);}
.prog-lbl{font-size:.74rem;color:var(--text-light);margin-bottom:.55rem;}
.prog-outer{height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);margin-bottom:.4rem;}
.prog-inner{height:100%;border-radius:99px;}
.prog-open{background:var(--amber);}.prog-funded{background:var(--green);}
.prog-stats{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);}
.stat-row{display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.84rem;}
.stat-row:last-child{border-bottom:none;}
.stat-lbl{color:var(--text-muted);}.stat-val{font-weight:600;color:var(--text);}
.terms-preview{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.85rem 1rem;margin-top:.85rem;}
.tp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);margin-bottom:.45rem;display:flex;align-items:center;gap:.4rem;}
.tp-label i{color:var(--navy-light);}
.tp-row{display:flex;justify-content:space-between;font-size:.82rem;padding:.3rem 0;border-bottom:1px solid var(--border);}
.tp-row:last-child{border-bottom:none;}
.tp-lbl{color:var(--text-muted);}.tp-val{font-weight:600;color:var(--navy-mid);}
/* Fleet highlights strip */
.fleet-hl{display:flex;gap:.5rem;flex-wrap:wrap;margin-top:.85rem;}
.fhl{display:flex;flex-direction:column;align-items:center;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.45rem .75rem;flex:1;min-width:72px;text-align:center;}
.fhl-val{font-size:.88rem;font-weight:700;color:var(--navy);}
.fhl-lbl{font-size:.67rem;color:var(--text-light);margin-top:.1rem;}

/* Form card */
.form-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
.fch{padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
.fct{font-size:.88rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:.45rem;}
.fct i{color:var(--navy-light);}
.fcb{padding:1.25rem;}
.field-label{display:block;font-size:.82rem;font-weight:600;color:var(--text);margin-bottom:.45rem;}
.amount-wrap{position:relative;margin-bottom:.4rem;}
.amount-prefix{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);font-size:1.1rem;font-weight:700;color:var(--text-muted);pointer-events:none;}
.amount-input{width:100%;padding:.85rem 1rem .85rem 2.4rem;border:2px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:1.35rem;font-weight:700;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
.amount-input:focus{border-color:var(--border-focus);background:#fff;box-shadow:0 0 0 4px rgba(26,86,176,.1);}
.amount-hint{font-size:.75rem;color:var(--text-light);margin-bottom:1rem;display:flex;align-items:center;gap:.3rem;}

/* Quick-amount buttons */
.quick-amounts{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;}
.quick-btn{padding:.4rem .85rem;border:1.5px solid var(--border);border-radius:99px;background:var(--surface-2);font-family:'DM Sans',sans-serif;cursor:pointer;transition:all var(--transition);display:flex;flex-direction:column;align-items:center;gap:.05rem;min-width:82px;}
.quick-btn:hover{border-color:var(--navy-light);color:var(--navy-mid);background:#eff4ff;}
.qa-amount{font-size:.82rem;font-weight:700;color:var(--text);}
.qa-distrib{font-size:.68rem;color:var(--green);font-weight:500;}

/* US-601: Fleet distribution panel */
.fleet-panel{background:linear-gradient(135deg,#eff4ff 0%,#f0fdf4 100%);border:1.5px solid #c7d9f8;border-radius:var(--radius-sm);padding:1rem 1.1rem;margin-bottom:1rem;}
.fp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);margin-bottom:.5rem;display:flex;align-items:center;gap:.35rem;}
.fp-main{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);line-height:1;margin-bottom:.3rem;}
.fp-main small{font-family:'DM Sans',sans-serif;font-size:.88rem;font-weight:400;color:var(--text-muted);}
.fp-rows{display:flex;flex-direction:column;gap:.28rem;margin-bottom:.6rem;}
.fp-row{display:flex;justify-content:space-between;font-size:.82rem;}
.fp-lbl{color:var(--text-muted);}
.fp-val{font-weight:600;color:var(--text);}
.fp-val.pos{color:var(--green);}
/* Hurdle pill */
.hurdle-pill{display:inline-flex;align-items:center;gap:.35rem;padding:.28rem .75rem;border-radius:99px;font-size:.76rem;font-weight:600;border:1px solid transparent;margin-bottom:.5rem;}
.hp-above{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.hp-below{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
/* Sensitivity */
.fp-sens{background:rgba(11,37,69,.04);border-radius:var(--radius-sm);padding:.5rem .7rem;font-size:.74rem;color:var(--text-muted);line-height:1.5;}
.fp-sens i{color:var(--amber-dark);margin-right:.25rem;font-size:.68rem;}

/* Non-fleet return preview */
.return-preview{background:#eff4ff;border:1px solid #c7d9f8;border-radius:var(--radius-sm);padding:.85rem 1rem;margin-bottom:1rem;}
.rp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem;}
.rp-text{font-size:.84rem;color:var(--navy-mid);line-height:1.55;}

.btn-invest{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.85rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;background:var(--amber);color:var(--navy);border:none;box-shadow:0 4px 14px rgba(245,158,11,.3);transition:all var(--transition);}
.btn-invest:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;margin-top:.85rem;font-size:.85rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
.btn-back:hover{color:var(--navy);}
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.legal-note{font-size:.73rem;color:var(--text-light);line-height:1.55;margin-top:.85rem;display:flex;align-items:flex-start;gap:.4rem;}
.legal-note i{flex-shrink:0;margin-top:.1rem;}
@media(max-width:800px){.page-inner{grid-template-columns:1fr;}.header-steps{display:none;}}
</style>
</head>
<body>
<header class="top-header">
    <a href="/app/invest/" class="header-brand">Old <span>U</span>nion</a>
    <div class="header-steps">
        <div class="step-pill active"><i class="fa-solid fa-circle-dot"></i> Amount</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Confirm</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Done</div>
    </div>
    <div class="avatar"><?php echo htmlspecialchars($ini); ?></div>
</header>

<div class="page"><div class="page-inner">

<!-- Campaign summary -->
<div class="summary-card">
    <div class="sh">
        <div class="sh-logo">
            <?php if($campaign['company_logo']):?><img src="<?php echo htmlspecialchars($campaign['company_logo']);?>" alt="">
            <?php else:?><div class="sh-logo-ph"><?php echo strtoupper(substr($campaign['company_name'],0,1));?></div><?php endif;?>
        </div>
        <div class="sh-text">
            <div class="sh-company"><?php echo htmlspecialchars($campaign['company_name']);?></div>
            <div class="sh-title"><?php echo htmlspecialchars($campaign['title']);?></div>
            <?php if($campaign['tagline']):?><div class="sh-tagline"><?php echo htmlspecialchars($campaign['tagline']);?></div><?php endif;?>
            <div class="type-chip <?php echo $ctInfo[2];?>"><i class="fa-solid <?php echo $ctInfo[1];?>"></i> <?php echo $ctInfo[0];?></div>
        </div>
    </div>
    <div class="sb">
        <div class="prog-raised"><?php echo s601_money($raised);?></div>
        <div class="prog-lbl">raised of <?php echo s601_money($target);?> target</div>
        <div class="prog-outer"><div class="prog-inner <?php echo $campaign['status']==='funded'?'prog-funded':'prog-open';?>" style="width:<?php echo $pct;?>%"></div></div>
        <div class="prog-stats"><span><?php echo $pct;?>% funded</span><span><?php echo (int)$campaign['contributor_count'];?>/<?php echo (int)$campaign['max_contributors'];?> investors</span></div>
        <div style="margin-top:1rem;">
            <div class="stat-row"><span class="stat-lbl">Closes</span><span class="stat-val"><?php echo s601_date($campaign['closes_at']);?> &nbsp;·&nbsp; <?php echo $days;?> day<?php echo $days!==1?'s':'';?> left</span></div>
            <div class="stat-row"><span class="stat-lbl">Min. Investment</span><span class="stat-val"><?php echo s601_money($min);?></span></div>
            <?php if($maxAmt):?><div class="stat-row"><span class="stat-lbl">Max. Investment</span><span class="stat-val"><?php echo s601_money($maxAmt);?></span></div><?php endif;?>
            <div class="stat-row"><span class="stat-lbl">Spots Remaining</span><span class="stat-val"><?php echo max(0,(int)$campaign['max_contributors']-(int)$campaign['contributor_count']);?></span></div>
        </div>
        <div class="terms-preview">
            <div class="tp-label"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
            <?php if($isFleet):?>
                <div class="tp-row"><span class="tp-lbl">Type</span><span class="tp-val">Fleet Asset SPV</span></div>
                <?php if(!empty($campaign['hurdle_rate'])):?><div class="tp-row"><span class="tp-lbl">Hurdle rate</span><span class="tp-val"><?php echo number_format((float)$campaign['hurdle_rate'],1);?>% p.a.</span></div><?php endif;?>
                <?php if(!empty($campaign['investor_waterfall_pct'])):?><div class="tp-row"><span class="tp-lbl">Investor waterfall</span><span class="tp-val"><?php echo number_format((float)$campaign['investor_waterfall_pct'],0);?>% above hurdle</span></div><?php endif;?>
                <?php if(!empty($campaign['term_months'])):?><div class="tp-row"><span class="tp-lbl">Term</span><span class="tp-val"><?php echo (int)$campaign['term_months'];?> months</span></div><?php endif;?>
                <?php if(!empty($campaign['distribution_frequency'])):?><div class="tp-row"><span class="tp-lbl">Distributions</span><span class="tp-val"><?php echo ucfirst($campaign['distribution_frequency']);?></span></div><?php endif;?>
            <?php elseif($campaign['campaign_type']==='revenue_share'):?>
                <div class="tp-row"><span class="tp-lbl">Type</span><span class="tp-val">Revenue Share</span></div>
                <div class="tp-row"><span class="tp-lbl">Monthly Share</span><span class="tp-val"><?php echo htmlspecialchars((string)$campaign['revenue_share_percentage']);?>% of revenue</span></div>
                <div class="tp-row"><span class="tp-lbl">Duration</span><span class="tp-val"><?php echo htmlspecialchars((string)$campaign['revenue_share_duration_months']);?> months</span></div>
            <?php elseif($campaign['campaign_type']==='cooperative_membership'):?>
                <div class="tp-row"><span class="tp-lbl">Type</span><span class="tp-val">Co-op Membership</span></div>
                <div class="tp-row"><span class="tp-lbl">Unit</span><span class="tp-val"><?php echo htmlspecialchars($campaign['unit_name']??'—');?></span></div>
                <div class="tp-row"><span class="tp-lbl">Price/Unit</span><span class="tp-val"><?php echo s601_money((float)($campaign['unit_price']??0));?></span></div>
            <?php endif;?>
            <div class="tp-row"><span class="tp-lbl">Governing Law</span><span class="tp-val"><?php echo htmlspecialchars($campaign['governing_law']??'Republic of South Africa');?></span></div>
        </div>
        <?php if($isFleet&&$fleetCalcBase):?>
        <div class="fleet-hl">
            <div class="fhl"><span class="fhl-val"><?php echo number_format((float)$fleetCalcBase['hurdle_rate'],1);?>%</span><span class="fhl-lbl">Hurdle p.a.</span></div>
            <div class="fhl"><span class="fhl-val"><?php echo number_format((float)$fleetCalcBase['investor_waterfall_pct'],0);?>%</span><span class="fhl-lbl">Waterfall</span></div>
            <div class="fhl"><span class="fhl-val"><?php echo number_format((float)$fleetCalcBase['annual_yield_pct'],1);?>%</span><span class="fhl-lbl">Indicative yield</span></div>
            <div class="fhl"><span class="fhl-val"><?php echo htmlspecialchars($fleetCalcBase['stabilised_period_label']??'—');?></span><span class="fhl-lbl">Stabilises at</span></div>
        </div>
        <?php endif;?>
    </div>
</div>

<!-- Amount form -->
<div>
<div class="form-card">
    <div class="fch"><div class="fct"><i class="fa-solid fa-hand-holding-dollar"></i> How much would you like to invest?</div></div>
    <div class="fcb">
        <?php if(!empty($errors)):?><div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($errors[0]);?></div><?php endif;?>
        <form method="POST" id="investForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token;?>">
            <label for="amount" class="field-label">Investment Amount</label>
            <div class="amount-wrap">
                <span class="amount-prefix">R</span>
                <input type="number" id="amount" name="amount" class="amount-input"
                       min="<?php echo htmlspecialchars((string)$campaign['min_contribution']);?>"
                       <?php if($maxAmt):?>max="<?php echo (int)$maxAmt;?>"<?php endif;?>
                       step="100"
                       value="<?php echo htmlspecialchars($_POST['amount']??number_format($min,0,'.','')); ?>"
                       autofocus required>
            </div>
            <div class="amount-hint"><i class="fa-solid fa-circle-info"></i> Min: <?php echo s601_money($min);?><?php if($maxAmt):?> &nbsp;·&nbsp; Max: <?php echo s601_money($maxAmt);?><?php endif;?></div>

            <!-- Quick-amount buttons (US-601) -->
            <?php if(!empty($quickAmts)):?>
            <div class="quick-amounts">
                <?php foreach($quickAmts as $qa):?>
                <button type="button" class="quick-btn" onclick="setAmount(<?php echo (int)$qa;?>)">
                    <span class="qa-amount">R <?php echo number_format($qa,0,'.',' ');?></span>
                    <?php if($isFleet):?><span class="qa-distrib" id="qa-d-<?php echo (int)$qa;?>">…</span><?php endif;?>
                </button>
                <?php endforeach;?>
            </div>
            <?php endif;?>

            <!-- US-601: Fleet projected distribution panel -->
            <?php if($isFleet):?>
            <div class="fleet-panel" id="fleetPanel">
                <div class="fp-label"><i class="fa-solid fa-chart-pie"></i> Projected Distribution</div>
                <div class="fp-main" id="fpMonthly">
                    <?php echo $fleetCalcBase ? s601_money($fleetCalcBase['monthly_distribution']) : 'Enter an amount'; ?>
                    <?php if($fleetCalcBase):?><small> / month</small><?php endif;?>
                </div>
                <div class="fp-rows">
                    <div class="fp-row"><span class="fp-lbl">Annual yield</span><span class="fp-val pos" id="fpYield"><?php echo $fleetCalcBase ? number_format($fleetCalcBase['annual_yield_pct'],1).'% p.a.' : '—';?></span></div>
                    <div class="fp-row"><span class="fp-lbl">Your SPV share</span><span class="fp-val" id="fpShare"><?php echo $fleetCalcBase ? number_format($fleetCalcBase['share_pct'],3).'%' : '—';?></span></div>
                </div>
                <div class="hurdle-pill <?php echo ($fleetCalcBase&&$fleetCalcBase['above_hurdle'])?'hp-above':'hp-below';?>" id="hurdlePill">
                    <i class="fa-solid <?php echo ($fleetCalcBase&&$fleetCalcBase['above_hurdle'])?'fa-circle-check':'fa-triangle-exclamation';?>" style="font-size:.78rem;"></i>
                    <span id="hurdleText"><?php
                        if($fleetCalcBase){
                            echo $fleetCalcBase['above_hurdle']
                                ? 'Above hurdle — full waterfall applies'
                                : 'Below hurdle — '.number_format($fleetCalcBase['hurdle_rate'],1).'% p.a. threshold';
                        } else { echo 'Enter an amount to see hurdle status'; }
                    ?></span>
                </div>
                <div class="fp-sens">
                    <i class="fa-solid fa-triangle-exclamation"></i>
                    At &minus;10% utilisation: est. <strong id="fpSens"><?php
                        if($fleetCalcBase){ echo s601_money($fleetCalcBase['monthly_distribution'] * 0.9).'/mo'; }
                        else { echo '—'; }
                    ?></strong>
                </div>
            </div>

            <?php elseif($campaign['campaign_type']==='revenue_share'&&$campaign['revenue_share_percentage']&&$campaign['revenue_share_duration_months']):?>
            <!-- Non-fleet: keep original revenue-share illustration (no regression) -->
            <div class="return-preview" id="returnPreview">
                <div class="rp-label"><i class="fa-solid fa-calculator"></i> Illustrative Return</div>
                <div class="rp-text" id="returnText">Enter an amount to see your illustrative share.</div>
            </div>
            <?php endif;?>

            <button type="submit" class="btn-invest">
                Continue to Review <i class="fa-solid fa-arrow-right"></i>
            </button>
        </form>
        <div class="legal-note"><i class="fa-solid fa-scale-balanced"></i><span>Max <strong><?php echo (int)$campaign['max_contributors'];?> contributors</strong> per campaign under SA private placement regulations.</span></div>
    </div>
</div>
<a href="/app/invest/campaign.php?cid=<?php echo urlencode($campaignUuid);?>" class="btn-back"><i class="fa-solid fa-arrow-left"></i> Back to campaign</a>
</div>

</div></div><!-- /.page-inner/.page -->

<script>
const FLEET_PARAMS = <?php echo json_encode($fleetParams, JSON_UNESCAPED_UNICODE); ?>;
const RAISE_TARGET = <?php echo (float)$campaign['raise_target']; ?>;
const RS_PCT       = <?php echo (float)($campaign['revenue_share_percentage'] ?? 0); ?>;
const RS_DUR       = <?php echo (int)($campaign['revenue_share_duration_months'] ?? 0); ?>;

function calcWaterfall(amount) {
    if (!FLEET_PARAMS.isFleet || !amount || amount <= 0) return null;
    const fp=FLEET_PARAMS, tr=fp.totalRaise||500000, st=fp.stabilisedNetIncome||87500,
          hr=fp.hurdleRate||8, ip=fp.investorWaterfallPct||85, mp=fp.managementFeePct||5, fb=fp.managementFeeBasis||'gross';
    const share=amount/tr, hAmt=tr*(hr/100)/12, above=Math.max(0,st-hAmt);
    const mgmt=fb==='gross'?st*(mp/100):above*(mp/100);
    const pool=(above-mgmt)*(ip/100), monthly=pool*share;
    const yield_=tr>0?(monthly*12/amount)*100:0;
    // sensitivity −10%
    const st90=st*0.9,a90=Math.max(0,st90-hAmt),m90=fb==='gross'?st90*(mp/100):a90*(mp/100);
    const sens90=(a90-m90)*(ip/100)*share;
    return {share,monthly,yield:yield_,aboveHurdle:st>=hAmt,sens90,hurdleRate:hr};
}

const fmtR = v => 'R ' + Math.round(v).toLocaleString('en-ZA');

function setAmount(v) { document.getElementById('amount').value = v; updateDisplay(); }

function updateDisplay() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    if (FLEET_PARAMS.isFleet) {
        if (amount < 100) return;
        const res = calcWaterfall(amount); if (!res) return;
        const mEl=document.getElementById('fpMonthly'), yEl=document.getElementById('fpYield'),
              sEl=document.getElementById('fpShare'), hEl=document.getElementById('hurdlePill'),
              htEl=document.getElementById('hurdleText'), snsEl=document.getElementById('fpSens');
        if(mEl) mEl.innerHTML = fmtR(res.monthly) + ' <small>/ month</small>';
        if(yEl) yEl.textContent = res.yield.toFixed(1) + '% p.a.';
        if(sEl) sEl.textContent = (res.share*100).toFixed(3) + '%';
        if(snsEl) snsEl.textContent = fmtR(res.sens90) + '/mo';
        if(hEl)  hEl.className = 'hurdle-pill ' + (res.aboveHurdle?'hp-above':'hp-below');
        if(htEl) htEl.textContent = res.aboveHurdle ? 'Above hurdle — full waterfall applies' : 'Below hurdle — '+res.hurdleRate.toFixed(1)+'% p.a. threshold';
    } else if (RS_PCT && RS_DUR && RAISE_TARGET > 0) {
        const el = document.getElementById('returnText'); if (!el || !amount) return;
        const share = amount/RAISE_TARGET, pct=(share*RS_PCT).toFixed(4);
        el.innerHTML = 'At <strong>R '+amount.toLocaleString('en-ZA')+'</strong> invested, you hold <strong>'+(share*100).toFixed(2)+'%</strong> of this raise, entitling you to <strong>'+pct+'% of monthly revenue</strong> for '+RS_DUR+' months.<br><span style="font-size:.76rem;color:var(--text-light)">Actual returns depend on reported monthly revenue.</span>';
    }
    // Quick-button distribution labels
    document.querySelectorAll('.quick-btn').forEach(btn => {
        const qa = parseInt(btn.getAttribute('onclick').match(/\d+/)[0], 10);
        const lEl = document.getElementById('qa-d-' + qa); if (!lEl) return;
        if (FLEET_PARAMS.isFleet) { const r=calcWaterfall(qa); lEl.textContent = r ? '~'+fmtR(r.monthly)+'/mo' : ''; }
    });
}

document.addEventListener('DOMContentLoaded', () => { if (FLEET_PARAMS.isFleet) updateDisplay(); });
document.getElementById('amount').addEventListener('input', updateDisplay);
</script>
</body>
</html>
