<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
$csrf_token = generateCSRFToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>X Union | Forgot Password</title>
    <style>
        :root {
            --primary: #0b2545;
            --maroon: #800020;
            --light-bg: #f8f9fb;
            --border: #e4e7ec;
            --text-dark: #101828;
            --text-muted: #667085;
            --success-bg: #ecfdf3;
            --success-border: #abefc6;
            --success-text: #067647;
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

        .forgot-card {
            background: white;
            border: 1px solid var(--border);
            padding: 40px 35px;
            width: 100%;
            max-width: 480px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }

        .forgot-card h2 {
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

        input[type="email"] {
            width: 100%;
            padding: 12px 14px;
            border: 1px solid var(--border);
            background: white;
            font-size: 15px;
            color: var(--text-dark);
            transition: border 0.2s;
        }

        input[type="email"]:focus {
            outline: none;
            border-color: var(--primary);
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
            margin-top: 8px;
        }

        button:hover {
            background: #660018;
        }

        .back-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
        }

        .back-link a {
            color: var(--text-muted);
            text-decoration: none;
        }

        .back-link a:hover {
            color: var(--primary);
        }

        .alert.success {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-text);
            padding: 14px 16px;
            margin-bottom: 28px;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class="forgot-card">
        <h2>X UNION</h2>
        <div class="accent-line"></div>
        <div class="subtitle">Reset your password</div>

        <?php if (isset($_GET['sent'])): ?>
            <div class="alert success">If that email exists, a reset link has been sent.</div>
        <?php endif; ?>

        <form method="POST" action="reset_password.php?action=request">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required placeholder="your.name@example.com">
            </div>

            <button type="submit">Send Reset Link</button>
        </form>

        <div class="back-link">
            <a href="login.php">← Back to login</a>
        </div>
    </div>
</body>
</html>