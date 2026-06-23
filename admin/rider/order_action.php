<?php
require_once __DIR__ . '/RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$riderBranchId = $ctx['branchId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

require_once __DIR__ . '/../../config/db.php';

if (!$riderId) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: orders.php");
    exit;
}

$isAjax = isset($_POST['ajax']);

// CSRF check
if (empty($_SESSION['rider_csrf']) || empty($_POST['csrf_token']) || $_SESSION['rider_csrf'] !== $_POST['csrf_token']) {
    $error = "Invalid security token. Please refresh the page.";
    if ($isAjax) {
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
    header("Location: orders.php?error=" . urlencode($error));
    exit;
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// Shift Lock Enforcement (04:00 AM - 09:59 AM)
if (RiderCycleHelper::isLocked()) {
    $error = RiderCycleHelper::getStatusMessage();
    if ($isAjax) {
        echo json_encode(['success' => false, 'error' => $error]);
        exit;
    }
    header("Location: orders.php?error=" . urlencode($error));
    exit;
}

$action = $_POST['action'] ?? '';
$order_id = intval($_POST['order_id'] ?? 0);

if (!$order_id) {
    if ($isAjax) {
        echo json_encode(['success' => false, 'error' => 'Invalid order ID.']);
        exit;
    }
    header("Location: orders.php?error=" . urlencode("Invalid order ID."));
    exit;
}

try {
    if ($action === 'grab') {
        // 4. Delivery Grab Logic
        $pdo->beginTransaction();

        // Lock row to prevent race conditions & Ensure branch segregation
        $stmt = $pdo->prepare("SELECT id, rider_id, status, restaurant_id FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order not found.");
        }

        // Branch check: Rider can only grab orders from their own branch
        if ($riderBranchId > 0 && $order['restaurant_id'] != $riderBranchId) {
            throw new Exception("This order belongs to another branch and cannot be picked.");
        }

        // Must be ready and unassigned
        if ($order['status'] !== 'ready') {
            throw new Exception("Order is not ready for delivery.");
        }
        if (!empty($order['rider_id'])) {
            throw new Exception("Order already picked by another rider.");
        }

        // Assign rider, change status to 'delivery', log timestamp
        $updateStmt = $pdo->prepare("UPDATE orders SET rider_id = ?, status = 'delivery', grabbed_at = CURRENT_TIMESTAMP WHERE id = ?");
        $updateStmt->execute([$riderId, $order_id]);

        // Save audit record
        $auditStmt = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'pick_delivery', ?)");
        $auditStmt->execute([$riderId, "Picked Order #" . ($order['order_id'] ?? $order_id)]);

        $pdo->commit();

        if (isset($_POST['ajax'])) {
            echo json_encode(['success' => true, 'message' => 'Order picked successfully!']);
            exit;
        }
        header("Location: orders.php?msg=picked");
        exit;

    } elseif ($action === 'mark_received' || $action === 'update_status') {
        // Status change and COD Logic
        $codCollected = floatval($_POST['cod_collected'] ?? 0);
        $newStatus = $_POST['status'] ?? '';

        // If coming from dropdown, we only care if they selected 'completed'
        if ($action === 'update_status' && $newStatus !== 'completed') {
            header("Location: orders.php?error=" . urlencode("Riders can only change status to Completed."));
            exit;
        }

        $pdo->beginTransaction();

        $stmt = $pdo->prepare("SELECT id, order_id, rider_id, status, payment_method, delivery_fee, restaurant_id FROM orders WHERE id = ? FOR UPDATE");
        $stmt->execute([$order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            throw new Exception("Order not found.");
        }

        if ($order['rider_id'] != $riderId) {
            throw new Exception("Unauthorized access.");
        }

        // Rider can change ONLY ONE status: Ready for Delivery -> Completed
        // Flow: Current Status (ready/delivery) -> Next Status (received or completed if prepaid)
        if (!in_array($order['status'], ['ready', 'delivery'])) {
            throw new Exception("You can only change status from ready/delivery to completed.");
        }

        $riderEarning = floatval($order['delivery_fee'] ?? 0);
        $payStatus = strtolower($order['payment_status'] ?? 'pending');
        $isPrepaid = ($payStatus === 'paid');

        // Common Stock Reduction Logic: Reduce stock IMMEDIATELY when rider picks up food
        // This ensures the stock dashboard is always accurate and has no delay
        $checkStockStmt = $pdo->prepare("SELECT stock_updated FROM orders WHERE id = ?");
        $checkStockStmt->execute([$order_id]);
        $stockState = $checkStockStmt->fetch();

        if ($stockState && $stockState['stock_updated'] == 0) {
            $itemsStmt = $pdo->prepare("SELECT item_name, quantity FROM order_items WHERE order_id = ?");
            $itemsStmt->execute([$order_id]);
            $orderItems = $itemsStmt->fetchAll();

            foreach ($orderItems as $item) {
                // We use item_name to find the menu item (standard in this system)
                $uStock = $pdo->prepare("UPDATE menu_items SET stock_count = GREATEST(0, stock_count - ?) WHERE item_name = ? AND track_stock = 1 AND restaurant_id = ?");
                $uStock->execute([$item['quantity'], $item['item_name'], $order['restaurant_id']]);
            }
            // Mark as updated so it's not reduced again by other actions (like update_payment.php)
            $pdo->prepare("UPDATE orders SET stock_updated = 1 WHERE id = ?")->execute([$order_id]);
        }

        if ($isPrepaid) {
            // If already paid (e.g. eSewa), mark as completed immediately
            $updateStmt = $pdo->prepare("UPDATE orders SET status = 'completed', delivered_at = CURRENT_TIMESTAMP, rider_earning = ? WHERE id = ?");
            $updateStmt->execute([$riderEarning, $order_id]);

            $desc = "Marked Order #" . ($order['order_id'] ?? $order_id) . " as Completed (Already Paid).";
            $redirectUrl = "orders.php?filter=delivered&msg=delivered";
        } else {
            // Update status to 'received' (rider has picked up, payment pending)
            $updateStmt = $pdo->prepare("UPDATE orders SET status = 'received', delivered_at = CURRENT_TIMESTAMP, rider_earning = ? WHERE id = ?");
            $updateStmt->execute([$riderEarning, $order_id]);
            $desc = "Confirmed Food Pickup for Order #" . ($order['order_id'] ?? $order_id) . " from restaurant.";
            $redirectUrl = "orders.php?filter=received_orders&msg=received";
        }

        // Audit Record
        $auditStmt = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'mark_received', ?)");
        $auditStmt->execute([$riderId, $desc]);

        $pdo->commit();

        if ($isAjax) {
            echo json_encode(['success' => true, 'message' => 'Status updated!', 'redirect' => $redirectUrl]);
            exit;
        }

        header("Location: " . $redirectUrl);
        exit;

    } else {
        throw new Exception("Invalid action.");
    }

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log("Rider Order Action Error: " . $e->getMessage());
    if ($isAjax) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit;
    }
    header("Location: orders.php?error=" . urlencode($e->getMessage()));
    exit;
}
