<?php
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';

if (!isLoggedIn()) {
    redirect('/auth/login.php');
}

$companyUuid  = trim($_GET['uuid'] ?? '');
$campaignUuid = trim($_GET['cid']  ?? '');

if (empty($companyUuid) || empty($campaignUuid)) {
    redirect('/company/');
}

$company = getCompanyByUuid($companyUuid);
if (!$company) { redirect('/company/'); }

requireCompanyRole($company['id'], 'editor');

$canAdmin  = hasCompanyPermission($company['id'], $_SESSION['user_id'], 'admin');
$pdo       = Database::getInstance();
$userId    = $_SESSION['user_id'];
$companyId = $company['id'];

/* ── Load campaign ───────────────────────────── */
$stmt = $pdo->prepare("
    SELECT fc.*, ct.revenue_share_percentage, ct.revenue_share_duration_months,
           ct.unit_name, ct.unit_price, ct.governing_law
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    WHERE fc.uuid = :uuid AND fc.company_id = :cid
");
$stmt->execute(['uuid' => $campaignUuid, 'cid' => $companyId]);
$campaign = $stmt->fetch();
if (!$campaign) { redirect('/company/campaigns/index.php?uuid=' . urlencode($companyUuid)); }

$campaignId = (int)$campaign['id'];

/* ── Load investment case ────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$pitch = $stmt->fetch() ?: [];
$pitchExists = !empty($pitch);

/* ── Load SPV financials ─────────────────────── */
$stmt = $pdo->prepare("
    SELECT id, period_year, period_month, revenue, cost_of_sales,
           gross_profit, operating_expenses, net_profit,
           disclosure_type, notes
    FROM campaign_financials
    WHERE campaign_id = ?
    ORDER BY period_year DESC, COALESCE(period_month, 0) DESC
");
$stmt->execute([$campaignId]);
$financials = $stmt->fetchAll();

/* ── User info ───────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';

$csrf_token = generateCSRFToken();
$errors  = [];
$success = '';
$activeTab = $_GET['tab'] ?? 'pitch'; // pitch | financials | external_invites

/* ═══════════════════════════════════════════════
   POST HANDLERS
═══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $action = trim($_POST['action'] ?? '');

        /* ─────────────────────────────────────────
           INVESTMENT CASE — save
        ───────────────────────────────────────── */
        if ($action === 'save_pitch') {
            $activeTab = 'pitch';

            $thesis  = trim($_POST['investment_thesis'] ?? '');
            $uof     = trim($_POST['use_of_funds']      ?? '');
            $risks   = trim($_POST['risk_factors']      ?? '');
            $exit    = trim($_POST['exit_strategy']     ?? '');
            $deck    = trim($_POST['pitch_deck_url']    ?? '');
            $video   = trim($_POST['pitch_video_url']   ?? '');

            if ($deck !== '' && !filter_var($deck, FILTER_VALIDATE_URL)) {
                $errors[] = 'Pitch deck URL is not a valid URL.';
            }
            if ($video !== '' && !filter_var($video, FILTER_VALIDATE_URL)) {
                $errors[] = 'Pitch video URL is not a valid URL.';
            }

            if (empty($errors)) {
                if ($pitchExists) {
                    $pdo->prepare("
                        UPDATE campaign_pitch SET
                            investment_thesis = :thesis,
                            use_of_funds      = :uof,
                            risk_factors      = :risks,
                            exit_strategy     = :exit,
                            pitch_deck_url    = :deck,
                            pitch_video_url   = :video,
                            updated_at        = NOW()
                        WHERE campaign_id = :cid
                    ")->execute([
                        'thesis' => $thesis ?: null,
                        'uof'    => $uof    ?: null,
                        'risks'  => $risks  ?: null,
                        'exit'   => $exit   ?: null,
                        'deck'   => $deck   ?: null,
                        'video'  => $video  ?: null,
                        'cid'    => $campaignId,
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO campaign_pitch
                            (campaign_id, investment_thesis, use_of_funds, risk_factors,
                             exit_strategy, pitch_deck_url, pitch_video_url)
                        VALUES
                            (:cid, :thesis, :uof, :risks, :exit, :deck, :video)
                    ")->execute([
                        'cid'    => $campaignId,
                        'thesis' => $thesis ?: null,
                        'uof'    => $uof    ?: null,
                        'risks'  => $risks  ?: null,
                        'exit'   => $exit   ?: null,
                        'deck'   => $deck   ?: null,
                        'video'  => $video  ?: null,
                    ]);
                    $pitchExists = true;
                }
                // Reload
                $stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
                $stmt->execute([$campaignId]);
                $pitch = $stmt->fetch() ?: [];
                logCompanyActivity($companyId, $userId, 'Updated investment case for campaign: ' . $campaign['title']);
                $success = 'Investment case saved.';
            }

        /* ─────────────────────────────────────────
           SPV FINANCIALS — delete
        ───────────────────────────────────────── */
        } elseif ($action === 'delete_fin') {
            $activeTab = 'financials';
            $deleteId  = (int)($_POST['record_id'] ?? 0);
            if ($deleteId > 0) {
                $pdo->prepare("DELETE FROM campaign_financials WHERE id = :id AND campaign_id = :cid")
                    ->execute(['id' => $deleteId, 'cid' => $campaignId]);
                logCompanyActivity($companyId, $userId, 'Deleted SPV financial record #' . $deleteId);
                $success = 'Financial record deleted.';
            }

        /* ─────────────────────────────────────────
           SPV FINANCIALS — add or edit
        ───────────────────────────────────────── */
        } elseif (in_array($action, ['add_fin', 'edit_fin'], true)) {
            $activeTab   = 'financials';
            $editId      = (int)($_POST['record_id'] ?? 0);
            $periodYear  = trim($_POST['period_year']  ?? '');
            $periodMonth = trim($_POST['period_month'] ?? '');
            $periodMonthVal = ($periodMonth === '') ? null : (int)$periodMonth;

            if ($periodYear === '' || !ctype_digit($periodYear)
                || (int)$periodYear < 2000 || (int)$periodYear > (int)date('Y') + 1) {
                $errors[] = 'Please enter a valid year (2000–' . (date('Y') + 1) . ').';
            }
            if ($periodMonthVal !== null && ($periodMonthVal < 1 || $periodMonthVal > 12)) {
                $errors[] = 'Month must be between 1 and 12.';
            }

            $numFields = ['revenue', 'cost_of_sales', 'gross_profit', 'operating_expenses', 'net_profit'];
            $nums = [];
            foreach ($numFields as $f) {
                $raw = trim($_POST[$f] ?? '');
                if ($raw === '') {
                    $nums[$f] = null;
                } elseif (!is_numeric($raw)) {
                    $errors[] = ucfirst(str_replace('_', ' ', $f)) . ' must be a number.';
                    $nums[$f] = null;
                } else {
                    $nums[$f] = (float)$raw;
                }
            }

            $disclosureType = trim($_POST['disclosure_type'] ?? 'self_reported');
            $validDisclosure = ['self_reported', 'accountant_verified', 'audited'];
            if (!in_array($disclosureType, $validDisclosure, true)) {
                $disclosureType = 'self_reported';
            }
            $notes = trim($_POST['notes'] ?? '') ?: null;

            if (empty($errors)) {
                if ($action === 'edit_fin' && $editId > 0) {
                    $pdo->prepare("
                        UPDATE campaign_financials SET
                            period_year          = :yr,
                            period_month         = :mo,
                            revenue              = :rev,
                            cost_of_sales        = :cos,
                            gross_profit         = :gp,
                            operating_expenses   = :opex,
                            net_profit           = :np,
                            disclosure_type      = :dt,
                            notes                = :notes,
                            updated_at           = NOW()
                        WHERE id = :id AND campaign_id = :cid
                    ")->execute([
                        'yr' => (int)$periodYear, 'mo' => $periodMonthVal,
                        'rev' => $nums['revenue'], 'cos' => $nums['cost_of_sales'],
                        'gp'  => $nums['gross_profit'], 'opex' => $nums['operating_expenses'],
                        'np'  => $nums['net_profit'], 'dt' => $disclosureType,
                        'notes' => $notes, 'id' => $editId, 'cid' => $campaignId,
                    ]);
                    $success = 'Financial record updated.';
                } else {
                    try {
                        $pdo->prepare("
                            INSERT INTO campaign_financials
                                (campaign_id, period_year, period_month, revenue, cost_of_sales,
                                 gross_profit, operating_expenses, net_profit, disclosure_type, notes)
                            VALUES
                                (:cid, :yr, :mo, :rev, :cos, :gp, :opex, :np, :dt, :notes)
                        ")->execute([
                            'cid' => $campaignId, 'yr' => (int)$periodYear, 'mo' => $periodMonthVal,
                            'rev' => $nums['revenue'], 'cos' => $nums['cost_of_sales'],
                            'gp'  => $nums['gross_profit'], 'opex' => $nums['operating_expenses'],
                            'np'  => $nums['net_profit'], 'dt' => $disclosureType,
                            'notes' => $notes,
                        ]);
                        $success = 'Financial record added.';
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') {
                            $errors[] = 'A record for this period already exists. Use Edit to update it.';
                        } else {
                            throw $e;
                        }
                    }
                }
                // Only log and reload if no error was set inside the try-catch
                if (empty($errors)) {
                    logCompanyActivity($companyId, $userId, ($action === 'edit_fin' ? 'Updated' : 'Added') . ' SPV financial record for: ' . $campaign['title']);
                    $stmt = $pdo->prepare("SELECT id, period_year, period_month, revenue, cost_of_sales, gross_profit, operating_expenses, net_profit, disclosure_type, notes FROM campaign_financials WHERE campaign_id = ? ORDER BY period_year DESC, COALESCE(period_month,0) DESC");
                    $stmt->execute([$campaignId]);
                    $financials = $stmt->fetchAll();
                } else {
                    $success = ''; // clear any partial success message
                }
            }
        }
    }
}

