<?php
require_once __DIR__ . '/../../config/mapbox.php';

/**
 * Normalize delivery fee to clean, rounded amounts.
 *
 * Rules:
 * - Work in whole rupees (no paise).
 * - Snap to the nearest multiple of 5 with a soft threshold:
 *   e.g. 62 → 60, 63 → 65.
 */
function jk_normalize_delivery_fee($fee)
{
    $fee = max(0, round((float) $fee)); // whole rupees only
    $step = 5;
    $offset = 2; // 0–2 down, 3–4 up within each 5-rupee band

    return floor(($fee + $offset) / $step) * $step;
}

function getRoadDistanceAndFee($userLat, $userLng, $branchId = null)
{
    if (!is_numeric($userLat) || !is_numeric($userLng)) {
        return ['success' => false, 'error' => 'Invalid coordinates provided.'];
    }

    // Check valid coordinate ranges
    if ($userLat < -90 || $userLat > 90 || $userLng < -180 || $userLng > 180) {
        return ['success' => false, 'error' => 'Coordinates out of bounds.'];
    }

    // Load delivery settings for branch (falls back to global if $branchId is null or file missing)
    $settings = getDeliverySettings($branchId);
    $baseFee = $settings['base_fee'] ?? (defined('DELIVERY_BASE_FEE') ? DELIVERY_BASE_FEE : 20);
    $ratePerKm = $settings['rate_per_km'] ?? (defined('DELIVERY_RATE_PER_KM') ? DELIVERY_RATE_PER_KM : 20);

    // Get branch coordinates or fallback to system kitchen defaults
    $coords = getBranchCoords($branchId);
    $kitchenLat = $coords['latitude'];
    $kitchenLng = $coords['longitude'];

    $token = MAPBOX_SECRET_TOKEN;

    // Mapbox Directions API endpoint for driving
    // Format: .../driving/{longitude},{latitude};{longitude},{latitude}
    $url = "https://api.mapbox.com/directions/v5/mapbox/driving/{$kitchenLng},{$kitchenLat};{$userLng},{$userLat}?access_token={$token}&overview=false";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 8); // 8 seconds timeout

    // SSL CA Bundle Path
    $caPath = __DIR__ . '/../../config/cacert.pem';
    if (file_exists($caPath)) {
        curl_setopt($ch, CURLOPT_CAINFO, $caPath);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    } else {
        // Fallback for development if file missing, but log it
        error_log("CA bundle missing at $caPath, fallback to no-verify");
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['success' => false, 'error' => 'Unable to calculate road distance. Please try again. (Timeout/Network)'];
    }

    if ($httpCode !== 200) {
        return ['success' => false, 'error' => 'Unable to calculate road distance. Please try again. (API Error)'];
    }

    $data = json_decode($response, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'Unable to calculate road distance. Please try again. (Invalid Response)'];
    }

    if (!isset($data['routes']) || empty($data['routes']) || !isset($data['routes'][0]['distance'])) {
        return ['success' => false, 'error' => 'Unable to calculate road distance. Please try again. (No route found)'];
    }

    // Mapbox returns distance in meters
    $distanceMeters = $data['routes'][0]['distance'];
    $distanceKm = $distanceMeters / 1000;

    // Pricing Logic: Pure Linear (Base + Distance * Rate)
    $fee = $baseFee + ($distanceKm * $ratePerKm);

    // First compute from distance, then normalize to clean steps
    $fee = jk_normalize_delivery_fee($fee);
    $distancePrecise = round($distanceKm, 3);

    return [
        'success' => true,
        'distance_km' => $distancePrecise,
        'distance_text' => $distancePrecise . ' km',
        'delivery_fee' => $fee,
        'branch_id' => $branchId,
        'max_distance' => floatval($settings['max_distance'] ?? 0)
    ];
}
