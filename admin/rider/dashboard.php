<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require '_guard.php';

// Streamlined: Skip the welcome dashboard and go straight to deliveries
header("Location: orders.php");
exit;