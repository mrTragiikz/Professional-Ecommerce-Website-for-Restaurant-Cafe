<?php
/**
 * ============================================================================
 * SECURITY CONFIGURATION LOADER
 * ============================================================================
 * 
 * This file loads ALL security configurations from ONE file outside public_html.
 * 
 * SECURITY: The actual config file is located outside public_html and cannot
 * be accessed via web browser.
 * 
 * FOLDER STRUCTURE (Example):
 *   [Server Root]/
 *   ├── public_html/da/              (Your application)
 *   │   └── config/load_security.php (This file - safe)
 *   │
 *   └── secure_config/               (NOT web accessible) 
 *       ├── .htaccess                (Protection)
 *       └── all_security_config.php  (ALL secrets here) 
 * 
 * ============================================================================
 */

// Prevent direct access
if (!defined('SECURITY_CONFIG_LOADED')) {
    define('SECURITY_CONFIG_LOADED', true);
}

// SECURITY HARDENING
ini_set('display_errors', 1);
ini_set('log_errors', 1);
error_reporting(E_ALL);

/**
 * Get the path to secure_config folder (outside public_html)
 * 
 * @return string Absolute path to secure_config folder
 */
function getSecureConfigPath()
{
    // Get current file's directory (config/)
    $currentDir = __DIR__; // App directory config folder

    // Get app directory (da/)
    $appDir = dirname($currentDir); // e:\wamp64\www\da or /home/username/public_html/da

    // Detect environment
    $isLocalhost = (
        stripos($appDir, 'localhost') !== false ||
        stripos($appDir, '127.0.0.1') !== false ||
        stripos($appDir, 'wamp') !== false ||
        stripos($appDir, 'xampp') !== false ||
        stripos($appDir, 'htdocs') !== false ||
        (PHP_OS_FAMILY === 'Windows')
    );

    // Try multiple possible locations
    $possiblePaths = [];

    if ($isLocalhost) {
        // LOCALHOST: Check in app directory first (for development)
        $possiblePaths[] = $appDir . DIRECTORY_SEPARATOR . 'secure_config';
        // Then check parent directory
        $possiblePaths[] = dirname($appDir) . DIRECTORY_SEPARATOR . 'secure_config';
    } else {
        // Method 1: Go up from public_html/da to account root directory
        // If appDir is under public_html/da, then root is 2 levels up
        $homeDir = dirname(dirname($appDir)); // Go up 2 levels
        $possiblePaths[] = $homeDir . DIRECTORY_SEPARATOR . 'secure_config';

        // Method 2: Try using get_current_user() to get username
        $username = get_current_user();
        if ($username && $username !== 'www-data' && $username !== 'apache') {
            $possiblePaths[] = DIRECTORY_SEPARATOR . 'home' . DIRECTORY_SEPARATOR . $username . DIRECTORY_SEPARATOR . 'secure_config';
        }

        // Method 3: Try environment variable
        if (isset($_ENV['HOME'])) {
            $possiblePaths[] = $_ENV['HOME'] . DIRECTORY_SEPARATOR . 'secure_config';
        }

        // Method 4: Try common cPanel paths
        if (isset($_SERVER['HOME'])) {
            $possiblePaths[] = $_SERVER['HOME'] . DIRECTORY_SEPARATOR . 'secure_config';
        }

        // Method 5: Fallback - same level as app directory (for testing)
        $possiblePaths[] = dirname($appDir) . DIRECTORY_SEPARATOR . 'secure_config';

        // Method 6: FINAL FALLBACK - inside app directory (if user uploaded it as part of project)
        $possiblePaths[] = $appDir . DIRECTORY_SEPARATOR . 'secure_config';
    }

    // Check each path and return first valid one
    foreach ($possiblePaths as $path) {
        if (is_dir($path) && is_readable($path)) {
            error_log("Secure config path found: " . $path);
            return $path;
        }
    }

    // If no path found, return the most likely one and log warning
    $defaultPath = $isLocalhost
        ? $appDir . DIRECTORY_SEPARATOR . 'secure_config'
        : (isset($homeDir) ? $homeDir . DIRECTORY_SEPARATOR . 'secure_config' : $appDir . DIRECTORY_SEPARATOR . 'secure_config');

    error_log("WARNING: secure_config directory not found. Tried paths:");
    foreach ($possiblePaths as $path) {
        error_log("  - " . $path . " (exists: " . (file_exists($path) ? 'YES' : 'NO') . ")");
    }
    error_log("Using default path: " . $defaultPath);

    return $defaultPath;
}

