<?php
/**
 * Global Security Configuration (EXAMPLE TEMPLATE)
 * Centralized settings for Database, Email, OAuth, and API services.
 *
 * SETUP:
 *   1. Copy this file to "all_security_config.php" in the same folder.
 *   2. Fill in your own real credentials below.
 *   3. Keep "all_security_config.php" OUTSIDE of version control (already gitignored).
 *   4. This folder should live outside the public web root in production.
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) == basename(__FILE__)) {
    die('Direct access not allowed');
}

// Environment Detection
$is_localhost = false;
$host_check = $_SERVER['HTTP_HOST'] ?? '';
$server_name = $_SERVER['SERVER_NAME'] ?? '';

if (
    $host_check === 'localhost' ||
    strpos($host_check, '127.0.0.1') !== false ||
    strpos($host_check, '192.168.') !== false ||
    strpos($host_check, '10.') !== false ||
    strpos($host_check, '172.') !== false ||
    strpos($host_check, '.local') !== false ||
    strpos($host_check, '::1') !== false ||
    strpos($host_check, 'localhost:') !== false ||
    strpos($host_check, '127.0.0.1:') !== false ||
    $server_name === 'localhost' ||
    strpos($server_name, '127.0.0.1') !== false ||
    (isset($_SERVER['SERVER_ADDR']) && (
        $_SERVER['SERVER_ADDR'] === '127.0.0.1' ||
        $_SERVER['SERVER_ADDR'] === '::1' ||
        strpos($_SERVER['SERVER_ADDR'], '192.168.') === 0 ||
        strpos($_SERVER['SERVER_ADDR'], '10.') === 0
    )) ||
    (php_sapi_name() === 'cli' && (PHP_OS_FAMILY === 'Windows' || stripos(gethostname(), 'localhost') !== false))
) {
    $is_localhost = true;
}

// Database Connection Settings
if ($is_localhost) {
    $DB_CONFIG = [
        'host' => 'localhost',
        'dbname' => 'your_local_db_name',
        'charset' => 'utf8mb4',
        'username' => 'root',
        'password' => '',
    ];
} else {
    $DB_CONFIG = [
        'host' => 'localhost',
        'dbname' => 'your_production_db_name',
        'charset' => 'utf8mb4',
        'username' => 'your_production_db_user',
        'password' => 'your_production_db_password',
    ];
}

// Email/SMTP Configuration
$EMAIL_CONFIG = [
    'smtp_enabled'    => true,
    'smtp_host'       => 'smtp-relay.example.com',
    'smtp_port'       => 587,
    'smtp_username'   => 'your_smtp_username',
    'smtp_password'   => 'your_smtp_password',
    'smtp_encryption' => 'tls',
    'from_email'      => 'noreply@example.com',
    'from_name'       => 'Your App Name',
    'reply_to'        => 'support@example.com',
    'smtp_debug'      => false,
];

// Helper to determine base path for redirect URIs
if (!function_exists('_secure_config_get_base_path')) {
    function _secure_config_get_base_path()
    {
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
            $firstDir = $matches[1];
            if (!in_array($firstDir, ['auth', 'app', 'css', 'js', 'assets', 'includes'])) {
                return '/' . $firstDir;
            }
        }
        return '';
    }
}

// Redirect URI Configuration
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$basePath = _secure_config_get_base_path();

if ($is_localhost) {
    $redirectUri = 'http://localhost/your-app/auth/google_callback.php';
} else {
    $redirectUri = 'https://yourdomain.com/auth/google_callback.php';
}

// Google OAuth Settings
$GOOGLE_OAUTH_CONFIG = [
    'client_id' => 'your_google_client_id.apps.googleusercontent.com',
    'client_secret' => 'your_google_client_secret',
    'redirect_uri' => $redirectUri,
    'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
    'token_url' => 'https://oauth2.' . 'googleapis' . '.com/token',
    'userinfo_url' => 'https://www.' . 'googleapis' . '.com/oauth2/v2/userinfo',
    'scopes' => [
        'openid',
        'https://www.' . 'googleapis' . '.com/auth/userinfo.email',
        'https://www.' . 'googleapis' . '.com/auth/userinfo.profile'
    ]
];

// eSewa Payment Integration
$ESEWA_CONFIG = [
    'env'          => 'production',
    'form_url'     => 'https://epay.esewa.com.np/api/epay/main/v2/form',
    'status_url'   => 'https://epay.esewa.com.np/api/epay/transaction/status/',
    'product_code' => 'your_esewa_product_code',
    'secret_key'   => 'your_esewa_secret_key'
];

// Mapbox and Location Settings
$KITCHEN_CONFIG = [
    'latitude' => 27.703861,
    'longitude' => 84.452333,
    'mapbox_token' => 'your_mapbox_secret_token',
    'mapbox_public_token' => 'your_mapbox_public_token',
];

// Return Unified Configuration
return [
    'database' => $DB_CONFIG,
    'email' => $EMAIL_CONFIG,
    'google_oauth' => $GOOGLE_OAUTH_CONFIG,
    'esewa' => $ESEWA_CONFIG,
    'kitchen' => $KITCHEN_CONFIG,
    'mapbox' => [
        'secret_token' => $KITCHEN_CONFIG['mapbox_token'],
        'public_token' => $KITCHEN_CONFIG['mapbox_public_token']
    ],
];
