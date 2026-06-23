<?php
// Security initialization
require_once __DIR__ . '/app/functions/security_init.php';

require_once __DIR__ . '/app/functions/auth.php';
require_once __DIR__ . '/app/functions/email.php';
require_once __DIR__ . '/config/load_security.php';
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/app/functions/location.php';
require_once __DIR__ . '/app/services/mapbox_distance.php';
requireUserLogin();

// User verification status
$user = getCurrentUser();
$isVerified = $user && ($user['is_verified'] ?? 0);
$basePath = getBasePath();


// Initialize orders if not exists (for backward compatibility)
if (!isset($_SESSION['orders'])) {
    $_SESSION['orders'] = [];
}

/**
 * Cleanup old orders from user view
 */
function cleanupOldOrders(PDO $pdo, int $userId): void
{
    return;
}

// Handle order operations
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'save_order') {
        // Start output buffering to capture any unwanted warnings/notices that might corrupt JSON
        ob_start();

        $orderData = json_decode($_POST['order'] ?? '{}', true);

        if (!$orderData || !isset($orderData['order_id']) || !isset($orderData['items'])) {
            // Clear buffer before sending error
            if (ob_get_length())
                ob_end_clean();
            echo json_encode(['success' => false, 'error' => 'Invalid order data']);
            exit;
        }

        try {
            if (!isset($pdo) || $pdo === null) {
                throw new Exception("Database connection unavailable");
            }

            // Check store hours
            $user = getCurrentUser();
            $branchId = getCurrentCustomerBranchId();

            $settings = getRestaurantSettings($branchId);
            $tz = $settings['timezone'] ?? 'Asia/Kathmandu';
            $opening = $settings['opening_time'] ?? '11:00';
            $closing = $settings['closing_time'] ?? '02:00';
            $isManualClosed = !empty($settings['is_closed']);

            if ($isManualClosed) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (ob_get_length()) {
                    ob_end_clean();
                }
                echo json_encode(['success' => false, 'error' => 'Restaurant is currently closed. Please try again later.']);
                exit;
            }

            // Compute open/closed window in restaurant timezone, including overnight close.
            $now = new DateTime('now', new DateTimeZone($tz));
            $today = $now->format('Y-m-d');
            $openDt = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $opening, new DateTimeZone($tz));
            $closeDt = DateTime::createFromFormat('Y-m-d H:i', $today . ' ' . $closing, new DateTimeZone($tz));

            if (!$openDt || !$closeDt) {
                // If settings are malformed, fail safe (block ordering).
                if (ob_get_length()) {
                    ob_end_clean();
                }
                echo json_encode(['success' => false, 'error' => 'Restaurant timing configuration error. Please contact support.']);
                exit;
            }

            // Overnight handling: if closing time is earlier/equal to opening, close is next day.
            if ($closeDt <= $openDt) {
                $closeDt->modify('+1 day');
                // For overnight window, if current time is after midnight but before closing,
                // opening should be considered as yesterday.
                if ($now < $openDt) {
                    $openDt->modify('-1 day');
                }
            }

            $isOpenNow = ($now >= $openDt && $now < $closeDt);
            if (!$isOpenNow) {
                if (ob_get_length()) {
                    ob_end_clean();
                }
                echo json_encode(['success' => false, 'error' => 'Restaurant is currently closed. Please order during opening hours.']);
                exit;
            }

            // Start transaction
            $pdo->beginTransaction();

            // Calculate totals
            $subtotal = 0;
            $verifiedItems = []; // Store items with verified prices

            // Collect all item names for batch querying
            $itemNames = array_map(function ($item) {
                return $item['name'] ?? '';
            }, $orderData['items']);

            // Remove duplicates and empty names
            $itemNames = array_unique(array_filter($itemNames));

            // Fetch all prices in one query
            $priceMap = [];
            if (!empty($itemNames)) {
                $placeholders = implode(',', array_fill(0, count($itemNames), '?'));
                $batchStmt = $pdo->prepare("SELECT item_name, price, stock_count, track_stock FROM menu_items WHERE restaurant_id = ? AND item_name IN ($placeholders)");
                $params = array_values($itemNames);
                array_unshift($params, $branchId);
                $batchStmt->execute($params);
                $stockMap = [];
                while ($row = $batchStmt->fetch(PDO::FETCH_ASSOC)) {
                    $priceMap[$row['item_name']] = floatval($row['price']);
                    $stockMap[$row['item_name']] = [
                        'count' => (int) $row['stock_count'],
                        'track' => (int) $row['track_stock']
                    ];
                }
            }

            // Validate Stock for all items first
            foreach ($orderData['items'] as $item) {
                $itemName = $item['name'] ?? '';
                $itemQty = intval($item['quantity'] ?? 1);

                if (isset($stockMap[$itemName])) {
                    $stockInfo = $stockMap[$itemName];
                    if ($stockInfo['track'] == 1 && $itemQty > $stockInfo['count']) {
                        // Rollback and fail
                        $pdo->rollBack();
                        if (ob_get_length())
                            ob_end_clean();
                        echo json_encode([
                            'success' => false,
                            'error' => "Sorry, '{$itemName}' is out of stock (Only {$stockInfo['count']} left)."
                        ]);
                        exit;
                    }
                }
            }

            foreach ($orderData['items'] as $item) {
                $itemName = $item['name'] ?? '';
                $itemQty = intval($item['quantity'] ?? 1);

                // Fetch verified price from map
                if (isset($priceMap[$itemName])) {
                    $itemPrice = $priceMap[$itemName];
                } else {
                    // Fallback to client price ONLY if item not found (should not happen in normal flow)
                    // Log this potential issue
                    error_log("Warning: Item '$itemName' not found in database during order placement. Using client price.");
                    $itemPrice = floatval($item['price'] ?? 0);
                }

                // Calculate total strictly from items + delivery
                $subtotal += $itemPrice * $itemQty;

                // Store verified item data for insertion later
                $verifiedItems[] = [
                    'name' => $itemName,
                    'description' => $item['description'] ?? null,
                    'quantity' => $itemQty,
                    'price' => $itemPrice,
                    'line_total' => $itemPrice * $itemQty,
                    'image' => $item['image'] ?? null
                ];
            }

            // Verify delivery fee
            $serviceType = $orderData['service_type'] ?? 'delivery';
            $deliveryFee = 0.00;
            $deliveryDistance = null;

            if ($serviceType === 'delivery') {
                $userLat = $orderData['user_lat'] ?? $user['location_lat'] ?? null;
                $userLng = $orderData['user_lng'] ?? $user['location_lng'] ?? null;

                if (!$userLat || !$userLng) {
                    throw new Exception("Delivery location not configured. Please update your map location.");
                }

                // Try calculating via server service, passing branchId for accurate coordinates
                $calcResult = getRoadDistanceAndFee($userLat, $userLng, $branchId);

                if ($calcResult['success'] && floatval($calcResult['delivery_fee'] ?? 0) > 0) {
                    $deliveryFee = floatval($calcResult['delivery_fee']);
                    $deliveryDistance = floatval($calcResult['distance_km']);
                } else {
                    // Fallback for Localhost or API Failure: Use the values already calculated by the user's browser
                    $deliveryFee = floatval($orderData['delivery_fee'] ?? 0);
                    $deliveryDistance = floatval($orderData['delivery_distance'] ?? $orderData['distance_km'] ?? 0);

                    if ($deliveryFee <= 0) {
                        throw new Exception("Delivery distance could not be calculated. Please ensure your location is pinned correctly on the map.");
                    }
                }
            }

            // Calculate total
            $total = round($subtotal + $deliveryFee, 2);

            // Get IP and user agent
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

            // Get user ID
            $user = getCurrentUser();
            $userId = $user['id'] ?? null;

            if (!$userId) {
                throw new Exception('User not found. Please log in again.');
            }

            // Payment method and status
            $paymentMethod = $orderData['payment_method'] ?? 'COD'; // Default to COD
            // Map frontend payment method values to DB ENUM values if needed
            // Assuming frontend sends 'cod' or 'esewa'
            $dbPaymentMethod = strtoupper($paymentMethod); // COD, ESEWA (mapped to ONLINE), SPLIT
            if ($dbPaymentMethod === 'ESEWA')
                $dbPaymentMethod = 'ONLINE';

            $paymentStatus = 'PENDING'; // Always starts as pending

            // Insert order into database
            $stmt = $pdo->prepare("
                INSERT INTO orders (
                    user_id, order_id, restaurant_id, service_type,
                    pickup_time, delivery_fee, subtotal, total, total_amount, status, is_read,
                    payment_method, payment_status, paid_amount_cash, paid_amount_online,
                    created_ip, user_agent, notes, stock_updated, 
                    delivery_distance_km, location_lat, location_lng, delivery_address, created_at
                ) VALUES (
                    :user_id, :order_id, :restaurant_id, :service_type,
                    :pickup_time, :delivery_fee, :subtotal, :total, :total_amount, :status, 0,
                    :payment_method, :payment_status, 0, 0,
                    :created_ip, :user_agent, :notes, 0, 
                    :delivery_distance_km, :location_lat, :location_lng, :delivery_address, NOW()
                )
            ");

            $stmt->execute([
                ':user_id' => $userId,
                ':order_id' => $orderData['order_id'],
                ':restaurant_id' => $orderData['restaurant_id'] ?? getCurrentCustomerBranchId(),
                ':service_type' => $orderData['service_type'] ?? 'delivery',
                ':pickup_time' => null,
                ':delivery_fee' => $deliveryFee,
                ':subtotal' => $subtotal,
                ':total' => $total,
                ':total_amount' => $total,
                ':status' => 'pending',
                ':payment_method' => $dbPaymentMethod,
                ':payment_status' => $paymentStatus,
                ':created_ip' => $ip,
                ':user_agent' => $userAgent,
                ':notes' => $orderData['notes'] ?? null,
                ':delivery_distance_km' => $deliveryDistance,
                ':location_lat' => $userLat,
                ':location_lng' => $userLng,
                ':delivery_address' => $orderData['delivery_address'] ?? null
            ]);

            $orderDbId = $pdo->lastInsertId();

            // Insert order items
            $itemStmt = $pdo->prepare("
                INSERT INTO order_items (
                    order_id, product_id, item_name, item_description, quantity, unit_price, line_total, item_image
                ) VALUES (
                    :order_id, 0, :item_name, :item_description, :quantity, :unit_price, :line_total, :item_image
                )
            ");

            foreach ($verifiedItems as $item) {
                $itemStmt->execute([
                    ':order_id' => $orderDbId,
                    ':item_name' => $item['name'],
                    ':item_description' => $item['description'],
                    ':quantity' => $item['quantity'],
                    ':unit_price' => $item['price'],
                    ':line_total' => $item['line_total'],
                    ':item_image' => $item['image']
                ]);

                // Reduce Stock Immediately
                $reduceStockStmt = $pdo->prepare("UPDATE menu_items SET stock_count = GREATEST(0, stock_count - ?) WHERE item_name = ? AND track_stock = 1 AND restaurant_id = ?");
                $reduceStockStmt->execute([$item['quantity'], $item['name'], $branchId]);

                if ($reduceStockStmt->rowCount() > 0) {
                    $pdo->prepare("UPDATE orders SET stock_updated = 1 WHERE id = ?")->execute([$orderDbId]);
                }
            }

            // Handle ONLINE payment (eSewa) - Use payments table
            if ($dbPaymentMethod === 'ONLINE') {
                $transactionUuid = sprintf(
                    '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0x0fff) | 0x4000,
                    mt_rand(0, 0x3fff) | 0x8000,
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff),
                    mt_rand(0, 0xffff)
                );

                // Create payments record (Standard)
                // Also maintain legacy payment_transactions if needed, but we focus on `payments` as requested.
                // Assuming we migrate to `payments` table fully.
                $txnStmt = $pdo->prepare("
                    INSERT INTO payments (
                        order_id, vendor, transaction_uuid, amount, status, created_at
                    ) VALUES (
                        :order_id, 'ESEWA', :transaction_uuid, :amount, 'PENDING', NOW()
                    )
                ");

                $txnStmt->execute([
                    ':order_id' => $orderDbId,
                    ':transaction_uuid' => $transactionUuid,
                    ':amount' => $total
                ]);

                // BACKWARD COMPATIBILITY: Also insert into payment_transactions if it exists and differs
                // For now, let's update `payment_transactions` logic to use `payments` table if we replaced it?
                // Or just insert into both to be safe during migration?
                // Let's assume `payments` acts as the new source of truth.
                // We'll duplicate insert to `payment_transactions` to avoid breaking existing legacy code that queries it.
                try {
                    $legacyTxnStmt = $pdo->prepare("
                        INSERT INTO payment_transactions (
                            order_id, vendor, transaction_uuid, amount, status, created_at
                        ) VALUES (
                            :order_id, 'ESEWA', :transaction_uuid, :amount, 'PENDING', NOW()
                        )
                    ");
                    $legacyTxnStmt->execute([
                        ':order_id' => $orderDbId,
                        ':transaction_uuid' => $transactionUuid,
                        ':amount' => $total
                    ]);
                } catch (Exception $types) {
                    // Ignore if table doesn't exist or duplicate
                }
            }

            // ... (previous transaction commit code) ...
            $pdo->commit();



            // Also save to session for backward compatibility
            $orderData['id'] = $orderDbId;
            $orderData['payment_method'] = $dbPaymentMethod;
            // Update items with verified prices in session too
            $orderData['items'] = array_map(function ($item) {
                return [
                    'name' => $item['name'],
                    'description' => $item['description'],
                    'quantity' => $item['quantity'],
                    'price' => $item['price'], // Verified price
                    'image' => $item['image']
                ];
            }, $verifiedItems);

            $orderId = $orderData['order_id'];
            $_SESSION['orders'][$orderId] = $orderData;

            // Clear cart
            $_SESSION['cart'] = [];
            // Remove from DB cart if logged in
            $clearCartStmt = $pdo->prepare("DELETE FROM cart_items WHERE user_id = ?");
            if ($userId)
                $clearCartStmt->execute([$userId]);

            // Close connection and run background tasks
            $response = [
                'success' => true,
                'order_id' => $orderId,
                'db_id' => $orderDbId,
                'payment_method' => $dbPaymentMethod,
                'transaction_uuid' => isset($transactionUuid) ? $transactionUuid : null,
                'grand_total' => $total
            ];

            $jsonResponse = json_encode($response);

            // Clear any previous output
            if (ob_get_length())
                ob_end_clean();

            // Send headers to close connection
            header('Content-Encoding: none');
            header('Content-Length: ' . strlen($jsonResponse));
            header('Connection: close');

            echo $jsonResponse;

            // Flush output to browser
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                ob_flush();
                flush();
            }

            // Background Tasks
            // Notifications
            if (isset($user) && $dbPaymentMethod !== 'ONLINE' && function_exists('sendOrderToAdminEmail')) {
                // Ensure we don't time out if email takes long
                set_time_limit(120);
                ignore_user_abort(true);

                $orderData['total'] = $total;
                $orderData['display_id'] = 'ORD-' . str_pad($orderDbId, 5, '0', STR_PAD_LEFT);

                // Email functions might emit warnings - suppress them or log them
                // We've already sent output, so we can't output anything else or it might cause issues (though connection is closed)
                error_log("Sending background emails for Order #{$orderDbId}...");

                // sendOrderToAdminEmail($orderData, $user);

                /*
                if (function_exists('sendOrderConfirmationToCustomer')) {
                    sendOrderConfirmationToCustomer($orderData, $user);
                }
                */

                error_log("Background emails sent for Order #{$orderDbId}.");
            }

            exit;

        } catch (Throwable $e) {
            try {
                if (isset($pdo) && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $rollbackError) {
                // Ignore rollback errors
            }
            error_log("Order save error: " . $e->getMessage());

            // Clear buffer before sending error
            if (ob_get_length())
                ob_end_clean();

            echo json_encode(['success' => false, 'error' => 'Failed to save order: ' . $e->getMessage()]);
        }
        exit;
    }

    if ($_POST['action'] === 'update_status') {
        $orderId = $_POST['order_id'] ?? '';
        $status = $_POST['status'] ?? 'pending';

        if (isset($_SESSION['orders'][$orderId])) {
            $_SESSION['orders'][$orderId]['status'] = $status;
            $_SESSION['orders'][$orderId]['updated_at'] = date('Y-m-d H:i:s');
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => 'Order not found']);
        }
        exit;
    }
}

