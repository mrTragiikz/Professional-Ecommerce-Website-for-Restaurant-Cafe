<?php
/**
 * JustKleek Admin eSewa Diagnostic Tool
 * Purpose: Verify eSewa Integration & Audit Report
 * Access: Admin only + debug=1 parameter
 */

// 1. Session & Auth Guard
require_once __DIR__ . '/../includes/auth.php';
requireAdminLogin();

// 2. Developer Debug Key Parameter Check
if (!isset($_GET['debug']) || $_GET['debug'] !== '1') {
    die("Developer diagnostic tool. Use ?debug=1 to access.");
}

// Helper functions for masking
function maskSecret($str)
{
    if (!$str)
        return 'NOT DEFINED';
    $len = strlen($str);
    if ($len <= 4)
        return str_repeat('*', $len);
    return str_repeat('*', $len - 4) . substr($str, -4);
}

function maskMerchant($str)
{
    if (!$str)
        return 'NOT DEFINED';
    $len = strlen($str);
    if ($len <= 2)
        return str_repeat('*', $len);
    return substr($str, 0, 1) . str_repeat('*', $len - 2) . substr($str, -1);
}

// 3. Initialize check statuses
$config_included = file_exists(__DIR__ . '/../../config/esewa_config.php');
if ($config_included) {
    require_once __DIR__ . '/../../config/esewa_config.php';
}

$m_code = defined('ESEWA_PRODUCT_CODE') ? ESEWA_PRODUCT_CODE : null;
$s_key = defined('ESEWA_SECRET_KEY') ? ESEWA_SECRET_KEY : null;
$env = defined('ESEWA_ENV') ? ESEWA_ENV : null;
$base_url = defined('ESEWA_FORM_URL') ? ESEWA_FORM_URL : null;

$has_success_url = file_exists(__DIR__ . '/../../esewa/success.php') || file_exists(__DIR__ . '/../../api/esewa_success.php');
$has_failure_url = file_exists(__DIR__ . '/../../esewa/failure.php') || file_exists(__DIR__ . '/../../api/esewa_failure.php');

// Database Checks
require_once __DIR__ . '/../../config/db.php';
$cols = [];
if (isset($pdo)) {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM orders");
        $cols = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Exception $e) {
        $cols = [];
    }
}

$has_tx_uuid = in_array('transaction_uuid', $cols);
$has_ref_id = in_array('refId', $cols) || in_array('ref_id', $cols);
$has_pay_status = in_array('payment_status', $cols);
$has_amount = in_array('amount', $cols) || in_array('total', $cols) || in_array('total_amount', $cols);

// Verification Logic Check
$has_verify_file = file_exists(__DIR__ . '/../../includes/esewa_functions.php') || file_exists(__DIR__ . '/../../verify_esewa.php');

// Security Checks
$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
    (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443) ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) == 'https');

$js_exposed = false;
$js_search_dirs = [__DIR__ . '/../../js', __DIR__ . '/../../assets/js'];
foreach ($js_search_dirs as $dir) {
    if (is_dir($dir)) {
        $files = glob($dir . '/*.js');
        foreach ($files as $file) {
            $content = @file_get_contents($file);
            if ($s_key && strpos($content, $s_key) !== false) {
                $js_exposed = true;
                break 2;
            }
        }
    }
}

// Determine Final Status
$all_ok = $config_included && $m_code && $s_key && $has_success_url && $has_failure_url &&
    $has_tx_uuid && $has_pay_status && $has_amount && $has_verify_file && $is_https && !$js_exposed;

$final_status = "CONFIGURATION INCOMPLETE";
if ($all_ok) {
    if (strtolower($env) === 'production' || strtolower($env) === 'prod') {
        $final_status = "READY FOR PRODUCTION";
    } else {
        $final_status = "TEST MODE ACTIVE";
    }
}

