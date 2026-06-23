<?php
/**
 * Google Onboarding Submit Handler
 * Handles profile completion form submission for first-time Google users
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/email.php';

function handleGoogleOnboardingSubmit()
{
    initSecureSession();

    // Check if OAuth data exists
    if (!isset($_SESSION['google_oauth_data'])) {
        return ['success' => false, 'error' => 'Session expired. Please sign in with Google again.'];
    }

    // CSRF verification disabled as requested


    $oauthData = $_SESSION['google_oauth_data'];

    // Get form data
    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $deliveryLocation = trim($_POST['delivery_location'] ?? '');
    $streetLocation = trim($_POST['street_location'] ?? '');
    $locationLat = $_POST['location_lat'] ?? null;
    $locationLng = $_POST['location_lng'] ?? null;
    $branchId = intval($_POST['branch_id'] ?? 1);

    // Validation
    if (empty($firstName) || empty($lastName) || empty($phone) || empty($deliveryLocation) || empty($streetLocation) || empty($branchId)) {
        return ['success' => false, 'error' => 'Please fill in all required fields'];
    }

    // Validate and format phone number
    // Phone should be in format +977XXXXXXXXX (10 digits after +977)
    if (!preg_match('/^\+977[0-9]{10}$/', $phone)) {
        // If phone doesn't start with +977, try to add it
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneDigits) == 10) {
            $phone = '+977' . $phoneDigits;
        } else {
            return ['success' => false, 'error' => 'Please enter a valid phone number (10 digits)'];
        }
    }

    // Create user with Google OAuth data
    $userData = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'email' => $oauthData['email'],
        'google_id' => $oauthData['google_id'],
        'phone' => $phone,
        'delivery_location' => $deliveryLocation,
        'street_location' => $streetLocation,
        'location_lat' => $locationLat,
        'location_lng' => $locationLng,
        'branch_id' => $branchId,
        'auth_provider' => 'google',
        'profile_completed' => 1
    ];

    // Check for existing user by email
    $existingUser = findUserByEmail($oauthData['email']);

    if ($existingUser) {
        // User exists - UPDATE profile instead of creating new one
        try {
            global $pdo;
            $pdo->beginTransaction();

            $updateFields = [
                'first_name' => $userData['first_name'],
                'last_name' => $userData['last_name'],
                'name' => $userData['first_name'] . ' ' . $userData['last_name'],
                'phone' => $userData['phone'],
                'delivery_location' => $userData['delivery_location'],
                'street_location' => $userData['street_location'],
                'location_lat' => $userData['location_lat'],
                'location_lng' => $userData['location_lng'],
                'branch_id' => $userData['branch_id'],
                'profile_completed' => 1,
                'google_id' => $userData['google_id'], // Ensure Google ID is linked
                'auth_provider' => 'google' // Ensure auth provider is set
            ];

            // Build dynamic update query
            $setClause = [];
            $params = [];
            foreach ($updateFields as $field => $value) {
                $setClause[] = "`$field` = ?";
                $params[] = $value;
            }
            $params[] = $existingUser['id']; // For WHERE clause

            $sql = "UPDATE users SET " . implode(', ', $setClause) . ", updated_at = NOW() WHERE id = ?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $pdo->commit();

            $userId = $existingUser['id'];

            // Send email verification if not already verified (and if we want to enforce it here)
            // But usually we just let them in and `requireVerifiedUser` blocks specific actions later
            if ($existingUser['is_verified'] != 1) {
                createVerificationOTP($userId);
            }

            return ['success' => true, 'user_id' => $userId, 'verification_sent' => false];

        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log("Update existing user error in onboarding: " . $e->getMessage());
            
            if ($e->getCode() == 23000) {
                return ['success' => false, 'error' => 'This phone number is already registered with another account.'];
            }
            
            return ['success' => false, 'error' => 'Failed to update profile'];
        }
    }

    // User does not exist - CREATE new user
    $result = createUser($userData);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?: 'Failed to create account'];
    }

    $userId = $result['user_id'];

    // Send email verification with OTP (required before ordering)
    $otpResult = createVerificationOTP($userId);
    // Do not auto-generate OTP; user will trigger from profile
    return ['success' => true, 'user_id' => $userId, 'verification_sent' => false];
}



