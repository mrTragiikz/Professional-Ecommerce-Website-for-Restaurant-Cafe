<?php
/**
 * Admin Logout
 */

if (!defined('JK_SESSION_NAME_ADMIN')) {
    require_once __DIR__ . '/../config/session_config.php';
}
jk_start_admin_session();

// Clear all admin session variables
$_SESSION = [];

// Delete the session cookie explicitly
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

session_destroy();
header('Location: admin.php?logout=1');
exit;
