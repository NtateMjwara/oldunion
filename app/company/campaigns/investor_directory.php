<?php
/**
 * /app/company/campaigns/investor_directory.php
 *
 * US-102 — Founder Investor Discovery Feed
 *
 * Authenticated company admins browse opted-in investors and send
 * campaign-specific invitations. Writes to campaign_invites and
 * notifications; logs to compliance_events (US-105).
 *
 * PRIVACY RULES (enforced here, not just in the schema):
 *   • Only users where user_investor_preferences.opt_in_directory = 1
 *     are ever queried.
 *   • No joins to user_wallets, wallet_transactions, contributions,
 *     or any financial table.
 *   • Email addresses are masked; only display_handle is shown.
 *
 * Dependencies (concurrent with US-101):
 *   • campaign_invites table (migration_us101_campaign_invites.sql)
 *   • user_investor_preferences table (migration_us102_*.sql)
 *   • notifications table (migration_us102_*.sql)
 */

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';
require_once '../../includes/functions.php';
require_once '../../includes/mailer.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$companyUuid  = trim($_GET['uuid'] ?? '');
$campaignUuid = trim($_GET['cid']  ?? '');

if (empty($companyUuid) || empty($campaignUuid)) {
    redirect('/app/company/');
}

$company = getCompanyByUuid($companyUuid);
if (!$company) { redirect('/app/company/'); }

// Only verified, active companies can access the directory
if ($company['status'] !== 'active' || !$company['verified']) {
    redirect("/app/company/dashboard.php?uuid=$companyUuid&error=not_verified");
}

requireCompanyRole($company['id'], 'admin');

$pdo          = Database::getInstance();
$userId       = (int)$_SESSION['user_id'];
$companyId    = (int)$company['id'];
$csrf_token   = generateCSRFToken();
$errors       = [];
$successMsg   = '';

