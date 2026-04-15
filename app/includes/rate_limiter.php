<?php
/**
 * Database-based rate limiting for login attempts.
 */
require_once __DIR__ . '/database.php';

function checkRateLimit($email, $ip) {
    $pdo = Database::getInstance();
    $window = RATE_LIMIT_WINDOW;
    $maxAttempts = RATE_LIMIT_ATTEMPTS;

    // Count attempts from this email and IP in the last window
    $stmt = $pdo->prepare("
        SELECT COUNT(*) as attempts 
        FROM login_attempts 
        WHERE (email = :email OR ip = :ip) 
          AND attempted_at > (NOW() - INTERVAL :window SECOND)
    ");
    $stmt->execute(['email' => $email, 'ip' => $ip, 'window' => $window]);
    $result = $stmt->fetch();

    if ($result['attempts'] >= $maxAttempts) {
        // Lock the user account if email exists
        $stmt = $pdo->prepare("UPDATE users SET locked_until = DATE_ADD(NOW(), INTERVAL :duration SECOND) WHERE email = :email");
        $stmt->execute(['duration' => LOCKOUT_DURATION, 'email' => $email]);
        return false;
    }
    return true;
}

function recordFailedAttempt($email, $ip) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("INSERT INTO login_attempts (email, ip, attempted_at) VALUES (:email, :ip, NOW())");
    $stmt->execute(['email' => $email, 'ip' => $ip]);
}

function clearFailedAttempts($email) {
    $pdo = Database::getInstance();
    $stmt = $pdo->prepare("DELETE FROM login_attempts WHERE email = :email");
    $stmt->execute(['email' => $email]);
}

// Create the login_attempts table (run once)
/*
CREATE TABLE login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    ip VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL,
    INDEX (email),
    INDEX (ip),
    INDEX (attempted_at)
) ENGINE=InnoDB;
*/