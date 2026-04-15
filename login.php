<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
$csrf_token = generateCSRFToken();

// ── US-103: Capture pending invite token so it survives the POST.
// Session alone is unreliable because session_regenerate_id() in
// authentication.php may silently drop pre-login session data.
// Priority: existing session value → ?invite= query param.
$pendingInviteToken = trim(
    $_SESSION['pending_invite_token']
    ?? trim($_GET['invite'] ?? '')
);

// ── Capture ?redirect= so authentication.php can honour it after login.
// accept_invite.php bounces unauthenticated users here with a ?redirect=
// pointing back to itself. We whitelist to relative paths only to prevent
// open-redirect abuse — any value not starting with '/' is discarded.
$redirectAfterLogin = '';
$rawRedirect = trim($_GET['redirect'] ?? '');
if ($rawRedirect !== '' && str_starts_with($rawRedirect, '/')) {
    $redirectAfterLogin = $rawRedirect;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | Old Union</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="../assets/images/logo/icons.png">
    <link rel="apple-touch-icon" href="../assets/images/logo/icons.png">
    <meta name="msapplication-TileImage" content="../assets/images/logo/icons.png">
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
            --success-bg: #ecfdf3;
            --success-border: #abefc6;
            --success-text: #067647;
            --warning-bg: #fffaeb;
            --warning-border: #fedf89;
            --warning-text: #b54708;
        }

        *, *::before, *::after {
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

        .login-card {
            background: white;
            border: 1px solid var(--border);
            padding: 40px 35px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .login-card h2 {
            font-size: 26px;
            font-weight: 600;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .accent-line {
            height: 4px;
            background: var(--maroon);
            width: 60px;
            margin-bottom: 20px;
        }

        .subtitle {
            font-size: 14px;
            color: var(--text-muted);
            margin-bottom: 32px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
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

        button {
            width: 100%;
            padding: 14px;
            background: var(--maroon);
            border: 1px solid var(--maroon);
            color: white;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            cursor: pointer;
            transition: background 0.2s;
            margin-top: 8px;
        }

        button:hover { background: #660018; }

        .links {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
        }

        .links a    { color: var(--text-muted); text-decoration: none; margin: 0 8px; }
        .links a:hover { color: var(--primary); }
        .links span { color: var(--border); }

        .alert {
            padding: 14px 16px;
            margin-bottom: 28px;
            font-size: 14px;
            border: 1px solid transparent;
        }

        .alert.error   { background: var(--error-bg);  border-color: var(--error-border);  color: var(--error-text); }
        .alert.success { background: var(--success-bg); border-color: var(--success-border); color: var(--success-text); }
        .alert.warning { background: var(--warning-bg); border-color: var(--warning-border); color: var(--warning-text); }
    </style>
</head>
<body>
    <div class="login-card">
        <h2>OLD UNION</h2>
        <div class="accent-line"></div>
        <div class="subtitle">Sign in to your dashboard</div>

        <?php if (isset($_GET['error'])): ?>
            <div class="alert error"><?php echo htmlspecialchars($_GET['error']); ?></div>
        <?php endif; ?>
        <?php if (isset($_GET['registered'])): ?>
            <div class="alert success">Registration successful! Please check your email to verify your account.</div>
        <?php endif; ?>
        <?php if (isset($_GET['verified'])): ?>
            <div class="alert success">Email verified! You can now log in.</div>
        <?php endif; ?>
        <?php if (isset($_GET['timeout'])): ?>
            <div class="alert warning">Session expired. Please log in again.</div>
        <?php endif; ?>

        <form method="POST" action="authentication.php?action=login">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <?php if (!empty($pendingInviteToken)): ?>
            <!-- US-103: carry the raw hex invite token (64 chars, set by
                 claim_invite.php) through the POST body so it survives
                 session_regenerate_id() in authentication.php.
                 Note: when accept_invite.php bounces an unauthenticated user
                 here it writes a UUID (36 chars) into session — that case is
                 handled via redirect_after_login below, NOT this field.
                 authentication.php distinguishes the two by token length. -->
            <input type="hidden" name="invite_token" value="<?php echo htmlspecialchars($pendingInviteToken); ?>">
            <?php endif; ?>

            <?php if (!empty($redirectAfterLogin)): ?>
            <!-- Carry ?redirect= through the POST so authentication.php can
                 return the user to the right page after login. Used by
                 accept_invite.php when it bounces an unauthenticated user. -->
            <input type="hidden" name="redirect_after_login" value="<?php echo htmlspecialchars($redirectAfterLogin); ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required>
            </div>

            <div class="form-group">
                <label for="password">Password</label>
                <input type="password" id="password" name="password" required>
            </div>

            <button type="submit">Login</button>
        </form>

        <div class="links">
            <a href="forgot_password.php">Forgot password?</a>
            <span>|</span>
            <a href="register.php">Create account</a>
        </div>
    </div>
</body>
</html>