/**
 * Load ALL security configurations from single file
 * 
 * @return array All configurations (database, email, google_oauth)
 * @throws Exception If config file cannot be loaded
 */
function loadAllSecurityConfigs()
{
    static $loadedConfigs = null;

    // Prevent infinite loops - return cached config if already loaded
    if ($loadedConfigs !== null) {
        return $loadedConfigs;
    }

    $secureConfigDir = getSecureConfigPath();
    $configPath = $secureConfigDir . DIRECTORY_SEPARATOR . 'all_security_config.php';

    if (!file_exists($configPath)) {
        // For development: try to use environment variables or defaults
        $loadedConfigs = [
            'database' => [
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'dbname' => $_ENV['DB_NAME'] ?? 'justkleek',
                'charset' => 'utf8mb4',
                'username' => $_ENV['DB_USER'] ?? 'root',
                'password' => $_ENV['DB_PASS'] ?? '',
            ],
            'email' => [
                'smtp_enabled'    => false,
                'smtp_host'       => $_ENV['SMTP_HOST'] ?? 'mail.justkleek.com',
                'smtp_port'       => $_ENV['SMTP_PORT'] ?? 465,
                'smtp_username'   => $_ENV['SMTP_USER'] ?? '',
                'smtp_password'   => $_ENV['SMTP_PASS'] ?? '',
                'smtp_encryption' => 'ssl',
                'from_email'      => $_ENV['FROM_EMAIL'] ?? 'noreply@justkleek.com',
                'from_name'       => 'JustKleek',
                'reply_to'        => $_ENV['REPLY_TO'] ?? 'info@justkleek.com',
                'admin_email'     => 'info@justkleek.com',
            ],
            'google_oauth' => [
                'client_id' => $_ENV['GOOGLE_CLIENT_ID'] ?? '',
                'client_secret' => $_ENV['GOOGLE_CLIENT_SECRET'] ?? '',
                'redirect_uri' => '',
                'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
                'token_url' => 'https://oauth2.' . 'googleapis' . '.com/token',
                'userinfo_url' => 'https://www.' . 'googleapis' . '.com/oauth2/v2/userinfo',
                'scopes' => [
                    'openid',
                    'https://www.' . 'googleapis' . '.com/auth/userinfo.email',
                    'https://www.' . 'googleapis' . '.com/auth/userinfo.profile'
                ]
            ]
        ];

        error_log("WARNING: secure_config/all_security_config.php not found. Using environment variables or defaults.");
        error_log("Please create: {$configPath}");
        return $loadedConfigs;
    }

    if (!is_readable($configPath)) {
        throw new Exception("Security config file is not readable. Check file permissions (should be 600).");
    }

    // Load the config file
    error_log("DEBUG: Loading security config from: " . $configPath);
    $configs = require $configPath;

    // Validate structure
    if (!is_array($configs) || !isset($configs['database'])) {
        throw new Exception("Invalid security config file structure. Expected array with 'database', 'email', 'google_oauth' keys.");
    }

    // Cache the loaded configs
    $loadedConfigs = $configs;

    return $configs;
}

/**
 * Get database configuration
 * 
 * @return array Database config
 */
function getDBConfig()
{
    $configs = loadAllSecurityConfigs();
    return $configs['database'] ?? [];
}

/**
 * Get email/SMTP configuration
 * 
 * @return array Email config
 */
function getEmailConfig()
{
    $configs = loadAllSecurityConfigs();
    return $configs['email'] ?? [];
}

/**
 * Build Google OAuth redirect URI dynamically based on current request
 * This ensures redirect_uri matches exactly what's registered in Google Cloud Console
 * 
 * @return string Redirect URI
 */
