<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require __DIR__ . '/_guard.php';
require_once __DIR__ . '/../../config/db.php';


if (empty($_POST['csrf_token']) || empty($_SESSION['rider_csrf']) || $_POST['csrf_token'] !== $_SESSION['rider_csrf']) {
    header("Location: daily_closing.php?error=" . urlencode("Invalid session token."));
    exit;
}

$action = trim($_POST['action'] ?? '');
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// Shift Lock Enforcement (04:00 AM - 09:59 AM)
if (RiderCycleHelper::isLocked()) {
    header("Location: daily_closing.php?error=" . urlencode(RiderCycleHelper::getStatusMessage()));
    exit;
}

$workingDate = $_POST['closing_date'] ?? RiderCycleHelper::getBusinessDate();
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $workingDate)) {
    $workingDate = RiderCycleHelper::getBusinessDate();
}

if ($action === 'start_duty') {
    $startKm = isset($_POST['start_km']) ? (float) $_POST['start_km'] : null;
    if ($startKm === null || $startKm < 0) {
        header("Location: daily_closing.php?error=" . urlencode("Please enter a valid Start KM."));
        exit;
    }
    try {
        // Find existing record for THIS business day
        $row = RiderCycleHelper::ensureAuditRow($pdo, $riderId, $workingDate);

        if ($row && (int) $row['is_locked'] === 1) {
            header("Location: daily_closing.php?error=" . urlencode("Duty for $workingDate is already finalized and locked."));
            exit;
        }

        // Update Start KM and Duty Start Time
        $pdo->prepare("UPDATE rider_daily_closings SET start_km = ?, duty_start_time = NOW(), end_km = NULL, duty_end_time = NULL, total_km = NULL, is_locked = 0, status = 'OPEN' WHERE id = ?")
            ->execute([$startKm, $row['id']]);

        // Sync to riders table
        $pdo->prepare("UPDATE riders SET closing_start_km = ?, duty_start_time = NOW(), closing_end_km = NULL, duty_end_time = NULL, closing_total_km = NULL, closing_audit_date = ? WHERE id = ?")
            ->execute([$startKm, $workingDate, $riderId]);

        header("Location: daily_closing.php?msg=start_saved&date=" . $workingDate);
        exit;
    } catch (Exception $e) {
        error_log('Start duty error: ' . $e->getMessage());
        header("Location: daily_closing.php?error=" . urlencode("Unable to save. Please try again."));
        exit;
    }
}

