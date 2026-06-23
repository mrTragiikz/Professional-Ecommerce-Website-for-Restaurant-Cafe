<?php
if (!defined('RiderAppCore')) {
    define('RiderAppCore', true);
}
require '_guard.php';

require_once __DIR__ . '/../../config/db.php';
?>
<script>
    const currentRiderId = <?php echo json_encode($riderId); ?>;
    const currentContextMode = <?php echo json_encode($contextMode); ?>;
</script>
<?php


// Generate CSRF token for forms
if (empty($_SESSION['rider_csrf'])) {
    $_SESSION['rider_csrf'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['rider_csrf'];

$msg = $_GET['msg'] ?? '';
$error = $_GET['error'] ?? '';

// Get filter parameter
$filter = $_GET['filter'] ?? 'available';
$search = trim($_GET['search'] ?? '');
$filter_date = trim($_GET['filter_date'] ?? '');

// Auto-default to Today for the delivered tab if no date is picked
if ($filter === 'delivered' && empty($filter_date)) {
    $filter_date = date('Y-m-d');
}

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$perPage = 15;
// Initial Default Pagination Variables
$totalOrders = 0;
$totalPages = 0;
$offset = ($page - 1) * $perPage;

try {
    $where = [];
    $params = [];

    // Branch filter.
    $where[] = "o.restaurant_id = ?";
    $params[] = $riderBranchId;

    if ($filter === 'my') {
        // To Deliver: orders grabbed and in transit
        $where[] = "o.rider_id = ? AND LOWER(o.status) IN ('ready', 'delivery')";
        $params[] = $riderId;
    } elseif ($filter === 'received_orders') {
        // Order Received: picked up from restaurant, awaiting payment
        $where[] = "o.rider_id = ? AND LOWER(o.status) = 'received'";
        $params[] = $riderId;
    } elseif ($filter === 'available') {
        $where[] = "LOWER(o.status) = 'ready' AND (o.rider_id IS NULL OR o.rider_id = 0 OR o.rider_id = '')";
        // Capability check.
        $where[] = "EXISTS (SELECT 1 FROM riders rr WHERE rr.id = ? AND rr.can_take_available = 1)";
        $params[] = $riderId;
    } elseif ($filter === 'delivered') {
        // Date filter.
        $where[] = "LOWER(o.status) = 'completed' AND o.rider_id = ? AND DATE(o.delivered_at) = ?";
        $params[] = $riderId;
        $params[] = $filter_date;
    } elseif ($filter === 'new') {
        $where[] = "LOWER(o.status) = 'pending' AND o.is_read = 0";
    } elseif ($filter === 'pending') {
        $where[] = "LOWER(o.status) = 'pending'";
    } elseif ($filter === 'confirmed') {
        $where[] = "(LOWER(o.status) = 'confirmed' OR LOWER(o.status) = 'preparing')";
    } elseif ($filter === 'cancelled') {
        $where[] = "LOWER(o.status) = 'cancelled' AND o.rider_id = ?";
        $params[] = $riderId;
    }

    if (!empty($search)) {
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
                "CONCAT('ORD-', LPAD(o.id, 5, '0')) LIKE UPPER(?)",
                "UPPER(COALESCE(o.order_code, '')) LIKE UPPER(?)"
            ];
            $currentParams = [$searchTerm, $searchTerm, $searchTerm, $searchTerm];

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
    }

    $whereClause = !empty($where) ? "WHERE " . implode(" AND ", $where) : "";

    // Count for pagination
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM orders o LEFT JOIN users u ON o.user_id = u.id $whereClause");
    $countStmt->execute($params);
    $totalOrders = $countStmt->fetchColumn();
    $totalPages = ceil($totalOrders / $perPage);

    // Fetch orders
    // Fetch orders.
    $query = "
        SELECT o.*, u.name as u_name, u.phone as u_phone,
               COALESCE(o.location_lat, u.location_lat) as location_lat, 
               COALESCE(o.location_lng, u.location_lng) as location_lng,
               u.street_location as user_street_location, u.delivery_location as user_delivery_location,
               COALESCE(o.customer_name, u.name) as display_name,
               COALESCE(o.customer_phone, u.phone) as display_phone,
               COALESCE(r.username, 'Rider') as rider_name
        FROM (
            SELECT * FROM orders o
            $whereClause
            ORDER BY " . ($filter === 'delivered' ? 'o.delivered_at' : 'o.created_at') . " DESC
            LIMIT $perPage OFFSET $offset
        ) o
        LEFT JOIN users u ON o.user_id = u.id
        LEFT JOIN riders r ON o.rider_id = r.id
    ";
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Fetch stats.
    $statsStmt = $pdo->prepare("
        SELECT 
            SUM(
                CASE 
                    WHEN LOWER(o.status) = 'pending' AND o.is_read = 0 
                    THEN 1 ELSE 0 
                END
            ) as new_orders,
            SUM(
                CASE 
                    WHEN LOWER(o.status) = 'ready' 
                         AND (o.rider_id IS NULL OR o.rider_id = 0 OR o.rider_id = '') 
                         AND r.can_take_available = 1
                    THEN 1 ELSE 0 
                END
            ) as ready_orders,
            SUM(
                CASE 
                    WHEN o.rider_id = :rider_id1 AND o.status IN ('ready', 'delivery') 
                    THEN 1 ELSE 0 
                END
            ) as my_orders,
            SUM(
                CASE 
                    WHEN o.rider_id = :rider_id2 AND o.status = 'received' 
                    THEN 1 ELSE 0 
                END
            ) as received_count,
            SUM(
                CASE 
                    WHEN o.rider_id = :rider_id3 
                         AND o.status = 'completed' 
                         AND o.delivered_at >= :today_start 
                    THEN 1 ELSE 0 
                END
            ) as delivered_orders
        FROM orders o
        JOIN riders r ON r.id = :rider_join_id
        WHERE (o.created_at >= :cutoff OR o.status NOT IN ('completed', 'cancelled'))
          AND o.restaurant_id = :branch_id
    ");
    $statsStmt->execute([
        ':rider_id1'     => $riderId, 
        ':rider_id2'     => $riderId, 
        ':rider_id3'     => $riderId, 
        ':today_start'   => date('Y-m-d 00:00:00'), 
        ':cutoff'        => date('Y-m-d 00:00:00', strtotime('-7 days')),
        ':branch_id'     => $riderBranchId,
        ':rider_join_id' => $riderId,
    ]);
    $stats = $statsStmt ? $statsStmt->fetch(PDO::FETCH_ASSOC) : ['new_orders'=>0,'ready_orders'=>0,'my_orders'=>0,'received_count'=>0,'delivered_orders'=>0];
    $stats['received_count'] = $stats['received_count'] ?? 0;

    // Sync available count.
    if ($filter === 'available') {
        $stats['ready_orders'] = is_array($orders) ? count($orders) : 0;
    }

} catch (Exception $e) {
    $orders = [];
    $error = "Database error: " . $e->getMessage();
}

if (isset($_GET['ajax_list'])) {
    // Only output the list of orders for the auto-refresh script
    if (empty($orders)) {
        echo '<div class="order-card" style="text-align: center; padding: 40px; color: #64748b;">No orders found.</div>';
    } else {
        foreach ($orders as $order) {
            // Need to repeat the logic for variables used in the card
            $status = strtolower($order['status']);
            $isMine = ($order['rider_id'] == $riderId);
            $isAvailable = ($status === 'ready' && (empty($order['rider_id']) || $order['rider_id'] == 0));
            $statusText = ucfirst($status);
            $statusDisplay = $status;
            if ($status === 'delivery') {
                $statusText = 'In Transit';
                $statusDisplay = 'delivery';
            } elseif ($status === 'received') {
                $statusText = 'Food Collected';
                $statusDisplay = 'received';
            } elseif ($status === 'completed') {
                $statusText = 'Completed';
                $statusDisplay = 'completed';
            } elseif ($status === 'ready') {
                $statusText = 'Ready';
                $statusDisplay = 'ready';
            }

            $borderColor = '#6366f1';
            if ($status === 'received')
                $borderColor = '#f59e0b';
            elseif ($status === 'completed')
                $borderColor = '#10b981';
            elseif ($status === 'delivery')
                $borderColor = '#3b82f6';

            // Include the card HTML (compact version for refresh)
            ?>
            <div class="order-card" style="<?php echo $isMine ? 'border-left: 5px solid ' . $borderColor . ';' : ''; ?>">
                <div class="order-card-header">
                    <div class="order-header-left">
                        <h3 class="order-id">Order #ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h3>
                        <span class="order-time"><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></span>
                    </div>
                    <div class="order-header-right">
                        <div class="order-status-badge status-<?php echo $statusDisplay; ?>">
                            <?php echo $statusText; ?>
                        </div>
                    </div>
                </div>

                <div class="order-card-body-compact">
                    <div class="order-essential-row">
                        <div class="essential-info">
                            <div class="essential-label">CUSTOMER</div>
                            <div class="essential-value"><?php echo htmlspecialchars($order['display_name']); ?></div>
                        </div>
                        <div class="essential-info">
                            <div class="essential-label">PHONE</div>
                            <div class="essential-value"><?php echo htmlspecialchars($order['display_phone']); ?></div>
                        </div>
                        <div class="essential-info" style="flex: 1;">
                            <div class="essential-label">LOCATION</div>
                            <div class="essential-value">
                                <?php
                                $orderAddr  = trim($order['delivery_address'] ?? '');
                                $userArea   = trim($order['user_delivery_location'] ?? '');
                                $userStreet = trim($order['user_street_location'] ?? '');
                                $locName    = $orderAddr ?: ($userArea ?: ($userStreet ?: 'N/A'));
                                ?>
                                <div><?php echo htmlspecialchars($locName); ?></div>
                                <?php if (!empty($order['location_lat']) && !empty($order['location_lng'])): ?>
                                    <div style="font-size: 11px; color: #64748b; font-weight: 500; margin-top: 2px;">
                                        GPS: <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="essential-info">
                            <div class="essential-label">TRACK CUSTOMER</div>
                            <div class="essential-value-with-action">
                                <?php if ($order['display_phone']): ?>
                                    <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $order['display_phone']); ?>"
                                        target="_blank" class="track-btn track-btn-whatsapp" title="WhatsApp">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                            <path
                                                d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                                        </svg>
                                    </a>
                                <?php endif; ?>

                                <?php if (($order['location_lat'] && $order['location_lng']) || $order['delivery_address'] || $order['user_street_location']):
                                    $lat = $order['location_lat'] ?: '27.690290';
                                    $lng = $order['location_lng'] ?: '84.446950';
                                    $gmapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode("$lat,$lng");
                                    ?>
                                    <a href="<?php echo htmlspecialchars($gmapLink); ?>" target="_blank"
                                        class="track-btn track-btn-maps" title="Track on Google Maps">
                                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                            stroke-width="2.5">
                                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" />
                                            <circle cx="12" cy="10" r="3" />
                                        </svg>
                                    </a>
                                <?php endif; ?>
                            </div>
                        </div>

                    </div>

                    <div class="order-essential-row" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                        <div class="essential-info">
                            <div class="essential-label">PAYMENT</div>
                            <div class="essential-value">
                                <?php
                                $payStatus = strtolower($order['payment_status'] ?? 'pending');
                                $payMethod = strtoupper($order['payment_method'] ?: ($payStatus === 'paid' ? 'ESEWA' : ''));

                                if ($payStatus === 'paid'):
                                    // Show detailed payment method for paid orders
                                    if (($payMethod === 'COD' || $payMethod === 'CASH') && ($order['paid_amount_cash'] ?? 0) > 0):
                                        ?>
                                        <span class="payment-status-badge payment-paid">Paid by Cash</span>
                                    <?php elseif ($payMethod === 'COD' && ($order['paid_amount_online'] ?? 0) > 0): ?>
                                        <span class="payment-status-badge payment-paid">Online by COD</span>
                                    <?php elseif ($payMethod === 'SPLIT'): ?>
                                        <span class="payment-status-badge payment-paid">Split Payment (Paid)</span>
                                    <?php elseif (in_array($payMethod, ['ESEWA', 'KHALTI', 'ONLINE', 'CONNECTIPS', 'PREPAID'])):
                                        $logoDisplay = ($payMethod === 'ESEWA' || $payMethod === 'ONLINE');
                                        $methodLabel = ($payMethod === 'ONLINE' || $payMethod === 'PREPAID') ? 'eSewa' : $payMethod;
                                        ?>
                                        <span class="payment-status-badge payment-paid payment-esewa">
                                            <?php if ($logoDisplay): ?>
                                                <img src="../../assets/esewalogo.jpg" alt="eSewa"
                                                    style="height:18px; width:auto; border-radius:2px; margin-right:5px;">
                                            <?php endif; ?>
                                            Payment Succeed paid by <?php echo $methodLabel; ?></span>
                                    <?php else: ?>
                                        <span class="payment-status-badge payment-paid">Paid by <?php echo $payMethod ?: 'Others'; ?></span>
                                    <?php endif; ?>
                                <?php elseif ($payMethod === 'COD'): ?>
                                    <span class="payment-status-badge payment-unpaid">COD Pending</span>
                                <?php else: ?>
                                    <span class="payment-status-badge payment-unpaid">Pending
                                        (<?php echo $payMethod ?: 'Online'; ?>)</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="essential-info" style="width:100%;">
                            <button type="button" onclick="openShopQR()"
                                style="width:100%; padding:12px; background:linear-gradient(135deg,#6366f1,#4f46e5); border:none; border-radius:12px; color:#fff; font-size:13px; font-weight:800; letter-spacing:0.5px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 14px rgba(99,102,241,0.35); margin-bottom:2px; transition:all 0.2s;" onmousedown="this.style.transform='scale(0.97)'" onmouseup="this.style.transform='scale(1)'">
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h2v2h-2zM18 14h3M18 18v3M14 18h3v3"/></svg>
                                Shop QR
                            </button>
                        </div>
                        <div class="essential-info">
                            <div class="essential-label">TOTAL</div>
                            <div class="total-amount">Rs. <?php echo number_format($order['total'], 2); ?></div>
                        </div>

                        <?php if (($order['rider_tip'] ?? 0) > 0): ?>
                            <div class="essential-info">
                                <div class="essential-label" style="color: #10b981;">TIP
                                    (<?php echo htmlspecialchars($order['rider_name']); ?>)</div>
                                <div class="total-amount" style="color: #10b981;">Rs.
                                    <?php echo number_format($order['rider_tip'], 2); ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="order-card-footer">
                    <div class="footer-btn-group">
                        <button type="button" class="rider-btn-action btn-view-details"
                            onclick="viewOrderDetails(<?php echo $order['id']; ?>, <?php echo ($isMine && $status === 'received') ? 'true' : 'false'; ?>)">
                            View Details
                        </button>
                        <?php if ($isAvailable): ?>
                            <button type="button" class="rider-btn-action btn-grab" onclick="confirmGrab(<?php echo $order['id']; ?>)">
                                Pick Order
                            </button>
                        <?php elseif ($isMine && ($status === 'ready' || $status === 'delivery')):
                            $isPaid = (strtolower($order['payment_status'] ?? '') === 'paid'); ?>
                            <button type="button" class="rider-btn-action btn-status-received"
                                onclick="triggerMarkReceived(<?php echo $order['id']; ?>, '<?php echo $order['payment_method']; ?>', <?php echo $isPaid ? 'true' : 'false'; ?>)"
                                style="background: <?php echo $isPaid ? 'linear-gradient(135deg, #6366f1, #4f46e5)' : 'linear-gradient(135deg, #10b981, #059669)'; ?>; font-weight: 800;">
                                <?php echo $isPaid ? 'Finalize Delivery' : 'Delivered'; ?>
                            </button>
                        <?php elseif ($isMine && $status === 'received'): 
                            $isPaid = (strtolower($order['payment_status'] ?? '') === 'paid'); ?>
                            <?php if ($isPaid): ?>
                                <button type="button" class="rider-btn-action"
                                    onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'prepaid')"
                                    style="background: linear-gradient(135deg, #10b981, #059669); font-weight: 800; color: white; border: none; border-radius: 10px; padding: 12px 16px; font-size: 14px; letter-spacing: 0.5px;">
                                    COMPLETE ORDER
                                </button>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>

                        <?php if ($isMine && $status === 'received' && strtolower($order['payment_status'] ?? '') !== 'paid'): ?>
                        <!-- Payment Options -->
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1.5px dashed #e2e8f0; display: flex; gap: 10px;">
                            <button onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cash')"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                CASH
                            </button>
                            <button onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cod_online')"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #3b82f6, #2563eb); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                ONLINE
                            </button>
                            <button onclick="openSplitModal(<?php echo $order['id']; ?>, <?php echo $order['total']; ?>)"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #64748b, #475569); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(100, 116, 139, 0.2); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M4 4l5 5"/></svg>
                                SPLIT
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php
        }
    }
    exit;
}

