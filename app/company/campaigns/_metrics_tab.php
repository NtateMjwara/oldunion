<?php
/**
 * /app/company/campaigns/_metrics_tab.php
 *
 * US-501 — Operational Metrics Reporting Tab (Fleet campaigns only)
 * US-503 — Notify investors when metrics are posted
 * Team D — Phase 2
 *
 * INTEGRATION INTO manage.php (Team C):
 *   1. Add to requires at top of manage.php:
 *        require_once '../../includes/NotificationService.php';
 *
 *   2. Add "Metrics" tab button (fleet only, after existing tabs):
 *        <?php if ($isFleet): ?>
 *        <button class="tab <?php echo $tab==='metrics'?'active':''; ?>"
 *                onclick="switchTab('metrics')">
 *            <i class="fa-solid fa-chart-bar"></i> Metrics
 *        </button>
 *        <?php endif; ?>
 *
 *   3. Add tab content panel:
 *        <?php if ($isFleet): ?>
 *        <div id="metrics" class="tab-content <?php echo $tab==='metrics'?'active':''; ?>">
 *            <?php require __DIR__ . '/_metrics_tab.php'; ?>
 *        </div>
 *        <?php endif; ?>
 *
 * VARIABLES EXPECTED FROM CALLER (manage.php scope):
 *   $pdo          PDO     — singleton
 *   $campaign     array   — campaign row (id, uuid, company_id, title, status, closes_at)
 *   $userId       int     — currently logged-in user (company admin)
 *   $csrf_token   string  — already generated in manage.php
 *
 * SCHEMA REQUIRED:
 *   campaign_operational_metrics (Team A migration US-105)
 *   notifications (migration 006)
 *   campaign_invites (existing)
 *
 * NOTE: This file is responsible for both the POST handler and the rendered HTML.
 *   It checks for $tab === 'metrics' before running the POST handler so that
 *   it doesn't interfere with other manage.php form submissions.
 */

if (!isset($pdo, $campaign, $userId, $csrf_token)) {
    echo '<div style="color:#b91c1c;padding:1rem;">_metrics_tab.php: required variables not in scope.</div>';
    return;
}

$campaignId = (int)$campaign['id'];

// ── Helpers ──────────────────────────────────────────────────────────────────
function m_money(mixed $v): string {
    return ($v === null || $v === '') ? '—' : 'R ' . number_format((float)$v, 0, '.', ' ');
}
function m_pct(mixed $v): string {
    return ($v === null || $v === '') ? '—' : number_format((float)$v, 1) . '%';
}

