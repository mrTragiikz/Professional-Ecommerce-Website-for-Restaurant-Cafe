<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_rider_only.php';
require_once __DIR__ . '/../../../config/db.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$shiftStartTime = '10:00:00';
$startTime = $date . ' ' . $shiftStartTime;
$endTime = date('Y-m-d', strtotime($date . ' +1 day')) . ' 03:59:59';

try {
    // Get all completed orders assigned to this rider delivered in this business window
    $stmt = $pdo->prepare("
        SELECT o.id, o.total, o.status, o.delivered_at, o.payment_method, o.payment_status,
               u.name as user_name,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.rider_id = ? AND o.status = 'completed' AND (o.delivered_at >= ? AND o.delivered_at <= ?)
        ORDER BY o.delivered_at DESC
    ");
    $stmt->execute([$riderId, $startTime, $endTime]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    ?>
    <div style="padding: 10px;">
        <?php if (empty($orders)): ?>
            <div style="text-align: center; padding: 40px 20px; color: #64748b;">
                <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"
                    style="margin-bottom: 16px; opacity: 0.5;">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path>
                    <polyline points="22 4 12 14.01 9 11.01"></polyline>
                </svg>
                <div style="font-weight: 700; font-size: 1.1rem; color: #1e293b;">No delivered orders found</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($orders as $order): ?>
                    <div style="background: white; border: 1.5px solid #f1f5f9; border-radius: 20px; padding: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <div style="font-size: 0.7rem; font-weight: 800; color: #10b981; margin-bottom: 4px; letter-spacing: 0.02em;">
                                    #ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?>
                                </div>
                                <h4 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">
                                    <?php echo htmlspecialchars($order['display_name']); ?>
                                </h4>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8;">Rs.</span>
                                <span style="font-size: 1.3rem; font-weight: 900; color: #1e293b;"><?php echo number_format($order['total']); ?></span>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                            <div style="font-size: 0.8rem; font-weight: 600; color: #64748b; line-height: 1.5;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    <?php echo date('h:i A', strtotime($order['delivered_at'])); ?>
                                </div>
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"></path></svg>
                                    <?php echo htmlspecialchars($order['display_phone']); ?>
                                </div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                <span style="background:#f0fdf4; color:#10b981; padding: 4px 10px; border-radius: 8px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; border: 1px solid #10b98120;">
                                    Delivered
                                </span>
                                <button onclick="viewOrderDetails(<?php echo $order['id']; ?>)" style="background: #f8fafc; color: #1e293b; border: 1.5px solid #e2e8f0; padding: 6px 14px; border-radius: 10px; font-size: 0.7rem; font-weight: 800; cursor: pointer;">
                                    View Details
                                </button>
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