require '_header.php';
?>

<!-- Professional Topbar -->
<div class="rider-topbar">
    <div class="hamburger-btn" onclick="toggleSidebar()">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"
            stroke-linecap="round" stroke-linejoin="round">
            <line x1="3" y1="12" x2="21" y2="12"></line>
            <line x1="3" y1="6" x2="21" y2="6"></line>
            <line x1="3" y1="18" x2="21" y2="18"></line>
        </svg>
    </div>

    <div class="topbar-left" style="display:flex; align-items:center; gap:12px;">
        <div class="logo-icon" style="width:32px; height:32px; box-shadow:none;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z"></path>
            </svg>
        </div>
        <span class="topbar-title">JustKleek</span>
    </div>

    <!-- Navigation -->
    <div class="rider-desktop-nav">
        <a href="daily_closing.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <polyline points="12 6 12 12 16 14"></polyline>
            </svg>
            Bike Km Set
        </a>
        <a href="orders.php" class="desktop-link <?php echo ($filter !== 'delivered') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="1" y="3" width="15" height="13"></rect>
                <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"></polygon>
                <circle cx="5.5" cy="18.5" r="2.5"></circle>
                <circle cx="18.5" cy="18.5" r="2.5"></circle>
            </svg>
            Delivery
        </a>
        <a href="orders.php?filter=delivered"
            class="desktop-link <?php echo ($filter === 'delivered') ? 'active' : ''; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="16" y1="13" x2="8" y2="13"></line>
                <line x1="16" y1="17" x2="8" y2="17"></line>
            </svg>
            Completed
        </a>
        <a href="my_details.php" class="desktop-link">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                <circle cx="12" cy="7" r="4"></circle>
            </svg>
            My Details
        </a>
        <a href="logout.php" class="desktop-link logout">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
                <polyline points="16 17 21 12 16 7"></polyline>
                <line x1="21" y1="12" x2="9" y2="12"></line>
            </svg>
            Sign Out
        </a>
    </div>

    <div style="flex:0; width:40px;" class="mobile-spacer"></div> <!-- Spacer for mobile centering if needed -->
