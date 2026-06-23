<?php
/**
 * eSewa pay.php - OUTDATED
 * This file is kept for backward compatibility and redirects to the newer esewa_payment_start.php.
 */
require_once __DIR__ . '/../app/functions/security_init.php';
$basePath = getBasePath();
$order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;

header("Location: {$basePath}/esewa/esewa_payment_start.php?order_id={$order_id}");
exit;
