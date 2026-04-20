<?php
// ============================================================
// company/campaigns/wizard.php
// SPV Campaign Setup Wizard
//
// Step 1 — Basics          (title, type, timeline)
// Step 2 — SPV Identity    (registered name, CIPC number, description)
// Step 3 — SPV Address     (same-as-parent toggle, or own address)
// Step 4 — Targets         (raise amounts, contributor limits)
// Step 5 — Deal Terms      (revenue share, co-op, or fleet waterfall)
//
// Fleet campaigns (campaign_type = 'fleet_asset') get 9 steps:
// Step 6 — Pitch & Funds + Asset Register (US-403)
// Step 7 — Financial Model / Projections  (US-404) ← fleet only
// Step 8 — Highlights
// Step 9 — Review & Submit
//
// Non-fleet campaigns keep 8 steps:
// Step 6 — Pitch & Funds
// Step 7 — Highlights
// Step 8 — Review & Submit
//
// ── Team C changes (Phase 1 — shipped) ──────────────────────
// US-401  Step 1: fleet_asset type card + sub-fields
// US-402  Step 5: fleet waterfall branch (session-only while
//         awaiting Team A migration — now WIRED to DB below)
//
// ── Team C changes (Phase 2 — this commit) ──────────────────
// US-402  campaign_terms fleet columns now written to DB.
//         Team A US-101 migration confirmed live.
// US-403  Step 6 fleet section: asset register dynamic rows,
//         saved to campaign_assets (US-102 table live).
// US-404  Step 7 fleet only: monthly projection model,
//         saved via FleetService::bulkUpsertProjections()
//         (US-103 table live, FleetService confirmed by Team A).
// ============================================================

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/company_uploads.php';
require_once '../../includes/database.php';
require_once '../../includes/FleetService.php'; // US-403/404 — Team A service

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$companyUuid  = trim($_GET['uuid'] ?? '');
$campaignUuid = trim($_GET['cid']  ?? '');

$company = getCompanyByUuid($companyUuid);
if (!$company) { die('Company not found.'); }

requireCompanyRole($company['id'], 'admin');

if ($company['status'] !== 'active' || !$company['verified']) {
    redirect("/app/company/dashboard.php?uuid=$companyUuid&error=not_verified");
}

$pdo       = Database::getInstance();
$userId    = $_SESSION['user_id'];
$companyId = $company['id'];

/* ── Load campaign ─────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM funding_campaigns WHERE uuid = :uuid AND company_id = :cid");
$stmt->execute(['uuid' => $campaignUuid, 'cid' => $companyId]);
$campaign = $stmt->fetch();
if (!$campaign) { die('Campaign not found.'); }

if (!in_array($campaign['status'], ['draft'], true)) {
    die('This campaign can no longer be edited in the wizard.');
}

$campaignId = (int)$campaign['id'];
$sessKey    = 'wizard_campaign_' . $campaignId;
$csrf_token = generateCSRFToken();

/* ── Load related rows ─────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM campaign_terms WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$terms = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("SELECT * FROM campaign_pitch WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$pitchRow = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("SELECT * FROM campaign_kyc WHERE campaign_id = ?");
$stmt->execute([$campaignId]);
$kycRow = $stmt->fetch() ?: [];

$stmt = $pdo->prepare("SELECT label, value FROM campaign_highlights WHERE campaign_id = ? ORDER BY sort_order ASC");
$stmt->execute([$campaignId]);
$existingHighlights = $stmt->fetchAll();

/* ── Load parent company address ────────────── */
$stmt = $pdo->prepare("SELECT * FROM company_filter WHERE company_id = ?");
$stmt->execute([$companyId]);
$parentAddress = $stmt->fetch() ?: [];

