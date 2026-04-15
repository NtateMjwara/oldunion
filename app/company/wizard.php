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

$uuid    = $_GET['uuid'] ?? '';
$company = getCompanyByUuid($uuid);
if (!$company) { die('Company not found.'); }

requireCompanyRole($company['id'], 'admin');
if ($company['status'] !== 'draft' && $company['status'] !== 'pending_verification') {
    die('This company cannot be edited in its current state.');
}

$pdo        = Database::getInstance();
$csrf_token = generateCSRFToken();
$companyId  = $company['id'];
$sessKey    = 'wizard_company_' . $companyId;

/* ── KYC row ─────────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM company_kyc WHERE company_id = ?");
$stmt->execute([$companyId]);
$kyc = $stmt->fetch();

/* ── Company filter row ──────────────────── */
$stmt = $pdo->prepare("SELECT * FROM company_filter WHERE company_id = ?");
$stmt->execute([$companyId]);
$cfRow = $stmt->fetch();

/* ── Pitch row ───────────────────────────── */
$stmt = $pdo->prepare("SELECT * FROM company_pitch WHERE company_id = ?");
$stmt->execute([$companyId]);
$pitchRow = $stmt->fetch();

/* ── Existing highlights ─────────────────── */
$stmt = $pdo->prepare("SELECT label, value FROM pitch_highlights WHERE company_id = ? ORDER BY sort_order ASC");
$stmt->execute([$companyId]);
$existingHighlights = $stmt->fetchAll();

/* ── Seed session from DB on first visit ─── */
if (!isset($_SESSION[$sessKey])) {
    $_SESSION[$sessKey] = [
        // Step 1 — Basic Info
        'name'                  => $company['name']                ?? '',
        'type'                  => $company['type']                ?? 'startup',
        'industry'              => $company['industry']            ?? '',
        'stage'                 => $company['stage']               ?? '',
        'founded_year'          => $company['founded_year']        ?? '',
        'employee_count'        => $company['employee_count']      ?? '',
        'description'           => $company['description']         ?? '',
        'registration_number'   => $company['registration_number'] ?? '',
        // Step 2 — Contact
        'email'                 => $company['email']               ?? '',
        'phone'                 => $company['phone']               ?? '',
        'website'               => $company['website']             ?? '',
        // Step 3 — Address
        'province'              => $cfRow['province']              ?? '',
        'municipality'          => $cfRow['municipality']          ?? '',
        'city'                  => $cfRow['city']                  ?? '',
        'suburb'                => $cfRow['suburb']                ?? '',
        'area'                  => $cfRow['area']                  ?? '',
        // Step 4 — Pitch (no use_of_funds — that lives on each SPV campaign)
        'pitch_problem'         => $pitchRow['problem_statement']      ?? '',
        'pitch_solution'        => $pitchRow['solution']               ?? '',
        'pitch_business_model'  => $pitchRow['business_model']         ?? '',
        'pitch_traction'        => $pitchRow['traction']               ?? '',
        'pitch_target_market'   => $pitchRow['target_market']          ?? '',
        'pitch_competitive'     => $pitchRow['competitive_landscape']  ?? '',
        'pitch_team'            => $pitchRow['team_overview']          ?? '',
        'pitch_deck_url'        => $pitchRow['pitch_deck_url']         ?? '',
        'pitch_video_url'       => $pitchRow['pitch_video_url']        ?? '',
        // Step 5 — Highlights
        'highlights'            => !empty($existingHighlights)
                                    ? $existingHighlights
                                    : [
                                        ['label' => 'Monthly Revenue',  'value' => ''],
                                        ['label' => 'Years Operating',  'value' => ''],
                                        ['label' => 'Team Size',        'value' => ''],
                                      ],
        // Step 6 — Branding
        'logo'                  => $company['logo']   ?? '',
        'banner'                => $company['banner'] ?? '',
        // Step 7 — Documents
        'registration_document' => $kyc['registration_document']       ?? '',
        'proof_of_address'      => $kyc['proof_of_address']            ?? '',
        'director_id_document'  => $kyc['director_id_document']        ?? '',
        'tax_clearance_document'=> $kyc['tax_clearance_document']      ?? '',
    ];
}

$data   = &$_SESSION[$sessKey];
$step   = max(1, min(7, (int)($_GET['step'] ?? $_POST['step'] ?? 1)));
$errors = [];

