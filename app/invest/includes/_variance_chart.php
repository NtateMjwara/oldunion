<?php
/**
 * /app/invest/includes/_variance_chart.php
 *
 * US-502 — Actual vs Projected Variance Chart Component
 * Team D — Phase 2
 *
 * INJECTION POINT (Team B campaign.php line ~1074):
 *   Replace the TODO comment with:
 *     <?php
 *       $varianceData = FleetService::getVarianceChartData($campaignId);
 *       require __DIR__ . '/includes/_variance_chart.php';
 *     ?>
 *
 * VARIABLES EXPECTED FROM CALLER:
 *   $varianceData   array|null   — From FleetService::getVarianceChartData()
 *                                  Returns null if no operational metrics exist.
 *   $campaign       array        — Campaign row (for title, uuid — already in scope)
 *   $fleetParams    array        — Fleet params JSON blob (already in scope in campaign.php)
 *
 * WHAT FleetService::getVarianceChartData($campaignId) SHOULD RETURN:
 *   (Team D → Team A: please add this method to FleetService.php)
 *   [
 *     'periods'      => ['Jan 2026', 'Feb 2026', ...],      // period labels
 *     'projected'    => [87500, 92000, ...],                 // net_to_spv from projections
 *     'actual'       => [91200, null, ...],                  // net_income_actual (null = not yet posted)
 *     'distributions_projected' => [62000, 65000, ...],     // investor_distribution from projections
 *     'distributions_actual'    => [64000, null, ...],      // investor_distribution_actual
 *     'utilisation'  => [88.2, null, ...],                  // utilisation_rate_pct
 *     'rows'         => [                                    // raw rows for table display
 *       ['period' => 'Jan 2026', 'projected_net' => 87500, 'actual_net' => 91200,
 *        'projected_distrib' => 62000, 'actual_distrib' => 64000,
 *        'variance_pct' => 4.2, 'utilisation' => 88.2, 'disclosure_type' => 'self_reported'],
 *       ...
 *     ]
 *   ]
 *
 * STUB BEHAVIOUR:
 *   If $varianceData is null (no metrics posted yet), renders a clean placeholder
 *   card explaining what will appear here — no crash, no error state.
 *   Chart.js is only loaded when $varianceData is not null (performance).
 *
 * CSS NOTE:
 *   All CSS is scoped with .vcd- prefix to avoid collisions with Team B's styles.
 *   This component adds no global styles to campaign.php.
 */

// Guard: $varianceData and $campaign must be in scope
if (!isset($campaign)) {
    return; // safety — do not render outside campaign context
}

$hasData       = !empty($varianceData) && !empty($varianceData['periods']);
$disclosureLabels = [
    'self_reported'       => ['Self-Reported',       'vcd-dl-self'],
    'platform_verified'   => ['Platform Verified',   'vcd-dl-plat'],
    'audited'             => ['Audited',             'vcd-dl-audit'],
];
?>

<!-- ═══════════════════════════════════════════════════════
     US-502 — Actual vs Projected Variance Chart
     Team D component — injected into Financials tab
══════════════════════════════════════════════════════════ -->

