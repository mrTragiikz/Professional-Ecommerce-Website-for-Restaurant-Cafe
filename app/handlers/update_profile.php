<?php
/**
 * Update User Profile Handler
 */

require_once __DIR__ . '/../functions/security.php';
require_once __DIR__ . '/../functions/auth.php';

initSecureSession();

header('Content-Type: application/json');

// Keep JSON responses clean (avoid PHP notices/warnings breaking JSON)
ini_set('display_errors', '0');
error_reporting(E_ERROR | E_PARSE);
if (ob_get_level()) {
    ob_clean();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

// Check if user is logged in
if (!isUserLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Please log in to update your profile']);
    exit;
}

// CSRF verification disabled as requested
$data = json_decode(file_get_contents('php://input'), true);

// Fallback to POST form data when JSON is not available (e.g., some hosts block JSON)
if (!is_array($data) || empty($data)) {
    $data = $_POST ?? [];
}


$user = getCurrentUser();
require_once __DIR__ . '/../../config/db.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$name = trim($data['name'] ?? '');
$phone = trim($data['phone'] ?? '');
$deliveryLocation = trim($data['delivery_location'] ?? '');
$streetLocation = trim($data['street_location'] ?? '');
$locationLat = $data['location_lat'] ?? null;
$locationLng = $data['location_lng'] ?? null;

// Get current user data to preserve fields that aren't being updated
try {
    $stmt = $pdo->prepare("SELECT name, phone, delivery_location, street_location, location_lat, location_lng FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $currentData = $stmt->fetch();
} catch (PDOException $e) {
    error_log("Get current user error: " . $e->getMessage());
    $currentData = [];
}

// Use provided values or keep existing ones
$name = !empty($name) ? $name : ($currentData['name'] ?? '');
$phone = !empty($phone) ? $phone : ($currentData['phone'] ?? null);
$deliveryLocation = !empty($deliveryLocation) ? $deliveryLocation : ($currentData['delivery_location'] ?? null);
$streetLocation = !empty($streetLocation) ? $streetLocation : ($currentData['street_location'] ?? null);
$locationLat = ($locationLat !== null) ? $locationLat : ($currentData['location_lat'] ?? null);
$locationLng = ($locationLng !== null) ? $locationLng : ($currentData['location_lng'] ?? null);

// Validation - name should not be empty (use existing if not provided)
if (empty($name)) {
    echo json_encode(['success' => false, 'error' => 'Name cannot be empty']);
    exit;
}

// Validate and format phone if provided
if (!empty($phone)) {
    // Ensure phone starts with +977 and has valid format
    if (!preg_match('/^\+977[0-9]{10}$/', $phone)) {
        $phoneDigits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phoneDigits) == 10) {
            $phone = '+977' . $phoneDigits;
        } elseif (strlen($phoneDigits) > 0) {
            if (strlen($phoneDigits) != 10) {
                echo json_encode(['success' => false, 'error' => 'Please enter a valid phone number (10 digits)']);
                exit;
            }
        }
    }
}

try {
    // Start transaction to ensure data consistency
    $pdo->beginTransaction();

    $stmt = $pdo->prepare("
        UPDATE users 
        SET name = ?,
            phone = ?,
            delivery_location = ?,
            street_location = ?,
            location_lat = ?,
            location_lng = ?,
            updated_at = NOW()
        WHERE id = ?
    ");

    $stmt->execute([
        $name,
        $phone ?: null,
        $deliveryLocation ?: null,
        $streetLocation ?: null,
        $locationLat,
        $locationLng,
        $user['id']
    ]);

    // Commit the transaction immediately
    $pdo->commit();

    // Verify the update by fetching the updated record from database
    $verifyStmt = $pdo->prepare("SELECT name, phone, delivery_location, street_location, location_lat, location_lng FROM users WHERE id = ?");
    $verifyStmt->execute([$user['id']]);
    $verifiedData = $verifyStmt->fetch(PDO::FETCH_ASSOC);

    // Return verified data from database (not form data)
    echo json_encode([
        'success' => true,
        'message' => 'Profile updated successfully',
        'name' => $verifiedData['name'] ?? $name,
        'phone' => $verifiedData['phone'] ?? ($phone ?: ''),
        'delivery_location' => $verifiedData['delivery_location'] ?? ($deliveryLocation ?: ''),
        'street_location' => $verifiedData['street_location'] ?? ($streetLocation ?: ''),
        'location_lat' => $verifiedData['location_lat'] ?? ($locationLat ?: ''),
        'location_lng' => $verifiedData['location_lng'] ?? ($locationLng ?: '')
    ]);
} catch (PDOException $e) {
    // Rollback on error
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Update profile error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Failed to update profile: ' . $e->getMessage()]);
}

