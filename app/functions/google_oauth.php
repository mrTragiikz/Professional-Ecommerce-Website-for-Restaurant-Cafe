<?php
/**
 * ============================================================================
 * GOOGLE OAUTH HELPER FUNCTIONS
 * ============================================================================
 * 
 * Functions for handling Google OAuth 2.0 authentication flow
 * 
 * ============================================================================
 */

// Load all security configs
require_once __DIR__ . '/../../config/load_security.php';

/**
 * Build Google OAuth authorization URL
 * 
 * @param string $state Optional state parameter for CSRF protection
 * @return string Authorization URL
 */
function buildGoogleAuthUrl($state = null)
{
    $config = getGoogleOAuthConfig();

    if ($state === null) {
        $state = bin2hex(random_bytes(16));
        $_SESSION['oauth_state'] = $state;
    }

    $params = [
        'client_id' => $config['client_id'],
        'redirect_uri' => $config['redirect_uri'],
        'response_type' => 'code',
        'scope' => implode(' ', $config['scopes']),
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state
    ];

    return $config['auth_url'] . '?' . http_build_query($params);
}

/**
 * Exchange authorization code for access token
 * 
 * @param string $code Authorization code from Google
 * @return array|false Token data or false on failure
 */
function exchangeCodeForToken($code)
{
    $config = getGoogleOAuthConfig();

    $data = [
        'code' => $code,
        'client_id' => $config['client_id'],
        'client_secret' => $config['client_secret'],
        'redirect_uri' => $config['redirect_uri'],
        'grant_type' => 'authorization_code'
    ];

    error_log("Token exchange request - Redirect URI: " . $config['redirect_uri']);
    error_log("Token exchange request - Client ID: " . $config['client_id']);

    // Detect if we're on localhost (for SSL verification)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocalhost = (
        stripos($host, 'localhost') !== false ||
        stripos($host, '127.0.0.1') !== false ||
        stripos($host, '::1') !== false ||
        $host === 'localhost' ||
        $host === '127.0.0.1'
    );

    $ch = curl_init($config['token_url']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);

    // SSL Verification: Disable on localhost (development), enable on production
    if ($isLocalhost) {
        // Localhost: Disable SSL verification (common for WAMP/XAMPP development)
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        error_log("Token exchange: SSL verification disabled (localhost development)");
    } else {
        // Production: Enable SSL verification for security
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        error_log("Token exchange: SSL verification enabled (production)");
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    // Log token exchange request details
    error_log("Token exchange request details:");
    error_log("  URL: " . $config['token_url']);
    error_log("  HTTP Method: POST");
    error_log("  HTTP Status: " . $httpCode);
    error_log("  Redirect URI: " . $config['redirect_uri']);
    error_log("  Client ID: " . substr($config['client_id'], 0, 30) . "...");

    if ($curlError) {
        error_log("ERROR: Google token exchange curl error: " . $curlError);
        return ['error' => 'curl_error', 'error_description' => $curlError, 'http_code' => 0];
    }

    if ($httpCode !== 200) {
        $errorResponse = json_decode($response, true);
        error_log("ERROR: Google token exchange failed - HTTP $httpCode");
        error_log("Full response: " . $response);

        if ($errorResponse && isset($errorResponse['error'])) {
            error_log("Error code: " . $errorResponse['error']);
            if (isset($errorResponse['error_description'])) {
                error_log("Error description: " . $errorResponse['error_description']);

                // Check for redirect_uri mismatch (most common OAuth error)
                if (
                    stripos($errorResponse['error_description'], 'redirect_uri') !== false ||
                    $errorResponse['error'] === 'redirect_uri_mismatch'
                ) {
                    error_log("=== REDIRECT_URI MISMATCH DETECTED ===");
                    error_log("The redirect_uri used in this request: " . $config['redirect_uri']);
                    error_log("ACTION REQUIRED: Verify this redirect_uri is EXACTLY registered in Google Cloud Console");
                    error_log("Common issues:");
                    error_log("  - Trailing slash mismatch");
                    error_log("  - http vs https mismatch");
                    error_log("  - localhost vs 127.0.0.1 mismatch");
                    error_log("  - Missing or extra path segments");
                }
            }
        }

        error_log("Request redirect_uri: " . $config['redirect_uri']);
        error_log("Request data (without secret): " . print_r(array_merge($data, ['client_secret' => 'HIDDEN']), true));

        // Return error details for debugging
        return $errorResponse ?: ['error' => 'http_error', 'http_code' => $httpCode, 'response' => $response];
    }

    $tokenData = json_decode($response, true);

    if (isset($tokenData['error'])) {
        error_log("Google token exchange error: " . $tokenData['error']);
        if (isset($tokenData['error_description'])) {
            error_log("Error description: " . $tokenData['error_description']);
        }
        error_log("Redirect URI used: " . $config['redirect_uri']);
        return $tokenData; // Return error details
    }

    return $tokenData;
}

/**
 * Fetch user profile from Google using access token
 * 
 * @param string $accessToken Access token
 * @return array|false User profile data or false on failure
 */
function fetchGoogleUserProfile($accessToken)
{
    $config = getGoogleOAuthConfig();

    // Use the new OpenID Connect userinfo endpoint
    $userinfoUrl = 'https://openidconnect.' . 'googleapis' . '.com/v1/userinfo';

    // Detect if we're on localhost (for SSL verification)
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $isLocalhost = (
        stripos($host, 'localhost') !== false ||
        stripos($host, '127.0.0.1') !== false ||
        stripos($host, '::1') !== false ||
        $host === 'localhost' ||
        $host === '127.0.0.1'
    );

    $ch = curl_init($userinfoUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $accessToken]);

    // SSL Verification: Disable on localhost (development), enable on production
    if ($isLocalhost) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        error_log("Google userinfo fetch curl error: " . $curlError);
        return false;
    }

    if ($httpCode !== 200) {
        error_log("Google userinfo fetch failed: HTTP $httpCode - $response");
        return false;
    }

    $userData = json_decode($response, true);

    if (isset($userData['error'])) {
        error_log("Google userinfo error: " . $userData['error']);
        if (isset($userData['error_description'])) {
            error_log("Error description: " . $userData['error_description']);
        }
        return false;
    }

    return $userData;
}

