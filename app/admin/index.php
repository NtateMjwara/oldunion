<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

// Auth guard — uncomment in production:
 if (!isLoggedIn() || !in_array($_SESSION['user_role'] ?? '', ['admin','super_admin'])) {
     die('Access denied.');
}

$pdo = Database::getInstance();

/* ═══════════════════════════════════════════
   Single-company mode detection
   ?id=X  → show one company regardless of status
   (no id) → show pending verification queue
═══════════════════════════════════════════ */
$viewId     = (int) ($_GET['id'] ?? 0);
$singleMode = $viewId > 0;

/* ═══════════════════════════════════════════
   POST — status actions (PRG pattern)
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $companyId = (int) $_POST['company_id'];
    $action    = $_POST['action'];
    $reason    = trim($_POST['rejection_reason'] ?? '');
    $postToast = null;

    switch ($action) {

        case 'approve':
            $pdo->prepare("UPDATE companies SET status = 'active', verified = 1 WHERE id = ?")
                ->execute([$companyId]);
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'approved', verified_at = NOW() WHERE company_id = ?")
                ->execute([$companyId]);
            logCompanyActivity($companyId, $_SESSION['user_id'], 'Company approved by admin');
            $postToast = 'approved';
            break;

        case 'reject':
            if ($reason === '') {
                // Cannot redirect on validation failure — stay on page with error
                $toast = ['type' => 'error', 'msg' => 'A rejection reason is required.'];
                break;
            }
            $pdo->prepare("UPDATE companies SET status = 'draft' WHERE id = ?")
                ->execute([$companyId]);
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'rejected', rejection_reason = ? WHERE company_id = ?")
                ->execute([$reason, $companyId]);
            logCompanyActivity($companyId, $_SESSION['user_id'], "Company rejected: $reason");
            $postToast = 'rejected';
            break;

        case 'suspend':
            $pdo->prepare("UPDATE companies SET status = 'suspended', verified = 0 WHERE id = ?")
                ->execute([$companyId]);
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'pending' WHERE company_id = ?")
                ->execute([$companyId]);
            logCompanyActivity($companyId, $_SESSION['user_id'], 'Company suspended by admin');
            $postToast = 'suspended';
            break;

        case 'activate':
            $pdo->prepare("UPDATE companies SET status = 'active', verified = 1 WHERE id = ?")
                ->execute([$companyId]);
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'approved', verified_at = NOW() WHERE company_id = ?")
                ->execute([$companyId]);
            logCompanyActivity($companyId, $_SESSION['user_id'], 'Company reinstated by admin');
            $postToast = 'activated';
            break;

        case 'return_draft':
            $pdo->prepare("UPDATE companies SET status = 'draft', verified = 0 WHERE id = ?")
                ->execute([$companyId]);
            $pdo->prepare("UPDATE company_kyc SET verification_status = 'pending' WHERE company_id = ?")
                ->execute([$companyId]);
            logCompanyActivity($companyId, $_SESSION['user_id'], 'Company returned to draft by admin');
            $postToast = 'drafted';
            break;
    }

    // PRG: redirect so refresh doesn't re-submit
    if (isset($postToast)) {
        $dest = $singleMode
            ? "company_review.php?id={$viewId}&flash={$postToast}"
            : "company_review.php?flash={$postToast}";
        header("Location: $dest");
        exit;
    }
}

/* ── Flash message from redirect ─────────── */
if (!isset($toast) && !empty($_GET['flash'])) {
    $flashMap = [
        'approved'  => ['success', 'Company approved and set to active.'],
        'rejected'  => ['success', 'Company rejected and returned to draft.'],
        'suspended' => ['success', 'Company suspended.'],
        'activated' => ['success', 'Company reinstated and set to active.'],
        'drafted'   => ['success', 'Company returned to draft.'],
    ];
    [$ft, $fm] = $flashMap[$_GET['flash']] ?? ['success', 'Action completed.'];
    $toast = ['type' => $ft, 'msg' => $fm];
}

/* ═══════════════════════════════════════════
   Sidebar stats — MySQL-compatible
═══════════════════════════════════════════ */
$statsRow = [
    'pending'        => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'pending_verification'")->fetchColumn(),
    'approved_today' => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'active' AND DATE(updated_at) = CURDATE()")->fetchColumn(),
    'rejected_today' => (int) $pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'draft' AND verified = 0 AND DATE(updated_at) = CURDATE()")->fetchColumn(),
];

/* ═══════════════════════════════════════════
   Fetch companies — single or queue mode
═══════════════════════════════════════════ */
$companyQuery = "
    SELECT
        c.*,
        k.verification_status, k.registration_document, k.proof_of_address,
        k.director_id_document, k.tax_clearance_document, k.rejection_reason AS kyc_rejection,
        p.problem_statement, p.solution, p.business_model, p.traction,
        p.target_market, p.competitive_landscape, p.team_overview,
        p.pitch_deck_url, p.pitch_video_url,
        u.email AS owner_email,
        (SELECT COUNT(*) FROM funding_campaigns fc WHERE fc.company_id = c.id) AS campaign_count
    FROM companies c
    LEFT JOIN company_kyc k        ON c.id = k.company_id
    LEFT JOIN company_pitch p      ON c.id = p.company_id
    LEFT JOIN company_admins ca_ow ON ca_ow.company_id = c.id AND ca_ow.role = 'owner'
    LEFT JOIN users u              ON ca_ow.user_id = u.id
";

if ($singleMode) {
    $stmt = $pdo->prepare($companyQuery . " WHERE c.id = :id LIMIT 1");
    $stmt->execute([':id' => $viewId]);
    $row  = $stmt->fetch();
    $pending = $row ? [$row] : [];
} else {
    $stmt = $pdo->query($companyQuery . " WHERE c.status = 'pending_verification' ORDER BY c.created_at ASC");
    $pending = $stmt->fetchAll();
}

