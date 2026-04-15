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
   POST — quick status actions
═══════════════════════════════════════════ */
$actionMsg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['company_id'], $_POST['action'])) {
    $companyId = (int) $_POST['company_id'];
    $action    = $_POST['action'];

    $transitions = [
        'approve'  => ['status' => 'active',    'verified' => 1, 'msg' => 'Company approved and activated.'],
        'suspend'  => ['status' => 'suspended',  'verified' => 0, 'msg' => 'Company suspended.'],
        'activate' => ['status' => 'active',     'verified' => 1, 'msg' => 'Company set to active.'],
        'draft'    => ['status' => 'draft',      'verified' => 0, 'msg' => 'Company returned to draft.'],
    ];

    if (isset($transitions[$action])) {
        $t = $transitions[$action];
        $pdo->prepare("UPDATE companies SET status = ?, verified = ? WHERE id = ?")
            ->execute([$t['status'], $t['verified'], $companyId]);

        // Sync KYC verification_status when approving
        if ($action === 'approve') {
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'approved', verified_at = NOW() WHERE company_id = ?")
                ->execute([$companyId]);
        }

        logCompanyActivity($companyId, $_SESSION['user_id'], "Admin action: {$action}");
        $actionMsg = ['type' => 'success', 'text' => $t['msg']];
    }

    // Redirect to prevent re-POST on refresh, preserving current filter state
    $qs = http_build_query(array_filter([
        'q'        => $_POST['_q']        ?? '',
        'status'   => $_POST['_status']   ?? '',
        'type'     => $_POST['_type']     ?? '',
        'industry' => $_POST['_industry'] ?? '',
        'page'     => $_POST['_page']     ?? '',
    ]));
    $flash = urlencode($actionMsg['text']);
    header("Location: companies.php?{$qs}&flash=" . $flash);
    exit;
}

// Flash message from redirect
$flash = null;
if (!empty($_GET['flash'])) {
    $flash = ['type' => 'success', 'text' => urldecode($_GET['flash'])];
}

/* ═══════════════════════════════════════════
   FILTERS & PAGINATION
═══════════════════════════════════════════ */
$search   = trim($_GET['q']        ?? '');
$fStatus  = $_GET['status']        ?? '';
$fType    = $_GET['type']          ?? '';
$fIndustry= $_GET['industry']      ?? '';
$fStage   = $_GET['stage']         ?? '';
$sort     = in_array($_GET['sort'] ?? '', ['name','created_at','updated_at','status']) ? $_GET['sort'] : 'created_at';
$dir      = ($_GET['dir'] ?? 'desc') === 'asc' ? 'asc' : 'desc';
$perPage  = 20;
$page     = max(1, (int)($_GET['page'] ?? 1));
$offset   = ($page - 1) * $perPage;

// Build WHERE
$where  = ['1=1'];
$params = [];

