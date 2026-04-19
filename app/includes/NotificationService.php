<?php
// ============================================================
// NotificationService.php — US-703 supporting service
//
// Team A — canonical notification INSERT helper.
//
// WHY THIS EXISTS:
//   Team D's index.php defines NOTIFICATION_TYPES with the
//   confirmed type strings. Teams B, C, and D all need to INSERT
//   notifications at different points in their flows. Without a
//   shared service, each team writes their own raw INSERT with
//   the risk of:
//     - wrong column names (the 'link' → 'link_url' rename)
//     - wrong type strings diverging from NOTIFICATION_TYPES
//     - missing user ownership validation
//
//   This service is the single authoritative INSERT path.
//   All teams call it. Never write a raw INSERT INTO notifications.
//
// COLUMN CONTRACT (post migration 006):
//   user_id, type VARCHAR(60), title VARCHAR(255), body TEXT,
//   link_url VARCHAR(500), is_read TINYINT(1) DEFAULT 0,
//   created_at DATETIME, archived_at DATETIME NULL,
//   meta_json JSON NULL
//
// USAGE EXAMPLES:
//
//   // Team B — accept_invite.php (after invite accepted):
//   NotificationService::send($pdo, $userId, 'deal_invite',
//       'Invitation accepted: ' . $campaign['title'],
//       'You have accepted the invitation from ' . $company['name'] . '.',
//       '/app/invest/campaign.php?cid=' . $campaign['uuid']
//   );
//
//   // Team D — metrics_update (US-503):
//   NotificationService::send($pdo, $userId, 'metrics_update',
//       $campaign['title'] . ' — ' . $monthLabel . ' performance posted',
//       'Net income: ' . fmtR($actualNet) . '. ' . $varianceStr . ' vs projection.',
//       '/app/invest/campaign.php?cid=' . $campaign['uuid'] . '#financials',
//       ['campaign_uuid' => $campaign['uuid'], 'period' => $monthLabel]
//   );
//
//   // Team D — payout credited (US-504):
//   NotificationService::send($pdo, $userId, 'payout_credited',
//       'Distribution credited: ' . fmtR($amount),
//       'Your ' . $monthLabel . ' distribution of ' . fmtR($amount)
//           . ' from ' . $campaign['title'] . ' has been credited.',
//       '/app/wallet/'
//   );
//
//   // Bulk — notify all accepted investors for a campaign:
//   NotificationService::notifyCampaignInvestors(
//       $pdo, $campaignId, 'metrics_update',
//       $campaign['title'] . ' — Jan 2026 performance posted',
//       'Net income R 87 500 — 3.2% ahead of projection.',
//       '/app/invest/campaign.php?cid=' . $campaign['uuid'] . '#financials'
//   );
//
// NEVER throws — wraps DB writes in try/catch.
// Returns insert ID on success, null on failure.
// ============================================================

require_once __DIR__ . '/database.php';

class NotificationService
{
    // ── Confirmed type strings — must match NOTIFICATION_TYPES in index.php ─
    const TYPE_DEAL_INVITE            = 'deal_invite';
    const TYPE_PAYOUT_CREDITED        = 'payout_credited';
    const TYPE_PAYOUT_CALCULATED      = 'payout_calculated';
    const TYPE_METRICS_UPDATE         = 'metrics_update';
    const TYPE_CAMPAIGN_STATUS_CHANGE = 'campaign_status_change';
    const TYPE_INVITE_REVOKED         = 'invite_revoked';
    const TYPE_SYSTEM                 = 'system';
    const TYPE_GENERAL                = 'general';

