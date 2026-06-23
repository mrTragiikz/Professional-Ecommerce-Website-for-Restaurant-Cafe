<?php
/**
 * API: Update Order Status
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$orderId = intval($_POST['order_id'] ?? 0);
$status = strtolower(trim($_POST['status'] ?? ''));

$validStatuses = ['confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
// Removed 'pending' - admin cannot set orders back to pending

if (!$orderId || empty($status) || !in_array($status, $validStatuses)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

try {
    global $pdo;

    // Verify the order exists and belongs to this branch
    $selectedBranchId = getAdminBranchId();
    $whereParams = [$orderId];
    $whereSql = "WHERE id = ?";
    
    if ($selectedBranchId) {
        $whereSql .= " AND restaurant_id = ?";
        $whereParams[] = $selectedBranchId;
    }

    $checkStmt = $pdo->prepare("SELECT id, status, total_amount, created_at, stock_updated, rider_id FROM orders $whereSql");
    $checkStmt->execute($whereParams);
    $order = $checkStmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
        exit;
    }

    // After rider grab, admin is not allowed to change status
    if (!empty($order['rider_id'])) {
        echo json_encode(['success' => false, 'error' => 'This order has been grabbed by a rider. Admin cannot change its status.']);
        exit;
    }

    // Orders are now moved to 'completed' status directly to show in history
    /*
    $payCheckStmt = $pdo->prepare("SELECT payment_status FROM orders WHERE id = ?");
    $payCheckStmt->execute([$orderId]);
    $payData = $payCheckStmt->fetch();

    if (strtolower($payData['payment_status'] ?? '') === 'paid') {
        $status = 'completed';
    }
    */

    // Update status and mark as read when status is changed
    $stmt = $pdo->prepare("UPDATE orders SET status = ?, is_read = 1, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$status, $orderId]);



    // Stock reduction/restoration logic
    // We reduce stock when status is set to 'confirmed' or 'completed'
    if (in_array($status, ['confirmed', 'completed']) && $order['stock_updated'] == 0) {
        $itemsStmt = $pdo->prepare("SELECT item_name, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $item) {
            // Always update stock count for all items if they match by name
            $updateStockStmt = $pdo->prepare("
                UPDATE menu_items 
                SET stock_count = stock_count - ?
                WHERE item_name = ?
            ");
            $updateStockStmt->execute([$item['quantity'], $item['item_name']]);
        }
        // Mark order as stock updated
        $pdo->prepare("UPDATE orders SET stock_updated = 1 WHERE id = ?")->execute([$orderId]);
    }
    // Restore stock if order is cancelled AFTER it was already reduced
    elseif ($status === 'cancelled' && $order['stock_updated'] == 1) {
        $itemsStmt = $pdo->prepare("SELECT item_name, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $items = $itemsStmt->fetchAll();

        foreach ($items as $item) {
            $restoreStockStmt = $pdo->prepare("
                UPDATE menu_items 
                SET stock_count = stock_count + ?
                WHERE item_name = ?
            ");
            $restoreStockStmt->execute([$item['quantity'], $item['item_name']]);
        }
        // Reset stock updated flag so it can be reduced again if status changes back
        $pdo->prepare("UPDATE orders SET stock_updated = 0 WHERE id = ?")->execute([$orderId]);
    }

    if ($stmt->rowCount() > 0 || ($status === $order['status'])) {
        echo json_encode(['success' => true, 'new_status' => $status]);
    } else {
        echo json_encode(['success' => false, 'error' => 'Failed to update order status']);
    }
} catch (PDOException $e) {
    error_log("Update order status error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}

