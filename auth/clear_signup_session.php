<?php
/**
 * AJAX Endpoint: Clear Signup Session
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../app/functions/security.php';

initSecureSession();

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false]);
    exit;
}

// Clear signup session
unset($_SESSION['signup_email']);
unset($_SESSION['signup_user_id']);

echo json_encode(['success' => true]);







