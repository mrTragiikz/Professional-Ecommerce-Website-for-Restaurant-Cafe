<?php
/**
 * API: Delete Order
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

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
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

    // Check if order is cancelled before allowing delete
    $checkStmt = $pdo->prepare("SELECT status FROM orders $whereSql");
    $checkStmt->execute($whereParams);
    $order = $checkStmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
        exit;
    }

    // Allow delete for cancelled and completed orders
    if ($order['status'] !== 'cancelled' && $order['status'] !== 'completed') {
        echo json_encode(['success' => false, 'error' => 'Only cancelled or completed orders can be deleted']);
        exit;
    }

    // 1. Delete order items manually (just in case CASCADE is missing)
    $pdo->prepare("DELETE FROM order_items WHERE order_id = ?")->execute([$orderId]);

    // 2. Delete payment transactions explicitly
    try {
        $pdo->prepare("DELETE FROM payment_transactions WHERE order_id = ?")->execute([$orderId]);
    } catch (PDOException $e) {
        // Table might not exist yet, ignore
    }

    // 3. Delete order
    $stmt = $pdo->prepare("DELETE FROM orders WHERE id = ?");
    $stmt->execute([$orderId]);

    if ($stmt->rowCount() > 0) {
        echo json_encode(['success' => true, 'message' => 'Order deleted successfully']);
    } else {
        // This might happen if order was already deleted
        echo json_encode(['success' => true, 'message' => 'Order deleted (or not found)']);
    }
} catch (PDOException $e) {
    error_log("Delete order error: " . $e->getMessage());
    // Return actual error for debugging
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
}

