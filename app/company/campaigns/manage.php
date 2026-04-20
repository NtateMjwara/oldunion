<?php
// ============================================================
// company/campaigns/manage.php
// Campaign management — Investment Case, SPV Financials,
// Investor Directory quick-links, External Invites tab.
//
// ── Team C Phase 2 additions ───────────────────────────────
// US-405  New "Assets" tab — fleet vehicle register management.
//         Only visible for campaign_type='fleet_asset'.
//         Writes to campaign_assets (US-102 table, Team A live).
//         Status changes log to compliance_events.
//
// US-406  New "Documents" tab — structured document upload.
//         All campaign types. Writes to campaign_documents
//         (US-104 table, Team A live).
//         Soft-delete (is_active=0) on remove.
//         Version history retained.
// ============================================================

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';
require_once '../../includes/FleetService.php'; // US-405 asset summary

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
           ct.unit_name, ct.unit_price, ct.governing_law,
           ct.hurdle_rate, ct.investor_waterfall_pct, ct.management_fee_pct,
           ct.management_fee_basis, ct.distribution_frequency,
           ct.term_months, ct.asset_type, ct.asset_count,
           ct.acquisition_cost_per_unit, ct.total_acquisition_cost
    FROM funding_campaigns fc
    LEFT JOIN campaign_terms ct ON ct.campaign_id = fc.id
    WHERE fc.uuid = :uuid AND fc.company_id = :cid
");
$stmt->execute(['uuid' => $campaignUuid, 'cid' => $companyId]);
$campaign = $stmt->fetch();
if (!$campaign) { redirect('/company/campaigns/index.php?uuid=' . urlencode($companyUuid)); }

$campaignId  = (int)$campaign['id'];
$isFleet     = ($campaign['campaign_type'] === 'fleet_asset');

/* ── Load pitch ──────────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$pitch       = $stmt->fetch() ?: [];
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

/* ── US-405: Load fleet assets ───────────────── */
$fleetAssets    = $isFleet ? FleetService::getAssets($campaignId) : [];
$assetSummary   = $isFleet ? FleetService::getAssetSummary($campaignId) : [];

