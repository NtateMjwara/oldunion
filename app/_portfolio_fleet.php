<?php
/**
 * /app/includes/_portfolio_fleet.php
 *
 * US-702 — Portfolio Dashboard: Fleet Investments + Payout History
 * US-704 — Portfolio Dashboard: Watchlist Tab
 * Team D — Phase 3
 *
 * INTEGRATION INTO app/index.php:
 *
 *   1. Add requires at top:
 *        require_once 'includes/PayoutService.php';
 *        require_once 'includes/WatchlistService.php';
 *
 *   2. Add portfolio data loading:
 *        $fleetSummaries  = PayoutService::getFleetInvestmentSummary($userId);
 *        $payoutHistory   = PayoutService::getPayoutsForUser($userId);
 *        $pendingTotal    = PayoutService::getPendingTotal($userId);
 *        $watchlistItems  = WatchlistService::getWatchlistForUser($pdo, $userId);
 *
 *   3. Add tab buttons (alongside existing "Portfolio" / "Payouts" tabs):
 *        <button class="tab <?= $tab==='fleet' ? 'active' : ''; ?>"
 *                onclick="switchTab('fleet')">
 *            <i class="fa-solid fa-truck"></i> Fleet
 *            <?php if (!empty($fleetSummaries)): ?>
 *            <span class="tab-count"><?= count($fleetSummaries); ?></span>
 *            <?php endif; ?>
 *        </button>
 *        <button class="tab <?= $tab==='watchlist' ? 'active' : ''; ?>"
 *                onclick="switchTab('watchlist')">
 *            <i class="fa-solid fa-heart"></i> Watchlist
 *            <?php if (!empty($watchlistItems)): ?>
 *            <span class="tab-count"><?= count($watchlistItems); ?></span>
 *            <?php endif; ?>
 *        </button>
 *
 *   4. Add tab panels:
 *        <div id="fleet" class="tab-content <?= $tab==='fleet'?'active':''; ?>">
 *            <?php require __DIR__ . '/includes/_portfolio_fleet.php'; ?>
 *        </div>
 *        <div id="watchlist" class="tab-content <?= $tab==='watchlist'?'active':''; ?>">
 *            <?php require __DIR__ . '/includes/_portfolio_watchlist.php'; ?>
 *        </div>
 *
 * VARIABLES EXPECTED FROM CALLER:
 *   $fleetSummaries  array   — from PayoutService::getFleetInvestmentSummary($userId)
 *   $payoutHistory   array   — from PayoutService::getPayoutsForUser($userId)
 *   $pendingTotal    float   — from PayoutService::getPendingTotal($userId)
 *   $userId          int
 *   $pdo             PDO
 */

