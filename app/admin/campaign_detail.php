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
   Load campaign
═══════════════════════════════════════════ */
$campaignId = (int)($_GET['id'] ?? 0);
if (!$campaignId) { header('Location: campaigns.php'); exit; }

function loadCampaign(PDO $pdo, int $id): ?array {
    $stmt = $pdo->prepare("
        SELECT fc.*,
               c.id AS company_id, c.uuid AS company_uuid,
               c.name AS company_name, c.logo AS company_logo,
               c.status AS company_status,
               u.email AS creator_email
        FROM funding_campaigns fc
        JOIN companies c  ON fc.company_id = c.id
        LEFT JOIN users u ON fc.created_by  = u.id
        WHERE fc.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() ?: null;
}

$campaign = loadCampaign($pdo, $campaignId);
if (!$campaign) { header('Location: campaigns.php'); exit; }

/* ═══════════════════════════════════════════
   POST handler (PRG on success)
═══════════════════════════════════════════ */
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    /* ── Status transitions ──────────────── */
    $transitions = [
        'submit_review'  => [['draft'],                              'under_review'],
        'approve'        => [['under_review'],                        'approved'],
        'return_draft'   => [['under_review'],                        'draft'],
        'open'           => [['approved'],                            'open'],
        'suspend'        => [['open','funded'],                       'suspended'],
        'reinstate'      => [['suspended'],                           'open'],
        'cancel'         => [['draft','approved','open','suspended'], 'cancelled'],
        'close_success'  => [['open','funded'],                       'closed_successful'],
        'close_fail'     => [['open'],                                'closed_unsuccessful'],
    ];

    if (isset($transitions[$action])) {
        [$fromList, $toStatus] = $transitions[$action];
        if (in_array($campaign['status'], $fromList, true)) {
            $pdo->prepare("UPDATE funding_campaigns SET status = ?, updated_at = NOW() WHERE id = ?")
                ->execute([$toStatus, $campaignId]);
            logCompanyActivity($campaign['company_id'], $_SESSION['user_id'],
                "Campaign #{$campaignId} '{$campaign['title']}' changed to {$toStatus}");
            header("Location: campaign_detail.php?id={$campaignId}&flash=" . urlencode("Status updated to " . str_replace('_',' ',$toStatus) . "."));
            exit;
        }
    }

    /* ── Save campaign + terms ───────────── */
    if ($action === 'save') {
        $editable = in_array($campaign['status'], ['draft','under_review']);
        $adminEdit = true; // admins can always edit

        // ── funding_campaigns fields
        $title          = trim($_POST['title']          ?? '');
        $tagline        = trim($_POST['tagline']        ?? '');
        $campaign_type  = $_POST['campaign_type']       ?? $campaign['campaign_type'];
        $raise_target   = (float)($_POST['raise_target']  ?? 0);
        $raise_minimum  = (float)($_POST['raise_minimum'] ?? 0);
        $raise_maximum  = $_POST['raise_maximum'] !== '' ? (float)$_POST['raise_maximum'] : null;
        $min_contrib    = (float)($_POST['min_contribution'] ?? 500);
        $max_contrib    = $_POST['max_contribution'] !== '' ? (float)$_POST['max_contribution'] : null;
        $max_contr      = $_POST['max_contributors']  !== '' ? (int)$_POST['max_contributors']  : null;
        $opens_at       = trim($_POST['opens_at']      ?? '');
        $closes_at      = trim($_POST['closes_at']     ?? '');

        if ($title === '') $errors[] = 'Campaign title is required.';
        if ($raise_target <= 0) $errors[] = 'Raise target must be greater than zero.';
        if ($raise_minimum <= 0) $errors[] = 'Raise minimum must be greater than zero.';
        if ($raise_minimum > $raise_target) $errors[] = 'Raise minimum cannot exceed the raise target.';
        if ($opens_at === '') $errors[] = 'Opening date is required.';
        if ($closes_at === '') $errors[] = 'Closing date is required.';
        if ($opens_at && $closes_at && strtotime($closes_at) <= strtotime($opens_at)) {
            $errors[] = 'Closing date must be after the opening date.';
        }

        if (empty($errors)) {
            $pdo->prepare("
                UPDATE funding_campaigns SET
                    title = :title, tagline = :tagline, campaign_type = :type,
                    raise_target = :rt, raise_minimum = :rm, raise_maximum = :rmax,
                    min_contribution = :minc, max_contribution = :maxc,
                    max_contributors = :maxco, opens_at = :opens, closes_at = :closes,
                    updated_at = NOW()
                WHERE id = :id
            ")->execute([
                ':title'  => $title,   ':tagline' => $tagline ?: null,
                ':type'   => $campaign_type,
                ':rt'     => $raise_target,  ':rm'    => $raise_minimum,
                ':rmax'   => $raise_maximum, ':minc'  => $min_contrib,
                ':maxc'   => $max_contrib,   ':maxco' => $max_contr,
                ':opens'  => $opens_at,      ':closes'=> $closes_at,
                ':id'     => $campaignId,
            ]);

            // ── campaign_terms upsert
            $existingTerms = $pdo->prepare("SELECT id FROM campaign_terms WHERE campaign_id = ?");
            $existingTerms->execute([$campaignId]);
            $hasTerms = (bool)$existingTerms->fetchColumn();

            $termsData = [
                ':cid'     => $campaignId,
                ':rsp'     => $_POST['revenue_share_percentage']     !== '' ? (float)$_POST['revenue_share_percentage']     : null,
                ':rsd'     => $_POST['revenue_share_duration_months'] !== '' ? (int)$_POST['revenue_share_duration_months']  : null,
                ':frr'     => $_POST['fixed_return_rate']            !== '' ? (float)$_POST['fixed_return_rate']            : null,
                ':ltm'     => $_POST['loan_term_months']             !== '' ? (int)$_POST['loan_term_months']               : null,
                ':rstart'  => $_POST['repayment_start_date']         !== '' ? $_POST['repayment_start_date']                : null,
                ':rfreq'   => $_POST['repayment_frequency']          !== '' ? $_POST['repayment_frequency']                 : null,
                ':up'      => $_POST['unit_price']                   !== '' ? (float)$_POST['unit_price']                   : null,
                ':un'      => trim($_POST['unit_name']               ?? '') ?: null,
                ':tua'     => $_POST['total_units_available']        !== '' ? (int)$_POST['total_units_available']          : null,
                ':vc'      => $_POST['valuation_cap']                !== '' ? (float)$_POST['valuation_cap']                : null,
                ':dr'      => $_POST['discount_rate']                !== '' ? (float)$_POST['discount_rate']                : null,
                ':ct'      => trim($_POST['conversion_trigger']      ?? '') ?: null,
                ':gl'      => trim($_POST['governing_law']           ?? '') ?: 'Republic of South Africa',
                ':lat'     => trim($_POST['legal_agreement_template'] ?? '') ?: null,
            ];

            if ($hasTerms) {
                $pdo->prepare("
                    UPDATE campaign_terms SET
                        revenue_share_percentage = :rsp, revenue_share_duration_months = :rsd,
                        fixed_return_rate = :frr, loan_term_months = :ltm,
                        repayment_start_date = :rstart, repayment_frequency = :rfreq,
                        unit_price = :up, unit_name = :un, total_units_available = :tua,
                        valuation_cap = :vc, discount_rate = :dr, conversion_trigger = :ct,
                        governing_law = :gl, legal_agreement_template = :lat
                    WHERE campaign_id = :cid
                ")->execute($termsData);
            } else {
                $pdo->prepare("
                    INSERT INTO campaign_terms
                        (campaign_id, revenue_share_percentage, revenue_share_duration_months,
                         fixed_return_rate, loan_term_months, repayment_start_date, repayment_frequency,
                         unit_price, unit_name, total_units_available,
                         valuation_cap, discount_rate, conversion_trigger,
                         governing_law, legal_agreement_template)
                    VALUES
                        (:cid, :rsp, :rsd, :frr, :ltm, :rstart, :rfreq,
                         :up, :un, :tua, :vc, :dr, :ct, :gl, :lat)
                ")->execute($termsData);
            }

            logCompanyActivity($campaign['company_id'], $_SESSION['user_id'],
                "Campaign #{$campaignId} updated by admin");
            header("Location: campaign_detail.php?id={$campaignId}&flash=" . urlencode("Campaign saved.") . "&tab=overview");
            exit;
        }
    }

    /* ── Add admin note (campaign_update) ── */
    if ($action === 'add_note') {
        $noteTitle = trim($_POST['note_title'] ?? '');
        $noteBody  = trim($_POST['note_body']  ?? '');
        $noteType  = $_POST['note_type']       ?? 'general';
        $isPublic  = isset($_POST['note_public']) ? 1 : 0;
        $validTypes = ['general','financial','milestone','payout','issue','campaign_closed'];

        if ($noteTitle === '') $errors[] = 'Note title is required.';
        if ($noteBody  === '') $errors[] = 'Note body is required.';
        if (!in_array($noteType, $validTypes, true)) $noteType = 'general';

        if (empty($errors)) {
            $pdo->prepare("
                INSERT INTO campaign_updates
                    (campaign_id, author_id, update_type, title, body,
                     is_public, published_at, created_at)
                VALUES (:cid, :aid, :type, :title, :body, :pub, NOW(), NOW())
            ")->execute([
                ':cid'   => $campaignId,
                ':aid'   => $_SESSION['user_id'],
                ':type'  => $noteType,
                ':title' => $noteTitle,
                ':body'  => $noteBody,
                ':pub'   => $isPublic,
            ]);
            logCompanyActivity($campaign['company_id'], $_SESSION['user_id'],
                "Admin added campaign update: {$noteTitle}");
            header("Location: campaign_detail.php?id={$campaignId}&flash=" . urlencode("Update posted.") . "&tab=updates");
            exit;
        }
    }

    /* ── Answer Q&A question ─────────────── */
    if ($action === 'answer_question') {
        $questionId = (int)($_POST['question_id'] ?? 0);
        $answer     = trim($_POST['answer'] ?? '');
        if ($questionId && $answer !== '') {
            $pdo->prepare("
                UPDATE campaign_questions
                SET answer = :ans, answered_by = :by, answered_at = NOW()
                WHERE id = :id AND campaign_id = :cid
            ")->execute([
                ':ans' => $answer,
                ':by'  => $_SESSION['user_id'],
                ':id'  => $questionId,
                ':cid' => $campaignId,
            ]);
            header("Location: campaign_detail.php?id={$campaignId}&flash=" . urlencode("Answer posted.") . "&tab=qa");
            exit;
        }
    }

    // Reload after failed save (errors array populated)
    $campaign = loadCampaign($pdo, $campaignId);
}

