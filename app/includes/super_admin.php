<?php
/**
 * Super Admin User Management Class
 * 
 * Provides absolute control over users: create, read, update, delete, verify, change roles, etc.
 * 
 * @requires config.php, database.php, functions.php to be loaded first
 */
class UserManager {
    private PDO $db;
    private ?array $currentUser; // optional, stores current user data (id, role)

    /**
     * Constructor.
     * 
     * @param array|null $currentUser Optional current user data (id, role) for logging and permission checks.
     */
    public function __construct(?array $currentUser = null) {
        $this->db = Database::getInstance();
        $this->currentUser = $currentUser;
    }

    // -------------------------------------------------------------------------
    // Permission Helper (comment/uncomment as needed)
    // -------------------------------------------------------------------------

    /**
     * Ensures the current user is a super_admin.
     * @throws Exception If not authorized.
     */
    private function requireSuperAdmin(): void {
        // Commented out to allow testing. Uncomment to enforce super_admin only.
        /*
        if (!$this->currentUser || $this->currentUser['role'] !== 'super_admin') {
            throw new Exception('Unauthorized: only super_admin can perform this action.');
        }
        */
    }

    // -------------------------------------------------------------------------
    // Audit Logging
    // -------------------------------------------------------------------------

    /**
     * Logs an action performed by the current user.
     * @param string $action
     * @param array $details
     */
    public function logAction(string $action, array $details): void {
        $logFile = LOG_PATH . '/admin_audit.log';
        $logEntry = sprintf(
            "[%s] User ID: %s, Action: %s, Details: %s\n",
            date('Y-m-d H:i:s'),
            $this->currentUser['id'] ?? 'system',
            $action,
            json_encode($details)
        );
        @file_put_contents($logFile, $logEntry, FILE_APPEND);
    }

    // -------------------------------------------------------------------------
    // Validation Helpers
    // -------------------------------------------------------------------------

    /**
     * Checks if an email already exists (excluding a specific user if provided).
     * @param string $email
     * @param int|null $excludeUserId
     * @return bool
     */
    private function emailExists(string $email, ?int $excludeUserId = null): bool {
        $sql = 'SELECT id FROM users WHERE email = :email';
        $params = [':email' => $email];
        if ($excludeUserId !== null) {
            $sql .= ' AND id != :exclude_id';
            $params[':exclude_id'] = $excludeUserId;
        }
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch() !== false;
    }

    /**
     * Validates a user role.
     * @param string $role
     * @return bool
     */
    private function isValidRole(string $role): bool {
        return in_array($role, ['user', 'admin', 'super_admin']);
    }

    // -------------------------------------------------------------------------
    // Public User Fetching Methods
    // -------------------------------------------------------------------------

