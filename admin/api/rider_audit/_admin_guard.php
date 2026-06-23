<?php
/**
 * Admin-only guard for rider audit APIs. Used by managerider.php.
 * Requires admin login, rider_id from GET. No rider session.
 */
require_once __DIR__ . '/../../includes/auth.php';
if (!isAdminLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Admin authorization required']);
    exit;
}
$riderId = isset($_GET['rider_id']) ? (int)$_GET['rider_id'] : 0;
if ($riderId <= 0) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Rider ID required']);
    exit;
}
