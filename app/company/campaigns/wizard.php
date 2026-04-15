<?php
// ============================================================
// company/campaigns/wizard.php
// SPV Campaign Setup Wizard — 8 steps
//
// Step 1 — Basics          (title, type, timeline)
// Step 2 — SPV Identity    (registered name, CIPC number, description)
// Step 3 — SPV Address     (same-as-parent toggle, or own address)
// Step 4 — Targets         (raise amounts, contributor limits)
// Step 5 — Deal Terms      (revenue share or co-op)
// Step 6 — Pitch & Funds   (investment thesis, use of funds, team, deck)
// Step 7 — Highlights      (SPV-specific key stats)
// Step 8 — Review & Submit
// ============================================================

require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/company_uploads.php';
require_once '../../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$companyUuid = trim($_GET['uuid'] ?? '');
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

/* ── Load parent company address for "same as parent" toggle ── */
$stmt = $pdo->prepare("SELECT * FROM company_filter WHERE company_id = ?");
$stmt->execute([$companyId]);
$parentAddress = $stmt->fetch() ?: [];

/* ── Seed session on first visit ───────────── */
if (!isset($_SESSION[$sessKey])) {
    // Use_of_funds stored as JSON array in funding_campaigns.use_of_funds
    $existingUof = [];
    if (!empty($campaign['use_of_funds'])) {
        $existingUof = json_decode($campaign['use_of_funds'], true) ?: [];
    }

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
        // Step 5 — Deal Terms
        'rs_percentage'            => $terms['revenue_share_percentage']      ?? '',
        'rs_duration'              => $terms['revenue_share_duration_months'] ?? '',
        'co_unit_name'             => $terms['unit_name']             ?? '',
        'co_unit_price'            => $terms['unit_price']            ?? '',
        'co_units_total'           => $terms['total_units_available'] ?? '',
        // Step 6 — Pitch & Funds
        'investment_thesis'        => $pitchRow['investment_thesis']  ?? '',
        'use_of_funds'             => $existingUof,
        'risk_factors'             => $pitchRow['risk_factors']       ?? '',
        'exit_strategy'            => $pitchRow['exit_strategy']      ?? '',
        'spv_team_overview'        => $pitchRow['spv_team_overview']  ?? '',
        'spv_traction'             => $pitchRow['spv_traction']       ?? '',
        'pitch_deck_url'           => $pitchRow['pitch_deck_url']     ?? '',
        'pitch_video_url'          => $pitchRow['pitch_video_url']    ?? '',
        // Step 7 — Highlights
        'highlights'               => !empty($existingHighlights)
                                        ? $existingHighlights
                                        : [
                                            ['label' => 'Total Raise',       'value' => ''],
                                            ['label' => 'Revenue Share',      'value' => ''],
                                            ['label' => 'Duration',           'value' => ''],
                                          ],
        // Branding (collected on step 2, stored separately)
        'spv_logo'                 => $campaign['spv_logo']   ?? '',
        'spv_banner'               => $campaign['spv_banner'] ?? '',
        // KYC docs
        'kyc_registration_document' => $kycRow['registration_document']  ?? '',
        'kyc_proof_of_address'      => $kycRow['proof_of_address']        ?? '',
        'kyc_director_id'           => $kycRow['director_id_document']    ?? '',
        'kyc_tax_clearance'         => $kycRow['tax_clearance_document']  ?? '',
    ];
}

