<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/mailer.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    if (!$email) {
        redirect('/app/auth/login.php?error=Invalid email');
    }

    $pdo = Database::getInstance();
    $user = getUserByEmail($email);

    if ($user && !$user['email_verified']) {
        // Generate new token
        $token = generateToken();
        $stmt = $pdo->prepare("UPDATE users SET verification_token = :token WHERE id = :id");
        $stmt->execute(['token' => $token, 'id' => $user['id']]);

        $verification_link = SITE_URL . "/app/auth/verify.php?token=$token";
        $body = "Please verify your email by clicking this link: <a href=\"$verification_link\">$verification_link</a>";
        sendEmail($email, 'Verify Your Email', $body);
    }

    // Always show success
    redirect('/app/auth/login.php?resent=1');
} else {
    // Show form
    $csrf_token = generateCSRFToken();
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <title>Resend Verification Email</title>
    </head>
    <body>
        <div class="container">
            <h2>Resend Verification Email</h2>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <div>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" required>
                </div>
                <button type="submit">Resend</button>
            </form>
        </div>
    </body>
    </html>
    <?php
}