if (!isset($fleetSummaries, $payoutHistory, $pendingTotal, $userId)) {
    echo '<p style="color:#b91c1c;padding:1rem;">_portfolio_fleet.php: required variables not in scope.</p>';
    return;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function pf_money(mixed $v): string {
    return ($v === null || $v === '') ? '—' : 'R ' . number_format((float)$v, 2, '.', ' ');
}
function pf_money0(mixed $v): string {
    return ($v === null || $v === '') ? '—' : 'R ' . number_format((float)$v, 0, '.', ' ');
}
function pf_pct(mixed $v): string {
    return ($v === null) ? '—' : number_format((float)$v, 3) . '%';
}
?>

<style>
/* All scoped under .pf- (portfolio fleet) */
.pf-summary-bar{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:1rem;margin-bottom:1.75rem;}
.pf-summary-item{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.9rem 1.1rem;box-shadow:var(--shadow);}
.pf-summary-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.3rem;}
.pf-summary-value{font-size:1.15rem;font-weight:700;color:var(--navy);line-height:1.1;}
.pf-summary-value.pending{color:var(--amber-dark);}
.pf-summary-sub{font-size:.73rem;color:var(--text-muted);margin-top:.2rem;}
/* Section heading */
.pf-section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem;gap:.5rem;flex-wrap:wrap;}
.pf-section-title{font-size:.83rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--navy);display:flex;align-items:center;gap:.4rem;}
.pf-section-title i{color:var(--navy-light);}
/* Investment card */
.pf-invest-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1rem;}
.pf-invest-head{display:flex;align-items:flex-start;gap:.85rem;padding:1rem 1.1rem;border-bottom:1px solid var(--border);}
.pf-logo{width:40px;height:40px;border-radius:8px;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;flex-shrink:0;overflow:hidden;}
.pf-logo img{width:100%;height:100%;object-fit:cover;}
.pf-invest-meta{flex:1;min-width:0;}
.pf-invest-company{font-size:.73rem;color:var(--text-muted);margin-bottom:.15rem;}
.pf-invest-title{font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:.35rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.pf-invest-badges{display:flex;flex-wrap:wrap;gap:.3rem;}
.pf-badge{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .55rem;border-radius:99px;font-size:.71rem;font-weight:600;border:1px solid transparent;}
.pf-b-fleet{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
.pf-b-active{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.pf-b-funded{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.pf-b-closed{background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
/* Metrics grid */
.pf-metrics-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:0;border-top:1px solid var(--border);}
.pf-metric{padding:.65rem .9rem;border-right:1px solid var(--border);display:flex;flex-direction:column;gap:.15rem;}
.pf-metric:last-child{border-right:none;}
.pf-metric-label{font-size:.69rem;font-weight:600;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);}
.pf-metric-value{font-size:.88rem;font-weight:700;color:var(--text);}
.pf-metric-value.pos{color:var(--green);}
.pf-metric-value.neg{color:var(--error);}
.pf-metric-value.pending{color:var(--amber-dark);}
/* Trend indicator */
.pf-trend{display:inline-flex;align-items:center;gap:.25rem;font-size:.72rem;font-weight:600;padding:.1rem .5rem;border-radius:99px;}
.pf-trend-up{background:var(--green-bg);color:var(--green);}
.pf-trend-down{background:var(--error-bg);color:var(--error);}
.pf-trend-mixed{background:#f1f5f9;color:#64748b;}
/* Campaign link */
.pf-campaign-link{display:inline-flex;align-items:center;gap:.3rem;font-size:.79rem;font-weight:600;color:var(--navy-mid);text-decoration:none;padding:.28rem .7rem;background:#eff4ff;border-radius:99px;transition:all .2s;white-space:nowrap;}
.pf-campaign-link:hover{background:var(--navy-mid);color:#fff;}
/* Payout history */
.pf-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:1rem;}
.pf-table th{text-align:left;padding:.5rem .65rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.pf-table th.r{text-align:right;}
.pf-table td{padding:.6rem .65rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.pf-table tr:last-child td{border-bottom:none;}
.pf-table tr:hover td{background:#fafbfc;}
.pf-table td.r{text-align:right;font-variant-numeric:tabular-nums;}
.pf-status-credited{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:99px;font-size:.71rem;font-weight:600;background:var(--green-bg);color:var(--green);border:1px solid var(--green-bdr);}
.pf-status-pending {display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:99px;font-size:.71rem;font-weight:600;background:var(--amber-light);color:#78350f;border:1px solid var(--amber);}
/* Empty state */
.pf-empty{text-align:center;padding:3rem 1.5rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);}
.pf-empty i{font-size:1.75rem;color:var(--text-light);opacity:.4;display:block;margin-bottom:.65rem;}
.pf-empty-title{font-size:.9rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem;}
.pf-empty-desc{font-size:.8rem;color:var(--text-light);line-height:1.5;}
</style>

<!-- ── Summary metrics bar ───────────────────────────────────────────── -->
<?php
$totalInvested   = array_sum(array_column($fleetSummaries, 'invested_amount'));
$totalReceived   = array_sum(array_column($fleetSummaries, 'total_received'));
$activeCount     = count(array_filter($fleetSummaries, fn($s) => in_array($s['campaign_status'], ['open','funded'])));
?>

<div class="pf-summary-bar">
    <div class="pf-summary-item">
        <div class="pf-summary-label">Fleet Invested</div>
        <div class="pf-summary-value"><?php echo pf_money0($totalInvested); ?></div>
        <div class="pf-summary-sub"><?php echo count($fleetSummaries); ?> campaign<?php echo count($fleetSummaries) !== 1 ? 's' : ''; ?></div>
    </div>
    <div class="pf-summary-item">
        <div class="pf-summary-label">Distributions Received</div>
        <div class="pf-summary-value"><?php echo pf_money0($totalReceived); ?></div>
        <div class="pf-summary-sub">Total to date</div>
    </div>
    <?php if ($pendingTotal > 0): ?>
    <div class="pf-summary-item">
        <div class="pf-summary-label">Pending Approval</div>
        <div class="pf-summary-value pending"><?php echo pf_money0($pendingTotal); ?></div>
        <div class="pf-summary-sub">Awaiting admin credit</div>
    </div>
    <?php endif; ?>
    <div class="pf-summary-item">
        <div class="pf-summary-label">Active Campaigns</div>
        <div class="pf-summary-value"><?php echo $activeCount; ?></div>
        <div class="pf-summary-sub">Open or funded</div>
    </div>
</div>

<!-- ── Fleet investment cards ────────────────────────────────────────── -->
<?php if (empty($fleetSummaries)): ?>
<div class="pf-empty">
    <i class="fa-solid fa-truck"></i>
    <div class="pf-empty-title">No fleet investments yet</div>
    <div class="pf-empty-desc">
        Browse fleet operator campaigns on the <a href="/app/discover/" style="color:var(--navy-light);">Discover</a> page.
        You'll need an invitation from a company to invest.
    </div>
</div>
<?php else: ?>

<div class="pf-section-head">
    <div class="pf-section-title"><i class="fa-solid fa-truck-fast"></i> Your Fleet Investments</div>
</div>

<?php foreach ($fleetSummaries as $inv):
    $isActive  = in_array($inv['campaign_status'], ['open','funded']);
    $badgeClass = $inv['campaign_status'] === 'funded' ? 'pf-b-funded'
                : ($isActive ? 'pf-b-active' : 'pf-b-closed');
    $badgeLabel = ucfirst($inv['campaign_status']);
    $logo = $inv['company_logo'] ?? '';
    $trend = $inv['trend_3m'];
?>
<div class="pf-invest-card">
    <div class="pf-invest-head">
        <div class="pf-logo">
            <?php if ($logo): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="">
            <?php else: ?>
                <?php echo strtoupper(substr($inv['company_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <div class="pf-invest-meta">
            <div class="pf-invest-company"><?php echo htmlspecialchars($inv['company_name']); ?></div>
            <div class="pf-invest-title"><?php echo htmlspecialchars($inv['campaign_title']); ?></div>
            <div class="pf-invest-badges">
                <span class="pf-badge pf-b-fleet">
                    <i class="fa-solid fa-truck" style="font-size:.65rem;"></i>
                    Fleet<?php echo !empty($inv['asset_type']) ? ' · ' . htmlspecialchars($inv['asset_type']) : ''; ?>
                </span>
                <span class="pf-badge <?php echo $badgeClass; ?>"><?php echo $badgeLabel; ?></span>
                <?php if ($trend): ?>
                <span class="pf-trend pf-trend-<?php echo $trend; ?>">
                    <?php if ($trend === 'up'): ?><i class="fa-solid fa-arrow-trend-up" style="font-size:.68rem;"></i> Above projection
                    <?php elseif ($trend === 'down'): ?><i class="fa-solid fa-arrow-trend-down" style="font-size:.68rem;"></i> Below projection
                    <?php else: ?><i class="fa-solid fa-minus" style="font-size:.68rem;"></i> Mixed
                    <?php endif; ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <div style="flex-shrink:0;">
            <a href="/app/invest/campaign.php?cid=<?php echo urlencode($inv['campaign_uuid']); ?>"
               class="pf-campaign-link">
                <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.7rem;"></i> View Deal Room
            </a>
        </div>
    </div>

    <div class="pf-metrics-grid">
        <div class="pf-metric">
            <span class="pf-metric-label">Your Investment</span>
            <span class="pf-metric-value"><?php echo pf_money0($inv['invested_amount']); ?></span>
        </div>
        <div class="pf-metric">
            <span class="pf-metric-label">Your Share</span>
            <span class="pf-metric-value"><?php echo pf_pct($inv['share_pct']); ?></span>
        </div>
        <div class="pf-metric">
            <span class="pf-metric-label">Latest Actual Distrib.</span>
            <span class="pf-metric-value pos"><?php echo pf_money($inv['last_actual_distrib']); ?></span>
        </div>
        <div class="pf-metric">
            <span class="pf-metric-label">Projected Monthly</span>
            <span class="pf-metric-value"><?php echo pf_money($inv['projected_monthly']); ?></span>
        </div>
        <div class="pf-metric">
            <span class="pf-metric-label">Total Received</span>
            <span class="pf-metric-value pos"><?php echo pf_money0($inv['total_received']); ?></span>
        </div>
        <?php if ((float)$inv['pending_amount'] > 0): ?>
        <div class="pf-metric">
            <span class="pf-metric-label">Pending</span>
            <span class="pf-metric-value pending">
                <?php echo pf_money0($inv['pending_amount']); ?>
                <span style="font-size:.65rem;font-weight:400;color:var(--text-light);display:block;">Awaiting approval</span>
            </span>
        </div>
        <?php endif; ?>
        <?php if (!empty($inv['latest_metrics']['utilisation_rate_pct'])): ?>
        <div class="pf-metric">
            <span class="pf-metric-label">Latest Utilisation</span>
            <span class="pf-metric-value"><?php echo number_format((float)$inv['latest_metrics']['utilisation_rate_pct'], 1); ?>%</span>
        </div>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>

<?php endif; // end fleet investments ?>

<!-- ── Payout history table ──────────────────────────────────────────── -->
<?php if (!empty($payoutHistory)): ?>
<div class="pf-section-head" style="margin-top:1.75rem;">
    <div class="pf-section-title"><i class="fa-solid fa-coins"></i> Payout History</div>
</div>
<div style="overflow-x:auto;">
    <table class="pf-table">
        <thead>
            <tr>
                <th>Period</th>
                <th>Campaign</th>
                <th class="r">Your Amount</th>
                <th class="r">Your Share</th>
                <th>Status</th>
                <th class="r">Date</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($payoutHistory as $ph): ?>
        <tr>
            <td style="font-weight:600;color:var(--navy);white-space:nowrap;"><?php echo htmlspecialchars($ph['period_label']); ?></td>
            <td style="color:var(--text-muted);">
                <a href="/app/invest/campaign.php?cid=<?php echo urlencode($ph['campaign_uuid']); ?>"
                   style="color:var(--navy-light);text-decoration:none;font-weight:500;">
                    <?php echo htmlspecialchars($ph['campaign_title']); ?>
                </a>
            </td>
            <td class="r" style="font-weight:600;"><?php echo pf_money($ph['amount']); ?></td>
            <td class="r" style="color:var(--text-muted);"><?php echo pf_pct($ph['share_pct']); ?></td>
            <td>
                <?php if ($ph['line_status'] === 'credited'): ?>
                    <span class="pf-status-credited"><i class="fa-solid fa-circle-check" style="font-size:.65rem;"></i> Credited</span>
                <?php else: ?>
                    <span class="pf-status-pending"><i class="fa-regular fa-clock" style="font-size:.65rem;"></i> Pending approval</span>
                <?php endif; ?>
            </td>
            <td class="r" style="color:var(--text-light);white-space:nowrap;font-size:.78rem;">
                <?php echo $ph['credited_at'] ? date('d M Y', strtotime($ph['credited_at'])) : '—'; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