/* ── US-406: Load campaign documents ─────────── */
$stmt = $pdo->prepare("
    SELECT id, uuid, doc_type, label, file_url, file_size_kb,
           version, access_level, is_active, uploaded_at,
           (SELECT email FROM users WHERE id = cd.uploaded_by) AS uploader_email
    FROM campaign_documents cd
    WHERE campaign_id = ?
    ORDER BY is_active DESC, FIELD(doc_type,
        'subscription_agreement','ppm','financial_model',
        'investor_rights','due_diligence','other'
    ), uploaded_at DESC
");
$stmt->execute([$campaignId]);
$documents = $stmt->fetchAll();

/* ── User info ───────────────────────────────── */
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$authUser    = $stmt->fetch();
$userInitial = $authUser ? strtoupper(substr($authUser['email'], 0, 1)) : 'U';

$csrf_token = generateCSRFToken();
$errors  = [];
$success = '';
$activeTab = $_GET['tab'] ?? 'pitch';

/* ═══════════════════════════════════════════════
   POST HANDLERS
═══════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    } else {

        $action = trim($_POST['action'] ?? '');

        /* ── Investment case save ─────────────────── */
        if ($action === 'save_pitch') {
            $activeTab = 'pitch';
            $thesis = trim($_POST['investment_thesis'] ?? '');
            $uof    = trim($_POST['use_of_funds']      ?? '');
            $risks  = trim($_POST['risk_factors']      ?? '');
            $exit   = trim($_POST['exit_strategy']     ?? '');
            $deck   = trim($_POST['pitch_deck_url']    ?? '');
            $video  = trim($_POST['pitch_video_url']   ?? '');
            if ($deck  !== '' && !filter_var($deck,  FILTER_VALIDATE_URL)) { $errors[] = 'Pitch deck URL is not valid.'; }
            if ($video !== '' && !filter_var($video, FILTER_VALIDATE_URL)) { $errors[] = 'Pitch video URL is not valid.'; }
            if (empty($errors)) {
                if ($pitchExists) {
                    $pdo->prepare("UPDATE campaign_pitch SET
                        investment_thesis=:thesis, use_of_funds=:uof, risk_factors=:risks,
                        exit_strategy=:exit, pitch_deck_url=:deck, pitch_video_url=:video, updated_at=NOW()
                    WHERE campaign_id=:cid")->execute(['thesis'=>$thesis?:null,'uof'=>$uof?:null,'risks'=>$risks?:null,'exit'=>$exit?:null,'deck'=>$deck?:null,'video'=>$video?:null,'cid'=>$campaignId]);
                } else {
                    $pdo->prepare("INSERT INTO campaign_pitch
                        (campaign_id,investment_thesis,use_of_funds,risk_factors,exit_strategy,pitch_deck_url,pitch_video_url)
                        VALUES(:cid,:thesis,:uof,:risks,:exit,:deck,:video)")->execute(['cid'=>$campaignId,'thesis'=>$thesis?:null,'uof'=>$uof?:null,'risks'=>$risks?:null,'exit'=>$exit?:null,'deck'=>$deck?:null,'video'=>$video?:null]);
                    $pitchExists = true;
                }
                $stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
                $stmt->execute([$campaignId]);
                $pitch = $stmt->fetch() ?: [];
                logCompanyActivity($companyId, $userId, 'Updated investment case: ' . $campaign['title']);
                $success = 'Investment case saved.';
            }

        /* ── Financials delete ────────────────────── */
        } elseif ($action === 'delete_fin') {
            $activeTab = 'financials';
            $deleteId  = (int)($_POST['record_id'] ?? 0);
            if ($deleteId > 0) {
                $pdo->prepare("DELETE FROM campaign_financials WHERE id=:id AND campaign_id=:cid")->execute(['id'=>$deleteId,'cid'=>$campaignId]);
                logCompanyActivity($companyId, $userId, 'Deleted financial record #' . $deleteId);
                $success = 'Financial record deleted.';
            }

        /* ── Financials add / edit ────────────────── */
        } elseif (in_array($action, ['add_fin','edit_fin'], true)) {
            $activeTab   = 'financials';
            $editId      = (int)($_POST['record_id'] ?? 0);
            $periodYear  = trim($_POST['period_year']  ?? '');
            $periodMonth = trim($_POST['period_month'] ?? '');
            $periodMonthVal = ($periodMonth === '') ? null : (int)$periodMonth;
            if ($periodYear === '' || !ctype_digit($periodYear) || (int)$periodYear < 2000 || (int)$periodYear > (int)date('Y')+1) {
                $errors[] = 'Please enter a valid year (2000–'.(date('Y')+1).').';
            }
            $numFields = ['revenue','cost_of_sales','gross_profit','operating_expenses','net_profit'];
            $nums = [];
            foreach ($numFields as $f) {
                $raw = trim($_POST[$f] ?? '');
                if ($raw === '') { $nums[$f] = null; }
                elseif (!is_numeric($raw)) { $errors[] = ucfirst(str_replace('_',' ',$f)).' must be a number.'; $nums[$f]=null; }
                else { $nums[$f] = (float)$raw; }
            }
            $disclosureType = trim($_POST['disclosure_type'] ?? 'self_reported');
            if (!in_array($disclosureType, ['self_reported','accountant_verified','audited'], true)) { $disclosureType='self_reported'; }
            $notes = trim($_POST['notes'] ?? '') ?: null;
            if (empty($errors)) {
                if ($action === 'edit_fin' && $editId > 0) {
                    $pdo->prepare("UPDATE campaign_financials SET period_year=:yr,period_month=:mo,revenue=:rev,cost_of_sales=:cos,gross_profit=:gp,operating_expenses=:opex,net_profit=:np,disclosure_type=:dt,notes=:notes,updated_at=NOW() WHERE id=:id AND campaign_id=:cid")
                        ->execute(['yr'=>(int)$periodYear,'mo'=>$periodMonthVal,'rev'=>$nums['revenue'],'cos'=>$nums['cost_of_sales'],'gp'=>$nums['gross_profit'],'opex'=>$nums['operating_expenses'],'np'=>$nums['net_profit'],'dt'=>$disclosureType,'notes'=>$notes,'id'=>$editId,'cid'=>$campaignId]);
                    $success = 'Financial record updated.';
                } else {
                    try {
                        $pdo->prepare("INSERT INTO campaign_financials (campaign_id,period_year,period_month,revenue,cost_of_sales,gross_profit,operating_expenses,net_profit,disclosure_type,notes) VALUES(:cid,:yr,:mo,:rev,:cos,:gp,:opex,:np,:dt,:notes)")
                            ->execute(['cid'=>$campaignId,'yr'=>(int)$periodYear,'mo'=>$periodMonthVal,'rev'=>$nums['revenue'],'cos'=>$nums['cost_of_sales'],'gp'=>$nums['gross_profit'],'opex'=>$nums['operating_expenses'],'np'=>$nums['net_profit'],'dt'=>$disclosureType,'notes'=>$notes]);
                        $success = 'Financial record added.';
                    } catch (PDOException $e) {
                        if ($e->getCode() === '23000') { $errors[] = 'A record for this period already exists. Use Edit to update it.'; }
                        else { throw $e; }
                    }
                }
                if (empty($errors)) {
                    logCompanyActivity($companyId, $userId, ($action==='edit_fin'?'Updated':'Added').' financial record: '.$campaign['title']);
                    $stmt = $pdo->prepare("SELECT id,period_year,period_month,revenue,cost_of_sales,gross_profit,operating_expenses,net_profit,disclosure_type,notes FROM campaign_financials WHERE campaign_id=? ORDER BY period_year DESC,COALESCE(period_month,0) DESC");
                    $stmt->execute([$campaignId]); $financials = $stmt->fetchAll();
                } else { $success = ''; }
            }

        /* ──────────────────────────────────────────────────────────────────
           US-405 (Team C Phase 2) — Fleet Asset Management
           Writes to campaign_assets (Team A US-102 migration confirmed live)
        ─────────────────────────────────────────────────────────────────── */
        } elseif ($action === 'add_asset' && $isFleet) {
            $activeTab   = 'assets';
            $assetLabel  = trim($_POST['asset_label']         ?? '');
            $assetMake   = trim($_POST['asset_make']          ?? '');
            $assetModel  = trim($_POST['asset_model']         ?? '');
            $assetYear   = (int)($_POST['asset_year']         ?? 0) ?: null;
            $assetCost   = trim($_POST['asset_cost']          ?? '');
            $assetPlatform = trim($_POST['asset_platform']    ?? 'other');
            $assetSerial = trim($_POST['asset_serial']        ?? '') ?: null;
            $assetGps    = trim($_POST['asset_gps']           ?? '') ?: null;
            $assetInsRef = trim($_POST['asset_ins_ref']       ?? '') ?: null;
            $assetInsExp = trim($_POST['asset_ins_expiry']    ?? '') ?: null;

            if ($assetLabel === '') { $errors[] = 'Asset label is required.'; }
            if (!in_array($assetPlatform, ['uber_eats','bolt','both','direct','other'], true)) { $assetPlatform = 'other'; }
            if ($assetCost !== '' && (!is_numeric($assetCost) || (float)$assetCost < 0)) { $errors[] = 'Acquisition cost must be a positive number.'; }

            // Enforce asset count cap
            $currentCount = (int)($assetSummary['total'] ?? 0);
            $maxCount     = (int)($campaign['asset_count'] ?? 200);
            if ($currentCount >= $maxCount) { $errors[] = "This campaign is at its maximum of $maxCount assets."; }

            if (empty($errors)) {
                $pdo->prepare("INSERT INTO campaign_assets (
                    uuid, campaign_id, asset_label, asset_type, make, model, year,
                    acquisition_cost, serial_number, gps_device_id,
                    insurance_ref, insurance_expiry, deployment_platform, status
                ) VALUES (
                    :uuid, :cid, :label, :type, :make, :model, :year,
                    :cost, :serial, :gps, :ins_ref, :ins_exp, :platform, 'pending'
                )")->execute([
                    'uuid'    => generateUuidV4(),
                    'cid'     => $campaignId,
                    'label'   => $assetLabel,
                    'type'    => $campaign['asset_type'] ?? 'Other',
                    'make'    => $assetMake  ?: null,
                    'model'   => $assetModel ?: null,
                    'year'    => $assetYear,
                    'cost'    => $assetCost !== '' ? (float)$assetCost : 0,
                    'serial'  => $assetSerial,
                    'gps'     => $assetGps,
                    'ins_ref' => $assetInsRef,
                    'ins_exp' => ($assetInsExp && $assetInsExp !== '') ? $assetInsExp : null,
                    'platform'=> $assetPlatform,
                ]);
                logCompanyActivity($companyId, $userId, 'Added fleet asset: ' . $assetLabel);
                $success = 'Asset <strong>' . htmlspecialchars($assetLabel) . '</strong> added.';
                $fleetAssets  = FleetService::getAssets($campaignId);
                $assetSummary = FleetService::getAssetSummary($campaignId);
            }

        } elseif ($action === 'edit_asset' && $isFleet) {
            $activeTab   = 'assets';
            $assetId     = (int)($_POST['asset_id']           ?? 0);
            $assetLabel  = trim($_POST['asset_label']         ?? '');
            $assetMake   = trim($_POST['asset_make']          ?? '');
            $assetModel  = trim($_POST['asset_model']         ?? '');
            $assetYear   = (int)($_POST['asset_year']         ?? 0) ?: null;
            $assetCost   = trim($_POST['asset_cost']          ?? '');
            $assetPlatform = trim($_POST['asset_platform']    ?? 'other');
            $assetSerial = trim($_POST['asset_serial']        ?? '') ?: null;
            $assetGps    = trim($_POST['asset_gps']           ?? '') ?: null;
            $assetInsRef = trim($_POST['asset_ins_ref']       ?? '') ?: null;
            $assetInsExp = trim($_POST['asset_ins_expiry']    ?? '') ?: null;
            $assetNotes  = trim($_POST['asset_notes']         ?? '') ?: null;

            if ($assetId <= 0)    { $errors[] = 'Invalid asset.'; }
            if ($assetLabel === '') { $errors[] = 'Asset label is required.'; }
            if (!in_array($assetPlatform, ['uber_eats','bolt','both','direct','other'], true)) { $assetPlatform = 'other'; }
            if ($assetCost !== '' && (!is_numeric($assetCost) || (float)$assetCost < 0)) { $errors[] = 'Acquisition cost must be positive.'; }

            if (empty($errors)) {
                $pdo->prepare("UPDATE campaign_assets SET
                    asset_label          = :label,
                    make                 = :make,
                    model                = :model,
                    year                 = :year,
                    acquisition_cost     = :cost,
                    serial_number        = :serial,
                    gps_device_id        = :gps,
                    insurance_ref        = :ins_ref,
                    insurance_expiry     = :ins_exp,
                    deployment_platform  = :platform,
                    notes                = :notes,
                    updated_at           = NOW()
                WHERE id = :id AND campaign_id = :cid
                ")->execute([
                    'label'   => $assetLabel,
                    'make'    => $assetMake  ?: null,
                    'model'   => $assetModel ?: null,
                    'year'    => $assetYear,
                    'cost'    => $assetCost !== '' ? (float)$assetCost : 0,
                    'serial'  => $assetSerial,
                    'gps'     => $assetGps,
                    'ins_ref' => $assetInsRef,
                    'ins_exp' => ($assetInsExp && $assetInsExp !== '') ? $assetInsExp : null,
                    'platform'=> $assetPlatform,
                    'notes'   => $assetNotes,
                    'id'      => $assetId,
                    'cid'     => $campaignId,
                ]);
                logCompanyActivity($companyId, $userId, 'Edited fleet asset #' . $assetId . ': ' . $assetLabel);
                $success = 'Asset updated.';
                $fleetAssets  = FleetService::getAssets($campaignId);
                $assetSummary = FleetService::getAssetSummary($campaignId);
            }

        } elseif ($action === 'update_asset_status' && $isFleet) {
            // US-405: Status change — logs to compliance_events per acceptance criteria
            $activeTab = 'assets';
            $assetId   = (int)($_POST['asset_id']  ?? 0);
            $newStatus = trim($_POST['new_status'] ?? '');
            $validStatuses = ['pending','active','damaged','sold'];

            if ($assetId <= 0 || !in_array($newStatus, $validStatuses, true)) {
                $errors[] = 'Invalid asset or status.';
            } else {
                // Load current status for the compliance log
                $stmt = $pdo->prepare("SELECT asset_label, status FROM campaign_assets WHERE id=? AND campaign_id=?");
                $stmt->execute([$assetId, $campaignId]);
                $assetRow = $stmt->fetch();

                if (!$assetRow) {
                    $errors[] = 'Asset not found.';
                } elseif ($assetRow['status'] === $newStatus) {
                    $errors[] = 'Asset is already ' . $newStatus . '.';
                } else {
                    $oldStatus = $assetRow['status'];
                    $pdo->beginTransaction();
                    try {
                        $deployedAt = ($newStatus === 'active' && $oldStatus === 'pending')
                            ? ', deployed_at = NOW()' : '';
                        $pdo->prepare("UPDATE campaign_assets
                            SET status=:status, updated_at=NOW() $deployedAt
                            WHERE id=:id AND campaign_id=:cid"
                        )->execute(['status'=>$newStatus,'id'=>$assetId,'cid'=>$campaignId]);

                        // Compliance log — soft failure (per ComplianceService design)
                        try {
                            $pdo->prepare("INSERT INTO compliance_events
                                (event_type, actor_id, campaign_id, ip_address, meta_json, created_at)
                                VALUES ('asset_status_changed', :actor, :cid, :ip, :meta, NOW())"
                            )->execute([
                                'actor' => $userId,
                                'cid'   => $campaignId,
                                'ip'    => $_SERVER['REMOTE_ADDR'] ?? '',
                                'meta'  => json_encode([
                                    'asset_id'    => $assetId,
                                    'asset_label' => $assetRow['asset_label'],
                                    'old_status'  => $oldStatus,
                                    'new_status'  => $newStatus,
                                ]),
                            ]);
                        } catch (PDOException $e) {
                            error_log('[manage US-405] compliance_events: ' . $e->getMessage());
                        }

                        $pdo->commit();
                        logCompanyActivity($companyId, $userId, "Asset '{$assetRow['asset_label']}' status: $oldStatus → $newStatus");
                        $success = 'Asset <strong>' . htmlspecialchars($assetRow['asset_label']) . '</strong> marked as <strong>' . ucfirst($newStatus) . '</strong>.';
                        $fleetAssets  = FleetService::getAssets($campaignId);
                        $assetSummary = FleetService::getAssetSummary($campaignId);
                    } catch (Throwable $e) {
                        $pdo->rollBack();
                        error_log('[manage US-405 status] ' . $e->getMessage());
                        $errors[] = 'Could not update asset status. Please try again.';
                    }
                }
            }
        /* ── End US-405 ─────────────────────────────────────────────────── */

        /* ──────────────────────────────────────────────────────────────────
           US-406 (Team C Phase 2) — Campaign Documents
           Writes to campaign_documents (Team A US-104 migration confirmed live)
        ─────────────────────────────────────────────────────────────────── */
        } elseif ($action === 'upload_document') {
            $activeTab   = 'documents';
            $docType     = trim($_POST['doc_type']     ?? 'other');
            $docLabel    = trim($_POST['doc_label']    ?? '');
            $docVersion  = trim($_POST['doc_version']  ?? '') ?: null;
            $docAccess   = trim($_POST['doc_access']   ?? 'accepted');
            $validTypes  = ['subscription_agreement','ppm','financial_model','investor_rights','due_diligence','other'];
            $validAccess = ['public','invited','accepted'];

            if (!in_array($docType, $validTypes, true)) { $errors[] = 'Invalid document type.'; }
            if ($docLabel === '') { $errors[] = 'Document label is required.'; }
            if (!in_array($docAccess, $validAccess, true)) { $docAccess = 'accepted'; }

            $fileUrl     = null;
            $fileSizeKb  = null;

            if (!empty($_FILES['doc_file']['name'])) {
                $upload = uploadCompanyFile('doc_file', $campaignUuid, 'document');
                if (!$upload['success']) {
                    $errors[] = $upload['error'];
                } else {
                    $fileUrl    = $upload['path'];
                    $fileSizeKb = isset($_FILES['doc_file']['size'])
                        ? (int)ceil($_FILES['doc_file']['size'] / 1024) : null;
                }
            } else {
                $fileUrl = trim($_POST['doc_url'] ?? '');
                if ($fileUrl === '') { $errors[] = 'Please upload a file or provide a document URL.'; }
                elseif (!filter_var($fileUrl, FILTER_VALIDATE_URL)) { $errors[] = 'Document URL must be a valid URL.'; }
            }

            if (empty($errors)) {
                // Soft-retire previous active versions of the same doc_type (optional: keep all active)
                // Per spec, old versions are retained — we just insert new.
                $pdo->prepare("INSERT INTO campaign_documents
                    (uuid, campaign_id, doc_type, label, file_url, file_size_kb,
                     version, access_level, is_active, uploaded_by, uploaded_at)
                    VALUES (:uuid,:cid,:type,:label,:url,:size,:ver,:access,1,:by,NOW())"
                )->execute([
                    'uuid'   => generateUuidV4(),
                    'cid'    => $campaignId,
                    'type'   => $docType,
                    'label'  => $docLabel,
                    'url'    => $fileUrl,
                    'size'   => $fileSizeKb,
                    'ver'    => $docVersion,
                    'access' => $docAccess,
                    'by'     => $userId,
                ]);
                logCompanyActivity($companyId, $userId, 'Uploaded document: ' . $docLabel);
                $success = 'Document <strong>' . htmlspecialchars($docLabel) . '</strong> uploaded.';

                // Reload documents
                $stmt = $pdo->prepare("SELECT id, uuid, doc_type, label, file_url, file_size_kb, version, access_level, is_active, uploaded_at, (SELECT email FROM users WHERE id=cd.uploaded_by) AS uploader_email FROM campaign_documents cd WHERE campaign_id=? ORDER BY is_active DESC, FIELD(doc_type,'subscription_agreement','ppm','financial_model','investor_rights','due_diligence','other'), uploaded_at DESC");
                $stmt->execute([$campaignId]);
                $documents = $stmt->fetchAll();
            }

        } elseif ($action === 'delete_document') {
            // US-406: Soft-delete — is_active = 0, record retained for audit
            $activeTab = 'documents';
            $docId     = (int)($_POST['doc_id'] ?? 0);
            if ($docId <= 0) {
                $errors[] = 'Invalid document.';
            } else {
                $stmt = $pdo->prepare("SELECT label FROM campaign_documents WHERE id=? AND campaign_id=?");
                $stmt->execute([$docId, $campaignId]);
                $docRow = $stmt->fetch();
                if (!$docRow) {
                    $errors[] = 'Document not found.';
                } else {
                    $pdo->prepare("UPDATE campaign_documents SET is_active=0, updated_at=NOW() WHERE id=? AND campaign_id=?")->execute([$docId, $campaignId]);
                    logCompanyActivity($companyId, $userId, 'Soft-deleted document: ' . $docRow['label']);
                    $success = 'Document removed (record retained for audit).';
                    $stmt = $pdo->prepare("SELECT id, uuid, doc_type, label, file_url, file_size_kb, version, access_level, is_active, uploaded_at, (SELECT email FROM users WHERE id=cd.uploaded_by) AS uploader_email FROM campaign_documents cd WHERE campaign_id=? ORDER BY is_active DESC, FIELD(doc_type,'subscription_agreement','ppm','financial_model','investor_rights','due_diligence','other'), uploaded_at DESC");
                    $stmt->execute([$campaignId]);
                    $documents = $stmt->fetchAll();
                }
            }
        /* ── End US-406 ─────────────────────────────────────────────────── */
        }
    }
}

