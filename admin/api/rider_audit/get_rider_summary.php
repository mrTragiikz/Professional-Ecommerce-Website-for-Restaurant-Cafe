<?php
header('Content-Type: application/json');
require_once __DIR__ . '/_admin_guard.php';
require_once __DIR__ . '/../../../config/db.php';

$tz = 'Asia/Kathmandu';
date_default_timezone_set($tz);

$viewDate = $_GET['date'] ?? date('Y-m-d');
if ($viewDate === 'today' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $viewDate)) {
    $nowTime = date('H:i:s');
    $cutoffTime = '04:00:00';
    $viewDate = ($nowTime < $cutoffTime) ? date('Y-m-d', strtotime('yesterday')) : date('Y-m-d');
}

$shiftStartTime = '10:00:00';
$startTime = $viewDate . ' ' . $shiftStartTime;
$endTime = date('Y-m-d', strtotime($viewDate . ' +1 day')) . ' 03:59:59';
$currentMonth = date('Y-m', strtotime($viewDate));
$monthName = date('F Y', strtotime($viewDate));
$monthFirstDay = $currentMonth . '-01';
$monthLastDay = date('Y-m-t', strtotime($viewDate));
$monthStartTime = $monthFirstDay . ' 10:00:00';
$monthEndTime = date('Y-m-d', strtotime($monthLastDay . ' +1 day')) . ' 03:59:59';

try {
    $stmt = $pdo->prepare("
        SELECT
            COUNT(id) AS total_trips,
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s1 AND delivered_at <= :e1 THEN 1 ELSE 0 END) AS total_delivered,
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s2 AND delivered_at <= :e2 THEN COALESCE(delivery_distance_km, 0) ELSE 0 END) AS total_km,
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s3 AND delivered_at <= :e3 THEN COALESCE(delivery_fee, 0) ELSE 0 END) AS total_delivery_fees,
            SUM(CASE WHEN status = 'completed' AND delivered_at >= :s4 AND delivered_at <= :e4 AND LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_cash, 0) ELSE 0 END) AS total_cash,
            SUM(CASE WHEN status = 'completed' AND delivered_at >= :s5 AND delivered_at <= :e5 AND LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_online, 0) ELSE 0 END) AS total_online,
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s6 AND delivered_at <= :e6 THEN COALESCE(rider_tip, 0) ELSE 0 END) AS total_tips
        FROM orders
        WHERE rider_id = :rider_id 
        AND (
            (grabbed_at >= :gs AND grabbed_at <= :ge) OR 
            (delivered_at >= :ds AND delivered_at <= :de)
        )
    ");
    $stmt->execute([
        ':s1'=>$startTime,':e1'=>$endTime,':s2'=>$startTime,':e2'=>$endTime,':s3'=>$startTime,':e3'=>$endTime,
        ':s4'=>$startTime,':e4'=>$endTime,':s5'=>$startTime,':e5'=>$endTime,':s6'=>$startTime,':e6'=>$endTime,
        ':gs'=>$startTime,':ge'=>$endTime,':ds'=>$startTime,':de'=>$endTime,':rider_id'=>$riderId
    ]);
    $daily = $stmt->fetch(PDO::FETCH_ASSOC);

    $cStmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
    $cStmt->execute([$riderId, $viewDate]);
    $closing = $cStmt->fetch(PDO::FETCH_ASSOC) ?: ['start_km'=>0,'end_km'=>0,'duty_start_time'=>null,'duty_end_time'=>null];

    $mStmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s1 AND delivered_at <= :e1 THEN 1 ELSE 0 END) AS m_delivered,
            SUM(CASE WHEN status IN ('completed', 'received') AND delivered_at >= :s2 AND delivered_at <= :e2 THEN COALESCE(delivery_distance_km, 0) ELSE 0 END) AS m_hired
        FROM orders 
        WHERE rider_id = :rider_id 
        AND (
            (grabbed_at >= :gs AND grabbed_at <= :ge) OR 
            (delivered_at >= :ds AND delivered_at <= :de)
        )
    ");
    $mStmt->execute([':s1'=>$monthStartTime,':e1'=>$monthEndTime,':s2'=>$monthStartTime,':e2'=>$monthEndTime,':gs'=>$monthStartTime,':ge'=>$monthEndTime,':ds'=>$monthStartTime,':de'=>$monthEndTime,':rider_id'=>$riderId]);
    $mRow = $mStmt->fetch(PDO::FETCH_ASSOC);

    $cStmt = $pdo->prepare("SELECT SUM(COALESCE(total_km, end_km - start_km, 0)) as m_bike_km FROM rider_daily_closings WHERE rider_id = ? AND closing_date >= ? AND closing_date <= ?");
    $cStmt->execute([$riderId, $monthFirstDay, $monthLastDay]);
    $cMonthly = $cStmt->fetch(PDO::FETCH_ASSOC);

    $tipsStmt = $pdo->prepare("SELECT SUM(COALESCE(rider_tip, 0)) FROM orders WHERE rider_id = ? AND delivered_at >= ? AND delivered_at <= ? AND status IN ('completed', 'received')");
    $tipsStmt->execute([$riderId, $monthStartTime, $monthEndTime]);
    $monthlyTips = (float) $tipsStmt->fetchColumn();

    $data = [
        'monthly' => [
            'name' => $monthName,
            'bike_km' => (float)($cMonthly['m_bike_km'] ?? 0),
            'hired_km' => (float)($mRow['m_delivered'] ? $mRow['m_hired'] : 0),
            'delivered' => (int)($mRow['m_delivered'] ?? 0),
            'tips' => $monthlyTips
        ],
        'daily' => [
            'date_formatted' => date('M j, Y', strtotime($viewDate)),
            'picked' => (int)($daily['total_trips'] ?? 0),
            'delivered' => (int)($daily['total_delivered'] ?? 0),
            'km' => (float)($daily['total_km'] ?? 0),
            'cash' => (float)($daily['total_cash'] ?? 0),
            'online' => (float)($daily['total_online'] ?? 0),
            'tips' => (float)($daily['total_tips'] ?? 0),
            'start_km' => (float)($closing['start_km'] ?? 0),
            'end_km' => (float)($closing['end_km'] ?? 0),
            'duty_start_time' => $closing['duty_start_time'] ? date('d M, h:i A', strtotime($closing['duty_start_time'])) : null,
            'duty_end_time' => $closing['duty_end_time'] ? date('d M, h:i A', strtotime($closing['duty_end_time'])) : null
        ]
    ];

    echo json_encode(['success' => true, 'data' => $data]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
