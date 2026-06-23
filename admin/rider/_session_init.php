<?php
/**
 * Rider Session Bootstrapper
 * Include this at the VERY TOP of every rider PHP file, before any other require.
 * Replaces all bare session_start() calls in the rider area.
 */
if (!defined('JK_SESSION_NAME_RIDER')) {
    require_once __DIR__ . '/../../config/session_config.php';
}
jk_start_rider_session();
