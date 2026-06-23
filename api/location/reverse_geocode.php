<?php
// api/location/reverse_geocode.php - Secure Server-Side Reverse Geocoding
header('Content-Type: application/json');

require_once __DIR__ . '/../../config/mapbox.php';

// Parse POST or JSON body
$inputJSON = file_get_contents('php://input');
$input = json_decode($inputJSON, true);

$lat = $_POST['lat'] ?? $input['lat'] ?? null;
$lng = $_POST['lng'] ?? $input['lng'] ?? null;

if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Latitude and Longitude are required.']);
    exit;
}

$mapboxToken = defined('MAPBOX_SECRET_TOKEN') ? MAPBOX_SECRET_TOKEN : '';

if (empty($mapboxToken)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Mapbox token not configured.']);
    exit;
}

$url = sprintf('https://api.mapbox.com/geocoding/v5/mapbox.places/%F,%F.json?access_token=%s&limit=1', $lng, $lat, $mapboxToken);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 8);
curl_setopt($ch, CURLOPT_USERAGENT, 'JustKleek/1.1');

// SSL CA Bundle Path
$caPath = __DIR__ . '/../../config/cacert.pem';
if (file_exists($caPath)) {
    curl_setopt($ch, CURLOPT_CAINFO, $caPath);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
} else {
    // Fallback for development if file missing, but log it
    // In production, this file COMPULSORY
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
}

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    if (isset($data['features']) && !empty($data['features'])) {
        $feature = $data['features'][0];
        $address = $feature['place_name'] ?? '';
        $text = $feature['text'] ?? '';

        echo json_encode([
            'success' => true,
            'address' => $address,
            'text' => $text,
            'features' => $data['features']
        ]);
        exit;
    }
}

echo json_encode(['success' => false, 'error' => $error ?: 'Reverse geocoding failed', 'http_code' => $httpCode]);
