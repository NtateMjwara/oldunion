<?php
/**
 * Secure file upload handler for company-related files.
 * Files are stored outside public_html or in a protected directory.
 */
define('UPLOAD_BASE',     dirname(__DIR__) . '/uploads/companies/'); // outside public_html if possible
define('UPLOAD_WEB_PATH', '/uploads/companies/');                    // relative path for web access

function uploadCompanyFile($fileInput, $companyUuid, $type) {
    // Allowed MIME types per upload category
    $allowed = [
        'logo'     => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'banner'   => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'document' => ['application/pdf', 'image/jpeg', 'image/png'],
    ];

    if (!isset($_FILES[$fileInput]) || $_FILES[$fileInput]['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File upload error.'];
    }

    if (!array_key_exists($type, $allowed)) {
        return ['success' => false, 'error' => 'Unknown upload type.'];
    }

    $file  = $_FILES[$fileInput];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime  = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    // Validate MIME type against the allowed list for this category
    if (!in_array($mime, $allowed[$type])) {
        return ['success' => false, 'error' => 'Invalid file type.'];
    }

    // Max size: 5 MB
    if ($file['size'] > 5 * 1024 * 1024) {
        return ['success' => false, 'error' => 'File too large (max 5MB).'];
    }

    // Generate safe filename
    $ext      = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $safeName = $companyUuid . '_' . $type . '_' . time() . '.' . $ext;
    $destination = UPLOAD_BASE . $safeName;

    // Create directory if not exists
    if (!is_dir(UPLOAD_BASE)) {
        mkdir(UPLOAD_BASE, 0755, true);
    }

    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'path' => UPLOAD_WEB_PATH . $safeName];
    } else {
        return ['success' => false, 'error' => 'Failed to move uploaded file.'];
    }
}