$data   = &$_SESSION[$sessKey];
$step   = max(1, min(8, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$errors = [];

$totalSteps = 8;

/* ═══════════════════════════════════════════════════
   POST HANDLER
═══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $action   = $_POST['action'] ?? 'next';
    $postStep = (int)($_POST['step'] ?? 1);

    if (empty($errors)) {

        /* ── Step 1 : Basics ──────────────────────── */
        if ($postStep === 1) {
            $title    = trim($_POST['title']    ?? '');
            $type     = trim($_POST['campaign_type'] ?? '');
            $opensAt  = trim($_POST['opens_at']  ?? '');
            $closesAt = trim($_POST['closes_at'] ?? '');

            if ($title === '') { $errors[] = 'Campaign title is required.'; }
            if (!in_array($type, ['revenue_share', 'cooperative_membership'], true)) {
                $errors[] = 'Please select a campaign type.';
            }
            if ($opensAt  === '') { $errors[] = 'Opening date is required.'; }
            if ($closesAt === '') { $errors[] = 'Closing date is required.'; }
            if ($opensAt !== '' && $closesAt !== '' && $closesAt <= $opensAt) {
                $errors[] = 'Closing date must be after the opening date.';
            }
            if (empty($errors)) {
                $data['title']         = $title;
                $data['tagline']       = trim($_POST['tagline'] ?? '');
                $data['campaign_type'] = $type;
                $data['opens_at']      = $opensAt;
                $data['closes_at']     = $closesAt;
            }
        }

        /* ── Step 2 : SPV Identity ────────────────── */
        if ($postStep === 2) {
            $regName = trim($_POST['spv_registered_name'] ?? '');
            if ($regName === '') { $errors[] = 'SPV registered name is required.'; }

            $websiteVal = trim($_POST['spv_website'] ?? '');
            if ($websiteVal !== '' && !filter_var($websiteVal, FILTER_VALIDATE_URL)) {
                $errors[] = 'SPV website must be a valid URL (include https://).';
            }
            $emailVal = trim($_POST['spv_email'] ?? '');
            if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'SPV email must be a valid email address.';
            }

            // SPV logo upload
            if (!empty($_FILES['spv_logo']['name'])) {
                $upload = uploadCompanyFile('spv_logo', $campaignUuid, 'logo');
                if ($upload['success']) { $data['spv_logo'] = $upload['path']; }
                else { $errors[] = $upload['error']; }
            }
            // SPV banner upload
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
                $validProvinces = [
                    'Eastern Cape','Free State','Gauteng','KwaZulu-Natal',
                    'Limpopo','Mpumalanga','North West','Northern Cape','Western Cape',
                ];
                $province = trim($_POST['spv_province'] ?? '');
                $area     = trim($_POST['spv_area']     ?? '');
                if (!in_array($province, $validProvinces, true)) {
                    $errors[] = 'Please select a valid province for the SPV address.';
                }
                if (!in_array($area, ['urban','township','rural'], true)) {
                    $errors[] = 'Please select an area type for the SPV address.';
                }
                if (empty($errors)) {
                    $data['spv_province']     = $province;
                    $data['spv_municipality'] = trim($_POST['spv_municipality'] ?? '');
                    $data['spv_city']         = trim($_POST['spv_city']         ?? '');
                    $data['spv_suburb']       = trim($_POST['spv_suburb']       ?? '');
                    $data['spv_area']         = $area;
                }
            } else {
                // Clear any previously entered SPV address
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

            if (!is_numeric($raiseTarget) || (float)$raiseTarget <= 0) {
                $errors[] = 'Raise target must be a positive amount.';
            }
            if (!is_numeric($raiseMinimum) || (float)$raiseMinimum <= 0) {
                $errors[] = 'Minimum raise must be a positive amount.';
            }
            if (is_numeric($raiseMinimum) && is_numeric($raiseTarget)
                && (float)$raiseMinimum > (float)$raiseTarget) {
                $errors[] = 'Minimum raise cannot exceed the raise target.';
            }
            if ($raiseMaximum !== '' && (!is_numeric($raiseMaximum) || (float)$raiseMaximum < (float)$raiseTarget)) {
                $errors[] = 'Hard cap must be greater than or equal to the raise target.';
            }
            if (!is_numeric($minContribution) || (float)$minContribution < 100) {
                $errors[] = 'Minimum contribution must be at least R100.';
            }
            if ($maxContributors < 1 || $maxContributors > 50) {
                $errors[] = 'Contributor cap must be between 1 and 50.';
            }
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
                    $errors[] = 'Revenue share percentage must be between 0.01 and 100.';
                }
                if (!ctype_digit($rsDuration) || (int)$rsDuration < 1) {
                    $errors[] = 'Revenue share duration must be a whole number of months (minimum 1).';
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
                if (!is_numeric($unitPrice) || (float)$unitPrice <= 0) {
                    $errors[] = 'Unit price must be a positive amount.';
                }
                if (!ctype_digit($unitsTotal) || (int)$unitsTotal < 1) {
                    $errors[] = 'Total units available must be a whole positive number.';
                }
                if (empty($errors)) {
                    $data['co_unit_name']   = $unitName;
                    $data['co_unit_price']  = $unitPrice;
                    $data['co_units_total'] = $unitsTotal;
                }
            }
        }

        /* ── Step 6 : Pitch & Funds ───────────────── */
        if ($postStep === 6) {
            // Pitch deck upload
            if (!empty($_FILES['pitch_deck']['name'])) {
                $upload = uploadCompanyFile('pitch_deck', $campaignUuid, 'document');
                if ($upload['success']) { $data['pitch_deck_url'] = $upload['path']; }
                else { $errors[] = $upload['error']; }
            }
            $deckUrl  = trim($_POST['pitch_deck_url_text']  ?? '');
            $videoUrl = trim($_POST['pitch_video_url']       ?? '');
            if ($deckUrl !== '' && !filter_var($deckUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Pitch deck URL must be a valid URL.';
            }
            if ($videoUrl !== '' && !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
                $errors[] = 'Pitch video URL must be a valid URL.';
            }
            if (empty($errors)) {
                $data['investment_thesis'] = trim($_POST['investment_thesis'] ?? '');
                $data['risk_factors']      = trim($_POST['risk_factors']      ?? '');
                $data['exit_strategy']     = trim($_POST['exit_strategy']     ?? '');
                $data['spv_team_overview'] = trim($_POST['spv_team_overview'] ?? '');
                $data['spv_traction']      = trim($_POST['spv_traction']      ?? '');
                // Prefer file upload over text URL
                if (empty($data['pitch_deck_url']) && $deckUrl !== '') {
                    $data['pitch_deck_url'] = $deckUrl;
                }
                $data['pitch_video_url']   = $videoUrl;

                // Use of funds — dynamic rows
                $uofLabels  = $_POST['uof_label']  ?? [];
                $uofAmounts = $_POST['uof_amount']  ?? [];
                $uof = [];
                foreach ($uofLabels as $i => $lbl) {
                    $lbl = trim($lbl);
                    $amt = trim($uofAmounts[$i] ?? '');
                    if ($lbl !== '' || $amt !== '') {
                        $uof[] = ['label' => $lbl, 'amount' => $amt];
                    }
                }
                $data['use_of_funds'] = $uof;
            }
        }

        /* ── Step 7 : Highlights ──────────────────── */
        if ($postStep === 7) {
            $hlLabels = $_POST['hl_label'] ?? [];
            $hlValues = $_POST['hl_value'] ?? [];
            $highlights = [];
            foreach ($hlLabels as $i => $lbl) {
                $lbl = trim($lbl);
                $val = trim($hlValues[$i] ?? '');
                if ($lbl !== '' || $val !== '') {
                    $highlights[] = ['label' => $lbl, 'value' => $val];
                }
            }
            if (empty($highlights)) {
                $highlights = [
                    ['label' => 'Total Raise',  'value' => ''],
                    ['label' => 'Revenue Share', 'value' => ''],
                    ['label' => 'Duration',      'value' => ''],
                ];
            }
            $data['highlights'] = $highlights;
        }

        /* ── Persist to DB on every valid save ────── */
        if (empty($errors)) {

            $isFinalSubmit = ($postStep === 8 && $action === 'submit');
            $newStatus     = $isFinalSubmit ? 'under_review' : 'draft';

            /* funding_campaigns */
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

            /* campaign_terms upsert */
            $termsExists = !empty($terms);
            if ($data['campaign_type'] === 'revenue_share') {
                if ($termsExists) {
                    $pdo->prepare("
                        UPDATE campaign_terms SET
                            revenue_share_percentage      = :pct,
                            revenue_share_duration_months = :dur,
                            unit_name = NULL, unit_price = NULL, total_units_available = NULL
                        WHERE campaign_id = :cid
                    ")->execute(['pct' => $data['rs_percentage'] ?: null, 'dur' => $data['rs_duration'] ?: null, 'cid' => $campaignId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO campaign_terms (campaign_id, revenue_share_percentage, revenue_share_duration_months)
                        VALUES (:cid, :pct, :dur)
                    ")->execute(['cid' => $campaignId, 'pct' => $data['rs_percentage'] ?: null, 'dur' => $data['rs_duration'] ?: null]);
                    $terms = ['campaign_id' => $campaignId];
                }
            } elseif ($data['campaign_type'] === 'cooperative_membership') {
                if ($termsExists) {
                    $pdo->prepare("
                        UPDATE campaign_terms SET
                            unit_name = :name, unit_price = :price, total_units_available = :total,
                            revenue_share_percentage = NULL, revenue_share_duration_months = NULL
                        WHERE campaign_id = :cid
                    ")->execute(['name' => $data['co_unit_name'] ?: null, 'price' => $data['co_unit_price'] ?: null, 'total' => $data['co_units_total'] ?: null, 'cid' => $campaignId]);
                } else {
                    $pdo->prepare("
                        INSERT INTO campaign_terms (campaign_id, unit_name, unit_price, total_units_available)
                        VALUES (:cid, :name, :price, :total)
                    ")->execute(['cid' => $campaignId, 'name' => $data['co_unit_name'] ?: null, 'price' => $data['co_unit_price'] ?: null, 'total' => $data['co_units_total'] ?: null]);
                    $terms = ['campaign_id' => $campaignId];
                }
            }

            /* campaign_pitch upsert */
            $pitchExists = !empty($pitchRow);
            if ($pitchExists) {
                $pdo->prepare("
                    UPDATE campaign_pitch SET
                        investment_thesis = :thesis,
                        risk_factors      = :risks,
                        exit_strategy     = :exit,
                        spv_team_overview = :team,
                        spv_traction      = :traction,
                        pitch_deck_url    = :deck,
                        pitch_video_url   = :video,
                        updated_at        = NOW()
                    WHERE campaign_id = :cid
                ")->execute([
                    'thesis'   => $data['investment_thesis'] ?: null,
                    'risks'    => $data['risk_factors']      ?: null,
                    'exit'     => $data['exit_strategy']     ?: null,
                    'team'     => $data['spv_team_overview'] ?: null,
                    'traction' => $data['spv_traction']      ?: null,
                    'deck'     => $data['pitch_deck_url']    ?: null,
                    'video'    => $data['pitch_video_url']   ?: null,
                    'cid'      => $campaignId,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO campaign_pitch
                        (campaign_id, investment_thesis, risk_factors, exit_strategy,
                         spv_team_overview, spv_traction, pitch_deck_url, pitch_video_url)
                    VALUES (:cid, :thesis, :risks, :exit, :team, :traction, :deck, :video)
                ")->execute([
                    'cid'      => $campaignId,
                    'thesis'   => $data['investment_thesis'] ?: null,
                    'risks'    => $data['risk_factors']      ?: null,
                    'exit'     => $data['exit_strategy']     ?: null,
                    'team'     => $data['spv_team_overview'] ?: null,
                    'traction' => $data['spv_traction']      ?: null,
                    'deck'     => $data['pitch_deck_url']    ?: null,
                    'video'    => $data['pitch_video_url']   ?: null,
                ]);
                $pitchRow = ['campaign_id' => $campaignId];
            }

            /* campaign_highlights — delete and reinsert */
            $pdo->prepare("DELETE FROM campaign_highlights WHERE campaign_id = ?")->execute([$campaignId]);
            if (!empty($data['highlights'])) {
                $hlStmt = $pdo->prepare("INSERT INTO campaign_highlights (campaign_id, label, value, sort_order) VALUES (:cid, :label, :value, :sort)");
                foreach ($data['highlights'] as $i => $hl) {
                    if (!empty($hl['label']) || !empty($hl['value'])) {
                        $hlStmt->execute(['cid' => $campaignId, 'label' => $hl['label'], 'value' => $hl['value'], 'sort' => $i]);
                    }
                }
            }

            /* campaign_kyc upsert — only if we have any doc data */
            $hasKycData = !empty($data['kyc_registration_document'])
                       || !empty($data['kyc_proof_of_address'])
                       || !empty($data['kyc_director_id'])
                       || !empty($data['kyc_tax_clearance']);
            if ($hasKycData) {
                $kycStatus = $isFinalSubmit ? 'under_review' : ($kycRow['verification_status'] ?? 'pending');
                if (!empty($kycRow)) {
                    $pdo->prepare("
                        UPDATE campaign_kyc SET
                            registration_document  = :rd,
                            proof_of_address       = :pa,
                            director_id_document   = :did,
                            tax_clearance_document = :tc,
                            verification_status    = :vs
                        WHERE campaign_id = :cid
                    ")->execute([
                        'rd' => $data['kyc_registration_document'] ?: null,
                        'pa' => $data['kyc_proof_of_address']       ?: null,
                        'did'=> $data['kyc_director_id']            ?: null,
                        'tc' => $data['kyc_tax_clearance']          ?: null,
                        'vs' => $kycStatus, 'cid' => $campaignId,
                    ]);
                } else {
                    $pdo->prepare("
                        INSERT INTO campaign_kyc
                            (campaign_id, registration_document, proof_of_address,
                             director_id_document, tax_clearance_document, verification_status)
                        VALUES (:cid, :rd, :pa, :did, :tc, :vs)
                    ")->execute([
                        'cid'=> $campaignId,
                        'rd' => $data['kyc_registration_document'] ?: null,
                        'pa' => $data['kyc_proof_of_address']       ?: null,
                        'did'=> $data['kyc_director_id']            ?: null,
                        'tc' => $data['kyc_tax_clearance']          ?: null,
                        'vs' => $kycStatus,
                    ]);
                    $kycRow = ['campaign_id' => $campaignId];
                }
            }

            logCompanyActivity($companyId, $userId, 'Saved campaign wizard step ' . $postStep . ': ' . $data['title']);

            if ($isFinalSubmit) {
                logCompanyActivity($companyId, $userId, 'Submitted campaign for review: ' . $data['title']);
                unset($_SESSION[$sessKey]);
                redirect("/app/company/campaigns/index.php?uuid=$companyUuid&submitted=1");
            }

            $step = ($action === 'back') ? max(1, $postStep - 1) : min($totalSteps, $postStep + 1);

        } else {
            $step = $postStep;
        }
    }
}

