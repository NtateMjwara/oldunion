<?php
/**
 * /app/invest/accept_invite.php
 *
 * US-102 (stub) / US-104 (full implementation)
 *
 * Landing page reached via the unique invite link sent by a founder.
 * Serves as the entry point for:
 *   1. Showing the investor which company / campaign invited them
 *   2. Presenting the risk disclosure acknowledgement (US-104 criterion 3)
 *   3. Recording Accept / Decline in campaign_invites
 *   4. Writing a compliance_events audit row
 *
 * If the investor is not logged in, they are redirected to login/register
 * with the token preserved in the session so it can be consumed on return.
 *
 * Token lookup: campaign_invites.uuid (the invite row's UUID, not the
 * campaign UUID) — passed as ?token=<invite_uuid> in the link.
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';

$token = trim($_GET['token'] ?? '');

/* ── Token required ─────────────────────────────────────── */
if (empty($token)) {
    redirect('/app/discover/');
}

/* ── If not logged in, store token and redirect to login ── */
if (!isLoggedIn()) {
    $_SESSION['pending_invite_token'] = $token;
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/invest/accept_invite.php?token=' . urlencode($token)));
}

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance();

/* ── Load invite by UUID (token) ────────────────────────── */
$stmt = $pdo->prepare("
    SELECT
        ci.id          AS invite_id,
        ci.uuid        AS invite_uuid,
        ci.campaign_id,
        ci.user_id     AS invited_user_id,
        ci.invited_by,
        ci.status,
        ci.expires_at,
        ci.accepted_at,
        ci.declined_at,
        fc.uuid        AS campaign_uuid,
        fc.title       AS campaign_title,
        fc.campaign_type,
        fc.status      AS campaign_status,
        fc.opens_at,
        fc.closes_at,
        fc.raise_target,
        fc.raise_minimum,
        fc.min_contribution,
        fc.max_contributors,
        c.name         AS company_name,
        c.logo         AS company_logo,
        c.uuid         AS company_uuid
    FROM campaign_invites ci
    JOIN funding_campaigns fc ON fc.id = ci.campaign_id
    JOIN companies c          ON c.id  = fc.company_id
    WHERE ci.uuid = :token
    LIMIT 1
");
$stmt->execute(['token' => $token]);
$invite = $stmt->fetch();

/* ── Guard: invite not found ─────────────────────────────── */
if (!$invite) {
    $errorMsg = 'This invitation link is invalid or has already been used.';
    goto renderError;
}

/* ── Guard: wrong user ───────────────────────────────────── */
if ((int)$invite['invited_user_id'] !== $userId) {
    $errorMsg = 'This invitation was sent to a different account. Please log in with the correct account.';
    goto renderError;
}

/* ── Guard: already actioned ─────────────────────────────── */
if (in_array($invite['status'], ['accepted', 'declined', 'revoked'], true)) {
    // Show a status page rather than an error
    goto renderStatus;
}

/* ── Guard: expired ─────────────────────────────────────── */
if (strtotime($invite['expires_at']) < time()) {
    $errorMsg = 'This invitation has expired. Please contact the company to request a new one.';
    goto renderError;
}

/* ── POST: accept or decline ─────────────────────────────── */
$csrf_token = generateCSRFToken();
$postError  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $postError = 'Invalid security token. Please refresh and try again.';
    } elseif (empty($_POST['acknowledge_risk'])) {
        $postError = 'You must acknowledge the risk disclosure before proceeding.';
    } else {

        $action = trim($_POST['action'] ?? '');

        if (!in_array($action, ['accept', 'decline'], true)) {
            $postError = 'Invalid action.';
        } else {
            $pdo->beginTransaction();
            try {
                $now = date('Y-m-d H:i:s');

                if ($action === 'accept') {
                    $pdo->prepare("
                        UPDATE campaign_invites SET
                            status      = 'accepted',
                            accepted_at = :now,
                            updated_at  = :now2
                        WHERE id = :id
                    ")->execute(['now' => $now, 'now2' => $now, 'id' => $invite['invite_id']]);
                } else {
                    $pdo->prepare("
                        UPDATE campaign_invites SET
                            status      = 'declined',
                            declined_at = :now,
                            updated_at  = :now2
                        WHERE id = :id
                    ")->execute(['now' => $now, 'now2' => $now, 'id' => $invite['invite_id']]);
                }

                // Compliance audit log (US-105)
                try {
                    $eventType = $action === 'accept' ? 'invite_accepted' : 'invite_declined';
                    $pdo->prepare("
                        INSERT INTO compliance_events
                            (event_type, actor_id, target_id, campaign_id, ip_address, created_at)
                        VALUES
                            (:type, :actor, :target, :cid, :ip, NOW())
                    ")->execute([
                        'type'   => $eventType,
                        'actor'  => $userId,
                        'target' => $userId,
                        'cid'    => $invite['campaign_id'],
                        'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                    ]);
                } catch (PDOException $e) {
                    error_log('[accept_invite] compliance_events write skipped: ' . $e->getMessage());
                }

                $pdo->commit();

                // Reload invite status
                $stmt = $pdo->prepare("SELECT status, accepted_at, declined_at FROM campaign_invites WHERE id = ?");
                $stmt->execute([$invite['invite_id']]);
                $updated = $stmt->fetch();
                $invite['status']      = $updated['status'];
                $invite['accepted_at'] = $updated['accepted_at'];
                $invite['declined_at'] = $updated['declined_at'];

                goto renderStatus;

            } catch (Throwable $e) {
                $pdo->rollBack();
                error_log('[accept_invite] failed: ' . $e->getMessage());
                $postError = 'Something went wrong. Please try again.';
            }
        }
    }
}

/* ── Get user email for avatar ───────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me          = $stmt->fetch();
$userInitial = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

$typeLabels = [
    'revenue_share'          => ['Revenue Share',    'fa-chart-line'],
    'cooperative_membership' => ['Co-op Membership', 'fa-people-roof'],
];
$ctInfo = $typeLabels[$invite['campaign_type']] ?? ['Campaign', 'fa-rocket'];

function fmtR($v) { return 'R ' . number_format((float)$v, 0, '.', ' '); }
function fmtD($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RENDER — Accept/Decline form (normal flow)
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Invitation | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow-card:0 8px 28px rgba(11,37,69,.09),0 1px 4px rgba(11,37,69,.06);--header-h:64px;--transition:.2s cubic-bezier(.4,0,.2,1);}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}

    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
    .logo{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .logo span{color:#c8102e;}
    .header-steps{display:flex;align-items:center;gap:.15rem;}
    .step-pill{display:flex;align-items:center;gap:.35rem;padding:.35rem .9rem;font-size:.8rem;font-weight:600;color:var(--text-light);border-radius:99px;white-space:nowrap;}
    .step-pill.active{background:#eff4ff;color:var(--navy-mid);}
    .step-pill.done{color:var(--green);}
    .step-sep{color:var(--border);font-size:.7rem;}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}

    .page{padding-top:var(--header-h);min-height:100vh;display:flex;align-items:flex-start;justify-content:center;padding-bottom:3rem;}
    .page-inner{max-width:600px;width:100%;padding:2.5rem 1.5rem;}

    /* Invite hero */
    .invite-hero{background:var(--navy);border-radius:var(--radius);overflow:hidden;margin-bottom:1.25rem;box-shadow:var(--shadow-card);}
    .invite-hero-inner{padding:1.75rem 2rem;display:flex;align-items:flex-start;gap:1.1rem;}
    .invite-logo{width:56px;height:56px;border-radius:10px;background:var(--surface);overflow:hidden;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
    .invite-logo img{width:100%;height:100%;object-fit:cover;}
    .invite-logo-ph{width:100%;height:100%;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.4rem;color:#fff;}
    .invite-company{font-size:.75rem;font-weight:600;color:rgba(255,255,255,.5);text-transform:uppercase;letter-spacing:.08em;margin-bottom:.25rem;}
    .invite-campaign{font-family:'DM Serif Display',serif;font-size:1.3rem;color:#fff;line-height:1.25;margin-bottom:.45rem;}
    .invite-type-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.73rem;font-weight:600;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.15);color:rgba(255,255,255,.8);}

    /* Cards */
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 2px 8px rgba(11,37,69,.06);margin-bottom:1.1rem;overflow:hidden;}
    .card-header{display:flex;align-items:center;gap:.5rem;padding:.85rem 1.25rem;border-bottom:1px solid var(--border);background:var(--surface-2);}
    .card-title{font-size:.8rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.4rem;}
    .card-title i{color:var(--navy-light);}
    .card-body{padding:1.1rem 1.25rem;}

    /* Terms rows */
    .term-row{display:flex;align-items:baseline;justify-content:space-between;padding:.55rem 0;border-bottom:1px solid var(--border);gap:.75rem;font-size:.85rem;}
    .term-row:last-child{border-bottom:none;}
    .term-lbl{color:var(--text-muted);flex-shrink:0;}
    .term-val{font-weight:600;color:var(--text);text-align:right;}
    .term-val.highlight{color:var(--navy-mid);}

    /* Risk disclosure */
    .risk-box{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.1rem;max-height:160px;overflow-y:auto;font-size:.82rem;color:var(--text-muted);line-height:1.65;margin-bottom:1rem;}
    .risk-box strong{color:var(--text);}
    .ack-label{display:flex;align-items:flex-start;gap:.75rem;cursor:pointer;font-size:.87rem;font-weight:500;color:var(--text);}
    .ack-label input[type="checkbox"]{width:18px;height:18px;accent-color:var(--navy-mid);cursor:pointer;flex-shrink:0;margin-top:.1rem;}

    /* Action buttons */
    .action-row{display:flex;gap:.75rem;margin-top:1.25rem;flex-wrap:wrap;}
    .btn{display:inline-flex;align-items:center;justify-content:center;gap:.5rem;padding:.8rem 1.5rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;flex:1;}
    .btn-accept{background:var(--navy-mid);color:#fff;box-shadow:0 4px 12px rgba(15,59,122,.25);}
    .btn-accept:hover{background:var(--navy);transform:translateY(-1px);}
    .btn-decline{background:var(--error-bg);color:var(--error);border:1.5px solid var(--error-bdr);}
    .btn-decline:hover{background:var(--error);color:#fff;}

    /* Error alert */
    .alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1.1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}

    /* Status pages (shared CSS) */
    .status-hero{text-align:center;padding:2.5rem 2rem;}
    .status-icon{width:80px;height:80px;border-radius:50%;margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;font-size:2rem;}
    .icon-green{background:var(--green-bg);color:var(--green);border:2px solid var(--green-bdr);}
    .icon-muted{background:#f1f5f9;color:#475569;border:2px solid #e2e8f0;}
    .icon-red  {background:var(--error-bg);color:var(--error);border:2px solid var(--error-bdr);}
    .status-title{font-family:'DM Serif Display',serif;font-size:1.8rem;color:var(--navy);margin-bottom:.5rem;}
    .status-sub{font-size:.9rem;color:var(--text-muted);line-height:1.6;margin-bottom:.75rem;}

    /* Expiry warning */
    .expiry-note{display:flex;align-items:center;gap:.5rem;font-size:.78rem;color:var(--text-light);margin-top:.5rem;}

    @media(max-width:500px){.invite-hero-inner{flex-direction:column;}.header-steps{display:none;}.btn{font-size:.85rem;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>

<header class="top-header">
    <a href="/app/" class="logo">Old <span>U</span>nion</a>
    <div class="header-steps">
        <div class="step-pill active"><i class="fa-solid fa-circle-dot"></i> Review Invitation</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Accept / Decline</div>
        <span class="step-sep"><i class="fa-solid fa-chevron-right"></i></span>
        <div class="step-pill"><i class="fa-regular fa-circle"></i> Done</div>
    </div>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page">
<div class="page-inner">

    <!-- Campaign hero -->
    <div class="invite-hero">
        <div class="invite-hero-inner">
            <div class="invite-logo">
                <?php if ($invite['company_logo']): ?>
                    <img src="<?php echo htmlspecialchars($invite['company_logo']); ?>"
                         alt="<?php echo htmlspecialchars($invite['company_name']); ?>">
                <?php else: ?>
                    <div class="invite-logo-ph"><?php echo strtoupper(substr($invite['company_name'], 0, 1)); ?></div>
                <?php endif; ?>
            </div>
            <div>
                <div class="invite-company"><?php echo htmlspecialchars($invite['company_name']); ?></div>
                <div class="invite-campaign"><?php echo htmlspecialchars($invite['campaign_title']); ?></div>
                <span class="invite-type-chip">
                    <i class="fa-solid <?php echo $ctInfo[1]; ?>"></i>
                    <?php echo $ctInfo[0]; ?>
                </span>
            </div>
        </div>
    </div>

    <?php if (!empty($postError)): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($postError); ?>
        </div>
    <?php endif; ?>

    <!-- Campaign terms snapshot -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-file-contract"></i> Campaign Details</div>
        </div>
        <div class="card-body">
            <div class="term-row"><span class="term-lbl">Company</span><span class="term-val"><?php echo htmlspecialchars($invite['company_name']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Campaign</span><span class="term-val"><?php echo htmlspecialchars($invite['campaign_title']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Type</span><span class="term-val"><?php echo $ctInfo[0]; ?></span></div>
            <div class="term-row"><span class="term-lbl">Raise Target</span><span class="term-val highlight"><?php echo fmtR($invite['raise_target']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Min. Raise</span><span class="term-val"><?php echo fmtR($invite['raise_minimum']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Min. Investment</span><span class="term-val"><?php echo fmtR($invite['min_contribution']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Opens</span><span class="term-val"><?php echo fmtD($invite['opens_at']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Closes</span><span class="term-val"><?php echo fmtD($invite['closes_at']); ?></span></div>
            <div class="term-row"><span class="term-lbl">Max Contributors</span><span class="term-val"><?php echo (int)$invite['max_contributors']; ?> (private placement)</span></div>
        </div>
    </div>

    <!-- Risk disclosure & consent form -->
    <div class="card">
        <div class="card-header">
            <div class="card-title"><i class="fa-solid fa-shield-halved"></i> Risk Disclosure</div>
        </div>
        <div class="card-body">
            <div class="risk-box">
                <strong>Important Notice — Please Read Carefully</strong><br><br>
                This is a private placement opportunity offered under Section 96(1)(b) of the Companies Act, 2008 (South Africa).
                Participation is restricted to a maximum of <?php echo (int)$invite['max_contributors']; ?> persons per campaign.<br><br>
                <strong>Risk factors include but are not limited to:</strong>
                Investing in early-stage and community businesses carries significant risk. You may lose part or all of your investment.
                Returns are not guaranteed and depend entirely on the company's financial performance.
                This opportunity has not been registered with the Financial Sector Conduct Authority (FSCA) under the Financial Markets Act or Collective Investment Schemes Control Act.
                It is not a deposit, and is not covered by any depositor protection scheme.<br><br>
                <strong>Old Union's role:</strong>
                Old Union acts as a technology facilitator only. It does not provide financial advice, conduct due diligence on behalf of investors, or underwrite any investment.
                You are encouraged to seek independent legal and financial advice before committing any funds.<br><br>
                By ticking the box below, you confirm that you understand and accept these risks.
            </div>

            <form method="POST" id="inviteForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <label class="ack-label">
                    <input type="checkbox" name="acknowledge_risk" id="ackRisk" value="1">
                    <span>I have read and understood the risk disclosure above. I acknowledge that this investment carries risk and that Old Union is acting as a facilitator only.</span>
                </label>
                <div class="action-row">
                    <button type="submit" name="action" value="accept" class="btn btn-accept">
                        <i class="fa-solid fa-circle-check"></i> Accept Invitation
                    </button>
                    <button type="submit" name="action" value="decline" class="btn btn-decline"
                        onclick="return confirm('Are you sure you want to decline this invitation? This cannot be undone.')">
                        <i class="fa-solid fa-circle-xmark"></i> Decline
                    </button>
                </div>
            </form>

            <div class="expiry-note">
                <i class="fa-regular fa-clock"></i>
                This invitation expires on <?php echo date('d M Y \a\t H:i', strtotime($invite['expires_at'])); ?> SAST
            </div>
        </div>
    </div>

</div>
</div>

<script>
// Require acknowledgement before either button fires
document.getElementById('inviteForm').addEventListener('submit', function(e) {
    if (!document.getElementById('ackRisk').checked) {
        e.preventDefault();
        alert('Please tick the acknowledgement box before proceeding.');
    }
});
</script>

</body>
</html>
<?php
/* ── JUMP TARGETS (avoids duplicating HTML boilerplate) ────── */
exit;

/* ── renderStatus — show outcome card ───────────────────────── */
renderStatus:
$statusIcon   = 'icon-muted';
$statusIcon_i = 'fa-question';
$statusTitle  = 'Invitation';
$statusSub    = '';
$nextAction   = '';

if ($invite['status'] === 'accepted') {
    $statusIcon   = 'icon-green';
    $statusIcon_i = 'fa-circle-check';
    $statusTitle  = 'You\'re in!';
    $statusSub    = 'You have accepted the invitation to <strong>' . htmlspecialchars($invite['campaign_title']) . '</strong> by ' . htmlspecialchars($invite['company_name']) . '. You can now view full campaign details and invest when the campaign opens.';
    $nextAction   = '<a href="/app/discover/campaign.php?cid=' . urlencode($invite['campaign_uuid']) . '" style="display:inline-flex;align-items:center;gap:.45rem;padding:.75rem 1.5rem;background:#0f3b7a;color:#fff;border-radius:99px;font-weight:600;text-decoration:none;font-size:.9rem;margin-top:.5rem;"><i class="fa-solid fa-rocket"></i> View Campaign</a>';
} elseif ($invite['status'] === 'declined') {
    $statusIcon   = 'icon-muted';
    $statusIcon_i = 'fa-circle-xmark';
    $statusTitle  = 'Invitation Declined';
    $statusSub    = 'You declined the invitation to <strong>' . htmlspecialchars($invite['campaign_title']) . '</strong>. No action has been taken on your account. You can still discover other opportunities on Old Union.';
    $nextAction   = '<a href="/app/discover/" style="display:inline-flex;align-items:center;gap:.45rem;padding:.75rem 1.5rem;background:#0f3b7a;color:#fff;border-radius:99px;font-weight:600;text-decoration:none;font-size:.9rem;margin-top:.5rem;"><i class="fa-solid fa-compass"></i> Discover Opportunities</a>';
} elseif ($invite['status'] === 'revoked') {
    $statusIcon   = 'icon-red';
    $statusIcon_i = 'fa-ban';
    $statusTitle  = 'Invitation Revoked';
    $statusSub    = 'This invitation has been revoked by the company. If you believe this is an error, please contact the company directly.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investment Invitation | Old Union</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    *{box-sizing:border-box;margin:0;padding:0;}body{font-family:'DM Sans',sans-serif;background:#f8f9fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
    .card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;box-shadow:0 8px 28px rgba(11,37,69,.09);max-width:520px;width:100%;overflow:hidden;}
    .status-hero{text-align:center;padding:2.5rem 2rem 2rem;}
    .status-icon{width:80px;height:80px;border-radius:50%;margin:0 auto 1.25rem;display:flex;align-items:center;justify-content:center;font-size:2rem;}
    .icon-green{background:#e6f7ec;color:#0b6b4d;border:2px solid #a7f3d0;}
    .icon-muted{background:#f1f5f9;color:#475569;border:2px solid #e2e8f0;}
    .icon-red  {background:#fef2f2;color:#b91c1c;border:2px solid #fecaca;}
    .status-title{font-family:'DM Serif Display',serif;font-size:1.8rem;color:#0b2545;margin-bottom:.5rem;}
    .status-sub{font-size:.9rem;color:#667085;line-height:1.65;}
    </style>
</head>
<body>
<div class="card">
    <div class="status-hero">
        <div class="status-icon <?php echo $statusIcon; ?>">
            <i class="fa-solid <?php echo $statusIcon_i; ?>"></i>
        </div>
        <h2 class="status-title"><?php echo $statusTitle; ?></h2>
        <p class="status-sub"><?php echo $statusSub; ?></p>
        <?php echo $nextAction; ?>
    </div>
</div>
</body>
</html>
<?php exit;

/* ── renderError ─────────────────────────────────────────────── */
renderError:
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invalid Invitation | Old Union</title>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500&display=swap" rel="stylesheet">
    <style>
    *{box-sizing:border-box;margin:0;padding:0;}body{font-family:'DM Sans',sans-serif;background:#f8f9fb;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
    .card{background:#fff;border:1px solid #e4e7ec;border-radius:14px;box-shadow:0 8px 28px rgba(11,37,69,.09);max-width:480px;width:100%;padding:2.5rem 2rem;text-align:center;}
    h2{font-family:'DM Serif Display',serif;font-size:1.7rem;color:#0b2545;margin-bottom:.6rem;}
    p{font-size:.9rem;color:#667085;line-height:1.65;margin-bottom:1.25rem;}
    a{display:inline-flex;align-items:center;gap:.45rem;padding:.7rem 1.4rem;background:#0f3b7a;color:#fff;border-radius:99px;font-weight:600;text-decoration:none;font-size:.9rem;}
    </style>
</head>
<body>
<div class="card">
    <h2>Invalid Invitation</h2>
    <p><?php echo htmlspecialchars($errorMsg ?? 'This invitation link is not valid.'); ?></p>
    <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Discover Opportunities</a>
</div>
</body>
</html>
<?php exit;