$monthNames = [1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
               7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'];

// ── POST handler ──────────────────────────────────────────────────────────────
$mErrors  = [];
$mSuccess = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['metrics_submit'])) {

    // CSRF (manage.php validates the token first; we re-check defensively)
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $mErrors[] = 'Invalid security token. Please refresh and try again.';
    } else {
        $periodYear  = (int)($_POST['period_year']  ?? 0);
        $periodMonth = (int)($_POST['period_month'] ?? 0);
        $editId      = (int)($_POST['edit_id']      ?? 0); // 0 = new record

        // Validate period
        if ($periodYear < 2020 || $periodYear > (int)date('Y') + 1) {
            $mErrors[] = 'Please enter a valid year (2020–' . ((int)date('Y') + 1) . ').';
        }
        if ($periodMonth < 1 || $periodMonth > 12) {
            $mErrors[] = 'Please select a valid month.';
        }

        // Numeric fields
        $numFields = [
            'active_asset_count'            => ['Active asset count',            0, 999,    false],
            'utilisation_rate_pct'          => ['Utilisation rate %',            0, 100,    true],
            'total_trips'                   => ['Total trips',                   0, 999999, false],
            'revenue_per_asset_avg'         => ['Revenue per asset (avg)',        0, null,   true],
            'total_gross_revenue'           => ['Total gross revenue',           0, null,   true],
            'total_opex'                    => ['Total operating expenses',       0, null,   true],
            'net_income_actual'             => ['Net income (actual)',            null, null, true],
            'investor_distribution_actual'  => ['Investor distribution (actual)', 0, null,  true],
        ];
        $values = [];
        foreach ($numFields as $field => [$label, $min, $max, $isDecimal]) {
            $raw = trim($_POST[$field] ?? '');
            if ($raw === '' || $raw === null) {
                $values[$field] = null;
                continue;
            }
            if (!is_numeric($raw)) {
                $mErrors[] = "$label must be a number.";
                continue;
            }
            $val = $isDecimal ? (float)$raw : (int)$raw;
            if ($min !== null && $val < $min) { $mErrors[] = "$label must be ≥ $min."; continue; }
            if ($max !== null && $val > $max) { $mErrors[] = "$label must be ≤ $max."; continue; }
            $values[$field] = $val;
        }

        $disclosureType = in_array($_POST['disclosure_type'] ?? '', ['self_reported','platform_verified','audited'])
            ? $_POST['disclosure_type']
            : 'self_reported';
        $notes = mb_substr(trim($_POST['metrics_notes'] ?? ''), 0, 2000);
        $isPayoutPost = isset($_POST['mark_as_payout']) && !empty($values['investor_distribution_actual']);

        if (empty($mErrors)) {
            try {
                if ($editId > 0) {
                    // Update existing row (create audit note)
                    $stmt = $pdo->prepare("
                        UPDATE campaign_operational_metrics
                        SET active_asset_count          = :ac,
                            utilisation_rate_pct        = :ur,
                            total_trips                 = :tt,
                            revenue_per_asset_avg       = :rpa,
                            total_gross_revenue         = :tgr,
                            total_opex                  = :opx,
                            net_income_actual           = :nia,
                            investor_distribution_actual= :ida,
                            disclosure_type             = :dt,
                            notes                       = :notes
                        WHERE id = :id AND campaign_id = :cid
                    ");
                    $stmt->execute([
                        'ac'    => $values['active_asset_count'],
                        'ur'    => $values['utilisation_rate_pct'],
                        'tt'    => $values['total_trips'],
                        'rpa'   => $values['revenue_per_asset_avg'],
                        'tgr'   => $values['total_gross_revenue'],
                        'opx'   => $values['total_opex'],
                        'nia'   => $values['net_income_actual'],
                        'ida'   => $values['investor_distribution_actual'],
                        'dt'    => $disclosureType,
                        'notes' => $notes ?: null,
                        'id'    => $editId,
                        'cid'   => $campaignId,
                    ]);
                    $mSuccess = 'Metrics updated for ' . ($monthNames[$periodMonth] ?? '') . ' ' . $periodYear . '.';
                    $insertedId = $editId;
                } else {
                    // Insert new row — UNIQUE constraint on (campaign_id, period_year, period_month)
                    $stmt = $pdo->prepare("
                        INSERT INTO campaign_operational_metrics
                            (campaign_id, period_year, period_month,
                             active_asset_count, utilisation_rate_pct, total_trips,
                             revenue_per_asset_avg, total_gross_revenue, total_opex,
                             net_income_actual, investor_distribution_actual,
                             disclosure_type, notes, created_at)
                        VALUES
                            (:cid, :yr, :mo,
                             :ac, :ur, :tt,
                             :rpa, :tgr, :opx,
                             :nia, :ida,
                             :dt, :notes, NOW())
                    ");
                    $stmt->execute([
                        'cid'   => $campaignId,
                        'yr'    => $periodYear,
                        'mo'    => $periodMonth,
                        'ac'    => $values['active_asset_count'],
                        'ur'    => $values['utilisation_rate_pct'],
                        'tt'    => $values['total_trips'],
                        'rpa'   => $values['revenue_per_asset_avg'],
                        'tgr'   => $values['total_gross_revenue'],
                        'opx'   => $values['total_opex'],
                        'nia'   => $values['net_income_actual'],
                        'ida'   => $values['investor_distribution_actual'],
                        'dt'    => $disclosureType,
                        'notes' => $notes ?: null,
                    ]);
                    $insertedId = (int)$pdo->lastInsertId();
                    $mSuccess = 'Metrics posted for ' . ($monthNames[$periodMonth] ?? '') . ' ' . $periodYear . '.';
                }

                // ── US-503: Notify all accepted investors ─────────────────────────────
                // Fires on both new inserts and updates to keep investors informed.
                if ($insertedId > 0 && class_exists('NotificationService')) {
                    $periodLabel = ($monthNames[$periodMonth] ?? '') . ' ' . $periodYear;
                    $actualNetStr  = isset($values['net_income_actual']) ? 'R ' . number_format((float)$values['net_income_actual'], 0, '.', ' ') : null;
                    $notifBody = $actualNetStr
                        ? 'Net income: ' . $actualNetStr . '. Fleet performance report now available.'
                        : 'Monthly fleet performance report now available in the deal room.';

                    NotificationService::notifyCampaignInvestors(
                        $pdo,
                        $campaignId,
                        NotificationService::TYPE_METRICS_UPDATE,
                        htmlspecialchars($campaign['title']) . ' — ' . $periodLabel . ' performance posted',
                        $notifBody,
                        '/app/invest/campaign.php?cid=' . urlencode($campaign['uuid']) . '#financials',
                        ['campaign_id' => $campaignId, 'period' => $periodLabel, 'metrics_id' => $insertedId]
                    );
                }

                // ── US-503 payout notification (if marked as payout post) ─────────────
                if ($isPayoutPost && $insertedId > 0 && class_exists('NotificationService')) {
                    $distribStr = 'R ' . number_format((float)$values['investor_distribution_actual'], 0, '.', ' ');
                    NotificationService::notifyCampaignInvestors(
                        $pdo,
                        $campaignId,
                        NotificationService::TYPE_PAYOUT_CALCULATED,
                        htmlspecialchars($campaign['title']) . ' — Distribution calculated: ' . $distribStr,
                        'A distribution of ' . $distribStr . ' has been calculated for ' . $periodLabel . '. Pending admin approval before wallet credits are issued.',
                        '/app/invest/campaign.php?cid=' . urlencode($campaign['uuid']) . '#financials',
                        ['campaign_id' => $campaignId, 'period' => $periodLabel, 'amount' => $values['investor_distribution_actual']]
                    );
                }

            } catch (PDOException $e) {
                if ($e->getCode() === '23000' || strpos($e->getMessage(), 'Duplicate entry') !== false) {
                    $mErrors[] = 'Metrics for ' . ($monthNames[$periodMonth] ?? '') . ' ' . $periodYear . ' already exist. Use the edit button on the existing row to update it.';
                } else {
                    $mErrors[] = 'Database error. Please try again.';
                    error_log('[_metrics_tab.php] ' . $e->getMessage());
                }
            }
        }
    }
}

// ── Load existing metrics (newest first) ─────────────────────────────────────
$existingMetrics = [];
try {
    $stmt = $pdo->prepare("
        SELECT id, period_year, period_month,
               active_asset_count, utilisation_rate_pct, total_trips,
               revenue_per_asset_avg, total_gross_revenue, total_opex,
               net_income_actual, investor_distribution_actual,
               disclosure_type, notes, created_at
        FROM campaign_operational_metrics
        WHERE campaign_id = ?
        ORDER BY period_year DESC, period_month DESC
    ");
    $stmt->execute([$campaignId]);
    $existingMetrics = $stmt->fetchAll();
} catch (PDOException $e) {
    // Table not yet migrated — show stub notice
    $mErrors[] = 'The campaign_operational_metrics table is not yet available. Awaiting Team A migration US-105.';
}

$dlLabels = [
    'self_reported'       => ['Self-Reported',       'mt-dl-self'],
    'platform_verified'   => ['Platform Verified',   'mt-dl-plat'],
    'audited'             => ['Audited',             'mt-dl-audit'],
];

// Current year/month defaults
$defaultYear  = (int)date('Y');
$defaultMonth = (int)date('m') - 1 ?: 12; // previous month
if ($defaultMonth === 12 && (int)date('m') === 1) $defaultYear--;
?>

<!-- ═══════════════════════════════════════════════════════
     US-501 — Operational Metrics Tab
     US-503 — Investor notification on submit
     Team D component
══════════════════════════════════════════════════════════ -->

<style>
/* Scoped .mt- prefix */
.mt-section-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:var(--navy);margin-bottom:.35rem;}
.mt-section-desc{font-size:.86rem;color:var(--text-muted);line-height:1.55;margin-bottom:1.5rem;}
.mt-alert{display:flex;align-items:flex-start;gap:.6rem;padding:.8rem 1rem;border-radius:var(--radius-sm);margin-bottom:1rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.mt-alert i{flex-shrink:0;margin-top:.05rem;}
.mt-alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.mt-alert-error  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.mt-alert-warn   {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.mt-form-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius-sm);padding:1.5rem;margin-bottom:1.5rem;box-shadow:var(--shadow);}
.mt-form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1rem;}
.mt-form-grid .span-2{grid-column:span 2;}
.mt-field{display:flex;flex-direction:column;gap:.35rem;}
.mt-label{font-size:.82rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.3rem;}
.mt-label i{color:var(--navy-light);font-size:.75rem;}
.mt-label .opt{font-weight:400;color:var(--text-light);font-size:.75rem;}
.mt-input{width:100%;padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
.mt-input:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.08);}
.mt-input-wrap{position:relative;}
.mt-input-pre{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.mt-input-pre ~ .mt-input{padding-left:1.9rem;}
.mt-input-suf{position:absolute;right:.75rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.mt-input-suf ~ .mt-input{padding-right:2.5rem;}
.mt-hint{font-size:.73rem;color:var(--text-light);}
.mt-sec-div{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);display:flex;align-items:center;gap:.6rem;margin:1.25rem 0 .9rem;grid-column:span 2;}
.mt-sec-div::after{content:'';flex:1;height:1px;background:var(--border);}
/* Payout toggle */
.mt-payout-toggle{display:flex;align-items:flex-start;gap:.85rem;padding:.9rem 1rem;border-radius:var(--radius-sm);border:1.5px solid var(--border);background:var(--surface-2);cursor:pointer;transition:all var(--transition);grid-column:span 2;}
.mt-payout-toggle.active{border-color:var(--amber);background:var(--amber-light);}
.mt-payout-toggle input{position:absolute;opacity:0;pointer-events:none;}
.mt-pt-icon{width:36px;height:36px;border-radius:8px;background:var(--amber-light);border:1px solid var(--amber);display:flex;align-items:center;justify-content:center;color:var(--amber-dark);font-size:.9rem;flex-shrink:0;}
.mt-pt-text strong{font-size:.88rem;font-weight:600;color:var(--text);display:block;margin-bottom:.15rem;}
.mt-pt-text span{font-size:.78rem;color:var(--text-muted);}
/* Disclosure select */
.mt-disc-chips{display:flex;flex-wrap:wrap;gap:.45rem;}
.mt-disc-option{position:relative;}
.mt-disc-option input{position:absolute;opacity:0;pointer-events:none;}
.mt-disc-label{display:flex;align-items:center;gap:.3rem;padding:.32rem .8rem;border-radius:99px;border:1.5px solid var(--border);font-size:.78rem;font-weight:500;color:var(--text-muted);cursor:pointer;transition:all var(--transition);}
.mt-disc-option input:checked + .mt-disc-label.dl-self {background:#f1f5f9;border-color:#94a3b8;color:#475569;font-weight:600;}
.mt-disc-option input:checked + .mt-disc-label.dl-plat {background:var(--amber-light);border-color:var(--amber);color:#78350f;font-weight:600;}
.mt-disc-option input:checked + .mt-disc-label.dl-audit{background:var(--green-bg);border-color:var(--green);color:var(--green);font-weight:600;}
/* Buttons */
.mt-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.6rem 1.2rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.86rem;font-weight:600;cursor:pointer;border:none;text-decoration:none;transition:all var(--transition);}
.mt-btn-primary{background:var(--navy-mid);color:#fff;}
.mt-btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.mt-btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
.mt-btn-ghost:hover{border-color:#94a3b8;color:var(--text);}
.mt-btn-sm{padding:.35rem .8rem;font-size:.78rem;}
/* History table */
.mt-table{width:100%;border-collapse:collapse;font-size:.82rem;}
.mt-table th{text-align:left;padding:.5rem .65rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.mt-table th.r{text-align:right;}
.mt-table td{padding:.6rem .65rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.mt-table tr:last-child td{border-bottom:none;}
.mt-table tr:hover td{background:#fafbfc;}
.mt-table td.r{text-align:right;font-variant-numeric:tabular-nums;}
.mt-table td.pos{color:var(--green);font-weight:600;}
.mt-badge{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;font-weight:600;border:1px solid transparent;}
.mt-dl-self {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.mt-dl-plat {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.mt-dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
@media(max-width:640px){.mt-form-grid{grid-template-columns:1fr;}.mt-form-grid .span-2{grid-column:span 1;}.mt-sec-div{grid-column:span 1;}.mt-payout-toggle{grid-column:span 1;}}
</style>

<h2 class="mt-section-title"><i class="fa-solid fa-gauge" style="color:var(--navy-light);font-size:.9rem;margin-right:.4rem;"></i>Operational Metrics</h2>
<p class="mt-section-desc">
    Post monthly fleet performance data so investors can compare actual results against the financial model projections.
    Notifications are sent automatically to all accepted investors when you submit.
</p>

<?php if (!empty($mSuccess)): ?>
    <div class="mt-alert mt-alert-success">
        <i class="fa-solid fa-circle-check"></i>
        <?php echo htmlspecialchars($mSuccess); ?>
        Accepted investors have been notified.
    </div>
<?php endif; ?>

<?php if (!empty($mErrors)): ?>
    <?php foreach ($mErrors as $err): ?>
    <div class="mt-alert mt-alert-error">
        <i class="fa-solid fa-circle-exclamation"></i>
        <?php echo htmlspecialchars($err); ?>
    </div>
    <?php endforeach; ?>
<?php endif; ?>

<!-- ── Entry form ──────────────────────────────────────────────────────────── -->
<div class="mt-form-card">
    <div style="font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--navy);margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;">
        <i class="fa-solid fa-plus-circle" style="color:var(--navy-light);"></i> Post New Month
    </div>

    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="metrics_submit" value="1">
        <input type="hidden" name="edit_id" value="0" id="editIdField">

        <div class="mt-form-grid">

            <!-- Period -->
            <div class="mt-field">
                <label class="mt-label" for="period_month"><i class="fa-solid fa-calendar"></i> Month *</label>
                <select name="period_month" id="period_month" class="mt-input">
                    <?php foreach ($monthNames as $mn => $ml): ?>
                    <option value="<?php echo $mn; ?>" <?php echo $mn === $defaultMonth ? 'selected' : ''; ?>><?php echo $ml; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="mt-field">
                <label class="mt-label" for="period_year"><i class="fa-solid fa-calendar"></i> Year *</label>
                <input type="number" name="period_year" id="period_year" class="mt-input"
                       min="2020" max="<?php echo $defaultYear + 1; ?>"
                       value="<?php echo $defaultYear; ?>">
            </div>

            <!-- Revenue section -->
            <div class="mt-sec-div"><i class="fa-solid fa-sack-dollar" style="color:var(--navy-light);"></i> Revenue</div>

            <div class="mt-field">
                <label class="mt-label" for="total_gross_revenue"><i class="fa-solid fa-arrow-up"></i> Total Gross Revenue <span class="opt">(optional)</span></label>
                <div class="mt-input-wrap">
                    <span class="mt-input-pre">R</span>
                    <input type="number" name="total_gross_revenue" id="total_gross_revenue"
                           class="mt-input" min="0" step="100" placeholder="0">
                </div>
            </div>
            <div class="mt-field">
                <label class="mt-label" for="revenue_per_asset_avg"><i class="fa-solid fa-truck"></i> Revenue per Asset (avg) <span class="opt">(optional)</span></label>
                <div class="mt-input-wrap">
                    <span class="mt-input-pre">R</span>
                    <input type="number" name="revenue_per_asset_avg" id="revenue_per_asset_avg"
                           class="mt-input" min="0" step="10" placeholder="0">
                </div>
            </div>
            <div class="mt-field">
                <label class="mt-label" for="total_opex"><i class="fa-solid fa-arrow-down"></i> Total Operating Expenses <span class="opt">(optional)</span></label>
                <div class="mt-input-wrap">
                    <span class="mt-input-pre">R</span>
                    <input type="number" name="total_opex" id="total_opex"
                           class="mt-input" min="0" step="100" placeholder="0">
                </div>
            </div>
            <div class="mt-field">
                <label class="mt-label" for="net_income_actual"><i class="fa-solid fa-circle-check" style="color:var(--green);"></i> Net Income (Actual) <span class="opt">(optional)</span></label>
                <div class="mt-input-wrap">
                    <span class="mt-input-pre">R</span>
                    <input type="number" name="net_income_actual" id="net_income_actual"
                           class="mt-input" step="100" placeholder="0">
                </div>
                <span class="mt-hint">Can be negative if operating at a loss.</span>
            </div>

            <!-- Operations section -->
            <div class="mt-sec-div"><i class="fa-solid fa-gauge" style="color:var(--navy-light);"></i> Operations</div>

            <div class="mt-field">
                <label class="mt-label" for="active_asset_count"><i class="fa-solid fa-truck-fast"></i> Active Assets <span class="opt">(optional)</span></label>
                <input type="number" name="active_asset_count" id="active_asset_count"
                       class="mt-input" min="0" max="999" step="1" placeholder="0">
            </div>
            <div class="mt-field">
                <label class="mt-label" for="utilisation_rate_pct"><i class="fa-solid fa-percent"></i> Utilisation Rate % <span class="opt">(optional)</span></label>
                <div class="mt-input-wrap">
                    <input type="number" name="utilisation_rate_pct" id="utilisation_rate_pct"
                           class="mt-input" min="0" max="100" step="0.1" placeholder="85.0"
                           style="padding-right:2.5rem;">
                    <span class="mt-input-suf">%</span>
                </div>
            </div>
            <div class="mt-field">
                <label class="mt-label" for="total_trips"><i class="fa-solid fa-route"></i> Total Trips <span class="opt">(optional)</span></label>
                <input type="number" name="total_trips" id="total_trips"
                       class="mt-input" min="0" step="1" placeholder="0">
            </div>

            <!-- Distribution section -->
            <div class="mt-sec-div"><i class="fa-solid fa-coins" style="color:var(--navy-light);"></i> Investor Distribution</div>

            <div class="mt-field span-2">
                <label class="mt-label" for="investor_distribution_actual">
                    <i class="fa-solid fa-coins" style="color:var(--amber-dark);"></i>
                    Investor Distribution (Actual) <span class="opt">(optional)</span>
                </label>
                <div class="mt-input-wrap" style="max-width:340px;">
                    <span class="mt-input-pre">R</span>
                    <input type="number" name="investor_distribution_actual"
                           id="investor_distribution_actual"
                           class="mt-input" min="0" step="100" placeholder="0">
                </div>
                <span class="mt-hint">Total amount distributed to all investors this period combined.</span>
            </div>

            <!-- Mark as payout toggle -->
            <label class="mt-payout-toggle" id="payoutToggle" for="mark_as_payout">
                <div class="mt-pt-icon"><i class="fa-solid fa-coins"></i></div>
                <div class="mt-pt-text">
                    <strong>Mark as Payout Event</strong>
                    <span>Marks this month as a payout period. Investors will receive a "Distribution Calculated" notification. An admin must approve before wallet credits are issued.</span>
                </div>
                <input type="checkbox" name="mark_as_payout" id="mark_as_payout" value="1">
            </label>

            <!-- Disclosure type -->
            <div class="mt-field span-2">
                <label class="mt-label"><i class="fa-solid fa-shield-halved"></i> Disclosure Type</label>
                <div class="mt-disc-chips">
                    <div class="mt-disc-option">
                        <input type="radio" name="disclosure_type" id="dl_self" value="self_reported" checked>
                        <label class="mt-disc-label dl-self" for="dl_self"><i class="fa-solid fa-user"></i> Self-Reported</label>
                    </div>
                    <div class="mt-disc-option">
                        <input type="radio" name="disclosure_type" id="dl_plat" value="platform_verified">
                        <label class="mt-disc-label dl-plat" for="dl_plat"><i class="fa-solid fa-badge-check"></i> Platform Verified</label>
                    </div>
                    <div class="mt-disc-option">
                        <input type="radio" name="disclosure_type" id="dl_audit" value="audited">
                        <label class="mt-disc-label dl-audit" for="dl_audit"><i class="fa-solid fa-file-invoice"></i> Audited</label>
                    </div>
                </div>
            </div>

            <!-- Notes -->
            <div class="mt-field span-2">
                <label class="mt-label" for="metrics_notes"><i class="fa-solid fa-note-sticky"></i> Notes <span class="opt">(optional)</span></label>
                <textarea name="metrics_notes" id="metrics_notes" class="mt-input" rows="2"
                          maxlength="2000" style="resize:vertical;min-height:60px;line-height:1.5;"
                          placeholder="Any context for investors about this month's performance…"></textarea>
                <span class="mt-hint">Max 2 000 characters. Visible to investors in the deal room.</span>
            </div>

        </div><!-- /.mt-form-grid -->

        <div style="display:flex;gap:.65rem;margin-top:1.25rem;flex-wrap:wrap;align-items:center;">
            <button type="submit" class="mt-btn mt-btn-primary">
                <i class="fa-solid fa-paper-plane"></i> Post Metrics &amp; Notify Investors
            </button>
            <button type="button" class="mt-btn mt-btn-ghost" onclick="resetMetricsForm()">
                <i class="fa-solid fa-rotate-left"></i> Reset
            </button>
            <span style="font-size:.75rem;color:var(--text-light);">
                <i class="fa-solid fa-bell" style="font-size:.7rem;margin-right:.2rem;"></i>
                All accepted investors are notified automatically.
            </span>
        </div>
    </form>
</div>

<!-- ── History table ──────────────────────────────────────────────────────── -->
<?php if (empty($existingMetrics) && empty($mErrors)): ?>
    <div style="text-align:center;padding:2.5rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);">
        <i class="fa-solid fa-chart-column" style="font-size:1.75rem;color:var(--text-light);opacity:.4;display:block;margin-bottom:.65rem;"></i>
        <div style="font-size:.9rem;font-weight:600;color:var(--text-muted);margin-bottom:.3rem;">No metrics posted yet</div>
        <div style="font-size:.8rem;color:var(--text-light);">Use the form above to post your first monthly performance report.</div>
    </div>
<?php elseif (!empty($existingMetrics)): ?>
    <div style="font-size:.83rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--navy);margin-bottom:.85rem;display:flex;align-items:center;gap:.4rem;">
        <i class="fa-solid fa-clock-rotate-left" style="color:var(--navy-light);"></i>
        Posted Metrics History
    </div>
    <div style="overflow-x:auto;">
        <table class="mt-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th class="r">Gross Rev.</th>
                    <th class="r">Net Income</th>
                    <th class="r">Investor Distrib.</th>
                    <th class="r">Utilisation</th>
                    <th class="r">Active Assets</th>
                    <th>Source</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($existingMetrics as $mx):
                [$dlLabel, $dlClass] = $dlLabels[$mx['disclosure_type'] ?? 'self_reported'] ?? ['Self-Reported','mt-dl-self'];
                $periodStr = ($monthNames[$mx['period_month']] ?? '') . ' ' . $mx['period_year'];
            ?>
            <tr>
                <td style="font-weight:600;color:var(--navy);white-space:nowrap;"><?php echo $periodStr; ?></td>
                <td class="r" style="color:var(--text-muted);"><?php echo m_money($mx['total_gross_revenue']); ?></td>
                <td class="r <?php echo ($mx['net_income_actual'] ?? 0) >= 0 ? '' : 'style="color:var(--error);"'; ?>"><?php echo m_money($mx['net_income_actual']); ?></td>
                <td class="r pos"><?php echo m_money($mx['investor_distribution_actual']); ?></td>
                <td class="r"><?php echo m_pct($mx['utilisation_rate_pct']); ?></td>
                <td class="r"><?php echo isset($mx['active_asset_count']) ? (int)$mx['active_asset_count'] : '—'; ?></td>
                <td><span class="mt-badge <?php echo $dlClass; ?>"><?php echo $dlLabel; ?></span></td>
                <td>
                    <button type="button" class="mt-btn mt-btn-ghost mt-btn-sm"
                            onclick="loadEditMetrics(<?php echo (int)$mx['id']; ?>,<?php echo (int)$mx['period_year']; ?>,<?php echo (int)$mx['period_month']; ?>)"
                            title="Edit this entry">
                        <i class="fa-solid fa-pen-to-square"></i>
                    </button>
                </td>
            </tr>
            <?php if (!empty($mx['notes'])): ?>
            <tr>
                <td colspan="8" style="padding:.35rem .65rem .7rem;color:var(--text-muted);font-size:.78rem;font-style:italic;border-bottom:1px solid var(--border);">
                    <i class="fa-solid fa-quote-left" style="font-size:.65rem;margin-right:.3rem;color:var(--text-light);"></i><?php echo htmlspecialchars($mx['notes']); ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:.74rem;color:var(--text-light);margin-top:.75rem;">
        <i class="fa-solid fa-circle-info" style="margin-right:.25rem;"></i>
        Editing a row updates the record and re-notifies investors. Soft-delete (mark as retracted) is not available via this form — contact platform support for data corrections after 30 days.
    </p>
<?php endif; ?>

<script>
function resetMetricsForm() {
    document.getElementById('editIdField').value = '0';
    document.querySelectorAll('[name="metrics_notes"]')[0].value = '';
    document.querySelectorAll('[name="total_gross_revenue"]')[0].value = '';
    document.querySelectorAll('[name="revenue_per_asset_avg"]')[0].value = '';
    document.querySelectorAll('[name="total_opex"]')[0].value = '';
    document.querySelectorAll('[name="net_income_actual"]')[0].value = '';
    document.querySelectorAll('[name="active_asset_count"]')[0].value = '';
    document.querySelectorAll('[name="utilisation_rate_pct"]')[0].value = '';
    document.querySelectorAll('[name="total_trips"]')[0].value = '';
    document.querySelectorAll('[name="investor_distribution_actual"]')[0].value = '';
    document.getElementById('mark_as_payout').checked = false;
    document.getElementById('payoutToggle').classList.remove('active');
    document.getElementById('dl_self').checked = true;
}

function loadEditMetrics(id, year, month) {
    document.getElementById('editIdField').value = id;
    document.getElementById('period_year').value  = year;
    document.getElementById('period_month').value = month;
    document.querySelector('.mt-form-card').scrollIntoView({ behavior: 'smooth', block: 'start' });
    // Visual cue that we're in edit mode
    document.querySelector('[name="metrics_submit"]').closest('form')
        .querySelector('button[type="submit"]').innerHTML =
        '<i class="fa-solid fa-pen-to-square"></i> Update Metrics & Re-notify';
}

// Payout toggle visual feedback
document.getElementById('mark_as_payout').addEventListener('change', function() {
    document.getElementById('payoutToggle').classList.toggle('active', this.checked);
});
</script>
