<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_admin_guard.php';
require_once __DIR__ . '/../../../config/db.php';

$date = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    echo json_encode(['success' => false, 'error' => 'Invalid date format']);
    exit;
}

$startTime = $date . ' 10:00:00';
$endTime = date('Y-m-d', strtotime($date . ' +1 day')) . ' 03:59:59';

try {
    $stmt = $pdo->prepare("
        SELECT o.id, o.status, o.delivered_at, o.delivery_distance_km,
               COALESCE(o.customer_name, u.name) as display_name
        FROM (
            SELECT id, status, delivered_at, delivery_distance_km, customer_name, user_id
            FROM orders
            WHERE rider_id = ? AND status IN ('completed', 'received') AND delivery_distance_km > 0 AND (delivered_at >= ? AND delivered_at <= ?)
            ORDER BY delivered_at DESC
            LIMIT 200
        ) o
        LEFT JOIN users u ON o.user_id = u.id
    ");
    $stmt->execute([$riderId, $startTime, $endTime]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    ob_start();
    if (empty($orders)) {
        echo '<div style="padding:40px;text-align:center;color:#64748b;">No hired KM found</div>';
    } else {
        echo '<div style="padding:10px;">';
        foreach ($orders as $order) {
            echo '<div style="background:white;border:1.5px solid #f1f5f9;border-radius:20px;padding:16px;margin-bottom:12px;">';
            echo '<div style="display:flex;justify-content:space-between;"><div><div style="font-size:0.7rem;font-weight:800;color:#f59e0b;">#ORD-'.str_pad($order['id'],5,'0',STR_PAD_LEFT).'</div><h4 style="margin:0;">'.htmlspecialchars($order['display_name']).'</h4></div><div>'.number_format($order['delivery_distance_km'],2).' km</div></div>';
            echo '<div style="display:flex;justify-content:space-between;margin-top:12px;"><div style="font-size:0.8rem;color:#64748b;">'.date('h:i A',strtotime($order['delivered_at'])).'</div>';
            echo '<button onclick="viewOrderDetails('.$order['id'].')" style="background:#f8fafc;border:1.5px solid #e2e8f0;padding:6px 14px;border-radius:10px;font-size:0.7rem;cursor:pointer;">View Details</button></div></div>';
        }
        echo '</div>';
    }
    $html = ob_get_clean();
    echo json_encode(['success' => true, 'html' => $html]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
