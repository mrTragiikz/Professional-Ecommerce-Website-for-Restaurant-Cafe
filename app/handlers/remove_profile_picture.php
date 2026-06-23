<?php
/**
 * Remove Profile Picture Handler
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
    echo json_encode(['success' => false, 'error' => 'Please log in']);
    exit;
}

// Verify CSRF token
$data = json_decode(file_get_contents('php://input'), true);
if (!isset($data['csrf_token']) || !verifyCSRFToken($data['csrf_token'])) {
    echo json_encode(['success' => false, 'error' => 'Invalid security token']);
    exit;
}

$user = getCurrentUser();
require_once __DIR__ . '/../../config/db.php';

try {
    // Get current picture path
    $stmt = $pdo->prepare("SELECT profile_picture FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $currentPicture = $stmt->fetchColumn();
    
    // Delete file if exists
    if ($currentPicture && file_exists(__DIR__ . '/../../' . $currentPicture)) {
        unlink(__DIR__ . '/../../' . $currentPicture);
    }
    
    // Update database
    $stmt = $pdo->prepare("UPDATE users SET profile_picture = NULL, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$user['id']]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Profile picture removed successfully'
    ]);
} catch (PDOException $e) {
    error_log("Remove profile picture error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to remove profile picture']);
}









