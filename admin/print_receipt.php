<?php
/**
 * Placeholder for future receipt implementation.
 * Buttons in admin_dashboard.php still link here, but
 * the actual thermal receipt layout will be added later.
 */
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

$orderId = intval($_GET['order_id'] ?? 0);
$type = $_GET['type'] ?? 'customer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Receipt Coming Soon</title>
</head>
<body style="font-family: Arial, sans-serif; padding: 20px;">
    <h2>Receipt layout not ready yet</h2>
    <p>Order ID: <strong><?php echo htmlspecialchars($orderId); ?></strong></p>
    <p>Mode: <strong><?php echo htmlspecialchars($type); ?></strong> (customer / kitchen)</p>
    <p>We will add the new professional thermal receipt design here later.</p>
</body>
</html>
