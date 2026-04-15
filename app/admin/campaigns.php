<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

// Auth guard — uncomment in production:
// if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['admin','super_admin'])) {
//     die('Access denied.');
// }

$pdo = Database::getInstance();

/* ═══════════════════════════════════════════
   POST — campaign status actions (PRG)
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['campaign_id'])) {
    $campaignId = (int) $_POST['campaign_id'];
    $action     = $_POST['action'];

    // Fetch current campaign for validation
    $cur = $pdo->prepare("SELECT status, company_id FROM funding_campaigns WHERE id = ?");
    $cur->execute([$campaignId]);
    $curRow = $cur->fetch();

    if ($curRow) {
        $validTransitions = [
            // action         => [allowed from statuses]           → new status
            'submit_review'  => [['draft'],                         'under_review'],
            'approve'        => [['under_review'],                   'approved'],
            'return_draft'   => [['under_review'],                   'draft'],
            'open'           => [['approved'],                       'open'],
            'suspend'        => [['open', 'funded'],                 'suspended'],
            'reinstate'      => [['suspended'],                      'open'],
            'cancel'         => [['draft','approved','open','suspended'], 'cancelled'],
            'close_success'  => [['open', 'funded'],                'closed_successful'],
            'close_fail'     => [['open'],                           'closed_unsuccessful'],
        ];

        if (isset($validTransitions[$action])) {
            [$fromStatuses, $toStatus] = $validTransitions[$action];
            if (in_array($curRow['status'], $fromStatuses, true)) {
                $pdo->prepare("UPDATE funding_campaigns SET status = ?, updated_at = NOW() WHERE id = ?")
                    ->execute([$toStatus, $campaignId]);
                logCompanyActivity($curRow['company_id'], $_SESSION['user_id'],
                    "Campaign #{$campaignId} status changed from {$curRow['status']} to {$toStatus}");
                $flash = urlencode("Campaign " . str_replace('_', ' ', $toStatus) . ".");
            }
        }
    }

    // Preserve filter state across redirect
    $qs = http_build_query(array_filter([
        'q'       => $_POST['_q']       ?? '',
        'status'  => $_POST['_status']  ?? '',
        'type'    => $_POST['_type']    ?? '',
        'company' => $_POST['_company'] ?? '',
        'page'    => $_POST['_page']    ?? '',
    ]));
    header("Location: campaigns.php?{$qs}" . (isset($flash) ? "&flash={$flash}" : ''));
    exit;
}

/* ─── Flash ─────────────────────────────── */
$flashMsg = null;
if (!empty($_GET['flash'])) {
    $flashMsg = urldecode($_GET['flash']);
}

/* ═══════════════════════════════════════════
   FILTERS & PAGINATION
═══════════════════════════════════════════ */
$search   = trim($_GET['q']       ?? '');
$fStatus  = $_GET['status']       ?? '';
$fType    = $_GET['type']         ?? '';
$fCompany = trim($_GET['company'] ?? '');
$sort     = in_array($_GET['sort'] ?? '', ['title','created_at','opens_at','closes_at','total_raised','status'])
            ? $_GET['sort'] : 'created_at';
$dir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage  = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));

$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]    = '(fc.title LIKE :q OR c.name LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($fStatus !== '') {
    $where[]          = 'fc.status = :status';
    $params[':status'] = $fStatus;
}
if ($fType !== '') {
    $where[]        = 'fc.campaign_type = :type';
    $params[':type'] = $fType;
}
if ($fCompany !== '') {
    $where[]           = 'c.name LIKE :company';
    $params[':company'] = '%' . $fCompany . '%';
}

$whereClause = implode(' AND ', $where);

// Total count
$cntStmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM funding_campaigns fc
    JOIN companies c ON fc.company_id = c.id
    WHERE {$whereClause}
