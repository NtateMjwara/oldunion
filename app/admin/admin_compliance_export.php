<?php
/**
 * /app/admin/campaigns/compliance_export.php
 *
 * US-105 — Admin Compliance Audit Trail & CSV Export
 *
 * Shows the complete tamper-evident audit log for a specific
 * campaign. Accessible only to platform admin roles.
 *
 * Features:
 *   • Invite summary with soft-limit progress bar
 *   • Paginated event table with filter by event type + date
 *   • One-click CSV export for regulatory review
 *   • Red banner when campaign is at / near the 50-person cap
 *
 * Access: platform_role = superadmin OR compliance_officer
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/ComplianceService.php';

// ── Admin gate ───────────────────────────────────────────────
if (empty($_SESSION['user_id'])) {
    redirect('/app/auth/login.php');
}
$pdo    = Database::getInstance();
$userId = (int)$_SESSION['user_id'];

// Check platform role
$stmt = $pdo->prepare("
    SELECT role FROM platform_roles
    WHERE user_id = ? AND role IN ('superadmin','compliance_officer')
    LIMIT 1
");
$stmt->execute([$userId]);
$adminRole = $stmt->fetchColumn();
if (!$adminRole) {
    http_response_code(403);
    exit('Access denied. Compliance officer or superadmin role required.');
}

// ── Campaign required ────────────────────────────────────────
$campaignUuid = trim($_GET['cid'] ?? '');
if (empty($campaignUuid)) {
    redirect('/app/admin/campaigns/');
}

$stmt = $pdo->prepare("
    SELECT fc.id, fc.uuid, fc.title, fc.status, fc.max_contributors,
           fc.contributor_count, fc.opens_at, fc.closes_at,
           c.name AS company_name, c.uuid AS company_uuid
    FROM funding_campaigns fc
    JOIN companies c ON c.id = fc.company_id
    WHERE fc.uuid = ?
");
$stmt->execute([$campaignUuid]);
$campaign = $stmt->fetch();
if (!$campaign) {
    exit('Campaign not found.');
}

$campaignId    = (int)$campaign['id'];
$campaignTitle = $campaign['title'];

// ── CSV download shortcut ────────────────────────────────────
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    ComplianceService::streamCsvExport($pdo, $campaignId, $campaignTitle);
    // exits inside streamCsvExport
}

// ── Invite summary ───────────────────────────────────────────
$summary = ComplianceService::inviteSummary($pdo, $campaignId);

// ── Filters ──────────────────────────────────────────────────
$filterType  = trim($_GET['type']      ?? '');
$filterFrom  = trim($_GET['date_from'] ?? '');
$filterTo    = trim($_GET['date_to']   ?? '');
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 50;
$offset      = ($page - 1) * $perPage;

$filters = [
    'limit'       => $perPage,
    'offset'      => $offset,
    'date_from'   => $filterFrom ?: null,
    'date_to'     => $filterTo   ?: null,
];
if ($filterType !== '') {
    $filters['event_types'] = [$filterType];
}

$events    = ComplianceService::getEventsForCampaign($pdo, $campaignId, $filters);
$totalRows = ComplianceService::countEventsForCampaign($pdo, $campaignId);
$totalPages = max(1, (int)ceil($totalRows / $perPage));

// ── User info for avatar ──────────────────────────────────────
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me          = $stmt->fetch();
$userInitial = $me ? strtoupper(substr($me['email'], 0, 1)) : 'A';

// ── Helpers ───────────────────────────────────────────────────
function fmtDT(string $v): string {
    return $v ? date('d M Y · H:i', strtotime($v)) : '—';
}

// Event type display label + colour class
$eventLabels = [
    'invite_sent'                    => ['Invite Sent (Directory)',     'el-blue'],
    'invite_sent_external'           => ['Invite Sent (External)',      'el-blue'],
    'invite_resent_external'         => ['Invite Resent',               'el-blue'],
    'invite_opened'                  => ['Invite Opened',               'el-grey'],
    'invite_accepted'                => ['Invite Accepted',             'el-green'],
    'invite_declined'                => ['Invite Declined',             'el-red'],
    'invite_revoked'                 => ['Invite Revoked',              'el-red'],
    'invite_claimed_on_verification' => ['Invite Claimed on Verify',    'el-green'],
    'invite_reRequest'               => ['Re-request (Expired)',        'el-amber'],
    'invite_soft_limit_reached'      => ['⚠ Soft Limit Reached (40)',  'el-red-strong'],
    'contribution_started'           => ['Contribution Started',        'el-purple'],
    'contribution_confirmed'         => ['Contribution Confirmed',      'el-purple'],
    'contribution_completed'         => ['Contribution Completed',      'el-green'],
    'contribution_refunded'          => ['Contribution Refunded',       'el-red'],
    'contribution_eft_pending'       => ['EFT Pending',                 'el-amber'],
    'campaign_submitted'             => ['Campaign Submitted',          'el-grey'],
    'campaign_approved'              => ['Campaign Approved',           'el-green'],
    'campaign_rejected'              => ['Campaign Rejected',           'el-red'],
    'campaign_revoked'               => ['Campaign Revoked',            'el-red'],
    'campaign_closed_success'        => ['Campaign Closed — Success',   'el-green'],
    'campaign_closed_failed'         => ['Campaign Closed — Failed',    'el-red'],
    'admin_kyc_approved'             => ['KYC Approved',                'el-green'],
    'admin_kyc_rejected'             => ['KYC Rejected',                'el-red'],
    'admin_contribution_confirmed'   => ['Admin: EFT Confirmed',        'el-green'],
    'admin_payout_approved'          => ['Payout Approved',             'el-green'],
];

$slotPct = $summary['max_contributors'] > 0
    ? min(100, round(($summary['accepted'] / $summary['max_contributors']) * 100))
    : 0;
$slotBarClass = $summary['at_hard_limit'] ? 'bar-red'
    : ($summary['at_soft_limit'] ? 'bar-amber' : 'bar-green');

// Build query string for pagination
function pgLink(int $p): string {
    $q = $_GET;
    $q['page'] = $p;
    return '?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Compliance Audit — <?= htmlspecialchars($campaignTitle) ?> | Old Union Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{font-size:14px;-webkit-font-smoothing:antialiased;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
/* Header */
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--navy);border-bottom:1px solid rgba(255,255,255,.1);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.logo{font-family:'DM Serif Display',serif;font-size:1.35rem;color:#fff;text-decoration:none;}
.logo span{color:#c8102e;}
.header-role-badge{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;background:rgba(200,168,75,.2);color:var(--amber);border:1px solid rgba(200,168,75,.3);border-radius:4px;padding:3px 10px;}
.avatar{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.88rem;flex-shrink:0;}
/* Sidebar */
.page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
.sidebar-section-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.12em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
.sidebar-section-label:first-child{margin-top:0;}
.sidebar a{display:flex;align-items:center;gap:.6rem;padding:.5rem .7rem;border-radius:var(--radius-sm);font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.08rem;}
.sidebar a:hover{background:var(--surface-2);color:var(--text);}
.sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
.sidebar a i{width:15px;text-align:center;font-size:.83rem;}
/* Main */
.main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
/* Breadcrumb */
.breadcrumb{font-size:.78rem;color:var(--text-light);margin-bottom:1.25rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
.breadcrumb a{color:var(--navy-light);text-decoration:none;}
.breadcrumb a:hover{color:var(--navy);}
/* Page head */
.page-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;}
.page-head h1{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--navy);line-height:1.2;margin-bottom:.25rem;}
.page-head-sub{font-size:.87rem;color:var(--text-muted);}
/* Alert banners */
.alert-banner{display:flex;align-items:flex-start;gap:.75rem;padding:1rem 1.25rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.88rem;font-weight:500;border:1px solid transparent;}
.alert-banner i{flex-shrink:0;margin-top:.05rem;}
.alert-hard{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.alert-soft{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
/* Invite summary cards */
.summary-strip{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:1rem;margin-bottom:1.5rem;}
.summary-stat{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem;}
.summary-stat-val{font-size:1.5rem;font-weight:700;color:var(--navy);line-height:1;margin-bottom:.2rem;}
.summary-stat-label{font-size:.73rem;color:var(--text-muted);font-weight:500;text-transform:uppercase;letter-spacing:.07em;}
/* Slot progress */
.slot-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1.1rem 1.25rem;margin-bottom:1.5rem;}
.slot-card-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;font-size:.82rem;font-weight:600;color:var(--text);}
.slot-outer{height:10px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);margin-bottom:.45rem;}
.slot-inner{height:100%;border-radius:99px;transition:width .5s ease;}
.bar-green{background:var(--green);}
.bar-amber{background:var(--amber);}
.bar-red  {background:var(--error);}
.slot-labels{display:flex;justify-content:space-between;font-size:.73rem;color:var(--text-muted);}
/* Filter bar */
.filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.9rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;}
.filter-bar form{display:contents;}
.filter-select{padding:.42rem .8rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;color:var(--text-muted);background:var(--surface-2);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .65rem center;padding-right:2rem;}
.filter-input{padding:.42rem .8rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.82rem;color:var(--text);background:var(--surface-2);outline:none;}
.filter-input:focus,.filter-select:focus{border-color:var(--navy-light);}
.filter-count{margin-left:auto;font-size:.78rem;color:var(--text-light);}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.1rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
.btn-navy{background:var(--navy-mid);color:#fff;}.btn-navy:hover{background:var(--navy);}
.btn-green{background:var(--green);color:#fff;}.btn-green:hover{background:#095c41;}
.btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}.btn-ghost:hover{color:var(--text);border-color:#94a3b8;}
.btn-sm{padding:.32rem .75rem;font-size:.76rem;}
/* Event table */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:.85rem 1.25rem;border-bottom:1px solid var(--border);}
.card-title{display:flex;align-items:center;gap:.5rem;font-size:.8rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;}
.card-title i{color:var(--navy-light);}
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:.82rem;}
th{text-align:left;padding:.55rem .9rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);white-space:nowrap;background:var(--surface-2);}
td{padding:.65rem .9rem;border-bottom:1px solid var(--border);vertical-align:top;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafbfc;}
/* Event type badges */
.el-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.17rem .6rem;border-radius:99px;font-size:.71rem;font-weight:600;border:1px solid transparent;white-space:nowrap;}
.el-blue      {background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
.el-green     {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.el-red       {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.el-red-strong{background:var(--error);color:#fff;border-color:var(--error);}
.el-amber     {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.el-purple    {background:#f3e8ff;color:#5b21b6;border-color:#d4c5f9;}
.el-grey      {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
/* Meta JSON preview */
.meta-cell{font-family:monospace;font-size:.72rem;color:var(--text-muted);max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;cursor:pointer;}
.meta-cell:hover{color:var(--text);}
/* Pagination */
.pagination{display:flex;align-items:center;gap:.4rem;padding:1rem 1.25rem;border-top:1px solid var(--border);flex-wrap:wrap;}
.pg-link{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:6px;font-size:.8rem;font-weight:600;text-decoration:none;color:var(--text-muted);border:1px solid var(--border);background:var(--surface-2);transition:all var(--transition);}
.pg-link:hover{border-color:var(--navy-light);color:var(--navy-mid);}
.pg-link.active{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
.pg-link.disabled{opacity:.35;pointer-events:none;}
.pg-info{font-size:.78rem;color:var(--text-light);margin-left:auto;}
/* Empty state */
.empty-state{text-align:center;padding:3rem;font-size:.88rem;color:var(--text-light);}
.empty-state i{font-size:2rem;display:block;margin-bottom:.65rem;opacity:.3;}
/* Responsive */
@media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}}
::-webkit-scrollbar{width:4px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<header class="top-header">
    <a href="/app/admin/" class="logo">Old <span>U</span>nion <span style="font-size:.7rem;color:rgba(255,255,255,.4);font-family:'DM Sans',sans-serif;margin-left:.5rem;">Admin</span></a>
    <div class="header-role-badge"><?= htmlspecialchars($adminRole) ?></div>
    <div class="avatar"><?= htmlspecialchars($userInitial) ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Platform</div>
        <a href="/app/admin/"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="/app/admin/kyc/"><i class="fa-solid fa-shield-check"></i> KYC Review</a>
        <a href="/app/admin/campaigns/"><i class="fa-solid fa-rocket"></i> Campaign Review</a>
        <a href="/app/admin/eft/"><i class="fa-solid fa-building-columns"></i> EFT Reconciliation</a>
        <div class="sidebar-section-label">Compliance</div>
        <a href="/app/admin/campaigns/compliance_export.php" class="active"><i class="fa-solid fa-file-shield"></i> Audit Trail</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="/app/admin/"><i class="fa-solid fa-gauge"></i> Admin</a>
            <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
            <a href="/app/admin/campaigns/">Campaigns</a>
            <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
            <?= htmlspecialchars($campaignTitle) ?>
            <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
            Compliance Audit
        </div>

        <!-- Page head -->
        <div class="page-head">
            <div>
                <h1>Compliance Audit Trail</h1>
                <p class="page-head-sub">
                    <strong><?= htmlspecialchars($campaignTitle) ?></strong> ·
                    <?= htmlspecialchars($campaign['company_name']) ?>
                    <span style="color:var(--border);margin:0 .4rem;">|</span>
                    UUID: <span style="font-family:monospace;font-size:.82rem;"><?= htmlspecialchars($campaignUuid) ?></span>
                </p>
            </div>
            <a href="?cid=<?= urlencode($campaignUuid) ?>&export=csv" class="btn btn-green">
                <i class="fa-solid fa-download"></i> Export CSV
            </a>
        </div>

        <!-- Hard limit alert -->
        <?php if ($summary['at_hard_limit']): ?>
        <div class="alert-banner alert-hard">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div>
                <strong>Contributor cap reached.</strong>
                This campaign has <?= $summary['accepted'] ?> accepted invites — the maximum of <?= $summary['max_contributors'] ?> has been hit. No further invitations can be issued.
            </div>
        </div>
        <?php elseif ($summary['at_soft_limit']): ?>
        <div class="alert-banner alert-soft">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div>
                <strong>Soft limit reached (40 / <?= $summary['max_contributors'] ?>).</strong>
                Only <?= $summary['slots_remaining'] ?> invitation slots remain. Review pending invites carefully before issuing more.
            </div>
        </div>
        <?php endif; ?>

        <!-- Invite summary strip -->
        <div class="summary-strip">
            <div class="summary-stat">
                <div class="summary-stat-val"><?= $summary['accepted'] ?></div>
                <div class="summary-stat-label">Accepted</div>
            </div>
            <div class="summary-stat">
                <div class="summary-stat-val"><?= $summary['pending'] ?></div>
                <div class="summary-stat-label">Pending</div>
            </div>
            <div class="summary-stat">
                <div class="summary-stat-val"><?= $summary['declined'] ?></div>
                <div class="summary-stat-label">Declined</div>
            </div>
            <div class="summary-stat">
                <div class="summary-stat-val"><?= $summary['revoked'] ?></div>
                <div class="summary-stat-label">Revoked</div>
            </div>
            <div class="summary-stat">
                <div class="summary-stat-val" style="color:<?= $summary['at_soft_limit'] ? 'var(--amber-dark)' : 'var(--green)' ?>">
                    <?= $summary['slots_remaining'] ?>
                </div>
                <div class="summary-stat-label">Slots Remaining</div>
            </div>
            <div class="summary-stat">
                <div class="summary-stat-val"><?= $campaign['contributor_count'] ?></div>
                <div class="summary-stat-label">Confirmed Investors</div>
            </div>
        </div>

        <!-- Slot progress bar -->
        <div class="slot-card">
            <div class="slot-card-head">
                <span>
                    Invitation Capacity
                    <span style="color:var(--text-light);font-weight:400;margin-left:.4rem;">
                        <?= $summary['accepted'] ?> / <?= $summary['max_contributors'] ?> slots used
                    </span>
                </span>
                <span style="font-size:.78rem;color:var(--text-muted);">SA Private Placement Cap: <?= $summary['max_contributors'] ?></span>
            </div>
            <div class="slot-outer">
                <div class="slot-inner <?= $slotBarClass ?>" style="width:<?= $slotPct ?>%"></div>
            </div>
            <div class="slot-labels">
                <span><?= $slotPct ?>% of cap used</span>
                <span style="color:<?= $summary['at_soft_limit'] ? 'var(--error)' : 'var(--text-light)' ?>">
                    <?php if ($summary['at_soft_limit']): ?>
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        Soft limit of <?= ComplianceService::SOFT_LIMIT ?> reached
                    <?php else: ?>
                        Soft limit: <?= ComplianceService::SOFT_LIMIT ?> accepted invites
                    <?php endif; ?>
                </span>
            </div>
        </div>

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="GET" id="ff" style="display:contents;">
                <input type="hidden" name="cid" value="<?= htmlspecialchars($campaignUuid) ?>">

                <select name="type" class="filter-select" onchange="document.getElementById('ff').submit()">
                    <option value="">All Event Types</option>
                    <?php foreach ($eventLabels as $type => [$label, ]): ?>
                    <option value="<?= htmlspecialchars($type) ?>" <?= $filterType === $type ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label) ?>
                    </option>
                    <?php endforeach; ?>
                </select>

                <input type="date" name="date_from" class="filter-input"
                    value="<?= htmlspecialchars($filterFrom) ?>"
                    placeholder="From date"
                    onchange="document.getElementById('ff').submit()">

                <input type="date" name="date_to" class="filter-input"
                    value="<?= htmlspecialchars($filterTo) ?>"
                    placeholder="To date"
                    onchange="document.getElementById('ff').submit()">

                <?php if ($filterType || $filterFrom || $filterTo): ?>
                <a href="?cid=<?= urlencode($campaignUuid) ?>" class="btn btn-ghost btn-sm">
                    <i class="fa-solid fa-xmark"></i> Clear
                </a>
                <?php endif; ?>

                <span class="filter-count">
                    <?= number_format($totalRows) ?> event<?= $totalRows !== 1 ? 's' : '' ?> total
                </span>
            </form>
        </div>

        <!-- Events table -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-list-check"></i> Audit Events</span>
                <span style="font-size:.78rem;color:var(--text-light);">
                    Page <?= $page ?> of <?= $totalPages ?>
                </span>
            </div>

            <?php if (empty($events)): ?>
            <div class="empty-state">
                <i class="fa-solid fa-file-shield"></i>
                No compliance events found matching your filters.
            </div>
            <?php else: ?>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Event</th>
                            <th>Actor</th>
                            <th>Target / Guest</th>
                            <th>Contribution</th>
                            <th>IP Address</th>
                            <th>Meta</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($events as $ev):
                        [$label, $cls] = $eventLabels[$ev['event_type']] ?? [$ev['event_type'], 'el-grey'];
                        $meta = $ev['meta_json'] ? json_decode($ev['meta_json'], true) : null;
                        $metaDisplay = $ev['meta_json'] ? htmlspecialchars(substr($ev['meta_json'], 0, 80)) . (strlen($ev['meta_json']) > 80 ? '…' : '') : '—';
                    ?>
                    <tr>
                        <td style="white-space:nowrap;color:var(--text-muted);font-size:.8rem;"><?= fmtDT($ev['created_at']) ?></td>
                        <td><span class="el-badge <?= $cls ?>"><?= htmlspecialchars($label) ?></span></td>
                        <td style="font-size:.82rem;">
                            <?php if ($ev['actor_email']): ?>
                                <span style="font-weight:500;"><?= htmlspecialchars($ev['actor_email']) ?></span>
                                <br><span style="color:var(--text-light);font-size:.74rem;">ID: <?= $ev['actor_id'] ?></span>
                            <?php elseif ($ev['actor_id']): ?>
                                <span style="color:var(--text-muted);">User #<?= $ev['actor_id'] ?></span>
                            <?php else: ?>
                                <span style="color:var(--text-light);">System</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.82rem;">
                            <?php if ($ev['target_email']): ?>
                                <?= htmlspecialchars($ev['target_email']) ?>
                            <?php elseif ($ev['guest_email']): ?>
                                <span style="color:var(--navy-light);"><?= htmlspecialchars($ev['guest_email']) ?></span>
                                <span class="el-badge el-blue" style="font-size:.66rem;margin-left:.3rem;">External</span>
                            <?php else: ?>
                                <span style="color:var(--text-light);">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:.8rem;color:var(--text-muted);">
                            <?= $ev['contribution_id'] ? '#' . $ev['contribution_id'] : '—' ?>
                        </td>
                        <td style="font-family:monospace;font-size:.78rem;color:var(--text-muted);"><?= htmlspecialchars($ev['ip_address'] ?? '—') ?></td>
                        <td>
                            <?php if ($ev['meta_json']): ?>
                            <span class="meta-cell" title="<?= htmlspecialchars($ev['meta_json']) ?>">
                                <?= $metaDisplay ?>
                            </span>
                            <?php else: ?>
                            <span style="color:var(--text-light);">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <a href="<?= pgLink(1) ?>" class="pg-link <?= $page === 1 ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-angles-left"></i>
                </a>
                <a href="<?= pgLink(max(1, $page - 1)) ?>" class="pg-link <?= $page === 1 ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-angle-left"></i>
                </a>
                <?php
                $startPg = max(1, $page - 2);
                $endPg   = min($totalPages, $page + 2);
                for ($pg = $startPg; $pg <= $endPg; $pg++): ?>
                <a href="<?= pgLink($pg) ?>" class="pg-link <?= $pg === $page ? 'active' : '' ?>"><?= $pg ?></a>
                <?php endfor; ?>
                <a href="<?= pgLink(min($totalPages, $page + 1)) ?>" class="pg-link <?= $page === $totalPages ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-angle-right"></i>
                </a>
                <a href="<?= pgLink($totalPages) ?>" class="pg-link <?= $page === $totalPages ? 'disabled' : '' ?>">
                    <i class="fa-solid fa-angles-right"></i>
                </a>
                <span class="pg-info">
                    Showing <?= (($page - 1) * $perPage) + 1 ?>–<?= min($page * $perPage, $totalRows) ?> of <?= number_format($totalRows) ?>
                </span>
            </div>
            <?php endif; ?>

            <?php endif; ?>
        </div>

        <!-- Append-only notice -->
        <div style="margin-top:1.25rem;padding:.85rem 1rem;background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);font-size:.78rem;color:var(--text-muted);display:flex;align-items:flex-start;gap:.6rem;line-height:1.55;">
            <i class="fa-solid fa-lock" style="color:var(--navy-light);flex-shrink:0;margin-top:.1rem;"></i>
            <div>
                <strong style="color:var(--text);">Tamper-evident log.</strong>
                This table is enforced append-only at the database level — no UPDATE or DELETE operations are permitted. Events may only be added, never removed or modified.
                The CSV export is admissible as a compliance record for FAIS / FSCA review.
            </div>
        </div>

    </main>
</div>

</body>
</html>