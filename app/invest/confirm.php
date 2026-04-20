<?php
/**
 * /app/invest/confirm.php
 *
 * US-602 — Fleet deal terms summary + waterfall display         Team B
 *
 * Changes from original confirm.php:
 *   - FleetService::calculateDistribution() computes exact share/yield at contribution amount
 *   - FleetService::getDocuments() loads subscription_agreement for pre-signing download
 *   - Deal Terms card: fleet branch shows asset_type, asset_count, term_months,
 *     hurdle_rate, investor_waterfall_pct, management_fee_pct, your_share_pct,
 *     projected_monthly_distrib (US-602 criterion 1)
 *   - Investment Agreement text updated for fleet waterfall language (criterion 2)
 *   - Document Links card: subscription_agreement shown as downloadable PDF (criterion 3)
 *   - Non-fleet revenue_share and co-op branches unchanged (criterion 4)
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InvestmentService.php';
require_once '../includes/WalletService.php';
require_once '../includes/FleetService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/invest/confirm.php'));
}

$intent = $_SESSION['invest_intent'] ?? null;
if (!$intent
    || empty($intent['campaign_uuid'])
    || empty($intent['amount'])
    || empty($intent['expires_at'])
    || $intent['expires_at'] < time()
) {
    unset($_SESSION['invest_intent']);
    redirect('/app/invest/');
}

$campaignUuid = $intent['campaign_uuid'];
$amount       = (float)$intent['amount'];
$userId       = (int)$_SESSION['user_id'];

$campaign = InvestmentService::getCampaignForInvestment($campaignUuid);
if (!$campaign) { unset($_SESSION['invest_intent']); redirect('/app/invest/'); }

if (InvestmentService::getUserContribution($userId, (int)$campaign['id'])) {
    unset($_SESSION['invest_intent']);
    redirect('/app/invest/success.php?already=1&cid=' . urlencode($campaignUuid));
}

$wallet        = WalletService::getByUserId($userId);
$walletBalance = $wallet ? (float)$wallet['balance'] : 0.0;
$canUseWallet  = $wallet && $wallet['status'] === 'active' && $walletBalance >= $amount;
$isFleet       = $campaign['campaign_type'] === 'fleet_asset';

/* ── US-602: Fleet data ─────────────────────────────────────── */
$fleetCalc    = null;
$subAgreement = null;
if ($isFleet) {
    // Exact distribution at this specific contribution amount
    $fleetCalc = FleetService::calculateDistribution($amount, (int)$campaign['id']);
    // Load subscription_agreement (access-filtered — user is accepted invite holder)
    $docs = FleetService::getDocuments((int)$campaign['id'], $userId);
    foreach ($docs as $doc) {
        if (($doc['doc_type'] ?? '') === 'subscription_agreement') {
            $subAgreement = $doc;
            break;
        }
    }
}

$csrf_token = generateCSRFToken();
$errors     = [];

/* POST: process payment */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } elseif (empty($_POST['agree_terms'])) {
        $errors[] = 'You must read and agree to the investment terms to proceed.';
    } else {
        $paymentMethod = trim($_POST['payment_method'] ?? '');
        if (!in_array($paymentMethod, ['platform_wallet', 'eft'], true)) {
            $errors[] = 'Please select a payment method.';
        }
        if (empty($errors)) {
            try {
                if ($paymentMethod === 'platform_wallet') {
                    $result = InvestmentService::createContributionFromWallet($userId, $campaign, $amount);
                } else {
                    $result = InvestmentService::createContributionEFT($userId, $campaign, $amount);
                }
                unset($_SESSION['invest_intent']);
                $_SESSION['invest_result'] = [
                    'contribution_id'   => $result['contribution_id'],
                    'contribution_uuid' => $result['contribution_uuid'],
                    'reference'         => $result['reference'],
                    'amount'            => $result['amount'],
                    'payment_method'    => $result['payment_method'],
                    'campaign_title'    => $result['campaign_title'],
                    'company_name'      => $result['company_name'],
                    'campaign_uuid'     => $campaignUuid,
                ];
                redirect('/app/invest/success.php');
            } catch (RuntimeException $e) {
                $errors[] = $e->getMessage();
            }
        }
    }
}

