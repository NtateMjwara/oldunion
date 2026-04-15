<?php
/**
 * /app/invest/claim_invite.php
 *
 * US-103 — External Invite Landing Page (Pre-Auth)
 *
 * Entry point for signed links sent to non-users.
 * Handles four distinct states:
 *
 *   A. Token invalid / not found         → clean error + contact link
 *   B. Token expired                     → expiry page + re-request form
 *   C. Token valid, user IS logged in    → redirect to accept_invite.php
 *      (which handles the full risk disclosure / accept / decline flow)
 *   D. Token valid, user NOT logged in   → choice card:
 *      "I have an account → login" / "I'm new → register"
 *      Token is preserved in session so it survives the auth redirect.
 *
 * Criterion 3: Invite stored with user_id = NULL; user_id is backfilled
 * when the account is created + verified (handled in authentication.php
 * and verify.php patches — see migration_us103_auth_patch notes).
 *
 * Criterion 4: Expired tokens show a clean error with a re-request option
 * (a form that emails the founder to ask for a fresh link — or, if the
 * platform allows self-serve re-request, POSTs to this page).
 */

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
// Note: session.php is included here but we do NOT require isLoggedIn()
// because this page is intentionally accessible to unauthenticated users.
require_once '../includes/session.php';

$rawToken = trim($_GET['token'] ?? '');

// ── State A: no token ──────────────────────────────────────
if (empty($rawToken)) {
    renderError('Missing invitation token.', 'The invitation link appears to be incomplete. Please check the email you received and try again.');
    exit;
}

$pdo = Database::getInstance();

// ── Load invite by token ────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        ci.id          AS invite_id,
        ci.uuid        AS invite_uuid,
        ci.campaign_id,
        ci.user_id,
        ci.guest_email,
        ci.token,
        ci.status,
        ci.invite_source,
        ci.expires_at,
        fc.uuid        AS campaign_uuid,
        fc.title       AS campaign_title,
        fc.campaign_type,
        fc.status      AS campaign_status,
        c.name         AS company_name,
        c.logo         AS company_logo,
        c.uuid         AS company_uuid
    FROM campaign_invites ci
    JOIN funding_campaigns fc ON fc.id = ci.campaign_id
    JOIN companies c          ON c.id  = fc.company_id
    WHERE ci.token = :token
    LIMIT 1
");
$stmt->execute(['token' => $rawToken]);
$invite = $stmt->fetch();

// ── State A: token not found ────────────────────────────────
if (!$invite) {
    renderError(
        'Invalid invitation link.',
        'This invitation link was not found. It may have already been used, revoked, or the link may be incomplete. Please contact the company that invited you to request a new link.'
    );
    exit;
}

// ── State A: revoked or already fully consumed ──────────────
if ($invite['status'] === 'accepted') {
    renderStatus(
        'fa-circle-check', 'icon-green',
        'Invitation Already Accepted',
        'This invitation has already been accepted. Log in to your Old Union account to view the campaign.',
        '<a href="/app/auth/login.php" class="btn btn-navy"><i class="fa-solid fa-right-to-bracket"></i> Log In</a>'
    );
    exit;
}
if ($invite['status'] === 'revoked') {
    renderError(
        'Invitation Revoked',
        'This invitation has been revoked by the issuing company. Please contact them directly if you believe this is an error.'
    );
    exit;
}

// ── State B: expired ────────────────────────────────────────
if (strtotime($invite['expires_at']) < time()) {
    renderExpired($invite);
    exit;
}

