<?php
session_start();
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/super_admin.php';

// Ensure user is logged in and is super_admin
//if (!isLoggedIn() || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
//    redirect(SITE_URL . '/login.php');
//}

$userManager = new UserManager();
$users = $userManager->getAllUsers();

// Handle success/error messages from session
$message = $_SESSION['message'] ?? null;
$error = $_SESSION['error'] ?? null;
unset($_SESSION['message'], $_SESSION['error']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - User Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container mt-5">
    <h1>User Management</h1>
    <?php if ($message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <div class="mb-3">
        <a href="create_user.php" class="btn btn-primary">Create New User</a>
    </div>

    <table class="table table-striped table-bordered">
        <thead>
            <tr>
                <th>ID</th>
                <th>UUID</th>
                <th>Email</th>
                <th>Role</th>
                <th>Status</th>
                <th>Verified</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($users as $user): ?>
                <tr>
                    <td><?= $user['id'] ?></td>
                    <td><?= htmlspecialchars($user['uuid']) ?></td>
                    <td><?= htmlspecialchars($user['email']) ?></td>
                    <td><?= ucfirst($user['role']) ?></td>
                    <td><?= ucfirst($user['status']) ?></td>
                    <td><?= $user['email_verified'] ? 'Yes' : 'No' ?></td>
                    <td><?= $user['created_at'] ?></td>
                    <td>
                        <a href="edit_user.php?id=<?= $user['id'] ?>" class="btn btn-sm btn-info">Edit</a>
                        <form action="actions.php" method="post" style="display:inline;">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <button type="submit" class="btn btn-sm btn-danger" onclick="return confirm('Are you sure?')">Delete</button>
                        </form>
                        <?php if (!$user['email_verified']): ?>
                            <form action="actions.php" method="post" style="display:inline;">
                                <input type="hidden" name="action" value="verify">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <button type="submit" class="btn btn-sm btn-success">Verify</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($user['status'] === 'suspended'): ?>
                            <form action="actions.php" method="post" style="display:inline;">
                                <input type="hidden" name="action" value="activate">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <button type="submit" class="btn btn-sm btn-success">Activate</button>
                            </form>
                        <?php elseif ($user['status'] === 'active'): ?>
                            <form action="actions.php" method="post" style="display:inline;">
                                <input type="hidden" name="action" value="suspend">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                                <button type="submit" class="btn btn-sm btn-warning">Suspend</button>
                            </form>
                        <?php endif; ?>
                        <form action="actions.php" method="post" style="display:inline;">
                            <input type="hidden" name="action" value="reset_password">
                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?? '' ?>">
                            <button type="submit" class="btn btn-sm btn-secondary" onclick="return confirm('Reset password for <?= htmlspecialchars($user['email']) ?>? New password will be sent? (Implement email logic)')">Reset Password</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>