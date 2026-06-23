<?php
/**
 * AJAX endpoint to generate and send PIN Reset OTP
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../../app/functions/email.php';
require_once __DIR__ . '/../../config/load_security.php';

// Set JSON header
header('Content-Type: application/json');

// Check if admin is logged in
if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$adminId = $_SESSION['admin_id'];

// Generate 6-digit OTP
$otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
$expiry = date('Y-m-d H:i:s', time() + 600); // 10 minutes

// Reset failed attempts when requesting recovery
$_SESSION['pin_attempts'] = 0;

try {
    global $pdo;
    $emailConfig = getEmailConfig();
    
    // Save to DB
    $stmt = $pdo->prepare("UPDATE admins SET pin_otp = ?, pin_otp_expiry = ? WHERE id = ?");
    $stmt->execute([$otp, $expiry, $adminId]);
    
    // Send email
    $adminEmail = $emailConfig['admin_email'] ?? 'info@justkleek.com';
    
    if (sendAdminPinResetEmail($adminEmail, $otp)) {
        // Log for debug on localhost
        if (strpos($_SERVER['HTTP_HOST'], 'localhost') !== false) {
             file_put_contents(__DIR__ . '/../../latest_admin_pin_otp.txt', "Admin PIN OTP: " . $otp . " (Time: " . date('Y-m-d H:i:s') . ")");
        }
        echo json_encode(['success' => true, 'message' => 'OTP sent to registered email.']);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to send email. Please try again.']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