/* ── Flash ───────────────────────────────── */
$flashMsg = !empty($_GET['flash']) ? urldecode($_GET['flash']) : null;
$activeTab = in_array($_GET['tab'] ?? '', ['overview','terms','contributors','updates','qa'])
           ? $_GET['tab'] : 'overview';

/* ═══════════════════════════════════════════
   Fetch related data
═══════════════════════════════════════════ */
// Terms
$tStmt = $pdo->prepare("SELECT * FROM campaign_terms WHERE campaign_id = ?");
$tStmt->execute([$campaignId]);
$terms = $tStmt->fetch() ?: [];

// Contributors
$cStmt = $pdo->prepare("
    SELECT con.*, u.email AS contributor_email
    FROM contributions con
    JOIN users u ON con.user_id = u.id
    WHERE con.campaign_id = ?
    ORDER BY con.created_at DESC
");
$cStmt->execute([$campaignId]);
$contributors = $cStmt->fetchAll();

$contribStats = [
    'total'    => count($contributors),
    'paid'     => 0,
    'pending'  => 0,
    'refunded' => 0,
    'active'   => 0,
    'total_r'  => 0.0,
];
foreach ($contributors as $con) {
    if (in_array($con['status'], ['paid','active','completed'])) { $contribStats['paid']++; $contribStats['total_r'] += (float)$con['amount']; }
    if (in_array($con['status'], ['pending_payment','under_review'])) $contribStats['pending']++;
    if ($con['status'] === 'refunded') $contribStats['refunded']++;
    if ($con['status'] === 'active')   $contribStats['active']++;
}

// Updates / notes
$uStmt = $pdo->prepare("
    SELECT cu.*, u.email AS author_email
    FROM campaign_updates cu
    JOIN users u ON cu.author_id = u.id
    WHERE cu.campaign_id = ?
    ORDER BY cu.created_at DESC
");
$uStmt->execute([$campaignId]);
$updates = $uStmt->fetchAll();

// Q&A
$qStmt = $pdo->prepare("
    SELECT cq.*,
           asker.email  AS asker_email,
           answerer.email AS answerer_email
    FROM campaign_questions cq
    JOIN   users asker    ON cq.asked_by    = asker.id
    LEFT JOIN users answerer ON cq.answered_by = answerer.id
    WHERE cq.campaign_id = ?
    ORDER BY cq.asked_at DESC
");
$qStmt->execute([$campaignId]);
$questions = $qStmt->fetchAll();

// Pending queue count for sidebar
$pendingQueue = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status='pending_verification'")->fetchColumn();

/* ═══════════════════════════════════════════
   Helpers
═══════════════════════════════════════════ */
function fmtR(float $n): string { return 'R ' . number_format($n, 2, '.', ' '); }
function fmtDate(?string $d): string { return $d ? date('d M Y', strtotime($d)) : '—'; }
function fmtDateTime(?string $d): string { return $d ? date('d M Y, H:i', strtotime($d)) : '—'; }

function statusBadgeArr(string $s): array {
    return [
        'draft'               => ['Draft',          '#667085','#f8f9fb','#e4e7ec'],
        'under_review'        => ['Under Review',   '#92400e','#fef3c7','#fde68a'],
        'approved'            => ['Approved',        '#1e40af','#dbeafe','#93c5fd'],
        'open'                => ['Live',            '#14532d','#dcfce7','#86efac'],
        'funded'              => ['Funded',          '#134e4a','#ccfbf1','#5eead4'],
        'closed_successful'   => ['Closed ✓',       '#134e4a','#ccfbf1','#5eead4'],
        'closed_unsuccessful' => ['Closed ✗',       '#dc2626','#fef2f2','#fecaca'],
        'cancelled'           => ['Cancelled',       '#dc2626','#fef2f2','#fecaca'],
        'suspended'           => ['Suspended',       '#dc2626','#fef2f2','#fecaca'],
    ][$s] ?? [ucfirst(str_replace('_',' ',$s)),'#667085','#f8f9fb','#e4e7ec'];
}

function conStatusBadge(string $s): string {
    $m = [
        'pending_payment' => ['Pending Payment', '#92400e','#fef3c7'],
        'paid'            => ['Paid',            '#1e40af','#dbeafe'],
        'under_review'    => ['Under Review',    '#92400e','#fef3c7'],
        'active'          => ['Active',          '#14532d','#dcfce7'],
        'refunded'        => ['Refunded',        '#6b21a8','#f3e8ff'],
        'defaulted'       => ['Defaulted',       '#dc2626','#fef2f2'],
        'completed'       => ['Completed',       '#134e4a','#ccfbf1'],
    ];
    [$l,$c,$bg] = $m[$s] ?? [ucfirst($s),'#667085','#f8f9fb'];
    return "<span style='display:inline-flex;align-items:center;padding:.18rem .65rem;border-radius:99px;font-size:.72rem;font-weight:600;background:{$bg};color:{$c};'>{$l}</span>";
}

function updateTypeIcon(string $t): string {
    return [
        'general'         => 'fa-comment',
        'financial'       => 'fa-chart-line',
        'milestone'       => 'fa-trophy',
        'payout'          => 'fa-money-bill-transfer',
        'issue'           => 'fa-triangle-exclamation',
        'campaign_closed' => 'fa-flag-checkered',
    ][$t] ?? 'fa-circle-info';
}

[$statusLabel, $statusColor, $statusBg, $statusBorder] = statusBadgeArr($campaign['status']);
$canEdit  = true; // admin can always edit (UI shows warning for live campaigns)
$isLive   = in_array($campaign['status'], ['open','funded']);
$pct      = $campaign['raise_target'] > 0
          ? min(100, round(($campaign['total_raised'] / $campaign['raise_target']) * 100))
          : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?php echo htmlspecialchars($campaign['title']); ?> | Campaign Admin</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root {
    --navy:#0b2545; --navy-mid:#0f3b7a; --navy-light:#1a56b0;
    --amber:#f59e0b; --amber-dark:#d97706;
    --emerald:#059669; --emerald-bg:#ecfdf5; --emerald-bdr:#6ee7b7;
    --red:#dc2626; --red-bg:#fef2f2; --red-bdr:#fecaca;
    --surface:#ffffff; --surface-2:#f8f9fb;
    --border:#e4e7ec; --text:#101828; --text-muted:#667085; --text-light:#98a2b3;
    --sidebar-w:260px; --header-h:60px;
    --radius:14px; --radius-sm:8px;
    --shadow-sm:0 1px 3px rgba(11,37,69,.08);
    --shadow-card:0 4px 24px rgba(11,37,69,.07),0 1px 4px rgba(11,37,69,.04);
    --transition:.2s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;display:flex;}

/* Mobile header */
.mob-header{display:none;position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--navy);align-items:center;justify-content:space-between;padding:0 1.25rem;z-index:200;box-shadow:0 2px 12px rgba(0,0,0,.25);}
.mob-brand{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;display:flex;align-items:center;gap:.5rem;min-width:0;}
.mob-brand span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.mob-menu-btn{background:rgba(255,255,255,.1);border:none;border-radius:var(--radius-sm);color:#fff;width:38px;height:38px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:1.1rem;transition:background var(--transition);flex-shrink:0;}
.mob-menu-btn:hover{background:rgba(255,255,255,.2);}

/* Sidebar */
.sidebar{width:var(--sidebar-w);background:var(--navy);min-height:100vh;padding:2rem 1.5rem;display:flex;flex-direction:column;position:sticky;top:0;height:100vh;overflow-y:auto;flex-shrink:0;z-index:100;box-shadow:4px 0 20px rgba(0,0,0,.12);transition:transform var(--transition);}
.sidebar::after{content:'';position:absolute;bottom:-60px;right:-60px;width:220px;height:220px;border-radius:50%;border:50px solid rgba(245,158,11,.06);pointer-events:none;}
.sidebar-brand{display:flex;align-items:center;gap:.75rem;margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid rgba(255,255,255,.08);}
.brand-icon{width:40px;height:40px;background:var(--amber);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--navy);font-size:1rem;flex-shrink:0;}
.brand-name{font-family:'DM Serif Display',serif;font-size:1.15rem;color:#fff;line-height:1.1;}
.brand-sub{font-size:.7rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.1em;}
.sidebar-divider{height:1px;background:rgba(255,255,255,.07);margin:.75rem 0;}
.sidebar-nav{list-style:none;margin-bottom:1.5rem;}
.nav-item a{display:flex;align-items:center;gap:.75rem;padding:.7rem .9rem;border-radius:var(--radius-sm);color:rgba(255,255,255,.55);text-decoration:none;font-size:.88rem;font-weight:500;transition:all var(--transition);}
.nav-item a:hover{background:rgba(255,255,255,.07);color:rgba(255,255,255,.85);}
.nav-item.active a{background:rgba(245,158,11,.15);color:var(--amber);font-weight:600;}
.nav-item a i{width:18px;text-align:center;font-size:.9rem;}
.nav-count{margin-left:auto;background:var(--amber);color:var(--navy);border-radius:99px;font-size:.68rem;font-weight:700;padding:.1rem .45rem;}
.sidebar-footer{margin-top:auto;padding-top:1rem;border-top:1px solid rgba(255,255,255,.08);font-size:.78rem;color:rgba(255,255,255,.3);text-align:center;}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:150;}

/* Main */
.main{flex:1;padding:2rem 2.5rem;overflow-x:hidden;min-width:0;}

/* Breadcrumb */
.breadcrumb{display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text-muted);margin-bottom:1.25rem;flex-wrap:wrap;}
.breadcrumb a{color:var(--navy-light);text-decoration:none;}
.breadcrumb a:hover{text-decoration:underline;}
.breadcrumb .sep{color:var(--border);}

/* Page header */
.page-header{display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:1rem;margin-bottom:1.75rem;}
.page-title{font-family:'DM Serif Display',serif;font-size:1.7rem;color:var(--navy);line-height:1.15;max-width:700px;}
.page-meta{display:flex;flex-wrap:wrap;align-items:center;gap:.5rem;margin-top:.4rem;font-size:.83rem;color:var(--text-muted);}
.page-meta .dot{color:var(--border);}
.status-badge{display:inline-flex;align-items:center;padding:.25rem .85rem;border-radius:99px;font-size:.78rem;font-weight:600;border:1px solid;}
.header-actions{display:flex;gap:.6rem;flex-wrap:wrap;align-items:flex-start;}
.btn-sm{display:inline-flex;align-items:center;gap:.35rem;padding:.5rem 1rem;border-radius:99px;border:1.5px solid var(--border);background:var(--surface-2);color:var(--text-muted);font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;cursor:pointer;text-decoration:none;transition:all var(--transition);}
.btn-sm:hover{border-color:var(--navy-light);color:var(--navy);background:#eff4ff;}
.btn-sm.primary{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
.btn-sm.primary:hover{background:var(--navy);}

/* Toast */
.toast{display:flex;align-items:center;gap:.75rem;padding:.9rem 1.4rem;border-radius:var(--radius-sm);margin-bottom:1.5rem;font-size:.88rem;font-weight:500;border:1px solid transparent;animation:toastIn .3s ease;}
@keyframes toastIn{from{opacity:0;transform:translateY(-6px);}to{opacity:1;transform:translateY(0);}}
.toast.success{background:var(--emerald-bg);color:var(--emerald);border-color:var(--emerald-bdr);}
.toast.error{background:var(--red-bg);color:var(--red);border-color:var(--red-bdr);}
.toast i{font-size:1rem;flex-shrink:0;}

/* Stats bar */
.stats-bar{display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.75rem;}
.stat-box{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.25rem;box-shadow:var(--shadow-sm);}
.stat-box .sb-val{font-size:1.5rem;font-weight:700;color:var(--navy);line-height:1;margin-bottom:.2rem;}
.stat-box .sb-lbl{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);}
.stat-box .sb-sub{font-size:.78rem;color:var(--text-muted);margin-top:.15rem;}
.sb-progress{height:5px;background:var(--border);border-radius:99px;overflow:hidden;margin-top:.4rem;}
.sb-progress-fill{height:100%;border-radius:99px;background:var(--navy-light);}
.sb-progress-fill.done{background:var(--emerald);}

/* Action bar */
.action-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.5rem;margin-bottom:1.75rem;display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;box-shadow:var(--shadow-sm);}
.action-bar-label{font-size:.78rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-right:.25rem;}
.btn-action{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.1rem;border-radius:99px;border:1.5px solid var(--border);background:var(--surface-2);color:var(--text-muted);font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;cursor:pointer;transition:all var(--transition);white-space:nowrap;}
.btn-action:hover{border-color:var(--navy-light);color:var(--navy);background:#eff4ff;}
.btn-action.green{border-color:var(--emerald-bdr);color:var(--emerald);background:var(--emerald-bg);}
.btn-action.green:hover{background:var(--emerald);color:#fff;}
.btn-action.red{border-color:var(--red-bdr);color:var(--red);background:var(--red-bg);}
.btn-action.red:hover{background:var(--red);color:#fff;}
.btn-action.amber{border-color:#fde68a;color:#92400e;background:#fef3c7;}
.btn-action.amber:hover{background:#d97706;color:#fff;border-color:#d97706;}
.btn-action.blue{border-color:#93c5fd;color:#1e40af;background:#dbeafe;}
.btn-action.blue:hover{background:#1e40af;color:#fff;}
.live-warning{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;color:#92400e;background:#fef3c7;border:1px solid #fde68a;border-radius:99px;padding:.25rem .75rem;margin-left:.5rem;}

/* Tabs */
.tab-nav{display:flex;border-bottom:1px solid var(--border);gap:0;margin-bottom:1.75rem;overflow-x:auto;scrollbar-width:none;}
.tab-nav::-webkit-scrollbar{display:none;}
.tab-btn{display:inline-flex;align-items:center;gap:.4rem;padding:.85rem 1.25rem;border:none;background:transparent;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.87rem;font-weight:500;color:var(--text-muted);border-bottom:2.5px solid transparent;margin-bottom:-1px;transition:all var(--transition);white-space:nowrap;}
.tab-btn:hover{color:var(--navy);}
.tab-btn.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
.tab-badge{background:var(--surface-2);border:1px solid var(--border);border-radius:99px;font-size:.68rem;font-weight:600;padding:.05rem .45rem;color:var(--text-muted);}
.tab-btn.active .tab-badge{background:var(--navy-mid);color:#fff;border-color:var(--navy-mid);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* Cards */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.75rem;margin-bottom:1.5rem;box-shadow:var(--shadow-card);}
.card-title{font-size:1rem;font-weight:700;color:var(--navy);margin-bottom:1.25rem;display:flex;align-items:center;gap:.5rem;padding-bottom:.85rem;border-bottom:1px solid var(--border);}
.card-title i{font-size:.9rem;color:var(--navy-light);}

/* Form elements */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
.form-grid.three{grid-template-columns:1fr 1fr 1fr;}
.form-grid .span-2{grid-column:span 2;}
.field{display:flex;flex-direction:column;gap:.4rem;}
.field label{font-size:.8rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.35rem;}
.field label .req{color:var(--amber-dark);}
.field label .hint-inline{font-size:.75rem;font-weight:400;color:var(--text-light);margin-left:.25rem;}
.field input,.field select,.field textarea{padding:.68rem .95rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.9rem;color:var(--text);background:var(--surface-2);outline:none;transition:border-color var(--transition),box-shadow var(--transition);}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.09);}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .85rem center;padding-right:2.25rem;cursor:pointer;}
.field select:disabled{opacity:.45;cursor:not-allowed;}
.field textarea{resize:vertical;min-height:90px;line-height:1.6;}
.field .field-hint{font-size:.75rem;color:var(--text-light);}
.field input[readonly]{background:var(--surface-2);color:var(--text-muted);cursor:default;}
.form-section{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);display:flex;align-items:center;gap:.6rem;margin:1.5rem 0 1rem;}
.form-section::after{content:'';flex:1;height:1px;background:var(--border);}
.terms-block{display:none;}
.terms-block.visible{display:contents;}

/* Alert */
.alert-block{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.87rem;font-weight:500;border:1px solid;}
.alert-block.error{background:var(--red-bg);color:var(--red);border-color:var(--red-bdr);}
.alert-block.warn{background:#fef3c7;color:#92400e;border-color:#fde68a;}
.alert-block i{flex-shrink:0;margin-top:.05rem;}
.alert-block ul{margin:.3rem 0 0 1rem;}
.alert-block ul li{margin-bottom:.15rem;}

/* Save button row */
.form-footer{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;padding-top:1.25rem;margin-top:1.25rem;border-top:1px solid var(--border);}
.btn-save{display:inline-flex;align-items:center;gap:.5rem;padding:.75rem 2rem;border-radius:99px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;background:var(--navy-mid);color:#fff;box-shadow:0 4px 12px rgba(15,59,122,.2);transition:all var(--transition);}
.btn-save:hover{background:var(--navy);transform:translateY(-1px);}

/* Contributors table */
.data-table{width:100%;border-collapse:collapse;font-size:.85rem;}
.data-table th{padding:.75rem 1rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-muted);text-align:left;border-bottom:1px solid var(--border);background:var(--surface-2);}
.data-table td{padding:.85rem 1rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover{background:#fafbff;}
.data-table .amount-cell{font-weight:600;color:var(--navy);}

/* Updates list */
.update-item{display:flex;gap:1rem;padding:1rem 0;border-bottom:1px solid var(--border);}
.update-item:last-child{border-bottom:none;}
.update-icon{width:36px;height:36px;border-radius:10px;background:var(--surface-2);border:1px solid var(--border);display:flex;align-items:center;justify-content:center;color:var(--navy-light);font-size:.9rem;flex-shrink:0;}
.update-body{flex:1;min-width:0;}
.update-title{font-weight:600;color:var(--navy);margin-bottom:.2rem;display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;}
.update-meta{font-size:.78rem;color:var(--text-muted);}
.update-text{font-size:.87rem;color:var(--text);margin-top:.4rem;line-height:1.6;}
.pub-badge{display:inline-flex;align-items:center;padding:.1rem .55rem;border-radius:99px;font-size:.68rem;font-weight:600;}
.pub-badge.public{background:#dcfce7;color:#14532d;}
.pub-badge.private{background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);}

/* Q&A */
.qa-item{padding:1rem 0;border-bottom:1px solid var(--border);}
.qa-item:last-child{border-bottom:none;}
.qa-q{font-weight:600;color:var(--navy);margin-bottom:.35rem;}
.qa-meta{font-size:.76rem;color:var(--text-muted);margin-bottom:.5rem;}
.qa-a{background:var(--surface-2);border-left:3px solid var(--navy-light);padding:.6rem 1rem;border-radius:0 var(--radius-sm) var(--radius-sm) 0;font-size:.87rem;color:var(--text);line-height:1.6;}
.qa-a-meta{font-size:.72rem;color:var(--text-muted);margin-top:.3rem;}
.answer-form{margin-top:.75rem;}
.answer-form textarea{width:100%;padding:.65rem .9rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.87rem;color:var(--text);background:var(--surface-2);resize:vertical;min-height:72px;outline:none;transition:border-color var(--transition);}
.answer-form textarea:focus{border-color:var(--navy-light);background:#fff;}
.btn-answer{display:inline-flex;align-items:center;gap:.35rem;padding:.45rem .95rem;border-radius:99px;border:none;cursor:pointer;font-family:'DM Sans',sans-serif;font-size:.8rem;font-weight:600;background:var(--navy-mid);color:#fff;margin-top:.5rem;transition:background var(--transition);}
.btn-answer:hover{background:var(--navy);}

/* Empty */
.empty-panel{text-align:center;padding:3rem 1rem;color:var(--text-muted);}
.empty-panel i{font-size:2rem;margin-bottom:.75rem;display:block;opacity:.3;}
.empty-panel p{font-size:.9rem;}

/* Responsive */
@media(max-width:900px){
    body{flex-direction:column;}
    .mob-header{display:flex;}
    .sidebar{position:fixed;top:0;left:0;bottom:0;transform:translateX(-100%);z-index:160;height:100%;}
    .sidebar.open{transform:translateX(0);}
    .main{margin-top:var(--header-h);padding:1.5rem 1.25rem;}
    .stats-bar{grid-template-columns:1fr 1fr;}
    .form-grid,.form-grid.three{grid-template-columns:1fr;}
    .form-grid .span-2{grid-column:span 1;}
    .page-title{font-size:1.35rem;}
}
@media(max-width:540px){
    .main{padding:1rem .85rem;}
    .stats-bar{grid-template-columns:1fr 1fr;}
    .action-bar{flex-direction:column;align-items:stretch;}
}
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:transparent;}
::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<!-- Mobile header -->
<header class="mob-header">
    <div class="mob-brand">
        <i class="fa-solid fa-rocket" style="color:var(--amber);flex-shrink:0;"></i>
        <span><?php echo htmlspecialchars($campaign['title']); ?></span>
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
    <ul class="sidebar-nav">
        <li class="nav-item">
            <a href="company_review.php">
                <i class="fa-solid fa-inbox"></i> Pending Queue
                <?php if ($pendingQueue > 0): ?><span class="nav-count"><?php echo $pendingQueue; ?></span><?php endif; ?>
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
            <a href="company_review.php?id=<?php echo $campaign['company_id']; ?>">
                <i class="fa-solid fa-building-circle-arrow-right"></i>
                <?php echo htmlspecialchars(mb_strimwidth($campaign['company_name'], 0, 22, '…')); ?>
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

<!-- Main -->
<main class="main">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="campaigns.php"><i class="fa-solid fa-rocket"></i> Campaigns</a>
        <span class="sep">/</span>
        <a href="company_review.php?id=<?php echo $campaign['company_id']; ?>">
            <?php echo htmlspecialchars($campaign['company_name']); ?>
        </a>
        <span class="sep">/</span>
        <span><?php echo htmlspecialchars($campaign['title']); ?></span>
    </div>

    <!-- Page header -->
    <div class="page-header">
        <div>
            <div class="page-title"><?php echo htmlspecialchars($campaign['title']); ?></div>
            <div class="page-meta">
                <span class="status-badge" style="background:<?php echo $statusBg; ?>;color:<?php echo $statusColor; ?>;border-color:<?php echo $statusBorder; ?>;">
                    <?php echo $statusLabel; ?>
                </span>
                <span class="dot">·</span>
                <?php echo htmlspecialchars($campaign['company_name']); ?>
                <span class="dot">·</span>
                <?php echo ucfirst(str_replace('_', ' ', $campaign['campaign_type'])); ?>
                <?php if ($campaign['tagline']): ?>
                    <span class="dot">·</span>
                    <span style="font-style:italic;"><?php echo htmlspecialchars($campaign['tagline']); ?></span>
                <?php endif; ?>
            </div>
        </div>
        <div class="header-actions">
            <a href="campaigns.php" class="btn-sm">
                <i class="fa-solid fa-arrow-left"></i> All Campaigns
            </a>
            <a href="campaign_detail.php?id=<?php echo $campaignId; ?>" class="btn-sm">
                <i class="fa-solid fa-rotate-right"></i> Refresh
            </a>
        </div>
    </div>

    <!-- Toast -->
    <?php if ($flashMsg): ?>
        <div class="toast success"><i class="fa-solid fa-circle-check"></i> <?php echo htmlspecialchars($flashMsg); ?></div>
    <?php endif; ?>

    <!-- Error block (save failures) -->
    <?php if (!empty($errors)): ?>
        <div class="alert-block error">
            <i class="fa-solid fa-circle-exclamation"></i>
            <div><strong>Please fix the following:</strong>
                <ul><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul>
            </div>
        </div>
    <?php endif; ?>

    <!-- Stats bar -->
    <div class="stats-bar">
        <div class="stat-box">
            <div class="sb-val"><?php echo fmtR((float)$campaign['total_raised']); ?></div>
            <div class="sb-lbl">Total Raised</div>
            <div class="sb-progress"><div class="sb-progress-fill <?php echo $pct >= 100 ? 'done' : ''; ?>" style="width:<?php echo $pct; ?>%"></div></div>
            <div class="sb-sub"><?php echo $pct; ?>% of <?php echo fmtR((float)$campaign['raise_target']); ?></div>
        </div>
        <div class="stat-box">
            <div class="sb-val"><?php echo $campaign['contributor_count']; ?></div>
            <div class="sb-lbl">Contributors</div>
            <div class="sb-sub">
                <?php echo $campaign['max_contributors'] ? 'Cap: ' . $campaign['max_contributors'] : 'No cap'; ?>
                <?php if ($contribStats['pending'] > 0): ?>
                    &nbsp;· <span style="color:var(--amber-dark);"><?php echo $contribStats['pending']; ?> pending</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="stat-box">
            <div class="sb-val"><?php echo fmtR((float)$campaign['raise_target']); ?></div>
            <div class="sb-lbl">Target</div>
            <div class="sb-sub">Min: <?php echo fmtR((float)$campaign['raise_minimum']); ?><?php echo $campaign['raise_maximum'] ? ' · Max: ' . fmtR((float)$campaign['raise_maximum']) : ''; ?></div>
        </div>
        <div class="stat-box">
            <div class="sb-val"><?php echo fmtDate($campaign['closes_at']); ?></div>
            <div class="sb-lbl">Closes</div>
            <div class="sb-sub">
                <?php
                if ($campaign['closes_at']) {
                    $daysLeft = (int)ceil((strtotime($campaign['closes_at']) - time()) / 86400);
                    if ($daysLeft > 0) echo $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left';
                    elseif ($daysLeft === 0) echo 'Closes today';
                    else echo abs($daysLeft) . ' days ago';
                } else { echo 'Not set'; }
                ?>
            </div>
        </div>
    </div>

    <!-- Status action bar -->
    <div class="action-bar">
        <span class="action-bar-label">Actions</span>

        <?php if ($isLive): ?>
            <span class="live-warning"><i class="fa-solid fa-triangle-exclamation"></i> Campaign is live — changes are visible to contributors</span>
        <?php endif; ?>

        <?php
        $s = $campaign['status'];

        $actionDefs = [
            'submit_review' => ['draft',                 'Submit for Review',    'fa-paper-plane',    'blue',  'Submit this campaign for admin review?'],
            'approve'       => ['under_review',          'Approve',              'fa-circle-check',   'green', 'Approve this campaign? It will move to Approved status.'],
            'return_draft'  => ['under_review',          'Return to Draft',      'fa-rotate-left',    'amber', 'Return this campaign to draft?'],
            'open'          => ['approved',              'Open for Contributions','fa-play',           'green', 'Open this campaign for contributions now?'],
            'suspend'       => [['open','funded'],       'Suspend',              'fa-circle-pause',   'red',   "Suspend this live campaign? Contributors won't be able to contribute."],
            'reinstate'     => ['suspended',             'Reinstate',            'fa-play',           'green', 'Reinstate this campaign?'],
            'close_success' => [['open','funded'],       'Close — Successful',   'fa-flag-checkered', 'green', 'Mark this campaign as successfully closed?'],
            'close_fail'    => ['open',                  'Close — Unsuccessful', 'fa-circle-xmark',   'red',   'Mark as closed unsuccessful? This will trigger refunds.'],
            'cancel'        => [['draft','approved','open','suspended'], 'Cancel Campaign', 'fa-ban', 'red', 'Cancel this campaign permanently?'],
        ];

        foreach ($actionDefs as $act => [$from, $label, $icon, $cls, $conf]):
            $fromArr = is_array($from) ? $from : [$from];
            if (!in_array($s, $fromArr, true)) continue;
        ?>
            <form method="POST" style="display:inline;">
                <input type="hidden" name="action" value="<?php echo $act; ?>">
                <button type="submit" class="btn-action <?php echo $cls; ?>"
                        onclick="return confirm(<?php echo json_encode($conf); ?>)">
                    <i class="fa-solid <?php echo $icon; ?>"></i> <?php echo $label; ?>
                </button>
            </form>
        <?php endforeach; ?>
    </div>

    <!-- Tab nav -->
    <div class="tab-nav">
        <button class="tab-btn <?php echo $activeTab==='overview'?'active':''; ?>"
                onclick="switchTab('overview',this)">
            <i class="fa-solid fa-pen-to-square"></i> Overview &amp; Edit
        </button>
        <button class="tab-btn <?php echo $activeTab==='terms'?'active':''; ?>"
                onclick="switchTab('terms',this)">
            <i class="fa-solid fa-file-contract"></i> Deal Terms
        </button>
        <button class="tab-btn <?php echo $activeTab==='contributors'?'active':''; ?>"
                onclick="switchTab('contributors',this)">
            <i class="fa-solid fa-users"></i> Contributors
            <span class="tab-badge"><?php echo count($contributors); ?></span>
        </button>
        <button class="tab-btn <?php echo $activeTab==='updates'?'active':''; ?>"
                onclick="switchTab('updates',this)">
            <i class="fa-solid fa-bullhorn"></i> Updates
            <span class="tab-badge"><?php echo count($updates); ?></span>
        </button>
        <button class="tab-btn <?php echo $activeTab==='qa'?'active':''; ?>"
                onclick="switchTab('qa',this)">
            <i class="fa-solid fa-circle-question"></i> Q &amp; A
            <span class="tab-badge"><?php echo count($questions); ?></span>
        </button>
    </div>

    <!-- ════════════════════════════════════
         TAB: OVERVIEW & EDIT
    ════════════════════════════════════ -->
    <div id="tab-overview" class="tab-panel <?php echo $activeTab==='overview'?'active':''; ?>">
        <?php if ($isLive): ?>
            <div class="alert-block warn" style="margin-bottom:1.25rem;">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div>This campaign is <strong>live</strong>. Editing financial terms or dates may affect active contributors. Proceed carefully.</div>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="action" value="save">

            <div class="card">
                <div class="card-title"><i class="fa-solid fa-building"></i> Campaign Identity</div>
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="title">Title <span class="req">*</span></label>
                        <input type="text" id="title" name="title" required maxlength="255"
                               value="<?php echo htmlspecialchars($campaign['title']); ?>">
                    </div>
                    <div class="field span-2">
                        <label for="tagline">Tagline <span class="hint-inline">— shown below the title on listings</span></label>
                        <input type="text" name="tagline" maxlength="500"
                               value="<?php echo htmlspecialchars($campaign['tagline'] ?? ''); ?>"
                               placeholder="One compelling sentence about this raise…">
                    </div>
                    <div class="field">
                        <label for="campaign_type">Campaign Type <span class="req">*</span></label>
                        <select id="campaign_type" name="campaign_type" onchange="updateTermsVisibility(this.value)">
                            <?php foreach ([
                                'revenue_share'          => 'Revenue Share',
                                'fixed_return_loan'      => 'Fixed Return Loan',
                                'cooperative_membership' => 'Co-op Membership',
                                'donation'               => 'Donation',
                                'convertible_note'       => 'Convertible Note',
                            ] as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $campaign['campaign_type']===$v?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label>Company</label>
                        <input type="text" readonly value="<?php echo htmlspecialchars($campaign['company_name']); ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><i class="fa-solid fa-coins"></i> Funding Targets</div>
                <div class="form-grid three">
                    <div class="field">
                        <label for="raise_target">Raise Target (R) <span class="req">*</span></label>
                        <input type="number" id="raise_target" name="raise_target" min="1" step="100" required
                               value="<?php echo $campaign['raise_target']; ?>">
                    </div>
                    <div class="field">
                        <label for="raise_minimum">Raise Minimum (R) <span class="req">*</span>
                            <span class="hint-inline">— soft floor</span></label>
                        <input type="number" id="raise_minimum" name="raise_minimum" min="1" step="100" required
                               value="<?php echo $campaign['raise_minimum']; ?>">
                    </div>
                    <div class="field">
                        <label for="raise_maximum">Raise Maximum (R)
                            <span class="hint-inline">— leave blank for uncapped</span></label>
                        <input type="number" id="raise_maximum" name="raise_maximum" min="0" step="100"
                               value="<?php echo $campaign['raise_maximum'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="min_contribution">Min. Contribution (R) <span class="req">*</span></label>
                        <input type="number" id="min_contribution" name="min_contribution" min="0" step="50"
                               value="<?php echo $campaign['min_contribution']; ?>">
                    </div>
                    <div class="field">
                        <label for="max_contribution">Max. Contribution (R)
                            <span class="hint-inline">— blank = unlimited</span></label>
                        <input type="number" id="max_contribution" name="max_contribution" min="0" step="100"
                               value="<?php echo $campaign['max_contribution'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="max_contributors">Max. Contributors
                            <span class="hint-inline">— §49 compliance cap</span></label>
                        <input type="number" id="max_contributors" name="max_contributors" min="1" max="499"
                               value="<?php echo $campaign['max_contributors'] ?? ''; ?>">
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-title"><i class="fa-solid fa-calendar-days"></i> Timeline</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="opens_at">Opens At <span class="req">*</span></label>
                        <input type="datetime-local" id="opens_at" name="opens_at" required
                               value="<?php echo $campaign['opens_at'] ? date('Y-m-d\TH:i', strtotime($campaign['opens_at'])) : ''; ?>">
                    </div>
                    <div class="field">
                        <label for="closes_at">Closes At <span class="req">*</span></label>
                        <input type="datetime-local" id="closes_at" name="closes_at" required
                               value="<?php echo $campaign['closes_at'] ? date('Y-m-d\TH:i', strtotime($campaign['closes_at'])) : ''; ?>">
                    </div>
                    <?php if ($campaign['funded_at']): ?>
                    <div class="field">
                        <label>Funded At</label>
                        <input type="text" readonly value="<?php echo fmtDateTime($campaign['funded_at']); ?>">
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="form-footer">
                <div style="font-size:.8rem;color:var(--text-muted);">
                    Last updated: <?php echo fmtDateTime($campaign['updated_at']); ?>
                    · Created by: <?php echo htmlspecialchars($campaign['creator_email'] ?? 'Unknown'); ?>
                </div>
                <button type="submit" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                </button>
            </div>
        </form>
    </div>

    <!-- ════════════════════════════════════
         TAB: DEAL TERMS
    ════════════════════════════════════ -->
    <div id="tab-terms" class="tab-panel <?php echo $activeTab==='terms'?'active':''; ?>">
        <form method="POST">
            <input type="hidden" name="action" value="save">
            <!-- Also save main fields to avoid losing them -->
            <input type="hidden" name="title"             value="<?php echo htmlspecialchars($campaign['title']); ?>">
            <input type="hidden" name="tagline"           value="<?php echo htmlspecialchars($campaign['tagline'] ?? ''); ?>">
            <input type="hidden" name="campaign_type"     value="<?php echo $campaign['campaign_type']; ?>">
            <input type="hidden" name="raise_target"      value="<?php echo $campaign['raise_target']; ?>">
            <input type="hidden" name="raise_minimum"     value="<?php echo $campaign['raise_minimum']; ?>">
            <input type="hidden" name="raise_maximum"     value="<?php echo $campaign['raise_maximum'] ?? ''; ?>">
            <input type="hidden" name="min_contribution"  value="<?php echo $campaign['min_contribution']; ?>">
            <input type="hidden" name="max_contribution"  value="<?php echo $campaign['max_contribution'] ?? ''; ?>">
            <input type="hidden" name="max_contributors"  value="<?php echo $campaign['max_contributors'] ?? ''; ?>">
            <input type="hidden" name="opens_at"  value="<?php echo $campaign['opens_at'] ? date('Y-m-d\TH:i', strtotime($campaign['opens_at'])) : ''; ?>">
            <input type="hidden" name="closes_at" value="<?php echo $campaign['closes_at'] ? date('Y-m-d\TH:i', strtotime($campaign['closes_at'])) : ''; ?>">

            <?php $ct = $campaign['campaign_type']; ?>

            <!-- Revenue Share -->
            <?php if ($ct === 'revenue_share'): ?>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-percent"></i> Revenue Share Terms</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="rsp">Revenue Share % <span class="req">*</span>
                            <span class="hint-inline">— e.g. 5 = 5%</span></label>
                        <input type="number" id="rsp" name="revenue_share_percentage"
                               min="0.01" max="100" step="0.01"
                               value="<?php echo $terms['revenue_share_percentage'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="rsd">Duration (months) <span class="req">*</span></label>
                        <input type="number" id="rsd" name="revenue_share_duration_months"
                               min="1" max="240" step="1"
                               value="<?php echo $terms['revenue_share_duration_months'] ?? ''; ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Fixed Return Loan -->
            <?php if ($ct === 'fixed_return_loan'): ?>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-building-columns"></i> Fixed Return Loan Terms</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="frr">Annual Return Rate (%) <span class="req">*</span></label>
                        <input type="number" id="frr" name="fixed_return_rate"
                               min="0" max="100" step="0.01"
                               value="<?php echo $terms['fixed_return_rate'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="ltm">Loan Term (months) <span class="req">*</span></label>
                        <input type="number" id="ltm" name="loan_term_months"
                               min="1" max="360" step="1"
                               value="<?php echo $terms['loan_term_months'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="rstart">Repayment Start Date</label>
                        <input type="date" id="rstart" name="repayment_start_date"
                               value="<?php echo $terms['repayment_start_date'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="rfreq">Repayment Frequency</label>
                        <select id="rfreq" name="repayment_frequency">
                            <option value="">— Select —</option>
                            <?php foreach(['monthly'=>'Monthly','quarterly'=>'Quarterly','bi_annually'=>'Bi-Annually','at_maturity'=>'At Maturity'] as $v=>$l): ?>
                                <option value="<?php echo $v; ?>" <?php echo ($terms['repayment_frequency']??'')===$v?'selected':''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Cooperative Membership -->
            <?php if ($ct === 'cooperative_membership'): ?>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-handshake"></i> Co-op Membership Terms</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="up">Unit Price (R) <span class="req">*</span></label>
                        <input type="number" id="up" name="unit_price"
                               min="0" step="0.01"
                               value="<?php echo $terms['unit_price'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="un">Unit Name <span class="hint-inline">— e.g. "membership unit"</span></label>
                        <input type="text" id="un" name="unit_name" maxlength="100"
                               value="<?php echo htmlspecialchars($terms['unit_name'] ?? ''); ?>">
                    </div>
                    <div class="field">
                        <label for="tua">Total Units Available</label>
                        <input type="number" id="tua" name="total_units_available"
                               min="1" step="1"
                               value="<?php echo $terms['total_units_available'] ?? ''; ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Convertible Note -->
            <?php if ($ct === 'convertible_note'): ?>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-arrows-rotate"></i> Convertible Note Terms</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="vc">Valuation Cap (R)</label>
                        <input type="number" id="vc" name="valuation_cap"
                               min="0" step="1000"
                               value="<?php echo $terms['valuation_cap'] ?? ''; ?>">
                    </div>
                    <div class="field">
                        <label for="dr">Discount Rate (%) <span class="hint-inline">— on conversion</span></label>
                        <input type="number" id="dr" name="discount_rate"
                               min="0" max="100" step="0.01"
                               value="<?php echo $terms['discount_rate'] ?? ''; ?>">
                    </div>
                    <div class="field span-2">
                        <label for="ctrig">Conversion Trigger</label>
                        <input type="text" id="ctrig" name="conversion_trigger" maxlength="255"
                               placeholder="e.g. Series A round of at least R5M"
                               value="<?php echo htmlspecialchars($terms['conversion_trigger'] ?? ''); ?>">
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Donation — no special terms -->
            <?php if ($ct === 'donation'): ?>
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-heart"></i> Donation Campaign</div>
                <p style="font-size:.9rem;color:var(--text-muted);">
                    Donation campaigns have no return terms. Contributors give without expectation of financial return.
                    You may add a legal agreement template below if required.
                </p>
            </div>
            <?php endif; ?>

            <!-- Legal (all types) -->
            <div class="card">
                <div class="card-title"><i class="fa-solid fa-scale-balanced"></i> Legal</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="gl">Governing Law</label>
                        <input type="text" id="gl" name="governing_law" maxlength="100"
                               value="<?php echo htmlspecialchars($terms['governing_law'] ?? 'Republic of South Africa'); ?>">
                    </div>
                    <div class="field">
                        <label for="lat">Legal Agreement Template (URL/path)</label>
                        <input type="text" id="lat" name="legal_agreement_template" maxlength="500"
                               placeholder="/uploads/agreements/template.pdf"
                               value="<?php echo htmlspecialchars($terms['legal_agreement_template'] ?? ''); ?>">
                    </div>
                </div>
            </div>

            <!-- Hidden term fields not on this type — send empty so PHP doesn't crash -->
            <?php
            $allTermFields = ['revenue_share_percentage','revenue_share_duration_months',
                'fixed_return_rate','loan_term_months','repayment_start_date','repayment_frequency',
                'unit_price','unit_name','total_units_available',
                'valuation_cap','discount_rate','conversion_trigger'];
            $renderedTermFields = match($ct) {
                'revenue_share'          => ['revenue_share_percentage','revenue_share_duration_months'],
                'fixed_return_loan'      => ['fixed_return_rate','loan_term_months','repayment_start_date','repayment_frequency'],
                'cooperative_membership' => ['unit_price','unit_name','total_units_available'],
                'convertible_note'       => ['valuation_cap','discount_rate','conversion_trigger'],
                default                  => [],
            };
            foreach (array_diff($allTermFields, $renderedTermFields) as $f):
            ?>
                <input type="hidden" name="<?php echo $f; ?>" value="">
            <?php endforeach; ?>

            <div class="form-footer">
                <div style="font-size:.8rem;color:var(--text-muted);">
                    Terms saved alongside campaign data.
                </div>
                <button type="submit" class="btn-save">
                    <i class="fa-solid fa-floppy-disk"></i> Save Terms
                </button>
            </div>
        </form>
    </div>

    <!-- ════════════════════════════════════
         TAB: CONTRIBUTORS
    ════════════════════════════════════ -->
    <div id="tab-contributors" class="tab-panel <?php echo $activeTab==='contributors'?'active':''; ?>">
        <!-- Summary stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.85rem;margin-bottom:1.5rem;">
            <?php foreach ([
                ['Total',    $contribStats['total'],    'var(--navy)',   'fa-users'],
                ['Paid',     $contribStats['paid'],     'var(--emerald)','fa-circle-check'],
                ['Pending',  $contribStats['pending'],  '#92400e',       'fa-clock'],
                ['Refunded', $contribStats['refunded'], 'var(--red)',    'fa-rotate-left'],
            ] as [$l, $n, $c, $ic]): ?>
                <div class="stat-box">
                    <div class="sb-val" style="color:<?php echo $c; ?>;font-size:1.3rem;">
                        <i class="fa-solid <?php echo $ic; ?>" style="font-size:.9rem;margin-right:.25rem;"></i><?php echo $n; ?>
                    </div>
                    <div class="sb-lbl"><?php echo $l; ?></div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (empty($contributors)): ?>
            <div class="empty-panel"><i class="fa-solid fa-users"></i><p>No contributors yet.</p></div>
        <?php else: ?>
        <div class="card" style="padding:0;overflow:hidden;">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Contributor</th>
                        <th>Amount</th>
                        <th>Payment Method</th>
                        <th>Status</th>
                        <th>Agreement Signed</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($contributors as $con): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($con['contributor_email']); ?></td>
                        <td class="amount-cell"><?php echo fmtR((float)$con['amount']); ?></td>
                        <td style="font-size:.82rem;color:var(--text-muted);"><?php echo ucfirst(str_replace('_',' ',$con['payment_method'])); ?></td>
                        <td><?php echo conStatusBadge($con['status']); ?></td>
                        <td style="font-size:.8rem;color:var(--text-muted);">
                            <?php echo $con['agreement_signed_at'] ? '<span style="color:var(--emerald);"><i class="fa-solid fa-check"></i> ' . fmtDate($con['agreement_signed_at']) . '</span>' : '<span style="color:var(--text-light);">Not signed</span>'; ?>
                        </td>
                        <td style="font-size:.8rem;color:var(--text-muted);"><?php echo fmtDateTime($con['created_at']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════
         TAB: UPDATES / NOTES
    ════════════════════════════════════ -->
    <div id="tab-updates" class="tab-panel <?php echo $activeTab==='updates'?'active':''; ?>">

        <!-- Add update form -->
        <div class="card">
            <div class="card-title"><i class="fa-solid fa-plus"></i> Post Admin Update</div>
            <form method="POST">
                <input type="hidden" name="action" value="add_note">
                <div class="form-grid">
                    <div class="field">
                        <label for="note_type">Update Type</label>
                        <select id="note_type" name="note_type">
                            <?php foreach ([
                                'general'=>'General','financial'=>'Financial Report',
                                'milestone'=>'Milestone','payout'=>'Payout',
                                'issue'=>'Issue / Delay','campaign_closed'=>'Campaign Closed',
                            ] as $v=>$l): ?>
                                <option value="<?php echo $v; ?>"><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="note_title">Title <span class="req">*</span></label>
                        <input type="text" id="note_title" name="note_title" maxlength="255"
                               placeholder="Update headline…" required>
                    </div>
                    <div class="field span-2">
                        <label for="note_body">Body <span class="req">*</span></label>
                        <textarea id="note_body" name="note_body" rows="4"
                                  placeholder="Full update content…" required></textarea>
                    </div>
                </div>
                <div style="display:flex;align-items:center;justify-content:space-between;margin-top:1rem;flex-wrap:wrap;gap:.75rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;font-size:.87rem;font-weight:500;cursor:pointer;">
                        <input type="checkbox" name="note_public" value="1">
                        Make this update <strong>public</strong> (visible on the listing page)
                    </label>
                    <button type="submit" class="btn-save" style="padding:.6rem 1.5rem;">
                        <i class="fa-solid fa-paper-plane"></i> Post Update
                    </button>
                </div>
            </form>
        </div>

        <!-- Existing updates -->
        <?php if (empty($updates)): ?>
            <div class="empty-panel"><i class="fa-solid fa-bullhorn"></i><p>No updates posted yet.</p></div>
        <?php else: ?>
        <div class="card">
            <?php foreach ($updates as $upd): ?>
                <div class="update-item">
                    <div class="update-icon">
                        <i class="fa-solid <?php echo updateTypeIcon($upd['update_type']); ?>"></i>
                    </div>
                    <div class="update-body">
                        <div class="update-title">
                            <?php echo htmlspecialchars($upd['title']); ?>
                            <span class="pub-badge <?php echo $upd['is_public'] ? 'public' : 'private'; ?>">
                                <?php echo $upd['is_public'] ? 'Public' : 'Private'; ?>
                            </span>
                            <span style="font-size:.72rem;font-weight:400;color:var(--text-light);background:var(--surface-2);border:1px solid var(--border);padding:.1rem .5rem;border-radius:6px;">
                                <?php echo ucfirst(str_replace('_',' ',$upd['update_type'])); ?>
                            </span>
                        </div>
                        <div class="update-meta">
                            <?php echo htmlspecialchars($upd['author_email']); ?>
                            · <?php echo fmtDateTime($upd['created_at']); ?>
                        </div>
                        <div class="update-text"><?php echo nl2br(htmlspecialchars($upd['body'])); ?></div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- ════════════════════════════════════
         TAB: Q&A
    ════════════════════════════════════ -->
    <div id="tab-qa" class="tab-panel <?php echo $activeTab==='qa'?'active':''; ?>">
        <?php if (empty($questions)): ?>
            <div class="empty-panel"><i class="fa-solid fa-circle-question"></i><p>No questions yet.</p></div>
        <?php else: ?>
        <div class="card">
            <?php foreach ($questions as $q): ?>
                <div class="qa-item">
                    <div class="qa-q"><?php echo htmlspecialchars($q['question']); ?></div>
                    <div class="qa-meta">
                        Asked by <strong><?php echo htmlspecialchars($q['asker_email']); ?></strong>
                        · <?php echo fmtDateTime($q['asked_at']); ?>
                        <?php if (!$q['is_public']): ?>
                            <span style="background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:.05rem .45rem;font-size:.68rem;color:var(--text-muted);margin-left:.3rem;">Private</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($q['answer']): ?>
                        <div class="qa-a">
                            <?php echo nl2br(htmlspecialchars($q['answer'])); ?>
                            <div class="qa-a-meta">
                                Answered by <?php echo htmlspecialchars($q['answerer_email'] ?? 'Admin'); ?>
                                · <?php echo fmtDateTime($q['answered_at']); ?>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="answer-form">
                            <form method="POST">
                                <input type="hidden" name="action"      value="answer_question">
                                <input type="hidden" name="question_id" value="<?php echo $q['id']; ?>">
                                <textarea name="answer" placeholder="Type your answer…" required></textarea>
                                <button type="submit" class="btn-answer">
                                    <i class="fa-solid fa-reply"></i> Post Answer
                                </button>
                            </form>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

</main>

<script>
/* ── Mobile sidebar ─────────────────────── */
const sidebar = document.getElementById('sidebar');
const overlay = document.getElementById('sidebarOverlay');
const menuBtn = document.getElementById('menuBtn');
function openSidebar()  { sidebar.classList.add('open'); overlay.classList.add('open'); menuBtn.innerHTML='<i class="fa-solid fa-xmark"></i>'; }
function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); menuBtn.innerHTML='<i class="fa-solid fa-bars"></i>'; }
menuBtn.addEventListener('click', () => sidebar.classList.contains('open') ? closeSidebar() : openSidebar());
overlay.addEventListener('click', closeSidebar);

/* ── Tab switching ──────────────────────── */
function switchTab(name, btn) {
    document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
    document.getElementById('tab-' + name).classList.add('active');
    btn.classList.add('active');
    history.replaceState(null,'', '?id=<?php echo $campaignId; ?>&tab=' + name);
}

/* ── Toast auto-dismiss ─────────────────── */
const toast = document.querySelector('.toast');
if (toast) {
    window.scrollTo({ top: 0, behavior: 'smooth' });
    setTimeout(() => { toast.style.transition='opacity .4s'; toast.style.opacity='0'; setTimeout(()=>toast.remove(),400); }, 5000);
}

/* ── Validate closes > opens ────────────── */
document.querySelectorAll('form').forEach(f => {
    f.addEventListener('submit', function() {
        const opens  = f.querySelector('[name="opens_at"]');
        const closes = f.querySelector('[name="closes_at"]');
        if (opens && closes && opens.value && closes.value) {
            if (new Date(closes.value) <= new Date(opens.value)) {
                alert('Closing date must be after the opening date.');
                return false;
            }
        }
    });
});
</script>
</body>
</html>
