<?php
// Load centralized session config (defines JK_SESSION_NAME_USER, JK_SESSION_LIFETIME, helpers)
if (!defined('JK_SESSION_NAME_USER')) {
    require_once __DIR__ . '/../../config/session_config.php';
}
/**
 * Security Helper Functions
 * CSRF protection, rate limiting, session hardening
 *
 * IMPORTANT: This file must NOT require db.php or any file that may output
 * before session. Session init must run before ANY output.
 */

/**
 * Get the base path of the application (handles subdirectories)
 * @return string Base path (e.g., '/da' or '')
 */
function getBasePath()
{
    static $basePath = null;
    if ($basePath !== null) {
        return $basePath;
    }

    // Check if we're in a subdirectory by looking at SCRIPT_NAME
    $scriptName = $_SERVER['SCRIPT_NAME'];

    // If script is at /da/auth/login.php, base path is /da
    if (preg_match('#^/([^/]+)/#', $scriptName, $matches)) {
        // Check if it's not just 'auth' or other common dirs
        $firstDir = $matches[1];
        if (!in_array($firstDir, ['auth', 'app', 'css', 'js', 'assets', 'includes', 'admin', 'api', 'esewa', 'cron', 'tools', 'cache', 'uploads', 'PHPMailer', 'secure_config'])) {
            $basePath = '/' . $firstDir;
            return $basePath;
        }
    }

    // Default: no subdirectory
    $basePath = '';
    return $basePath;
}

/**
 * Initialize secure session with enhanced security
 */
function initSecureSession()
{
    if (session_status() !== PHP_SESSION_NONE) {
        return; // Already started
    }

    // Apply persistent 7-day session config (uses JK_USER_SESS name, isolated from admin/rider)
    jk_start_user_session();

    // Regenerate session ID periodically for security (prevent session fixation)
    if (!isset($_SESSION['created'])) {
        $_SESSION['created'] = time();
        $_SESSION['ip_address'] = getClientIP();
        $_SESSION['user_agent'] = $_SERVER['HTTP_USER_AGENT'] ?? '';
    } else {
        // Verify session hasn't been hijacked
        if (isset($_SESSION['ip_address']) && $_SESSION['ip_address'] !== getClientIP()) {
            // IP changed — log only, don't destroy (mobile users change IPs frequently)
            logSecurityEvent('session_ip_change', [
                'old_ip'  => $_SESSION['ip_address'],
                'new_ip'  => getClientIP(),
                'user_id' => $_SESSION['user_id'] ?? null
            ]);
        }

        // Regenerate session ID every 30 minutes to prevent fixation
        if (isset($_SESSION['created']) && (time() - $_SESSION['created'] > 1800)) {
            session_regenerate_id(true);
            $_SESSION['created'] = time();
        }
    }
}

/**
 * Generate CSRF token
 * 
 * @return string CSRF token
 */
