<?php
// eSewa Payment Initiation Endpoint

// Security & Config
require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/esewa_config.php';

// Require login
requireUserLogin();

$basePath = getBasePath();
$userId = $_SESSION['user_id'];

// Get and validate order_id
$orderId = $_GET['order_id'] ?? null;

if (!$orderId) {
    die("Invalid request. Missing Order ID.");
}

// 1. Verify User Owns the Order and Fetch Details
$stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? AND user_id = ?");
$stmt->execute([$orderId, $userId]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    die("Order not found or access denied.");
}

// 2. Check if already paid
if ($order['payment_status'] === 'PAID' || $order['payment_status'] === 'COMPLETE') {
    header("Location: " . $basePath . "/order-tracking.php?order_id=" . $orderId . "&info=already_paid");
    exit;
}

// 3. Get or Create PENDING eSewa Transaction
// Check for existing PENDING transaction
$stmt_txn = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? AND vendor = 'ESEWA' AND status = 'PENDING' ORDER BY created_at DESC LIMIT 1");
$stmt_txn->execute([$orderId]);
$existing_txn = $stmt_txn->fetch(PDO::FETCH_ASSOC);

if ($existing_txn) {
    $transaction_uuid = strtoupper(bin2hex(random_bytes(6))); 

    // Update amount and uuid (Always update to ensure fresh attempt)
    $update = $pdo->prepare("UPDATE payment_transactions SET amount = ?, transaction_uuid = ? WHERE id = ?");
    $update->execute([$order['total'], $transaction_uuid, $existing_txn['id']]);

    // Save trace to orders table
    $updateOrder = $pdo->prepare("UPDATE orders SET transaction_uuid = ? WHERE id = ?");
    $updateOrder->execute([$transaction_uuid, $orderId]);
} else {
    // Create new transaction
    $transaction_uuid = strtoupper(bin2hex(random_bytes(6)));
    $insert = $pdo->prepare("INSERT INTO payment_transactions (order_id, transaction_uuid, vendor, amount, status, created_at) VALUES (?, ?, 'ESEWA', ?, 'PENDING', NOW())");
    $insert->execute([$orderId, $transaction_uuid, $order['total']]);

    // Save to orders table
    $updateOrder = $pdo->prepare("UPDATE orders SET transaction_uuid = ? WHERE id = ?");
    $updateOrder->execute([$transaction_uuid, $orderId]);
}


// 4. Prepare eSewa Form Data
$amountVal = (float) $order['total'];
$taxAmountVal = 0;
$serviceChargeVal = 0;
$deliveryChargeVal = 0;
// Note: In this system, order['total'] usually includes everything. 
// If we want to split, we need to extract from order logic. 
// For now, prompt sets charges to 0 and base amount to total, or similar. 
// Let's stick to simple: Total Amount = Order Total. Tax/Service/Delivery = 0.
$totalAmountVal = $amountVal + $taxAmountVal + $serviceChargeVal + $deliveryChargeVal;

$amount = number_format((float)$amountVal, 2, '.', '');
$tax_amount = number_format((float)$taxAmountVal, 2, '.', '');
$product_service_charge = number_format((float)$serviceChargeVal, 2, '.', '');
$product_delivery_charge = number_format((float)$deliveryChargeVal, 2, '.', '');
$total_amount = number_format((float)$totalAmountVal, 2, '.', '');





// IMPORTANT: Prompt rule says "total_amount must MATCH exactly" logic.
// eSewa signature is sensitive to strings vs numbers. 
// Standardize to simple values or leave as default PHP printing if integers.
// But prompt example shows integer/float mixing is risky.
// Let's use logic from prompt: $amount = 100; (integer).
// If we have decimals, we should be careful. 
// Best practice: cast to standard string or ensuring simple number format.

// URLs
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? "https" : "http";
$successUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}{$basePath}/esewa/success.php";
$failureUrl = "{$scheme}://{$_SERVER['HTTP_HOST']}{$basePath}/esewa/failure.php?order_id={$orderId}";
// Actually, eSewa redirects POST or GET with data. 
// Prompt example: success_url=".../esewa-success.php"
// We will pass order_id via session or rely on transaction_uuid lookup in success page?
// Wait, prompt success page decodes data["transaction_uuid"]. We can lookup order from that!
// So success URL can be generic.

// Generate Signature using new function
$signature = generateEsewaSignature($total_amount, $transaction_uuid, ESEWA_PRODUCT_CODE);

?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecting to eSewa...</title>
    <style>
        body {
            font-family: sans-serif;
            text-align: center;
            padding: 50px;
            background: #f4f4f4;
        }

        .loader {
            border: 5px solid #f3f3f3;
            border-top: 5px solid #60bb46;
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
            margin: 0 auto 20px;
        }

        @keyframes spin {
            0% {
                transform: rotate(0deg);
            }

            100% {
                transform: rotate(360deg);
            }
        }
    </style>
    <?php require_once __DIR__ . '/../includes/meta_pixel.php'; ?>
</head>

<body onload="document.getElementById('esewaForm').submit()">
    <div class="loader"></div>
    <h3>Redirecting to eSewa Payment...</h3>

    <form id="esewaForm" action="<?php echo ESEWA_FORM_URL; ?>" method="POST">
        <input type="hidden" name="amount" value="<?php echo $amount; ?>">
        <input type="hidden" name="tax_amount" value="<?php echo $tax_amount; ?>">
        <input type="hidden" name="product_service_charge" value="<?php echo $product_service_charge; ?>">
        <input type="hidden" name="product_delivery_charge" value="<?php echo $product_delivery_charge; ?>">
        <input type="hidden" name="total_amount" value="<?php echo $total_amount; ?>">
        <input type="hidden" name="transaction_uuid" value="<?php echo $transaction_uuid; ?>">
        <input type="hidden" name="product_code" value="<?php echo ESEWA_PRODUCT_CODE; ?>">
        <input type="hidden" name="success_url" value="<?php echo $successUrl; ?>">
        <input type="hidden" name="failure_url" value="<?php echo $failureUrl; ?>">
        <input type="hidden" name="signed_field_names" value="total_amount,transaction_uuid,product_code">
        <input type="hidden" name="signature" value="<?php echo $signature; ?>">
        <noscript><button type="submit">Pay with eSewa</button></noscript>
    </form>
</body>

</html>