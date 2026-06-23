<?php
/**
 * ============================================================================
 * SECURE CONNECTION LOADER
 * ============================================================================
 */

// Prevent direct access
if (!defined('SECURE_CONFIG_LOADED')) {
    define('SECURE_CONFIG_LOADED', true);
}

/**
 * Get the path to the secure config folder
 * Automatically detects location relative to current file
 * 
 * @return string Absolute path to secure_config folder
 */
function getSecureConfigPath()
{
    // Get the directory 2 levels up from this file (config/secure_connection.php)
    // This should be the home directory (same level as public_html)
    $currentDir = __DIR__; // config/
    $appDir = dirname($currentDir); // da/ (or root of app)
    $homeDir = dirname($appDir); // Should be home directory

    // Try to find secure_config folder
    $possiblePaths = [
        $homeDir . '/secure_config',           // Standard cPanel structure
        dirname($homeDir) . '/secure_config',  // Alternative structure
        '/home/' . get_current_user() . '/secure_config', // User home directory
        $appDir . '/secure_config', // FINAL FALLBACK - Inside application directly
    ];

    // Check if any of these paths exist
    foreach ($possiblePaths as $path) {
        if (is_dir($path) && is_readable($path)) {
            return $path;
        }
    }

    // Fallback: Try to use environment variable
    if (isset($_ENV['SECURE_CONFIG_PATH']) && is_dir($_ENV['SECURE_CONFIG_PATH'])) {
        return $_ENV['SECURE_CONFIG_PATH'];
    }

    // If nothing found, return default (you should update this)
    // For cPanel: /home/username/secure_config
    // Update 'username' with your actual cPanel username
    return $homeDir . '/secure_config';
}

/**
 * Load secure database configuration
 * 
 * @return array Database configuration array
 * @throws Exception If config file cannot be loaded
 */
function loadSecureDBConfig()
{
    $configPath = getSecureConfigPath() . '/db_config.php';

    if (!file_exists($configPath)) {
        // Fallback to old location for backward compatibility during migration
        $fallbackPath = __DIR__ . '/db.php';
        if (file_exists($fallbackPath)) {
            error_log("WARNING: Using fallback db.php. Please move to secure_config/db_config.php");
            require_once $fallbackPath;
            return $db_config ?? [];
        }
        throw new Exception("Database config file not found. Please create secure_config/db_config.php");
    }

    if (!is_readable($configPath)) {
        throw new Exception("Database config file is not readable. Check file permissions.");
    }

    return require $configPath;
}

/**
 * Load secure email/SMTP configuration
 * 
 * @return array Email configuration array
 * @throws Exception If config file cannot be loaded
 */
function loadSecureEmailConfig()
{
    $configPath = getSecureConfigPath() . '/email_config.php';

    if (!file_exists($configPath)) {
        // Fallback to old location for backward compatibility during migration
        $fallbackPath = dirname(__DIR__) . '/app/config/email.php';
        if (file_exists($fallbackPath)) {
            error_log("WARNING: Using fallback email.php. Please move to secure_config/email_config.php");
            return require $fallbackPath;
        }
        throw new Exception("Email config file not found. Please create secure_config/email_config.php");
    }

    if (!is_readable($configPath)) {
        throw new Exception("Email config file is not readable. Check file permissions.");
    }

    return require $configPath;
}

/**
 * Load secure Google OAuth configuration
 * 
 * @return array Google OAuth configuration array
 * @throws Exception If config file cannot be loaded
 */
function loadSecureGoogleOAuthConfig()
{
    $configPath = getSecureConfigPath() . '/google_oauth_config.php';

    if (!file_exists($configPath)) {
        // Fallback to old location for backward compatibility during migration
        $fallbackPath = dirname(__DIR__) . '/app/config/google_oauth.php';
        if (file_exists($fallbackPath)) {
            error_log("WARNING: Using fallback google_oauth.php. Please move to secure_config/google_oauth_config.php");
            $config = require $fallbackPath;
            // Handle the basePath logic from old config
            if (!function_exists('getBasePath')) {
                function getBasePath()
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
            if (!isset($config['redirect_uri'])) {
                $basePath = getBasePath();
                $config['redirect_uri'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
                    . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/auth/google_callback.php';
            }
            return $config;
        }
        throw new Exception("Google OAuth config file not found. Please create secure_config/google_oauth_config.php");
    }

    if (!is_readable($configPath)) {
        throw new Exception("Google OAuth config file is not readable. Check file permissions.");
    }

    $config = require $configPath;

    // Handle redirect_uri if not set
    if (!isset($config['redirect_uri']) || empty($config['redirect_uri'])) {
        if (!function_exists('getBasePath')) {
            function getBasePath()
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
        $basePath = getBasePath();
        $config['redirect_uri'] = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . $_SERVER['HTTP_HOST'] . $basePath . '/auth/google_callback.php';
    }

    return $config;
}

// Auto-load database connection if this file is included
// This maintains backward compatibility with existing code
if (!isset($pdo)) {
    try {
        $db_config = loadSecureDBConfig();

        // Support both old format (array) and new format (return array)
        if (!isset($db_config['host'])) {
            // Old format - $db_config is already set
            if (isset($db_config) && is_array($db_config)) {
                // Config is already loaded
            }
        }

        // Create database connection
        $dsn = "mysql:host={$db_config['host']};dbname={$db_config['dbname']};charset={$db_config['charset']}";
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
        ];

        $pdo = new PDO($dsn, $db_config['username'], $db_config['password'], $options);
    } catch (Exception $e) {
        error_log("Secure database connection failed: " . $e->getMessage());

        if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
            die("Database connection error. Please contact the administrator.");
        } else {
            die("Database connection failed: " . htmlspecialchars($e->getMessage()));
        }
    }
}

