<?php
header('Content-Type: application/json');
require_once __DIR__ . '/../RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

if (!$riderId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}


require_once __DIR__ . '/../../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Invalid request method']);
    exit;
}

$orderId = intval($_POST['order_id'] ?? 0);
$mode = trim($_POST['mode'] ?? '');
$cashAmount = floatval($_POST['cash_amount'] ?? 0);
$onlineAmount = floatval($_POST['online_amount'] ?? 0);
$tipsAmount = floatval($_POST['tips_amount'] ?? 0);

if (!$orderId || empty($mode)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

// 0. Fetch Rider Name for transaction notes - more resilient query
$riderName = "Unknown Rider";
try {
    $riderStmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
    $riderStmt->execute([$riderId]);
    $rRow = $riderStmt->fetch(PDO::FETCH_ASSOC);
    if ($rRow) {
        $riderName = $rRow['full_name'] ?? ($rRow['name'] ?? ($rRow['username'] ?? 'Rider'));
    }
} catch (Exception $e) {
    // Ignore error, use default
}

try {
    $pdo->beginTransaction();

    // 1. Fetch Order and verify rider assignment
    $stmt = $pdo->prepare("SELECT id, status, total, rider_id, payment_method, paid_amount_cash, paid_amount_online, restaurant_id FROM orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    if ($order['rider_id'] != $riderId) {
        throw new Exception("Unauthorized: This order is not assigned to you.");
    }

    $total = (float) $order['total'];
    $newOrderStatus = $order['status'];

    // Finalize: set to 'completed' when payment is confirmed
    // Status flow: ready -> delivery (grabbed) -> received (picked up) -> completed (paid)
    if (in_array(strtolower($order['status']), ['received', 'ready', 'delivery'])) {
        $newOrderStatus = 'completed';
    }

    $paymentMethod = '';
    $finalCash = 0;
    $finalOnline = 0;
    $transactions = [];

    switch ($mode) {
        case 'cash':
            $paymentMethod = 'COD';
            $finalCash = $total;
            $finalOnline = 0;
            $transactions[] = ['vendor' => 'CASH', 'amount' => $total];
            break;

        case 'cod_online':
            $paymentMethod = 'COD';
            $finalCash = 0;
            $finalOnline = $total;
            $transactions[] = ['vendor' => 'ESEWA', 'amount' => $total];
            break;

        case 'split':
            $sum = $cashAmount + $onlineAmount;
            if (abs($sum - $total) > 0.01) {
                throw new Exception("Split amounts do not equal order total");
            }
            $paymentMethod = 'SPLIT';
            $finalCash = $cashAmount;
            $finalOnline = $onlineAmount;
            if ($finalCash > 0)
                $transactions[] = ['vendor' => 'CASH', 'amount' => $finalCash];
            if ($finalOnline > 0)
                $transactions[] = ['vendor' => 'ESEWA', 'amount' => $finalOnline];
            break;

        case 'prepaid':
            // Logic for orders already paid (e.g. eSewa checkout) but needing rider finalization
            // We fetch the current method to keep it consistent
            $paymentMethod = $order['payment_method'] ?: 'ONLINE';
            $finalCash = (float) ($order['paid_amount_cash'] ?? 0);
            $finalOnline = (float) ($order['paid_amount_online'] ?? 0);
            if ($finalOnline == 0 && $finalCash == 0) {
                $finalOnline = $total; // Assume online if no split data
            }
            break;

        default:
            throw new Exception("Invalid payment mode");
    }

    // DO NOT prefix vendor with rider name because vendor is an ENUM('CASH', 'ESEWA')
    // and would cause a database error. We can use a comment or a different field if needed,
    // but for now let's just use the enum values.

    // Update Order
    $updateSql = "
        UPDATE orders 
        SET 
            payment_method = ?,
            payment_status = 'PAID',
            status = ?,
            paid_amount_cash = ?,
            paid_amount_online = ?,
            rider_tip = ?,
            updated_at = NOW(),
            delivered_at = COALESCE(delivered_at, NOW())
        WHERE id = ?
    ";
    $stmtUpdate = $pdo->prepare($updateSql);
    $stmtUpdate->execute([$paymentMethod, $newOrderStatus, $finalCash, $finalOnline, $tipsAmount, $orderId]);

    // Insert Transactions with rider info in UUID for traceability
    $insertSql = "INSERT INTO payment_transactions (order_id, vendor, amount, status, transaction_uuid, created_at) VALUES (?, ?, ?, 'COMPLETE', ?, NOW())";
    $stmtInsert = $pdo->prepare($insertSql);

    foreach ($transactions as $txn) {
        // UUID encodes rider name for identification in sales reports
        $safeRiderSlug = preg_replace('/[^a-zA-Z0-9_]/', '_', $riderName);
        $uuid = 'RIDER_' . $safeRiderSlug . '_' . $riderId . '_' . uniqid();
        $stmtInsert->execute([$orderId, $txn['vendor'], $txn['amount'], $uuid]);
    }

    // Log audit entry
    try {
        $auditStmt = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'payment_updated', ?)");
        $auditDesc = "Payment confirmed for Order #" . $orderId . " by " . $riderName . ". Mode: " . strtoupper($mode) . ". Total: Rs." . $total;
        $auditStmt->execute([$riderId, $auditDesc]);
    } catch (Exception $auditEx) {
        // Non-critical: ignore audit log failure
    }
    // Stock reduction check
    $checkStockStmt = $pdo->prepare("SELECT stock_updated FROM orders WHERE id = ?");
    $checkStockStmt->execute([$orderId]);
    $stockState = $checkStockStmt->fetch();

    if ($stockState && $stockState['stock_updated'] == 0) {
        $itemsStmt = $pdo->prepare("SELECT item_name, quantity FROM order_items WHERE order_id = ?");
        $itemsStmt->execute([$orderId]);
        $orderItems = $itemsStmt->fetchAll();

        foreach ($orderItems as $item) {
            $updateStock = $pdo->prepare("UPDATE menu_items SET stock_count = GREATEST(0, stock_count - ?) WHERE item_name = ? AND track_stock = 1 AND restaurant_id = ?");
            $updateStock->execute([$item['quantity'], $item['item_name'], $order['restaurant_id']]);
        }
        $pdo->prepare("UPDATE orders SET stock_updated = 1 WHERE id = ?")->execute([$orderId]);
    }

    $pdo->commit();

    echo json_encode([
        'success' => true,
        'message' => 'Payment updated and order marked as completed!',
        'new_status' => $newOrderStatus
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction())
        $pdo->rollBack();
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
