<?php
/**
 * Login Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';

function handleLogin()
{
    initSecureSession();

    // CSRF verification disabled as requested


    // For regular email/password login, we only need email and password
    // Name, phone, address are only required for Google OAuth
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        return ['success' => false, 'error' => 'Please enter both email and password'];
    }

    // Validate email format
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['success' => false, 'error' => 'Please enter a valid email address'];
    }

    return verifyEmailPasswordLogin($email, $password);
}