");
$cntStmt->execute($params);
$totalRows  = (int) $cntStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Main query
$stmt = $pdo->prepare("
    SELECT
        fc.id, fc.uuid, fc.title, fc.tagline, fc.campaign_type,
        fc.raise_target, fc.raise_minimum, fc.raise_maximum,
        fc.min_contribution, fc.max_contribution, fc.max_contributors,
        fc.opens_at, fc.closes_at, fc.funded_at,
        fc.status, fc.total_raised, fc.contributor_count,
        fc.created_at, fc.updated_at,
        c.id AS company_id, c.uuid AS company_uuid,
        c.name AS company_name, c.logo AS company_logo,
        c.status AS company_status,
        u.email AS creator_email
    FROM funding_campaigns fc
    JOIN companies c  ON fc.company_id = c.id
    LEFT JOIN users u ON fc.created_by  = u.id
    WHERE {$whereClause}
    ORDER BY fc.{$sort} {$dir}
    LIMIT :limit OFFSET :offset
");
$params[':limit']  = $perPage;
$params[':offset'] = $offset;
$stmt->execute($params);
$campaigns = $stmt->fetchAll();

/* ═══════════════════════════════════════════
   SIDEBAR STATS — MySQL-compatible
═══════════════════════════════════════════ */
$statsStmt = $pdo->query("SELECT status, COUNT(*) AS n FROM funding_campaigns GROUP BY status");
$statsRaw  = $statsStmt->fetchAll(PDO::FETCH_KEY_PAIR);

$stats = [
    'total'       => array_sum($statsRaw),
    'open'        => (int)($statsRaw['open']                ?? 0),
    'review'      => (int)($statsRaw['under_review']        ?? 0),
    'draft'       => (int)($statsRaw['draft']               ?? 0),
    'approved'    => (int)($statsRaw['approved']            ?? 0),
    'funded'      => (int)(($statsRaw['funded'] ?? 0) + ($statsRaw['closed_successful'] ?? 0)),
    'problem'     => (int)(($statsRaw['suspended'] ?? 0) + ($statsRaw['cancelled'] ?? 0) + ($statsRaw['closed_unsuccessful'] ?? 0)),
];

/* ═══════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════ */
function campaignStatusBadge(string $s): array {
    return [
        'draft'               => ['Draft',           'badge-grey'],
        'under_review'        => ['Under Review',    'badge-amber'],
        'approved'            => ['Approved',         'badge-blue'],
        'open'                => ['Live',             'badge-green'],
        'funded'              => ['Funded',           'badge-teal'],
        'closed_successful'   => ['Closed ✓',        'badge-teal'],
        'closed_unsuccessful' => ['Closed ✗',        'badge-red'],
        'cancelled'           => ['Cancelled',        'badge-red'],
        'suspended'           => ['Suspended',        'badge-red'],
    ][$s] ?? [ucfirst(str_replace('_',' ',$s)), 'badge-grey'];
}

function campaignTypeLabel(string $t): array {
    return [
        'revenue_share'          => ['Revenue Share',      'pill-amber'],
        'fixed_return_loan'      => ['Fixed Return Loan',  'pill-blue'],
        'cooperative_membership' => ['Co-op Membership',  'pill-teal'],
        'donation'               => ['Donation',           'pill-green'],
        'convertible_note'       => ['Convertible Note',  'pill-purple'],
    ][$t] ?? [ucfirst(str_replace('_',' ',$t)), 'pill-grey'];
}

function progressPct(float $raised, float $target): int {
    if ($target <= 0) return 0;
    return min(100, (int) round(($raised / $target) * 100));
}

function pageUrl(array $overrides = []): string {
    global $search, $fStatus, $fType, $fCompany, $sort, $dir, $page;
    $base = ['q'=>$search,'status'=>$fStatus,'type'=>$fType,'company'=>$fCompany,'sort'=>$sort,'dir'=>$dir,'page'=>$page];
    return 'campaigns.php?' . http_build_query(array_filter(array_merge($base, $overrides), fn($v) => $v !== '' && $v !== null));
}

function sortUrl(string $col): string {
    global $sort, $dir;
    return pageUrl(['sort' => $col, 'dir' => ($sort === $col && $dir === 'asc') ? 'desc' : 'asc', 'page' => 1]);
}

function sortIcon(string $col): string {
    global $sort, $dir;
    if ($sort !== $col) return '<i class="fa-solid fa-sort si-inactive"></i>';
    return $dir === 'asc' ? '<i class="fa-solid fa-sort-up si-active"></i>' : '<i class="fa-solid fa-sort-down si-active"></i>';
}

function fmtMoney(float $n): string {
    return 'R ' . number_format($n, 0, '.', ' ');
}

function fmtDate(?string $d): string {
    return $d ? date('d M Y', strtotime($d)) : '—';
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)   return round($diff / 60) . 'm ago';
    if ($diff < 86400)  return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($dt));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Campaigns | Old Union Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* ══ Design tokens (consistent with rest of admin) ══ */
    :root {
        --navy:         #0b2545;
        --navy-mid:     #0f3b7a;
        --navy-light:   #1a56b0;
        --amber:        #f59e0b;
        --amber-dark:   #d97706;
        --emerald:      #059669;
        --emerald-bg:   #ecfdf5;
        --emerald-bdr:  #6ee7b7;
        --red:          #dc2626;
        --red-bg:       #fef2f2;
        --red-bdr:      #fecaca;
        --teal-bg:      #ccfbf1;
        --teal-text:    #134e4a;
        --surface:      #ffffff;
        --surface-2:    #f8f9fb;
        --border:       #e4e7ec;
        --text:         #101828;
        --text-muted:   #667085;
        --text-light:   #98a2b3;
        --sidebar-w:    260px;
        --header-h:     60px;
        --radius:       14px;
        --radius-sm:    8px;
        --shadow-sm:    0 1px 3px rgba(11,37,69,.08);
        --shadow-card:  0 4px 24px rgba(11,37,69,.07), 0 1px 4px rgba(11,37,69,.04);
        --transition:   .2s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body { font-family: 'DM Sans', sans-serif; background: var(--surface-2); color: var(--text); min-height: 100vh; display: flex; }

    /* ── Mobile header ──────────────────────── */
    .mob-header {
        display: none; position: fixed; top: 0; left: 0; right: 0;
        height: var(--header-h); background: var(--navy);
        align-items: center; justify-content: space-between;
        padding: 0 1.25rem; z-index: 200; box-shadow: 0 2px 12px rgba(0,0,0,.25);
    }
    .mob-brand { font-family: 'DM Serif Display', serif; font-size: 1.15rem; color: #fff; display: flex; align-items: center; gap: .6rem; }
    .mob-menu-btn { background: rgba(255,255,255,.1); border: none; border-radius: var(--radius-sm); color: #fff; width: 38px; height: 38px; display: flex; align-items: center; justify-content: center; cursor: pointer; font-size: 1.1rem; transition: background var(--transition); }
    .mob-menu-btn:hover { background: rgba(255,255,255,.2); }

    /* ── Sidebar ────────────────────────────── */
    .sidebar {
        width: var(--sidebar-w); background: var(--navy); min-height: 100vh;
        padding: 2rem 1.5rem; display: flex; flex-direction: column;
        position: sticky; top: 0; height: 100vh; overflow-y: auto;
        flex-shrink: 0; z-index: 100; box-shadow: 4px 0 20px rgba(0,0,0,.12);
        transition: transform var(--transition);
    }
    .sidebar::after { content: ''; position: absolute; bottom: -60px; right: -60px; width: 220px; height: 220px; border-radius: 50%; border: 50px solid rgba(245,158,11,.06); pointer-events: none; }

    .sidebar-brand { display: flex; align-items: center; gap: .75rem; margin-bottom: 2rem; padding-bottom: 1.5rem; border-bottom: 1px solid rgba(255,255,255,.08); }
    .brand-icon { width: 40px; height: 40px; background: var(--amber); border-radius: 10px; display: flex; align-items: center; justify-content: center; color: var(--navy); font-size: 1rem; flex-shrink: 0; }
    .brand-name { font-family: 'DM Serif Display', serif; font-size: 1.15rem; color: #fff; line-height: 1.1; }
    .brand-sub  { font-size: .7rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .1em; }

    /* Stat chips */
    .sidebar-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: 1.75rem; }
    .stat-chip { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-sm); padding: .6rem .75rem; text-align: center; cursor: pointer; text-decoration: none; transition: background var(--transition); display: block; }
    .stat-chip:hover { background: rgba(255,255,255,.1); }
    .stat-chip.active-filter { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.4); }
    .stat-chip.span-2 { grid-column: span 2; }
    .stat-num   { font-size: 1.4rem; font-weight: 700; color: var(--amber); line-height: 1; margin-bottom: .15rem; }
    .stat-chip.green  .stat-num { color: #4ade80; }
    .stat-chip.blue   .stat-num { color: #93c5fd; }
    .stat-chip.teal   .stat-num { color: #5eead4; }
    .stat-chip.red    .stat-num { color: #f87171; }
    .stat-chip.grey   .stat-num { color: rgba(255,255,255,.5); }
    .stat-label { font-size: .66rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .07em; }

    .sidebar-divider { height: 1px; background: rgba(255,255,255,.07); margin: .75rem 0; }

    /* Nav */
    .sidebar-nav { list-style: none; margin-bottom: 1.5rem; }
    .nav-item a { display: flex; align-items: center; gap: .75rem; padding: .7rem .9rem; border-radius: var(--radius-sm); color: rgba(255,255,255,.55); text-decoration: none; font-size: .88rem; font-weight: 500; transition: all var(--transition); }
    .nav-item a:hover { background: rgba(255,255,255,.07); color: rgba(255,255,255,.85); }
    .nav-item.active a { background: rgba(245,158,11,.15); color: var(--amber); font-weight: 600; }
    .nav-item a i { width: 18px; text-align: center; font-size: .9rem; }
    .nav-count { margin-left: auto; background: var(--amber); color: var(--navy); border-radius: 99px; font-size: .68rem; font-weight: 700; padding: .1rem .45rem; }

    .sidebar-footer { margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,.08); font-size: .78rem; color: rgba(255,255,255,.3); text-align: center; }
    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 150; }

    /* ── Main ───────────────────────────────── */
    .main { flex: 1; padding: 2rem 2.5rem; overflow-x: hidden; min-width: 0; }

    .page-header { display: flex; align-items: flex-start; justify-content: space-between; flex-wrap: wrap; gap: 1rem; margin-bottom: 1.75rem; }
    .page-title { font-family: 'DM Serif Display', serif; font-size: 1.8rem; color: var(--navy); line-height: 1.1; }
    .page-subtitle { font-size: .9rem; color: var(--text-muted); margin-top: .3rem; }

    /* Toast */
    .toast { display: flex; align-items: center; gap: .75rem; padding: .9rem 1.4rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-size: .88rem; font-weight: 500; border: 1px solid transparent; animation: toastIn .3s ease; }
    @keyframes toastIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
    .toast.success { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald-bdr); }
    .toast i { font-size: 1rem; }

    /* Filter bar */
    .filter-bar { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 1.25rem 1.5rem; margin-bottom: 1.5rem; box-shadow: var(--shadow-sm); }
    .filter-row { display: flex; flex-wrap: wrap; gap: .75rem; align-items: flex-end; }
    .filter-group { display: flex; flex-direction: column; gap: .3rem; }
    .filter-group label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); }

    .filter-input, .filter-select {
        padding: .6rem .9rem; border: 1.5px solid var(--border); border-radius: var(--radius-sm);
        font-family: 'DM Sans', sans-serif; font-size: .88rem; color: var(--text);
        background: var(--surface-2); outline: none; transition: border-color var(--transition), box-shadow var(--transition);
    }
    .filter-input:focus, .filter-select:focus { border-color: var(--navy-light); background: #fff; box-shadow: 0 0 0 3px rgba(26,86,176,.09); }
    .filter-input { width: 220px; }
    .filter-select { min-width: 160px; appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right .75rem center; padding-right: 2rem; cursor: pointer; }

    .filter-search-wrap { position: relative; }
    .filter-search-wrap .search-icon { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: .85rem; pointer-events: none; }
    .filter-search-wrap .filter-input { padding-left: 2.1rem; }

    .btn-filter { display: inline-flex; align-items: center; gap: .4rem; padding: .62rem 1.1rem; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: var(--surface-2); color: var(--text-muted); font-family: 'DM Sans', sans-serif; font-size: .85rem; font-weight: 500; cursor: pointer; transition: all var(--transition); text-decoration: none; }
    .btn-filter:hover { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }
    .btn-filter.primary { background: var(--navy-mid); color: #fff; border-color: var(--navy-mid); box-shadow: 0 2px 8px rgba(15,59,122,.2); }
    .btn-filter.primary:hover { background: var(--navy); }

    .active-tag { display: inline-flex; align-items: center; gap: .35rem; font-size: .75rem; font-weight: 500; padding: .25rem .65rem; background: #eff4ff; color: var(--navy-light); border: 1px solid #c7d9f8; border-radius: 99px; }
    .active-tag a { color: var(--navy-light); text-decoration: none; margin-left: .15rem; }
    .active-tag a:hover { color: var(--red); }

    /* Results bar */
    .results-bar { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
    .results-count { font-size: .88rem; color: var(--text-muted); }
    .results-count strong { color: var(--text); font-weight: 600; }

    /* Table */
    .table-wrap { background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-card); overflow: hidden; }
    table { width: 100%; border-collapse: collapse; }
    thead { background: var(--surface-2); border-bottom: 1px solid var(--border); }
    th { padding: .85rem 1rem; font-size: .72rem; font-weight: 700; text-transform: uppercase; letter-spacing: .07em; color: var(--text-muted); text-align: left; white-space: nowrap; user-select: none; }
    th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
    th a:hover { color: var(--navy); }
    .si-inactive { font-size: .7rem; color: var(--text-light); opacity: .5; }
    .si-active   { font-size: .7rem; color: var(--navy-light); }
    td { padding: .9rem 1rem; font-size: .87rem; color: var(--text); vertical-align: middle; border-bottom: 1px solid var(--border); }
    tr:last-child td { border-bottom: none; }
    tbody tr { transition: background var(--transition); }
    tbody tr:hover { background: #fafbff; }

    /* Campaign cell */
    .camp-cell { display: flex; align-items: center; gap: .85rem; }
    .camp-logo { width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0; object-fit: cover; border: 1.5px solid var(--border); }
    .camp-logo-ph { width: 38px; height: 38px; border-radius: 8px; flex-shrink: 0; background: var(--navy-mid); display: flex; align-items: center; justify-content: center; color: rgba(255,255,255,.6); font-size: .85rem; }
    .camp-title { font-weight: 600; color: var(--navy); line-height: 1.2; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .camp-title a { color: inherit; text-decoration: none; }
    .camp-title a:hover { text-decoration: underline; }
    .camp-company { font-size: .76rem; color: var(--text-muted); margin-top: .15rem; max-width: 240px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .camp-company a { color: var(--navy-light); text-decoration: none; }
    .camp-company a:hover { text-decoration: underline; }

    /* Badges / pills */
    .badge { display: inline-flex; align-items: center; padding: .2rem .65rem; border-radius: 99px; font-size: .72rem; font-weight: 600; white-space: nowrap; }
    .badge-green  { background: #dcfce7; color: #14532d; }
    .badge-amber  { background: #fef3c7; color: #92400e; }
    .badge-grey   { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }
    .badge-red    { background: var(--red-bg); color: var(--red); }
    .badge-blue   { background: #dbeafe; color: #1e40af; }
    .badge-teal   { background: var(--teal-bg); color: var(--teal-text); }

    .pill { display: inline-flex; align-items: center; padding: .18rem .6rem; border-radius: 6px; font-size: .7rem; font-weight: 600; white-space: nowrap; }
    .pill-amber  { background: #fef3c7; color: #92400e; }
    .pill-blue   { background: #dbeafe; color: #1e40af; }
    .pill-teal   { background: var(--teal-bg); color: var(--teal-text); }
    .pill-green  { background: #dcfce7; color: #14532d; }
    .pill-purple { background: #f3e8ff; color: #6b21a8; }
    .pill-grey   { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }

    /* Progress bar */
    .progress-wrap { min-width: 140px; }
    .progress-bar-bg { height: 6px; background: var(--border); border-radius: 99px; overflow: hidden; margin-top: .3rem; }
    .progress-bar-fill { height: 100%; border-radius: 99px; background: var(--navy-light); transition: width .3s; }
    .progress-bar-fill.full { background: var(--emerald); }
    .progress-nums { display: flex; justify-content: space-between; font-size: .72rem; color: var(--text-muted); margin-top: .2rem; }
    .progress-nums .raised { font-weight: 600; color: var(--navy); }

    /* Mini stat */
    .mini-stat { text-align: center; }
    .mini-stat .mval { font-size: .95rem; font-weight: 700; color: var(--navy); }
    .mini-stat .mval.live { color: var(--emerald); }
    .mini-stat .mval.zero { color: var(--text-light); }
    .mini-stat .mlbl { font-size: .65rem; color: var(--text-light); text-transform: uppercase; letter-spacing: .05em; }

    /* Date range */
    .date-range { font-size: .8rem; line-height: 1.5; }
    .date-range .dr-label { font-size: .66rem; text-transform: uppercase; letter-spacing: .06em; color: var(--text-light); }
    .date-range .dr-open  { color: var(--emerald); font-weight: 500; }
    .date-range .dr-close { color: var(--text-muted); }
    .date-range .dr-days  { display: inline-flex; align-items: center; gap: .25rem; font-size: .72rem; margin-top: .15rem; font-weight: 500; }
    .date-range .dr-days.closing-soon { color: #d97706; }
    .date-range .dr-days.ended { color: var(--text-light); }

    /* Actions */
    .actions-cell { display: flex; align-items: center; gap: .4rem; }
    .btn-action { display: inline-flex; align-items: center; gap: .3rem; padding: .35rem .75rem; border-radius: 99px; border: 1.5px solid var(--border); background: var(--surface-2); color: var(--text-muted); font-family: 'DM Sans', sans-serif; font-size: .75rem; font-weight: 600; cursor: pointer; text-decoration: none; transition: all var(--transition); white-space: nowrap; }
    .btn-action:hover { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }

    /* Dropdown */
    .action-menu-wrap { position: relative; }
    .action-menu-btn { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 8px; border: 1.5px solid var(--border); background: var(--surface-2); color: var(--text-muted); cursor: pointer; font-size: .8rem; transition: all var(--transition); }
    .action-menu-btn:hover { background: var(--surface); border-color: var(--navy-light); color: var(--navy); }
    .action-dropdown { display: none; position: absolute; right: 0; top: calc(100% + 4px); background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: 0 8px 24px rgba(11,37,69,.12); min-width: 190px; z-index: 50; overflow: hidden; }
    .action-dropdown.open { display: block; }
    .dd-item { display: flex; align-items: center; gap: .6rem; padding: .65rem 1rem; font-size: .83rem; font-weight: 500; color: var(--text); cursor: pointer; border: none; background: transparent; width: 100%; font-family: 'DM Sans', sans-serif; transition: background var(--transition); text-align: left; text-decoration: none; }
    .dd-item:hover { background: var(--surface-2); }
    .dd-item i { width: 16px; text-align: center; color: var(--text-muted); font-size: .82rem; }
    .dd-item.green:hover { background: var(--emerald-bg); color: var(--emerald); }
    .dd-item.green:hover i { color: var(--emerald); }
    .dd-item.red:hover { background: var(--red-bg); color: var(--red); }
    .dd-item.red:hover i { color: var(--red); }
    .dd-item.amber:hover { background: #fef3c7; color: #92400e; }
    .dd-item.amber:hover i { color: #92400e; }
    .dd-item.blue:hover { background: #dbeafe; color: #1e40af; }
    .dd-item.blue:hover i { color: #1e40af; }
    .dd-separator { height: 1px; background: var(--border); margin: .25rem 0; }

    /* Mobile cards */
    .mobile-cards { display: none; flex-direction: column; gap: 1rem; }
    .mob-card { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); overflow: hidden; }
    .mob-card-head { display: flex; align-items: center; gap: .85rem; padding: 1rem 1.1rem; border-bottom: 1px solid var(--border); }
    .mob-card-info { flex: 1; min-width: 0; }
    .mob-card-name { font-weight: 600; font-size: .95rem; color: var(--navy); margin-bottom: .25rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mob-card-name a { color: inherit; text-decoration: none; }
    .mob-card-co { font-size: .76rem; color: var(--text-muted); margin-bottom: .35rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .mob-card-badges { display: flex; flex-wrap: wrap; gap: .3rem; }
    .mob-card-body { padding: .85rem 1.1rem; display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; }
    .mob-field { display: flex; flex-direction: column; gap: .15rem; }
    .mob-field-label { font-size: .66rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); }
    .mob-field-value { font-size: .83rem; font-weight: 500; color: var(--text); }
    .mob-card-foot { padding: .75rem 1.1rem; background: var(--surface-2); border-top: 1px solid var(--border); display: flex; gap: .5rem; flex-wrap: wrap; }

    /* Empty state */
    .empty-state { text-align: center; padding: 5rem 2rem; background: var(--surface); border-radius: var(--radius); border: 1px solid var(--border); box-shadow: var(--shadow-card); }
    .empty-icon { font-size: 2.75rem; color: var(--text-light); margin-bottom: 1rem; }
    .empty-title { font-size: 1.3rem; font-weight: 600; color: var(--navy); margin-bottom: .4rem; }
    .empty-sub { color: var(--text-muted); font-size: .9rem; }

    /* Pagination */
    .pagination { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem; }
    .pag-info { font-size: .85rem; color: var(--text-muted); }
    .pag-info strong { color: var(--text); font-weight: 600; }
    .pag-pages { display: flex; gap: .3rem; flex-wrap: wrap; }
    .pag-btn { display: inline-flex; align-items: center; justify-content: center; min-width: 34px; height: 34px; padding: 0 .6rem; border-radius: var(--radius-sm); border: 1.5px solid var(--border); background: var(--surface); color: var(--text-muted); font-family: 'DM Sans', sans-serif; font-size: .83rem; font-weight: 500; text-decoration: none; transition: all var(--transition); }
    .pag-btn:hover { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }
    .pag-btn.active { background: var(--navy-mid); color: #fff; border-color: var(--navy-mid); font-weight: 600; }
    .pag-btn.disabled { opacity: .4; pointer-events: none; }
    .pag-ellipsis { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; color: var(--text-light); font-size: .83rem; }

    /* ── Responsive ─────────────────────────── */
    @media (max-width: 900px) {
        body { flex-direction: column; }
        .mob-header { display: flex; }
        .sidebar { position: fixed; top: 0; left: 0; bottom: 0; transform: translateX(-100%); z-index: 160; height: 100%; }
        .sidebar.open { transform: translateX(0); }
        .main { margin-top: var(--header-h); padding: 1.5rem 1.25rem; }
        .table-wrap { display: none; }
        .mobile-cards { display: flex; }
        .page-title { font-size: 1.4rem; }
        .filter-input { width: 100%; }
        .filter-row { flex-direction: column; }
        .filter-group { width: 100%; }
        .filter-select { width: 100%; }
    }
    @media (max-width: 540px) {
        .main { padding: 1rem .85rem; }
        .pagination { flex-direction: column; align-items: stretch; text-align: center; }
        .pag-pages { justify-content: center; }
    }

    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
    </style>
</head>
<body>

<!-- Mobile header -->
<header class="mob-header">
    <div class="mob-brand">
        <i class="fa-solid fa-rocket" style="color:var(--amber);"></i>
        Campaigns
    </div>
    <button class="mob-menu-btn" id="menuBtn"><i class="fa-solid fa-bars"></i></button>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- Sidebar -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div>
            <div class="brand-name">Old Union</div>
            <div class="brand-sub">Admin Panel</div>
        </div>
    </div>

    <!-- Stats as filter shortcuts -->
    <div class="sidebar-stats">
        <a href="campaigns.php" class="stat-chip span-2 <?php echo $fStatus === '' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['total']; ?></div>
            <div class="stat-label">All Campaigns</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'open','page'=>1]); ?>"
           class="stat-chip green <?php echo $fStatus==='open' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['open']; ?></div>
            <div class="stat-label">Live</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'under_review','page'=>1]); ?>"
           class="stat-chip <?php echo $fStatus==='under_review' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['review']; ?></div>
            <div class="stat-label">Review</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'approved','page'=>1]); ?>"
           class="stat-chip blue <?php echo $fStatus==='approved' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['approved']; ?></div>
            <div class="stat-label">Approved</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'draft','page'=>1]); ?>"
           class="stat-chip grey <?php echo $fStatus==='draft' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['draft']; ?></div>
            <div class="stat-label">Draft</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'closed_successful','page'=>1]); ?>"
           class="stat-chip teal <?php echo in_array($fStatus,['funded','closed_successful']) ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['funded']; ?></div>
            <div class="stat-label">Funded/Closed</div>
        </a>
        <a href="<?php echo pageUrl(['status'=>'suspended','page'=>1]); ?>"
           class="stat-chip red <?php echo in_array($fStatus,['suspended','cancelled','closed_unsuccessful']) ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['problem']; ?></div>
            <div class="stat-label">Suspended/Cancelled</div>
        </a>
    </div>

    <div class="sidebar-divider"></div>

    <ul class="sidebar-nav">
        <li class="nav-item">
            <a href="company_review.php">
                <i class="fa-solid fa-inbox"></i> Pending Queue
                <?php if ($statsRow ?? false): ?>
                    <?php $pq = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status='pending_verification'")->fetchColumn(); ?>
                    <?php if ($pq > 0): ?><span class="nav-count"><?php echo $pq; ?></span><?php endif; ?>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a href="companies.php"><i class="fa-solid fa-building"></i> All Companies</a>
        </li>
        <li class="nav-item active">
            <a href="campaigns.php"><i class="fa-solid fa-rocket"></i> Campaigns</a>
        </li>
        <li class="nav-item">
            <a href="/admin/users.php"><i class="fa-solid fa-users"></i> Users</a>
        </li>
        <li><div class="sidebar-divider"></div></li>
        <li class="nav-item">
            <a href="/admin/"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        </li>
        <li class="nav-item">
            <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
        </li>
    </ul>

    <div class="sidebar-footer">Old Union Admin &copy; <?php echo date('Y'); ?></div>
</aside>

<!-- Main -->
<main class="main">

    <div class="page-header">
        <div>
            <div class="page-title">Campaigns</div>
            <div class="page-subtitle">Manage all funding campaigns across the platform</div>
        </div>
    </div>

    <?php if ($flashMsg): ?>
        <div class="toast success">
            <i class="fa-solid fa-circle-check"></i>
            <?php echo htmlspecialchars($flashMsg); ?>
        </div>
    <?php endif; ?>

    <!-- Filter bar -->
    <div class="filter-bar">
        <form method="GET" action="campaigns.php" id="filterForm">
            <div class="filter-row">

                <div class="filter-group" style="flex:1;">
                    <label for="q">Search</label>
                    <div class="filter-search-wrap">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="q" name="q" class="filter-input"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Campaign title or company name…" autocomplete="off">
                    </div>
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <?php foreach ([
                            'draft'               => 'Draft',
                            'under_review'        => 'Under Review',
                            'approved'            => 'Approved',
                            'open'                => 'Live / Open',
                            'funded'              => 'Funded',
                            'closed_successful'   => 'Closed (Success)',
                            'closed_unsuccessful' => 'Closed (Unsuccessful)',
                            'suspended'           => 'Suspended',
                            'cancelled'           => 'Cancelled',
                        ] as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>" <?php echo $fStatus === $val ? 'selected' : ''; ?>>
                                <?php echo $lbl; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="type">Campaign Type</label>
                    <select id="type" name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach ([
                            'revenue_share'          => 'Revenue Share',
                            'fixed_return_loan'      => 'Fixed Return Loan',
                            'cooperative_membership' => 'Co-op Membership',
                            'donation'               => 'Donation',
                            'convertible_note'       => 'Convertible Note',
                        ] as $val => $lbl): ?>
                            <option value="<?php echo $val; ?>" <?php echo $fType === $val ? 'selected' : ''; ?>>
                                <?php echo $lbl; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group" style="justify-content:flex-end;">
                    <label style="visibility:hidden;">.</label>
                    <div style="display:flex;gap:.5rem;">
                        <button type="submit" class="btn-filter primary">
                            <i class="fa-solid fa-magnifying-glass"></i> Search
                        </button>
                        <?php if ($search || $fStatus || $fType || $fCompany): ?>
                            <a href="campaigns.php" class="btn-filter">
                                <i class="fa-solid fa-xmark"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="dir"  value="<?php echo htmlspecialchars($dir); ?>">
            </div>

            <?php if ($search || $fStatus || $fType): ?>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.85rem;padding-top:.85rem;border-top:1px solid var(--border);">
                    <?php if ($search): ?>
                        <span class="active-tag"><i class="fa-solid fa-magnifying-glass"></i> "<?php echo htmlspecialchars($search); ?>"<a href="<?php echo pageUrl(['q'=>'','page'=>1]); ?>">×</a></span>
                    <?php endif; ?>
                    <?php if ($fStatus): ?>
                        <span class="active-tag">Status: <?php echo ucfirst(str_replace('_',' ',$fStatus)); ?><a href="<?php echo pageUrl(['status'=>'','page'=>1]); ?>">×</a></span>
                    <?php endif; ?>
                    <?php if ($fType): ?>
                        <span class="active-tag">Type: <?php echo ucfirst(str_replace('_',' ',$fType)); ?><a href="<?php echo pageUrl(['type'=>'','page'=>1]); ?>">×</a></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- Results bar -->
    <div class="results-bar">
        <div class="results-count">
            Showing <strong><?php echo number_format(min($offset+1,$totalRows)); ?>–<?php echo number_format(min($offset+$perPage,$totalRows)); ?></strong>
            of <strong><?php echo number_format($totalRows); ?></strong>
            <?php echo $totalRows === 1 ? 'campaign' : 'campaigns'; ?>
        </div>
        <div style="font-size:.82rem;color:var(--text-muted);">
            Sorted by <strong style="color:var(--text);"><?php echo ucfirst(str_replace('_',' ',$sort)); ?></strong>
            <?php echo $dir === 'asc' ? '↑' : '↓'; ?>
        </div>
    </div>

    <?php if (empty($campaigns)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-rocket"></i></div>
            <div class="empty-title">No campaigns found</div>
            <div class="empty-sub">Try adjusting your search or filters.</div>
        </div>
    <?php else: ?>

    <!-- Desktop table -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="min-width:260px;">
                        <a href="<?php echo sortUrl('title'); ?>">Campaign <?php echo sortIcon('title'); ?></a>
                    </th>
                    <th>Type</th>
                    <th>
                        <a href="<?php echo sortUrl('status'); ?>">Status <?php echo sortIcon('status'); ?></a>
                    </th>
                    <th style="min-width:160px;">
                        <a href="<?php echo sortUrl('total_raised'); ?>">Raise Progress <?php echo sortIcon('total_raised'); ?></a>
                    </th>
                    <th style="text-align:center;">Contributors</th>
                    <th>
                        <a href="<?php echo sortUrl('opens_at'); ?>">Timeline <?php echo sortIcon('opens_at'); ?></a>
                    </th>
                    <th>
                        <a href="<?php echo sortUrl('created_at'); ?>">Added <?php echo sortIcon('created_at'); ?></a>
                    </th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($campaigns as $camp):
                $campId  = $camp['id'];
                $pct     = progressPct((float)$camp['total_raised'], (float)$camp['raise_target']);
                [$sLabel, $sCls] = campaignStatusBadge($camp['status']);
                [$tLabel, $tCls] = campaignTypeLabel($camp['campaign_type']);

                // Days until close / status
                $daysLeft = null;
                $daysClass = 'ended';
                if ($camp['closes_at'] && in_array($camp['status'], ['open','approved','funded'])) {
                    $daysLeft = (int) ceil((strtotime($camp['closes_at']) - time()) / 86400);
                    $daysClass = $daysLeft <= 7 ? 'closing-soon' : '';
                }
            ?>
                <tr>
                    <!-- Campaign -->
                    <td>
                        <div class="camp-cell">
                            <?php if ($camp['company_logo']): ?>
                                <img src="<?php echo htmlspecialchars($camp['company_logo']); ?>" alt="" class="camp-logo">
                            <?php else: ?>
                                <div class="camp-logo-ph"><i class="fa-solid fa-building"></i></div>
                            <?php endif; ?>
                            <div>
                                <div class="camp-title">
                                    <a href="campaign_detail.php?id=<?php echo $campId; ?>">
                                        <?php echo htmlspecialchars($camp['title']); ?>
                                    </a>
                                </div>
                                <div class="camp-company">
                                    <a href="company_review.php?id=<?php echo $camp['company_id']; ?>">
                                        <?php echo htmlspecialchars($camp['company_name']); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- Type -->
                    <td><span class="pill <?php echo $tCls; ?>"><?php echo $tLabel; ?></span></td>

                    <!-- Status -->
                    <td><span class="badge <?php echo $sCls; ?>"><?php echo $sLabel; ?></span></td>

                    <!-- Progress -->
                    <td>
                        <div class="progress-wrap">
                            <div style="font-size:.82rem;font-weight:600;color:var(--navy);">
                                <?php echo fmtMoney((float)$camp['total_raised']); ?>
                                <span style="font-weight:400;color:var(--text-muted);font-size:.76rem;">
                                    of <?php echo fmtMoney((float)$camp['raise_target']); ?>
                                </span>
                            </div>
                            <div class="progress-bar-bg">
                                <div class="progress-bar-fill <?php echo $pct >= 100 ? 'full' : ''; ?>"
                                     style="width:<?php echo $pct; ?>%"></div>
                            </div>
                            <div class="progress-nums">
                                <span><?php echo $pct; ?>%</span>
                                <?php if ($camp['raise_minimum'] < $camp['raise_target']): ?>
                                    <span>min: <?php echo fmtMoney((float)$camp['raise_minimum']); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>

                    <!-- Contributors -->
                    <td>
                        <div class="mini-stat">
                            <div class="mval <?php echo $camp['contributor_count'] === 0 ? 'zero' : ''; ?>">
                                <?php echo $camp['contributor_count'] ?: '—'; ?>
                            </div>
                            <?php if ($camp['max_contributors']): ?>
                                <div class="mlbl">/ <?php echo $camp['max_contributors']; ?> max</div>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Timeline -->
                    <td>
                        <div class="date-range">
                            <?php if ($camp['opens_at']): ?>
                                <div><span class="dr-label">Opens</span> <span class="dr-open"><?php echo fmtDate($camp['opens_at']); ?></span></div>
                                <div><span class="dr-label">Closes</span> <span class="dr-close"><?php echo fmtDate($camp['closes_at']); ?></span></div>
                                <?php if ($daysLeft !== null): ?>
                                    <div class="dr-days <?php echo $daysClass; ?>">
                                        <i class="fa-solid fa-clock"></i>
                                        <?php echo $daysLeft > 0 ? "{$daysLeft}d left" : 'Closing today'; ?>
                                    </div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color:var(--text-light);font-style:italic;font-size:.8rem;">Not scheduled</span>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Added -->
                    <td>
                        <span style="font-size:.82rem;color:var(--text-muted);" title="<?php echo $camp['created_at']; ?>">
                            <?php echo timeAgo($camp['created_at']); ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div class="actions-cell">
                            <a href="campaign_detail.php?id=<?php echo $campId; ?>"
                               class="btn-action" title="Edit campaign">
                                <i class="fa-solid fa-pen-to-square"></i> Edit
                            </a>

                            <div class="action-menu-wrap">
                                <button class="action-menu-btn" onclick="toggleMenu(<?php echo $campId; ?>, event)">
                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                </button>
                                <div class="action-dropdown" id="menu-<?php echo $campId; ?>">
                                    <?php
                                    // Helper to emit a status-transition form item
                                    function ddAction(int $campId, string $action, string $icon, string $label, string $cls, string $confirm, array $filterState): void {
                                        echo '<form method="POST">';
                                        foreach (['_q','_status','_type','_page'] as $k) {
                                            $v = htmlspecialchars($filterState[$k] ?? '');
                                            echo "<input type='hidden' name='{$k}' value='{$v}'>";
                                        }
                                        echo "<input type='hidden' name='campaign_id' value='{$campId}'>";
                                        echo "<input type='hidden' name='action' value='{$action}'>";
                                        $oc = $confirm ? "return confirm(" . json_encode($confirm) . ")" : '';
                                        echo "<button type='submit' class='dd-item {$cls}' onclick=\"{$oc}\">";
                                        echo "<i class='fa-solid {$icon}'></i> {$label}";
                                        echo "</button></form>";
                                    }

                                    $fs = ['_q'=>$search,'_status'=>$fStatus,'_type'=>$fType,'_page'=>$page];
                                    $cSt = $camp['status'];

                                    if ($cSt === 'draft'):
                                        ddAction($campId,'submit_review','fa-paper-plane','Submit for Review','blue','Submit this campaign for admin review?', $fs);
                                    endif;
                                    if ($cSt === 'under_review'):
                                        ddAction($campId,'approve','fa-circle-check','Approve Campaign','green','Approve this campaign? It will move to Approved status.',$fs);
                                        ddAction($campId,'return_draft','fa-rotate-left','Return to Draft','amber','Return this campaign to draft?',$fs);
                                    endif;
                                    if ($cSt === 'approved'):
                                        ddAction($campId,'open','fa-play','Open Campaign','green','Open this campaign for contributions now?',$fs);
                                        ddAction($campId,'cancel','fa-ban','Cancel','red','Cancel this approved campaign?',$fs);
                                    endif;
                                    if (in_array($cSt,['open','funded'])):
                                        ddAction($campId,'suspend','fa-circle-pause','Suspend','red','Suspend this live campaign? Contributors will be notified.',$fs);
                                        ddAction($campId,'close_success','fa-circle-check','Close — Successful','green','Mark this campaign as successfully closed?',$fs);
                                    endif;
                                    if ($cSt === 'open'):
                                        ddAction($campId,'close_fail','fa-circle-xmark','Close — Unsuccessful','red','Mark this campaign as closed unsuccessful? This triggers refunds.',$fs);
                                    endif;
                                    if ($cSt === 'suspended'):
                                        ddAction($campId,'reinstate','fa-play','Reinstate Campaign','green','Reinstate this campaign?',$fs);
                                        ddAction($campId,'cancel','fa-ban','Cancel','red','Cancel this campaign permanently?',$fs);
                                    endif;
                                    ?>
                                    <div class="dd-separator"></div>
                                    <a href="campaign_detail.php?id=<?php echo $campId; ?>" class="dd-item">
                                        <i class="fa-solid fa-pen-to-square"></i> Edit Campaign
                                    </a>
                                    <a href="company_review.php?id=<?php echo $camp['company_id']; ?>" class="dd-item">
                                        <i class="fa-solid fa-building"></i> View Company
                                    </a>
                                    <a href="/company/dashboard.php?uuid=<?php echo urlencode($camp['company_uuid']); ?>" target="_blank" class="dd-item">
                                        <i class="fa-solid fa-arrow-up-right-from-square"></i> Public Profile
                                    </a>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Mobile cards -->
    <div class="mobile-cards">
    <?php foreach ($campaigns as $camp):
        $campId = $camp['id'];
        $pct    = progressPct((float)$camp['total_raised'], (float)$camp['raise_target']);
        [$sLabel, $sCls] = campaignStatusBadge($camp['status']);
        [$tLabel, $tCls] = campaignTypeLabel($camp['campaign_type']);
    ?>
        <div class="mob-card">
            <div class="mob-card-head">
                <?php if ($camp['company_logo']): ?>
                    <img src="<?php echo htmlspecialchars($camp['company_logo']); ?>" alt="" class="camp-logo">
                <?php else: ?>
                    <div class="camp-logo-ph"><i class="fa-solid fa-building"></i></div>
                <?php endif; ?>
                <div class="mob-card-info">
                    <div class="mob-card-name">
                        <a href="campaign_detail.php?id=<?php echo $camp['id']; ?>">
                            <?php echo htmlspecialchars($camp['title']); ?>
                        </a>
                    </div>
                    <div class="mob-card-co"><?php echo htmlspecialchars($camp['company_name']); ?></div>
                    <div class="mob-card-badges">
                        <span class="badge <?php echo $sCls; ?>"><?php echo $sLabel; ?></span>
                        <span class="pill <?php echo $tCls; ?>"><?php echo $tLabel; ?></span>
                    </div>
                </div>
            </div>
            <div class="mob-card-body">
                <div class="mob-field">
                    <span class="mob-field-label">Raised</span>
                    <span class="mob-field-value"><?php echo fmtMoney((float)$camp['total_raised']); ?> <small style="color:var(--text-muted);">(<?php echo $pct; ?>%)</small></span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Target</span>
                    <span class="mob-field-value"><?php echo fmtMoney((float)$camp['raise_target']); ?></span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Contributors</span>
                    <span class="mob-field-value"><?php echo $camp['contributor_count'] ?: '—'; ?><?php echo $camp['max_contributors'] ? ' / '.$camp['max_contributors'] : ''; ?></span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Closes</span>
                    <span class="mob-field-value"><?php echo fmtDate($camp['closes_at']); ?></span>
                </div>
            </div>
            <div class="mob-card-foot">
                <a href="campaign_detail.php?id=<?php echo $camp['id']; ?>" class="btn-action">
                    <i class="fa-solid fa-pen-to-square"></i> Edit
                </a>
                <a href="company_review.php?id=<?php echo $camp['company_id']; ?>" class="btn-action">
                    <i class="fa-solid fa-building"></i> Company
                </a>
                <?php if ($camp['status'] === 'under_review'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="campaign_id" value="<?php echo $campId; ?>">
                        <input type="hidden" name="action" value="approve">
                        <button type="submit" class="btn-action" style="border-color:var(--emerald-bdr);color:var(--emerald);background:var(--emerald-bg);"
                                onclick="return confirm('Approve this campaign?')">
                            <i class="fa-solid fa-circle-check"></i> Approve
                        </button>
                    </form>
                <?php endif; ?>
                <?php if ($camp['status'] === 'open'): ?>
                    <form method="POST" style="display:inline">
                        <input type="hidden" name="campaign_id" value="<?php echo $campId; ?>">
                        <input type="hidden" name="action" value="suspend">
                        <button type="submit" class="btn-action" style="border-color:var(--red-bdr);color:var(--red);background:var(--red-bg);"
                                onclick="return confirm('Suspend this campaign?')">
                            <i class="fa-solid fa-circle-pause"></i> Suspend
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <div class="pag-info">Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong></div>
        <div class="pag-pages">
            <?php if ($page > 1): ?>
                <a href="<?php echo pageUrl(['page'=>$page-1]); ?>" class="pag-btn"><i class="fa-solid fa-chevron-left"></i></a>
            <?php else: ?>
                <span class="pag-btn disabled"><i class="fa-solid fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php
            $shown = []; $prev = null;
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p === 1 || $p === $totalPages || abs($p - $page) <= 2) $shown[] = $p;
            }
            foreach ($shown as $p):
                if ($prev !== null && $p - $prev > 1): ?>
                    <span class="pag-ellipsis">…</span>
                <?php endif; ?>
                <a href="<?php echo pageUrl(['page'=>$p]); ?>" class="pag-btn <?php echo $p===$page?'active':''; ?>"><?php echo $p; ?></a>
            <?php $prev = $p; endforeach; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo pageUrl(['page'=>$page+1]); ?>" class="pag-btn"><i class="fa-solid fa-chevron-right"></i></a>
            <?php else: ?>
                <span class="pag-btn disabled"><i class="fa-solid fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty($campaigns) ?>

</main>

<script>
/* ── Mobile sidebar ────────────────────── */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const menuBtn = document.getElementById('menuBtn');
function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('open'); menuBtn.innerHTML='<i class="fa-solid fa-xmark"></i>'; }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); menuBtn.innerHTML='<i class="fa-solid fa-bars"></i>'; }
menuBtn.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
overlay.addEventListener('click', closeSidebar);

/* ── Dropdown menus ────────────────────── */
let openMenu = null;
function toggleMenu(id, e) {
    e.stopPropagation();
    const menu = document.getElementById('menu-' + id);
    if (!menu) return;
    if (openMenu && openMenu !== menu) openMenu.classList.remove('open');
    menu.classList.toggle('open');
    openMenu = menu.classList.contains('open') ? menu : null;
}
document.addEventListener('click', () => { if (openMenu) { openMenu.classList.remove('open'); openMenu = null; } });

/* ── Toast auto-dismiss ────────────────── */
const toast = document.querySelector('.toast');
if (toast) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => { toast.style.transition='opacity .4s'; toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); }, 5000);
}

/* ── Live search debounce ──────────────── */
const qInput = document.getElementById('q');
if (qInput) {
    let t;
    qInput.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => { if (qInput.value.length === 0 || qInput.value.length >= 2) document.getElementById('filterForm').submit(); }, 500);
    });
}
</script>
</body>
</html>
