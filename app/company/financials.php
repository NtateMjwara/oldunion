<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/company_functions.php';
require_once '../includes/company_uploads.php';
require_once '../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$uuid = $_GET['uuid'] ?? '';
if (empty($uuid)) { redirect('/app/company/'); }

$company = getCompanyByUuid($uuid);
if (!$company) { redirect('/app/company/'); }

requireCompanyRole($company['id'], 'viewer');

$canEdit   = hasCompanyPermission($company['id'], $_SESSION['user_id'], 'editor');
$pdo       = Database::getInstance();
$userId    = $_SESSION['user_id'];
$companyId = $company['id'];

$csrf_token = generateCSRFToken();
$errors  = [];
$success = '';

/* ═══════════════════════════════════════
   POST HANDLER
═══════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canEdit) {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $action = $_POST['action'] ?? '';

        /* ── DELETE ─────────────────────────────── */
        if ($action === 'delete') {
            $deleteId = (int)($_POST['record_id'] ?? 0);
            if ($deleteId > 0) {
                $stmt = $pdo->prepare("
                    DELETE FROM company_financials
                    WHERE id = :id AND company_id = :cid
                ");
                $stmt->execute(['id' => $deleteId, 'cid' => $companyId]);
                logCompanyActivity($companyId, $userId, 'Deleted financial report #' . $deleteId);
                $success = 'Financial report deleted.';
            }

        /* ── ADD or EDIT ────────────────────────── */
        } elseif (in_array($action, ['add', 'edit'], true)) {
            $editId      = (int)($_POST['record_id'] ?? 0);
            $periodYear  = trim($_POST['period_year']  ?? '');
            $periodMonth = trim($_POST['period_month'] ?? '');

            // period_month: '' means annual (NULL), otherwise 1–12
            $periodMonthVal = ($periodMonth === '') ? null : (int)$periodMonth;

            if ($periodYear === '' || !ctype_digit($periodYear)
                || (int)$periodYear < 2000 || (int)$periodYear > (int)date('Y')) {
                $errors[] = 'Please enter a valid year (2000–' . date('Y') . ').';
            }
            if ($periodMonthVal !== null && ($periodMonthVal < 1 || $periodMonthVal > 12)) {
                $errors[] = 'Month must be between 1 and 12.';
            }

            // Numeric fields — all nullable
            $numFields = ['revenue', 'cost_of_sales', 'gross_profit', 'operating_expenses', 'net_profit'];
            $nums      = [];
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

            $disclosureType = $_POST['disclosure_type'] ?? 'self_reported';
            if (!in_array($disclosureType, ['self_reported', 'accountant_verified', 'audited'], true)) {
                $disclosureType = 'self_reported';
            }

            $notes = trim($_POST['notes'] ?? '');

            // Handle document upload
            $docUrl = trim($_POST['existing_doc_url'] ?? '');
            if (!empty($_FILES['supporting_document']['name'])) {
                $upload = uploadCompanyFile('supporting_document', $company['uuid'], 'document');
                if ($upload['success']) {
                    $docUrl = $upload['path'];
                } else {
                    $errors[] = $upload['error'];
                }
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    // Check for duplicate period
                    $stmt = $pdo->prepare("
                        SELECT id FROM company_financials
                        WHERE company_id = :cid
                          AND period_year = :yr
                          AND period_month <=> :mo
                    ");
                    $stmt->execute(['cid' => $companyId, 'yr' => $periodYear, 'mo' => $periodMonthVal]);
                    if ($stmt->fetch()) {
                        $errors[] = 'A financial report for this period already exists. Edit the existing record instead.';
                    } else {
                        $stmt = $pdo->prepare("
                            INSERT INTO company_financials
                                (company_id, period_year, period_month,
                                 revenue, cost_of_sales, gross_profit,
                                 operating_expenses, net_profit,
                                 disclosure_type, supporting_document_url, notes)
                            VALUES
                                (:cid, :yr, :mo,
                                 :revenue, :cos, :gp,
                                 :opex, :np,
                                 :dtype, :doc, :notes)
                        ");
                        $stmt->execute([
                            'cid'    => $companyId,
                            'yr'     => $periodYear,
                            'mo'     => $periodMonthVal,
                            'revenue'=> $nums['revenue'],
                            'cos'    => $nums['cost_of_sales'],
                            'gp'     => $nums['gross_profit'],
                            'opex'   => $nums['operating_expenses'],
                            'np'     => $nums['net_profit'],
                            'dtype'  => $disclosureType,
                            'doc'    => $docUrl ?: null,
                            'notes'  => $notes ?: null,
                        ]);
                        logCompanyActivity($companyId, $userId,
                            'Added financial report: ' . $periodYear . ($periodMonthVal ? '/' . $periodMonthVal : ' (Annual)'));
                        $success = 'Financial report added.';
                    }
                } else {
                    // edit — verify ownership
                    $stmt = $pdo->prepare("SELECT id FROM company_financials WHERE id = :id AND company_id = :cid");
                    $stmt->execute(['id' => $editId, 'cid' => $companyId]);
                    if (!$stmt->fetch()) {
                        $errors[] = 'Record not found.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE company_financials SET
                                period_year           = :yr,
                                period_month          = :mo,
                                revenue               = :revenue,
                                cost_of_sales         = :cos,
                                gross_profit          = :gp,
                                operating_expenses    = :opex,
                                net_profit            = :np,
                                disclosure_type       = :dtype,
                                supporting_document_url = :doc,
                                notes                 = :notes
                            WHERE id = :id AND company_id = :cid
                        ");
                        $stmt->execute([
                            'yr'     => $periodYear,
                            'mo'     => $periodMonthVal,
                            'revenue'=> $nums['revenue'],
                            'cos'    => $nums['cost_of_sales'],
                            'gp'     => $nums['gross_profit'],
                            'opex'   => $nums['operating_expenses'],
                            'np'     => $nums['net_profit'],
                            'dtype'  => $disclosureType,
                            'doc'    => $docUrl ?: null,
                            'notes'  => $notes ?: null,
                            'id'     => $editId,
                            'cid'    => $companyId,
                        ]);
                        logCompanyActivity($companyId, $userId, 'Updated financial report #' . $editId);
                        $success = 'Financial report updated.';
                    }
                }
            }
        }
    }
}

