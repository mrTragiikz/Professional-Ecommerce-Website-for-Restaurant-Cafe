<?php
/**
 * Rider Cycle Helper
 * Handles the 10:00 AM to 04:00 AM business cycle logic.
 */

class RiderCycleHelper
{
    const SHIFT_START_HOUR = 10; // 10:00 AM
    const SHIFT_END_HOUR = 4;    // 04:00 AM (Next Day)

    /**
     * Get the current Business Date.
     * If before 4 AM, it's the previous calendar day.
     * If after 10 AM, it's the current calendar day.
     */
    public static function getBusinessDate()
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
        $hour = (int) $now->format('H');

        // If between midnight and 4 AM, the business date is yesterday
        if ($hour < self::SHIFT_END_HOUR) {
            return $now->modify('-1 day')->format('Y-m-d');
        }

        // Between 4 AM and 10 AM, we are technically in the "dead zone" 
        // but typically we'd be looking at the business date that just finished.
        if ($hour >= self::SHIFT_END_HOUR && $hour < self::SHIFT_START_HOUR) {
            return $now->modify('-1 day')->format('Y-m-d');
        }

        return $now->format('Y-m-d');
    }

    /**
     * Check if the system is currently in the LOCKED window (04:00 AM - 09:59 AM)
     */
    public static function isLocked()
    {
        $now = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
        $hour = (int) $now->format('H');

        return ($hour >= self::SHIFT_END_HOUR && $hour < self::SHIFT_START_HOUR);
    }

    /**
     * Get a user-friendly status message
     */
    public static function getStatusMessage()
    {
        if (self::isLocked()) {
            return "Shift closed. Next shift opens at 10:00 AM.";
        }
        return "Shift active.";
    }

    /**
     * System-Wide Trigger: Automatically Finalizes and Unlocks shifts
     * based on the 10 AM / 4 AM cycle. Runs on every page load.
     */
    public static function systemTrigger($pdo)
    {
        if (!$pdo)
            return;

        try {
            date_default_timezone_set('Asia/Kathmandu');
            $now = new DateTime('now', new DateTimeZone('Asia/Kathmandu'));
            $hour = (int) $now->format('H');
            $today = $now->format('Y-m-d');
            $yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

            // 1. FINALIZATION TRIGGER (After 4 AM)
            if ($hour >= self::SHIFT_END_HOUR) {
                $stmt = $pdo->prepare("
                    UPDATE rider_daily_closings
                    SET is_locked = 1, 
                        status = CASE WHEN end_km > 0 THEN 'COMPLETED' ELSE 'MISSING_END_KM' END, 
                        finalized_at = NOW()
                    WHERE closing_date = ? AND is_locked = 0
                ");
                $stmt->execute([$yesterday]);
            }

            // 2. NEW DAY TRIGGER (After 10 AM)
            if ($hour >= self::SHIFT_START_HOUR) {
                $riderStmt = $pdo->query("SELECT id FROM riders WHERE is_active = 1");
                $riderIds = $riderStmt->fetchAll(PDO::FETCH_COLUMN);
                foreach ($riderIds as $rid) {
                    self::ensureAuditRow($pdo, $rid, $today);
                }
            }
        } catch (Exception $e) {
            // Silently log error to prevent 500 crash
            error_log("RiderCycle System Trigger Error: " . $e->getMessage());
        }
    }

    /**
     * Ensure we have a database record for today's audit.
     */
    public static function ensureAuditRow($pdo, $riderId, $busDate)
    {
        $stmt = $pdo->prepare("SELECT id, is_locked FROM rider_daily_closings WHERE rider_id = ? AND closing_date = ?");
        $stmt->execute([$riderId, $busDate]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            try {
                $ins = $pdo->prepare("INSERT INTO rider_daily_closings (rider_id, closing_date, start_km, is_locked, status, created_by_rider_id) VALUES (?, ?, NULL, 0, 'OPEN', ?)");
                $ins->execute([$riderId, $busDate, $riderId]);
                return ['id' => $pdo->lastInsertId(), 'is_locked' => 0];
            } catch (Exception $e) {
                return null;
            }
        }
        return $row;
    }
}
