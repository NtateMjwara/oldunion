<?php
// ============================================================
// InvestmentService.php — contribution business logic
//
// Handles everything related to a user investing in a
// funding campaign: validation, wallet debit, EFT intent,
// contribution record lifecycle, and campaign aggregate updates.
//
// Depends on WalletService::appendLedger() for ledger writes.
// Both share the same PDO singleton so ledger entries
// participate in InvestmentService's own transactions.
//
// US-101: InviteService::assertAccepted() is called inside
// validate() — the shared pre-flight chokepoint — so the
// invite gate applies to every contribution path automatically,
// including any future methods added to this class.
// ============================================================

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/WalletService.php';
require_once __DIR__ . '/InviteService.php'; // US-101

class InvestmentService
{
    // ----------------------------------------------------------
    // CAMPAIGN LOADING
    // Load a campaign that is eligible for investment.
    // Returns null if not found, not open, or company not verified.
    // ----------------------------------------------------------
    public static function getCampaignForInvestment(string $campaignUuid): ?array
    {
        $stmt = Database::getInstance()->prepare("
            SELECT
                fc.id, fc.uuid, fc.title, fc.tagline,
                fc.campaign_type, fc.status,
                fc.raise_target, fc.raise_minimum, fc.raise_maximum,
                fc.min_contribution, fc.max_contribution,
                fc.max_contributors, fc.total_raised,
                fc.contributor_count, fc.opens_at, fc.closes_at,
                ct.revenue_share_percentage,
                ct.revenue_share_duration_months,
                ct.unit_name, ct.unit_price,
                ct.total_units_available,
                ct.governing_law,
                c.id   AS company_id,
                c.uuid AS company_uuid,
                c.name AS company_name,
                c.logo AS company_logo
            FROM funding_campaigns fc
            LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
            JOIN companies c ON c.id = fc.company_id
            WHERE fc.uuid  = :uuid
              AND fc.status IN ('open','funded')
              AND c.status  = 'active'
              AND c.verified = 1
        ");
        $stmt->execute(['uuid' => $campaignUuid]);
        return $stmt->fetch() ?: null;
    }

    // ----------------------------------------------------------
    // EXISTING CONTRIBUTION CHECK
    // Returns the user's existing contribution row for a campaign.
    // Used to block duplicate investments (schema enforces UNIQUE
    // on campaign_id + user_id; this gives a clean error first).
    // ----------------------------------------------------------
    public static function getUserContribution(int $userId, int $campaignId): ?array
    {
        $stmt = Database::getInstance()->prepare("
            SELECT id, uuid, amount, status, payment_method,
                   payment_reference, created_at
            FROM contributions
            WHERE user_id = :uid AND campaign_id = :cid
            LIMIT 1
        ");
        $stmt->execute(['uid' => $userId, 'cid' => $campaignId]);
        return $stmt->fetch() ?: null;
    }

    // ----------------------------------------------------------
    // VALIDATE — shared pre-flight checks.
    // Throws RuntimeException with a user-facing message on failure.
    //
    // US-101: Invite gate assertion is the first check so that
    // an uninvited user cannot probe campaign limits or caps.
    //
    // NOTE on contributor cap: we check contributor_count
    // (confirmed contributions only). EFT contributions in
    // pending_payment status do not count toward the cap until
    // payment is confirmed. This is intentional for Phase 1 —
    // only confirmed funds count toward the legal 50-person limit.
    // ----------------------------------------------------------
    private static function validate(
        array $campaign,
        int   $userId,
        float $amount
    ): void {
        // --- US-101: server-side invite gate (defence-in-depth) ---
        // UI pages also gate before reaching here, but this assertion
        // ensures even a direct API call cannot bypass the invite check.
        InviteService::assertAccepted($userId, (int)$campaign['id']);

        $now = time();

        if (strtotime($campaign['opens_at']) > $now) {
            throw new RuntimeException('This campaign is not open yet.');
        }
        if (strtotime($campaign['closes_at']) < $now) {
            throw new RuntimeException('This campaign has closed.');
        }
        if ((int)$campaign['contributor_count'] >= (int)$campaign['max_contributors']) {
            throw new RuntimeException(
                'This campaign has reached its maximum number of contributors.'
            );
        }
        if (self::getUserContribution($userId, (int)$campaign['id'])) {
            throw new RuntimeException('You have already invested in this campaign.');
        }

        $min = (float)$campaign['min_contribution'];
        if ($amount < $min) {
            throw new RuntimeException(
                'Minimum investment is R ' . number_format($min, 2) . '.'
            );
        }

        $max = $campaign['max_contribution'] ? (float)$campaign['max_contribution'] : null;
        if ($max !== null && $amount > $max) {
            throw new RuntimeException(
                'Maximum investment is R ' . number_format($max, 2) . '.'
            );
        }

        if ($campaign['raise_maximum'] !== null) {
            $remaining = (float)$campaign['raise_maximum'] - (float)$campaign['total_raised'];
            if ($amount > $remaining) {
                throw new RuntimeException(
                    'Only R ' . number_format($remaining, 2) .
                    ' of the hard cap remains. Please invest R ' .
                    number_format($remaining, 2) . ' or less.'
                );
            }
        }
    }

    // ----------------------------------------------------------
    // UUID HELPER
    // ----------------------------------------------------------
    private static function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }

    // ----------------------------------------------------------
    // WALLET INVESTMENT
    // Atomically:
    //   1. Validate campaign + amount (includes invite gate)
    //   2. Lock and debit user wallet
    //   3. Insert contributions row (status = 'paid')
    //   4. Update campaign aggregate totals
    //   5. Append wallet ledger entry via WalletService
    // ----------------------------------------------------------
    public static function createContributionFromWallet(
        int   $userId,
        array $campaign,
        float $amount
    ): array {
        self::validate($campaign, $userId, $amount);

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            // Lock wallet row for update
            $stmt = $pdo->prepare(
                'SELECT id, balance, status
                 FROM user_wallets
                 WHERE user_id = ? AND status = "active"
                 FOR UPDATE'
            );
            $stmt->execute([$userId]);
            $wallet = $stmt->fetch();

            if (!$wallet) {
                throw new RuntimeException(
                    'No active wallet found. Please top up your wallet first.'
                );
            }
            if ((float)$wallet['balance'] < $amount) {
                throw new RuntimeException(
                    'Insufficient wallet balance. Your balance is R ' .
                    number_format((float)$wallet['balance'], 2) .
                    '. Please top up and try again.'
                );
            }

            $walletId      = (int)   $wallet['id'];
            $balanceBefore = (float) $wallet['balance'];
            $ref           = generateReference('INV');
            $uuid          = self::generateUuid();
            $campaignId    = (int)   $campaign['id'];
            $now           = date('Y-m-d H:i:s');

            // Insert contribution record
            $pdo->prepare("
                INSERT INTO contributions
                    (uuid, campaign_id, user_id, amount,
                     payment_method, payment_reference, paid_at,
                     agreement_signed_at, agreement_document_url,
                     status)
                VALUES
                    (:uuid, :cid, :uid, :amount,
                     'platform_wallet', :ref, :paid_at,
                      :agreed_at, :agreement,
                     'paid')
            ")->execute([
                'uuid'      => $uuid,
                'cid'       => $campaignId,
                'uid'       => $userId,
                'amount'    => $amount,
                'ref'       => $ref,
                'paid_at'   => $now,
                'agreed_at' => $now,
                'agreement' => 'digital_acceptance_' . time(),
            ]);
            $contributionId = (int) $pdo->lastInsertId();

            // Debit wallet
            $pdo->prepare(
                'UPDATE user_wallets SET balance = balance - ? WHERE id = ?'
            )->execute([$amount, $walletId]);

            // Update campaign aggregates
            $pdo->prepare("
                UPDATE funding_campaigns
                SET total_raised      = total_raised + :amount,
                    contributor_count = contributor_count + 1
                WHERE id = :cid
            ")->execute(['amount' => $amount, 'cid' => $campaignId]);

            // Ledger entry through WalletService (same PDO/transaction)
            WalletService::appendLedger(
                $pdo,
                $walletId,
                'debit',
                $amount,
                $balanceBefore,
                'investment',
                $contributionId,
                'Investment: ' . $campaign['company_name'] . ' — ' . $campaign['title'],
                $ref
            );

            $pdo->commit();

            return [
                'success'            => true,
                'contribution_id'    => $contributionId,
                'contribution_uuid'  => $uuid,
                'reference'          => $ref,
                'payment_method'     => 'platform_wallet',
                'amount'             => $amount,
                'campaign_title'     => $campaign['title'],
                'company_name'       => $campaign['company_name'],
            ];

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[InvestmentService::createContributionFromWallet] ' . $e->getMessage());
            throw $e;
        }
    }

