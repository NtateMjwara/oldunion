<?php
/**
 * PHPMailer configuration and sending function.
 */
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/../vendor/PHPMailer-master/PHPMailer-master/src/Exception.php';
require_once __DIR__ . '/../vendor/PHPMailer-master/PHPMailer-master/src/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer-master/PHPMailer-master/src/SMTP.php';

function sendEmail($to, $subject, $body) {
    $mail = new PHPMailer(true);
    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(SMTP_FROM, SMTP_FROM_NAME);
        $mail->addAddress($to);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $body;

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mailer Error: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * sendCampaignInviteEmail()                              US-102 / criterion 4
 *
 * Sends a private placement invitation email to a prospective investor.
 * Template is legally neutral — no financial projections or return estimates.
 *
 * @param string $to            Investor email address
 * @param string $companyName   Display name of the issuing company
 * @param string $campaignTitle Campaign title (headline only — no terms)
 * @param string $inviteLink    Absolute URL to accept_invite.php?token=...
 * @param string $expiresAt     MySQL datetime string (Y-m-d H:i:s) for expiry
 * @return bool
 */
function sendCampaignInviteEmail(
    string $to,
    string $companyName,
    string $campaignTitle,
    string $inviteLink,
    string $expiresAt
): bool {
    $expFormatted = date('d M Y \a\t H:i', strtotime($expiresAt));
    $subject = "You've been invited to a private investment opportunity";
    $body    = '
    <div style="font-family:\'DM Sans\',sans-serif;max-width:520px;margin:0 auto;background:#fff;
                padding:40px 32px;border:1px solid #e4e7ec;border-radius:10px;">
        <div style="font-family:Georgia,serif;font-size:22px;color:#0b2545;margin-bottom:24px;">
            Old <span style="color:#c8102e;">U</span>nion
        </div>
        <h2 style="font-size:18px;color:#101828;font-weight:600;margin-bottom:12px;">
            Private Investment Invitation
        </h2>
        <p style="color:#667085;font-size:15px;line-height:1.6;margin-bottom:20px;">
            <strong style="color:#101828;">' . htmlspecialchars($companyName) . '</strong>
            has invited you to view and participate in a private investment opportunity:
            <em>' . htmlspecialchars($campaignTitle) . '</em>.
        </p>
        <p style="color:#667085;font-size:14px;line-height:1.6;margin-bottom:28px;">
            This invitation is strictly private and has been extended to a limited number of
            individuals under South African private placement regulations. By accepting, you
            will be able to view the full campaign terms.
        </p>
        <a href="' . htmlspecialchars($inviteLink) . '"
           style="display:inline-block;background:#0f3b7a;color:#fff;font-size:15px;font-weight:600;
                  padding:13px 28px;border-radius:99px;text-decoration:none;margin-bottom:20px;">
            View Investment Opportunity →
        </a>
        <p style="font-size:12px;color:#98a2b3;margin-top:20px;">
            This invitation expires on ' . htmlspecialchars($expFormatted) . '.<br>
            If you did not expect this invitation or do not wish to participate, you may safely
            ignore this email. Old Union is a facilitator only and does not provide financial advice.
        </p>
    </div>
    ';
    return sendEmail($to, $subject, $body);
}
/**
 * sendInviteExpiredNotification()                        US-103 / criterion 4
 *
 * Notifies the original founder (invited_by) that a prospective investor
 * tried to use their expired invite link and is requesting a fresh one.
 * Called by request_reinvite.php after the rate-limit check passes.
 *
 * This does NOT auto-generate a new invite — that control stays with the
 * founder via external_invite.php. The email contains a direct link to
 * the External Invites management page pre-scoped to the right company +
 * campaign so the founder can re-issue in one click.
 *
 * @param string $founderEmail   Email address of the original invite sender
 * @param string $guestEmail     Email of the prospect requesting a fresh link
 * @param string $campaignTitle  Campaign name for context
 * @param string $externalInviteUrl  Absolute URL to external_invite.php
 * @return bool
 */
function sendInviteExpiredNotification(
    string $founderEmail,
    string $guestEmail,
    string $campaignTitle,
    string $externalInviteUrl
): bool {
    $subject = 'Re-invitation request: ' . $guestEmail . ' wants a fresh link';
    $body = '
<div style="font-family:\'DM Sans\',sans-serif;max-width:520px;margin:0 auto;background:#fff;
            border:1px solid #e4e7ec;border-radius:10px;overflow:hidden;">
    <div style="background:#0b2545;padding:22px 28px;">
        <div style="font-family:Georgia,serif;font-size:19px;color:#fff;">
            Old <span style="color:#c8102e;">U</span>nion
        </div>
    </div>
    <div style="padding:28px 28px 24px;">
        <h2 style="font-size:17px;color:#0b2545;font-weight:600;margin:0 0 14px;">
            Re-invitation Request
        </h2>
        <p style="font-size:14px;color:#667085;line-height:1.65;margin:0 0 16px;">
            <strong style="color:#101828;">' . htmlspecialchars($guestEmail) . '</strong>
            tried to use their invitation to
            <strong style="color:#101828;">' . htmlspecialchars($campaignTitle) . '</strong>
            but the link has expired. They are requesting a fresh invitation.
        </p>
        <p style="font-size:14px;color:#667085;line-height:1.65;margin:0 0 24px;">
            To re-issue their invitation, visit your campaign\'s External Invites page.
            You are under no obligation to send a new link.
        </p>
        <a href="' . htmlspecialchars($externalInviteUrl) . '"
           style="display:inline-block;background:#0f3b7a;color:#fff;font-size:14px;
                  font-weight:600;padding:12px 24px;border-radius:99px;text-decoration:none;">
            Manage External Invites →
        </a>
        <p style="font-size:11px;color:#98a2b3;margin-top:24px;line-height:1.55;">
            You received this because you sent an invitation via Old Union.
            Old Union is a technology facilitator only and does not provide financial advice.
        </p>
    </div>
</div>
    ';
    return sendEmail($founderEmail, $subject, $body);
}
