<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/database.php';
require_once '../includes/functions.php'; // includes us103_claimExternalInvite()

$token = $_GET['token'] ?? '';
if (empty($token)) {
    redirect('/app/auth/login.php?error=Invalid verification link');
}

$pdo = Database::getInstance();

$stmt = $pdo->prepare("
    SELECT id, email
    FROM users
    WHERE verification_token = :token
      AND email_verified = 0
");
$stmt->execute(['token' => $token]);
$user = $stmt->fetch();

if (!$user) {
    redirect('/app/auth/login.php?error=Invalid or expired verification token');
}

// Mark email as verified
$pdo->prepare("
    UPDATE users
    SET email_verified = 1, verification_token = NULL
    WHERE id = :id
")->execute(['id' => $user['id']]);

// ── US-103: Claim any pending external invite.
//
// us103_claimExternalInvite() matches the invite by session hex token or
// guest_email, backfills user_id on the campaign_invites row, writes a
// compliance_events entry, and returns the invite's UUID on success.
//
// IMPORTANT: the user is NOT authenticated at this point — verify.php is
// opened from a link in an email, often in a fresh browser tab. We must
// NOT redirect directly to accept_invite.php because accept_invite.php
// requires an authenticated session and will immediately bounce the user
// back to login.php — but at that point it writes the invite UUID (not
// the hex token) into $_SESSION['pending_invite_token'], causing
// claim_invite.php to fail with "Invalid invitation link" when
// authentication.php routes the UUID there after login.
//
// The correct flow for new users:
//   verify.php  →  login.php?verified=1&redirect=/app/invest/accept_invite.php?token=<uuid>
//   login.php   →  authentication.php (carries redirect_after_login in POST)
//   authentication.php  →  /app/invest/accept_invite.php?token=<uuid>  (Scenario A)
//
// This keeps accept_invite.php as the destination while ensuring the user
// is fully authenticated before they arrive there.

$inviteUuid = us103_claimExternalInvite($pdo, (int)$user['id'], $user['email']);

if ($inviteUuid) {
    // Build the destination URL the user should reach after logging in
    $acceptUrl = '/app/invest/accept_invite.php?token=' . urlencode($inviteUuid);

    // Send them to login first (they are not authenticated), carrying the
    // destination as ?redirect= so login.php passes it through the POST
    // to authentication.php, which honours it as Scenario A.
    redirect(
        '/app/auth/login.php?verified=1&redirect=' . urlencode($acceptUrl)
    );
} else {
    // No invite to claim — standard post-verification login flow
    redirect('/app/auth/login.php?verified=1');
}
