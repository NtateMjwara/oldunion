<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/InvestmentService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$userId = (int)$_SESSION['user_id'];

/* ── "Already invested" shortcut ── */
$alreadyRedirect = isset($_GET['already']) && !empty($_GET['cid']);
if ($alreadyRedirect) {
    $campaign = InvestmentService::getCampaignForInvestment(trim($_GET['cid']));
    $existing = $campaign
        ? InvestmentService::getUserContribution($userId, (int)$campaign['id'])
        : null;

    if ($existing) {
        // Build a result-like array from the existing contribution
        $result = [
            'contribution_id'   => $existing['id'],
            'contribution_uuid' => $existing['uuid'],
            'reference'         => $existing['payment_reference'] ?? '',
            'amount'            => $existing['amount'],
            'payment_method'    => $existing['payment_method'],
            'campaign_title'    => $campaign['title']       ?? '',
            'company_name'      => $campaign['company_name'] ?? '',
            'campaign_uuid'     => $campaign['uuid']         ?? '',
        ];
    } else {
        redirect('/discover/');
    }
} else {
    // Normal flow — read from session
    $result = $_SESSION['invest_result'] ?? null;
    if (!$result) { redirect('/discover/'); }
    unset($_SESSION['invest_result']);
}

$isWallet  = $result['payment_method'] === 'platform_wallet';
$isEft     = $result['payment_method'] === 'eft';
$isAlready = $alreadyRedirect;

function fmtR($v) { return 'R ' . number_format((float)$v, 2, '.', ' '); }

$pdo  = Database::getInstance();
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me   = $stmt->fetch();
$ini  = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

