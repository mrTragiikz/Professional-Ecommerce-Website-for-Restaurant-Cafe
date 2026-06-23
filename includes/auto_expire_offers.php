<?php
/**
 * Auto-expire offers based on time.
 *
 * IMPORTANT:
 * - This file is included from `config/db.php`, so it runs on many requests.
 * - Keep it fast: no schema checks, no ALTERs, and throttle execution.
 *
 * Logic:
 * - expire rows where offer_end_time < now AND old_price is set
 * Action:
 * - restore price = old_price; clear old_price + offer_end_time
 */

if (!isset($pdo) || !$pdo) {
    return;
}

// Throttle: run at most once per 5 minutes (per server) to avoid slowing page loads.
try {
    $cooldownSeconds = 300;
    $lockFile = rtrim(sys_get_temp_dir(), "\\/") . DIRECTORY_SEPARATOR . 'justkleek_offer_expire_last_run.txt';
    $now = time();

    if (is_file($lockFile)) {
        $last = (int) @file_get_contents($lockFile);
        if ($last > 0 && ($now - $last) < $cooldownSeconds) {
            return;
        }
    }

    // Best-effort write; ignore failures (shared hosting / permissions)
    @file_put_contents($lockFile, (string) $now, LOCK_EX);
} catch (Throwable $e) {
    // If throttling fails, still attempt the update (but do not throw)
}

try {
    $currentTime = date('Y-m-d H:i:s');

    $sql = "UPDATE menu_items
            SET price = old_price,
                old_price = NULL,
                offer_end_time = NULL
            WHERE offer_end_time IS NOT NULL
              AND offer_end_time < ?
              AND old_price IS NOT NULL
              AND old_price > 0";

    $stmt = $pdo->prepare($sql);
    $stmt->execute([$currentTime]);
} catch (PDOException $e) {
    error_log("Auto-Expire Offers Error: " . $e->getMessage());
}
