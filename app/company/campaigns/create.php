<?php
require_once '../../includes/security.php';
require_once '../../includes/session.php';
require_once '../../includes/csrf.php';
require_once '../../includes/company_functions.php';
require_once '../../includes/database.php';

if (!isLoggedIn()) {
    redirect('/app/auth/login.php');
}

$uuid    = $_GET['uuid'] ?? '';
$company = getCompanyByUuid($uuid);

if (!$company) {
    die('Company not found.');
}

// Only active verified companies can create campaigns
if ($company['status'] !== 'active' || !$company['verified']) {
    redirect("/app/company/dashboard.php?uuid=$uuid&error=not_verified");
}

requireCompanyRole($company['id'], 'admin');

$pdo        = Database::getInstance();
$userId     = $_SESSION['user_id'];
$csrf_token = generateCSRFToken();
$error      = '';

// Fetch user initial for avatar
$stmt = $pdo->prepare("SELECT email FROM users WHERE id = ?");
$stmt->execute([$userId]);
$user        = $stmt->fetch();
$userInitial = $user ? strtoupper(substr($user['email'], 0, 1)) : 'U';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $title = trim($_POST['title'] ?? '');

    if (empty($title)) {
        $error = 'Campaign title is required.';
    } else {
        // Generate UUID v4
        $campaignUuid = generateCompanyUuid(); // reuses same UUID v4 function

        // Create stub campaign — wizard fills everything else in
        // opens_at / closes_at are NOT NULL so we seed sensible defaults
        $stmt = $pdo->prepare("
            INSERT INTO funding_campaigns
                (uuid, company_id, created_by, title, campaign_type,
                 raise_target, raise_minimum, min_contribution,
                 max_contributors, opens_at, closes_at, status)
            VALUES
                (:uuid, :company_id, :created_by, :title, 'revenue_share',
                 0.00, 0.00, 500.00,
                 50, NOW(), DATE_ADD(NOW(), INTERVAL 30 DAY), 'draft')
        ");

        $ok = $stmt->execute([
            'uuid'       => $campaignUuid,
            'company_id' => $company['id'],
            'created_by' => $userId,
            'title'      => $title,
        ]);

        if ($ok) {
            logCompanyActivity($company['id'], $userId, "Created campaign: $title");
            redirect("/app/company/campaigns/wizard.php?uuid=$uuid&cid=$campaignUuid");
        } else {
            $error = 'Failed to create campaign. Please try again.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New Campaign | Old Union</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:opsz,wght@9..40,400;9..40,500;9..40,600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --navy:        #0b2545;
            --navy-mid:    #0f3b7a;
            --navy-light:  #1a56b0;
            --amber:       #f59e0b;
            --amber-dark:  #d97706;
            --surface:     #ffffff;
            --surface-2:   #f8f9fb;
            --border:      #e4e7ec;
            --text:        #101828;
            --text-muted:  #667085;
            --error:       #b91c1c;
            --error-bg:    #fef2f2;
            --error-bdr:   #fecaca;
            --radius:      14px;
            --shadow-card: 0 8px 32px rgba(11,37,69,.08), 0 1px 3px rgba(11,37,69,.06);
            --shadow-btn:  0 4px 12px rgba(15,59,122,.25);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--surface-2);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem;
        }
        .card {
            background: var(--surface);
            border-radius: var(--radius);
            box-shadow: var(--shadow-card);
            border: 1px solid var(--border);
            padding: 2.5rem;
            width: 100%;
            max-width: 560px;
        }
        .card-eyebrow {
            font-size: .78rem;
            font-weight: 600;
            color: var(--amber-dark);
            text-transform: uppercase;
            letter-spacing: .1em;
            margin-bottom: .5rem;
        }
        .card h1 {
            font-family: 'DM Serif Display', serif;
            font-size: 1.9rem;
            color: var(--navy);
            margin-bottom: .4rem;
            line-height: 1.2;
        }
        .card .subtitle {
            font-size: .93rem;
            color: var(--text-muted);
            margin-bottom: 2rem;
            line-height: 1.5;
        }
        .company-chip {
            display: inline-flex;
            align-items: center;
            gap: .4rem;
            background: #eff4ff;
            border: 1px solid #c7d9f8;
            border-radius: 99px;
            padding: .25rem .75rem;
            font-size: .8rem;
            font-weight: 600;
            color: var(--navy-mid);
            margin-bottom: 1.75rem;
        }
        .field { display: flex; flex-direction: column; gap: .45rem; margin-bottom: 1.5rem; }
        .field label {
            font-size: .83rem;
            font-weight: 600;
            color: var(--text);
            display: flex;
            align-items: center;
            gap: .4rem;
        }
        .field label i { color: var(--navy-light); font-size: .8rem; }
        .field input {
            padding: .75rem 1rem;
            border: 1.5px solid var(--border);
            border-radius: 8px;
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            color: var(--text);
            background: var(--surface-2);
            outline: none;
            transition: border-color .2s, box-shadow .2s, background .2s;
        }
        .field input:focus {
            border-color: var(--navy-light);
            background: #fff;
            box-shadow: 0 0 0 3.5px rgba(26,86,176,.1);
        }
        .field .hint { font-size: .77rem; color: #98a2b3; line-height: 1.4; }
        .alert {
            display: flex;
            align-items: center;
            gap: .75rem;
            padding: .85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-size: .88rem;
            font-weight: 500;
            background: var(--error-bg);
            color: var(--error);
            border: 1px solid var(--error-bdr);
        }
        .btn-primary {
            width: 100%;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .5rem;
            padding: .8rem 1.6rem;
            background: var(--navy-mid);
            color: #fff;
            border: none;
            border-radius: 99px;
            font-family: 'DM Sans', sans-serif;
            font-size: .95rem;
            font-weight: 600;
            cursor: pointer;
            box-shadow: var(--shadow-btn);
            transition: background .2s, transform .15s, box-shadow .2s;
        }
        .btn-primary:hover {
            background: var(--navy);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(15,59,122,.3);
        }
        .cancel-link {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: .4rem;
            margin-top: 1.25rem;
            font-size: .88rem;
            color: var(--text-muted);
            text-decoration: none;
            transition: color .2s;
        }
        .cancel-link:hover { color: var(--navy); }
    </style>
</head>
<body>
    <div class="card">
        <div class="card-eyebrow"><i class="fa-solid fa-rocket"></i> &nbsp;New Fundraising Campaign</div>
        <h1>Start a Campaign</h1>
        <p class="subtitle">Give your campaign a working title to get started. You can refine everything — targets, terms, timeline — in the next steps.</p>

        <div class="company-chip">
            <i class="fa-solid fa-building"></i>
            <?php echo htmlspecialchars($company['name']); ?>
        </div>

        <?php if ($error): ?>
            <div class="alert">
                <i class="fa-solid fa-circle-exclamation"></i>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <div class="field">
                <label for="title">
                    <i class="fa-solid fa-pen"></i> Campaign Title
                </label>
                <input
                    type="text"
                    id="title"
                    name="title"
                    maxlength="255"
                    placeholder="e.g. Community Expansion Round 2025"
                    value="<?php echo htmlspecialchars($_POST['title'] ?? ''); ?>"
                    autofocus
                    required
                >
                <span class="hint">This is the headline contributors will see on your listing.</span>
            </div>
            <button type="submit" class="btn-primary">
                <i class="fa-solid fa-arrow-right"></i> Continue to Campaign Setup
            </button>
        </form>

        <a href="/company/dashboard.php?uuid=<?php echo urlencode($uuid); ?>" class="cancel-link">
            <i class="fa-solid fa-arrow-left"></i> Back to dashboard
        </a>
    </div>
</body>
</html>
