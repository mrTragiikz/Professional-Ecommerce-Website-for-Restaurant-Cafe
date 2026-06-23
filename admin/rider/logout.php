<?php
/**
 * Rider Logout Handler
 *
 * Since admin and rider now use DIFFERENT session names (JK_ADMIN_SESS vs JK_RIDER_SESS),
 * it is now 100% safe to destroy the rider session completely without affecting the admin.
 * The old "unset only" approach was only needed when they shared the same PHPSESSID.
 */

// Initialize the correct rider session by name first
require_once __DIR__ . '/_session_init.php';

// Fully clear all session data for this rider
$_SESSION = [];

// Delete the session cookie explicitly (removes the JK_RIDER_SESS cookie from browser)
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

// Destroy the rider session entirely
session_destroy();

header("Location: login.php");
exit;