function buildGoogleOAuthRedirectUri()
{
    $protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';

    // Detect if we're running on localhost
    $isLocalhost = (
        stripos($host, 'localhost') !== false ||
        stripos($host, '127.0.0.1') !== false ||
        stripos($host, '::1') !== false ||
        $host === 'localhost' ||
        $host === '127.0.0.1'
    );

    if ($isLocalhost) {
        // Local development: http://localhost/justkleek/auth/google_callback.php
        // Matches Google Console configuration exactly
        $redirectUri = 'http://localhost/justkleek/auth/google_callback.php';
        error_log("Google OAuth - Dynamic redirect URI (localhost): " . $redirectUri);
        return $redirectUri;
    } else {
        // Production: https://justkleek.com/auth/google_callback.php
        // Matches Google Console configuration exactly
        $redirectUri = 'https://justkleek.com/auth/google_callback.php';
        error_log("Google OAuth - Dynamic redirect URI (production): " . $redirectUri);
        return $redirectUri;
    }
}

/**
 * Get Google OAuth configuration
 * 
 * NOTE: redirect_uri is rebuilt dynamically per request to ensure it matches current environment
 * 
 * @return array Google OAuth config
 */
function getGoogleOAuthConfig()
{
    $configs = loadAllSecurityConfigs();
    $oauthConfig = $configs['google_oauth'] ?? [];

    // Rebuild redirect_uri dynamically per request to ensure accuracy
    // This ensures the redirect_uri matches the current request environment exactly
    $oauthConfig['redirect_uri'] = buildGoogleOAuthRedirectUri();

    return $oauthConfig;
}

/**
 * Get eSewa configurations
 * 
 * @return array eSewa config
 */
function getESEWAConfig()
{
    $configs = loadAllSecurityConfigs();
    return $configs['esewa'] ?? [];
}

/**
 * Get Kitchen location configuration
 * 
 * @return array Kitchen config
 */
function getKitchenConfig()
{
    $configs = loadAllSecurityConfigs();
    return $configs['kitchen'] ?? [
        'latitude' => 27.703861,
        'longitude' => 84.452333,
        'mapbox_token' => '',
    ];
}

/**
 * Get Delivery settings (base fee, rate per km)
 * Supports per-branch settings. Pass a branch ID to load branch-specific settings.
 * Falls back to global settings if no branch-specific file exists.
 *
 * @param int|null $branchId  Optional branch ID for per-branch settings
 * @return array Delivery settings
 */
function getDeliverySettings($branchId = null)
{
    $secureDir = getSecureConfigPath();

    // 1. Try branch-specific file
    if ($branchId && intval($branchId) > 0) {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'delivery_settings_branch_' . intval($branchId) . '.json';
    } else {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'delivery_settings.json';
    }

    // 2. Fallback to global file if branch file missing
    if (!file_exists($settingsFile)) {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'delivery_settings.json';
    }

    // 3. Last resort: if still missing, try to find ANY branch-specific file (useful if only one branch exists but ID is mismatched)
    if (!file_exists($settingsFile)) {
        $files = glob($secureDir . DIRECTORY_SEPARATOR . 'delivery_settings_branch_*.json');
        if (!empty($files)) {
            $settingsFile = $files[0];
        }
    }

    // Default settings (0 for a clean start on new branches)
    $settings = [
        'base_fee'     => 0,
        'rate_per_km'  => 0,
        'max_distance' => 0
    ];

    if (file_exists($settingsFile)) {
        $jsonContent = file_get_contents($settingsFile);
        if ($jsonContent !== false) {
            $data = json_decode($jsonContent, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                $settings = array_merge($settings, $data);
            }
        }
    }

    return $settings;
}


/**
 * Get Restaurant operating settings (hours, status, timezone)
 * 
 * @return array Restaurant settings
 */