/* ═══════════════════════════════════════════
   POST HANDLER
═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $errors[] = 'Invalid security token. Please refresh and try again.';
    }

    $action   = $_POST['action'] ?? 'next';
    $postStep = (int)($_POST['step'] ?? 1);

    if (empty($errors)) {

        /* ── Step 1 : Basic Information ─────────────────── */
        if ($postStep === 1) {
            $name     = trim($_POST['name']     ?? '');
            $industry = trim($_POST['industry'] ?? '');

            if ($name === '')     { $errors[] = 'Company name is required.'; }
            if ($industry === '') { $errors[] = 'Please select an industry.'; }

            if (empty($errors)) {
                $data['name']                = $name;
                $data['type']                = $_POST['type']           ?? 'startup';
                $data['industry']            = $industry;
                $data['stage']               = $_POST['stage']          ?? '';
                $data['founded_year']        = trim($_POST['founded_year']        ?? '');
                $data['employee_count']      = $_POST['employee_count'] ?? '';
                $data['description']         = trim($_POST['description']         ?? '');
                $data['registration_number'] = trim($_POST['registration_number'] ?? '');
            }
        }

        /* ── Step 2 : Contact ───────────────────────────── */
        if ($postStep === 2) {
            $emailVal   = trim($_POST['email']   ?? '');
            $websiteVal = trim($_POST['website'] ?? '');

            if ($emailVal !== '' && !filter_var($emailVal, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            }
            if ($websiteVal !== '' && !filter_var($websiteVal, FILTER_VALIDATE_URL)) {
                $errors[] = 'Please enter a valid website URL (include https://).';
            }
            if (empty($errors)) {
                $data['email']   = $emailVal;
                $data['phone']   = trim($_POST['phone'] ?? '');
                $data['website'] = $websiteVal;
            }
        }

        /* ── Step 3 : Address ───────────────────────────── */
        if ($postStep === 3) {
            $validProvinces = [
                'Eastern Cape','Free State','Gauteng','KwaZulu-Natal',
                'Limpopo','Mpumalanga','North West','Northern Cape','Western Cape',
            ];
            $province = trim($_POST['province'] ?? '');
            $area     = trim($_POST['area']     ?? '');

            if (!in_array($province, $validProvinces, true)) { $errors[] = 'Please select a valid province.'; }
            if (!in_array($area, ['urban','township','rural'], true)) { $errors[] = 'Please select an area type.'; }

            if (empty($errors)) {
                $data['province']     = $province;
                $data['municipality'] = trim($_POST['municipality'] ?? '');
                $data['city']         = trim($_POST['city']         ?? '');
                $data['suburb']       = trim($_POST['suburb']       ?? '');
                $data['area']         = $area;
            }
        }

        /* ── Step 4 : Pitch ─────────────────────────────── */
        if ($postStep === 4) {
            // Pitch deck upload
            if (!empty($_FILES['pitch_deck']['name'])) {
                $upload = uploadCompanyFile('pitch_deck', $company['uuid'], 'document');
                if ($upload['success']) { $data['pitch_deck_url'] = $upload['path']; }
                else                   { $errors[] = $upload['error']; }
            }

            if (empty($errors)) {
                $data['pitch_problem']        = trim($_POST['pitch_problem']        ?? '');
                $data['pitch_solution']       = trim($_POST['pitch_solution']       ?? '');
                $data['pitch_business_model'] = trim($_POST['pitch_business_model'] ?? '');
                $data['pitch_traction']       = trim($_POST['pitch_traction']       ?? '');
                $data['pitch_target_market']  = trim($_POST['pitch_target_market']  ?? '');
                $data['pitch_competitive']    = trim($_POST['pitch_competitive']    ?? '');
                $data['pitch_team']           = trim($_POST['pitch_team']           ?? '');
                $data['pitch_video_url']      = trim($_POST['pitch_video_url']      ?? '');
            }
        }

        /* ── Step 5 : Highlights ────────────────────────── */
        if ($postStep === 5) {
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
            // Preserve blank defaults so the UI stays populated
            if (empty($highlights)) {
                $highlights = [
                    ['label' => 'Monthly Revenue', 'value' => ''],
                    ['label' => 'Years Operating', 'value' => ''],
                    ['label' => 'Team Size',        'value' => ''],
                ];
            }
            $data['highlights'] = $highlights;
        }

        /* ── Step 6 : Branding ──────────────────────────── */
        if ($postStep === 6) {
            if (!empty($_FILES['logo']['name'])) {
                $upload = uploadCompanyFile('logo', $company['uuid'], 'logo');
                if ($upload['success']) { $data['logo'] = $upload['path']; }
                else                   { $errors[] = $upload['error']; }
            }
            if (empty($errors) && !empty($_FILES['banner']['name'])) {
                $upload = uploadCompanyFile('banner', $company['uuid'], 'banner');
                if ($upload['success']) { $data['banner'] = $upload['path']; }
                else                   { $errors[] = $upload['error']; }
            }
        }

        /* ── Step 7 : Documents (KYC) ───────────────────── */
        if ($postStep === 7) {
            foreach (['registration_document','proof_of_address','director_id_document','tax_clearance_document'] as $field) {
                if (!empty($_FILES[$field]['name'])) {
                    $upload = uploadCompanyFile($field, $company['uuid'], 'document');
                    if ($upload['success']) { $data[$field] = $upload['path']; }
                    else                   { $errors[] = $upload['error']; break; }
                }
            }
        }

        /* ── Persist to DB on every valid save ──────────── */
        if (empty($errors)) {

            $isFinalSubmit = ($postStep === 7 && $action === 'submit');
            $newStatus     = $isFinalSubmit ? 'pending_verification' : 'draft';

            /* companies */
            $pdo->prepare("
                UPDATE companies SET
                    name = :name, type = :type, industry = :industry, stage = :stage,
                    founded_year = :founded_year, employee_count = :employee_count,
                    description = :description, email = :email, phone = :phone,
                    website = :website, registration_number = :reg,
                    logo = :logo, banner = :banner, status = :status
                WHERE id = :id
            ")->execute([
                'name'           => $data['name'],
                'type'           => $data['type'],
                'industry'       => $data['industry']       ?: null,
                'stage'          => $data['stage']          ?: null,
                'founded_year'   => $data['founded_year']   ?: null,
                'employee_count' => $data['employee_count'] ?: null,
                'description'    => $data['description'],
                'email'          => $data['email']          ?: null,
                'phone'          => $data['phone']          ?: null,
                'website'        => $data['website']        ?: null,
                'reg'            => $data['registration_number'] ?: null,
                'logo'           => $data['logo']           ?: null,
                'banner'         => $data['banner']         ?: null,
                'status'         => $newStatus,
                'id'             => $companyId,
            ]);

            /* company_filter upsert */
            $pdo->prepare("
                INSERT INTO company_filter (company_id, province, municipality, city, suburb, area)
                VALUES (:cid, :prov, :muni, :city, :sub, :area)
                ON DUPLICATE KEY UPDATE
                    province = VALUES(province), municipality = VALUES(municipality),
                    city = VALUES(city), suburb = VALUES(suburb), area = VALUES(area)
            ")->execute([
                'cid'  => $companyId,
                'prov' => $data['province']     ?: null,
                'muni' => $data['municipality'] ?: null,
                'city' => $data['city']         ?: null,
                'sub'  => $data['suburb']       ?: null,
                'area' => $data['area']         ?: null,
            ]);

            /* company_pitch upsert — no use_of_funds; that lives per SPV campaign */
            $pitchExists = !empty($pitchRow);
            if ($pitchExists) {
                $pdo->prepare("
                    UPDATE company_pitch SET
                        problem_statement     = :problem,
                        solution              = :solution,
                        business_model        = :bmodel,
                        traction              = :traction,
                        target_market         = :tmarket,
                        competitive_landscape = :competitive,
                        team_overview         = :team,
                        pitch_deck_url        = :deck,
                        pitch_video_url       = :video
                    WHERE company_id = :cid
                ")->execute([
                    'cid'         => $companyId,
                    'problem'     => $data['pitch_problem']        ?: null,
                    'solution'    => $data['pitch_solution']       ?: null,
                    'bmodel'      => $data['pitch_business_model'] ?: null,
                    'traction'    => $data['pitch_traction']       ?: null,
                    'tmarket'     => $data['pitch_target_market']  ?: null,
                    'competitive' => $data['pitch_competitive']    ?: null,
                    'team'        => $data['pitch_team']           ?: null,
                    'deck'        => $data['pitch_deck_url']       ?: null,
                    'video'       => $data['pitch_video_url']      ?: null,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO company_pitch
                        (company_id, problem_statement, solution, business_model, traction,
                         target_market, competitive_landscape, team_overview,
                         pitch_deck_url, pitch_video_url)
                    VALUES
                        (:cid, :problem, :solution, :bmodel, :traction,
                         :tmarket, :competitive, :team,
                         :deck, :video)
                ")->execute([
                    'cid'         => $companyId,
                    'problem'     => $data['pitch_problem']        ?: null,
                    'solution'    => $data['pitch_solution']       ?: null,
                    'bmodel'      => $data['pitch_business_model'] ?: null,
                    'traction'    => $data['pitch_traction']       ?: null,
                    'tmarket'     => $data['pitch_target_market']  ?: null,
                    'competitive' => $data['pitch_competitive']    ?: null,
                    'team'        => $data['pitch_team']           ?: null,
                    'deck'        => $data['pitch_deck_url']       ?: null,
                    'video'       => $data['pitch_video_url']      ?: null,
                ]);
                $pitchRow = ['company_id' => $companyId];
            }

            /* pitch_highlights — delete-and-reinsert */
            $pdo->prepare("DELETE FROM pitch_highlights WHERE company_id = ?")->execute([$companyId]);
            if (!empty($data['highlights'])) {
                $stmt = $pdo->prepare("
                    INSERT INTO pitch_highlights (company_id, label, value, sort_order)
                    VALUES (:cid, :label, :value, :sort)
                ");
                foreach ($data['highlights'] as $i => $hl) {
                    if (!empty($hl['label']) || !empty($hl['value'])) {
                        $stmt->execute([
                            'cid'   => $companyId,
                            'label' => $hl['label'],
                            'value' => $hl['value'],
                            'sort'  => $i,
                        ]);
                    }
                }
            }

            /* company_kyc upsert */
            $kycStatus = $isFinalSubmit ? 'pending' : ($kyc['verification_status'] ?? 'pending');
            if ($kyc) {
                $pdo->prepare("
                    UPDATE company_kyc SET
                        registration_document  = :rd,
                        proof_of_address       = :pa,
                        director_id_document   = :did,
                        tax_clearance_document = :tc,
                        verification_status    = :vs
                    WHERE company_id = :cid
                ")->execute([
                    'cid' => $companyId,
                    'rd'  => $data['registration_document']  ?: null,
                    'pa'  => $data['proof_of_address']        ?: null,
                    'did' => $data['director_id_document']    ?: null,
                    'tc'  => $data['tax_clearance_document']  ?: null,
                    'vs'  => $kycStatus,
                ]);
            } else {
                $pdo->prepare("
                    INSERT INTO company_kyc
                        (company_id, registration_document, proof_of_address,
                         director_id_document, tax_clearance_document, verification_status)
                    VALUES (:cid, :rd, :pa, :did, :tc, :vs)
                ")->execute([
                    'cid' => $companyId,
                    'rd'  => $data['registration_document']  ?: null,
                    'pa'  => $data['proof_of_address']        ?: null,
                    'did' => $data['director_id_document']    ?: null,
                    'tc'  => $data['tax_clearance_document']  ?: null,
                    'vs'  => $kycStatus,
                ]);
            }

            if ($isFinalSubmit) {
                logCompanyActivity($companyId, $_SESSION['user_id'], 'Submitted for verification');
                unset($_SESSION[$sessKey]);
                redirect("/app/company/dashboard.php?uuid=$uuid&submitted=1");
            }

            $step = ($action === 'back') ? max(1, $postStep - 1) : min(7, $postStep + 1);

        } else {
            $step = $postStep;
        }
    }
}