/* Helpers */
function c602_money(?float $v): string { return ($v===null||$v<=0)?'—':'R '.number_format($v,0,'.',' '); }
function c602_date(?string $v): string { return $v ? date('d M Y', strtotime($v)) : '—'; }

$proRataShare = 0;
if ($campaign['campaign_type'] === 'revenue_share' && (float)$campaign['raise_target'] > 0) {
    $proRataShare = $amount / (float)$campaign['raise_target'];
}

$pdo  = Database::getInstance();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me   = $stmt->fetch();
$ini  = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Confirm Investment | Old Union</title>
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
.step-pill.done{color:var(--green);}.step-pill.active{background:#eff4ff;color:var(--navy-mid);}
.step-sep{color:var(--border);font-size:.7rem;}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}

.page{padding-top:var(--header-h);min-height:100vh;padding-bottom:3rem;}
.page-inner{max-width:780px;margin:0 auto;padding:2.5rem 1.5rem;}
.page-title{font-family:'DM Serif Display',serif;font-size:1.65rem;color:var(--navy);margin-bottom:.3rem;}
.page-sub{font-size:.9rem;color:var(--text-muted);margin-bottom:2rem;}

/* Cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden;}
.card-header{display:flex;align-items:center;gap:.5rem;padding:.9rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface-2);}
.card-header-title{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.4rem;}
.card-header-title i{color:var(--navy-light);}
.card-body{padding:1.1rem 1.25rem;}

/* Summary row */
.summary-row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.summary-logo{width:48px;height:48px;border-radius:10px;overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;background:var(--surface-2);border:1px solid var(--border);}
.summary-logo img{width:100%;height:100%;object-fit:cover;}
.summary-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.2rem;color:#fff;}
.summary-info{flex:1;}
.summary-company{font-size:.75rem;color:var(--text-light);font-weight:500;margin-bottom:.15rem;}
.summary-title{font-size:1rem;font-weight:600;color:var(--navy);}
.amount-display{text-align:right;}
.amount-big{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);line-height:1;}
.amount-label{font-size:.75rem;color:var(--text-light);}

/* Review rows */
.review-row{display:flex;align-items:baseline;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--border);gap:.75rem;font-size:.85rem;}
.review-row:last-child{border-bottom:none;}
.review-lbl{color:var(--text-muted);flex-shrink:0;}
.review-val{font-weight:600;color:var(--text);text-align:right;}
.review-val.hl{color:var(--navy-mid);font-size:.92rem;}
.review-val.pos{color:var(--green);}

