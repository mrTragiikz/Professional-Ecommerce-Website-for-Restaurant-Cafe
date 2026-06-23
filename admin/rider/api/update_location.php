<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../../../config/db.php';
require_once __DIR__ . '/../RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

if (!$riderId) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}


$data = json_decode(file_get_contents('php://input'), true);

if (!isset($data['lat']) || !isset($data['lng'])) {
    echo json_encode(['success' => false, 'error' => 'Missing coordinates']);
    exit;
}

$lat = (float) $data['lat'];
$lng = (float) $data['lng'];

try {
    $stmt = $pdo->prepare("UPDATE `riders` SET `location_lat` = ?, `location_lng` = ?, `last_location_update` = NOW(), `is_online` = 1 WHERE `id` = ?");
    $result = $stmt->execute([$lat, $lng, $riderId]);

    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update location']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
?>