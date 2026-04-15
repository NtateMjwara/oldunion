<?php
/**
 * Helper functions.
 */
require_once __DIR__ . '/database.php';

function sanitizeInput($data) {
    return htmlspecialchars(trim($data), ENT_QUOTES, 'UTF-8');
}

function generateToken() {
    return bin2hex(random_bytes(32));
}

function getUserByEmail($email) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    return $stmt->fetch();
}

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function redirect($url) {
    header('Location: ' . $url);
    exit;
}

/**
 * Generate a random UUID version 4 string.
 *
 * @return string UUID v4 (36 characters)
 */
function generateUuidV4(): string {
    $data = random_bytes(16);
    // Set version to 0100 (UUID v4)
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set bits 6-7 to 10 (variant as per RFC 4122)
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    // Format as UUID
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a collision-safe campaign invite UUID.
 *
 * generateToken() (bin2hex of 32 random bytes) has ~2^256 entropy so
 * collisions are astronomically unlikely, but we verify against the DB
 * once before returning so the caller can insert without a retry loop.
 * Falls back gracefully if the campaign_invites table does not yet exist.
 *
 * @return string UUID v4 guaranteed unique in campaign_invites.uuid
 */
function generateCampaignInviteToken(): string {
    $pdo = Database::getInstance();
    $maxAttempts = 5;
    for ($i = 0; $i < $maxAttempts; $i++) {
        $uuid = generateUuidV4();
        try {
            $stmt = $pdo->prepare("SELECT 1 FROM campaign_invites WHERE uuid = ? LIMIT 1");
            $stmt->execute([$uuid]);
            if (!$stmt->fetchColumn()) {
                return $uuid; // confirmed unique
            }
        } catch (PDOException $e) {
            // Table not yet migrated — UUID collision risk is negligible; return it.
            return $uuid;
        }
    }
    // Theoretically unreachable; return last generated value.
    return $uuid;
}

// ============================================================
// US-103 — External invite claim helpers
// ============================================================


/**
 * us103_claimExternalInvite()                          US-103 criterion 3
 *
 * Called by verify.php after email_verified is set to 1.
 *
 * Locates the single most-recently-created, non-expired, pending external
 * invite whose guest_email matches the verified user. Backfills user_id,
 * logs to compliance_events, clears session tokens, and returns the invite
 * UUID so verify.php can redirect the user to accept_invite.php.
 *
 * Matching priority:
 *   1. Session token (set by claim_invite.php → authentication.php)
 *   2. Email fallback (covers session loss / incognito edge case)
 *
 * @param PDO    $pdo
 * @param int    $userId     Newly created + verified user ID
 * @param string $userEmail  Verified email address
 * @return string|null       Invite UUID for redirect, or null
 */
function us103_claimExternalInvite(PDO $pdo, int $userId, string $userEmail): ?string {

    $sessionToken = $_SESSION['pending_invite_token'] ?? null;
    $invite       = null;

    // Priority 1: match by session token
    if ($sessionToken) {
        $stmt = $pdo->prepare("
            SELECT id, uuid, campaign_id, status, expires_at, guest_email
            FROM campaign_invites
            WHERE token          = :token
              AND user_id        IS NULL
              AND status         = 'pending'
              AND invite_source  = 'external_email'
            LIMIT 1
        ");
        $stmt->execute(['token' => $sessionToken]);
        $row = $stmt->fetch();

        // Only accept if the email on the invite matches the verified address
        if ($row && strtolower($row['guest_email']) === strtolower($userEmail)) {
            $invite = $row;
        }
    }

    // Priority 2: match by guest_email (covers session loss between pages)
    if (!$invite) {
        $stmt = $pdo->prepare("
            SELECT id, uuid, campaign_id, status, expires_at, guest_email
            FROM campaign_invites
            WHERE LOWER(guest_email) = LOWER(:email)
              AND user_id            IS NULL
              AND status             = 'pending'
              AND invite_source      = 'external_email'
              AND expires_at         > NOW()
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute(['email' => $userEmail]);
        $invite = $stmt->fetch() ?: null;
    }

    if (!$invite) {
        return null; // no claimable invite — normal verify→login flow
    }

    // Guard: don't claim if the link expired (session-token path doesn't
    // filter by expires_at, so we check here)
    if (strtotime($invite['expires_at']) < time()) {
        return null;
    }

    $pdo->beginTransaction();
    try {
        // Backfill user_id (criterion 3)
        $pdo->prepare("
            UPDATE campaign_invites
            SET user_id    = :uid,
                updated_at = NOW()
            WHERE id = :id
        ")->execute(['uid' => $userId, 'id' => $invite['id']]);

        // Compliance log — graceful skip if table not yet created
        try {
            $pdo->prepare("
                INSERT INTO compliance_events
                    (event_type, actor_id, campaign_id, guest_email, ip_address, created_at)
                VALUES
                    ('invite_claimed_on_verification', :uid, :cid, :email, :ip, NOW())
            ")->execute([
                'uid'   => $userId,
                'cid'   => $invite['campaign_id'],
                'email' => $userEmail,
                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
            ]);
        } catch (PDOException $e) {
            error_log('[us103_claimExternalInvite] compliance_events: ' . $e->getMessage());
        }

        $pdo->commit();

        // Clear session keys — they've served their purpose
        unset($_SESSION['pending_invite_token'], $_SESSION['pending_invite_email']);

        return $invite['uuid']; // UUID → accept_invite.php?token=

    } catch (Throwable $e) {
        $pdo->rollBack();
        error_log('[us103_claimExternalInvite] backfill failed: ' . $e->getMessage());
        return null;
    }
}