// Helper function to fetch and format orders (uses proven old logic)
// NOTE: Hides completed/cancelled orders older than 1 month from tracking.
function fetchUserOrders($pdo, $userId)
{
    if (!$pdo || !$userId) {
        return [];
    }

    // Only include:
    // - Any non-completed / non-cancelled orders (all time)
    // - Completed / cancelled orders from the last 1 month
    $stmt = $pdo->prepare("
        SELECT o.*, 
               GROUP_CONCAT(
                   CONCAT(
                       oi.item_name, '|',
                       COALESCE(oi.item_description, ''), '|',
                       oi.quantity, '|',
                       oi.unit_price, '|',
                       COALESCE(oi.item_image, '')
                   )
                   SEPARATOR '||'
               ) AS items_data
        FROM orders o
        LEFT JOIN order_items oi ON oi.order_id = o.id
        WHERE o.user_id = ?
          AND (
                o.status NOT IN ('completed', 'cancelled')
                OR o.created_at >= DATE_SUB(NOW(), INTERVAL 1 MONTH)
          )
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 100
    ");
    $stmt->execute([$userId]);
    $dbOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $orders = [];

    foreach ($dbOrders as $dbOrder) {
        $items = [];

        // Primary path: parse GROUP_CONCAT items_data
        if (!empty($dbOrder['items_data'])) {
            $itemsRaw = explode('||', $dbOrder['items_data']);
            foreach ($itemsRaw as $itemRaw) {
                $itemParts = explode('|', $itemRaw);
                if (count($itemParts) >= 4) {
                    $items[] = [
                        'name' => $itemParts[0],
                        'description' => $itemParts[1] ?: '',
                        'quantity' => (int) $itemParts[2],
                        'price' => (float) $itemParts[3],
                        'image' => isset($itemParts[4]) && $itemParts[4] ? $itemParts[4] : 'assets/plate.png',
                    ];
                }
            }
        }

        // Fallback path: direct SELECT from order_items if items_data is empty
        if (empty($items)) {
            $itemStmt = $pdo->prepare("
                SELECT item_name, item_description, quantity, unit_price, item_image 
                FROM order_items 
                WHERE order_id = ?
            ");
            $itemStmt->execute([$dbOrder['id']]);
            $itemRows = $itemStmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($itemRows as $itemRow) {
                $items[] = [
                    'name' => $itemRow['item_name'],
                    'description' => $itemRow['item_description'] ?: '',
                    'quantity' => (int) $itemRow['quantity'],
                    'price' => (float) $itemRow['unit_price'],
                    'image' => $itemRow['item_image'] ?: 'assets/plate.png',
                ];
            }
        }

        $orders[$dbOrder['order_id']] = [
            'id' => (int) $dbOrder['id'],
            'order_id' => $dbOrder['order_id'],
            'items' => $items,
            'service_type' => $dbOrder['service_type'],
            'pickup_time' => $dbOrder['pickup_time'],
            'delivery_fee' => (float) $dbOrder['delivery_fee'],
            'subtotal' => (float) $dbOrder['subtotal'],
            'total' => (float) ($dbOrder['total_amount'] ?: $dbOrder['total']),
            'status' => strtolower(trim($dbOrder['status'])),
            'created_at' => $dbOrder['created_at'],
            'updated_at' => $dbOrder['updated_at'],
        ];
    }

    return $orders;
}

// Handle GET request for orders (AJAX callback)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action']) && $_GET['action'] === 'get_orders') {
    header('Content-Type: application/json');
    try {
        $currentUser = getCurrentUser();
        $userId = $currentUser['id'] ?? 0;

        // Release session lock before running potentially slow DB queries.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        // Cleanup old completed/cancelled orders for this user (older than 1 month)
        cleanupOldOrders($pdo, (int) $userId);

        $orders = fetchUserOrders($pdo, $userId);
        echo json_encode(['success' => true, 'orders' => $orders]);
    } catch (Throwable $e) {
        error_log("Get orders error: " . $e->getMessage());
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// PERFORMANCE NOTE:
// Do not pre-fetch orders server-side for initial page load.
// This page must render fast; orders are loaded asynchronously via `?action=get_orders`.
$initialOrders = [];
?>
<!DOCTYPE html>

<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Order Tracking | JustKleek</title>
    <link rel="stylesheet" href="<?php echo $basePath; ?>/css/style.css?v=1.0.2">
    <link rel="stylesheet" href="<?php echo $basePath; ?>/assets/css/dv_mobile_nav_v3.css?v=1.0.2">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
    <link href="https://fonts.bunny.net/css2?family=Inter:wght@400;500;600;700&family=Poppins:wght@600;700&display=swap"
        rel="stylesheet">
    <script src="<?php echo $basePath; ?>/js/prevent-zoom.js?v=1.0.1"></script>
    <script src="<?php echo $basePath; ?>/js/click-sound.js?v=1.0.1"></script>
    <?php require_once __DIR__ . '/includes/meta_pixel.php'; ?>
    <style>
        .payment-status-message {
            padding: 16px 20px;
            border-radius: 16px;
            margin-bottom: 25px;
            display: flex;
            align-items: center;
            gap: 15px;
            animation: statusSlideDown 0.6s cubic-bezier(0.175, 0.885, 0.32, 1.275);
            backdrop-filter: blur(10px);
        }
        .payment-status-message.success {
            background: linear-gradient(135deg, rgba(76, 175, 80, 0.12) 0%, rgba(76, 175, 80, 0.05) 100%);
            border: 1px solid rgba(76, 175, 80, 0.3);
            color: #1b5e20;
        }
        .payment-status-message.failed {
            background: linear-gradient(135deg, rgba(244, 67, 54, 0.12) 0%, rgba(244, 67, 54, 0.05) 100%);
            border: 1px solid rgba(244, 67, 54, 0.3);
            color: #b71c1c;
        }
        .payment-status-message .status-icon {
            font-size: 26px;
            flex-shrink: 0;
            background: white;
            width: 44px;
            height: 44px;
            display: flex;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.05);
        }
        .payment-status-message .status-text h3 {
            margin: 0 0 4px 0;
            font-size: 16px;
            font-weight: 800;
        }
        .payment-status-message .status-text p {
            margin: 0;
            font-size: 13.5px;
            opacity: 0.85;
            line-height: 1.4;
        }
        @keyframes statusSlideDown {
            from { transform: translateY(-40px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .order-card.highlight-payment {
            border: 2px solid #ff9800;
            box-shadow: 0 0 20px rgba(255, 152, 0, 0.2);
            animation: highlightPulse 2s infinite;
        }
        @keyframes highlightPulse {
            0% { box-shadow: 0 0 10px rgba(255, 152, 0, 0.2); }
            50% { box-shadow: 0 0 25px rgba(255, 152, 0, 0.4); }
            100% { box-shadow: 0 0 10px rgba(255, 152, 0, 0.2); }
        }
    </style>
</head>


<body class="tracking-page">
    <!-- Navigation Bar -->
    <?php require_once __DIR__ . '/includes/navbar.php'; ?>


    <!-- Order Tracking Container -->
    <div class="tracking-container">
        <div class="tracking-wrapper">
            <div class="tracking-header">
                <h1 class="tracking-title">Order Tracking</h1>
                <p class="tracking-subtitle">Track your orders and see their status</p>
            </div>

            <?php if (isset($_GET['payment'])): ?>
                <div class="payment-status-message <?php echo $_GET['payment'] === 'success' ? 'success' : 'failed'; ?>">
                    <div class="status-icon">
                        <?php echo $_GET['payment'] === 'success' ? '✔' : '✘'; ?>
                    </div>
                    <div class="status-text">
                        <h3>Payment <?php echo $_GET['payment'] === 'success' ? 'Successful' : 'Failed / Cancelled'; ?></h3>
                        <p>
                            <?php 
                            if ($_GET['payment'] === 'success') {
                                echo "Thank you! Your payment has been processed successfully. Your order is now being prepared with extra love.";
                            } else {
                                echo "We couldn't complete the payment. Your order is still in our system, and you can try paying again or choose another method.";
                            }
                            ?>
                        </p>
                    </div>
                </div>
            <?php endif; ?>


            <div id="ordersList" class="orders-list">
                <!-- Orders will be populated by JavaScript -->
            </div>

            <div id="emptyOrdersMessage" class="empty-orders-message">
                <div class="empty-orders-icon">
                    <svg width="80" height="80" viewBox="0 0 80 80" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="40" cy="40" r="38" stroke="#d0d0d0" stroke-width="2" stroke-dasharray="4 4"
                            opacity="0.5" />
                        <path d="M40 25V40M40 40L47 47M40 40L33 47" stroke="#d0d0d0" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </div>
                <h3 class="empty-orders-title">No Orders Yet</h3>
                <p class="empty-orders-text">You haven't placed any orders yet. Start ordering from our delicious menu!
                </p>
                <a href="menu" class="empty-orders-button">Browse Menu</a>
            </div>
        </div>
    </div>

    <script>
        // User verification status
        const isLoggedIn = true; // User is logged in (requiredUserLogin ensures this)
        const isVerified = <?php echo json_encode($isVerified); ?>;

        // Check verification on page load
        if (isLoggedIn && !isVerified) {
            // Show verification modal after page loads
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof window.showVerificationModal === 'function') {
                    window.showVerificationModal();
                }
            });
        }

        // Pre-seeded orders from PHP for instant load
        let orders = <?php echo json_encode($initialOrders); ?>;

        // Fetch orders from database on page load (async)
        function loadOrders() {
            // Always render quickly first (empty state or previous data),
            // then fetch in background to avoid blocking initial navigation.
            renderOrders();
            // Use same-page endpoint to avoid routing/base-path issues
            fetch('?action=get_orders')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.orders) {
                        orders = data.orders;
                        renderOrders();
                        // Initialize order cards after first load
                        setTimeout(initOrderCards, 100);
                        
                        // Handle order highlighting from URL
                        setTimeout(() => {
                            const urlParams = new URLSearchParams(window.location.search);
                            const targetId = urlParams.get('order_id');
                            if (targetId) {
                                // Find card that matches DB ID
                                const card = Array.from(document.querySelectorAll('.order-card')).find(c => {
                                    const oId = c.dataset.orderId;
                                    return orders[oId] && (orders[oId].id == targetId || orders[oId].order_id == targetId);
                                });
                                
                                if (card) {
                                    card.classList.add('expanded');
                                    card.classList.add('highlight-payment');
                                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                                    
                                    // Remove highlight after 5 seconds
                                    setTimeout(() => card.classList.remove('highlight-payment'), 5000);
                                }
                            }
                        }, 500);

                    } else {
                        console.error('Failed to load orders:', data.error);
                        renderOrders(); // Still render empty state
                    }
                })
                .catch(err => {
                    console.error('Error loading orders:', err);
                    renderOrders(); // Still render empty state
                });
        }

        // Render orders
        function renderOrders() {
            const ordersList = document.getElementById('ordersList');
            const emptyMessage = document.getElementById('emptyOrdersMessage');

            if (!orders || Object.keys(orders).length === 0) {
                ordersList.innerHTML = '';
                emptyMessage.style.display = 'flex';
                return;
            }

            emptyMessage.style.display = 'none';

            // Sort orders by date (newest first)
            const sortedOrders = Object.entries(orders).sort((a, b) => {
                return new Date(b[1].created_at) - new Date(a[1].created_at);
            });

            ordersList.innerHTML = sortedOrders.map(([orderId, order]) => {
                const status = (order.status || 'pending').toLowerCase();
                const statusClass = status.replace(' ', '-');
                const statusColors = {
                    'pending': '#ff9800',
                    'confirmed': '#2196f3',
                    'preparing': '#2196f3',
                    'ready': '#ff9800',
                    'delivery': '#2196f3',
                    'received': '#4caf50',
                    'completed': '#4caf50',
                    'cancelled': '#f44336'
                };
                // Status labels - updated to match admin dashboard
                function getStatusLabel(status, serviceType) {
                    const statusLower = (status || '').toLowerCase();

                    if (statusLower === 'cancelled') {
                        return 'Order Cancelled';
                    } else if (statusLower === 'confirmed' || statusLower === 'preparing') {
                        return 'Confirmed and Preparing';
                    } else if (statusLower === 'ready') {
                        return 'Ready for Delivery';
                    } else if (statusLower === 'delivery') {
                        return 'In Transit';
                    } else if (statusLower === 'received') {
                        return 'Order Received';
                    } else if (statusLower === 'completed') {
                        return 'Completed';
                    } else if (statusLower === 'pending') {
                        return 'Pending';
                    }

                    // Fallback
                    return status || 'Pending';
                }

                const total = order.total || order.items.reduce((sum, item) => sum + (item.price * item.quantity), 0) + (order.delivery_fee || 0);
                const subtotal = order.subtotal || order.items.reduce((sum, item) => sum + (item.price * item.quantity), 0);
                const itemsCount = order.items.length;

                // Format Order ID to match Admin/Email (ORD-XXXXX)
                // Use order.id if available (Database ID), otherwise valid fallback or original string
                const displayId = order.id
                    ? 'ORD-' + String(order.id).padStart(5, '0')
                    : 'Order #' + orderId.substring(0, 8).toUpperCase();

                return `
                    <div class="order-card" data-order-id="${orderId}">
                        <div class="order-card-header order-card-clickable">
                            <div class="order-info">
                                <h3 class="order-number">${displayId.startsWith('Order') ? displayId : 'Order #' + displayId}</h3>
                                <p class="order-date">${new Date(order.created_at).toLocaleString('en-US', { year: 'numeric', month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit', hour12: true })}</p>
                                <div class="order-summary-collapsed">
                                    <span class="order-items-count">${itemsCount} item${itemsCount !== 1 ? 's' : ''}</span>
                                    <span class="order-total-collapsed">Rs. ${total.toFixed(2)}</span>
                                </div>
                            </div>
                            <div class="order-header-right">
                                <div class="order-status-badge" style="background: ${statusColors[status] || '#999'}20; color: ${statusColors[status] || '#999'};">
                                    <span class="status-dot" style="background: ${statusColors[status] || '#999'};"></span>
                                    ${getStatusLabel(status, order.service_type)}
                                </div>
                                <svg class="order-expand-icon" width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
                                    <path d="M5 7.5L10 12.5L15 7.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </div>
                        </div>
                        
                        <div class="order-card-content">
                            <div class="order-items-section">
                                <h4 class="order-items-title">Order Items</h4>
                                <div class="order-items-list">
                                    ${order.items.map(item => `
                                        <div class="order-item-row">
                                            <div class="order-item-info">
                                                <span class="order-item-name">${item.name}</span>
                                                <span class="order-item-quantity">x${item.quantity}</span>
                                            </div>
                                            <span class="order-item-price">Rs. ${(item.price * item.quantity).toFixed(2)}</span>
                                        </div>
                                    `).join('')}
                                </div>
                            </div>
                            
                            <div class="order-details-section">
                                <div class="order-detail-row">
                                    <span>Service Type:</span>
                                    <span class="order-detail-value">${order.service_type === 'delivery' ? 'Delivery' : 'Pickup'}</span>
                                </div>
                                ${order.service_type === 'pickup' && order.pickup_time ? `
                                    <div class="order-detail-row">
                                        <span>Pickup Time:</span>
                                        <span class="order-detail-value">${order.pickup_time}</span>
                                    </div>
                                ` : ''}
                                ${order.delivery_fee > 0 ? `
                                    <div class="order-detail-row">
                                        <span>Delivery Fee:</span>
                                         <span class="order-detail-value">Rs. ${order.delivery_fee.toFixed(2)}</span>
                                    </div>
                                ` : ''}
                            </div>
                            
                            <div class="order-total-section">
                                <div class="order-total-row" style="margin-bottom: 8px; font-size: 0.9em; opacity: 0.8;">
                                    <span>Subtotal:</span>
                                    <span>Rs. ${subtotal.toFixed(2)}</span>
                                </div>
                                <div class="order-total-row" style="margin-bottom: 8px; font-size: 0.9em; opacity: 0.8;">
                                    <span>Delivery Fee:</span>
                                    <span>Rs. ${(order.delivery_fee || 0).toFixed(2)}</span>
                                </div>
                                <div class="order-total-row" style="border-top: 1px solid rgba(255,255,255,0.1); padding-top: 12px; margin-top: 8px;">
                                    <span style="font-weight: 700;">Total Amount:</span>
                                    <span class="order-total-amount" style="font-weight: 800; font-size: 1.2em;">Rs. ${total.toFixed(2)}</span>
                                </div>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');
        }

        // Auto-refresh orders every 30 seconds to check for status updates
        function refreshOrders() {
            fetch('?action=get_orders')
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.orders) {
                        // Replace orders object with latest data from database
                        orders = data.orders;
                        renderOrders();
                        // Re-initialize order cards after render
                        setTimeout(initOrderCards, 100);
                    }
                })
                .catch(err => console.log('Error refreshing orders:', err));
        }

        // Load orders on page load
        loadOrders();

        // Add click handlers for expand/collapse
        function initOrderCards() {
            const orderCards = document.querySelectorAll('.order-card');
            orderCards.forEach(card => {
                const header = card.querySelector('.order-card-clickable');
                const content = card.querySelector('.order-card-content');
                const icon = card.querySelector('.order-expand-icon');

                if (header && content) {
                    header.addEventListener('click', function (e) {
                        // Don't expand if clicking on status badge
                        if (e.target.closest('.order-status-badge')) return;

                        card.classList.toggle('expanded');
                        if (icon) {
                            icon.style.transform = card.classList.contains('expanded') ? 'rotate(180deg)' : 'rotate(0deg)';
                        }
                    });
                }
            });
        }


        // Auto-refresh every 10 seconds to get status updates from admin
        setInterval(refreshOrders, 10000);
    </script>

    <!-- Footer Section -->
    <?php require_once __DIR__ . '/includes/footer.php'; ?>

    <!-- Email Verification Required Modal -->
    <?php if ($isLoggedIn && !$isVerified): ?>
        <?php require_once __DIR__ . '/includes/verification_modal.php'; ?>
        <script>
            // Auto-show modal
            document.addEventListener('DOMContentLoaded', function () {
                if (typeof showVerificationModal === 'function') {
                    showVerificationModal();
                }
            });
        </script>
    <?php endif; ?>

    <script src="<?php echo $basePath; ?>/assets/js/dv_mobile_nav.js" defer></script>
</body>

</html>