<?php

/**
 * Unified handler for registration and login.
 */
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limiter.php';
require_once '../includes/mailer.php';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirect('/app/auth/login.php');
}

if ($action === 'register') {
    handleRegistration();
} elseif ($action === 'login') {
    handleLogin();
} else {
    redirect('/app/auth/login.php');
}

// ============================================================
// REGISTRATION
// ============================================================
function handleRegistration() {
    $email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm_password'] ?? '';

    if (!$email) {
        redirect('/app/auth/register.php?error=Invalid email address');
    }

    // Password complexity
    $uppercase = preg_match('@[A-Z]@', $password);
    $lowercase = preg_match('@[a-z]@', $password);
    $number    = preg_match('@[0-9]@', $password);
    $special   = preg_match('@[^\w]@', $password);

    if (!$uppercase || !$lowercase || !$number || !$special || strlen($password) < 8) {
        redirect('/app/auth/register.php?error=Password must be at least 8 characters and include uppercase, lowercase, number, and special character');
    }

    if ($password !== $confirm) {
        redirect('/app/auth/register.php?error=Passwords do not match');
    }

    $pdo  = Database::getInstance();
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = :email");
    $stmt->execute(['email' => $email]);
    if ($stmt->fetch()) {
        redirect('/app/auth/register.php?error=Email already registered');
    }

    $password_hash      = password_hash($password, PASSWORD_ALGO);
    $verification_token = generateToken();
    $uuid               = generateUuidV4();

    $stmt = $pdo->prepare("
        INSERT INTO users (uuid, email, password_hash, verification_token, created_at)
        VALUES (:uuid, :email, :password_hash, :verification_token, NOW())
    ");
    $success = $stmt->execute([
        'uuid'               => $uuid,
        'email'              => $email,
        'password_hash'      => $password_hash,
        'verification_token' => $verification_token,
    ]);

    if ($success) {
        $userId = $pdo->lastInsertId();

        // Create wallet
        $pdo->prepare("INSERT INTO user_wallets (user_id) VALUES (?)")->execute([$userId]);

        // ── US-103 PATCH B: preserve invite token in session ─────────
        // POST body is the authoritative carrier (register.php emits a
        // hidden invite_token field). Session is a secondary fallback.
        // We do NOT backfill user_id here — the account is not verified
        // yet. The claim happens in verify.php via us103_claimExternalInvite().
        $postInviteToken = trim($_POST['invite_token'] ?? '');
        if (!empty($postInviteToken)) {
            $_SESSION['pending_invite_token'] = $postInviteToken;
        }
        // ─────────────────────────────────────────────────────────────

        $verification_link = SITE_URL . "/app/auth/verify.php?token=$verification_token";
        $body = "Please verify your email by clicking this link: <a href=\"$verification_link\">$verification_link</a>";
        sendEmail($email, 'Verify Your Email', $body);

        redirect('/app/auth/login.php?registered=1');
    } else {
        redirect('/app/auth/register.php?error=Registration failed');
    }
}

// ============================================================
// LOGIN
// ============================================================
function handleLogin() {
    $email    = filter_var($_POST['email'] ?? '', FILTER_VALIDATE_EMAIL);
    $password = $_POST['password'] ?? '';
    $ip       = $_SERVER['REMOTE_ADDR'];

    if (!$email || !$password) {
        redirect('/app/auth/login.php?error=Invalid credentials');
    }

    if (!checkRateLimit($email, $ip)) {
        redirect('/app/auth/login.php?error=Too many failed attempts. Account locked for 15 minutes.');
    }

    $pdo  = Database::getInstance();
    $user = getUserByEmail($email);

    if (!$user) {
        recordFailedAttempt($email, $ip);
        redirect('/app/auth/login.php?error=Invalid credentials');
    }

    if ($user['status'] !== 'active') {
        recordFailedAttempt($email, $ip);
        redirect('/app/auth/login.php?error=Account is not active');
    }

    if (!$user['email_verified']) {
        recordFailedAttempt($email, $ip);
        redirect('/app/auth/login.php?error=Please verify your email first');
    }

    if ($user['locked_until'] && strtotime($user['locked_until']) > time()) {
        redirect('/app/auth/login.php?error=Account is locked. Try again later.');
    }

    if (!password_verify($password, $user['password_hash'])) {
        recordFailedAttempt($email, $ip);
        redirect('/app/auth/login.php?error=Invalid credentials');
    }

    // ── US-103: Read ALL post-login routing values BEFORE
    // session_regenerate_id(). On some PHP/filesystem configurations,
    // session_regenerate_id(true) deletes the old session file immediately,
    // silently losing any data written before login (e.g. by claim_invite.php
    // or accept_invite.php). We capture everything here so nothing is lost.
    //
    // Three distinct scenarios after login:
    //
    //   A. New external-invite user (came via claim_invite.php → register):
    //      invite_token is a 64-char hex string in $_POST (carried by the
    //      hidden field in login.php) or $_SESSION. verify.php has already
    //      claimed the invite and redirected to accept_invite.php, which
    //      bounced them here because they weren't logged in yet.
    //      The redirect_after_login field will be set to /app/invest/accept_invite.php?token=<uuid>.
    //      We honour that redirect directly — do NOT send to claim_invite.php.
    //
    //   B. Existing user who clicked an external invite link while logged out:
    //      claim_invite.php set the session hex token and sent them to login.
    //      No redirect_after_login, but invite_token (hex, 64 chars) is present.
    //      Send to claim_invite.php?token=<hex> so it can backfill user_id
    //      and forward to accept_invite.php.
    //
    //   C. Normal login — no invite token, no redirect. Send to /app.

    // Capture pending invite token (hex) — POST wins over session
    $pendingHexToken = trim($_POST['invite_token'] ?? '')
                    ?: trim($_SESSION['pending_invite_token'] ?? '');

    // Capture explicit redirect (set by accept_invite.php or other pages)
    // Whitelist: relative paths starting with '/' only — prevents open redirect.
    $redirectAfterLogin = '';
    $rawRedirect = trim($_POST['redirect_after_login'] ?? '');
    if ($rawRedirect !== '' && str_starts_with($rawRedirect, '/')) {
        $redirectAfterLogin = $rawRedirect;
    }

    // Rehash password if algorithm/cost has changed
    if (password_needs_rehash($user['password_hash'], PASSWORD_ALGO)) {
        $new_hash = password_hash($password, PASSWORD_ALGO);
        $pdo->prepare("UPDATE users SET password_hash = :hash WHERE id = :id")
            ->execute(['hash' => $new_hash, 'id' => $user['id']]);
    }

    // Clear rate limiting and record login timestamp
    clearFailedAttempts($email);
    $pdo->prepare("
        UPDATE users
        SET login_attempts = 0, locked_until = NULL, last_login = NOW()
        WHERE id = :id
    ")->execute(['id' => $user['id']]);

    // Regenerate session ID — old session file may be gone after this line
    session_regenerate_id(true);

    // Write fresh session
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_uuid'] = $user['uuid'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['created']   = time();

    // ── Scenario A: explicit redirect (e.g. back to accept_invite.php).
    // This takes priority over the hex token because accept_invite.php has
    // already claimed the invite — claim_invite.php must not run again.
    if (!empty($redirectAfterLogin)) {
        // Restore hex token to session in case downstream pages need it,
        // but do NOT route through claim_invite.php.
        if (!empty($pendingHexToken)) {
            $_SESSION['pending_invite_token'] = $pendingHexToken;
        }
        redirect($redirectAfterLogin);
    }

    // ── Scenario B: hex token present, no explicit redirect.
    // Distinguish a raw claim token (64-char hex) from an invite UUID
    // (36-char, written to session by accept_invite.php's bounce) to avoid
    // sending a UUID into claim_invite.php which looks up by ci.token.
    if (!empty($pendingHexToken) && strlen($pendingHexToken) === 64) {
        $_SESSION['pending_invite_token'] = $pendingHexToken;
        redirect('/app/invest/claim_invite.php?token=' . urlencode($pendingHexToken));
    }

    // ── Scenario C: normal login
    redirect('/app');
}
