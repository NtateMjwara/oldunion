<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/super_admin.php';

//if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
//    redirect(SITE_URL . '/login.php');
//}

$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$userId) {
    redirect('index.php');
}

$userManager = new UserManager();
$user = $userManager->getUserById($userId);
if (!$user) {
    $_SESSION['error'] = 'User not found.';
    redirect('index.php');
}

$error = null;
$success = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF protection
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid CSRF token.';
    } else {
        try {
            // Update email if changed
            $newEmail = trim($_POST['email'] ?? '');
            if ($newEmail !== $user['email']) {
                $userManager->updateUserEmail($userId, $newEmail);
            }

            // Update role if changed
            $newRole = $_POST['role'] ?? '';
            if ($newRole !== $user['role']) {
                $userManager->changeUserRole($userId, $newRole);
            }

            // Update status if changed
            $newStatus = $_POST['status'] ?? '';
            if ($newStatus !== $user['status']) {
                if ($newStatus === 'active') {
                    $userManager->activateUser($userId);
                } elseif ($newStatus === 'suspended') {
                    $userManager->suspendUser($userId);
                }
                // 'deleted' is handled separately, not in edit
            }

            // Update verified flag if changed
            $newVerified = isset($_POST['email_verified']) ? 1 : 0;
            if ($newVerified != $user['email_verified']) {
                if ($newVerified) {
                    $userManager->verifyUser($userId);
                } else {
                    // You might want a method to unverify; not provided in UserManager; add if needed.
                    // For simplicity, we'll just set it via direct query (but better to add method)
                    $db = Database::getInstance();
                    $stmt = $db->prepare('UPDATE users SET email_verified = 0 WHERE id = :id');
                    $stmt->execute([':id' => $userId]);
                }
            }

            $_SESSION['message'] = "User updated successfully.";
            redirect('index.php');
        } catch (Exception $e) {
            $error = $e->getMessage();
        }
    }
}

// Generate CSRF token if not exists
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = generateToken();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Edit User</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>Edit User: <?= htmlspecialchars($user['email']) ?></h1>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <form method="post">
        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
        <div class="mb-3">
            <label for="email" class="form-label">Email</label>
            <input type="email" class="form-control" id="email" name="email" value="<?= htmlspecialchars($user['email']) ?>" required>
        </div>
        <div class="mb-3">
            <label for="role" class="form-label">Role</label>
            <select class="form-select" id="role" name="role">
                <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>User</option>
                <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Admin</option>
                <option value="super_admin" <?= $user['role'] === 'super_admin' ? 'selected' : '' ?>>Super Admin</option>
            </select>
        </div>
        <div class="mb-3">
            <label for="status" class="form-label">Status</label>
            <select class="form-select" id="status" name="status">
                <option value="active" <?= $user['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="suspended" <?= $user['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                <!-- Deleted users are not editable, so we don't show 'deleted' -->
            </select>
        </div>
        <div class="mb-3 form-check">
            <input type="checkbox" class="form-check-input" id="email_verified" name="email_verified" <?= $user['email_verified'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="email_verified">Email Verified</label>
        </div>
        <button type="submit" class="btn btn-primary">Update User</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
</body>
</html>