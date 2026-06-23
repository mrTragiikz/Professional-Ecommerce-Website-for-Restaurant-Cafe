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
    // Get all completed orders assigned to this rider in this business window where cash was collected
    $stmt = $pdo->prepare("
        SELECT o.id, o.status, o.delivered_at, o.paid_amount_cash, o.paid_amount_online,
               u.name as user_name,
               COALESCE(o.customer_name, u.name) as display_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE o.rider_id = ? 
          AND o.status = 'completed' 
          AND LOWER(o.payment_status) = 'paid'
          AND o.paid_amount_cash > 0 
          AND (o.delivered_at >= ? AND o.delivered_at <= ?)
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
                    <rect x="2" y="6" width="20" height="12" rx="2"></rect>
                    <circle cx="12" cy="12" r="2"></circle>
                    <path d="M6 12h.01M18 12h.01"></path>
                </svg>
                <div style="font-weight: 700; font-size: 1.1rem; color: #1e293b;">No cash collections found</div>
            </div>
        <?php else: ?>
            <div style="display: flex; flex-direction: column; gap: 12px;">
                <?php foreach ($orders as $order): ?>
                    <div style="background: white; border: 1.5px solid #f1f5f9; border-radius: 20px; padding: 16px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.03);">
                        <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                            <div>
                                <div style="font-size: 0.7rem; font-weight: 800; color: #ef4444; margin-bottom: 4px; letter-spacing: 0.02em;">
                                    #ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?>
                                    <?php if ($order['paid_amount_online'] > 0): ?>
                                        <span style="margin-left:8px; color:#f59e0b; background:#fffbeb; padding:2px 8px; border-radius:6px; font-size:0.6rem; letter-spacing:0;">SPLIT</span>
                                    <?php endif; ?>
                                </div>
                                <h4 style="margin:0; font-size:1.1rem; font-weight:800; color:#1e293b;">
                                    <?php echo htmlspecialchars($order['display_name']); ?>
                                </h4>
                            </div>
                            <div style="text-align: right;">
                                <span style="font-size: 0.75rem; font-weight: 700; color: #94a3b8;">Rs.</span>
                                <span style="font-size: 1.3rem; font-weight: 900; color: #ef4444;"><?php echo number_format($order['paid_amount_cash']); ?></span>
                            </div>
                        </div>

                        <div style="display: flex; justify-content: space-between; align-items: flex-end;">
                            <div style="font-size: 0.8rem; font-weight: 600; color: #64748b;">
                                <div style="display:flex; align-items:center; gap:6px;">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><polyline points="12 6 12 12 16 14"></polyline></svg>
                                    <?php echo date('h:i A', strtotime($order['delivered_at'])); ?>
                                </div>
                                <div style="font-size:0.65rem; color:#94a3b8; margin-top:4px; font-weight: 700;">Collected in Physical Cash</div>
                            </div>
                            <div style="display: flex; flex-direction: column; gap: 8px; align-items: flex-end;">
                                <span style="background:#fef2f2; color:#ef4444; padding: 4px 10px; border-radius: 8px; font-size: 0.65rem; font-weight: 800; text-transform: uppercase; border: 1px solid #ef444420;">
                                    CASH COLLECTED
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
