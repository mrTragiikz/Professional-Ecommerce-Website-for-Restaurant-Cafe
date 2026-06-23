<?php
/**
 * API: Calculate Delivery Distance and Fee
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../config/load_security.php';

require_once __DIR__ . '/../app/functions/location.php';

// Get input coordinates
$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}

$userLat = floatval($data['lat'] ?? 0);
$userLng = floatval($data['lng'] ?? 0);

if (!$userLat || !$userLng) {
    echo json_encode(['success' => false, 'error' => 'Invalid coordinates']);
    exit;
}

$result = calculateDeliveryFee($userLat, $userLng);
echo json_encode($result);
