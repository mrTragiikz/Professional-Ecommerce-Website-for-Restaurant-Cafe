<?php
/**
 * API: Update Payment Details (Redesigned)
 * Handles COD, Online (COD), and SPLIT logic with Ledger entries.
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

// Inputs
$orderId = intval($_POST['order_id'] ?? 0);
$mode = trim($_POST['mode'] ?? ''); // 'cash', 'cod_online', 'split'
$cashAmount = floatval($_POST['cash_amount'] ?? 0);
$onlineAmount = floatval($_POST['online_amount'] ?? 0);
// Optional ref IDs (just logged if provided)
$refId = trim($_POST['ref_id'] ?? '');

if (!$orderId || empty($mode)) {
    echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
    exit;
}

global $pdo;

try {
    $pdo->beginTransaction();

    // 1. Fetch Order
    $stmt = $pdo->prepare("SELECT id, status, total, restaurant_id FROM orders WHERE id = ? FOR UPDATE");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        throw new Exception("Order not found");
    }

    $total = (float) $order['total'];
    $newOrderStatus = $order['status'];

    // Auto-confirm if pending
    if (strtolower($order['status']) === 'pending') {
        $newOrderStatus = 'confirmed';
    }

    // Auto-complete if received (Move to All Orders history)
    if (strtolower($order['status']) === 'received') {
        $newOrderStatus = 'completed';
    }

    // Default Update Values
    $paymentMethod = '';
    $finalCash = 0;
    $finalOnline = 0;

    // Transaction Data to Insert
    $transactions = []; // Array of ['vendor', 'amount']

    // 2. Logic based on Mode
    switch ($mode) {
        case 'cash':
            $paymentMethod = 'COD'; // Stays COD but is now PAID
            $finalCash = $total;
            $finalOnline = 0;
            $transactions[] = ['vendor' => 'CASH', 'amount' => $total];
            break;

        case 'cod_online':
        case 'online': // Handle generic online click as COD Online
        case 'online_cod':
            $paymentMethod = 'COD'; // Stays COD (paid online on delivery)
            $finalCash = 0;
            $finalOnline = $total;
            $transactions[] = ['vendor' => 'ESEWA', 'amount' => $total];
            break;

        case 'split':
            // Validate totals
            $sum = $cashAmount + $onlineAmount;
            // Allow small float difference
            if (abs($sum - $total) > 0.01) {
                throw new Exception("Split amounts ($sum) do not equal order total ($total)");
            }
            $paymentMethod = 'SPLIT';
            $finalCash = $cashAmount;
            $finalOnline = $onlineAmount;

            if ($finalCash > 0)
                $transactions[] = ['vendor' => 'CASH', 'amount' => $finalCash];
            if ($finalOnline > 0)
                $transactions[] = ['vendor' => 'ESEWA', 'amount' => $finalOnline];
            break;

        default:
            throw new Exception("Invalid payment mode: $mode");
    }

    // 3. Update Order Table
    $updateSql = "
        UPDATE orders 
        SET 
            payment_method = ?,
            payment_status = 'PAID',
            status = ?,
            paid_amount_cash = ?,
            paid_amount_online = ?,
            paid_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ";

    $stmtUpdate = $pdo->prepare($updateSql);
    $stmtUpdate->execute([
        $paymentMethod,
        $newOrderStatus,
        $finalCash,
        $finalOnline,
        $orderId
    ]);

    // 4. Insert Transactions (Ledger)
    // Check if `payment_transactions` table exists and columns match
    // Schema assumed: vendor, amount, status, order_id, transaction_uuid

    $insertSql = "INSERT INTO payment_transactions (order_id, vendor, amount, status, transaction_uuid, created_at) VALUES (?, ?, ?, 'COMPLETE', ?, NOW())";
    $stmtInsert = $pdo->prepare($insertSql);

    foreach ($transactions as $txn) {
        $uuid = uniqid('txn_', true); // Generate unique ID
        $stmtInsert->execute([
            $orderId,
            $txn['vendor'],
            $txn['amount'],
            $uuid
        ]);
    }

    // 5. Stock reduction safety check (if not updated by status change yet)
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
        'message' => 'Payment updated successfully',
        'mode' => $mode,
        'new_status' => $newOrderStatus
    ]);

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Update Payment Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>