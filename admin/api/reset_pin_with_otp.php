<?php
/**
 * AJAX endpoint to verify OTP and reset Admin PIN
 */
require_once __DIR__ . '/../includes/auth.php';

// Set JSON header
header('Content-Type: application/json');

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

// Get POST data
$data = json_decode(file_get_contents('php://input'), true);
$otp = $data['otp'] ?? '';
$newPin = $data['newPin'] ?? '';
$adminId = $_SESSION['admin_id'];

if (strlen($newPin) !== 4 || !is_numeric($newPin)) {
    echo json_encode(['success' => false, 'message' => 'New PIN must be 4 digits.']);
    exit;
}

try {
    global $pdo;
    
    // Verify OTP
    $stmt = $pdo->prepare("SELECT pin_otp, pin_otp_expiry FROM admins WHERE id = ?");
    $stmt->execute([$adminId]);
    $admin = $stmt->fetch();
    
    if (!$admin || $admin['pin_otp'] !== $otp) {
        echo json_encode(['success' => false, 'message' => 'Invalid OTP.']);
        exit;
    }
    
    if (strtotime($admin['pin_otp_expiry']) < time()) {
        echo json_encode(['success' => false, 'message' => 'OTP has expired.']);
        exit;
    }
    
    // Reset PIN and clear OTP
    $stmt = $pdo->prepare("UPDATE admins SET pin_code = ?, pin_otp = NULL, pin_otp_expiry = NULL WHERE id = ?");
    $stmt->execute([$newPin, $adminId]);
    
    // Also mark as verified in session so they can go ahead
    $_SESSION['admin_pin_verified'] = true;
    $_SESSION['pin_attempts'] = 0;
    
    echo json_encode(['success' => true, 'message' => 'PIN reset successfully.']);
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
