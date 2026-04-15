<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/company_functions.php';
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
                    DELETE FROM company_milestones
                    WHERE id = :id AND company_id = :cid
                ");
                $stmt->execute(['id' => $deleteId, 'cid' => $companyId]);
                logCompanyActivity($companyId, $userId, 'Deleted milestone #' . $deleteId);
                $success = 'Milestone deleted.';
            }

        /* ── ADD or EDIT ────────────────────────── */
        } elseif (in_array($action, ['add', 'edit'], true)) {
            $editId          = (int)($_POST['record_id'] ?? 0);
            $milestoneDate   = trim($_POST['milestone_date']  ?? '');
            $title           = trim($_POST['title']           ?? '');
            $description     = trim($_POST['description']     ?? '');
            $isPublic        = isset($_POST['is_public']) ? 1 : 0;
            $sortOrder       = (int)($_POST['sort_order'] ?? 0);

            if ($milestoneDate === '') {
                $errors[] = 'Date is required.';
            } elseif (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $milestoneDate)) {
                $errors[] = 'Invalid date format.';
            }
            if ($title === '') {
                $errors[] = 'Title is required.';
            } elseif (mb_strlen($title) > 255) {
                $errors[] = 'Title cannot exceed 255 characters.';
            }

            if (empty($errors)) {
                if ($action === 'add') {
                    $stmt = $pdo->prepare("
                        INSERT INTO company_milestones
                            (company_id, milestone_date, title, description, is_public, sort_order)
                        VALUES
                            (:cid, :dt, :title, :desc, :pub, :sort)
                    ");
                    $stmt->execute([
                        'cid'   => $companyId,
                        'dt'    => $milestoneDate,
                        'title' => $title,
                        'desc'  => $description ?: null,
                        'pub'   => $isPublic,
                        'sort'  => $sortOrder,
                    ]);
                    logCompanyActivity($companyId, $userId, 'Added milestone: ' . $title);
                    $success = 'Milestone added.';
                } else {
                    $stmt = $pdo->prepare("SELECT id FROM company_milestones WHERE id = :id AND company_id = :cid");
                    $stmt->execute(['id' => $editId, 'cid' => $companyId]);
                    if (!$stmt->fetch()) {
                        $errors[] = 'Record not found.';
                    } else {
                        $stmt = $pdo->prepare("
                            UPDATE company_milestones SET
                                milestone_date = :dt,
                                title          = :title,
                                description    = :desc,
                                is_public      = :pub,
                                sort_order     = :sort
                            WHERE id = :id AND company_id = :cid
                        ");
                        $stmt->execute([
                            'dt'    => $milestoneDate,
                            'title' => $title,
                            'desc'  => $description ?: null,
                            'pub'   => $isPublic,
                            'sort'  => $sortOrder,
                            'id'    => $editId,
                            'cid'   => $companyId,
                        ]);
                        logCompanyActivity($companyId, $userId, 'Updated milestone #' . $editId);
                        $success = 'Milestone updated.';
                    }
                }
            }
        }
    }
}