</div>

<?php $rider_current_page = 'orders';
$rider_orders_filter = $filter;
require __DIR__ . '/_sidebar.php'; ?>

<div class="rider-dashboard-wrapper">

    <!-- Upper section removed as per request -->
    <!-- Tabs Selection & Search -->
    <div class="controls-section">
        <form method="GET" class="search-form">
            <input type="hidden" name="filter" value="<?php echo htmlspecialchars($filter); ?>">

            <?php if ($filter === 'delivered'): ?>
                <input type="date" name="filter_date" value="<?php echo htmlspecialchars($filter_date); ?>"
                    style="flex: 0 0 160px; padding: 14px 16px; border-radius: 14px; cursor: pointer; border: 1.5px solid #6366f1; background: #eef2ff; color: #4338ca; font-weight: 700; font-family: 'Outfit', 'Inter', sans-serif; font-size: 14px; outline: none; box-shadow: 0 2px 4px rgba(99,102,241,0.1);"
                    title="Filter by delivery date" onchange="this.form.submit()">
            <?php endif; ?>

            <input type="text" name="search" placeholder="Search order ID or phone..."
                value="<?php echo htmlspecialchars($search); ?>">
            <button type="submit" class="btn-search-premium">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"
                    stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="8"></circle>
                    <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
                </svg>
                Search
            </button>
        </form>
        <?php if ($filter !== 'delivered'): ?>
            <div class="tabs-group">
                <a href="?filter=available" class="tab-item <?php echo $filter === 'available' ? 'active' : ''; ?>">
                    Order Publish
                </a>
                <a href="?filter=my" class="tab-item <?php echo $filter === 'my' ? 'active' : ''; ?>">
                    To Deliver
                </a>
                <a href="?filter=received_orders"
                    class="tab-item <?php echo $filter === 'received_orders' ? 'active tab-received' : ''; ?>"
                    style="<?php echo $filter === 'received_orders' ? '' : (($stats['received_count'] ?? 0) > 0 ? 'border: 1.5px solid #f59e0b; color:#92400e;' : ''); ?>">
                    Order Received
                </a>

                <div class="tabs-extra">
                    <a href="?filter=pending"
                        class="tab-small <?php echo $filter === 'pending' ? 'active' : ''; ?>">Pending</a>
                    <a href="?filter=cancelled"
                        class="tab-small <?php echo $filter === 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($msg === 'picked'): ?>
        <div class="rider-alert-success" style="margin-bottom: 20px;">Order successfully assigned. Please proceed to the
            kitchen for pickup.</div>
    <?php elseif ($msg === 'delivered'): ?>
        <div class="rider-alert-success" style="margin-bottom: 20px;">Order Marked as Completed!</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="rider-alert-error" style="margin-bottom: 20px;"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="orders-list">
        <?php if (empty($orders)): ?>
            <div class="order-card" style="text-align: center; padding: 40px; color: #64748b;">No orders found.</div>
        <?php else:
            $currentDateGroup = '';
            foreach ($orders as $order):

                // Date grouping logic for Delivered section
                if ($filter === 'delivered') {
                    $orderDate = date('Y-m-d', strtotime($order['delivered_at']));
                    $today = date('Y-m-d');
                    $yesterday = date('Y-m-d', strtotime('-1 day'));

                    if ($orderDate === $today) {
                        $dateLabel = 'Today';
                    } elseif ($orderDate === $yesterday) {
                        $dateLabel = 'Yesterday';
                    } else {
                        $dateLabel = date('M d, Y', strtotime($orderDate));
                    }

                    if ($currentDateGroup !== $dateLabel) {
                        $currentDateGroup = $dateLabel;
                        echo '<div style="margin-top: 15px; margin-bottom: 10px; font-weight: 800; font-size: 13px; color: #64748b; text-transform: uppercase; letter-spacing: 1px; border-bottom: 2px solid #e2e8f0; padding-bottom: 8px;">' . $dateLabel . '</div>';
                    }
                }

                $status = strtolower($order['status']);
                $isMine = ($order['rider_id'] == $riderId);
                $isAvailable = ($status === 'ready' && (empty($order['rider_id']) || $order['rider_id'] == 0));

                // Human-readable status text
                $statusText = ucfirst($status);
                $statusDisplay = $status;
                if ($status === 'delivery') {
                    $statusText = 'In Transit';
                    $statusDisplay = 'delivery';
                } elseif ($status === 'received') {
                    $statusText = 'Food Collected';
                    $statusDisplay = 'received';
                } elseif ($status === 'completed') {
                    $statusText = 'Completed';
                    $statusDisplay = 'completed';
                } elseif ($status === 'ready') {
                    $statusText = 'Ready';
                    $statusDisplay = 'ready';
                }

                // Border color per status
                $borderColor = '#6366f1';
                if ($status === 'received')
                    $borderColor = '#f59e0b';
                elseif ($status === 'completed')
                    $borderColor = '#10b981';
                elseif ($status === 'delivery')
                    $borderColor = '#3b82f6';
                ?>
                <div class="order-card" style="<?php echo $isMine ? 'border-left: 5px solid ' . $borderColor . ';' : ''; ?>">
                    <div class="order-card-header">
                        <div class="order-header-left">
                            <h3 class="order-id">Order #ORD-<?php echo str_pad($order['id'], 5, '0', STR_PAD_LEFT); ?></h3>
                            <span class="order-time"><?php echo date('M d, Y h:i A', strtotime($order['created_at'])); ?></span>
                        </div>
                        <div class="order-header-right">
                            <div class="order-status-badge status-<?php echo $statusDisplay; ?>">
                                <?php echo $statusText; ?>
                            </div>
                        </div>
                    </div>

                    <div class="order-card-body-compact">
                        <div class="order-essential-row">
                            <div class="essential-info">
                                <div class="essential-label">CUSTOMER</div>
                                <div class="essential-value"><?php echo htmlspecialchars($order['display_name']); ?></div>
                            </div>
                            <div class="essential-info">
                                <div class="essential-label">PHONE</div>
                                <div class="essential-value"><?php echo htmlspecialchars($order['display_phone']); ?></div>
                            </div>
                            <div class="essential-info" style="flex: 1;">
                                <div class="essential-label">LOCATION</div>
                                <div class="essential-value">
                                    <?php
                                    $orderAddr  = trim($order['delivery_address'] ?? '');
                                    $userArea   = trim($order['user_delivery_location'] ?? '');
                                    $userStreet = trim($order['user_street_location'] ?? '');
                                    $locName    = $orderAddr ?: ($userArea ?: ($userStreet ?: 'N/A'));
                                    ?>
                                    <div><?php echo htmlspecialchars($locName); ?></div>
                                    <?php if (!empty($order['location_lat']) && !empty($order['location_lng'])): ?>
                                        <div style="font-size: 11px; color: #64748b; font-weight: 500; margin-top: 2px;">
                                            GPS: <?php echo htmlspecialchars($order['location_lat'] . ', ' . $order['location_lng']); ?>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="essential-info">
                                <div class="essential-label">TRACK CUSTOMER</div>
                                <div class="essential-value-with-action">
                                    <?php if ($order['display_phone']): ?>
                                        <a href="https://wa.me/<?php echo preg_replace('/[^0-9]/', '', $order['display_phone']); ?>"
                                            target="_blank" class="track-btn track-btn-whatsapp" title="WhatsApp">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor">
                                                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413Z" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>

                                    <?php if (($order['location_lat'] && $order['location_lng']) || $order['delivery_address'] || $order['user_street_location']):
                                        $lat = $order['location_lat'] ?: '27.690290';
                                        $lng = $order['location_lng'] ?: '84.446950';
                                        $gmapLink = "https://www.google.com/maps/search/?api=1&query=" . urlencode("$lat,$lng");
                                        ?>
                                        <a href="<?php echo htmlspecialchars($gmapLink); ?>" class="track-btn track-btn-maps"
                                            title="Track on Google Maps" target="_blank">
                                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                                stroke-width="2.5">
                                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z" />
                                                <circle cx="12" cy="10" r="3" />
                                            </svg>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="order-essential-row" style="border-bottom: none; margin-bottom: 0; padding-bottom: 0;">
                            <div class="essential-info">
                                <div class="essential-label">PAYMENT</div>
                                <div class="essential-value">
                                    <?php
                                    $payStatus = strtolower($order['payment_status'] ?? 'pending');
                                    $payMethod = strtoupper($order['payment_method'] ?: ($payStatus === 'paid' ? 'ESEWA' : ''));

                                    if ($payStatus === 'paid'):
                                        // Show detailed payment method for paid orders
                                        if (($payMethod === 'COD' || $payMethod === 'CASH') && ($order['paid_amount_cash'] ?? 0) > 0):
                                            ?>
                                            <span class="payment-status-badge payment-paid">Paid by Cash</span>
                                        <?php elseif ($payMethod === 'COD' && ($order['paid_amount_online'] ?? 0) > 0): ?>
                                            <span class="payment-status-badge payment-paid">Online by COD</span>
                                        <?php elseif ($payMethod === 'SPLIT'): ?>
                                            <span class="payment-status-badge payment-paid">Split Payment (Paid)</span>
                                        <?php elseif (in_array($payMethod, ['ESEWA', 'KHALTI', 'ONLINE', 'CONNECTIPS', 'PREPAID'])):
                                            $logoDisplay = ($payMethod === 'ESEWA' || $payMethod === 'ONLINE');
                                            $methodLabel = ($payMethod === 'ONLINE' || $payMethod === 'PREPAID') ? 'eSewa' : $payMethod;
                                            ?>
                                            <span class="payment-status-badge payment-paid payment-esewa">
                                                <?php if ($logoDisplay): ?>
                                                    <img src="../../assets/esewalogo.jpg" alt="eSewa"
                                                        style="height:18px; width:auto; border-radius:2px; margin-right:5px;">
                                                <?php endif; ?>
                                                Payment Succeed paid by <?php echo $methodLabel; ?></span>
                                        <?php else: ?>
                                            <span class="payment-status-badge payment-paid">Paid by
                                                <?php echo $payMethod ?: 'Others'; ?></span>
                                        <?php endif; ?>
                                    <?php elseif ($payMethod === 'COD'): ?>
                                        <span class="payment-status-badge payment-unpaid">COD Pending</span>
                                    <?php else: ?>
                                        <span class="payment-status-badge payment-unpaid">Payment Pending
                                            (<?php echo $payMethod ?: 'Online'; ?>)</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="essential-info" style="width:100%;">
                                <button type="button" onclick="openShopQR()"
                                    style="width:100%; padding:12px; background:linear-gradient(135deg,#6366f1,#4f46e5); border:none; border-radius:12px; color:#fff; font-size:13px; font-weight:800; letter-spacing:0.5px; cursor:pointer; display:flex; align-items:center; justify-content:center; gap:8px; box-shadow:0 4px 14px rgba(99,102,241,0.35); margin-bottom:2px; transition:all 0.2s;" onmousedown="this.style.transform='scale(0.97)'" onmouseup="this.style.transform='scale(1)'">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h2v2h-2zM18 14h3M18 18v3M14 18h3v3"/></svg>
                                    Shop QR
                                </button>
                            </div>
                            <div class="essential-info">
                                <div class="essential-label">TOTAL</div>
                                <div class="total-amount">Rs. <?php echo number_format($order['total'], 2); ?></div>
                            </div>

                            <?php if (($order['rider_tip'] ?? 0) > 0): ?>
                                <div class="essential-info">
                                    <div class="essential-label" style="color: #10b981;">TIP
                                        (<?php echo htmlspecialchars($order['rider_name']); ?>)</div>
                                    <div class="total-amount" style="color: #10b981;">Rs.
                                        <?php echo number_format($order['rider_tip'], 2); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="order-card-footer">
                        <div class="footer-btn-group">
                            <button type="button" class="rider-btn-action btn-view-details"
                                onclick="viewOrderDetails(<?php echo $order['id']; ?>, <?php echo ($isMine && $status === 'received') ? 'true' : 'false'; ?>)">
                                View Details
                            </button>
                            <?php if ($isAvailable): ?>
                                <button type="button" class="rider-btn-action btn-grab"
                                    onclick="confirmGrab(<?php echo $order['id']; ?>)">
                                    Pick Order
                                </button>
                            <?php elseif ($isMine && ($status === 'ready' || $status === 'delivery')):
                                $isPaid = (strtolower($order['payment_status'] ?? '') === 'paid');
                                ?>
                                <button type="button" class="rider-btn-action btn-status-received"
                                    onclick="triggerMarkReceived(<?php echo $order['id']; ?>, '<?php echo $order['payment_method']; ?>', <?php echo $isPaid ? 'true' : 'false'; ?>)"
                                    style="background: <?php echo $isPaid ? 'linear-gradient(135deg, #6366f1, #4f46e5)' : 'linear-gradient(135deg, #10b981, #059669)'; ?>; font-weight: 800;">
                                    <?php echo $isPaid ? 'Finalize Delivery' : 'Delivered'; ?>
                                </button>
                            <?php elseif ($isMine && $status === 'received'): 
                                $isPaid = (strtolower($order['payment_status'] ?? '') === 'paid'); ?>
                                <?php if ($isPaid): ?>
                                    <button type="button" class="rider-btn-action"
                                        onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'prepaid')"
                                        style="background: linear-gradient(135deg, #10b981, #059669); font-weight: 800; color: white; border: none; border-radius: 10px; padding: 12px 16px; font-size: 14px; letter-spacing: 0.5px;">
                                        COMPLETE ORDER
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>

                        <?php if ($isMine && $status === 'received' && strtolower($order['payment_status'] ?? '') !== 'paid'): ?>
                        <!-- Realistic Premium Payment Buttons (Main Loop) -->
                        <div style="margin-top: 15px; padding-top: 15px; border-top: 1.5px dashed #e2e8f0; display: flex; gap: 10px;">
                            <button onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cash')"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #10b981, #059669); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M12 1v22M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
                                CASH
                            </button>
                            <button onclick="handlePaymentUpdate(<?php echo $order['id']; ?>, 'cod_online')"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #3b82f6, #2563eb); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(59, 130, 246, 0.3); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><rect x="2" y="5" width="20" height="14" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/></svg>
                                ONLINE
                            </button>
                            <button onclick="openSplitModal(<?php echo $order['id']; ?>, <?php echo $order['total']; ?>)"
                                style="flex: 1; padding: 16px 8px; background: linear-gradient(135deg, #64748b, #475569); border: none; border-radius: 14px; cursor: pointer; font-size: 13px; font-weight: 900; color: white; display: flex; align-items: center; justify-content: center; gap: 8px; box-shadow: 0 4px 15px rgba(100, 116, 139, 0.2); transition: all 0.2s;" onmousedown="this.style.transform='scale(0.95)';" onmouseup="this.style.transform='scale(1)';">
                                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3.5"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M4 4l5 5"/></svg>
                                SPLIT
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

