<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_admin_guard.php';
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
        LIMIT 200
    ");
    $stmt->execute([$riderId, $startTime, $endTime, $startTime, $endTime]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    foreach ($orders as $order) {
        $sid = strtolower($order['status']);
        $sc = ($sid==='completed'||$sid==='received')?'#10b981':(($sid==='delivery')?'#3b82f6':'#64748b');
        echo '<div style="padding:10px;"><div style="background:white;border:1.5px solid #f1f5f9;border-radius:20px;padding:16px;">';
        echo '<div style="display:flex;justify-content:space-between;"><div><div style="font-size:0.7rem;font-weight:800;color:#6366f1;">#ORD-'.str_pad($order['id'],5,'0',STR_PAD_LEFT).'</div><h4 style="margin:0;">'.htmlspecialchars($order['display_name']).'</h4></div>';
        echo '<div>Rs. '.number_format($order['total']).'</div></div>';
        echo '<div style="display:flex;justify-content:space-between;margin-top:12px;"><div style="font-size:0.8rem;color:#64748b;">'.date('h:i A',strtotime($order['created_at'])).' • '.htmlspecialchars($order['display_phone']).'</div>';
        echo '<span style="background:'.$sc.'20;color:'.$sc.';padding:4px 10px;border-radius:8px;font-size:0.65rem;">'.$order['status'].'</span> ';
        echo '<button onclick="viewOrderDetails('.$order['id'].')" style="background:#f8fafc;border:1.5px solid #e2e8f0;padding:6px 14px;border-radius:10px;font-size:0.7rem;cursor:pointer;">View Details</button></div></div></div>';
    }
    $body = ob_get_clean();
    $html = empty($orders) ? '<div style="padding:40px;text-align:center;color:#64748b;">No picked orders found</div>' : '<div style="padding:10px;">'.$body.'</div>';
    echo json_encode(['success' => true, 'html' => $html]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
