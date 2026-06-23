<?php
/**
 * Google OAuth Start - Redirects to Google
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/google_oauth.php';

// Session: security.php initSecureSession() is the ONLY place that sets params + session_start
initSecureSession();
// Regenerate session ID at OAuth start
session_regenerate_id(true);

// Store next URL if provided
$next = $_GET['next'] ?? '/';
$_SESSION['oauth_next'] = $next;

// Generate state parameter for CSRF protection
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;
$_SESSION['oauth_state_time'] = time();

// Get config to validate and log redirect URI
require_once __DIR__ . '/../config/load_security.php';
$config = getGoogleOAuthConfig();

// Validate redirect_uri is set
if (empty($config['redirect_uri'])) {
    error_log("ERROR: Google OAuth redirect_uri is empty!");
    die("OAuth configuration error. Please contact administrator.");
}

// Build authorization URL with state parameter
$authUrl = buildGoogleAuthUrl($state);

// Log OAuth initiation details for debugging (temporary)
error_log("=== Google OAuth Start ===");
error_log("Session ID: " . session_id());
error_log("State parameter: $state");
error_log("Next URL: $next");
error_log("Redirect URI: " . $config['redirect_uri']);
error_log("Client ID: " . substr($config['client_id'], 0, 20) . "...");
error_log("Auth URL (first 100 chars): " . substr($authUrl, 0, 100) . "...");
error_log("Current Host: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET'));
error_log("Current Protocol: " . (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http'));

// PHP will automatically save session data when script ends, but we force it here to be safe
// before the redirect happens
session_write_close();

// Redirect to Google for authorization
header('Location: ' . $authUrl);
exit;

