<?php
/**
 * Secure session handling.
 */
require_once __DIR__ . '/config.php';

// Detect HTTPS properly
$isHttps = (
    (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
);

// Configure session cookie settings before session_start()
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $isHttps ? '1' : '0');
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.gc_maxlifetime', (string) SESSION_TIMEOUT);
ini_set('session.cookie_lifetime', '0');

// Start session if needed
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle inactivity timeout
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > SESSION_TIMEOUT)) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            $params['secure'],
            $params['httponly']
        );
    }

    session_destroy();
    header('Location: /app/auth/login.php?timeout=1');
    exit;
}

// Update activity timestamp
$_SESSION['last_activity'] = time();

// Regenerate session ID periodically
if (!isset($_SESSION['created'])) {
    $_SESSION['created'] = time();
} elseif ((time() - $_SESSION['created']) > 300) {
    session_regenerate_id(true);
    $_SESSION['created'] = time();
}