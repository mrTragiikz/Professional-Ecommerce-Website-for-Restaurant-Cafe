<?php
/**
 * API - Get Restaurant Operating Settings
 * Returns the current opening/closing times and manual closed status.
 */
header('Content-Type: application/json');
header('Cache-Control: no-cache, must-revalidate');

// Start session if available to read selected branch
if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

// Define base directory
$baseDir = __DIR__ . '/..';

// Load security/config helpers
if (file_exists($baseDir . '/config/load_security.php')) {
    require_once $baseDir . '/config/load_security.php';
    require_once $baseDir . '/app/functions/auth.php';
    $branchId = null;
    if (isset($_GET['branch_id']) && $_GET['branch_id'] !== '') {
        $branchId = intval($_GET['branch_id']);
    } else {
        $branchId = getCurrentCustomerBranchId();
    }
    echo json_encode(getRestaurantSettings($branchId));
    exit;
}

// Fallback (redundant since getRestaurantSettings has defaults, but safe)
echo json_encode([
    'opening_time' => '11:00',
    'closing_time' => '02:00',
    'is_closed' => false,
    'timezone' => 'Asia/Kathmandu'
]);