/* ── uploadCompanyFile shim (delegates to existing helper) ──────────────
   If company_uploads.php isn't loaded via this file, we need it for
   US-406 document uploads. Require it here.
   ─────────────────────────────────────────────────────────────────────── */
if (!function_exists('uploadCompanyFile')) {
    require_once '../../includes/company_uploads.php';
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
    'closed_successful'   => ['Closed — Success','cs-success','fa-flag-checkered'],
    'closed_unsuccessful' => ['Closed — Failed','cs-failed',  'fa-xmark-circle'],
    'suspended'           => ['Suspended',     'cs-suspended','fa-pause'],
];
$sInfo = $statusConfig[$campaign['status']] ?? ['Unknown','cs-draft','fa-question'];

// US-405 helpers
$assetStatusConfig = [
    'pending' => ['Pending',  'as-pending',  'fa-clock'],
    'active'  => ['Active',   'as-active',   'fa-circle-play'],
    'damaged' => ['Damaged',  'as-damaged',  'fa-triangle-exclamation'],
    'sold'    => ['Sold/Off', 'as-sold',     'fa-ban'],
];
$platformLabels = ['uber_eats'=>'Uber Eats','bolt'=>'Bolt','both'=>'Both','direct'=>'Direct','other'=>'Other'];

// US-406 helpers
$docTypeConfig = [
    'subscription_agreement' => ['Subscription Agreement', 'fa-file-signature'],
    'ppm'                    => ['PPM',                    'fa-file-contract'],
    'financial_model'        => ['Financial Model',        'fa-file-chart-line'],
    'investor_rights'        => ['Investor Rights',        'fa-scale-balanced'],
    'due_diligence'          => ['Due Diligence',          'fa-magnifying-glass-chart'],
    'other'                  => ['Other',                  'fa-file'],
];
$docAccessConfig = [
    'public'   => ['Public',         'da-public',   'Any logged-in user'],
    'invited'  => ['Invited',        'da-invited',  'Pending or accepted invite'],
    'accepted' => ['Accepted only',  'da-accepted', 'Accepted invite only'],
];

// Count pending invite / external invite badges for tabs
$pendingInviteCount = 0;
$externalInviteCount = 0;
try {
    $s = $pdo->prepare("SELECT COUNT(*) FROM campaign_invites WHERE campaign_id=? AND status='pending'");
    $s->execute([$campaignId]); $pendingInviteCount = (int)$s->fetchColumn();
    $s = $pdo->prepare("SELECT COUNT(*) FROM campaign_invites WHERE campaign_id=? AND invite_source='external_email'");
    $s->execute([$campaignId]); $externalInviteCount = (int)$s->fetchColumn();
} catch (PDOException $e) { /* table may not exist during migration */ }

$editFinId = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$editAssetId = isset($_GET['edit_asset']) ? (int)$_GET['edit_asset'] : 0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Manage Campaign | <?= htmlspecialchars($company['name']) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<style>
:root{
    --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
    --amber:#f59e0b;--amber-dark:#d97706;--amber-light:#fef3c7;
    --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
    --surface:#fff;--surface-2:#f8f9fb;--border:#e4e7ec;
    --text:#101828;--text-muted:#667085;--text-light:#98a2b3;
    --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
    /* US-405 Team C — fleet/asset colour tokens */
    --fleet:#b45309;--fleet-pale:#fef3e2;--fleet-mid:#fde68a;
    --radius:14px;--radius-sm:8px;
    --shadow:0 4px 16px rgba(11,37,69,.07),0 1px 3px rgba(11,37,69,.05);
    --header-h:64px;--sidebar-w:240px;--transition:.2s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;}
