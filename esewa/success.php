<?php
// eSewa Success Handler (Strict V2 Implementation)

require_once __DIR__ . '/../app/functions/security_init.php';
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/esewa_config.php';
require_once __DIR__ . '/../includes/esewa_functions.php';

// 1. Get and Decode Data
$data = json_decode(base64_decode($_GET['data'] ?? ''), true);

if (!$data) {
    die('Invalid response data.');
}

// 2. Client-Side Signature Verification (First line of defense)
$response_signature = $data['signature'] ?? '';
$signed_field_names = $data['signed_field_names'] ?? '';

if (!$signed_field_names) {
    die('Invalid response: missing field names.');
}

// Reconstruct the message string dynamically
$fields = explode(',', $signed_field_names);
$message_parts = [];

foreach ($fields as $field) {
    $field = trim($field);
    if ($field === 'signed_field_names') {
        $message_parts[] = "signed_field_names=$signed_field_names";
    } else {
        // Wait, earlier I didn't remove comma for signature check. Let's keep it strictly raw from data.
        $message_parts[] = "$field=" . ($data[$field] ?? '');
    }
}

$message = implode(',', $message_parts);
$generated_signature = base64_encode(hash_hmac('sha256', $message, ESEWA_SECRET_KEY, true));

if ($generated_signature !== $response_signature) {
    error_log("eSewa Signature Mismatch.\nGenerated: $generated_signature\nReceived: $response_signature\nMessage: $message");
    die('Signature mismatch – possible fraud attempt. Transaction verification failed.');
}

// 3. Server-to-Server Status Verification (Final Truth) & Data Persistence
// We extract the Transaction UUID from the response to find the order.
$transaction_uuid = $data['transaction_uuid'] ?? '';

// Find the order ID associated with this transaction
$stmt = $pdo->prepare("SELECT order_id FROM payment_transactions WHERE transaction_uuid = ?");
$stmt->execute([$transaction_uuid]);

$txnRow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$txnRow) {
    die('Transaction record not found.');
}

$order_id = $txnRow['order_id'];

// Call the shared verification function which handles:
// - Status Check API Call
// - DB Updates (Transaction & Order) using atomic transactions
// - Logging
$result = verify_esewa_payment($order_id, $pdo);

if ($result['success']) {
    // Redirect to Success Page
    $basePath = getBasePath();
    header("Location: " . $basePath . "/order-tracking.php?order_id={$order_id}&payment=success");
    exit;
} else {
    // Redirect to Failure Page
    header("Location: failure.php?order_id={$order_id}&status=" . ($result['status'] ?? 'FAILED'));
    exit;
}

?>
