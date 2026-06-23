<?php
/**
 * Google OAuth Callback Handler
 */

// TEMPORARY DEBUG MODE - Disabled in production for user-friendly errors
$isDebugMode = false; // Do not show debug output to users

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', $isDebugMode ? 1 : 0);

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/google_oauth.php';
require_once __DIR__ . '/../app/functions/auth.php';
require_once __DIR__ . '/../app/functions/email.php';

// Session: security.php initSecureSession() is the ONLY place that sets params + session_start
initSecureSession();

$basePath = getBasePath();

$error = '';

// Log the callback for debugging (temporary)
error_log("=== Google OAuth Callback ===");
error_log("Session ID: " . session_id());
error_log("GET params: " . print_r($_GET, true));
error_log("Session state: " . (isset($_SESSION['oauth_state']) ? $_SESSION['oauth_state'] : 'NOT SET'));
error_log("Current Host: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET'));
error_log("Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET'));

// TEMPORARY DEBUG: Show Google error if present
if ($isDebugMode && isset($_GET['error'])) {
    $googleError = $_GET['error'];
    $googleErrorDesc = $_GET['error_description'] ?? 'No description provided';
    die("
    <h2>Google OAuth Error (DEBUG MODE)</h2>
    <p><strong>Error:</strong> " . htmlspecialchars($googleError) . "</p>
    <p><strong>Description:</strong> " . htmlspecialchars($googleErrorDesc) . "</p>
    <p><strong>Full Query String:</strong> " . htmlspecialchars($_SERVER['QUERY_STRING'] ?? 'N/A') . "</p>
    <hr>
    <p><a href='google_start.php'>Try Again</a> | <a href='login.php'>Back to Login</a></p>
    ");
}

// TEMPORARY DEBUG: Show error if code is missing
if ($isDebugMode && !isset($_GET['code'])) {
    die("
    <h2>OAuth Callback Debug (DEBUG MODE)</h2>
    <p><strong>Error:</strong> No authorization code received from Google</p>
    <p><strong>Full Query String:</strong> " . htmlspecialchars($_SERVER['QUERY_STRING'] ?? 'N/A') . "</p>
    <p><strong>GET Parameters:</strong></p>
    <pre>" . htmlspecialchars(print_r($_GET, true)) . "</pre>
    <hr>
    <p><a href='google_start.php'>Try Again</a> | <a href='login.php'>Back to Login</a></p>
    ");
}

// Verify state parameter (CSRF protection)
if (!isset($_GET['state'])) {
    $error = 'Missing OAuth state parameter. Please try again.';
    error_log("Google OAuth Error: Missing state parameter");
    if ($isDebugMode) {
        die("
        <h2>State Parameter Missing (DEBUG MODE)</h2>
        <p><strong>Error:</strong> Missing OAuth state parameter</p>
        <p><strong>GET Parameters:</strong></p>
        <pre>" . htmlspecialchars(print_r($_GET, true)) . "</pre>
        <p><a href='google_start.php'>Try Again</a></p>
        ");
    } else {
        header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
        exit;
    }
}

// Try a safe re-open if session key isn't visible (rare mobile/browser quirk)
if (!isset($_SESSION['oauth_state']) && isset($_GET['state'])) {
    session_write_close();
    initSecureSession();
}

if (!isset($_SESSION['oauth_state'])) {
    $error = 'Session expired. Please try Google sign-in again.';
    error_log("Google OAuth Error: Session state not found");
    error_log("Session ID: " . session_id());
    error_log("Session data: " . print_r($_SESSION, true));
    if ($isDebugMode) {
        // Safe fallback: If user is ALREADY logged in, just redirect them
        if (isset($_SESSION['user_id'])) {
            error_log("Session state missing but user is already logged in. Redirecting to next/home.");
            $next = $_SESSION['oauth_next'] ?? '/';
            header('Location: ' . $basePath . $next);
            exit;
        }

        die("
        <h2>Session State Not Found (DEBUG MODE)</h2>
        <p><strong>Error:</strong> OAuth session expired or not found</p>
        <p><strong>Session ID:</strong> " . session_id() . "</p>
        <p><strong>Received State:</strong> " . htmlspecialchars($_GET['state'] ?? 'NOT SET') . "</p>
        <p><strong>Session State:</strong> " . (isset($_SESSION['oauth_state']) ? htmlspecialchars($_SESSION['oauth_state']) : 'NOT SET') . "</p>
        <p><strong>Session Data:</strong></p>
        <pre>" . htmlspecialchars(print_r($_SESSION, true)) . "</pre>
        <p><a href='google_start.php'>Try Again</a></p>
        ");
    }

    // Safe fallback for production too
    if (isset($_SESSION['user_id'])) {
        $next = $_SESSION['oauth_next'] ?? '/';
        $targetUrl = ($basePath && strpos($next, $basePath) === 0) ? $next : $basePath . $next;
        if ($next === '/' || $next === '' || rtrim($next, '/') === $basePath) {
            $targetUrl = $basePath . '/index.php?fresh=' . time();
        }
        session_write_close();
        header('Location: ' . $targetUrl);
        exit;
    }

    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
    exit;
}

if (!hash_equals((string) $_SESSION['oauth_state'], (string) ($_GET['state'] ?? ''))) {
    $error = 'Invalid OAuth state. Please try again.';
    error_log("Google OAuth Error: State mismatch");
    error_log("  Expected (Session): " . $_SESSION['oauth_state']);
    error_log("  Received (GET): " . $_GET['state']);
    error_log("  Match: " . ($_GET['state'] === $_SESSION['oauth_state'] ? 'YES' : 'NO'));
    if ($isDebugMode) {
        die("
        <h2>State Mismatch (DEBUG MODE)</h2>
        <p><strong>Error:</strong> Invalid OAuth state - CSRF protection failed</p>
        <p><strong>Expected (Session):</strong> " . htmlspecialchars($_SESSION['oauth_state']) . "</p>
        <p><strong>Received (GET):</strong> " . htmlspecialchars($_GET['state']) . "</p>
        <p><strong>Match:</strong> " . ($_GET['state'] === $_SESSION['oauth_state'] ? 'YES ✓' : 'NO ✗') . "</p>
        <p><strong>Session ID:</strong> " . session_id() . "</p>
        <p><a href='google_start.php'>Try Again</a></p>
        ");
    } else {
        unset($_SESSION['oauth_state']);
        header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
        exit;
    }
}

unset($_SESSION['oauth_state']);

// Check for error from Google
if (isset($_GET['error'])) {
    // Handle "access_denied" specifically (User clicked Cancel)
    if ($_GET['error'] === 'access_denied') {
        $error = 'Google sign-in cancelled. You can try again.';
        header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
        exit;
    }

    // Handle other errors
    if (!$isDebugMode) {
        $error = 'Google authentication was cancelled or failed.';
        error_log("Google OAuth Error from Google: " . ($_GET['error'] ?? 'unknown'));
        error_log("Error description: " . ($_GET['error_description'] ?? 'none'));
        header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
        exit;
    }
}

$code = $_GET['code'];

// Exchange code for token
error_log("Exchanging authorization code for access token...");
error_log("Authorization code received: " . substr($code, 0, 20) . "...");

// Get config to log redirect URI used in token exchange
require_once __DIR__ . '/../config/load_security.php';
$config = getGoogleOAuthConfig();
error_log("Token exchange - Redirect URI: " . $config['redirect_uri']);

$tokenData = exchangeCodeForToken($code);

if (!$tokenData || !isset($tokenData['access_token'])) {
    $error = 'Failed to authenticate with Google. Please try again.';
    error_log("ERROR: Google OAuth token exchange failed");
    error_log("Token response: " . print_r($tokenData, true));
    error_log("This often indicates a redirect_uri mismatch. Verify redirect_uri in Google Cloud Console matches: " . $config['redirect_uri']);

    // TEMPORARY DEBUG: Show detailed error
    if ($isDebugMode) {
        $errorDetails = "Token exchange failed. ";
        if (is_array($tokenData) && isset($tokenData['error'])) {
            $errorDetails .= "Error: " . htmlspecialchars($tokenData['error']);
            if (isset($tokenData['error_description'])) {
                $errorDetails .= " - " . htmlspecialchars($tokenData['error_description']);
            }
        } else {
            $errorDetails .= "No response or invalid response from Google.";
        }

        die("
        <h2>Token Exchange Failed (DEBUG MODE)</h2>
        <p><strong>Error:</strong> " . $errorDetails . "</p>
        <p><strong>Configured Redirect URI:</strong> " . htmlspecialchars($config['redirect_uri']) . "</p>
        <p><strong>Token Response:</strong></p>
        <pre>" . htmlspecialchars(print_r($tokenData, true)) . "</pre>
        <hr>
        <p><strong>ACTION REQUIRED:</strong> Verify the redirect_uri above EXACTLY matches what's registered in Google Cloud Console.</p>
        <p><a href='google_start.php'>Try Again</a> | <a href='login.php'>Back to Login</a></p>
        ");
    } else {
        header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
        exit;
    }
}

error_log("Token exchange successful - Access token received");

error_log("Token exchange successful. Fetching user profile...");

// Fetch user profile
$googleUser = fetchGoogleUserProfile($tokenData['access_token']);

if (!$googleUser) {
    $error = 'Failed to fetch user information from Google.';
    error_log("Google OAuth Error: Failed to fetch user profile");
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
    exit;
}

if (!isset($googleUser['id']) && !isset($googleUser['sub'])) {
    $error = 'Invalid user data from Google (missing ID).';
    error_log("Google OAuth Error: Missing user ID - " . print_r($googleUser, true));
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
    exit;
}

if (!isset($googleUser['email'])) {
    $error = 'Invalid user data from Google (missing email).';
    error_log("Google OAuth Error: Missing email - " . print_r($googleUser, true));
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
    exit;
}

error_log("User profile fetched successfully: " . print_r($googleUser, true));

$googleId = $googleUser['id'] ?? $googleUser['sub'] ?? null;
$email = $googleUser['email'] ?? '';
$name = $googleUser['name'] ?? '';
$emailVerified = isset($googleUser['verified_email']) && $googleUser['verified_email'] === true;

// Check for existing user by Google ID
$user = findUserByGoogleId($googleId);

if ($user) {
    // Case A1: User exists with matching google_id
    // Check if profile is completed
    if ($user['profile_completed'] == 1) {
        // Profile completed - log them in
        loginUser($user['id'], $user['email']);
        $next = $_SESSION['oauth_next'] ?? '/';
        unset($_SESSION['oauth_next']);

        // Normalize next to index.php for home to ensure fresh load (no cache)
        $targetUrl = ($basePath && strpos($next, $basePath) === 0) ? $next : $basePath . $next;
        if ($next === '/' || $next === '' || rtrim($next, '/') === $basePath) {
            $targetUrl = $basePath . '/index.php?fresh=' . time();
        }

        session_write_close();
        header('Location: ' . $targetUrl);
        exit;
    } else {
        // Profile not completed - redirect to onboarding
        $_SESSION['google_oauth_data'] = [
            'google_id' => $googleId,
            'email' => $email,
            'name' => $name,
            'email_verified' => $emailVerified
        ];
        $next = $_SESSION['oauth_next'] ?? '/';
        $redirectUrl = $basePath . '/auth/google_onboarding.php?next=' . urlencode($next);
        session_write_close();
        header('Location: ' . $redirectUrl);
        exit;
    }
}

// Check for existing user by email
$user = findUserByEmail($email);

if ($user) {
    // Case A2: User exists with matching email but google_id is NULL
    // DISABLED: Do not link automatically. Force them to use their original sign-up method.
    $error = 'This email is already registered via Sign Up. Please log in using your password.';
    header('Location: ' . $basePath . '/auth/login.php?error=' . urlencode($error));
    exit;
}

// Case B: New account - redirect to onboarding
// Store Google OAuth data in session for onboarding form
$_SESSION['google_oauth_data'] = [
    'google_id' => $googleId,
    'email' => $email,
    'name' => $name,
    'email_verified' => $emailVerified
];

error_log("New Google user detected. Storing OAuth data in session. Google ID: $googleId, Email: $email");
error_log("Session google_oauth_data stored: " . print_r($_SESSION['google_oauth_data'], true));
error_log("Session ID: " . session_id());
error_log("Session save path: " . session_save_path());

// Redirect to onboarding page
$next = $_SESSION['oauth_next'] ?? '/';
$redirectUrl = $basePath . '/auth/google_onboarding.php?next=' . urlencode($next);
error_log("Redirecting to onboarding: $redirectUrl");
error_log("Base path: $basePath");

session_write_close();
header('Location: ' . $redirectUrl);
exit;

