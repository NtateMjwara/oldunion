<?php
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/functions.php';

/**
 * Get company by UUID
 */
function getCompanyByUuid($uuid) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("SELECT * FROM companies WHERE uuid = :uuid");
    $stmt->execute(['uuid' => $uuid]);
    return $stmt->fetch();
}

/**
 * Get a user's role in a specific company
 */
function getUserCompanyRole($companyId, $userId) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT role, status FROM company_admins
        WHERE company_id = :company_id AND user_id = :user_id AND status = 'active'
    ");
    $stmt->execute(['company_id' => $companyId, 'user_id' => $userId]);
    return $stmt->fetch();
}

/**
 * Check if a user has at least the required role in a company.
 * Role hierarchy: owner > admin > editor > viewer
 */
function hasCompanyPermission($companyId, $userId, $requiredRole) {
    $roleData = getUserCompanyRole($companyId, $userId);
    if (!$roleData) {
        return false;
    }
    $role = $roleData['role'];

    $hierarchy = ['viewer' => 1, 'editor' => 2, 'admin' => 3, 'owner' => 4];
    $userLevel     = $hierarchy[$role]         ?? 0;
    $requiredLevel = $hierarchy[$requiredRole] ?? 0;

    return $userLevel >= $requiredLevel;
}

/**
 * Middleware: require a minimum company role.
 * Redirects to login if not authenticated; dies with error if insufficient permission.
 */
function requireCompanyRole($companyId, $requiredRole) {
    if (!isLoggedIn()) {
        redirect('/auth/login.php');
    }
    if (!hasCompanyPermission($companyId, $_SESSION['user_id'], $requiredRole)) {
        die('You do not have permission to access this page.');
    }
}

/**
 * Log an activity event for a company
 */
function logCompanyActivity($companyId, $userId, $action) {
    $pdo = Database::getInstance();
    $ip  = $_SERVER['REMOTE_ADDR'] ?? null;
    $stmt = $pdo->prepare("
        INSERT INTO company_activity_logs (company_id, user_id, action, ip_address, created_at)
        VALUES (:company_id, :user_id, :action, :ip, NOW())
    ");
    $stmt->execute([
        'company_id' => $companyId,
        'user_id'    => $userId,
        'action'     => $action,
        'ip'         => $ip,
    ]);
}

/**
 * Get all administrators for a company, with user details.
 * Ordered by role hierarchy then join date.
 */
function getCompanyAdmins($companyId) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("
        SELECT ca.*, u.email, u.uuid as user_uuid
        FROM company_admins ca
        JOIN users u ON ca.user_id = u.id
        WHERE ca.company_id = :company_id
        ORDER BY FIELD(ca.role, 'owner', 'admin', 'editor', 'viewer'), ca.created_at
    ");
    $stmt->execute(['company_id' => $companyId]);
    return $stmt->fetchAll();
}

/**
 * Generate a unique company UUID (v4-style)
 */
function generateCompanyUuid() {
    return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );
}
