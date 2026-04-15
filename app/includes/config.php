<?php
/**
 * Configuration file - MUST be placed outside public_html in production.
 * For shared hosting, store this one level above public_html and adjust path.
 */

// ============================================================
// config.php — central configuration
// ============================================================

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'u637509541_oldunionapp');
define('DB_USER', 'u637509541_oldunion');
define('DB_PASS', '0LdUn!0n');
define('DB_CHARSET', 'utf8mb4');

// Site URL (no trailing slash)
define('SITE_URL', 'https://oldunion.co.za');

// Email settings (SMTP)
define('SMTP_HOST', 'smtp.hostinger.com');
define('SMTP_PORT', '587');
define('SMTP_USER', 'admin@oldunion.co.za');
define('SMTP_PASS', '@Dmin893');
define('SMTP_FROM', 'admin@oldunion.co.za');
define('SMTP_FROM_NAME', 'Old Union');

// ============================================================
// Helper utilities
// ============================================================
function generateReference(string $prefix = 'TXN'): string {
    return strtoupper($prefix) . '_' . date('Ymd') . '_' . bin2hex(random_bytes(6));
}

function jsonResponse(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    exit;
}

function formatZAR(float $amount): string {
    return 'R ' . number_format($amount, 2);
}

// ============================================================
// Security
// ============================================================

// Security
define('PASSWORD_ALGO', PASSWORD_ARGON2ID); // Argon2ID if available, else PASSWORD_DEFAULT
define('CSRF_TOKEN_NAME', 'csrf_token');
define('SESSION_TIMEOUT', 1800); // 30 minutes in seconds
define('RATE_LIMIT_ATTEMPTS', 5);
define('RATE_LIMIT_WINDOW', 900); // 15 minutes
define('LOCKOUT_DURATION', 900); // 15 minutes

// Paths
define('BASE_PATH', dirname(__DIR__)); // /public_html
define('INCLUDES_PATH', BASE_PATH . '/includes');
define('LOG_PATH', BASE_PATH . '/../logs'); // outside public_html

// ============================================================
// YOCO Payment Gateway
// ============================================================

// YoCo API credentials
// Test keys:  https://dashboard.yoco.com/developers
// Live keys:  https://dashboard.yoco.com/developers
define('YOCO_SECRET_KEY',  getenv('YOCO_SECRET_KEY')  ?: 'sk_test_97458d1f23YgA6Odacd4359a25b6');
define('YOCO_PUBLIC_KEY',  getenv('YOCO_PUBLIC_KEY')  ?: 'pk_test_76afac4bV03A6MY14324');
define('YOCO_WEBHOOK_SECRET', getenv('YOCO_WEBHOOK_SECRET') ?: 'whsec_QkNDNEFBOTI3MDRBRURCMUUyQ0Y2MzFDNzI3RTNGNTA=');

// App settings
define('APP_URL',      getenv('APP_URL')      ?: 'https://oldunion.co.za');
define('APP_NAME',     'Old Union');
define('CURRENCY',     'ZAR');
define('MIN_DEPOSIT',  50.00);     // ZAR
define('MAX_DEPOSIT',  500000.00); // ZAR
define('MIN_TRANSFER', 10.00);     // ZAR

// YoCo API base — WalletService appends /checkouts to this
define('YOCO_API_BASE', 'https://payments.yoco.com/api');

// ============================================================
// Cache
// ============================================================

// Cache configuration (Redis optional)
define('CACHE_DRIVER', 'file'); // 'redis', 'apcu', 'file'
define('REDIS_HOST', '127.0.0.1');
define('REDIS_PORT', 6379);
define('REDIS_PASSWORD', '');
define('REDIS_DB', 0);

// Rate limiting for deposits
define('DEPOSIT_RATE_LIMIT', 5); // max attempts
define('DEPOSIT_RATE_WINDOW', 3600); // per hour (seconds)

// Error reporting — errors logged to file, never printed to screen
ini_set('display_errors', 1);
ini_set('log_errors', 1);
//ini_set('error_log', LOG_PATH . '/php_errors.log');
error_reporting(E_ALL);