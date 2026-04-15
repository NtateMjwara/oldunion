<?php
/**
 * /app/company/campaigns/external_invite.php
 *
 * US-103 — External Email Invite
 *
 * Founders enter an email address for someone who is NOT yet a
 * platform user. The system:
 *   1. Validates the address is not already registered (if they
 *      are, the founder should use investor_directory.php instead).
 *   2. Creates a campaign_invites row with guest_email populated,
 *      user_id = NULL, invite_source = 'external_email', and a
 *      raw hex token embedded in the invite URL.
 *   3. Sends a templated email with the signed, time-limited link.
 *   4. Writes a compliance_events entry (criterion 5).
 *
 * A "re-send" flow handles expired tokens: update the existing row
 * with a new token + new expires_at rather than inserting a new row,
 * preserving the audit trail on a single row per email+campaign.
 *
 * Dependencies:
 *   • migration_us101_campaign_invites.sql
 *   • migration_us103_external_invites.sql  ← nullable user_id, token col
 *   • mailer.php (sendEmail)
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$companyUuid  = trim($_GET['uuid'] ?? '');
$campaignUuid = trim($_GET['cid']  ?? '');

if (empty($companyUuid) || empty($campaignUuid)) {
    redirect('/app/company/');
}

$company = getCompanyByUuid($companyUuid);
if (!$company || $company['status'] !== 'active' || !$company['verified']) {
    redirect('/app/company/');
}

requireCompanyRole($company['id'], 'admin');

$pdo        = Database::getInstance();
$userId     = (int)$_SESSION['user_id'];
$companyId  = (int)$company['id'];
$csrf_token = generateCSRFToken();

/* ── Load campaign ───────────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT id, uuid, title, status, max_contributors
    FROM funding_campaigns
    WHERE uuid = :uuid AND company_id = :cid
");
$stmt->execute(['uuid' => $campaignUuid, 'cid' => $companyId]);
$campaign = $stmt->fetch();

if (!$campaign || in_array($campaign['status'], ['cancelled','suspended','closed_unsuccessful','closed_successful'], true)) {
    redirect('/app/company/campaigns/index.php?uuid=' . urlencode($companyUuid));
}

$campaignId = (int)$campaign['id'];

/* ── Count issued slots ──────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM campaign_invites
    WHERE campaign_id = ? AND status IN ('pending','accepted')
");
$stmt->execute([$campaignId]);
$slotsIssued    = (int)$stmt->fetchColumn();
$slotsAvailable = max(0, (int)$campaign['max_contributors'] - $slotsIssued);

/* ── Load recent external invites for this campaign ─────────── */
$stmt = $pdo->prepare("
    SELECT guest_email, status, expires_at, created_at, re_requested_at
    FROM campaign_invites
    WHERE campaign_id = ? AND invite_source = 'external_email'
    ORDER BY created_at DESC
    LIMIT 30
");
$stmt->execute([$campaignId]);
$externalInvites = $stmt->fetchAll();

/* ── User info ───────────────────────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me          = $stmt->fetch();
$userInitial = $me ? strtoupper(substr($me['email'] ?? '', 0, 1)) : 'U';

/* ── Helpers ─────────────────────────────────────────────────── */
$errors     = [];
$successMsg = '';
$warnMsg    = '';

function generateInviteToken(): string {
    return bin2hex(random_bytes(32)); // 64-char hex, ~256 bits entropy
}

/* ── POST handler ────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $rawEmail = trim($_POST['guest_email'] ?? '');
        $email    = filter_var($rawEmail, FILTER_VALIDATE_EMAIL);

        if (!$email) {
            $errors[] = 'Please enter a valid email address.';
        } elseif ($slotsAvailable <= 0) {
            $errors[] = 'No invitation slots remaining for this campaign.';
        } else {

            // ── Check if this email already belongs to a registered user ──
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $existingUser = $stmt->fetch();

            if ($existingUser) {
                // Registered user — this form is for non-users only.
                // The directory flow (investor_directory.php) handles registered users.
                $warnMsg = 'This email address belongs to a registered Old Union user. Please use the <a href="/app/company/campaigns/investor_directory.php?uuid=' . urlencode($companyUuid) . '&cid=' . urlencode($campaignUuid) . '">Investor Directory</a> to send them an invitation instead.';
            } else {

                // ── Check for existing invite row for this email+campaign ──
                $stmt = $pdo->prepare("
                    SELECT id, status, expires_at, token
                    FROM campaign_invites
                    WHERE campaign_id = ? AND guest_email = ?
                    LIMIT 1
                ");
                $stmt->execute([$campaignId, $email]);
                $existingInvite = $stmt->fetch();

                if ($existingInvite && in_array($existingInvite['status'], ['accepted'], true)) {
                    $errors[] = 'This email address has already accepted an invitation to this campaign.';

                } elseif ($existingInvite && $existingInvite['status'] === 'pending'
                          && strtotime($existingInvite['expires_at']) > time()) {
                    // Active pending invite — offer to resend rather than duplicate
                    $errors[] = 'An active invitation has already been sent to this address. It expires on '
                              . date('d M Y \a\t H:i', strtotime($existingInvite['expires_at']))
                              . '. Use the "Resend" button in the table below to issue a fresh link.';

                } else {

                    $pdo->beginTransaction();
                    try {
                        $newToken  = generateInviteToken();
                        $expiresAt = date('Y-m-d H:i:s', time() + 72 * 3600); // 72 hours
                        $now       = date('Y-m-d H:i:s');

                        if ($existingInvite) {
                            // Re-issue: update existing row (expired or declined)
                            $pdo->prepare("
                                UPDATE campaign_invites SET
                                    token            = :token,
                                    status           = 'pending',
                                    expires_at       = :exp,
                                    re_requested_at  = :now,
                                    declined_at      = NULL,
                                    updated_at       = :now2
                                WHERE id = :id
                            ")->execute([
                                'token' => $newToken,
                                'exp'   => $expiresAt,
                                'now'   => $now,
                                'now2'  => $now,
                                'id'    => $existingInvite['id'],
                            ]);
                        } else {
                            // New invite row — user_id is NULL (non-user)
                            $inviteUuid = generateUuidV4();
                            $pdo->prepare("
                                INSERT INTO campaign_invites
                                    (uuid, campaign_id, user_id, guest_email,
                                     token, invite_source,
                                     status, invited_by, expires_at)
                                VALUES
                                    (:uuid, :cid, NULL, :email,
                                     :token, 'external_email',
                                     'pending', :by, :exp)
                            ")->execute([
                                'uuid'  => $inviteUuid,
                                'cid'   => $campaignId,
                                'email' => $email,
                                'token' => $newToken,
                                'by'    => $userId,
                                'exp'   => $expiresAt,
                            ]);
                        }

                        // ── Compliance log (criterion 5) ──
                        try {
                            $pdo->prepare("
                                INSERT INTO compliance_events
                                    (event_type, actor_id, target_id, campaign_id,
                                     guest_email, ip_address, user_agent, created_at)
                                VALUES
                                    ('invite_sent_external', :actor, NULL, :cid,
                                     :email, :ip, :ua, NOW())
                            ")->execute([
                                'actor' => $userId,
                                'cid'   => $campaignId,
                                'email' => $email,
                                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
                                'ua'    => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512),
                            ]);
                        } catch (PDOException $e) {
                            error_log('[external_invite] compliance_events skipped: ' . $e->getMessage());
                        }

                        $pdo->commit();

                        // ── Send email ──
                        $claimLink = SITE_URL . '/app/invest/claim_invite.php?token=' . urlencode($newToken);
                        sendExternalInviteEmail(
                            $email,
                            $company['name'],
                            $campaign['title'],
                            $claimLink,
                            $expiresAt
                        );

                        $successMsg = 'Invitation sent to <strong>' . htmlspecialchars($email) . '</strong>. The link expires in 72 hours.';

                        // Reload table
                        $stmt = $pdo->prepare("
                            SELECT guest_email, status, expires_at, created_at, re_requested_at
                            FROM campaign_invites
                            WHERE campaign_id = ? AND invite_source = 'external_email'
                            ORDER BY created_at DESC LIMIT 30
                        ");
                        $stmt->execute([$campaignId]);
                        $externalInvites = $stmt->fetchAll();
                        $slotsIssued++;
                        $slotsAvailable = max(0, (int)$campaign['max_contributors'] - $slotsIssued);

                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('[external_invite] ' . $e->getMessage());
                        $errors[] = 'Could not send invitation. Please try again.';
                    }
                }
            }
        }
    }
}

/* ── Resend handler (GET ?resend=<email>) ────────────────────── */
if (isset($_GET['resend']) && !empty($_GET['resend'])) {
    $resendEmail = filter_var(trim($_GET['resend']), FILTER_VALIDATE_EMAIL);
    if ($resendEmail) {
        $stmt = $pdo->prepare("
            SELECT id, status FROM campaign_invites
            WHERE campaign_id = ? AND guest_email = ? AND invite_source = 'external_email'
            LIMIT 1
        ");
        $stmt->execute([$campaignId, $resendEmail]);
        $rRow = $stmt->fetch();

        if ($rRow && $rRow['status'] !== 'accepted') {
            $pdo->beginTransaction();
            try {
                $newToken  = generateInviteToken();
                $expiresAt = date('Y-m-d H:i:s', time() + 72 * 3600);
                $now       = date('Y-m-d H:i:s');

                $pdo->prepare("
                    UPDATE campaign_invites SET
                        token           = :token,
                        status          = 'pending',
                        expires_at      = :exp,
                        re_requested_at = :now,
                        declined_at     = NULL,
                        updated_at      = :now2
                    WHERE id = :id
                ")->execute(['token'=>$newToken,'exp'=>$expiresAt,'now'=>$now,'now2'=>$now,'id'=>$rRow['id']]);

                try {
                    $pdo->prepare("
                        INSERT INTO compliance_events
                            (event_type, actor_id, campaign_id, guest_email, ip_address, created_at)
                        VALUES ('invite_resent_external', :actor, :cid, :email, :ip, NOW())
                    ")->execute(['actor'=>$userId,'cid'=>$campaignId,'email'=>$resendEmail,'ip'=>$_SERVER['REMOTE_ADDR']??'']);
                } catch (PDOException $e) {}

                $pdo->commit();

                $claimLink = SITE_URL . '/app/invest/claim_invite.php?token=' . urlencode($newToken);
                sendExternalInviteEmail($resendEmail, $company['name'], $campaign['title'], $claimLink, $expiresAt);

                $successMsg = 'Fresh invitation link sent to <strong>' . htmlspecialchars($resendEmail) . '</strong>.';

                $stmt = $pdo->prepare("SELECT guest_email, status, expires_at, created_at, re_requested_at FROM campaign_invites WHERE campaign_id = ? AND invite_source = 'external_email' ORDER BY created_at DESC LIMIT 30");
                $stmt->execute([$campaignId]);
                $externalInvites = $stmt->fetchAll();

            } catch (Throwable $e) {
                $pdo->rollBack();
                $errors[] = 'Could not resend. Please try again.';
            }
        }
    }
    // Strip ?resend from URL to prevent re-send on refresh
    redirect('/app/company/campaigns/external_invite.php?uuid=' . urlencode($companyUuid)
           . '&cid=' . urlencode($campaignUuid)
           . ($successMsg ? '&resent=1' : ''));
}

/* ── Email helper ────────────────────────────────────────────── */
/**
 * sendExternalInviteEmail()
 *
 * Sends the external investor invite email. Legally neutral —
 * no return projections or financial advice.
 */
function sendExternalInviteEmail(
    string $to,
    string $companyName,
    string $campaignTitle,
    string $claimLink,
    string $expiresAt
): bool {
    $expFormatted = date('d M Y \a\t H:i', strtotime($expiresAt));
    $subject = htmlspecialchars($companyName) . ' has invited you to a private investment opportunity';
    $body = '
<div style="font-family:\'DM Sans\',sans-serif;max-width:560px;margin:0 auto;background:#fff;border:1px solid #e4e7ec;border-radius:12px;overflow:hidden;">

    <!-- Header -->
    <div style="background:#0b2545;padding:28px 36px 24px;">
        <div style="font-family:Georgia,serif;font-size:20px;color:#fff;margin-bottom:4px;">
            Old <span style="color:#c8102e;">U</span>nion
        </div>
        <div style="font-size:11px;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.12em;">Private Investment Platform</div>
    </div>

    <!-- Body -->
    <div style="padding:32px 36px;">
        <h2 style="font-family:Georgia,serif;font-size:22px;color:#0b2545;margin:0 0 14px;font-weight:600;line-height:1.25;">
            You\'ve been invited to a private investment opportunity
        </h2>

        <p style="font-size:15px;color:#667085;line-height:1.65;margin:0 0 20px;">
            <strong style="color:#101828;">' . htmlspecialchars($companyName) . '</strong> has selected you for a private deal:
        </p>

        <div style="background:#f8f9fb;border:1px solid #e4e7ec;border-radius:8px;padding:16px 20px;margin-bottom:24px;">
            <div style="font-size:11px;color:#98a2b3;text-transform:uppercase;letter-spacing:.1em;margin-bottom:6px;">Campaign</div>
            <div style="font-size:17px;font-weight:600;color:#0b2545;font-family:Georgia,serif;">' . htmlspecialchars($campaignTitle) . '</div>
        </div>

        <p style="font-size:14px;color:#667085;line-height:1.65;margin:0 0 28px;">
            This invitation is <strong>strictly private</strong> and has been extended to a limited number of individuals
            under South African private placement regulations. To view the full campaign terms and decide whether to participate,
            you\'ll need to create a free Old Union account (or log in if you already have one).
        </p>

        <a href="' . htmlspecialchars($claimLink) . '"
           style="display:inline-block;background:#0f3b7a;color:#fff;font-size:15px;font-weight:600;
                  padding:14px 32px;border-radius:99px;text-decoration:none;letter-spacing:.01em;">
            View &amp; Claim Your Invitation →
        </a>

        <p style="font-size:12px;color:#98a2b3;margin-top:28px;line-height:1.6;">
            <strong>This link expires on ' . htmlspecialchars($expFormatted) . ' SAST.</strong><br>
            If you don\'t have an Old Union account, you\'ll be prompted to create one — it\'s free and takes under a minute.<br><br>
            If you did not expect this email or do not wish to participate, you can safely ignore it.
            Old Union is a technology facilitator only and does not provide financial advice.
            Always seek independent legal and financial guidance before investing.
        </p>
    </div>

    <!-- Footer -->
    <div style="background:#f8f9fb;border-top:1px solid #e4e7ec;padding:16px 36px;">
        <div style="font-size:11px;color:#98a2b3;">
            © Old Union · Private Investment Platform · South Africa<br>
            You received this email because a verified company founder selected your address for a private deal.
        </div>
    </div>
</div>
    ';
    return sendEmail($to, $subject, $body);
}

/* ── Status label helper ─────────────────────────────────────── */
function externalStatusBadge(string $status, string $expiresAt): string {
    $isExpired = strtotime($expiresAt) < time();
    if ($status === 'accepted') {
        return '<span class="badge badge-accepted"><i class="fa-solid fa-circle-check"></i> Accepted</span>';
    }
    if ($status === 'declined') {
        return '<span class="badge badge-declined"><i class="fa-solid fa-circle-xmark"></i> Declined</span>';
    }
    if ($status === 'revoked') {
        return '<span class="badge badge-revoked"><i class="fa-solid fa-ban"></i> Revoked</span>';
    }
    if ($status === 'pending' && $isExpired) {
        return '<span class="badge badge-expired"><i class="fa-regular fa-clock"></i> Expired</span>';
    }
    return '<span class="badge badge-pending"><i class="fa-solid fa-paper-plane"></i> Sent</span>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>External Invite — <?php echo htmlspecialchars($campaign['title']); ?> | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cormorant+Garamond:ital,wght@0,400;0,600;1,400&family=Jost:wght@300;400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    /* ─────────────────────────────────────────────────────────────
       Design direction: Refined editorial dark — navy/ink foundation
       with gold accents. Typographically led: Cormorant Garamond
       headings against Jost body. Sparse, intentional layout.
    ───────────────────────────────────────────────────────────── */
    :root {
        --ink:         #0a0f1a;
        --navy:        #0b2545;
        --navy-mid:    #0f3b7a;
        --navy-light:  #1a56b0;
        --gold:        #c8a84b;
        --gold-dim:    rgba(200,168,75,.12);
        --gold-border: rgba(200,168,75,.3);
        --surface:     #ffffff;
        --surface-2:   #f7f8fa;
        --border:      #e2e7ee;
        --text:        #101828;
        --text-muted:  #5a6a80;
        --text-light:  #96a3b4;
        --green:       #0b6b4d;
        --green-bg:    #e6f7ec;
        --green-bdr:   #a7f3d0;
        --amber:       #d97706;
        --amber-bg:    #fef3e2;
        --amber-bdr:   #fcd34d;
        --error:       #b91c1c;
        --error-bg:    #fef2f2;
        --error-bdr:   #fecaca;
        --radius:      10px;
        --radius-sm:   6px;
        --shadow:      0 2px 12px rgba(10,15,26,.07), 0 1px 3px rgba(10,15,26,.05);
        --shadow-lg:   0 8px 32px rgba(10,15,26,.12), 0 2px 6px rgba(10,15,26,.07);
        --header-h:    60px;
        --sidebar-w:   220px;
        --transition:  .2s cubic-bezier(.4,0,.2,1);
    }
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    html { font-size: 15px; -webkit-font-smoothing: antialiased; }
    body { font-family: 'Jost', sans-serif; background: var(--surface-2); color: var(--text); min-height: 100vh; }

    /* ── Header ── */
    .top-header {
        position: fixed; top: 0; left: 0; right: 0; height: var(--header-h);
        background: var(--navy); display: flex; align-items: center;
        padding: 0 1.75rem; justify-content: space-between; z-index: 100; gap: 1rem;
        border-bottom: 1px solid rgba(255,255,255,.07);
    }
    .logo { font-family: 'Cormorant Garamond', serif; font-size: 1.35rem; font-weight: 600;
            color: #fff; text-decoration: none; letter-spacing: .02em; }
    .logo span { color: #c8102e; }
    .header-crumb { font-size: .78rem; color: rgba(255,255,255,.4); display: flex; align-items: center; gap: .5rem; flex: 1; padding-left: 1.5rem; }
    .header-crumb a { color: rgba(255,255,255,.55); text-decoration: none; transition: color var(--transition); }
    .header-crumb a:hover { color: #fff; }
    .header-crumb i { font-size: .6rem; }
    .avatar { width: 34px; height: 34px; border-radius: 50%;
               background: linear-gradient(135deg, #6a11cb, #2575fc);
               display: flex; align-items: center; justify-content: center;
               font-weight: 700; color: #fff; font-size: .88rem; flex-shrink: 0; }

    /* ── Layout ── */
    .page-wrapper { padding-top: var(--header-h); display: flex; min-height: 100vh; }
    .sidebar {
        width: var(--sidebar-w); background: var(--navy); border-right: 1px solid rgba(255,255,255,.06);
        position: sticky; top: var(--header-h); height: calc(100vh - var(--header-h));
        overflow-y: auto; padding: 1.5rem .85rem; flex-shrink: 0;
    }
    .sidebar-section-label { font-size: .65rem; font-weight: 600; text-transform: uppercase;
        letter-spacing: .15em; color: rgba(255,255,255,.25); padding: 0 .6rem;
        margin-bottom: .4rem; margin-top: 1.25rem; }
    .sidebar-section-label:first-child { margin-top: 0; }
    .sidebar a { display: flex; align-items: center; gap: .55rem; padding: .5rem .65rem;
        border-radius: var(--radius-sm); font-size: .83rem; font-weight: 400; color: rgba(255,255,255,.5);
        text-decoration: none; transition: all var(--transition); margin-bottom: .05rem; }
    .sidebar a:hover { background: rgba(255,255,255,.06); color: rgba(255,255,255,.85); }
    .sidebar a.active { background: rgba(200,168,75,.15); color: var(--gold); }
    .sidebar a i { width: 15px; text-align: center; font-size: .82rem; }

    .main-content { flex: 1; padding: 2rem 2.5rem; min-width: 0; }

    /* ── Page head ── */
    .page-head { margin-bottom: 1.75rem; }
    .page-head h1 { font-family: 'Cormorant Garamond', serif; font-size: 2rem; font-weight: 600;
                    color: var(--navy); line-height: 1.15; margin-bottom: .35rem; }
    .page-head p { font-size: .88rem; color: var(--text-muted); line-height: 1.6; max-width: 600px; }

    /* ── Two-column layout ── */
    .two-col { display: grid; grid-template-columns: 440px 1fr; gap: 2rem; align-items: start; }

    /* ── Cards ── */
    .card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
            box-shadow: var(--shadow); overflow: hidden; margin-bottom: 1.25rem; }
    .card:last-child { margin-bottom: 0; }
    .card-header { display: flex; align-items: center; justify-content: space-between;
                   padding: .9rem 1.25rem; border-bottom: 1px solid var(--border); background: var(--surface-2); }
    .card-title { font-size: .75rem; font-weight: 600; text-transform: uppercase;
                  letter-spacing: .1em; color: var(--text-light); display: flex; align-items: center; gap: .4rem; }
    .card-title i { color: var(--navy-light); }
    .card-body { padding: 1.25rem; }

    /* ── Slot meter ── */
    .slot-meter { display: flex; align-items: center; gap: 1.25rem; margin-bottom: 1.1rem; flex-wrap: wrap; }
    .slot-bar-wrap { flex: 1; min-width: 140px; }
    .slot-bar-label { display: flex; justify-content: space-between; font-size: .73rem;
                      color: var(--text-light); margin-bottom: .3rem; }
    .slot-bar { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; }
    .slot-bar-fill { height: 100%; border-radius: 99px; transition: width .4s ease; }
    .fill-green { background: var(--green); }
    .fill-amber { background: var(--amber); }
    .fill-red   { background: var(--error); }
    .slot-count { font-size: .78rem; color: var(--text-muted); flex-shrink: 0; text-align: right; }
    .slot-count strong { color: var(--navy); font-size: .95rem; }

    /* ── Form ── */
    .field { display: flex; flex-direction: column; gap: .4rem; margin-bottom: 1.1rem; }
    .field:last-child { margin-bottom: 0; }
    .field label { font-size: .78rem; font-weight: 600; text-transform: uppercase;
                   letter-spacing: .08em; color: var(--text-muted); }
    .field input { width: 100%; padding: .72rem 1rem; border: 1.5px solid var(--border);
                   border-radius: var(--radius-sm); font-family: 'Jost', sans-serif; font-size: .95rem;
                   color: var(--text); background: var(--surface-2); outline: none;
                   transition: border-color var(--transition), box-shadow var(--transition), background var(--transition); }
    .field input:focus { border-color: var(--navy-light); background: #fff;
                         box-shadow: 0 0 0 3px rgba(26,86,176,.1); }
    .field .hint { font-size: .74rem; color: var(--text-light); line-height: 1.45; }

    /* ── Alerts ── */
    .alert { display: flex; align-items: flex-start; gap: .65rem; padding: .85rem 1rem;
             border-radius: var(--radius-sm); margin-bottom: 1.1rem; font-size: .86rem;
             font-weight: 500; border: 1px solid transparent; line-height: 1.5; }
    .alert i { flex-shrink: 0; margin-top: .05rem; }
    .alert-success { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .alert-error   { background: var(--error-bg); color: var(--error); border-color: var(--error-bdr); }
    .alert-warn    { background: var(--amber-bg); color: var(--amber); border-color: var(--amber-bdr); }
    .alert a { color: inherit; font-weight: 700; }

    /* ── Process steps (visual diagram) ── */
    .process-steps { display: flex; flex-direction: column; gap: 0; }
    .process-step { display: flex; gap: .9rem; padding-bottom: 1.25rem; position: relative; }
    .process-step:not(:last-child)::before { content: ''; position: absolute;
        left: 17px; top: 36px; bottom: 0; width: 1px; background: var(--border); }
    .step-dot { width: 36px; height: 36px; border-radius: 50%; flex-shrink: 0;
                display: flex; align-items: center; justify-content: center;
                font-size: .82rem; font-weight: 600; position: relative; z-index: 1; }
    .dot-navy  { background: var(--navy); color: var(--gold); }
    .dot-mid   { background: #eff4ff; color: var(--navy-mid); border: 1.5px solid #c7d9f8; }
    .dot-green { background: var(--green-bg); color: var(--green); border: 1.5px solid var(--green-bdr); }
    .step-body { flex: 1; padding-top: .35rem; }
    .step-body-title { font-size: .88rem; font-weight: 600; color: var(--text); margin-bottom: .2rem; }
    .step-body-desc  { font-size: .8rem; color: var(--text-muted); line-height: 1.55; }

    /* ── Sent invites table ── */
    .table-wrap { overflow-x: auto; }
    table { width: 100%; border-collapse: collapse; font-size: .83rem; }
    th { text-align: left; padding: .55rem .85rem; font-size: .7rem; font-weight: 600;
         text-transform: uppercase; letter-spacing: .09em; color: var(--text-light);
         border-bottom: 2px solid var(--border); white-space: nowrap; }
    td { padding: .65rem .85rem; border-bottom: 1px solid var(--border); vertical-align: middle; color: var(--text); }
    tr:last-child td { border-bottom: none; }
    tr:hover td { background: var(--surface-2); }
    .email-cell { font-weight: 500; color: var(--navy); }
    .date-cell  { font-size: .78rem; color: var(--text-light); white-space: nowrap; }
    .expiry-cell { font-size: .78rem; color: var(--text-muted); white-space: nowrap; }
    .expiry-cell.is-expired { color: var(--error); }

    /* ── Badges ── */
    .badge { display: inline-flex; align-items: center; gap: .28rem; padding: .18rem .6rem;
             border-radius: 99px; font-size: .72rem; font-weight: 600; border: 1px solid transparent;
             white-space: nowrap; }
    .badge-pending  { background: #eff4ff; color: var(--navy-mid); border-color: #c7d9f8; }
    .badge-accepted { background: var(--green-bg); color: var(--green); border-color: var(--green-bdr); }
    .badge-declined { background: var(--error-bg); color: var(--error); border-color: var(--error-bdr); }
    .badge-expired  { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }
    .badge-revoked  { background: #f1f5f9; color: #64748b; border-color: #cbd5e1; }

    /* ── Buttons ── */
    .btn { display: inline-flex; align-items: center; gap: .4rem; padding: .62rem 1.3rem;
           border-radius: 99px; font-family: 'Jost', sans-serif; font-size: .88rem;
           font-weight: 500; cursor: pointer; transition: all var(--transition); border: none;
           text-decoration: none; letter-spacing: .01em; }
    .btn-navy   { background: var(--navy-mid); color: #fff; }
    .btn-navy:hover   { background: var(--navy); transform: translateY(-1px); }
    .btn-ghost  { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border); }
    .btn-ghost:hover  { border-color: var(--navy-light); color: var(--navy-mid); }
    .btn-gold   { background: var(--gold); color: var(--navy); font-weight: 600; }
    .btn-gold:hover   { background: #b8962e; transform: translateY(-1px); }
    .btn-xs     { padding: .28rem .7rem; font-size: .75rem; }
    .btn:disabled { opacity: .45; cursor: not-allowed; transform: none !important; }

    /* ── Empty state ── */
    .empty-state { padding: 2.5rem; text-align: center; color: var(--text-light); }
    .empty-state i { font-size: 2rem; display: block; margin-bottom: .65rem; opacity: .3; }
    .empty-state p { font-size: .85rem; }

    /* ── Legal note ── */
    .legal-strip { background: var(--navy); border-radius: var(--radius-sm); padding: .85rem 1.1rem;
                   display: flex; align-items: flex-start; gap: .65rem; font-size: .78rem;
                   color: rgba(255,255,255,.5); line-height: 1.55; margin-bottom: 1.25rem; }
    .legal-strip i { color: var(--gold); flex-shrink: 0; margin-top: .1rem; }
    .legal-strip strong { color: rgba(255,255,255,.8); }

    /* ── Responsive ── */
    @media(max-width:1100px){ .two-col { grid-template-columns: 1fr; } }
    @media(max-width:900px)  { .sidebar { display: none; } .main-content { padding: 1.5rem; } }
    @media(max-width:600px)  { .main-content { padding: 1rem; }
        th:nth-child(3), td:nth-child(3),
        th:nth-child(4), td:nth-child(4) { display: none; } }
    ::-webkit-scrollbar { width: 4px; }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
    </style>
</head>
<body>

<header class="top-header">
    <a href="/app/company/" class="logo">Old <span>U</span>nion</a>
    <div class="header-crumb">
        <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>"><?php echo htmlspecialchars($company['name']); ?></a>
        <i class="fa-solid fa-chevron-right"></i>
        <a href="/app/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>">Campaigns</a>
        <i class="fa-solid fa-chevron-right"></i>
        <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"><?php echo htmlspecialchars($campaign['title']); ?></a>
        <i class="fa-solid fa-chevron-right"></i>
        External Invite
    </div>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-gauge"></i> Overview
        </a>
        <div class="sidebar-section-label">Fundraising</div>
        <a href="/app/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-rocket"></i> Campaigns
        </a>
        <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-sliders"></i> Manage Campaign
        </a>
        <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-users"></i> Investor Directory
        </a>
        <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>" class="active">
            <i class="fa-solid fa-envelope-open-text"></i> External Invite
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">

        <div class="page-head">
            <h1>Invite by Email</h1>
            <p>Reach investors from your own network who don't have an Old Union account yet. They'll receive a private link, create an account, and their invitation will be auto-claimed on email verification.</p>
        </div>

        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?php echo $successMsg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($warnMsg)): ?>
            <div class="alert alert-warn">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div><?php echo $warnMsg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div><?php echo htmlspecialchars($errors[0]); ?></div>
            </div>
        <?php endif; ?>

        <div class="two-col">

            <!-- LEFT: Send invite form -->
            <div>

                <!-- Slot meter -->
                <?php
                $slotPct   = min(100, round($slotsIssued / max(1,$campaign['max_contributors']) * 100));
                $fillClass = $slotsAvailable > 10 ? 'fill-green' : ($slotsAvailable > 0 ? 'fill-amber' : 'fill-red');
                ?>
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-users"></i> Slot Status — <?php echo htmlspecialchars($campaign['title']); ?></div>
                    </div>
                    <div class="card-body">
                        <div class="slot-meter">
                            <div class="slot-bar-wrap">
                                <div class="slot-bar-label">
                                    <span>Slots Issued</span>
                                    <span><?php echo $slotsIssued; ?> / <?php echo (int)$campaign['max_contributors']; ?></span>
                                </div>
                                <div class="slot-bar">
                                    <div class="slot-bar-fill <?php echo $fillClass; ?>" style="width:<?php echo $slotPct; ?>%"></div>
                                </div>
                            </div>
                            <div class="slot-count">
                                <strong><?php echo $slotsAvailable; ?></strong><br>remaining
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Invite form -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-envelope-open-text"></i> Send External Invitation</div>
                    </div>
                    <div class="card-body">
                        <div class="legal-strip">
                            <i class="fa-solid fa-shield-halved"></i>
                            <div>
                                <strong>This form is for people who don't have an Old Union account.</strong>
                                If the person is already registered, use the
                                <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>" style="color:var(--gold);font-weight:600;">Investor Directory</a> instead.
                                Each email sends a private, signed link — never forward or share this link with others.
                            </div>
                        </div>

                        <form method="POST" id="inviteForm">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                            <div class="field">
                                <label for="guest_email">Email Address</label>
                                <input type="email" id="guest_email" name="guest_email"
                                    placeholder="investor@example.com"
                                    value="<?php echo htmlspecialchars($_POST['guest_email'] ?? ''); ?>"
                                    autocomplete="email"
                                    required>
                                <span class="hint">
                                    A private link will be emailed to this address. It expires in 72 hours and cannot be reused.
                                </span>
                            </div>

                            <div style="display:flex;gap:.65rem;flex-wrap:wrap;margin-top:1.1rem;">
                                <button type="submit" class="btn btn-gold"
                                    <?php echo $slotsAvailable <= 0 ? 'disabled' : ''; ?>>
                                    <i class="fa-solid fa-paper-plane"></i>
                                    <?php echo $slotsAvailable > 0 ? 'Send Invitation' : 'No Slots Remaining'; ?>
                                </button>
                                <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
                                   class="btn btn-ghost">Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>

            </div>

            <!-- RIGHT: How it works + sent invites -->
            <div>

                <!-- How it works -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-circle-info"></i> How It Works</div>
                    </div>
                    <div class="card-body">
                        <div class="process-steps">
                            <div class="process-step">
                                <div class="step-dot dot-navy">1</div>
                                <div class="step-body">
                                    <div class="step-body-title">You send an invitation</div>
                                    <div class="step-body-desc">Enter an email address above. A signed, time-limited link (72 hours) is emailed to them instantly. A compliance log entry is created.</div>
                                </div>
                            </div>
                            <div class="process-step">
                                <div class="step-dot dot-mid">2</div>
                                <div class="step-body">
                                    <div class="step-body-title">They click the link</div>
                                    <div class="step-body-desc">If they already have an account they are taken straight to the risk disclosure &amp; accept flow. If not, they're prompted to register — free, under a minute.</div>
                                </div>
                            </div>
                            <div class="process-step">
                                <div class="step-dot dot-mid">3</div>
                                <div class="step-body">
                                    <div class="step-body-title">Registration &amp; email verification</div>
                                    <div class="step-body-desc">They create an account with the invited email address and verify it. The platform automatically links their new account to their pending invitation.</div>
                                </div>
                            </div>
                            <div class="process-step">
                                <div class="step-dot dot-green"><i class="fa-solid fa-check" style="font-size:.75rem;"></i></div>
                                <div class="step-body">
                                    <div class="step-body-title">Invitation auto-claimed</div>
                                    <div class="step-body-desc">On email verification, the invitation is linked to their account. They are redirected to review campaign terms and formally accept or decline.</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Sent external invites table -->
                <div class="card">
                    <div class="card-header">
                        <div class="card-title"><i class="fa-solid fa-list-check"></i> External Invitations Sent</div>
                        <span style="font-size:.75rem;color:var(--text-light);"><?php echo count($externalInvites); ?> total</span>
                    </div>
                    <?php if (empty($externalInvites)): ?>
                        <div class="empty-state">
                            <i class="fa-solid fa-envelope"></i>
                            <p>No external invitations sent yet for this campaign.</p>
                        </div>
                    <?php else: ?>
                    <div class="table-wrap">
                        <table>
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Expires</th>
                                    <th>Sent</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($externalInvites as $ei):
                                    $isExpired  = strtotime($ei['expires_at']) < time();
                                    $canResend  = $ei['status'] !== 'accepted';
                                ?>
                                <tr>
                                    <td class="email-cell"><?php echo htmlspecialchars($ei['guest_email']); ?></td>
                                    <td><?php echo externalStatusBadge($ei['status'], $ei['expires_at']); ?></td>
                                    <td class="expiry-cell <?php echo $isExpired ? 'is-expired' : ''; ?>">
                                        <?php echo $isExpired
                                            ? '<i class="fa-solid fa-triangle-exclamation" style="font-size:.72rem;"></i> '
                                            : ''; ?>
                                        <?php echo date('d M · H:i', strtotime($ei['expires_at'])); ?>
                                    </td>
                                    <td class="date-cell"><?php echo date('d M Y', strtotime($ei['created_at'])); ?></td>
                                    <td>
                                        <?php if ($canResend && $slotsAvailable > 0): ?>
                                            <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&resend=<?php echo urlencode($ei['guest_email']); ?>"
                                               class="btn btn-ghost btn-xs"
                                               onclick="return confirm('Resend fresh 72-hour link to <?php echo htmlspecialchars(addslashes($ei['guest_email'])); ?>?')">
                                                <i class="fa-solid fa-rotate-right"></i> Resend
                                            </a>
                                        <?php elseif ($ei['status'] === 'accepted'): ?>
                                            <span style="font-size:.74rem;color:var(--green);"><i class="fa-solid fa-circle-check"></i></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </main>
</div>

</body>
</html>