<style>
/* Scoped to .vcd- prefix — no global pollution */
.vcd-wrap{margin-top:1.75rem;border-top:1px solid var(--border);padding-top:1.5rem;}
.vcd-header{display:flex;align-items:flex-start;justify-content:space-between;gap:.75rem;margin-bottom:1rem;flex-wrap:wrap;}
.vcd-title{font-size:.83rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--navy);display:flex;align-items:center;gap:.4rem;}
.vcd-title i{color:var(--navy-light);}
.vcd-legend{display:flex;align-items:center;gap:1rem;flex-wrap:wrap;}
.vcd-legend-item{display:flex;align-items:center;gap:.35rem;font-size:.75rem;color:var(--text-muted);}
.vcd-legend-dot{width:10px;height:10px;border-radius:2px;flex-shrink:0;}
.vcd-chart-wrap{position:relative;height:220px;margin-bottom:1rem;}
/* Utilisation mini-bar */
.vcd-util-section{margin-bottom:1.25rem;}
.vcd-util-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.6rem;}
.vcd-util-bars{display:flex;flex-direction:column;gap:.4rem;}
.vcd-util-row{display:flex;align-items:center;gap:.65rem;font-size:.73rem;}
.vcd-util-period{color:var(--text-muted);min-width:64px;flex-shrink:0;}
.vcd-util-bar-outer{flex:1;height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);}
.vcd-util-bar-inner{height:100%;border-radius:99px;background:var(--navy-light);transition:width .4s ease;}
.vcd-util-bar-inner.above{background:var(--green);}
.vcd-util-bar-inner.below{background:var(--amber);}
.vcd-util-pct{min-width:38px;text-align:right;font-weight:600;color:var(--text);}
.vcd-util-baseline{font-size:.68rem;color:var(--text-light);}
/* Variance table */
.vcd-table{width:100%;border-collapse:collapse;font-size:.82rem;margin-bottom:.75rem;}
.vcd-table th{text-align:left;padding:.5rem .65rem;font-size:.71rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.vcd-table th.r{text-align:right;}
.vcd-table td{padding:.6rem .65rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.vcd-table tr:last-child td{border-bottom:none;}
.vcd-table tr:hover td{background:#fafbfc;}
.vcd-table td.r{text-align:right;font-variant-numeric:tabular-nums;}
.vcd-var-pos{color:var(--green);font-weight:600;}
.vcd-var-neg{color:var(--error);font-weight:600;}
.vcd-var-nil{color:var(--text-light);}
/* Distribution history */
.vcd-distrib-row td.pending{color:var(--amber-dark);font-style:italic;}
/* Disclosure badges */
.vcd-dl-self {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.vcd-dl-plat {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.vcd-dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.vcd-badge{display:inline-flex;align-items:center;gap:.2rem;padding:.15rem .5rem;border-radius:99px;font-size:.68rem;font-weight:600;border:1px solid transparent;}
/* Placeholder */
.vcd-placeholder{display:flex;flex-direction:column;align-items:center;justify-content:center;padding:2.5rem 1.5rem;background:var(--surface-2);border:1.5px dashed var(--border);border-radius:var(--radius-sm);text-align:center;gap:.65rem;}
.vcd-placeholder-icon{font-size:1.75rem;color:var(--text-light);opacity:.5;}
.vcd-placeholder-title{font-size:.9rem;font-weight:600;color:var(--text-muted);}
.vcd-placeholder-desc{font-size:.8rem;color:var(--text-light);line-height:1.55;max-width:380px;}
</style>

<div class="vcd-wrap">

    <?php if (!$hasData): ?>
    <!-- No metrics posted yet — placeholder -->
    <div class="vcd-placeholder">
        <div class="vcd-placeholder-icon"><i class="fa-solid fa-chart-column"></i></div>
        <div class="vcd-placeholder-title">Performance data coming</div>
        <div class="vcd-placeholder-desc">
            Once the SPV operator submits monthly operational metrics, actual revenue and distribution results will appear here compared against the financial model projections.
        </div>
    </div>

    <?php else:
        $periods       = $varianceData['periods'];
        $projected     = $varianceData['projected'];
        $actual        = $varianceData['actual'];
        $distProj      = $varianceData['distributions_projected'] ?? [];
        $distActual    = $varianceData['distributions_actual'] ?? [];
        $utilisation   = $varianceData['utilisation'] ?? [];
        $rows          = $varianceData['rows'] ?? [];

        // Chart data — nulls become JS null (gaps in line)
        $jsLabels    = json_encode($periods, JSON_UNESCAPED_UNICODE);
        $jsProjected = json_encode($projected);
        $jsActual    = json_encode(array_map(fn($v) => $v === null ? 'null' : $v, $actual));
        // Inline null as literal JS null, not string
        $jsActualClean = preg_replace('/"null"/', 'null', $jsActual);
        $chartId = 'varianceChart_' . $campaign['id'];
    ?>

    <div class="vcd-header">
        <div class="vcd-title"><i class="fa-solid fa-chart-column"></i> Actual vs Projected Performance</div>
        <div class="vcd-legend">
            <div class="vcd-legend-item"><span class="vcd-legend-dot" style="background:#0f3b7a;opacity:.35;"></span>Projected (net to SPV)</div>
            <div class="vcd-legend-item"><span class="vcd-legend-dot" style="background:#0b6b4d;"></span>Actual net income</div>
        </div>
    </div>

    <!-- Chart.js dual-dataset -->
    <div class="vcd-chart-wrap">
        <canvas id="<?php echo $chartId; ?>"></canvas>
    </div>

    <!-- Utilisation mini-bars (only if utilisation data exists) -->
    <?php
    $utilRows = array_filter(
        array_map(fn($p, $u) => $u !== null ? ['period' => $p, 'pct' => (float)$u] : null, $periods, $utilisation),
        fn($r) => $r !== null
    );
    if (!empty($utilRows)):
    ?>
    <div class="vcd-util-section">
        <div class="vcd-util-label">Fleet Utilisation Rate (baseline: 85%)</div>
        <div class="vcd-util-bars">
            <?php foreach (array_slice($utilRows, -6) as $ur): // show last 6 months
                $uPct  = min(100, (float)$ur['pct']);
                $above = $uPct >= 85;
            ?>
            <div class="vcd-util-row">
                <span class="vcd-util-period"><?php echo htmlspecialchars($ur['period']); ?></span>
                <div class="vcd-util-bar-outer">
                    <div class="vcd-util-bar-inner <?php echo $above ? 'above' : 'below'; ?>" style="width:<?php echo $uPct; ?>%"></div>
                </div>
                <span class="vcd-util-pct"><?php echo number_format($uPct, 1); ?>%</span>
                <span class="vcd-util-baseline"><?php echo $above ? '↑' : '↓'; ?> vs 85%</span>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Distribution history table -->
    <?php if (!empty($rows)): ?>
    <div style="overflow-x:auto;margin-bottom:.75rem;">
        <table class="vcd-table">
            <thead>
                <tr>
                    <th>Period</th>
                    <th class="r">Projected Net</th>
                    <th class="r">Actual Net</th>
                    <th class="r">Variance</th>
                    <th class="r">Proj. Distrib.</th>
                    <th class="r">Actual Distrib.</th>
                    <th>Source</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row):
                $varPct   = isset($row['variance_pct']) ? (float)$row['variance_pct'] : null;
                $varClass = $varPct === null ? 'vcd-var-nil' : ($varPct >= 0 ? 'vcd-var-pos' : 'vcd-var-neg');
                $varStr   = $varPct === null ? '—' : ($varPct >= 0 ? '+' : '') . number_format($varPct, 1) . '%';
                [$dlLabel, $dlClass] = $disclosureLabels[$row['disclosure_type'] ?? 'self_reported'] ?? ['Self-Reported','vcd-dl-self'];
                $hasActualDistrib = isset($row['actual_distrib']) && $row['actual_distrib'] !== null;
            ?>
            <tr class="<?php echo $hasActualDistrib ? '' : 'vcd-distrib-row'; ?>">
                <td style="font-weight:600;color:var(--navy);white-space:nowrap;"><?php echo htmlspecialchars($row['period']); ?></td>
                <td class="r" style="color:var(--text-muted);"><?php echo isset($row['projected_net']) ? 'R ' . number_format((float)$row['projected_net'], 0, '.', ' ') : '—'; ?></td>
                <td class="r"><?php echo isset($row['actual_net']) ? 'R ' . number_format((float)$row['actual_net'], 0, '.', ' ') : '<span style="color:var(--text-light);font-style:italic;font-size:.78rem;">Pending</span>'; ?></td>
                <td class="r <?php echo $varClass; ?>"><?php echo $varStr; ?></td>
                <td class="r" style="color:var(--text-muted);"><?php echo isset($row['projected_distrib']) ? 'R ' . number_format((float)$row['projected_distrib'], 0, '.', ' ') : '—'; ?></td>
                <td class="r <?php echo $hasActualDistrib ? 'vcd-var-pos' : 'pending'; ?>"><?php echo $hasActualDistrib ? 'R ' . number_format((float)$row['actual_distrib'], 0, '.', ' ') : '<span style="color:var(--amber-dark);font-size:.78rem;">Not yet posted</span>'; ?></td>
                <td><span class="vcd-badge <?php echo $dlClass; ?>"><?php echo $dlLabel; ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p style="font-size:.73rem;color:var(--text-light);line-height:1.5;margin-top:.5rem;">
        <i class="fa-solid fa-circle-info" style="margin-right:.25rem;"></i>
        Variance = (actual − projected) ÷ projected. Positive values indicate the fleet performed above model.
        Distribution data is updated monthly after operational metrics are submitted by the SPV operator.
    </p>
    <?php endif; ?>

    <!-- Chart.js — only loaded when there is data to render -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.min.js"></script>
    <script>
    (function() {
        const ctx = document.getElementById(<?php echo json_encode($chartId); ?>);
        if (!ctx) return;

        const labels    = <?php echo $jsLabels; ?>;
        const projected = <?php echo json_encode($projected); ?>;
        const actual    = <?php echo $jsActualClean; ?>;

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels,
                datasets: [
                    {
                        type: 'line',
                        label: 'Projected net income',
                        data: projected,
                        borderColor: 'rgba(15, 59, 122, 0.55)',
                        backgroundColor: 'transparent',
                        borderWidth: 2,
                        borderDash: [5, 4],
                        pointRadius: 3,
                        pointBackgroundColor: '#0f3b7a',
                        tension: 0.3,
                        order: 1,
                    },
                    {
                        type: 'bar',
                        label: 'Actual net income',
                        data: actual,
                        backgroundColor: actual.map(v =>
                            v === null ? 'transparent' :
                            v >= projected[actual.indexOf(v)]
                                ? 'rgba(11, 107, 77, 0.75)'
                                : 'rgba(245, 158, 11, 0.75)'
                        ),
                        borderColor: actual.map(v =>
                            v === null ? 'transparent' :
                            v >= projected[actual.indexOf(v)]
                                ? 'rgba(11, 107, 77, 1)'
                                : 'rgba(217, 119, 6, 1)'
                        ),
                        borderWidth: 1,
                        borderRadius: 4,
                        order: 2,
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: ctx => {
                                const val = ctx.parsed.y;
                                if (val === null) return ctx.dataset.label + ': Pending';
                                return ctx.dataset.label + ': R ' + Math.round(val).toLocaleString('en-ZA');
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, color: '#98a2b3' }
                    },
                    y: {
                        grid: { color: 'rgba(228,231,236,0.6)' },
                        ticks: {
                            font: { size: 11 }, color: '#98a2b3',
                            callback: v => 'R ' + (v / 1000).toFixed(0) + 'k'
                        },
                        beginAtZero: false
                    }
                }
            }
        });
    })();
    </script>

    <?php endif; ?>

</div><!-- /.vcd-wrap -->
