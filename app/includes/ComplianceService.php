<?php
// ============================================================
// ComplianceService.php — US-105 Compliance Audit Trail
//
// Single source of truth for all compliance event logging.
// Every invite, contribution, and campaign lifecycle action
// that must be auditable flows through this service.
//
// Design principles:
//   • NEVER throws — wraps all DB writes in try/catch so a
//     logging failure never breaks the user-facing action.
//   • Append-only — callers cannot update or delete events.
//   • Soft-limit sentinel — checkSoftLimit() fires a
//     'invite_soft_limit_reached' event the first time a
//     campaign reaches 40 accepted invites (one time only).
//
// Usage:
//   ComplianceService::log($pdo, 'invite_accepted', [
//       'actor_id'     => $userId,
//       'campaign_id'  => $campaignId,
//       'target_id'    => $investorId,
//       'ip_address'   => $_SERVER['REMOTE_ADDR'] ?? '',
//       'meta'         => ['invite_uuid' => $uuid],
//   ]);
//   ComplianceService::checkSoftLimit($pdo, $campaignId);
// ============================================================

require_once __DIR__ . '/database.php';

class ComplianceService
{
    // ── Event type constants ──────────────────────────────────
    // Invite lifecycle
    const EVT_INVITE_SENT                    = 'invite_sent';
    const EVT_INVITE_SENT_EXTERNAL           = 'invite_sent_external';
    const EVT_INVITE_RESENT_EXTERNAL         = 'invite_resent_external';
    const EVT_INVITE_OPENED                  = 'invite_opened';
    const EVT_INVITE_ACCEPTED                = 'invite_accepted';
    const EVT_INVITE_DECLINED                = 'invite_declined';
    const EVT_INVITE_REVOKED                 = 'invite_revoked';
    const EVT_INVITE_CLAIMED_ON_VERIFICATION = 'invite_claimed_on_verification';
    const EVT_INVITE_REREQUEST               = 'invite_reRequest';
    const EVT_INVITE_SOFT_LIMIT_REACHED      = 'invite_soft_limit_reached';

    // Contribution lifecycle
    const EVT_CONTRIBUTION_STARTED          = 'contribution_started';
    const EVT_CONTRIBUTION_CONFIRMED        = 'contribution_confirmed';
    const EVT_CONTRIBUTION_COMPLETED        = 'contribution_completed';
    const EVT_CONTRIBUTION_REFUNDED         = 'contribution_refunded';
    const EVT_CONTRIBUTION_EFT_PENDING      = 'contribution_eft_pending';

    // Campaign lifecycle
    const EVT_CAMPAIGN_SUBMITTED            = 'campaign_submitted';
    const EVT_CAMPAIGN_APPROVED             = 'campaign_approved';
    const EVT_CAMPAIGN_REJECTED             = 'campaign_rejected';
    const EVT_CAMPAIGN_REVOKED              = 'campaign_revoked';
    const EVT_CAMPAIGN_CLOSED_SUCCESS       = 'campaign_closed_success';
    const EVT_CAMPAIGN_CLOSED_FAILED        = 'campaign_closed_failed';

    // Admin actions
    const EVT_ADMIN_KYC_APPROVED            = 'admin_kyc_approved';
    const EVT_ADMIN_KYC_REJECTED            = 'admin_kyc_rejected';
    const EVT_ADMIN_CONTRIBUTION_CONFIRMED  = 'admin_contribution_confirmed';
    const EVT_ADMIN_PAYOUT_APPROVED         = 'admin_payout_approved';

    // Soft-limit threshold (SA private placement: 50 max contributors)
    const SOFT_LIMIT = 40;

    // ──────────────────────────────────────────────────────────
    // LOG
    // Core method — inserts a single compliance event row.
    //
    // $context keys (all optional except event_type):
    //   actor_id       int|null   — platform user who triggered the action
    //   target_id      int|null   — investor/user the action is about
    //   campaign_id    int|null
    //   contribution_id int|null
    //   guest_email    string     — for external-invite events
    //   ip_address     string
    //   user_agent     string     — auto-populated from $_SERVER if omitted
    //   meta           array      — arbitrary structured payload (→ JSON)
    //
    // Returns the new row ID on success, null on failure.
    // ──────────────────────────────────────────────────────────
    public static function log(
        PDO    $pdo,
        string $eventType,
        array  $context = []
    ): ?int {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO compliance_events
                    (event_type, actor_id, target_id, campaign_id,
                     contribution_id, guest_email,
                     ip_address, user_agent, meta_json, created_at)
                VALUES
                    (:event_type, :actor_id, :target_id, :campaign_id,
                     :contribution_id, :guest_email,
                     :ip_address, :user_agent, :meta_json, NOW())
            ");

            $stmt->execute([
                'event_type'      => $eventType,
                'actor_id'        => $context['actor_id']        ?? null,
                'target_id'       => $context['target_id']       ?? null,
                'campaign_id'     => $context['campaign_id']     ?? null,
                'contribution_id' => $context['contribution_id'] ?? null,
                'guest_email'     => $context['guest_email']     ?? null,
                'ip_address'      => $context['ip_address']      ?? ($_SERVER['REMOTE_ADDR'] ?? null),
                'user_agent'      => $context['user_agent']      ?? (substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 512) ?: null),
                'meta_json'       => isset($context['meta']) && is_array($context['meta'])
                                        ? json_encode($context['meta'], JSON_UNESCAPED_UNICODE)
                                        : null,
            ]);

