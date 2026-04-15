<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/super_admin.php';

//if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
//    redirect(SITE_URL . '/login.php');
//}

// CSRF protection
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid CSRF token.';
    redirect('index.php');
}

$action = $_POST['action'] ?? '';
$userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

if (!$userId) {
    $_SESSION['error'] = 'Invalid user ID.';
    redirect('index.php');
}

$userManager = new UserManager();

try {
    switch ($action) {
        case 'delete':
            $userManager->deleteUser($userId);
            $_SESSION['message'] = 'User deleted successfully.';
            break;
        case 'verify':
            $userManager->verifyUser($userId);
            $_SESSION['message'] = 'User verified successfully.';
            break;
        case 'suspend':
            $userManager->suspendUser($userId);
            $_SESSION['message'] = 'User suspended.';
            break;
        case 'activate':
            $userManager->activateUser($userId);
            $_SESSION['message'] = 'User activated.';
            break;
        case 'reset_password':
            // Generate a random password or allow custom input
            $newPassword = bin2hex(random_bytes(8)); // 16 character random password
            $userManager->resetUserPassword($userId, $newPassword);
            // Optionally send email with new password
            // For now, just show it in message
            $_SESSION['message'] = "Password reset successfully. New password: $newPassword (please communicate to user)";
            break;
        default:
            $_SESSION['error'] = 'Invalid action.';
    }
} catch (Exception $e) {
    $_SESSION['error'] = $e->getMessage();
}

redirect('index.php');