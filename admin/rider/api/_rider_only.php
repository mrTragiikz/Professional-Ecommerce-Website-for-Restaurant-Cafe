<?php
/**
 * Rider-only guard for popup APIs used by my_details.php.
 * Uses ONLY JK_RIDER_SESS - never checks admin. Rider can view their audit anytime.
 */
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require_once __DIR__ . '/../_session_init.php';

$riderId = isset($_SESSION['rider_id']) ? (int)$_SESSION['rider_id'] : 0;

if ($riderId <= 0) {
    header('Content-Type: application/json');
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}
