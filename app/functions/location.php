<?php
/**
 * Location and Distance Functions
 */

require_once __DIR__ . '/../../config/load_security.php';
require_once __DIR__ . '/../services/mapbox_distance.php';

/**
 * Calculate delivery distance and fee using Mapbox Directions API
 * 
 * @param float $userLat
 * @param float $userLng
 * @return array [success, distance_text, distance_km, delivery_fee]
 */
function calculateDeliveryFee($userLat, $userLng)
{
    return getRoadDistanceAndFee($userLat, $userLng);
}
