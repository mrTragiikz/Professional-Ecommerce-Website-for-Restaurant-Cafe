<?php
require_once __DIR__ . '/../config/esewa_config.php';

// Ensure we don't start session if already started
if (session_status() === PHP_SESSION_NONE) {
    // session_start(); // Let the caller handle session start if needed, but functions shouldn't force it unless necessary.
}

/**
 * Verify eSewa Payment for an Order via Status Check API
 * 
 * @param int $order_id
 * @param PDO $pdo
 * @return array ['success' => bool, 'message' => string, 'status' => string]
 */
function verify_esewa_payment($order_id, $pdo)
{
    try {
        // 1. Get the latest eSewa transaction for this order (from payment_transactions table)
        $stmt = $pdo->prepare("
            SELECT * FROM payment_transactions 
            WHERE order_id = ? AND vendor = 'ESEWA' 
            ORDER BY created_at DESC LIMIT 1
        ");
        $stmt->execute([$order_id]);
        $transaction = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$transaction) {
            return ['success' => false, 'message' => 'No eSewa transaction found for this order.', 'status' => 'NOT_FOUND'];
        }

        // Idempotency check: If already complete, return success immediately
        if ($transaction['status'] === 'COMPLETE') {
            return ['success' => true, 'message' => 'Payment already verified.', 'status' => 'COMPLETE'];
        }

        $transaction_uuid = $transaction['transaction_uuid'];
        $amount = $transaction['amount'];

        // 2. Prepare Status Check API URL
        $query_params = [
            'product_code' => ESEWA_PRODUCT_CODE,
            'total_amount' => number_format((float) $amount, 2, '.', ''),
            'transaction_uuid' => $transaction_uuid
        ];

        $status_url = ESEWA_STATUS_URL . "?" . http_build_query($query_params);

        // 3. Call Status Check API
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $status_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        curl_close($ch);

        // Debug Logging
        $logFile = __DIR__ . '/../logs/esewa_debug.log';
        $logEntry = date('Y-m-d H:i:s') . " - Order: $order_id - URL: $status_url - HTTP: $http_code - Response: $response - Error: $curl_error\n";
        @file_put_contents($logFile, $logEntry, FILE_APPEND);

        if ($response === false) {
            return ['success' => false, 'message' => 'Failed to connect to eSewa Status API: ' . $curl_error, 'status' => 'API_ERROR'];
        }

        $json = json_decode($response, true);

        // Log raw response
        $update_txn_log = $pdo->prepare("UPDATE payment_transactions SET response_json = ? WHERE id = ?");
        $update_txn_log->execute([$response, $transaction['id']]);

        // Parse status
        $esewa_status = $json['status'] ?? 'UNKNOWN';
        $ref_id = $json['ref_id'] ?? null;

        // 4. Handle Status
        if ($esewa_status === 'COMPLETE') {
            // MARK COMPLETE atomically
            $pdo->beginTransaction();
            try {
                // Update transaction
                $stmt_txn = $pdo->prepare("UPDATE payment_transactions SET status = 'COMPLETE', vendor_ref_id = ?, updated_at = NOW() WHERE id = ?");
                $stmt_txn->execute([$ref_id, $transaction['id']]);


                // Update order
                $stmt_order = $pdo->prepare("
                    UPDATE orders 
                    SET payment_status = 'PAID', 
                        ref_id = ?,
                        payment_method = 'eSewa', 
                        paid_amount_online = total_amount, 
                        paid_amount_cash = 0,
                        paid_at = NOW(),
                        updated_at = NOW(),
                        status = CASE 
                            WHEN LOWER(status) = 'pending' THEN 'preparing' 
                            WHEN LOWER(status) = 'received' THEN 'completed' 
                            ELSE status 
                        END,
                        is_read = 1
                    WHERE id = ?
                ");
                $stmt_order->execute([$ref_id, $order_id]);
                $pdo->commit();
                return ['success' => true, 'message' => 'Payment verified successfully.', 'status' => 'COMPLETE'];

            } catch (Exception $e) {
                $pdo->rollBack();
                return ['success' => false, 'message' => 'Database update failed: ' . $e->getMessage(), 'status' => 'DB_ERROR'];
            }

        } elseif ($esewa_status === 'CANCELED' || $esewa_status === 'NOT_FOUND') {
            // Payment failed or didn't happen
            if ($transaction['status'] !== 'FAILED') {
                $stmt_fail = $pdo->prepare("UPDATE payment_transactions SET status = 'FAILED', updated_at = NOW() WHERE id = ?");
                $stmt_fail->execute([$transaction['id']]);

                // Update Order Status to reflect rejection
                $stmt_order_fail = $pdo->prepare("UPDATE orders SET payment_status = 'FAILED', payment_method = 'Online Payment Rejected', status = 'cancelled' WHERE id = ?");
                $stmt_order_fail->execute([$order_id]);


            }
            return ['success' => false, 'message' => 'Payment failed or canceled.', 'status' => 'FAILED'];

        } elseif ($esewa_status === 'FULL_REFUND') {
            if ($transaction['status'] !== 'REFUNDED') {
                $stmt_ref = $pdo->prepare("UPDATE payment_transactions SET status = 'REFUNDED', updated_at = NOW() WHERE id = ?");
                $stmt_ref->execute([$transaction['id']]);
            }
            return ['success' => false, 'message' => 'Payment refunded.', 'status' => 'REFUNDED'];

        } else {
            // PENDING or AMBIGUOUS
            return ['success' => false, 'message' => 'Payment verification pending.', 'status' => 'PENDING'];
        }

    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Verification error: ' . $e->getMessage(), 'status' => 'ERROR'];
    }
}
?>