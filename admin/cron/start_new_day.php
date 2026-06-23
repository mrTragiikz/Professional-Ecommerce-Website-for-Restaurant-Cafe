<?php
/**
 * Cron Job [B] - 10:00 AM
 * Start New Business Day for all active riders.
 */
// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    die('Access denied');
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// Set timezone
date_default_timezone_set('Asia/Kathmandu');

$busDate = date('Y-m-d'); // 10 AM today starts today's business date

try {
    // 1. Get all active riders
    $riders = $pdo->query("SELECT id FROM riders WHERE status = 'active'")->fetchAll(PDO::FETCH_COLUMN);

    $countInserted = 0;
    foreach ($riders as $riderId) {
        $stmt = $pdo->prepare("SELECT id FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
        $stmt->execute([$riderId, $busDate]);
        if (!$stmt->fetch()) {
            $ins = $pdo->prepare("INSERT INTO rider_daily_closings (rider_id, closing_date, start_km, end_km, total_km, is_locked, status) 
                                 VALUES (?, ?, NULL, NULL, 0, 0, 'OPEN')");
            $ins->execute([$riderId, $busDate]);
            $countInserted++;
        } else {
            $reset = $pdo->prepare("UPDATE rider_daily_closings SET is_locked = 0, status = 'OPEN' WHERE rider_id = ? AND closing_date = ? AND is_locked = 1");
            $reset->execute([$riderId, $busDate]);
        }
    }

    echo "[" . date('Y-m-d H:i:s') . "] Unlocked/Started New Day ($busDate). New rows: $countInserted.\n";

} catch (Exception $e) {
    error_log("Start Day Cron Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
