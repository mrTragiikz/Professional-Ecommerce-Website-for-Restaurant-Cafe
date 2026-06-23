<?php
// api/reverse_geocode.php - Secure OSM Reverse Geocoding with Caching
require_once __DIR__ . '/../app/functions/security.php';
initSecureSession();
header('Content-Type: application/json');

$lat = isset($_GET['lat']) ? filter_var($_GET['lat'], FILTER_VALIDATE_FLOAT) : null;
$lng = isset($_GET['lng']) ? filter_var($_GET['lng'], FILTER_VALIDATE_FLOAT) : null;

// Validation
if ($lat === false || $lng === false || $lat === null || $lng === null) {
    echo json_encode(['ok' => false, 'address' => '', 'message' => 'Invalid coordinates']);
    exit;
}

// 1. RATE LIMITING: Block repeat requests faster than 2 seconds per session
$now = time();
if (isset($_SESSION['last_geocode_time']) && ($now - $_SESSION['last_geocode_time']) < 2) {
    echo json_encode(['ok' => false, 'address' => '', 'message' => 'Rate limit exceeded. Please wait.']);
    exit;
}
$_SESSION['last_geocode_time'] = $now;

// 2. CACHING: Round to 5 decimals (approx 1 meter precision)
// This significantly increases cache hits for slightly moving GPS
$roundLat = round($lat, 5);
$roundLng = round($lng, 5);
$cacheKey = "geo_v2_{$roundLat}_{$roundLng}";

// Check session cache (expires in 10 minutes)
if (isset($_SESSION[$cacheKey]) && isset($_SESSION[$cacheKey . '_time']) && ($now - $_SESSION[$cacheKey . '_time']) < 600) {
    $cached = $_SESSION[$cacheKey];
    if (is_array($cached)) {
        $address = $cached['address'] ?? '';
        $area = $cached['area'] ?? '';
    } else {
        $address = $cached;
        $area = '';
    }
    echo json_encode(['ok' => true, 'address' => $address, 'area' => $area, 'cached' => true]);
    exit;
}

// 3. CALL MAPBOX REVERSE GEOCODING
require_once __DIR__ . '/../config/mapbox.php';

$mapboxToken = defined('MAPBOX_SECRET_TOKEN') ? MAPBOX_SECRET_TOKEN : '';
$url = sprintf('https://api.mapbox.com/geocoding/v5/mapbox.places/%F,%F.json?access_token=%s&limit=1&types=address,poi,neighborhood,locality', $lng, $lat, $mapboxToken);

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 6);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 3);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Fix for local WAMP/Windows SSL issues
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'User-Agent: JustKleekFoodDelivery/1.1',
    'Accept-Language: en'
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200 && $response) {
    $data = json_decode($response, true);
    if (isset($data['features']) && !empty($data['features'])) {
        $feature = $data['features'][0];
        $address = $feature['place_name'] ?? '';

        // Derive area (neighborhood or locality)
        $area = '';
        if (isset($feature['context'])) {
            foreach ($feature['context'] as $ctx) {
                if (strpos($ctx['id'], 'neighborhood') !== false || strpos($ctx['id'], 'locality') !== false) {
                    $area = $ctx['text'];
                    break;
                }
            }
        }

        if (empty($area) && isset($feature['text'])) {
            $area = $feature['text'];
        }

        // Cache it in session
        $_SESSION[$cacheKey] = [
            'address' => $address,
            'area' => $area,
        ];
        $_SESSION[$cacheKey . '_time'] = $now;

        echo json_encode(['ok' => true, 'address' => $address, 'area' => $area, 'cached' => false]);
        exit;
    }
}

// FALLBACK: Return coordinates if address lookup fails
echo json_encode([
    'ok' => true,
    'address' => "{$lat}, {$lng}",
    'area' => '',
    'message' => 'Reverse geocode failed, using coordinates'
]);
