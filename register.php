<?php
//require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
$csrf_token = generateCSRFToken();

// ── US-103 PATCH A: Pre-fill email + preserve invite token ──────────
// claim_invite.php stores the raw hex token and the invited email in
// session before redirecting here. We also accept them from the query
// string so the link from external_invite.php works directly even if
// the session was started on a different browser tab.
$prefillEmail  = '';
$pendingInvite = '';

$qsEmail  = trim($_GET['email']  ?? '');
$qsInvite = trim($_GET['invite'] ?? '');

if ($qsEmail && filter_var($qsEmail, FILTER_VALIDATE_EMAIL)) {
    $prefillEmail = $qsEmail;
}
// Session value wins if both are present (set by claim_invite.php)
if (!empty($_SESSION['pending_invite_email'])) {
    $prefillEmail = $_SESSION['pending_invite_email'];
}
if (!empty($_SESSION['pending_invite_token'])) {
    $pendingInvite = $_SESSION['pending_invite_token'];
} elseif ($qsInvite) {
    $pendingInvite = $qsInvite;
}
// ────────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>X Union | Register</title>
    
    <style>
        :root {
            --primary: #0b2545;
            --maroon: #800020;
            --light-bg: #f8f9fb;
            --border: #e4e7ec;
            --text-dark: #101828;
            --text-muted: #667085;
            --error-bg: #fef2f2;
            --error-border: #fecaca;
            --error-text: #991b1b;
            --success: #0e7b0e;
        }

        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
            font-family: "Inter", "Segoe UI", sans-serif;
        }

        body {
            background: var(--light-bg);
            color: var(--text-dark);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .register-card {
            background: white;
            border: 1px solid var(--border);
            padding: 40px 35px;
            width: 100%;
            max-width: 500px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .register-card h2 {
            font-size: 26px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .register-card .subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 32px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
        }

        .accent-line {
            height: 4px;
            background: var(--maroon);
            width: 60px;
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 24px;
        }

        label {
            display: block;
            font-size: 13px;
            font-weight: 500;
            color: var(--text-muted);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }

        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            background: white;
            font-size: 15px;
            color: var(--text-dark);
            transition: border 0.2s;
        }

        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: var(--primary);
        }

        small {
            display: block;
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* Password requirements list */
        .password-requirements {
            list-style: none;
            margin-top: 8px;
            font-size: 13px;
        }

        .password-requirements li {
            margin-bottom: 5px;
            color: var(--text-muted);
            display: flex;
            align-items: center;
        }

        .requirement-met {
            color: var(--success);
        }

        .requirement-met .req-icon {
            color: var(--success);
            margin-right: 8px;
            font-weight: bold;
        }

        .req-icon {
            display: inline-block;
            width: 20px;
            margin-right: 8px;
            font-size: 14px;
        }

        .confirm-match {
            margin-top: 5px;
            font-size: 13px;
            display: flex;
            align-items: center;
        }

        .match-met {
            color: var(--success);
        }

        button {
            width: 100%;
            padding: 14px;
            background: var(--maroon);
            border: none;
            color: white;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: background 0.2s;
            border: 1px solid var(--maroon);
            margin-top: 10px;
        }

        button:hover {
            background: #660018;
        }

        .login-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: var(--text-muted);
        }

        .login-link a {
            color: var(--primary);
            text-decoration: none;
            font-weight: 500;
        }

        .login-link a:hover {
            text-decoration: underline;
        }

        .alert-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            padding: 14px 16px;
            margin-bottom: 28px;
            font-size: 14px;
        }

        #client-error {
            background: var(--error-bg);
            border: 1px solid var(--error-border);
            color: var(--error-text);
            padding: 14px 16px;
            margin-bottom: 28px;
            font-size: 14px;
        }

        #client-error ul {
            margin-top: 8px;
            margin-left: 18px;
        }

        #client-error li {
            margin-bottom: 4px;
        }
    </style>
</head>
<body>
    <div class="register-card">
        <h2>X UNION</h2>
        <div class="accent-line"></div>
        <div class="subtitle">Create an account</div>

        <!-- Server-side error message -->
        <?php if (isset($_GET['error'])): ?>
            <div class="alert-error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>

        <!-- Client-side error message (hidden by default) -->
        <div id="client-error" style="display: none;"></div>

        <form method="POST" action="authentication.php?action=register" id="register-form">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required maxlength="255"
                       placeholder="your.name@example.com"
                       value="<?php echo htmlspecialchars($prefillEmail); ?>"
                       <?php echo $prefillEmail ? 'readonly' : ''; ?>>
                <?php if ($prefillEmail): ?>
                    <small>This address was specified in your invitation and cannot be changed.</small>
                <?php endif; ?>
            </div>

            <?php if ($pendingInvite): ?>
            <!-- US-103: carry the invite token through the form POST so
                 authentication.php can write it to session even if the
                 browser clears session between pages (rare but possible). -->
            <input type="hidden" name="invite_token" value="<?php echo htmlspecialchars($pendingInvite); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required minlength="8">
                <!-- Password requirement checklist -->
                <ul class="password-requirements" id="password-reqs">
                    <li id="req-length" class="requirement">
                        <span class="req-icon">○</span> At least 8 characters
                    </li>
                    <li id="req-uppercase" class="requirement">
                        <span class="req-icon">○</span> At least one uppercase letter
                    </li>
                    <li id="req-lowercase" class="requirement">
                        <span class="req-icon">○</span> At least one lowercase letter
                    </li>
                    <li id="req-number" class="requirement">
                        <span class="req-icon">○</span> At least one number
                    </li>
                    <li id="req-special" class="requirement">
                        <span class="req-icon">○</span> At least one special character
                    </li>
                </ul>
            </div>

            <div class="form-group">
                <label for="confirm_password">Confirm Password</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
                <div id="confirm-match" class="confirm-match">
                    <span class="req-icon">○</span> Passwords match
                </div>
            </div>

            <button type="submit">Register</button>
        </form>

        <div class="login-link">
            Already have an account? <a href="login.php">Login</a>
        </div>
    </div>
    <script src="../assets/js/register.js"></script>

</body>
</html>