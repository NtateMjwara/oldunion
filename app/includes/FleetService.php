<?php
// ============================================================
// FleetService.php — US-106
//
// Single source of truth for all fleet-specific business logic.
// Called by:
//   Team B  — invest/campaign.php (deal room), invest/start.php
//   Team C  — wizard.php (projection bulk upsert), manage.php
//   Team D  — metrics reporting, payout calculation
//
// Design principles:
//   • All public methods have explicit return types.
//   • Methods fail gracefully — never throw on missing data.
//     Non-fleet campaigns and empty tables return null / [].
//   • calculateDistribution() and calculatePayouts() are pure
//     computation — they do NOT write to DB.
//   • getDocuments() enforces access control against invite status.
//   • bulkUpsertProjections() is the only write method —
//     called by Team C's wizard Step 7 save handler.
//
// Dependencies: Database singleton (database.php), InviteService
// ============================================================

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/InviteService.php';

class FleetService
{
    // ──────────────────────────────────────────────────────────
    // GET ASSETS
    // Returns all campaign_assets rows for a campaign, ordered
    // by asset_label. Empty array if none. Never null.
    //
    // @param int $campaignId
    // @return array<int, array<string, mixed>>
    // ──────────────────────────────────────────────────────────
    public static function getAssets(int $campaignId): array
    {
        try {
            $stmt = Database::getInstance()->prepare("
                SELECT
                    id, uuid, asset_label, asset_type,
                    make, model, year,
                    acquisition_cost,
                    serial_number,
                    gps_device_id,
                    insurance_ref, insurance_expiry,
                    deployment_platform,
                    status, deployed_at, notes,
                    created_at, updated_at
                FROM campaign_assets
                WHERE campaign_id = :cid
                ORDER BY asset_label ASC
            ");
            $stmt->execute(['cid' => $campaignId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[FleetService::getAssets] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET ASSET SUMMARY
    // Aggregate counts used in deal room header and wizard.
    //
    // @param int $campaignId
    // @return array{total: int, active: int, pending: int, damaged: int, sold: int}
    // ──────────────────────────────────────────────────────────
    public static function getAssetSummary(int $campaignId): array
    {
        $defaults = ['total' => 0, 'active' => 0, 'pending' => 0, 'damaged' => 0, 'sold' => 0];
        try {
            $stmt = Database::getInstance()->prepare("
                SELECT
                    COUNT(*)                                              AS total,
                    COUNT(CASE WHEN status = 'active'  THEN 1 END)       AS active,
                    COUNT(CASE WHEN status = 'pending' THEN 1 END)       AS pending,
                    COUNT(CASE WHEN status = 'damaged' THEN 1 END)       AS damaged,
                    COUNT(CASE WHEN status = 'sold'    THEN 1 END)       AS sold
                FROM campaign_assets
                WHERE campaign_id = :cid
            ");
            $stmt->execute(['cid' => $campaignId]);
            $row = $stmt->fetch();
            return $row ? array_map('intval', $row) : $defaults;
        } catch (Throwable $e) {
            error_log('[FleetService::getAssetSummary] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return $defaults;
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET PROJECTIONS
    // Returns campaign_projections rows ordered by period_number
    // ASC. Empty array if none. Never null.
    //
    // @param int $campaignId
    // @return array<int, array<string, mixed>>
    // ──────────────────────────────────────────────────────────
    public static function getProjections(int $campaignId): array
    {
        try {
            $stmt = Database::getInstance()->prepare("
                SELECT
                    id, period_number, label,
                    gross_revenue_projected,
                    energy_cost, maintenance_reserve,
                    insurance_cost, management_fee,
                    opex_total,
                    net_to_spv,
                    investor_distribution,
                    hurdle_cleared,
                    notes
                FROM campaign_projections
                WHERE campaign_id = :cid
                ORDER BY period_number ASC
            ");
            $stmt->execute(['cid' => $campaignId]);
            return $stmt->fetchAll();
        } catch (Throwable $e) {
            error_log('[FleetService::getProjections] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET DOCUMENTS
    // Returns campaign_documents filtered by user's invite status.
    //
    // Access rules:
    //   access_level='public'   → always visible to logged-in users
    //   access_level='invited'  → visible if invite status ∈ {pending, accepted}
    //   access_level='accepted' → visible only if invite status = accepted
    //
    // Returns is_active=1 rows only. Sorted: subscription_agreement
    // first, then ppm, financial_model, investor_rights, then others
    // alphabetically.
    //
    // @param int $campaignId
    // @param int $userId       The currently logged-in user
    // @return array<int, array<string, mixed>>
    // ──────────────────────────────────────────────────────────
    public static function getDocuments(int $campaignId, int $userId): array
    {
        try {
            $pdo = Database::getInstance();

            // Determine user's invite status for this campaign
            $inviteStatus = self::getInviteStatus($pdo, $campaignId, $userId);

            // Build access_level whitelist based on invite status
            $allowedAccessLevels = ['public'];
            if (in_array($inviteStatus, ['pending', 'accepted'], true)) {
                $allowedAccessLevels[] = 'invited';
            }
            if ($inviteStatus === 'accepted') {
                $allowedAccessLevels[] = 'accepted';
            }

            $placeholders = implode(',', array_fill(0, count($allowedAccessLevels), '?'));

            $stmt = $pdo->prepare("
                SELECT
                    id, uuid, doc_type, label,
                    file_url, file_size_kb, version,
                    access_level, uploaded_at
                FROM campaign_documents
                WHERE campaign_id = ?
                  AND is_active   = 1
                  AND access_level IN ($placeholders)
                ORDER BY
                    FIELD(doc_type,
                        'subscription_agreement',
                        'ppm',
                        'financial_model',
                        'investor_rights',
                        'due_diligence',
                        'other'
                    ),
                    uploaded_at DESC
            ");
            $params = array_merge([$campaignId], $allowedAccessLevels);
            $stmt->execute($params);
            return $stmt->fetchAll();

        } catch (Throwable $e) {
            error_log('[FleetService::getDocuments] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // CALCULATE DISTRIBUTION
    // Pure computation — does NOT write to DB.
    //
    // Given an investment amount and campaign, calculates what
    // the investor's projected monthly distribution and annual
    // yield would be, based on the stabilised net income period
    // in campaign_projections and the waterfall in campaign_terms.
    //
    // Returns null if:
    //   - campaign is not fleet_asset type
    //   - no projections loaded
    //   - campaign_terms are incomplete (missing waterfall params)
    //
    // Waterfall logic:
    //   1. Identify stabilised period (highest net_to_spv period,
    //      or the last period with hurdle_cleared=1, or period 6
    //      as fallback).
    //   2. Calculate investor's pro-rata share of total raise.
    //   3. Apply waterfall:
    //        if net_to_spv >= hurdle_amount:
    //            hurdle_amount goes to investors first (pro-rata)
    //            surplus = (net_to_spv - hurdle_amount) × investor_waterfall_pct
    //            investor_total = hurdle_amount_prorata + surplus_prorata
    //        else:
    //            investor_total = net_to_spv × investor_waterfall_pct × share_pct
    //   4. Annual yield = (monthly_distribution × 12) / investment_amount × 100
    //
    // @param float $amount       Investment amount (ZAR)
    // @param int   $campaignId
    // @return array<string, mixed>|null
    // ──────────────────────────────────────────────────────────
    public static function calculateDistribution(float $amount, int $campaignId): ?array
    {
        if ($amount <= 0) {
            return null;
        }

        try {
            $pdo = Database::getInstance();

            // Load campaign terms + raise target
            $stmt = $pdo->prepare("
                SELECT
                    fc.campaign_type,
                    fc.raise_target,
                    ct.hurdle_rate,
                    ct.investor_waterfall_pct,
                    ct.management_fee_pct,
                    ct.management_fee_basis,
                    ct.distribution_frequency,
                    ct.term_months
                FROM funding_campaigns fc
                LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
                WHERE fc.id = :cid
                LIMIT 1
            ");
            $stmt->execute(['cid' => $campaignId]);
            $terms = $stmt->fetch();

            if (!$terms) {
                return null;
            }

            // Only proceed for fleet_asset campaigns
            if ($terms['campaign_type'] !== 'fleet_asset') {
                return null;
            }

            // Require core waterfall params
            if ($terms['investor_waterfall_pct'] === null || $terms['raise_target'] <= 0) {
                return null;
            }

            $raiseTarget        = (float)$terms['raise_target'];
            $hurdleRate         = (float)($terms['hurdle_rate'] ?? 0);          // % p.a.
            $investorPct        = (float)$terms['investor_waterfall_pct'] / 100; // fraction
            $termMonths         = (int)($terms['term_months'] ?? 36);

            // Load projections to find stabilised period
            $projections = self::getProjections($campaignId);
            if (empty($projections)) {
                return null;
            }

            $stabilisedRow   = self::findStabilisedPeriod($projections);
            $stabilisedNet   = (float)$stabilisedRow['net_to_spv'];
            $stabilisedLabel = $stabilisedRow['label'];

            // Investor's pro-rata share of the raise
            $sharePct = $amount / $raiseTarget;  // e.g. 0.05 for 5%

            // Monthly hurdle amount for the WHOLE campaign
            $monthlyHurdleTotal = ($hurdleRate / 100 * $raiseTarget) / 12;

            // Determine above/below hurdle
            $aboveHurdle = $stabilisedNet >= $monthlyHurdleTotal;

            // Calculate this investor's monthly distribution
            if ($aboveHurdle && $monthlyHurdleTotal > 0) {
                // Hurdle portion distributed pro-rata to all investors
                $hurdlePortionInvestor = $monthlyHurdleTotal * $sharePct;
                // Surplus above hurdle split per waterfall pct, then pro-rata
                $surplus            = $stabilisedNet - $monthlyHurdleTotal;
                $surplusTotal       = $surplus * $investorPct;
                $surplusInvestor    = $surplusTotal * $sharePct;
                $monthlyDistribution = $hurdlePortionInvestor + $surplusInvestor;
            } else {
                // Below hurdle or no hurdle — simple pro-rata × investor_waterfall_pct
                $monthlyDistribution = $stabilisedNet * $investorPct * $sharePct;
            }

            $annualYieldPct = $amount > 0
                ? round(($monthlyDistribution * 12) / $amount * 100, 2)
                : 0;

            return [
                'share_pct'               => round($sharePct * 100, 4),       // e.g. 5.0000
                'monthly_distribution'    => round($monthlyDistribution, 2),   // ZAR
                'annual_yield_pct'        => $annualYieldPct,                  // %
                'above_hurdle'            => $aboveHurdle,
                'hurdle_rate'             => $hurdleRate,                      // % p.a.
                'management_fee_pct'      => (float)($terms['management_fee_pct'] ?? 0),
                'investor_waterfall_pct'  => (float)$terms['investor_waterfall_pct'],
                'stabilised_period_label' => $stabilisedLabel,
                'stabilised_net_income'   => $stabilisedNet,
                'distribution_frequency'  => $terms['distribution_frequency'] ?? 'monthly',
                'term_months'             => $termMonths,
            ];

        } catch (Throwable $e) {
            error_log('[FleetService::calculateDistribution] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // CALCULATE PAYOUTS
    // Pure computation — does NOT write to DB.
    // Called by Team D's payout recording handler.
    //
    // Distributes $totalDistribution pro-rata across all
    // confirmed (paid) contributions for the campaign.
    //
    // @param int   $campaignId
    // @param float $totalDistribution   Total ZAR to split
    // @return array<int, array{user_id: int, contribution_id: int, amount: float}>
    //         Empty array if no confirmed contributions or $totalDistribution <= 0
    // ──────────────────────────────────────────────────────────
    public static function calculatePayouts(int $campaignId, float $totalDistribution): array
    {
        if ($totalDistribution <= 0) {
            return [];
        }

        try {
            $pdo = Database::getInstance();

            // Load all confirmed (wallet-paid) or EFT-confirmed contributions
            $stmt = $pdo->prepare("
                SELECT
                    c.id        AS contribution_id,
                    c.user_id,
                    c.amount
                FROM contributions c
                WHERE c.campaign_id = :cid
                  AND c.status IN ('paid', 'confirmed')
                ORDER BY c.id ASC
            ");
            $stmt->execute(['cid' => $campaignId]);
            $contributions = $stmt->fetchAll();

            if (empty($contributions)) {
                return [];
            }

            $totalRaised = array_sum(array_column($contributions, 'amount'));
            if ($totalRaised <= 0) {
                return [];
            }

            $payouts = [];
            foreach ($contributions as $c) {
                $share   = (float)$c['amount'] / $totalRaised;
                $payout  = round($share * $totalDistribution, 2);

                if ($payout > 0) {
                    $payouts[] = [
                        'user_id'         => (int)$c['user_id'],
                        'contribution_id' => (int)$c['contribution_id'],
                        'amount'          => $payout,
                        'share_pct'       => round($share * 100, 4),
                    ];
                }
            }

            // Correct rounding drift: adjust largest payout so total matches exactly
            $computedTotal = array_sum(array_column($payouts, 'amount'));
            $drift = round($totalDistribution - $computedTotal, 2);
            if (!empty($payouts) && $drift !== 0.00) {
                usort($payouts, fn($a, $b) => $b['amount'] <=> $a['amount']);
                $payouts[0]['amount'] = round($payouts[0]['amount'] + $drift, 2);
            }

            return $payouts;

        } catch (Throwable $e) {
            error_log('[FleetService::calculatePayouts] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return [];
        }
    }

    // ──────────────────────────────────────────────────────────
    // GET OPERATIONAL SUMMARY
    // Returns the most recent campaign_operational_metrics row,
    // enriched with a variance_pct against the corresponding
    // campaign_projections period.
    //
    // Returns null if no metrics have been posted.
    //
    // @param int $campaignId
    // @return array<string, mixed>|null
    // ──────────────────────────────────────────────────────────
    public static function getOperationalSummary(int $campaignId): ?array
    {
        try {
            $pdo = Database::getInstance();

            $stmt = $pdo->prepare("
                SELECT
                    com.*,
                    CONCAT(com.period_year, '-', LPAD(com.period_month, 2, '0')) AS period_key
                FROM campaign_operational_metrics com
                WHERE com.campaign_id = :cid
                ORDER BY com.period_year DESC, com.period_month DESC
                LIMIT 1
            ");
            $stmt->execute(['cid' => $campaignId]);
            $metrics = $stmt->fetch();

            if (!$metrics) {
                return null;
            }

            // Look up the corresponding projection period by number
            // (period_number in projections maps to months since campaign open)
            // We compute an approximate period_number from oldest metric in the set
            $stmt2 = $pdo->prepare("
                SELECT COUNT(*) AS months_elapsed
                FROM campaign_operational_metrics
                WHERE campaign_id = :cid
                  AND (period_year < :py OR (period_year = :py2 AND period_month <= :pm))
            ");
            $stmt2->execute([
                'cid' => $campaignId,
                'py'  => $metrics['period_year'],
                'py2' => $metrics['period_year'],
                'pm'  => $metrics['period_month'],
            ]);
            $periodNumber = max(1, (int)$stmt2->fetchColumn());

            // Find the matching projection row
            $stmt3 = $pdo->prepare("
                SELECT net_to_spv AS projected_net, investor_distribution AS projected_distribution
                FROM campaign_projections
                WHERE campaign_id = :cid AND period_number = :pn
                LIMIT 1
            ");
            $stmt3->execute(['cid' => $campaignId, 'pn' => $periodNumber]);
            $projection = $stmt3->fetch();

            // Calculate variance
            $projectedNet  = $projection ? (float)$projection['projected_net'] : null;
            $actualNet     = (float)$metrics['net_income_actual'];
            $variancePct   = null;

            if ($projectedNet !== null && $projectedNet > 0) {
                $variancePct = round((($actualNet - $projectedNet) / $projectedNet) * 100, 2);
            }

            return array_merge($metrics, [
                'projected_net_income'          => $projectedNet,
                'projected_investor_distribution'=> $projection ? (float)$projection['projected_distribution'] : null,
                'variance_pct'                  => $variancePct,
                'period_number'                 => $periodNumber,
            ]);

        } catch (Throwable $e) {
            error_log('[FleetService::getOperationalSummary] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return null;
        }
    }

    // ──────────────────────────────────────────────────────────
    // BULK UPSERT PROJECTIONS
    // Called by Team C's wizard Step 7 save handler.
    // Inserts or updates projection rows for a campaign.
    //
    // $rows format (array of arrays):
    //   period_number, label, gross_revenue_projected,
    //   energy_cost, maintenance_reserve, insurance_cost,
    //   management_fee, opex_total, net_to_spv,
    //   investor_distribution, hurdle_cleared, notes (optional)
    //
    // Uses INSERT ... ON DUPLICATE KEY UPDATE to handle re-saves.
    // Wraps in a transaction — all rows succeed or all are rolled back.
    //
    // @param int   $campaignId
    // @param array $rows
    // @return bool  true on success
    // ──────────────────────────────────────────────────────────
    public static function bulkUpsertProjections(int $campaignId, array $rows): bool
    {
        if (empty($rows)) {
            return true;
        }

        $pdo = Database::getInstance();
        $pdo->beginTransaction();

        try {
            $stmt = $pdo->prepare("
                INSERT INTO campaign_projections (
                    campaign_id, period_number, label,
                    gross_revenue_projected,
                    energy_cost, maintenance_reserve,
                    insurance_cost, management_fee,
                    opex_total, net_to_spv,
                    investor_distribution, hurdle_cleared, notes
                ) VALUES (
                    :cid, :period_number, :label,
                    :gross_revenue_projected,
                    :energy_cost, :maintenance_reserve,
                    :insurance_cost, :management_fee,
                    :opex_total, :net_to_spv,
                    :investor_distribution, :hurdle_cleared, :notes
                )
                ON DUPLICATE KEY UPDATE
                    label                    = VALUES(label),
                    gross_revenue_projected  = VALUES(gross_revenue_projected),
                    energy_cost              = VALUES(energy_cost),
                    maintenance_reserve      = VALUES(maintenance_reserve),
                    insurance_cost           = VALUES(insurance_cost),
                    management_fee           = VALUES(management_fee),
                    opex_total               = VALUES(opex_total),
                    net_to_spv               = VALUES(net_to_spv),
                    investor_distribution    = VALUES(investor_distribution),
                    hurdle_cleared           = VALUES(hurdle_cleared),
                    notes                    = VALUES(notes),
                    updated_at               = NOW()
            ");

            foreach ($rows as $row) {
                if ((int)($row['period_number'] ?? 0) < 1 || (int)($row['period_number'] ?? 0) > 60) {
                    continue; // silently skip out-of-range periods
                }

                $stmt->execute([
                    'cid'                       => $campaignId,
                    'period_number'             => (int)$row['period_number'],
                    'label'                     => trim($row['label'] ?? 'Month ' . $row['period_number']),
                    'gross_revenue_projected'   => (float)($row['gross_revenue_projected'] ?? 0),
                    'energy_cost'               => (float)($row['energy_cost'] ?? 0),
                    'maintenance_reserve'       => (float)($row['maintenance_reserve'] ?? 0),
                    'insurance_cost'            => (float)($row['insurance_cost'] ?? 0),
                    'management_fee'            => (float)($row['management_fee'] ?? 0),
                    'opex_total'                => (float)($row['opex_total'] ?? 0),
                    'net_to_spv'                => (float)($row['net_to_spv'] ?? 0),
                    'investor_distribution'     => (float)($row['investor_distribution'] ?? 0),
                    'hurdle_cleared'            => (int)(bool)($row['hurdle_cleared'] ?? false),
                    'notes'                     => $row['notes'] ?? null,
                ]);
            }

            $pdo->commit();
            return true;

        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[FleetService::bulkUpsertProjections] campaign=' . $campaignId . ' — ' . $e->getMessage());
            return false;
        }
    }

    // ──────────────────────────────────────────────────────────
    // BUILD FLEET PARAMS JSON
    // Produces the FLEET_PARAMS JSON block that Team B injects
    // into invest/campaign.php and invest/start.php for the
    // client-side distribution calculator.
    //
    // Contract with Team B — this shape is fixed:
    //   isFleet, totalRaise, hurdleRate, investorWaterfallPct,
    //   managementFeePct, managementFeeBasis, stabilisedNetIncome,
    //   stabilisedPeriodLabel, distributionFrequency, termMonths,
    //   minContribution, maxContribution
    //
    // If campaign is not fleet_asset, returns ['isFleet' => false]
    // with all other keys set to null.
    //
    // @param int  $campaignId
    // @param array $campaign   Row from getCampaignForInvestment()
    // @return array<string, mixed>
    // ──────────────────────────────────────────────────────────
    public static function buildFleetParamsJson(int $campaignId, array $campaign): array
    {
        $notFleet = [
            'isFleet'                => false,
            'totalRaise'             => null,
            'hurdleRate'             => null,
            'investorWaterfallPct'   => null,
            'managementFeePct'       => null,
            'managementFeeBasis'     => null,
            'stabilisedNetIncome'    => null,
            'stabilisedPeriodLabel'  => null,
            'distributionFrequency'  => null,
            'termMonths'             => null,
            'minContribution'        => null,
            'maxContribution'        => null,
        ];

        if (($campaign['campaign_type'] ?? '') !== 'fleet_asset') {
            return $notFleet;
        }

        if (($campaign['investor_waterfall_pct'] ?? null) === null) {
            return $notFleet;
        }

        $projections = self::getProjections($campaignId);
        $stabilised  = !empty($projections) ? self::findStabilisedPeriod($projections) : null;

        return [
            'isFleet'                => true,
            'totalRaise'             => (float)($campaign['raise_target'] ?? 0),
            'hurdleRate'             => (float)($campaign['hurdle_rate'] ?? 0),
            'investorWaterfallPct'   => (float)($campaign['investor_waterfall_pct'] ?? 0),
            'managementFeePct'       => (float)($campaign['management_fee_pct'] ?? 0),
            'managementFeeBasis'     => $campaign['management_fee_basis'] ?? 'gross',
            'stabilisedNetIncome'    => $stabilised ? (float)$stabilised['net_to_spv'] : null,
            'stabilisedPeriodLabel'  => $stabilised ? $stabilised['label'] : null,
            'distributionFrequency'  => $campaign['distribution_frequency'] ?? 'monthly',
            'termMonths'             => (int)($campaign['term_months'] ?? 36),
            'minContribution'        => (float)($campaign['min_contribution'] ?? 0),
            'maxContribution'        => $campaign['max_contribution'] ? (float)$campaign['max_contribution'] : null,
        ];
    }

    // ──────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ──────────────────────────────────────────────────────────

    /**
     * Find the stabilised period from a projections array.
     *
     * Priority:
     *   1. Last period where hurdle_cleared = 1
     *   2. Period with highest net_to_spv (stabilisation plateau)
     *   3. Fallback: period index 5 (month 6) or last period if < 6
     */
    private static function findStabilisedPeriod(array $projections): array
    {
        // Try last hurdle-cleared period
        $hurclePeriods = array_filter($projections, fn($r) => (bool)$r['hurdle_cleared']);
        if (!empty($hurclePeriods)) {
            return end($hurclePeriods);
        }

        // Try highest net_to_spv
        usort($projections, fn($a, $b) => (float)$b['net_to_spv'] <=> (float)$a['net_to_spv']);
        if ((float)$projections[0]['net_to_spv'] > 0) {
            return $projections[0];
        }

        // Fallback: period index 5 (month 6) or last
        usort($projections, fn($a, $b) => $a['period_number'] <=> $b['period_number']);
        return $projections[min(5, count($projections) - 1)];
    }

    /**
     * Look up a user's invite status for a given campaign.
     * Returns: 'accepted' | 'pending' | 'declined' | 'revoked' | 'none'
     */
    private static function getInviteStatus(PDO $pdo, int $campaignId, int $userId): string
    {
        try {
            $stmt = $pdo->prepare("
                SELECT status FROM campaign_invites
                WHERE campaign_id = :cid AND user_id = :uid
                LIMIT 1
            ");
            $stmt->execute(['cid' => $campaignId, 'uid' => $userId]);
            return $stmt->fetchColumn() ?: 'none';
        } catch (Throwable $e) {
            return 'none';
        }
    }
}