/* ─────────────────────────────────────────────
   Step meta
───────────────────────────────────────────── */
$steps = [
    1 => ['label' => 'Basic Info',   'icon' => 'fa-building',         'desc' => 'Name, type & industry'],
    2 => ['label' => 'Contact',      'icon' => 'fa-address-card',     'desc' => 'Email, phone & website'],
    3 => ['label' => 'Address',      'icon' => 'fa-map-location-dot', 'desc' => 'Location & area type'],
    4 => ['label' => 'Your Pitch',   'icon' => 'fa-bullhorn',         'desc' => 'Story & pitch assets'],
    5 => ['label' => 'Highlights',   'icon' => 'fa-star',             'desc' => 'Key stats & achievements'],
    6 => ['label' => 'Branding',     'icon' => 'fa-image',            'desc' => 'Logo & banner'],
    7 => ['label' => 'Documents',    'icon' => 'fa-file-shield',      'desc' => 'Business verification files'],
];

$validProvinces = [
    'Eastern Cape','Free State','Gauteng','KwaZulu-Natal',
    'Limpopo','Mpumalanga','North West','Northern Cape','Western Cape',
];

$validIndustries = [
    'Technology & Software',
    'Fintech & Financial Services',
    'Healthcare & Biotech',
    'Education & EdTech',
    'E-Commerce & Retail',
    'Agriculture & AgriTech',
    'Energy & CleanTech',
    'Real Estate & PropTech',
    'Media & Entertainment',
    'Logistics & Supply Chain',
    'Food & Beverage',
    'Manufacturing',
    'Consulting & Professional Services',
    'Non-Profit & Social Impact',
    'Other',
];

$companyTypes = [
    'startup'           => 'Startup',
    'sme'               => 'SME (Small & Medium Enterprise)',
    'corporation'       => 'Corporation',
    'ngo'               => 'NGO / Non-Profit',
    'cooperative'       => 'Cooperative',
    'social_enterprise' => 'Social Enterprise',
    'other'             => 'Other',
];

$stages = [
    'idea'        => 'Idea / Pre-Seed',
    'seed'        => 'Seed',
    'series_a'    => 'Series A',
    'series_b'    => 'Series B+',
    'growth'      => 'Growth',
    'established' => 'Established',
];