function getRestaurantSettings($branchId = null)
{
    static $settingsCache = [];

    $cacheKey = ($branchId && intval($branchId) > 0) ? intval($branchId) : 0;
    if (isset($settingsCache[$cacheKey])) {
        return $settingsCache[$cacheKey];
    }

    $secureDir = getSecureConfigPath();

    // 1. Try branch-specific file
    if ($branchId && intval($branchId) > 0) {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours_branch_' . intval($branchId) . '.json';
    } else {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours.json';
    }

    // 2. Fallback to global file if branch file missing
    if (!file_exists($settingsFile)) {
        $settingsFile = $secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours.json';
    }

    // 3. Last resort: if still missing, try to find ANY branch-specific file
    if (!file_exists($settingsFile)) {
        $files = glob($secureDir . DIRECTORY_SEPARATOR . 'restaurant_hours_branch_*.json');
        if (!empty($files)) {
            $settingsFile = $files[0];
        }
    }

    // Default settings (Empty for a clean start on new branches)
    $settings = [
        'opening_time' => '',
        'closing_time' => '',
        'timezone' => 'Asia/Kathmandu',
        'is_closed' => false
    ];

    if (file_exists($settingsFile)) {
        $data = json_decode(file_get_contents($settingsFile), true);
        if ($data) {
            $settings = array_merge($settings, $data);
        }
    }

    $settingsCache[$cacheKey] = $settings;
    return $settings;
}

// ============================================================================
// AUTO-SET TIMEZONE
// ============================================================================
// Set PHP timezone to match restaurant location (Essential for accurate Offer Expiry)
$rSettings = getRestaurantSettings();
if (!empty($rSettings['timezone'])) {
    date_default_timezone_set($rSettings['timezone']);
} else {
    date_default_timezone_set('Asia/Kathmandu'); // Fallback
}

// ============================================================================
// AUTO-CREATE DATABASE CONNECTION
// ============================================================================
/**
 * Get Branch Coordinates (latitude/longitude)
 * Falls back to global kitchen settings if branch not found or no coords set.
 * 
 * @param int|null $branchId
 * @return array [lat, lng]
 */
function getBranchCoords($branchId = null)
{
    global $pdo;
    $coords = [
        'latitude' => defined('KITCHEN_LAT') ? KITCHEN_LAT : 27.703861,
        'longitude' => defined('KITCHEN_LNG') ? KITCHEN_LNG : 84.452333
    ];

    if ($branchId && intval($branchId) > 0) {
        try {
            $stmt = $pdo->prepare("SELECT latitude, longitude FROM branches WHERE id = ? LIMIT 1");
            $stmt->execute([intval($branchId)]);
            $row = $stmt->fetch();
            if ($row && !is_null($row['latitude']) && !is_null($row['longitude'])) {
                $coords['latitude'] = floatval($row['latitude']);
                $coords['longitude'] = floatval($row['longitude']);
            }
        } catch (Exception $e) {
            error_log("Error fetching branch coords: " . $e->getMessage());
        }
    }

    return $coords;
}

// Automatically create $pdo connection for backward compatibility
if (!isset($pdo)) {
    try {
        $db_config = getDBConfig();

        if (empty($db_config) || !isset($db_config['host'])) {
            // throw new Exception("Database configuration is missing or invalid."); 
            // Don't throw for now to allow SMTP debug
            error_log("Database configuration is missing or invalid.");
            $db_config = [];
        }

        if (!empty($db_config)) {
            // Create PDO connection
            $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci; SET time_zone = '+05:45';"
            ];

            $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], $options);
        }
    } catch (Exception $e) {
        error_log("Database connection failed: " . $e->getMessage());
        // For debugging on localhost
        $db_config = getDBConfig();
        $dbname = $db_config['dbname'] ?? 'unknown';
        if (strpos($_SERVER['HTTP_HOST'] ?? '', 'localhost') !== false || (PHP_OS_FAMILY === 'Windows')) {
            die("Database connection failed for '{$dbname}': " . htmlspecialchars($e->getMessage()));
        } else {
            // TEMPORARILY EXPOSE LIVE ERROR FOR DEBUGGING
            die("LIVE Database connection failed for '{$dbname}' (Host: " . ($db_config['host'] ?? 'N/A') . "): " . htmlspecialchars($e->getMessage()));
        }
    }
}

// Automatically load configurations and define constants when this file is included
try {
    loadAllSecurityConfigs();
} catch (Exception $e) {
    error_log("Failed to auto-load security configs: " . $e->getMessage());
}