// EFT banking details (from constants or config)
define('EFT_BANK_NAME',    defined('EFT_BANK')    ? EFT_BANK    : 'FNB');
define('EFT_ACCOUNT_NAME', defined('EFT_ACC_NAME') ? EFT_ACC_NAME : 'Old Union (Pty) Ltd');
define('EFT_ACCOUNT_NO',   defined('EFT_ACC_NO')   ? EFT_ACC_NO   : 'XXXXXXXXXX');
define('EFT_BRANCH_CODE',  defined('EFT_BRANCH')   ? EFT_BRANCH   : '250655');
define('EFT_ACCOUNT_TYPE', 'Cheque / Current');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $isAlready ? 'Your Investment' : 'Investment Confirmed'; ?> | Old Union</title>
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
    .step-pill.done{color:var(--green);}
    .step-sep{color:var(--border);font-size:.7rem;padding:0 .1rem;}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}
    .page{padding-top:var(--header-h);min-height:100vh;padding-bottom:3rem;display:flex;align-items:flex-start;justify-content:center;}
    .page-inner{max-width:600px;width:100%;padding:3rem 1.5rem;}
    /* Success hero */
    .success-hero{text-align:center;margin-bottom:2rem;}
    .success-icon{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;margin:0 auto 1.25rem;}
    .icon-green{background:var(--green-bg);color:var(--green);border:2px solid var(--green-bdr);}
    .icon-amber{background:var(--amber-light);color:#78350f;border:2px solid var(--amber);}
    .icon-blue{background:#eff4ff;color:var(--navy-mid);border:2px solid #c7d9f8;}
    .success-title{font-family:'DM Serif Display',serif;font-size:2rem;color:var(--navy);margin-bottom:.5rem;line-height:1.2;}
    .success-sub{font-size:.95rem;color:var(--text-muted);line-height:1.6;}
    /* Card */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);margin-bottom:1.25rem;overflow:hidden;}
    .card-header{display:flex;align-items:center;gap:.5rem;padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface-2);}
    .card-header-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.4rem;}
    .card-header-title i{color:var(--navy-light);}
    .card-body{padding:1.1rem 1.25rem;}
    /* Detail rows */
    .detail-row{display:flex;align-items:baseline;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--border);gap:.75rem;font-size:.85rem;}
    .detail-row:last-child{border-bottom:none;}
    .detail-lbl{color:var(--text-muted);flex-shrink:0;}
    .detail-val{font-weight:600;color:var(--text);text-align:right;word-break:break-all;}
    .detail-val.big{font-family:'DM Serif Display',serif;font-size:1.25rem;color:var(--navy);}
    .detail-val.ref{font-family:monospace;font-size:.9rem;color:var(--navy-mid);background:#eff4ff;padding:.1rem .4rem;border-radius:4px;}
    /* EFT banking details */
    .eft-bank-table{width:100%;border-collapse:collapse;font-size:.85rem;}
    .eft-bank-table td{padding:.6rem .75rem;border-bottom:1px solid var(--border);}
    .eft-bank-table tr:last-child td{border-bottom:none;}
    .eft-bank-table td:first-child{color:var(--text-muted);width:40%;}
    .eft-bank-table td:last-child{font-weight:600;color:var(--text);}
    .ref-display{background:var(--navy);color:#fff;border-radius:var(--radius-sm);padding:1rem 1.25rem;text-align:center;margin-bottom:.75rem;}
    .ref-label{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.55);margin-bottom:.35rem;}
    .ref-code{font-family:monospace;font-size:1.5rem;font-weight:700;color:var(--amber);letter-spacing:.1em;}
    .ref-hint{font-size:.75rem;color:rgba(255,255,255,.5);margin-top:.25rem;}
    /* Steps */
    .step-list{list-style:none;display:flex;flex-direction:column;gap:.75rem;}
    .step-li{display:flex;align-items:flex-start;gap:.75rem;font-size:.88rem;color:var(--text-muted);}
    .step-num{width:24px;height:24px;border-radius:50%;background:var(--navy);color:#fff;font-size:.72rem;font-weight:700;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    /* Buttons */
    .btn{display:flex;align-items:center;justify-content:center;gap:.5rem;padding:.75rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;text-align:center;}
    .btn-primary{background:var(--navy-mid);color:#fff;}
    .btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
    .btn-outline{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-outline:hover{border-color:#94a3b8;color:var(--text);}
    .btn-row{display:flex;flex-direction:column;gap:.6rem;margin-top:1.5rem;}
    .callout-info{background:#eff4ff;border:1px solid #c7d9f8;border-radius:var(--radius-sm);padding:.85rem 1rem;font-size:.82rem;color:var(--navy-mid);display:flex;gap:.55rem;align-items:flex-start;margin-bottom:1.25rem;}
    .callout-info i{flex-shrink:0;margin-top:.1rem;color:var(--navy-light);}
    @media(max-width:768px){.header-steps{display:none;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>
<header class="top-header">
    <a href="/discover/" class="header-brand">Old <span>U</span>nion</a>
    <div class="header-steps">
        <div class="step-pill done"><i class="fa-solid fa-circle-check"></i> Amount</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill done"><i class="fa-solid fa-circle-check"></i> Confirm</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill done"><i class="fa-solid fa-circle-check"></i> Done</div>
    </div>
    <div class="avatar"><?php echo htmlspecialchars($ini); ?></div>
</header>

<div class="page">
<div class="page-inner">

    <!-- ── Success Hero ── -->
    <div class="success-hero">
        <?php if ($isAlready): ?>
            <div class="success-icon icon-blue"><i class="fa-solid fa-chart-pie"></i></div>
            <h1 class="success-title">You're already invested.</h1>
            <p class="success-sub">Here are the details of your existing investment in <strong><?php echo htmlspecialchars($result['company_name']); ?></strong>.</p>
        <?php elseif ($isWallet): ?>
            <div class="success-icon icon-green"><i class="fa-solid fa-circle-check"></i></div>
            <h1 class="success-title">Investment confirmed!</h1>
            <p class="success-sub">
                Your investment of <strong><?php echo fmtR($result['amount']); ?></strong> in
                <strong><?php echo htmlspecialchars($result['company_name']); ?></strong> has been processed.
                It has been debited from your wallet instantly.
            </p>
        <?php else: ?>
            <div class="success-icon icon-amber"><i class="fa-solid fa-clock"></i></div>
            <h1 class="success-title">Spot reserved.</h1>
            <p class="success-sub">
                Your investment of <strong><?php echo fmtR($result['amount']); ?></strong> in
                <strong><?php echo htmlspecialchars($result['company_name']); ?></strong> is reserved.
                Complete your EFT payment using the details below.
            </p>
        <?php endif; ?>
    </div>

    <!-- ── Investment Details ── -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-receipt"></i> Investment Details</div>
        </div>
        <div class="card-body">
            <div class="detail-row">
                <span class="detail-lbl">Company</span>
                <span class="detail-val"><?php echo htmlspecialchars($result['company_name']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-lbl">Campaign</span>
                <span class="detail-val"><?php echo htmlspecialchars($result['campaign_title']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-lbl">Amount</span>
                <span class="detail-val big"><?php echo fmtR($result['amount']); ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-lbl">Payment Method</span>
                <span class="detail-val"><?php echo $isWallet ? 'Platform Wallet' : 'Bank EFT'; ?></span>
            </div>
            <div class="detail-row">
                <span class="detail-lbl">Status</span>
                <span class="detail-val">
                    <?php if ($isWallet): ?>
                        <span style="color:var(--green);font-weight:700;"><i class="fa-solid fa-circle-check"></i> Paid</span>
                    <?php else: ?>
                        <span style="color:#78350f;font-weight:700;"><i class="fa-solid fa-clock"></i> Awaiting EFT Payment</span>
                    <?php endif; ?>
                </span>
            </div>
            <div class="detail-row">
                <span class="detail-lbl">Reference</span>
                <span class="detail-val ref"><?php echo htmlspecialchars($result['reference']); ?></span>
            </div>
        </div>
    </div>

    <!-- ── EFT Payment Instructions ── -->
    <?php if ($isEft && !$isAlready): ?>
    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-building-columns"></i> EFT Payment Instructions</div>
        </div>
        <div class="card-body">
            <div class="callout-info">
                <i class="fa-solid fa-circle-info"></i>
                <div>Use exactly this reference when making your EFT. Without the correct reference, we cannot match your payment to your investment.</div>
            </div>
            <div class="ref-display">
                <div class="ref-label">Your Unique Payment Reference</div>
                <div class="ref-code"><?php echo htmlspecialchars($result['reference']); ?></div>
                <div class="ref-hint">Copy this exactly — include all characters</div>
            </div>
            <table class="eft-bank-table">
                <tr><td>Bank</td><td><?php echo htmlspecialchars(EFT_BANK_NAME); ?></td></tr>
                <tr><td>Account Name</td><td><?php echo htmlspecialchars(EFT_ACCOUNT_NAME); ?></td></tr>
                <tr><td>Account Number</td><td><?php echo htmlspecialchars(EFT_ACCOUNT_NO); ?></td></tr>
                <tr><td>Branch Code</td><td><?php echo htmlspecialchars(EFT_BRANCH_CODE); ?></td></tr>
                <tr><td>Account Type</td><td><?php echo htmlspecialchars(EFT_ACCOUNT_TYPE); ?></td></tr>
                <tr><td>Amount</td><td><strong><?php echo fmtR($result['amount']); ?></strong></td></tr>
                <tr><td>Reference</td><td><strong style="color:var(--navy-mid);"><?php echo htmlspecialchars($result['reference']); ?></strong></td></tr>
            </table>
        </div>
    </div>

    <!-- What happens next -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-list-check"></i> What Happens Next</div>
        </div>
        <div class="card-body">
            <ul class="step-list">
                <li class="step-li"><div class="step-num">1</div><div>Make your EFT of <?php echo fmtR($result['amount']); ?> using the reference <strong><?php echo htmlspecialchars($result['reference']); ?></strong>.</div></li>
                <li class="step-li"><div class="step-num">2</div><div>Once payment is received and verified by Old Union (1–2 business days), your investment status updates to <strong>Confirmed</strong>.</div></li>
                <li class="step-li"><div class="step-num">3</div><div>If the campaign reaches its minimum raise, funds are disbursed to the company and your agreement becomes active.</div></li>
                <li class="step-li"><div class="step-num">4</div><div>If the minimum is not reached, your full payment is refunded to your wallet or via EFT.</div></li>
            </ul>
        </div>
    </div>
    <?php elseif ($isWallet && !$isAlready): ?>
    <!-- What happens next — wallet -->
    <div class="card">
        <div class="card-header">
            <div class="card-header-title"><i class="fa-solid fa-list-check"></i> What Happens Next</div>
        </div>
        <div class="card-body">
            <ul class="step-list">
                <li class="step-li"><div class="step-num">1</div><div>Your wallet has been debited <?php echo fmtR($result['amount']); ?>. Your investment is now confirmed.</div></li>
                <li class="step-li"><div class="step-num">2</div><div>If the campaign reaches its minimum raise, funds are disbursed to the company and your agreement becomes active.</div></li>
                <li class="step-li"><div class="step-num">3</div><div>The company will post monthly financial updates in your portfolio. Revenue share payments will appear in your wallet.</div></li>
                <li class="step-li"><div class="step-num">4</div><div>If the minimum is not reached, your full payment is refunded to your wallet automatically.</div></li>
            </ul>
        </div>
    </div>
    <?php endif; ?>

    <!-- ── Action buttons ── -->
    <div class="btn-row">
        <a href="/app/discover/" class="btn btn-primary">
            <i class="fa-solid fa-chart-pie"></i> View My Portfolio
        </a>
        <a href="/app/discover/company.php?uuid=<?php echo urlencode($result['campaign_uuid'] ?? ''); ?>"
           class="btn btn-outline">
            <i class="fa-solid fa-building"></i> Back to Company Profile
        </a>
        <a href="/app/discover/" class="btn btn-outline">
            <i class="fa-solid fa-compass"></i> Discover More Businesses
        </a>
    </div>

</div>
</div>
</body>
</html>
