<?php
/**
 * Upload Profile Picture Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';

initSecureSession();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Check if user is logged in
if (!isUserLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please log in to upload profile picture']);
    exit;
}

// CSRF verification disabled as requested


if (!isset($_FILES['profile_picture']) || $_FILES['profile_picture']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded or upload error']);
    exit;
}

$file = $_FILES['profile_picture'];
$user = getCurrentUser();
require_once __DIR__ . '/../../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// Validate file
$allowedMime = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
$allowedExt = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
$maxSize = 5 * 1024 * 1024; // 5MB

// Check MIME using finfo (more reliable than $_FILES['type'])
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = $finfo ? finfo_file($finfo, $file['tmp_name']) : $file['type'];
if ($finfo)
    finfo_close($finfo);

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

if (!in_array($mime, $allowedMime) || !in_array($ext, $allowedExt)) {
    echo json_encode(['success' => false, 'error' => 'Invalid file type. Only JPEG, PNG, GIF, and WebP are allowed.']);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File size too large. Maximum 5MB allowed.']);
    exit;
}

// Create uploads directory if it doesn't exist
$uploadDir = __DIR__ . '/../../uploads/profile_pictures/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}
// Ensure directory is writable
if (!is_writable($uploadDir)) {
    @chmod($uploadDir, 0775);
}
if (!is_writable($uploadDir)) {
    echo json_encode(['success' => false, 'error' => 'Upload directory is not writable.']);
    exit;
}

// Generate unique filename
$filename = 'user_' . $user['id'] . '_' . time() . '.' . $ext;
$filepath = $uploadDir . $filename;

// Delete old profile picture if exists
try {
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $oldPicture = $stmt->fetchColumn();

    if ($oldPicture && file_exists(__DIR__ . '/../../' . $oldPicture)) {
        unlink(__DIR__ . '/../../' . $oldPicture);
    }
} catch (PDOException $e) {
    error_log("Error checking old picture: " . $e->getMessage());
}

// Move uploaded file
if (!move_uploaded_file($file['tmp_name'], $filepath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to save file']);
    exit;
}

// Update database
$relativePath = 'uploads/profile_pictures/' . $filename;
try {
    $stmt = $pdo->prepare("UPDATE users SET profile_picture = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$relativePath, $user['id']]);

    $basePath = getBasePath();
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture uploaded successfully',
        'picture_url' => $basePath . '/' . $relativePath
    ]);
} catch (PDOException $e) {
    error_log("Update profile picture error: " . $e->getMessage());
    // Delete file if database update fails
    if (file_exists($filepath)) {
        unlink($filepath);
    }
    echo json_encode(['success' => false, 'error' => 'Failed to update profile picture']);
}








