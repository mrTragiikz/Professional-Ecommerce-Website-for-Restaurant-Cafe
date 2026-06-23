<?php
/**
 * API - Save Restaurant Operating Settings
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
    $branchId = getAdminBranchId();
    if (!$branchId) {
        echo json_encode(['success' => false, 'error' => 'Please select a branch before saving time settings.']);
        exit;
    }
    $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours_branch_' . intval($branchId) . '.json';

    $settings = getRestaurantSettings($branchId);

    // Validate and update fields
    if (isset($data['opening_time'])) {
        $settings['opening_time'] = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $data['opening_time']) ? $data['opening_time'] : $settings['opening_time'];
    }

    if (isset($data['closing_time'])) {
        $settings['closing_time'] = preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', $data['closing_time']) ? $data['closing_time'] : $settings['closing_time'];
    }

    if (isset($data['is_closed'])) {
        $settings['is_closed'] = (bool) $data['is_closed'];
    }

    $settings['timezone'] = 'Asia/Kathmandu';

    if (file_put_contents($settingsFile, json_encode($settings, JSON_PRETTY_PRINT))) {
        echo json_encode([
            'success' => true,
            'message' => 'Settings updated successfully',
            'opening_time' => $settings['opening_time'],
            'closing_time' => $settings['closing_time'],
            'is_closed' => $settings['is_closed']
        ]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Unable to save settings file']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
}