/* ── Load all records ───────────────────────── */
$stmt = $pdo->prepare("
    SELECT * FROM company_financials
    WHERE company_id = ?
    ORDER BY period_year DESC, COALESCE(period_month, 0) DESC
");
$stmt->execute([$companyId]);
$records = $stmt->fetchAll();

/* ── Load record to edit (if ?edit=id) ─────── */
$editRecord = null;
$editId     = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM company_financials WHERE id = ? AND company_id = ?");
    $stmt->execute([$editId, $companyId]);
    $editRecord = $stmt->fetch() ?: null;
}

// Avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';

$monthNames = [
    1=>'January',2=>'February',3=>'March',4=>'April',5=>'May',6=>'June',
    7=>'July',8=>'August',9=>'September',10=>'October',11=>'November',12=>'December'
];
$disclosureLabels = [
    'self_reported'       => ['Self-Reported',        'dt-self'],
    'accountant_verified' => ['Accountant Verified',  'dt-acct'],
    'audited'             => ['Audited',               'dt-audit'],
];

function fmtNum($val) {
    if ($val === null || $val === '') return '—';
    return 'R ' . number_format((float)$val, 0, '.', ' ');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Financials | <?php echo htmlspecialchars($company['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root {
        --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
        --amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;
        --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
        --surface:#ffffff;--surface-2:#f8f9fb;--border:#e4e7ec;
        --text:#101828;--text-muted:#667085;--text-light:#98a2b3;
        --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
        --radius:14px;--radius-sm:8px;
        --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
        --header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);
    }
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.5rem;justify-content:space-between;z-index:100;gap:1rem;}
    .header-brand{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .header-brand span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.25rem;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .8rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;}
    .page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
    .sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
    .sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
    .sidebar-section-label:first-child{margin-top:0;}
    .sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
    .sidebar a:hover{background:var(--surface-2);color:var(--text);}
    .sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
    .sidebar a i{width:16px;text-align:center;font-size:.85rem;}
    .main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
    .page-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem;}
    .page-head-left .breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:.35rem;}
    .page-head-left .breadcrumb a{color:var(--navy-light);text-decoration:none;}
    .page-head-left h1{font-family:'DM Serif Display',serif;font-size:1.7rem;color:var(--navy);}
    .card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.5rem;}
    .card-header{display:flex;align-items:center;justify-content:space-between;padding:1rem 1.25rem;border-bottom:1px solid var(--border);}
    .card-title{display:flex;align-items:center;gap:.5rem;font-size:.88rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.06em;}
    .card-title i{color:var(--navy-light);}
    .card-body{padding:1.25rem;}
    .alert{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.88rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert.success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .alert.error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    /* Form */
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;}
    .form-grid .span-2{grid-column:span 2;}
    .field{display:flex;flex-direction:column;gap:.4rem;}
    .field label{font-size:.82rem;font-weight:600;color:var(--text);}
    .field label i{color:var(--navy-light);margin-right:.25rem;}
    .field input,.field select,.field textarea{padding:.65rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition),box-shadow var(--transition);}
    .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.1);}
    .field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .9rem center;padding-right:2.2rem;cursor:pointer;}
    .field textarea{resize:vertical;min-height:70px;}
    .field .hint{font-size:.75rem;color:var(--text-light);}
    .input-prefix-wrap{position:relative;}
    .input-prefix-wrap .prefix{position:absolute;left:.75rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
    .input-prefix-wrap input{padding-left:1.9rem;}
    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.25rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;white-space:nowrap;}
    .btn-primary{background:var(--navy-mid);color:#fff;box-shadow:0 2px 8px rgba(15,59,122,.2);}
    .btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
    .btn-outline{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-outline:hover{border-color:#94a3b8;color:var(--text);background:#f8fafc;}
    .btn-danger{background:var(--error-bg);color:var(--error);border:1.5px solid var(--error-bdr);}
    .btn-danger:hover{background:var(--error);color:#fff;}
    .btn-sm{padding:.38rem .85rem;font-size:.78rem;}
    .btn-group{display:flex;gap:.5rem;flex-wrap:wrap;}
    /* Table */
    .table-wrap{overflow-x:auto;}
    table{width:100%;border-collapse:collapse;font-size:.85rem;}
    th{text-align:left;padding:.65rem .85rem;font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);white-space:nowrap;}
    td{padding:.75rem .85rem;border-bottom:1px solid var(--border);color:var(--text);vertical-align:middle;}
    tr:last-child td{border-bottom:none;}
    tr:hover td{background:#fafbfc;}
    td.num{text-align:right;font-variant-numeric:tabular-nums;}
    .empty-row td{text-align:center;padding:2rem;color:var(--text-light);font-size:.88rem;}
    /* Disclosure badges */
    .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .6rem;border-radius:99px;font-size:.73rem;font-weight:600;border:1px solid transparent;}
    .dt-self{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
    .dt-acct{background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .dt-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .form-section-divider{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);margin:1.25rem 0 .75rem;display:flex;align-items:center;gap:.5rem;}
    .form-section-divider::after{content:'';flex:1;height:1px;background:var(--border);}
    @media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}.form-grid{grid-template-columns:1fr;}.form-grid .span-2{grid-column:span 1;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>
<header class="top-header">
    <a href="/company/" class="header-brand">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/user/"><i class="fa-solid fa-chart-pie"></i> Dashboard</a>
        <a href="/company/" class="active"><i class="fa-solid fa-building"></i> Companies</a>
        <a href="/user/profile.php"><i class="fa-solid fa-user"></i> Profile</a>
        <a href="/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="dashboard.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-gauge"></i> Overview</a>
        <?php if ($canEdit): ?><a href="wizard.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-pen-to-square"></i> Edit Profile</a><?php endif; ?>
        <div class="sidebar-section-label">Content</div>
        <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>" class="active"><i class="fa-solid fa-chart-bar"></i> Financials</a>
        <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-trophy"></i> Milestones</a>
        <div class="sidebar-section-label">Fundraising</div>
        <a href="campaigns/index.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-rocket"></i> Campaigns</a>
        <div class="sidebar-section-label">Team</div>
        <a href="manage_admins.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-users"></i> Manage Team</a>
        <div class="sidebar-section-label">Account</div>
        <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">
        <div class="page-head">
            <div class="page-head-left">
                <div class="breadcrumb">
                    <a href="dashboard.php?uuid=<?php echo urlencode($uuid); ?>"><?php echo htmlspecialchars($company['name']); ?></a>
                    &rsaquo; Financials
                </div>
                <h1>Financial Reports</h1>
            </div>
            <?php if ($canEdit): ?>
                <a href="#add-form" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Add Report
                </a>
            <?php endif; ?>
        </div>

        <?php if (!empty($success)): ?>
            <div class="alert success"><i class="fa-solid fa-circle-check"></i><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert error"><i class="fa-solid fa-circle-exclamation"></i>
                <ul style="margin:.25rem 0 0 1rem;">
                    <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- RECORDS TABLE -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-table"></i> All Reports</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Period</th>
                            <th>Revenue</th>
                            <th>Cost of Sales</th>
                            <th>Gross Profit</th>
                            <th>Op. Expenses</th>
                            <th>Net Profit</th>
                            <th>Disclosure</th>
                            <?php if ($canEdit): ?><th></th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($records)): ?>
                            <tr class="empty-row"><td colspan="<?php echo $canEdit ? 8 : 7; ?>">No financial reports yet. Add your first report below.</td></tr>
                        <?php else: ?>
                            <?php foreach ($records as $r):
                                $periodLabel = $r['period_month']
                                    ? ($monthNames[(int)$r['period_month']] ?? $r['period_month']) . ' ' . $r['period_year']
                                    : $r['period_year'] . ' (Annual)';
                                $dlInfo = $disclosureLabels[$r['disclosure_type']] ?? ['Self-Reported', 'dt-self'];
                            ?>
                                <tr>
                                    <td><strong><?php echo htmlspecialchars($periodLabel); ?></strong></td>
                                    <td class="num"><?php echo fmtNum($r['revenue']); ?></td>
                                    <td class="num"><?php echo fmtNum($r['cost_of_sales']); ?></td>
                                    <td class="num"><?php echo fmtNum($r['gross_profit']); ?></td>
                                    <td class="num"><?php echo fmtNum($r['operating_expenses']); ?></td>
                                    <td class="num" style="<?php echo ($r['net_profit'] !== null && $r['net_profit'] < 0) ? 'color:var(--error)' : ''; ?>">
                                        <?php echo fmtNum($r['net_profit']); ?>
                                    </td>
                                    <td><span class="badge <?php echo $dlInfo[1]; ?>"><?php echo $dlInfo[0]; ?></span></td>
                                    <?php if ($canEdit): ?>
                                    <td>
                                        <div class="btn-group">
                                            <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>&edit=<?php echo $r['id']; ?>#add-form" class="btn btn-outline btn-sm">
                                                <i class="fa-solid fa-pen"></i>
                                            </a>
                                            <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this financial report?');">
                                                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                <input type="hidden" name="action" value="delete">
                                                <input type="hidden" name="record_id" value="<?php echo $r['id']; ?>">
                                                <button type="submit" class="btn btn-danger btn-sm"><i class="fa-solid fa-trash"></i></button>
                                            </form>
                                        </div>
                                    </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ADD / EDIT FORM -->
        <?php if ($canEdit): ?>
        <div class="card" id="add-form">
            <div class="card-header">
                <span class="card-title">
                    <i class="fa-solid fa-<?php echo $editRecord ? 'pen' : 'plus'; ?>"></i>
                    <?php echo $editRecord ? 'Edit Report' : 'Add Financial Report'; ?>
                </span>
                <?php if ($editRecord): ?>
                    <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-xmark"></i> Cancel Edit
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action"    value="<?php echo $editRecord ? 'edit' : 'add'; ?>">
                    <?php if ($editRecord): ?>
                        <input type="hidden" name="record_id" value="<?php echo $editRecord['id']; ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="field">
                            <label><i class="fa-solid fa-calendar"></i> Year <span style="color:#d97706;">*</span></label>
                            <input type="number" name="period_year" min="2000" max="<?php echo date('Y'); ?>"
                                   value="<?php echo htmlspecialchars($editRecord['period_year'] ?? date('Y')); ?>"
                                   placeholder="<?php echo date('Y'); ?>" required>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-calendar-days"></i> Month <span style="font-size:.75rem;color:var(--text-light);">(leave blank for annual)</span></label>
                            <select name="period_month">
                                <option value="">— Annual Summary —</option>
                                <?php foreach ($monthNames as $num => $name): ?>
                                    <option value="<?php echo $num; ?>"
                                        <?php echo (isset($editRecord['period_month']) && (int)$editRecord['period_month'] === $num) ? 'selected' : ''; ?>>
                                        <?php echo $name; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-section-divider">Revenue &amp; Costs</div>
                    <div class="form-grid">
                        <div class="field">
                            <label><i class="fa-solid fa-sack-dollar"></i> Revenue</label>
                            <div class="input-prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" name="revenue" min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($editRecord['revenue'] ?? ''); ?>"
                                       placeholder="0.00">
                            </div>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-boxes-stacked"></i> Cost of Sales</label>
                            <div class="input-prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" name="cost_of_sales" min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($editRecord['cost_of_sales'] ?? ''); ?>"
                                       placeholder="0.00">
                            </div>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-circle-dollar-to-slot"></i> Gross Profit</label>
                            <div class="input-prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" name="gross_profit" step="0.01"
                                       value="<?php echo htmlspecialchars($editRecord['gross_profit'] ?? ''); ?>"
                                       placeholder="0.00">
                            </div>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-receipt"></i> Operating Expenses</label>
                            <div class="input-prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" name="operating_expenses" min="0" step="0.01"
                                       value="<?php echo htmlspecialchars($editRecord['operating_expenses'] ?? ''); ?>"
                                       placeholder="0.00">
                            </div>
                        </div>
                        <div class="field span-2">
                            <label><i class="fa-solid fa-chart-line"></i> Net Profit / (Loss)</label>
                            <div class="input-prefix-wrap">
                                <span class="prefix">R</span>
                                <input type="number" name="net_profit" step="0.01"
                                       value="<?php echo htmlspecialchars($editRecord['net_profit'] ?? ''); ?>"
                                       placeholder="Use negative for a loss, e.g. -12500">
                            </div>
                        </div>
                    </div>

                    <div class="form-section-divider">Disclosure</div>
                    <div class="form-grid">
                        <div class="field">
                            <label><i class="fa-solid fa-stamp"></i> Disclosure Type</label>
                            <select name="disclosure_type">
                                <option value="self_reported"       <?php echo ($editRecord['disclosure_type'] ?? '') === 'self_reported'       ? 'selected' : ''; ?>>Self-Reported</option>
                                <option value="accountant_verified" <?php echo ($editRecord['disclosure_type'] ?? '') === 'accountant_verified' ? 'selected' : ''; ?>>Accountant Verified</option>
                                <option value="audited"             <?php echo ($editRecord['disclosure_type'] ?? '') === 'audited'             ? 'selected' : ''; ?>>Audited</option>
                            </select>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-file-pdf"></i> Supporting Document <span style="font-size:.75rem;color:var(--text-light);">(optional)</span></label>
                            <input type="file" name="supporting_document" accept=".pdf,image/*">
                            <?php if (!empty($editRecord['supporting_document_url'])): ?>
                                <input type="hidden" name="existing_doc_url" value="<?php echo htmlspecialchars($editRecord['supporting_document_url']); ?>">
                                <a href="<?php echo htmlspecialchars($editRecord['supporting_document_url']); ?>" target="_blank"
                                   style="font-size:.78rem;color:var(--navy-light);margin-top:.25rem;display:inline-flex;align-items:center;gap:.3rem;">
                                    <i class="fa-solid fa-file"></i> Current document — view
                                </a>
                            <?php endif; ?>
                        </div>
                        <div class="field span-2">
                            <label><i class="fa-solid fa-note-sticky"></i> Notes</label>
                            <textarea name="notes" rows="2" placeholder="Any context or commentary on this period…"><?php echo htmlspecialchars($editRecord['notes'] ?? ''); ?></textarea>
                        </div>
                    </div>

                    <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?php echo $editRecord ? 'Save Changes' : 'Add Report'; ?>
                        </button>
                        <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-outline">
                            Cancel
                        </a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