if ($search !== '') {
    $where[]  = '(c.name LIKE :q OR c.email LIKE :q OR c.registration_number LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}
if ($fStatus !== '') {
    $where[]       = 'c.status = :status';
    $params[':status'] = $fStatus;
}
if ($fType !== '') {
    $where[]      = 'c.type = :type';
    $params[':type'] = $fType;
}
if ($fIndustry !== '') {
    $where[]         = 'c.industry = :industry';
    $params[':industry'] = $fIndustry;
}
if ($fStage !== '') {
    $where[]      = 'c.stage = :stage';
    $params[':stage'] = $fStage;
}

$whereClause = implode(' AND ', $where);
$orderClause = "ORDER BY c.{$sort} {$dir}";

// Total count
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM companies c WHERE {$whereClause}");
$countStmt->execute($params);
$totalRows  = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Main query — join owner + KYC + campaign count + pitch existence
$stmt = $pdo->prepare("
    SELECT
        c.id, c.uuid, c.name, c.type, c.stage, c.industry,
        c.registration_number, c.logo, c.email, c.phone, c.website,
        c.founded_year, c.employee_count,
        c.status, c.verified, c.created_at, c.updated_at,
        u.email AS owner_email,
        k.verification_status,
        (SELECT COUNT(*) FROM funding_campaigns fc WHERE fc.company_id = c.id) AS campaign_count,
        (SELECT COUNT(*) FROM funding_campaigns fc WHERE fc.company_id = c.id AND fc.status = 'open') AS live_campaigns,
        (SELECT SUM(fc.total_raised) FROM funding_campaigns fc WHERE fc.company_id = c.id) AS total_raised,
        (SELECT COUNT(*) FROM company_admins ca WHERE ca.company_id = c.id) AS admin_count
    FROM companies c
    LEFT JOIN company_admins ca_owner ON ca_owner.company_id = c.id AND ca_owner.role = 'owner'
    LEFT JOIN users u ON ca_owner.user_id = u.id
    LEFT JOIN company_kyc k ON k.company_id = c.id
    WHERE {$whereClause}
    {$orderClause}
    LIMIT :limit OFFSET :offset
");
$params[':limit']  = $perPage;
$params[':offset'] = $offset;
$stmt->execute($params);
$companies = $stmt->fetchAll();

/* ═══════════════════════════════════════════
   SIDEBAR STATS
═══════════════════════════════════════════ */
$stats = [];
$statsRaw = $pdo->query("
    SELECT status, COUNT(*) AS n FROM companies GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);

$stats['total']    = array_sum($statsRaw);
$stats['active']   = (int)($statsRaw['active']                ?? 0);
$stats['pending']  = (int)($statsRaw['pending_verification']  ?? 0);
$stats['draft']    = (int)($statsRaw['draft']                 ?? 0);
$stats['suspended']= (int)($statsRaw['suspended']             ?? 0);

// Distinct industries for filter dropdown
$industries = $pdo->query("
    SELECT DISTINCT industry FROM companies
    WHERE industry IS NOT NULL AND industry != ''
    ORDER BY industry ASC
")->fetchAll(PDO::FETCH_COLUMN);

/* ═══════════════════════════════════════════
   HELPERS
═══════════════════════════════════════════ */
function statusBadge(string $s, bool $verified = false): string {
    $map = [
        'active'               => ['Active',             'badge-green'],
        'pending_verification' => ['Pending Review',     'badge-amber'],
        'draft'                => ['Draft',              'badge-grey'],
        'suspended'            => ['Suspended',          'badge-red'],
    ];
    [$label, $cls] = $map[$s] ?? [ucfirst($s), 'badge-grey'];
    $veri = ($verified && $s === 'active')
          ? ' <span class="verified-pip" title="Verified"><i class="fa-solid fa-badge-check"></i></span>'
          : '';
    return '<span class="badge ' . $cls . '">' . $label . '</span>' . $veri;
}

function typePill(string $t): string {
    $map = [
        'startup'           => ['Startup',          'pill-amber'],
        'sme'               => ['SME',              'pill-blue'],
        'corporation'       => ['Corporation',      'pill-navy'],
        'ngo'               => ['NGO',              'pill-teal'],
        'cooperative'       => ['Co-op',            'pill-purple'],
        'social_enterprise' => ['Social Ent.',      'pill-green'],
        'other'             => ['Other',            'pill-grey'],
    ];
    [$label, $cls] = $map[$t] ?? [ucfirst($t), 'pill-grey'];
    return '<span class="pill ' . $cls . '">' . $label . '</span>';
}

function stageLabel(string $s): string {
    return [
        'idea'        => 'Pre-Seed',
        'seed'        => 'Seed',
        'series_a'    => 'Series A',
        'series_b'    => 'Series B+',
        'growth'      => 'Growth',
        'established' => 'Established',
    ][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function timeAgo(string $dt): string {
    $diff = time() - strtotime($dt);
    if ($diff < 3600)   return round($diff / 60) . 'm ago';
    if ($diff < 86400)  return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($dt));
}

// Build URL helper preserving all active filters
function pageUrl(array $overrides = []): string {
    global $search, $fStatus, $fType, $fIndustry, $fStage, $sort, $dir, $page;
    $base = [
        'q'        => $search,
        'status'   => $fStatus,
        'type'     => $fType,
        'industry' => $fIndustry,
        'stage'    => $fStage,
        'sort'     => $sort,
        'dir'      => $dir,
        'page'     => $page,
    ];
    $merged = array_merge($base, $overrides);
    return 'companies.php?' . http_build_query(array_filter($merged, fn($v) => $v !== '' && $v !== null));
}

function sortUrl(string $col): string {
    global $sort, $dir;
    $newDir = ($sort === $col && $dir === 'asc') ? 'desc' : 'asc';
    return pageUrl(['sort' => $col, 'dir' => $newDir, 'page' => 1]);
}

function sortIcon(string $col): string {
    global $sort, $dir;
    if ($sort !== $col) return '<i class="fa-solid fa-sort sort-icon inactive"></i>';
    return $dir === 'asc'
        ? '<i class="fa-solid fa-sort-up sort-icon active"></i>'
        : '<i class="fa-solid fa-sort-down sort-icon active"></i>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Companies | Old Union Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* ══════════════════════════════════════════
       DESIGN TOKENS  (identical to review page)
    ══════════════════════════════════════════ */
    :root {
        --navy:          #0b2545;
        --navy-mid:      #0f3b7a;
        --navy-light:    #1a56b0;
        --amber:         #f59e0b;
        --amber-dark:    #d97706;
        --emerald:       #059669;
        --emerald-bg:    #ecfdf5;
        --emerald-bdr:   #6ee7b7;
        --red:           #dc2626;
        --red-bg:        #fef2f2;
        --red-bdr:       #fecaca;
        --surface:       #ffffff;
        --surface-2:     #f8f9fb;
        --border:        #e4e7ec;
        --text:          #101828;
        --text-muted:    #667085;
        --text-light:    #98a2b3;
        --sidebar-w:     260px;
        --header-h:      60px;
        --radius:        14px;
        --radius-sm:     8px;
        --shadow-sm:     0 1px 3px rgba(11,37,69,.08);
        --shadow-card:   0 4px 24px rgba(11,37,69,.07), 0 1px 4px rgba(11,37,69,.04);
        --transition:    .2s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--surface-2);
        color: var(--text);
        min-height: 100vh;
        display: flex;
    }

    /* ══════════════════════════════════════════
       MOBILE HEADER
    ══════════════════════════════════════════ */
    .mob-header {
        display: none;
        position: fixed;
        top: 0; left: 0; right: 0;
        height: var(--header-h);
        background: var(--navy);
        align-items: center;
        justify-content: space-between;
        padding: 0 1.25rem;
        z-index: 200;
        box-shadow: 0 2px 12px rgba(0,0,0,.25);
    }

    .mob-brand {
        font-family: 'DM Serif Display', serif;
        font-size: 1.15rem;
        color: #fff;
        display: flex;
        align-items: center;
        gap: .6rem;
    }

    .mob-menu-btn {
        background: rgba(255,255,255,.1);
        border: none;
        border-radius: var(--radius-sm);
        color: #fff;
        width: 38px; height: 38px;
        display: flex; align-items: center; justify-content: center;
        cursor: pointer;
        font-size: 1.1rem;
        transition: background var(--transition);
    }
    .mob-menu-btn:hover { background: rgba(255,255,255,.2); }

    /* ══════════════════════════════════════════
       SIDEBAR  (shared with review page)
    ══════════════════════════════════════════ */
    .sidebar {
        width: var(--sidebar-w);
        background: var(--navy);
        min-height: 100vh;
        padding: 2rem 1.5rem;
        display: flex;
        flex-direction: column;
        position: sticky;
        top: 0;
        height: 100vh;
        overflow-y: auto;
        flex-shrink: 0;
        z-index: 100;
        box-shadow: 4px 0 20px rgba(0,0,0,.12);
        transition: transform var(--transition);
    }

    .sidebar::after {
        content: '';
        position: absolute;
        bottom: -60px; right: -60px;
        width: 220px; height: 220px;
        border-radius: 50%;
        border: 50px solid rgba(245,158,11,.06);
        pointer-events: none;
    }

    .sidebar-brand {
        display: flex; align-items: center; gap: .75rem;
        margin-bottom: 2rem; padding-bottom: 1.5rem;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }
    .brand-icon {
        width: 40px; height: 40px; background: var(--amber); border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: var(--navy); font-size: 1rem; flex-shrink: 0;
    }
    .brand-text { line-height: 1.1; }
    .brand-name { font-family: 'DM Serif Display', serif; font-size: 1.15rem; color: #fff; }
    .brand-sub  { font-size: .7rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .1em; }

    /* Sidebar stats */
    .sidebar-stats { display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: 1.75rem; }
    .stat-chip { background: rgba(255,255,255,.05); border: 1px solid rgba(255,255,255,.08); border-radius: var(--radius-sm); padding: .6rem .75rem; text-align: center; cursor: pointer; text-decoration: none; transition: background var(--transition); display: block; }
    .stat-chip:hover { background: rgba(255,255,255,.1); }
    .stat-chip.active-filter { background: rgba(245,158,11,.15); border-color: rgba(245,158,11,.4); }
    .stat-chip.span-2 { grid-column: span 2; }
    .stat-num { font-size: 1.4rem; font-weight: 700; color: var(--amber); line-height: 1; margin-bottom: .15rem; }
    .stat-chip.green .stat-num { color: #4ade80; }
    .stat-chip.red   .stat-num { color: #f87171; }
    .stat-chip.blue  .stat-num { color: #93c5fd; }
    .stat-chip.grey  .stat-num { color: rgba(255,255,255,.5); }
    .stat-label { font-size: .66rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .07em; }

    .sidebar-divider { height: 1px; background: rgba(255,255,255,.07); margin: .75rem 0; }

    /* Nav */
    .sidebar-nav { list-style: none; margin-bottom: 1.5rem; }
    .nav-item a {
        display: flex; align-items: center; gap: .75rem; padding: .7rem .9rem;
        border-radius: var(--radius-sm); color: rgba(255,255,255,.55);
        text-decoration: none; font-size: .88rem; font-weight: 500;
        transition: all var(--transition);
    }
    .nav-item a:hover { background: rgba(255,255,255,.07); color: rgba(255,255,255,.85); }
    .nav-item.active a { background: rgba(245,158,11,.15); color: var(--amber); font-weight: 600; }
    .nav-item a i { width: 18px; text-align: center; font-size: .9rem; }
    .nav-count { margin-left: auto; background: var(--amber); color: var(--navy); border-radius: 99px; font-size: .68rem; font-weight: 700; padding: .1rem .45rem; min-width: 20px; text-align: center; }

    .sidebar-footer { margin-top: auto; padding-top: 1rem; border-top: 1px solid rgba(255,255,255,.08); font-size: .78rem; color: rgba(255,255,255,.3); text-align: center; }

    .sidebar-overlay { display: none; position: fixed; inset: 0; background: rgba(0,0,0,.5); z-index: 150; }

    /* ══════════════════════════════════════════
       MAIN
    ══════════════════════════════════════════ */
    .main { flex: 1; padding: 2rem 2.5rem; overflow-x: hidden; min-width: 0; }

    /* Page header */
    .page-header {
        display: flex; align-items: flex-start;
        justify-content: space-between; flex-wrap: wrap;
        gap: 1rem; margin-bottom: 1.75rem;
    }
    .page-title { font-family: 'DM Serif Display', serif; font-size: 1.8rem; color: var(--navy); line-height: 1.1; }
    .page-subtitle { font-size: .9rem; color: var(--text-muted); margin-top: .3rem; }

    .header-actions { display: flex; gap: .75rem; flex-wrap: wrap; align-items: center; }

    /* ── Toast ──────────────────────────────── */
    .toast {
        display: flex; align-items: center; gap: .75rem;
        padding: .9rem 1.4rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem;
        font-size: .88rem; font-weight: 500; border: 1px solid transparent;
        animation: toastIn .3s ease;
    }
    @keyframes toastIn { from { opacity:0; transform:translateY(-6px); } to { opacity:1; transform:translateY(0); } }
    .toast.success { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald-bdr); }
    .toast.error   { background: var(--red-bg);     color: var(--red);     border-color: var(--red-bdr); }
    .toast i { font-size: 1rem; }

    /* ── Filter Bar ─────────────────────────── */
    .filter-bar {
        background: var(--surface);
        border: 1px solid var(--border);
        border-radius: var(--radius);
        padding: 1.25rem 1.5rem;
        margin-bottom: 1.5rem;
        box-shadow: var(--shadow-sm);
    }

    .filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: .75rem;
        align-items: flex-end;
    }

    .filter-group { display: flex; flex-direction: column; gap: .3rem; }
    .filter-group label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); }

    .filter-input, .filter-select {
        padding: .6rem .9rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: 'DM Sans', sans-serif;
        font-size: .88rem;
        color: var(--text);
        background: var(--surface-2);
        outline: none;
        transition: border-color var(--transition), box-shadow var(--transition);
    }
    .filter-input:focus, .filter-select:focus {
        border-color: var(--navy-light);
        background: #fff;
        box-shadow: 0 0 0 3px rgba(26,86,176,.09);
    }
    .filter-input  { width: 260px; }
    .filter-select { min-width: 150px; appearance: none;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-position: right .75rem center; padding-right: 2rem; cursor: pointer;
    }

    .filter-search-wrap { position: relative; }
    .filter-search-wrap .search-icon { position: absolute; left: .75rem; top: 50%; transform: translateY(-50%); color: var(--text-light); font-size: .85rem; pointer-events: none; }
    .filter-search-wrap .filter-input { padding-left: 2.1rem; }

    .btn-filter {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .62rem 1.1rem; border-radius: var(--radius-sm);
        border: 1.5px solid var(--border); background: var(--surface-2);
        color: var(--text-muted); font-family: 'DM Sans', sans-serif;
        font-size: .85rem; font-weight: 500; cursor: pointer;
        transition: all var(--transition);
    }
    .btn-filter:hover { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }
    .btn-filter.primary { background: var(--navy-mid); color: #fff; border-color: var(--navy-mid); box-shadow: 0 2px 8px rgba(15,59,122,.2); }
    .btn-filter.primary:hover { background: var(--navy); }

    .filter-active-tag {
        display: inline-flex; align-items: center; gap: .35rem;
        font-size: .75rem; font-weight: 500; padding: .25rem .65rem;
        background: #eff4ff; color: var(--navy-light);
        border: 1px solid #c7d9f8; border-radius: 99px;
    }
    .filter-active-tag a { color: var(--navy-light); text-decoration: none; margin-left: .15rem; }
    .filter-active-tag a:hover { color: var(--red); }

    /* ── Results bar ────────────────────────── */
    .results-bar {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem;
    }
    .results-count { font-size: .88rem; color: var(--text-muted); }
    .results-count strong { color: var(--text); font-weight: 600; }

    /* ── Table ──────────────────────────────── */
    .table-wrap {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }

    table {
        width: 100%;
        border-collapse: collapse;
    }

    thead {
        background: var(--surface-2);
        border-bottom: 1px solid var(--border);
    }

    th {
        padding: .85rem 1.1rem;
        font-size: .72rem; font-weight: 700;
        text-transform: uppercase; letter-spacing: .07em;
        color: var(--text-muted); text-align: left;
        white-space: nowrap; user-select: none;
    }

    th a { color: inherit; text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
    th a:hover { color: var(--navy); }
    .sort-icon { font-size: .7rem; }
    .sort-icon.inactive { color: var(--text-light); opacity: .5; }
    .sort-icon.active   { color: var(--navy-light); }

    td {
        padding: 1rem 1.1rem;
        font-size: .87rem;
        color: var(--text);
        vertical-align: middle;
        border-bottom: 1px solid var(--border);
    }

    tr:last-child td { border-bottom: none; }

    tr { transition: background var(--transition); }
    tbody tr:hover { background: #fafbff; }

    /* Company cell */
    .company-cell { display: flex; align-items: center; gap: .85rem; }
    .company-thumb {
        width: 40px; height: 40px; border-radius: 8px; flex-shrink: 0;
        object-fit: cover; border: 1.5px solid var(--border);
    }
    .company-thumb-placeholder {
        width: 40px; height: 40px; border-radius: 8px; flex-shrink: 0;
        background: var(--navy-mid); display: flex; align-items: center;
        justify-content: center; color: rgba(255,255,255,.6); font-size: .9rem;
        border: 1.5px solid transparent;
    }
    .company-name { font-weight: 600; color: var(--navy); line-height: 1.2; white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }
    .company-name a { color: inherit; text-decoration: none; }
    .company-name a:hover { text-decoration: underline; }
    .company-sub { font-size: .75rem; color: var(--text-muted); margin-top: .15rem; white-space: nowrap; max-width: 200px; overflow: hidden; text-overflow: ellipsis; }

    /* Badges / pills */
    .badge {
        display: inline-flex; align-items: center; padding: .2rem .65rem;
        border-radius: 99px; font-size: .72rem; font-weight: 600; white-space: nowrap;
    }
    .badge-green  { background: #dcfce7; color: #14532d; }
    .badge-amber  { background: #fef3c7; color: #92400e; }
    .badge-grey   { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }
    .badge-red    { background: var(--red-bg); color: var(--red); }
    .badge-blue   { background: #dbeafe; color: #1e40af; }

    .pill { display: inline-flex; align-items: center; padding: .18rem .55rem; border-radius: 6px; font-size: .7rem; font-weight: 600; white-space: nowrap; }
    .pill-amber  { background: #fef3c7; color: #92400e; }
    .pill-blue   { background: #dbeafe; color: #1e40af; }
    .pill-navy   { background: #e0e7ff; color: #3730a3; }
    .pill-teal   { background: #ccfbf1; color: #134e4a; }
    .pill-purple { background: #f3e8ff; color: #6b21a8; }
    .pill-green  { background: #dcfce7; color: #14532d; }
    .pill-grey   { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }

    .verified-pip { color: #059669; font-size: .75rem; margin-left: .25rem; }

    /* Stat mini-cells */
    .mini-stat { text-align: center; }
    .mini-stat .mval { font-size: .95rem; font-weight: 700; color: var(--navy); }
    .mini-stat .mlbl { font-size: .65rem; color: var(--text-light); text-transform: uppercase; letter-spacing: .05em; }
    .mini-stat .mval.live { color: var(--emerald); }
    .mini-stat .mval.zero { color: var(--text-light); }

    /* Actions cell */
    .actions-cell { display: flex; align-items: center; gap: .4rem; flex-wrap: nowrap; }

    .btn-action {
        display: inline-flex; align-items: center; gap: .3rem;
        padding: .35rem .75rem; border-radius: 99px;
        border: 1.5px solid var(--border); background: var(--surface-2);
        color: var(--text-muted); font-family: 'DM Sans', sans-serif;
        font-size: .75rem; font-weight: 600; cursor: pointer;
        text-decoration: none; transition: all var(--transition); white-space: nowrap;
    }
    .btn-action:hover            { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }
    .btn-action.green:hover      { border-color: var(--emerald); color: var(--emerald); background: var(--emerald-bg); }
    .btn-action.red:hover        { border-color: var(--red); color: var(--red); background: var(--red-bg); }
    .btn-action.amber:hover      { border-color: var(--amber-dark); color: var(--amber-dark); background: #fef3c7; }

    /* Action dropdown */
    .action-menu-wrap { position: relative; }
    .action-menu-btn {
        display: inline-flex; align-items: center; justify-content: center;
        width: 30px; height: 30px; border-radius: 8px;
        border: 1.5px solid var(--border); background: var(--surface-2);
        color: var(--text-muted); cursor: pointer; font-size: .8rem;
        transition: all var(--transition);
    }
    .action-menu-btn:hover { background: var(--surface); border-color: var(--navy-light); color: var(--navy); }

    .action-dropdown {
        display: none; position: absolute; right: 0; top: calc(100% + 4px);
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius-sm); box-shadow: 0 8px 24px rgba(11,37,69,.12);
        min-width: 170px; z-index: 50; overflow: hidden;
    }
    .action-dropdown.open { display: block; }

    .dd-item {
        display: flex; align-items: center; gap: .6rem;
        padding: .65rem 1rem; font-size: .83rem; font-weight: 500;
        color: var(--text); cursor: pointer; border: none;
        background: transparent; width: 100%; font-family: 'DM Sans', sans-serif;
        transition: background var(--transition); text-align: left;
        text-decoration: none;
    }
    .dd-item:hover           { background: var(--surface-2); }
    .dd-item i               { width: 16px; text-align: center; color: var(--text-muted); font-size: .82rem; }
    .dd-item.green:hover     { background: var(--emerald-bg); color: var(--emerald); }
    .dd-item.green:hover i   { color: var(--emerald); }
    .dd-item.red:hover       { background: var(--red-bg); color: var(--red); }
    .dd-item.red:hover i     { color: var(--red); }
    .dd-item.amber:hover     { background: #fef3c7; color: #92400e; }
    .dd-item.amber:hover i   { color: #92400e; }
    .dd-separator { height: 1px; background: var(--border); margin: .25rem 0; }

    /* ── Mobile cards ───────────────────────── */
    .mobile-cards { display: none; flex-direction: column; gap: 1rem; }

    .mob-card {
        background: var(--surface); border: 1px solid var(--border);
        border-radius: var(--radius); box-shadow: var(--shadow-sm);
        overflow: hidden;
    }

    .mob-card-head {
        display: flex; align-items: center; gap: .85rem;
        padding: 1rem 1.1rem; border-bottom: 1px solid var(--border);
    }

    .mob-card-info { flex: 1; min-width: 0; }
    .mob-card-name { font-weight: 600; font-size: .95rem; color: var(--navy); margin-bottom: .3rem; }
    .mob-card-name a { color: inherit; text-decoration: none; }
    .mob-card-badges { display: flex; flex-wrap: wrap; gap: .3rem; }

    .mob-card-body {
        padding: .85rem 1.1rem;
        display: grid; grid-template-columns: 1fr 1fr; gap: .6rem;
    }

    .mob-field { display: flex; flex-direction: column; gap: .15rem; }
    .mob-field-label { font-size: .66rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); }
    .mob-field-value { font-size: .83rem; font-weight: 500; color: var(--text); }
    .mob-field-value.none { color: var(--text-light); font-style: italic; }

    .mob-card-foot {
        padding: .75rem 1.1rem;
        background: var(--surface-2);
        border-top: 1px solid var(--border);
        display: flex; gap: .5rem; flex-wrap: wrap;
    }

    /* ── Empty state ────────────────────────── */
    .empty-state {
        text-align: center; padding: 5rem 2rem;
        background: var(--surface); border-radius: var(--radius);
        border: 1px solid var(--border); box-shadow: var(--shadow-card);
    }
    .empty-icon { font-size: 2.75rem; color: var(--text-light); margin-bottom: 1rem; }
    .empty-title { font-size: 1.3rem; font-weight: 600; color: var(--navy); margin-bottom: .4rem; }
    .empty-sub { color: var(--text-muted); font-size: .9rem; }

    /* ── Pagination ─────────────────────────── */
    .pagination {
        display: flex; align-items: center; justify-content: space-between;
        flex-wrap: wrap; gap: .75rem; margin-top: 1.5rem;
    }

    .pag-info { font-size: .85rem; color: var(--text-muted); }
    .pag-info strong { color: var(--text); }

    .pag-pages { display: flex; gap: .3rem; flex-wrap: wrap; }

    .pag-btn {
        display: inline-flex; align-items: center; justify-content: center;
        min-width: 34px; height: 34px; padding: 0 .6rem;
        border-radius: var(--radius-sm); border: 1.5px solid var(--border);
        background: var(--surface); color: var(--text-muted);
        font-family: 'DM Sans', sans-serif; font-size: .83rem; font-weight: 500;
        text-decoration: none; transition: all var(--transition);
    }
    .pag-btn:hover    { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }
    .pag-btn.active   { background: var(--navy-mid); color: #fff; border-color: var(--navy-mid); font-weight: 600; }
    .pag-btn.disabled { opacity: .4; pointer-events: none; }
    .pag-ellipsis { display: flex; align-items: center; justify-content: center; width: 34px; height: 34px; color: var(--text-light); font-size: .83rem; }

    /* ══════════════════════════════════════════
       RESPONSIVE
    ══════════════════════════════════════════ */
    @media (max-width: 900px) {
        body { flex-direction: column; }
        .mob-header { display: flex; }
        .sidebar {
            position: fixed; top: 0; left: 0; bottom: 0;
            transform: translateX(-100%); z-index: 160; height: 100%;
        }
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

<!-- ════════════════════════════════════════
     MOBILE HEADER
════════════════════════════════════════ -->
<header class="mob-header">
    <div class="mob-brand">
        <i class="fa-solid fa-building" style="color:var(--amber);"></i>
        All Companies
    </div>
    <button class="mob-menu-btn" id="menuBtn" aria-label="Menu">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<div class="sidebar-overlay" id="sidebarOverlay"></div>

<!-- ════════════════════════════════════════
     SIDEBAR
════════════════════════════════════════ -->
<aside class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fa-solid fa-shield-halved"></i></div>
        <div class="brand-text">
            <div class="brand-name">Old Union</div>
            <div class="brand-sub">Admin Panel</div>
        </div>
    </div>

    <!-- Status quick-filters as stat chips -->
    <div class="sidebar-stats">
        <a href="companies.php" class="stat-chip span-2 <?php echo ($fStatus === '') ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['total']; ?></div>
            <div class="stat-label">All Companies</div>
        </a>
        <a href="<?php echo pageUrl(['status' => 'active',   'page' => 1]); ?>"
           class="stat-chip green <?php echo $fStatus === 'active' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['active']; ?></div>
            <div class="stat-label">Active</div>
        </a>
        <a href="<?php echo pageUrl(['status' => 'pending_verification', 'page' => 1]); ?>"
           class="stat-chip <?php echo $fStatus === 'pending_verification' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['pending']; ?></div>
            <div class="stat-label">Pending</div>
        </a>
        <a href="<?php echo pageUrl(['status' => 'draft', 'page' => 1]); ?>"
           class="stat-chip grey <?php echo $fStatus === 'draft' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['draft']; ?></div>
            <div class="stat-label">Draft</div>
        </a>
        <a href="<?php echo pageUrl(['status' => 'suspended', 'page' => 1]); ?>"
           class="stat-chip red <?php echo $fStatus === 'suspended' ? 'active-filter' : ''; ?>">
            <div class="stat-num"><?php echo $stats['suspended']; ?></div>
            <div class="stat-label">Suspended</div>
        </a>
    </div>

    <div class="sidebar-divider"></div>

    <ul class="sidebar-nav">
        <li class="nav-item">
            <a href="company_review.php">
                <i class="fa-solid fa-inbox"></i> Pending Queue
                <?php if ($stats['pending'] > 0): ?>
                    <span class="nav-count"><?php echo $stats['pending']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item active">
            <a href="companies.php">
                <i class="fa-solid fa-building"></i> All Companies
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/campaigns.php">
                <i class="fa-solid fa-rocket"></i> Campaigns
            </a>
        </li>
        <li class="nav-item">
            <a href="/admin/users.php">
                <i class="fa-solid fa-users"></i> Users
            </a>
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

<!-- ════════════════════════════════════════
     MAIN
════════════════════════════════════════ -->
<main class="main">

    <div class="page-header">
        <div>
            <div class="page-title">All Companies</div>
            <div class="page-subtitle">Browse, search, filter and manage every company on the platform</div>
        </div>
    </div>

    <!-- Toast -->
    <?php if ($flash): ?>
        <div class="toast <?php echo $flash['type']; ?>">
            <i class="fa-solid fa-circle-check"></i>
            <?php echo htmlspecialchars($flash['text']); ?>
        </div>
    <?php endif; ?>

    <!-- ── FILTER BAR ──────────────────────────────── -->
    <div class="filter-bar">
        <form method="GET" action="companies.php" id="filterForm">
            <div class="filter-row">

                <div class="filter-group" style="flex:1;">
                    <label for="q">Search</label>
                    <div class="filter-search-wrap">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="q" name="q" class="filter-input"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="Company name, email, reg. number…"
                               autocomplete="off">
                    </div>
                </div>

                <div class="filter-group">
                    <label for="status">Status</label>
                    <select id="status" name="status" class="filter-select" onchange="this.form.submit()">
                        <option value="">All statuses</option>
                        <option value="active"               <?php echo $fStatus === 'active'               ? 'selected' : ''; ?>>Active</option>
                        <option value="pending_verification" <?php echo $fStatus === 'pending_verification' ? 'selected' : ''; ?>>Pending Review</option>
                        <option value="draft"                <?php echo $fStatus === 'draft'                ? 'selected' : ''; ?>>Draft</option>
                        <option value="suspended"            <?php echo $fStatus === 'suspended'            ? 'selected' : ''; ?>>Suspended</option>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="type">Type</label>
                    <select id="type" name="type" class="filter-select" onchange="this.form.submit()">
                        <option value="">All types</option>
                        <?php foreach ([
                            'startup'           => 'Startup',
                            'sme'               => 'SME',
                            'corporation'       => 'Corporation',
                            'ngo'               => 'NGO / Non-Profit',
                            'cooperative'       => 'Cooperative',
                            'social_enterprise' => 'Social Enterprise',
                            'other'             => 'Other',
                        ] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $fType === $val ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="industry">Industry</label>
                    <select id="industry" name="industry" class="filter-select" onchange="this.form.submit()">
                        <option value="">All industries</option>
                        <?php foreach ($industries as $ind): ?>
                            <option value="<?php echo htmlspecialchars($ind); ?>"
                                    <?php echo $fIndustry === $ind ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($ind); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="filter-group">
                    <label for="stage">Stage</label>
                    <select id="stage" name="stage" class="filter-select" onchange="this.form.submit()">
                        <option value="">All stages</option>
                        <?php foreach ([
                            'idea'        => 'Idea / Pre-Seed',
                            'seed'        => 'Seed',
                            'series_a'    => 'Series A',
                            'series_b'    => 'Series B+',
                            'growth'      => 'Growth',
                            'established' => 'Established',
                        ] as $val => $label): ?>
                            <option value="<?php echo $val; ?>" <?php echo $fStage === $val ? 'selected' : ''; ?>>
                                <?php echo $label; ?>
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
                        <?php if ($search || $fStatus || $fType || $fIndustry || $fStage): ?>
                            <a href="companies.php" class="btn-filter">
                                <i class="fa-solid fa-xmark"></i> Clear
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Preserve sort state across filter submissions -->
                <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">
                <input type="hidden" name="dir"  value="<?php echo htmlspecialchars($dir); ?>">
            </div>

            <!-- Active filter tags -->
            <?php if ($search || $fStatus || $fType || $fIndustry || $fStage): ?>
                <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.85rem;padding-top:.85rem;border-top:1px solid var(--border);">
                    <?php if ($search): ?>
                        <span class="filter-active-tag">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            "<?php echo htmlspecialchars($search); ?>"
                            <a href="<?php echo pageUrl(['q' => '', 'page' => 1]); ?>">×</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($fStatus): ?>
                        <span class="filter-active-tag">
                            Status: <?php echo ucfirst(str_replace('_', ' ', $fStatus)); ?>
                            <a href="<?php echo pageUrl(['status' => '', 'page' => 1]); ?>">×</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($fType): ?>
                        <span class="filter-active-tag">
                            Type: <?php echo ucfirst($fType); ?>
                            <a href="<?php echo pageUrl(['type' => '', 'page' => 1]); ?>">×</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($fIndustry): ?>
                        <span class="filter-active-tag">
                            Industry: <?php echo htmlspecialchars($fIndustry); ?>
                            <a href="<?php echo pageUrl(['industry' => '', 'page' => 1]); ?>">×</a>
                        </span>
                    <?php endif; ?>
                    <?php if ($fStage): ?>
                        <span class="filter-active-tag">
                            Stage: <?php echo stageLabel($fStage); ?>
                            <a href="<?php echo pageUrl(['stage' => '', 'page' => 1]); ?>">×</a>
                        </span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </form>
    </div>

    <!-- ── RESULTS BAR ────────────────────────────── -->
    <div class="results-bar">
        <div class="results-count">
            Showing <strong><?php echo number_format(min($offset + 1, $totalRows)); ?>–<?php echo number_format(min($offset + $perPage, $totalRows)); ?></strong>
            of <strong><?php echo number_format($totalRows); ?></strong>
            <?php echo $totalRows === 1 ? 'company' : 'companies'; ?>
        </div>
        <div style="font-size:.82rem;color:var(--text-muted);">
            Sorted by
            <strong style="color:var(--text);"><?php echo ucfirst(str_replace('_', ' ', $sort)); ?></strong>
            <?php echo $dir === 'asc' ? '↑' : '↓'; ?>
        </div>
    </div>

    <!-- ── EMPTY STATE ────────────────────────────── -->
    <?php if (empty($companies)): ?>
        <div class="empty-state">
            <div class="empty-icon"><i class="fa-solid fa-building-circle-xmark"></i></div>
            <div class="empty-title">No companies found</div>
            <div class="empty-sub">Try adjusting your search or filters.</div>
        </div>

    <?php else: ?>

    <!-- ════════════════════════════════════════
         DESKTOP TABLE
    ════════════════════════════════════════ -->
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th style="width:280px;">
                        <a href="<?php echo sortUrl('name'); ?>">Company <?php echo sortIcon('name'); ?></a>
                    </th>
                    <th>Type / Stage</th>
                    <th>Industry</th>
                    <th>
                        <a href="<?php echo sortUrl('status'); ?>">Status <?php echo sortIcon('status'); ?></a>
                    </th>
                    <th style="text-align:center;">KYC</th>
                    <th style="text-align:center;">Campaigns</th>
                    <th>Owner</th>
                    <th>
                        <a href="<?php echo sortUrl('created_at'); ?>">Added <?php echo sortIcon('created_at'); ?></a>
                    </th>
                    <th style="text-align:right;">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($companies as $c):
                $cId = $c['id'];
                $statusClass = match($c['status']) {
                    'active'               => 'green',
                    'pending_verification' => 'amber',
                    'draft'                => 'grey',
                    'suspended'            => 'red',
                    default                => 'grey',
                };
                $kycLabel = match($c['verification_status'] ?? '') {
                    'approved'     => ['Approved',     'badge-green'],
                    'pending'      => ['Pending',      'badge-amber'],
                    'under_review' => ['In Review',    'badge-blue'],
                    'rejected'     => ['Rejected',     'badge-red'],
                    default        => ['No docs',      'badge-grey'],
                };
                $liveCount = (int) $c['live_campaigns'];
                $campTotal = (int) $c['campaign_count'];
                $totalRaised = (float) ($c['total_raised'] ?? 0);
            ?>
                <tr>
                    <!-- Company -->
                    <td>
                        <div class="company-cell">
                            <?php if ($c['logo']): ?>
                                <img src="<?php echo htmlspecialchars($c['logo']); ?>" alt="" class="company-thumb">
                            <?php else: ?>
                                <div class="company-thumb-placeholder">
                                    <i class="fa-solid fa-building"></i>
                                </div>
                            <?php endif; ?>
                            <div>
                                <div class="company-name">
                                    <a href="company_review.php?id=<?php echo $c['id']; ?>">
                                        <?php echo htmlspecialchars($c['name']); ?>
                                    </a>
                                </div>
                                <div class="company-sub">
                                    <?php echo $c['registration_number']
                                        ? htmlspecialchars($c['registration_number'])
                                        : ($c['email'] ? htmlspecialchars($c['email']) : '—'); ?>
                                </div>
                            </div>
                        </div>
                    </td>

                    <!-- Type / Stage -->
                    <td>
                        <?php echo typePill($c['type']); ?>
                        <?php if ($c['stage']): ?>
                            <br><span style="font-size:.75rem;color:var(--text-muted);margin-top:.3rem;display:inline-block;">
                                <?php echo stageLabel($c['stage']); ?>
                            </span>
                        <?php endif; ?>
                    </td>

                    <!-- Industry -->
                    <td style="max-width:160px;">
                        <span style="font-size:.83rem;color:var(--text-muted);white-space:normal;line-height:1.3;">
                            <?php echo $c['industry'] ? htmlspecialchars($c['industry']) : '<span style="color:var(--text-light);font-style:italic;">Not set</span>'; ?>
                        </span>
                    </td>

                    <!-- Status -->
                    <td>
                        <?php echo statusBadge($c['status'], (bool)$c['verified']); ?>
                    </td>

                    <!-- KYC -->
                    <td>
                        <div class="mini-stat">
                            <span class="badge <?php echo $kycLabel[1]; ?>">
                                <?php echo $kycLabel[0]; ?>
                            </span>
                        </div>
                    </td>

                    <!-- Campaigns -->
                    <td>
                        <div class="mini-stat">
                            <?php if ($campTotal === 0): ?>
                                <div class="mval zero">—</div>
                                <div class="mlbl">None</div>
                            <?php else: ?>
                                <div class="mval <?php echo $liveCount > 0 ? 'live' : ''; ?>">
                                    <?php echo $campTotal; ?>
                                    <?php if ($liveCount > 0): ?>
                                        <span style="font-size:.7rem;font-weight:500;color:var(--emerald);">(<?php echo $liveCount; ?> live)</span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($totalRaised > 0): ?>
                                    <div class="mlbl">R <?php echo number_format($totalRaised, 0, '.', ' '); ?> raised</div>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </td>

                    <!-- Owner -->
                    <td>
                        <span style="font-size:.82rem;color:var(--text-muted);word-break:break-all;">
                            <?php echo $c['owner_email'] ? htmlspecialchars($c['owner_email']) : '<em style="color:var(--text-light)">Unknown</em>'; ?>
                        </span>
                    </td>

                    <!-- Added -->
                    <td>
                        <span style="font-size:.82rem;color:var(--text-muted);" title="<?php echo $c['created_at']; ?>">
                            <?php echo timeAgo($c['created_at']); ?>
                        </span>
                    </td>

                    <!-- Actions -->
                    <td>
                        <div class="actions-cell">
                            <a href="company_review.php?id=<?php echo $cId; ?>"
                               class="btn-action" title="Admin view">
                                <i class="fa-solid fa-shield-halved"></i> View
                            </a>

                            <?php if ($c['status'] === 'pending_verification'): ?>
                                <span class="badge badge-amber" style="font-size:.72rem;">
                                    <i class="fa-solid fa-clock"></i> Needs Review
                                </span>
                            <?php endif; ?>

                            <!-- Dropdown for other actions -->
                            <div class="action-menu-wrap">
                                <button class="action-menu-btn" onclick="toggleMenu(<?php echo $cId; ?>, event)"
                                        title="More actions">
                                    <i class="fa-solid fa-ellipsis-vertical"></i>
                                </button>
                                <div class="action-dropdown" id="menu-<?php echo $cId; ?>">

                                    <?php if ($c['status'] === 'pending_verification' || $c['status'] === 'draft'): ?>
                                        <form method="POST">
                                            <?php /* Preserve filter state for redirect */ ?>
                                            <input type="hidden" name="_q"        value="<?php echo htmlspecialchars($search); ?>">
                                            <input type="hidden" name="_status"   value="<?php echo htmlspecialchars($fStatus); ?>">
                                            <input type="hidden" name="_type"     value="<?php echo htmlspecialchars($fType); ?>">
                                            <input type="hidden" name="_industry" value="<?php echo htmlspecialchars($fIndustry); ?>">
                                            <input type="hidden" name="_page"     value="<?php echo $page; ?>">
                                            <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                                            <input type="hidden" name="action"     value="approve">
                                            <button type="submit" class="dd-item green"
                                                    onclick="return confirm('Approve <?php echo htmlspecialchars(addslashes($c['name'])); ?>?')">
                                                <i class="fa-solid fa-circle-check"></i> Approve &amp; Activate
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'suspended' || $c['status'] === 'draft'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="_q"        value="<?php echo htmlspecialchars($search); ?>">
                                            <input type="hidden" name="_status"   value="<?php echo htmlspecialchars($fStatus); ?>">
                                            <input type="hidden" name="_type"     value="<?php echo htmlspecialchars($fType); ?>">
                                            <input type="hidden" name="_industry" value="<?php echo htmlspecialchars($fIndustry); ?>">
                                            <input type="hidden" name="_page"     value="<?php echo $page; ?>">
                                            <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                                            <input type="hidden" name="action"     value="activate">
                                            <button type="submit" class="dd-item green"
                                                    onclick="return confirm('Set <?php echo htmlspecialchars(addslashes($c['name'])); ?> to active?')">
                                                <i class="fa-solid fa-play"></i> Set Active
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['status'] === 'active'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="_q"        value="<?php echo htmlspecialchars($search); ?>">
                                            <input type="hidden" name="_status"   value="<?php echo htmlspecialchars($fStatus); ?>">
                                            <input type="hidden" name="_type"     value="<?php echo htmlspecialchars($fType); ?>">
                                            <input type="hidden" name="_industry" value="<?php echo htmlspecialchars($fIndustry); ?>">
                                            <input type="hidden" name="_page"     value="<?php echo $page; ?>">
                                            <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                                            <input type="hidden" name="action"     value="suspend">
                                            <button type="submit" class="dd-item red"
                                                    onclick="return confirm('Suspend <?php echo htmlspecialchars(addslashes($c['name'])); ?>? This will hide them from the public.')">
                                                <i class="fa-solid fa-circle-pause"></i> Suspend
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <?php if ($c['status'] !== 'draft'): ?>
                                        <form method="POST">
                                            <input type="hidden" name="_q"        value="<?php echo htmlspecialchars($search); ?>">
                                            <input type="hidden" name="_status"   value="<?php echo htmlspecialchars($fStatus); ?>">
                                            <input type="hidden" name="_type"     value="<?php echo htmlspecialchars($fType); ?>">
                                            <input type="hidden" name="_industry" value="<?php echo htmlspecialchars($fIndustry); ?>">
                                            <input type="hidden" name="_page"     value="<?php echo $page; ?>">
                                            <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                                            <input type="hidden" name="action"     value="draft">
                                            <button type="submit" class="dd-item amber"
                                                    onclick="return confirm('Return <?php echo htmlspecialchars(addslashes($c['name'])); ?> to draft?')">
                                                <i class="fa-solid fa-rotate-left"></i> Return to Draft
                                            </button>
                                        </form>
                                    <?php endif; ?>

                                    <div class="dd-separator"></div>

                                    <a href="company_review.php?id=<?php echo $cId; ?>"
                                       class="dd-item">
                                        <i class="fa-solid fa-shield-halved"></i> Admin View
                                    </a>
                                    <a href="/company/dashboard.php?uuid=<?php echo urlencode($c['uuid']); ?>"
                                       target="_blank" class="dd-item">
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

    <!-- ════════════════════════════════════════
         MOBILE CARDS
    ════════════════════════════════════════ -->
    <div class="mobile-cards">
    <?php foreach ($companies as $c):
        $cId = $c['id'];
        $liveCount  = (int) $c['live_campaigns'];
        $campTotal  = (int) $c['campaign_count'];
        $kycLabel   = match($c['verification_status'] ?? '') {
            'approved'     => ['Approved',  'badge-green'],
            'pending'      => ['Pending',   'badge-amber'],
            'under_review' => ['In Review', 'badge-blue'],
            'rejected'     => ['Rejected',  'badge-red'],
            default        => ['No docs',   'badge-grey'],
        };
    ?>
        <div class="mob-card">
            <div class="mob-card-head">
                <?php if ($c['logo']): ?>
                    <img src="<?php echo htmlspecialchars($c['logo']); ?>" alt="" class="company-thumb">
                <?php else: ?>
                    <div class="company-thumb-placeholder"><i class="fa-solid fa-building"></i></div>
                <?php endif; ?>
                <div class="mob-card-info">
                    <div class="mob-card-name">
                        <a href="/company/dashboard.php?uuid=<?php echo urlencode($c['uuid']); ?>" target="_blank">
                            <?php echo htmlspecialchars($c['name']); ?>
                        </a>
                    </div>
                    <div class="mob-card-badges">
                        <?php echo statusBadge($c['status'], (bool)$c['verified']); ?>
                        <?php echo typePill($c['type']); ?>
                        <?php if ($c['stage']): ?>
                            <span class="pill pill-blue"><?php echo stageLabel($c['stage']); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="mob-card-body">
                <div class="mob-field">
                    <span class="mob-field-label">Industry</span>
                    <span class="mob-field-value <?php echo empty($c['industry']) ? 'none' : ''; ?>">
                        <?php echo $c['industry'] ? htmlspecialchars($c['industry']) : 'Not set'; ?>
                    </span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Owner</span>
                    <span class="mob-field-value <?php echo empty($c['owner_email']) ? 'none' : ''; ?>">
                        <?php echo $c['owner_email'] ? htmlspecialchars($c['owner_email']) : 'Unknown'; ?>
                    </span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">KYC</span>
                    <span class="mob-field-value">
                        <span class="badge <?php echo $kycLabel[1]; ?>"><?php echo $kycLabel[0]; ?></span>
                    </span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Campaigns</span>
                    <span class="mob-field-value <?php echo $campTotal === 0 ? 'none' : ''; ?>">
                        <?php if ($campTotal === 0): ?>
                            None
                        <?php else: ?>
                            <?php echo $campTotal; ?><?php echo $liveCount > 0 ? ' (' . $liveCount . ' live)' : ''; ?>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="mob-field">
                    <span class="mob-field-label">Added</span>
                    <span class="mob-field-value"><?php echo timeAgo($c['created_at']); ?></span>
                </div>
                <?php if ($c['registration_number']): ?>
                <div class="mob-field">
                    <span class="mob-field-label">Reg. No.</span>
                    <span class="mob-field-value"><?php echo htmlspecialchars($c['registration_number']); ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="mob-card-foot">
                <a href="company_review.php?id=<?php echo $cId; ?>"
                   class="btn-action">
                    <i class="fa-solid fa-shield-halved"></i> Admin View
                </a>
                <a href="/company/dashboard.php?uuid=<?php echo urlencode($c['uuid']); ?>"
                   target="_blank" class="btn-action">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Public
                </a>

                <?php if (in_array($c['status'], ['pending_verification', 'draft', 'suspended'])): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                        <input type="hidden" name="action"     value="approve">
                        <button type="submit" class="btn-action green"
                                onclick="return confirm('Approve <?php echo htmlspecialchars(addslashes($c['name'])); ?>?')">
                            <i class="fa-solid fa-circle-check"></i> Approve
                        </button>
                    </form>
                <?php endif; ?>

                <?php if ($c['status'] === 'active'): ?>
                    <form method="POST" style="display:inline;">
                        <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                        <input type="hidden" name="action"     value="suspend">
                        <button type="submit" class="btn-action red"
                                onclick="return confirm('Suspend <?php echo htmlspecialchars(addslashes($c['name'])); ?>?')">
                            <i class="fa-solid fa-circle-pause"></i> Suspend
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
    </div>

    <!-- ── PAGINATION ──────────────────────────── -->
    <?php if ($totalPages > 1): ?>
    <div class="pagination">
        <div class="pag-info">
            Page <strong><?php echo $page; ?></strong> of <strong><?php echo $totalPages; ?></strong>
        </div>
        <div class="pag-pages">
            <!-- Prev -->
            <?php if ($page > 1): ?>
                <a href="<?php echo pageUrl(['page' => $page - 1]); ?>" class="pag-btn">
                    <i class="fa-solid fa-chevron-left"></i>
                </a>
            <?php else: ?>
                <span class="pag-btn disabled"><i class="fa-solid fa-chevron-left"></i></span>
            <?php endif; ?>

            <?php
            // Page number buttons — show first, last, and a window around current
            $window = 2;
            $shown  = [];
            for ($p = 1; $p <= $totalPages; $p++) {
                if ($p === 1 || $p === $totalPages || abs($p - $page) <= $window) {
                    $shown[] = $p;
                }
            }
            $prev = null;
            foreach ($shown as $p):
                if ($prev !== null && $p - $prev > 1):
            ?>
                <span class="pag-ellipsis">…</span>
            <?php endif; ?>
            <a href="<?php echo pageUrl(['page' => $p]); ?>"
               class="pag-btn <?php echo $p === $page ? 'active' : ''; ?>">
                <?php echo $p; ?>
            </a>
            <?php
                $prev = $p;
            endforeach;
            ?>

            <!-- Next -->
            <?php if ($page < $totalPages): ?>
                <a href="<?php echo pageUrl(['page' => $page + 1]); ?>" class="pag-btn">
                    <i class="fa-solid fa-chevron-right"></i>
                </a>
            <?php else: ?>
                <span class="pag-btn disabled"><i class="fa-solid fa-chevron-right"></i></span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty($companies) ?>

</main>

<script>
/* ── Mobile sidebar ─────────────────────────── */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const menuBtn = document.getElementById('menuBtn');

function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('open'); menuBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>'; }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); menuBtn.innerHTML = '<i class="fa-solid fa-bars"></i>'; }

menuBtn.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
overlay.addEventListener('click', closeSidebar);

/* ── Action dropdown ────────────────────────── */
let openMenu = null;

function toggleMenu(id, e) {
    e.stopPropagation();
    const menu = document.getElementById('menu-' + id);
    if (!menu) return;
    if (openMenu && openMenu !== menu) openMenu.classList.remove('open');
    menu.classList.toggle('open');
    openMenu = menu.classList.contains('open') ? menu : null;
}

document.addEventListener('click', () => {
    if (openMenu) { openMenu.classList.remove('open'); openMenu = null; }
});

/* ── Toast auto-dismiss ─────────────────────── */
const toast = document.querySelector('.toast');
if (toast) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => { toast.style.transition = 'opacity .4s'; toast.style.opacity = '0'; setTimeout(() => toast.remove(), 400); }, 5000);
}

/* ── Live search (debounced) ────────────────── */
const searchInput = document.getElementById('q');
if (searchInput) {
    let searchTimer;
    searchInput.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            if (searchInput.value.length === 0 || searchInput.value.length >= 2) {
                document.getElementById('filterForm').submit();
            }
        }, 500);
    });
}
</script>
</body>
</html>