// ── State C: token valid + user IS logged in ─────────────────
// Redirect to accept_invite.php which uses the invite UUID (not the token)
// and handles the full risk disclosure flow.
if (isset($_SESSION['user_id'])) {
    $loggedInUserId = (int)$_SESSION['user_id'];

    // If user_id is already set on the invite, confirm it matches
    if ($invite['user_id'] !== null && (int)$invite['user_id'] !== $loggedInUserId) {
        renderError(
            'Wrong Account',
            'This invitation was sent to a different email address. Please log out and log in with the account associated with ' . htmlspecialchars($invite['guest_email'] ?? 'the invited email') . '.'
        );
        exit;
    }

    // If user_id is NULL, backfill it now (user registered and is logged in)
    if ($invite['user_id'] === null) {
        $stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
        $stmt->execute([$loggedInUserId]);
        $userRow = $stmt->fetch();

        // Only backfill if the logged-in user's email matches guest_email
        if ($userRow && strtolower($userRow['email']) === strtolower($invite['guest_email'] ?? '')) {
            $pdo->prepare("
                UPDATE campaign_invites SET user_id = :uid, updated_at = NOW()
                WHERE id = :id
            ")->execute(['uid' => $loggedInUserId, 'id' => $invite['invite_id']]);
        } else {
            // Email mismatch — logged in as a different user
            renderError(
                'Wrong Account',
                'This invitation was sent to ' . htmlspecialchars($invite['guest_email'] ?? 'a different address') . '. Please log in with the account registered to that email address.'
            );
            exit;
        }
    }

    // Redirect to the full accept/decline flow using the invite UUID
    redirect('/app/invest/accept_invite.php?token=' . urlencode($invite['invite_uuid']));
    exit;
}

// ── State D: token valid + NOT logged in ─────────────────────
// Store the token in session so it survives the login/register redirect
$_SESSION['pending_invite_token']  = $rawToken;
$_SESSION['pending_invite_email']  = $invite['guest_email'] ?? '';

// Render the choice card
renderChoicePage($invite);
exit;

/* ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
   RENDER FUNCTIONS
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━ */

function renderChoicePage(array $invite): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Private Investment Invitation | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    <?php echo baseStyles(); ?>

    body { background: var(--navy); min-height: 100vh; display: flex; flex-direction: column; align-items: center; justify-content: center; padding: 2rem; }

    .outer-wrap { max-width: 560px; width: 100%; }

    /* Gold rule header */
    .brand-header { text-align: center; margin-bottom: 2rem; }
    .brand-wordmark { font-family: 'Cormorant Garamond', serif; font-size: 1.8rem; font-weight: 600; color: #fff; letter-spacing: .03em; }
    .brand-wordmark span { color: #c8102e; }
    .brand-sub { font-size: .7rem; text-transform: uppercase; letter-spacing: .2em; color: rgba(255,255,255,.3); margin-top: .3rem; }

    /* Invitation card */
    .invite-card { background: #fff; border-radius: 16px; overflow: hidden; box-shadow: 0 24px 64px rgba(0,0,0,.4); }
    .invite-card-hero { background: linear-gradient(135deg, #0b2545 0%, #0f3b7a 100%); padding: 2rem 2rem 1.75rem; border-bottom: 3px solid var(--gold); position: relative; overflow: hidden; }
    .invite-card-hero::after { content: ''; position: absolute; top: -40px; right: -40px; width: 160px; height: 160px; border-radius: 50%; border: 40px solid rgba(200,168,75,.08); pointer-events: none; }
    .company-row { display: flex; align-items: center; gap: .85rem; margin-bottom: 1.1rem; }
    .company-logo-wrap { width: 48px; height: 48px; border-radius: 10px; background: rgba(255,255,255,.1); border: 1.5px solid rgba(255,255,255,.15); overflow: hidden; display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
    .company-logo-wrap img { width: 100%; height: 100%; object-fit: cover; }
    .company-logo-ph { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; font-family: 'Cormorant Garamond', serif; font-size: 1.3rem; color: var(--gold); }
    .company-name-label { font-size: .68rem; text-transform: uppercase; letter-spacing: .12em; color: rgba(255,255,255,.4); margin-bottom: .2rem; }
    .company-name-val { font-size: .95rem; font-weight: 600; color: #fff; }
    .invite-eyebrow { font-size: .7rem; text-transform: uppercase; letter-spacing: .14em; color: var(--gold); margin-bottom: .5rem; }
    .invite-campaign-title { font-family: 'Cormorant Garamond', serif; font-size: 1.5rem; font-weight: 600; color: #fff; line-height: 1.25; margin-bottom: .5rem; }
    .invite-type-pill { display: inline-flex; align-items: center; gap: .3rem; padding: .22rem .7rem; border-radius: 99px; font-size: .73rem; font-weight: 500; background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.15); color: rgba(255,255,255,.75); }
    .expires-note { display: flex; align-items: center; gap: .4rem; margin-top: .85rem; font-size: .74rem; color: rgba(255,255,255,.4); }

    /* Choice body */
    .choice-body { padding: 1.75rem 2rem 2rem; }
    .choice-intro { font-size: .9rem; color: var(--text-muted); line-height: 1.6; margin-bottom: 1.5rem; }
    .choice-intro strong { color: var(--text); }

    .choice-cards { display: grid; grid-template-columns: 1fr 1fr; gap: .85rem; margin-bottom: 1.25rem; }
    .choice-card { border: 2px solid var(--border); border-radius: 10px; padding: 1.1rem 1rem; text-decoration: none; color: inherit; text-align: center; transition: all .2s; display: flex; flex-direction: column; align-items: center; gap: .55rem; }
    .choice-card:hover { border-color: var(--navy-light); background: #eff4ff; }
    .choice-card.primary { border-color: var(--navy-mid); background: var(--navy-mid); color: #fff; }
    .choice-card.primary:hover { background: var(--navy); border-color: var(--navy); }
    .choice-icon { width: 44px; height: 44px; border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 1.1rem; }
    .choice-card.primary .choice-icon { background: rgba(255,255,255,.15); color: #fff; }
    .choice-card:not(.primary) .choice-icon { background: var(--surface-2); color: var(--navy-mid); }
    .choice-label { font-size: .88rem; font-weight: 600; line-height: 1.25; }
    .choice-desc  { font-size: .76rem; opacity: .7; line-height: 1.4; }

    .divider-text { text-align: center; font-size: .74rem; color: var(--text-light); position: relative; margin: .4rem 0; }
    .divider-text::before, .divider-text::after { content: ''; position: absolute; top: 50%; width: 42%; height: 1px; background: var(--border); }
    .divider-text::before { left: 0; }
    .divider-text::after  { right: 0; }

    .legal-note-small { font-size: .74rem; color: var(--text-light); line-height: 1.55; text-align: center; border-top: 1px solid var(--border); padding-top: 1rem; margin-top: 1.1rem; }

    @media(max-width:480px) { .choice-cards { grid-template-columns: 1fr; } body { padding: 1rem; } .choice-body { padding: 1.25rem; } }
    </style>
</head>
<body>
    <div class="outer-wrap">
        <div class="brand-header">
            <div class="brand-wordmark">Old <span>U</span>nion</div>
            <div class="brand-sub">Private Investment Platform</div>
        </div>

        <div class="invite-card">
            <!-- Campaign hero -->
            <div class="invite-card-hero">
                <div class="company-row">
                    <div class="company-logo-wrap">
                        <?php if (!empty($invite['company_logo'])): ?>
                            <img src="<?php echo htmlspecialchars($invite['company_logo']); ?>"
                                 alt="<?php echo htmlspecialchars($invite['company_name']); ?>">
                        <?php else: ?>
                            <div class="company-logo-ph"><?php echo strtoupper(substr($invite['company_name'], 0, 1)); ?></div>
                        <?php endif; ?>
                    </div>
                    <div>
                        <div class="company-name-label">Invited by</div>
                        <div class="company-name-val"><?php echo htmlspecialchars($invite['company_name']); ?></div>
                    </div>
                </div>
                <div class="invite-eyebrow">Private Investment Opportunity</div>
                <div class="invite-campaign-title"><?php echo htmlspecialchars($invite['campaign_title']); ?></div>
                <?php
                $typeLabels = ['revenue_share' => 'Revenue Share', 'cooperative_membership' => 'Co-op Membership'];
                $typeIcons  = ['revenue_share' => 'fa-chart-line', 'cooperative_membership' => 'fa-people-roof'];
                $tLabel = $typeLabels[$invite['campaign_type']] ?? 'Campaign';
                $tIcon  = $typeIcons[$invite['campaign_type']] ?? 'fa-rocket';
                ?>
                <span class="invite-type-pill"><i class="fa-solid <?php echo $tIcon; ?>"></i> <?php echo $tLabel; ?></span>
                <div class="expires-note">
                    <i class="fa-regular fa-clock"></i>
                    This invitation expires <?php echo date('d M Y \a\t H:i', strtotime($invite['expires_at'])); ?> SAST
                </div>
            </div>

            <!-- Choice body -->
            <div class="choice-body">
                <p class="choice-intro">
                    You've been personally selected for this private investment opportunity.
                    To view the full campaign terms and decide whether to participate,
                    <strong>sign in to your Old Union account</strong> — or <strong>create one for free</strong> if you don't have one yet.
                </p>

                <div class="choice-cards">
                    <!-- New user — primary CTA -->
                    <a href="/app/auth/register.php?invite=<?php echo urlencode($_SESSION['pending_invite_token'] ?? ''); ?>&email=<?php echo urlencode($invite['guest_email'] ?? ''); ?>"
                       class="choice-card primary">
                        <div class="choice-icon"><i class="fa-solid fa-user-plus"></i></div>
                        <div>
                            <div class="choice-label">Create Account</div>
                            <div class="choice-desc">New to Old Union? Register free in under a minute</div>
                        </div>
                    </a>

                    <!-- Existing user -->
                    <a href="/app/auth/login.php?invite=<?php echo urlencode($_SESSION['pending_invite_token'] ?? ''); ?>&redirect=<?php echo urlencode('/app/invest/claim_invite.php?token=' . urlencode($_SESSION['pending_invite_token'] ?? '')); ?>"
                       class="choice-card">
                        <div class="choice-icon"><i class="fa-solid fa-right-to-bracket"></i></div>
                        <div>
                            <div class="choice-label">Log In</div>
                            <div class="choice-desc">Already have an account? Sign in to claim</div>
                        </div>
                    </a>
                </div>

                <div class="legal-note-small">
                    Old Union is a private placement technology facilitator only. This invitation is not an offer of financial advice.
                    This opportunity is restricted to a limited number of invited individuals under South African private placement regulations.
                    Investing carries risk — you may lose part or all of your investment.
                    Always seek independent financial advice before committing funds.
                </div>
            </div>
        </div>
    </div>
</body>
</html>
<?php
}

/* ── renderExpired ─────────────────────────────────────────── */
function renderExpired(array $invite): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Invitation Expired | Old Union</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style><?php echo baseStyles(); ?>
    body { background: var(--surface-2); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 2rem; }
    .card { background: #fff; border: 1px solid var(--border); border-radius: 14px; box-shadow: 0 8px 32px rgba(10,15,26,.1); max-width: 480px; width: 100%; overflow: hidden; }
    .card-top { background: var(--navy); padding: 1.5rem 2rem; }
    .brand { font-family: 'Cormorant Garamond', serif; font-size: 1.3rem; font-weight: 600; color: #fff; }
    .brand span { color: #c8102e; }
    .card-body { padding: 2rem; text-align: center; }
    .icon-ring { width: 72px; height: 72px; border-radius: 50%; background: var(--amber-bg); border: 2px solid var(--amber-bdr); display: flex; align-items: center; justify-content: center; font-size: 1.8rem; color: var(--amber); margin: 0 auto 1.25rem; }
    h2 { font-family: 'Cormorant Garamond', serif; font-size: 1.7rem; color: var(--navy); margin-bottom: .5rem; }
    p  { font-size: .88rem; color: var(--text-muted); line-height: 1.65; margin-bottom: 1rem; }
    .company-chip { display: inline-flex; align-items: center; gap: .4rem; padding: .28rem .85rem; background: #eff4ff; border: 1px solid #c7d9f8; border-radius: 99px; font-size: .8rem; font-weight: 600; color: var(--navy-mid); margin-bottom: 1.25rem; }
    .btn { display: inline-flex; align-items: center; gap: .45rem; padding: .72rem 1.5rem; border-radius: 99px; font-family: 'Jost', sans-serif; font-size: .9rem; font-weight: 600; text-decoration: none; border: none; cursor: pointer; transition: all .2s; }
    .btn-navy { background: var(--navy-mid); color: #fff; }.btn-navy:hover { background: var(--navy); }
    .btn-ghost { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border); }.btn-ghost:hover { border-color: #94a3b8; }
    .btn-row { display: flex; gap: .65rem; justify-content: center; flex-wrap: wrap; }
    .re-request-form { margin-top: 1.5rem; border-top: 1px solid var(--border); padding-top: 1.5rem; }
    .re-request-form p { margin-bottom: .75rem; font-size: .82rem; }
    .re-request-form input { width: 100%; max-width: 320px; padding: .65rem .9rem; border: 1.5px solid var(--border); border-radius: var(--radius-sm); font-family: 'Jost', sans-serif; font-size: .9rem; outline: none; margin-bottom: .75rem; }
    .re-request-form input:focus { border-color: var(--navy-light); box-shadow: 0 0 0 3px rgba(26,86,176,.1); }
    .sent-confirm { background: var(--green-bg); border: 1px solid var(--green-bdr); color: var(--green); border-radius: var(--radius-sm); padding: .75rem 1rem; font-size: .85rem; font-weight: 500; display: none; margin-top: .5rem; }
    </style>
</head>
<body>
<div class="card">
    <div class="card-top"><div class="brand">Old <span>U</span>nion</div></div>
    <div class="card-body">
        <div class="icon-ring"><i class="fa-regular fa-clock"></i></div>
        <h2>Invitation Expired</h2>
        <div class="company-chip">
            <i class="fa-solid fa-building" style="font-size:.72rem;"></i>
            <?php echo htmlspecialchars($invite['company_name']); ?>
        </div>
        <p>
            Your invitation to <strong><?php echo htmlspecialchars($invite['campaign_title']); ?></strong>
            expired on <?php echo date('d M Y \a\t H:i', strtotime($invite['expires_at'])); ?> SAST.
            Invitation links are only valid for 72 hours.
        </p>
        <p>To request a fresh link, contact the company directly — or use the form below to notify them.</p>

        <div class="btn-row">
            <a href="/app/discover/" class="btn btn-navy"><i class="fa-solid fa-compass"></i> Browse Opportunities</a>
        </div>

        <!-- Re-request form (criterion 4) -->
        <div class="re-request-form">
            <p>Want to request a new invitation link from <strong><?php echo htmlspecialchars($invite['company_name']); ?></strong>?</p>
            <form id="rereqForm" onsubmit="handleReRequest(event)">
                <input type="email" id="rereqEmail"
                    placeholder="Your email address"
                    value="<?php echo htmlspecialchars($invite['guest_email'] ?? ''); ?>"
                    required>
                <br>
                <button type="submit" class="btn btn-ghost">
                    <i class="fa-solid fa-paper-plane"></i> Notify Company &amp; Request Fresh Link
                </button>
            </form>
            <div class="sent-confirm" id="sentConfirm">
                <i class="fa-solid fa-circle-check"></i>
                Request sent. The company has been notified and can re-issue your invitation from their campaign management panel.
            </div>
        </div>
    </div>
</div>
<script>
function handleReRequest(e) {
    e.preventDefault();
    const email = document.getElementById('rereqEmail').value;
    // POST to a lightweight endpoint that notifies the campaign founder
    fetch('/app/invest/request_reinvite.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            campaign_id:   <?php echo (int)$invite['campaign_id']; ?>,
            campaign_uuid: <?php echo json_encode($invite['campaign_uuid']); ?>,
            guest_email:   email,
            invite_uuid:   <?php echo json_encode($invite['invite_uuid']); ?>
        })
    })
    .then(r => r.json())
    .then(() => {
        document.getElementById('rereqForm').style.display = 'none';
        document.getElementById('sentConfirm').style.display = 'block';
    })
    .catch(() => {
        alert('Could not send request. Please contact the company directly.');
    });
}
</script>
</body>
</html>
<?php
}

/* ── renderError ───────────────────────────────────────────── */
function renderError(string $title, string $message): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | Old Union</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Jost:wght@400;500&display=swap" rel="stylesheet">
    <style>
    <?php echo baseStyles(); ?>
    body{background:var(--surface-2);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
    .card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(10,15,26,.1);max-width:440px;width:100%;padding:2.5rem 2rem;text-align:center;}
    .icon-ring{width:68px;height:68px;border-radius:50%;background:var(--error-bg);border:2px solid var(--error-bdr);display:flex;align-items:center;justify-content:center;font-size:1.7rem;color:var(--error);margin:0 auto 1.25rem;}
    h2{font-family:'Cormorant Garamond',serif;font-size:1.6rem;color:var(--navy);margin-bottom:.5rem;}
    p{font-size:.88rem;color:var(--text-muted);line-height:1.65;margin-bottom:1.25rem;}
    a.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.35rem;border-radius:99px;background:var(--navy-mid);color:#fff;font-family:'Jost',sans-serif;font-size:.88rem;font-weight:500;text-decoration:none;}
    a.btn:hover{background:var(--navy);}
    </style>
</head>
<body>
<div class="card">
    <div class="icon-ring"><i class="fa-solid fa-triangle-exclamation"></i></div>
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <p><?php echo htmlspecialchars($message); ?></p>
    <a href="/app/discover/" class="btn"><i class="fa-solid fa-compass"></i> Browse Opportunities</a>
</div>
</body>
</html>
<?php
}

/* ── renderStatus ──────────────────────────────────────────── */
function renderStatus(string $icon, string $iconClass, string $title, string $message, string $cta = ''): void { ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($title); ?> | Old Union</title>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:wght@400;600&family=Jost:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    <?php echo baseStyles(); ?>
    body{background:var(--surface-2);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:2rem;}
    .card{background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(10,15,26,.1);max-width:440px;width:100%;padding:2.5rem 2rem;text-align:center;}
    .icon-ring{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.75rem;margin:0 auto 1.25rem;}
    .icon-green{background:var(--green-bg);border:2px solid var(--green-bdr);color:var(--green);}
    .icon-muted{background:#f1f5f9;border:2px solid #e2e8f0;color:#475569;}
    .icon-red  {background:var(--error-bg);border:2px solid var(--error-bdr);color:var(--error);}
    h2{font-family:'Cormorant Garamond',serif;font-size:1.65rem;color:var(--navy);margin-bottom:.5rem;}
    p{font-size:.88rem;color:var(--text-muted);line-height:1.65;margin-bottom:1.25rem;}
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.65rem 1.35rem;border-radius:99px;font-family:'Jost',sans-serif;font-size:.88rem;font-weight:600;text-decoration:none;border:none;cursor:pointer;}
    .btn-navy{background:var(--navy-mid);color:#fff;}.btn-navy:hover{background:var(--navy);}
    </style>
</head>
<body>
<div class="card">
    <div class="icon-ring <?php echo $iconClass; ?>"><i class="fa-solid <?php echo $icon; ?>"></i></div>
    <h2><?php echo htmlspecialchars($title); ?></h2>
    <p><?php echo htmlspecialchars($message); ?></p>
    <?php echo $cta; ?>
</div>
</body>
</html>
<?php
}

/* ── Shared CSS ────────────────────────────────────────────── */
function baseStyles(): string {
    return '
    :root{
        --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
        --gold:#c8a84b;--gold-dim:rgba(200,168,75,.12);
        --surface:#fff;--surface-2:#f7f8fa;--border:#e2e7ee;
        --text:#101828;--text-muted:#5a6a80;--text-light:#96a3b4;
        --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
        --amber:#d97706;--amber-bg:#fef3e2;--amber-bdr:#fcd34d;
        --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
        --radius:10px;--radius-sm:6px;
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:\'Jost\',sans-serif;-webkit-font-smoothing:antialiased;}
    ';
}