/* Per-company highlights + campaigns (fetched in loop below) */
$highlightsCache = [];
$campaignsCache  = [];
if (!empty($pending)) {
    $ids     = array_column($pending, 'id');
    $inClause = implode(',', array_map('intval', $ids));

    $hlStmt = $pdo->query("
        SELECT company_id, label, value, sort_order
        FROM pitch_highlights WHERE company_id IN ($inClause)
        ORDER BY sort_order ASC
    ");
    foreach ($hlStmt->fetchAll() as $hl) {
        $highlightsCache[$hl['company_id']][] = $hl;
    }

    $campStmt = $pdo->query("
        SELECT company_id, title, campaign_type, raise_target, total_raised,
               contributor_count, status, opens_at, closes_at, use_of_funds
        FROM funding_campaigns WHERE company_id IN ($inClause)
        ORDER BY created_at DESC
    ");
    foreach ($campStmt->fetchAll() as $camp) {
        $campaignsCache[$camp['company_id']][] = $camp;
    }
}

/* ── Helpers ──────────────────────────────── */
function typeBadge(string $type): string {
    $map = [
        'startup'           => ['label' => 'Startup',          'cls' => 'badge-amber'],
        'sme'               => ['label' => 'SME',              'cls' => 'badge-blue'],
        'corporation'       => ['label' => 'Corporation',      'cls' => 'badge-navy'],
        'ngo'               => ['label' => 'NGO',              'cls' => 'badge-teal'],
        'cooperative'       => ['label' => 'Cooperative',      'cls' => 'badge-purple'],
        'social_enterprise' => ['label' => 'Social Enterprise','cls' => 'badge-green'],
        'other'             => ['label' => 'Other',            'cls' => 'badge-grey'],
    ];
    $b = $map[$type] ?? ['label' => ucfirst($type), 'cls' => 'badge-grey'];
    return '<span class="badge ' . $b['cls'] . '">' . $b['label'] . '</span>';
}

function stageLabel(string $s): string {
    return [
        'idea'        => 'Idea / Pre-Seed',
        'seed'        => 'Seed',
        'series_a'    => 'Series A',
        'series_b'    => 'Series B+',
        'growth'      => 'Growth',
        'established' => 'Established',
    ][$s] ?? ucfirst(str_replace('_', ' ', $s));
}

function campaignTypeBadge(string $t): string {
    $map = [
        'revenue_share'          => ['Revenue Share',       'badge-amber'],
        'fixed_return_loan'      => ['Fixed Return Loan',   'badge-blue'],
        'cooperative_membership' => ['Co-op Membership',   'badge-teal'],
        'donation'               => ['Donation',            'badge-green'],
        'convertible_note'       => ['Convertible Note',   'badge-purple'],
    ];
    [$label, $cls] = $map[$t] ?? [ucfirst($t), 'badge-grey'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}

function campaignStatusBadge(string $s): string {
    $map = [
        'draft'                => ['Draft',              'badge-grey'],
        'under_review'         => ['Under Review',       'badge-amber'],
        'approved'             => ['Approved',           'badge-blue'],
        'open'                 => ['Live',               'badge-green'],
        'funded'               => ['Funded',             'badge-teal'],
        'closed_successful'    => ['Closed ✓',          'badge-green'],
        'closed_unsuccessful'  => ['Closed ✗',          'badge-red'],
        'cancelled'            => ['Cancelled',          'badge-red'],
        'suspended'            => ['Suspended',          'badge-red'],
    ];
    [$label, $cls] = $map[$s] ?? [ucfirst($s), 'badge-grey'];
    return '<span class="badge ' . $cls . '">' . $label . '</span>';
}

function docLink(?string $path, string $label, string $icon): string {
    if (empty($path)) {
        return '<span class="doc-missing"><i class="fa-solid fa-xmark"></i> ' . $label . ' not uploaded</span>';
    }
    return '<a href="' . htmlspecialchars($path) . '" target="_blank" class="doc-link">'
         . '<i class="fa-solid ' . $icon . '"></i> ' . $label . '</a>';
}

function pitchField(string $heading, ?string $content, string $icon): string {
    if (empty(trim($content ?? ''))) return '';
    return '<div class="pitch-field">'
         . '<div class="pitch-field-label"><i class="fa-solid ' . $icon . '"></i> ' . $heading . '</div>'
         . '<div class="pitch-field-body">' . nl2br(htmlspecialchars($content)) . '</div>'
         . '</div>';
}

function timeAgo(string $datetime): string {
    $diff = time() - strtotime($datetime);
    if ($diff < 3600)   return round($diff / 60) . 'm ago';
    if ($diff < 86400)  return round($diff / 3600) . 'h ago';
    if ($diff < 604800) return round($diff / 86400) . 'd ago';
    return date('d M Y', strtotime($datetime));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Review Queue | Old Union Admin</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">

    <style>
    /* ══════════════════════════════════════════
       DESIGN TOKENS
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
        --shadow-sm:     0 1px 3px rgba(11,37,69,.08), 0 1px 2px rgba(11,37,69,.04);
        --shadow-card:   0 4px 24px rgba(11,37,69,.07), 0 1px 4px rgba(11,37,69,.04);
        --shadow-sidebar:4px 0 20px rgba(0,0,0,.12);
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
       MOBILE HEADER (hidden on desktop)
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
        font-size: 1.2rem;
        color: #fff;
        display: flex;
        align-items: center;
        gap: .6rem;
    }

    .mob-brand .queue-badge {
        background: var(--amber);
        color: var(--navy);
        border-radius: 99px;
        font-family: 'DM Sans', sans-serif;
        font-size: .72rem;
        font-weight: 700;
        padding: .15rem .55rem;
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
       SIDEBAR
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
        box-shadow: var(--shadow-sidebar);
        transition: transform var(--transition);
    }

    /* Decorative circle */
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
        display: flex;
        align-items: center;
        gap: .75rem;
        margin-bottom: 2rem;
        padding-bottom: 1.5rem;
        border-bottom: 1px solid rgba(255,255,255,.08);
    }

    .brand-icon {
        width: 40px; height: 40px;
        background: var(--amber);
        border-radius: 10px;
        display: flex; align-items: center; justify-content: center;
        color: var(--navy);
        font-size: 1rem;
        flex-shrink: 0;
    }

    .brand-text { line-height: 1.1; }
    .brand-name { font-family: 'DM Serif Display', serif; font-size: 1.15rem; color: #fff; }
    .brand-sub  { font-size: .7rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .1em; }

    /* Stats strip */
    .sidebar-stats {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: .6rem;
        margin-bottom: 1.75rem;
    }

    .stat-chip {
        background: rgba(255,255,255,.05);
        border: 1px solid rgba(255,255,255,.08);
        border-radius: var(--radius-sm);
        padding: .6rem .75rem;
        text-align: center;
    }
    .stat-chip.span-2 { grid-column: span 2; }

    .stat-num {
        font-size: 1.5rem;
        font-weight: 700;
        color: var(--amber);
        line-height: 1;
        margin-bottom: .15rem;
    }
    .stat-chip.green .stat-num { color: #4ade80; }
    .stat-chip.red   .stat-num { color: #f87171; }
    .stat-label { font-size: .68rem; color: rgba(255,255,255,.4); text-transform: uppercase; letter-spacing: .07em; }

    /* Nav */
    .sidebar-nav { list-style: none; margin-bottom: 1.5rem; }

    .nav-item a, .nav-item button {
        display: flex;
        align-items: center;
        gap: .75rem;
        padding: .7rem .9rem;
        border-radius: var(--radius-sm);
        color: rgba(255,255,255,.55);
        text-decoration: none;
        font-size: .88rem;
        font-weight: 500;
        width: 100%;
        border: none;
        background: transparent;
        cursor: pointer;
        transition: all var(--transition);
    }

    .nav-item a:hover, .nav-item button:hover {
        background: rgba(255,255,255,.07);
        color: rgba(255,255,255,.85);
    }

    .nav-item.active a {
        background: rgba(245,158,11,.15);
        color: var(--amber);
        font-weight: 600;
    }

    .nav-item a i, .nav-item button i { width: 18px; text-align: center; font-size: .9rem; }

    .nav-count {
        margin-left: auto;
        background: var(--amber);
        color: var(--navy);
        border-radius: 99px;
        font-size: .68rem;
        font-weight: 700;
        padding: .1rem .45rem;
        min-width: 20px;
        text-align: center;
    }

    .sidebar-divider {
        height: 1px;
        background: rgba(255,255,255,.07);
        margin: .75rem 0;
    }

    .sidebar-footer {
        margin-top: auto;
        padding-top: 1rem;
        border-top: 1px solid rgba(255,255,255,.08);
        font-size: .78rem;
        color: rgba(255,255,255,.3);
        text-align: center;
        line-height: 1.5;
    }

    /* Mobile sidebar overlay */
    .sidebar-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,.5);
        z-index: 150;
    }

    /* ══════════════════════════════════════════
       MAIN CONTENT
    ══════════════════════════════════════════ */
    .main {
        flex: 1;
        padding: 2rem 2.5rem;
        overflow-x: hidden;
        min-width: 0;
    }

    /* Page header */
    .page-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 1rem;
        margin-bottom: 2rem;
    }

    .page-title { font-family: 'DM Serif Display', serif; font-size: 1.8rem; color: var(--navy); line-height: 1.1; }
    .page-subtitle { font-size: .9rem; color: var(--text-muted); margin-top: .25rem; }
    .page-back-link {
        display: inline-flex; align-items: center; gap: .4rem;
        font-size: .82rem; font-weight: 500; color: var(--text-muted);
        text-decoration: none; transition: color var(--transition);
    }
    .page-back-link:hover { color: var(--navy); }

    .refresh-btn {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .55rem 1.1rem; border-radius: 99px;
        border: 1.5px solid var(--border); background: var(--surface);
        color: var(--text-muted); font-family: 'DM Sans', sans-serif;
        font-size: .83rem; font-weight: 500; cursor: pointer; text-decoration: none;
        transition: all var(--transition);
    }
    .refresh-btn:hover { border-color: var(--navy-light); color: var(--navy); background: #eff4ff; }

    /* Toast */
    .toast {
        display: flex; align-items: center; gap: .75rem;
        padding: 1rem 1.5rem; border-radius: var(--radius-sm);
        margin-bottom: 1.5rem; font-size: .9rem; font-weight: 500;
        border: 1px solid transparent; animation: toastIn .3s ease;
    }
    @keyframes toastIn { from { opacity:0; transform:translateY(-8px); } to { opacity:1; transform:translateY(0); } }
    .toast.success { background: var(--emerald-bg); color: var(--emerald); border-color: var(--emerald-bdr); }
    .toast.error   { background: var(--red-bg);     color: var(--red);     border-color: var(--red-bdr); }
    .toast i { font-size: 1.1rem; }

    /* Empty state */
    .empty-state {
        text-align: center; padding: 5rem 2rem;
        background: var(--surface); border-radius: var(--radius);
        border: 1px solid var(--border); box-shadow: var(--shadow-card);
    }
    .empty-icon { font-size: 3rem; color: var(--text-light); margin-bottom: 1.25rem; }
    .empty-title { font-size: 1.4rem; font-weight: 600; color: var(--navy); margin-bottom: .5rem; }
    .empty-sub { color: var(--text-muted); font-size: .95rem; }

    /* ── Review Cards ───────────────────────── */
    .review-cards { display: flex; flex-direction: column; gap: 2rem; }

    .review-card {
        background: var(--surface);
        border-radius: var(--radius);
        border: 1px solid var(--border);
        box-shadow: var(--shadow-card);
        overflow: hidden;
    }

    /* Card Top Banner */
    .card-banner {
        height: 120px;
        background: linear-gradient(135deg, var(--navy-mid) 0%, var(--navy) 100%);
        background-size: cover;
        background-position: center;
        position: relative;
    }

    .card-banner-overlay {
        position: absolute;
        inset: 0;
        background: linear-gradient(to bottom, transparent 40%, rgba(11,37,69,.7) 100%);
    }

    .card-header {
        display: flex;
        align-items: flex-end;
        gap: 1rem;
        padding: 0 1.75rem 1.25rem;
        margin-top: -40px;
        position: relative;
        z-index: 1;
        flex-wrap: wrap;
    }

    .card-logo {
        width: 72px; height: 72px;
        border-radius: 14px;
        border: 3px solid var(--surface);
        background: var(--surface-2);
        object-fit: cover;
        box-shadow: 0 4px 12px rgba(0,0,0,.12);
        flex-shrink: 0;
    }

    .card-logo-placeholder {
        width: 72px; height: 72px;
        border-radius: 14px;
        border: 3px solid var(--surface);
        background: var(--navy-mid);
        display: flex; align-items: center; justify-content: center;
        color: rgba(255,255,255,.6);
        font-size: 1.75rem;
        box-shadow: 0 4px 12px rgba(0,0,0,.12);
        flex-shrink: 0;
    }

    .card-title-area {
        flex: 1;
        padding-bottom: .25rem;
        min-width: 200px;
    }

    .card-company-name {
        font-size: 1.35rem;
        font-weight: 700;
        color: var(--navy);
        line-height: 1.2;
        margin-bottom: .4rem;
    }

    .card-meta {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .4rem;
        font-size: .8rem;
        color: var(--text-muted);
    }

    .card-meta .dot { color: var(--border); }

    .card-wait {
        display: inline-flex; align-items: center; gap: .3rem;
        font-size: .75rem; color: var(--amber-dark); font-weight: 500;
        background: #fef3c7; padding: .2rem .6rem; border-radius: 99px;
        border: 1px solid #fde68a;
    }

    /* ── Tab Nav ────────────────────────────── */
    .card-tabs {
        display: flex;
        border-bottom: 1px solid var(--border);
        overflow-x: auto;
        scrollbar-width: none;
        padding: 0 1.75rem;
        gap: 0;
    }
    .card-tabs::-webkit-scrollbar { display: none; }

    .tab-btn {
        display: inline-flex; align-items: center; gap: .4rem;
        padding: .85rem 1.1rem;
        border: none; background: transparent; cursor: pointer;
        font-family: 'DM Sans', sans-serif; font-size: .85rem; font-weight: 500;
        color: var(--text-muted);
        border-bottom: 2.5px solid transparent;
        margin-bottom: -1px;
        transition: all var(--transition);
        white-space: nowrap;
    }
    .tab-btn:hover { color: var(--navy); }
    .tab-btn.active { color: var(--navy-mid); border-bottom-color: var(--navy-mid); font-weight: 600; }
    .tab-btn .tab-count {
        background: var(--surface-2); border: 1px solid var(--border);
        border-radius: 99px; font-size: .68rem; font-weight: 600;
        padding: .05rem .4rem; color: var(--text-muted);
    }
    .tab-btn.active .tab-count { background: var(--navy-mid); color: #fff; border-color: var(--navy-mid); }

    /* ── Tab Panels ─────────────────────────── */
    .tab-panels { padding: 1.75rem; }

    .tab-panel { display: none; }
    .tab-panel.active { display: block; }

    /* ── Overview panel ─────────────────────── */
    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
        gap: 1rem;
        margin-bottom: 1.5rem;
    }

    .info-item { display: flex; flex-direction: column; gap: .25rem; }
    .info-label { font-size: .72rem; font-weight: 600; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); }
    .info-value { font-size: .92rem; font-weight: 500; color: var(--text); word-break: break-word; }
    .info-value a { color: var(--navy-light); text-decoration: none; }
    .info-value a:hover { text-decoration: underline; }
    .info-value.none { color: var(--text-light); font-style: italic; }

    /* Highlights chips */
    .highlights-wrap { display: flex; flex-wrap: wrap; gap: .6rem; margin-top: 1rem; }

    .highlight-chip {
        display: inline-flex; flex-direction: column;
        background: var(--surface-2); border: 1.5px solid var(--border);
        border-radius: var(--radius-sm); padding: .5rem .85rem;
        min-width: 110px;
    }
    .highlight-chip .hl-val { font-size: 1rem; font-weight: 700; color: var(--navy); line-height: 1; }
    .highlight-chip .hl-lbl { font-size: .7rem; color: var(--text-muted); margin-top: .2rem; }

    /* ── Documents panel ────────────────────── */
    .docs-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
        gap: .85rem;
    }

    .doc-card {
        display: flex; align-items: center; gap: .85rem;
        padding: .9rem 1.1rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        background: var(--surface-2);
        transition: border-color var(--transition);
    }
    .doc-card:hover { border-color: var(--navy-light); }
    .doc-card:has(.doc-missing) { background: #fffbeb; border-color: #fde68a; }

    .doc-icon {
        width: 38px; height: 38px; border-radius: 8px;
        background: #eff4ff; color: var(--navy-light);
        display: flex; align-items: center; justify-content: center;
        font-size: .95rem; flex-shrink: 0;
    }
    .doc-icon.missing { background: #fffbeb; color: #b45309; }

    .doc-info { flex: 1; min-width: 0; }
    .doc-name { font-size: .82rem; font-weight: 600; color: var(--text); margin-bottom: .15rem; }
    .doc-link { font-size: .78rem; color: var(--navy-light); text-decoration: none; display: inline-flex; align-items: center; gap: .3rem; }
    .doc-link:hover { text-decoration: underline; }
    .doc-missing { font-size: .78rem; color: #b45309; display: inline-flex; align-items: center; gap: .3rem; }

    /* ── Pitch panel ────────────────────────── */
    .pitch-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 1rem;
    }

    .pitch-field {
        background: var(--surface-2); border: 1.5px solid var(--border);
        border-radius: var(--radius-sm); padding: 1rem 1.1rem;
        transition: border-color var(--transition);
    }
    .pitch-field:hover { border-color: var(--border-focus, var(--navy-light)); }
    .pitch-field.span-2 { grid-column: span 2; }

    .pitch-field-label {
        font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .08em; color: var(--navy-light);
        margin-bottom: .5rem; display: flex; align-items: center; gap: .35rem;
    }

    .pitch-field-body {
        font-size: .88rem; color: var(--text); line-height: 1.6;
        max-height: 140px; overflow-y: auto;
    }

    /* Use of funds table */
    .uof-table { width: 100%; border-collapse: collapse; font-size: .85rem; margin-top: .5rem; }
    .uof-table th { text-align: left; font-size: .7rem; text-transform: uppercase; letter-spacing: .07em; color: var(--text-light); padding: .4rem .6rem; border-bottom: 1px solid var(--border); }
    .uof-table td { padding: .5rem .6rem; border-bottom: 1px solid var(--border); color: var(--text); }
    .uof-table td:last-child { text-align: right; font-weight: 600; color: var(--navy); }
    .uof-table tfoot td { font-weight: 700; color: var(--navy); padding-top: .75rem; border-top: 2px solid var(--border); border-bottom: none; }

    .pitch-assets { display: flex; gap: .75rem; flex-wrap: wrap; margin-top: 1rem; }

    .pitch-asset-btn {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .5rem 1.1rem; border-radius: 99px;
        font-family: 'DM Sans', sans-serif; font-size: .82rem; font-weight: 600;
        text-decoration: none; border: 1.5px solid var(--border);
        background: var(--surface-2); color: var(--navy);
        transition: all var(--transition);
    }
    .pitch-asset-btn:hover { border-color: var(--navy-light); background: #eff4ff; color: var(--navy-mid); }
    .pitch-asset-btn.red-btn { border-color: #fecaca; background: var(--red-bg); color: var(--red); }
    .pitch-asset-btn.red-btn:hover { background: var(--red); color: #fff; }

    /* ── Campaigns panel ────────────────────── */
    .campaigns-list { display: flex; flex-direction: column; gap: .85rem; }

    .campaign-row {
        display: flex; align-items: center; flex-wrap: wrap; gap: .85rem;
        padding: 1rem 1.25rem;
        border: 1.5px solid var(--border); border-radius: var(--radius-sm);
        background: var(--surface-2);
    }

    .campaign-title { font-weight: 600; font-size: .92rem; color: var(--navy); flex: 1; min-width: 150px; }
    .campaign-sub { font-size: .78rem; color: var(--text-muted); margin-top: .15rem; }

    .campaign-stats { display: flex; gap: 1.5rem; }
    .camp-stat { text-align: center; }
    .camp-stat-num { font-size: 1rem; font-weight: 700; color: var(--navy); }
    .camp-stat-label { font-size: .68rem; color: var(--text-light); text-transform: uppercase; letter-spacing: .05em; }

    /* ── Action Panel ───────────────────────── */
    .action-panel {
        border-top: 1px solid var(--border);
        padding: 1.5rem 1.75rem;
        background: var(--surface-2);
    }

    .action-panel-inner {
        display: flex;
        gap: 1rem;
        flex-wrap: wrap;
        align-items: flex-start;
    }

    .approve-form, .reject-form { flex: 1; min-width: 220px; }
    .action-form-half { flex: 1; min-width: 160px; }
    .action-note {
        flex: 1; font-size: .83rem; color: var(--text-muted); padding: .6rem .9rem;
        background: var(--surface-2); border: 1px solid var(--border);
        border-radius: var(--radius-sm); display: flex; align-items: center; gap: .5rem;
    }
    .action-note i { color: var(--navy-light); flex-shrink: 0; }

    .btn-approve {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .75rem 1.75rem; border-radius: 99px; border: none; cursor: pointer;
        font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 600;
        background: var(--emerald); color: #fff;
        box-shadow: 0 4px 12px rgba(5,150,105,.25);
        transition: all var(--transition);
        width: 100%;
        justify-content: center;
    }
    .btn-approve:hover { background: #047857; transform: translateY(-1px); box-shadow: 0 6px 16px rgba(5,150,105,.3); }

    .reject-textarea {
        width: 100%; padding: .65rem .9rem;
        border: 1.5px solid var(--border); border-radius: var(--radius-sm);
        font-family: 'DM Sans', sans-serif; font-size: .85rem; color: var(--text);
        background: var(--surface); resize: vertical; min-height: 72px;
        transition: border-color var(--transition);
        margin-bottom: .6rem;
    }
    .reject-textarea:focus { outline: none; border-color: var(--red); box-shadow: 0 0 0 3px rgba(220,38,38,.08); }

    .btn-reject {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .75rem 1.75rem; border-radius: 99px;
        border: 1.5px solid var(--red-bdr); cursor: pointer;
        font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 600;
        background: var(--red-bg); color: var(--red);
        transition: all var(--transition);
        width: 100%;
        justify-content: center;
    }
    .btn-reject:hover { background: var(--red); color: #fff; border-color: var(--red); }

    .btn-neutral {
        display: inline-flex; align-items: center; gap: .5rem;
        padding: .75rem 1.75rem; border-radius: 99px;
        border: 1.5px solid var(--border); cursor: pointer;
        font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 600;
        background: var(--surface-2); color: var(--text-muted);
        transition: all var(--transition);
        width: 100%; justify-content: center;
    }
    .btn-neutral:hover { background: var(--surface); border-color: var(--navy-light); color: var(--navy); }

    /* ── Badges ─────────────────────────────── */
    .badge {
        display: inline-flex; align-items: center;
        padding: .2rem .7rem; border-radius: 99px;
        font-size: .72rem; font-weight: 600; white-space: nowrap;
    }
    .badge-amber  { background: #fef3c7; color: #92400e; }
    .badge-blue   { background: #dbeafe; color: #1e40af; }
    .badge-navy   { background: #e0e7ff; color: #3730a3; }
    .badge-teal   { background: #ccfbf1; color: #134e4a; }
    .badge-purple { background: #f3e8ff; color: #6b21a8; }
    .badge-green  { background: #dcfce7; color: #14532d; }
    .badge-grey   { background: var(--surface-2); color: var(--text-muted); border: 1px solid var(--border); }
    .badge-red    { background: var(--red-bg); color: var(--red); }

    /* ── Divider ────────────────────────────── */
    .section-divider {
        font-size: .72rem; font-weight: 700; text-transform: uppercase;
        letter-spacing: .08em; color: var(--text-light);
        display: flex; align-items: center; gap: .6rem;
        margin: 1.25rem 0 1rem;
    }
    .section-divider::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    /* ══════════════════════════════════════════
       RESPONSIVE
    ══════════════════════════════════════════ */
    @media (max-width: 900px) {
        body { flex-direction: column; }

        .mob-header { display: flex; }

        .sidebar {
            position: fixed;
            top: 0; left: 0; bottom: 0;
            transform: translateX(-100%);
            z-index: 160;
            height: 100%;
        }
        .sidebar.open { transform: translateX(0); }
        .sidebar-overlay { display: block; }
        .sidebar-overlay.open { display: block; }

        .main {
            margin-top: var(--header-h);
            padding: 1.5rem 1.25rem;
        }

        .page-header { margin-bottom: 1.25rem; }
        .page-title { font-size: 1.4rem; }

        .pitch-grid { grid-template-columns: 1fr; }
        .pitch-field.span-2 { grid-column: span 1; }

        .action-panel-inner { flex-direction: column; }
        .approve-form, .reject-form { min-width: 100%; }

        .campaign-stats { gap: 1rem; }
    }

    @media (max-width: 540px) {
        .main { padding: 1rem .85rem; }
        .card-tabs { padding: 0 1rem; }
        .tab-panels { padding: 1.25rem 1rem; }
        .card-header { padding: 0 1rem 1rem; }
        .action-panel { padding: 1.25rem 1rem; }
        .info-grid { grid-template-columns: 1fr 1fr; }
        .docs-grid { grid-template-columns: 1fr; }
    }

    /* Scrollbar */
    ::-webkit-scrollbar { width: 5px; height: 5px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }
    </style>
</head>
<body>

<!-- ════════════════════════════════════════
     MOBILE HEADER
════════════════════════════════════════ -->
<header class="mob-header" id="mobHeader">
    <div class="mob-brand">
        <i class="fa-solid fa-shield-halved" style="color:var(--amber);"></i>
        Review Queue
        <?php if ($statsRow['pending'] > 0): ?>
            <span class="queue-badge"><?php echo $statsRow['pending']; ?></span>
        <?php endif; ?>
    </div>
    <button class="mob-menu-btn" id="menuBtn" aria-label="Open navigation">
        <i class="fa-solid fa-bars"></i>
    </button>
</header>

<!-- ════════════════════════════════════════
     SIDEBAR OVERLAY (mobile)
════════════════════════════════════════ -->
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

    <!-- Stats -->
    <div class="sidebar-stats">
        <div class="stat-chip span-2">
            <div class="stat-num"><?php echo $statsRow['pending']; ?></div>
            <div class="stat-label">Awaiting Review</div>
        </div>
        <div class="stat-chip green">
            <div class="stat-num"><?php echo $statsRow['approved_today']; ?></div>
            <div class="stat-label">Approved Today</div>
        </div>
        <div class="stat-chip red">
            <div class="stat-num"><?php echo $statsRow['rejected_today']; ?></div>
            <div class="stat-label">Rejected Today</div>
        </div>
    </div>

    <div class="sidebar-divider"></div>

    <!-- Nav -->
    <ul class="sidebar-nav">
        <li class="nav-item <?php echo !$singleMode ? 'active' : ''; ?>">
            <a href="company_review.php">
                <i class="fa-solid fa-inbox"></i> Pending Queue
                <?php if ($statsRow['pending'] > 0): ?>
                    <span class="nav-count"><?php echo $statsRow['pending']; ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item <?php echo $singleMode ? 'active' : ''; ?>">
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
            <a href="/admin/">
                <i class="fa-solid fa-gauge-high"></i> Dashboard
            </a>
        </li>
        <li class="nav-item">
            <a href="/auth/logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> Logout
            </a>
        </li>
    </ul>

    <div class="sidebar-footer">
        Old Union Admin &copy; <?php echo date('Y'); ?>
    </div>
</aside>

<!-- ════════════════════════════════════════
     MAIN CONTENT
════════════════════════════════════════ -->
<main class="main">

    <div class="page-header">
        <div>
            <?php if ($singleMode && !empty($pending)): ?>
                <a href="companies.php" class="page-back-link">
                    <i class="fa-solid fa-arrow-left"></i> All Companies
                </a>
                <div class="page-title" style="margin-top:.4rem;">
                    <?php echo htmlspecialchars($pending[0]['name']); ?>
                </div>
                <div class="page-subtitle">
                    Admin detail view
                    <?php
                    $sc = $pending[0];
                    $statusLabels = [
                        'draft'               => ['Draft',           '#b45309', '#fffbeb', '#fcd34d'],
                        'pending_verification'=> ['Pending Review',  '#1e4bd2', '#eef2ff', '#a5c9ff'],
                        'active'              => ['Active',          '#0b6b4d', '#e6f7ec', '#a3e0c0'],
                        'suspended'           => ['Suspended',       '#b91c1c', '#fef2f2', '#fecaca'],
                    ];
                    [$slabel, $scolor, $sbg, $sborder] = $statusLabels[$sc['status']] ?? ['Unknown','#667085','#f8f9fb','#e4e7ec'];
                    ?>
                    &nbsp;<span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .75rem;border-radius:99px;font-size:.78rem;font-weight:600;background:<?php echo $sbg; ?>;color:<?php echo $scolor; ?>;border:1px solid <?php echo $sborder; ?>;">
                        <?php echo $slabel; ?>
                        <?php if ($sc['verified']): ?>
                            <i class="fa-solid fa-badge-check" style="color:var(--emerald);"></i>
                        <?php endif; ?>
                    </span>
                </div>
            <?php else: ?>
                <div class="page-title">Company Review Queue</div>
                <div class="page-subtitle">
                    <?php echo $statsRow['pending']; ?> pending
                    <?php echo $statsRow['pending'] === 1 ? 'submission' : 'submissions'; ?>
                    awaiting verification
                </div>
            <?php endif; ?>
        </div>
        <?php if ($singleMode): ?>
            <div style="display:flex;gap:.6rem;flex-wrap:wrap;">
                <a href="company_review.php?id=<?php echo $viewId; ?>" class="refresh-btn">
                    <i class="fa-solid fa-rotate-right"></i> Refresh
                </a>
                <a href="companies.php" class="refresh-btn">
                    <i class="fa-solid fa-list"></i> All Companies
                </a>
            </div>
        <?php else: ?>
            <a href="company_review.php" class="refresh-btn">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </a>
        <?php endif; ?>
    </div>

    <!-- Toast notification -->
    <?php if (!empty($toast)): ?>
        <div class="toast <?php echo $toast['type']; ?>">
            <i class="fa-solid <?php echo $toast['type'] === 'success' ? 'fa-circle-check' : 'fa-circle-exclamation'; ?>"></i>
            <?php echo htmlspecialchars($toast['msg']); ?>
        </div>
    <?php endif; ?>

    <!-- Empty state -->
    <?php if (empty($pending)): ?>
        <div class="empty-state">
            <?php if ($singleMode): ?>
                <div class="empty-icon"><i class="fa-solid fa-building-circle-xmark"></i></div>
                <div class="empty-title">Company not found</div>
                <div class="empty-sub">No company exists with that ID.</div>
            <?php else: ?>
                <div class="empty-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div class="empty-title">Queue is clear</div>
                <div class="empty-sub">No companies are pending verification right now. Check back later.</div>
            <?php endif; ?>

    <?php else: ?>

    <div class="review-cards">
    <?php foreach ($pending as $i => $c):
        $cId        = $c['id'];
        $highlights = $highlightsCache[$cId] ?? [];
        $campaigns  = $campaignsCache[$cId]  ?? [];
        $cardId = 'card-' . $cId;
        $hasPitch = !empty($c['problem_statement']) || !empty($c['solution']) ||
                    !empty($c['business_model'])    || !empty($c['traction']) ||
                    !empty($c['target_market'])      || !empty($c['pitch_deck_url']);
        $docCount = (int)!empty($c['registration_document'])
                  + (int)!empty($c['proof_of_address'])
                  + (int)!empty($c['director_id_document'])
                  + (int)!empty($c['tax_clearance_document']);
    ?>
    <div class="review-card" id="<?php echo $cardId; ?>">

        <!-- Banner -->
        <div class="card-banner" style="<?php echo $c['banner'] ? 'background-image:url(' . htmlspecialchars($c['banner']) . ')' : ''; ?>">
            <div class="card-banner-overlay"></div>
        </div>

        <!-- Header -->
        <div class="card-header">
            <?php if ($c['logo']): ?>
                <img src="<?php echo htmlspecialchars($c['logo']); ?>" alt="Logo" class="card-logo">
            <?php else: ?>
                <div class="card-logo-placeholder">
                    <i class="fa-solid fa-building"></i>
                </div>
            <?php endif; ?>

            <div class="card-title-area">
                <div class="card-company-name"><?php echo htmlspecialchars($c['name']); ?></div>
                <div class="card-meta">
                    <?php echo typeBadge($c['type'] ?? 'other'); ?>
                    <?php if ($c['stage']): ?>
                        <span class="dot">·</span>
                        <span class="badge badge-blue"><?php echo stageLabel($c['stage']); ?></span>
                    <?php endif; ?>
                    <?php if ($c['industry']): ?>
                        <span class="dot">·</span>
                        <span><?php echo htmlspecialchars($c['industry']); ?></span>
                    <?php endif; ?>
                    <span class="dot">·</span>
                    <span class="card-wait">
                        <i class="fa-regular fa-clock"></i>
                        <?php echo timeAgo($c['created_at']); ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- Tabs -->
        <div class="card-tabs">
            <button class="tab-btn active" onclick="switchTab('<?php echo $cardId; ?>', 'overview', this)">
                <i class="fa-solid fa-circle-info"></i> Overview
            </button>
            <button class="tab-btn" onclick="switchTab('<?php echo $cardId; ?>', 'docs', this)">
                <i class="fa-solid fa-folder-open"></i> Documents
                <span class="tab-count"><?php echo $docCount; ?>/4</span>
            </button>
            <button class="tab-btn" onclick="switchTab('<?php echo $cardId; ?>', 'pitch', this)">
                <i class="fa-solid fa-bullhorn"></i> Pitch
                <?php if (!$hasPitch): ?>
                    <span class="tab-count" style="background:#fef3c7;color:#92400e;border-color:#fde68a;">empty</span>
                <?php endif; ?>
            </button>
            <button class="tab-btn" onclick="switchTab('<?php echo $cardId; ?>', 'campaigns', this)">
                <i class="fa-solid fa-rocket"></i> Campaigns
                <span class="tab-count"><?php echo (int)($c['campaign_count'] ?? count($campaigns)); ?></span>
            </button>
        </div>

        <!-- ── TAB PANELS ──────────────────────────────────── -->
        <div class="tab-panels">

            <!-- OVERVIEW -->
            <div class="tab-panel active" data-panel="<?php echo $cardId; ?>-overview">
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Reg. Number</span>
                        <span class="info-value <?php echo empty($c['registration_number']) ? 'none' : ''; ?>">
                            <?php echo $c['registration_number'] ? htmlspecialchars($c['registration_number']) : 'Not provided'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Founded</span>
                        <span class="info-value <?php echo empty($c['founded_year']) ? 'none' : ''; ?>">
                            <?php echo $c['founded_year'] ?: 'Not provided'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Team Size</span>
                        <span class="info-value <?php echo empty($c['employee_count']) ? 'none' : ''; ?>">
                            <?php echo $c['employee_count'] ? htmlspecialchars($c['employee_count']) . ' employees' : 'Not provided'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Email</span>
                        <span class="info-value <?php echo empty($c['email']) ? 'none' : ''; ?>">
                            <?php if ($c['email']): ?>
                                <a href="mailto:<?php echo htmlspecialchars($c['email']); ?>"><?php echo htmlspecialchars($c['email']); ?></a>
                            <?php else: ?>Not provided<?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Phone</span>
                        <span class="info-value <?php echo empty($c['phone']) ? 'none' : ''; ?>">
                            <?php echo $c['phone'] ? htmlspecialchars($c['phone']) : 'Not provided'; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Website</span>
                        <span class="info-value <?php echo empty($c['website']) ? 'none' : ''; ?>">
                            <?php if ($c['website']): ?>
                                <a href="<?php echo htmlspecialchars($c['website']); ?>" target="_blank">
                                    <?php echo htmlspecialchars($c['website']); ?>
                                </a>
                            <?php else: ?>Not provided<?php endif; ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Location</span>
                        <span class="info-value <?php echo empty($c['province']) ? 'none' : ''; ?>">
                            <?php
                            $loc = array_filter([$c['city'] ?? null, $c['province'] ?? null]);
                            echo $loc ? htmlspecialchars(implode(', ', $loc)) : 'Not provided';
                            ?>
                        </span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Submitted By</span>
                        <span class="info-value <?php echo empty($c['owner_email']) ? 'none' : ''; ?>">
                            <?php echo $c['owner_email'] ? htmlspecialchars($c['owner_email']) : 'Unknown'; ?>
                        </span>
                    </div>
                </div>

                <?php if (!empty($c['description'])): ?>
                    <div class="section-divider">About</div>
                    <p style="font-size:.9rem;line-height:1.7;color:var(--text);">
                        <?php echo nl2br(htmlspecialchars($c['description'])); ?>
                    </p>
                <?php endif; ?>

                <?php if (!empty($highlights)): ?>
                    <div class="section-divider">Key Highlights</div>
                    <div class="highlights-wrap">
                        <?php foreach ($highlights as $hl): ?>
                            <div class="highlight-chip">
                                <span class="hl-val"><?php echo htmlspecialchars($hl['value']); ?></span>
                                <span class="hl-lbl"><?php echo htmlspecialchars($hl['label']); ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- DOCUMENTS -->
            <div class="tab-panel" data-panel="<?php echo $cardId; ?>-docs">
                <div class="docs-grid">

                    <?php
                    $docs = [
                        ['label' => 'Registration Certificate', 'key' => 'registration_document', 'icon' => 'fa-file-certificate'],
                        ['label' => 'Proof of Address',         'key' => 'proof_of_address',        'icon' => 'fa-map-location-dot'],
                        ['label' => 'Director / CEO ID',        'key' => 'director_id_document',    'icon' => 'fa-id-card'],
                        ['label' => 'Tax Clearance',            'key' => 'tax_clearance_document',   'icon' => 'fa-receipt'],
                    ];
                    foreach ($docs as $doc):
                        $path = $c[$doc['key']] ?? null;
                    ?>
                    <div class="doc-card">
                        <div class="doc-icon <?php echo $path ? '' : 'missing'; ?>">
                            <i class="fa-solid <?php echo $doc['icon']; ?>"></i>
                        </div>
                        <div class="doc-info">
                            <div class="doc-name"><?php echo $doc['label']; ?></div>
                            <?php if ($path): ?>
                                <a href="<?php echo htmlspecialchars($path); ?>" target="_blank" class="doc-link">
                                    <i class="fa-solid fa-arrow-up-right-from-square"></i> View document
                                </a>
                            <?php else: ?>
                                <span class="doc-missing">
                                    <i class="fa-solid fa-triangle-exclamation"></i> Not uploaded
                                </span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>

                </div>

                <?php if ($c['logo'] || $c['banner']): ?>
                    <div class="section-divider">Branding Assets</div>
                    <div style="display:flex;gap:.75rem;flex-wrap:wrap;">
                        <?php if ($c['logo']): ?>
                            <a href="<?php echo htmlspecialchars($c['logo']); ?>" target="_blank" class="pitch-asset-btn">
                                <i class="fa-solid fa-image"></i> View Logo
                            </a>
                        <?php endif; ?>
                        <?php if ($c['banner']): ?>
                            <a href="<?php echo htmlspecialchars($c['banner']); ?>" target="_blank" class="pitch-asset-btn">
                                <i class="fa-solid fa-panorama"></i> View Banner
                            </a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>

            <!-- PITCH -->
            <div class="tab-panel" data-panel="<?php echo $cardId; ?>-pitch">
                <?php if (!$hasPitch): ?>
                    <div style="text-align:center;padding:2.5rem;color:var(--text-muted);">
                        <i class="fa-regular fa-file-lines" style="font-size:2rem;margin-bottom:.75rem;display:block;"></i>
                        No pitch content has been submitted yet.
                    </div>
                <?php else: ?>

                    <div class="pitch-grid">
                        <?php
                        echo pitchField('The Problem',              $c['problem_statement'],   'fa-triangle-exclamation');
                        echo pitchField('Your Solution',            $c['solution'],             'fa-lightbulb');
                        echo pitchField('Business Model',           $c['business_model'],       'fa-coins');
                        echo pitchField('Traction & Milestones',    $c['traction'],             'fa-rocket');
                        echo pitchField('Target Market',            $c['target_market'],        'fa-bullseye');
                        echo pitchField('Competitive Landscape',    $c['competitive_landscape'],'fa-chess-knight');
                        echo pitchField('The Team',                 $c['team_overview'],        'fa-people-group');
                        ?>
                    </div>

                    <?php if ($c['pitch_deck_url'] || $c['pitch_video_url']): ?>
                        <div class="section-divider">Pitch Assets</div>
                        <div class="pitch-assets">
                            <?php if ($c['pitch_deck_url']): ?>
                                <a href="<?php echo htmlspecialchars($c['pitch_deck_url']); ?>" target="_blank" class="pitch-asset-btn">
                                    <i class="fa-solid fa-file-pdf" style="color:#dc2626;"></i> Pitch Deck (PDF)
                                </a>
                            <?php endif; ?>
                            <?php if ($c['pitch_video_url']): ?>
                                <a href="<?php echo htmlspecialchars($c['pitch_video_url']); ?>" target="_blank" class="pitch-asset-btn">
                                    <i class="fa-brands fa-youtube" style="color:#dc2626;"></i> Pitch Video
                                </a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                <?php endif; // hasPitch ?>
            </div>

            <!-- CAMPAIGNS -->
            <div class="tab-panel" data-panel="<?php echo $cardId; ?>-campaigns">
                <?php if (empty($campaigns)): ?>
                    <div style="text-align:center;padding:2.5rem;color:var(--text-muted);">
                        <i class="fa-solid fa-rocket" style="font-size:2rem;margin-bottom:.75rem;display:block;opacity:.3;"></i>
                        No funding campaigns have been created yet.
                    </div>
                <?php else: ?>
                    <div class="campaigns-list">
                        <?php foreach ($campaigns as $camp):
                            $campUof = [];
                            if (!empty($camp['use_of_funds'])) {
                                $campUof = json_decode($camp['use_of_funds'], true) ?: [];
                            }
                        ?>
                            <div class="campaign-row">
                                <div style="flex:1;min-width:160px;">
                                    <div class="campaign-title"><?php echo htmlspecialchars($camp['title']); ?></div>
                                    <div class="campaign-sub">
                                        <?php echo campaignTypeBadge($camp['campaign_type']); ?>
                                        &nbsp;<?php echo campaignStatusBadge($camp['status']); ?>
                                    </div>
                                </div>
                                <div class="campaign-stats">
                                    <div class="camp-stat">
                                        <div class="camp-stat-num">R <?php echo number_format($camp['raise_target'], 0, '.', ' '); ?></div>
                                        <div class="camp-stat-label">Target</div>
                                    </div>
                                    <div class="camp-stat">
                                        <div class="camp-stat-num">R <?php echo number_format($camp['total_raised'], 0, '.', ' '); ?></div>
                                        <div class="camp-stat-label">Raised</div>
                                    </div>
                                    <div class="camp-stat">
                                        <div class="camp-stat-num"><?php echo $camp['contributor_count']; ?></div>
                                        <div class="camp-stat-label">Contributors</div>
                                    </div>
                                </div>
                                <div style="font-size:.75rem;color:var(--text-light);white-space:nowrap;">
                                    <?php if ($camp['opens_at']): ?>
                                        <?php echo date('d M Y', strtotime($camp['opens_at'])); ?> →
                                        <?php echo date('d M Y', strtotime($camp['closes_at'])); ?>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($campUof)): ?>
                                    <div style="margin-top:.85rem;border-top:1px solid var(--border);padding-top:.75rem;">
                                        <div style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.5rem;">
                                            <i class="fa-solid fa-sack-dollar" style="margin-right:.3rem;"></i>Use of Funds
                                        </div>
                                        <table class="uof-table">
                                            <thead>
                                                <tr><th>Item</th><th style="text-align:right;">Amount</th></tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($campUof as $uofRow): ?>
                                                    <tr>
                                                        <td><?php echo htmlspecialchars($uofRow['label'] ?? '—'); ?></td>
                                                        <td style="text-align:right;">R <?php echo number_format((float)($uofRow['amount'] ?? 0), 0, '.', ' '); ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td>Total</td>
                                                    <td style="text-align:right;">R <?php echo number_format(array_sum(array_column($campUof, 'amount')), 0, '.', ' '); ?></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>

        </div><!-- /.tab-panels -->

        <!-- ── ACTION PANEL ──────────────────────── -->
        <div class="action-panel">
            <div class="action-panel-inner">
            <?php
            $cStatus = $c['status'];

            // Approve / Activate — show for draft, pending, suspended
            if (in_array($cStatus, ['draft', 'pending_verification', 'suspended'])): ?>
                <form method="POST" class="approve-form"
                      onsubmit="return confirm('Approve &amp; activate <?php echo htmlspecialchars(addslashes($c['name'])); ?>?\n\nThis sets the company to Active and marks it as Verified.')">
                    <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                    <input type="hidden" name="action"     value="<?php echo $cStatus === 'suspended' ? 'activate' : 'approve'; ?>">
                    <button type="submit" class="btn-approve">
                        <i class="fa-solid fa-circle-check"></i>
                        <?php echo $cStatus === 'suspended' ? 'Reinstate &amp; Activate' : 'Approve &amp; Activate'; ?>
                    </button>
                </form>
            <?php endif; ?>

            <?php // Reject with reason — only for pending_verification
            if ($cStatus === 'pending_verification'): ?>
                <form method="POST" class="reject-form">
                    <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                    <input type="hidden" name="action"     value="reject">
                    <textarea name="rejection_reason" class="reject-textarea"
                              placeholder="Rejection reason — required. Sent to the company owner…" required></textarea>
                    <button type="submit" class="btn-reject"
                            onclick="return confirm('Reject and return this submission to draft?')">
                        <i class="fa-solid fa-circle-xmark"></i> Reject &amp; Return to Draft
                    </button>
                </form>
            <?php endif; ?>

            <?php // Suspend — only for active companies
            if ($cStatus === 'active'): ?>
                <form method="POST" class="action-form-half"
                      onsubmit="return confirm('Suspend <?php echo htmlspecialchars(addslashes($c['name'])); ?>?\n\nThis will hide the company from the public platform.')">
                    <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                    <input type="hidden" name="action"     value="suspend">
                    <button type="submit" class="btn-neutral">
                        <i class="fa-solid fa-circle-pause"></i> Suspend Company
                    </button>
                </form>
            <?php endif; ?>

            <?php // Return to Draft — for active or suspended (not pending — reject handles that)
            if (in_array($cStatus, ['active', 'suspended'])): ?>
                <form method="POST" class="action-form-half"
                      onsubmit="return confirm('Return <?php echo htmlspecialchars(addslashes($c['name'])); ?> to draft?\n\nThe owner will need to re-submit for verification.')">
                    <input type="hidden" name="company_id" value="<?php echo $cId; ?>">
                    <input type="hidden" name="action"     value="return_draft">
                    <button type="submit" class="btn-neutral">
                        <i class="fa-solid fa-rotate-left"></i> Return to Draft
                    </button>
                </form>
            <?php endif; ?>

            <?php // Draft with no action available — just info
            if ($cStatus === 'draft' && !in_array($cStatus, ['pending_verification', 'active', 'suspended'])): ?>
                <p class="action-note">
                    <i class="fa-solid fa-circle-info"></i>
                    This company is in <strong>draft</strong>. The owner has not yet submitted it for verification.
                    You can approve it directly above to skip the review step.
                </p>
            <?php endif; ?>

            </div>
        </div>

    </div><!-- /.review-card -->
    <?php endforeach; ?>
    </div><!-- /.review-cards -->

    <?php endif; // empty($pending) ?>

</main><!-- /.main -->

<script>
/* ── Tab switching ──────────────────────────── */
function switchTab(cardId, panelName, clickedBtn) {
    // Deactivate all tabs in this card
    const card = document.getElementById(cardId);
    card.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    card.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));

    // Activate clicked tab + corresponding panel
    clickedBtn.classList.add('active');
    const panel = card.querySelector('[data-panel="' + cardId + '-' + panelName + '"]');
    if (panel) panel.classList.add('active');
}

/* ── Mobile sidebar toggle ─────────────────── */
const sidebar  = document.getElementById('sidebar');
const overlay  = document.getElementById('sidebarOverlay');
const menuBtn  = document.getElementById('menuBtn');

function openSidebar() {
    sidebar.classList.add('open');
    overlay.classList.add('open');
    menuBtn.innerHTML = '<i class="fa-solid fa-xmark"></i>';
}

function closeSidebar() {
    sidebar.classList.remove('open');
    overlay.classList.remove('open');
    menuBtn.innerHTML = '<i class="fa-solid fa-bars"></i>';
}

menuBtn.addEventListener('click', () => {
    sidebar.classList.contains('open') ? closeSidebar() : openSidebar();
});

overlay.addEventListener('click', closeSidebar);

/* ── Auto-dismiss toast after 5s ───────────── */
const toast = document.querySelector('.toast');
if (toast) {
    setTimeout(() => {
        toast.style.transition = 'opacity .4s';
        toast.style.opacity = '0';
        setTimeout(() => toast.remove(), 400);
    }, 5000);

    // Scroll to top so toast is visible after form submit
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* ── Highlight active card after approve/reject */
const hash = window.location.hash;
if (hash) {
    const el = document.querySelector(hash);
    if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
</script>
</body>
</html>
