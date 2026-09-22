<?php
/**
 * Shared image upload handling. Files are saved under hef/uploads/{subfolder}/
 * so they're directly web-accessible (needed since <img> tags just point
 * at the path). Only images are accepted, size-capped, and renamed to a
 * random filename (never trust the original name).
 */

define('HEF_UPLOAD_MAX_BYTES', 5 * 1024 * 1024); // 5MB
define('HEF_UPLOAD_ALLOWED_TYPES', ['image/jpeg', 'image/png', 'image/webp', 'image/gif']);
define('HEF_UPLOAD_EXT_MAP', ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif']);

/**
 * Handles a single <input type="file" name="$fieldName"> upload.
 * Returns the web-relative path (e.g. "uploads/batches/abc123.jpg") on
 * success, null if no file was submitted, or throws RuntimeException
 * with a user-facing message on validation failure.
 */
function hef_handle_image_upload(string $fieldName, string $subfolder): ?string
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed — please try a different file.');
    }

    if ($file['size'] > HEF_UPLOAD_MAX_BYTES) {
        throw new RuntimeException('Image is too large (max 5MB).');
    }

    $mimeType = mime_content_type($file['tmp_name']);
    if (! in_array($mimeType, HEF_UPLOAD_ALLOWED_TYPES)) {
        throw new RuntimeException('Only JPG, PNG, WEBP, or GIF images are allowed.');
    }

    $ext = HEF_UPLOAD_EXT_MAP[$mimeType];
    $filename = bin2hex(random_bytes(16)) . '.' . $ext;

    $uploadDir = __DIR__ . '/../uploads/' . $subfolder;
    if (! is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }
    chmod($uploadDir, 0755);

    $destination = $uploadDir . '/' . $filename;
    if (! move_uploaded_file($file['tmp_name'], $destination)) {
        throw new RuntimeException('Could not save the uploaded image.');
    }
    chmod($destination, 0644);

    return "uploads/{$subfolder}/{$filename}";
}

/**
 * Deletes a previously uploaded file, given the web-relative path stored
 * in the DB (e.g. "uploads/batches/abc123.jpg"). Safe to call with null.
 */
function hef_delete_uploaded_image(?string $webPath): void
{
    if (! $webPath || ! str_starts_with($webPath, 'uploads/')) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $webPath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
