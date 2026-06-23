<?php
/**
 * ============================================================================
 * SECURITY INITIALIZATION - Run this early in your application
 * ============================================================================
 * 
 * This file should be included at the very beginning of your PHP files
 * (before any output) to enable security protections.
 * 
 * Usage: require_once __DIR__ . '/app/functions/security_init.php';
 * 
 * ============================================================================
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF']) === basename(__FILE__)) {
    die('Direct access not allowed');
}

// Load security functions and configurations
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/../../config/load_security.php';

// Initialize secure session
initSecureSession();

// --- BRANCH SELECTION LOGIC ---
if (isset($_GET['branch'])) {
    $branchSlug = trim($_GET['branch']);
    // We can't use $pdo here yet if it's not initialized, but we can check later or initialize it now if needed.
    // However, security_init is included everywhere. Let's do a lazy check or check if we can get DB.
    try {
        require_once __DIR__ . '/../../config/db.php';
        global $pdo;
        if (isset($pdo)) {
            $stmt = $pdo->prepare("SELECT id FROM branches WHERE slug = ? AND is_active = 1");
            $stmt->execute([$branchSlug]);
            $branchId = $stmt->fetchColumn();
            if ($branchId) {
                $_SESSION['customer_branch_id'] = $branchId;
                $_SESSION['customer_branch_slug'] = $branchSlug;
            }
        }
    } catch (Exception $e) {
        // Silent fail
    }
}
// -----------------------------

// Set security headers (must be before any output)
setSecurityHeaders();

// Check if IP is blocked (only if database is available)
try {
    if (function_exists('isIPBlocked') && isIPBlocked()) {
        http_response_code(403);
        die('Access denied. Your IP address has been blocked.');
    }
} catch (Exception $e) {
    // If database check fails, continue (don't block legitimate users)
    error_log("IP block check failed: " . $e->getMessage());
}

// Check for suspicious requests (only if database is available)
try {
    if (function_exists('isSuspiciousRequest') && isSuspiciousRequest()) {
        // Log but don't block (to avoid false positives)
        // You can uncomment below to block suspicious requests
        // http_response_code(403);
        // die('Access denied.');
    }
} catch (Exception $e) {
    // If check fails, continue (don't block legitimate users)
    error_log("Suspicious request check failed: " . $e->getMessage());
}

// Validate request origin for POST/PUT/DELETE requests
if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'])) {
    try {
        if (function_exists('validateRequestOrigin') && !validateRequestOrigin()) {
            // Log but don't block (to avoid false positives with localhost)
            // Uncomment below to block invalid origins
            // http_response_code(403);
            if (function_exists('logSecurityEvent')) {
                logSecurityEvent('invalid_request_origin', [
                    'method' => $_SERVER['REQUEST_METHOD'],
                    'uri' => $_SERVER['REQUEST_URI'] ?? '',
                    'ip' => getClientIP()
                ]);
            }
            // die('Invalid request origin.');
        }
    } catch (Exception $e) {
        // If check fails, continue (don't block legitimate users)
        error_log("Request origin validation failed: " . $e->getMessage());
    }
}

// Helper function to check if value is in whitelist (handles URL encoding)
function isWhitelistedValue($value, $whitelist)
{
    if (!is_string($value)) {
        return false;
    }

    $trimmed = trim($value);
    $decoded = urldecode($trimmed);

    // Check exact match
    if (in_array($trimmed, $whitelist, true) || in_array($decoded, $whitelist, true)) {
        return true;
    }

    // Check case-insensitive match
    $trimmedLower = strtolower($trimmed);
    $decodedLower = strtolower($decoded);
    foreach ($whitelist as $safe) {
        if (strtolower($safe) === $trimmedLower || strtolower($safe) === $decodedLower) {
            return true;
        }
    }

    return false;
}

// Whitelist of known safe values that should bypass security checks
$safeWhitelist = [
    'OTHER (Enter manually)',
    'OTHER',
];

// Whitelist of form field names that should bypass strict security validation
// These are user input fields that may contain legitimate special characters
$safeFieldNames = [
    'delivery_suburb',
    'deliverySuburb',
    'location',
    'street_address',
    'streetAddress',
    'address',
    'suburb',
    'customSuburb',
    'custom_suburb',
    'first_name',
    'last_name',
    'name',
    'phone',
    'email',
    'password',
    'password_hash',
    'confirm_password',
    'password_confirm',
    'csrf_token',
    'signup',
    'complete_profile',
    'next',
];

// Whitelist of GET parameter names that should bypass strict security validation
$safeGetParams = [
    'next',
    'error',
    'success',
    'verified',
    'token',
    'id',
];

// Sanitize all GET and POST inputs automatically (only if functions are available)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && function_exists('detectSQLInjection') && function_exists('detectXSS')) {
    foreach ($_GET as $key => $value) {
        if (is_string($value)) {
            // Skip security check for whitelisted GET parameter names
            if (in_array(strtolower($key), array_map('strtolower', $safeGetParams), true)) {
                continue;
            }

            // Skip security check for whitelisted safe values
            if (isWhitelistedValue($value, $safeWhitelist)) {
                continue;
            }

            try {
                // Check for SQL injection
                if (detectSQLInjection($value)) {
                    http_response_code(400);
                    if (function_exists('logSecurityEvent')) {
                        logSecurityEvent('sql_injection_blocked', [
                            'field' => $key,
                            'ip' => getClientIP()
                        ]);
                    }
                    die('Invalid input detected.');
                }

                // Check for XSS
                if (detectXSS($value)) {
                    http_response_code(400);
                    if (function_exists('logSecurityEvent')) {
                        logSecurityEvent('xss_blocked', [
                            'field' => $key,
                            'ip' => getClientIP()
                        ]);
                    }
                    die('Invalid input detected.');
                }
            } catch (Exception $e) {
                // If check fails, continue (don't block legitimate users)
                error_log("Input validation failed for GET: " . $e->getMessage());
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && function_exists('detectSQLInjection') && function_exists('detectXSS')) {
    foreach ($_POST as $key => $value) {
        if (is_string($value)) {
            // Skip security check for whitelisted field names (user input fields)
            if (in_array(strtolower($key), array_map('strtolower', $safeFieldNames), true)) {
                // Only check for obvious SQL injection patterns, not strict validation
                // Allow normal user input with special characters
                if (
                    stripos($value, 'UNION SELECT') !== false ||
                    stripos($value, 'DROP TABLE') !== false ||
                    stripos($value, '<script') !== false ||
                    stripos($value, 'javascript:') !== false
                ) {
                    // Only block obvious malicious patterns
                    http_response_code(400);
                    if (function_exists('logSecurityEvent')) {
                        logSecurityEvent('obvious_attack_blocked', [
                            'field' => $key,
                            'ip' => getClientIP()
                        ]);
                    }
                    die('Invalid input detected.');
                }
                continue; // Skip strict validation for safe fields
            }

            // Skip security check for whitelisted safe values
            if (isWhitelistedValue($value, $safeWhitelist)) {
                continue;
            }

            try {
                // Check for SQL injection
                if (detectSQLInjection($value)) {
                    http_response_code(400);
                    if (function_exists('logSecurityEvent')) {
                        logSecurityEvent('sql_injection_blocked', [
                            'field' => $key,
                            'ip' => getClientIP()
                        ]);
                    }
                    die('Invalid input detected.');
                }

                // Check for XSS
                if (detectXSS($value)) {
                    http_response_code(400);
                    if (function_exists('logSecurityEvent')) {
                        logSecurityEvent('xss_blocked', [
                            'field' => $key,
                            'ip' => getClientIP()
                        ]);
                    }
                    die('Invalid input detected.');
                }
            } catch (Exception $e) {
                // If check fails, continue (don't block legitimate users)
                error_log("Input validation failed for POST: " . $e->getMessage());
            }
        }
    }
}

// Log successful page access (optional - can be disabled for performance)
// logSecurityEvent('page_access', [
//     'page' => $_SERVER['REQUEST_URI'] ?? '',
//     'method' => $_SERVER['REQUEST_METHOD'] ?? '',
//     'ip' => getClientIP()
// ]);

