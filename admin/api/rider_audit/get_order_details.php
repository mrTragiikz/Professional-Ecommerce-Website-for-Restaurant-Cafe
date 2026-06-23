<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_admin_guard.php';
require_once __DIR__ . '/../../../config/db.php';

$orderId = intval($_GET['order_id'] ?? 0);
$riderId = 0; // Admin view - hide rider action buttons
$canPay = 0;

if (!$orderId) {
    echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
    exit;
}

try {
    $stmt = $pdo->prepare("
        SELECT o.*, 
               u.name as user_name,
               u.phone as user_phone,
               u.email as user_email,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               COALESCE(o.location_lat, u.location_lat) as location_lat,
               COALESCE(o.location_lng, u.location_lng) as location_lng,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(o.customer_email, u.email) as display_email
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.id = ?
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch();

    if (!$order) {
        echo json_encode(['success' => false, 'error' => 'Order not found']);
        exit;
    }

    $riderName = '';
    if (!empty($order['rider_id'])) {
        try {
            $riderStmt = $pdo->prepare("SELECT * FROM riders WHERE id = ?");
            $riderStmt->execute([$order['rider_id']]);
            $riderRow = $riderStmt->fetch(PDO::FETCH_ASSOC);
            if ($riderRow) {
                $riderName = $riderRow['full_name'] ?? ($riderRow['name'] ?? ($riderRow['username'] ?? ''));
            }
        } catch (Exception $re) {}
    }

    $stmt = $pdo->prepare("SELECT * FROM order_items WHERE order_id = ? ORDER BY id");
    $stmt->execute([$orderId]);
    $items = $stmt->fetchAll();

    $stmt = $pdo->prepare("SELECT * FROM payment_transactions WHERE order_id = ? AND status = 'COMPLETE' ORDER BY id DESC LIMIT 1");
    $stmt->execute([$orderId]);
    $txn = $stmt->fetch();

    ob_start();
    $status = strtolower(trim($order['status']));
    $statusClass = ($status === 'confirmed' || $status === 'preparing') ? 'confirmed' : $status;
    $payStatus = strtolower($order['payment_status'] ?? 'unpaid');
    $payClass = ($payStatus === 'paid') ? 'paid' : 'unpaid';
    ?>
    <style>.order-detail-premium{font-family:system-ui;color:#1e293b;}.od-header{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:24px;}.od-id{font-size:1.25rem;font-weight:800;}.od-meta{font-size:0.8rem;color:#64748b;}.od-status{padding:6px 14px;border-radius:50px;font-size:0.75rem;font-weight:800;background:#f1f5f9;color:#475569;}.od-section{background:white;border-radius:16px;border:1px solid #f1f5f9;padding:16px;margin-bottom:20px;}.od-item-row{display:flex;justify-content:space-between;padding:12px 0;border-bottom:1px solid #f8fafc;}.od-totals-section{background:#f8fafc;border-radius:16px;padding:20px;}.od-card{background:white;border-radius:16px;border:1px solid #f1f5f9;padding:16px;margin-bottom:16px;}.od-label{font-size:0.75rem;font-weight:800;color:#64748b;}.od-value{font-size:0.95rem;font-weight:700;}</style>
    <div class="order-detail-premium">
        <div class="od-header">
            <div><h1 class="od-id">#ORD-<?php echo str_pad($order['id'],5,'0',STR_PAD_LEFT); ?></h1><div class="od-meta"><?php echo date('M d, Y h:i A',strtotime($order['created_at'])); ?></div></div>
            <div class="od-status"><?php echo ucfirst($status); ?></div>
        </div>
        <div class="od-section"><span class="od-label">Items</span>
            <?php foreach ($items as $item): ?>
            <div class="od-item-row"><div><?php echo htmlspecialchars($item['item_name']); ?> x<?php echo $item['quantity']; ?></div><div>Rs. <?php echo number_format($item['line_total']); ?></div></div>
            <?php endforeach; ?>
        </div>
        <div class="od-totals-section">
            <div class="od-item-row"><span>Subtotal</span><span>Rs. <?php echo number_format($order['total'] - ($order['delivery_fee']??0)); ?></span></div>
            <div class="od-item-row"><span>Delivery</span><span>Rs. <?php echo number_format($order['delivery_fee']??0); ?></span></div>
            <div class="od-item-row" style="font-weight:800;"><span>Total</span><span>Rs. <?php echo number_format($order['total']); ?></span></div>
            <?php if (($order['rider_tip'] ?? 0) > 0): ?>
                <div class="od-item-row" style="color:#7c3aed; font-weight:700; border-top:1px dashed #e2e8f0; margin-top:8px; padding-top:12px;">
                    <span>Tips</span><span>Rs. <?php echo number_format($order['rider_tip']); ?></span>
                </div>
            <?php endif; ?>
        </div>
        <div class="od-card"><span class="od-label">Customer</span><div class="od-value"><?php echo htmlspecialchars($order['display_name']); ?> · <?php echo htmlspecialchars($order['display_phone']); ?></div></div>
        <div class="od-card"><span class="od-label">Payment</span><span style="padding:4px 12px;border-radius:50px;font-size:0.7rem;font-weight:800;background:<?php echo $payClass==='paid'?'#dcfce7':'#fee2e2';?>;color:<?php echo $payClass==='paid'?'#166534':'#991b1b';?>;"><?php echo strtoupper($payStatus); ?></span></div>
        <div class="od-card"><span class="od-label">Address</span><div class="od-value"><?php echo htmlspecialchars($order['delivery_address'] ?: ($order['user_delivery_location'] ?: 'N/A')); ?></div></div>
        <?php if ($riderName): ?><div class="od-card"><span class="od-label">Rider</span><div class="od-value"><?php echo htmlspecialchars($riderName); ?></div></div><?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
