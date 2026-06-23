<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$lat = $_GET['lat'] ?? null;
$lng = $_GET['lng'] ?? null;

if ($lat === null || $lng === null) {
    echo json_encode(['success' => false, 'error' => 'Lat and Lng are required']);
    exit;
}

require_once __DIR__ . '/../../app/services/mapbox_distance.php';

$result = getRoadDistanceAndFee($lat, $lng);
echo json_encode($result);
