<?php
/**
 * Admin Delivery Radius Logic - Location Calculation Proxy
 * This provides authenticated access to Mapbox distance calculations
 */
header('Content-Type: application/json');
require_once __DIR__ . '/../../includes/auth.php';

// Verification of administrative identity
if (!isAdminLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

require_once __DIR__ . '/../../../app/services/mapbox_distance.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

// Extract parameters from JSON payload
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$userLat = $_POST['user_lat'] ?? $input['lat'] ?? $input['user_lat'] ?? null;
$userLng = $_POST['user_lng'] ?? $input['lng'] ?? $input['user_lng'] ?? null;
$branchId = $_POST['branch_id'] ?? $input['branch_id'] ?? null;

if ($userLat === null || $userLng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Latitude and Longitude coordinates are required.']);
    exit;
}

// Invoke the core delivery metrics engine
$result = getRoadDistanceAndFee($userLat, $userLng, $branchId);

if ($result['success'] === false) {
    http_response_code(400);
}

echo json_encode($result);
