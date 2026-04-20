<?php
/**
 * /app/watchlist/toggle.php
 *
 * US-704 — Watchlist toggle endpoint
 * Team D — Phase 3
 *
 * Accepts: POST { company_id: int, csrf_token: string }
 * Returns: JSON { watching: bool, count: int } | JSON { error: string }
 *
 * Called by WatchlistService::bookmarkButton() JS via fetch().
 * Also handles plain-POST form fallback (redirects back to referer).
 */

require_once '../includes/security.php';
require_once '../includes/session.php';
require_once '../includes/csrf.php';
require_once '../includes/database.php';
require_once '../includes/functions.php';
require_once '../includes/WatchlistService.php';

header('Content-Type: application/json');

// Auth
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated.']);
    exit;
}

// CSRF — accept both session token (form fallback) and header-based token
$token     = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
$sessionTk = $_SESSION['csrf_token'] ?? '';
if ($token !== $sessionTk || $sessionTk === '') {
    http_response_code(403);
    echo json_encode(['error' => 'Invalid security token.']);
    exit;
}

$companyId = (int)($_POST['company_id'] ?? 0);
if ($companyId <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid company_id.']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo    = Database::getInstance();

// Verify company exists and is active
try {
    $stmt = $pdo->prepare("SELECT id FROM companies WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$companyId]);
    if (!$stmt->fetchColumn()) {
        http_response_code(404);
        echo json_encode(['error' => 'Company not found.']);
        exit;
    }
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Service temporarily unavailable.']);
    exit;
}

$result = WatchlistService::toggle($pdo, $userId, $companyId);

// Plain-POST fallback: if not an AJAX request, redirect back
if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) && empty($_POST['ajax'])) {
    $referer = $_SERVER['HTTP_REFERER'] ?? '/app/discover/';
    header('Location: ' . $referer);
    exit;
}

echo json_encode($result);