/* ── Step metadata ─────────────────────────── */
$steps = [
    1 => ['label' => 'Basics',       'icon' => 'fa-pen',           'desc' => 'Title, type & timeline'],
    2 => ['label' => 'SPV Identity', 'icon' => 'fa-building',      'desc' => 'Registered name, number & branding'],
    3 => ['label' => 'SPV Address',  'icon' => 'fa-map-pin',       'desc' => 'Registered address of the SPV'],
    4 => ['label' => 'Targets',      'icon' => 'fa-bullseye',      'desc' => 'Raise goal & contributor limits'],
    5 => ['label' => 'Terms',        'icon' => 'fa-file-contract', 'desc' => 'Deal structure & return terms'],
    6 => ['label' => 'Pitch & Funds','icon' => 'fa-bullhorn',      'desc' => 'Investment case & use of funds'],
    7 => ['label' => 'Highlights',   'icon' => 'fa-star',          'desc' => 'Key SPV stats for investors'],
    8 => ['label' => 'Review',       'icon' => 'fa-circle-check',  'desc' => 'Confirm & submit for review'],
];

$validProvinces = ['Eastern Cape','Free State','Gauteng','KwaZulu-Natal','Limpopo','Mpumalanga','North West','Northern Cape','Western Cape'];

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
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<?php if ($step === 3): ?>
<script src="/app/assets/js/sa-locations.js"></script>
<?php endif; ?>
<style>
:root{--navy:#0b2545;--navy-mid:#0f3b7a;--navy-light:#1a56b0;--amber:#f59e0b;--amber-light:#fef3c7;--amber-dark:#d97706;--green:#0b6b4d;--green-bg:#e6f7ec;--green-bdr:#a7f3d0;--surface:#ffffff;--surface-2:#f8f9fb;--border:#e4e7ec;--border-focus:#1a56b0;--text:#101828;--text-muted:#667085;--text-light:#98a2b3;--error:#b91c1c;--error-bg:#fef2f2;--error-bdr:#fecaca;--sidebar-w:280px;--radius:14px;--radius-sm:8px;--shadow-card:0 8px 32px rgba(11,37,69,.08),0 1px 3px rgba(11,37,69,.06);--shadow-btn:0 4px 12px rgba(15,59,122,.25);--transition:.22s cubic-bezier(.4,0,.2,1);}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--surface-2);color:var(--text);min-height:100vh;display:flex;flex-direction:column;}
::-webkit-scrollbar{width:6px;}::-webkit-scrollbar-thumb{background:var(--border);border-radius:99px;}
.wizard-shell{display:flex;min-height:100vh;}
/* Sidebar */
.wizard-sidebar{width:var(--sidebar-w);background:var(--navy);min-height:100vh;padding:3rem 2rem;display:flex;flex-direction:column;position:sticky;top:0;flex-shrink:0;overflow:hidden;}
.wizard-sidebar::before{content:'';position:absolute;bottom:-80px;right:-80px;width:300px;height:300px;border-radius:50%;border:60px solid rgba(245,158,11,.07);pointer-events:none;}
.sidebar-brand{margin-bottom:2rem;}
.sidebar-brand-logo{font-family:'DM Serif Display',serif;font-size:1.5rem;color:#fff;line-height:1;margin-bottom:.25rem;}
.sidebar-brand-sub{font-size:.78rem;color:rgba(255,255,255,.45);text-transform:uppercase;letter-spacing:.1em;}
.step-list{list-style:none;flex:1;}
.step-item{display:flex;align-items:flex-start;gap:1rem;padding:.65rem 0;position:relative;}
.step-item:not(:last-child)::after{content:'';position:absolute;left:19px;top:calc(.65rem + 38px);width:2px;height:calc(100% - 14px);background:rgba(255,255,255,.1);transition:background var(--transition);}
.step-item.done:not(:last-child)::after{background:rgba(245,158,11,.35);}
.step-icon{width:40px;height:40px;border-radius:50%;background:rgba(255,255,255,.08);border:2px solid rgba(255,255,255,.12);display:flex;align-items:center;justify-content:center;font-size:.85rem;color:rgba(255,255,255,.4);flex-shrink:0;transition:all var(--transition);position:relative;z-index:1;}
.step-item.done .step-icon{background:rgba(245,158,11,.2);border-color:var(--amber);color:var(--amber);}
.step-item.active .step-icon{background:var(--amber);border-color:var(--amber);color:var(--navy);box-shadow:0 0 0 5px rgba(245,158,11,.2);}
.step-text{padding-top:.15rem;}
.step-label{font-size:.82rem;font-weight:600;color:rgba(255,255,255,.4);line-height:1;margin-bottom:.15rem;transition:color var(--transition);}
.step-item.done .step-label{color:rgba(255,255,255,.65);}
.step-item.active .step-label{color:#fff;}
.step-desc{font-size:.7rem;color:rgba(255,255,255,.22);transition:color var(--transition);}
.step-item.active .step-desc{color:rgba(255,255,255,.48);}
.sidebar-footer{margin-top:1.5rem;padding-top:1.25rem;border-top:1px solid rgba(255,255,255,.08);}
.sidebar-footer-label{font-size:.7rem;color:rgba(255,255,255,.32);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.25rem;}
.sidebar-footer-val{font-size:.88rem;font-weight:600;color:rgba(255,255,255,.7);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sidebar-footer-company{font-size:.75rem;color:rgba(255,255,255,.32);margin-top:.25rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
/* Mobile progress */
.mobile-progress{display:none;background:var(--navy);padding:1.25rem 1.5rem;position:sticky;top:0;z-index:99;}
.mob-prog-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem;}
.mob-prog-label{font-size:.9rem;font-weight:600;color:#fff;}
.mob-prog-count{font-size:.8rem;color:rgba(255,255,255,.5);}
.mob-prog-bar{height:4px;background:rgba(255,255,255,.15);border-radius:99px;overflow:hidden;}
.mob-prog-fill{height:100%;background:var(--amber);border-radius:99px;transition:width .4s ease;}
/* Main */
.wizard-main{flex:1;display:flex;flex-direction:column;min-height:100vh;padding:3rem 3.5rem;overflow-x:hidden;}
.step-card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow-card);padding:2.5rem 2.75rem;border:1px solid var(--border);max-width:720px;width:100%;animation:slideIn .3s ease;}
@keyframes slideIn{from{opacity:0;transform:translateX(16px)}to{opacity:1;transform:translateX(0)}}
@keyframes slideBack{from{opacity:0;transform:translateX(-16px)}to{opacity:1;transform:translateX(0)}}
.step-card.going-back{animation:slideBack .3s ease;}
.step-heading{margin-bottom:2rem;}
.step-heading .step-number{font-size:.78rem;font-weight:600;color:var(--amber-dark);text-transform:uppercase;letter-spacing:.1em;margin-bottom:.4rem;}
.step-heading h2{font-family:'DM Serif Display',serif;font-size:1.85rem;color:var(--navy);line-height:1.2;margin-bottom:.4rem;}
.step-heading p{font-size:.93rem;color:var(--text-muted);}
/* Form */
.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;}
.form-grid .span-2{grid-column:span 2;}
.field{display:flex;flex-direction:column;gap:.45rem;}
.field label{font-size:.83rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:.4rem;}
.field label .req{color:var(--amber-dark);}
.field label i{color:var(--navy-light);font-size:.8rem;}
.field input,.field select,.field textarea{padding:.72rem 1rem;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-family:'DM Sans',sans-serif;font-size:.93rem;color:var(--text);background:var(--surface-2);outline:none;width:100%;transition:border-color var(--transition),box-shadow var(--transition),background var(--transition);}
.field input:focus,.field select:focus,.field textarea:focus{border-color:var(--border-focus);background:#fff;box-shadow:0 0 0 3.5px rgba(26,86,176,.1);}
.field select{appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 1rem center;padding-right:2.25rem;cursor:pointer;}
.field select:disabled{opacity:.45;cursor:not-allowed;}
.field textarea{resize:vertical;min-height:90px;line-height:1.6;}
.field .hint{font-size:.77rem;color:var(--text-light);line-height:1.4;}
.form-section-label{font-size:.78rem;font-weight:700;text-transform:uppercase;letter-spacing:.1em;color:var(--text-light);margin:1.75rem 0 1rem;display:flex;align-items:center;gap:.6rem;}
.form-section-label::after{content:'';flex:1;height:1px;background:var(--border);}
/* Toggle / checkbox */
.toggle-field{display:flex;align-items:flex-start;gap:.85rem;padding:1rem 1.25rem;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);cursor:pointer;transition:all var(--transition);}
.toggle-field:has(input:checked){border-color:var(--navy-light);background:#eff4ff;}
.toggle-field input[type="checkbox"]{width:18px;height:18px;accent-color:var(--navy-mid);cursor:pointer;flex-shrink:0;margin-top:.1rem;}
.toggle-field-body{}
.toggle-field-label{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.2rem;}
.toggle-field-desc{font-size:.8rem;color:var(--text-muted);}
/* Address section that shows/hides */
#ownAddressSection{transition:opacity .25s;}
#ownAddressSection.hidden{opacity:.3;pointer-events:none;}
/* File upload */
.file-upload-zone{border:2px dashed var(--border);border-radius:var(--radius-sm);background:var(--surface-2);padding:1.25rem;text-align:center;cursor:pointer;transition:all var(--transition);position:relative;}
.file-upload-zone:hover{border-color:var(--navy-light);background:#eff4ff;}
.file-upload-zone input[type="file"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;}
.file-upload-icon{font-size:1.5rem;color:var(--text-light);margin-bottom:.4rem;}
.file-upload-label{font-size:.85rem;font-weight:500;color:var(--text-muted);}
.file-upload-label strong{color:var(--navy-light);}
.file-upload-sub{font-size:.74rem;color:var(--text-light);margin-top:.2rem;}
.existing-file-link{display:inline-flex;align-items:center;gap:.4rem;font-size:.78rem;color:var(--navy-light);text-decoration:none;margin-top:.5rem;padding:.3rem .75rem;background:#eff4ff;border-radius:99px;border:1px solid #c7d9f8;}
.preview-wrap{margin-top:.75rem;}
.preview-img{width:100%;max-height:100px;object-fit:cover;border-radius:var(--radius-sm);border:1.5px solid var(--border);}
.preview-logo-img{width:72px;height:72px;object-fit:cover;border-radius:50%;border:2px solid var(--border);}
/* Type cards */
.type-cards{display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:.5rem;}
.type-card{position:relative;border:2px solid var(--border);border-radius:var(--radius-sm);padding:1.25rem;cursor:pointer;transition:all var(--transition);background:var(--surface-2);}
.type-card:hover{border-color:var(--navy-light);background:#eff4ff;}
.type-card input[type="radio"]{position:absolute;inset:0;opacity:0;cursor:pointer;width:100%;height:100%;margin:0;}
.type-card.selected{border-color:var(--navy-mid);background:#eff4ff;box-shadow:0 0 0 3px rgba(15,59,122,.1);}
.type-card-icon{width:40px;height:40px;border-radius:var(--radius-sm);background:var(--navy);display:flex;align-items:center;justify-content:center;color:var(--amber);font-size:1.1rem;margin-bottom:.75rem;}
.type-card.selected .type-card-icon{background:var(--navy-mid);}
.type-card-title{font-size:.9rem;font-weight:600;color:var(--text);margin-bottom:.25rem;}
.type-card-desc{font-size:.77rem;color:var(--text-muted);line-height:1.45;}
/* Input with prefix/suffix */
.input-prefix-wrap{position:relative;}
.input-prefix-wrap .prefix{position:absolute;left:.85rem;top:50%;transform:translateY(-50%);font-size:.85rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.input-prefix-wrap input{padding-left:2.1rem;}
.input-suffix-wrap{position:relative;}
.input-suffix-wrap .suffix{position:absolute;right:.85rem;top:50%;transform:translateY(-50%);font-size:.82rem;color:var(--text-muted);pointer-events:none;}
.input-suffix-wrap input{padding-right:3.5rem;}
/* Dynamic rows */
.dynamic-rows{display:flex;flex-direction:column;gap:.75rem;}
.dynamic-row{display:grid;gap:.75rem;align-items:center;background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:.9rem 1rem;transition:border-color var(--transition);animation:rowIn .2s ease;}
@keyframes rowIn{from{opacity:0;transform:translateY(-6px)}to{opacity:1;transform:translateY(0)}}
.dynamic-row:focus-within{border-color:var(--border-focus);}
.dynamic-row.hl-row{grid-template-columns:1fr 1fr auto;}
.dynamic-row.uof-row{grid-template-columns:1fr 160px auto;}
.dynamic-row input{padding:.6rem .85rem;border:1.5px solid var(--border);border-radius:6px;font-family:'DM Sans',sans-serif;font-size:.88rem;color:var(--text);background:#fff;outline:none;width:100%;transition:border-color var(--transition);}
.dynamic-row input:focus{border-color:var(--border-focus);}
.amount-wrap{position:relative;}
.amount-wrap .currency-prefix{position:absolute;left:.7rem;top:50%;transform:translateY(-50%);font-size:.82rem;font-weight:600;color:var(--text-muted);pointer-events:none;}
.amount-wrap input{padding-left:2rem;}
.btn-remove-row{width:30px;height:30px;border-radius:50%;border:1.5px solid var(--error-bdr);background:var(--error-bg);color:var(--error);display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;font-size:.8rem;transition:all var(--transition);}
.btn-remove-row:hover{background:var(--error);color:#fff;}
.btn-add-row{display:inline-flex;align-items:center;gap:.5rem;margin-top:.75rem;padding:.55rem 1.1rem;border:1.5px dashed var(--navy-light);border-radius:99px;background:transparent;color:var(--navy-light);font-family:'DM Sans',sans-serif;font-size:.83rem;font-weight:600;cursor:pointer;transition:all var(--transition);}
.btn-add-row:hover{background:#eff4ff;border-style:solid;}
.row-col-label{font-size:.74rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:.07em;margin-bottom:.3rem;}
.uof-total{display:flex;justify-content:flex-end;align-items:center;gap:.5rem;margin-top:.6rem;padding-top:.6rem;border-top:1px solid var(--border);font-size:.85rem;color:var(--text-muted);}
.uof-total strong{color:var(--navy);font-size:.95rem;}
/* Callout */
.callout{background:var(--amber-light);border:1px solid var(--amber);border-radius:var(--radius-sm);padding:.85rem 1.1rem;font-size:.85rem;color:#78350f;display:flex;gap:.6rem;align-items:flex-start;margin-bottom:1.5rem;}
.callout i{margin-top:.1rem;flex-shrink:0;color:var(--amber-dark);}
.callout.info{background:#eff4ff;border-color:#c7d9f8;color:var(--navy-mid);}
.callout.info i{color:var(--navy-light);}
/* Review blocks */
.review-block{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);overflow:hidden;margin-bottom:1.25rem;}
.review-block-header{background:var(--navy);padding:.65rem 1.1rem;font-size:.78rem;font-weight:700;color:rgba(255,255,255,.7);text-transform:uppercase;letter-spacing:.08em;display:flex;align-items:center;gap:.5rem;}
.review-block-header i{color:var(--amber);}
.review-row{display:flex;align-items:baseline;justify-content:space-between;padding:.7rem 1.1rem;border-bottom:1px solid var(--border);gap:1rem;}
.review-row:last-child{border-bottom:none;}
.review-row-label{font-size:.82rem;color:var(--text-muted);flex-shrink:0;}
.review-row-value{font-size:.88rem;font-weight:600;color:var(--text);text-align:right;}
.review-row-value.highlight{color:var(--navy-mid);font-size:.95rem;}
/* Alert */
.alert{display:flex;align-items:flex-start;gap:.75rem;padding:.9rem 1.1rem;border-radius:var(--radius-sm);margin-bottom:1.5rem;font-size:.88rem;font-weight:500;border:1px solid transparent;}
.alert i{font-size:1rem;margin-top:.05rem;flex-shrink:0;}
.alert.error{background:var(--error-bg);color:var(--error);border-color:var(--error-bdr);}
.alert ul{margin:.3rem 0 0 1rem;}
.alert ul li{margin-bottom:.15rem;}
/* KYC doc field */
.doc-field{background:var(--surface-2);border:1.5px solid var(--border);border-radius:var(--radius-sm);padding:1.1rem;display:flex;align-items:flex-start;gap:.9rem;transition:border-color var(--transition);}
.doc-field:focus-within{border-color:var(--border-focus);}
.doc-field+.doc-field{margin-top:1rem;}
.doc-field-icon{width:40px;height:40px;background:#eff4ff;border-radius:var(--radius-sm);display:flex;align-items:center;justify-content:center;color:var(--navy-light);font-size:1rem;flex-shrink:0;}
.doc-field-body{flex:1;}
.doc-field-label{font-size:.87rem;font-weight:600;color:var(--text);margin-bottom:.15rem;}
.doc-field-hint{font-size:.76rem;color:var(--text-light);margin-bottom:.6rem;}
.doc-field input[type="file"]{font-size:.82rem;color:var(--text-muted);width:100%;}
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
.btn-back{background:transparent;color:var(--text-muted);padding-left:.5rem;border:none;}
.btn-back:hover{color:var(--text);}
.step-actions{display:flex;align-items:center;justify-content:space-between;margin-top:2.25rem;padding-top:1.5rem;border-top:1px solid var(--border);gap:1rem;flex-wrap:wrap;}
.step-actions-left{display:flex;gap:.75rem;align-items:center;}
.step-actions-right{display:flex;gap:.75rem;align-items:center;}
/* Responsive */
@media(max-width:900px){.wizard-sidebar{display:none;}.mobile-progress{display:block;}.wizard-main{padding:1.5rem;}.step-card{padding:1.75rem 1.5rem;}.form-grid{grid-template-columns:1fr;}.form-grid .span-2{grid-column:span 1;}.type-cards{grid-template-columns:1fr;}.dynamic-row.hl-row{grid-template-columns:1fr auto;}.dynamic-row.uof-row{grid-template-columns:1fr auto;}}
@media(max-width:540px){.wizard-main{padding:1rem;}.step-card{padding:1.25rem 1rem;}.step-actions{flex-direction:column-reverse;align-items:stretch;}.step-actions-left,.step-actions-right{justify-content:center;}.btn{justify-content:center;}}
</style>
</head>
<body>
<div class="wizard-shell">

<!-- ═══ SIDEBAR ═══ -->
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
        <div class="sidebar-footer-company"><i class="fa-solid fa-building" style="font-size:.7rem;margin-right:.3rem;"></i><?= htmlspecialchars($company['name']) ?></div>
    </div>
</aside>

<!-- ═══ MOBILE PROGRESS ═══ -->
<div class="mobile-progress">
    <div class="mob-prog-head">
        <span class="mob-prog-label">Step <?= $step ?>: <?= $steps[$step]['label'] ?></span>
        <span class="mob-prog-count"><?= $step ?> / <?= $totalSteps ?></span>
    </div>
    <div class="mob-prog-bar">
        <div class="mob-prog-fill" style="width:<?= round($step / $totalSteps * 100) ?>%"></div>
    </div>
</div>

<!-- ═══ MAIN ═══ -->
<main class="wizard-main">
<div class="step-card" id="stepCard">

<?php if (!empty($errors)): ?>
<div class="alert error">
    <i class="fa-solid fa-circle-exclamation"></i>
    <div><strong>Please fix the following:</strong>
    <ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
    </div>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" id="wizardForm">
<input type="hidden" name="csrf_token" value="<?= $csrf_token ?>">
<input type="hidden" name="step"       value="<?= $step ?>">
<input type="hidden" name="action"     value="next" id="actionInput">

<?php /* ════ STEP 1 — Basics ════════════════════════════════ */ ?>
<?php if ($step === 1): ?>
<div class="step-heading">
    <div class="step-number">Step 1 of 8</div>
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
<div class="type-cards">
    <label class="type-card <?= $data['campaign_type'] === 'revenue_share' ? 'selected' : '' ?>">
        <input type="radio" name="campaign_type" value="revenue_share" <?= $data['campaign_type'] === 'revenue_share' ? 'checked' : '' ?>>
        <div class="type-card-icon"><i class="fa-solid fa-chart-line"></i></div>
        <div class="type-card-title">Revenue Share</div>
        <div class="type-card-desc">Investors receive a percentage of monthly revenue for a fixed term. Ideal for businesses with consistent income.</div>
    </label>
    <label class="type-card <?= $data['campaign_type'] === 'cooperative_membership' ? 'selected' : '' ?>">
        <input type="radio" name="campaign_type" value="cooperative_membership" <?= $data['campaign_type'] === 'cooperative_membership' ? 'checked' : '' ?>>
        <div class="type-card-icon"><i class="fa-solid fa-people-roof"></i></div>
        <div class="type-card-title">Cooperative Membership</div>
        <div class="type-card-desc">Investors purchase membership units in a cooperative. Best for community-owned or worker cooperative structures.</div>
    </label>
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

<?php /* ════ STEP 2 — SPV Identity ════════════════════════ */ ?>
<?php elseif ($step === 2): ?>
<div class="step-heading">
    <div class="step-number">Step 2 of 8</div>
    <h2>SPV Identity</h2>
    <p>The SPV is a separate legal entity from the parent company. Give it its own registered details, contact information, and branding.</p>
</div>
<div class="callout info">
    <i class="fa-solid fa-circle-info"></i>
    <div>The SPV's <strong>registered name</strong> is its official CIPC name (e.g. "Soweto Bread Co SPV 001 (RF) (Pty) Ltd"). This may differ from the campaign title shown to investors.</div>
</div>
<div class="form-grid">
    <div class="field span-2">
        <label><i class="fa-solid fa-building"></i> SPV Registered Name <span class="req">*</span></label>
        <input type="text" name="spv_registered_name" maxlength="255" value="<?= htmlspecialchars($data['spv_registered_name']) ?>" placeholder="e.g. Soweto Bread Co SPV 001 (RF) (Pty) Ltd" required>
        <span class="hint">The exact name as it appears on the CIPC registration certificate.</span>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-hashtag"></i> CIPC Registration Number</label>
        <input type="text" name="spv_registration_number" maxlength="100" value="<?= htmlspecialchars($data['spv_registration_number']) ?>" placeholder="e.g. 2025/123456/07">
    </div>
    <div class="field">
        <label><i class="fa-solid fa-envelope"></i> SPV Email</label>
        <input type="email" name="spv_email" value="<?= htmlspecialchars($data['spv_email']) ?>" placeholder="spv@yourcompany.co.za">
    </div>
    <div class="field">
        <label><i class="fa-solid fa-phone"></i> SPV Phone</label>
        <input type="tel" name="spv_phone" value="<?= htmlspecialchars($data['spv_phone']) ?>" placeholder="+27 10 000 0000">
    </div>
    <div class="field">
        <label><i class="fa-solid fa-globe"></i> SPV Website</label>
        <input type="url" name="spv_website" value="<?= htmlspecialchars($data['spv_website']) ?>" placeholder="https://spv.yourcompany.co.za">
        <span class="hint">Include https://</span>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-align-left"></i> About this SPV</label>
        <textarea name="spv_description" rows="4" placeholder="Describe the purpose and scope of this specific SPV entity — what it was formed to do and what it owns or operates."><?= htmlspecialchars($data['spv_description']) ?></textarea>
        <span class="hint">This is distinct from the parent company's description. Focus on this SPV's specific mandate.</span>
    </div>
</div>
<div class="form-section-label"><i class="fa-solid fa-palette" style="color:var(--navy-light)"></i> SPV Branding</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-circle-user"></i> SPV Logo</label>
        <div class="file-upload-zone">
            <input type="file" name="spv_logo" accept="image/*" onchange="previewImage(this,'logoPreview','logoImg')">
            <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div>
            <div class="file-upload-sub">PNG, JPG, WebP · max 5MB · square works best<br>Falls back to parent company logo if not set</div>
        </div>
        <div class="preview-wrap" id="logoPreview" style="display:none;text-align:center;">
            <img id="logoImg" class="preview-logo-img" src="" alt="Logo preview">
        </div>
        <?php if ($data['spv_logo']): ?>
        <a href="<?= htmlspecialchars($data['spv_logo']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-image"></i> Current SPV logo — view</a>
        <?php endif; ?>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-panorama"></i> SPV Banner</label>
        <div class="file-upload-zone">
            <input type="file" name="spv_banner" accept="image/*" onchange="previewImage(this,'bannerPreview','bannerImg')">
            <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
            <div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div>
            <div class="file-upload-sub">PNG, JPG, WebP · max 5MB · landscape ratio<br>Falls back to parent company banner if not set</div>
        </div>
        <div class="preview-wrap" id="bannerPreview" style="display:none;">
            <img id="bannerImg" class="preview-img" src="" alt="Banner preview">
        </div>
        <?php if ($data['spv_banner']): ?>
        <a href="<?= htmlspecialchars($data['spv_banner']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-panorama"></i> Current SPV banner — view</a>
        <?php endif; ?>
    </div>
</div>

<?php /* ════ STEP 3 — SPV Address ══════════════════════════ */ ?>
<?php elseif ($step === 3): ?>
<div class="step-heading">
    <div class="step-number">Step 3 of 8</div>
    <h2>SPV Registered Address</h2>
    <p>The SPV's registered address for legal and compliance purposes. This is the address on CIPC records — it can be the same as the parent company.</p>
</div>
<label class="toggle-field">
    <input type="checkbox" name="spv_address_same_as_company" id="sameAsParentToggle"
           <?= $data['spv_address_same_as_company'] ? 'checked' : '' ?>
           onchange="toggleAddressSection(this.checked)">
    <div class="toggle-field-body">
        <div class="toggle-field-label">Same as parent company address</div>
        <div class="toggle-field-desc">
            <?php
            $parentAddrParts = array_filter([$parentAddress['suburb'] ?? '', $parentAddress['city'] ?? '', $parentAddress['province'] ?? '']);
            echo $parentAddrParts ? htmlspecialchars(implode(', ', $parentAddrParts)) : 'No parent address on file — complete the company profile first.';
            ?>
        </div>
    </div>
</label>
<div id="ownAddressSection" class="<?= $data['spv_address_same_as_company'] ? 'hidden' : '' ?>" style="margin-top:1.5rem;">
    <div class="form-grid">
        <div class="field">
            <label><i class="fa-solid fa-map"></i> Province <span class="req">*</span></label>
            <select name="spv_province" id="spvProvince">
                <option value="">— Select province —</option>
                <?php foreach ($validProvinces as $prov): ?>
                <option value="<?= htmlspecialchars($prov) ?>" <?= $data['spv_province'] === $prov ? 'selected' : '' ?>><?= htmlspecialchars($prov) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label><i class="fa-solid fa-layer-group"></i> Area Type <span class="req">*</span></label>
            <select name="spv_area" id="spvArea">
                <option value="">— Select area type —</option>
                <option value="urban"    <?= $data['spv_area'] === 'urban'    ? 'selected' : '' ?>>Urban</option>
                <option value="township" <?= $data['spv_area'] === 'township' ? 'selected' : '' ?>>Township</option>
                <option value="rural"    <?= $data['spv_area'] === 'rural'    ? 'selected' : '' ?>>Rural</option>
            </select>
        </div>
        <div class="field">
            <label><i class="fa-solid fa-city"></i> Municipality</label>
            <select name="spv_municipality" id="spvMunicipality" disabled>
                <option value="">— Select municipality —</option>
            </select>
            <span class="hint">Select a province first.</span>
        </div>
        <div class="field">
            <label><i class="fa-solid fa-building"></i> City / Town</label>
            <select name="spv_city" id="spvCity" disabled>
                <option value="">— Select city —</option>
            </select>
        </div>
        <div class="field span-2">
            <label><i class="fa-solid fa-location-dot"></i> Suburb</label>
            <select name="spv_suburb" id="spvSuburb" disabled>
                <option value="">— Select suburb —</option>
            </select>
        </div>
    </div>
</div>

<?php /* ════ STEP 4 — Targets ═════════════════════════════ */ ?>
<?php elseif ($step === 4): ?>
<div class="step-heading">
    <div class="step-number">Step 4 of 8</div>
    <h2>Funding Targets</h2>
    <p>Define the raise amounts and contributor guardrails for this SPV.</p>
</div>
<div class="callout">
    <i class="fa-solid fa-scale-balanced"></i>
    <div><strong>Legal note:</strong> The contributor cap is capped at 50 to comply with South African private placement regulations. You may set a lower cap.</div>
</div>
<div class="form-section-label"><i class="fa-solid fa-sack-dollar" style="color:var(--navy-light)"></i> Raise Amounts</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-bullseye"></i> Raise Target <span class="req">*</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="raise_target" min="1" step="100" value="<?= htmlspecialchars($data['raise_target']) ?>" placeholder="500000" required></div>
        <span class="hint">The amount the SPV is aiming to raise.</span>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-circle-minus"></i> Minimum Raise <span class="req">*</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="raise_minimum" min="1" step="100" value="<?= htmlspecialchars($data['raise_minimum']) ?>" placeholder="250000" required></div>
        <span class="hint">If this floor is not reached, all contributions are refunded.</span>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-circle-plus"></i> Hard Cap <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="raise_maximum" min="1" step="100" value="<?= htmlspecialchars($data['raise_maximum']) ?>" placeholder="Leave blank for no hard cap"></div>
        <span class="hint">SPV closes early if this amount is reached. Must be ≥ raise target.</span>
    </div>
</div>
<div class="form-section-label"><i class="fa-solid fa-users" style="color:var(--navy-light)"></i> Contributor Limits</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-arrow-down-1-9"></i> Minimum Contribution <span class="req">*</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="min_contribution" min="100" step="100" value="<?= htmlspecialchars($data['min_contribution']) ?>" placeholder="500" required></div>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-arrow-up-9-1"></i> Maximum Contribution <span style="font-weight:400;color:var(--text-light);font-size:.8rem;">(optional)</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="max_contribution" min="100" step="100" value="<?= htmlspecialchars($data['max_contribution']) ?>" placeholder="No limit"></div>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-user-group"></i> Contributor Cap <span class="req">*</span></label>
        <div class="input-suffix-wrap">
        <input type="number" name="max_contributors" min="1" max="50" value="<?= htmlspecialchars($data['max_contributors']) ?>" required>
        <span class="suffix">max 50</span></div>
        <span class="hint">Maximum number of investors. Cannot exceed 50.</span>
    </div>
</div>

<?php /* ════ STEP 5 — Deal Terms ══════════════════════════ */ ?>
<?php elseif ($step === 5): ?>
<div class="step-heading">
    <div class="step-number">Step 5 of 8</div>
    <h2>Deal Terms</h2>
    <?php if ($data['campaign_type'] === 'revenue_share'): ?>
    <p>Define the revenue share percentage and how many months investors receive it.</p>
    <?php else: ?>
    <p>Define the cooperative membership unit structure.</p>
    <?php endif; ?>
</div>

<?php if ($data['campaign_type'] === 'revenue_share'): ?>
<div class="callout info">
    <i class="fa-solid fa-circle-info"></i>
    <div>Each investor receives their <strong>pro-rata share</strong> of the agreed percentage, based on their contribution relative to the total raised.</div>
</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-percent"></i> Revenue Share <span class="req">*</span></label>
        <div class="input-suffix-wrap">
        <input type="number" name="rs_percentage" min="0.01" max="100" step="0.01" value="<?= htmlspecialchars($data['rs_percentage']) ?>" placeholder="5" required id="rsPct">
        <span class="suffix">% / mo</span></div>
        <span class="hint">Percentage of monthly revenue shared across all investors combined.</span>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-hourglass-half"></i> Duration <span class="req">*</span></label>
        <div class="input-suffix-wrap">
        <input type="number" name="rs_duration" min="1" max="120" step="1" value="<?= htmlspecialchars($data['rs_duration']) ?>" placeholder="36" required id="rsDur">
        <span class="suffix">months</span></div>
        <span class="hint">How many months investors receive their share.</span>
    </div>
</div>
<div class="review-block" style="margin-top:1.5rem;">
    <div class="review-block-header"><i class="fa-solid fa-calculator"></i> Illustrative Summary</div>
    <div class="review-row"><span class="review-row-label">If you raise your full target of</span><span class="review-row-value"><?= fmtCurrency($data['raise_target'] ?: 0) ?></span></div>
    <div class="review-row"><span class="review-row-label">Investors share <span id="previewPct">—</span>% monthly for <span id="previewDur">—</span> months</span><span class="review-row-value highlight" id="previewTerms">Fill in the fields above</span></div>
</div>

<?php elseif ($data['campaign_type'] === 'cooperative_membership'): ?>
<div class="callout info">
    <i class="fa-solid fa-circle-info"></i>
    <div>Investors purchase membership units at a fixed price. Each unit represents an equal share of cooperative membership.</div>
</div>
<div class="form-grid">
    <div class="field span-2">
        <label><i class="fa-solid fa-tag"></i> Unit Name <span class="req">*</span></label>
        <input type="text" name="co_unit_name" maxlength="100" value="<?= htmlspecialchars($data['co_unit_name']) ?>" placeholder="e.g. Community Membership Unit" required>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-coins"></i> Price Per Unit <span class="req">*</span></label>
        <div class="input-prefix-wrap"><span class="prefix">R</span>
        <input type="number" name="co_unit_price" min="1" step="1" value="<?= htmlspecialchars($data['co_unit_price']) ?>" placeholder="1000" required id="coPrice"></div>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-layer-group"></i> Total Units Available <span class="req">*</span></label>
        <input type="number" name="co_units_total" min="1" max="50" step="1" value="<?= htmlspecialchars($data['co_units_total']) ?>" placeholder="50" required id="coTotal">
        <span class="hint">Max <?= (int)$data['max_contributors'] ?> (matches contributor cap).</span>
    </div>
</div>
<div class="review-block" style="margin-top:1.5rem;">
    <div class="review-block-header"><i class="fa-solid fa-calculator"></i> Implied Totals</div>
    <div class="review-row"><span class="review-row-label">Price per unit</span><span class="review-row-value" id="coPriceDisplay">—</span></div>
    <div class="review-row"><span class="review-row-label">Units available</span><span class="review-row-value" id="coUnitsDisplay">—</span></div>
    <div class="review-row"><span class="review-row-label">Implied raise (all units sold)</span><span class="review-row-value highlight" id="coTotalDisplay">—</span></div>
</div>
<?php endif; ?>

<?php /* ════ STEP 6 — Pitch & Funds ═══════════════════════ */ ?>
<?php elseif ($step === 6): ?>
<div class="step-heading">
    <div class="step-number">Step 6 of 8</div>
    <h2>Investment Pitch &amp; Use of Funds</h2>
    <p>Tell investors why this SPV is compelling, how the money will be deployed, and who is running it. All fields are optional but strongly recommended.</p>
</div>
<div class="form-section-label"><i class="fa-solid fa-briefcase" style="color:var(--navy-light)"></i> Investment Case</div>
<div class="form-grid">
    <div class="field span-2">
        <label><i class="fa-solid fa-lightbulb"></i> Investment Thesis</label>
        <textarea name="investment_thesis" rows="4" maxlength="3000" placeholder="Why should an investor back this specific SPV? What is the core value proposition and opportunity for this raise?"><?= htmlspecialchars($data['investment_thesis']) ?></textarea>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-shield-halved"></i> Risk Factors</label>
        <textarea name="risk_factors" rows="3" maxlength="2000" placeholder="List the key risks relevant to this SPV and how you are mitigating them. Honest disclosure builds trust."><?= htmlspecialchars($data['risk_factors']) ?></textarea>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-door-open"></i> Exit / Return Strategy</label>
        <textarea name="exit_strategy" rows="3" maxlength="2000" placeholder="How and when do investors realise their returns from this SPV?"><?= htmlspecialchars($data['exit_strategy']) ?></textarea>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-rocket"></i> SPV Traction</label>
        <textarea name="spv_traction" rows="3" maxlength="2000" placeholder="What has this SPV or the underlying business already achieved that is relevant to this raise?"><?= htmlspecialchars($data['spv_traction']) ?></textarea>
    </div>
    <div class="field span-2">
        <label><i class="fa-solid fa-people-group"></i> SPV Team</label>
        <textarea name="spv_team_overview" rows="3" maxlength="2000" placeholder="Who is directing and managing this specific SPV? Include names, roles, and relevant experience."><?= htmlspecialchars($data['spv_team_overview']) ?></textarea>
        <span class="hint">This can be the same as the parent company team or a subset — be specific to this SPV.</span>
    </div>
</div>
<div class="form-section-label"><i class="fa-solid fa-sack-dollar" style="color:var(--navy-light)"></i> Use of Funds</div>
<p style="font-size:.87rem;color:var(--text-muted);margin-bottom:1rem;line-height:1.55;">Break down exactly how the capital raised by this SPV will be deployed. Investors need to see a specific, credible plan.</p>
<div class="dynamic-rows" id="uofRows">
    <?php if (empty($data['use_of_funds'])): ?>
    <div class="dynamic-row uof-row">
        <div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" placeholder="e.g. Equipment purchase"></div>
        <div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" placeholder="0" oninput="updateUofTotal()"></div></div>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php else: foreach ($data['use_of_funds'] as $uofItem): ?>
    <div class="dynamic-row uof-row">
        <div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" value="<?= htmlspecialchars($uofItem['label'] ?? '') ?>" placeholder="e.g. Equipment purchase"></div>
        <div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" value="<?= htmlspecialchars($uofItem['amount'] ?? '') ?>" placeholder="0" oninput="updateUofTotal()"></div></div>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php endforeach; endif; ?>
</div>
<div class="uof-total">Total: <strong id="uofTotalDisplay">R 0</strong></div>
<button type="button" class="btn-add-row" onclick="addUofRow()"><i class="fa-solid fa-plus"></i> Add line item</button>

<div class="form-section-label" style="margin-top:1.75rem;"><i class="fa-solid fa-photo-film" style="color:var(--navy-light)"></i> Pitch Assets</div>
<div class="form-grid">
    <div class="field">
        <label><i class="fa-solid fa-file-pdf"></i> Pitch Deck (upload)</label>
        <div class="file-upload-zone">
            <input type="file" name="pitch_deck" accept=".pdf" onchange="showFileName(this,'deckLabel')">
            <div class="file-upload-icon"><i class="fa-solid fa-file-pdf"></i></div>
            <div class="file-upload-label" id="deckLabel"><strong>Click to upload</strong> PDF pitch deck</div>
            <div class="file-upload-sub">PDF only · max 5MB</div>
        </div>
        <?php if ($data['pitch_deck_url']): ?>
        <a href="<?= htmlspecialchars($data['pitch_deck_url']) ?>" target="_blank" class="existing-file-link"><i class="fa-solid fa-file-pdf"></i> Uploaded deck — view</a>
        <?php endif; ?>
    </div>
    <div class="field">
        <label><i class="fa-solid fa-link"></i> Pitch Deck URL</label>
        <input type="url" name="pitch_deck_url_text" value="<?= htmlspecialchars(!empty($data['pitch_deck_url']) && !str_starts_with($data['pitch_deck_url'], '/') ? $data['pitch_deck_url'] : '') ?>" placeholder="https://drive.google.com/…">
        <span class="hint">Link to a Google Drive / Dropbox PDF. Overrides upload above if both provided.</span>
        <label style="margin-top:.75rem;"><i class="fa-brands fa-youtube"></i> Pitch Video URL</label>
        <input type="url" name="pitch_video_url" value="<?= htmlspecialchars($data['pitch_video_url']) ?>" placeholder="https://youtu.be/…">
        <span class="hint">YouTube or Vimeo link.</span>
    </div>
</div>

<?php /* ════ STEP 7 — Highlights ═════════════════════════ */ ?>
<?php elseif ($step === 7): ?>
<div class="step-heading">
    <div class="step-number">Step 7 of 8</div>
    <h2>SPV Key Highlights</h2>
    <p>These headline stats appear on the SPV listing card — the first numbers a potential investor sees. Keep them specific, accurate, and compelling. Add up to 8.</p>
</div>
<div class="callout info">
    <i class="fa-solid fa-circle-info"></i>
    <div>These highlights are <strong>specific to this SPV</strong> and will override the parent company's highlights on the campaign page. Use them to show stats directly relevant to this raise.</div>
</div>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:.5rem;margin-bottom:1.25rem;">
    <div class="row-col-label" style="padding-left:.2rem;">Label</div>
    <div class="row-col-label" style="padding-left:.2rem;">Value</div>
</div>
<div class="dynamic-rows" id="hlRows">
    <?php foreach ($data['highlights'] as $hl): ?>
    <div class="dynamic-row hl-row">
        <div><input type="text" name="hl_label[]" value="<?= htmlspecialchars($hl['label'] ?? '') ?>" placeholder="e.g. Total Raise"></div>
        <div><input type="text" name="hl_value[]" value="<?= htmlspecialchars($hl['value'] ?? '') ?>" placeholder="e.g. R 500 000"></div>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>
    </div>
    <?php endforeach; ?>
</div>
<button type="button" class="btn-add-row" id="addHlBtn" onclick="addHlRow()"><i class="fa-solid fa-plus"></i> Add highlight</button>
<p style="font-size:.77rem;color:var(--text-light);margin-top:1rem;"><i class="fa-solid fa-circle-info"></i>&nbsp; Tip: concrete numbers over vague claims — "R500 000 raise" beats "Large raise".</p>

<?php /* ════ STEP 8 — Review & Submit ══════════════════════ */ ?>
<?php elseif ($step === 8): ?>
<div class="step-heading">
    <div class="step-number">Step 8 of 8</div>
    <h2>Review &amp; Submit</h2>
    <p>Review everything before submitting. Once submitted, the SPV campaign moves to <strong>under review</strong> and cannot be edited until assessed by the Old Union team.</p>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-pen"></i> Campaign</div>
    <div class="review-row"><span class="review-row-label">Title</span><span class="review-row-value"><?= htmlspecialchars($data['title']) ?></span></div>
    <?php if ($data['tagline']): ?><div class="review-row"><span class="review-row-label">Tagline</span><span class="review-row-value"><?= htmlspecialchars($data['tagline']) ?></span></div><?php endif; ?>
    <div class="review-row"><span class="review-row-label">Type</span><span class="review-row-value"><?= $data['campaign_type'] === 'revenue_share' ? 'Revenue Share' : 'Cooperative Membership' ?></span></div>
    <div class="review-row"><span class="review-row-label">Opens</span><span class="review-row-value"><?= fmtDate2($data['opens_at']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Closes</span><span class="review-row-value"><?= fmtDate2($data['closes_at']) ?></span></div>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-building"></i> SPV Identity</div>
    <div class="review-row"><span class="review-row-label">Registered Name</span><span class="review-row-value highlight"><?= htmlspecialchars($data['spv_registered_name'] ?: '—') ?></span></div>
    <div class="review-row"><span class="review-row-label">CIPC Number</span><span class="review-row-value"><?= htmlspecialchars($data['spv_registration_number'] ?: '—') ?></span></div>
    <div class="review-row"><span class="review-row-label">Address</span><span class="review-row-value">
        <?php if ($data['spv_address_same_as_company']): ?>
            Same as <?= htmlspecialchars($company['name']) ?>
        <?php else: ?>
            <?= htmlspecialchars(implode(', ', array_filter([$data['spv_suburb'], $data['spv_city'], $data['spv_province']])) ?: '—') ?>
        <?php endif; ?>
    </span></div>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-bullseye"></i> Funding Targets</div>
    <div class="review-row"><span class="review-row-label">Raise Target</span><span class="review-row-value highlight"><?= fmtCurrency($data['raise_target']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Minimum Raise</span><span class="review-row-value"><?= fmtCurrency($data['raise_minimum']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Hard Cap</span><span class="review-row-value"><?= $data['raise_maximum'] ? fmtCurrency($data['raise_maximum']) : 'No hard cap' ?></span></div>
    <div class="review-row"><span class="review-row-label">Min. Contribution</span><span class="review-row-value"><?= fmtCurrency($data['min_contribution']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Contributor Cap</span><span class="review-row-value"><?= (int)$data['max_contributors'] ?> people</span></div>
</div>

<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-file-contract"></i> Deal Terms</div>
    <?php if ($data['campaign_type'] === 'revenue_share'): ?>
    <div class="review-row"><span class="review-row-label">Revenue Share</span><span class="review-row-value highlight"><?= htmlspecialchars($data['rs_percentage']) ?>% per month</span></div>
    <div class="review-row"><span class="review-row-label">Duration</span><span class="review-row-value"><?= htmlspecialchars($data['rs_duration']) ?> months</span></div>
    <?php else: ?>
    <div class="review-row"><span class="review-row-label">Unit Name</span><span class="review-row-value"><?= htmlspecialchars($data['co_unit_name']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Price / Unit</span><span class="review-row-value highlight"><?= fmtCurrency($data['co_unit_price']) ?></span></div>
    <div class="review-row"><span class="review-row-label">Units Available</span><span class="review-row-value"><?= (int)$data['co_units_total'] ?></span></div>
    <?php endif; ?>
    <div class="review-row"><span class="review-row-label">Governing Law</span><span class="review-row-value">Republic of South Africa</span></div>
</div>

<?php if (!empty($data['use_of_funds'])): ?>
<div class="review-block">
    <div class="review-block-header"><i class="fa-solid fa-sack-dollar"></i> Use of Funds</div>
    <?php foreach ($data['use_of_funds'] as $uof): ?>
    <div class="review-row">
        <span class="review-row-label"><?= htmlspecialchars($uof['label']) ?></span>
        <span class="review-row-value"><?= $uof['amount'] ? fmtCurrency($uof['amount']) : '—' ?></span>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="callout" style="margin-top:1rem;">
    <i class="fa-solid fa-triangle-exclamation"></i>
    <div>By submitting you confirm all information is accurate and this SPV complies with Old Union platform rules. The Old Union team will review your submission within <strong>2–4 business days</strong>. You will not be able to edit the campaign while it is under review.</div>
</div>

<?php endif; ?>

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
function setAction(v) { document.getElementById('actionInput').value = v; }

function previewImage(input, wrapId, imgId) {
    if (input.files && input.files[0]) {
        const r = new FileReader();
        r.onload = e => { document.getElementById(imgId).src = e.target.result; document.getElementById(wrapId).style.display = 'block'; };
        r.readAsDataURL(input.files[0]);
    }
}
function showFileName(input, labelId) {
    if (input.files && input.files[0]) document.getElementById(labelId).innerHTML = '<strong>' + input.files[0].name + '</strong> selected';
}

// Type card selection
document.querySelectorAll('.type-card input[type="radio"]').forEach(r => {
    r.addEventListener('change', function() {
        document.querySelectorAll('.type-card').forEach(c => c.classList.remove('selected'));
        this.closest('.type-card').classList.add('selected');
    });
});

// Address toggle
function toggleAddressSection(sameAsParent) {
    const section = document.getElementById('ownAddressSection');
    if (section) section.classList.toggle('hidden', sameAsParent);
}

// Revenue share live preview
(function() {
    const pctEl = document.getElementById('rsPct');
    const durEl = document.getElementById('rsDur');
    if (!pctEl || !durEl) return;
    function update() {
        const pct = parseFloat(pctEl.value) || 0;
        const dur = parseInt(durEl.value) || 0;
        document.getElementById('previewPct').textContent = pct || '—';
        document.getElementById('previewDur').textContent = dur || '—';
        document.getElementById('previewTerms').textContent = (pct && dur)
            ? pct + '% of monthly revenue for ' + dur + ' months'
            : 'Fill in the fields above';
    }
    pctEl.addEventListener('input', update); durEl.addEventListener('input', update); update();
})();

// Co-op live preview
(function() {
    const priceEl = document.getElementById('coPrice');
    const totalEl = document.getElementById('coTotal');
    if (!priceEl || !totalEl) return;
    const fmt = n => 'R\u00a0' + n.toLocaleString('en-ZA', {minimumFractionDigits:2});
    function update() {
        const price = parseFloat(priceEl.value) || 0;
        const total = parseInt(totalEl.value) || 0;
        document.getElementById('coPriceDisplay').textContent = price ? fmt(price) : '—';
        document.getElementById('coUnitsDisplay').textContent = total || '—';
        document.getElementById('coTotalDisplay').textContent = (price && total) ? fmt(price * total) : '—';
    }
    priceEl.addEventListener('input', update); totalEl.addEventListener('input', update); update();
})();

// Dynamic rows
function removeRow(btn) { btn.closest('.dynamic-row').remove(); updateUofTotal(); }

function addUofRow() {
    const c = document.getElementById('uofRows');
    const row = document.createElement('div');
    row.className = 'dynamic-row uof-row';
    row.innerHTML = '<div><div class="row-col-label">Item / Category</div><input type="text" name="uof_label[]" placeholder="e.g. Working capital"></div><div><div class="row-col-label">Amount (R)</div><div class="amount-wrap"><span class="currency-prefix">R</span><input type="number" name="uof_amount[]" min="0" step="100" placeholder="0" oninput="updateUofTotal()"></div></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>';
    c.appendChild(row);
    row.querySelector('input[type="text"]').focus();
}

function updateUofTotal() {
    const inputs = document.querySelectorAll('#uofRows input[name="uof_amount[]"]');
    let total = 0;
    inputs.forEach(i => { total += parseFloat(i.value) || 0; });
    const el = document.getElementById('uofTotalDisplay');
    if (el) el.textContent = 'R\u00a0' + total.toLocaleString('en-ZA', {minimumFractionDigits:0});
}

const MAX_HL = 8;
function addHlRow() {
    const c = document.getElementById('hlRows');
    if (!c) return;
    if (c.querySelectorAll('.dynamic-row').length >= MAX_HL) { checkHlMax(); return; }
    const row = document.createElement('div');
    row.className = 'dynamic-row hl-row';
    row.innerHTML = '<div><input type="text" name="hl_label[]" placeholder="e.g. Monthly Revenue"></div><div><input type="text" name="hl_value[]" placeholder="e.g. R 45 000"></div><button type="button" class="btn-remove-row" onclick="removeRow(this)"><i class="fa-solid fa-xmark"></i></button>';
    c.appendChild(row);
    row.querySelector('input').focus();
    checkHlMax();
}
function checkHlMax() {
    const btn = document.getElementById('addHlBtn');
    const c = document.getElementById('hlRows');
    if (!btn || !c) return;
    const count = c.querySelectorAll('.dynamic-row').length;
    btn.disabled = count >= MAX_HL;
    btn.innerHTML = count >= MAX_HL
        ? '<i class="fa-solid fa-lock"></i> Maximum 8 highlights reached'
        : '<i class="fa-solid fa-plus"></i> Add highlight';
}

// SPV address location cascade (step 3)
(function() {
    const LOC = window.SA_LOCATIONS;
    if (!LOC) return;
    const selProv = document.getElementById('spvProvince');
    const selMuni = document.getElementById('spvMunicipality');
    const selCity = document.getElementById('spvCity');
    const selSub  = document.getElementById('spvSuburb');
    if (!selProv) return;

    const INIT = {
        municipality: <?= json_encode($data['spv_municipality'] ?? '') ?>,
        city:         <?= json_encode($data['spv_city']         ?? '') ?>,
        suburb:       <?= json_encode($data['spv_suburb']       ?? '') ?>,
    };

    function fill(sel, items, placeholder, selected) {
        sel.innerHTML = '<option value="">' + placeholder + '</option>';
        (items || []).forEach(item => {
            const o = document.createElement('option');
            o.value = item; o.textContent = item;
            if (item === selected) o.selected = true;
            sel.appendChild(o);
        });
        sel.disabled = !items || items.length === 0;
    }
    function clear(sel, placeholder) { sel.innerHTML = '<option value="">' + placeholder + '</option>'; sel.disabled = true; }

    function onProv() { fill(selMuni, LOC.municipalities[selProv.value] || [], '— Select municipality —', INIT.municipality); clear(selCity, '— Select city —'); clear(selSub, '— Select suburb —'); if (LOC.municipalities[selProv.value]?.length && INIT.municipality) onMuni(); }
    function onMuni() { fill(selCity, LOC.cities[selMuni.value] || [], '— Select city —', INIT.city); clear(selSub, '— Select suburb —'); if (LOC.cities[selMuni.value]?.length && INIT.city) onCity(); }
    function onCity() { fill(selSub, LOC.suburbs[selCity.value] || [], '— Select suburb —', INIT.suburb); }

    selProv.addEventListener('change', () => { INIT.municipality = INIT.city = INIT.suburb = ''; onProv(); });
    selMuni.addEventListener('change', () => { INIT.city = INIT.suburb = ''; onMuni(); });
    selCity.addEventListener('change', () => { INIT.suburb = ''; onCity(); });

    if (selProv.value) onProv();
})();

// Submit confirmation
const submitBtn = document.getElementById('submitBtn');
if (submitBtn) {
    submitBtn.addEventListener('click', function(e) {
        if (!confirm('Submit this SPV campaign for review?\n\nYou will not be able to edit it while it is under review (2–4 business days).')) e.preventDefault();
    });
}

// Slide animation direction
const card = document.getElementById('stepCard');
<?php if (!empty($_POST['action']) && $_POST['action'] === 'back'): ?>
card.classList.add('going-back');
<?php endif; ?>

document.addEventListener('DOMContentLoaded', () => { updateUofTotal(); checkHlMax(); });
</script>
</body>
</html>
