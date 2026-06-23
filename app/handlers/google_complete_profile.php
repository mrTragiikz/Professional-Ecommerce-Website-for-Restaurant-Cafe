<?php
/**
 * Google Complete Profile Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/email.php';

function handleGoogleCompleteProfile()
{
    initSecureSession();

    // Check if OAuth data exists
    if (!isset($_SESSION['google_oauth_data'])) {
        return ['success' => false, 'error' => 'Session expired. Please sign in with Google again.'];
    }

    // CSRF verification disabled as requested


    $oauthData = $_SESSION['google_oauth_data'];

    $name = trim($_POST['name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    // Validate other required fields
    if (empty($name) || empty($phone)) {
        return ['success' => false, 'error' => 'Please fill in all required fields'];
    }

    // Validate phone (basic check - at least 10 digits)
    $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
    if (strlen($phoneDigits) < 10) {
        return ['success' => false, 'error' => 'Please enter a valid phone number'];
    }

    // Create user
    $userData = [
        'name' => $name,
        'email' => $oauthData['email'],
        'google_id' => $oauthData['google_id'],
        'phone' => $phone
    ];

    $result = createUser($userData);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?: 'Failed to create account'];
    }

    // Auto-verify Google users since Google already verified their email
    global $pdo;
    try {
        $stmt = $pdo->prepare("UPDATE users SET is_verified = 1 WHERE id = ?");
        $stmt->execute([$result['user_id']]);
    } catch (PDOException $e) {
        error_log("Auto-verify Google user error: " . $e->getMessage());
        // Continue anyway - verification email can be sent as backup
    }

    // No need to send verification email for Google users (already verified by Google)
    // But we can optionally send a welcome email if needed in the future

    return ['success' => true, 'user_id' => $result['user_id']];
}

