<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InvestmentService.php';

if (!isLoggedIn()) {
    $returnUrl = '/app/invest/start.php?cid=' . urlencode($_GET['cid'] ?? '');
    redirect('/app/auth/login.php?redirect=' . urlencode($returnUrl));
}

$campaignUuid = trim($_GET['cid'] ?? '');
if (empty($campaignUuid)) { redirect('/discover/'); }

$campaign = InvestmentService::getCampaignForInvestment($campaignUuid);
if (!$campaign) { redirect('/discover/'); }

$userId     = (int)$_SESSION['user_id'];
$csrf_token = generateCSRFToken();
$errors     = [];

// Already contributed — send to success/status page
$existing = InvestmentService::getUserContribution($userId, (int)$campaign['id']);
if ($existing) {
    redirect('/app/invest/success.php?already=1&cid=' . urlencode($campaignUuid));
}

/* ── POST: validate amount, store intent ── */
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
                $errors[] = 'Minimum investment is R ' . number_format($min, 2) . '.';
            } elseif ($max !== null && $amount > $max) {
                $errors[] = 'Maximum investment is R ' . number_format($max, 2) . '.';
            } elseif ($campaign['raise_maximum'] !== null) {
                $remaining = (float)$campaign['raise_maximum'] - (float)$campaign['total_raised'];
                if ($amount > $remaining) {
                    $errors[] = 'Only R ' . number_format($remaining, 2) . ' of the hard cap remains.';
                }
            }

            if (empty($errors)) {
                $_SESSION['invest_intent'] = [
                    'campaign_uuid' => $campaign['uuid'],
                    'campaign_id'   => $campaign['id'],
                    'amount'        => $amount,
                    'expires_at'    => time() + 900, // 15 minutes
                ];
                redirect('/app/invest/confirm.php');
            }
        }
    }
}

/* ── View helpers ── */
function fmtR($v) {
    if ($v === null || $v === '') return '—';
    return 'R ' . number_format((float)$v, 2, '.', ' ');
}
function fmtDate($v) {
    return $v ? date('d M Y', strtotime($v)) : '—';
}
function daysLeft($d) {
    return max(0, (int)ceil((strtotime($d) - time()) / 86400));
}

$target = (float)$campaign['raise_target'];
$raised = (float)$campaign['total_raised'];
$pct    = $target > 0 ? min(100, round(($raised / $target) * 100)) : 0;
$days   = daysLeft($campaign['closes_at']);

$typeLabels = [
    'revenue_share'          => ['Revenue Share',    'fa-chart-line',  'ct-rs'],
    'cooperative_membership' => ['Co-op Membership', 'fa-people-roof', 'ct-co'],
];
$ctInfo = $typeLabels[$campaign['campaign_type']] ?? ['Campaign', 'fa-rocket', 'ct-rs'];

$pdo  = Database::getInstance();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me   = $stmt->fetch();
$ini  = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

