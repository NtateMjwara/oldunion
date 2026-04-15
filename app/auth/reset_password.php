<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

$action = $_GET['action'] ?? '';

if ($action === 'request') {
    handleRequest();
} elseif ($action === 'reset') {
    showResetForm();
} else {
    redirect('/app/auth/forgot_password.php');
}

function handleRequest() {
    // CSRF already validated
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        redirect('/app/auth/forgot_password.php?error=Invalid email');
    }

    $pdo = Database::getInstance();
    $user = getUserByEmail($email);
    if ($user) {
        $token = generateToken();
        $expiry = date('Y-m-d H:i:s', time() + 3600); // 1 hour
        $stmt = $pdo->prepare("UPDATE users SET reset_token = :token, reset_token_expiry = :expiry WHERE id = :id");
        $stmt->execute(['token' => $token, 'expiry' => $expiry, 'id' => $user['id']]);

        $reset_link = SITE_URL . "/app/auth/reset_password.php?action=reset&token=$token";
        $body = "Click here to reset your password: <a href=\"$reset_link\">$reset_link</a>";
        sendEmail($email, 'Password Reset', $body);
    }

    // Always show success to prevent email enumeration
    redirect('/app/auth/forgot_password.php?sent=1');
}

function showResetForm() {
    $token = $_GET['token'] ?? '';
    if (empty($token)) {
        redirect('/app/auth/forgot_password.php');
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        redirect('/app/auth/forgot_password.php?error=Invalid or expired token');
    }

    // Display form
    $csrf_token = generateCSRFToken();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Set New Password</title>
    </head>
    <body>
        <div class="container">
            <h2>Set New Password</h2>
            <form method="POST" action="reset_password.php?action=update">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
                <div>
                    <label for="password">New Password</label>
                    <input type="password" id="password" name="password" required minlength="8">
                    <small>Min 8 characters, with uppercase, lowercase, number, and special character.</small>
                </div>
                <div>
                    <label for="confirm_password">Confirm Password</label>
                    <input type="password" id="confirm_password" name="confirm_password" required>
                </div>
                <button type="submit">Reset Password</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}

// Handle the actual password update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update') {
    // CSRF already validated
    $token = $_POST['token'] ?? '';
    $password = $_POST['password'] ?? '';
    $confirm = $_POST['confirm_password'] ?? '';

    // Validate password complexity again
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $special   = preg_match('@[^\w]@', $password);

    if (!$uppercase || !$lowercase || !$number || !$special || strlen($password) < 8) {
        redirect("/app/auth/reset_password.php?action=reset&token=$token&error=Password does not meet requirements");
    }

    if ($password !== $confirm) {
        redirect("/app/auth/reset_password.php?action=reset&token=$token&error=Passwords do not match");
    }

    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()");
    $stmt->execute(['token' => $token]);
    $user = $stmt->fetch();

    if (!$user) {
        redirect('/app/auth/forgot_password.php?error=Invalid or expired token');
    }

    $hash = password_hash($password, PASSWORD_ALGO);
    $stmt = $pdo->prepare("UPDATE users SET password_hash = :hash, reset_token = NULL, reset_token_expiry = NULL WHERE id = :id");
    $stmt->execute(['hash' => $hash, 'id' => $user['id']]);

    redirect('/app/auth/login.php?reset=1');
}