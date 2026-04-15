<?php
/**
 * Global Security Configuration
 * Include at the very top of every public-facing page.
 */

/* -------------------------------------------------------
   Force HTTPS
------------------------------------------------------- */
if (
    empty($_SERVER['HTTPS']) ||
    $_SERVER['HTTPS'] === 'off'
) {
    $redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
    header('Location: ' . $redirect, true, 301);
    exit;
}

/* -------------------------------------------------------
   Security Headers
------------------------------------------------------- */

// Prevent clickjacking
header('X-Frame-Options: DENY');

// Prevent MIME sniffing
header('X-Content-Type-Options: nosniff');

// Control referrer leakage
header('Referrer-Policy: strict-origin-when-cross-origin');

// Enforce HTTPS for 1 year
header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

// Hide server signature
header_remove('X-Powered-By');
header('Server:');

/* -------------------------------------------------------
   Content Security Policy
   Supports:
   - Yoco payments
   - Font Awesome (cdnjs)
------------------------------------------------------- */
header("
Content-Security-Policy:
    default-src 'self';

    script-src 
        'self'
        https://payments.yoco.com
        https://cdnjs.cloudflare.com;

    style-src 
        'self'
        'unsafe-inline'
        https://cdnjs.cloudflare.com;

    connect-src 
        'self'
        https://payments.yoco.com;

    frame-src 
        https://payments.yoco.com;

    form-action 
        'self'
        https://payments.yoco.com;

    font-src 
        'self'
        https://cdnjs.cloudflare.com
        data:;

    img-src 
        'self'
        data:;
");