    // ──────────────────────────────────────────────────────────
    // SEND — insert a single notification for one user.
    //
    // @param PDO         $pdo
    // @param int         $userId     Recipient user ID
    // @param string      $type       One of the TYPE_* constants
    // @param string      $title      Max 255 chars — shown in bell list
    // @param string|null $body       Optional longer description
    // @param string|null $linkUrl    Relative URL for click-through
    // @param array|null  $meta       Optional structured payload → meta_json
    //
    // @return int|null   Insert ID on success, null on failure
    // ──────────────────────────────────────────────────────────
    public static function send(
        PDO     $pdo,
        int     $userId,
        string  $type,
        string  $title,
        ?string $body    = null,
        ?string $linkUrl = null,
        ?array  $meta    = null
    ): ?int {
        if ($userId <= 0) {
            return null;
        }

        // Truncate title defensively — VARCHAR(255)
        $title = mb_substr(trim($title), 0, 255);
        if ($title === '') {
            return null;
        }

        // Normalise link_url — must be relative (no open-redirect risk)
        if ($linkUrl !== null) {
            $linkUrl = trim($linkUrl);
            if (!str_starts_with($linkUrl, '/')) {
                $linkUrl = null; // discard unsafe or absolute URLs
            }
            if (mb_strlen($linkUrl) > 500) {
                $linkUrl = mb_substr($linkUrl, 0, 500);
            }
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO notifications
                    (user_id, type, title, body, link_url, is_read, meta_json, created_at)
                VALUES
                    (:user_id, :type, :title, :body, :link_url, 0, :meta_json, NOW())
            ");
            $stmt->execute([
                'user_id'   => $userId,
                'type'      => $type,
                'title'     => $title,
                'body'      => $body,
                'link_url'  => $linkUrl,
                'meta_json' => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
            ]);
            return (int)$pdo->lastInsertId() ?: null;

        } catch (Throwable $e) {
            // Never break the caller's flow for a notification failure
            error_log('[NotificationService::send] type=' . $type . ' user=' . $userId . ' — ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // NOTIFY CAMPAIGN INVESTORS
    // Inserts the same notification for ALL users with an accepted
    // invite for a given campaign. Used by:
    //   - Team D US-503: metrics posted → notify all investors
    //   - Team D US-504: payout calculated → notify all investors
    //   - Team A (future): campaign status change → notify all investors
    //
    // Returns count of successfully inserted notifications.
    // ──────────────────────────────────────────────────────────
    public static function notifyCampaignInvestors(
        PDO     $pdo,
        int     $campaignId,
        string  $type,
        string  $title,
        ?string $body    = null,
        ?string $linkUrl = null,
        ?array  $meta    = null
    ): int {
        try {
            // Get all accepted investors for this campaign
            $stmt = $pdo->prepare("
                SELECT DISTINCT user_id
                FROM campaign_invites
                WHERE campaign_id = :cid
                  AND status = 'accepted'
                  AND user_id IS NOT NULL
            ");
            $stmt->execute(['cid' => $campaignId]);
            $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

            if (empty($userIds)) {
                return 0;
            }

            $count = 0;
            foreach ($userIds as $uid) {
                if (self::send($pdo, (int)$uid, $type, $title, $body, $linkUrl, $meta) !== null) {
                    $count++;
                }
            }
            return $count;

        } catch (Throwable $e) {
            error_log('[NotificationService::notifyCampaignInvestors] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET UNREAD COUNT
    // Used by header bell badge across all pages.
    // Returns 0 gracefully if table doesn't exist yet.
    // ──────────────────────────────────────────────────────────
    public static function getUnreadCount(PDO $pdo, int $userId): int
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM notifications
                WHERE user_id    = :uid
                  AND is_read    = 0
                  AND archived_at IS NULL
            ");
            $stmt->execute(['uid' => $userId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            // Table may not exist yet — degrade silently (Team D's stub handles this)
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET HEADER BELL HTML SNIPPET
    // Returns a self-contained HTML string for the bell icon
    // to drop into any page header. Team D specified this in
    // index.php — centralising it here so all teams use one copy.
    //
    // Usage: echo NotificationService::bellHtml($pdo, $userId);
    // ──────────────────────────────────────────────────────────
    public static function bellHtml(PDO $pdo, int $userId): string
    {
        $count = self::getUnreadCount($pdo, $userId);
        $badge = $count > 0
            ? '<span style="position:absolute;top:-4px;right:-4px;background:#c8102e;color:#fff;'
              . 'font-size:.62rem;font-weight:700;padding:.08rem .35rem;border-radius:99px;'
              . 'min-width:16px;text-align:center;pointer-events:none;">'
              . ($count > 99 ? '99+' : $count)
              . '</span>'
            : '';

        return '<a href="/app/notifications/" '
            . 'style="position:relative;display:inline-flex;align-items:center;justify-content:center;'
            . 'width:36px;height:36px;border-radius:8px;color:var(--text-muted,#667085);'
            . 'text-decoration:none;transition:background .15s;" '
            . 'title="Notifications' . ($count > 0 ? " ($count unread)" : '') . '">'
            . '<i class="fa-solid fa-bell" style="font-size:.9rem;"></i>'
            . $badge
            . '</a>';
    }

    // ──────────────────────────────────────────────────────────
    // ARCHIVE OLD NOTIFICATIONS
    // Called by the US-1003 background job (nightly).
    // Soft-archives notifications older than $days days.
    // Returns count of archived rows.
    // ──────────────────────────────────────────────────────────
    public static function archiveOld(PDO $pdo, int $days = 90): int
    {
        try {
            $stmt = $pdo->prepare("
                UPDATE notifications
                SET archived_at = NOW()
                WHERE archived_at IS NULL
                  AND created_at < DATE_SUB(NOW(), INTERVAL :days DAY)
            ");
            $stmt->execute(['days' => $days]);
            return (int)$stmt->rowCount();
        } catch (Throwable $e) {
            error_log('[NotificationService::archiveOld] — ' . $e->getMessage());
            return 0;
        }
    }
}