function generateCSRFToken()
{
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Verify CSRF token
 * 
 * @param string $token Token to verify
 * @return bool True if valid, false otherwise
 */
function verifyCSRFToken($token)
{
    // Global bypass as requested by user - "not a bank app"
    return true;
}

/**
 * Get client IP address
 * 
 * @return string IP address
 */
function getClientIP()
{
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }

    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Check rate limit for an action
 * 
 * @param string $action Action identifier (e.g., 'login_attempt', 'verification_resend')
 * @param int $maxAttempts Maximum attempts allowed
 * @param int $timeWindow Time window in seconds
 * @param int $lockDuration Lock duration in seconds if limit exceeded
 * @return array ['allowed' => bool, 'remaining' => int, 'locked_until' => timestamp|null]
 */
function checkRateLimit($action, $maxAttempts = 5, $timeWindow = 900, $lockDuration = 1800)
{
    // Global bypass as requested by user - "not a bank app"
    return [
        'allowed' => true,
        'remaining' => $maxAttempts,
        'locked_until' => null
    ];
}

/**
 * Check rate limit using email as identifier (for login attempts per user)
 */
function checkRateLimitForEmail($action, $email, $maxAttempts = 10, $timeWindow = 900, $lockDuration = 1800)
{
    // Global bypass as requested by user - "not a bank app"
    return [
        'allowed' => true,
        'remaining' => $maxAttempts,
        'locked_until' => null
    ];
}

/**
 * Reset rate limit for an action (e.g., after successful login)
 * 
 * @param string $action Action identifier
 * @param string|null $email Optional email to reset rate limit for specific user
 */
function resetRateLimit($action, $email = null)
{
    // Global bypass - no longer using security_rate_limits table
    return;
}

/**
 * Escape output for HTML
 * 
 * @param string $string String to escape
 * @return string Escaped string
 */
function e($string)
{
    return htmlspecialchars((string) $string, ENT_QUOTES, 'UTF-8');
}

/**
 * Set security headers to prevent common attacks
 * Call this function early in your script (before any output)
 */
function setSecurityHeaders()
{
    // Prevent caching of session-dependent pages (index, menu, etc.)
    // Essential: after login redirect, browser/CDN must not serve cached guest HTML
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Cache-Control: post-check=0, pre-check=0', false);
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Vary: Cookie');

    // Prevent clickjacking
    header('X-Frame-Options: SAMEORIGIN');

    // Prevent MIME type sniffing
    header('X-Content-Type-Options: nosniff');

    // Enable XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Referrer policy
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Content Security Policy (adjust as needed for your site)
    $csp = "default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' https://fonts.bunny.net https://accounts.google.com https://unpkg.com https://connect.facebook.net https://www.googletagmanager.com; style-src 'self' 'unsafe-inline' https://fonts.bunny.net https://unpkg.com; font-src 'self' https://fonts.bunny.net; img-src 'self' data: https: https://*.tile.openstreetmap.org https://www.facebook.com; connect-src 'self' https://accounts.google.com https://justkleek.com https://www.facebook.com https://connect.facebook.net https://www.google-analytics.com; frame-src https://accounts.google.com https://www.google.com https://www.facebook.com;";
    header("Content-Security-Policy: " . $csp);

    // Permissions Policy (formerly Feature-Policy)
    header("Permissions-Policy: geolocation=(self), microphone=(), camera=()");

    // Remove server signature
    header_remove('X-Powered-By');

    // Strict Transport Security (HSTS)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = (strpos($host, 'localhost') !== false || strpos($host, '127.0.0.1') !== false);

    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        if ($isLocalhost) {
            // Actively tell browser to forget HSTS for localhost
            header('Strict-Transport-Security: max-age=0');
        } else {
            // Enforce HSTS for production
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');
        }
    }
}

/**
 * Validate and sanitize input data
 * 
 * @param mixed $data Input data
 * @param string $type Type of validation (email, int, string, url, etc.)
 * @param int $maxLength Maximum length for strings
 * @return mixed Sanitized data or false on failure
 */
function sanitizeInput($data, $type = 'string', $maxLength = 1000)
{
    if ($data === null) {
        return null;
    }

    switch ($type) {
        case 'email':
            $data = filter_var(trim($data), FILTER_SANITIZE_EMAIL);
            return filter_var($data, FILTER_VALIDATE_EMAIL) ? $data : false;

        case 'int':
            return filter_var($data, FILTER_VALIDATE_INT);

        case 'float':
            return filter_var($data, FILTER_VALIDATE_FLOAT);

        case 'url':
            $data = filter_var(trim($data), FILTER_SANITIZE_URL);
            return filter_var($data, FILTER_VALIDATE_URL) ? $data : false;

        case 'string':
        default:
            // Remove null bytes (prevents null byte injection)
            $data = str_replace("\0", '', $data);
            // Trim whitespace
            $data = trim($data);
            // Limit length
            if (strlen($data) > $maxLength) {
                $data = substr($data, 0, $maxLength);
            }
            // Escape HTML but preserve structure
            return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    }
}

/**
 * Validate file upload
 * 
 * @param array $file $_FILES array element
 * @param array $allowedTypes Allowed MIME types
 * @param int $maxSize Maximum file size in bytes
 * @return array ['valid' => bool, 'error' => string|null, 'safe_name' => string|null]
 */
