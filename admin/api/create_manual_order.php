<?php
/**
 * API: Create Manual Order
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

require_once __DIR__ . '/../../config/db.php';

try {
    global $pdo;
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (!$data) {
        throw new Exception("Invalid order data");
    }

    $customerName = trim($data['customer_name'] ?? '');
    $customerPhone = trim($data['customer_phone'] ?? '');
    $customerEmail = trim($data['customer_email'] ?? '');
    $serviceType = $data['service_type'] ?? 'delivery';
    $deliveryAddress = trim($data['delivery_address'] ?? '');
    $lat = !empty($data['location_lat']) ? floatval($data['location_lat']) : null;
    $lng = !empty($data['location_lng']) ? floatval($data['location_lng']) : null;
    $inputDistance = !empty($data['delivery_distance_km']) ? floatval($data['delivery_distance_km']) : null;
    $deliveryFee = floatval($data['delivery_fee'] ?? 0);
    $notes = trim($data['notes'] ?? '');
    $items = $data['items'] ?? [];

    if (empty($customerName) || empty($customerPhone) || empty($items)) {
        throw new Exception("Customer name, phone, and items are required.");
    }

    // 1. Get/Create Placeholder User for Manual Orders
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = 'manual@justkleek.com' LIMIT 1");
    $stmt->execute();
    $userId = $stmt->fetchColumn();

    if (!$userId) {
        $pdo->prepare("INSERT INTO users (name, email, phone, is_verified, status, profile_completed) VALUES ('Manual Orders', 'manual@justkleek.com', '0000000000', 1, 'ACTIVE', 1)")->execute();
        $userId = $pdo->lastInsertId();
    }

    // 2. Calculate Totals
    $subtotal = 0;
    foreach ($items as $item) {
        $subtotal += floatval($item['price']) * intval($item['quantity']);
    }
    $total = round($subtotal + $deliveryFee, 2);

    // 3. Generate Order ID
    $orderIdStr = 'ORD' . time() . strtoupper(substr(uniqid(), -5));

    $pdo->beginTransaction();

    // Get correct restaurant ID from admin session
    $selectedRestaurantId = getAdminBranchId() ?: 1;

    // 4. Insert Order
    $stmt = $pdo->prepare("
        INSERT INTO orders (
            user_id, order_id, restaurant_id, service_type,
            delivery_address, delivery_fee, subtotal, total, total_amount, status, is_read,
            payment_method, payment_status, created_ip, user_agent, notes,
            location_lat, location_lng, delivery_distance_km, customer_name, customer_phone, customer_email, created_at
        ) VALUES (
            :user_id, :order_id, :restaurant_id, :service_type,
            :delivery_address, :delivery_fee, :subtotal, :total, :total_amount, 'pending', 0,
            'COD', 'PENDING', :ip, 'Admin Manual Entry', :notes,
            :lat, :lng, :distance_km, :c_name, :c_phone, :c_email, NOW()
        )
    ");

    $manualDistance = $inputDistance;
    if ($manualDistance === null && $lat && $lng) {
        require_once __DIR__ . '/../../app/services/mapbox_distance.php';
        $distResult = getRoadDistanceAndFee($lat, $lng, $selectedRestaurantId);
        if ($distResult['success']) {
            $manualDistance = $distResult['distance_km'];
        }
    }

    // 4.5 Distance Validation (Respect admin/delivery.php settings)
    if ($serviceType === 'delivery' && $manualDistance !== null) {
        require_once __DIR__ . '/../../config/load_security.php';
        $dSettings = getDeliverySettings($selectedRestaurantId);
        $maxDist = $dSettings['max_distance'] ?? 25; // fallback
        
        if ($maxDist > 0 && $manualDistance > $maxDist) {
            throw new Exception("Delivery distance ($manualDistance KM) exceeds the branch limit of $maxDist KM.");
        }
    }

    $stmt->execute([
        ':user_id' => $userId,
        ':order_id' => $orderIdStr,
        ':restaurant_id' => $selectedRestaurantId,
        ':service_type' => $serviceType,
        ':delivery_address' => $deliveryAddress,
        ':delivery_fee' => $deliveryFee,
        ':subtotal' => $subtotal,
        ':total' => $total,
        ':total_amount' => $total,
        ':ip' => $_SERVER['REMOTE_ADDR'] ?? '::1',
        ':notes' => $notes,
        ':lat' => $lat,
        ':lng' => $lng,
        ':distance_km' => $manualDistance,
        ':c_name' => $customerName,
        ':c_phone' => $customerPhone,
        ':c_email' => $customerEmail
    ]);

    $dbOrderId = $pdo->lastInsertId();

    // 5. Insert Items
    $itemStmt = $pdo->prepare("
        INSERT INTO order_items (
            order_id, product_id, item_name, item_description, quantity, unit_price, line_total, item_image
        ) VALUES (
            :order_id, 0, :item_name, :item_description, :quantity, :unit_price, :line_total, :item_image
        )
    ");

    foreach ($items as $item) {
        $itemQty = intval($item['quantity']);
        $itemPrice = floatval($item['price']);
        $itemTotal = round($itemPrice * $itemQty, 2);
        
        $itemStmt->execute([
            ':order_id' => $dbOrderId,
            ':item_name' => $item['name'],
            ':item_description' => $item['description'] ?? null,
            ':quantity' => $itemQty,
            ':unit_price' => $itemPrice,
            ':line_total' => $itemTotal,
            ':item_image' => $item['image'] ?? null
        ]);
        
        // Update stock if tracked
        $pdo->prepare("UPDATE menu_items SET stock_count = GREATEST(0, stock_count - ?) WHERE item_name = ? AND track_stock = 1 AND restaurant_id = ?")->execute([$itemQty, $item['name'], $selectedRestaurantId]);
    }

    $pdo->commit();



    echo json_encode(['success' => true, 'order_id' => $orderIdStr, 'db_id' => $dbOrderId]);

} catch (Exception $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
