<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_rider_only.php';
require_once __DIR__ . '/../../../config/db.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$cutoffTime = '04:00:00';
$startTime = $date . ' ' . $cutoffTime;
$endTime = date('Y-m-d', strtotime($date . ' +1 day')) . ' 03:59:59';

try {
    $limit = max(1, min(500, (int)($_GET['limit'] ?? 200)));
    $stmt = $pdo->prepare("
        SELECT o.id, o.total, o.status, o.created_at, o.payment_method, o.payment_status,
               u.name as user_name,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.rider_id = ?
          AND (
                o.grabbed_at BETWEEN ? AND ?
             OR o.delivered_at BETWEEN ? AND ?
          )
        ORDER BY o.created_at DESC
        LIMIT $limit
    ");
    $stmt->execute([$riderId, $startTime, $endTime, $startTime, $endTime]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    ?>
    <div style="padding: 10px;">
        <?php if (empty($orders)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" style="margin-bottom: 16px; opacity: 0.5;">
                    <path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path>
                    <path d="m3.3 7 8.7 5 8.7-5"></path>
                    <path d="M12 22V12"></path>
                </svg>
                <div style="font-weight: 700; font-size: 1.1rem; color: #1e293b;">No picked orders found</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($orders as $order): ?>
                    <div style="background: white; border: 1.5px solid #f1f5f9; border-radius: 20px; padding: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <div style="font-size: 0.7rem; font-weight: 800; color: #6366f1; margin-bottom: 4px;">#ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></div>
                                <h4 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;"><?php echo htmlspecialchars($order['display_name']); ?></h4>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8;">Rs.</span>
                                <span style="font-size: 1.3rem; font-weight: 900; color: #1e293b;"><?php echo number_format($order['total']); ?></span>
                            </div>
                        </div>
                        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                            <div style="font-size: 0.8rem; font-weight: 600; color: #64748b;"><?php echo date('h:i A', strtotime($order['created_at'])); ?> • <?php echo htmlspecialchars($order['display_phone']); ?></div>
                            <div>
                                <?php $s = strtolower($order['status']); $c = ($s==='completed'||$s==='received')?'#10b981':(($s==='delivery')?'#3b82f6':'#64748b'); ?>
                                <span style="background:<?php echo $c; ?>20; color:<?php echo $c; ?>; padding: 4px 10px; border-radius: 8px; font-size: 0.65rem; font-weight: 800;"><?php echo $s; ?></span>
                                <button onclick="viewOrderDetails(<?php echo $order['id']; ?>)" style="background: #f8fafc; color: #1e293b; border: 1.5px solid #e2e8f0; padding: 6px 14px; border-radius: 10px; font-size: 0.7rem; font-weight: 800; cursor: pointer; margin-left:8px;">View Details</button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