$employeeSizes = ['1–5', '6–15', '16–50', '51–100', '101–250', '251–500', '500+'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Onboarding Wizard | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display:ital@0;1&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;0,9..40,600;1,9..40,300&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="/app/assets/js/sa-locations.js"></script>
    <style>
    /* ══════════════════════════════════════════
       VARIABLES & RESET
    ══════════════════════════════════════════ */
    :root {
        --navy:         #0b2545;
        --navy-mid:     #0f3b7a;
        --navy-light:   #1a56b0;
        --amber:        #f59e0b;
        --amber-light:  #fef3c7;
        --amber-dark:   #d97706;
        --surface:      #ffffff;
        --surface-2:    #f8f9fb;
        --border:       #e4e7ec;
        --border-focus: #1a56b0;
        --text:         #101828;
        --text-muted:   #667085;
        --text-light:   #98a2b3;
        --success:      #0b6b4d;
        --success-bg:   #e6f7ec;
        --error:        #b91c1c;
        --error-bg:     #fef2f2;
        --error-bdr:    #fecaca;
        --sidebar-w:    280px;
        --radius:       14px;
        --radius-sm:    8px;
        --shadow-card:  0 8px 32px rgba(11,37,69,.08), 0 1px 3px rgba(11,37,69,.06);
        --shadow-btn:   0 4px 12px rgba(15,59,122,.25);
        --transition:   .22s cubic-bezier(.4,0,.2,1);
    }

    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'DM Sans', sans-serif;
        background: var(--surface-2);
        color: var(--text);
        min-height: 100vh;
        display: flex;
        flex-direction: column;
    }
    ::-webkit-scrollbar { width: 6px; }
    ::-webkit-scrollbar-track { background: transparent; }
    ::-webkit-scrollbar-thumb { background: var(--border); border-radius: 99px; }

    /* ══ LAYOUT SHELL ════════════════════════ */
    .wizard-shell { display: flex; min-height: 100vh; }

    /* ══ SIDEBAR ═════════════════════════════ */
    .wizard-sidebar {
        width: var(--sidebar-w);
        background: var(--navy);
        min-height: 100vh;
        padding: 3rem 2rem;
        display: flex;
        flex-direction: column;
        position: sticky;
        top: 0;
        flex-shrink: 0;
        overflow: hidden;
    }
    .wizard-sidebar::before {
        content: '';
        position: absolute;
        bottom: -80px; right: -80px;
        width: 300px; height: 300px;
        border-radius: 50%;
        border: 60px solid rgba(245,158,11,.07);
        pointer-events: none;
    }
    .sidebar-brand { margin-bottom: 2.5rem; }
    .sidebar-brand-logo { font-family: 'DM Serif Display', serif; font-size: 1.5rem; color: #fff; letter-spacing: -.01em; line-height: 1; margin-bottom: .25rem; }
    .sidebar-brand-sub { font-size: .78rem; color: rgba(255,255,255,.45); text-transform: uppercase; letter-spacing: .1em; }
    .step-list { list-style: none; flex: 1; }
    .step-item { display: flex; align-items: flex-start; gap: 1rem; padding: .75rem 0; position: relative; }
    .step-item:not(:last-child)::after { content: ''; position: absolute; left: 19px; top: calc(.75rem + 38px); width: 2px; height: calc(100% - 16px); background: rgba(255,255,255,.1); transition: background var(--transition); }
    .step-item.done:not(:last-child)::after { background: rgba(245,158,11,.35); }
    .step-icon { width: 40px; height: 40px; border-radius: 50%; background: rgba(255,255,255,.08); border: 2px solid rgba(255,255,255,.12); display: flex; align-items: center; justify-content: center; font-size: .9rem; color: rgba(255,255,255,.4); flex-shrink: 0; transition: all var(--transition); position: relative; z-index: 1; }
    .step-item.done   .step-icon { background: rgba(245,158,11,.2); border-color: var(--amber); color: var(--amber); }
    .step-item.active .step-icon { background: var(--amber); border-color: var(--amber); color: var(--navy); box-shadow: 0 0 0 5px rgba(245,158,11,.2); }
    .step-text { padding-top: .2rem; }
    .step-label { font-size: .85rem; font-weight: 600; color: rgba(255,255,255,.45); transition: color var(--transition); line-height: 1; margin-bottom: .18rem; }
    .step-item.done   .step-label { color: rgba(255,255,255,.7); }
    .step-item.active .step-label { color: #fff; }
    .step-desc { font-size: .72rem; color: rgba(255,255,255,.25); transition: color var(--transition); }
    .step-item.active .step-desc { color: rgba(255,255,255,.5); }
    .sidebar-footer { margin-top: 1.5rem; padding-top: 1.25rem; border-top: 1px solid rgba(255,255,255,.08); }
    .sidebar-company-name { font-size: .75rem; color: rgba(255,255,255,.35); margin-bottom: .25rem; text-transform: uppercase; letter-spacing: .07em; }
    .sidebar-company-val { font-size: .92rem; font-weight: 600; color: rgba(255,255,255,.7); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

    /* ══ MOBILE PROGRESS ═════════════════════ */
    .mobile-progress { display: none; background: var(--navy); padding: 1.25rem 1.5rem; position: sticky; top: 0; z-index: 99; }
    .mob-prog-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: .75rem; }
    .mob-prog-label { font-size: .9rem; font-weight: 600; color: #fff; }
    .mob-prog-count { font-size: .8rem; color: rgba(255,255,255,.5); }
    .mob-prog-bar { height: 4px; background: rgba(255,255,255,.15); border-radius: 99px; overflow: hidden; }
    .mob-prog-fill { height: 100%; background: var(--amber); border-radius: 99px; transition: width .4s ease; }

    /* ══ MAIN CONTENT ════════════════════════ */
    .wizard-main { flex: 1; display: flex; flex-direction: column; min-height: 100vh; padding: 3rem 3.5rem; overflow-x: hidden; }
    .step-card { background: var(--surface); border-radius: var(--radius); box-shadow: var(--shadow-card); padding: 2.5rem 2.75rem; border: 1px solid var(--border); flex: 1; animation: slideIn .3s ease; max-width: 760px; width: 100%; }
    @keyframes slideIn  { from { opacity: 0; transform: translateX( 18px); } to { opacity: 1; transform: translateX(0); } }
    @keyframes slideBack { from { opacity: 0; transform: translateX(-18px); } to { opacity: 1; transform: translateX(0); } }
    .step-card.going-back { animation: slideBack .3s ease; }
    .step-heading { margin-bottom: 2rem; }
    .step-heading .step-number { font-size: .78rem; font-weight: 600; color: var(--amber-dark); text-transform: uppercase; letter-spacing: .1em; margin-bottom: .4rem; }
    .step-heading h2 { font-family: 'DM Serif Display', serif; font-size: 1.85rem; color: var(--navy); line-height: 1.2; margin-bottom: .4rem; }
    .step-heading p { font-size: .95rem; color: var(--text-muted); }

    /* ══ FORM ELEMENTS ═══════════════════════ */
    .form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
    .form-grid .span-2 { grid-column: span 2; }
    .field { display: flex; flex-direction: column; gap: .45rem; }
    .field label { font-size: .83rem; font-weight: 600; color: var(--text); display: flex; align-items: center; gap: .4rem; }
    .field label .req { color: var(--amber-dark); font-weight: 700; }
    .field label i { color: var(--navy-light); font-size: .8rem; }
    .field input, .field select, .field textarea {
        padding: .72rem 1rem;
        border: 1.5px solid var(--border);
        border-radius: var(--radius-sm);
        font-family: 'DM Sans', sans-serif;
        font-size: .93rem;
        color: var(--text);
        background: var(--surface-2);
        transition: border-color var(--transition), box-shadow var(--transition), background var(--transition);
        outline: none;
        width: 100%;
    }
    .field input:focus, .field select:focus, .field textarea:focus { border-color: var(--border-focus); background: #fff; box-shadow: 0 0 0 3.5px rgba(26,86,176,.1); }
    .field select { appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 12 12'%3E%3Cpath fill='%23667085' d='M6 8L1 3h10z'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 1rem center; padding-right: 2.25rem; cursor: pointer; }
    .field select:disabled { opacity: .45; cursor: not-allowed; }
    .field textarea { resize: vertical; min-height: 90px; line-height: 1.6; }
    .field .hint { font-size: .77rem; color: var(--text-light); line-height: 1.4; }

    /* ── File upload ──────────────────────── */
    .file-upload-zone { border: 2px dashed var(--border); border-radius: var(--radius-sm); background: var(--surface-2); padding: 1.5rem; text-align: center; cursor: pointer; transition: all var(--transition); position: relative; }
    .file-upload-zone:hover, .file-upload-zone.dragover { border-color: var(--navy-light); background: #eff4ff; }
    .file-upload-zone input[type="file"] { position: absolute; inset: 0; opacity: 0; cursor: pointer; width: 100%; height: 100%; }
    .file-upload-icon { font-size: 1.75rem; color: var(--text-light); margin-bottom: .5rem; }
    .file-upload-label { font-size: .88rem; font-weight: 500; color: var(--text-muted); }
    .file-upload-label strong { color: var(--navy-light); }
    .file-upload-sub { font-size: .75rem; color: var(--text-light); margin-top: .2rem; }
    .preview-wrap { margin-top: .75rem; position: relative; display: inline-block; }
    .preview-img { width: 100%; max-height: 120px; object-fit: cover; border-radius: var(--radius-sm); border: 1.5px solid var(--border); }
    .preview-logo-img { width: 80px; height: 80px; object-fit: cover; border-radius: 50%; border: 2px solid var(--border); }
    .existing-file-link { display: inline-flex; align-items: center; gap: .4rem; font-size: .8rem; color: var(--navy-light); text-decoration: none; margin-top: .5rem; padding: .3rem .75rem; background: #eff4ff; border-radius: 99px; border: 1px solid #c7d9f8; }
    .existing-file-link:hover { background: #dbeafe; }

    /* ── KYC document field ───────────────── */
    .doc-field { background: var(--surface-2); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: 1.25rem; display: flex; align-items: flex-start; gap: 1rem; transition: border-color var(--transition); }
    .doc-field:focus-within { border-color: var(--border-focus); }
    .doc-field + .doc-field { margin-top: 1rem; }
    .doc-field-icon { width: 40px; height: 40px; background: #eff4ff; border-radius: var(--radius-sm); display: flex; align-items: center; justify-content: center; color: var(--navy-light); font-size: 1rem; flex-shrink: 0; }
    .doc-field-body { flex: 1; }
    .doc-field-label { font-size: .88rem; font-weight: 600; color: var(--text); margin-bottom: .15rem; }
    .doc-field-hint { font-size: .76rem; color: var(--text-light); margin-bottom: .6rem; }
    .doc-field input[type="file"] { font-size: .82rem; color: var(--text-muted); width: 100%; }

    /* ── Section divider ─────────────────── */
    .form-section-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; letter-spacing: .1em; color: var(--text-light); margin: 1.75rem 0 1rem; display: flex; align-items: center; gap: .6rem; }
    .form-section-label::after { content: ''; flex: 1; height: 1px; background: var(--border); }

    /* ── Alerts ──────────────────────────── */
    .alert { display: flex; align-items: flex-start; gap: .75rem; padding: .9rem 1.1rem; border-radius: var(--radius-sm); margin-bottom: 1.5rem; font-size: .88rem; font-weight: 500; border: 1px solid transparent; animation: slideIn .3s ease; }
    .alert i { font-size: 1rem; margin-top: .05rem; flex-shrink: 0; }
    .alert.error { background: var(--error-bg); color: var(--error); border-color: var(--error-bdr); }
    .alert ul { margin: .3rem 0 0 1rem; }
    .alert ul li { margin-bottom: .15rem; }

    /* ══ DYNAMIC ROWS (Highlights) ═══════════ */
    .dynamic-rows { display: flex; flex-direction: column; gap: .75rem; }
    .dynamic-row { display: grid; gap: .75rem; align-items: center; background: var(--surface-2); border: 1.5px solid var(--border); border-radius: var(--radius-sm); padding: .9rem 1rem; transition: border-color var(--transition); animation: rowIn .2s ease; }
    @keyframes rowIn { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: translateY(0); } }
    .dynamic-row:focus-within { border-color: var(--border-focus); }
    .dynamic-row.hl-row { grid-template-columns: 1fr 1fr auto; }
    .dynamic-row input { padding: .6rem .85rem; border: 1.5px solid var(--border); border-radius: 6px; font-family: 'DM Sans', sans-serif; font-size: .88rem; color: var(--text); background: #fff; outline: none; width: 100%; transition: border-color var(--transition); }
    .dynamic-row input:focus { border-color: var(--border-focus); }
    .btn-remove-row { width: 30px; height: 30px; border-radius: 50%; border: 1.5px solid var(--error-bdr); background: var(--error-bg); color: var(--error); display: flex; align-items: center; justify-content: center; cursor: pointer; flex-shrink: 0; font-size: .8rem; transition: all var(--transition); }
    .btn-remove-row:hover { background: var(--error); color: #fff; }
    .btn-add-row { display: inline-flex; align-items: center; gap: .5rem; margin-top: .75rem; padding: .55rem 1.1rem; border: 1.5px dashed var(--navy-light); border-radius: 99px; background: transparent; color: var(--navy-light); font-family: 'DM Sans', sans-serif; font-size: .83rem; font-weight: 600; cursor: pointer; transition: all var(--transition); }
    .btn-add-row:hover { background: #eff4ff; border-style: solid; }
    .row-col-label { font-size: .75rem; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .07em; margin-bottom: .3rem; }

    /* ══ CALLOUT ═════════════════════════════ */
    .callout { background: var(--amber-light); border: 1px solid var(--amber); border-radius: var(--radius-sm); padding: .85rem 1rem; font-size: .84rem; color: #78350f; display: flex; gap: .6rem; align-items: flex-start; margin-bottom: 1.25rem; }
    .callout i { flex-shrink: 0; margin-top: .1rem; color: var(--amber-dark); }
    .callout.info { background: #eff4ff; border-color: #c7d9f8; color: var(--navy-mid); }
    .callout.info i { color: var(--navy-light); }

    /* ══ STEP ACTIONS ════════════════════════ */
    .step-actions { display: flex; align-items: center; justify-content: space-between; margin-top: 2.25rem; padding-top: 1.5rem; border-top: 1px solid var(--border); gap: 1rem; flex-wrap: wrap; }
    .step-actions-left  { display: flex; gap: .75rem; align-items: center; }
    .step-actions-right { display: flex; gap: .75rem; align-items: center; }
    .btn { display: inline-flex; align-items: center; gap: .5rem; padding: .72rem 1.6rem; border-radius: 99px; font-family: 'DM Sans', sans-serif; font-size: .9rem; font-weight: 600; cursor: pointer; transition: all var(--transition); border: none; text-decoration: none; outline: none; }
    .btn-ghost  { background: transparent; color: var(--text-muted); border: 1.5px solid var(--border); }
    .btn-ghost:hover  { border-color: #94a3b8; color: var(--text); background: #f1f5f9; }
    .btn-save   { background: var(--surface-2); color: var(--navy); border: 1.5px solid var(--border); }
    .btn-save:hover   { background: #eff4ff; border-color: var(--navy-light); color: var(--navy-mid); }
    .btn-primary { background: var(--navy-mid); color: #fff; box-shadow: var(--shadow-btn); }
    .btn-primary:hover { background: var(--navy); transform: translateY(-1px); box-shadow: 0 6px 16px rgba(15,59,122,.3); }
    .btn-amber  { background: var(--amber); color: var(--navy); box-shadow: 0 4px 12px rgba(245,158,11,.3); }
    .btn-amber:hover  { background: var(--amber-dark); color: #fff; transform: translateY(-1px); }
    .btn-back   { background: transparent; color: var(--text-muted); padding-left: .5rem; }
    .btn-back:hover   { color: var(--text); }

    /* ══ RESPONSIVE ══════════════════════════ */
    @media (max-width: 900px) {
        .wizard-sidebar { display: none; }
        .mobile-progress { display: block; }
        .wizard-main { padding: 1.5rem; }
        .step-card { padding: 1.75rem 1.5rem; }
        .form-grid { grid-template-columns: 1fr; }
        .form-grid .span-2 { grid-column: span 1; }
        .dynamic-row.hl-row { grid-template-columns: 1fr auto; }
        .dynamic-row.hl-row .hl-value-col { grid-column: 1; }
    }
    @media (max-width: 540px) {
        .wizard-main { padding: 1rem; }
        .step-card { padding: 1.25rem 1rem; }
        .step-actions { flex-direction: column-reverse; align-items: stretch; }
        .step-actions-left, .step-actions-right { justify-content: center; }
        .btn { justify-content: center; }
    }
    </style>
</head>
<body>
<div class="wizard-shell">

    <!-- ════════ SIDEBAR ════════ -->
    <aside class="wizard-sidebar">
        <div class="sidebar-brand">
            <div class="sidebar-brand-logo">Old Union</div>
            <div class="sidebar-brand-sub">Company Onboarding</div>
        </div>
        <ul class="step-list">
            <?php foreach ($steps as $n => $s):
                $cls = $n < $step ? 'done' : ($n === $step ? 'active' : '');
            ?>
            <li class="step-item <?php echo $cls; ?>">
                <div class="step-icon">
                    <?php if ($n < $step): ?>
                        <i class="fa-solid fa-check"></i>
                    <?php else: ?>
                        <i class="fa-solid <?php echo $s['icon']; ?>"></i>
                    <?php endif; ?>
                </div>
                <div class="step-text">
                    <div class="step-label"><?php echo $s['label']; ?></div>
                    <div class="step-desc"><?php echo $s['desc']; ?></div>
                </div>
            </li>
            <?php endforeach; ?>
        </ul>
        <div class="sidebar-footer">
            <div class="sidebar-company-name">Editing company</div>
            <div class="sidebar-company-val"><?php echo htmlspecialchars($data['name'] ?: $company['name']); ?></div>
        </div>
    </aside>

    <!-- ════════ MOBILE TOP BAR ════════ -->
    <div class="mobile-progress">
        <div class="mob-prog-head">
            <span class="mob-prog-label">Step <?php echo $step; ?>: <?php echo $steps[$step]['label']; ?></span>
            <span class="mob-prog-count"><?php echo $step; ?> / <?php echo count($steps); ?></span>
        </div>
        <div class="mob-prog-bar">
            <div class="mob-prog-fill" style="width: <?php echo round($step / count($steps) * 100); ?>%"></div>
        </div>
    </div>

    <!-- ════════ MAIN ════════ -->
    <main class="wizard-main">
        <div class="step-card" id="stepCard">

            <?php if (!empty($errors)): ?>
            <div class="alert error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <div>
                    <strong>Please fix the following:</strong>
                    <ul>
                        <?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?>
                    </ul>
                </div>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data" id="wizardForm">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="step"       value="<?php echo $step; ?>">
                <input type="hidden" name="action"     value="next" id="actionInput">


                <?php /* ════════════════════════════════
                            STEP 1 — Basic Information
                        ════════════════════════════════ */ ?>
                <?php if ($step === 1): ?>
                <div class="step-heading">
                    <div class="step-number">Step 1 of 7</div>
                    <h2>Basic Information</h2>
                    <p>Tell us about your company — its name, type, industry, and stage.</p>
                </div>
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="name"><i class="fa-solid fa-building"></i> Company Name <span class="req">*</span></label>
                        <input type="text" id="name" name="name"
                               value="<?php echo htmlspecialchars($data['name']); ?>"
                               placeholder="e.g. Acme Technologies Ltd" required>
                    </div>
                    <div class="field">
                        <label for="type"><i class="fa-solid fa-tag"></i> Company Type <span class="req">*</span></label>
                        <select id="type" name="type">
                            <?php foreach ($companyTypes as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $data['type'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="stage"><i class="fa-solid fa-chart-line"></i> Growth Stage</label>
                        <select id="stage" name="stage">
                            <option value="">— Select stage —</option>
                            <?php foreach ($stages as $v => $l): ?>
                                <option value="<?php echo $v; ?>" <?php echo $data['stage'] === $v ? 'selected' : ''; ?>><?php echo $l; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label for="industry"><i class="fa-solid fa-industry"></i> Industry / Sector <span class="req">*</span></label>
                        <select id="industry" name="industry" required>
                            <option value="">— Select industry —</option>
                            <?php foreach ($validIndustries as $ind): ?>
                                <option value="<?php echo htmlspecialchars($ind); ?>"
                                    <?php echo $data['industry'] === $ind ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($ind); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="founded_year"><i class="fa-solid fa-calendar"></i> Year Founded</label>
                        <input type="number" id="founded_year" name="founded_year"
                               min="1800" max="<?php echo date('Y'); ?>"
                               placeholder="<?php echo date('Y'); ?>"
                               value="<?php echo htmlspecialchars($data['founded_year']); ?>">
                    </div>
                    <div class="field">
                        <label for="employee_count"><i class="fa-solid fa-users"></i> Team Size</label>
                        <select id="employee_count" name="employee_count">
                            <option value="">— Select —</option>
                            <?php foreach ($employeeSizes as $sz): ?>
                                <option value="<?php echo $sz; ?>" <?php echo $data['employee_count'] === $sz ? 'selected' : ''; ?>><?php echo $sz; ?> employees</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="registration_number"><i class="fa-solid fa-hashtag"></i> Registration Number</label>
                        <input type="text" id="registration_number" name="registration_number"
                               value="<?php echo htmlspecialchars($data['registration_number']); ?>"
                               placeholder="e.g. 2023/123456/07">
                        <span class="hint">Your CIPC or relevant authority registration number.</span>
                    </div>
                    <div class="field span-2">
                        <label for="description"><i class="fa-solid fa-align-left"></i> About the Company</label>
                        <textarea id="description" name="description" rows="4"
                                  placeholder="Describe what your company does, the problem you solve, and your mission…"><?php echo htmlspecialchars($data['description']); ?></textarea>
                    </div>
                </div>


                <?php /* ════════════════════════════════
                            STEP 2 — Contact Information
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 2): ?>
                <div class="step-heading">
                    <div class="step-number">Step 2 of 7</div>
                    <h2>Contact Information</h2>
                    <p>How can investors and Old Union reach your company?</p>
                </div>
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="email"><i class="fa-solid fa-envelope"></i> Business Email</label>
                        <input type="email" id="email" name="email"
                               value="<?php echo htmlspecialchars($data['email']); ?>"
                               placeholder="hello@yourcompany.com">
                    </div>
                    <div class="field">
                        <label for="phone"><i class="fa-solid fa-phone"></i> Phone Number</label>
                        <input type="tel" id="phone" name="phone"
                               value="<?php echo htmlspecialchars($data['phone']); ?>"
                               placeholder="+27 10 000 0000">
                    </div>
                    <div class="field">
                        <label for="website"><i class="fa-solid fa-globe"></i> Website</label>
                        <input type="url" id="website" name="website"
                               value="<?php echo htmlspecialchars($data['website']); ?>"
                               placeholder="https://yourcompany.com">
                        <span class="hint">Include <strong>https://</strong></span>
                    </div>
                </div>


                <?php /* ════════════════════════════════
                            STEP 3 — Address
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 3): ?>
                <div class="step-heading">
                    <div class="step-number">Step 3 of 7</div>
                    <h2>Location &amp; Area</h2>
                    <p>Where is your company based? This helps match you with local investors and opportunities.</p>
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="province"><i class="fa-solid fa-map"></i> Province <span class="req">*</span></label>
                        <select id="province" name="province" required>
                            <option value="">— Select province —</option>
                            <?php foreach ($validProvinces as $prov): ?>
                                <option value="<?php echo htmlspecialchars($prov); ?>"
                                    <?php echo $data['province'] === $prov ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($prov); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="area"><i class="fa-solid fa-layer-group"></i> Area Type <span class="req">*</span></label>
                        <select id="area" name="area" required>
                            <option value="">— Select area type —</option>
                            <option value="urban"    <?php echo $data['area'] === 'urban'    ? 'selected' : ''; ?>>Urban</option>
                            <option value="township" <?php echo $data['area'] === 'township' ? 'selected' : ''; ?>>Township</option>
                            <option value="rural"    <?php echo $data['area'] === 'rural'    ? 'selected' : ''; ?>>Rural</option>
                        </select>
                    </div>
                    <div class="field">
                        <label for="municipality"><i class="fa-solid fa-city"></i> Municipality</label>
                        <select id="municipality" name="municipality" disabled>
                            <option value="">— Select municipality —</option>
                        </select>
                        <span class="hint">Select a province first.</span>
                    </div>
                    <div class="field">
                        <label for="city"><i class="fa-solid fa-building"></i> City / Town</label>
                        <select id="city" name="city" disabled>
                            <option value="">— Select city —</option>
                        </select>
                    </div>
                    <div class="field span-2">
                        <label for="suburb"><i class="fa-solid fa-location-dot"></i> Suburb</label>
                        <select id="suburb" name="suburb" disabled>
                            <option value="">— Select suburb —</option>
                        </select>
                    </div>
                </div>


                <?php /* ════════════════════════════════
                            STEP 4 — Pitch
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 4): ?>
                <div class="step-heading">
                    <div class="step-number">Step 4 of 7</div>
                    <h2>Your Pitch</h2>
                    <p>Tell your company's story clearly and compellingly. This is what contributors read when deciding whether to back you. All fields are optional but the more you fill in, the stronger your listing.</p>
                </div>

                <div class="callout info">
                    <i class="fa-solid fa-circle-info"></i>
                    <div><strong>Note:</strong> Use of funds is set per SPV campaign — not here. Each campaign you create will have its own dedicated use-of-funds section so investors know exactly where their money goes for that specific raise.</div>
                </div>

                <div class="form-section-label"><i class="fa-solid fa-pen-nib" style="color:var(--navy-light)"></i> The Story</div>
                <div class="form-grid">
                    <div class="field span-2">
                        <label for="pitch_problem"><i class="fa-solid fa-triangle-exclamation"></i> The Problem</label>
                        <textarea id="pitch_problem" name="pitch_problem" rows="3"
                                  placeholder="What pain point or gap in the market does your business address? Be specific — real problems resonate."><?php echo htmlspecialchars($data['pitch_problem']); ?></textarea>
                    </div>
                    <div class="field span-2">
                        <label for="pitch_solution"><i class="fa-solid fa-lightbulb"></i> Your Solution</label>
                        <textarea id="pitch_solution" name="pitch_solution" rows="3"
                                  placeholder="How does your product or service solve that problem? What makes your approach unique?"><?php echo htmlspecialchars($data['pitch_solution']); ?></textarea>
                    </div>
                    <div class="field span-2">
                        <label for="pitch_business_model"><i class="fa-solid fa-coins"></i> Business Model</label>
                        <textarea id="pitch_business_model" name="pitch_business_model" rows="3"
                                  placeholder="How do you make money? Who pays you, how often, and why? e.g. subscription fees, commission, product sales…"><?php echo htmlspecialchars($data['pitch_business_model']); ?></textarea>
                    </div>
                    <div class="field span-2">
                        <label for="pitch_traction"><i class="fa-solid fa-rocket"></i> Traction &amp; Milestones</label>
                        <textarea id="pitch_traction" name="pitch_traction" rows="3"
                                  placeholder="What have you already achieved? Revenue figures, customer counts, partnerships, awards — anything that shows momentum."><?php echo htmlspecialchars($data['pitch_traction']); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="pitch_target_market"><i class="fa-solid fa-bullseye"></i> Target Market</label>
                        <textarea id="pitch_target_market" name="pitch_target_market" rows="3"
                                  placeholder="Who are your customers? Describe the segment — demographics, geography, size of the opportunity."><?php echo htmlspecialchars($data['pitch_target_market']); ?></textarea>
                    </div>
                    <div class="field">
                        <label for="pitch_competitive"><i class="fa-solid fa-chess-knight"></i> Competitive Landscape</label>
                        <textarea id="pitch_competitive" name="pitch_competitive" rows="3"
                                  placeholder="Who are your main competitors? What is your advantage or differentiation over them?"><?php echo htmlspecialchars($data['pitch_competitive']); ?></textarea>
                    </div>
                    <div class="field span-2">
                        <label for="pitch_team"><i class="fa-solid fa-people-group"></i> The Team</label>
                        <textarea id="pitch_team" name="pitch_team" rows="3"
                                  placeholder="Who is behind this business? Key people, their backgrounds, and why this team can execute."><?php echo htmlspecialchars($data['pitch_team']); ?></textarea>
                    </div>
                </div>

                <div class="form-section-label"><i class="fa-solid fa-photo-film" style="color:var(--navy-light)"></i> Pitch Assets</div>
                <div class="form-grid">
                    <div class="field">
                        <label for="pitch_deck"><i class="fa-solid fa-file-powerpoint"></i> Pitch Deck</label>
                        <div class="file-upload-zone">
                            <input type="file" id="pitch_deck" name="pitch_deck" accept=".pdf"
                                   onchange="showFileName(this, 'deckLabel')">
                            <div class="file-upload-icon"><i class="fa-solid fa-file-pdf"></i></div>
                            <div class="file-upload-label" id="deckLabel"><strong>Click to upload</strong> your pitch deck</div>
                            <div class="file-upload-sub">PDF only · max 5 MB</div>
                        </div>
                        <?php if ($data['pitch_deck_url']): ?>
                            <a href="<?php echo htmlspecialchars($data['pitch_deck_url']); ?>" target="_blank" class="existing-file-link">
                                <i class="fa-solid fa-file-pdf"></i> Uploaded pitch deck — view
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="pitch_video_url"><i class="fa-brands fa-youtube"></i> Pitch Video URL</label>
                        <input type="url" id="pitch_video_url" name="pitch_video_url"
                               value="<?php echo htmlspecialchars($data['pitch_video_url']); ?>"
                               placeholder="https://youtube.com/watch?v=...">
                        <span class="hint">YouTube or Vimeo link. A 2-minute video pitch goes a long way.</span>
                    </div>
                </div>


                <?php /* ════════════════════════════════
                            STEP 5 — Highlights
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 5): ?>
                <div class="step-heading">
                    <div class="step-number">Step 5 of 7</div>
                    <h2>Key Highlights</h2>
                    <p>These headline stats appear on your listing card — the first things a potential contributor sees. Keep them specific and impressive. Add up to 8.</p>
                </div>

                <div style="display:grid; grid-template-columns:1fr 1fr; gap:.5rem; margin-bottom:1.25rem;">
                    <div class="row-col-label" style="padding-left:.2rem;">Label</div>
                    <div class="row-col-label" style="padding-left:.2rem;">Value</div>
                </div>

                <div class="dynamic-rows" id="hlRows">
                    <?php foreach ($data['highlights'] as $hl): ?>
                    <div class="dynamic-row hl-row">
                        <div>
                            <input type="text" name="hl_label[]"
                                   value="<?php echo htmlspecialchars($hl['label'] ?? ''); ?>"
                                   placeholder="e.g. Monthly Revenue">
                        </div>
                        <div class="hl-value-col">
                            <input type="text" name="hl_value[]"
                                   value="<?php echo htmlspecialchars($hl['value'] ?? ''); ?>"
                                   placeholder="e.g. R 45 000">
                        </div>
                        <button type="button" class="btn-remove-row" onclick="removeRow(this)" title="Remove">
                            <i class="fa-solid fa-xmark"></i>
                        </button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <button type="button" class="btn-add-row" id="addHlBtn" onclick="addHlRow()">
                    <i class="fa-solid fa-plus"></i> Add highlight
                </button>

                <p style="font-size:.77rem; color:var(--text-light); margin-top:1rem;">
                    <i class="fa-solid fa-circle-info"></i>&nbsp;
                    Tip: use concrete numbers over vague claims — "R45 000 monthly revenue" beats "Strong revenue". Values can be updated any time after your profile goes live.
                </p>


                <?php /* ════════════════════════════════
                            STEP 6 — Branding
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 6): ?>
                <div class="step-heading">
                    <div class="step-number">Step 6 of 7</div>
                    <h2>Branding</h2>
                    <p>Upload your company logo and cover image to appear on your public profile. Individual SPV campaigns can override these with their own branding.</p>
                </div>

                <div class="form-section-label">Logo</div>
                <div class="field">
                    <label for="logo"><i class="fa-solid fa-circle-user"></i> Company Logo</label>
                    <div class="file-upload-zone" id="logoZone">
                        <input type="file" id="logo" name="logo" accept="image/*"
                               onchange="previewImage(this,'logoPreview','logoImg')">
                        <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div>
                        <div class="file-upload-sub">PNG, JPG, WebP — max 5 MB. Square works best.</div>
                    </div>
                    <div class="preview-wrap" id="logoPreview" style="display:none; text-align:center;">
                        <img id="logoImg" class="preview-logo-img" src="" alt="Logo preview">
                    </div>
                    <?php if ($data['logo']): ?>
                        <a href="<?php echo htmlspecialchars($data['logo']); ?>" target="_blank" class="existing-file-link">
                            <i class="fa-solid fa-image"></i> Current logo — click to view
                        </a>
                    <?php endif; ?>
                </div>

                <div class="form-section-label">Banner</div>
                <div class="field">
                    <label for="banner"><i class="fa-solid fa-panorama"></i> Cover / Banner Image</label>
                    <div class="file-upload-zone" id="bannerZone">
                        <input type="file" id="banner" name="banner" accept="image/*"
                               onchange="previewImage(this,'bannerPreview','bannerImg')">
                        <div class="file-upload-icon"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                        <div class="file-upload-label"><strong>Click to upload</strong> or drag &amp; drop</div>
                        <div class="file-upload-sub">PNG, JPG, WebP — max 5 MB. Landscape ratio (3:1) works best.</div>
                    </div>
                    <div class="preview-wrap" id="bannerPreview" style="display:none;">
                        <img id="bannerImg" class="preview-img" src="" alt="Banner preview">
                    </div>
                    <?php if ($data['banner']): ?>
                        <a href="<?php echo htmlspecialchars($data['banner']); ?>" target="_blank" class="existing-file-link">
                            <i class="fa-solid fa-panorama"></i> Current banner — click to view
                        </a>
                    <?php endif; ?>
                </div>


                <?php /* ════════════════════════════════
                            STEP 7 — Documents (KYC)
                        ════════════════════════════════ */ ?>
                <?php elseif ($step === 7): ?>
                <div class="step-heading">
                    <div class="step-number">Step 7 of 7</div>
                    <h2>Verification Documents</h2>
                    <p>Upload supporting documents so Old Union can verify your business. All documents are stored securely and never shared publicly. Upload what you have — missing documents can delay approval.</p>
                </div>

                <div class="doc-field">
                    <div class="doc-field-icon"><i class="fa-solid fa-file-certificate"></i></div>
                    <div class="doc-field-body">
                        <div class="doc-field-label">Company Registration Certificate</div>
                        <div class="doc-field-hint">Certificate of incorporation or equivalent from CIPC or your local business registrar. PDF or image · max 5 MB.</div>
                        <input type="file" name="registration_document" accept=".pdf,image/*">
                        <?php if ($data['registration_document']): ?>
                            <a href="<?php echo htmlspecialchars($data['registration_document']); ?>" target="_blank" class="existing-file-link">
                                <i class="fa-solid fa-file"></i> Uploaded — view
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="doc-field">
                    <div class="doc-field-icon"><i class="fa-solid fa-map-pin"></i></div>
                    <div class="doc-field-body">
                        <div class="doc-field-label">Proof of Business Address</div>
                        <div class="doc-field-hint">Utility bill, lease agreement, or bank statement showing the company address — not older than 3 months. Max 5 MB.</div>
                        <input type="file" name="proof_of_address" accept=".pdf,image/*">
                        <?php if ($data['proof_of_address']): ?>
                            <a href="<?php echo htmlspecialchars($data['proof_of_address']); ?>" target="_blank" class="existing-file-link">
                                <i class="fa-solid fa-file"></i> Uploaded — view
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="doc-field">
                    <div class="doc-field-icon"><i class="fa-solid fa-id-card"></i></div>
                    <div class="doc-field-body">
                        <div class="doc-field-label">Director / CEO ID Document</div>
                        <div class="doc-field-hint">Government-issued ID, passport, or driver's licence of the primary director or CEO. Max 5 MB.</div>
                        <input type="file" name="director_id_document" accept=".pdf,image/*">
                        <?php if ($data['director_id_document']): ?>
                            <a href="<?php echo htmlspecialchars($data['director_id_document']); ?>" target="_blank" class="existing-file-link">
                                <i class="fa-solid fa-file"></i> Uploaded — view
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="doc-field">
                    <div class="doc-field-icon"><i class="fa-solid fa-receipt"></i></div>
                    <div class="doc-field-body">
                        <div class="doc-field-label">Tax Clearance Certificate <span style="font-weight:400;color:var(--text-light);">(optional)</span></div>
                        <div class="doc-field-hint">Good standing certificate or tax clearance pin from SARS or your local tax authority. Max 5 MB.</div>
                        <input type="file" name="tax_clearance_document" accept=".pdf,image/*">
                        <?php if ($data['tax_clearance_document']): ?>
                            <a href="<?php echo htmlspecialchars($data['tax_clearance_document']); ?>" target="_blank" class="existing-file-link">
                                <i class="fa-solid fa-file"></i> Uploaded — view
                            </a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php endif; ?>


                <!-- ═══════════════════════════════════════
                     ACTION BUTTONS
                ════════════════════════════════════════ -->
                <div class="step-actions">
                    <div class="step-actions-left">
                        <?php if ($step > 1): ?>
                            <button type="submit" class="btn btn-back" onclick="setAction('back')">
                                <i class="fa-solid fa-arrow-left"></i> Back
                            </button>
                        <?php else: ?>
                            <a href="/app/company/dashboard.php?uuid=<?php echo urlencode($uuid); ?>" class="btn btn-ghost">
                                <i class="fa-solid fa-xmark"></i> Cancel
                            </a>
                        <?php endif; ?>
                    </div>
                    <div class="step-actions-right">
                        <?php if ($step < 7): ?>
                            <button type="submit" class="btn btn-save" onclick="setAction('next')">
                                <i class="fa-solid fa-floppy-disk"></i> Save &amp; Continue
                            </button>
                            <button type="submit" class="btn btn-primary" onclick="setAction('next')">
                                Continue <i class="fa-solid fa-arrow-right"></i>
                            </button>
                        <?php else: ?>
                            <button type="submit" class="btn btn-save" onclick="setAction('next')">
                                <i class="fa-solid fa-floppy-disk"></i> Save Draft
                            </button>
                            <button type="submit" class="btn btn-amber" onclick="setAction('submit')" id="submitBtn">
                                <i class="fa-solid fa-paper-plane"></i> Submit for Verification
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
    </main>
</div>

<script>
/* ── Helpers ─────────────────────────────────── */
function setAction(val) {
    document.getElementById('actionInput').value = val;
}

function previewImage(input, wrapId, imgId) {
    const wrap = document.getElementById(wrapId);
    const img  = document.getElementById(imgId);
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => { img.src = e.target.result; wrap.style.display = 'block'; };
        reader.readAsDataURL(input.files[0]);
    }
}

function showFileName(input, labelId) {
    const label = document.getElementById(labelId);
    if (input.files && input.files[0] && label) {
        label.innerHTML = '<strong>' + input.files[0].name + '</strong> selected';
    }
}

/* ── Drag-over styling ───────────────────────── */
document.querySelectorAll('.file-upload-zone').forEach(zone => {
    zone.addEventListener('dragover',  e => { e.preventDefault(); zone.classList.add('dragover'); });
    zone.addEventListener('dragleave', ()  => zone.classList.remove('dragover'));
    zone.addEventListener('drop',      e => { e.preventDefault(); zone.classList.remove('dragover'); });
});

/* ── Dynamic row: shared remove ─────────────── */
function removeRow(btn) {
    btn.closest('.dynamic-row').remove();
    checkHlMax();
}

/* ── Highlights: add row ─────────────────────── */
const MAX_HIGHLIGHTS = 8;

function addHlRow() {
    const container = document.getElementById('hlRows');
    if (!container) return;
    if (container.querySelectorAll('.dynamic-row').length >= MAX_HIGHLIGHTS) return;
    const row = document.createElement('div');
    row.className = 'dynamic-row hl-row';
    row.innerHTML = `
        <div>
            <input type="text" name="hl_label[]" placeholder="e.g. Monthly Revenue">
        </div>
        <div class="hl-value-col">
            <input type="text" name="hl_value[]" placeholder="e.g. R 45 000">
        </div>
        <button type="button" class="btn-remove-row" onclick="removeRow(this)" title="Remove">
            <i class="fa-solid fa-xmark"></i>
        </button>`;
    container.appendChild(row);
    row.querySelector('input').focus();
    checkHlMax();
}

function checkHlMax() {
    const btn       = document.getElementById('addHlBtn');
    const container = document.getElementById('hlRows');
    if (!btn || !container) return;
    const count = container.querySelectorAll('.dynamic-row').length;
    btn.disabled = count >= MAX_HIGHLIGHTS;
    if (count >= MAX_HIGHLIGHTS) {
        btn.innerHTML = '<i class="fa-solid fa-lock"></i> Maximum 8 highlights reached';
    } else {
        btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add highlight';
    }
}

/* ── Location selects (Step 3) ───────────────── */
(function () {
    const LOC = window.SA_LOCATIONS;
    if (!LOC) return;

    const selProvince     = document.getElementById('province');
    const selMunicipality = document.getElementById('municipality');
    const selCity         = document.getElementById('city');
    const selSuburb       = document.getElementById('suburb');
    if (!selProvince) return;

    const INIT = {
        municipality: <?php echo json_encode($data['municipality'] ?? ''); ?>,
        city:         <?php echo json_encode($data['city']         ?? ''); ?>,
        suburb:       <?php echo json_encode($data['suburb']       ?? ''); ?>,
    };

    function fillSelect(sel, items, placeholder, selected) {
        sel.innerHTML = `<option value="">${placeholder}</option>`;
        (items || []).forEach(item => {
            const opt = document.createElement('option');
            opt.value = item; opt.textContent = item;
            if (item === selected) opt.selected = true;
            sel.appendChild(opt);
        });
        sel.disabled = !items || items.length === 0;
    }

    function clearSelect(sel, placeholder) {
        sel.innerHTML = `<option value="">${placeholder}</option>`;
        sel.disabled = true;
    }

    function onProvinceChange() {
        const munis = LOC.municipalities[selProvince.value] || [];
        fillSelect(selMunicipality, munis, '— Select municipality —', INIT.municipality);
        clearSelect(selCity,   '— Select city —');
        clearSelect(selSuburb, '— Select suburb —');
        if (munis.length && INIT.municipality) onMunicipalityChange();
    }

    function onMunicipalityChange() {
        const cities = LOC.cities[selMunicipality.value] || [];
        fillSelect(selCity, cities, '— Select city —', INIT.city);
        clearSelect(selSuburb, '— Select suburb —');
        if (cities.length && INIT.city) onCityChange();
    }

    function onCityChange() {
        const suburbs = LOC.suburbs[selCity.value] || [];
        fillSelect(selSuburb, suburbs, '— Select suburb —', INIT.suburb);
    }

    selProvince.addEventListener('change',     () => { INIT.municipality = INIT.city = INIT.suburb = ''; onProvinceChange(); });
    selMunicipality.addEventListener('change', () => { INIT.city = INIT.suburb = ''; onMunicipalityChange(); });
    selCity.addEventListener('change',         () => { INIT.suburb = ''; onCityChange(); });

    if (selProvince.value) onProvinceChange();
})();

/* ── Submit confirmation (Step 7) ────────────── */
const submitBtn = document.getElementById('submitBtn');
if (submitBtn) {
    submitBtn.addEventListener('click', function (e) {
        if (!confirm('Submit this company for verification?\n\nYou will not be able to edit it until the Old Union team has reviewed your documents. This usually takes 1–3 business days.')) {
            e.preventDefault();
        }
    });
}

/* ── Animate card on back navigation ─────────── */
const card = document.getElementById('stepCard');
<?php if (!empty($_POST['action']) && $_POST['action'] === 'back'): ?>
card.classList.add('going-back');
<?php endif; ?>

/* ── Init on load ────────────────────────────── */
document.addEventListener('DOMContentLoaded', function () {
    checkHlMax();
});
</script>
</body>
</html>