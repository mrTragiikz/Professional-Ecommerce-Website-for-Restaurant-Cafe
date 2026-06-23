<?php
/**
 * Admin Dashboard - Order Management
 */

// Check auth first (before any output) - auth.php already starts session
require_once __DIR__ . '/includes/auth.php';
requireAdminLogin();

// Get filter parameter
$filter = $_GET['filter'] ?? 'new';

// Get search parameter
$search = trim($_GET['search'] ?? '');

// Quick redirect check BEFORE any output
if (!empty($search)) {
    require_once __DIR__ . '/../config/db.php';

    // Build search query to find orders
    $cleanSearch = trim(str_replace('#', '', $search));
    $searchTerm = '%' . $cleanSearch . '%';
    $phoneSearch = preg_replace('/[^0-9]/', '', $cleanSearch);
    $phoneSearchTerm = $phoneSearch ? '%' . $phoneSearch . '%' : '';

    $searchConditions = [
        "o.order_id LIKE ?",
        "COALESCE(o.customer_name, u.name) LIKE ?"
    ];
    $searchParams = [$searchTerm, $searchTerm];

    if ($phoneSearchTerm) {
        $searchConditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(o.customer_phone, u.phone), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?";
        $searchParams[] = $phoneSearchTerm;
    } else {
        $searchConditions[] = "COALESCE(o.customer_phone, u.phone) LIKE ?";
        $searchParams[] = $searchTerm;
    }

    $searchConditions[] = "COALESCE(o.order_code, '') LIKE ?";
    $searchParams[] = $searchTerm;

    $searchWhere = "(" . implode(" OR ", $searchConditions) . ")";

    // Quick query to find matching orders
    $quickStmt = $pdo->prepare("
        SELECT o.status, o.is_read
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        WHERE $searchWhere
        LIMIT 2
    ");
    $quickStmt->execute($searchParams);
    $quickResults = $quickStmt->fetchAll();

    // If exactly one order found, redirect to appropriate filter
    if (count($quickResults) === 1) {
        $foundOrder = $quickResults[0];
        // normalize status handling
        $orderStatus = strtolower(trim($foundOrder['status'] ?? ''));

        $targetFilter = 'all';
        if ($orderStatus === 'pending' && ($foundOrder['is_read'] ?? 0) == 0) {
            $targetFilter = 'new';
        } elseif ($orderStatus === 'pending') {
            $targetFilter = 'pending';
        } elseif ($orderStatus === 'confirmed' || $orderStatus === 'preparing') {
            $targetFilter = 'confirmed';
        } elseif ($orderStatus === 'ready' || $orderStatus === 'delivery' || $orderStatus === 'received') {
            $targetFilter = 'ready';
        } elseif ($orderStatus === 'completed') {
            // Forward immediately to All Orders history for completed items
            header('Location: allorder.php?search=' . urlencode($search));
            exit;
        } elseif ($orderStatus === 'cancelled') {
            $targetFilter = 'cancelled';
        }

        // Redirect before any output
        if ($targetFilter !== $filter) {
            header('Location: ?filter=' . $targetFilter . '&search=' . urlencode($search));
            exit;
        }
    }
}

// Now include header after redirect check
if (!isset($_GET['ajax'])) {
    require_once __DIR__ . '/includes/header.php';
    // Expose branch info to JS for manual order modal
    $branchIdForJs = getAdminBranchId();
    $dSettings = getDeliverySettings($branchIdForJs);
    $maxDist = floatval($dSettings['max_distance'] ?? 0);
    $baseFee = floatval($dSettings['base_fee'] ?? 0);
    $rateKm  = floatval($dSettings['rate_per_km'] ?? 0);

    echo "<script>const CURRENT_BRANCH_ID = " . ($branchIdForJs ?: 'null') . "; const DELIVERY_MAX_DISTANCE = $maxDist; const DELIVERY_BASE_FEE = $baseFee; const DELIVERY_RATE_PER_KM = $rateKm;</script>";
}

$pageTitle = 'Order Management';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

// Build query
global $pdo;

try {
    // Build WHERE clause
    $where = [];
    $params = [];

    // --- BRANCH FILTERING ---
    $selectedBranchId = getAdminBranchId();
    if (isSuperAdmin() && isset($_GET['branch']) && $_GET['branch'] !== 'all') {
        $branchFromUrl = (int)$_GET['branch'];
        if ($branchFromUrl > 0) {
            $selectedBranchId = $branchFromUrl;
        }
    }

    if ($selectedBranchId) {
        $where[] = "o.restaurant_id = ?";
        $params[] = (int)$selectedBranchId;
    }
    // ------------------------

    // If searching, ignore filter restrictions and search across all orders
    // Then we'll auto-redirect to the correct filter based on found order status
    $isSearching = !empty($search);

    if (!$isSearching) {
        // Only apply filter restrictions when not searching
        if ($filter === 'new') {
            // Show unread pending orders (new orders that haven't been marked as read)
            $where[] = "o.status = 'pending' AND o.is_read = 0";
        } elseif ($filter === 'pending') {
            // Show all pending orders (both read and unread)
            $where[] = "o.status = 'pending'";
        } elseif ($filter === 'confirmed' || $filter === 'preparing') {
            // Show both 'confirmed' and 'preparing' statuses under "Confirmed and Preparing"
            // Handle both 'confirmed' and 'preparing' filter values for backward compatibility
            $where[] = "(o.status = 'confirmed' OR o.status = 'preparing')";
        } elseif ($filter === 'ready') {
            // handle status filtering for ready/delivery/received orders
            // Show 'ready' (published for riders), 'delivery' (grabbed/in transit), 'received' (picked up, payment pending)
            $where[] = "o.status IN ('ready', 'delivery', 'received')";
        } elseif ($filter === 'completed') {
            $where[] = "o.status = 'completed'";
        } elseif ($filter === 'cancelled') {
            $where[] = "o.status = 'cancelled'";
        } elseif ($filter !== 'all') {
            $where[] = "o.status = ?";
            $params[] = strtolower($filter);
        } else {
            // optimize dashboard query to exclude completed orders
            // The dashboard mainly shows active orders, completed ones go to history
            $where[] = "o.status <> 'completed'";
        }
    }

    // Add smart search functionality
    if ($isSearching) {
        // Clean search term
        $cleanSearch = trim(str_replace('#', '', $search));
        $keywords = array_filter(explode(' ', $cleanSearch), function($k) { return strlen(trim($k)) > 0; });
        
        foreach ($keywords as $keyword) {
            $searchTerm = '%' . $keyword . '%';
            
            // For phone number search, also try without special characters
            $phoneKeyword = preg_replace('/[^0-9]/', '', $keyword);
            $phoneSearchTerm = $phoneKeyword ? '%' . $phoneKeyword . '%' : '';

            $searchConditions = [
                "UPPER(o.order_id) LIKE UPPER(?)",
                "UPPER(COALESCE(o.customer_name, u.name)) LIKE UPPER(?)",
                "UPPER(COALESCE(o.order_code, '')) LIKE UPPER(?)"
            ];
            $currentParams = [$searchTerm, $searchTerm, $searchTerm];

            if ($phoneSearchTerm) {
                // Modified phone search to be more flexible per keyword
                $searchConditions[] = "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(o.customer_phone, u.phone), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?";
                $currentParams[] = $phoneSearchTerm;
            } else {
                $searchConditions[] = "COALESCE(o.customer_phone, u.phone) LIKE ?";
                $currentParams[] = $searchTerm;
            }

            // All keywords must be found (AND between keyword groups, OR within columns)
            $where[] = "(" . implode(" OR ", $searchConditions) . ")";
            $params = array_merge($params, $currentParams);
        }

        // hide completed orders from dashboard search
        // Even when searching broadly on the dashboard, completely hide 'completed' orders
        // They should only be searched and viewed within the allorder.php history section
        $where[] = "o.status <> 'completed'";
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Get total count (separate params array for count query)
    // If search is active, we need JOIN for count query too
    $countParams = $params;
    $countQuery = "SELECT COUNT(*) as total FROM orders o";
    if (!empty($search)) {
        $countQuery .= " LEFT JOIN users u ON o.user_id = u.id";
    }
    $countQuery .= " " . $whereClause;
    $countStmt = $pdo->prepare($countQuery);
    $countStmt->execute($countParams);
    $totalOrders = $countStmt->fetch()['total'];
    $totalPages = ceil($totalOrders / $perPage);

    // Get orders with pagination - join with users table to get user information
    $limitParams = array_merge($params, [$perPage, $offset]);
    $stmt = $pdo->prepare("
        SELECT o.*, 
               u.name as user_name,
               u.phone as user_phone,
               u.email as user_email,
               u.delivery_location as user_delivery_location,
               u.street_location as user_street_location,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(o.customer_email, u.email) as display_email,
               COALESCE(NULLIF(o.delivery_address, ''), NULLIF(CONCAT_WS(', ', NULLIF(u.delivery_location, ''), NULLIF(u.street_location, '')), ''), 'N/A') as display_location,
               COALESCE(oi_summary.item_count, 0) as item_count,
               COALESCE(oi_summary.items_total, 0) as items_total,
               r.username as rider_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
        LEFT JOIN (
            SELECT order_id, COUNT(*) as item_count, SUM(line_total) as items_total 
            FROM order_items 
            GROUP BY order_id
        ) oi_summary ON oi_summary.order_id = o.id
        $whereClause
        ORDER BY o.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute($limitParams);
    $orders = $stmt->fetchAll();

    // Get statistics
    $statsQuery = "
        SELECT 
            SUM(CASE WHEN o.status = 'pending' AND o.is_read = 0 THEN 1 ELSE 0 END) as new_orders,
            SUM(CASE WHEN o.status = 'pending' THEN 1 ELSE 0 END) as pending_orders,
            SUM(CASE WHEN o.status = 'pending' AND o.payment_status = 'pending' AND o.payment_method IN ('esewa', 'khalti', 'connectips', 'online', 'online_cod') THEN 1 ELSE 0 END) as online_pending_orders,
            SUM(CASE WHEN o.status = 'confirmed' OR o.status = 'preparing' THEN 1 ELSE 0 END) as confirmed_preparing_orders,
            SUM(CASE WHEN LOWER(o.status) IN ('ready', 'delivery', 'received') THEN 1 ELSE 0 END) as ready_delivery_orders,
            -- Today Stats (Using Business Day 4:00 AM shift)
            (SELECT COUNT(*) FROM orders o2 WHERE o2.created_at BETWEEN '" . getBusinessDayRange()['start'] . "' AND '" . getBusinessDayRange()['end'] . "' " . ($selectedBranchId ? " AND o2.restaurant_id = " . (int)$selectedBranchId : "") . " AND o2.status != 'cancelled') as today_orders,
            (SELECT COALESCE(SUM(o2.total), 0) FROM orders o2 WHERE o2.created_at BETWEEN '" . getBusinessDayRange()['start'] . "' AND '" . getBusinessDayRange()['end'] . "' " . ($selectedBranchId ? " AND o2.restaurant_id = " . (int)$selectedBranchId : "") . " AND o2.status != 'cancelled') as today_revenue
        FROM orders o
        WHERE o.status IN ('pending','confirmed','preparing','ready','delivery','received')
    ";
    
    if ($selectedBranchId) {
        $statsQuery .= " AND o.restaurant_id = " . (int)$selectedBranchId;
    }
    
    $stats = $pdo->query($statsQuery)->fetch();

    if (!$stats) {
        $stats = ['new_orders' => 0, 'pending_orders' => 0, 'online_pending_orders' => 0, 'confirmed_preparing_orders' => 0, 'ready_delivery_orders' => 0, 'today_orders' => 0, 'today_revenue' => 0];
    }




} catch (PDOException $e) {
    error_log("Dashboard error: " . $e->getMessage());
    $orders = [];
    $stats = ['new_orders' => 0, 'pending_orders' => 0, 'online_pending_orders' => 0, 'confirmed_preparing_orders' => 0, 'ready_delivery_orders' => 0];

    $totalOrders = 0;
    $totalPages = 1;
}
?>

<?php if (!isset($_GET['ajax'])): ?>
    <div class="dashboard-content">
        <div class="dashboard-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; flex-wrap: wrap; gap: 15px;">
            <div class="header-left">
                <h1 style="margin: 0; font-family: 'Outfit', sans-serif; font-weight: 800; color: #0f172a; font-size: 1.8rem; letter-spacing: -0.5px;">Dynamic Overview</h1>
                <p class="subtitle" style="margin: 5px 0 0 0; color: #64748b; font-weight: 500; font-size: 0.95rem;"><?php echo date('l, F j, Y'); ?> - <span id="currentTime"></span></p>
            </div>
            

        </div>

    <div class="dashboard-stats">
        <div class="stat-card">
            <div class="stat-icon stat-icon-blue">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path
                        d="M9 12L11 14L15 10M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value" id="statNewOrders"><?php echo $stats['new_orders']; ?></div>
                <div class="stat-label">New Orders</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-orange">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 8V12L15 15M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value" id="statPendingOrders"><?php echo (int)($stats['pending_orders'] ?? 0); ?></div>
                <div class="stat-label">Pending</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-purple">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path
                        d="M12 6V10M12 14H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z"
                        stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value" id="statConfirmedOrders"><?php echo (int)($stats['confirmed_preparing_orders'] ?? 0); ?></div>
                <div class="stat-label">Confirmed and Preparing</div>
            </div>
        </div>

        <div class="stat-card">
            <div class="stat-icon stat-icon-green">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
            </div>
            <div class="stat-content">
                <div class="stat-value" id="statReadyOrders"><?php echo (int)($stats['ready_delivery_orders'] ?? 0); ?></div>
                <div class="stat-label">Ready for Delivery</div>
            </div>
        </div>
    </div>

    <div class="dashboard-controls">
        <!-- Search Bar -->
        <div class="search-container">
            <form method="GET" action="" class="search-form">
                <!-- Don't include filter when searching - search works across all categories -->
                <?php if ($filter !== 'all' && empty($search)): ?>
                    <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">
                <?php endif; ?>
                <?php if (isset($_GET['branch'])): ?>
                    <input type="hidden" name="branch" value="<?php echo htmlspecialchars($_GET['branch']); ?>">
                <?php endif; ?>
                <div class="search-input-wrapper">
                    <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                        stroke-width="2">
                        <circle cx="11" cy="11" r="8" />
                        <path d="m21 21-4.35-4.35" />
                    </svg>
                    <input type="text" name="search" class="search-input"
                        placeholder="Search by name, phone, or order number..."
                        value="<?php echo htmlspecialchars($search); ?>" autocomplete="off">
                    <?php if (!empty($search)): ?>
                        <?php $clearUrl = '?filter=all' . (isset($_GET['branch']) ? '&branch=' . urlencode($_GET['branch']) : ''); ?>
                        <a href="<?php echo htmlspecialchars($clearUrl); ?>" class="search-clear" title="Clear search">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M18 6L6 18M6 6l12 12" />
                            </svg>
                        </a>
                    <?php endif; ?>
                </div>
                <button type="submit" class="search-btn">Search</button>
            </form>

            <button id="createManualOrderBtn" class="btn btn-primary" style="padding: 10px 18px; border-radius: 10px; font-weight: 700; background: #4f46e5; color: white; border: none; cursor: pointer; display: flex; align-items: center; gap: 8px; transition: all 0.2s; white-space: nowrap;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M12 5v14M5 12h14" />
                </svg>
                Create Manual Order
            </button>
        </div>

        <div class="filter-tabs">
            <?php
            $extraParams = '';
            if (!empty($search))
                $extraParams .= '&search=' . urlencode($search);
            if (isset($_GET['branch']))
                $extraParams .= '&branch=' . urlencode($_GET['branch']);
            ?>
            <a href="?filter=new<?php echo htmlspecialchars($extraParams); ?>"
                class="filter-tab <?php echo $filter === 'new' ? 'active' : ''; ?>"
                style="<?php echo $stats['new_orders'] > 0 ? 'position: relative;' : ''; ?>">
                New Orders
                <?php if ($stats['new_orders'] > 0): ?>
                    <span class="filter-badge"
                        style="background: #f44336; color: white; padding: 4px 10px; border-radius: 12px; font-size: 12px; font-weight: 700; margin-left: 8px; animation: pulse-badge 1.5s ease-in-out infinite;"><?php echo $stats['new_orders']; ?></span>
                <?php endif; ?>
            </a>
            <a href="?filter=pending<?php echo htmlspecialchars($extraParams); ?>"
                class="filter-tab <?php echo $filter === 'pending' ? 'active' : ''; ?>"
                style="<?php echo $stats['online_pending_orders'] > 0 ? 'position: relative;' : ''; ?>">
                Pending
                <?php if ($stats['online_pending_orders'] > 0): ?>
                    <span class="filter-badge pending-online-badge"
                        style="background: #ffffff; color: #111827; border: 1px solid #e5e7eb; padding: 2px 10px; border-radius: 12px; font-size: 13px; font-weight: 700; margin-left: 8px; animation: pulse-badge 1.5s ease-in-out infinite; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <img src="../assets/esewalogo.jpg" alt="eSewa"
                            style="height: 24px; width: auto; object-fit: contain; border-radius: 2px; mix-blend-mode: multiply;">
                        <?php echo $stats['online_pending_orders']; ?>
                    </span>
                <?php endif; ?>
            </a>
            <a href="?filter=confirmed<?php echo htmlspecialchars($extraParams); ?>"
                class="filter-tab <?php echo ($filter === 'confirmed' || $filter === 'preparing') ? 'active' : ''; ?>">Confirmed
                and Preparing</a>
            <a href="?filter=ready<?php echo htmlspecialchars($extraParams); ?>"
                class="filter-tab <?php echo $filter === 'ready' ? 'active' : ''; ?>">Ready for Delivery</a>
            <a href="?filter=cancelled<?php echo htmlspecialchars($extraParams); ?>"
                class="filter-tab <?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Order Cancelled</a>
        </div>
    </div>
<?php endif; ?>

<?php if (!isset($_GET['ajax']))
    echo '<div class="orders-list">'; ?>
<?php if (empty($orders)): ?>
    <div class="empty-state">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none">
            <path
                d="M9 12H15M9 16H15M17 21H7C5.89543 21 5 20.1046 5 19V5C5 3.89543 5.89543 3 7 3H12.5858C12.851 3 13.1054 3.10536 13.2929 3.29289L18.7071 8.70711C18.8946 8.89464 19 9.149 19 9.41421V19C19 20.1046 18.1046 21 17 21Z"
                stroke="currentColor" stroke-width="2" stroke-linecap="round" />
        </svg>
        <h3>No Orders Found</h3>
        <p>There are no orders matching your current filter.</p>
    </div>
<?php else: ?>
    <?php foreach ($orders as $order): ?>
        <div class="order-card <?php echo ($order['status'] === 'pending' && $order['is_read'] == 0) ? 'unread' : ''; ?>"
            data-order-id="<?php echo $order['id']; ?>">
            <div class="order-card-header">
                <div class="order-header-left">
                    <?php if (strtolower(trim($order['status'] ?? '')) === 'pending'): ?>
                        <span class="new-badge">NEW</span>
                    <?php endif; ?>
                    <div class="order-id-section">
                        <h3 class="order-id">Order
                            #ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h3>
                        <span class="order-time"><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                </div>
                <div class="order-header-right">
                    <?php
                    $status = strtolower(trim($order['status']));
                    $payStatus = strtolower(trim($order['payment_status'] ?? 'pending'));
                    $payMethod = strtolower(trim($order['payment_method'] ?? ''));
                    $isOnlinePending = ($payStatus === 'pending' && in_array($payMethod, ['esewa', 'khalti', 'connectips', 'online', 'online_cod']));

                    $statusClass = 'pending';
                    $statusText = 'Pending';

                    if ($isOnlinePending) {
                        $statusClass = 'pending';
                        $statusText = 'Processing...';
                    } elseif ($status === 'cancelled') {
                        $statusClass = 'cancelled';
                        $statusText = 'Order Cancelled';
                    } elseif ($status === 'confirmed' || $status === 'preparing') {
                        $statusClass = 'confirmed';
                        $statusText = 'Confirmed and Preparing';
                    } elseif ($status === 'ready') {
                        $statusClass = 'ready';
                        $statusText = 'Ready for Delivery';
                    } elseif ($status === 'delivery') {
                        $statusClass = 'ready';
                        $statusText = 'Ready for Delivery';
                    } elseif ($status === 'received') {
                        $statusClass = 'ready';
                        $statusText = 'Ready for Delivery';
                    } elseif ($status === 'completed') {
                        $statusClass = 'completed';
                        $statusText = 'Completed';
                    } else {
                        $statusClass = $status;
                        $statusText = ucfirst($status);
                    }
                    ?>
                    <div class="order-status-badge status-<?php echo $statusClass; ?>">
                        <?php echo $statusText; ?>
                    </div>
                </div>
            </div>

            <div class="order-card-body-compact">
                <!-- Essential Info Row - Most Important -->
                <div class="order-essential-row">
                    <div class="essential-info">
                        <div class="essential-label">CUSTOMER</div>
                        <div class="essential-value"><?php echo htmlspecialchars($order['display_name'] ?? 'N/A'); ?></div>
                    </div>
                    <div class="essential-info">
                        <div class="essential-label">PHONE</div>
                        <div class="essential-value">
                            <span><?php echo htmlspecialchars($order['display_phone'] ?? 'N/A'); ?></span>
                        </div>
                    </div>
                    <div class="essential-info">
                        <div class="essential-label">EMAIL</div>
                        <div class="essential-value essential-value-email">
                            <?php
                            $customerEmail = $order['display_email'] ?? $order['customer_email'] ?? null;
                            if ($customerEmail):
                                ?>
                                <a href="mailto:<?php echo htmlspecialchars($customerEmail); ?>" class="essential-email-link"
                                    title="<?php echo htmlspecialchars($customerEmail); ?>">
                                    <?php echo htmlspecialchars($customerEmail); ?>
                                </a>
                            <?php else: ?>
                                <span>N/A</span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="essential-info">
                        <div class="essential-label">LOCATION</div>
                        <div class="essential-value">
                            <?php
                            // Priority: order delivery_address > user_delivery_location > user_street_location
                            $orderAddr  = trim($order['delivery_address'] ?? '');
                            $userArea   = trim($order['user_delivery_location'] ?? '');
                            $userStreet = trim($order['user_street_location'] ?? '');
                            $displayAddr = $orderAddr ?: ($userArea ?: ($userStreet ?: ''));
                            ?>
                            <span class="location-main"><?php echo htmlspecialchars($displayAddr ?: 'N/A'); ?></span>
                            <?php if (!empty($order['location_lat']) && !empty($order['location_lng'])): ?>
                                <div style="font-size: 11px; color: #64748b; font-weight: 600; margin-top: 2px;">
                                    GPS: <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="essential-info">
                        <div class="essential-label">TRACK CUSTOMER</div>
                        <div class="essential-value-with-action">
                            <?php if ($order['display_phone']):
                                $phoneNumber = preg_replace('/[^0-9]/', '', $order['display_phone']);
                                $whatsappLink = 'https://wa.me/' . $phoneNumber;
                                ?>
                                <a href="<?php echo htmlspecialchars($whatsappLink); ?>" target="_blank"
                                    class="track-btn track-btn-whatsapp" title="WhatsApp">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor">
                                        <path
                                            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                                    </svg>
                                </a>
                                <?php if (($order['location_lat'] && $order['location_lng']) || $order['user_street_location']):
                                    $lat = $order['location_lat'] ?: '27.690290';
                                    $lng = $order['location_lng'] ?: '84.446950';
                                    $gmapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode("$lat,$lng");
                                    ?>
                                    <a href="<?php echo htmlspecialchars($gmapLink); ?>" target="_blank"
                                        class="track-btn track-btn-maps" title="Track on Google Maps">
                                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" />
                                            <circle cx="12" cy="10" r="3" />
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="essential-info">
                        <div class="essential-label">PAYMENT</div>
                        <div class="essential-value">
                            <?php
                            $payStatus = strtolower(trim($order['payment_status'] ?? 'unpaid'));
                            $payMethod = strtolower(trim($order['payment_method'] ?? ''));
                            // Default to unpaid if null/empty
                            if (empty($payStatus))
                                $payStatus = 'unpaid';

                            // Check if this is an online payment that is still pending
                            $isOnlinePending = ($payStatus === 'pending' && in_array($payMethod, ['esewa', 'khalti', 'connectips', 'online']));

                            if ($payStatus === 'paid') {
                                $payClass = 'payment-paid';
                                $payText = 'Paid';
                            } elseif ($isOnlinePending) {
                                $payClass = 'payment-pending';
                                $payText = 'Payment Pending';
                            } elseif ($payStatus === 'failed' || $payStatus === 'cancelled') {
                                $payClass = 'payment-unpaid'; // Use red style
                                $payText = 'Payment Rejected';
                            } else {
                                $payClass = 'payment-unpaid';
                                $payText = ucfirst($payStatus);
                            }
                            ?>
                            <span class="payment-status-badge <?php echo $payClass; ?>">
                                <?php echo $payText; ?>
                            </span>

                        </div>
                    </div>
                    <div class="essential-info" style="margin-left: 20px;">
                        <div class="essential-label">METHOD</div>
                        <div class="essential-value">
                            <?php
                            if (empty($payMethod) && $payStatus === 'paid') {
                                $payMethod = 'esewa';
                            }

                            // Normalise online/esewa variants → always display as eSewa
                            $onlineVariants = ['online', 'esewa', 'online_cod', 'connectips', 'khalti'];
                            $isEsewaMethod  = in_array(strtolower($payMethod), $onlineVariants);

                            if (strtoupper($payMethod) === 'COD') {
                                $displayMethod = 'Cash In Delivery';
                            } elseif ($isEsewaMethod) {
                                $displayMethod = 'eSewa';
                            } else {
                                $displayMethod = $payMethod ? ucfirst($payMethod) : 'N/A';
                            }

                            $esewaLogoHtml = $isEsewaMethod
                                ? '<img src="../assets/esewalogo.jpg" alt="eSewa" style="height:22px; width:auto; border-radius:3px; vertical-align:middle; margin-right:4px;">'
                                : '';

                            if ($payStatus === 'failed' || $payStatus === 'cancelled') {
                                echo '<div style="font-size:13px;font-weight:800;color:#000;">' . $esewaLogoHtml . $displayMethod . '</div>';
                                echo '<div style="font-size:11px;font-weight:700;color:#dc2626;margin-top:2px;">Failed</div>';
                            } elseif ($isOnlinePending) {
                                echo '<div style="font-size:13px;font-weight:800;color:#000;display:flex;align-items:center;gap:5px;">' . $esewaLogoHtml . $displayMethod . '</div>';
                                echo '<div style="font-size:11px;font-weight:700;color:#d97706;margin-top:2px;">Processing...</div>';
                            } elseif ($payStatus === 'paid') {
                                echo '<div style="font-size:13px;font-weight:800;color:#000;display:flex;align-items:center;gap:5px;">'
                                    . $esewaLogoHtml . $displayMethod
                                    . ' <span style="color:#059669;font-weight:700;">Paid</span></div>';
                            } else {
                                echo '<div style="font-size:13px;font-weight:800;color:#000;display:flex;align-items:center;gap:5px;">' . $esewaLogoHtml . $displayMethod . '</div>';
                            }
                            ?>
                        </div>
                    </div>
                    <div class="essential-info essential-total">
                        <div class="essential-label">TOTAL</div>
                        <div class="essential-value total-amount">Rs. <?php echo number_format($order['total'], 2); ?></div>
                    </div>
                </div>

                <?php if (!empty($order['rider_id'])): ?>
                    <div class="order-rider-info"
                        style="margin-top: 15px; padding: 12px; background: #f0fdf4; border: 1px solid #bbf7d0; border-radius: 10px; display: flex; align-items: center; gap: 12px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);">
                        <div
                            style="background: #10b981; color: white; padding: 8px; border-radius: 8px; display: flex; align-items: center; justify-content: center;">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="1" y="3" width="15" height="13"></rect>
                                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                                <circle cx="18.5" cy="18.5" r="2.5"></circle>
                            </svg>
                        </div>
                        <div style="flex: 1;">
                            <div
                                style="font-size: 11px; font-weight: 800; color: #059669; text-transform: uppercase; letter-spacing: 0.8px; margin-bottom: 2px;">
                                Delivery Rider</div>
                            <div style="font-size: 15px; font-weight: 700; color: #111827;">
                                <?php echo htmlspecialchars($order['rider_name'] ?? 'Rider Assigned'); ?>
                            </div>
                        </div>
                        <?php
                        $riderStatus = strtolower(trim($order['status']));
                        if ($riderStatus === 'received'):
                            ?>
                            <div
                                style="background: #fef3c7; color: #92400e; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; border: 1px solid #fcd34d; text-align: center;">
                                ORDER RECEIVED<br>
                                <span style="font-size: 10px; font-weight: 600; opacity: 0.8;">Payment Pending</span>
                            </div>
                        <?php elseif ($riderStatus === 'delivery'): ?>
                            <div
                                style="background: #dcfce7; color: #16a34a; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; border: 1px solid #bbf7d0;">
                                PICKED
                            </div>
                        <?php else: ?>
                            <div
                                style="background: #dcfce7; color: #16a34a; font-size: 11px; font-weight: 700; padding: 4px 10px; border-radius: 12px; border: 1px solid #bbf7d0;">
                                PICKED
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>

                <div class="order-details-row">
                    <span
                        class="detail-badge badge-<?php echo strtolower($order['service_type']); ?>"><?php echo ucfirst($order['service_type']); ?></span>
                    <span class="detail-badge"><?php echo $order['item_count']; ?> item(s)</span>
                    <?php if ($order['service_type'] === 'pickup' && $order['pickup_time']): ?>
                        <span class="detail-badge">Pickup: <?php echo htmlspecialchars($order['pickup_time']); ?></span>
                    <?php endif; ?>
                </div>

                <?php if ($order['notes']): ?>
                    <div class="order-notes">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" style="flex-shrink: 0;">
                            <path
                                d="M12 6V10M12 14H12.01M21 12C21 16.9706 16.9706 21 12 21C7.02944 21 3 16.9706 3 12C3 7.02944 7.02944 3 12 3C16.9706 3 21 7.02944 21 12Z"
                                stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                        </svg>
                        <span><strong>Notes:</strong> <?php echo htmlspecialchars($order['notes']); ?></span>
                    </div>
                <?php endif; ?>
            </div>

            <div class="order-card-footer">
                <div class="order-actions-left">
                    <?php
                    $orderStatusForMarkRead = strtolower(trim($order['status'] ?? ''));
                    if ($filter === 'new' && $orderStatusForMarkRead === 'pending' && $order['is_read'] == 0):
                        ?>
                        <button class="btn btn-sm btn-primary mark-read-btn" data-order-id="<?php echo $order['id']; ?>">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                                <path
                                    d="M16.7071 5.29289C17.0976 5.68342 17.0976 6.31658 16.7071 6.70711L8.70711 14.7071C8.31658 15.0976 7.68342 15.0976 7.29289 14.7071L3.29289 10.7071C2.90237 10.3166 2.90237 9.68342 3.29289 9.29289C3.68342 8.90237 4.31658 8.90237 4.70711 9.29289L8 12.5858L15.2929 5.29289C15.6834 4.90237 16.3166 4.90237 16.7071 5.29289Z"
                                    fill="currentColor" />
                            </svg>
                            Mark as Read
                        </button>
                    <?php endif; ?>
                    <button class="btn btn-sm btn-secondary view-order-btn" data-order-id="<?php echo $order['id']; ?>">
                        <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                            <path
                                d="M10 3C6 3 2.73 5.11 1 8.5C2.73 11.89 6 14 10 14C14 14 17.27 11.89 19 8.5C17.27 5.11 14 3 10 3ZM10 12.5C8.067 12.5 6.5 10.933 6.5 9C6.5 7.067 8.067 5.5 10 5.5C11.933 5.5 13.5 7.067 13.5 9C13.5 10.933 11.933 12.5 10 12.5Z"
                                stroke="currentColor" stroke-width="1.5" />
                        </svg>
                        View Details
                    </button>
                    <!-- Receipt Printing Buttons -->
                    <button type="button" onclick="openKitchenReceipt(<?php echo $order['id']; ?>)"
                       class="btn btn-sm btn-outline-kitchen custom-kitchen-btn"
                       title="Print for Kitchen">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 13.8V4a2 2 0 0 1 2-2h4c1.1 0 2 .9 2 2v9.8m-8 0H4a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h2m2 7.8h8m-8 0v6a2 2 0 0 0 2 2h4a2 2 0 0 0 2-2v-6m0 0h2a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-2"></path>
                        </svg>
                        Kitchen
                    </button>
                    <button type="button" onclick="openReceipt(<?php echo $order['id']; ?>)"
                       class="btn btn-sm btn-outline-customer custom-receipt-btn"
                       title="Print for Customer">
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M6 9V2h12v7"></path>
                            <path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"></path>
                            <path d="M6 14h12v8H6z"></path>
                        </svg>
                        Customer
                    </button>
                </div>

                <div class="status-update-group">
                    <label class="status-label">Status:</label>
                    <?php
                    $currentStatus = strtolower(trim($order['status'] ?? 'pending'));
                    $isGrabbed = !empty($order['rider_id']) && in_array($currentStatus, ['delivery', 'received']);
                    if ($isGrabbed):
                        // Admin can only watch, not change once rider has grabbed and is in transit
                        $subStatusText = 'Picked by Rider';
                        if ($currentStatus === 'received')
                            $subStatusText = 'Food Collected by Rider';
                        ?>
                        <div class="status-display-readonly"
                            style="display:inline-flex; align-items:center; gap:8px; padding:7px 14px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:8px; font-size:13px; font-weight:700; color:#374151; cursor:default;">
                            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
                                style="color:#6b7280;">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" />
                                <circle cx="12" cy="12" r="3" />
                            </svg>
                            <?php echo htmlspecialchars($subStatusText); ?>
                        </div>
                    <?php else: ?>
                        <select class="status-select" data-order-id="<?php echo $order['id']; ?>"
                            data-service-type="<?php echo $order['service_type']; ?>" <?php echo !empty($order['rider_id']) ? 'disabled title="Status locked: Order grabbed by rider"' : ''; ?>>
                            <?php
                            if ($currentStatus === 'pending'):
                                ?>
                                <option value="" disabled selected>-- Select status to confirm --</option>
                            <?php endif; ?>
                            <option value="confirmed" <?php echo ($currentStatus === 'confirmed' || $currentStatus === 'preparing') ? 'selected' : ''; ?>>Confirmed and Preparing</option>
                            <option value="ready" <?php echo $currentStatus === 'ready' ? 'selected' : ''; ?>>Ready for Delivery
                            </option>
                            <option value="cancelled" <?php echo $currentStatus === 'cancelled' ? 'selected' : ''; ?>>Order
                                Cancelled</option>
                        </select>
                    <?php endif; ?>
                    <?php
                    $orderStatusForDelete = strtolower(trim($order['status'] ?? ''));
                    // Only show delete button for cancelled and completed orders, NOT for received
                    if ($orderStatusForDelete === 'cancelled' || $orderStatusForDelete === 'completed'):
                        ?>
                        <button class="btn btn-sm btn-danger delete-order-btn" data-order-id="<?php echo $order['id']; ?>"
                            title="Delete Order">
                            <svg width="16" height="16" viewBox="0 0 20 20" fill="none">
                                <path d="M3 6H5H17" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path
                                    d="M8 6V4C8 3.46957 8.21071 2.96086 8.58579 2.58579C8.96086 2.21071 9.46957 2 10 2H10C10.5304 2 11.0391 2.21071 11.4142 2.58579C11.7893 2.96086 12 3.46957 12 4V6M15 6V16C15 16.5304 14.7893 17.0391 14.4142 17.4142C14.0391 17.7893 13.5304 18 13 18H7C6.46957 18 5.96086 17.7893 5.58579 17.4142C5.21071 17.0391 5 16.5304 5 16V6H15Z"
                                    stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                <path d="M8 9V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round" />
                                <path d="M12 9V14" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"
                                    stroke-linejoin="round" />
                            </svg>
                            Delete
                        </button>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if ($totalPages > 1): ?>
        <div class="pagination">
            <?php
            $paginationBase = '?filter=' . $filter;
            if (!empty($search)) {
                $paginationBase .= '&search=' . urlencode($search);
            }
            if (isset($_GET['branch'])) {
                $paginationBase .= '&branch=' . urlencode($_GET['branch']);
            }
            ?>
            <?php if ($page > 1): ?>
                <a href="<?php echo $paginationBase; ?>&page=<?php echo $page - 1; ?>" class="pagination-btn">Previous</a>
            <?php endif; ?>

            <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                <a href="<?php echo $paginationBase; ?>&page=<?php echo $i; ?>"
                    class="pagination-btn <?php echo $i === $page ? 'active' : ''; ?>"><?php echo $i; ?></a>
            <?php endfor; ?>

            <?php if ($page < $totalPages): ?>
                <a href="<?php echo $paginationBase; ?>&page=<?php echo $page + 1; ?>" class="pagination-btn">Next</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php if (!isset($_GET['ajax']))
    echo '</div>'; ?>

<?php if (isset($_GET['ajax']))
    exit; ?>

<!-- Order Detail Modal -->
<div id="orderDetailModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Order Details</h2>
            <button class="modal-close" id="closeOrderModal">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none">
                    <path d="M18 6L6 18M6 6L18 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                </svg>
            </button>
        </div>
        <div class="modal-body" id="orderDetailContent">
            <div class="loading">Loading order details...</div>
        </div>
    </div>
</div>

<!-- Custom Delete Confirmation Modal -->
<div id="deleteConfirmModal" class="dashboard-confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-icon">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6" />
            </svg>
        </div>
        <h3>Delete Order?</h3>
        <p>This will permanently remove the order from the database. This action cannot be reversed.</p>
        <div class="confirm-modal-actions">
            <button id="cancelDeleteBtn" class="confirm-btn-secondary">Keep Order</button>
            <button id="confirmDeleteBtn" class="confirm-btn-danger">Yes, Delete</button>
        </div>
    </div>
</div>

<!-- Manual Order Success Modal -->
<div id="moSuccessModal" class="dashboard-confirm-modal">
    <div class="confirm-modal-content">
        <div class="confirm-modal-icon icon-success">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <polyline points="20 6 9 17 4 12"></polyline>
            </svg>
        </div>
        <h3 style="font-family: 'Outfit', sans-serif;">Order Created!</h3>
        <p style="font-family: 'Inter', sans-serif;">Manual order has been created successfully. The page will reload now.</p>
        <div style="display: flex; justify-content: center;">
            <button onclick="window.location.reload()" class="confirm-btn-primary" style="width: 100%; max-width: 200px;">Great, Thanks!</button>
        </div>
    </div>
</div>

<!-- Mini CSS Modal for Out of Zone -->
<div id="moOutOfZoneModal" class="dashboard-confirm-modal">
    <div class="confirm-modal-content" style="border-top: 5px solid #ef4444;">
        <div class="confirm-modal-icon" style="background: #fee2e2; color: #ef4444;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="12" y1="8" x2="12" y2="12"></line>
                <line x1="12" y1="16" x2="12.01" y2="16"></line>
            </svg>
        </div>
        <h3 style="font-family: 'Outfit', sans-serif; color: #1e293b; margin-top: 15px;">Sorry, Out of Zone!</h3>
        <p id="moOutOfZoneText" style="font-family: 'Inter', sans-serif; color: #64748b; font-size: 0.95rem; line-height: 1.5; margin: 10px 0 20px;">This delivery location is <strong>beyond our limit</strong>. Please check the coordinates or notify the customer.</p>
        <div style="display: flex; justify-content: center;">
            <button onclick="document.getElementById('moOutOfZoneModal').classList.remove('active'); window.closeManualOrderModal();" class="confirm-btn-danger" style="width: 100%; max-width: 140px; background: #f1f5f9; color: #475569; border: 1px solid #e2e8f0; font-family: 'Inter', sans-serif;">Close</button>
        </div>
    </div>
</div>

<!-- Manual Order Warning Modal (General Alert Replacement) -->
<div id="moWarningModal" class="dashboard-confirm-modal">
    <div class="confirm-modal-content" style="border-top: 5px solid #f59e0b;">
        <div class="confirm-modal-icon" style="background: #fef3c7; color: #d97706;">
            <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
            </svg>
        </div>
        <h3 style="font-family: 'Outfit', sans-serif; color: #1e293b; margin-top: 15px;">Attention</h3>
        <p id="moWarningText" style="font-family: 'Inter', sans-serif; color: #64748b; font-size: 0.95rem; line-height: 1.5; margin: 10px 0 20px;">Please check your input.</p>
        <div style="display: flex; justify-content: center;">
            <button onclick="document.getElementById('moWarningModal').classList.remove('active')" class="confirm-btn-primary" style="width: 100%; max-width: 140px; background: #f59e0b; border: none;">Got it</button>
        </div>
    </div>
</div>

<style>
    @import url('https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Inter:wght@300;400;500;600;700;800&display=swap');

    /* Payment Status Styles */
    .payment-status-badge {
        display: inline-block;
        padding: 3px 8px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        line-height: 1;
    }

    /* Google UIX Inspired Manual Order Modal */
    .manual-order-modal {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%; height: 100%;
        background: rgba(15, 23, 42, 0.45);
        z-index: 9999;
        backdrop-filter: blur(12px);
        -webkit-backdrop-filter: blur(12px);
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        padding: 20px;
    }

    .manual-order-modal.active {
        display: flex;
        opacity: 1;
    }

    .mo-content {
        background: #ffffff;
        width: 100%;
        max-width: 1100px;
        max-height: 92vh;
        border-radius: 28px;
        display: flex;
        flex-direction: column;
        overflow: hidden;
        box-shadow: 0 40px 100px -20px rgba(0, 0, 0, 0.25), 0 0 0 1px rgba(0,0,0,0.05);
        transform: scale(0.98) translateY(15px);
        transition: transform 0.4s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }

    .manual-order-modal.active .mo-content {
        transform: scale(1) translateY(0);
    }

    .mo-header {
        padding: 24px 32px;
        background: #ffffff;
        border-bottom: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .mo-header h2 {
        margin: 0;
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
        letter-spacing: -0.03em;
        font-family: 'Outfit', sans-serif;
    }

    .mo-body {
        padding: 0;
        overflow: hidden;
        display: grid;
        grid-template-columns: 400px 1fr;
        height: 100%;
        background: #f8fafc;
    }

    /* Left Sidebar: Form */
    .mo-sidebar {
        background: #ffffff;
        padding: 32px;
        overflow-y: auto;
        border-right: 1px solid #f1f5f9;
        display: flex;
        flex-direction: column;
        gap: 32px;
    }

    .mo-main-content {
        padding: 32px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 24px;
        background: #f8fafc;
    }

    .mo-section-title {
        font-size: 0.75rem;
        font-weight: 800;
        color: #6366f1;
        text-transform: uppercase;
        letter-spacing: 0.1em;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        font-family: 'Outfit', sans-serif;
    }

    .mo-form-group {
        margin-bottom: 20px;
    }

    .mo-label {
        display: block;
        font-size: 0.85rem;
        font-weight: 700;
        color: #475569;
        margin-bottom: 8px;
        font-family: 'Outfit', sans-serif;
    }

    .mo-input, .mo-textarea {
        width: 100% !important;
        padding: 14px 18px !important;
        border: 2px solid #f1f5f9 !important;
        border-radius: 16px !important;
        font-size: 0.95rem !important;
        color: #1e293b !important;
        background: #f8fafc !important;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
        font-family: 'Inter', sans-serif !important;
        font-weight: 500 !important;
        box-sizing: border-box !important;
    }

    .mo-input:focus, .mo-textarea:focus {
        background: white !important;
        border-color: #6366f1 !important;
        box-shadow: 0 0 0 5px rgba(99, 102, 241, 0.08) !important;
        outline: none !important;
    }

    /* GPS Calculation Group */
    .mo-gps-group {
        display: flex;
        gap: 8px;
        margin-top: 8px;
    }

    .gps-input-wrapper {
        position: relative;
        flex: 1;
    }

    .gps-clear-btn {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        background: #f1f5f9;
        border: none;
        color: #94a3b8;
        width: 24px;
        height: 24px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        transition: all 0.2s;
        z-index: 5;
    }

    .gps-clear-btn:hover {
        background: #e2e8f0;
        color: #ef4444;
    }

    .mo-calc-btn {
        padding: 0 24px;
        background: #6366f1;
        color: white;
        border: none;
        border-radius: 16px;
        font-weight: 700;
        font-size: 0.85rem;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.2);
    }

    .mo-calc-btn:hover {
        background: #4f46e5;
        transform: translateY(-1px);
    }

    /* Right Content: Menu Explorer */
    .mo-explorer {
        background: white;
        border: 2px solid #f1f5f9;
        border-radius: 24px;
        padding: 24px;
        display: flex;
        flex-direction: column;
        gap: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.02);
    }

    .mo-search-wrapper {
        position: relative;
    }

    .mo-item-search {
        padding-left: 48px !important;
    }

    .search-icon-fixed {
        position: absolute;
        left: 18px;
        top: 50%;
        transform: translateY(-50%);
        color: #94a3b8;
        pointer-events: none;
    }

    .mo-category-tabs {
        display: flex;
        gap: 8px;
        overflow-x: auto;
        padding: 4px 0 12px;
        scrollbar-width: thin;
        scrollbar-color: #cbd5e1 transparent;
    }

    .mo-category-tabs::-webkit-scrollbar {
        height: 6px;
    }

    .mo-category-tabs::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }

    .mo-category-tabs::-webkit-scrollbar-track {
        background: transparent;
    }

    .mo-cat-tab {
        padding: 10px 20px;
        background: #f1f5f9;
        color: #64748b;
        border-radius: 100px;
        font-size: 0.85rem;
        font-weight: 700;
        white-space: nowrap;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        border: 1px solid transparent;
        font-family: 'Outfit', sans-serif;
    }

    .mo-cat-tab.active {
        background: #6366f1;
        color: white;
        box-shadow: 0 10px 15px -3px rgba(99, 102, 241, 0.2);
    }

    .mo-quick-pick {
        max-height: 400px;
        overflow-y: auto;
        display: flex;
        flex-direction: column;
        gap: 24px;
        padding-right: 8px;
    }

    .mo-qp-cat-title {
        font-size: 0.8rem;
        font-weight: 900;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.15em;
        margin: 24px 0 16px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 15px;
        text-align: center;
        font-family: 'Outfit', sans-serif;
    }

    .mo-qp-cat-title::before, .mo-qp-cat-title::after {
        content: "";
        height: 1px;
        flex: 1;
        background: radial-gradient(circle, #e2e8f0 0%, transparent 100%);
    }

    .mo-qp-items-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
        gap: 16px;
    }

    .mo-qp-item {
        background: #ffffff;
        border: 1px solid #f1f5f9;
        padding: 18px;
        border-radius: 20px;
        cursor: pointer;
        transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 100px;
        position: relative;
        box-shadow: 0 1px 2px rgba(0,0,0,0.02);
    }

    .mo-qp-item:hover {
        border-color: #6366f1;
        background: #fdfdff;
        transform: translateY(-4px);
        box-shadow: 0 12px 24px -10px rgba(99, 102, 241, 0.15);
    }

    .mo-qp-item-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
        line-height: 1.4;
    }

    .mo-qp-item-price {
        font-size: 0.85rem;
        font-weight: 800;
        color: #059669;
        margin-top: 8px;
    }

    /* Selected Items List */
    .mo-items-list {
        background: #f8fafc;
        border: 1px solid #f1f5f9;
        border-radius: 20px;
        min-height: 150px;
        max-height: 300px;
        overflow-y: auto;
    }

    .mo-selected-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 20px;
        background: white;
        border-bottom: 1px solid #f1f5f9;
        transition: background 0.2s;
    }

    .mo-selected-item:last-child { border-bottom: none; }

    .mo-item-info {
        display: flex;
        flex-direction: column;
    }

    .mo-item-main-name {
        font-size: 0.95rem;
        font-weight: 700;
        color: #1e293b;
    }

    .mo-item-sub-price {
        font-size: 0.75rem;
        font-weight: 600;
        color: #94a3b8;
    }

    .mo-item-controls {
        display: flex;
        align-items: center;
        gap: 12px;
    }

    .mo-qty-ctrl {
        display: flex;
        align-items: center;
        background: #f1f5f9;
        border-radius: 10px;
        padding: 4px;
        gap: 12px;
    }

    .mo-qty-btn {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        border: none;
        background: #ffffff;
        color: #1e293b;
        font-weight: 800;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }

    .mo-qty-btn:hover { background: #6366f1; color: white; }

    .mo-qty-num {
        font-size: 0.9rem;
        font-weight: 800;
        min-width: 20px;
        text-align: center;
    }

    .mo-item-remove {
        color: #94a3b8;
        cursor: pointer;
        padding: 8px;
        transition: color 0.2s;
    }

    .mo-item-remove:hover { color: #ef4444; }

    /* Footer Stats */
    .mo-footer {
        padding: 24px 32px;
        background: #ffffff;
        border-top: 1px solid #f1f5f9;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .mo-total-display {
        display: flex;
        flex-direction: column;
    }

    .mo-subtotal {
        font-size: 0.8rem;
        font-weight: 700;
        color: #94a3b8;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .mo-grand-total {
        font-size: 1.6rem;
        font-weight: 900;
        color: #0f172a;
        margin-top: 2px;
        font-family: 'Outfit', sans-serif;
    }

    .mo-submit-btn {
        background: #0f172a;
        color: white;
        padding: 16px 40px;
        border-radius: 18px;
        font-weight: 800;
        font-size: 1rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        box-shadow: 0 15px 30px -10px rgba(15, 23, 42, 0.3);
        font-family: 'Outfit', sans-serif;
    }

    .mo-submit-btn:hover {
        background: #6366f1;
        transform: translateY(-2px);
        box-shadow: 0 20px 40px -10px rgba(99, 102, 241, 0.4);
    }

    .mo-submit-btn:disabled {
        background: #cbd5e1;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    /* Scrollbar */
    .mo-sidebar::-webkit-scrollbar,
    .mo-main-content::-webkit-scrollbar,
    .mo-quick-pick::-webkit-scrollbar,
    .mo-items-list::-webkit-scrollbar {
        width: 6px;
    }
    .mo-sidebar::-webkit-scrollbar-thumb,
    .mo-main-content::-webkit-scrollbar-thumb,
    .mo-quick-pick::-webkit-scrollbar-thumb,
    .mo-items-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 10px;
    }
    .mo-sidebar::-webkit-scrollbar-thumb:hover,
    .mo-main-content::-webkit-scrollbar-thumb:hover,
    .mo-quick-pick::-webkit-scrollbar-thumb:hover,
    .mo-items-list::-webkit-scrollbar-thumb:hover {
        background: #94a3b8;
    }

    .payment-paid {
        background-color: #d1fae5;
        color: #047857;
        border: 1px solid #a7f3d0;
    }

    .payment-unpaid {
        background-color: #fee2e2;
        color: #b91c1c;
        border: 1px solid #fecaca;
    }

    .payment-pending {
        background-color: #ffedd5;
        color: #c2410c;
        border: 1px solid #fed7aa;
    }

    /* Print Button Styles */
    .print-receipt-btn, .custom-receipt-btn, .custom-kitchen-btn {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        text-decoration: none;
        transition: all 0.2s;
        font-weight: 600 !important;
    }

    .btn-outline-kitchen {
        color: #4b5563;
        background: #f3f4f6;
        border: 1px solid #d1d5db;
    }

    .btn-outline-kitchen:hover {
        background: #374151;
        color: white;
        border-color: #374151;
    }

    .btn-outline-customer {
        color: #2563eb;
        background: #eff6ff;
        border: 1px solid #bfdbfe;
    }

    .btn-outline-customer:hover {
        background: #2563eb;
        color: white;
        border-color: #2563eb;
    }

    .payment-method-detail {
        font-size: 11px;
        color: #6b7280;
        margin-top: 4px;
        font-weight: 500;
    }

    .essential-value .location-main {
        font-weight: 600;
        display: block;
    }

    .essential-value .location-sub {
        font-size: 11px;
        color: #6b7280;
        margin-top: 2px;
        line-height: 1.3;
        word-break: break-word;
    }

    @keyframes pulse-badge {

        0%,
        100% {
            transform: scale(1);
            box-shadow: 0 0 0 0 rgba(244, 67, 54, 0.7);
        }

        50% {
            transform: scale(1.1);
            box-shadow: 0 0 0 8px rgba(244, 67, 54, 0);
        }
    }

    /* Custom Confirm Modal Styles */
    .dashboard-confirm-modal {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(15, 23, 42, 0.65);
        z-index: 10001;
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        align-items: center;
        justify-content: center;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .dashboard-confirm-modal.active {
        display: flex;
        opacity: 1;
    }

    .confirm-modal-content {
        background: white;
        width: 90%;
        max-width: 400px;
        padding: 32px;
        border-radius: 24px;
        text-align: center;
        box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
        transform: translateY(20px);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .dashboard-confirm-modal.active .confirm-modal-content {
        transform: translateY(0);
    }

    .confirm-modal-icon {
        width: 64px;
        height: 64px;
        background: #fee2e2;
        color: #ef4444;
        border-radius: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
    }

    .confirm-modal-content h3 {
        margin: 0 0 12px;
        font-size: 1.5rem;
        font-weight: 800;
        color: #0f172a;
    }

    .confirm-modal-content p {
        margin: 0 0 24px;
        color: #64748b;
        font-size: 0.95rem;
        line-height: 1.5;
    }

    .confirm-modal-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 12px;
    }

    .confirm-btn-secondary {
        padding: 12px;
        border-radius: 12px;
        border: 2px solid #f1f5f9;
        background: #f8fafc;
        color: #475569;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
    }

    .confirm-btn-secondary:hover {
        background: #f1f5f9;
        border-color: #e2e8f0;
    }

    .confirm-btn-danger {
        padding: 12px;
        border-radius: 12px;
        border: none;
        background: #ef4444;
        color: white;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 4px 6px -1px rgba(239, 68, 68, 0.2);
    }

    .confirm-btn-primary {
        padding: 12px;
        border-radius: 12px;
        border: none;
        background: #10b981;
        color: white;
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s;
        box-shadow: 0 10px 15px -3px rgba(16, 185, 129, 0.3);
    }

    .confirm-btn-primary:hover {
        background: #059669;
        transform: translateY(-2px);
    }

    .confirm-modal-icon.icon-success {
        background: #dcfce7;
        color: #10b981;
    }
    
    .confirm-modal-icon.icon-success svg {
        filter: drop-shadow(0 4px 6px rgba(16, 185, 129, 0.2));
    }

    .confirm-btn-danger:hover {
        background: #dc2626;
        transform: translateY(-1px);
        box-shadow: 0 10px 15px -3px rgba(239, 68, 68, 0.3);
    }
    @keyframes soundBlockedPulse {
        0% { transform: scale(1); box-shadow: 0 0 0 0 rgba(225, 29, 72, 0.4); }
        70% { transform: scale(1.05); box-shadow: 0 0 0 10px rgba(225, 29, 72, 0); }
        100% { transform: scale(1); box-shadow: 0 0 0 0 rgba(225, 29, 72, 0); }
    }

    .sound-blocked {
        animation: soundBlockedPulse 1.5s infinite !important;
        border: 2px solid #e11d48 !important;
    }
</style>

<script>
    // Dynamic delivery settings are injected at the top of the file


    // Custom Alert for Manual Order
    window.moAlert = function(message) {
        const modal = document.getElementById('moWarningModal');
        const text = document.getElementById('moWarningText');
        if (modal && text) {
            text.textContent = message;
            modal.classList.add('active');
        } else {
            alert(message);
        }
    };

    // Auto-search functionality
    (function () {
        const searchInput = document.querySelector('.search-input');
        const searchForm = document.querySelector('.search-form');
        let searchTimeout;

        if (searchInput) {
            // Auto-search on input with debounce
            searchInput.addEventListener('input', function () {
                clearTimeout(searchTimeout);
                const searchValue = this.value.trim();

                // Debounce: wait 500ms after user stops typing
                searchTimeout = setTimeout(function () {
                    // When searching, don't restrict by filter - search across all orders
                    // The server will auto-redirect to the correct filter based on found order status
                    let url = '?filter=all';
                    if (searchValue) {
                        url += '&search=' + encodeURIComponent(searchValue);
                    }

                    // Navigate to search results
                    window.location.href = url;
                }, 500);
            });

            // Also allow Enter key for immediate search
            searchInput.addEventListener('keydown', function (e) {
                if (e.key === 'Enter') {
                    e.preventDefault();
                    clearTimeout(searchTimeout);
                    searchForm.submit();
                }
            });
        }
    })();

    // Refresh orders button
    document.getElementById('refreshOrdersBtn')?.addEventListener('click', function () {
        window.location.reload();
    });

    // Rebuild Statistics Function
    window.rebuildSystemStats = function() {
        if (!confirm('Are you sure you want to rebuild the dashboard statistics? This will scan the entire orders table to recalculate lifetime totals.')) {
            return;
        }

        const btn = document.getElementById('rebuildStatsBtn');
        const originalHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = 'Recalculating...';

        fetch('../system/init_stats.php')
            .then(response => response.text())
            .then(data => {
                // Success - the script resets and rebuilds
                alert('Statistics rebuilt successfully!');
                window.location.reload();
            })
            .catch(err => {
                console.error('Rebuild stats error:', err);
                alert('Error rebuilding statistics. Please try again or check logs.');
                btn.disabled = false;
                btn.innerHTML = originalHtml;
            });
    };

    // Real-time Auto-Refresh and New Order Polling
    (function () {
        // Initial count from PHP, but prefer persisted value to avoid redirect loops on reload
        const initialNewOrdersFromServer = <?php echo intval($stats['new_orders']); ?>;
        const storedCount = parseInt(localStorage.getItem('jk_lastNewOrderCount') || '', 10);
        let lastNewOrderCount = Number.isFinite(storedCount) ? storedCount : initialNewOrdersFromServer;
        const pollInterval = 3000; // Synchronized (3s) to match global sound interval
        let isUpdatingHtml = false;

        // Exposed globally so the sound-trigger in footer.php can force an instant UI sync
        window.refreshDashboardOrderList = function () {
            if (isUpdatingHtml) return;
            // Prevent DOM refresh ONLY if admin is actively using the status dropdown
            if (document.activeElement && document.activeElement.classList.contains('status-select')) {
                return;
            }
            isUpdatingHtml = true;
            const params = new URLSearchParams(window.location.search);
            params.set('ajax', '1');

            fetch('admin_dashboard.php?' + params.toString(), {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest',
                    'Accept': 'text/html, */*'
                }
            })
                .then(response => {
                    if (response.status === 401) {
                        window.location.reload();
                        return Promise.reject('Unauthorized');
                    }
                    return response.ok ? response.text() : Promise.reject();
                })
                .then(html => {
                    // Check again before applying HTML
                    if (document.activeElement && document.activeElement.classList.contains('status-select')) {
                        isUpdatingHtml = false;
                        return;
                    }
                    const list = document.querySelector('.orders-list');
                    if (list && html.trim() && list.innerHTML.trim() !== html.trim()) {
                        list.innerHTML = html;
                    }
                    isUpdatingHtml = false;
                })
                .catch(() => { isUpdatingHtml = false; });
        };

        // Consolidate Dashboard UI Updates (Called automatically by global footer poller)
        window.refreshDashboardUI = function(data) {
            const serverCount = parseInt(data.new_orders_count || 0);
            const serverLatestId = parseInt(data.latest_id || 0);
            const storedLatestId = parseInt(localStorage.getItem('jk_lastLatestId_Global') || '0', 10);
            const currentFilter = (new URLSearchParams(window.location.search).get('filter') || 'new').toLowerCase();

            // Redirect to New Orders on brand new order detection
            if (serverLatestId > storedLatestId && currentFilter !== 'new') {
                const skipNotif = localStorage.getItem('jk_skipNextNotification') === 'true';
                if (!skipNotif) {
                    window.location.href = 'admin_dashboard.php?filter=new';
                    return;
                }
            }

            // Sync HTML list every time stats arrive
            window.refreshDashboardOrderList();

            // Update Tab Badge and Dashboard stat cards
            const badge = document.querySelector('.filter-tab[href*="filter=new"] .filter-badge');
            const cardValue = document.getElementById('statNewOrders');
            const cardPending = document.getElementById('statPendingOrders');
            const cardConfirmed = document.getElementById('statConfirmedOrders');
            const cardReady = document.getElementById('statReadyOrders');

            if (serverCount > 0) {
                if (badge) badge.textContent = serverCount;
                if (cardValue) cardValue.textContent = serverCount;
            } else {
                if (badge) badge.remove();
                if (cardValue) cardValue.textContent = '0';
            }

            // Sync other status card values if provided in poller data
            if (cardPending) cardPending.textContent = data.pending_orders_count || '0';
            if (cardConfirmed) cardConfirmed.textContent = data.confirmed_orders_count || '0';
            if (cardReady) cardReady.textContent = data.ready_orders_count || '0';

            // Update Lifetime and Today Statistics
            const todayBadge = document.querySelector('.today-badge');
            if (todayBadge) {
                todayBadge.innerHTML = `<span style="display: inline-block; width: 8px; height: 8px; background: #4f46e5; border-radius: 50%;"></span>
                    TODAY: ${parseInt(data.today_orders).toLocaleString()} Orders | Rs. ${parseFloat(data.today_revenue).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
            }

            const lifetimeOrdersCard = document.querySelector('.stat-card[style*="#4f46e5"] .stat-value');
            if (lifetimeOrdersCard) {
                lifetimeOrdersCard.textContent = parseInt(data.total_orders).toLocaleString();
            }

            const lifetimeRevenueCard = document.querySelector('.stat-card[style*="#059669"] .stat-value');
            if (lifetimeRevenueCard) {
                lifetimeRevenueCard.textContent = `Rs. ${parseFloat(data.total_revenue).toLocaleString(undefined, {minimumFractionDigits: 2})}`;
            }

            // Handle eSewa icon badge for online pending orders on the Pending tab
            const onlinePendingCount = parseInt(data.online_pending_count || 0);
            const pendingTab = document.querySelector('.filter-tab[href*="filter=pending"]');
            if (pendingTab) {
                let onlineBadge = pendingTab.querySelector('.pending-online-badge');
                if (onlinePendingCount > 0) {
                    if (!onlineBadge) {
                        pendingTab.style.position = 'relative';
                        onlineBadge = document.createElement('span');
                        onlineBadge.className = 'filter-badge pending-online-badge';
                        onlineBadge.style = "background: #ffffff; color: #111827; border: 1px solid #e5e7eb; padding: 2px 10px; border-radius: 12px; font-size: 13px; font-weight: 700; margin-left: 8px; animation: pulse-badge 1.5s ease-in-out infinite; display: inline-flex; align-items: center; gap: 6px; box-shadow: 0 1px 2px rgba(0,0,0,0.05);";
                        pendingTab.appendChild(onlineBadge);
                    }
                    onlineBadge.innerHTML = `<img src="../assets/esewalogo.jpg" alt="eSewa" style="height: 24px; width: auto; object-fit: contain; border-radius: 2px; mix-blend-mode: multiply;"> ${onlinePendingCount}`;
                } else if (onlineBadge) {
                    onlineBadge.remove();
                }
            }
        };
    })();

    // Prevent accidental scroll wheel from closing native select dropdowns
    window.addEventListener('wheel', function (e) {
        if (document.activeElement && document.activeElement.classList.contains('status-select')) {
            e.preventDefault();
        }
    }, { passive: false });

    // Use event delegation for status changes (works with dynamically added content)
    document.addEventListener('change', function (e) {
        if (e.target.classList.contains('status-select')) {
            const select = e.target;
            const orderId = select.dataset.orderId;
            const newStatus = select.value;
            const orderCard = select.closest('.order-card');

            // Don't proceed if no status selected (for pending orders with placeholder)
            if (!newStatus || newStatus === '') {
                select.selectedIndex = 0; // Reset to placeholder
                return;
            }

            // Show loading state
            select.disabled = true;

            fetch('api/update_order_status.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${orderId}&status=${encodeURIComponent(newStatus)}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        window.location.reload();
                    } else {
                        alert('Error updating status');
                        select.disabled = false;
                    }
                })
                .catch(err => {
                    console.error(err);
                    alert('Error updating status');
                    select.disabled = false;
                });
        }
    });

    // Global tracker for deletion
    let orderToDelete = null;

    // View Details
    document.addEventListener('click', function (e) {
        // View Details Button 
        if (e.target.closest('.view-order-btn')) {
            const btn = e.target.closest('.view-order-btn');
            const orderId = btn.dataset.orderId;
            const modal = document.getElementById('orderDetailModal');
            const content = document.getElementById('orderDetailContent');

            if (modal && content) {
                modal.classList.add('active');
                document.documentElement.classList.add('modal-open');
                document.body.classList.add('modal-open');
                content.innerHTML = '<div class="loading">Loading order details...</div>';

                fetch('api/get_order_details.php?order_id=' + orderId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            content.innerHTML = data.html;
                        } else {
                            content.innerHTML = '<div class="error">' + (data.error || 'Failed to load details') + '</div>';
                        }
                    })
                    .catch(err => {
                        content.innerHTML = '<div class="error">Error loading details</div>';
                        console.error(err);
                    });
            }
        }

        // Close Modal
        if (e.target.closest('.modal-close') || e.target.classList.contains('modal')) {
            const modal = document.getElementById('orderDetailModal');
            if (modal) {
                modal.classList.remove('active');
                document.documentElement.classList.remove('modal-open');
                document.body.classList.remove('modal-open');
            }
        }

        // Delete order handler
        if (e.target.closest('.delete-order-btn')) {
            const btn = e.target.closest('.delete-order-btn');
            e.stopPropagation();
            
            orderToDelete = {
                id: btn.dataset.orderId,
                card: btn.closest('.order-card'),
                btn: btn
            };

            const modal = document.getElementById('deleteConfirmModal');
            if (modal) {
                modal.classList.add('active');
                document.documentElement.classList.add('modal-open');
                document.body.classList.add('modal-open');
            }
        }

        // Custom Modal Cancel
        if (e.target.id === 'cancelDeleteBtn' || (e.target.closest('.dashboard-confirm-modal') && e.target.classList.contains('dashboard-confirm-modal'))) {
            const modal = document.getElementById('deleteConfirmModal');
            if (modal) {
                modal.classList.remove('active');
                document.documentElement.classList.remove('modal-open');
                document.body.classList.remove('modal-open');
            }
            orderToDelete = null;
        }

        // Custom Modal Confirm Delete
        if (e.target.id === 'confirmDeleteBtn') {
            if (!orderToDelete) return;

            const { id, card, btn } = orderToDelete;
            const modal = document.getElementById('deleteConfirmModal');
            
            // Show loading on the button in the modal
            const confirmBtn = document.getElementById('confirmDeleteBtn');
            const cancelBtn = document.getElementById('cancelDeleteBtn');
            
            confirmBtn.disabled = true;
            cancelBtn.disabled = true;
            const originalBtnText = confirmBtn.innerHTML;
            confirmBtn.innerHTML = 'Deleting...';

            fetch('api/delete_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${id}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Close modal
                        if (modal) modal.classList.remove('active');
                        document.documentElement.classList.remove('modal-open');
                        document.body.classList.remove('modal-open');

                        if (card) {
                            card.style.opacity = '0';
                            card.style.transform = 'translateX(20px)';
                            setTimeout(() => {
                                card.remove();
                                window.location.reload();
                            }, 300);
                        } else {
                            window.location.reload();
                        }
                    } else {
                        alert(data.error || 'Failed to delete order');
                        confirmBtn.disabled = false;
                        cancelBtn.disabled = false;
                        confirmBtn.innerHTML = originalBtnText;
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    alert('An error occurred');
                    confirmBtn.disabled = false;
                    cancelBtn.disabled = false;
                    confirmBtn.innerHTML = originalBtnText;
                });
        }

        // Mark as Read Handler
        if (e.target.closest('.mark-read-btn')) {
            const btn = e.target.closest('.mark-read-btn');
            const orderId = btn.dataset.orderId;
            const originalText = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = 'Updating...';

            fetch('api/mark_read.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `order_id=${orderId}`
            })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        // Reload to update UI state (remove badge/button)
                        window.location.reload();
                    } else {
                        alert(data.error || 'Failed to mark as read');
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    }
                })
                .catch(err => {
                    console.error('Error:', err);
                    alert('An error occurred');
                    btn.disabled = false;
                    btn.innerHTML = originalText;
                });
        }
    });

    // Manual Order Modal Functions
    window.moSelectedItems = [];
    window.menuItems = []; // This will be populated on modal open or page load

    // Function to fetch menu items
    async function fetchMenuItems() {
        try {
            const response = await fetch('api/get_menu_items.php');
            const data = await response.json();
            if (data.success) {
                window.menuItems = data.items;
                window.populateMOCategories();
                window.renderMOQuickPick();
            } else {
                console.error('Failed to fetch menu items:', data.error);
            }
        } catch (error) {
            console.error('Error fetching menu items:', error);
        }
    }

    window.populateMOCategories = function() {
        const tabsContainer = document.getElementById('moCategoryTabs');
        if (!tabsContainer || !window.menuItems) return;

        const categories = [...new Set(window.menuItems.map(item => item.category || 'Other'))].sort();
        
        let html = '<div class="mo-cat-tab active" data-cat="all" onclick="window.switchMOCat(\'all\')">All</div>';
        categories.forEach(cat => {
            html += `<div class="mo-cat-tab" data-cat="${cat}" onclick="window.switchMOCat(\'${cat.replace(/'/g, "\\'")}\')">${cat}</div>`;
        });
        tabsContainer.innerHTML = html;
    };

    window.switchMOCat = function(cat) {
        // Toggle active class on tabs
        const tabs = document.querySelectorAll('.mo-cat-tab');
        tabs.forEach(tab => {
            if (tab.getAttribute('data-cat') === cat) {
                tab.classList.add('active');
            } else {
                tab.classList.remove('active');
            }
        });

        // Store selected category globally for search integration
        window.currentMOBrowseCat = cat;
        
        // Use search query if present
        const searchVal = document.getElementById('moItemSearch') ? document.getElementById('moItemSearch').value : '';
        window.renderMOQuickPick(cat, searchVal);
    };

    window.renderMOQuickPick = function(filterCat = 'all', searchQuery = '') {
        const area = document.getElementById('moQuickPickArea');
        if (!area || !window.menuItems) return;

        const lowQuery = searchQuery.toLowerCase().trim();

        // Group by category
        const groups = {};
        window.menuItems.forEach(item => {
            const cat = item.category || 'Other';
            
            // Filter by Category
            if (filterCat !== 'all' && cat !== filterCat) return;
            
            // Filter by Search Query
            if (lowQuery && !item.name.toLowerCase().includes(lowQuery)) return;
            
            if (!groups[cat]) groups[cat] = [];
            groups[cat].push(item);
        });

        let html = '';
        const entries = Object.entries(groups);
        
        if (entries.length === 0) {
            html = '<div style="text-align: center; padding: 40px; color: #94a3b8;"><svg width="40" height="40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="margin-bottom: 12px; display: block; margin-left: auto; margin-right: auto; opacity: 0.5;"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/></svg>No matching dishes found. Try a different search.</div>';
        } else {
            for (const [cat, items] of entries) {
                html += `
                    <div class="mo-qp-category">
                        <div class="mo-qp-cat-title">${cat}</div>
                        <div class="mo-qp-items-grid">
                            ${items.map(item => `
                                <div class="mo-qp-item" onclick="window.addMOItem(${JSON.stringify(item).replace(/"/g, '&quot;')})">
                                    <span class="mo-qp-item-name">${item.name}</span>
                                    <span class="mo-qp-item-price">Rs. ${parseFloat(item.price).toFixed(2)}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                `;
            }
        }
        area.innerHTML = html;
    };

    // Call this on page load or when the modal is about to open
    fetchMenuItems();

    window.openManualOrderModal = function() {
        document.getElementById('manualOrderModal').classList.add('active');
        document.documentElement.classList.add('modal-open');
        document.body.classList.add('modal-open');
        
        // Reset form
        document.getElementById('moForm').reset();
        
        // Reset category tabs and search
        window.currentMOBrowseCat = 'all';
        const searchInput = document.getElementById('moItemSearch');
        if (searchInput) searchInput.value = '';
        
        window.populateMOCategories();
        window.renderMOQuickPick('all');

        document.getElementById('moSelectedItemsList').innerHTML = `
            <div style="padding: 40px; text-align: center; color: #94a3b8; font-size: 0.95rem; font-weight: 500;">
                No items added yet. Search or browse above to fill the platter.
            </div>
        `;
        window.moSelectedItems = [];
        window.updateMOTotals();
    };

    window.closeManualOrderModal = function() {
        document.getElementById('manualOrderModal').classList.remove('active');
        document.documentElement.classList.remove('modal-open');
        document.body.classList.remove('modal-open');
    };


    window.addMOItem = function(item) {
        // Check if item already exists in selected items
        const existingItemIndex = window.moSelectedItems.findIndex(selected => selected.id === item.id);
        if (existingItemIndex > -1) {
            window.moSelectedItems[existingItemIndex].quantity++;
        } else {
            window.moSelectedItems.push({ ...item, quantity: 1 });
        }
        window.renderMOSelectedItems();
        window.updateMOTotals();
    };

    window.removeMOItem = function(itemId) {
        window.moSelectedItems = window.moSelectedItems.filter(item => item.id != itemId);
        window.renderMOSelectedItems();
        window.updateMOTotals();
    };

    window.updateMOItemQuantity = function(itemId, change) {
        const itemIndex = window.moSelectedItems.findIndex(item => item.id == itemId);
        if (itemIndex > -1) {
            window.moSelectedItems[itemIndex].quantity += change;
            if (window.moSelectedItems[itemIndex].quantity <= 0) {
                window.moSelectedItems.splice(itemIndex, 1); // Remove if quantity is 0 or less
            }
            window.renderMOSelectedItems();
            window.updateMOTotals();
        }
    };

    window.renderMOSelectedItems = function() {
        const selectedItemsList = document.getElementById('moSelectedItemsList');
        if (!selectedItemsList) return;
        
        selectedItemsList.innerHTML = '';

        if (window.moSelectedItems.length === 0) {
            selectedItemsList.innerHTML = `
                <div style="padding: 40px; text-align: center; color: #94a3b8; font-size: 0.95rem; font-weight: 500;">
                    No items added yet. Search or browse above to fill the platter.
                </div>
            `;
            return;
        }

        window.moSelectedItems.forEach(item => {
            const itemDiv = document.createElement('div');
            itemDiv.className = 'mo-selected-item';
            
            const lineTotal = (parseFloat(item.price) * item.quantity).toFixed(2);
            
            itemDiv.innerHTML = `
                <div class="mo-item-info">
                    <div class="mo-item-main-name">${item.name}</div>
                    <div class="mo-item-sub-price">Rs. ${parseFloat(item.price).toFixed(2)} / unit</div>
                </div>
                <div class="mo-item-controls">
                    <div class="mo-qty-ctrl">
                        <button type="button" class="mo-qty-btn" onclick="window.updateMOItemQuantity('${item.id}', -1)" title="Decrease">
                             <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"><path d="M5 12h14"/></svg>
                        </button>
                        <span class="mo-qty-num">${item.quantity}</span>
                        <button type="button" class="mo-qty-btn" onclick="window.updateMOItemQuantity('${item.id}', 1)" title="Increase">
                             <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="4"><path d="M12 5v14M5 12h14"/></svg>
                        </button>
                    </div>
                    <div style="min-width: 90px; text-align: right; font-weight: 800; color: #0f172a; font-size: 0.95rem;">
                        Rs. ${lineTotal}
                    </div>
                    <div class="mo-item-remove" onclick="window.removeMOItem('${item.id}')" title="Remove Item">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <path d="M3 6h18M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2M10 11v6M14 11v6"/>
                        </svg>
                    </div>
                </div>
            `;
            selectedItemsList.appendChild(itemDiv);
        });
    };

    window.updateMOTotals = function() {
        let subtotal = 0;
        window.moSelectedItems.forEach(item => {
            subtotal += parseFloat(item.price) * item.quantity;
        });

        const deliveryFee = parseFloat(document.getElementById('moDeliveryFee').value) || 0;
        const grandTotal = subtotal + deliveryFee;

        document.getElementById('moSubtotalDisplay').textContent = `Subtotal: Rs. ${subtotal.toFixed(2)}`;
        document.getElementById('moGrandTotalDisplay').textContent = `Total: Rs. ${grandTotal.toFixed(2)}`;
    };

    window.clearGPS = function() {
        document.getElementById('moGPS').value = '';
        document.getElementById('moLat').value = '';
        document.getElementById('moLng').value = '';
        document.getElementById('moDistance').value = '';
        document.getElementById('moDeliveryFee').value = '0';
        const feedback = document.getElementById('moGPSFeedback');
        if (feedback) feedback.innerHTML = '';
        window.updateMOTotals();
    };

    window.parseGPS = async function(val) {
        if (!val) return;
        const parts = val.split(/[,\s]+/).map(p => p.trim()).filter(p => p.length > 0);
        const feedback = document.getElementById('moGPSFeedback');
        const distanceInput = document.getElementById('moDistance');
        const feeInput = document.getElementById('moDeliveryFee');

        if (parts.length >= 2) {
            const lat = parts[0];
            const lng = parts[1];
            document.getElementById('moLat').value = lat;
            document.getElementById('moLng').value = lng;
            
            if (feedback) {
                feedback.innerHTML = `<span style="color: #6366f1; font-size: 11px; font-weight: 700;">🔍 Calculating distance...</span>`;
            }

            try {
                const response = await fetch('api/location/calc_delivery.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        lat: lat,
                        lng: lng,
                        branch_id: typeof CURRENT_BRANCH_ID !== 'undefined' ? CURRENT_BRANCH_ID : null
                    })
                });
                const data = await response.json();
                
                if (data.success) {
                    const distance = parseFloat(data.distance_km);
                    const currentMax = parseFloat(data.max_distance) || (typeof DELIVERY_MAX_DISTANCE !== 'undefined' ? DELIVERY_MAX_DISTANCE : 0);
                    
                    // Check max distance limit
                    if (distance > currentMax) {
                        const outOfZoneModal = document.getElementById('moOutOfZoneModal');
                        const outOfZoneText = document.getElementById('moOutOfZoneText');
                        if (outOfZoneText) {
                            outOfZoneText.innerHTML = `This delivery location is <strong>beyond our ${currentMax}KM limit</strong> (${distance} KM detected). Please check the coordinates.`;
                        }
                        if (outOfZoneModal) outOfZoneModal.classList.add('active');
                        
                        // Clear all fields since it's out of zone
                        document.getElementById('moLat').value = '';
                        document.getElementById('moLng').value = '';
                        if (distanceInput) distanceInput.value = '';
                        if (feeInput) feeInput.value = '0';
                        if (feedback) {
                            feedback.innerHTML = `<span style="color: #ef4444; font-size: 11px; font-weight: 800;">⚠ Out of Zone (${distance} KM)</span>`;
                        }
                        window.updateMOTotals();
                        return;
                    }

                    if (distanceInput) distanceInput.value = data.distance_km;
                    if (feeInput) {
                        feeInput.value = data.delivery_fee;
                        window.updateMOTotals(); // Update the totals display
                    }
                    if (feedback) {
                        feedback.innerHTML = `<span style="color: #10b981; font-size: 11px; font-weight: 700;">✓ Road Distance: ${data.distance_km} KM</span>`;
                    }
                } else {
                    if (feedback) {
                        feedback.innerHTML = `<span style="color: #ef4444; font-size: 11px; font-weight: 700;">⚠ ${data.error || 'Calc Error'}</span>`;
                    }
                }
            } catch (err) {
                if (feedback) feedback.innerHTML = '';
            }
        } else {
            if (feedback) feedback.innerHTML = '';
        }
    };

    window.submitManualOrder = async function() {
        const customerName = document.getElementById('moCustomerName').value.trim();
        const customerPhone = document.getElementById('moCustomerPhone').value.trim();
        const customerEmail = document.getElementById('moCustomerEmail').value.trim();
        const serviceType = 'delivery';
        const address = document.getElementById('moAddress').value.trim();
        const lat = document.getElementById('moLat').value.trim();
        const lng = document.getElementById('moLng').value.trim();
        const distanceKm = document.getElementById('moDistance').value.trim();
        const deliveryFee = parseFloat(document.getElementById('moDeliveryFee').value) || 0;
        const notes = document.getElementById('moNotes').value.trim();

        if (!customerName || !customerPhone) {
            window.moAlert('Customer Name and Phone Number are required.');
            return;
        }

        if (serviceType === 'delivery' && !address) {
            window.moAlert('Delivery Address is required for delivery orders.');
            return;
        }

        // Max Distance Limit Validation
        if (serviceType === 'delivery') {
            const dist = parseFloat(distanceKm);
            if (isNaN(dist) || dist <= 0) {
                window.moAlert('Delivery order requires a valid calculated distance. Please click "Calc" on the GPS location.');
                return;
            }
            if (dist > DELIVERY_MAX_DISTANCE) {
                window.moAlert(`Cannot create order: Delivery distance (${dist}km) exceeds the ${DELIVERY_MAX_DISTANCE}km limit.`);
                return;
            }
        }

        if (window.moSelectedItems.length === 0) {
            window.moAlert('Please add at least one item to the order.');
            return;
        }

        const submitBtn = document.getElementById('moSubmitBtn');
        submitBtn.disabled = true;
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = 'Creating...';

        const orderData = {
            customer_name: customerName,
            customer_phone: customerPhone,
            customer_email: customerEmail,
            service_type: serviceType,
            delivery_address: address,
            location_lat: lat,
            location_lng: lng,
            delivery_distance_km: distanceKm,
            delivery_fee: deliveryFee,
            notes: notes,
            items: window.moSelectedItems.map(item => ({
                name: item.name,
                quantity: item.quantity,
                price: item.price
            }))
        };

        try {
            const response = await fetch('api/create_manual_order.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(orderData)
            });
            const data = await response.json();

            if (data.success) {
                // Show custom premium success modal
                const successModal = document.getElementById('moSuccessModal');
                if (successModal) {
                    // Prevent the "Ding" sound for this manual order
                    localStorage.setItem('jk_skipNextNotification', 'true');
                    successModal.classList.add('active');
                } else {
                    alert('Manual order created successfully!');
                    window.location.reload();
                }
            } else {
                alert('Error creating order: ' + (data.error || 'Unknown error'));
            }
        } catch (error) {
            console.error('Error:', error);
            alert('An error occurred while creating the order.');
        } finally {
            submitBtn.disabled = false;
            submitBtn.innerHTML = originalText;
        }
    };

    // Event listener for the "Create Manual Order" button in the main UI
    document.addEventListener('click', function(e) {
        if (e.target.id === 'createManualOrderBtn') {
            window.openManualOrderModal();
        }
    });

    /**
     * Premium Smooth Scroll Hub
     * Provides inertia and buttery smoothness to scrollable areas
     */
    function initMoSmoothScroll() {
        const scrollAreas = ['.mo-sidebar', '.mo-main-content', '.mo-quick-pick', '.mo-items-list'];
        
        scrollAreas.forEach(selector => {
            const el = document.querySelector(selector);
            if (!el) return;

            el.style.scrollBehavior = 'auto'; // Disable native smooth to use our custom logic
            el.style.overscrollBehavior = 'auto'; // Allow scroll chaining

            let targetY = el.scrollTop;
            let currentY = el.scrollTop;
            let isMoving = false;
            
            // Sync targetY if something else scrolls the element (like dragging scrollbar)
            el.addEventListener('scroll', () => {
                if (!isMoving) targetY = el.scrollTop;
            });

            function lerp(start, end, amt) {
                return (1 - amt) * start + amt * end;
            }

            function frame() {
                if (!isMoving) return;
                
                currentY = lerp(currentY, targetY, 0.12); // Slightly smoother easing
                el.scrollTop = currentY;

                if (Math.abs(targetY - currentY) < 0.2) {
                    el.scrollTop = targetY;
                    isMoving = false;
                } else {
                    requestAnimationFrame(frame);
                }
            }

            el.addEventListener('wheel', (e) => {
                const maxScroll = el.scrollHeight - el.clientHeight;
                
                // PERFECT SCROLL LOGIC: 
                // Only hijack if we are NOT at boundaries, otherwise let it scroll the parent
                if (e.deltaY < 0 && el.scrollTop <= 0) return; // Top boundary
                if (e.deltaY > 0 && el.scrollTop >= maxScroll) return; // Bottom boundary
                
                e.preventDefault();
                e.stopPropagation(); // STOP BUBBLING: Prevents parent .mo-body from moving while we scroll this area
                
                targetY += e.deltaY; // Native multiplier (1:1 with delta feels logical)
                
                // Clamp targetY
                targetY = Math.max(0, Math.min(targetY, maxScroll));

                if (!isMoving) {
                    isMoving = true;
                    currentY = el.scrollTop;
                    requestAnimationFrame(frame);
                }
            }, { passive: false });
        });
    }

    // Initialize smooth scroll when modal opens
    const originalOpenModal = window.openManualOrderModal;
    window.openManualOrderModal = function() {
        originalOpenModal();
        // Delay slightly to ensure elements are rendered/sized
        setTimeout(initMoSmoothScroll, 100);
    };

</script>

<!-- Manual Order Modal -->
<div id="manualOrderModal" class="manual-order-modal" onclick="if(event.target === this) window.closeManualOrderModal()">
    <div class="mo-content">
        <div class="mo-header">
            <h2>Create Manual Order</h2>
            <button class="modal-close" onclick="window.closeManualOrderModal()" style="background: #f1f5f9; border: none; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; color: #64748b; cursor: pointer; transition: all 0.2s;">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M18 6L6 18M6 6l12 12"/>
                </svg>
            </button>
        </div>
        <div class="mo-body">
            <!-- Sidebar: Customer & Delivery Facts -->
            <div class="mo-sidebar">
                <form id="moForm">
                    <div class="mo-section">
                        <div class="mo-section-title">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                            Customer Info
                        </div>
                        <div class="mo-form-group">
                            <label class="mo-label">Full Name *</label>
                            <input type="text" id="moCustomerName" class="mo-input" placeholder="e.g. John Doe" required>
                        </div>
                        <div class="mo-form-group">
                            <label class="mo-label">Phone Number *</label>
                            <input type="tel" id="moCustomerPhone" class="mo-input" placeholder="e.g. 9841234567" required>
                        </div>
                        <div class="mo-form-group">
                            <label class="mo-label">Email Address (Optional)</label>
                            <input type="email" id="moCustomerEmail" class="mo-input" placeholder="e.g. john@example.com">
                        </div>
                        
                        <div class="mo-section-title" style="margin-top: 10px;">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/>
                            </svg>
                            Delivery Logistics
                        </div>
                        <div class="mo-form-group">
                            <label class="mo-label">Drop-off Address *</label>
                            <textarea id="moAddress" class="mo-textarea" style="height: 90px;" placeholder="Street name, landmark, house no..."></textarea>
                        </div>
                        <div class="mo-form-group">
                            <label class="mo-label">GPS Location (Precise)</label>
                            <div class="mo-gps-group">
                                <div class="gps-input-wrapper">
                                    <input type="text" id="moGPS" class="mo-input" placeholder="Lat, Lng" 
                                        onpaste="setTimeout(() => window.parseGPS(this.value), 50)">
                                    <button type="button" onclick="window.clearGPS()" class="gps-clear-btn" title="Clear">
                                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3">
                                            <path d="M18 6L6 18M6 6l12 12"/>
                                        </svg>
                                    </button>
                                </div>
                                <button type="button" onclick="window.parseGPS(document.getElementById('moGPS').value)" class="mo-calc-btn">
                                    Calc
                                </button>
                            </div>
                            <div id="moGPSFeedback" style="margin-top: 6px; min-height: 16px;"></div>
                            <input type="hidden" id="moLat">
                            <input type="hidden" id="moLng">
                        </div>
                        
                        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px;">
                            <div class="mo-form-group">
                                <label class="mo-label">Distance (KM)</label>
                                <input type="number" id="moDistance" class="mo-input" step="any" readonly style="background: #f1f5f9; cursor: not-allowed; font-weight: 700;">
                            </div>
                            <div class="mo-form-group">
                                <label class="mo-label">Fee (Rs.)</label>
                                <input type="number" id="moDeliveryFee" class="mo-input" value="0" readonly style="background: #f1f5f9; cursor: not-allowed; font-weight: 700;">
                            </div>
                        </div>

                        <div class="mo-form-group" style="margin-top: 10px;">
                            <label class="mo-label">Chef & Rider Notes</label>
                            <textarea id="moNotes" class="mo-textarea" style="height: 80px;" placeholder="Special instructions..."></textarea>
                        </div>
                    </div>
                </form>
            </div>

            <!-- Main Area: Item Selection -->
            <div class="mo-main-content">
                <div class="mo-section-title">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M6 2 3 6v14a2 2 0 0 0 2 2h14a2 4 0 0 0 2-2V6l-3-4Z"/><path d="M3 6h18"/><path d="M16 10a4 4 0 0 1-8 0"/>
                    </svg>
                    Order Basket
                </div>

                <div class="mo-explorer">
                    <div class="mo-search-wrapper">
                        <svg class="search-icon-fixed" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                            <circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>
                        </svg>
                        <input type="text" id="moItemSearch" class="mo-input mo-item-search" placeholder="Search dish names..." oninput="window.renderMOQuickPick('all', this.value)">
                    </div>

                    <div class="mo-category-tabs" id="moCategoryTabs">
                        <!-- Polished tabs injected here -->
                    </div>

                    <div class="mo-quick-pick" id="moQuickPickArea">
                        <!-- Smart grid injected here -->
                    </div>
                </div>

                <div class="mo-section-title" style="margin-top: 12px;">Items in Basket</div>
                <div id="moSelectedItemsList" class="mo-items-list">
                    <div style="padding: 40px; text-align: center; color: #94a3b8; font-size: 0.95rem; font-weight: 500;">
                        No items added yet. Search or browse above to fill the platter.
                    </div>
                </div>
            </div>
        </div>
        <div class="mo-footer">
            <div class="mo-total-display">
                <div class="mo-subtotal" id="moSubtotalDisplay">Subtotal: Rs. 0.00</div>
                <div class="mo-grand-total" id="moGrandTotalDisplay">Total: Rs. 0.00</div>
            </div>
            <button id="moSubmitBtn" class="mo-submit-btn" onclick="window.submitManualOrder()">
                Place Manual Order
            </button>
        </div>
    </div>
</div>

<!-- Receipt Modal -->
<div id="receiptModal"
    style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; align-items: center; justify-content: center; padding: 20px;">
    <div
        style="background: white; border-radius: 12px; width: 100%; max-width: 400px; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: 0 10px 25px rgba(0,0,0,0.1);">
        <div
            style="padding: 15px 20px; border-bottom: 1px solid #f1f5f9; display: flex; justify-content: space-between; align-items: center;">
            <h3 id="receiptModalTitle" style="margin: 0; font-size: 16px; font-weight: 700;">Customer Receipt</h3>
            <button onclick="closeReceiptModal()"
                style="background: none; border: none; font-size: 20px; cursor: pointer; color: #94a3b8;">&times;</button>
        </div>
        <div id="receiptContent" style="padding: 20px; overflow-y: auto; display: flex; justify-content: center;">
            <div style="padding: 20px; text-align: center; color: #64748b;">Loading receipt...</div>
        </div>
        <div style="padding: 15px 20px; border-top: 1px solid #f1f5f9; background: #f8fafc; display: flex; gap: 10px;">
            <button onclick="printReceiptContent()"
                style="flex: 1; background: #000; color: white; border: none; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">Print
                Receipt</button>
            <button onclick="closeReceiptModal()"
                style="flex: 1; background: white; color: #475569; border: 1px solid #cbd5e1; padding: 10px; border-radius: 8px; font-weight: 600; cursor: pointer;">Close</button>
        </div>
    </div>
</div>

<script>
    // Simple in-page cache so subsequent opens for the same order are instant.
    const receiptCache = {};
    const kitchenReceiptCache = {};

    function openReceipt(orderId) {
        const modal = document.getElementById('receiptModal');
        const content = document.getElementById('receiptContent');
        const title = document.getElementById('receiptModalTitle');

        title.textContent = "Customer Receipt";
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        document.documentElement.classList.add('modal-open');

        // If we already loaded this receipt once, show it immediately
        if (receiptCache[orderId]) {
            content.innerHTML = receiptCache[orderId];
            return;
        }

        content.innerHTML = '<div style="padding: 20px; text-align: center; color: #64748b;">Loading receipt...</div>';

        fetch('api/get_receipt_html.php?order_id=' + orderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    receiptCache[orderId] = data.html || '';
                    content.innerHTML = receiptCache[orderId];
                } else {
                    content.innerHTML = '<div style="padding: 20px; text-align: center; color: #ef4444;">Error: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                content.innerHTML = '<div style="padding: 20px; text-align: center; color: #ef4444;">Connection failed</div>';
            });
    }

    function openKitchenReceipt(orderId) {
        const modal = document.getElementById('receiptModal');
        const content = document.getElementById('receiptContent');
        const title = document.getElementById('receiptModalTitle');

        title.textContent = "Kitchen Receipt (KOT)";
        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        document.documentElement.classList.add('modal-open');

        // If we already loaded this KOT once, show it immediately
        if (kitchenReceiptCache[orderId]) {
            content.innerHTML = kitchenReceiptCache[orderId];
            return;
        }

        content.innerHTML = '<div style="padding: 20px; text-align: center; color: #64748b;">Loading Kitchen Receipt...</div>';

        fetch('api/get_kitchen_receipt_html.php?order_id=' + orderId)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    kitchenReceiptCache[orderId] = data.html || '';
                    content.innerHTML = kitchenReceiptCache[orderId];
                } else {
                    content.innerHTML = '<div style="padding: 20px; text-align: center; color: #ef4444;">Error: ' + data.error + '</div>';
                }
            })
            .catch(err => {
                content.innerHTML = '<div style="padding: 20px; text-align: center; color: #ef4444;">Connection failed</div>';
            });
    }

    function closeReceiptModal() {
        document.getElementById('receiptModal').style.display = 'none';
        document.body.style.overflow = '';
        document.documentElement.classList.remove('modal-open');
    }

    function printReceiptContent() {
        const content = document.getElementById('receiptContent').innerHTML;
        const printWindow = window.open('', '_blank', 'width=350,height=800');

        printWindow.document.write('<!DOCTYPE html><html><head><title>JustKleek - Receipt</title>');
        printWindow.document.write('<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">');
        printWindow.document.write(`<style>
            @page { 
                margin: 0; 
                size: 80mm auto;
            }
            body { 
                margin: 0; 
                padding: 6px 4px; /* Inner margin */
                width: 68mm;      /* Smaller than paper width to prevent cropping */
                font-family: 'Inter', sans-serif;
                overflow-x: hidden;
                box-sizing: border-box;
            }
            .receipt-print-wrapper { 
                width: 100% !important; 
                max-width: 68mm !important;
                padding: 0 !important; 
                border: none !important; 
                margin: 0 auto !important;
                font-size: 9px !important; /* Slightly smaller base font */
            }
            @media print {
                body { width: 68mm; padding: 4px; }
            }
        </style>`);
        printWindow.document.write('</head><body>');
        printWindow.document.write(content);
        printWindow.document.write('</body></html>');

        printWindow.document.close();
        printWindow.focus();

        setTimeout(() => {
            printWindow.print();
            printWindow.close();
        }, 800);
    }
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>