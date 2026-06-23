<?php
/**
 * Admin Session Bootstrapper
 * Include this at the VERY TOP of every admin PHP file, before any other require.
 * Replaces all bare session_start() calls in the admin area.
 */
if (!defined('JK_SESSION_NAME_ADMIN')) {
    require_once __DIR__ . '/../../config/session_config.php';
}
jk_start_admin_session();
