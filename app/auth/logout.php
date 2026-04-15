<?php
require_once '../includes/security.php';
require_once '../includes/session.php';

// Make sure a session is active (only if your session.php doesn't already do this)
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Unset all session variables
$_SESSION = [];

// Destroy the session cookie
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', // secure
        true // httponly
    );
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit;