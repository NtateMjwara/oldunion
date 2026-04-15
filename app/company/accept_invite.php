<?php
require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/company_functions.php';
require_once '../includes/database.php';

$token = $_GET['token'] ?? '';
if (empty($token)) {
    die('Invalid invitation link.');
}

$pdo = Database::getInstance();

// Fetch invite
$stmt = $pdo->prepare("
    SELECT ci.*, c.name as company_name, c.id as company_id
    FROM company_invites ci
    JOIN companies c ON ci.company_id = c.id
    WHERE ci.token = :token AND ci.status = 'pending' AND ci.expires_at > NOW()
");
$stmt->execute(['token' => $token]);
$invite = $stmt->fetch();

if (!$invite) {
    die('This invitation link is invalid or has expired.');
}

// If user not logged in, redirect to register with email prefilled
if (!isLoggedIn()) {
    $_SESSION['invite_token'] = $token; // store for later
    redirect("/app/auth/register.php?email=" . urlencode($invite['email']) . "&invite=$token");
}

// Logged in user: check if email matches
$currentUserEmail = '';
if (isset($_SESSION['user_id'])) {
    $stmt = $pdo->prepare("SELECT email FROM users WHERE id = :id");
    $stmt->execute(['id' => $_SESSION['user_id']]);
    $user = $stmt->fetch();
    $currentUserEmail = $user['email'];
}

if (strcasecmp($currentUserEmail, $invite['email']) !== 0) {
    die('This invitation was sent to a different email address. Please log in with the correct account.');
}

// Add user as admin
$stmt = $pdo->prepare("
    INSERT INTO company_admins (company_id, user_id, role, added_by, created_at)
    VALUES (:company_id, :user_id, :role, :invited_by, NOW())
");
$stmt->execute([
    'company_id' => $invite['company_id'],
    'user_id'    => $_SESSION['user_id'],
    'role'       => $invite['role'],
    'invited_by' => $invite['invited_by'],
]);

// Mark invite as accepted
$stmt = $pdo->prepare("UPDATE company_invites SET status = 'accepted' WHERE id = :id");
$stmt->execute(['id' => $invite['id']]);

logCompanyActivity($invite['company_id'], $_SESSION['user_id'], "Accepted admin invitation as " . $invite['role']);

// Fetch company uuid and redirect to dashboard
$stmt = $pdo->prepare("SELECT uuid FROM companies WHERE id = ?");
$stmt->execute([$invite['company_id']]);
$company = $stmt->fetch();

redirect("/app/company/dashboard.php?uuid=" . $company['uuid']);
