<?php
/**
 * ============================================================================
 * JustKleek — Centralized Session Configuration
 * ============================================================================
 *
 * Defines session constants and initialization helpers for all three
 * login systems: User, Admin, and Rider.
 *
 * Each system has a DIFFERENT session name so that:
 *   - Rider logout cannot affect Admin session
 *   - Admin logout cannot affect User session
 *   - Browser cookie isolation is maintained per role
 *
 * Session lifetime: 7 days (604800 seconds)
 * Users stay logged in until they manually click Logout.
 */

// Prevent direct access
if (basename($_SERVER['PHP_SELF'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Direct access not allowed');
}

// ============================================================================
// CONSTANTS
// ============================================================================

define('JK_SESSION_LIFETIME',  7 * 24 * 60 * 60); // 7 days in seconds
define('JK_SESSION_NAME_USER',  'JK_USER_SESS');
define('JK_SESSION_NAME_ADMIN', 'JK_ADMIN_SESS');
define('JK_SESSION_NAME_RIDER', 'JK_RIDER_SESS');

// ============================================================================
// HELPER: detect HTTPS
// ============================================================================
function jk_is_https(): bool
{
    return (
        (isset($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) === 'on') ||
        (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ||
        (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
    );
}

// ============================================================================
// HELPER: common INI settings (applied before session_start for all types)
// ============================================================================
function jk_apply_session_ini(string $sessionName, int $lifetime = JK_SESSION_LIFETIME): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return; // Already started — nothing we can do
    }

    ini_set('session.gc_maxlifetime',   (string) $lifetime);
    ini_set('session.cookie_lifetime',  (string) $lifetime);
    ini_set('session.cookie_httponly',  '1');
    ini_set('session.cookie_samesite',  'Lax');
    ini_set('session.use_strict_mode',  '1');
    ini_set('session.use_only_cookies', '1');

    // Enable secure cookies only on HTTPS
    if (jk_is_https()) {
        ini_set('session.cookie_secure', '1');
    } else {
        ini_set('session.cookie_secure', '0');
    }

    // Cookie domain on production (not on localhost)
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $isLocalhost = (
        strpos($host, 'localhost') !== false ||
        strpos($host, '127.0.0.1') !== false ||
        strpos($host, '::1') !== false ||
        strpos($host, '192.168.') !== false ||
        strpos($host, '10.') !== false
    );
    if (!$isLocalhost && preg_match('/(^|\.)justkleek\.com$/i', $host)) {
        // Scope to current domain, not shared across subdomains for stricter isolation
        ini_set('session.cookie_domain', '.justkleek.com');
    }

    session_name($sessionName);
}

// ============================================================================
// PUBLIC: Start USER session
// ============================================================================
function jk_start_user_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    jk_apply_session_ini(JK_SESSION_NAME_USER);
    session_start();
}

// ============================================================================
// PUBLIC: Start ADMIN session
// ============================================================================
function jk_start_admin_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    jk_apply_session_ini(JK_SESSION_NAME_ADMIN);
    session_start();
}

// ============================================================================
// PUBLIC: Start RIDER session
// ============================================================================
function jk_start_rider_session(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }
    jk_apply_session_ini(JK_SESSION_NAME_RIDER);
    session_start();
}
