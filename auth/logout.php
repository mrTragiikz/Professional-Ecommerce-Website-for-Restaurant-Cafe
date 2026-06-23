<?php
/**
 * User Logout
 */

require_once __DIR__ . '/../app/functions/security.php';
require_once __DIR__ . '/../app/functions/auth.php';

initSecureSession();
logoutUser();

$basePath = getBasePath();
header('Location: ' . $basePath . '/auth/login.php?logout=1');
exit;