/* ── Load all records ───────────────────────── */
$stmt = $pdo->prepare("
    SELECT * FROM company_milestones
    WHERE company_id = ?
    ORDER BY milestone_date DESC, sort_order ASC
");
$stmt->execute([$companyId]);
$records = $stmt->fetchAll();

/* ── Load record to edit (if ?edit=id) ─────── */
$editRecord = null;
$editId     = (int)($_GET['edit'] ?? 0);
if ($editId > 0 && $canEdit) {
    $stmt = $pdo->prepare("SELECT * FROM company_milestones WHERE id = ? AND company_id = ?");
    $stmt->execute([$editId, $companyId]);
    $editRecord = $stmt->fetch() ?: null;
}

// Avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Milestones | <?php echo htmlspecialchars($company['name']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{
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
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.1rem;}
    .form-grid .span-2{grid-column:span 2;}
    .field{display:flex;flex-direction:column;gap:.4rem;}
    .field label{font-size:.82rem;font-weight:600;color:var(--text);}
    .field label i{color:var(--navy-light);margin-right:.25rem;}
    .field input,.field select,.field textarea{padding:.65rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition),box-shadow var(--transition);}
    .field input:focus,.field select:focus,.field textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.1);}
    .field textarea{resize:vertical;min-height:80px;}
    .checkbox-field{display:flex;align-items:center;gap:.6rem;cursor:pointer;font-size:.88rem;font-weight:500;color:var(--text);}
    .checkbox-field input[type="checkbox"]{width:16px;height:16px;accent-color:var(--navy-mid);cursor:pointer;}
    .btn{display:inline-flex;align-items:center;gap:.45rem;padding:.6rem 1.25rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.85rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;white-space:nowrap;}
    .btn-primary{background:var(--navy-mid);color:#fff;box-shadow:0 2px 8px rgba(15,59,122,.2);}
    .btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
    .btn-outline{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-outline:hover{border-color:#94a3b8;color:var(--text);background:#f8fafc;}
    .btn-danger{background:var(--error-bg);color:var(--error);border:1.5px solid var(--error-bdr);}
    .btn-danger:hover{background:var(--error);color:#fff;}
    .btn-sm{padding:.38rem .85rem;font-size:.78rem;}
    .btn-group{display:flex;gap:.5rem;flex-wrap:wrap;}
    /* Timeline */
    .timeline{padding:0 .5rem;}
    .timeline-item{display:flex;gap:1.25rem;padding-bottom:1.75rem;position:relative;}
    .timeline-item:last-child{padding-bottom:0;}
    .timeline-item:not(:last-child)::before{content:'';position:absolute;left:17px;top:36px;bottom:0;width:2px;background:var(--border);}
    .timeline-dot{width:36px;height:36px;border-radius:50%;background:var(--navy);display:flex;align-items:center;justify-content:center;color:var(--amber);font-size:.85rem;flex-shrink:0;position:relative;z-index:1;}
    .timeline-content{flex:1;min-width:0;padding-top:.3rem;}
    .timeline-date{font-size:.75rem;font-weight:600;color:var(--text-light);text-transform:uppercase;letter-spacing:.06em;margin-bottom:.2rem;}
    .timeline-title{font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:.3rem;}
    .timeline-desc{font-size:.84rem;color:var(--text-muted);line-height:1.55;}
    .timeline-actions{display:flex;gap:.4rem;margin-top:.5rem;}
    .badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
    .badge-public{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .badge-private{background:#f1f5f9;color:#64748b;border-color:#cbd5e1;}
    .empty-state{text-align:center;padding:3rem 1.5rem;color:var(--text-light);}
    .empty-state i{font-size:2.5rem;margin-bottom:.75rem;display:block;color:var(--border);}
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
        <a href="financials.php?uuid=<?php echo urlencode($uuid); ?>"><i class="fa-solid fa-chart-bar"></i> Financials</a>
        <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>" class="active"><i class="fa-solid fa-trophy"></i> Milestones</a>
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
                    &rsaquo; Milestones
                </div>
                <h1>Milestones</h1>
            </div>
            <?php if ($canEdit): ?>
                <a href="#add-form" class="btn btn-primary">
                    <i class="fa-solid fa-plus"></i> Add Milestone
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

        <!-- TIMELINE -->
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="fa-solid fa-timeline"></i> Timeline</span>
            </div>
            <div class="card-body">
                <?php if (empty($records)): ?>
                    <div class="empty-state">
                        <i class="fa-solid fa-flag"></i>
                        No milestones yet. Add your first achievement below.
                    </div>
                <?php else: ?>
                    <div class="timeline">
                        <?php foreach ($records as $r): ?>
                            <div class="timeline-item">
                                <div class="timeline-dot"><i class="fa-solid fa-flag"></i></div>
                                <div class="timeline-content">
                                    <div class="timeline-date"><?php echo date('d M Y', strtotime($r['milestone_date'])); ?></div>
                                    <div class="timeline-title"><?php echo htmlspecialchars($r['title']); ?></div>
                                    <?php if ($r['description']): ?>
                                        <div class="timeline-desc"><?php echo nl2br(htmlspecialchars($r['description'])); ?></div>
                                    <?php endif; ?>
                                    <div style="display:flex;align-items:center;gap:.5rem;margin-top:.4rem;flex-wrap:wrap;">
                                        <span class="badge <?php echo $r['is_public'] ? 'badge-public' : 'badge-private'; ?>">
                                            <i class="fa-solid fa-<?php echo $r['is_public'] ? 'globe' : 'lock'; ?>"></i>
                                            <?php echo $r['is_public'] ? 'Public' : 'Private'; ?>
                                        </span>
                                        <?php if ($canEdit): ?>
                                            <div class="timeline-actions">
                                                <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>&edit=<?php echo $r['id']; ?>#add-form"
                                                   class="btn btn-outline btn-sm">
                                                    <i class="fa-solid fa-pen"></i> Edit
                                                </a>
                                                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this milestone?');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="record_id" value="<?php echo $r['id']; ?>">
                                                    <button type="submit" class="btn btn-danger btn-sm">
                                                        <i class="fa-solid fa-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ADD / EDIT FORM -->
        <?php if ($canEdit): ?>
        <div class="card" id="add-form">
            <div class="card-header">
                <span class="card-title">
                    <i class="fa-solid fa-<?php echo $editRecord ? 'pen' : 'plus'; ?>"></i>
                    <?php echo $editRecord ? 'Edit Milestone' : 'Add Milestone'; ?>
                </span>
                <?php if ($editRecord): ?>
                    <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-xmark"></i> Cancel Edit
                    </a>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="action"    value="<?php echo $editRecord ? 'edit' : 'add'; ?>">
                    <?php if ($editRecord): ?>
                        <input type="hidden" name="record_id" value="<?php echo $editRecord['id']; ?>">
                    <?php endif; ?>

                    <div class="form-grid">
                        <div class="field">
                            <label><i class="fa-solid fa-calendar"></i> Date <span style="color:#d97706;">*</span></label>
                            <input type="date" name="milestone_date"
                                   value="<?php echo htmlspecialchars($editRecord['milestone_date'] ?? ''); ?>"
                                   required>
                        </div>
                        <div class="field">
                            <label><i class="fa-solid fa-arrow-up-9-1"></i> Sort Order</label>
                            <input type="number" name="sort_order" min="0" max="9999"
                                   value="<?php echo htmlspecialchars((string)($editRecord['sort_order'] ?? 0)); ?>"
                                   placeholder="0">
                            <span style="font-size:.75rem;color:var(--text-light);">Lower numbers appear first within the same date.</span>
                        </div>
                        <div class="field span-2">
                            <label><i class="fa-solid fa-flag"></i> Title <span style="color:#d97706;">*</span></label>
                            <input type="text" name="title" maxlength="255"
                                   value="<?php echo htmlspecialchars($editRecord['title'] ?? ''); ?>"
                                   placeholder="e.g. Reached R1M in cumulative revenue"
                                   required>
                        </div>
                        <div class="field span-2">
                            <label><i class="fa-solid fa-align-left"></i> Description</label>
                            <textarea name="description" rows="3"
                                      placeholder="Any extra context about this achievement…"><?php echo htmlspecialchars($editRecord['description'] ?? ''); ?></textarea>
                        </div>
                        <div class="field span-2">
                            <label class="checkbox-field">
                                <input type="checkbox" name="is_public" value="1"
                                    <?php echo (!isset($editRecord) || $editRecord['is_public']) ? 'checked' : ''; ?>>
                                Show this milestone on the public company profile
                            </label>
                        </div>
                    </div>

                    <div style="margin-top:1.25rem;display:flex;gap:.75rem;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary">
                            <i class="fa-solid fa-floppy-disk"></i>
                            <?php echo $editRecord ? 'Save Changes' : 'Add Milestone'; ?>
                        </button>
                        <a href="milestones.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-outline">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