/* ── Load campaign ───────────────────────────────────────── */
$stmt = $pdo->prepare("
    SELECT id, uuid, title, status, max_contributors, contributor_count
    FROM funding_campaigns
    WHERE uuid = :uuid AND company_id = :cid
");
$stmt->execute(['uuid' => $campaignUuid, 'cid' => $companyId]);
$campaign = $stmt->fetch();
if (!$campaign) {
    redirect('/app/company/campaigns/index.php?uuid=' . urlencode($companyUuid));
}

if (in_array($campaign['status'], ['cancelled', 'suspended', 'closed_unsuccessful', 'closed_successful'], true)) {
    redirect('/app/company/campaigns/manage.php?uuid=' . urlencode($companyUuid) . '&cid=' . urlencode($campaignUuid));
}

$campaignId     = (int)$campaign['id'];
$slotsTotal     = (int)$campaign['max_contributors'];
$slotsUsed      = (int)$campaign['contributor_count'];
// Count accepted invites (pending slots)
$stmt = $pdo->prepare("
    SELECT COUNT(*) FROM campaign_invites
    WHERE campaign_id = ? AND status IN ('pending','accepted')
");
$stmt->execute([$campaignId]);
$slotsIssued = (int)$stmt->fetchColumn();
$slotsAvailable = max(0, $slotsTotal - $slotsIssued);

/* ── POST: send invite ───────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $inviteUserId = (int)($_POST['invite_user_id'] ?? 0);

        if ($inviteUserId <= 0) {
            $errors[] = 'Invalid investor selected.';
        } elseif ($slotsAvailable <= 0) {
            $errors[] = 'No invitation slots remaining for this campaign.';
        } else {
            // Confirm investor exists and is opted in
            $stmt = $pdo->prepare("
                SELECT u.id, u.email, uip.display_handle
                FROM users u
                JOIN user_investor_preferences uip ON uip.user_id = u.id
                WHERE u.id = :uid AND uip.opt_in_directory = 1
            ");
            $stmt->execute(['uid' => $inviteUserId]);
            $investor = $stmt->fetch();

            if (!$investor) {
                $errors[] = 'Investor not found or not opted in to the directory.';
            } else {
                // Check not already invited to this campaign
                $stmt = $pdo->prepare("
                    SELECT status FROM campaign_invites
                    WHERE campaign_id = ? AND user_id = ?
                ");
                $stmt->execute([$campaignId, $inviteUserId]);
                $existing = $stmt->fetch();

                if ($existing) {
                    $errors[] = 'This investor has already been invited (status: ' . $existing['status'] . ').';
                } elseif ($inviteUserId === $userId) {
                    $errors[] = 'You cannot invite yourself.';
                } else {
                    $pdo->beginTransaction();
                    try {
                        $token     = generateToken(); // bin2hex(random_bytes(32))
                        $expiresAt = date('Y-m-d H:i:s', time() + 72 * 3600); // 72 hours

                        // Insert campaign invite
                        $inviteUuid = generateUuidV4();
                        $pdo->prepare("
                            INSERT INTO campaign_invites
                                (uuid, campaign_id, user_id, invited_by,
                                 status, expires_at)
                            VALUES
                                (:uuid, :cid, :uid, :by,
                                 'pending', :exp)
                        ")->execute([
                            'uuid' => $inviteUuid,
                            'cid'  => $campaignId,
                            'uid'  => $inviteUserId,
                            'by'   => $userId,
                            'exp'  => $expiresAt,
                        ]);
                        $inviteDbId = (int)$pdo->lastInsertId();

                        // In-app notification for investor
                        $notifTitle = 'New Investment Invitation: ' . $campaign['title'];
                        $notifBody  = $company['name'] . ' has invited you to a private investment opportunity.';
                        $notifLink  = '/app/invest/accept_invite.php?token=' . urlencode($inviteUuid);

                        $pdo->prepare("
                            INSERT INTO notifications
                                (user_id, type, title, body, link, meta_json)
                            VALUES
                                (:uid, 'deal_invite', :title, :body, :link, :meta)
                        ")->execute([
                            'uid'   => $inviteUserId,
                            'title' => $notifTitle,
                            'body'  => $notifBody,
                            'link'  => $notifLink,
                            'meta'  => json_encode([
                                'campaign_uuid' => $campaignUuid,
                                'company_name'  => $company['name'],
                                'invite_uuid'   => $inviteUuid,
                            ]),
                        ]);

                        // Compliance audit log (US-105)
                        // Writes to compliance_events if the table exists; gracefully skips if not.
                        try {
                            $pdo->prepare("
                                INSERT INTO compliance_events
                                    (event_type, actor_id, target_id, campaign_id,
                                     ip_address, created_at)
                                VALUES
                                    ('invite_sent', :actor, :target, :cid,
                                     :ip, NOW())
                            ")->execute([
                                'actor'  => $userId,
                                'target' => $inviteUserId,
                                'cid'    => $campaignId,
                                'ip'     => $_SERVER['REMOTE_ADDR'] ?? '',
                            ]);
                        } catch (PDOException $e) {
                            // compliance_events table not yet created (US-105); log and continue
                            error_log('[investor_directory] compliance_events write skipped: ' . $e->getMessage());
                        }

                        $pdo->commit();

                        // Send invitation email
                        $inviteLink = SITE_URL . '/app/invest/accept_invite.php?token=' . urlencode($inviteUuid);
                        $emailBody  = sendCampaignInviteEmail(
                            $investor['email'],
                            $company['name'],
                            $campaign['title'],
                            $inviteLink,
                            $expiresAt
                        );

                        $handle      = $investor['display_handle'] ?: maskEmailForDisplay($investor['email']);
                        $successMsg  = "Invitation sent to <strong>" . htmlspecialchars($handle) . "</strong>. They have 72 hours to accept.";
                        $slotsIssued++;
                        $slotsAvailable = max(0, $slotsTotal - $slotsIssued);

                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('[investor_directory] invite failed: ' . $e->getMessage());
                        $errors[] = 'Could not send invitation. Please try again.';
                    }
                }
            }
        }
    }
}

/* ── Load invited investor IDs for this campaign ─────────── */
$stmt = $pdo->prepare("
    SELECT user_id, status FROM campaign_invites WHERE campaign_id = ?
");
$stmt->execute([$campaignId]);
$inviteStatusByUser = [];
foreach ($stmt->fetchAll() as $row) {
    $inviteStatusByUser[(int)$row['user_id']] = $row['status'];
}

/* ── Filters ─────────────────────────────────────────────── */
$filterSector = trim($_GET['sector'] ?? '');
$filterType   = trim($_GET['type']   ?? '');
$filterArea   = trim($_GET['area']   ?? '');
$searchQuery  = trim($_GET['q']      ?? '');

/* ── Query opted-in investors (PRIVACY: no financial columns) */
$where  = ['uip.opt_in_directory = 1', 'u.status = "active"'];
$params = [];

if ($searchQuery !== '') {
    $where[]          = '(uip.display_handle LIKE :q OR uip.preferences_json LIKE :q2)';
    $params['q']      = '%' . $searchQuery . '%';
    $params['q2']     = '%' . $searchQuery . '%';
}
// Sector filter — JSON contains check (MySQL 5.7+)
if ($filterSector !== '') {
    $where[]             = "JSON_CONTAINS(uip.preferences_json->'$.sectors', :sec)";
    $params['sec']       = json_encode($filterSector);
}
if ($filterType !== '') {
    $where[]             = "JSON_CONTAINS(uip.preferences_json->'$.campaign_types', :typ)";
    $params['typ']       = json_encode($filterType);
}
if ($filterArea !== '') {
    $where[]             = "JSON_CONTAINS(uip.preferences_json->'$.area_preferences', :area)";
    $params['area']      = json_encode($filterArea);
}

$whereSQL = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        u.id                              AS user_id,
        uip.display_handle,
        uip.preferences_json,
        uip.total_investments,
        uip.updated_at
    FROM user_investor_preferences uip
    JOIN users u ON u.id = uip.user_id
    {$whereSQL}
    ORDER BY uip.total_investments DESC, uip.updated_at DESC
    LIMIT 80
");
$stmt->execute($params);
$investors = $stmt->fetchAll();

/* ── Helpers ─────────────────────────────────────────────── */
function maskEmailForDisplay(string $email): string {
    [$local] = explode('@', $email, 2) + [''];
    if (mb_strlen($local) <= 1) return 'Investor';
    return $local[0] . str_repeat('*', max(2, mb_strlen($local) - 1)) . '@…';
}

function inviteStatusBadge(string $status): string {
    $map = [
        'pending'  => ['Invited',  'ib-pending',  'fa-clock'],
        'accepted' => ['Accepted', 'ib-accepted', 'fa-circle-check'],
        'declined' => ['Declined', 'ib-declined', 'fa-circle-xmark'],
        'revoked'  => ['Revoked',  'ib-revoked',  'fa-ban'],
    ];
    $info = $map[$status] ?? [$status, 'ib-pending', 'fa-question'];
    return '<span class="invite-badge ' . $info[1] . '"><i class="fa-solid ' . $info[2] . '"></i>' . $info[0] . '</span>';
}

$allSectors = [
    'Technology & Software', 'Fintech & Financial Services',
    'Healthcare & Biotech', 'Education & EdTech',
    'E-Commerce & Retail', 'Agriculture & AgriTech',
    'Energy & CleanTech', 'Real Estate & PropTech',
    'Media & Entertainment', 'Logistics & Supply Chain',
    'Food & Beverage', 'Manufacturing',
    'Consulting & Professional Services',
    'Non-Profit & Social Impact', 'Other',
];

// User info for avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$me          = $stmt->fetch();
$userInitial = $me ? strtoupper(substr($me['email'], 0, 1)) : 'U';

// Slot colour class
$slotClass = $slotsAvailable > 10
    ? 'slots-green'
    : ($slotsAvailable > 0 ? 'slots-amber' : 'slots-red');

$baseUrl = '/app/company/campaigns/investor_directory.php?uuid='
         . urlencode($companyUuid) . '&cid=' . urlencode($campaignUuid);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Investor Directory — <?php echo htmlspecialchars($campaign['title']); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
    :root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--radius:14px;--radius-sm:8px;--shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);--header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);}
    *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
    body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}

    /* Header */
    .top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
    .logo{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
    .logo span{color:#c8102e;}
    .header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
    .header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);}
    .header-nav a:hover{background:var(--surface-2);color:var(--text);}
    .header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
    .avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}

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
    .breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
    .breadcrumb a{color:var(--navy-light);text-decoration:none;}.breadcrumb a:hover{color:var(--navy);}
    .breadcrumb i{font-size:.65rem;}

    /* Page head */
    .page-head{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:1.5rem;gap:1rem;flex-wrap:wrap;}
    .page-head h1{font-family:'DM Serif Display',serif;font-size:1.6rem;color:var(--navy);margin-bottom:.25rem;}
    .page-head p{font-size:.88rem;color:var(--text-muted);line-height:1.5;}

    /* Slot meter */
    .slot-meter{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1rem 1.25rem;margin-bottom:1.5rem;display:flex;align-items:center;gap:1.25rem;flex-wrap:wrap;}
    .slot-meter-bar-wrap{flex:1;min-width:160px;}
    .slot-meter-label{font-size:.77rem;font-weight:600;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:.4rem;display:flex;justify-content:space-between;}
    .slot-meter-bar{height:8px;background:var(--surface-2);border-radius:99px;overflow:hidden;border:1px solid var(--border);}
    .slot-meter-fill{height:100%;border-radius:99px;transition:width .4s ease;}
    .slots-green .slot-meter-fill{background:var(--green);}
    .slots-amber .slot-meter-fill{background:var(--amber);}
    .slots-red   .slot-meter-fill{background:var(--error);}
    .slot-stat{text-align:center;flex-shrink:0;}
    .slot-stat-val{font-family:'DM Serif Display',serif;font-size:1.75rem;color:var(--navy);line-height:1;}
    .slot-stat-lbl{font-size:.72rem;color:var(--text-muted);margin-top:.1rem;}
    .slots-red .slot-stat-val{color:var(--error);}
    .slots-amber .slot-stat-val{color:var(--amber-dark);}
    .slots-green .slot-stat-val{color:var(--green);}

    /* Filter bar */
    .filter-bar{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);padding:.85rem 1.1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;}
    .filter-bar form{display:contents;}
    .search-wrap{position:relative;flex:1;min-width:200px;}
    .search-wrap i{position:absolute;left:.8rem;top:50%;transform:translateY(-50%);color:var(--text-light);font-size:.82rem;pointer-events:none;}
    .search-input{width:100%;padding:.48rem .9rem .48rem 2.1rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.84rem;color:var(--text);background:var(--surface-2);outline:none;transition:all var(--transition);}
    .search-input:focus{border-color:var(--navy-light);background:#fff;box-shadow:0 0 0 3px rgba(26,86,176,.08);}
    .filter-select{padding:.45rem .85rem;border:1.5px solid var(--border);border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.82rem;font-weight:500;color:var(--text-muted);background:var(--surface-2);outline:none;cursor:pointer;appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='10' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right .7rem center;padding-right:2rem;}
    .filter-select:focus,.filter-select.active{border-color:var(--navy-light);color:var(--navy-mid);}
    .result-count{font-size:.8rem;color:var(--text-light);margin-left:auto;}

    /* Alerts */
    .alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
    .alert i{flex-shrink:0;margin-top:.05rem;}
    .alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .alert-error  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}

    /* Investor grid */
    .investor-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;}

    /* Investor card */
    .investor-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-sm);box-shadow:var(--shadow);padding:1.1rem 1.25rem;display:flex;flex-direction:column;gap:.75rem;transition:border-color var(--transition),box-shadow var(--transition);}
    .investor-card:hover{border-color:#c7d9f8;box-shadow:0 6px 20px rgba(11,37,69,.1);}
    .investor-card.is-invited{border-color:var(--green-bdr);background:var(--green-bg);}
    .investor-card.is-declined{opacity:.6;}

    .investor-card-top{display:flex;align-items:flex-start;gap:.75rem;}
    .investor-avatar{width:42px;height:42px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:1rem;flex-shrink:0;}
    .investor-handle{font-size:.95rem;font-weight:600;color:var(--navy);margin-bottom:.15rem;}
    .investor-bio{font-size:.8rem;color:var(--text-muted);line-height:1.5;}
    .investor-investments{font-size:.74rem;color:var(--text-light);margin-top:.15rem;}

    /* Preference chips */
    .pref-chips{display:flex;flex-wrap:wrap;gap:.35rem;}
    .pref-chip{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
    .pc-sector{background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;}
    .pc-type  {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .pc-area  {background:var(--amber-light);color:#78350f;border-color:var(--amber);}

    /* Cheque range */
    .cheque-range{font-size:.78rem;color:var(--text-muted);display:flex;align-items:center;gap:.35rem;}

    /* Invite action row */
    .invite-action{display:flex;align-items:center;justify-content:space-between;padding-top:.65rem;border-top:1px solid var(--border);gap:.5rem;flex-wrap:wrap;}

    /* Invite status badge */
    .invite-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .65rem;border-radius:99px;font-size:.73rem;font-weight:600;border:1px solid transparent;}
    .ib-pending {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
    .ib-accepted{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
    .ib-declined{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
    .ib-revoked {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}

    /* Buttons */
    .btn{display:inline-flex;align-items:center;gap:.4rem;padding:.5rem 1.1rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
    .btn-navy{background:var(--navy-mid);color:#fff;}
    .btn-navy:hover:not(:disabled){background:var(--navy);}
    .btn-navy:disabled{opacity:.4;cursor:not-allowed;}
    .btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
    .btn-ghost:hover{border-color:#94a3b8;color:var(--text);}
    .btn-sm{padding:.35rem .85rem;font-size:.78rem;}

    /* Empty state */
    .empty-state{text-align:center;padding:4rem 2rem;grid-column:1/-1;}
    .empty-state i{font-size:2.5rem;color:var(--border);margin-bottom:1rem;display:block;}
    .empty-state h3{font-family:'DM Serif Display',serif;font-size:1.3rem;color:var(--navy);margin-bottom:.5rem;}
    .empty-state p{font-size:.88rem;color:var(--text-muted);}

    /* Privacy notice */
    .privacy-strip{background:var(--navy);border-radius:var(--radius-sm);padding:.75rem 1.1rem;display:flex;align-items:flex-start;gap:.65rem;font-size:.79rem;color:rgba(255,255,255,.65);margin-bottom:1.25rem;line-height:1.5;}
    .privacy-strip i{color:var(--amber);flex-shrink:0;margin-top:.1rem;}

    /* Responsive */
    @media(max-width:1024px){.header-nav{display:none;}.main-content{padding:1.5rem;}}
    @media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}.filter-bar{flex-wrap:wrap;}}
    @media(max-width:600px){.investor-grid{grid-template-columns:1fr;}}
    ::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
    </style>
</head>
<body>

<header class="top-header">
    <a href="/app/company/" class="logo">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/app/"><i class="fa-solid fa-home"></i> Home</a>
        <a href="/app/company/" class="active"><i class="fa-solid fa-building"></i> Companies</a>
        <a href="/app/wallet/"><i class="fa-solid fa-wallet"></i> Wallet</a>
    </nav>
    <div class="avatar"><?php echo htmlspecialchars($userInitial); ?></div>
</header>

<div class="page-wrapper">
    <aside class="sidebar">
        <div class="sidebar-section-label">Company</div>
        <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-gauge"></i> Overview
        </a>
        <div class="sidebar-section-label">Fundraising</div>
        <a href="/app/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>">
            <i class="fa-solid fa-rocket"></i> Campaigns
        </a>
        <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-sliders"></i> Manage Campaign
        </a>
        <a href="/app/company/campaigns/investor_directory.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>" class="active">
            <i class="fa-solid fa-users"></i> Investor Directory
        </a>
        <!-- US-103 -->
        <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>">
            <i class="fa-solid fa-envelope-open-text"></i> External Invites
        </a>
        <div class="sidebar-section-label">Account</div>
        <a href="/app/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <main class="main-content">

        <!-- Breadcrumb -->
        <div class="breadcrumb">
            <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($companyUuid); ?>">
                <i class="fa-solid fa-gauge"></i> <?php echo htmlspecialchars($company['name']); ?>
            </a>
            <i class="fa-solid fa-chevron-right"></i>
            <a href="/app/company/campaigns/index.php?uuid=<?php echo urlencode($companyUuid); ?>">Campaigns</a>
            <i class="fa-solid fa-chevron-right"></i>
            <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"><?php echo htmlspecialchars($campaign['title']); ?></a>
            <i class="fa-solid fa-chevron-right"></i>
            Investor Directory
        </div>

        <div class="page-head">
            <div>
                <h1>Investor Directory</h1>
                <p>Browse investors who have opted in to be discoverable. Send them a private, campaign-specific invitation — never publicly advertised.</p>
            </div>
            <a href="/app/company/campaigns/manage.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>&tab=invites"
               class="btn btn-ghost btn-sm">
                <i class="fa-solid fa-list-check"></i> View Sent Invites
            </a>
        </div>

        <!-- Privacy assurance -->
        <div class="privacy-strip">
            <i class="fa-solid fa-shield-halved"></i>
            <div>
                <strong style="color:#fff;">Privacy protected.</strong>
                Only investors who have explicitly opted in to this directory are shown below. You see their handle, preferences, and investment range only — no email addresses, balances, or account data are disclosed to you. Old Union acts as a facilitator; each invitation requires the investor's explicit acceptance.
            </div>
        </div>

        <!-- Slot meter -->
        <div class="slot-meter <?php echo $slotClass; ?>">
            <div class="slot-meter-bar-wrap">
                <div class="slot-meter-label">
                    <span>Invitation Slots</span>
                    <span><?php echo $slotsIssued; ?> / <?php echo $slotsTotal; ?> issued</span>
                </div>
                <div class="slot-meter-bar">
                    <div class="slot-meter-fill" style="width:<?php echo min(100, round($slotsIssued / max(1,$slotsTotal) * 100)); ?>%"></div>
                </div>
            </div>
            <div class="slot-stat">
                <div class="slot-stat-val"><?php echo $slotsAvailable; ?></div>
                <div class="slot-stat-lbl">slots left</div>
            </div>
            <div class="slot-stat">
                <div class="slot-stat-val"><?php echo $slotsUsed; ?></div>
                <div class="slot-stat-lbl">confirmed</div>
            </div>
            <div class="slot-stat">
                <div class="slot-stat-val"><?php echo $slotsTotal; ?></div>
                <div class="slot-stat-lbl">max (SA law)</div>
            </div>
        </div>

        <!-- Alerts -->
        <?php if (!empty($successMsg)): ?>
            <div class="alert alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <div><?php echo $successMsg; ?></div>
            </div>
        <?php endif; ?>
        <?php if (!empty($errors)): ?>
            <div class="alert alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($errors[0]); ?>
            </div>
        <?php endif; ?>

        <!-- Filter bar -->
        <div class="filter-bar">
            <form method="GET" id="filterForm" style="display:contents;">
                <input type="hidden" name="uuid" value="<?php echo htmlspecialchars($companyUuid); ?>">
                <input type="hidden" name="cid"  value="<?php echo htmlspecialchars($campaignUuid); ?>">

                <div class="search-wrap">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" name="q" class="search-input"
                        placeholder="Search handles or preferences…"
                        value="<?php echo htmlspecialchars($searchQuery); ?>"
                        onchange="document.getElementById('filterForm').submit()">
                </div>

                <select name="sector" class="filter-select <?php echo $filterSector ? 'active' : ''; ?>"
                        onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Sectors</option>
                    <?php foreach ($allSectors as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>"
                            <?php echo $filterSector === $s ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select name="type" class="filter-select <?php echo $filterType ? 'active' : ''; ?>"
                        onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Types</option>
                    <option value="revenue_share"          <?php echo $filterType === 'revenue_share' ? 'selected' : ''; ?>>Revenue Share</option>
                    <option value="cooperative_membership" <?php echo $filterType === 'cooperative_membership' ? 'selected' : ''; ?>>Co-op Membership</option>
                </select>

                <select name="area" class="filter-select <?php echo $filterArea ? 'active' : ''; ?>"
                        onchange="document.getElementById('filterForm').submit()">
                    <option value="">All Areas</option>
                    <option value="urban"    <?php echo $filterArea === 'urban' ? 'selected' : ''; ?>>Urban</option>
                    <option value="township" <?php echo $filterArea === 'township' ? 'selected' : ''; ?>>Township</option>
                    <option value="rural"    <?php echo $filterArea === 'rural' ? 'selected' : ''; ?>>Rural</option>
                </select>

                <?php if ($searchQuery || $filterSector || $filterType || $filterArea): ?>
                    <a href="<?php echo htmlspecialchars($baseUrl); ?>"
                       style="font-size:.8rem;color:var(--navy-light);font-weight:600;text-decoration:none;">
                        <i class="fa-solid fa-xmark"></i> Clear
                    </a>
                <?php endif; ?>

                <span class="result-count"><?php echo count($investors); ?> investor<?php echo count($investors) !== 1 ? 's' : ''; ?></span>
            </form>
        </div>

        <!-- Investor grid -->
        <div class="investor-grid">
            <?php if (empty($investors)): ?>
                <div class="empty-state">
                    <i class="fa-solid fa-users-slash"></i>
                    <h3>No investors found</h3>
                    <p>No investors match your current filters, or none have opted in to the directory yet.</p>
                </div>
            <?php else: ?>
                <?php foreach ($investors as $inv):
                    $invUserId  = (int)$inv['user_id'];
                    $invStatus  = $inviteStatusByUser[$invUserId] ?? null;
                    $isInvited  = $invStatus !== null;
                    $isAccepted = $invStatus === 'accepted';
                    $isDeclined = $invStatus === 'declined';
                    $cardClass  = $isAccepted ? 'is-invited' : ($isDeclined ? 'is-declined' : '');

                    $prefs       = json_decode($inv['preferences_json'] ?? '{}', true) ?: [];
                    $handle      = $inv['display_handle'] ?: 'Investor';
                    $bio         = $prefs['bio'] ?? '';
                    $sectors     = $prefs['sectors'] ?? [];
                    $types       = $prefs['campaign_types'] ?? [];
                    $areas       = $prefs['area_preferences'] ?? [];
                    $minCheque   = $prefs['min_cheque'] ?? null;
                    $maxCheque   = $prefs['max_cheque'] ?? null;
                    $totalInvest = (int)$inv['total_investments'];

                    $typeLabels = ['revenue_share'=>'Rev. Share','cooperative_membership'=>'Co-op'];
                    $areaLabels = ['urban'=>'Urban','township'=>'Township','rural'=>'Rural'];
                ?>
                <div class="investor-card <?php echo $cardClass; ?>">
                    <div class="investor-card-top">
                        <div class="investor-avatar"><?php echo strtoupper(substr($handle, 0, 1)); ?></div>
                        <div style="flex:1;min-width:0;">
                            <div class="investor-handle"><?php echo htmlspecialchars($handle); ?></div>
                            <?php if ($bio): ?>
                                <div class="investor-bio"><?php echo htmlspecialchars($bio); ?></div>
                            <?php endif; ?>
                            <?php if ($totalInvest > 0): ?>
                                <div class="investor-investments">
                                    <i class="fa-solid fa-suitcase" style="font-size:.7rem;"></i>
                                    <?php echo $totalInvest; ?> prior investment<?php echo $totalInvest !== 1 ? 's' : ''; ?> on platform
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Preference chips -->
                    <?php if ($sectors || $types || $areas): ?>
                    <div class="pref-chips">
                        <?php foreach (array_slice($sectors, 0, 2) as $s): ?>
                            <span class="pref-chip pc-sector"><i class="fa-solid fa-tag" style="font-size:.65rem;"></i><?php echo htmlspecialchars($s); ?></span>
                        <?php endforeach; ?>
                        <?php if (count($sectors) > 2): ?>
                            <span class="pref-chip pc-sector">+<?php echo count($sectors) - 2; ?></span>
                        <?php endif; ?>
                        <?php foreach ($types as $t): ?>
                            <span class="pref-chip pc-type"><?php echo $typeLabels[$t] ?? $t; ?></span>
                        <?php endforeach; ?>
                        <?php foreach ($areas as $a): ?>
                            <span class="pref-chip pc-area"><?php echo $areaLabels[$a] ?? $a; ?></span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- Cheque range -->
                    <?php if ($minCheque || $maxCheque): ?>
                    <div class="cheque-range">
                        <i class="fa-solid fa-coins" style="font-size:.72rem;color:var(--text-light);"></i>
                        <?php
                        if ($minCheque && $maxCheque) {
                            echo 'R ' . number_format($minCheque, 0, '.', ' ')
                               . ' – R ' . number_format($maxCheque, 0, '.', ' ');
                        } elseif ($minCheque) {
                            echo 'From R ' . number_format($minCheque, 0, '.', ' ');
                        } else {
                            echo 'Up to R ' . number_format($maxCheque, 0, '.', ' ');
                        }
                        ?>
                    </div>
                    <?php endif; ?>

                    <!-- Invite action -->
                    <div class="invite-action">
                        <?php if ($isInvited): ?>
                            <?php echo inviteStatusBadge($invStatus); ?>
                            <?php if ($isAccepted): ?>
                                <span style="font-size:.76rem;color:var(--green);">
                                    <i class="fa-solid fa-circle-check"></i> Accepted your invite
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="font-size:.76rem;color:var(--text-light);">Not yet invited</span>
                            <?php if ($slotsAvailable > 0): ?>
                                <form method="POST" style="display:inline;">
                                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                                    <input type="hidden" name="invite_user_id" value="<?php echo $invUserId; ?>">
                                    <button type="submit" class="btn btn-navy btn-sm"
                                        onclick="return confirm('Send a private investment invitation to <?php echo addslashes(htmlspecialchars($handle)); ?>?')">
                                        <i class="fa-solid fa-paper-plane"></i> Invite
                                    </button>
                                </form>
                            <?php else: ?>
                                <button class="btn btn-navy btn-sm" disabled>
                                    <i class="fa-solid fa-lock"></i> No slots
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <!-- ── US-103: Invite by Email callout ─────────────────────────────
             For people who are not yet on the platform, founders use the
             External Invites page — a dedicated flow with token generation,
             compliance logging, and resend management.
        ────────────────────────────────────────────────────────────────── -->
        <?php
        // Count outstanding external invites for this campaign
        $extInviteCount = 0;
        try {
            $s = $pdo->prepare("
                SELECT COUNT(*) FROM campaign_invites
                WHERE campaign_id = ? AND invite_source = 'external_email'
            ");
            $s->execute([$campaignId]);
            $extInviteCount = (int)$s->fetchColumn();
        } catch (PDOException $e) { /* table not yet migrated */ }
        ?>
        <div style="margin-top:2rem;background:var(--navy);border-radius:var(--radius-sm);
                    padding:1.25rem 1.5rem;display:flex;align-items:center;
                    justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div style="display:flex;align-items:flex-start;gap:.75rem;">
                <i class="fa-solid fa-envelope-open-text"
                   style="color:var(--amber);font-size:1.1rem;margin-top:.15rem;flex-shrink:0;"></i>
                <div>
                    <div style="font-size:.9rem;font-weight:600;color:#fff;margin-bottom:.2rem;">
                        Investor not on Old Union yet?
                    </div>
                    <div style="font-size:.8rem;color:rgba(255,255,255,.6);line-height:1.5;">
                        Send a signed, time-limited invitation link directly to their email address.
                        They'll be prompted to create a free account and are automatically linked to
                        this campaign when they verify.
                        <?php if ($extInviteCount > 0): ?>
                            <strong style="color:var(--amber);">
                                <?php echo $extInviteCount; ?> external invite<?php echo $extInviteCount !== 1 ? 's' : ''; ?> sent for this campaign.
                            </strong>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <a href="/app/company/campaigns/external_invite.php?uuid=<?php echo urlencode($companyUuid); ?>&cid=<?php echo urlencode($campaignUuid); ?>"
               style="display:inline-flex;align-items:center;gap:.45rem;padding:.65rem 1.25rem;
                      background:var(--amber);color:var(--navy);border-radius:99px;
                      font-size:.87rem;font-weight:700;text-decoration:none;flex-shrink:0;
                      transition:background .2s;"
               onmouseover="this.style.background='#d97706';this.style.color='#fff'"
               onmouseout="this.style.background='var(--amber)';this.style.color='var(--navy)'">
                <i class="fa-solid fa-paper-plane"></i>
                <?php echo $extInviteCount > 0 ? 'Manage External Invites' : 'Invite by Email'; ?>
            </a>
        </div>

    </main>
</div>

</body>
</html>
