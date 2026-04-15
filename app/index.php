<?php
require_once 'includes/security.php';
require_once 'includes/session.php';
require_once 'includes/database.php';
require_once 'includes/functions.php';
require_once 'includes/InvestmentService.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php?redirect=' . urlencode('/app/'));
}

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance();

/* ── Load all contributions ──────────────────── */
$contributions = InvestmentService::getContributionsByUser($userId);

/* ── Portfolio aggregate stats ───────────────── */
$totalInvested  = 0.0;
$totalReceived  = 0.0;
$activeCount    = 0;
$pendingCount   = 0;

foreach ($contributions as $con) {
    $status = $con['status'];
    if (in_array($status, ['paid', 'active', 'completed'], true)) {
        $totalInvested += (float)$con['amount'];
    }
    if (in_array($status, ['active', 'completed'], true)) {
        $activeCount++;
    }
    if ($status === 'pending_payment') {
        $pendingCount++;
    }
}

/* ── Total received from payout_ledger ───────── */
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(amount), 0)
    FROM payout_ledger
    WHERE user_id = :uid AND status = 'paid'
");
$stmt->execute(['uid' => $userId]);
$totalReceived = (float)$stmt->fetchColumn();

/* ── Recent payouts (last 5) ─────────────────── */
$stmt = $pdo->prepare("
    SELECT
        pl.id, pl.amount, pl.period_year, pl.period_month,
        pl.status, pl.paid_at, pl.payment_method,
        fc.title AS campaign_title,
        c.name   AS company_name,
        c.uuid   AS company_uuid
    FROM payout_ledger pl
    JOIN funding_campaigns fc ON fc.id = pl.campaign_id
    JOIN companies c ON c.id = fc.company_id
    WHERE pl.user_id = :uid
    ORDER BY pl.created_at DESC
    LIMIT 5
");
$stmt->execute(['uid' => $userId]);
$recentPayouts = $stmt->fetchAll();

/* ── Watchlist (campaign_interests) ─────────── */
$stmt = $pdo->prepare("
    SELECT
        ci.interest_type, ci.indicative_amount, ci.created_at,
        fc.uuid  AS campaign_uuid,
        fc.title AS campaign_title,
        fc.status AS campaign_status,
        fc.raise_target, fc.total_raised,
        fc.closes_at,
        c.name AS company_name,
        c.uuid AS company_uuid,
        c.logo AS company_logo
    FROM campaign_interests ci
    JOIN funding_campaigns fc ON fc.id = ci.campaign_id
    JOIN companies c ON c.id = fc.company_id
    WHERE ci.user_id = :uid
    ORDER BY ci.updated_at DESC
    LIMIT 6
");
$stmt->execute(['uid' => $userId]);
$watchlist = $stmt->fetchAll();

/* ── User info for header ────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me  = $stmt->fetch();
$ini = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

/* ── Helpers ─────────────────────────────────── */
function fmtR($v) {
    if ($v === null || $v === '') return 'R 0';
    return 'R ' . number_format((float)$v, 2, '.', ' ');
}
function fmtDate($v) {
    return $v ? date('d M Y', strtotime($v)) : '—';
}

$monthNames = [
    1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
    7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec',
];

$statusConfig = [
    'pending_payment' => ['Pending Payment',  'sc-pending',   'fa-clock'],
    'paid'            => ['Paid',             'sc-paid',      'fa-circle-check'],
    'under_review'    => ['Under Review',     'sc-review',    'fa-magnifying-glass'],
    'active'          => ['Active',           'sc-active',    'fa-rocket'],
    'refunded'        => ['Refunded',         'sc-refunded',  'fa-rotate-left'],
    'defaulted'       => ['Defaulted',        'sc-defaulted', 'fa-triangle-exclamation'],
    'completed'       => ['Completed',        'sc-completed', 'fa-trophy'],
];

$campaignTypeConfig = [
    'revenue_share'          => ['Revenue Share',    'fa-chart-line',   'ct-rs'],
    'cooperative_membership' => ['Co-op Membership', 'fa-people-roof',  'ct-co'],
    'fixed_return_loan'      => ['Fixed Return',     'fa-hand-holding-dollar', 'ct-loan'],
    'donation'               => ['Donation',         'fa-heart',        'ct-don'],
];

$payoutStatusConfig = [
    'pending'    => ['Pending',    'ps-pending'],
    'processing' => ['Processing', 'ps-processing'],
    'paid'       => ['Paid',       'ps-paid'],
    'failed'     => ['Failed',     'ps-failed'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Portfolio | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        /* ----------------------------------------------
           1. GLOBAL & RESET
        ------------------------------------------------ */
        :root {
            --navy:#0b2545;
            --navy-mid:#0f3b7a;
            --navy-light:#1a56b0;
            --amber:#f59e0b;
            --amber-dark:#d97706;
            --amber-light:#fef3c7;
            --green:#0b6b4d;
            --green-bg:#e6f7ec;
            --green-bdr:#a7f3d0;
            --surface:#fff;
            --surface-2:#f8f9fb;
            --border:#e4e7ec;
            --text:#101828;
            --text-muted:#667085;
            --text-light:#98a2b3;
            --error:#b91c1c;
            --error-bg:#fef2f2;
            --error-bdr:#fecaca;
            --radius:14px;
            --radius-sm:8px;
            --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
            --header-h:64px;
            --sidebar-w:240px;
            --transition:.2s cubic-bezier(.4,0,.2,1);
        }

        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface-2);
            color: var(--text);
            min-height: 100vh;
        }

        /* ----------------------------------------------
           2. HEADER & TOP BAR
        ------------------------------------------------ */
        .top-header {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            height: var(--header-h);
            background: var(--surface);
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            padding: 0 1.75rem;
            justify-content: space-between;
            z-index: 100;
            gap: 1rem;
        }

        .header-brand {
            font-family: 'DM Serif Display', serif;
            font-size: 1.35rem;
            color: var(--navy);
            text-decoration: none;
            white-space: nowrap;
        }
        .header-brand span { color: #c8102e; }

        .header-nav {
            display: flex;
            align-items: center;
            gap: .15rem;
            flex: 1;
            justify-content: center;
        }
        .header-nav a {
            display: flex;
            align-items: center;
            gap: .4rem;
            padding: .4rem .85rem;
            border-radius: 6px;
            font-size: .85rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--transition);
            white-space: nowrap;
        }
        .header-nav a:hover {
            background: var(--surface-2);
            color: var(--text);
        }
        .header-nav a.active {
            background: #eff4ff;
            color: var(--navy-mid);
        }

        .logo-container {
            display: flex;
            align-items: center;
        }
        .logo {
            font-family: 'Playfair Display', serif;
            font-weight: 600;
            font-size: 18px;
            color: #333;
            text-decoration: none;
            text-transform: uppercase;
            display: inline-block;
        }
        .logo span { display: inline-block; }
        .logo .second { color: #c8102e; font-family: 'Playfair Display', serif; }
        .logo .first, .logo .big { font-size: 1.4rem; line-height: 0.8; vertical-align: baseline; font-family: 'Playfair Display', serif; }

        .avatar {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: linear-gradient(135deg, #6a11cb, #2575fc);
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            color: #fff;
            font-size: .9rem;
            flex-shrink: 0;
            cursor: pointer;
        }

        /* ----------------------------------------------
           3. DROPDOWN (MOBILE ONLY)
        ------------------------------------------------ */
        .header-dropdown {
            position: fixed;
            top: calc(var(--header-h) + 1px);
            right: 0;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 0 0 12px 12px;
            box-shadow: 0 12px 32px rgba(36, 33, 33, 0.12);
            min-width: 260px;
            z-index: 1001;
            opacity: 0;
            transform: translateY(-8px) scale(0.98);
            pointer-events: none;
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .header-dropdown--open {
            opacity: 1;
            transform: translateY(0) scale(1);
            pointer-events: all;
        }
        .header-dropdown-inner { padding: 0.5rem 0; }
        .header-section-label {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.08em;
            color: var(--text-light);
            padding: 0.7rem 1rem 0.3rem;
        }
        .header-dropdown-link {
            display: flex;
            align-items: center;
            gap: 0.75rem;
            padding: 0.6rem 1rem;
            color: #1e293b;
            text-decoration: none;
            font-size: 0.85rem;
            font-weight: 500;
            transition: background 0.15s ease;
        }
        .header-dropdown-link:hover {
            background: var(--surface-2);
            color: #0f3b7a;
        }
        .header-dropdown-link i {
            width: 20px;
            text-align: center;
            font-size: 0.9rem;
            color: #5b6e8c;
        }
        .header-dropdown-link.active {
            background: #e8f0fe;
            color: #0f3b7a;
            font-weight: 600;
        }
        .header-dropdown-link.active i { color: #0f3b7a; }
        .header-dropdown-link.danger {
            color: #b91c1c;
        }
        .header-dropdown-link.danger:hover {
            background: #fef2f2;
            color: #b91c1c;
        }
        .header-divider {
            height: 1px;
            background: var(--border);
            margin: 0.3rem 0;
        }

        /* ----------------------------------------------
           4. SIDEBAR
        ------------------------------------------------ */
        .page-wrapper {
            padding-top: var(--header-h);
            display: flex;
            min-height: 100vh;
        }
        .sidebar {
            width: var(--sidebar-w);
            background: var(--surface);
            border-right: 1px solid var(--border);
            position: sticky;
            top: var(--header-h);
            height: calc(100vh - var(--header-h));
            overflow-y: auto;
            padding: 1.5rem 1rem;
            flex-shrink: 0;
        }
        .sidebar-section-label {
            font-size: .7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .1em;
            color: var(--text-light);
            padding: 0 .5rem;
            margin-bottom: .5rem;
            margin-top: 1.25rem;
        }
        .sidebar-section-label:first-child { margin-top: 0; }
        .sidebar a {
            display: flex;
            align-items: center;
            gap: .6rem;
            padding: .55rem .75rem;
            border-radius: var(--radius-sm);
            font-size: .86rem;
            font-weight: 500;
            color: var(--text-muted);
            text-decoration: none;
            transition: all var(--transition);
            margin-bottom: .1rem;
        }
        .sidebar a:hover {
            background: var(--surface-2);
            color: var(--text);
        }
        .sidebar a.active {
            background: #eff4ff;
            color: var(--navy-mid);
            font-weight: 600;
        }
        .sidebar a i {
            width: 16px;
            text-align: center;
            font-size: .85rem;
        }

        /* ----------------------------------------------
           5. MAIN CONTENT
        ------------------------------------------------ */
        .main-content {
            flex: 1;
            padding: 2rem 2.5rem;
            min-width: 0;
        }
        .page-head {
            margin-bottom: 1.75rem;
        }
        .page-head h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.75rem;
            color: var(--navy);
            margin-bottom: .25rem;
        }
        .page-head p {
            font-size: .9rem;
            color: var(--text-muted);
        }

        /* ----------------------------------------------
           6. STAT TILES
        ------------------------------------------------ */
        .stat-strip {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 1rem;
            margin-bottom: 1.75rem;
        }
        .stat-tile {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 1.25rem;
        }
        .stat-tile-icon {
            width: 38px;
            height: 38px;
            border-radius: var(--radius-sm);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: .95rem;
            margin-bottom: .5rem;
        }
        .si-invested { background: #eff4ff; color: var(--navy-light); }
        .si-received { background: var(--green-bg); color: var(--green); }
        .si-active   { background: var(--amber-light); color: var(--amber-dark); }
        .si-pending  { background: #f1f5f9; color: #64748b; }
        .stat-tile-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--navy);
            line-height: 1;
            margin-bottom: .2rem;
        }
        .stat-tile-label {
            font-size: .77rem;
            color: var(--text-muted);
            font-weight: 500;
        }

        /* ----------------------------------------------
           7. CARDS
        ------------------------------------------------ */
        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            margin-bottom: 1.5rem;
        }
        .card-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .9rem 1.25rem;
            border-bottom: 1px solid var(--border);
        }
        .card-title {
            display: flex;
            align-items: center;
            gap: .5rem;
            font-size: .83rem;
            font-weight: 700;
            color: var(--navy);
            text-transform: uppercase;
            letter-spacing: .07em;
        }
        .card-title i { color: var(--navy-light); }
        .card-link {
            font-size: .8rem;
            font-weight: 600;
            color: var(--navy-light);
            text-decoration: none;
            display: flex;
            align-items: center;
            gap: .3rem;
        }
        .card-link:hover { color: var(--navy); }

        /* ----------------------------------------------
           8. INVESTMENT ROWS
        ------------------------------------------------ */
        .contribution-row {
            display: flex;
            align-items: center;
            gap: 1rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid var(--border);
            text-decoration: none;
            color: inherit;
            transition: background var(--transition);
        }
        .contribution-row:last-child { border-bottom: none; }
        .contribution-row:hover { background: var(--surface-2); }
        .con-logo {
            width: 42px;
            height: 42px;
            border-radius: 8px;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface-2);
            border: 1px solid var(--border);
        }
        .con-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .con-logo-ph {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Serif Display', serif;
            font-size: 1.1rem;
            color: #fff;
        }
        .con-info {
            flex: 1;
            min-width: 0;
        }
        .con-company {
            font-size: .77rem;
            color: var(--text-light);
            font-weight: 500;
            margin-bottom: .1rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .con-title {
            font-size: .9rem;
            font-weight: 600;
            color: var(--navy);
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            margin-bottom: .25rem;
        }
        .con-meta {
            display: flex;
            align-items: center;
            gap: .4rem;
            flex-wrap: wrap;
        }
        .con-amount {
            font-size: .95rem;
            font-weight: 700;
            color: var(--navy);
            text-align: right;
            flex-shrink: 0;
        }
        .con-date {
            font-size: .72rem;
            color: var(--text-light);
            text-align: right;
            margin-top: .1rem;
        }
        .con-arrow {
            color: var(--text-light);
            font-size: .75rem;
            flex-shrink: 0;
        }

        /* ----------------------------------------------
           9. BADGES (Status & Campaign Types)
        ------------------------------------------------ */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: .25rem;
            padding: .17rem .6rem;
            border-radius: 99px;
            font-size: .72rem;
            font-weight: 600;
            border: 1px solid transparent;
        }
        /* Contribution status */
        .sc-pending   { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .sc-paid      { background:#eff4ff; color:var(--navy-light); border-color:#c7d9f8; }
        .sc-review    { background:var(--amber-light); color:#78350f; border-color:var(--amber); }
        .sc-active    { background:var(--green-bg); color:var(--green); border-color:var(--green-bdr); }
        .sc-refunded  { background:#f1f5f9; color:#64748b; border-color:#cbd5e1; }
        .sc-defaulted { background:var(--error-bg); color:var(--error); border-color:var(--error-bdr); }
        .sc-completed { background:var(--green-bg); color:var(--green); border-color:var(--green-bdr); }
        /* Campaign type */
        .ct-rs  { background:#eff4ff; color:var(--navy-light); border-color:#c7d9f8; }
        .ct-co  { background:var(--green-bg); color:var(--green); border-color:var(--green-bdr); }
        .ct-loan{ background:var(--amber-light); color:#78350f; border-color:var(--amber); }
        .ct-don { background:#fce7f3; color:#9d174d; border-color:#fbcfe8; }
        /* Payout status */
        .ps-pending    { background:#f1f5f9; color:#475569; border-color:#cbd5e1; }
        .ps-processing { background:var(--amber-light); color:#78350f; border-color:var(--amber); }
        .ps-paid       { background:var(--green-bg); color:var(--green); border-color:var(--green-bdr); }
        .ps-failed     { background:var(--error-bg); color:var(--error); border-color:var(--error-bdr); }

        /* ----------------------------------------------
           10. PAYOUT ROWS
        ------------------------------------------------ */
        .payout-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: .75rem 1.25rem;
            border-bottom: 1px solid var(--border);
            gap: 1rem;
            flex-wrap: wrap;
        }
        .payout-row:last-child { border-bottom: none; }
        .payout-info {
            flex: 1;
            min-width: 0;
        }
        .payout-title {
            font-size: .86rem;
            font-weight: 600;
            color: var(--text);
            margin-bottom: .12rem;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .payout-period {
            font-size: .74rem;
            color: var(--text-light);
        }
        .payout-right {
            text-align: right;
            flex-shrink: 0;
        }
        .payout-amount {
            font-size: .95rem;
            font-weight: 700;
            color: var(--green);
        }
        .payout-date {
            font-size: .73rem;
            color: var(--text-light);
            margin-top: .1rem;
        }

        /* ----------------------------------------------
           11. WATCHLIST
        ------------------------------------------------ */
        .watchlist-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
            gap: 1rem;
            padding: 1.25rem;
        }
        .watch-card {
            background: var(--surface-2);
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 1rem;
            text-decoration: none;
            color: inherit;
            display: flex;
            flex-direction: column;
            gap: .6rem;
            transition: all var(--transition);
        }
        .watch-card:hover {
            border-color: var(--navy-light);
            background: #eff4ff;
        }
        .watch-card-top {
            display: flex;
            align-items: center;
            gap: .6rem;
        }
        .watch-logo {
            width: 32px;
            height: 32px;
            border-radius: 6px;
            overflow: hidden;
            flex-shrink: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--surface);
            border: 1px solid var(--border);
        }
        .watch-logo img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .watch-logo-ph {
            width: 100%;
            height: 100%;
            background: linear-gradient(135deg, var(--navy-mid), var(--navy-light));
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'DM Serif Display', serif;
            font-size: .9rem;
            color: #fff;
        }
        .watch-company {
            font-size: .74rem;
            color: var(--text-light);
            font-weight: 500;
        }
        .watch-title {
            font-size: .86rem;
            font-weight: 600;
            color: var(--navy);
            line-height: 1.3;
        }
        .watch-prog-outer {
            height: 5px;
            background: var(--border);
            border-radius: 99px;
            overflow: hidden;
        }
        .watch-prog-inner {
            height: 100%;
            background: var(--amber);
            border-radius: 99px;
        }
        .watch-stats {
            display: flex;
            justify-content: space-between;
            font-size: .72rem;
            color: var(--text-light);
        }

        /* ----------------------------------------------
           12. EMPTY STATES & CTA BANNER
        ------------------------------------------------ */
        .empty-state {
            text-align: center;
            padding: 3rem 1.5rem;
            color: var(--text-light);
        }
        .empty-state i {
            font-size: 2rem;
            display: block;
            margin-bottom: .75rem;
            color: var(--border);
        }
        .empty-state p {
            font-size: .88rem;
            margin-bottom: 1rem;
        }
        .cta-banner {
            background: var(--navy);
            border-radius: var(--radius);
            padding: 1.75rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1.5rem;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
        }
        .cta-text h2 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.3rem;
            color: #fff;
            margin-bottom: .25rem;
        }
        .cta-text p {
            font-size: .85rem;
            color: rgba(255,255,255,.65);
        }
        .btn-cta {
            display: inline-flex;
            align-items: center;
            gap: .45rem;
            padding: .65rem 1.35rem;
            border-radius: 99px;
            font-family: 'DM Sans', sans-serif;
            font-size: .88rem;
            font-weight: 700;
            background: var(--amber);
            color: var(--navy);
            text-decoration: none;
            transition: all var(--transition);
            white-space: nowrap;
            flex-shrink: 0;
        }
        .btn-cta:hover {
            background: var(--amber-dark);
            color: #fff;
        }

        /* ----------------------------------------------
           13. MEDIA QUERIES
        ------------------------------------------------ */
        @media (max-width: 1024px) {
            .page-wrapper{
                margin-bottom: 2rem;
            }
        }
        @media (max-width: 1100px) {
            .stat-strip { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main-content { padding: 1.25rem; }
            .stat-strip { grid-template-columns: 1fr 1fr; }
        }
        @media (max-width: 540px) {
            .stat-strip { grid-template-columns: 1fr; }
            .header-nav { display: none; }
            .contribution-row { flex-wrap: wrap; }
            .cta-banner { flex-direction: column; align-items: flex-start; }
        }

        /* Desktop: hide dropdown panel */
        @media screen and (min-width: 1024px) {
            .header-dropdown {
                display: none !important;
            }
        }

        ::-webkit-scrollbar { width: 2px; }
        ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
    </style>
</head>
<body>

<header class="top-header">
    <div class="logo-container">
        <a href="/app/" class="logo"><span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span></a>
    </div>
    <nav class="header-nav">
        <a href="/app/" class="active"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Discover</a>
        <!--<a href="/app/company/"><i class="fa-solid fa-building"></i> My Companies</a>-->
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar" id="AvatarBtn"><?php echo htmlspecialchars($ini); ?></div>
    <div class="header-dropdown" id="UserDropdown" role="menu" aria-label="User menu">
        <div class="header-dropdown-inner">
            <div class="header-section-label">Dashboard</div>
            <a href="/investor/" class="header-dropdown-link active">
                <i class="fa-solid fa-chart-pie"></i> Portfolio
            </a>
            <a href="/app/?tab=payouts" class="header-dropdown-link">
                <i class="fa-solid fa-coins"></i> Payouts
            </a>
            <a href="/app/?tab=watchlist" class="header-dropdown-link">
                <i class="fa-solid fa-bookmark"></i> Watchlist
            </a>
            <div class="header-section-label">Discover</div>
            <a href="/app/discover/" class="header-dropdown-link">
                <i class="fa-solid fa-compass"></i> Browse Businesses
            </a>
            <div class="header-section-label">Account</div>
            <a href="/app/wallet/" class="header-dropdown-link">
                <i class="fa-solid fa-wallet"></i> Wallet
            </a>
            <a href="/app/profile.php" class="header-dropdown-link">
                <i class="fa-solid fa-user"></i> Profile
            </a>
            <div class="header-divider"></div>
            <a href="/app/auth/logout.php" class="header-dropdown-link danger">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </div>
    </div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Dashboard</div>
        <a href="/app/" class="active"><i class="fa-solid fa-chart-pie"></i> Portfolio</a>
        <a href="/app/?tab=payouts"><i class="fa-solid fa-coins"></i> Payouts</a>
        <a href="/app/?tab=watchlist"><i class="fa-solid fa-bookmark"></i> Watchlist</a>
        <div class="sidebar-section-label">Discover</div>
        <a href="/app/discover/"><i class="fa-solid fa-compass"></i> Browse Businesses</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
        <a href="/app/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">
        <div class="page-head">
            <h1>My Portfolio</h1>
            <p>Track your investments, payouts, and the businesses you're backing.</p>
        </div>

        <!-- Stats -->
        <div class="stat-strip">
            <div class="stat-tile">
                <div class="stat-tile-icon si-invested"><i class="fa-solid fa-hand-holding-dollar"></i></div>
                <div class="stat-tile-value"><?php echo fmtR($totalInvested); ?></div>
                <div class="stat-tile-label">Total Invested</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile-icon si-received"><i class="fa-solid fa-coins"></i></div>
                <div class="stat-tile-value"><?php echo fmtR($totalReceived); ?></div>
                <div class="stat-tile-label">Total Received Back</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile-icon si-active"><i class="fa-solid fa-rocket"></i></div>
                <div class="stat-tile-value"><?php echo $activeCount; ?></div>
                <div class="stat-tile-label">Active Investments</div>
            </div>
            <div class="stat-tile">
                <div class="stat-tile-icon si-pending"><i class="fa-solid fa-clock"></i></div>
                <div class="stat-tile-value"><?php echo $pendingCount; ?></div>
                <div class="stat-tile-label">Pending EFT Payments</div>
            </div>
        </div>

        <!-- Discover CTA when no active investments -->
        <?php if ($activeCount === 0 && empty($contributions)): ?>
        <div class="cta-banner">
            <div class="cta-text">
                <h2>Start building your portfolio.</h2>
                <p>Browse verified township and community businesses raising capital right now.</p>
            </div>
            <a href="/browse/" class="btn-cta">
                <i class="fa-solid fa-compass"></i> Discover Businesses
            </a>
        </div>
        <?php endif; ?>

        <!-- Investments -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-briefcase"></i> My Investments</span>
                <span style="font-size:.78rem;color:var(--text-light);"><?php echo count($contributions); ?> total</span>
            </div>
            <?php if (empty($contributions)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-briefcase"></i>
                    <p>You haven't made any investments yet.</p>
                    <a href="/browse/" style="color:var(--navy-light);font-weight:600;font-size:.85rem;">Browse businesses →</a>
                </div>
            <?php else: ?>
                <?php foreach ($contributions as $con):
                    $sInfo = $statusConfig[$con['status']] ?? ['Unknown', 'sc-pending', 'fa-question'];
                    $tInfo = $campaignTypeConfig[$con['campaign_type']] ?? ['Campaign', 'fa-rocket', 'ct-rs'];
                ?>
                    <a href="/app/contribution.php?id=<?php echo (int)$con['id']; ?>" class="contribution-row">
                        <div class="con-logo">
                            <?php if ($con['company_logo']): ?>
                                <img src="<?php echo htmlspecialchars($con['company_logo']); ?>"
                                     alt="<?php echo htmlspecialchars($con['company_name']); ?>">
                            <?php else: ?>
                                <div class="con-logo-ph"><?php echo strtoupper(substr($con['company_name'], 0, 1)); ?></div>
                            <?php endif; ?>
                        </div>
                        <div class="con-info">
                            <div class="con-company"><?php echo htmlspecialchars($con['company_name']); ?></div>
                            <div class="con-title"><?php echo htmlspecialchars($con['campaign_title']); ?></div>
                            <div class="con-meta">
                                <span class="badge <?php echo $sInfo[1]; ?>">
                                    <i class="fa-solid <?php echo $sInfo[2]; ?>"></i>
                                    <?php echo $sInfo[0]; ?>
                                </span>
                                <span class="badge <?php echo $tInfo[2]; ?>">
                                    <i class="fa-solid <?php echo $tInfo[1]; ?>"></i>
                                    <?php echo $tInfo[0]; ?>
                                </span>
                            </div>
                        </div>
                        <div>
                            <div class="con-amount"><?php echo fmtR($con['amount']); ?></div>
                            <div class="con-date"><?php echo fmtDate($con['created_at']); ?></div>
                        </div>
                        <i class="fa-solid fa-chevron-right con-arrow"></i>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Recent Payouts -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-coins"></i> Recent Payouts</span>
                <?php if (!empty($recentPayouts)): ?>
                <a href="/investor/?tab=payouts" class="card-link">
                    View all <i class="fa-solid fa-arrow-right"></i>
                </a>
                <?php endif; ?>
            </div>
            <?php if (empty($recentPayouts)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-coins"></i>
                    <p>No payouts yet. Payouts appear here once campaigns begin distributing returns.</p>
                </div>
            <?php else: ?>
                <?php foreach ($recentPayouts as $pay):
                    $psInfo = $payoutStatusConfig[$pay['status']] ?? ['Unknown', 'ps-pending'];
                    $period = ($monthNames[(int)$pay['period_month']] ?? '') . ' ' . $pay['period_year'];
                ?>
                    <div class="payout-row">
                        <div class="payout-info">
                            <div class="payout-title"><?php echo htmlspecialchars($pay['company_name']); ?> — <?php echo htmlspecialchars($pay['campaign_title']); ?></div>
                            <div class="payout-period">
                                <?php echo htmlspecialchars($period); ?>
                                &nbsp;·&nbsp;
                                <span class="badge <?php echo $psInfo[1]; ?>"><?php echo $psInfo[0]; ?></span>
                            </div>
                        </div>
                        <div class="payout-right">
                            <div class="payout-amount">+<?php echo fmtR($pay['amount']); ?></div>
                            <div class="payout-date"><?php echo $pay['paid_at'] ? fmtDate($pay['paid_at']) : '—'; ?></div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- Watchlist -->
        <?php if (!empty($watchlist)): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-bookmark"></i> Watchlist</span>
            </div>
            <div class="watchlist-grid">
                <?php foreach ($watchlist as $w):
                    $wt = (float)$w['raise_target'];
                    $wr = (float)$w['total_raised'];
                    $wp = $wt > 0 ? min(100, round(($wr / $wt) * 100)) : 0;
                ?>
                    <a href="/browse/company.php?uuid=<?php echo urlencode($w['company_uuid']); ?>" class="watch-card">
                        <div class="watch-card-top">
                            <div class="watch-logo">
                                <?php if ($w['company_logo']): ?>
                                    <img src="<?php echo htmlspecialchars($w['company_logo']); ?>"
                                         alt="<?php echo htmlspecialchars($w['company_name']); ?>">
                                <?php else: ?>
                                    <div class="watch-logo-ph"><?php echo strtoupper(substr($w['company_name'], 0, 1)); ?></div>
                                <?php endif; ?>
                            </div>
                            <div>
                                <div class="watch-company"><?php echo htmlspecialchars($w['company_name']); ?></div>
                            </div>
                        </div>
                        <div class="watch-title"><?php echo htmlspecialchars($w['campaign_title']); ?></div>
                        <div class="watch-prog-outer">
                            <div class="watch-prog-inner" style="width:<?php echo $wp; ?>%"></div>
                        </div>
                        <div class="watch-stats">
                            <span><?php echo fmtR($w['total_raised']); ?> raised</span>
                            <span><?php echo $wp; ?>%</span>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </main>
</div>
<?php include('classes/footer.php'); ?>

<script>
    (function() {
        'use strict';

        const avatarBtn = document.getElementById('AvatarBtn');
        const dropdown = document.getElementById('UserDropdown');

        function closeDropdown() {
            if (dropdown) dropdown.classList.remove('header-dropdown--open');
            if (avatarBtn) avatarBtn.setAttribute('aria-expanded', 'false');
        }

        function toggleDropdown() {
            // Only allow dropdown on screens smaller than 1024px
            if (window.innerWidth >= 1024) {
                closeDropdown();
                return;
            }

            if (!dropdown || !avatarBtn) return;
            const isOpen = dropdown.classList.contains('header-dropdown--open');
            if (isOpen) {
                closeDropdown();
            } else {
                dropdown.classList.add('header-dropdown--open');
                avatarBtn.setAttribute('aria-expanded', 'true');
            }
        }

        // Handle resize: if width >= 1024, ensure dropdown is closed and event disabled
        function handleResize() {
            if (window.innerWidth >= 1024) {
                closeDropdown();
            }
        }

        if (avatarBtn) {
            avatarBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                toggleDropdown();
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (dropdown && !dropdown.contains(e.target) && !avatarBtn.contains(e.target)) {
                closeDropdown();
            }
        });

        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeDropdown();
            }
        });

        window.addEventListener('resize', handleResize);
        handleResize(); // initial check
    })();
</script>

</body>
</html>