<?php endforeach; ?>
        <?php endif; ?>
    </div>

    <!-- Confirmation Modal -->
    <div id="confirmModal" class="confirm-overlay">
        <div class="confirm-card">
            <div class="confirm-icon">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <path d="M22 11.08V12a10 10 0 1 1-5.93-9.14" />
                    <polyline points="22 4 12 14.01 9 11.01" />
                </svg>
            </div>
            <h3 class="confirm-title">Accept this Delivery?</h3>
            <p class="confirm-body">Are you sure you want to accept this delivery? This action will
                assign the order to your task list and notify the restaurant.</p>
            <div class="confirm-footer">
                <button type="button" class="btn-confirm btn-confirm-no" onclick="closeConfirm()">No, Cancel</button>
                <button type="button" id="confirm-yes-btn" class="btn-confirm btn-confirm-yes">Confirm
                    Assignment</button>
            </div>
        </div>
    </div>

    <!-- Modal for Order Details -->
    <div id="orderDetailModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h3 style="margin:0; font-weight: 800; color: #1e293b;">Order Receipt #<span
                        id="modal-order-id-label"></span></h3>
                <span class="modal-close" onclick="closeModal()">&times;</span>
            </div>
            <div class="modal-body" id="orderDetailContent">
                <!-- Details loaded via AJAX -->
            </div>
        </div>
    </div>

    <!-- Generic Confirm Modal -->
    <div id="genericConfirmModal" class="confirm-overlay">
        <div class="confirm-card" style="padding: 24px;">
            <div class="confirm-icon" id="genericConfirmIcon"
                style="background:#e0e7ff; color:#4f46e5; margin-bottom:16px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <circle cx="12" cy="12" r="10"></circle>
                    <line x1="12" y1="8" x2="12" y2="12"></line>
                    <line x1="12" y1="16" x2="12.01" y2="16"></line>
                </svg>
            </div>
            <h3 class="confirm-title" id="genericConfirmTitle" style="margin-bottom:8px;">Are you sure?</h3>
            <p class="confirm-body" id="genericConfirmBody"
                style="margin-bottom:20px; font-size:14px; white-space: pre-wrap;">Please confirm this action.</p>
            <div class="confirm-footer">
                <button type="button" class="btn-confirm btn-confirm-no" onclick="closeGenericConfirm()">Cancel</button>
                <button type="button" class="btn-confirm btn-confirm-yes" id="genericConfirmYesBtn"
                    style="background:#4f46e5;">Confirm</button>
            </div>
        </div>
    </div>

    <!-- Tips Modal -->
    <div id="tipsModal" class="confirm-overlay">
        <div class="confirm-card" style="padding: 24px;">
            <div class="confirm-icon" style="background:#fef3c7; color:#f59e0b; margin-bottom:16px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                    <rect x="2" y="5" width="20" height="14" rx="2"></rect>
                    <line x1="2" y1="10" x2="22" y2="10"></line>
                </svg>
            </div>
            <h3 class="confirm-title" style="margin-bottom:8px;">Any tips for you?</h3>
            <p class="confirm-body" style="margin-bottom:20px; font-size:14px;">Did the customer add any tip amount
                during this transaction?</p>

            <div id="tipsInputContainer" style="display:none; margin-bottom:20px;">
                <label
                    style="display:block; text-align:left; font-size:12px; font-weight:700; color:#64748b; margin-bottom:8px; letter-spacing:0.5px;">TIPS
                    AMOUNT (Rs.)</label>
                <input type="number" id="tipsAmountInput" placeholder="0.00"
                    style="width:100%; padding:14px; border:2px solid #e2e8f0; border-radius:12px; font-size:18px; font-weight:800; color:#1e293b; outline:none; transition:0.2s;"
                    onfocus="this.style.borderColor='#f59e0b'" onblur="this.style.borderColor='#e2e8f0'">
            </div>

            <div class="confirm-footer" id="tipsActions">
                <button type="button" class="btn-confirm btn-confirm-no" onclick="submitPaymentWithTips('no')">No
                    Tips</button>
                <button type="button" class="btn-confirm btn-confirm-yes" style="background:#f59e0b;"
                    onclick="showTipsInput()">Yes, Add Tips</button>
            </div>
            <div class="confirm-footer" id="tipsConfirmAction" style="display:none;">
                <button type="button" class="btn-confirm btn-confirm-no" onclick="closeTipsModal()">Cancel</button>
                <button type="button" class="btn-confirm btn-confirm-yes" style="background:#10b981;"
                    onclick="submitPaymentWithTips('yes')">Confirm Payment</button>
            </div>
        </div>
    </div>

    <!-- Split Payment Modal -->
    <div id="splitModal" class="confirm-overlay">
        <div class="confirm-card" style="padding: 32px; border-radius: 28px; border: none; box-shadow: 0 40px 80px -20px rgba(0,0,0,0.4); max-width: 400px;">
            <div style="width: 72px; height: 72px; border-radius: 20px; background: #eef2ff; color: #6366f1; display: flex; align-items: center; justify-content: center; margin: 0 auto 24px;">
                <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M16 3h5v5M4 20L21 3M21 16v5h-5M4 4l5 5"/></svg>
            </div>
            
            <h3 style="font-family: system-ui, sans-serif; font-size: 1.5rem; font-weight: 900; color: #0f172a; margin-bottom: 8px;">Split Payment</h3>
            
            <div style="background: #f8fafc; padding: 12px 16px; border-radius: 12px; margin-bottom: 24px; border: 1.5px solid #f1f5f9;">
                <span style="font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px; display: block; margin-bottom: 4px;">Total To Collect</span>
                <span id="splitModalTotalDisplay" style="font-size: 1.25rem; font-weight: 900; color: #1e293b;">Rs. 0.00</span>
            </div>

            <p style="font-size: 0.95rem; color: #64748b; font-weight: 500; line-height: 1.6; margin-bottom: 24px;">Adjust the amounts below. Both fields will stay balanced automatically.</p>
            
            <input type="hidden" id="splitOrderId">
            <input type="hidden" id="splitOrderTotal">

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 30px;">
                <div class="split-input-group">
                    <label style="display: block; text-align: left; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Cash Amount</label>
                    <div style="position: relative;">
                        <input type="number" id="splitCashInput" placeholder="0" oninput="calcModalSplit('cash')" inputmode="decimal"
                            style="width: 100%; padding: 16px; border: 2px solid #e2e8f0; border-radius: 14px; font-size: 1.1rem; font-weight: 800; color: #0f172a; outline: none; background: white; transition: all 0.2s;">
                    </div>
                </div>
                <div class="split-input-group">
                    <label style="display: block; text-align: left; font-size: 11px; font-weight: 800; color: #64748b; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">Online Amount</label>
                    <div style="position: relative;">
                        <input type="number" id="splitOnlineInput" placeholder="0" oninput="calcModalSplit('online')" inputmode="decimal"
                            style="width: 100%; padding: 16px; border: 2px solid #e2e8f0; border-radius: 14px; font-size: 1.1rem; font-weight: 800; color: #0f172a; outline: none; background: white; transition: all 0.2s;">
                    </div>
                </div>
            </div>

            <div style="display: flex; gap: 12px;">
                <button type="button" onclick="closeSplitModal()" 
                    style="flex: 1; padding: 16px; background: #f1f5f9; color: #475569; border: none; border-radius: 14px; font-size: 14px; font-weight: 800; cursor: pointer; transition: all 0.2s;">CANCEL</button>
                <button type="button" class="btn-confirm-yes" onclick="submitModalSplit()" 
                    style="flex: 1.5; padding: 16px; background: linear-gradient(135deg, #6366f1, #4f46e5); color: white; border: none; border-radius: 14px; font-size: 14px; font-weight: 900; cursor: pointer; box-shadow: 0 4px 15px rgba(99, 102, 241, 0.3); transition: all 0.2s;">CONFIRM PAYMENT</button>
            </div>
        </div>
    </div>

    <style>
        .split-input-group input:focus {
            border-color: #6366f1 !important;
            box-shadow: 0 0 0 4px rgba(99, 102, 241, 0.1) !important;
        }
    </style>

    <!-- Custom Alert Modal (for validation errors) -->
    <div id="moAlertModal" class="confirm-overlay" style="z-index: 10001;">
        <div class="confirm-card" style="padding: 32px; border-radius: 24px; max-width: 320px; text-align: center;">
            <div style="width: 64px; height: 64px; border-radius: 20px; background: #fff7ed; color: #f97316; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px;">
                <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
            </div>
            <h3 id="moAlertTitle" style="font-size: 1.25rem; font-weight: 800; color: #1e293b; margin-bottom: 8px;">Notice</h3>
            <p id="moAlertMsg" style="font-size: 0.9rem; color: #64748b; font-weight: 500; line-height: 1.5; margin-bottom: 24px;"></p>
            <button onclick="closeMoAlert()" style="width: 100%; padding: 14px; background: #1e293b; color: white; border: none; border-radius: 12px; font-size: 14px; font-weight: 800; cursor: pointer;">OK</button>
        </div>
    </div>

    <!-- Premium Payment Success Modal -->
    <div id="paymentSuccessModal" style="display:none; position:fixed; inset:0; z-index:9999; background:rgba(15,23,42,0.7); backdrop-filter:blur(6px); -webkit-backdrop-filter:blur(6px); align-items:center; justify-content:center; padding:20px;">
        <div id="paymentSuccessCard" style="background:white; border-radius:28px; padding:40px 32px; max-width:360px; width:100%; text-align:center; box-shadow:0 40px 80px -20px rgba(0,0,0,0.4); transform:scale(0.85) translateY(20px); opacity:0; transition:all 0.45s cubic-bezier(0.34,1.56,0.64,1); position:relative; overflow:hidden;">
            <!-- Particles container -->
            <div id="paymentSuccessParticles" style="position:absolute; inset:0; pointer-events:none; overflow:hidden;"></div>

            <!-- Animated checkmark -->
            <div style="width:88px; height:88px; border-radius:24px; background:linear-gradient(135deg,#d1fae5,#a7f3d0); display:flex; align-items:center; justify-content:center; margin:0 auto 24px; box-shadow:0 8px 24px rgba(16,185,129,0.25);">
                <svg id="paymentSuccessCheck" width="44" height="44" viewBox="0 0 52 52" style="">
                    <circle cx="26" cy="26" r="24" fill="none" stroke="#10b981" stroke-width="3" stroke-dasharray="150.8" stroke-dashoffset="150.8" style="transition:stroke-dashoffset 0.6s ease-out; transform-origin:center; transform:rotate(-90deg);" id="successCircle"/>
                    <polyline points="14,27 22,35 38,19" fill="none" stroke="#10b981" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round" stroke-dasharray="35" stroke-dashoffset="35" id="successCheck" style="transition:stroke-dashoffset 0.4s ease-out 0.5s;"/>
                </svg>
            </div>

            <h2 id="paymentSuccessTitle" style="font-family:system-ui,-apple-system,sans-serif; font-size:1.6rem; font-weight:900; color:#0f172a; margin:0 0 10px; letter-spacing:-0.03em;"></h2>
            <p id="paymentSuccessMsg" style="font-size:0.95rem; color:#64748b; font-weight:500; line-height:1.6; margin:0 0 28px;"></p>

            <button onclick="window.location.reload()" style="width:100%; padding:16px; background:linear-gradient(135deg,#10b981,#059669); color:white; border:none; border-radius:14px; font-size:1rem; font-weight:800; cursor:pointer; box-shadow:0 8px 20px -4px rgba(16,185,129,0.4); transition:all 0.2s; letter-spacing:0.02em;" onmouseover="this.style.transform='translateY(-2px)'; this.style.boxShadow='0 12px 28px -4px rgba(16,185,129,0.5)'" onmouseout="this.style.transform=''; this.style.boxShadow='0 8px 20px -4px rgba(16,185,129,0.4)'">
                Done!
            </button>
        </div>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
        <div style="display: flex; justify-content: center; gap: 8px; margin-top: 30px;">
            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                <a href="?filter=<?php echo $filter; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>"
                    class="tab-item <?php echo $i === $page ? 'active' : ''; ?>"
                    style="padding: 8px 16px; border-radius: 10px;"><?php echo $i; ?></a>
            <?php endfor; ?>
        </div>
    <?php endif; ?>