$min = (float)$campaign['min_contribution'];
$quickAmounts = array_values(array_unique(array_filter(
    [$min, $min * 2, $min * 5, $min * 10],
    fn($v) => !$campaign['max_contribution'] || $v <= (float)$campaign['max_contribution']
)));
sort($quickAmounts);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invest in <?php echo htmlspecialchars($campaign['company_name']); ?> | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--header-h:64px;--transition:.2s cubic-bezier(.4,0,.2,1);}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
    .header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .header-brand span{color:#c8102e;}
    .header-steps{display:flex;align-items:center;gap:.15rem;}
    .step-pill{display:flex;align-items:center;gap:.35rem;padding:.35rem .9rem;font-size:.8rem;font-weight:600;color:var(--text-light);border-radius:99px;white-space:nowrap;}
    .step-pill.active{background:#eff4ff;color:var(--navy-mid);}
    .step-pill.done{color:var(--green);}
    .step-sep{color:var(--border);font-size:.7rem;padding:0 .1rem;}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}
    .page{padding-top:var(--header-h);min-height:100vh;padding-bottom:3rem;}
    .page-inner{max-width:960px;margin:0 auto;padding:2.5rem 1.5rem;display:grid;grid-template-columns:1fr 380px;gap:2rem;align-items:start;}
    /* Summary card */
    .summary-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
    .summary-head{background:var(--navy);padding:1.5rem;display:flex;align-items:flex-start;gap:1rem;}
    .summary-logo{width:52px;height:52px;border-radius:10px;background:var(--surface);overflow:hidden;flex-shrink:0;display:flex;align-items:center;justify-content:center;}
    .summary-logo img{width:100%;height:100%;object-fit:cover;}
    .summary-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.4rem;color:#fff;}
    .summary-company{font-size:.72rem;font-weight:600;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.2rem;}
    .summary-title{font-family:'DM Serif Display',serif;font-size:1.2rem;color:#fff;line-height:1.2;margin-bottom:.35rem;}
    .summary-tagline{font-size:.8rem;color:rgba(255,255,255,.58);}
    .type-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.72rem;font-weight:700;margin-top:.55rem;}
    .ct-rs{background:rgba(26,86,176,.3);color:#93c5fd;}
    .ct-co{background:rgba(11,107,77,.3);color:#6ee7b7;}
    .summary-body{padding:1.25rem;}
    .prog-raised{font-size:1.3rem;font-weight:700;color:var(--navy);line-height:1;}
    .prog-lbl{font-size:.74rem;color:var(--text-light);margin-bottom:.55rem;}
    .prog-outer{height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);margin-bottom:.4rem;}
    .prog-inner{height:100%;background:var(--amber);border-radius:99px;}
    .prog-stats{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-light);}
    .stat-row{display:flex;align-items:center;justify-content:space-between;padding:.5rem 0;border-bottom:1px solid var(--border);font-size:.84rem;}
    .stat-row:last-child{border-bottom:none;}
    .stat-lbl{color:var(--text-muted);}
    .stat-val{font-weight:600;color:var(--text);}
    .terms-preview{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.85rem 1rem;margin-top:.85rem;}
    .tp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);margin-bottom:.45rem;display:flex;align-items:center;gap:.4rem;}
    .tp-label i{color:var(--navy-light);}
    .tp-row{display:flex;justify-content:space-between;font-size:.82rem;padding:.3rem 0;border-bottom:1px solid var(--border);}
    .tp-row:last-child{border-bottom:none;}
    .tp-lbl{color:var(--text-muted);}
    .tp-val{font-weight:600;color:var(--navy-mid);}
    /* Form card */
    .form-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);}
    .form-card-head{padding:1.1rem 1.25rem;border-bottom:1px solid var(--border);}
    .form-card-title{font-size:.88rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.06em;display:flex;align-items:center;gap:.45rem;}
    .form-card-title i{color:var(--navy-light);}
    .form-card-body{padding:1.25rem;}
    .field-label{display:block;font-size:.82rem;font-weight:600;color:var(--text);margin-bottom:.45rem;}
    .amount-wrap{position:relative;margin-bottom:.4rem;}
    .amount-prefix{position:absolute;left:.9rem;top:50%;transform:translateY(-50%);font-size:1.1rem;font-weight:700;color:var(--text-muted);pointer-events:none;}
    .amount-input{width:100%;padding:.85rem 1rem .85rem 2.4rem;border:2px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:1.35rem;font-weight:700;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
    .amount-input:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 4px rgba(26,86,176,.1);}
    .amount-hint{font-size:.75rem;color:var(--text-light);margin-bottom:1rem;display:flex;align-items:center;gap:.3rem;}
    .quick-amounts{display:flex;flex-wrap:wrap;gap:.5rem;margin-bottom:1rem;}
    .quick-btn{padding:.4rem .85rem;border:1.5px solid var(--border);border-radius:99px;background:var(--surface-2);font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:600;color:var(--text-muted);cursor:pointer;transition:all var(--transition);}
    .quick-btn:hover{border-color:var(--navy-light);color:var(--navy-mid);background:#eff4ff;}
    .return-preview{background:#eff4ff;border:1px solid #c7d9f8;border-radius:var(--radius-sm);padding:.85rem 1rem;margin-bottom:1rem;}
    .rp-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy-light);margin-bottom:.35rem;display:flex;align-items:center;gap:.35rem;}
    .rp-text{font-size:.84rem;color:var(--navy-mid);line-height:1.55;}
    .rp-highlight{font-size:1rem;font-weight:700;color:var(--navy);}
    .btn-invest{display:flex;align-items:center;justify-content:center;gap:.5rem;width:100%;padding:.85rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.95rem;font-weight:700;cursor:pointer;background:var(--amber);color:var(--navy);border:none;box-shadow:0 4px 14px rgba(245,158,11,.3);transition:all var(--transition);}
    .btn-invest:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);box-shadow:0 6px 18px rgba(245,158,11,.4);}
    .btn-back{display:inline-flex;align-items:center;gap:.4rem;margin-top:.85rem;font-size:.85rem;color:var(--text-muted);text-decoration:none;transition:color var(--transition);}
    .btn-back:hover{color:var(--navy);}
    .alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    .legal-note{font-size:.73rem;color:var(--text-light);line-height:1.55;margin-top:.85rem;display:flex;align-items:flex-start;gap:.4rem;}
    .legal-note i{flex-shrink:0;margin-top:.1rem;}
    @media(max-width:800px){.page-inner{grid-template-columns:1fr;}.header-steps{display:none;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>
<header class="top-header">
    <a href="/browse/" class="header-brand">Old <span>U</span>nion</a>
    <div class="header-steps">
        <div class="step-pill active"><i class="fa-solid fa-circle-dot"></i> Amount</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Confirm</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Done</div>
    </div>
    <div class="avatar"><?php echo htmlspecialchars($ini); ?></div>
</header>

<div class="page">
<div class="page-inner">

    <!-- Campaign Summary -->
    <div class="summary-card">
        <div class="summary-head">
            <div class="summary-logo">
                <?php if ($campaign['company_logo']): ?>
                    <img src="<?php echo htmlspecialchars($campaign['company_logo']); ?>"
                         alt="<?php echo htmlspecialchars($campaign['company_name']); ?>">
                <?php else: ?>
                    <div class="summary-logo-ph"><?php echo strtoupper(substr($campaign['company_name'], 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="summary-company"><?php echo htmlspecialchars($campaign['company_name']); ?></div>
                <div class="summary-title"><?php echo htmlspecialchars($campaign['title']); ?></div>
                <?php if ($campaign['tagline']): ?>
                    <div class="summary-tagline"><?php echo htmlspecialchars($campaign['tagline']); ?></div>
                <?php endif; ?>
                <div class="type-chip <?php echo $ctInfo[2]; ?>">
                    <i class="fa-solid <?php echo $ctInfo[1]; ?>"></i> <?php echo $ctInfo[0]; ?>
                </div>
            </div>
        </div>
        <div class="summary-body">
            <div class="prog-raised"><?php echo fmtR($raised); ?></div>
            <div class="prog-lbl">raised of <?php echo fmtR($target); ?> target</div>
            <div class="prog-outer"><div class="prog-inner" style="width:<?php echo $pct; ?>%"></div></div>
            <div class="prog-stats">
                <span><?php echo $pct; ?>% funded</span>
                <span><?php echo (int)$campaign['contributor_count']; ?> / <?php echo (int)$campaign['max_contributors']; ?> contributors</span>
            </div>
            <div style="margin-top:1rem;">
                <div class="stat-row"><span class="stat-lbl">Closes</span><span class="stat-val"><?php echo fmtDate($campaign['closes_at']); ?> &nbsp;·&nbsp; <?php echo $days; ?> day<?php echo $days !== 1 ? 's' : ''; ?> left</span></div>
                <div class="stat-row"><span class="stat-lbl">Min. Investment</span><span class="stat-val"><?php echo fmtR($campaign['min_contribution']); ?></span></div>
                <?php if ($campaign['max_contribution']): ?>
                <div class="stat-row"><span class="stat-lbl">Max. Investment</span><span class="stat-val"><?php echo fmtR($campaign['max_contribution']); ?></span></div>
                <?php endif; ?>
                <div class="stat-row">
                    <span class="stat-lbl">Spots Remaining</span>
                    <span class="stat-val"><?php echo max(0, (int)$campaign['max_contributors'] - (int)$campaign['contributor_count']); ?></span>
                </div>
            </div>
            <div class="terms-preview">
                <div class="tp-label"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
                <?php if ($campaign['campaign_type'] === 'revenue_share'): ?>
                    <div class="tp-row"><span class="tp-lbl">Type</span><span class="tp-val">Revenue Share</span></div>
                    <div class="tp-row"><span class="tp-lbl">Monthly Share</span><span class="tp-val"><?php echo htmlspecialchars((string)$campaign['revenue_share_percentage']); ?>% of revenue</span></div>
                    <div class="tp-row"><span class="tp-lbl">Duration</span><span class="tp-val"><?php echo htmlspecialchars((string)$campaign['revenue_share_duration_months']); ?> months</span></div>
                <?php elseif ($campaign['campaign_type'] === 'cooperative_membership'): ?>
                    <div class="tp-row"><span class="tp-lbl">Type</span><span class="tp-val">Co-op Membership</span></div>
                    <div class="tp-row"><span class="tp-lbl">Unit Name</span><span class="tp-val"><?php echo htmlspecialchars($campaign['unit_name'] ?? '—'); ?></span></div>
                    <div class="tp-row"><span class="tp-lbl">Price / Unit</span><span class="tp-val"><?php echo fmtR($campaign['unit_price']); ?></span></div>
                <?php endif; ?>
                <div class="tp-row"><span class="tp-lbl">Governing Law</span><span class="tp-val"><?php echo htmlspecialchars($campaign['governing_law'] ?? 'Republic of South Africa'); ?></span></div>
            </div>
        </div>
    </div>

    <!-- Amount Form -->
    <div>
        <div class="form-card">
            <div class="form-card-head">
                <div class="form-card-title"><i class="fa-solid fa-hand-holding-dollar"></i> How much would you like to invest?</div>
            </div>
            <div class="form-card-body">
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><?php echo htmlspecialchars($errors[0]); ?></div>
                <?php endif; ?>
                <form method="POST" id="investForm">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <label for="amount" class="field-label">Investment Amount</label>
                    <div class="amount-wrap">
                        <span class="amount-prefix">R</span>
                        <input type="number" id="amount" name="amount" class="amount-input"
                               min="<?php echo htmlspecialchars((string)$campaign['min_contribution']); ?>"
                               <?php if ($campaign['max_contribution']): ?>max="<?php echo htmlspecialchars((string)$campaign['max_contribution']); ?>"<?php endif; ?>
                               step="100"
                               value="<?php echo htmlspecialchars($_POST['amount'] ?? number_format($min, 0, '.', '')); ?>"
                               autofocus required>
                    </div>
                    <div class="amount-hint">
                        <i class="fa-solid fa-circle-info"></i>
                        Min: <?php echo fmtR($campaign['min_contribution']); ?>
                        <?php if ($campaign['max_contribution']): ?> &nbsp;·&nbsp; Max: <?php echo fmtR($campaign['max_contribution']); ?><?php endif; ?>
                    </div>

                    <?php if (!empty($quickAmounts)): ?>
                    <div class="quick-amounts">
                        <?php foreach ($quickAmounts as $qa): ?>
                            <button type="button" class="quick-btn" onclick="setAmount(<?php echo (int)$qa; ?>)">
                                R <?php echo number_format($qa, 0, '.', ' '); ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($campaign['campaign_type'] === 'revenue_share'
                              && $campaign['revenue_share_percentage']
                              && $campaign['revenue_share_duration_months']): ?>
                    <div class="return-preview" id="returnPreview">
                        <div class="rp-label"><i class="fa-solid fa-calculator"></i> Illustrative Return</div>
                        <div class="rp-text" id="returnText">Enter an amount to see your illustrative share.</div>
                    </div>
                    <?php endif; ?>

                    <button type="submit" class="btn-invest">
                        Continue to Review <i class="fa-solid fa-arrow-right"></i>
                    </button>
                </form>
                <div class="legal-note">
                    <i class="fa-solid fa-scale-balanced"></i>
                    <span>Max <strong><?php echo (int)$campaign['max_contributors']; ?> contributors</strong> per campaign under SA private placement regulations. Proceed only if you understand the deal terms.</span>
                </div>
            </div>
        </div>
        <a href="/app/discover/company.php?uuid=<?php echo urlencode($campaign['company_uuid']); ?>" class="btn-back">
            <i class="fa-solid fa-arrow-left"></i> Back to company profile
        </a>
    </div>

</div>
</div>

<script>
function setAmount(v) { document.getElementById('amount').value = v; updateReturn(); }
<?php if ($campaign['campaign_type'] === 'revenue_share' && $campaign['revenue_share_percentage'] && $campaign['revenue_share_duration_months']): ?>
const RAISE_TARGET = <?php echo (float)$campaign['raise_target']; ?>;
const RS_PCT       = <?php echo (float)$campaign['revenue_share_percentage']; ?>;
const RS_DUR       = <?php echo (int)$campaign['revenue_share_duration_months']; ?>;
function updateReturn() {
    const amount = parseFloat(document.getElementById('amount').value) || 0;
    const el = document.getElementById('returnText');
    if (!el || !amount || RAISE_TARGET <= 0) return;
    const share = amount / RAISE_TARGET;
    const pct   = (share * RS_PCT).toFixed(4);
    el.innerHTML = 'At <span class="rp-highlight">R ' + amount.toLocaleString('en-ZA') + '</span> invested, you hold ' +
        '<span class="rp-highlight">' + (share * 100).toFixed(2) + '% of this raise</span>, ' +
        'entitling you to <span class="rp-highlight">' + pct + '% of monthly revenue</span> for ' + RS_DUR + ' months.<br>' +
        '<span style="font-size:.76rem;color:var(--text-light)">Actual returns depend on reported monthly revenue.</span>';
}
document.getElementById('amount').addEventListener('input', updateReturn);
updateReturn();
<?php else: ?>
function updateReturn() {}
<?php endif; ?>
</script>
</body>
</html>