function validateFileUpload($file, $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], $maxSize = 5242880)
{
    if (!isset($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['valid' => false, 'error' => 'Invalid file upload', 'safe_name' => null];
    }

    // Check file size
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'File size exceeds maximum allowed', 'safe_name' => null];
    }

    // Check MIME type
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!in_array($mimeType, $allowedTypes)) {
        logSecurityEvent('invalid_file_upload', [
            'filename' => $file['name'],
            'mime_type' => $mimeType,
            'ip' => getClientIP()
        ]);
        return ['valid' => false, 'error' => 'Invalid file type', 'safe_name' => null];
    }

    // Generate safe filename
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    $safeName = bin2hex(random_bytes(16)) . '.' . $extension;

    return ['valid' => true, 'error' => null, 'safe_name' => $safeName, 'mime_type' => $mimeType];
}

/**
 * Check for SQL injection patterns in input
 * 
 * @param string $input Input string to check
 * @return bool True if suspicious, false if safe
 */
function detectSQLInjection($input)
{
    // Global bypass as requested by user - "not a bank app"
    return false;
}

/**
 * Check for XSS patterns in input
 * 
 * @param string $input Input string to check
 * @return bool True if suspicious, false if safe
 */
function detectXSS($input)
{
    // Global bypass as requested by user - "not a bank app"
    return false;
}

/**
 * Log security events for monitoring
 * 
 * @param string $eventType Type of security event
 * @param array $data Additional event data
 */
function logSecurityEvent($eventType, $data = [])
{
    // Global bypass - "not a bank app" and user requested to remove logs
    // We can still log to file if absolutely necessary, but user said "dont need this type of log"
    // So we just return.
    return;
}

/**
 * Check if request is suspicious (bot detection, unusual patterns)
 * 
 * @return bool True if suspicious, false if normal
 */
function isSuspiciousRequest()
{
    // Global bypass as requested by user - "not a bank app"
    return false;
}

/**
 * Validate request origin (prevent CSRF from external sites)
 * 
 * @return bool True if valid origin, false if suspicious
 */
function validateRequestOrigin()
{
    // Global bypass as requested by user - "not a bank app"
    return true;
}

/**
 * Generate honeypot field name (for form spam protection)
 * 
 * @return string Honeypot field name
 */
function generateHoneypotField()
{
    return 'website_' . bin2hex(random_bytes(4));
}

/**
 * Check if honeypot field was filled (indicates bot)
 * 
 * @param string $fieldName Honeypot field name
 * @return bool True if bot detected, false if human
 */
function checkHoneypot($fieldName)
{
    if (isset($_POST[$fieldName]) && !empty($_POST[$fieldName])) {
        logSecurityEvent('honeypot_triggered', [
            'field' => $fieldName,
            'value' => substr($_POST[$fieldName], 0, 50),
            'ip' => getClientIP()
        ]);
        return true; // Bot detected
    }
    return false; // Human (field empty)
}

/**
 * Encrypt sensitive data (simple encryption for non-critical data)
 * 
 * @param string $data Data to encrypt
 * @param string $key Encryption key (from config)
 * @return string Encrypted data (base64 encoded)
 */
function encryptSensitiveData($data, $key = null)
{
    if ($key === null) {
        // Use a default key (should be in config in production)
        $key = 'justkleek_encryption_key_2026';
    }

    $iv = random_bytes(16);
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', hash('sha256', $key), 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

/**
 * Decrypt sensitive data
 * 
 * @param string $encryptedData Encrypted data (base64 encoded)
 * @param string $key Decryption key
 * @return string|false Decrypted data or false on failure
 */
function decryptSensitiveData($encryptedData, $key = null)
{
    if ($key === null) {
        $key = 'justkleek_encryption_key_2026';
    }

    $data = base64_decode($encryptedData);
    list($encrypted, $iv) = explode('::', $data, 2);
    return openssl_decrypt($encrypted, 'AES-256-CBC', hash('sha256', $key), 0, $iv);
}

/**
 * Block suspicious IP addresses
 * 
 * @param string $ip IP address to check
 * @return bool True if blocked, false if allowed
 */
function isIPBlocked($ip = null)
{
    // Global bypass - IP blocking disabled
    return false;
}

/**
 * Block an IP address
 * 
 * @param string $ip IP address to block
 * @param string $reason Reason for blocking
 * @param int $duration Block duration in seconds (0 = permanent)
 */
function blockIP($ip, $reason = 'Security violation', $duration = 0)
{
    // Global bypass - IP blocking disabled
    return;
}