</div>

<!-- Interactive Scripts -->
<script>
    let activeOrderId = null;

    function viewOrderDetails(orderId, canPay = false) {
        const modal = document.getElementById('orderDetailModal');
        const content = document.getElementById('orderDetailContent');
        const label = document.getElementById('modal-order-id-label');

        label.innerText = orderId;
        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
        content.innerHTML = '<div style="padding:60px; text-align:center;"><div class="stat-icon-blue" style="width:50px; height:50px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; margin-bottom:15px; animation: pulse 1.5s infinite;"><svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.219-8.56" /></svg></div><div style="font-weight:700; color:#64748b;">Loading order information...</div></div>';

        fetch(`api/get_order_details.php?order_id=${orderId}&can_pay=${canPay ? 1 : 0}`)
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    content.innerHTML = data.html;
                } else {
                    content.innerHTML = `<div style="padding:40px; text-align:center; color:#ef4444; font-weight:700;">${data.error}</div>`;
                }
            });
    }

    function confirmGrab(orderId) {
        activeOrderId = orderId;
        const modal = document.getElementById('confirmModal');
        const yesBtn = document.getElementById('confirm-yes-btn');

        // Fresh listener pattern
        const newYesBtn = yesBtn.cloneNode(true);
        yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);
        
        newYesBtn.addEventListener('click', function(e) {
            e.preventDefault();
            const btn = newYesBtn;
            const originalText = btn.innerText;
            btn.disabled = true;
            btn.innerText = 'Processing...';

            const formData = new FormData();
            formData.append('action', 'grab');
            formData.append('order_id', orderId);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('ajax', '1');

            fetch('order_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = 'orders.php?filter=my&msg=grabbed';
                } else {
                    alert('Sorry! ' + data.error);
                    window.location.reload();
                }
            })
            .catch(err => {
                alert('Connection error. Please try again.');
                btn.disabled = false;
                btn.innerText = originalText;
            });
        });

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeConfirm() {
        document.getElementById('confirmModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function closeModal() {
        document.getElementById('orderDetailModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function showPaymentOptions() {
        const btn = document.getElementById('mainUpdateBtn');
        const container = document.getElementById('paymentOptionsContainer');
        if (btn) btn.style.display = 'none';
        if (container) container.style.display = 'block';
    }

    function toggleSplitContainer() {
        const splitCont = document.getElementById('splitContainer');
        if (splitCont) splitCont.classList.toggle('active');
    }

    function calculateSplit(total) {
        const cashInput = document.getElementById('splitCash');
        const onlineInput = document.getElementById('splitOnline');
        if (!cashInput || !onlineInput) return;

        const cash = parseFloat(cashInput.value) || 0;
        const online = Math.max(0, total - cash);
        onlineInput.value = online.toFixed(2);
    }

    let genericConfirmCallback = null;

    // === PREMIUM PAYMENT SUCCESS MODAL ===
    function showPaymentSuccess(title, message) {
        const modal = document.getElementById('paymentSuccessModal');
        const card = document.getElementById('paymentSuccessCard');
        const titleEl = document.getElementById('paymentSuccessTitle');
        const msgEl = document.getElementById('paymentSuccessMsg');
        const circle = document.getElementById('successCircle');
        const check = document.getElementById('successCheck');

        if (!modal) return;

        // Reset animation state
        circle.style.strokeDashoffset = '150.8';
        check.style.strokeDashoffset = '35';
        titleEl.textContent = '';
        msgEl.textContent = '';

        modal.style.display = 'flex';
        document.body.style.overflow = 'hidden';

        // Trigger card entrance
        requestAnimationFrame(() => {
            requestAnimationFrame(() => {
                card.style.transform = 'scale(1) translateY(0)';
                card.style.opacity = '1';
            });
        });

        // Animate circle drawing
        setTimeout(() => { circle.style.strokeDashoffset = '0'; }, 100);
        // Animate checkmark drawing
        setTimeout(() => { check.style.strokeDashoffset = '0'; }, 600);

        // Typewriter for title
        setTimeout(() => typewriter(titleEl, title, 40), 700);
        // Typewriter for message (after title)
        setTimeout(() => typewriter(msgEl, message, 18), 700 + title.length * 40 + 100);

        // Spawn particles
        setTimeout(() => spawnParticles(), 500);
    }

    function typewriter(el, text, speed) {
        let i = 0;
        el.textContent = '';
        const interval = setInterval(() => {
            if (i < text.length) {
                el.textContent += text[i++];
            } else {
                clearInterval(interval);
            }
        }, speed);
    }

    function spawnParticles() {
        const container = document.getElementById('paymentSuccessParticles');
        if (!container) return;
        container.innerHTML = '';
        const colors = ['#10b981','#34d399','#6ee7b7','#f59e0b','#fbbf24','#a78bfa','#4f46e5'];
        for (let i = 0; i < 22; i++) {
            const p = document.createElement('div');
            const size = Math.random() * 8 + 4;
            const x = Math.random() * 100;
            const delay = Math.random() * 0.4;
            const duration = Math.random() * 1.2 + 1;
            const color = colors[Math.floor(Math.random() * colors.length)];
            p.style.cssText = `
                position:absolute;
                left:${x}%;
                top:-10px;
                width:${size}px;
                height:${size}px;
                background:${color};
                border-radius:${Math.random() > 0.5 ? '50%' : '3px'};
                animation: particleFall ${duration}s ease-in ${delay}s forwards;
                opacity:0;
            `;
            container.appendChild(p);
        }
    }


    function showCustomConfirm(title, message, iconType, onConfirm) {
        genericConfirmCallback = onConfirm;
        document.getElementById('genericConfirmTitle').innerText = title;
        document.getElementById('genericConfirmBody').innerText = message;

        const iconDiv = document.getElementById('genericConfirmIcon');
        const yesBtn = document.getElementById('genericConfirmYesBtn');

        // Reset button state
        yesBtn.disabled = false;
        yesBtn.innerText = 'Confirm'; 
        yesBtn.style.opacity = '1';
        yesBtn.style.pointerEvents = 'auto';

        if (iconType === 'success') {
            iconDiv.style.background = '#ecfdf5';
            iconDiv.style.color = '#10b981';
            iconDiv.style.setProperty('--pulse-color', 'rgba(16, 185, 129, 0.4)');
            yesBtn.style.background = '#10b981';
            iconDiv.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>';
        } else if (iconType === 'warning') {
            iconDiv.style.background = '#eff6ff';
            iconDiv.style.color = '#2563eb';
            iconDiv.style.setProperty('--pulse-color', 'rgba(37, 99, 235, 0.3)');
            yesBtn.style.background = '#2563eb';
            iconDiv.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"></path><polyline points="3.29 7 12 12 20.71 7"></polyline><line x1="12" y1="22" x2="12" y2="12"></line></svg>';
        } else {
            iconDiv.style.background = '#f1f5f9';
            iconDiv.style.color = '#475569';
            iconDiv.style.setProperty('--pulse-color', 'rgba(71, 85, 105, 0.2)');
            yesBtn.style.background = '#475569';
            iconDiv.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
        }

        // Use a clean event listener approach
        const newYesBtn = yesBtn.cloneNode(true);
        yesBtn.parentNode.replaceChild(newYesBtn, yesBtn);
        
        newYesBtn.addEventListener('click', function(e) {
            e.preventDefault();
            if (onConfirm) {
                // Determine if onConfirm handles the closing itself (e.g. for AJAX)
                const shouldClose = onConfirm(); 
                if (shouldClose !== false) {
                    closeGenericConfirm();
                }
            } else {
                closeGenericConfirm();
            }
        });

        document.getElementById('genericConfirmModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeGenericConfirm() {
        document.getElementById('genericConfirmModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    let pendingPaymentData = null;

    function openSplitModal(orderId, total) {
        document.getElementById('splitOrderId').value = orderId;
        document.getElementById('splitOrderTotal').value = total;
        document.getElementById('splitCashInput').value = '';
        const cleanTotal = Number(parseFloat(total).toFixed(2));
        document.getElementById('splitOnlineInput').value = cleanTotal;
        document.getElementById('splitModalTotalDisplay').innerText = `Rs. ${total.toLocaleString(undefined, {minimumFractionDigits: 0, maximumFractionDigits: 2})}`;
        document.getElementById('splitModal').classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeSplitModal() {
        document.getElementById('splitModal').classList.remove('active');
        document.body.style.overflow = '';
    }

    function calcModalSplit(source) {
        const total = parseFloat(document.getElementById('splitOrderTotal').value) || 0;
        if (source === 'cash') {
            const cash = parseFloat(document.getElementById('splitCashInput').value) || 0;
            const online = Math.max(0, total - cash);
            document.getElementById('splitOnlineInput').value = Number(online.toFixed(2));
        } else {
            const online = parseFloat(document.getElementById('splitOnlineInput').value) || 0;
            const cash = Math.max(0, total - online);
            document.getElementById('splitCashInput').value = Number(cash.toFixed(2));
        }
    }

    function showMoAlert(msg, title = 'Notice') {
        document.getElementById('moAlertTitle').innerText = title;
        document.getElementById('moAlertMsg').innerText = msg;
        document.getElementById('moAlertModal').classList.add('active');
    }

    function closeMoAlert() {
        document.getElementById('moAlertModal').classList.remove('active');
    }

    function submitModalSplit() {
        const orderId = document.getElementById('splitOrderId').value;
        const total = parseFloat(document.getElementById('splitOrderTotal').value);
        const cash = parseFloat(document.getElementById('splitCashInput').value) || 0;
        const online = parseFloat(document.getElementById('splitOnlineInput').value) || 0;

        if (Math.abs(cash + online - total) > 0.01) {
            showMoAlert('CASH + ONLINE must equal Rs. ' + total, 'Payment Balance');
            return;
        }

        const yesBtn = document.querySelector('#splitModal .btn-confirm-yes');
        yesBtn.disabled = true;
        yesBtn.innerText = 'Processing...';

        const formData = new URLSearchParams();
        formData.append('order_id', orderId);
        formData.append('mode', 'split');
        formData.append('cash_amount', cash);
        formData.append('online_amount', online);
        if (currentContextMode === 'admin') {
            formData.append('rider_id', currentRiderId);
        }


        fetch('api/update_payment.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: formData.toString()
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                closeSplitModal();
                showPaymentSuccess('Payment Confirmed!', data.message || 'Split payment confirmed!');
            } else {
                showMoAlert(data.error || 'Update failed', 'Update Error');
                yesBtn.disabled = false;
                yesBtn.innerText = 'Confirm Payment';
            }
        });
    }

    function handlePaymentUpdate(orderId, mode) {
        if (mode === 'cod_online') {
            pendingPaymentData = { orderId, mode };
            document.getElementById('tipsAmountInput').value = '';
            document.getElementById('tipsInputContainer').style.display = 'none';
            document.getElementById('tipsActions').style.display = 'flex';
            document.getElementById('tipsConfirmAction').style.display = 'none';
            document.getElementById('tipsModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            return;
        }

        executePaymentUpdate({ orderId, mode, tips: 0 });
    }

    function showTipsInput() {
        document.getElementById('tipsActions').style.display = 'none';
        document.getElementById('tipsInputContainer').style.display = 'block';
        document.getElementById('tipsConfirmAction').style.display = 'flex';
        document.getElementById('tipsAmountInput').focus();
    }

    function closeTipsModal() {
        document.getElementById('tipsModal').classList.remove('active');
    }

    function submitPaymentWithTips(hasTips) {
        let tips = 0;
        if (hasTips === 'yes') {
            tips = parseFloat(document.getElementById('tipsAmountInput').value) || 0;
            if (tips < 0) {
                showMoAlert('Tips cannot be negative', 'Invalid Entry');
                return;
            }
        }

        closeTipsModal();
        let payload = { ...pendingPaymentData, tips };
        executePaymentUpdate(payload);
    }

    function executePaymentUpdate(data) {
        const { orderId, mode, tips } = data;
        let confirmMsg = 'Confirm ' + mode.toUpperCase() + ' payment collected? This will complete the order.';
        let confirmTitle = 'Confirm Payment';
        if (mode === 'split') confirmMsg = 'Confirm split payment?';
        if (mode === 'prepaid') {
            confirmMsg = 'Confirm delivery completion for this prepaid order?';
            confirmTitle = 'Complete Delivery';
        }

        showCustomConfirm(confirmTitle, confirmMsg, 'success', function () {
            const yesBtn = document.getElementById('genericConfirmYesBtn');
            yesBtn.disabled = true;
            yesBtn.innerText = 'Processing...';

            const formData = new URLSearchParams();
            formData.append('order_id', orderId);
            formData.append('mode', mode);
            if (tips > 0) {
                formData.append('tips_amount', tips);
            }

            if (mode === 'split') {
                const cash = document.getElementById('splitCash').value;
                const online = document.getElementById('splitOnline').value;
                formData.append('cash_amount', cash);
                formData.append('online_amount', online);
            }
            if (currentContextMode === 'admin') {
                formData.append('rider_id', currentRiderId);
            }


            fetch('api/update_payment.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString()
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    closeGenericConfirm();
                    showPaymentSuccess('Payment Confirmed!', data.message || 'Payment updated and order marked as completed!');
                } else {
                    showMoAlert(data.error, 'Error');
                }
            })
            .catch(err => {
                showMoAlert('Request failed: ' + err, 'Connection Error');
            });
            
            return false; // Keep modal open while processing
        });
    }

    function triggerMarkReceived(orderId, payMethod, isPaid = false) {
        let msg = "Are you sure you have collected this order from the restaurant? This will record the start of your delivery journey.";
        let title = "Confirm Food Pickup?";
        if (isPaid) {
            msg = "Payment for this order is already confirmed via eSewa/Online.\n\nConfirm you have collected this order? This will mark the delivery as finalized.";
            title = "Finalize Prepaid Delivery?";
        }
        showCustomConfirm(title, msg, 'warning', function() {
            const yesBtn = document.getElementById('genericConfirmYesBtn');
            yesBtn.disabled = true;
            yesBtn.innerText = 'Processing...';

            const formData = new FormData();
            formData.append('action', 'mark_received');
            formData.append('order_id', orderId);
            formData.append('csrf_token', '<?php echo $csrf_token; ?>');
            formData.append('ajax', '1');
            if (currentContextMode === 'admin') {
                formData.append('rider_id', currentRiderId);
            }


            fetch('order_action.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    window.location.href = data.redirect || 'orders.php';
                } else {
                    showMoAlert(data.error || 'Unknown error', 'Pickup Error');
                    yesBtn.disabled = false;
                    yesBtn.innerText = 'Confirm';
                }
            })
            .catch(err => {
                showMoAlert('Connection failed. Please check your internet and try again.', 'Network Error');
                yesBtn.disabled = false;
                yesBtn.innerText = 'Confirm';
            });
            
            return false; // Tells showCustomConfirm NOT to close immediately
        });
        document.querySelector('#genericConfirmModal .btn-confirm-no').style.display = 'block';
    }

    function handleStatusChange(select, orderId, payMethod, currentStatus) {
        const newStatus = select.value;
        if (newStatus === 'completed') {
            triggerMarkReceived(orderId, payMethod);
        } else {
            select.value = currentStatus;
            showMoAlert("Riders can only update status to 'Completed' once a task is picked.", 'Permission Denied');
        }
    }

    function toggleSidebar() {
        document.getElementById('sidebarNav').classList.toggle('active');
        document.getElementById('sidebarOverlay').classList.toggle('active');
    }
    document.addEventListener('DOMContentLoaded', function() {
        let isRefreshing = false;
        setInterval(function() {
            if (isRefreshing) return;
            isRefreshing = true;
            fetch(window.location.href + (window.location.href.includes('?') ? '&' : '?') + 'ajax_list=1')
                .then(res => res.text())
                .then(html => {
                    const containers = document.querySelectorAll('.orders-list');
                    if (containers.length > 0) {
                        containers[0].innerHTML = html;
                    }
                    isRefreshing = false;
                })
                .catch(() => { isRefreshing = false; });
        }, 5000);
    });
    function openShopQR() {
        document.getElementById('shopQrModal').style.display = 'flex';
        document.body.style.overflow = 'hidden';
    }
    function closeShopQR() {
        document.getElementById('shopQrModal').style.display = 'none';
        document.body.style.overflow = '';
    }
</script>

<!-- Shop QR Modal -->
<div id="shopQrModal" onclick="if(event.target===this)closeShopQR()" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,0.7); z-index:9999; align-items:center; justify-content:center; backdrop-filter:blur(4px);">
    <div style="background:#fff; border-radius:20px; padding:32px 28px; max-width:340px; width:92%; text-align:center; box-shadow:0 20px 60px rgba(0,0,0,0.3); position:relative;">
        <button onclick="closeShopQR()" style="position:absolute; top:14px; right:16px; background:none; border:none; font-size:22px; cursor:pointer; color:#64748b; line-height:1;">&times;</button>
        <div style="width:56px; height:56px; background:linear-gradient(135deg,#6366f1,#4f46e5); border-radius:16px; display:flex; align-items:center; justify-content:center; margin:0 auto 16px;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="white" stroke-width="2.5"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h2v2h-2zM18 14h3M18 18v3M14 18h3v3"/></svg>
        </div>
        <h3 style="margin:0 0 6px; font-size:1.2rem; font-weight:800; color:#1e293b;">Shop Payment QR</h3>
        <p style="margin:0 0 20px; font-size:0.85rem; color:#64748b;">Show this QR to the customer for online payment.</p>
        <div style="background:#f8fafc; border-radius:16px; padding:16px; margin-bottom:20px;">
            <img src="../../assets/shopqr.png" alt="Shop Payment QR" style="max-width:220px; width:100%; border-radius:10px; display:block; margin:0 auto;">
        </div>
        <button onclick="closeShopQR()" style="width:100%; padding:13px; background:linear-gradient(135deg,#6366f1,#4f46e5); border:none; border-radius:12px; color:#fff; font-size:14px; font-weight:700; cursor:pointer;">Close</button>
    </div>
</div>

<?php require '_footer.php'; ?>