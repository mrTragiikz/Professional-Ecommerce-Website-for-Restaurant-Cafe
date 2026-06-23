<?php
/**
 * AJAX Endpoint: Resend OTP
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../app/functions/email.php';

initSecureSession();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Get email
$email = trim($_POST['email'] ?? '');

// CSRF verification disabled as requested


if (empty($email)) {
    echo json_encode(['success' => false, 'error' => 'Email is required']);
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Invalid email address']);
    exit;
}

// Find user
$user = findUserByEmail($email);

if (!$user) {
    // Don't reveal if email exists for security
    echo json_encode(['success' => true, 'message' => 'If the email exists, a verification code has been sent']);
    exit;
}

// Check if already verified
if ($user['is_verified']) {
    echo json_encode(['success' => false, 'error' => 'This email is already verified']);
    exit;
}

// Check rate limit
/*
// Disable rate limit as per user request
$rateLimit = checkRateLimit('verification_resend', 15, 3600, 1800); // 15 per hour, 30 min lockout
if (!$rateLimit['allowed']) {
    echo json_encode([
        'success' => false,
        'error' => 'Too many verification code requests. Please try again later.'
    ]);
    exit;
}
*/

// Create new verification OTP
$otpResult = createVerificationOTP($user['id']);

if (!$otpResult['success']) {
    echo json_encode(['success' => false, 'error' => 'Failed to create verification OTP']);
    exit;
}

// Send email with OTP
$emailSent = sendVerificationEmail($user['email'], $user['name'], $otpResult['otp']);


// Check provided email for localhost environment debug
$isLocalhost = (
    $_SERVER['HTTP_HOST'] === 'localhost' ||
    strpos($_SERVER['HTTP_HOST'], '127.0.0.1') !== false ||
    strpos($_SERVER['HTTP_HOST'], '::1') !== false
);

if ($emailSent) {
    $response = ['success' => true, 'message' => 'Verification code has been sent'];
    if ($isLocalhost) {
        $response['debug_otp'] = $otpResult['otp'];
        $response['message'] .= " (Debug: OTP is " . $otpResult['otp'] . ")";
    }
    echo json_encode($response);
} else {
    // If email failed but we have a valid OTP, allow verification on localhost
    if ($isLocalhost) {
        echo json_encode([
            'success' => true,
            'message' => 'Email failed but OTP generated (Localhost Debug)',
            'debug_otp' => $otpResult['otp']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to send verification email']);
    }
}


