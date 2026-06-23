<?php
/**
 * eSewa Failure Handler
 * Called by eSewa if payment is cancelled or failed.
 */

// Include Config & DB
require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/esewa_functions.php';

// 1. Identify Order safely
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

if ($order_id > 0) {
    // 2. Mark latest PENDING eSewa transaction as FAILED
    // We only update if it is currently PENDING. If COMPLETE, we ignore (rare race condition).
    $stmt = $pdo->prepare("
        UPDATE payment_transactions 
        SET status = 'FAILED', updated_at = NOW() 
        WHERE order_id = ? AND vendor = 'ESEWA' AND status = 'PENDING'
    ");
    $stmt->execute([$order_id]);

    // Update order status
    $stmt_order = $pdo->prepare("UPDATE orders SET payment_status = 'FAILED', payment_method = 'Online Payment Rejected', status = 'cancelled' WHERE id = ?");
    $stmt_order->execute([$order_id]);

    // Log failure reason if provided in URL (optional, e.g. ?q=fu from eSewa)
    // No specific error from eSewa in failure URL usually, just redirect.
}

// 3. Redirect to Order Tracking
$basePath = getBasePath();
$redirectUrl = $basePath . "/order-tracking.php?order_id={$order_id}&payment=failed&reason=cancelled";
header("Location: " . $redirectUrl);
exit;

?>