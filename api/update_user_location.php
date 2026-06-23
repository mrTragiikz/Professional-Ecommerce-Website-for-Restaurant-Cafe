<?php
/**
 * API to update user location
 */
require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isUserLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) {
    $data = $_POST;
}

$lat = $data['lat'] ?? null;
$lng = $data['lng'] ?? null;
$address = $data['address'] ?? null;
$area = $data['area'] ?? null;

if (!$lat || !$lng) {
    echo json_encode(['success' => false, 'error' => 'Missing coordinates']);
    exit;
}

$user = getCurrentUser();
$userId = $user['id'];

try {
    $stmt = $pdo->prepare("
        UPDATE users 
        SET location_lat = ?, location_lng = ?, street_location = ?, delivery_location = ?
        WHERE id = ?
    ");
    $stmt->execute([$lat, $lng, $address, $area, $userId]);

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    error_log("Update location error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