.top-header{position:fixed;top:0;left:0;right:0;height:var(--header-h);background:var(--surface);border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 1.75rem;justify-content:space-between;z-index:100;gap:1rem;}
.logo{font-family:'DM Serif Display',serif;font-size:1.35rem;color:var(--navy);text-decoration:none;}
.logo span{color:#c8102e;}
.header-nav{display:flex;align-items:center;gap:.15rem;flex:1;justify-content:center;}
.header-nav a{display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border-radius:6px;font-size:.85rem;font-weight:500;color:var(--text-muted);text-decoration:none;}
.header-nav a:hover{background:var(--surface-2);color:var(--text);}
.header-nav a.active{background:#eff4ff;color:var(--navy-mid);}
.avatar{width:36px;height:36px;border-radius:50%;background:linear-gradient(135deg,#6a11cb,#2575fc);display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.9rem;flex-shrink:0;}
.page-wrapper{padding-top:var(--header-h);display:flex;min-height:100vh;}
.sidebar{width:var(--sidebar-w);background:var(--surface);border-right:1px solid var(--border);position:sticky;top:var(--header-h);height:calc(100vh - var(--header-h));overflow-y:auto;padding:1.5rem 1rem;flex-shrink:0;}
.sidebar-section-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);padding:0 .5rem;margin-bottom:.5rem;margin-top:1.25rem;}
.sidebar-section-label:first-child{margin-top:0;}
.sidebar a{display:flex;align-items:center;gap:.6rem;padding:.55rem .75rem;border-radius:var(--radius-sm);font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;transition:all var(--transition);margin-bottom:.1rem;}
.sidebar a:hover{background:var(--surface-2);color:var(--text);}
.sidebar a.active{background:#eff4ff;color:var(--navy-mid);font-weight:600;}
.sidebar a i{width:16px;text-align:center;font-size:.85rem;}
.main-content{flex:1;padding:2rem 2.5rem;min-width:0;}
.breadcrumb{font-size:.8rem;color:var(--text-light);margin-bottom:1.25rem;display:flex;align-items:center;gap:.4rem;flex-wrap:wrap;}
.breadcrumb a{color:var(--navy-light);text-decoration:none;}
.breadcrumb i{font-size:.65rem;}
.campaign-info-strip{background:var(--navy);border-radius:var(--radius-sm);padding:1rem 1.25rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;margin-bottom:1.5rem;}
.ci-title{font-family:'DM Serif Display',serif;font-size:1.1rem;color:#fff;flex:1;}
.ci-meta{font-size:.78rem;color:rgba(255,255,255,.55);margin-top:.15rem;}
.status-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .75rem;border-radius:99px;font-size:.77rem;font-weight:600;border:1px solid transparent;}
.cs-draft   {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
.cs-review  {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.cs-approved{background:#eff4ff;color:var(--navy-light);border-color:#c7d9f8;}
.cs-open    {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-funded  {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-success {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.cs-failed  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.cs-suspended{background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
.page-tabs{display:flex;border-bottom:1px solid var(--border);margin-bottom:1.75rem;overflow-x:auto;}
.page-tab{display:flex;align-items:center;gap:.4rem;padding:.8rem 1.25rem;font-size:.86rem;font-weight:500;color:var(--text-muted);text-decoration:none;border-bottom:2.5px solid transparent;margin-bottom:-1px;white-space:nowrap;}
.page-tab:hover{color:var(--navy);}
.page-tab.active{color:var(--navy-mid);border-bottom-color:var(--navy-mid);font-weight:600;}
.page-tab.fleet-tab{color:var(--fleet);}
.page-tab.fleet-tab.active{color:var(--fleet);border-bottom-color:var(--fleet);}
.tab-badge{display:inline-flex;align-items:center;justify-content:center;font-size:.67rem;padding:.05rem .45rem;border-radius:99px;font-weight:700;margin-left:.2rem;}
.badge-navy{background:var(--navy-mid);color:#fff;}
.badge-amber{background:var(--amber);color:var(--navy);}
.badge-fleet{background:var(--fleet);color:#fff;}
.badge-green{background:var(--green);color:#fff;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);box-shadow:var(--shadow);overflow:hidden;margin-bottom:1.5rem;}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.25rem;border-bottom:1px solid var(--border);}
.card-title{display:flex;align-items:center;gap:.5rem;font-size:.83rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:.07em;}
.card-title i{color:var(--navy-light);}
.card-title.fleet i{color:var(--fleet);}
.card-body{padding:1.25rem;}
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
.alert{display:flex;align-items:flex-start;gap:.65rem;padding:.85rem 1rem;border-radius:var(--radius-sm);margin-bottom:1.25rem;font-size:.86rem;font-weight:500;border:1px solid transparent;}
.alert i{flex-shrink:0;margin-top:.05rem;}
.alert-success{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.alert-error  {background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.btn{display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.2rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.84rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;}
.btn-primary{background:var(--navy-mid);color:#fff;}.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.btn-outline{background:var(--surface);color:var(--text-muted);border:1.5px solid var(--border);}.btn-outline:hover{border-color:var(--navy-light);color:var(--navy-mid);}
.btn-danger {background:var(--error-bg);color:var(--error);border:1px solid var(--error-bdr);}.btn-danger:hover{background:var(--error);color:#fff;}
.btn-amber  {background:var(--amber);color:var(--navy);}.btn-amber:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
.btn-fleet  {background:var(--fleet);color:#fff;}.btn-fleet:hover{background:#92400e;transform:translateY(-1px);}
.btn-sm{padding:.35rem .85rem;font-size:.79rem;}
.btn-xs{padding:.22rem .6rem;font-size:.74rem;}
/* ── Financials table ── */
.fin-table{width:100%;border-collapse:collapse;font-size:.84rem;}
.fin-table th{text-align:left;padding:.55rem .75rem;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--text-light);border-bottom:2px solid var(--border);}
.fin-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.fin-table tr:last-child td{border-bottom:none;}.fin-table tr:hover td{background:#fafbfc;}
.fin-table td.num{text-align:right;font-variant-numeric:tabular-nums;}
.neg{color:var(--error);}
/* ── US-405: Asset management styles ── */
.asset-summary-strip{display:flex;gap:1rem;flex-wrap:wrap;padding:1rem 1.25rem;background:var(--surface-2);border-bottom:1px solid var(--border);}
.asset-stat{display:flex;flex-direction:column;align-items:center;padding:.6rem 1rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius-sm);min-width:80px;}
.asset-stat-val{font-family:'DM Serif Display',serif;font-size:1.5rem;font-weight:700;color:var(--navy);line-height:1;}
.asset-stat-val.active {color:var(--green);}
.asset-stat-val.pending{color:var(--amber-dark);}
.asset-stat-val.damaged{color:var(--error);}
.asset-stat-lbl{font-size:.7rem;color:var(--text-light);margin-top:.2rem;}
.asset-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.asset-table th{text-align:left;padding:.55rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);white-space:nowrap;}
.asset-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.asset-table tr:last-child td{border-bottom:none;}
.asset-table tr:hover td{background:var(--surface-2);}
.asset-table tr.row-editing td{background:#fffbeb;}
/* Asset status badges */
.as-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.18rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
.as-pending{background:var(--fleet-pale);color:var(--fleet);border-color:var(--fleet-mid);}
.as-active {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.as-damaged{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.as-sold   {background:#f1f5f9;color:#475569;border-color:#cbd5e1;}
/* Insurance expiry warning */
.ins-warn{color:var(--error);font-size:.75rem;}
.ins-ok  {color:var(--green);font-size:.75rem;}
/* Add asset form panel */
.add-panel{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1.25rem;margin-top:1.25rem;}
.add-panel-head{font-size:.82rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);margin-bottom:1rem;display:flex;align-items:center;gap:.4rem;}
/* ── US-406: Documents styles ── */
.doc-table{width:100%;border-collapse:collapse;font-size:.83rem;}
.doc-table th{text-align:left;padding:.55rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);}
.doc-table td{padding:.65rem .75rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.doc-table tr:last-child td{border-bottom:none;}
.doc-table tr:hover td{background:var(--surface-2);}
.doc-table tr.doc-inactive td{opacity:.45;}
.doc-type-badge{display:inline-flex;align-items:center;gap:.3rem;padding:.18rem .65rem;border-radius:99px;font-size:.72rem;font-weight:600;background:#eff4ff;color:var(--navy-mid);border:1px solid #c7d9f8;}
.doc-access-badge{display:inline-flex;align-items:center;gap:.25rem;padding:.15rem .55rem;border-radius:99px;font-size:.7rem;font-weight:600;border:1px solid transparent;}
.da-public  {background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
.da-invited {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.da-accepted{background:#f3e8ff;color:#6d28d9;border-color:#d8b4fe;}
.doc-version{font-family:monospace;font-size:.75rem;background:var(--surface-2);border:1px solid var(--border);border-radius:4px;padding:1px 5px;color:var(--text-muted);}
/* Upload form */
.upload-dropzone{border:2px dashed var(--border);border-radius:var(--radius-sm);padding:1.5rem;text-align:center;position:relative;cursor:pointer;transition:border-color var(--transition),background var(--transition);}
.upload-dropzone:hover{border-color:var(--navy-light);background:#eff4ff;}
.upload-dropzone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.upload-dropzone-icon{font-size:1.5rem;color:var(--text-light);margin-bottom:.4rem;}
.upload-dropzone-label{font-size:.85rem;font-weight:500;color:var(--text-muted);}
.upload-dropzone-label strong{color:var(--navy-light);}
.upload-dropzone-sub{font-size:.75rem;color:var(--text-light);margin-top:.2rem;}
.file-size{font-size:.73rem;color:var(--text-light);}
/* Badges */
.badge{display:inline-flex;align-items:center;gap:.22rem;padding:.17rem .6rem;border-radius:99px;font-size:.72rem;font-weight:600;border:1px solid transparent;}
.dl-self {background:#f1f5f9;color:#475569;border-color:#e2e8f0;}
.dl-acct {background:var(--amber-light);color:#78350f;border-color:var(--amber);}
.dl-audit{background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);}
/* Inline edit row */
.edit-row td{background:#fffbeb!important;}
/* Action button cluster */
.action-cluster{display:flex;gap:.3rem;align-items:center;flex-wrap:nowrap;}
/* Fleet waterfall info bar */
.fleet-info-bar{background:var(--fleet);border-radius:var(--radius-sm);padding:.75rem 1.1rem;display:flex;align-items:center;gap:.75rem;margin-bottom:1.25rem;flex-wrap:wrap;}
.fleet-info-bar i{color:rgba(255,255,255,.75);font-size:.9rem;}
.fleet-info-bar-text{font-size:.82rem;color:#fff;line-height:1.5;}
.fleet-info-bar-chip{display:inline-flex;align-items:center;gap:.25rem;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.25);border-radius:99px;padding:.15rem .6rem;font-size:.75rem;font-weight:600;color:#fff;white-space:nowrap;}
/* Responsive */
@media(max-width:1024px){.header-nav{display:none;}.main-content{padding:1.5rem;}}
@media(max-width:900px){.sidebar{display:none;}.main-content{padding:1.25rem;}.form-grid{grid-template-columns:1fr;}}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
</style>
</head>
<body>

<header class="top-header">
    <a href="/company/" class="logo">Old <span>U</span>nion</a>
    <nav class="header-nav">
        <a href="/company/dashboard.php?uuid=<?= urlencode($companyUuid) ?>"><i class="fa-solid fa-gauge"></i> Dashboard</a>
        <a href="/company/campaigns/index.php?uuid=<?= urlencode($companyUuid) ?>" class="active"><i class="fa-solid fa-rocket"></i> Campaigns</a>
    </nav>
    <div class="avatar"><?= htmlspecialchars($userInitial) ?></div>
</header>

<div class="page-wrapper">

<aside class="sidebar">
    <div class="sidebar-section-label">Company</div>
    <a href="/company/dashboard.php?uuid=<?= urlencode($companyUuid) ?>"><i class="fa-solid fa-gauge"></i> Overview</a>
    <?php if ($canAdmin): ?><a href="/company/wizard.php?uuid=<?= urlencode($companyUuid) ?>"><i class="fa-solid fa-pen-to-square"></i> Edit Profile</a><?php endif; ?>
    <div class="sidebar-section-label">Fundraising</div>
    <a href="/company/campaigns/index.php?uuid=<?= urlencode($companyUuid) ?>"><i class="fa-solid fa-rocket"></i> Campaigns</a>
    <a href="/company/campaigns/manage.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="active"><i class="fa-solid fa-sliders"></i> Manage Campaign</a>
    <a href="/app/company/campaigns/investor_directory.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>"><i class="fa-solid fa-users"></i> Investor Directory</a>
    <a href="/app/company/campaigns/external_invite.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>"><i class="fa-solid fa-envelope-open-text"></i> External Invites</a>
    <div class="sidebar-section-label">Account</div>
    <a href="/auth/logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
</aside>

<main class="main-content">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="/company/dashboard.php?uuid=<?= urlencode($companyUuid) ?>"><i class="fa-solid fa-gauge"></i> <?= htmlspecialchars($company['name']) ?></a>
        <i class="fa-solid fa-chevron-right"></i>
        <a href="/company/campaigns/index.php?uuid=<?= urlencode($companyUuid) ?>">Campaigns</a>
        <i class="fa-solid fa-chevron-right"></i>
        <?= htmlspecialchars($campaign['title']) ?>
    </div>

    <!-- Campaign info strip -->
    <div class="campaign-info-strip">
        <div style="flex:1;">
            <div class="ci-title">
                <?php if ($isFleet): ?><i class="fa-solid fa-truck" style="color:var(--amber);font-size:.85rem;margin-right:.4rem;"></i><?php endif; ?>
                <?= htmlspecialchars($campaign['title']) ?>
            </div>
            <div class="ci-meta">UUID: <?= htmlspecialchars($campaign['uuid']) ?> &nbsp;·&nbsp; Closes <?= mDate($campaign['closes_at']) ?></div>
        </div>
        <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap;">
            <span class="status-badge <?= $sInfo[1] ?>"><i class="fa-solid <?= $sInfo[2] ?>"></i> <?= $sInfo[0] ?></span>
            <?php if ($campaign['status'] === 'draft'): ?>
            <a href="/company/campaigns/wizard.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-pen"></i> Edit in Wizard</a>
            <?php endif; ?>
            <a href="/app/invest/campaign.php?cid=<?= urlencode($campaignUuid) ?>" target="_blank" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Public View</a>
            <a href="/app/company/campaigns/investor_directory.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="btn btn-amber btn-sm"><i class="fa-solid fa-users"></i> Find Investors</a>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error"><i class="fa-solid fa-circle-exclamation"></i><div><?= implode('<br>', array_map('htmlspecialchars', $errors)) ?></div></div>
    <?php endif; ?>
    <?php if ($success): ?>
    <div class="alert alert-success"><i class="fa-solid fa-circle-check"></i><div><?= $success ?></div></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="page-tabs">
        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=pitch" class="page-tab <?= $activeTab==='pitch'?'active':'' ?>"><i class="fa-solid fa-briefcase"></i> Investment Case</a>
        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=financials" class="page-tab <?= $activeTab==='financials'?'active':'' ?>">
            <i class="fa-solid fa-chart-bar"></i> SPV Financials
            <?php if (!empty($financials)): ?><span class="tab-badge badge-navy"><?= count($financials) ?></span><?php endif; ?>
        </a>
        <?php if ($isFleet): ?>
        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=assets" class="page-tab fleet-tab <?= $activeTab==='assets'?'active':'' ?>">
            <i class="fa-solid fa-truck"></i> Assets
            <?php if (!empty($assetSummary['total'])): ?><span class="tab-badge badge-fleet"><?= $assetSummary['total'] ?></span><?php endif; ?>
        </a>
        <?php endif; ?>
        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=documents" class="page-tab <?= $activeTab==='documents'?'active':'' ?>">
            <i class="fa-solid fa-folder-open"></i> Documents
            <?php $activeDocs = count(array_filter($documents, fn($d) => $d['is_active']));
            if ($activeDocs > 0): ?><span class="tab-badge badge-navy"><?= $activeDocs ?></span><?php endif; ?>
        </a>
        <a href="/app/company/campaigns/investor_directory.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="page-tab">
            <i class="fa-solid fa-users"></i> Investor Directory
            <?php if ($pendingInviteCount > 0): ?><span class="tab-badge badge-amber"><?= $pendingInviteCount ?></span><?php endif; ?>
        </a>
        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=external_invites" class="page-tab <?= $activeTab==='external_invites'?'active':'' ?>">
            <i class="fa-solid fa-envelope-open-text"></i> External Invites
            <?php if ($externalInviteCount > 0): ?><span class="tab-badge badge-navy"><?= $externalInviteCount ?></span><?php endif; ?>
        </a>
    </div>


<?php /* ════ INVESTMENT CASE TAB ════════════════════════════════════════ */ ?>
<?php if ($activeTab === 'pitch'): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-briefcase"></i> Investment Case</span></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="save_pitch">
            <div class="form-grid">
                <div class="field span-2"><label>Investment Thesis</label><textarea name="investment_thesis" rows="5" maxlength="3000" placeholder="Why should an investor back this specific SPV?"><?= htmlspecialchars($pitch['investment_thesis']??'') ?></textarea></div>
                <div class="field span-2"><label>Use of Funds</label><textarea name="use_of_funds" rows="4" maxlength="2000" placeholder="e.g. 40% equipment, 35% working capital…"><?= htmlspecialchars($pitch['use_of_funds']??'') ?></textarea></div>
                <div class="field span-2"><label>Risk Factors</label><textarea name="risk_factors" rows="4" maxlength="2000" placeholder="Key risks and mitigants."><?= htmlspecialchars($pitch['risk_factors']??'') ?></textarea></div>
                <div class="field span-2"><label>Exit / Return Strategy</label><textarea name="exit_strategy" rows="3" maxlength="2000" placeholder="How and when do investors realise returns?"><?= htmlspecialchars($pitch['exit_strategy']??'') ?></textarea></div>
                <div class="field"><label>Pitch Deck URL</label><input type="url" name="pitch_deck_url" value="<?= htmlspecialchars($pitch['pitch_deck_url']??'') ?>" placeholder="https://drive.google.com/…"></div>
                <div class="field"><label>Pitch Video URL</label><input type="url" name="pitch_video_url" value="<?= htmlspecialchars($pitch['pitch_video_url']??'') ?>" placeholder="https://youtu.be/…"></div>
            </div>
            <div style="margin-top:1.5rem;"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-floppy-disk"></i> Save Investment Case</button></div>
        </form>
    </div>
</div>

<?php /* ════ SPV FINANCIALS TAB ════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'financials'): ?>
<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-chart-bar"></i> SPV Financial Records</span>
        <span style="font-size:.78rem;color:var(--text-light);"><?= count($financials) ?> record<?= count($financials)!==1?'s':'' ?></span>
    </div>
    <?php if (empty($financials)): ?>
    <div style="padding:2.5rem;text-align:center;font-size:.88rem;color:var(--text-light);"><i class="fa-solid fa-chart-bar" style="font-size:1.75rem;display:block;margin-bottom:.6rem;opacity:.3;"></i>No financial records yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="fin-table">
            <thead><tr><th>Period</th><th style="text-align:right;">Revenue</th><th style="text-align:right;">Gross Profit</th><th style="text-align:right;">Net Profit</th><th>Disclosure</th><th style="width:100px;"></th></tr></thead>
            <tbody>
            <?php foreach ($financials as $fin):
                $pl = $fin['period_month'] ? ($monthNames[(int)$fin['period_month']].' '.$fin['period_year']) : ($fin['period_year'].' Annual');
                $dlMap=['self_reported'=>['Self-Reported','dl-self'],'accountant_verified'=>['Accountant Verified','dl-acct'],'audited'=>['Audited','dl-audit']];
                $dl=$dlMap[$fin['disclosure_type']]??['Self-Reported','dl-self'];
                $isEditing = ($editFinId === (int)$fin['id']);
            ?>
            <?php if ($isEditing): ?>
            <tr class="edit-row"><td colspan="6" style="padding:1rem 1.25rem;">
                <form method="POST" style="display:contents;">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="edit_fin"><input type="hidden" name="record_id" value="<?= (int)$fin['id'] ?>">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.85rem;margin-bottom:.85rem;">
                        <div class="field"><label>Year <span class="req">*</span></label><input type="number" name="period_year" value="<?= (int)$fin['period_year'] ?>" min="2000" max="<?= date('Y')+1 ?>" required></div>
                        <div class="field"><label>Month</label><select name="period_month"><option value="">Annual</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>" <?= (int)$fin['period_month']===$m?'selected':'' ?>><?= $monthNames[$m] ?></option><?php endfor; ?></select></div>
                        <div class="field"><label>Revenue</label><input type="number" name="revenue" step="0.01" value="<?= mFmt($fin['revenue']) ?>" placeholder="0.00"></div>
                        <div class="field"><label>Gross Profit</label><input type="number" name="gross_profit" step="0.01" value="<?= mFmt($fin['gross_profit']) ?>" placeholder="0.00"></div>
                        <div class="field"><label>Net Profit</label><input type="number" name="net_profit" step="0.01" value="<?= mFmt($fin['net_profit']) ?>" placeholder="0.00"></div>
                        <div class="field"><label>Disclosure</label><select name="disclosure_type"><option value="self_reported" <?= $fin['disclosure_type']==='self_reported'?'selected':'' ?>>Self-Reported</option><option value="accountant_verified" <?= $fin['disclosure_type']==='accountant_verified'?'selected':'' ?>>Accountant Verified</option><option value="audited" <?= $fin['disclosure_type']==='audited'?'selected':'' ?>>Audited</option></select></div>
                        <div class="field" style="grid-column:1/-1;"><label>Notes</label><input type="text" name="notes" value="<?= htmlspecialchars($fin['notes']??'') ?>" placeholder="Optional" maxlength="500"></div>
                    </div>
                    <div style="display:flex;gap:.5rem;"><button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Save</button><a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=financials" class="btn btn-outline btn-sm">Cancel</a></div>
                </form>
            </td></tr>
            <?php else: ?>
            <tr>
                <td><strong><?= htmlspecialchars($pl) ?></strong><?php if($fin['notes']): ?><br><span style="font-size:.74rem;color:var(--text-light);"><?= htmlspecialchars($fin['notes']) ?></span><?php endif; ?></td>
                <td class="num"><?= mDisp($fin['revenue']) ?></td>
                <td class="num"><?= mDisp($fin['gross_profit']) ?></td>
                <td class="num <?= ($fin['net_profit']!==null&&$fin['net_profit']<0)?'neg':'' ?>"><?= mDisp($fin['net_profit']) ?></td>
                <td><span class="badge <?= $dl[1] ?>"><?= $dl[0] ?></span></td>
                <td><div class="action-cluster">
                    <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=financials&edit=<?= (int)$fin['id'] ?>" class="btn btn-outline btn-xs"><i class="fa-solid fa-pen"></i></a>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this record?')">
                        <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="delete_fin"><input type="hidden" name="record_id" value="<?= (int)$fin['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-xs"><i class="fa-solid fa-trash"></i></button>
                    </form>
                </div></td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
<?php if (!$editFinId): ?>
<div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-plus"></i> Add Financial Record</span></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="add_fin">
            <div class="form-grid">
                <div class="field"><label>Year <span class="req">*</span></label><input type="number" name="period_year" value="<?= date('Y') ?>" min="2000" max="<?= date('Y')+1 ?>" required></div>
                <div class="field"><label>Month</label><select name="period_month"><option value="">Annual Summary</option><?php for($m=1;$m<=12;$m++): ?><option value="<?= $m ?>"><?= $monthNames[$m] ?></option><?php endfor; ?></select></div>
                <div class="field"><label>Revenue</label><input type="number" name="revenue" step="0.01" placeholder="0.00"></div>
                <div class="field"><label>Cost of Sales</label><input type="number" name="cost_of_sales" step="0.01" placeholder="0.00"></div>
                <div class="field"><label>Gross Profit</label><input type="number" name="gross_profit" step="0.01" placeholder="0.00"></div>
                <div class="field"><label>Operating Expenses</label><input type="number" name="operating_expenses" step="0.01" placeholder="0.00"></div>
                <div class="field"><label>Net Profit / Loss</label><input type="number" name="net_profit" step="0.01" placeholder="0.00"></div>
                <div class="field"><label>Disclosure Type</label><select name="disclosure_type"><option value="self_reported">Self-Reported</option><option value="accountant_verified">Accountant Verified</option><option value="audited">Audited</option></select></div>
                <div class="field span-2"><label>Notes</label><input type="text" name="notes" placeholder="Optional note" maxlength="500"></div>
            </div>
            <div style="margin-top:1.25rem;"><button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Add Record</button></div>
        </form>
    </div>
</div>
<?php endif; ?>

<?php /* ════ US-405: FLEET ASSETS TAB ═══════════════════════════════════
          Only rendered for fleet_asset campaigns.
          team A US-102 migration confirmed live.
       ════════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'assets' && $isFleet): ?>

<?php // Fleet waterfall info bar ?>
<div class="fleet-info-bar">
    <i class="fa-solid fa-truck"></i>
    <div class="fleet-info-bar-text">
        <strong style="color:#fff;">Fleet Asset SPV</strong> &nbsp;
        <?php foreach ([
            (int)($campaign['asset_count']??0) . ' ' . htmlspecialchars($campaign['asset_type']??'assets'),
            htmlspecialchars($campaign['hurdle_rate']??'0') . '% hurdle',
            htmlspecialchars($campaign['investor_waterfall_pct']??'—') . '% investor share',
            htmlspecialchars($campaign['management_fee_pct']??'0') . '% mgmt fee',
            ucfirst($campaign['distribution_frequency']??'monthly') . ' · ' . (int)($campaign['term_months']??0) . ' months',
        ] as $chip): ?>
        <span class="fleet-info-bar-chip"><?= $chip ?></span>
        <?php endforeach; ?>
    </div>
</div>

<div class="card">
    <div class="asset-summary-strip">
        <?php foreach ([
            ['label'=>'Total',   'val'=>$assetSummary['total']??0,   'class'=>''],
            ['label'=>'Active',  'val'=>$assetSummary['active']??0,  'class'=>'active'],
            ['label'=>'Pending', 'val'=>$assetSummary['pending']??0, 'class'=>'pending'],
            ['label'=>'Damaged', 'val'=>$assetSummary['damaged']??0, 'class'=>'damaged'],
            ['label'=>'Sold',    'val'=>$assetSummary['sold']??0,    'class'=>''],
        ] as $stat): ?>
        <div class="asset-stat">
            <div class="asset-stat-val <?= $stat['class'] ?>"><?= $stat['val'] ?></div>
            <div class="asset-stat-lbl"><?= $stat['label'] ?></div>
        </div>
        <?php endforeach; ?>
        <div style="flex:1;"></div>
        <?php $canAdd = ($assetSummary['total']??0) < (int)($campaign['asset_count']??200); ?>
        <?php if ($canAdd): ?>
        <button class="btn btn-fleet btn-sm" onclick="document.getElementById('addAssetPanel').style.display=document.getElementById('addAssetPanel').style.display==='none'?'block':'none'">
            <i class="fa-solid fa-plus"></i> Add Asset
        </button>
        <?php endif; ?>
    </div>

    <div style="overflow-x:auto;">
        <table class="asset-table">
            <thead>
                <tr>
                    <th>Label</th>
                    <th>Make / Model</th>
                    <th>Year</th>
                    <th>Cost (R)</th>
                    <th>Platform</th>
                    <th>GPS Device</th>
                    <th>Insurance Exp.</th>
                    <th>Status</th>
                    <th style="min-width:180px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php if (empty($fleetAssets)): ?>
            <tr><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-light);font-size:.86rem;"><i class="fa-solid fa-truck" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.3;"></i>No assets registered yet. Use the wizard or the Add Asset button.</td></tr>
            <?php else: foreach ($fleetAssets as $fa):
                $sConf  = $assetStatusConfig[$fa['status']] ?? ['Unknown','as-pending','fa-question'];
                $isEdit = ($editAssetId === (int)$fa['id']);
                $insExp = $fa['insurance_expiry'];
                $insClass = '';
                if ($insExp) {
                    $daysLeft = (strtotime($insExp) - time()) / 86400;
                    $insClass = $daysLeft < 0 ? 'ins-warn' : ($daysLeft < 30 ? 'ins-warn' : 'ins-ok');
                }
            ?>
            <?php if ($isEdit): ?>
            <tr class="row-editing"><td colspan="9" style="padding:1rem 1.1rem;">
                <form method="POST">
                    <input type="hidden" name="csrf_token"  value="<?= $csrf_token ?>">
                    <input type="hidden" name="action"      value="edit_asset">
                    <input type="hidden" name="asset_id"    value="<?= (int)$fa['id'] ?>">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:.75rem;margin-bottom:.85rem;">
                        <div class="field"><label>Label <span class="req">*</span></label><input type="text" name="asset_label" value="<?= htmlspecialchars($fa['asset_label']) ?>" required></div>
                        <div class="field"><label>Make</label><input type="text" name="asset_make" value="<?= htmlspecialchars($fa['make']??'') ?>" placeholder="e.g. Ninebot"></div>
                        <div class="field"><label>Model</label><input type="text" name="asset_model" value="<?= htmlspecialchars($fa['model']??'') ?>" placeholder="e.g. ES4"></div>
                        <div class="field"><label>Year</label><input type="number" name="asset_year" value="<?= htmlspecialchars($fa['year']??'') ?>" min="2015" max="<?= date('Y')+1 ?>"></div>
                        <div class="field"><label>Acq. Cost (R)</label><input type="number" name="asset_cost" value="<?= htmlspecialchars((string)($fa['acquisition_cost']??'')) ?>" min="0" step="100"></div>
                        <div class="field"><label>Platform</label>
                            <select name="asset_platform"><?php foreach ($platformLabels as $v=>$l): ?><option value="<?= $v ?>" <?= ($fa['deployment_platform']??'other')===$v?'selected':'' ?>><?= $l ?></option><?php endforeach; ?></select>
                        </div>
                        <div class="field"><label>Serial No.</label><input type="text" name="asset_serial" value="<?= htmlspecialchars($fa['serial_number']??'') ?>"></div>
                        <div class="field"><label>GPS Device ID</label><input type="text" name="asset_gps" value="<?= htmlspecialchars($fa['gps_device_id']??'') ?>" placeholder="IMEI or device ID"></div>
                        <div class="field"><label>Insurance Ref.</label><input type="text" name="asset_ins_ref" value="<?= htmlspecialchars($fa['insurance_ref']??'') ?>"></div>
                        <div class="field"><label>Insurance Expiry</label><input type="date" name="asset_ins_expiry" value="<?= htmlspecialchars($fa['insurance_expiry']??'') ?>"></div>
                        <div class="field" style="grid-column:1/-1;"><label>Notes</label><input type="text" name="asset_notes" value="<?= htmlspecialchars($fa['notes']??'') ?>" placeholder="Optional" maxlength="500"></div>
                    </div>
                    <div style="display:flex;gap:.5rem;">
                        <button type="submit" class="btn btn-primary btn-sm"><i class="fa-solid fa-floppy-disk"></i> Save</button>
                        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=assets" class="btn btn-outline btn-sm">Cancel</a>
                    </div>
                </form>
            </td></tr>
            <?php else: ?>
            <tr>
                <td><strong><?= htmlspecialchars($fa['asset_label']) ?></strong><?php if($fa['serial_number']): ?><br><span style="font-size:.73rem;color:var(--text-light);">SN: <?= htmlspecialchars($fa['serial_number']) ?></span><?php endif; ?></td>
                <td><?= htmlspecialchars(trim(($fa['make']??'').' '.($fa['model']??''))?:'—') ?></td>
                <td><?= $fa['year'] ?: '—' ?></td>
                <td><?= $fa['acquisition_cost'] ? 'R '.number_format((float)$fa['acquisition_cost'],0,'.',' ') : '—' ?></td>
                <td><?= $platformLabels[$fa['deployment_platform']] ?? htmlspecialchars($fa['deployment_platform']) ?></td>
                <td style="font-size:.77rem;color:var(--text-muted);"><?= htmlspecialchars($fa['gps_device_id']??'') ?: '—' ?></td>
                <td class="<?= $insClass ?>">
                    <?= $insExp ? date('d M Y', strtotime($insExp)) : '—' ?>
                    <?php if ($insExp && $insClass === 'ins-warn'): ?><br><span style="font-size:.7rem;"><i class="fa-solid fa-triangle-exclamation"></i> <?= $daysLeft < 0 ? 'Expired' : round($daysLeft).' days' ?></span><?php endif; ?>
                </td>
                <td><span class="as-badge <?= $sConf[1] ?>"><i class="fa-solid <?= $sConf[2] ?>"></i> <?= $sConf[0] ?></span></td>
                <td>
                    <div class="action-cluster">
                        <a href="?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>&tab=assets&edit_asset=<?= (int)$fa['id'] ?>" class="btn btn-outline btn-xs"><i class="fa-solid fa-pen"></i></a>
                        <?php if ($fa['status'] !== 'active'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark asset as Active?')">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="update_asset_status"><input type="hidden" name="asset_id" value="<?= (int)$fa['id'] ?>"><input type="hidden" name="new_status" value="active">
                            <button type="submit" class="btn btn-xs" style="background:var(--green-bg);color:var(--green);border:1px solid var(--green-bdr);"><i class="fa-solid fa-circle-play"></i> Activate</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($fa['status'] === 'active'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark asset as Damaged?')">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="update_asset_status"><input type="hidden" name="asset_id" value="<?= (int)$fa['id'] ?>"><input type="hidden" name="new_status" value="damaged">
                            <button type="submit" class="btn btn-xs" style="background:var(--error-bg);color:var(--error);border:1px solid var(--error-bdr);"><i class="fa-solid fa-triangle-exclamation"></i> Damaged</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($fa['status'] !== 'sold'): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Mark asset as Sold/Written Off? This is permanent.')">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="update_asset_status"><input type="hidden" name="asset_id" value="<?= (int)$fa['id'] ?>"><input type="hidden" name="new_status" value="sold">
                            <button type="submit" class="btn btn-xs btn-danger"><i class="fa-solid fa-ban"></i> Sell/Retire</button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add asset panel (hidden by default) -->
<div class="card" id="addAssetPanel" style="display:none;">
    <div class="card-header"><span class="card-title fleet"><i class="fa-solid fa-plus"></i> Add New Asset</span><span style="font-size:.78rem;color:var(--text-light);"><?= (int)($assetSummary['total']??0) ?> / <?= (int)($campaign['asset_count']??0) ?> registered</span></div>
    <div class="card-body">
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="add_asset">
            <div class="form-grid">
                <div class="field"><label><i class="fa-solid fa-tag"></i> Asset Label <span class="req">*</span></label><input type="text" name="asset_label" placeholder="e.g. <?= htmlspecialchars($campaign['asset_type']??'Scooter') ?> #<?= str_pad(($assetSummary['total']??0)+1,2,'0',STR_PAD_LEFT) ?>" required></div>
                <div class="field"><label><i class="fa-solid fa-layer-group"></i> Platform</label>
                    <select name="asset_platform"><?php foreach ($platformLabels as $v=>$l): ?><option value="<?= $v ?>"><?= $l ?></option><?php endforeach; ?></select></div>
                <div class="field"><label>Make</label><input type="text" name="asset_make" placeholder="e.g. Ninebot"></div>
                <div class="field"><label>Model</label><input type="text" name="asset_model" placeholder="e.g. ES4"></div>
                <div class="field"><label>Year</label><input type="number" name="asset_year" min="2015" max="<?= date('Y')+1 ?>" placeholder="<?= date('Y') ?>"></div>
                <div class="field"><label>Acquisition Cost (R)</label><input type="number" name="asset_cost" min="0" step="100" placeholder="35000"></div>
                <div class="field"><label>Serial Number</label><input type="text" name="asset_serial" placeholder="Optional"></div>
                <div class="field"><label>GPS Device ID</label><input type="text" name="asset_gps" placeholder="IMEI or device ID (optional)"></div>
                <div class="field"><label>Insurance Ref.</label><input type="text" name="asset_ins_ref" placeholder="Policy reference (optional)"></div>
                <div class="field"><label>Insurance Expiry</label><input type="date" name="asset_ins_expiry"></div>
            </div>
            <p style="font-size:.77rem;color:var(--text-light);margin:.85rem 0;"><i class="fa-solid fa-circle-info" style="margin-right:.3rem;"></i>New assets are created with status <strong>Pending</strong>. Activate them once deployed to revenue service.</p>
            <div style="display:flex;gap:.65rem;margin-top:.5rem;">
                <button type="submit" class="btn btn-fleet"><i class="fa-solid fa-plus"></i> Add Asset</button>
                <button type="button" class="btn btn-outline" onclick="document.getElementById('addAssetPanel').style.display='none'">Cancel</button>
            </div>
        </form>
    </div>
</div>

<?php /* ════ US-406: DOCUMENTS TAB ════════════════════════════════════
          All campaign types. Writes to campaign_documents (US-104 live).
          Soft-delete: is_active=0, record retained for audit.
       ════════════════════════════════════════════════════════════════ */ ?>
<?php elseif ($activeTab === 'documents'): ?>

<div class="card">
    <div class="card-header">
        <span class="card-title"><i class="fa-solid fa-folder-open"></i> Campaign Documents</span>
        <span style="font-size:.78rem;color:var(--text-light);"><?= $activeDocs ?> active · <?= count($documents)-$activeDocs ?> archived</span>
    </div>
    <?php if (empty($documents)): ?>
    <div style="padding:2.5rem;text-align:center;color:var(--text-light);font-size:.86rem;"><i class="fa-solid fa-folder-open" style="font-size:1.75rem;display:block;margin-bottom:.6rem;opacity:.3;"></i>No documents uploaded yet.</div>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="doc-table">
            <thead>
                <tr>
                    <th>Document</th>
                    <th>Type</th>
                    <th>Version</th>
                    <th>Access</th>
                    <th>Size</th>
                    <th>Uploaded</th>
                    <th style="min-width:120px;"></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($documents as $doc):
                $dtConf = $docTypeConfig[$doc['doc_type']] ?? ['Other','fa-file'];
                $daConf = $docAccessConfig[$doc['access_level']] ?? $docAccessConfig['accepted'];
            ?>
            <tr class="<?= $doc['is_active'] ? '' : 'doc-inactive' ?>">
                <td>
                    <div style="display:flex;align-items:center;gap:.6rem;">
                        <i class="fa-solid <?= $dtConf[1] ?>" style="color:var(--navy-light);font-size:1.1rem;flex-shrink:0;"></i>
                        <div>
                            <a href="<?= htmlspecialchars($doc['file_url']) ?>" target="_blank" style="font-weight:600;color:var(--navy);text-decoration:none;font-size:.88rem;"><?= htmlspecialchars($doc['label']) ?></a>
                            <?php if (!$doc['is_active']): ?><span style="font-size:.72rem;background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1;border-radius:99px;padding:1px 7px;margin-left:.4rem;">Archived</span><?php endif; ?>
                            <?php if ($doc['uploader_email']): ?><br><span style="font-size:.73rem;color:var(--text-light);"><?= htmlspecialchars($doc['uploader_email']) ?></span><?php endif; ?>
                        </div>
                    </div>
                </td>
                <td><span class="doc-type-badge"><i class="fa-solid <?= $dtConf[1] ?>" style="font-size:.7rem;"></i><?= $dtConf[0] ?></span></td>
                <td><?= $doc['version'] ? '<span class="doc-version">'.htmlspecialchars($doc['version']).'</span>' : '—' ?></td>
                <td><span class="doc-access-badge <?= $daConf[1] ?>"><?= $daConf[0] ?></span></td>
                <td style="font-size:.77rem;color:var(--text-muted);"><?= $doc['file_size_kb'] ? number_format($doc['file_size_kb']).' KB' : '—' ?></td>
                <td style="font-size:.78rem;color:var(--text-muted);white-space:nowrap;"><?= date('d M Y', strtotime($doc['uploaded_at'])) ?></td>
                <td>
                    <div class="action-cluster">
                        <a href="<?= htmlspecialchars($doc['file_url']) ?>" target="_blank" class="btn btn-outline btn-xs"><i class="fa-solid fa-download"></i></a>
                        <?php if ($doc['is_active']): ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Archive this document? The record is retained for audit — this cannot be permanently deleted.')">
                            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>"><input type="hidden" name="action" value="delete_document"><input type="hidden" name="doc_id" value="<?= (int)$doc['id'] ?>">
                            <button type="submit" class="btn btn-danger btn-xs"><i class="fa-solid fa-archive"></i></button>
                        </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- Upload new document -->
<div class="card">
    <div class="card-header"><span class="card-title"><i class="fa-solid fa-upload"></i> Upload Document</span></div>
    <div class="card-body">
        <form method="POST" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
            <input type="hidden" name="action" value="upload_document">
            <div class="form-grid">
                <div class="field">
                    <label><i class="fa-solid fa-tag"></i> Document Type <span class="req">*</span></label>
                    <select name="doc_type">
                        <?php foreach ($docTypeConfig as $val=>[$lbl,$icon]): ?>
                        <option value="<?= $val ?>"><?= $lbl ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label><i class="fa-solid fa-lock"></i> Access Level <span class="req">*</span></label>
                    <select name="doc_access">
                        <?php foreach ($docAccessConfig as $val=>[$lbl,$cls,$desc]): ?>
                        <option value="<?= $val ?>" <?= $val==='accepted'?'selected':'' ?>><?= $lbl ?> — <?= $desc ?></option>
                        <?php endforeach; ?>
                    </select>
                    <span class="hint">Controls who can see and download this document in the deal room.</span>
                </div>
                <div class="field span-2">
                    <label><i class="fa-solid fa-heading"></i> Label <span class="req">*</span></label>
                    <input type="text" name="doc_label" placeholder="e.g. Subscription Agreement v2 — June 2025" maxlength="200" required>
                </div>
                <div class="field">
                    <label><i class="fa-solid fa-code-branch"></i> Version <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label>
                    <input type="text" name="doc_version" placeholder="e.g. 1.0 or 2025-06" maxlength="20">
                </div>
                <div class="field">
                    <label><i class="fa-solid fa-link"></i> External URL <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(if no file upload)</span></label>
                    <input type="url" name="doc_url" placeholder="https://drive.google.com/…">
                    <span class="hint">Either upload a file OR provide a URL — not both (file upload takes priority).</span>
                </div>
                <div class="field span-2">
                    <label><i class="fa-solid fa-file-arrow-up"></i> File Upload</label>
                    <div class="upload-dropzone" id="docDropzone">
                        <input type="file" name="doc_file" accept=".pdf,.doc,.docx,.xls,.xlsx" onchange="showDocFileName(this)">
                        <div class="upload-dropzone-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="upload-dropzone-label" id="docFileLabel"><strong>Click to upload</strong> or drag &amp; drop</div>
                        <div class="upload-dropzone-sub">PDF, Word, Excel · max 10MB</div>
                    </div>
                </div>
            </div>
            <div style="margin-top:1.25rem;display:flex;gap:.65rem;align-items:center;">
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-upload"></i> Upload Document</button>
                <span style="font-size:.77rem;color:var(--text-light);">Previous versions of the same document type are retained for audit.</span>
            </div>
        </form>
    </div>
</div>

<!-- Document access legend -->
<div style="background:var(--surface-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.25rem;font-size:.82rem;color:var(--text-muted);">
    <strong style="color:var(--navy);">Access Level Guide</strong>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.65rem;margin-top:.65rem;">
        <?php foreach ($docAccessConfig as $val=>[$lbl,$cls,$desc]): ?>
        <div style="display:flex;align-items:center;gap:.5rem;"><span class="doc-access-badge <?= $cls ?>"><?= $lbl ?></span><span><?= $desc ?></span></div>
        <?php endforeach; ?>
    </div>
</div>

<?php /* ════ EXTERNAL INVITES TAB (summary, links to full page) ════════ */ ?>
<?php elseif ($activeTab === 'external_invites'): ?>
<?php
$externalRows = [];
try {
    $s = $pdo->prepare("SELECT guest_email, status, expires_at, created_at, re_requested_at FROM campaign_invites WHERE campaign_id=? AND invite_source='external_email' ORDER BY created_at DESC LIMIT 50");
    $s->execute([$campaignId]); $externalRows = $s->fetchAll();
} catch (PDOException $e) {}
function extBadge(string $status, string $exp): string {
    $isExp = strtotime($exp) < time();
    if ($status==='accepted') return '<span class="badge" style="background:var(--green-bg);color:var(--green);border-color:var(--green-bdr);">Accepted</span>';
    if ($status==='declined') return '<span class="badge" style="background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);">Declined</span>';
    if ($status==='revoked')  return '<span class="badge" style="background:#f1f5f9;color:#475569;border-color:#cbd5e1;">Revoked</span>';
    if ($isExp) return '<span class="badge" style="background:var(--amber-light);color:#78350f;border-color:var(--amber);">Expired</span>';
    return '<span class="badge" style="background:#eff4ff;color:var(--navy-mid);border-color:#c7d9f8;">Sent</span>';
}
?>
<div class="card">
    <div class="card-header" style="justify-content:space-between;">
        <span class="card-title"><i class="fa-solid fa-list-check"></i> External Invitations</span>
        <a href="/app/company/campaigns/external_invite.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="btn btn-amber btn-sm"><i class="fa-solid fa-paper-plane"></i> Send New Invite</a>
    </div>
    <div class="card-body">
        <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:1.25rem;">Invitations to email addresses not yet registered on Old Union. Full management — resend, revoke — is on the <a href="/app/company/campaigns/external_invite.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" style="color:var(--navy-light);font-weight:600;">External Invites</a> page.</p>
        <?php if (empty($externalRows)): ?>
        <div style="text-align:center;padding:2rem;color:var(--text-light);font-size:.86rem;"><i class="fa-solid fa-envelope" style="font-size:1.5rem;display:block;margin-bottom:.5rem;opacity:.4;"></i>No external invitations sent yet.</div>
        <?php else: ?>
        <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:.83rem;">
                <thead><tr><th style="text-align:left;padding:.45rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);">Email</th><th style="text-align:left;padding:.45rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);">Status</th><th style="text-align:left;padding:.45rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);">Sent</th><th style="text-align:left;padding:.45rem .75rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);">Expires</th></tr></thead>
                <tbody>
                <?php foreach ($externalRows as $er): ?>
                <tr><td style="padding:.55rem .75rem;border-bottom:1px solid var(--border);font-weight:500;"><?= htmlspecialchars($er['guest_email']) ?></td><td style="padding:.55rem .75rem;border-bottom:1px solid var(--border);"><?= extBadge($er['status'],$er['expires_at']) ?></td><td style="padding:.55rem .75rem;border-bottom:1px solid var(--border);font-size:.78rem;color:var(--text-muted);"><?= date('d M Y', strtotime($er['created_at'])) ?></td><td style="padding:.55rem .75rem;border-bottom:1px solid var(--border);font-size:.78rem;color:<?= strtotime($er['expires_at'])<time()?'var(--error)':'var(--text-muted)' ?>;"><?= date('d M H:i', strtotime($er['expires_at'])) ?></td></tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <div style="margin-top:1rem;text-align:right;"><a href="/app/company/campaigns/external_invite.php?uuid=<?= urlencode($companyUuid) ?>&cid=<?= urlencode($campaignUuid) ?>" class="btn btn-outline btn-sm"><i class="fa-solid fa-arrow-up-right-from-square"></i> Full Invite Management →</a></div>
        <?php endif; ?>
    </div>
</div>
<?php endif; /* end tab switch */ ?>

</main>
</div>

<script>
function showDocFileName(input) {
    if (input.files && input.files[0]) {
        const f = input.files[0];
        document.getElementById('docFileLabel').innerHTML =
            '<strong>' + f.name + '</strong> — ' + Math.round(f.size/1024) + ' KB selected';
    }
}
// Auto-show add asset panel if there was an add_asset error
<?php if (!empty($errors) && ($_POST['action']??'')==='add_asset'): ?>
document.addEventListener('DOMContentLoaded',()=>{
    const p=document.getElementById('addAssetPanel');
    if(p) p.style.display='block';
});
<?php endif; ?>
</script>
</body>
</html>