/* ── Seed session on first visit ───────────── */
if (!isset($_SESSION[$sessKey])) {
    $existingUof = [];
    if (!empty($campaign['use_of_funds'])) {
        $existingUof = json_decode($campaign['use_of_funds'], true) ?: [];
    }

    // US-403 (Team C Phase 2): Load existing fleet assets from campaign_assets
    $existingFleetAssets = [];
    if ($campaign['campaign_type'] === 'fleet_asset') {
        $stmt = $pdo->prepare("
            SELECT asset_label, make, model, year, acquisition_cost,
                   deployment_platform, serial_number
            FROM campaign_assets
            WHERE campaign_id = ? AND status = 'pending'
            ORDER BY asset_label ASC
        ");
        $stmt->execute([$campaignId]);
        $existingFleetAssets = $stmt->fetchAll();
    }

    // US-404 (Team C Phase 2): Load existing projection rows from campaign_projections
    $existingProjections = ($campaign['campaign_type'] === 'fleet_asset')
        ? FleetService::getProjections($campaignId)
        : [];

    $_SESSION[$sessKey] = [
        // Step 1 — Basics
        'title'                    => $campaign['title']         ?? '',
        'tagline'                  => $campaign['tagline']       ?? '',
        'campaign_type'            => $campaign['campaign_type'] ?? 'revenue_share',
        'opens_at'                 => $campaign['opens_at']  ? date('Y-m-d', strtotime($campaign['opens_at']))  : '',
        'closes_at'                => $campaign['closes_at'] ? date('Y-m-d', strtotime($campaign['closes_at'])) : '',
        // Step 2 — SPV Identity
        'spv_registered_name'      => $campaign['spv_registered_name']    ?? '',
        'spv_registration_number'  => $campaign['spv_registration_number'] ?? '',
        'spv_description'          => $campaign['spv_description']        ?? '',
        'spv_email'                => $campaign['spv_email']              ?? '',
        'spv_phone'                => $campaign['spv_phone']              ?? '',
        'spv_website'              => $campaign['spv_website']            ?? '',
        // Step 3 — SPV Address
        'spv_address_same_as_company' => (int)($campaign['spv_address_same_as_company'] ?? 1),
        'spv_province'             => $campaign['spv_province']     ?? '',
        'spv_municipality'         => $campaign['spv_municipality'] ?? '',
        'spv_city'                 => $campaign['spv_city']         ?? '',
        'spv_suburb'               => $campaign['spv_suburb']       ?? '',
        'spv_area'                 => $campaign['spv_area']         ?? '',
        // Step 4 — Targets
        'raise_target'             => $campaign['raise_target']     ?? '',
        'raise_minimum'            => $campaign['raise_minimum']    ?? '',
        'raise_maximum'            => $campaign['raise_maximum']    ?? '',
        'min_contribution'         => $campaign['min_contribution'] ?? '500.00',
        'max_contribution'         => $campaign['max_contribution'] ?? '',
        'max_contributors'         => $campaign['max_contributors'] ?? 50,
        // Step 5 — Deal Terms (RS / co-op)
        'rs_percentage'            => $terms['revenue_share_percentage']      ?? '',
        'rs_duration'              => $terms['revenue_share_duration_months'] ?? '',
        'co_unit_name'             => $terms['unit_name']             ?? '',
        'co_unit_price'            => $terms['unit_price']            ?? '',
        'co_units_total'           => $terms['total_units_available'] ?? '',
        // Step 5 — Fleet waterfall (US-401/402, now wired to DB via US-101 cols)
        'fleet_asset_type'             => $terms['asset_type']                ?? '',
        'fleet_asset_count'            => $terms['asset_count']               ?? '',
        'fleet_acq_cost_per_unit'      => $terms['acquisition_cost_per_unit'] ?? '',
        'fleet_total_acq_cost'         => $terms['total_acquisition_cost']    ?? '',
        'fleet_hurdle_rate'            => $terms['hurdle_rate']               ?? '',
        'fleet_investor_waterfall_pct' => $terms['investor_waterfall_pct']    ?? '',
        'fleet_management_fee_pct'     => $terms['management_fee_pct']        ?? '',
        'fleet_management_fee_basis'   => $terms['management_fee_basis']      ?? 'gross',
        'fleet_distribution_frequency' => $terms['distribution_frequency']    ?? 'monthly',
        'fleet_term_months'            => $terms['term_months']               ?? '',
        // Step 6 — Pitch & Funds
        'investment_thesis'        => $pitchRow['investment_thesis']  ?? '',
        'use_of_funds'             => $existingUof,
        'risk_factors'             => $pitchRow['risk_factors']       ?? '',
        'exit_strategy'            => $pitchRow['exit_strategy']      ?? '',
        'spv_team_overview'        => $pitchRow['spv_team_overview']  ?? '',
        'spv_traction'             => $pitchRow['spv_traction']       ?? '',
        'pitch_deck_url'           => $pitchRow['pitch_deck_url']     ?? '',
        'pitch_video_url'          => $pitchRow['pitch_video_url']    ?? '',
        // Step 6 fleet — Asset register (US-403, Team C Phase 2)
        'fleet_assets'             => $existingFleetAssets,
        // Step 7 fleet — Projection model (US-404, Team C Phase 2)
        'fleet_projection_rows'    => $existingProjections,
        // Step 7/8 — Highlights
        'highlights'               => !empty($existingHighlights)
                                        ? $existingHighlights
                                        : [
                                            ['label' => 'Total Raise',   'value' => ''],
                                            ['label' => 'Revenue Share', 'value' => ''],
                                            ['label' => 'Duration',      'value' => ''],
                                          ],
        // Branding
        'spv_logo'                 => $campaign['spv_logo']   ?? '',
        'spv_banner'               => $campaign['spv_banner'] ?? '',
        // KYC docs
        'kyc_registration_document' => $kycRow['registration_document']  ?? '',
        'kyc_proof_of_address'      => $kycRow['proof_of_address']        ?? '',
        'kyc_director_id'           => $kycRow['director_id_document']    ?? '',
        'kyc_tax_clearance'         => $kycRow['tax_clearance_document']  ?? '',
    ];
}

$data     = &$_SESSION[$sessKey];
$isFleet  = ($data['campaign_type'] === 'fleet_asset');

// Fleet campaigns get 9 steps; non-fleet keep 8
$totalSteps = $isFleet ? 9 : 8;

$step   = max(1, min($totalSteps, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$errors = [];

/* ═══════════════════════════════════════════════════
   POST HANDLER
═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $action   = $_POST['action'] ?? 'next';
    $postStep = (int)($_POST['step'] ?? 1);

    // Re-derive isFleet from POST in case type changed in step 1
    $postIsFleet = ($data['campaign_type'] === 'fleet_asset');

    if (empty($errors)) {

        /* ── Step 1 : Basics ──────────────────────── */
        if ($postStep === 1) {
            $title    = trim($_POST['title']         ?? '');
            $type     = trim($_POST['campaign_type'] ?? '');
            $opensAt  = trim($_POST['opens_at']      ?? '');
            $closesAt = trim($_POST['closes_at']     ?? '');

            if ($title === '') { $errors[] = 'Campaign title is required.'; }
            if (!in_array($type, ['revenue_share', 'cooperative_membership', 'fleet_asset'], true)) {
                $errors[] = 'Please select a campaign type.';
            }
            if ($opensAt  === '') { $errors[] = 'Opening date is required.'; }
            if ($closesAt === '') { $errors[] = 'Closing date is required.'; }
            if ($opensAt !== '' && $closesAt !== '' && $closesAt <= $opensAt) {
                $errors[] = 'Closing date must be after the opening date.';
            }
            if ($type === 'fleet_asset') {
                $fleetAssetSubType = trim($_POST['fleet_asset_type']  ?? '');
                $fleetAssetCount   = (int)($_POST['fleet_asset_count'] ?? 0);
                $validFleetTypes   = ['Electric Scooter','Motorcycle','Delivery Vehicle','Car','Taxi','Equipment','Other'];
                if (!in_array($fleetAssetSubType, $validFleetTypes, true)) {
                    $errors[] = 'Please select a fleet asset type.';
                }
                if ($fleetAssetCount < 1 || $fleetAssetCount > 200) {
                    $errors[] = 'Asset count must be between 1 and 200.';
                }
            }
            if (empty($errors)) {
                $data['title']         = $title;
                $data['tagline']       = trim($_POST['tagline'] ?? '');
                $data['campaign_type'] = $type;
                $data['opens_at']      = $opensAt;
                $data['closes_at']     = $closesAt;
                if ($type === 'fleet_asset') {
                    $data['fleet_asset_type']  = $fleetAssetSubType;
                    $data['fleet_asset_count'] = $fleetAssetCount;
                } else {
                    $data['fleet_asset_type']  = '';
                    $data['fleet_asset_count'] = '';
                }
                $isFleet     = ($type === 'fleet_asset');
                $postIsFleet = $isFleet;
                $totalSteps  = $isFleet ? 9 : 8;
            }
        }

        /* ── Step 2 : SPV Identity ────────────────── */
        if ($postStep === 2) {
            $regName = trim($_POST['spv_registered_name'] ?? '');
            if ($regName === '') { $errors[] = 'SPV registered name is required.'; }
            $websiteVal = trim($_POST['spv_website'] ?? '');
            if ($websiteVal !== '' && !filter_var($websiteVal, FILTER_VALIDATE_URL)) {
                $errors[] = 'SPV website must be a valid URL.';
            }
            $emailVal = trim($_POST['spv_email'] ?? '');
            if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'SPV email must be a valid email address.';
            }
            if (!empty($_FILES['spv_logo']['name'])) {
                $upload = uploadCompanyFile('spv_logo', $campaignUuid, 'logo');
                if ($upload['success']) { $data['spv_logo'] = $upload['path']; }
                else { $errors[] = $upload['error']; }
            }
            if (empty($errors) && !empty($_FILES['spv_banner']['name'])) {
                $upload = uploadCompanyFile('spv_banner', $campaignUuid, 'banner');
                if ($upload['success']) { $data['spv_banner'] = $upload['path']; }
                else { $errors[] = $upload['error']; }
            }
            if (empty($errors)) {
                $data['spv_registered_name']     = $regName;
                $data['spv_registration_number'] = trim($_POST['spv_registration_number'] ?? '');
                $data['spv_description']         = trim($_POST['spv_description'] ?? '');
                $data['spv_email']               = $emailVal;
                $data['spv_phone']               = trim($_POST['spv_phone'] ?? '');
                $data['spv_website']             = $websiteVal;
            }
        }

        /* ── Step 3 : SPV Address ─────────────────── */
        if ($postStep === 3) {
            $sameAsParent = isset($_POST['spv_address_same_as_company']) ? 1 : 0;
            $data['spv_address_same_as_company'] = $sameAsParent;
            if (!$sameAsParent) {
                $validProvinces = ['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'];
                $province = trim($_POST['spv_province'] ?? '');
                $area     = trim($_POST['spv_area']     ?? '');
                if (!in_array($province, $validProvinces, true)) { $errors[] = 'Please select a valid province.'; }
                if (!in_array($area, ['urban','township','rural'], true)) { $errors[] = 'Please select an area type.'; }
                if (empty($errors)) {
                    $data['spv_province']     = $province;
                    $data['spv_municipality'] = trim($_POST['spv_municipality'] ?? '');
                    $data['spv_city']         = trim($_POST['spv_city']         ?? '');
                    $data['spv_suburb']       = trim($_POST['spv_suburb']       ?? '');
                    $data['spv_area']         = $area;
                }
            } else {
                $data['spv_province'] = $data['spv_municipality'] = $data['spv_city'] = $data['spv_suburb'] = $data['spv_area'] = '';
            }
        }

        /* ── Step 4 : Targets ─────────────────────── */
        if ($postStep === 4) {
            $raiseTarget     = trim($_POST['raise_target']     ?? '');
            $raiseMinimum    = trim($_POST['raise_minimum']    ?? '');
            $raiseMaximum    = trim($_POST['raise_maximum']    ?? '');
            $minContribution = trim($_POST['min_contribution'] ?? '');
            $maxContribution = trim($_POST['max_contribution'] ?? '');
            $maxContributors = (int)($_POST['max_contributors'] ?? 50);
            if (!is_numeric($raiseTarget) || (float)$raiseTarget <= 0)   { $errors[] = 'Raise target must be a positive amount.'; }
            if (!is_numeric($raiseMinimum) || (float)$raiseMinimum <= 0) { $errors[] = 'Minimum raise must be a positive amount.'; }
            if (is_numeric($raiseMinimum) && is_numeric($raiseTarget) && (float)$raiseMinimum > (float)$raiseTarget) {
                $errors[] = 'Minimum raise cannot exceed the raise target.';
            }
            if ($raiseMaximum !== '' && (!is_numeric($raiseMaximum) || (float)$raiseMaximum < (float)$raiseTarget)) {
                $errors[] = 'Hard cap must be ≥ raise target.';
            }
            if (!is_numeric($minContribution) || (float)$minContribution < 100) { $errors[] = 'Minimum contribution must be at least R100.'; }
            if ($maxContributors < 1 || $maxContributors > 50) { $errors[] = 'Contributor cap must be 1–50.'; }
            if (empty($errors)) {
                $data['raise_target']     = $raiseTarget;
                $data['raise_minimum']    = $raiseMinimum;
                $data['raise_maximum']    = $raiseMaximum;
                $data['min_contribution'] = $minContribution;
                $data['max_contribution'] = $maxContribution;
                $data['max_contributors'] = $maxContributors;
            }
        }

        /* ── Step 5 : Deal Terms ──────────────────── */
        if ($postStep === 5) {
            if ($data['campaign_type'] === 'revenue_share') {
                $rsPct      = trim($_POST['rs_percentage'] ?? '');
                $rsDuration = trim($_POST['rs_duration']   ?? '');
                if (!is_numeric($rsPct) || (float)$rsPct <= 0 || (float)$rsPct > 100) {
                    $errors[] = 'Revenue share % must be 0.01–100.';
                }
                if (!ctype_digit($rsDuration) || (int)$rsDuration < 1) {
                    $errors[] = 'Duration must be a whole number of months (min 1).';
                }
                if (empty($errors)) {
                    $data['rs_percentage'] = $rsPct;
                    $data['rs_duration']   = $rsDuration;
                }

            } elseif ($data['campaign_type'] === 'cooperative_membership') {
                $unitName   = trim($_POST['co_unit_name']   ?? '');
                $unitPrice  = trim($_POST['co_unit_price']  ?? '');
                $unitsTotal = trim($_POST['co_units_total'] ?? '');
                if ($unitName === '') { $errors[] = 'Membership unit name is required.'; }
                if (!is_numeric($unitPrice) || (float)$unitPrice <= 0) { $errors[] = 'Unit price must be positive.'; }
                if (!ctype_digit($unitsTotal) || (int)$unitsTotal < 1) { $errors[] = 'Total units must be a positive integer.'; }
                if (empty($errors)) {
                    $data['co_unit_name']   = $unitName;
                    $data['co_unit_price']  = $unitPrice;
                    $data['co_units_total'] = $unitsTotal;
                }

            } elseif ($data['campaign_type'] === 'fleet_asset') {
                // ── US-402 (Team C Phase 2) — validation (unchanged from Phase 1) ──
                $hurdleRate   = trim($_POST['fleet_hurdle_rate']             ?? '');
                $waterfallPct = trim($_POST['fleet_investor_waterfall_pct']  ?? '');
                $mgmtFeePct   = trim($_POST['fleet_management_fee_pct']      ?? '');
                $mgmtFeeBasis = trim($_POST['fleet_management_fee_basis']    ?? 'gross');
                $distFreq     = trim($_POST['fleet_distribution_frequency']  ?? 'monthly');
                $termMonths   = trim($_POST['fleet_term_months']             ?? '');
                $acqPerUnit   = trim($_POST['fleet_acq_cost_per_unit']       ?? '');

                if ($hurdleRate !== '' && (!is_numeric($hurdleRate) || (float)$hurdleRate < 0 || (float)$hurdleRate > 100)) {
                    $errors[] = 'Hurdle rate must be 0–100%.';
                }
                if (!is_numeric($waterfallPct) || (float)$waterfallPct <= 0 || (float)$waterfallPct > 100) {
                    $errors[] = 'Investor waterfall % is required (0.01–100).';
                }
                if (!is_numeric($mgmtFeePct) || (float)$mgmtFeePct < 0 || (float)$mgmtFeePct > 50) {
                    $errors[] = 'Management fee must be 0–50%.';
                }
                if (!in_array($mgmtFeeBasis, ['gross','net_after_hurdle'], true)) { $mgmtFeeBasis = 'gross'; }
                if (!in_array($distFreq, ['monthly','quarterly'], true))           { $distFreq = 'monthly'; }
                if (!ctype_digit($termMonths) || (int)$termMonths < 1 || (int)$termMonths > 120) {
                    $errors[] = 'Term must be 1–120 months.';
                }
                if ($acqPerUnit !== '' && (!is_numeric($acqPerUnit) || (float)$acqPerUnit < 0)) {
                    $errors[] = 'Acquisition cost per unit must be a positive number.';
                }

                if (empty($errors)) {
                    $data['fleet_hurdle_rate']            = $hurdleRate;
                    $data['fleet_investor_waterfall_pct'] = $waterfallPct;
                    $data['fleet_management_fee_pct']     = $mgmtFeePct;
                    $data['fleet_management_fee_basis']   = $mgmtFeeBasis;
                    $data['fleet_distribution_frequency'] = $distFreq;
                    $data['fleet_term_months']            = $termMonths;
                    $data['fleet_acq_cost_per_unit']      = $acqPerUnit;
                    $count = (int)($data['fleet_asset_count'] ?? 0);
                    $cpu   = (float)($acqPerUnit ?: 0);
                    $data['fleet_total_acq_cost'] = ($count > 0 && $cpu > 0)
                        ? number_format($count * $cpu, 2, '.', '')
                        : '';
                }
            }
        }

        /* ── Step 6 : Pitch & Funds (+ fleet asset register for US-403) ── */
        if ($postStep === 6) {
            // Pitch deck upload
            if (!empty($_FILES['pitch_deck']['name'])) {
                $upload = uploadCompanyFile('pitch_deck', $campaignUuid, 'document');
                if ($upload['success']) { $data['pitch_deck_url'] = $upload['path']; }
                else { $errors[] = $upload['error']; }
            }
            $deckUrl  = trim($_POST['pitch_deck_url_text'] ?? '');
            $videoUrl = trim($_POST['pitch_video_url']      ?? '');
            if ($deckUrl  !== '' && !filter_var($deckUrl,  FILTER_VALIDATE_URL)) { $errors[] = 'Pitch deck URL must be a valid URL.'; }
            if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) { $errors[] = 'Pitch video URL must be a valid URL.'; }

            // ── US-403 (Team C Phase 2) — Fleet asset register validation ──────
            if ($postIsFleet) {
                $assetLabels    = $_POST['fa_label']    ?? [];
                $assetMakes     = $_POST['fa_make']     ?? [];
                $assetModels    = $_POST['fa_model']    ?? [];
                $assetYears     = $_POST['fa_year']     ?? [];
                $assetCosts     = $_POST['fa_cost']     ?? [];
                $assetPlatforms = $_POST['fa_platform'] ?? [];
                $assetSerials   = $_POST['fa_serial']   ?? [];

                $maxAssets = max(1, (int)($data['fleet_asset_count'] ?? 200));
                $assetRows = [];

                foreach ($assetLabels as $i => $lbl) {
                    $lbl = trim($lbl);
                    $cost = trim($assetCosts[$i] ?? '');
                    if ($lbl === '' && $cost === '') { continue; } // skip blank rows
                    if ($lbl === '') { $errors[] = 'Asset label is required on row ' . ($i + 1) . '.'; continue; }
                    if ($cost !== '' && (!is_numeric($cost) || (float)$cost < 0)) {
                        $errors[] = 'Acquisition cost must be a positive number on row ' . ($i + 1) . '.';
                    }
                    $platform = trim($assetPlatforms[$i] ?? 'other');
                    if (!in_array($platform, ['uber_eats','bolt','both','direct','other'], true)) {
                        $platform = 'other';
                    }
                    $assetRows[] = [
                        'asset_label'        => $lbl,
                        'make'               => trim($assetMakes[$i]  ?? ''),
                        'model'              => trim($assetModels[$i] ?? ''),
                        'year'               => (int)($assetYears[$i] ?? 0) ?: null,
                        'acquisition_cost'   => $cost !== '' ? (float)$cost : 0,
                        'deployment_platform'=> $platform,
                        'serial_number'      => trim($assetSerials[$i] ?? '') ?: null,
                    ];
                    if (count($assetRows) >= $maxAssets) { break; }
                }
                if (empty($errors)) {
                    $data['fleet_assets'] = $assetRows;
                }
            }
            // ── End US-403 validation ────────────────────────────────────────────

            if (empty($errors)) {
                $data['investment_thesis'] = trim($_POST['investment_thesis'] ?? '');
                $data['risk_factors']      = trim($_POST['risk_factors']      ?? '');
                $data['exit_strategy']     = trim($_POST['exit_strategy']     ?? '');
                $data['spv_team_overview'] = trim($_POST['spv_team_overview'] ?? '');
                $data['spv_traction']      = trim($_POST['spv_traction']      ?? '');
                if (empty($data['pitch_deck_url']) && $deckUrl !== '') {
                    $data['pitch_deck_url'] = $deckUrl;
                }
                $data['pitch_video_url'] = $videoUrl;
                $uofLabels  = $_POST['uof_label']  ?? [];
                $uofAmounts = $_POST['uof_amount']  ?? [];
                $uof = [];
                foreach ($uofLabels as $i => $lbl) {
                    $lbl = trim($lbl); $amt = trim($uofAmounts[$i] ?? '');
                    if ($lbl !== '' || $amt !== '') { $uof[] = ['label' => $lbl, 'amount' => $amt]; }
                }
                $data['use_of_funds'] = $uof;
            }
        }

        /* ── Step 7 fleet only : Financial Model / Projections (US-404) ── */
        if ($postStep === 7 && $postIsFleet) {
            $projPeriods  = $_POST['proj_period_number']  ?? [];
            $projLabels   = $_POST['proj_label']          ?? [];
            $projGross    = $_POST['proj_gross']          ?? [];
            $projEnergy   = $_POST['proj_energy']         ?? [];
            $projMaint    = $_POST['proj_maint']          ?? [];
            $projInsur    = $_POST['proj_insur']          ?? [];
            $projMgmtFee  = $_POST['proj_mgmt_fee']       ?? [];
            $projOpex     = $_POST['proj_opex']           ?? [];
            $projNet      = $_POST['proj_net']            ?? [];
            $projInvDist  = $_POST['proj_inv_dist']       ?? [];
            $projHurdle   = $_POST['proj_hurdle']         ?? [];
            $projNotes    = $_POST['proj_notes']          ?? [];

            $projRows = [];
            foreach ($projPeriods as $i => $periodNum) {
                $periodNum = (int)$periodNum;
                if ($periodNum < 1 || $periodNum > 60) { continue; }
                $gross = (float)($projGross[$i] ?? 0);
                if ($gross <= 0 && (float)($projNet[$i] ?? 0) <= 0) { continue; } // skip empty rows
                $projRows[] = [
                    'period_number'          => $periodNum,
                    'label'                  => trim($projLabels[$i] ?? ('Month ' . $periodNum)),
                    'gross_revenue_projected'=> $gross,
                    'energy_cost'            => (float)($projEnergy[$i]  ?? 0),
                    'maintenance_reserve'    => (float)($projMaint[$i]   ?? 0),
                    'insurance_cost'         => (float)($projInsur[$i]   ?? 0),
                    'management_fee'         => (float)($projMgmtFee[$i] ?? 0),
                    'opex_total'             => (float)($projOpex[$i]    ?? 0),
                    'net_to_spv'             => (float)($projNet[$i]     ?? 0),
                    'investor_distribution'  => (float)($projInvDist[$i] ?? 0),
                    'hurdle_cleared'         => !empty($projHurdle[$i]) ? 1 : 0,
                    'notes'                  => trim($projNotes[$i] ?? '') ?: null,
                ];
            }
            $data['fleet_projection_rows'] = $projRows;
        }

        /* ── Step 7 non-fleet / Step 8 fleet : Highlights ──────────────── */
        $highlightsStep = $postIsFleet ? 8 : 7;
        if ($postStep === $highlightsStep) {
            $hlLabels = $_POST['hl_label'] ?? [];
            $hlValues = $_POST['hl_value'] ?? [];
            $highlights = [];
            foreach ($hlLabels as $i => $lbl) {
                $lbl = trim($lbl); $val = trim($hlValues[$i] ?? '');
                if ($lbl !== '' || $val !== '') { $highlights[] = ['label' => $lbl, 'value' => $val]; }
            }
            $data['highlights'] = $highlights ?: [
                ['label' => 'Total Raise',  'value' => ''],
                ['label' => 'Revenue Share','value' => ''],
                ['label' => 'Duration',     'value' => ''],
            ];
        }

        /* ── Persist to DB on every valid save ────── */
        if (empty($errors)) {

            $isFinalSubmit = ($postStep === $totalSteps && $action === 'submit');
            $newStatus     = $isFinalSubmit ? 'under_review' : 'draft';

            /* ── funding_campaigns ── */
            $pdo->prepare("
                UPDATE funding_campaigns SET
                    title                        = :title,
                    tagline                      = :tagline,
                    campaign_type                = :campaign_type,
                    opens_at                     = :opens_at,
                    closes_at                    = :closes_at,
                    spv_registered_name          = :spv_registered_name,
                    spv_registration_number      = :spv_reg_no,
                    spv_description              = :spv_description,
                    spv_email                    = :spv_email,
                    spv_phone                    = :spv_phone,
                    spv_website                  = :spv_website,
                    spv_logo                     = :spv_logo,
                    spv_banner                   = :spv_banner,
                    spv_address_same_as_company  = :same_addr,
                    spv_province                 = :spv_province,
                    spv_municipality             = :spv_municipality,
                    spv_city                     = :spv_city,
                    spv_suburb                   = :spv_suburb,
                    spv_area                     = :spv_area,
                    raise_target                 = :raise_target,
                    raise_minimum                = :raise_minimum,
                    raise_maximum                = :raise_maximum,
                    min_contribution             = :min_contribution,
                    max_contribution             = :max_contribution,
                    max_contributors             = :max_contributors,
                    use_of_funds                 = :use_of_funds,
                    status                       = :status
                WHERE id = :id
            ")->execute([
                'title'            => $data['title'],
                'tagline'          => $data['tagline']       ?: null,
                'campaign_type'    => $data['campaign_type'],
                'opens_at'         => $data['opens_at']      ?: null,
                'closes_at'        => $data['closes_at']     ?: null,
                'spv_registered_name' => $data['spv_registered_name'] ?: null,
                'spv_reg_no'       => $data['spv_registration_number'] ?: null,
                'spv_description'  => $data['spv_description'] ?: null,
                'spv_email'        => $data['spv_email']     ?: null,
                'spv_phone'        => $data['spv_phone']     ?: null,
                'spv_website'      => $data['spv_website']   ?: null,
                'spv_logo'         => $data['spv_logo']      ?: null,
                'spv_banner'       => $data['spv_banner']    ?: null,
                'same_addr'        => (int)$data['spv_address_same_as_company'],
                'spv_province'     => $data['spv_province']     ?: null,
                'spv_municipality' => $data['spv_municipality'] ?: null,
                'spv_city'         => $data['spv_city']         ?: null,
                'spv_suburb'       => $data['spv_suburb']       ?: null,
                'spv_area'         => $data['spv_area']         ?: null,
                'raise_target'     => $data['raise_target']    ?: 0,
                'raise_minimum'    => $data['raise_minimum']   ?: 0,
                'raise_maximum'    => $data['raise_maximum']   ?: null,
                'min_contribution' => $data['min_contribution'] ?: 500,
                'max_contribution' => $data['max_contribution'] ?: null,
                'max_contributors' => (int)$data['max_contributors'],
                'use_of_funds'     => !empty($data['use_of_funds']) ? json_encode($data['use_of_funds']) : null,
                'status'           => $newStatus,
                'id'               => $campaignId,
            ]);

            /* ── campaign_terms upsert ── */
            $termsExists = !empty($terms);

            if ($data['campaign_type'] === 'revenue_share') {
                if ($termsExists) {
                    $pdo->prepare("UPDATE campaign_terms SET
                        revenue_share_percentage=:pct, revenue_share_duration_months=:dur,
                        unit_name=NULL, unit_price=NULL, total_units_available=NULL,
                        hurdle_rate=NULL, investor_waterfall_pct=NULL, management_fee_pct=NULL,
                        management_fee_basis=NULL, distribution_frequency=NULL, term_months=NULL,
                        asset_type=NULL, asset_count=NULL, acquisition_cost_per_unit=NULL, total_acquisition_cost=NULL
                    WHERE campaign_id=:cid")->execute(['pct'=>$data['rs_percentage']?:null,'dur'=>$data['rs_duration']?:null,'cid'=>$campaignId]);
                } else {
                    $pdo->prepare("INSERT INTO campaign_terms (campaign_id,revenue_share_percentage,revenue_share_duration_months) VALUES(:cid,:pct,:dur)")
                        ->execute(['cid'=>$campaignId,'pct'=>$data['rs_percentage']?:null,'dur'=>$data['rs_duration']?:null]);
                    $terms = ['campaign_id'=>$campaignId];
                }

            } elseif ($data['campaign_type'] === 'cooperative_membership') {
                if ($termsExists) {
                    $pdo->prepare("UPDATE campaign_terms SET
                        unit_name=:name, unit_price=:price, total_units_available=:total,
                        revenue_share_percentage=NULL, revenue_share_duration_months=NULL,
                        hurdle_rate=NULL, investor_waterfall_pct=NULL, management_fee_pct=NULL,
                        management_fee_basis=NULL, distribution_frequency=NULL, term_months=NULL,
                        asset_type=NULL, asset_count=NULL, acquisition_cost_per_unit=NULL, total_acquisition_cost=NULL
                    WHERE campaign_id=:cid")->execute(['name'=>$data['co_unit_name']?:null,'price'=>$data['co_unit_price']?:null,'total'=>$data['co_units_total']?:null,'cid'=>$campaignId]);
                } else {
                    $pdo->prepare("INSERT INTO campaign_terms (campaign_id,unit_name,unit_price,total_units_available) VALUES(:cid,:name,:price,:total)")
                        ->execute(['cid'=>$campaignId,'name'=>$data['co_unit_name']?:null,'price'=>$data['co_unit_price']?:null,'total'=>$data['co_units_total']?:null]);
                    $terms = ['campaign_id'=>$campaignId];
                }

            } elseif ($data['campaign_type'] === 'fleet_asset') {
                // ── US-402 (Team C Phase 2) — WIRED DB write ─────────────────────
                // Team A US-101 migration confirmed live. All 10 fleet columns exist.
                // Column contract from 001_us101_campaign_terms_fleet_extension.sql:
                //   hurdle_rate, investor_waterfall_pct, management_fee_pct,
                //   management_fee_basis ENUM, distribution_frequency ENUM,
                //   term_months, asset_type, asset_count,
                //   acquisition_cost_per_unit, total_acquisition_cost
                if ($termsExists) {
                    $pdo->prepare("UPDATE campaign_terms SET
                        hurdle_rate                   = :hr,
                        investor_waterfall_pct        = :iwp,
                        management_fee_pct            = :mfp,
                        management_fee_basis          = :mfb,
                        distribution_frequency        = :df,
                        term_months                   = :tm,
                        asset_type                    = :at,
                        asset_count                   = :ac,
                        acquisition_cost_per_unit     = :acp,
                        total_acquisition_cost        = :tac,
                        revenue_share_percentage      = NULL,
                        revenue_share_duration_months = NULL,
                        unit_name = NULL, unit_price = NULL, total_units_available = NULL
                    WHERE campaign_id = :cid")->execute([
                        'hr'  => $data['fleet_hurdle_rate']            ?: null,
                        'iwp' => $data['fleet_investor_waterfall_pct'] ?: null,
                        'mfp' => $data['fleet_management_fee_pct']     ?: null,
                        'mfb' => $data['fleet_management_fee_basis']   ?: null,
                        'df'  => $data['fleet_distribution_frequency'] ?: null,
                        'tm'  => $data['fleet_term_months']            ?: null,
                        'at'  => $data['fleet_asset_type']             ?: null,
                        'ac'  => $data['fleet_asset_count']            ?: null,
                        'acp' => $data['fleet_acq_cost_per_unit']      ?: null,
                        'tac' => $data['fleet_total_acq_cost']         ?: null,
                        'cid' => $campaignId,
                    ]);
                } else {
                    $pdo->prepare("INSERT INTO campaign_terms (
                        campaign_id, hurdle_rate, investor_waterfall_pct,
                        management_fee_pct, management_fee_basis, distribution_frequency,
                        term_months, asset_type, asset_count,
                        acquisition_cost_per_unit, total_acquisition_cost
                    ) VALUES (
                        :cid, :hr, :iwp, :mfp, :mfb, :df, :tm, :at, :ac, :acp, :tac
                    )")->execute([
                        'cid' => $campaignId,
                        'hr'  => $data['fleet_hurdle_rate']            ?: null,
                        'iwp' => $data['fleet_investor_waterfall_pct'] ?: null,
                        'mfp' => $data['fleet_management_fee_pct']     ?: null,
                        'mfb' => $data['fleet_management_fee_basis']   ?: null,
                        'df'  => $data['fleet_distribution_frequency'] ?: null,
                        'tm'  => $data['fleet_term_months']            ?: null,
                        'at'  => $data['fleet_asset_type']             ?: null,
                        'ac'  => $data['fleet_asset_count']            ?: null,
                        'acp' => $data['fleet_acq_cost_per_unit']      ?: null,
                        'tac' => $data['fleet_total_acq_cost']         ?: null,
                    ]);
                    $terms = ['campaign_id' => $campaignId];
                }
                // ── End US-402 wired write ────────────────────────────────────────
            }

            /* ── US-403 (Team C Phase 2): Save fleet assets to campaign_assets ── */
            // Runs whenever step 6 is saved for a fleet campaign.
            // We only touch status='pending' rows — active/damaged/sold
            // assets are managed exclusively via manage.php Assets tab.
            if ($data['campaign_type'] === 'fleet_asset' && !empty($data['fleet_assets'])) {
                $pdo->beginTransaction();
                try {
                    // Remove pending-only rows (not deployed yet) and re-insert
                    $pdo->prepare("DELETE FROM campaign_assets WHERE campaign_id = ? AND status = 'pending'")
                        ->execute([$campaignId]);

                    $insAsset = $pdo->prepare("
                        INSERT INTO campaign_assets (
                            uuid, campaign_id, asset_label, asset_type,
                            make, model, year, acquisition_cost,
                            serial_number, deployment_platform, status
                        ) VALUES (
                            :uuid, :cid, :label, :type,
                            :make, :model, :year, :cost,
                            :serial, :platform, 'pending'
                        )
                    ");
                    foreach ($data['fleet_assets'] as $fa) {
                        $insAsset->execute([
                            'uuid'     => generateUuidV4(),
                            'cid'      => $campaignId,
                            'label'    => $fa['asset_label'],
                            'type'     => $data['fleet_asset_type'] ?: 'Other',
                            'make'     => $fa['make']    ?: null,
                            'model'    => $fa['model']   ?: null,
                            'year'     => $fa['year']    ?: null,
                            'cost'     => (float)($fa['acquisition_cost'] ?? 0),
                            'serial'   => $fa['serial_number'] ?? null,
                            'platform' => $fa['deployment_platform'] ?? 'other',
                        ]);
                    }
                    $pdo->commit();
                } catch (Throwable $e) {
                    $pdo->rollBack();
                    error_log('[wizard US-403] campaign=' . $campaignId . ' — ' . $e->getMessage());
                    $errors[] = 'Could not save asset register. Please try again.';
                }
            }
            // ── End US-403 ────────────────────────────────────────────────────────

            /* ── US-404 (Team C Phase 2): Bulk-upsert projections ────────────── */
            // Only fires when Step 7 (fleet) is saved and rows exist.
            // FleetService::bulkUpsertProjections() uses INSERT...ON DUPLICATE KEY
            // UPDATE on the UNIQUE (campaign_id, period_number) constraint.
            if ($postStep === 7 && $data['campaign_type'] === 'fleet_asset'
                && !empty($data['fleet_projection_rows'])) {
                if (!FleetService::bulkUpsertProjections($campaignId, $data['fleet_projection_rows'])) {
                    error_log('[wizard US-404] bulkUpsertProjections returned false for campaign=' . $campaignId);
                    $errors[] = 'Could not save projection model. Please check the data and try again.';
                }
            }
            // ── End US-404 ────────────────────────────────────────────────────────

            if (!empty($errors)) {
                // Don't advance step if a DB write failed
                $step = $postStep;
            } else {
                /* ── campaign_pitch upsert ── */
                $pitchExists = !empty($pitchRow);
                if ($pitchExists) {
                    $pdo->prepare("UPDATE campaign_pitch SET
                        investment_thesis=:thesis, risk_factors=:risks, exit_strategy=:exit,
                        spv_team_overview=:team, spv_traction=:traction,
                        pitch_deck_url=:deck, pitch_video_url=:video, updated_at=NOW()
                    WHERE campaign_id=:cid")->execute([
                        'thesis'=>$data['investment_thesis']?:null,'risks'=>$data['risk_factors']?:null,
                        'exit'=>$data['exit_strategy']?:null,'team'=>$data['spv_team_overview']?:null,
                        'traction'=>$data['spv_traction']?:null,'deck'=>$data['pitch_deck_url']?:null,
                        'video'=>$data['pitch_video_url']?:null,'cid'=>$campaignId,
                    ]);
                } else {
                    $pdo->prepare("INSERT INTO campaign_pitch
                        (campaign_id,investment_thesis,risk_factors,exit_strategy,spv_team_overview,spv_traction,pitch_deck_url,pitch_video_url)
                        VALUES(:cid,:thesis,:risks,:exit,:team,:traction,:deck,:video)")->execute([
                        'cid'=>$campaignId,'thesis'=>$data['investment_thesis']?:null,'risks'=>$data['risk_factors']?:null,
                        'exit'=>$data['exit_strategy']?:null,'team'=>$data['spv_team_overview']?:null,
                        'traction'=>$data['spv_traction']?:null,'deck'=>$data['pitch_deck_url']?:null,'video'=>$data['pitch_video_url']?:null,
                    ]);
                    $pitchRow = ['campaign_id'=>$campaignId];
                }

                /* ── campaign_highlights ── */
                $pdo->prepare("DELETE FROM campaign_highlights WHERE campaign_id=?")->execute([$campaignId]);
                if (!empty($data['highlights'])) {
                    $hlStmt = $pdo->prepare("INSERT INTO campaign_highlights (campaign_id,label,value,sort_order) VALUES(:cid,:label,:value,:sort)");
                    foreach ($data['highlights'] as $i => $hl) {
                        if (!empty($hl['label']) || !empty($hl['value'])) {
                            $hlStmt->execute(['cid'=>$campaignId,'label'=>$hl['label'],'value'=>$hl['value'],'sort'=>$i]);
                        }
                    }
                }

                /* ── campaign_kyc ── */
                $hasKycData = !empty($data['kyc_registration_document']) || !empty($data['kyc_proof_of_address'])
                           || !empty($data['kyc_director_id'])           || !empty($data['kyc_tax_clearance']);
                if ($hasKycData) {
                    $kycStatus = $isFinalSubmit ? 'under_review' : ($kycRow['verification_status'] ?? 'pending');
                    if (!empty($kycRow)) {
                        $pdo->prepare("UPDATE campaign_kyc SET
                            registration_document=:rd, proof_of_address=:pa,
                            director_id_document=:did, tax_clearance_document=:tc, verification_status=:vs
                        WHERE campaign_id=:cid")->execute([
                            'rd'=>$data['kyc_registration_document']?:null,'pa'=>$data['kyc_proof_of_address']?:null,
                            'did'=>$data['kyc_director_id']?:null,'tc'=>$data['kyc_tax_clearance']?:null,
                            'vs'=>$kycStatus,'cid'=>$campaignId,
                        ]);
                    } else {
                        $pdo->prepare("INSERT INTO campaign_kyc
                            (campaign_id,registration_document,proof_of_address,director_id_document,tax_clearance_document,verification_status)
                            VALUES(:cid,:rd,:pa,:did,:tc,:vs)")->execute([
                            'cid'=>$campaignId,'rd'=>$data['kyc_registration_document']?:null,'pa'=>$data['kyc_proof_of_address']?:null,
                            'did'=>$data['kyc_director_id']?:null,'tc'=>$data['kyc_tax_clearance']?:null,'vs'=>$kycStatus,
                        ]);
                        $kycRow = ['campaign_id'=>$campaignId];
                    }
                }

                logCompanyActivity($companyId, $userId, 'Saved campaign wizard step ' . $postStep . ': ' . $data['title']);

                if ($isFinalSubmit) {
                    logCompanyActivity($companyId, $userId, 'Submitted campaign for review: ' . $data['title']);
                    unset($_SESSION[$sessKey]);
                    redirect("/app/company/campaigns/index.php?uuid=$companyUuid&submitted=1");
                }

                $step = ($action === 'back') ? max(1, $postStep - 1) : min($totalSteps, $postStep + 1);
            }
        }
    } else {
        $step = $postStep;
    }
}