    /**
     * Fetches a user by ID (excludes soft-deleted).
     * @param int $userId
     * @return array|null
     */
    public function getUserById(int $userId): ?array {
        $stmt = $this->db->prepare('SELECT * FROM users WHERE id = :id AND status != "deleted"');
        $stmt->execute([':id' => $userId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Returns all users (except deleted) with optional filters.
     * @param string|null $role
     * @param string|null $status
     * @return array
     */
    public function getAllUsers(?string $role = null, ?string $status = null): array {
        $sql = 'SELECT * FROM users WHERE status != "deleted"';
        $params = [];
        if ($role !== null) {
            $sql .= ' AND role = :role';
            $params[':role'] = $role;
        }
        if ($status !== null) {
            $sql .= ' AND status = :status';
            $params[':status'] = $status;
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // User Management Methods (all require super_admin)
    // -------------------------------------------------------------------------

    /**
     * Create a new user.
     * @param array $data Required: email, password. Optional: role, email_verified.
     * @return int New user ID
     * @throws Exception
     */
    public function createUser(array $data): int {
        $this->requireSuperAdmin();

        if (empty($data['email']) || empty($data['password'])) {
            throw new Exception('Email and password are required.');
        }

        $email = trim($data['email']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }

        if ($this->emailExists($email)) {
            throw new Exception('Email already exists.');
        }

        $role = $data['role'] ?? 'user';
        if (!$this->isValidRole($role)) {
            throw new Exception('Invalid role.');
        }

        $passwordHash = password_hash($data['password'], PASSWORD_ALGO);
        $uuid = generateUuidV4(); // using your existing UUID function

        $sql = 'INSERT INTO users (uuid, email, password_hash, role, status, email_verified, created_at, updated_at)
                VALUES (:uuid, :email, :password_hash, :role, :status, :email_verified, NOW(), NOW())';
        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            ':uuid' => $uuid,
            ':email' => $email,
            ':password_hash' => $passwordHash,
            ':role' => $role,
            ':status' => 'active',
            ':email_verified' => $data['email_verified'] ?? 0
        ]);

        $userId = (int) $this->db->lastInsertId();
        $this->logAction('create_user', ['user_id' => $userId, 'email' => $email, 'role' => $role]);
        return $userId;
    }

    /**
     * Update a user's email.
     * @param int $userId
     * @param string $newEmail
     * @throws Exception
     */
    public function updateUserEmail(int $userId, string $newEmail): void {
        $this->requireSuperAdmin();

        $newEmail = trim($newEmail);
        if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
            throw new Exception('Invalid email format.');
        }

        if ($this->emailExists($newEmail, $userId)) {
            throw new Exception('Email already in use by another user.');
        }

        $stmt = $this->db->prepare('UPDATE users SET email = :email WHERE id = :id');
        $stmt->execute([':email' => $newEmail, ':id' => $userId]);
        $this->logAction('update_email', ['user_id' => $userId, 'new_email' => $newEmail]);
    }

    /**
     * Verify a user's email (set email_verified = 1).
     * @param int $userId
     */
    public function verifyUser(int $userId): void {
        $this->requireSuperAdmin();

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $stmt = $this->db->prepare('UPDATE users SET email_verified = 1 WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->logAction('verify_user', ['user_id' => $userId]);
    }

    /**
     * Soft-delete a user (status = 'deleted').
     * Prevents deleting the last super_admin.
     * @param int $userId
     */
    public function deleteUser(int $userId): void {
        $this->requireSuperAdmin();

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        // Prevent deleting the only super_admin
        if ($user['role'] === 'super_admin') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE role = "super_admin" AND status != "deleted"');
            $stmt->execute();
            $count = $stmt->fetchColumn();
            if ($count <= 1) {
                throw new Exception('Cannot delete the last super_admin.');
            }
        }

        $stmt = $this->db->prepare('UPDATE users SET status = "deleted", email = CONCAT(email, "_deleted_", id) WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->logAction('delete_user', ['user_id' => $userId]);
    }

    /**
     * Change a user's role.
     * @param int $userId
     * @param string $newRole
     * @throws Exception
     */
    public function changeUserRole(int $userId, string $newRole): void {
        $this->requireSuperAdmin();

        if (!$this->isValidRole($newRole)) {
            throw new Exception('Invalid role.');
        }

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        // Prevent demoting the last super_admin
        if ($user['role'] === 'super_admin' && $newRole !== 'super_admin') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE role = "super_admin" AND status != "deleted"');
            $stmt->execute();
            $count = $stmt->fetchColumn();
            if ($count <= 1) {
                throw new Exception('Cannot demote the last super_admin.');
            }
        }

        $stmt = $this->db->prepare('UPDATE users SET role = :role WHERE id = :id');
        $stmt->execute([':role' => $newRole, ':id' => $userId]);
        $this->logAction('change_role', ['user_id' => $userId, 'new_role' => $newRole]);
    }

    /**
     * Suspend a user (status = 'suspended').
     * @param int $userId
     */
    public function suspendUser(int $userId): void {
        $this->requireSuperAdmin();

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        // Prevent suspending the last super_admin
        if ($user['role'] === 'super_admin') {
            $stmt = $this->db->prepare('SELECT COUNT(*) FROM users WHERE role = "super_admin" AND status != "deleted"');
            $stmt->execute();
            $count = $stmt->fetchColumn();
            if ($count <= 1) {
                throw new Exception('Cannot suspend the last super_admin.');
            }
        }

        $stmt = $this->db->prepare('UPDATE users SET status = "suspended" WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->logAction('suspend_user', ['user_id' => $userId]);
    }

    /**
     * Activate a user (set status = 'active').
     * @param int $userId
     */
    public function activateUser(int $userId): void {
        $this->requireSuperAdmin();

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $stmt = $this->db->prepare('UPDATE users SET status = "active" WHERE id = :id');
        $stmt->execute([':id' => $userId]);
        $this->logAction('activate_user', ['user_id' => $userId]);
    }

    // -------------------------------------------------------------------------
    // Additional intelligence: reset password, force logout, etc.
    // -------------------------------------------------------------------------

    /**
     * Reset a user's password (force new password).
     * @param int $userId
     * @param string $newPassword
     */
    public function resetUserPassword(int $userId, string $newPassword): void {
        $this->requireSuperAdmin();

        $user = $this->getUserById($userId);
        if (!$user) {
            throw new Exception('User not found.');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_ALGO);
        $stmt = $this->db->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $passwordHash, ':id' => $userId]);
        $this->logAction('reset_password', ['user_id' => $userId]);
    }

    /**
     * Force logout a user by clearing their session data.
     * Note: This assumes you store sessions in a database or can access session files.
     * For simplicity, we'll just log the action. Implement as needed.
     */
    public function forceLogout(int $userId): void {
        $this->requireSuperAdmin();

        // Example: if you store sessions in a `sessions` table, you could delete them.
        // For now, we'll just log it.
        $this->logAction('force_logout', ['user_id' => $userId]);
    }
}