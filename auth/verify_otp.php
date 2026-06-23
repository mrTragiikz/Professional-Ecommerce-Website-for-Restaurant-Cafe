<?php
/**
 * AJAX Endpoint: Verify OTP
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/email.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get OTP and email
$otp = trim($_POST['otp'] ?? '');
$email = trim($_POST['email'] ?? '');

// CSRF verification disabled as requested


if (empty($otp) || empty($email)) {
    echo json_encode(['success' => false, 'error' => 'OTP and email are required']);
    exit;
}

// Get user ID from email if provided
$userId = null;
$user = null;
if (!empty($email)) {
    $user = findUserByEmail($email);
    if ($user) {
        $userId = $user['id'];
    }
}

// Verify OTP
$result = verifyEmailOTP($otp, $userId);

if ($result['success']) {
    // Log the user in if not already logged in
    if (!isUserLoggedIn() && $user) {
        loginUser($user['id'], $user['email']);
    }

    // Clear signup session
    unset($_SESSION['signup_email']);
    unset($_SESSION['signup_user_id']);

    echo json_encode([
        'success' => true,
        'message' => 'Email verified successfully'
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => $result['error'] ?? 'Invalid verification code'
    ]);
}

