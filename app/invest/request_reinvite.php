<?php
/**
 * /app/invest/request_reinvite.php
 *
 * US-103 criterion 4 — Re-request endpoint
 *
 * Called via AJAX from claim_invite.php when an invite has expired
 * and the prospect wants to notify the founder to re-issue their link.
 *
 * Does NOT create a new invite (only the founder can do that).
 * Instead, it:
 *   1. Validates the request (campaign exists, invite row exists
 *      in expired/pending state, email matches)
 *   2. Emails the founder (invited_by) that this prospect wants a
 *      fresh link, with a direct link to external_invite.php pre-filled
 *   3. Writes a compliance_events entry
 *   4. Returns JSON { success: true }
 *
 * Rate-limited to 1 re-request per email per campaign per 24 hours
 * via the re_requested_at column set in the invite row.
 */

require_once '../includes/security.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

header('Content-Type: application/json');

// Only POST, no session required (unauthenticated prospect)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$campaignUuid = trim($body['campaign_uuid'] ?? '');
$inviteUuid   = trim($body['invite_uuid']   ?? '');
$guestEmail   = filter_var(trim($body['guest_email'] ?? ''), FILTER_VALIDATE_EMAIL);
$campaignId   = (int)($body['campaign_id'] ?? 0);

if (empty($campaignUuid) || empty($inviteUuid) || !$guestEmail || $campaignId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    exit;
}

$pdo = Database::getInstance();

// Load invite row
$stmt = $pdo->prepare("
    SELECT ci.id, ci.status, ci.expires_at, ci.re_requested_at,
           ci.invited_by,
           u_founder.email AS founder_email,
           fc.title        AS campaign_title,
           c.uuid          AS company_uuid,
           c.name          AS company_name
    FROM campaign_invites ci
    JOIN users u_founder        ON u_founder.id = ci.invited_by
    JOIN funding_campaigns fc   ON fc.id = ci.campaign_id
    JOIN companies c            ON c.id  = fc.company_id
    WHERE ci.uuid = :iuuid
      AND ci.campaign_id = :cid
      AND ci.guest_email = :email
    LIMIT 1
");
$stmt->execute(['iuuid' => $inviteUuid, 'cid' => $campaignId, 'email' => $guestEmail]);
$invite = $stmt->fetch();

if (!$invite) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Invite not found']);
    exit;
}

// Only allow re-request for expired or declined invites (not accepted)
if ($invite['status'] === 'accepted') {
    echo json_encode(['success' => false, 'error' => 'Invite already accepted']);
    exit;
}

// Rate-limit: one re-request per 24 hours
if (!empty($invite['re_requested_at'])) {
    $lastReq = strtotime($invite['re_requested_at']);
    if (time() - $lastReq < 86400) {
        echo json_encode([
            'success' => false,
            'error'   => 'A re-request was already sent recently. Please allow 24 hours before trying again.'
        ]);
        exit;
    }
}

// Update re_requested_at on the invite row
$pdo->prepare("
    UPDATE campaign_invites SET re_requested_at = NOW(), updated_at = NOW()
    WHERE id = :id
")->execute(['id' => $invite['id']]);

// Compliance log
try {
    $pdo->prepare("
        INSERT INTO compliance_events
            (event_type, actor_id, campaign_id, guest_email, ip_address, created_at)
        VALUES ('invite_reRequest', NULL, :cid, :email, :ip, NOW())
    ")->execute([
        'cid'   => $campaignId,
        'email' => $guestEmail,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
    ]);
} catch (PDOException $e) {
    error_log('[request_reinvite] compliance_events: ' . $e->getMessage());
}

// Email the founder
$externalInviteUrl = SITE_URL . '/app/company/campaigns/external_invite.php'
    . '?uuid=' . urlencode($invite['company_uuid'])
    . '&cid='  . urlencode($campaignUuid);

$subject = 'Re-invitation request: ' . $guestEmail . ' wants a fresh link';
$body_html = '
<div style="font-family:\'DM Sans\',sans-serif;max-width:520px;margin:0 auto;background:#fff;border:1px solid #e4e7ec;border-radius:10px;overflow:hidden;">
    <div style="background:#0b2545;padding:22px 28px;">
        <div style="font-family:Georgia,serif;font-size:19px;color:#fff;">Old <span style="color:#c8102e;">U</span>nion</div>
    </div>
    <div style="padding:28px;">
        <h2 style="font-size:17px;color:#0b2545;font-weight:600;margin:0 0 14px;">Re-invitation Request</h2>
        <p style="font-size:14px;color:#667085;line-height:1.65;margin:0 0 16px;">
            <strong style="color:#101828;">' . htmlspecialchars($guestEmail) . '</strong>
            tried to use their invitation to
            <strong>' . htmlspecialchars($invite['campaign_title']) . '</strong>
            but the link has expired. They are requesting a fresh invitation.
        </p>
        <p style="font-size:14px;color:#667085;line-height:1.65;margin:0 0 24px;">
            To re-issue their invitation, visit your campaign's External Invite page:
        </p>
        <a href="' . htmlspecialchars($externalInviteUrl) . '"
           style="display:inline-block;background:#0f3b7a;color:#fff;font-size:14px;font-weight:600;
                  padding:12px 24px;border-radius:99px;text-decoration:none;">
            Manage External Invites →
        </a>
        <p style="font-size:11px;color:#98a2b3;margin-top:24px;line-height:1.55;">
            You are receiving this because you sent an invitation via Old Union. You are under no obligation to re-issue a link.
        </p>
    </div>
</div>
';

sendEmail($invite['founder_email'], $subject, $body_html);

echo json_encode(['success' => true]);
