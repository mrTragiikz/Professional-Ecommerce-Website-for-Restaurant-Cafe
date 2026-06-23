<?php
/**
 * API - Save Delivery Charge Settings
 */
require_once __DIR__ . '/../includes/auth.php';

// Ensure admin is logged in
if (!isAdminLoggedIn()) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/load_security.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!$data) {
        echo json_encode(['success' => false, 'error' => 'No data received']);
        exit;
    }

    $secureDir = getSecureConfigPath();

    // Determine which file to save (branch-specific or global)
    $branchId = isset($_GET['branch']) ? intval($_GET['branch']) : 0;
    if ($branchId > 0) {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'delivery_settings_branch_' . $branchId . '.json';
    } else {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'delivery_settings.json';
    }

    $settings = getDeliverySettings($branchId > 0 ? $branchId : null);

    // Validate and update fields
    if (isset($data['base_fee'])) {
        $settings['base_fee'] = floatval($data['base_fee']);
    }

    if (isset($data['rate_per_km'])) {
        $settings['rate_per_km'] = floatval($data['rate_per_km']);
    }

    if (isset($data['max_distance'])) {
        $settings['max_distance'] = floatval($data['max_distance']);
    }

    if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT), LOCK_EX)) {
        echo json_encode([
            'success'      => true,
            'message'      => 'Delivery settings updated successfully',
            'base_fee'     => $settings['base_fee'],
            'rate_per_km'  => $settings['rate_per_km'],
            'max_distance' => $settings['max_distance']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to save settings file']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