            return (int)$pdo->lastInsertId() ?: null;

        } catch (Throwable $e) {
            // Logging must never break the caller's flow.
            error_log('[ComplianceService::log] ' . $eventType . ' — ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // CHECK SOFT LIMIT
    // Counts accepted invites for the campaign and fires a
    // sentinel event the FIRST time the count reaches SOFT_LIMIT.
    // Idempotent: only one sentinel per campaign is ever inserted.
    //
    // Returns true if the limit has been reached (either now or
    // previously), false otherwise.
    // ──────────────────────────────────────────────────────────
    public static function checkSoftLimit(PDO $pdo, int $campaignId): bool
    {
        try {
            // Count currently accepted invites
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM campaign_invites
                WHERE campaign_id = ? AND status = 'accepted'
            ");
            $stmt->execute([$campaignId]);
            $accepted = (int)$stmt->fetchColumn();

            if ($accepted < self::SOFT_LIMIT) {
                return false;
            }

            // Check if we have already fired the sentinel for this campaign
            $stmt = $pdo->prepare("
                SELECT 1 FROM compliance_events
                WHERE event_type   = 'invite_soft_limit_reached'
                  AND campaign_id  = ?
                LIMIT 1
            ");
            $stmt->execute([$campaignId]);
            if ($stmt->fetchColumn()) {
                return true; // already fired
            }

            // First time hitting the limit — fire the sentinel
            self::log($pdo, self::EVT_INVITE_SOFT_LIMIT_REACHED, [
                'campaign_id' => $campaignId,
                'meta'        => [
                    'accepted_count' => $accepted,
                    'threshold'      => self::SOFT_LIMIT,
                    'message'        => "Campaign $campaignId has reached $accepted accepted invites (soft limit: " . self::SOFT_LIMIT . ")",
                ],
            ]);

            return true;

        } catch (Throwable $e) {
            error_log('[ComplianceService::checkSoftLimit] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET EVENTS FOR CAMPAIGN
    // Fetches the audit trail for one campaign, ordered newest
    // first. Used by the admin export page and the founder
    // invite management dashboard.
    //
    // $filters:
    //   event_types  array   — whitelist; empty = all
    //   date_from    string  — Y-m-d
    //   date_to      string  — Y-m-d
    //   limit        int     — default 500
    //   offset       int     — for pagination
    // ──────────────────────────────────────────────────────────
    public static function getEventsForCampaign(
        PDO   $pdo,
        int   $campaignId,
        array $filters = []
    ): array {
        $where  = ['ce.campaign_id = :cid'];
        $params = ['cid' => $campaignId];

        if (!empty($filters['event_types']) && is_array($filters['event_types'])) {
            $placeholders = implode(',', array_fill(0, count($filters['event_types']), '?'));
            // We build this part with positional params separately — see combined execute below.
        }
        if (!empty($filters['date_from'])) {
            $where[]                = 'DATE(ce.created_at) >= :date_from';
            $params['date_from']    = $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[]                = 'DATE(ce.created_at) <= :date_to';
            $params['date_to']      = $filters['date_to'];
        }

        $whereSQL = 'WHERE ' . implode(' AND ', $where);
        $limit    = (int)($filters['limit']  ?? 500);
        $offset   = (int)($filters['offset'] ?? 0);

        // Event type filter handled separately due to IN clause
        $eventTypeSQL = '';
        $eventTypeParams = [];
        if (!empty($filters['event_types'])) {
            $ph = implode(',', array_fill(0, count($filters['event_types']), '?'));
            $eventTypeSQL    = "AND ce.event_type IN ($ph)";
            $eventTypeParams = array_values($filters['event_types']);
        }

        $sql = "
            SELECT
                ce.id,
                ce.event_type,
                ce.actor_id,
                ua.email        AS actor_email,
                ce.target_id,
                ut.email        AS target_email,
                ce.guest_email,
                ce.contribution_id,
                ce.ip_address,
                ce.meta_json,
                ce.created_at
            FROM compliance_events ce
            LEFT JOIN users ua ON ua.id = ce.actor_id
            LEFT JOIN users ut ON ut.id = ce.target_id
            $whereSQL $eventTypeSQL
            ORDER BY ce.created_at DESC
            LIMIT $limit OFFSET $offset
        ";

        $stmt = $pdo->prepare($sql);

        // Bind named params first, then positional event-type params
        foreach ($params as $key => $val) {
            $stmt->bindValue(':' . $key, $val);
        }
        foreach ($eventTypeParams as $i => $val) {
            $stmt->bindValue($i + 1, $val);
        }
        $stmt->execute();

        return $stmt->fetchAll();
    }

    // ──────────────────────────────────────────────────────────
    // COUNT EVENTS FOR CAMPAIGN
    // Total row count for pagination.
    // ──────────────────────────────────────────────────────────
    public static function countEventsForCampaign(PDO $pdo, int $campaignId): int
    {
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) FROM compliance_events WHERE campaign_id = ?
            ");
            $stmt->execute([$campaignId]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }

    // ──────────────────────────────────────────────────────────
    // INVITE SUMMARY FOR CAMPAIGN
    // Returns counts by status for a single campaign — used in
    // the soft-limit progress bar.
    // ──────────────────────────────────────────────────────────
    public static function inviteSummary(PDO $pdo, int $campaignId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT
                    COUNT(*)                                         AS total_issued,
                    COUNT(CASE WHEN status = 'accepted'  THEN 1 END) AS accepted,
                    COUNT(CASE WHEN status = 'pending'   THEN 1 END) AS pending,
                    COUNT(CASE WHEN status = 'declined'  THEN 1 END) AS declined,
                    COUNT(CASE WHEN status = 'revoked'   THEN 1 END) AS revoked
                FROM campaign_invites
                WHERE campaign_id = ?
            ");
            $stmt->execute([$campaignId]);
            $row = $stmt->fetch();

            // Also pull max_contributors from the campaign
            $stmt2 = $pdo->prepare("SELECT max_contributors FROM funding_campaigns WHERE id = ?");
            $stmt2->execute([$campaignId]);
            $maxContributors = (int)$stmt2->fetchColumn();

            return [
                'total_issued'    => (int)$row['total_issued'],
                'accepted'        => (int)$row['accepted'],
                'pending'         => (int)$row['pending'],
                'declined'        => (int)$row['declined'],
                'revoked'         => (int)$row['revoked'],
                'max_contributors'=> $maxContributors,
                'slots_remaining' => max(0, $maxContributors - (int)$row['accepted'] - (int)$row['pending']),
                'at_soft_limit'   => (int)$row['accepted'] >= self::SOFT_LIMIT,
                'at_hard_limit'   => (int)$row['accepted'] >= $maxContributors,
            ];
        } catch (Throwable $e) {
            error_log('[ComplianceService::inviteSummary] ' . $e->getMessage());
            return [
                'total_issued'    => 0, 'accepted' => 0, 'pending' => 0,
                'declined'        => 0, 'revoked'  => 0, 'max_contributors' => 50,
                'slots_remaining' => 50, 'at_soft_limit' => false, 'at_hard_limit' => false,
            ];
        }
    }

    // ──────────────────────────────────────────────────────────
    // BUILD CSV LINE
    // Escapes a single array row for RFC 4180-compliant CSV.
    // ──────────────────────────────────────────────────────────
    private static function csvLine(array $fields): string
    {
        return implode(',', array_map(function ($v) {
            $v = (string)$v;
            if (str_contains($v, ',') || str_contains($v, '"') || str_contains($v, "\n")) {
                $v = '"' . str_replace('"', '""', $v) . '"';
            }
            return $v;
        }, $fields)) . "\r\n";
    }

    // ──────────────────────────────────────────────────────────
    // STREAM CSV EXPORT
    // Sends the complete audit trail for a campaign as a
    // downloadable CSV directly to the HTTP response.
    // Call this method only from admin-gated pages.
    // ──────────────────────────────────────────────────────────
    public static function streamCsvExport(
        PDO    $pdo,
        int    $campaignId,
        string $campaignTitle = 'campaign'
    ): void {
        $slug    = preg_replace('/[^a-z0-9]+/', '-', strtolower($campaignTitle));
        $slug    = trim($slug, '-');
        $filename = 'compliance-' . $slug . '-' . date('Ymd') . '.csv';

        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, no-store');
        header('Pragma: no-cache');

        $out = fopen('php://output', 'w');

        // BOM for Excel UTF-8 compatibility
        fwrite($out, "\xEF\xBB\xBF");

        // Header row
        fputcsv($out, [
            'ID', 'Event Type', 'Timestamp (SAST)',
            'Actor ID', 'Actor Email',
            'Target ID', 'Target Email',
            'Guest Email',
            'Contribution ID',
            'IP Address',
            'Meta',
        ]);

        // Stream rows in batches of 200 to keep memory flat
        $offset = 0;
        $batch  = 200;
        do {
            $rows = self::getEventsForCampaign($pdo, $campaignId, [
                'limit'  => $batch,
                'offset' => $offset,
            ]);
            foreach ($rows as $r) {
                fputcsv($out, [
                    $r['id'],
                    $r['event_type'],
                    $r['created_at'],
                    $r['actor_id']       ?? '',
                    $r['actor_email']    ?? '',
                    $r['target_id']      ?? '',
                    $r['target_email']   ?? '',
                    $r['guest_email']    ?? '',
                    $r['contribution_id'] ?? '',
                    $r['ip_address']     ?? '',
                    $r['meta_json']      ?? '',
                ]);
            }
            $offset += $batch;
        } while (count($rows) === $batch);

        fclose($out);
        exit;
    }
}