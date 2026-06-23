<?php
/**
 * API: Get Dashboard Statistics
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

try {
    global $pdo;

    // --- BRANCH FILTERING ---
    $selectedBranchId = getAdminBranchId();
    if (isSuperAdmin() && isset($_GET['branch']) && $_GET['branch'] !== 'all') {
        $branchFromUrl = (int)$_GET['branch'];
        if ($branchFromUrl > 0) {
            $selectedBranchId = $branchFromUrl;
        }
    }

    $branchFilter = "";
    if ($selectedBranchId) {
        $branchFilter = " AND restaurant_id = " . (int)$selectedBranchId;
    }
    // ------------------------

    $stmt = $pdo->query("
        SELECT 
            SUM(CASE WHEN (status = 'pending') AND is_read = 0 THEN 1 ELSE 0 END) as new_orders_count,
            SUM(CASE WHEN (status = 'pending') THEN 1 ELSE 0 END) as pending_orders_count,
            SUM(CASE WHEN (status = 'confirmed' OR status = 'preparing') THEN 1 ELSE 0 END) as confirmed_orders_count,
            SUM(CASE WHEN LOWER(status) IN ('ready', 'delivery', 'received') THEN 1 ELSE 0 END) as ready_orders_count,
            SUM(CASE WHEN status = 'pending' AND payment_status = 'pending' AND payment_method IN ('esewa', 'khalti', 'connectips', 'online', 'online_cod') THEN 1 ELSE 0 END) as online_pending_count,
            MAX(CASE WHEN (status = 'pending') AND is_read = 0 THEN id ELSE 0 END) as latest_id
        FROM orders
        WHERE 1=1 $branchFilter
    ");
    $stats = $stmt->fetch();

    // --- BUSINESS DAY STATS (4:00 AM Cutoff) ---
    $range = getBusinessDayRange();
    $start = $range['start'];
    $end = $range['end'];

    // Additional side queries for aggregates using the business day range
    $q = "
        SELECT 
            (SELECT COUNT(*) FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'cancelled' $branchFilter) as today_orders,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE created_at BETWEEN ? AND ? AND status != 'cancelled' $branchFilter) as today_revenue,
            (SELECT COUNT(*) FROM orders WHERE status != 'cancelled' $branchFilter) as total_orders,
            (SELECT COALESCE(SUM(total), 0) FROM orders WHERE status != 'cancelled' $branchFilter) as total_revenue
    ";
    $stmtAggr = $pdo->prepare($q);
    $stmtAggr->execute([$start, $end, $start, $end]);
    $aggregates = $stmtAggr->fetch();

    echo json_encode([
        'success' => true,
        'new_orders_count' => intval($stats['new_orders_count'] ?? 0),
        'pending_orders_count' => intval($stats['pending_orders_count'] ?? 0),
        'confirmed_orders_count' => intval($stats['confirmed_orders_count'] ?? 0),
        'ready_orders_count' => intval($stats['ready_orders_count'] ?? 0),
        'online_pending_count' => intval($stats['online_pending_count'] ?? 0),
        'latest_id' => intval($stats['latest_id'] ?? 0),
        'today_orders' => intval($aggregates['today_orders'] ?? 0),
        'today_revenue' => floatval($aggregates['today_revenue'] ?? 0),
        'total_orders' => intval($aggregates['total_orders'] ?? 0),
        'total_revenue' => floatval($aggregates['total_revenue'] ?? 0)
    ]);

} catch (PDOException $e) {
    error_log("Get stats error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
