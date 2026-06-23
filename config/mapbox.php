<?php
// Config for Mapbox Directions API (Server-Side Only)
require_once __DIR__ . '/load_security.php';

$kitchenConfig = getKitchenConfig();
$deliverySettings = getDeliverySettings();

if (!defined('MAPBOX_SECRET_TOKEN')) {
    define('MAPBOX_SECRET_TOKEN', $kitchenConfig['mapbox_token'] ?? '');
}
if (!defined('MAPBOX_PUBLIC_TOKEN')) {
    define('MAPBOX_PUBLIC_TOKEN', $kitchenConfig['mapbox_public_token'] ?? '');
}
if (!defined('KITCHEN_LAT')) {
    define('KITCHEN_LAT', $kitchenConfig['latitude'] ?? 27.703861);
}
if (!defined('KITCHEN_LNG')) {
    define('KITCHEN_LNG', $kitchenConfig['longitude'] ?? 84.452333);
}
if (!defined('DELIVERY_RATE_PER_KM')) {
    define('DELIVERY_RATE_PER_KM', $deliverySettings['rate_per_km'] ?? 20);
}
if (!defined('DELIVERY_BASE_FEE')) {
    define('DELIVERY_BASE_FEE', $deliverySettings['base_fee'] ?? 20);
}
if (!defined('DELIVERY_MAX_DISTANCE')) {
    define('DELIVERY_MAX_DISTANCE', $deliverySettings['max_distance'] ?? 25);
}
