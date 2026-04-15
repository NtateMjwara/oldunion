<?php
/**
 * CSRF protection: generate and validate tokens.
 */
require_once __DIR__ . '/session.php';

function generateCSRFToken() {
    if (empty($_SESSION[CSRF_TOKEN_NAME])) {
        $_SESSION[CSRF_TOKEN_NAME] = bin2hex(random_bytes(32));
    }
    return $_SESSION[CSRF_TOKEN_NAME];
}

function validateCSRFToken($token) {
    if (empty($_SESSION[CSRF_TOKEN_NAME]) || empty($token)) {
        return false;
    }
    return hash_equals($_SESSION[CSRF_TOKEN_NAME], $token);
}

// Automatically validate for POST requests (except logout, which we handle separately)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && basename($_SERVER['SCRIPT_NAME']) !== 'logout.php') {
    $token = $_POST[CSRF_TOKEN_NAME] ?? '';
    if (!validateCSRFToken($token)) {
        die('Invalid CSRF token.');
    }
}