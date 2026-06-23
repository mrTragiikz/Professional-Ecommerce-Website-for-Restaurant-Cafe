<?php
// Load dynamic config from secure_config/all_security_config.php
require_once __DIR__ . '/load_security.php';

$esewaCfg = getESEWAConfig();

// All sensitive values (URLs, product_code, secret_key) now **must** come
// from secure_config/all_security_config.php via getESEWAConfig().
// We intentionally avoid hard-coded fallbacks here so nothing leaks in code.

if (
    empty($esewaCfg['env']) ||
    empty($esewaCfg['form_url']) ||
    empty($esewaCfg['status_url']) ||
    empty($esewaCfg['product_code']) ||
    empty($esewaCfg['secret_key'])
) {
    throw new RuntimeException(
        'eSewa configuration is incomplete. Please define ESEWA_CONFIG properly in secure_config/all_security_config.php.'
    );
}

define('ESEWA_ENV', $esewaCfg['env']);
define('ESEWA_FORM_URL', trim((string)$esewaCfg['form_url']));
define('ESEWA_STATUS_URL', trim((string)$esewaCfg['status_url']));
define('ESEWA_PRODUCT_CODE', trim((string)$esewaCfg['product_code']));
define('ESEWA_SECRET_KEY', trim((string)$esewaCfg['secret_key']));

/**
 * Generate HMAC SHA256 Signature for eSewa
 */
function generateEsewaSignature($total_amount, $transaction_uuid, $product_code)
{
    // 1. Force total_amount to exactly 2 decimal places (Production standard for eSewa v2)
    $total_amount = number_format((float) $total_amount, 2, '.', '');

    // 2. Build signature message EXACTLY as required
    $message = "total_amount={$total_amount},transaction_uuid={$transaction_uuid},product_code={$product_code}";
    
    // 3. Get the secret key and ensure it's clean (no BOM, whitespace, etc.)
    $secretKey = ESEWA_SECRET_KEY;
    // Strip any BOM or invisible characters that could corrupt the key during FTP upload
    $secretKey = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F\xEF\xBB\xBF]/', '', $secretKey);
    $secretKey = trim($secretKey);
    
    // 4. Generate HMAC-SHA256
    $s = hash_hmac('sha256', $message, $secretKey, true);

    // 5. Debug logging (helps diagnose ES104 errors on production)
    $debugFile = dirname(__DIR__) . '/logs/esewa_signature_debug.log';
    $debugLog = date('Y-m-d H:i:s') . "\n";
    $debugLog .= "  Message: {$message}\n";
    $debugLog .= "  Key Length: " . strlen($secretKey) . " chars\n";
    $debugLog .= "  Key First4: " . substr($secretKey, 0, 4) . "\n";
    $debugLog .= "  Key Last4: " . substr($secretKey, -4) . "\n";
    $debugLog .= "  Product Code: {$product_code}\n";
    $debugLog .= "  Signature: " . base64_encode($s) . "\n";
    $debugLog .= "---\n";
    @file_put_contents($debugFile, $debugLog, FILE_APPEND);

    // 6. Return Base64 Encoded Signature
    return base64_encode($s);
}
?>
