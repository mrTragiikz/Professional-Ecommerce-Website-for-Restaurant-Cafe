<?php
require_once __DIR__ . '/RiderContext.php';

$ctx = RiderContext::resolve();
$riderId = $ctx['riderId'];
$riderBranchId = $ctx['branchId'];
$contextMode = $ctx['contextMode'];
$resolverError = $ctx['resolverError'];

if (!$riderId) {
    header("Location: login.php");
    exit;
}


require_once __DIR__ . '/../../config/db.php';

// Check if rider is still active
try {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM riders WHERE id = ? AND (status = 'active' OR is_active = 1)");
    $stmt->execute([$riderId]);
    if ($stmt->fetchColumn() == 0) {
        if ($contextMode === 'rider') {
            session_destroy();
            header("Location: login.php?error=account_deactivated");
            exit;
        } else {
            die("Rider account is inactive.");
        }
    }

} catch (Exception $e) {
    // Ignore DB errors in guard to prevent lockout on temporary glitches
}
require_once __DIR__ . '/../includes/rider_cycle_helper.php';

// Auto-Trigger: Finalize 4 AM lock / Open 10 AM shifts
RiderCycleHelper::systemTrigger($pdo);

// Shift Lock Enforcement (04:00 AM - 09:59 AM)
if (RiderCycleHelper::isLocked()) {
    $currentPage = basename($_SERVER['PHP_SELF']);
    if ($currentPage !== 'shift_closed.php' && $currentPage !== 'logout.php') {
        header("Location: shift_closed.php");
        exit;
    }
}