/* ── Step metadata — dynamic for fleet (9 steps) vs non-fleet (8 steps) ── */
$isFleet    = ($data['campaign_type'] === 'fleet_asset');
$totalSteps = $isFleet ? 9 : 8;

if ($isFleet) {
    $steps = [
        1 => ['label'=>'Basics',          'icon'=>'fa-pen',            'desc'=>'Title, type & timeline'],
        2 => ['label'=>'SPV Identity',    'icon'=>'fa-building',       'desc'=>'Registered name, number & branding'],
        3 => ['label'=>'SPV Address',     'icon'=>'fa-map-pin',        'desc'=>'Registered address of the SPV'],
        4 => ['label'=>'Targets',         'icon'=>'fa-bullseye',       'desc'=>'Raise goal & contributor limits'],
        5 => ['label'=>'Terms',           'icon'=>'fa-file-contract',  'desc'=>'Fleet waterfall economics'],
        6 => ['label'=>'Pitch & Assets',  'icon'=>'fa-bullhorn',       'desc'=>'Investment case & asset register'],
        7 => ['label'=>'Financial Model', 'icon'=>'fa-chart-line',     'desc'=>'Monthly projections (US-404)'],
        8 => ['label'=>'Highlights',      'icon'=>'fa-star',           'desc'=>'Key stats for investors'],
        9 => ['label'=>'Review',          'icon'=>'fa-circle-check',   'desc'=>'Confirm & submit for review'],
    ];
} else {
    $steps = [
        1 => ['label'=>'Basics',       'icon'=>'fa-pen',           'desc'=>'Title, type & timeline'],
        2 => ['label'=>'SPV Identity', 'icon'=>'fa-building',      'desc'=>'Registered name, number & branding'],
        3 => ['label'=>'SPV Address',  'icon'=>'fa-map-pin',       'desc'=>'Registered address of the SPV'],
        4 => ['label'=>'Targets',      'icon'=>'fa-bullseye',      'desc'=>'Raise goal & contributor limits'],
        5 => ['label'=>'Terms',        'icon'=>'fa-file-contract', 'desc'=>'Deal structure & return terms'],
        6 => ['label'=>'Pitch & Funds','icon'=>'fa-bullhorn',      'desc'=>'Investment case & use of funds'],
        7 => ['label'=>'Highlights',   'icon'=>'fa-star',          'desc'=>'Key SPV stats for investors'],
        8 => ['label'=>'Review',       'icon'=>'fa-circle-check',  'desc'=>'Confirm & submit for review'],
    ];
}

$validProvinces = ['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'];
$fleetAssetSubTypes = ['Electric Scooter','Motorcycle','Delivery Vehicle','Car','Taxi','Equipment','Other'];
$fleetPlatforms = ['uber_eats'=>'Uber Eats','bolt'=>'Bolt','both'=>'Both','direct'=>'Direct','other'=>'Other'];

// Determine which step number contains Highlights and Review for this campaign type
$highlightsStepNum = $isFleet ? 8 : 7;
$reviewStepNum     = $isFleet ? 9 : 8;