    // ----------------------------------------------------------
    // EFT INVESTMENT
    // Creates a contribution in pending_payment status.
    // No wallet debit — user must EFT using the generated ref.
    // Campaign aggregates are NOT updated here; they are updated
    // when an admin confirms receipt of the EFT payment.
    // ----------------------------------------------------------
    public static function createContributionEFT(
        int   $userId,
        array $campaign,
        float $amount
    ): array {
        self::validate($campaign, $userId, $amount);

        $pdo        = Database::getInstance();
        $ref        = generateReference('EFT');
        $uuid       = self::generateUuid();
        $campaignId = (int) $campaign['id'];

        $pdo->prepare("
            INSERT INTO contributions
                (uuid, campaign_id, user_id, amount,
                 payment_method, payment_reference,
                 status)
            VALUES
                (:uuid, :cid, :uid, :amount,
                 'eft', :ref,
                 'pending_payment')
        ")->execute([
            'uuid'   => $uuid,
            'cid'    => $campaignId,
            'uid'    => $userId,
            'amount' => $amount,
            'ref'    => $ref,
        ]);
        $contributionId = (int) $pdo->lastInsertId();

        return [
            'success'           => true,
            'contribution_id'   => $contributionId,
            'contribution_uuid' => $uuid,
            'reference'         => $ref,
            'payment_method'    => 'eft',
            'amount'            => $amount,
            'campaign_title'    => $campaign['title'],
            'company_name'      => $campaign['company_name'],
        ];
    }

    // ----------------------------------------------------------
    // INVESTOR PORTFOLIO — all contributions for a user
    // ----------------------------------------------------------
    public static function getContributionsByUser(int $userId): array
    {
        $stmt = Database::getInstance()->prepare("
            SELECT
                con.id, con.uuid, con.amount, con.status,
                con.payment_method, con.payment_reference,
                con.paid_at, con.created_at,
                fc.uuid  AS campaign_uuid,
                fc.title AS campaign_title,
                fc.campaign_type,
                fc.status AS campaign_status,
                fc.closes_at,
                c.name AS company_name,
                c.uuid AS company_uuid,
                c.logo AS company_logo
            FROM contributions con
            JOIN funding_campaigns fc ON fc.id = con.campaign_id
            JOIN companies c ON c.id = fc.company_id
            WHERE con.user_id = :uid
            ORDER BY con.created_at DESC
        ");
        $stmt->execute(['uid' => $userId]);
        return $stmt->fetchAll();
    }

    // ----------------------------------------------------------
    // SINGLE CONTRIBUTION DETAIL — with full context.
    // Verifies ownership via user_id match.
    // ----------------------------------------------------------
    public static function getContributionDetail(int $contributionId, int $userId): ?array
    {
        $stmt = Database::getInstance()->prepare("
            SELECT
                con.id, con.uuid, con.amount, con.status,
                con.payment_method, con.payment_reference,
                con.paid_at, con.agreement_signed_at,
                con.refund_reason, con.refunded_at,
                con.created_at, con.updated_at,
                fc.uuid   AS campaign_uuid,
                fc.title  AS campaign_title,
                fc.tagline AS campaign_tagline,
                fc.campaign_type,
                fc.status AS campaign_status,
                fc.raise_target, fc.total_raised,
                fc.opens_at, fc.closes_at,
                ct.revenue_share_percentage,
                ct.revenue_share_duration_months,
                ct.unit_name, ct.unit_price,
                ct.governing_law,
                c.name AS company_name,
                c.uuid AS company_uuid,
                c.logo AS company_logo
            FROM contributions con
            JOIN funding_campaigns fc ON fc.id = con.campaign_id
            LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
            JOIN companies c ON c.id = fc.company_id
            WHERE con.id = :id AND con.user_id = :uid
        ");
        $stmt->execute(['id' => $contributionId, 'uid' => $userId]);
        return $stmt->fetch() ?: null;
    }
}
