<?php
/**
 * Auto-Audit Engine: Finalizes the most recent business day into the database.
 * This runs automatically to ensure no record is missed.
 */

function runAutoAudit($pdo)
{
    $cutoff = '04:00:00';
    $now = new DateTime();
    $currentTime = $now->format('H:i:s');

    // The "Recently Finished" business day is always 'Yesterday' if we are past 4 AM today.
    // If we are BEFORE 4 AM today, the recently finished one was 'Day before yesterday'.
    if ($currentTime >= $cutoff) {
        $finishedDate = date('Y-m-d', strtotime('yesterday'));
    } else {
        $finishedDate = date('Y-m-d', strtotime('-2 days'));
    }

    try {
        // 1. Get all riders
        $riders = $pdo->query("SELECT id FROM riders")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($riders as $riderId) {
            // Check if record already exists for this date
            $check = $pdo->prepare("SELECT id FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
            $check->execute([$riderId, $finishedDate]);
            if ($check->fetch())
                continue; // Already exists, don't overwrite auto-save

            // Calculate Performance for that specific business day
            $startTime = $finishedDate . ' ' . $cutoff;
            $endTime = date('Y-m-d', strtotime($finishedDate . ' +1 day')) . ' 03:59:59';

            $perfStmt = $pdo->prepare("
                SELECT
                    SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s1 AND o.delivered_at <= :e1 THEN COALESCE(o.delivery_distance_km, 0) ELSE 0 END) AS km,
                    SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s2 AND o.delivered_at <= :e2 AND LOWER(o.payment_status) = 'paid' THEN COALESCE(o.paid_amount_cash, 0) ELSE 0 END) AS cash,
                    SUM(CASE WHEN o.status IN ('completed', 'received') AND o.delivered_at >= :s3 AND o.delivered_at <= :e3 AND LOWER(o.payment_status) = 'paid' THEN COALESCE(o.paid_amount_online, 0) ELSE 0 END) AS online
                FROM orders o
                WHERE o.rider_id = :rider_id
            ");
            $perfStmt->execute([
                ':s1' => $startTime,
                ':e1' => $endTime,
                ':s2' => $startTime,
                ':e2' => $endTime,
                ':s3' => $startTime,
                ':e3' => $endTime,
                ':rider_id' => $riderId
            ]);
            $stats = $perfStmt->fetch(PDO::FETCH_ASSOC);

            // Auto-Insert the Snapshot
            $ins = $pdo->prepare("
                INSERT INTO rider_daily_closings (rider_id, closing_date, start_km, end_km, total_km, cash_collect, online_collect, is_locked)
                VALUES (?, ?, 0, ?, ?, ?, ?, 0)
            ");
            $ins->execute([
                $riderId,
                $finishedDate,
                $stats['km'],
                $stats['km'],
                $stats['cash'],
                $stats['online']
            ]);
        }
    } catch (Exception $e) {
        error_log("Auto Audit Error: " . $e->getMessage());
    }
}
