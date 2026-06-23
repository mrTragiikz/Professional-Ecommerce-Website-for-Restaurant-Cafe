<?php
/**
 * Signup Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';
require_once __DIR__ . '/../functions/email.php';

function handleSignup()
{
    initSecureSession();

    // CSRF verification disabled as requested


    $firstName = trim($_POST['first_name'] ?? '');
    $lastName = trim($_POST['last_name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone = trim($_POST['phone'] ?? '');
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';

    $deliveryLocation = trim($_POST['delivery_location'] ?? '');
    $streetLocation = trim($_POST['street_location'] ?? '');
    $locationLat = $_POST['location_lat'] ?? null;
    $locationLng = $_POST['location_lng'] ?? null;
    $branchId = intval($_POST['branch_id'] ?? 1);

    // Combine first and last name
    $name = trim($firstName . ' ' . $lastName);

    // Validate required fields (Lat/Lng are OPTIONAL)
    if (empty($firstName) || empty($lastName) || empty($email) || empty($phone) || empty($password) || empty($deliveryLocation) || empty($streetLocation) || empty($branchId)) {
        return ['success' => false, 'error' => 'Please fill in all required fields'];
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address'];
    }

    if (strlen($password) < 8) {
        return ['success' => false, 'error' => 'Password must be at least 8 characters long'];
    }

    if ($password !== $passwordConfirm) {
        return ['success' => false, 'error' => 'Passwords do not match'];
    }

    // Validate phone - ensure it starts with +977 and has valid format
    // Phone should be in format +977XXXXXXXXX (10 digits after +977)
    if (!preg_match('/^\+977[0-9]{10}$/', $phone)) {
        // If phone doesn't start with +977, add it
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneDigits) == 10) {
            $phone = '+977' . $phoneDigits;
        } else {
            return ['success' => false, 'error' => 'Please enter a valid phone number'];
        }
    }

    // Create user with basic data
    // Email existence check is handled by createUser() internally to allow upgrades
    $userData = [
        'first_name' => $firstName,
        'last_name' => $lastName,
        'name' => $name,
        'email' => $email,
        'password_hash' => password_hash($password, PASSWORD_DEFAULT),
        'phone' => $phone,
        'delivery_location' => $deliveryLocation,
        'street_location' => $streetLocation,
        'location_lat' => $locationLat,
        'location_lng' => $locationLng,
        'branch_id' => $branchId
    ];

    $result = createUser($userData);

    if (!$result['success']) {
        return ['success' => false, 'error' => $result['error'] ?: 'Failed to create account'];
    }

    // Do not auto-generate OTP; verification will be initiated from profile
    return ['success' => true, 'email' => $email, 'user_id' => $result['user_id'], 'otp' => null];
}

