<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require '_guard.php';
require_once __DIR__ . '/../../config/db.php';


// CSRF validation
if (empty($_POST['csrf_token']) || empty($_SESSION['rider_csrf']) || $_POST['csrf_token'] !== $_SESSION['rider_csrf']) {
    header("Location: daily_closing.php?error=" . urlencode("Invalid session token. Please try again."));
    exit;
}

$closingDate = trim($_POST['closing_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $closingDate)) {
    $closingDate = date('Y-m-d');
}

$startKm = isset($_POST['start_km']) ? (float) $_POST['start_km'] : null;
$endKm = isset($_POST['end_km']) ? (float) $_POST['end_km'] : null;

if ($startKm === null || $endKm === null || $startKm < 0 || $endKm < 0) {
    header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&error=" . urlencode("Please provide valid odometer readings."));
    exit;
}

if ($endKm < $startKm) {
    header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&error=" . urlencode("End KM must be greater than or equal to Start KM."));
    exit;
}

// Optional sanity check (e.g. max 500 km per day)
$totalKm = $endKm - $startKm;
if ($totalKm > 1000) {
    header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&error=" . urlencode("Total KM looks too high. Please double check and try again."));
    exit;
}

try {
    // Check existing closing row
    $checkStmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
    $checkStmt->execute([$riderId, $closingDate]);
    $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);

    if ($existing && (int) $existing['is_locked'] === 1) {
        header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&error=" . urlencode("Closing already submitted and locked. Please contact admin for changes."));
        exit;
    }

    // Compute stats from orders for snapshot
    $statsSql = "
        SELECT
            COUNT(*) AS orders_count,
            SUM(COALESCE(delivery_distance_km, 0)) AS hired_km,
            SUM(COALESCE(delivery_fee, 0)) AS delivery_revenue,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_cash, 0) ELSE 0 END) AS cash_collect,
            SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_online, 0) ELSE 0 END) AS online_collect
        FROM orders
        WHERE rider_id = :rider_id
          AND status IN ('completed', 'received')
          AND DATE(delivered_at) = :closing_date
    ";
    $statsStmt = $pdo->prepare($statsSql);
    $statsStmt->execute([
        ':rider_id' => $riderId,
        ':closing_date' => $closingDate,
    ]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $ordersCount = (int) ($stats['orders_count'] ?? 0);
    $hiredKm = (float) ($stats['hired_km'] ?? 0);
    $deliveryRevenue = (float) ($stats['delivery_revenue'] ?? 0);
    $cashCollect = (float) ($stats['cash_collect'] ?? 0);
    $onlineCollect = (float) ($stats['online_collect'] ?? 0);

    $vacantKm = max($totalKm - $hiredKm, 0);

    if ($existing) {
        // Update unlocked record
        $updateSql = "
            UPDATE rider_daily_closings
            SET
                start_km = :start_km,
                end_km = :end_km,
                total_km = :total_km,
                hired_km = :hired_km,
                vacant_km = :vacant_km,
                orders_count = :orders_count,
                delivery_revenue = :delivery_revenue,
                cash_collect = :cash_collect,
                online_collect = :online_collect,
                created_by_rider_id = :created_by_rider_id,
                duty_start_time = COALESCE(duty_start_time, NOW()),
                duty_end_time = NOW(),
                is_locked = 1,
                locked_at = NOW()
            WHERE id = :id
        ";
        $stmt = $pdo->prepare($updateSql);
        $stmt->execute([
            ':start_km' => $startKm,
            ':end_km' => $endKm,
            ':total_km' => $totalKm,
            ':hired_km' => $hiredKm,
            ':vacant_km' => $vacantKm,
            ':orders_count' => $ordersCount,
            ':delivery_revenue' => $deliveryRevenue,
            ':cash_collect' => $cashCollect,
            ':online_collect' => $onlineCollect,
            ':created_by_rider_id' => $riderId,
            ':id' => $existing['id'],
        ]);
        $closingId = (int) $existing['id'];
    } else {
        // Insert new record
        $insertSql = "
            INSERT INTO rider_daily_closings
            (rider_id, closing_date, start_km, end_km, total_km, hired_km, vacant_km, orders_count, delivery_revenue, cash_collect, online_collect, created_at, created_by_rider_id, duty_start_time, duty_end_time, is_locked, locked_at)
            VALUES
            (:rider_id, :closing_date, :start_km, :end_km, :total_km, :hired_km, :vacant_km, :orders_count, :delivery_revenue, :cash_collect, :online_collect, NOW(), :created_by_rider_id, NOW(), NOW(), 1, NOW())
        ";
        $stmt = $pdo->prepare($insertSql);
        $stmt->execute([
            ':rider_id' => $riderId,
            ':closing_date' => $closingDate,
            ':start_km' => $startKm,
            ':end_km' => $endKm,
            ':total_km' => $totalKm,
            ':hired_km' => $hiredKm,
            ':vacant_km' => $vacantKm,
            ':orders_count' => $ordersCount,
            ':delivery_revenue' => $deliveryRevenue,
            ':cash_collect' => $cashCollect,
            ':online_collect' => $onlineCollect,
            ':created_by_rider_id' => $riderId,
        ]);
        $closingId = (int) $pdo->lastInsertId();
    }

    // Audit log
    try {
        $auditStmt = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'closing_created', ?)");
        $desc = sprintf(
            "Daily closing for %s: start_km=%.2f, end_km=%.2f, total_km=%.2f, hired_km=%.3f, vacant_km=%.3f, orders=%d, cash=%.2f, online=%.2f (closing_id=%d)",
            $closingDate,
            $startKm,
            $endKm,
            $totalKm,
            $hiredKm,
            $vacantKm,
            $ordersCount,
            $cashCollect,
            $onlineCollect,
            $closingId
        );
        $auditStmt->execute([$riderId, $desc]);
    } catch (Exception $e) {
        // Non-fatal
    }

    header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&msg=submitted");
    exit;
} catch (Exception $e) {
    error_log('Rider daily closing error: ' . $e->getMessage());
    header("Location: daily_closing.php?date=" . urlencode($closingDate) . "&error=" . urlencode("Unable to save daily closing. Please try again."));
    exit;
}

