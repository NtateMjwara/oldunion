<?php
/**
 * /app/includes/_portfolio_watchlist.php
 *
 * US-704 — Portfolio Dashboard: Watchlist Tab
 * Team D — Phase 3
 *
 * INTEGRATION INTO app/index.php (see _portfolio_fleet.php header for full instructions):
 *   $watchlistItems = WatchlistService::getWatchlistForUser($pdo, $userId);
 *
 *   <div id="watchlist" class="tab-content">
 *       <?php require __DIR__ . '/includes/_portfolio_watchlist.php'; ?>
 *   </div>
 *
 * VARIABLES EXPECTED FROM CALLER:
 *   $watchlistItems  array  — from WatchlistService::getWatchlistForUser($pdo, $userId)
 *   $pdo             PDO
 *   $userId          int
 *   $csrf_token      string — already generated in index.php
 */

if (!isset($watchlistItems, $pdo, $userId, $csrf_token)) {
    echo '<p style="color:#b91c1c;padding:1rem;">_portfolio_watchlist.php: required variables not in scope.</p>';
    return;
}

function wl_money(mixed $v): string {
    return ($v === null || $v === '') ? '—' : 'R ' . number_format((float)$v, 0, '.', ' ');
}
?>

<style>
/* Scoped .wlt- (watchlist tab) */
.wlt-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:1rem;}
.wlt-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);overflow:hidden;display:flex;flex-direction:column;}
.wlt-card-head{padding:.9rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.7rem;}
.wlt-logo{width:36px;height:36px;border-radius:8px;background:linear-gradient(135deg,var(--navy-mid),var(--navy-light));display:flex;align-items:center;justify-content:center;font-family:'DM Serif Display',serif;font-size:.95rem;color:#fff;flex-shrink:0;overflow:hidden;}
.wlt-logo img{width:100%;height:100%;object-fit:cover;}
.wlt-company-name{font-size:.9rem;font-weight:600;color:var(--navy);margin-bottom:.1rem;}
.wlt-company-meta{font-size:.72rem;color:var(--text-muted);}
.wlt-card-body{padding:.8rem 1rem;flex:1;display:flex;flex-direction:column;gap:.55rem;}
/* Campaign strip inside watchlist card */
.wlt-campaign-strip{background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.6rem .75rem;}
.wlt-campaign-title{font-size:.8rem;font-weight:600;color:var(--navy);margin-bottom:.3rem;display:flex;align-items:center;gap:.3rem;}
.wlt-prog-outer{height:5px;background:var(--border);border-radius:99px;overflow:hidden;margin-bottom:.3rem;}
.wlt-prog-inner{height:100%;border-radius:99px;}
.wlt-prog-open  {background:var(--amber);}
.wlt-prog-funded{background:var(--green);}
.wlt-prog-stats{display:flex;justify-content:space-between;font-size:.71rem;color:var(--text-light);}
/* Invited badge */
.wlt-invited{display:inline-flex;align-items:center;gap:.25rem;padding:.2rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;background:var(--green-bg);color:var(--green);border:1px solid var(--green-bdr);}
.wlt-no-campaign{font-size:.78rem;color:var(--text-light);font-style:italic;padding:.4rem 0;}
/* Actions */
.wlt-actions{display:flex;gap:.4rem;flex-wrap:wrap;margin-top:.4rem;}
.wlt-btn{display:inline-flex;align-items:center;gap:.3rem;padding:.32rem .75rem;border-radius:99px;font-size:.78rem;font-weight:600;border:none;cursor:pointer;text-decoration:none;transition:all .2s;font-family:'DM Sans',sans-serif;}
.wlt-btn-view{background:#eff4ff;color:var(--navy-mid);}
.wlt-btn-view:hover{background:var(--navy-mid);color:#fff;}
.wlt-btn-remove{background:transparent;color:var(--text-light);border:1px solid var(--border);}
.wlt-btn-remove:hover{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
/* Empty state */
.wlt-empty{text-align:center;padding:3rem 1.5rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);}
.wlt-empty i{font-size:1.75rem;color:var(--text-light);opacity:.4;display:block;margin-bottom:.65rem;}
</style>

<?php if (empty($watchlistItems)): ?>
<div class="wlt-empty">
    <i class="fa-regular fa-heart"></i>
    <div style="font-size:.9rem;font-weight:600;color:var(--text-muted);margin-bottom:.35rem;">No companies on your watchlist</div>
    <div style="font-size:.82rem;color:var(--text-light);max-width:340px;margin:0 auto;line-height:1.55;">
        Tap the <strong>Watch</strong> heart on any company profile in
        <a href="/app/discover/" style="color:var(--navy-light);">Discover</a>
        to follow them. You'll be notified when they launch new investment campaigns.
    </div>
</div>
<?php else: ?>

<div style="font-size:.83rem;color:var(--text-muted);margin-bottom:1.1rem;">
    You are watching <strong><?php echo count($watchlistItems); ?></strong> operator<?php echo count($watchlistItems) !== 1 ? 's' : ''; ?>.
    You'll be notified when any of them launch a new campaign.
</div>

<div class="wlt-grid">
<?php foreach ($watchlistItems as $wi):
    $logo = $wi['logo'] ?? '';
    $hasCampaign = !empty($wi['latest_campaign_id']);
    $isInvited   = (bool)($wi['is_invited'] ?? false);
    $pct = 0;
    if ($hasCampaign && (float)($wi['raise_target'] ?? 0) > 0) {
        $pct = min(100, round(((float)$wi['total_raised'] / (float)$wi['raise_target']) * 100));
    }
    $campaignStatus = $wi['campaign_status'] ?? '';
    $isFunded = $campaignStatus === 'funded';
?>
<div class="wlt-card">
    <div class="wlt-card-head">
        <div class="wlt-logo">
            <?php if ($logo): ?>
                <img src="<?php echo htmlspecialchars($logo); ?>" alt="">
            <?php else: ?>
                <?php echo strtoupper(substr($wi['company_name'], 0, 1)); ?>
            <?php endif; ?>
        </div>
        <div>
            <div class="wlt-company-name"><?php echo htmlspecialchars($wi['company_name']); ?></div>
            <div class="wlt-company-meta">
                <?php echo htmlspecialchars($wi['industry'] ?? ''); ?>
                <?php if ($wi['industry'] && $wi['company_type']): ?> · <?php endif; ?>
                <?php echo htmlspecialchars(ucfirst($wi['company_type'] ?? '')); ?>
            </div>
        </div>
    </div>

    <div class="wlt-card-body">
        <?php if ($hasCampaign): ?>
        <div class="wlt-campaign-strip">
            <div class="wlt-campaign-title">
                <i class="fa-solid fa-rocket" style="font-size:.65rem;color:var(--navy-light);"></i>
                <?php echo htmlspecialchars($wi['latest_campaign_title']); ?>
                <?php if ($isInvited): ?>
                    <span class="wlt-invited"><i class="fa-solid fa-circle-check" style="font-size:.6rem;"></i> Invited</span>
                <?php endif; ?>
            </div>
            <div class="wlt-prog-outer">
                <div class="wlt-prog-inner <?php echo $isFunded ? 'wlt-prog-funded' : 'wlt-prog-open'; ?>"
                     style="width:<?php echo $pct; ?>%"></div>
            </div>
            <div class="wlt-prog-stats">
                <span><?php echo wl_money($wi['total_raised']); ?> raised</span>
                <span><?php echo $pct; ?>% · <?php echo (int)($wi['contributor_count'] ?? 0); ?>/<?php echo (int)($wi['max_contributors'] ?? 50); ?></span>
            </div>
        </div>

        <div class="wlt-actions">
            <?php if ($isInvited): ?>
                <a href="/app/invest/campaign.php?cid=<?php echo urlencode($wi['latest_campaign_uuid']); ?>"
                   class="wlt-btn wlt-btn-view">
                    <i class="fa-solid fa-arrow-up-right-from-square" style="font-size:.7rem;"></i> View Campaign
                </a>
            <?php else: ?>
                <a href="/app/invest/company.php?uuid=<?php echo urlencode($wi['company_uuid']); ?>"
                   class="wlt-btn wlt-btn-view">
                    <i class="fa-solid fa-building" style="font-size:.7rem;"></i> View Company
                </a>
            <?php endif; ?>

            <form method="POST" action="/app/watchlist/toggle.php" style="display:contents;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="company_id" value="<?php echo (int)$wi['company_id']; ?>">
                <button type="submit" class="wlt-btn wlt-btn-remove"
                        onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($wi['company_name'])); ?> from your watchlist?')">
                    <i class="fa-regular fa-heart-broken" style="font-size:.7rem;"></i> Unwatch
                </button>
            </form>
        </div>

        <?php else: ?>
        <div class="wlt-no-campaign">No active campaigns right now. You'll be notified when one launches.</div>
        <div class="wlt-actions">
            <a href="/app/invest/company.php?uuid=<?php echo urlencode($wi['company_uuid']); ?>"
               class="wlt-btn wlt-btn-view">
                <i class="fa-solid fa-building" style="font-size:.7rem;"></i> View Company
            </a>
            <form method="POST" action="/app/watchlist/toggle.php" style="display:contents;">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="company_id" value="<?php echo (int)$wi['company_id']; ?>">
                <button type="submit" class="wlt-btn wlt-btn-remove"
                        onclick="return confirm('Remove <?php echo htmlspecialchars(addslashes($wi['company_name'])); ?> from your watchlist?')">
                    <i class="fa-regular fa-heart-broken" style="font-size:.7rem;"></i> Unwatch
                </button>
            </form>
        </div>
        <?php endif; ?>

        <div style="font-size:.72rem;color:var(--text-light);margin-top:auto;padding-top:.4rem;">
            Watching since <?php echo date('d M Y', strtotime($wi['watched_since'])); ?>
        </div>
    </div>
</div>
<?php endforeach; ?>
</div>

<?php endif; ?>