/* ── Helpers ─────────────────────────────────── */
function mFmt($v) {
    if ($v === null || $v === '') return '';
    return number_format((float)$v, 2, '.', '');
}
function mDisp($v) {
    if ($v === null || $v === '') return '—';
    return 'R ' . number_format((float)$v, 0, '.', ' ');
}
function mDate($v) { return $v ? date('d M Y', strtotime($v)) : '—'; }

$monthNames = [1=>'Jan',2=>'Feb',3=>'Mar',4=>'Apr',5=>'May',6=>'Jun',
               7=>'Jul',8=>'Aug',9=>'Sep',10=>'Oct',11=>'Nov',12=>'Dec'];

$statusConfig = [
    'draft'               => ['Draft',         'cs-draft',    'fa-pencil'],
    'under_review'        => ['Under Review',  'cs-review',   'fa-clock'],
    'approved'            => ['Approved',      'cs-approved', 'fa-circle-check'],
    'open'                => ['Open',          'cs-open',     'fa-rocket'],
    'funded'              => ['Funded',        'cs-funded',   'fa-trophy'],
    'closed_successful'   => ['Closed — Success', 'cs-success','fa-flag-checkered'],
    'closed_unsuccessful' => ['Closed — Failed',  'cs-failed', 'fa-xmark-circle'],
    'suspended'           => ['Suspended',     'cs-suspended','fa-pause'],
];
$sInfo = $statusConfig[$campaign['status']] ?? ['Unknown', 'cs-draft', 'fa-question'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Campaign | <?php echo htmlspecialchars($company['name']); ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&family=Playfair+Display:wght@600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
/* Header */
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.logo-container{display:flex;align-items:center;}
.logo{font-family:'Playfair Display',serif;font-weight:600;font-size:18px;color:#333;text-decoration:none;text-transform:uppercase;display:inline-block;}
.logo span{display:inline-block;}.logo .second{color:#c8102e;font-family:'Playfair Display',serif;}
.logo .first,.logo .big{font-size:1.4rem;line-height:0.8;vertical-align:baseline;font-family:'Playfair Display',serif;}
.header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
.header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);white-space:nowrap;}
.header-nav a:hover{background:var(--surface-2);color:var(--text);}
.header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;cursor:pointer;}
/* Layout */
.page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
.sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
.sidebar-section-label:first-child{margin-top:0;}
.sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
.sidebar a:hover{background:var(--surface-2);color:var(--text);}
.sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
.sidebar a i{width:16px;text-align:center;font-size:.85rem;}
.main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
/* Breadcrumb */
.breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:1.25rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
.breadcrumb a{color:var(--navy-light);text-decoration:none;}.breadcrumb a:hover{color:var(--navy);}
.breadcrumb i{font-size:.65rem;color:var(--text-light);}
/* Page head */
.page-head{margin-bottom:1.5rem;}
.page-head-row{display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap;}
.page-head h1{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--navy);line-height:1.2;margin-bottom:.25rem;}
.page-head-sub{font-size:.87rem;color:var(--text-muted);}
.btn{display:inline-flex;align-items:center;gap:.45rem;padding:.55rem 1.2rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
.btn-primary{background:var(--navy-mid);color:#fff;}.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.btn-outline{background:var(--surface);color:var(--text-muted);border:1.5px solid var(--border);}.btn-outline:hover{border-color:var(--navy-light);color:var(--navy-mid);}
.btn-danger{background:var(--error-bg);color:var(--error);border:1px solid var(--error-bdr);}.btn-danger:hover{background:var(--error);color:#fff;}
.btn-amber{background:var(--amber);color:var(--navy);border:none;}.btn-amber:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
.btn-sm{padding:.35rem .85rem;font-size:.79rem;}
/* Status badge */
.status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .75rem;border-radius:99px;font-size:.77rem;font-weight:600;border:1px solid transparent;}
.cs-draft    {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
.cs-review   {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.cs-approved {background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
.cs-open     {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-funded   {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-success  {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-failed   {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.cs-suspended{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
/* Tabs */
.page-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:1.75rem;}
.page-tab{display:flex;align-items:center;gap:.4rem;padding:.8rem 1.25rem;font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:all var(--transition);white-space:nowrap;}
.page-tab:hover{color:var(--navy);}
.page-tab.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
/* Cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.5rem;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border);}
.card-title{display:flex;align-items:center;gap:.5rem;font-size:.83rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;}
.card-title i{color:var(--navy-light);}
.card-body{padding:1.25rem;}
/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
.field{display:flex;flex-direction:column;gap:.4rem;}
.field.span-2{grid-column:span 2;}
.field label{font-size:.82rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.35rem;}
.field label i{color:var(--navy-light);font-size:.78rem;}
.field input,.field select,.field textarea{width:100%;padding:.65rem .85rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.08);}
.field textarea{resize:vertical;min-height:100px;line-height:1.6;}
.hint{font-size:.75rem;color:var(--text-light);}
.req{color:var(--error);}
/* Alerts */
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.alert-error  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
/* Financials table */
.fin-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.fin-table th{text-align:left;padding:.55rem .75rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.fin-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.fin-table tr:last-child td{border-bottom:none;}.fin-table tr:hover td{background:#fafbfc;}
.fin-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
.neg{color:var(--error);}
.badge{display:inline-flex;align-items:center;gap:.22rem;padding:.17rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
.dl-self {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.dl-acct {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
/* Add form panel */
.add-form-panel{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1.25rem;margin-top:1.25rem;}
.add-form-panel-head{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
/* Campaign info strip */
.campaign-info-strip{background:var(--navy);border-radius:var(--radius-sm);padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;}
.ci-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;flex:1;}
.ci-meta{font-size:.78rem;color:rgba(255,255,255,.55);margin-top:.15rem;}
/* Edit row inline */
.edit-row td{background:#fffbeb!important;}
/* Responsive */
@media(max-width:1024px){.header-nav{display:none;}.main-content{padding:1.5rem;}}
@media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}}
@media(max-width:640px){.form-grid{grid-template-columns:1fr;}.field.span-2{grid-column:span 1;}}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<header class="top-header">
    <div class="logo-container">
        <a href="/company/" class="logo"><span class="first">O</span>LD<span class="second"><span class="big">U</span>NION</span></a>
    </div>
    <nav class="header-nav">
        <a href="/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-gauge"></i> Dashboard
        </a>
        <a href="/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>" class="active">
            <i class="fa-solid fa-rocket"></i> Campaigns
        </a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">

    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-gauge"></i> Overview
        </a>
        <?php if ($canAdmin): ?>
        <a href="/company/wizard.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-pen-to-square"></i> Edit Profile
        </a>
        <?php endif; ?>
        <div class="sidebar-section-label">Content</div>
        <a href="/company/financials.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-chart-bar"></i> Financials
        </a>
        <a href="/company/milestones.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-trophy"></i> Milestones
        </a>
        <div class="sidebar-section-label">Fundraising</div>
        <a href="/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>" class="active">
            <i class="fa-solid fa-rocket"></i> Campaigns
        </a>
        <!-- US-102 -->
        <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-users"></i> Investor Directory
        </a>
        <!-- US-103 -->
        <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-envelope-open-text"></i> External Invites
        </a>
        <div class="sidebar-section-label">Team</div>
        <a href="/company/manage_admins.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-users"></i> Manage Team
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
                <i class="fa-solid fa-gauge"></i> <?php echo htmlspecialchars($company['name']); ?>
            </a>
            <i class="fa-solid fa-chevron-right"></i>
            <a href="/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>">Campaigns</a>
            <i class="fa-solid fa-chevron-right"></i>
            <?php echo htmlspecialchars($campaign['title']); ?>
        </div>

        <!-- Campaign info strip -->
        <div class="campaign-info-strip">
            <div style="flex:1;">
                <div class="ci-title"><?php echo htmlspecialchars($campaign['title']); ?></div>
                <div class="ci-meta">
                    UUID: <?php echo htmlspecialchars($campaign['uuid']); ?>
                    &nbsp;·&nbsp;
                    Closes <?php echo mDate($campaign['closes_at']); ?>
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
                <span class="status-badge <?php echo $sInfo[1]; ?>">
                    <i class="fa-solid <?php echo $sInfo[2]; ?>"></i> <?php echo $sInfo[0]; ?>
                </span>
                <?php if ($campaign['status'] === 'draft'): ?>
                <a href="/company/campaigns/wizard.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
                   class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-pen"></i> Edit in Wizard
                </a>
                <?php endif; ?>
                <a href="/app/discover/campaign.php?cid=<?php echo urlencode($campaignUuid); ?>"
                   target="_blank" class="btn btn-outline btn-sm">
                    <i class="fa-solid fa-arrow-up-right-from-square"></i> Public View
                </a>
                <!-- US-102: Find Investors CTA -->
                <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
                   class="btn btn-amber btn-sm">
                    <i class="fa-solid fa-users"></i> Find Investors
                </a>
            </div>
        </div>

        <!-- Page head -->
        <div class="page-head">
            <h1>Manage Campaign</h1>
            <p class="page-head-sub">Edit the investment case and manage SPV financials for this campaign.</p>
        </div>

        <!-- Alerts -->
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><?php echo implode('<br>', array_map('htmlspecialchars', $errors)); ?></div>
        </div>
        <?php endif; ?>
        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($success); ?>
        </div>
        <?php endif; ?>

        <!-- Tabs -->
        <div class="page-tabs">
            <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=pitch"
               class="page-tab <?php echo $activeTab === 'pitch' ? 'active' : ''; ?>">
                <i class="fa-solid fa-briefcase"></i> Investment Case
            </a>
            <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=financials"
               class="page-tab <?php echo $activeTab === 'financials' ? 'active' : ''; ?>">
                <i class="fa-solid fa-chart-bar"></i> SPV Financials
                <?php if (!empty($financials)): ?>
                    <span style="background:var(--navy-mid);color:#fff;border-radius:99px;font-size:.67rem;padding:.05rem .45rem;font-weight:700;"><?php echo count($financials); ?></span>
                <?php endif; ?>
            </a>
            <!-- US-102: Investor Directory tab -->
            <?php
            // Pending invite count for badge — gracefully skips if table not yet migrated
            $pendingInviteCount = 0;
            try {
                $s = $pdo->prepare("SELECT COUNT(*) FROM campaign_invites WHERE campaign_id = ? AND status = 'pending'");
                $s->execute([$campaignId]);
                $pendingInviteCount = (int)$s->fetchColumn();
            } catch (PDOException $e) { /* table may not exist during migration */ }
            ?>
            <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
               class="page-tab">
                <i class="fa-solid fa-users"></i> Investor Directory
                <?php if ($pendingInviteCount > 0): ?>
                    <span style="background:var(--amber);color:var(--navy);border-radius:99px;font-size:.67rem;padding:.05rem .45rem;font-weight:700;"><?php echo $pendingInviteCount; ?></span>
                <?php endif; ?>
            </a>
            <!-- US-103: External Invites tab -->
            <?php
            $externalInviteCount = 0;
            try {
                $s = $pdo->prepare("
                    SELECT COUNT(*) FROM campaign_invites
                    WHERE campaign_id = ? AND invite_source = 'external_email'
                ");
                $s->execute([$campaignId]);
                $externalInviteCount = (int)$s->fetchColumn();
            } catch (PDOException $e) { /* table may not exist during migration */ }
            ?>
            <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=external_invites"
               class="page-tab <?php echo $activeTab === 'external_invites' ? 'active' : ''; ?>">
                <i class="fa-solid fa-envelope-open-text"></i> External Invites
                <?php if ($externalInviteCount > 0): ?>
                    <span style="background:var(--navy-mid);color:#fff;border-radius:99px;font-size:.67rem;padding:.05rem .45rem;font-weight:700;"><?php echo $externalInviteCount; ?></span>
                <?php endif; ?>
            </a>
        </div>

        <?php if ($activeTab === 'external_invites'): ?>
        <!-- ══════════════════════════════════════
             EXTERNAL INVITES TAB  (US-103)
             Shows a summary list of all external email invites for this
             campaign, with status and expiry, plus a CTA to the full
             management page (external_invite.php) for sending new ones.
        ══════════════════════════════════════ -->
        <?php
        $externalRows = [];
        try {
            $s = $pdo->prepare("
                SELECT guest_email, status, expires_at, created_at, re_requested_at
                FROM campaign_invites
                WHERE campaign_id    = ?
                  AND invite_source  = 'external_email'
                ORDER BY created_at DESC
                LIMIT 50
            ");
            $s->execute([$campaignId]);
            $externalRows = $s->fetchAll();
        } catch (PDOException $e) { /* table may not exist yet */ }

        function extStatusBadge(string $status, string $expiresAt): string {
            $exp = strtotime($expiresAt) < time();
            if ($status === 'accepted') return '<span class="badge badge-accepted"><i class="fa-solid fa-circle-check"></i> Accepted</span>';
            if ($status === 'declined') return '<span class="badge badge-declined"><i class="fa-solid fa-circle-xmark"></i> Declined</span>';
            if ($status === 'revoked')  return '<span class="badge badge-revoked"><i class="fa-solid fa-ban"></i> Revoked</span>';
            if ($exp)                  return '<span class="badge badge-expired"><i class="fa-regular fa-clock"></i> Expired</span>';
            return '<span class="badge badge-pending"><i class="fa-solid fa-paper-plane"></i> Sent</span>';
        }
        ?>
        <style>
        .badge{display:inline-flex;align-items:center;gap:.25rem;padding:.17rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
        .badge-accepted{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
        .badge-declined{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
        .badge-revoked {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
        .badge-expired {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
        .badge-pending {background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
        .ext-table{width:100%;border-collapse:collapse;font-size:.84rem;}
        .ext-table th{text-align:left;padding:.55rem .75rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
        .ext-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
        .ext-table tr:last-child td{border-bottom:none;}
        .ext-table tr:hover td{background:#fafbfc;}
        .ext-expired td{opacity:.6;}
        </style>

        <div class="card">
            <div class="card-header" style="justify-content:space-between;">
                <span class="card-title"><i class="fa-solid fa-list-check"></i> External Invitations</span>
                <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
                   class="btn btn-amber btn-sm">
                    <i class="fa-solid fa-paper-plane"></i> Send New Invite
                </a>
            </div>
            <div class="card-body">
                <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.6;">
                    Invitations sent to email addresses not yet registered on Old Union. Their
                    accounts are automatically linked to this campaign when they verify.
                    Use the <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>" style="color:var(--navy-light);font-weight:600;">External Invites</a>
                    page to send new links, resend expired ones, or revoke outstanding invites.
                </p>
                <?php if (empty($externalRows)): ?>
                    <div style="text-align:center;padding:2.5rem 1rem;color:var(--text-light);">
                        <i class="fa-solid fa-envelope" style="font-size:1.75rem;display:block;margin-bottom:.75rem;opacity:.4;"></i>
                        <div style="font-size:.88rem;font-weight:600;margin-bottom:.3rem;">No external invitations sent yet.</div>
                        <div style="font-size:.78rem;">Use the button above to invite someone who isn't on Old Union.</div>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="ext-table">
                            <thead>
                                <tr>
                                    <th>Email</th>
                                    <th>Status</th>
                                    <th>Sent</th>
                                    <th>Expires</th>
                                    <th>Re-requested</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($externalRows as $er):
                                $isExpired = strtotime($er['expires_at']) < time();
                            ?>
                            <tr class="<?php echo ($isExpired && $er['status'] === 'pending') ? 'ext-expired' : ''; ?>">
                                <td style="font-weight:500;"><?php echo htmlspecialchars($er['guest_email']); ?></td>
                                <td><?php echo extStatusBadge($er['status'], $er['expires_at']); ?></td>
                                <td style="color:var(--text-muted);white-space:nowrap;"><?php echo date('d M Y', strtotime($er['created_at'])); ?></td>
                                <td style="color:<?php echo $isExpired ? 'var(--error)' : 'var(--text-muted)'; ?>;white-space:nowrap;">
                                    <?php echo date('d M H:i', strtotime($er['expires_at'])); ?>
                                </td>
                                <td style="color:var(--text-muted);">
                                    <?php echo $er['re_requested_at']
                                        ? date('d M Y', strtotime($er['re_requested_at']))
                                        : '—'; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <div style="margin-top:1rem;text-align:right;">
                        <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
                           class="btn btn-outline btn-sm">
                            <i class="fa-solid fa-arrow-up-right-from-square"></i> Full Invite Management →
                        </a>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <?php endif; ?>

        <?php if ($activeTab === 'pitch'): ?>
        <!-- ══════════════════════════════════════
             INVESTMENT CASE TAB
        ══════════════════════════════════════ -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-briefcase"></i> Investment Case</span>
            </div>
            <div class="card-body">
                <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:1.25rem;line-height:1.6;">
                    The investment case is specific to this SPV campaign. It tells investors why this particular raise is compelling, how the money will be used, and what the risks are. All fields are optional but recommended.
                </p>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action"     value="save_pitch">
                    <div class="form-grid">

                        <div class="field span-2">
                            <label for="investment_thesis">
                                <i class="fa-solid fa-lightbulb"></i> Investment Thesis
                            </label>
                            <textarea id="investment_thesis" name="investment_thesis"
                                      rows="5" maxlength="3000"
                                      placeholder="Why should an investor back this specific SPV? What is the core value proposition and opportunity?"><?php echo htmlspecialchars($pitch['investment_thesis'] ?? ''); ?></textarea>
                            <span class="hint">Describe the strategic rationale for this raise — what makes this SPV compelling.</span>
                        </div>

                        <div class="field span-2">
                            <label for="use_of_funds">
                                <i class="fa-solid fa-sack-dollar"></i> Use of Funds
                            </label>
                            <textarea id="use_of_funds" name="use_of_funds"
                                      rows="4" maxlength="2000"
                                      placeholder="e.g. 40% equipment, 35% working capital, 15% marketing, 10% legal & admin"><?php echo htmlspecialchars($pitch['use_of_funds'] ?? ''); ?></textarea>
                            <span class="hint">Specific to this raise — how will the capital raised in this campaign be deployed?</span>
                        </div>

                        <div class="field span-2">
                            <label for="risk_factors">
                                <i class="fa-solid fa-shield-halved"></i> Risk Factors
                            </label>
                            <textarea id="risk_factors" name="risk_factors"
                                      rows="4" maxlength="2000"
                                      placeholder="List the key risks relevant to this SPV and how you are mitigating them…"><?php echo htmlspecialchars($pitch['risk_factors'] ?? ''); ?></textarea>
                            <span class="hint">Mandatory honest disclosure builds investor trust. Include mitigants where applicable.</span>
                        </div>

                        <div class="field span-2">
                            <label for="exit_strategy">
                                <i class="fa-solid fa-door-open"></i> Exit / Return Strategy
                            </label>
                            <textarea id="exit_strategy" name="exit_strategy"
                                      rows="3" maxlength="2000"
                                      placeholder="How and when do investors realise their returns? e.g. Monthly revenue share for 36 months, then agreement expires."><?php echo htmlspecialchars($pitch['exit_strategy'] ?? ''); ?></textarea>
                        </div>

                        <div class="field">
                            <label for="pitch_deck_url">
                                <i class="fa-solid fa-file-pdf"></i> Pitch Deck URL
                            </label>
                            <input type="url" id="pitch_deck_url" name="pitch_deck_url"
                                   value="<?php echo htmlspecialchars($pitch['pitch_deck_url'] ?? ''); ?>"
                                   placeholder="https://drive.google.com/…">
                            <span class="hint">Link to a PDF on Google Drive, Dropbox, or similar. Must be publicly accessible.</span>
                        </div>

                        <div class="field">
                            <label for="pitch_video_url">
                                <i class="fa-brands fa-youtube"></i> Pitch Video URL
                            </label>
                            <input type="url" id="pitch_video_url" name="pitch_video_url"
                                   value="<?php echo htmlspecialchars($pitch['pitch_video_url'] ?? ''); ?>"
                                   placeholder="https://youtu.be/…">
                            <span class="hint">YouTube or Vimeo link to your pitch video.</span>
                        </div>

                    </div>
                    <div style="margin-top:1.5rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i> Save Investment Case
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php else: ?>
        <!-- ══════════════════════════════════════
             SPV FINANCIALS TAB
        ══════════════════════════════════════ -->

        <!-- Existing records -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-chart-bar"></i> SPV Financial Records</span>
                <span style="font-size:.78rem;color:var(--text-light);"><?php echo count($financials); ?> record<?php echo count($financials) !== 1 ? 's' : ''; ?></span>
            </div>
            <div class="card-body" style="padding:0;">
                <?php if (empty($financials)): ?>
                <div style="padding:2.5rem;text-align:center;font-size:.88rem;color:var(--text-light);">
                    <i class="fa-solid fa-chart-bar" style="font-size:1.75rem;display:block;margin-bottom:.6rem;opacity:.3;"></i>
                    No financial records yet. Add your first record below.
                </div>
                <?php else: ?>
                <div style="overflow-x:auto;">
                    <table class="fin-table">
                        <thead><tr>
                            <th>Period</th>
                            <th style="text-align:right;">Revenue</th>
                            <th style="text-align:right;">Gross Profit</th>
                            <th style="text-align:right;">Net Profit</th>
                            <th>Disclosure</th>
                            <th style="width:100px;"></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ($financials as $fin):
                            $pl  = $fin['period_month']
                                ? ($monthNames[(int)$fin['period_month']] . ' ' . $fin['period_year'])
                                : ($fin['period_year'] . ' Annual');
                            $dlMap = ['self_reported'=>['Self-Reported','dl-self'],'accountant_verified'=>['Accountant Verified','dl-acct'],'audited'=>['Audited','dl-audit']];
                            $dl = $dlMap[$fin['disclosure_type']] ?? ['Self-Reported','dl-self'];
                            $isEditing = isset($_GET['edit']) && (int)$_GET['edit'] === (int)$fin['id'];
                        ?>
                        <?php if ($isEditing): ?>
                        <tr class="edit-row">
                            <td colspan="6" style="padding:1rem 1.25rem;">
                                <form method="POST" style="display:contents;">
                                    <input type="hidden" name="csrf_token"  value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="action"      value="edit_fin">
                                    <input type="hidden" name="record_id"   value="<?php echo (int)$fin['id']; ?>">
                                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.85rem;margin-bottom:.85rem;">
                                        <div class="field">
                                            <label>Year <span class="req">*</span></label>
                                            <input type="number" name="period_year" value="<?php echo (int)$fin['period_year']; ?>" min="2000" max="<?php echo date('Y')+1; ?>" required>
                                        </div>
                                        <div class="field">
                                            <label>Month</label>
                                            <select name="period_month">
                                                <option value="">Annual</option>
                                                <?php for ($m=1;$m<=12;$m++): ?>
                                                <option value="<?php echo $m; ?>" <?php echo ((int)$fin['period_month'] === $m) ? 'selected' : ''; ?>>
                                                    <?php echo $monthNames[$m]; ?>
                                                </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="field">
                                            <label>Revenue</label>
                                            <input type="number" name="revenue" step="0.01" value="<?php echo mFmt($fin['revenue']); ?>" placeholder="0.00">
                                        </div>
                                        <div class="field">
                                            <label>Gross Profit</label>
                                            <input type="number" name="gross_profit" step="0.01" value="<?php echo mFmt($fin['gross_profit']); ?>" placeholder="0.00">
                                        </div>
                                        <div class="field">
                                            <label>Net Profit</label>
                                            <input type="number" name="net_profit" step="0.01" value="<?php echo mFmt($fin['net_profit']); ?>" placeholder="0.00">
                                        </div>
                                        <div class="field">
                                            <label>Disclosure</label>
                                            <select name="disclosure_type">
                                                <option value="self_reported"       <?php echo $fin['disclosure_type']==='self_reported'?'selected':''; ?>>Self-Reported</option>
                                                <option value="accountant_verified" <?php echo $fin['disclosure_type']==='accountant_verified'?'selected':''; ?>>Accountant Verified</option>
                                                <option value="audited"             <?php echo $fin['disclosure_type']==='audited'?'selected':''; ?>>Audited</option>
                                            </select>
                                        </div>
                                        <div class="field" style="grid-column:1/-1;">
                                            <label>Notes</label>
                                            <input type="text" name="notes" value="<?php echo htmlspecialchars($fin['notes'] ?? ''); ?>" placeholder="Optional note…" maxlength="500">
                                        </div>
                                    </div>
                                    <div style="display:flex;gap:.5rem;">
                                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                                        <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=financials" class="btn btn-outline btn-sm">Cancel</a>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php else: ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($pl); ?></strong>
                                <?php if (!empty($fin['notes'])): ?>
                                    <br><span style="font-size:.74rem;color:var(--text-light);"><?php echo htmlspecialchars($fin['notes']); ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?php echo mDisp($fin['revenue']); ?></td>
                            <td class="num"><?php echo mDisp($fin['gross_profit']); ?></td>
                            <td class="num <?php echo ($fin['net_profit'] !== null && $fin['net_profit'] < 0) ? 'neg' : ''; ?>">
                                <?php echo mDisp($fin['net_profit']); ?>
                            </td>
                            <td><span class="badge <?php echo $dl[1]; ?>"><?php echo $dl[0]; ?></span></td>
                            <td style="text-align:right;">
                                <div style="display:flex;gap:.4rem;justify-content:flex-end;">
                                    <a href="?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=financials&edit=<?php echo (int)$fin['id']; ?>"
                                       class="btn btn-outline btn-sm">
                                        <i class="fa-solid fa-pen"></i>
                                    </a>
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this financial record?')">
                                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                        <input type="hidden" name="action"    value="delete_fin">
                                        <input type="hidden" name="record_id" value="<?php echo (int)$fin['id']; ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">
                                            <i class="fa-solid fa-trash"></i>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Add new record -->
        <?php if (!isset($_GET['edit'])): ?>
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-plus"></i> Add Financial Record</span>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action"     value="add_fin">
                    <div class="form-grid">
                        <div class="field">
                            <label for="period_year">Year <span class="req">*</span></label>
                            <input type="number" id="period_year" name="period_year"
                                   value="<?php echo date('Y'); ?>"
                                   min="2000" max="<?php echo date('Y') + 1; ?>" required>
                        </div>
                        <div class="field">
                            <label for="period_month">Month</label>
                            <select id="period_month" name="period_month">
                                <option value="">Annual Summary</option>
                                <?php for ($m=1;$m<=12;$m++): ?>
                                <option value="<?php echo $m; ?>"><?php echo $monthNames[$m]; ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="revenue"><i class="fa-solid fa-arrow-trend-up"></i> Revenue</label>
                            <input type="number" id="revenue" name="revenue" step="0.01" placeholder="0.00">
                        </div>
                        <div class="field">
                            <label for="cost_of_sales">Cost of Sales</label>
                            <input type="number" id="cost_of_sales" name="cost_of_sales" step="0.01" placeholder="0.00">
                        </div>
                        <div class="field">
                            <label for="gross_profit">Gross Profit</label>
                            <input type="number" id="gross_profit" name="gross_profit" step="0.01" placeholder="0.00">
                        </div>
                        <div class="field">
                            <label for="operating_expenses">Operating Expenses</label>
                            <input type="number" id="operating_expenses" name="operating_expenses" step="0.01" placeholder="0.00">
                        </div>
                        <div class="field">
                            <label for="net_profit">Net Profit / Loss</label>
                            <input type="number" id="net_profit" name="net_profit" step="0.01" placeholder="0.00">
                        </div>
                        <div class="field">
                            <label for="disclosure_type">Disclosure Type</label>
                            <select id="disclosure_type" name="disclosure_type">
                                <option value="self_reported">Self-Reported</option>
                                <option value="accountant_verified">Accountant Verified</option>
                                <option value="audited">Audited</option>
                            </select>
                        </div>
                        <div class="field span-2">
                            <label for="notes">Notes</label>
                            <input type="text" id="notes" name="notes" placeholder="Optional note about this period…" maxlength="500">
                        </div>
                    </div>
                    <div style="margin-top:1.25rem;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-plus"></i> Add Record
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

        <?php endif; ?>

    </main>
</div>
</body>
</html>