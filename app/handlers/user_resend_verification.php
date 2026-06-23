<?php
/**
 * Resend Verification Code (OTP) Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/email.php';

function handleResendVerification()
{
    initSecureSession();

    // CSRF verification disabled as requested

    // Rate limit intentionally disabled to allow unlimited OTP resend attempts

    // Get email
    if (isUserLoggedIn()) {
        $user = getCurrentUser();
        $email = $user['email'] ?? '';
    } else {
        $email = trim($_POST['email'] ?? '');
    }

    if (empty($email)) {
        return ['success' => false, 'error' => 'Please provide your email address'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address'];
    }

    // Find user
    $user = findUserByEmail($email);

    if (!$user) {
        // Don't reveal if email exists for security
        return ['success' => true]; // Return success even if user doesn't exist
    }

    // Check if already verified
    if ($user['is_verified']) {
        return ['success' => false, 'error' => 'This email is already verified'];
    }

    // Create new verification OTP
    $otpResult = createVerificationOTP($user['id']);

    if (!$otpResult['success']) {
        return ['success' => false, 'error' => 'Failed to create verification OTP'];
    }

    // Send email with OTP
    sendVerificationEmail($user['email'], $user['name'], $otpResult['otp']);

    return ['success' => true];
}



