<?php
/**
 * Store user data temporarily before Google OAuth
 */

require_once __DIR__ . '/../app/functions/security.php';

initSecureSession();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if ($data && isset($data['first_name']) && isset($data['last_name']) && isset($data['phone']) && isset($data['email'])) {
        // Store in session
        $_SESSION['pending_user_data'] = [
            'name' => trim($data['first_name'] . ' ' . $data['last_name']),
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'phone' => trim($data['phone']),
            'email' => trim($data['email']),
            'address' => null // Address can be added later
        ];
        
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Missing required fields']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request']);
}