// HTML Helper
function renderBadge($condition)
{
    if ($condition === true) {
        return '<span class="badge badge-ok">OK</span>';
    } elseif ($condition === false) {
        return '<span class="badge badge-missing">MISSING</span>';
    } else {
        return '<span class="badge badge-warning">WARNING</span>';
    }
}
function renderStatusBadge($condition)
{
    if ($condition) {
        return '<span class="badge badge-ok">OK</span>';
    }
    return '<span class="badge badge-missing">MISSING / ERROR</span>';
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JustKleek Admin eSewa Diagnostic Tool</title>
    <style>
        :root {
            --primary: #2c3e50;
            --success: #10b981;
            --danger: #ef4444;
            --warning: #f59e0b;
            --bg: #f8fafc;
            --card-bg: #ffffff;
            --text: #334155;
            --border: #e2e8f0;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            line-height: 1.6;
            margin: 0;
            padding: 20px;
        }

        .container {
            max-width: 900px;
            margin: 0 auto;
        }

        h1 {
            text-align: center;
            color: var(--primary);
            margin-bottom: 30px;
            border-bottom: 3px solid var(--primary);
            padding-bottom: 10px;
        }

        .section {
            background: var(--card-bg);
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            border: 1px solid var(--border);
        }

        .section-title {
            font-size: 1.25rem;
            color: var(--primary);
            margin-top: 0;
            margin-bottom: 15px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 8px;
        }

        .check-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px dashed var(--border);
        }

        .check-item:last-child {
            border-bottom: none;
        }

        .check-label {
            font-weight: 500;
        }

        .check-value {
            font-family: monospace;
            background: #f1f5f9;
            padding: 2px 6px;
            border-radius: 4px;
            color: #475569;
        }

        .badge {
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
        }

        .badge-ok {
            background-color: var(--success);
        }

        .badge-missing {
            background-color: var(--danger);
        }

        .badge-warning {
            background-color: var(--warning);
        }

        .final-status {
            text-align: center;
            padding: 25px;
            border-radius: 8px;
            font-size: 1.5rem;
            font-weight: bold;
            color: white;
            text-transform: uppercase;
            margin-top: 30px;
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        }

        .status-production {
            background: linear-gradient(135deg, #059669, #10b981);
        }

        .status-test {
            background: linear-gradient(135deg, #2563eb, #3b82f6);
        }

        .status-incomplete {
            background: linear-gradient(135deg, #dc2626, #ef4444);
        }
    </style>
</head>

<body>

    <div class="container">
        <h1>JustKleek Admin eSewa Diagnostic Tool</h1>

        <div class="section">
            <h2 class="section-title">SECTION 1 &ndash; CONFIG FILE CHECK</h2>
            <div class="check-item">
                <span class="check-label">eSewa config file included successfully?</span>
                <?php echo renderStatusBadge($config_included); ?>
            </div>
            <div class="check-item">
                <span class="check-label">Merchant Code defined?</span>
                <div>
                    <span class="check-value">
                        <?php echo htmlspecialchars(maskMerchant($m_code)); ?>
                    </span>
                    <?php echo renderBadge((bool) $m_code); ?>
                </div>
            </div>
            <div class="check-item">
                <span class="check-label">Secret Key defined?</span>
                <div>
                    <span class="check-value">
                        <?php echo htmlspecialchars(maskSecret($s_key)); ?>
                    </span>
                    <?php echo renderBadge((bool) $s_key); ?>
                </div>
            </div>
            <div class="check-item">
                <span class="check-label">Success URL configured?</span>
                <?php echo renderStatusBadge($has_success_url); ?>
            </div>
            <div class="check-item">
                <span class="check-label">Failure URL configured?</span>
                <?php echo renderStatusBadge($has_failure_url); ?>
            </div>
            <div class="check-item">
                <span class="check-label">eSewa base URL configured?</span>
                <div>
                    <span class="check-value">
                        <?php echo htmlspecialchars($base_url ? $base_url : 'NOT DEFINED'); ?>
                    </span>
                    <?php echo renderBadge((bool) $base_url); ?>
                </div>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">SECTION 2 &ndash; ENVIRONMENT DETECTION</h2>
            <div class="check-item">
                <span class="check-label">Current Environment Mode</span>
                <?php
                if (strtolower($env) === 'production' || strtolower($env) === 'prod') {
                    echo '<span class="badge badge-ok">PRODUCTION</span>';
                } elseif (strtolower($env) === 'test' || strtolower($env) === 'sandbox') {
                    echo '<span class="badge badge-warning">TEST</span>';
                } else {
                    echo '<span class="badge badge-missing">UNKNOWN / NOT DEFINED</span>';
                }
                ?>
            </div>
            <div class="check-item">
                <span class="check-label">eSewa Endpoint URL Used</span>
                <span class="check-value">
                    <?php echo htmlspecialchars($base_url ?? 'None'); ?>
                </span>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">SECTION 3 &ndash; DATABASE CHECK (Orders Table)</h2>
            <div class="check-item">
                <span class="check-label">Column: transaction_uuid</span>
                <?php echo renderStatusBadge($has_tx_uuid); ?>
            </div>
            <div class="check-item">
                <span class="check-label">Column: refId / ref_id</span>
                <?php echo renderStatusBadge($has_ref_id); ?>
            </div>
            <div class="check-item">
                <span class="check-label">Column: payment_status</span>
                <?php echo renderStatusBadge($has_pay_status); ?>
            </div>
            <div class="check-item">
                <span class="check-label">Column: amount / total</span>
                <?php echo renderStatusBadge($has_amount); ?>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">SECTION 4 &ndash; VERIFICATION LOGIC CHECK</h2>
            <div class="check-item">
                <span class="check-label">Backend Verification Endpoint Exists?</span>
                <?php echo renderStatusBadge($has_verify_file); ?>
            </div>
        </div>

        <div class="section">
            <h2 class="section-title">SECTION 5 &ndash; SECURITY CHECK</h2>
            <div class="check-item">
                <span class="check-label">HTTPS Enabled?</span>
                <?php echo $is_https ? '<span class="badge badge-ok">OK</span>' : '<span class="badge badge-warning">WARNING: NOT DETECTED (Localhost?)</span>'; ?>
            </div>
            <div class="check-item">
                <span class="check-label">Secret Key Exposed in JS?</span>
                <?php echo $js_exposed ? '<span class="badge badge-missing">EXPOSED (CRITICAL)</span>' : '<span class="badge badge-ok">SECURE</span>'; ?>
            </div>
        </div>

        <?php
        $status_class = '';
        if ($final_status === "READY FOR PRODUCTION")
            $status_class = 'status-production';
        elseif ($final_status === "TEST MODE ACTIVE")
            $status_class = 'status-test';
        else
            $status_class = 'status-incomplete';
        ?>
        <div class="final-status <?php echo $status_class; ?>">
            FINAL STATUS: <br />
            <?php echo $final_status; ?>
        </div>

    </div>

</body>

</html>