function fmtCurrency($val) {
    if ($val === '' || $val === null) return '—';
    return 'R ' . number_format((float)$val, 2, '.', ' ');
}
function fmtDate2($val) {
    if ($val === '' || $val === null) return '—';
    return date('d M Y', strtotime($val));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>SPV Campaign Setup | Old Union</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:opsz,wght@9..40,300;9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($step === 3): ?><script src="/app/assets/js/sa-locations.js"></script><?php endif; ?>
<style>
:root{
    --navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;
    --amber:#f59e0b;--amber-light:#fef3c7;--amber-dark:#d97706;
    --green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;
    --surface:#ffffff;--surface-2:#f8f9fb;--border:#e4e7ec;--border-focus:#1a56b0;
    --text:#101828;--text-muted:#667085;--text-light:#98a2b3;
    --error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;
    /* US-401 Team C — fleet accent tokens */
    --fleet:#b45309;--fleet-pale:#fef3e2;--fleet-mid:#fde68a;
    --sidebar-w:280px;--radius:14px;--radius-sm:8px;
    --shadow-card:0 8px 32px rgba(11,37,69,.08),0 1px 3px rgba(11,37,69,.06);
    --shadow-btn:0 4px 12px rgba(15,59,122,.25);--transition:.22s cubic-bezier(.4,0,.2,1);
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
::-webkit-scrollbar{width:6px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
.wizard-shell{display:flex;min-height:100vh;}
.wizard-sidebar{width:var(--sidebar-w);background:var(--navy);min-height:100vh;padding:3rem 2rem;display:flex;flex-direction:column;position:sticky;top:0;flex-shrink:0;overflow:hidden;}
.wizard-sidebar::before{content:'';position:absolute;bottom:-80px;right:-80px;width:300px;height:300px;border-radius:50%;border:60px solid rgba(245,158,11,.07);pointer-events:none;}
.sidebar-brand{margin-bottom:2rem;}
.sidebar-brand-logo{font-family:'DM Serif Display',serif;font-size:1.5rem;color:#fff;line-height:1;margin-bottom:.25rem;}
.sidebar-brand-sub{font-size:.78rem;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.1em;}
.step-list{list-style:none;flex:1;}
.step-item{display:flex;align-items:flex-start;gap:1rem;padding:.65rem 0;position:relative;}
.step-item:not(:last-child)::after{content:'';position:absolute;left:19px;top:calc(.65rem + 38px);width:2px;height:calc(100% - 14px);background:rgba(255,255,255,.1);}
.step-item.done:not(:last-child)::after{background:rgba(245,158,11,.35);}
.step-icon{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);border:2px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:.85rem;color:rgba(255,255,255,.4);flex-shrink:0;position:relative;z-index:1;}
.step-item.done   .step-icon{background:rgba(245,158,11,.2);border-color:var(--amber);color:var(--amber);}
.step-item.active .step-icon{background:var(--amber);border-color:var(--amber);color:var(--navy);box-shadow:0 0 0 5px rgba(245,158,11,.2);}
.step-text{padding-top:.15rem;}
.step-label{font-size:.82rem;font-weight:600;color:rgba(255,255,255,.4);line-height:1;margin-bottom:.15rem;}
.step-item.done   .step-label{color:rgba(255,255,255,.65);}
.step-item.active .step-label{color:#fff;}
.step-desc{font-size:.7rem;color:rgba(255,255,255,.22);}
.step-item.active .step-desc{color:rgba(255,255,255,.48);}
.sidebar-footer{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.08);}
.sidebar-footer-label{font-size:.7rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.25rem;}
.sidebar-footer-val{font-size:.88rem;font-weight:600;color:rgba(255,255,255,.7);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sidebar-footer-meta{font-size:.75rem;color:rgba(255,255,255,.32);margin-top:.25rem;}
.mobile-progress{display:none;background:var(--navy);padding:1.25rem 1.5rem;position:sticky;top:0;z-index:99;}
.mob-prog-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;}
.mob-prog-label{font-size:.9rem;font-weight:600;color:#fff;}
.mob-prog-count{font-size:.8rem;color:rgba(255,255,255,.5);}
.mob-prog-bar{height:4px;background:rgba(255,255,255,.15);border-radius:99px;overflow:hidden;}
.mob-prog-fill{height:100%;background:var(--amber);border-radius:99px;}
.wizard-main{flex:1;display:flex;flex-direction:column;min-height:100vh;padding:3rem 3.5rem;overflow-x:hidden;}
.step-card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-card);padding:2.5rem 2.75rem;border:1px solid var(--border);max-width:720px;width:100%;animation:slideIn .3s ease;}
@keyframes slideIn{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:translateX(0)}}
.step-card.going-back{animation:slideBack .3s ease;}
@keyframes slideBack{from{opacity:0;transform:translateX(-16px)}to{opacity:1;transform:translateX(0)}}
.step-heading{margin-bottom:2rem;}
.step-heading .step-number{font-size:.78rem;font-weight:600;color:var(--amber-dark);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;}
.step-heading h2{font-family:'DM Serif Display',serif;font-size:1.85rem;color:var(--navy);line-height:1.2;margin-bottom:.4rem;}
.step-heading p{font-size:.93rem;color:var(--text-muted);}
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
.form-grid .span-2{grid-column:span 2;}
.field{display:flex;flex-direction:column;gap:.45rem;}
.field label{font-size:.83rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.4rem;}
.field label .req{color:var(--amber-dark);}
.field label i{color:var(--navy-light);font-size:.8rem;}
.field input,.field select,.field textarea{padding:.72rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.93rem;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition),box-shadow var(--transition),background var(--transition);}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--border-focus);background:#fff;box-shadow:0 0 0 3.5px rgba(26,86,176,.1);}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 1rem center;padding-right:2.25rem;cursor:pointer;}
.field textarea{resize:vertical;min-height:90px;line-height:1.6;}
.field .hint{font-size:.77rem;color:var(--text-light);line-height:1.4;}
.form-section-label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);margin:1.75rem 0 1rem;display:flex;align-items:center;gap:.6rem;}
.form-section-label::after{content:'';flex:1;height:1px;background:var(--border);}
.toggle-field{display:flex;align-items:flex-start;gap:.85rem;padding:1rem 1.25rem;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;}
.toggle-field:has(input:checked){border-color:var(--navy-light);background:#eff4ff;}
.toggle-field input[type="checkbox"]{width:18px;height:18px;accent-color:var(--navy-mid);cursor:pointer;flex-shrink:0;margin-top:.1rem;}
.toggle-field-label{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.2rem;}
.toggle-field-desc{font-size:.8rem;color:var(--text-muted);}
#ownAddressSection.hidden{opacity:.3;pointer-events:none;}
.file-upload-zone{border:2px dashed var(--border);border-radius:var(--radius-sm);background:var(--surface-2);padding:1.25rem;text-align:center;cursor:pointer;position:relative;}
.file-upload-zone:hover{border-color:var(--navy-light);}
.file-upload-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.file-upload-icon{font-size:1.5rem;color:var(--text-light);margin-bottom:.4rem;}
.file-upload-label{font-size:.85rem;font-weight:500;color:var(--text-muted);}
.file-upload-label strong{color:var(--navy-light);}
.file-upload-sub{font-size:.74rem;color:var(--text-light);margin-top:.2rem;}
.existing-file-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--navy-light);text-decoration:none;margin-top:.5rem;padding:.3rem .75rem;background:#eff4ff;border-radius:99px;border:1px solid #c7d9f8;}
.preview-wrap{margin-top:.75rem;}
.preview-img{width:100%;max-height:100px;object-fit:cover;border-radius:var(--radius-sm);}
.preview-logo-img{width:72px;height:72px;object-fit:cover;border-radius:50%;}
/* Type cards */
.type-cards-three{display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem;margin-bottom:.5rem;}
.type-card{position:relative;border:2px solid var(--border);border-radius:var(--radius-sm);padding:1.25rem;cursor:pointer;background:var(--surface-2);}
.type-card:hover{border-color:var(--navy-light);background:#eff4ff;}
.type-card input[type="radio"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;margin:0;}
.type-card.selected{border-color:var(--navy-mid);background:#eff4ff;box-shadow:0 0 0 3px rgba(15,59,122,.1);}
.type-card.fleet-card:hover{border-color:var(--fleet);background:var(--fleet-pale);}
.type-card.fleet-card.selected{border-color:var(--fleet);background:var(--fleet-pale);box-shadow:0 0 0 3px rgba(180,83,9,.1);}
.type-card-icon{width:40px;height:40px;border-radius:var(--radius-sm);background:var(--navy);display:flex;align-items:center;justify-content:center;color:var(--amber);font-size:1.1rem;margin-bottom:.75rem;}
.type-card.fleet-card .type-card-icon{background:var(--fleet);}
.type-card.selected .type-card-icon{background:var(--navy-mid);}
.type-card.fleet-card.selected .type-card-icon{background:var(--fleet);}
.type-card-title{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.25rem;}
.type-card-desc{font-size:.77rem;color:var(--text-muted);line-height:1.45;}
/* Fleet sub-fields */
.fleet-subfields{background:var(--fleet-pale);border:1.5px solid var(--fleet-mid);border-radius:var(--radius-sm);padding:1.1rem 1.25rem;margin-top:.75rem;display:none;}
.fleet-subfields.visible{display:block;}
.fleet-subfields-label{font-size:.78rem;font-weight:700;color:var(--fleet);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.85rem;display:flex;align-items:center;gap:.5rem;}
/* Input prefix/suffix */
.input-prefix-wrap{position:relative;}
.input-prefix-wrap .prefix{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.85rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.input-prefix-wrap input{padding-left:2.1rem;}
.input-suffix-wrap{position:relative;}
.input-suffix-wrap .suffix{position:absolute;right:.85rem;top:50%;transform:translateY(-50%);font-size:.82rem;color:var(--text-muted);pointer-events:none;}
.input-suffix-wrap input{padding-right:3.5rem;}
/* Dynamic rows (use of funds + highlights) */
.dynamic-rows{display:flex;flex-direction:column;gap:.75rem;}
.dynamic-row{display:grid;gap:.75rem;align-items:center;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:.9rem 1rem;animation:rowIn .2s ease;}
@keyframes rowIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.dynamic-row:focus-within{border-color:var(--border-focus);}
.dynamic-row.hl-row{grid-template-columns:1fr 1fr auto;}
.dynamic-row.uof-row{grid-template-columns:1fr 160px auto;}
.dynamic-row input{padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.88rem;color:var(--text);background:#fff;outline:none;width:100%;}
.dynamic-row input:focus{border-color:var(--border-focus);}
.amount-wrap{position:relative;}
.amount-wrap .currency-prefix{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.amount-wrap input{padding-left:2rem;}
.btn-remove-row{width:30px;height:30px;border-radius:50%;border:1.5px solid var(--error-bdr);background:var(--error-bg);color:var(--error);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;font-size:.8rem;}
.btn-remove-row:hover{background:var(--error);color:#fff;}
.btn-add-row{display:inline-flex;align-items:center;gap:.5rem;margin-top:.75rem;padding:.55rem 1.1rem;border:1.5px dashed var(--navy-light);border-radius:99px;background:transparent;color:var(--navy-light);font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;cursor:pointer;}
.btn-add-row:hover{background:#eff4ff;border-style:solid;}
.row-col-label{font-size:.74rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;}
.uof-total{display:flex;justify-content:flex-end;align-items:center;gap:.5rem;margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--border);font-size:.85rem;color:var(--text-muted);}
.uof-total strong{color:var(--navy);font-size:.95rem;}
/* Callouts */
.callout{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.85rem 1.1rem;font-size:.85rem;color:#78350f;display:flex;gap:.6rem;align-items:flex-start;margin-bottom:1.5rem;}
.callout i{margin-top:.1rem;flex-shrink:0;color:var(--amber-dark);}
.callout.info{background:#eff4ff;border-color:#c7d9f8;color:var(--navy-mid);}
.callout.info i{color:var(--navy-light);}
.callout.fleet{background:var(--fleet-pale);border-color:var(--fleet-mid);color:var(--fleet);}
.callout.fleet i{color:var(--fleet);}
.callout.success{background:var(--green-bg);border-color:var(--green-bdr);color:var(--green);}
.callout.success i{color:var(--green);}
/* Review blocks */
.review-block{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:1.25rem;}
.review-block-header{background:var(--navy);padding:.65rem 1.1rem;font-size:.78rem;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:.5rem;}
.review-block-header i{color:var(--amber);}
.review-block-header.fleet-header{background:var(--fleet);}
.review-block-header.fleet-header i{color:#fff;}
.review-row{display:flex;align-items:baseline;justify-content:space-between;padding:.7rem 1.1rem;border-bottom:1px solid var(--border);gap:1rem;}
.review-row:last-child{border-bottom:none;}
.review-row-label{font-size:.82rem;color:var(--text-muted);flex-shrink:0;}
.review-row-value{font-size:.88rem;font-weight:600;color:var(--text);text-align:right;}
.review-row-value.highlight{color:var(--navy-mid);}
/* Alerts */
.alert{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.5rem;font-size:.88rem;font-weight:500;border:1px solid transparent;}
.alert i{font-size:1rem;margin-top:.05rem;flex-shrink:0;}
.alert.error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.alert ul{margin:.3rem 0 0 1rem;}
.alert ul li{margin-bottom:.15rem;}
/* Waterfall preview (Step 5 fleet) */
.fleet-preview{background:var(--navy);border-radius:var(--radius-sm);padding:1rem 1.25rem;margin-top:1.25rem;}
.fleet-preview-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:rgba(255,255,255,.45);margin-bottom:.6rem;}
.fleet-preview-row{display:flex;justify-content:space-between;align-items:baseline;padding:.35rem 0;border-bottom:1px solid rgba(255,255,255,.08);font-size:.85rem;}
.fleet-preview-row:last-child{border-bottom:none;}
.fleet-preview-key{color:rgba(255,255,255,.55);}
.fleet-preview-val{font-weight:600;color:#fff;}
.fleet-preview-val.highlight{color:var(--amber);}
/* ── US-403 (Team C Phase 2) — Asset register table ─────────────────────── */
.asset-register-table-wrap{overflow-x:auto;margin-top:.75rem;}
.asset-table{width:100%;border-collapse:collapse;font-size:.83rem;min-width:760px;}
.asset-table th{text-align:left;padding:.5rem .65rem;font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:var(--text-light);border-bottom:2px solid var(--border);background:var(--surface-2);white-space:nowrap;}
.asset-table td{padding:.45rem .6rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.asset-table tr:last-child td{border-bottom:none;}
.asset-table input,.asset-table select{padding:.4rem .65rem;border:1.5px solid var(--border);border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.83rem;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition);}
.asset-table input:focus,.asset-table select:focus{border-color:var(--border-focus);background:#fff;}
.asset-table .btn-remove-row{width:26px;height:26px;font-size:.75rem;}
.asset-row-num{font-family:monospace;font-size:.7rem;color:var(--text-light);text-align:center;padding-right:.25rem;}
.asset-count-note{font-size:.77rem;color:var(--text-muted);margin-top:.5rem;display:flex;align-items:center;gap:.35rem;}
/* ── US-404 (Team C Phase 2) — Projection model table ───────────────────── */
.proj-table-wrap{overflow-x:auto;margin-top:.75rem;}
.proj-table{width:100%;border-collapse:collapse;font-size:.82rem;min-width:900px;}
.proj-table th{text-align:right;padding:.5rem .55rem;font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:var(--text-light);border-bottom:2px solid var(--border);background:var(--surface-2);white-space:nowrap;}
.proj-table th:first-child,.proj-table th:nth-child(2){text-align:left;}
.proj-table td{padding:.4rem .5rem;border-bottom:1px solid var(--border);vertical-align:middle;}
.proj-table tr:last-child td{border-bottom:none;}
.proj-table tr:hover td{background:var(--surface-2);}
.proj-table input[type="number"],.proj-table input[type="text"]{padding:.38rem .55rem;border:1.5px solid var(--border);border-radius:5px;font-family:'DM Sans',sans-serif;font-size:.81rem;text-align:right;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition);}
.proj-table input[type="text"]{text-align:left;}
.proj-table input:focus{border-color:var(--border-focus);background:#fff;}
.proj-table input.computed{background:var(--surface);color:var(--navy-mid);font-weight:600;cursor:default;}
.proj-table .chk-cell{text-align:center;}
.proj-table input[type="checkbox"]{width:16px;height:16px;accent-color:var(--green);cursor:pointer;}
.proj-table .pn-cell{text-align:center;font-family:monospace;font-size:.72rem;color:var(--text-light);}
.proj-table .btn-remove-row{width:24px;height:24px;font-size:.7rem;margin:0 auto;}
.proj-totals-row td{background:var(--navy);color:#fff;font-weight:600;font-size:.82rem;border-top:2px solid var(--border-focus);}
.proj-totals-row td.money{text-align:right;font-family:monospace;color:var(--amber);}
.proj-generate-bar{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1rem 1.25rem;margin-bottom:1rem;display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap;}
.proj-generate-bar .field{flex:1;min-width:140px;margin:0;}
.proj-generate-bar label{font-size:.78rem;font-weight:600;color:var(--text-muted);display:block;margin-bottom:.35rem;}
.proj-generate-bar input{padding:.55rem .75rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.88rem;width:100%;outline:none;background:var(--surface);}
.proj-generate-bar input:focus{border-color:var(--border-focus);}
/* Buttons */
.btn{display:inline-flex;align-items:center;gap:.5rem;padding:.72rem 1.6rem;border-radius:99px;font-family:'DM Sans',sans-serif;font-size:.9rem;font-weight:600;cursor:pointer;transition:all var(--transition);border:none;text-decoration:none;outline:none;}
.btn-ghost{background:transparent;color:var(--text-muted);border:1.5px solid var(--border);}
.btn-ghost:hover{border-color:#94a3b8;color:var(--text);background:#f1f5f9;}
.btn-save{background:var(--surface-2);color:var(--navy);border:1.5px solid var(--border);}
.btn-save:hover{background:#eff4ff;border-color:var(--navy-light);color:var(--navy-mid);}
.btn-primary{background:var(--navy-mid);color:#fff;box-shadow:var(--shadow-btn);}
.btn-primary:hover{background:var(--navy);transform:translateY(-1px);}
.btn-amber{background:var(--amber);color:var(--navy);box-shadow:0 4px 12px rgba(245,158,11,.3);}
.btn-amber:hover{background:var(--amber-dark);color:#fff;transform:translateY(-1px);}
.btn-sm{padding:.45rem 1rem;font-size:.82rem;}
.btn-back{background:transparent;color:var(--text-muted);padding-left:.5rem;border:none;}
.btn-back:hover{color:var(--text);}
.step-actions{display:flex;align-items:center;justify-content:space-between;margin-top:2.25rem;padding-top:1.5rem;border-top:1px solid var(--border);gap:1rem;flex-wrap:wrap;}
.step-actions-left{display:flex;gap:.75rem;align-items:center;}
.step-actions-right{display:flex;gap:.75rem;align-items:center;}
@media(max-width:900px){
    .wizard-sidebar{display:none;}.mobile-progress{display:block;}.wizard-main{padding:1.5rem;}
    .step-card{padding:1.75rem 1.5rem;}.form-grid{grid-template-columns:1fr;}.form-grid .span-2{grid-column:span 1;}
    .type-cards-three{grid-template-columns:1fr;}.dynamic-row.hl-row{grid-template-columns:1fr auto;}.dynamic-row.uof-row{grid-template-columns:1fr auto;}
}
@media(max-width:540px){.wizard-main{padding:1rem;}.step-card{padding:1.25rem 1rem;}.step-actions{flex-direction:column-reverse;align-items:stretch;}.step-actions-left,.step-actions-right{justify-content:center;}.btn{justify-content:center;}}
</style>
</head>
<body>
<div class="wizard-shell">

<aside class="wizard-sidebar">
    <div class="sidebar-brand">
        <div class="sidebar-brand-logo">Old Union</div>
        <div class="sidebar-brand-sub">SPV Campaign Setup</div>
    </div>
    <ul class="step-list">
        <?php foreach ($steps as $n => $s):
            $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
        ?>
        <li class="step-item <?= $cls ?>">
            <div class="step-icon">
                <?= $n < $step ? '<i class="fa-solid fa-check"></i>' : '<i class="fa-solid ' . $s['icon'] . '"></i>' ?>
            </div>
            <div class="step-text">
                <div class="step-label"><?= $s['label'] ?></div>
                <div class="step-desc"><?= $s['desc'] ?></div>
            </div>
        </li>
        <?php endforeach; ?>
    </ul>
    <div class="sidebar-footer">
        <div class="sidebar-footer-label">Campaign</div>
        <div class="sidebar-footer-val"><?= htmlspecialchars($data['title'] ?: 'Untitled Campaign') ?></div>
        <div class="sidebar-footer-meta">
            <?php if ($isFleet): ?>
                <i class="fa-solid fa-truck" style="color:var(--amber);font-size:.7rem;margin-right:.3rem;"></i>
                Fleet Asset SPV · <?= $totalSteps ?> steps
            <?php else: ?>
                <i class="fa-solid fa-building" style="font-size:.7rem;margin-right:.3rem;"></i>
                <?= htmlspecialchars($company['name']) ?>
            <?php endif; ?>
        </div>
    </div>
</aside>

<div class="mobile-progress">
    <div class="mob-prog-head">
        <span class="mob-prog-label">Step <?= $step ?>: <?= $steps[$step]['label'] ?></span>
        <span class="mob-prog-count"><?= $step ?> / <?= $totalSteps ?></span>
    </div>
    <div class="mob-prog-bar">
        <div class="mob-prog-fill" style="width:<?= round($step / $totalSteps * 100) ?>%"></div>
    </div>
</div>

<main class="wizard-main">
<div class="step-card" id="stepCard">

<?php if (!empty($errors)): ?>
<div class="alert error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul></div>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="wizardForm">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="hidden" name="step"       value="<?= $step ?>">
<input type="hidden" name="action"     value="next" id="actionInput">

<?php /* ════ STEP 1 ════════════════════════════════════════════════════ */ ?>
<?php if ($step === 1): ?>
<div class="step-heading">
    <div class="step-number">Step 1 of <?= $totalSteps ?></div>
    <h2>Campaign Basics</h2>
    <p>Set the headline, choose your fundraising instrument, and define the window investors can commit.</p>
</div>
<div class="form-grid">
    <div class="field span-2">
        <label><i class="fa-solid fa-pen"></i> Campaign Title <span class="req">*</span></label>
        <input type="text" name="title" maxlength="255" value="<?= htmlspecialchars($data['title']) ?>" placeholder="e.g. Soweto Bread Co — Community Expansion SPV 2025" required>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-quote-left"></i> Tagline</label>
        <input type="text" name="tagline" maxlength="500" value="<?= htmlspecialchars($data['tagline']) ?>" placeholder="One sentence that tells investors exactly what they are backing">
    </div>
</div>
<div class="form-section-label"><i class="fa-solid fa-file-contract" style="color:var(--navy-light)"></i> Fundraising Instrument</div>
<div class="type-cards-three">
    <label class="type-card <?= $data['campaign_type'] === 'revenue_share' ? 'selected' : '' ?>">
        <input type="radio" name="campaign_type" value="revenue_share" <?= $data['campaign_type'] === 'revenue_share' ? 'checked' : '' ?> onchange="onTypeChange(this.value)">
        <div class="type-card-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div class="type-card-title">Revenue Share</div>
        <div class="type-card-desc">Investors receive a percentage of monthly revenue for a fixed term.</div>
    </label>
    <label class="type-card <?= $data['campaign_type'] === 'cooperative_membership' ? 'selected' : '' ?>">
        <input type="radio" name="campaign_type" value="cooperative_membership" <?= $data['campaign_type'] === 'cooperative_membership' ? 'checked' : '' ?> onchange="onTypeChange(this.value)">
        <div class="type-card-icon"><i class="fa-solid fa-people-roof"></i></div>
        <div class="type-card-title">Cooperative Membership</div>
        <div class="type-card-desc">Investors purchase membership units. Best for community-owned structures.</div>
    </label>
    <label class="type-card fleet-card <?= $data['campaign_type'] === 'fleet_asset' ? 'selected' : '' ?>">
        <input type="radio" name="campaign_type" value="fleet_asset" <?= $data['campaign_type'] === 'fleet_asset' ? 'checked' : '' ?> onchange="onTypeChange(this.value)">
        <div class="type-card-icon"><i class="fa-solid fa-truck"></i></div>
        <div class="type-card-title">Fleet Asset SPV</div>
        <div class="type-card-desc">Investors earn from net operating income of a fleet — scooters, vehicles, or equipment. Waterfall distribution.</div>
    </label>
</div>
<div class="fleet-subfields <?= $data['campaign_type'] === 'fleet_asset' ? 'visible' : '' ?>" id="fleetSubfields">
    <div class="fleet-subfields-label"><i class="fa-solid fa-truck"></i> Fleet Configuration</div>
    <div class="form-grid">
        <div class="field">
            <label for="fleet_asset_type"><i class="fa-solid fa-tag"></i> Asset Type <span class="req">*</span></label>
            <select id="fleet_asset_type" name="fleet_asset_type">
                <option value="">— Select asset type —</option>
                <?php foreach ($fleetAssetSubTypes as $fat): ?>
                <option value="<?= htmlspecialchars($fat) ?>" <?= ($data['fleet_asset_type'] === $fat) ? 'selected' : '' ?>><?= htmlspecialchars($fat) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="fleet_asset_count"><i class="fa-solid fa-layer-group"></i> Number of Assets <span class="req">*</span></label>
            <div class="input-suffix-wrap">
                <input type="number" id="fleet_asset_count" name="fleet_asset_count" min="1" max="200" step="1"
                       value="<?= htmlspecialchars((string)($data['fleet_asset_count'] ?? '')) ?>" placeholder="e.g. 15">
                <span class="suffix">max 200</span>
            </div>
        </div>
    </div>
</div>
<div class="form-section-label"><i class="fa-solid fa-calendar-days" style="color:var(--navy-light)"></i> Fundraising Window</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-calendar-check"></i> Opens On <span class="req">*</span></label>
        <input type="date" name="opens_at" value="<?= htmlspecialchars($data['opens_at']) ?>" min="<?= date('Y-m-d') ?>" required>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-calendar-xmark"></i> Closes On <span class="req">*</span></label>
        <input type="date" name="closes_at" value="<?= htmlspecialchars($data['closes_at']) ?>" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required>
    </div>
</div>

<?php /* ════ STEP 2 ════════════════════════════════════════════════════ */ ?>
<?php elseif ($step === 2): ?>
<div class="step-heading"><div class="step-number">Step 2 of <?= $totalSteps ?></div><h2>SPV Identity</h2><p>Registered details, contact information, and branding for the SPV entity.</p></div>
<div class="callout info"><i class="fa-solid fa-circle-info"></i><div>The SPV's <strong>registered name</strong> is its official CIPC name. This may differ from the campaign title shown to investors.</div></div>
<div class="form-grid">
    <div class="field span-2"><label><i class="fa-solid fa-building"></i> SPV Registered Name <span class="req">*</span></label><input type="text" name="spv_registered_name" maxlength="255" value="<?= htmlspecialchars($data['spv_registered_name']) ?>" placeholder="e.g. Soweto Bread Co SPV 001 (RF) (Pty) Ltd" required></div>
    <div class="field"><label><i class="fa-solid fa-hashtag"></i> CIPC Registration Number</label><input type="text" name="spv_registration_number" maxlength="100" value="<?= htmlspecialchars($data['spv_registration_number']) ?>" placeholder="e.g. 2025/123456/07"></div>
    <div class="field"><label><i class="fa-solid fa-envelope"></i> SPV Email</label><input type="email" name="spv_email" value="<?= htmlspecialchars($data['spv_email']) ?>" placeholder="spv@yourcompany.co.za"></div>
    <div class="field"><label><i class="fa-solid fa-phone"></i> SPV Phone</label><input type="tel" name="spv_phone" value="<?= htmlspecialchars($data['spv_phone']) ?>" placeholder="+27 10 000 0000"></div>
    <div class="field"><label><i class="fa-solid fa-globe"></i> SPV Website</label><input type="url" name="spv_website" value="<?= htmlspecialchars($data['spv_website']) ?>" placeholder="https://spv.yourcompany.co.za"><span class="hint">Include https://</span></div>
    <div class="field span-2"><label><i class="fa-solid fa-align-left"></i> About this SPV</label><textarea name="spv_description" rows="4" placeholder="Describe the purpose and scope of this specific SPV entity."><?= htmlspecialchars($data['spv_description']) ?></textarea></div>
</div>
<div class="form-section-label"><i class="fa-solid fa-palette" style="color:var(--navy-light)"></i> SPV Branding</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-circle-user"></i> SPV Logo</label>
        <div class="file-upload-zone"><input type="file" name="spv_logo" accept="image/*" onchange="previewImage(this,'logoPreview','logoImg')"><div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div><div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div><div class="file-upload-sub">PNG, JPG, WebP · max 5MB</div></div>
        <div class="preview-wrap" id="logoPreview" style="display:none;text-align:center;"><img id="logoImg" class="preview-logo-img" src="" alt="Logo preview"></div>
        <?php if ($data['spv_logo']): ?><a href="<?= htmlspecialchars($data['spv_logo']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-image"></i> Current logo</a><?php endif; ?>
    </div>
    <div class="field"><label><i class="fa-solid fa-panorama"></i> SPV Banner</label>
        <div class="file-upload-zone"><input type="file" name="spv_banner" accept="image/*" onchange="previewImage(this,'bannerPreview','bannerImg')"><div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div><div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div><div class="file-upload-sub">PNG, JPG, WebP · max 5MB · landscape</div></div>
        <div class="preview-wrap" id="bannerPreview" style="display:none;"><img id="bannerImg" class="preview-img" src="" alt="Banner preview"></div>
        <?php if ($data['spv_banner']): ?><a href="<?= htmlspecialchars($data['spv_banner']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-panorama"></i> Current banner</a><?php endif; ?>
    </div>
</div>

<?php /* ════ STEP 3 ════════════════════════════════════════════════════ */ ?>
<?php elseif ($step === 3): ?>
<div class="step-heading"><div class="step-number">Step 3 of <?= $totalSteps ?></div><h2>SPV Registered Address</h2><p>The SPV's registered address for legal and compliance purposes.</p></div>
<label class="toggle-field">
    <input type="checkbox" name="spv_address_same_as_company" id="sameAsParentToggle" <?= $data['spv_address_same_as_company'] ? 'checked' : '' ?> onchange="toggleAddressSection(this.checked)">
    <div class="toggle-field-body">
        <div class="toggle-field-label">Same as parent company address</div>
        <div class="toggle-field-desc"><?php $p = array_filter([$parentAddress['suburb']??'', $parentAddress['city']??'', $parentAddress['province']??'']); echo $p ? htmlspecialchars(implode(', ', $p)) : 'No parent address on file.'; ?></div>
    </div>
</label>
<div id="ownAddressSection" class="<?= $data['spv_address_same_as_company'] ? 'hidden' : '' ?>" style="margin-top:1.5rem;">
    <div class="form-grid">
        <div class="field"><label><i class="fa-solid fa-map"></i> Province <span class="req">*</span></label>
            <select name="spv_province" id="spvProvince"><option value="">— Select province —</option>
            <?php foreach ($validProvinces as $prov): ?><option value="<?= htmlspecialchars($prov) ?>" <?= $data['spv_province'] === $prov ? 'selected' : '' ?>><?= htmlspecialchars($prov) ?></option><?php endforeach; ?></select>
        </div>
        <div class="field"><label><i class="fa-solid fa-layer-group"></i> Area Type <span class="req">*</span></label>
            <select name="spv_area" id="spvArea"><option value="">— Select —</option><option value="urban" <?= $data['spv_area']==='urban'?'selected':'' ?>>Urban</option><option value="township" <?= $data['spv_area']==='township'?'selected':'' ?>>Township</option><option value="rural" <?= $data['spv_area']==='rural'?'selected':'' ?>>Rural</option></select>
        </div>
        <div class="field"><label><i class="fa-solid fa-city"></i> Municipality</label><select name="spv_municipality" id="spvMunicipality" disabled><option value="">— Select province first —</option></select></div>
        <div class="field"><label><i class="fa-solid fa-building"></i> City / Town</label><select name="spv_city" id="spvCity" disabled><option value="">— Select municipality first —</option></select></div>
        <div class="field span-2"><label><i class="fa-solid fa-location-dot"></i> Suburb</label><select name="spv_suburb" id="spvSuburb" disabled><option value="">— Select city first —</option></select></div>
    </div>
</div>

<?php /* ════ STEP 4 ════════════════════════════════════════════════════ */ ?>
<?php elseif ($step === 4): ?>
<div class="step-heading"><div class="step-number">Step 4 of <?= $totalSteps ?></div><h2>Funding Targets</h2><p>Define the raise amounts and contributor guardrails for this SPV.</p></div>
<div class="callout"><i class="fa-solid fa-scale-balanced"></i><div><strong>Legal note:</strong> The contributor cap is maximum 50 to comply with SA private placement regulations.</div></div>
<div class="form-section-label"><i class="fa-solid fa-sack-dollar" style="color:var(--navy-light)"></i> Raise Amounts</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-bullseye"></i> Raise Target <span class="req">*</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="raise_target" min="1" step="100" value="<?= htmlspecialchars($data['raise_target']) ?>" placeholder="500000" required></div></div>
    <div class="field"><label><i class="fa-solid fa-circle-minus"></i> Minimum Raise <span class="req">*</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="raise_minimum" min="1" step="100" value="<?= htmlspecialchars($data['raise_minimum']) ?>" placeholder="250000" required></div><span class="hint">Contributions refunded if this floor isn't reached.</span></div>
    <div class="field span-2"><label><i class="fa-solid fa-circle-plus"></i> Hard Cap <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="raise_maximum" min="1" step="100" value="<?= htmlspecialchars($data['raise_maximum']) ?>" placeholder="Leave blank for no hard cap"></div></div>
</div>
<div class="form-section-label"><i class="fa-solid fa-users" style="color:var(--navy-light)"></i> Contributor Limits</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-arrow-down-1-9"></i> Minimum Contribution <span class="req">*</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="min_contribution" min="100" step="100" value="<?= htmlspecialchars($data['min_contribution']) ?>" placeholder="500" required></div></div>
    <div class="field"><label><i class="fa-solid fa-arrow-up-9-1"></i> Maximum Contribution <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="max_contribution" min="100" step="100" value="<?= htmlspecialchars($data['max_contribution']) ?>" placeholder="No limit"></div></div>
    <div class="field span-2"><label><i class="fa-solid fa-user-group"></i> Contributor Cap <span class="req">*</span></label><div class="input-suffix-wrap"><input type="number" name="max_contributors" min="1" max="50" value="<?= htmlspecialchars($data['max_contributors']) ?>" required><span class="suffix">max 50</span></div></div>
</div>

<?php /* ════ STEP 5 — Deal Terms ════════════════════════════════════════ */ ?>
<?php elseif ($step === 5): ?>
<div class="step-heading">
    <div class="step-number">Step 5 of <?= $totalSteps ?></div>
    <h2>Deal Terms</h2>
    <p><?php
        if ($data['campaign_type'] === 'fleet_asset') echo 'Define the waterfall economics — hurdle, investor share, management fee, and term.';
        elseif ($data['campaign_type'] === 'cooperative_membership') echo 'Define the cooperative membership unit structure.';
        else echo 'Define the revenue share percentage and how many months investors receive it.';
    ?></p>
</div>

<?php if ($data['campaign_type'] === 'revenue_share'): ?>
<div class="callout info"><i class="fa-solid fa-circle-info"></i><div>Each investor receives their <strong>pro-rata share</strong> of the agreed percentage, based on their contribution relative to the total raised.</div></div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-percent"></i> Revenue Share <span class="req">*</span></label><div class="input-suffix-wrap"><input type="number" name="rs_percentage" min="0.01" max="100" step="0.01" value="<?= htmlspecialchars($data['rs_percentage']) ?>" placeholder="5" required id="rsPct"><span class="suffix">% / mo</span></div></div>
    <div class="field"><label><i class="fa-solid fa-hourglass-half"></i> Duration <span class="req">*</span></label><div class="input-suffix-wrap"><input type="number" name="rs_duration" min="1" max="120" step="1" value="<?= htmlspecialchars($data['rs_duration']) ?>" placeholder="36" required id="rsDur"><span class="suffix">months</span></div></div>
</div>
<div class="review-block" style="margin-top:1.5rem;"><div class="review-block-header"><i class="fa-solid fa-calculator"></i> Illustrative Summary</div>
<div class="review-row"><span class="review-row-label">Target</span><span class="review-row-value"><?= fmtCurrency($data['raise_target']?:0) ?></span></div>
<div class="review-row"><span class="review-row-label">Share for <span id="previewDur">—</span> months</span><span class="review-row-value highlight" id="previewTerms">Fill in fields above</span></div></div>

<?php elseif ($data['campaign_type'] === 'cooperative_membership'): ?>
<div class="form-grid">
    <div class="field span-2"><label><i class="fa-solid fa-tag"></i> Unit Name <span class="req">*</span></label><input type="text" name="co_unit_name" maxlength="100" value="<?= htmlspecialchars($data['co_unit_name']) ?>" placeholder="e.g. Community Membership Unit" required></div>
    <div class="field"><label><i class="fa-solid fa-coins"></i> Price Per Unit <span class="req">*</span></label><div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="co_unit_price" min="1" step="1" value="<?= htmlspecialchars($data['co_unit_price']) ?>" placeholder="1000" required id="coPrice"></div></div>
    <div class="field"><label><i class="fa-solid fa-layer-group"></i> Total Units <span class="req">*</span></label><input type="number" name="co_units_total" min="1" max="50" step="1" value="<?= htmlspecialchars($data['co_units_total']) ?>" placeholder="50" required id="coTotal"></div>
</div>
<div class="review-block" style="margin-top:1.5rem;"><div class="review-block-header"><i class="fa-solid fa-calculator"></i> Implied Totals</div>
<div class="review-row"><span class="review-row-label">Price / unit</span><span class="review-row-value" id="coPriceDisplay">—</span></div>
<div class="review-row"><span class="review-row-label">Units</span><span class="review-row-value" id="coUnitsDisplay">—</span></div>
<div class="review-row"><span class="review-row-label">Implied raise</span><span class="review-row-value highlight" id="coTotalDisplay">—</span></div></div>

<?php elseif ($data['campaign_type'] === 'fleet_asset'): ?>
<div class="callout fleet"><i class="fa-solid fa-truck"></i><div>Fleet Asset SPV: <strong><?= htmlspecialchars($data['fleet_asset_count']?:'?') ?> × <?= htmlspecialchars($data['fleet_asset_type']?:'Assets') ?></strong>. These waterfall parameters are now written directly to the database (US-101 migration live).</div></div>
<div class="form-section-label"><i class="fa-solid fa-water" style="color:var(--navy-light)"></i> Waterfall Economics</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-shield-halved"></i> Hurdle Rate <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label>
        <div class="input-suffix-wrap"><input type="number" name="fleet_hurdle_rate" id="fleetHurdleRate" min="0" max="100" step="0.1" value="<?= htmlspecialchars($data['fleet_hurdle_rate']) ?>" placeholder="e.g. 8"><span class="suffix">% p.a.</span></div>
        <span class="hint">Preferred return p.a. before performance split. Leave blank for no hurdle.</span></div>
    <div class="field"><label><i class="fa-solid fa-percent"></i> Investor Waterfall % <span class="req">*</span></label>
        <div class="input-suffix-wrap"><input type="number" name="fleet_investor_waterfall_pct" id="fleetWaterfallPct" min="0.01" max="100" step="0.1" value="<?= htmlspecialchars($data['fleet_investor_waterfall_pct']) ?>" placeholder="e.g. 85" required><span class="suffix">%</span></div>
        <span class="hint">% of distributable net income paid to investors collectively.</span></div>
    <div class="field"><label><i class="fa-solid fa-building-columns"></i> Management Fee % <span class="req">*</span></label>
        <div class="input-suffix-wrap"><input type="number" name="fleet_management_fee_pct" id="fleetMgmtFeePct" min="0" max="50" step="0.1" value="<?= htmlspecialchars($data['fleet_management_fee_pct']) ?>" placeholder="e.g. 5" required><span class="suffix">%</span></div></div>
    <div class="field"><label><i class="fa-solid fa-sliders"></i> Fee Basis</label>
        <select name="fleet_management_fee_basis" id="fleetMgmtFeeBasis">
            <option value="gross"           <?= ($data['fleet_management_fee_basis']??'gross')==='gross'           ?'selected':'' ?>>Gross Revenue</option>
            <option value="net_after_hurdle"<?= ($data['fleet_management_fee_basis']??'')==='net_after_hurdle' ?'selected':'' ?>>Net After Hurdle</option>
        </select></div>
</div>
<div class="form-section-label"><i class="fa-solid fa-calendar-days" style="color:var(--navy-light)"></i> Distribution Schedule</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-rotate"></i> Distribution Frequency <span class="req">*</span></label>
        <select name="fleet_distribution_frequency" id="fleetDistFreq">
            <option value="monthly"   <?= ($data['fleet_distribution_frequency']??'monthly')==='monthly'   ?'selected':'' ?>>Monthly</option>
            <option value="quarterly" <?= ($data['fleet_distribution_frequency']??'')==='quarterly' ?'selected':'' ?>>Quarterly</option>
        </select></div>
    <div class="field"><label><i class="fa-solid fa-hourglass-half"></i> Investment Term <span class="req">*</span></label>
        <div class="input-suffix-wrap"><input type="number" name="fleet_term_months" id="fleetTermMonths" min="1" max="120" step="1" value="<?= htmlspecialchars($data['fleet_term_months']) ?>" placeholder="e.g. 36" required><span class="suffix">months</span></div></div>
</div>
<div class="form-section-label"><i class="fa-solid fa-truck" style="color:var(--navy-light)"></i> Asset Acquisition Cost</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-coins"></i> Cost Per Unit <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span><input type="number" name="fleet_acq_cost_per_unit" id="fleetAcqCostPerUnit" min="0" step="100" value="<?= htmlspecialchars($data['fleet_acq_cost_per_unit']) ?>" placeholder="e.g. 35000"></div></div>
    <div class="field"><label><i class="fa-solid fa-calculator"></i> Total Acquisition Cost</label>
        <div class="input-prefix-wrap"><span class="prefix">R</span><input type="text" id="fleetTotalAcqCost" readonly value="<?= htmlspecialchars($data['fleet_total_acq_cost'] ? number_format((float)$data['fleet_total_acq_cost'],0,'.', ' ') : '') ?>" placeholder="Auto-calculated" style="background:var(--surface-2);color:var(--text-muted);cursor:not-allowed;"></div>
        <span class="hint">Automatically: cost per unit × <?= (int)($data['fleet_asset_count']??0) ?> assets.</span></div>
</div>
<div class="fleet-preview" id="fleetWaterfallPreview">
    <div class="fleet-preview-label">Waterfall Preview — At Stabilised Net Income (R 100 000 example)</div>
    <div class="fleet-preview-row"><span class="fleet-preview-key">Hurdle clearance (<span id="prevHurdle">—</span>% p.a.)</span><span class="fleet-preview-val" id="prevHurdleAmt">—</span></div>
    <div class="fleet-preview-row"><span class="fleet-preview-key">Management fee (<span id="prevMgmtPct">—</span>% of <span id="prevMgmtBasis">gross</span>)</span><span class="fleet-preview-val" id="prevMgmtAmt">—</span></div>
    <div class="fleet-preview-row"><span class="fleet-preview-key">Investors receive (<span id="prevInvPct">—</span>% waterfall)</span><span class="fleet-preview-val highlight" id="prevInvAmt">—</span></div>
    <div class="fleet-preview-row"><span class="fleet-preview-key">Distribution frequency</span><span class="fleet-preview-val" id="prevFreq">—</span></div>
    <div class="fleet-preview-row"><span class="fleet-preview-key">Term</span><span class="fleet-preview-val" id="prevTerm">—</span></div>
</div>
<?php endif; ?>


<?php /* ════ STEP 6 — Pitch & Funds + Fleet Asset Register (US-403) ════════ */ ?>
<?php elseif ($step === 6): ?>
<div class="step-heading">
    <div class="step-number">Step 6 of <?= $totalSteps ?></div>
    <h2><?= $isFleet ? 'Pitch, Funds &amp; Asset Register' : 'Investment Pitch &amp; Use of Funds' ?></h2>
    <p>Tell investors why this SPV is compelling<?= $isFleet ? ', how capital is deployed, and register your fleet assets.' : ' and how the money will be deployed.' ?></p>
</div>
<div class="form-section-label"><i class="fa-solid fa-briefcase" style="color:var(--navy-light)"></i> Investment Case</div>
<div class="form-grid">
    <div class="field span-2"><label><i class="fa-solid fa-lightbulb"></i> Investment Thesis</label><textarea name="investment_thesis" rows="4" maxlength="3000" placeholder="Why should an investor back this specific SPV?"><?= htmlspecialchars($data['investment_thesis']) ?></textarea></div>
    <div class="field span-2"><label><i class="fa-solid fa-shield-halved"></i> Risk Factors</label><textarea name="risk_factors" rows="3" maxlength="2000" placeholder="Key risks and mitigants."><?= htmlspecialchars($data['risk_factors']) ?></textarea></div>
    <div class="field"><label><i class="fa-solid fa-door-open"></i> Exit / Return Strategy</label><textarea name="exit_strategy" rows="3" maxlength="2000" placeholder="How and when do investors realise returns?"><?= htmlspecialchars($data['exit_strategy']) ?></textarea></div>
    <div class="field"><label><i class="fa-solid fa-rocket"></i> SPV Traction</label><textarea name="spv_traction" rows="3" maxlength="2000" placeholder="What has this SPV or business already achieved?"><?= htmlspecialchars($data['spv_traction']) ?></textarea></div>
    <div class="field span-2"><label><i class="fa-solid fa-people-group"></i> SPV Team</label><textarea name="spv_team_overview" rows="3" maxlength="2000" placeholder="Who is directing and managing this SPV?"><?= htmlspecialchars($data['spv_team_overview']) ?></textarea></div>
</div>
<div class="form-section-label"><i class="fa-solid fa-sack-dollar" style="color:var(--navy-light)"></i> Use of Funds</div>
<div class="dynamic-rows" id="uofRows">
    <?php if (empty($data['use_of_funds'])): ?>
    <div class="dynamic-row uof-row"><div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" placeholder="e.g. Equipment purchase"></div><div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" placeholder="0" oninput="updateUofTotal()"></div></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button></div>
    <?php else: foreach ($data['use_of_funds'] as $uofItem): ?>
    <div class="dynamic-row uof-row"><div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" value="<?= htmlspecialchars($uofItem['label']??'') ?>" placeholder="e.g. Equipment"></div><div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" value="<?= htmlspecialchars($uofItem['amount']??'') ?>" placeholder="0" oninput="updateUofTotal()"></div></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button></div>
    <?php endforeach; endif; ?>
</div>
<div class="uof-total">Total: <strong id="uofTotalDisplay">R 0</strong></div>
<button type="button" class="btn-add-row" onclick="addUofRow()"><i class="fa-solid fa-plus"></i> Add line item</button>

<?php /* ── US-403 (Team C Phase 2): Fleet Asset Register ─────────────────
          Only shown for fleet_asset campaigns.
          Saves to campaign_assets (US-102 table, Team A migration confirmed live).
       ── */ ?>
<?php if ($isFleet): ?>
<div class="form-section-label" style="margin-top:2rem;">
    <i class="fa-solid fa-truck" style="color:var(--fleet)"></i>
    Fleet Asset Register
    <span style="font-size:.75rem;font-weight:400;color:var(--text-light);text-transform:none;letter-spacing:0;">
        — Saved to <code style="font-family:monospace;font-size:.72rem;background:var(--surface-2);padding:1px 4px;border-radius:3px;">campaign_assets</code>
    </span>
</div>
<div class="callout fleet">
    <i class="fa-solid fa-circle-info"></i>
    <div>Register your fleet assets here. Maximum <strong><?= (int)($data['fleet_asset_count']??0) ?> <?= htmlspecialchars($data['fleet_asset_type']?:'assets') ?></strong> (from Step 1). GPS device IDs and insurance details can be added later in the campaign management Assets tab.</div>
</div>
<div class="asset-register-table-wrap">
    <table class="asset-table" id="assetTable">
        <thead>
            <tr>
                <th>#</th>
                <th style="min-width:140px;">Asset Label <span style="color:var(--error);">*</span></th>
                <th style="min-width:110px;">Make</th>
                <th style="min-width:110px;">Model</th>
                <th style="min-width:60px;">Year</th>
                <th style="min-width:120px;">Acq. Cost (R)</th>
                <th style="min-width:130px;">Platform</th>
                <th style="min-width:120px;">Serial No.</th>
                <th style="width:32px;"></th>
            </tr>
        </thead>
        <tbody id="assetTableBody">
        <?php
        $existingAssetRows = $data['fleet_assets'] ?? [];
        if (empty($existingAssetRows)) {
            // Seed first row for new campaigns
            $existingAssetRows = [['asset_label'=>'','make'=>'','model'=>'','year'=>'','acquisition_cost'=>'','deployment_platform'=>'other','serial_number'=>'']];
        }
        foreach ($existingAssetRows as $ai => $fa):
        ?>
        <tr class="asset-row">
            <td class="asset-row-num"><?= $ai + 1 ?></td>
            <td><input type="text" name="fa_label[]" value="<?= htmlspecialchars($fa['asset_label']??'') ?>" placeholder="e.g. Scooter #<?= str_pad($ai+1,2,'0',STR_PAD_LEFT) ?>" required></td>
            <td><input type="text" name="fa_make[]"  value="<?= htmlspecialchars($fa['make']??'') ?>"  placeholder="e.g. Ninebot"></td>
            <td><input type="text" name="fa_model[]" value="<?= htmlspecialchars($fa['model']??'') ?>" placeholder="e.g. ES4"></td>
            <td><input type="number" name="fa_year[]" value="<?= htmlspecialchars((string)($fa['year']??'')) ?>" min="2015" max="<?= date('Y')+1 ?>" placeholder="<?= date('Y') ?>"></td>
            <td><input type="number" name="fa_cost[]" value="<?= htmlspecialchars((string)($fa['acquisition_cost']??'')) ?>" min="0" step="100" placeholder="35000"></td>
            <td>
                <select name="fa_platform[]">
                    <?php foreach ($fleetPlatforms as $val => $lbl): ?>
                    <option value="<?= $val ?>" <?= ($fa['deployment_platform']??'other')===$val?'selected':'' ?>><?= $lbl ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="fa_serial[]" value="<?= htmlspecialchars($fa['serial_number']??'') ?>" placeholder="Optional"></td>
            <td><button type="button" class="btn-remove-row" onclick="removeAssetRow(this)" <?= count($existingAssetRows) <= 1 && $ai === 0 ? 'disabled' : '' ?>><i class="fa-solid fa-xmark"></i></button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<div class="asset-count-note" id="assetCountNote">
    <i class="fa-solid fa-truck" style="color:var(--fleet);"></i>
    <span id="assetRowCount"><?= count($existingAssetRows) ?></span> / <?= (int)($data['fleet_asset_count']??0) ?> assets registered
    &nbsp;·&nbsp;
    <button type="button" class="btn-add-row" style="margin:0;padding:.3rem .75rem;font-size:.78rem;" id="addAssetRowBtn" onclick="addAssetRow()" <?= count($existingAssetRows) >= (int)($data['fleet_asset_count']??200) ? 'disabled' : '' ?>>
        <i class="fa-solid fa-plus"></i> Add asset
    </button>
</div>
<?php endif; /* end fleet asset register */ ?>
<?php /* End US-403 */ ?>

<div class="form-section-label" style="margin-top:2rem;"><i class="fa-solid fa-photo-film" style="color:var(--navy-light)"></i> Pitch Assets</div>
<div class="form-grid">
    <div class="field"><label><i class="fa-solid fa-file-pdf"></i> Pitch Deck (upload)</label>
        <div class="file-upload-zone"><input type="file" name="pitch_deck" accept=".pdf" onchange="showFileName(this,'deckLabel')"><div class="file-upload-icon"><i class="fa-solid fa-file-pdf"></i></div><div class="file-upload-label" id="deckLabel"><strong>Click to upload</strong> PDF</div><div class="file-upload-sub">PDF only · max 5MB</div></div>
        <?php if ($data['pitch_deck_url']): ?><a href="<?= htmlspecialchars($data['pitch_deck_url']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-file-pdf"></i> Uploaded deck</a><?php endif; ?>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-link"></i> Pitch Deck URL</label>
        <input type="url" name="pitch_deck_url_text" value="<?= htmlspecialchars(!empty($data['pitch_deck_url']) && !str_starts_with($data['pitch_deck_url'],'/') ? $data['pitch_deck_url'] : '') ?>" placeholder="https://drive.google.com/…">
        <span class="hint">Google Drive / Dropbox PDF. Overrides upload if both provided.</span>
        <label style="margin-top:.75rem;"><i class="fa-brands fa-youtube"></i> Pitch Video URL</label>
        <input type="url" name="pitch_video_url" value="<?= htmlspecialchars($data['pitch_video_url']) ?>" placeholder="https://youtu.be/…">
    </div>
</div>

<?php /* ════ STEP 7 FLEET ONLY — Financial Model / Projections (US-404) ══ */ ?>
<?php elseif ($step === 7 && $isFleet): ?>
<div class="step-heading">
    <div class="step-number">Step 7 of <?= $totalSteps ?></div>
    <h2>Financial Model</h2>
    <p>Enter your monthly revenue projections. These power the Chart.js chart in the deal room and the investor distribution calculator. Saved via <code style="font-family:monospace;font-size:.85rem;">FleetService::bulkUpsertProjections()</code>.</p>
</div>
<div class="callout fleet">
    <i class="fa-solid fa-chart-line"></i>
    <div>
        Waterfall from Step 5: <strong><?= htmlspecialchars($data['fleet_investor_waterfall_pct']?:'—') ?>%</strong> investor share ·
        <strong><?= htmlspecialchars($data['fleet_hurdle_rate'] ? $data['fleet_hurdle_rate'].'% hurdle' : 'No hurdle') ?></strong> ·
        <strong><?= htmlspecialchars($data['fleet_management_fee_pct']?:'—') ?>%</strong> mgmt fee (<?= $data['fleet_management_fee_basis']==='net_after_hurdle'?'net after hurdle':'gross' ?>) ·
        <strong><?= (int)($data['fleet_term_months']??0) ?> months</strong>
    </div>
</div>

<?php
// Pre-compute waterfall constants for JS
$jsHurdleRate    = (float)($data['fleet_hurdle_rate']             ?: 0);
$jsWaterfallPct  = (float)($data['fleet_investor_waterfall_pct']  ?: 0) / 100;
$jsMgmtFeePct    = (float)($data['fleet_management_fee_pct']      ?: 0) / 100;
$jsMgmtFeeBasis  = ($data['fleet_management_fee_basis'] === 'net_after_hurdle') ? 'net_after_hurdle' : 'gross';
$jsRaiseTarget   = (float)($data['raise_target'] ?: 0);
?>

<!-- Auto-generate bar -->
<div class="proj-generate-bar">
    <div class="field">
        <label>Stabilised Monthly Net Income (R)</label>
        <input type="number" id="genStabilisedNet" min="0" step="100" placeholder="e.g. 87500">
    </div>
    <div class="field">
        <label>Ramp-up periods before stabilised</label>
        <input type="number" id="genRampPeriods" min="0" max="12" step="1" value="3" placeholder="e.g. 3">
    </div>
    <div class="field">
        <label>Total periods</label>
        <input type="number" id="genTotalPeriods" min="1" max="60" step="1" value="<?= (int)($data['fleet_term_months']??12) ?>" placeholder="<?= (int)($data['fleet_term_months']??12) ?>">
    </div>
    <button type="button" class="btn btn-save btn-sm" onclick="generateProjections()" style="align-self:flex-end;white-space:nowrap;">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Generate Template
    </button>
</div>

<div class="proj-table-wrap">
    <table class="proj-table" id="projTable">
        <thead>
            <tr>
                <th style="text-align:center;width:36px;">#</th>
                <th style="text-align:left;min-width:110px;">Label</th>
                <th style="min-width:110px;">Gross Rev (R)</th>
                <th style="min-width:90px;">Energy (R)</th>
                <th style="min-width:90px;">Maint. (R)</th>
                <th style="min-width:90px;">Insurance (R)</th>
                <th style="min-width:100px;">Mgmt Fee (R)</th>
                <th style="min-width:100px;">OpEx Total (R)</th>
                <th style="min-width:110px;">Net to SPV (R)</th>
                <th style="min-width:110px;">Inv. Distrib. (R)</th>
                <th style="text-align:center;min-width:60px;">Hurdle</th>
                <th style="width:28px;"></th>
            </tr>
        </thead>
        <tbody id="projTableBody">
        <?php
        $projRows = $data['fleet_projection_rows'] ?? [];
        if (empty($projRows)) {
            // Seed one blank row
            $projRows = [[
                'period_number'=>1,'label'=>'Month 1',
                'gross_revenue_projected'=>'','energy_cost'=>'','maintenance_reserve'=>'',
                'insurance_cost'=>'','management_fee'=>'','opex_total'=>'',
                'net_to_spv'=>'','investor_distribution'=>'','hurdle_cleared'=>0,'notes'=>'',
            ]];
        }
        foreach ($projRows as $pi => $pr):
        ?>
        <tr class="proj-row" data-period="<?= (int)$pr['period_number'] ?>">
            <td class="pn-cell"><input type="hidden" name="proj_period_number[]" value="<?= (int)$pr['period_number'] ?>"><?= (int)$pr['period_number'] ?></td>
            <td><input type="text"   name="proj_label[]"     value="<?= htmlspecialchars($pr['label']??('Month '.(int)$pr['period_number'])) ?>" placeholder="Month <?= (int)$pr['period_number'] ?>"></td>
            <td><input type="number" name="proj_gross[]"     value="<?= $pr['gross_revenue_projected']!=='' ? (float)$pr['gross_revenue_projected'] : '' ?>" min="0" step="100" placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_energy[]"    value="<?= $pr['energy_cost']!=='' ? (float)$pr['energy_cost'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_maint[]"     value="<?= $pr['maintenance_reserve']!=='' ? (float)$pr['maintenance_reserve'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_insur[]"     value="<?= $pr['insurance_cost']!=='' ? (float)$pr['insurance_cost'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_mgmt_fee[]"  value="<?= $pr['management_fee']!=='' ? (float)$pr['management_fee'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input computed" readonly title="Auto-computed from waterfall"></td>
            <td><input type="number" name="proj_opex[]"      value="<?= $pr['opex_total']!=='' ? (float)$pr['opex_total'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input computed" readonly title="Auto-computed"></td>
            <td><input type="number" name="proj_net[]"       value="<?= $pr['net_to_spv']!=='' ? (float)$pr['net_to_spv'] : '' ?>" min="0" step="10" placeholder="0" class="proj-input computed" readonly title="Auto-computed"></td>
            <td><input type="number" name="proj_inv_dist[]"  value="<?= $pr['investor_distribution']!=='' ? (float)$pr['investor_distribution'] : '' ?>" min="0" step="0.01" placeholder="0" class="proj-input computed" readonly title="Auto-computed from waterfall"></td>
            <td class="chk-cell"><input type="checkbox" name="proj_hurdle[]" value="1" <?= !empty($pr['hurdle_cleared']) ? 'checked' : '' ?> class="proj-hurdle" disabled title="Auto-set when net ≥ hurdle"></td>
            <td><button type="button" class="btn-remove-row" onclick="removeProjRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="proj-totals-row">
                <td colspan="2" style="color:rgba(255,255,255,.6);font-size:.75rem;text-transform:uppercase;letter-spacing:.07em;">Totals</td>
                <td class="money" id="totGross">—</td>
                <td class="money" id="totEnergy">—</td>
                <td class="money" id="totMaint">—</td>
                <td class="money" id="totInsur">—</td>
                <td class="money" id="totMgmt">—</td>
                <td class="money" id="totOpex">—</td>
                <td class="money" id="totNet">—</td>
                <td class="money" id="totInvDist">—</td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>
<div style="margin-top:.75rem;display:flex;align-items:center;gap:1rem;flex-wrap:wrap;">
    <button type="button" class="btn-add-row" style="margin:0;" onclick="addProjRow()"><i class="fa-solid fa-plus"></i> Add period</button>
    <span style="font-size:.77rem;color:var(--text-light);" id="projRowCount"><?= count($projRows) ?> period<?= count($projRows)!==1?'s':'' ?> · max 60</span>
</div>
<p style="font-size:.74rem;color:var(--text-light);margin-top:.85rem;line-height:1.55;">
    <i class="fa-solid fa-circle-info" style="margin-right:.35rem;"></i>
    Mgmt Fee, OpEx Total, Net to SPV, and Investor Distribution are auto-calculated from your waterfall parameters.
    Saved via <code style="font-family:monospace;font-size:.7rem;background:var(--surface-2);padding:1px 4px;border-radius:3px;">FleetService::bulkUpsertProjections()</code> using INSERT…ON DUPLICATE KEY UPDATE on the UNIQUE (campaign_id, period_number) constraint.
</p>

<?php /* ════ STEP 7 non-fleet / STEP 8 fleet — Highlights ════════════════ */ ?>
<?php elseif ($step === $highlightsStepNum): ?>
<div class="step-heading">
    <div class="step-number">Step <?= $highlightsStepNum ?> of <?= $totalSteps ?></div>
    <h2>SPV Key Highlights</h2>
    <p>These headline stats appear on the SPV listing card — the first numbers a potential investor sees. Add up to 8.</p>
</div>
<div class="callout info"><i class="fa-solid fa-circle-info"></i><div>These highlights are <strong>specific to this SPV</strong> and override the parent company's highlights on the campaign page.</div></div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1.25rem;">
    <div class="row-col-label" style="padding-left:.2rem;">Label</div>
    <div class="row-col-label" style="padding-left:.2rem;">Value</div>
</div>
<div class="dynamic-rows" id="hlRows">
    <?php foreach ($data['highlights'] as $hl): ?>
    <div class="dynamic-row hl-row">
        <div><input type="text" name="hl_label[]" value="<?= htmlspecialchars($hl['label']??'') ?>" placeholder="e.g. Total Raise"></div>
        <div><input type="text" name="hl_value[]" value="<?= htmlspecialchars($hl['value']??'') ?>" placeholder="e.g. R 500 000"></div>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php endforeach; ?>
</div>
<button type="button" class="btn-add-row" id="addHlBtn" onclick="addHlRow()"><i class="fa-solid fa-plus"></i> Add highlight</button>

<?php /* ════ STEP 8 non-fleet / STEP 9 fleet — Review & Submit ════════════ */ ?>
<?php elseif ($step === $reviewStepNum): ?>
<div class="step-heading">
    <div class="step-number">Step <?= $reviewStepNum ?> of <?= $totalSteps ?></div>
    <h2>Review &amp; Submit</h2>
    <p>Review everything before submitting. Once submitted, the campaign moves to <strong>under review</strong> and cannot be edited.</p>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-pen"></i> Campaign</div>
    <div class="review-row"><span class="review-row-label">Title</span><span class="review-row-value"><?= htmlspecialchars($data['title']) ?></span></div>
    <?php if ($data['tagline']): ?><div class="review-row"><span class="review-row-label">Tagline</span><span class="review-row-value"><?= htmlspecialchars($data['tagline']) ?></span></div><?php endif; ?>
    <div class="review-row"><span class="review-row-label">Type</span><span class="review-row-value"><?= ['revenue_share'=>'Revenue Share','cooperative_membership'=>'Cooperative Membership','fleet_asset'=>'Fleet Asset SPV'][$data['campaign_type']] ?? $data['campaign_type'] ?></span></div>
    <?php if ($isFleet && !empty($data['fleet_asset_type'])): ?>
    <div class="review-row"><span class="review-row-label">Asset Type</span><span class="review-row-value"><?= htmlspecialchars($data['fleet_asset_type']) ?> (<?= (int)($data['fleet_asset_count']??0) ?> units)</span></div>
    <?php endif; ?>
    <div class="review-row"><span class="review-row-label">Opens</span><span class="review-row-value"><?= fmtDate2($data['opens_at']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Closes</span><span class="review-row-value"><?= fmtDate2($data['closes_at']) ?></span></div>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-building"></i> SPV Identity</div>
    <div class="review-row"><span class="review-row-label">Registered Name</span><span class="review-row-value highlight"><?= htmlspecialchars($data['spv_registered_name']?:'—') ?></span></div>
    <div class="review-row"><span class="review-row-label">CIPC Number</span><span class="review-row-value"><?= htmlspecialchars($data['spv_registration_number']?:'—') ?></span></div>
    <div class="review-row"><span class="review-row-label">Address</span><span class="review-row-value"><?= $data['spv_address_same_as_company'] ? 'Same as '.htmlspecialchars($company['name']) : htmlspecialchars(implode(', ',array_filter([$data['spv_suburb'],$data['spv_city'],$data['spv_province']]))?:'—') ?></span></div>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-bullseye"></i> Funding Targets</div>
    <div class="review-row"><span class="review-row-label">Raise Target</span><span class="review-row-value highlight"><?= fmtCurrency($data['raise_target']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Minimum Raise</span><span class="review-row-value"><?= fmtCurrency($data['raise_minimum']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Hard Cap</span><span class="review-row-value"><?= $data['raise_maximum'] ? fmtCurrency($data['raise_maximum']) : 'No hard cap' ?></span></div>
    <div class="review-row"><span class="review-row-label">Min. Contribution</span><span class="review-row-value"><?= fmtCurrency($data['min_contribution']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Contributor Cap</span><span class="review-row-value"><?= (int)$data['max_contributors'] ?> people</span></div>
</div>

<?php if ($isFleet): ?>
<div class="review-block">
    <div class="review-block-header fleet-header"><i class="fa-solid fa-truck"></i> Fleet Asset Waterfall Terms</div>
    <div class="review-row"><span class="review-row-label">Hurdle Rate</span><span class="review-row-value"><?= $data['fleet_hurdle_rate'] ? htmlspecialchars($data['fleet_hurdle_rate']).'% p.a.' : 'No hurdle' ?></span></div>
    <div class="review-row"><span class="review-row-label">Investor Waterfall</span><span class="review-row-value highlight"><?= htmlspecialchars($data['fleet_investor_waterfall_pct']?:'—') ?>%</span></div>
    <div class="review-row"><span class="review-row-label">Management Fee</span><span class="review-row-value"><?= htmlspecialchars($data['fleet_management_fee_pct']?:'—') ?>% of <?= $data['fleet_management_fee_basis']==='net_after_hurdle'?'net (after hurdle)':'gross revenue' ?></span></div>
    <div class="review-row"><span class="review-row-label">Distribution</span><span class="review-row-value"><?= ucfirst($data['fleet_distribution_frequency']?:'—') ?> · <?= $data['fleet_term_months'] ? htmlspecialchars($data['fleet_term_months']).' months' : '—' ?></span></div>
    <?php if (!empty($data['fleet_total_acq_cost'])): ?>
    <div class="review-row"><span class="review-row-label">Total Acquisition Cost</span><span class="review-row-value"><?= fmtCurrency($data['fleet_total_acq_cost']) ?></span></div>
    <?php endif; ?>
</div>
<?php
$assetCount = count($data['fleet_assets'] ?? []);
$projCount  = count($data['fleet_projection_rows'] ?? []);
if ($assetCount > 0 || $projCount > 0):
?>
<div class="review-block">
    <div class="review-block-header fleet-header"><i class="fa-solid fa-list-check"></i> Fleet Data Summary</div>
    <div class="review-row"><span class="review-row-label">Assets Registered</span><span class="review-row-value"><?= $assetCount ?> of <?= (int)($data['fleet_asset_count']??0) ?> <?= $assetCount === 0 ? '<span style="color:var(--amber);">⚠ none entered</span>' : '' ?></span></div>
    <div class="review-row"><span class="review-row-label">Projection Periods</span><span class="review-row-value"><?= $projCount ?> periods <?= $projCount === 0 ? '<span style="color:var(--amber);">⚠ no financial model</span>' : '' ?></span></div>
</div>
<?php endif; ?>
<?php elseif ($data['campaign_type'] === 'revenue_share'): ?>
<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
    <div class="review-row"><span class="review-row-label">Revenue Share</span><span class="review-row-value highlight"><?= htmlspecialchars($data['rs_percentage']) ?>% per month</span></div>
    <div class="review-row"><span class="review-row-label">Duration</span><span class="review-row-value"><?= htmlspecialchars($data['rs_duration']) ?> months</span></div>
    <div class="review-row"><span class="review-row-label">Governing Law</span><span class="review-row-value">Republic of South Africa</span></div>
</div>
<?php else: ?>
<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
    <div class="review-row"><span class="review-row-label">Unit Name</span><span class="review-row-value"><?= htmlspecialchars($data['co_unit_name']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Price / Unit</span><span class="review-row-value highlight"><?= fmtCurrency($data['co_unit_price']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Units Available</span><span class="review-row-value"><?= (int)$data['co_units_total'] ?></span></div>
</div>
<?php endif; ?>

<?php if (!empty($data['use_of_funds'])): ?>
<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-sack-dollar"></i> Use of Funds</div>
    <?php foreach ($data['use_of_funds'] as $uof): ?>
    <div class="review-row"><span class="review-row-label"><?= htmlspecialchars($uof['label']) ?></span><span class="review-row-value"><?= $uof['amount'] ? fmtCurrency($uof['amount']) : '—' ?></span></div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="callout" style="margin-top:1rem;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>By submitting you confirm all information is accurate. The Old Union team will review within <strong>2–4 business days</strong>.</div>
</div>

<?php endif; /* end step switch */ ?>

<!-- ═══ STEP ACTIONS ═══ -->
<div class="step-actions">
    <div class="step-actions-left">
        <?php if ($step > 1): ?>
        <button type="submit" class="btn btn-back" onclick="setAction('back')"><i class="fa-solid fa-arrow-left"></i> Back</button>
        <?php else: ?>
        <a href="/app/company/campaigns/index.php?uuid=<?= urlencode($companyUuid) ?>" class="btn btn-ghost"><i class="fa-solid fa-xmark"></i> Cancel</a>
        <?php endif; ?>
    </div>
    <div class="step-actions-right">
        <?php if ($step < $totalSteps): ?>
        <button type="submit" class="btn btn-save" onclick="setAction('next')"><i class="fa-solid fa-floppy-disk"></i> Save &amp; Continue</button>
        <button type="submit" class="btn btn-primary" onclick="setAction('next')">Continue <i class="fa-solid fa-arrow-right"></i></button>
        <?php else: ?>
        <button type="submit" class="btn btn-save" onclick="setAction('back')"><i class="fa-solid fa-arrow-left"></i> Go Back &amp; Edit</button>
        <button type="submit" class="btn btn-amber" onclick="setAction('submit')" id="submitBtn"><i class="fa-solid fa-paper-plane"></i> Submit for Review</button>
        <?php endif; ?>
    </div>
</div>
</form>
</div>
</main>
</div>

<script>
// ── Shared helpers ────────────────────────────────────────────────────────
function setAction(v){ document.getElementById('actionInput').value = v; }
function previewImage(input, wrapId, imgId){
    if(input.files&&input.files[0]){
        const r=new FileReader();
        r.onload=e=>{document.getElementById(imgId).src=e.target.result;document.getElementById(wrapId).style.display='block';};
        r.readAsDataURL(input.files[0]);
    }
}
function showFileName(input, labelId){
    if(input.files&&input.files[0]) document.getElementById(labelId).innerHTML='<strong>'+input.files[0].name+'</strong> selected';
}
function toggleAddressSection(sameAsParent){
    const s=document.getElementById('ownAddressSection');
    if(s) s.classList.toggle('hidden',sameAsParent);
}

// ── US-401: Type card toggle ──────────────────────────────────────────────
document.querySelectorAll('.type-cards-three input[type="radio"]').forEach(r=>{
    r.addEventListener('change',function(){
        document.querySelectorAll('.type-card').forEach(c=>c.classList.remove('selected'));
        this.closest('.type-card').classList.add('selected');
        onTypeChange(this.value);
    });
});
function onTypeChange(type){
    const sf=document.getElementById('fleetSubfields');
    if(!sf) return;
    const atEl=document.getElementById('fleet_asset_type');
    const acEl=document.getElementById('fleet_asset_count');
    if(type==='fleet_asset'){
        sf.classList.add('visible');
        if(atEl) atEl.required=true;
        if(acEl) acEl.required=true;
    } else {
        sf.classList.remove('visible');
        if(atEl) atEl.required=false;
        if(acEl) acEl.required=false;
    }
}
(function(){
    const checked=document.querySelector('.type-cards-three input[type="radio"]:checked');
    if(checked) onTypeChange(checked.value);
})();

// ── Revenue share preview ─────────────────────────────────────────────────
(function(){
    const p=document.getElementById('rsPct'),d=document.getElementById('rsDur');
    if(!p||!d) return;
    function u(){ const pv=parseFloat(p.value)||0,dv=parseInt(d.value)||0;
        document.getElementById('previewDur').textContent=dv||'—';
        document.getElementById('previewTerms').textContent=(pv&&dv)?pv+'% of monthly revenue for '+dv+' months':'Fill in fields above'; }
    p.addEventListener('input',u);d.addEventListener('input',u);u();
})();

// ── Co-op preview ─────────────────────────────────────────────────────────
(function(){
    const pr=document.getElementById('coPrice'),to=document.getElementById('coTotal');
    if(!pr||!to) return;
    const fmt=n=>'R\u00a0'+n.toLocaleString('en-ZA',{minimumFractionDigits:2});
    function u(){ const pv=parseFloat(pr.value)||0,tv=parseInt(to.value)||0;
        document.getElementById('coPriceDisplay').textContent=pv?fmt(pv):'—';
        document.getElementById('coUnitsDisplay').textContent=tv||'—';
        document.getElementById('coTotalDisplay').textContent=(pv&&tv)?fmt(pv*tv):'—'; }
    pr.addEventListener('input',u);to.addEventListener('input',u);u();
})();

// ── Fleet waterfall preview (Step 5) ─────────────────────────────────────
(function(){
    const hEl=document.getElementById('fleetHurdleRate'),
          wEl=document.getElementById('fleetWaterfallPct'),
          mEl=document.getElementById('fleetMgmtFeePct'),
          bEl=document.getElementById('fleetMgmtFeeBasis'),
          fEl=document.getElementById('fleetDistFreq'),
          tEl=document.getElementById('fleetTermMonths'),
          aEl=document.getElementById('fleetAcqCostPerUnit'),
          xEl=document.getElementById('fleetTotalAcqCost');
    if(!hEl) return;
    const ASSET_COUNT=<?= (int)($data['fleet_asset_count']??0) ?>;
    const fmt=n=>'R\u00a0'+Math.round(n).toLocaleString('en-ZA');
    function updateAcq(){
        if(!aEl||!xEl) return;
        const c=parseFloat(aEl.value)||0;
        xEl.value=(c&&ASSET_COUNT)?(c*ASSET_COUNT).toLocaleString('en-ZA',{minimumFractionDigits:0}):'';
    }
    function updatePrev(){
        const N=100000,h=parseFloat(hEl.value)||0,w=parseFloat(wEl.value)||0,
              m=parseFloat(mEl.value)||0,b=bEl?bEl.value:'gross',
              freq=fEl?fEl.value:'monthly',tm=tEl?parseInt(tEl.value)||0:0;
        const mHurdle=h>0?(N*h/100)/12:0;
        const mgmt=b==='net_after_hurdle'?Math.max(0,N-mHurdle)*m/100:N*m/100;
        const distrib=Math.max(0,N-mHurdle-mgmt)*w/100;
        const set=(id,v)=>{const e=document.getElementById(id);if(e)e.textContent=v;};
        set('prevHurdle',h?h+'%':'none');set('prevHurdleAmt',h?fmt(mHurdle):'—');
        set('prevMgmtPct',m?m+'%':'0%');set('prevMgmtBasis',b==='net_after_hurdle'?'net (after hurdle)':'gross');
        set('prevMgmtAmt',m?fmt(mgmt):'—');set('prevInvPct',w?w+'%':'—');
        set('prevInvAmt',distrib?fmt(distrib):'—');
        set('prevFreq',freq==='quarterly'?'Quarterly':'Monthly');
        set('prevTerm',tm?tm+' months':'—');
    }
    [hEl,wEl,mEl,bEl,fEl,tEl].forEach(e=>{if(e){e.addEventListener('input',updatePrev);e.addEventListener('change',updatePrev);}});
    if(aEl) aEl.addEventListener('input',updateAcq);
    updatePrev();updateAcq();
})();

// ── SA location cascade (Step 3) ─────────────────────────────────────────
(function(){
    const LOC=window.SA_LOCATIONS;if(!LOC) return;
    const sP=document.getElementById('spvProvince'),sM=document.getElementById('spvMunicipality'),
          sC=document.getElementById('spvCity'),sS=document.getElementById('spvSuburb');
    if(!sP) return;
    const INIT={municipality:<?= json_encode($data['spv_municipality']??'') ?>,city:<?= json_encode($data['spv_city']??'') ?>,suburb:<?= json_encode($data['spv_suburb']??'') ?>};
    function fill(sel,items,ph,sel2){sel.innerHTML='<option value="">'+ph+'</option>';(items||[]).forEach(i=>{const o=document.createElement('option');o.value=i;o.textContent=i;if(i===sel2)o.selected=true;sel.appendChild(o);});sel.disabled=!items||items.length===0;}
    function clear(sel,ph){sel.innerHTML='<option value="">'+ph+'</option>';sel.disabled=true;}
    function onP(){fill(sM,LOC.municipalities[sP.value]||[],'— Select municipality —',INIT.municipality);clear(sC,'— Select city —');clear(sS,'— Select suburb —');if(LOC.municipalities[sP.value]?.length&&INIT.municipality)onM();}
    function onM(){fill(sC,LOC.cities[sM.value]||[],'— Select city —',INIT.city);clear(sS,'— Select suburb —');if(LOC.cities[sM.value]?.length&&INIT.city)onC();}
    function onC(){fill(sS,LOC.suburbs[sC.value]||[],'— Select suburb —',INIT.suburb);}
    sP.addEventListener('change',()=>{INIT.municipality=INIT.city=INIT.suburb='';onP();});
    sM.addEventListener('change',()=>{INIT.city=INIT.suburb='';onM();});
    sC.addEventListener('change',()=>{INIT.suburb='';onC();});
    if(sP.value) onP();
})();

// ── UoF dynamic rows ─────────────────────────────────────────────────────
function removeRow(btn){btn.closest('.dynamic-row').remove();updateUofTotal();}
function addUofRow(){
    const c=document.getElementById('uofRows');
    const r=document.createElement('div');r.className='dynamic-row uof-row';
    r.innerHTML='<div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" placeholder="e.g. Working capital"></div><div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" placeholder="0" oninput="updateUofTotal()"></div></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>';
    c.appendChild(r);r.querySelector('input[type="text"]').focus();
}
function updateUofTotal(){
    const inputs=document.querySelectorAll('#uofRows input[name="uof_amount[]"]');
    let t=0;inputs.forEach(i=>{t+=parseFloat(i.value)||0;});
    const el=document.getElementById('uofTotalDisplay');
    if(el) el.textContent='R\u00a0'+t.toLocaleString('en-ZA',{minimumFractionDigits:0});
}

// ── Highlights dynamic rows ───────────────────────────────────────────────
const MAX_HL=8;
function addHlRow(){
    const c=document.getElementById('hlRows');if(!c) return;
    if(c.querySelectorAll('.dynamic-row').length>=MAX_HL){checkHlMax();return;}
    const r=document.createElement('div');r.className='dynamic-row hl-row';
    r.innerHTML='<div><input type="text" name="hl_label[]" placeholder="e.g. Monthly Revenue"></div><div><input type="text" name="hl_value[]" placeholder="e.g. R 45 000"></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>';
    c.appendChild(r);r.querySelector('input').focus();checkHlMax();
}
function checkHlMax(){
    const btn=document.getElementById('addHlBtn'),c=document.getElementById('hlRows');
    if(!btn||!c) return;
    const cnt=c.querySelectorAll('.dynamic-row').length;
    btn.disabled=cnt>=MAX_HL;
    btn.innerHTML=cnt>=MAX_HL?'<i class="fa-solid fa-lock"></i> Maximum 8 reached':'<i class="fa-solid fa-plus"></i> Add highlight';
}

// ── US-403 (Team C Phase 2): Asset register table JS ─────────────────────
const FLEET_MAX_ASSETS = <?= (int)($data['fleet_asset_count']??200) ?>;
const FLEET_PLATFORMS = <?= json_encode(array_keys($fleetPlatforms)) ?>;

function removeAssetRow(btn){
    const tbody=document.getElementById('assetTableBody');
    if(!tbody||tbody.querySelectorAll('.asset-row').length<=1){return;}
    btn.closest('.asset-row').remove();
    renumberAssetRows();
}
function renumberAssetRows(){
    const rows=document.querySelectorAll('#assetTableBody .asset-row');
    rows.forEach((r,i)=>{const n=r.querySelector('.asset-row-num');if(n)n.textContent=i+1;});
    const countEl=document.getElementById('assetRowCount');
    if(countEl) countEl.textContent=rows.length;
    const addBtn=document.getElementById('addAssetRowBtn');
    if(addBtn) addBtn.disabled=rows.length>=FLEET_MAX_ASSETS;
}
function addAssetRow(){
    const tbody=document.getElementById('assetTableBody');if(!tbody) return;
    const currentCount=tbody.querySelectorAll('.asset-row').length;
    if(currentCount>=FLEET_MAX_ASSETS) return;
    const n=currentCount+1;
    const pad=String(n).padStart(2,'0');
    const platformOpts=FLEET_PLATFORMS.map(v=>`<option value="${v}"${v==='other'?' selected':''}>${{uber_eats:'Uber Eats',bolt:'Bolt',both:'Both',direct:'Direct',other:'Other'}[v]}</option>`).join('');
    const tr=document.createElement('tr');tr.className='asset-row';
    tr.innerHTML=`
        <td class="asset-row-num">${n}</td>
        <td><input type="text" name="fa_label[]" placeholder="e.g. Scooter #${pad}" required></td>
        <td><input type="text" name="fa_make[]"  placeholder="e.g. Ninebot"></td>
        <td><input type="text" name="fa_model[]" placeholder="e.g. ES4"></td>
        <td><input type="number" name="fa_year[]"  min="2015" max="${new Date().getFullYear()+1}" placeholder="${new Date().getFullYear()}"></td>
        <td><input type="number" name="fa_cost[]"  min="0" step="100" placeholder="35000"></td>
        <td><select name="fa_platform[]">${platformOpts}</select></td>
        <td><input type="text" name="fa_serial[]" placeholder="Optional"></td>
        <td><button type="button" class="btn-remove-row" onclick="removeAssetRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    tbody.appendChild(tr);
    tr.querySelector('input[name="fa_label[]"]').focus();
    renumberAssetRows();
}
// ── End US-403 ───────────────────────────────────────────────────────────

// ── US-404 (Team C Phase 2): Projection model JS ─────────────────────────
// Waterfall constants from PHP (Step 5 session data)
const PROJ_HURDLE_RATE   = <?= (float)($data['fleet_hurdle_rate']??0) ?>;          // % p.a.
const PROJ_WATERFALL_PCT = <?= (float)($data['fleet_investor_waterfall_pct']??0) ?>; // %
const PROJ_MGMT_FEE_PCT  = <?= (float)($data['fleet_management_fee_pct']??0) ?>;    // %
const PROJ_MGMT_BASIS    = '<?= addslashes($data['fleet_management_fee_basis']??'gross') ?>';
const PROJ_RAISE_TARGET  = <?= (float)($data['raise_target']??0) ?>;

function calcWaterfall(gross, energy, maint, insur) {
    // Management fee
    let mgmt = 0;
    if (PROJ_MGMT_BASIS === 'gross') {
        mgmt = gross * PROJ_MGMT_FEE_PCT / 100;
    }
    const opex = energy + maint + insur + mgmt;
    let net = gross - opex;

    if (PROJ_MGMT_BASIS === 'net_after_hurdle') {
        // mgmt is on net after hurdle — recompute
        const hurdleMonthly = PROJ_HURDLE_RATE > 0 ? (PROJ_HURDLE_RATE / 100 * PROJ_RAISE_TARGET) / 12 : 0;
        const aboveHurdle   = net >= hurdleMonthly;
        const netForFee     = aboveHurdle ? net - hurdleMonthly : 0;
        mgmt = netForFee * PROJ_MGMT_FEE_PCT / 100;
        const opex2 = energy + maint + insur + mgmt;
        net  = gross - opex2;
    }

    // Hurdle check
    const hurdleMonthly = PROJ_HURDLE_RATE > 0 ? (PROJ_HURDLE_RATE / 100 * PROJ_RAISE_TARGET) / 12 : 0;
    const hurdleCleared = net >= hurdleMonthly && hurdleMonthly > 0;

    // Investor distribution from waterfall
    const distributable = Math.max(0, net);
    const invDist = distributable * PROJ_WATERFALL_PCT / 100;

    // Recompute opex with final mgmt
    const opexFinal = energy + maint + insur + mgmt;

    return {
        mgmt: Math.max(0, mgmt),
        opexFinal: Math.max(0, opexFinal),
        net: net,
        invDist: Math.max(0, invDist),
        hurdleCleared: hurdleCleared,
    };
}

function recalcRow(inputEl) {
    const tr = inputEl.closest('tr');
    if (!tr) return;
    const get = (name) => parseFloat(tr.querySelector(`input[name="${name}"]`)?.value) || 0;
    const set = (name, val) => {
        const el = tr.querySelector(`input[name="${name}"]`);
        if (el) el.value = Math.round(val * 100) / 100;
    };
    const setChk = (name, val) => {
        const el = tr.querySelector(`input[name="${name}"]`);
        if (el) el.checked = val;
    };

    const gross  = get('proj_gross[]');
    const energy = get('proj_energy[]');
    const maint  = get('proj_maint[]');
    const insur  = get('proj_insur[]');
    const r = calcWaterfall(gross, energy, maint, insur);

    set('proj_mgmt_fee[]', r.mgmt);
    set('proj_opex[]',     r.opexFinal);
    set('proj_net[]',      r.net);
    set('proj_inv_dist[]', r.invDist);
    setChk('proj_hurdle[]', r.hurdleCleared);

    updateProjTotals();
}

function updateProjTotals() {
    const rows = document.querySelectorAll('#projTableBody .proj-row');
    const totals = { gross:0, energy:0, maint:0, insur:0, mgmt:0, opex:0, net:0, invDist:0 };
    const getV = (tr, name) => parseFloat(tr.querySelector(`input[name="${name}"]`)?.value) || 0;
    rows.forEach(tr => {
        totals.gross   += getV(tr,'proj_gross[]');
        totals.energy  += getV(tr,'proj_energy[]');
        totals.maint   += getV(tr,'proj_maint[]');
        totals.insur   += getV(tr,'proj_insur[]');
        totals.mgmt    += getV(tr,'proj_mgmt_fee[]');
        totals.opex    += getV(tr,'proj_opex[]');
        totals.net     += getV(tr,'proj_net[]');
        totals.invDist += getV(tr,'proj_inv_dist[]');
    });
    const fmt = n => n ? 'R\u00a0'+Math.round(n).toLocaleString('en-ZA') : '—';
    const setT = (id, v) => { const e = document.getElementById(id); if(e) e.textContent = fmt(v); };
    setT('totGross', totals.gross); setT('totEnergy', totals.energy);
    setT('totMaint',  totals.maint); setT('totInsur',  totals.insur);
    setT('totMgmt',   totals.mgmt); setT('totOpex',    totals.opex);
    setT('totNet',    totals.net);  setT('totInvDist', totals.invDist);
}

function removeProjRow(btn) {
    const tbody = document.getElementById('projTableBody');
    if (!tbody || tbody.querySelectorAll('.proj-row').length <= 1) return;
    btn.closest('.proj-row').remove();
    renumberProjRows();
    updateProjTotals();
}

let projRowCounter = <?= count($data['fleet_projection_rows'] ?? [[1]]) ?>;

function addProjRow() {
    const tbody = document.getElementById('projTableBody');
    if (!tbody || tbody.querySelectorAll('.proj-row').length >= 60) return;
    projRowCounter++;
    const tr = document.createElement('tr');
    tr.className = 'proj-row';
    tr.dataset.period = projRowCounter;
    tr.innerHTML = `
        <td class="pn-cell"><input type="hidden" name="proj_period_number[]" value="${projRowCounter}">${projRowCounter}</td>
        <td><input type="text" name="proj_label[]" value="Month ${projRowCounter}" placeholder="Month ${projRowCounter}"></td>
        <td><input type="number" name="proj_gross[]"    min="0" step="100" placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
        <td><input type="number" name="proj_energy[]"   min="0" step="10"  placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
        <td><input type="number" name="proj_maint[]"    min="0" step="10"  placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
        <td><input type="number" name="proj_insur[]"    min="0" step="10"  placeholder="0" class="proj-input" onchange="recalcRow(this)"></td>
        <td><input type="number" name="proj_mgmt_fee[]" min="0" step="10"  placeholder="0" class="proj-input computed" readonly></td>
        <td><input type="number" name="proj_opex[]"     min="0" step="10"  placeholder="0" class="proj-input computed" readonly></td>
        <td><input type="number" name="proj_net[]"      min="0" step="10"  placeholder="0" class="proj-input computed" readonly></td>
        <td><input type="number" name="proj_inv_dist[]" min="0" step="0.01" placeholder="0" class="proj-input computed" readonly></td>
        <td class="chk-cell"><input type="checkbox" name="proj_hurdle[]" value="1" class="proj-hurdle" disabled></td>
        <td><button type="button" class="btn-remove-row" onclick="removeProjRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
    `;
    tbody.appendChild(tr);
    tr.querySelector('input[name="proj_gross[]"]').focus();
    renumberProjRows();
}

function renumberProjRows() {
    const rows = document.querySelectorAll('#projTableBody .proj-row');
    const countEl = document.getElementById('projRowCount');
    if (countEl) countEl.textContent = rows.length + ' period' + (rows.length!==1?'s':'') + ' · max 60';
}

function generateProjections() {
    const stabNet   = parseFloat(document.getElementById('genStabilisedNet')?.value)   || 0;
    const rampPer   = parseInt(document.getElementById('genRampPeriods')?.value)        || 3;
    const totalPer  = parseInt(document.getElementById('genTotalPeriods')?.value)       || 12;

    if (!stabNet || totalPer < 1) {
        alert('Please enter a stabilised monthly net income and total periods.');
        return;
    }

    // Clear existing rows
    const tbody = document.getElementById('projTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    projRowCounter = 0;

    for (let p = 1; p <= Math.min(totalPer, 60); p++) {
        projRowCounter = p;
        // Ramp: periods 1..rampPer scale from 50% to 100%
        const rampFactor = p <= rampPer ? (0.5 + 0.5 * ((p-1)/(Math.max(rampPer,1)))) : 1;
        const gross = Math.round(stabNet * rampFactor / 0.7 / 100) * 100; // assume 70% margin → back-calc gross

        // Simple cost split: energy 15%, maint 10%, insur 5%
        const energy = Math.round(gross * 0.15 / 10) * 10;
        const maint  = Math.round(gross * 0.10 / 10) * 10;
        const insur  = Math.round(gross * 0.05 / 10) * 10;
        const r = calcWaterfall(gross, energy, maint, insur);

        const tr = document.createElement('tr');
        tr.className = 'proj-row';
        tr.dataset.period = p;
        tr.innerHTML = `
            <td class="pn-cell"><input type="hidden" name="proj_period_number[]" value="${p}">${p}</td>
            <td><input type="text" name="proj_label[]" value="Month ${p}"></td>
            <td><input type="number" name="proj_gross[]"    value="${gross}"               min="0" step="100" class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_energy[]"   value="${energy}"              min="0" step="10"  class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_maint[]"    value="${maint}"               min="0" step="10"  class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_insur[]"    value="${insur}"               min="0" step="10"  class="proj-input" onchange="recalcRow(this)"></td>
            <td><input type="number" name="proj_mgmt_fee[]" value="${r.mgmt.toFixed(2)}"   min="0" step="10"  class="proj-input computed" readonly></td>
            <td><input type="number" name="proj_opex[]"     value="${r.opexFinal.toFixed(2)}" min="0" step="10"  class="proj-input computed" readonly></td>
            <td><input type="number" name="proj_net[]"      value="${r.net.toFixed(2)}"    min="0" step="10"  class="proj-input computed" readonly></td>
            <td><input type="number" name="proj_inv_dist[]" value="${r.invDist.toFixed(2)}" min="0" step="0.01" class="proj-input computed" readonly></td>
            <td class="chk-cell"><input type="checkbox" name="proj_hurdle[]" value="1" ${r.hurdleCleared?'checked':''} class="proj-hurdle" disabled></td>
            <td><button type="button" class="btn-remove-row" onclick="removeProjRow(this)"><i class="fa-solid fa-xmark"></i></button></td>
        `;
        tbody.appendChild(tr);
    }
    renumberProjRows();
    updateProjTotals();
}
// ── End US-404 ───────────────────────────────────────────────────────────

// Submit confirmation
const submitBtn = document.getElementById('submitBtn');
if (submitBtn) {
    submitBtn.addEventListener('click', function(e) {
        if (!confirm('Submit this SPV campaign for review?\n\nYou will not be able to edit it while under review (2–4 business days).')) e.preventDefault();
    });
}

// Slide animation
const card = document.getElementById('stepCard');
<?php if (!empty($_POST['action']) && $_POST['action'] === 'back'): ?>
card.classList.add('going-back');
<?php endif; ?>

document.addEventListener('DOMContentLoaded',()=>{
    updateUofTotal();
    checkHlMax();
    updateProjTotals();
    renumberAssetRows();
    renumberProjRows();
});
</script>
</body>
</html>
