<?php
/**
 * API: Get Orders List for Auto-Refresh
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../includes/auth.php';

if (!isAdminLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

// Get filter parameter
$filter = $_GET['filter'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

require_once __DIR__ . '/../../config/db.php';

try {
    // Build WHERE clause
    $where = [];
    $params = [];

    if ($filter === 'new') {
        $where[] = "o.status = 'pending' AND o.is_read = 0";
    } elseif ($filter === 'pending') {
        $where[] = "o.status = 'pending'";
    } elseif ($filter === 'confirmed' || $filter === 'preparing') {
        $where[] = "(o.status = 'confirmed' OR o.status = 'preparing')";
    } elseif ($filter === 'ready') {
        // JK-AUDIT-FIX: normalized status handling
        $where[] = "o.status IN ('ready', 'received')";
    } elseif ($filter === 'completed') {
        $where[] = "o.status = 'completed'";
    } elseif ($filter === 'cancelled') {
        $where[] = "o.status = 'cancelled'";
    } elseif ($filter !== 'all') {
        $where[] = "o.status = ?";
        $params[] = strtolower($filter);
    } else {
        // JK-AUDIT-FIX: dashboard query optimization
        $where[] = "o.status <> 'completed'";
    }

    // --- BRANCH FILTERING ---
    $selectedBranchId = getAdminBranchId();
    if ($selectedBranchId) {
        $where[] = "o.restaurant_id = ?";
        $params[] = $selectedBranchId;
    }
    // ------------------------

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count
    $countParams = $params;
    $countQuery = "SELECT COUNT(*) as total FROM orders o " . $whereClause;
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalOrders = $countStmt->fetch()['total'];
    $totalPages = ceil($totalOrders / $perPage);

    // Get orders with pagination
    $limitParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
        SELECT o.*, 
               u.name as user_name,
               u.phone as user_phone,
               u.email as user_email,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               u.location_lat,
               u.location_lng,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(o.customer_email, u.email) as display_email,
               COALESCE(NULLIF(CONCAT_WS(', ', NULLIF(u.delivery_location, ''), NULLIF(u.street_location, '')), ''), 'N/A') as display_location,
               (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count,
               (SELECT SUM(line_total) FROM order_items WHERE order_id = o.id) as items_total
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        $whereClause
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($limitParams);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Get statistics
    $statsWhere = "";
    $statsParams = [];
    if ($selectedBranchId) {
        $statsWhere = "WHERE restaurant_id = ?";
        $statsParams[] = $selectedBranchId;
    }

    $statsStmt = $pdo->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'pending' AND is_read = 0 THEN 1 ELSE 0 END) as new_orders,
            SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN status = 'confirmed' OR status = 'preparing' THEN 1 ELSE 0 END) as confirmed_preparing_orders,
            SUM(CASE WHEN LOWER(status) = 'received' THEN 1 ELSE 0 END) as received_orders
        FROM orders
        $statsWhere
    ");
    $statsStmt->execute($statsParams);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    // Get latest order timestamp for comparison
    $latestOrderStmt = $pdo->prepare("SELECT MAX(created_at) as latest_order_time FROM orders $statsWhere");
    $latestOrderStmt->execute($statsParams);
    $latestOrder = $latestOrderStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'orders' => $orders,
        'stats' => $stats,
        'total_orders' => $totalOrders,
        'total_pages' => $totalPages,
        'current_page' => $page,
        'latest_order_time' => $latestOrder['latest_order_time'] ?? null
    ]);

} catch (PDOException $e) {
    error_log("Get orders API error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => 'Database error']);
}