if ($action === 'end_km') {
    $endKm = isset($_POST['end_km']) ? (float) $_POST['end_km'] : null;
    if ($endKm === null || $endKm < 0) {
        header("Location: daily_closing.php?error=" . urlencode("Please enter a valid End KM."));
        exit;
    }
    try {
        // Look for the ACTIVE duty record for CURRENT business cycle
        $stmt = $pdo->prepare("SELECT id, start_km FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ? AND (is_locked = 0 OR is_locked IS NULL)");
        $stmt->execute([$riderId, $workingDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            header("Location: daily_closing.php?error=" . urlencode("Start duty first for $workingDate, then enter End KM."));
            exit;
        }

        $startKm = (float) $row['start_km'];
        if ($endKm < $startKm) {
            header("Location: daily_closing.php?error=" . urlencode("End KM must be greater than or equal to Start KM."));
            exit;
        }
        $totalKmVal = $endKm - $startKm;
        $pdo->prepare("UPDATE rider_daily_closings SET end_km = ?, duty_end_time = NOW(), total_km = ? WHERE id = ?")
            ->execute([$endKm, $totalKmVal, $row['id']]);
        // Sync to riders table
        $pdo->prepare("UPDATE riders SET closing_end_km = ?, duty_end_time = NOW(), closing_total_km = ? WHERE id = ?")
            ->execute([$endKm, $totalKmVal, $riderId]);

        header("Location: daily_closing.php?msg=end_saved&date=" . $workingDate);
        exit;
    } catch (Exception $e) {
        header("Location: daily_closing.php?error=" . urlencode("Unable to save End KM."));
        exit;
    }
}

if ($action === 'stop_duty') {
    try {
        // Look for the ACTIVE duty record for specifically this date
        $stmt = $pdo->prepare("SELECT * FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ? AND (is_locked = 0 OR is_locked IS NULL)");
        $stmt->execute([$riderId, $workingDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['end_km'] === null) {
            header("Location: daily_closing.php?error=" . urlencode("Enter and confirm End KM before stopping duty."));
            exit;
        }
        $dutyDate = $row['closing_date'];
        $startKm = (float) $row['start_km'];
        $endKm = (float) $row['end_km'];
        $totalKm = $endKm - $startKm;

        $statsStmt = $pdo->prepare("
            SELECT
                COUNT(*) AS orders_count,
                SUM(COALESCE(delivery_distance_km, 0)) AS hired_km,
                SUM(COALESCE(delivery_fee, 0)) AS delivery_revenue,
                SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_cash, 0) ELSE 0 END) AS cash_collect,
                SUM(CASE WHEN LOWER(payment_status) = 'paid' THEN COALESCE(paid_amount_online, 0) ELSE 0 END) AS online_collect
            FROM (
                SELECT delivery_distance_km, delivery_fee, payment_status, paid_amount_cash, paid_amount_online
                FROM orders
                WHERE rider_id = ? AND status = 'completed' AND DATE(delivered_at) = ?
                LIMIT 500
            ) o
        ");
        $statsStmt->execute([$riderId, $dutyDate]);
        $stats = $statsStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $hiredKm = (float) ($stats['hired_km'] ?? 0);
        $vacantKm = max($totalKm - $hiredKm, 0);
        $ordersCount = (int) ($stats['orders_count'] ?? 0);
        $deliveryRevenue = (float) ($stats['delivery_revenue'] ?? 0);
        $cashCollect = (float) ($stats['cash_collect'] ?? 0);
        $onlineCollect = (float) ($stats['online_collect'] ?? 0);

        $pdo->prepare("
            UPDATE rider_daily_closings SET
                total_km = ?, hired_km = ?, vacant_km = ?, orders_count = ?, delivery_revenue = ?, cash_collect = ?, online_collect = ?,
                is_locked = 1, locked_at = NOW()
            WHERE id = ?
        ")->execute([$totalKm, $hiredKm, $vacantKm, $ordersCount, $deliveryRevenue, $cashCollect, $onlineCollect, $row['id']]);

        // Sync to riders table (per-rider: start km, end km, total bike km, date audit)
        $pdo->prepare("UPDATE riders SET closing_start_km = ?, duty_start_time = ?, closing_end_km = ?, duty_end_time = ?, closing_total_km = ?, closing_audit_date = ? WHERE id = ?")
            ->execute([$startKm, $row['duty_start_time'], $endKm, $row['duty_end_time'], $totalKm, $dutyDate, $riderId]);

        $auditStmt = $pdo->prepare("INSERT INTO rider_audit_logs (rider_id, action, description) VALUES (?, 'closing_created', ?)");
        $auditDesc = sprintf(
            "Daily closing %s: start=%.2f (%s), end=%.2f (%s), total=%.2f km (closing_id=%d)",
            $dutyDate,
            $startKm,
            $row['duty_start_time'] ? date('h:i A', strtotime($row['duty_start_time'])) : 'N/A',
            $endKm,
            $row['duty_end_time'] ? date('h:i A', strtotime($row['duty_end_time'])) : 'N/A',
            $totalKm,
            $row['id']
        );
        $auditStmt->execute([$riderId, $auditDesc]);

        header("Location: daily_closing.php?msg=submitted&date=" . $workingDate);
        exit;
    } catch (Exception $e) {
        error_log('Stop duty error: ' . $e->getMessage());
        header("Location: daily_closing.php?error=" . urlencode("Unable to finalize. Try again.") . "&date=" . $workingDate);
        exit;
    }
}

header("Location: daily_closing.php?error=" . urlencode("Invalid action."));
exit;
