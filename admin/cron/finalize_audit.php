<?php
/**
 * Cron Job [A] - 04:00 AM
 * Finalize & Lock previous business day audits.
 */
// Prevent direct web access
if (php_sapi_name() !== 'cli') {
    die('Access denied');
}

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// Set timezone
date_default_timezone_set('Asia/Kathmandu');

// The business day that just ended at 4 AM is 'Yesterday'
$finishedDate = date('Y-m-d', strtotime('yesterday'));

try {
    $stmt = $pdo->prepare("
        UPDATE rider_daily_closings
        SET 
            is_locked = 1,
            finalized_at = NOW(),
            status = CASE 
                WHEN end_km IS NOT NULL AND end_km > 0 THEN 'COMPLETED'
                ELSE 'MISSING_END_KM'
            END,
            total_km = CASE 
                WHEN end_km IS NOT NULL AND end_km > 0 THEN (end_km - COALESCE(start_km, 0))
                ELSE total_km
            END
        WHERE closing_date = ? AND is_locked = 0
    ");
    $stmt->execute([$finishedDate]);

    $count = $stmt->rowCount();
    echo "[" . date('Y-m-d H:i:s') . "] Finalized $count audits for $finishedDate.\n";

} catch (Exception $e) {
    error_log("Finalization Cron Error: " . $e->getMessage());
    echo "Error: " . $e->getMessage() . "\n";
}