/* US-602: Fleet projected distribution summary */
.fleet-distrib-summary{background:linear-gradient(135deg,#eff4ff 0%,#f0fdf4 100%);border:1.5px solid #c7d9f8;border-radius:var(--radius-sm);padding:.9rem 1.1rem;margin:1rem 0 .5rem;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;}
.fds-item{display:flex;flex-direction:column;gap:.1rem;}
.fds-val{font-family:'DM Serif Display',serif;font-size:1.15rem;color:var(--navy);}
.fds-lbl{font-size:.71rem;color:var(--text-muted);}
.fds-divider{width:1px;background:#c7d9f8;align-self:stretch;flex-shrink:0;}
.hurdle-badge{display:inline-flex;align-items:center;gap:.3rem;font-size:.76rem;font-weight:600;padding:.25rem .7rem;border-radius:99px;border:1px solid transparent;}
.hb-above{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.hb-below{background:var(--amber-light);color:#78350f;border-color:var(--amber);}

/* US-602: Subscription agreement document card */
.doc-item{display:flex;align-items:center;gap:1rem;padding:.85rem 1rem;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);margin-bottom:.6rem;}
.doc-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;background:#fef2f2;color:#dc2626;}
.doc-info{flex:1;}
.doc-label{font-size:.87rem;font-weight:600;color:var(--text);margin-bottom:.15rem;}
.doc-meta{font-size:.74rem;color:var(--text-light);display:flex;align-items:center;gap:.5rem;}
.doc-ver{background:var(--surface);border:1px solid var(--border);border-radius:4px;padding:.05rem .35rem;font-size:.68rem;font-weight:600;color:var(--text-muted);}
.btn-doc-dl{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .9rem;border-radius:99px;font-size:.8rem;font-weight:600;background:var(--navy-mid);color:#fff;text-decoration:none;border:none;cursor:pointer;transition:background var(--transition);flex-shrink:0;}
.btn-doc-dl:hover{background:var(--navy);}
.doc-notice{padding:.65rem .9rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);font-size:.8rem;color:var(--text-light);text-align:center;}

/* Agreement */
.agreement-box{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;max-height:180px;overflow-y:auto;font-size:.82rem;color:var(--text-muted);line-height:1.65;margin-bottom:1rem;}
.agreement-box strong{color:var(--text);}
.agree-label{display:flex;align-items:flex-start;gap:.75rem;cursor:pointer;font-size:.86rem;font-weight:500;color:var(--text);}
.agree-label input[type="checkbox"]{width:18px;height:18px;accent-color:var(--navy-mid);cursor:pointer;flex-shrink:0;margin-top:.1rem;}

/* Payment methods */
.payment-methods{display:flex;flex-direction:column;gap:.75rem;}
.payment-option{position:relative;border:2px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;cursor:pointer;transition:all var(--transition);display:flex;align-items:flex-start;gap:.85rem;}
.payment-option:hover{border-color:var(--navy-light);}
.payment-option.selected{border-color:var(--navy-mid);background:#eff4ff;}
.payment-option input[type="radio"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;margin:0;}
.payment-icon{width:40px;height:40px;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.pi-wallet{background:var(--green-bg);color:var(--green);}
.pi-eft{background:#eff4ff;color:var(--navy-light);}
.pi-disabled{background:#f1f5f9;color:#94a3b8;}
.payment-title{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.15rem;}
.payment-desc{font-size:.78rem;color:var(--text-muted);line-height:1.45;}
.payment-badge{display:inline-flex;align-items:center;gap:.25rem;font-size:.7rem;font-weight:600;padding:.15rem .5rem;border-radius:99px;margin-top:.3rem;}
.badge-instant{background:var(--green-bg);color:var(--green);border:1px solid var(--green-bdr);}
.badge-manual{background:var(--amber-light);color:#78350f;border:1px solid var(--amber);}
.badge-disabled{background:#f1f5f9;color:#94a3b8;border:1px solid #e2e8f0;}
.payment-balance{font-size:.82rem;font-weight:700;color:var(--green);margin-top:.25rem;}
.payment-balance.insufficient{color:var(--error);}

/* Submit */
.btn-submit{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.9rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:1rem;font-weight:700;cursor:pointer;background:var(--navy-mid);color:#fff;border:none;box-shadow:0 4px 14px rgba(15,59,122,.25);transition:all var(--transition);}
.btn-submit:hover{background:var(--navy);transform:translateY(-1px);}
.btn-back{display:inline-flex;align-items:center;gap:.4rem;margin-top:.85rem;font-size:.85rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
.btn-back:hover{color:var(--navy);}
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.callout{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.85rem 1rem;font-size:.8rem;color:#78350f;display:flex;gap:.55rem;align-items:flex-start;margin-bottom:1.25rem;}
.callout i{flex-shrink:0;margin-top:.1rem;color:var(--amber-dark);}
@media(max-width:600px){.summary-row{flex-direction:column;}.amount-display{text-align:left;}.fleet-distrib-summary{flex-direction:column;gap:.5rem;}.header-steps{display:none;}}
</style>
</head>
<body>
<header class="top-header">
    <a href="/app/invest/" class="header-brand">Old <span>U</span>nion</a>
    <div class="header-steps">
        <div class="step-pill done"><i class="fa-solid fa-circle-check"></i> Amount</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill active"><i class="fa-solid fa-circle-dot"></i> Confirm</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Done</div>
    </div>
    <div class="avatar"><?php echo htmlspecialchars($ini); ?></div>
</header>

<div class="page"><div class="page-inner">

<h1 class="page-title">Review Your Investment</h1>
<p class="page-sub">Read the terms carefully and confirm your payment method before proceeding.</p>

<?php if(!empty($errors)):?>
<div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($errors[0]);?></div>
<?php endif;?>

<form method="POST" id="confirmForm">
<input type="hidden" name="csrf_token" value="<?php echo $csrf_token;?>">

<!-- ── Investment Summary ─────────────────────────────────── -->
<div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fa-solid fa-receipt"></i> Investment Summary</div></div>
    <div class="card-body">
        <div class="summary-row">
            <div class="summary-logo">
                <?php if($campaign['company_logo']):?><img src="<?php echo htmlspecialchars($campaign['company_logo']);?>" alt="">
                <?php else:?><div class="summary-logo-ph"><?php echo strtoupper(substr($campaign['company_name'],0,1));?></div><?php endif;?>
            </div>
            <div class="summary-info">
                <div class="summary-company"><?php echo htmlspecialchars($campaign['company_name']);?></div>
                <div class="summary-title"><?php echo htmlspecialchars($campaign['title']);?></div>
            </div>
            <div class="amount-display">
                <div class="amount-big"><?php echo c602_money($amount);?></div>
                <div class="amount-label">your investment</div>
            </div>
        </div>
    </div>
</div>

<!-- ── US-602: Deal Terms card ────────────────────────────── -->
<div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fa-solid fa-file-contract"></i> Deal Terms</div></div>
    <div class="card-body">
        <?php if($isFleet):?>
            <!-- Fleet branch (US-602 criterion 1) -->
            <div class="review-row"><span class="review-lbl">Instrument</span><span class="review-val">Fleet Asset SPV — Investor Distribution Agreement</span></div>
            <?php if(!empty($campaign['asset_type'])&&!empty($campaign['asset_count'])):?>
            <div class="review-row"><span class="review-lbl">Underlying Assets</span><span class="review-val hl"><?php echo (int)$campaign['asset_count'];?> × <?php echo htmlspecialchars($campaign['asset_type']);?></span></div>
            <?php endif;?>
            <?php if(!empty($campaign['term_months'])):?>
            <div class="review-row"><span class="review-lbl">Investment Term</span><span class="review-val hl"><?php echo (int)$campaign['term_months'];?> months</span></div>
            <?php endif;?>
            <?php if(!empty($campaign['hurdle_rate'])):?>
            <div class="review-row"><span class="review-lbl">Hurdle Rate</span><span class="review-val"><?php echo number_format((float)$campaign['hurdle_rate'],1);?>% p.a. (investors share above this)</span></div>
            <?php endif;?>
            <?php if(!empty($campaign['investor_waterfall_pct'])):?>
            <div class="review-row"><span class="review-lbl">Investor Waterfall</span><span class="review-val hl"><?php echo number_format((float)$campaign['investor_waterfall_pct'],0);?>% of net income above hurdle</span></div>
            <?php endif;?>
            <?php if(!empty($campaign['management_fee_pct'])):?>
            <div class="review-row"><span class="review-lbl">Management Fee</span><span class="review-val"><?php echo number_format((float)$campaign['management_fee_pct'],0);?>% of <?php echo htmlspecialchars($campaign['management_fee_basis']??'gross');?></span></div>
            <?php endif;?>
            <?php if(!empty($campaign['distribution_frequency'])):?>
            <div class="review-row"><span class="review-lbl">Distribution Schedule</span><span class="review-val"><?php echo ucfirst($campaign['distribution_frequency']);?></span></div>
            <?php endif;?>

            <?php if($fleetCalc):?>
            <!-- Your specific numbers at this contribution amount -->
            <div class="review-row"><span class="review-lbl">Your SPV Share</span><span class="review-val hl"><?php echo number_format($fleetCalc['share_pct'],4);?>%</span></div>
            <!-- Fleet projected distribution summary strip -->
            <div class="fleet-distrib-summary">
                <div class="fds-item">
                    <div class="fds-val"><?php echo c602_money($fleetCalc['monthly_distribution']);?></div>
                    <div class="fds-lbl">Projected monthly distribution</div>
                </div>
                <div class="fds-divider"></div>
                <div class="fds-item">
                    <div class="fds-val"><?php echo number_format($fleetCalc['annual_yield_pct'],1);?>%</div>
                    <div class="fds-lbl">Indicative annual yield</div>
                </div>
                <div class="fds-divider"></div>
                <div class="fds-item">
                    <span class="hurdle-badge <?php echo $fleetCalc['above_hurdle']?'hb-above':'hb-below';?>">
                        <i class="fa-solid <?php echo $fleetCalc['above_hurdle']?'fa-circle-check':'fa-triangle-exclamation';?>" style="font-size:.72rem;"></i>
                        <?php echo $fleetCalc['above_hurdle']?'Above hurdle':'Below hurdle';?>
                    </span>
                    <div class="fds-lbl" style="margin-top:.25rem;">At stabilised period (<?php echo htmlspecialchars($fleetCalc['stabilised_period_label']??'—');?>)</div>
                </div>
            </div>
            <?php endif;?>

            <div class="review-row"><span class="review-lbl">Min. Raise</span><span class="review-val">If <?php echo c602_money($campaign['raise_minimum']);?> is not reached, your investment is refunded in full.</span></div>
            <div class="review-row"><span class="review-lbl">Governing Law</span><span class="review-val"><?php echo htmlspecialchars($campaign['governing_law']??'Republic of South Africa');?></span></div>

        <?php elseif($campaign['campaign_type']==='revenue_share'):?>
            <!-- Revenue share branch (unchanged — no regression) -->
            <div class="review-row"><span class="review-lbl">Instrument</span><span class="review-val">Revenue Share Agreement</span></div>
            <div class="review-row"><span class="review-lbl">Monthly Revenue Share</span><span class="review-val hl"><?php echo htmlspecialchars((string)$campaign['revenue_share_percentage']);?>% of reported monthly revenue</span></div>
            <div class="review-row"><span class="review-lbl">Duration</span><span class="review-val hl"><?php echo htmlspecialchars((string)$campaign['revenue_share_duration_months']);?> months</span></div>
            <div class="review-row"><span class="review-lbl">Your Pro-Rata Share</span><span class="review-val"><?php echo number_format($proRataShare*100,4);?>% (based on <?php echo c602_money($amount);?> of <?php echo c602_money($campaign['raise_target']);?> target)</span></div>
            <div class="review-row"><span class="review-lbl">Your Monthly Entitlement</span><span class="review-val hl"><?php echo number_format($proRataShare*(float)$campaign['revenue_share_percentage'],5);?>% of reported revenue</span></div>
            <div class="review-row"><span class="review-lbl">Campaign Closes</span><span class="review-val"><?php echo c602_date($campaign['closes_at']);?></span></div>
            <div class="review-row"><span class="review-lbl">Min. Raise</span><span class="review-val">If <?php echo c602_money($campaign['raise_minimum']);?> is not reached, your investment is refunded in full.</span></div>
            <div class="review-row"><span class="review-lbl">Governing Law</span><span class="review-val"><?php echo htmlspecialchars($campaign['governing_law']??'Republic of South Africa');?></span></div>

        <?php elseif($campaign['campaign_type']==='cooperative_membership'):?>
            <!-- Co-op branch (unchanged — no regression) -->
            <div class="review-row"><span class="review-lbl">Instrument</span><span class="review-val">Cooperative Membership</span></div>
            <div class="review-row"><span class="review-lbl">Unit Name</span><span class="review-val hl"><?php echo htmlspecialchars($campaign['unit_name']??'—');?></span></div>
            <div class="review-row"><span class="review-lbl">Price Per Unit</span><span class="review-val"><?php echo c602_money($campaign['unit_price']);?></span></div>
            <?php if($campaign['unit_price']&&(float)$campaign['unit_price']>0):?>
            <div class="review-row"><span class="review-lbl">Units You Will Receive</span><span class="review-val hl"><?php echo number_format($amount/(float)$campaign['unit_price'],2);?> units</span></div>
            <?php endif;?>
            <div class="review-row"><span class="review-lbl">Campaign Closes</span><span class="review-val"><?php echo c602_date($campaign['closes_at']);?></span></div>
            <div class="review-row"><span class="review-lbl">Min. Raise</span><span class="review-val">If <?php echo c602_money($campaign['raise_minimum']);?> is not reached, your investment is refunded in full.</span></div>
            <div class="review-row"><span class="review-lbl">Governing Law</span><span class="review-val"><?php echo htmlspecialchars($campaign['governing_law']??'Republic of South Africa');?></span></div>
        <?php endif;?>
    </div>
</div>

<!-- ── US-602: Subscription Agreement document (criterion 3) ── -->
<?php if($isFleet):?>
<div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fa-solid fa-file-pdf"></i> Subscription Agreement</div></div>
    <div class="card-body">
        <p style="font-size:.84rem;color:var(--text-muted);line-height:1.6;margin-bottom:.85rem;">
            Review the Subscription Agreement before confirming your investment. Your tick of the acknowledgement box below constitutes digital acceptance of these terms.
        </p>
        <?php if($subAgreement&&!empty($subAgreement['file_url'])):?>
        <div class="doc-item">
            <div class="doc-icon"><i class="fa-solid fa-file-contract"></i></div>
            <div class="doc-info">
                <div class="doc-label"><?php echo htmlspecialchars($subAgreement['label']??'Subscription Agreement');?></div>
                <div class="doc-meta">
                    <?php if(!empty($subAgreement['version'])):?><span class="doc-ver"><?php echo htmlspecialchars($subAgreement['version']);?></span><?php endif;?>
                    <?php if(!empty($subAgreement['file_size_kb'])):?><span><?php echo number_format((int)$subAgreement['file_size_kb']);?> KB · PDF</span><?php endif;?>
                </div>
            </div>
            <a href="<?php echo htmlspecialchars($subAgreement['file_url']);?>" target="_blank" rel="noopener" class="btn-doc-dl">
                <i class="fa-solid fa-download"></i> Review PDF
            </a>
        </div>
        <?php else:?>
        <div class="doc-notice">
            <i class="fa-solid fa-clock" style="margin-right:.4rem;color:var(--text-light);"></i>
            The subscription agreement for this campaign has not been uploaded yet. The SPV operator is responsible for providing this document. By proceeding, you acknowledge the investment terms stated above.
        </div>
        <?php endif;?>
    </div>
</div>
<?php endif;?>

<!-- ── Investment Agreement ───────────────────────────────── -->
<div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fa-solid fa-pen-to-square"></i> Investment Agreement</div></div>
    <div class="card-body">
        <div class="agreement-box">
            <strong>INVESTMENT AGREEMENT — SUMMARY</strong><br><br>
            This document records your intent to invest <strong><?php echo c602_money($amount);?></strong> in
            <strong><?php echo htmlspecialchars($campaign['company_name']);?></strong> via the
            <strong><?php echo htmlspecialchars($campaign['title']);?></strong> campaign on the Old Union platform.<br><br>

            <?php if($isFleet):?>
            <!-- US-602 criterion 2: Fleet-specific agreement language -->
            This campaign is structured as a <strong>Fleet Asset SPV</strong>. In exchange for your investment,
            you will receive a pro-rata share of the SPV's net distributable income, calculated as follows:<br><br>
            <strong>Distribution Waterfall:</strong> After operating expenses and a management fee of
            <strong><?php echo number_format((float)($campaign['management_fee_pct']??5),0);?>%
            of <?php echo htmlspecialchars($campaign['management_fee_basis']??'gross');?> income</strong>,
            <?php echo number_format((float)($campaign['investor_waterfall_pct']??85),0);?>% of net income
            above the hurdle rate of <strong><?php echo number_format((float)($campaign['hurdle_rate']??8),1);?>% p.a.</strong>
            is distributed to investors on a <strong><?php echo htmlspecialchars($campaign['distribution_frequency']??'monthly');?></strong>
            basis. Your proportional share is based on your contribution of <strong><?php echo c602_money($amount);?></strong>
            relative to the total raised.<br><br>
            <?php if($campaign['asset_type']&&$campaign['asset_count']):?>
            <strong>Underlying Assets:</strong> <?php echo (int)$campaign['asset_count'];?> ×
            <?php echo htmlspecialchars($campaign['asset_type']);?> vehicles/assets held by the SPV for a term of
            <?php echo (int)($campaign['term_months']??36);?> months.
            The SPV retains ownership of the assets; investors receive distributions from net operating income only.<br><br>
            <?php endif;?>
            <?php else:?>
            <!-- Non-fleet agreement language (no regression) -->
            <?php if($campaign['campaign_type']==='revenue_share'):?>
            In exchange for your investment, you will receive a pro-rata share of
            <strong><?php echo htmlspecialchars((string)$campaign['revenue_share_percentage']);?>% of the company's reported monthly revenue</strong>
            for <strong><?php echo htmlspecialchars((string)$campaign['revenue_share_duration_months']);?> months</strong>,
            beginning after the campaign closes successfully and funds are disbursed.
            Your share is proportional to your contribution relative to the total amount raised.<br><br>
            <?php elseif($campaign['campaign_type']==='cooperative_membership'):?>
            In exchange for your investment, you will receive
            <strong><?php echo htmlspecialchars($campaign['unit_name']??'membership units');?></strong>
            in the cooperative at the agreed unit price. Membership rights and obligations are governed by the cooperative's constitution.<br><br>
            <?php endif;?>
            <?php endif;?>

            <strong>Risk Disclosure:</strong> Investing in early-stage and community businesses carries significant risk.
            You may lose part or all of your investment. Returns are not guaranteed and depend on the
            <?php echo $isFleet ? "SPV's fleet performance and utilisation rates" : "company's financial performance"; ?>.
            This is not a deposit or a product regulated by the FSCA.
            This platform operates under a private placement exemption (max <?php echo (int)$campaign['max_contributors'];?> investors per campaign).<br><br>
            <strong>Governing Law:</strong> <?php echo htmlspecialchars($campaign['governing_law']??'Republic of South Africa');?>.<br><br>
            By ticking the box below, you confirm that you have read, understood, and agree to these terms.
        </div>
        <label class="agree-label">
            <input type="checkbox" name="agree_terms" id="agreeTerms" value="1">
            <span>I have read and understood the investment terms and risk disclosure above, and I agree to proceed.</span>
        </label>
    </div>
</div>

<!-- ── Payment Method ─────────────────────────────────────── -->
<div class="card">
    <div class="card-header"><div class="card-header-title"><i class="fa-solid fa-credit-card"></i> Payment Method</div></div>
    <div class="card-body">
        <div class="payment-methods">
            <!-- Platform Wallet -->
            <label class="payment-option <?php echo $canUseWallet?'selected':'';?>" id="optWallet">
                <input type="radio" name="payment_method" value="platform_wallet" id="radioWallet"
                       <?php echo $canUseWallet?'checked':'disabled';?>
                       onchange="selectOption('optWallet','optEft')">
                <div class="payment-icon <?php echo $canUseWallet?'pi-wallet':'pi-disabled';?>"><i class="fa-solid fa-wallet"></i></div>
                <div class="payment-info">
                    <div class="payment-title">Platform Wallet</div>
                    <div class="payment-desc">Invest instantly using your Old Union wallet balance. No bank transfer needed.</div>
                    <?php if($canUseWallet):?>
                        <div class="payment-balance">Balance: <?php echo c602_money($walletBalance);?> &nbsp;→&nbsp; After: <?php echo c602_money($walletBalance-$amount);?></div>
                        <span class="payment-badge badge-instant"><i class="fa-solid fa-bolt"></i> Instant</span>
                    <?php else:?>
                        <div class="payment-balance insufficient">
                            <?php if(!$wallet||$wallet['status']!=='active'):?>No active wallet found. <a href="/app/wallet/" style="color:inherit;font-weight:700;">Top up your wallet</a> first.
                            <?php else:?>Insufficient balance. You need <?php echo c602_money($amount);?> but have <?php echo c602_money($walletBalance);?>. <a href="/app/wallet/" style="color:inherit;font-weight:700;">Top up →</a><?php endif;?>
                        </div>
                        <span class="payment-badge badge-disabled">Unavailable</span>
                    <?php endif;?>
                </div>
            </label>
            <!-- EFT -->
            <label class="payment-option <?php echo !$canUseWallet?'selected':'';?>" id="optEft">
                <input type="radio" name="payment_method" value="eft" id="radioEft"
                       <?php echo !$canUseWallet?'checked':'';?>
                       onchange="selectOption('optEft','optWallet')">
                <div class="payment-icon pi-eft"><i class="fa-solid fa-building-columns"></i></div>
                <div class="payment-info">
                    <div class="payment-title">Bank EFT</div>
                    <div class="payment-desc">Reserve your spot now. You will receive banking details and a unique reference to complete your EFT. Your investment is confirmed once payment clears (1–2 business days).</div>
                    <span class="payment-badge badge-manual"><i class="fa-solid fa-clock"></i> 1–2 business days</span>
                </div>
            </label>
        </div>
    </div>
</div>

<div class="callout">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>
        <strong>This action cannot be undone after submission.</strong>
        Wallet payments are debited immediately. EFT reservations hold your spot until the campaign closes.
        If the campaign does not reach its minimum raise of <?php echo c602_money($campaign['raise_minimum']);?>, your investment will be refunded in full.
    </div>
</div>

<button type="submit" class="btn-submit" id="submitBtn">
    <i class="fa-solid fa-lock"></i> Confirm Investment of <?php echo c602_money($amount);?>
</button>
</form>

<a href="/app/invest/start.php?cid=<?php echo urlencode($campaignUuid);?>" class="btn-back">
    <i class="fa-solid fa-arrow-left"></i> Back — change amount
</a>

</div></div><!-- /.page-inner/.page -->

<script>
function selectOption(sel, other) {
    document.getElementById(sel).classList.add('selected');
    document.getElementById(other).classList.remove('selected');
}
document.getElementById('confirmForm').addEventListener('submit', function(e) {
    if (!document.getElementById('agreeTerms').checked) {
        e.preventDefault();
        alert('Please read and agree to the investment terms before proceeding.');
        return;
    }
    const btn = document.getElementById('submitBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';
});
</script>
</body>
</html>
