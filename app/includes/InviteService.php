<?php
// ============================================================
// InviteService.php — invite gate enforcement (US-101)
//
// Single source of truth for every invite-status check in the
// application. All UI pages and service methods call through
// here so the logic lives in exactly one place.
//
// Depends on: Database singleton (database.php)
// ============================================================

require_once __DIR__ . '/database.php';

class InviteService
{
    // ----------------------------------------------------------
    // SINGLE-CAMPAIGN CHECK
    // Returns true only when the user holds an *accepted* invite
    // for the given campaign. Pending, declined, and revoked
    // invites all return false — they must re-accept.
    // ----------------------------------------------------------
    public static function hasAccepted(int $userId, int $campaignId): bool
    {
        $stmt = Database::getInstance()->prepare("
            SELECT 1 FROM campaign_invites
            WHERE campaign_id = :cid
              AND user_id     = :uid
              AND status      = 'accepted'
            LIMIT 1
        ");
        $stmt->execute(['cid' => $campaignId, 'uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    // ----------------------------------------------------------
    // BATCH CHECK
    // Given an array of campaign IDs, returns the subset for
    // which this user holds an accepted invite.
    //
    // Used by company.php and index.php to hide/show CTAs for
    // multiple campaigns in a single DB round-trip.
    //
    // @param  int[] $campaignIds
    // @return int[]
    // ----------------------------------------------------------
    public static function acceptedCampaignIds(int $userId, array $campaignIds): array
    {
        if (empty($campaignIds)) {
            return [];
        }

        // Safe: values are already cast to int above
        $placeholders = implode(',', array_fill(0, count($campaignIds), '?'));
        $stmt = Database::getInstance()->prepare("
            SELECT campaign_id
            FROM   campaign_invites
            WHERE  user_id     = ?
              AND  status      = 'accepted'
              AND  campaign_id IN ($placeholders)
        ");
        $stmt->execute(array_merge([$userId], array_map('intval', $campaignIds)));
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    // ----------------------------------------------------------
    // GATE ASSERTION (throws)
    // Throws RuntimeException if the user does not hold an
    // accepted invite. Used inside InvestmentService as the
    // server-side enforcement layer.
    // ----------------------------------------------------------
    public static function assertAccepted(int $userId, int $campaignId): void
    {
        if (!self::hasAccepted($userId, $campaignId)) {
            throw new RuntimeException(
                'You have not been invited to invest in this campaign.'
            );
        }
    }
}
